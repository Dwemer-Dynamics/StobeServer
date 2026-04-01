<?php

/**
 * Rechat processor - NPC-to-NPC dialogue continuation.
 */

$normalizedEventType = strtolower(trim(strval($eventType)));
$normalizeDialogueSignature = static function (string $rawEventData): string {
    $sanitized = trim($rawEventData);
    // Some client echoes append a stray numeric token after "(talking to: ...)".
    $sanitized = preg_replace('/(\(talking to:\s*[^\)]+\))\s*\d+\s*$/i', '$1', $sanitized) ?? $sanitized;
    $parsed = parseDialogueEventData($sanitized);
    $speaker = strtolower(trim(strval($parsed['speaker'] ?? '')));
    $target = strtolower(trim(strval($parsed['target'] ?? '')));
    $message = strtolower(trim(preg_replace('/\s+/', ' ', strval($parsed['message'] ?? '')) ?? ''));
    if ($speaker === '' && $target === '' && $message === '') {
        return '';
    }
    return $speaker . '|' . $target . '|' . $message;
};
$normalizedEventData = trim($eventData);
$normalizedEventData = preg_replace('/(\(talking to:\s*[^\)]+\))\s*\d+\s*$/i', '$1', $normalizedEventData) ?? $normalizedEventData;
if ($normalizedEventData !== trim($eventData)) {
    stobeLogDebug('Rechat incoming payload sanitized', [
        'event_type' => $eventType,
        'before' => substr($eventData, 0, 180),
        'after' => substr($normalizedEventData, 0, 180),
    ]);
    $eventData = $normalizedEventData;
}

$storeIncomingEvent = $normalizedEventType !== 'rechat';
if (!$storeIncomingEvent) {
    // `rechat` requests are trigger echoes for the *previous* spoken line.
    // Persisting them causes duplicate chat/rechat rows in eventlog.
    $currentSignature = $normalizeDialogueSignature($eventData);
    stobeLogDebug('Rechat incoming trigger skipped (no eventlog write)', [
        'event_type' => $eventType,
        'gamets' => intval($gamets),
        'signature' => substr($currentSignature, 0, 180),
    ]);
} else {
    storeEvent($eventType, $timestamp, $gamets, $eventData);
}

$campaign = 'Default';
$requestMode = strtolower(trim(strval($_GET['mode'] ?? '')));
if ($requestMode === 'whisper' || $requestMode === 'narrator') {
    stobeLogInfo('Rechat skipped: private mode', [
        'event_type' => $eventType,
        'mode' => $requestMode,
    ]);
    echo "ok";
    return;
}
$playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$speakerRechatEnabled = getSettingBool('SPEAKER_RECHAT', false);
$incomingProfile = normalizeParticipantNameToken(trim(strval($_GET['profile'] ?? '')));
$peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));
$requestedDepthRaw = intval($_GET['rechat_depth'] ?? 0);
$requestedDepth = $requestedDepthRaw > 0 ? $requestedDepthRaw : 0;
$initiatorIdentity = extractParticipantIdentityToken(strval($_GET['initiator'] ?? ''));
$initiatorName = normalizeParticipantNameToken(strval($initiatorIdentity['name'] ?? ''));
$initiatorStorageId = normalizeStorageIdToken(
    strval($_GET['initiator_sid'] ?? ($initiatorIdentity['storage_id'] ?? ''))
);

$dialogueData = parseDialogueEventData($eventData);
$previousSpeaker = normalizeParticipantNameToken(strval($dialogueData['speaker'] ?? ''));
$previousMessage = trim(strval($dialogueData['message'] ?? ''));
$previousTarget = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));
if ($previousSpeaker === '') {
    $previousSpeaker = $incomingProfile;
}

if ($previousSpeaker === '') {
    stobeLogInfo('Rechat skipped: previous speaker missing', [
        'event_type' => $eventType,
        'data_preview' => substr($eventData, 0, 120),
    ]);
    echo "ok";
    return;
}
if (stobeIsNarratorName($previousSpeaker)) {
    stobeLogInfo('Rechat skipped: narrator speaker', [
        'event_type' => $eventType,
        'speaker' => $previousSpeaker,
    ]);
    echo "ok";
    return;
}

if (stobeTryTriggerRandomNarration(
    intval($gamets),
    $previousSpeaker,
    $previousMessage,
    $previousTarget,
    'rechat',
    $playerName,
    intval($timestamp)
)) {
    echo "ok";
    return;
}

