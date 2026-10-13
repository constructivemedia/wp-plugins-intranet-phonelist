<?php

namespace ConstructiveMedia\IntranetPhonelist;

/**
 * Company (managed by hand) → Division (synced) → Staff (synced).
 */
final class PostTypes
{
    public const DATE = 'date';
    /** Two-digit years resolve to the most recent past year: a birth date is never in the future. */
    public const BIRTH_DATE = 'birth_date';

    public const COMPANY = 'company';
    public const DIVISION = 'division';
    public const STAFF = 'staff';

    /** The Phonelist menu opens the Staff list. */
    public const MENU = 'edit.php?post_type=' . self::STAFF;

    /** Kept in capitals by prettyName(). */
    private const ACRONYMS = ['JWH', 'IT', 'GSH', 'W.A.'];

    /**
     * Post types mirrored from Oracle, in sync order (divisions before staff).
     *
     * 'fields' is the editor layout: tab => [ORACLE_COLUMN => [label, width %, date kind?]]. Widths in a row add up to 100.
     * Date kinds: self::DATE or self::BIRTH_DATE. Oracle's '05Sep85' is converted once at sync to ACF's Ymd ('19850905').
     * 'columns' (what the sync SELECTs and writes) and 'dates' (column => kind) are derived from it.
     *
     * @return array<string, array{singular: string, plural: string, group: string, table: string, key: string, title: callable, fields: array, columns: string[], dates: array<string, string>}>
     */
    public static function synced(): array
    {
        $types = [
            self::DIVISION => [
                'singular' => 'Division',
                'plural' => 'Divisions',
                'group' => 'Division Details',
                'table' => 'DIVISIONS',
                'key' => 'DIV_CODE',
                'title' => fn (array $row): string => self::prettyName($row['DIV_NAME']),
                'fields' => [
                    'Details' => [
                        'DIV_CODE' => ['Division Code', 25], 'DIV_NAME' => ['Division Name', 75],
                        'PHONE' => ['Phone', 33], 'FAX' => ['Fax', 33], 'DX_NO' => ['DX Number', 34],
                    ],
                    'Address' => [
                        'DIV_ADDR1' => ['Address Line 1', 40], 'DIV_ADDR2' => ['Address Line 2', 40], 'DIV_PCODE' => ['Postcode', 20],
                        'POSTAL_ADDRESS' => ['Postal Address', 100],
                    ],
                ],
            ],
            self::STAFF => [
                'singular' => 'Staff Member',
                'plural' => 'Staff',
                'group' => 'Staff Details',
                'table' => 'PHONE_LIST_INTRANET',
                'key' => 'ID',
                // Admin label only; displays should use the fields.
                'title' => fn (array $row): string => self::displayName($row['PREF_NAME'], $row['NAME_FIRST'], $row['NAME_LAST_CO']),
                'fields' => [
                    'Contact' => [
                        'PREF_NAME' => ['Preferred Name', 33], 'NAME_FIRST' => ['First Name', 33], 'NAME_LAST_CO' => ['Last Name', 34],
                        'FULL_NAME' => ['Full Name', 100],
                        'EMAIL' => ['Email', 50], 'MOBILE_NO' => ['Mobile', 25], 'PH_EXT' => ['Extension', 25],
                        'VOIP' => ['VoIP', 50], 'HOME_PHONE' => ['Home Phone', 50],
                    ],
                    'Role' => [
                        'POSITION' => ['Position', 50], 'POSITION_NO' => ['Position No.', 25], 'POSITION_ABBR' => ['Position Abbr.', 25],
                        'JOB' => ['Job', 50], 'JOB_TYPE' => ['Job Type', 50],
                        'TAG' => ['Department Code', 25], 'TAG_DESCRIPTION' => ['Department', 75],
                        'DIV_CODE' => ['Division Code', 25], 'COMPANY_ID' => ['Company ID', 25], 'REPORTS_TO_ID' => ['Reports To (ID)', 25],
                        'USERNAME' => ['Username', 25],
                    ],
                    'Employment' => [
                        'START_DATE' => ['Start Date', 50, self::DATE], 'INDUCTION_COMPLETE_DATE' => ['Induction Completed', 50, self::DATE],
                        'E_STATUS' => ['Status', 33], 'ON_LEAVE' => ['On Leave', 33, self::DATE], 'AWARD_CODE' => ['Award Code', 34],
                    ],
                    'Birthday' => [
                        'DOB' => ['Date of Birth', 50, self::BIRTH_DATE], 'HIDE_BIRTHDAY' => ['Hide Birthday', 50],
                    ],
                    'Record' => [
                        'ID' => ['Oracle ID', 100],
                    ],
                ],
            ],
        ];

        foreach ($types as &$type) {
            $fields = array_merge(...array_values($type['fields']));
            $type['columns'] = array_keys($fields);
            $type['dates'] = array_filter(array_map(fn (array $field): ?string => $field[2] ?? null, $fields));
        }

        return $types;
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

    public static function fieldKey(string $type, string $column): string
    {
        return 'field_iphl_' . $type . '_' . strtolower($column);
    }

    /**
     * Preferred name (else first name) + surname.
     */
    public static function displayName(string $preferred, string $first, string $last): string
    {
        return trim((trim($preferred) !== '' ? $preferred : $first) . ' ' . $last);
    }

    public function register(): void
    {
        $types = [self::COMPANY => ['singular' => 'Company', 'plural' => 'Companies']] + self::synced();

        // Synced types can be viewed and edited (e.g. a division's Company) but not added or deleted by hand:
        // a hand-made post has no Oracle ID, so the sync would never manage it.
        $syncedOnly = array_fill_keys(
            ['create_posts', 'delete_posts', 'delete_published_posts', 'delete_others_posts', 'delete_private_posts'],
            'do_not_allow',
        );

        // Registration order is the submenu order: Companies, Divisions, Staff.
        foreach ($types as $type => $config) {
            register_post_type($type, [
                'labels' => ['name' => $config['plural'], 'singular_name' => $config['singular']],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => self::MENU,
                'supports' => ['title'],
                'map_meta_cap' => true,
                'capabilities' => $type === self::COMPANY ? [] : $syncedOnly,
            ]);
        }
    }

    /**
     * Parent "Phonelist" admin menu (opens Staff); the post types attach themselves to it via show_in_menu.
     * WordPress repeats a parent as its own first submenu item, so that duplicate is removed once the submenus exist.
     */
    public function registerMenu(): void
    {
        add_menu_page('Phonelist', 'Phonelist', 'edit_posts', self::MENU, '', 'dashicons-phone', 26);
        add_action('admin_menu', fn () => remove_submenu_page(self::MENU, self::MENU), 99);
    }

    public function registerFields(): void
    {
        // Synced fields are read-only in the editor: Oracle owns them.
        foreach (self::synced() as $type => $config) {
            $fields = [];
            foreach ($config['fields'] as $tab => $columns) {
                $fields[] = ['key' => "field_iphl_{$type}_tab_" . sanitize_key($tab), 'label' => $tab, 'type' => 'tab'];
                foreach ($columns as $column => [$label, $width]) {
                    $date = isset($config['dates'][$column]) ? ['type' => 'date_picker', 'display_format' => 'j M Y', 'return_format' => 'Ymd'] : [];
                    $fields[] = $date + [
                        'key' => self::fieldKey($type, $column),
                        'name' => strtolower($column),
                        'label' => $label,
                        'type' => 'text',
                        'readonly' => 1,
                        'wrapper' => ['width' => $width],
                    ];
                }
            }

            acf_add_local_field_group([
                'key' => 'group_iphl_' . $type,
                'title' => $config['group'],
                'fields' => $fields,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $type]]],
            ]);
        }

        acf_add_local_field_group([
            'key' => 'group_iphl_division_company',
            'title' => 'Company',
            'fields' => [[
                'key' => 'field_iphl_division_company',
                'name' => 'company',
                'label' => 'Company',
                'type' => 'post_object',
                'post_type' => [self::COMPANY],
                'return_format' => 'id',
                'allow_null' => 1,
            ]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => self::DIVISION]]],
            'position' => 'side',
        ]);
    }
}
