# Kronixon Connect for PrestaShop

The free, open-source companion module that links a PrestaShop store to **Kronixon PI**. The
platform never touches the shop's database, files or the PrestaShop Webservice: it talks only to
this module, over the signed [Connect protocol v1](protocol/v1/README.md).

> **Status: 0.4.0, pairing, catalog reads and writes.** The module pairs with Kronixon PI from its settings
> page, verifies the signature of every protocol request, signs every answer and serves `capabilities`,
> `profile`, `health`, `rotate`, `unpair`, the eleven `GET catalog/*` reads and the writes of products,
> variants, categories, attributes and their values, brands, tags and redirects. Prices and stock in bulk,
> images, webhooks and self-update arrive in the next versions. Do not install it on a production shop yet.

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

1. Download `kronixonconnect-<version>.zip` in Kronixon PI (*Stores → Connect a store*, step 2), or from the
   [releases](https://github.com/kronixon-pi/connect-prestashop/releases). A pre-release is a staging build: it talks
   to the staging platform only.
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
- The module talks only to the platform URL on its settings page (default `https://connect.kronixon.com`; a
  staging build's default is `https://connect-staging.kronixon.com`). A release build pins its channel's public
  key: pairing with a platform that names another key is refused.
  A plain `http://` URL is refused unless the Configuration key `KX_PLATFORM_ALLOW_HTTP` is `1` — for a
  development platform only; it is not on the settings page.

## Catalog reads

The signed `GET catalog/*` endpoints ([protocol §6.1](protocol/v1/README.md#61-module-endpoints)) read
the paired shop (Context shop) and never write. Each answer is exactly its `module/catalog-*.json`
schema: `products`, `variants`, `images`, `prices`, `stock`, `categories`, `brands`, `suppliers`,
`attributes` (features and attribute groups, told apart by `kind`), `attribute-values` (`kind` required) and
`tags`.

- **Order and paging.** Items come in ascending id order (prices and stock, and variants read with
  `product_id_in`: product id, then variant id; attributes: id, then kind — a feature before the attribute
  group with the same id);
  `limit` is 1–500 (1–1 000 for prices and stock), 100 by default; an invalid parameter answers
  `400 bad_request` with `details.errors`. An answer stays under 8 MB: items past that move to the next page.
- **Cursor.** base64url without padding of the JSON `{"id":<last id>}`, or `{"id":<product id>,"v":<variant
  id>}` for prices, stock and variants read with `product_id_in`, `{"id":<id>,"v":0|1}` for attributes (0 a feature, 1 an attribute group). A
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
- **Variants of several products.** `variants?product_id_in=1,2,…` (up to 100 distinct product ids,
  duplicates count once) answers the combinations of those products, product id then combination id,
  paged by `limit` and the cursor `{"id":<product id>,"v":<combination id>}` (`{"id":N}` starts after every
  combination of product N). It does not combine with `product_id` or `until_id`; an empty list, a member
  that is not an id or more than 100 ids answer `400`. `meta.max_id` stays the highest combination id. The
  pages of one list hold exactly the items of the `product_id=` answers of its products, byte for byte
  (tests/Integration/variants-in.php checks it on real shops). `capabilities.catalog.variants_product_id_in`
  is `true` from 0.3.2.
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
  text value. `product_count` counts the shop's products (`product_shop`, joined only when the install has
  more than one shop), `value_count` of an attribute group the shop's values (`attribute_shop`) and of a
  feature its predefined values; `facets` (with the `facets` capability) the categories of the paired shop whose
  storefront filters offer the feature or group (`layered_category`).
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
- **Cost.** A page is a bounded number of queries whatever its size (products 9, `fields=basic` 5, variants 6
  (≤ 7 with `product_id_in`), images 3, prices ≤ 6, stock ≤ 2, categories, brands, suppliers and attribute values 4, attributes ≤ 8,
  tags 2): base rows by id, then one query per related table for the page's ids. A variants page without
  items runs only its base query (two with a `product_id_in` cursor inside a product).
  In MySQL, a products page reads about 20–30 rows per item wherever it starts, a prices page about 5,
  a variants page about 7 and a `product_id_in` page of 100 products about 8; an empty `product_id_in`
  page of 100 products without combinations reads about 100 rows, one index lookup per product.
  [tests/Integration/page-cost.php](tests/Integration/page-cost.php) checks this in the smoke run (at
  most 60, 20, 20 and 20 per item, 200 for the empty page).
  Measured on 100 000 generated products, limit 500, in process (`tests/Integration/catalog-walk.php`):
  memory flat between pages; the time per products page depends on the machine more than on the
  version: a median of 27–81 ms on GitHub's runners (two matrix runs, every version, the same version up
  to 2× apart between runs) and 105–110 ms on a slower development machine. The matrix prints the
  figures of every run in its job summary.

## Catalog writes

The signed `POST` endpoints write the paired shop through PrestaShop's own object model, so its validation
and the hooks of other modules run. Rules of the protocol: [§6](protocol/v1/README.md#6-endpoints-phase-0).

- **What is written.** `categories` (`Category`), `attributes` (`Feature`, `AttributeGroup`),
  `attribute-values` (`FeatureValue`, `Attribute` on 1.7.8, `ProductAttribute` from 8.0), `brands`
  (`Manufacturer`), `tags` (`Tag`), `products` (`Product` with `updateCategories()`, `deleteFeatures()` /
  `addFeaturesToDB()`, `FeatureValue`, `ProductSupplier`, `Tag`), `variants` (`Combination`, and the classes of
  attributes) and `seo/redirects`. Nothing of these writes a catalog table by SQL; the module's own tables
  (`kx_entity_map`, `kx_idempotency`, `kx_redirect`) are, and so are ps_facetedsearch's filters of `facets`. The bulk
  endpoints are the exception the protocol defines ([below](#bulk-prices-stock-and-maintenance)).
- **Only what differs.** An item is compared with the shop: an update saves only the fields, in the languages,
  that changed (`setFieldsToUpdate`), so a field or a language that was not sent keeps what the shop holds; an
  item equal to the shop is `unchanged` and saves nothing (no hook, no new `date_upd`). A create needs the name in
  the shop's default language and fills the other languages from it. A language the shop does not have is left
  out with the warning `language_unknown`, or refused with `options.strict_languages`.
- **One transaction per item.** A refused item leaves nothing behind and the rest of the batch goes on. A module
  hooked to a save can commit that transaction early (`ps_facetedsearch` truncates its filter cache, and MySQL
  commits before that), and another may fail after it; so an item is checked as a whole before its first write, and an
  object that a failed item still left is deleted. `kx_pending` names what an item has created until the item is
  complete: it is written with the object, in the same transaction, before any module's hook of that object (this
  module stands first in `actionObjectAddAfter`, in every shop). When the deletion fails too the item is
  `cleanup_failed` and the rest of the batch goes on; when the request died, or the deletion failed, the next request
  deletes what is left: a write before its first item, a read (any `GET`, also `health` and `profile`) at most three
  objects before it answers, when no write is running. Until then nothing finds it (`match_by`), links to it or
  creates it again, and no `GET catalog/*` lists it: not in a page, a count, `max_id` or the `profile` counts. A
  product, category or brand to be active is created inactive and switched on when its item is complete, so the
  storefront neither lists what a dead request left nor shows its page (see Known limits). The switch is the last
  step before the item's `kx_pending` row goes; after a hook has committed early these steps no longer share one
  transaction, and a `kx_pending` row that cannot be deleted switches the object off again, so a row that is left
  always names an inactive object. An object somebody has worked with since (a category with children, a brand
  with products) is kept and no longer counted as left over.
  Only a module that commits before the object's own rows are complete, followed by the death of the server process
  itself, can leave an object the module does not know of.
- **Idempotency.** With an `Idempotency-Key` (a UUID, else `400`) the answer of a `200` is kept for 24 hours in
  `kx_idempotency`: the same request gets the same bytes with `X-KX-Idempotent-Replay: true`, signed anew; another
  body or endpoint with the key is `409 idempotency_conflict`. A refused request is not kept. Without a key, or when
  an answer could not be kept, a repeat finds the same entities again (`external_id`, `kx_id`, `match_by`).
- **One write at a time.** Every `POST`/`DELETE` runs under a lock of the shop (`GET_LOCK`, released with the
  connection); a second one gets `409 busy` with `retry_after: 5`. The stored answers are read and written under it.
  A `GET` takes the lock only when `kx_pending` names something to delete, and only while it deletes it; a write
  that comes meanwhile gets `409 busy` too. On a shop with nothing left over a read never holds the lock.
- **Limits.** 100 items (`413 batch_too_large`), 4 MB, 25 s: an item starts only with 1.5 s left, the rest is
  `skipped` with `continue: true`.
- **`content_hash`** of an `ok` item is read back from the shop once, after the last item, with the code of
  `GET catalog/*`.
- **Hooks** run for every active module of the shop. The module's service account is not the employee of the
  request: PrestaShop before 9.1 runs a module's hook only for an employee who may view that module, and the
  account has no rights. The hooks of a create see a product, category or brand inactive; once its item is committed
  the module runs the hooks of an update for it, with the object as stored and active
  (`actionObjectUpdateBefore`/`After`, `actionObject<Class>UpdateBefore`/`After`, and `actionProductSave` +
  `actionProductUpdate` for a product, `actionCategoryUpdate` for a category), in the paired shop. They save
  nothing; one that throws is logged, the next one still runs (1.7.8 lets a module's exception through) and the item
  stays `ok`.
- **Multistore.** Ids of another shop are not found; a created category, feature, group or brand is in the paired
  shop only. Columns PrestaShop shares between shops (a product's reference, a brand's name and logo) change for all.
- **Categories.** Items are written in order, `parent_key` names an earlier one; without a parent the category is
  under Home (one directly under the Root stays there). The nested tree is rebuilt once, after the last item
  (`options.regenerate_tree: none` leaves it to a later batch). Root and Home are refused (`root_category`). Nothing is
  deleted: deactivate. A position is set on the category alone, its siblings keep theirs.
- **Attributes.** `public_name` is cut at PrestaShop's 64 characters (warning `public_name_cut`). `facets` (capability
  `facets`: ps_facetedsearch is active, else they are left out with `facets_unsupported`) are the categories whose
  storefront filters offer the feature or group: a row of `layered_category` per category of the paired shop (a new
  one at the category's next position unless `position` is sent) and the member `layered_selection_feat_<id>` /
  `layered_selection_ag_<id>` of each filter template of the shop that covers the category, so they survive a template
  saved in the back office (which rebuilds `layered_category` from the templates; a category no template covers
  loses the filter then, as in the back office). `mode: replace` removes the filter from the categories not sent, and
  from the templates that cover none of them. An unknown category is `category_not_found`. The storefront's cached
  filter blocks are cleared after the last item; the index itself is `POST maintenance/facets`. `GET
  catalog/attributes` lists them (`facets`), outside the `content_hash`.
- **Brands.** The logo is fetched before the item's transaction (see [Files from the platform](#product-images)), and
  dropped unless its sha256 is the announced one; a logo the shop already holds is not fetched. A logo PrestaShop
  converts (anything but a JPEG) carries the sha256 of its source in the stored file.
- **Tags.** PrestaShop deletes a tag no product has as soon as any product's tags change.
- **Products.** Only the groups in `groups`. A create is inactive, under Home and in the tax rules group most products
  have, unless sent otherwise; replacing the categories by none is Home too; `stock_initial` applies to a create only and makes no stock movement. The friendly URL
  of an existing product stays unless `preserve_link_rewrite` is `false`; one PrestaShop refuses is rewritten
  (`link_rewrite_changed`). Meta texts are made acceptable (`isGenericName`) and cut at the column's length
  (`meta_title_cut`, `meta_description_cut`). A custom feature text changes in the languages sent; changing any
  feature of a product gives its custom texts new ids, as PrestaShop's own form does. `reindex: deferred` (the
  default) marks a changed product for the shop's search indexing, `inline` indexes it at once, `none` leaves it.
  Hooks always run (`fire_hooks: false` has no effect). Tags are added; `mode: replace` makes the list sent those of
  its language, languages that were not sent keep theirs.
- **Variants.** One transaction for a product's option groups, values and combinations. Groups and values belong to the
  whole shop, so they are found (`external_id`, `kx_id`, the name in the default language) or created, never renamed,
  and no `kx_id` is linked to them. Only `mode: replace` deletes combinations, with their stock. `image_keys` name
  pictures of `POST images`; pictures the shop added to a combination itself stay, an unknown key is
  `image_key_unknown`. Without combinations left the product is a simple one again. Packs and virtual products are
  refused. A combination a failed item left is never the default one, and the product's variants are `cleanup_failed`
  until it can be removed. `content_hash` is the product's.
- **Redirects.** A product's own (`redirect_type`; `410` from 8.0, else `404` and `redirect_410_unsupported`).
  A category's addresses and old slugs are kept in `kx_redirect`, by path (friendly URLs only, compared byte for
  byte as PrestaShop's router does: `/en/CART`, `/en/Cart` and `/en/Cart ` are three paths), and served from the
  hook that builds the routes: one cached setting per request, one indexed lookup where a shop has any. As PrestaShop
  does for a product, a category is redirected only while inactive (`category_active`); when it is active again, in
  the back office too, its redirects go. `seo.redirect.auto` keeps the old address of a product whose friendly URL
  changed. Targets are checked when they are sent (`target_not_found`, `target_inactive`, `redirect_chain`,
  `redirect_loop`); a redirect a product already has is not checked again by `POST products`. An old slug may not be the
  address of a page (`slug_taken`): the path is resolved as PrestaShop does it, by the language prefix and the
  dispatcher's routes (entities while they are active, the shop's pages, modules), and a file or folder of the
  installation counts too. On the storefront a row is served only where the shop shows no other page, and only to a
  target that is active. `KX_REDIRECTS` goes back to `0` with the write that finds no row left.
- **Known limits.** Until a half-written product, category or brand is deleted, its storefront URL answers as
  PrestaShop answers for any inactive object: on 1.7.8 `404`; on 9.1 `403` for a product, `404` for a brand and `500`
  for a category, whose tree position is not set yet. Some of these pages carry its name (the breadcrumb, JSON-LD,
  `<title>`). The next signed request that finds no write running deletes it.

## Bulk prices, stock and maintenance

`POST prices/bulk` and `stock/bulk` take up to 1 000 rows (`413 batch_too_large` above) and answer one result per row,
in the order sent, told apart by `product_id` and `variant_id` (a row naming the same pair as an earlier one is
refused). They write as the protocol defines it, without `Product::save()`: rows are read and written in chunks of
100, each chunk in one transaction, a row checked before anything of it is written; a chunk that fails is
`module_error` for its rows and the next chunk goes on. Under the write lock and once per `Idempotency-Key`, as every
`POST`; what a dead write left half-written is removed first, and a product that is still left so is
`cleanup_failed`.

- **`expected_price` / `expected_quantity`** is the value the platform believes the shop holds. When the shop holds
  another one (changed in the back office), the row is `status: conflict` with `code` `price_changed` /
  `quantity_changed` and the shop's value (`current_price` / `current_quantity`), and nothing of the row is written. A
  row whose value the shop already holds is `ok` / `unchanged`, never a conflict: a repeat after a lost answer is safe.
- **Prices** are net, in the shop's default currency, as PrestaShop stores them (`profile.tax`): no gross price is
  converted. A product row (`variant_id: "0"`) sets `price`, `wholesale_price`, `tax_rules_group_id`
  (`tax_rules_group_not_found`) and `unit_price`; a combination row its price **impact** (what `GET catalog/prices`
  reads as `price_impact`) and `wholesale_price` (`tax_rules_group_id`/`unit_price` there: `product_fields_ignored`).
  `product_shop`/`product_attribute_shop` of the paired shop are written, and the main table when the paired shop is
  the default one, as PrestaShop's save does; `date_upd` of the product too unless `touch_date_upd: false`. A unit
  price follows the version: 8.0+ keeps it and derives the ratio, 1.7.8 keeps only the ratio, so a new price changes
  its unit price as in the back office. After the last chunk the price caches go (`flush_cache: false` keeps them)
  and `actionKxBulkPricesUpdated` runs once with the changed product ids; `fire_hooks: true` adds
  `actionProductUpdate` per changed product. ps_facetedsearch's price filter: `reindex: inline` indexes the changed
  products after each chunk; `deferred` (the default) lists them in `facets_pending` for `POST maintenance/facets`;
  `none` leaves it. The search index holds no prices.
- **Stock** is the paired shop's (its group's when the group shares stock); `location` other than `default` is
  `location_ignored` and `capabilities.stock_locations` is false: PrestaShop has one stock per shop. A product with
  combinations takes their quantities (its own row is their total). `mode: safe` (the default) calls
  `StockAvailable::setQuantity()` as the back office does (`actionUpdateQuantity`, packs, the total; PrestaShop
  records a stock movement only where its Symfony container runs, which a module's front controller does not have on
  1.7.8–9.1, so none is recorded); `mode: fast` updates `stock_available` (quantity, and physical quantity = reserved + quantity) and the
  total per chunk, with `actionUpdateQuantity` per row only for `fire_hooks: true`; a row the shop has no stock row
  for goes the safe way. `out_of_stock` through `StockAvailable::setProductOutOfStock()`. `shop_id` of another shop is
  `shop_mismatch`. `available_now`/`available_later` are texts of the product: `available_texts_ignored` (send them
  with `POST products`, `content`). `visibility_policy: restore_if_hidden` shows a product hidden from everywhere
  (`visibility: none`) once its total is above 0 (`visibility_restored`); `hide_when_zero` hides a product the
  platform has linked (a `kx_id`) when its total is 0 (`visibility_hidden`; an unlinked one is
  `not_hidden_unlinked`) and shows again only those it hid (`kx_entity_map.flags` bit 1). A visibility change marks
  the product for search indexing. `actionKxBulkStockUpdated` runs once with the changed product ids.
- **`POST maintenance/reindex`** (`search_reindex`): PrestaShop's `Search::indexation()` per product, by id, within
  `budget_s` (at most the request's). `pending` indexes the products PrestaShop marked (`indexed = 0`), `products`
  marks and indexes the listed ones (up to 1 000), `full` marks every product of the paired shop first. While
  `continue` is true the platform sends the same body with the answer's `cursor`; a call with a cursor marks nothing
  again, so a walk visits each product once and ends, also where PrestaShop leaves a product unindexed (an inactive
  one). `remaining` counts the marked active, searchable products after the cursor.
- **`POST maintenance/cache-flush`** (`cache_flush`): `prices` (the price caches), `products` (the product, category
  and object caches of the request), `smarty` (`Tools::clearSmartyCache()`), `all` (`Tools::clearAllCache()`, which
  also removes Symfony's cache of the environment at the end of the request, `Media::clearCache()`,
  the class index of the autoloader, `actionClearCache`). Only PrestaShop's own APIs; no file is deleted otherwise.
- **`POST maintenance/facets`** (`facets`, else `403 capability_disabled`): ps_facetedsearch's own index methods per
  product, by id, with the same `cursor` walk: `prices` (`indexProductPrices`), `attributes` (`indexAttributes`, after
  `indexFeatures`/`indexAttributeGroup` so new features and groups are indexable), `categories` and `all` both. The
  products are those of `product_ids`, else those directly in `category_ids`, else every product of the shop. The
  storefront's cached filter blocks are cleared when the walk ends.

## Product images

`POST images` writes the images of one product (`product_id`), up to 50 items, each in its own transaction;
`DELETE images` removes them. Capabilities `images` and `image_alt`.

- **Files from the platform.** An image (and a brand logo) is fetched only from a host of the platform the shop is
  paired with: the host and port of its Connect URL and of the pairing's API base, and the `download_hosts` the pair
  answer names (port 443). Anything else is `download_host_refused`. Over HTTPS with TLS verified (plain HTTP only with
  the development setting `KX_PLATFORM_ALLOW_HTTP`), no redirects, at most 20 MB per file in 20 s and 100 MB per
  request; outside development the host must resolve to public addresses only, and the connection goes to the
  address that was checked. A file whose sha256 is not the one sent is `sha256_mismatch`; one PrestaShop does not
  take (`getimagesize`: jpg, png, gif or webp, at least 1×1, and `ImageManager::isRealImage`) is `image_invalid`; one
  above `PS_PRODUCT_PICTURE_MAX_SIZE` is `image_too_large`.
- **Ids are kept.** `kx_image_map` remembers the `kx_key` and the sha256 of each image the platform sent. An item
  whose `kx_key` (or `external_id`, which must be an image of the product) names an image with the same sha256 is not
  fetched at all: only its alt texts change (`unchanged` when they do not). Another sha256 replaces the files of the
  same image, so its id and URLs stay (`updated`). Two items with one `kx_key` are refused.
- **Files.** The original (`<id>.jpg`) and one file per product image type and format the shop has (`PS_IMAGE_FORMAT`
  from 8.1; `2x` with high-DPI images) are made by PrestaShop's `ImageManager` in a work folder under `img/tmp`, before
  the item's transaction, and renamed into place: each file is complete the moment it appears. `actionWatermark` runs
  after the commit, as the back office does.
- **No half image.** The image's rows are written by `Image::add()`; its files are put in place from this module's
  `actionObjectAddAfter`, right after the rows and before any other module's hook. A module that commits early there
  commits a complete image, and `kx_pending` keeps it out of every read until its item is complete; a request that dies
  then leaves a complete image that the next request removes before it writes (the retry makes exactly one). A request
  that dies before any commit leaves no rows; files it had put in place belong to no image and are never shown. A
  replacement that dies half-way leaves complete files of either picture and the old sha256, so the retry replaces
  them again.
- **Alt texts** (`alt`) per language sent; the others keep theirs, a create fills them from the default language.
  PrestaShop's rules apply (128 characters, no `<>={}`).
- **`strategy`.** After the last item: `sync` (the default) removes the images of the product the platform sent before
  and not in this request, and keeps the images the shop uploaded itself (no `kx_key`) after ours; `replace_all` keeps
  only the images of the request; `append` removes nothing and adds the new ones at the end. Then the images are
  numbered: the request's by `position`, else in the order sent. Exactly one cover: the last item with `cover: true`,
  else the cover there was, else the first image. `items: []` with `sync` removes every image of ours.
- **Combinations.** `variant_id` links every image of the request to that combination of the product (its other images
  stay). `POST variants` finds the images of `image_keys` by `kx_key` once they are sent: send images before variants,
  or name the combination here (a variant sent before its images is `image_key_unknown`).
- **`DELETE images`** (also as `POST` with `X-HTTP-Method-Override: DELETE`): by `external_ids`, `kx_keys` or `all`,
  through `Image::delete()` (rows, files, combination links; on multistore PrestaShop keeps an image another shop has).
  `only_kx` (the default) spares the images the shop uploaded itself: named by id they are `image_not_kx`, with `all`
  they stay. When the cover goes, the first image left is the new one. On 9.0 `Image::delete()` stops half-way outside
  the back office (it asks the Symfony container for a setting); the module then removes the thumbnails and renumbers
  the product's images as `Image::delete()` would have.
- Each `ok` result carries `urls`: the image's URL per image type, and `original`.

## What the module adds to the shop

- Tables `kx_nonce`, `kx_idempotency`, `kx_log`, `kx_entity_map`, `kx_pending`, `kx_image_map`, `kx_redirect`,
  `kx_outbox` ([sql/install.sql](sql/install.sql) says which version uses each; `kx_log` is kept 14 days and
  never holds a secret).
- Configuration keys `KX_*`; uninstalling removes the tables and the keys.
- A Back Office menu entry *Advanced Parameters → Kronixon Connect* (the settings page).
- A Profile "Kronixon Connect" without any access right and an inactive Employee in it; uninstalling removes
  both.
- Hooks on product, stock, category, image, brand, feature and combination changes. They record nothing yet;
  later they only queue an event in `kx_outbox` (no HTTP call inside a hook). `actionObjectAddAfter` marks what the
  module's own writes create and does nothing for any other object. The hook that builds the routes serves the
  redirects of `kx_redirect`.
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
unpair. [tests/Integration/catalog-writes.php](tests/Integration/catalog-writes.php) and
[product-writes.php](tests/Integration/product-writes.php) then write the shop through the signed endpoints and
compare with the reads, and [failed-writes.php](tests/Integration/failed-writes.php) does so with a module whose
hooks commit and then fail ([kxprobe](tests/Integration/kxprobe)). Then
[tests/Integration/catalog-walk.php](tests/Integration/catalog-walk.php) walks every catalog
reader in K = 1, 3 and 8 id ranges on `KX_WALK_PRODUCTS` products (generated by SQL; default 0 = the shop's
own) and [validate-answers.php](tests/Integration/validate-answers.php) checks every answer against its
schema (needs `make install`): `KX_WALK_PRODUCTS=100000 make smoke PS=91`. Last,
[tests/Integration/multistore.php](tests/Integration/multistore.php) adds a second shop in a second group,
shares a product with it and checks that each shop reads only its own relations of that product; the writes
run once more with the two shops.
`scripts/ps/pair.php <platform url> <code>` pairs a local shop from the command line.

`PS` is one of `178`, `81`, `82`, `90`, `91`, `92` ([docker/](docker/)); `PS_TAG` overrides the image tag.
The PrestaShop and MariaDB images are pinned by digest in [docker/images.lock](docker/images.lock), the
only place to change them; `scripts/compose.sh` (used by `make` and the CI scripts) resolves them.
The shops use PrestaShop's documented default admin login and `admin-dev` as the admin folder.

Layout: `kronixonconnect.php` (module class), `controllers/front/api.php` (the only API entry),
`src/` (`Kronixon\Connect\` — `Api`, `Auth`, `Catalog`, `Compat`, `Crypto`, `Install`, `Platform`, `Redirect`, `Shop`, `Support`, `Write`, loaded by the module's
own autoloader; no composer at runtime), `sql/`, `upgrade/`, `views/`, `protocol/v1/` (protocol document, JSON
Schemas, signature vectors), `tests/` (PHPUnit, the fixture generator), `docker/`, `scripts/`.
Development tools live in `tools/vendor`, because PrestaShop loads a module's `vendor/autoload.php`
at runtime.

### CI

| Workflow | When | What |
| --- | --- | --- |
| `module-ci` | every PR and push to `main` | PSR-12, PHPCompatibility, PHPStan 6, PHPUnit on 7.4 and 8.5, schemas + vectors, smoke install on 9.1.5 (PHP 8.5) with a catalog walk on 10 000 products, an install of the built release zip on 9.1.5, commitlint |
| `matrix` | PRs to `main`, label `matrix`, weekly | the smoke run (install, pairing with the stub, signed calls) on the six versions above, catalog walk on 100 000 products |
| `fixture-images` | on `main` only: a push that changes an image input, or by hand (by hand elsewhere it bakes without pushing) | bakes and publishes `ghcr.io/kronixon-pi/connect-ps-fixture:<ps>-<fixture sha>` (installed shop + module + 500 products); the fixture sha covers the generator, the bake scripts, the pinned images and the module code |
| `release` | by hand, on `main` only | builds the staging zip three times (the same bytes), signs it after an approval, creates the GitHub pre-release: [docs/RELEASING.md](docs/RELEASING.md) |

Commits follow Conventional Commits with the scopes `api`, `auth`, `catalog`, `compat`, `outbox`,
`update`, `admin`, `ci`, `deps` ([.commitlintrc.json](.commitlintrc.json)).

## Security

See [SECURITY.md](SECURITY.md). Please report vulnerabilities privately.

## Licence

[Academic Free License 3.0](LICENSE).
