<?php
// Run: ddev exec php -d zend.assertions=1 /usr/local/bin/wp-cli --path=wp eval-file tests/wp-check.php
// Checks inside WordPress. Everything it creates uses ids starting 'test-' (or titles starting 'Test ') and is deleted
// at the end, even on failure.

use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;
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
    $wpdb->query("DELETE FROM $logTable WHERE run_id LIKE 'test-%' OR source_id LIKE 'test-%' OR label LIKE 'Test %'");
    if (method_exists(Plugin::class, 'jobs')) {
        $jobsTable = $wpdb->prefix . 'iphl_jobs';
        $testJobs = $wpdb->get_col("SELECT id FROM $jobsTable WHERE idempotency_key LIKE 'test-%' OR payload LIKE '%\"test-%'");
        foreach ($testJobs as $jobId) {
            $wpdb->query($wpdb->prepare("DELETE FROM $logTable WHERE run_id = %s", $jobId));
            $wpdb->delete($jobsTable, ['id' => $jobId]);
        }
    }
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

    // Relations: linked on write, in any order; a new division gets a company named after it; a division save re-points its staff.
    $link = fn (int $postId, string $name): int => (int) get_post_meta($postId, $name, true);
    assert($in->put('staff', 'test-s1', $jane + ['division_id' => 'test-RD', 'reports_to' => 'test-s2', 'updated_at' => $t1], 'test-run', 'api')->result === 'created');
    $s1 = $in->postId('staff', 'test-s1');
    assert($link($s1, 'division') === 0 && $link($s1, 'manager') === 0);                       // neither exists yet
    $in->put('divisions', 'test-RD', ['name' => 'Test Relations Division', 'updated_at' => $t1], 'test-run', 'api');
    $d = $in->postId('divisions', 'test-RD');
    $c = $link($d, 'company');
    assert(get_post_field('post_title', $c, 'raw') === 'Test Relations Division');            // company named after it
    assert($link($s1, 'division') === $d && $link($s1, 'company') === $c);
    $in->put('staff', 'test-s2', ['first_name' => 'Boss'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    assert($link($s1, 'manager') === $in->postId('staff', 'test-s2'));                         // manager arrived later
    $in->put('staff', 'test-s3', $jane + ['reports_to' => 'test-s3', 'updated_at' => $t1], 'test-run', 'api');
    assert($link($in->postId('staff', 'test-s3'), 'manager') === 0);                           // never their own manager
    $other = wp_insert_post(['post_type' => PostTypes::COMPANY, 'post_status' => 'publish', 'post_title' => 'Test Other Co']);
    update_field('field_iphl_division_company', $other, $d);
    Plugin::relations()->refreshDivision($d);
    assert($link($s1, 'company') === $other);
    $in->put('divisions', 'test-RD2', ['name' => 'Test Relations Division', 'updated_at' => $t1], 'test-run', 'api');
    assert($link($in->postId('divisions', 'test-RD2'), 'company') === $c);                     // reuses by name, no duplicate
    $in->delete('staff', 'test-s2', null, 'test-run', 'api');
    assert($link($s1, 'manager') === 0);                                                      // deleted manager unlinked
    $in->delete('divisions', 'test-RD', null, 'test-run', 'api');
    assert($link($s1, 'division') === 0 && $link($s1, 'company') === 0);
    $in->put('divisions', 'test-RD', ['name' => 'Test Relations Division', 'updated_at' => $t2], 'test-run', 'api');
    assert($link($s1, 'division') === $in->postId('divisions', 'test-RD'));                   // re-added: staff waiting on it are linked
    update_post_meta($s1, 'division', 0);
    Plugin::relations()->relinkAll();
    assert($link($s1, 'division') === $in->postId('divisions', 'test-RD'));

    // Photos: from the folder on create only; after that only a new photo_url replaces it; a failed download doesn't fail the record.
    $folder = get_temp_dir() . 'test-photos-' . wp_generate_password(6, false);
    wp_mkdir_p($folder);
    $image = imagecreatetruecolor(1, 1);
    imagejpeg($image, "$folder/jane_test_test-pd.jpg");
    $photos = new Photos($folder, $log);
    $withPhotos = new Ingestor($log, $wpdb, Plugin::relations(), $photos);
    $withPhotos->put('staff', 'test-p1', $jane + ['division_id' => 'test-PD', 'updated_at' => $t1], 'test-run', 'api');
    $p1 = $withPhotos->postId('staff', 'test-p1');
    assert(has_post_thumbnail($p1));
    wp_delete_attachment(get_post_thumbnail_id($p1), true);                                    // removed by hand
    assert($withPhotos->put('staff', 'test-p1', $jane + ['division_id' => 'test-PD', 'position' => 'New', 'updated_at' => $t2], 'test-run', 'api')->result === 'updated');
    assert(!has_post_thumbnail($p1));                                                           // not re-added
    $failed = $withPhotos->patch('staff', 'test-p1', ['photo_url' => 'https://127.0.0.1:1/x.jpg', 'updated_at' => $t2], 'test-run', 'api');
    assert($failed->result === 'updated' && !has_post_thumbnail($p1));
    assert($photos->afterWrite($p1, ['photo_url' => 'https://127.0.0.1:1/x.jpg'], false) === 'failed');
    assert($photos->afterWrite($p1, ['photo_url' => null], false) === null);
    array_map('unlink', glob("$folder/*"));
    rmdir($folder);

    // Jobs: a batch resolves out-of-order references, repeats by idempotency key, and a crashed job is resumed.
    $jobs = Plugin::jobs();
    $job = $jobs->create('batch', null, [
        ['op' => 'put', 'type' => 'staff', 'id' => 'test-b1', 'data' => $jane + ['division_id' => 'test-BD', 'updated_at' => $t1]],
        ['op' => 'put', 'type' => 'divisions', 'id' => 'test-BD', 'data' => ['name' => 'Test Batch Division', 'updated_at' => $t1]],
        ['op' => 'put', 'type' => 'staff', 'data' => $jane + ['updated_at' => $t1]],                                  // no id
    ], 'test-key-1', 'api');
    assert(!$job['existing'] && $jobs->create('batch', null, [], 'test-key-1', 'api')['id'] === $job['id']);
    $jobs->process($job['id']);
    $done = $jobs->get($job['id']);
    assert($done['status'] === 'done' && $done['total'] === 3 && $done['done'] === 3);
    assert(array_column($done['results'], 'result') === ['created', 'created', 'invalid']);
    assert($link($in->postId('staff', 'test-b1'), 'division') === $in->postId('divisions', 'test-BD'));       // Review Focus 2

    $job2 = $jobs->create('batch', null, [['op' => 'put', 'type' => 'staff', 'id' => 'test-b2', 'data' => $jane + ['updated_at' => $t1]]], null, 'api');
    $wpdb->update($wpdb->prefix . 'iphl_jobs', ['status' => 'running', 'heartbeat' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $job2['id']]);
    $jobs->sweep();
    assert($jobs->get($job2['id'])['status'] === 'done' && $in->postId('staff', 'test-b2') > 0);

    // Snapshot of one type deletes that type's records (with a Data Hub ID) missing from it. Companies here, so no real
    // records are at risk: locally only test companies have a Data Hub ID.
    $realWithIds = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value NOT LIKE 'test-%%'", PostTypes::COMPANY, PostTypes::SOURCE_ID));
    if ($realWithIds === 0) {
        $in->put('companies', 'test-C1', ['name' => 'Test Company One', 'updated_at' => $t1], 'test-run', 'api');
        $in->put('companies', 'test-C2', ['name' => 'Test Company Two', 'updated_at' => $t1], 'test-run', 'api');
        $ours = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", PostTypes::COMPANY)) - 2;
        $results = $jobs->applySnapshot('companies', [['id' => 'test-C1', 'data' => ['name' => 'Test Company One', 'updated_at' => $t2]]], 'test-run', 'api');
        assert(array_column($results, 'result') === ['unchanged', 'deleted']);
        assert($in->postId('companies', 'test-C1') > 0 && $in->postId('companies', 'test-C2') === 0);
        assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", PostTypes::COMPANY)) - 1 === (int) $ours); // ours untouched
    } else {
        echo "Skipped the snapshot deletion check: this site has companies with Data Hub IDs.\n";
    }
} finally {
    $cleanUp();
}

echo "wp-check OK\n";
