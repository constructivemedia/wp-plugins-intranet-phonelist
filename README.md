# Intranet Phonelist

A WordPress plugin holding JWH's companies, divisions and staff for the intranet phone list. JWH's Data Hub pushes them in through a signed REST API, and theme developers read them in PHP or over REST.

- **Structure:** **Company** → **Division** → **Staff**. Data Hub owns the records. Which company a division belongs to is set in WordPress.
- **Requirements:** PHP 8.1+, Advanced Custom Fields, and `giggsey/libphonenumber-for-php-lite` (installed with the plugin by Composer). Admin Columns is optional.
- **For Data Hub:** the API contract is [`docs/openapi.yaml`](docs/openapi.yaml), and the guide for JWH IT is [`docs/integration-guide.md`](docs/integration-guide.md).

## Local setup (DDEV)

```bash
ddev start                 # builds Instant Client + oci8 (.ddev/web-build/Dockerfile, x86_64 only), for the Oracle import
ddev composer install      # WordPress → wp/, plugins, and links this repo into wp/wp-content/plugins/
ddev wp core install --url=https://wp-intranet-phonelist.ddev.site --title="Intranet Phonelist" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev wp plugin activate advanced-custom-fields codepress-admin-columns wp-plugins-intranet-phonelist
```

Create `.env` in the project root. It's gitignored, so never commit it. [wp-config.php](wp-config.php) reads it:

```
INTRANET_PHONELIST_API_SECRET=        # optional locally: defaults to local-dev-secret
INTRANET_PHONELIST_ORACLE_DSN=<host>:1521/<service_name>
INTRANET_PHONELIST_ORACLE_USER=
INTRANET_PHONELIST_ORACLE_PASSWORD=
```

Any WordPress constant can also be set there (`DB_*`, `WP_HOME`, `WP_DEBUG`, salts…); see `wp-config.php` for the full list and defaults.

**Filling a local site:** `ddev wp phonelist oracle-import` (or **Import from Oracle** on the Jobs tab) reads Oracle and sends every division and staff member through the same ingest code as the API. `--staff=<id>` imports one person. It only exists when the Oracle constants are set; production leaves them blank.

## How data gets in

Everything goes through one place, `Ingest\Ingestor`, whether it comes from a single API request, a batch or snapshot job, or the Oracle import.

- **Identity:** records are matched only by their Data Hub `id`, stored in `_iphl_source_id`.
- **Order:** every record carries `updated_at`. Older than what's stored is refused (`409`); identical data is `unchanged`.
- **Status:** `active` staff are kept and `inactive` staff are removed. Any other value is refused (`422`), so an unexpected status can't remove anyone (`Schema::STATUSES`).
- **Locked fields:** pushed fields can't be changed in the editor. Hand-set fields stay editable: a division's **Company**, and a staff member's **JWH Club**.
- **Batches and snapshots** go into a queue table (`wp_iphl_jobs`):
  - The request gets `202` straight away, and the job is processed after the response is sent.
  - A cron task every minute picks up anything left behind.
  - Jobs run one at a time. An interrupted one is simply run again: every item is safe to repeat.
  - On the server, run WP-Cron from the system cron so the sweep doesn't depend on site visits: `* * * * * wp cron event run --due-now --path=/path/to/wordpress`, as the web server's user.

### Stored values

| Fields | Stored as | Editor field |
|---|---|---|
| Dates (`start_date`, `on_leave_until`, `date_of_birth`) | `Ymd` | Date picker |
| `hide_birthday` | `1` / `0` | Select (Yes / No) |
| Phones (`mobile`, `phone`, `home_phone`, division `phone`) | E.164 via libphonenumber (`+61437793226`); unreadable numbers are kept as sent and logged as a warning | Text |
| Everything else | As sent | Text |

8-digit numbers with no area code are assumed to be WA (`08`); change `PhoneNumbers::DEFAULT_AREA_CODE` if that stops being true.

### Links (for Admin Columns and queries)

Stored as post IDs, so renames never need updating:

