<?php

/**
 * Chat helper functions for StobeServer.
 * Builds context, manages conversation state, formats responses.
 */

function loadPromptTemplate(string $templateName): string {
    $normalized = strtolower(trim($templateName));
    if ($normalized === 'prompt_bored.txt' || $normalized === 'prompt_bored') {
        $defaultBoredPrompt = "<bored_prompt_template>\n"
            . "  <task>Generate a brief bored-event conversation between NPCs in Kenshi.</task>\n"
            . "  <npcs>#NPC_LIST#</npcs>\n"
            . "  <location>#LOCATION#</location>\n"
            . "  <world_events>#WORLD_EVENTS#</world_events>\n"
            . "  <requirements>\n"
            . "    <rule>Create a short natural exchange (2-4 lines total).</rule>\n"
            . "    <rule>Possible themes: gossip, faction tensions, survival concerns, world events, trade/work complaints.</rule>\n"
            . "    <rule>Keep it brief and natural like an overheard snippet.</rule>\n"
            . "  </requirements>\n"
            . "</bored_prompt_template>";
        if (function_exists('stobeGetPromptTemplateValue')) {
            return stobeGetPromptTemplateValue('bored_event_template', $defaultBoredPrompt);
        }
        return $defaultBoredPrompt;
    }

    return "<roleplay_instructions>\n"
        . "#ROLEPLAY_INSTRUCTIONS#\n"
        . "</roleplay_instructions>\n\n"
        . "<character>\n"
        . "Roleplay as #NPC_NAME#.\n"
        . "#NPC_NAME# is a #NPC_RACE# aligned with #NPC_FACTION# in the world of Kenshi.\n"
        . "#NPC_CHARACTER_STATE#\n"
        . "<basic_summary>\n"
        . "#NPC_BACKSTORY#\n"
        . "</basic_summary>\n"
        . "<personality>\n"
        . "#NPC_PERSONALITY#\n"
        . "</personality>\n"
        . "<appearance>\n"
        . "#NPC_APPEARANCE#\n"
        . "</appearance>\n"
        . "<relationships>\n"
        . "#NPC_RELATIONSHIPS#\n"
        . "</relationships>\n"
        . "<occupation>\n"
        . "#NPC_OCCUPATION#\n"
        . "</occupation>\n"
        . "#NPC_BOUNTY_BLOCK#\n"
        . "<skills>\n"
        . "#NPC_SKILLS#\n"
        . "</skills>\n"
        . "<speech_style>\n"
        . "#NPC_SPEECHSTYLE#\n"
        . "</speech_style>\n"
        . "<goals>\n"
        . "#NPC_GOALS#\n"
        . "</goals>\n"
        . "#NPC_MIDDLE_TERM_MEMORY#\n"
        . "</character>\n\n"
        . "<conversation_target><name>#PLAYER_NAME#</name></conversation_target>\n\n"
        . "<general_instructions>\n"
        . "#GENERAL_INSTRUCTIONS#\n"
        . "</general_instructions>";
}

function parseActionAllowlistSetting(string $rawAllowlist): array {
    $trimmed = trim($rawAllowlist);
    if ($trimmed === '') {
        return [];
    }
    $tokens = preg_split('/[\s,|]+/', $trimmed) ?: [];
    $normalized = [];
    foreach ($tokens as $token) {
        $value = strtoupper(trim(strval($token)));
        if ($value === '' || $value === '*' || $value === 'ALL') {
            continue;
        }
        if (!in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }
    return $normalized;
}

function stobeActionCatalogAvailable(): bool {
    static $cached = null;
    if ($cached !== null) {
        return boolval($cached);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $cached = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.combined_core_action') AS rel");
    $cached = is_array($row) && !empty(trim(strval($row['rel'] ?? '')));
    return boolval($cached);
}

function loadCoreActionRows(bool $onlyActivated = true): array {
    if (!stobeActionCatalogAvailable()) {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $sql = "SELECT
                command,
                action_name,
                description,
                is_activated
            FROM combined_core_action";
    if ($onlyActivated) {
        $sql .= " WHERE is_activated = TRUE";
    }
    $sql .= " ORDER BY LOWER(action_name) ASC, LOWER(command) ASC";

    $rows = $db->fetchAll($sql);
    $hasTravelLocation = false;
    $hasUseObject = false;
    $hasUseDrugs = false;
    $hasDrinkItem = false;
    $hasKill = false;
    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        if ($command === 'TRAVEL_LOCATION') {
            $hasTravelLocation = true;
        } elseif ($command === 'USE_OBJECT') {
            $hasUseObject = true;
        } elseif ($command === 'USE_DRUGS') {
            $hasUseDrugs = true;
        } elseif ($command === 'DRINK_ITEM') {
            $hasDrinkItem = true;
        } elseif ($command === 'KILL') {
            $hasKill = true;
        }
    }

    $appendFallbackAction = static function (
        string $command,
        string $actionName,
        string $description
    ) use (&$rows, $db, $onlyActivated): void {
        $shouldAppendFallback = true;
        try {
            $commandSql = preg_replace('/[^A-Z_]/', '', strtoupper($command));
            if (!is_string($commandSql) || trim($commandSql) === '') {
                $commandSql = strtoupper($command);
            }
            $existingRow = $db->fetchOne(
                "SELECT is_activated
                 FROM combined_core_action
                 WHERE UPPER(command) = '" . $commandSql . "'
                 LIMIT 1"
            );
            if (is_array($existingRow)) {
                if ($onlyActivated) {
                    $rawIsActivated = strtolower(trim(strval($existingRow['is_activated'] ?? '')));
                    $isActivated = in_array($rawIsActivated, ['1', 't', 'true', 'yes', 'on'], true);
                    $shouldAppendFallback = $isActivated;
                } else {
                    $shouldAppendFallback = true;
                }
            }
        } catch (Throwable $exception) {
            $shouldAppendFallback = true;
        }

        if ($shouldAppendFallback) {
            $rows[] = [
                'command' => $command,
                'action_name' => $actionName,
                'description' => $description,
                'is_activated' => true,
            ];
        }
    };

    if (!$hasTravelLocation) {
        $appendFallbackAction(
            'TRAVEL_LOCATION',
            'TravelLocation',
            'Travel to a previously visited location by name.'
        );
    }
    if (!$hasUseObject) {
        $appendFallbackAction(
            'USE_OBJECT',
            'UseObject',
            'Use a nearby point of interest that has a free usable slot.'
        );
    }
    if (!$hasUseDrugs) {
        $appendFallbackAction(
            'USE_DRUGS',
            'UseDrugs',
            'Consume Hashish from inventory/equipment.'
        );
    }
    if (!$hasDrinkItem) {
        $appendFallbackAction(
            'DRINK',
            'Drink',
            'Consume Bloodrum, Cactus Rum, Grog, or Sake from inventory/equipment.'
        );
    }
    if (!$hasKill) {
        $appendFallbackAction(
            'KILL',
            'Kill',
            'Kill a helpless target immediately.'
        );
    }

    return $rows;
}

function buildActionGuidanceFromRows(array $rows, array $npcData = []): string {
    if (count($rows) === 0) {
        return '';
    }

    $lines = [];
    $lines[] = '<available_actions_list>';
    $lines[] = '#Available Actions';
    $lines[] = 'Use if your character needs to perform an action:';
    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        $actionName = trim(strval($row['action_name'] ?? ''));
        if ($command === 'STOP_CARRYING') {
            $actionName = 'StopCarrying';
        }
        if ($actionName === '') {
            continue;
        }
        $description = trim(strval($row['description'] ?? ''));
        if ($command === 'STOP_CARRYING') {
            $description = 'Put down what you are currently carrying.';
        }
        if ($command === 'FACTION_RELATIONS') {
            $inlineRules = stobeBuildFactionRelationsActionInlineGuidance($npcData);
            if ($inlineRules !== '') {
                if ($description === '') {
                    $description = $inlineRules;
                } else {
                    $description .= ' ' . $inlineRules;
                }
            }
        }
        if ($description !== '') {
            $lines[] = "AVAILABLE ACTION: {$actionName} ({$description})";
        } else {
            $lines[] = "AVAILABLE ACTION: {$actionName}";
        }
    }
    $lines[] = '</available_actions_list>';

    return implode("\n", $lines);
}

function getActionRuntimeConfig(string $eventType): array {
    $normalizedEventType = strtolower(trim($eventType));
    $actionsEnabled = getSettingBool('ACTIONS_ENABLED', true);
    if ($normalizedEventType === 'bored') {
        $actionsEnabled = $actionsEnabled && getSettingBool('BORED_ALLOW_ACTIONS', false);
    } elseif ($normalizedEventType === 'rechat') {
        $actionsEnabled = $actionsEnabled && getSettingBool('RECHAT_ALLOW_ACTIONS', true);
    }

    $maxActions = getSettingInt('MAX_ACTIONS_PER_RESPONSE', 1);
    if ($maxActions < 1) {
        $maxActions = 1;
    } elseif ($maxActions > 3) {
        $maxActions = 3;
    }

    $allowlist = parseActionAllowlistSetting(getSetting('ACTIONS_ALLOWLIST', ''));
    $activeActionRows = loadCoreActionRows(true);
    $activeCommands = [];
    foreach ($activeActionRows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        if ($command === '') {
            continue;
        }
        if (!in_array($command, $activeCommands, true)) {
            $activeCommands[] = $command;
        }
    }
    if (count($activeCommands) > 0) {
        if (count($allowlist) === 0) {
            $allowlist = $activeCommands;
        } else {
            $allowlist = array_values(array_intersect($allowlist, $activeCommands));
        }
    }
    $minFaction = intval(getSettingInt('MIN_FACTION_RELATION', -100));
    $maxFaction = intval(getSettingInt('MAX_FACTION_RELATION', 100));
    if ($minFaction < -100) {
        $minFaction = -100;
    }
    if ($maxFaction > 100) {
        $maxFaction = 100;
    }
    if ($minFaction > $maxFaction) {
        $swap = $minFaction;
        $minFaction = $maxFaction;
        $maxFaction = $swap;
    }

    return [
        'enabled' => $actionsEnabled,
        'max_actions' => $maxActions,
        'allowlist' => $allowlist,
        'active_rows' => $activeActionRows,
        'min_faction_relation' => $minFaction,
        'max_faction_relation' => $maxFaction,
        'disallow_follow_for_player_faction' => false,
        'disallow_give_cats' => false,
        'disallow_take_cats' => false,
        'allow_travel_location' => true,
    ];
}

function stobeBuildActionConfigForNpc(string $eventType, array|false $npcData = false): array {
    $config = getActionRuntimeConfig($eventType);
    $config['disallow_stop_carrying'] = false;
    $config['disallow_remove_limb'] = true;
    $config['disallow_use_drugs'] = true;
    $config['disallow_drink_item'] = true;
    // AI-generated money transfers are currently too error-prone in trade dialog.
    // Keep cats transfer as manual-action only from the chatbox.
    $config['disallow_give_cats'] = true;
    $config['disallow_take_cats'] = true;
    $config['allow_travel_location'] = true;
    if (is_array($npcData) && count($npcData) > 0 && npcIsInPlayerFaction($npcData)) {
        $config['disallow_follow_for_player_faction'] = true;
    }
    if (is_array($npcData) && count($npcData) > 0 && !stobeNpcIsCarryingTarget($npcData)) {
        $config['disallow_stop_carrying'] = true;
    }
    if (is_array($npcData) && count($npcData) > 0 && stobeNpcHasHacksaw($npcData)) {
        $config['disallow_remove_limb'] = false;
    }
    if (is_array($npcData) && count($npcData) > 0 && !stobeNpcIsSkeletonRace($npcData)) {
        if (stobeNpcHasHashish($npcData)) {
            $config['disallow_use_drugs'] = false;
        }
        if (stobeNpcHasDrinkItem($npcData)) {
            $config['disallow_drink_item'] = false;
        }
    }
    return $config;
}

function stobeFilterPartyActionGuidanceByMembership(string $guidance, ?bool $inPlayerFaction): string {
    if (!is_string($guidance) || trim($guidance) === '') {
        return $guidance;
    }
    if ($inPlayerFaction === null) {
        return $guidance;
    }

    if ($inPlayerFaction) {
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*JoinParty\b.*\R?/mi', '', $guidance) ?? $guidance;
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*Follow\b.*\R?/mi', '', $guidance) ?? $guidance;
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*StopFollow\b.*\R?/mi', '', $guidance) ?? $guidance;
    } else {
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*Leave\b.*\R?/mi', '', $guidance) ?? $guidance;
    }

    $stateHint = $inPlayerFaction
        ? 'Action State: This NPC is already in the player faction/squad. Use Leave (not JoinParty). Follow/StopFollow are unavailable. TravelLocation is allowed.'
        : 'Action State: This NPC is not in the player faction/squad. Use JoinParty (not Leave). TravelLocation is allowed.';

    $anchor = "Use if your character needs to perform an action:";
    if (strpos($guidance, $anchor) !== false) {
        $guidance = str_replace($anchor, $anchor . "\n" . $stateHint, $guidance);
    } else {
        $guidance .= "\n" . $stateHint;
    }

    return trim($guidance);
}

function appendActionGuidanceToPrompt(string $prompt, string $eventType, array $npcData = []): string {
    $config = stobeBuildActionConfigForNpc($eventType, $npcData);
    if (!boolval($config['enabled'] ?? false)) {
        return $prompt;
    }
    $rows = [];
    $allowed = $config['allowlist'] ?? [];
    $activeRows = $config['active_rows'] ?? [];
    if (is_array($activeRows) && count($activeRows) > 0) {
        foreach ($activeRows as $row) {
            $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            if (count($allowed) > 0 && !in_array($command, $allowed, true)) {
                continue;
            }
            if ($command === 'GIVE_CATS' && boolval($config['disallow_give_cats'] ?? false)) {
                continue;
            }
            if ($command === 'TAKE_CATS' && boolval($config['disallow_take_cats'] ?? false)) {
                continue;
            }
            if ($command === 'STOP_CARRYING' && boolval($config['disallow_stop_carrying'] ?? false)) {
                continue;
            }
            if ($command === 'REMOVE_LIMB' && boolval($config['disallow_remove_limb'] ?? false)) {
                continue;
            }
            if ($command === 'USE_DRUGS' && boolval($config['disallow_use_drugs'] ?? false)) {
                continue;
            }
            if ($command === 'DRINK_ITEM' && boolval($config['disallow_drink_item'] ?? false)) {
                continue;
            }
            if ($command === 'TRAVEL_LOCATION' && !boolval($config['allow_travel_location'] ?? false)) {
                continue;
            }
            $rows[] = $row;
        }
    }

    $guidance = buildActionGuidanceFromRows($rows, $npcData);
    if ($guidance === '') {
        return $prompt;
    }
    $inPlayerFaction = null;
    if (is_array($npcData) && count($npcData) > 0) {
        $inPlayerFaction = npcIsInPlayerFaction($npcData);
    }
    $guidance = stobeFilterPartyActionGuidanceByMembership($guidance, $inPlayerFaction);
    return implode("\n\n", [$prompt, $guidance]);
}

function stobeBuildFactionRelationsActionInlineGuidance(array $npcData): string {
    if (count($npcData) === 0) {
        return '';
    }
    $speakerFactionIdentity = getNpcFactionIdentityFromProfile($npcData);
    $speakerFactionName = trim(strval($speakerFactionIdentity['name'] ?? ''));
    if ($speakerFactionName === '') {
        $speakerFactionName = 'Unknown';
    }

    $speakerName = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    $speakerKey = strtolower($speakerName);

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $nearby = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($nearby) === 0) {
        $nearby = stobeExtractSceneArray($extendedData, 'nearby');
    }

    $targets = [];
    $seen = [];
    foreach ($nearby as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if ($nameKey === $speakerKey || isset($seen[$nameKey])) {
            continue;
        }
        if (!nearbyEntryIsInPlayerFaction($entry)) {
            continue;
        }
        $seen[$nameKey] = true;
        $targets[] = $name;
        if (count($targets) >= 20) {
            break;
        }
    }

    $targetsText = count($targets) > 0 ? implode(', ', $targets) : '(none detected right now)';
    $text = 'FactionRelations rules: use item value -100 or 100 only. '
        . 'Changes relation between your faction and the faction of the named nearby player-faction person. '
        . 'Your faction: ' . $speakerFactionName . '. '
        . 'Nearby player-faction targets: ' . $targetsText . '.';
    return truncatePromptValue($text, 520);
}

function stobeCanonicalizeActionCommand(string $command): string {
    $upper = strtoupper(trim($command));
    if ($upper === '') {
        return '';
    }
    if (in_array($upper, ['TRAVELLOCATION', 'TRAVEL-LOCATION'], true)) {
        return 'TRAVEL_LOCATION';
    }
    if (in_array($upper, ['STOPCARRYING'], true)) {
        return 'STOP_CARRYING';
    }
    if (in_array($upper, ['REMOVELIMB'], true)) {
        return 'REMOVE_LIMB';
    }
    if (in_array($upper, ['KILLTARGET', 'EXECUTE', 'MURDER'], true)) {
        return 'KILL';
    }
    if (in_array($upper, ['USEOBJECT', 'USE-OBJECT'], true)) {
        return 'USE_OBJECT';
    }
    if (in_array($upper, ['USEDRUGS', 'USE-DRUGS'], true)) {
        return 'USE_DRUGS';
    }
    if (in_array($upper, ['DRINK', 'DRINKITEM', 'DRINK_ITEM', 'DRINK-ITEM'], true)) {
        return 'DRINK_ITEM';
    }
    if (in_array($upper, ['ROLEPLAYACTION', 'ROLEPLAY-ACTION', 'NOTIFY'], true)) {
        return 'ROLEPLAY_ACTION';
    }
    return $upper;
}

function stobeParseFlexibleBool(mixed $value): ?bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return intval($value) !== 0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }
    }
    return null;
}

function stobeResolveNpcCarryState(array $npcData): array {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $carryFlag = stobeParseFlexibleBool($npcData['is_carrying'] ?? null);
    if (!is_bool($carryFlag)) {
        $carryFlag = stobeParseFlexibleBool($metadata['is_carrying'] ?? null);
    }

    $carryingTargetName = trim(strval($npcData['carrying_target_name'] ?? ''));
    if ($carryingTargetName === '') {
        $carryingTargetName = trim(strval($metadata['carrying_target_name'] ?? ''));
    }

    if (!is_bool($carryFlag)) {
        $currentAction = trim(strval($npcData['current_action'] ?? ''));
        if ($currentAction === '') {
            $currentAction = trim(strval($metadata['current_action'] ?? ''));
        }
        $actionLower = strtolower($currentAction);
        if ($actionLower !== '' &&
            preg_match('/\b(carry|carrying|hauling|haul|kidnap|kidnapping)\b/', $actionLower) === 1) {
            $carryFlag = true;
        }
    }

    $isCarrying = false;
    if ($carryFlag === true) {
        $isCarrying = true;
    } elseif ($carryFlag === null && $carryingTargetName !== '') {
        $isCarrying = true;
    }

    return [
        'is_carrying' => $isCarrying,
        'target_name' => $carryingTargetName,
    ];
}

function stobeNpcIsCarryingTarget(array $npcData): bool {
    $state = stobeResolveNpcCarryState($npcData);
    return boolval($state['is_carrying'] ?? false);
}

function stobeNpcHasItemKeyword(array $npcData, array $keywords): bool {
    $normalizedKeywords = [];
    foreach ($keywords as $keywordRaw) {
        $keyword = strtolower(trim(strval($keywordRaw)));
        if ($keyword === '') {
            continue;
        }
        if (!in_array($keyword, $normalizedKeywords, true)) {
            $normalizedKeywords[] = $keyword;
        }
    }
    if (count($normalizedKeywords) === 0) {
        return false;
    }

    $haystacks = [];
    $equipment = trim(strval($npcData['equipment'] ?? ''));
    if ($equipment !== '') {
        $haystacks[] = $equipment;
    }
    $inventory = trim(strval($npcData['inventory'] ?? ''));
    if ($inventory !== '') {
        $haystacks[] = $inventory;
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $inventoryItems = $metadata['inventory_items'] ?? [];
    if (is_array($inventoryItems)) {
        foreach ($inventoryItems as $entry) {
            if (is_string($entry) || is_numeric($entry)) {
                $value = trim(strval($entry));
                if ($value !== '') {
                    $haystacks[] = $value;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['name', 'item_name', 'display_name', 'item_id', 'id', 'base_id'] as $field) {
                $value = trim(strval($entry[$field] ?? ''));
                if ($value !== '') {
                    $haystacks[] = $value;
                }
            }
        }
    }

    foreach ($haystacks as $haystackRaw) {
        $haystack = strtolower(strval($haystackRaw));
        if ($haystack === '') {
            continue;
        }
        foreach ($normalizedKeywords as $keyword) {
            if (strpos($haystack, $keyword) !== false) {
                return true;
            }
        }
    }
    return false;
}

function stobeNpcHasHacksaw(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, ['hacksaw', 'hack saw']);
}

function stobeNpcHasHashish(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, ['hashish']);
}

function stobeNpcHasDrinkItem(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, [
        'bloodrum',
        'blood rum',
        'cactus rum',
        'cactusrum',
        'grog',
        'sake',
    ]);
}

function stobeNpcIsSkeletonRace(array $npcData): bool {
    $race = strtolower(trim(strval($npcData['race'] ?? '')));
    if ($race !== '' && strpos($race, 'skeleton') !== false) {
        return true;
    }
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $metaRace = strtolower(trim(strval($metadata['race'] ?? '')));
    if ($metaRace !== '' && strpos($metaRace, 'skeleton') !== false) {
        return true;
    }
    return false;
}

function isAllowedActionCommand(string $command, array $allowlist): bool {
    $command = stobeCanonicalizeActionCommand($command);
    if (in_array($command, ['RELEASE_PLAYER', 'RELEASE_PRISONER', 'RELEASEPLAYER'], true)) {
        return false;
    }
    if (count($allowlist) === 0) {
        return true;
    }
    foreach ($allowlist as $allowedCommand) {
        if ($command === stobeCanonicalizeActionCommand(strval($allowedCommand))) {
            return true;
        }
    }
    return false;
}

function normalizeActionTagToken(string $rawTag, array $config = []): string {
    $value = trim($rawTag);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if ($value === '') {
        return '';
    }

    $firstAt = strpos($value, '@');
    if ($firstAt === false) {
        return '';
    }

    $command = strtoupper(trim(substr($value, 0, $firstAt)));
    $argument = trim(substr($value, $firstAt + 1));
    if ($command === '') {
        return '';
    }

    $commandAliases = [
        'STOPFOLLOW' => 'STOP_FOLLOW',
        'JOINPARTY' => 'JOIN_PARTY',
        'KILLSELF' => 'SUICIDE',
        'SELFDESTRUCT' => 'SUICIDE',
        'STOPCARRYING' => 'STOP_CARRYING',
        'GIVECATS' => 'GIVE_CATS',
        'TAKECATS' => 'TAKE_CATS',
        'TAKEITEM' => 'TAKE_ITEM',
        'GIVEITEM' => 'GIVE_ITEM',
        'DROPITEM' => 'DROP_ITEM',
        'FACTIONRELATIONS' => 'FACTION_RELATIONS',
        'SETBLOCK' => 'SET_BLOCK',
        'SETHOLD' => 'SET_HOLD',
        'SETPASSIVE' => 'SET_PASSIVE',
        'SETJOBS' => 'SET_JOBS',
        'SETRANGED' => 'SET_RANGED',
        'SETTAUNT' => 'SET_TAUNT',
        'SETSNEAK' => 'SET_SNEAK',
        'SETRESOURCE' => 'SET_RESOURCE',
        'SETMEDIC' => 'SET_MEDIC',
        'REMOVELIMB' => 'REMOVE_LIMB',
        'KILLTARGET' => 'KILL',
        'EXECUTE' => 'KILL',
        'MURDER' => 'KILL',
        'USEOBJECT' => 'USE_OBJECT',
        'USE-OBJECT' => 'USE_OBJECT',
        'USEDRUGS' => 'USE_DRUGS',
        'USE-DRUGS' => 'USE_DRUGS',
        'DRINK' => 'DRINK_ITEM',
        'DRINKITEM' => 'DRINK_ITEM',
        'DRINK_ITEM' => 'DRINK_ITEM',
        'DRINK-ITEM' => 'DRINK_ITEM',
        'TRAVELLOCATION' => 'TRAVEL_LOCATION',
        'ROLEPLAYACTION' => 'ROLEPLAY_ACTION',
        'ROLEPLAY-ACTION' => 'ROLEPLAY_ACTION',
        'NOTIFY' => 'ROLEPLAY_ACTION',
    ];
    if (isset($commandAliases[$command])) {
        $command = $commandAliases[$command];
    }
    $command = stobeCanonicalizeActionCommand($command);

    if (!isAllowedActionCommand($command, $config['allowlist'] ?? [])) {
        return '';
    }
    if (boolval($config['disallow_follow_for_player_faction'] ?? false) &&
        ($command === 'FOLLOW' || $command === 'STOP_FOLLOW')) {
        return '';
    }
    if (boolval($config['disallow_give_cats'] ?? false) &&
        $command === 'GIVE_CATS') {
        return '';
    }
    if (boolval($config['disallow_take_cats'] ?? false) &&
        $command === 'TAKE_CATS') {
        return '';
    }
    if (boolval($config['disallow_stop_carrying'] ?? false) &&
        $command === 'STOP_CARRYING') {
        return '';
    }
    if (boolval($config['disallow_remove_limb'] ?? false) &&
        $command === 'REMOVE_LIMB') {
        return '';
    }
    if (boolval($config['disallow_use_drugs'] ?? false) &&
        $command === 'USE_DRUGS') {
        return '';
    }
    if (boolval($config['disallow_drink_item'] ?? false) &&
        $command === 'DRINK_ITEM') {
        return '';
    }
    if ($command === 'TRAVEL_LOCATION' && !boolval($config['allow_travel_location'] ?? false)) {
        return '';
    }

    $simpleNoArg = [
        'JOIN_PARTY', 'LEAVE', 'IDLE',
        'STOP_FOLLOW', 'STOP_CARRYING', 'SUICIDE',
    ];
    if (in_array($command, $simpleNoArg, true)) {
        return $command . '@';
    }

    $toggleCommands = [
        'SET_BLOCK',
        'SET_HOLD',
        'SET_PASSIVE',
        'SET_JOBS',
        'SET_RANGED',
        'SET_TAUNT',
        'SET_SNEAK',
        'SET_RESOURCE',
        'SET_MEDIC',
    ];
    if (in_array($command, $toggleCommands, true)) {
        $toggle = strtoupper(trim($argument));
        if (in_array($toggle, ['ON', 'TRUE', '1', 'ENABLE', 'ENABLED'], true)) {
            return $command . '@ON';
        }
        if (in_array($toggle, ['OFF', 'FALSE', '0', 'DISABLE', 'DISABLED'], true)) {
            return $command . '@OFF';
        }
        return '';
    }

    $sanitizeInlineText = static function (string $text, int $maxLen = 220): string {
        $clean = trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
        $clean = trim(str_replace(['[', ']', '@'], '', $clean));
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) > $maxLen) {
            $clean = substr($clean, 0, $maxLen);
        }
        return trim($clean);
    };

    if ($command === 'GIVE_CATS' || $command === 'TAKE_CATS') {
        if (preg_match('/-?\d+/', $argument, $m) !== 1) {
            return '';
        }
        $amount = intval($m[0]);
        if ($amount < 0) {
            $amount = -$amount;
        }
        if ($amount < 1) {
            return '';
        }
        if ($amount > 1000000) {
            $amount = 1000000;
        }
        return $command . '@' . strval($amount);
    }

    if ($command === 'TAKE_ITEM' || $command === 'DROP_ITEM' || $command === 'GIVE_ITEM') {
        $itemName = $sanitizeInlineText($argument, 140);
        if ($itemName === '') {
            return '';
        }
        return $command . '@' . $itemName;
    }
    if ($command === 'REMOVE_LIMB') {
        $payload = trim($argument);
        if ($payload === '') {
            return '';
        }
        $payloadParts = explode('@', $payload, 2);
        if (count($payloadParts) !== 2) {
            return '';
        }
        $targetName = $sanitizeInlineText(strval($payloadParts[0]), 120);
        $limbToken = strtoupper(trim(strval($payloadParts[1])));
        if ($targetName === '' || $limbToken === '') {
            return '';
        }
        $limbAliases = [
            'LEFT_ARM' => 'LEFT_ARM',
            'RIGHT_ARM' => 'RIGHT_ARM',
            'LEFT_LEG' => 'LEFT_LEG',
            'RIGHT_LEG' => 'RIGHT_LEG',
            'LEFTARM' => 'LEFT_ARM',
            'RIGHTARM' => 'RIGHT_ARM',
            'LEFTLEG' => 'LEFT_LEG',
            'RIGHTLEG' => 'RIGHT_LEG',
            'L_ARM' => 'LEFT_ARM',
            'R_ARM' => 'RIGHT_ARM',
            'L_LEG' => 'LEFT_LEG',
            'R_LEG' => 'RIGHT_LEG',
            'LARM' => 'LEFT_ARM',
            'RARM' => 'RIGHT_ARM',
            'LLEG' => 'LEFT_LEG',
            'RLEG' => 'RIGHT_LEG',
        ];
        if (!isset($limbAliases[$limbToken])) {
            return '';
        }
        return 'REMOVE_LIMB@' . $targetName . '@' . strval($limbAliases[$limbToken]);
    }
    if ($command === 'KILL') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'KILL@' . $targetName;
    }
    if ($command === 'USE_OBJECT') {
        $objectToken = $sanitizeInlineText($argument, 160);
        if ($objectToken === '') {
            return 'USE_OBJECT@';
        }
        return 'USE_OBJECT@' . $objectToken;
    }
    if ($command === 'USE_DRUGS') {
        $drugName = $sanitizeInlineText($argument, 80);
        if ($drugName === '') {
            return '';
        }
        return 'USE_DRUGS@' . $drugName;
    }
    if ($command === 'DRINK_ITEM') {
        $drinkName = $sanitizeInlineText($argument, 80);
        if ($drinkName === '') {
            return '';
        }
        return 'DRINK_ITEM@' . $drinkName;
    }

    if ($command === 'ATTACK') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return 'ATTACK@';
        }
        return 'ATTACK@' . $targetName;
    }
    if ($command === 'FOLLOW') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'FOLLOW@' . $targetName;
    }
    if ($command === 'TRAVEL_LOCATION') {
        $locationName = $sanitizeInlineText($argument, 120);
        if ($locationName === '') {
            return '';
        }
        return 'TRAVEL_LOCATION@' . $locationName;
    }

    if ($command === 'ROLEPLAY_ACTION') {
        $notice = $sanitizeInlineText($argument, 220);
        if ($notice === '') {
            return '';
        }
        return 'ROLEPLAY_ACTION@' . $notice;
    }

    if ($command === 'FACTION_RELATIONS') {
        if (preg_match('/^\s*([^@:]+?)\s*[@:]\s*(-?\d{1,4})\s*$/', $argument, $m) !== 1) {
            return '';
        }
        $targetName = $sanitizeInlineText(strval($m[1]), 80);
        if ($targetName === '') {
            return '';
        }
        $relationDelta = intval($m[2]);
        if ($relationDelta === 0) {
            return '';
        }
        $relationDelta = $relationDelta < 0 ? -100 : 100;
        return 'FACTION_RELATIONS@' . $targetName . '@' . strval($relationDelta);
    }

    if ($command === 'TASK') {
        $taskName = strtoupper($sanitizeInlineText($argument, 64));
        if ($taskName === '') {
            return '';
        }
        return 'TASK@' . $taskName;
    }

    return '';
}

