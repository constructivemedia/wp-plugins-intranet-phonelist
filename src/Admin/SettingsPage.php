<?php

namespace ConstructiveMedia\IntranetPhonelist\Admin;

use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;
use ConstructiveMedia\IntranetPhonelist\Plugin;
use ConstructiveMedia\IntranetPhonelist\PostTypes;

/**
 * Settings → Phonelist: Jobs (recent batches and snapshots; the Oracle import on development sites),
 * Logs (filterable audit log with payloads) and Photos (staff without a photo).
 */
final class SettingsPage
{
    private const SLUG = 'intranet-phonelist';
    private const PER_PAGE = 100;
    private const TABS = ['jobs' => 'Jobs', 'logs' => 'Logs', 'photos' => 'Photos'];
    private const LEVEL_COLOURS = ['error' => '#d63638', 'warning' => '#996800', 'notice' => '#2271b1', 'info' => '#50575e'];

    public function __construct(
        private readonly Jobs $jobs,
        private readonly Photos $photos,
        private readonly Log $log,
        private readonly bool $oracle,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_options_page('Phonelist', 'Phonelist', PostTypes::CAPABILITY, self::SLUG, [$this, 'render']));
        add_action('admin_post_intranet_phonelist_delete_logs', [$this, 'handleDeleteLogs']);
        add_action('admin_post_intranet_phonelist_oracle_import', [$this, 'handleOracleImport']);
        add_action('admin_post_intranet_phonelist_run_job', [$this, 'handleRunJob']);
    }

    /**
     * Run a waiting or failed job now.
     */
    public function handleRunJob(): void
    {
        $this->authorize('intranet_phonelist_run_job');
        $ran = $this->jobs->process(sanitize_key(wp_unslash($_POST['job'] ?? '')));
        $this->redirect(['notice' => $ran ? 'job_ran' : 'job_busy']);
    }

    public function handleDeleteLogs(): void
    {
        $this->authorize('intranet_phonelist_delete_logs');
        $deleted = $this->log->delete(absint($_POST['days'] ?? 0));
        $this->redirect(['tab' => 'logs', 'notice' => 'logs_deleted', 'count' => $deleted]);
    }

    /**
     * Queue the Oracle import in the background (WP-Cron) and return straight away.
     */
    public function handleOracleImport(): void
    {
        $this->authorize('intranet_phonelist_oracle_import');
        if ($this->oracle && !wp_next_scheduled(Plugin::ORACLE_HOOK, [get_current_user_id()])) {
            wp_schedule_single_event(time(), Plugin::ORACLE_HOOK, [get_current_user_id()]);
            spawn_cron();
        }
        $this->redirect(['notice' => 'import_started']);
    }

    public function render(): void
    {
        $tab = isset(self::TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'jobs';
        $notices = [
            'import_started' => ['success', 'Oracle import started in the background. Its results show in the Logs tab.'],
            'job_ran' => ['success', 'Job run. Its results are below.'],
            'job_busy' => ['warning', 'That job wasn\'t run: another job is running, or it has already finished.'],
            'logs_deleted' => ['success', sprintf('Deleted %d log entries.', absint($_GET['count'] ?? 0))],
        ];
        $notice = $notices[$_GET['notice'] ?? ''] ?? null;
        ?>
        <div class="wrap">
            <h1>Phonelist</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach (self::TABS as $name => $label) : ?>
                    <a href="<?= esc_url($this->url($name === 'jobs' ? [] : ['tab' => $name])) ?>" class="nav-tab <?= $tab === $name ? 'nav-tab-active' : '' ?>"><?= esc_html($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($notice) : ?>
                <div class="notice notice-<?= esc_attr($notice[0]) ?> is-dismissible"><p><?= esc_html($notice[1]) ?></p></div>
            <?php endif; ?>

            <?php match ($tab) {
                'photos' => $this->renderPhotos(),
                'logs' => $this->renderLogs(),
                default => $this->renderJobs(),
            }; ?>
        </div>
        <?php
    }

    private function renderJobs(): void
    {
        $jobs = $this->jobs->recent();
        $busy = array_filter($jobs, fn (array $job): bool => in_array($job['status'], ['queued', 'running'], true))
            || wp_next_scheduled(Plugin::ORACLE_HOOK, [get_current_user_id()]);
        ?>
        <?php if ($this->oracle) : ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin:12px 0">
                <input type="hidden" name="action" value="intranet_phonelist_oracle_import">
                <?php wp_nonce_field('intranet_phonelist_oracle_import'); ?>
                <?php submit_button('Import from Oracle', 'secondary', 'submit', false); ?>
                <span class="description">Development only: reads Oracle and sends every division and staff member through the API's ingest.</span>
            </form>
        <?php endif; ?>

        <p>Batches and snapshots sent by Data Hub. Single-record changes are in the Logs tab.</p>
        <table class="widefat iphl-log">
            <thead><tr><th>Created</th><th>Job</th><th>Kind</th><th>Status</th><th>Items</th><th>Results</th><th>Finished</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$jobs) : ?>
                <tr><td colspan="8">No jobs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($jobs as $job) : ?>
                <tr class="iphl-entry">
                    <td><?= esc_html($this->when($job['created_at'])) ?></td>
                    <td>
                        <a href="<?= esc_url($this->url(['tab' => 'logs', 'run' => $job['id']])) ?>"><code><?= esc_html(substr($job['id'], 0, 8)) ?></code></a>
                        <div class="iphl-sub"><?= esc_html($job['triggered_by']) ?></div>
                    </td>
                    <td><?= esc_html($job['kind'] . ($job['type'] ? " · {$job['type']}" : '')) ?></td>
                    <td><?= $this->badge(match ($job['status']) { 'failed' => 'error', 'running', 'queued' => 'notice', default => 'info' }, $job['status']) ?></td>
                    <td><?= (int) $job['done'] ?> / <?= (int) $job['total'] ?></td>
                    <td><?= esc_html(Jobs::summary($job['results'])) ?></td>
                    <td><?= esc_html($this->when($job['finished_at'])) ?></td>
                    <td>
                        <button type="button" class="button button-small iphl-toggle" aria-expanded="false" aria-controls="iphl-job-<?= esc_attr($job['id']) ?>">View</button>
                        <?php if (in_array($job['status'], ['queued', 'failed'], true)) : ?>
                            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline">
                                <input type="hidden" name="action" value="intranet_phonelist_run_job">
                                <input type="hidden" name="job" value="<?= esc_attr($job['id']) ?>">
                                <?php wp_nonce_field('intranet_phonelist_run_job'); ?>
                                <button type="submit" class="button button-small"><?= $job['status'] === 'failed' ? 'Retry' : 'Run now' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="iphl-detail" id="iphl-job-<?= esc_attr($job['id']) ?>" hidden>
                    <td colspan="8"><pre><?= esc_html(wp_json_encode(['error' => $job['error'], 'results' => $job['results']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $this->logStyles();
        if ($busy) {
            echo '<script>setTimeout(() => location.replace(location.href.replace(/&notice=[^&]*/, "")), 5000);</script>';
        }
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
            Photos are matched by file name in <code><?= esc_html($this->photos->directory()) ?></code> when a staff member is created, unless Data Hub sends a photo URL.
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
                $tried = Photos::candidates($meta('preferred_name'), $meta('first_name'), $meta('last_name'), $meta('division_id'));
                ?>
                <tr>
                    <td><a href="<?= esc_url(get_edit_post_link($person->ID)) ?>"><?= esc_html($person->post_title) ?></a></td>
                    <td><code><?= esc_html($meta('division_id')) ?></code></td>
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
        ?>
        <form method="get" style="margin:12px 0 8px">
            <input type="hidden" name="page" value="<?= esc_attr(self::SLUG) ?>">
            <input type="hidden" name="tab" value="logs">
            <label for="iphl-run">Job</label>
            <select id="iphl-run" name="run">
                <option value="">All</option>
                <?php foreach ($runs as $runId => $run) : ?>
                    <option value="<?= esc_attr($runId) ?>" <?php selected($filters['run_id'], $runId); ?>>
                        <?= esc_html(implode(' · ', array_filter([
                            $this->when($run->started),
                            $this->who($run->triggered_by, (int) $run->user_id),
                            $run->status !== 'done' ? $run->status : null, // Only worth showing when a job didn't finish.
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

        <table class="widefat iphl-log">
            <thead><tr><th>Time</th><th>Job</th><th>Level</th><th>Action</th><th>Record</th><th>ID</th><th>Message</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$log['rows']) : ?>
                <tr><td colspan="8">No log entries.</td></tr>
            <?php endif; ?>
            <?php foreach ($log['rows'] as $row) : ?>
                <tr class="iphl-entry">
                    <td><?= esc_html($this->when($row->created_at)) ?></td>
                    <td>
                        <a href="<?= esc_url(add_query_arg(['run' => $row->run_id, 'paged' => false])) ?>"><code><?= esc_html(substr($row->run_id, 0, 8)) ?></code></a>
                        <div class="iphl-sub"><?= esc_html($this->who($row->triggered_by, (int) $row->user_id)) ?></div>
                    </td>
                    <td><?= $this->badge($row->level) ?></td>
                    <td><?= esc_html($row->action) ?></td>
                    <td><?= in_array($row->action, ['job_start', 'job_end'], true) ? '' : esc_html($row->label) ?></td>
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
        <?php $this->logStyles(); ?>
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
     * A UTC time from the tables ('2026-10-15 01:30:00' or '2026-10-15T01:30:00Z') in the site's timezone; '' for none.
     */
    private function when(?string $utc): string
    {
        return $utc ? wp_date('j M Y g:i:sa', strtotime(rtrim(str_replace('T', ' ', $utc), 'Z') . ' UTC')) : '';
    }

    /**
     * 'api', 'oracle' or 'system', or 'manual · Display Name' when a logged-in user did it.
     */
    private function who(string $trigger, int $userId): string
    {
        $user = $userId ? get_userdata($userId) : false;

        return $user ? "$trigger · {$user->display_name}" : $trigger;
    }

    private function badge(string $level, ?string $label = null): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;background:%s;font-size:11px">%s</span>',
            esc_attr(self::LEVEL_COLOURS[$level] ?? '#50575e'),
            esc_html($label ?? $level),
        );
    }

    /**
     * Styles for the Jobs and Logs tables, and their View/Hide toggles.
     */
    private function logStyles(): void
    {
        ?>
        <style>
            .iphl-log td { vertical-align: middle; }
            .iphl-log tr.iphl-entry:nth-of-type(4n+1) { background: #f6f7f7; }
            .iphl-log .iphl-sub { color: #646970; font-size: 12px; }
            .iphl-log .iphl-detail > td { background: #fcfcfc; border-left: 3px solid #2271b1; }
            .iphl-log .iphl-detail pre { margin: 0; white-space: pre-wrap; word-break: break-word; max-height: 480px; overflow: auto; }
        </style>
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
        <?php
    }

    private function authorize(string $nonceAction): void
    {
        if (!current_user_can(PostTypes::CAPABILITY)) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer($nonceAction);
    }

    private function redirect(array $args): never
    {
        wp_safe_redirect($this->url($args));
        exit;
    }
}
