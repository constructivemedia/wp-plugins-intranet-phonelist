<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use wpdb;

/**
 * Audit log table: every sync start/end and every create, update, delete and error. Kept until deleted in settings.
 */
final class Log
{
    public const ACTIONS = ['sync_start', 'created', 'updated', 'deleted', 'photo', 'error', 'sync_end'];

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
            action varchar(20) NOT NULL,
            post_type varchar(20) DEFAULT NULL,
            source_id varchar(64) DEFAULT NULL,
            label varchar(255) NOT NULL DEFAULT '',
            message text,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY created_at (created_at),
            KEY action (action)
        ) {$this->db->get_charset_collate()};");
    }

    public function write(string $runId, string $trigger, string $action, ?string $postType = null, ?string $sourceId = null, string $label = '', ?string $message = null): void
    {
        $this->db->insert($this->table, [
            'run_id' => $runId,
            'created_at' => current_time('mysql', true),
            'triggered_by' => $trigger,
            'action' => $action,
            'post_type' => $postType,
            'source_id' => $sourceId,
            'label' => $label,
            'message' => $message,
        ]);
    }

    /**
     * Newest first, optionally filtered by action and/or run.
     *
     * @return array{rows: object[], total: int}
     */
    public function page(int $page, int $perPage, ?string $action = null, ?string $runId = null): array
    {
        $where = '1=1';
        $args = [];
        if ($action) {
            $where .= ' AND action = %s';
            $args[] = $action;
        }
        if ($runId) {
            $where .= ' AND run_id = %s';
            $args[] = $runId;
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
     * Delete entries older than $days, or everything when $days is 0. Returns rows deleted.
     */
    public function delete(int $days = 0): int
    {
        return (int) ($days > 0
            ? $this->db->query($this->db->prepare("DELETE FROM {$this->table} WHERE created_at < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)))
            : $this->db->query("DELETE FROM {$this->table}"));
    }
}
