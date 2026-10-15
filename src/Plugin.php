<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Admin\Relationships;
use ConstructiveMedia\IntranetPhonelist\Admin\SettingsPage;
use ConstructiveMedia\IntranetPhonelist\Api\StaffController;
use ConstructiveMedia\IntranetPhonelist\Cli\SyncCommand;
use ConstructiveMedia\IntranetPhonelist\Sync\CompanyMap;
use ConstructiveMedia\IntranetPhonelist\Sync\Log;
use ConstructiveMedia\IntranetPhonelist\Sync\OracleSource;
use ConstructiveMedia\IntranetPhonelist\Sync\Photos;
use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;

/**
 * Wires the plugin into WordPress. The only place objects are built.
 */
final class Plugin
{
    public static function boot(string $pluginFile): void
    {
        global $wpdb;

        $log = new Log($wpdb);
        $log->maybeUpgrade();
        $photos = Photos::fromConstants();
        $companies = new CompanyMap();
        $synchronizer = new Synchronizer(OracleSource::fromConstants(), $photos, $companies, $log, $wpdb);
        $postTypes = new PostTypes();

        add_action('init', [$postTypes, 'register']);
        add_action('admin_menu', [$postTypes, 'registerMenu'], 9);
        add_action('acf/include_fields', [$postTypes, 'registerFields']);
        (new StaffController())->register();

        // Sync Now runs here, via WP-Cron, so the browser never waits on a long sync.
        add_action(Synchronizer::BACKGROUND_HOOK, function (int $userId = 0) use ($synchronizer): void {
            wp_set_current_user($userId); // Logged as the user who clicked Sync Now.
            $synchronizer->run('manual');
        });
        register_activation_hook($pluginFile, [$log, 'install']);

        if (is_admin()) {
            (new SettingsPage($synchronizer, $photos, $companies, $log))->register();
            (new Relationships())->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            (new SyncCommand($synchronizer))->register();
        }
    }
}
