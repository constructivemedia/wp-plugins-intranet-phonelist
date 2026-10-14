<?php
/**
 * Plugin Name: Intranet Phonelist
 * Description: Syncs staff and divisions from Oracle into WordPress.
 * Version:     1.6.1
 * Author:      Constructive Media
 * Requires PHP: 8.1
 * Requires Plugins: advanced-custom-fields
 * Text Domain: intranet-phonelist
 */

defined('ABSPATH') || exit;

// No runtime dependencies, so no vendor/ needed: works installed via Composer, git or zip.
spl_autoload_register(function (string $class): void {
    $prefix = 'ConstructiveMedia\\IntranetPhonelist\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

ConstructiveMedia\IntranetPhonelist\Plugin::boot(__FILE__);
