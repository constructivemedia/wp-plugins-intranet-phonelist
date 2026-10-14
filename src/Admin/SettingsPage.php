<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Sync\Log;
use ConstructiveMedia\IntranetPhonelist\Sync\Photos;
use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;

/**
 * Settings → Phonelist, in three tabs: Sync (last result, Sync Now), Logs (filterable audit log with payloads)
 * and Photos (staff without a photo).
 */
final class SettingsPage
{
    private const SLUG = 'intranet-phonelist';
    private const PER_PAGE = 100;
    private const TABS = ['sync' => 'Sync', 'logs' => 'Logs', 'photos' => 'Photos'];
    private const LEVEL_COLOURS = ['error' => '#d63638', 'warning' => '#996800', 'notice' => '#2271b1', 'info' => '#50575e', 'debug' => '#8c8f94'];

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Photos $photos,
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
        $result = $this->synchronizer->run('manual');
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
        $tab = isset(self::TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'sync';
        $notices = [
            'synced' => ['success', 'Sync completed.'],
            'sync_failed' => ['error', 'Sync did not complete cleanly. See the log.'],
            'logs_deleted' => ['success', sprintf('Deleted %d log entries.', absint($_GET['count'] ?? 0))],
        ];
        $notice = $notices[$_GET['notice'] ?? ''] ?? null;
        ?>
        <div class="wrap">
            <h1>Phonelist</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach (self::TABS as $name => $label) : ?>
                    <a href="<?= esc_url($this->url($name === 'sync' ? [] : ['tab' => $name])) ?>" class="nav-tab <?= $tab === $name ? 'nav-tab-active' : '' ?>"><?= esc_html($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($notice) : ?>
                <div class="notice notice-<?= esc_attr($notice[0]) ?> is-dismissible"><p><?= esc_html($notice[1]) ?></p></div>
            <?php endif; ?>

            <?php match ($tab) {
                'photos' => $this->renderPhotos(),
                'logs' => $this->renderLogs(),
                default => $this->renderSync(),
            }; ?>
        </div>
        <?php
    }

    private function renderPhotos(): void
    {
        if (!$this->photos->enabled()) {
            echo '<div class="notice notice-warning inline"><p>Staff photos are off: <code>INTRANET_PHONELIST_PHOTOS_DIR</code> is not set to an existing folder.</p></div>';
            return;
        }

        $total = (int) wp_count_posts(PostTypes::STAFF)->publish;
        $missing = get_posts([
            'post_type' => PostTypes::STAFF,
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']],
        ]);
        ?>
        <p>
            <?= (int) ($total - count($missing)) ?> of <?= (int) $total ?> staff have a photo.
            Photos are matched by file name in <code><?= esc_html($this->photos->directory()) ?></code> on each sync.
        </p>
        <?php if (!$missing) : ?>
            <p>Everyone has a photo.</p>
            <?php return; ?>
        <?php endif; ?>

        <h2><?= count($missing) ?> without a photo</h2>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th>Name</th><th>Division</th><th>File names looked for</th></tr></thead>
            <tbody>
            <?php foreach ($missing as $person) : ?>
                <?php
                $meta = fn (string $key): string => (string) get_post_meta($person->ID, $key, true);
                $tried = Photos::candidates(['PREF_NAME' => $meta('pref_name'), 'NAME_FIRST' => $meta('name_first'), 'NAME_LAST_CO' => $meta('name_last_co'), 'DIV_CODE' => $meta('div_code')]);
                ?>
                <tr>
                    <td><a href="<?= esc_url(get_edit_post_link($person->ID)) ?>"><?= esc_html($person->post_title) ?></a></td>
                    <td><code><?= esc_html($meta('div_code')) ?></code></td>
                    <td><code><?= implode('.jpg</code>, <code>', array_map('esc_html', $tried)) ?>.jpg</code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function url(array $args = []): string
    {
        return add_query_arg($args, admin_url('options-general.php?page=' . self::SLUG));
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
        $runs = $this->log->runs();
        $filters = [
            'run_id' => isset($runs[$_GET['run'] ?? '']) ? $_GET['run'] : '',
            'level' => in_array($_GET['level'] ?? '', Log::LEVELS, true) ? $_GET['level'] : '',
            'action' => in_array($_GET['log_action'] ?? '', $actions, true) ? $_GET['log_action'] : '',
        ];
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $log = $this->log->page($paged, self::PER_PAGE, $filters);
        $postUrl = admin_url('admin-post.php');
        $when = fn (string $utc): string => wp_date('j M Y g:i:sa', strtotime($utc . ' UTC'));
        ?>
        <form method="get" style="margin:12px 0 8px">
            <input type="hidden" name="page" value="<?= esc_attr(self::SLUG) ?>">
            <input type="hidden" name="tab" value="logs">
            <label for="iphl-run">Run</label>
            <select id="iphl-run" name="run">
                <option value="">All runs</option>
                <?php foreach ($runs as $runId => $run) : ?>
                    <option value="<?= esc_attr($runId) ?>" <?php selected($filters['run_id'], $runId); ?>>
                        <?= esc_html(implode(' · ', array_filter([
                            $when($run->started),
                            $this->who($run->triggered_by, (int) $run->user_id),
                            $run->status !== 'completed' ? $run->status : null, // Only worth showing when a run didn't complete.
                        ]))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
            <?php submit_button('Filter', '', '', false); ?>
        </form>

        <style>
            .iphl-log td { vertical-align: middle; }
            .iphl-log tr.iphl-entry:nth-of-type(4n+1) { background: #f6f7f7; }
            .iphl-log .iphl-sub { color: #646970; font-size: 12px; }
            .iphl-log .iphl-detail > td { background: #fcfcfc; border-left: 3px solid #2271b1; }
            .iphl-log .iphl-detail pre { margin: 0; white-space: pre-wrap; word-break: break-word; max-height: 480px; overflow: auto; }
        </style>
        <table class="widefat iphl-log">
            <thead><tr><th>Time</th><th>Run</th><th>Level</th><th>Action</th><th>Record</th><th>ID</th><th>Message</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$log['rows']) : ?>
                <tr><td colspan="8">No log entries.</td></tr>
            <?php endif; ?>
            <?php foreach ($log['rows'] as $row) : ?>
                <tr class="iphl-entry">
                    <td><?= esc_html($when($row->created_at)) ?></td>
                    <td>
                        <a href="<?= esc_url(add_query_arg(['run' => $row->run_id, 'paged' => false])) ?>"><code><?= esc_html(substr($row->run_id, 0, 8)) ?></code></a>
                        <div class="iphl-sub"><?= esc_html($this->who($row->triggered_by, (int) $row->user_id)) ?></div>
                    </td>
                    <td><?= $this->badge($row->level) ?></td>
                    <td><?= esc_html($row->action) ?></td>
                    <td><?= in_array($row->action, ['sync_start', 'sync_end'], true) ? '' : esc_html($row->label) ?></td>
                    <td>
                        <?php if ($row->source_id) : ?>
                            <code title="<?= esc_attr(str_replace('iphl_', '', (string) $row->post_type)) ?>"><?= esc_html($row->source_id) ?></code>
                        <?php endif; ?>
                    </td>
                    <td><?= esc_html($row->message) ?></td>
                    <td>
                        <?php if ($row->context) : ?>
                            <button type="button" class="button button-small iphl-toggle" aria-expanded="false" aria-controls="iphl-detail-<?= (int) $row->id ?>">View</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="iphl-detail" id="iphl-detail-<?= (int) $row->id ?>" hidden>
                    <td colspan="8">
                        <?php if ($row->context) : ?>
                            <pre><?= esc_html(wp_json_encode(json_decode($row->context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
            document.addEventListener('click', (event) => {
                const button = event.target.closest('.iphl-toggle');
                if (!button) return;
                const open = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', String(!open));
                button.textContent = open ? 'View' : 'Hide';
                document.getElementById(button.getAttribute('aria-controls')).hidden = open;
            });
        </script>
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
     * 'cli', or 'manual · Display Name' when a logged-in user started it.
     */
    private function who(string $trigger, int $userId): string
    {
        $user = $userId ? get_userdata($userId) : false;

        return $user ? "$trigger · {$user->display_name}" : $trigger;
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
