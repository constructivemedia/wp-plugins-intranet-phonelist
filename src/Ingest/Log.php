<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use wpdb;

/**
 * Audit log table: every request and record outcome, with a level and an optional JSON payload.
 * Entries older than days() are pruned daily.
 */
final class Log
{
    public const LEVELS = ['error', 'warning', 'notice', 'info'];

    private const DEFAULT_DAYS = 90;

    /** Bump when the table changes; the table is upgraded on the next load. */
    private const SCHEMA = '4';
    private const SCHEMA_OPTION = 'intranet_phonelist_log_schema';

    private readonly string $table;

    public function __construct(private readonly wpdb $db)
    {
        $this->table = $db->prefix . 'intranet_phonelist_log';
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) NOT NULL,
            created_at datetime NOT NULL,
            triggered_by varchar(10) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            level varchar(10) NOT NULL DEFAULT 'info',
            action varchar(20) NOT NULL,
            post_type varchar(20) DEFAULT NULL,
            source_id varchar(64) DEFAULT NULL,
            label varchar(255) NOT NULL DEFAULT '',
            message text,
            context longtext,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY created_at (created_at),
            KEY level (level),
            KEY action (action),
            KEY source (post_type,source_id)
        ) {$this->db->get_charset_collate()};");
        update_option(self::SCHEMA_OPTION, self::SCHEMA);
    }

    /**
     * Upgrade the table on sites installed with an older schema (dbDelta only ever adds).
     */
    public function maybeUpgrade(): void
    {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA) {
            $this->install();
        }
    }

    public function write(
        string $runId,
        string $trigger,
        string $level,
        string $action,
        ?string $postType = null,
        ?string $sourceId = null,
        string $label = '',
        ?string $message = null,
        ?array $context = null,
    ): void {
        $this->db->insert($this->table, [
            'run_id' => $runId,
            'created_at' => current_time('mysql', true),
            'triggered_by' => $trigger,
            'user_id' => get_current_user_id(), // 0 for cron/CLI.
            'level' => $level,
            'action' => $action,
            'post_type' => $postType,
            'source_id' => $sourceId,
            'label' => mb_substr($label, 0, 255), // The column's size: a longer label would lose the whole row.
            'message' => $message,
            'context' => $context ? wp_json_encode($context) : null,
        ]);
    }

    /**
     * Newest first, optionally filtered by level, action and/or run.
     *
     * @return array{rows: object[], total: int}
     */
    public function page(int $page, int $perPage, array $filters = []): array
    {
        $where = '1=1';
        $args = [];
        foreach (['level', 'action', 'run_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $where .= " AND $column = %s";
                $args[] = $filters[$column];
            }
        }

        $count = "SELECT COUNT(*) FROM {$this->table} WHERE $where";

        return [
            'total' => (int) $this->db->get_var($args ? $this->db->prepare($count, $args) : $count),
            'rows' => $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->table} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
                [...$args, $perPage, ($page - 1) * $perPage]
            )),
        ];
    }

    /**
     * @return string[] Every action that has been logged, for the filter.
     */
    public function actions(): array
    {
        return $this->db->get_col("SELECT DISTINCT action FROM {$this->table} ORDER BY action");
    }

    /**
     * @return array<string, object{started: string, triggered_by: string, user_id: int, status: ?string}> The 50 most recent runs, newest first, keyed by run ID.
     */
    public function runs(): array
    {
        $rows = $this->db->get_results(
            "SELECT run_id, MIN(created_at) AS started, MAX(triggered_by) AS triggered_by, MAX(user_id) AS user_id,
                MAX(CASE WHEN action = 'job_end' THEN JSON_UNQUOTE(JSON_EXTRACT(context, '$.status')) END) AS status
            FROM {$this->table} GROUP BY run_id ORDER BY started DESC LIMIT 50"
        );

        return array_column($rows, null, 'run_id');
    }

    /**
     * Days entries are kept: INTRANET_PHONELIST_LOG_DAYS, else 90.
     */
    public static function days(): int
    {
        return max(1, defined('INTRANET_PHONELIST_LOG_DAYS') ? (int) INTRANET_PHONELIST_LOG_DAYS : self::DEFAULT_DAYS);
    }

    /**
     * Delete entries older than days(). Run daily. Returns rows deleted.
     */
    public function prune(): int
    {
        return $this->delete(self::days());
    }

    /**
     * Delete entries older than $days, or everything when $days is 0. Returns rows deleted.
     */
    public function delete(int $days = 0): int
    {
        return (int) ($days > 0
            ? $this->db->query($this->db->prepare("DELETE FROM {$this->table} WHERE created_at < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)))
            : $this->db->query("DELETE FROM {$this->table}"));
    }
}
