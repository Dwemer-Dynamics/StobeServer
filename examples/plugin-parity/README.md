# Plugin parity example

Two tiny server plugins plus a CLI probe for StobeServer's CHIM-style extension
hooks. Nothing here is loaded automatically: the plugins live outside `ext/` and
the probe uses a scratch copy. See [plugin runtime](../../docs/plugin-runtime.md)
for the hook contract.

| File | Demonstrates |
| --- | --- |
| `ext/parity_probe/globals.php` | `chimRegisterPromptInjection()` (`character_bottom`, `prompt_bottom`), `chimRegisterActorProfileEnricher()` reading context version 2 (`registered_npc`, the `player` type and `player_side` squads), `stobeRegisterExtensionAction('ExtCmdParityProbe_Ping', …)`, and `ExtCmdParityProbe_Report` with an opt-in `followup` |
| `ext/parity_probe/context.php` | Context-stage marker in `$GLOBALS['PLUGIN_PARITY_TRACE']` |
| `ext/parity_probe/postrequest.php` | Post-response marker; runs only after a model route completed its turn |
| `ext/parity_probe/prerequest.php` | Observer for the STOBE client's `funcret` (`command@ExtCmdParityProbe_Ping@<argument>@<completed\|failed…>`) and `addon_state` (`ParityProbe: <key>=<value>`) events |
| `ext/parity_probe_order/globals.php` | Second plugin: load order versus injection priority |
| `probe.php` | Real loader, include-once, exclusions, rendering, shared stage helpers, enrichment context version 2 against an in-memory database stub, action validation, and Director cast serials parsed from `main.php`'s normalized `Name (state)\|hand_<serial>` people tokens (valid, malformed, out-of-range and duplicate-name cases) |

No provider, database, network or game client is used.

## Run the probe

From the server source root:

```sh
php examples/plugin-parity/probe.php
```

It creates a temporary `ext/` with the example plugins and fixtures (disabled,
hidden, `private/`, `staging/`, root-level, failing, legacy
`relationship_system` and, where the OS permits, symlinked plugins), prints
`PASS`/`FAIL` lines, removes the temporary directory and exits non-zero on any
failure. Pass `--scratch=<new-or-empty-dir>` to keep the fixture tree and
`probe-error.log` for inspection. On Windows without symlink privilege the
symlink fixture is reported as skipped; run under Linux/WSL to cover it.

## Build the package

`build_package.php` turns `ext/parity_probe/` into a schema-4 server package
(`manifest.json` named `parity_probe`, `checksums.sha256`, files under
`server/`). Entries are sorted and timestamped at 1980-01-01, so the same source
and version produce identical bytes. It needs PHP's `zip` extension (on the
Distro/WSL PHP, not the Windows CLI without `zip`):

```sh
php examples/plugin-parity/build_package.php /tmp/parity_probe-1.0.0.dwpkg 1.0.0
```

Do not commit built archives. For STOBE's game-side sync, place the archive in
the client addon mod as `Stobe/server-plugins/parity_probe/1.0.0.dwpkg`. The
folder name must match the manifest `name` (`parity_probe`), and the file stem
must match `version`, or the server rejects the upload. `parity_probe_order` is a
load-order fixture for the probe and is not packaged.

## Install on a disposable test server

Use an isolated server and database, never the live playthrough.

1. Install the package from the Server Plugins page, through STOBE's package
   sync, or by copying `ext/parity_probe/` into the test server's `ext/`
   directory. Preserve other extensions.
2. Send a normal dialogue turn. The system prompt gains the marker text inside
   the character section and the footer at the end; nearby actors gain
   `Parity probe sees <name> (registered #<id>)` (without the suffix for
   unregistered names), and a `<player_character>` block names the player
   character with `Parity probe player in <squad>`. Rechat, bored, Director,
   manual diary and narrator welcome turns use the same plugin; see
   [model route coverage](../../docs/plugin-runtime.md#model-route-coverage). Stobe's prompt formatter renders XML-style tags
   as Markdown headings (`## Parity Probe`, `# Parity Probe Footer`). Plugins
   should not rely on literal tags reaching the model.
   `ExtCmdParityProbe_Ping` appears in the available actions and the
   structured `action` enum.
3. If the model selects it, the client receives
   `<actor>|ActionQueue|ExtCmdParityProbe_Ping@<target>`, ending `|sid=<serial>`
   when the request's `people` list names exactly one identity for the actor. With the STOBE
   client's ParityProbe addon, its `funcret` result makes the observer log
   `[parity_probe] completion: completed…`.
4. Disable without deleting by creating `ext/parity_probe/.disabled`, or remove
   the directory. Restore the server's `ext/` afterwards.

Action codes must match `ExtCmd<Bridge>_<Action>` (64 characters at most). The
bridge starts with a letter and contains only ASCII letters and digits; the
action starts with a letter and may also contain underscores, as in
`ExtCmdParityProbe_Do_Thing`. The STOBE client also accepts bridges that begin
with a digit; the server does not register them, so use a leading letter.

## Not verified by the probe

The probe does not run `main.php`, the model processors or `chat.php`, call a
model, write the database, or start Kenshi. Its enrichment checks use an
in-memory stand-in for the two database reads. Hook placement in those entry
points, a model choosing the action, client `ExtCmd` dispatch and the client's
result events remain live-test items. Stobe acknowledges `funcret` without a
follow-up model call unless the client opts in.

## Opt-in follow-up

`ExtCmdParityProbe_Report` registers a text-only follow-up. The probe checks
its validation, invalid options, no aid without `addon_followup=1` and
rejection of malformed IDs or results before any query. The ledger claim, the
follow-up model turn, duplicates, playthrough changes and concurrency need
PostgreSQL and the HTTP route; the request and stream contract is in
[action follow-ups](../../docs/plugin-runtime.md#action-follow-ups-opt-in). On a
disposable server with the example installed and the ledger migration applied:

1. Send a dialogue turn with `&addon_followup=1` and a `people` entry such as
   `"Beep|9101"`. If the model picks the action, the line is
   `Beep|ActionQueue|ExtCmdParityProbe_Report@|sid=9101|aid=<n>`.
2. Send `funcret` data `command@ExtCmdParityProbe_Report@@completed: green`
   with `&addon_followup=1&aid=<n>&sid=9101&arid=1`. The response has
   `X-Stobe-Addon-Followup: v1 accepted aid=<n>` and Beep's `ScriptQueue` line.
   Sending it again returns `v1 rejected` and `ok`.

A separate paired check has gone further on a disposable database. It installed
the built package through the package API, ran a `processor/chat.php` turn
against a stub OpenAI-compatible provider (which returned the
`ActionQueue|ExtCmdParityProbe_Ping@<target>` line), and sent client-format
`funcret` and `addon_state` events through `stream.php`. Game dispatch has not
been run.
