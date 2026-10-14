<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use WP_Post;

/**
 * Read-only "Relationships" boxes on the staff, division and company edit screens.
 * Worked out live from synced fields (div_code, position_no, reports_to_id) and the division's Company,
 * so there's nothing extra to store or keep in sync.
 */
final class Relationships
{
    public function register(): void
    {
        $boxes = [
            PostTypes::STAFF => [$this, 'renderStaff'],
            PostTypes::DIVISION => [$this, 'renderDivision'],
            PostTypes::COMPANY => [$this, 'renderCompany'],
        ];

        foreach ($boxes as $type => $render) {
            add_action('add_meta_boxes_' . $type, fn () => add_meta_box('iphl-relationships', 'Relationships', $render, $type));
        }
    }

    public function renderStaff(WP_Post $staff): void
    {
        $division = $this->divisionByCode($this->meta($staff, 'div_code'));
        $companyId = $division ? (int) get_post_meta($division->ID, 'company', true) : 0;
        $reportsToId = $this->meta($staff, 'reports_to_id');
        $positionNo = $this->meta($staff, 'position_no');

        // Reports-to is position based: REPORTS_TO_ID is the manager's POSITION_NO.
        $managers = $reportsToId === '' ? [] : $this->staffWhere('position_no', $reportsToId, exclude: $staff->ID);
        $reports = $positionNo === '' ? [] : $this->staffWhere('reports_to_id', $positionNo, exclude: $staff->ID);

        $this->table([
            'Division' => $division ? $this->link($division) : '—',
            'Company' => $companyId ? $this->link(get_post($companyId)) : '—',
            'Reports to' => $this->links($managers),
            sprintf('Direct reports (%d)', count($reports)) => $this->links($reports),
        ]);
    }

    public function renderDivision(WP_Post $division): void
    {
        $companyId = (int) get_post_meta($division->ID, 'company', true);
        $staff = $this->staffWhere('div_code', $this->meta($division, PostTypes::SOURCE_ID));

        $this->table(['Company' => $companyId ? $this->link(get_post($companyId)) : '—']);
        $this->list(
            sprintf('Staff (%d)', count($staff)),
            ['Name', 'Position'],
            array_map(fn (WP_Post $person): array => [$this->link($person), esc_html($this->meta($person, 'position'))], $staff),
        );
    }

    public function renderCompany(WP_Post $company): void
    {
        $divisions = get_posts([
            'post_type' => PostTypes::DIVISION,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => 'company',
            'meta_value' => $company->ID,
        ]);

        $rows = [];
        $total = 0;
        foreach ($divisions as $division) {
            $count = count(get_posts([
                'post_type' => PostTypes::STAFF,
                'numberposts' => -1,
                'fields' => 'ids', // Counting only.
                'meta_key' => 'div_code',
                'meta_value' => $this->meta($division, PostTypes::SOURCE_ID),
            ]));
            $total += $count;
            $rows[] = [$this->link($division), '<code>' . esc_html($this->meta($division, PostTypes::SOURCE_ID)) . '</code>', $count];
        }

        $this->list(sprintf('Divisions (%d) · Staff (%d)', count($divisions), $total), ['Division', 'Code', 'Staff'], $rows);
    }

    /**
     * @return WP_Post[] Staff whose $key field equals $value exactly, by name.
     */
    private function staffWhere(string $key, string $value, int $exclude = 0): array
    {
        return get_posts([
            'post_type' => PostTypes::STAFF,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => $key,
            'meta_value' => $value,
            'exclude' => $exclude ? [$exclude] : [],
        ]);
    }

    private function divisionByCode(string $code): ?WP_Post
    {
        return $code === '' ? null : (get_posts([
            'post_type' => PostTypes::DIVISION,
            'numberposts' => 1,
            'meta_key' => PostTypes::SOURCE_ID,
            'meta_value' => $code,
        ])[0] ?? null);
    }

    private function meta(WP_Post $post, string $key): string
    {
        return trim((string) get_post_meta($post->ID, $key, true));
    }

    private function link(WP_Post $post): string
    {
        $url = get_edit_post_link($post->ID);

        return $url ? sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($post->post_title)) : esc_html($post->post_title);
    }

    /**
     * @param WP_Post[] $posts
     */
    private function links(array $posts): string
    {
        return $posts ? implode('<br>', array_map(fn (WP_Post $post): string => $this->link($post), $posts)) : '—';
    }

    /**
     * Label → already-escaped HTML.
     */
    private function table(array $rows): void
    {
        echo '<table class="widefat striped" style="margin-bottom:12px"><tbody>';
        foreach ($rows as $label => $html) {
            printf('<tr><th style="width:30%%">%s</th><td>%s</td></tr>', esc_html($label), $html);
        }
        echo '</tbody></table>';
    }

    /**
     * @param string[] $headings
     * @param array<array<string|int>> $rows Already-escaped HTML cells.
     */
    private function list(string $title, array $headings, array $rows): void
    {
        printf('<h4 style="margin:12px 0 6px">%s</h4>', esc_html($title));
        if (!$rows) {
            echo '<p>None.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($headings as $heading) {
            printf('<th>%s</th>', esc_html($heading));
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $cells) {
            echo '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
