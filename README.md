# Kronixon Connect for PrestaShop

The free, open-source companion module that links a PrestaShop store to **Kronixon PI**. The
platform never touches the shop's database, files or the PrestaShop Webservice: it talks only to
this module, over the signed [Connect protocol v1](protocol/v1/README.md).

> **Status: 0.2.0, pairing.** The module pairs with Kronixon PI from its settings page, verifies the
> signature of every protocol request, signs every answer and serves `capabilities`, `profile`,
> `health`, `rotate` and `unpair`. Catalog reads, writes, webhooks and self-update arrive in the next
> versions. Do not install it on a production shop yet.

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
capabilities` check, profile, health, replay/tamper/clock refusals, a rotation and an unpair.
`scripts/ps/pair.php <platform url> <code>` pairs a local shop from the command line.

`PS` is one of `178`, `81`, `82`, `90`, `91`, `92` ([docker/](docker/)); `PS_TAG` overrides the image tag.
The PrestaShop and MariaDB images are pinned by digest in [docker/images.lock](docker/images.lock), the
only place to change them; `scripts/compose.sh` (used by `make` and the CI scripts) resolves them.
The shops use PrestaShop's documented default admin login and `admin-dev` as the admin folder.

Layout: `kronixonconnect.php` (module class), `controllers/front/api.php` (the only API entry),
`src/` (`Kronixon\Connect\` — `Api`, `Auth`, `Compat`, `Crypto`, `Install`, `Platform`, `Shop`, `Support`, loaded by the module's
own autoloader; no composer at runtime), `sql/`, `views/`, `protocol/v1/` (protocol document, JSON
Schemas, signature vectors), `tests/` (PHPUnit, the fixture generator), `docker/`, `scripts/`.
Development tools live in `tools/vendor`, because PrestaShop loads a module's `vendor/autoload.php`
at runtime.

### CI

| Workflow | When | What |
| --- | --- | --- |
| `module-ci` | every PR and push to `main` | PSR-12, PHPCompatibility, PHPStan 6, PHPUnit on 7.4 and 8.5, schemas + vectors, smoke install on 9.1.5 (PHP 8.5), commitlint |
| `matrix` | PRs to `main`, label `matrix`, weekly | the smoke run (install, pairing with the stub, signed calls) on the six versions above |
| `fixture-images` | on `main` only: a push that changes an image input, or by hand (by hand elsewhere it bakes without pushing) | bakes and publishes `ghcr.io/kronixon-pi/connect-ps-fixture:<ps>-<fixture sha>` (installed shop + module + 500 products); the fixture sha covers the generator, the bake scripts, the pinned images and the module code |

Commits follow Conventional Commits with the scopes `api`, `auth`, `catalog`, `compat`, `outbox`,
`update`, `admin`, `ci`, `deps` ([.commitlintrc.json](.commitlintrc.json)).

## Security

See [SECURITY.md](SECURITY.md). Please report vulnerabilities privately.

## Licence

[Academic Free License 3.0](LICENSE).
