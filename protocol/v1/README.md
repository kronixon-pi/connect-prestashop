# Kronixon Connect protocol v1

The contract between **Kronixon PI** (the platform) and a store-side **Connect module** — today
[Kronixon Connect for PrestaShop](../../README.md). This document, the JSON Schemas in
[`schemas/`](schemas/) and the signature vectors in [`vectors.json`](vectors.json) are the public
source of truth; the platform vendors a locked copy of `protocol/v1/*` and fails its tests when the
copy drifts, so a protocol change is a pull request in both repositories.

Status: **v1 draft** (frozen at the first stable module release, 1.0.0). Licence: AFL-3.0, like the module.

## 1. Parties and hosts

| Party | Address |
| --- | --- |
| Connect service of the platform | `https://connect.kronixon.com/v1/…` |
| Module (PrestaShop) | `https://<shop>/module/kronixonconnect/api?path=<endpoint>` or, without friendly URLs, `https://<shop>/index.php?fc=module&module=kronixonconnect&controller=api&path=<endpoint>` |

The platform never touches the shop's database, files or the PrestaShop Webservice: everything goes
through the module's endpoints. The module talks only to the Connect host built into it.

`path` names the endpoint: `capabilities`, `v1/capabilities` and `/v1/capabilities` are the same
endpoint, whose **logical path** is `/v1/capabilities`. The logical path, not the transport URL, is
signed, so a signature is the same with and without friendly URLs. Another version prefix
(`/v2/…`) answers `400 protocol_version_unsupported`.

## 2. Transport

- HTTPS only (plain `http://` only against a platform in development mode). TLS certificates are verified.
- Methods: `GET`, `POST`, `DELETE`. A host that blocks `DELETE` sends `POST` with
  `X-HTTP-Method-Override: DELETE`; the override is honoured only on `POST` and only for `DELETE`,
  and the request is signed as `DELETE`. Any other method answers `405 method_not_allowed`.
- Bodies are `application/json; charset=utf-8`, UTF-8 without BOM.
- Money is a **string** with a dot and up to 6 decimals (`"99.990000"`), never a JSON number.
  Quantities are integers. Dates are ISO 8601 in UTC with `Z` (`2026-09-30T12:40:00Z`); signature
  timestamps are unix seconds.
- Shop-side ids (`external_id`, `product_id`, …) are decimal strings; `"0"` means none / the base product.
- Translated fields are objects keyed by the shop's 2-letter language `iso` (`{"bg": "…", "en": "…"}`);
  `GET profile` maps `iso ↔ id_lang ↔ locale`. On create a missing language is filled from the shop's
  default language; on update a missing language is left as it is.
- A request body may be sent with `Content-Encoding: gzip`; **signatures always cover the
  uncompressed bytes**. A response is gzip-compressed when the client sends `Accept-Encoding: gzip`
  and the body is at least 1 KB; again the signature covers the uncompressed body.
- Receivers ignore members they do not know. Within v1 a change only **adds optional** members,
  endpoints or enum values announced through `capabilities`; anything else is `/v2`.

## 3. Headers

| Header | Direction | Value |
| --- | --- | --- |
| `X-KX-Protocol` | every request and response | `v1`; a request without it answers `400 protocol_version_unsupported` |
| `X-KX-Store` | signed requests | the store's UUID (`store_id`) issued at pairing |
| `X-KX-Timestamp` | signed requests and responses | unix seconds of the sender |
| `X-KX-Nonce` | signed requests | a UUID (36 characters), never reused |
| `X-KX-Signature` | signed requests and responses | lower-case hex HMAC-SHA256 |
| `X-KX-Key-Id` | signed requests and responses | `cur` or `prev` (which secret signed; absent = try `cur`, then `prev`) |
| `Idempotency-Key` | optional on `POST`/`DELETE` | a UUID; same key + same body → the stored answer with `X-KX-Idempotent-Replay: true`; same key + another body → `409 idempotency_conflict` |
| `X-KX-Request-Id` | optional request, always response | echoed when it is 1–64 of `[A-Za-z0-9._-]`, otherwise a UUID is issued; also `error.request_id` |
| `X-KX-Module-Version`, `X-KX-Time-Used` | signed module responses only (§4.3) | module semver; milliseconds spent |
| `User-Agent` | requests | `KronixonConnect/<version> (prestashop <ps>; php <php>)` or `KronixonPlatform/<version>` |

Module answers never set cookies and carry `Cache-Control: no-store`.

## 4. Signatures

