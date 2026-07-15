<?php

/**
 * Phase 3 supervised planner. The model proposes one catalog action; this
 * layer validates and normalizes it before the decision ledger can issue it.
 */

function stobeAutonomyPlannerActionContracts(): array
{
    $target = ['target'];
    return [
        'ATTACK' => $target,
        'SUICIDE' => [],
        'FOLLOW' => $target,
        'STOP_FOLLOW' => [],
        'JOIN_PARTY' => [],
        'LEAVE' => [],
        'IDLE' => ['duration_ms'],
        'STOP_CARRYING' => [],
        'PICKUP_NPC' => $target,
        'GIVE_CATS' => ['target', 'amount'],
        'TAKE_CATS' => ['target', 'amount'],
        'TAKE_ITEM' => ['target', 'item', 'amount'],
        'GIVE_ITEM' => ['target', 'item', 'amount'],
        'DROP_ITEM' => ['item'],
        'ROLEPLAY_ACTION' => ['message'],
        'FACTION_RELATIONS' => ['target', 'amount'],
        'SET_BLOCK' => ['enabled'],
        'SET_HOLD' => ['enabled'],
        'SET_PASSIVE' => ['enabled'],
        'SET_JOBS' => ['enabled'],
        'SET_RANGED' => ['enabled'],
        'SET_TAUNT' => ['enabled'],
        'SET_SNEAK' => ['enabled'],
        'SET_RESOURCE' => ['enabled'],
        'SET_MEDIC' => ['enabled'],
        'REMOVE_LIMB' => ['target', 'limb'],
        'CUT_HORNS' => $target,
        'KNOCKOUT' => $target,
        'KILL' => $target,
        'USE_OBJECT' => ['object'],
        'USE_DRUGS' => ['item'],
        'DRINK' => ['item'],
        'FORCE_DRINK' => ['target', 'item'],
        'TRAVEL_LOCATION' => ['location_zone_id'],
        'TALK' => ['message'],
    ];
}

function stobeAutonomyPlannerRequiredArguments(string $command): array
{
    return match ($command) {
        'TAKE_ITEM' => ['item'],
        'GIVE_ITEM' => ['target', 'item'],
        'FORCE_DRINK' => ['target'],
        'USE_OBJECT' => [],
        default => stobeAutonomyPlannerActionContracts()[$command] ?? [],
    };
}

function stobeAutonomyPlannerPolicy(array $session): array
{
    $policy = is_array($session['policy'] ?? null) ? $session['policy'] : [];
    $disabled = [];
    foreach (($policy['disabled_actions'] ?? []) as $command) {
        $normalized = strtoupper(trim(strval($command)));
        if ($normalized !== '') {
            $disabled[$normalized] = true;
        }
    }
    return [
        'mode' => strtolower(trim(strval($session['planner_mode'] ?? ($policy['planner_mode'] ?? 'llm')))) === 'pilot' ? 'pilot' : 'llm',
        'minimum_interval_seconds' => max(3, min(300, intval($policy['minimum_interval_seconds'] ?? 30))),
        'max_decisions_per_hour' => max(1, min(240, intval($policy['max_decisions_per_hour'] ?? 30))),
        'disabled_actions' => $disabled,
    ];
}

function stobeAutonomyPlannerBackoffSeconds(int $failureCount, int $minimumIntervalSeconds): int
{
    $base = max(20, min(300, $minimumIntervalSeconds));
    $exponent = max(0, min(4, $failureCount - 1));
    return min(300, $base * (2 ** $exponent));
}

function stobeAutonomyPlannerConnectorRequiresApiKey(array $config): bool
{
    $type = strtolower(trim(strval($config['connector_type'] ?? '')));
    if (in_array($type, ['player2', 'player2json', 'koboldcpp', 'koboldcppjson'], true)) {
        return false;
    }
    $host = strtolower(strval(parse_url(trim(strval($config['base_url'] ?? '')), PHP_URL_HOST) ?? ''));
    return !in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

function stobeAutonomyPlannerRecentActors(int $gameTs, int $limit = 30): array
{
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT people, data, type, gamets, location
         FROM eventlog
         WHERE gamets <= $1 AND gamets >= GREATEST(0, $1 - 21600)
         ORDER BY gamets DESC, rowid DESC LIMIT " . max(1, min(100, $limit)),
        [$gameTs]
    );
    $names = [];
    foreach ($rows as $row) {
        $people = preg_split('/[,;|]+/', strval($row['people'] ?? '')) ?: [];
        foreach ($people as $person) {
            $person = trim($person);
            if ($person !== '' && strlen($person) <= 120) {
                $names[strtolower($person)] = $person;
            }
        }
    }
    return array_values($names);
}

