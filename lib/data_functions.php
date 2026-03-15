<?php

/**
 * Core data functions for StobeServer.
 * Handles event logging, NPC data, and context building.
 */

function stobePromptsTableAvailable(): bool {
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.prompts') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeGetPromptTemplateValue(string $promptKey, string $fallback = ''): string {
    static $cache = [];

    $key = trim($promptKey);
    if ($key === '') {
        return $fallback;
    }
    $cacheKey = strtolower($key);
    if (array_key_exists($cacheKey, $cache)) {
        return strval($cache[$cacheKey]);
    }

    $value = '';
    $db = $GLOBALS['db'] ?? null;
    if ($db && stobePromptsTableAvailable()) {
        $row = $db->fetchOne(
            "SELECT custom_prompt, default_prompt
             FROM prompts
             WHERE prompt_key = $1
             LIMIT 1",
            [$key]
        );

        if (is_array($row)) {
            $customPrompt = strval($row['custom_prompt'] ?? '');
            if (trim($customPrompt) !== '') {
                $value = $customPrompt;
            } else {
                $defaultPrompt = strval($row['default_prompt'] ?? '');
                if (trim($defaultPrompt) !== '') {
                    $value = $defaultPrompt;
                }
            }
        }
    }

    if ($value === '') {
        $value = $fallback;
    }

    $cache[$cacheKey] = $value;
    return $value;
}

function stobeNormalizeGeoLabel(string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    $lower = strtolower($normalized);
    if (in_array($lower, ['unknown', 'none', 'null', 'n/a', '-'], true)) {
        return '';
    }
    return $normalized;
}

function stobeKenshiZoneAliasMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $canonicalZones = [
        'Arach',
        'Bast',
        'Black Desert',
        'Bonefields',
        'Border Zone',
        'Burning Forest',
        'Cannibal Plains',
        'Darkfinger',
        'Deadlands',
        'Dreg',
        'Fishman Island',
        'Floodlands',
        'Fog Islands',
        'Forbidden Isle',
        'Great Desert',
        'Great Plateau',
        'Greenbeach',
        'Gut',
        'Heng',
        'Hidden Forest',
        'High Bonefields',
        'Howler Maze',
        'Iron Valleys',
        'Leviathan Coast',
        'Narrow Valley',
        "Okran's Gulf",
        "Okran's Pride",
        'Outlands',
        'Purple Sands',
        'Raptor Island',
        'Royal Valley',
        'Shem',
        'Shun',
        'Sinkuun',
        "Skinner's Roam",
        'Sonorous Dark',
        "Stobe's Gamble",
        'Stenn Desert',
        'Stormgap Coast',
        'Swamp',
        'The Crater',
        'The Grid',
        'The Hook',
        'The Pits',
        'The Pits East',
        'Unwanted Zone',
        "Watcher's Rim",
        'Wend',
    ];

    $map = [];
    foreach ($canonicalZones as $zone) {
        $key = strtolower($zone);
        $map[$key] = $zone;
        $map[strtolower('The ' . $zone)] = $zone;
        $withoutThe = preg_replace('/^the\s+/i', '', $zone);
        if (is_string($withoutThe) && $withoutThe !== '') {
            $map[strtolower($withoutThe)] = $zone;
        }
    }

    // Explicit aliases commonly seen in payloads/log text.
    $map['the great desert'] = 'Great Desert';
    $map['great desert'] = 'Great Desert';
    $map['the border zone'] = 'Border Zone';
    $map['border zone'] = 'Border Zone';
    $map['the hook'] = 'The Hook';
    $map['hook'] = 'The Hook';
    $map['the grid'] = 'The Grid';
    $map['grid'] = 'The Grid';
    $map["stobes gamble"] = "Stobe's Gamble";
    $map["stobe's gamble"] = "Stobe's Gamble";
    $map["skinners roam"] = "Skinner's Roam";
    $map["skinner's roam"] = "Skinner's Roam";

    return $map;
}

function stobeKenshiTownToZoneMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    // Best-effort mapping for eventlog normalization. If a town is known
    // but zone is absent, use this to append canonical zone to location.
    $map = [
        'the hub' => 'Border Zone',
        'brink' => 'Unwanted Zone',
        'squin' => 'Stenn Desert',
        'admag' => 'Stenn Desert',
        'stack' => "Okran's Pride",
        'bad teeth' => "Okran's Pride",
        'blister hill' => "Okran's Pride",
        "world's end" => 'Hidden Forest',
        'mongrel' => 'Fog Islands',
        'shark' => 'Swamp',
        'mud town' => 'Swamp',
        'black desert city' => 'Deadlands',
        'flats lagoon' => 'The Grid',
        'clownsteady' => 'The Hook',
        "drifter's last" => 'The Hook',
        'bark' => 'The Hook',
        'heft' => 'Great Desert',
        'sho-battai' => 'Great Desert',
        'stoat' => 'Great Desert',
        'heng' => 'Heng',
        'catun' => 'The Pits East',
        'mourn' => 'Bonefields',
        'black scratch' => 'Stormgap Coast',
        'spring' => "Stobe's Gamble",
    ];

    return $map;
}

function stobeResolveSeenZoneFromCity(string $rawTown): string {
    $town = stobeNormalizeGeoLabel($rawTown);
    if ($town === '') {
        return '';
    }

    // Prefer explicit canonical town->zone mapping for deterministic zones.
    $mapped = stobeResolveKenshiZoneFromTown($town);
    if ($mapped !== '') {
        return $mapped;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    try {
        $row = $db->fetchOne(
            "SELECT zone_name
             FROM location_zones
             WHERE LOWER(city_name) = LOWER($1)
             ORDER BY last_seen_ts DESC, id DESC
             LIMIT 1",
            [$town]
        );
    } catch (Throwable $exception) {
        return '';
    }

    if (!$row) {
        return '';
    }

    $zone = stobeCanonicalizeKenshiZoneName(strval($row['zone_name'] ?? ''));
    if ($zone !== '' && strtolower($zone) !== strtolower($town)) {
        return $zone;
    }
    return '';
}

function stobeCanonicalizeKenshiZoneName(string $rawZone): string {
    $zone = stobeNormalizeGeoLabel($rawZone);
    if ($zone === '') {
        return '';
    }
    $aliasMap = stobeKenshiZoneAliasMap();
    $lookup = strtolower($zone);
    return $aliasMap[$lookup] ?? $zone;
}

function stobeResolveKenshiZoneFromTown(string $rawTown): string {
    $town = stobeNormalizeGeoLabel($rawTown);
    if ($town === '') {
        return '';
    }
    $map = stobeKenshiTownToZoneMap();
    $lookup = strtolower($town);
    return $map[$lookup] ?? '';
}

function stobeNormalizeGeoContext(array $geo): array {
    $normalized = [
        'location' => stobeNormalizeGeoLabel(strval($geo['location'] ?? '')),
        'city' => stobeNormalizeGeoLabel(strval($geo['city'] ?? '')),
        'region' => stobeNormalizeGeoLabel(strval($geo['region'] ?? '')),
    ];

    $normalized['region'] = stobeCanonicalizeKenshiZoneName($normalized['region']);

    $mappedZoneFromCity = '';
    if ($normalized['city'] !== '') {
        $mappedZoneFromCity = stobeResolveKenshiZoneFromTown($normalized['city']);
    }

    // If city has a canonical zone mapping, prefer it over conflicting payload region.
    if ($mappedZoneFromCity !== '') {
        $normalized['region'] = $mappedZoneFromCity;
    } elseif ($normalized['region'] === '' && $normalized['city'] !== '') {
        $normalized['region'] = stobeResolveSeenZoneFromCity($normalized['city']);
    }
    if ($normalized['region'] === '' && $normalized['location'] !== '') {
        $locationParts = preg_split('/\s*,\s*/', $normalized['location']) ?: [];
        foreach ($locationParts as $part) {
            $candidate = stobeCanonicalizeKenshiZoneName(strval($part));
            if ($candidate !== '') {
                $normalized['region'] = $candidate;
                break;
            }
        }
        if ($normalized['region'] === '' && count($locationParts) > 0) {
            $normalized['region'] = stobeResolveKenshiZoneFromTown(strval($locationParts[0]));
        }
    }

    if ($normalized['location'] === '') {
        $normalized['location'] = $normalized['city'] !== '' ? $normalized['city'] : $normalized['region'];
    }
    return $normalized;
}

function mergeEventGeoContext(array $base, array $extra): array {
    foreach (['location', 'city', 'region'] as $key) {
        if (!array_key_exists($key, $base)) {
            $base[$key] = '';
        }
        $candidate = trim(strval($extra[$key] ?? ''));
        if ($base[$key] === '' && $candidate !== '') {
            $base[$key] = $candidate;
        }
    }
    return $base;
}

function stobeNormalizeItemDescriptionText(string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized);
    if ($normalized === '') {
        return '';
    }
    $lower = strtolower($normalized);
    if (in_array($lower, ['unknown', 'none', 'null', 'n/a', '-', '(none)'], true)) {
        return '';
    }
    if (strlen($normalized) > 700) {
        $normalized = substr($normalized, 0, 700);
    }
    return $normalized;
}

function stobeBuildSyntheticItemStringId(string $name): string {
    $normalized = strtolower(trim($name));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        return '';
    }
    if (strlen($normalized) > 110) {
        $normalized = substr($normalized, 0, 110);
        $normalized = rtrim($normalized, '_');
    }
    if ($normalized === '') {
        return '';
    }
    return 'name_' . $normalized;
}

function stobeNormalizeVoiceLookupValue(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeNormalizeVoiceFactionValue(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    $barPos = strpos($raw, '|');
    if ($barPos !== false) {
        $raw = trim(substr($raw, 0, $barPos));
    }
    $raw = preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $raw) ?? $raw;
    return stobeNormalizeVoiceLookupValue($raw);
}

function stobeNormalizeVoiceGender(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === 'male' || $normalized === 'female' || $normalized === 'any') {
        return $normalized;
    }
    if ($normalized === '') {
        return 'any';
    }
    return 'any';
}

function stobeBuildVoiceNameCandidates(string $name, string $originalName = ''): array {
    $candidates = [];
    $push = static function (array &$target, string $raw): void {
        $value = stobeNormalizeVoiceLookupValue($raw);
        if ($value === '') {
            return;
        }
        if (!in_array($value, $target, true)) {
            $target[] = $value;
        }
    };

    $push($candidates, $name);
    $push($candidates, $originalName);

    $open = strpos($name, '[');
    $close = strrpos($name, ']');
    if ($open !== false && $close !== false && $close > $open) {
        $inside = trim(substr($name, $open + 1, $close - $open - 1));
        $before = trim(substr($name, 0, $open));
        $push($candidates, $inside);
        $push($candidates, $before);
    }

    return $candidates;
}

function stobeGetCombinedVoiceRows(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache = [];
        return $cache;
    }

    try {
        $rows = $db->fetchAll(
            "SELECT
                id,
                voiceid,
                sample_file,
                gender,
                race,
                faction,
                \"unique\",
                notes
             FROM combined_core_voiceid
             ORDER BY id ASC"
        );
    } catch (Throwable $exception) {
        $rows = [];
    }

    $customVoiceMap = [];
    try {
        $customRows = $db->fetchAll("SELECT voiceid FROM core_voiceid_custom");
        foreach ($customRows as $customRow) {
            $customVoiceId = strtolower(trim(strval($customRow['voiceid'] ?? '')));
            if ($customVoiceId !== '') {
                $customVoiceMap[$customVoiceId] = true;
            }
        }
    } catch (Throwable $exception) {
        $customVoiceMap = [];
    }

    $normalized = [];
    foreach ($rows as $row) {
        $voiceId = trim(strval($row['voiceid'] ?? ''));
        if ($voiceId === '') {
            continue;
        }
        $voiceIdKey = strtolower($voiceId);
        $normalized[] = [
            'id' => intval($row['id'] ?? 0),
            'voiceid' => $voiceId,
            'sample_file' => trim(strval($row['sample_file'] ?? '')),
            'gender' => stobeNormalizeVoiceGender(strval($row['gender'] ?? 'any')),
            'race' => stobeNormalizeVoiceLookupValue(strval($row['race'] ?? 'any')),
            'faction' => stobeNormalizeVoiceLookupValue(strval($row['faction'] ?? 'any')),
            'unique' => stobeNormalizeVoiceLookupValue(strval($row['unique'] ?? '')),
            'notes' => trim(strval($row['notes'] ?? '')),
            'is_custom' => isset($customVoiceMap[$voiceIdKey]),
        ];
    }

    $cache = $normalized;
    return $cache;
}

function stobeShouldAllowVoiceRowInRandomPool(array $row): bool {
    $uniqueTag = trim(strval($row['unique'] ?? ''));
    return $uniqueTag === '';
}

function stobeHasUniqueVoiceCandidateForNpc(string $name, string $originalName = ''): bool {
    $rows = stobeGetCombinedVoiceRows();
    if (count($rows) === 0) {
        return false;
    }

    $candidateNames = stobeBuildVoiceNameCandidates($name, $originalName);
    if (count($candidateNames) === 0) {
        return false;
    }
    $candidateNameMap = array_fill_keys($candidateNames, true);

    foreach ($rows as $row) {
        $uniqueName = trim(strval($row['unique'] ?? ''));
        if ($uniqueName === '') {
            continue;
        }
        if (isset($candidateNameMap[$uniqueName])) {
            return true;
        }
    }

    return false;
}

function stobeBuildVoiceStableKey(string $name, array $profile = []): string {
    $metadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    $storageId = normalizeStorageIdToken($metadata['storage_id'] ?? '');
    if ($storageId !== '') {
        return strtolower($storageId);
    }

    $fallback = strtolower(trim($name));
    if ($fallback !== '') {
        return $fallback;
    }

    $encoded = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return 'voice-fallback';
    }
    return md5($encoded);
}

function stobePickDeterministicVoice(array $candidates, string $stableKey, string $salt = ''): string {
    if (count($candidates) === 0) {
        return '';
    }
    $seed = $stableKey . '|' . $salt;
    $hash = sprintf('%u', crc32($seed));
    $index = intval($hash) % count($candidates);
    $picked = $candidates[$index] ?? [];
    return trim(strval($picked['voiceid'] ?? ''));
}

function stobeVoiceTagMatches(string $rowValue, string $npcValue): bool {
    $row = stobeNormalizeVoiceLookupValue($rowValue);
    if ($row === '' || $row === 'any') {
        return true;
    }
    $npc = stobeNormalizeVoiceLookupValue($npcValue);
    if ($npc === '') {
        return false;
    }
    return strcasecmp($row, $npc) === 0;
}

function stobeSelectVoiceIdForNpc(
    string $name,
    string $race = '',
    string $gender = '',
    string $faction = '',
    array $profile = []
): string {
    $rows = stobeGetCombinedVoiceRows();
    if (count($rows) === 0) {
        return '';
    }

    $stableKey = stobeBuildVoiceStableKey($name, $profile);
    $candidateNames = stobeBuildVoiceNameCandidates($name, trim(strval($profile['original_name'] ?? '')));
    $candidateNameMap = array_fill_keys($candidateNames, true);

    $uniqueMatches = [];
    foreach ($rows as $row) {
        $uniqueName = trim(strval($row['unique'] ?? ''));
        if ($uniqueName === '') {
            continue;
        }
        if (isset($candidateNameMap[$uniqueName])) {
            $uniqueMatches[] = $row;
        }
    }
    $pickedUnique = stobePickDeterministicVoice($uniqueMatches, $stableKey, 'unique');
    if ($pickedUnique !== '') {
        return $pickedUnique;
    }

    $normalizedGender = stobeNormalizeVoiceGender($gender);
    $normalizedRace = stobeNormalizeVoiceLookupValue($race);
    $normalizedFaction = stobeNormalizeVoiceFactionValue($faction);

    $nonUniqueRows = [];
    foreach ($rows as $row) {
        if (!stobeShouldAllowVoiceRowInRandomPool($row)) {
            continue;
        }
        $nonUniqueRows[] = $row;
    }

    $strictMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['race'] ?? 'any'), $normalizedRace)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
            continue;
        }
        $strictMatches[] = $row;
    }
    $pickedStrict = stobePickDeterministicVoice($strictMatches, $stableKey, 'strict');
    if ($pickedStrict !== '') {
        return $pickedStrict;
    }

    $genderRaceMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['race'] ?? 'any'), $normalizedRace)) {
            continue;
        }
        $genderRaceMatches[] = $row;
    }
    $pickedGenderRace = stobePickDeterministicVoice($genderRaceMatches, $stableKey, 'gender_race');
    if ($pickedGenderRace !== '') {
        return $pickedGenderRace;
    }

    $genderFactionMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
            continue;
        }
        $genderFactionMatches[] = $row;
    }
    $pickedGenderFaction = stobePickDeterministicVoice($genderFactionMatches, $stableKey, 'gender_faction');
    if ($pickedGenderFaction !== '') {
        return $pickedGenderFaction;
    }

    $genderMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        $genderMatches[] = $row;
    }
    $pickedGender = stobePickDeterministicVoice($genderMatches, $stableKey, 'gender');
    if ($pickedGender !== '') {
        return $pickedGender;
    }

    return stobePickDeterministicVoice($nonUniqueRows, $stableKey, 'fallback');
}

function stobeAssignVoiceIdIfMissing(
    string $npcName,
    string $race = '',
    string $gender = '',
    string $faction = '',
    array $profile = []
): string {
    $safeName = trim($npcName);
    if ($safeName === '') {
        return '';
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $row = $db->fetchOne(
        "SELECT voiceid,
                race,
                gender,
                faction,
                original_name,
                metadata
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );
    $existingVoice = trim(strval($row['voiceid'] ?? ''));
    if ($existingVoice !== '') {
        return $existingVoice;
    }

    $existingRace = trim(strval($row['race'] ?? ''));
    $existingGender = trim(strval($row['gender'] ?? ''));
    $existingFaction = trim(strval($row['faction'] ?? ''));
    $existingOriginalName = trim(strval($row['original_name'] ?? ''));
    $existingMetadata = normalizeCoreNpcMetadata($row['metadata'] ?? []);
    $providedMetadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    $effectiveMetadata = count($providedMetadata) > 0 ? $providedMetadata : $existingMetadata;

    $effectiveRace = trim($race) !== '' ? trim($race) : $existingRace;
    $effectiveGender = trim($gender) !== '' ? trim($gender) : $existingGender;
    $effectiveFaction = trim($faction) !== '' ? trim($faction) : $existingFaction;
    $effectiveOriginalName = trim(strval($profile['original_name'] ?? ''));
    if ($effectiveOriginalName === '') {
        $effectiveOriginalName = $existingOriginalName;
    }

    $hasUniqueCandidate = stobeHasUniqueVoiceCandidateForNpc($safeName, $effectiveOriginalName);
    $normalizedGender = stobeNormalizeVoiceGender($effectiveGender);
    if (!$hasUniqueCandidate && $normalizedGender !== 'male' && $normalizedGender !== 'female') {
        return '';
    }

    $selectionProfile = $profile;
    $selectionProfile['metadata'] = $effectiveMetadata;
    $selectionProfile['original_name'] = $effectiveOriginalName;
    $selected = stobeSelectVoiceIdForNpc($safeName, $effectiveRace, $effectiveGender, $effectiveFaction, $selectionProfile);
    if ($selected === '') {
        return '';
    }

    $db->exec(
        "UPDATE core_npc_master
         SET voiceid = $2,
             updated_at = NOW()
         WHERE LOWER(name) = LOWER($1)
           AND (voiceid IS NULL OR BTRIM(voiceid) = '')",
        [$safeName, $selected]
    );

    return $selected;
}

function stobeUpsertDescriptionsFromInventoryEntries(array $inventoryEntries, string $source = '', string $npcName = '', int $gamets = 0): int {
    $db = $GLOBALS["db"] ?? null;
    if (!$db || count($inventoryEntries) === 0) {
        return 0;
    }

    $rowsByStringId = [];
    foreach ($inventoryEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
        if ($itemDescription === '') {
            continue;
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

        $stringId = $itemId !== '' ? $itemId : stobeBuildSyntheticItemStringId($itemName);
        if ($stringId === '') {
            continue;
        }

        $mapKey = strtolower($stringId);
        if (!array_key_exists($mapKey, $rowsByStringId)) {
            $rowsByStringId[$mapKey] = [
                'stringid' => $stringId,
                'name' => $itemName,
                'description' => $itemDescription,
            ];
            continue;
        }

        $existingDescription = strval($rowsByStringId[$mapKey]['description'] ?? '');
        if (strlen($itemDescription) > strlen($existingDescription)) {
            $rowsByStringId[$mapKey]['description'] = $itemDescription;
            $rowsByStringId[$mapKey]['name'] = $itemName;
        }
    }

    if (count($rowsByStringId) === 0) {
        return 0;
    }

    $upserted = 0;
    foreach ($rowsByStringId as $row) {
        try {
            $db->exec(
                "INSERT INTO descriptions (stringid, name, description)
                 VALUES ($1, $2, $3)
                 ON CONFLICT (stringid) DO UPDATE
                 SET
                    name = EXCLUDED.name,
                    description = EXCLUDED.description
                 WHERE COALESCE(descriptions.name, '') <> COALESCE(EXCLUDED.name, '')
                    OR COALESCE(descriptions.description, '') <> COALESCE(EXCLUDED.description, '')",
                [
                    strval($row['stringid'] ?? ''),
                    strval($row['name'] ?? ''),
                    strval($row['description'] ?? ''),
                ]
            );
            $upserted++;
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Failed to upsert live item description', [
                'stringid' => strval($row['stringid'] ?? ''),
                'name' => strval($row['name'] ?? ''),
                'source' => $source,
                'npc_name' => $npcName,
                'gamets' => max(0, intval($gamets)),
            ]);
        }
    }

    return $upserted;
}

function extractEventGeoFromArray(array $payload): array {
    $context = ['location' => '', 'city' => '', 'region' => ''];

    $pickValue = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $candidate = trim(strval($source[$key]));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    };

    $extractFrom = static function (array $source) use (&$context, $pickValue): void {
        $location = $pickValue($source, ['location', 'location_name', 'place', 'area_name', 'area', 'cell']);
        $city = $pickValue($source, ['city', 'town_name', 'town', 'settlement', 'village']);
        $region = $pickValue($source, [
            'region', 'region_name', 'regionName',
            'zone', 'zone_name', 'zoneName',
            'province', 'district', 'territory', 'biome'
        ]);
        $building = $pickValue($source, ['building_name', 'building']);

        if ($context['city'] === '' && $city !== '') {
            $context['city'] = $city;
        }
        if ($context['region'] === '' && $region !== '') {
            $context['region'] = $region;
        }
        if ($context['location'] === '') {
            if ($location !== '') {
                $context['location'] = $location;
            } elseif ($building !== '' && $city !== '') {
                $context['location'] = $building . ', ' . $city;
            } elseif ($building !== '') {
                $context['location'] = $building;
            } elseif ($city !== '') {
                $context['location'] = $city;
            } elseif ($region !== '') {
                $context['location'] = $region;
            }
        }
    };

    $extractFrom($payload);

    foreach (['environment', 'location', 'context', 'metadata', 'entry'] as $nestedKey) {
        $nested = $payload[$nestedKey] ?? null;
        if (is_array($nested)) {
            $extractFrom($nested);
        }
    }

    if ($context['city'] === '' && $context['location'] !== '' && strpos($context['location'], ',') === false) {
        $context['city'] = $context['location'];
    }

    return stobeNormalizeGeoContext($context);
}

function extractEventGeoFromString(string $eventData): array {
    $text = trim($eventData);
    if ($text === '') {
        return ['location' => '', 'city' => '', 'region' => ''];
    }

    if ($text[0] === '{') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return extractEventGeoFromArray($decoded);
        }
    }

    $pairs = [];
    $chunks = preg_split('/[|,;\n\r]+/', $text) ?: [];
    foreach ($chunks as $chunk) {
        $part = trim(strval($chunk));
        if ($part === '') {
            continue;
        }
        $delimiter = '';
        if (strpos($part, '=') !== false) {
            $delimiter = '=';
        } elseif (strpos($part, ':') !== false) {
            $delimiter = ':';
        }
        if ($delimiter === '') {
            continue;
        }
        $kv = explode($delimiter, $part, 2);
        if (count($kv) !== 2) {
            continue;
        }
        $key = strtolower(trim(strval($kv[0])));
        $value = trim(strval($kv[1]));
        if ($key === '' || $value === '') {
            continue;
        }
        $pairs[$key] = $value;
    }

    if (count($pairs) > 0) {
        return extractEventGeoFromArray($pairs);
    }

    return stobeNormalizeGeoContext(['location' => $text, 'city' => '', 'region' => '']);
}

function getEventGeoFromNpcName(string $name): array {
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return ['location' => '', 'city' => '', 'region' => ''];
    }

    static $cache = [];
    $cacheKey = strtolower($normalizedName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata, extended_data
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );

    if (!$row) {
        $cache[$cacheKey] = ['location' => '', 'city' => '', 'region' => ''];
        return $cache[$cacheKey];
    }

    $resolved = ['location' => '', 'city' => '', 'region' => ''];
    foreach (['extended_data', 'metadata'] as $field) {
        $raw = $row[$field] ?? '{}';
        if (is_array($raw)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($raw));
            continue;
        }
        $decoded = json_decode(strval($raw), true);
        if (is_array($decoded)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($decoded));
        }
    }

    $cache[$cacheKey] = $resolved;
    return $resolved;
}

function getEventGeoFromPlayerSnapshot(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata, extended_data
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
        $cached = ['location' => '', 'city' => '', 'region' => ''];
        return $cached;
    }

    $resolved = ['location' => '', 'city' => '', 'region' => ''];
    foreach (['extended_data', 'metadata'] as $field) {
        $raw = $row[$field] ?? '{}';
        if (is_array($raw)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($raw));
            continue;
        }
        $decoded = json_decode(strval($raw), true);
        if (is_array($decoded)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($decoded));
        }
    }

    $cached = $resolved;
    return $cached;
}

function getRecentEventGeoFallback(string $participant = '', int $windowSeconds = 1800): array {
    static $cache = [];
    $key = strtolower(trim($participant)) . '|' . strval($windowSeconds);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $db = $GLOBALS["db"];
    $window = $windowSeconds;
    if ($window < 60) {
        $window = 60;
    } elseif ($window > 86400) {
        $window = 86400;
    }
    $cutoff = time() - $window;

    $row = null;
    $normalized = normalizeParticipantNameToken($participant);
    if ($normalized !== '') {
        $nameLower = strtolower($normalized);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nameLower);
        $row = $db->fetchOne(
            "SELECT location
             FROM eventlog
             WHERE localts > $1
               AND COALESCE(location, '') <> ''
               AND (
                    LOWER(data) LIKE $2 ESCAPE '\\'
                    OR LOWER(people) LIKE $3 ESCAPE '\\'
               )
             ORDER BY localts DESC, gamets DESC, ts DESC, rowid DESC
             LIMIT 1",
            [
                $cutoff,
                $escaped . ':%',
                '%"' . $escaped . '|%',
            ]
        );
    }

    if (!$row) {
        $row = $db->fetchOne(
            "SELECT location
             FROM eventlog
             WHERE localts > $1
               AND COALESCE(location, '') <> ''
             ORDER BY localts DESC, gamets DESC, ts DESC, rowid DESC
             LIMIT 1",
            [$cutoff]
        );
    }

    $location = trim(strval($row['location'] ?? ''));
    $cache[$key] = $location !== '' ? extractEventGeoFromString($location) : ['location' => '', 'city' => '', 'region' => ''];
    return $cache[$key];
}

function resolveEventGeoContext(string $normalizedType, string $eventData): array {
    $resolved = ['location' => '', 'city' => '', 'region' => ''];
    $speakerHint = '';
    $targetHint = '';

    $fromQuery = [
        'location' => trim(strval($_GET['location'] ?? '')),
        'city' => trim(strval($_GET['city'] ?? '')),
        'region' => trim(strval($_GET['region'] ?? '')),
    ];
    $resolved = mergeEventGeoContext($resolved, $fromQuery);

    if ($normalizedType === 'location') {
        $resolved = mergeEventGeoContext($resolved, extractEventGeoFromString($eventData));
    }

    $profileName = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    if ($profileName !== '') {
        $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($profileName));
    }

    if (
        in_array($normalizedType, ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored', 'action', 'infoaction', 'lockpicked', 'lockpiked', 'infonpc', 'infoloc'], true) &&
        strpos($eventData, ':') !== false
    ) {
        $speakerHint = normalizeParticipantNameToken(explode(':', $eventData, 2)[0] ?? '');
        if ($speakerHint !== '') {
            $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($speakerHint));
        }
    }
    if (
        in_array($normalizedType, ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored', 'action', 'infoaction', 'lockpicked', 'lockpiked', 'infonpc', 'infoloc'], true) &&
        stripos($eventData, '(talking to:') !== false
    ) {
        $targetExtract = extractDialogueTarget($eventData);
        $targetHint = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
        if ($targetHint !== '') {
            $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($targetHint));
        }
    }

    $peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));
    if (trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $participantToken) {
                if (!is_string($participantToken)) {
                    continue;
                }
                $participantName = normalizeParticipantNameToken($participantToken);
                if ($participantName === '') {
                    continue;
                }
                $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($participantName));
                if (
                    $resolved['location'] !== '' &&
                    $resolved['city'] !== '' &&
                    $resolved['region'] !== ''
                ) {
                    break;
                }
            }
        }
    }

    $resolved = mergeEventGeoContext($resolved, getEventGeoFromPlayerSnapshot());

    $resolved = mergeEventGeoContext($resolved, [
        'location' => trim(strval($GLOBALS["CACHE_LOCATION"] ?? '')),
        'city' => trim(strval($GLOBALS["CACHE_CITY"] ?? '')),
        'region' => trim(strval($GLOBALS["CACHE_REGION"] ?? '')),
    ]);

    if ($resolved['location'] === '' || $resolved['city'] === '' || $resolved['region'] === '') {
        $fallbackParticipant = $speakerHint !== '' ? $speakerHint : $targetHint;
        $resolved = mergeEventGeoContext($resolved, getRecentEventGeoFallback($fallbackParticipant, 1800));
    }

    return stobeNormalizeGeoContext($resolved);
}

function composeEventLocationText(array $geo): string {
    $normalizedGeo = stobeNormalizeGeoContext($geo);
    $location = trim(strval($normalizedGeo['location'] ?? ''));
    $city = trim(strval($normalizedGeo['city'] ?? ''));
    $region = trim(strval($normalizedGeo['region'] ?? ''));

    if ($location === '') {
        $location = $city !== '' ? $city : $region;
    }
    if ($location === '') {
        return '';
    }

    $parts = [];
    $seen = [];
    $pushPart = static function (string $rawPart) use (&$parts, &$seen): void {
        $part = trim($rawPart);
        if ($part === '') {
            return;
        }
        if (preg_match('/^(?:city|region)\s*:\s*(.+)$/i', $part, $m)) {
            $part = trim(strval($m[1]));
        }
        if ($part === '') {
            return;
        }
        $key = strtolower($part);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $parts[] = $part;
    };

    $legacyClean = preg_replace('/\((?:city|region)\s*:\s*[^)]*\)/i', '', $location);
    $locationParts = preg_split('/\s*,\s*/', trim(strval($legacyClean))) ?: [];
    foreach ($locationParts as $part) {
        $pushPart(strval($part));
    }

    $pushPart($city);
    $pushPart($region);

    if (count($parts) === 0) {
        return $location;
    }
    return implode(', ', $parts);
}

function stobeNormalizeZoneLocationToken(string $value): string {
    return stobeNormalizeGeoLabel($value);
}

function stobeParseNullableCoordinate(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
        return floatval($value);
    }
    if (is_string($value)) {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $normalized) === 1) {
            return floatval($normalized);
        }
    }
    return null;
}

