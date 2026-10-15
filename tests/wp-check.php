<?php
// Run: ddev exec php -d zend.assertions=1 /usr/local/bin/wp-cli --path=wp eval-file tests/wp-check.php
// Checks inside WordPress. Everything it creates uses ids starting 'test-' and is deleted at the end, even on failure.

use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;

defined('ABSPATH') || exit;

// ACF fields come from Schema; the v1 Oracle-named fields are gone.
$names = fn (string $group): array => array_column(acf_get_fields($group) ?: [], 'name');
$staffFields = $names('group_iphl_staff');
foreach (Schema::fields('staff') as $field => $config) {
    assert(in_array($field, $staffFields, true), "staff field $field");
}
foreach (['address_line1', 'postal_address'] as $field) {
    assert(in_array($field, $names('group_iphl_division'), true), "division field $field");
}
assert(!in_array('name', $names('group_iphl_division'), true));                              // the title, not a field
assert(acf_get_field('field_iphl_staff_link_manager')['type'] === 'post_object');
assert(!acf_get_field('field_iphl_staff_name_first') && !acf_get_field('field_iphl_staff_link_reports_to'));
assert(acf_get_field('field_iphl_staff_date_of_birth')['type'] === 'date_picker');
assert(acf_get_field('field_iphl_staff_hide_birthday')['type'] === 'select');

// Log retention: configurable, and pruning removes only expired rows.
global $wpdb;
assert(Log::days() === 90 || defined('INTRANET_PHONELIST_LOG_DAYS'));
$log = new Log($wpdb);
$table = $wpdb->prefix . 'intranet_phonelist_log';
try {
    $log->write('test-run', 'manual', 'debug', 'test_old');
    $wpdb->query("UPDATE $table SET created_at = '2000-01-01 00:00:00' WHERE run_id = 'test-run'");
    $log->write('test-run', 'manual', 'debug', 'test_new');
    assert($log->prune() >= 1);
    assert($wpdb->get_col("SELECT action FROM $table WHERE run_id = 'test-run'") === ['test_new']);
} finally {
    $wpdb->query("DELETE FROM $table WHERE run_id = 'test-run'");
}

echo "wp-check OK\n";
