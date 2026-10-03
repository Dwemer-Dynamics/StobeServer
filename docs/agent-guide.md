# StobeServer architecture and agent guide

StobeServer is the PHP/PostgreSQL backend for the [STOBE Kenshi client](https://github.com/Dwemer-Dynamics/STOBE). Server source is [Dwemer-Dynamics/StobeServer](https://github.com/Dwemer-Dynamics/StobeServer). Start with [AGENTS.md](../AGENTS.md), including its existing playthrough rules, and [setup and validation](building.md).

## Installed server or source checkout?

A deployed application can contain the source files plus valuable live data. Inspect `.version_number.txt`, `version.txt`, Git state when available, and the actual web-server document root before choosing a source revision. The usual DwemerDistro path is `/var/www/html/StobeServer`; custom installations can differ. Current upstream documentation may describe a later revision than the installed server.

Preserve the database, connector credentials, profiles, memories, `conf/`, `ext/`, `log/`, `soundcache/`, uploads, and other generated user content when updating. Never replace an existing server wholesale with a clean Git checkout. Reading instructions does not authorize migrations, restarts, database recreation, or plugin installation.

## Runtime flow and source map

The client captures Kenshi context and sends HTTP requests. Entry points route them through bootstrap and event processors. The server reads NPC/squad/world state and recent history, builds prompts, calls a model connector, and streams dialogue/actions back. Speech connectors synthesize/cache audio; the client applies actions and plays audio. Background work maintains memory and profiles outside foreground chat.

| Change or diagnostic | Start here |
| --- | --- |
| Request ingress and streaming | `main.php`, `chat.php`, `gamedata.php`, `stream.php` |
| Bootstrap and database connection | `lib/bootstrap.php`, `lib/postgresql.class.php` |
| Event routing | `processor/` |
| Prompt/context and response shaping | `lib/chat_helper_functions.php` |
| NPC storage and identity | `lib/data_functions.php`, `npc_snapshot.php`, `get_batch_identities.php` |
| Model providers | `connector/llm_dispatcher.php`, `connector/` |
| Speech recognition/synthesis | `stt.php`, `stt/`, `tts/`, `dialogue_tts.php` |
| Settings and administration | `lib/settings.php`, `ui/` |
| Schema and upgrades | `data/schema.sql`, `debug/db_updates.php`, `debug/run_db_updates.php` |
| Background memory/profile work | `service/start.sh`, `service/manager.php` |
| Saves, switching and recovery | `lib/playthrough_policy.php` and the root agent instructions |

## Configuration and troubleshooting

Runtime settings live in PostgreSQL (`general_settings`, profiles and related tables); this server does not use CHIM's runtime `conf.php` model. Connection environment variables are `STOBE_DB_HOST`, `STOBE_DB_NAME`, `STOBE_DB_USER`, and `STOBE_DB_PASSWORD` in `lib/postgresql.class.php`. Apply them to the relevant PHP/worker process environment; a shell variable alone does not configure Apache.

Start with server `log/`, Apache/PHP errors, the worker process and heartbeat, and client `stobe.log`. `service/start.sh` uses a local heartbeat listener on `127.0.0.1:12346`. Inspect the running instance before restarting anything.

For missing replies, trace ingress, processor selection, connector output, streaming, and client handling in that order. For speech failures, distinguish STT upload, model output, TTS generation, audio fetch, and client playback. For incorrect NPC context, compare captured identity and stored snapshots. Redact credentials and private conversation data from shared evidence.

## Custom plugins and extensions

Read the [integration runtime reference](plugin-runtime.md) for execution timing, required and optional state, atomic writes, installation/update boundaries and background work.

Choose the extension boundary before coding:

- Existing connector/profile/prompt configuration may be enough for a provider or behavior change. Try the existing OpenAI-compatible connector for a compatible service.
- A new provider adapter is a source contribution: use [connector/openaijson.php](../connector/openaijson.php) and [connector/llm_dispatcher.php](../connector/llm_dispatcher.php) as current examples. Register and validate the type and any settings paths; dropping a PHP file into `connector/` does not register it.
- CHIM-named hook files in `ext/<plugin>/` run through [lib/extension_hooks.php](../lib/extension_hooks.php) at the stages listed in the runtime reference, with CHIM's prompt-injection, actor-enricher, `JSON_TEMPLATE`, `BIOGRAPHY_BUILDER` and registered `ExtCmd` action APIs. Schema-4 `.dwpkg` packages install them through [the package manager](../lib/plugin_package_manager.php) and the [Server Plugins page](../ui/server_plugins.php); installation does not prove execution. Stobe's call sites, state and actions differ from Skyrim; a CHIM plugin is not automatically compatible. Start from [examples/plugin-parity](../examples/plugin-parity/README.md).
- [ext/relationship_system](../ext/relationship_system/README.md) is excluded from that loader. Its hook files are legacy; the active evaluator is in `lib/chat_helper_functions.php`. Verify actual call sites before copying its patterns.

Keep an independently distributed extension in its own maintained repository and deploy only its own directory after authorization. Most `ext/*` files are Git-ignored; do not mistake a runtime copy for the source repository. Preserve unrelated extensions. Use idempotent migrations, explicit configuration, validated input and bounded background work. Check playthrough table policy rather than adding new tables to saves implicitly.

Lint changed PHP, exercise the hook/adapter with a disposable database and controlled provider, and test disabled/missing-dependency behavior. A new game action also needs a matching client implementation in [STOBE](https://github.com/Dwemer-Dynamics/STOBE); server text cannot create a new native game capability by itself. Report provider, worker and in-game tests separately.

## Distribution

Ship root `AGENTS.md` and `docs/` with the server source/application. Keep relative links intact in copied installations and release archives. Check the actual updater's include/exclude rules when changing distribution; documentation presence in Git alone does not prove it reached a deployed server.
