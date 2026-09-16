<?php

declare(strict_types=1);


namespace ProjectFlash\Agent;

/**
 * ONE ENGINE for the columns of OUR tables that name a person.
 *
 * The record tables took this road already: a person is a row of `user` and the
 * column holds its sys_id. The platform's own tables did not — group membership,
 * who consented to an OAuth code, who fired an event, who wrote a journal entry
 * were all a `wp_users.ID`. That is what made a person a WordPress account: no
 * account, no membership, therefore no roles, therefore not able to be anything.
 *
 * A table joins by being NAMED here, never by getting its own method. The shape
 * is the one the general runbook prescribes and it is the same every time:
 *
 *   photograph  → is the column still an integer? if not, there is nothing to do
 *   widen       → add the destination column beside it, nullable
 *   re-key      → resolve every distinct id through `user.account`, in ONE
 *                 statement per column, not one per row
 *   verify      → read the rows back; only then drop the old column and rename
 *
 * NO FLAG GUARDS IT. The photograph IS the guard: once the column is CHAR(32)
 * the pass does nothing, so it cannot run twice and it finishes a half-done job
 * if it was interrupted. A pass that cannot verify leaves the ORIGINAL column in
 * place and reports — half a table is worse than not having started.
 *
 * A value that resolves to no `user` row becomes NULL, and the count is
 * reported. That is not data loss to hide: it is a WordPress account nobody
 * mirrored, and an id nobody can read is not an answer either.
 */
final class PersonColumns
{
    /** Destination shape: the sys_id as lower-case hex, like every other reference we print. */
    private const SHAPE = 'CHAR(32) NULL DEFAULT NULL';

    /** Every column of ours that names a person. A table joins by being NAMED. */
    private const COLUMNS = [
        ['pfaf_conversations', 'owner_user_id'],
        ['pfa_trace_log', 'user_id'],
    ];

