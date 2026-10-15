<?php

namespace ConstructiveMedia\IntranetPhonelist;

/**
 * Company (managed by hand) → Division (synced) → Staff (synced).
 */
final class PostTypes
{
    /** Value kinds: how a synced column is converted at sync and shown in the editor. */
    public const DATE = 'date';
    /** 'y' → '1', anything else → '0'; a locked Yes/No select (a checkbox's label stays clickable). */
    public const BOOL = 'bool';
    /** 'e' → 'active', anything else → 'inactive'; an ACF select. */
    public const STATUS = 'status';
    /** Stored in international E.164 format via libphonenumber ('+61437793226'); unreadable numbers are kept as they are. */
    public const PHONE = 'phone';
    public const STATUS_CHOICES = ['active' => 'Active', 'inactive' => 'Inactive'];
    /** Two-digit years resolve to the most recent past year: a birth date is never in the future. */
    public const BIRTH_DATE = 'birth_date';

    /** Prefix for everything this plugin puts in WordPress's shared namespaces. */
    private const PREFIX = 'iphl_';

    public const COMPANY = self::PREFIX . 'company';
    public const DIVISION = self::PREFIX . 'division';
    public const STAFF = self::PREFIX . 'staff';

    /**
     * Links stored on each staff member by the sync (post IDs), worked out from synced fields:
     * division from DIV_CODE, company from the division's Company, reports_to from REPORTS_TO_ID = the manager's POSITION_NO.
     */
    public const STAFF_LINKS = [
        'division' => ['label' => 'Division', 'type' => self::DIVISION, 'multiple' => false],
        'company' => ['label' => 'Company', 'type' => self::COMPANY, 'multiple' => false],
        'reports_to' => ['label' => 'Reports To', 'type' => self::STAFF, 'multiple' => true],
    ];

    /**
     * JWH Club: a taxonomy on staff, set by hand on each staff member (not from HR: service can have breaks).
     * Leavers drop off with their record; retired Lifetime members are added with the 'intranet_phonelist_club' filter.
     */
    public const CLUB_TAXONOMY = self::PREFIX . 'club';

    /** The levels created if the taxonomy is empty (slug => [name, description]), in page order. */
    public const CLUB = [
        'lifetime-club' => ['Lifetime Club', 'Our lifetime club members.'],
        '40-year-club' => ['40 Year Club', 'Celebrating 40 years with JWH Group.'],
        '35-year-club' => ['35 Year Club', 'Celebrating 35 years with JWH Group.'],
        '30-year-club' => ['30 Year Club', 'Celebrating 30 years with JWH Group.'],
        '25-year-club' => ['25 Year Club', 'Celebrating 25 years with JWH Group.'],
        '20-year-club' => ['20 Year Club', 'Celebrating 20 years with JWH Group.'],
        '15-year-club' => ['15 Year Club', 'Celebrating 15 years with JWH Group.'],
        '10-year-club' => ['10 Year Club', 'Celebrating 10 years with JWH Group.'],
    ];

    /** HR fields kept out of the log: it records that they changed, never their values. */
    public const PRIVATE_COLUMNS = ['DOB', 'HOME_PHONE'];

    /** Post meta on synced posts: the Oracle key, and the fingerprint of the last synced row. */
    public const SOURCE_ID = '_' . self::PREFIX . 'source_id';
    public const SOURCE_HASH = '_' . self::PREFIX . 'source_hash';

    /** Who can see and manage Phonelist in the admin. */
    public const CAPABILITY = 'manage_options';

    /** The Phonelist menu opens the Staff list. */
    public const MENU = 'edit.php?post_type=' . self::STAFF;

    /** Kept in capitals by prettyName(). */
    private const ACRONYMS = ['JWH', 'IT', 'GSH', 'W.A.'];

