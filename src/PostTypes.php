<?php

namespace ConstructiveMedia\IntranetPhonelist;

/**
 * Company → Division → Staff, as pushed by Data Hub (see Schema), plus the JWH Club taxonomy.
 */
final class PostTypes
{
    /** Prefix for everything this plugin puts in WordPress's shared namespaces. */
    private const PREFIX = 'iphl_';

    public const COMPANY = self::PREFIX . 'company';
    public const DIVISION = self::PREFIX . 'division';
    public const STAFF = self::PREFIX . 'staff';

    /** Singular and plural labels, in menu order. */
    private const LABELS = [
        self::COMPANY => ['Company', 'Companies'],
        self::DIVISION => ['Division', 'Divisions'],
        self::STAFF => ['Staff Member', 'Staff'],
    ];

    /**
     * Links stored on each staff member (post IDs), set when records are written (see Relations):
     * division from division_id, company from the division's Company, manager from reports_to.
     */
    public const STAFF_LINKS = [
        'division' => ['label' => 'Division', 'type' => self::DIVISION],
        'company' => ['label' => 'Company', 'type' => self::COMPANY],
        'manager' => ['label' => 'Manager', 'type' => self::STAFF],
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

    /** Post meta on pushed posts: the Data Hub ID, the updated_at last applied, and the fingerprint of the stored fields. */
    public const SOURCE_ID = '_' . self::PREFIX . 'source_id';
    public const UPDATED_AT = '_' . self::PREFIX . 'updated_at';
    public const SOURCE_HASH = '_' . self::PREFIX . 'source_hash';

    /** Read-only record details on every pushed post, shown from the post meta the ingest writes. */
    private const RECORD_FIELDS = [
        self::SOURCE_ID => ['label' => 'Data Hub ID', 'kind' => 'string', 'tab' => 'Record', 'width' => 50],
        self::UPDATED_AT => ['label' => 'Updated At', 'kind' => 'string', 'tab' => 'Record', 'width' => 50],
    ];

    /** Who can see and manage Phonelist in the admin. */
    public const CAPABILITY = 'manage_options';

    /** The Phonelist menu opens the Staff list. */
    public const MENU = 'edit.php?post_type=' . self::STAFF;

    /**
     * The post holding a Data Hub ID, or 0.
     */
    public static function postId(string $postType, string $id): int
    {
        global $wpdb;

        return $id === '' ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID
            WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID LIMIT 1",
            $postType,
            self::SOURCE_ID,
            $id
        ));
    }

    /**
     * @return string[] Every Data Hub ID held for a post type.
     */
    public static function sourceIds(string $postType): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT m.meta_value FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s",
            $postType,
            self::SOURCE_ID
        ));
    }

    /**
     * ACF field key: 'field_iphl_staff_first_name'. Stored against every post, so kept stable.
     */
    public static function fieldKey(string $postType, string $field): string
    {
        return "field_{$postType}_$field";
    }

    public static function linkKey(string $name): string
    {
        return self::fieldKey(self::STAFF, "link_$name");
    }

    /**
     * Preferred name (else first name) + surname.
     */
    public static function displayName(?string $preferred, ?string $first, ?string $last): string
    {
        return trim((trim((string) $preferred) !== '' ? $preferred : $first) . ' ' . $last);
    }

    public function register(): void
    {
        // Administrators only: staff records hold HR data (DOB, home phone).
        $create = ['create_posts'];
        $delete = ['delete_posts', 'delete_published_posts', 'delete_others_posts', 'delete_private_posts'];
        $edit = ['edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts'];
        $adminOnly = array_fill_keys([...$create, ...$delete, ...$edit], self::CAPABILITY);

        // Pushed types can be viewed and edited (e.g. a division's Company) but not added or deleted by hand:
        // a hand-made post has no Data Hub ID, so nothing would ever update it.
        $pushedOnly = array_fill_keys([...$create, ...$delete], 'do_not_allow') + $adminOnly;

        // Registration order is the submenu order: Companies, Divisions, Staff.
        foreach (self::LABELS as $type => [$singular, $plural]) {
            register_post_type($type, [
                'labels' => ['name' => $plural, 'singular_name' => $singular],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => self::MENU,
                'supports' => $type === self::DIVISION ? ['title'] : ['title', 'thumbnail'], // Staff and company photos.
                'map_meta_cap' => true,
                'capabilities' => $type === self::COMPANY ? $adminOnly : $pushedOnly,
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

    /**
     * Every pushed field, locked: Data Hub owns them. Plus a Record tab with the Data Hub ID and the last update time.
     */
    public function registerFields(): void
    {
        foreach (Schema::TYPES as $type => $postType) {
            $fields = [];
            $tab = null;
            foreach ([...array_filter(Schema::fields($type), fn (array $config): bool => empty($config['title'])), ...self::RECORD_FIELDS] as $name => $config) {
                if ($config['tab'] !== $tab) {
                    $tab = $config['tab'];
                    $fields[] = ['key' => self::fieldKey($postType, 'tab_' . sanitize_key($tab)), 'label' => $tab, 'type' => 'tab'];
                }
                $fields[] = match ($config['kind']) {
                    'date' => ['type' => 'date_picker', 'display_format' => 'j M Y', 'return_format' => 'Ymd'],
                    'bool' => ['type' => 'select', 'choices' => ['1' => 'Yes', '0' => 'No'], 'allow_null' => 1, 'disabled' => 1],
                    default => [],
                } + [
                    'key' => self::fieldKey($postType, $name),
                    'name' => $name,
                    'label' => $config['label'],
                    'type' => 'text',
                    'readonly' => 1,
                    'wrapper' => ['width' => $config['width'], 'class' => 'iphl-locked'],
                ];
            }

            acf_add_local_field_group([
                'key' => "group_$postType",
                'title' => self::LABELS[$postType][0] . ' Details',
                'fields' => $fields,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $postType]]],
            ]);
        }

        acf_add_local_field_group([
            'key' => 'group_iphl_division_company',
            'title' => 'Company',
            'fields' => [[
                'key' => self::fieldKey(self::DIVISION, 'company'),
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

        // JWH Club: set by hand, never by Data Hub. Saved as the staff member's club term.
        acf_add_local_field_group([
            'key' => 'group_iphl_staff_club',
            'title' => 'JWH Club',
            'fields' => [[
                'key' => self::fieldKey(self::STAFF, 'club'),
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

        // Staff links, so Admin Columns and queries can use them. Hidden in the editor (the Relationships box shows them)
        // and locked, so a manual save never overwrites them.
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
                'return_format' => 'id',
                'allow_null' => 1,
                'disabled' => 1,
                'wrapper' => ['class' => 'hidden'],
            ], array_keys(self::STAFF_LINKS), self::STAFF_LINKS),
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => self::STAFF]]],
        ]);
    }

    /**
     * Write a pushed value the way ACF stores it (value + field-key reference), bypassing ACF's save path,
     * which lock() closes to everyone else.
     */
    public static function store(int $postId, string $fieldKey, string $name, mixed $value): void
    {
        update_post_meta($postId, $name, wp_slash($value)); // WordPress unslashes meta values; keep backslashes as sent.
        update_post_meta($postId, '_' . $name, $fieldKey);
    }

    /**
     * Pushed fields and staff links can't be changed from the editor: whatever is submitted, the stored value is kept.
     * (ACF's 'disabled'/'readonly' is only visual and isn't honoured by every field type.) Hand-set fields stay editable.
     */
    public function lock(): void
    {
        $locked = [];
        foreach (Schema::TYPES as $type => $postType) {
            foreach ([...array_keys(Schema::fields($type)), ...array_keys(self::RECORD_FIELDS)] as $name) {
                $locked[self::fieldKey($postType, $name)] = $name;
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
