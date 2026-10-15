<?php
// Run: ddev exec php -d zend.assertions=1 tests/plan-check.php

PHP_SAPI === 'cli' || exit;

require __DIR__ . '/../vendor/autoload.php';

use ConstructiveMedia\IntranetPhonelist\Cli\ClubCommand;
use ConstructiveMedia\IntranetPhonelist\Phonelist;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;
use ConstructiveMedia\IntranetPhonelist\Staff;
use ConstructiveMedia\IntranetPhonelist\Sync\CompanyMap;
use ConstructiveMedia\IntranetPhonelist\Sync\Photos;
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
$expected = [PostTypes::DIVISION => 9, PostTypes::STAFF => 28];
foreach (PostTypes::synced() as $type => $config) {
    assert(count($config['columns']) === $expected[$type] && count(array_unique($config['columns'])) === $expected[$type]);
    foreach ($config['fields'] as $tab => $columns) {
        $row = 0;
        foreach ($columns as [, $width]) {
            $row += $width;
            $row = $row === 100 ? 0 : $row;
            assert($row < 100, "{$type}/{$tab} widths overflow a row");
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

// Log payloads: field-level changes, and HR fields never logged by value.
$changes = Planner::changes(['POSITION' => 'Estimator', 'DOB' => '19850905', 'EMAIL' => 'a@x'], ['POSITION' => 'Senior Estimator', 'DOB' => '19850906', 'EMAIL' => 'a@x']);
assert($changes === ['POSITION' => ['from' => 'Estimator', 'to' => 'Senior Estimator'], 'DOB' => '(changed)']);
assert(!str_contains(json_encode($changes), '1985'));
assert(Planner::redact(['ID' => '1', 'DOB' => '19850905', 'HOME_PHONE' => '9999', 'EMAIL' => 'a@x']) === ['ID' => '1', 'EMAIL' => 'a@x']);
$raw = ['DOB' => '31Feb85', 'START_DATE' => 'N/A', 'ON_LEAVE' => '', 'INDUCTION_COMPLETE_DATE' => '05Sep85'];
$normalized = ['DOB' => '', 'START_DATE' => '', 'ON_LEAVE' => '', 'INDUCTION_COMPLETE_DATE' => '19850905'];
assert(Planner::unreadable($raw, $normalized, array_fill_keys(array_keys($raw), PostTypes::DATE)) === ['DOB']); // 'N/A' and '' are empty on purpose

// Other kinds: BOOL 'y' → '1', STATUS 'e' → 'active', PHONE in one format (unreadable kept as is, and reported).
$kinds = ['HIDE_BIRTHDAY' => PostTypes::BOOL, 'E_STATUS' => PostTypes::STATUS, 'MOBILE_NO' => PostTypes::PHONE, 'PH_EXT' => PostTypes::PHONE];
$typed = fn (array $row) => Planner::normalize($row, $kinds, new DateTimeImmutable('2026-10-13'));
$row = $typed(['HIDE_BIRTHDAY' => 'y', 'E_STATUS' => 'e', 'MOBILE_NO' => '0437793226', 'PH_EXT' => '(08) 94647800']);
assert($row === ['HIDE_BIRTHDAY' => '1', 'E_STATUS' => 'active', 'MOBILE_NO' => '+61437793226', 'PH_EXT' => '+61894647800']);
$row = $typed(['HIDE_BIRTHDAY' => ' ', 'E_STATUS' => 'xcon', 'MOBILE_NO' => '+61437793226', 'PH_EXT' => '9464 7800']);
assert($row === ['HIDE_BIRTHDAY' => '0', 'E_STATUS' => 'inactive', 'MOBILE_NO' => '+61437793226', 'PH_EXT' => '+61894647800']); // no area code → 08
assert($typed(['HIDE_BIRTHDAY' => 'Y', 'E_STATUS' => 'E', 'MOBILE_NO' => '', 'PH_EXT' => ''])['HIDE_BIRTHDAY'] === '1');
$raw = ['HIDE_BIRTHDAY' => '', 'E_STATUS' => '', 'MOBILE_NO' => '0894647800 - Bunbury', 'PH_EXT' => ''];
assert($typed($raw)['MOBILE_NO'] === '0894647800 - Bunbury');                                       // unreadable: kept as it was
assert(Planner::unreadable($raw, $typed($raw), $kinds) === ['MOBILE_NO']);                          // ...and reported

// Company mapping: one company per line, codes split by commas/spaces, blank lines ignored, round-trips.
$map = CompanyMap::parse("WA Country Builders: GTN, BSN,BNB  ALB\n\nJWH Group: JWH, JIT\nJWH Group: JIT, CME\nNo Codes Yet:\n");
assert($map === ['WA Country Builders' => ['GTN', 'BSN', 'BNB', 'ALB'], 'JWH Group' => ['JWH', 'JIT', 'CME'], 'No Codes Yet' => []]);
assert(CompanyMap::parse(CompanyMap::format($map)) === $map);
assert(CompanyMap::parse('') === []);
$byDivision = CompanyMap::byDivision(['A' => ['X', 'Y'], 'B' => ['Y', 'Z']]);
assert($byDivision['divisions'] === ['X' => 'A', 'Y' => 'A', 'Z' => 'B']); // first listing wins
assert($byDivision['conflicts'] === ['Y' => ['A', 'B']]);

// Every division gets a company: mapped, else kept if set by hand, else one named after the division.
$mapped = ['JIT' => 'JWH Group'];
assert(CompanyMap::companyFor('JIT', 'JWH IT', true, $mapped) === 'JWH Group');                       // mapping wins over a hand-set one
assert(CompanyMap::companyFor('RBC', 'Rural Building Company', true, $mapped) === null);              // set by hand, not mapped: keep
assert(CompanyMap::companyFor('RBC', 'Rural Building Company', false, $mapped) === 'Rural Building Company');
assert(CompanyMap::companyFor('NEW', 'Brand New Division', false, []) === 'Brand New Division');      // works with no mapping at all

// Club list import: level headings, description lines skipped, then name/company pairs; names compared loosely.
$list =ClubCommand::parse("Lifetime Club\nOur lifetime club members.\n\nJane Citizen\nJWH Group\n\n40 Year Club\nCelebrating 40 years with JWH Group.\n\n\nAnn Smith-Jones\nPlunkett Homes\nMaria Santos Lima\nWA Country Builders, Drafting\n");
assert($list === ['lifetime-club' => [['Jane Citizen', 'JWH Group']], '40-year-club' => [['Ann Smith-Jones', 'Plunkett Homes'], ['Maria Santos Lima', 'WA Country Builders, Drafting']]]);
assert(ClubCommand::key('Dianne O’Malley') === ClubCommand::key("dianne o'malley") && ClubCommand::key('Derrick D’Souza') === 'derrickdsouza');

// Photo matching: 'first_last_divcode.jpg', preferred name first, names simplified the same way on both sides.
$photoRow = fn (string $pref, string $first, string $last, string $div = 'JWH') => ['PREF_NAME' => $pref, 'NAME_FIRST' => $first, 'NAME_LAST_CO' => $last, 'DIV_CODE' => $div];
assert(Photos::candidates($photoRow('Janey', 'Jane A', 'Citizen')) === ['janey_citizen_jwh', 'jane_citizen_jwh', 'janea_citizen_jwh']);
assert(Photos::candidates($photoRow('', 'Jane', 'Citizen')) === ['jane_citizen_jwh']);
assert(Photos::candidates($photoRow('Ann', 'Ann', "O'Shea-Smith"))[0] === 'ann_oshea-smith_jwh');
assert(Photos::candidates($photoRow('Maria', 'Maria', 'Santos Lima', 'PHM'))[0] === 'maria_santoslima_phm');
assert(Photos::fileKey('jane_citizen_jwh.jpg') === 'jane_citizen_jwh');
assert(Photos::fileKey('Jane_Citizen_JWH.JPEG') === 'jane_citizen_jwh');
assert(Photos::fileKey('jane_citizen_jwh.jpg.jpg') === 'jane_citizen_jwh');
assert(Photos::fileKey('Jane Citizen_WACB.jpg') === 'jane_citizen_wacb');
assert(Photos::fileKey('notes.txt') === null && Photos::fileKey('.jpg') === null);

// Staff title: preferred name, else first name, then surname.
$title = PostTypes::synced()[PostTypes::STAFF]['title'];
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

// Schema: validation and normalisation of pushed records.
$ok = Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00+08:00', 'first_name' => 'Jane', 'last_name' => 'Citizen',
    'status' => 'active', 'mobile' => '0437 793 226', 'hide_birthday' => true, 'date_of_birth' => '1985-09-05', 'bogus' => 'x'], false);
assert($ok['errors'] === []);
assert($ok['data']['mobile'] === '+61437793226' && $ok['data']['hide_birthday'] === '1' && $ok['data']['date_of_birth'] === '19850905');
assert(!isset($ok['data']['bogus']) && !isset($ok['data']['id']) && !isset($ok['data']['updated_at']));
assert(array_key_exists('email', $ok['data']) && $ok['data']['email'] === null); // full write: missing optional fields are cleared

$bad = Schema::validate('staff', ['id' => 'S1', 'updated_at' => 'yesterday', 'first_name' => '', 'status' => 'active', 'email' => 'nope', 'start_date' => '05Sep85', 'hide_birthday' => 'y'], false);
assert(array_column($bad['errors'], 'field') === ['updated_at', 'first_name', 'last_name', 'email', 'start_date', 'hide_birthday']);
assert(Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00', 'first_name' => 'A', 'last_name' => 'B', 'status' => 'active'], false)['errors'][0]['field'] === 'updated_at'); // no offset
assert(Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00Z', 'first_name' => 'A', 'last_name' => 'B', 'status' => 'active', 'start_date' => '2026-02-31'], false)['errors'][0]['field'] === 'start_date');

$patch = Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00Z', 'email' => null], true);
assert($patch['errors'] === [] && $patch['data'] === ['email' => null]);    // partial: only what was sent

assert(Schema::validate('divisions', ['id' => 'JWH', 'updated_at' => '2026-10-15T09:30:00Z'], false)['errors'][0]['field'] === 'name');
assert(Schema::validate('staff', ['updated_at' => '2026-10-15T09:30:00Z'], true)['errors'][0]['field'] === 'id');
assert(Schema::keeps('active') && !Schema::keeps('terminated') && !Schema::keeps(null));
assert(Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00+08:00', 'first_name' => 'A', 'last_name' => 'B', 'status' => 'active', 'phone' => '12'], false)['data']['phone'] === '12'); // unreadable phone kept as sent
assert(Schema::updatedAt(['updated_at' => '2026-10-15T09:30:00+08:00'])->format('U') === (new DateTimeImmutable('2026-10-15T01:30:00Z'))->format('U'));
assert(Schema::updatedAt(['updated_at' => 'nope']) === null && Schema::updatedAt([]) === null);

// Editor layout: each tab's widths fill whole rows.
foreach (array_keys(Schema::TYPES) as $type) {
    $rows = [];
    foreach (Schema::fields($type) as $field => $config) {
        $rows[$config['tab']] = (($rows[$config['tab']] ?? 0) + $config['width']) % 100;
    }
    assert(array_filter($rows) === [], "$type has an unfinished row");
}

echo "plan-check OK\n";