$toBool = static function (mixed $value, bool $default = false): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return floatval($value) != 0.0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
    }
    return $default;
};

$decodeJsonArray = static function (mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return [];
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : [];
};

$extractEnvironment = static function (array|false $npcData, string $fallbackName = '') use ($decodeJsonArray, $toBool): array {
    $npcRow = is_array($npcData) ? $npcData : [];
    $name = normalizeParticipantNameToken(strval($npcRow['name'] ?? $fallbackName));
    $extendedData = $decodeJsonArray($npcRow['extended_data'] ?? []);
    $environment = [];
    if (isset($extendedData['environment']) && is_array($extendedData['environment'])) {
        $environment = $extendedData['environment'];
    }
    if (!is_array($environment) || count($environment) === 0) {
        $candidateKeys = ['nearby_snapshot', 'entry'];
        foreach ($candidateKeys as $candidateKey) {
            if (!isset($extendedData[$candidateKey]) || !is_array($extendedData[$candidateKey])) {
                continue;
            }
            $nested = $extendedData[$candidateKey];
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $environment = $nested['environment'];
                break;
            }
            $environment = $nested;
            break;
        }
    }
    if (!is_array($environment)) {
        $environment = [];
    }

    $indoorsKnown = array_key_exists('indoors', $environment) || array_key_exists('outdoors', $environment);
    $indoors = false;
    if (array_key_exists('indoors', $environment)) {
        $indoors = $toBool($environment['indoors'], false);
    } elseif (array_key_exists('outdoors', $environment)) {
        $indoors = !$toBool($environment['outdoors'], true);
    }

    $buildingSerial = '';
    foreach (['building_serial', 'building_id', 'indoors_serial'] as $serialKey) {
        if (!array_key_exists($serialKey, $environment)) {
            continue;
        }
        $candidateSerial = trim(strval($environment[$serialKey]));
        if ($candidateSerial !== '' && $candidateSerial !== '0') {
            $buildingSerial = $candidateSerial;
            break;
        }
    }

    $buildingName = '';
    foreach (['building_name', 'indoors_name'] as $nameKey) {
        if (!array_key_exists($nameKey, $environment)) {
            continue;
        }
        $candidateName = trim(strval($environment[$nameKey]));
        if ($candidateName !== '') {
            $buildingName = $candidateName;
            break;
        }
    }

    $townName = '';
    foreach (['town_name', 'town'] as $townKey) {
        if (!array_key_exists($townKey, $environment)) {
            continue;
        }
        $candidateTown = trim(strval($environment[$townKey]));
        if ($candidateTown !== '') {
            $townName = $candidateTown;
            break;
        }
    }

    $floorKnown = false;
    $floor = 0;
    foreach (['floor', 'floor_num', 'current_floor'] as $floorKey) {
        if (!array_key_exists($floorKey, $environment)) {
            continue;
        }
        $rawFloor = $environment[$floorKey];
        if (is_int($rawFloor) || is_float($rawFloor) || (is_string($rawFloor) && preg_match('/^-?[0-9]+$/', trim($rawFloor)) === 1)) {
            $floor = intval($rawFloor);
            $floorKnown = true;
            break;
        }
    }

    $known = $indoorsKnown || $buildingSerial !== '' || $buildingName !== '' || $townName !== '';

    return [
        'name' => $name,
        'known' => $known,
        'indoors' => $indoors,
        'building_serial' => $buildingSerial,
        'building_name' => $buildingName,
        'floor_known' => $floorKnown,
        'floor' => $floor,
        'town_name' => $townName,
    ];
};

