# Intranet Phonelist

A WordPress plugin that mirrors staff and divisions from JWH's Oracle database into WordPress. Theme developers can then query them in PHP or over REST.

- **Company** (entered by hand in WordPress) → **Division** (synced from `DIVISIONS`) → **Staff** (synced from `PHONE_LIST_INTRANET`).
- Requires PHP 8.1+, Advanced Custom Fields, and the `oci8` extension with Oracle Instant Client. Admin Columns is optional.
- Oracle access is read-only: the plugin only ever runs `SELECT` on those two tables.

## Local setup (DDEV)

```bash
ddev start                 # builds Instant Client + oci8 (.ddev/web-build/Dockerfile, x86_64 only)
ddev composer install      # WordPress → wp/, plugins, and links this repo into wp/wp-content/plugins/
ddev wp core install --url=https://wp-intranet-phonelist.ddev.site --title="Intranet Phonelist" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev wp plugin activate advanced-custom-fields codepress-admin-columns intranet-phonelist
```

Create `.env` in the project root. It's gitignored, so never commit it. [wp-config.php](wp-config.php) reads it:

```
INTRANET_PHONELIST_ORACLE_DSN=<host>:1521/<service_name>
INTRANET_PHONELIST_ORACLE_USER=
INTRANET_PHONELIST_ORACLE_PASSWORD=
```

Any WordPress constant can also be set there (`DB_*`, `WP_HOME`, `WP_DEBUG`, salts…); see `wp-config.php` for the full list and defaults.

## Syncing

```bash
wp phonelist sync                  # full sync: mirror Oracle, delete anyone no longer there
wp phonelist sync --staff=00001    # one person only; never deletes anyone else
```

- **Nightly cron**: `0 2 * * * wp phonelist sync --path=/path/to/wordpress`. The command exits non-zero unless the run is fully clean.
- **Settings → Phonelist** has three tabs:
  - **Sync:** the last result, any errors or warnings from it, and **Sync Now**.
  - **Logs:** the audit log, filterable by run, level and action.
  - **Photos:** how many staff have a photo, and who doesn't, with the file names looked for.
- **Log levels:**
  - `error`: failures.
  - `warning`: divisions without a company, dates Oracle sent that couldn't be read.
  - `notice`: deletions.
  - `info`: everything else.
  - `debug`: row counts and timings, written only with `WP_DEBUG`.
- **Log details:** **View** on any entry shows its payload as formatted JSON in a row underneath. Created and updated entries include the row as Oracle sent it, and updates also list each changed field from → to. DOB and home phone are never stored: changes to them are recorded as "(changed)" only.
- **What gets synced**:
  - Divisions are synced only while at least one current staff member belongs to them.
  - IDs are matched as exact strings, so `00001` ≠ `1`.
  - Nothing is deleted if Oracle can't be read or returns no rows.
- **Companies are entered by hand.** Create them under **Companies**, then set each division's **Company** field. The sync never touches either.

## Staff photos

Oracle has no photos, so they come from a folder of files named `first_last_divcode.jpg` (e.g. `jane_citizen_jwh.jpg`). Set the folder in `wp-config.php`:

```php
define('INTRANET_PHONELIST_PHOTOS_DIR', '/path/to/staffPhotos');
```

- **When:** every sync (full or `--staff`) matches each current staff member to a file. It tries the preferred name first, then the first name, ignoring case, spaces and apostrophes.
- **Where they go:** matched files are uploaded to the Media Library and set as the staff member's featured image.
- **Re-syncs:** unchanged files are skipped, and a changed file replaces the photo.
- **Leavers:** their photo is deleted with them.
- **Folder rules:** only the top level of the folder is scanned (subfolders are ignored), and the folder itself is never modified.
- **No folder:** if the constant isn't set, photos are skipped.
- **Gaps:** **Settings → Phonelist → Photos** lists staff without a photo and the file names it looked for. A photo set by hand in the editor is kept unless a matching file turns up.

Locally the folder is `assets/staffPhotos/` (gitignored: never commit staff photos).

## Using it in a theme