### 4.1 Shared secret

At pairing the platform issues `kxs_` + 32 base62 characters (36 ASCII characters). **The HMAC key is
that string itself (its UTF-8 bytes), not a decoding of it.** A pairing has a current secret (`cur`)
and, for 24 hours after a rotation, the previous one (`prev`).

### 4.2 Canonical request

```
METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256hex(BODY)
```

Five lines joined by `\n`, no trailing newline.

- `METHOD` — upper case, after the method override (`DELETE`, not `POST`).
- `PATH` — the logical path `/v1/<endpoint>`, then `?` and the canonical query when the endpoint has
  parameters; no `?` when it has none.
- Canonical query:
  1. split the raw query on `&`, each part on its first `=` (a part without `=` has an empty value);
     empty parts are dropped;
  2. decode key and value as `application/x-www-form-urlencoded` (`+` is a space, `%XX` a byte);
  3. drop the **transport parameters** `fc`, `module`, `controller`, `path`, `id_lang`, `isolang`, `id_shop`;
  4. encode key and value with RFC 3986 percent-encoding (PHP `rawurlencode`: everything except
     `A–Z a–z 0–9 - _ . ~` is encoded, upper-case hex, space is `%20`);
  5. sort the pairs byte-wise by encoded key, then by encoded value; keep duplicates;
  6. join as `key=value` with `&`.
- `TIMESTAMP` — the decimal unix seconds of `X-KX-Timestamp`.
- `NONCE` — `X-KX-Nonce` as sent.
- `BODY` — the raw, uncompressed request body; an empty body hashes to
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- `X-KX-Signature = hex(hmac_sha256(secret, canonical))`, lower case. Receivers accept upper-case hex
  and compare with a constant-time function (`hash_equals`).

### 4.3 Canonical response

```
RESPONSE \n REQUEST_NONCE \n TIMESTAMP \n STATUS \n sha256hex(BODY)
```

A signed response binds the answer to the request that asked for it (its nonce), to the responder's
own `X-KX-Timestamp`, to the HTTP status and to the uncompressed body. The literal first line keeps
request and response strings apart. **Both sides reject an unsigned or wrongly signed `2xx`.** Error
answers are signed when the responder holds a secret; a module that is not paired has none, so its
`403 store_unpaired` is unsigned — clients must not trust any data in an unsigned answer.

Headers of a module answer:

| Answer | Headers |
| --- | --- |
| Unsigned (any answer before a verified signature: `400`, `403 store_unpaired`, `403 ip_not_allowed`, `401`, `405`, `413`, `500` of an unpaired module) | `X-KX-Protocol`, `X-KX-Request-Id`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, nofollow` — nothing else, in particular no module version, so an unauthenticated caller cannot fingerprint a release |
| Signed (after a verified request) | the unsigned set plus `X-KX-Timestamp`, `X-KX-Signature`, `X-KX-Key-Id`, `X-KX-Module-Version`, `X-KX-Time-Used` (and `X-KX-Idempotent-Replay` on a replay) |

### 4.4 Verification order

1. Parse the request (`400 bad_request`, `405`, `413 payload_too_large`); check `X-KX-Protocol` (`400`).
2. Is the receiver paired / is the store known and not revoked (`403 store_unpaired` / `403 store_revoked`)?
   No endpoint, known or not, is revealed before this point.
3. Module: the caller's IP against the optional IP allowlist (`403 ip_not_allowed`), **before** any
   signature work.
4. Format of timestamp, nonce and signature (`401 signature_invalid`).
5. Clock window: `|receiver_now − X-KX-Timestamp| ≤ 300` seconds, else `401 timestamp_out_of_window`;
   every error carries `server_time` so the sender can correct its clock.
6. HMAC against the candidate keys: `X-KX-Key-Id` narrows to that key; without it `cur`, then `prev`
   while it is valid (`401 signature_invalid`).
7. Nonce: first use within 10 minutes, else `401 nonce_reused`. The nonce is stored **after** a valid
   signature, so a forged request cannot burn a nonce. Platform: Redis `SET NX EX 600`; module: table `kx_nonce`.
8. Route: unknown endpoint `404 not_found`, wrong method `405 method_not_allowed`.

### 4.5 Rotation and revocation

The platform sends `POST rotate {secret_sealed, prev_valid_until}` signed with the current secret.
From the module's `200` on, the new secret is `cur`; the old one is `prev` until `prev_valid_until`
(24 h) and both verify. A rotation that verifies with `prev` is a retry whose first answer was lost
(the platform still holds the secret that is `prev` on the module): the module replaces only `cur` and
keeps `prev` with its original expiry, so the secret the platform never confirmed stops verifying.
Outgoing messages are signed with `cur`. A revocation drops the secret at once
without a `prev` period; later requests answer `403 store_revoked`. Scheduled rotation: every 180 days.

### 4.6 Vectors

[`vectors.json`](vectors.json) holds 8 vectors that every implementation must reproduce: the two
examples of the original specification, query sorting and encoding, an empty body without a query,
a gzip body, `X-HTTP-Method-Override: DELETE`, the `prev` key after a rotation and a signed response.
The secrets in it are public test values.

## 5. Pairing (summary)

1. In the platform the user starts "Connect a store" for a URL and gets a code `XXXX-XXXX`
   (8 symbols of `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, dash and case ignored, 10 minutes, single use,
   5 attempts per code, 10 attempts per hour per IP).