function stobeExtractZoneSnapshotContext(array $snapshot): array {
    $environment = [];
    if (isset($snapshot['environment']) && is_array($snapshot['environment'])) {
        $environment = $snapshot['environment'];
    } elseif (isset($snapshot['environment']) && is_string($snapshot['environment'])) {
        $decodedEnvironment = json_decode(strval($snapshot['environment']), true);
        if (is_array($decodedEnvironment)) {
            $environment = $decodedEnvironment;
        }
    }

    $pickText = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (is_array($source[$key]) || is_object($source[$key])) {
                continue;
            }
            $value = stobeNormalizeZoneLocationToken(strval($source[$key]));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $pickCoordinate = static function (array $primary, array $fallback, string $key): ?float {
        $fromPrimary = stobeParseNullableCoordinate($primary[$key] ?? null);
        if ($fromPrimary !== null) {
            return $fromPrimary;
        }
        return stobeParseNullableCoordinate($fallback[$key] ?? null);
    };

    $zoneName = $pickText($environment, ['zone_name', 'zone', 'region', 'region_name']);
    if ($zoneName === '') {
        $zoneName = $pickText($snapshot, ['zone_name', 'zone', 'region', 'region_name']);
    }
    $zoneName = stobeCanonicalizeKenshiZoneName($zoneName);

    $cityName = $pickText($environment, ['town_name', 'town', 'city', 'settlement']);
    if ($cityName === '') {
        $cityName = $pickText($snapshot, ['town_name', 'town', 'city', 'settlement']);
    }
    $cityName = stobeNormalizeZoneLocationToken($cityName);

    if ($zoneName === '') {
        $zoneName = stobeResolveKenshiZoneFromTown($cityName);
    }
    if ($zoneName === '') {
        // Final fallback when no canonical zone mapping exists for this place yet.
        $zoneName = $cityName;
    }
    // Keep location_zones keyed on zone only.

    $x = $pickCoordinate($environment, $snapshot, 'x');
    $y = $pickCoordinate($environment, $snapshot, 'y');
    $z = $pickCoordinate($environment, $snapshot, 'z');

    $metadata = [];
    foreach (['indoors', 'outdoors', 'in_town', 'weather'] as $field) {
        if (array_key_exists($field, $environment)) {
            $metadata[$field] = $environment[$field];
        }
    }

    return [
        'zone_name' => $zoneName,
        'city_name' => $cityName,
        'x' => $x,
        'y' => $y,
        'z' => $z,
        'metadata' => $metadata,
    ];
}

function stobeUpsertLocationZoneFromSnapshot(array $snapshot, int $gamets, string $observerName = ''): bool {
    if (!coerceBoolean($snapshot['is_player_character'] ?? false)) {
        return false;
    }

    $zoneContext = stobeExtractZoneSnapshotContext($snapshot);
    $zoneName = stobeNormalizeZoneLocationToken(strval($zoneContext['zone_name'] ?? ''));
    if ($zoneName === '') {
        return false;
    }

    $cityName = stobeNormalizeZoneLocationToken(strval($zoneContext['city_name'] ?? ''));
    $x = $zoneContext['x'] ?? null;
    $y = $zoneContext['y'] ?? null;
    $z = $zoneContext['z'] ?? null;
    $safeGamets = max(0, intval($gamets));
    $nowTs = time();

    $metadata = is_array($zoneContext['metadata'] ?? null) ? $zoneContext['metadata'] : [];
    if ($observerName !== '') {
        $metadata['observer'] = $observerName;
    }
    $metadataJson = normalizeJsonString($metadata);

    $db = $GLOBALS["db"];
    try {
        $db->exec(
            "INSERT INTO location_zones (
                zone_name,
                city_name,
                x,
                y,
                z,
                first_game_ts,
                last_game_ts,
                first_seen_ts,
                last_seen_ts,
                metadata,
                updated_at
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $8, $9::jsonb, NOW()
            )
            ON CONFLICT (zone_name) DO UPDATE SET
                city_name = CASE
                    WHEN NULLIF(EXCLUDED.city_name, '') IS NOT NULL THEN EXCLUDED.city_name
                    ELSE location_zones.city_name
                END,
                x = COALESCE(EXCLUDED.x, location_zones.x),
                y = COALESCE(EXCLUDED.y, location_zones.y),
                z = COALESCE(EXCLUDED.z, location_zones.z),
                first_game_ts = CASE
                    WHEN COALESCE(location_zones.first_game_ts, 0) <= 0 THEN EXCLUDED.first_game_ts
                    WHEN COALESCE(EXCLUDED.first_game_ts, 0) <= 0 THEN location_zones.first_game_ts
                    WHEN EXCLUDED.first_game_ts < location_zones.first_game_ts THEN EXCLUDED.first_game_ts
                    ELSE location_zones.first_game_ts
                END,
                last_game_ts = CASE
                    WHEN EXCLUDED.last_game_ts > location_zones.last_game_ts THEN EXCLUDED.last_game_ts
                    ELSE location_zones.last_game_ts
                END,
                last_seen_ts = EXCLUDED.last_seen_ts,
                metadata = CASE
                    WHEN EXCLUDED.metadata = '{}'::jsonb THEN location_zones.metadata
                    ELSE location_zones.metadata || EXCLUDED.metadata
                END,
                updated_at = NOW()",
            [
                $zoneName,
                $cityName,
                $x,
                $y,
                $z,
                $safeGamets,
                $safeGamets,
                $nowTs,
                $metadataJson,
            ]
        );
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to upsert location zone', [
            'zone_name' => $zoneName,
            'observer' => $observerName,
            'gamets' => $safeGamets,
        ]);
        return false;
    }

    return true;
}

function storeEvent(string $type, int $ts, int $gamets, string $data, string $sess = 'pending'): void {
    $normalizedType = strtolower(trim($type));
    if ($normalizedType === 'npc_snapshot' || $normalizedType === 'playerinfo') {
        return;
    }

    $rawData = strval($data);
    $data = stobeSanitizeEventDataForLog($normalizedType, $rawData);

    $geo = resolveEventGeoContext($normalizedType, $data);
    $eventLocation = composeEventLocationText($geo);
    if ($eventLocation !== '') {
        $GLOBALS["CACHE_LOCATION"] = $eventLocation;
    }

    $db = $GLOBALS["db"];
    $eventLocalTs = time();
    $peopleCache = $GLOBALS["CACHE_PEOPLE"] ?? '';
    $locationCache = $GLOBALS["CACHE_LOCATION"] ?? '';

    $inserted = $db->fetchOne(
        "INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
         RETURNING rowid, localts",
        [
            $type,
            $ts,
            $gamets,
            $data,
            $sess,
            $eventLocalTs,
            $peopleCache,
            $locationCache,
        ]
    );

    if (is_array($inserted)) {
        $eventLocalTs = intval($inserted['localts'] ?? $eventLocalTs);
    }

    if ($data !== $rawData) {
        stobeLogDebug('Event data sanitized before eventlog insert', [
            'type' => $normalizedType,
            'before' => substr($rawData, 0, 240),
            'after' => substr($data, 0, 240),
        ]);
    }

    if (function_exists('stobeMemoryTrackEvent')) {
        stobeMemoryTrackEvent(
            strval($type),
            intval($ts),
            intval($gamets),
            strval($data),
            strval($peopleCache),
            strval($locationCache),
            $eventLocalTs
        );
    }
}

function stobeSanitizeEventDataForLog(string $normalizedType, string $rawData): string {
    $clean = sanitizeForKenshi(strval($rawData));

    // Strip control bytes that can leak in from malformed payload fragments.
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $clean) ?? $clean;
    $clean = trim($clean);

    // Malformed target suffixes can arrive as "9talking to)" or "(talking to: )".
    $clean = preg_replace('/\s*\(?\d+\s*talking to\)\s*$/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*\(talking to:\s*\)\s*$/iu', '', $clean) ?? $clean;

    // Common corruption pattern: garbage suffix after a complete "(talking to: X)" segment.
    $clean = preg_replace(
        '/(\(talking to:\s*[^\)]+\))\s*(?:\?+|[0-9]{1,3})\s*$/iu',
        '$1',
        $clean
    ) ?? $clean;

    $contextOnlyTypes = ['infonpc', 'infonpc_close', 'infoloc', 'location', 'infoitems'];
    if (in_array($normalizedType, $contextOnlyTypes, true)) {
        // Context telemetry rows are not directional dialogue.
        $clean = preg_replace('/\s*\(talking to:\s*[^\)]*\)\s*/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s*\(?\d+\s*talking to\)\s*/iu', ' ', $clean) ?? $clean;
    }

    // Strip common corrupted dialogue tails while preserving normal punctuation.
    // Examples: "...7", ")7", "*Glug glug* ??".
    $clean = preg_replace('/([\.!\?\)\]])\d{1,3}\s*$/u', '$1', $clean) ?? $clean;
    $clean = preg_replace('/\s+\?{2,}\s*$/u', '', $clean) ?? $clean;

    $speechTypes = ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored'];
    if (!in_array($normalizedType, $speechTypes, true)) {
        // Non-dialogue events should not end with repeated "??" noise.
        $clean = preg_replace('/\s*\?{2,}\s*$/u', '', $clean) ?? $clean;
        // Strip accidental trailing digits like "consciousness7".
        $clean = preg_replace('/(?<=\p{L}|\))\d{1,2}\s*$/u', '', $clean) ?? $clean;
    }

    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    return trim($clean);
}

function baseNameWithoutBracketSuffix(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    return trim((string)preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $trimmed));
}

function normalizeParticipantNameToken(string $raw): string {
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $pipePos = strpos($value, '|');
    if ($pipePos !== false) {
        $left = trim(substr($value, 0, $pipePos));
        if ($left !== '') {
            $value = $left;
        }
    }

    return trim($value);
}

function normalizeStorageIdToken(mixed $raw): string {
    if (is_array($raw) || is_object($raw)) {
        return '';
    }

    $value = trim(strval($raw));
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if (in_array($lower, ['player', 'unknown', 'none', 'null'], true)) {
        return '';
    }

    $extractNumericPart = $value;
    if (preg_match('/^hand_(.+)$/i', $value, $m) === 1) {
        $extractNumericPart = trim(strval($m[1]));
    }

    if (preg_match('/^-?[0-9]+$/', $extractNumericPart) === 1) {
        $numeric = intval($extractNumericPart);
        if ($numeric < 0 && $numeric >= -2147483648) {
            $numeric = $numeric + 4294967296;
        }
        if ($numeric >= 0) {
            return 'hand_' . strval($numeric);
        }
    }

    return $value;
}

function buildStorageIdSearchVariants(string $storageId): array {
    $variants = [];
    $normalized = normalizeStorageIdToken($storageId);
    if ($normalized !== '') {
        $variants[strtolower($normalized)] = $normalized;
    }

    $trimmed = trim($storageId);
    if ($trimmed !== '') {
        $variants[strtolower($trimmed)] = $trimmed;
    }

    if (preg_match('/^hand_(-?[0-9]+)$/i', $trimmed, $m) === 1) {
        $numeric = intval($m[1]);
        $variants[strtolower($m[1])] = $m[1];
        if ($numeric < 0 && $numeric >= -2147483648) {
            $unsigned = $numeric + 4294967296;
            $variants['hand_' . strval($unsigned)] = 'hand_' . strval($unsigned);
            $variants[strval($unsigned)] = strval($unsigned);
        } elseif ($numeric > 2147483647 && $numeric <= 4294967295) {
            $signed = $numeric - 4294967296;
            $variants['hand_' . strval($signed)] = 'hand_' . strval($signed);
            $variants[strval($signed)] = strval($signed);
        }
    } elseif (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
        $numeric = intval($trimmed);
        $variants['hand_' . strtolower($trimmed)] = 'hand_' . $trimmed;
        if ($numeric < 0 && $numeric >= -2147483648) {
            $unsigned = $numeric + 4294967296;
            $variants['hand_' . strval($unsigned)] = 'hand_' . strval($unsigned);
            $variants[strval($unsigned)] = strval($unsigned);
        } elseif ($numeric > 2147483647 && $numeric <= 4294967295) {
            $signed = $numeric - 4294967296;
            $variants['hand_' . strval($signed)] = 'hand_' . strval($signed);
            $variants[strval($signed)] = strval($signed);
        }
    }

    return array_values($variants);
}

function composeFactionWithId(string $factionName, string $factionId): string {
    $name = trim($factionName);
    $id = trim($factionId);

    if ($id === '') {
        return $name;
    }
    if ($name === '') {
        return $id;
    }
    if (strpos($name, '[' . $id . ']') !== false) {
        return $name;
    }
    return $name . ' [' . $id . ']';
}

function extractParticipantIdentityToken(string $raw): array {
    $value = trim($raw);
    if ($value === '') {
        return ['name' => '', 'storage_id' => ''];
    }

    $name = normalizeParticipantNameToken($value);
    if ($name === '') {
        return ['name' => '', 'storage_id' => ''];
    }

    $storageId = '';
    $pipePos = strpos($value, '|');
    if ($pipePos !== false) {
        $storageId = normalizeStorageIdToken(substr($value, $pipePos + 1));
    }

    return ['name' => $name, 'storage_id' => $storageId];
}

function extractParticipantNames(array $options): array {
    $namesByKey = [];
    $addName = function (string $rawName) use (&$namesByKey): void {
        $normalized = normalizeParticipantNameToken($rawName);
        if ($normalized === '') {
            return;
        }
        $key = strtolower($normalized);
        if (!isset($namesByKey[$key])) {
            $namesByKey[$key] = $normalized;
        }
    };

    $peopleRaw = $options['people'] ?? '';
    if (is_array($peopleRaw)) {
        foreach ($peopleRaw as $entry) {
            if (is_string($entry)) {
                $addName($entry);
            }
        }
    } elseif (is_string($peopleRaw) && trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $entry) {
                if (is_string($entry)) {
                    $addName($entry);
                }
            }
        } else {
            $addName($peopleRaw);
        }
    }

    $profileName = $options['profile'] ?? '';
    if (is_string($profileName)) {
        $addName($profileName);
    }

    $speakerName = $options['speaker'] ?? '';
    if (is_string($speakerName)) {
        $addName($speakerName);
    }

    $npcs = $options['npcs'] ?? [];
    if (is_array($npcs)) {
        foreach ($npcs as $entry) {
            if (is_string($entry)) {
                $addName($entry);
                continue;
            }
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? ($entry['npc'] ?? ''));
                if ($candidate !== '') {
                    $addName($candidate);
                }
            }
        }
    }

    $nearby = $options['nearby'] ?? [];
    if (is_array($nearby)) {
        foreach ($nearby as $entry) {
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? '');
                if ($candidate !== '') {
                    $addName($candidate);
                }
            } elseif (is_string($entry)) {
                $addName($entry);
            }
        }
    }

    return array_values($namesByKey);
}

function extractParticipantIdentities(array $options): array {
    $identitiesByKey = [];
    $addIdentity = function (string $rawName, $rawStorageId = null) use (&$identitiesByKey): void {
        $parsed = extractParticipantIdentityToken($rawName);
        $name = strval($parsed['name'] ?? '');
        if ($name === '') {
            return;
        }

        $storageId = strval($parsed['storage_id'] ?? '');
        if ($rawStorageId !== null && !is_array($rawStorageId) && !is_object($rawStorageId)) {
            $rawStorageIdText = normalizeStorageIdToken($rawStorageId);
            if ($rawStorageIdText !== '') {
                $storageId = $rawStorageIdText;
            }
        }
        $storageId = normalizeStorageIdToken($storageId);

        $nameKey = '__name__:' . strtolower($name);
        if ($storageId !== '') {
            $storageKey = '__sid__:' . strtolower($storageId);
            if (isset($identitiesByKey[$storageKey])) {
                if ($identitiesByKey[$storageKey]['name'] === '') {
                    $identitiesByKey[$storageKey]['name'] = $name;
                }
                return;
            }
            if (isset($identitiesByKey[$nameKey]) && strval($identitiesByKey[$nameKey]['storage_id'] ?? '') === '') {
                $entry = $identitiesByKey[$nameKey];
                unset($identitiesByKey[$nameKey]);
                $entry['storage_id'] = $storageId;
                $identitiesByKey[$storageKey] = $entry;
                return;
            }
            $identitiesByKey[$storageKey] = [
                'name' => $name,
                'storage_id' => $storageId,
            ];
            return;
        }

        if (!isset($identitiesByKey[$nameKey])) {
            $identitiesByKey[$nameKey] = [
                'name' => $name,
                'storage_id' => '',
            ];
        }
    };

    $peopleRaw = $options['people'] ?? '';
    if (is_array($peopleRaw)) {
        foreach ($peopleRaw as $entry) {
            if (is_string($entry)) {
                $addIdentity($entry);
            }
        }
    } elseif (is_string($peopleRaw) && trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $entry) {
                if (is_string($entry)) {
                    $addIdentity($entry);
                }
            }
        } else {
            $addIdentity($peopleRaw);
        }
    }

    $profileName = $options['profile'] ?? '';
    if (is_string($profileName)) {
        $addIdentity($profileName);
    }

    $speakerName = $options['speaker'] ?? '';
    if (is_string($speakerName)) {
        $addIdentity($speakerName);
    }

    $npcs = $options['npcs'] ?? [];
    if (is_array($npcs)) {
        foreach ($npcs as $entry) {
            if (is_string($entry)) {
                $addIdentity($entry);
                continue;
            }
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? ($entry['npc'] ?? ''));
                $candidateId = $entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? null)));
                if ($candidate !== '') {
                    $addIdentity($candidate, $candidateId);
                }
            }
        }
    }

    $nearby = $options['nearby'] ?? [];
    if (is_array($nearby)) {
        foreach ($nearby as $entry) {
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? '');
                $candidateId = $entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? null)));
                if ($candidate !== '') {
                    $addIdentity($candidate, $candidateId);
                }
            } elseif (is_string($entry)) {
                $addIdentity($entry);
            }
        }
    }

    return array_values($identitiesByKey);
}

function ensureOriginalName(string $name, string $fallbackOriginal = ''): string {
    $db = $GLOBALS["db"];
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return '';
    }

    $fallback = baseNameWithoutBracketSuffix($fallbackOriginal);
    if ($fallback === '') {
        $fallback = baseNameWithoutBracketSuffix($normalizedName);
    }
    if ($fallback === '') {
        return '';
    }

    $existing = $db->fetchOne(
        "SELECT original_name FROM core_npc WHERE name = $1",
        [$normalizedName]
    );
    if (!$existing) {
        return $fallback;
    }

    $currentOriginal = trim(strval($existing['original_name'] ?? ''));
    if ($currentOriginal !== '') {
        return $currentOriginal;
    }

    $db->exec(
        "UPDATE core_npc
         SET original_name = $2
         WHERE name = $1
           AND (original_name IS NULL OR BTRIM(original_name) = '')",
        [$normalizedName, $fallback]
    );

    return $fallback;
}

function resolveSnapshotTargetNpcName(string $incomingName, string $incomingStorageId = ''): array {
    $db = $GLOBALS["db"];
    $normalizedIncomingName = normalizeParticipantNameToken($incomingName);
    if ($normalizedIncomingName === '') {
        return [
            'name' => '',
            'matched_by' => 'none',
        ];
    }

    $storageIdVariants = buildStorageIdSearchVariants($incomingStorageId);
    if (count($storageIdVariants) > 0) {
        $params = [];
        $variantPlaceholders = [];
        $index = 1;
        foreach ($storageIdVariants as $variant) {
            $params[] = $variant;
            $variantPlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }

        $byStorageId = $db->fetchOne(
            "SELECT name
             FROM core_npc
             WHERE LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $variantPlaceholders) . ")
             ORDER BY updated_at DESC, gamets_last_updated DESC
             LIMIT 1",
            $params
        );
        if ($byStorageId) {
            $resolvedName = normalizeParticipantNameToken(strval($byStorageId['name'] ?? ''));
            if ($resolvedName !== '') {
                return [
                    'name' => $resolvedName,
                    'matched_by' => 'storage_id',
                ];
            }
        }

        stobeLogImport('Snapshot storage_id did not match existing profile', [
            'incoming_name' => $normalizedIncomingName,
            'incoming_storage_id' => $incomingStorageId,
            'storage_id_variants' => $storageIdVariants,
        ], 'DEBUG');
    }

    $byExactName = $db->fetchOne(
        "SELECT name,
                race,
                faction,
                gender,
                equipment,
                skills,
                COALESCE(metadata->>'storage_id', '') AS storage_id
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedIncomingName]
    );
    if ($byExactName) {
        $resolvedName = normalizeParticipantNameToken(strval($byExactName['name'] ?? ''));
        if ($resolvedName !== '') {
            $exactStorageId = normalizeStorageIdToken(strval($byExactName['storage_id'] ?? ''));
            $exactRace = strtolower(trim(strval($byExactName['race'] ?? '')));
            $exactFaction = trim(strval($byExactName['faction'] ?? ''));
            $exactGender = trim(strval($byExactName['gender'] ?? ''));
            $exactEquipment = trim(strval($byExactName['equipment'] ?? ''));
            $exactSkills = trim(strval($byExactName['skills'] ?? ''));
            $exactLooksPlaceholder =
                $exactStorageId === '' &&
                ($exactRace === '' || $exactRace === 'unknown') &&
                $exactFaction === '' &&
                $exactGender === '' &&
                $exactEquipment === '' &&
                $exactSkills === '';

            if ($exactLooksPlaceholder) {
                $preferredByOriginal = $db->fetchOne(
                    "SELECT name
                     FROM core_npc
                     WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
                       AND COALESCE(metadata->>'storage_id', '') <> ''
                     ORDER BY updated_at DESC, gamets_last_updated DESC
                     LIMIT 1",
                    [$normalizedIncomingName]
                );
                if ($preferredByOriginal) {
                    $preferredName = normalizeParticipantNameToken(strval($preferredByOriginal['name'] ?? ''));
                    if ($preferredName !== '' && strcasecmp($preferredName, $resolvedName) !== 0) {
                        stobeLogImport('Snapshot target remapped away from placeholder exact match', [
                            'incoming_name' => $normalizedIncomingName,
                            'placeholder_name' => $resolvedName,
                            'preferred_name' => $preferredName,
                        ], 'DEBUG');
                        return [
                            'name' => $preferredName,
                            'matched_by' => 'original_name_preferred_over_placeholder',
                        ];
                    }
                }
            }

            return [
                'name' => $resolvedName,
                'matched_by' => 'name',
            ];
        }
    }

    $baseIncomingName = baseNameWithoutBracketSuffix($normalizedIncomingName);
    $originalNameCandidates = [];
    $originalNameCandidates[] = $normalizedIncomingName;
    if (
        $baseIncomingName !== '' &&
        strtolower($baseIncomingName) !== strtolower($normalizedIncomingName)
    ) {
        $originalNameCandidates[] = $baseIncomingName;
    }

    foreach ($originalNameCandidates as $candidateOriginalName) {
        $byOriginalName = $db->fetchOne(
            "SELECT name
             FROM core_npc
             WHERE LOWER(original_name) = LOWER($1)
             ORDER BY updated_at DESC, gamets_last_updated DESC
             LIMIT 1",
            [$candidateOriginalName]
        );
        if ($byOriginalName) {
            $resolvedName = normalizeParticipantNameToken(strval($byOriginalName['name'] ?? ''));
            if ($resolvedName !== '') {
                $ambiguousCount = intval(($db->fetchOne(
                    "SELECT COUNT(*) AS cnt
                     FROM core_npc
                     WHERE LOWER(original_name) = LOWER($1)",
                    [$candidateOriginalName]
                )['cnt'] ?? 0));
                if ($ambiguousCount > 1) {
                    stobeLogImport('Snapshot original_name match is ambiguous', [
                        'incoming_name' => $normalizedIncomingName,
                        'incoming_storage_id' => $incomingStorageId,
                        'candidate_original_name' => $candidateOriginalName,
                        'match_count' => $ambiguousCount,
                        'resolved_name' => $resolvedName,
                    ], 'WARN');
                }
                return [
                    'name' => $resolvedName,
                    'matched_by' => 'original_name',
                ];
            }
        }
    }

    return [
        'name' => $normalizedIncomingName,
        'matched_by' => 'incoming',
    ];
}

function ensureNpcProfilesFromParticipants(array $participantNames): array {
    $identities = [];
    foreach ($participantNames as $name) {
        $candidate = normalizeParticipantNameToken(strval($name));
        if ($candidate === '') {
            continue;
        }
        $identities[] = [
            'name' => $candidate,
            'storage_id' => '',
        ];
    }
    return ensureNpcProfilesFromParticipantIdentities($identities);
}

function ensureNpcProfilesFromParticipantIdentities(array $participantIdentities): array {
    $db = $GLOBALS["db"];
    $normalized = [];
    foreach ($participantIdentities as $entry) {
        if (is_string($entry)) {
            $parsed = extractParticipantIdentityToken($entry);
            $name = strval($parsed['name'] ?? '');
            $storageId = strval($parsed['storage_id'] ?? '');
        } elseif (is_array($entry)) {
            $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
            $storageId = normalizeStorageIdToken($entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? ''))));
        } else {
            continue;
        }

        if ($name === '') {
            continue;
        }
        $storageId = normalizeStorageIdToken($storageId);
        $key = $storageId !== ''
            ? ('__sid__:' . strtolower($storageId))
            : ('__name__:' . strtolower($name));
        if (!isset($normalized[$key])) {
            $normalized[$key] = [
                'name' => $name,
                'storage_id' => $storageId,
            ];
            continue;
        }
        if ($normalized[$key]['storage_id'] === '' && $storageId !== '') {
            $normalized[$key]['storage_id'] = $storageId;
        }
    }

    $entries = array_values($normalized);
    if (count($entries) === 0) {
        return ['parsed' => 0, 'created' => 0, 'updated' => 0, 'participants' => []];
    }

    $nameParams = [];
    $storageParams = [];
    foreach ($entries as $entry) {
        $nameValue = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($nameValue !== '') {
            $nameParams[strtolower($nameValue)] = $nameValue;
        }
        $storageValue = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        if ($storageValue !== '') {
            $storageParams[strtolower($storageValue)] = $storageValue;
        }
    }

    $whereParts = [];
    $params = [];
    $index = 1;
    if (count($nameParams) > 0) {
        $namePlaceholders = [];
        foreach (array_values($nameParams) as $nameValue) {
            $params[] = $nameValue;
            $namePlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }
        $whereParts[] = "LOWER(name) IN (" . implode(',', $namePlaceholders) . ")";
        $whereParts[] = "LOWER(COALESCE(original_name, '')) IN (" . implode(',', $namePlaceholders) . ")";
    }
    if (count($storageParams) > 0) {
        $storagePlaceholders = [];
        foreach (array_values($storageParams) as $storageValue) {
            $params[] = $storageValue;
            $storagePlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }
        $whereParts[] = "LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $storagePlaceholders) . ")";
    }
    if (count($whereParts) === 0) {
        return ['parsed' => count($entries), 'created' => 0, 'updated' => 0, 'participants' => array_map(static fn(array $entry): string => strval($entry['name']), $entries)];
    }

    $rows = $db->fetchAll(
        "SELECT id, name, original_name, COALESCE(metadata->>'storage_id', '') AS storage_id
         FROM core_npc
         WHERE " . implode(' OR ', $whereParts),
        $params
    );
    $existingByLookup = [];
    foreach ($rows as $row) {
        $existingName = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($existingName !== '') {
            $existingByLookup['name:' . strtolower($existingName)] = $row;
        }
        $existingOriginal = normalizeParticipantNameToken(strval($row['original_name'] ?? ''));
        if ($existingOriginal !== '') {
            $existingByLookup['original:' . strtolower($existingOriginal)] = $row;
        }
        $existingStorageId = normalizeStorageIdToken(strval($row['storage_id'] ?? ''));
        if ($existingStorageId !== '') {
            $existingByLookup['storage:' . strtolower($existingStorageId)] = $row;
        }
    }

    $created = 0;
    $updated = 0;
    foreach ($entries as $entry) {
        $name = strval($entry['name']);
        $storageId = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        $lookupOrder = [];
        if ($storageId !== '') {
            $lookupOrder[] = 'storage:' . strtolower($storageId);
        }
        $lookupOrder[] = 'name:' . strtolower($name);
        $lookupOrder[] = 'original:' . strtolower($name);

        $matchedRow = null;
        foreach ($lookupOrder as $lookupKey) {
            if (isset($existingByLookup[$lookupKey])) {
                $matchedRow = $existingByLookup[$lookupKey];
                break;
            }
        }

        if (!$matchedRow) {
            $bootstrapProfile = [];
            if ($storageId !== '') {
                $bootstrapProfile['metadata'] = ['storage_id' => $storageId];
            }
            storeNpcProfile($name, $bootstrapProfile);
            ensureOriginalName($name, $name);
            $created++;
            if (strpos($name, '[') !== false) {
                stobeLogImport('Bracket participant JIT created placeholder', [
                    'name' => $name,
                    'storage_id' => $storageId,
                    'lookup_order' => $lookupOrder,
                ], 'WARN');
            }
            continue;
        }

        $existingStorageId = normalizeStorageIdToken(strval($matchedRow['storage_id'] ?? ''));
        if ($storageId !== '' && $existingStorageId === '') {
            $db->exec(
                "UPDATE core_npc
                 SET metadata = CASE
                     WHEN metadata IS NULL
                       OR metadata = '[]'::jsonb
                       OR jsonb_typeof(metadata) <> 'object'
                     THEN jsonb_build_object('storage_id', $2::text)
                     ELSE metadata || jsonb_build_object('storage_id', $2::text)
                 END,
                 updated_at = NOW()
                 WHERE id = $1",
                [intval($matchedRow['id'] ?? 0), $storageId]
            );
            $updated++;
            if (strpos($name, '[') !== false) {
                stobeLogImport('Bracket participant JIT attached storage_id', [
                    'name' => $name,
                    'storage_id' => $storageId,
                    'matched_name' => strval($matchedRow['name'] ?? ''),
                    'matched_original_name' => strval($matchedRow['original_name'] ?? ''),
                ], 'DEBUG');
            }
        } elseif (strpos($name, '[') !== false) {
            stobeLogImport('Bracket participant JIT matched existing', [
                'name' => $name,
                'storage_id' => $storageId,
                'matched_name' => strval($matchedRow['name'] ?? ''),
                'matched_original_name' => strval($matchedRow['original_name'] ?? ''),
                'matched_storage_id' => $existingStorageId,
            ], 'DEBUG');
        }
    }

    return [
        'parsed' => count($entries),
        'created' => $created,
        'updated' => $updated,
        'participants' => array_map(static fn(array $entry): string => strval($entry['name']), $entries),
    ];
}

function normalizeGenderHint(string $gender): string {
    $genderKey = strtolower(trim($gender));
    if (strpos($genderKey, 'f') === 0) {
        return 'female';
    }
    if (strpos($genderKey, 'm') === 0) {
        return 'male';
    }
    if (strpos($genderKey, 'n') === 0) {
        return 'neutral';
    }
    return '';
}

function normalizeNamePoolTrait(string $value): string {
    $normalized = strtolower(trim((string)preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $value)));
    if ($normalized === '' || $normalized === 'unknown' || $normalized === 'null') {
        return '';
    }
    return $normalized;
}

function extractBracketSuffixName(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('/\[\s*([^\]]+?)\s*\]\s*$/u', $trimmed, $matches) !== 1) {
        return '';
    }
    return trim(strval($matches[1] ?? ''));
}

function getBioRandomLookupNames(string $name = '', string $originalName = ''): array {
    $names = [];
    $append = static function (string $candidate) use (&$names): void {
        $normalized = normalizeNamePoolTrait($candidate);
        if ($normalized === '') {
            return;
        }
        if (!in_array($normalized, $names, true)) {
            $names[] = $normalized;
        }
    };

    $append($originalName);
    $append(extractBracketSuffixName($name));
    $append(baseNameWithoutBracketSuffix($name));
    $append($name);

    return $names;
}

function normalizeBioUniqueName(string $name): string {
    $normalized = strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
    if ($normalized === '' || $normalized === 'unknown' || $normalized === 'null') {
        return '';
    }
    return $normalized;
}

function normalizeBioTraitType(string $value): string {
    $raw = strtolower(trim($value));
    if ($raw === '') {
        return '';
    }

    $compact = preg_replace('/[^a-z]/', '', $raw);
    if (!is_string($compact) || $compact === '') {
        return '';
    }

    if ($compact === 'personality') {
        return 'personality';
    }
    if ($compact === 'backstory') {
        return 'backstory';
    }
    if ($compact === 'speechstyle' || $compact === 'speechtyle') {
        return 'speechstyle';
    }
    if ($compact === 'occupation') {
        return 'occupation';
    }
    if ($compact === 'appearance' || $compact === 'appearence') {
        return 'appearance';
    }
    if ($compact === 'goal' || $compact === 'goals') {
        return 'goals';
    }

    return '';
}

