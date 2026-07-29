# Kenshi Vanilla World-State Source of Truth

FCS defines validity. Wiki sources provide public names, String ID corroboration, and documentation coverage.

## Coverage

| Metric | Count |
| --- | ---: |
| fcs_queries | 152 |
| fcs_character_targets | 61 |
| wiki_confirmed_character_targets | 56 |
| wiki_index_confirmed_character_targets | 48 |
| wiki_page_confirmed_character_targets | 8 |
| wiki_identity_only_character_targets | 5 |
| fcs_only_character_targets | 0 |
| wiki_only_unresolved_characters | 0 |
| wiki_string_id_conflicts | 0 |
| fcs_faction_targets | 14 |
| wiki_town_locations | 53 |
| wiki_town_locations_with_fcs_variant_match | 49 |
| wiki_town_locations_without_direct_variant_name | 4 |

## Individual-Page World-State Coverage

- [Spider Foreman](https://kenshi.fandom.com/wiki/Spider_Foreman) (`64856-rebirth.mod`)
- [Bo](https://kenshi.fandom.com/wiki/Bo) (`7994-D-Dialogue.mod`)
- [Big Darkbrow](https://kenshi.fandom.com/wiki/Big_Darkbrow) (`48739-Dialogue.mod`)
- [Sand Ninja Oni](https://kenshi.fandom.com/wiki/Sand_Ninja_Oni) (`51676-Dialogue.mod`)
- [Mad Cat-Lon](https://kenshi.fandom.com/wiki/Mad_Cat-Lon) (`1533858-rebirth.mod`)
- [Gutterhead](https://kenshi.fandom.com/wiki/Gutterhead) (`98227-__March 18.mod`)
- [Dimak](https://kenshi.fandom.com/wiki/Dimak) (`56888-Dialogue.mod`)
- [Dust King](https://kenshi.fandom.com/wiki/Dust_King) (`2849-gamedata.base`)

## Wiki Identity Only

- [Eyegore](https://kenshi.fandom.com/wiki/Eyegore) (`56576-Dialogue.mod`)
- [Agnu](https://kenshi.fandom.com/wiki/Agnu) (`97095-rebirth.mod`)
- [Grey](https://kenshi.fandom.com/wiki/Grey) (`56734-Dialogue.mod`)
- [Jaegar](https://kenshi.fandom.com/wiki/Jaegar) (`56890-Dialogue.mod`)
- [Beep](https://kenshi.fandom.com/wiki/Beep) (`57390-rebirth.mod`)

## FCS-Only Valid Character Targets

- None

## Wiki-Only Unresolved Characters

- None

## String ID Conflicts

- None

## Wiki Towns Without a Direct Variant-Name Match

- [Distant Hive Village](https://kenshi.fandom.com/wiki/Distant_Hive_Village)
- [Southern Hive](https://kenshi.fandom.com/wiki/Southern_Hive_(Location))
- [Southern Hive Village](https://kenshi.fandom.com/wiki/Southern_Hive_Village)
- [Western Hive](https://kenshi.fandom.com/wiki/Western_Hive_(Location))

## Interpretation

- `wiki_index_confirmed` means a pinned World States index resolved to the same FCS character target.
- `wiki_page_confirmed` means the actor is omitted from the index but its individual page has a World States section.
- `wiki_identity_only` means the wiki corroborates the actor identity but does not currently document a World States section.
- `fcs_only` is still a valid vanilla world-state target; the index pages simply do not list it.
- Town matching compares public wiki names with FCS town-variant names. An unmatched name is a documentation/alias gap, not evidence that the town override is invalid.
- Campaign, dialogue, squad, and town consumers remain eligibility conditions until Stobe evaluates the referenced query in game.