function stobeSanitizeTravelLocationToken(string $value, int $maxLen = 120): string {
    $normalized = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
    if ($normalized === '') {
        return '';
    }
    $normalized = trim(str_replace(['[', ']', '@', '|', ';'], '', $normalized));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized, " \t\n\r\0\x0B\"'.,!?:;");
    if (function_exists('stobeNormalizeZoneLocationToken')) {
        $zone = stobeNormalizeZoneLocationToken($normalized);
        if ($zone !== '') {
            $normalized = $zone;
        }
    }
    if (strlen($normalized) > $maxLen) {
        $normalized = substr($normalized, 0, $maxLen);
    }
    return trim($normalized);
}

function stobeFormatTravelCoordinateToken(float $value): string {
    $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
    if ($formatted === '' || $formatted === '-0') {
        return '0';
    }
    return $formatted;
}

function stobeResolveTravelLocationFromVisitedZones(string $requestedLocation): array|false {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $normalizedRequest = stobeSanitizeTravelLocationToken($requestedLocation);
    if ($normalizedRequest === '') {
        return false;
    }

    $candidates = [$normalizedRequest];
    if (strpos($normalizedRequest, ',') !== false) {
        $parts = preg_split('/\s*,\s*/', $normalizedRequest) ?: [];
        foreach ($parts as $part) {
            $candidate = stobeSanitizeTravelLocationToken(strval($part));
            if ($candidate === '' || in_array($candidate, $candidates, true)) {
                continue;
            }
            $candidates[] = $candidate;
        }
    }

    $match = false;
    $matchedCandidate = $normalizedRequest;
    foreach ($candidates as $candidate) {
        try {
            $match = $db->fetchOne(
                "SELECT zone_name, city_name, x, y, z
                 FROM location_zones
                 WHERE LOWER(zone_name) = LOWER($1)
                    OR LOWER(city_name) = LOWER($1)
                 ORDER BY
                    CASE
                        WHEN LOWER(zone_name) = LOWER($1) THEN 0
                        ELSE 1
                    END,
                    last_seen_ts DESC,
                    id DESC
                 LIMIT 1",
                [$candidate]
            );
        } catch (Throwable $exception) {
            $match = false;
        }
        if ($match) {
            $matchedCandidate = $candidate;
            break;
        }
    }

    if (!$match) {
        $likeNeedle = '%' . strtolower($normalizedRequest) . '%';
        try {
            $match = $db->fetchOne(
                "SELECT zone_name, city_name, x, y, z
                 FROM location_zones
                 WHERE LOWER(zone_name) LIKE $1
                    OR LOWER(city_name) LIKE $1
                 ORDER BY
                    CASE
                        WHEN LOWER(zone_name) LIKE $1 THEN 0
                        ELSE 1
                    END,
                    last_seen_ts DESC,
                    id DESC
                 LIMIT 1",
                [$likeNeedle]
            );
        } catch (Throwable $exception) {
            $match = false;
        }
    }

    if (!$match) {
        return false;
    }

    if (!is_numeric($match['x'] ?? null) || !is_numeric($match['y'] ?? null) || !is_numeric($match['z'] ?? null)) {
        return false;
    }

    $zoneLabel = stobeSanitizeTravelLocationToken(strval($match['zone_name'] ?? ''));
    $cityLabel = stobeSanitizeTravelLocationToken(strval($match['city_name'] ?? ''));

    // If the request matched a city token, keep the city as display label.
    $label = $zoneLabel;
    if ($cityLabel !== '' && strcasecmp($cityLabel, $matchedCandidate) === 0) {
        $label = $cityLabel;
    } elseif ($label === '') {
        $label = $cityLabel;
    }
    if ($label === '') {
        $label = $normalizedRequest;
    }

    return [
        'x' => floatval($match['x']),
        'y' => floatval($match['y']),
        'z' => floatval($match['z']),
        'label' => stobeSanitizeTravelLocationToken($label),
    ];
}

function stobeBuildTravelLocationFailureAction(string $requestedLocation): string {
    $destination = stobeSanitizeTravelLocationToken($requestedLocation);
    if ($destination === '') {
        $destination = 'that location';
    }
    return 'ROLEPLAY_ACTION@Can not travel to ' . $destination . ' as you have not visited it yet';
}

function stobeTransformActionForDispatch(string $normalizedAction, array|false $npcData = false): string {
    $value = trim($normalizedAction);
    if ($value === '') {
        return '';
    }

    $atPos = strpos($value, '@');
    if ($atPos === false) {
        return $value;
    }

    $command = stobeCanonicalizeActionCommand(substr($value, 0, $atPos));
    $argument = trim(substr($value, $atPos + 1));
    if ($command !== 'TRAVEL_LOCATION') {
        return $value;
    }

    if ($argument === '') {
        return stobeBuildTravelLocationFailureAction('that location');
    }

    $resolved = stobeResolveTravelLocationFromVisitedZones($argument);
    if (!$resolved) {
        return stobeBuildTravelLocationFailureAction($argument);
    }

    $xToken = stobeFormatTravelCoordinateToken(floatval($resolved['x'] ?? 0.0));
    $yToken = stobeFormatTravelCoordinateToken(floatval($resolved['y'] ?? 0.0));
    $zToken = stobeFormatTravelCoordinateToken(floatval($resolved['z'] ?? 0.0));
    $label = stobeSanitizeTravelLocationToken(strval($resolved['label'] ?? $argument));
    if ($label === '') {
        $label = stobeSanitizeTravelLocationToken($argument);
    }
    if ($label === '') {
        $label = 'destination';
    }

    // Use ';' separators because '|' is the wire protocol field delimiter.
    return 'TRAVEL_LOCATION@' . $xToken . ';' . $yToken . ';' . $zToken . ';' . $label;
}

function extractAndNormalizeActionTags(string $rawResponse, string $eventType, ?array $configOverride = null): array {
    $text = trim($rawResponse);
    $config = is_array($configOverride) ? $configOverride : getActionRuntimeConfig($eventType);

    $commandNames = [
        'ATTACK', 'FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY',
        'LEAVE', 'IDLE', 'STOP_CARRYING', 'RELEASE_PLAYER', 'RELEASE_PRISONER', 'SUICIDE',
        'GIVE_CATS', 'TAKE_CATS', 'TAKE_ITEM', 'GIVE_ITEM', 'DROP_ITEM', 'REMOVE_LIMB', 'KILL', 'USE_OBJECT', 'USE_DRUGS', 'DRINK_ITEM', 'DRINK', 'TRAVEL_LOCATION',
        'ROLEPLAY_ACTION', 'NOTIFY', 'FACTION_RELATIONS', 'TASK', 'TALK',
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC',
        // Common alias forms emitted by models without underscores.
        'STOPFOLLOW', 'JOINPARTY', 'STOPCARRYING', 'RELEASEPLAYER', 'GIVECATS', 'TAKECATS',
        'TAKEITEM', 'GIVEITEM', 'DROPITEM', 'REMOVELIMB', 'KILLTARGET', 'EXECUTE', 'MURDER', 'USEOBJECT', 'USE-OBJECT', 'USEDRUGS', 'USE-DRUGS', 'DRINKITEM', 'DRINK-ITEM', 'FACTIONRELATIONS', 'TRAVELLOCATION',
        'ROLEPLAYACTION', 'ROLEPLAY-ACTION',
        'SETBLOCK', 'SETHOLD', 'SETPASSIVE', 'SETJOBS', 'SETRANGED',
        'SETTAUNT', 'SETSNEAK', 'SETRESOURCE', 'SETMEDIC',
    ];
    $commandAlternation = implode('|', $commandNames);

    $commandPattern = '/(?:^|[\s>])((?:' . $commandAlternation . ')\s*@[^\r\n<]*)/im';

    $rawTags = [];
    $commandMatches = [];
    preg_match_all($commandPattern, $text, $commandMatches);
    if (is_array($commandMatches[1] ?? null)) {
        foreach ($commandMatches[1] as $match) {
            $candidate = trim(strval($match));
            if ($candidate !== '') {
                $rawTags[] = $candidate;
            }
        }
    }

    $cleanText = trim(preg_replace($commandPattern, ' ', $text) ?? $text);
    $cleanText = trim(preg_replace('/[ \t]{2,}/', ' ', $cleanText) ?? $cleanText);

    if (!boolval($config['enabled'] ?? false)) {
        return [
            'text' => $cleanText,
            'actions' => [],
            'raw_actions' => $rawTags,
            'actions_enabled' => false,
        ];
    }

    $normalized = [];
    $seen = [];
    $maxActions = intval($config['max_actions'] ?? 1);
    if ($maxActions < 1) {
        $maxActions = 1;
    }
    foreach ($rawTags as $rawTag) {
        if (count($normalized) >= $maxActions) {
            break;
        }
        $parsed = normalizeActionTagToken(strval($rawTag), $config);
        if ($parsed === '') {
            continue;
        }
        $key = strtolower($parsed);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $parsed;
    }

    return [
        'text' => $cleanText,
        'actions' => $normalized,
        'raw_actions' => $rawTags,
        'actions_enabled' => true,
    ];
}

function parseNpcKnowledgeTags(array $npcData, string $npcName = ''): array {
    $rawTags = trim(strval($npcData['world_knowledge_tags'] ?? ''));
    if ($rawTags === '') {
        $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
        $rawTags = trim(strval($metadata['world_knowledge_tags'] ?? ''));
    }
    $tokens = [];
    if ($rawTags !== '') {
        $tokens = explode(',', strtolower($rawTags));
    }
    $normalized = [];
    foreach ($tokens as $token) {
        $tag = trim($token);
        if ($tag === '') {
            continue;
        }
        if (!in_array($tag, $normalized, true)) {
            $normalized[] = $tag;
        }
    }

    // Mirror Herika awareness behavior: NPC identity can satisfy class-matching
    // without implicitly granting full knowall access.
    $resolvedNpcName = normalizeParticipantNameToken($npcName);
    $npcTagCandidates = [
        strtolower(trim($resolvedNpcName)),
        strtolower(trim(baseNameWithoutBracketSuffix($resolvedNpcName))),
    ];
    foreach ($npcTagCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (!in_array($candidate, $normalized, true)) {
            $normalized[] = $candidate;
        }
    }

    return $normalized;
}

function stobeWorldKnowledgeHasQueryableTerms(string $value): bool {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }

    $tokens = preg_split('/[^a-z0-9_]+/i', $normalized) ?: [];
    foreach ($tokens as $token) {
        $word = trim(strval($token));
        if (strlen($word) >= 3) {
            return true;
        }
    }
    return false;
}

function stobeWorldKnowledgeUniqueTerms(array $terms, int $max = 10): array {
    $unique = [];
    foreach ($terms as $term) {
        $clean = trim(strval($term));
        if ($clean === '') {
            continue;
        }
        $key = strtolower($clean);
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = $clean;
        if (count($unique) >= $max) {
            break;
        }
    }
    return array_values($unique);
}

function stobeWorldKnowledgeBuildNpcKey(string $npcName, array $npcData): string {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $storageId = normalizeStorageIdToken(strval($metadata['storage_id'] ?? ''));
    if ($storageId !== '') {
        return strtolower($storageId);
    }
    $normalizedName = normalizeParticipantNameToken($npcName);
    if ($normalizedName !== '') {
        return strtolower($normalizedName);
    }
    return strtolower(trim($npcName));
}

function stobeWorldKnowledgeBuildCurrentTopicConfId(string $npcKey): string {
    $normalized = strtolower(trim($npcKey));
    $normalized = preg_replace('/[^a-z0-9_:-]+/i', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_:');
    if ($normalized === '') {
        $normalized = 'global';
    }
    if (strlen($normalized) > 96) {
        $normalized = substr($normalized, 0, 96);
    }
    return 'current_world_knowledge_topic:' . $normalized;
}

function stobeWorldKnowledgeGetCurrentTopic(string $npcKey): string {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $confId = stobeWorldKnowledgeBuildCurrentTopicConfId($npcKey);
    try {
        $row = $db->fetchOne(
            "SELECT value
             FROM conf_opts
             WHERE id = $1
             LIMIT 1",
            [$confId]
        );
    } catch (Throwable $exception) {
        return '';
    }

    return trim(strval($row['value'] ?? ''));
}

function stobeWorldKnowledgeSetCurrentTopic(string $npcKey, string $npcName, string $topic, string $eventType = '', int $gamets = 0): void {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return;
    }

    $normalizedTopic = trim($topic);
    if ($normalizedTopic === '') {
        return;
    }

    $confId = stobeWorldKnowledgeBuildCurrentTopicConfId($npcKey);
    try {
        $db->exec(
            "INSERT INTO conf_opts (id, value, updated_at)
             VALUES ($1, $2, NOW())
             ON CONFLICT (id) DO UPDATE
             SET value = EXCLUDED.value,
                 updated_at = NOW()",
            [$confId, $normalizedTopic]
        );
    } catch (Throwable $exception) {
        return;
    }
}

function stobeWorldKnowledgeEventAllowed(string $eventType): bool {
    $normalized = strtolower(trim($eventType));
    if ($normalized === '') {
        $normalized = 'chat';
    }
    return in_array(
        $normalized,
        ['chat', 'rechat', 'continue', 'instruction', 'suggestion', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'],
        true
    );
}

function stobeWorldKnowledgeRetrieverEnabled(): bool {
    return getSettingBool('WORLD_KNOWLEDGE_ENABLED', true);
}

function stobeWorldKnowledgeSplitKnowledgeClassSpec(string $rawSpec): array {
    $positive = [];
    $negative = [];
    $tokens = explode(',', strtolower(trim($rawSpec)));
    foreach ($tokens as $token) {
        $value = trim($token);
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, '!')) {
            $tag = trim(substr($value, 1));
            if ($tag !== '' && !in_array($tag, $negative, true)) {
                $negative[] = $tag;
            }
            continue;
        }
        if (!in_array($value, $positive, true)) {
            $positive[] = $value;
        }
    }
    return [
        'positive' => $positive,
        'negative' => $negative,
    ];
}

function stobeWorldKnowledgeIsKnowledgeAllowed(string $requiredClasses, array $npcKnowledgeTags, bool $isKnowAll): bool {
    if ($isKnowAll) {
        return true;
    }

    $spec = stobeWorldKnowledgeSplitKnowledgeClassSpec($requiredClasses);
    $positive = $spec['positive'];
    $negative = $spec['negative'];

    if (count($negative) > 0) {
        foreach ($negative as $blockedTag) {
            if (in_array($blockedTag, $npcKnowledgeTags, true)) {
                return false;
            }
        }
    }

    if (count($positive) === 0) {
        return true;
    }
    foreach ($positive as $requiredTag) {
        if (in_array($requiredTag, $npcKnowledgeTags, true)) {
            return true;
        }
    }
    return false;
}

function stobeWorldKnowledgeSelectKnowledgePayload(array $row, array $npcKnowledgeTags, bool $isKnowAll): array {
    $topic = trim(strval($row['topic'] ?? ''));

    $advancedDesc = trim(strval($row['topic_desc'] ?? ''));
    $advancedClass = trim(strval($row['knowledge_class'] ?? ''));
    if ($advancedDesc !== '' && stobeWorldKnowledgeIsKnowledgeAllowed($advancedClass, $npcKnowledgeTags, $isKnowAll)) {
        return [
            'allowed' => true,
            'mode' => 'advanced',
            'topic' => $topic,
            'desc' => $advancedDesc,
            'class_used' => $advancedClass,
        ];
    }

    $basicDesc = trim(strval($row['topic_desc_basic'] ?? ''));
    $basicClass = trim(strval($row['knowledge_class_basic'] ?? ''));
    if ($basicDesc !== '' && stobeWorldKnowledgeIsKnowledgeAllowed($basicClass, $npcKnowledgeTags, $isKnowAll)) {
        return [
            'allowed' => true,
            'mode' => 'basic',
            'topic' => $topic,
            'desc' => $basicDesc,
            'class_used' => $basicClass,
        ];
    }

    return [
        'allowed' => false,
        'mode' => 'blocked',
        'topic' => $topic,
        'desc' => '',
        'class_used' => '',
    ];
}

function stobeWorldKnowledgeResolveLocationContext(string $npcName): string {
    $parts = [];
    $seen = [];
    $push = static function (string $value) use (&$parts, &$seen): void {
        $token = trim($value);
        if ($token === '') {
            return;
        }
        $key = strtolower($token);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $parts[] = $token;
    };

    $npcGeo = getEventGeoFromNpcName($npcName);
    foreach (['location', 'city', 'region'] as $field) {
        $push(strval($npcGeo[$field] ?? ''));
    }

    if (count($parts) === 0) {
        $playerGeo = getEventGeoFromPlayerSnapshot();
        foreach (['location', 'city', 'region'] as $field) {
            $push(strval($playerGeo[$field] ?? ''));
        }
    }

    return implode(' ', $parts);
}

function stobeWorldKnowledgeExtractContextKeywords(string $npcName, int $historyRows = 16, int $maxKeywords = 8): string {
    $rows = DataEventLog(max(6, min(60, $historyRows)), $npcName);
    if (count($rows) === 0) {
        return '';
    }

    $stopwords = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'just',
        'about', 'into', 'your', 'youre', 'they', 'them', 'then', 'than',
        'will', 'would', 'could', 'should', 'there', 'their', 'talking',
        'response', 'rechat', 'chat', 'inputtext', 'bored', 'pending',
        'speaker', 'target', 'line', 'said', 'says', 'what', 'when', 'where',
        'been', 'were', 'while', 'keep', 'make', 'made', 'very', 'more',
        'some', 'only', 'same', 'over', 'under', 'does', 'dont', 'cant',
        'im', 'ive', 'its', 'our', 'out', 'off', 'not', 'all',
    ];
    $stopLookup = array_fill_keys($stopwords, true);

    $weights = [];
    foreach ($rows as $row) {
        $line = trim(strval($row['data'] ?? ''));
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\(talking to:\s*[^\)]+\)/i', ' ', $line) ?? $line;
        $line = preg_replace('/^\s*[^:]+:\s*/', '', $line) ?? $line;
        $line = strtolower($line);
        $tokens = preg_split('/[^a-z0-9_]+/i', $line) ?: [];
        foreach ($tokens as $token) {
            $word = trim($token);
            if (strlen($word) < 4) {
                continue;
            }
            if (isset($stopLookup[$word])) {
                continue;
            }
            if (preg_match('/^[0-9]+$/', $word) === 1) {
                continue;
            }
            if (!isset($weights[$word])) {
                $weights[$word] = 0;
            }
            $weights[$word] += 1;
        }
    }

    if (count($weights) === 0) {
        return '';
    }

    arsort($weights);
    $keywords = array_slice(array_keys($weights), 0, max(1, min(20, $maxKeywords)));
    return implode(' ', $keywords);
}

function stobeWorldKnowledgeExtractTopicsHeuristic(string $message, string $contextKeywords = '', int $maxTopics = 2): array {
    $safeMaxTopics = max(1, min(5, $maxTopics));
    $topics = [];

    $normalizedMessage = trim($message);
    if ($normalizedMessage !== '') {
        $topics[] = truncatePromptValue($normalizedMessage, 140);
    }

    $text = strtolower($normalizedMessage . ' ' . $contextKeywords);
    $tokens = preg_split('/[^a-z0-9_]+/i', $text) ?: [];
    $freq = [];
    $stopwords = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'just',
        'about', 'into', 'your', 'youre', 'they', 'them', 'then', 'than',
        'will', 'would', 'could', 'should', 'there', 'their', 'talking',
        'response', 'rechat', 'chat', 'inputtext', 'bored', 'speaker', 'target',
        'line', 'said', 'says', 'what', 'when', 'where', 'been', 'were', 'while',
        'keep', 'make', 'made', 'very', 'more', 'some', 'only', 'same', 'over',
        'under', 'does', 'dont', 'cant', 'im', 'ive', 'its', 'our', 'out', 'off',
        'not', 'all',
    ];
    $stopLookup = array_fill_keys($stopwords, true);

    foreach ($tokens as $token) {
        $word = trim($token);
        if (strlen($word) < 4) {
            continue;
        }
        if (isset($stopLookup[$word])) {
            continue;
        }
        if (preg_match('/^[0-9]+$/', $word) === 1) {
            continue;
        }
        if (!isset($freq[$word])) {
            $freq[$word] = 0;
        }
        $freq[$word] += 1;
    }

    if (count($freq) > 0) {
        arsort($freq);
        foreach (array_keys($freq) as $word) {
            $topics[] = $word;
            if (count($topics) >= ($safeMaxTopics * 3)) {
                break;
            }
        }
    }

    $topics = stobeWorldKnowledgeUniqueTerms($topics, $safeMaxTopics);
    if (count($topics) === 0 && trim($message) !== '') {
        $topics[] = truncatePromptValue($message, 80);
    }
    return $topics;
}

function stobeWorldKnowledgeMinimeServiceAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $socket = @fsockopen('127.0.0.1', 8082, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        $available = true;
    } else {
        $available = false;
        stobeLogWarn('World knowledge Minime service unavailable; using heuristic fallback', [
            'host' => '127.0.0.1',
            'port' => 8082,
        ]);
    }

    return boolval($available);
}

function stobeWorldKnowledgeMinimeTopicRequest(string $text): ?string
{
    $payload = trim($text);
    if ($payload === '') {
        return null;
    }
    if (!stobeWorldKnowledgeMinimeServiceAvailable()) {
        return null;
    }

    $timeout = intval($GLOBALS['HTTP_TIMEOUT'] ?? 15);
    if ($timeout < 2) {
        $timeout = 2;
    } elseif ($timeout > 20) {
        $timeout = 20;
    }

    $url = 'http://127.0.0.1:8082/topic?text=' . urlencode($payload);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return $raw;
}

