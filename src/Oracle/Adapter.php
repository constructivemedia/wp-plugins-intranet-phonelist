<?php

namespace ConstructiveMedia\IntranetPhonelist\Oracle;

use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use DateTimeImmutable;

/**
 * Development only: reads Oracle's PHONE_LIST_INTRANET and DIVISIONS and pushes them through the same ingest code
 * Data Hub uses, as contract records. Oracle's quirks (two-digit years, 'y' flags, 'N/A', capitals) are dealt with here.
 */
final class Adapter
{
    /** Division → company, applied when a division is first created by the import. */
    public const COMPANIES = [
        'JWH Group' => ['JWH', 'JIT'],
        'WA Country Builders' => ['GTN', 'BSN', 'BNB', 'ALB', 'CDR'],
        'Active Plumbing' => ['ACT', 'ABN', 'AGH'],
        'Residential Attitudes' => ['RES', 'RAT'],
        'Plunkett Homes' => ['PHM'],
        'Rural Building Company' => ['RBC'],
        'Oswald Homes' => ['OSW'],
        'Constructive Media' => ['CME'],
        'Ceiling Force' => ['CEF'],
        'Roofstyle' => ['ROO'],
    ];

    private const STAFF_TABLE = 'PHONE_LIST_INTRANET';
    private const STAFF_COLUMNS = ['ID', 'NAME_FIRST', 'NAME_LAST_CO', 'PREF_NAME', 'EMAIL', 'MOBILE_NO', 'PH_EXT', 'VOIP', 'HOME_PHONE',
        'POSITION', 'POSITION_NO', 'POSITION_ABBR', 'JOB', 'JOB_TYPE', 'AWARD_CODE', 'TAG', 'TAG_DESCRIPTION', 'DIV_CODE', 'COMPANY_ID', 'REPORTS_TO_ID',
        'E_STATUS', 'START_DATE', 'ON_LEAVE', 'DOB', 'HIDE_BIRTHDAY'];
    private const DIVISION_TABLE = 'DIVISIONS';
    private const DIVISION_COLUMNS = ['DIV_CODE', 'DIV_NAME', 'PHONE', 'DIV_ADDR1', 'DIV_ADDR2', 'DIV_PCODE', 'POSTAL_ADDRESS'];

    /** Kept in capitals by prettyName(). */
    private const ACRONYMS = ['JWH', 'IT', 'GSH', 'W.A.'];

    public function __construct(private readonly Source $source, private readonly Ingestor $ingestor, private readonly Jobs $jobs)
    {
    }

    public function configured(): bool
    {
        return $this->source->configured();
    }

    /**
     * Import everything (divisions, then staff, as snapshots), or one staff member by Oracle ID.
     *
     * @param callable(string): void $progress
     * @return array<string, array<string, int>> Type => result => count.
     */
    public function run(?string $staffId, string $runId, string $trigger, callable $progress): array
    {
        $updatedAt = (new DateTimeImmutable('now', wp_timezone()))->format(DATE_RFC3339);
        $today = new DateTimeImmutable('today', wp_timezone());

        $progress('Reading Oracle…');
        $staffRows = $this->source->fetch(self::STAFF_TABLE, self::STAFF_COLUMNS);
        $byPosition = [];
        foreach ($staffRows as $row) {
            if (trim($row['POSITION_NO']) !== '') {
                $byPosition[trim($row['POSITION_NO'])] ??= trim($row['ID']);
            }
        }
        $staff = array_map(fn (array $row): array => self::staff($row, $byPosition, $updatedAt, $today), $staffRows);

        if ($staffId !== null) {
            $record = current(array_filter($staff, fn (array $record): bool => $record['id'] === $staffId));
            if (!$record) {
                return ['staff' => ['not_found' => 1]];
            }
            return ['staff' => [$this->ingestor->put('staff', $staffId, $record, $runId, $trigger)->result => 1]];
        }

        // Only divisions someone current works in.
        $staffed = array_flip(array_column(array_filter($staff, fn (array $record): bool => $record['status'] === 'active'), 'division_id'));
        $divisions = array_values(array_filter(
            array_map(fn (array $row): array => self::division($row, $updatedAt), $this->source->fetch(self::DIVISION_TABLE, self::DIVISION_COLUMNS)),
            fn (array $division): bool => isset($staffed[$division['id']]),
        ));

        // New divisions get their company from the list above instead of one named after them.
        $mapped = fn (string $name, string $id): string => self::companyFor($id) ?? $name;
        add_filter('intranet_phonelist_new_division_company', $mapped, 10, 2);
        try {
            $counts = [];
            foreach (['divisions' => $divisions, 'staff' => $staff] as $type => $records) {
                $progress(sprintf('Importing %d %s…', count($records), $type));
                $results = $this->jobs->applySnapshot($type, array_map(fn (array $record): array => ['id' => $record['id'], 'data' => $record], $records), $runId, $trigger);
                $counts[$type] = array_count_values(array_column($results, 'result'));
            }
        } finally {
            remove_filter('intranet_phonelist_new_division_company', $mapped, 10);
        }

        return $counts;
    }

