<?php

function stobeDirectorReadPayload(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function stobeDirectorSendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stobeDirectorDecodePlannerResponse(string $raw): array|false
{
    $candidate = trim($raw);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $candidate, $match) === 1) {
        $candidate = trim(strval($match[1] ?? ''));
    }
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($candidate, '{');
    $end = strrpos($candidate, '}');
    if ($start === false || $end === false || $end <= $start) {
        return false;
    }
    $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : false;
}

function stobeDirectorNormalizeCapabilities(mixed $raw): array
{
    $allowed = ['inspect', 'notify', 'save', 'time', 'teleport', 'movement'];
    $values = is_array($raw) ? $raw : [];
    $result = [];
    foreach ($values as $value) {
        $capability = strtolower(trim(strval($value)));
        if (in_array($capability, $allowed, true) && !in_array($capability, $result, true)) {
            $result[] = $capability;
        }
    }
    return $result;
}

function stobeDirectorValidateScript(string $script, array $capabilities): string
{
    if ($script === '') {
        return 'planner_empty_script';
    }
    if (strlen($script) > 24576) {
        return 'planner_script_too_large';
    }
    if (str_contains($script, "\0")) {
        return 'planner_script_contains_nul';
    }
    if (preg_match('/\b(?:io|os|package|debug)\s*[\.:]/i', $script) === 1) {
        return 'planner_script_uses_blocked_library';
    }

    $functionCapabilities = [
        'world_summary' => 'inspect',
        'player_characters' => 'inspect',
        'notify' => 'notify',
        'save' => 'save',
        'set_game_speed' => 'time',
        'teleport' => 'teleport',
        'teleport_selected' => 'teleport',
        'move_to' => 'movement',
        'move_selected' => 'movement',
    ];
    if (preg_match_all('/\bkenshi\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)\b/', $script, $matches) === false) {
        return 'planner_script_scan_failed';
    }
    if (empty($matches[1])) {
        return 'planner_script_has_no_kenshi_calls';
    }
    foreach ($matches[1] as $function) {
        if (!array_key_exists($function, $functionCapabilities)) {
            return 'planner_script_unknown_binding';
        }
        if (!in_array($functionCapabilities[$function], $capabilities, true)) {
            return 'planner_script_missing_capability';
        }
    }
    $withoutDirectBindings = preg_replace(
        '/\bkenshi\s*\.\s*[A-Za-z_][A-Za-z0-9_]*\b/',
        '',
        $script
    );
    if (!is_string($withoutDirectBindings) || preg_match('/\bkenshi\b/', $withoutDirectBindings) === 1) {
        return 'planner_script_uses_dynamic_binding_access';
    }
    return '';
}

function stobeDirectorPlan(array $payload): array
{
    $requestId = trim(strval($payload['request_id'] ?? ''));
    $prompt = trim(strval($payload['prompt'] ?? ''));
    $apiManifest = trim(strval($payload['api_manifest'] ?? ''));
    $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
    if ($requestId === '' || strlen($requestId) > 100) {
        return ['status' => 400, 'ok' => false, 'error' => 'invalid_request_id'];
    }
    if ($prompt === '' || strlen($prompt) > 2000) {
        return ['status' => 400, 'ok' => false, 'error' => 'invalid_prompt'];
    }
    if ($apiManifest === '' || strlen($apiManifest) > 12000) {
        return ['status' => 400, 'ok' => false, 'error' => 'invalid_api_manifest'];
    }

    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contextJson)) {
        return ['status' => 400, 'ok' => false, 'error' => 'invalid_context'];
    }
    $messages = [
        [
            'role' => 'system',
            'content' => "You write a single Lua 5.4 script for STOBE's live Kenshi sandbox. Use only the exact kenshi bindings in the supplied API manifest. Never use or invent files, operating-system APIs, network APIs, packages, native modules, debug APIs, FFI, raw pointers, memory addresses, or unlisted functions. The script runs once on Kenshi's main game thread and must finish immediately: no infinite loops, polling, sleeping, or waiting. Use only player character serials supplied in context. If the request lacks required coordinates or cannot be completed with the manifest, return a harmless script that calls kenshi.notify with a short explanation. Return only JSON with this exact shape: {\"summary\":\"short description\",\"capabilities\":[\"inspect|notify|save|time|teleport|movement\"],\"script\":\"Lua source\"}. Include only capabilities actually used by kenshi calls.",
        ],
        [
            'role' => 'user',
            'content' => "REQUEST:\n{$prompt}\n\nLIVE CONTEXT:\n{$contextJson}\n\nAPI MANIFEST:\n{$apiManifest}",
        ],
    ];

    $started = microtime(true);
    if (isset($GLOBALS['stobe_director_planner_test_callback'])
        && is_callable($GLOBALS['stobe_director_planner_test_callback'])) {
        $raw = call_user_func($GLOBALS['stobe_director_planner_test_callback'], $messages, $payload);
    } else {
        require_once dirname(__DIR__) . '/connector/llm_dispatcher.php';
        $config = getLlmConfigForNpcPurpose(false, 'response');
        $config['max_tokens'] = max(600, min(1800, intval($config['max_tokens'] ?? 1200)));
        $config['temperature'] = min(0.3, max(0.0, floatval($config['temperature'] ?? 0.2)));
        $raw = stobeCallLLM($messages, $config, [
            'event_type' => 'kenshi_director',
            'response_format' => ['type' => 'json_object'],
        ]);
    }
    if ($raw === false || trim(strval($raw)) === '') {
        return ['status' => 502, 'ok' => false, 'error' => 'planner_connector_failed'];
    }
    $decoded = stobeDirectorDecodePlannerResponse(strval($raw));
    if (!$decoded) {
        return ['status' => 502, 'ok' => false, 'error' => 'planner_malformed_json'];
    }

    if (!is_string($decoded['summary'] ?? null)
        || !is_array($decoded['capabilities'] ?? null)
        || !is_string($decoded['script'] ?? null)) {
        return ['status' => 502, 'ok' => false, 'error' => 'planner_invalid_schema'];
    }
    $summary = trim($decoded['summary']);
    if ($summary === '' || strlen($summary) > 300) {
        return ['status' => 502, 'ok' => false, 'error' => 'planner_invalid_summary'];
    }
    $capabilities = stobeDirectorNormalizeCapabilities($decoded['capabilities']);
    $script = trim($decoded['script']);
    $scriptError = stobeDirectorValidateScript($script, $capabilities);
    if ($scriptError !== '') {
        return ['status' => 502, 'ok' => false, 'error' => $scriptError];
    }
    $mutatingCapabilities = ['save', 'time', 'teleport', 'movement'];
    $mutating = count(array_intersect($capabilities, $mutatingCapabilities)) > 0;

    return [
        'status' => 200,
        'ok' => true,
        'request_id' => $requestId,
        'summary' => $summary,
        'capabilities' => $capabilities,
        'mutating' => $mutating,
        'script' => $script,
        'latency_ms' => max(0, intval(round((microtime(true) - $started) * 1000))),
    ];
}
