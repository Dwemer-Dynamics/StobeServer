# Stobe plugin runtime reference

This guide covers StobeServer's current `unstable` source. Read the [agent guide](agent-guide.md), [safe test setup](building.md) and [NPC plugin-data contract](plugin-npc-data.md) first. Check the linked callers against the version your extension supports; CHIM examples are not automatically compatible with Kenshi.

## Integration points and timing

[lib/extension_hooks.php](../lib/extension_hooks.php) loads CHIM-named hook files from `ext/<plugin>/`. The loader itself reads no manifest; packages are installed separately ([Installation and updates](#installation-and-updates)). The `ext/` tree is scanned once per request; each stage then requires its matching files once, in byte-sorted path order, with CHIM's scope (`$gameRequest` plus `$GLOBALS`). A throwing hook file is logged and skipped. Discovery skips files directly under `ext/`, dot-prefixed entries, symlinks, `private/` and `staging/` directories, top-level directories ending `.disabled` or containing a `.disabled` file, and the built-in `relationship_system` directory. A missing `ext/` is a no-op. `requireFilesRecursively($dir, $name)` uses the same rules and cache; given a plugin's own folder (for example `__DIR__`), it also loads matching files directly in that folder, skips `private/`, `staging/`, dot entries and symlinks below it, and loads nothing when the plugin's top-level folder is disabled or reserved.

| Hook file | `main.php` → model processors ([coverage](#model-route-coverage)) | [chat.php](../chat.php) (JSON) |
|---|---|---|
| `globals.php` | After bootstrap, before request parsing | After bootstrap |
| `preprocessing.php` | After `explode('|')`, before fields are derived; may rewrite `$gameRequest` | After payload validation |
| `prerequest.php` | Before processor routing, for every event type; `$gameRequest` changes are re-read | After `preprocessing.php` |
| `prompts.php`, `dialogue_prompt.php` | After the route's skip checks, before history | After history query |
| `context_building.php` | `$GLOBALS['CONTEXT_BUILDING_DATA']` holds role/content history messages | Same |
| `json_response_custom.php` | Once, when the first structured template is built (output contract, after `context_pre.php`); its direct edits reach that template, `HOOKS['JSON_TEMPLATE']` reaches every template | Same |
| `context_pre.php` | Before the system prompt is built | Same |
| `context.php` | `$GLOBALS['messages']` is complete, before the model call | Same |
| `prepostrequest.php`, `postrequest.php` | After the route completed its turn and sent its output; output is discarded | After the JSON response |

### Model route coverage

`globals.php`, `preprocessing.php` and `prerequest.php` run for every `main.php` event. The later stages run only on foreground routes that call a model, in the order shown, through the shared helpers in [lib/extension_hooks.php](../lib/extension_hooks.php). Each hook file still runs at most once per request, so a second model call in the same request sees no stage files again. A route with a different prompt shape skips stages that have nothing to act on rather than receiving an empty imitation (—).

| Route (event) | Caller | Prompt stages | `context_building.php` | `context_pre.php` | `json_response_custom.php` / `JSON_TEMPLATE` | Prompt sections | `context.php` | Post stages after |
|---|---|---|---|---|---|---|---|---|
| Player dialogue (`inputtext`, `inputtext_s`, answered `injection`) | [processor/chat.php](../processor/chat.php) | ✓ | ✓ | ✓ | ✓ | All | ✓ | Streamed reply |
| JSON chat | [chat.php](../chat.php) | ✓ | ✓ | ✓ | ✓ | All | ✓ | JSON response |
| NPC follow-up (`rechat`, `limb_loss`) | [processor/rechat.php](../processor/rechat.php) | ✓ | ✓ | ✓ | ✓ | All | ✓ | Streamed reply |
| Bored dialogue (`bored`) | [processor/bored.php](../processor/bored.php) | ✓ | ✓ | ✓ | ✓ | All | ✓ | Streamed reply |
| Director scene (`bored`, `mode=director`) | [lib/director_scene.php](../lib/director_scene.php) | ✓ | — history is text | ✓ | — scene schema | `prompt_bottom`, cast enrichment | ✓ | Scene payload |
| Manual diary (`diary`, `diary_narrator`) | [processor/diary.php](../processor/diary.php) | ✓ | — history is text | ✓ | — plain text | All | ✓ | At least one entry written |
| Narrator welcome (`init`) | [processor/init.php](../processor/init.php) | ✓ | ✓ | ✓ | — plain text | All | ✓ | Welcome line |

"All" prompt sections are `BIOGRAPHY_BUILDER` and `character_bottom` inside `<character>`, then the `<player_character>` enrichment block, then `prompt_bottom`. A route runs its prompt stages once it has passed its main skip checks, such as the bored chance gate, the interaction switch or a missing candidate; those skips run only the first three stages. A later exit without output (a target that cannot speak, a missing API key, a failed rechat or bored reply, a rejected Director scene, no diary entry) skips the post stages. Routes that send a fallback line instead, such as `...` for player dialogue or the default narrator welcome, count as completed. Routes without these stages: Hypnosis profile rewrites, model calls made inside another turn (autochat rewrite, random narration, relationship evaluation), background work (memory, middle-term, dynamic profiles, auto-diary, autonomy planning) and UI tools. Non-model events such as `funcret`, `addon_state`, `location` and the `info*` events run only the first three stages.

`chat.php` has no `$gameRequest`: hooks see a temporary `['inputtext', ts, gamets, 'Speaker: message']` view. Edits to its `Speaker: message` field in `preprocessing.php`/`prerequest.php` are applied; other edits are discarded. From `prompts.php` until the model reply is parsed, the view is also `$GLOBALS['gameRequest']`, so `JSON_TEMPLATE`, `BIOGRAPHY_BUILDER` and actor-enricher callbacks can read it; it is removed before the turn is stored. Stobe has no CHIM `$PROMPTS`/`$TEMPLATE_DIALOG` arrays; use the prompt stages to register injections. `ext/relationship_system` is never loaded by this mechanism; the evaluator below remains the only relationship turn owner.

PHP API (signatures, slots and priority ordering match CHIM; `stobe*` names are equivalent):

- `chimRegisterPromptInjection($slot, $id, $content, $priority = 100)` / `chimRenderPromptInjections($slot, $context)`. Rendered slots: `character_bottom` (inside `<character>`) and `prompt_bottom` (end of the system prompt). Context keys: `game_request`, `herika_name`, `npc_name`, `narrator_name`, `player_name`.
- `chimRegisterActorProfileEnricher($id, $callback, $priority = 100)` / `chimBuildActorProfileEnrichmentText($name, $type, $context)`. Stobe passes [context version 2](#actor-profile-enrichment-context); the callback signature and result handling are unchanged.
- `$GLOBALS['HOOKS']['JSON_TEMPLATE'][]` callbacks may edit `$GLOBALS['responseTemplate']` (prompt schema) and `$GLOBALS['structuredOutputTemplate']` (provider `response_format`). Strict schemas also need new properties in `required`.
- `$GLOBALS['HOOKS']['BIOGRAPHY_BUILDER'][$name]` receives `(&$bio, $npcData)`. Stobe has no CHIM dynamic biography fields, so `$bio` starts empty and the result joins `<character>`.
- `stobeRegisterExtensionAction('ExtCmd<Bridge>_<Action>', $description, ['target' => 'none'|'optional'|'required'])` from `globals.php`. A registered code is added to the action guidance, the structured `action` enum and the normalizer allowlist, then dispatched unchanged as `<actor>|ActionQueue|<code>@<target>`. When the request's client `people` list names exactly one identity for that actor, the line ends `|sid=<serial>`; the STOBE client needs it to run the action for any speaker other than the request's own NPC and accepts it only if it matches an identity it sent. The server never derives the serial from stored NPC data. A name that is unlisted, listed without a serial or listed with two serials gets no `sid`, and the client fails the action as `speaker unresolved`. Older clients ignore the token; built-in action lines are unchanged. The model sees the code itself, not a display alias. Unregistered `ExtCmd*` commands are rejected. A non-empty `ACTIONS_ALLOWLIST` setting must list the code; disabling actions removes it. `|`, `@`, brackets and newlines are stripped from the argument (120 characters maximum).

Client results and addon state use the existing `main.php` event transport and reach `prerequest.php`, which sees every event type. The STOBE client sends `funcret` with CHIM's data `command@<code>@<argument>@<completed|failed[: detail]>` plus a readable `infoaction` line for context: one outcome per action, and exactly one for each request an addon accepted. Besides the addon's own result it sends failures such as `speaker unresolved`, `actor unavailable`, `actor not loaded`, `rejected by handler`, `addon unregistered` and `timed out` (10 minutes); requests from before a game load are cancelled locally and never reported. Addon state arrives as `addon_state` with `<Addon>: <key>=<value>`. `main.php` acknowledges `funcret` and `addon_state` without storing them in the event log. By default there is no follow-up model call for `funcret`; see [action follow-ups](#action-follow-ups-opt-in) for the opt-in. A runnable example and probe are in [examples/plugin-parity](../examples/plugin-parity/README.md).

### Action follow-ups (opt-in)

A registered action can ask for one more model turn after the client reports that exact action completed:

```php
stobeRegisterExtensionAction('ExtCmdMyBridge_Report', 'Ask MyBridge for a status report.', [
    'target' => 'none',
    'followup' => [
        'enabled' => true,                 // required boolean; absent or false means no follow-up
        'prompt' => 'Reply with one short in-character line about the report below.',
        'arg_name' => 'target',            // optional, [A-Za-z][A-Za-z0-9_]{0,31}; names the argument in the context block
        'use_functions_again' => false,    // optional boolean
    ],
]);
```

`followup` must be an array with only these keys. Flags must be booleans. An enabled follow-up needs a non-blank prompt of at most 1000 bytes; whitespace runs are collapsed. Invalid options reject the whole registration with an `[ExtensionActions]` log line. Registrations without `followup` are unchanged.

This needs both sides of contract `stobe.addon_followup.v1`. Clients and requests without it remain acknowledgement-only:

1. **Issue.** A dialogue request with `addon_followup=1` in the `stream.php` query lets the server add `|aid=<n>` after `|sid=<serial>` on an `ActionQueue` line. This happens only for a code registered with an enabled follow-up and only when the line has a `sid`. Before echoing the line, the server commits a `stobe_addon_action_ledger` row with the aid (random positive uint32, unique), the current `PLAYTHROUGH_CAMPAIGN_ID`, the playthrough runtime generation, the interaction generation, the actor name and serial, the exact code and argument, and the time. The open-row count and insert run in one short transaction under an advisory lock, so concurrent requests cannot exceed 16 open rows; no model call or output happens inside it. No aid is issued during a follow-up turn, while 16 issued rows are open, when the actor or argument contains a character the `funcret` fields cannot echo exactly (`@`, `|`, brackets, tabs or newlines, invalid UTF-8, actor over 120 characters), or when the write fails or collides twice. The action itself is still sent as before.
2. **Report.** The client sends the unchanged `funcret` packet (`command@<code>@<argument>@<completed|failed[: detail]>`) and adds `addon_followup=1&aid=<aid>&sid=<serial>&arid=<client request id>` to the query, all positive uint32 decimals. Only `addon_followup=1` routes `funcret` to [processor/addon_followup.php](../processor/addon_followup.php); `prerequest.php` observers still see every result first.
3. **Claim.** A single primary-key `UPDATE … WHERE aid = $n AND state = 'issued'` must also match serial, code, argument, interaction generation, the current `PLAYTHROUGH_CAMPAIGN_ID` and the playthrough runtime generation, within 660 seconds; the client times out accepted requests at 10 minutes. Every statement uses bound parameters, and no event data is scanned or cast. Any matching result closes the row. Only `completed` or `completed: <detail>` for a code still registered with a follow-up proceeds. Missing or malformed IDs, a malformed packet, a mismatch, an unknown, stale or already-resolved aid, another playthrough, failures, timeouts and other terminal results all get the legacy `ok` with no model call. A row that did not match stays open for its real result. Concurrent copies of one delivery are serialized by the row update, so at most one proceeds.
4. **Turn.** When the interaction switch allows it, the issued NPC gets one turn. Its profile is the single `core_npc` row whose `storage_id` is `hand_<sid>` and whose name is the issued actor; a missing or duplicated registration or a different name gets `skipped unavailable`, with no namesake or `original_name` fallback. The connector must have an API key unless it is KoboldCpp, Player2 or on a loopback host. The turn runs through the normal stream engine: prompt, context and post-response stages, `stobeStreamDialogueViaLlm()` and the usual `ScriptQueue` lines, which carry `|sid=<serial>` for the issued actor. The context gains an `<addon_action_result>` block (actor, action, argument, `completed`, the client detail capped at 400 characters, the registered prompt as `<instruction>`). It is a tool outcome, not player speech. Actions are disabled unless `use_functions_again` is true, and actions chosen in a follow-up turn never get an aid. One issued action therefore costs at most one extra model call.

Every routed `funcret` response carries `X-Stobe-Addon-Followup: v1 accepted aid=<n>`, `v1 rejected`, `v1 skipped interaction-off` or `v1 skipped unavailable aid=<n>`. Reasons go to the PHP error log. The ledger is live infrastructure (see [lib/playthrough_policy.php](../lib/playthrough_policy.php)), not playthrough data. Rows older than a day are deleted on the next issue. It correlates the client's own result with what the server issued; it does not authenticate the caller. Anything that can post game events can also send player input.

The runtime generation is written by the first Playthrough Save switch or restore and changes on each one, including restores that keep the campaign ID. Until a server has switched once it is empty, so only the campaign ID and the TTL separate playthroughs there.

### Actor-profile enrichment context

Kenshi has no single player actor. The player controls squads of characters, each stored as an NPC, and `PLAYER_NAME` names the player character. Stobe therefore calls enrichers in three places. Every context has `source` and `context_version` `2`.

| Type, `source` | When | Other context keys | Output |
|---|---|---|---|
| `npc`, `nearby_actors` | Once per person (not animal) in the nearby snapshot used for `<nearby_actors>` | `metadata`, `npc_data` and `nearby`: the nearby snapshot entry, as in version 1; `registered_npc`; `player_side` | Joins that actor's line |
| `npc`, `director_cast` | Once per eligible Director cast member (12 at most) | `npc_data` and `registered_npc`: the same projection; `player_side` | Added to that actor's profile JSON as `extension_context` |
| `player`, `player_character` | Once per prompt on routes with all prompt sections | `registered_npc`, `player_side`, `squad_members` (up to 32 other members of the character's squads), `turn_speaker` | `<player_character>` block before `prompt_bottom` |

A Director cast member is eligible only when the request's people list gives that name exactly one serial, a positive 32-bit integer. `main.php` stores that list as `Name|hand_<serial>`, with a state tag such as `(sleeping)` after the name. The scene's `actor_id` is the client's original decimal serial. A name that is unlisted, has no serial, has a malformed or out-of-range serial, or has two different serials is left out of the cast. If the seed actor is left out, the scene fails without calling the model. The server never takes a serial from stored NPC data or guesses between people who share a name.

- The player character is the turn's speaker when that speaker is `PLAYER_NAME` or a member of a saved player squad (`PLAYER_SQUADS` in `conf_opts`, which is playthrough data); otherwise it is `PLAYER_NAME`. It is never the NPC whose prompt is being built. Bored and diary turns, and rechat turns that answer an NPC, have no player speaker and use `PLAYER_NAME`. Director scenes have no player block.
- Squad members remain `npc` entries in `<nearby_actors>`. `player_side` is `{"player_faction": bool, "squads": [...]}`: `player_faction` is true when the entry matches the current player faction or a saved squad, and `squads` lists up to eight saved squad names containing that character. Do not treat a squad member as the CHIM player.
- `registered_npc` is `null` for an unregistered name, otherwise `id`, `name`, `original_name`, `storage_id`, `race`, `gender`, `faction`, `faction_id`, `profile_id` and `in_player_faction` (strings capped at 120 characters). It uses the nearby appearance lookup's row preference. `id` works with [`NpcMaster::getPluginData()`](plugin-npc-data.md), which is one more query per call; cache it within the request.
- Registered rows are fetched only when an enricher is registered: one batch query for up to 32 names not yet cached, and the results (including misses) are reused for the rest of the request. A nearby list or player character adds no query when its names are already cached.
- Each rendered enrichment is collapsed to one line and capped at 600 characters. `chimBuildActorProfileEnrichmentText()` itself does not truncate.
- Callbacks receive the `player` type, as in CHIM. A callback that ignores `$type` now also writes the `<player_character>` block; return `''` for types you do not handle.

| Boundary | Current caller | What the input means |
|---|---|---|
| Event ingress | [main.php](../main.php) | Decodes `type|timestamp|gamets|data` and routes to processors. Event data is type-specific. |
| Dialogue processing | [processor/chat.php](../processor/chat.php) | Builds context, generates a reply and evaluates relationships for eligible turns. Generated text is not a playback acknowledgement. |
| JSON chat | [chat.php](../chat.php) | Separate `npc`, `player`, `message`, `mode`, `gamets`, `nearby`, `context`, `npcs` input path. Do not assume every `main.php` processor runs here. |
| Playback delivery | [speech_delivery.php](../speech_delivery.php) | Accepts JSON delivery updates; applies them through `stobeApplySpeechDeliveryUpdates()`. |
| Background maintenance | [service/manager.php](../service/manager.php) | Runs a manager tick using current database settings and game activity; it is not a plugin hook dispatcher. |

The copies under `ext/relationship_system` contain legacy hook comments. For current turn evaluation, inspect `stobeEvaluateRelationshipsForTurn()` and `stobePersistNpcRelationshipMap()` in [lib/chat_helper_functions.php](../lib/chat_helper_functions.php), including their caller in `processor/chat.php`. Do not describe the legacy extension worker as the active Stobe turn path.

## Required and optional state

Keep these stages separate: Player input, injected scene context, generated NPC response and playback delivery. The chat processor labels injected input as an event for relationship evaluation. Mentioning someone in text does not make them a listener. Player-faction/squad state is distinct from an individual NPC's profile; never replace it with a CHIM single-player assumption.

Delivery updates use this shape (synthetic fixture, not an instruction to POST to a live server):

```json
{"updates":[{"utterance_id":"example-001","delivery_state":"spoken"}]}
```

`speech_delivery.php` also accepts one top-level `utterance_id`/`delivery_state` pair. The updater skips non-array entries, empty IDs and states other than `spoken` or `cancelled`. If both states occur for one ID in a batch, `spoken` wins normalization; later database transition checks still apply. In particular, a late receipt cannot revive a cancelled Director line. An HTTP `ok` response does not mean every supplied update changed a row.

Stored legacy event rows are a different contract: a row without an utterance ID remains visible to context. Do not reject all such rows or invent receipt IDs for them. This CLI example calls the actual pure visibility helper without bootstrap, providers or database access. Run from the source root:

```php
<?php
require_once getcwd() . '/lib/data_functions.php';
$exampleRows = [
    [],
    ['utterance_id' => 'example-001', 'delivery_state' => 'pending'],
    ['utterance_id' => 'example-001', 'delivery_state' => 'spoken'],
    ['utterance_id' => 'example-001', 'delivery_state' => 'cancelled'],
];
$exampleVisible = array_map('stobeEventlogRowIsVisibleForContext', $exampleRows);
if ($exampleVisible !== [true, false, true, false]) {
    throw new RuntimeException('Unexpected delivery visibility contract');
}
```

An absent active Playthrough Saves profile is not automatically a malformed request. If an extension requires durable playthrough identity, skip its scoped write with a bounded diagnostic when that identity is unavailable. Do not fabricate profile `0` or create a save from a hook. A different Kenshi save alone does not isolate the server database.

The [playthrough policy](../lib/playthrough_policy.php) owns capture/restore membership. Arbitrary plugin tables are unmanaged; adding a table does not enroll it in saves. NPC plugin data follows its documented history rules. Respect the [runtime switch barrier](../lib/playthrough_runtime.php) and revalidate queued work after a switch; a cached NPC ID alone is insufficient.

## Atomic writes

One accepted effect needs one invariant: state, duplicate-prevention record and history all commit, or none do. Make model calls before the write transaction, then acquire the appropriate lock and re-read current state before applying output.

- Use one connection, parameterized values and a unique operation key. A preceding `SELECT` alone does not prevent concurrent duplicates. Include listener/subject/effect identity when one utterance can produce multiple effects.
- Stobe's [database wrapper](../lib/postgresql.class.php) returns a PostgreSQL result or `false` from `exec()` and `execQuery()`. Use `exec($sql, $params)` to bind values; `execQuery()` takes only a SQL string. Check `=== false`; do not import CHIM's empty-string-success convention.
- `stobePersistNpcRelationshipMap()` updates both `core_npc.relationships` and the JSON relationship map through `stobeRunWithRelationshipExtendedDataWrite()`. Preserve that product-specific representation and its protection of custom relationship information. This operation does not also commit your extension's duplicate ledger and history.
- `NpcMaster::setPluginData()` replaces one namespace; it does not combine separate writes into a transaction. Inspect all helper connection and transaction behavior before composing them. A transaction opened on another connection cannot cover these calls.
- Preserve unrelated state, manual relationship data and profile/faction ownership. Define valid no-change outcomes. Never turn a rejected or disbelieved suggestion into an obligatory affinity change.
- PostgreSQL `BEGIN` does not nest. Use a savepoint only with an explicit outer-transaction contract. On any failed statement, roll back rather than marking work complete.

The exercise below uses temporary tables only; it is not a recipe for bypassing Stobe's relationship writer.

### Runnable transaction exercise

Run these SQL blocks on **one connection to a disposable PostgreSQL database**. Do not substitute core table names. Stobe defines no standard plugin-table schema or automatic plugin-migration route here; establish table ownership, migrations and retention through a reviewed source integration.

```sql
CREATE TEMP TABLE example_effects (
    scope text NOT NULL, event_id text NOT NULL,
    PRIMARY KEY (scope, event_id)
);
CREATE TEMP TABLE example_affinity (
    scope text NOT NULL, listener_id bigint NOT NULL, subject_id bigint NOT NULL,
    affinity integer NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    PRIMARY KEY (scope, listener_id, subject_id)
);
CREATE TEMP TABLE example_history (
    scope text NOT NULL, event_id text NOT NULL,
    listener_id bigint NOT NULL, subject_id bigint NOT NULL, affinity integer NOT NULL,
    PRIMARY KEY (scope, event_id)
);
```

The example operation key represents one effect on one listener/subject pair. A real plugin must include those identities in its deduplication key when one utterance can produce multiple effects. Parameterize the fixture values when adapting this statement.

```sql
BEGIN;
WITH claimed AS (
    INSERT INTO example_effects (scope, event_id)
    VALUES ('test-session', 'effect-001')
    ON CONFLICT DO NOTHING
    RETURNING scope, event_id
), changed AS (
    INSERT INTO example_affinity AS current (scope, listener_id, subject_id, affinity)
    SELECT scope, 1, 2, 5 FROM claimed
    ON CONFLICT (scope, listener_id, subject_id) DO UPDATE
    SET affinity = LEAST(100, GREATEST(-100, current.affinity + EXCLUDED.affinity))
    RETURNING scope, listener_id, subject_id, affinity
)
INSERT INTO example_history (scope, event_id, listener_id, subject_id, affinity)
SELECT changed.scope, claimed.event_id, listener_id, subject_id, affinity
FROM changed JOIN claimed USING (scope);
COMMIT;
```

After one run, affinity is `5` with one effect and one history row. Repeating the operation leaves those values unchanged. To test rollback, repeat with a new event ID and replace `COMMIT` with `ROLLBACK`; none of that operation's three writes should remain. On any statement error, roll back and report failure rather than continuing to commit or marking the job complete.

## Installation and updates

StobeServer uses the schema-4 ZIP-compatible `.dwpkg`/`.zip` [package manager](../lib/plugin_package_manager.php) shared with CHIM and Dialectic. A package has root `manifest.json` (`schema_version` 4, `name`, `version`, `server`), `checksums.sha256` and payload under `server/`, which installs to `ext/<name>/`. Optional `server/migrations/*.sql` run on install; `server.mutable_paths` survive updates. Updates, removal, reinstall and rollback keep the folder recorded in the ledger, so a manifest `name` that changes only letter case still updates the same `ext/` folder. Build the example with [examples/plugin-parity/build_package.php](../examples/plugin-parity/README.md#build-the-package); do not commit built archives.

- **Game bundle.** The STOBE client syncs packages from the active mod's `Stobe/server-plugins/<name>/<version>.dwpkg` (for example `Stobe/server-plugins/parity_probe/1.0.0.dwpkg`). It probes [ui/api/plugin_packages.php](../ui/api/plugin_packages.php) and uploads only when the installed version differs or its files are missing. The folder must match the manifest `name` and the file stem its `version`. This needs a STOBE client revision with package sync; an older client syncs nothing.
- **Server Plugins page.** [ui/server_plugins.php](../ui/server_plugins.php) uploads a package, and installs or switches catalog entries from [ui/data/plugin_repository.json](../ui/data/plugin_repository.json) on their Live or Dev channel. The page never checks releases on load; **Check for Updates** and an install or channel switch fetch them. The page lists each plugin's `.disabled` marker, a `config_url` from its manifest when it names an existing file inside the plugin's own `ext/` folder (`settings.php`, `ext/<folder>/settings.php` or `/<web root>/ext/<folder>/settings.php`; links are relative to `ui/`), and an HTTPS-only `mod_download_url` with `<version>` replaced. Other values are omitted.
- **Remove.** Only ledger-managed packages can be removed, after confirmation. The folder moves out of `ext/` into retained package storage; database tables, migration records and declared mutable data are kept, and reinstalling the same plugin restores them. A game-bundled package is reinstalled at the next game load unless its addon is disabled first.
- **Protected.** Built-in `relationship_system` is protected. Unmanaged `ext/` folders are listed and cannot be removed through the page, but installing a package with the same folder name can replace one after backing it up.

Installing a package does not prove it executed. Then:

1. Verify supported Stobe/client versions and the stage(s) the extension needs. If it needs a stage the loader does not run, resolve that source integration before distributing it.
2. Use an isolated Stobe database for the first install. Back up the extension's configuration and data before an update.
3. Install through one route (game bundle, upload or catalog) and keep it for updates. Manual file copies bypass the ledger and appear as unmanaged.
4. Verify the installed version on Server Plugins and a known integration event in the server/client logs.
5. Removing game-side files only stops future sync; server removal is a separate operation. Do not delete tables to “reset” a failed install.

See the [STOBE client](https://github.com/Dwemer-Dynamics/STOBE) for game-side integration; a PHP extension cannot add a native Kenshi action by itself.

## Background model calls

Use [service/start.sh](../service/start.sh), [service/manager.php](../service/manager.php), [lib/dynamic_profile_scheduler.php](../lib/dynamic_profile_scheduler.php) and [lib/memory_helper_functions.php](../lib/memory_helper_functions.php) as the current maintenance example. The manager records heartbeat state, runs maintenance, then applies interaction, game-clock and activity gates to later work. Those checks are not automatic protection for an unrelated custom process.

To exercise a manager tick, first configure all `STOBE_DB_*` variables for a disposable database, bootstrap it using the [test instructions](building.md), and use controlled providers. From the source root:

```sh
php service/manager.php
```

This writes maintenance state and can call configured providers. It is not a lint command or a no-op plugin worker. Run only on the prepared test instance. If the game clock or activity gate is inactive, AI work can legitimately stay pending.

For a custom job, define its enqueue point, bounded payload, stable scoped operation key, claim/lease policy, timeout, retry limit and shutdown behavior. Keep provider calls outside database transactions. Commit accepted effects and completion bookkeeping consistently, and handle a crash after commit without applying the effect twice. Do not reuse another feature's lock/PID files or treat the manager as a generic plugin queue API.

## Validation and reports

Lint extracted PHP examples and run the visibility fixture above. Execute the transaction exercise in disposable PostgreSQL. Test actual integration separately with absent optional state, duplicate/cancelled delivery, no active profile, update/removal and provider failure. Use the [existing regression workflow](../.github/workflows/pr-tests.yml) and [test safety guidance](building.md); never point regression scripts at the live playthrough.

Record client/server/extension versions, event route, speaker/listener/subject, expected versus actual result and filtered logs. Source checks do not establish Kenshi gameplay or provider success.
