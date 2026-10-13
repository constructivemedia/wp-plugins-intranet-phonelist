# Intranet Phonelist

A WordPress plugin that mirrors staff and divisions from JWH's Oracle database into WordPress. Theme developers can then query them in PHP or over REST.

- **Company** (entered by hand in WordPress) → **Division** (synced from `DIVISIONS`) → **Staff** (synced from `PHONE_LIST_INTRANET`).
- Requires PHP 8.1+, Advanced Custom Fields, and the `oci8` extension with Oracle Instant Client. Admin Columns is optional.
- Oracle access is read-only: the plugin only ever runs `SELECT` on those two tables.

Design and decisions: [docs/design.md](docs/design.md).

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
- **Settings → Phone List** has **Sync Now**, the last sync result, divisions still missing a company, and the full audit log, with options to delete old entries.
- **What gets synced**:
  - Divisions are synced only while at least one current staff member belongs to them.
  - IDs are matched as exact strings, so `00001` ≠ `1`.
  - Nothing is deleted if Oracle can't be read or returns no rows.
- **Companies are entered by hand.** Create them under **Companies**, then set each division's **Company** field. The sync never touches either.

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
| `src/Sync/` | `OracleSource` (SELECTs), `Planner` (pure diff), `Synchronizer` (runs a sync), `Log` (audit table) |
| `src/Admin/SettingsPage.php`, `src/Cli/SyncCommand.php`, `src/Api/StaffController.php` | Settings page, WP-CLI command, REST route |

Tests: `ddev exec php -d zend.assertions=1 tests/plan-check.php`

## Deploying

Run `composer install --no-dev` so `vendor/autoload.php` ships without the dev-only packages (WordPress, the bundled plugins, dotenv). Then set the three `INTRANET_PHONELIST_ORACLE_*` constants in the production `wp-config.php`, install `oci8` on the server, and add the nightly cron.

Run the **first** sync with `wp phonelist sync`, not **Sync Now**. Creating every post takes about a minute, which is longer than most servers let a web request run. Later syncs only touch what changed and take a few seconds.

Staff and divisions can't be added or deleted by hand; the sync manages them. They can still be opened and edited (e.g. a division's **Company**).
