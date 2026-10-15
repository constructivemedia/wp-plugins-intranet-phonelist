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
 * A job is a list of operations ({op, type, id, data}); a snapshot is stored as one put per record. Safe to re-run:
 * every operation is guarded by updated_at, so a job interrupted part way is simply run again from the start.
 * One job runs at a time, oldest first: a sweep that finds another job running leaves it be.
 */
final class Jobs
{
    private const SWEEP_HOOK = 'iphl_jobs_sweep';
    private const MINUTE_SCHEDULE = 'iphl_minute';

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
     * Queue a batch (operations) or a snapshot (every record of $type). With an idempotency key already used, the
     * existing job is returned instead. Throws if the job can't be stored.
     *
     * @return array{id: string, status: string, existing: bool}
     */
    public function create(string $kind, ?string $type, array $items, ?string $idempotencyKey, string $trigger): array
    {
        $existing = fn () => $idempotencyKey === null ? null : $this->db->get_row($this->db->prepare("SELECT id, status FROM {$this->table} WHERE idempotency_key = %s", $idempotencyKey));
        if ($job = $existing()) {
            return ['id' => $job->id, 'status' => $job->status, 'existing' => true];
        }

        $id = wp_generate_uuid4();
        $operations = $kind === 'snapshot' ? self::puts((string) $type, $items) : array_values($items);
        $stored = $this->db->insert($this->table, [
            'id' => $id,
            'idempotency_key' => $idempotencyKey,
            'kind' => $kind,
            'type' => $type,
            'status' => 'queued',
            'triggered_by' => $trigger,
            'payload' => wp_json_encode($operations),
            'total' => count($operations),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$stored) {
            // The same idempotency key arriving twice at once: the other request stored it.
            if ($job = $existing()) {
                return ['id' => $job->id, 'status' => $job->status, 'existing' => true];
            }
            throw new RuntimeException('The job could not be stored: ' . ($this->db->last_error ?: 'unknown database error'));
        }

        return ['id' => $id, 'status' => 'queued', 'existing' => false];
    }

    /**
     * @return array{id: string, kind: string, type: ?string, status: string, triggered_by: string, total: int, done: int, results: array, error: ?string, created_at: string, finished_at: ?string}|null
     */
    public function get(string $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %s", $id));

        return $row ? $this->describe($row) : null;
    }

    /**
     * @return array<array> The most recent jobs, newest first.
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, kind, type, status, triggered_by, results, error, total, cursor_at, created_at, finished_at FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));

        return array_map($this->describe(...), $rows);
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
     * Run one job now (the Jobs tab's Run now, `wp phonelist job`). A failed job is retried with fresh attempts.
     * Returns false if it isn't waiting or failed, or another job is running.
     */
    public function process(string $id): bool
    {
        if ($this->busy()) {
            return false;
        }
        $this->db->query($this->db->prepare("UPDATE {$this->table} SET status = 'queued', attempts = 0, error = NULL WHERE id = %s AND status = 'failed'", $id));

        return $this->run($id);
    }

    /**
     * After the current response has been sent, work through the queue (the new job included, in order).
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
     * A snapshot without the queue (the Oracle import uses this): every record is put, then records of $type with a
     * Data Hub ID that aren't in the snapshot are deleted, then every staff link is rechecked.
     *
     * @return array<array> A result per record, then one per deleted record.
     */
    public function applySnapshot(string $type, array $records, string $runId, string $trigger): array
    {
        $operations = self::puts($type, $records);
        $results = array_map(fn (array $operation): array => $this->apply($operation, $runId, $trigger), $operations);

        return [...$results, ...$this->finishSnapshot($type, $operations, $runId, $trigger)];
    }

    /**
     * Delete finished jobs older than KEEP_DAYS. Run daily. Returns jobs deleted.
     */
    public function prune(): int
    {
        return (int) $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table} WHERE status IN ('done', 'failed') AND finished_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)
        ));
    }

    /**
     * A snapshot's records as put operations.
     */
    private static function puts(string $type, array $records): array
    {
        return array_map(fn ($record): array => [
            'op' => 'put',
            'type' => $type,
            'id' => is_array($record) ? ($record['id'] ?? null) : null,
            'data' => is_array($record) ? $record : [],
        ], array_values($records));
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
        if ((int) $job->attempts > self::MAX_ATTEMPTS) {
            $this->finish($job, 'failed', [], 'Gave up after ' . self::MAX_ATTEMPTS . ' attempts.');
            return true;
        }
        $operations = json_decode($job->payload, true) ?: [];
        $this->log->write($id, $job->triggered_by, 'info', 'job_start', $job->type ? (Schema::TYPES[$job->type] ?? null) : null, null, $job->kind, null, ['items' => (int) $job->total, 'attempt' => (int) $job->attempts]);

        try {
            $results = [];
            foreach ($operations as $i => $operation) {
                $results[] = $this->apply($operation, $id, $job->triggered_by);
                // Progress for the Jobs tab, and a sign of life; the results are saved once, at the end.
                $this->db->update($this->table, ['cursor_at' => $i + 1, 'heartbeat' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            }
            if ($job->kind === 'snapshot') {
                $results = [...$results, ...$this->finishSnapshot($job->type, $operations, $id, $job->triggered_by)];
            }
            $this->finish($job, 'done', $results);
        } catch (Throwable $e) {
            $this->db->update($this->table, ['status' => (int) $job->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'error' => $e->getMessage()], ['id' => $id]);
            $this->log->write($id, $job->triggered_by, 'error', 'job_error', null, null, $job->kind, $e->getMessage());
        }

        return true;
    }

    private function finish(object $job, string $status, array $results, ?string $error = null): void
    {
        $this->db->update($this->table, ['status' => $status, 'results' => wp_json_encode($results), 'error' => $error, 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $job->id]);
        $this->log->write($job->id, $job->triggered_by, $status === 'done' ? 'info' : 'error', 'job_end', null, null, $job->kind, $error, [
            'status' => $status,
            'results' => array_count_values(array_column($results, 'result')),
        ]);
    }

    /**
     * One operation through the Ingestor, as a result row: {id, type, op, result, errors?, warnings?, message?}.
     */
    private function apply(mixed $operation, string $runId, string $trigger): array
    {
        $operation = is_array($operation) ? $operation : [];

        return [
            'id' => is_string($operation['id'] ?? null) ? $operation['id'] : null,
            'type' => $operation['type'] ?? null,
            'op' => $operation['op'] ?? null,
        ] + $this->ingestor->apply($operation, $runId, $trigger)->toArray();
    }

    /**
     * After a snapshot's puts: delete records of $type with a Data Hub ID the snapshot didn't list, as of its newest
     * updated_at (so a record changed since is kept), then recheck every staff link.
     *
     * Nothing is deleted when any record lacks a usable id, or when the deletions would exceed MAX_SNAPSHOT_DELETIONS
     * and MAX_SNAPSHOT_DELETE_SHARE of the records held: a malformed or truncated snapshot can't empty the phone list.
     */
    private function finishSnapshot(string $type, array $operations, string $runId, string $trigger): array
    {
        $ids = array_map(fn (array $operation): ?string => is_string($operation['id']) && $operation['id'] !== '' ? $operation['id'] : null, $operations);
        $listed = array_flip(array_filter($ids));
        $times = array_filter(array_map(fn (array $operation) => Schema::updatedAt($operation['data']), $operations));
        $newest = $times ? max($times)->format(DATE_RFC3339) : null;
        $held = PostTypes::sourceIds(Schema::TYPES[$type]);
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
                $results[] = $this->apply(['op' => 'delete', 'type' => $type, 'id' => $id, 'data' => ['updated_at' => $newest]], $runId, $trigger);
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
            'triggered_by' => $row->triggered_by,
            'total' => (int) $row->total,
            'done' => (int) $row->cursor_at,
            'results' => json_decode((string) $row->results, true) ?: [],
            'error' => $row->error,
            'created_at' => $row->created_at,
            'finished_at' => $row->finished_at,
        ];
    }
}