function stobeWorldKnowledgeNormalizeTopicForDedup(string $topic): string
{
    $normalized = strtolower(trim($topic));
    if ($normalized === '') {
        return '';
    }
    $normalized = str_replace('_', ' ', $normalized);
    $normalized = preg_replace('/[^a-z0-9\s]+/i', ' ', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeWorldKnowledgeExtractTopicsMinime(string $message, int $maxTopics = 2): array
{
    $safeMaxTopics = max(1, min(5, $maxTopics));
    $remainingText = trim($message);
    if ($remainingText === '') {
        return [];
    }

    $topics = [];
    $seen = [];
    for ($i = 0; $i < $safeMaxTopics; $i++) {
        $topicPayload = stobeWorldKnowledgeMinimeTopicRequest($remainingText);
        if ($topicPayload === null) {
            break;
        }

        $decoded = json_decode($topicPayload, true);
        if (!is_array($decoded)) {
            break;
        }

        $generated = $decoded['generated_tags'] ?? '';
        $candidates = [];
        if (is_array($generated)) {
            foreach ($generated as $item) {
                if (!is_scalar($item) || $item === null) {
                    continue;
                }
                $candidates[] = strval($item);
            }
        } else {
            $candidates = preg_split('/[,;\r\n]+/', strval($generated)) ?: [];
        }

        $selected = '';
        foreach ($candidates as $candidate) {
            $topic = trim(strval($candidate));
            if ($topic === '') {
                continue;
            }
            $dedupe = stobeWorldKnowledgeNormalizeTopicForDedup($topic);
            if ($dedupe === '' || isset($seen[$dedupe])) {
                continue;
            }
            $selected = $topic;
            $seen[$dedupe] = true;
            break;
        }

        if ($selected === '') {
            break;
        }

        $topics[] = $selected;
        $remainingText = preg_replace('/\b' . preg_quote($selected, '/') . '\b/iu', ' ', $remainingText) ?? $remainingText;
        $remainingText = trim(preg_replace('/\s+/', ' ', $remainingText) ?? $remainingText);
        if ($remainingText === '') {
            break;
        }
    }

    return stobeWorldKnowledgeUniqueTerms($topics, $safeMaxTopics);
}

function stobeWorldKnowledgeExtractTopics(string $message, string $contextKeywords = '', int $maxTopics = 2): array
{
    $topics = stobeWorldKnowledgeExtractTopicsMinime($message, $maxTopics);
    if (count($topics) > 0) {
        return $topics;
    }
    return stobeWorldKnowledgeExtractTopicsHeuristic($message, $contextKeywords, $maxTopics);
}

function stobeWorldKnowledgeWriteAuditRow(array $payload): void {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return;
    }

    $input = truncatePromptValue(strval($payload['input_text'] ?? ''), 3000);
    $selectedTopic = trim(strval($payload['selected_topic'] ?? ''));
    $selectedMode = trim(strval($payload['selected_mode'] ?? ''));
    $selectedEntryId = intval($payload['selected_entry_id'] ?? 0);
    $locationContext = truncatePromptValue(strval($payload['location_context'] ?? ''), 400);
    $contextKeywords = truncatePromptValue(strval($payload['context_keywords'] ?? ''), 400);
    $currentTopicBefore = truncatePromptValue(strval($payload['current_topic_before'] ?? ''), 180);
    $currentTopicAfter = truncatePromptValue(strval($payload['current_topic_after'] ?? ''), 180);
    $knowledgeTags = truncatePromptValue(strval($payload['knowledge_tags'] ?? ''), 240);
    $notes = truncatePromptValue(strval($payload['notes'] ?? ''), 800);
    $rank = floatval($payload['selected_rank'] ?? 0.0);

    $topicText = '';
    $rawTopics = $payload['extracted_topics'] ?? [];
    if (is_array($rawTopics)) {
        $topicParts = [];
        foreach ($rawTopics as $topic) {
            $clean = trim(strval($topic));
            if ($clean === '') {
                continue;
            }
            $topicParts[] = $clean;
            if (count($topicParts) >= 10) {
                break;
            }
        }
        $topicText = implode(', ', $topicParts);
    } elseif (!is_bool($rawTopics) && $rawTopics !== null) {
        $topicText = trim(strval($rawTopics));
    }
    $topicText = truncatePromptValue($topicText, 800);

    $keywordParts = [];
    if ($topicText !== '') {
        $keywordParts[] = 'topics=' . $topicText;
    }
    if ($notes !== '') {
        $keywordParts[] = 'notes=' . $notes;
    }
    $keywords = trim(implode(' | ', $keywordParts));
    if ($keywords === '') {
        $keywords = 'world_knowledge';
    }

    $memoryParts = [];
    if ($selectedTopic !== '') {
        $memoryParts[] = 'selected=' . $selectedTopic;
    }
    if ($selectedMode !== '') {
        $memoryParts[] = 'mode=' . $selectedMode;
    }
    if ($selectedEntryId > 0) {
        $memoryParts[] = 'entry_id=' . strval($selectedEntryId);
    }
    if ($locationContext !== '') {
        $memoryParts[] = 'location=' . $locationContext;
    }
    if ($contextKeywords !== '') {
        $memoryParts[] = 'context=' . $contextKeywords;
    }
    if ($currentTopicBefore !== '') {
        $memoryParts[] = 'before=' . $currentTopicBefore;
    }
    if ($currentTopicAfter !== '') {
        $memoryParts[] = 'after=' . $currentTopicAfter;
    }
    if ($knowledgeTags !== '') {
        $memoryParts[] = 'tags=' . $knowledgeTags;
    }
    $memory = truncatePromptValue(implode(' / ', $memoryParts), 8000);

    $elapsedSeconds = floatval($payload['elapsed_seconds'] ?? 0.0);
    if ($elapsedSeconds < 0.0) {
        $elapsedSeconds = 0.0;
    }
    $elapsedText = number_format($elapsedSeconds, 4, '.', '') . ' secs';

    try {
        $result = $db->exec(
            'INSERT INTO audit_memory (input, keywords, rank_any, rank_all, memory, "time")
             VALUES ($1, $2, $3, $4, $5, $6)',
            [$input, $keywords, $rank, $rank, $memory, $elapsedText]
        );
        if ($result === false) {
            $error = '';
            if (is_object($db) && method_exists($db, 'GetLastError')) {
                $error = strval($db->GetLastError());
            }
            stobeLogWarn('Failed to persist audit_memory row', [
                'error' => $error,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to persist audit_memory row', [
            'error' => $exception->getMessage(),
        ]);
    }
}

function truncatePromptValue(string $value, int $maxLength = 220): string {
    $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($clean === '') {
        return '';
    }
    if (mb_strlen($clean, 'UTF-8') <= $maxLength) {
        return $clean;
    }
    return rtrim(mb_substr($clean, 0, $maxLength - 3, 'UTF-8')) . '...';
}

function stobePromptXmlEscape(mixed $value): string {
    $text = strval($value);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Keep apostrophes readable in text nodes while still escaping XML control chars.
    return htmlspecialchars($text, ENT_COMPAT | ENT_XML1, 'UTF-8');
}

function stobeBuildGameTimePromptBlock(mixed $gamets): string {
    $safeGamets = stobeGametsNormalize($gamets);
    $dateLabel = stobeGametsDateLabel($safeGamets);

    return "<game_time>\n"
        . "  <date_label>" . stobePromptXmlEscape($dateLabel) . "</date_label>\n"
        . "  <gamets>" . strval($safeGamets) . "</gamets>\n"
        . "</game_time>";
}

function stobeBuildRecentContextPromptBlock(string $historyText, string $tag = 'recent_context'): string {
    $normalizedTag = strtolower(trim($tag));
    if ($normalizedTag === '') {
        $normalizedTag = 'recent_context';
    }
    $normalizedTag = preg_replace('/[^a-z0-9_]+/i', '_', $normalizedTag) ?? 'recent_context';
    if ($normalizedTag === '') {
        $normalizedTag = 'recent_context';
    }

    $lines = preg_split('/\R+/', trim($historyText)) ?: [];
    $xml = ["<{$normalizedTag}>"];
    $added = 0;
    foreach ($lines as $line) {
        $clean = trim(strval($line));
        if ($clean === '') {
            continue;
        }
        $xml[] = '  <line>' . stobePromptXmlEscape($clean) . '</line>';
        $added++;
    }
    if ($added === 0) {
        $xml[] = '  <line>(none)</line>';
    }
    $xml[] = "</{$normalizedTag}>";

    return implode("\n", $xml);
}

function stobeNormalizeContextHistoryDataLine(string $historyData): string {
    $raw = trim($historyData);
    if ($raw === '') {
        return '';
    }

    // Collapse multiline payloads into one line to avoid fragmented context
    // entries when old event rows contain raw JSON.
    $singleLine = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

    if (preg_match('/^\s*([^:]{1,120}):\s*(\{.*)$/s', $raw, $matches) === 1) {
        $speaker = normalizeParticipantNameToken(strval($matches[1] ?? ''));
        $jsonPayload = trim(strval($matches[2] ?? ''));
        $structured = stobeParseStructuredDialogueResponse($jsonPayload, 'chat');
        $message = trim(strval($structured['message'] ?? ''));
        if ($message !== '') {
            $line = $speaker !== '' ? ($speaker . ': ' . $message) : $message;
            $listener = normalizeParticipantNameToken(strval($structured['listener'] ?? ''));
            if ($listener !== '') {
                $line .= ' (talking to: ' . $listener . ')';
            }
            return trim($line);
        }
    }

    if (str_starts_with(ltrim($raw), '{')) {
        $structured = stobeParseStructuredDialogueResponse($raw, 'chat');
        $message = trim(strval($structured['message'] ?? ''));
        if ($message !== '') {
            $character = normalizeParticipantNameToken(strval($structured['character'] ?? ''));
            $line = $character !== '' ? ($character . ': ' . $message) : $message;
            $listener = normalizeParticipantNameToken(strval($structured['listener'] ?? ''));
            if ($listener !== '') {
                $line .= ' (talking to: ' . $listener . ')';
            }
            return trim($line);
        }
    }

    return $singleLine;
}

function stobeIsMergeableRecentContextType(string $historyType): bool {
    return in_array($historyType, ['infonpc', 'infonpc_close', 'infoloc', 'location', 'infoitems'], true);
}

function stobeBuildRecentContextDedupeKey(string $historyType, string $historyData): string {
    $normalizedType = strtolower(trim($historyType));
    $normalizedData = strtolower(trim(preg_replace('/\s+/u', ' ', $historyData) ?? $historyData));
    return $normalizedType . '|' . $normalizedData;
}

function stobeBuildRecentContextMessages(array $eventHistory, int $currentGamets = 0, int $maxMessages = 64): array {
    $messages = [];
    $messageTypes = [];
    $messageKeys = [];
    $lastLocation = '';
    $safeCurrentGamets = max(0, intval($currentGamets));

    $rows = array_reverse($eventHistory);
    foreach ($rows as $row) {
        $location = trim(strval($row['location'] ?? ''));
        if ($location !== '' && strcasecmp($location, $lastLocation) !== 0) {
            $line = 'LOCATION CHANGE to ' . $location;
            $rowGamets = max(0, intval($row['gamets'] ?? 0));
            if ($safeCurrentGamets > 0 && $rowGamets > 0) {
                $hoursAgo = round(max(0.0, ($safeCurrentGamets - $rowGamets) * 0.0000024), 0);
                $line .= ', timeline mark: ' . strval($hoursAgo) . ' hours ago';
            }
            $messages[] = [
                'role' => 'user',
                'content' => $line,
            ];
            $messageTypes[] = 'location';
            $messageKeys[] = stobeBuildRecentContextDedupeKey('location', $line);
            $lastLocation = $location;
        }

        $historyType = strtolower(trim(strval($row['type'] ?? 'event')));
        $historyData = stobeNormalizeContextHistoryDataLine(strval($row['data'] ?? ''));
        if ($historyData === '') {
            continue;
        }

        $inlineTypes = [
            'inputtext', 'inputtext_s', 'chat', 'rechat', 'bored',
            'action', 'death', 'limb_loss', 'knockout',
            'enslaved', 'freed_slave', 'item_pickup',
        ];
        if (!in_array($historyType, $inlineTypes, true)) {
            $historyData = '[' . $historyType . '] ' . $historyData;
        }

        $messages[] = [
            'role' => 'user',
            'content' => " (...\n" . $historyData . "\n...)",
        ];
        $dedupeKey = stobeBuildRecentContextDedupeKey($historyType, $historyData);
        $lastIndex = count($messages) - 1;
        $priorIndex = $lastIndex - 1;

        if ($priorIndex >= 0) {
            $previousKey = strval($messageKeys[$priorIndex] ?? '');
            if ($previousKey !== '' && $previousKey === $dedupeKey) {
                array_pop($messages);
                continue;
            }

            if (
                stobeIsMergeableRecentContextType($historyType) &&
                strval($messageTypes[$priorIndex] ?? '') === $historyType
            ) {
                $messages[$priorIndex] = $messages[$lastIndex];
                $messageKeys[$priorIndex] = $dedupeKey;
                array_pop($messages);
                continue;
            }
        }

        $messageTypes[] = $historyType;
        $messageKeys[] = $dedupeKey;
    }

    $safeMax = max(8, min(120, $maxMessages));
    if (count($messages) > $safeMax) {
        $messages = array_slice($messages, -$safeMax);
    }
    return $messages;
}

function stobeBuildRecentContextMessagesFromText(string $historyText, int $maxMessages = 32): array {
    $messages = [];
    $lines = preg_split('/\R+/', trim($historyText)) ?: [];
    foreach ($lines as $line) {
        $clean = trim(strval($line));
        if ($clean === '') {
            continue;
        }
        $messages[] = [
            'role' => 'user',
            'content' => " (...\n" . $clean . "\n...)",
        ];
    }
    $safeMax = max(4, min(80, $maxMessages));
    if (count($messages) > $safeMax) {
        $messages = array_slice($messages, -$safeMax);
    }
    return $messages;
}

function stobeBuildTurnGuidanceUserPrompt(string $npcName, string $previousSpeaker = ''): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }

    $safeSpeaker = normalizeParticipantNameToken($previousSpeaker);
    $targetLine = 'Address whoever just spoke.';
    if ($safeSpeaker !== '') {
        $targetLine = 'Address ' . $safeSpeaker . ' directly.';
    }

    return 'Dialogue turn for ' . $safeNpc . '. Respond naturally to whoever just spoke. '
        . $targetLine
        . ' Write the next dialogue line. Be original and avoid repeating phraseology from recent context history.';
}

function stobeBuildOutputContractUserPrompt(
    string $npcName,
    bool $preferAction = false,
    bool $streamTextMode = false,
    ?bool $inPlayerFaction = null
): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }

    $npcData = false;
    if ($inPlayerFaction === null) {
        $npcData = getNpcData($safeNpc);
        if (is_array($npcData) && count($npcData) > 0) {
            $inPlayerFaction = npcIsInPlayerFaction($npcData);
        }
    } else {
        $npcData = getNpcData($safeNpc);
    }
    if (!is_bool($inPlayerFaction)) {
        $inPlayerFaction = null;
    }
    $canStopCarrying = is_array($npcData) && count($npcData) > 0 && stobeNpcIsCarryingTarget($npcData);
    $canRemoveLimb = is_array($npcData) && count($npcData) > 0 && stobeNpcHasHacksaw($npcData);
    $npcIsSkeleton = is_array($npcData) && count($npcData) > 0 && stobeNpcIsSkeletonRace($npcData);
    $canUseDrugs = is_array($npcData) && count($npcData) > 0 && !$npcIsSkeleton && stobeNpcHasHashish($npcData);
    $canDrinkItem = is_array($npcData) && count($npcData) > 0 && !$npcIsSkeleton && stobeNpcHasDrinkItem($npcData);
    $actionConfig = stobeBuildActionConfigForNpc('chat', $npcData);
    $allowGiveCats = !boolval($actionConfig['disallow_give_cats'] ?? false);
    $allowTakeCats = !boolval($actionConfig['disallow_take_cats'] ?? false);

    $actionLine = $preferAction
        ? '(If another action is even remotely contextually appropriate, use it, even if in doubt).'
        : '(If action is clearly contextually appropriate, use it; otherwise use Talk).';
    $actionLine .= " Command semantics: GIVE_ITEM means hand over an item; GIVE_CATS means this NPC gives away its own money. Do not use GIVE_CATS for trade pricing.";
    $actionLine .= " KILL is only valid on knocked-out, unconscious, imprisoned, or carried targets.";

    $actions = [
        'Talk',
        'Attack',
        'Suicide',
        'Idle',
        'TakeItem',
        'GiveItem',
        'DropItem',
        'Kill',
        'RoleplayAction',
        'FactionRelations',
        'Task',
        'SetBlock',
        'SetHold',
        'SetPassive',
        'SetJobs',
        'SetRanged',
        'SetTaunt',
        'SetSneak',
        'SetResource',
        'SetMedic',
    ];
    if ($allowGiveCats) {
        $actions[] = 'GiveCats';
    }
    if ($allowTakeCats) {
        $actions[] = 'TakeCats';
    }
    if ($canStopCarrying) {
        $actions[] = 'StopCarrying';
    }
    if ($canRemoveLimb) {
        $actions[] = 'RemoveLimb';
    }
    if ($canUseDrugs) {
        $actions[] = 'UseDrugs';
    }
    if ($canDrinkItem) {
        $actions[] = 'Drink';
    }
    if ($inPlayerFaction !== true) {
        $actions[] = 'Follow';
        $actions[] = 'StopFollow';
    }
    if ($inPlayerFaction === true) {
        $actions[] = 'Leave';
    } elseif ($inPlayerFaction === false) {
        $actions[] = 'JoinParty';
    } else {
        $actions[] = 'JoinParty';
        $actions[] = 'Leave';
    }
    $actions[] = 'TravelLocation';
    $moodsCsv = '';
    if (is_array($npcData)) {
        $moodsCsv = trim(strval($npcData['emote_moods'] ?? ''));
    }
    if ($moodsCsv !== '') {
        if (function_exists('stobeNormalizeEmoteMoodsCsv')) {
            $moodsCsv = stobeNormalizeEmoteMoodsCsv($moodsCsv);
        } else {
            $moodsCsv = str_replace(
                'mockingdesperatedistressedpleadingsad',
                'mocking,desperate,distressed,pleading,sad',
                $moodsCsv
            );
        }
    }
    if ($moodsCsv === '') {
        if (function_exists('stobeResolveGlobalEmoteMoods')) {
            $moodsCsv = trim(stobeResolveGlobalEmoteMoods());
        } else {
            $moodsCsv = trim(strval(getSetting(
                'EMOTEMOODS',
                'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,sardonic,smirking,amused,default,assisting,irritated,playful,neutral,teasing,mocking,desperate,distressed,pleading,sad'
            )));
            $moodsCsv = str_replace(
                'mockingdesperatedistressedpleadingsad',
                'mocking,desperate,distressed,pleading,sad',
                $moodsCsv
            );
        }
    }
    $moods = [];
    $seenMoods = [];
    foreach (explode(',', $moodsCsv) as $rawMood) {
        $normalizedMood = strtolower(trim(strval($rawMood)));
        if ($normalizedMood === '' || isset($seenMoods[$normalizedMood])) {
            continue;
        }
        $seenMoods[$normalizedMood] = true;
        $moods[] = $normalizedMood;
    }
    if (count($moods) === 0) {
        $moods = [
            'default', 'neutral', 'assertive', 'kindly', 'smug', 'sarcastic',
            'teasing', 'playful', 'sardonic', 'irritated', 'amused', 'assisting',
        ];
    }

    $exampleAction = 'JOIN_PARTY@';
    if ($inPlayerFaction === true) {
        $exampleAction = 'LEAVE@';
    }

    if ($streamTextMode) {
        $exampleFollow = $inPlayerFaction === true ? '' : 'FOLLOW@TargetName, STOP_FOLLOW@, ';
        $exampleCarry = $canStopCarrying ? 'STOP_CARRYING@TargetName, ' : '';
        $exampleRemoveLimb = $canRemoveLimb ? 'REMOVE_LIMB@TargetName@LEFT_ARM, ' : '';
        $exampleKill = 'KILL@TargetName, ';
        $exampleUseObject = 'USE_OBJECT@ChairName, ';
        $exampleUseDrugs = $canUseDrugs ? 'USE_DRUGS@Hashish, ' : '';
        $exampleDrink = $canDrinkItem ? 'DRINK@Sake, ' : '';
        $exampleTravel = 'TRAVEL_LOCATION@LocationName, ';
        $exampleCats = '';
        if ($allowTakeCats) {
            $exampleCats .= 'TAKE_CATS@50, ';
        }
        if ($allowGiveCats) {
            $exampleCats .= 'GIVE_CATS@50, ';
        }
        return $actionLine
            . " Use <speech_style> for reference.\n"
            . "Return plain dialogue text only (NO JSON, NO markdown fences).\n"
            . "If an action is needed, append exactly one final line in command form COMMAND@ARG.\n"
            . "Examples: ATTACK@TargetName, " . $exampleFollow . $exampleCarry . $exampleRemoveLimb . $exampleKill . $exampleUseObject . $exampleUseDrugs . $exampleDrink . $exampleTravel . $exampleCats . "GIVE_ITEM@ItemName, " . $exampleAction . ", IDLE@, SUICIDE@, SET_BLOCK@ON, SET_PASSIVE@OFF.\n"
            . "If no action is needed, output dialogue text only.";
    }

    $schema = [
        'character' => $safeNpc,
        'listener' => 'who ' . $safeNpc . ' is addressing',
        'mood' => implode('|', $moods),
        'action' => implode('|', $actions),
        'target' => 'action target actor or destination name',
        'item' => 'item name, amount (for GIVE/TAKE_CATS), limb token (LEFT_ARM/RIGHT_ARM/LEFT_LEG/RIGHT_LEG), object token for USE_OBJECT, or consumable item for DRINK/USE_DRUGS',
        'message' => 'lines of dialogue',
    ];

    return $actionLine
        . " Use <speech_style> for reference.\n"
        . "Use ONLY this JSON object to give your answer. Do not send any other characters outside of this JSON structure:\n"
        . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function stobeDecodeStructuredDialoguePayload(string $rawResponse): array {
    $trimmed = trim($rawResponse);
    if ($trimmed === '') {
        return [];
    }

    $decodeCandidate = static function (string $candidateText): array {
        $decodedLocal = json_decode($candidateText, true);
        if (is_array($decodedLocal)) {
            return $decodedLocal;
        }
        // Some providers return a JSON string that itself contains JSON.
        if (is_string($decodedLocal) && trim($decodedLocal) !== '') {
            $decodedNested = json_decode($decodedLocal, true);
            if (is_array($decodedNested)) {
                return $decodedNested;
            }
        }
        // Salvage common LLM JSON formatting errors (e.g. trailing commas).
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $candidateText);
        if (is_string($repaired) && $repaired !== $candidateText) {
            $decodedRepaired = json_decode($repaired, true);
            if (is_array($decodedRepaired)) {
                return $decodedRepaired;
            }
        }
        return [];
    };

    $candidate = $trimmed;
    if (strpos($candidate, '```') === 0) {
        $candidate = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);
    }

    $decoded = $decodeCandidate($candidate);
    if (count($decoded) > 0) {
        return $decoded;
    }

    $firstBrace = strpos($candidate, '{');
    $lastBrace = strrpos($candidate, '}');
    if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
        return [];
    }

    $slice = substr($candidate, $firstBrace, $lastBrace - $firstBrace + 1);
    if (!is_string($slice) || trim($slice) === '') {
        return [];
    }

    $decoded = $decodeCandidate($slice);
    if (count($decoded) > 0) {
        return $decoded;
    }

    if (strpos($slice, '\\"') !== false) {
        $unslashed = stripcslashes($slice);
        if (is_string($unslashed) && trim($unslashed) !== '') {
            $decoded = $decodeCandidate($unslashed);
            if (count($decoded) > 0) {
                return $decoded;
            }
        }
    }

    return [];
}

function stobeHeuristicExtractStructuredFields(string $rawResponse): array {
    $text = trim($rawResponse);
    if ($text === '') {
        return [];
    }

    // Strip leading markdown fence marker when present.
    if (strpos($text, '```') === 0) {
        $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
        $text = trim($text);
    }

    $extractQuoted = static function (string $key) use ($text): string {
        $quotedKey = preg_quote($key, '/');
        $patterns = [
            '/"' . $quotedKey . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/"' . $quotedKey . '"\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/\'' . $quotedKey . '\'\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return trim(stripcslashes(strval($m[1])));
            }
        }
        return '';
    };

    $extractMaybeUnclosedMessage = static function (string $key) use ($text): string {
        $quotedKey = preg_quote($key, '/');
        $closedPatterns = [
            '/"' . $quotedKey . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/"' . $quotedKey . '"\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/\'' . $quotedKey . '\'\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
        ];
        foreach ($closedPatterns as $closedPattern) {
            if (preg_match($closedPattern, $text, $m) === 1) {
                return trim(stripcslashes(strval($m[1])));
            }
        }

        // Handle truncated JSON where the final quote/object close is missing.
        $openPatterns = [
            '/"' . $quotedKey . '"\s*:\s*"([\s\S]*)$/is',
            '/"' . $quotedKey . '"\s*:\s*\'([\s\S]*)$/is',
            '/\'' . $quotedKey . '\'\s*:\s*"([\s\S]*)$/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'([\s\S]*)$/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"([\s\S]*)$/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'([\s\S]*)$/is',
        ];
        foreach ($openPatterns as $openPattern) {
            if (preg_match($openPattern, $text, $m) === 1) {
                $value = strval($m[1]);
                $value = preg_replace('/\s*```$/', '', $value) ?? $value;
                $value = rtrim($value, " \t\r\n,}");
                return trim(stripcslashes($value));
            }
        }
        return '';
    };

    $message = $extractMaybeUnclosedMessage('message');
    if ($message === '') {
        $message = $extractMaybeUnclosedMessage('text');
    }
    if ($message === '') {
        $message = $extractMaybeUnclosedMessage('content');
    }

    $item = $extractQuoted('item');
    if ($item === '') {
        if (preg_match('/"item"\s*:\s*(null|NULL)\b/', $text) === 1) {
            $item = '';
        }
    }

    $fields = [
        'message' => $message,
        'action' => $extractQuoted('action'),
        'target' => $extractQuoted('target'),
        'item' => $item,
        'listener' => $extractQuoted('listener'),
        'mood' => $extractQuoted('mood'),
    ];

    foreach ($fields as $value) {
        if (trim(strval($value)) !== '') {
            return $fields;
        }
    }
    return [];
}

function stobeBuildActionTagFromStructuredPayload(
    string $action,
    string $target,
    string $item,
    string $message,
    string $listener = ''
): string {
    $actionUpper = strtoupper(trim($action));
    $target = trim($target);
    $item = trim($item);

    if ($actionUpper === '' || $actionUpper === 'TALK') {
        return '';
    }

    $synonyms = [
        'STOPFOLLOW' => 'STOP_FOLLOW',
        'UNFOLLOW' => 'STOP_FOLLOW',
        'STOPCARRYING' => 'STOP_CARRYING',
        'KILLSELF' => 'SUICIDE',
        'SELFDESTRUCT' => 'SUICIDE',
        'JOINPARTY' => 'JOIN_PARTY',
        'JOIN_TO_RANGROOSQUAD' => 'JOIN_PARTY',
        'GIVECATS' => 'GIVE_CATS',
        'TAKECATS' => 'TAKE_CATS',
        'TAKEITEM' => 'TAKE_ITEM',
        'GIVEITEM' => 'GIVE_ITEM',
        'DROPITEM' => 'DROP_ITEM',
        'FACTIONRELATIONS' => 'FACTION_RELATIONS',
        'SETBLOCK' => 'SET_BLOCK',
        'SETHOLD' => 'SET_HOLD',
        'SETPASSIVE' => 'SET_PASSIVE',
        'SETJOBS' => 'SET_JOBS',
        'SETRANGED' => 'SET_RANGED',
        'SETTAUNT' => 'SET_TAUNT',
        'SETSNEAK' => 'SET_SNEAK',
        'SETRESOURCE' => 'SET_RESOURCE',
        'SETMEDIC' => 'SET_MEDIC',
        'REMOVELIMB' => 'REMOVE_LIMB',
        'KILLTARGET' => 'KILL',
        'EXECUTE' => 'KILL',
        'MURDER' => 'KILL',
        'USEOBJECT' => 'USE_OBJECT',
        'USE-OBJECT' => 'USE_OBJECT',
        'USEDRUGS' => 'USE_DRUGS',
        'USE-DRUGS' => 'USE_DRUGS',
        'DRINK' => 'DRINK_ITEM',
        'DRINKITEM' => 'DRINK_ITEM',
        'DRINK_ITEM' => 'DRINK_ITEM',
        'DRINK-ITEM' => 'DRINK_ITEM',
        'TRAVELLOCATION' => 'TRAVEL_LOCATION',
        'ROLEPLAYACTION' => 'ROLEPLAY_ACTION',
        'ROLEPLAY-ACTION' => 'ROLEPLAY_ACTION',
        'NOTIFY' => 'ROLEPLAY_ACTION',
        'WAITHERE' => 'IDLE',
        'FIGHT' => 'ATTACK',
        'HUNT' => 'ATTACK',
        'TAKEMONEYFROMRANGROO' => 'TAKE_CATS',
        'GIVEGOLDTO' => 'GIVE_CATS',
        'MOVE_TO' => 'TASK',
        'MOVETO' => 'TASK',
    ];
    if (isset($synonyms[$actionUpper])) {
        $actionUpper = $synonyms[$actionUpper];
    }
    $actionUpper = stobeCanonicalizeActionCommand($actionUpper);

    if ($actionUpper === 'ATTACK') {
        return 'ATTACK@' . $target;
    }
    if ($actionUpper === 'FOLLOW') {
        $followTarget = trim($target !== '' ? $target : $item);
        if ($followTarget === '') {
            // Default to player-follow when model selects FOLLOW but omits target.
            return 'FOLLOW@player';
        }
        return 'FOLLOW@' . $followTarget;
    }
    if ($actionUpper === 'TRAVEL_LOCATION') {
        $destination = trim($target !== '' ? $target : ($item !== '' ? $item : $message));
        if ($destination === '') {
            return '';
        }
        return 'TRAVEL_LOCATION@' . $destination;
    }
    if ($actionUpper === 'USE_OBJECT') {
        $objectToken = trim($target !== '' ? $target : $item);
        if ($objectToken === '') {
            return 'USE_OBJECT@';
        }
        return 'USE_OBJECT@' . $objectToken;
    }
    if ($actionUpper === 'USE_DRUGS') {
        $drugName = trim($item !== '' ? $item : ($target !== '' ? $target : $message));
        if ($drugName === '') {
            return '';
        }
        return 'USE_DRUGS@' . $drugName;
    }
    if ($actionUpper === 'DRINK_ITEM') {
        $drinkName = trim($item !== '' ? $item : ($target !== '' ? $target : $message));
        if ($drinkName === '') {
            return '';
        }
        return 'DRINK_ITEM@' . $drinkName;
    }
    if (in_array($actionUpper, ['STOP_FOLLOW', 'STOP_CARRYING', 'JOIN_PARTY', 'LEAVE', 'IDLE', 'SUICIDE'], true)) {
        return $actionUpper . '@';
    }
    if (in_array($actionUpper, ['SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED', 'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC'], true)) {
        $toggleRaw = strtoupper(trim($item !== '' ? $item : $target));
        if ($toggleRaw === '') {
            return '';
        }
        if (in_array($toggleRaw, ['TRUE', '1', 'YES', 'ON', 'ENABLE', 'ENABLED'], true)) {
            return $actionUpper . '@ON';
        }
        if (in_array($toggleRaw, ['FALSE', '0', 'NO', 'OFF', 'DISABLE', 'DISABLED'], true)) {
            return $actionUpper . '@OFF';
        }
        return '';
    }
    if (in_array($actionUpper, ['GIVE_CATS', 'TAKE_CATS'], true)) {
        $amountSource = $item !== '' ? $item : $target;
        if (preg_match('/-?\d+/', $amountSource, $m) !== 1) {
            return '';
        }
        $amount = abs(intval($m[0]));
        if ($amount <= 0) {
            return '';
        }
        return $actionUpper . '@' . strval($amount);
    }
    if (in_array($actionUpper, ['TAKE_ITEM', 'GIVE_ITEM', 'DROP_ITEM'], true)) {
        $itemName = trim($item !== '' ? $item : $target);
        if ($itemName === '') {
            return '';
        }
        return $actionUpper . '@' . $itemName;
    }
    if ($actionUpper === 'REMOVE_LIMB') {
        $targetName = trim($target);
        $limbToken = trim($item);
        if ($limbToken === '' && strpos($targetName, '@') !== false) {
            $parts = explode('@', $targetName, 2);
            if (count($parts) === 2) {
                $targetName = trim(strval($parts[0]));
                $limbToken = trim(strval($parts[1]));
            }
        }
        if ($targetName === '' || $limbToken === '') {
            return '';
        }
        return 'REMOVE_LIMB@' . $targetName . '@' . $limbToken;
    }
    if ($actionUpper === 'KILL') {
        $targetName = trim($target !== '' ? $target : $item);
        if ($targetName === '') {
            return '';
        }
        return 'KILL@' . $targetName;
    }
    if ($actionUpper === 'ROLEPLAY_ACTION') {
        $notice = trim($target !== '' ? $target : $message);
        if ($notice === '') {
            return '';
        }
        return 'ROLEPLAY_ACTION@' . $notice;
    }
    if ($actionUpper === 'FACTION_RELATIONS') {
        $targetName = trim($target);
        $deltaSource = trim($item);

        if ($targetName !== '' && strpos($targetName, '@') !== false && $deltaSource === '') {
            $parts = explode('@', $targetName, 2);
            if (count($parts) === 2) {
                $targetName = trim(strval($parts[0]));
                $deltaSource = trim(strval($parts[1]));
            }
        }
        if ($targetName === '') {
            return '';
        }
        if ($deltaSource === '') {
            $deltaSource = trim($message);
        }

        $delta = 0;
        if (preg_match('/-?\d+/', $deltaSource, $m) === 1) {
            $delta = intval($m[0]);
        } else {
            $low = strtolower($deltaSource);
            if (preg_match('/\b(hostile|enemy|hate|hateful|worse|decrease|lower|negative)\b/', $low) === 1) {
                $delta = -100;
            } elseif (preg_match('/\b(friendly|friend|allied|ally|improve|increase|raise|positive)\b/', $low) === 1) {
                $delta = 100;
            }
        }
        if ($delta === 0) {
            return '';
        }
        $delta = $delta < 0 ? -100 : 100;
        return 'FACTION_RELATIONS@' . $targetName . '@' . strval($delta);
    }
    if ($actionUpper === 'TASK') {
        $task = trim($target !== '' ? $target : $item);
        if ($task === '') {
            return '';
        }
        return 'TASK@' . $task;
    }

    return '';
}

