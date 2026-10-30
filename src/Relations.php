<?php

namespace ConstructiveMedia\IntranetPhonelist;

use wpdb;

/**
 * Staff links (division, company, manager) and each division's company, stored as post IDs so Admin Columns and
 * queries can use them. Set when records are written, in whatever order they arrive:
 * - a staff member links to their division, its company and their manager, if those exist yet;
 * - a new division gets its company from COMPANIES (else one named after it; created if needed) and links the staff
 *   waiting on it;
 * - a new staff member becomes the manager of anyone already reporting to them.
 * The one listener: saving a division in the admin re-points its staff to its company.
 */
final class Relations
{
    /** Company => its divisions' Data Hub IDs. A division not listed gets a company named after it. */
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

    public function __construct(private readonly wpdb $db)
    {
    }

    public function register(): void
    {
        add_action('acf/save_post', function ($postId): void {
            if (is_numeric($postId) && get_post_type((int) $postId) === PostTypes::DIVISION) {
                $this->refreshDivision((int) $postId);
            }
        }, 20);
    }

    /**
     * Link a record just written. Returns changes worth logging with it: a new division's company.
     *
     * @return array<string, array{from: null, to: string}>
     */
    public function afterWrite(string $type, int $postId, bool $created): array
    {
        $id = (string) get_post_meta($postId, PostTypes::SOURCE_ID, true);
        $changes = [];

        if ($type === 'staff') {
            $this->linkStaff($postId);
            foreach ($this->staffWhere('reports_to', $id) as $report) {
                $this->linkStaff($report);
            }
        } elseif ($type === 'divisions') {
            if ($created && !(int) get_post_meta($postId, 'company', true)) {
                $name = $this->defaultCompany($postId);
                [$companyId, $new] = $this->companyFor($name);
                PostTypes::store($postId, PostTypes::fieldKey(PostTypes::DIVISION, 'company'), 'company', $companyId ?: '');
                $changes['company'] = ['from' => null, 'to' => $name . ($new ? ' (new company)' : '')];
            }
            $this->refreshDivision($postId);
        }

        return $changes;
    }

    /**
     * Every staff member in the division gets the division's company.
     */
    public function refreshDivision(int $divisionPostId): void
    {
        foreach ($this->staffWhere('division_id', (string) get_post_meta($divisionPostId, PostTypes::SOURCE_ID, true)) as $staff) {
            $this->linkStaff($staff);
        }
    }

    /**
     * Clear every link pointing at a record about to be deleted.
     */
    public function beforeDelete(string $type, int $postId): void
    {
        // A deleted company leaves its divisions and their staff without one; a deleted division leaves its staff
        // without a division or company (they keep division_id, and are linked again if it comes back).
        $cleared = match ($type) {
            'companies' => ['company' => [PostTypes::DIVISION, PostTypes::STAFF]],
            'divisions' => ['division' => [PostTypes::STAFF]],
            'staff' => ['manager' => [PostTypes::STAFF]],
        };
        foreach ($cleared as $name => $postTypes) {
            foreach ($this->postsWhere($postTypes, $name, (string) $postId) as $linked) {
                update_post_meta($linked, $name, '');
                if ($type === 'divisions') {
                    update_post_meta($linked, 'company', '');
                }
            }
        }
    }

