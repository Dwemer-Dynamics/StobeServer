# StobeServer Copilot Instructions

- This repository is the PHP backend for the Stobe AI framework for Kenshi.
- `main.php` is the primary runtime router. It parses incoming game events, sets up request globals, performs JIT identity/profile work, and dispatches to `processor/*.php`.
- `lib/bootstrap.php` is the canonical runtime bootstrap. It initializes the DB handle, loads shared helpers, configures logging, registers the background processor helpers, and loads TTS modules.
- `processor/` contains event-specific handlers such as `chat.php`, `rechat.php`, `combat.php`, `context.php`, `location.php`, `infonpc.php`, and `diary.php`.
- `lib/chat_helper_functions.php`, `lib/data_functions.php`, `lib/memory_helper_functions.php`, `lib/middleterm_helper_functions.php`, `lib/dynamic_profile_helper_functions.php`, `lib/diary_helper_functions.php`, and `lib/narrator_helper_functions.php` contain most of the prompt assembly, memory, and world-state logic.
- `connector/` contains LLM connector implementations and dispatch helpers.
- `tts/` and `stt/` contain speech connector logic.
- `service/start.sh` and `service/manager.php` implement the background processor loop used for maintenance work outside foreground chat latency.
- `ui/` contains the dashboard/admin UI.
- `data/schema.sql` is the primary schema source. `debug/run_db_updates.php` is used for DB update application.

- StobeServer is DB-settings driven. Runtime settings live in the `general_settings` table, not in `conf.php` or INI files.
- Do not introduce Herika-style `conf.php` assumptions into StobeServer runtime logic.

- Repository-tracked content intentionally includes shipped reference assets such as `data/voices/*`, SQL/import files under `data/`, and tracked relationship-system code under `ext/relationship_system/**`.
- Runtime or local-only directories are intentionally not source of truth:
- `log/` is runtime log output
- `soundcache/` is generated TTS cache
- most of `ext/` is ignored except `ext/relationship_system/**`
- local editor config under `.vscode/` is ignored

- If you touch request routing or prompt construction, check both `main.php` and the corresponding `processor/*.php` plus `lib/*helper_functions.php`.
- If you touch DB shape or defaults, update `data/schema.sql` and review `debug/run_db_updates.php`.
- If you touch background maintenance or memory cycles, inspect both `lib/background_processor.php` and `service/manager.php`.
- If you touch the relationship system, use the tracked `ext/relationship_system/**` subtree, not ignored runtime files under `ext/`.

- There is no compile step, but PHP validation and regression scripts matter.
- In the current monorepo environment, these WSL PHP syntax checks were validated successfully:

```powershell
wsl -d DwemerAI4Skyrim3 bash -lc "php -l '/mnt/c/Users/reece/Desktop/Dwemer Dynamics/MonoRepo/StobeServer/main.php'"
wsl -d DwemerAI4Skyrim3 bash -lc "php -l '/mnt/c/Users/reece/Desktop/Dwemer Dynamics/MonoRepo/StobeServer/lib/bootstrap.php'"
wsl -d DwemerAI4Skyrim3 bash -lc "php -l '/mnt/c/Users/reece/Desktop/Dwemer Dynamics/MonoRepo/StobeServer/processor/chat.php'"
```

- Targeted regression coverage lives in `tests/*.php`.
- In the current monorepo environment, the existing wrapper command is:

```powershell
.\scripts\test-stobe-server.ps1 -RenameSnapshot
```

- Do not assume all regression subsets are green without running the relevant test for the area you changed.
- In the current monorepo environment, deployment to the WSL runtime is handled by:

```powershell
.\scripts\deploy-stobe.ps1
```

- There are no repository-local GitHub Actions workflows under `.github/`; only a PR template is present.
- Trust these instructions first and only search more broadly when they are incomplete.
