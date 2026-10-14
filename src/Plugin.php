<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Admin\Relationships;
use ConstructiveMedia\IntranetPhonelist\Admin\SettingsPage;
use ConstructiveMedia\IntranetPhonelist\Api\StaffController;
use ConstructiveMedia\IntranetPhonelist\Cli\SyncCommand;
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
        $synchronizer = new Synchronizer(OracleSource::fromConstants(), Photos::fromConstants(), $log, $wpdb);
        $postTypes = new PostTypes();

        add_action('init', [$postTypes, 'register']);
        add_action('admin_menu', [$postTypes, 'registerMenu'], 9);
        add_action('acf/include_fields', [$postTypes, 'registerFields']);
        (new StaffController())->register();
        register_activation_hook($pluginFile, [$log, 'install']);

        if (is_admin()) {
            (new SettingsPage($synchronizer, $log))->register();
            (new Relationships())->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            (new SyncCommand($synchronizer))->register();
        }
    }
}
