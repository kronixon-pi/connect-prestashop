# Releases

A release is one zip, its Ed25519 signature and its sha256 on a GitHub (pre-)release of this repository. Kronixon PI
offers a release to shops only when the signature verifies against a public key it has pinned for that channel.

| Channel | API host built in | Built by |
| --- | --- | --- |
| `staging` | `https://connect-staging.kronixon.com` | the workflow [`release`](../.github/workflows/release.yml), as a GitHub **pre-release**: for tests only |
| `stable` | `https://connect.kronixon.com` | the same script; nothing is released on this channel yet |

## What the zip is

`scripts/release/build.php <version> <channel> <public keys> <output>` builds it from the commit (`HEAD`), nothing else:

- the module's tracked files — the list `scripts/bake-fixture.sh` installs into the test shops; no tests, tools,
  workflows or documents;
- `src/Protocol.php` with the channel's API host (`DEFAULT_PLATFORM_URL`) and the first public key
  (`PINNED_PLATFORM_KEY`); pairing with a platform that names another key is refused; the channel (`CHANNEL`) and every
  public key given, in that order (`RELEASE_KEYS`): the keys the module checks its own updates with;
- `kx-manifest.json`: version, channel, protocol, the compatibility (PrestaShop from … to, PHP), the API host, the public
  keys and the sha256 of every file.

`module-ci` builds it on every pull request and installs it on PrestaShop 9.1 (`scripts/zip-smoke.sh`).

## Build the same bytes yourself

The build is **reproducible**: files sorted by path, stored uncompressed, one fixed time and mode, written by the script
itself. The same commit, version, channel and public keys give the same sha256 on any machine:

```sh
git checkout v0.3.2
php scripts/release/build.php 0.3.2 staging <key_id:base64> /tmp/k.zip
```

It prints the sha256, which must be the one in the release's `.zip.sha256`. The public keys (a comma list when there is
more than one, in the same order) are `release_keys` in the `kx-manifest.json` of the released zip. The workflow builds
three times, on two machines, and stops when a byte differs.

## What the signature proves

`kronixonconnect-<version>.zip.sig` is the base64 of the detached Ed25519 signature over the bytes of the zip. It proves
that the holder of the channel's signing key signed exactly these bytes. To check it, with the channel's public key (32
bytes, base64) and PHP's `sodium`:

```sh
php -r '[, $zip, $key] = $argv; var_dump(sodium_crypto_sign_verify_detached(base64_decode(trim(file_get_contents("$zip.sig"))), file_get_contents($zip), base64_decode($key)));' kronixonconnect-0.3.2.zip <base64>
```

`bool(true)`, or the file is not the one that was signed. Take the key from `/.well-known/kx-release-keys.json` on the
channel's API host, not from the zip you are checking.

## How a release is made

1. The version is the module's own: `Protocol::MODULE_VERSION`, `config.xml` and the changelog agree on `main`.
2. The workflow runs by hand, on `main` only, with that version. *build* has no secret; *sign* runs in the GitHub
   environment `release-staging` after an approval, builds once more, must get the same sha256, and signs; *publish* has
   no secret and is the only job that may write to the repository: it creates the pre-release `v<version>` and its tag.
3. `scripts/release/sign.php` refuses a key that is not the half of the **first** public key, the one built into the zip.

The signing key exists in that environment only. In the job it is masked, written to a `mktemp` file (mode 600) that a
`trap` removes, and read from there: never on a command line, never in the output.

A release is never changed. A mistake is a new version.

## How a shop installs it

A shop paired with Kronixon PI asks `GET /v1/module/latest` (signed) once a day and when the administrator clicks
*Check for updates*; the settings page then offers the newer compatible version, and nothing happens before the
administrator clicks *Update now* (the module's `src/Update/`):

1. The zip is fetched from the URL of the signed answer, only from the platform's hosts (as images are), into
   PrestaShop's cache folder.
2. Before anything is unpacked: its size and sha256 must be the answer's, and its Ed25519 signature must verify with the
   key the answer names **among the keys built into the running module** (`RELEASE_KEYS`) — never a key from the answer
   or the zip. Its `kx-manifest.json` must be of this module, of the version announced, of the running module's channel
   and API host, for this PrestaShop and PHP, and list exactly the zip's files with their sha256. A version that is not
   newer than the running one is never installed: a release that has to be withdrawn is followed by a newer one.
3. The files are written to `modules/.kronixonconnect-next` and swapped in by two renames; the old folder waits as
   `.kronixonconnect-prev`. The next request (the settings page the browser is sent to, or the cron URL) runs
   PrestaShop's own upgrade (`upgrade/upgrade-<version>.php`) with the new code. When it fails, the old folder and the
   module's version row come back. The platform hears `module.updated` or `module.update_failed` through the outbox.
   A worker killed while writing leaves the old version running; killed after the swap, the next request finishes.
   Only a kill between the two renames (two system calls apart; the time limit and a closed browser cannot cause it)
   leaves no `modules/kronixonconnect`: rename `modules/.kronixonconnect-prev` back to it.
4. A server that does not let PHP replace the module (read-only folder, too little disk space, OPcache that never
   rechecks files while `opcache_invalidate()` is not allowed) gets the download link of the zip, to upload it in the
   Module Manager.

**Key rotation.** A shop trusts only the keys of the module it runs. To move to a new key, first release a version whose
`RELEASE_KEYS` holds the key in use and then the next one (`build.php … <current>,<next>`), signed with the key in use;
only once shops run it can a release be signed with the next key (listed first from then on). A shop that skipped the
intermediate release refuses the new signature (`unknown_key`) and is updated by hand.

`scripts/update-smoke.sh <ps>` plays all of this on a real shop with throw-away keys (the `matrix` workflow, every
version).