    /**
     * Recompute every staff member's links (after a full snapshot, as a safety net).
     */
    public function relinkAll(): void
    {
        $ids = get_posts(['post_type' => PostTypes::STAFF, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']);
        update_meta_cache('post', $ids);
        foreach ($ids as $staff) {
            $this->linkStaff($staff);
        }
    }

    /**
     * Put each division whose company was made for it (named after it, the default before 2.2) or is missing into
     * its default company, then delete the companies made for divisions that none uses any more. Companies set by
     * hand and companies from Data Hub stay. Returns how many divisions moved.
     */
    public function applyDefaultCompanies(): int
    {
        $moved = 0;
        foreach (get_posts(['post_type' => PostTypes::DIVISION, 'numberposts' => -1, 'post_status' => 'any']) as $division) {
            $current = (int) get_post_meta($division->ID, 'company', true);
            if ($current && get_post_field('post_title', $current, 'raw') !== $division->post_title) {
                continue; // Set by hand.
            }
            [$companyId] = $this->companyFor($this->defaultCompany($division->ID));
            if ($companyId && $companyId !== $current) {
                PostTypes::store($division->ID, PostTypes::fieldKey(PostTypes::DIVISION, 'company'), 'company', $companyId);
                $this->refreshDivision($division->ID);
                $moved++;
            }
        }

        foreach (get_posts(['post_type' => PostTypes::COMPANY, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']) as $company) {
            if (get_post_meta($company, PostTypes::SOURCE_ID, true) === '' && !$this->postsWhere([PostTypes::DIVISION], 'company', (string) $company)) {
                $this->beforeDelete('companies', $company);
                wp_delete_post($company, true);
            }
        }

        return $moved;
    }

    /**
     * A division's company name: from COMPANIES, else the division's own name. The
     * 'intranet_phonelist_new_division_company' filter can name another.
     */
    private function defaultCompany(int $divisionPostId): string
    {
        $id = (string) get_post_meta($divisionPostId, PostTypes::SOURCE_ID, true);
        $listed = array_keys(array_filter(self::COMPANIES, fn (array $divisions): bool => in_array($id, $divisions, true)));

        return (string) apply_filters('intranet_phonelist_new_division_company', $listed[0] ?? get_post_field('post_title', $divisionPostId, 'raw'), $id);
    }

    /**
     * The company with exactly this name, created if there isn't one (with no Data Hub ID).
     *
     * @return array{0: int, 1: bool} [company post ID (0 if it couldn't be created), whether it was created]
     */
    private function companyFor(string $name): array
    {
        $existing = (int) $this->db->get_var($this->db->prepare(
            "SELECT ID FROM {$this->db->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s ORDER BY ID LIMIT 1",
            PostTypes::COMPANY,
            $name
        ));
        if ($existing) {
            return [$existing, false];
        }
        $id = wp_insert_post(wp_slash(['post_type' => PostTypes::COMPANY, 'post_status' => 'publish', 'post_title' => $name]));

        return [(int) $id, (bool) $id];
    }

    /**
     * Set a staff member's division, company and manager from division_id, the division's company and reports_to.
     * Only changed links are written.
     */
    private function linkStaff(int $staffPostId): void
    {
        $division = PostTypes::postId(PostTypes::DIVISION, (string) get_post_meta($staffPostId, 'division_id', true));
        $manager = PostTypes::postId(PostTypes::STAFF, (string) get_post_meta($staffPostId, 'reports_to', true));
        $links = [
            'division' => $division,
            'company' => $division ? (int) get_post_meta($division, 'company', true) : 0,
            'manager' => $manager === $staffPostId ? 0 : $manager,
        ];
        foreach ($links as $name => $value) {
            if ((int) get_post_meta($staffPostId, $name, true) !== $value) {
                PostTypes::store($staffPostId, PostTypes::linkKey($name), $name, $value ?: '');
            }
        }
    }

    /**
     * @return int[] Staff post IDs whose $key meta equals $value ('' matches nothing).
     */
    private function staffWhere(string $key, string $value): array
    {
        return $this->postsWhere([PostTypes::STAFF], $key, $value);
    }

    /**
     * @param string[] $postTypes
     * @return int[]
     */
    private function postsWhere(array $postTypes, string $key, string $value): array
    {
        if ($value === '') {
            return [];
        }
        $in = implode(',', array_fill(0, count($postTypes), '%s'));

        return array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT p.ID FROM {$this->db->posts} p JOIN {$this->db->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type IN ($in) AND m.meta_key = %s AND m.meta_value = %s",
            [...$postTypes, $key, $value]
        )));
    }
}
