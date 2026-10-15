<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * Oracle → WordPress.
 *
 * Full sync mirrors: anyone (or any staffed division) missing from Oracle is deleted.
 * Single staff sync touches only that person; divisions and everyone else are untouched.
 *
 * Changes are detected by a hash of the Oracle row only, so changing how titles are built
 * (PostTypes::synced()) won't touch existing posts until their Oracle data changes.
 */
final class Synchronizer
{
    private const LOCK = 'intranet_phonelist_lock';
    /** No heartbeat for this long = the sync holding the lock is dead. */
    private const LOCK_TTL = 300;
    public const LAST_SYNC = 'intranet_phonelist_last_sync';
    /** WP-Cron hook that runs a Sync Now in the background. */
    public const BACKGROUND_HOOK = 'intranet_phonelist_sync_background';

    private string $runId = '';
    private int $lastBeat = 0;
    /** What the sync is doing right now, shown on the Sync tab and printed by WP-CLI. */
    private string $step = '';
    /** @var callable|null */
    private $onProgress = null;
    private string $trigger = '';
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, array<string, array>> Type => key => the row exactly as oci8 returned it, for the log. */
    private array $raw = [];
    /** @var array<string, array> "type|key" => created/updated entry, held until photos have run so each person gets one entry. */
    private array $pending = [];

    public function __construct(
        private readonly OracleSource $source,
        private readonly Photos $photos,
        private readonly CompanyMap $companies,
        private readonly Log $log,
        private readonly wpdb $db,
    ) {
    }

    /**
     * @param string $trigger 'cli' (WP-CLI or cron) or 'manual' (Sync Now; the user is logged with each entry).
     * @return array{status: string, counts: array<string, int>, message: string}
     */
    public function run(string $trigger, ?string $staffId = null): array
    {
        $full = $staffId === null;
        $this->runId = wp_generate_uuid4();
        $this->trigger = $trigger;
        $this->counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0, 'photos' => 0, 'no_photo' => 0];

        if (!$this->lock()) {
            $lock = $this->running();
            $busy = $lock
                ? sprintf('A sync is already running (started %s, last activity %s).', wp_date('g:i:sa', $lock['started']), wp_date('g:i:sa', $lock['beat']))
                : 'A sync is already running.';
            $this->record('error', 'error', message: $busy);
            return ['status' => 'failed', 'counts' => $this->counts, 'message' => $busy];
        }

        // A full first sync can take minutes: don't let PHP's time limit or a closed browser tab cut it short.
        @set_time_limit(0);
        ignore_user_abort(true);

        $this->record('info', 'sync_start', message: $full ? 'Full sync.' : "Single staff sync: $staffId.");
        $message = '';
        $this->pending = [];