2. In the module the administrator enters the code. The module creates an X25519 key pair and sends
   the unsigned `POST /v1/pair` ([schema](schemas/platform/pair.json)).
3. The platform answers `201` with `store_id` and the secret sealed to the module's public key
   (`crypto_box_seal`). The module opens it, checks `platform_public_key` against its pinned key and
   stores the secret encrypted (PrestaShop `PhpEncryption` with `_NEW_COOKIE_KEY_`).
4. The module calls the signed `GET /v1/stores/{store_id}/ping`; the platform calls the signed
   `GET capabilities` on the declared store URL (proof of control over the domain) and only then
   marks the store connected.
5. Re-pairing the same URL from the same tenant keeps `store_id`. Another tenant can pair a URL only
   after it has been unpaired (`409 store_already_paired`).

## 6. Endpoints (Phase 0)

Every module endpoint is signed; there is no public module endpoint. Batches: **≤ 100 entities** per
entity endpoint, **≤ 1 000 rows** for `prices/bulk` and `stock/bulk`, ≤ 50 images (above:
`413 batch_too_large`). Items are processed in order, each in its own transaction; partial success is
normal and reported per item ([`item_result`](schemas/common.json)). When the time budget (25 s) runs
out the answer says `continue: true` and the client resends **only** the `skipped` items, in order.
`GET catalog/*` pages are ordered by id ascending with an opaque `cursor`, `limit` 1–500 (1–1 000 for
prices and stock), `since` (ISO 8601) and `until_id` (an upper id bound, for parallel id-range slices);
`meta.max_id` reports the highest id.
`GET catalog/variants?product_id_in=1,2,…` reads the combinations of up to 100 products in one walk,
ordered by product id, then combination id; it does not combine with `product_id` or `until_id`. A module
that serves it announces `capabilities.catalog.variants_product_id_in: true`; without that member a
client reads one `product_id` at a time.

### 6.1 Module endpoints

