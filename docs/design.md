# Intranet Phonelist — Oracle Sync Design

Date: 2026-10-13

## Goal

Replace the legacy phone list sync script with a WordPress plugin that mirrors staff and divisions from JWH's Oracle database into custom post types with ACF fields, grouped under hand-managed companies. It runs nightly from cron via WP-CLI or on demand from a **Sync Now** button, and every change goes into an audit log.

## Decisions

| Topic | Decision |
|---|---|
| Source | Oracle, read-only. `PHONE_LIST_INTRANET` → staff, `DIVISIONS` → divisions. |
| Driver | `oci8` PHP extension (PECL) + Oracle Instant Client. No ORDS available. |
| Read-only | Code runs only `SELECT`. JWH IT to supply an account with `SELECT` on those two tables only. |
| Credentials | `.env` → `wp-config.php` constants: `INTRANET_PHONELIST_ORACLE_DSN`, `INTRANET_PHONELIST_ORACLE_USER`, `INTRANET_PHONELIST_ORACLE_PASSWORD`. |
| Storage | Synced CPTs `iphl_staff` and `iphl_division` (one ACF field per synced Oracle column, read-only). Manual CPT `iphl_company` (about 10 posts, entered by hand). Everything in WordPress's shared namespaces is prefixed `iphl_`. ACF required, Admin Columns optional. |
| Keys | Staff: `ID`. Division: `DIV_CODE`. Stored as post meta `_iphl_source_id` and treated as **opaque strings**: no trimming or casting (`00001` ≠ `1`; UUIDs are fine). The internal ID is the WordPress post ID. |
| Which divisions | Full sync keeps only divisions that **at least one current staff member** belongs to (exact `DIV_CODE` match), about 18 of 69. `DIVISIONS` has no status flag, so this drops defunct and dummy divisions, and a division is deleted once its last person leaves. |
| Hierarchy | Company (manual) → Division (synced) → Staff (synced). A division's editable ACF `company` field (post_object, ID) points at its company; the sync never writes it. Staff link to their division by `div_code` = `DIV_CODE`. The settings page lists divisions with no company. Oracle's `COMPANY_ID` follows the legal entity, not the brand, so it isn't used. |
| Leavers | Oracle's source already excludes leavers. **Full sync**: any key missing from Oracle, or with `PH_EXT = 'LEFT'`, is **permanently deleted** and logged. **Single sync**: only that person is created, updated or deleted. |
| Change detection | `_iphl_source_hash` meta = md5 of the source row. An unchanged hash means no write and no log entry. |
| Log | Custom table, kept forever, viewable and deletable in settings. |
| DOB | Synced for the birthday list. `HIDE_BIRTHDAY` (the HR opt-out) is synced alongside it and must be respected by any display. |

## Files

