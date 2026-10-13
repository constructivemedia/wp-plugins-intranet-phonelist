<?php
// Run: ddev exec php -d zend.assertions=1 tests/plan-check.php

PHP_SAPI === 'cli' || exit;

require __DIR__ . '/../vendor/autoload.php';

use ConstructiveMedia\IntranetPhonelist\Phonelist;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Staff;
use ConstructiveMedia\IntranetPhonelist\Sync\Planner;

$row = fn ($id, $ext = '100', $name = 'A') => ['ID' => $id, 'PH_EXT' => $ext, 'FULL_NAME' => $name];
$hash = fn ($r) => Planner::hash($r);
$post = fn ($postId, $id, $h = 'x') => ['post_id' => $postId, 'source_id' => $id, 'hash' => $h];
$ids = fn ($items) => array_map(fn ($i) => $i['row']['ID'] ?? $i['source_id'], array_values($items));

// Full sync.
$existing = [
    '1' => $post(11, '1', $hash($row('1'))), // unchanged
    '2' => $post(12, '2', 'stale'),          // changed
    '3' => $post(13, '3'),                   // gone from Oracle
    '4' => $post(14, '4'),                   // now LEFT
];
$plan = Planner::plan([$row('1'), $row('2', '200'), $row('4', 'LEFT'), $row('5'), $row('6', 'LEFT')], $existing, 'ID');
assert($ids($plan['create']) === ['5']);
assert($ids($plan['update']) === ['2'] && array_values($plan['update'])[0]['post_id'] === 12);
assert($ids($plan['delete']) === ['3', '4']);
assert(Planner::plan([], [], 'ID') === ['create' => [], 'update' => [], 'delete' => []]);

// IDs are opaque strings: '00001', '1' and a UUID are all distinct and come back unchanged.
$uuid = '0b7c6a3e-2f1d-4c5b-9a8e-7d6c5b4a3f21';
$plan = Planner::plan([$row('00001'), $row('1'), $row($uuid)], ['00001' => $post(21, '00001', $hash($row('00001')))], 'ID');
assert($ids($plan['create']) === ['1', $uuid]);
assert($plan['update'] === [] && $plan['delete'] === []);

// Single sync: existing is scoped to that one ID, so only they can be deleted.
assert($ids(Planner::plan([], ['00001' => $post(21, '00001')], 'ID')['delete']) === ['00001']);
assert($ids(Planner::plan([$row('00001', 'LEFT')], ['00001' => $post(21, '00001')], 'ID')['delete']) === ['00001']);

// Only divisions with at least one current staff member; LEFT staff don't count; exact code match.
$divisions = [['DIV_CODE' => 'JWH'], ['DIV_CODE' => 'OLD'], ['DIV_CODE' => 'GONE'], ['DIV_CODE' => '000']];
$staff = [['DIV_CODE' => 'JWH', 'PH_EXT' => '1'], ['DIV_CODE' => 'GONE', 'PH_EXT' => 'LEFT'], ['DIV_CODE' => '0', 'PH_EXT' => '2']];
assert(array_column(Planner::staffedDivisions($divisions, $staff), 'DIV_CODE') === ['JWH']);

// Fingerprint ignores column order.
assert(Planner::hash(['A' => '1', 'B' => '2']) === Planner::hash(['B' => '2', 'A' => '1']));
assert(Planner::hash(['A' => '1']) !== Planner::hash(['A' => '2']));

// Editor layout covers every synced column exactly once; each row's widths add up to 100.
$expected = ['division' => 9, 'staff' => 28];
foreach (PostTypes::synced() as $type => $config) {
    assert(count($config['columns']) === $expected[$type] && count(array_unique($config['columns'])) === $expected[$type]);
    foreach ($config['fields'] as $tab => $columns) {
        $row = 0;
        foreach ($columns as [, $width]) {
            $row += $width;
            $row = $row === 100 ? 0 : $row;
            assert($row < 100, "$type/$tab widths overflow a row");
        }
        assert($row === 0, "$type/$tab has an unfinished row");
    }
}

// Division titles: title case, tidy brackets, known acronyms kept.
foreach ([
    'ACTIVE PLUMBING - METRO' => 'Active Plumbing - Metro',
    'ACTIVE PLUMBING - GSH HOUSING' => 'Active Plumbing - GSH Housing',
    'W.A. COUNTRY BUILDERS ( BUNBURY)' => 'W.A. Country Builders (Bunbury)',
    'JWH GROUP PTY LTD' => 'JWH Group Pty Ltd',
    'JWH IT' => 'JWH IT',
    'ROOFSTYLE' => 'Roofstyle',
    'ITEM ITALIA' => 'Item Italia',
] as $raw => $pretty) {
    assert(PostTypes::prettyName($raw) === $pretty, "$raw → " . PostTypes::prettyName($raw));
}

