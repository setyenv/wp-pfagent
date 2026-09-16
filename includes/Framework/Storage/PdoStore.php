<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Storage;

use PDO;
use ProjectFlash\Agent\Framework\Conversation;
use ProjectFlash\Agent\Framework\Message;

/**
 * PDO-backed Store. Works with SQLite (for tests) and MySQL 5.7+ (for production).
 * Driver-specific SQL surfaced through small private helpers.
 */
final class PdoStore implements Store
{
    private string $driver;

    public function __construct(private readonly PDO $pdo, private readonly string $tablePrefix = '')
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function migrate(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../schema/0001_init.sql');
        if ($schema === false) {
            throw new \RuntimeException('Failed to read schema/0001_init.sql');
        }
        if ($this->tablePrefix !== '') {
            $schema = str_replace('pfaf_', $this->tablePrefix . 'pfaf_', $schema);
        }
        if ($this->driver === 'mysql') {
            $schema = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $schema);
            $schema = preg_replace('/CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS/i', 'CREATE $1INDEX', $schema) ?? $schema;
        }
        // Strip `--` comment lines BEFORE splitting so the first statement
        // (which is preceded by header comments) doesn't get masked as "all
        // comments". Keep multi-line SQL intact.
        $cleaned = preg_replace('/^\s*--[^\n]*\n/m', '', $schema) ?? $schema;
        $statements = preg_split('/;\s*$/m', $cleaned) ?: [];
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            try {
                $this->pdo->exec($stmt);
            } catch (\PDOException $e) {
                // MySQL throws on duplicate index even with IF NOT EXISTS not
                // available on older versions. Silently swallow that one class.
                if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                    throw $e;
                }
            }
        }
    }

    public function createConversation(string $label, array $metadata = []): string
    {
        $now = gmdate('c');
        // The key is minted, never handed out by the database: an
        // auto-increment id means something different in every install, and a
        // conversation has to mean the same thing wherever it travels.
        $id = self::new_uuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->t('pfaf_conversations')}
                (id, label, status, created_at, last_turn_at, turn_count, metadata_json)
            VALUES (:id, :label, 'open', :now, '', 0, :meta)
        ");
        $stmt->execute([
            ':id' => $id,
            ':label' => $label,
            ':now' => $now,
            ':meta' => (string) json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        return $id;
    }

    /** A uuid for a new conversation (the PDO backend has no WordPress). */
    public static function new_uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    public function loadConversation(string $id): ?Conversation
    {
        $convStmt = $this->pdo->prepare("SELECT * FROM {$this->t('pfaf_conversations')} WHERE id = :id");
        $convStmt->execute([':id' => $id]);
        $row = $convStmt->fetch();
        if ($row === false) {
            return null;
        }

        $msgStmt = $this->pdo->prepare("
            SELECT * FROM {$this->t('pfaf_messages')}
            WHERE conversation_id = :id
            ORDER BY ordinal ASC
        ");
        $msgStmt->execute([':id' => $id]);
        $messages = [];
        foreach ($msgStmt->fetchAll() as $r) {
            $messages[] = new Message(
                role: (string) $r['role'],
                content: ($c = $this->decodeContent((string) $r['content_json'])) === null ? null : (string) $c,
                toolCalls: (array) (json_decode((string) $r['tool_calls_json'], true) ?? []),
                toolCallId: (string) ($r['tool_call_id'] ?? ''),
                reasoning: (string) ($r['reasoning'] ?? ''),
                finishReason: (string) ($r['finish_reason'] ?? ''),
                tokensIn: (int) ($r['tokens_in'] ?? 0),
                tokensOut: (int) ($r['tokens_out'] ?? 0),
            );
        }

        $metadata = json_decode((string) $row['metadata_json'], true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return new Conversation(
            id: (string) $row['id'],
            label: (string) $row['label'],
            status: (string) $row['status'],
            messages: $messages,
            turnCount: (int) $row['turn_count'],
            metadata: $metadata,
        );
    }

    public function appendMessage(string $conversationId, Message $message): int
    {
        // Pick the next ordinal atomically. For SQLite this is fine because
        // PDO operations are serialised by the engine; for MySQL we lean on
        // a SELECT MAX inside the same statement using a sub-query.
        $nextOrdinal = (int) $this->pdo->query(
            "SELECT COALESCE(MAX(ordinal), 0) + 1 AS n FROM {$this->t('pfaf_messages')} WHERE conversation_id = " . $this->pdo->quote($conversationId)
        )->fetchColumn();

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->t('pfaf_messages')}
                (id, conversation_id, ordinal, role, content_json, tool_calls_json, tool_call_id, reasoning, finish_reason, tokens_in, tokens_out, cost_micros, created_at)
            VALUES (:id, :cid, :ord, :role, :content, :tc, :tcid, :reasoning, :fr, :ti, :to, 0, :now)
        ");
        $id = self::new_uuid();
        $stmt->execute([
            ':id' => $id,
            ':cid' => $conversationId,
            ':ord' => $nextOrdinal,
            ':role' => $message->role,
            ':content' => (string) json_encode($message->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':tc' => (string) json_encode($message->toolCalls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':tcid' => $message->toolCallId,
            ':reasoning' => $message->reasoning,
            ':fr' => $message->finishReason,
            ':ti' => $message->tokensIn,
            ':to' => $message->tokensOut,
            ':now' => gmdate('c'),
        ]);

        // Only a user message opens a turn; the assistant's reply and each tool
        // result belong to the same one. The stamp moves on every append.
        $this->pdo->prepare(
            $message->role === 'user'
                ? "UPDATE {$this->t('pfaf_conversations')} SET turn_count = turn_count + 1, last_turn_at = :now WHERE id = :id"
                : "UPDATE {$this->t('pfaf_conversations')} SET last_turn_at = :now WHERE id = :id"
        )->execute([':now' => gmdate('c'), ':id' => $conversationId]);

        return $nextOrdinal;
    }

    public function updateConversationMetadata(string $conversationId, array $partial): void
    {
        $existing = $this->pdo->query(
            "SELECT metadata_json FROM {$this->t('pfaf_conversations')} WHERE id = " . $this->pdo->quote($conversationId)
        )->fetchColumn();
        $current = is_string($existing) ? (array) (json_decode($existing, true) ?? []) : [];
        $merged = array_replace_recursive($current, $partial);
        $this->pdo->prepare("
            UPDATE {$this->t('pfaf_conversations')} SET metadata_json = :m WHERE id = :id
        ")->execute([
            ':m' => (string) json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $conversationId,
        ]);
    }

    public function closeConversation(string $conversationId, string $status = 'closed'): void
    {
        $this->pdo->prepare("UPDATE {$this->t('pfaf_conversations')} SET status = :s WHERE id = :id")
            ->execute([':s' => $status, ':id' => $conversationId]);
    }

    public function logToolCall(
        string $conversationId,
        int $messageOrdinal,
        string $toolCallId,
        string $toolName,
        array $arguments,
        bool $sideEffect,
        string $status,
        mixed $result,
        mixed $stateAfter,
        string $errorCode,
        string $errorMessage,
        string $fingerprint,
        int $durationMs,
        string $startedAt,
        string $endedAt,
    ): string {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->t('pfaf_tool_calls')}
                (id, conversation_id, message_ordinal, tool_call_id, tool_name, arguments_json, side_effect, status, result_json, state_after_json, error_code, error_message, fingerprint, duration_ms, started_at, ended_at)
            VALUES (:id, :cid, :ord, :tcid, :name, :args, :se, :status, :res, :sa, :ecode, :emsg, :fp, :dur, :st, :en)
        ");
        $id = self::new_uuid();
        $stmt->execute([
            ':id' => $id,
            ':cid' => $conversationId,
            ':ord' => $messageOrdinal,
            ':tcid' => $toolCallId,
            ':name' => $toolName,
            ':args' => (string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':se' => $sideEffect ? 1 : 0,
            ':status' => $status,
            ':res' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':sa' => (string) json_encode($stateAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ecode' => $errorCode,
            ':emsg' => $errorMessage,
            ':fp' => $fingerprint,
            ':dur' => $durationMs,
            ':st' => $startedAt,
            ':en' => $endedAt,
        ]);
        return $id;
    }

    public function findIdempotentResult(string $conversationId, string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT result_json, state_after_json
            FROM {$this->t('pfaf_tool_calls')}
            WHERE conversation_id = :cid AND fingerprint = :fp AND status = 'ok'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $conversationId, ':fp' => $fingerprint]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'result' => json_decode((string) $row['result_json'], true),
            'stateAfter' => json_decode((string) $row['state_after_json'], true),
        ];
    }

    public function countFingerprint(string $conversationId, string $fingerprint, int $sinceOrdinal = 0): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->t('pfaf_tool_calls')}
            WHERE conversation_id = :cid AND fingerprint = :fp AND message_ordinal >= :ord
        ");
        $stmt->execute([':cid' => $conversationId, ':fp' => $fingerprint, ':ord' => $sinceOrdinal]);
        return (int) $stmt->fetchColumn();
    }

    public function countSuccessfulSideEffects(string $conversationId): int
    {
        // Mirror of WpDbStore::countSuccessfulSideEffects — see that
        // method for the rationale on querying by tool_name rather
        // than the side_effect flag.
        $names = [
            'pfm_apply', 'pfm_delete', 'write_file', 'edit_file',
            'move_file', 'delete_file', 'create_variable', 'activate_workflow',
        ];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->t('pfaf_tool_calls')}
            WHERE conversation_id = ? AND status = 'ok'
              AND tool_name IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$conversationId], $names));
        return (int) $stmt->fetchColumn();
    }

    public function logTrace(string $conversationId, int $turn, int $round, string $kind, array $payload, string $systemFingerprint = ''): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->t('pfaf_traces')}
                (id, conversation_id, turn, round, kind, payload_json, system_fingerprint, created_at)
            VALUES (:id, :cid, :turn, :round, :kind, :p, :sf, :now)
        ");
        $id = self::new_uuid();
        $stmt->execute([
            ':id' => $id,
            ':cid' => $conversationId,
            ':turn' => $turn,
            ':round' => $round,
            ':kind' => $kind,
            ':p' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':sf' => $systemFingerprint,
            ':now' => gmdate('c'),
        ]);
    }

    private function t(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    private function decodeContent(string $json): mixed
    {
        // Content is stored as JSON-encoded scalar/object/null. json_decode
        // returns null for "null" literal AND on parse error; we need to
        // distinguish, so probe the raw byte first.
        if ($json === 'null' || $json === '') {
            return null;
        }
        return json_decode($json, true);
    }
}
