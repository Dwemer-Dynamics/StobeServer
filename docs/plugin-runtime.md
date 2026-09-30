# Stobe plugin runtime reference

This guide covers StobeServer's current `unstable` source. Read the [agent guide](agent-guide.md), [safe test setup](building.md) and [NPC plugin-data contract](plugin-npc-data.md) first. Check the linked callers against the version your extension supports; CHIM examples are not automatically compatible with Kenshi.

## Integration points and timing

There is no generic CHIM-style `ext/*` hook loader or package catalog in this revision. A file named `prerequest.php` or `postrequest.php` does not register a custom extension. Choose an existing configuration surface or a reviewed source integration point. An independent extension needs an explicit loading contract before it can be installed as a working feature.

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

StobeServer does not implement the CHIM/Dialectic schema-4 package manager in this revision. Do not promise that a `.dwpkg`, CHIM catalog entry or MO2 sync folder will install or activate a Stobe extension.

1. Obtain the extension's own source, supported Stobe/client versions, explicit integration point and installation instructions. If no loader exists for it, resolve that source integration before distributing it.
2. Use an isolated Stobe database for testing. Back up the extension's configuration and data before an update.
3. Deploy only the extension-owned files through its documented route. Preserve other extensions, database state and runtime configuration. File presence is not proof of execution.
4. Verify its version and a known integration event in the server/client logs. Use one maintained route for subsequent updates; prevent an older local sync from overwriting newer files.
5. Treat removal of game-side files and removal of server-side code/data as separate operations. Follow the author's uninstall procedure; do not delete tables to “reset” a failed install.

MO2-specific CHIM packaging warnings do not define a Kenshi installation contract. See the [STOBE client](https://github.com/Dwemer-Dynamics/STOBE) for game-side integration; a PHP extension cannot add a native Kenshi action by itself.

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
