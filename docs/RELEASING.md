# Releasing the module

A release is one zip, its Ed25519 signature and its sha256 on a GitHub (pre-)release. The platform accepts a release
only when the signature verifies against a public key it has pinned for that channel
(`connect:release:publish`, the platform's `docs/runbooks/connect.md` §6).

| Channel | API host built in | Who signs | State |
| --- | --- | --- | --- |
| `staging` | `https://connect-staging.kronixon.com` | the workflow [`release`](../.github/workflows/release.yml), after the owner's approval | this document |
| `stable` | `https://connect.kronixon.com` | offline, two people, two YubiKeys (Security §5.5) | **out of scope here**: the production key ceremony comes before the first stable release |

## What the zip is

`scripts/release/build.php <version> <channel> <public keys> <output>` builds it from the commit (`HEAD`), nothing else:

- the module's tracked files — the list `scripts/bake-fixture.sh` installs into the test shops; no tests, tools,
  workflows or documents;
- `src/Protocol.php` with the channel's API host (`DEFAULT_PLATFORM_URL`) and the first public key
  (`PINNED_PLATFORM_KEY`); pairing with a platform that names another key is refused;
- `kx-manifest.json`: version, channel, protocol, the compatibility (PrestaShop from … to, PHP), the public keys and
  the sha256 of every file.

It is **reproducible**: files sorted by path, stored uncompressed, one fixed time and mode, written by the script
itself. Anyone gets the same sha256 from the same commit, version, channel and public keys:

```sh
php scripts/release/build.php 0.3.2 staging "$(gh variable get KX_RELEASE_PUBLIC_KEYS_STAGING)" /tmp/k.zip
```

The workflow builds three times (twice in one job, once more on the signing runner) and stops when a byte differs.

## One-time setup by the owner (staging)

Nothing here is ever sent in a chat, an e-mail, an issue or a pull request — except the **public** line of step 2, which
is public.

### 1. Generate the key pair, on your own machine

With OpenSSL (macOS with Homebrew's `openssl`, Linux, or Git Bash on Windows):

```sh
umask 077
openssl genpkey -algorithm ed25519 -out kx-release-staging.pem
echo "kx-staging-1:$(openssl pkey -in kx-release-staging.pem -pubout -outform DER | tail -c 32 | base64)"
```

The last line prints the **public** line, `kx-staging-1:` and 44 characters. Or with PHP (with `sodium`), which prints
the same line and writes the secret to a file:

```sh
umask 077
php -r '$p = sodium_crypto_sign_keypair(); file_put_contents("kx-release-staging.key", base64_encode(sodium_crypto_sign_secretkey($p))); echo "kx-staging-1:", base64_encode(sodium_crypto_sign_publickey($p)), PHP_EOL;'
```

The secret is the file `kx-release-staging.pem` (or `.key`). Keep one copy in your password manager.

### 2. The environment and its secret, in the GitHub web page

1. `github.com/kronixon-pi/connect-prestashop` → **Settings** → **Environments** → **New environment**, name
   `release-staging`, **Configure environment**.
2. **Required reviewers**: tick it, add yourself, **Save protection rules**.
3. **Deployment branches and tags** → **Selected branches and tags** → **Add deployment branch or tag rule**: type
   *Branch*, pattern `main`; then again: type *Tag*, pattern `v*`.
4. **Environment secrets** → **Add environment secret**: name `KX_RELEASE_SIGNING_KEY_STAGING`, value the whole content
   of the secret file (a PEM with its BEGIN and END lines, or the one line of the `.key` file).
5. **Settings** → **Secrets and variables** → **Actions** → tab **Variables** → **New repository variable**: name
   `KX_RELEASE_PUBLIC_KEYS_STAGING`, value the public line of step 1.
6. Delete the secret file from your machine once it is in the password manager and in the environment.

### 3. The public line on the platform

Give the same public line to the platform as `CONNECT_RELEASE_KEYS_STAGING` (its `docs/runbooks/connect.md` §1 step 3b).
Until then the platform pairs nothing and accepts no release.

## A staging release

1. The version is the module's own: `Protocol::MODULE_VERSION`, `config.xml` and the changelog agree, merged to `main`.
2. **Actions** → **release** → **Run workflow** on `main`, version `0.3.2`. (Or push a tag: `git tag v0.3.2 <commit of
   main>`, `git push origin v0.3.2`.)
3. The job *sign* waits. Open the run, check that the job *build* is green and that the commit in its summary is the one
   you expect on `main`; then **Review deployments** → `release-staging` → **Approve and deploy**.
4. The pre-release `v0.3.2` holds `kronixonconnect-0.3.2.zip`, `.zip.sig` and `.zip.sha256`.
5. Publish it on the platform: `ssh staging-runner artisan connect:release:publish --channel=staging --release=0.3.2`
   (the platform fetches both files and checks the signature itself). It prints the sha256: it must be the one of step 4.

A release is never changed. A mistake is a new version, and the bad one is withdrawn on the platform (`--yank`).

## Key rotation, and a leaked key

See the platform's `docs/runbooks/connect.md` §6.3: both keys in both lists for a while, the one in use first. On this
side it means the order of `KX_RELEASE_PUBLIC_KEYS_STAGING` and the environment secret: `sign.php` refuses a secret that
is not the half of the **first** public key, so a half-done switch publishes nothing. After a leak: delete the
environment secret, remove the key from the platform's list, then start again at step 1 with the next `key_id`.

## Why the secret stays where it is

- It exists in the environment `release-staging` only; a job gets it only after the owner's approval, and only the job
  *sign* names that environment. The jobs that build and publish never see it; *sign* cannot write to the repository.
- In that job it is masked line by line, written to a `mktemp` file (mode 600) that a `trap` removes, and read from
  there: never on a command line, never in the output, no `set -x`.
- The platform holds public keys only: a break-in there can publish nothing the key did not sign.