$isEnvironmentCompatible = static function (array $speakerEnv, array $candidateEnv): array {
    if (!boolval($speakerEnv['known'] ?? false)) {
        return ['ok' => true, 'reason' => 'speaker_unknown'];
    }
    if (!boolval($candidateEnv['known'] ?? false)) {
        return ['ok' => false, 'reason' => 'candidate_unknown'];
    }

    $speakerIndoors = boolval($speakerEnv['indoors'] ?? false);
    $candidateIndoors = boolval($candidateEnv['indoors'] ?? false);
    if ($speakerIndoors) {
        if (!$candidateIndoors) {
            return ['ok' => false, 'reason' => 'speaker_indoors_candidate_outdoors'];
        }
        $speakerSerial = trim(strval($speakerEnv['building_serial'] ?? ''));
        $candidateSerial = trim(strval($candidateEnv['building_serial'] ?? ''));
        if ($speakerSerial !== '' && $candidateSerial !== '') {
            if ($speakerSerial === $candidateSerial) {
                $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
                $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
                if ($speakerFloorKnown && $candidateFloorKnown) {
                    $speakerFloor = intval($speakerEnv['floor'] ?? 0);
                    $candidateFloor = intval($candidateEnv['floor'] ?? 0);
                    if ($speakerFloor !== $candidateFloor) {
                        return ['ok' => false, 'reason' => 'different_floor'];
                    }
                    return ['ok' => true, 'reason' => 'same_building_same_floor'];
                }
                return ['ok' => true, 'reason' => 'same_building_serial_floor_unknown'];
            }
            return ['ok' => false, 'reason' => 'different_building_serial'];
        }
        $speakerBuildingName = strtolower(trim(strval($speakerEnv['building_name'] ?? '')));
        $candidateBuildingName = strtolower(trim(strval($candidateEnv['building_name'] ?? '')));
        if ($speakerBuildingName !== '' && $candidateBuildingName !== '') {
            if ($speakerBuildingName === $candidateBuildingName) {
                $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
                $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
                if ($speakerFloorKnown && $candidateFloorKnown) {
                    $speakerFloor = intval($speakerEnv['floor'] ?? 0);
                    $candidateFloor = intval($candidateEnv['floor'] ?? 0);
                    if ($speakerFloor !== $candidateFloor) {
                        return ['ok' => false, 'reason' => 'different_floor'];
                    }
                    return ['ok' => true, 'reason' => 'same_building_same_floor'];
                }
                return ['ok' => true, 'reason' => 'same_building_name_floor_unknown'];
            }
            return ['ok' => false, 'reason' => 'different_building_name'];
        }
        return ['ok' => false, 'reason' => 'missing_building_identity'];
    }

    if ($candidateIndoors) {
        return ['ok' => false, 'reason' => 'speaker_outdoors_candidate_indoors'];
    }

    $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
    $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
    if ($speakerFloorKnown && $candidateFloorKnown) {
        $speakerFloor = intval($speakerEnv['floor'] ?? 0);
        $candidateFloor = intval($candidateEnv['floor'] ?? 0);
        if ($candidateFloor > $speakerFloor) {
            return ['ok' => false, 'reason' => 'speaker_outdoors_candidate_higher_floor'];
        }
    }

    return ['ok' => true, 'reason' => 'both_outdoors'];
};

$participants = extractParticipantIdentities([
    'people' => $peopleRaw,
    'profile' => $incomingProfile,
    'speaker' => $previousSpeaker,
]);

$conversationScopeNames = [];
$pushConversationScopeName = static function (string $rawName) use (&$conversationScopeNames): void {
    $name = normalizeParticipantNameToken($rawName);
    if ($name === '') {
        return;
    }
    $conversationScopeNames[strtolower($name)] = $name;
};
foreach ($participants as $participant) {
    if (is_array($participant)) {
        $pushConversationScopeName(strval($participant['name'] ?? ''));
    } elseif (is_string($participant)) {
        $parsedIdentity = extractParticipantIdentityToken($participant);
        $pushConversationScopeName(strval($parsedIdentity['name'] ?? ''));
    }
}
$pushConversationScopeName($previousSpeaker);
$pushConversationScopeName($previousTarget);
$pushConversationScopeName($incomingProfile);
$pushConversationScopeName($initiatorName);

$forcedLimbLossReaction = stobeResolvePendingLimbLossRechatReaction(array_values($conversationScopeNames), 300);
$forcedResponder = normalizeParticipantNameToken(strval($forcedLimbLossReaction['victim'] ?? ''));
if ($forcedResponder !== '') {
    if (($playerName !== '' && strcasecmp($forcedResponder, $playerName) === 0) || stobeIsNarratorName($forcedResponder)) {
        $forcedLimbLossReaction = [];
        $forcedResponder = '';
    }
}
if ($forcedResponder !== '') {
    stobeLogInfo('Rechat pending forced limb-loss reaction detected', [
        'rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'victim' => $forcedResponder,
        'attacker' => strval($forcedLimbLossReaction['attacker'] ?? ''),
        'limb' => strval($forcedLimbLossReaction['limb'] ?? ''),
        'hacksaw' => boolval($forcedLimbLossReaction['hacksaw'] ?? false),
    ]);
}