    /**
     * Post types mirrored from Oracle, in sync order (divisions before staff).
     *
     * 'fields' is the editor layout: tab => [ORACLE_COLUMN => [label, width %, date kind?]]. Widths in a row add up to 100.
     * Kinds (self::DATE, BIRTH_DATE, BOOL, STATUS, PHONE) are converted once at sync (Planner::normalize()),
     * e.g. Oracle's '05Sep85' → ACF's Ymd '19850905'. 'columns' (what the sync SELECTs and writes) and
     * 'kinds' (column => kind) are derived from it.
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
                        'PHONE' => ['Phone', 33, self::PHONE], 'FAX' => ['Fax', 33, self::PHONE], 'DX_NO' => ['DX Number', 34],
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
                        // PH_EXT holds full direct numbers; the short extensions are in VOIP (left as they are).
                        'EMAIL' => ['Email', 50], 'MOBILE_NO' => ['Mobile', 25, self::PHONE], 'PH_EXT' => ['Direct Phone', 25, self::PHONE],
                        'VOIP' => ['Extension (VoIP)', 50], 'HOME_PHONE' => ['Home Phone', 50, self::PHONE],
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
                        'E_STATUS' => ['Status', 33, self::STATUS], 'ON_LEAVE' => ['On Leave', 33, self::DATE], 'AWARD_CODE' => ['Award Code', 34],
                    ],
                    'Birthday' => [
                        'DOB' => ['Date of Birth', 50, self::BIRTH_DATE], 'HIDE_BIRTHDAY' => ['Hide Birthday', 50, self::BOOL],
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
            $type['kinds'] = array_filter(array_map(fn (array $field): ?string => $field[2] ?? null, $fields));
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
        return 'field_iphl_' . self::shortName($type) . '_' . strtolower($column);
    }

    /**
     * 'iphl_staff' → 'staff', for ACF keys (already prefixed, and stored against every post, so kept stable).
     */
    private static function shortName(string $type): string
    {
        return substr($type, strlen(self::PREFIX));
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

        // Administrators only: staff records hold HR data (DOB, home phone).
        $create = ['create_posts'];
        $delete = ['delete_posts', 'delete_published_posts', 'delete_others_posts', 'delete_private_posts'];
        $edit = ['edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts'];
        $adminOnly = array_fill_keys([...$create, ...$delete, ...$edit], self::CAPABILITY);

        // Synced types can be viewed and edited (e.g. a division's Company) but not added or deleted by hand:
        // a hand-made post has no Oracle ID, so the sync would never manage it.
        $syncedOnly = array_fill_keys([...$create, ...$delete], 'do_not_allow') + $adminOnly;

        // Registration order is the submenu order: Companies, Divisions, Staff.
        foreach ($types as $type => $config) {
            register_post_type($type, [
                'labels' => ['name' => $config['plural'], 'singular_name' => $config['singular']],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => self::MENU,
                'supports' => $type === self::STAFF ? ['title', 'thumbnail'] : ['title'],
                'map_meta_cap' => true,
                'capabilities' => $type === self::COMPANY ? $adminOnly : $syncedOnly,
            ]);
        }

        register_taxonomy(self::CLUB_TAXONOMY, self::STAFF, [
            'labels' => ['name' => 'JWH Club', 'singular_name' => 'Club Level', 'add_new_item' => 'Add New Club Level', 'search_items' => 'Search Club Levels'],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Added to the Phonelist menu by registerMenu().
            'show_admin_column' => true,
            'meta_box_cb' => false, // Set with the JWH Club field in the staff sidebar.
            'capabilities' => array_fill_keys(['manage_terms', 'edit_terms', 'delete_terms', 'assign_terms'], self::CAPABILITY),
        ]);
    }

    /**
     * Creates the standard levels once, while the taxonomy is empty; after that they're managed under Phonelist → JWH Club.
     */
    public static function seedClub(): void
    {
        if (get_terms(['taxonomy' => self::CLUB_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1])) {
            return;
        }
        foreach (self::CLUB as $slug => [$name, $description]) {
            wp_insert_term($name, self::CLUB_TAXONOMY, ['slug' => $slug, 'description' => $description]);
        }
    }

    /**
     * Parent "Phonelist" admin menu (opens Staff); the post types attach themselves to it via show_in_menu.
     * WordPress repeats a parent as its own first submenu item, so that duplicate is removed once the submenus exist.
     */
    public function registerMenu(): void
    {
        add_menu_page('Phonelist', 'Phonelist', self::CAPABILITY, self::MENU, '', 'dashicons-phone', 26);
        add_action('admin_menu', function (): void {
            remove_submenu_page(self::MENU, self::MENU);
            add_submenu_page(self::MENU, 'JWH Club', 'JWH Club', self::CAPABILITY, 'edit-tags.php?taxonomy=' . self::CLUB_TAXONOMY . '&amp;post_type=' . self::STAFF); // &amp; as WordPress writes it, so the item highlights.
        }, 99);
    }

