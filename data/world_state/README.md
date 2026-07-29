# Kenshi Vanilla World-State Catalog

Run the extractor from the StobeServer repository:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\export-kenshi-world-state-catalog.ps1
```

Use `-KenshiPath` when Kenshi is installed outside the detected Steam locations:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\export-kenshi-world-state-catalog.ps1 `
  -KenshiPath 'D:\SteamLibrary\steamapps\common\Kenshi'
```

The extractor:

- Loads `gamedata.base`, `Newwworld.mod`, `Dialogue.mod`, and `rebirth.mod` through the official FCS `GameData` API.
- Runs in a 32-bit process because the FCS executable is 32-bit.
- Reads only the official game data and never saves or modifies a mod.
- Exports every `WORLD_EVENT_STATE` rule and every reverse-reference consumer.
- Separates durable query facts from dialogue, campaign, squad, and town eligibility semantics.
- Generates safe true-result prompt addenda and false-result addenda only for single-rule queries.
- Maps exact character, faction, and town names to current world-knowledge topics and aliases.
- Applies and validates explicit query mappings from `vanilla_world_state_knowledge_map.csv`.
- Fails when FCS exposes an unsupported rule, consumer, value, or unresolved reference.

Committed inputs and runtime data:

- `vanilla_world_state_catalog.json`: compact runtime query definitions, mappings, and prompt translations.
- `vanilla_world_knowledge_manifest.json`: approved wiki sources, aliases, classes, and tags for missing World Knowledge topics.
- `vanilla_world_state_knowledge_map.csv`: explicit query-to-topic mappings used during every catalog regeneration.

Regenerable review artifacts are ignored by Git:

- `vanilla_world_state_catalog.full.json`: complete rules, consumers, town variants, mappings, and prompt translations.
- `vanilla_world_state_addenda.csv`: compact prompt-addendum review sheet.
- `vanilla_world_state_coverage.json` and `.md`: machine-readable and human-readable coverage reports.
- `wiki_world_state_sources.json`: pinned wiki revisions, actor String IDs, and documented town locations.
- `vanilla_world_state_source_of_truth.json`: FCS-authoritative records enriched with wiki corroboration.
- `vanilla_world_state_wiki_comparison.md`: wiki-confirmed, FCS-only, and unresolved comparison report.
- `vanilla_world_knowledge_generation.json`: GLM generation provenance and cache.

Generate the approved missing World Knowledge articles through OpenRouter GLM:

```powershell
python .\tools\generate-kenshi-world-state-knowledge.py `
  --manifest .\data\world_state\vanilla_world_knowledge_manifest.json `
  --output-csv "$env:TEMP\stobe-world-knowledge.csv" `
  --report-json "$env:TEMP\stobe-world-knowledge-report.json" `
  --api-key-file C:\path\to\openrouterapikey.txt
```

The generator writes review artifacts only. It never connects to or writes to the Stobe database.

Refresh the wiki snapshot and rebuild the source of truth:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\refresh-kenshi-world-state-wiki.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\build-kenshi-world-state-source-of-truth.ps1
```

The source-of-truth builder reads `vanilla_world_state_catalog.full.json`, so run the extractor before rebuilding the wiki comparison. FCS remains authoritative because the wiki intentionally documents notable actors and locations rather than every composite query. Wiki omissions are retained as coverage gaps and never remove valid FCS records.

The extractor never reads live game memory or enables a background `GameData` scan. Stobe evaluates loaded queries on its safe game-thread path, and StobeServer consumes those results for prompt-time addenda.
