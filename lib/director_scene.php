<?php
require_once __DIR__ . '/director_scene_contract.php';

// Use Stobe's existing action catalog and faction/equipment restrictions for each actor.
function stobeDirectorCatalog(array $actors): array
{
    $catalog = [];
    foreach ($actors as $name => $npc) {
        $config = stobeBuildActionConfigForNpc('director', $npc);
        if (empty($config['enabled'])) continue;
        foreach ($config['active_rows'] as $row) {
            $code = stobeCanonicalizeActionCommand($row['command']);
            if ($code === '' || $code === 'TALK' || !isAllowedActionCommand($code, $config['allowlist'])) continue;
            if (!empty($config['disallow_' . strtolower($code)])) continue;
            $inParty = npcIsInPlayerFaction($npc);
            if (($inParty && in_array($code, ['JOIN_PARTY', 'FOLLOW', 'STOP_FOLLOW'], true))
                || (!$inParty && $code === 'LEAVE')) continue;
            $catalog[$code]['description'] = $row['description'];
            $catalog[$code]['speakers'][] = $name;
            // These are the same fields consumed by Stobe's structured action adapter.
            $catalog[$code]['parameters'] = ['type' => 'object', 'properties' => [
                'target' => ['type' => 'string', 'description' => 'Exact target name or value required by the action.'],
                'item' => ['type' => 'string', 'description' => 'Exact available item; use the actor inventory.'],
                'amount' => ['type' => 'integer', 'description' => 'Quantity or numeric value required by the action.'],
            ]];
        }
    }
    return $catalog;
}

// Request-bound client serial for a cast member. main.php stores people as "Name (state)|hand_<n>";
// missing, malformed, out-of-range or conflicting serials for the same name fail closed.
function stobeDirectorActorSerial(string $name, string $peopleJson): string
{
    $people = json_decode($peopleJson, true);
    $serial = '';
    foreach (is_array($people) ? $people : [] as $entry) {
        $token = is_string($entry) ? extractParticipantIdentityToken($entry) : ['name' => ''];
        if ($token['name'] === '' || strcasecmp($token['name'], $name) !== 0) continue;
        if (preg_match('/^hand_([1-9][0-9]{0,9})$/', $token['storage_id'], $m) !== 1 || (int)$m[1] > 4294967295
            || ($serial !== '' && $serial !== $m[1])) return '';
        $serial = $m[1];
    }
    return $serial;
}