function getRequiredBioTraitTypes(): array {
    return ['personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'];
}

function getBioUniqueLookupKeys(string $name): array {
    $keys = [];
    $appendKey = static function (string $candidate) use (&$keys): void {
        if ($candidate === '') {
            return;
        }
        if (!in_array($candidate, $keys, true)) {
            $keys[] = $candidate;
        }
    };

    $appendKey(normalizeBioUniqueName($name));
    $appendKey(normalizeBioUniqueName(baseNameWithoutBracketSuffix($name)));
    return $keys;
}

function loadBioUniqueTraits(string $name): array {
    static $cache = [];
    $requiredTypes = getRequiredBioTraitTypes();
    $result = [];
    foreach ($requiredTypes as $type) {
        $result[$type] = '';
    }

    $lookupKeys = getBioUniqueLookupKeys($name);
    if (count($lookupKeys) === 0) {
        return $result;
    }

    $cacheKey = implode('|', $lookupKeys);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $rows = [];
    try {
        if (count($lookupKeys) === 1) {
            $rows = $db->fetchAll(
                "SELECT name, type, description
                 FROM combined_bio_unique
                 WHERE LOWER(name) = $1
                 ORDER BY id ASC",
                [$lookupKeys[0]]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT name, type, description
                 FROM combined_bio_unique
                 WHERE LOWER(name) IN ($1, $2)
                 ORDER BY
                    CASE
                        WHEN LOWER(name) = $1 THEN 0
                        WHEN LOWER(name) = $2 THEN 1
                        ELSE 2
                    END,
                    id ASC",
                [$lookupKeys[0], $lookupKeys[1]]
            );
        }
    } catch (Throwable $exception) {
        $cache[$cacheKey] = $result;
        return $cache[$cacheKey];
    }

    foreach ($rows as $row) {
        $type = normalizeBioTraitType(strval($row['type'] ?? ''));
        if ($type === '' || !array_key_exists($type, $result)) {
            continue;
        }
        if (trim(strval($result[$type])) !== '') {
            continue;
        }
        $description = trim(strval($row['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $result[$type] = $description;
    }

    $cache[$cacheKey] = $result;
    return $result;
}

function loadBioRandomCandidates(
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $name = '',
    string $originalName = ''
): array {
    static $cache = [];
    $genderHint = normalizeGenderHint($gender);
    $raceKey = normalizeNamePoolTrait($race);
    $factionKey = normalizeNamePoolTrait($faction);
    $lookupNames = getBioRandomLookupNames($name, $originalName);
    $cacheKey = $genderHint . '|' . $raceKey . '|' . $factionKey . '|' . implode(',', $lookupNames);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $requiredTypes = getRequiredBioTraitTypes();
    $specificityLevels = [7, 6, 5, 4, 3, 2, 1, 0];
    $candidatesByType = [];
    foreach ($requiredTypes as $requiredType) {
        $candidatesByType[$requiredType] = [];
        foreach ($specificityLevels as $level) {
            $candidatesByType[$requiredType][$level] = [];
        }
    }
    $lookupNameSet = [];
    foreach ($lookupNames as $candidateName) {
        $lookupNameSet[$candidateName] = true;
    }

    $db = $GLOBALS["db"];
    $rows = [];
    try {
        $rows = $db->fetchAll(
            "SELECT type, description, race, gender, faction, name
             FROM combined_bio_random
             ORDER BY id ASC"
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = $candidatesByType;
        return $cache[$cacheKey];
    }

    foreach ($rows as $row) {
        $type = normalizeBioTraitType(strval($row['type'] ?? ''));
        if ($type === '' || !isset($candidatesByType[$type])) {
            continue;
        }

        $description = trim(strval($row['description'] ?? ''));
        if ($description === '') {
            continue;
        }

        $rowRace = normalizeNamePoolTrait(strval($row['race'] ?? ''));
        $rowFaction = normalizeNamePoolTrait(strval($row['faction'] ?? ''));
        $rowGender = normalizeGenderHint(strval($row['gender'] ?? ''));
        $rowName = normalizeNamePoolTrait(strval($row['name'] ?? ''));

        if ($rowName !== '' && !isset($lookupNameSet[$rowName])) {
            continue;
        }

        if ($rowRace !== '' && ($raceKey === '' || $rowRace !== $raceKey)) {
            continue;
        }
        if ($rowFaction !== '' && ($factionKey === '' || $rowFaction !== $factionKey)) {
            continue;
        }
        if ($rowGender !== '' && $rowGender !== 'neutral' && ($genderHint === '' || $rowGender !== $genderHint)) {
            continue;
        }

        $specificity = 0;
        if ($rowName !== '') {
            // Name-matched rows should win over generic race/gender/faction rows.
            $specificity += 4;
        }
        if ($rowRace !== '') {
            $specificity++;
        }
        if ($rowFaction !== '') {
            $specificity++;
        }
        if ($rowGender !== '' && $rowGender !== 'neutral') {
            $specificity++;
        }
        if ($specificity < 0) {
            $specificity = 0;
        } elseif ($specificity > 7) {
            $specificity = 7;
        }

        $descriptionKey = strtolower($description);
        if (!isset($candidatesByType[$type][$specificity][$descriptionKey])) {
            $candidatesByType[$type][$specificity][$descriptionKey] = $description;
        }
    }

    foreach ($requiredTypes as $requiredType) {
        foreach ($specificityLevels as $specificity) {
            $candidatesByType[$requiredType][$specificity] = array_values($candidatesByType[$requiredType][$specificity]);
        }
    }

    $cache[$cacheKey] = $candidatesByType;
    return $candidatesByType;
}

function selectRandomBioTraits(
    string $seedName = '',
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $name = '',
    string $originalName = ''
): array {
    $requiredTypes = getRequiredBioTraitTypes();
    $specificityLevels = [7, 6, 5, 4, 3, 2, 1, 0];
    $candidatesByType = loadBioRandomCandidates($race, $gender, $faction, $name, $originalName);
    $traits = [];

    foreach ($requiredTypes as $type) {
        if (!isset($candidatesByType[$type])) {
            continue;
        }

        $options = [];
        foreach ($specificityLevels as $specificity) {
            if (count($candidatesByType[$type][$specificity] ?? []) > 0) {
                $options = $candidatesByType[$type][$specificity];
                break;
            }
        }

        if (count($options) === 0) {
            continue;
        }

        if ($seedName !== '') {
            $bestIndex = 0;
            $bestRank = null;
            foreach ($options as $index => $option) {
                $rank = intval(sprintf('%u', crc32(strtolower($seedName . '|' . $type . '|' . $option))));
                if ($bestRank === null || $rank < $bestRank) {
                    $bestRank = $rank;
                    $bestIndex = $index;
                }
            }
            $traits[$type] = strval($options[$bestIndex] ?? '');
            continue;
        }

        $randomIndex = random_int(0, count($options) - 1);
        $traits[$type] = strval($options[$randomIndex] ?? '');
    }

    return $traits;
}

function selectBioTraitsForNpc(
    string $name = '',
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $originalName = ''
): array {
    $requiredTypes = getRequiredBioTraitTypes();
    $uniqueTraits = loadBioUniqueTraits($name);
    $randomTraits = selectRandomBioTraits($name, $race, $gender, $faction, $name, $originalName);
    $resolvedTraits = [];
    $traitSources = [];

    foreach ($requiredTypes as $type) {
        $uniqueTrait = trim(strval($uniqueTraits[$type] ?? ''));
        if ($uniqueTrait !== '') {
            $resolvedTraits[$type] = $uniqueTrait;
            $traitSources[$type] = 'unique';
            continue;
        }

        $randomTrait = trim(strval($randomTraits[$type] ?? ''));
        if ($randomTrait !== '') {
            $resolvedTraits[$type] = $randomTrait;
            $traitSources[$type] = 'random';
            continue;
        }

        $traitSources[$type] = 'default';
    }

    return [
        'traits' => $resolvedTraits,
        'sources' => $traitSources,
    ];
}

function loadGlobalNamePool(string $gender = '', string $race = '', string $faction = ''): array {
    static $cache = [];
    $genderHint = normalizeGenderHint($gender);
    $raceKey = normalizeNamePoolTrait($race);
    $factionKey = normalizeNamePoolTrait($faction);
    $cacheKey = $genderHint . '|' . $raceKey . '|' . $factionKey;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $rows = [];

    $relationExists = static function (string $name, string $kind) use ($db): bool {
        $tableName = trim($name);
        if ($tableName === '') {
            return false;
        }
        if ($kind === 'view') {
            $probe = $db->fetchOne(
                "SELECT 1 AS present
                 FROM information_schema.views
                 WHERE table_schema = 'public'
                   AND table_name = $1
                 LIMIT 1",
                [$tableName]
            );
            return is_array($probe) && count($probe) > 0;
        }
        $probe = $db->fetchOne(
            "SELECT 1 AS present
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name = $1
             LIMIT 1",
            [$tableName]
        );
        return is_array($probe) && count($probe) > 0;
    };

    $viewSource = '';
    if ($relationExists('combined_rename_global', 'view')) {
        $viewSource = 'combined_rename_global';
    }

    if ($viewSource !== '') {
        $rows = $db->fetchAll(
            "SELECT name, gender, race, faction
             FROM {$viewSource}
             ORDER BY name ASC"
        );
    } else {
        // Last-resort fallback: compose pool from rename_global tables.
        $poolByName = [];
        $customTables = ['rename_global_custom'];
        $baseTables = ['rename_global'];

        foreach ($customTables as $tableName) {
            if (!$relationExists($tableName, 'table')) {
                continue;
            }
            $tableRows = $db->fetchAll(
                "SELECT name, gender, race, faction
                 FROM {$tableName}
                 ORDER BY name ASC"
            );
            foreach ($tableRows as $row) {
                $candidate = trim(strval($row['name'] ?? ''));
                if ($candidate === '') {
                    continue;
                }
                $poolByName[strtolower($candidate)] = $row;
            }
        }

        foreach ($baseTables as $tableName) {
            if (!$relationExists($tableName, 'table')) {
                continue;
            }
            $tableRows = $db->fetchAll(
                "SELECT name, gender, race, faction
                 FROM {$tableName}
                 ORDER BY name ASC"
            );
            foreach ($tableRows as $row) {
                $candidate = trim(strval($row['name'] ?? ''));
                if ($candidate === '') {
                    continue;
                }
                $key = strtolower($candidate);
                if (isset($poolByName[$key])) {
                    continue;
                }
                $poolByName[$key] = $row;
            }
        }

        $rows = array_values($poolByName);
    }

    $tierExact = [];
    $tierPartial = [];
    $tierGeneral = [];
    foreach ($rows as $row) {
        $candidate = baseNameWithoutBracketSuffix(trim(strval($row['name'] ?? '')));
        if ($candidate === '') {
            continue;
        }

        $rowGender = normalizeGenderHint(strval($row['gender'] ?? ''));
        if ($genderHint !== '' && $rowGender !== '' && $rowGender !== 'neutral' && $rowGender !== $genderHint) {
            continue;
        }

        $rowRace = normalizeNamePoolTrait(strval($row['race'] ?? ''));
        $rowFaction = normalizeNamePoolTrait(strval($row['faction'] ?? ''));
        if ($rowRace !== '' && ($raceKey === '' || $rowRace !== $raceKey)) {
            continue;
        }
        if ($rowFaction !== '' && ($factionKey === '' || $rowFaction !== $factionKey)) {
            continue;
        }

        $key = strtolower($candidate);
        $isRaceSpecific = $rowRace !== '';
        $isFactionSpecific = $rowFaction !== '';
        if ($isRaceSpecific && $isFactionSpecific) {
            if (!isset($tierExact[$key])) {
                $tierExact[$key] = $candidate;
            }
            continue;
        }
        if ($isRaceSpecific || $isFactionSpecific) {
            if (!isset($tierExact[$key]) && !isset($tierPartial[$key])) {
                $tierPartial[$key] = $candidate;
            }
            continue;
        }
        if (!isset($tierExact[$key]) && !isset($tierPartial[$key]) && !isset($tierGeneral[$key])) {
            $tierGeneral[$key] = $candidate;
        }
    }

    $cache[$cacheKey] = array_values(array_merge($tierExact, $tierPartial, $tierGeneral));
    return $cache[$cacheKey];
}

function isNpcNameTaken(string $name): bool {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT id FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$name]
    );
    return $row !== false;
}

function generateUniqueLoreName(string $gender = '', string $seedName = '', string $race = '', string $faction = ''): string {
    $pool = loadGlobalNamePool($gender, $race, $faction);
    if (count($pool) === 0 && ($gender !== '' || $race !== '' || $faction !== '')) {
        $pool = loadGlobalNamePool('', '', '');
    }
    if (count($pool) === 0) {
        return 'Wanderer';
    }

    $seed = $seedName !== '' ? $seedName : (string)microtime(true);
    $unsignedHash = intval(sprintf('%u', crc32(strtolower($seed))));
    $startIndex = $unsignedHash % count($pool);

    for ($offset = 0; $offset < count($pool); $offset++) {
        $candidate = $pool[($startIndex + $offset) % count($pool)];
        if (!isNpcNameTaken($candidate)) {
            return $candidate;
        }
    }

    $base = $pool[$startIndex];
    $suffix = 2;
    while (isNpcNameTaken($base . ' ' . $suffix)) {
        $suffix++;
    }
    return $base . ' ' . $suffix;
}

function shouldBracketOriginalNameOnAutoRename(): bool {
    static $cached = null;
    if ($cached !== null) {
        return boolval($cached);
    }
    $cached = getSettingBool('BRACKET_ORIGINAL_NAME', true);
    return boolval($cached);
}

function formatAutoRenameWithOriginal(string $generatedName, string $originalName): string {
    $generated = baseNameWithoutBracketSuffix(trim($generatedName));
    $original = baseNameWithoutBracketSuffix(trim($originalName));
    if ($generated === '' || $original === '') {
        return $generated !== '' ? $generated : $generatedName;
    }
    if (strtolower($generated) === strtolower($original)) {
        return $generated;
    }
    if (!shouldBracketOriginalNameOnAutoRename()) {
        return $generated;
    }
    return $generated . ' [' . $original . ']';
}

function buildIdentityRenameBootstrapProfile(array $entry, string $storageId): array {
    $pick = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (is_array($source[$key]) || is_object($source[$key])) {
                continue;
            }
            $value = trim(strval($source[$key]));
            if ($value === '') {
                continue;
            }
            return $value;
        }
        return '';
    };

    $race = $pick($entry, ['race', 'race_name']);
    if (strtolower($race) === 'unknown') {
        $race = '';
    }

    $faction = $pick($entry, ['faction', 'faction_name', 'origin_faction']);
    if (strtolower($faction) === 'unknown') {
        $faction = '';
    }
    $factionId = $pick($entry, ['faction_id', 'factionID']);
    $faction = composeFactionWithId($faction, $factionId);

    $gender = $pick($entry, ['gender', 'sex']);
    if (strtolower($gender) === 'unknown') {
        $gender = '';
    }

    $equipment = $pick($entry, ['equipment']);
    $inventory = $pick($entry, ['inventory']);
    $skills = $pick($entry, ['skills']);

    $profile = [];
    if ($race !== '') {
        $profile['race'] = $race;
    }
    if ($faction !== '') {
        $profile['faction'] = $faction;
        $profile['occupation'] = 'Faction: ' . $faction;
        $profile['goals'] = 'Survive, recover, and pursue faction priorities.';
    }
    if ($gender !== '') {
        $profile['gender'] = $gender;
    }
    if ($equipment !== '') {
        $profile['equipment'] = $equipment;
    }
    if ($inventory !== '') {
        $profile['inventory'] = $inventory;
    }
    if ($skills !== '') {
        $profile['skills'] = $skills;
    }

    if ($storageId !== '') {
        $profile['metadata'] = [
            'storage_id' => $storageId,
            'source' => 'batch_identity_rename',
        ];
    }

    return $profile;
}

function loadServerRenameEligibilityTokens(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $tokens = [];
    $db = $GLOBALS["db"] ?? null;
    if ($db) {
        $viewProbe = $db->fetchOne(
            "SELECT to_regclass('public.combined_rename_token_global') AS rel"
        );
        $hasView = trim(strval($viewProbe['rel'] ?? '')) !== '';
        if ($hasView) {
            $rows = $db->fetchAll(
                "SELECT token
                 FROM combined_rename_token_global
                 ORDER BY token ASC"
            );
            foreach ($rows as $row) {
                $token = trim(strval($row['token'] ?? ''));
                if ($token === '') {
                    continue;
                }
                $tokens[] = strtolower($token);
            }
        }
    }

    $normalized = [];
    $seen = [];
    foreach ($tokens as $token) {
        $clean = strtolower(trim(strval($token)));
        if ($clean === '') {
            continue;
        }
        if (isset($seen[$clean])) {
            continue;
        }
        $seen[$clean] = true;
        $normalized[] = $clean;
    }

    if (count($normalized) === 0) {
        stobeLogWarn('Rename eligibility token list is empty; no auto-renames will trigger', [
            'source' => 'combined_rename_token_global',
        ]);
    }

    $cache = $normalized;
    return $cache;
}

function isServerRenameEligibleName(string $name): bool {
    $tokens = loadServerRenameEligibilityTokens();

    $normalized = normalizeParticipantNameToken($name);
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, '[') !== false || strpos($normalized, ']') !== false) {
        return false;
    }

    $lower = strtolower($normalized);
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        if (strpos($lower, strtolower($token)) !== false) {
            return true;
        }
    }
    return false;
}

function batchIdentityRenameDecisions(array $identities): array {
    $normalizedNames = [];
    foreach ($identities as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        // Avoid creating placeholder profiles for names that are about to be
        // auto-renamed; those placeholders can later conflict by storage_id.
        if ($name !== '' && !isServerRenameEligibleName($name)) {
            $normalizedNames[] = $name;
        }
    }
    if (count($normalizedNames) > 0) {
        ensureNpcProfilesFromParticipants($normalizedNames);
    }

    $results = [];
    foreach ($identities as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $context = [];
        if (isset($entry['context']) && is_array($entry['context'])) {
            $context = $entry['context'];
        }
        $identitySource = $entry;
        if (count($context) > 0) {
            $contextBridgeKeys = [
                'gender', 'sex',
                'race', 'race_name',
                'faction', 'faction_name', 'origin_faction',
                'faction_id', 'factionID',
                'equipment',
                'skills',
                'inventory',
                'storage_id', 'id', 'refid', 'handle',
            ];
            foreach ($contextBridgeKeys as $bridgeKey) {
                if (!array_key_exists($bridgeKey, $context)) {
                    continue;
                }
                if (array_key_exists($bridgeKey, $identitySource)) {
                    $existing = $identitySource[$bridgeKey];
                    if (!is_array($existing) && !is_object($existing) && trim(strval($existing)) !== '') {
                        continue;
                    }
                }
                $identitySource[$bridgeKey] = $context[$bridgeKey];
            }
        }

        $serial = strval($entry['serial'] ?? '');
        $currentName = normalizeParticipantNameToken(strval($identitySource['name'] ?? ''));
        $storageId = normalizeStorageIdToken(
            $identitySource['storage_id'] ?? ($identitySource['id'] ?? ($identitySource['refid'] ?? ($identitySource['handle'] ?? $serial)))
        );
        $gender = strval($identitySource['gender'] ?? ($identitySource['sex'] ?? ''));
        $race = strval($identitySource['race'] ?? ($identitySource['race_name'] ?? ''));
        $faction = strval($identitySource['faction'] ?? ($identitySource['faction_name'] ?? ($identitySource['origin_faction'] ?? '')));

        if ($currentName === '') {
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }
        $existingNpc = getNpcData($currentName);
        if (is_array($existingNpc)) {
            $existingOriginal = normalizeParticipantNameToken(strval($existingNpc['original_name'] ?? ''));
            if ($existingOriginal !== '' && strcasecmp($existingOriginal, $currentName) !== 0) {
                stobeLogImport('Batch identity rename skipped (already renamed)', [
                    'serial' => $serial,
                    'current_name' => $currentName,
                    'original_name' => $existingOriginal,
                    'storage_id' => $storageId,
                ], 'DEBUG');
                $results[] = ['serial' => $serial, 'status' => 'ok'];
                continue;
            }
        }
        if (!isServerRenameEligibleName($currentName)) {
            stobeLogImport('Batch identity rename skipped (not eligible)', [
                'serial' => $serial,
                'current_name' => $currentName,
                'storage_id' => $storageId,
            ], 'DEBUG');
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }

        $firstSeenOriginal = ensureOriginalName($currentName, $currentName);
        $generated = generateUniqueLoreName($gender, $currentName, $race, $faction);
        $generatedBase = baseNameWithoutBracketSuffix($generated);
        if ($generatedBase === '') {
            $generatedBase = 'Wanderer';
        }
        $renameCandidates = [];
        $renameCandidates[] = formatAutoRenameWithOriginal($generatedBase, $firstSeenOriginal);
        for ($suffix = 2; $suffix <= 12; $suffix++) {
            $renameCandidates[] = formatAutoRenameWithOriginal($generatedBase . ' ' . $suffix, $firstSeenOriginal);
        }

        $uniqueCandidates = [];
        $seenCandidates = [];
        foreach ($renameCandidates as $candidateName) {
            $candidateNormalized = normalizeParticipantNameToken($candidateName);
            if ($candidateNormalized === '' || strtolower($candidateNormalized) === strtolower($currentName)) {
                continue;
            }
            $key = strtolower($candidateNormalized);
            if (isset($seenCandidates[$key])) {
                continue;
            }
            $seenCandidates[$key] = true;
            $uniqueCandidates[] = $candidateNormalized;
        }
        if (count($uniqueCandidates) === 0) {
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }

        $bootstrapProfile = buildIdentityRenameBootstrapProfile($identitySource, $storageId);
        $renamePersistResult = ['status' => 'error', 'message' => 'No rename candidates attempted'];
        $newName = '';
        foreach ($uniqueCandidates as $candidateName) {
            if (strpos($candidateName, '[') !== false) {
                stobeLogImport('Batch identity rename candidate', [
                    'serial' => $serial,
                    'storage_id' => $storageId,
                    'current_name' => $currentName,
                    'new_name' => $candidateName,
                    'input_gender' => trim($gender),
                    'input_race' => trim($race),
                    'input_faction' => trim($faction),
                    'seeded_keys' => array_keys($bootstrapProfile),
                    'has_context' => count($context) > 0,
                ], count($bootstrapProfile) > 1 ? 'DEBUG' : 'WARN');
            }

            $renamePersistResult = persistManualRename($currentName, $candidateName, $storageId, $bootstrapProfile);
            $persistStatus = strtolower(trim(strval($renamePersistResult['status'] ?? '')));
            if ($persistStatus === 'ok') {
                $newName = $candidateName;
                $persistedNewName = normalizeParticipantNameToken(strval($renamePersistResult['new_name'] ?? ''));
                if ($persistedNewName !== '') {
                    $newName = $persistedNewName;
                }
                break;
            }

            $persistMessage = strtolower(trim(strval($renamePersistResult['message'] ?? '')));
            if ($persistMessage === 'name already exists') {
                continue;
            }
            break;
        }
        if ($newName === '') {
            stobeLogImport('Batch identity rename persistence failed', [
                'serial' => $serial,
                'storage_id' => $storageId,
                'current_name' => $currentName,
                'attempted_candidates' => $uniqueCandidates,
                'persist_result' => $renamePersistResult,
            ], 'WARN');
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }
        if (count($context) > 0) {
            $snapshot = $context;
            $snapshot['name'] = $newName;
            if ($storageId !== '' && (!isset($snapshot['storage_id']) || trim(strval($snapshot['storage_id'])) === '')) {
                $snapshot['storage_id'] = $storageId;
            }
            if (!isset($snapshot['race']) && isset($bootstrapProfile['race'])) {
                $snapshot['race'] = $bootstrapProfile['race'];
            }
            if (!isset($snapshot['faction']) && isset($bootstrapProfile['faction'])) {
                $snapshot['faction'] = $bootstrapProfile['faction'];
            }
            if (!isset($snapshot['gender']) && isset($bootstrapProfile['gender'])) {
                $snapshot['gender'] = $bootstrapProfile['gender'];
            }
            $contextGameTs = intval($context['game_ts'] ?? ($entry['game_ts'] ?? 0));
            if ($contextGameTs < 0) {
                $contextGameTs = 0;
            }
            $contextHydrated = storeNpcSnapshot($snapshot, $contextGameTs);
            stobeLogImport('Batch identity rename snapshot hydrate', [
                'serial' => $serial,
                'new_name' => $newName,
                'storage_id' => normalizeStorageIdToken($snapshot['storage_id'] ?? ''),
                'stored' => $contextHydrated,
                'game_ts' => $contextGameTs,
                'snapshot_keys' => array_keys($snapshot),
                'stats_count' => is_array($snapshot['stats'] ?? null) ? count($snapshot['stats']) : 0,
                'has_medical' => is_array($snapshot['medical'] ?? null) && count($snapshot['medical']) > 0,
                'equipment_len' => strlen(trim(strval($snapshot['equipment'] ?? ''))),
            ], $contextHydrated ? 'DEBUG' : 'WARN');
        }

        $results[] = [
            'serial' => $serial,
            'status' => 'rename',
            'new_name' => $newName,
        ];
    }

    return $results;
}

function persistManualRename(
    string $oldName,
    string $newName,
    string $storageId = '',
    array $bootstrapProfile = []
): array {
    $db = $GLOBALS["db"];
    $oldNormalized = normalizeParticipantNameToken($oldName);
    $newNormalized = normalizeParticipantNameToken($newName);
    $storageIdNormalized = normalizeStorageIdToken($storageId);

    if ($oldNormalized === '' || $newNormalized === '') {
        return ['status' => 'error', 'message' => 'Missing names'];
    }

    if (strtolower($oldNormalized) === strtolower($newNormalized)) {
        return ['status' => 'ok', 'new_name' => $newNormalized];
    }

    $oldRow = $db->fetchOne(
        "SELECT id, name, original_name FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$oldNormalized]
    );
    if ($storageIdNormalized !== '') {
        $storageIdVariants = buildStorageIdSearchVariants($storageIdNormalized);
        if (count($storageIdVariants) > 0) {
            $storageParams = [];
            $variantPlaceholders = [];
            $variantIndex = 1;
            foreach ($storageIdVariants as $variant) {
                $storageParams[] = $variant;
                $variantPlaceholders[] = "LOWER($" . $variantIndex . ")";
                $variantIndex++;
            }
            $storageMatchedRow = $db->fetchOne(
                "SELECT id, name, original_name
                 FROM core_npc
                 WHERE LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $variantPlaceholders) . ")
                 ORDER BY updated_at DESC, gamets_last_updated DESC
                 LIMIT 1",
                $storageParams
            );
            if ($storageMatchedRow) {
                if (!$oldRow) {
                    $oldRow = $storageMatchedRow;
                    stobeLogImport('Manual rename source resolved by storage_id', [
                        'requested_old_name' => $oldNormalized,
                        'resolved_old_name' => strval($oldRow['name'] ?? ''),
                        'new_name' => $newNormalized,
                        'storage_id' => $storageIdNormalized,
                    ], 'DEBUG');
                } else {
                    $oldRowId = intval($oldRow['id'] ?? 0);
                    $storageRowId = intval($storageMatchedRow['id'] ?? 0);
                    $oldRowName = normalizeParticipantNameToken(strval($oldRow['name'] ?? ''));
                    $storageRowName = normalizeParticipantNameToken(strval($storageMatchedRow['name'] ?? ''));
                    $oldLooksPlaceholder = $oldRowName !== '' && strcasecmp($oldRowName, $oldNormalized) === 0;
                    $storageIsDifferentName = $storageRowName !== '' && strcasecmp($storageRowName, $oldNormalized) !== 0;
                    if ($oldLooksPlaceholder && $storageIsDifferentName && $oldRowId > 0 && $storageRowId > 0 && $oldRowId !== $storageRowId) {
                        $oldRow = $storageMatchedRow;
                        stobeLogImport('Manual rename source switched to storage_id canonical row', [
                            'requested_old_name' => $oldNormalized,
                            'placeholder_row_id' => $oldRowId,
                            'canonical_row_id' => $storageRowId,
                            'canonical_name' => $storageRowName,
                            'new_name' => $newNormalized,
                            'storage_id' => $storageIdNormalized,
                        ], 'DEBUG');
                    }
                }
            }
        }
    }
    $newRow = $db->fetchOne(
        "SELECT id, name FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$newNormalized]
    );

    if (!$oldRow) {
        if ($newRow) {
            if (count($bootstrapProfile) > 0) {
                storeNpcProfile($newNormalized, $bootstrapProfile);
            }
            return ['status' => 'ok', 'new_name' => strval($newRow['name'])];
        }
        $resolvedBootstrap = $bootstrapProfile;
        if ($storageIdNormalized !== '') {
            $existingMetadata = [];
            if (isset($resolvedBootstrap['metadata']) && is_array($resolvedBootstrap['metadata'])) {
                $existingMetadata = $resolvedBootstrap['metadata'];
            }
            $existingMetadata['storage_id'] = $storageIdNormalized;
            $resolvedBootstrap['metadata'] = $existingMetadata;
        }
        storeNpcProfile($newNormalized, $resolvedBootstrap);
        if (strpos($newNormalized, '[') !== false) {
            stobeLogImport('Manual rename bootstrap created row', [
                'requested_old_name' => $oldNormalized,
                'new_name' => $newNormalized,
                'storage_id' => $storageIdNormalized,
                'bootstrap_keys' => array_keys($resolvedBootstrap),
            ], count($resolvedBootstrap) > 1 ? 'DEBUG' : 'WARN');
        }
        ensureOriginalName($newNormalized, baseNameWithoutBracketSuffix($oldNormalized));
        return ['status' => 'ok', 'new_name' => $newNormalized];
    }

    $oldId = intval($oldRow['id'] ?? 0);
    $newId = intval($newRow['id'] ?? 0);
    if ($newRow && $newId !== $oldId) {
        return ['status' => 'error', 'message' => 'Name already exists'];
    }

    $existingOriginal = trim(strval($oldRow['original_name'] ?? ''));
    if ($existingOriginal === '') {
        $existingOriginal = baseNameWithoutBracketSuffix(strval($oldRow['name'] ?? $oldNormalized));
    }

    $result = $db->exec(
        "UPDATE core_npc
         SET name = $1,
             original_name = CASE
                 WHEN original_name IS NULL OR BTRIM(original_name) = '' THEN $2
                 ELSE original_name
             END,
             updated_at = NOW()
         WHERE id = $3",
        [$newNormalized, $existingOriginal, $oldId]
    );
    if ($result === false) {
        return ['status' => 'error', 'message' => 'Failed to update profile'];
    }

    $bootstrapOccupation = trim(strval($bootstrapProfile['occupation'] ?? ''));
    if ($bootstrapOccupation !== '') {
        $db->exec(
            "UPDATE core_npc
             SET occupation = $1,
                 updated_at = NOW()
             WHERE id = $2",
            [$bootstrapOccupation, $oldId]
        );
    }
    $bootstrapGoals = trim(strval($bootstrapProfile['goals'] ?? ''));
    if ($bootstrapGoals !== '') {
        $db->exec(
            "UPDATE core_npc
             SET goals = $1,
                 updated_at = NOW()
             WHERE id = $2",
            [$bootstrapGoals, $oldId]
        );
    }

    if ($storageIdNormalized !== '') {
        $db->exec(
            "UPDATE core_npc
             SET metadata = CASE
                 WHEN metadata IS NULL
                   OR metadata = '[]'::jsonb
                   OR jsonb_typeof(metadata) <> 'object'
                 THEN jsonb_build_object('storage_id', $2::text)
                 ELSE metadata || jsonb_build_object('storage_id', $2::text)
             END,
             updated_at = NOW()
             WHERE id = $1",
            [$oldId, $storageIdNormalized]
        );
    }

    if (count($bootstrapProfile) > 0) {
        $resolvedBootstrap = $bootstrapProfile;
        if ($storageIdNormalized !== '') {
            $existingMetadata = [];
            if (isset($resolvedBootstrap['metadata']) && is_array($resolvedBootstrap['metadata'])) {
                $existingMetadata = $resolvedBootstrap['metadata'];
            }
            $existingMetadata['storage_id'] = $storageIdNormalized;
            $resolvedBootstrap['metadata'] = $existingMetadata;
        }
        storeNpcProfile($newNormalized, $resolvedBootstrap);
        if (strpos($newNormalized, '[') !== false) {
            stobeLogImport('Manual rename bootstrap merged profile', [
                'new_name' => $newNormalized,
                'storage_id' => $storageIdNormalized,
                'bootstrap_keys' => array_keys($resolvedBootstrap),
            ], count($resolvedBootstrap) > 1 ? 'DEBUG' : 'WARN');
        }
    }

    return ['status' => 'ok', 'new_name' => $newNormalized];
}