$candidateNames = [];
$seen = [];
$pushCandidate = static function (array $candidate) use (&$candidateNames, &$seen, $previousSpeaker, $playerName, $speakerRechatEnabled, $initiatorName, $initiatorStorageId): void {
    $name = normalizeParticipantNameToken(strval($candidate['name'] ?? ''));
    $storageId = normalizeStorageIdToken(strval($candidate['storage_id'] ?? ''));
    if ($name === '') {
        return;
    }
    if (strcasecmp($name, $previousSpeaker) === 0) {
        return;
    }
    if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
        return;
    }
    if (!$speakerRechatEnabled) {
        $matchesInitiator = false;
        if ($initiatorStorageId !== '' && $storageId !== '') {
            $matchesInitiator = (strcasecmp($storageId, $initiatorStorageId) === 0);
        } elseif ($initiatorName !== '') {
            $matchesInitiator = (strcasecmp($name, $initiatorName) === 0);
        }
        if ($matchesInitiator) {
            return;
        }
    }
    $key = strtolower($name);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $candidateNames[] = $name;
};

if (count($participants) > 1) {
    shuffle($participants);
}
foreach ($participants as $participant) {
    if (is_array($participant)) {
        $pushCandidate($participant);
    } elseif (is_string($participant)) {
        $pushCandidate(extractParticipantIdentityToken($participant));
    }
}

if ($forcedResponder !== '') {
    $forcedKey = strtolower($forcedResponder);
    if (!isset($seen[$forcedKey])) {
        $seen[$forcedKey] = true;
        $candidateNames[] = $forcedResponder;
    }
}

if (count($candidateNames) === 0) {
    stobeLogInfo('Rechat skipped: no eligible NPC candidates', [
        'previous_speaker' => $previousSpeaker,
        'player_name' => $playerName,
        'speaker_rechat' => $speakerRechatEnabled ? 'true' : 'false',
        'initiator_name' => $initiatorName,
        'initiator_sid' => $initiatorStorageId,
    ]);
    echo "ok";
    return;
}

$speakerNpcData = getNpcData($previousSpeaker);
$speakerEnvironment = $extractEnvironment($speakerNpcData, $previousSpeaker);
$maxRoundsForDepth = getNpcProfileIntegerSetting(
    $speakerNpcData,
    ['RECHAT_RESPONSES'],
    '',
    3,
    1,
    12
);
if ($requestedDepth > 0 && $requestedDepth > $maxRoundsForDepth) {
    stobeLogInfo('Rechat skipped: depth limit reached', [
        'previous_speaker' => $previousSpeaker,
        'requested_depth' => $requestedDepth,
        'max_rounds' => $maxRoundsForDepth,
    ]);
    echo "ok";
    return;
}

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

$candidateMap = [];
foreach ($candidateNames as $candidateName) {
    $candidateMap[strtolower($candidateName)] = $candidateName;
}

$fallbackOrder = $candidateNames;
if (count($fallbackOrder) > 1) {
    shuffle($fallbackOrder);
}
$fallbackPair = array_slice($fallbackOrder, 0, min(2, count($fallbackOrder)));

$selectionSource = 'fallback_random';
$selectedPair = $fallbackPair;
$selectorRaw = '';
$selectorError = '';

$selectorNpcData = getNpcData($previousSpeaker);
if (!$selectorNpcData && count($candidateNames) > 0) {
    $selectorNpcData = getNpcData($candidateNames[0]);
}

