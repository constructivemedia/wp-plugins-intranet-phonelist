<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Sync\Log;
use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;

/**
 * Settings → Phone List: last sync, Sync Now, unassigned divisions, audit log, log deletion.
 */
final class SettingsPage
{
    private const SLUG = 'intranet-phonelist';
    private const PER_PAGE = 100;

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Log $log,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_options_page('Phone List', 'Phone List', 'manage_options', self::SLUG, [$this, 'render']));
        add_action('admin_post_intranet_phonelist_sync', [$this, 'handleSync']);
        add_action('admin_post_intranet_phonelist_delete_logs', [$this, 'handleDeleteLogs']);
    }

    public function handleSync(): void
    {
        $this->authorize('intranet_phonelist_sync');
        $result = $this->synchronizer->run('button');
        $this->redirect(['notice' => $result['status'] === 'completed' ? 'synced' : 'sync_failed']);
    }

    public function handleDeleteLogs(): void
    {
        $this->authorize('intranet_phonelist_delete_logs');
        $deleted = $this->log->delete(absint($_POST['days'] ?? 0));
        $this->redirect(['notice' => 'logs_deleted', 'count' => $deleted]);
    }

    public function render(): void
    {
        $last = get_option(Synchronizer::LAST_SYNC);
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $action = in_array($_GET['log_action'] ?? '', Log::ACTIONS, true) ? $_GET['log_action'] : '';
        $run = sanitize_text_field(wp_unslash($_GET['run'] ?? ''));
        $log = $this->log->page($paged, self::PER_PAGE, $action ?: null, $run ?: null);
        $unassigned = $this->unassignedDivisions();

        $notices = [
            'synced' => ['success', 'Sync completed.'],
            'sync_failed' => ['error', 'Sync did not complete cleanly, see the log below.'],
            'logs_deleted' => ['success', sprintf('Deleted %d log entries.', absint($_GET['count'] ?? 0))],
        ];
        $notice = $notices[$_GET['notice'] ?? ''] ?? null;
        $postUrl = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1>Phone List</h1>

            <?php if ($notice) : ?>
                <div class="notice notice-<?= esc_attr($notice[0]) ?> is-dismissible"><p><?= esc_html($notice[1]) ?></p></div>
            <?php endif; ?>
            <?php if (!function_exists('oci_connect')) : ?>
                <div class="notice notice-error"><p>The <code>oci8</code> PHP extension is not loaded, so sync cannot connect to Oracle.</p></div>
            <?php endif; ?>

            <h2>Last sync</h2>
            <p>
                <?= $last
                    ? esc_html(wp_date('j M Y g:ia', $last['time']) . " ({$last['trigger']}) — {$last['status']}. {$last['message']}")
                    : 'Never.' ?>
            </p>
            <form method="post" action="<?= esc_url($postUrl) ?>">
                <input type="hidden" name="action" value="intranet_phonelist_sync">
                <?php wp_nonce_field('intranet_phonelist_sync'); ?>
                <?php submit_button('Sync Now', 'primary', 'submit', false); ?>
            </form>

            <?php if ($unassigned) : ?>
                <div class="notice notice-warning inline">
                    <p><strong><?= count($unassigned) ?> division(s) have no company:</strong></p>
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ($unassigned as $division) : ?>
                            <li><a href="<?= esc_url(get_edit_post_link($division->ID)) ?>"><?= esc_html($division->post_title) ?></a> (<?= esc_html(get_post_meta($division->ID, PostTypes::SOURCE_ID, true)) ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h2>Log</h2>
            <form method="get" style="margin-bottom:8px">
                <input type="hidden" name="page" value="<?= esc_attr(self::SLUG) ?>">
                <label for="iphl-action">Action</label>
                <select id="iphl-action" name="log_action">
                    <option value="">All</option>
                    <?php foreach (Log::ACTIONS as $option) : ?>
                        <option value="<?= esc_attr($option) ?>" <?php selected($action, $option); ?>><?= esc_html($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($run) : ?>
                    <input type="hidden" name="run" value="<?= esc_attr($run) ?>">
                    Run <code><?= esc_html($run) ?></code> (<a href="<?= esc_url(remove_query_arg(['run', 'paged'])) ?>">all runs</a>)
                <?php endif; ?>
                <?php submit_button('Filter', '', '', false); ?>
            </form>

            <table class="widefat striped">
                <thead><tr><th>Time (UTC)</th><th>Run</th><th>Trigger</th><th>Action</th><th>Type</th><th>Source ID</th><th>Label</th><th>Message</th></tr></thead>
                <tbody>
                <?php if (!$log['rows']) : ?>
                    <tr><td colspan="8">No log entries.</td></tr>
                <?php endif; ?>
                <?php foreach ($log['rows'] as $row) : ?>
                    <tr>
                        <td><?= esc_html($row->created_at) ?></td>
                        <td><a href="<?= esc_url(add_query_arg(['run' => $row->run_id, 'paged' => false])) ?>"><code><?= esc_html(substr($row->run_id, 0, 8)) ?></code></a></td>
                        <td><?= esc_html($row->triggered_by) ?></td>
                        <td><?= esc_html($row->action) ?></td>
                        <td><?= esc_html($row->post_type) ?></td>
                        <td><?= esc_html($row->source_id) ?></td>
                        <td><?= esc_html($row->label) ?></td>
                        <td><?= esc_html($row->message) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="tablenav"><div class="tablenav-pages">
                <?= wp_kses_post(paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => max(1, (int) ceil($log['total'] / self::PER_PAGE)),
                ]) ?? '') ?>
            </div></div>

            <h2>Delete logs</h2>
            <form method="post" action="<?= esc_url($postUrl) ?>" onsubmit="return confirm('Permanently delete these log entries?');">
                <input type="hidden" name="action" value="intranet_phonelist_delete_logs">
                <?php wp_nonce_field('intranet_phonelist_delete_logs'); ?>
                <label>Delete entries older than <input type="number" name="days" min="1" value="365" class="small-text"> days</label>
                <?php submit_button('Delete old logs', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?= esc_url($postUrl) ?>" onsubmit="return confirm('Permanently delete ALL log entries?');" style="margin-top:8px">
                <input type="hidden" name="action" value="intranet_phonelist_delete_logs">
                <input type="hidden" name="days" value="0">
                <?php wp_nonce_field('intranet_phonelist_delete_logs'); ?>
                <?php submit_button('Delete all logs', 'delete', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return \WP_Post[]
     */
    private function unassignedDivisions(): array
    {
        return get_posts([
            'post_type' => PostTypes::DIVISION,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => 'company', 'compare' => 'NOT EXISTS'],
                ['key' => 'company', 'value' => ''],
            ],
        ]);
    }

    private function authorize(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer($nonceAction);
    }

    private function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=' . self::SLUG)));
        exit;
    }
}