function stobeAutonomyPlannerRecentEvents(int $gameTs, int $limit = 18): array
{
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT type, data, people, location, gamets
         FROM eventlog WHERE gamets <= $1
         ORDER BY gamets DESC, rowid DESC LIMIT " . max(1, min(50, $limit)),
        [$gameTs]
    );
    return array_map(static function (array $row): array {
        return [
            'type' => trim(strval($row['type'] ?? '')),
            'data' => mb_substr(trim(strval($row['data'] ?? '')), 0, 500),
            'people' => mb_substr(trim(strval($row['people'] ?? '')), 0, 300),
            'location' => mb_substr(trim(strval($row['location'] ?? '')), 0, 200),
            'game_ts' => intval($row['gamets'] ?? 0),
        ];
    }, $rows);
}

function stobeAutonomyPlannerBuildAllowlist(array $session, array $snapshot, array $npc): array
{
    $contracts = stobeAutonomyPlannerActionContracts();
    $policy = stobeAutonomyPlannerPolicy($session);
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT UPPER(command) AS command, action_name, description
         FROM combined_core_action
         WHERE is_activated = TRUE ORDER BY action_name, command"
    );
    $locations = stobeAutonomyListVisitedLocations(120);
    $nearbyActors = is_array($snapshot['nearby_actors'] ?? null) ? $snapshot['nearby_actors'] : [];
    $actors = array_values(array_unique(array_map(
        static fn(array $actor): string => strval($actor['name'] ?? ''),
        $nearbyActors
    )));
    $allowlist = [];
    foreach ($rows as $row) {
        $command = strtoupper(trim(strval($row['command'] ?? '')));
        $commandActors = $actors;
        if (!isset($contracts[$command]) || isset($policy['disabled_actions'][$command])) {
            continue;
        }
        if ($command === 'TRAVEL_LOCATION' && count($locations) === 0) {
            continue;
        }
        if ($command === 'KNOCKOUT') {
            $commandActors[] = strval($npc['name'] ?? '');
            $commandActors = array_values(array_unique(array_filter($commandActors)));
        }
        if (in_array('target', stobeAutonomyPlannerRequiredArguments($command), true) && count($commandActors) === 0) {
            continue;
        }
        if (in_array($command, ['FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY'], true)) {
            // The selected character is already in the player faction. These
            // adapters intentionally reject player-faction actors.
            continue;
        }
        if ($command === 'STOP_CARRYING' && !stobeAutonomyBool($snapshot['status']['carrying'] ?? false)) {
            continue;
        }
        if (in_array($command, ['GIVE_ITEM', 'DROP_ITEM', 'USE_DRUGS', 'DRINK', 'FORCE_DRINK'], true) &&
            trim(strval($npc['inventory'] ?? '')) === '' && trim(strval($npc['equipment'] ?? '')) === '') {
            continue;
        }
        $entry = [
            'command' => $command,
            'description' => trim(strval($row['description'] ?? '')),
            'arguments' => $contracts[$command],
            'required_arguments' => stobeAutonomyPlannerRequiredArguments($command),
        ];
        if (in_array('target', $contracts[$command], true)) {
            $validTargets = $commandActors;
            if (in_array($command, ['PICKUP_NPC', 'TAKE_ITEM', 'REMOVE_LIMB', 'CUT_HORNS', 'KILL', 'FORCE_DRINK'], true)) {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    array_filter($nearbyActors, static fn(array $actor): bool =>
                        stobeAutonomyBool($actor['dead'] ?? false) || stobeAutonomyBool($actor['unconscious'] ?? false))
                ));
                if (count($validTargets) === 0 && $command !== 'TAKE_ITEM') {
                    continue;
                }
            } elseif ($command === 'ATTACK') {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    array_filter($nearbyActors, static fn(array $actor): bool => !stobeAutonomyBool($actor['dead'] ?? false))
                ));
                if (count($validTargets) === 0) {
                    continue;
                }
            }
            $entry['valid_targets'] = array_values(array_slice(array_unique(array_filter($validTargets)), 0, 30));
        }
        if ($command === 'TRAVEL_LOCATION') {
            $entry['visited_locations'] = array_map(static fn(array $location): array => [
                'location_zone_id' => intval($location['id']),
                'zone_name' => strval($location['zone_name']),
                'city_name' => strval($location['city_name']),
            ], $locations);
        }
        $allowlist[] = $entry;
    }
    return $allowlist;
}

