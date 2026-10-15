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
use ConstructiveMedia\IntranetPhonelist\Ingest\Photos;
use ConstructiveMedia\IntranetPhonelist\Oracle\Adapter;

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
    assert(Adapter::prettyName($raw) === $pretty, "$raw → " . Adapter::prettyName($raw));
}

// Club list import: level headings, description lines skipped, then name/company pairs; names compared loosely.
$list =ClubCommand::parse("Lifetime Club\nOur lifetime club members.\n\nJane Citizen\nJWH Group\n\n40 Year Club\nCelebrating 40 years with JWH Group.\n\n\nAnn Smith-Jones\nPlunkett Homes\nMaria Santos Lima\nWA Country Builders, Drafting\n");
assert($list === ['lifetime-club' => [['Jane Citizen', 'JWH Group']], '40-year-club' => [['Ann Smith-Jones', 'Plunkett Homes'], ['Maria Santos Lima', 'WA Country Builders, Drafting']]]);
assert(ClubCommand::key('Dianne O’Malley') === ClubCommand::key("dianne o'malley") && ClubCommand::key('Derrick D’Souza') === 'derrickdsouza');

// Photo matching: 'first_last_divcode.jpg', preferred name first, names simplified the same way on both sides.
assert(Photos::candidates('Janey', 'Jane A', 'Citizen', 'JWH') === ['janey_citizen_jwh', 'jane_citizen_jwh', 'janea_citizen_jwh']);
assert(Photos::candidates(null, 'Jane', 'Citizen', 'JWH') === ['jane_citizen_jwh']);
assert(Photos::candidates('Ann', 'Ann', "O'Shea-Smith", 'JWH')[0] === 'ann_oshea-smith_jwh');
assert(Photos::candidates('Maria', 'Maria', 'Santos Lima', 'PHM')[0] === 'maria_santoslima_phm');
assert(Photos::candidates('Jane', 'Jane', 'Citizen', null) === []);                       // no division, no match
assert(Photos::fileKey('jane_citizen_jwh.jpg') === 'jane_citizen_jwh');
assert(Photos::fileKey('Jane_Citizen_JWH.JPEG') === 'jane_citizen_jwh');
assert(Photos::fileKey('jane_citizen_jwh.jpg.jpg') === 'jane_citizen_jwh');
assert(Photos::fileKey('Jane Citizen_WACB.jpg') === 'jane_citizen_wacb');
assert(Photos::fileKey('notes.txt') === null && Photos::fileKey('.jpg') === null);

// Next birthday from stored Ymd: year of birth ignored, wraps into next year, 29 Feb → 28 Feb in non-leap years.
$next = fn ($dob, $today) => Phonelist::nextOccurrence($dob, new DateTimeImmutable($today))?->format('Y-m-d');
assert($next('19851013', '2026-10-13') === '2026-10-13'); // today
assert($next('19851014', '2026-10-13') === '2026-10-14');
assert($next('19851012', '2026-10-13') === '2027-10-12'); // passed → next year
assert($next('19990102', '2026-12-30') === '2027-01-02'); // across year end
assert($next('19880229', '2026-10-13') === '2027-02-28'); // 2027 not leap
assert($next('19880229', '2027-10-13') === '2028-02-29'); // 2028 leap
assert($next('', '2026-10-13') === null);

