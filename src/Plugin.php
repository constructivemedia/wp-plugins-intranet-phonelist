<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Admin\Relationships;
use ConstructiveMedia\IntranetPhonelist\Admin\SettingsPage;
use ConstructiveMedia\IntranetPhonelist\Api\IngressController;
use ConstructiveMedia\IntranetPhonelist\Api\ReadController;
use ConstructiveMedia\IntranetPhonelist\Cli\ClubCommand;
use ConstructiveMedia\IntranetPhonelist\Cli\JobCommand;
use ConstructiveMedia\IntranetPhonelist\Cli\OracleImportCommand;
use ConstructiveMedia\IntranetPhonelist\Oracle\Adapter;
use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;
use Throwable;

/**
 * Wires the plugin into WordPress: the one place its services are built and hooked up.
 */
final class Plugin
{
    public const DAILY_HOOK = 'iphl_daily';
    /** The admin's Oracle import runs here, via WP-Cron, so the browser never waits on it. */
    public const ORACLE_HOOK = 'iphl_oracle_import';

    private static Ingestor $ingestor;
    private static Relations $relations;
    private static Jobs $jobs;

    /**
     * The services, for tests/wp-check.php.
     */
    public static function ingestor(): Ingestor
    {
        return self::$ingestor;
    }

    public static function relations(): Relations
    {
        return self::$relations;
    }

    public static function jobs(): Jobs
    {
        return self::$jobs;
    }

    public static function boot(string $pluginFile): void
    {
        global $wpdb;

        $log = new Log($wpdb);
        $log->register();
        Upgrade::maybeRun($log);
        self::$relations = new Relations($wpdb);
        self::$relations->register();
        $photos = Photos::fromConstants();
        self::$ingestor = new Ingestor($log, self::$relations, $photos);
        self::$jobs = new Jobs(self::$ingestor, self::$relations, $log, $wpdb);
        self::$jobs->register();
        $oracle = new Adapter(self::$ingestor, self::$jobs);
        $postTypes = new PostTypes();

        add_action('init', [$postTypes, 'register']);
        add_action('admin_init', [PostTypes::class, 'seedClub']);
        add_action('admin_menu', [$postTypes, 'registerMenu'], 9);
        add_action('acf/include_fields', [$postTypes, 'registerFields']);
        $postTypes->lock();
        (new IngressController(self::$ingestor, self::$jobs, $log))->register();
        (new ReadController())->register();
        register_deactivation_hook($pluginFile, function (): void {
            wp_clear_scheduled_hook(self::DAILY_HOOK);
            wp_clear_scheduled_hook(self::ORACLE_HOOK);
            Jobs::unschedule();
        });

        // Daily housekeeping: expired log entries and finished jobs.
        add_action(self::DAILY_HOOK, [$log, 'prune']);
        add_action(self::DAILY_HOOK, [self::$jobs, 'prune']);
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }

        if (Adapter::configured()) {
            add_filter('intranet_phonelist_new_division_company', [Adapter::class, 'mapCompanies'], 10, 2);
        }
        add_action(self::ORACLE_HOOK, function (int $userId = 0) use ($oracle, $log): void {
            wp_set_current_user($userId); // Logged as the user who clicked Import.
            try {
                $oracle->run(null, 'manual', fn () => null);
            } catch (Throwable $e) {
                $log->write(wp_generate_uuid4(), 'manual', 'error', 'oracle_error', null, null, 'Oracle import', $e->getMessage());
            }
        });

        if (is_admin()) {
            (new SettingsPage(self::$jobs, $photos, $log, Adapter::configured()))->register();
            (new Relationships())->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            (new ClubCommand())->register();
            (new JobCommand(self::$jobs))->register();
            if (Adapter::configured()) {
                (new OracleImportCommand($oracle))->register();
            }
        }
    }
}
