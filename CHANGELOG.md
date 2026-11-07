# Changelog

All notable changes of the module are listed here; versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- 0.2.1: a rotation retried after a lost answer (verified with `prev`) replaces only `cur` and keeps
  `prev` with its original expiry, so the platform's secret keeps verifying and the unconfirmed one
  does not.
- An unpair by the platform also forgets the last pairing outcome (`KX_PAIR_STATE`) and the time of the
  last platform request (`KX_LAST_REQUEST_AT`).
- Uninstall deletes the `access` rows of the back-office tab's roles, which PrestaShop leaves behind.

### Added

- 0.2.0 pairing: the settings page (also *Advanced Parameters → Kronixon Connect*) pairs the shop with a
  code, shows the outcome or the platform's reason with the time to retry, tests the connection and
  unpairs; `POST /v1/pair` with the module's X25519 key, the sealed secret opened and stored encrypted
  (`PhpEncryption` with `_NEW_COOKIE_KEY_`), the signed ping.
- Every `/v1/…` request verified in the protocol's order (clock window, HMAC with `cur`/`prev`, single-use
  nonce in `kx_nonce`), 120 verified requests a minute, one write at a time; every answer after the
  signature signed; endpoints `capabilities`, `profile`, `health`, `rotate`, `unpair`.
- A zero-right Profile and an inactive Employee created at install and removed at uninstall; uninstall
  tells the platform (best effort) before the secrets go.
- `kx_log` (14 days, secrets masked), translations bg and de, an install failure names its step.
- 0.1.0 skeleton: module class with install/uninstall, the module tables, hooks registered (handlers
  empty until the outbox arrives), the API front controller with its kernel (25 s budget, JSON errors,
  no stack traces), compatibility profiles for PrestaShop 1.7.8 / 8.0 / 8.1–8.2 / 9.x.
- Connect protocol v1: [document](protocol/v1/README.md), JSON Schemas for every Phase-0 endpoint and
  event, 8 signature vectors, request/response signer and verifier.
- Until the shop is paired every `/v1/…` request answers `403 store_unpaired`.
