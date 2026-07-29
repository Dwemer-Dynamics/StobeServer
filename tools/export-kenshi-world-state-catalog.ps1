[CmdletBinding()]
param(
    [string]$KenshiPath,
    [string]$OutputDirectory,
    [string]$WorldKnowledgeCsv,
    [string]$WorldKnowledgeMapCsv
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = Split-Path -Parent $scriptDirectory
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repositoryRoot 'data\world_state'
}
if ([string]::IsNullOrWhiteSpace($WorldKnowledgeCsv)) {
    $WorldKnowledgeCsv = Join-Path $repositoryRoot 'data\import\world_knowledge_v1.csv'
}
if ([string]::IsNullOrWhiteSpace($WorldKnowledgeMapCsv)) {
    $WorldKnowledgeMapCsv = Join-Path $repositoryRoot 'data\world_state\vanilla_world_state_knowledge_map.csv'
}

# FCS is a 32-bit managed application, so its assembly must be loaded by a 32-bit process.
if ([Environment]::Is64BitProcess) {
    $windowsPowerShellX86 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $windowsPowerShellX86 -PathType Leaf)) {
        throw "32-bit Windows PowerShell was not found at '$windowsPowerShellX86'."
    }

    $forwardedArguments = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $PSCommandPath,
        '-OutputDirectory', $OutputDirectory,
        '-WorldKnowledgeCsv', $WorldKnowledgeCsv,
        '-WorldKnowledgeMapCsv', $WorldKnowledgeMapCsv
    )
    if (-not [string]::IsNullOrWhiteSpace($KenshiPath)) {
        $forwardedArguments += @('-KenshiPath', $KenshiPath)
    }

    & $windowsPowerShellX86 @forwardedArguments
    if ($LASTEXITCODE -ne 0) {
        throw "The 32-bit FCS extraction process failed with exit code $LASTEXITCODE."
    }
    return
}

function Resolve-KenshiInstallPath {
    param([string]$RequestedPath)

    $candidates = New-Object System.Collections.Generic.List[string]
    if (-not [string]::IsNullOrWhiteSpace($RequestedPath)) {
        $candidates.Add($RequestedPath)
    }
    if (-not [string]::IsNullOrWhiteSpace($env:KENSHI_PATH)) {
        $candidates.Add($env:KENSHI_PATH)
    }

    foreach ($drive in @('C', 'D', 'E', 'F', 'G')) {
        $candidates.Add("${drive}:\Program Files (x86)\Steam\steamapps\common\Kenshi")
        $candidates.Add("${drive}:\SteamLibrary\steamapps\common\Kenshi")
    }

    foreach ($candidate in $candidates) {
        if ([string]::IsNullOrWhiteSpace($candidate)) {
            continue
        }
        $expanded = [Environment]::ExpandEnvironmentVariables($candidate)
        $fcsPath = Join-Path $expanded 'forgotten construction set.exe'
        if (Test-Path -LiteralPath $fcsPath -PathType Leaf) {
            return (Resolve-Path -LiteralPath $expanded).Path
        }
    }

    throw 'Kenshi was not found. Pass -KenshiPath or set KENSHI_PATH.'
}

