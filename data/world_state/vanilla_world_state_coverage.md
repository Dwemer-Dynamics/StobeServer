# Kenshi Vanilla World-State Coverage

Generated deterministically from the official FCS data model and the four vanilla data layers.

## Extraction

| Metric | Count |
| --- | ---: |
| Records loaded | 54951 |
| WORLD_EVENT_STATE queries | 152 |
| Query rules | 211 |
| Reverse-reference consumers | 1638 |
| Town variants using world state | 126 |
| Queries mapped to world knowledge | 144 |
| Queries without a world-knowledge match | 8 |

## Query Classification

| Classification | Queries |
| --- | ---: |
| ambiguous_empty_query | 8 |
| durable_world_fact | 144 |

## Semantics

- `WORLD_EVENT_STATE` rules are combined with AND.
- Dialogue references are dialogue conditions.
- Campaign references are eligibility gates, not proof that a campaign occurred.
- Squad and town references are spawn or variant conditions, not proof that a spawn occurred.
- A false multi-rule query does not reveal which rule failed, so no false addendum is generated for it.
- `source_mod` is FCS record provenance and can contain internal development filenames even though only official vanilla layers were loaded.

## Consumer Coverage

| Consumer type | References |
| --- | ---: |
| DIALOGUE | 36 |
| DIALOGUE_LINE | 874 |
| FACTION_CAMPAIGN | 252 |
| SQUAD_TEMPLATE | 179 |
| TOWN | 297 |

## Validation

- PASS - vanilla_layers_loaded
- PASS - nonzero_world_event_state_queries
- PASS - every_query_classified
- PASS - all_rule_shapes_supported
- PASS - all_consumer_shapes_supported
- PASS - all_references_resolved
- PASS - all_explicit_topic_mappings_resolved

## Unmapped World-Knowledge Entities

- CHARACTER:Slave Market Master
- CHARACTER:Yabuta of the Sands

## Runtime Integration

StobeServer seeds built-in addenda from this catalog and injects current query-result text at prompt time. Stobe remains responsible for safe game-thread query evaluation; the extractor never enables a background `GameData` scan.
