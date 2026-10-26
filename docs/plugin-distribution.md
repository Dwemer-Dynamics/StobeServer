# Distributing server plugins

How an addon author builds a StobeServer package and how a maintainer lists it
in the product catalog. The hook contract is in
[plugin runtime](plugin-runtime.md); a buildable example is in
[examples/plugin-parity](../examples/plugin-parity/README.md). This page
describes the current parser in [lib/plugin_catalog.php](../lib/plugin_catalog.php)
and [lib/plugin_package_manager.php](../lib/plugin_package_manager.php); it does
not add policy.

## Package (schema 4 ZIP)

A package is a ZIP archive, usually named `.dwpkg` (`.zip` is also accepted):

```text
manifest.json        {"schema_version": 4, "name", "version", "server": {"mutable_paths": [...]}}
checksums.sha256     "<sha256>  <path>" for every other file
server/...           copied into ext/<name>/
```

- `schema_version` must be the integer `4`. `name` matches
  `^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$`, cannot end with a dot or space, is
  compared case-insensitively and cannot be `relationship_system`. `version`
  matches `^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$`.
- Only `manifest.json`, `checksums.sha256` and `server/` entries are allowed,
  and `server/` must contain at least one file. Paths must be relative, use `/`,
  and contain no `.`/`..` segments; symlink entries and duplicates are rejected.
- Every file except `checksums.sha256` needs exactly one checksum line, and no
  line may name a missing file. Optional `description`, `config_url` and
  `mod_download_url` are shown on the Server Plugins page.
- Limits: 3 to 5000 entries, 1 GiB uncompressed, 512 MiB archive.
- Build the example deterministically with
  `php examples/plugin-parity/build_package.php <out.dwpkg> <version>` (needs the
  PHP `zip` extension). Write output outside the repository; do not commit archives.

### Not CHIM's format

CHIM's installer reads `github_repo`/`branch`/`manifest_url` channels and
downloads GitHub `.tar.gz`/`.tar` archives with no catalog hash. StobeServer
reads none of those keys, does not accept tarballs, and requires the schema-4
manifest and inner checksums above. Do not copy CHIM catalog entries or
packages; convert them into a schema-4 ZIP first.

## Supported install paths

| Path | Who chooses the version |
| --- | --- |
| Game sync: the active Kenshi mod folder providing `Stobe/server-plugins/<name>/<version>.dwpkg` | The STOBE client; folder must equal manifest `name`, file stem must equal `version` |
| Upload on the Server Plugins page | The uploaded file |
| Catalog Live/Dev channel on the Server Plugins page | The channel's release document |

All three use the same validation and ledger. Versions are compared by exact
string equality, never by semantic order: a different version (older or newer)
is treated as a change, and changed bytes under an unchanged version are not
detected by the game-sync probe. Game sync needs a STOBE client revision with
package sync. Use one source per plugin; see
[plugin runtime](plugin-runtime.md#installation-and-updates).

## Catalog file

`ui/data/plugin_repository.json` ships empty. The parser reads only the
`plugins` object; `schema_version` and `product` are informational. The example
below parses, but its hosts are deliberately non-working placeholders
(`.invalid` is reserved), so it cannot install anything. Replace every URL and
host with HTTPS locations the author controls before proposing a real entry:

```json
{
    "schema_version": 1,
    "product": "stobe",
    "plugins": {
        "example_addon": {
            "name": "example_addon",
            "description": "Illustrative entry only; the URLs do not resolve.",
            "homepage": "https://addons.example.invalid/example_addon",
            "default_channel": "live",
            "channels": {
                "live": {
                    "release_url": "https://addons.example.invalid/example_addon/live.json",
                    "package_url": "https://downloads.example.invalid/example_addon/<version>/example_addon-<version>.dwpkg",
                    "package_hosts": ["mirror.example.invalid"]
                },
                "dev": {
                    "release_url": "https://addons.example.invalid/example_addon/dev.json"
                }
            }
        }
    }
}
```

| Field | Rule (entry or channel is skipped silently when it fails) |
| --- | --- |
| entry ID (key) | `^[a-z0-9][a-z0-9_-]{0,63}$`; sent by the browser, never a URL |
| `name` | Same rule as a manifest `name`; the downloaded manifest must match it case-insensitively |
| `description` | Trimmed, cut to 300 characters |
| `homepage` | Optional; if present must be HTTPS without credentials, or the entry is skipped |
| `channels` | Only `live` and `dev`; other keys ignored; an entry without a valid channel is skipped |
| `release_url` | Required HTTPS URL of the release document; its host is allowed for packages |
| `package_url` | Optional HTTPS template; `<version>` is replaced by the URL-encoded release version; its host is allowed |
| `package_hosts` | Optional extra hostnames (`[a-z0-9.-]`, lower-cased, exact match, no wildcards or ports); invalid items are dropped |
| `default_channel` | Falls back to the first valid channel |

The file is capped at 256 KiB and 50 entries; skipped entries are counted in
the API's `catalog_skipped`. Release and package URLs never reach the browser.

## Release document

Each channel's `release_url` returns at most 64 KiB of JSON:

```json
{
    "name": "example_addon",
    "version": "1.2.0",
    "package_url": "https://downloads.example.invalid/example_addon/1.2.0/example_addon-1.2.0.dwpkg",
    "sha256": "<64 lowercase hex characters of the .dwpkg file>"
}
```

- `version` is required and must satisfy the manifest version rule; the
  installed manifest must have exactly this version.
- `name` is optional; when present it must equal the catalog `name`
  (case-insensitive).
- `package_url` is optional when the channel has a template. Its host must be
  in the channel's allowed hosts (release host, template host, `package_hosts`).
- `sha256` is the outer hash of the whole archive. It is optional: when present
  the download must match it; when absent the server only relies on HTTPS and
  the package's own `checksums.sha256`, which detects corruption but not a
  replaced package. Curated entries should always publish it.

Downloads use HTTPS only with certificate verification, up to 5 HTTPS
redirects, and a 512 MiB limit. The host allowlist applies to the resolved
`package_url`, not to redirect targets. Release documents are fetched only by
**Check for Updates**, an install or a channel switch, never on page load.

## Publishing (manual, curated)

There is no automated submission or signing service.

1. The author publishes a schema-4 package and a release document per channel
   on HTTPS hosts they control, and records the archive's SHA-256.
2. A maintainer reviews the package source (hooks, migrations, declared
   `mutable_paths`, network use) and installs it on a disposable server and
   database, never a live playthrough.
3. The maintainer adds the entry to `ui/data/plugin_repository.json` in a
   reviewed pull request. The catalog ships with a server release; merging a PR
   does not publish to existing installs.
4. Later releases change only the author's release document; the catalog needs
   another PR only when URLs, hosts or channels change.

## Automated coverage

[.github/workflows/plugin-parity.yml](../.github/workflows/plugin-parity.yml)
runs on Linux with the runner's PHP (8.2 or later), separately from the
database regression workflow: PHP lint of the plugin example and
package/catalog libraries, the parity probe (including the symlink fixture that
Windows usually skips), and the example builder twice to check identical bytes
and the inner `checksums.sha256`. It does not cover catalog parsing or
downloads, package install, upgrade, mutable-path preservation, migrations,
removal or rollback; check those manually on a disposable server as described
in [plugin runtime](plugin-runtime.md#validation-and-reports).
