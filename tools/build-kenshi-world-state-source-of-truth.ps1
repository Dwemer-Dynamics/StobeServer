[CmdletBinding()]
param(
    [string]$CatalogPath,
    [string]$WikiSourcesPath,
    [string]$OutputDirectory
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = Split-Path -Parent $scriptDirectory
if ([string]::IsNullOrWhiteSpace($CatalogPath)) {
    $CatalogPath = Join-Path $repositoryRoot 'data\world_state\vanilla_world_state_catalog.json'
}
if ([string]::IsNullOrWhiteSpace($WikiSourcesPath)) {
    $WikiSourcesPath = Join-Path $repositoryRoot 'data\world_state\wiki_world_state_sources.json'
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repositoryRoot 'data\world_state'
}

$CatalogPath = [System.IO.Path]::GetFullPath($CatalogPath)
$WikiSourcesPath = [System.IO.Path]::GetFullPath($WikiSourcesPath)
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)

function ConvertTo-NormalizedName {
    param([AllowNull()][string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return ''
    }
    return ((($Value.ToLowerInvariant() -replace '[\u2018\u2019]', "'") -replace '[^a-z0-9]+', ' ') -replace '\s+', ' ').Trim()
}

function ConvertTo-BaseTownName {
    param([string]$Value)

    $name = $Value.Trim().TrimStart('_')
    $name = $name -replace '\s*\(override.*$', ''
    $name = $name -replace '\s*\(destroyed\).*$', ''
    return $name.Trim()
}

function Write-Utf8WithoutBom {
    param(
        [string]$Path,
        [string]$Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

foreach ($path in @($CatalogPath, $WikiSourcesPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required source file was not found at '$path'."
    }
}

$catalog = Get-Content -LiteralPath $CatalogPath -Raw | ConvertFrom-Json
$wikiSources = Get-Content -LiteralPath $WikiSourcesPath -Raw | ConvertFrom-Json
$sourceById = @{}
foreach ($source in $wikiSources.sources) {
    $sourceById[[string]$source.id] = $source
}

$actorRuleGroups = @($catalog.queries.rules |
    Where-Object { $_.target_type -eq 'CHARACTER' } |
    Group-Object target_id)
$actorById = @{}
$actorByName = @{}
foreach ($group in $actorRuleGroups) {
    $first = $group.Group[0]
    $record = [ordered]@{
        string_id = [string]$first.target_id
        name = [string]$first.target_name
        validity = 'confirmed_by_fcs'
        wiki_status = 'fcs_only'
        wiki_references = New-Object System.Collections.Generic.List[object]
        query_ids = @()
        rule_categories = @($group.Group.category | Sort-Object -Unique)
        consumer_types = @()
        affected_towns = @()
    }
    $actorById[$record.string_id] = $record
    $actorByName[(ConvertTo-NormalizedName $record.name)] = $record
}

$wikiOnlyActors = New-Object System.Collections.Generic.List[object]
$wikiConflicts = New-Object System.Collections.Generic.List[object]
foreach ($entry in $wikiSources.actor_entries) {
    $matchedActor = $null
    $matchMethod = $null
    $entryStringId = ''
    if ($entry.PSObject.Properties.Name -contains 'string_id') {
        $entryStringId = [string]$entry.string_id
    }
    if (-not [string]::IsNullOrWhiteSpace($entryStringId) -and $actorById.ContainsKey($entryStringId)) {
        $matchedActor = $actorById[$entryStringId]
        $matchMethod = 'string_id'
    }
    elseif ($entry.PSObject.Properties.Name -contains 'fcs_name' -and -not [string]::IsNullOrWhiteSpace([string]$entry.fcs_name)) {
        $key = ConvertTo-NormalizedName ([string]$entry.fcs_name)
        if ($actorByName.ContainsKey($key)) {
            $matchedActor = $actorByName[$key]
            $matchMethod = 'fcs_name'
        }
    }
    if ($null -eq $matchedActor) {
        foreach ($candidate in @([string]$entry.display_name, [string]$entry.wiki_title)) {
            $key = ConvertTo-NormalizedName $candidate
            if ($actorByName.ContainsKey($key)) {
                $matchedActor = $actorByName[$key]
                $matchMethod = 'normalized_name'
                break
            }
        }
    }
    if ($null -eq $matchedActor -and $wikiSources.actor_aliases.PSObject.Properties.Name -contains [string]$entry.display_name) {
        $aliasId = [string]$wikiSources.actor_aliases.([string]$entry.display_name)
        if ($actorById.ContainsKey($aliasId)) {
            $matchedActor = $actorById[$aliasId]
            $matchMethod = 'explicit_alias'
        }
    }

    if ($null -eq $matchedActor) {
        $wikiOnlyActors.Add([ordered]@{
            source_id = [string]$entry.source_id
            wiki_title = [string]$entry.wiki_title
            display_name = [string]$entry.display_name
            string_id = $entryStringId
            page_url = [string]$entry.page_url
        })
        continue
    }

    if (-not [string]::IsNullOrWhiteSpace($entryStringId) -and $entryStringId -ne $matchedActor.string_id) {
        $wikiConflicts.Add([ordered]@{
            source_id = [string]$entry.source_id
            wiki_title = [string]$entry.wiki_title
            wiki_string_id = $entryStringId
            matched_fcs_string_id = $matchedActor.string_id
        })
        continue
    }

    $source = $sourceById[[string]$entry.source_id]
    if ([string]$entry.source_id -eq 'kenshi_fandom_character_pages') {
        if ([bool]$entry.has_world_states_section -and $matchedActor.wiki_status -ne 'wiki_index_confirmed') {
            $matchedActor.wiki_status = 'wiki_page_confirmed'
        }
        elseif ($matchedActor.wiki_status -eq 'fcs_only') {
            $matchedActor.wiki_status = 'wiki_identity_only'
        }
    }
    else {
        $matchedActor.wiki_status = 'wiki_index_confirmed'
    }
    $matchedActor.wiki_references.Add([ordered]@{
        source_id = [string]$entry.source_id
        source_revision_id = [int]$source.revision_id
        section = [string]$entry.section
        wiki_title = [string]$entry.wiki_title
        page_url = [string]$entry.page_url
        match_method = $matchMethod
        page_string_id = if ($entry.PSObject.Properties.Name -contains 'string_id') { $entryStringId } else { $null }
        page_revision_id = if ($entry.PSObject.Properties.Name -contains 'page_revision_id') { [int]$entry.page_revision_id } else { $null }
        has_world_states_section = if ($entry.PSObject.Properties.Name -contains 'has_world_states_section') { [bool]$entry.has_world_states_section } else { $null }
    })
}

foreach ($query in $catalog.queries) {
    foreach ($rule in $query.rules | Where-Object { $_.target_type -eq 'CHARACTER' }) {
        $actor = $actorById[[string]$rule.target_id]
        $actor.query_ids = @($actor.query_ids + [string]$query.query_id | Sort-Object -Unique)
        $actor.consumer_types = @($actor.consumer_types + $query.consumers.owner_type | Sort-Object -Unique)
        $actor.affected_towns = @($actor.affected_towns + @(
            $query.consumers |
                Where-Object { $_.owner_type -eq 'TOWN' } |
                ForEach-Object { [string]$_.owner_name }
        ) | Sort-Object -Unique)
    }
}

$factionStates = @($catalog.queries.rules |
    Where-Object { $_.target_type -eq 'FACTION' } |
    Group-Object target_id |
    ForEach-Object {
        $first = $_.Group[0]
        [ordered]@{
            string_id = [string]$first.target_id
            name = [string]$first.target_name
            validity = 'confirmed_by_fcs'
            query_ids = @($catalog.queries |
                Where-Object {
                    @($_.rules | Where-Object { $_.target_id -eq [string]$first.target_id }).Count -gt 0
                } |
                ForEach-Object { [string]$_.query_id } |
                Sort-Object -Unique)
            rule_categories = @($_.Group.category | Sort-Object -Unique)
        }
    } |
    Sort-Object name)

$fcsTownCandidates = New-Object System.Collections.Generic.List[object]
foreach ($town in $catalog.town_variants) {
    $fcsTownCandidates.Add([ordered]@{
        string_id = [string]$town.town_id
        name = [string]$town.town_name
        base_name = ConvertTo-BaseTownName ([string]$town.town_name)
    })
    foreach ($override in $town.override_towns) {
        $fcsTownCandidates.Add([ordered]@{
            string_id = [string]$override.target_id
            name = [string]$override.target_name
            base_name = ConvertTo-BaseTownName ([string]$override.target_name)
        })
    }
}

$townComparisons = New-Object System.Collections.Generic.List[object]
foreach ($entry in $wikiSources.town_entries) {
    $candidateNames = New-Object System.Collections.Generic.List[string]
    $candidateNames.Add([string]$entry.display_name)
    if ($wikiSources.town_aliases.PSObject.Properties.Name -contains [string]$entry.display_name) {
        foreach ($alias in $wikiSources.town_aliases.([string]$entry.display_name)) {
            $candidateNames.Add([string]$alias)
        }
    }
    $normalizedCandidates = @($candidateNames | ForEach-Object { ConvertTo-NormalizedName $_ } | Sort-Object -Unique)
    $matches = @($fcsTownCandidates |
        Where-Object { $normalizedCandidates -contains (ConvertTo-NormalizedName $_.base_name) } |
        Sort-Object string_id -Unique)
    $townComparisons.Add([ordered]@{
        wiki_title = [string]$entry.wiki_title
        display_name = [string]$entry.display_name
        section = [string]$entry.section
        page_url = [string]$entry.page_url
        status = if ($matches.Count -gt 0) { 'fcs_variant_matched' } else { 'no_direct_fcs_variant_name' }
        fcs_variants = @($matches)
    })
}

$actors = @($actorById.Values | Sort-Object name)
$wikiIndexActors = @($actors | Where-Object { $_.wiki_status -eq 'wiki_index_confirmed' })
$wikiPageActors = @($actors | Where-Object { $_.wiki_status -eq 'wiki_page_confirmed' })
$wikiIdentityActors = @($actors | Where-Object { $_.wiki_status -eq 'wiki_identity_only' })
$wikiConfirmedActors = @($wikiIndexActors + $wikiPageActors)
$fcsOnlyActors = @($actors | Where-Object { $_.wiki_status -eq 'fcs_only' })
$matchedTowns = @($townComparisons | Where-Object { $_.status -eq 'fcs_variant_matched' })
$unmatchedTowns = @($townComparisons | Where-Object { $_.status -ne 'fcs_variant_matched' })

$sourceOfTruth = [ordered]@{
    schema_version = 1
    authority = [ordered]@{
        primary = 'Installed vanilla FCS data'
        enrichment = 'Kenshi Wiki and Kenshi Wiki Fandom snapshots'
        rule = 'Wiki omission never invalidates an FCS query, actor, faction, or town variant.'
    }
    source_hashes = [ordered]@{
        fcs_catalog_sha256 = (Get-FileHash -LiteralPath $CatalogPath -Algorithm SHA256).Hash.ToLowerInvariant()
        wiki_sources_sha256 = (Get-FileHash -LiteralPath $WikiSourcesPath -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    sources = @($wikiSources.sources)
    summary = [ordered]@{
        fcs_queries = [int]$catalog.summary.world_event_state_queries
        fcs_character_targets = $actors.Count
        wiki_confirmed_character_targets = $wikiConfirmedActors.Count
        wiki_index_confirmed_character_targets = $wikiIndexActors.Count
        wiki_page_confirmed_character_targets = $wikiPageActors.Count
        wiki_identity_only_character_targets = $wikiIdentityActors.Count
        fcs_only_character_targets = $fcsOnlyActors.Count
        wiki_only_unresolved_characters = $wikiOnlyActors.Count
        wiki_string_id_conflicts = $wikiConflicts.Count
        fcs_faction_targets = $factionStates.Count
        wiki_town_locations = $townComparisons.Count
        wiki_town_locations_with_fcs_variant_match = $matchedTowns.Count
        wiki_town_locations_without_direct_variant_name = $unmatchedTowns.Count
    }
    character_states = @($actors | ForEach-Object {
        [ordered]@{
            string_id = $_.string_id
            name = $_.name
            validity = $_.validity
            wiki_status = $_.wiki_status
            wiki_references = @($_.wiki_references | Sort-Object source_id, wiki_title)
            query_ids = $_.query_ids
            rule_categories = $_.rule_categories
            consumer_types = $_.consumer_types
            affected_towns = $_.affected_towns
        }
    })
    faction_relation_states = $factionStates
    wiki_only_unresolved_characters = $wikiOnlyActors.ToArray()
    wiki_conflicts = $wikiConflicts.ToArray()
    town_override_comparison = $townComparisons.ToArray()
}

$jsonPath = Join-Path $OutputDirectory 'vanilla_world_state_source_of_truth.json'
$reportPath = Join-Path $OutputDirectory 'vanilla_world_state_wiki_comparison.md'
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
Write-Utf8WithoutBom -Path $jsonPath -Content (($sourceOfTruth | ConvertTo-Json -Depth 14) + "`n")

$lines = New-Object System.Collections.Generic.List[string]
$lines.Add('# Kenshi Vanilla World-State Source of Truth')
$lines.Add('')
$lines.Add('FCS defines validity. Wiki sources provide public names, String ID corroboration, and documentation coverage.')
$lines.Add('')
$lines.Add('## Coverage')
$lines.Add('')
$lines.Add('| Metric | Count |')
$lines.Add('| --- | ---: |')
foreach ($property in $sourceOfTruth.summary.GetEnumerator()) {
    $lines.Add("| $($property.Key) | $($property.Value) |")
}
$lines.Add('')
$lines.Add('## Individual-Page World-State Coverage')
$lines.Add('')
if ($wikiPageActors.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($actor in $wikiPageActors) {
        $pageReference = @($actor.wiki_references | Where-Object { $_.source_id -eq 'kenshi_fandom_character_pages' })[0]
        $lines.Add(('- [{0}]({1}) (`{2}`)' -f $actor.name, $pageReference.page_url, $actor.string_id))
    }
}
$lines.Add('')
$lines.Add('## Wiki Identity Only')
$lines.Add('')
if ($wikiIdentityActors.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($actor in $wikiIdentityActors) {
        $pageReference = @($actor.wiki_references | Where-Object { $_.source_id -eq 'kenshi_fandom_character_pages' })[0]
        $lines.Add(('- [{0}]({1}) (`{2}`)' -f $actor.name, $pageReference.page_url, $actor.string_id))
    }
}
$lines.Add('')
$lines.Add('## FCS-Only Valid Character Targets')
$lines.Add('')
if ($fcsOnlyActors.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($actor in $fcsOnlyActors) {
        $lines.Add(('- {0} (`{1}`)' -f $actor.name, $actor.string_id))
    }
}
$lines.Add('')
$lines.Add('## Wiki-Only Unresolved Characters')
$lines.Add('')
if ($wikiOnlyActors.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($actor in $wikiOnlyActors) {
        $lines.Add("- [$($actor.display_name)]($($actor.page_url))")
    }
}
$lines.Add('')
$lines.Add('## String ID Conflicts')
$lines.Add('')
if ($wikiConflicts.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($conflict in $wikiConflicts) {
        $lines.Add(('- {0}: wiki `{1}`, FCS `{2}`' -f $conflict.wiki_title, $conflict.wiki_string_id, $conflict.matched_fcs_string_id))
    }
}
$lines.Add('')
$lines.Add('## Wiki Towns Without a Direct Variant-Name Match')
$lines.Add('')
if ($unmatchedTowns.Count -eq 0) {
    $lines.Add('- None')
}
else {
    foreach ($town in $unmatchedTowns) {
        $lines.Add("- [$($town.display_name)]($($town.page_url))")
    }
}
$lines.Add('')
$lines.Add('## Interpretation')
$lines.Add('')
$lines.Add('- `wiki_index_confirmed` means a pinned World States index resolved to the same FCS character target.')
$lines.Add('- `wiki_page_confirmed` means the actor is omitted from the index but its individual page has a World States section.')
$lines.Add('- `wiki_identity_only` means the wiki corroborates the actor identity but does not currently document a World States section.')
$lines.Add('- `fcs_only` is still a valid vanilla world-state target; the index pages simply do not list it.')
$lines.Add('- Town matching compares public wiki names with FCS town-variant names. An unmatched name is a documentation/alias gap, not evidence that the town override is invalid.')
$lines.Add('- Campaign, dialogue, squad, and town consumers remain eligibility conditions until Stobe evaluates the referenced query in game.')

Write-Utf8WithoutBom -Path $reportPath -Content (($lines -join "`n") + "`n")

Write-Output "Wiki-confirmed FCS character targets: $($wikiConfirmedActors.Count)"
Write-Output "FCS-only valid character targets: $($fcsOnlyActors.Count)"
Write-Output "Wiki-only unresolved characters: $($wikiOnlyActors.Count)"
Write-Output "Wiki String ID conflicts: $($wikiConflicts.Count)"
Write-Output "Source of truth: $jsonPath"
Write-Output "Comparison: $reportPath"
