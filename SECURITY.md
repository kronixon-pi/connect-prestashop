# Security policy

Kronixon Connect runs inside online shops and holds the key of their link to Kronixon PI, so we treat
every report seriously and handle it privately until a fix is available.

## Reporting a vulnerability

- **Preferred:** a private report through GitHub — *Security → Report a vulnerability* on
  <https://github.com/kronixon-pi/connect-prestashop/security/advisories/new>.
- **E-mail:** security@kronixon.com (English or Bulgarian).

Please include the module and PrestaShop versions, the steps to reproduce and the impact you see.
Do not open a public issue, and do not test against shops you do not own.

## What to expect

| Step | Target |
| --- | --- |
| Acknowledgement | within 3 working days |
| First assessment (severity, affected versions) | within 10 working days |
| Fix released for critical and high issues | as fast as possible, at most 30 days |
| Public advisory | when the fix is released, coordinated with you; credit if you wish |

We follow coordinated disclosure: please give us up to 90 days before publishing details.

## Supported versions

Only the latest release receives security fixes. The module verifies updates with a pinned Ed25519
key before installing them; a release that is not signed by Kronixon is never installed automatically.

## Scope

In scope: this module (signature checks, pairing, secret storage, the API front controller, the
update mechanism) and the protocol in [`protocol/v1`](protocol/v1/README.md).
Out of scope: PrestaShop core and third-party modules (report those to their maintainers), and
findings that need an administrator account of the shop.