// Staff filters: any word of the full name starts with (case-insensitive), exact division and department codes, AND-combined.
$staffMember = fn (string $first, string $last, string $divisionId = 'PHM', string $department = '') => new Staff(
    id: '1', postId: 1, name: "$first $last", firstName: $first, lastName: $last, position: '', email: '', mobile: '', phone: '',
    extension: '', divisionId: $divisionId, division: '', company: '', departmentCode: $department, department: '',
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
assert($match(department: '08') && !$match(department: '8'));
assert($match(name: 'Jan', division: 'JWH', department: '08') && !$match(name: 'Jan', division: 'JIT'));
assert(Staff::manual('John Monkhouse', company: 'JWH Group')->lastName === 'Monkhouse');
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
// Status is a fixed list: anything else is refused, never treated as a leaver.
$status = fn (mixed $value): array => Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00Z', 'first_name' => 'A', 'last_name' => 'B', 'status' => $value], false);
assert($status('active')['errors'] === [] && $status('inactive')['errors'] === []);
foreach (['Active', 'terminated', 'A', 1] as $unknown) {
    assert($status($unknown)['errors'][0]['field'] === 'status', "status $unknown");
}
assert(Schema::STATUSES === ['active' => true, 'inactive' => false]);
assert(Schema::validate('staff', ['id' => 'S1', 'updated_at' => '2026-10-15T09:30:00+08:00', 'first_name' => 'A', 'last_name' => 'B', 'status' => 'active', 'phone' => '12'], false)['data']['phone'] === '12'); // unreadable phone kept as sent
assert(Schema::updatedAt('2026-10-15T09:30:00+08:00')->format('U') === (new DateTimeImmutable('2026-10-15T01:30:00Z'))->format('U'));
assert(Schema::updatedAt('nope') === null && Schema::updatedAt(null) === null && Schema::updatedAt(5) === null);
assert(Schema::updatedAt('2026-10-15T09:30:00.1234567+08:00')?->format('Y-m-d H:i:s.u') === '2026-10-15 09:30:00.123456'); // .NET's 7-digit fractions

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
$path = '/wp-json/phonelist/v2/staff/S1';
$header = Signature::sign('PUT', $path, $body, $secret, $now);
assert(preg_match('/^t=1760000000,v1=[0-9a-f]{64}$/', $header) === 1);
assert($header === 't=1760000000,v1=' . hash_hmac('sha256', "1760000000.PUT.$path.$body", $secret));
assert(Signature::verify($header, 'PUT', $path, $body, $secret, $now + 299) === null);
assert(Signature::verify($header, 'PUT', $path, $body, $secret, $now - 299) === null);
assert(Signature::verify($header, 'PUT', $path, $body, $secret, $now + 301) === 'expired');
assert(Signature::verify($header, 'PUT', $path, '{"id": "S1", "name": "A"}', $secret, $now) === 'mismatch'); // re-encoded body fails
assert(Signature::verify($header, 'PUT', $path, $body, 'other', $now) === 'mismatch');
assert(Signature::verify($header, 'DELETE', $path, $body, $secret, $now) === 'mismatch');                   // another method
assert(Signature::verify($header, 'PUT', '/wp-json/phonelist/v2/staff/S2', $body, $secret, $now) === 'mismatch'); // another record
assert(Signature::verify(null, 'PUT', $path, $body, $secret, $now) === 'missing' && Signature::verify('', 'PUT', $path, $body, $secret, $now) === 'missing');
assert(Signature::verify('v1=abc', 'PUT', $path, $body, $secret, $now) === 'malformed');

// Problem details (RFC 9457).
assert(Problem::body(422, 'Invalid record', 'staff S1', [['field' => 'email', 'message' => 'Not a valid email address.']])
    === ['type' => 'about:blank#validation', 'title' => 'Invalid record', 'status' => 422, 'detail' => 'staff S1', 'errors' => [['field' => 'email', 'message' => 'Not a valid email address.']]]);
assert(Problem::body(404, 'Not found') === ['type' => 'about:blank', 'title' => 'Not found', 'status' => 404, 'detail' => '']);

// Oracle import (development only): Oracle rows → contract records.
$oracleRow = ['ID' => '42', 'NAME_FIRST' => ' Jane ', 'NAME_LAST_CO' => 'Citizen', 'PREF_NAME' => '', 'E_STATUS' => 'E', 'PH_EXT' => '0892221111',
    'REPORTS_TO_ID' => 'P9', 'POSITION_NO' => 'P1', 'DOB' => '05Sep85', 'START_DATE' => 'N/A', 'ON_LEAVE' => '20261101', 'HIDE_BIRTHDAY' => 'y', 'DIV_CODE' => 'JWH', 'EMAIL' => 'not an email']
    + array_fill_keys(['MOBILE_NO', 'VOIP', 'HOME_PHONE', 'POSITION', 'JOB', 'JOB_TYPE', 'AWARD_CODE', 'TAG', 'TAG_DESCRIPTION', 'COMPANY_ID'], '');
$today = new DateTimeImmutable('2026-10-13');
$rec = Adapter::staff($oracleRow, ['P9' => '7', 'P1' => '42'], '2026-10-15T09:00:00+08:00', $today);
assert($rec['id'] === '42' && $rec['first_name'] === 'Jane' && $rec['preferred_name'] === null && $rec['status'] === 'active' && $rec['email'] === null);
assert($rec['reports_to'] === '7' && $rec['date_of_birth'] === '1985-09-05' && $rec['start_date'] === null && $rec['on_leave_until'] === '2026-11-01' && $rec['hide_birthday'] === true);
assert(Adapter::staff(['REPORTS_TO_ID' => 'P1'] + $oracleRow, ['P1' => '42'], '2026-10-15T09:00:00+08:00', $today)['reports_to'] === null); // never their own manager
assert(Adapter::staff(['PH_EXT' => 'LEFT'] + $oracleRow, [], '2026-10-15T09:00:00+08:00', $today)['status'] === 'inactive');
assert(Adapter::staff(['E_STATUS' => 'xcon'] + $oracleRow, [], '2026-10-15T09:00:00+08:00', $today)['status'] === 'inactive');
assert(Schema::validate('staff', $rec, false)['errors'] === []);                               // adapter output is a valid contract record
$dob = fn (string $value): ?string => Adapter::staff(['DOB' => $value] + $oracleRow, [], '2026-10-15T09:00:00+08:00', $today)['date_of_birth'];
assert($dob('15Jun65') === '1965-06-15' && $dob('15jun05') === '2005-06-15');                  // PHP alone would say 2065
assert($dob('13Oct26') === '2026-10-13' && $dob('14Oct26') === '1926-10-14');                  // birth dates never in the future
assert($dob('31Feb85') === null && $dob('') === null && $dob('garbage') === null && $dob('19650615') === '1965-06-15');
$start = fn (string $value): ?string => Adapter::staff(['START_DATE' => $value] + $oracleRow, [], '2026-10-15T09:00:00+08:00', $today)['start_date'];
assert($start('01Apr27') === '2027-04-01' && $start('20260231') === null);                     // future start dates stay future
$division = Adapter::division(['DIV_CODE' => 'BNB', 'DIV_NAME' => 'W.A. COUNTRY BUILDERS ( BUNBURY)', 'PHONE' => '9721 0000', 'DIV_ADDR1' => '1 Main St', 'DIV_ADDR2' => '', 'DIV_PCODE' => '6230', 'POSTAL_ADDRESS' => ''], '2026-10-15T09:00:00+08:00');
assert($division['id'] === 'BNB' && $division['name'] === 'W.A. Country Builders (Bunbury)' && $division['address_line2'] === null);
assert(Schema::validate('divisions', $division, false)['errors'] === []);
assert(Adapter::companyFor('BNB') === 'WA Country Builders' && Adapter::companyFor('NEW') === null);

echo "plan-check OK\n";
