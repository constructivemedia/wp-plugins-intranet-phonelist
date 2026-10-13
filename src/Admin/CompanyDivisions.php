<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use WP_Post;

/**
 * "Divisions" box on the company edit screen: the divisions whose Company field points here.
 * Read-only; the link is edited on the division, so there's one source of truth.
 */
final class CompanyDivisions
{
    public function register(): void
    {
        add_action('add_meta_boxes_' . PostTypes::COMPANY, fn () => add_meta_box(
            'iphl-company-divisions',
            'Divisions',
            [$this, 'render'],
            PostTypes::COMPANY,
        ));
    }

    public function render(WP_Post $company): void
    {
        $divisions = get_posts([
            'post_type' => PostTypes::DIVISION,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => 'company',
            'meta_value' => $company->ID,
        ]);

        if (!$divisions) {
            echo '<p>No divisions.</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr><th>Division</th><th>Code</th></tr></thead>
            <tbody>
            <?php foreach ($divisions as $division) : ?>
                <tr>
                    <td><a href="<?= esc_url(get_edit_post_link($division->ID)) ?>"><?= esc_html($division->post_title) ?></a></td>
                    <td><code><?= esc_html(get_post_meta($division->ID, '_source_id', true)) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
