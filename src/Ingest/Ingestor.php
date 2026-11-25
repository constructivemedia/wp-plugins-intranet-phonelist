<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Relations;
use ConstructiveMedia\IntranetPhonelist\Schema;
use DateTimeImmutable;
use Throwable;

/**
 * The only code that writes companies, divisions and staff. Used by the API (single records, batches and snapshots)
 * and by the Oracle import. Every outcome but 'unchanged' is logged, one row per record: created and updated with
 * the fields that changed, deleted with the data removed.
 *
 * Records are found by their Data Hub ID. Each write is checked against the updated_at last applied:
 * older is refused (stale), the same data is 'unchanged', anything else is written. Deletions are remembered for a
 * while, so an older push, or one with the same updated_at, arriving late can't bring the record back.
 */
final class Ingestor
{
    /** Option: "type:id" => [updated_at, deleted at (unix)], for records deleted in the last TOMBSTONE_DAYS. */
    private const TOMBSTONES = 'intranet_phonelist_deleted';
    private const TOMBSTONE_DAYS = 90;
    /** The results a report counts even when there are none. */
    private const COUNTED = ['created', 'updated', 'unchanged', 'deleted'];

    public function __construct(
        private readonly Log $log,
        private readonly Relations $relations,
        private readonly Photos $photos,
    ) {
    }