/**
 * Build context from eventlog for LLM prompts.
 * Returns the most recent N events as formatted history.
 */
function DataEventLog(int $limit = 0, string $actorFilter = '', string $campaignFilter = ''): array {
    $db = $GLOBALS["db"];

    if ($limit <= 0) {
        $limit = 50;
    }
    $excludeTypes = "'prechat','setconf','status_msg','user_input'";

    $query = "SELECT * FROM eventlog
              WHERE type NOT IN ({$excludeTypes})";
    $params = [];

    if ($actorFilter) {
        $query .= " AND (people LIKE $1 OR data LIKE $1)";
        $params[] = "%{$actorFilter}%";
    }

    $query .= " ORDER BY gamets DESC, ts DESC, rowid DESC LIMIT " . intval($limit);

    return $db->fetchAll($query, $params);
}

function storeGameData(string $name, string $type, array $data): bool {
    $db = $GLOBALS["db"];
    $safeName = normalizeParticipantNameToken($name);
    if ($safeName === '') {
        return false;
    }

    $jsonColumns = ['stats', 'equipment', 'inventory', 'skills', 'limbs'];
    $scalarColumns = ['race', 'faction', 'squad_name', 'bio'];
    if (!in_array($type, $jsonColumns, true) && !in_array($type, $scalarColumns, true)) {
        return false;
    }

    $existing = $db->fetchOne(
        "SELECT metadata, race, faction, tags
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );

    $metadata = [];
    if ($existing && isset($existing['metadata'])) {
        $decodedMetadata = json_decode(strval($existing['metadata']), true);
        if (is_array($decodedMetadata)) {
            $metadata = $decodedMetadata;
        }
    }

    $playerData = [];
    if (isset($metadata['player_data']) && is_array($metadata['player_data'])) {
        $playerData = $metadata['player_data'];
    }

    $incomingRace = '';
    $incomingFaction = '';
    if (in_array($type, $jsonColumns, true)) {
        $playerData[$type] = $data;
    } else {
        $value = strval($data['value'] ?? '');
        $playerData[$type] = $value;
        if ($type === 'race') {
            $incomingRace = trim($value);
        } elseif ($type === 'faction') {
            $incomingFaction = trim($value);
        }
    }
    $playerData['updated_at'] = time();
    $metadata['player_data'] = $playerData;
    $metadata['last_gamedata_type'] = $type;
    $metadata['last_gamedata_source'] = 'gamedata.php';

    $metadataJson = normalizeJsonString($metadata);

    $currentRace = trim(strval($existing['race'] ?? ''));
    $currentFaction = trim(strval($existing['faction'] ?? ''));
    $race = $incomingRace !== '' ? $incomingRace : $currentRace;
    $faction = $incomingFaction !== '' ? $incomingFaction : $currentFaction;
    $tags = trim(strval($existing['tags'] ?? ''));
    if ($tags === '') {
        $tags = '';
    }
    $defaultProfileId = getDefaultNpcProfileId();
    $defaultProfileIdOrNull = $defaultProfileId > 0 ? $defaultProfileId : null;

    $result = $db->exec(
        "INSERT INTO core_npc_master (
            name,
            race,
            faction,
            is_animal,
            metadata,
            tags,
            bounty,
            profile_id,
            updated_at
         ) VALUES (
            $1, $2, $3, FALSE, $4::jsonb, $5, '{}'::jsonb, $6, NOW()
         )
         ON CONFLICT (name) DO UPDATE SET
            race = CASE
                WHEN NULLIF($2, '') IS NOT NULL THEN $2
                ELSE core_npc_master.race
            END,
            faction = CASE
                WHEN NULLIF($3, '') IS NOT NULL THEN $3
                ELSE core_npc_master.faction
            END,
            is_animal = FALSE,
            metadata = $4::jsonb,
            tags = COALESCE(NULLIF(core_npc_master.tags, ''), $5),
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            updated_at = NOW()",
        [$safeName, $race, $faction, $metadataJson, $tags, $defaultProfileIdOrNull]
    );

    return $result !== false;
}

function storeWorldStateEntries(array $payload): int {
    $db = $GLOBALS["db"];

    $gameTs = intval($payload['game_ts'] ?? 0);
    if ($gameTs < 0) {
        $gameTs = 0;
    }

    $source = trim(strval($payload['source'] ?? 'world_event_state_query'));
    if ($source === '') {
        $source = 'world_event_state_query';
    }

    $queries = $payload['queries'] ?? [];
    if (!is_array($queries) && is_string($queries) && trim($queries) !== '') {
        $decodedQueries = json_decode($queries, true);
        if (is_array($decodedQueries)) {
            $queries = $decodedQueries;
        }
    }
    if (!is_array($queries)) {
        $queries = [];
    }

    $parseBool = static function (mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        $text = strtolower(trim(strval($value)));
        if ($text === '') {
            return null;
        }
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return null;
    };

    $normalizeList = static function (mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    };

    $entriesByKey = [];
    $buildEntryKey = static function (
        string $source,
        string $queryName,
        string $queryStringId,
        int $queryNumericId,
        bool $playerInvolvement,
        string $ruleCategory,
        string $entityName,
        string $entityStringId,
        int $entityNumericId
    ): string {
        return implode('|', [
            strtolower(trim($source)),
            strtolower(trim($queryName)),
            strtolower(trim($queryStringId)),
            strval($queryNumericId),
            $playerInvolvement ? '1' : '0',
            strtolower(trim($ruleCategory)),
            strtolower(trim($entityName)),
            strtolower(trim($entityStringId)),
            strval($entityNumericId),
        ]);
    };
    $setEntry = static function (
        int $gameTs,
        string $source,
        string $queryName,
        string $queryStringId,
        int $queryNumericId,
        bool $playerInvolvement,
        string $ruleCategory,
        string $entityName,
        string $entityStringId,
        int $entityNumericId,
        string $stateValue,
        ?bool $boolValue
    ) use (&$entriesByKey, $buildEntryKey): void {
        $key = $buildEntryKey(
            $source,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement,
            $ruleCategory,
            $entityName,
            $entityStringId,
            $entityNumericId
        );
        $entriesByKey[$key] = [
            'merge_key' => $key,
            'game_ts' => $gameTs,
            'source' => $source,
            'query_name' => $queryName,
            'query_string_id' => $queryStringId,
            'query_numeric_id' => $queryNumericId,
            'player_involvement' => $playerInvolvement,
            'rule_category' => $ruleCategory,
            'entity_name' => $entityName,
            'entity_string_id' => $entityStringId,
            'entity_numeric_id' => $entityNumericId,
            'state_value' => $stateValue,
            'bool_value' => $boolValue,
        ];
    };

    foreach ($queries as $query) {
        if (!is_array($query)) {
            continue;
        }

        $queryName = trim(strval($query['query_name'] ?? ''));
        $queryStringId = trim(strval($query['query_string_id'] ?? ''));
        $queryNumericId = intval($query['query_numeric_id'] ?? 0);
        $playerInvolvement = boolval($query['player_involvement'] ?? false);

        $insertStateList = static function (
            mixed $listValue,
            string $ruleCategory,
            string $source,
            int $gameTs,
            string $queryName,
            string $queryStringId,
            int $queryNumericId,
            bool $playerInvolvement
        ) use ($normalizeList, $setEntry): bool {
            $list = $normalizeList($listValue);
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entityName = trim(strval($entry['name'] ?? ''));
                $entityStringId = trim(strval($entry['string_id'] ?? ''));
                $entityNumericId = intval($entry['numeric_id'] ?? 0);
                $stateValue = strtolower(trim(strval($entry['state'] ?? '')));
                if (!in_array($stateValue, ['dead', 'alive', 'imprisoned'], true)) {
                    $stateValue = '';
                }
                if (
                    $entityName === '' &&
                    $entityStringId === '' &&
                    $entityNumericId <= 0 &&
                    $stateValue === ''
                ) {
                    continue;
                }
                $setEntry(
                    $gameTs,
                    $source,
                    $queryName,
                    $queryStringId,
                    $queryNumericId,
                    $playerInvolvement,
                    $ruleCategory,
                    $entityName,
                    $entityStringId,
                    $entityNumericId,
                    $stateValue,
                    null
                );
            }
            return true;
        };

        $ok = $insertStateList(
            $query['unique_npcs_are'] ?? [],
            'unique_npcs_are',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertStateList(
            $query['unique_npcs_are_not'] ?? [],
            'unique_npcs_are_not',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertStateList(
            $query['towns'] ?? [],
            'towns',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }

        $insertBoolList = static function (
            mixed $listValue,
            string $ruleCategory,
            string $boolKey,
            string $source,
            int $gameTs,
            string $queryName,
            string $queryStringId,
            int $queryNumericId,
            bool $playerInvolvement
        ) use ($normalizeList, $parseBool, $setEntry): bool {
            $list = $normalizeList($listValue);
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entityName = trim(strval($entry['name'] ?? ''));
                $entityStringId = trim(strval($entry['string_id'] ?? ''));
                $entityNumericId = intval($entry['numeric_id'] ?? 0);
                $boolValue = $parseBool($entry[$boolKey] ?? null);
                if (
                    $entityName === '' &&
                    $entityStringId === '' &&
                    $entityNumericId <= 0 &&
                    $boolValue === null
                ) {
                    continue;
                }
                $setEntry(
                    $gameTs,
                    $source,
                    $queryName,
                    $queryStringId,
                    $queryNumericId,
                    $playerInvolvement,
                    $ruleCategory,
                    $entityName,
                    $entityStringId,
                    $entityNumericId,
                    '',
                    $boolValue
                );
            }
            return true;
        };

        $ok = $insertBoolList(
            $query['is_ally_of'] ?? [],
            'is_ally_of',
            'is_ally',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertBoolList(
            $query['is_enemy_of'] ?? [],
            'is_enemy_of',
            'is_enemy',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
    }

    $txStarted = false;
    try {
        $beginOk = $db->exec("BEGIN");
        if ($beginOk === false) {
            return -1;
        }
        $txStarted = true;

        foreach ($entriesByKey as $entry) {
            $ok = $db->exec(
                "INSERT INTO world_state (
                    merge_key,
                    game_ts,
                    source,
                    query_name,
                    query_string_id,
                    query_numeric_id,
                    player_involvement,
                    rule_category,
                    entity_name,
                    entity_string_id,
                    entity_numeric_id,
                    state_value,
                    bool_value,
                    created_at
                ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, NOW()
                )
                ON CONFLICT (merge_key) DO UPDATE SET
                    game_ts = EXCLUDED.game_ts,
                    source = EXCLUDED.source,
                    query_name = EXCLUDED.query_name,
                    query_string_id = EXCLUDED.query_string_id,
                    query_numeric_id = EXCLUDED.query_numeric_id,
                    player_involvement = EXCLUDED.player_involvement,
                    rule_category = EXCLUDED.rule_category,
                    entity_name = EXCLUDED.entity_name,
                    entity_string_id = EXCLUDED.entity_string_id,
                    entity_numeric_id = EXCLUDED.entity_numeric_id,
                    state_value = EXCLUDED.state_value,
                    bool_value = EXCLUDED.bool_value,
                    created_at = NOW()",
                [
                    strval($entry['merge_key'] ?? ''),
                    intval($entry['game_ts'] ?? 0),
                    strval($entry['source'] ?? ''),
                    strval($entry['query_name'] ?? ''),
                    strval($entry['query_string_id'] ?? ''),
                    intval($entry['query_numeric_id'] ?? 0),
                    boolval($entry['player_involvement'] ?? false),
                    strval($entry['rule_category'] ?? ''),
                    strval($entry['entity_name'] ?? ''),
                    strval($entry['entity_string_id'] ?? ''),
                    intval($entry['entity_numeric_id'] ?? 0),
                    strval($entry['state_value'] ?? ''),
                    $entry['bool_value'] ?? null,
                ]
            );
            if ($ok === false) {
                $db->exec("ROLLBACK");
                return -1;
            }
        }

        $commitOk = $db->exec("COMMIT");
        if ($commitOk === false) {
            $db->exec("ROLLBACK");
            return -1;
        }
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec("ROLLBACK");
        }
        stobeLogException($exception, 'storeWorldStateEntries failed', [
            'source' => $source,
            'game_ts' => $gameTs,
        ]);
        return -1;
    }

    return count($entriesByKey);
}

function getNpcData(string $name): array|false {
    $db = $GLOBALS["db"];
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return false;
    }

    $exact = $db->fetchOne(
        "SELECT *
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );
    if ($exact) {
        $metadata = normalizeCoreNpcMetadata($exact['metadata'] ?? '{}');
        $exactStorageId = normalizeStorageIdToken(strval($metadata['storage_id'] ?? ''));
        $exactRace = strtolower(trim(strval($exact['race'] ?? '')));
        $exactFaction = trim(strval($exact['faction'] ?? ''));
        $exactGender = trim(strval($exact['gender'] ?? ''));
        $exactEquipment = trim(strval($exact['equipment'] ?? ''));
        $exactSkills = trim(strval($exact['skills'] ?? ''));
        $exactLooksPlaceholder =
            $exactStorageId === '' &&
            ($exactRace === '' || $exactRace === 'unknown') &&
            $exactFaction === '' &&
            $exactGender === '' &&
            $exactEquipment === '' &&
            $exactSkills === '';

        if ($exactLooksPlaceholder) {
            $preferred = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
                   AND COALESCE(metadata->>'storage_id', '') <> ''
                 ORDER BY updated_at DESC, gamets_last_updated DESC
                 LIMIT 1",
                [$normalizedName]
            );
            if ($preferred) {
                return $preferred;
            }
        }
        return $exact;
    }

    return $db->fetchOne(
        "SELECT *
         FROM core_npc
         WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
         ORDER BY
           CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
           gamets_last_updated DESC,
           updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );
}

function getApiBadgeById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_api_badge WHERE id = $1",
        [$id]
    );
}

function getApiBadgeByLabel(string $label): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_api_badge WHERE LOWER(label) = LOWER($1) LIMIT 1",
        [$label]
    );
}

function getAllApiBadges(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT * FROM core_api_badge ORDER BY LOWER(label) ASC"
    );
}

function saveApiBadge(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $label = trim(strval($fields['label'] ?? ''));
    $apiKey = strval($fields['api_key'] ?? '');

    if ($label === '') {
        return 0;
    }

    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_api_badge
             SET label = $1,
                 api_key = $2
             WHERE id = $3
             RETURNING id",
            [$label, $apiKey, $id]
        );
        return $result ? intval($result['id']) : 0;
    }

    $existing = getApiBadgeByLabel($label);
    if ($existing) {
        $existingId = intval($existing['id'] ?? 0);
        if ($existingId > 0) {
            $result = $db->fetchOne(
                "UPDATE core_api_badge
                 SET label = $1,
                     api_key = $2
                 WHERE id = $3
                 RETURNING id",
                [$label, $apiKey, $existingId]
            );
            return $result ? intval($result['id']) : 0;
        }
    }

    $result = $db->fetchOne(
        "INSERT INTO core_api_badge (label, api_key)
         VALUES ($1, $2)
         RETURNING id",
        [$label, $apiKey]
    );
    return $result ? intval($result['id']) : 0;
}

function deleteApiBadge(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_api_badge WHERE id = $1", [$id]);
}

function stobeSortArrayRecursive(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }

    $isSequential = array_keys($value) === range(0, count($value) - 1);
    if ($isSequential) {
        foreach ($value as $index => $entry) {
            $value[$index] = stobeSortArrayRecursive($entry);
        }
        return $value;
    }

    ksort($value);
    foreach ($value as $key => $entry) {
        $value[$key] = stobeSortArrayRecursive($entry);
    }
    return $value;
}

function stobeNormalizeJsonArrayValue(mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeBuildNpcHistoryHashFromRow(array $row): string {
    $metadata = normalizeCoreNpcMetadata($row['metadata'] ?? '{}');
    $extendedData = normalizeCoreNpcExtendedData($row['extended_data'] ?? '{}');
    $limbs = stobeNormalizeJsonArrayValue($row['limbs'] ?? '{}');
    $bounty = stobeNormalizeBountyPayload($row['bounty'] ?? '{}');
    $dynamicProfileEnabled = null;
    if (array_key_exists('DYNAMIC_PROFILE_ENABLED', $metadata)) {
        $dynamicProfileEnabled = coerceBoolean($metadata['DYNAMIC_PROFILE_ENABLED']);
    } elseif (array_key_exists('dynamic_profile', $row) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynamicProfileEnabled = coerceBoolean($row['dynamic_profile']);
    }

    $canonical = [
        'name' => trim(strval($row['name'] ?? '')),
        'original_name' => trim(strval($row['original_name'] ?? '')),
        'npc_favorite' => coerceBoolean($row['npc_favorite'] ?? false),
        'lock_profile' => coerceBoolean($row['lock_profile'] ?? false),
        'prompt_head' => strval($row['prompt_head'] ?? ''),
        'personality' => strval($row['personality'] ?? ''),
        'backstory' => strval($row['backstory'] ?? ''),
        'emote_moods' => strval($row['emote_moods'] ?? ''),
        'occupation' => strval($row['occupation'] ?? ''),
        'appearance' => strval($row['appearance'] ?? ''),
        'equipment' => strval($row['equipment'] ?? ''),
        'inventory' => strval($row['inventory'] ?? ''),
        'skills' => strval($row['skills'] ?? ''),
        'speechstyle' => strval($row['speechstyle'] ?? ''),
        'goals' => strval($row['goals'] ?? ''),
        'relationships' => strval($row['relationships'] ?? ''),
        'voiceid' => strval($row['voiceid'] ?? ''),
        'metadata' => stobeSortArrayRecursive($metadata),
        'race' => strval($row['race'] ?? ''),
        'faction' => strval($row['faction'] ?? ''),
        'gender' => strval($row['gender'] ?? ''),
        'profile_id' => intval($row['profile_id'] ?? 0),
        'dynamic_profile' => $dynamicProfileEnabled,
        'extended_data' => stobeSortArrayRecursive($extendedData),
        'md5' => strval($row['md5'] ?? ''),
        'bounty' => stobeSortArrayRecursive($bounty),
        'limbs' => stobeSortArrayRecursive($limbs),
        'blood' => strval($row['blood'] ?? ''),
        'hunger' => strval($row['hunger'] ?? ''),
        'tags' => strval($row['tags'] ?? ''),
        'is_animal' => coerceBoolean($row['is_animal'] ?? false),
        'is_slave' => coerceBoolean($row['is_slave'] ?? false),
        'world_knowledge_tags' => strval($row['world_knowledge_tags'] ?? ''),
    ];

    $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return '';
    }
    return md5($encoded);
}

function stobeFetchNpcRowForHistoryById(int $id): array|false {
    if ($id <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_npc WHERE id = $1 LIMIT 1",
        [$id]
    );
}

function stobeFetchNpcRowForHistoryByName(string $name): array|false {
    $safeName = trim($name);
    if ($safeName === '') {
        return false;
    }
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$safeName]
    );
}

function stobeInsertNpcHistorySnapshotFromRow(array $row, string $reason = 'snapshot'): bool {
    $npcId = intval($row['id'] ?? 0);
    $name = trim(strval($row['name'] ?? ''));
    if ($npcId <= 0 || $name === '') {
        return false;
    }

    $db = $GLOBALS["db"];
    $snapshotHash = stobeBuildNpcHistoryHashFromRow($row);
    if ($snapshotHash === '') {
        return false;
    }

    $latest = $db->fetchOne(
        "SELECT snapshot_hash
         FROM core_npc_master_history
         WHERE npc_id = $1
         ORDER BY history_id DESC
         LIMIT 1",
        [$npcId]
    );
    $latestHash = trim(strval($latest['snapshot_hash'] ?? ''));
    if ($latestHash !== '' && hash_equals($latestHash, $snapshotHash)) {
        return false;
    }

    $metadata = normalizeJsonString(normalizeCoreNpcMetadata($row['metadata'] ?? '{}'));
    $extendedData = normalizeJsonString(normalizeCoreNpcExtendedData($row['extended_data'] ?? '{}'));
    $limbs = normalizeJsonString(stobeNormalizeJsonArrayValue($row['limbs'] ?? '{}'));
    $bounty = stobeNormalizeBountyJsonString($row['bounty'] ?? '{}');
    $safeReason = trim($reason);
    if ($safeReason === '') {
        $safeReason = 'snapshot';
    }

    $dynamicProfileEnabled = false;
    $metadataArray = normalizeCoreNpcMetadata($metadata);
    if (array_key_exists('DYNAMIC_PROFILE_ENABLED', $metadataArray)) {
        $dynamicProfileEnabled = coerceBoolean($metadataArray['DYNAMIC_PROFILE_ENABLED']);
    } elseif (array_key_exists('dynamic_profile', $row) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynamicProfileEnabled = coerceBoolean($row['dynamic_profile']);
    }

    $result = $db->fetchOne(
        "INSERT INTO core_npc_master_history (
            npc_id, name, original_name, npc_favorite, lock_profile,
            prompt_head, personality, backstory, emote_moods, occupation,
            appearance, equipment, inventory, skills, speechstyle, goals, relationships,
            voiceid, metadata, race, faction, gender, profile_id, dynamic_profile,
            extended_data, md5, gamets_last_updated, bounty, limbs, blood,
            hunger, tags, is_animal, is_slave, world_knowledge_tags, snapshot_reason,
            snapshot_hash, source_created_at, source_updated_at, created
        ) VALUES (
            $1, $2, $3, $4, $5,
            $6, $7, $8, $9, $10,
            $11, $12, $13, $14, $15, $16, $17,
            $18, $19::jsonb, $20, $21, $22, $23, $24,
            $25::jsonb, $26, $27, $28::jsonb, $29::jsonb, $30,
            $31, $32, $33, $34, $35, $36,
            $37, $38, $39, NOW()
        )
        RETURNING history_id",
        [
            $npcId,
            $name,
            strval($row['original_name'] ?? ''),
            coerceBoolean($row['npc_favorite'] ?? false),
            coerceBoolean($row['lock_profile'] ?? false),
            strval($row['prompt_head'] ?? ''),
            strval($row['personality'] ?? ''),
            strval($row['backstory'] ?? ''),
            strval($row['emote_moods'] ?? ''),
            strval($row['occupation'] ?? ''),
            strval($row['appearance'] ?? ''),
            strval($row['equipment'] ?? ''),
            strval($row['inventory'] ?? ''),
            strval($row['skills'] ?? ''),
            strval($row['speechstyle'] ?? ''),
            strval($row['goals'] ?? ''),
            strval($row['relationships'] ?? ''),
            strval($row['voiceid'] ?? ''),
            $metadata,
            strval($row['race'] ?? ''),
            strval($row['faction'] ?? ''),
            strval($row['gender'] ?? ''),
            ($row['profile_id'] ?? '') === '' ? null : intval($row['profile_id']),
            $dynamicProfileEnabled,
            $extendedData,
            strval($row['md5'] ?? ''),
            intval($row['gamets_last_updated'] ?? 0),
            $bounty,
            $limbs,
            strval($row['blood'] ?? '0/0'),
            strval($row['hunger'] ?? '300/300'),
            strval($row['tags'] ?? ''),
            coerceBoolean($row['is_animal'] ?? false),
            coerceBoolean($row['is_slave'] ?? false),
            strval($row['world_knowledge_tags'] ?? ''),
            $safeReason,
            $snapshotHash,
            ($row['created_at'] ?? '') === '' ? null : strval($row['created_at']),
            ($row['updated_at'] ?? '') === '' ? null : strval($row['updated_at']),
        ]
    );

    if ($result) {
        stobeLogInfo('NPC history snapshot stored', [
            'npc_id' => $npcId,
            'name' => $name,
            'reason' => $safeReason,
            'history_id' => intval($result['history_id'] ?? 0),
        ]);
    }

    return $result !== false;
}

function stobeMaybeSnapshotNpcHistoryBeforeAfter(array|false $beforeRow, array|false $afterRow, string $reason): bool {
    if (!$beforeRow || !$afterRow) {
        return false;
    }
    $beforeHash = stobeBuildNpcHistoryHashFromRow($beforeRow);
    $afterHash = stobeBuildNpcHistoryHashFromRow($afterRow);
    if ($beforeHash === '' || $afterHash === '' || hash_equals($beforeHash, $afterHash)) {
        return false;
    }
    return stobeInsertNpcHistorySnapshotFromRow($beforeRow, $reason);
}

function stobeDefaultEmoteMoods(): string {
    // Keep parity with HerikaServer conf.sample.php default EMOTEMOODS.
    return 'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,sardonic,smirking,amused,default,assisting,irritated,playful,neutral,teasing,mocking,desperate,distressed,pleading,sad';
}

function stobeNormalizeEmoteMoodsCsv(string $csv): string {
    $raw = trim($csv);
    if ($raw === '') {
        return '';
    }
    // Repair legacy malformed suffix without commas.
    $raw = str_replace(
        'mockingdesperatedistressedpleadingsad',
        'mocking,desperate,distressed,pleading,sad',
        $raw
    );
    $parts = explode(',', $raw);
    $clean = [];
    $seen = [];
    foreach ($parts as $part) {
        $token = strtolower(trim($part));
        if ($token === '' || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $clean[] = $token;
    }
    return implode(',', $clean);
}

function stobeResolveGlobalEmoteMoods(): string {
    $configured = trim(strval(getSetting('EMOTEMOODS', '')));
    if ($configured !== '') {
        $normalized = stobeNormalizeEmoteMoodsCsv($configured);
        if ($normalized !== '') {
            return $normalized;
        }
    }
    return stobeDefaultEmoteMoods();
}

function stobeParseNonNegativeInt(mixed $value, int $fallback = 0): int {
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_float($value)) {
        return max(0, intval($value));
    }
    if (is_string($value)) {
        $text = trim($value);
        if ($text !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) === 1) {
            return max(0, intval(floatval($text)));
        }
    }
    return max(0, $fallback);
}

function stobeNormalizeBountyReasonList(mixed $value): array {
    $tokens = [];
    if (is_array($value)) {
        $tokens = $value;
    } elseif (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') {
            return [];
        }
        $tokens = preg_split('/[|,;]+/', $raw);
        if (!is_array($tokens) || count($tokens) === 0) {
            $tokens = [$raw];
        }
    } else {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($tokens as $token) {
        if (is_array($token) || is_object($token)) {
            continue;
        }
        $text = trim(strval($token));
        if ($text === '') {
            continue;
        }
        $dedupe = strtolower($text);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;
        $normalized[] = $text;
        if (count($normalized) >= 24) {
            break;
        }
    }
    return $normalized;
}

function stobeExtractBountyTracking(mixed $rawBounty): array {
    $details = [];
    $amount = 0;

    if (is_string($rawBounty)) {
        $trimmed = trim($rawBounty);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $rawBounty = $decoded;
            }
        }
    }

    if (is_int($rawBounty) || is_float($rawBounty) || is_string($rawBounty)) {
        $amount = stobeParseNonNegativeInt($rawBounty, 0);
        if ($amount > 0) {
            $details['total'] = $amount;
        }
        return [
            'amount' => $amount,
            'details' => $details,
        ];
    }

    $payload = [];
    if (is_array($rawBounty)) {
        $payload = $rawBounty;
    } elseif (is_object($rawBounty)) {
        $payload = json_decode(json_encode($rawBounty), true);
        if (!is_array($payload)) {
            $payload = [];
        }
    }
    if (count($payload) === 0) {
        return [
            'amount' => 0,
            'details' => [],
        ];
    }

    foreach (['amount', 'total', 'value', 'bounty', 'cats'] as $amountKey) {
        if (!array_key_exists($amountKey, $payload)) {
            continue;
        }
        $amount = stobeParseNonNegativeInt($payload[$amountKey], $amount);
        if ($amount > 0) {
            break;
        }
    }

    $rawEntries = [];
    if (array_is_list($payload)) {
        $rawEntries = $payload;
    } else {
        foreach (['factions', 'entries', 'by_faction', 'list'] as $entryKey) {
            if (isset($payload[$entryKey]) && is_array($payload[$entryKey])) {
                $rawEntries = $payload[$entryKey];
                break;
            }
        }
    }

    if (count($rawEntries) > 0 && !array_is_list($rawEntries)) {
        $looksLikeSingleEntry = false;
        foreach (['faction', 'faction_name', 'faction_id', 'factionID', 'name', 'amount', 'total', 'value', 'bounty', 'cats', 'what_for', 'crimes', 'crime_mask', 'crimes_mask'] as $entryField) {
            if (array_key_exists($entryField, $rawEntries)) {
                $looksLikeSingleEntry = true;
                break;
            }
        }
        if ($looksLikeSingleEntry) {
            $rawEntries = [$rawEntries];
        }
    }

    if (count($rawEntries) === 0) {
        $singleFaction = trim(strval($payload['faction'] ?? ($payload['faction_name'] ?? ($payload['name'] ?? ''))));
        $singleFactionId = trim(strval($payload['faction_id'] ?? ($payload['factionID'] ?? '')));
        if (
            $singleFaction !== '' ||
            $singleFactionId !== '' ||
            $amount > 0 ||
            array_key_exists('what_for', $payload) ||
            array_key_exists('crimes', $payload)
        ) {
            $rawEntries = [[
                'faction' => $singleFaction,
                'faction_id' => $singleFactionId,
                'amount' => $amount,
                'what_for' => $payload['what_for'] ?? ($payload['crimes'] ?? []),
                'crime_mask' => $payload['crime_mask'] ?? ($payload['crimes_mask'] ?? 0),
                'claimed_once' => $payload['claimed_once'] ?? ($payload['claimed'] ?? false),
                'assigned_game_ts' => $payload['assigned_game_ts'] ?? ($payload['assignment_ts'] ?? 0),
            ]];
        }
    }

    $normalizedEntries = [];
    $sumAmount = 0;
    $maxAmount = 0;
    $primaryFaction = '';
    $primaryFactionId = '';
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryAmount = 0;
        foreach (['amount', 'total', 'value', 'bounty', 'cats'] as $entryAmountKey) {
            if (!array_key_exists($entryAmountKey, $entry)) {
                continue;
            }
            $entryAmount = stobeParseNonNegativeInt($entry[$entryAmountKey], $entryAmount);
            if ($entryAmount > 0) {
                break;
            }
        }
        $entryFaction = trim(strval($entry['faction'] ?? ($entry['faction_name'] ?? ($entry['name'] ?? ''))));
        $entryFactionId = trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? '')));
        $entryCrimeMask = stobeParseNonNegativeInt(
            $entry['crime_mask'] ?? ($entry['crimes_mask'] ?? ($entry['crime_bits'] ?? 0)),
            0
        );
        $entryReasons = stobeNormalizeBountyReasonList(
            $entry['what_for'] ?? ($entry['crimes'] ?? ($entry['reasons'] ?? ($entry['reason'] ?? [])))
        );

        if (
            $entryAmount <= 0 &&
            $entryFaction === '' &&
            $entryFactionId === '' &&
            $entryCrimeMask <= 0 &&
            count($entryReasons) === 0
        ) {
            continue;
        }

        $normalizedEntry = [];
        if ($entryFaction !== '') {
            $normalizedEntry['faction'] = $entryFaction;
        }
        if ($entryFactionId !== '') {
            $normalizedEntry['faction_id'] = $entryFactionId;
        }
        if ($entryAmount > 0) {
            $normalizedEntry['amount'] = $entryAmount;
        }
        if ($entryCrimeMask > 0) {
            $normalizedEntry['crime_mask'] = $entryCrimeMask;
        }
        if (count($entryReasons) > 0) {
            $normalizedEntry['what_for'] = $entryReasons;
        }
        if (array_key_exists('claimed_once', $entry) || array_key_exists('claimed', $entry)) {
            $normalizedEntry['claimed_once'] = coerceBoolean($entry['claimed_once'] ?? $entry['claimed']);
        }
        $assignedTs = stobeParseNonNegativeInt(
            $entry['assigned_game_ts'] ?? ($entry['assignment_ts'] ?? ($entry['start_ts'] ?? 0)),
            0
        );
        if ($assignedTs > 0) {
            $normalizedEntry['assigned_game_ts'] = $assignedTs;
        }

        $normalizedEntries[] = $normalizedEntry;
        $sumAmount += $entryAmount;
        if ($entryAmount > $maxAmount) {
            $maxAmount = $entryAmount;
            $primaryFaction = $entryFaction;
            $primaryFactionId = $entryFactionId;
        }
        if (count($normalizedEntries) >= 24) {
            break;
        }
    }

    if ($amount <= 0 && $sumAmount > 0) {
        $amount = $sumAmount;
    }

    if ($amount > 0) {
        $details['total'] = $amount;
    }
    if ($primaryFaction !== '') {
        $details['primary_faction'] = $primaryFaction;
    }
    if ($primaryFactionId !== '') {
        $details['primary_faction_id'] = $primaryFactionId;
    }
    if (count($normalizedEntries) > 0) {
        $details['factions'] = $normalizedEntries;
    }

    return [
        'amount' => max(0, $amount),
        'details' => $details,
    ];
}

