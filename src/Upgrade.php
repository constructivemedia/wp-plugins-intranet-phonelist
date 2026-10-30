<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Ingest\Log;

/**
 * v1 → v2, in place: posts are kept (photos, JWH Club levels, hand-set division companies survive), their v1
 * field names become the v2 ones, v1-only fields and options go, and every record is marked as never updated
 * so Data Hub's first push always applies. 2.2: divisions move into their companies from Relations::COMPANIES.
 * Safe to run more than once.
 */
final class Upgrade
{
    public const VERSION = '2.2.0';
    public const EPOCH = '1970-01-01T00:00:00+00:00';
    private const VERSION_OPTION = 'intranet_phonelist_version';
    private const UPGRADING = 'intranet_phonelist_upgrading';

    /** v1 field => v2 field, per post type. Fields with the same name in both are left alone. */
    private const RENAMES = [
        PostTypes::STAFF => [
            'pref_name' => 'preferred_name',
            'name_first' => 'first_name',
            'name_last_co' => 'last_name',
            'mobile_no' => 'mobile',
            'ph_ext' => 'phone',
            'voip' => 'extension',
            'position_no' => 'position_number',
            'tag' => 'department_code',
            'tag_description' => 'department',
            'div_code' => 'division_id',
            'e_status' => 'status',
            'on_leave' => 'on_leave_until',
            'dob' => 'date_of_birth',
        ],
        PostTypes::DIVISION => [
            'div_addr1' => 'address_line1',
            'div_addr2' => 'address_line2',
            'div_pcode' => 'postcode',
        ],
    ];

    /** v1 fields with no v2 equivalent. */
    private const DROPPED = [
        PostTypes::STAFF => ['full_name', 'username', 'induction_complete_date', 'reports_to_id', 'id', '_iphl_photo_source'],
        PostTypes::DIVISION => ['div_code', 'div_name', 'fax', 'dx_no'],
    ];

    private const OPTIONS = ['intranet_phonelist_companies', 'intranet_phonelist_last_sync', 'intranet_phonelist_lock'];

    /**
     * Run once per site, the first time v2 loads. A request that finds another one upgrading leaves it to that one.
     * Returns whether it ran.
     */
    public static function maybeRun(Log $log): bool
    {
        if (!version_compare((string) get_option(self::VERSION_OPTION, '1'), self::VERSION, '<') || get_transient(self::UPGRADING)) {
            return false;
        }
        set_transient(self::UPGRADING, 1, 10 * MINUTE_IN_SECONDS); // Expires, so an interrupted upgrade is retried.
        $log->write(wp_generate_uuid4(), 'system', 'notice', 'upgrade', null, null, 'v' . self::VERSION, null, self::run());
        update_option(self::VERSION_OPTION, self::VERSION);
        delete_transient(self::UPGRADING);

        return true;
    }

    /**
     * @return array<string, int> What changed, for the log.
     */
    public static function run(): array
    {
        global $wpdb;
        $counts = ['renamed' => 0, 'dropped' => 0, 'managers' => 0];

        // The v1 manager link (an array of post IDs on 'reports_to') becomes 'manager' (one post ID), and 'reports_to'
        // becomes the manager's Data Hub ID, as Data Hub will send it.
        $v1Links = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_reports_to' AND meta_value = %s",
            PostTypes::linkKey('reports_to')
        ));
        foreach ($v1Links as $row) {
            $postId = (int) $row->post_id;
            $manager = (int) (((array) get_post_meta($postId, 'reports_to', true))[0] ?? 0);
            PostTypes::store($postId, PostTypes::linkKey('manager'), 'manager', $manager ?: '');
            PostTypes::store($postId, PostTypes::fieldKey(PostTypes::STAFF, 'reports_to'), 'reports_to', $manager ? (string) get_post_meta($manager, PostTypes::SOURCE_ID, true) : '');
            $counts['managers']++;
        }

        foreach (self::RENAMES as $postType => $renames) {
            foreach ($renames as $old => $new) {
                $counts['renamed'] += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id SET m.meta_key = %s WHERE p.post_type = %s AND m.meta_key = %s",
                    $new, $postType, $old
                ));
                $wpdb->query($wpdb->prepare(
                    "UPDATE $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id SET m.meta_key = %s, m.meta_value = %s WHERE p.post_type = %s AND m.meta_key = %s",
                    "_$new", PostTypes::fieldKey($postType, $new), $postType, "_$old"
                ));
            }
        }

        foreach (self::DROPPED as $postType => $fields) {
            foreach ($fields as $field) {
                $counts['dropped'] += (int) $wpdb->query($wpdb->prepare(
                    "DELETE m FROM $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key IN (%s, %s)",
                    $postType, $field, "_$field"
                ));
            }
        }

        // Hide Birthday was 'y' before v1.10.
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id SET m.meta_value = IF(LOWER(m.meta_value) IN ('1', 'y'), '1', '0') WHERE p.post_type = %s AND m.meta_key = 'hide_birthday'",
            PostTypes::STAFF
        ));

        // Never updated, so Data Hub's first push always applies.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value)
            SELECT s.post_id, %s, %s FROM $wpdb->postmeta s
            WHERE s.meta_key = %s AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta u WHERE u.post_id = s.post_id AND u.meta_key = %s)",
            PostTypes::UPDATED_AT, self::EPOCH, PostTypes::SOURCE_ID, PostTypes::UPDATED_AT
        ));

        // Divisions used to get a company named after them unless Oracle was configured.
        $counts['moved'] = (new Relations($wpdb))->applyDefaultCompanies();

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        wp_clear_scheduled_hook('intranet_phonelist_sync_background');
        wp_cache_flush();

        return $counts;
    }
}