    public static function run_all(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $res = self::convert($table, $column);
            if (($res['status'] ?? '') === 'failed') {
                error_log("[pfa] person column $table.$column NOT converted: " . ($res['reason'] ?? '?'));
            }
        }
    }

    /**
     * The `user` row a WordPress account mirrors, or ''.
     *
     * Read straight from the table rather than through PFM's classes: this runs
     * during schema setup and on every write, and PFM may not be loaded.
     */
    public static function person_for_account(int $account): string
    {
        if ($account <= 0) {
            return '';
        }
        global $wpdb;
        $people = $wpdb->prefix . 'pfm_rec_user';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $people)) !== $people) {
            return '';
        }
        $sid = $wpdb->get_var($wpdb->prepare("SELECT LOWER(HEX(sys_id)) FROM `$people` WHERE account = %d LIMIT 1", $account));
        return is_string($sid) ? $sid : '';
    }

    /** The person acting right now, or '' when there is nobody to name. */
    public static function current(): string
    {
        return self::person_for_account(function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
    }

    /**
     * Convert one column, or answer why it was not converted.
     *
     * @param string $table  table name WITHOUT the site prefix
     * @param string $column the column holding a wp_users id
     * @return array{status:string, rows?:int, unresolved?:int, reason?:string}
     */
    public static function convert(string $table, string $column): array
    {
        global $wpdb;
        $tab = $wpdb->prefix . $table;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tab)) !== $tab) {
            return ['status' => 'skipped', 'reason' => 'no such table'];
        }
        $def = $wpdb->get_row("SHOW COLUMNS FROM `$tab` LIKE '$column'", ARRAY_A);
        if ($def === null) {
            return ['status' => 'skipped', 'reason' => 'no such column'];
        }
        if (stripos((string) $def['Type'], 'int') === false) {
            // Already the destination shape — but a row can still carry the id
            // as TEXT if dbDelta got to the column before this engine did (it
            // rewrites 1 as '1'). Those name nobody, so they are resolved with
            // the same join and, when they resolve to nothing, removed: a
            // membership pointing at a person who does not exist grants a role
            // to nobody and hides the fact that it does.
            return ['status' => 'done', 'reason' => 'already a person']
                + self::heal_text_integers($tab, $column);
        }
        $people = $wpdb->prefix . 'pfm_rec_user';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $people)) !== $people) {
            // Without the directory there is nothing to resolve AGAINST, and a
            // pass that nulled every value would be destroying the only copy of
            // who did what. Leave it exactly as it is.
            return ['status' => 'deferred', 'reason' => 'the user table is not here yet'];
        }

        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tab`");
        $named  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tab` WHERE `$column` IS NOT NULL AND `$column` <> 0");
        $tmp = $column . '__person';

        // Widen: the destination beside the original, so both are on the row at
        // the same time and the re-key is a join instead of a lookup in PHP.
        $wpdb->query("ALTER TABLE `$tab` ADD COLUMN `$tmp` " . self::SHAPE);
        if ($wpdb->last_error !== '') {
            return ['status' => 'failed', 'reason' => 'add column: ' . $wpdb->last_error];
        }

        // Re-key: one statement, every row.
        $wpdb->query(
            "UPDATE `$tab` t
               JOIN `$people` u ON u.account = t.`$column`
                SET t.`$tmp` = LOWER(HEX(u.sys_id))
              WHERE t.`$column` IS NOT NULL AND t.`$column` <> 0"
        );
        if ($wpdb->last_error !== '') {
            $wpdb->query("ALTER TABLE `$tab` DROP COLUMN `$tmp`");
            return ['status' => 'failed', 'reason' => 're-key: ' . $wpdb->last_error];
        }

        // Verify by READING THE ROWS BACK, not by trusting the UPDATE's return.
        $resolved   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tab` WHERE `$tmp` IS NOT NULL");
        $unresolved = $named - $resolved;
        $after      = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tab`");
        if ($after !== $before) {
            $wpdb->query("ALTER TABLE `$tab` DROP COLUMN `$tmp`");
            return ['status' => 'failed', 'reason' => "row count moved: $before -> $after"];
        }

        // Swap. The primary key may sit ON this column (group membership does),
        // so it is dropped and put back in the SAME statement — two ALTERs can
        // be interrupted between them and leave a table with no key.
        $pk = self::primary_key_columns($tab);
        $sql = "ALTER TABLE `$tab` DROP COLUMN `$column`, CHANGE `$tmp` `$column` " . self::SHAPE;
        if (in_array($column, $pk, true)) {
            $others = array_values(array_filter($pk, static fn (string $c): bool => $c !== $column));
            $cols = array_map(static fn (string $c): string => "`$c`", array_merge($others, [$column]));
            $sql .= ', DROP PRIMARY KEY, ADD PRIMARY KEY (' . implode(', ', $cols) . ')';
        }
        $wpdb->query($sql);
        if ($wpdb->last_error !== '') {
            return ['status' => 'failed', 'reason' => 'swap: ' . $wpdb->last_error];
        }

        return ['status' => 'converted', 'rows' => $resolved, 'unresolved' => max(0, $unresolved)];
    }

    /**
     * Rows whose value is still a WordPress id written as text.
     *
     * @return array{healed?:int, dropped?:int}
     */
    private static function heal_text_integers(string $table, string $column): array
    {
        global $wpdb;
        $people = $wpdb->prefix . 'pfm_rec_user';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $people)) !== $people) {
            return [];
        }
        $stale = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$table` WHERE `$column` REGEXP '^[0-9]{1,10}$'"
        );
        if ($stale === 0) {
            return [];
        }
        // IGNORE, and it is the point rather than a shortcut: where this column
        // is part of a key — group membership is (group_id, user_id) — the same
        // person may ALREADY be in the row the sync added, and the update would
        // collide with it. A collision means the good row is there, so the stale
        // one is redundant; it stays as text and the delete below takes it.
        $wpdb->query(
            "UPDATE IGNORE `$table` t
               JOIN `$people` u ON u.account = CAST(t.`$column` AS UNSIGNED)
                SET t.`$column` = LOWER(HEX(u.sys_id))
              WHERE t.`$column` REGEXP '^[0-9]{1,10}$'"
        );
        $left = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$table` WHERE `$column` REGEXP '^[0-9]{1,10}$'"
        );
        if ($left > 0) {
            $wpdb->query("DELETE FROM `$table` WHERE `$column` REGEXP '^[0-9]{1,10}$'");
        }
        return ['healed' => $stale - $left, 'dropped' => $left];
    }

    /** @return array<int, string> */
    private static function primary_key_columns(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r['Seq_in_index']] = (string) $r['Column_name'];
        }
        ksort($out);
        return array_values($out);
    }
}