| Endpoint | Schema | Spec | Module PR |
| --- | --- | --- | --- |
| `GET /v1/capabilities` | `module/capabilities.json` | §2.1, §6.1 | M1 |
| `GET /v1/profile` | `module/profile.json` | §6.2 | M1 |
| `GET /v1/health` | `module/health.json` | §6.31 | M1 |
| `POST /v1/rotate` | `module/rotate.json` | §4.3, §6.32 | M1 |
| `POST /v1/unpair` | `module/unpair.json` | §3.4, §6.32 | M1 |
| `GET /v1/catalog/products` | `module/catalog-products.json` | §6.3 | M2 |
| `GET /v1/catalog/variants` | `module/catalog-variants.json` | §6.4 | M2 |
| `GET /v1/catalog/categories` | `module/catalog-categories.json` | §6.5 | M2 |
| `GET /v1/catalog/attributes` | `module/catalog-attributes.json` | §6.6 | M2 |
| `GET /v1/catalog/attribute-values` | `module/catalog-attribute-values.json` | §6.7 | M2 |
| `GET /v1/catalog/brands` | `module/catalog-brands.json` | §6.8 | M2 |
| `GET /v1/catalog/suppliers` | `module/catalog-suppliers.json` | §6.9 | M2 |
| `GET /v1/catalog/images` | `module/catalog-images.json` | §6.10 | M2 |
| `GET /v1/catalog/stock` | `module/catalog-stock.json` | §6.11 | M2 |
| `GET /v1/catalog/prices` | `module/catalog-prices.json` | §6.12 | M2 |
| `GET /v1/catalog/tags` | `module/catalog-tags.json` | §6.13 | M2 |
| `POST /v1/products` | `module/products.json` | §6.14 | M3 |
| `POST /v1/variants` | `module/variants.json` | §6.15 | M3 |
| `POST /v1/categories` | `module/categories.json` | §6.16 | M3 |
| `POST /v1/attributes` | `module/attributes.json` | §6.17 | M3 |
| `POST /v1/attribute-values` | `module/attribute-values.json` | §6.18 | M3 |
| `POST /v1/brands` | `module/brands.json` | §6.19 | M3 |
| `POST /v1/tags` | `module/tags.json` | §6.20 | M3 |
| `POST /v1/seo/redirects` | `module/seo-redirects.json` | §6.26 | M3 |
| `POST /v1/prices/bulk` | `module/prices-bulk.json` | §6.21 | M4 |
| `POST /v1/stock/bulk` | `module/stock-bulk.json` | §6.22 | M4 |
| `POST /v1/images` | `module/images.json` | §6.24 | M4 |
| `DELETE /v1/images` | `module/images-delete.json` | §6.25 | M4 |
| `POST /v1/maintenance/reindex` | `module/maintenance-reindex.json` | §6.28 | M4 |
| `POST /v1/maintenance/cache-flush` | `module/maintenance-cache-flush.json` | §6.29 | M4 |
| `POST /v1/maintenance/facets` | `module/maintenance-facets.json` | §6.30 | M4 |
| `GET /v1/events` | `module/events.json` | §6.34 | M5 |
| `POST /v1/events/ack` | `module/events-ack.json` | §6.34 | M5 |

Not in Phase 0 (no schema yet; capability `false` until then): `POST specific-prices` (`specific_prices`),
`POST/GET blog/posts`, `POST blog/categories`, `POST blog/import` (`blog.enabled`), `GET orders` (`orders.read`).

In module **0.1.0** (this skeleton) no endpoint is implemented: every `/v1/…` request answers
`403 store_unpaired`, unsigned, because pairing arrives with M1.

### 6.2 Platform endpoints used by the module

| Endpoint | Schema | Signed | Platform PR |
| --- | --- | --- | --- |
| `GET /v1/status` | `platform/status.json` | no (probe) | B1 |
| `POST /v1/pair` | `platform/pair.json` | no (the code is the secret) | B2 |
| `GET /v1/stores/{store_id}/ping` | `platform/ping.json` | yes | B2 |
| `POST /v1/stores/{store_id}/unpair` | `platform/unpair.json` | yes | B2 |
| `POST /v1/stores/{store_id}/rotate-request` | `platform/rotate-request.json` | yes | B2 |
| `POST /v1/stores/{store_id}/events` | `platform/store-events.json` | yes | C3 |
| `GET /v1/module/latest` | `platform/module-latest.json` | yes | E |

Each schema file has `$defs.query` (GET) or `$defs.request` (POST/DELETE) and `$defs.response`, plus
`x-kx-endpoint` with method, path and whether it is signed. Shared types are in
[`schemas/common.json`](schemas/common.json); events in [`schemas/events.json`](schemas/events.json).
Every `$id` is `https://connect.kronixon.com/protocol/v1/schemas/<file>`. `$id`s are identifiers, not
download URLs — map them to `protocol/v1/schemas/` locally (register the files in your validator's
schema registry; nothing is fetched over the network).

## 7. Errors

Every non-2xx answer is:

```json
{ "error": { "code": "…", "message": "…", "details": {}, "request_id": "…", "server_time": 1790772000, "retry_after": 30 } }
```

`details` and `retry_after` are optional; `message` is short English for logs, never shown to end
users as is, and never contains secrets, signatures, host paths or stack traces.