// Author all dialogue and prepare its audio before emitting a single scene payload.
function stobeGenerateDirectorScene(array $names, string $seed, string $listener, string $instruction, int $gamets): void
{
    require_once __DIR__ . '/../connector/llm_dispatcher.php';
    require_once __DIR__ . '/relationship_manager.php';
    $player = getSetting('PLAYER_NAME', 'Drifter');
    $people = strval($GLOBALS['CACHE_PEOPLE'] ?? $_GET['people'] ?? '[]');
    $identities = [];
    foreach ($names as $name) {
        if (($serial = stobeDirectorActorSerial($name, $people)) !== '') $identities[$name] = $serial;
    }
    usort($names, static fn($a, $b) => (int)($b === $seed || stripos($instruction, $b) !== false)
        <=> (int)($a === $seed || stripos($instruction, $a) !== false));
    $actors = [];
    $context = [];
    foreach (array_slice(array_unique($names), 0, 12) as $name) {
        if ($name === $player || stobeIsNarratorName($name) || !isset($identities[$name])) continue;
        $npc = getNpcData($name);
        if (!$npc) continue;
        $actors[$name] = $npc;
        $bio = ['name' => $name];
        foreach (['backstory', 'personality', 'speechstyle', 'occupation', 'appearance', 'skills', 'goals', 'core'] as $field) {
            $bio[$field] = mb_substr((string)($npc[$field] ?? ''), 0, 3000);
        }
        $profile = getCoreProfileById((int)($npc['profile_id'] ?? getDefaultNpcProfileId())) ?: [];
        $bio['profile_instructions'] = mb_substr((string)($npc['profile_prompt'] ?? $profile['profile_prompt'] ?? ''), 0, 2000);
        $metadata = is_array($npc['metadata'] ?? null) ? $npc['metadata'] : (json_decode($npc['metadata'] ?? '{}', true) ?: []);
        $inventory = $npc['inventory'] ?? $metadata['inventory'] ?? [];
        $bio['inventory'] = array_slice(is_array($inventory) ? $inventory : (json_decode($inventory, true) ?: []), 0, 80);
        $extended = is_array($npc['extended_data'] ?? null) ? $npc['extended_data'] : (json_decode($npc['extended_data'] ?? '{}', true) ?: []);
        $bio['past_events'] = [];
        foreach ($extended['middle_term_memory'] ?? [] as $time => $memory) {
            if (is_numeric($time) && (int)$time <= $gamets && is_string($memory)) $bio['past_events'][] = mb_substr($memory, 0, 2000);
        }
        $bio['past_events'] = array_slice($bio['past_events'], -2);
        if (stobeExtensionHasActorProfileEnrichers()) {
            $registered = stobeExtensionRegisteredNpcProjection($npc);
            $extra = stobeExtensionActorEnrichmentText($name, 'npc', ['source' => 'director_cast',
                'npc_data' => $registered, 'registered_npc' => $registered,
                'player_side' => stobeExtensionPlayerSide($name, $registered['in_player_faction'])]);
            if ($extra !== '') $bio['extension_context'] = $extra;
        }
        $context[] = $bio;
    }
    if (!$actors || !isset($actors[$seed])) throw new RuntimeException('No eligible Director cast');
    $history = DataEventLog(30, $seed, 'Default');
    $world = stobeBuildGameTimePromptBlock($gamets, $actors[$seed]);
    $world .= "\n# Historical dialogue and events (not current presence)\n";
    foreach (array_reverse(stobeFilterNarratorRowsForContext($history, $seed, 'director')) as $row) {
        $world .= "\n" . stobeFormatEventHistoryLine($row, true);
    }
    $world .= "\n# Current nearby party\n" . stobeBuildNearbyPlayerFactionPartyPrompt($actors[$seed], $seed);
    if (class_exists('RelationshipManager')) $world .= "\n# Present cast relationships\n" . RelationshipManager::buildDirectorContext(array_keys($actors));
    if (trim($instruction) === '') $instruction = "Start a natural conversation between {$seed} and {$listener} about the current situation.";
    $catalog = stobeDirectorCatalog($actors);
    // Scene prompts have no single <character> or dialogue JSON template; only prompt_bottom applies.
    stobeRunExtensionHook('context_pre.php');
    $messages = [
        ['role' => 'system', 'content' => dwemerDirectorPrompt('Kenshi', $catalog)
            . stobeRenderPromptInjections('prompt_bottom', stobeExtensionPromptContext($seed))],
        ['role' => 'user', 'content' => "# World context and history\n" . $world
            . "\n# Present eligible NPC profiles\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n# Player name\n" . $player],
        ['role' => 'user', 'content' => $instruction],
    ];
    $messages = stobeApplyExtensionContextHook($messages);
    $config = getLlmConfigForNpc($actors[$seed]);
    $config['max_tokens'] = 4000;
    $format = ['type' => 'json_object'];
    if (!empty($config['config']['json_schema'])) {
        $format = dwemerDirectorResponseFormat($actors, $catalog, $player);
    }
    $raw = stobeCallLLM($messages, $config, ['event_type' => 'director', 'npc_name' => $seed,
        'response_format' => $format]);
    if (is_string($raw)) {
        $raw = trim($raw);
        // Accept one complete JSON fence, without accepting surrounding prose.
        if (preg_match('/\A```(?:json)?[ \t]*\R(.*)\R```[ \t]*\z/is', $raw, $match)) {
            $raw = trim($match[1]);
        }
    }
    try {
        $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    } catch (JsonException $error) {
        throw new RuntimeException('Director did not return JSON: ' . $error->getMessage(), 0, $error);
    }
    if (!is_array($decoded)) throw new RuntimeException('Director did not return a JSON scene');
    $scene = dwemerValidateDirectorScene($decoded, $actors, $catalog, $player);
    $scene = dwemerSplitDirectorScene($scene, static fn(array $line): array =>
        stobeSplitSentencesStream(stobeStripParentheticalDialogueText($line['text'])));
    // Indexed transport fields use the existing Kenshi JSON reader without a new runtime dependency.
    $wire = ['schema' => 'stobe.director_scene.v2', 'id' => $scene['id'], 'line_count' => count($scene['lines'])];
    $turns = [];
    foreach ($scene['lines'] as $index => $line) {
        if ($line['text'] === '' || preg_match('/[|\[\]]/', $line['text'])) throw new RuntimeException('Unsafe Director dialogue');
        $line['utterance_id'] = 'director-' . $scene['id'] . '-' . $index;
        $npc = $actors[$line['speaker']];
        $line['actor_id'] = $identities[$line['speaker']];
        $audio = stobeIsTtsEnabledForCurrentRequest() ? stobeSynthesizePocketTtsLine($line['speaker'], $line['text'], $npc) : [];
        $line['tts_hash'] = $audio['hash'] ?? '';
        $line['tts_duration_ms'] = (int)($audio['duration_ms'] ?? 0);
        if (stobeIsTtsEnabledForCurrentRequest() && $line['tts_hash'] === '') throw new RuntimeException('Director audio failed');
        $attached = 0;
        foreach ($scene['actions'] as $action) {
            if ($action['after_line'] !== $index + 1) continue;
            $parameters = $action['parameters'];
            foreach ($parameters as $value) {
                if (is_string($value) && preg_match('/[|\[\]]/', $value)) throw new RuntimeException('Unsafe Director action argument');
            }
            $tag = stobeBuildActionTagFromStructuredPayload($action['command_name'], $parameters['target'] ?? '',
                $parameters['item'] ?? '', '', $line['listener'], (string)($parameters['amount'] ?? ''));
            $tag = normalizeActionTagToken($tag, stobeBuildActionConfigForNpc('director', $npc));
            if ($tag === '') throw new RuntimeException('Director action is not currently available');
            $dispatch = stobeTransformActionForDispatch($tag, $npc);
            if ($dispatch === '' || preg_match('/[\r\n]/', $dispatch)) throw new RuntimeException('Director action could not be resolved');
            $line['action_' . $attached++] = $dispatch;
        }
        $line['action_count'] = $attached;
        $turns[] = $line;
        $wire['turn_' . $index] = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $payload = 'rolemaster|DirectorScene|' . json_encode($wire, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\r\n";
    $db = $GLOBALS['db'];
    if ($db->exec('BEGIN') === false) throw new RuntimeException('Director history transaction failed');
    try {
        foreach ($turns as $line) {
            $saved = stobePersistGeneratedSpeechChunk(['actor' => $line['speaker'], 'message' => $line['text'],
                'utterance_id' => $line['utterance_id'], 'event_type' => 'chat', 'listener' => $line['listener']], $gamets);
            if (empty($saved['rowid'])) throw new RuntimeException('Director pending history failed');
        }
        if ($db->exec('COMMIT') === false) throw new RuntimeException('Director history commit failed');
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
    echo $payload;
    stobeLogOutputToPlugin('rolemaster', 'DirectorScene', '', $payload);
    stobeMarkExtensionModelTurnCompleted();
    stobeLogInfo('Director scene queued', ['scene_id' => $scene['id'], 'turns' => count($turns)]);
}
