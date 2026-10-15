<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Relations;
use ConstructiveMedia\IntranetPhonelist\Schema;
use DateTimeImmutable;
use Throwable;

/**
 * The only code that writes companies, divisions and staff. Used by the API's single-record endpoints,
 * by batch and snapshot jobs, and by the Oracle import. Every outcome is logged, one row per record.
 *
 * Records are found by their Data Hub ID. Each write is checked against the updated_at last applied:
 * older is refused (stale), the same data is 'unchanged', anything else is written. Deleted records are
 * remembered for a while, so an older push arriving late can't bring them back.
 */
final class Ingestor
{
    /** Option: "type:id" => [updated_at, deleted at (unix)], for records deleted in the last TOMBSTONE_DAYS. */
    private const TOMBSTONES = 'intranet_phonelist_deleted';
    private const TOMBSTONE_DAYS = 90;

    public function __construct(
        private readonly Log $log,
        private readonly Relations $relations,
        private readonly Photos $photos,
    ) {
    }

    /**
     * Create or replace a record: fields not sent are cleared. $type must be one of Schema::TYPES.
     */
    public function put(string $type, string $id, array $input, string $runId, string $trigger): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, fn (): Outcome => $this->write($type, $id, $input, false));
    }

    /**
     * Change only the fields sent; null clears a field. The record must exist.
     */
    public function patch(string $type, string $id, array $input, string $runId, string $trigger): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, fn (): Outcome => $this->write($type, $id, $input, true));
    }

    /**
     * Remove a record, unless it was updated after $updatedAt. Gone already is still 'deleted'.
     */
    public function delete(string $type, string $id, string $updatedAt, string $runId, string $trigger): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, function () use ($type, $id, $updatedAt): Outcome {
            $time = Schema::updatedAt(['updated_at' => $updatedAt]);
            if ($time === null) {
                return new Outcome('invalid', 422, errors: [['field' => 'updated_at', 'message' => 'Must be an ISO 8601 date and time with a timezone offset.']]);
            }
            $postId = $this->postId($type, $id);
            if (!$postId) {
                return new Outcome('deleted', 204, message: 'Already gone.');
            }
            $label = get_post_field('post_title', $postId, 'raw');
            if ($this->isOlder($postId, $time)) {
                return new Outcome('stale', 409, $label, message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').');
            }
            $this->remove($type, $id, $postId, $updatedAt);

            return new Outcome('deleted', 204, $label);
        });
    }

    /**
     * One batch operation: {op: put|patch|delete, type, id, data}. Delete takes updated_at from data.
     */
    public function apply(array $op, string $runId, string $trigger): Outcome
    {
        $type = $op['type'] ?? null;
        $id = $op['id'] ?? null;
        $data = $op['data'] ?? [];
        $errors = [];
        if (!in_array($op['op'] ?? null, ['put', 'patch', 'delete'], true)) {
            $errors[] = ['field' => 'op', 'message' => 'Must be put, patch or delete.'];
        }
        if (!is_string($type) || !isset(Schema::TYPES[$type])) {
            $errors[] = ['field' => 'type', 'message' => 'Must be ' . implode(', ', array_keys(Schema::TYPES)) . '.'];
        }
        if (!is_string($id) || $id === '') {
            $errors[] = ['field' => 'id', 'message' => 'Required: the Data Hub ID.'];
        }
        if (!is_array($data)) {
            $errors[] = ['field' => 'data', 'message' => 'Must be an object.'];
        } elseif (($op['op'] ?? null) === 'delete' && !is_string($data['updated_at'] ?? null)) {
            $errors[] = ['field' => 'updated_at', 'message' => 'Required in data for a delete.'];
        }
        if ($errors) {
            return $this->logged((string) $type, is_string($id) ? $id : '', $runId, $trigger, fn (): Outcome => new Outcome('invalid', 422, errors: $errors));
        }

        return match ($op['op']) {
            'put' => $this->put($type, $id, $data, $runId, $trigger),
            'patch' => $this->patch($type, $id, $data, $runId, $trigger),
            'delete' => $this->delete($type, $id, $data['updated_at'], $runId, $trigger),
        };
    }

    public function postId(string $type, string $id): int
    {
        return PostTypes::postId(Schema::TYPES[$type], $id);
    }

    private function write(string $type, string $id, array $input, bool $partial): Outcome
    {
        if (isset($input['id']) && $input['id'] !== $id) {
            return new Outcome('invalid', 422, errors: [['field' => 'id', 'message' => 'Must match the record ID it was sent to.']]);
        }
        ['data' => $data, 'errors' => $errors, 'warnings' => $warnings] = Schema::validate($type, ['id' => $id] + $input, $partial);
        if ($errors) {
            return new Outcome('invalid', 422, errors: $errors);
        }
        $time = Schema::updatedAt($input);

        $postId = $this->postId($type, $id);
        if (!$postId && $partial) {
            return new Outcome('not_found', 404, message: 'No record with this ID. Send the whole record with PUT to create it.');
        }
        $deleted = $postId ? null : $this->tombstone($type, $id);
        if ($deleted && $time < $deleted) {
            return new Outcome('stale', 409, message: 'Older than the deletion of this record (' . $deleted->format(DATE_RFC3339) . ').');
        }
        if ($postId && $this->isOlder($postId, $time)) {
            return new Outcome('stale', 409, get_post_field('post_title', $postId, 'raw'), message: 'Older than the stored record (' . get_post_meta($postId, PostTypes::UPDATED_AT, true) . ').');
        }

        $old = $postId ? $this->stored($type, $postId) : [];
        $new = $partial ? array_merge($old, $data) : $data;
        $label = $type === 'staff'
            ? PostTypes::displayName($new['preferred_name'] ?? null, $new['first_name'] ?? null, $new['last_name'] ?? null)
            : (string) ($new['name'] ?? '');

        if ($type === 'staff' && !Schema::keeps($new['status'] ?? null)) {
            if ($postId) {
                $this->remove($type, $id, $postId, $input['updated_at']);
            }

            return new Outcome('deleted', 200, $label, message: "Status '{$new['status']}' is not kept on the phone list.");
        }

        $hash = self::hash($new);
        if ($postId && get_post_meta($postId, PostTypes::SOURCE_HASH, true) === $hash) {
            update_post_meta($postId, PostTypes::UPDATED_AT, wp_slash($input['updated_at']));

            return new Outcome('unchanged', 200, $label, warnings: $warnings);
        }

        $created = !$postId;
        if ($created) {
            $postId = wp_insert_post(wp_slash(['post_type' => Schema::TYPES[$type], 'post_status' => 'publish', 'post_title' => $label]), true);
            if (is_wp_error($postId)) {
                return new Outcome('error', 500, $label, message: $postId->get_error_message());
            }
            update_post_meta($postId, PostTypes::SOURCE_ID, wp_slash($id));
            $this->unbury($type, $id);
        } elseif (get_post_field('post_title', $postId, 'raw') !== $label) {
            wp_update_post(wp_slash(['ID' => $postId, 'post_title' => $label]));
        }

        foreach (Schema::fields($type) as $name => $config) {
            if (empty($config['title'])) {
                PostTypes::store($postId, PostTypes::fieldKey(Schema::TYPES[$type], $name), $name, $new[$name] ?? '');
            }
        }
        update_post_meta($postId, PostTypes::UPDATED_AT, wp_slash($input['updated_at']));
        update_post_meta($postId, PostTypes::SOURCE_HASH, $hash);

        $changes = self::changes($old, $new) + $this->relations->afterWrite($type, $postId, $created);
        $photoProblem = $type === 'staff' ? $this->photos->afterWrite($postId, $new, $created) : null;
        if ($photoProblem !== null) {
            $warnings[] = ['field' => 'photo_url', 'message' => $photoProblem];
        }

        return new Outcome($created ? 'created' : 'updated', $created ? 201 : 200, $label, $changes, warnings: $warnings);
    }

    /**
     * Run $work and log its outcome as one row; anything thrown becomes an 'error' outcome.
     *
     * @param callable(): Outcome $work
     */
    private function logged(string $type, string $id, string $runId, string $trigger, callable $work): Outcome
    {
        try {
            $outcome = $work();
        } catch (Throwable $e) {
            $outcome = new Outcome('error', 500, message: $e->getMessage());
        }

        $level = match (true) {
            $outcome->status >= 500 => 'error',
            $outcome->status >= 400, $outcome->warnings !== [] => 'warning',
            default => 'info',
        };
        $context = array_filter(['changes' => $outcome->changes, 'errors' => $outcome->errors, 'warnings' => $outcome->warnings]);
        $this->log->write($runId, $trigger, $level, $outcome->result, Schema::TYPES[$type] ?? null, $id !== '' ? $id : null, $outcome->label, $outcome->message, $context ?: null);

        return $outcome;
    }

    /**
     * Delete a record, remembering it as deleted as of $updatedAt. A leaver's photo goes with them.
     */
    private function remove(string $type, string $id, int $postId, string $updatedAt): void
    {
        $this->bury($type, $id, $updatedAt);
        $this->relations->beforeDelete($type, $postId);
        $photo = (int) get_post_thumbnail_id($postId);
        if ($photo) {
            wp_delete_attachment($photo, true);
        }
        wp_delete_post($postId, true);
    }

    /**
     * When a record was deleted (as of its updated_at), or null if it wasn't, or not recently.
     */
    private function tombstone(string $type, string $id): ?DateTimeImmutable
    {
        [$updatedAt, $deletedAt] = get_option(self::TOMBSTONES, [])["$type:$id"] ?? [null, 0];

        return $deletedAt > time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS ? Schema::updatedAt(['updated_at' => $updatedAt]) : null;
    }

    private function bury(string $type, string $id, string $updatedAt): void
    {
        $cutoff = time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS;
        $tombstones = array_filter(get_option(self::TOMBSTONES, []), fn (array $tombstone): bool => $tombstone[1] > $cutoff);
        $tombstones["$type:$id"] = [$updatedAt, time()];
        update_option(self::TOMBSTONES, $tombstones, false);
    }

    private function unbury(string $type, string $id): void
    {
        $tombstones = get_option(self::TOMBSTONES, []);
        if (isset($tombstones["$type:$id"])) {
            unset($tombstones["$type:$id"]);
            update_option(self::TOMBSTONES, $tombstones, false);
        }
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