// Staff title: preferred name, else first name, then surname.
$title = PostTypes::synced()['staff']['title'];
assert($title(['PREF_NAME' => 'Janey', 'NAME_FIRST' => 'Jane', 'NAME_LAST_CO' => 'Citizen']) === 'Janey Citizen');
assert($title(['PREF_NAME' => ' ', 'NAME_FIRST' => 'Jane', 'NAME_LAST_CO' => 'Citizen']) === 'Jane Citizen');

// Oracle dates → ACF Ymd, once at sync. Birth dates never land in the future; other dates keep PHP's 1970–2069 window.
$dates = ['DOB' => PostTypes::BIRTH_DATE, 'START_DATE' => PostTypes::DATE];
$normalize = fn ($dob, $start = '') => Planner::normalize(['DOB' => $dob, 'START_DATE' => $start, 'ID' => '1'], $dates, new DateTimeImmutable('2026-10-13'));
assert($normalize('05Sep85')['DOB'] === '19850905');
assert($normalize('05sep85')['DOB'] === '19850905');                 // month case varies in Oracle
assert($normalize('15Jun65')['DOB'] === '19650615');                 // PHP alone would say 2065
assert($normalize('15Jun05')['DOB'] === '20050615');
assert($normalize('13Oct26')['DOB'] === '20261013');                 // today is not the future
assert($normalize('14Oct26')['DOB'] === '19261014');
assert($normalize('29Feb88')['DOB'] === '19880229');
assert($normalize('31Feb85')['DOB'] === '' && $normalize('')['DOB'] === '' && $normalize('garbage')['DOB'] === '');
assert($normalize('05Sep85', '01Apr27')['START_DATE'] === '20270401'); // future start dates stay future
assert($normalize('05Sep85', '01dec25')['START_DATE'] === '20251201');
assert($normalize('05Sep85')['ID'] === '1');                          // other columns untouched
assert($normalize('05Sep85', '20261020')['START_DATE'] === '20261020'); // real Oracle DATE columns arrive as Ymd already
assert($normalize('19650615')['DOB'] === '19650615');                 // full year: no century guessing
assert($normalize('05Sep85', '20260231')['START_DATE'] === '');        // impossible Ymd

// Next birthday from stored Ymd: year of birth ignored, wraps into next year, 29 Feb → 28 Feb in non-leap years.
$next = fn ($dob, $today) => Phonelist::nextBirthday($dob, new DateTimeImmutable($today))?->format('Y-m-d');
assert($next('19851013', '2026-10-13') === '2026-10-13'); // today
assert($next('19851014', '2026-10-13') === '2026-10-14');
assert($next('19851012', '2026-10-13') === '2027-10-12'); // passed → next year
assert($next('19990102', '2026-12-30') === '2027-01-02'); // across year end
assert($next('19880229', '2026-10-13') === '2027-02-28'); // 2027 not leap
assert($next('19880229', '2027-10-13') === '2028-02-29'); // 2028 leap
assert($next('', '2026-10-13') === null);

// Staff filters: any word of the full name starts with (case-insensitive), exact division and tag codes, AND-combined.
$staffMember = fn (string $first, string $last, string $divisionCode = 'PHM', string $tag = '') => new Staff(
    id: '1', postId: 1, name: "$first $last", firstName: $first, lastName: $last, position: '', email: '', mobile: '',
    extension: '', divisionCode: $divisionCode, division: '', company: '', tag: $tag, tagDescription: '',
);
$person = $staffMember('Jane', 'Citizen', 'JWH', '08');
$match = fn (...$filters) => Phonelist::matches($person, ...$filters);
assert($match() && $match(name: '') && $match(name: 'jan') && $match(name: 'CIT') && $match(name: ' Ja '));
assert($match(name: 'jane cit') && $match(name: 'Jane Citizen'));
assert(!$match(name: 'ane') && !$match(name: 'itizen') && !$match(name: 'Janelle') && !$match(name: 'citizen jane'));
assert(!$match(name: '.*') && !$match(name: '/'));  // regex characters are literal
$compound = $staffMember('Maria', 'Santos Lima');
assert(Phonelist::matches($compound, name: 'lim') && Phonelist::matches($compound, name: 'san'));
assert(Phonelist::matches($staffMember('Ann', 'Smith-Jones'), name: 'jones'));
assert($match(division: 'JWH') && !$match(division: 'jwh') && !$match(division: 'JW'));
assert($match(tag: '08') && !$match(tag: '8'));
assert($match(name: 'Jan', division: 'JWH', tag: '08') && !$match(name: 'Jan', division: 'JIT'));
assert(!isset($person->jsonSerialize()['birthday']) && !isset($person->jsonSerialize()['daysUntil']));

echo "plan-check OK\n";
