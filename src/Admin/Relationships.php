<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use WP_Post;

/**
 * Read-only "Relationships" boxes on the staff, division and company edit screens, from the stored links
 * (staff division, company and manager; division company).
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
        $reports = $this->staffWhere('manager', (string) $staff->ID);

        $this->table([
            'Division' => $this->linkTo((int) $this->meta($staff, 'division')),
            'Company' => $this->linkTo((int) $this->meta($staff, 'company')),
            'Reports to' => $this->linkTo((int) $this->meta($staff, 'manager')),
            sprintf('Direct reports (%d)', count($reports)) => $this->links($reports),
        ]);
    }

    public function renderDivision(WP_Post $division): void
    {
        $staff = $this->staffWhere('division', (string) $division->ID);

        $this->table(['Company' => $this->linkTo((int) get_post_meta($division->ID, 'company', true))]);
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
            $count = count($this->staffWhere('division', (string) $division->ID));
            $total += $count;
            $rows[] = [$this->link($division), '<code>' . esc_html($this->meta($division, PostTypes::SOURCE_ID)) . '</code>', $count];
        }

        $this->list(sprintf('Divisions (%d) · Staff (%d)', count($divisions), $total), ['Division', 'ID', 'Staff'], $rows);
    }

    /**
     * @return WP_Post[] Staff whose $key field equals $value exactly, by name.
     */
    private function staffWhere(string $key, string $value): array
    {
        return get_posts([
            'post_type' => PostTypes::STAFF,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function linkTo(int $postId): string
    {
        $post = $postId ? get_post($postId) : null;

        return $post ? $this->link($post) : '—';
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