if (is_array($selectorNpcData)) {
    $selectorConfig = getLlmConfigForNpc($selectorNpcData);
    $selectorApiKey = trim(strval($selectorConfig['api_key'] ?? ''));

    if ($selectorApiKey !== '') {
        $selectorSystemPrompt = "<rechat_target_selector>\n"
            . "  <rule>Select the next rechat responders in a Kenshi NPC conversation.</rule>\n"
            . "  <rule>Choose exactly two names from the candidate list.</rule>\n"
            . "  <rule>Return strict JSON only in this shape: {\"targets\":[\"Name A\",\"Name B\"]}.</rule>\n"
            . "  <rule>Do not add extra keys or prose.</rule>\n"
            . "</rechat_target_selector>";

        $selectorUserPrompt = "<rechat_target_selector_input>\n"
            . "  <previous_speaker>" . stobePromptXmlEscape($previousSpeaker) . "</previous_speaker>\n"
            . "  <previous_message>" . stobePromptXmlEscape($previousMessage) . "</previous_message>\n"
            . "  <previous_target>" . stobePromptXmlEscape($previousTarget !== '' ? $previousTarget : '(none)') . "</previous_target>\n"
            . "  <candidates>" . stobePromptXmlEscape(implode(', ', $candidateNames)) . "</candidates>\n"
            . "</rechat_target_selector_input>";

        $selectorMessages = [
            [
                'role' => 'system',
                'content' => $selectorSystemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $selectorUserPrompt,
            ],
        ];

        $selectorRawResponse = stobeCallLLM($selectorMessages, $selectorConfig, [
            'npc_name' => $previousSpeaker !== '' ? $previousSpeaker : (strval($selectorNpcData['name'] ?? 'selector')),
            'event_type' => 'rechat_target_picker',
        ]);

        if ($selectorRawResponse !== false && trim($selectorRawResponse) !== '') {
            $selectorRaw = trim(strval($selectorRawResponse));
            $pickedNames = [];
            $pickedSet = [];

            $pushPicked = static function (string $name) use (&$pickedNames, &$pickedSet, $candidateMap): void {
                if (count($pickedNames) >= 2) {
                    return;
                }
                $normalized = normalizeParticipantNameToken($name);
                if ($normalized === '') {
                    return;
                }
                $key = strtolower($normalized);
                if (!isset($candidateMap[$key])) {
                    return;
                }
                if (isset($pickedSet[$key])) {
                    return;
                }
                $pickedSet[$key] = true;
                $pickedNames[] = $candidateMap[$key];
            };

            $decoded = json_decode($selectorRaw, true);
            if (is_array($decoded)) {
                $rawTargets = [];
                if (isset($decoded['targets'])) {
                    $rawTargets = $decoded['targets'];
                } elseif (isset($decoded['target'])) {
                    $rawTargets = $decoded['target'];
                }

                if (is_string($rawTargets)) {
                    $rawTargets = preg_split('/[,;\n]+/', $rawTargets);
                }

                if (is_array($rawTargets)) {
                    foreach ($rawTargets as $rawTarget) {
                        $pushPicked(strval($rawTarget));
                    }
                }
            }

            if (count($pickedNames) < 2) {
                foreach ($candidateNames as $candidateName) {
                    $pattern = '/\b' . preg_quote($candidateName, '/') . '\b/i';
                    if (preg_match($pattern, $selectorRaw) === 1) {
                        $pushPicked($candidateName);
                    }
                }
            }

            if (count($pickedNames) > 0) {
                if (count($pickedNames) < 2) {
                    foreach ($fallbackPair as $fallbackName) {
                        $pushPicked($fallbackName);
                    }
                }
                $selectedPair = array_slice($pickedNames, 0, min(2, count($pickedNames)));
                $selectionSource = 'ai';
            } else {
                $selectorError = 'unparsed_selector_output';
            }
        } else {
            $selectorError = 'empty_selector_output';
        }
    } else {
        $selectorError = 'missing_selector_api_key';
    }
} else {
    $selectorError = 'missing_selector_profile';
}

$normalSelectedPair = $selectedPair;
$normalSelectionSource = $selectionSource;
$forcedReactionActive = ($forcedResponder !== '');
if ($forcedReactionActive) {
    $selectedPair = [$forcedResponder];
    $selectionSource = 'forced_limb_loss';
}

stobeLogInfo('Rechat target pair selected', [
    'source' => $selectionSource,
    'pair' => $selectedPair,
    'normal_pair' => $normalSelectedPair,
    'candidate_count' => count($candidateNames),
    'previous_speaker' => $previousSpeaker,
    'speaker_environment' => $speakerEnvironment,
    'selector_error' => $selectorError,
    'forced_limb_loss_rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
    'forced_limb_loss_victim' => strval($forcedResponder),
]);