    /**
     * Oracle staff row → contract record. $staffIdByPosition: POSITION_NO => the ID of the person holding it,
     * so REPORTS_TO_ID (a position) becomes reports_to (a staff ID).
     */
    public static function staff(array $row, array $staffIdByPosition, string $updatedAt, DateTimeImmutable $today): array
    {
        $text = fn (string $column): ?string => trim((string) ($row[$column] ?? '')) === '' ? null : trim((string) $row[$column]);
        $id = (string) $text('ID');
        $manager = $staffIdByPosition[(string) $text('REPORTS_TO_ID')] ?? null;
        $email = $text('EMAIL');
        $current = strtoupper((string) $text('E_STATUS')) === 'E' && strtoupper((string) $text('PH_EXT')) !== 'LEFT';

        return [
            'id' => $id,
            'updated_at' => $updatedAt,
            'first_name' => $text('NAME_FIRST'),
            'last_name' => $text('NAME_LAST_CO'),
            'preferred_name' => $text('PREF_NAME'),
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'mobile' => $text('MOBILE_NO'),
            'phone' => strtoupper((string) $text('PH_EXT')) === 'LEFT' ? null : $text('PH_EXT'),
            'extension' => $text('VOIP'),
            'home_phone' => $text('HOME_PHONE'),
            'position' => $text('POSITION'),
            'position_number' => $text('POSITION_NO'),
            'position_abbr' => $text('POSITION_ABBR'),
            'job' => $text('JOB'),
            'job_type' => $text('JOB_TYPE'),
            'award_code' => $text('AWARD_CODE'),
            'department_code' => $text('TAG'),
            'department' => $text('TAG_DESCRIPTION'),
            'division_id' => $text('DIV_CODE'),
            'company_id' => $text('COMPANY_ID'),
            'reports_to' => $manager === $id ? null : $manager,
            'status' => $current ? 'active' : 'inactive',
            'start_date' => self::date((string) $text('START_DATE'), null),
            'on_leave_until' => self::date((string) $text('ON_LEAVE'), null),
            'date_of_birth' => self::date((string) $text('DOB'), $today),
            'hide_birthday' => strtolower((string) $text('HIDE_BIRTHDAY')) === 'y',
        ];
    }

    /**
     * Oracle division row → contract record.
     */
    public static function division(array $row, string $updatedAt): array
    {
        $text = fn (string $column): ?string => trim((string) ($row[$column] ?? '')) === '' ? null : trim((string) $row[$column]);

        return [
            'id' => (string) $text('DIV_CODE'),
            'updated_at' => $updatedAt,
            'name' => self::prettyName((string) $text('DIV_NAME')),
            'phone' => $text('PHONE'),
            'address_line1' => $text('DIV_ADDR1'),
            'address_line2' => $text('DIV_ADDR2'),
            'postcode' => $text('DIV_PCODE'),
            'postal_address' => $text('POSTAL_ADDRESS'),
        ];
    }

    /**
     * The company COMPANIES puts a division in, or null.
     */
    public static function companyFor(string $divisionId): ?string
    {
        foreach (self::COMPANIES as $company => $divisions) {
            if (in_array($divisionId, $divisions, true)) {
                return $company;
            }
        }

        return null;
    }

    /**
     * 'W.A. COUNTRY BUILDERS ( BUNBURY)' → 'W.A. Country Builders (Bunbury)'.
     */
    public static function prettyName(string $name): string
    {
        $name = preg_replace(['/\s+/', '/\(\s*/', '/\s*\)/'], [' ', '(', ')'], trim($name));
        $name = ucwords(strtolower($name), " -(/");
        $acronyms = implode('|', array_map(fn (string $acronym): string => preg_quote($acronym, '/'), self::ACRONYMS));

        return preg_replace_callback("/\\b($acronyms)(?=\\W|$)/i", fn (array $match): string => strtoupper($match[1]), $name);
    }

    /**
     * An Oracle date ('05Sep85', or Ymd from real DATE columns) as YYYY-MM-DD, or null if empty ('N/A' too) or unreadable.
     * With $notAfter (birth dates), a two-digit year never lands in the future.
     */
    private static function date(string $value, ?DateTimeImmutable $notAfter): ?string
    {
        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value) ?: DateTimeImmutable::createFromFormat('!dMy', $value);
        $errors = DateTimeImmutable::getLastErrors(); // Catches overflow like '31Feb85'.
        if (!$date || ($errors && $errors['warning_count'])) {
            return null;
        }

        return ($notAfter && $date > $notAfter ? $date->modify('-100 years') : $date)->format('Y-m-d');
    }
}
