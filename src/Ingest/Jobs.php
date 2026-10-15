<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Relations;
use ConstructiveMedia\IntranetPhonelist\Schema;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * The queue for batches and snapshots, in its own table. A job is stored, answered with 202, then processed after the
 * response is sent; a cron sweep every minute picks up anything left queued or abandoned.
 *
 * Safe to re-run: every item is an upsert or delete guarded by updated_at, so a job interrupted part way is simply
 * run again from the start. One job runs at a time, oldest first: a sweep that finds another job running leaves it be.
 */
final class Jobs
{
    public const SWEEP_HOOK = 'iphl_jobs_sweep';
    public const MINUTE_SCHEDULE = 'iphl_minute';

    /** A running job with no heartbeat for this long is taken to be dead and picked up again. */
    private const STALE_SECONDS = 300;
    private const MAX_ATTEMPTS = 3;
    private const KEEP_DAYS = 30;
    /** A sweep stops starting jobs after this long, so it finishes inside a cron run. */
    private const SWEEP_SECONDS = 50;
    /** A snapshot may delete up to this many missing records, or more if they are at most this share of those held. */
    private const MAX_SNAPSHOT_DELETIONS = 10;
    private const MAX_SNAPSHOT_DELETE_SHARE = 0.1;

    private const SCHEMA = '1';
    private const SCHEMA_OPTION = 'intranet_phonelist_jobs_schema';

