<?php
// Run: ddev exec php -d zend.assertions=1 tests/plan-check.php

PHP_SAPI === 'cli' || exit;

require __DIR__ . '/../vendor/autoload.php';

use ConstructiveMedia\IntranetPhonelist\Api\Problem;
use ConstructiveMedia\IntranetPhonelist\Api\Signature;
use ConstructiveMedia\IntranetPhonelist\Cli\ClubCommand;
use ConstructiveMedia\IntranetPhonelist\Phonelist;
use ConstructiveMedia\IntranetPhonelist\PostTypes;
use ConstructiveMedia\IntranetPhonelist\Schema;
use ConstructiveMedia\IntranetPhonelist\Staff;
use ConstructiveMedia\IntranetPhonelist\Sync\CompanyMap;
use ConstructiveMedia\IntranetPhonelist\Sync\Photos;

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

// Request signatures: HMAC-SHA256 over "<t>.<raw body>", 5-minute window.
$secret = 'test-secret'; $body = '{"id":"S1","name":"A"}'; $now = 1_760_000_000;
$header = Signature::sign($body, $secret, $now);
assert(preg_match('/^t=1760000000,v1=[0-9a-f]{64}$/', $header) === 1);
assert(Signature::verify($header, $body, $secret, $now + 299) === null);
assert(Signature::verify($header, $body, $secret, $now - 299) === null);
assert(Signature::verify($header, $body, $secret, $now + 301) === 'expired');
assert(Signature::verify($header, '{"id": "S1", "name": "A"}', $secret, $now) === 'mismatch'); // re-encoded body fails
assert(Signature::verify($header, $body, 'other', $now) === 'mismatch');
assert(Signature::verify(null, $body, $secret, $now) === 'missing' && Signature::verify('', $body, $secret, $now) === 'missing');
assert(Signature::verify('v1=abc', $body, $secret, $now) === 'malformed');

// Problem details (RFC 9457).
assert(Problem::body(422, 'Invalid record', 'staff S1', [['field' => 'email', 'message' => 'Not a valid email address.']])
    === ['type' => 'about:blank#validation', 'title' => 'Invalid record', 'status' => 422, 'detail' => 'staff S1', 'errors' => [['field' => 'email', 'message' => 'Not a valid email address.']]]);
assert(Problem::body(404, 'Not found') === ['type' => 'about:blank', 'title' => 'Not found', 'status' => 404, 'detail' => '']);

echo "plan-check OK\n";
