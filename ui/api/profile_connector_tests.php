<?php

ob_start();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'llm_connector.class.php');

function stobeProfileConnectorTestsRespond(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stobeProfileConnectorTestsString($value): string
{
    return trim(strval($value ?? ''));
}

function stobeProfileConnectorTestsBoolish($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim(strval($value ?? '')));
    return $normalized !== '' && $normalized !== '0' && $normalized !== 'false' && $normalized !== 'no' && $normalized !== 'off';
}

function stobeProfileConnectorTestsProblemResult(string $type, int $id, string $status, string $message, array $details = []): array
{
    return [
        'job_key' => $type . ':' . $id,
        'type' => $type,
        'id' => $id,
        'status' => $status,
        'message' => $message,
        'details' => $details,
        'elapsed_ms' => 0,
    ];
}

function stobeProfileConnectorTestsRunWithCapturedErrors(callable $callback): array
{
    $errors = [];
    $previousHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$errors) {
        $errors[] = [
            'level' => intval($errno),
            'message' => strval($errstr),
            'file' => strval($errfile),
            'line' => intval($errline),
        ];
        return true;
    });

    try {
        $value = $callback();
    } catch (Throwable $e) {
        $errors[] = [
            'level' => E_ERROR,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $value = null;
    } finally {
        if ($previousHandler !== null) {
            set_error_handler($previousHandler);
        } else {
            restore_error_handler();
        }
    }

    return [
        'value' => $value,
        'errors' => $errors,
    ];
}

function stobeProfileConnectorTestsFirstErrorMessage(array $errors): string
{
    if (empty($errors)) {
        return '';
    }

    $message = stobeProfileConnectorTestsString($errors[0]['message'] ?? '');
    return $message !== '' ? $message : 'Unknown connector error';
}

