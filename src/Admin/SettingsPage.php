<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\Sync\Log;
use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;

/**
 * Settings → Phonelist, in two tabs: Sync (last result, Sync Now) and Logs (filterable audit log with payloads).
 */
final class SettingsPage
{
    private const SLUG = 'intranet-phonelist';
    private const PER_PAGE = 100;
    private const LEVEL_COLOURS = ['error' => '#d63638', 'warning' => '#996800', 'notice' => '#2271b1', 'info' => '#50575e', 'debug' => '#8c8f94'];

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Log $log,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_options_page('Phonelist', 'Phonelist', 'manage_options', self::SLUG, [$this, 'render']));
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
        $this->redirect(['tab' => 'logs', 'notice' => 'logs_deleted', 'count' => $deleted]);
    }

    public function render(): void
    {
        $tab = ($_GET['tab'] ?? '') === 'logs' ? 'logs' : 'sync';
        $notices = [
            'synced' => ['success', 'Sync completed.'],
            'sync_failed' => ['error', 'Sync did not complete cleanly. See the log.'],
            'logs_deleted' => ['success', sprintf('Deleted %d log entries.', absint($_GET['count'] ?? 0))],
        ];
        $notice = $notices[$_GET['notice'] ?? ''] ?? null;
        $tabUrl = fn (string $name): string => admin_url('options-general.php?page=' . self::SLUG . ($name === 'logs' ? '&tab=logs' : ''));
        ?>
        <div class="wrap">
            <h1>Phonelist</h1>
            <nav class="nav-tab-wrapper">
                <a href="<?= esc_url($tabUrl('sync')) ?>" class="nav-tab <?= $tab === 'sync' ? 'nav-tab-active' : '' ?>">Sync</a>
                <a href="<?= esc_url($tabUrl('logs')) ?>" class="nav-tab <?= $tab === 'logs' ? 'nav-tab-active' : '' ?>">Logs</a>
            </nav>

            <?php if ($notice) : ?>
                <div class="notice notice-<?= esc_attr($notice[0]) ?> is-dismissible"><p><?= esc_html($notice[1]) ?></p></div>
            <?php endif; ?>

            <?php $tab === 'logs' ? $this->renderLogs() : $this->renderSync(); ?>
        </div>
        <?php
    }

    private function renderSync(): void
    {
        $last = get_option(Synchronizer::LAST_SYNC);
        $counts = !empty($last['run']) ? $this->log->levelCounts($last['run']) : [];
        ?>
        <?php if (!function_exists('oci_connect')) : ?>
            <div class="notice notice-error inline"><p>The <code>oci8</code> PHP extension is not loaded, so sync cannot connect to Oracle.</p></div>
        <?php endif; ?>

        <h2>Last sync</h2>
        <p>
            <?= $last
                ? esc_html(wp_date('j M Y g:ia', $last['time']) . " ({$last['trigger']}) — {$last['status']}. {$last['message']}")
                : 'Never.' ?>
        </p>
        <?php foreach (['error', 'warning'] as $level) : ?>
            <?php if (!empty($counts[$level])) : ?>
                <p>
                    <?= $this->badge($level) ?> <?= (int) $counts[$level] ?> in the last sync —
                    <a href="<?= esc_url(add_query_arg(['tab' => 'logs', 'run' => $last['run'], 'level' => $level])) ?>">view</a>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="intranet_phonelist_sync">
            <?php wp_nonce_field('intranet_phonelist_sync'); ?>
            <?php submit_button('Sync Now', 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderLogs(): void
    {
        $actions = $this->log->actions();
        $filters = [
            'level' => in_array($_GET['level'] ?? '', Log::LEVELS, true) ? $_GET['level'] : '',
            'action' => in_array($_GET['log_action'] ?? '', $actions, true) ? $_GET['log_action'] : '',
            'run_id' => sanitize_text_field(wp_unslash($_GET['run'] ?? '')),
        ];
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $log = $this->log->page($paged, self::PER_PAGE, $filters);
        $postUrl = admin_url('admin-post.php');
        ?>
        <form method="get" style="margin:12px 0 8px">
            <input type="hidden" name="page" value="<?= esc_attr(self::SLUG) ?>">
            <input type="hidden" name="tab" value="logs">
            <label for="iphl-level">Level</label>
            <select id="iphl-level" name="level">
                <option value="">All</option>
                <?php foreach (Log::LEVELS as $level) : ?>
                    <option value="<?= esc_attr($level) ?>" <?php selected($filters['level'], $level); ?>><?= esc_html($level) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="iphl-action">Action</label>
            <select id="iphl-action" name="log_action">
                <option value="">All</option>
                <?php foreach ($actions as $action) : ?>
                    <option value="<?= esc_attr($action) ?>" <?php selected($filters['action'], $action); ?>><?= esc_html($action) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filters['run_id']) : ?>
                <input type="hidden" name="run" value="<?= esc_attr($filters['run_id']) ?>">
                Run <code><?= esc_html(substr($filters['run_id'], 0, 8)) ?></code> (<a href="<?= esc_url(remove_query_arg(['run', 'paged'])) ?>">all runs</a>)
            <?php endif; ?>
            <?php submit_button('Filter', '', '', false); ?>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Time (UTC)</th><th>Level</th><th>Run</th><th>Trigger</th><th>Action</th><th>Type</th><th>Source ID</th><th>Label</th><th>Message</th></tr></thead>
            <tbody>
            <?php if (!$log['rows']) : ?>
                <tr><td colspan="9">No log entries.</td></tr>
            <?php endif; ?>
            <?php foreach ($log['rows'] as $row) : ?>
                <?php $context = $row->context ? json_decode($row->context, true) : null; ?>
                <tr>
                    <td><?= esc_html($row->created_at) ?></td>
                    <td><?= $this->badge($row->level) ?></td>
                    <td><a href="<?= esc_url(add_query_arg(['run' => $row->run_id, 'paged' => false])) ?>"><code><?= esc_html(substr($row->run_id, 0, 8)) ?></code></a></td>
                    <td><?= esc_html($row->triggered_by) ?></td>
                    <td><?= esc_html($row->action) ?></td>
                    <td><?= esc_html($row->post_type) ?></td>
                    <td><?= esc_html($row->source_id) ?></td>
                    <td><?= esc_html($row->label) ?></td>
                    <td>
                        <?= esc_html($row->message) ?>
                        <?php if ($context) : ?>
                            <details><summary>Details</summary><?php $this->renderContext($context); ?></details>
                        <?php endif; ?>
                    </td>
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
        <?php
    }

    /**
     * Payload, shaped for reading: changes as from → to, item lists as links, anything else as JSON.
     */
    private function renderContext(array $context): void
    {
        if (isset($context['changes'])) {
            echo '<table class="widefat" style="margin-top:6px"><thead><tr><th>Field</th><th>From</th><th>To</th></tr></thead><tbody>';
            foreach ($context['changes'] as $field => $change) {
                is_array($change)
                    ? printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($field), esc_html($change['from']), esc_html($change['to']))
                    : printf('<tr><td>%s</td><td colspan="2"><em>%s</em></td></tr>', esc_html($field), esc_html($change));
            }
            echo '</tbody></table>';
            unset($context['changes']);
        }

        if (isset($context['items'])) {
            echo '<ul style="list-style:disc;margin:6px 0 0 20px;columns:2">';
            foreach ($context['items'] as $item) {
                $label = esc_html($item['label'] ?? '');
                $link = !empty($item['postId']) ? get_edit_post_link((int) $item['postId']) : null;
                $extra = array_diff_key($item, array_flip(['postId', 'label']));
                printf(
                    '<li>%s <span style="color:#646970">%s</span></li>',
                    $link ? sprintf('<a href="%s">%s</a>', esc_url($link), $label) : $label,
                    esc_html(implode(' · ', array_map(fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value, $extra))),
                );
            }
            echo '</ul>';
            unset($context['items']);
        }

        if ($context) {
            printf('<pre style="white-space:pre-wrap;margin-top:6px">%s</pre>', esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
    }

    private function badge(string $level): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;background:%s;font-size:11px">%s</span>',
            esc_attr(self::LEVEL_COLOURS[$level] ?? '#50575e'),
            esc_html($level),
        );
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
