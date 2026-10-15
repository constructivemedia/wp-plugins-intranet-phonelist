<?php
// Run: ddev exec php -d zend.assertions=1 /usr/local/bin/wp-cli --path=wp eval-file tests/wp-check.php
// Checks inside WordPress. Everything it creates uses ids starting 'test-' (or titles starting 'Test ') and is deleted
// at the end, even on failure.

use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Plugin;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;

defined('ABSPATH') || exit;

global $wpdb;
$logTable = $wpdb->prefix . 'intranet_phonelist_log';

$cleanUp = function () use ($wpdb, $logTable): void {
    $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE 'test-%%'", PostTypes::SOURCE_ID));
    $ids = [...$ids, ...$wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_type IN ('iphl_company', 'iphl_division', 'iphl_staff') AND post_title LIKE 'Test %'")];
    foreach (array_unique($ids) as $id) {
        if ($thumbnail = get_post_thumbnail_id($id)) {
            wp_delete_attachment($thumbnail, true);
        }
        wp_delete_post((int) $id, true);
    }
    $wpdb->query("DELETE FROM $logTable WHERE run_id LIKE 'test-%' OR source_id LIKE 'test-%'");
};

$cleanUp();
try {
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
    assert(Log::days() === 90 || defined('INTRANET_PHONELIST_LOG_DAYS'));
    $log = new Log($wpdb);
    $log->write('test-run', 'manual', 'debug', 'test_old');
    $wpdb->query("UPDATE $logTable SET created_at = '2000-01-01 00:00:00' WHERE run_id = 'test-run'");
    $log->write('test-run', 'manual', 'debug', 'test_new');
    assert($log->prune() >= 1);
    assert($wpdb->get_col("SELECT action FROM $logTable WHERE run_id = 'test-run'") === ['test_new']);

    // Ingestor: create, unchanged, same time with new data, stale, patch, status rule, delete.
    $in = Plugin::ingestor();
    $t0 = '2026-10-15T08:00:00+08:00'; $t1 = '2026-10-15T09:00:00+08:00'; $t2 = '2026-10-15T10:00:00+08:00';
    $jane = ['first_name' => 'Jane', 'last_name' => 'Test', 'status' => 'active', 'email' => 'jane@example.com'];
    assert($in->put('staff', 'test-1', $jane + ['updated_at' => $t1, 'date_of_birth' => '1985-09-05'], 'test-run', 'api')->result === 'created');
    assert($in->put('staff', 'test-1', $jane + ['updated_at' => $t1, 'date_of_birth' => '1985-09-05'], 'test-run', 'api')->result === 'unchanged');
    assert($in->put('staff', 'test-1', ['email' => 'j@example.com'] + $jane + ['updated_at' => $t1, 'date_of_birth' => '1985-09-06'], 'test-run', 'api')->result === 'updated');
    $stale = $in->put('staff', 'test-1', $jane + ['updated_at' => $t0], 'test-run', 'api');
    assert($stale->result === 'stale' && $stale->status === 409);
    $id = $in->postId('staff', 'test-1');
    assert(get_post_field('post_title', $id, 'raw') === 'Jane Test' && get_post_meta($id, 'email', true) === 'j@example.com');
    assert(get_post_meta($id, 'date_of_birth', true) === '19850906' && get_post_meta($id, PostTypes::UPDATED_AT, true) === $t1);
    assert($in->patch('staff', 'test-1', ['updated_at' => $t2, 'preferred_name' => 'Janey'], 'test-run', 'api')->result === 'updated');
    assert(get_post_meta($id, 'email', true) === 'j@example.com' && get_post_field('post_title', $id, 'raw') === 'Janey Test');
    assert($in->patch('staff', 'test-404', ['updated_at' => $t2], 'test-run', 'api')->status === 404);
    assert($in->put('staff', 'test-1', ['id' => 'other'] + $jane + ['updated_at' => $t2], 'test-run', 'api')->status === 422);
    assert($in->put('staff', 'test-1', ['last_name' => ''] + $jane + ['updated_at' => $t2], 'test-run', 'api')->errors[0]['field'] === 'last_name');
    $terminated = $in->put('staff', 'test-2', ['status' => 'terminated'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    assert($terminated->result === 'deleted' && $terminated->status === 200 && $in->postId('staff', 'test-2') === 0);
    assert($in->put('divisions', 'test-D', ['name' => 'Test Division', 'updated_at' => $t1], 'test-run', 'api')->status === 201);
    assert(get_post_field('post_title', $in->postId('divisions', 'test-D'), 'raw') === 'Test Division');

    // One log row per outcome; private values never logged.
    $rows = $wpdb->get_results("SELECT action, context FROM $logTable WHERE source_id = 'test-1' ORDER BY id");
    assert(array_column($rows, 'action') === ['created', 'unchanged', 'updated', 'stale', 'updated', 'invalid', 'invalid']);
    assert(!str_contains(implode(' ', array_column($rows, 'context')), '1985'));

    assert($in->delete('staff', 'test-1', $t0, 'test-run', 'api')->status === 409);
    $deleted = $in->delete('staff', 'test-1', $t2, 'test-run', 'api');
    assert($deleted->result === 'deleted' && $deleted->status === 204 && $in->postId('staff', 'test-1') === 0);
    assert($in->delete('staff', 'test-1', $t2, 'test-run', 'api')->status === 204);
    assert($in->apply(['op' => 'put', 'type' => 'nope', 'id' => 'test-x', 'data' => []], 'test-run', 'api')->status === 422);
    assert($in->apply(['op' => 'put', 'type' => 'staff', 'id' => 'test-3', 'data' => $jane + ['updated_at' => $t1]], 'test-run', 'api')->result === 'created');
} finally {
    $cleanUp();
}

echo "wp-check OK\n";
