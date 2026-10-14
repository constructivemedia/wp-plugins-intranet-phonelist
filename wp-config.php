<?php
/**
 * Local dev (DDEV) config. WordPress finds this one level above wp/.
 * Values come from .env (gitignored) when set, otherwise the DDEV defaults below.
 */

// Dev only: never runs on a site where the plugin is installed.
getenv('IS_DDEV_PROJECT') === 'true' || exit;

require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable( __DIR__ )->safeLoad();

$env  = fn( string $key, $default = null ) => $_ENV[ $key ] ?? $default;
$bool = fn( string $key, bool $default ) => filter_var( $env( $key, $default ), FILTER_VALIDATE_BOOLEAN );

// Database.
define( 'DB_NAME', $env( 'DB_NAME', 'db' ) );
define( 'DB_USER', $env( 'DB_USER', 'db' ) );
define( 'DB_PASSWORD', $env( 'DB_PASSWORD', 'db' ) );
define( 'DB_HOST', $env( 'DB_HOST', 'db' ) );
define( 'DB_CHARSET', $env( 'DB_CHARSET', 'utf8mb4' ) );
define( 'DB_COLLATE', $env( 'DB_COLLATE', '' ) );
$table_prefix = $env( 'DB_TABLE_PREFIX', 'wp_' );

// Site.
define( 'WP_HOME', $env( 'WP_HOME', getenv( 'DDEV_PRIMARY_URL' ) ) );
define( 'WP_SITEURL', $env( 'WP_SITEURL', WP_HOME ) );
define( 'WP_ENVIRONMENT_TYPE', $env( 'WP_ENVIRONMENT_TYPE', 'local' ) );

// Debug.
define( 'WP_DEBUG', $bool( 'WP_DEBUG', true ) );
define( 'WP_DEBUG_LOG', $bool( 'WP_DEBUG_LOG', true ) );
define( 'WP_DEBUG_DISPLAY', $bool( 'WP_DEBUG_DISPLAY', false ) );

// Salts: optional, WordPress generates and stores its own when unset.
foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $salt ) {
	if ( $env( $salt ) ) {
		define( $salt, $env( $salt ) );
	}
}

// Intranet Phonelist: Oracle (read-only account).
define( 'INTRANET_PHONELIST_ORACLE_DSN', $env( 'INTRANET_PHONELIST_ORACLE_DSN', '' ) );
define( 'INTRANET_PHONELIST_ORACLE_USER', $env( 'INTRANET_PHONELIST_ORACLE_USER', '' ) );
define( 'INTRANET_PHONELIST_ORACLE_PASSWORD', $env( 'INTRANET_PHONELIST_ORACLE_PASSWORD', '' ) );

// Intranet Phonelist: folder of staff photos named first_last_divcode.jpg (gitignored).
define( 'INTRANET_PHONELIST_PHOTOS_DIR', $env( 'INTRANET_PHONELIST_PHOTOS_DIR', __DIR__ . '/assets/staffPhotos' ) );

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/wp/' );
require_once ABSPATH . 'wp-settings.php';
