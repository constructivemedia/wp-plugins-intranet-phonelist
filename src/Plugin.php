<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Admin\Relationships;
use ConstructiveMedia\IntranetPhonelist\Admin\SettingsPage;
use ConstructiveMedia\IntranetPhonelist\Api\IngressController;
use ConstructiveMedia\IntranetPhonelist\Api\ReadController;
use ConstructiveMedia\IntranetPhonelist\Cli\ClubCommand;
use ConstructiveMedia\IntranetPhonelist\Cli\OracleImportCommand;
use ConstructiveMedia\IntranetPhonelist\Oracle\Adapter;
use ConstructiveMedia\IntranetPhonelist\Oracle\Source;
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
    /** The admin's Oracle import runs here, via WP-Cron, so the browser never waits on it. */
    public const ORACLE_HOOK = 'iphl_oracle_import';

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

    /**
     * The development Oracle import (does nothing without Oracle credentials).
     */
    public static function oracle(): Adapter
    {
        return new Adapter(Source::fromConstants(), self::$ingestor, self::$jobs);
    }

    public static function boot(string $pluginFile): void
    {
        global $wpdb;

        $log = new Log($wpdb);
        $log->maybeUpgrade();
        Upgrade::maybeRun($log);
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
        (new IngressController(self::$ingestor, self::$jobs, $log))->register();
        (new ReadController(self::$ingestor))->register();
        register_activation_hook($pluginFile, [$log, 'install']);

        // Daily housekeeping: expired log entries and finished jobs.
        add_action(self::DAILY_HOOK, [$log, 'prune']);
        add_action(self::DAILY_HOOK, [self::$jobs, 'prune']);
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }

        add_action(self::ORACLE_HOOK, function (int $userId = 0): void {
            wp_set_current_user($userId); // Logged as the user who clicked Import.
            self::oracle()->run(null, wp_generate_uuid4(), 'manual', fn () => null);
        });

        if (is_admin()) {
            (new SettingsPage(self::$jobs, $photos, $log, self::oracle()->configured()))->register();
            (new Relationships())->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            (new ClubCommand())->register();
            if (self::oracle()->configured()) {
                (new OracleImportCommand(self::oracle()))->register();
            }
        }
    }
}