function stobeParseStructuredDialogueResponse(string $rawResponse, string $eventType = 'chat'): array {
    $fallbackMessage = trim($rawResponse);
    $fallbackActionTag = '';
    $decoded = stobeDecodeStructuredDialoguePayload($rawResponse);
    if (is_array($decoded) && count($decoded) > 0) {
        // Normalize wrapper payloads like {"response":{...}} or {"data":"{...}"}.
        $unwrapKeys = ['response', 'data', 'output', 'result', 'payload'];
        foreach ($unwrapKeys as $unwrapKey) {
            if (!array_key_exists($unwrapKey, $decoded)) {
                continue;
            }
            $nestedRaw = $decoded[$unwrapKey];
            if (is_array($nestedRaw) && count($nestedRaw) > 0) {
                $decoded = $nestedRaw;
                break;
            }
            if (is_string($nestedRaw) && trim($nestedRaw) !== '') {
                $nestedDecoded = stobeDecodeStructuredDialoguePayload($nestedRaw);
                if (count($nestedDecoded) > 0) {
                    $decoded = $nestedDecoded;
                    break;
                }
            }
        }
    }
    if (!is_array($decoded) || count($decoded) === 0) {
        $heuristic = stobeHeuristicExtractStructuredFields($rawResponse);
        if (count($heuristic) > 0) {
            $heuristicMessage = trim(strval($heuristic['message'] ?? ''));
            if ($heuristicMessage !== '') {
                $fallbackMessage = $heuristicMessage;
            }
            $heuristicAction = trim(strval($heuristic['action'] ?? ''));
            $heuristicTarget = trim(strval($heuristic['target'] ?? ''));
            $heuristicItem = trim(strval($heuristic['item'] ?? ''));
            $heuristicListener = trim(strval($heuristic['listener'] ?? ''));
            $fallbackActionTag = stobeBuildActionTagFromStructuredPayload(
                $heuristicAction,
                $heuristicTarget,
                $heuristicItem,
                $fallbackMessage,
                $heuristicListener
            );
            if ($fallbackActionTag !== '') {
                $fallbackActionTag = normalizeActionTagToken(
                    $fallbackActionTag,
                    getActionRuntimeConfig($eventType)
                );
            }
            return [
                'is_structured' => true,
                'message' => trim($fallbackMessage),
                'action_tag' => $fallbackActionTag,
                'listener' => trim(strval($heuristic['listener'] ?? '')),
                'mood' => trim(strval($heuristic['mood'] ?? '')),
            ];
        }

        return [
            'is_structured' => false,
            'message' => $fallbackMessage,
            'action_tag' => $fallbackActionTag,
            'listener' => '',
            'mood' => '',
        ];
    }

    $message = trim(strval($decoded['message'] ?? ''));
    if ($message === '') {
        $message = trim(strval($decoded['text'] ?? ''));
    }
    if ($message === '') {
        $message = trim(strval($decoded['content'] ?? ''));
    }
    if ($message === '') {
        $message = $fallbackMessage;
    }
    $action = trim(strval($decoded['action'] ?? 'Talk'));
    $target = trim(strval($decoded['target'] ?? ''));
    $item = trim(strval($decoded['item'] ?? ''));
    $listener = trim(strval($decoded['listener'] ?? ''));
    $mood = trim(strval($decoded['mood'] ?? ''));

    $rawActionTag = stobeBuildActionTagFromStructuredPayload(
        $action,
        $target,
        $item,
        $message,
        $listener
    );
    $actionTag = '';
    if ($rawActionTag !== '') {
        $actionTag = normalizeActionTagToken($rawActionTag, getActionRuntimeConfig($eventType));
    }

    return [
        'is_structured' => true,
        'message' => trim($message),
        'action_tag' => $actionTag,
        'listener' => $listener,
        'mood' => $mood,
    ];
}

function queryWorldKnowledgeForNpc(
    string $npcName,
    string $playerMessage,
    int $limit = 3,
    array|false $npcData = false,
    string $eventType = 'chat'
): array {
    $queryStartedAt = microtime(true);

    $normalizedEventType = strtolower(trim($eventType));
    if ($normalizedEventType === '') {
        $normalizedEventType = 'chat';
    }

    if (!stobeWorldKnowledgeEventAllowed($normalizedEventType)) {
        return [];
    }

    if (!stobeWorldKnowledgeRetrieverEnabled()) {
        return [];
    }

    $message = trim($playerMessage);
    if ($message === '') {
        return [];
    }

    if (!is_array($npcData)) {
        $npcData = getNpcData($npcName);
    }
    if (!is_array($npcData)) {
        return [];
    }

    $knowledgeTags = parseNpcKnowledgeTags($npcData, $npcName);
    $isKnowAll = in_array('knowall', $knowledgeTags, true);
    $knowledgeSummary = implode(',', $knowledgeTags);
    $npcKey = stobeWorldKnowledgeBuildNpcKey($npcName, $npcData);
    $currentTopicBefore = stobeWorldKnowledgeGetCurrentTopic($npcKey);

    $topicCount = max(1, min(5, getSettingInt('WORLD_KNOWLEDGE_AMOUNT', 2)));
    $keywordWindow = max(6, min(60, getSettingInt('WORLD_KNOWLEDGE_CONTEXT_HISTORY', 16)));
    $keywordLimit = max(3, min(24, getSettingInt('WORLD_KNOWLEDGE_CONTEXT_KEYWORDS', 8)));
    $minRank = max(0.0, min(100.0, getSettingFloat('WORLD_KNOWLEDGE_MIN_RANK', 3.30)));

    $locationContext = stobeWorldKnowledgeResolveLocationContext($npcName);
    $contextKeywords = stobeWorldKnowledgeExtractContextKeywords($npcName, $keywordWindow, $keywordLimit);
    $topics = stobeWorldKnowledgeExtractTopics($message, $contextKeywords, $topicCount);
    $primaryTopic = count($topics) > 0 ? strval($topics[0]) : $message;

    $db = $GLOBALS["db"];
    $vectorExpr = "COALESCE(native_vector, to_tsvector('english', COALESCE(topic, '') || ' ' || COALESCE(topic_desc, '') || ' ' || COALESCE(topic_desc_basic, '')))";
    $aliasExpr = "to_tsvector('simple', regexp_replace(replace(lower(COALESCE(topic, '')), '_', ' '), ',', ' ', 'g'))";
    $scoreParts = ['0'];
    $whereParts = [];
    $params = [];
    $notes = [];

    $addSignal = static function (string $signalText, float $weight, string $label) use (&$params, &$scoreParts, &$whereParts, $vectorExpr, &$notes): void {
        $text = trim($signalText);
        if ($text === '') {
            return;
        }
        if (!stobeWorldKnowledgeHasQueryableTerms($text)) {
            return;
        }

        $params[] = $text;
        $paramIdx = count($params);
        $scoreParts[] = "(CASE WHEN {$vectorExpr} @@ websearch_to_tsquery('english', $" . $paramIdx . ") THEN " . strval($weight * 3.0) . " ELSE 0 END)";
        $scoreParts[] = "(ts_rank_cd({$vectorExpr}, websearch_to_tsquery('english', $" . $paramIdx . ")) * " . strval($weight) . ")";
        $whereParts[] = "{$vectorExpr} @@ websearch_to_tsquery('english', $" . $paramIdx . ")";
        $notes[] = $label;
    };

    $addSignal($primaryTopic, 10.0, 'topic');
    $addSignal($currentTopicBefore, 5.0, 'continuity');
    $addSignal($locationContext, 2.0, 'location');
    $addSignal($contextKeywords, 1.0, 'context');
    $addSignal($message, 1.0, 'message');

    if (stobeWorldKnowledgeHasQueryableTerms($primaryTopic)) {
        $params[] = $primaryTopic;
        $aliasIdx = count($params);
        $scoreParts[] = "(CASE WHEN {$aliasExpr} @@ websearch_to_tsquery('simple', $" . $aliasIdx . ") THEN 20 ELSE 0 END)";
        $whereParts[] = "{$aliasExpr} @@ websearch_to_tsquery('simple', $" . $aliasIdx . ")";
        $notes[] = 'alias';
    }

    if (count($whereParts) === 0) {
        stobeWorldKnowledgeWriteAuditRow([
            'npc_name' => $npcName,
            'npc_key' => $npcKey,
            'event_type' => $normalizedEventType,
            'input_text' => $message,
            'extracted_topics' => $topics,
            'selected_topic' => '',
            'selected_entry_id' => 0,
            'selected_mode' => 'none',
            'selected_rank' => 0.0,
            'location_context' => $locationContext,
            'context_keywords' => $contextKeywords,
            'current_topic_before' => $currentTopicBefore,
            'current_topic_after' => $currentTopicBefore,
            'knowledge_tags' => $knowledgeSummary,
            'notes' => 'No queryable world_knowledge signals',
            'elapsed_seconds' => max(0.0, microtime(true) - $queryStartedAt),
        ]);
        return [];
    }

    $safeLimit = max(1, min(6, $limit));
    $candidateLimit = max(8, min(40, $safeLimit * 6));
    $query = "SELECT
                id,
                topic,
                topic_desc,
                COALESCE(topic_desc_basic, '') AS topic_desc_basic,
                COALESCE(knowledge_class, '') AS knowledge_class,
                COALESCE(knowledge_class_basic, '') AS knowledge_class_basic,
                COALESCE(tags, '') AS tags,
                (" . implode(' + ', $scoreParts) . ") AS combined_rank
              FROM world_knowledge
              WHERE (" . implode(' OR ', $whereParts) . ")
              ORDER BY combined_rank DESC, id DESC
              LIMIT " . intval($candidateLimit);

    $rows = $db->fetchAll($query, $params);
    $hints = [];
    $seenHints = [];
    $selectedTopic = '';
    $selectedMode = '';
    $selectedRank = 0.0;
    $selectedEntryId = 0;

    foreach ($rows as $row) {
        $rank = floatval($row['combined_rank'] ?? 0.0);
        if ($rank < $minRank) {
            continue;
        }

        $payload = stobeWorldKnowledgeSelectKnowledgePayload($row, $knowledgeTags, $isKnowAll);
        if (!boolval($payload['allowed'] ?? false)) {
            continue;
        }

        $topic = trim(strval($payload['topic'] ?? ''));
        $desc = truncatePromptValue(strval($payload['desc'] ?? ''), 260);
        if ($topic === '' || $desc === '') {
            continue;
        }

        $line = $topic . ': ' . $desc;
        $line = trim($line);

        $dedupeKey = strtolower($topic . '|' . $desc);
        if ($line === '' || isset($seenHints[$dedupeKey])) {
            continue;
        }
        $seenHints[$dedupeKey] = true;
        $hints[] = $line;

        if ($selectedTopic === '') {
            $selectedTopic = $topic;
            $selectedMode = strval($payload['mode'] ?? '');
            $selectedRank = $rank;
            $selectedEntryId = intval($row['id'] ?? 0);
        }

        if (count($hints) >= $safeLimit) {
            break;
        }
    }

    $currentTopicAfter = $currentTopicBefore;
    if ($selectedTopic !== '') {
        $safeGamets = isset($GLOBALS['gameRequest']) && is_array($GLOBALS['gameRequest'])
            ? intval($GLOBALS['gameRequest'][2] ?? 0)
            : 0;
        stobeWorldKnowledgeSetCurrentTopic($npcKey, $npcName, $selectedTopic, $normalizedEventType, $safeGamets);
        $currentTopicAfter = $selectedTopic;
    }

    stobeWorldKnowledgeWriteAuditRow([
        'npc_name' => $npcName,
        'npc_key' => $npcKey,
        'event_type' => $normalizedEventType,
        'input_text' => $message,
        'extracted_topics' => $topics,
        'selected_topic' => $selectedTopic,
        'selected_entry_id' => $selectedEntryId,
        'selected_mode' => $selectedMode,
        'selected_rank' => $selectedRank,
        'location_context' => $locationContext,
        'context_keywords' => $contextKeywords,
        'current_topic_before' => $currentTopicBefore,
        'current_topic_after' => $currentTopicAfter,
        'knowledge_tags' => $knowledgeSummary,
        'notes' => implode(',', $notes),
        'elapsed_seconds' => max(0.0, microtime(true) - $queryStartedAt),
    ]);

    return $hints;
}

function getItemDescriptionFromCombinedTable(string $itemName, string $itemId = ''): string {
    static $cacheById = [];
    static $cacheByName = [];

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $normalizedId = trim($itemId);
    if ($normalizedId !== '') {
        $idKey = strtolower($normalizedId);
        if (array_key_exists($idKey, $cacheById)) {
            return strval($cacheById[$idKey]);
        }
        try {
            $row = $db->fetchOne(
                "SELECT description
                 FROM combined_descriptions
                 WHERE LOWER(stringid) = LOWER($1)
                 LIMIT 1",
                [$normalizedId]
            );
            $description = trim(strval($row['description'] ?? ''));
            $cacheById[$idKey] = $description;
            if ($description !== '') {
                return $description;
            }
        } catch (Throwable $exception) {
            $cacheById[$idKey] = '';
            return '';
        }
    }

    $normalizedName = trim($itemName);
    if ($normalizedName === '') {
        return '';
    }

    $nameKey = strtolower($normalizedName);
    if (array_key_exists($nameKey, $cacheByName)) {
        return strval($cacheByName[$nameKey]);
    }

    try {
        $row = $db->fetchOne(
            "SELECT description
             FROM combined_descriptions
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$normalizedName]
        );
        $description = trim(strval($row['description'] ?? ''));
        $cacheByName[$nameKey] = $description;
        return $description;
    } catch (Throwable $exception) {
        $cacheByName[$nameKey] = '';
        return '';
    }
}

function stobeResolveArmorQualityLabelFromLevel(int $level): string {
    if ($level >= 95) {
        return 'Masterwork';
    }
    if ($level >= 80) {
        return 'Specialist';
    }
    if ($level >= 60) {
        return 'High';
    }
    if ($level >= 40) {
        return 'Standard';
    }
    if ($level >= 20) {
        return 'Shoddy';
    }
    return 'Prototype';
}

function stobeResolveItemQualityFromEntry(array $entry): array {
    $label = trim(strval(
        $entry['quality']
            ?? ($entry['quality_label']
            ?? ($entry['quality_name'] ?? ''))
    ));
    if ($label !== '') {
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = trim($label);
    }

    $level = -1;
    foreach (['quality_level', 'qualityLevel', 'level', 'gear_level'] as $field) {
        if (!array_key_exists($field, $entry)) {
            continue;
        }
        $rawLevel = $entry[$field];
        if (is_int($rawLevel) || is_float($rawLevel)) {
            $level = intval(round(floatval($rawLevel)));
            break;
        }
        if (is_string($rawLevel)) {
            $text = trim($rawLevel);
            if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                continue;
            }
            $level = intval(round(floatval($text)));
            break;
        }
    }
    if ($level >= 0) {
        if ($level > 100) {
            $level = 100;
        }
        if ($label === '') {
            $label = stobeResolveArmorQualityLabelFromLevel($level);
        }
    } else {
        $level = -1;
    }

    return [
        'label' => $label,
        'level' => $level,
    ];
}

function stobeResolveWeaponModelFromEntry(array $entry): string {
    $model = trim(strval(
        $entry['weapon_model']
            ?? ($entry['weaponModel']
            ?? ($entry['model'] ?? ''))
    ));
    if ($model === '') {
        return '';
    }

    $model = preg_replace('/\s+/u', ' ', $model) ?? $model;
    $model = trim($model);
    if ($model === '') {
        return '';
    }
    if (preg_match('/^\[(.*)\]$/u', $model, $matches) === 1) {
        $model = trim(strval($matches[1] ?? ''));
    }
    if ($model === '') {
        return '';
    }
    return $model;
}

function stobeFormatInventoryItemNameWithQuality(string $itemName, array $entry): string {
    $name = trim($itemName);
    if ($name === '') {
        return '';
    }
    $tags = [];

    $quality = stobeResolveItemQualityFromEntry($entry);
    $qualityLabel = trim(strval($quality['label'] ?? ''));
    if ($qualityLabel !== '') {
        $tags[] = $qualityLabel;
    }

    $weaponModel = stobeResolveWeaponModelFromEntry($entry);
    if ($weaponModel !== '') {
        $alreadyPresent = false;
        foreach ($tags as $tag) {
            if (strcasecmp($tag, $weaponModel) === 0) {
                $alreadyPresent = true;
                break;
            }
        }
        if (!$alreadyPresent) {
            $tags[] = $weaponModel;
        }
    }

    if (count($tags) === 0) {
        return $name;
    }

    $displayName = $name;
    foreach ($tags as $tag) {
        if ($tag === '') {
            continue;
        }
        if (stripos($displayName, '[' . $tag . ']') !== false) {
            continue;
        }
        $displayName .= ' [' . $tag . ']';
    }
    return $displayName;
}