Symfony-style PHP (PSR-4 + PSR-12, constructor injection), with no Symfony dependency. Namespace `ConstructiveMedia\IntranetPhonelist\` → `src/`, loaded by Composer.

- `intranet-phonelist.php`: WordPress entry point only — plugin header, autoloader, `Plugin::boot(__FILE__)`.
- `src/Plugin.php`: wires everything (the only place objects are built).
- `src/PostTypes.php`: company/division/staff post types, the synced column maps (`PostTypes::synced()`), and the ACF fields.
- `src/Sync/OracleSource.php`: the read-only `SELECT`s.
- `src/Sync/Planner.php`: pure create/update/delete and staffed-division logic.
- `src/Sync/Synchronizer.php`: runs a sync — lock, fetch, apply, log.
- `src/Sync/Log.php`: the audit log table (install, write, page, delete).
- `src/Admin/SettingsPage.php`, `src/Cli/SyncCommand.php`.
- `src/Phonelist.php`: public API for themes; `src/Staff.php`: the read-only value object it returns.
- `tests/plan-check.php`: assert-based checks (planner, IDs, staffed divisions, titles, birthdays).
- `.ddev/web-build/Dockerfile`: Instant Client + `pecl install oci8`.

## Theme API

`Phonelist::birthdays(int $days = 7): Staff[]` returns staff whose next birthday is within `$days` (0 = today), soonest first, in the site timezone. It excludes `HIDE_BIRTHDAY = 'y'` (case/space-insensitive) and unparseable DOBs. Oracle sends `DOB`, `START_DATE` and `INDUCTION_COMPLETE_DATE` as text like `05Sep85`. `ON_LEAVE` is a real `DATE`: the session's `NLS_DATE_FORMAT = 'YYYYMMDD'` returns it in ACF's format with the full year, and its placeholder `'N/A'` means empty. The sync converts all four once (`Planner::normalize()`) to ACF's native Date Picker format, `Ymd` (`19850905`), and nothing else ever parses Oracle date text. Two-digit DOB years resolve to the most recent past year (`65` → 1965), while other dates use PHP's 1970–2069 window. Unreadable values are stored empty. `birthdays()` ignores the year, so no age or birth year is exposed. 29 Feb falls on 28 Feb in non-leap years. Each `Staff` has `id`, `postId`, `name`, `firstName`, `lastName`, `position`, `email`, `mobile`, `extension`, `divisionCode`, `division`, `company`, `tag` (department code, e.g. `20`), `tagDescription` (e.g. `Construction`), plus `birthday` (next occurrence) and `daysUntil` from `birthdays()`. It never carries DOB, home phone or other HR fields.

`Phonelist::staff(?string $name, ?string $division, ?string $tag): Staff[]` filters combine with AND and results are sorted by name.
- `name`: case-insensitive match from the start of any word in the full name (spaces and hyphens separate words). For "Jane Santos-Lima", `jan`, `san`, `lima` and `jane san` all work; `ane` doesn't. Regex characters are treated literally.
- `division`: exact `DIV_CODE`.
- `tag`: exact department code (`08` ≠ `8`).

## Staff photos

`Sync\Photos`: Oracle has no photos, so `INTRANET_PHONELIST_PHOTOS_DIR` points at a folder of `first_last_divcode.jpg` files (top level only).
- **Matching:** each sync matches every current staff member, trying the preferred name, then the first word of the first name, then the full first name. Names are lowercased with spaces and apostrophes removed and hyphens kept.
- **Upload:** matches are sideloaded into the Media Library as the featured image.
- **Changes:** `_iphl_photo_source` (name|size|mtime) skips unchanged files, and a changed file replaces the photo. Leavers' photos are deleted with them.
- **Future:** a photo field from Oracle would take priority, at the marked spot in `Photos::attach()`.
- **Logging and API:** each photo is logged as a `photo` action, and the summary reports "Photos added N, no photo M". `Staff` exposes `photoId` and `photoUrl`.

## REST API

`GET /wp-json/phonelist/v1/staff?name=&division=&tag=` is the same as `Phonelist::staff()`, returned as JSON (`src/Api/StaffController.php`). Access: **any logged-in user**, with no further capability checks. Intranet pages use their login cookie plus `X-WP-Nonce`; scripts and external systems use WordPress Application Passwords (HTTP Basic). Unauthenticated requests get `401`.

## Column maps

One array per post type (`ORACLE_COLUMN => acf_field_name`, lowercased). Post title (an admin label only; displays use the fields): staff = `PREF_NAME` (or `NAME_FIRST` if blank) + `NAME_LAST_CO`; division = `PostTypes::prettyName(DIV_NAME)` (title case and tidy brackets, keeping the acronyms `JWH`, `IT`, `GSH` and `W.A.`; e.g. `ACTIVE PLUMBING - METRO` → `Active Plumbing - Metro`). `Staff::$division` uses that title. Admin: a **Phonelist** menu (opens Staff) holds Companies, Divisions and Staff; editor fields are grouped into tabs (`fields` layout in `PostTypes::synced()`); each edit screen has a read-only **Relationships** box (`AdminRelationships`): staff show their division, company, reports-to (`REPORTS_TO_ID` is the manager's `POSITION_NO`) and direct reports; divisions show their company and staff; companies show their divisions with staff counts. Values are stored raw; formatting is the display's job.

- **Division** (`DIVISIONS`): `DIV_CODE`, `DIV_NAME`, `DIV_ADDR1`, `DIV_ADDR2`, `DIV_PCODE`, `POSTAL_ADDRESS`, `PHONE`, `FAX`, `DX_NO`.
- **Staff** (`PHONE_LIST_INTRANET`): `ID`, `NAME_LAST_CO`, `NAME_FIRST`, `FULL_NAME`, `PREF_NAME`, `DOB`, `START_DATE`, `DIV_CODE`, `EMAIL`, `MOBILE_NO`, `PH_EXT`, `POSITION`, `HOME_PHONE`, `AWARD_CODE`, `POSITION_NO`, `POSITION_ABBR`, `USERNAME`, `E_STATUS`, `JOB`, `ON_LEAVE`, `JOB_TYPE`, `COMPANY_ID`, `VOIP`, `REPORTS_TO_ID`, `INDUCTION_COMPLETE_DATE`, `HIDE_BIRTHDAY`, `TAG`, `TAG_DESCRIPTION`.
- Dropped from the legacy script: `BACKGROUND`, `FAVOURITE`, `HOBBY` (they were always empty).

## Sync flow

`Synchronizer::run(string $trigger, ?string $staffId = null): array` — `$trigger` is `cli` (manual or nightly cron) or `button`. With `$staffId`, it fetches only that row (`WHERE ID = :id`, bound), skips divisions and the empty-table guard, scopes the existing posts to that one ID, and leaves the last-sync summary untouched.

1. **Lock**: `add_option` lock, which is atomic; a lock older than 1 hour is treated as dead. If a lock is held → log `error` and return.
2. **Fetch** (`OracleSource`): `SELECT <mapped columns>` from `DIVISIONS` and `PHONE_LIST_INTRANET`. On a full sync, divisions are then narrowed to those with at least one current staff member. A connection or query failure, an empty table, or no staffed divisions → log `error` and abort. **Nothing is deleted.**
3. **Plan** (`Planner::plan()`, pure): `$existing` is `source_id => [post_id, source_id, hash]`. It returns `create`, `update` and `delete` lists. Rows with `PH_EXT = 'LEFT'` are treated as absent, so they get deleted.
4. **Apply**: divisions first, then staff. Create/update via `wp_insert_post` / `wp_update_post`, plus `update_field` per mapped column and `_iphl_source_id` / `_iphl_source_hash` meta. Delete via `wp_delete_post($id, true)`. Each create, update and delete gets one log row. A per-row failure → log `error` and continue.
5. **Finish**: log `sync_end` with the counts; on a full sync, store the `intranet_phonelist_last_sync` option (time, trigger, status, message); release the lock.

The hash covers the Oracle row only, so changing how titles are built won't touch existing posts until their Oracle data changes.

## Log table — `{prefix}intranet_phonelist_log`

| Column | Type |
|---|---|
| `id` | bigint unsigned, PK, auto-increment |
| `run_id` | char(36) — UUID per run |
| `created_at` | datetime (UTC) |
| `triggered_by` | varchar(10) — `cli`/`button` (`trigger` is a MySQL reserved word) |
| `action` | varchar(20) — `sync_start`, `created`, `updated`, `deleted`, `error`, `sync_end` |
| `post_type` | varchar(20), nullable |
| `source_id` | varchar(64), nullable |
| `label` | varchar(255) — person/division name |
| `message` | text, nullable |

Indexes: `run_id`, `created_at`, `action`.

## Entry points

- **WP-CLI**: `wp phonelist sync`, for the nightly cron, e.g. `0 2 * * * cd /path && wp phonelist sync`. Exits non-zero on error. `wp phonelist sync --staff=<id>` syncs one person only.
- **Settings → Phone List** (`manage_options`):
  - Last sync summary.
  - **Sync Now** button (`admin-post.php`, nonce; runs synchronously).
  - The log, as a paginated `widefat` table (100 rows per page, `paginate_links`) filterable by action and run.
  - **Delete all logs**, and **Delete logs older than N days** (nonce + JS confirm).

## Error handling

- Oracle unreachable, bad credentials, query error or empty result: abort before any write, log `error`, surface it in the CLI exit code or as an admin notice.
- Per-row write failure: log it and continue; the run status becomes `completed_with_errors`.
- `oci8` not loaded: admin notice on the settings page; sync aborts with a logged `error`.

## Testing

- `tests/plan-check.php`: assert-based checks of the planner (create, update, unchanged, delete, `LEFT`, single-sync scope), string IDs, staffed divisions, titles, birthdays and staff filters. Run with `ddev exec php -d zend.assertions=1 tests/plan-check.php`.
- Manual: `ddev wp phonelist sync` against real Oracle (read-only account), then check the posts and the log.

## Git

- Commit `.ddev/config.yaml` and `.ddev/web-build/`. Ignore the rest of `.ddev/`, plus `.env`, `wp/` and `vendor/`.
- No credentials, personal data or Instant Client binaries go into git. The Dockerfile downloads Instant Client at build time.

## Out of scope

- A front-end phone list or birthday display (themes build their own with `Phonelist` or the REST API).
- Syncing companies from Oracle (they're managed by hand).
- Syncing anything back to Oracle.
