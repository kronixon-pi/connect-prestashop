# Kronixon Connect for PrestaShop

The free, open-source companion module that links a PrestaShop store to **Kronixon PI**. The
platform never touches the shop's database, files or the PrestaShop Webservice: it talks only to
this module, over the signed [Connect protocol v1](protocol/v1/README.md).

> **Status: 0.1.0, skeleton.** The module installs, creates its tables and answers every protocol
> request with `403 store_unpaired`. Pairing, catalog reads, writes, webhooks and self-update arrive
> in the next versions. Do not install it on a production shop yet.

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
Required PHP extensions: `curl`, `json`, `mbstring`, `openssl`, `zlib`.

## Install

1. Download `kronixonconnect.zip` from the [releases](https://github.com/kronixon-pi/connect-prestashop/releases)
   (the first release comes with pairing).
2. In the shop's back office: *Modules → Module Manager → Upload a module*, choose the zip.
3. Open *Configure* on Kronixon Connect. From 1.0 on you pair the shop there with the code that
   Kronixon PI shows under *Stores → Connect a store*.

The API front controller answers at `https://<shop>/module/kronixonconnect/api?path=<endpoint>`
(friendly URLs) or `https://<shop>/index.php?fc=module&module=kronixonconnect&controller=api&path=<endpoint>`.

## What the module adds to the shop

- Tables `kx_nonce`, `kx_idempotency`, `kx_log`, `kx_entity_map`, `kx_image_map`, `kx_redirect`,
  `kx_outbox` (empty in 0.1.0; [sql/install.sql](sql/install.sql) says which version uses each).
- Configuration keys `KX_*`; uninstalling removes the tables and the keys.
- Hooks on product, stock, category, image, brand, feature and combination changes. In 0.1.0 they do
  nothing; later they only queue an event in `kx_outbox` (no HTTP call inside a hook).
- No core files are changed, no overrides are installed.

## Development

Everything runs in Docker; the host needs `docker` (with compose), `bash`, `curl` and `jq`.

```sh
make install          # development tools into tools/vendor (composer in Docker)
make test             # PHPUnit on PHP 7.4 and 8.5
make check            # PHP-CS-Fixer (PSR-12), PHPCompatibility 7.4–8.5, PHPStan level 6, PHPUnit
make up PS=91         # PrestaShop 9.1.5 + MariaDB 10.11 on http://localhost:8091 with the module
make fixture PS=91    # load the 500-product test fixture into that shop
make down PS=91
make matrix           # install + module + GET /v1/status on 1.7.8, 8.1, 8.2, 9.0, 9.1
```

`PS` is one of `178`, `81`, `82`, `90`, `91`, `92` ([docker/](docker/)); `PS_TAG` overrides the image tag.
The PrestaShop and MariaDB images are pinned by digest in [docker/images.lock](docker/images.lock), the
only place to change them; `scripts/compose.sh` (used by `make` and the CI scripts) resolves them.
The shops use PrestaShop's documented default admin login and `admin-dev` as the admin folder.

Layout: `kronixonconnect.php` (module class), `controllers/front/api.php` (the only API entry),
`src/` (`Kronixon\Connect\` — `Api`, `Auth`, `Compat`, `Install`, `Support`, loaded by the module's
own autoloader; no composer at runtime), `sql/`, `views/`, `protocol/v1/` (protocol document, JSON
Schemas, signature vectors), `tests/` (PHPUnit, the fixture generator), `docker/`, `scripts/`.
Development tools live in `tools/vendor`, because PrestaShop loads a module's `vendor/autoload.php`
at runtime.

### CI

| Workflow | When | What |
| --- | --- | --- |
| `module-ci` | every PR and push to `main` | PSR-12, PHPCompatibility, PHPStan 6, PHPUnit on 7.4 and 8.5, schemas + vectors, smoke install on 9.1.5 (PHP 8.5), commitlint |
| `matrix` | PRs to `main`, label `matrix`, weekly | install + module + `GET /v1/status` on the six versions above |
| `fixture-images` | on `main` only: a push that changes an image input, or by hand (by hand elsewhere it bakes without pushing) | bakes and publishes `ghcr.io/kronixon-pi/connect-ps-fixture:<ps>-<fixture sha>` (installed shop + module + 500 products); the fixture sha covers the generator, the bake scripts, the pinned images and the module code |

Commits follow Conventional Commits with the scopes `api`, `auth`, `catalog`, `compat`, `outbox`,
`update`, `admin`, `ci`, `deps` ([.commitlintrc.json](.commitlintrc.json)).

## Security

See [SECURITY.md](SECURITY.md). Please report vulnerabilities privately.

## Licence

[Academic Free License 3.0](LICENSE).
