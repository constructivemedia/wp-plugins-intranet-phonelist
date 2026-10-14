<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use wpdb;

/**
 * Audit log table: every sync event with a level and an optional JSON payload. Kept until deleted in settings.
 */
final class Log
{
    public const LEVELS = ['error', 'warning', 'notice', 'info', 'debug'];

    /** Bump when the table changes; the table is upgraded on the next load. */
    private const SCHEMA = '2';
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
            KEY action (action)
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
            'level' => $level,
            'action' => $action,
            'post_type' => $postType,
            'source_id' => $sourceId,
            'label' => $label,
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
     * @return array<string, object{started: string, triggered_by: string, status: ?string}> Recent runs, newest first, keyed by run ID.
     */
    public function runs(int $limit = 50): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT run_id, MIN(created_at) AS started, MAX(triggered_by) AS triggered_by,
                MAX(CASE WHEN action = 'sync_end' THEN label END) AS status
            FROM {$this->table} GROUP BY run_id ORDER BY started DESC LIMIT %d",
            $limit
        ));

        return array_column($rows, null, 'run_id');
    }

    /**
     * @return array<string, int> Level => entries in one run.
     */
    public function levelCounts(string $runId): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT level, COUNT(*) n FROM {$this->table} WHERE run_id = %s GROUP BY level", $runId));

        return array_column(array_map(fn (object $row): array => [$row->level, (int) $row->n], $rows), 1, 0);
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
