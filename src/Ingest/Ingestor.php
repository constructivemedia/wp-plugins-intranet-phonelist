<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Relations;
use ConstructiveMedia\IntranetPhonelist\Schema;
use DateTimeImmutable;
use Throwable;
use wpdb;

/**
 * The only code that writes companies, divisions and staff. Used by the API's single-record endpoints,
 * by batch and snapshot jobs, and by the Oracle import.
 *
 * Records are found by their Data Hub ID. Each write is checked against the updated_at last applied:
 * older is refused (stale), the same data is 'unchanged', anything else is written.
 */
final class Ingestor
{
    /** Seconds to wait for another write to the same record to finish. */
    private const LOCK_TIMEOUT = 10;

    public function __construct(
        private readonly Log $log,
        private readonly wpdb $db,
        private readonly ?Relations $relations = null,
        private readonly ?Photos $photos = null,
    ) {
    }

    /**
     * Create or replace a record: fields not sent are cleared.
     */
    public function put(string $type, string $id, array $input, string $runId, string $trigger): Outcome
    {
        return $this->write($type, $id, $input, false, $runId, $trigger);
    }

    /**
     * Change only the fields sent; null clears a field. The record must exist.
     */
    public function patch(string $type, string $id, array $input, string $runId, string $trigger): Outcome
    {
        return $this->write($type, $id, $input, true, $runId, $trigger);
    }

    /**
     * Remove a record. Gone already is still 'deleted'. With $updatedAt, an older delete than the stored record is stale.
     */
    public function delete(string $type, string $id, ?string $updatedAt, string $runId, string $trigger): Outcome
    {
        if (!isset(Schema::TYPES[$type])) {
            return $this->finish(new Outcome('invalid', 422, errors: [['field' => 'type', 'message' => 'Unknown type.']]), $type, $id, '', $runId, $trigger);
        }
        $time = $updatedAt === null ? null : Schema::updatedAt(['updated_at' => $updatedAt]);
        if ($updatedAt !== null && $time === null) {
            return $this->finish(new Outcome('invalid', 422, errors: [['field' => 'updated_at', 'message' => 'Must be an ISO 8601 date and time with a timezone offset.']]), $type, $id, '', $runId, $trigger);
        }

        return $this->locked($type, $id, $runId, $trigger, function () use ($type, $id, $time): array {
            $postId = $this->postId($type, $id);
            if (!$postId) {
                return [new Outcome('deleted', 204, message: 'Already gone.'), ''];
            }
            $title = get_post_field('post_title', $postId, 'raw');
            if ($time && $this->isOlder($postId, $time)) {
                return [new Outcome('stale', 409, message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').'), $title];
            }
            $this->remove($type, $postId);

            return [new Outcome('deleted', 204), $title];
        });
    }

    /**
     * One batch operation: {op: put|patch|delete, type, id, data}. Delete takes updated_at from data.
     */
    public function apply(array $op, string $runId, string $trigger): Outcome
    {
        $id = $op['id'] ?? null;
        $data = $op['data'] ?? [];
        $errors = [];
        if (!in_array($op['op'] ?? null, ['put', 'patch', 'delete'], true)) {
            $errors[] = ['field' => 'op', 'message' => 'Must be put, patch or delete.'];
        }
        if (!isset(Schema::TYPES[$op['type'] ?? ''])) {
            $errors[] = ['field' => 'type', 'message' => 'Must be ' . implode(', ', array_keys(Schema::TYPES)) . '.'];
        }
        if (!is_string($id) || $id === '') {
            $errors[] = ['field' => 'id', 'message' => 'Required: the Data Hub ID.'];
        }
        if (!is_array($data)) {
            $errors[] = ['field' => 'data', 'message' => 'Must be an object.'];
        }
        if ($errors) {
            return $this->finish(new Outcome('invalid', 422, errors: $errors), (string) ($op['type'] ?? ''), is_string($id) ? $id : '', '', $runId, $trigger);
        }

        return match ($op['op']) {
            'put' => $this->put($op['type'], $id, $data, $runId, $trigger),
            'patch' => $this->patch($op['type'], $id, $data, $runId, $trigger),
            'delete' => $this->delete($op['type'], $id, isset($data['updated_at']) ? (string) $data['updated_at'] : null, $runId, $trigger),
        };
    }