function buildInventoryContextFromMetadata(array $metadata): array {
    $rawEntries = $metadata['inventory_items'] ?? [];
    if (is_string($rawEntries) && trim($rawEntries) !== '') {
        $decoded = json_decode($rawEntries, true);
        if (is_array($decoded)) {
            $rawEntries = $decoded;
        }
    }

    $rawTraderEntries = $metadata['trader_inventory_items'] ?? [];
    if (is_string($rawTraderEntries) && trim($rawTraderEntries) !== '') {
        $decodedTrader = json_decode($rawTraderEntries, true);
        if (is_array($decodedTrader)) {
            $rawTraderEntries = $decodedTrader;
        }
    }

    if (!is_array($rawEntries)) {
        $rawEntries = [];
    }
    if (!is_array($rawTraderEntries)) {
        $rawTraderEntries = [];
    }
    if (count($rawEntries) === 0 && count($rawTraderEntries) === 0) {
        return [
            'equipment' => '',
            'inventory' => '',
            'trader_inventory' => '',
            'merchant_inventory' => '',
            'has_items' => false,
            'has_trader_items' => false,
        ];
    }

    $parseFlag = static function (mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return false;
    };

    $equipmentCounts = [];
    $inventoryCounts = [];
    $traderInventoryCounts = [];
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $itemCount = intval($entry['count'] ?? 1);
        if ($itemCount <= 0) {
            $itemCount = 1;
        }

        $itemId = trim(strval(
            $entry['item_id']
                ?? ($entry['itemId']
                ?? ($entry['string_id']
                ?? ($entry['stringid']
                ?? ($entry['sid']
                ?? ($entry['baseid']
                ?? ($entry['id'] ?? ''))))))
        ));
        $itemDescription = trim(strval($entry['description'] ?? ''));
        if ($itemDescription !== '') {
            $itemDescription = preg_replace('/\s+/u', ' ', $itemDescription) ?? $itemDescription;
            $itemDescription = trim($itemDescription);
        }
        $itemQuality = stobeResolveItemQualityFromEntry($entry);
        $itemQualityLabel = trim(strval($itemQuality['label'] ?? ''));
        $itemQualityLevel = intval($itemQuality['level'] ?? -1);
        $itemWeaponModel = stobeResolveWeaponModelFromEntry($entry);
        $itemModel = '';
        $itemManufacturer = '';
        $itemManufacturerId = '';
        $itemValueEach = intval($entry['value_each'] ?? ($entry['value_single'] ?? 0));
        if ($itemValueEach < 0) {
            $itemValueEach = 0;
        }
        $isEquipped = $parseFlag($entry['equipped'] ?? ($entry['is_equipped'] ?? false));

        $qualityKey = $itemQualityLevel >= 0
            ? ('q:' . strval($itemQualityLevel))
            : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
        $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
        $entryKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName)) . '|' . $qualityKey . '|' . $weaponModelKey;
        if ($isEquipped) {
            if (!array_key_exists($entryKey, $equipmentCounts)) {
                $equipmentCounts[$entryKey] = [
                    'name' => $itemName,
                    'item_id' => $itemId,
                    'description' => $itemDescription,
                    'quality' => $itemQualityLabel,
                    'quality_level' => $itemQualityLevel,
                    'weapon_model' => $itemWeaponModel,
                    'value_each' => $itemValueEach,
                    'count' => 0,
                ];
            }
            $equipmentCounts[$entryKey]['count'] += $itemCount;
            if (trim(strval($equipmentCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $equipmentCounts[$entryKey]['item_id'] = $itemId;
            }
            if (trim(strval($equipmentCounts[$entryKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                $equipmentCounts[$entryKey]['description'] = $itemDescription;
            }
            if (trim(strval($equipmentCounts[$entryKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
                $equipmentCounts[$entryKey]['quality'] = $itemQualityLabel;
            }
            if (intval($equipmentCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
                $equipmentCounts[$entryKey]['quality_level'] = $itemQualityLevel;
            }
            if (trim(strval($equipmentCounts[$entryKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
                $equipmentCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
            }
            if (intval($equipmentCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
                $equipmentCounts[$entryKey]['value_each'] = $itemValueEach;
            }
            continue;
        }

        if (!array_key_exists($entryKey, $inventoryCounts)) {
            $inventoryCounts[$entryKey] = [
                'name' => $itemName,
                'item_id' => $itemId,
                'description' => $itemDescription,
                'quality' => $itemQualityLabel,
                'quality_level' => $itemQualityLevel,
                'weapon_model' => $itemWeaponModel,
                'value_each' => $itemValueEach,
                'count' => 0,
            ];
        }
        $inventoryCounts[$entryKey]['count'] += $itemCount;
        if (trim(strval($inventoryCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
            $inventoryCounts[$entryKey]['item_id'] = $itemId;
        }
        if (trim(strval($inventoryCounts[$entryKey]['description'] ?? '')) === '' && $itemDescription !== '') {
            $inventoryCounts[$entryKey]['description'] = $itemDescription;
        }
        if (trim(strval($inventoryCounts[$entryKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
            $inventoryCounts[$entryKey]['quality'] = $itemQualityLabel;
        }
        if (intval($inventoryCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
            $inventoryCounts[$entryKey]['quality_level'] = $itemQualityLevel;
        }
        if (trim(strval($inventoryCounts[$entryKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
            $inventoryCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
        }
        if (intval($inventoryCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
            $inventoryCounts[$entryKey]['value_each'] = $itemValueEach;
        }
    }

    foreach ($rawTraderEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }
        $itemCount = intval($entry['count'] ?? ($entry['quantity'] ?? 1));
        if ($itemCount <= 0) {
            $itemCount = 1;
        }
        $itemId = trim(strval(
            $entry['item_id']
                ?? ($entry['itemId']
                ?? ($entry['string_id']
                ?? ($entry['stringid']
                ?? ($entry['sid']
                ?? ($entry['baseid']
                ?? ($entry['id'] ?? ''))))))
        ));
        $itemDescription = trim(strval($entry['description'] ?? ''));
        if ($itemDescription !== '') {
            $itemDescription = preg_replace('/\s+/u', ' ', $itemDescription) ?? $itemDescription;
            $itemDescription = trim($itemDescription);
        }
        $itemQuality = stobeResolveItemQualityFromEntry($entry);
        $itemQualityLabel = trim(strval($itemQuality['label'] ?? ''));
        $itemQualityLevel = intval($itemQuality['level'] ?? -1);
        $itemWeaponModel = stobeResolveWeaponModelFromEntry($entry);
        $itemValueEach = intval($entry['value_each'] ?? ($entry['value_single'] ?? 0));
        if ($itemValueEach < 0) {
            $itemValueEach = 0;
        }

        $qualityKey = $itemQualityLevel >= 0
            ? ('q:' . strval($itemQualityLevel))
            : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
        $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
        $entryKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName)) . '|' . $qualityKey . '|' . $weaponModelKey;
        if (!array_key_exists($entryKey, $traderInventoryCounts)) {
            $traderInventoryCounts[$entryKey] = [
                'name' => $itemName,
                'item_id' => $itemId,
                'description' => $itemDescription,
                'quality' => $itemQualityLabel,
                'quality_level' => $itemQualityLevel,
                'weapon_model' => $itemWeaponModel,
                'value_each' => $itemValueEach,
                'count' => 0,
            ];
        }
        $traderInventoryCounts[$entryKey]['count'] += $itemCount;
        if (trim(strval($traderInventoryCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
            $traderInventoryCounts[$entryKey]['item_id'] = $itemId;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['description'] ?? '')) === '' &&
            $itemDescription !== ''
        ) {
            $traderInventoryCounts[$entryKey]['description'] = $itemDescription;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['quality'] ?? '')) === '' &&
            $itemQualityLabel !== ''
        ) {
            $traderInventoryCounts[$entryKey]['quality'] = $itemQualityLabel;
        }
        if (intval($traderInventoryCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
            $traderInventoryCounts[$entryKey]['quality_level'] = $itemQualityLevel;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['weapon_model'] ?? '')) === '' &&
            $itemWeaponModel !== ''
        ) {
            $traderInventoryCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
        }
        if (intval($traderInventoryCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
            $traderInventoryCounts[$entryKey]['value_each'] = $itemValueEach;
        }
    }

    $describedTokens = [];
    $resolveDescription = static function (array $entry, bool $isInventory) use (&$describedTokens): string {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            return '';
        }
        $entryId = trim(strval($entry['item_id'] ?? ''));
        $entryCount = intval($entry['count'] ?? 1);
        if ($isInventory && $entryCount > 5) {
            return '';
        }

        $dedupeToken = strtolower($entryId !== '' ? ('id:' . $entryId) : ('name:' . $entryName));
        if (isset($describedTokens[$dedupeToken])) {
            return '';
        }

        $inlineDescription = trim(strval($entry['description'] ?? ''));
        if ($inlineDescription !== '') {
            $inlineDescription = preg_replace('/\s+/u', ' ', $inlineDescription) ?? $inlineDescription;
            $inlineDescription = trim($inlineDescription);
            if ($inlineDescription !== '') {
                $describedTokens[$dedupeToken] = true;
                return $inlineDescription;
            }
        }

        $description = getItemDescriptionFromCombinedTable($entryName, $entryId);
        if ($description !== '') {
            $describedTokens[$dedupeToken] = true;
        }
        return $description;
    };

    $equipmentParts = [];
    foreach ($equipmentCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        $description = $resolveDescription($entry, false);
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $equipmentParts[] = $entryText;
        if (count($equipmentParts) >= 30) {
            break;
        }
    }

    $inventoryParts = [];
    foreach ($inventoryCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $description = $resolveDescription($entry, true);
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $inventoryParts[] = $entryText;
        if (count($inventoryParts) >= 60) {
            break;
        }
    }

    $traderInventoryParts = [];
    foreach ($traderInventoryCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $description = $resolveDescription($entry, true);
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $traderInventoryParts[] = $entryText;
        if (count($traderInventoryParts) >= 80) {
            break;
        }
    }

    if (count($inventoryParts) === 0 && count($traderInventoryParts) > 0) {
        $inventoryParts = $traderInventoryParts;
    }

    $hasTraderItems = count($traderInventoryCounts) > 0;
    $hasItems = (count($equipmentCounts) + count($inventoryCounts) + count($traderInventoryCounts)) > 0;

    return [
        'equipment' => implode(', ', $equipmentParts),
        'inventory' => implode(', ', $inventoryParts),
        'trader_inventory' => implode(', ', $traderInventoryParts),
        'merchant_inventory' => implode(', ', $traderInventoryParts),
        'has_items' => $hasItems,
        'has_trader_items' => $hasTraderItems,
    ];
}

function stobeNormalizeItemNameKey(string $name): string {
    $normalized = trim(strtolower($name));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeLookupItemIdentityFromKnownMetadata(string $itemName): array {
    static $cache = [];

    $normalizedName = trim($itemName);
    if ($normalizedName === '') {
        return ['model' => '', 'manufacturer' => ''];
    }
    $cacheKey = strtolower($normalizedName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache[$cacheKey] = ['model' => '', 'manufacturer' => ''];
        return $cache[$cacheKey];
    }

    try {
        $row = $db->fetchOne(
            "SELECT
                COALESCE(
                    NULLIF(TRIM(item_entry->>'model'), ''),
                    NULLIF(TRIM(item_entry->>'item_id'), ''),
                    NULLIF(TRIM(item_entry->>'itemId'), ''),
                    ''
                ) AS model,
                COALESCE(
                    NULLIF(TRIM(item_entry->>'manufacturer'), ''),
                    NULLIF(TRIM(item_entry->>'manufacturer_id'), ''),
                    NULLIF(TRIM(item_entry->>'manufacturerId'), ''),
                    ''
                ) AS manufacturer
             FROM core_npc_master n
             CROSS JOIN LATERAL jsonb_array_elements(
                CASE
                    WHEN jsonb_typeof(n.metadata->'inventory_items') = 'array'
                        THEN n.metadata->'inventory_items'
                    ELSE '[]'::jsonb
                END
             ) AS item_entry
             WHERE LOWER(TRIM(item_entry->>'name')) = LOWER(TRIM($1))
               AND (
                    NULLIF(TRIM(item_entry->>'model'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'item_id'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'itemId'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturer'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturer_id'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturerId'), '') IS NOT NULL
               )
             ORDER BY n.updated_at DESC
             LIMIT 1",
            [$normalizedName]
        );

        $identity = [
            'model' => trim(strval($row['model'] ?? '')),
            'manufacturer' => trim(strval($row['manufacturer'] ?? '')),
        ];
        $cache[$cacheKey] = $identity;
        return $identity;
    } catch (Throwable $exception) {
        $cache[$cacheKey] = ['model' => '', 'manufacturer' => ''];
        return $cache[$cacheKey];
    }
}

function stobeExtractBaseItemNameFromAggregateToken(string $token): string {
    $work = trim($token);
    if ($work === '') {
        return '';
    }

    $cutPatterns = [
        '/\s+x\d+\b/iu',
        '/\s+value\s+\d+\b/iu',
        '/\s+model\s+.+$/iu',
        '/\s+manufacturer\s+.+$/iu',
    ];
    $cutAt = null;
    foreach ($cutPatterns as $pattern) {
        if (preg_match($pattern, $work, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = intval($match[0][1] ?? -1);
            if ($offset >= 0 && ($cutAt === null || $offset < $cutAt)) {
                $cutAt = $offset;
            }
        }
    }
    if ($cutAt !== null) {
        $work = substr($work, 0, $cutAt);
    }
    return trim($work);
}

function stobeEnrichAggregateItemTokenWithDescription(string $token, int $maxDescriptionLength = 120): string {
    $text = trim($token);
    if ($text === '') {
        return '';
    }

    $baseName = stobeExtractBaseItemNameFromAggregateToken($text);
    if ($baseName === '') {
        return $text;
    }

    $description = getItemDescriptionFromCombinedTable($baseName, '');
    $description = trim(strval($description));
    if ($description === '') {
        return $text;
    }
    $description = preg_replace('/\s+/u', ' ', $description) ?? $description;
    $description = trim($description);
    if ($description === '') {
        return $text;
    }
    if ($maxDescriptionLength > 0 && strlen($description) > $maxDescriptionLength) {
        $description = substr($description, 0, $maxDescriptionLength);
        $description = rtrim($description, " \t\n\r\0\x0B.,;:");
    }
    if ($description === '') {
        return $text;
    }
    if (stripos($text, $description) !== false) {
        return $text;
    }
    return $text . ' (' . $description . ')';
}

function stobeEnrichItemCsvWithDescriptions(
    string $csv,
    int $maxItems = 12,
    int $maxDescriptionLength = 120,
    int $maxTotalLength = 1800
): string {
    $raw = trim($csv);
    if ($raw === '') {
        return '';
    }

    $tokens = preg_split('/\s*,\s*/u', $raw) ?: [];
    $out = [];
    $describedByBaseName = [];
    foreach ($tokens as $token) {
        $cleanToken = trim(strval($token));
        if ($cleanToken === '') {
            continue;
        }

        $baseName = stobeExtractBaseItemNameFromAggregateToken($cleanToken);
        $baseKey = stobeNormalizeItemNameKey($baseName);

        $enrichedToken = $cleanToken;
        if ($baseKey !== '' && isset($describedByBaseName[$baseKey])) {
            // Already described once in this list; keep later duplicates concise.
            $enrichedToken = preg_replace('/\s*\([^)]{8,}\)\s*$/u', '', $cleanToken) ?? $cleanToken;
            $enrichedToken = trim(strval($enrichedToken));
            if ($enrichedToken === '') {
                $enrichedToken = $cleanToken;
            }
        } else {
            $enrichedToken = stobeEnrichAggregateItemTokenWithDescription($cleanToken, $maxDescriptionLength);
            if ($baseKey !== '' && $enrichedToken !== $cleanToken) {
                $describedByBaseName[$baseKey] = true;
            }
        }

        $out[] = $enrichedToken;
        if ($maxItems > 0 && count($out) >= $maxItems) {
            break;
        }
    }

    $result = implode(', ', $out);
    if ($maxTotalLength > 0 && strlen($result) > $maxTotalLength) {
        $result = substr($result, 0, $maxTotalLength);
        $result = rtrim($result, " \t\n\r\0\x0B,;:");
    }
    return trim($result);
}

function stobeParseCurrentMaxRatio(mixed $raw): array {
    $text = trim(strval($raw));
    if ($text === '' || preg_match('/^\s*(-?[0-9]+)\s*\/\s*(-?[0-9]+)\s*$/', $text, $m) !== 1) {
        return ['valid' => false, 'current' => 0, 'max' => 0, 'pct' => 0.0];
    }

    $current = max(0, intval($m[1]));
    $max = max(0, intval($m[2]));
    if ($max <= 0) {
        return ['valid' => false, 'current' => $current, 'max' => 0, 'pct' => 0.0];
    }
    if ($current > $max) {
        $current = $max;
    }
    return [
        'valid' => true,
        'current' => $current,
        'max' => $max,
        'pct' => ($current / $max) * 100.0,
    ];
}

function stobeDescribeBloodStatus(mixed $bloodRaw): string {
    $ratio = stobeParseCurrentMaxRatio($bloodRaw);
    if (!boolval($ratio['valid'] ?? false)) {
        return '';
    }
    $pct = floatval($ratio['pct'] ?? 0.0);
    if ($pct >= 95.0) {
        return 'In good health';
    }
    if ($pct >= 75.0) {
        return 'Lightly wounded';
    }
    if ($pct >= 50.0) {
        return 'Wounded';
    }
    if ($pct >= 25.0) {
        return 'Badly wounded';
    }
    if ($pct > 0.0) {
        return 'Near death';
    }
    return "On death's door";
}

function stobeDescribeHungerStatus(mixed $hungerRaw): string {
    $ratio = stobeParseCurrentMaxRatio($hungerRaw);
    if (!boolval($ratio['valid'] ?? false)) {
        return '';
    }
    $pct = floatval($ratio['pct'] ?? 0.0);
    if ($pct >= 90.0) {
        return 'Well fed';
    }
    if ($pct >= 70.0) {
        return 'Sated';
    }
    if ($pct >= 45.0) {
        return 'Peckish';
    }
    if ($pct >= 25.0) {
        return 'Hungry';
    }
    if ($pct >= 10.0) {
        return 'Very hungry';
    }
    if ($pct > 0.0) {
        return 'Starving';
    }
    return 'Near starvation';
}

function stobeDescribeLimbStatus(mixed $limbsRaw): string {
    $limbs = [];
    if (is_array($limbsRaw)) {
        $limbs = $limbsRaw;
    } else {
        $text = trim(strval($limbsRaw));
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $limbs = $decoded;
            }
        }
    }
    if (count($limbs) === 0) {
        return '';
    }

    $tracked = ['head', 'stomach', 'left_arm', 'right_arm', 'left_leg', 'right_leg', 'torso_extra_1'];
    $readNumeric = static function (mixed $value): ?float {
        if (is_int($value) || is_float($value)) {
            return floatval($value);
        }
        if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
            return floatval($value);
        }
        return null;
    };

    $ratios = [];
    $severed = 0;
    foreach ($tracked as $base) {
        $current = null;
        $max = null;
        if (array_key_exists($base . '_current', $limbs)) {
            $current = $readNumeric($limbs[$base . '_current']);
        }
        if ($current === null && array_key_exists($base, $limbs)) {
            $current = $readNumeric($limbs[$base]);
        }
        if (array_key_exists($base . '_max', $limbs)) {
            $max = $readNumeric($limbs[$base . '_max']);
        }
        if ($current === null) {
            continue;
        }
        if ($max === null || $max <= 0.0) {
            $max = 100.0;
        }

        $current = max(0.0, min($current, $max));
        $pct = ($current / $max) * 100.0;
        if ($pct <= 1.0) {
            $severed++;
        }
        $ratios[] = $pct;
    }

    if ($severed > 0) {
        return 'Maimed';
    }
    if (count($ratios) === 0) {
        return '';
    }

    $worst = min($ratios);
    if ($worst >= 92.0) {
        return 'Limbs intact';
    }
    if ($worst >= 70.0) {
        return 'Minor limb injuries';
    }
    if ($worst >= 40.0) {
        return 'Injured limbs';
    }
    if ($worst >= 10.0) {
        return 'Crippled';
    }
    return 'Maimed';
}

function buildWorldStateBlock(array $npcData): string {
    $fields = [];
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $parseFlag = static function (mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }
        return null;
    };
    $rawEnvironment = $extendedData['environment'] ?? ($metadata['environment'] ?? []);
    $environment = [];
    if (is_array($rawEnvironment)) {
        $environment = $rawEnvironment;
    } elseif (is_string($rawEnvironment) && trim($rawEnvironment) !== '') {
        $decodedEnvironment = json_decode($rawEnvironment, true);
        if (is_array($decodedEnvironment)) {
            $environment = $decodedEnvironment;
        }
    }
    $normalizeEnvironmentToken = static function (mixed $value): string {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }
        $text = trim(strval($value));
        if ($text === '') {
            return '';
        }
        $lower = strtolower($text);
        if (in_array($lower, ['unknown', 'none', 'n/a', 'null', 'unset'], true)) {
            return '';
        }
        return $text;
    };
    $pickEnvironmentToken = static function (array $source, array $keys) use ($normalizeEnvironmentToken): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $token = $normalizeEnvironmentToken($source[$key]);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    };
    $locationCandidates = [
        $pickEnvironmentToken($environment, ['building_name', 'indoors_name']),
        $pickEnvironmentToken($environment, ['town_name', 'town', 'city', 'settlement']),
        $pickEnvironmentToken($environment, ['zone_name', 'zone']),
        $pickEnvironmentToken($environment, ['region', 'region_name']),
    ];
    if (count(array_filter($locationCandidates, static function (string $value): bool {
        return $value !== '';
    })) === 0) {
        $locationCandidates = [
            $normalizeEnvironmentToken($npcData['town'] ?? ''),
            $normalizeEnvironmentToken($npcData['zone'] ?? ''),
            $normalizeEnvironmentToken($npcData['region'] ?? ''),
        ];
    }
    $locationParts = [];
    $seenLocationParts = [];
    foreach ($locationCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $dedupeKey = strtolower($candidate);
        if (isset($seenLocationParts[$dedupeKey])) {
            continue;
        }
        $seenLocationParts[$dedupeKey] = true;
        $locationParts[] = $candidate;
    }
    if (count($locationParts) > 0) {
        $locationText = implode(', ', $locationParts);
        $locationFlags = [];
        $indoorsFlag = $parseFlag($environment['indoors'] ?? null);
        $outdoorsFlag = $parseFlag($environment['outdoors'] ?? null);
        $inTownFlag = $parseFlag($environment['in_town'] ?? null);
        if ($indoorsFlag === true) {
            $locationFlags[] = 'indoors';
        } elseif ($outdoorsFlag === true) {
            $locationFlags[] = 'outdoors';
        }
        if ($inTownFlag === true) {
            $locationFlags[] = 'in town';
        }
        if (count($locationFlags) > 0) {
            $locationText .= ' (' . implode(', ', $locationFlags) . ')';
        }
        $fields['location'] = truncatePromptValue($locationText, 260);
    }
    $parseWeatherCode = static function (mixed $value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return intval(round($value));
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $trimmed) === 1) {
                return intval(round(floatval($trimmed)));
            }
        }
        return null;
    };
    $weatherCode = $parseWeatherCode($environment['weather'] ?? ($metadata['weather'] ?? null));
    if ($weatherCode !== null) {
        $weatherLabelMap = [
            0 => 'Clear',
            1 => 'Duststorm',
            2 => 'Acid',
            3 => 'Burning',
            4 => 'Gas',
            5 => 'Rain',
        ];
        $fields['weather'] = $weatherLabelMap[$weatherCode] ?? 'Unknown';
    } else {
        $weatherText = $pickEnvironmentToken(
            $environment,
            ['weather_name', 'weather_state', 'weather_type']
        );
        if ($weatherText !== '') {
            $normalizedWeatherText = preg_replace('/\s+/', ' ', trim($weatherText)) ?? trim($weatherText);
            $fields['weather'] = truncatePromptValue(ucfirst(strtolower($normalizedWeatherText)), 120);
        }
    }

    $bloodState = stobeDescribeBloodStatus($npcData['blood'] ?? '');
    if ($bloodState !== '') {
        $fields['blood'] = $bloodState;
    }

    $hungerState = stobeDescribeHungerStatus($npcData['hunger'] ?? '');
    if ($hungerState !== '') {
        $fields['hunger'] = $hungerState;
    }

    $characterState = trim(strval($metadata['character_state'] ?? ''));
    if ($characterState !== '' && strtolower($characterState) !== 'normal') {
        $fields['state'] = $characterState;
    }

    $activityFlags = [];
    $activitySignals = [];
    $activityMap = [
        'is_in_combat' => 'in combat',
        'is_attacking' => 'attacking',
        'is_sneaking' => 'sneaking',
        'is_running' => 'running',
        'is_moving' => 'moving',
    ];
    foreach ($activityMap as $key => $label) {
        $parsed = $parseFlag($metadata[$key] ?? null);
        if ($parsed === true) {
            $activityFlags[] = $label;
            $activitySignals[$key] = true;
        }
    }

    $currentAction = trim(strval($metadata['current_action'] ?? ''));
    if ($currentAction === '') {
        if (($activitySignals['is_attacking'] ?? false) === true) {
            $currentAction = 'attacking';
        } elseif (($activitySignals['is_in_combat'] ?? false) === true) {
            $currentAction = 'combat';
        } elseif (($activitySignals['is_sneaking'] ?? false) === true) {
            $currentAction = 'sneaking';
        } elseif (($activitySignals['is_running'] ?? false) === true) {
            $currentAction = 'running';
        } elseif (($activitySignals['is_moving'] ?? false) === true) {
            $currentAction = 'moving';
        } elseif ($characterState !== '' && strtolower($characterState) !== 'normal') {
            $currentAction = strtolower($characterState);
        } else {
            $currentAction = 'idle';
        }
    }
    $fields['current_action'] = $currentAction;

    if (count($activityFlags) > 0) {
        $fields['action_flags'] = implode(', ', $activityFlags);
    }

    $attackTarget = trim(strval($metadata['attack_target'] ?? ''));
    if ($attackTarget !== '') {
        $fields['attack_target'] = $attackTarget;
    }

    $movementSpeedRaw = $metadata['movement_speed'] ?? '';
    $movementSpeedText = trim(strval($movementSpeedRaw));
    if ($movementSpeedText !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $movementSpeedText) === 1) {
        $movementSpeed = floatval($movementSpeedText);
        if ($movementSpeed > 0.0) {
            $fields['movement_speed'] = number_format($movementSpeed, 2);
        }
    }

    $bounty = function_exists('stobeBountyAmountFromPayload')
        ? stobeBountyAmountFromPayload($npcData['bounty'] ?? [])
        : intval($npcData['bounty'] ?? 0);
    if ($bounty > 0) {
        $fields['bounty'] = number_format($bounty) . ' Cats';
    }

    $inventoryContext = buildInventoryContextFromMetadata($metadata);
    $hasInventoryContext = boolval($inventoryContext['has_items'] ?? false);

    $equipmentRaw = trim(strval($inventoryContext['equipment'] ?? ''));
    $usedEquipmentFallback = false;
    if ($equipmentRaw === '') {
        $equipmentRaw = strval($npcData['equipment'] ?? '');
        $usedEquipmentFallback = true;
    }
    if ($usedEquipmentFallback) {
        $equipmentRaw = stobeEnrichItemCsvWithDescriptions($equipmentRaw, 14, 0, 4000);
    }
    $equipment = truncatePromptValue($equipmentRaw, 3200);
    if ($equipment !== '') {
        $fields['equipment'] = $equipment;
    }

    $inventoryRaw = trim(strval($inventoryContext['inventory'] ?? ''));
    $usedInventoryFallback = false;
    if ($inventoryRaw === '' && !$hasInventoryContext) {
        $inventoryRaw = strval($npcData['inventory'] ?? '');
        $usedInventoryFallback = true;
    }
    if ($usedInventoryFallback) {
        $inventoryRaw = stobeEnrichItemCsvWithDescriptions($inventoryRaw, 16, 0, 4500);
    }
    $inventory = truncatePromptValue($inventoryRaw, 3600);
    if ($inventory !== '') {
        $fields['personal_inventory'] = $inventory;
    }

    $merchantInventoryRaw = trim(strval(
        $inventoryContext['merchant_inventory']
            ?? ($inventoryContext['trader_inventory'] ?? '')
    ));
    $merchantInventory = truncatePromptValue($merchantInventoryRaw, 3600);
    if ($merchantInventory !== '') {
        $fields['merchant_inventory'] = $merchantInventory;
    }

    $limbState = stobeDescribeLimbStatus($npcData['limbs'] ?? '');
    if ($limbState !== '') {
        $fields['limb_status'] = $limbState;
    }

    $roboticLimbRaw = $metadata['robotic_limbs'] ?? [];
    $roboticLimbList = [];
    if (is_array($roboticLimbRaw)) {
        $roboticLimbList = $roboticLimbRaw;
    } elseif (is_string($roboticLimbRaw) && trim($roboticLimbRaw) !== '') {
        $decodedRobotLimbs = json_decode($roboticLimbRaw, true);
        if (is_array($decodedRobotLimbs)) {
            $roboticLimbList = $decodedRobotLimbs;
        }
    }
    $roboticLabels = [];
    foreach ($roboticLimbList as $limbNameRaw) {
        $limbName = trim(strval($limbNameRaw));
        if ($limbName === '') {
            continue;
        }
        $normalized = strtolower(str_replace(['-', ' '], '_', $limbName));
        if ($normalized === 'left_arm') {
            $roboticLabels[] = 'left arm prosthetic';
        } elseif ($normalized === 'right_arm') {
            $roboticLabels[] = 'right arm prosthetic';
        } elseif ($normalized === 'left_leg') {
            $roboticLabels[] = 'left leg prosthetic';
        } elseif ($normalized === 'right_leg') {
            $roboticLabels[] = 'right leg prosthetic';
        } elseif ($normalized === 'head') {
            $roboticLabels[] = 'robotic head';
        } elseif ($normalized === 'stomach') {
            $roboticLabels[] = 'robotic torso';
        } else {
            $roboticLabels[] = str_replace('_', ' ', $limbName);
        }
        if (count($roboticLabels) >= 6) {
            break;
        }
    }
    if (count($roboticLabels) > 0) {
        $fields['robotic_limbs'] = implode(', ', $roboticLabels);
    } else {
        $hasRobotics = $parseFlag($metadata['has_robotic_limbs'] ?? null);
        if ($hasRobotics === true) {
            $fields['robotic_limbs'] = 'Robotics detected';
        }
    }

    if (count($fields) === 0) {
        return '';
    }

    $xml = ['<character_state>'];
    foreach ($fields as $tag => $value) {
        $xml[] = '  <' . $tag . '>' . stobePromptXmlEscape($value) . '</' . $tag . '>';
    }
    $xml[] = '</character_state>';
    return implode("\n", $xml);
}

function parseFactionIdentityToken(string $rawFaction): array {
    $raw = trim($rawFaction);
    if ($raw === '') {
        return ['name' => '', 'id' => ''];
    }

    $name = $raw;
    $id = '';
    if (preg_match('/^(.*?)\s*\[\[([^\]]+)\]\]\s*$/u', $raw, $matches) === 1) {
        $parsedName = trim(strval($matches[1] ?? ''));
        $parsedId = trim(strval($matches[2] ?? ''));
        if ($parsedId !== '') {
            $id = $parsedId;
            $name = $parsedName !== '' ? $parsedName : $raw;
        }
    } elseif (preg_match('/^(.*?)\s*\[([^\]]+)\]\s*$/u', $raw, $matches) === 1) {
        $parsedName = trim(strval($matches[1] ?? ''));
        $parsedId = trim(strval($matches[2] ?? ''));
        if ($parsedId !== '') {
            $id = $parsedId;
            $name = $parsedName !== '' ? $parsedName : $raw;
        }
    }
    $name = trim(strval(preg_replace('/\s*\[\[[^\]]+\]\]\s*$/u', '', $name)));
    $name = trim(strval(preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $name)));

    $nameLower = strtolower($name);
    if (in_array($nameLower, ['unknown', 'neutral', 'none', 'n/a'], true)) {
        $name = '';
    }

    return ['name' => $name, 'id' => $id];
}

function stobeFactionDisplayName(string $rawFaction): string {
    $identity = parseFactionIdentityToken($rawFaction);
    $name = trim(strval($identity['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    return $name;
}

function normalizeNpcMetadataPayload(mixed $metadata): array {
    if (is_array($metadata)) {
        return $metadata;
    }
    if (is_string($metadata) && trim($metadata) !== '') {
        $decoded = json_decode($metadata, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function normalizeNpcExtendedDataPayload(mixed $extendedData): array {
    if (is_array($extendedData)) {
        return $extendedData;
    }
    if (is_string($extendedData) && trim($extendedData) !== '') {
        $decoded = json_decode($extendedData, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeExtractSceneArray(array $payload, string $key): array {
    if (!array_key_exists($key, $payload)) {
        return [];
    }
    $raw = $payload[$key];
    if (is_array($raw)) {
        return $raw;
    }
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeDescribeDistanceBand(mixed $rawDistance): string {
    $text = trim(strval($rawDistance));
    if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
        return '';
    }

    $distance = floatval($text);
    if ($distance < 0.0) {
        return '';
    }

    if ($distance <= 60.0) {
        return 'Close';
    }
    if ($distance <= 180.0) {
        return 'Nearby';
    }
    return 'Far away';
}

function stobeDescribeNearbyEntryHunger(array $entry): string {
    $direct = trim(strval($entry['hunger'] ?? ''));
    if ($direct !== '') {
        $status = stobeDescribeHungerStatus($direct);
        if ($status !== '') {
            return $status;
        }
    }

    $medical = $entry['medical'] ?? null;
    if (is_array($medical)) {
        $current = trim(strval($medical['hunger'] ?? ''));
        $max = trim(strval($medical['hunger_max'] ?? ($medical['max_hunger'] ?? '')));
        if ($current !== '' && $max !== '') {
            $status = stobeDescribeHungerStatus($current . '/' . $max);
            if ($status !== '') {
                return $status;
            }
        }
    } elseif (is_string($medical) && trim($medical) !== '') {
        $decoded = json_decode($medical, true);
        if (is_array($decoded)) {
            $current = trim(strval($decoded['hunger'] ?? ''));
            $max = trim(strval($decoded['hunger_max'] ?? ($decoded['max_hunger'] ?? '')));
            if ($current !== '' && $max !== '') {
                $status = stobeDescribeHungerStatus($current . '/' . $max);
                if ($status !== '') {
                    return $status;
                }
            }
        }
    }

    return '';
}

function stobeCoerceTruthyPromptFlag(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return floatval($value) != 0.0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'enabled'], true);
    }
    return false;
}

function stobeDescribeDrunkPromptState(mixed $rawLevel, mixed $rawIsDrunk, mixed $rawStatus): string {
    $status = strtolower(trim(strval($rawStatus)));
    if ($status !== '') {
        if (in_array($status, ['passed_out', 'passed out', 'blackout', 'knocked_out', 'knocked out'], true)) {
            return 'Passed out';
        }
        if (in_array($status, ['very_drunk', 'very drunk', 'verydrunk'], true)) {
            return 'Very drunk';
        }
        if (in_array($status, ['drunk', 'tipsy'], true)) {
            return 'Drunk';
        }
        if ($status === 'sober') {
            return '';
        }
    }

    $level = 0;
    if (is_int($rawLevel) || is_float($rawLevel)) {
        $level = intval($rawLevel);
    } elseif (is_string($rawLevel) && preg_match('/^-?[0-9]+$/', trim($rawLevel)) === 1) {
        $level = intval($rawLevel);
    }

    if ($level >= 3) {
        return 'Passed out';
    }
    if ($level >= 2) {
        return 'Very drunk';
    }
    if ($level >= 1 || stobeCoerceTruthyPromptFlag($rawIsDrunk)) {
        return 'Drunk';
    }

    return '';
}

function stobeDescribeHighPromptState(
    mixed $rawIsHigh,
    mixed $rawStatus,
    mixed $rawSecondsRemaining,
    mixed $rawHungerMultiplier
): string {
    $status = strtolower(trim(strval($rawStatus)));
    $isHigh = stobeCoerceTruthyPromptFlag($rawIsHigh);
    if (!$isHigh && $status !== '') {
        $isHigh = in_array($status, ['high', 'stoned', 'drugged'], true);
    }
    if (!$isHigh) {
        return '';
    }

    $hungerMultiplier = 1.0;
    if (is_int($rawHungerMultiplier) || is_float($rawHungerMultiplier)) {
        $hungerMultiplier = floatval($rawHungerMultiplier);
    } elseif (is_string($rawHungerMultiplier) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($rawHungerMultiplier)) === 1) {
        $hungerMultiplier = floatval($rawHungerMultiplier);
    }
    if ($hungerMultiplier < 1.0) {
        $hungerMultiplier = 1.0;
    }

    $secondsRemaining = 0;
    if (is_int($rawSecondsRemaining) || is_float($rawSecondsRemaining)) {
        $secondsRemaining = max(0, intval($rawSecondsRemaining));
    } elseif (is_string($rawSecondsRemaining) && preg_match('/^-?[0-9]+$/', trim($rawSecondsRemaining)) === 1) {
        $secondsRemaining = max(0, intval($rawSecondsRemaining));
    }

    $parts = ['High'];
    if ($hungerMultiplier > 1.0) {
        $parts[] = 'hunger x' . rtrim(rtrim(sprintf('%.2f', $hungerMultiplier), '0'), '.');
    }
    if ($secondsRemaining > 0) {
        $minutes = intval(ceil($secondsRemaining / 60.0));
        if ($minutes < 1) {
            $minutes = 1;
        }
        $parts[] = strval($minutes) . 'm remaining';
    }

    return implode(' | ', $parts);
}

function stobeDescribeNearbyEntryAppearance(array $entry): string {
    $appearance = trim(strval($entry['appearance'] ?? ($entry['looks'] ?? '')));
    if ($appearance !== '') {
        return truncatePromptValue($appearance, 140);
    }

    $race = trim(strval($entry['race'] ?? ''));
    $gender = trim(strval($entry['gender'] ?? ''));
    $parts = [];
    if ($gender !== '' && !in_array(strtolower($gender), ['unknown', 'none', 'n/a'], true)) {
        $parts[] = ucfirst(strtolower($gender));
    }
    if ($race !== '' && !in_array(strtolower($race), ['unknown', 'none', 'n/a'], true)) {
        $parts[] = $race;
    }
    if (count($parts) > 0) {
        return implode(' ', $parts);
    }

    return '';
}

function stobeGetActivatedNpcNameLookup(): array {
    static $lookup = null;
    if (is_array($lookup)) {
        return $lookup;
    }

    $lookup = [];
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return $lookup;
    }

    $rows = $db->fetchAll("SELECT name FROM core_npc");
    foreach ($rows as $row) {
        $name = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lookup[strtolower($name)] = $name;
    }
    return $lookup;
}

function stobeResolveBountyPromptText(mixed $rawBounty, mixed $rawBountyInfo = null, int $maxLength = 420): string {
    $amount = 0;
    if (function_exists('stobeBountyAmountFromPayload')) {
        $amount = intval(stobeBountyAmountFromPayload($rawBounty, $rawBountyInfo));
    } elseif (is_int($rawBounty) || is_float($rawBounty) || (is_string($rawBounty) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($rawBounty)) === 1)) {
        $amount = intval(floatval($rawBounty));
    }
    if ($amount <= 0) {
        return '';
    }

    $summary = '';
    if (function_exists('stobeNormalizeBountyPayload') && function_exists('stobeBuildBountyText')) {
        $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
        if (is_array($payload) && count($payload) > 0) {
            $summary = trim(strval(stobeBuildBountyText($payload)));
        }
    }

    if ($summary === '') {
        $summary = 'Bounty: ' . number_format($amount) . ' cats';
    } else {
        $summary = str_replace(' | ', '; ', $summary);
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;
        if (stripos($summary, 'Bounty:') !== 0) {
            $summary = 'Bounty: ' . $summary;
        }
    }

    return truncatePromptValue(trim($summary), max(80, $maxLength));
}

function stobeBuildNpcBountyPromptBlock(array $npcData): string {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $rawBounty = $npcData['bounty'] ?? ($metadata['bounty'] ?? null);
    $rawBountyInfo = $npcData['bounty_info']
        ?? ($npcData['bounty_details']
        ?? ($metadata['bounty_info'] ?? ($metadata['bounty_details'] ?? null)));

    $text = stobeResolveBountyPromptText($rawBounty, $rawBountyInfo, 520);
    if ($text === '') {
        return '';
    }

    return "<bounty>\n" . stobePromptXmlEscape($text) . "\n</bounty>";
}

function stobeBuildNearbyEntryBountyText(array $entry): string {
    $metadata = normalizeNpcMetadataPayload($entry['metadata'] ?? []);
    $rawBounty = $entry['bounty'] ?? ($entry['bounty_payload'] ?? ($metadata['bounty'] ?? null));
    $rawBountyInfo = $entry['bounty_info']
        ?? ($entry['bounty_details']
        ?? ($entry['bounty_payload'] ?? ($metadata['bounty_info'] ?? ($metadata['bounty_details'] ?? null))));
    return stobeResolveBountyPromptText($rawBounty, $rawBountyInfo, 380);
}

function stobeBuildNearbyActorsPromptBlock(array $npcData, string $speakerName = ''): string {
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $actors = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($actors) === 0) {
        $actors = stobeExtractSceneArray($extendedData, 'nearby');
    }
    if (count($actors) === 0) {
        return '';
    }

    $speakerKey = strtolower(normalizeParticipantNameToken($speakerName));
    $activated = stobeGetActivatedNpcNameLookup();
    $hasActivatedLookup = count($activated) > 0;
    $seen = [];
    $lines = [
        '<nearby_actors>',
        '# NEARBY ACTORS/NPC IN THE SCENE',
    ];
    $added = 0;

    foreach ($actors as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if ($nameKey === $speakerKey || isset($seen[$nameKey])) {
            continue;
        }
        if ($hasActivatedLookup && !isset($activated[$nameKey])) {
            continue;
        }
        $seen[$nameKey] = true;

        $race = trim(strval($entry['race'] ?? ''));
        $gender = trim(strval($entry['gender'] ?? ''));
        $descriptorParts = [];
        if ($gender !== '' && !in_array(strtolower($gender), ['unknown', 'none', 'n/a'], true)) {
            $descriptorParts[] = ucfirst(strtolower($gender));
        }
        if ($race !== '' && !in_array(strtolower($race), ['unknown', 'none', 'n/a'], true)) {
            $descriptorParts[] = $race;
        }
        $descriptor = count($descriptorParts) > 0 ? (' (' . implode(' ', $descriptorParts) . ')') : '';

        $detailParts = [];
        $faction = stobeFactionDisplayName(strval($entry['faction'] ?? ''));
        if ($faction !== '' && !in_array(strtolower($faction), ['unknown', 'none', 'n/a'], true)) {
            $detailParts[] = 'Faction: ' . $faction;
        }
        $bountyText = stobeBuildNearbyEntryBountyText($entry);
        if ($bountyText !== '') {
            $detailParts[] = $bountyText;
        }
        $action = trim(strval($entry['current_action'] ?? ''));
        if ($action !== '' && !in_array(strtolower($action), ['unknown', 'none', 'n/a'], true)) {
            $detailParts[] = 'Action: ' . $action;
        }
        $hungerState = stobeDescribeNearbyEntryHunger($entry);
        if ($hungerState !== '') {
            $detailParts[] = 'Hunger: ' . $hungerState;
        }
        $drunkState = stobeDescribeDrunkPromptState(
            $entry['drunk_level'] ?? null,
            $entry['is_drunk'] ?? null,
            $entry['drunk_status'] ?? null
        );
        if ($drunkState !== '') {
            $detailParts[] = 'Drunk state: ' . $drunkState;
        }
        $highState = stobeDescribeHighPromptState(
            $entry['is_high'] ?? null,
            $entry['high_status'] ?? null,
            $entry['high_seconds_remaining'] ?? null,
            $entry['high_hunger_rate_multiplier'] ?? null
        );
        if ($highState !== '') {
            $detailParts[] = 'Drug state: ' . $highState;
        }
        $appearance = stobeDescribeNearbyEntryAppearance($entry);
        if ($appearance !== '') {
            $detailParts[] = 'Appearance: ' . $appearance;
        }
        $equipmentRaw = trim(strval($entry['equipment'] ?? ''));
        $equipment = truncatePromptValue(
            stobeEnrichItemCsvWithDescriptions($equipmentRaw, 8, 70, 760),
            760
        );
        if ($equipment !== '') {
            $detailParts[] = 'Equipment: ' . $equipment;
        }
        $distanceBand = stobeDescribeDistanceBand($entry['dist'] ?? '');
        if ($distanceBand !== '') {
            $detailParts[] = 'Distance: ' . $distanceBand;
        }

        $line = '## ' . stobePromptXmlEscape($name . $descriptor);
        if (count($detailParts) > 0) {
            $line .= ': ' . stobePromptXmlEscape(implode(' | ', $detailParts));
        }
        $lines[] = $line;
        $added++;
        if ($added >= 24) {
            break;
        }
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</nearby_actors>';
    return implode("\n", $lines);
}

function stobeBuildNearbyItemsPromptBlock(array $npcData): string {
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $items = stobeExtractSceneArray($extendedData, 'nearby_items');
    if (count($items) === 0) {
        return '';
    }

    $seen = [];
    $lines = [
        '<nearby_items>',
        '# NEARBY ITEMS (format: RefID:ItemName (Description))',
    ];
    $added = 0;

    foreach ($items as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim(strval($entry['name'] ?? ($entry['item'] ?? '')));
        if ($name === '') {
            continue;
        }
        $refId = trim(strval($entry['refid'] ?? ($entry['id'] ?? '')));
        $baseName = stobeExtractBaseItemNameFromAggregateToken($name);
        if ($baseName === '') {
            $baseName = $name;
        }
        $dedupeKey = stobeNormalizeItemNameKey($baseName);
        if ($dedupeKey === '') {
            $dedupeKey = strtolower(($refId !== '' ? $refId : $name));
        }
        if ($dedupeKey === '' || isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $displayName = stobeEnrichAggregateItemTokenWithDescription($name, 120);
        $display = $refId !== '' ? ($refId . ':' . $displayName) : $displayName;
        $distanceBand = stobeDescribeDistanceBand($entry['dist'] ?? '');
        if ($distanceBand !== '') {
            $display .= ' (' . $distanceBand . ')';
        }

        $lines[] = '## ' . stobePromptXmlEscape($display);
        $added++;
        if ($added >= 32) {
            break;
        }
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</nearby_items>';
    return implode("\n", $lines);
}

function stobeBuildPointsOfInterestPromptBlock(array $npcData): string {
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $points = stobeExtractSceneArray($extendedData, 'points_of_interest');
    if (count($points) === 0) {
        return '';
    }

    $seen = [];
    $lines = [
        '<points_of_interest>',
        '# POIs - Points of Interest nearby',
    ];
    $added = 0;

    foreach ($points as $entry) {
        $name = '';
        $type = '';
        $distRaw = '';

        if (is_string($entry)) {
            $name = trim($entry);
        } elseif (is_array($entry)) {
            $name = trim(strval($entry['name'] ?? ($entry['location'] ?? '')));
            $type = trim(strval($entry['type'] ?? ($entry['kind'] ?? '')));
            $distRaw = trim(strval($entry['dist'] ?? ''));
        }

        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($seen[$nameKey])) {
            continue;
        }
        $seen[$nameKey] = true;

        $display = $name;
        if ($type !== '') {
            $display .= ' (' . $type . ')';
        }
        $distanceBand = stobeDescribeDistanceBand($distRaw);
        if ($distanceBand !== '') {
            $display .= ' - ' . $distanceBand;
        }

        $lines[] = '## ' . stobePromptXmlEscape($display);
        $added++;
        if ($added >= 24) {
            break;
        }
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</points_of_interest>';
    return implode("\n", $lines);
}

function stobeBuildScenePromptBlock(array $npcData, string $speakerName = ''): string {
    $blocks = [];

    $actors = stobeBuildNearbyActorsPromptBlock($npcData, $speakerName);
    if ($actors !== '') {
        $blocks[] = $actors;
    }

    $items = stobeBuildNearbyItemsPromptBlock($npcData);
    if ($items !== '') {
        $blocks[] = $items;
    }

    $points = stobeBuildPointsOfInterestPromptBlock($npcData);
    if ($points !== '') {
        $blocks[] = $points;
    }

    if (count($blocks) === 0) {
        return '';
    }

    return implode("\n\n", $blocks);
}

function getCurrentPlayerFactionIdentity(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT faction, metadata
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
            OR LOWER(COALESCE(metadata->>'is_player_character', 'false')) IN ('true', '1', 'yes', 'on')
         ORDER BY
            CASE WHEN LOWER(name) = LOWER($1) THEN 0 ELSE 1 END,
            gamets_last_updated DESC,
            updated_at DESC
         LIMIT 1",
        [$playerName]
    );

    if (!$row) {
        $cached = ['name' => '', 'id' => ''];
        return $cached;
    }

    $identity = parseFactionIdentityToken(strval($row['faction'] ?? ''));
    $metadata = normalizeNpcMetadataPayload($row['metadata'] ?? []);
    $metaFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
    if ($identity['id'] === '' && $metaFactionId !== '') {
        $identity['id'] = $metaFactionId;
    }

    if ($identity['name'] === '') {
        $metaFaction = trim(strval($metadata['faction'] ?? ''));
        if ($metaFaction !== '') {
            $metaIdentity = parseFactionIdentityToken($metaFaction);
            if ($metaIdentity['name'] !== '') {
                $identity['name'] = $metaIdentity['name'];
            }
            if ($identity['id'] === '' && $metaIdentity['id'] !== '') {
                $identity['id'] = $metaIdentity['id'];
            }
        }
    }

    $cached = $identity;
    return $cached;
}

function getNpcFactionIdentityFromProfile(array $npcData): array {
    $identity = parseFactionIdentityToken(strval($npcData['faction'] ?? ''));
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $metaFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
    if ($identity['id'] === '' && $metaFactionId !== '') {
        $identity['id'] = $metaFactionId;
    }
    return $identity;
}

function npcIsInPlayerFaction(array $npcData): bool {
    $playerFaction = getCurrentPlayerFactionIdentity();
    $npcFaction = getNpcFactionIdentityFromProfile($npcData);

    $playerFactionId = trim(strval($playerFaction['id'] ?? ''));
    $npcFactionId = trim(strval($npcFaction['id'] ?? ''));
    if ($playerFactionId !== '' && $npcFactionId !== '') {
        return strcasecmp($playerFactionId, $npcFactionId) === 0;
    }

    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    $npcFactionName = trim(strval($npcFaction['name'] ?? ''));
    if ($playerFactionName !== '' && $npcFactionName !== '') {
        return strcasecmp($playerFactionName, $npcFactionName) === 0;
    }

    return false;
}

function decodeConfOptJsonList(string $confId): array {
    $raw = trim(getConfOpt($confId, ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

function getPlayerSquadMembershipSnapshot(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $snapshot = [
        'squad_members' => [],
        'member_to_squads' => [],
    ];

    $squadNamesRaw = decodeConfOptJsonList('PLAYER_SQUADS');
    foreach ($squadNamesRaw as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $squadName = trim($entry);
        if ($squadName === '') {
            continue;
        }

        $membersRaw = decodeConfOptJsonList($squadName);
        $memberMap = [];
        foreach ($membersRaw as $memberEntry) {
            if (!is_string($memberEntry)) {
                continue;
            }
            $memberName = normalizeParticipantNameToken($memberEntry);
            if ($memberName === '') {
                continue;
            }
            $memberKey = strtolower($memberName);
            if (!isset($memberMap[$memberKey])) {
                $memberMap[$memberKey] = $memberName;
            }
            if (!isset($snapshot['member_to_squads'][$memberKey])) {
                $snapshot['member_to_squads'][$memberKey] = [];
            }
            if (!in_array($squadName, $snapshot['member_to_squads'][$memberKey], true)) {
                $snapshot['member_to_squads'][$memberKey][] = $squadName;
            }
        }
        if (count($memberMap) > 0) {
            $snapshot['squad_members'][$squadName] = $memberMap;
        }
    }

    $cached = $snapshot;
    return $cached;
}

function getSpeakerSquadMemberSet(string $speakerName): array {
    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '') {
        return [];
    }

    $membership = getPlayerSquadMembershipSnapshot();
    $speakerKey = strtolower($speaker);
    $speakerSquads = is_array($membership['member_to_squads'][$speakerKey] ?? null)
        ? $membership['member_to_squads'][$speakerKey]
        : [];
    if (count($speakerSquads) === 0) {
        return [];
    }

    $memberSet = [];
    foreach ($speakerSquads as $squadName) {
        $members = is_array($membership['squad_members'][$squadName] ?? null)
            ? $membership['squad_members'][$squadName]
            : [];
        foreach ($members as $memberKey => $memberName) {
            $memberSet[$memberKey] = $memberName;
        }
    }
    unset($memberSet[$speakerKey]);

    return $memberSet;
}

function getFactionIdentityFromNearbyEntry(array $entry): array {
    $identity = parseFactionIdentityToken(strval($entry['faction'] ?? ''));
    $factionId = trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? '')));
    if ($identity['id'] === '' && $factionId !== '') {
        $identity['id'] = $factionId;
    }
    return $identity;
}

function nearbyEntryIsInPlayerFaction(array $entry): bool {
    $playerFaction = getCurrentPlayerFactionIdentity();
    $nearFaction = getFactionIdentityFromNearbyEntry($entry);

    $playerFactionId = trim(strval($playerFaction['id'] ?? ''));
    $nearFactionId = trim(strval($nearFaction['id'] ?? ''));
    if ($playerFactionId !== '' && $nearFactionId !== '') {
        return strcasecmp($playerFactionId, $nearFactionId) === 0;
    }

    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    $nearFactionName = trim(strval($nearFaction['name'] ?? ''));
    if ($playerFactionName !== '' && $nearFactionName !== '') {
        return strcasecmp($playerFactionName, $nearFactionName) === 0;
    }

    return false;
}

function buildNearbyPlayerAlliesPrompt(array $nearby, string $speakerName): string {
    if (count($nearby) === 0) {
        return '';
    }

    $speakerSquadMembers = getSpeakerSquadMemberSet($speakerName);
    $sameSquad = [];
    $sameFaction = [];
    $seen = [];

    foreach ($nearby as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($seen[$nameKey])) {
            continue;
        }

        if (isset($speakerSquadMembers[$nameKey])) {
            $sameSquad[] = $speakerSquadMembers[$nameKey];
            $seen[$nameKey] = true;
            continue;
        }

        if (nearbyEntryIsInPlayerFaction($entry)) {
            $sameFaction[] = $name;
            $seen[$nameKey] = true;
        }
    }

    if (count($sameSquad) === 0 && count($sameFaction) === 0) {
        return '';
    }

    $lines = [
        '<nearby_player_allies>',
        '  <summary>Nearby people aligned with the player side.</summary>',
    ];
    if (count($sameSquad) > 0) {
        $lines[] = '  <same_squad>' . stobePromptXmlEscape(implode(', ', $sameSquad)) . '</same_squad>';
    }
    if (count($sameFaction) > 0) {
        $lines[] = '  <same_player_faction>' . stobePromptXmlEscape(implode(', ', $sameFaction)) . '</same_player_faction>';
    }
    $lines[] = '  <usage_note>Use this only as social context. Do not mention squad or faction names explicitly.</usage_note>';
    $lines[] = '</nearby_player_allies>';

    return implode("\n", $lines);
}

function stobeBuildRoleplayInstructionsText(string $npcName, string $playerName, array|false $npcData = false): string {
    $custom = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $custom = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['ROLEPLAY_INSTRUCTIONS'],
            'ROLEPLAY_INSTRUCTIONS',
            ''
        ));
    } else {
        $custom = trim(strval(getSetting('ROLEPLAY_INSTRUCTIONS', '')));
    }
    if ($custom !== '') {
        return $custom;
    }

    $safeNpc = trim($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'this character';
    }
    $safePlayer = trim($playerName);
    if ($safePlayer === '') {
        $safePlayer = 'the conversation target';
    }

    return 'Roleplay as ' . $safeNpc . '. Treat Kenshi as your lived reality and respond directly to '
        . $safePlayer . ' in grounded in-world terms.';
}

function stobeBuildGeneralInstructionsText(array|false $npcData = false): string {
    $custom = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $custom = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['GENERAL_INSTRUCTIONS'],
            'GENERAL_INSTRUCTIONS',
            ''
        ));
    } else {
        $custom = trim(strval(getSetting('GENERAL_INSTRUCTIONS', '')));
    }
    if ($custom !== '') {
        return $custom;
    }

    // Match HerikaServer behavior by preferring COMMAND_PROMPT when available.
    $herikaCommandPrompt = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $herikaCommandPrompt = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['COMMAND_PROMPT'],
            'COMMAND_PROMPT',
            ''
        ));
    } else {
        $herikaCommandPrompt = trim(strval(getSetting('COMMAND_PROMPT', '')));
    }
    if ($herikaCommandPrompt === '' && function_exists('getConfOpt')) {
        $herikaCommandPrompt = trim(strval(getConfOpt('COMMAND_PROMPT', '')));
    }
    if ($herikaCommandPrompt !== '') {
        return $herikaCommandPrompt;
    }

    $defaults = [
        'Stay in character at all times.',
        'Keep responses concise unless the moment needs detail.',
        'Use Kenshi world logic, factions, and survival tone.',
        'Never mention being an AI or being in a game.',
    ];
    return implode("\n", $defaults);
}

function stobeResolveNpcPromptOverrides(array $npcData, array $metadata = []): array {
    $profileId = intval($npcData['profile_id'] ?? 0);
    if ($profileId <= 0 && function_exists('getDefaultNpcProfileId')) {
        $profileId = intval(getDefaultNpcProfileId());
    }

    $profilePromptHead = '';
    $profilePrompt = '';
    if ($profileId > 0 && function_exists('getCoreProfileById')) {
        static $profileCache = [];
        if (!array_key_exists($profileId, $profileCache)) {
            $profileRow = getCoreProfileById($profileId);
            $profileCache[$profileId] = is_array($profileRow) ? $profileRow : false;
        }
        $resolvedProfile = $profileCache[$profileId];
        if (is_array($resolvedProfile)) {
            $profilePromptHead = trim(strval($resolvedProfile['prompt_head'] ?? ''));
            $profilePrompt = trim(strval($resolvedProfile['profile_prompt'] ?? ''));
        }
    }

    $npcPromptHead = trim(strval($npcData['prompt_head'] ?? ($metadata['prompt_head'] ?? '')));
    $npcProfilePrompt = trim(strval($npcData['profile_prompt'] ?? ($metadata['profile_prompt'] ?? '')));

    if (function_exists('stobeGetNpcRuntimeOverrideMap')) {
        $runtimeOverrides = stobeGetNpcRuntimeOverrideMap($npcData);
        if ($npcPromptHead === '') {
            foreach (['PROMPT_HEAD', 'NPC_PROMPT_HEAD'] as $overrideKey) {
                if (!array_key_exists($overrideKey, $runtimeOverrides)) {
                    continue;
                }
                $npcPromptHead = trim(strval($runtimeOverrides[$overrideKey]));
                if ($npcPromptHead !== '') {
                    break;
                }
            }
        }
        if ($npcProfilePrompt === '') {
            foreach (['PROFILE_PROMPT'] as $overrideKey) {
                if (!array_key_exists($overrideKey, $runtimeOverrides)) {
                    continue;
                }
                $npcProfilePrompt = trim(strval($runtimeOverrides[$overrideKey]));
                if ($npcProfilePrompt !== '') {
                    break;
                }
            }
        }
    }

    return [
        'prompt_head' => $npcPromptHead !== '' ? $npcPromptHead : $profilePromptHead,
        'profile_prompt' => $npcProfilePrompt !== '' ? $npcProfilePrompt : $profilePrompt,
    ];
}

function stobeBuildNpcAppearanceText(array $npcData): string {
    $appearance = trim(strval($npcData['appearance'] ?? ''));
    if ($appearance !== '') {
        return truncatePromptValue($appearance, 280);
    }

    $race = trim(strval($npcData['race'] ?? ''));
    if ($race === '') {
        $race = 'Unknown';
    }

    return 'No detailed appearance record. Known race: ' . $race . '.';
}

function stobeBuildNpcConditionText(array $npcData, array $metadata): string {
    $parts = [];

    $health = trim(strval($npcData['health'] ?? ($metadata['health'] ?? '')));
    if ($health !== '') {
        $parts[] = 'Health: ' . $health;
    }

    $bloodState = stobeDescribeBloodStatus($npcData['blood'] ?? '');
    if ($bloodState !== '') {
        $parts[] = 'Blood: ' . $bloodState;
    }

    $hungerState = stobeDescribeHungerStatus($npcData['hunger'] ?? '');
    if ($hungerState !== '') {
        $parts[] = 'Hunger: ' . $hungerState;
    }

    $limbState = stobeDescribeLimbStatus($npcData['limbs'] ?? '');
    if ($limbState !== '') {
        $parts[] = 'Limbs: ' . $limbState;
    }

    $drunkState = stobeDescribeDrunkPromptState(
        $npcData['drunk_level'] ?? ($metadata['drunk_level'] ?? null),
        $npcData['is_drunk'] ?? ($metadata['is_drunk'] ?? null),
        $npcData['drunk_status'] ?? ($metadata['drunk_status'] ?? null)
    );
    if ($drunkState !== '') {
        $parts[] = 'Drunk state: ' . $drunkState;
    }
    $highState = stobeDescribeHighPromptState(
        $npcData['is_high'] ?? ($metadata['is_high'] ?? null),
        $npcData['high_status'] ?? ($metadata['high_status'] ?? null),
        $npcData['high_seconds_remaining'] ?? ($metadata['high_seconds_remaining'] ?? null),
        $npcData['high_hunger_rate_multiplier'] ?? ($metadata['high_hunger_rate_multiplier'] ?? null)
    );
    if ($highState !== '') {
        $parts[] = 'Drug state: ' . $highState;
    }

    $characterState = trim(strval($metadata['character_state'] ?? ''));
    if ($characterState !== '' && strtolower($characterState) !== 'normal') {
        $parts[] = 'State: ' . $characterState;
    }

    if (count($parts) === 0) {
        return 'Condition appears stable.';
    }

    return implode("\n", array_map(static function (string $line): string {
        return '- ' . $line;
    }, $parts));
}

function stobeSkillGroupMaps(): array {
    return [
        'attributes' => [
            'strength' => 'Strength',
            'dexterity' => 'Dexterity',
            'toughness' => 'Toughness',
            'perception' => 'Perception',
        ],
        'combat' => [
            'melee_attack' => 'Melee Attack',
            'melee_defence' => 'Melee Defence',
            'dodge' => 'Dodge',
            'martial_arts' => 'Martial Arts',
            'katanas' => 'Katanas',
            'sabres' => 'Sabres',
            'hackers' => 'Hackers',
            'heavy_weapons' => 'Heavy Weapons',
            'blunt' => 'Blunt',
            'polearms' => 'Polearms',
            'crossbows' => 'Crossbows',
            'turrets' => 'Turrets',
            'athletics' => 'Athletics',
            'stealth' => 'Stealth',
            'assassination' => 'Assassination',
            'swimming' => 'Swimming',
            'survival' => 'Survival',
        ],
        'core' => [
            'labouring' => 'Labouring',
            'thieving' => 'Thieving',
            'lockpicking' => 'Lockpicking',
            'medic' => 'Medic',
            'science' => 'Science',
            'engineering' => 'Engineering',
            'robotics' => 'Robotics',
            'farming' => 'Farming',
            'cooking' => 'Cooking',
            'weapon_smithing' => 'Weapon Smithing',
            'armour_smithing' => 'Armour Smithing',
            'bow_smithing' => 'Bow Smithing',
            'hive_medic' => 'Hive Medic',
            'vet' => 'Veterinary',
        ],
    ];
}

function stobeSkillAliases(): array {
    return [
        'melee_defense' => 'melee_defence',
        'martialarts' => 'martial_arts',
        'heavyweapons' => 'heavy_weapons',
        'weapon_smith' => 'weapon_smithing',
        'armor_smithing' => 'armour_smithing',
        'armour_smith' => 'armour_smithing',
        'bow_smith' => 'bow_smithing',
        'hivemedic' => 'hive_medic',
    ];
}

function stobeNormalizeSkillToken(string $token): string {
    $lower = strtolower(trim($token));
    if ($lower === '') {
        return '';
    }
    return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
}

function stobeSkillLookupTable(): array {
    static $lookup = null;
    if (is_array($lookup)) {
        return $lookup;
    }

    $lookup = [];
    $maps = stobeSkillGroupMaps();
    foreach ($maps as $groupMap) {
        foreach ($groupMap as $canonical => $label) {
            $lookup[stobeNormalizeSkillToken($canonical)] = $canonical;
            $lookup[stobeNormalizeSkillToken($label)] = $canonical;
        }
    }

    $shortLabels = [
        'str' => 'strength',
        'dex' => 'dexterity',
        'tgh' => 'toughness',
        'per' => 'perception',
        'matk' => 'melee_attack',
        'mdef' => 'melee_defence',
        'ma' => 'martial_arts',
        'katana' => 'katanas',
        'sabre' => 'sabres',
        'hacker' => 'hackers',
        'heavy' => 'heavy_weapons',
        'blunt' => 'blunt',
        'polearm' => 'polearms',
        'crossbow' => 'crossbows',
        'turret' => 'turrets',
        'assassin' => 'assassination',
        'swim' => 'swimming',
        'labour' => 'labouring',
        'lockpick' => 'lockpicking',
        'engineer' => 'engineering',
        'wpnsmith' => 'weapon_smithing',
        'armsmith' => 'armour_smithing',
        'bowsmith' => 'bow_smithing',
        'hivemedic' => 'hive_medic',
        'vet' => 'vet',
    ];
    foreach ($shortLabels as $token => $canonical) {
        $lookup[stobeNormalizeSkillToken($token)] = $canonical;
    }

    foreach (stobeSkillAliases() as $alias => $canonical) {
        $lookup[stobeNormalizeSkillToken($alias)] = $canonical;
    }

    return $lookup;
}

function stobeParseSkillInt(mixed $value): ?int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return intval($value);
    }
    if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
        return intval(floatval($value));
    }
    return null;
}