function stobeNormalizeBountyPayload(mixed $rawBounty, mixed $rawBountyInfo = null): array {
    $bountyParsed = stobeExtractBountyTracking($rawBounty);
    $bounty = intval($bountyParsed['amount'] ?? 0);
    $bountyInfo = is_array($bountyParsed['details'] ?? null) ? $bountyParsed['details'] : [];

    if ($rawBountyInfo !== null) {
        $bountyInfoParsed = stobeExtractBountyTracking($rawBountyInfo);
        $bountyInfoDetails = is_array($bountyInfoParsed['details'] ?? null) ? $bountyInfoParsed['details'] : [];
        if ($bounty <= 0) {
            $bounty = intval($bountyInfoParsed['amount'] ?? 0);
        }
        if (count($bountyInfo) === 0) {
            $bountyInfo = $bountyInfoDetails;
        } elseif (count($bountyInfoDetails) > 0) {
            if (!isset($bountyInfo['factions']) && isset($bountyInfoDetails['factions'])) {
                $bountyInfo['factions'] = $bountyInfoDetails['factions'];
            }
            if (!isset($bountyInfo['primary_faction']) && isset($bountyInfoDetails['primary_faction'])) {
                $bountyInfo['primary_faction'] = $bountyInfoDetails['primary_faction'];
            }
            if (!isset($bountyInfo['primary_faction_id']) && isset($bountyInfoDetails['primary_faction_id'])) {
                $bountyInfo['primary_faction_id'] = $bountyInfoDetails['primary_faction_id'];
            }
            if (!isset($bountyInfo['total']) && isset($bountyInfoDetails['total'])) {
                $bountyInfo['total'] = $bountyInfoDetails['total'];
            }
        }
    }

    if ($bounty < 0) {
        $bounty = 0;
    }
    if ($bounty <= 0 && isset($bountyInfo['total'])) {
        $bounty = stobeParseNonNegativeInt($bountyInfo['total'], 0);
    }
    if ($bounty > 0) {
        $bountyInfo['total'] = $bounty;
    } elseif (isset($bountyInfo['total'])) {
        $total = stobeParseNonNegativeInt($bountyInfo['total'], 0);
        if ($total > 0) {
            $bountyInfo['total'] = $total;
        } else {
            unset($bountyInfo['total']);
        }
    }

    if (!is_array($bountyInfo) || count($bountyInfo) === 0) {
        return [];
    }
    return $bountyInfo;
}

function stobeNormalizeBountyJsonString(mixed $rawBounty, mixed $rawBountyInfo = null): string {
    $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
    if (!is_array($payload) || count($payload) === 0) {
        return '{}';
    }
    if (array_is_list($payload)) {
        $reparsed = stobeExtractBountyTracking($payload);
        $payload = is_array($reparsed['details'] ?? null) ? $reparsed['details'] : [];
        if (!is_array($payload) || count($payload) === 0 || array_is_list($payload)) {
            return '{}';
        }
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || $encoded === '[]') {
        return '{}';
    }
    return $encoded;
}

function stobeBountyAmountFromPayload(mixed $rawBounty, mixed $rawBountyInfo = null): int {
    $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
    if (!is_array($payload) || count($payload) === 0) {
        return 0;
    }
    return stobeParseNonNegativeInt($payload['total'] ?? 0, 0);
}

function stobeBuildBountyText(array $bountyDetails): string {
    if (!is_array($bountyDetails) || count($bountyDetails) === 0) {
        return '';
    }
    $factions = $bountyDetails['factions'] ?? [];
    if (!is_array($factions) || count($factions) === 0) {
        $total = intval($bountyDetails['total'] ?? 0);
        return $total > 0 ? ('Bounty: ' . number_format($total) . ' cats') : '';
    }

    $parts = [];
    $maxFactionsToShow = 3;
    foreach ($factions as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (count($parts) >= $maxFactionsToShow) {
            break;
        }

        $factionName = trim(strval($entry['faction'] ?? ($entry['faction_id'] ?? 'Unknown faction')));
        $amount = intval($entry['amount'] ?? 0);
        $whatForRaw = $entry['what_for'] ?? [];
        $whatFor = [];
        if (is_array($whatForRaw)) {
            foreach ($whatForRaw as $crime) {
                $crimeText = trim(strval($crime));
                if ($crimeText !== '') {
                    $whatFor[] = $crimeText;
                }
            }
        } elseif (is_string($whatForRaw) && trim($whatForRaw) !== '') {
            $whatFor = array_values(array_filter(array_map('trim', preg_split('/[|,;]+/', $whatForRaw))));
        }
        if (count($whatFor) > 8) {
            $whatFor = array_slice($whatFor, 0, 8);
        }

        $segment = $factionName;
        if ($amount > 0) {
            $segment .= ' (' . number_format($amount) . ' cats)';
        }
        if (count($whatFor) > 0) {
            $segment .= ' - Wanted for: ' . implode(', ', $whatFor);
        }
        $parts[] = $segment;
    }

    if (count($factions) > $maxFactionsToShow) {
        $parts[] = '+' . strval(count($factions) - $maxFactionsToShow) . ' more faction(s)';
    }
    return implode(' | ', $parts);
}

function storeNpcProfile(string $name, array $profile, array $options = []): void {
    $db = $GLOBALS["db"];
    $safeName = trim($name);
    if ($safeName === '') {
        return;
    }
    $historyEnabled = !coerceBoolean($options['skip_history'] ?? false);
    $deferVoiceAssignment = coerceBoolean($options['defer_voice_assignment'] ?? false);
    $historyReason = trim(strval($options['history_reason'] ?? 'profile_update'));
    if ($historyReason === '') {
        $historyReason = 'profile_update';
    }
    $historyBeforeRow = $historyEnabled ? stobeFetchNpcRowForHistoryByName($safeName) : false;

    $speechStyleRaw = trim(strval($profile['speechstyle'] ?? ($profile['speech_quirks'] ?? '')));
    $voiceIdRaw = trim(strval($profile['voiceid'] ?? ($profile['voice_model'] ?? '')));
    $promptHeadRaw = trim(strval($profile['prompt_head'] ?? ''));
    $personalityRaw = trim(strval($profile['personality'] ?? ''));
    $backstoryRaw = trim(strval($profile['backstory'] ?? ''));
    $emoteMoodsRaw = trim(strval($profile['emote_moods'] ?? ''));
    $occupationRaw = trim(strval($profile['occupation'] ?? ''));
    $appearanceRaw = trim(strval($profile['appearance'] ?? ''));
    $equipmentRaw = trim(strval($profile['equipment'] ?? ''));
    $inventoryRaw = trim(strval($profile['inventory'] ?? ''));
    $skillsRaw = trim(strval($profile['skills'] ?? ''));
    $goalsRaw = trim(strval($profile['goals'] ?? ''));
    $raceRaw = trim(strval($profile['race'] ?? ''));
    $factionRaw = trim(strval($profile['faction'] ?? ''));
    $factionIdRaw = trim(strval($profile['faction_id'] ?? ($profile['factionID'] ?? '')));
    $genderRaw = trim(strval($profile['gender'] ?? ''));
    $originalNameRaw = trim(strval($profile['original_name'] ?? ''));
    $tagsRaw = trim(strval($profile['tags'] ?? ''));
    $world_knowledgeRaw = trim(strval($profile['world_knowledge_tags'] ?? ''));
    $bountyRaw = $profile['bounty'] ?? 0;
    $bountyInfoRaw = $profile['bounty_info'] ?? ($profile['bounty_details'] ?? null);
    $factionCombined = composeFactionWithId($factionRaw, $factionIdRaw);
    $traitSelection = selectBioTraitsForNpc($safeName, $raceRaw, $genderRaw, $factionCombined, $originalNameRaw);
    $resolvedTraits = is_array($traitSelection['traits'] ?? null) ? $traitSelection['traits'] : [];

    $promptHead = $promptHeadRaw;
    $personality = $personalityRaw !== '' ? $personalityRaw : trim(strval($resolvedTraits['personality'] ?? ''));
    if ($personality === '') {
        $personality = 'Pragmatic wasteland survivor.';
    }
    $backstory = $backstoryRaw !== '' ? $backstoryRaw : trim(strval($resolvedTraits['backstory'] ?? ''));
    if ($backstory === '') {
        $backstory = 'A drifter surviving the harsh world of Kenshi.';
    }
    $emoteMoods = $emoteMoodsRaw !== '' ? stobeNormalizeEmoteMoodsCsv($emoteMoodsRaw) : stobeResolveGlobalEmoteMoods();
    if ($emoteMoods === '') {
        $emoteMoods = stobeDefaultEmoteMoods();
    }
    $occupation = $occupationRaw !== '' ? $occupationRaw : trim(strval($resolvedTraits['occupation'] ?? ''));
    $appearance = $appearanceRaw !== '' ? $appearanceRaw : trim(strval($resolvedTraits['appearance'] ?? ''));
    $equipment = $equipmentRaw;
    $inventory = $inventoryRaw;
    $skills = $skillsRaw;
    $speechStyle = $speechStyleRaw !== '' ? $speechStyleRaw : trim(strval($resolvedTraits['speechstyle'] ?? ''));
    if ($speechStyle === '') {
        $speechStyle = 'Direct and practical.';
    }
    $goals = $goalsRaw !== '' ? $goalsRaw : trim(strval($resolvedTraits['goals'] ?? ''));
    $voiceId = $voiceIdRaw;
    if (!$deferVoiceAssignment && $voiceId === '') {
        $voiceId = stobeSelectVoiceIdForNpc(
            $safeName,
            $raceRaw,
            $genderRaw,
            $factionCombined,
            [
                'metadata' => $profile['metadata'] ?? [],
                'original_name' => $originalNameRaw,
            ]
        );
    }
    $race = $raceRaw !== '' ? $raceRaw : 'Unknown';
    $faction = $factionCombined;
    $gender = $genderRaw;
    $tags = $tagsRaw !== '' ? $tagsRaw : '';
    $world_knowledge = $world_knowledgeRaw !== '' ? $world_knowledgeRaw : '';
    $bountyPayload = stobeNormalizeBountyPayload($bountyRaw, $bountyInfoRaw);
    $bountyJson = stobeNormalizeBountyJsonString($bountyPayload);

    $isAnimal = coerceBoolean($profile['is_animal'] ?? false);
    $isBracketName = strpos($safeName, '[') !== false;

    $isPlaceholderWrite = (
        ($raceRaw === '' || strtolower($raceRaw) === 'unknown') &&
        trim($factionRaw) === '' &&
        trim($genderRaw) === '' &&
        trim($equipmentRaw) === '' &&
        trim($skillsRaw) === '' &&
        trim($inventoryRaw) === ''
    );

    $metadataArray = normalizeCoreNpcMetadata($profile['metadata'] ?? '{}');
    unset($metadataArray['bounty_info'], $metadataArray['bounty_text']);
    $metadataSource = trim(strval($metadataArray['source'] ?? ''));
    if ($isBracketName && $isPlaceholderWrite) {
        stobeLogImport('Bracket profile write is placeholder-like', [
            'name' => $safeName,
            'race_raw' => $raceRaw,
            'faction_raw' => $factionRaw,
            'gender_raw' => $genderRaw,
            'storage_id' => normalizeStorageIdToken($metadataArray['storage_id'] ?? ''),
            'metadata_source' => $metadataSource,
            'profile_keys' => array_keys($profile),
        ], 'WARN');
    }

    $metadataJson = normalizeJsonString($metadataArray);
    $extendedDataJson = normalizeJsonString(normalizeCoreNpcExtendedData($profile['extended_data'] ?? '{}'));
    $defaultProfileId = getDefaultNpcProfileId();
    $defaultProfileIdOrNull = $defaultProfileId > 0 ? $defaultProfileId : null;

    $db->exec(
        "INSERT INTO core_npc_master (
            name,
            npc_favorite,
            lock_profile,
            prompt_head,
            personality,
            backstory,
            emote_moods,
            occupation,
            appearance,
            equipment,
            inventory,
            skills,
            speechstyle,
            goals,
            voiceid,
            metadata,
            race,
            faction,
            gender,
            profile_id,
            extended_data,
            gamets_last_updated,
            bounty,
            tags,
            is_animal,
            world_knowledge_tags,
            updated_at
         )
         VALUES (
            $1, FALSE, FALSE, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14::jsonb, $15, $16, $17,
            $18, $19::jsonb, 0, $20::jsonb, $21, $22, $23, NOW()
         )
         ON CONFLICT (name) DO UPDATE SET
            prompt_head = COALESCE(NULLIF(core_npc_master.prompt_head, ''), EXCLUDED.prompt_head),
            personality = COALESCE(NULLIF(core_npc_master.personality, ''), EXCLUDED.personality),
            backstory = COALESCE(NULLIF(core_npc_master.backstory, ''), EXCLUDED.backstory),
            emote_moods = COALESCE(NULLIF(core_npc_master.emote_moods, ''), EXCLUDED.emote_moods),
            occupation = COALESCE(NULLIF(core_npc_master.occupation, ''), EXCLUDED.occupation),
            appearance = COALESCE(NULLIF(core_npc_master.appearance, ''), EXCLUDED.appearance),
            equipment = CASE
                WHEN NULLIF(EXCLUDED.equipment, '') IS NOT NULL THEN EXCLUDED.equipment
                ELSE COALESCE(core_npc_master.equipment, '')
            END,
            inventory = CASE
                WHEN NULLIF(EXCLUDED.inventory, '') IS NOT NULL THEN EXCLUDED.inventory
                ELSE COALESCE(core_npc_master.inventory, '')
            END,
            skills = CASE
                WHEN NULLIF(EXCLUDED.skills, '') IS NOT NULL THEN EXCLUDED.skills
                ELSE COALESCE(core_npc_master.skills, '')
            END,
            speechstyle = COALESCE(NULLIF(core_npc_master.speechstyle, ''), EXCLUDED.speechstyle),
            goals = COALESCE(NULLIF(core_npc_master.goals, ''), EXCLUDED.goals),
            race = CASE
                WHEN NULLIF(EXCLUDED.race, '') IS NOT NULL
                  AND LOWER(EXCLUDED.race) <> 'unknown'
                THEN EXCLUDED.race
                ELSE COALESCE(NULLIF(core_npc_master.race, ''), EXCLUDED.race)
            END,
            faction = CASE
                WHEN NULLIF(EXCLUDED.faction, '') IS NOT NULL THEN EXCLUDED.faction
                WHEN core_npc_master.faction IS NULL OR TRIM(core_npc_master.faction) = '' OR LOWER(core_npc_master.faction) = 'unknown' THEN ''
                ELSE core_npc_master.faction
            END,
            gender = CASE
                WHEN NULLIF(EXCLUDED.gender, '') IS NOT NULL THEN EXCLUDED.gender
                WHEN core_npc_master.gender IS NULL OR TRIM(core_npc_master.gender) = '' OR LOWER(core_npc_master.gender) = 'unknown' THEN ''
                ELSE core_npc_master.gender
            END,
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            voiceid = COALESCE(NULLIF(core_npc_master.voiceid, ''), EXCLUDED.voiceid),
            metadata = CASE
                WHEN core_npc_master.metadata IS NULL OR core_npc_master.metadata = '{}'::jsonb OR core_npc_master.metadata = '[]'::jsonb THEN EXCLUDED.metadata
                ELSE core_npc_master.metadata
            END,
            extended_data = CASE
                WHEN core_npc_master.extended_data IS NULL OR core_npc_master.extended_data = '{}'::jsonb OR core_npc_master.extended_data = '[]'::jsonb THEN EXCLUDED.extended_data
                ELSE core_npc_master.extended_data
            END,
            bounty = CASE
                WHEN EXCLUDED.bounty <> '{}'::jsonb THEN EXCLUDED.bounty
                ELSE COALESCE(core_npc_master.bounty, '{}'::jsonb)
            END,
            tags = COALESCE(NULLIF(core_npc_master.tags, ''), EXCLUDED.tags),
            world_knowledge_tags = COALESCE(NULLIF(core_npc_master.world_knowledge_tags, ''), EXCLUDED.world_knowledge_tags),
            is_animal = COALESCE(core_npc_master.is_animal, EXCLUDED.is_animal),
            updated_at = NOW()",
        [
            $safeName,
            $promptHead,
            $personality,
            $backstory,
            $emoteMoods,
            $occupation,
            $appearance,
            $equipment,
            $inventory,
            $skills,
            $speechStyle,
            $goals,
            $voiceId,
            $metadataJson,
            $race,
            $faction,
            $gender,
            $defaultProfileIdOrNull,
            $extendedDataJson,
            $bountyJson,
            $tags,
            $isAnimal,
            $world_knowledge,
        ]
    );
    if ($historyEnabled) {
        $historyAfterRow = stobeFetchNpcRowForHistoryByName($safeName);
        stobeMaybeSnapshotNpcHistoryBeforeAfter($historyBeforeRow, $historyAfterRow, $historyReason);
    }

    if ($isBracketName) {
        $rowAfter = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$safeName]
        );
        if ($rowAfter) {
            $raceAfter = trim(strval($rowAfter['race'] ?? ''));
            $factionAfter = trim(strval($rowAfter['faction'] ?? ''));
            $genderAfter = trim(strval($rowAfter['gender'] ?? ''));
            $equipmentAfter = trim(strval($rowAfter['equipment'] ?? ''));
            $skillsAfter = trim(strval($rowAfter['skills'] ?? ''));
            $isUnresolved = (
                ($raceAfter === '' || strtolower($raceAfter) === 'unknown') &&
                $factionAfter === '' &&
                $genderAfter === '' &&
                $equipmentAfter === '' &&
                $skillsAfter === ''
            );
            stobeLogImport('Bracket profile persisted', [
                'name' => $safeName,
                'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                'race' => $raceAfter,
                'faction' => $factionAfter,
                'gender' => $genderAfter,
                'equipment_len' => strlen($equipmentAfter),
                'skills_len' => strlen($skillsAfter),
                'gamets_last_updated' => intval($rowAfter['gamets_last_updated'] ?? 0),
                'metadata_source' => $metadataSource,
                'placeholder_like_input' => $isPlaceholderWrite,
            ], $isUnresolved ? 'WARN' : 'DEBUG');
        }
    }
}

function npcNeedsBootstrap(array $npcData): bool {
    $requiredTextFields = [
        'personality',
        'backstory',
        'speechstyle',
        'occupation',
        'goals',
        'world_knowledge_tags',
    ];

    foreach ($requiredTextFields as $field) {
        if (!isset($npcData[$field])) {
            return true;
        }
        if (trim(strval($npcData[$field])) === '') {
            return true;
        }
    }

    if (!isset($npcData['metadata']) || trim(strval($npcData['metadata'])) === '') {
        return true;
    }

    if (!isset($npcData['extended_data']) || trim(strval($npcData['extended_data'])) === '') {
        return true;
    }

    $faction = strtolower(trim(strval($npcData['faction'] ?? '')));
    $gender = strtolower(trim(strval($npcData['gender'] ?? '')));
    if ($faction === 'unknown' || $gender === 'unknown') {
        return true;
    }

    $metadataRaw = trim(strval($npcData['metadata'] ?? ''));
    $extendedRaw = trim(strval($npcData['extended_data'] ?? ''));
    if ($metadataRaw === '[]' || $extendedRaw === '[]') {
        return true;
    }

    return false;
}

