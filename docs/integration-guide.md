# Intranet Phonelist API: integration guide for Data Hub

Data Hub pushes companies, divisions and staff to the intranet phone list. This guide covers how to sign requests, which endpoint to use when, and what every response means. The exact contract (every field, type and response) is in [`openapi.yaml`](openapi.yaml).

**Base URL:** `https://<intranet host>/wp-json/phonelist/v2`

## The rules in brief

1. **Records are identified by your ID.** `id` is the Data Hub ID of the company, division or staff member. We never return our own IDs, and we never match records by name.
2. **Every record carries `updated_at`.** It's an ISO 8601 date and time with a timezone offset, for example `2026-10-15T09:30:00+08:00`. If you send something older than what we hold, or older than when we deleted the record, we refuse it with `409`. That way retries and out-of-order deliveries can't overwrite newer data or bring back someone who has left.
3. **Every write is signed** with the shared secret (see Signing).
4. **Send our format, not Oracle's:**
   - Dates are `YYYY-MM-DD`.
   - Booleans are `true` or `false`.
   - Empty values are `null`.
   - Field names are exactly as listed in [`openapi.yaml`](openapi.yaml).
   - Fields we don't know are ignored.
5. **Errors are [Problem Details](https://www.rfc-editor.org/rfc/rfc9457)** (`application/problem+json`). Validation errors list each field that failed.

## Signing

Send this header with every request:

```
X-Phonelist-Signature: t=<unix seconds>,v1=<signature>
```

`<signature>` is the lowercase hex HMAC-SHA256, keyed with the shared secret, of:

```
<t>.<METHOD>.<path>.<raw body>
```

- **`<METHOD>`:** `PUT`, `PATCH`, `DELETE`, `POST` or `GET`.
- **`<path>`:** the path and query string exactly as in the request URL. For example `/wp-json/phonelist/v2/staff/10423`, or `/wp-json/phonelist/v2/staff/10099?updated_at=2026-10-15T09:30:00%2B08:00`. Because the method and path are signed, a signature for one record can't be reused for another.
- **`<raw body>`:** the exact bytes you send. Don't re-encode the JSON after signing. With no body, it's empty, so the string ends with the final `.`.
- **Timing:** `t` must be within 5 minutes of our clock, so keep your servers on NTP.
- **Failures:** a missing, malformed, expired or wrong signature gets `401`.

All three examples below give the same header for `t = 1760000000`, the secret `example-secret`, `PUT /wp-json/phonelist/v2/staff/10423` and the body `{"id":"10423"}`:

```
t=1760000000,v1=a46069269697906d9e15425513a80bd4c083a5902d748fdb9d73dc4d91a49fed
```

Use it to check your implementation before connecting.

**PHP**
```php
$t = time();
$header = "t=$t,v1=" . hash_hmac('sha256', "$t.$method.$path.$body", $secret);
```

**Python**
```python
import hmac, hashlib, time
t = int(time.time())
header = f"t={t},v1=" + hmac.new(secret.encode(), f"{t}.{method}.{path}.{body}".encode(), hashlib.sha256).hexdigest()
```

**Shell (curl + openssl)**
```bash
t=$(date +%s); path=/wp-json/phonelist/v2/staff/10423
sig=$(printf '%s' "$t.PUT.$path.$body" | openssl dgst -sha256 -hmac "$secret" | sed 's/^.* //')
curl -X PUT "https://<intranet host>$path" -H 'Content-Type: application/json' \
     -H "X-Phonelist-Signature: t=$t,v1=$sig" --data-raw "$body"
```

## Which endpoint to use

| You want to… | Use | Reply |
|---|---|---|
| Create a record, or replace all of its fields | `PUT /{type}/{id}` | `201` created · `200` updated or unchanged |
| Change a few fields of a record we already have | `PATCH /{type}/{id}` | `200` · `404` if we don't have it |
| Remove a record | `DELETE /{type}/{id}?updated_at=…` | `204`, including when it's already gone |
| Send many changes at once (mixed types and operations) | `POST /batch` | `202` + job |
| Send the complete list of one type | `PUT /{type}` | `202` + job |
| Check a batch or snapshot | `GET /jobs/{job}` | `200` |
| Check what we hold | `GET /{type}/{id}`, `GET /{type}` | `200` |

`{type}` is `companies`, `divisions` or `staff`.

### PUT: create or replace

Send the whole record. **Fields you leave out are cleared.** Use PUT whenever you have the full record, because it's the most robust option: a missed message is fixed by the next PUT.

```http
PUT /staff/10423
{"updated_at":"2026-10-15T09:30:00+08:00","first_name":"Jane","last_name":"Citizen","status":"active","division_id":"JWH","reports_to":"10007"}
```

If the `id` in the body is there, it must match the URL.

### PATCH: change some fields

Only the fields you send change; `null` clears a field. `updated_at` is always required. You can't create a record with PATCH; use PUT.

### DELETE

`updated_at` is required, either as JSON in the body (`{"updated_at":"…"}`) or in the query string. In a query string, **encode `+` as `%2B`**:

```
DELETE /staff/10099?updated_at=2026-10-15T09:30:00%2B08:00
```

### POST /batch: many operations

Use this for up to 500 operations in one request, in any mix of types:

```json
{"operations": [
  {"op": "put",    "type": "divisions", "id": "BNB",   "data": {"updated_at": "2026-10-15T09:30:00+08:00", "name": "WA Country Builders (Bunbury)"}},
  {"op": "patch",  "type": "staff",     "id": "10423", "data": {"updated_at": "2026-10-15T09:30:00+08:00", "division_id": "BNB"}},
  {"op": "delete", "type": "staff",     "id": "10099", "data": {"updated_at": "2026-10-15T09:30:00+08:00"}}
]}
```

- **Order:** operations run in the order sent.
- **Deletes** must include `updated_at` in `data`.
- **Failures:** each operation succeeds or fails on its own. An invalid operation doesn't stop the rest.
- **References:** it doesn't matter whether a staff member arrives before their division or manager. Links are made once both exist.

### PUT /{type}: snapshot

Send every record of one type that should exist (up to 5,000):

```json
{"records": [ {"id": "JWH", "updated_at": "…", "name": "JWH Group"}, … ]}
```

- **Each record** is upserted, as with PUT.
- **Records we hold that aren't in the snapshot are deleted.** The one exception is a record updated after the snapshot's newest `updated_at`, which is kept.
- **Safeguards, so a failed or partial export can't wipe the phone list:**
  - An empty snapshot is refused (`422`).
  - If any record has no usable `id` (it must be a non-empty string, not a number), nothing is deleted.
  - If the snapshot would delete more than 10 records **and** more than 10% of those we hold, nothing is deleted.
  - In both of the last two cases, the job's results end with an `error` entry explaining why. To remove many records on purpose, send DELETEs.
- **Recommended schedule:** send a snapshot of each type nightly, divisions first, then staff. It corrects anything a missed message left behind.

### Jobs and retries

Batches and snapshots are processed in the background. The reply comes straight away:

```http
HTTP/1.1 202 Accepted
Location: https://…/wp-json/phonelist/v2/jobs/3dd928bf-…
{"job":"3dd928bf-…","status":"queued","links":{"self":"https://…/jobs/3dd928bf-…"}}
```

Poll `GET /jobs/{job}` until `status` is `done` or `failed`. `results` has one entry per item (`created`, `updated`, `unchanged`, `deleted`, `stale`, `invalid`, `not_found`, `error`), with `errors` for invalid ones. Jobs run one at a time, in the order they arrived. Their results are kept for 30 days.

**Retries:**
- **Single records:** safe to resend as they are. A repeat with the same `updated_at` is `unchanged`, and an older one is `409`.
- **Batches and snapshots:** send an `Idempotency-Key` header, a unique value per logical request (up to 100 characters). Resending with the same key returns the original job (`200`) instead of creating a new one.
- **Back-off:** on `503` or a network error, retry after 5 seconds, then 30 seconds, then 2 minutes, then 10 minutes.

## Records

### Companies

| Field | Type | Notes |
|---|---|---|
| `id` | string, required | |
| `updated_at` | timestamp, required | |
| `name` | string, required | |

### Divisions

| Field | Type | Notes |
|---|---|---|
| `id` | string, required | |
| `updated_at` | timestamp, required | |
| `name` | string, required | As it should be shown, e.g. `WA Country Builders (Bunbury)` |
| `phone` | string | Any format; stored as E.164 |
| `address_line1`, `address_line2`, `postcode`, `postal_address` | string | |

**Which company a division belongs to is set in the intranet, not by Data Hub.** A new division is put under the company with the same name, which is created if it doesn't exist. Intranet staff can move it to another company, and everyone in the division follows automatically.

### Staff

| Field | Type | Notes |
|---|---|---|
| `id` | string, required | |
| `updated_at` | timestamp, required | |
| `first_name`, `last_name` | string, required | |
| `preferred_name` | string | Shown instead of `first_name` when set |
| `status` | string, required | `active` stays on the phone list. **Any other value removes the person**, the same as a DELETE. The list of values is still being finalised. |
| `email` | string | Must be a valid address |
| `mobile`, `phone` | string | Any format; stored as E.164. `phone` is the direct number. |
| `extension` | string | VoIP extension |
| `home_phone` | string | Private: never shown on the intranet. Also used for after-hours numbers. |
| `position`, `position_number`, `job`, `job_type`, `award_code` | string | |
| `department_code`, `department` | string | |
| `division_id` | string | The division's `id` |
| `company_id` | string | Stored as sent; the company shown comes from the division |
| `reports_to` | string | The manager's staff `id` |
| `start_date`, `on_leave_until` | date | `YYYY-MM-DD` |
| `date_of_birth` | date | Private: only the day and month are used (birthdays) |
| `hide_birthday` | boolean | |
| `photo_url` | URL | `http(s)`. Downloaded when it changes; the same URL isn't fetched twice. |

## Errors

| Status | When | Example body |
|---|---|---|
| `400` | The body isn't a JSON object | `{"type":"about:blank","title":"Bad request","status":400,"detail":"The body must be a JSON object."}` |
| `401` | Signature missing, malformed, expired or wrong | `{"type":"about:blank","title":"Unauthorized","status":401,"detail":"Signature mismatch."}` |
| `404` | PATCH or GET of an `id` we don't have; unknown job or route | `{"type":"about:blank","title":"Not found","status":404,"detail":"No record with this ID. Send the whole record with PUT to create it."}` |
| `409` | `updated_at` is older than the stored record | `{"type":"about:blank","title":"Older than the stored record","status":409,"detail":"Older than the stored record (2026-10-15T10:00:00+08:00)."}` |
| `413` | Body over 5 MB, batch over 500, snapshot over 5,000 | `{"type":"about:blank","title":"Request too large","status":413,"detail":"At most 500 operations per request."}` |
| `422` | Missing or invalid fields; empty batch or snapshot | `{"type":"about:blank#validation","title":"Invalid record","status":422,"detail":"","errors":[{"field":"last_name","message":"Required."}]}` |
| `503` | Signing isn't configured on the site, or the record is busy; retry later | `{"type":"about:blank","title":"Could not be saved","status":503,"detail":"The record is busy; try again."}` |

## Changelog

- **2.0.0:** first version of the push API.