function stobeSkillTierLabel(?int $value): string {
    if ($value === null || $value <= 0) {
        return 'Untrained';
    }
    if ($value < 15) {
        return 'Novice';
    }
    if ($value < 30) {
        return 'Apprentice';
    }
    if ($value < 45) {
        return 'Competent';
    }
    if ($value < 60) {
        return 'Skilled';
    }
    if ($value < 75) {
        return 'Expert';
    }
    if ($value < 90) {
        return 'Veteran';
    }
    return 'Elite';
}

function stobeExtractSkillValues(array $npcData): array {
    $values = [];
    $lookup = stobeSkillLookupTable();
    $aliases = stobeSkillAliases();
    $addSkill = static function (string $rawKey, mixed $rawValue) use (&$values, $lookup, $aliases): void {
        $normalized = trim(strtolower($rawKey));
        if ($normalized === '') {
            return;
        }
        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }
        $token = stobeNormalizeSkillToken($normalized);
        if ($token === '' || !isset($lookup[$token])) {
            return;
        }
        $score = stobeParseSkillInt($rawValue);
        if ($score === null) {
            return;
        }
        $values[$lookup[$token]] = $score;
    };

    $rawSkills = $npcData['skills'] ?? '';
    if (is_array($rawSkills)) {
        foreach ($rawSkills as $key => $value) {
            $addSkill(strval($key), $value);
        }
    } else {
        $skillsText = trim(strval($rawSkills));
        if ($skillsText !== '') {
            $decoded = json_decode($skillsText, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $addSkill(strval($key), $value);
                }
            } else {
                if (preg_match_all('/([A-Za-z][A-Za-z0-9_ ]{1,32})\s+(-?[0-9]+)/', $skillsText, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $addSkill(strval($match[1] ?? ''), strval($match[2] ?? ''));
                    }
                }
            }
        }
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $stats = $metadata['stats'] ?? null;
    if (is_array($stats)) {
        foreach ($stats as $key => $value) {
            $addSkill(strval($key), $value);
        }
    }

    return $values;
}

function stobeBuildNpcSkillsText(array $npcData): string {
    $maps = stobeSkillGroupMaps();
    $values = stobeExtractSkillValues($npcData);

    $lines = [];
    foreach ($maps as $groupName => $groupMap) {
        $lines[] = '<group name="' . stobePromptXmlEscape($groupName) . '">';
        foreach ($groupMap as $canonical => $label) {
            $tier = stobeSkillTierLabel($values[$canonical] ?? null);
            $lines[] = '  <skill name="' . stobePromptXmlEscape($label) . '">' . stobePromptXmlEscape($tier) . '</skill>';
        }
        $lines[] = '</group>';
    }

    return implode("\n", $lines);
}

function stobeRelationshipTierLabel(int $score): string {
    if ($score >= 91) return 'Bonded';
    if ($score >= 76) return 'Devoted';
    if ($score >= 56) return 'Fond';
    if ($score >= 31) return 'Friendly';
    if ($score >= 6) return 'Acquaintance';
    if ($score >= -5) return 'Neutral';
    if ($score >= -30) return 'Wary';
    if ($score >= -55) return 'Cold';
    if ($score >= -75) return 'Resentful';
    if ($score >= -90) return 'Hateful';
    return 'Hostile';
}

function stobeNormalizeRelationshipTypeToken(string $rawType): string {
    $type = strtolower(trim($rawType));
    if ($type === '') {
        return 'neutral';
    }
    if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) !== 1) {
        return 'neutral';
    }
    return $type;
}