$selectResponderFromPool = static function (array $candidatePool) use (
    $extractEnvironment,
    $isEnvironmentCompatible,
    $speakerEnvironment,
    $campaign,
    $requestedDepth
): array {
    $respondingNpc = '';
    $npcData = false;
    $skipCounts = [
        'missing_data' => 0,
        'location_mismatch' => 0,
        'incapacitated' => 0,
        'ineligible' => 0,
    ];
    $skipSamples = [];

    $pool = $candidatePool;
    if (count($pool) > 1) {
        shuffle($pool);
    }

    foreach ($pool as $candidateName) {
        $candidateData = getNpcData($candidateName);
        if (!$candidateData) {
            storeNpcProfile($candidateName, []);
            $candidateData = getNpcData($candidateName);
        } elseif (npcNeedsBootstrap($candidateData)) {
            storeNpcProfile($candidateName, []);
            $candidateData = getNpcData($candidateName) ?: $candidateData;
        }

        if (!$candidateData) {
            $skipCounts['missing_data']++;
            if (count($skipSamples) < 5) {
                $skipSamples[] = $candidateName . ':missing_data';
            }
            continue;
        }

        if (stobeNpcIsIncapacitatedForRechat($candidateData)) {
            $skipCounts['incapacitated']++;
            if (count($skipSamples) < 5) {
                $stateLabel = strtolower(trim(strval($candidateData['character_state'] ?? 'unknown')));
                if ($stateLabel === '') {
                    $stateLabel = 'unknown';
                }
                $skipSamples[] = $candidateName . ':incapacitated_' . $stateLabel;
            }
            stobeLogDebug('Rechat candidate skipped: incapacitated', [
                'candidate' => $candidateName,
                'character_state' => strval($candidateData['character_state'] ?? ''),
            ]);
            continue;
        }

        $candidateEnvironment = $extractEnvironment($candidateData, $candidateName);
        $environmentGate = $isEnvironmentCompatible($speakerEnvironment, $candidateEnvironment);
        if (!boolval($environmentGate['ok'] ?? false)) {
            $skipCounts['location_mismatch']++;
            if (count($skipSamples) < 5) {
                $skipSamples[] = $candidateName . ':location_' . strval($environmentGate['reason'] ?? 'mismatch');
            }
            stobeLogDebug('Rechat candidate skipped: location mismatch', [
                'candidate' => $candidateName,
                'reason' => $environmentGate['reason'] ?? 'mismatch',
                'speaker_environment' => $speakerEnvironment,
                'candidate_environment' => $candidateEnvironment,
            ]);
            continue;
        }

        if (!isRechatEligible($candidateData, $campaign, $requestedDepth)) {
            $skipCounts['ineligible']++;
            if (count($skipSamples) < 5) {
                $skipSamples[] = $candidateName . ':ineligible';
            }
            continue;
        }

        $respondingNpc = $candidateName;
        $npcData = $candidateData;
        break;
    }

    return [
        'responding_npc' => $respondingNpc,
        'npc_data' => $npcData,
        'skip_counts' => $skipCounts,
        'skip_samples' => $skipSamples,
        'evaluated_pool' => $pool,
    ];
};

$responsePool = $selectedPair;
$selectionAttempt = $selectResponderFromPool($responsePool);
$respondingNpc = strval($selectionAttempt['responding_npc'] ?? '');
$npcData = $selectionAttempt['npc_data'] ?? false;
$skipCounts = is_array($selectionAttempt['skip_counts'] ?? null) ? $selectionAttempt['skip_counts'] : [];
$skipSamples = is_array($selectionAttempt['skip_samples'] ?? null) ? $selectionAttempt['skip_samples'] : [];
$responsePoolEvaluated = is_array($selectionAttempt['evaluated_pool'] ?? null) ? $selectionAttempt['evaluated_pool'] : $responsePool;

$forcedFallbackApplied = false;
if (($respondingNpc === '' || !$npcData) && $forcedReactionActive && count($normalSelectedPair) > 0) {
    stobeConsumeLimbLossRechatReaction(intval($forcedLimbLossReaction['rowid'] ?? 0));
    $forcedFallbackApplied = true;
    stobeLogInfo('Rechat forced limb-loss responder unavailable; falling back to normal pair', [
        'forced_rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'forced_victim' => $forcedResponder,
        'normal_pair' => $normalSelectedPair,
        'forced_skip_counts' => $skipCounts,
        'forced_skip_samples' => $skipSamples,
    ]);

    $responsePool = $normalSelectedPair;
    $selectionAttempt = $selectResponderFromPool($responsePool);
    $respondingNpc = strval($selectionAttempt['responding_npc'] ?? '');
    $npcData = $selectionAttempt['npc_data'] ?? false;
    $skipCounts = is_array($selectionAttempt['skip_counts'] ?? null) ? $selectionAttempt['skip_counts'] : [];
    $skipSamples = is_array($selectionAttempt['skip_samples'] ?? null) ? $selectionAttempt['skip_samples'] : [];
    $responsePoolEvaluated = is_array($selectionAttempt['evaluated_pool'] ?? null) ? $selectionAttempt['evaluated_pool'] : $responsePool;
    $selectionSource = $normalSelectionSource;
}