function stobeProfileConnectorTestsEnsureOmniVoiceLanguage(string $endpoint, string $language, string $scope, array $voices = []): array
{
    $endpoint = rtrim(trim($endpoint), '/');
    $language = strtolower(trim($language));
    if ($endpoint === '' || $language === '') {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'OmniVoice endpoint or language is empty.'];
    }

    $payload = [
        'language' => $language,
        'scope' => $scope,
        'voices' => array_values(array_filter(array_map('strval', $voices), function ($voice) {
            return trim($voice) !== '';
        })),
        'make_active' => true,
        'start' => true,
    ];

    $ch = curl_init($endpoint . '/ensure_language');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['ok' => false, 'status' => 'unreachable', 'error' => $curlError ?: 'Unable to reach OmniVoice.'];
    }

    $decoded = json_decode(strval($response), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 'bad_response', 'error' => 'OmniVoice returned a non-JSON response.', 'http_code' => $httpCode];
    }
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function stobeProfileConnectorTestsPreview(string $value, int $length = 180): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function stobeProfileConnectorTestsConnectorLabel(array $row, string $fallback): string
{
    foreach (['label', 'name', 'model', 'driver', 'connector_type'] as $field) {
        $value = stobeProfileConnectorTestsString($row[$field] ?? '');
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function stobeProfileConnectorTestsBuildPlan(): array
{
    $slotDefinitions = [
        ['field' => 'tts_connector_id', 'type' => 'tts', 'label' => 'TTS Connector', 'required' => false],
        ['field' => 'response_connector', 'type' => 'llm', 'label' => 'Response LLM', 'required' => true],
        ['field' => 'diary_connector', 'type' => 'llm', 'label' => 'Diary LLM', 'required' => false],
        ['field' => 'autochat_connector', 'type' => 'llm', 'label' => 'Autochat LLM', 'required' => false],
        ['field' => 'middleterm_connector', 'type' => 'llm', 'label' => 'Middle-Term Memory LLM', 'required' => false],
        ['field' => 'backgroundlife_connector', 'type' => 'llm', 'label' => 'Background Life LLM', 'required' => false],
        ['field' => 'dynamic_connector', 'type' => 'llm', 'label' => 'Dynamic Profile LLM', 'required' => false],
        ['field' => 'relationship_connector', 'type' => 'llm', 'label' => 'Relationship LLM', 'required' => false],
    ];

    $profiles = getAllCoreProfiles();
    $jobs = [];
    $profileRows = [];

    foreach ($profiles as $profile) {
        $profileId = intval($profile['id'] ?? 0);
        $slots = [];

        foreach ($slotDefinitions as $definition) {
            $connectorId = intval($profile[$definition['field']] ?? 0);
            if ($connectorId <= 0) {
                $slots[] = [
                    'field' => $definition['field'],
                    'type' => $definition['type'],
                    'label' => $definition['label'],
                    'required' => $definition['required'],
                    'connector_id' => null,
                    'job_key' => null,
                    'status' => 'skipped',
                    'message' => 'No connector selected',
                ];
                continue;
            }

            $jobKey = $definition['type'] . ':' . $connectorId;
            $jobs[$jobKey] = [
                'job_key' => $jobKey,
                'type' => $definition['type'],
                'id' => $connectorId,
            ];
            $slots[] = [
                'field' => $definition['field'],
                'type' => $definition['type'],
                'label' => $definition['label'],
                'required' => $definition['required'],
                'connector_id' => $connectorId,
                'job_key' => $jobKey,
                'status' => 'pending',
                'message' => 'Waiting to test',
            ];
        }

        $profileRows[] = [
            'id' => $profileId,
            'label' => stobeProfileConnectorTestsString($profile['label'] ?? ("Profile #{$profileId}")),
            'default_npc' => stobeProfileConnectorTestsBoolish($profile['is_default_npc'] ?? false),
            'player_faction' => stobeProfileConnectorTestsBoolish($profile['is_player_faction_profile'] ?? false),
            'slots' => $slots,
        ];
    }

    return [
        'profiles' => $profileRows,
        'jobs' => array_values($jobs),
    ];
}

function stobeProfileConnectorTestsLlmRequiresApiKey(array $row): bool
{
    $driver = strtolower(stobeProfileConnectorTestsString($row['driver'] ?? $row['connector_type'] ?? ''));
    $service = strtolower(stobeProfileConnectorTestsString($row['service'] ?? ''));
    $provider = strtolower(stobeProfileConnectorTestsString($row['provider'] ?? ''));
    $url = strtolower(stobeProfileConnectorTestsString($row['url'] ?? $row['base_url'] ?? ''));

    if (in_array($driver, ['openrouterjson', 'google_openaijson', 'groqjson'], true)) {
        return true;
    }
    if ($driver === 'openaijson' && in_array($service, ['openai', 'openrouter', 'groq', 'google'], true)) {
        return true;
    }

    foreach (['api.openai.com', 'openrouter.ai', 'groq.com', 'generativelanguage.googleapis.com'] as $needle) {
        if (strpos($url, $needle) !== false) {
            return true;
        }
    }

    return in_array($provider, ['openai', 'openrouter', 'groq', 'google'], true);
}

function stobeProfileConnectorTestsTestLlm(int $connectorId): array
{
    $started = microtime(true);
    $llm = new LLMConnector();
    $connector = $llm->getById($connectorId);
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return stobeProfileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector was not found');
    }

    $driver = stobeProfileConnectorTestsString($connector['driver'] ?? $connector['connector_type'] ?? '');
    $details = [
        'label' => stobeProfileConnectorTestsConnectorLabel($connector, "LLM connector #{$connectorId}"),
        'driver' => $driver,
        'model' => stobeProfileConnectorTestsString($connector['model'] ?? ''),
        'url' => stobeProfileConnectorTestsString($connector['url'] ?? $connector['base_url'] ?? ''),
    ];
    $apiBadgeLabel = stobeProfileConnectorTestsString($connector['api_badge_label'] ?? '');
    if ($apiBadgeLabel !== '') {
        $details['api_badge'] = $apiBadgeLabel;
    }

    if ($driver === '') {
        return stobeProfileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector has no driver selected', $details);
    }

    if ($details['model'] === '') {
        return stobeProfileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector has no model configured', $details);
    }

    if (stobeProfileConnectorTestsLlmRequiresApiKey($connector) && stobeProfileConnectorTestsString($connector['api_key'] ?? '') === '') {
        return stobeProfileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector has no API key configured', $details);
    }

    $run = stobeProfileConnectorTestsRunWithCapturedErrors(function () use ($llm, $connector) {
        $GLOBALS['HERIKA_NAME'] = 'STOBE Profile Test';
        $GLOBALS['PLAYER_NAME'] = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        $GLOBALS['DEBUG_DATA'] = [];
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = '';

        $llm->setOldGlobals($connector);
        $driver = $llm->getConnector($connector);
        $messages = [
            ['role' => 'system', 'content' => 'You are a connection health check. Reply with OK.'],
            ['role' => 'user', 'content' => 'Reply with exactly OK.'],
        ];

        return trim(strval($driver->fast_request($messages, ['max_tokens' => 32, 'temperature' => 0], 'stobe_profile_connector_test')));
    });

    $elapsedMs = intval(round((microtime(true) - $started) * 1000));
    $response = stobeProfileConnectorTestsString($run['value'] ?? '');
    if ($response === '') {
        $message = stobeProfileConnectorTestsFirstErrorMessage($run['errors']);
        if ($message === '') {
            $message = 'LLM test returned an empty response';
        }

        return [
            'job_key' => 'llm:' . $connectorId,
            'type' => 'llm',
            'id' => $connectorId,
            'status' => 'fail',
            'message' => $message,
            'details' => $details + ['errors' => $run['errors']],
            'elapsed_ms' => $elapsedMs,
        ];
    }

    return [
        'job_key' => 'llm:' . $connectorId,
        'type' => 'llm',
        'id' => $connectorId,
        'status' => empty($run['errors']) ? 'pass' : 'warn',
        'message' => empty($run['errors']) ? 'LLM responded successfully' : stobeProfileConnectorTestsFirstErrorMessage($run['errors']),
        'details' => $details + ['response_preview' => stobeProfileConnectorTestsPreview($response), 'errors' => $run['errors']],
        'elapsed_ms' => $elapsedMs,
    ];
}

function stobeProfileConnectorTestsTestTts(int $connectorId): array
{
    $started = microtime(true);
    $connector = getTtsConnectorById($connectorId);
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector was not found');
    }

    $runtime = stobeResolveTtsRuntimeFromConnector($connector, 'male1');
    $driver = stobeProfileConnectorTestsString($runtime['provider'] ?? $connector['connector_type'] ?? '');
    $details = [
        'label' => stobeProfileConnectorTestsConnectorLabel($connector, "TTS connector #{$connectorId}"),
        'driver' => $driver,
        'url' => stobeProfileConnectorTestsString($runtime['endpoint'] ?? $connector['base_url'] ?? ''),
        'voice' => stobeProfileConnectorTestsString($runtime['voiceid'] ?? 'male1'),
    ];

    if (!stobeProfileConnectorTestsBoolish($runtime['enabled'] ?? true)) {
        return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'skipped', 'TTS connector is disabled', $details);
    }

    if ($driver === '') {
        return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector has no driver selected', $details);
    }

    if (in_array($driver, ['cartesia', 'inworld'], true) && stobeProfileConnectorTestsString($runtime['api_key'] ?? '') === '') {
        return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector has no API key configured', $details);
    }

    if ($details['url'] === '') {
        return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector has no endpoint URL configured', $details);
    }

    if ($driver === 'omnivoice') {
        $language = strtolower(stobeProfileConnectorTestsString($runtime['language'] ?? ''));
        $details['language'] = $language;
        $ensure = stobeProfileConnectorTestsEnsureOmniVoiceLanguage($details['url'], $language, 'voice_set', [
            stobeProfileConnectorTestsString($runtime['fallback_male'] ?? ''),
            stobeProfileConnectorTestsString($runtime['fallback_female'] ?? ''),
            stobeProfileConnectorTestsString($runtime['voiceid'] ?? ''),
        ]);
        $details['omnivoice_prepare'] = $ensure;
        $ensureStatus = strtolower(stobeProfileConnectorTestsString($ensure['status'] ?? ''));
        if (!($ensure['ok'] ?? false)) {
            return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'warn', 'OmniVoice language preparation could not be checked: ' . stobeProfileConnectorTestsString($ensure['error'] ?? 'unknown error'), $details);
        }
        if ($ensureStatus !== 'ready') {
            return stobeProfileConnectorTestsProblemResult('tts', $connectorId, 'warn', 'OmniVoice ' . ($language !== '' ? $language : 'language') . ' is preparing; test again after the background job finishes.', $details);
        }
    }

    $run = stobeProfileConnectorTestsRunWithCapturedErrors(function () use ($connector, $runtime) {
        $voiceId = stobeProfileConnectorTestsString($runtime['voiceid'] ?? 'male1');
        return stobeSynthesizeTtsFromConnector($connector, 'STOBE profile connector test.', $voiceId !== '' ? $voiceId : 'male1');
    });

    $elapsedMs = intval(round((microtime(true) - $started) * 1000));
    $result = is_array($run['value'] ?? null) ? $run['value'] : [];
    $audioPath = stobeProfileConnectorTestsString($result['audio_path'] ?? '');
    if ($audioPath === '') {
        $message = stobeProfileConnectorTestsFirstErrorMessage($run['errors']);
        if ($message === '') {
            $message = 'TTS synthesis failed';
        }

        return [
            'job_key' => 'tts:' . $connectorId,
            'type' => 'tts',
            'id' => $connectorId,
            'status' => 'fail',
            'message' => $message,
            'details' => $details + ['errors' => $run['errors']],
            'elapsed_ms' => $elapsedMs,
        ];
    }

    return [
        'job_key' => 'tts:' . $connectorId,
        'type' => 'tts',
        'id' => $connectorId,
        'status' => empty($run['errors']) ? 'pass' : 'warn',
        'message' => empty($run['errors']) ? 'TTS produced audio successfully' : stobeProfileConnectorTestsFirstErrorMessage($run['errors']),
        'details' => $details + [
            'generated_file' => basename($audioPath),
            'duration_ms' => intval($result['duration_ms'] ?? 0),
            'cached' => stobeProfileConnectorTestsBoolish($result['cached'] ?? false),
            'errors' => $run['errors'],
        ],
        'elapsed_ms' => $elapsedMs,
    ];
}

$action = stobeProfileConnectorTestsString($_GET['action'] ?? $_POST['action'] ?? 'plan');

try {
    if ($action === 'plan') {
        stobeProfileConnectorTestsRespond([
            'ok' => true,
            'plan' => stobeProfileConnectorTestsBuildPlan(),
        ]);
    }

    if ($action === 'test') {
        $type = strtolower(stobeProfileConnectorTestsString($_GET['type'] ?? $_POST['type'] ?? ''));
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!in_array($type, ['llm', 'tts'], true) || $id <= 0) {
            stobeProfileConnectorTestsRespond([
                'ok' => false,
                'error' => 'Invalid connector test request',
            ], 400);
        }

        $result = $type === 'llm'
            ? stobeProfileConnectorTestsTestLlm($id)
            : stobeProfileConnectorTestsTestTts($id);

        stobeProfileConnectorTestsRespond([
            'ok' => true,
            'result' => $result,
        ]);
    }

    stobeProfileConnectorTestsRespond([
        'ok' => false,
        'error' => 'Unknown action',
    ], 400);
} catch (Throwable $e) {
    stobeProfileConnectorTestsRespond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