function stobeNormalizeRelationshipMap(mixed $rawMap): array {
    $source = [];
    if (is_array($rawMap)) {
        $source = $rawMap;
    } elseif (is_string($rawMap) && trim($rawMap) !== '') {
        $trimmedRaw = trim($rawMap);
        $decoded = json_decode($trimmedRaw, true);
        if (is_array($decoded)) {
            $source = $decoded;
        } else {
            // Legacy compatibility: parse summary text rows like:
            // "Name: Friendly (ally, aff 35)" or "* Name - +12 (Friendly, ally)"
            $parsed = [];
            $lines = preg_split('/\r\n|\r|\n/', $trimmedRaw) ?: [];
            foreach ($lines as $rawLine) {
                $line = trim(strval($rawLine));
                if ($line === '') {
                    continue;
                }
                $line = ltrim($line, " \t-*");
                if ($line === '') {
                    continue;
                }

                $target = '';
                $aff = 0;
                $type = 'neutral';
                $note = '';

                if (preg_match('/^(.+?)\s*:\s*[A-Za-z][A-Za-z ]{0,31}\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*,\s*aff\s*([+-]?[0-9]{1,3})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $type = strval($m[2] ?? 'neutral');
                    $aff = intval($m[3] ?? 0);
                    $note = trim(strval($m[4] ?? ''));
                } elseif (preg_match('/^(.+?)\s*-\s*([+-]?[0-9]{1,3})\s*\(\s*[A-Za-z][A-Za-z ]{0,31}\s*,\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $aff = intval($m[2] ?? 0);
                    $type = strval($m[3] ?? 'neutral');
                    $note = trim(strval($m[4] ?? ''));
                } elseif (preg_match('/^(.+?)\s*:\s*([+-]?[0-9]{1,3})\s*$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $aff = intval($m[2] ?? 0);
                }

                if ($target === '') {
                    continue;
                }
                $parsed[$target] = [
                    'aff' => $aff,
                    'type' => $type,
                    'note' => $note,
                ];
            }
            if (count($parsed) > 0) {
                $source = $parsed;
            }
        }
    }
    if (count($source) === 0) {
        return [];
    }

    $normalized = [];
    $canonicalNames = [];
    $append = static function (string $targetName, mixed $value) use (&$normalized, &$canonicalNames): void {
        $target = normalizeParticipantNameToken($targetName);
        if ($target === '') {
            if (is_array($value)) {
                $target = normalizeParticipantNameToken(strval($value['target'] ?? ($value['name'] ?? '')));
            }
        }
        if ($target === '') {
            return;
        }

        $affRaw = 0;
        $typeRaw = 'neutral';
        $noteRaw = '';
        if (is_array($value)) {
            $affRaw = $value['aff'] ?? ($value['affinity'] ?? 0);
            $typeRaw = strval($value['type'] ?? ($value['relationship_type'] ?? 'neutral'));
            $noteRaw = strval($value['note'] ?? ($value['reason'] ?? ''));
        } elseif (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[+-]?[0-9]+$/', trim($value)) === 1)) {
            $affRaw = $value;
        }

        $affinity = intval($affRaw);
        if ($affinity > 100) {
            $affinity = 100;
        } elseif ($affinity < -100) {
            $affinity = -100;
        }

        $type = stobeNormalizeRelationshipTypeToken($typeRaw);
        $note = trim($noteRaw);
        if (strlen($note) > 180) {
            $note = substr($note, 0, 180);
        }

        $key = strtolower($target);
        if (!isset($canonicalNames[$key])) {
            $canonicalNames[$key] = $target;
        }
        $canonical = $canonicalNames[$key];
        $normalized[$canonical] = [
            'aff' => $affinity,
            'type' => $type,
            'tier' => stobeRelationshipTierLabel($affinity),
            'note' => $note,
            'updated_at' => intval(time()),
        ];
    };

    $isList = array_keys($source) === range(0, count($source) - 1);
    if ($isList) {
        foreach ($source as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $target = strval($entry['target'] ?? ($entry['name'] ?? ''));
            $append($target, $entry);
        }
    } else {
        foreach ($source as $target => $entry) {
            $append(strval($target), $entry);
        }
    }

    return $normalized;
}

function stobeFindRelationshipEntryKey(array $relationshipMap, string $targetName): string {
    $target = normalizeParticipantNameToken($targetName);
    if ($target === '') {
        return '';
    }
    $targetLower = strtolower($target);
    if (array_key_exists($target, $relationshipMap)) {
        return $target;
    }
    foreach ($relationshipMap as $existingName => $_entry) {
        if (strtolower(strval($existingName)) === $targetLower) {
            return strval($existingName);
        }
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $playerLower = strtolower($playerName);
    if ($playerLower !== '' && ($targetLower === 'player' || $targetLower === $playerLower)) {
        foreach ($relationshipMap as $existingName => $_entry) {
            $existingLower = strtolower(strval($existingName));
            if ($existingLower === 'player' || ($playerLower !== '' && $existingLower === $playerLower)) {
                return strval($existingName);
            }
        }
    }

    return '';
}

function stobeCollectRelationshipContextTargets(array|false $npcData, string $speakerName, string $conversationTarget): array {
    $targets = [];
    $seen = [];
    $add = static function (string $rawName) use (&$targets, &$seen, $speakerName): void {
        $name = normalizeParticipantNameToken($rawName);
        if ($name === '') {
            return;
        }
        if (strcasecmp($name, $speakerName) === 0) {
            return;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $targets[] = $name;
    };

    $add($conversationTarget);
    $add(getSetting('PLAYER_NAME', 'Drifter'));
    $add('Player');

    $participants = extractParticipantNames([
        'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ''),
        'profile' => $conversationTarget,
        'speaker' => $speakerName,
    ]);
    foreach ($participants as $participantName) {
        $add(strval($participantName));
    }

    if (is_array($npcData)) {
        $extended = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
        $nearbyActors = stobeExtractSceneArray($extended, 'nearby_actors');
        if (count($nearbyActors) === 0) {
            $nearbyActors = stobeExtractSceneArray($extended, 'nearby');
        }
        foreach ($nearbyActors as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $add(strval($entry['name'] ?? ''));
        }
    }

    if (count($targets) > 16) {
        $targets = array_slice($targets, 0, 16);
    }
    return $targets;
}

function stobeShouldUseTierOnlyRelationshipContext(array|false $npcData): bool {
    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, 'relationship');
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return false;
    }
    $apiKey = trim(strval($connector['api_badge_key'] ?? ($connector['api_key'] ?? '')));
    return $apiKey !== '';
}

function stobeBuildNpcRelationshipsText(string $speakerName, string $conversationTarget, array|false $npcData = false): string {
    $speaker = normalizeParticipantNameToken($speakerName);
    if (!is_array($npcData)) {
        $npcData = getNpcData($speaker);
    }

    $relationshipMap = is_array($npcData)
        ? stobeNormalizeRelationshipMap(strval($npcData['relationships'] ?? ''))
        : [];
    $targetNames = stobeCollectRelationshipContextTargets($npcData, $speaker, $conversationTarget);

    foreach (array_keys($relationshipMap) as $mapTarget) {
        if (count($targetNames) >= 16) {
            break;
        }
        $normalized = normalizeParticipantNameToken(strval($mapTarget));
        if ($normalized === '') {
            continue;
        }
        if (strcasecmp($normalized, $speaker) === 0) {
            continue;
        }
        $alreadyIncluded = false;
        foreach ($targetNames as $existing) {
            if (strcasecmp($existing, $normalized) === 0) {
                $alreadyIncluded = true;
                break;
            }
        }
        if (!$alreadyIncluded) {
            $targetNames[] = $normalized;
        }
    }

    if (count($targetNames) === 0) {
        return 'No explicit relationship map recorded.';
    }

    $tierOnly = stobeShouldUseTierOnlyRelationshipContext($npcData);
    $lines = [];
    foreach ($targetNames as $targetName) {
        $existingKey = stobeFindRelationshipEntryKey($relationshipMap, $targetName);
        $entry = $existingKey !== '' ? $relationshipMap[$existingKey] : [
            'aff' => 0,
            'type' => 'neutral',
            'tier' => stobeRelationshipTierLabel(0),
            'note' => '',
        ];

        $aff = intval($entry['aff'] ?? 0);
        if ($aff > 100) {
            $aff = 100;
        } elseif ($aff < -100) {
            $aff = -100;
        }
        $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
        $tier = stobeRelationshipTierLabel($aff);
        $note = trim(strval($entry['note'] ?? ''));
        if (strlen($note) > 120) {
            $note = substr($note, 0, 120);
        }

        if ($tierOnly) {
            $line = '* ' . $targetName . ' - ' . $tier . ' (' . $type . ')';
        } else {
            $signedAff = $aff > 0 ? ('+' . strval($aff)) : strval($aff);
            $line = '* ' . $targetName . ' - ' . $signedAff . ' (' . $tier . ', ' . $type . ')';
        }
        if ($note !== '') {
            $line .= ' | ' . $note;
        }
        $lines[] = $line;
        if (count($lines) >= 16) {
            break;
        }
    }

    if (count($lines) === 0) {
        return 'No explicit relationship map recorded.';
    }
    return implode("\n", $lines);
}

function stobeStripRelationshipCommandTags(string $text): string {
    $cleaned = preg_replace('/#(?:REL|TYPE)\s*:[^#]+#/i', '', $text);
    if (!is_string($cleaned)) {
        $cleaned = $text;
    }
    $cleaned = preg_replace('/[ \t]{2,}/', ' ', $cleaned) ?? $cleaned;
    $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned) ?? $cleaned;
    return trim($cleaned);
}

function stobeParseRelationshipCommandTags(string $text): array {
    $updatesByKey = [];
    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($playerName === '') {
        $playerName = 'Player';
    }

    $normalizeTarget = static function (string $rawTarget) use ($playerName): string {
        $target = normalizeParticipantNameToken($rawTarget);
        if ($target === '') {
            return '';
        }
        $targetLower = strtolower($target);
        if ($targetLower === 'player' || $targetLower === 'the player') {
            return $playerName;
        }
        return $target;
    };

    if (preg_match_all('/#REL\s*:\s*([^=#]+?)\s*=\s*([+-]?[0-9]{1,3})\s*#/i', $text, $matches)) {
        foreach ($matches[1] as $idx => $rawTarget) {
            $target = $normalizeTarget(strval($rawTarget));
            if ($target === '') {
                continue;
            }
            $key = strtolower($target);
            if (!isset($updatesByKey[$key])) {
                $updatesByKey[$key] = [
                    'target' => $target,
                    'aff_delta' => 0,
                    'type' => '',
                    'note' => '',
                ];
            }
            $updatesByKey[$key]['aff_delta'] += intval($matches[2][$idx] ?? 0);
        }
    }

    if (preg_match_all('/#TYPE\s*:\s*([^=#]+?)\s*=\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*#/i', $text, $matches)) {
        foreach ($matches[1] as $idx => $rawTarget) {
            $target = $normalizeTarget(strval($rawTarget));
            if ($target === '') {
                continue;
            }
            $key = strtolower($target);
            if (!isset($updatesByKey[$key])) {
                $updatesByKey[$key] = [
                    'target' => $target,
                    'aff_delta' => 0,
                    'type' => '',
                    'note' => '',
                ];
            }
            $updatesByKey[$key]['type'] = stobeNormalizeRelationshipTypeToken(strval($matches[2][$idx] ?? 'neutral'));
        }
    }

    return [
        'updates' => array_values($updatesByKey),
        'clean_response' => stobeStripRelationshipCommandTags($text),
    ];
}

function stobeParseRelationshipEvalUpdates(string $rawResponse): array {
    $decoded = stobeDecodeStructuredDialoguePayload($rawResponse);
    if (!is_array($decoded) || count($decoded) === 0) {
        return [];
    }

    $rawUpdates = [];
    if (isset($decoded['updates'])) {
        $rawUpdates = $decoded['updates'];
    } elseif (isset($decoded['relationships'])) {
        $rawUpdates = $decoded['relationships'];
    } elseif (isset($decoded['changes'])) {
        $rawUpdates = $decoded['changes'];
    }

    if (!is_array($rawUpdates)) {
        return [];
    }

    $isList = array_keys($rawUpdates) === range(0, count($rawUpdates) - 1);
    if (!$isList) {
        $mapped = [];
        foreach ($rawUpdates as $target => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $payload['target'] = strval($payload['target'] ?? $target);
            $mapped[] = $payload;
        }
        $rawUpdates = $mapped;
    }

    $updates = [];
    foreach ($rawUpdates as $entry) {
        if (is_string($entry)) {
            $target = '';
            $delta = 0;
            $type = '';
            if (preg_match('/^\s*([^:]+)\s*:\s*([+-]?[0-9]{1,3})(?:\s*:\s*([a-zA-Z][a-zA-Z0-9_-]{0,31}))?\s*$/', $entry, $m) === 1) {
                $target = strval($m[1] ?? '');
                $delta = intval($m[2] ?? 0);
                $type = strval($m[3] ?? '');
            }
            if (trim($target) !== '') {
                $updates[] = [
                    'target' => $target,
                    'aff_delta' => $delta,
                    'type' => $type,
                    'note' => '',
                ];
            }
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }

        $target = strval($entry['target'] ?? ($entry['name'] ?? ($entry['listener'] ?? ($entry['npc'] ?? ''))));
        if (trim($target) === '') {
            continue;
        }

        $deltaRaw = $entry['aff_delta'] ?? ($entry['delta'] ?? ($entry['change'] ?? ($entry['affinity_delta'] ?? 0)));
        if (is_string($deltaRaw) && preg_match('/[+-]?[0-9]{1,3}/', $deltaRaw, $m) === 1) {
            $deltaRaw = intval($m[0]);
        }
        $delta = intval($deltaRaw);

        $updates[] = [
            'target' => $target,
            'aff_delta' => $delta,
            'type' => strval($entry['type'] ?? ($entry['relationship_type'] ?? ($entry['relation_type'] ?? ''))),
            'note' => strval($entry['note'] ?? ($entry['reason'] ?? ($entry['summary'] ?? ''))),
        ];
    }

    return $updates;
}

function stobeApplyRelationshipUpdatesMap(array $relationshipMap, array $updates, array $allowedTargets = []): array {
    $allowedLookup = [];
    foreach ($allowedTargets as $name) {
        $normalized = normalizeParticipantNameToken(strval($name));
        if ($normalized === '') {
            continue;
        }
        $allowedLookup[strtolower($normalized)] = true;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($playerName === '') {
        $playerName = 'Player';
    }
    if (!isset($allowedLookup[strtolower($playerName)])) {
        $allowedLookup[strtolower($playerName)] = true;
    }
    $allowedLookup['player'] = true;

    $applied = [];
    foreach ($updates as $update) {
        if (!is_array($update)) {
            continue;
        }
        $targetRaw = strval($update['target'] ?? '');
        $target = normalizeParticipantNameToken($targetRaw);
        if ($target === '') {
            continue;
        }
        $targetLower = strtolower($target);
        if ($targetLower === 'player' || $targetLower === 'the player') {
            $target = $playerName;
            $targetLower = strtolower($target);
        }
        if (count($allowedLookup) > 0 && !isset($allowedLookup[$targetLower])) {
            continue;
        }

        $delta = intval($update['aff_delta'] ?? 0);
        if ($delta > 80) {
            $delta = 80;
        } elseif ($delta < -80) {
            $delta = -80;
        }
        $typeCandidate = trim(strval($update['type'] ?? ''));
        $noteCandidate = trim(strval($update['note'] ?? ''));
        if (strlen($noteCandidate) > 180) {
            $noteCandidate = substr($noteCandidate, 0, 180);
        }

        $existingKey = stobeFindRelationshipEntryKey($relationshipMap, $target);
        if ($existingKey === '') {
            $existingKey = $target;
        }
        $existing = is_array($relationshipMap[$existingKey] ?? null) ? $relationshipMap[$existingKey] : [];
        $oldAff = intval($existing['aff'] ?? 0);
        $newAff = $oldAff + $delta;
        if ($newAff > 100) {
            $newAff = 100;
        } elseif ($newAff < -100) {
            $newAff = -100;
        }

        $oldType = stobeNormalizeRelationshipTypeToken(strval($existing['type'] ?? 'neutral'));
        $newType = $oldType;
        if ($typeCandidate !== '') {
            $newType = stobeNormalizeRelationshipTypeToken($typeCandidate);
        }

        $oldNote = trim(strval($existing['note'] ?? ''));
        $newNote = $noteCandidate !== '' ? $noteCandidate : $oldNote;

        $changed = ($newAff !== $oldAff) || ($newType !== $oldType) || ($newNote !== $oldNote);
        if (!$changed) {
            continue;
        }

        $relationshipMap[$existingKey] = [
            'aff' => $newAff,
            'type' => $newType,
            'tier' => stobeRelationshipTierLabel($newAff),
            'note' => $newNote,
            'updated_at' => intval(time()),
        ];

        $applied[] = [
            'target' => $existingKey,
            'delta' => ($newAff - $oldAff),
            'old_aff' => $oldAff,
            'new_aff' => $newAff,
            'type' => $newType,
        ];
    }

    return [
        'map' => $relationshipMap,
        'applied' => $applied,
        'updated' => count($applied),
    ];
}

function stobePersistNpcRelationshipMap(string $speakerName, array $relationshipMap, array|false $npcData = false): bool {
    $normalizedSpeaker = normalizeParticipantNameToken($speakerName);
    if ($normalizedSpeaker === '') {
        return false;
    }
    if (!is_array($npcData)) {
        $npcData = getNpcData($normalizedSpeaker);
    }
    if (!is_array($npcData)) {
        return false;
    }

    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        return false;
    }

    $normalizedMap = stobeNormalizeRelationshipMap($relationshipMap);
    $serializedMap = count($normalizedMap) > 0 ? normalizeJsonString($normalizedMap) : '';

    $db = $GLOBALS["db"];
    $db->exec(
        "UPDATE core_npc
         SET relationships = $1,
             updated_at = NOW()
         WHERE id = $2",
        [$serializedMap, $npcId]
    );

    return true;
}

function stobeEvaluateRelationshipsForTurn(
    string $speakerName,
    string $listenerName,
    string $incomingLine,
    string $responseText,
    array|false $speakerNpcData = false,
    string $eventType = 'chat'
): array {
    $result = [
        'clean_response' => stobeStripRelationshipCommandTags($responseText),
        'method' => 'none',
        'updated' => 0,
        'applied' => [],
        'error' => '',
    ];

    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '' || strcasecmp($speaker, 'The Narrator') === 0) {
        $result['error'] = 'invalid_speaker';
        return $result;
    }
    $hasInlineCommands = preg_match('/#(?:REL|TYPE)\s*:/i', $responseText) === 1;
    if (trim($result['clean_response']) === '' && !$hasInlineCommands) {
        $result['error'] = 'empty_response';
        return $result;
    }

    if (!is_array($speakerNpcData)) {
        $speakerNpcData = getNpcData($speaker);
    }
    if (!is_array($speakerNpcData)) {
        $result['error'] = 'missing_speaker_profile';
        return $result;
    }

    $enabled = getNpcProfileBoolSetting(
        $speakerNpcData,
        ['RELATIONSHIP_SYSTEM_ENABLED'],
        'RELATIONSHIP_SYSTEM_ENABLED',
        true
    );
    if (!$enabled) {
        $result['error'] = 'relationship_system_disabled';
        return $result;
    }

    $listener = normalizeParticipantNameToken($listenerName);
    if ($listener === '') {
        $listener = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    }

    $relationshipMap = stobeNormalizeRelationshipMap(strval($speakerNpcData['relationships'] ?? ''));
    $contextTargets = stobeCollectRelationshipContextTargets($speakerNpcData, $speaker, $listener);
    foreach (array_keys($relationshipMap) as $mapTarget) {
        $contextTargets[] = $mapTarget;
    }
    $contextTargets = array_values(array_unique(array_filter(array_map(
        static fn($value): string => normalizeParticipantNameToken(strval($value)),
        $contextTargets
    ))));

    $updates = [];
    $method = '';
    $connector = getProfileLlmConnectorForNpcByPurpose($speakerNpcData, 'relationship');
    $connectorApiKey = trim(strval($connector['api_badge_key'] ?? ($connector['api_key'] ?? '')));
    if (is_array($connector) && intval($connector['id'] ?? 0) > 0 && $connectorApiKey !== '' && function_exists('stobeCallLLM')) {
        $currentRelationships = [];
        foreach ($contextTargets as $targetName) {
            $key = stobeFindRelationshipEntryKey($relationshipMap, $targetName);
            $entry = $key !== '' ? ($relationshipMap[$key] ?? []) : [];
            $aff = intval($entry['aff'] ?? 0);
            $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
            $currentRelationships[] = $targetName . ': ' . strval($aff) . ' (' . stobeRelationshipTierLabel($aff) . ', ' . $type . ')';
            if (count($currentRelationships) >= 16) {
                break;
            }
        }
        if (count($currentRelationships) === 0) {
            $currentRelationships[] = '(none)';
        }

        $systemPrompt = "<relationship_evaluator>\n"
            . "  <rule>You update only the speaker NPC relationship map for this single turn.</rule>\n"
            . "  <rule>Return strict JSON only: {\"updates\":[{\"target\":\"Name\",\"aff_delta\":-10,\"type\":\"rival\",\"note\":\"short optional note\"}]}</rule>\n"
            . "  <rule>Use at most 3 updates.</rule>\n"
            . "  <rule>Use conservative deltas for normal chat (typically -8..+8). Reserve larger deltas for major events.</rule>\n"
            . "  <rule>Use lowercase one-word types. If type should not change, omit type or leave it empty.</rule>\n"
            . "  <rule>If nothing changed, return {\"updates\":[]}.</rule>\n"
            . "</relationship_evaluator>";

        $userPrompt = "<relationship_turn>\n"
            . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
            . "  <listener>" . stobePromptXmlEscape($listener) . "</listener>\n"
            . "  <event_type>" . stobePromptXmlEscape($eventType) . "</event_type>\n"
            . "  <allowed_targets>" . stobePromptXmlEscape(implode(', ', $contextTargets)) . "</allowed_targets>\n"
            . "  <incoming_line>" . stobePromptXmlEscape($incomingLine) . "</incoming_line>\n"
            . "  <speaker_response>" . stobePromptXmlEscape($result['clean_response']) . "</speaker_response>\n"
            . "  <current_relationships>" . stobePromptXmlEscape(implode(' | ', $currentRelationships)) . "</current_relationships>\n"
            . "</relationship_turn>";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        $relConfig = getLlmConfigForNpcPurpose($speakerNpcData, 'relationship');
        $rawEval = stobeCallLLM($messages, $relConfig, [
            'npc_name' => $speaker,
            'event_type' => 'relationship_eval',
            'response_format' => ['type' => 'json_object'],
        ]);
        if ($rawEval !== false && trim(strval($rawEval)) !== '') {
            $updates = stobeParseRelationshipEvalUpdates(strval($rawEval));
            if (count($updates) > 0) {
                $method = 'connector';
            } else {
                $result['error'] = 'connector_empty_updates';
            }
        } else {
            $result['error'] = 'connector_eval_failed';
        }
    } elseif (is_array($connector) && intval($connector['id'] ?? 0) > 0 && $connectorApiKey !== '' && !function_exists('stobeCallLLM')) {
        $result['error'] = 'callllm_unavailable';
    }

    if (count($updates) === 0) {
        $parsedTags = stobeParseRelationshipCommandTags($responseText);
        $updates = is_array($parsedTags['updates'] ?? null) ? $parsedTags['updates'] : [];
        if (count($updates) > 0) {
            $method = 'command_tags';
        }
    }

    if (count($updates) === 0) {
        if ($method === '') {
            $method = 'none';
        }
        $result['method'] = $method;
        stobeLogDebug('Relationship evaluation skipped/no changes', [
            'speaker' => $speaker,
            'listener' => $listener,
            'event_type' => $eventType,
            'method' => $method,
            'error' => $result['error'],
        ]);
        return $result;
    }

    $applied = stobeApplyRelationshipUpdatesMap($relationshipMap, $updates, $contextTargets);
    $updatedCount = intval($applied['updated'] ?? 0);
    $appliedRows = is_array($applied['applied'] ?? null) ? $applied['applied'] : [];
    if ($updatedCount <= 0) {
        $result['method'] = $method !== '' ? $method : 'none';
        $result['updated'] = 0;
        $result['applied'] = [];
        return $result;
    }

    $persisted = stobePersistNpcRelationshipMap($speaker, is_array($applied['map'] ?? null) ? $applied['map'] : [], $speakerNpcData);
    if (!$persisted) {
        stobeLogWarn('Relationship updates computed but not persisted', [
            'speaker' => $speaker,
            'listener' => $listener,
            'event_type' => $eventType,
            'method' => $method,
            'updated' => $updatedCount,
        ]);
        $result['error'] = 'persist_failed';
    }

    $result['method'] = $method !== '' ? $method : 'unknown';
    $result['updated'] = $updatedCount;
    $result['applied'] = $appliedRows;

    stobeLogInfo('Relationship updates applied', [
        'speaker' => $speaker,
        'listener' => $listener,
        'event_type' => $eventType,
        'method' => $result['method'],
        'updated' => $updatedCount,
        'applied' => $appliedRows,
    ]);

    return $result;
}

function stobeBuildNpcEquipmentInventoryText(array $npcData, array $metadata): array {
    $inventoryContext = buildInventoryContextFromMetadata($metadata);

    $equipment = trim(strval($inventoryContext['equipment'] ?? ''));
    $usedEquipmentFallback = false;
    if ($equipment === '') {
        $equipment = trim(strval($npcData['equipment'] ?? ''));
        $usedEquipmentFallback = true;
    }
    if ($usedEquipmentFallback) {
        $equipment = stobeEnrichItemCsvWithDescriptions($equipment, 14, 0, 4000);
    }
    if ($equipment === '') {
        $equipment = 'No notable equipment recorded.';
    }

    $inventory = trim(strval($inventoryContext['inventory'] ?? ''));
    $usedInventoryFallback = false;
    if ($inventory === '' && !boolval($inventoryContext['has_items'] ?? false)) {
        $inventory = trim(strval($npcData['inventory'] ?? ''));
        $usedInventoryFallback = true;
    }
    if ($usedInventoryFallback) {
        $inventory = stobeEnrichItemCsvWithDescriptions($inventory, 16, 0, 4500);
    }
    if ($inventory === '') {
        $inventory = 'No notable inventory recorded.';
    }

    return [
        'equipment' => truncatePromptValue($equipment, 1800),
        'inventory' => truncatePromptValue($inventory, 1800),
    ];
}

function stobeExtractMiddleTermMemoryEntriesFromExtendedData(array $npcData, int $maxEntries): array {
    if ($maxEntries < 1) {
        $maxEntries = 1;
    } elseif ($maxEntries > 8) {
        $maxEntries = 8;
    }

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $rawEntries = $extended['middle_term_memory'] ?? [];
    if (!is_array($rawEntries) || count($rawEntries) === 0) {
        return [];
    }

    $entries = [];
    foreach ($rawEntries as $entry) {
        if (!is_scalar($entry) || $entry === null) {
            continue;
        }
        $text = trim(sanitizeForKenshi(strval($entry)));
        if ($text === '') {
            continue;
        }
        $entries[] = truncatePromptValue($text, 1400);
    }

    if (count($entries) === 0) {
        return [];
    }

    if (count($entries) > $maxEntries) {
        $entries = array_slice($entries, -$maxEntries);
    }

    return array_values($entries);
}

function stobeMemorySummaryTableAvailable(): bool {
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.memory_summary') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeFetchMiddleTermMemorySummaryEntries(string $npcName, array $npcData, int $maxEntries): array {
    if ($maxEntries < 1) {
        $maxEntries = 1;
    } elseif ($maxEntries > 8) {
        $maxEntries = 8;
    }

    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    if (!stobeMemorySummaryTableAvailable()) {
        return [];
    }

    $quotedNpc = strtolower(json_encode($safeNpcName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($quotedNpc === '') {
        $quotedNpc = '"' . strtolower($safeNpcName) . '"';
    }

    $rows = $db->fetchAll(
        "SELECT summary
         FROM memory_summary
         WHERE (
                LOWER(people) = LOWER($1)
                OR POSITION($2 IN LOWER(COALESCE(people, ''))) > 0
               )
           AND summary IS NOT NULL
           AND BTRIM(summary) <> ''
         ORDER BY COALESCE(period_end, created_at) DESC, created_at DESC
         LIMIT " . intval($maxEntries),
        [$safeNpcName, $quotedNpc]
    );

    if (!is_array($rows) || count($rows) === 0) {
        return [];
    }

    $entries = [];
    foreach ($rows as $row) {
        $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
        if ($summary === '') {
            continue;
        }
        $entries[] = truncatePromptValue($summary, 1400);
    }

    if (count($entries) === 0) {
        return [];
    }

    // DB query is newest-first; keep prompt entries oldest->newest for readability.
    return array_values(array_reverse($entries));
}

function stobeBuildMiddleTermMemoryPromptBlock(array $npcData, string $npcName): string {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || strcasecmp($safeNpcName, 'The Narrator') === 0) {
        return '';
    }

    $enabled = getNpcProfileBoolSetting(
        $npcData,
        ['middle_term_enabled', 'MIDDLE_TERM_MEMORY_ENABLED'],
        'MIDDLE_TERM_MEMORY_ENABLED',
        true
    );
    if (!$enabled) {
        return '';
    }

    $maxEntries = getNpcProfileIntegerSetting(
        $npcData,
        ['MEMORY_CONTEXT_SIZE'],
        'MEMORY_CONTEXT_SIZE',
        1,
        1,
        8
    );

    $entries = stobeExtractMiddleTermMemoryEntriesFromExtendedData($npcData, $maxEntries);
    if (count($entries) === 0) {
        $entries = stobeFetchMiddleTermMemorySummaryEntries($safeNpcName, $npcData, $maxEntries);
    }
    if (count($entries) === 0) {
        return '';
    }

    $lines = [];
    $lines[] = '<middle_term_memory>';
    $lines[] = 'Past events';
    if (count($entries) === 1) {
        $lines[] = stobePromptXmlEscape($entries[0]);
    } else {
        foreach ($entries as $entry) {
            $lines[] = '- ' . stobePromptXmlEscape($entry);
        }
    }
    $lines[] = '</middle_term_memory>';
    return implode("\n", $lines);
}

function buildSystemPrompt(
    string $npcName,
    array $npcData,
    string $playerName,
    string $playerMessage = '',
    bool $includeActionGuidance = true,
    string $eventType = 'chat',
    int $currentGamets = 0
): string {
    $template = loadPromptTemplate('prompt_chat.txt');
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $npcRace = trim(strval($npcData['race'] ?? ''));
    if ($npcRace === '') {
        $npcRace = 'Unknown';
    }
    $npcFaction = stobeFactionDisplayName(strval($npcData['faction'] ?? ''));
    if ($npcFaction === '') {
        $npcFaction = 'Unknown';
    }
    $npcPersonality = trim(strval($npcData['personality'] ?? ''));
    if ($npcPersonality === '') {
        $npcPersonality = 'Pragmatic wasteland survivor.';
    }
    $npcBackstory = trim(strval($npcData['backstory'] ?? ''));
    if ($npcBackstory === '') {
        $npcBackstory = 'A drifter surviving the harsh world of Kenshi.';
    }
    $npcSpeechStyle = trim(strval($npcData['speechstyle'] ?? ''));
    if ($npcSpeechStyle === '') {
        $npcSpeechStyle = 'Direct and practical.';
    }
    $npcGoals = trim(strval($npcData['goals'] ?? ''));
    if ($npcGoals === '') {
        $npcGoals = 'Survive and adapt to the wasteland.';
    }
    $playerCatsRaw = trim(getConfOpt('PLAYER_CATS', '0'));
    if ($playerCatsRaw === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $playerCatsRaw) !== 1) {
        $playerCatsRaw = '0';
    }
    $playerCats = strval(max(0, intval(floatval($playerCatsRaw))));
    $inPlayerFaction = npcIsInPlayerFaction($npcData);
    $npcEquipmentInventory = stobeBuildNpcEquipmentInventoryText($npcData, $metadata);
    $npcOccupation = trim(strval($npcData['occupation'] ?? ''));
    if ($npcOccupation !== '') {
        $npcOccupation = preg_replace('/\s*\[default[^\]]*factionsid\]\s*/iu', ' ', $npcOccupation) ?? $npcOccupation;
        $npcOccupation = trim(preg_replace('/\s+/u', ' ', $npcOccupation) ?? $npcOccupation);
    }
    if ($npcOccupation === '') {
        $npcOccupation = 'Wasteland drifter.';
    }
    $npcRelationships = stobeBuildNpcRelationshipsText($npcName, $playerName, $npcData);
    $npcAppearance = stobeBuildNpcAppearanceText($npcData);
    $npcBountyBlock = stobeBuildNpcBountyPromptBlock($npcData);
    $npcSkills = stobeBuildNpcSkillsText($npcData);
    $npcCondition = stobeBuildNpcConditionText($npcData, $metadata);
    $regularMemoryBlock = '';
    if (function_exists('stobeBuildRegularMemoryPromptBlock')) {
        $regularMemoryBlock = stobeBuildRegularMemoryPromptBlock(
            $npcData,
            $npcName,
            $playerMessage,
            $currentGamets
        );
    }
    $middleTermMemoryBlock = stobeBuildMiddleTermMemoryPromptBlock($npcData, $npcName);
    $memoryBlocks = trim($regularMemoryBlock . ($regularMemoryBlock !== '' && $middleTermMemoryBlock !== '' ? "\n\n" : '') . $middleTermMemoryBlock);
    $promptOverrides = stobeResolveNpcPromptOverrides($npcData, $metadata);
    $promptHeadOverride = trim(strval($promptOverrides['prompt_head'] ?? ''));
    $profilePromptOverride = trim(strval($promptOverrides['profile_prompt'] ?? ''));

    $roleplayInstructions = stobeBuildRoleplayInstructionsText($npcName, $playerName, $npcData);
    if ($promptHeadOverride !== '') {
        $roleplayInstructions = trim($promptHeadOverride . "\n\n" . $roleplayInstructions);
    }

    $generalInstructions = stobeBuildGeneralInstructionsText($npcData);
    if ($profilePromptOverride !== '') {
        $generalInstructions = trim($profilePromptOverride . "\n" . $generalInstructions);
    }

    $replacements = [
        '#ROLEPLAY_INSTRUCTIONS#' => stobePromptXmlEscape($roleplayInstructions),
        '#NPC_NAME#' => stobePromptXmlEscape($npcName),
        '#NPC_RACE#' => stobePromptXmlEscape($npcRace),
        '#NPC_FACTION#' => stobePromptXmlEscape($npcFaction),
        '#NPC_PERSONALITY#' => stobePromptXmlEscape($npcPersonality),
        '#NPC_BACKSTORY#' => stobePromptXmlEscape($npcBackstory),
        '#NPC_APPEARANCE#' => stobePromptXmlEscape($npcAppearance),
        '#NPC_EQUIPMENT#' => stobePromptXmlEscape(strval($npcEquipmentInventory['equipment'] ?? '')),
        '#NPC_INVENTORY#' => stobePromptXmlEscape(strval($npcEquipmentInventory['inventory'] ?? '')),
        '#NPC_CURRENT_CONDITION#' => stobePromptXmlEscape($npcCondition),
        '#NPC_RELATIONSHIPS#' => stobePromptXmlEscape($npcRelationships),
        '#NPC_OCCUPATION#' => stobePromptXmlEscape($npcOccupation),
        '#NPC_BOUNTY_BLOCK#' => $npcBountyBlock,
        '#NPC_SKILLS#' => $npcSkills,
        '#NPC_SPEECHSTYLE#' => stobePromptXmlEscape($npcSpeechStyle),
        '#NPC_GOALS#' => stobePromptXmlEscape($npcGoals),
        '#NPC_MIDDLE_TERM_MEMORY#' => $memoryBlocks,
        '#PLAYER_NAME#' => stobePromptXmlEscape($playerName),
        '#PLAYER_CATS#' => stobePromptXmlEscape($playerCats),
        '#GENERAL_INSTRUCTIONS#' => stobePromptXmlEscape($generalInstructions),
    ];

    $prompt = str_replace(array_keys($replacements), array_values($replacements), $template);
    $worldStateBlock = buildWorldStateBlock($npcData);
    if (strpos($prompt, '#NPC_CHARACTER_STATE#') !== false) {
        $prompt = str_replace('#NPC_CHARACTER_STATE#', $worldStateBlock, $prompt);
    } elseif ($worldStateBlock !== '') {
        $prompt .= "\n\n" . $worldStateBlock;
    }
    if ($inPlayerFaction) {
        $prompt .= "\n\n<player_faction_funds>\n"
            . "  <cats>" . stobePromptXmlEscape($playerCats) . "</cats>\n"
            . "  <note>cats is the currently available shared funds for this character's player-side squad/faction.</note>\n"
            . "</player_faction_funds>";
    }

    $loreHints = queryWorldKnowledgeForNpc($npcName, $playerMessage, 3, $npcData, $eventType);
    if (count($loreHints) > 0) {
        $prompt .= "\n\n<knowledge>";
        foreach ($loreHints as $hint) {
            $prompt .= "\n  <entry>" . stobePromptXmlEscape($hint) . "</entry>";
        }
        $prompt .= "\n</knowledge>";
    }

    if ($includeActionGuidance) {
        $prompt = appendActionGuidanceToPrompt($prompt, $eventType, $npcData);
    }

    $scenePromptBlock = stobeBuildScenePromptBlock($npcData, $npcName);
    if ($scenePromptBlock !== '') {
        $prompt .= "\n\n" . $scenePromptBlock;
    }

    return $prompt;
}

function buildAutochatRewriteSpeakerPrompt(
    string $speakerName,
    string $targetNpc,
    array $targetNpcData,
    string $sourceMessage
): string {
    $speakerNpcData = getNpcData($speakerName);
    if (is_array($speakerNpcData)) {
        return buildSystemPrompt($speakerName, $speakerNpcData, $targetNpc, $sourceMessage, false, 'chat');
    }

    $effectiveSpeaker = trim($speakerName);
    if ($effectiveSpeaker === '') {
        $effectiveSpeaker = getSetting('PLAYER_NAME', 'Drifter');
    }

    $playerBio = trim(strval(getSetting('PLAYER_BIOS', 'I am #PLAYER_NAME#, a wanderer in the wastelands of Kenshi.')));
    if ($playerBio !== '') {
        $playerBio = str_replace('#PLAYER_NAME#', $effectiveSpeaker, $playerBio);
    }

    $targetPrompt = buildSystemPrompt($targetNpc, $targetNpcData, $effectiveSpeaker, $sourceMessage, false, 'chat');

    $lines = [
        '<autochat_rewrite_context>',
        '  <speaker>' . stobePromptXmlEscape($effectiveSpeaker) . '</speaker>',
        '  <target>' . stobePromptXmlEscape($targetNpc) . '</target>',
    ];
    if ($playerBio !== '') {
        $lines[] = '  <speaker_background>' . stobePromptXmlEscape($playerBio) . '</speaker_background>';
    }
    $lines[] = '</autochat_rewrite_context>';
    $lines[] = '<target_context>';
    $lines[] = $targetPrompt;
    $lines[] = '</target_context>';

    return implode("\n", $lines);
}

function rewriteSpeakerMessageForAutochat(
    string $speakerName,
    string $targetNpc,
    array $targetNpcData,
    string $sourceMessage,
    string $historyText = ''
): array {
    $original = trim($sourceMessage);
    if ($original === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'empty_source',
            'model' => '',
        ];
    }

    $llmConfig = getLlmConfigForNpcPurpose($targetNpcData, 'autochat');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'missing_api_key',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    $speakerPrompt = buildAutochatRewriteSpeakerPrompt(
        $speakerName,
        $targetNpc,
        $targetNpcData,
        $original
    );

    $systemPrompt = $speakerPrompt
        . "\n\n<autochat_rewrite_mode>\n"
        . "  <rule>Rewrite the source line into an in-character Kenshi dialogue reply for the speaker.</rule>\n"
        . "  <rule>Preserve the intent and core meaning of the source line.</rule>\n"
        . "  <rule>Keep it concise (1-2 sentences).</rule>\n"
        . "  <rule>Do not add stage directions or action tags.</rule>\n"
        . "  <rule>Do not include a speaker prefix like \"Name:\".</rule>\n"
        . "  <rule>Output dialogue text only.</rule>\n"
        . "</autochat_rewrite_mode>";

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
    ];
    if (trim($historyText) !== '') {
        $historyMessages = stobeBuildRecentContextMessagesFromText($historyText, 24);
        foreach ($historyMessages as $historyMessage) {
            $messages[] = $historyMessage;
        }
    }
    $messages[] = [
        'role' => 'user',
        'content' => "<source_line>\n"
            . "  <speaker>" . stobePromptXmlEscape($speakerName) . "</speaker>\n"
            . "  <text>" . stobePromptXmlEscape($original) . "</text>\n"
            . "</source_line>",
    ];
    $messages[] = [
        'role' => 'user',
        'content' => '<output_format><requirement>Return only the rewritten dialogue line.</requirement></output_format>',
    ];

    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $targetNpc,
        'event_type' => 'autochat_rewrite',
        'speaker' => $speakerName,
    ]);
    if ($raw === false || trim(strval($raw)) === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'empty_rewrite_response',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    $rewritten = sanitizeForKenshi(trim(strval($raw)));
    $trimmedSpeaker = trim($speakerName);
    if ($trimmedSpeaker !== '') {
        $prefixPattern = '/^\s*' . preg_quote($trimmedSpeaker, '/') . '\s*:\s*/iu';
        $rewritten = preg_replace($prefixPattern, '', $rewritten) ?? $rewritten;
    }
    $rewritten = trim($rewritten, " \t\n\r\0\x0B\"'");

    if ($rewritten === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'rewrite_empty_after_sanitize',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    return [
        'message' => $rewritten,
        'rewritten' => strcasecmp($rewritten, $original) !== 0,
        'error' => '',
        'model' => strval($llmConfig['model'] ?? ''),
    ];
}

function extractDialogueTarget(string $source): array {
    $text = trim($source);
    if ($text === '') {
        return ['target' => '', 'cleaned' => ''];
    }

    if (preg_match('/\(talking to:\s*([^\)]+)\)/i', $text, $matches) === 1) {
        $target = trim(strval($matches[1] ?? ''));
        $cleaned = trim(preg_replace('/\s*\(talking to:\s*[^\)]+\)\s*/i', ' ', $text) ?? '');
        return [
            'target' => normalizeParticipantNameToken($target),
            'cleaned' => $cleaned,
        ];
    }

    return ['target' => '', 'cleaned' => $text];
}

function parseDialogueEventData(string $eventData): array {
    $targetExtract = extractDialogueTarget($eventData);
    $cleaned = trim(strval($targetExtract['cleaned'] ?? ''));
    $target = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));

    $speaker = '';
    $message = $cleaned;
    $parts = explode(': ', $cleaned, 2);
    if (count($parts) === 2) {
        $speaker = normalizeParticipantNameToken(strval($parts[0] ?? ''));
        $message = trim(strval($parts[1] ?? ''));
    }

    if ($speaker === '') {
        $colonPos = strpos($cleaned, ':');
        if ($colonPos !== false && $colonPos > 0) {
            $speaker = normalizeParticipantNameToken(substr($cleaned, 0, $colonPos));
            $message = trim(substr($cleaned, $colonPos + 1));
        }
    }

    return [
        'speaker' => $speaker,
        'message' => trim($message),
        'target' => $target,
        'cleaned' => $cleaned,
    ];
}

