[CmdletBinding()]
param(
    [string]$OutputPath,
    [string]$CatalogPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = Split-Path -Parent $scriptDirectory
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repositoryRoot 'data\world_state\wiki_world_state_sources.json'
}
if ([string]::IsNullOrWhiteSpace($CatalogPath)) {
    $CatalogPath = Join-Path $repositoryRoot 'data\world_state\vanilla_world_state_catalog.json'
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$CatalogPath = [System.IO.Path]::GetFullPath($CatalogPath)

function Invoke-FandomApi {
    param([hashtable]$Parameters)

    $pairs = foreach ($entry in $Parameters.GetEnumerator() | Sort-Object Key) {
        [Uri]::EscapeDataString([string]$entry.Key) + '=' + [Uri]::EscapeDataString([string]$entry.Value)
    }
    $uri = 'https://kenshi.fandom.com/api.php?' + ($pairs -join '&')
    return Invoke-RestMethod -Uri $uri -TimeoutSec 30 -Headers @{
        'User-Agent' = 'StobeServer world-state source builder/1.0'
    }
}

function Get-FandomPage {
    param([string]$Title)

    $response = Invoke-FandomApi @{
        action = 'query'
        prop = 'revisions'
        rvprop = 'ids|timestamp|content'
        rvslots = 'main'
        redirects = '1'
        titles = $Title
        format = 'json'
        formatversion = '2'
    }
    $page = $response.query.pages[0]
    if ($null -eq $page -or $page.PSObject.Properties.Name -contains 'missing') {
        throw "Kenshi Wiki page '$Title' was not found."
    }
    $revision = $page.revisions[0]
    return [ordered]@{
        title = [string]$page.title
        page_id = [int]$page.pageid
        revision_id = [int]$revision.revid
        revision_timestamp = [string]$revision.timestamp
        wikitext = [string]$revision.slots.main.content
    }
}

function Get-WikiLinks {
    param([string]$Text)

    $links = New-Object System.Collections.Generic.List[object]
    foreach ($match in [regex]::Matches($Text, '\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]')) {
        $title = $match.Groups[1].Value.Trim()
        $display = $match.Groups[2].Value.Trim()
        if ([string]::IsNullOrWhiteSpace($display)) {
            $display = $title
        }
        $links.Add([ordered]@{
            title = $title
            display = $display
        })
    }
    return $links.ToArray()
}

function Get-FandomActorIndex {
    param([string]$Wikitext)

    $actors = New-Object System.Collections.Generic.List[object]
    $section = ''
    foreach ($rawLine in $Wikitext -split "`r?`n") {
        $line = $rawLine.Trim()
        if ($line -match '^==\s*\[\[The Holy Nation\]\]') {
            $section = 'The Holy Nation'
            continue
        }
        if ($line -match '^===\s*\[\[United Cities\]\]') {
            $section = 'United Cities'
            continue
        }
        if ($line -match '^===\s*\[\[Traders Guild\]\]') {
            $section = 'Traders Guild'
            continue
        }
        if ($line -match '^==Other World States') {
            $section = 'Other'
            continue
        }
        if ($line -match '^==[^=]') {
            if ($line -notmatch 'World States') {
                $section = ''
            }
            continue
        }
        if ([string]::IsNullOrWhiteSpace($section) -or $line -notmatch '^\*') {
            continue
        }

        $actorText = $line
        if ($section -eq 'Other' -and $line -match '\s+of\s+(?:the\s+)?\[\[') {
            $actorText = $line.Substring(0, $line.IndexOf($matches[0]))
        }
        foreach ($link in Get-WikiLinks $actorText) {
            $actors.Add([ordered]@{
                section = $section
                wiki_title = [string]$link.title
                display_name = [string]$link.display
            })
        }
    }
    return $actors.ToArray()
}

function Get-FandomTownIndex {
    param([string]$Wikitext)

    $towns = New-Object System.Collections.Generic.List[object]
    $section = ''
    foreach ($rawLine in $Wikitext -split "`r?`n") {
        $line = $rawLine.Trim()
        if ($line -match '^={2,3}\s*([^=\[]+)') {
            $section = $matches[1].Trim()
            continue
        }
        if ($line -notmatch '^\*') {
            continue
        }
        $links = @(Get-WikiLinks $line)
        if ($links.Count -eq 0) {
            continue
        }
        $towns.Add([ordered]@{
            section = $section
            wiki_title = [string]$links[0].title
            display_name = [string]$links[0].display
        })
    }
    return $towns.ToArray()
}

function Get-CharacterMetadata {
    param([string]$WikiTitle)

    $page = Get-FandomPage $WikiTitle
    $stringId = $null
    $fcsName = $null
    $stringIdMatch = [regex]::Match($page.wikitext, '(?im)^\s*\|\s*string_id\s*=\s*([^|}\r\n]+)')
    if ($stringIdMatch.Success) {
        $stringId = $stringIdMatch.Groups[1].Value.Trim()
    }
    $fcsNameMatch = [regex]::Match($page.wikitext, '(?im)^\s*\|\s*fcs_name\s*=\s*([^|}\r\n]+)')
    if ($fcsNameMatch.Success) {
        $fcsName = $fcsNameMatch.Groups[1].Value.Trim()
    }

    return [ordered]@{
        resolved_title = [string]$page.title
        page_id = [int]$page.page_id
        page_revision_id = [int]$page.revision_id
        page_revision_timestamp = [string]$page.revision_timestamp
        fcs_name = $fcsName
        string_id = $stringId
        has_world_states_section = [bool]($page.wikitext -match '(?im)^==+\s*(?:\[\[)?World States')
    }
}

function Write-Utf8WithoutBom {
    param(
        [string]$Path,
        [string]$Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

$worldStatesPage = Get-FandomPage 'World States'
$townOverridesPage = Get-FandomPage 'Town Overrides'
$fandomActors = @(Get-FandomActorIndex $worldStatesPage.wikitext)
$fandomTowns = @(Get-FandomTownIndex $townOverridesPage.wikitext)

$enrichedFandomActors = New-Object System.Collections.Generic.List[object]
foreach ($actor in $fandomActors) {
    $metadata = Get-CharacterMetadata $actor.wiki_title
    $enrichedFandomActors.Add([ordered]@{
        source_id = 'kenshi_fandom_world_states'
        section = [string]$actor.section
        wiki_title = [string]$actor.wiki_title
        display_name = [string]$actor.display_name
        resolved_title = [string]$metadata.resolved_title
        fcs_name = $metadata.fcs_name
        string_id = $metadata.string_id
        page_id = [int]$metadata.page_id
        page_revision_id = [int]$metadata.page_revision_id
        page_revision_timestamp = [string]$metadata.page_revision_timestamp
        has_world_states_section = [bool]$metadata.has_world_states_section
        page_url = 'https://kenshi.fandom.com/wiki/' + ([Uri]::EscapeDataString($metadata.resolved_title) -replace '%20', '_')
    })
}

$catalog = Get-Content -LiteralPath $CatalogPath -Raw | ConvertFrom-Json
$indexedStringIds = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
$indexedNames = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
foreach ($actor in $enrichedFandomActors) {
    if (-not [string]::IsNullOrWhiteSpace([string]$actor.string_id)) {
        $null = $indexedStringIds.Add([string]$actor.string_id)
    }
    foreach ($name in @([string]$actor.fcs_name, [string]$actor.display_name, [string]$actor.resolved_title)) {
        if (-not [string]::IsNullOrWhiteSpace($name)) {
            $null = $indexedNames.Add($name)
        }
    }
}

$supplementalActors = New-Object System.Collections.Generic.List[object]
$fcsActors = @($catalog.queries.rules |
    Where-Object { $_.target_type -eq 'CHARACTER' } |
    Group-Object target_id |
    ForEach-Object {
        [ordered]@{
            string_id = [string]$_.Name
            name = [string]$_.Group[0].target_name
        }
    })
foreach ($actor in $fcsActors) {
    if ($indexedStringIds.Contains([string]$actor.string_id) -or $indexedNames.Contains([string]$actor.name)) {
        continue
    }
    try {
        $metadata = Get-CharacterMetadata $actor.name
    }
    catch {
        continue
    }
    $supplementalActors.Add([ordered]@{
        source_id = 'kenshi_fandom_character_pages'
        section = 'Supplemental FCS cross-check'
        wiki_title = [string]$actor.name
        display_name = [string]$actor.name
        resolved_title = [string]$metadata.resolved_title
        fcs_name = $metadata.fcs_name
        string_id = $metadata.string_id
        page_id = [int]$metadata.page_id
        page_revision_id = [int]$metadata.page_revision_id
        page_revision_timestamp = [string]$metadata.page_revision_timestamp
        has_world_states_section = [bool]$metadata.has_world_states_section
        page_url = 'https://kenshi.fandom.com/wiki/' + ([Uri]::EscapeDataString($metadata.resolved_title) -replace '%20', '_')
    })
}

$canonicalActors = @(
    @('Shek Kingdom', 'Bayan', 'Bayan'),
    @('Shek Kingdom', 'Esata The Stone Golem', 'Esata The Stone Golem'),
    @('Shek Kingdom', 'Flying Bull', 'Flying Bull'),
    @('Shek Kingdom', 'Mukai the Mountain', 'Mukai the Mountain'),
    @('Shek Kingdom', 'Seto', 'Seto'),
    @('The Holy Nation', 'Holy Lord Phoenix', 'Holy Lord Phoenix'),
    @('The Holy Nation', 'High Inquisitor Valtena', 'High Inquisitor Valtena'),
    @('The Holy Nation', 'High Inquisitor Seta', 'High Inquisitor Seta'),
    @('United Cities', 'Emperor Tengu', 'Emperor Tengu'),
    @('United Cities', 'Lady Sanda', 'Lady Sanda'),
    @('United Cities', 'Lady Tsugi', 'Lady Tsugi'),
    @('United Cities', 'Lady Merin', 'Lady Merin'),
    @('United Cities', 'Lord Inaba', 'Lord Inaba'),
    @('United Cities', 'Lord Ohta', 'Lord Ohta'),
    @('United Cities', 'Lord Yoshinaga', 'Lord Yoshinaga'),
    @('United Cities', 'Lord Shiro', 'Lord Shiro'),
    @('United Cities', 'Lord Nagata', 'Lord Nagata'),
    @('Traders Guild', 'Longen', 'Longen'),
    @('Traders Guild', 'Lady Kana', 'Lady Kana'),
    @('Traders Guild', 'Slave Mistress Grace', 'Slave Mistress Grace'),
    @('Traders Guild', 'Slave Market Master', 'Slave Market Master'),
    @('Traders Guild', 'Slave Mistress Ren', 'Slave Mistress Ren'),
    @('Traders Guild', 'Slave Master Grande', 'Slave Master Grande'),
    @('Traders Guild', 'Slave Master Haga', 'Slave Master Haga'),
    @('Traders Guild', 'Slave Master Ruben', 'Slave Master Ruben'),
    @('Traders Guild', 'Slave Master Wada', 'Slave Master Wada'),
    @('Other', 'Tinfist', 'Tinfist'),
    @('Other', 'Tora the Fearless', 'Tora the Fearless'),
    @('Other', 'Ghost', 'Ghost'),
    @('Other', 'Crab Queen', 'Crab Queen'),
    @('Other', 'Moll', 'Moll'),
    @('Other', 'Gorrillo', 'Gorrillo'),
    @('Other', 'Big Gray', 'Big Gray'),
    @('Other', 'Big Grim', 'Big Grim'),
    @('Other', 'Valamon', 'Valamon'),
    @('Other', 'Boss Simion', 'Boss Simion'),
    @('Other', 'Red Sabre Boss', 'Red Sabre Boss'),
    @('Other', 'Elder', 'Elder'),
    @('Other', 'Savant', 'Savant'),
    @('Other', 'Queen of the South', 'Queen of the South'),
    @('Other', 'Big Al', 'Big Al'),
    @('Other', 'Shade', 'Shade'),
    @('Other', 'The Queen', 'The Queen'),
    @('Other', 'Yabuta Chief', 'Yabuta Chief')
) | ForEach-Object {
    [ordered]@{
        source_id = 'kenshi_wiki_world_states'
        section = $_[0]
        wiki_title = $_[1]
        display_name = $_[2]
        page_url = 'https://kenshi.wiki/w/' + ([Uri]::EscapeDataString($_[1]) -replace '%20', '_')
    }
}

$snapshot = [ordered]@{
    schema_version = 1
    authority_policy = [ordered]@{
        valid_state_definition = 'The installed vanilla FCS catalog is authoritative.'
        wiki_role = 'Wiki sources confirm public names and document notable actors or locations; omission does not invalidate an FCS record.'
        conflict_policy = 'Retain the FCS record and report the wiki disagreement for manual review.'
    }
    sources = @(
        [ordered]@{
            id = 'kenshi_wiki_world_states'
            title = 'World States'
            url = 'https://kenshi.wiki/w/World_States'
            permanent_url = 'https://kenshi.wiki/index.php?title=World_States&oldid=26630'
            revision_id = 26630
            revision_timestamp = '2025-05-27T00:10:00Z'
            license = 'CC BY-SA'
            retrieval = 'Pinned public-page snapshot; the current site API does not expose this historical content.'
        },
        [ordered]@{
            id = 'kenshi_fandom_world_states'
            title = 'World States'
            url = 'https://kenshi.fandom.com/wiki/World_States'
            page_id = [int]$worldStatesPage.page_id
            revision_id = [int]$worldStatesPage.revision_id
            revision_timestamp = [string]$worldStatesPage.revision_timestamp
            license = 'CC BY-SA'
            retrieval = 'MediaWiki API'
        },
        [ordered]@{
            id = 'kenshi_fandom_town_overrides'
            title = 'Town Overrides'
            url = 'https://kenshi.fandom.com/wiki/Town_Overrides'
            page_id = [int]$townOverridesPage.page_id
            revision_id = [int]$townOverridesPage.revision_id
            revision_timestamp = [string]$townOverridesPage.revision_timestamp
            license = 'CC BY-SA'
            retrieval = 'MediaWiki API'
        },
        [ordered]@{
            id = 'kenshi_fandom_character_pages'
            title = 'Individual character pages'
            url = 'https://kenshi.fandom.com/wiki/Category:Characters'
            revision_id = 0
            revision_timestamp = $null
            license = 'CC BY-SA'
            retrieval = 'MediaWiki API; each actor entry carries its own page revision.'
        }
    )
    actor_aliases = [ordered]@{
        'Yabuta Chief' = '63371-Dialogue.mod'
    }
    town_aliases = [ordered]@{
        'Free Settlement' = @('The Free City')
        'Slave Markets' = @('Slave Market')
        'Slave Farm South' = @('Slave Farm S')
        'Hive Village' = @('Hive Village N')
    }
    actor_entries = @($canonicalActors) + $enrichedFandomActors.ToArray() + $supplementalActors.ToArray()
    town_entries = @($fandomTowns | ForEach-Object {
        [ordered]@{
            source_id = 'kenshi_fandom_town_overrides'
            section = [string]$_.section
            wiki_title = [string]$_.wiki_title
            display_name = [string]$_.display_name
            page_url = 'https://kenshi.fandom.com/wiki/' + ([Uri]::EscapeDataString($_.wiki_title) -replace '%20', '_')
        }
    })
}

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $OutputPath) | Out-Null
Write-Utf8WithoutBom -Path $OutputPath -Content (($snapshot | ConvertTo-Json -Depth 10) + "`n")

Write-Output "Wiki actor entries: $($snapshot.actor_entries.Count)"
Write-Output "Wiki town entries: $($snapshot.town_entries.Count)"
Write-Output "Snapshot: $OutputPath"