| `code` | HTTP | Meaning | Client retries? |
| --- | --- | --- | --- |
| `protocol_version_unsupported` | 400 | `X-KX-Protocol` missing or unknown, or a non-v1 path | no |
| `bad_request` | 400 | invalid JSON, missing/invalid `path`, bad encoding | no |
| `signature_invalid` | 401 | signature does not match or is malformed | no (check the secret) |
| `timestamp_out_of_window` | 401 | outside ±300 s; see `server_time` | yes, after correcting the clock |
| `nonce_reused` | 401 | replayed nonce | no |
| `store_unpaired` | 403 | the module is not paired | no |
| `store_revoked` | 403 | (platform) the secret was revoked | no |
| `ip_not_allowed` | 403 | outside the module's IP allowlist (checked before the signature) | no |
| `capability_disabled` | 403 | the feature is off on this shop | no |
| `not_found` | 404 | unknown endpoint or entity | no |
| `method_not_allowed` | 405 | wrong method (`details.allowed`) | no |
| `busy` | 409 | another write holds the shop's write lock | yes, after `retry_after` |
| `idempotency_conflict` | 409 | same `Idempotency-Key`, different body | no |
| `payload_too_large` | 413 | body above 4 MB before gzip (the module also stops a gzip body that inflates above 4 MB, §9) | no (split) |
| `batch_too_large` | 413 | more than 100 / 1 000 / 50 items | no (split) |
| `validation_failed` | 422 | the body breaks the schema (`details.errors[]`) | no |
| `rate_limited` | 429 | above 120 requests/min | yes, after `retry_after` |
| `module_error` | 500 | unexpected error (logged by the module) | once |
| `maintenance` | 503 | the shop is in maintenance or the module is updating | yes, after `retry_after` |
| `pair_code_invalid` / `pair_code_expired` | 400 / 410 | (pair) wrong or expired code | no |
| `store_already_paired` | 409 | (pair) the URL belongs to another tenant | no |
| `store_url_insecure` | 422 | (pair) not HTTPS | no |
| `module_too_old` | 426 | (pair, platform) below the minimum module version | no (update) |

Platform rule: 401/403/404/405/413/422 are not retried and put the job or item in
`needs_attention`; 409 `busy`, 429 and 503 are retried after `retry_after` (else 5 s × 2ⁿ with jitter,
6 tries); 500 is retried once; a network error counts as 503. Per-item results are not HTTP errors.

## 8. Events (module → platform)

The module records shop changes in an outbox (hooks only insert; no HTTP inside a hook) and sends
them signed to `POST /v1/stores/{store_id}/events`, at most 200 per request and 1 MB, on page visits,
from a cron URL and with backoff (`2^attempts × 30 s`, at most 6 h, 10 attempts). Delivery is
at-least-once; the platform deduplicates by `event_id` and by `(store, type, external_id, occurred_at)`
and treats an event as a hint: it re-reads the entity through `GET catalog/*` instead of trusting the
payload. Changes made by the platform's own requests are not recorded (`origin: kronixon` is
suppressed). `module.heartbeat` is sent every 24 h; more than 48 h without one is a warning. When
webhooks fail, the platform drains the outbox with `GET events` + `POST events/ack`.
Types and payloads: [`schemas/events.json`](schemas/events.json).

## 9. Limits

| Limit | Value |
| --- | --- |
| Time budget per module request | 25 s (configurable up to 30 s); writers stop with `continue: true` when less than 1.5 s is left |
| Request body | 4 MB before gzip (the bytes as sent). Extra zip-bomb guard: the module also stops decoding a gzip body once it inflates above 4 MB (`413`) |
| `GET catalog/*` answer | ≤ 8 MB (the module lowers `limit` itself) |
| Entity batch / bulk rows / images | 100 / 1 000 / 50 per request; images ≤ 20 MB each, ≤ 100 MB per request |
| Rate limit (module) | 120 requests/min per shop |
| Clock window / nonce memory | ±300 s / 10 min |
| `prev` secret validity after rotation | 24 h |
| Webhook batch | ≤ 200 events, ≤ 1 MB |
| Pairing code | 8 symbols, 10 min, single use, 5 attempts, 10 per hour per IP |

A shop on a constrained host announces lower limits in `GET capabilities.limits`; the platform follows them.

## 10. Versioning and compatibility

- The protocol major version is in every path (`/v1`) and in `X-KX-Protocol`. An incompatible change
  is `/v2`; a module may serve both during a migration.
- The module is versioned with semver. The platform keeps a "minimum module version per feature"
  table and blocks only the features an older module cannot do, with a clear message.
- Supported shops: PrestaShop 1.7.8 – 9.x on PHP 7.4 – 8.5; version differences live only in the
  module's `Compat` layer, and what a shop cannot do is a `false` capability, never an error.

## 11. Security notes

Replay is stopped by the nonce store, the clock window and the signature over the body; a stolen
shop backup does not reveal the secret (encrypted with a key outside the database dump); a forged
platform is stopped by the built-in host and the pinned Ed25519 key of module releases; the platform
validates every inbound payload against these schemas and never executes HTML from a shop. Report
vulnerabilities as described in [SECURITY.md](../../SECURITY.md).