function buildActionEventData(string $actor, string $actionToken, string $target = '', string $source = ''): string {
    $safeActor = normalizeParticipantNameToken($actor);
    if ($safeActor === '') {
        $safeActor = 'Unknown';
    }

    $safeAction = trim(strval($actionToken));
    $line = $safeActor . ': ' . $safeAction;

    $safeTarget = normalizeParticipantNameToken($target);
    if ($safeTarget !== '') {
        $line .= ' (talking to: ' . $safeTarget . ')';
    }

    $safeSource = trim(strval($source));
    if ($safeSource !== '') {
        $line .= ' [source: ' . strtolower($safeSource) . ']';
    }

    return $line;
}

function storeActionEvents(
    string $actor,
    array $actions,
    int $gamets,
    string $target = '',
    string $sourceEventType = 'chat'
): int {
    if (count($actions) === 0) {
        return 0;
    }

    $source = strtolower(trim($sourceEventType));
    if ($source === '') {
        $source = 'chat';
    }
    $actorData = getNpcData($actor);
    $config = stobeBuildActionConfigForNpc($source, $actorData);

    $stored = 0;
    $seen = [];
    foreach ($actions as $rawAction) {
        $normalized = normalizeActionTagToken(strval($rawAction), $config);
        if ($normalized === '') {
            continue;
        }
        $dispatchAction = stobeTransformActionForDispatch($normalized, $actorData);
        if ($dispatchAction === '') {
            continue;
        }
        $key = strtolower($dispatchAction);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $actionEventData = buildActionEventData($actor, $dispatchAction, $target, $source);
        storeEvent('action', time(), $gamets, $actionEventData);
        $stored++;
    }

    if ($stored > 0) {
        stobeLogInfo('Action events stored', [
            'actor' => $actor,
            'target' => $target,
            'source_event_type' => $source,
            'stored' => $stored,
        ]);
    }

    return $stored;
}

function buildRechatSystemPrompt(
    string $npcName,
    array $npcData,
    string $previousSpeaker,
    string $previousMessage,
    string $previousTarget = '',
    int $currentGamets = 0
): string {
    $speakerForBasePrompt = $previousSpeaker !== '' ? $previousSpeaker : getSetting('PLAYER_NAME', 'Drifter');
    $prompt = buildSystemPrompt($npcName, $npcData, $speakerForBasePrompt, $previousMessage, true, 'rechat', $currentGamets);

    $guidance = [
        'This is an NPC-to-NPC rechat turn.',
        "Respond naturally to {$speakerForBasePrompt}, who just spoke.",
        'Address exactly one listener and keep continuity with the last line.',
        'Keep the response concise and in-character for Kenshi.',
    ];
    if ($previousTarget !== '') {
        $guidance[] = "The previous line was directed at: {$previousTarget}.";
    }

    $xml = ["<rechat_mode>"];
    foreach ($guidance as $rule) {
        $xml[] = '  <rule>' . stobePromptXmlEscape($rule) . '</rule>';
    }
    $xml[] = '</rechat_mode>';

    return $prompt . "\n\n" . implode("\n", $xml);
}

function formatResponse(string $actor, string $action, string $message, string $ttsHash = '', int $ttsDurationMs = 0): string {
    if ($ttsHash !== '') {
        if ($ttsDurationMs > 0) {
            return "{$actor}|{$action}|{$message}|tts={$ttsHash}|ttsd={$ttsDurationMs}\r\n";
        }
        return "{$actor}|{$action}|{$message}|tts={$ttsHash}\r\n";
    }
    return "{$actor}|{$action}|{$message}\r\n";
}

function stobeGetOutputToPluginLogPath(): string {
    if (function_exists('stobeGetLogPath')) {
        return stobeGetLogPath('output_to_plugin.log');
    }
    $enginePath = $GLOBALS['ENGINE_PATH'] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $logDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    return $logDir . 'output_to_plugin.log';
}

function stobeLogOutputToPlugin(
    string $actor,
    string $action,
    string $message,
    string $wirePayload,
    string $ttsHash = '',
    int $ttsDurationMs = 0
): void {
    $entry = [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'actor' => $actor,
        'action' => $action,
        'message' => $message,
        'tts_hash' => $ttsHash,
        'tts_duration_ms' => $ttsDurationMs,
        'wire_payload' => trim($wirePayload),
    ];
    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($json) || $json === '') {
        return;
    }
    $line = '[' . gmdate('Y-m-d H:i:s') . '] [INFO] output_to_plugin | ' . $json . PHP_EOL;
    @file_put_contents(stobeGetOutputToPluginLogPath(), $line, FILE_APPEND | LOCK_EX);
}

function stobeIsTtsEnabledForCurrentRequest(): bool {
    static $resolved = null;
    if ($resolved !== null) {
        return boolval($resolved);
    }

    $requestRaw = $_GET['tts_enabled'] ?? $_POST['tts_enabled'] ?? null;
    if ($requestRaw === null) {
        $resolved = true;
        return boolval($resolved);
    }

    $normalized = strtolower(trim(strval($requestRaw)));
    if ($normalized === '') {
        $resolved = true;
        return boolval($resolved);
    }

    $requestEnabled = !in_array($normalized, ['0', 'false', 'off', 'no', 'disabled'], true);
    $resolved = $requestEnabled;
    return boolval($resolved);
}

function stobeGetStreamSentenceMaxSize(): int {
    if (defined('MAXIMUM_SENTENCE_SIZE')) {
        return max(32, intval(MAXIMUM_SENTENCE_SIZE));
    }
    return 125;
}

function stobeGetStreamSentenceMinSize(): int {
    if (defined('MINIMUM_SENTENCE_SIZE')) {
        return max(20, intval(MINIMUM_SENTENCE_SIZE));
    }
    return 75;
}

function stobeStreamSentenceSplitRegex(): string {
    // Includes Latin and CJK sentence punctuation.
    return '/(?<=[.?!。？！])(?<!\.\.)(?<!\.\.\.)\s+/u';
}

function stobeFindFastSentencePosition(string $text): int|false {
    if ($text === '') {
        return false;
    }
    if (preg_match('/([.?!。？！])(?<!\.\.)(?<!\.\.\.)\s+/u', $text, $matches, PREG_OFFSET_CAPTURE) === 1) {
        return intval($matches[1][1] ?? -1);
    }
    return false;
}

function stobeSplitSentencesStream(string $paragraph): array {
    $text = trim(strval($paragraph));
    if ($text === '') {
        return [];
    }

    $maxSize = stobeGetStreamSentenceMaxSize();
    $minSize = stobeGetStreamSentenceMinSize();
    if (strlen($text) <= $maxSize) {
        return [$text];
    }

    $sentences = preg_split(stobeStreamSentenceSplitRegex(), $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($sentences) || count($sentences) === 0) {
        return [$text];
    }

    $chunks = [];
    $current = '';
    foreach ($sentences as $sentenceRaw) {
        $sentence = trim(strval($sentenceRaw));
        if ($sentence === '') {
            continue;
        }
        if ($current === '') {
            $current = $sentence;
            continue;
        }
        $combined = $current . ' ' . $sentence;
        if (strlen($combined) > $maxSize) {
            $chunks[] = $current;
            $current = $sentence;
            continue;
        }
        $current = $combined;
        if (strlen($current) >= $minSize) {
            $chunks[] = $current;
            $current = '';
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return count($chunks) > 0 ? $chunks : [$text];
}

function stobeStripParentheticalDialogueText(string $text): string {
    $clean = sanitizeForKenshi(trim(strval($text)));
    if ($clean === '') {
        return '';
    }

    // Remove parenthetical stage directions, including repeated nested groups.
    $previous = null;
    while ($previous !== $clean) {
        $previous = $clean;
        $clean = preg_replace('/\([^()]*\)/u', ' ', $clean) ?? $clean;
    }

    // Remove XML/HTML-like metadata tags that should never be spoken.
    $previous = null;
    while ($previous !== $clean) {
        $previous = $clean;
        $clean = preg_replace('/<([a-z][a-z0-9:_-]*)(?:\s[^<>]*)?>[\s\S]*?<\/\1>/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<\/?[a-z][^>]*>/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<[^>\r\n]{1,240}>/u', ' ', $clean) ?? $clean;
    }

    // Remove leaked inline action tags (e.g. FACTION_RELATIONS@Target@100).
    $commandNames = [
        'ATTACK', 'FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY',
        'LEAVE', 'IDLE', 'STOP_CARRYING', 'RELEASE_PLAYER', 'RELEASE_PRISONER', 'SUICIDE',
        'GIVE_CATS', 'TAKE_CATS', 'TAKE_ITEM', 'GIVE_ITEM', 'DROP_ITEM', 'REMOVE_LIMB', 'KILL', 'USE_OBJECT', 'USE_DRUGS', 'DRINK_ITEM', 'DRINK', 'TRAVEL_LOCATION',
        'ROLEPLAY_ACTION', 'NOTIFY', 'FACTION_RELATIONS', 'TASK', 'TALK',
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC',
        'STOPFOLLOW', 'JOINPARTY', 'STOPCARRYING', 'RELEASEPLAYER', 'GIVECATS', 'TAKECATS',
        'TAKEITEM', 'GIVEITEM', 'DROPITEM', 'REMOVELIMB', 'KILLTARGET', 'EXECUTE', 'MURDER', 'USEOBJECT', 'USE-OBJECT', 'USEDRUGS', 'USE-DRUGS', 'DRINKITEM', 'DRINK-ITEM', 'FACTIONRELATIONS', 'TRAVELLOCATION',
        'ROLEPLAYACTION', 'ROLEPLAY-ACTION',
        'SETBLOCK', 'SETHOLD', 'SETPASSIVE', 'SETJOBS', 'SETRANGED',
        'SETTAUNT', 'SETSNEAK', 'SETRESOURCE', 'SETMEDIC',
    ];
    $commandAlternation = implode('|', $commandNames);
    $clean = preg_replace('/(?:^|[\s>])(?:' . $commandAlternation . ')\s*@[^\r\n<]*/iu', ' ', $clean) ?? $clean;

    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s+([,.;:!?])/u', '$1', $clean) ?? $clean;
    return trim($clean);
}

function stobeDedupeActionList(array $actions, string $eventType, ?array $configOverride = null): array {
    $config = is_array($configOverride) ? $configOverride : getActionRuntimeConfig($eventType);
    $normalized = [];
    $seen = [];
    foreach ($actions as $rawAction) {
        $parsed = normalizeActionTagToken(strval($rawAction), $config);
        if ($parsed === '') {
            continue;
        }
        $key = strtolower($parsed);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $parsed;
    }
    return $normalized;
}

function stobeStreamDialogueViaLlm(
    string $actor,
    array|false $actorData,
    array $messages,
    array $llmConfig,
    string $eventType = 'chat',
    array $meta = []
): array {
    $result = [
        'ok' => false,
        'used_streaming' => false,
        'raw_response' => '',
        'response_text' => '',
        'actions' => [],
        'actions_streamed' => false,
        'structured_json' => false,
        'chunks_emitted' => 0,
    ];

    if (!function_exists('stobeCallLLMStream')) {
        return $result;
    }

    $actionConfig = is_array($meta['action_config'] ?? null)
        ? $meta['action_config']
        : stobeBuildActionConfigForNpc($eventType, $actorData);

    $rawResponse = '';
    $streamBuffer = '';
    $chunksEmitted = 0;
    $streamActionSeen = [];
    $rawActions = [];

    $streamMeta = $meta;
    unset($streamMeta['response_format']);

    $streamed = stobeCallLLMStream(
        $messages,
        $llmConfig,
        function (string $delta) use (&$rawResponse, &$streamBuffer, &$chunksEmitted, $actor, $actorData, $eventType, $actionConfig, &$streamActionSeen, &$rawActions): void {
            if ($delta === '') {
                return;
            }
            $rawResponse .= $delta;
            $streamBuffer .= $delta;

            while (true) {
                $position = stobeFindFastSentencePosition($streamBuffer);
                if ($position === false || $position < 0) {
                    break;
                }

                $extracted = trim(substr($streamBuffer, 0, $position + 1));
                $remaining = substr($streamBuffer, $position + 1);
                $streamBuffer = ltrim(strval($remaining));
                if ($extracted === '') {
                    continue;
                }

                $chunks = stobeSplitSentencesStream($extracted);
                foreach ($chunks as $chunkRaw) {
                    $chunk = sanitizeForKenshi(trim(strval($chunkRaw)));
                    if ($chunk === '') {
                        continue;
                    }
                    $chunkExtraction = extractAndNormalizeActionTags($chunk, $eventType, $actionConfig);
                    $chunkActions = is_array($chunkExtraction['actions'] ?? null)
                        ? $chunkExtraction['actions']
                        : [];
                    foreach ($chunkActions as $chunkAction) {
                        $normalizedChunkAction = trim(strval($chunkAction));
                        if ($normalizedChunkAction === '') {
                            continue;
                        }
                        $rawActions[] = $normalizedChunkAction;
                        $seenKey = strtolower($normalizedChunkAction);
                        if (!isset($streamActionSeen[$seenKey])) {
                            $streamActionSeen[$seenKey] = true;
                            streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedChunkAction]);
                        }
                    }
                    $chunkText = sanitizeForKenshi(trim(strval($chunkExtraction['text'] ?? $chunk)));
                    $chunkText = stobeStripParentheticalDialogueText($chunkText);
                    if ($chunkText !== '') {
                        streamResponse($actor, 'ScriptQueue', $chunkText, $actorData, []);
                        $chunksEmitted++;
                    }
                }
            }
        },
        $streamMeta
    );

    if ($streamed === false) {
        return $result;
    }

    if (is_string($streamed) && trim($streamed) !== '') {
        $rawResponse = $streamed;
    }

    $structured = stobeParseStructuredDialogueResponse($rawResponse, $eventType);
    $isStructured = boolval($structured['is_structured'] ?? false);

    $responseText = '';
    if ($isStructured) {
        $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
        $structuredAction = trim(strval($structured['action_tag'] ?? ''));
        if ($structuredAction !== '') {
            $rawActions[] = $structuredAction;
        }
        $inlineExtraction = extractAndNormalizeActionTags($responseText, $eventType, $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($inlineExtraction['text'] ?? $responseText)));
        if (is_array($inlineExtraction['actions'] ?? null)) {
            foreach ($inlineExtraction['actions'] as $inlineAction) {
                $rawActions[] = strval($inlineAction);
            }
        }
    } else {
        $fullExtraction = extractAndNormalizeActionTags($rawResponse, $eventType, $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($fullExtraction['text'] ?? $rawResponse)));
        if (is_array($fullExtraction['actions'] ?? null)) {
            foreach ($fullExtraction['actions'] as $inlineAction) {
                $rawActions[] = strval($inlineAction);
            }
        }
    }

    $responseText = stobeStripParentheticalDialogueText($responseText);

    $remaining = trim($streamBuffer);
    if ($remaining !== '') {
        $remainingExtraction = extractAndNormalizeActionTags($remaining, $eventType, $actionConfig);
        $remainingActions = is_array($remainingExtraction['actions'] ?? null)
            ? $remainingExtraction['actions']
            : [];
        foreach ($remainingActions as $remainingAction) {
            $normalizedRemainingAction = trim(strval($remainingAction));
            if ($normalizedRemainingAction === '') {
                continue;
            }
            $rawActions[] = $normalizedRemainingAction;
            $seenKey = strtolower($normalizedRemainingAction);
            if (!isset($streamActionSeen[$seenKey])) {
                $streamActionSeen[$seenKey] = true;
                streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedRemainingAction]);
            }
        }
        $remainingText = sanitizeForKenshi(trim(strval($remainingExtraction['text'] ?? '')));
        $remainingText = stobeStripParentheticalDialogueText($remainingText);
        if ($remainingText !== '') {
            streamResponse($actor, 'ScriptQueue', $remainingText, $actorData, []);
            $chunksEmitted++;
        }
    }

    $dedupedActions = stobeDedupeActionList($rawActions, $eventType, $actionConfig);
    foreach ($dedupedActions as $dedupedAction) {
        $normalizedAction = trim(strval($dedupedAction));
        if ($normalizedAction === '') {
            continue;
        }
        $seenKey = strtolower($normalizedAction);
        if (!isset($streamActionSeen[$seenKey])) {
            $streamActionSeen[$seenKey] = true;
            streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedAction]);
        }
    }

    $result['ok'] = true;
    $result['used_streaming'] = true;
    $result['raw_response'] = trim($rawResponse);
    $result['response_text'] = $responseText;
    $result['actions'] = $dedupedActions;
    $result['actions_streamed'] = count($streamActionSeen) > 0;
    $result['structured_json'] = $isStructured;
    $result['chunks_emitted'] = $chunksEmitted;
    return $result;
}

function streamResponse(
    string $actor,
    string $action,
    string $message,
    array|false $actorData = false,
    array $actions = []
): void {
    // Normalize accidental raw JSON payloads (including truncated JSON) before
    // splitting into streamed lines.
    $structuredFromMessage = stobeParseStructuredDialogueResponse($message, 'chat');
    if (boolval($structuredFromMessage['is_structured'] ?? false)) {
        $normalizedMessage = trim(strval($structuredFromMessage['message'] ?? ''));
        if ($normalizedMessage !== '') {
            $message = $normalizedMessage;
        }
        $structuredAction = trim(strval($structuredFromMessage['action_tag'] ?? ''));
        if ($structuredAction !== '' && !in_array($structuredAction, $actions, true)) {
            array_unshift($actions, $structuredAction);
        }
    }

    $queuedActions = 0;
    $actionConfig = stobeBuildActionConfigForNpc(
        'chat',
        is_array($actorData) ? $actorData : false
    );
    foreach ($actions as $rawAction) {
        $normalizedAction = normalizeActionTagToken(strval($rawAction), $actionConfig);
        if ($normalizedAction === '') {
            continue;
        }
        $dispatchAction = stobeTransformActionForDispatch($normalizedAction, is_array($actorData) ? $actorData : false);
        if ($dispatchAction === '') {
            continue;
        }
        $wirePayload = formatResponse($actor, 'ActionQueue', $dispatchAction);
        echo $wirePayload;
        stobeLogOutputToPlugin($actor, 'ActionQueue', $dispatchAction, $wirePayload);
        if (ob_get_length()) {
            ob_flush();
        }
        flush();
        $queuedActions++;
    }

    $rawLines = preg_split('/\r\n|\r|\n/', $message);
    if (!is_array($rawLines) || count($rawLines) === 0) {
        $rawLines = [$message];
    }

    $sentAny = false;
    $ttsEnabled = stobeIsTtsEnabledForCurrentRequest();
    if (strcasecmp($action, 'ScriptQueue') === 0 && !$ttsEnabled) {
        stobeLogInfo('TTS skipped for stream response', [
            'actor' => $actor,
            'action' => $action,
            'reason' => 'client_or_server_tts_disabled',
            'request_tts_enabled' => strval($_GET['tts_enabled'] ?? $_POST['tts_enabled'] ?? ''),
        ]);
    }
    foreach ($rawLines as $rawLine) {
        $line = rtrim(strval($rawLine), "\r");
        if (strcasecmp($action, 'ScriptQueue') === 0) {
            $line = stobeStripParentheticalDialogueText($line);
        }
        if ($line === '') {
            continue;
        }

        $ttsHash = '';
        $ttsDurationMs = 0;
        if ($ttsEnabled && strcasecmp($action, 'ScriptQueue') === 0) {
            $ttsResult = stobeSynthesizePocketTtsLine($actor, $line, $actorData);
            $ttsHash = trim(strval($ttsResult['hash'] ?? ''));
            $ttsDurationMs = intval($ttsResult['duration_ms'] ?? 0);
        }

        $wirePayload = formatResponse($actor, $action, $line, $ttsHash, $ttsDurationMs);
        echo $wirePayload;
        stobeLogOutputToPlugin($actor, $action, $line, $wirePayload, $ttsHash, $ttsDurationMs);
        if (ob_get_length()) {
            ob_flush();
        }
        flush();
        $sentAny = true;
    }

    if (!$sentAny && $queuedActions === 0) {
        $wirePayload = formatResponse($actor, $action, '...');
        echo $wirePayload;
        stobeLogOutputToPlugin($actor, $action, '...', $wirePayload);
        if (ob_get_length()) {
            ob_flush();
        }
        flush();
    }
}

if (!function_exists('mb_scrub')) {
    function mb_scrub(string $str): string {
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
}
