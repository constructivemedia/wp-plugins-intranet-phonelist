<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Ingest\PhoneNumbers;
use DateTimeImmutable;

/**
 * The data contract for pushed records: every field, its type and its place in the editor.
 * Validation, the ACF fields and the API documentation all follow from this.
 *
 * Every record also carries 'id' (the Data Hub ID) and 'updated_at' (ISO 8601 with an offset).
 */
final class Schema
{
    /** API type => post type. */
    public const TYPES = ['companies' => PostTypes::COMPANY, 'divisions' => PostTypes::DIVISION, 'staff' => PostTypes::STAFF];

    /** Never exposed to unsigned reads or written to the log. */
    public const PRIVATE = ['date_of_birth', 'home_phone'];

    /** What happens to a staff member with each status; any status not listed is deleted. */
    public const STATUS_ACTION = ['active' => 'keep'];

    private const ID_LENGTH = 64;
    private const STRING_LENGTH = 1000;

    /**
     * Field => config, in editor order. Kinds: string, email, phone, date, bool, url.
     * 'title' fields are stored as the post title, not as a field.
     *
     * @return array<string, array{label: string, kind: string, tab: string, width: int, required?: bool, title?: bool}>
     */
    public static function fields(string $type): array
    {
        $field = fn (string $label, int $width, string $tab, string $kind = 'string', bool $required = false): array
            => ['label' => $label, 'kind' => $kind, 'tab' => $tab, 'width' => $width] + ($required ? ['required' => true] : []);

        return match ($type) {
            'companies' => [
                'name' => ['title' => true] + $field('Name', 100, 'Details', required: true),
            ],
            'divisions' => [
                'name' => ['title' => true] + $field('Name', 100, 'Details', required: true),
                'phone' => $field('Phone', 100, 'Details', 'phone'),
                'address_line1' => $field('Address Line 1', 40, 'Address'),
                'address_line2' => $field('Address Line 2', 40, 'Address'),
                'postcode' => $field('Postcode', 20, 'Address'),
                'postal_address' => $field('Postal Address', 100, 'Address'),
            ],
            'staff' => [
                'preferred_name' => $field('Preferred Name', 33, 'Contact'),
                'first_name' => $field('First Name', 33, 'Contact', required: true),
                'last_name' => $field('Last Name', 34, 'Contact', required: true),
                'email' => $field('Email', 50, 'Contact', 'email'),
                'mobile' => $field('Mobile', 25, 'Contact', 'phone'),
                'phone' => $field('Direct Phone', 25, 'Contact', 'phone'),
                'extension' => $field('Extension (VoIP)', 50, 'Contact'),
                'home_phone' => $field('Home / After Hours Phone', 50, 'Contact', 'phone'),
                'position' => $field('Position', 50, 'Role'),
                'position_number' => $field('Position No.', 25, 'Role'),
                'award_code' => $field('Award Code', 25, 'Role'),
                'job' => $field('Job', 50, 'Role'),
                'job_type' => $field('Job Type', 50, 'Role'),
                'department_code' => $field('Department Code', 25, 'Role'),
                'department' => $field('Department', 75, 'Role'),
                'division_id' => $field('Division ID', 33, 'Role'),
                'company_id' => $field('Company ID', 33, 'Role'),
                'reports_to' => $field('Reports To (Staff ID)', 34, 'Role'),
                'status' => $field('Status', 33, 'Employment', required: true),
                'start_date' => $field('Start Date', 33, 'Employment', 'date'),
                'on_leave_until' => $field('On Leave Until', 34, 'Employment', 'date'),
                'date_of_birth' => $field('Date of Birth', 50, 'Birthday', 'date'),
                'hide_birthday' => $field('Hide Birthday', 50, 'Birthday', 'bool'),
                'photo_url' => $field('Photo URL', 100, 'Photo', 'url'),
            ],
        };
    }

    /**
     * Check a pushed record and convert it to stored values: dates as ACF Ymd, booleans as '1'/'0', phones as E.164
     * (unreadable numbers kept as sent), blank strings as null. Unknown fields are dropped.
     *
     * Full writes ($partial false) return every field, missing ones as null. Partial writes return only the fields sent.
     *
     * @return array{data: array<string, ?string>, errors: array<array{field: string, message: string}>}
     */
    public static function validate(string $type, array $input, bool $partial): array
    {
        $errors = [];
        $error = function (string $field, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        $id = $input['id'] ?? null;
        if (!is_string($id) || trim($id) === '' || strlen($id) > self::ID_LENGTH) {
            $error('id', 'Required: a string of 1 to ' . self::ID_LENGTH . ' characters.');
        }
        if (self::updatedAt($input) === null) {
            $error('updated_at', 'Required: an ISO 8601 date and time with a timezone offset, e.g. 2026-10-15T09:30:00+08:00.');
        }

        $data = [];
        foreach (self::fields($type) as $field => $config) {
            if ($partial && !array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }

            if ($value === null) {
                if (!empty($config['required'])) {
                    $error($field, 'Required.');
                }
                $data[$field] = null;
                continue;
            }

            [$stored, $message] = self::convert($config['kind'], $value);
            if ($message !== null) {
                $error($field, $message);
                continue;
            }
            $data[$field] = $stored;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * The record's 'updated_at', or null if it's missing or not ISO 8601 with an offset ('Z' counts).
     */
    public static function updatedAt(array $input): ?DateTimeImmutable
    {
        $value = $input['updated_at'] ?? null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(str_contains($value, '.') ? 'Y-m-d\TH:i:s.uP' : DATE_RFC3339, $value);
        $warnings = DateTimeImmutable::getLastErrors();

        return $date && !($warnings && $warnings['warning_count']) ? $date : null;
    }

    /**
     * Whether a staff member with this status stays on the phone list.
     */
    public static function keeps(?string $status): bool
    {
        return (self::STATUS_ACTION[$status ?? ''] ?? 'delete') === 'keep';
    }

    /**
     * @return array{0: ?string, 1: ?string} [stored value, error message]
     */
    private static function convert(string $kind, mixed $value): array
    {
        if ($kind === 'bool') {
            return is_bool($value) ? [$value ? '1' : '0', null] : [null, 'Must be true or false.'];
        }
        if (!is_string($value)) {
            return [null, 'Must be a string.'];
        }
        if (strlen($value) > self::STRING_LENGTH) {
            return [null, 'Must be at most ' . self::STRING_LENGTH . ' characters.'];
        }

        return match ($kind) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? [$value, null] : [null, 'Not a valid email address.'],
            'url' => filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? [$value, null] : [null, 'Must be an http or https URL.'],
            'phone' => [(PhoneNumbers::available() ? PhoneNumbers::format($value) : null) ?? $value, null],
            'date' => self::date($value),
            default => [$value, null],
        };
    }

    /**
     * 'YYYY-MM-DD' → ACF's 'Ymd'.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function date(string $value): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        $warnings = DateTimeImmutable::getLastErrors();
        if (!$date || ($warnings && $warnings['warning_count'])) {
            return [null, 'Must be a date as YYYY-MM-DD.'];
        }

        return [$date->format('Ymd'), null];
    }
}
