<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Persistent trace log for agent observability.
 *
 * Owns the table `{prefix}pfa_trace_log`. Distinct from (and
 * intentionally disjoint with) the Framework Loop's per-round trace
 * table `{prefix}pfaf_traces` owned by
 * {@see \ProjectFlash\Agent\Framework\WordPress\Storage\WpDbStore}.
 * Both names contain "trace"; the schemas do NOT overlap. See
 * docs/architecture-notes.md for the full layout (F6 disambiguation).
 *
 * Every AgentRuntime turn, every Workflow API call and every LLM call
 * acquires a single trace_id. Each step writes one row into the log
 * with kind/status/duration/error metadata so failures can be
 * classified by source (llm vs workflow_api vs agent_turn vs
 * rate_limit vs body_size). Secrets are redacted at write time using
 * the same patterns as ChatSessions::redact_secrets.
 */
final class TraceLogger
{
    // Dedicated store for the plugin's OWN trace table (wp_pfa_trace_log). All
    // queries bind their VALUES through $wpdb->prepare(); the only interpolation
    // is the fixed table prefix ($wpdb->prefix, never user input), so the
    // prepared-SQL sniffs mis-fire. Direct queries on a custom table are
    // unavoidable and per-request trace reads are not object-cache candidates.
    // Justified, class-scoped.
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public const SCHEMA_VERSION = '3';
    private const VERSION_OPTION = 'pfa_trace_log_schema_version';
    public const KIND_AGENT_TURN = 'agent_turn';
    public const KIND_WORKFLOW_API = 'workflow_api';
    public const KIND_LLM_CALL = 'llm_call';
    public const KIND_RATE_LIMIT = 'rate_limit';
    public const KIND_BODY_SIZE = 'body_size';
    public const KIND_CHAT_PERSIST = 'chat_persist';

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_NEEDS_CONFIRMATION = 'needs_confirmation';

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'pfa_trace_log';
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id CHAR(36) NOT NULL,
            trace_id VARCHAR(36) NOT NULL,
            user_id CHAR(32) NULL DEFAULT NULL,
            kind VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            tool VARCHAR(64) NOT NULL DEFAULT '',
            provider_id VARCHAR(64) NOT NULL DEFAULT '',
            duration_ms INT NOT NULL DEFAULT 0,
            http_status INT NOT NULL DEFAULT 0,
            error_code VARCHAR(64) NOT NULL DEFAULT '',
            tokens_total INT NOT NULL DEFAULT 0,
            cost_micros BIGINT NOT NULL DEFAULT 0,
            message TEXT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY trace_id (trace_id),
            KEY user_kind (user_id, kind),
            KEY user_status (user_id, status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function maybe_install(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public function new_trace_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        string $trace_id,
        string $kind,
        string $status,
        array $context = []
    ): string {
        global $wpdb;
        self::maybe_install();

        $id = $this->new_trace_id();
        $row = [
            'id' => $id,
            'trace_id' => substr($trace_id, 0, 36),
            // WHOSE trace it is: a person of the platform.
            'user_id' => \ProjectFlash\Agent\PersonColumns::current() ?: null,
            'kind' => substr($kind, 0, 32),
            'status' => substr($status, 0, 32),
            'tool' => substr((string) ($context['tool'] ?? ''), 0, 64),
            'provider_id' => substr((string) ($context['providerId'] ?? ''), 0, 64),
            'duration_ms' => max(0, (int) ($context['durationMs'] ?? 0)),
            'http_status' => max(0, (int) ($context['httpStatus'] ?? 0)),
            'error_code' => substr((string) ($context['errorCode'] ?? ''), 0, 64),
            'tokens_total' => max(0, (int) ($context['tokensTotal'] ?? 0)),
            'cost_micros' => max(0, (int) ($context['costMicros'] ?? 0)),
            'message' => $this->redact((string) ($context['message'] ?? '')),
            'context_json' => $this->encode_context($context),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $inserted = $wpdb->insert($wpdb->prefix . 'pfa_trace_log', $row);

        return $inserted === false ? '' : $id;
    }

    /**
     * @param array{user_id?: string, kind?: string, status?: string, since?: string, limit?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function query(array $filters = []): array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();

        $where = ['1=1'];
        $values = [];

        if (isset($filters['user_id'])) {
            // The trace belongs to a person, so the filter takes their sys_id.
            $where[] = 'user_id = %s';
            $values[] = strtolower((string) $filters['user_id']);
        }
        if (!empty($filters['kind'])) {
            $where[] = 'kind = %s';
            $values[] = (string) $filters['kind'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = (string) $filters['status'];
        }
        if (!empty($filters['trace_id'])) {
            $where[] = 'trace_id = %s';
            $values[] = (string) $filters['trace_id'];
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= %s';
            $values[] = (string) $filters['since'];
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $prepared = $values === [] ? $sql : $wpdb->prepare($sql, $values);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? array_map([$this, 'hydrate_row'], $rows) : [];
    }

    /**
     * @param array{user_id?: string, since?: string} $filters
     * @return array{byKind: array<string, int>, byStatus: array<string, int>, byProvider: array<string, int>, totalRows: int}
     */
    public function aggregate(array $filters = []): array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();

        $where = ['1=1'];
        $values = [];
        if (isset($filters['user_id'])) {
            $where[] = 'user_id = %s';
            $values[] = strtolower((string) $filters['user_id']);
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= %s';
            $values[] = (string) $filters['since'];
        }
        $where_sql = implode(' AND ', $where);

        $by_kind = $this->grouped_count($table, $where_sql, $values, 'kind');
        $by_status = $this->grouped_count($table, $where_sql, $values, 'status');
        $by_provider = $this->grouped_count($table, $where_sql, $values, 'provider_id');
        $cost_by_provider = $this->grouped_sum($table, $where_sql, $values, 'provider_id', 'cost_micros');
        $tokens_by_provider = $this->grouped_sum($table, $where_sql, $values, 'provider_id', 'tokens_total');

        $total_sql = 'SELECT COUNT(*) AS total_rows, COALESCE(SUM(tokens_total), 0) AS total_tokens, COALESCE(SUM(cost_micros), 0) AS total_cost FROM ' . $table . ' WHERE ' . $where_sql;
        $total_prepared = $values === [] ? $total_sql : $wpdb->prepare($total_sql, $values);
        $total_row = $wpdb->get_row($total_prepared, ARRAY_A);

        // Per-tool aggregation is restricted to workflow_api rows because
        // those are the ones where the agent decides "call list / call
        // full / call apply". Surface call counts and avg durations so the
        // operator can detect anti-patterns (eg. relisting after an item
        // was already in conversation context, or repeat full() fetches
        // for the same workflow).
        $tool_where = $where_sql . ' AND kind = %s AND tool <> ' . "''";
        $tool_values = array_merge($values, [self::KIND_WORKFLOW_API]);
        $tool_sql = 'SELECT tool, COUNT(*) AS calls, COALESCE(AVG(duration_ms), 0) AS avg_duration FROM ' . $table . ' WHERE ' . $tool_where . ' GROUP BY tool';
        $tool_prepared = $wpdb->prepare($tool_sql, $tool_values);
        $tool_rows = (array) $wpdb->get_results($tool_prepared, ARRAY_A);
        $calls_by_tool = [];
        $avg_duration_by_tool = [];
        foreach ($tool_rows as $row) {
            $tool = (string) ($row['tool'] ?? '');
            if ($tool === '') {
                continue;
            }
            $calls_by_tool[$tool] = (int) ($row['calls'] ?? 0);
            $avg_duration_by_tool[$tool] = (int) round((float) ($row['avg_duration'] ?? 0));
        }

        return [
            'byKind' => $by_kind,
            'byStatus' => $by_status,
            'byProvider' => $by_provider,
            'totalRows' => (int) ($total_row['total_rows'] ?? 0),
            'totalTokens' => (int) ($total_row['total_tokens'] ?? 0),
            'totalCostMicros' => (int) ($total_row['total_cost'] ?? 0),
            'tokensByProvider' => $tokens_by_provider,
            'costMicrosByProvider' => $cost_by_provider,
            'callsByTool' => $calls_by_tool,
            'avgDurationMsByTool' => $avg_duration_by_tool,
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, int>
     */
    private function grouped_sum(string $table, string $where_sql, array $values, string $column, string $sum_column): array
    {
        global $wpdb;
        $sql = "SELECT {$column} AS bucket, COALESCE(SUM({$sum_column}), 0) AS total FROM {$table} WHERE {$where_sql} GROUP BY {$column}";
        $prepared = $values === [] ? $sql : $wpdb->prepare($sql, $values);
        $rows = (array) $wpdb->get_results($prepared, ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['bucket'] ?? '');
            $out[$key === '' ? '_unknown' : $key] = (int) ($row['total'] ?? 0);
        }

        return $out;
    }

    public function purge_all(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, int>
     */
    private function grouped_count(string $table, string $where_sql, array $values, string $column): array
    {
        global $wpdb;
        $sql = "SELECT {$column} AS bucket, COUNT(*) AS total FROM {$table} WHERE {$where_sql} GROUP BY {$column}";
        $prepared = $values === [] ? $sql : $wpdb->prepare($sql, $values);
        $rows = (array) $wpdb->get_results($prepared, ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['bucket'] ?? '');
            $out[$key === '' ? '_unknown' : $key] = (int) ($row['total'] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate_row(array $row): array
    {
        $context = json_decode((string) ($row['context_json'] ?? ''), true);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'traceId' => (string) ($row['trace_id'] ?? ''),
            'userId' => (int) ($row['user_id'] ?? 0),
            'kind' => (string) ($row['kind'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'tool' => (string) ($row['tool'] ?? ''),
            'providerId' => (string) ($row['provider_id'] ?? ''),
            'durationMs' => (int) ($row['duration_ms'] ?? 0),
            'httpStatus' => (int) ($row['http_status'] ?? 0),
            'errorCode' => (string) ($row['error_code'] ?? ''),
            'tokensTotal' => (int) ($row['tokens_total'] ?? 0),
            'costMicros' => (int) ($row['cost_micros'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'context' => is_array($context) ? $context : [],
            'createdAt' => isset($row['created_at']) ? mysql_to_rfc3339((string) $row['created_at']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encode_context(array $context): string
    {
        // Drop the keys we already extracted into typed columns and the
        // ones that may carry secrets verbatim, then redact the rest.
        $stripped = $context;
        unset(
            $stripped['message'],
            $stripped['providerId'],
            $stripped['durationMs'],
            $stripped['httpStatus'],
            $stripped['errorCode'],
            $stripped['tokensTotal'],
            $stripped['costMicros']
        );

        $serialized = (string) wp_json_encode($stripped);
        if ($serialized === '' || $serialized === 'null' || $serialized === '[]') {
            return '';
        }

        return $this->redact($serialized);
    }

    private function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return ChatSessions::redact_secrets($text);
    }
}
