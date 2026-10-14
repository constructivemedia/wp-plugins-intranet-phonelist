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
            $this->record('error', message: 'A sync is already running.');
            return ['status' => 'failed', 'counts' => $this->counts, 'message' => 'A sync is already running.'];
        }

        $this->record('sync_start', $full ? null : PostTypes::STAFF, $staffId, $full ? 'full' : 'single');
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
                $this->apply($type, $config, Planner::plan($rows[$type], $existing, $config['key']));
            }

            if ($this->photos->enabled()) {
                $this->attachPhotos($rows[PostTypes::STAFF]);
            }

            $status = $this->counts['errors'] ? 'completed_with_errors' : 'completed';
        } catch (Throwable $e) {
            $this->counts['errors']++;
            $status = 'failed';
            $message = $e->getMessage();
            $this->record('error', message: $message);
        } finally {
            delete_option(self::LOCK);
        }

        ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'errors' => $errors, 'photos' => $photos, 'no_photo' => $noPhoto] = $this->counts;
        $message = trim("$message Created $created, updated $updated, deleted $deleted, errors $errors.");
        if ($this->photos->enabled()) {
            $message .= " Photos added $photos, no photo $noPhoto.";
        }
        $this->record('sync_end', $full ? null : PostTypes::STAFF, $staffId, $status, $message);
        if ($full) {
            update_option(self::LAST_SYNC, ['time' => time(), 'trigger' => $trigger, 'status' => $status, 'message' => $message], false);
        }

        return ['status' => $status, 'counts' => $this->counts, 'message' => $message];
    }

    /**
     * Fetch everything before touching WordPress, so a bad read never deletes anything.
     */
    private function fetchAll(array $types, bool $full, ?string $staffId): array
    {
        $rows = [];
        $today = new DateTimeImmutable('today', wp_timezone());
        foreach ($types as $type => $config) {
            $rows[$type] = array_map(
                fn (array $row): array => Planner::normalize($row, $config['dates'], $today),
                $full
                    ? $this->source->fetch($config['table'], $config['columns'])
                    : $this->source->fetch($config['table'], $config['columns'], $config['key'], $staffId),
            );

            if ($full && !$rows[$type]) {
                throw new RuntimeException("{$config['table']} returned no rows; aborting so nothing is deleted.");
            }
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
                    $this->record('error', $type, $id, $title, $postId->get_error_message());
                    continue;
                }
                foreach ($config['columns'] as $column) {
                    update_field(PostTypes::fieldKey($type, $column), $item['row'][$column], $postId);
                }

                $action = $operation === 'create' ? 'created' : 'updated';
                $this->counts[$action]++;
                $this->record($action, $type, $id, $title);
            }
        }

        foreach ($plan['delete'] as $post) {
            $title = get_the_title($post['post_id']);
            Photos::detach($post['post_id']); // Leavers' photos go too.
            if (wp_delete_post($post['post_id'], true)) {
                $this->counts['deleted']++;
                $this->record('deleted', $type, $post['source_id'], $title);
            } else {
                $this->counts['errors']++;
                $this->record('error', $type, $post['source_id'], $title, 'Delete failed.');
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
                $this->record('error', PostTypes::STAFF, $id, $title($row), $e->getMessage());
                continue;
            }

            if ($result === 'missing') {
                $this->counts['no_photo']++;
            } elseif ($result !== null) {
                $this->counts['photos']++;
                $this->record('photo', PostTypes::STAFF, $id, $title($row), $result);
            }
        }
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

    private function record(string $action, ?string $postType = null, ?string $sourceId = null, string $label = '', ?string $message = null): void
    {
        $this->log->write($this->runId, $this->trigger, $action, $postType, $sourceId, $label, $message);
    }
}