```php
use ConstructiveMedia\IntranetPhonelist\Phonelist;

// Upcoming birthdays: next 3 days (0 = today only), soonest first.
// Excludes HR opt-outs; never exposes year of birth or age.
foreach (Phonelist::birthdays(days: 3) as $person) {
    echo "{$person->name} — {$person->birthday->format('l j F')}";
}

// Staff search: every filter is optional and they combine with AND.
Phonelist::staff(name: 'jul');                 // any word of the name starts with 'jul'
Phonelist::staff(division: 'JWH', tag: '20');  // exact DIV_CODE and department code
```

Both return `Staff` objects with these read-only properties:
- `id`, `postId`
- `name`, `firstName`, `lastName`
- `position`, `email`, `mobile`, `extension`
- `divisionCode`, `division`, `company`
- `tag` (department code), `tagDescription`
- `photoId` (for `wp_get_attachment_image()`), `photoUrl` (full size); both null without a photo
- `birthday`, `daysUntil` (from `birthdays()` only)

DOB, home phone and other HR fields are never exposed.

## REST API

```
GET /wp-json/phonelist/v1/staff?name=jul&division=JWH&tag=20
```

This is the same as `Phonelist::staff()`, returned as JSON. Any logged-in user can call it:
- **Intranet pages:** the login cookie plus an `X-WP-Nonce` header (`wp_create_nonce('wp_rest')`).
- **Scripts and other systems:** an Application Password from the user's profile, sent as HTTP Basic auth.

Requests without a login get `401`.

## Code

Symfony-style PHP: PSR-4 (`ConstructiveMedia\IntranetPhonelist\` → `src/`), PSR-12 formatting, constructor injection, and no framework.

| File | Job |
|---|---|
| `intranet-phonelist.php` | WordPress entry point: plugin header, autoloader, `Plugin::boot()` |
| `src/Plugin.php` | Wires everything together; the only place objects are built |
| `src/PostTypes.php` | Post types, the Oracle column mapping, ACF fields |
| `src/Phonelist.php`, `src/Staff.php` | Public theme API and the value object it returns |
| `src/Sync/` | `OracleSource` (SELECTs), `Planner` (pure diff and log payloads), `Synchronizer` (runs a sync), `Photos` (photo folder → Media Library), `Log` (audit table, self-upgrading) |
| `src/Admin/` | `SettingsPage` (Sync, Logs and Photos tabs), `Relationships` (boxes on the edit screens) |
| `src/Cli/SyncCommand.php`, `src/Api/StaffController.php` | WP-CLI command, REST route |

Tests: `ddev exec php -d zend.assertions=1 tests/plan-check.php`

## Installing with Composer

In the site's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/constructivemedia/wp-plugins-intranet-phonelist.git" }
    ],
    "require": {
        "constructivemedia/intranet-phonelist": "^1.0"
    }
}
```

The plugin installs to `wp-content/plugins/intranet-phonelist/`. It requires `composer/installers`; if the site sets custom `installer-paths`, those apply. The plugin has no runtime dependencies and loads its own classes, so it needs no `vendor/` of its own. Installing from a git clone or a zip works the same way.

## Releasing

Composer reads versions from **git tags**, so `composer.json` has no `version` field.

1. Bump `Version:` in `intranet-phonelist.php`.
2. Commit, then tag with the same number and push: `git tag v1.1.2 && git push origin v1.1.2`.

A constraint like `^1.1.2` then picks up `v1.1.2` and anything later below 2.0, so sites get new releases with `composer update`. No reactivation is needed: the log table upgrades itself on load. Release archives leave out `.ddev/`, `tests/` and the dev `wp-config.php` (see `.gitattributes`).

## Deploying

Set the three `INTRANET_PHONELIST_ORACLE_*` constants in the production `wp-config.php`, install `oci8` on the server, activate the plugin, and add the nightly cron.

Run the **first** sync with `wp phonelist sync`, not **Sync Now**. Creating every post takes about a minute, which is longer than most servers let a web request run. Later syncs only touch what changed and take a few seconds.

The **Phonelist** admin area is for administrators only (`manage_options`), because staff records hold HR data. Staff and divisions can't be added or deleted by hand; the sync manages them. They can still be opened and edited (e.g. a division's **Company**).