function stobeAutonomyPlannerContext(array $session, array $snapshot, array $allowlist): array
{
    $npc = getNpcById(intval($session['npc_id'] ?? 0));
    if (!$npc) {
        throw new RuntimeException('Selected autonomy NPC no longer exists.');
    }
    $metadata = stobeAutonomyDecodeJsonColumn($npc['metadata'] ?? '{}');
    $extended = stobeAutonomyDecodeJsonColumn($npc['extended_data'] ?? '{}');
    return [
        'npc' => [
            'id' => intval($npc['id'] ?? 0),
            'name' => strval($npc['name'] ?? ''),
            'race' => strval($npc['race'] ?? ''),
            'faction' => strval($npc['faction'] ?? ''),
            'personality' => mb_substr(strval($npc['personality'] ?? ''), 0, 1800),
            'backstory' => mb_substr(strval($npc['backstory'] ?? ''), 0, 1800),
            'occupation' => mb_substr(strval($npc['occupation'] ?? ''), 0, 500),
            'goals' => mb_substr(strval($npc['goals'] ?? ''), 0, 1200),
            'relationships' => mb_substr(strval($npc['relationships'] ?? ''), 0, 1200),
            'inventory' => mb_substr(strval($npc['inventory'] ?? ''), 0, 1800),
            'equipment' => mb_substr(strval($npc['equipment'] ?? ''), 0, 1200),
            'hunger' => strval($npc['hunger'] ?? ''),
            'blood' => strval($npc['blood'] ?? ''),
            'limbs' => stobeAutonomyDecodeJsonColumn($npc['limbs'] ?? '{}'),
            'runtime' => array_intersect_key($metadata + $extended, array_flip([
                'current_goal', 'current_order', 'current_task', 'location', 'weather',
                'carrying', 'cats', 'health', 'conscious', 'combat',
            ])),
        ],
        'directive' => strval($session['long_term_directive'] ?? ''),
        'persistent_goal' => $session['current_goal'] ?? [],
        'snapshot' => $snapshot,
        'recent_events' => stobeAutonomyPlannerRecentEvents(intval($snapshot['game_ts'] ?? 0)),
        'recent_autonomy' => array_map(static fn(array $event): array => [
            'event_type' => strval($event['event_type'] ?? ''),
            'command' => strval($event['command'] ?? ''),
            'outcome' => strval($event['outcome'] ?? ''),
            'reason' => mb_substr(strval($event['reason'] ?? ''), 0, 300),
            'game_ts' => intval($event['game_ts'] ?? 0),
        ], stobeAutonomyListEvents(12)),
        'allowlist' => $allowlist,
    ];
}

function stobeAutonomyPlannerAuditContext(array $session, array $snapshot, array $allowlist): array
{
    $policy = stobeAutonomyPlannerPolicy($session);
    return [
        'context_hash' => strval($snapshot['context_hash'] ?? ''),
        'allowlist_count' => count($allowlist),
        'allowlist_commands' => array_values(array_map(
            static fn(array $entry): string => strval($entry['command'] ?? ''),
            array_slice($allowlist, 0, 100)
        )),
        'policy' => [
            'preset' => strval(($session['policy']['preset'] ?? 'full_autonomy')),
            'minimum_interval_seconds' => intval($policy['minimum_interval_seconds']),
            'max_decisions_per_hour' => intval($policy['max_decisions_per_hour']),
            'disabled_actions' => array_values(array_keys($policy['disabled_actions'])),
        ],
        'planner_connector_id' => max(0, intval($session['planner_connector_id'] ?? 0)),
    ];
}

function stobeAutonomyPlannerDecodeResponse(string $raw): array|false
{
    $text = trim($raw);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : false;
}

function stobeAutonomyPlannerCleanText(mixed $value, int $maxLength): string
{
    $text = trim(strval($value));
    $text = str_replace(["\r", "\n", '@'], [' ', ' ', ' '], $text);
    return mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, $maxLength);
}

