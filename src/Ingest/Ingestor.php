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
    /**
     * Deleted records are remembered (option iphl_tomb_<hash>: their last updated_at) so an older push that arrives
     * late can't bring them back. Kept for this many days.
     */
    private const TOMBSTONE_PREFIX = 'iphl_tomb_';
    private const TOMBSTONE_DAYS = 90;

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

        return $this->attempt($type, $id, $runId, $trigger, function () use ($type, $id, $time): array {
            $postId = $this->postId($type, $id);
            if (!$postId) {
                return [new Outcome('deleted', 204, message: 'Already gone.'), ''];
            }
            $title = get_post_field('post_title', $postId, 'raw');
            if ($time && $this->isOlder($postId, $time)) {
                return [new Outcome('stale', 409, message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').'), $title];
            }
            $this->remove($type, $id, $postId, $time?->format(DATE_RFC3339));

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
        } elseif (($op['op'] ?? null) === 'delete' && !isset($data['updated_at'])) {
            $errors[] = ['field' => 'updated_at', 'message' => 'Required in data for a delete.'];
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
        ['data' => $data, 'errors' => $errors, 'warnings' => $warnings] = Schema::validate($type, ['id' => $id] + $input, $partial);
        if ($errors) {
            return $this->finish(new Outcome('invalid', 422, errors: $errors), $type, $id, '', $runId, $trigger);
        }
        $time = Schema::updatedAt($input);

        return $this->attempt($type, $id, $runId, $trigger, function () use ($type, $id, $data, $partial, $time, $input, $warnings): array {
            $postId = $this->postId($type, $id);
            if (!$postId && $partial) {
                return [new Outcome('not_found', 404, message: 'No record with this ID. Send the whole record with PUT to create it.'), ''];
            }
            $deleted = $postId ? null : $this->tombstone($type, $id);
            if ($deleted !== null && $time && $time < $deleted) {
                return [new Outcome('stale', 409, message: 'Older than the deletion of this record (' . $deleted->format(DATE_RFC3339) . ').'), ''];
            }
            if ($postId && $this->isOlder($postId, $time)) {
                return [new Outcome('stale', 409, message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').'), get_post_field('post_title', $postId, 'raw')];
            }

            $old = $postId ? $this->stored($type, $postId) : [];
            $new = $partial ? array_merge($old, $data) : $data;

            if ($type === 'staff' && !Schema::keeps($new['status'] ?? null)) {
                $title = $postId ? get_post_field('post_title', $postId, 'raw') : $this->title($type, $new);
                if ($postId) {
                    $this->remove($type, $id, $postId, $input['updated_at']);
                }

                return [new Outcome('deleted', 200, message: "Status '{$new['status']}' is not kept on the phone list."), $title];
            }

            $title = $this->title($type, $new);
            $hash = self::hash($new);
            if ($postId && get_post_meta($postId, PostTypes::SOURCE_HASH, true) === $hash) {
                update_post_meta($postId, PostTypes::UPDATED_AT, wp_slash($input['updated_at']));

                return [new Outcome('unchanged', 200, warnings: $warnings), $title];
            }

            $created = !$postId;
            if ($created) {
                $postId = wp_insert_post(wp_slash(['post_type' => Schema::TYPES[$type], 'post_status' => 'publish', 'post_title' => $title]), true);
                if (is_wp_error($postId)) {
                    return [new Outcome('error', 500, message: $postId->get_error_message()), $title];
                }
                update_post_meta($postId, PostTypes::SOURCE_ID, wp_slash($id));
                delete_option(self::tombstoneName($type, $id));
            } elseif (get_post_field('post_title', $postId, 'raw') !== $title) {
                wp_update_post(wp_slash(['ID' => $postId, 'post_title' => $title]));
            }

            foreach (Schema::fields($type) as $name => $config) {
                if (empty($config['title'])) {
                    PostTypes::store($postId, PostTypes::fieldKey(Schema::TYPES[$type], $name), $name, $new[$name] ?? '');
                }
            }
            update_post_meta($postId, PostTypes::UPDATED_AT, wp_slash($input['updated_at']));
            update_post_meta($postId, PostTypes::SOURCE_HASH, $hash);

            $this->relations?->afterWrite($type, $postId, $created);
            if ($type === 'staff') {
                $this->photos?->afterWrite($postId, $new, $created);
            }

            return [new Outcome($created ? 'created' : 'updated', $created ? 201 : 200, self::changes($old, $new), warnings: $warnings), $title];
        });
    }

    /**
     * Run $work (which returns [Outcome, title]) and log the outcome; anything thrown becomes an 'error' outcome.
     */
    private function attempt(string $type, string $id, string $runId, string $trigger, callable $work): Outcome
    {
        try {
            [$outcome, $title] = $work();
        } catch (Throwable $e) {
            [$outcome, $title] = [new Outcome('error', 500, message: $e->getMessage()), ''];
        }

        return $this->finish($outcome, $type, $id, $title, $runId, $trigger);
    }

    private function finish(Outcome $outcome, string $type, string $id, string $title, string $runId, string $trigger): Outcome
    {
        $level = match (true) {
            $outcome->status >= 500 => 'error',
            $outcome->status >= 400, $outcome->warnings !== [] => 'warning',
            default => 'info',
        };
        $context = array_filter(['changes' => $outcome->changes, 'errors' => $outcome->errors, 'warnings' => $outcome->warnings]);
        $this->log->write($runId, $trigger, $level, $outcome->result, Schema::TYPES[$type] ?? null, $id !== '' ? $id : null, $title, $outcome->message, $context ?: null);

        return $outcome;
    }

    /**
     * Delete a record, remembering it as deleted as of $updatedAt (else the time it was last updated).
     */
    private function remove(string $type, string $id, int $postId, ?string $updatedAt): void
    {
        update_option(self::tombstoneName($type, $id), [
            'type' => $type,
            'id' => $id,
            'updated_at' => $updatedAt ?? (string) get_post_meta($postId, PostTypes::UPDATED_AT, true),
            'deleted_at' => time(),
        ], false);
        $this->relations?->beforeDelete($type, $postId);
        $photo = (int) get_post_thumbnail_id($postId);
        if ($photo) {
            wp_delete_attachment($photo, true); // A leaver's photo goes with them.
        }
        wp_delete_post($postId, true);
    }

    /**
     * When a deleted record was deleted (as of its updated_at), or null if it wasn't or that was too long ago.
     */
    private function tombstone(string $type, string $id): ?DateTimeImmutable
    {
        $tombstone = get_option(self::tombstoneName($type, $id));

        return is_array($tombstone) && ($tombstone['deleted_at'] ?? 0) > time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS
            ? Schema::updatedAt(['updated_at' => $tombstone['updated_at'] ?? null])
            : null;
    }

    private static function tombstoneName(string $type, string $id): string
    {
        return self::TOMBSTONE_PREFIX . md5("$type:$id");
    }

    /**
     * Forget deletions older than TOMBSTONE_DAYS. Returns how many.
     */
    public function pruneTombstones(): int
    {
        $pruned = 0;
        foreach ($this->db->get_col($this->db->prepare("SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s", $this->db->esc_like(self::TOMBSTONE_PREFIX) . '%')) as $name) {
            $tombstone = get_option($name);
            if (!is_array($tombstone) || ($tombstone['deleted_at'] ?? 0) < time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS) {
                $pruned += (int) delete_option($name);
            }
        }

        return $pruned;
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
