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
    public const LAST_SYNC = 'intranet_phonelist_last_sync';

    private string $runId = '';
    private string $trigger = '';
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, array<string, array>> Type => key => the row exactly as oci8 returned it, for the log. */
    private array $raw = [];

    public function __construct(
        private readonly OracleSource $source,
        private readonly Photos $photos,
        private readonly Log $log,
        private readonly wpdb $db,
    ) {
    }

    /**
     * @param string $trigger 'cli' (manual or cron) or 'button'.
     * @return array{status: string, counts: array<string, int>, message: string}
     */
    public function run(string $trigger, ?string $staffId = null): array
    {
        $full = $staffId === null;
        $this->runId = wp_generate_uuid4();
        $this->trigger = $trigger;
        $this->counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0, 'photos' => 0, 'no_photo' => 0];

        if (!$this->lock()) {
            $this->record('error', 'error', message: 'A sync is already running.');
            return ['status' => 'failed', 'counts' => $this->counts, 'message' => 'A sync is already running.'];
        }

        $this->record('info', 'sync_start', $full ? null : PostTypes::STAFF, $staffId, $full ? 'full' : 'single');
        $message = '';

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
                $this->apply($type, $config, $plan);
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
            delete_option(self::LOCK);
        }

        ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'errors' => $errors, 'photos' => $photos, 'no_photo' => $noPhoto] = $this->counts;
        $message = trim("$message Created $created, updated $updated, deleted $deleted, errors $errors.");
        if ($this->photos->enabled()) {
            $message .= " Photos added $photos, no photo $noPhoto.";
        }
        $level = ['completed' => 'info', 'completed_with_errors' => 'warning', 'failed' => 'error'][$status];
        $this->record($level, 'sync_end', $full ? null : PostTypes::STAFF, $staffId, $status, $message, $this->counts);
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
            $started = microtime(true);
            $raw = $full
                ? $this->source->fetch($config['table'], $config['columns'])
                : $this->source->fetch($config['table'], $config['columns'], $config['key'], $staffId);
            $this->debug('fetch', $type, ['table' => $config['table'], 'rows' => count($raw), 'ms' => (int) ((microtime(true) - $started) * 1000)]);

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
        foreach (['create', 'update'] as $operation) {
            foreach ($plan[$operation] as $item) {
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

                $oracleRow = Planner::redact($this->raw[$type][$id] ?? []);
                if ($operation === 'create') {
                    $this->counts['created']++;
                    $this->record('info', 'created', $type, $id, $title, context: ['oracle' => $oracleRow]);
                } else {
                    $changes = Planner::changes($before, $item['row']);
                    $this->counts['updated']++;
                    $this->record('info', 'updated', $type, $id, $title, implode(', ', array_keys($changes)), ['changes' => $changes, 'oracle' => $oracleRow]);
                }
            }
        }

        foreach ($plan['delete'] as $post) {
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

        foreach ($rows as $row) {
            $id = $row['ID'];
            if (!isset($posts[$id])) {
                continue; // LEFT, or failed to save.
            }
            try {
                $result = $this->photos->attach($posts[$id]['post_id'], $row);
            } catch (Throwable $e) {
                $this->counts['errors']++;
                $this->record('error', 'error', PostTypes::STAFF, $id, $title($row), $e->getMessage());
                continue;
            }

            // Staff without a photo are listed live on the Photos tab rather than logged every sync.
            if ($result === 'missing') {
                $this->counts['no_photo']++;
            } elseif ($result !== null) {
                $this->counts['photos']++;
                $this->record('info', 'photo', PostTypes::STAFF, $id, $title($row), $result, ['file' => get_post_meta($posts[$id]['post_id'], Photos::SOURCE, true)]);
            }
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
            $this->record('warning', 'no_company', PostTypes::DIVISION, label: count($items) . ' divisions', message: count($items) . ' division(s) have no company.', context: ['items' => $items]);
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
     * add_option is atomic on the unique option_name; a lock older than an hour is assumed dead.
     */
    private function lock(): bool
    {
        $since = (int) get_option(self::LOCK);
        if ($since && $since < time() - HOUR_IN_SECONDS) {
            delete_option(self::LOCK);
        }

        return add_option(self::LOCK, time(), '', false);
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
