<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Sync\CompanyMap;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Sync\Photos;
use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;

/**
 * Settings → Phonelist: Sync (last result, Sync Now), Logs (filterable audit log with payloads),
 * Companies (division → company mapping) and Photos (staff without a photo).
 */
final class SettingsPage
{
    private const SLUG = 'intranet-phonelist';
    private const PER_PAGE = 100;
    private const TABS = ['sync' => 'Sync', 'logs' => 'Logs', 'companies' => 'Companies', 'photos' => 'Photos'];
    private const LEVEL_COLOURS = ['error' => '#d63638', 'warning' => '#996800', 'notice' => '#2271b1', 'info' => '#50575e', 'debug' => '#8c8f94'];

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Photos $photos,
        private readonly CompanyMap $companies,
        private readonly Log $log,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_options_page('Phonelist', 'Phonelist', 'manage_options', self::SLUG, [$this, 'render']));
        add_action('admin_post_intranet_phonelist_sync', [$this, 'handleSync']);
        add_action('admin_post_intranet_phonelist_delete_logs', [$this, 'handleDeleteLogs']);
        add_action('admin_post_intranet_phonelist_save_companies', [$this, 'handleSaveCompanies']);
        add_action('admin_post_intranet_phonelist_clear_lock', [$this, 'handleClearLock']);
    }

    public function handleSaveCompanies(): void
    {
        $this->authorize('intranet_phonelist_save_companies');
        $this->companies->save(CompanyMap::parse(sanitize_textarea_field(wp_unslash($_POST['companies'] ?? ''))));
        $result = $this->synchronizer->applyCompanyMap();
        $this->redirect([
            'tab' => 'companies',
            'notice' => $result['status'] === 'failed' ? 'companies_busy' : 'companies_saved',
            'created' => $result['counts']['created'],
            'updated' => $result['counts']['updated'],
        ]);
    }

    /**
     * Queue the sync in the background (WP-Cron) and return straight away; the Sync tab shows its progress.
     */
    public function handleSync(): void
    {
        $this->authorize('intranet_phonelist_sync');
        if ($this->synchronizer->running()) {
            $this->redirect(['notice' => 'already_running']);
        }
        if (!wp_next_scheduled(Synchronizer::BACKGROUND_HOOK, [get_current_user_id()])) {
            wp_schedule_single_event(time(), Synchronizer::BACKGROUND_HOOK, [get_current_user_id()]);
        }
        spawn_cron();
        $this->redirect(['notice' => 'sync_started']);
    }

    public function handleClearLock(): void
    {
        $this->authorize('intranet_phonelist_clear_lock');
        $this->synchronizer->clearLock();
        $this->redirect(['notice' => 'lock_cleared']);
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
            'sync_started' => ['success', 'Sync started in the background. Progress shows below; this page refreshes until it finishes.'],
            'already_running' => ['warning', 'A sync is already running.'],
            'lock_cleared' => ['success', 'Lock cleared. You can start a new sync.'],
            'logs_deleted' => ['success', sprintf('Deleted %d log entries.', absint($_GET['count'] ?? 0))],
            'companies_saved' => ['success', sprintf('Mapping saved: %d companies created, %d divisions updated.', absint($_GET['created'] ?? 0), absint($_GET['updated'] ?? 0))],
            'companies_busy' => ['warning', 'Mapping saved. A sync is running, so it will be applied by that sync or the next one.'],
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
                'companies' => $this->renderCompanies(),
                'photos' => $this->renderPhotos(),
                'logs' => $this->renderLogs(),
                default => $this->renderSync(),
            }; ?>
        </div>
        <?php
    }

    private function renderCompanies(): void
    {
        $map = $this->companies->get();
        ['divisions' => $mapped, 'conflicts' => $conflicts] = CompanyMap::byDivision($map);

        // Synced divisions by code, with their current company and staff count.
        $divisions = [];
        foreach (get_posts(['post_type' => PostTypes::DIVISION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $division) {
            $code = (string) get_post_meta($division->ID, PostTypes::SOURCE_ID, true);
            $divisions[$code] = $division;
        }
        $unmapped = array_diff_key($divisions, $mapped);
        ?>
        <p>One company per line, then its division codes: <code>WA Country Builders: GTN, BSN, BNB, ALB, CDR</code>.
            Saving creates any missing companies and sets each listed division's Company; every full sync re-applies it.
            A division not listed keeps a company set by hand on the division; otherwise it gets a company named after itself, so everyone has a company.</p>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <input type="hidden" name="action" value="intranet_phonelist_save_companies">
            <?php wp_nonce_field('intranet_phonelist_save_companies'); ?>
            <textarea name="companies" rows="<?= max(6, count($map) + 2) ?>" class="large-text code" style="max-width:900px"><?= esc_textarea(CompanyMap::format($map)) ?></textarea>
            <?php submit_button('Save and apply', 'primary', 'submit', false); ?>
        </form>

        <?php if ($conflicts) : ?>
            <div class="notice notice-warning inline"><p>Listed under more than one company (the first is used):
                <?= esc_html(implode(', ', array_map(fn (string $code, array $names): string => "$code (" . implode(' / ', $names) . ')', array_keys($conflicts), $conflicts))) ?></p></div>
        <?php endif; ?>

        <?php if ($map) : ?>
            <h2>Mapping</h2>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Company</th><th>Divisions</th></tr></thead>
                <tbody>
                <?php foreach ($map as $company => $codes) : ?>
                    <tr>
                        <td><?= esc_html($company) ?></td>
                        <td>
                            <?php foreach ($codes as $code) : ?>
                                <?php if (isset($divisions[$code])) : ?>
                                    <div><code><?= esc_html($code) ?></code> <a href="<?= esc_url(get_edit_post_link($divisions[$code]->ID)) ?>"><?= esc_html($divisions[$code]->post_title) ?></a></div>
                                <?php else : ?>
                                    <div><code><?= esc_html($code) ?></code> <span style="color:#996800">no synced division with this code</span></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($unmapped) : ?>
            <h2>Divisions not in the mapping (<?= count($unmapped) ?>)</h2>
            <ul style="list-style:disc;margin-left:20px">
                <?php foreach ($unmapped as $code => $division) : ?>
                    <?php $companyId = (int) get_post_meta($division->ID, 'company', true); ?>
                    <li><code><?= esc_html((string) $code) ?></code> <a href="<?= esc_url(get_edit_post_link($division->ID)) ?>"><?= esc_html($division->post_title) ?></a>
                        — <?= $companyId ? esc_html(get_the_title($companyId)) : '<span style="color:#996800">no company</span>' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
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

        <?php
        $running = $this->synchronizer->running();
        $queued = wp_next_scheduled(Synchronizer::BACKGROUND_HOOK, [get_current_user_id()]);
        ?>
        <?php if ($running) : ?>
            <div class="notice notice-info inline">
                <p>
                    <span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span>
                    <strong>Sync running</strong> since <?= esc_html(wp_date('g:i:sa', $running['started'])) ?>:
                    <?= esc_html($running['step'] ?? 'starting…') ?>
                    <span style="color:#646970">(last activity <?= esc_html(wp_date('g:i:sa', $running['beat'])) ?>)</span>
                </p>
            </div>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" onsubmit="return confirm('Only clear the lock if the sync has stopped (e.g. the server was restarted). Clear it?');">
                <input type="hidden" name="action" value="intranet_phonelist_clear_lock">
                <?php wp_nonce_field('intranet_phonelist_clear_lock'); ?>
                <?php submit_button('Clear lock', 'secondary small', 'submit', false); ?>
            </form>
        <?php elseif ($queued) : ?>
            <div class="notice notice-info inline"><p><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><strong>Sync queued</strong>, starting…</p>
                <?php if ($queued < time() - MINUTE_IN_SECONDS) : ?>
                    <p>It hasn't started after a minute, so WP-Cron may not be running on this server (it needs to reach its own <code>wp-cron.php</code>).
                        Run <code>wp phonelist sync</code> instead, or have the server's cron call <code>wp-cron.php</code>.</p>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <input type="hidden" name="action" value="intranet_phonelist_sync">
                <?php wp_nonce_field('intranet_phonelist_sync'); ?>
                <?php submit_button('Sync Now', 'primary', 'submit', false); ?>
            </form>
        <?php endif; ?>
        <?php if ($running || $queued) : ?>
            <script>setTimeout(() => location.replace(location.href.replace(/&notice=[^&]*/, '')), 5000);</script>
        <?php endif; ?>
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