    /**
     * Create or replace a record: fields not sent are cleared. $type must be one of Schema::TYPES.
     * $force (snapshots) skips the updated_at checks: what's sent wins.
     */
    public function put(string $type, string $id, array $input, string $runId, string $trigger, bool $force = false): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, fn (): Outcome => $this->write($type, $id, $input, false, $force));
    }

    /**
     * Change only the fields sent; null clears a field. The record must exist.
     */
    public function patch(string $type, string $id, array $input, string $runId, string $trigger): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, fn (): Outcome => $this->write($type, $id, $input, true));
    }

    /**
     * Remove a record, unless it was updated after $updatedAt ($force, for snapshots: regardless). Gone already is
     * still 'deleted' (and remembered).
     */
    public function delete(string $type, string $id, string $updatedAt, string $runId, string $trigger, bool $force = false): Outcome
    {
        return $this->logged($type, $id, $runId, $trigger, function () use ($type, $id, $updatedAt, $force): Outcome {
            $time = Schema::updatedAt($updatedAt);
            if ($time === null) {
                return new Outcome('invalid', 422, errors: [['field' => 'updated_at', 'message' => 'Must be an ISO 8601 date and time with a timezone offset.']]);
            }
            $postId = $this->postId($type, $id);
            if (!$postId) {
                $deleted = $this->tombstone($type, $id);
                if ($deleted === null || $time > $deleted) {
                    $this->bury($type, $id, $updatedAt);
                }
                return new Outcome('deleted', 204, message: 'Already gone.');
            }
            if (!$force && ($stale = $this->stale($postId, $time))) {
                return $stale;
            }
            $label = get_post_field('post_title', $postId, 'raw');

            return new Outcome('deleted', 204, $label, $this->remove($type, $id, $postId, $updatedAt));
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
            $errors[] = ['field' => 'updated_at', 'message' => 'Required for a delete.'];
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

    /**
     * Operations ({op, type, id, data}) in order, each succeeding or failing on its own.
     *
     * @return array<array> A result row per operation: {id, type, op, result, errors?, warnings?, message?}.
     */
    public function batch(array $operations, string $runId, string $trigger): array
    {
        return array_map(fn (mixed $operation): array => [
            'id' => is_array($operation) && is_string($operation['id'] ?? null) ? $operation['id'] : null,
            'type' => is_array($operation) ? ($operation['type'] ?? null) : null,
            'op' => is_array($operation) ? ($operation['op'] ?? null) : null,
        ] + $this->apply(is_array($operation) ? $operation : [], $runId, $trigger)->toArray(), array_values($operations));
    }

    /**
     * Every record of $type, as the truth: each is written whatever its updated_at (optional here; now if missing),
     * then every other record of $type is deleted. That includes staff and divisions with no Data Hub ID; companies
     * the intranet made itself are kept. Finally every staff link is rechecked.
     *
     * Nothing is deleted when any record lacks a usable id (a non-empty string): a record we couldn't match would
     * otherwise be deleted as missing.
     *
     * @return array<array> As batch(): a row per record, then one per deletion (or one error row if they were refused).
     */
    public function snapshot(string $type, array $records, string $runId, string $trigger): array
    {
        @set_time_limit(0); // A large snapshot can outlast PHP's default limit.
        ignore_user_abort(true); // Finish (and do the deletions) even if the sender stops waiting.

        $now = (new DateTimeImmutable('now', wp_timezone()))->format(DATE_RFC3339);
        $results = [];
        $listed = [];

        foreach (array_values($records) as $record) {
            $record = is_array($record) ? $record : [];
            $id = is_string($record['id'] ?? null) && $record['id'] !== '' ? $record['id'] : null;
            $outcome = $id === null
                ? $this->logged($type, '', $runId, $trigger, fn (): Outcome => new Outcome('invalid', 422, errors: [['field' => 'id', 'message' => 'Required: the Data Hub ID, as a string.']]))
                : $this->put($type, $id, ['updated_at' => Schema::updatedAt($record['updated_at'] ?? null) ? $record['updated_at'] : $now] + $record, $runId, $trigger, true);
            $results[] = ['id' => $id, 'type' => $type, 'op' => 'put'] + $outcome->toArray();
            if ($id !== null) {
                $listed[$id] = true;
            }
        }

        if (count($listed) < count($results)) {
            $refused = 'Some records have no usable id (a non-empty string), so nothing was deleted.';
            $this->log->write($runId, $trigger, 'error', 'snapshot_refused', Schema::TYPES[$type], null, $type, $refused);
            $results[] = ['id' => null, 'type' => $type, 'op' => 'delete', 'result' => 'error', 'message' => $refused];
        } else {

            foreach (PostTypes::sourceIds(Schema::TYPES[$type]) as $id) {
                if (!isset($listed[$id])) {
                    $results[] = ['id' => $id, 'type' => $type, 'op' => 'delete'] + $this->delete($type, $id, $now, $runId, $trigger, true)->toArray();
                }
            }

            if ($type !== 'companies') {
                $untracked = get_posts(['post_type' => Schema::TYPES[$type], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
                    'meta_query' => [['key' => PostTypes::SOURCE_ID, 'compare' => 'NOT EXISTS']]]);
                foreach ($untracked as $postId) {
                    $results[] = ['id' => null, 'type' => $type, 'op' => 'delete'] + $this->logged($type, '', $runId, $trigger, function () use ($type, $postId, $now): Outcome {
                        $label = get_post_field('post_title', $postId, 'raw');
                        return new Outcome('deleted', 204, $label, $this->remove($type, null, $postId, $now), message: 'Had no Data Hub ID.');
                    })->toArray();
                }
            }
        }

        $this->relations->relinkAll();

        return $results;
    }

    /**
     * Batch or snapshot results as the API's reply: a count per result (created, updated, unchanged and deleted
     * always), the IDs created, updated and deleted, and only the rows worth a look: anything else, or with warnings.
     */
    public static function report(array $results): array
    {
        $problems = array_values(array_filter($results, fn (array $row): bool => !in_array($row['result'], self::COUNTED, true) || isset($row['warnings'])));
        $ids = [];
        foreach (['created', 'updated', 'deleted'] as $result) {
            $ids[$result] = array_values(array_filter(array_column(array_filter($results, fn (array $row): bool => $row['result'] === $result), 'id'), 'is_string'));
        }

        return array_merge(array_fill_keys(self::COUNTED, 0), array_count_values(array_column($results, 'result')), ['ids' => $ids, 'problems' => $problems]);
    }

    public function postId(string $type, string $id): int
    {
        return PostTypes::postId(Schema::TYPES[$type], $id);
    }

    private function write(string $type, string $id, array $input, bool $partial, bool $force = false): Outcome
    {
        if (isset($input['id']) && $input['id'] !== $id) {
            return new Outcome('invalid', 422, errors: [['field' => 'id', 'message' => 'Must match the record ID it was sent to.']]);
        }
        ['data' => $data, 'errors' => $errors, 'warnings' => $warnings] = Schema::validate($type, ['id' => $id] + $input, $partial);
        if ($errors) {
            return new Outcome('invalid', 422, errors: $errors);
        }
        $time = Schema::updatedAt($input['updated_at']);

        $postId = $this->postId($type, $id);
        if (!$postId && $partial) {
            return new Outcome('not_found', 404, message: 'No record with this ID. Send the whole record with PUT to create it.');
        }
        $deleted = $postId || $force ? null : $this->tombstone($type, $id);
        if ($deleted && $time <= $deleted) {
            return new Outcome('stale', 409, message: 'Not newer than the deletion of this record (' . $deleted->format(DATE_RFC3339) . ').');
        }
        if ($postId && !$force && ($stale = $this->stale($postId, $time))) {
            return $stale;
        }

        $old = $postId ? Schema::stored($type, $postId) : [];
        $new = $partial ? array_merge($old, $data) : $data;
        $label = $type === 'staff'
            ? PostTypes::displayName($new['preferred_name'] ?? null, $new['first_name'] ?? null, $new['last_name'] ?? null)
            : (string) ($new['name'] ?? '');

        if ($type === 'staff' && !(Schema::STATUSES[$new['status'] ?? ''] ?? true)) {
            $message = "Status '{$new['status']}' is not kept on the phone list.";
            if (!$postId) { // Never held, or already removed: nothing changes, so a snapshot's leavers don't count as deleted each time.
                $this->bury($type, $id, $input['updated_at']);
                return new Outcome('unchanged', 200, $label, message: $message);
            }
            return new Outcome('deleted', 200, $label, $this->remove($type, $id, $postId, $input['updated_at']), message: $message);
        }

        $hash = self::hash($new);
        $created = !$postId;
        if ($created) {
            $postId = wp_insert_post(wp_slash(['post_type' => Schema::TYPES[$type], 'post_status' => 'publish', 'post_title' => $label]), true);
            if (is_wp_error($postId)) {
                return new Outcome('error', 500, $label, message: $postId->get_error_message());
            }
            update_post_meta($postId, PostTypes::SOURCE_ID, wp_slash($id));
            $this->unbury($type, $id);
        } elseif (get_post_meta($postId, PostTypes::SOURCE_HASH, true) === $hash) {
            update_post_meta($postId, PostTypes::UPDATED_AT, wp_slash($input['updated_at']));

            return new Outcome('unchanged', 200, $label, warnings: [...$warnings, ...$this->photo($type, $postId, $new, false)]);
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

        return new Outcome($created ? 'created' : 'updated', $created ? 201 : 200, $label, $changes, warnings: [...$warnings, ...$this->photo($type, $postId, $new, $created)]);
    }

    /**
     * Give a staff member their photo (see Photos); a problem becomes a warning on the record.
     *
     * @return array<array{field: string, message: string}>
     */
    private function photo(string $type, int $postId, array $values, bool $created): array
    {
        $problem = $type === 'staff' ? $this->photos->afterWrite($postId, $values, $created) : null;

        return $problem === null ? [] : [['field' => 'photo_url', 'message' => $problem]];
    }

    /**
     * Run $work and log its outcome as one row (except 'unchanged'); anything thrown becomes an 'error' outcome.
     * Titles are saved as sent: WordPress would otherwise escape them ('&' → '&amp;') when no user is logged in.
     *
     * @param callable(): Outcome $work
     */
    private function logged(string $type, string $id, string $runId, string $trigger, callable $work): Outcome
    {
        $kses = (bool) has_filter('title_save_pre', 'wp_filter_kses');
        if ($kses) {
            kses_remove_filters();
        }
        try {
            $outcome = $work();
        } catch (Throwable $e) {
            $outcome = new Outcome('error', 500, message: $e->getMessage());
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }

        $level = match (true) {
            $outcome->status >= 500 => 'error',
            $outcome->status >= 400, $outcome->warnings !== [] => 'warning',
            default => 'info',
        };
        if ($outcome->result === 'unchanged') {
            return $outcome; // Not an event: the request row counts them, and the reply lists any warnings.
        }
        $context = array_filter(['changes' => $outcome->changes, 'errors' => $outcome->errors, 'warnings' => $outcome->warnings]);
        $this->log->write($runId, $trigger, $level, $outcome->result, Schema::TYPES[$type] ?? null, $id !== '' ? $id : null, $outcome->label, $outcome->message, $context ?: null);

        return $outcome;
    }

    /**
     * A 409 if $time is older than the record's stored updated_at, else null.
     */
    private function stale(int $postId, ?DateTimeImmutable $time): ?Outcome
    {
        $stored = get_post_meta($postId, PostTypes::UPDATED_AT, true);
        $storedTime = Schema::updatedAt($stored);

        return $storedTime !== null && $time !== null && $time < $storedTime
            ? new Outcome('stale', 409, get_post_field('post_title', $postId, 'raw'), message: "Older than the stored record ($stored).")
            : null;
    }

    /**
     * Delete a record, remembering it (if it has a Data Hub ID) as deleted as of $updatedAt. A leaver's photo goes with
     * them; a company's (set by hand, maybe used elsewhere) stays in the Media Library.
     *
     * @return array<string, mixed> What was removed, as changes to nothing, for the log.
     */
    private function remove(string $type, ?string $id, int $postId, string $updatedAt): array
    {
        $old = Schema::stored($type, $postId);
        if ($id !== null) {
            $this->bury($type, $id, $updatedAt);
        }
        $this->relations->beforeDelete($type, $postId);
        $photo = $type === 'staff' ? (int) get_post_thumbnail_id($postId) : 0;
        if ($photo) {
            wp_delete_attachment($photo, true);
        }
        wp_delete_post($postId, true);

        return self::changes($old, array_fill_keys(array_keys($old), null));
    }

    /**
     * When a record was deleted (as of its updated_at), or null if it wasn't, or not recently.
     */
    private function tombstone(string $type, string $id): ?DateTimeImmutable
    {
        [$updatedAt, $deletedAt] = get_option(self::TOMBSTONES, [])["$type:$id"] ?? [null, 0];

        return $deletedAt > self::tombstoneCutoff() ? Schema::updatedAt($updatedAt) : null;
    }

    private function bury(string $type, string $id, string $updatedAt): void
    {
        $tombstones = array_filter(get_option(self::TOMBSTONES, []), fn (array $tombstone): bool => $tombstone[1] > self::tombstoneCutoff());
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

    private static function tombstoneCutoff(): int
    {
        return time() - self::TOMBSTONE_DAYS * DAY_IN_SECONDS;
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