| Field (meta key) | On | Links to | Set from |
|---|---|---|---|
| `division` | staff | the division | `division_id` |
| `company` | staff | the company | the division's Company |
| `manager` | staff | the manager | `reports_to` (the manager's staff id) |
| `company` | division | the company | set by hand; new divisions get the company with the same name, created if needed |

- **When they're set:** links are made when a record is written, in whatever order records arrive. Staff waiting on a new division or manager are linked when it arrives.
- **Moving a division:** saving it in the admin re-points everyone in it to its company.
- **A full snapshot** finishes by rechecking every link.
- **In the editor:** the fields are hidden; the **Relationships** box shows them, plus direct reports.
- **In Admin Columns (free):** add a **Custom Field** column with the meta key and the field type **Post**.

### Staff photos

- **When a staff member is created:** if Data Hub sent a `photo_url`, it's downloaded. Otherwise a file named `first_last_divisionid.jpg` is looked for in `INTRANET_PHONELIST_PHOTOS_DIR`; only the top level of the folder is scanned.
- **After that, photos are managed by hand.** The only exception is when Data Hub sends a different `photo_url`, which replaces the photo.
- **A failed download** is logged as a warning; the record itself is still saved.
- **Leavers:** their photo is deleted with them.
- **Gaps:** **Settings → Phonelist → Photos** lists staff without a photo and the file names looked for.

Locally the folder is `assets/staffPhotos/`, which is gitignored: never commit staff photos.

## Admin

- **Phonelist menu:** Companies, Divisions, Staff and JWH Club. Administrators only (`manage_options`), because staff records hold HR data. Records can be opened and edited but not added or deleted by hand.
- **Settings → Phonelist:**
  - **Jobs:** recent batches and snapshots, with a result per item. A waiting job has **Run now**, and a failed one **Retry** (also `wp phonelist job <id>`). Development sites also get **Import from Oracle** here.
  - **Logs:** every API request and every record outcome (including `unchanged` and `stale`), filterable by job, level and action. **View** shows the details as JSON. Date of birth and home phone are recorded as "(changed)", never by value.
  - **Photos:** staff without a photo.
- **Retention:** logs are kept for 90 days (`INTRANET_PHONELIST_LOG_DAYS`), and finished jobs for 30. Both are pruned daily.

## Using it in a theme

```php
use ConstructiveMedia\IntranetPhonelist\Phonelist;

// Upcoming birthdays: next 3 days (0 = today only), soonest first.
// Excludes HR opt-outs; never exposes year of birth or age.
foreach (Phonelist::birthdays(days: 3) as $person) {
    echo "{$person->name} — {$person->birthday->format('l j F')}";
}

// Staff search: every filter is optional and they combine with AND.
Phonelist::staff(name: 'jul');                         // any word of the name starts with 'jul'
Phonelist::staff(division: 'JWH', department: '20');   // exact division ID and department code
```

### JWH Club

Club levels are a taxonomy, managed under **Phonelist → JWH Club** (name, description and Club Logo). The standard levels (Lifetime, 40, 35 … 10 Year Club) are created the first time it's empty. Each staff member's level is set with the **JWH Club** dropdown in the editor sidebar. It's set by hand, never by Data Hub, and leavers drop off with their record.

```php
foreach (Phonelist::club() as $level => $group) {   // 'lifetime-club', '40-year-club' … in page order; empty levels left out
    echo "<h2>{$group['title']}</h2>";               // e.g. '15 Year Club'; also 'description' and 'logo' (image URL or null)
    foreach ($group['members'] as $person) {         // Staff objects, sorted by name
        echo "{$person->name} — {$person->company}";
    }
}
```

People with no staff record, such as retired Lifetime members, are added in the theme with a filter:

```php
use ConstructiveMedia\IntranetPhonelist\Staff;

add_filter('intranet_phonelist_club', function (array $club): array {
    $club['lifetime-club']['members'][] = Staff::manual('John Monkhouse', company: 'JWH Group');
    $club['lifetime-club']['members'][] = Staff::manual('Ian Holloway', company: 'JWH Group');
    return $club;
});
```

**Loading an existing list:** run `wp phonelist club-import list.txt [--dry-run]`, with the list laid out like the club page:
- **Layout:** a level heading (`Lifetime Club`, `40 Year Club` …), then each member's name on one line and their company on the next.
- **Matching:** on the preferred name, then the legal first name, then the email.
- **Reported, not guessed:** names with no record, and anyone listed twice.

### Staff objects

`staff()`, `birthdays()` and `club()` return `Staff` objects with these read-only properties:
- `id` (the Data Hub ID), `postId`
- `name`, `firstName`, `lastName`
- `position`, `email`, `mobile`, `phone` (direct), `extension` (VoIP)
- `divisionId`, `division`, `company`
- `departmentCode`, `department`
- `onLeaveUntil` (a date, or null when not on leave)
- `photoId` (for `wp_get_attachment_image()`), `photoUrl` (full size); both null without a photo
- `club` (the level's slug: `'lifetime-club'`, `'40-year-club'` …, or null)
- `birthday`, `daysUntil` (from `birthdays()` only)

Date of birth, home phone and other HR fields are never exposed.

**Changes from v1:** `extension` is now the VoIP extension, and the direct number is `phone`. Also renamed: `divisionCode` → `divisionId`, `tag` → `departmentCode`, `tagDescription` → `department`, and `staff(tag:)` → `staff(department:)`.

## REST API

Everything is under `/wp-json/phonelist/v2/`:

| | |
|---|---|
| `GET /club` | `Phonelist::club()` as a list in page order: `[{"level": "lifetime-club", "title": …, "description": …, "logo": …, "members": [...]}, …]` |
| `GET /{companies\|divisions\|staff}` | Every record WordPress holds, in Data Hub's shape, sorted by name (`id` is null for records WordPress made, such as a company created for a new division). Staff can be filtered: `?name=&division_id=&department_code=` |
| `GET /{companies\|divisions\|staff}/{id}` | One record, by Data Hub ID |
| `PUT`, `PATCH`, `DELETE`, `POST /batch`, `PUT /{type}`, `GET /jobs/{id}` | Data Hub's ingress (signed), see [`docs/integration-guide.md`](docs/integration-guide.md) |

**Who can read:**
- **Logged-in users:** on intranet pages, use the login cookie plus an `X-WP-Nonce` header (`wp_create_nonce('wp_rest')`). Scripts can use an Application Password instead.
- **Signed requests:** these are the only ones that see `date_of_birth` and `home_phone`.

The v1 `phonelist/v1` routes are gone.

## Code

Symfony-style PHP: PSR-4 (`ConstructiveMedia\IntranetPhonelist\` → `src/`), PSR-12 formatting, constructor injection, and no framework.

| File | Job |
|---|---|
| `intranet-phonelist.php` | WordPress entry point: plugin header, autoloader, `Plugin::boot()` |
| `src/Plugin.php` | Wires everything together; the only place objects are built |
| `src/Schema.php` | The data contract: every field, validation, the status rule |
| `src/PostTypes.php` | Post types, the JWH Club taxonomy, ACF fields (from `Schema`) |
| `src/Relations.php` | Division, company and manager links |
| `src/Ingest/` | `Ingestor` (the only writer of records), `Outcome` (one record's result), `Jobs` (queue), `Photos`, `PhoneNumbers`, `Log` (audit table) |
| `src/Api/` | `IngressController`, `ReadController`, `Signature`, `Problem` |
| `src/Oracle/` | `Adapter`: the development import |
| `src/Admin/` | `SettingsPage` (Jobs, Logs, Photos), `Relationships` (boxes on the edit screens) |
| `src/Cli/` | `club-import`, `job`, `oracle-import` |
| `src/Upgrade.php` | One-off v1 → v2 data upgrade, run on first load |
| `src/Phonelist.php`, `src/Staff.php` | Public theme API and the value object it returns |

Tests:

```bash
ddev exec php -d zend.assertions=1 tests/plan-check.php                                            # pure PHP
ddev exec php -d zend.assertions=1 /usr/local/bin/wp-cli --path=wp eval-file tests/wp-check.php    # inside WordPress
bash tests/api-check.sh                                                                             # the REST API, end to end
```

## Installing with Composer

In the site's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/constructivemedia/wp-plugins-intranet-phonelist.git" }
    ],
    "require": {
        "constructivemedia/wp-plugins-intranet-phonelist": "^2.0"
    }
}
```

The repository is private, so each site needs GitHub access: either a token (`composer config --global github-oauth.github.com <token>`), or an SSH key with `"url": "git@github.com:constructivemedia/wp-plugins-intranet-phonelist.git"`. The plugin installs to `wp-content/plugins/wp-plugins-intranet-phonelist/` (`composer/installers`). Its one dependency, libphonenumber, is installed and autoloaded with the site. For a git clone or zip install, run `composer install --no-dev` in the plugin folder.

## Releasing

Composer reads versions from **git tags**, so `composer.json` has no `version` field.

1. Bump `Version:` in `intranet-phonelist.php`.
2. Commit, then tag with the same number and push: `git tag v2.0.1 && git push origin v2.0.1`.

Tables and data upgrade themselves on load. Release archives leave out `.ddev/`, `tests/` and the dev `wp-config.php` (see `.gitattributes`).

## Deploying

1. **Constants:** set `INTRANET_PHONELIST_API_SECRET` in the production `wp-config.php`, shared with JWH IT and never stored in the database. Set `INTRANET_PHONELIST_PHOTOS_DIR` if you use a photo folder. Leave the Oracle constants out.
2. **Install:** run `composer update`, then activate the plugin. Coming from v1, the first load upgrades the existing records in place, keeping their photos, club levels and division companies.
3. **Cron:** add the system cron line for WP-Cron (see **How data gets in**).
4. **Data:** ask Data Hub for a full snapshot of each type (divisions, then staff), and check the Jobs tab.