function stobeAutonomyPlannerNormalizeProposal(array $response, array $allowlist): array
{
    foreach (array_keys($response) as $field) {
        if (!in_array($field, ['goal', 'decision', 'reason'], true)) {
            throw new InvalidArgumentException('planner_unexpected_top_level_field');
        }
    }
    if (!array_key_exists('goal', $response) || !is_array($response['goal'])) {
        throw new InvalidArgumentException('planner_goal_not_object');
    }
    if (!array_key_exists('decision', $response)) {
        throw new InvalidArgumentException('planner_decision_missing');
    }
    $goalInput = is_array($response['goal'] ?? null) ? $response['goal'] : [];
    foreach (array_keys($goalInput) as $field) {
        if (!in_array($field, ['summary', 'status'], true)) {
            throw new InvalidArgumentException('planner_unexpected_goal_field');
        }
    }
    if (!array_key_exists('summary', $goalInput) || !array_key_exists('status', $goalInput)) {
        throw new InvalidArgumentException('planner_goal_fields_missing');
    }
    $goalStatus = strtolower(trim(strval($goalInput['status'] ?? 'active')));
    if (!in_array($goalStatus, ['active', 'complete', 'blocked'], true)) {
        throw new InvalidArgumentException('planner_goal_status_invalid');
    }
    $goal = [
        'summary' => stobeAutonomyPlannerCleanText($goalInput['summary'] ?? '', 500),
        'status' => $goalStatus,
        'updated_at' => time(),
    ];
    if (($response['decision'] ?? null) === null) {
        return ['goal' => $goal, 'decision' => null, 'reason' => stobeAutonomyPlannerCleanText($response['reason'] ?? '', 500)];
    }
    if (!is_array($response['decision'] ?? null)) {
        throw new InvalidArgumentException('planner_decision_not_object_or_null');
    }
    $decision = $response['decision'];
    $command = strtoupper(trim(strval($decision['command'] ?? '')));
    $allowed = [];
    foreach ($allowlist as $entry) {
        $allowed[strtoupper(strval($entry['command'] ?? ''))] = $entry;
    }
    if ($command === '' || !isset($allowed[$command])) {
        throw new InvalidArgumentException('planner_command_not_allowed');
    }
    $contracts = stobeAutonomyPlannerActionContracts();
    $validDecisionFields = array_merge(['command'], $contracts[$command]);
    foreach (array_keys($decision) as $field) {
        if (!in_array($field, $validDecisionFields, true)) {
            throw new InvalidArgumentException('planner_unexpected_decision_field');
        }
    }
    foreach (stobeAutonomyPlannerRequiredArguments($command) as $field) {
        if (!array_key_exists($field, $decision)) {
            throw new InvalidArgumentException('planner_missing_' . $field);
        }
    }
    $args = [];
    foreach ($contracts[$command] as $field) {
        if ($field === 'amount') {
            $defaultAmount = in_array($command, ['TAKE_ITEM', 'GIVE_ITEM'], true) ? 1 : 0;
            $args[$field] = intval($decision[$field] ?? $defaultAmount);
        } elseif ($field === 'duration_ms') {
            $args[$field] = max(500, min(30000, intval($decision[$field] ?? 1500)));
        } elseif ($field === 'location_zone_id') {
            $args[$field] = intval($decision[$field] ?? 0);
        } elseif ($field === 'enabled') {
            $args[$field] = stobeAutonomyBool($decision[$field] ?? false);
        } else {
            $args[$field] = stobeAutonomyPlannerCleanText($decision[$field] ?? '', 500);
        }
    }
    foreach (stobeAutonomyPlannerRequiredArguments($command) as $field) {
        if (in_array($field, ['target', 'item', 'message', 'limb', 'object'], true) && $args[$field] === '') {
            throw new InvalidArgumentException('planner_missing_' . $field);
        }
        if ($field === 'amount' && $args[$field] === 0) {
            throw new InvalidArgumentException('planner_invalid_amount');
        }
    }
    if (isset($args['item']) && preg_match('/[,;]/', strval($args['item'])) === 1) {
        throw new InvalidArgumentException('planner_item_must_be_single');
    }
    if (trim(strval($args['target'] ?? '')) !== '' && isset($allowed[$command]['valid_targets'])) {
        $validTargets = array_map('strtolower', $allowed[$command]['valid_targets']);
        if (!in_array(strtolower($args['target']), $validTargets, true) &&
            !($command === 'KNOCKOUT' && strtolower($args['target']) === strtolower(strval($GLOBALS['stobe_autonomy_npc_name'] ?? '')))) {
            throw new InvalidArgumentException('planner_target_not_observed');
        }
    }
    if ($command === 'TRAVEL_LOCATION') {
        $location = stobeAutonomyGetVisitedLocation(intval($args['location_zone_id'] ?? 0));
        if (!$location) {
            throw new InvalidArgumentException('planner_location_not_visited');
        }
        $args += $location;
        $args['arrival_radius'] = 8.0;
    }
    $args['legacy_argument'] = stobeAutonomyPlannerLegacyArgument($command, $args);
    return [
        'goal' => $goal,
        'decision' => ['command' => $command, 'arguments' => $args],
        'reason' => stobeAutonomyPlannerCleanText($response['reason'] ?? '', 500),
    ];
}

