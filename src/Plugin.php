<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Admin\Relationships;
use ConstructiveMedia\IntranetPhonelist\Admin\SettingsPage;
use ConstructiveMedia\IntranetPhonelist\Api\StaffController;
use ConstructiveMedia\IntranetPhonelist\Cli\ClubCommand;
use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;

/**
 * Wires the plugin into WordPress. The only place objects are built.
 */
final class Plugin
{
    public const DAILY_HOOK = 'iphl_daily';

    private static Ingestor $ingestor;
    private static Relations $relations;
    private static Jobs $jobs;

    /**
     * The one Ingestor, for the API, jobs, the Oracle import and tests/wp-check.php.
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
        $log->maybeUpgrade();
        self::$relations = new Relations($log, $wpdb);
        self::$relations->register();
        $photos = Photos::fromConstants($log);
        self::$ingestor = new Ingestor($log, $wpdb, self::$relations, $photos);
        self::$jobs = new Jobs(self::$ingestor, self::$relations, $log, $wpdb);
        self::$jobs->register();
        $postTypes = new PostTypes();

        add_action('init', [$postTypes, 'register']);
        add_action('admin_init', [PostTypes::class, 'seedClub']);
        add_action('admin_menu', [$postTypes, 'registerMenu'], 9);
        add_action('acf/include_fields', [$postTypes, 'registerFields']);
        $postTypes->lock();
        (new StaffController())->register();
        register_activation_hook($pluginFile, [$log, 'install']);

        // Daily housekeeping: expired log entries and finished jobs.
        add_action(self::DAILY_HOOK, [$log, 'prune']);
        add_action(self::DAILY_HOOK, [self::$jobs, 'prune']);
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }

        if (is_admin()) {
            (new SettingsPage($photos, $log))->register();
            (new Relationships())->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            (new ClubCommand())->register();
        }
    }
}
