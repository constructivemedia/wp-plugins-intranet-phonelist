<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use ConstructiveMedia\IntranetPhonelist\Ingest\PhoneNumbers;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use DateTimeImmutable;

/**
 * Pure sync decisions: no WordPress, no Oracle. See tests/plan-check.php.
 */
final class Planner
{
    /**
     * Diff source rows against existing posts.
     *
     * Source IDs are opaque strings ('00001', UUIDs): array keys are lookup only, never read back.
     *
     * @param array<array<string, string>> $rows Source rows (ORACLE_COLUMN => value).
     * @param array<string, array{post_id: int, source_id: string, hash: string}> $existing Keyed by source ID.
     * @return array{create: array, update: array, delete: array}
     */
    public static function plan(array $rows, array $existing, string $key): array
    {
        $plan = ['create' => [], 'update' => [], 'delete' => []];
        $seen = [];

        foreach ($rows as $row) {
            if (!self::isCurrent($row)) {
                continue; // Treated as absent, so it gets deleted below.
            }
            $id = $row[$key];
            $hash = self::hash($row);
            $seen[$id] = true;

            if (!isset($existing[$id])) {
                $plan['create'][$id] = ['row' => $row, 'hash' => $hash];
            } elseif ($existing[$id]['hash'] !== $hash) {
                $plan['update'][$id] = ['row' => $row, 'hash' => $hash, 'post_id' => $existing[$id]['post_id']];
            }
        }

        foreach ($existing as $id => $post) {
            if (!isset($seen[$id])) {
                $plan['delete'][$id] = $post;
            }
        }

        return $plan;
    }

    /**
     * Convert typed columns once, at sync (kinds from PostTypes::synced()):
     * - DATE / BIRTH_DATE → ACF's Ymd ('19850905'). Real DATE columns already arrive as Ymd (see OracleSource); text dates
     *   arrive as '05Sep85' (any month case). Unreadable → ''. Birth dates resolve two-digit years to the most recent past year.
     * - BOOL → '1' for 'y', else '0'. STATUS → 'active' for 'e', else 'inactive'.
     * - PHONE → one format via PhoneNumbers; unreadable numbers (and all numbers, if the library is missing) are kept trimmed.
     *
     * @param array<string, string> $kinds Column => PostTypes kind.
     */
    public static function normalize(array $row, array $kinds, DateTimeImmutable $today): array
    {
        foreach ($kinds as $column => $kind) {
            $value = trim($row[$column]);
            $row[$column] = match ($kind) {
                PostTypes::DATE, PostTypes::BIRTH_DATE => self::date($value, $kind === PostTypes::BIRTH_DATE ? $today : null),
                PostTypes::BOOL => strtolower($value) === 'y' ? '1' : '0',
                PostTypes::STATUS => strtolower($value) === 'e' ? 'active' : 'inactive',
                PostTypes::PHONE => (PhoneNumbers::available() ? PhoneNumbers::format($value) : null) ?? $value,
                default => $row[$column],
            };
        }

        return $row;
    }

    /**
     * Columns that had a value in Oracle that couldn't be read: dates stored empty ('N/A' means empty on purpose),
     * and phone numbers kept as they were.
     *
     * @return string[] Column names.
     */
    public static function unreadable(array $raw, array $normalized, array $kinds): array
    {
        return array_values(array_filter(array_keys($kinds), function (string $column) use ($raw, $normalized, $kinds): bool {
            $value = trim($raw[$column]);

            return match ($kinds[$column]) {
                PostTypes::DATE, PostTypes::BIRTH_DATE => !in_array(strtoupper($value), ['', 'N/A'], true) && $normalized[$column] === '',
                PostTypes::PHONE => $value !== '' && PhoneNumbers::available() && PhoneNumbers::format($value) === null,
                default => false,
            };
        }));
    }

    private static function date(string $value, ?DateTimeImmutable $notAfter): string
    {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value) ?: DateTimeImmutable::createFromFormat('!dMy', $value);
        $errors = DateTimeImmutable::getLastErrors(); // Catches overflow like '31Feb85'.
        if (!$date || ($errors && $errors['warning_count'])) {
            return '';
        }

        return ($notAfter && $date > $notAfter ? $date->modify('-100 years') : $date)->format('Ymd');
    }

    /**
     * Fields that differ between two rows, for the log. Private columns record only that they changed, never the values.
     *
     * @return array<string, array{from: string, to: string}|string> Column => from/to, or '(changed)'.
     */
    public static function changes(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $column => $value) {
            if ((string) ($old[$column] ?? '') !== (string) $value) {
                $changes[$column] = in_array($column, PostTypes::PRIVATE_COLUMNS, true) ? '(changed)' : ['from' => (string) ($old[$column] ?? ''), 'to' => (string) $value];
            }
        }

        return $changes;
    }

    /**
     * A row for the log, with private columns removed.
     */
    public static function redact(array $row): array
    {
        return array_diff_key($row, array_flip(PostTypes::PRIVATE_COLUMNS));
    }

    /**
     * Fingerprint of a source row, independent of column order.
     */
    public static function hash(array $row): string
    {
        ksort($row);

        return md5(serialize($row));
    }

    /**
     * Only divisions that at least one current (non-LEFT) staff member belongs to. Exact DIV_CODE match.
     */
    public static function staffedDivisions(array $divisions, array $staff): array
    {
        $staffed = [];
        foreach (array_filter($staff, self::isCurrent(...)) as $row) {
            $staffed[$row['DIV_CODE']] = true;
        }

        return array_values(array_filter($divisions, fn (array $division): bool => isset($staffed[$division['DIV_CODE']])));
    }

    /**
     * Staff marked LEFT are treated as gone. Rows without PH_EXT (divisions) are always current.
     */
    private static function isCurrent(array $row): bool
    {
        return ($row['PH_EXT'] ?? '') !== 'LEFT';
    }
}
