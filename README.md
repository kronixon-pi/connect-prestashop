# Kronixon Connect for PrestaShop

The free, open-source companion module that links a PrestaShop store to **Kronixon PI**. The
platform never touches the shop's database, files or the PrestaShop Webservice: it talks only to
this module, over the signed [Connect protocol v1](protocol/v1/README.md).

> **Status: 0.3.0, pairing and catalog reads.** The module pairs with Kronixon PI from its settings page,
> verifies the signature of every protocol request, signs every answer and serves `capabilities`,
> `profile`, `health`, `rotate`, `unpair` and the eleven `GET catalog/*` reads. Writes, webhooks and
> self-update arrive in the next versions. Do not install it on a production shop yet.

## Supported versions

| PrestaShop | PHP of the shop | Tested in CI (Docker image) |
| --- | --- | --- |
| 1.7.8.x | 7.4 | `prestashop/prestashop:1.7.8.11-7.4-apache` |
| 8.1.x | 8.1 | `8.1.7-8.1-apache` |
| 8.2.x | 8.1 | `8.2.8-8.1-apache` |
| 9.0.x | 8.4 | `9.0.3-8.4` |
| 9.1.x | 8.4, 8.5 | `9.1.5-8.4` (matrix), `9.1.5-apache` = PHP 8.5 (every PR) |
| 9.2.x | 8.2 | `9.2.0-6.0-classic-8.2-apache` (non-blocking) |

PrestaShop 8.0.x has its own compatibility profile but no CI job. Below 1.7.8 the module refuses to
install. The code uses PHP 7.4 syntax and is checked with PHPCompatibility for PHP 7.4–8.5.
Required PHP extensions: `curl`, `json`, `mbstring`, `openssl`, `zlib`, and `sodium` for pairing (part
of PHP since 7.2 and present in every image above; without it the module installs, and its settings page
says that pairing needs it).

## Install