if ($respondingNpc === '' || !$npcData) {
    if ($forcedReactionActive && !$forcedFallbackApplied) {
        stobeConsumeLimbLossRechatReaction(intval($forcedLimbLossReaction['rowid'] ?? 0));
    }
    stobeLogInfo('Rechat skipped: selected pair filtered out', [
        'candidate_count' => count($candidateNames),
        'selected_pair' => $responsePoolEvaluated,
        'requested_depth' => $requestedDepth,
        'speaker_environment' => $speakerEnvironment,
        'skip_counts' => $skipCounts,
        'skip_samples' => $skipSamples,
        'selection_source' => $selectionSource,
    ]);
    echo "ok";
    return;
}

$contextHistory = getNpcProfileIntegerSetting(
    $npcData,
    ['CONTEXT_HISTORY'],
    '',
    50,
    10,
    250
);
$eventHistory = DataEventLog($contextHistory, $respondingNpc, $campaign);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $respondingNpc, 'rechat');
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $line = stobeFormatEventHistoryLine($row, true);
    if ($line === '') {
        continue;
    }
    $historyLines[] = $line;
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages($eventHistory, intval($gamets));

$rechatSpecialContext = [];
if (
    $forcedReactionActive
    && $forcedResponder !== ''
    && strcasecmp($respondingNpc, $forcedResponder) === 0
) {
    $rechatSpecialContext = [
        'mode' => 'limb_loss_reaction',
        'victim' => $forcedResponder,
        'attacker' => strval($forcedLimbLossReaction['attacker'] ?? ''),
        'limb' => strval($forcedLimbLossReaction['limb'] ?? ''),
        'weapon' => strval($forcedLimbLossReaction['weapon'] ?? ''),
        'hacksaw' => boolval($forcedLimbLossReaction['hacksaw'] ?? false),
        'rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
    ];
}

$systemPrompt = stobeBuildGameTimePromptBlock($gamets)
    . "\n\n"
    . buildRechatSystemPrompt(
        $respondingNpc,
        is_array($npcData) ? $npcData : [],
        $previousSpeaker,
        $previousMessage,
        $previousTarget,
        intval($gamets),
        $rechatSpecialContext
    );
$nearbyPartyPrompt = stobeBuildNearbyPlayerFactionPartyPrompt($npcData, $respondingNpc);
if ($nearbyPartyPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPartyPrompt;
}
$userLine = trim($previousSpeaker . ': ' . $previousMessage);
if ($userLine === ':' || $userLine === '') {
    $userLine = "<rechat_input>\n"
        . "  <previous_speaker>" . stobePromptXmlEscape($previousSpeaker) . "</previous_speaker>\n"
        . "  <previous_target>" . stobePromptXmlEscape($previousTarget) . "</previous_target>\n"
        . "  <instruction>Continue the conversation naturally.</instruction>\n"
        . "</rechat_input>";
} else {
    $userLine = "<rechat_input>\n"
        . "  <previous_speaker>" . stobePromptXmlEscape($previousSpeaker) . "</previous_speaker>\n"
        . "  <previous_target>" . stobePromptXmlEscape($previousTarget) . "</previous_target>\n"
        . "  <previous_message>" . stobePromptXmlEscape($previousMessage) . "</previous_message>\n"
        . "</rechat_input>";
}

$messages = [
    [
        'role' => 'system',
        'content' => $systemPrompt,
    ],
];
foreach ($historyMessages as $historyMessage) {
    $messages[] = $historyMessage;
}
$messages[] = [
    'role' => 'user',
    'content' => $userLine,
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($respondingNpc, $previousSpeaker),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt(
        $respondingNpc,
        false,
        true,
        npcIsInPlayerFaction($npcData)
    ),
];

$llmConfig = getLlmConfigForNpc($npcData);
$actionConfig = stobeBuildActionConfigForNpc('rechat', $npcData);

