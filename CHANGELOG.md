# Changelog

All notable changes of the module are listed here; versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.5.0]

### Added

- `POST prices/bulk` and `POST stock/bulk`: up to 1 000 rows in chunks of 100, each chunk in one transaction, a
  result per row. A chunk reads its rows with their row locks, so `expected_price` / `expected_quantity` make a value
  changed in the shop, also during the request, a `conflict` that writes nothing; rows held by another session past
  the wait are `skipped` (`rows_locked`). A row the shop already holds is `unchanged`; a new price keeps the unit
  price on every version. Announced as `prices.bulk` and `stock` (`stock_locations` stays false: one stock per shop).
- `POST maintenance/reindex`, `cache-flush` and `facets`, bounded by `budget_s` and walked by a `cursor`. Announced as
  `search_reindex`, `cache_flush` and, with ps_facetedsearch active, `facets`.
- `facets` of `POST attributes` are written (`layered_category` and the shop's filter templates) and read in `GET
  catalog/attributes` when the shop has ps_facetedsearch; without it they stay `facets_unsupported`.
- `POST images` and `DELETE images` (`images`, `image_alt`): ids kept by `kx_key` + sha256, files replaced in place,
  alt texts per language, the strategies `sync`/`append`/`replace_all`, order and one cover, combination links. A
  request that dies while an image is written leaves no half image, and the retry makes exactly one.
  A request whose items the time budget skips removes no image.
  A fetch ends within the time budget.
  Pictures above 50 megapixels are refused from their header.

### Changed

- Logos and images are fetched only from the platform the shop is paired with (its Connect host and port, and the
  optional `download_hosts` of the pair answer), at public addresses outside development: `download_host_refused`.
  "Public" is an explicit IANA table, also for IPv4-mapped IPv6 on PHP 7.4.
  A shop paired before 0.5.0 knows no `download_hosts`: logos and images from another host answer
  `download_hosts_unknown` until it **pairs again** (before, logos came from any HTTPS host).

### Fixed

- One module whose hook throws no longer stops the update hooks the module runs after showing a created object
  (PrestaShop 1.7.8 lets the exception through).

## [0.4.0]

### Added

- `POST products` (by field group), `POST variants` and `POST seo/redirects`, announced as `products.write`,
  `variants`, `attribute_values_custom`, `seo` and `redirects`. Old addresses and inactive categories are redirected
  by the module on the storefront, never away from a page of the shop and only to an active one.
- `POST categories`, `attributes`, `attribute-values`, `brands` and `tags`: batches of up to 100 items, each in its
  own transaction, with a result per item and the `content_hash` the shop holds afterwards. Announced as the
  capabilities `categories`, `attributes` and `tags`.
- `Idempotency-Key` on every `POST`/`DELETE`: a repeated request gets the stored answer for 24 hours, another request
  with the same key `409 idempotency_conflict`. A key that is not a UUID is now refused (`400`).
- `kx_pending` and the hook `actionObjectAddAfter`: what a failed item or a request that died has left half-written is
  removed, at the latest before the next write (`cleanup_failed` until then). An upgrade adds both.
- A created product, category or brand stays inactive until its item is complete; then the module runs PrestaShop's
  hooks of an update for it, so other modules see it active. A read deletes what is left over only when there is
  some, and only then takes the write lock.
- Releases are built reproducibly and signed ([docs/RELEASING.md](docs/RELEASING.md)); the first one is 0.3.2 on the
  staging channel.

## [0.3.2]

### Added

- `GET catalog/variants?product_id_in=1,2,…`: the combinations of up to 100 products in one walk, ordered
  by product id, then combination id, paged by `limit` and a cursor naming product and combination. An
  empty list, a member that is not an id, more than 100 distinct ids, or `product_id_in` with `product_id`
  or `until_id` answer `400 bad_request`. Announced as `capabilities.catalog.variants_product_id_in: true`.
  `product_id=` and the id-range walk are unchanged.

### Fixed

- A `catalog/variants` page without items (for example `product_id=` of a product without combinations in
  the shop) runs only its base query. It also read the base stock row of every product of the shop
  (`stock_available … id_product_attribute IN (0)`): about 120 000 rows at 100 000 products.

## [0.3.1]

### Changed

- A `catalog/prices` page reads its products through `product` by primary key: on PrestaShop 8.2+
  it read and sorted every product of the shop per page (about 200 rows per item at 100 000 products,
  now about 5).
- A `catalog/variants` page reads its option values with two queries by the page's ids: it read every
  combination row of the shop per page (about 84 rows per item at 20 000 combinations, now about 7).
  Answers are unchanged; a variants page costs 6 queries instead of 5.

## [0.3.0]

### Added

- Catalog reads: the eleven `GET catalog/*` endpoints — `products`, `variants`, `images`, `prices`,
  `stock`, `categories`, `brands`, `suppliers`, `attributes`, `attribute-values`, `tags` — ordered by id,
  with a checked cursor, `until_id` (inclusive) and `meta.max_id` for parallel id ranges, `since`,
  multilang fields by iso, net prices in the shop default currency, the default stock location and
  `content_hash` on products and categories in the platform's canonical form (without `kx_id`). Every
  answer is the paired shop's, also on a multistore install. A page costs a bounded number of queries;
  memory is flat over a walk. `capabilities.suppliers` is true.

## [0.2.1]

### Fixed

- A rotation retried after a lost answer (verified with `prev`) replaces only `cur` and keeps `prev` with
  its original expiry, so the platform's secret keeps verifying and the unconfirmed one does not.
- An unpair by the platform also forgets the last pairing outcome (`KX_PAIR_STATE`) and the time of the
  last platform request (`KX_LAST_REQUEST_AT`).
- Uninstall deletes the `access` rows of the back-office tab's roles, which PrestaShop leaves behind.

## [0.2.0]

### Added

- Pairing: the settings page (also *Advanced Parameters → Kronixon Connect*) pairs the shop with a
  code, shows the outcome or the platform's reason with the time to retry, tests the connection and
  unpairs; `POST /v1/pair` with the module's X25519 key, the sealed secret opened and stored encrypted
  (`PhpEncryption` with `_NEW_COOKIE_KEY_`), the signed ping.
- Every `/v1/…` request verified in the protocol's order (clock window, HMAC with `cur`/`prev`, single-use
  nonce in `kx_nonce`), 120 verified requests a minute, one write at a time; every answer after the
  signature signed; endpoints `capabilities`, `profile`, `health`, `rotate`, `unpair`.
- A zero-right Profile and an inactive Employee created at install and removed at uninstall; uninstall
  tells the platform (best effort) before the secrets go.
- `kx_log` (14 days, secrets masked), translations bg and de, an install failure names its step.

## [0.1.0]

### Added

- Skeleton: module class with install/uninstall, the module tables, hooks registered (handlers
  empty until the outbox arrives), the API front controller with its kernel (25 s budget, JSON errors,
  no stack traces), compatibility profiles for PrestaShop 1.7.8 / 8.0 / 8.1–8.2 / 9.x.
- Connect protocol v1: [document](protocol/v1/README.md), JSON Schemas for every Phase-0 endpoint and
  event, 8 signature vectors, request/response signer and verifier.
- Until the shop is paired every `/v1/…` request answers `403 store_unpaired`.