function storeNpcSnapshot(array $snapshot, int $gamets = 0): bool {
    $db = $GLOBALS["db"];
    $incomingName = trim(strval($snapshot['name'] ?? ''));
    if ($incomingName === '') {
        return false;
    }
    $name = $incomingName;
    $snapshotSource = '';
    if (array_key_exists('source', $snapshot)) {
        $snapshotSource = trim(strval($snapshot['source']));
    } elseif (isset($snapshot['metadata']) && is_array($snapshot['metadata'])) {
        $snapshotSource = trim(strval($snapshot['metadata']['source'] ?? ''));
    }
    $isInventoryLiveSync = (strcasecmp($snapshotSource, 'inventory_live_sync') === 0);

    $parseToggleValue = static function (mixed $value): mixed {
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
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled'], true)) {
                return false;
            }
        }
        return null;
    };

    $ordersPayload = [];
    if (isset($snapshot['orders']) && is_array($snapshot['orders'])) {
        $ordersPayload = $snapshot['orders'];
    } elseif (isset($snapshot['orders']) && is_string($snapshot['orders'])) {
        $decodedOrders = json_decode(strval($snapshot['orders']), true);
        if (is_array($decodedOrders)) {
            $ordersPayload = $decodedOrders;
        }
    }

    $hasToggleInput = static function (array $source, string $key): bool {
        if (!array_key_exists($key, $source)) {
            return false;
        }
        $value = $source[$key];
        if ($value === null) {
            return false;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        return trim(strval($value)) !== '';
    };

    $orderToggleKeys = ['block', 'hold', 'passive', 'jobs', 'ranged', 'taunt', 'sneak', 'resource', 'medic'];
    foreach ($orderToggleKeys as $toggleKey) {
        $hasTopLevelValue = $hasToggleInput($snapshot, $toggleKey);
        if (!$hasTopLevelValue && array_key_exists($toggleKey, $ordersPayload)) {
            $snapshot[$toggleKey] = $ordersPayload[$toggleKey];
        }
        if (array_key_exists($toggleKey, $snapshot)) {
            $normalizedToggle = $parseToggleValue($snapshot[$toggleKey]);
            if ($normalizedToggle !== null) {
                $snapshot[$toggleKey] = $normalizedToggle;
            } else {
                unset($snapshot[$toggleKey]);
            }
        }
    }

    if (!array_key_exists('job_list', $snapshot) && array_key_exists('job_list', $ordersPayload)) {
        $snapshot['job_list'] = $ordersPayload['job_list'];
    }
    if (array_key_exists('job_list', $snapshot)) {
        $jobList = [];
        if (is_array($snapshot['job_list'])) {
            $jobList = $snapshot['job_list'];
        } elseif (is_string($snapshot['job_list']) && trim(strval($snapshot['job_list'])) !== '') {
            $decodedJobList = json_decode(strval($snapshot['job_list']), true);
            if (is_array($decodedJobList)) {
                $jobList = $decodedJobList;
            }
        }
        $normalizedJobList = [];
        foreach ($jobList as $jobName) {
            $jobText = trim(strval($jobName));
            if ($jobText === '') {
                continue;
            }
            $normalizedJobList[] = $jobText;
            if (count($normalizedJobList) >= 64) {
                break;
            }
        }
        $snapshot['job_list'] = $normalizedJobList;
        if (!array_key_exists('jobs', $snapshot)) {
            $snapshot['jobs'] = count($normalizedJobList) > 0;
        }
    }

    $pickText = static function (array $source, array $keys): array {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $raw = $source[$key];
            if (is_array($raw) || is_object($raw)) {
                continue;
            }
            $text = trim(strval($raw));
            if ($text === '') {
                continue;
            }
            return ['value' => $text, 'source' => $key];
        }
        return ['value' => '', 'source' => ''];
    };

    $pickMixed = static function (array $source, array $keys): array {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return ['value' => $source[$key], 'source' => $key];
        }
        return ['value' => null, 'source' => ''];
    };

    $parseNonNegativeInt = static function (mixed $value, int $fallback = 0): int {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value)) {
            return max(0, intval($value));
        }
        if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
            return max(0, intval(floatval($value)));
        }
        return max(0, $fallback);
    };

    $racePick = $pickText($snapshot, ['race', 'race_name']);
    $race = strval($racePick['value']);
    if (strtolower($race) === 'unknown') {
        $race = '';
    }

    $factionPick = $pickText($snapshot, ['faction', 'faction_name', 'origin_faction']);
    $faction = strval($factionPick['value']);
    if (strtolower($faction) === 'unknown') {
        $faction = '';
    }
    $factionIdPick = $pickText($snapshot, ['faction_id', 'factionID']);
    $factionId = strval($factionIdPick['value']);
    $faction = composeFactionWithId($faction, $factionId);

    $genderPick = $pickText($snapshot, ['gender', 'sex']);
    $gender = strval($genderPick['value']);
    if ($gender === '' && array_key_exists('is_female', $snapshot)) {
        $gender = coerceBoolean($snapshot['is_female']) ? 'female' : 'male';
        $genderPick['source'] = 'is_female';
    }
    if (strtolower($gender) === 'unknown') {
        $gender = '';
    }

    $storageIdPick = $pickText($snapshot, ['storage_id', 'id', 'refid', 'handle']);
    $storageId = normalizeStorageIdToken(strval($storageIdPick['value']));
    $resolvedIdentity = resolveSnapshotTargetNpcName($name, $storageId);
    $resolvedName = trim(strval($resolvedIdentity['name'] ?? ''));
    if ($resolvedName !== '') {
        $name = $resolvedName;
    }
    $matchedBy = strval($resolvedIdentity['matched_by'] ?? 'incoming');
    if ($storageId !== '' && $matchedBy !== 'storage_id') {
        stobeLogImport('Snapshot resolved without storage_id match', [
            'incoming_name' => $incomingName,
            'incoming_storage_id' => $storageId,
            'resolved_name' => $name,
            'matched_by' => $matchedBy,
        ], 'WARN');
    }
    if (strcasecmp($name, $incomingName) !== 0) {
        stobeLogImport('Snapshot name remapped', [
            'incoming_name' => $incomingName,
            'resolved_name' => $name,
            'storage_id' => $storageId,
            'matched_by' => $matchedBy,
        ], 'DEBUG');
    }
    $historyRowBefore = stobeFetchNpcRowForHistoryByName($name);
    $isBracketSnapshot = (strpos($incomingName, '[') !== false || strpos($name, '[') !== false);
    $rowBefore = false;
    if ($isBracketSnapshot) {
        $rowBefore = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
    }

    $isAnimal = coerceBoolean($snapshot['is_animal'] ?? false);
    $isPlayerCharacter = coerceBoolean($snapshot['is_player_character'] ?? false);
    $tags = trim(strval($snapshot['tags'] ?? ''));
    if ($tags === '') {
        // Import snapshots should not auto-tag rows as jit.
        $tags = '';
    }

    $bountyRaw = $snapshot['bounty'] ?? 0;
    $bountyInfoRaw = $snapshot['bounty_info'] ?? ($snapshot['bounty_details'] ?? null);
    $bountyPayload = stobeNormalizeBountyPayload($bountyRaw, $bountyInfoRaw);
    $hasBountySignal = static function (mixed $value): bool {
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === '0' || $trimmed === '{}' || $trimmed === '[]') {
                return false;
            }
            return true;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        if (is_object($value)) {
            return count(get_object_vars($value)) > 0;
        }
        return false;
    };
    if (count($bountyPayload) === 0 && ($hasBountySignal($bountyRaw) || $hasBountySignal($bountyInfoRaw))) {
        stobeLogImport('Snapshot bounty payload empty after normalization', [
            'name' => $name,
            'gamets' => max(0, intval($gamets)),
            'bounty_type' => get_debug_type($bountyRaw),
            'bounty_info_type' => get_debug_type($bountyInfoRaw),
            'bounty_preview' => is_array($bountyRaw) || is_object($bountyRaw)
                ? json_encode($bountyRaw, JSON_UNESCAPED_UNICODE)
                : substr(strval($bountyRaw), 0, 300),
            'bounty_info_preview' => is_array($bountyInfoRaw) || is_object($bountyInfoRaw)
                ? json_encode($bountyInfoRaw, JSON_UNESCAPED_UNICODE)
                : substr(strval($bountyInfoRaw), 0, 300),
        ], 'WARN');
    }
    if (count($bountyPayload) > 0) {
        $snapshot['bounty'] = $bountyPayload;
    } else {
        unset($snapshot['bounty']);
    }
    unset($snapshot['bounty_info'], $snapshot['bounty_details'], $snapshot['bounty_text']);

    if ($isPlayerCharacter) {
        $playerCatsValue = null;
        $moneyPick = $pickMixed($snapshot, ['money', 'cats', 'player_money', 'player_cats']);
        if ($moneyPick['source'] !== '') {
            $rawMoney = $moneyPick['value'];
            if (is_int($rawMoney)) {
                $playerCatsValue = max(0, $rawMoney);
            } elseif (is_float($rawMoney)) {
                $playerCatsValue = max(0, intval($rawMoney));
            } elseif (is_string($rawMoney)) {
                $moneyText = trim($rawMoney);
                if ($moneyText !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $moneyText) === 1) {
                    $playerCatsValue = max(0, intval(floatval($moneyText)));
                }
            }
        }
        if ($playerCatsValue !== null) {
            $catsChanged = setConfOpt('PLAYER_CATS', strval($playerCatsValue), true);
            if ($catsChanged) {
                stobeLogInfo('Player cats synced to conf_opts', [
                    'name' => $name,
                    'cats' => $playerCatsValue,
                    'source_key' => strval($moneyPick['source']),
                    'gamets' => max(0, $gamets),
                ]);
            }
        }
    }

    $medical = [];
    if (isset($snapshot['medical']) && is_array($snapshot['medical'])) {
        $medical = $snapshot['medical'];
    } elseif (isset($snapshot['medical']) && is_string($snapshot['medical'])) {
        $decodedMedical = json_decode(strval($snapshot['medical']), true);
        if (is_array($decodedMedical)) {
            $medical = $decodedMedical;
        }
    }
    $hasMedicalPayload = count($medical) > 0;

    $limbsPayload = [];
    $appendLimbValue = static function (array &$target, string $key, mixed $value) use ($parseNonNegativeInt): void {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return;
        }
        $target[$normalizedKey] = $parseNonNegativeInt($value, 0);
    };

    $limbSources = [];
    if (isset($medical['limbs']) && is_array($medical['limbs'])) {
        $limbSources[] = $medical['limbs'];
    } elseif (isset($medical['limbs']) && is_string($medical['limbs'])) {
        $decodedLimbSource = json_decode(strval($medical['limbs']), true);
        if (is_array($decodedLimbSource)) {
            $limbSources[] = $decodedLimbSource;
        }
    }
    if (isset($snapshot['limbs']) && is_array($snapshot['limbs'])) {
        $limbSources[] = $snapshot['limbs'];
    } elseif (isset($snapshot['limbs']) && is_string($snapshot['limbs'])) {
        $decodedLimbSource = json_decode(strval($snapshot['limbs']), true);
        if (is_array($decodedLimbSource)) {
            $limbSources[] = $decodedLimbSource;
        }
    }

    foreach ($limbSources as $limbSource) {
        foreach ($limbSource as $limbKey => $limbValue) {
            $normalizedLimb = trim(strval($limbKey));
            if ($normalizedLimb === '') {
                continue;
            }
            if (is_array($limbValue)) {
                $currentPick = $pickMixed($limbValue, ['current', 'value', 'hp', 'flesh']);
                $maxPick = $pickMixed($limbValue, ['max', 'max_hp', 'maxHealth', 'maximum']);
                if ($currentPick['source'] !== '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb . '_current', $currentPick['value']);
                }
                if ($maxPick['source'] !== '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb . '_max', $maxPick['value']);
                }
                if ($currentPick['source'] === '' && $maxPick['source'] === '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb, 0);
                }
                continue;
            }
            $appendLimbValue($limbsPayload, $normalizedLimb, $limbValue);
        }
    }

    if (count($limbsPayload) === 0) {
        $directLimbKeys = [
            'head', 'head_max',
            'stomach', 'stomach_max',
            'left_arm', 'left_arm_max',
            'right_arm', 'right_arm_max',
            'left_leg', 'left_leg_max',
            'right_leg', 'right_leg_max',
        ];
        foreach ($directLimbKeys as $limbKey) {
            if (array_key_exists($limbKey, $medical)) {
                $appendLimbValue($limbsPayload, $limbKey, $medical[$limbKey]);
            } elseif (array_key_exists($limbKey, $snapshot)) {
                $appendLimbValue($limbsPayload, $limbKey, $snapshot[$limbKey]);
            }
        }
    }

    $parseCurrentMax = static function (
        mixed $currentCandidate,
        mixed $maxCandidate,
        int $defaultMax,
        callable $parseNonNegativeInt
    ): array {
        $hasValue = ($currentCandidate !== null || $maxCandidate !== null);
        $current = 0;
        $max = $defaultMax;

        if (is_string($currentCandidate)) {
            $trimmed = trim($currentCandidate);
            if (preg_match('/^([0-9]+)\s*\/\s*([0-9]+)$/', $trimmed, $matches) === 1) {
                $current = $parseNonNegativeInt($matches[1], 0);
                $max = $parseNonNegativeInt($matches[2], $defaultMax);
                $hasValue = true;
            } elseif ($trimmed !== '') {
                $current = $parseNonNegativeInt($trimmed, 0);
            }
        } elseif (is_array($currentCandidate)) {
            $nestedCurrent = $currentCandidate['current'] ?? ($currentCandidate['value'] ?? null);
            $nestedMax = $currentCandidate['max'] ?? ($currentCandidate['maximum'] ?? null);
            if ($nestedCurrent !== null) {
                $current = $parseNonNegativeInt($nestedCurrent, 0);
                $hasValue = true;
            }
            if ($nestedMax !== null) {
                $max = $parseNonNegativeInt($nestedMax, $defaultMax);
                $hasValue = true;
            }
        } elseif ($currentCandidate !== null) {
            $current = $parseNonNegativeInt($currentCandidate, 0);
        }

        if ($maxCandidate !== null) {
            $max = $parseNonNegativeInt($maxCandidate, $defaultMax);
        }

        if ($max <= 0) {
            $max = $defaultMax > 0 ? $defaultMax : max($current, 0);
        }

        return [
            'current' => $current,
            'max' => $max,
            'has_value' => $hasValue,
        ];
    };

    $deriveBloodFromLimbs = static function (array $limbs) use ($parseNonNegativeInt): array {
        $totalCurrent = 0;
        $totalMax = 0;
        $pairCount = 0;

        foreach ($limbs as $limbKey => $limbCurrentValue) {
            $normalizedKey = trim(strval($limbKey));
            if ($normalizedKey === '' || preg_match('/_max$/', $normalizedKey) === 1) {
                continue;
            }
            $maxKey = $normalizedKey . '_max';
            if (!array_key_exists($maxKey, $limbs)) {
                continue;
            }

            $current = $parseNonNegativeInt($limbCurrentValue, 0);
            $max = $parseNonNegativeInt($limbs[$maxKey], 0);
            if ($max <= 0) {
                continue;
            }
            if ($current > $max) {
                $current = $max;
            }
            $totalCurrent += $current;
            $totalMax += $max;
            $pairCount++;
        }

        if ($pairCount <= 0 || $totalMax <= 0) {
            return [
                'current' => 0,
                'max' => 0,
                'has_value' => false,
            ];
        }

        $ratio = floatval($totalCurrent) / floatval($totalMax);
        if ($ratio < 0.0) {
            $ratio = 0.0;
        } elseif ($ratio > 1.0) {
            $ratio = 1.0;
        }

        $derivedMax = 100;
        $derivedCurrent = intval(round($ratio * floatval($derivedMax)));
        if ($derivedCurrent < 0) {
            $derivedCurrent = 0;
        } elseif ($derivedCurrent > $derivedMax) {
            $derivedCurrent = $derivedMax;
        }

        return [
            'current' => $derivedCurrent,
            'max' => $derivedMax,
            'has_value' => true,
        ];
    };

    $bloodCurrentPick = $pickMixed($medical, ['blood', 'blood_current']);
    if ($bloodCurrentPick['source'] === '') {
        $bloodCurrentPick = $pickMixed($snapshot, ['blood', 'blood_current']);
    }
    $bloodMaxPick = $pickMixed($medical, ['max_blood', 'blood_max']);
    if ($bloodMaxPick['source'] === '') {
        $bloodMaxPick = $pickMixed($snapshot, ['max_blood', 'blood_max']);
    }
    $bloodData = $parseCurrentMax($bloodCurrentPick['value'], $bloodMaxPick['value'], 100, $parseNonNegativeInt);

    $hungerCurrentPick = $pickMixed($medical, ['hunger', 'hunger_current']);
    if ($hungerCurrentPick['source'] === '') {
        $hungerCurrentPick = $pickMixed($snapshot, ['hunger', 'hunger_current']);
    }
    $hungerMaxPick = $pickMixed($medical, ['hunger_max', 'max_hunger']);
    if ($hungerMaxPick['source'] === '') {
        $hungerMaxPick = $pickMixed($snapshot, ['hunger_max', 'max_hunger']);
    }
    $hungerData = $parseCurrentMax($hungerCurrentPick['value'], $hungerMaxPick['value'], 300, $parseNonNegativeInt);

    $bloodDerivedFromLimbs = false;
    if (!boolval($bloodData['has_value']) || intval($bloodData['max']) <= 0) {
        $derivedBlood = $deriveBloodFromLimbs($limbsPayload);
        if (boolval($derivedBlood['has_value'])) {
            $bloodData = $derivedBlood;
            $bloodDerivedFromLimbs = true;
        }
    }

    if (boolval($bloodData['has_value']) && intval($bloodData['max']) <= 0) {
        $bloodData['max'] = 100;
    }
    if (intval($bloodData['current']) < 0) {
        $bloodData['current'] = 0;
    }
    if (intval($bloodData['max']) > 0 && intval($bloodData['current']) > intval($bloodData['max'])) {
        $bloodData['current'] = intval($bloodData['max']);
    }

    $hasLimbs = count($limbsPayload) > 0;
    $hasVitals = boolval($bloodData['has_value']) || boolval($hungerData['has_value']);
    $hasMedical = $hasMedicalPayload || $hasLimbs || $hasVitals;
    $limbsJson = normalizeJsonString($limbsPayload);
    $bloodValue = strval(intval($bloodData['current'])) . '/' . strval(intval($bloodData['max']));
    $hungerValue = strval(intval($hungerData['current'])) . '/' . strval(intval($hungerData['max']));
    $statsPayload = $snapshot['stats'] ?? [];
    $statsCount = is_array($statsPayload) ? count($statsPayload) : 0;

    if ($isBracketSnapshot) {
        stobeLogImport('Bracket snapshot ingest begin', [
            'incoming_name' => $incomingName,
            'resolved_name' => $name,
            'storage_id' => $storageId,
            'matched_by' => $matchedBy,
            'gamets' => max(0, $gamets),
            'race' => $race,
            'faction' => $faction,
            'gender' => $gender,
            'stats_count' => $statsCount,
            'has_medical_payload' => $hasMedicalPayload,
            'has_medical' => $hasMedical,
            'snapshot_keys' => array_keys($snapshot),
            'row_before' => is_array($rowBefore)
                ? [
                    'race' => trim(strval($rowBefore['race'] ?? '')),
                    'faction' => trim(strval($rowBefore['faction'] ?? '')),
                    'gender' => trim(strval($rowBefore['gender'] ?? '')),
                    'equipment_len' => strlen(trim(strval($rowBefore['equipment'] ?? ''))),
                    'skills_len' => strlen(trim(strval($rowBefore['skills'] ?? ''))),
                    'storage_id' => trim(strval($rowBefore['storage_id'] ?? '')),
                    'gamets_last_updated' => intval($rowBefore['gamets_last_updated'] ?? 0),
                ]
                : null,
        ], 'DEBUG');
    }

    $missingFields = [];
    if ($faction === '') {
        $missingFields[] = 'faction';
    }
    if ($gender === '') {
        $missingFields[] = 'gender';
    }
    if (!$hasLimbs) {
        $missingFields[] = 'limbs';
    }
    if (!$hasVitals) {
        $missingFields[] = 'vitals';
    }
    if (count($missingFields) > 0) {
        stobeLogImport('Snapshot field gaps detected', [
            'name' => $name,
            'incoming_name' => $incomingName,
            'identity_match' => $matchedBy,
            'missing_fields' => $missingFields,
            'race_source' => strval($racePick['source']),
            'faction_source' => strval($factionPick['source']),
            'faction_id_source' => strval($factionIdPick['source']),
            'gender_source' => strval($genderPick['source']),
            'blood_sources' => [
                'current' => strval($bloodCurrentPick['source']),
                'max' => strval($bloodMaxPick['source']),
            ],
            'hunger_sources' => [
                'current' => strval($hungerCurrentPick['source']),
                'max' => strval($hungerMaxPick['source']),
            ],
            'snapshot_keys' => array_keys($snapshot),
            'medical_keys' => array_keys($medical),
        ], 'WARN');
    }
    if ($bloodDerivedFromLimbs) {
        stobeLogImport('Blood derived from limb payload', [
            'name' => $name,
            'derived_blood' => $bloodData,
            'blood_sources' => [
                'current' => strval($bloodCurrentPick['source']),
                'max' => strval($bloodMaxPick['source']),
            ],
        ], 'DEBUG');
    }

    $town = trim(strval($snapshot['town'] ?? ''));

    $characterState = strtolower(trim(strval($snapshot['character_state'] ?? 'normal')));
    $isSlave = false;
    if (array_key_exists('is_slave', $snapshot)) {
        $isSlave = coerceBoolean($snapshot['is_slave']);
    } elseif ($characterState === 'enslaved' || $characterState === 'escaped-slave') {
        $isSlave = true;
    }
    $equipmentSummary = '';
    $inventorySummary = '';
    $inventoryEntriesRaw = $snapshot['inventory'] ?? [];
    $inventoryEntries = [];
    if (is_array($inventoryEntriesRaw)) {
        $inventoryEntries = $inventoryEntriesRaw;
    } elseif (is_string($inventoryEntriesRaw) && trim($inventoryEntriesRaw) !== '') {
        $decodedInventoryEntries = json_decode($inventoryEntriesRaw, true);
        if (is_array($decodedInventoryEntries)) {
            $inventoryEntries = $decodedInventoryEntries;
        }
    }
    $inventoryCounts = [];
    $equipmentCounts = [];
    $inventoryDescriptionCount = 0;
    if (is_array($inventoryEntries)) {
        foreach ($inventoryEntries as $entryKey => $entry) {
            if (!is_array($entry)) {
                $itemNameFromString = '';
                $itemCountFromScalar = 1;
                if (is_string($entryKey) && trim($entryKey) !== '') {
                    $itemNameFromString = trim($entryKey);
                    $itemCountFromScalar = $parseNonNegativeInt($entry, 1);
                    if ($itemCountFromScalar <= 0) {
                        $itemCountFromScalar = 1;
                    }
                } else {
                    $itemNameFromString = trim(strval($entry));
                }
                if ($itemNameFromString === '') {
                    continue;
                }
                $itemCountKey = strtolower($itemNameFromString);
                if (!array_key_exists($itemCountKey, $inventoryCounts)) {
                    $inventoryCounts[$itemCountKey] = [
                        'name' => $itemNameFromString,
                        'item_id' => '',
                        'count' => 0,
                        'value_each' => null,
                    ];
                }
                $inventoryCounts[$itemCountKey]['count'] += $itemCountFromScalar;
                continue;
            }
            $itemName = trim(strval($entry['name'] ?? (is_string($entryKey) ? $entryKey : '')));
            if ($itemName === '') {
                continue;
            }
            $itemCountRaw = $entry['count'] ?? ($entry['quantity'] ?? 1);
            $itemCount = $parseNonNegativeInt($itemCountRaw, 1);
            if ($itemCount <= 0) {
                $itemCount = 1;
            }
            $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            if ($itemDescription !== '') {
                $inventoryDescriptionCount++;
            }
            $itemValueEach = 0;
            $itemValueEachPick = $pickMixed($entry, [
                'value_each',
                'value_single',
                'single_value',
                'unit_value',
                'avg_value',
                'item_value',
            ]);
            if ($itemValueEachPick['source'] !== '') {
                $itemValueEach = $parseNonNegativeInt($itemValueEachPick['value'], 0);
            } else {
                $itemValueTotalPick = $pickMixed($entry, [
                    'value_total',
                    'value',
                    'total_value',
                    'price_total',
                    'price',
                    'cost',
                    'sell_value',
                    'buy_value',
                ]);
                if ($itemValueTotalPick['source'] !== '') {
                    $itemValueTotal = $parseNonNegativeInt($itemValueTotalPick['value'], 0);
                    if ($itemValueTotal > 0) {
                        $itemValueEach = intval(round(floatval($itemValueTotal) / floatval(max(1, $itemCount))));
                    }
                }
            }
            $itemIdPick = $pickText($entry, ['item_id', 'itemId', 'string_id', 'stringid', 'sid', 'baseid', 'id']);
            $itemId = trim(strval($itemIdPick['value']));
            $itemCountKeyId = $itemId !== '' ? strtolower($itemId) : strtolower($itemName);
            $itemCountKey = $itemCountKeyId;
            $isEquippedEntry = coerceBoolean($entry['equipped'] ?? ($entry['is_equipped'] ?? false));
            if ($isEquippedEntry) {
                if (!array_key_exists($itemCountKey, $equipmentCounts)) {
                    $equipmentCounts[$itemCountKey] = [
                        'name' => $itemName,
                        'item_id' => $itemId,
                        'description' => $itemDescription,
                        'count' => 0,
                        'value_each' => null,
                    ];
                }
                $equipmentCounts[$itemCountKey]['count'] += $itemCount;
                if (trim(strval($equipmentCounts[$itemCountKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                    $equipmentCounts[$itemCountKey]['item_id'] = $itemId;
                }
                if (trim(strval($equipmentCounts[$itemCountKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                    $equipmentCounts[$itemCountKey]['description'] = $itemDescription;
                }
                if (
                    $itemValueEach > 0 &&
                    (!isset($equipmentCounts[$itemCountKey]['value_each']) || intval($equipmentCounts[$itemCountKey]['value_each']) <= 0)
                ) {
                    $equipmentCounts[$itemCountKey]['value_each'] = $itemValueEach;
                }
                continue;
            }
            if (!array_key_exists($itemCountKey, $inventoryCounts)) {
                $inventoryCounts[$itemCountKey] = [
                    'name' => $itemName,
                    'item_id' => $itemId,
                    'description' => $itemDescription,
                    'count' => 0,
                    'value_each' => null,
                ];
            }
            $inventoryCounts[$itemCountKey]['count'] += $itemCount;
            if (trim(strval($inventoryCounts[$itemCountKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $inventoryCounts[$itemCountKey]['item_id'] = $itemId;
            }
            if (trim(strval($inventoryCounts[$itemCountKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                $inventoryCounts[$itemCountKey]['description'] = $itemDescription;
            }
            if (
                $itemValueEach > 0 &&
                (!isset($inventoryCounts[$itemCountKey]['value_each']) || intval($inventoryCounts[$itemCountKey]['value_each']) <= 0)
            ) {
                $inventoryCounts[$itemCountKey]['value_each'] = $itemValueEach;
            }
        }
    }
    stobeUpsertDescriptionsFromInventoryEntries($inventoryEntries, $snapshotSource, $name, $gamets);
    if (count($inventoryCounts) > 0) {
        $inventoryParts = [];
        foreach ($inventoryCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $entryValueEach = intval($entry['value_each'] ?? 0);
            $inventoryEntryText = $entryName . ' x' . strval($entryCount);
            if ($entryValueEach > 0) {
                $inventoryEntryText .= ' value ' . strval($entryValueEach);
            }
            $inventoryParts[] = $inventoryEntryText;
            if (count($inventoryParts) >= 60) {
                break;
            }
        }
        $inventorySummary = implode(', ', $inventoryParts);
    }
    if ($isInventoryLiveSync) {
        stobeLogImport('Inventory live sync ingest', [
            'name' => $name,
            'source' => $snapshotSource,
            'inventory_entry_count' => count($inventoryEntries),
            'inventory_description_count' => $inventoryDescriptionCount,
            'inventory_summary_len' => strlen($inventorySummary),
            'gamets' => max(0, $gamets),
        ], 'DEBUG');
    }
    if (count($equipmentCounts) > 0) {
        $equipmentParts = [];
        foreach ($equipmentCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $equipmentEntryText = $entryName . ' x' . strval($entryCount);
            $entryValueEach = intval($entry['value_each'] ?? 0);
            if ($entryValueEach > 0) {
                $equipmentEntryText .= ' value ' . strval($entryValueEach);
            }
            $equipmentParts[] = $equipmentEntryText;
            if (count($equipmentParts) >= 30) {
                break;
            }
        }
        $equipmentSummary = implode(', ', $equipmentParts);
    }
    if ($equipmentSummary === '') {
        $equipmentSummary = trim(strval($snapshot['equipment'] ?? ''));
    }

    $inventoryPromptItems = [];
    if (count($equipmentCounts) > 0) {
        foreach ($equipmentCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $inventoryPromptItems[] = [
                'name' => $entryName,
                'count' => $entryCount,
                'equipped' => true,
                'item_id' => trim(strval($entry['item_id'] ?? '')),
                'description' => stobeNormalizeItemDescriptionText(strval($entry['description'] ?? '')),
                'value_each' => intval($entry['value_each'] ?? 0),
            ];
            if (count($inventoryPromptItems) >= 220) {
                break;
            }
        }
    }
    if (count($inventoryPromptItems) < 220 && count($inventoryCounts) > 0) {
        foreach ($inventoryCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $inventoryPromptItems[] = [
                'name' => $entryName,
                'count' => $entryCount,
                'equipped' => false,
                'item_id' => trim(strval($entry['item_id'] ?? '')),
                'description' => stobeNormalizeItemDescriptionText(strval($entry['description'] ?? '')),
                'value_each' => intval($entry['value_each'] ?? 0),
            ];
            if (count($inventoryPromptItems) >= 220) {
                break;
            }
        }
    }

    $parseAppearanceBool = static function (array $source, array $keys): bool {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return coerceBoolean($source[$key]);
        }
        return false;
    };
    $parseNormalizedFloat = static function (array $source, array $keys, float $fallback = 0.5): float {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $raw = $source[$key];
            if (is_int($raw) || is_float($raw)) {
                $value = floatval($raw);
            } elseif (is_string($raw)) {
                $text = trim($raw);
                if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                    continue;
                }
                $value = floatval($text);
            } else {
                continue;
            }
            if ($value < 0.0) {
                $value = 0.0;
            } elseif ($value > 1.0) {
                $value = 1.0;
            }
            return $value;
        }
        if ($fallback < 0.0) {
            return 0.0;
        }
        if ($fallback > 1.0) {
            return 1.0;
        }
        return $fallback;
    };
    $heightBucketFromNorm = static function (float $norm): string {
        if ($norm < 0.33) {
            return 'short';
        }
        if ($norm > 0.66) {
            return 'tall';
        }
        return 'average';
    };
    $ageBucketFromNorm = static function (float $norm): string {
        if ($norm < 0.33) {
            return 'young';
        }
        if ($norm > 0.66) {
            return 'old';
        }
        return 'middle-aged';
    };
    $buildAppearanceFallback = static function (
        string $race,
        string $gender,
        bool $hasBeard,
        bool $isShaved,
        bool $isFlayed,
        float $heightNorm,
        float $ageNorm
    ) use ($heightBucketFromNorm, $ageBucketFromNorm): string {
        $raceText = trim($race);
        if ($raceText === '' || strcasecmp($raceText, 'Unknown') === 0) {
            $raceText = '';
        }
        $genderText = strtolower(trim($gender));
        if ($genderText !== 'male' && $genderText !== 'female') {
            $genderText = '';
        }

        $identityParts = [];
        if ($genderText !== '') {
            $identityParts[] = $genderText;
        }
        if ($raceText !== '') {
            $identityParts[] = $raceText;
        }
        $identity = count($identityParts) > 0 ? implode(' ', $identityParts) : 'wasteland drifter';
        $identity = ucfirst($identity);

        $heightBucket = $heightBucketFromNorm($heightNorm);
        $ageBucket = $ageBucketFromNorm($ageNorm);
        $ageText = $ageBucket;
        if ($ageBucket === 'old') {
            $ageText = 'older';
        }

        $sentences = [];
        $sentences[] = $identity . ' with a ' . $ageText . ' look.';
        $sentences[] = 'Build appears ' . $heightBucket . '.';
        if ($hasBeard) {
            $sentences[] = 'Keeps visible facial hair.';
        }
        if ($isShaved) {
            $sentences[] = 'Head is shaved.';
        }
        if ($isFlayed) {
            $sentences[] = 'Body shows flayed skin and heavy scarring.';
        }
        return implode(' ', $sentences);
    };
    $appearanceHasBeard = $parseAppearanceBool($snapshot, ['has_beard', 'beard', 'is_bearded']);
    $appearanceIsShaved = $parseAppearanceBool($snapshot, ['is_shaved', 'shaved']);
    $appearanceIsFlayed = $parseAppearanceBool($snapshot, ['is_flayed', 'flayed']);
    $appearanceHeightNorm = $parseNormalizedFloat($snapshot, ['height_norm', 'height_0to1', 'height_normalized'], 0.5);
    $appearanceAgeNorm = $parseNormalizedFloat($snapshot, ['age_norm', 'age_0to1'], 0.5);
    $appearanceFallback = $buildAppearanceFallback(
        $race,
        $gender,
        $appearanceHasBeard,
        $appearanceIsShaved,
        $appearanceIsFlayed,
        $appearanceHeightNorm,
        $appearanceAgeNorm
    );
    $appearance = '';

    $stats = $statsPayload;
    $skills = '';
    if (is_array($stats)) {
        $parseSkillInt = static function (mixed $value): int {
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return intval($value);
            }
            if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
                return intval(floatval($value));
            }
            return 0;
        };

        $statAliases = [
            'melee_defense' => 'melee_defence',
            'martialarts' => 'martial_arts',
            'heavyweapons' => 'heavy_weapons',
            'weapon_smith' => 'weapon_smithing',
            'armor_smithing' => 'armour_smithing',
            'armour_smith' => 'armour_smithing',
            'bow_smith' => 'bow_smithing',
            'hivemedic' => 'hive_medic',
        ];
        foreach ($statAliases as $aliasKey => $canonicalKey) {
            if (array_key_exists($canonicalKey, $stats) || !array_key_exists($aliasKey, $stats)) {
                continue;
            }
            $stats[$canonicalKey] = $stats[$aliasKey];
        }

        $attributeMap = [
            'strength' => 'STR',
            'dexterity' => 'DEX',
            'toughness' => 'TGH',
            'perception' => 'PER',
        ];
        $combatMap = [
            'melee_attack' => 'MAtk',
            'melee_defence' => 'MDef',
            'dodge' => 'Dodge',
            'martial_arts' => 'MA',
            'katanas' => 'Katana',
            'sabres' => 'Sabre',
            'hackers' => 'Hacker',
            'heavy_weapons' => 'Heavy',
            'blunt' => 'Blunt',
            'polearms' => 'Polearm',
            'crossbows' => 'Crossbow',
            'turrets' => 'Turret',
            'athletics' => 'Athletics',
            'stealth' => 'Stealth',
            'assassination' => 'Assassin',
            'swimming' => 'Swim',
            'survival' => 'Survival',
        ];
        $coreMap = [
            'labouring' => 'Labour',
            'thieving' => 'Thieving',
            'lockpicking' => 'Lockpick',
            'medic' => 'Medic',
            'science' => 'Science',
            'engineering' => 'Engineer',
            'robotics' => 'Robotics',
            'farming' => 'Farming',
            'cooking' => 'Cooking',
            'weapon_smithing' => 'WpnSmith',
            'armour_smithing' => 'ArmSmith',
            'bow_smithing' => 'BowSmith',
            'hive_medic' => 'HiveMedic',
            'vet' => 'Vet',
        ];

        $attributeParts = [];
        foreach ($attributeMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $attributeParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $combatParts = [];
        foreach ($combatMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $combatParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $coreParts = [];
        foreach ($coreMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $coreParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $skillSections = [];
        if (count($attributeParts) > 0) {
            $skillSections[] = 'ATTR: ' . implode(' | ', $attributeParts);
        }
        if (count($combatParts) > 0) {
            $skillSections[] = 'COMBAT: ' . implode(', ', $combatParts);
        }
        if (count($coreParts) > 0) {
            $skillSections[] = 'CORE: ' . implode(', ', $coreParts);
        }
        $skills = implode(' || ', $skillSections);
    }

    $traderShopSourcesRaw = $snapshot['trader_shop_sources'] ?? [];
    $traderShopSources = [];
    if (is_array($traderShopSourcesRaw)) {
        $traderShopSources = $traderShopSourcesRaw;
    } elseif (is_string($traderShopSourcesRaw) && trim($traderShopSourcesRaw) !== '') {
        $decodedTraderShopSources = json_decode($traderShopSourcesRaw, true);
        if (is_array($decodedTraderShopSources)) {
            $traderShopSources = $decodedTraderShopSources;
        }
    }

    $traderShopSourceSummaries = [];
    $traderShopInventoryCounts = [];
    $traderShopInventoryTotalCount = 0;
    foreach ($traderShopSources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $sourceName = trim(strval($source['name'] ?? ''));
        if ($sourceName === '') {
            $sourceName = 'shop storage';
        }
        $sourceSerial = $parseNonNegativeInt($source['serial'] ?? 0, 0);
        $sourceDist = 0.0;
        if (isset($source['dist']) && is_numeric($source['dist'])) {
            $sourceDist = floatval($source['dist']);
            if (!is_finite($sourceDist) || $sourceDist < 0.0) {
                $sourceDist = 0.0;
            }
        }
        $sourceBuildingClass = trim(strval($source['building_class'] ?? ''));
        $sourceSpecialFunction = trim(strval($source['special_function'] ?? ''));

        $sourceInventoryRaw = $source['inventory'] ?? [];
        $sourceInventory = [];
        if (is_array($sourceInventoryRaw)) {
            $sourceInventory = $sourceInventoryRaw;
        } elseif (is_string($sourceInventoryRaw) && trim($sourceInventoryRaw) !== '') {
            $decodedSourceInventory = json_decode($sourceInventoryRaw, true);
            if (is_array($decodedSourceInventory)) {
                $sourceInventory = $decodedSourceInventory;
            }
        }

        $sourceInventoryTotalCount = 0;
        foreach ($sourceInventory as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $itemName = trim(strval($entry['name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $itemCount = $parseNonNegativeInt($entry['count'] ?? ($entry['quantity'] ?? 1), 1);
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
            $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            $itemValueEach = $parseNonNegativeInt(
                $entry['value_each'] ?? ($entry['value_single'] ?? ($entry['value'] ?? 0)),
                0
            );

            $itemKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName));
            if (!array_key_exists($itemKey, $traderShopInventoryCounts)) {
                $traderShopInventoryCounts[$itemKey] = [
                    'name' => $itemName,
                    'count' => 0,
                    'equipped' => false,
                    'item_id' => $itemId,
                    'description' => $itemDescription,
                    'value_each' => $itemValueEach,
                ];
            }
            $traderShopInventoryCounts[$itemKey]['count'] += $itemCount;
            if (trim(strval($traderShopInventoryCounts[$itemKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $traderShopInventoryCounts[$itemKey]['item_id'] = $itemId;
            }
            if (
                trim(strval($traderShopInventoryCounts[$itemKey]['description'] ?? '')) === '' &&
                $itemDescription !== ''
            ) {
                $traderShopInventoryCounts[$itemKey]['description'] = $itemDescription;
            }
            if (
                intval($traderShopInventoryCounts[$itemKey]['value_each'] ?? 0) <= 0 &&
                $itemValueEach > 0
            ) {
                $traderShopInventoryCounts[$itemKey]['value_each'] = $itemValueEach;
            }
            $sourceInventoryTotalCount += $itemCount;
            $traderShopInventoryTotalCount += $itemCount;
            if (count($traderShopInventoryCounts) >= 260) {
                break;
            }
        }

        $sourceItemCountFromPayload = $parseNonNegativeInt(
            $source['inventory_item_count'] ?? $sourceInventoryTotalCount,
            $sourceInventoryTotalCount
        );
        if ($sourceItemCountFromPayload <= 0) {
            $sourceItemCountFromPayload = $sourceInventoryTotalCount;
        }

        $traderShopSourceSummaries[] = [
            'name' => $sourceName,
            'serial' => $sourceSerial,
            'dist' => $sourceDist,
            'building_class' => $sourceBuildingClass,
            'special_function' => $sourceSpecialFunction,
            'inventory_item_count' => $sourceItemCountFromPayload,
        ];
        if (count($traderShopSourceSummaries) >= 8) {
            break;
        }
    }

    $snapshotTraderInventoryItemsRaw = $snapshot['trader_inventory_items'] ?? [];
    $snapshotTraderInventoryItems = [];
    if (is_array($snapshotTraderInventoryItemsRaw)) {
        $snapshotTraderInventoryItems = $snapshotTraderInventoryItemsRaw;
    } elseif (is_string($snapshotTraderInventoryItemsRaw) && trim($snapshotTraderInventoryItemsRaw) !== '') {
        $decodedSnapshotTraderInventoryItems = json_decode($snapshotTraderInventoryItemsRaw, true);
        if (is_array($decodedSnapshotTraderInventoryItems)) {
            $snapshotTraderInventoryItems = $decodedSnapshotTraderInventoryItems;
        }
    }
    $snapshotTraderInventoryEntryCount = 0;
    foreach ($snapshotTraderInventoryItems as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $snapshotTraderInventoryEntryCount++;
    }

    $traderShopSourceCount = count($traderShopSourceSummaries);
    $traderShopItemCountFromSnapshot = $parseNonNegativeInt(
        $snapshot['trader_shop_item_count'] ?? $traderShopInventoryTotalCount,
        $traderShopInventoryTotalCount
    );
    $traderShopItemCount = max($traderShopInventoryTotalCount, $traderShopItemCountFromSnapshot);
    $isLikelyTraderContext = coerceBoolean($snapshot['is_trader'] ?? false) ||
        $traderShopSourceCount > 0 || $snapshotTraderInventoryEntryCount > 0;

    $occupationParts = [];
    if ($isLikelyTraderContext) {
        $occupationParts[] = 'Trader';
    }
    if (coerceBoolean($snapshot['is_leader'] ?? false)) {
        $occupationParts[] = 'Leader';
    }
    if ($faction !== '') {
        $occupationParts[] = 'Faction: ' . $faction;
    }
    if ($town !== '') {
        $occupationParts[] = 'Town: ' . $town;
    }
    $occupationDefault = implode(' | ', $occupationParts);

    $personalityDefault = 'Pragmatic wasteland survivor.';
    if ($characterState === 'dead') {
        $personalityDefault = 'No longer active.';
    } elseif ($characterState === 'imprisoned') {
        $personalityDefault = 'Currently imprisoned and cautious.';
    } elseif ($characterState === 'enslaved' || $characterState === 'escaped-slave') {
        $personalityDefault = 'Marked by hardship and survival instincts.';
    } elseif ($characterState === 'unconscious') {
        $personalityDefault = 'Incapacitated at the moment.';
    }

    $backstoryParts = [];
    if ($race !== '') {
        $backstoryParts[] = $race;
    }
    if ($faction !== '') {
        $backstoryParts[] = 'aligned with ' . $faction;
    }
    if ($town !== '') {
        $backstoryParts[] = 'seen around ' . $town;
    }
    $backstoryDefault = count($backstoryParts) > 0
        ? ('Observed in Kenshi: ' . implode(', ', $backstoryParts) . '.')
        : 'Observed in Kenshi world state.';

    $goalsDefault = $isPlayerCharacter
        ? 'Lead the squad and survive the wasteland.'
        : 'Survive, recover, and pursue faction priorities.';
    $speechstyleDefault = 'Direct and practical.';
    $originalNameForTraits = trim(strval($snapshot['original_name'] ?? ''));
    $traitSelection = selectBioTraitsForNpc($name, $race, $gender, $faction, $originalNameForTraits);
    $resolvedTraits = is_array($traitSelection['traits'] ?? null) ? $traitSelection['traits'] : [];
    $personality = trim(strval($resolvedTraits['personality'] ?? ''));
    if ($personality === '') {
        $personality = $personalityDefault;
    }
    $backstory = trim(strval($resolvedTraits['backstory'] ?? ''));
    if ($backstory === '') {
        $backstory = $backstoryDefault;
    }
    $speechstyle = trim(strval($resolvedTraits['speechstyle'] ?? ''));
    if ($speechstyle === '') {
        $speechstyle = $speechstyleDefault;
    }
    $occupation = trim(strval($resolvedTraits['occupation'] ?? ''));
    if ($occupation === '') {
        $occupation = $occupationDefault;
    }
    if ($faction !== '' && stripos($occupation, 'faction:') === false) {
        $occupation = trim($occupation) !== ''
            ? ($occupation . ' | Faction: ' . $faction)
            : ('Faction: ' . $faction);
    }
    $goals = trim(strval($resolvedTraits['goals'] ?? ''));
    if ($goals === '') {
        $goals = $goalsDefault;
    } elseif (stripos($goals, 'survive') === false) {
        $goals .= ' | ' . $goalsDefault;
    }
    $appearanceTrait = trim(strval($resolvedTraits['appearance'] ?? ''));
    if ($appearanceTrait !== '') {
        $appearance = $appearanceTrait;
    } else {
        $appearance = $appearanceFallback;
    }
    $emoteMoods = stobeResolveGlobalEmoteMoods();
    if ($characterState !== 'normal' && $characterState !== '') {
        $emoteMoods .= ',state:' . $characterState;
    }

    $snapshotExtendedPayload = normalizeCoreNpcExtendedData([
        'environment' => $snapshot['environment'] ?? [],
        'nearby_actors' => $snapshot['nearby'] ?? [],
        'nearby_items' => $snapshot['nearby_items'] ?? [],
        'points_of_interest' => $snapshot['points_of_interest'] ?? [],
        'trader_shop_sources' => $traderShopSourceSummaries,
        'trader_shop_source_count' => $traderShopSourceCount,
        'trader_shop_item_count' => $traderShopItemCount,
    ]);

    if ($storageId !== '') {
        $snapshot['storage_id'] = $storageId;
    }
    $metadataForStorage = normalizeCoreNpcMetadata($snapshot);
    if ($storageId !== '' && !array_key_exists('storage_id', $metadataForStorage)) {
        $metadataForStorage['storage_id'] = $storageId;
    }
    $shouldPreserveTraderMetadata =
        $snapshotTraderInventoryEntryCount <= 0 &&
        $traderShopSourceCount <= 0 &&
        !array_key_exists('trader_inventory_items', $metadataForStorage) &&
        !array_key_exists('trader_shop_sources', $metadataForStorage);
    if ($shouldPreserveTraderMetadata) {
        $existingMasterRow = $db->fetchOne(
            "SELECT metadata
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
        $existingMetadata = normalizeCoreNpcMetadata($existingMasterRow['metadata'] ?? []);
        $preservedTraderKeys = [
            'is_trader',
            'trader_inventory_items',
            'trader_inventory_item_count',
            'trader_inventory_snapshot_gamets',
            'trader_shop_sources',
            'trader_shop_source_count',
            'trader_shop_item_count',
        ];
        $preservedApplied = [];
        foreach ($preservedTraderKeys as $preservedKey) {
            if (
                !array_key_exists($preservedKey, $metadataForStorage) &&
                array_key_exists($preservedKey, $existingMetadata)
            ) {
                $metadataForStorage[$preservedKey] = $existingMetadata[$preservedKey];
                $preservedApplied[] = $preservedKey;
            }
        }
        if (count($preservedApplied) > 0) {
            stobeLogImport('Snapshot preserved trader metadata', [
                'name' => $name,
                'keys' => $preservedApplied,
                'gamets' => max(0, $gamets),
                'source' => $snapshotSource,
            ], 'DEBUG');
        }
    }
    if (count($inventoryPromptItems) > 0) {
        $metadataForStorage['inventory_items'] = $inventoryPromptItems;
    }
    if ($snapshotTraderInventoryEntryCount > 0) {
        $metadataForStorage['trader_inventory_items'] = $snapshotTraderInventoryItems;
        if (!array_key_exists('trader_inventory_item_count', $metadataForStorage)) {
            $metadataForStorage['trader_inventory_item_count'] = $snapshotTraderInventoryEntryCount;
        }
        if (!array_key_exists('trader_inventory_snapshot_gamets', $metadataForStorage)) {
            $metadataForStorage['trader_inventory_snapshot_gamets'] = max(0, $gamets);
        }
    }
    if ($traderShopSourceCount > 0) {
        $metadataForStorage['trader_shop_sources'] = $traderShopSourceSummaries;
        $metadataForStorage['trader_shop_source_count'] = $traderShopSourceCount;
        $metadataForStorage['trader_shop_item_count'] = $traderShopItemCount;
    }
    if ($isLikelyTraderContext) {
        $mergeTraderEntry = static function (array &$bucket, array $entry): void {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                return;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $entryItemId = trim(strval($entry['item_id'] ?? ''));
            $entryDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            $entryValueEach = intval($entry['value_each'] ?? 0);
            if ($entryValueEach < 0) {
                $entryValueEach = 0;
            }

            $entryKey = strtolower($entryItemId !== '' ? ('id:' . $entryItemId) : ('name:' . $entryName));
            if (!array_key_exists($entryKey, $bucket)) {
                $bucket[$entryKey] = [
                    'name' => $entryName,
                    'count' => 0,
                    'equipped' => false,
                    'item_id' => $entryItemId,
                    'description' => $entryDescription,
                    'value_each' => $entryValueEach,
                ];
            }

            $bucket[$entryKey]['count'] += $entryCount;
            if (trim(strval($bucket[$entryKey]['item_id'] ?? '')) === '' && $entryItemId !== '') {
                $bucket[$entryKey]['item_id'] = $entryItemId;
            }
            if (
                trim(strval($bucket[$entryKey]['description'] ?? '')) === '' &&
                $entryDescription !== ''
            ) {
                $bucket[$entryKey]['description'] = $entryDescription;
            }
            if (
                intval($bucket[$entryKey]['value_each'] ?? 0) <= 0 &&
                $entryValueEach > 0
            ) {
                $bucket[$entryKey]['value_each'] = $entryValueEach;
            }
        };

        $traderInventoryByKey = [];
        $traderInventoryItems = [];
        $traderInventoryCount = 0;
        foreach ($inventoryPromptItems as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (coerceBoolean($entry['equipped'] ?? false)) {
                continue;
            }
            $mergeTraderEntry($traderInventoryByKey, $entry);
        }
        foreach ($traderShopInventoryCounts as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $mergeTraderEntry($traderInventoryByKey, $entry);
        }

        foreach ($traderInventoryByKey as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $traderInventoryItems[] = $entry;
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $traderInventoryCount += $entryCount;
            if (count($traderInventoryItems) >= 260) {
                break;
            }
        }

        // Fallback: if slot classification is unavailable, keep the full snapshot list.
        if (count($traderInventoryItems) === 0 && count($inventoryPromptItems) > 0) {
            $traderInventoryItems = $inventoryPromptItems;
            $traderInventoryCount = 0;
            foreach ($traderInventoryItems as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryCount = intval($entry['count'] ?? 1);
                if ($entryCount <= 0) {
                    $entryCount = 1;
                }
                $traderInventoryCount += $entryCount;
            }
        }
        if ($traderInventoryCount <= 0) {
            $traderInventoryCount = $traderShopItemCount;
        }

        $metadataForStorage['trader_inventory_items'] = $traderInventoryItems;
        $metadataForStorage['trader_inventory_item_count'] = $traderInventoryCount;
        $metadataForStorage['trader_inventory_snapshot_gamets'] = max(0, $gamets);
    }
    storeNpcProfile($name, [
        'race' => $race,
        'faction' => $faction,
        'gender' => $gender,
        'appearance' => $appearance,
        'equipment' => $equipmentSummary,
        'inventory' => $inventorySummary,
        'occupation' => $occupation,
        'skills' => $skills,
        'speechstyle' => $speechstyle,
        'goals' => $goals,
        'personality' => $personality,
        'backstory' => $backstory,
        'emote_moods' => $emoteMoods,
        'bounty' => $bountyPayload,
        'is_animal' => $isAnimal,
        'tags' => $tags,
        'metadata' => $metadataForStorage,
        'extended_data' => $snapshotExtendedPayload,
    ], [
        'skip_history' => true,
        'defer_voice_assignment' => true,
    ]);

    $metadataJson = normalizeJsonString($metadataForStorage);
    $extendedDataJson = normalizeJsonString($snapshotExtendedPayload);
    $bountyJson = stobeNormalizeBountyJsonString($bountyPayload);
    $safeGamets = max(0, $gamets);
    $defaultProfileId = getDefaultNpcProfileId();
    $defaultProfileIdOrNull = $defaultProfileId > 0 ? $defaultProfileId : null;

    $result = $db->exec(
        "INSERT INTO core_npc_master (
            name,
            race,
            faction,
            gender,
            profile_id,
            is_animal,
            is_slave,
            metadata,
            gamets_last_updated,
            extended_data,
            tags,
            bounty,
            equipment,
            inventory,
            limbs,
            blood,
            hunger,
            updated_at
        ) VALUES (
            $1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9, $10::jsonb, $11, $12::jsonb,
            $13, $14, $15::jsonb, $16, $17,
            NOW()
        )
        ON CONFLICT (name) DO UPDATE SET
            race = COALESCE(NULLIF($2, ''), core_npc_master.race),
            faction = COALESCE(NULLIF($3, ''), core_npc_master.faction),
            gender = COALESCE(NULLIF($4, ''), core_npc_master.gender),
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            is_animal = $6,
            is_slave = $7,
            metadata = $8::jsonb,
            gamets_last_updated = CASE
                WHEN $9 > core_npc_master.gamets_last_updated THEN $9
                ELSE core_npc_master.gamets_last_updated
            END,
            extended_data = CASE
                WHEN $10::jsonb = '{}'::jsonb OR $10::jsonb = '[]'::jsonb THEN core_npc_master.extended_data
                ELSE $10::jsonb
            END,
            tags = CASE
                WHEN NULLIF($11, '') IS NOT NULL THEN $11
                ELSE ''
            END,
            bounty = CASE
                WHEN $12::jsonb <> '{}'::jsonb THEN $12::jsonb
                ELSE COALESCE(core_npc_master.bounty, '{}'::jsonb)
            END,
            equipment = CASE
                WHEN NULLIF($13, '') IS NOT NULL THEN $13
                ELSE COALESCE(core_npc_master.equipment, '')
            END,
            inventory = CASE
                WHEN $20 THEN $14
                WHEN NULLIF($14, '') IS NOT NULL THEN $14
                ELSE COALESCE(core_npc_master.inventory, '')
            END,
            limbs = CASE WHEN $18 THEN $15::jsonb ELSE core_npc_master.limbs END,
            blood = CASE WHEN $19 THEN $16 ELSE core_npc_master.blood END,
            hunger = CASE WHEN $19 THEN $17 ELSE core_npc_master.hunger END,
            updated_at = NOW()",
        [
            $name, $race, $faction, $gender, $defaultProfileIdOrNull, $isAnimal, $isSlave, $metadataJson, $safeGamets, $extendedDataJson, $tags, $bountyJson,
            $equipmentSummary, $inventorySummary, $limbsJson, $bloodValue, $hungerValue, $hasLimbs, $hasVitals, $isInventoryLiveSync
        ]
    );

    if ($result !== false) {
        stobeUpsertLocationZoneFromSnapshot($snapshot, $safeGamets, $name);
        stobeAssignVoiceIdIfMissing(
            $name,
            $race,
            $gender,
            $faction,
            [
                'metadata' => $metadataForStorage,
                'original_name' => $originalNameForTraits,
            ]
        );
    }

    $isSparseSnapshot = (
        $race === '' &&
        $faction === '' &&
        $gender === '' &&
        !$hasMedical &&
        !is_array($snapshot['stats'] ?? null)
    );
    if ($isSparseSnapshot) {
        stobeLogImport('Sparse snapshot persisted', [
            'name' => $name,
            'gamets' => $safeGamets,
            'has_limbs' => $hasLimbs,
            'has_medical' => $hasMedical,
            'keys' => array_keys($snapshot),
        ], 'WARN');
    }

    if ($isBracketSnapshot) {
        $rowAfter = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
        if ($rowAfter) {
            $raceAfter = trim(strval($rowAfter['race'] ?? ''));
            $factionAfter = trim(strval($rowAfter['faction'] ?? ''));
            $genderAfter = trim(strval($rowAfter['gender'] ?? ''));
            $equipmentAfter = trim(strval($rowAfter['equipment'] ?? ''));
            $skillsAfter = trim(strval($rowAfter['skills'] ?? ''));
            $isUnresolved = (
                ($raceAfter === '' || strtolower($raceAfter) === 'unknown') &&
                $factionAfter === '' &&
                $genderAfter === '' &&
                $equipmentAfter === '' &&
                $skillsAfter === ''
            );
            stobeLogImport('Bracket snapshot ingest end', [
                'incoming_name' => $incomingName,
                'resolved_name' => $name,
                'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                'matched_by' => $matchedBy,
                'result_ok' => ($result !== false),
                'race' => $raceAfter,
                'faction' => $factionAfter,
                'gender' => $genderAfter,
                'equipment_len' => strlen($equipmentAfter),
                'skills_len' => strlen($skillsAfter),
                'gamets_last_updated' => intval($rowAfter['gamets_last_updated'] ?? 0),
                'stats_count' => $statsCount,
                'has_medical_payload' => $hasMedicalPayload,
                'has_medical' => $hasMedical,
            ], $isUnresolved ? 'WARN' : 'DEBUG');
            if ($isUnresolved) {
                stobeLogImport('Bracket snapshot unresolved after upsert', [
                    'incoming_name' => $incomingName,
                    'resolved_name' => $name,
                    'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                    'matched_by' => $matchedBy,
                    'gamets' => $safeGamets,
                    'snapshot_keys' => array_keys($snapshot),
                ], 'WARN');
            }
        }
    }

    $historyRowAfter = stobeFetchNpcRowForHistoryByName($name);
    stobeMaybeSnapshotNpcHistoryBeforeAfter($historyRowBefore, $historyRowAfter, 'snapshot_sync');

    return $result !== false;
}

function getNpcById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT n.*,
                p.label AS profile_name,
                llm.name AS profile_response_connector_name,
                tts.name AS profile_tts_connector_name
         FROM core_npc n
         LEFT JOIN core_profiles p ON p.id = n.profile_id
         LEFT JOIN core_llm_connector llm ON llm.id = p.response_connector
         LEFT JOIN core_tts_connector tts ON tts.id = p.tts_connector_id
         WHERE n.id = $1",
        [$id]
    );
}

function deleteNpc(int $id): void {
    $db = $GLOBALS["db"];
    $historyRowBefore = stobeFetchNpcRowForHistoryById($id);
    if ($historyRowBefore) {
        stobeInsertNpcHistorySnapshotFromRow($historyRowBefore, 'delete_before');
    }
    $db->exec("DELETE FROM core_npc WHERE id = $1", [$id]);
}

function updateNpcById(int $id, array $fields): void {
    $db = $GLOBALS["db"];
    $historyRowBefore = stobeFetchNpcRowForHistoryById($id);
    if (array_key_exists('dynamic_profile', $fields) || array_key_exists('middle_term_enabled', $fields)) {
        $metadata = [];
        if (array_key_exists('metadata', $fields)) {
            $metadata = normalizeCoreNpcMetadata($fields['metadata']);
        } elseif ($historyRowBefore) {
            $metadata = normalizeCoreNpcMetadata($historyRowBefore['metadata'] ?? []);
        }

        if (array_key_exists('dynamic_profile', $fields)) {
            $raw = $fields['dynamic_profile'];
            if ($raw === '' || $raw === null) {
                unset($metadata['DYNAMIC_PROFILE_ENABLED']);
            } else {
                $metadata['DYNAMIC_PROFILE_ENABLED'] = coerceBoolean($raw);
            }
            unset($fields['dynamic_profile']);
        }

        if (array_key_exists('middle_term_enabled', $fields)) {
            $raw = $fields['middle_term_enabled'];
            if ($raw === '' || $raw === null) {
                unset($metadata['MIDDLE_TERM_MEMORY_ENABLED']);
            } else {
                $metadata['MIDDLE_TERM_MEMORY_ENABLED'] = coerceBoolean($raw);
            }
            unset($fields['middle_term_enabled']);
        }

        $fields['metadata'] = $metadata;
    }

    $allowedColumns = [
        'race' => 'text',
        'faction' => 'text',
        'gender' => 'text',
        'bounty' => 'bounty_json',
        'limbs' => 'json',
        'blood' => 'text',
        'hunger' => 'text',
        'tags' => 'text',
        'prompt_head' => 'text',
        'personality' => 'text',
        'backstory' => 'text',
        'appearance' => 'text',
        'equipment' => 'text',
        'inventory' => 'text',
        'occupation' => 'text',
        'skills' => 'text',
        'speechstyle' => 'text',
        'goals' => 'text',
        'emote_moods' => 'text',
        'voiceid' => 'text',
        'world_knowledge_tags' => 'text',
        'profile_id' => 'int_or_null',
        'npc_favorite' => 'bool',
        'lock_profile' => 'bool',
        'is_animal' => 'bool',
        'is_slave' => 'bool',
        'metadata' => 'json',
        'extended_data' => 'json',
        'gamets_last_updated' => 'int',
        'md5' => 'text',
    ];

    $setClauses = [];
    $params = [];
    $paramIndex = 1;

    foreach ($allowedColumns as $column => $type) {
        if (!array_key_exists($column, $fields)) {
            continue;
        }

        if ($type === 'json') {
            $setClauses[] = "{$column} = $" . $paramIndex . "::jsonb";
            $params[] = normalizeJsonString($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'bounty_json') {
            $setClauses[] = "{$column} = $" . $paramIndex . "::jsonb";
            $params[] = stobeNormalizeBountyJsonString($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'bool') {
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = coerceBoolean($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'int_or_null') {
            $rawValue = $fields[$column];
            $normalized = null;
            if ($rawValue !== null && strval($rawValue) !== '') {
                $normalized = intval($rawValue);
            }
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = $normalized;
            $paramIndex++;
            continue;
        }

        if ($type === 'int') {
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = intval($fields[$column]);
            $paramIndex++;
            continue;
        }

        $setClauses[] = "{$column} = $" . $paramIndex;
        $params[] = strval($fields[$column]);
        $paramIndex++;
    }

    if (count($setClauses) === 0) {
        return;
    }

    $params[] = $id;
    $idIndex = $paramIndex;
    $query = "UPDATE core_npc SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = $" . $idIndex;
    $db->exec($query, $params);
    $historyRowAfter = stobeFetchNpcRowForHistoryById($id);
    stobeMaybeSnapshotNpcHistoryBeforeAfter($historyRowBefore, $historyRowAfter, 'ui_update');
}

function getAllCoreProfiles(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT p.*,
                rc.name AS response_connector_name,
                dc.name AS diary_connector_name,
                ac.name AS autochat_connector_name,
                mc.name AS middleterm_connector_name,
                tc.name AS tts_connector_name
         FROM core_profiles p
         LEFT JOIN core_llm_connector rc ON rc.id = p.response_connector
         LEFT JOIN core_llm_connector dc ON dc.id = p.diary_connector
         LEFT JOIN core_llm_connector ac ON ac.id = p.autochat_connector
         LEFT JOIN core_llm_connector mc ON mc.id = p.middleterm_connector
         LEFT JOIN core_tts_connector tc ON tc.id = p.tts_connector_id
         ORDER BY p.is_default_npc DESC, p.label ASC"
    );
}

function getCoreProfileById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT p.*,
                rc.name AS response_connector_name,
                dc.name AS diary_connector_name,
                ac.name AS autochat_connector_name,
                mc.name AS middleterm_connector_name,
                tc.name AS tts_connector_name
         FROM core_profiles p
         LEFT JOIN core_llm_connector rc ON rc.id = p.response_connector
         LEFT JOIN core_llm_connector dc ON dc.id = p.diary_connector
         LEFT JOIN core_llm_connector ac ON ac.id = p.autochat_connector
         LEFT JOIN core_llm_connector mc ON mc.id = p.middleterm_connector
         LEFT JOIN core_tts_connector tc ON tc.id = p.tts_connector_id
         WHERE p.id = $1
         LIMIT 1",
        [$id]
    );
}

function saveCoreProfile(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $label = trim(strval($fields['label'] ?? ''));
    if ($label === '') {
        return 0;
    }

    $isDefaultNpc = coerceBoolean($fields['is_default_npc'] ?? false);
    $promptHead = trim(strval($fields['prompt_head'] ?? ''));
    $profilePrompt = trim(strval($fields['profile_prompt'] ?? ''));
    $responseConnector = ($fields['response_connector'] ?? '') === '' ? null : intval($fields['response_connector']);
    $diaryConnector = ($fields['diary_connector'] ?? '') === '' ? null : intval($fields['diary_connector']);
    $autochatConnector = ($fields['autochat_connector'] ?? '') === '' ? null : intval($fields['autochat_connector']);
    $middletermConnector = ($fields['middleterm_connector'] ?? '') === '' ? null : intval($fields['middleterm_connector']);
    $backgroundlifeConnector = ($fields['backgroundlife_connector'] ?? '') === '' ? null : intval($fields['backgroundlife_connector']);
    $dynamicConnector = ($fields['dynamic_connector'] ?? '') === '' ? null : intval($fields['dynamic_connector']);
    $relationshipConnector = ($fields['relationship_connector'] ?? '') === '' ? null : intval($fields['relationship_connector']);
    $ttsConnector = ($fields['tts_connector_id'] ?? '') === '' ? null : intval($fields['tts_connector_id']);
    $metadataJson = normalizeJsonString($fields['metadata'] ?? '{}');

    $savedId = 0;
    if ($id > 0) {
        if ($isDefaultNpc) {
            // Ensure only one default profile by clearing others before assigning this one.
            $db->exec(
                "UPDATE core_profiles
                 SET is_default_npc = FALSE,
                     updated_at = NOW()
                 WHERE id <> $1
                   AND is_default_npc = TRUE",
                [$id]
            );
        }
        $row = $db->fetchOne(
            "UPDATE core_profiles
             SET label = $1,
                 is_default_npc = $2,
                 prompt_head = $3,
                 profile_prompt = $4,
                 response_connector = $5,
                 diary_connector = $6,
                 autochat_connector = $7,
                 middleterm_connector = $8,
                 backgroundlife_connector = $9,
                 dynamic_connector = $10,
                 relationship_connector = $11,
                 tts_connector_id = $12,
                 metadata = $13::jsonb,
                 updated_at = NOW()
             WHERE id = $14
             RETURNING id",
            [
                $label,
                $isDefaultNpc,
                $promptHead,
                $profilePrompt,
                $responseConnector,
                $diaryConnector,
                $autochatConnector,
                $middletermConnector,
                $backgroundlifeConnector,
                $dynamicConnector,
                $relationshipConnector,
                $ttsConnector,
                $metadataJson,
                $id,
            ]
        );
        $savedId = intval($row['id'] ?? 0);
    } else {
        if ($isDefaultNpc) {
            // For new profiles marked default, unset any previous default first.
            $db->exec(
                "UPDATE core_profiles
                 SET is_default_npc = FALSE,
                     updated_at = NOW()
                 WHERE is_default_npc = TRUE"
            );
        }
        $row = $db->fetchOne(
            "INSERT INTO core_profiles (
                label,
                is_default_npc,
                prompt_head,
                profile_prompt,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
             ) VALUES (
                $1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13::jsonb
             )
             RETURNING id",
            [
                $label,
                $isDefaultNpc,
                $promptHead,
                $profilePrompt,
                $responseConnector,
                $diaryConnector,
                $autochatConnector,
                $middletermConnector,
                $backgroundlifeConnector,
                $dynamicConnector,
                $relationshipConnector,
                $ttsConnector,
                $metadataJson,
            ]
        );
        $savedId = intval($row['id'] ?? 0);
    }

    if ($savedId > 0 && $isDefaultNpc) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE, updated_at = NOW()
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$savedId]
        );
    }

    return $savedId;
}

function deleteCoreProfile(int $id): void {
    $db = $GLOBALS["db"];
    if ($id <= 0) {
        return;
    }

    $defaultProfileId = getDefaultNpcProfileId();
    if ($defaultProfileId > 0) {
        $db->exec(
            "UPDATE core_npc
             SET profile_id = $1,
                 updated_at = NOW()
             WHERE profile_id = $2",
            [$defaultProfileId, $id]
        );
    }
    $db->exec("DELETE FROM core_profiles WHERE id = $1", [$id]);
}

function getAllLlmConnectors(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT c.*, b.label AS api_badge_label,
                CASE WHEN COALESCE(b.api_key, '') <> '' THEN TRUE ELSE FALSE END AS has_api_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         ORDER BY c.is_default DESC, c.name ASC"
    );
}

function getLlmConnectorById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key,
                CASE WHEN COALESCE(b.api_key, '') <> '' THEN TRUE ELSE FALSE END AS has_api_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.id = $1",
        [$id]
    );
}

function getDefaultLlmConnector(): array|false {
    $db = $GLOBALS["db"];
    $defaultConnector = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.is_default = TRUE
         ORDER BY c.id ASC
         LIMIT 1"
    );
    if ($defaultConnector) {
        return $defaultConnector;
    }
    return $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         ORDER BY c.id ASC
         LIMIT 1"
    );
}

function stobeNormalizeLlmConnectorTypeForStorage(string $rawType): string {
    $normalized = strtolower(trim($rawType));
    if ($normalized === '') {
        return 'openrouterjson';
    }

    if (function_exists('stobeNormalizeLlmConnectorType')) {
        return stobeNormalizeLlmConnectorType($normalized);
    }

    $aliases = [
        'openrouter' => 'openrouterjson',
        'openrouterjson' => 'openrouterjson',
        'openai' => 'openaijson',
        'openaijson' => 'openaijson',
        'custom' => 'openaijson',
        'google' => 'google_openaijson',
        'google_openaijson' => 'google_openaijson',
        'groq' => 'groqjson',
        'groqjson' => 'groqjson',
        'koboldcpp' => 'koboldcppjson',
        'koboldcppjson' => 'koboldcppjson',
        'player2' => 'player2json',
        'player2json' => 'player2json',
    ];

    return $aliases[$normalized] ?? 'openaijson';
}

function saveLlmConnector(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $name = trim(strval($fields['name'] ?? ''));
    $connectorType = stobeNormalizeLlmConnectorTypeForStorage(strval($fields['connector_type'] ?? 'openrouterjson'));
    $apiBadgeId = null;
    if (array_key_exists('api_badge_id', $fields) && strval($fields['api_badge_id']) !== '') {
        $apiBadgeId = intval($fields['api_badge_id']);
    }
    $apiKey = strval($fields['api_key'] ?? '');
    $baseUrl = strval($fields['base_url'] ?? '');
    $model = strval($fields['model'] ?? '');
    $maxTokens = intval($fields['max_tokens'] ?? 2048);
    $temperature = floatval($fields['temperature'] ?? 0.8);
    $isDefault = coerceBoolean($fields['is_default'] ?? false);
    $configJson = normalizeJsonString($fields['config'] ?? '{}');

    if ($name === '') {
        return 0;
    }

    $savedId = 0;
    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_llm_connector
             SET name = $1,
                 connector_type = $2,
                 api_badge_id = $3,
                 api_key = $4,
                 base_url = $5,
                 model = $6,
                 max_tokens = $7,
                 temperature = $8,
                 is_default = $9,
                 config = $10::jsonb
             WHERE id = $11
             RETURNING id",
            [$name, $connectorType, $apiBadgeId, $apiKey, $baseUrl, $model, $maxTokens, $temperature, $isDefault, $configJson, $id]
        );
        $savedId = $result ? intval($result['id']) : 0;
    } else {
        $result = $db->fetchOne(
            "INSERT INTO core_llm_connector
             (name, connector_type, api_badge_id, api_key, base_url, model, max_tokens, temperature, is_default, config)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10::jsonb)
             RETURNING id",
            [$name, $connectorType, $apiBadgeId, $apiKey, $baseUrl, $model, $maxTokens, $temperature, $isDefault, $configJson]
        );
        $savedId = $result ? intval($result['id']) : 0;
    }

    if ($savedId > 0 && $isDefault) {
        $db->exec(
            "UPDATE core_llm_connector SET is_default = FALSE WHERE id <> $1",
            [$savedId]
        );
    }

    return $savedId;
}

