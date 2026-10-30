<?php
// Run: ddev exec php -d zend.assertions=1 /usr/local/bin/wp-cli --path=wp eval-file tests/wp-check.php
// Checks inside WordPress. Everything it creates uses ids starting 'test-' (or titles starting 'Test ') and is deleted
// at the end, even on failure.

use ConstructiveMedia\IntranetPhonelist\Company;
use ConstructiveMedia\IntranetPhonelist\Division;
use ConstructiveMedia\IntranetPhonelist\Ingest\Ingestor;
use ConstructiveMedia\IntranetPhonelist\Ingest\Log;
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;
use ConstructiveMedia\IntranetPhonelist\Phonelist;
use ConstructiveMedia\IntranetPhonelist\Plugin;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;
use ConstructiveMedia\IntranetPhonelist\Upgrade;

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
    $tombstones = get_option('intranet_phonelist_deleted', []);
    update_option('intranet_phonelist_deleted', array_filter($tombstones, fn (string $key): bool => !str_contains($key, ':test-'), ARRAY_FILTER_USE_KEY), false);
    $jobsTable = $wpdb->prefix . 'iphl_jobs';
    foreach ($wpdb->get_col("SELECT id FROM $jobsTable WHERE idempotency_key LIKE 'test-%' OR payload LIKE '%\"test-%'") as $jobId) {
        $wpdb->query($wpdb->prepare("DELETE FROM $logTable WHERE run_id = %s", $jobId));
        $wpdb->delete($jobsTable, ['id' => $jobId]);
    }
    wp_cache_flush();
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
    $terminated = $in->put('staff', 'test-2', ['status' => 'inactive'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
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
    assert($in->put('staff', 'test-1', $jane + ['updated_at' => $t1], 'test-run', 'api')->status === 409);       // a deleted record stays deleted for older pushes
    assert($in->put('staff', 'test-1', $jane + ['updated_at' => $t2], 'test-run', 'api')->status === 409);       // ...and for one at the same time: a delete wins ties
    $in->delete('staff', 'test-gone', $t2, 'test-run', 'api');                                                       // never held: still remembered
    assert($in->put('staff', 'test-gone', $jane + ['updated_at' => $t1], 'test-run', 'api')->status === 409);
    $in->delete('staff', 'test-gone', $t0, 'test-run', 'api');                                                       // a late, older delete...
    assert($in->put('staff', 'test-gone', $jane + ['updated_at' => $t1], 'test-run', 'api')->status === 409);       // ...doesn't weaken the newer one
    $longId = 'test-' . str_repeat('x', 70);
    $in->apply(['op' => 'put', 'type' => 'staff', 'id' => $longId, 'data' => []], 'test-run', 'api');
    assert((int) $wpdb->get_var("SELECT COUNT(*) FROM $logTable WHERE source_id LIKE 'test-xxxx%'") === 1);         // an over-long id still gets its log row
    $in->put('staff', 'test-left', ['status' => 'inactive'] + $jane + ['updated_at' => $t2], 'test-run', 'api'); // a leaver we never held: remembered too
    assert($in->put('staff', 'test-left', $jane + ['updated_at' => $t1], 'test-run', 'api')->status === 409);
    $t3 = '2026-10-15T11:00:00+08:00';
    assert($in->put('staff', 'test-1', $jane + ['updated_at' => $t3], 'test-run', 'api')->result === 'created');  // ...but a newer one brings it back
    assert($in->apply(['op' => 'delete', 'type' => 'staff', 'id' => 'test-1', 'data' => []], 'test-run', 'api')->errors[0]['field'] === 'updated_at');
    assert($in->postId('staff', 'test-1') > 0);
    assert($in->apply(['op' => 'put', 'type' => 'nope', 'id' => 'test-x', 'data' => []], 'test-run', 'api')->status === 422);
    assert($in->apply(['op' => 'put', 'type' => 'staff', 'id' => 'test-3', 'data' => $jane + ['updated_at' => $t1]], 'test-run', 'api')->result === 'created');

    // Strings are stored exactly as sent, backslashes included; an id with one is found again rather than duplicated.
    assert($in->put('staff', 'test-\\slash', ['position' => 'Estimator\\Supervisor'] + $jane + ['updated_at' => $t1], 'test-run', 'api')->result === 'created');
    assert(get_post_meta($in->postId('staff', 'test-\\slash'), 'position', true) === 'Estimator\\Supervisor');
    assert($in->put('staff', 'test-\\slash', ['position' => 'Estimator\\Supervisor'] + $jane + ['updated_at' => $t1], 'test-run', 'api')->result === 'unchanged');

    // '&' in names is kept as '&', not escaped, whoever runs the write (API and cron run with no user).
    $user = get_current_user_id();
    wp_set_current_user(0);
    $in->put('divisions', 'test-amp', ['name' => 'Test Building & Construction', 'updated_at' => $t1], 'test-run', 'api');
    wp_set_current_user($user);
    assert(get_post_field('post_title', $in->postId('divisions', 'test-amp'), 'raw') === 'Test Building & Construction');
    assert($in->patch('divisions', 'test-amp', ['updated_at' => $t2], 'test-run', 'api')->result === 'unchanged');

    // Unreadable phone numbers are kept as sent and logged as a warning; an over-long label doesn't lose the log row.
    $in->put('staff', 'test-phone', ['mobile' => '0894647800 - Bunbury', 'last_name' => str_repeat('Long', 100)] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $phoneLog = $wpdb->get_row("SELECT level, context FROM $logTable WHERE source_id = 'test-phone' ORDER BY id DESC LIMIT 1");
    assert($phoneLog && $phoneLog->level === 'warning' && str_contains($phoneLog->context, 'mobile'));
    assert(in_array('position_abbr', array_keys(Schema::fields('staff')), true));

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
    $in->delete('staff', 'test-s2', $t2, 'test-run', 'api');
    assert($link($s1, 'manager') === 0);                                                      // deleted manager unlinked
    $in->delete('divisions', 'test-RD', $t2, 'test-run', 'api');
    assert($link($s1, 'division') === 0 && $link($s1, 'company') === 0);
    $in->put('divisions', 'test-RD', ['name' => 'Test Relations Division', 'updated_at' => $t3], 'test-run', 'api');
    assert($link($s1, 'division') === $in->postId('divisions', 'test-RD'));                   // re-added: staff waiting on it are linked
    update_post_meta($s1, 'division', 0);
    Plugin::relations()->relinkAll();
    assert($link($s1, 'division') === $in->postId('divisions', 'test-RD'));

    // Photos: from the folder on create only; after that only a new photo_url replaces it; a failed download doesn't fail the record.
    $folder = get_temp_dir() . 'test-photos-' . wp_generate_password(6, false);
    wp_mkdir_p($folder);
    $image = imagecreatetruecolor(1, 1);
    imagejpeg($image, "$folder/jane_test_test-pd.jpg");
    $photos = new Photos($folder);
    $withPhotos = new Ingestor($log, Plugin::relations(), $photos);
    $withPhotos->put('staff', 'test-p1', $jane + ['division_id' => 'test-PD', 'updated_at' => $t1], 'test-run', 'api');
    $p1 = $withPhotos->postId('staff', 'test-p1');
    assert(has_post_thumbnail($p1));
    wp_delete_attachment(get_post_thumbnail_id($p1), true);                                    // removed by hand
    assert($withPhotos->put('staff', 'test-p1', $jane + ['division_id' => 'test-PD', 'position' => 'New', 'updated_at' => $t2], 'test-run', 'api')->result === 'updated');
    assert(!has_post_thumbnail($p1));                                                           // not re-added
    $failed = $withPhotos->patch('staff', 'test-p1', ['photo_url' => 'https://127.0.0.1:1/x.jpg', 'updated_at' => $t2], 'test-run', 'api');
    assert($failed->result === 'updated' && !has_post_thumbnail($p1) && $failed->warnings[0]['field'] === 'photo_url');
    assert(is_string($photos->afterWrite($p1, ['photo_url' => 'https://127.0.0.1:1/x.jpg'], false)));
    $retried = $withPhotos->patch('staff', 'test-p1', ['updated_at' => $t2], 'test-run', 'api');                   // same data: the failed photo is tried again
    assert($retried->result === 'unchanged' && ($retried->warnings[0]['field'] ?? null) === 'photo_url');
    assert($photos->afterWrite($p1, ['photo_url' => null], false) === null);
    array_map('unlink', glob("$folder/*"));
    rmdir($folder);

    // Theme API reads the v2 fields: search, birthdays (hidden ones left out), on leave.
    $day = fn (string $modify): string => (new DateTimeImmutable('today', wp_timezone()))->modify($modify)->format('Y-m-d');
    $in->put('staff', 'test-t1', ['first_name' => 'Zelda', 'last_name' => 'Testerson', 'department_code' => 'T9', 'phone' => '0894647800', 'extension' => '1234',
        'date_of_birth' => (new DateTimeImmutable('tomorrow', wp_timezone()))->modify('-30 years')->format('Y-m-d'), 'on_leave_until' => $day('+20 days')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-t2', ['first_name' => 'Zora', 'last_name' => 'Testerson', 'hide_birthday' => true,
        'date_of_birth' => (new DateTimeImmutable('tomorrow', wp_timezone()))->modify('-30 years')->format('Y-m-d')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $found = Phonelist::staff(name: 'zelda test');
    assert(count($found) === 1 && $found[0]->departmentCode === 'T9' && $found[0]->phone === '+61894647800' && $found[0]->extension === '1234' && $found[0]->id === 'test-t1');
    assert($found[0]->onLeaveUntil?->format('Y-m-d') === $day('+20 days'));
    assert($found[0]->birthday?->format('Y-m-d') === $day('+1 day') && $found[0]->daysUntil === null);         // every staff() result has their birthday...
    assert(Phonelist::staff(name: 'zora test')[0]->birthday === null);                                         // ...unless hidden
    assert(count(Phonelist::staff(name: 'Testerson', department: 'T9')) === 1);
    $birthdays = array_map(fn ($person) => $person->id, Phonelist::birthdays(1));
    assert(in_array('test-t1', $birthdays, true) && !in_array('test-t2', $birthdays, true));

    // On leave: those with a return date still ahead, soonest back first.
    $in->put('staff', 'test-t3', ['first_name' => 'Zack', 'last_name' => 'Testerson', 'on_leave_until' => $day('+2 days')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-t4', ['first_name' => 'Zeb', 'last_name' => 'Testerson', 'on_leave_until' => $day('-2 days')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $away = array_values(array_filter(array_map(fn ($person) => $person->id, Phonelist::onLeave()), fn (string $id): bool => str_starts_with($id, 'test-t')));
    assert($away === ['test-t3', 'test-t1']);

    // Work anniversaries: whole years only, soonest first; nobody's first day counts.
    $in->put('staff', 'test-a1', ['first_name' => 'Ann', 'last_name' => 'Testerson', 'start_date' => (new DateTimeImmutable('tomorrow', wp_timezone()))->modify('-5 years')->format('Y-m-d')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-a2', ['first_name' => 'New', 'last_name' => 'Testerson', 'start_date' => $day('+1 day')] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $anniversaries = array_filter(Phonelist::anniversaries(1), fn ($person) => str_starts_with($person->id, 'test-a'));
    assert(count($anniversaries) === 1 && current($anniversaries)->id === 'test-a1' && current($anniversaries)->years === 5 && current($anniversaries)->daysUntil === 1);
    $ann = Phonelist::staff(name: 'ann testerson')[0];
    assert($ann->years === 5 && $ann->anniversary?->format('Y-m-d') === $day('+1 day') && $ann->startDate?->format('Y-m-d') === $day('+1 day -5 years'));
    assert(Phonelist::staff(name: 'new testerson')[0]->anniversary === null && Phonelist::staff(name: 'new testerson')[0]->startDate !== null); // not before a year

    // Default companies (Relations::COMPANIES, here via the filter): a division leaves the company made for it, which
    // goes; one set by hand stays put; a new division goes straight in.
    $in->put('divisions', 'test-MD', ['name' => 'Test Mapped Division', 'updated_at' => $t1], 'test-run', 'api');
    $in->put('divisions', 'test-HD', ['name' => 'Test Hand Division', 'updated_at' => $t1], 'test-run', 'api');
    [$md, $hd] = [$in->postId('divisions', 'test-MD'), $in->postId('divisions', 'test-HD')];
    $madeFor = $link($md, 'company');
    update_field('field_iphl_division_company', $other, $hd);
    $map = fn (string $name, string $id): string => in_array($id, ['test-MD', 'test-HD', 'test-ND'], true) ? 'Test Group' : $name;
    add_filter('intranet_phonelist_new_division_company', $map, 10, 2);
    Plugin::relations()->applyDefaultCompanies();
    $in->put('divisions', 'test-ND', ['name' => 'Test New Division', 'updated_at' => $t1], 'test-run', 'api');
    remove_filter('intranet_phonelist_new_division_company', $map);
    assert(get_post_field('post_title', $link($md, 'company'), 'raw') === 'Test Group' && get_post($madeFor) === null);
    assert($link($hd, 'company') === $other && get_post($other) !== null);
    assert($link($in->postId('divisions', 'test-ND'), 'company') === $link($md, 'company'));

    // Org chart: a manager's team and a person's manager, from reports_to.
    $in->put('staff', 'test-o1', ['first_name' => 'Boss', 'last_name' => 'Testerson'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-o2', ['first_name' => 'Ava', 'last_name' => 'Testerson', 'reports_to' => 'test-o1'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-o3', ['first_name' => 'Ben', 'last_name' => 'Testerson', 'reports_to' => 'test-o1'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    assert(array_map(fn ($person) => $person->id, Phonelist::team('test-o1')) === ['test-o2', 'test-o3']);
    assert(Phonelist::manager('test-o2')?->id === 'test-o1' && Phonelist::manager('test-o1') === null && Phonelist::team('test-nobody') === []);
    assert(Phonelist::staff(name: 'Ava Testerson')[0]->reportsTo === 'test-o1');
    $in->put('staff', 'test-o4', ['first_name' => 'Cee', 'last_name' => 'Testerson', 'reports_to' => 'test-o4'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    assert(Phonelist::manager('test-o4') === null && Phonelist::team('test-o4') === [] && Phonelist::team('') === []); // never their own manager

    // Divisions and companies, with their staff; a division carries its company, photo included.
    $in->put('divisions', 'test-LD', ['name' => 'Test Listing Division', 'phone' => '9464 7800', 'updated_at' => $t1], 'test-run', 'api');
    $in->put('staff', 'test-l1', ['first_name' => 'Lia', 'last_name' => 'Testerson', 'division_id' => 'test-LD'] + $jane + ['updated_at' => $t1], 'test-run', 'api');
    $logo = fn (int $postId): int => set_post_thumbnail($postId, wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Test logo'], 'test-logo.jpg')) ? get_post_thumbnail_id($postId) : 0;
    $listingCompany = $logo($link($in->postId('divisions', 'test-LD'), 'company'));
    $division = current(array_filter(Phonelist::divisions(), fn (Division $division): bool => $division->id === 'test-LD'));
    assert($division->name === 'Test Listing Division' && $division->phone === '+61894647800' && array_map(fn ($person) => $person->id, $division->staff) === ['test-l1']);
    assert($division->company->name === 'Test Listing Division' && $division->company->photoId === $listingCompany && str_ends_with((string) $division->company->photoUrl, 'test-logo.jpg'));
    assert($division->company->divisions === null && $division->company->staff === null);                // no loop back to the division
    $company = current(array_filter(Phonelist::companies(), fn (Company $company): bool => $company->name === 'Test Listing Division'));
    assert(array_column($company->divisions, 'id') === ['test-LD'] && $company->staff[0]->id === 'test-l1' && $company->photoId === $listingCompany);
    assert(str_contains(wp_json_encode($company), '"divisions":[{"id":"test-LD"'));                      // encodes, nested objects and all
    $in->put('companies', 'test-LC', ['name' => 'Test Listing Division', 'updated_at' => $t1], 'test-run', 'api'); // a second company with the same name
    $withDivision = array_filter(Phonelist::companies(), fn (Company $company): bool => in_array('test-LD', array_column($company->divisions, 'id'), true));
    assert(count($withDivision) === 1);                                                                    // the division belongs to one of them only
    $deletedLogo = $logo($in->postId('companies', 'test-LC'));
    $in->delete('companies', 'test-LC', $t2, 'test-run', 'api');
    assert(get_post($deletedLogo) !== null);                                                              // a company's photo outlives it (set by hand)
    wp_delete_attachment($deletedLogo, true);
    $noId = wp_insert_post(['post_type' => PostTypes::DIVISION, 'post_status' => 'publish', 'post_title' => 'Test No Id Division']);
    assert(current(array_filter(Phonelist::divisions(), fn (Division $division): bool => $division->postId === $noId))->staff === []); // not everyone without a division

    // Upgrade from v1: meta renamed in place (with ACF references), the position-based manager link becomes reports_to, idempotent.
    $v1 = fn (string $title, array $meta): int => wp_insert_post(['post_type' => PostTypes::STAFF, 'post_status' => 'publish', 'post_title' => $title, 'meta_input' => $meta]);
    $um = $v1('Test Manager', [PostTypes::SOURCE_ID => 'test-um', 'name_first' => 'Test', 'name_last_co' => 'Manager', 'e_status' => 'active']);
    $us = $v1('Test Upgraded', [PostTypes::SOURCE_ID => 'test-us', 'name_first' => 'Old', '_name_first' => 'field_iphl_staff_name_first', 'name_last_co' => 'Upgraded',
        'pref_name' => '', 'div_code' => 'test-UD', 'tag' => '20', 'ph_ext' => '+61894647800', 'dob' => '19850905', 'on_leave' => '', 'hide_birthday' => 'y',
        'reports_to' => [$um], '_reports_to' => 'field_iphl_staff_link_reports_to', 'reports_to_id' => 'P9', 'full_name' => 'OLD UPGRADED', 'id' => 'test-us', 'e_status' => 'active']);
    $ud = wp_insert_post(['post_type' => PostTypes::DIVISION, 'post_status' => 'publish', 'post_title' => 'Test Upgraded Division',
        'meta_input' => [PostTypes::SOURCE_ID => 'test-UD', 'div_code' => 'test-UD', 'div_name' => 'TEST', 'div_addr1' => '1 Test St', 'fax' => '1', 'dx_no' => '2']]);
    Upgrade::run();
    Upgrade::run();
    $version = get_option('intranet_phonelist_version');
    update_option('intranet_phonelist_version', '1');
    set_transient('intranet_phonelist_upgrading', 1, 60);
    assert(Upgrade::maybeRun($log) === false);                                                     // another request is already upgrading
    delete_transient('intranet_phonelist_upgrading');
    update_option('intranet_phonelist_version', $version);
    $m = fn (int $postId, string $key) => get_post_meta($postId, $key, true);
    assert($m($us, 'first_name') === 'Old' && $m($us, '_first_name') === 'field_iphl_staff_first_name' && !metadata_exists('post', $us, 'name_first'));
    assert($m($us, 'division_id') === 'test-UD' && $m($us, 'department_code') === '20' && $m($us, 'phone') === '+61894647800' && $m($us, 'date_of_birth') === '19850905');
    assert($m($us, 'hide_birthday') === '1' && $m($us, 'status') === 'active');
    assert((int) $m($us, 'manager') === $um && $m($us, 'reports_to') === 'test-um' && $m($us, '_manager') === PostTypes::linkKey('manager'));
    assert(!metadata_exists('post', $us, 'full_name') && !metadata_exists('post', $us, 'reports_to_id') && !metadata_exists('post', $us, 'id'));
    assert($m($us, PostTypes::UPDATED_AT) === Upgrade::EPOCH);
    assert($m($ud, 'address_line1') === '1 Test St' && !metadata_exists('post', $ud, 'fax') && !metadata_exists('post', $ud, 'div_code') && !metadata_exists('post', $ud, 'div_name'));
    assert($in->put('staff', 'test-us', $jane + ['updated_at' => $t0], 'test-run', 'api')->result === 'updated');   // the first push always applies

    // Jobs: a batch resolves out-of-order references, repeats by idempotency key, and a crashed job is resumed.
    $jobs = Plugin::jobs();
    $snapshot = function (array $records) use ($jobs): array {
        $job = $jobs->create('snapshot', 'companies', $records, null, 'api');
        $jobs->process($job['id']);
        return $jobs->get($job['id'])['results'];
    };
    $job = $jobs->create('batch', null, [
        ['op' => 'put', 'type' => 'staff', 'id' => 'test-b1', 'data' => $jane + ['division_id' => 'test-BD', 'updated_at' => $t1]],
        ['op' => 'put', 'type' => 'divisions', 'id' => 'test-BD', 'data' => ['name' => 'Test Batch Division', 'updated_at' => $t1]],
        ['op' => 'put', 'type' => 'staff', 'data' => $jane + ['updated_at' => $t1]],                                  // no id
    ], 'test-key-1', 'api');
    assert(!$job['existing'] && $jobs->create('batch', null, [], 'test-key-1', 'api')['id'] === $job['id']);
    $jobs->sweep();
    $done = $jobs->get($job['id']);
    assert($done['status'] === 'done' && $done['total'] === 3 && $done['done'] === 3);
    assert(array_column($done['results'], 'result') === ['created', 'created', 'invalid']);
    assert($link($in->postId('staff', 'test-b1'), 'division') === $in->postId('divisions', 'test-BD'));       // staff sent before their division

    $job2 = $jobs->create('batch', null, [['op' => 'put', 'type' => 'staff', 'id' => 'test-b2', 'data' => $jane + ['updated_at' => $t1]]], null, 'api');
    $wpdb->update($wpdb->prefix . 'iphl_jobs', ['status' => 'running', 'heartbeat' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $job2['id']]);
    $jobs->sweep();
    assert($jobs->get($job2['id'])['status'] === 'done' && $in->postId('staff', 'test-b2') > 0);

    // A specific job can be run on demand, and a failed one retried.
    $job3 = $jobs->create('batch', null, [['op' => 'put', 'type' => 'staff', 'id' => 'test-b3', 'data' => $jane + ['updated_at' => $t1]]], null, 'api');
    $wpdb->update($wpdb->prefix . 'iphl_jobs', ['status' => 'failed', 'attempts' => 3], ['id' => $job3['id']]);
    assert($jobs->process($job3['id']) === true && $jobs->get($job3['id'])['status'] === 'done' && $in->postId('staff', 'test-b3') > 0);
    assert($jobs->process($job3['id']) === false);                                                 // done jobs aren't re-run
    $job4 = $jobs->create('batch', null, [['op' => 'put', 'type' => 'staff', 'id' => 'test-b4', 'data' => $jane + ['updated_at' => $t1]]], null, 'api');
    $wpdb->update($wpdb->prefix . 'iphl_jobs', ['attempts' => 3], ['id' => $job4['id']]);
    $jobs->sweep();                                                                                  // gives up: 4th attempt
    $gaveUp = $jobs->get($job4['id']);
    assert($gaveUp['status'] === 'failed' && $gaveUp['finished_at'] !== null && str_ends_with($gaveUp['created_at'], 'Z'));

    // A job that can't be stored is an error, never a silent acknowledgement.
    $refused = false;
    try {
        $jobs->create(str_repeat('x', 50), null, [['op' => 'put']], null, 'api');
    } catch (RuntimeException) {
        $refused = true;
    }
    assert($refused);

    // Snapshot of one type deletes that type's records (with a Data Hub ID) missing from it. Companies here, so no real
    // records are at risk: locally only test companies have a Data Hub ID.
    $realWithIds = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value NOT LIKE 'test-%%'", PostTypes::COMPANY, PostTypes::SOURCE_ID));
    if ($realWithIds === 0) {
        $in->put('companies', 'test-C1', ['name' => 'Test Company One', 'updated_at' => $t1], 'test-run', 'api');
        $in->put('companies', 'test-C2', ['name' => 'Test Company Two', 'updated_at' => $t1], 'test-run', 'api');
        $ours = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", PostTypes::COMPANY)) - 2;
        $results = $snapshot([['id' => 'test-C1', 'name' => 'Test Company One', 'updated_at' => $t2]]);
        assert(array_column($results, 'result') === ['unchanged', 'deleted']);
        assert($in->postId('companies', 'test-C1') > 0 && $in->postId('companies', 'test-C2') === 0);
        assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", PostTypes::COMPANY)) - 1 === (int) $ours); // ours untouched

        // A snapshot with unusable ids (numbers here) deletes nothing.
        $results = $snapshot([['id' => 7, 'name' => 'Test Seven', 'updated_at' => $t2]]);
        assert($in->postId('companies', 'test-C1') > 0 && !in_array('deleted', array_column($results, 'result'), true));
        assert(end($results)['result'] === 'error');

        // A snapshot that would delete more than 10 records and 10% of those held deletes nothing.
        for ($i = 2; $i <= 13; $i++) {
            $in->put('companies', "test-C$i", ['name' => "Test Company $i", 'updated_at' => $t1], 'test-run', 'api');
        }
        $results = $snapshot([['id' => 'test-C1', 'name' => 'Test Company One', 'updated_at' => $t2]]);
        assert($in->postId('companies', 'test-C13') > 0 && end($results)['result'] === 'error');
    } else {
        echo "Skipped the snapshot deletion check: this site has companies with Data Hub IDs.\n";
    }
} finally {
    $cleanUp();
}

echo "wp-check OK\n";
