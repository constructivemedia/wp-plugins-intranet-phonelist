<?php
/**
 * Plugin Name: Intranet Phonelist
 * Description: Syncs staff and divisions from Oracle into WordPress.
 * Version:     0.3.0
 * Author:      Constructive Media
 * Requires PHP: 8.1
 * Requires Plugins: advanced-custom-fields
 * Text Domain: intranet-phonelist
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

ConstructiveMedia\IntranetPhonelist\Plugin::boot(__FILE__);