        try {
            $types = $full ? PostTypes::synced() : [PostTypes::STAFF => PostTypes::synced()[PostTypes::STAFF]];
            $rows = $this->fetchAll($types, $full, $staffId);

            foreach ($types as $type => $config) {
                $existing = $this->existing($type);
                if (!$full) {
                    // Only this person is in scope, so only they can be deleted.
                    $existing = array_filter($existing, fn (array $post): bool => $post['source_id'] === $staffId);
                }
                $plan = Planner::plan($rows[$type], $existing, $config['key']);
                $this->debug('plan', $type, ['create' => count($plan['create']), 'update' => count($plan['update']), 'delete' => count($plan['delete'])]);
                $this->progress(sprintf('%s: %d to create, %d to update, %d to delete.', $config['plural'], count($plan['create']), count($plan['update']), count($plan['delete'])));
                $this->apply($type, $config, $plan);
            }

            if ($full) {
                $this->linkCompanies();
            }
            if ($this->photos->enabled()) {
                $this->attachPhotos($rows[PostTypes::STAFF]);
            }
            if ($full) {
                $this->warnUnassignedDivisions();
            }

            $status = $this->counts['errors'] ? 'completed_with_errors' : 'completed';
        } catch (Throwable $e) {
            $this->counts['errors']++;
            $status = 'failed';
            $message = $e->getMessage();
            $this->record('error', 'error', message: $message);
        } finally {
            $this->flushPending();
            $this->unlock();
        }

        ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'errors' => $errors, 'photos' => $photos, 'no_photo' => $noPhoto] = $this->counts;
        $message = trim("$message Created $created, updated $updated, deleted $deleted, errors $errors.");
        if ($this->photos->enabled()) {
            $message .= " Photos added $photos, no photo $noPhoto.";
        }
        $level = ['completed' => 'info', 'completed_with_errors' => 'warning', 'failed' => 'error'][$status];
        $this->record($level, 'sync_end', message: $message, context: ['status' => $status, 'counts' => $this->counts]);
        if ($full) {
            update_option(self::LAST_SYNC, ['time' => time(), 'trigger' => $trigger, 'status' => $status, 'message' => $message, 'run' => $this->runId], false);
        }

        return ['status' => $status, 'counts' => $this->counts, 'message' => $message];
    }

    /**
     * Fetch everything before touching WordPress, so a bad read never deletes anything.
     */
    private function fetchAll(array $types, bool $full, ?string $staffId): array
    {
        $rows = [];
        $unreadable = [];
        $today = new DateTimeImmutable('today', wp_timezone());

        foreach ($types as $type => $config) {
            $this->progress("Fetching {$config['table']} from Oracle…");
            $started = microtime(true);
            $raw = $full
                ? $this->source->fetch($config['table'], $config['columns'])
                : $this->source->fetch($config['table'], $config['columns'], $config['key'], $staffId);
            $seconds = microtime(true) - $started;
            $this->debug('fetch', $type, ['table' => $config['table'], 'rows' => count($raw), 'ms' => (int) ($seconds * 1000)]);
            $this->progress(sprintf('Fetched %d rows from %s (%.1fs).', count($raw), $config['table'], $seconds));

            if ($full && !$raw) {
                throw new RuntimeException("{$config['table']} returned no rows; aborting so nothing is deleted.");
            }

            $rows[$type] = [];
            $this->raw[$type] = array_column($raw, null, $config['key']);
            foreach ($raw as $row) {
                $normalized = Planner::normalize($row, $config['dates'], $today);
                foreach (Planner::unreadableDates($row, $normalized, $config['dates']) as $column) {
                    // Private columns (DOB) never have their value logged.
                    $value = in_array($column, PostTypes::PRIVATE_COLUMNS, true) ? '(hidden)' : $row[$column];
                    $unreadable[] = ['code' => $row[$config['key']], 'label' => $config['title']($row), 'field' => $column, 'value' => $value];
                }
                $rows[$type][] = $normalized;
            }
        }

        if ($unreadable) {
            $this->record('warning', 'bad_date', message: count($unreadable) . ' date(s) in Oracle could not be read and were stored empty.', context: ['items' => $unreadable]);
        }

        if ($full) {
            $rows[PostTypes::DIVISION] = Planner::staffedDivisions($rows[PostTypes::DIVISION], $rows[PostTypes::STAFF]);
            if (!$rows[PostTypes::DIVISION]) {
                throw new RuntimeException('No division has any current staff; aborting so nothing is deleted.');
            }
        }

        return $rows;
    }

    private function apply(string $type, array $config, array $plan): void
    {
        foreach (['create' => 'creating', 'update' => 'updating'] as $operation => $verb) {
            $done = 0;
            foreach ($plan[$operation] as $item) {
                $this->tick("{$config['plural']}: $verb", ++$done, count($plan[$operation]));
                $id = $item['row'][$config['key']];
                $title = $config['title']($item['row']);
                $before = $operation === 'update' ? $this->storedRow($item['post_id'], $config['columns']) : [];
                $post = [
                    'post_type' => $type,
                    'post_status' => 'publish',
                    'post_title' => $title,
                    'meta_input' => [PostTypes::SOURCE_ID => $id, PostTypes::SOURCE_HASH => $item['hash']],
                ];
                $postId = $operation === 'create'
                    ? wp_insert_post($post, true)
                    : wp_update_post($post + ['ID' => $item['post_id']], true);

                if (is_wp_error($postId)) {
                    $this->counts['errors']++;
                    $this->record('error', 'error', $type, $id, $title, $postId->get_error_message());
                    continue;
                }
                foreach ($config['columns'] as $column) {
                    update_field(PostTypes::fieldKey($type, $column), $item['row'][$column], $postId);
                }

                if ($operation === 'create') {
                    $this->counts['created']++;
                    $this->pend($type, $id, $title, 'created');
                } else {
                    $this->counts['updated']++;
                    $this->pend($type, $id, $title, 'updated', Planner::changes($before, $item['row']));
                }
            }
        }

        $done = 0;
        foreach ($plan['delete'] as $post) {
            $this->tick("{$config['plural']}: deleting", ++$done, count($plan['delete']));
            $title = get_the_title($post['post_id']);
            $context = ['division' => (string) get_post_meta($post['post_id'], 'div_code', true)];
            Photos::detach($post['post_id']); // Leavers' photos go too.
            if (wp_delete_post($post['post_id'], true)) {
                $this->counts['deleted']++;
                $this->record('notice', 'deleted', $type, $post['source_id'], $title, context: $context);
            } else {
                $this->counts['errors']++;
                $this->record('error', 'error', $type, $post['source_id'], $title, 'Delete failed.');
            }
        }
    }

    /**
     * Photos run for every current staff member, not just changed ones: a photo can be added to the folder any time.
     */
    private function attachPhotos(array $rows): void
    {
        $posts = $this->existing(PostTypes::STAFF);
        $title = PostTypes::synced()[PostTypes::STAFF]['title'];
        update_meta_cache('post', array_column($posts, 'post_id')); // One query, not one per person.

        $this->progress(sprintf('Photos: checking %d staff…', count($rows)));
        $done = 0;
        foreach ($rows as $row) {
            $this->tick('Photos', ++$done, count($rows));
            $id = $row['ID'];
            if (!isset($posts[$id])) {
                continue; // LEFT, or failed to save.
            }
            $postId = $posts[$id]['post_id'];
            $before = $this->photoFile($postId);
            try {
                $result = $this->photos->attach($postId, $row);
            } catch (Throwable $e) {
                $this->counts['errors']++;
                $this->record('error', 'error', PostTypes::STAFF, $id, $title($row), $e->getMessage());
                continue;
            }

            // Staff without a photo are listed live on the Photos tab rather than logged every sync.
            if ($result === 'missing') {
                $this->counts['no_photo']++;
            } elseif ($result !== null) {
                // A photo change is part of the person's created/updated entry, not an entry of its own.
                $this->counts['photos']++;
                $this->pend(PostTypes::STAFF, $id, $title($row), 'updated', ['PHOTO' => ['from' => $before, 'to' => $this->photoFile($postId)]]);
            }
        }
    }

    /**
     * File name of a staff member's current photo ('' if none), from the fingerprint Photos stores.
     */
    private function photoFile(int $postId): string
    {
        return explode('|', (string) get_post_meta($postId, Photos::SOURCE, true))[0];
    }

    /**
     * Queue a created/updated entry, merging into one already queued for the same record
     * (e.g. data changes and a new photo in the same sync become one 'updated' entry).
     */
    private function pend(string $type, string $id, string $title, string $action, array $changes = []): void
    {
        $key = "$type|$id";
        $entry = $this->pending[$key] ?? ['type' => $type, 'id' => $id, 'title' => $title, 'action' => $action, 'changes' => []];
        $entry['changes'] += $changes;
        $this->pending[$key] = $entry;
    }

    private function flushPending(): void
    {
        foreach ($this->pending as $entry) {
            $oracle = Planner::redact($this->raw[$entry['type']][$entry['id']] ?? []);
            $context = array_filter(['changes' => $entry['changes'], 'oracle' => $oracle]); // No Oracle row when applied from settings.
            $message = $entry['action'] === 'updated' ? implode(', ', array_keys($entry['changes'])) : null;
            $this->record('info', $entry['action'], $entry['type'], $entry['id'], $entry['title'], $message, $context);
        }
        $this->pending = [];
    }

    /**
     * Apply the company mapping straight after it's saved in settings, without a full sync. Logged as its own run.
     *
     * @return array{status: string, counts: array<string, int>}
     */
    public function applyCompanyMap(): array
    {
        $this->runId = wp_generate_uuid4();
        $this->trigger = 'manual';
        $this->counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0, 'photos' => 0, 'no_photo' => 0];
        $this->raw = [];
        $this->pending = [];

        if (!$this->lock()) {
            return ['status' => 'failed', 'counts' => $this->counts];
        }
        $this->record('info', 'company_map', message: 'Company mapping saved.', context: ['map' => $this->companies->get()]);
        try {
            $this->linkCompanies();
        } finally {
            $this->flushPending();
            $this->unlock();
        }

        return ['status' => $this->counts['errors'] ? 'completed_with_errors' : 'completed', 'counts' => $this->counts];
    }

    /**
     * Create mapped companies that don't exist yet and point each mapped division at its company.
     * Companies are matched by name and never deleted; divisions not in the mapping are left alone.
     */
    private function linkCompanies(): void
    {
        $map = $this->companies->get();
        if (!$map) {
            return;
        }
        $this->progress('Applying the company mapping…');

        $companyIds = [];
        foreach (get_posts(['post_type' => PostTypes::COMPANY, 'numberposts' => -1, 'post_status' => 'any']) as $company) {
            $companyIds[$company->post_title] ??= $company->ID;
        }

        foreach (array_keys($map) as $name) {
            $name = (string) $name;
            if (isset($companyIds[$name])) {
                continue;
            }
            $id = wp_insert_post(['post_type' => PostTypes::COMPANY, 'post_status' => 'publish', 'post_title' => $name], true);
            if (is_wp_error($id)) {
                $this->counts['errors']++;
                $this->record('error', 'error', PostTypes::COMPANY, label: $name, message: $id->get_error_message());
                continue;
            }
            $companyIds[$name] = $id;
            $this->counts['created']++;
            $this->record('info', 'created', PostTypes::COMPANY, label: $name, message: 'From the company mapping.');
        }

        ['divisions' => $mapped, 'conflicts' => $conflicts] = CompanyMap::byDivision($map);
        if ($conflicts) {
            $items = array_map(fn (string $code, array $names): array => ['code' => $code, 'label' => implode(' / ', $names)], array_keys($conflicts), $conflicts);
            $this->record('warning', 'company_map', message: count($conflicts) . ' division(s) are listed under more than one company; the first is used.', context: ['items' => $items]);
        }

        $divisions = $this->existing(PostTypes::DIVISION);
        foreach ($mapped as $code => $name) {
            $this->heartbeat();
            if (!isset($divisions[$code], $companyIds[$name])) {
                continue; // Division not synced (e.g. no current staff), or its company failed to save.
            }
            $postId = $divisions[$code]['post_id'];
            $current = (int) get_post_meta($postId, 'company', true);
            if ($current === $companyIds[$name]) {
                continue;
            }

            update_field('field_iphl_division_company', $companyIds[$name], $postId);
            if (!isset($this->pending[PostTypes::DIVISION . "|$code"])) {
                $this->counts['updated']++;
            }
            $this->pend(PostTypes::DIVISION, $code, get_the_title($postId), 'updated', [
                'COMPANY' => ['from' => $current ? get_the_title($current) : '', 'to' => $name],
            ]);
        }
    }

    private function warnUnassignedDivisions(): void
    {
        $divisions = get_posts([
            'post_type' => PostTypes::DIVISION,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => ['relation' => 'OR', ['key' => 'company', 'compare' => 'NOT EXISTS'], ['key' => 'company', 'value' => '']],
        ]);

        if ($divisions) {
            $items = array_map(fn ($division): array => [
                'postId' => $division->ID,
                'code' => (string) get_post_meta($division->ID, PostTypes::SOURCE_ID, true),
                'label' => $division->post_title,
            ], $divisions);
            $this->record('warning', 'no_company', message: count($items) . ' division(s) have no company.', context: ['items' => $items]);
        }
    }

    /**
     * A post's currently stored synced values, keyed by Oracle column, for diffing.
     */
    private function storedRow(int $postId, array $columns): array
    {
        $meta = get_post_meta($postId);

        return array_combine($columns, array_map(fn (string $column): string => (string) ($meta[strtolower($column)][0] ?? ''), $columns));
    }

    /**
     * @return array<string, array{post_id: int, source_id: string, hash: string}> Every synced post of a type, keyed by source ID.
     */
    private function existing(string $type): array
    {
        $results = $this->db->get_results($this->db->prepare(
            "SELECT p.ID AS post_id, sid.meta_value AS source_id, h.meta_value AS hash
            FROM {$this->db->posts} p
            JOIN {$this->db->postmeta} sid ON sid.post_id = p.ID AND sid.meta_key = %s
            LEFT JOIN {$this->db->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
            WHERE p.post_type = %s",
            PostTypes::SOURCE_ID,
            PostTypes::SOURCE_HASH,
            $type
        ));

        $existing = [];
        foreach ($results as $result) {
            $existing[$result->source_id] = ['post_id' => (int) $result->post_id, 'source_id' => $result->source_id, 'hash' => (string) $result->hash];
        }

        return $existing;
    }

    /**
     * The sync currently running, or null. A lock with no heartbeat for LOCK_TTL seconds is dead
     * (e.g. its process was killed by a php-fpm restart) and doesn't count.
     *
     * @return array{run: string, started: int, beat: int, step?: string}|null
     */
    public function running(): ?array
    {
        wp_cache_delete(self::LOCK, 'options'); // Another process may have changed it.
        $lock = get_option(self::LOCK);

        return is_array($lock) && $lock['beat'] >= time() - self::LOCK_TTL ? $lock : null;
    }

    public function clearLock(): void
    {
        delete_option(self::LOCK);
    }

    /**
     * add_option is atomic on the unique option_name, so only one run can take the lock.
     */
    private function lock(): bool
    {
        if (!$this->running()) {
            delete_option(self::LOCK); // Dead, or from an older version.
        }

        $now = time();
        if (!add_option(self::LOCK, ['run' => $this->runId, 'started' => $now, 'beat' => $now], '', false)) {
            return false;
        }
        $this->lastBeat = $now;
        register_shutdown_function(fn () => $this->unlock()); // Also released after a fatal error.

        return true;
    }

    /**
     * Receive progress messages as the sync works (WP-CLI prints them).
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
    }

    /**
     * Report what the sync is doing: printed by WP-CLI, and stored on the lock for the Sync tab.
     * Stage changes are saved straight away; frequent "n of total" updates are throttled by the heartbeat.
     */
    private function progress(string $step, bool $stage = true): void
    {
        $this->step = $step;
        if ($this->onProgress) {
            ($this->onProgress)($step);
        }
        $this->heartbeat($stage);
    }

    /**
     * Keep the lock alive (and its step current) while working; cheap to call often.
     */
    private function heartbeat(bool $force = false): void
    {
        if (!$force && time() - $this->lastBeat < 5) {
            return;
        }
        $lock = $this->running();
        if ($lock && $lock['run'] === $this->runId) {
            $this->lastBeat = time();
            update_option(self::LOCK, ['beat' => $this->lastBeat, 'step' => $this->step] + $lock, false);
        }
    }

    /**
     * Every $every items (and the last), report "label: n of total…".
     */
    private function tick(string $label, int $done, int $total, int $every = 25): void
    {
        if ($done % $every === 0 || $done === $total) {
            $this->progress("{$label}: {$done} of {$total}…", stage: false);
        } else {
            $this->heartbeat();
        }
    }

    /**
     * Release the lock, but only if this run holds it.
     */
    private function unlock(): void
    {
        wp_cache_delete(self::LOCK, 'options');
        $lock = get_option(self::LOCK);
        if (is_array($lock) && $lock['run'] === $this->runId) {
            delete_option(self::LOCK);
        }
    }

    /**
     * Debug entries are only written when WP_DEBUG is on.
     */
    private function debug(string $action, string $postType, array $context): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->record('debug', $action, $postType, context: $context);
        }
    }

    private function record(
        string $level,
        string $action,
        ?string $postType = null,
        ?string $sourceId = null,
        string $label = '',
        ?string $message = null,
        ?array $context = null,
    ): void {
        $this->log->write($this->runId, $this->trigger, $level, $action, $postType, $sourceId, $label, $message, $context);
    }
}