1. Download `kronixonconnect.zip` from the [releases](https://github.com/kronixon-pi/connect-prestashop/releases)
   (the first release comes with pairing).
2. In the shop's back office: *Modules → Module Manager → Upload a module*, choose the zip.
3. Open *Advanced Parameters → Kronixon Connect* (or *Configure* on the module) and enter the code
   `XXXX-XXXX` that Kronixon PI shows under *Stores → Connect a store*. The page shows the outcome: paired
   (with the workspace), or the platform's reason with the time to try again.

The API front controller answers at `https://<shop>/module/kronixonconnect/api?path=<endpoint>`
(friendly URLs) or `https://<shop>/index.php?fc=module&module=kronixonconnect&controller=api&path=<endpoint>`.

### Pairing and secrets

- The module creates an X25519 key pair at install; the platform seals the shared secret to its public
  key (`crypto_box_seal`), so the secret never crosses the wire in clear.
- The shared secret (and the previous one for 24 h after a rotation) and the key pair are stored in
  `Configuration` encrypted with PrestaShop's `PhpEncryption` and the shop's `_NEW_COOKIE_KEY_`, which
  lives in the parameters file, not in the database: a database dump alone does not reveal them. They are
  never logged or shown.
- Every request is checked in the order of [protocol §4.4](protocol/v1/README.md#44-verification-order):
  pairing, ±300 s clock window, HMAC (`cur`, then a valid `prev`), single-use nonce (`kx_nonce`, 10 min),
  120 verified requests a minute (`KX_RATE_LIMIT`), and writes one at a time (`409 busy`). Answers after a
  verified signature are signed; earlier answers carry no module version.
- *Unpair* on the settings page tells the platform (best effort) and forgets the secrets; uninstalling
  does the same. The platform can unpair or rotate the secret through the signed endpoints.
- The module talks only to the platform URL on its settings page (default `https://connect.kronixon.com`).
  A plain `http://` URL is refused unless the Configuration key `KX_PLATFORM_ALLOW_HTTP` is `1` — for a
  development platform only; it is not on the settings page.

## Catalog reads

The signed `GET catalog/*` endpoints ([protocol §6.1](protocol/v1/README.md#61-module-endpoints)) read
the paired shop (Context shop) and never write. Each answer is exactly its `module/catalog-*.json`
schema: `products`, `variants`, `images`, `prices`, `stock`, `categories`, `brands`, `suppliers`,
`attributes` (features and attribute groups, told apart by `kind`), `attribute-values` (`kind` required) and
`tags`.

- **Order and paging.** Items come in ascending id order (prices and stock: product id, then variant id;
  attributes: id, then kind — a feature before the attribute group with the same id);
  `limit` is 1–500 (1–1 000 for prices and stock), 100 by default; an invalid parameter answers
  `400 bad_request` with `details.errors`. An answer stays under 8 MB: items past that move to the next page.
- **Cursor.** base64url without padding of the JSON `{"id":<last id>}`, or `{"id":<product id>,"v":<variant
  id>}` for prices and stock, `{"id":<id>,"v":0|1}` for attributes (0 a feature, 1 an attribute group). A
  page starts after the cursor's position; `{"id":N}` without `v` starts after every row of id N. Only that
  exact form is accepted (no padding, no other member, integers only, `v` only on the endpoints that write
  it); anything else is `400`. The cursor carries no signature of its own: the request that carries it is signed.
- **Id ranges.** `meta.max_id` is the highest id of the entity (prices and stock: of products; attributes:
  of features and attribute groups; attribute values: of the kind asked), on every page. `until_id` is an **inclusive** upper bound of the same id. A client splits `(0, max_id]` into K
  ranges `(a, b]` and reads each from the cursor `{"id":a}` with `until_id=b`, in parallel.
- **Guarantee of a range walk.** Pages are keyset reads by id (no offsets, no snapshot), so: every row that
  exists for the whole walk comes exactly once, in exactly one range; no row comes twice; a row deleted
  during the walk comes at most once; a row created during the walk comes only if its id is above the
  walk's position in its range and within it — new PrestaShop ids are above the `max_id` the walk started
  from, so they are outside every range and arrive through events or a later `since` read. Tested in
  PHPUnit (K = 1, 3, 8 over ids with gaps, rows created and deleted between pages) and on real shops.
- **`since`** (UTC, compared in the shop's time zone) filters on `date_upd`: the product's for products,
  variants, images and prices; the entity's own for categories, brands and suppliers. Stock, attributes,
  attribute values and tags have no change date, so they ignore it and read every row.
- **Categories.** PrestaShop's Root (no parent) and the shop's Home category are `root: true`, Home also
  `is_home: true`. Pages follow ids, not the tree: parents first is an order by `level`.
- **Values.** Translated fields per shop language by iso (`{}` when a field has no row); prices net, as
  PrestaShop stores them, in the shop default currency (a combination's `price` is the product price plus
  its `price_impact`); stock is the shop's `stock_available` row (its group's when the group shares stock),
  location `default`; dates in UTC; image `sha256`/`kx_key` from `kx_image_map`. Image and brand logo URLs
  come from the shop's own `Link` (friendly URLs, media servers, overrides). `kx_id` comes from
  `kx_entity_map` with the entity types `product`, `variant`, `category`, `brand`, `supplier`, `feature`,
  `attribute_group`, `feature_value` and `attribute`. A feature value with `custom: true` is a per-product
  text value. `value_count` of a feature counts its predefined values; `facets` are not read (no `facets`
  capability).
- **Multistore.** Everything is the paired shop's: a product shared by several shops names only the
  categories, features, suppliers and images that shop has (`*_shop`), and its `brand_id`/`supplier_id`
  are `"0"` when the brand or supplier is not in the shop. What another shop links to a shared product
  changes neither the item nor its `content_hash`. The taxonomy readers answer the shop's own categories,
  brands, suppliers, features and attribute groups, and a value only when its feature or group is the
  shop's too; tags have no shop table and follow the shop's languages.
- **`content_hash`** (products and categories, as v1 declares it): sha256 of the canonical JSON of the item
  without `kx_id`, `date_add`, `date_upd`, `content_hash`, and for products `quantity` and `images[].url`, for
  categories `product_count` (a counter, not content). `kx_id` links the item to the platform; it is not
  content, so linking or unlinking does not change the hash. Canonical as the platform's sync engine: maps sorted by key
  (byte order) at every depth, lists in their order (the module sorts every list whose order means
  nothing: category ids, features, tags, suppliers; images by position), strings in Unicode NFC, no floats,
  amounts as decimals without trailing zeros (`"99.990000"` → `"99.99"`), an empty map `{}`, JSON without
  escaped slashes or Unicode. Vectors: [tests/Unit/Catalog/content-hash-vectors.json](tests/Unit/Catalog/content-hash-vectors.json).
  `fields=basic` hashes the members a basic item carries. The same data gives the same hash on PHP
  7.4–8.5 and PrestaShop 1.7.8–9.x; a value PrestaShop stores differently (a new 9.x product's
  `redirect_type` is `default`, 1.7.8's `404`) is different data.
- **Cost.** A page is a bounded number of queries whatever its size (products 9, `fields=basic` 5, variants 5,
  images 3, prices ≤ 6, stock ≤ 2, categories, brands, suppliers and attribute values 4, attributes ≤ 8,
  tags 2): base rows by id, then one query per related table for the page's ids.
  Measured on 100 000 generated products, limit 500, in process (`tests/Integration/catalog-walk.php`):
  memory flat between pages; the time per products page depends on the machine more than on the
  version: a median of 27–81 ms on GitHub's runners (two matrix runs, every version, the same version up
  to 2× apart between runs) and 105–110 ms on a slower development machine. The matrix prints the
  figures of every run in its job summary.

## What the module adds to the shop

- Tables `kx_nonce`, `kx_idempotency`, `kx_log`, `kx_entity_map`, `kx_image_map`, `kx_redirect`,
  `kx_outbox` ([sql/install.sql](sql/install.sql) says which version uses each; 0.2.0 writes `kx_nonce`
  and `kx_log`, kept 14 days, never with a secret).
- Configuration keys `KX_*`; uninstalling removes the tables and the keys.
- A Back Office menu entry *Advanced Parameters → Kronixon Connect* (the settings page).
- A Profile "Kronixon Connect" without any access right and an inactive Employee in it, the identity the
  module will act as in later versions; uninstalling removes both.
- Hooks on product, stock, category, image, brand, feature and combination changes. In 0.2.0 they do
  nothing; later they only queue an event in `kx_outbox` (no HTTP call inside a hook).
- No core files are changed, no overrides are installed. Translations: English, Bulgarian, German.

## Development

Everything runs in Docker; the host needs `docker` (with compose), `bash`, `curl` and `jq`.

```sh
make install          # development tools into tools/vendor (composer in Docker)
make test             # PHPUnit on PHP 7.4 and 8.5
make check            # PHP-CS-Fixer (PSR-12), PHPCompatibility 7.4–8.5, PHPStan level 6, PHPUnit
make up PS=91         # PrestaShop 9.1.5 + MariaDB 10.11 on http://localhost:8091 with the module
make fixture PS=91    # load the 500-product test fixture into that shop
make down PS=91
make matrix           # the smoke run below on 1.7.8, 8.1, 8.2, 9.0, 9.1
```

The smoke run (`scripts/ps-smoke.sh`, also `make up`) installs, uninstalls and reinstalls the module,
then pairs the shop with [tests/Integration/platform-stub.php](tests/Integration/platform-stub.php) (a
minimal Connect service run by the shop's own PHP) and plays the platform's side with
[tests/Integration/platform-driver.php](tests/Integration/platform-driver.php): the signed `GET
capabilities` check, profile, health, replay/tamper/clock refusals, signed catalog reads, a rotation and an
unpair. Then [tests/Integration/catalog-walk.php](tests/Integration/catalog-walk.php) walks every catalog
reader in K = 1, 3 and 8 id ranges on `KX_WALK_PRODUCTS` products (generated by SQL; default 0 = the shop's
own) and [validate-answers.php](tests/Integration/validate-answers.php) checks every answer against its
schema (needs `make install`): `KX_WALK_PRODUCTS=100000 make smoke PS=91`. Last,
[tests/Integration/multistore.php](tests/Integration/multistore.php) adds a second shop in a second group,
shares a product with it and checks that each shop reads only its own relations of that product.
`scripts/ps/pair.php <platform url> <code>` pairs a local shop from the command line.

`PS` is one of `178`, `81`, `82`, `90`, `91`, `92` ([docker/](docker/)); `PS_TAG` overrides the image tag.
The PrestaShop and MariaDB images are pinned by digest in [docker/images.lock](docker/images.lock), the
only place to change them; `scripts/compose.sh` (used by `make` and the CI scripts) resolves them.
The shops use PrestaShop's documented default admin login and `admin-dev` as the admin folder.

Layout: `kronixonconnect.php` (module class), `controllers/front/api.php` (the only API entry),
`src/` (`Kronixon\Connect\` — `Api`, `Auth`, `Catalog`, `Compat`, `Crypto`, `Install`, `Platform`, `Shop`, `Support`, loaded by the module's
own autoloader; no composer at runtime), `sql/`, `views/`, `protocol/v1/` (protocol document, JSON
Schemas, signature vectors), `tests/` (PHPUnit, the fixture generator), `docker/`, `scripts/`.
Development tools live in `tools/vendor`, because PrestaShop loads a module's `vendor/autoload.php`
at runtime.

### CI

| Workflow | When | What |
| --- | --- | --- |
| `module-ci` | every PR and push to `main` | PSR-12, PHPCompatibility, PHPStan 6, PHPUnit on 7.4 and 8.5, schemas + vectors, smoke install on 9.1.5 (PHP 8.5) with a catalog walk on 10 000 products, commitlint |
| `matrix` | PRs to `main`, label `matrix`, weekly | the smoke run (install, pairing with the stub, signed calls) on the six versions above, catalog walk on 100 000 products |
| `fixture-images` | on `main` only: a push that changes an image input, or by hand (by hand elsewhere it bakes without pushing) | bakes and publishes `ghcr.io/kronixon-pi/connect-ps-fixture:<ps>-<fixture sha>` (installed shop + module + 500 products); the fixture sha covers the generator, the bake scripts, the pinned images and the module code |

Commits follow Conventional Commits with the scopes `api`, `auth`, `catalog`, `compat`, `outbox`,
`update`, `admin`, `ci`, `deps` ([.commitlintrc.json](.commitlintrc.json)).

## Security

See [SECURITY.md](SECURITY.md). Please report vulnerabilities privately.

## Licence

[Academic Free License 3.0](LICENSE).