function deleteLlmConnector(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_llm_connector WHERE id = $1", [$id]);
}

function getAllTtsConnectors(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT * FROM core_tts_connector ORDER BY is_default DESC, name ASC"
    );
}

function getTtsConnectorById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         WHERE id = $1
         LIMIT 1",
        [$id]
    );
}

function getDefaultTtsConnector(): array|false {
    $db = $GLOBALS["db"];
    $defaultConnector = $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         WHERE is_default = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($defaultConnector) {
        return $defaultConnector;
    }
    return $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         ORDER BY id ASC
         LIMIT 1"
    );
}

function stobeNormalizeTtsConnectorTypeForStorage(string $rawType): string {
    $normalized = strtolower(trim($rawType));
    if ($normalized === '') {
        return 'pocket_tts';
    }

    $normalized = str_replace('-', '_', $normalized);
    $normalized = preg_replace('/\s+/', '_', $normalized) ?? $normalized;

    return match ($normalized) {
        'pockettts', 'pocketts', 'pocket_tts' => 'pocket_tts',
        'xtts', 'xtts_fastapi' => 'xtts',
        'chatterbox' => 'chatterbox',
        'cartesia' => 'cartesia',
        'inworld' => 'inworld',
        default => 'pocket_tts',
    };
}

function saveTtsConnector(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $name = trim(strval($fields['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $connectorType = stobeNormalizeTtsConnectorTypeForStorage(strval($fields['connector_type'] ?? 'pocket_tts'));
    $baseUrl = trim(strval($fields['base_url'] ?? ''));
    $isDefault = coerceBoolean($fields['is_default'] ?? false);
    $configJson = normalizeJsonString($fields['config'] ?? '{}');

    $savedId = 0;
    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_tts_connector
             SET name = $1,
                 connector_type = $2,
                 base_url = $3,
                 is_default = $4,
                 config = $5::jsonb
             WHERE id = $6
             RETURNING id",
            [$name, $connectorType, $baseUrl, $isDefault, $configJson, $id]
        );
        $savedId = intval($result['id'] ?? 0);
    } else {
        $result = $db->fetchOne(
            "INSERT INTO core_tts_connector
             (name, connector_type, base_url, is_default, config)
             VALUES ($1, $2, $3, $4, $5::jsonb)
             RETURNING id",
            [$name, $connectorType, $baseUrl, $isDefault, $configJson]
        );
        $savedId = intval($result['id'] ?? 0);
    }

    if ($savedId > 0 && $isDefault) {
        $db->exec(
            "UPDATE core_tts_connector
             SET is_default = FALSE
             WHERE id <> $1",
            [$savedId]
        );
    }

    return $savedId;
}

function deleteTtsConnector(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_tts_connector WHERE id = $1", [$id]);
}

function getDefaultPocketTtsConnectorConfig(): array {
    return [
        'language' => 'en',
        'voiceid' => 'malenord',
        'stream_chunk_size' => 20,
        'temperature' => 0.9,
        'speed' => 1.0,
        'length_penalty' => 1.0,
        'repetition_penalty' => 5.0,
        'top_p' => 0.85,
        'top_k' => 50,
        'enable_text_splitting' => true,
    ];
}

function ensurePocketTtsPlaceholderConnectorId(): int {
    $db = $GLOBALS["db"];
    $connectorName = 'Pocket TTS Default';
    $defaultEndpoint = 'http://127.0.0.1:8020';
    $defaultConfig = normalizeJsonString(getDefaultPocketTtsConnectorConfig());
    $existing = $db->fetchOne(
        "SELECT id
         FROM core_tts_connector
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$connectorName]
    );
    $existingId = intval($existing['id'] ?? 0);
    if ($existingId > 0) {
        $db->exec(
            "UPDATE core_tts_connector
              SET connector_type = $2,
                  base_url = CASE
                    WHEN COALESCE(NULLIF(BTRIM(base_url), ''), '') = '' THEN $3
                    ELSE base_url
                  END,
                  is_default = TRUE,
                  config = CASE
                    WHEN config IS NULL OR jsonb_typeof(config) <> 'object' THEN $4::jsonb
                    ELSE $4::jsonb || config
                  END
              WHERE id = $1",
            [$existingId, 'pocket_tts', $defaultEndpoint, $defaultConfig]
        );
        return $existingId;
    }

    $inserted = $db->fetchOne(
        "INSERT INTO core_tts_connector (
            name,
            connector_type,
            base_url,
            is_default,
            config
        ) VALUES (
            $1, $2, $3, TRUE, $4::jsonb
        )
        RETURNING id",
        [$connectorName, 'pocket_tts', $defaultEndpoint, $defaultConfig]
    );
    return intval($inserted['id'] ?? 0);
}