function ConvertTo-NormalizedKnowledgeName {
    param([AllowNull()][string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return ''
    }

    $normalized = $Value.Trim().ToLowerInvariant()
    $normalized = $normalized -replace '[\u2018\u2019]', "'"
    $normalized = $normalized -replace '[^a-z0-9]+', ' '
    return ($normalized -replace '\s+', ' ').Trim()
}

function Add-TopicAlias {
    param(
        [hashtable]$Index,
        [string]$Alias,
        [string]$Topic
    )

    $key = ConvertTo-NormalizedKnowledgeName $Alias
    if ([string]::IsNullOrWhiteSpace($key)) {
        return
    }
    if (-not $Index.ContainsKey($key)) {
        $Index[$key] = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
    }
    $null = $Index[$key].Add($Topic)
}

function Get-ReferenceRows {
    param(
        [object]$Item,
        [string]$ListName,
        [object]$GameData
    )

    $rows = New-Object System.Collections.Generic.List[object]
    foreach ($entry in $Item.referenceData($ListName)) {
        $target = $GameData.getItem([string]$entry.Key)
        $rows.Add([ordered]@{
            target_id = [string]$entry.Key
            target_name = if ($null -eq $target) { $null } else { [string]$target.Name }
            target_type = if ($null -eq $target) { $null } else { $target.type.ToString() }
            value0 = [int]$entry.Value.v0
            value1 = [int]$entry.Value.v1
            value2 = [int]$entry.Value.v2
        })
    }

    return @($rows | Sort-Object target_type, target_name, target_id)
}

function Get-RuleText {
    param(
        [string]$Category,
        [int]$Value,
        [string]$TargetName
    )

    switch ($Category) {
        'NPC is' {
            switch ($Value) {
                0 { return "$TargetName is dead" }
                1 { return "$TargetName is alive" }
                2 { return "$TargetName is imprisoned" }
            }
        }
        'NPC is NOT' {
            switch ($Value) {
                0 { return "$TargetName is not dead" }
                1 { return "$TargetName is not alive" }
                2 { return "$TargetName is not imprisoned" }
            }
        }
        'player ally' {
            if ($Value -eq 1) { return "$TargetName is allied with the player faction" }
            if ($Value -eq 0) { return "$TargetName is not allied with the player faction" }
        }
        'player enemy' {
            if ($Value -eq 1) { return "$TargetName is an enemy of the player faction" }
            if ($Value -eq 0) { return "$TargetName is not an enemy of the player faction" }
        }
        'town okay' {
            if ($Value -eq 1) { return "$TargetName is intact" }
            if ($Value -eq 0) { return "$TargetName is destroyed" }
        }
    }

    throw "Unsupported world-state rule '$Category' value '$Value'."
}

function Get-InverseRuleText {
    param(
        [string]$Category,
        [int]$Value,
        [string]$TargetName
    )

    switch ($Category) {
        'NPC is' {
            switch ($Value) {
                0 { return "$TargetName is not dead" }
                1 { return "$TargetName is not alive" }
                2 { return "$TargetName is not imprisoned" }
            }
        }
        'NPC is NOT' {
            switch ($Value) {
                0 { return "$TargetName is dead" }
                1 { return "$TargetName is alive" }
                2 { return "$TargetName is imprisoned" }
            }
        }
        'player ally' {
            if ($Value -eq 1) { return "$TargetName is not allied with the player faction" }
            if ($Value -eq 0) { return "$TargetName is allied with the player faction" }
        }
        'player enemy' {
            if ($Value -eq 1) { return "$TargetName is not an enemy of the player faction" }
            if ($Value -eq 0) { return "$TargetName is an enemy of the player faction" }
        }
        'town okay' {
            if ($Value -eq 1) { return "$TargetName is destroyed" }
            if ($Value -eq 0) { return "$TargetName is intact" }
        }
    }

    throw "Unsupported world-state rule '$Category' value '$Value'."
}

function Get-ConsumerRole {
    param([string]$OwnerType)

    switch ($OwnerType) {
        'DIALOGUE' { return 'dialogue_condition' }
        'DIALOGUE_LINE' { return 'dialogue_condition' }
        'WORD_SWAPS' { return 'dialogue_condition' }
        'FACTION_CAMPAIGN' { return 'campaign_eligibility' }
        'SQUAD_TEMPLATE' { return 'squad_spawn_condition' }
        'UNIQUE_SQUAD_TEMPLATE' { return 'squad_spawn_condition' }
        'TOWN' { return 'town_variant_condition' }
        default { throw "Unsupported WORLD_EVENT_STATE consumer type '$OwnerType'." }
    }
}

function Get-MatchedTopics {
    param(
        [hashtable]$TopicIndex,
        [string[]]$Names
    )

    $topics = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
    foreach ($name in $Names) {
        $key = ConvertTo-NormalizedKnowledgeName $name
        if (-not [string]::IsNullOrWhiteSpace($key) -and $TopicIndex.ContainsKey($key)) {
            foreach ($topic in $TopicIndex[$key]) {
                $null = $topics.Add($topic)
            }
        }
    }
    return @($topics | Sort-Object)
}

function Write-Utf8WithoutBom {
    param(
        [string]$Path,
        [string]$Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

$resolvedKenshiPath = Resolve-KenshiInstallPath $KenshiPath
$resolvedOutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$resolvedWorldKnowledgeCsv = [System.IO.Path]::GetFullPath($WorldKnowledgeCsv)
$resolvedWorldKnowledgeMapCsv = [System.IO.Path]::GetFullPath($WorldKnowledgeMapCsv)
$fcsExecutable = Join-Path $resolvedKenshiPath 'forgotten construction set.exe'
$dataDirectory = Join-Path $resolvedKenshiPath 'data'
$vanillaFiles = @('gamedata.base', 'Newwworld.mod', 'Dialogue.mod', 'rebirth.mod')

if (-not (Test-Path -LiteralPath $resolvedWorldKnowledgeCsv -PathType Leaf)) {
    throw "World knowledge CSV was not found at '$resolvedWorldKnowledgeCsv'."
}
if (-not (Test-Path -LiteralPath $resolvedWorldKnowledgeMapCsv -PathType Leaf)) {
    throw "World-state knowledge map was not found at '$resolvedWorldKnowledgeMapCsv'."
}
foreach ($fileName in $vanillaFiles) {
    $filePath = Join-Path $dataDirectory $fileName
    if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
        throw "Required vanilla data file was not found at '$filePath'."
    }
}

$topicIndex = @{}
$knownTopics = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
foreach ($record in Import-Csv -LiteralPath $resolvedWorldKnowledgeCsv) {
    $topic = [string]$record.topic
    $null = $knownTopics.Add($topic)
    Add-TopicAlias -Index $topicIndex -Alias $topic -Topic $topic
    foreach ($alias in ([string]$record.aliases -split '\s*[,;|]\s*')) {
        Add-TopicAlias -Index $topicIndex -Alias $alias -Topic $topic
    }
}

$queryTopicOverrides = @{}
foreach ($record in Import-Csv -LiteralPath $resolvedWorldKnowledgeMapCsv) {
    $queryId = ([string]$record.query_id).Trim()
    if ([string]::IsNullOrWhiteSpace($queryId)) {
        throw 'World-state knowledge map contains an empty query_id.'
    }
    if ($queryTopicOverrides.ContainsKey($queryId)) {
        throw "World-state knowledge map contains duplicate query_id '$queryId'."
    }

    $topics = @(([string]$record.world_knowledge_topics -split '\s*\|\s*') |
        Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    if ($topics.Count -eq 0) {
        throw "World-state knowledge map query '$queryId' has no topics."
    }
    foreach ($topic in $topics) {
        if (-not $knownTopics.Contains($topic)) {
            throw "World-state knowledge map query '$queryId' references missing topic '$topic'."
        }
    }
    $queryTopicOverrides[$queryId] = @($topics | Sort-Object -Unique)
}

$previousLocation = Get-Location
try {
    Set-Location -LiteralPath $resolvedKenshiPath
    $null = [System.Reflection.Assembly]::LoadFrom($fcsExecutable)
    $fcsAssembly = [AppDomain]::CurrentDomain.GetAssemblies() |
        Where-Object { $null -ne $_.GetType('forgotten_construction_set.GameData', $false) } |
        Select-Object -First 1
    if ($null -eq $fcsAssembly) {
        throw 'The FCS GameData assembly could not be loaded.'
    }

    $gameDataType = $fcsAssembly.GetType('forgotten_construction_set.GameData')
    $modModeType = $fcsAssembly.GetType('forgotten_construction_set.GameData+ModMode')
    $gameData = [Activator]::CreateInstance($gameDataType)
    $baseMode = [Enum]::Parse($modModeType, 'BASE')

    $loadedFiles = New-Object System.Collections.Generic.List[object]
    foreach ($fileName in $vanillaFiles) {
        $filePath = Join-Path $dataDirectory $fileName
        if (-not $gameData.load($filePath, $baseMode, $false, $false)) {
            throw "FCS failed to load '$filePath'."
        }
        $fileInfo = Get-Item -LiteralPath $filePath
        $loadedFiles.Add([ordered]@{
            file = $fileName
            bytes = [long]$fileInfo.Length
            sha256 = (Get-FileHash -LiteralPath $filePath -Algorithm SHA256).Hash.ToLowerInvariant()
        })
    }
    $gameData.resolveAllReferences()
}
finally {
    Set-Location $previousLocation
}

$queries = @($gameData.items.Values |
    Where-Object { $_.type.ToString() -eq 'WORLD_EVENT_STATE' } |
    Sort-Object stringID)
if ($queries.Count -eq 0) {
    throw 'FCS loaded successfully but no WORLD_EVENT_STATE records were found.'
}

$queryIds = @{}
$consumersByQuery = @{}
foreach ($query in $queries) {
    if ($queryIds.ContainsKey([string]$query.stringID)) {
        throw "Duplicate WORLD_EVENT_STATE string ID '$($query.stringID)'."
    }
    $queryIds[[string]$query.stringID] = $true
    $consumersByQuery[[string]$query.stringID] = New-Object System.Collections.Generic.List[object]
}

$unresolvedReferences = New-Object System.Collections.Generic.List[string]
foreach ($owner in $gameData.items.Values) {
    foreach ($listName in $owner.referenceLists()) {
        foreach ($entry in $owner.referenceData($listName)) {
            $targetId = [string]$entry.Key
            if (-not $queryIds.ContainsKey($targetId)) {
                continue
            }
            $ownerType = $owner.type.ToString()
            $role = Get-ConsumerRole $ownerType
            if ($listName -ne 'world state') {
                throw "Unexpected WORLD_EVENT_STATE reference list '$listName' on '$ownerType'."
            }
            $expected = [int]$entry.Value.v0
            if ($expected -notin @(0, 1)) {
                throw "Unexpected world-state expectation '$expected' on '$($owner.stringID)'."
            }

            $consumersByQuery[$targetId].Add([ordered]@{
                owner_id = [string]$owner.stringID
                owner_name = [string]$owner.Name
                owner_type = $ownerType
                owner_source_mod = [string]$owner.Mod
                list = [string]$listName
                expected_query_result = [bool]($expected -eq 1)
                semantic_role = $role
            })
        }
    }
}

$townVariants = New-Object System.Collections.Generic.List[object]
$townById = @{}
foreach ($town in $gameData.items.Values | Where-Object { $_.type.ToString() -eq 'TOWN' }) {
    $worldStateRequirements = @()
    if (@($town.referenceLists()) -contains 'world state') {
        $worldStateRequirements = @(Get-ReferenceRows -Item $town -ListName 'world state' -GameData $gameData)
    }
    if ($worldStateRequirements.Count -eq 0) {
        continue
    }

    $factions = @()
    $overrides = @()
    $residents = @()
    if (@($town.referenceLists()) -contains 'faction') {
        $factions = @(Get-ReferenceRows -Item $town -ListName 'faction' -GameData $gameData)
    }
    if (@($town.referenceLists()) -contains 'override town') {
        $overrides = @(Get-ReferenceRows -Item $town -ListName 'override town' -GameData $gameData)
    }
    if (@($town.referenceLists()) -contains 'residents') {
        $residents = @(Get-ReferenceRows -Item $town -ListName 'residents' -GameData $gameData)
    }

    $townNames = @([string]$town.Name)
    $baseTownName = ([string]$town.Name -replace '\s*\(override.*$', '').Trim()
    if (-not [string]::IsNullOrWhiteSpace($baseTownName)) {
        $townNames += $baseTownName
    }
    $townTopics = @(Get-MatchedTopics -TopicIndex $topicIndex -Names $townNames)

    $townRecord = [ordered]@{
        town_id = [string]$town.stringID
        town_name = [string]$town.Name
        source_mod = [string]$town.Mod
        world_knowledge_topics = $townTopics
        query_requirements = @($worldStateRequirements | ForEach-Object {
            [ordered]@{
                query_id = $_.target_id
                query_name = $_.target_name
                expected_query_result = [bool]($_.value0 -eq 1)
            }
        })
        factions = $factions
        override_towns = $overrides
        residents = $residents
    }
    $townVariants.Add($townRecord)
    $townById[[string]$town.stringID] = $townRecord
}

$catalogQueries = New-Object System.Collections.Generic.List[object]
$addendumRows = New-Object System.Collections.Generic.List[object]
$allRuleCategories = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::Ordinal)
$consumerTypeCounts = @{}
$consumerRoleCounts = @{}
$classificationCounts = @{}
$mappedQueryCount = 0
$usedQueryTopicOverrides = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
$unmappedEntities = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
$totalRuleCount = 0
$totalConsumerCount = 0

foreach ($query in $queries) {
    $rules = New-Object System.Collections.Generic.List[object]
    $ruleTexts = New-Object System.Collections.Generic.List[string]
    $queryEntityNames = New-Object System.Collections.Generic.List[string]
    $queryEntityMappings = New-Object System.Collections.Generic.List[object]

    foreach ($category in @($query.referenceLists() | Sort-Object)) {
        if ($category -notin @('NPC is', 'NPC is NOT', 'player ally', 'player enemy', 'town okay')) {
            throw "Unsupported WORLD_EVENT_STATE rule category '$category' on '$($query.stringID)'."
        }
        $null = $allRuleCategories.Add([string]$category)
        foreach ($entry in $query.referenceData($category) | Sort-Object Key) {
            $target = $gameData.getItem([string]$entry.Key)
            if ($null -eq $target) {
                $unresolvedReferences.Add("$($query.stringID):${category}:$($entry.Key)")
                continue
            }
            $targetName = [string]$target.Name
            $value = [int]$entry.Value.v0
            $text = Get-RuleText -Category $category -Value $value -TargetName $targetName
            $inverseText = Get-InverseRuleText -Category $category -Value $value -TargetName $targetName
            $matchedTopics = @(Get-MatchedTopics -TopicIndex $topicIndex -Names @($targetName))
            if ($matchedTopics.Count -eq 0) {
                $null = $unmappedEntities.Add("$($target.type):$targetName")
            }
            $queryEntityNames.Add($targetName)
            $queryEntityMappings.Add([ordered]@{
                entity_id = [string]$target.stringID
                entity_name = $targetName
                entity_type = $target.type.ToString()
                world_knowledge_topics = $matchedTopics
            })
            $rules.Add([ordered]@{
                category = [string]$category
                target_id = [string]$target.stringID
                target_name = $targetName
                target_type = $target.type.ToString()
                expected_value = $value
                condition_text = $text
                inverse_text = $inverseText
            })
            $ruleTexts.Add($text)
            $totalRuleCount++
        }
    }

    $playerInvolvement = $false
    if ($query.ContainsKey('player involvement')) {
        $playerInvolvement = [bool]$query['player involvement']
    }
    if ($playerInvolvement) {
        $ruleTexts.Add('the player was involved in at least one of these changes')
    }

    $consumers = @($consumersByQuery[[string]$query.stringID] |
        Sort-Object owner_type, owner_name, owner_id, expected_query_result)
    foreach ($consumer in $consumers) {
        if (-not $consumerTypeCounts.ContainsKey($consumer.owner_type)) {
            $consumerTypeCounts[$consumer.owner_type] = 0
        }
        if (-not $consumerRoleCounts.ContainsKey($consumer.semantic_role)) {
            $consumerRoleCounts[$consumer.semantic_role] = 0
        }
        $consumerTypeCounts[$consumer.owner_type]++
        $consumerRoleCounts[$consumer.semantic_role]++
        $totalConsumerCount++

        if ($consumer.owner_type -eq 'TOWN' -and $townById.ContainsKey($consumer.owner_id)) {
            $townRecord = $townById[$consumer.owner_id]
            $queryEntityNames.Add([string]$townRecord.town_name)
            foreach ($topic in $townRecord.world_knowledge_topics) {
                $queryEntityMappings.Add([ordered]@{
                    entity_id = [string]$townRecord.town_id
                    entity_name = [string]$townRecord.town_name
                    entity_type = 'TOWN'
                    world_knowledge_topics = @($topic)
                })
            }
        }
    }

    $allMatchedTopics = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
    foreach ($mapping in $queryEntityMappings) {
        foreach ($topic in $mapping.world_knowledge_topics) {
            $null = $allMatchedTopics.Add([string]$topic)
        }
    }
    $queryId = [string]$query.stringID
    if ($queryTopicOverrides.ContainsKey($queryId)) {
        foreach ($topic in $queryTopicOverrides[$queryId]) {
            $null = $allMatchedTopics.Add([string]$topic)
        }
        $null = $usedQueryTopicOverrides.Add($queryId)
    }
    $matchedTopicList = @($allMatchedTopics | Sort-Object)
    if ($matchedTopicList.Count -gt 0) {
        $mappedQueryCount++
    }

    $classification = 'durable_world_fact'
    if ($rules.Count -eq 0) {
        $classification = 'ambiguous_empty_query'
    }
    if (-not $classificationCounts.ContainsKey($classification)) {
        $classificationCounts[$classification] = 0
    }
    $classificationCounts[$classification]++
    $whenTrue = $null
    if ($ruleTexts.Count -gt 0) {
        $whenTrue = 'World state: ' + ($ruleTexts -join '; ') + '.'
    }
    $whenFalse = $null
    if ($rules.Count -eq 1 -and -not $playerInvolvement) {
        $whenFalse = 'World state: ' + $rules[0].inverse_text + '.'
    }

    $notes = ''
    if ($query.ContainsKey('notes')) {
        $notes = [string]$query['notes']
    }
    $catalogQueries.Add([ordered]@{
        query_id = [string]$query.stringID
        query_name = [string]$query.Name
        source_mod = [string]$query.Mod
        notes = $notes
        semantics = 'All listed rules are AND conditions.'
        player_involvement_required = $playerInvolvement
        classification = $classification
        rules = $rules.ToArray()
        consumers = $consumers
        world_knowledge = [ordered]@{
            matched_topics = $matchedTopicList
            entities = @($queryEntityMappings |
                Sort-Object entity_type, entity_name, entity_id -Unique)
        }
        prompt_addendum = [ordered]@{
            when_true = $whenTrue
            when_false = $whenFalse
            false_result_limit = if ($null -eq $whenFalse) {
                'A false AND query does not identify which condition failed.'
            } else {
                $null
            }
        }
    })

    $addendumRows.Add([pscustomobject][ordered]@{
        query_id = [string]$query.stringID
        query_name = [string]$query.Name
        source_mod = [string]$query.Mod
        classification = $classification
        when_true = $whenTrue
        when_false = $whenFalse
        world_knowledge_topics = ($matchedTopicList -join ' | ')
        consumer_count = $consumers.Count
    })
}

foreach ($queryId in $queryTopicOverrides.Keys) {
    if (-not $usedQueryTopicOverrides.Contains($queryId)) {
        throw "World-state knowledge map references unknown query '$queryId'."
    }
}

if ($unresolvedReferences.Count -gt 0) {
    throw "Unresolved world-state references were found: $($unresolvedReferences -join ', ')"
}

$fcsVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($fcsExecutable)
$catalog = [ordered]@{
    schema_version = 1
    extractor = [ordered]@{
        name = 'StobeServer Kenshi vanilla world-state catalog exporter'
        script_sha256 = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant()
        world_knowledge_map_sha256 = (Get-FileHash -LiteralPath $resolvedWorldKnowledgeMapCsv -Algorithm SHA256).Hash.ToLowerInvariant()
        process_architecture = 'x86'
        fcs_product_version = [string]$fcsVersion.ProductVersion
        fcs_file_version = [string]$fcsVersion.FileVersion
    }
    source = [ordered]@{
        game = 'Kenshi'
        scope = 'Official vanilla data layers loaded in FCS dependency order'
        loaded_files = $loadedFiles.ToArray()
        fcs_definition_sha256 = (Get-FileHash -LiteralPath (Join-Path $resolvedKenshiPath 'fcs.def') -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    semantics = [ordered]@{
        query_operator = 'AND'
        consumer_expected_query_result = 'Consumers store whether the referenced query must evaluate true or false.'
        false_result_limit = 'For multi-rule queries, false means at least one condition failed; it does not identify which one.'
        source_mod_note = 'source_mod is the record provenance retained by FCS and may be an internal development filename.'
    }
    summary = [ordered]@{
        total_records_loaded = [int]$gameData.items.Count
        world_event_state_queries = $catalogQueries.Count
        world_event_state_rules = $totalRuleCount
        world_event_state_consumers = $totalConsumerCount
        town_variants_with_world_state = $townVariants.Count
        queries_with_world_knowledge_match = $mappedQueryCount
        queries_without_world_knowledge_match = $catalogQueries.Count - $mappedQueryCount
        rule_categories = @($allRuleCategories | Sort-Object)
        classification_counts = [ordered]@{}
        consumer_type_counts = [ordered]@{}
        consumer_role_counts = [ordered]@{}
    }
    queries = $catalogQueries.ToArray()
    town_variants = @($townVariants | Sort-Object town_name, town_id)
}
foreach ($key in $classificationCounts.Keys | Sort-Object) {
    $catalog.summary.classification_counts[$key] = $classificationCounts[$key]
}
foreach ($key in $consumerTypeCounts.Keys | Sort-Object) {
    $catalog.summary.consumer_type_counts[$key] = $consumerTypeCounts[$key]
}
foreach ($key in $consumerRoleCounts.Keys | Sort-Object) {
    $catalog.summary.consumer_role_counts[$key] = $consumerRoleCounts[$key]
}

$coverage = [ordered]@{
    schema_version = 1
    checks = [ordered]@{
        vanilla_layers_loaded = $loadedFiles.Count -eq $vanillaFiles.Count
        nonzero_world_event_state_queries = $catalogQueries.Count -gt 0
        every_query_classified = @($catalogQueries | Where-Object { [string]::IsNullOrWhiteSpace($_.classification) }).Count -eq 0
        all_rule_shapes_supported = $true
        all_consumer_shapes_supported = $true
        all_references_resolved = $true
        all_explicit_topic_mappings_resolved = $usedQueryTopicOverrides.Count -eq $queryTopicOverrides.Count
    }
    counts = $catalog.summary
    unmapped_world_knowledge_entities = @($unmappedEntities | Sort-Object)
}

New-Item -ItemType Directory -Force -Path $resolvedOutputDirectory | Out-Null
$catalogPath = Join-Path $resolvedOutputDirectory 'vanilla_world_state_catalog.json'
$fullCatalogPath = Join-Path $resolvedOutputDirectory 'vanilla_world_state_catalog.full.json'
$coveragePath = Join-Path $resolvedOutputDirectory 'vanilla_world_state_coverage.json'
$addendaPath = Join-Path $resolvedOutputDirectory 'vanilla_world_state_addenda.csv'
$reportPath = Join-Path $resolvedOutputDirectory 'vanilla_world_state_coverage.md'

$runtimeCatalog = [ordered]@{
    schema_version = 1
    queries = @($catalogQueries | ForEach-Object {
        [ordered]@{
            query_id = [string]$_.query_id
            query_name = [string]$_.query_name
            source_mod = [string]$_.source_mod
            classification = [string]$_.classification
            player_involvement_required = [bool]$_.player_involvement_required
            rules = @($_.rules)
            world_knowledge = [ordered]@{
                matched_topics = @($_.world_knowledge.matched_topics)
            }
            prompt_addendum = [ordered]@{
                when_true = $_.prompt_addendum.when_true
                when_false = $_.prompt_addendum.when_false
            }
        }
    })
}
$runtimeCatalogJson = $runtimeCatalog | ConvertTo-Json -Depth 12 -Compress
$fullCatalogJson = $catalog | ConvertTo-Json -Depth 20
$coverageJson = $coverage | ConvertTo-Json -Depth 10
$addendaCsv = ($addendumRows | Sort-Object query_id | ConvertTo-Csv -NoTypeInformation) -join "`r`n"

$reportLines = New-Object System.Collections.Generic.List[string]
$reportLines.Add('# Kenshi Vanilla World-State Coverage')
$reportLines.Add('')
$reportLines.Add('Generated deterministically from the official FCS data model and the four vanilla data layers.')
$reportLines.Add('')
$reportLines.Add('## Extraction')
$reportLines.Add('')
$reportLines.Add("| Metric | Count |")
$reportLines.Add("| --- | ---: |")
$reportLines.Add("| Records loaded | $($catalog.summary.total_records_loaded) |")
$reportLines.Add("| WORLD_EVENT_STATE queries | $($catalog.summary.world_event_state_queries) |")
$reportLines.Add("| Query rules | $($catalog.summary.world_event_state_rules) |")
$reportLines.Add("| Reverse-reference consumers | $($catalog.summary.world_event_state_consumers) |")
$reportLines.Add("| Town variants using world state | $($catalog.summary.town_variants_with_world_state) |")
$reportLines.Add("| Queries mapped to world knowledge | $($catalog.summary.queries_with_world_knowledge_match) |")
$reportLines.Add("| Queries without a world-knowledge match | $($catalog.summary.queries_without_world_knowledge_match) |")
$reportLines.Add('')
$reportLines.Add('## Query Classification')
$reportLines.Add('')
$reportLines.Add("| Classification | Queries |")
$reportLines.Add("| --- | ---: |")
foreach ($key in $catalog.summary.classification_counts.Keys) {
    $reportLines.Add("| $key | $($catalog.summary.classification_counts[$key]) |")
}
$reportLines.Add('')
$reportLines.Add('## Semantics')
$reportLines.Add('')
$reportLines.Add('- `WORLD_EVENT_STATE` rules are combined with AND.')
$reportLines.Add('- Dialogue references are dialogue conditions.')
$reportLines.Add('- Campaign references are eligibility gates, not proof that a campaign occurred.')
$reportLines.Add('- Squad and town references are spawn or variant conditions, not proof that a spawn occurred.')
$reportLines.Add('- A false multi-rule query does not reveal which rule failed, so no false addendum is generated for it.')
$reportLines.Add('- `source_mod` is FCS record provenance and can contain internal development filenames even though only official vanilla layers were loaded.')
$reportLines.Add('')
$reportLines.Add('## Consumer Coverage')
$reportLines.Add('')
$reportLines.Add("| Consumer type | References |")
$reportLines.Add("| --- | ---: |")
foreach ($key in $catalog.summary.consumer_type_counts.Keys) {
    $reportLines.Add("| $key | $($catalog.summary.consumer_type_counts[$key]) |")
}
$reportLines.Add('')
$reportLines.Add('## Validation')
$reportLines.Add('')
foreach ($check in $coverage.checks.GetEnumerator()) {
    $status = if ($check.Value) { 'PASS' } else { 'FAIL' }
    $reportLines.Add("- $status - $($check.Key)")
}
$reportLines.Add('')
$reportLines.Add('## Unmapped World-Knowledge Entities')
$reportLines.Add('')
if ($coverage.unmapped_world_knowledge_entities.Count -eq 0) {
    $reportLines.Add('- None')
}
else {
    foreach ($entity in $coverage.unmapped_world_knowledge_entities) {
        $reportLines.Add("- $entity")
    }
}
$reportLines.Add('')
$reportLines.Add('## Runtime Integration')
$reportLines.Add('')
$reportLines.Add('StobeServer seeds built-in addenda from this catalog and injects current query-result text at prompt time. Stobe remains responsible for safe game-thread query evaluation; the extractor never enables a background `GameData` scan.')

Write-Utf8WithoutBom -Path $catalogPath -Content ($runtimeCatalogJson + "`n")
Write-Utf8WithoutBom -Path $fullCatalogPath -Content ($fullCatalogJson + "`n")
Write-Utf8WithoutBom -Path $coveragePath -Content ($coverageJson + "`n")
Write-Utf8WithoutBom -Path $addendaPath -Content ($addendaCsv + "`r`n")
Write-Utf8WithoutBom -Path $reportPath -Content (($reportLines -join "`n") + "`n")

Write-Output "Loaded $($catalog.summary.total_records_loaded) vanilla FCS records."
Write-Output "Exported $($catalog.summary.world_event_state_queries) world-state queries and $($catalog.summary.world_event_state_consumers) consumers."
Write-Output "Runtime catalog: $catalogPath"
Write-Output "Full analysis catalog: $fullCatalogPath"
Write-Output "Coverage: $coveragePath"
Write-Output "Addenda: $addendaPath"