function stobeAutonomyPlannerLegacyArgument(string $command, array $args): string
{
    $target = strval($args['target'] ?? '');
    $item = strval($args['item'] ?? '');
    $amount = intval($args['amount'] ?? 1);
    return match ($command) {
        'GIVE_CATS', 'TAKE_CATS', 'FACTION_RELATIONS' => $target . '@' . $amount,
        'GIVE_ITEM', 'TAKE_ITEM' => ($target !== '' ? $target . '@' : '') . $item . '@' . max(1, $amount),
        'DROP_ITEM' => $item,
        'REMOVE_LIMB' => $target . '@' . strval($args['limb'] ?? ''),
        'FORCE_DRINK' => $target . '@' . ($item !== '' ? $item : 'Cactus Rum'),
        'TRAVEL_LOCATION' => implode('@', [
            strval($args['x']), strval($args['y']), strval($args['z']),
            strval($args['city_name'] ?: $args['zone_name']),
        ]),
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC' => ($args['enabled'] ?? false) ? 'ON' : 'OFF',
        'ROLEPLAY_ACTION', 'TALK' => strval($args['message'] ?? ''),
        'USE_OBJECT' => strval($args['object'] ?? ''),
        'USE_DRUGS', 'DRINK' => $item,
        default => $target !== '' ? $target : $item,
    };
}

function stobeAutonomyPlannerCall(array $context, array $npc, array $session = []): array
{
    $promptJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($promptJson)) {
        throw new RuntimeException('planner_context_encoding_failed');
    }
    $messages = [
        ['role' => 'system', 'content' => 'You control one Kenshi squad NPC. Choose at most one action from allowlist. Never invent a command, target, item, or location. Do not repeat a command with identical arguments when recent_autonomy shows it already completed; use decision:null unless current context clearly requires a distinct new action. Return only JSON: {"goal":{"summary":"...","status":"active|complete|blocked"},"decision":null|{"command":"...", plus fields listed for that command},"reason":"..."}. Include every required_arguments field and no unlisted fields. Use decision:null when waiting is best.'],
        ['role' => 'user', 'content' => $promptJson],
    ];
    $started = microtime(true);
    if (isset($GLOBALS['stobe_autonomy_planner_test_callback']) && is_callable($GLOBALS['stobe_autonomy_planner_test_callback'])) {
        $raw = call_user_func($GLOBALS['stobe_autonomy_planner_test_callback'], $messages, $context);
    } else {
        require_once dirname(__DIR__) . '/connector/llm_dispatcher.php';
        $connectorId = max(0, intval($session['planner_connector_id'] ?? 0));
        if ($connectorId > 0) {
            $connector = getLlmConnectorById($connectorId);
            if (!$connector) {
                throw new RuntimeException('planner_connector_not_found');
            }
            $config = stobeBuildLlmConfigFromConnector($connector);
        } else {
            $config = getLlmConfigForNpcPurpose($npc, 'response');
        }
        if (trim(strval($config['api_key'] ?? '')) === '' && stobeAutonomyPlannerConnectorRequiresApiKey($config)) {
            throw new RuntimeException('planner_missing_api_key');
        }
        $config['max_tokens'] = max(300, min(900, intval($config['max_tokens'] ?? 500)));
        $raw = stobeCallLLM($messages, $config, [
            'npc_name' => strval($npc['name'] ?? ''),
            'event_type' => 'autonomy_planner',
            'response_format' => ['type' => 'json_object'],
        ]);
    }
    $latencyMs = max(0, intval(round((microtime(true) - $started) * 1000)));
    if ($raw === false || trim(strval($raw)) === '') {
        throw new RuntimeException('planner_connector_failed');
    }
    $raw = strval($raw);
    $decoded = stobeAutonomyPlannerDecodeResponse($raw);
    if (!$decoded) {
        throw new RuntimeException('planner_malformed_json');
    }
    return [
        'decoded' => $decoded,
        'raw' => $raw,
        'prompt_hash' => hash('sha256', $promptJson),
        'response_hash' => hash('sha256', $raw),
        'latency_ms' => $latencyMs,
        'prompt_tokens' => intval(ceil(strlen($promptJson) / 4)),
        'completion_tokens' => intval(ceil(strlen($raw) / 4)),
    ];
}