    private readonly string $table;

    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly Relations $relations,
        private readonly Log $log,
        private readonly wpdb $db,
    ) {
        $this->table = $db->prefix . 'iphl_jobs';
    }

    public function register(): void
    {
        add_filter('cron_schedules', fn (array $schedules): array => $schedules + [
            self::MINUTE_SCHEDULE => ['interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute'],
        ]);
        add_action(self::SWEEP_HOOK, [$this, 'sweep']);
        add_action('init', function (): void {
            if (!wp_next_scheduled(self::SWEEP_HOOK)) {
                wp_schedule_event(time(), self::MINUTE_SCHEDULE, self::SWEEP_HOOK);
            }
        });
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA) {
            $this->install();
        }
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$this->table} (
            id char(36) NOT NULL,
            idempotency_key varchar(100) DEFAULT NULL,
            kind varchar(10) NOT NULL,
            type varchar(20) DEFAULT NULL,
            status varchar(10) NOT NULL,
            triggered_by varchar(10) NOT NULL,
            payload longtext NOT NULL,
            results longtext,
            error text,
            total int unsigned NOT NULL DEFAULT 0,
            cursor_at int unsigned NOT NULL DEFAULT 0,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            heartbeat datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_created (status,created_at)
        ) {$this->db->get_charset_collate()};");
        update_option(self::SCHEMA_OPTION, self::SCHEMA);
    }

    /**
     * Queue a job. With an idempotency key already used, the existing job is returned instead.
     *
     * @param string $kind 'batch' (items: {op, type, id, data}) or 'snapshot' (items: {id, data}, all of $type).
     * @return array{id: string, status: string, existing: bool}
     */
    public function create(string $kind, ?string $type, array $items, ?string $idempotencyKey, string $trigger): array
    {
        if ($idempotencyKey !== null) {
            $existing = $this->db->get_row($this->db->prepare("SELECT id, status FROM {$this->table} WHERE idempotency_key = %s", $idempotencyKey));
            if ($existing) {
                return ['id' => $existing->id, 'status' => $existing->status, 'existing' => true];
            }
        }

        $id = wp_generate_uuid4();
        $stored = $this->db->insert($this->table, [
            'id' => $id,
            'idempotency_key' => $idempotencyKey,
            'kind' => $kind,
            'type' => $type,
            'status' => 'queued',
            'triggered_by' => $trigger,
            'payload' => wp_json_encode(array_values($items)),
            'results' => '[]',
            'total' => count($items),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if (!$stored) {
            // The same idempotency key arriving twice at once: the other request stored it.
            $existing = $idempotencyKey === null ? null : $this->db->get_row($this->db->prepare("SELECT id, status FROM {$this->table} WHERE idempotency_key = %s", $idempotencyKey));
            if ($existing) {
                return ['id' => $existing->id, 'status' => $existing->status, 'existing' => true];
            }
            throw new RuntimeException('The job could not be stored: ' . ($this->db->last_error ?: 'unknown database error'));
        }

        return ['id' => $id, 'status' => 'queued', 'existing' => false];
    }

    /**
     * @return array{id: string, kind: string, type: ?string, status: string, total: int, done: int, results: array, error: ?string, created_at: string, finished_at: ?string}|null
     */
    public function get(string $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %s", $id));

        return $row ? $this->describe($row) : null;
    }

    /**
     * @return array<array> The most recent jobs, newest first (without their payloads).
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, kind, type, status, triggered_by, results, error, total, cursor_at, created_at, finished_at FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));

        return array_map(fn (object $row): array => $this->describe($row) + ['triggered_by' => $row->triggered_by], $rows);
    }

    /**
     * Run one job, if it's waiting (or abandoned) and no other job is running.
     */
    public function process(string $id): void
    {
        if (!$this->busy()) {
            $this->run($id);
        }
    }

    /**
     * Work through the queue, oldest first, until it's empty or SWEEP_SECONDS have passed.
     */
    public function sweep(): void
    {
        $started = time();
        while (!$this->busy() && time() - $started < self::SWEEP_SECONDS) {
            $next = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->table} WHERE status = 'queued' OR (status = 'running' AND heartbeat < %s) ORDER BY created_at, id LIMIT 1",
                gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)
            ));
            if (!$next || !$this->run($next)) {
                break;
            }
        }
    }

    /**
     * Whether another job is running (and still alive).
     */
    private function busy(): bool
    {
        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT 1 FROM {$this->table} WHERE status = 'running' AND heartbeat >= %s LIMIT 1",
            gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)
        ));
    }

    /**
     * After the current response has been sent, work through the queue (this job included, in order).
     */
    public function runAfterResponse(): void
    {
        add_action('shutdown', function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(0);
            $this->sweep();
        }, 100);
    }

    /**
     * A snapshot without the queue (the Oracle import uses this): every item is put, then records of $type with a
     * Data Hub ID that aren't in the snapshot are deleted, then every staff link is rechecked.
     *
     * @return array<array> A result per item, then one per deleted record.
     */
    public function applySnapshot(string $type, array $items, string $runId, string $trigger): array
    {
        $results = array_map(fn (array $item): array => $this->item('snapshot', $type, $item, $runId, $trigger), $items);

        return [...$results, ...$this->finishSnapshot($type, $items, $runId, $trigger)];
    }

    /**
     * Delete finished jobs older than KEEP_DAYS. Returns jobs deleted.
     */
    public function prune(): int
    {
        return (int) $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table} WHERE status IN ('done', 'failed') AND finished_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)
        ));
    }

    /**
     * Claim and run a job. False if it couldn't be claimed (already done, or running elsewhere).
     */
    private function run(string $id): bool
    {
        $claimed = $this->db->query($this->db->prepare(
            "UPDATE {$this->table} SET status = 'running', attempts = attempts + 1, heartbeat = %s
            WHERE id = %s AND (status = 'queued' OR (status = 'running' AND heartbeat < %s))",
            gmdate('Y-m-d H:i:s'),
            $id,
            gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)
        ));
        if (!$claimed) {
            return false;
        }

        $job = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %s", $id));
        $items = json_decode($job->payload, true) ?: [];
        $results = [];
        $trigger = $job->triggered_by;

        if ((int) $job->attempts > self::MAX_ATTEMPTS) {
            $this->finish($id, 'failed', $results, 'Gave up after ' . self::MAX_ATTEMPTS . ' attempts.');
            return true;
        }
        $this->log->write($id, $trigger, 'info', 'job_start', $job->type ? (Schema::TYPES[$job->type] ?? null) : null, null, $job->kind, null, ['items' => (int) $job->total, 'attempt' => (int) $job->attempts]);

        try {
            foreach ($items as $i => $item) {
                $results[] = $this->item($job->kind, $job->type, $item, $id, $trigger);
                // Progress for the Jobs tab, and a sign of life; the results are saved once, at the end.
                $this->db->update($this->table, ['cursor_at' => $i + 1, 'heartbeat' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            }
            if ($job->kind === 'snapshot') {
                $results = [...$results, ...$this->finishSnapshot($job->type, $items, $id, $trigger)];
            }
            $this->finish($id, 'done', $results);
        } catch (Throwable $e) {
            $this->db->update($this->table, ['status' => (int) $job->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'error' => $e->getMessage()], ['id' => $id]);
            $this->log->write($id, $trigger, 'error', 'job_error', null, null, $job->kind, $e->getMessage());
        }

        return true;
    }

    private function finish(string $id, string $status, array $results, ?string $error = null): void
    {
        $this->db->update($this->table, ['status' => $status, 'results' => wp_json_encode(array_values($results)), 'error' => $error, 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        $job = $this->db->get_row($this->db->prepare("SELECT kind, triggered_by FROM {$this->table} WHERE id = %s", $id));
        $this->log->write($id, $job->triggered_by, $status === 'done' ? 'info' : 'error', 'job_end', null, null, $job->kind, $error, [
            'status' => $status,
            'results' => array_count_values(array_column($results, 'result')),
        ]);
    }

    /**
     * One item through the Ingestor, as a result row: {id, type, op, result, errors?, message?}.
     */
    private function item(string $kind, ?string $type, mixed $item, string $runId, string $trigger): array
    {
        $item = is_array($item) ? $item : [];
        if ($kind === 'snapshot') {
            $item = ['op' => 'put', 'type' => $type, 'id' => $item['id'] ?? null, 'data' => $item['data'] ?? []];
        }
        $outcome = $this->ingestor->apply($item, $runId, $trigger);

        return ['id' => is_string($item['id'] ?? null) ? $item['id'] : null, 'type' => $item['type'] ?? null, 'op' => $item['op'] ?? null] + $outcome->toArray();
    }

    /**
     * After a snapshot's items: delete records of $type with a Data Hub ID the snapshot didn't list, as of its newest
     * updated_at (so a record changed since is kept), then recheck every staff link.
     *
     * Nothing is deleted when any item lacks a usable id, or when the deletions would exceed MAX_SNAPSHOT_DELETIONS
     * and MAX_SNAPSHOT_DELETE_SHARE of the records held: a malformed or truncated snapshot can't empty the phone list.
     */
    private function finishSnapshot(string $type, array $items, string $runId, string $trigger): array
    {
        $ids = array_map(fn ($item): ?string => is_string($item['id'] ?? null) && $item['id'] !== '' ? $item['id'] : null, $items);
        $listed = array_flip(array_filter($ids));
        $times = array_filter(array_map(fn ($item) => Schema::updatedAt(is_array($item['data'] ?? null) ? $item['data'] : []), $items));
        $newest = $times ? max($times)->format(DATE_RFC3339) : null;
        $held = $this->db->get_col($this->db->prepare(
            "SELECT m.meta_value FROM {$this->db->posts} p JOIN {$this->db->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s",
            Schema::TYPES[$type],
            PostTypes::SOURCE_ID
        ));
        $missing = array_values(array_filter($held, fn (string $id): bool => !isset($listed[$id])));

        $refused = match (true) {
            in_array(null, $ids, true) => 'Some records have no usable id (a non-empty string), so nothing was deleted.',
            $newest === null => 'No record has a valid updated_at, so nothing was deleted.',
            count($missing) > self::MAX_SNAPSHOT_DELETIONS && count($missing) > count($held) * self::MAX_SNAPSHOT_DELETE_SHARE
                => sprintf('The snapshot would delete %d of %d records, more than allowed at once, so nothing was deleted.', count($missing), count($held)),
            default => null,
        };

        $results = [];
        if ($refused !== null) {
            if ($missing) {
                $this->log->write($runId, $trigger, 'error', 'snapshot_refused', Schema::TYPES[$type], null, $type, $refused, ['would_delete' => count($missing)]);
                $results[] = ['id' => null, 'type' => $type, 'op' => 'delete', 'result' => 'error', 'message' => $refused];
            }
        } else {
            foreach ($missing as $id) {
                $results[] = ['id' => $id, 'type' => $type, 'op' => 'delete'] + $this->ingestor->delete($type, $id, $newest, $runId, $trigger)->toArray();
            }
        }
        $this->relations->relinkAll();

        return $results;
    }

    private function describe(object $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'type' => $row->type,
            'status' => $row->status,
            'total' => (int) $row->total,
            'done' => (int) $row->cursor_at,
            'results' => json_decode((string) $row->results, true) ?: [],
            'error' => $row->error,
            'created_at' => $row->created_at,
            'finished_at' => $row->finished_at,
        ];
    }
}