    /**
     * The post holding a Data Hub ID, or 0.
     */
    public function postId(string $type, string $id): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT p.ID FROM {$this->db->posts} p JOIN {$this->db->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID LIMIT 1",
            Schema::TYPES[$type] ?? '',
            PostTypes::SOURCE_ID,
            $id
        ));
    }

    private function write(string $type, string $id, array $input, bool $partial, string $runId, string $trigger): Outcome
    {
        if (!isset(Schema::TYPES[$type])) {
            return $this->finish(new Outcome('invalid', 422, errors: [['field' => 'type', 'message' => 'Unknown type.']]), $type, $id, '', $runId, $trigger);
        }
        if (isset($input['id']) && $input['id'] !== $id) {
            return $this->finish(new Outcome('invalid', 422, errors: [['field' => 'id', 'message' => 'Must match the record ID it was sent to.']]), $type, $id, '', $runId, $trigger);
        }
        ['data' => $data, 'errors' => $errors] = Schema::validate($type, ['id' => $id] + $input, $partial);
        if ($errors) {
            return $this->finish(new Outcome('invalid', 422, errors: $errors), $type, $id, '', $runId, $trigger);
        }
        $time = Schema::updatedAt($input);

        return $this->locked($type, $id, $runId, $trigger, function () use ($type, $id, $data, $partial, $time, $input): array {
            $postId = $this->postId($type, $id);
            if (!$postId && $partial) {
                return [new Outcome('not_found', 404, message: 'No record with this ID. Send the whole record with PUT to create it.'), ''];
            }
            if ($postId && $this->isOlder($postId, $time)) {
                return [new Outcome('stale', 409, message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').'), get_post_field('post_title', $postId, 'raw')];
            }

            $old = $postId ? $this->stored($type, $postId) : [];
            $new = $partial ? array_merge($old, $data) : $data;

            if ($type === 'staff' && !Schema::keeps($new['status'] ?? null)) {
                $title = $postId ? get_post_field('post_title', $postId, 'raw') : $this->title($type, $new);
                if ($postId) {
                    $this->remove($type, $postId);
                }

                return [new Outcome('deleted', 200, message: "Status '{$new['status']}' is not kept on the phone list."), $title];
            }

            $title = $this->title($type, $new);
            $hash = self::hash($new);
            if ($postId && get_post_meta($postId, PostTypes::SOURCE_HASH, true) === $hash) {
                update_post_meta($postId, PostTypes::UPDATED_AT, $input['updated_at']);

                return [new Outcome('unchanged', 200), $title];
            }

            $created = !$postId;
            if ($created) {
                $postId = wp_insert_post(wp_slash(['post_type' => Schema::TYPES[$type], 'post_status' => 'publish', 'post_title' => $title]), true);
                if (is_wp_error($postId)) {
                    return [new Outcome('error', 500, message: $postId->get_error_message()), $title];
                }
                update_post_meta($postId, PostTypes::SOURCE_ID, $id);
            } elseif (get_post_field('post_title', $postId, 'raw') !== $title) {
                wp_update_post(wp_slash(['ID' => $postId, 'post_title' => $title]));
            }

            foreach (Schema::fields($type) as $name => $config) {
                if (empty($config['title'])) {
                    PostTypes::store($postId, PostTypes::fieldKey(Schema::TYPES[$type], $name), $name, $new[$name] ?? '');
                }
            }
            update_post_meta($postId, PostTypes::UPDATED_AT, $input['updated_at']);
            update_post_meta($postId, PostTypes::SOURCE_HASH, $hash);

            $this->relations?->afterWrite($type, $postId, $created);
            if ($type === 'staff') {
                $this->photos?->afterWrite($postId, $new, $created);
            }

            return [new Outcome($created ? 'created' : 'updated', $created ? 201 : 200, self::changes($old, $new)), $title];
        });
    }

    /**
     * Run $work while holding this record's lock, so a single request and a running job never write it at once.
     * $work returns [Outcome, title]; the outcome is logged.
     */
    private function locked(string $type, string $id, string $runId, string $trigger, callable $work): Outcome
    {
        $lock = 'iphl:' . md5("$type:$id"); // MySQL lock names are limited to 64 characters.
        if ((int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT)) !== 1) {
            return $this->finish(new Outcome('error', 503, message: 'The record is busy; try again.'), $type, $id, '', $runId, $trigger);
        }
        try {
            [$outcome, $title] = $work();
        } catch (Throwable $e) {
            [$outcome, $title] = [new Outcome('error', 500, message: $e->getMessage()), ''];
        } finally {
            $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }

        return $this->finish($outcome, $type, $id, $title, $runId, $trigger);
    }

    private function finish(Outcome $outcome, string $type, string $id, string $title, string $runId, string $trigger): Outcome
    {
        $level = match (true) {
            $outcome->status >= 500 => 'error',
            $outcome->status >= 400 => 'warning',
            default => 'info',
        };
        $context = array_filter(['changes' => $outcome->changes, 'errors' => $outcome->errors]);
        $this->log->write($runId, $trigger, $level, $outcome->result, Schema::TYPES[$type] ?? null, $id !== '' ? $id : null, $title, $outcome->message, $context ?: null);

        return $outcome;
    }

    private function remove(string $type, int $postId): void
    {
        $this->relations?->beforeDelete($type, $postId);
        $photo = (int) get_post_thumbnail_id($postId);
        if ($photo) {
            wp_delete_attachment($photo, true); // A leaver's photo goes with them.
        }
        wp_delete_post($postId, true);
    }

    private function isOlder(int $postId, ?DateTimeImmutable $time): bool
    {
        $stored = Schema::updatedAt(['updated_at' => get_post_meta($postId, PostTypes::UPDATED_AT, true)]);

        return $stored !== null && $time !== null && $time < $stored;
    }

    /**
     * The stored values of every field, as the ingest wrote them ('' read back as null).
     */
    private function stored(string $type, int $postId): array
    {
        $values = [];
        foreach (Schema::fields($type) as $name => $config) {
            $value = empty($config['title']) ? get_post_meta($postId, $name, true) : get_post_field('post_title', $postId, 'raw');
            $values[$name] = $value === '' || $value === false ? null : (string) $value;
        }

        return $values;
    }

    private function title(string $type, array $values): string
    {
        return $type === 'staff'
            ? PostTypes::displayName($values['preferred_name'] ?? null, $values['first_name'] ?? null, $values['last_name'] ?? null)
            : (string) ($values['name'] ?? '');
    }

    /**
     * Fingerprint of the stored values, independent of field order.
     */
    private static function hash(array $values): string
    {
        ksort($values);

        return md5(serialize(array_map(fn ($value): string => (string) $value, $values)));
    }

    /**
     * Fields that differ, for the log. Private fields record only that they changed, never their values.
     */
    private static function changes(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $field => $value) {
            if ((string) ($old[$field] ?? '') !== (string) $value) {
                $changes[$field] = in_array($field, Schema::PRIVATE, true) ? '(changed)' : ['from' => $old[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }
}
