<?php
/**
 * Plugin Name: Intranet Phonelist
 * Description: Syncs staff and divisions from Oracle into WordPress.
 * Version:     2.0.0
 * Author:      Constructive Media
 * Requires PHP: 8.1
 * Requires Plugins: advanced-custom-fields
 * Text Domain: intranet-phonelist
 */

defined('ABSPATH') || exit;

// Dependencies (libphonenumber): installed via Composer, the site's autoloader has them; a git or zip install
// with `composer install --no-dev` run in this folder has its own vendor/. Without either, phones are stored as-is.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// The plugin's own classes, however it was installed.
spl_autoload_register(function (string $class): void {
    $prefix = 'ConstructiveMedia\\IntranetPhonelist\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

ConstructiveMedia\IntranetPhonelist\Plugin::boot(__FILE__);