    public function registerFields(): void
    {
        // Synced fields are read-only in the editor: Oracle owns them.
        foreach (self::synced() as $type => $config) {
            $fields = [];
            foreach ($config['fields'] as $tab => $columns) {
                $fields[] = ['key' => 'field_iphl_' . self::shortName($type) . '_tab_' . sanitize_key($tab), 'label' => $tab, 'type' => 'tab'];
                foreach ($columns as $column => [$label, $width]) {
                    $kind = match ($config['kinds'][$column] ?? null) {
                        self::DATE, self::BIRTH_DATE => ['type' => 'date_picker', 'display_format' => 'j M Y', 'return_format' => 'Ymd'],
                        self::BOOL => ['type' => 'select', 'choices' => ['1' => 'Yes', '0' => 'No'], 'disabled' => 1],
                        self::STATUS => ['type' => 'select', 'choices' => self::STATUS_CHOICES, 'disabled' => 1],
                        default => [],
                    };
                    $fields[] = $kind + [
                        'key' => self::fieldKey($type, $column),
                        'name' => strtolower($column),
                        'label' => $label,
                        'type' => 'text',
                        'readonly' => 1,
                        'wrapper' => ['width' => $width, 'class' => 'iphl-locked'],
                    ];
                }
            }

            acf_add_local_field_group([
                'key' => 'group_iphl_' . self::shortName($type),
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

        // JWH Club: set by hand, never by the sync. Saved as the staff member's club term.
        acf_add_local_field_group([
            'key' => 'group_iphl_staff_club',
            'title' => 'JWH Club',
            'fields' => [[
                'key' => 'field_iphl_staff_club',
                'name' => 'club',
                'label' => 'Club',
                'type' => 'taxonomy',
                'taxonomy' => self::CLUB_TAXONOMY,
                'field_type' => 'select',
                'allow_null' => 1,
                'add_term' => 0,
                'save_terms' => 1,
                'load_terms' => 1,
                'return_format' => 'id',
            ]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => self::STAFF]]],
            'position' => 'side',
        ]);

        acf_add_local_field_group([
            'key' => 'group_iphl_club_level',
            'title' => 'Club Level',
            'fields' => [[
                'key' => 'field_iphl_club_logo',
                'name' => 'logo',
                'label' => 'Club Logo',
                'type' => 'image',
                'return_format' => 'id',
                'preview_size' => 'thumbnail',
            ]],
            'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => self::CLUB_TAXONOMY]]],
        ]);

        // Staff links, stored by the sync so Admin Columns and queries can use them. Hidden in the editor
        // (the Relationships box shows them) and disabled, so a manual save never overwrites them.
        acf_add_local_field_group([
            'key' => 'group_iphl_staff_links',
            'title' => 'Links',
            'style' => 'seamless',
            'fields' => array_map(fn (string $name, array $link): array => [
                'key' => self::linkKey($name),
                'name' => $name,
                'label' => $link['label'],
                'type' => 'post_object',
                'post_type' => [$link['type']],
                'multiple' => (int) $link['multiple'],
                'return_format' => 'id',
                'allow_null' => 1,
                'disabled' => 1,
                'wrapper' => ['class' => 'hidden'],
            ], array_keys(self::STAFF_LINKS), self::STAFF_LINKS),
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => self::STAFF]]],
        ]);
    }

    public static function linkKey(string $name): string
    {
        return 'field_iphl_staff_link_' . $name;
    }

    /**
     * Write a synced value the way ACF stores it (value + field-key reference), bypassing ACF's save path,
     * which lock() closes to everyone else.
     */
    public static function store(int $postId, string $fieldKey, string $name, mixed $value): void
    {
        update_post_meta($postId, $name, $value);
        update_post_meta($postId, '_' . $name, $fieldKey);
    }

    /**
     * Synced fields and staff links can't be changed from the editor: whatever is submitted, the stored value is kept.
     * (ACF's 'disabled'/'readonly' is only visual and isn't honoured by every field type.) Hand-set fields stay editable.
     */
    public function lock(): void
    {
        $locked = [];
        foreach (self::synced() as $type => $config) {
            foreach ($config['columns'] as $column) {
                $locked[self::fieldKey($type, $column)] = strtolower($column);
            }
        }
        foreach (array_keys(self::STAFF_LINKS) as $name) {
            $locked[self::linkKey($name)] = $name;
        }

        foreach ($locked as $key => $name) {
            add_filter("acf/update_value/key=$key", fn ($value, $postId) => get_post_meta((int) $postId, $name, true), 10, 2);
        }

        // And make them look locked: no clicking, typing or date pickers.
        add_action('admin_head', function (): void {
            if (in_array(get_current_screen()?->post_type, [self::DIVISION, self::STAFF], true)) {
                echo '<style>.iphl-locked .acf-input { pointer-events: none; opacity: .75; }</style>';
            }
        });
    }
}