$responseText = '';
$responseActions = [];
$alreadyStreamed = false;
$structuredJson = false;
$actionsStreamedInLlm = false;
if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
    stobeLogWarn('Rechat skipped: missing API key', ['responding_npc' => $respondingNpc]);
    echo "ok";
    return;
}

$streamResult = stobeStreamDialogueViaLlm(
    $respondingNpc,
    $npcData,
    $messages,
    $llmConfig,
    'rechat',
    [
        'npc_name' => $respondingNpc,
        'event_type' => 'rechat',
        'action_config' => $actionConfig,
    ]
);

if (boolval($streamResult['ok'] ?? false)) {
    $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
    $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
    $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
    $structuredJson = boolval($streamResult['structured_json'] ?? false);
    $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
} else {
    $rawResponse = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $respondingNpc,
        'event_type' => 'rechat',
    ]);
    if ($rawResponse === false || trim($rawResponse) === '') {
        stobeLogWarn('Rechat LLM returned empty response', ['responding_npc' => $respondingNpc]);
        echo "ok";
        return;
    }

    $structured = stobeParseStructuredDialogueResponse($rawResponse, 'rechat');
    $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
    $structuredJson = boolval($structured['is_structured'] ?? false);
    $structuredAction = trim(strval($structured['action_tag'] ?? ''));
    if ($structuredAction !== '') {
        $responseActions[] = $structuredAction;
    }
    if ($responseText !== '') {
        $actionExtraction = extractAndNormalizeActionTags($responseText, 'rechat', $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($actionExtraction['text'] ?? $responseText)));
        $inlineActions = is_array($actionExtraction['actions'] ?? null) ? $actionExtraction['actions'] : [];
        foreach ($inlineActions as $inlineAction) {
            if (!in_array($inlineAction, $responseActions, true)) {
                $responseActions[] = $inlineAction;
            }
        }
    }
}
$responseActions = stobeDedupeActionList($responseActions, 'rechat', $actionConfig);
$replyTarget = $previousSpeaker;
if ($replyTarget === '') {
    $replyTarget = $previousTarget;
}
if ($replyTarget === '') {
    $replyTarget = $playerName !== '' ? $playerName : 'Unknown';
}

$relationshipEval = stobeEvaluateRelationshipsForTurn(
    $respondingNpc,
    $replyTarget,
    trim($previousSpeaker . ': ' . $previousMessage),
    $responseText,
    $npcData,
    'rechat'
);
$responseText = stobeStripParentheticalDialogueText(
    sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)))
);
$responseText = stobeStripParentheticalDialogueText($responseText);

if ($responseText === '' && count($responseActions) === 0) {
    echo "ok";
    return;
}

if ($responseText === '' && count($responseActions) > 0) {
    $responseTextForStore = '[action issued]';
} else {
    $responseTextForStore = $responseText;
}

if ($responseTextForStore === '') {
    echo "ok";
    return;
}

$chatEventData = $respondingNpc . ': ' . $responseTextForStore . ' (talking to: ' . $replyTarget . ')';
$responseEventType = 'rechat';
storeActionEvents($respondingNpc, $responseActions, $gamets, $replyTarget, $responseEventType);
storeEvent($responseEventType, time(), $gamets, $chatEventData);
if (count($rechatSpecialContext) > 0) {
    stobeConsumeLimbLossRechatReaction(intval($rechatSpecialContext['rowid'] ?? 0));
}

stobeLogInfo('Rechat response generated', [
    'responding_npc' => $respondingNpc,
    'previous_speaker' => $previousSpeaker,
    'target' => $replyTarget,
    'speaker_environment' => $speakerEnvironment,
    'responder_environment' => $extractEnvironment($npcData, $respondingNpc),
    'response_length' => strlen($responseText),
    'structured_json' => $structuredJson,
    'actions_count' => count($responseActions),
    'actions' => $responseActions,
    'already_streamed' => $alreadyStreamed,
    'actions_streamed' => $actionsStreamedInLlm,
    'forced_limb_loss' => count($rechatSpecialContext) > 0,
    'forced_limb_loss_rowid' => intval($rechatSpecialContext['rowid'] ?? 0),
]);

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($respondingNpc, 'ScriptQueue', '', $npcData, $responseActions);
    }
} else {
    streamResponse($respondingNpc, 'ScriptQueue', $responseText, $npcData, $responseActions);
}
