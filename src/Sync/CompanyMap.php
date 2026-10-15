<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

/**
 * Which divisions belong to which company, edited under Settings → Phonelist → Companies.
 * Oracle's COMPANY_ID follows the legal entity, not the brand, so this mapping can't come from Oracle.
 */
final class CompanyMap
{
    private const OPTION = 'intranet_phonelist_companies';

    /**
     * @return array<string, string[]> Company name => DIV_CODEs.
     */
    public function get(): array
    {
        $map = get_option(self::OPTION, []);

        return is_array($map) ? $map : [];
    }

    public function save(array $map): void
    {
        update_option(self::OPTION, $map, false);
    }

    /**
     * 'WA Country Builders: GTN, BSN' per line → ['WA Country Builders' => ['GTN', 'BSN']]. Blank lines are ignored.
     *
     * @return array<string, string[]>
     */
    public static function parse(string $text): array
    {
        $map = [];
        foreach (preg_split('/\R/', $text) as $line) {
            [$company, $codes] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if ($company !== '') {
                $map[$company] = array_values(array_unique(array_merge($map[$company] ?? [], preg_split('/[\s,]+/', $codes, -1, PREG_SPLIT_NO_EMPTY))));
            }
        }

        return $map;
    }

    public static function format(array $map): string
    {
        return implode("\n", array_map(fn (string $company, array $codes): string => "$company: " . implode(', ', $codes), array_keys($map), $map));
    }

    /**
     * The company a division should belong to, or null to leave it as it is.
     * Mapped → that company. Not mapped but set by hand → keep it. Neither → a company named after the division,
     * so every division (and so every staff member) has a company.
     *
     * @param array<string, string> $mapped DIV_CODE => company name, from byDivision().
     */
    public static function companyFor(string $code, string $divisionName, bool $hasCompany, array $mapped): ?string
    {
        return $mapped[$code] ?? ($hasCompany ? null : $divisionName);
    }

    /**
     * @return array{divisions: array<string, string>, conflicts: array<string, string[]>}
     *     DIV_CODE => company name (exact codes, first listing wins), and DIV_CODE => every company listing it, where more than one does.
     */
    public static function byDivision(array $map): array
    {
        $divisions = [];
        $listed = [];
        foreach ($map as $company => $codes) {
            foreach ($codes as $code) {
                $listed[$code][] = (string) $company;
                $divisions[$code] ??= (string) $company;
            }
        }

        return ['divisions' => $divisions, 'conflicts' => array_filter($listed, fn (array $companies): bool => count($companies) > 1)];
    }
}