function getDefaultCoreProfileMetadata(): array {
    return [
        'DYNAMIC_PROFILE_ENABLED' => true,
        'MIDDLE_TERM_MEMORY_ENABLED' => true,
        'DIARY_DAYS' => 1,
        'DYNAMIC_PROFILE_FIELDS' => [
            'personality',
            'occupation',
            'speechstyle',
            'goals',
        ],
        'RECHAT_RESPONSES' => 3,
        'RECHAT_PROBABILITY' => 66,
        'DIARY_PROMPT' => "Please write a short summary of #PLAYER_NAME# and #NPC_NAME#'s last dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
        'DIARY_COOLDOWN' => 120,
        'CONTEXT_HISTORY' => 75,
        'CONTEXT_HISTORY_DIARY' => 100,
        'CONTEXT_HISTORY_DYNAMIC_PROFILE' => 50,
        'BORED_EVENT_CHANCE' => 50,
    ];
}

function getDefaultCoreProfileMetadataJson(): string {
    return normalizeJsonString(getDefaultCoreProfileMetadata());
}

function ensureDefaultCoreProfile(): int {
    $db = $GLOBALS["db"];
    // Respect an existing user-selected default profile if one is already set.
    $existingDefault = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE is_default_npc = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    $existingDefaultId = intval($existingDefault['id'] ?? 0);
    if ($existingDefaultId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$existingDefaultId]
        );
        return $existingDefaultId;
    }

    $profileLabel = 'Default Profile';
    $defaultMetadataJson = getDefaultCoreProfileMetadataJson();

    $connectorIdByName = static function (string $name) use ($db): int {
        $row = $db->fetchOne(
            "SELECT id
             FROM core_llm_connector
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
        return intval($row['id'] ?? 0);
    };

    $responseConnectorId = $connectorIdByName('Gemini 2.5 Flash');
    if ($responseConnectorId <= 0) {
        $defaultLlm = getDefaultLlmConnector();
        $responseConnectorId = intval($defaultLlm['id'] ?? 0);
    }
    if ($responseConnectorId <= 0) {
        $responseConnectorId = $connectorIdByName('OpenRouter Default');
    }
    $responseConnectorIdOrNull = $responseConnectorId > 0 ? $responseConnectorId : null;
    $diaryConnectorIdOrNull = $responseConnectorIdOrNull;

    $autochatConnectorId = $connectorIdByName('Gemini 2.5 Flash Lite');
    if ($autochatConnectorId <= 0) {
        $autochatConnectorId = $responseConnectorId;
    }
    $autochatConnectorIdOrNull = $autochatConnectorId > 0 ? $autochatConnectorId : null;

    $memoryConnectorId = $connectorIdByName('Mistral Small 3.2 24B');
    if ($memoryConnectorId <= 0) {
        $memoryConnectorId = $responseConnectorId;
    }
    $memoryConnectorIdOrNull = $memoryConnectorId > 0 ? $memoryConnectorId : null;
    $backgroundlifeConnectorIdOrNull = $memoryConnectorIdOrNull;
    $dynamicConnectorIdOrNull = $responseConnectorIdOrNull;
    $relationshipConnectorIdOrNull = $responseConnectorIdOrNull;

    $ttsConnectorId = ensurePocketTtsPlaceholderConnectorId();
    $ttsConnectorIdOrNull = $ttsConnectorId > 0 ? $ttsConnectorId : null;

    $existingProfile = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE LOWER(label) = LOWER($1)
         LIMIT 1",
        [$profileLabel]
    );
    $profileId = intval($existingProfile['id'] ?? 0);

    if ($profileId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = TRUE,
                 prompt_head = COALESCE(prompt_head, ''),
                 profile_prompt = COALESCE(profile_prompt, ''),
                 response_connector = COALESCE($2::INT, response_connector),
                 diary_connector = COALESCE($3::INT, diary_connector),
                 autochat_connector = COALESCE($4::INT, autochat_connector),
                 middleterm_connector = COALESCE($5::INT, middleterm_connector),
                 backgroundlife_connector = COALESCE($6::INT, backgroundlife_connector),
                 dynamic_connector = COALESCE($7::INT, dynamic_connector),
                 relationship_connector = COALESCE($8::INT, relationship_connector),
                 tts_connector_id = COALESCE($9::INT, tts_connector_id),
                 metadata = CASE
                    WHEN metadata IS NULL
                      OR metadata = '[]'::jsonb
                      OR jsonb_typeof(metadata) <> 'object'
                    THEN $10::jsonb
                    ELSE $10::jsonb || metadata
                 END,
                 updated_at = NOW()
             WHERE id = $1",
            [
                $profileId,
                $responseConnectorIdOrNull,
                $diaryConnectorIdOrNull,
                $autochatConnectorIdOrNull,
                $memoryConnectorIdOrNull,
                $backgroundlifeConnectorIdOrNull,
                $dynamicConnectorIdOrNull,
                $relationshipConnectorIdOrNull,
                $ttsConnectorIdOrNull,
                $defaultMetadataJson
            ]
        );
    } else {
        $insertedProfile = $db->fetchOne(
            "INSERT INTO core_profiles (
                label,
                is_default_npc,
                prompt_head,
                profile_prompt,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
            ) VALUES (
                $1, TRUE, '', '', $2, $3, $4, $5, $6, $7, $8, $9, $10::jsonb
            )
            RETURNING id",
            [
                $profileLabel,
                $responseConnectorIdOrNull,
                $diaryConnectorIdOrNull,
                $autochatConnectorIdOrNull,
                $memoryConnectorIdOrNull,
                $backgroundlifeConnectorIdOrNull,
                $dynamicConnectorIdOrNull,
                $relationshipConnectorIdOrNull,
                $ttsConnectorIdOrNull,
                $defaultMetadataJson
            ]
        );
        $profileId = intval($insertedProfile['id'] ?? 0);
    }

    if ($profileId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$profileId]
        );
        return $profileId;
    }

    $fallbackProfile = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE is_default_npc = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    return intval($fallbackProfile['id'] ?? 0);
}

function getDefaultNpcProfileId(): int {
    static $cachedProfileId = null;

    if (is_int($cachedProfileId) && $cachedProfileId > 0) {
        return $cachedProfileId;
    }

    $profileId = ensureDefaultCoreProfile();
    if ($profileId <= 0) {
        $db = $GLOBALS["db"];
        $fallbackProfile = $db->fetchOne(
            "SELECT id
             FROM core_profiles
             WHERE is_default_npc = TRUE
             ORDER BY id ASC
             LIMIT 1"
        );
        $profileId = intval($fallbackProfile['id'] ?? 0);
    }

    $cachedProfileId = $profileId;
    return $cachedProfileId;
}

function stobeBuildLlmConfigFromConnector(array|false $connector): array {
    if (!$connector) {
        return [
            'api_key' => '',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'openrouter/auto',
            'max_tokens' => 384,
            'temperature' => 0.8,
            'connector_type' => 'openrouterjson',
            'config' => [],
        ];
    }

    $resolvedApiKey = strval($connector['api_badge_key'] ?? '');
    if ($resolvedApiKey === '') {
        $resolvedApiKey = strval($connector['api_key'] ?? '');
    }
    $maxTokens = intval($connector['max_tokens'] ?? 2048);
    if ($maxTokens <= 0) {
        $maxTokens = 2048;
    }

    $baseUrl = trim(strval($connector['base_url'] ?? ''));
    $model = trim(strval($connector['model'] ?? ''));

    $temperature = floatval($connector['temperature'] ?? 0.8);
    if ($temperature < 0.0) {
        $temperature = 0.8;
    }

    $extraConfig = [];
    $rawConfig = $connector['config'] ?? '{}';
    if (is_array($rawConfig)) {
        $extraConfig = $rawConfig;
    } else {
        $decodedConfig = json_decode(strval($rawConfig), true);
        if (is_array($decodedConfig)) {
            $extraConfig = $decodedConfig;
        }
    }

    $connectorType = stobeNormalizeLlmConnectorTypeForStorage(strval($connector['connector_type'] ?? 'openrouterjson'));

    if ($connectorType === 'openrouterjson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://openrouter.ai/api/v1';
        }
        if ($model === '') {
            $model = 'openrouter/auto';
        }
    } elseif ($connectorType === 'google_openaijson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
        }
        if ($model === '') {
            $model = 'gemini-2.5-flash';
        }
    } elseif ($connectorType === 'groqjson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://api.groq.com/openai/v1';
        }
        if ($model === '') {
            $model = 'llama-3.3-70b-versatile';
        }
    } elseif ($connectorType === 'koboldcppjson') {
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:5001/v1';
        }
    } elseif ($connectorType === 'player2json') {
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:4315/v1';
        }
    }

    return [
        'api_key' => $resolvedApiKey,
        'base_url' => $baseUrl,
        'model' => $model,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'connector_type' => $connectorType,
        'config' => $extraConfig,
    ];
}

function stobeResolveNpcProfileId(array|false $npcData): int {
    $profileId = 0;
    if (is_array($npcData)) {
        $profileId = intval($npcData['profile_id'] ?? 0);
    }
    if ($profileId <= 0) {
        $profileId = getDefaultNpcProfileId();
    }
    return $profileId > 0 ? $profileId : 0;
}

function stobeGetProfileMetadataById(int $profileId): array {
    static $metadataCache = [];
    if ($profileId <= 0) {
        return [];
    }
    if (array_key_exists($profileId, $metadataCache)) {
        $cached = $metadataCache[$profileId];
        return is_array($cached) ? $cached : [];
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$profileId]
    );
    if (!$row) {
        $metadataCache[$profileId] = [];
        return [];
    }

    $rawMetadata = $row['metadata'] ?? '{}';
    $metadata = [];
    if (is_array($rawMetadata)) {
        $metadata = $rawMetadata;
    } else {
        $decoded = json_decode(strval($rawMetadata), true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $metadataCache[$profileId] = $metadata;
    return $metadata;
}

function stobeNormalizeSettingKey(string $key): string {
    return strtoupper(trim($key));
}

function stobeGetNpcRuntimeOverrideMap(array|false $npcData): array {
    if (!is_array($npcData)) {
        return [];
    }

    static $overrideCache = [];
    $cacheKey = '';
    $npcId = intval($npcData['id'] ?? 0);
    $npcMd5 = trim(strval($npcData['md5'] ?? ''));
    $npcUpdated = trim(strval($npcData['updated_at'] ?? ($npcData['gamets_last_updated'] ?? '')));
    if ($npcId > 0) {
        $cacheKey = 'id:' . $npcId . '|u:' . $npcUpdated;
    } elseif ($npcMd5 !== '') {
        $cacheKey = 'md5:' . $npcMd5 . '|u:' . $npcUpdated;
    }
    if ($cacheKey !== '' && array_key_exists($cacheKey, $overrideCache)) {
        $cached = $overrideCache[$cacheKey];
        return is_array($cached) ? $cached : [];
    }

    $overrides = [];
    $applyScalarMap = static function (array $input) use (&$overrides): void {
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (!(is_scalar($value) || $value === null)) {
                continue;
            }
            $normalizedKey = stobeNormalizeSettingKey($key);
            if ($normalizedKey === '') {
                continue;
            }
            $overrides[$normalizedKey] = $value;
        }
    };

    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    $applyScalarMap($metadata);

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $nestedOverrides = [];
    if (isset($extended['setting_overrides']) && is_array($extended['setting_overrides'])) {
        $nestedOverrides = $extended['setting_overrides'];
    }
    $applyScalarMap($nestedOverrides);

    $reservedTopLevel = [
        'environment',
        'nearby_actors',
        'nearby_items',
        'points_of_interest',
        'middle_term_memory',
        'setting_overrides',
    ];
    $compatScalarOverrides = [];
    foreach ($extended as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (in_array($key, $reservedTopLevel, true)) {
            continue;
        }
        $compatScalarOverrides[$key] = $value;
    }
    $applyScalarMap($compatScalarOverrides);

    if ($cacheKey !== '') {
        $overrideCache[$cacheKey] = $overrides;
    }
    return $overrides;
}

function stobeReadLayeredSettingRaw(
    array|false $npcData,
    array $preferredKeys,
    string $fallbackSettingId,
    mixed &$valueOut,
    string &$sourceOut
): bool {
    $valueOut = null;
    $sourceOut = '';

    $candidateKeys = [];
    foreach ($preferredKeys as $key) {
        if (!is_string($key)) {
            continue;
        }
        $normalized = stobeNormalizeSettingKey($key);
        if ($normalized === '' || in_array($normalized, $candidateKeys, true)) {
            continue;
        }
        $candidateKeys[] = $normalized;
    }
    $fallbackNormalized = stobeNormalizeSettingKey($fallbackSettingId);
    if ($fallbackNormalized !== '' && !in_array($fallbackNormalized, $candidateKeys, true)) {
        $candidateKeys[] = $fallbackNormalized;
    }

    $npcOverrides = stobeGetNpcRuntimeOverrideMap($npcData);
    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $npcOverrides)) {
            $valueOut = $npcOverrides[$key];
            $sourceOut = 'npc';
            return true;
        }
    }

    $profileMetadata = getCoreProfileMetadataForNpc($npcData);
    $profileLookup = [];
    if (is_array($profileMetadata)) {
        foreach ($profileMetadata as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = stobeNormalizeSettingKey($key);
            if ($normalized === '') {
                continue;
            }
            $profileLookup[$normalized] = $value;
        }
    }
    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $profileLookup)) {
            $valueOut = $profileLookup[$key];
            $sourceOut = 'profile';
            return true;
        }
    }

    $fallbackRaw = trim($fallbackSettingId);
    if ($fallbackRaw !== '') {
        $missingSentinel = '__STOBE_MISSING_SETTING__';
        $globalValue = getSetting($fallbackRaw, $missingSentinel);
        if ($globalValue === $missingSentinel && $fallbackNormalized !== '' && $fallbackNormalized !== $fallbackRaw) {
            $globalValue = getSetting($fallbackNormalized, $missingSentinel);
        }
        if ($globalValue !== $missingSentinel) {
            $valueOut = $globalValue;
            $sourceOut = 'global';
            return true;
        }
    }

    return false;
}

function stobePurposeToProfileConnectorColumn(string $purposeKey): string {
    if ($purposeKey === 'autochat') {
        return 'autochat_connector';
    }
    if ($purposeKey === 'diary') {
        return 'diary_connector';
    }
    if ($purposeKey === 'middleterm') {
        return 'middleterm_connector';
    }
    if ($purposeKey === 'backgroundlife') {
        return 'backgroundlife_connector';
    }
    if ($purposeKey === 'dynamic') {
        return 'dynamic_connector';
    }
    if ($purposeKey === 'relationship') {
        return 'relationship_connector';
    }
    return 'response_connector';
}

function stobeResolveNpcConnectorOverrideId(array|false $npcData, string $purposeKey): int {
    $overrides = stobeGetNpcRuntimeOverrideMap($npcData);
    if (count($overrides) === 0) {
        return 0;
    }

    $prefix = strtoupper(trim($purposeKey));
    if ($prefix === '') {
        $prefix = 'RESPONSE';
    }
    $candidateKeys = [
        $prefix . '_CONNECTOR',
        $prefix . '_CONNECTOR_ID',
    ];
    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $overrides)) {
            continue;
        }
        $parsed = parseIntLike($overrides[$key], 0);
        if ($parsed > 0) {
            return $parsed;
        }
    }
    return 0;
}

function stobeFetchLlmConnectorById(int $connectorId): array|false {
    if ($connectorId <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.id = $1
         LIMIT 1",
        [$connectorId]
    );
    if (!$row || intval($row['id'] ?? 0) <= 0) {
        return false;
    }
    return $row;
}

function getProfileLlmConnectorForNpcByPurpose(array|false $npcData, string $purpose = 'response'): array|false {
    $purposeKey = strtolower(trim($purpose));
    if ($purposeKey === '') {
        $purposeKey = 'response';
    }
    $profileColumn = stobePurposeToProfileConnectorColumn($purposeKey);

    // NPC override layer (highest precedence)
    $overrideConnectorId = stobeResolveNpcConnectorOverrideId($npcData, $purposeKey);
    if ($overrideConnectorId > 0) {
        $overrideConnector = stobeFetchLlmConnectorById($overrideConnectorId);
        if ($overrideConnector) {
            return $overrideConnector;
        }
    }

    $profileId = stobeResolveNpcProfileId($npcData);
    if ($profileId <= 0) {
        return false;
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_profiles p
         LEFT JOIN core_llm_connector c ON c.id = p.{$profileColumn}
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE p.id = $1
         LIMIT 1",
        [$profileId]
    );
    if (!$row || intval($row['id'] ?? 0) <= 0) {
        return false;
    }
    return $row;
}

function getLlmConfigForNpcPurpose(array|false $npcData, string $purpose = 'response'): array {
    $purposeKey = strtolower(trim($purpose));

    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, $purposeKey);
    if ($connector) {
        return stobeBuildLlmConfigFromConnector($connector);
    }

    return stobeBuildLlmConfigFromConnector(getDefaultLlmConnector());
}

function getMemoryTxtaiUrl(): string {
    // Stobe memory config: always use local txtai endpoint.
    return 'http://127.0.0.1:8082';
}

function getMemoryUseText2Vec(): bool {
    // Stobe memory config: text2vec is always enabled.
    return true;
}

function getLlmConfigForNpc(array|false $npcData): array {
    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, 'response');

    if (!$connector) {
        $connector = getDefaultLlmConnector();
    }

    return stobeBuildLlmConfigFromConnector($connector);
}

function normalizeJsonString(mixed $value): string {
    if (is_array($value)) {
        $encodedArray = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encodedArray) ? $encodedArray : '{}';
    }

    $text = trim(strval($value));
    if ($text === '') {
        return '{}';
    }

    $decoded = json_decode($text, true);
    if ($decoded === null && strtolower($text) !== 'null') {
        return '{}';
    }

    if (!is_array($decoded)) {
        return '{}';
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '{}';
}

function normalizeCoreNpcMetadata(mixed $value): array {
    $metadata = [];
    if (is_array($value)) {
        $metadata = $value;
    } elseif (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }

    if (count($metadata) === 0) {
        return [];
    }

    // These are persisted in first-class core_npc columns and do not need
    // duplication in metadata.
    $redundantTopLevel = [
        'name',
        'race',
        'faction',
        'faction_id',
        'factionID',
        'gender',
        'stringid',
        'id',
        'refid',
        'handle',
        'is_player_character',
        'is_animal',
        'is_slave',
        'bounty',
        'tags',
        'appearance',
        'equipment',
        'inventory',
        'skills',
        'speechstyle',
        'goals',
        'personality',
        'backstory',
        'emote_moods',
        'voiceid',
        'world_knowledge_tags',
        'blood',
        'hunger',
        'limbs',
        'medical',
        'stats',
        'nearby',
        'environment',
        'dist',
        'health',
        'source',
        'observer',
        'block',
        'hold',
        'passive',
        'jobs',
        'job_list',
        'ranged',
        'taunt',
        'sneak',
        'resource',
        'medic',
        'inventory',
        'metadata',
        'extended_data',
    ];
    foreach ($redundantTopLevel as $key) {
        if (array_key_exists($key, $metadata)) {
            unset($metadata[$key]);
        }
    }

    $stripNestedCoreFields = static function (array $entry): array {
        $redundantNested = [
            'name',
            'race',
            'faction',
            'faction_id',
            'factionID',
            'gender',
            'equipment',
            'storage_id',
            'stringid',
            'id',
            'refid',
            'handle',
        ];
        foreach ($redundantNested as $key) {
            if (array_key_exists($key, $entry)) {
                unset($entry[$key]);
            }
        }
        return $entry;
    };

    foreach (['nearby_snapshot', 'entry'] as $nestedKey) {
        if (!isset($metadata[$nestedKey]) || !is_array($metadata[$nestedKey])) {
            continue;
        }
        $metadata[$nestedKey] = $stripNestedCoreFields($metadata[$nestedKey]);
        unset($metadata[$nestedKey]);
    }

    if (array_key_exists('storage_id', $metadata)) {
        $normalizedStorageId = normalizeStorageIdToken($metadata['storage_id']);
        if ($normalizedStorageId === '') {
            unset($metadata['storage_id']);
        } else {
            $metadata['storage_id'] = $normalizedStorageId;
        }
    }

    return $metadata;
}

function normalizeCoreNpcExtendedData(mixed $value): array {
    $extended = [];
    if (is_array($value)) {
        $extended = $value;
    } elseif (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $extended = $decoded;
        }
    }

    if (count($extended) === 0) {
        return [];
    }

    $extractEnvironmentFields = static function (array $candidate): array {
        $allowedKeys = [
            'indoors',
            'outdoors',
            'in_town',
            'building_serial',
            'building_id',
            'indoors_serial',
            'building_name',
            'indoors_name',
            'town_name',
            'town',
            'floor',
            'floor_num',
            'current_floor',
            'region',
            'zone',
            'zone_name',
            'weather',
            'x',
            'y',
            'z',
        ];
        $environmentSubset = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }
            $environmentSubset[$key] = $candidate[$key];
        }
        return $environmentSubset;
    };

    $extractSceneRows = static function (
        mixed $candidate,
        array $allowedKeys,
        int $maxRows = 24
    ): array {
        $rows = [];
        $decoded = [];
        if (is_array($candidate)) {
            $decoded = $candidate;
        } elseif (is_string($candidate)) {
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }
        if (count($decoded) === 0) {
            return [];
        }

        $seen = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) && !is_string($entry)) {
                continue;
            }

            if (is_string($entry)) {
                $name = trim($entry);
                if ($name === '') {
                    continue;
                }
                $entry = ['name' => $name];
            }

            $clean = [];
            foreach ($allowedKeys as $key) {
                if (!array_key_exists($key, $entry)) {
                    continue;
                }
                $clean[$key] = $entry[$key];
            }
            if (count($clean) === 0) {
                continue;
            }

            $dedupe = '';
            if (array_key_exists('refid', $clean)) {
                $dedupe = strtolower(trim(strval($clean['refid'])));
            }
            if ($dedupe === '' && array_key_exists('name', $clean)) {
                $dedupe = strtolower(trim(strval($clean['name'])));
            }
            if ($dedupe === '') {
                $dedupe = md5(json_encode($clean));
            }
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $rows[] = $clean;
            if (count($rows) >= $maxRows) {
                break;
            }
        }
        return $rows;
    };

    $environment = [];
    if (isset($extended['environment']) && is_array($extended['environment'])) {
        $environment = $extractEnvironmentFields($extended['environment']);
    }
    if (count($environment) === 0) {
        foreach (['nearby_snapshot', 'entry'] as $candidateKey) {
            if (!isset($extended[$candidateKey]) || !is_array($extended[$candidateKey])) {
                continue;
            }
            $nested = $extended[$candidateKey];
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $environment = $extractEnvironmentFields($nested['environment']);
                break;
            }
            $environment = $extractEnvironmentFields($nested);
            break;
        }
    }

    $nearbyActors = $extractSceneRows(
        $extended['nearby_actors'] ?? ($extended['nearby'] ?? []),
        [
            'name',
            'race',
            'gender',
            'faction',
            'equipment',
            'current_action',
            'drunk_level',
            'is_drunk',
            'drunk_status',
            'drunk_seconds_remaining',
            'is_high',
            'high_status',
            'high_seconds_remaining',
            'high_hunger_rate_multiplier',
            'dist',
            'is_player_character',
            'is_animal',
        ],
        32
    );

    $nearbyItems = $extractSceneRows(
        $extended['nearby_items'] ?? [],
        ['name', 'refid', 'dist', 'quantity'],
        40
    );

    $pointsOfInterest = $extractSceneRows(
        $extended['points_of_interest'] ?? [],
        ['name', 'type', 'kind', 'location', 'dist'],
        32
    );
    $traderShopSources = $extractSceneRows(
        $extended['trader_shop_sources'] ?? [],
        ['name', 'serial', 'dist', 'building_class', 'special_function', 'inventory_item_count'],
        8
    );
    $traderShopSourceCount = parseIntLike(
        $extended['trader_shop_source_count'] ?? count($traderShopSources),
        count($traderShopSources)
    );
    if ($traderShopSourceCount < count($traderShopSources)) {
        $traderShopSourceCount = count($traderShopSources);
    }
    $traderShopItemCount = parseIntLike(
        $extended['trader_shop_item_count'] ?? 0,
        0
    );
    if ($traderShopItemCount < 0) {
        $traderShopItemCount = 0;
    }
    foreach ($traderShopSources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $sourceItemCount = parseIntLike($source['inventory_item_count'] ?? 0, 0);
        if ($sourceItemCount > 0) {
            $traderShopItemCount += $sourceItemCount;
        }
    }

    $redundantTopLevel = [
        'stats',
        'medical',
        'nearby',
        'inventory',
        'limbs',
        'blood',
        'hunger',
        'skills',
        'equipment',
        'metadata',
        'nearby_snapshot',
        'entry',
    ];
    foreach ($redundantTopLevel as $key) {
        if (array_key_exists($key, $extended)) {
            unset($extended[$key]);
        }
    }

    if (is_array($environment) && count($environment) > 0) {
        $extended['environment'] = $environment;
    } elseif (array_key_exists('environment', $extended)) {
        unset($extended['environment']);
    }

    if (count($nearbyActors) > 0) {
        $extended['nearby_actors'] = $nearbyActors;
    } elseif (array_key_exists('nearby_actors', $extended)) {
        unset($extended['nearby_actors']);
    }

    if (count($nearbyItems) > 0) {
        $extended['nearby_items'] = $nearbyItems;
    } elseif (array_key_exists('nearby_items', $extended)) {
        unset($extended['nearby_items']);
    }

    if (count($pointsOfInterest) > 0) {
        $extended['points_of_interest'] = $pointsOfInterest;
    } elseif (array_key_exists('points_of_interest', $extended)) {
        unset($extended['points_of_interest']);
    }

    if (count($traderShopSources) > 0) {
        $extended['trader_shop_sources'] = $traderShopSources;
        $extended['trader_shop_source_count'] = $traderShopSourceCount;
        $extended['trader_shop_item_count'] = $traderShopItemCount;
    } else {
        foreach (['trader_shop_sources', 'trader_shop_source_count', 'trader_shop_item_count'] as $key) {
            if (array_key_exists($key, $extended)) {
                unset($extended[$key]);
            }
        }
    }

    return $extended;
}

function coerceBoolean(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'enabled', 'checked'], true);
}

function sanitizeForKenshi(string $text): string {
    $replacements = [
        "\u{2018}" => "'", "\u{2019}" => "'",
        "\u{201c}" => '"', "\u{201d}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-',
        "\u{2026}" => '...',
        "\u{00a0}" => ' ',
    ];
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    $text = str_replace(["\r\n", "\\n", "\\r"], ["\n", "\n", ""], $text);
    return $text;
}

function parseIntLike(mixed $value, int $fallback): int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return intval($value);
    }
    if (is_string($value)) {
        $normalized = trim($value);
        if ($normalized === '') {
            return $fallback;
        }
        if (substr($normalized, -1) === '%') {
            $normalized = rtrim(substr($normalized, 0, -1));
        }
        if (preg_match('/^-?[0-9]+$/', $normalized) === 1) {
            return intval($normalized);
        }
    }
    return $fallback;
}

function getCoreProfileMetadataForNpc(array|false $npcData): array {
    $profileId = stobeResolveNpcProfileId($npcData);
    if ($profileId <= 0) {
        return [];
    }
    return stobeGetProfileMetadataById($profileId);
}

function getNpcProfileIntegerSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    int $default,
    int $min,
    int $max
): int {
    $resolvedRaw = null;
    $source = '';
    $value = $default;
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        $value = parseIntLike($resolvedRaw, $default);
    }

    if ($value < $min) {
        $value = $min;
    }
    if ($value > $max) {
        $value = $max;
    }
    return $value;
}

function getNpcProfileBoolSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    bool $default
): bool {
    $resolvedRaw = null;
    $source = '';
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        return coerceBoolean($resolvedRaw);
    }
    return $default;
}

function getNpcProfileStringSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    string $default
): string {
    $resolvedRaw = null;
    $source = '';
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        if (is_scalar($resolvedRaw) || $resolvedRaw === null) {
            return strval($resolvedRaw ?? '');
        }
    }
    return $default;
}

function stobeGetNpcLayeredStringSetting(
    array|false $npcData,
    array $keys,
    string $fallbackSettingId,
    string $default = ''
): string {
    return getNpcProfileStringSetting($npcData, $keys, $fallbackSettingId, $default);
}

function DataRechatHistory(string $campaignFilter = '', int $windowSeconds = 120, int $limit = 10): array {
    $db = $GLOBALS["db"];

    $window = $windowSeconds;
    if ($window < 10) {
        $window = 10;
    } elseif ($window > 3600) {
        $window = 3600;
    }

    $safeLimit = $limit;
    if ($safeLimit < 1) {
        $safeLimit = 1;
    } elseif ($safeLimit > 50) {
        $safeLimit = 50;
    }

    return $db->fetchAll(
        "SELECT gamets
         FROM eventlog
         WHERE type IN ('rechat')
           AND localts > $1
         ORDER BY gamets DESC, ts DESC
         LIMIT " . intval($safeLimit),
        [time() - $window]
    );
}

function stobeNpcIsIncapacitatedForRechat(array $npcData): bool {
    $state = strtolower(trim(strval($npcData['character_state'] ?? '')));
    if (in_array($state, ['dead', 'unconscious', 'ko', 'knockedout', 'knocked_out', 'incapacitated'], true)) {
        return true;
    }

    $metadataRaw = $npcData['metadata'] ?? [];
    $metadata = [];
    if (is_array($metadataRaw)) {
        $metadata = $metadataRaw;
    } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
        $decoded = json_decode($metadataRaw, true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    if (is_array($metadata)) {
        $metaState = strtolower(trim(strval($metadata['character_state'] ?? '')));
        if (in_array($metaState, ['dead', 'unconscious', 'ko', 'knockedout', 'knocked_out', 'incapacitated'], true)) {
            return true;
        }
        $medical = $metadata['medical'] ?? null;
        if (is_array($medical) && !empty($medical['is_unconscious'])) {
            return true;
        }
    }

    $extendedRaw = $npcData['extended_data'] ?? [];
    $extended = [];
    if (is_array($extendedRaw)) {
        $extended = $extendedRaw;
    } elseif (is_string($extendedRaw) && trim($extendedRaw) !== '') {
        $decoded = json_decode($extendedRaw, true);
        if (is_array($decoded)) {
            $extended = $decoded;
        }
    }
    if (is_array($extended)) {
        $medical = $extended['medical'] ?? null;
        if (is_array($medical) && !empty($medical['is_unconscious'])) {
            return true;
        }
    }

    return false;
}

function isRechatEligible(array|false $npcData = false, string $campaignFilter = '', int $requestedDepth = 0): bool {
    if (is_array($npcData) && stobeNpcIsIncapacitatedForRechat($npcData)) {
        return false;
    }
    $maxRounds = getNpcProfileIntegerSetting(
        $npcData,
        ['RECHAT_RESPONSES'],
        '',
        3,
        1,
        12
    );

    // New clients provide explicit chain depth so limits are scoped per
    // conversation chain rather than by global recent rechat traffic.
    if ($requestedDepth > 0) {
        return $requestedDepth <= $maxRounds;
    }

    // Legacy fallback: if depth is unknown, keep the previous global
    // short-window guard to avoid unbounded loops.
    $rechatHistory = DataRechatHistory($campaignFilter, 120, 10);
    if (count($rechatHistory) >= $maxRounds) {
        return false;
    }
    return true;
}

