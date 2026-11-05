# Changelog

All notable changes of the module are listed here; versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- 0.1.0 skeleton: module class with install/uninstall, the module tables, hooks registered (handlers
  empty until the outbox arrives), the API front controller with its kernel (25 s budget, JSON errors,
  no stack traces), compatibility profiles for PrestaShop 1.7.8 / 8.0 / 8.1–8.2 / 9.x.
- Connect protocol v1: [document](protocol/v1/README.md), JSON Schemas for every Phase-0 endpoint and
  event, 8 signature vectors, request/response signer and verifier.
- Until pairing is released every `/v1/…` request answers `403 store_unpaired`.
