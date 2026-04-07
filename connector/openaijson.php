<?php

/**
 * OpenAI-compatible LLM connector.
 * Works with OpenRouter, OpenAI, Ollama, LM Studio, etc.
 */

function stobeIsReasoningModel(string $model): bool {
    $modelLower = strtolower($model);
    $needles = [
        'deepseek-r',
        'qwq-32b',
        'qwq-max',
        '-thinking',
        ':thinking',
        '-reasoning',
        'grok-3-mini',
        'sonar-deep-research',
        'r1-1776',
        'o1',
        'o3',
        'o4',
    ];

    foreach ($needles as $needle) {
        if (strpos($modelLower, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function stobeIsOpenAiModel(string $model): bool {
    $modelLower = strtolower(trim($model));
    if ($modelLower === '') {
        return false;
    }

    $openAiPrefixes = ['gpt-', 'o1', 'o3', 'o4', 'chatgpt-'];
    foreach ($openAiPrefixes as $prefix) {
        if (strpos($modelLower, $prefix) === 0) {
            return true;
        }
    }

    return strpos($modelLower, 'openai/') === 0;
}

function stobeParseConnectorExtras(mixed $rawConfig): array {
    $config = [];
    if (is_array($rawConfig)) {
        $config = $rawConfig;
    } else {
        $decoded = json_decode(strval($rawConfig), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    $extras = [];

    $topP = $config['top_p'] ?? null;
    if ($topP !== null && is_numeric($topP)) {
        $extras['top_p'] = max(0.0, min(1.0, floatval($topP)));
    } else {
        $extras['top_p'] = 0.9;
    }

    $topK = $config['top_k'] ?? null;
    if ($topK !== null && is_numeric($topK)) {
        $extras['top_k'] = max(0, intval($topK));
    }

    $repetitionPenalty = $config['repetition_penalty'] ?? null;
    if ($repetitionPenalty !== null && is_numeric($repetitionPenalty)) {
        $extras['repetition_penalty'] = max(0.0, floatval($repetitionPenalty));
    }

    $frequencyPenalty = $config['frequency_penalty'] ?? null;
    if ($frequencyPenalty !== null && is_numeric($frequencyPenalty)) {
        $extras['frequency_penalty'] = floatval($frequencyPenalty);
    }

    $presencePenalty = $config['presence_penalty'] ?? null;
    if ($presencePenalty !== null && is_numeric($presencePenalty)) {
        $extras['presence_penalty'] = floatval($presencePenalty);
    }

    return $extras;
}

function stobeParseExtraHeaders(array $connectorConfig): array {
    $rawHeaders = $connectorConfig['extra_headers'] ?? ($connectorConfig['headers'] ?? []);
    if (is_string($rawHeaders)) {
        $decoded = json_decode($rawHeaders, true);
        if (is_array($decoded)) {
            $rawHeaders = $decoded;
        }
    }
    if (!is_array($rawHeaders)) {
        return [];
    }

    $parsed = [];
    foreach ($rawHeaders as $key => $value) {
        if (!is_int($key)) {
            $headerName = trim(strval($key));
            $headerValue = trim(strval($value));
            if ($headerName !== '' && $headerValue !== '') {
                $parsed[] = $headerName . ': ' . $headerValue;
            }
            continue;
        }

        $line = trim(strval($value));
        if ($line !== '' && strpos($line, ':') !== false) {
            $parsed[] = $line;
        }
    }

    return $parsed;
}

function stobeBuildLlmRequestHeaders(
    string $apiKey,
    array $connectorConfig,
    string $connectorType,
    bool $forStreaming
): array {
    $headers = ["Content-Type: application/json"];
    if ($forStreaming) {
        $headers[] = "Accept: text/event-stream";
        $headers[] = "Cache-Control: no-cache";
    }

    $disableBrandingHeaders = boolval($connectorConfig['disable_branding_headers'] ?? false);
    if (!$disableBrandingHeaders) {
        $headers[] = "HTTP-Referer: https://dwemerdynamics.com";
        $headers[] = "X-Title: Dwemer Dynamics";
    }

    $player2Key = trim(strval(
        $connectorConfig['player2_game_key'] ?? ($connectorConfig['game_key'] ?? '')
    ));
    if ($connectorType === 'player2json') {
        if ($player2Key === '' && $apiKey !== '') {
            $player2Key = $apiKey;
        }
        if ($player2Key !== '') {
            $headers[] = "player2-game-key: {$player2Key}";
        }
        if (boolval($connectorConfig['force_bearer_auth'] ?? false) && $apiKey !== '') {
            $headers[] = "Authorization: Bearer {$apiKey}";
        }
    } elseif ($apiKey !== '') {
        $headers[] = "Authorization: Bearer {$apiKey}";
    }

    foreach (stobeParseExtraHeaders($connectorConfig) as $headerLine) {
        $headers[] = $headerLine;
    }

    return $headers;
}

function stobeExtractMessageContent(array $choice): string {
    $message = $choice['message'] ?? [];
    $content = $message['content'] ?? null;

    if (is_string($content)) {
        return $content;
    }

    if (is_array($content)) {
        $segments = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $segments[] = $part;
                continue;
            }
            if (is_array($part)) {
                $segmentText = strval($part['text'] ?? '');
                if ($segmentText !== '') {
                    $segments[] = $segmentText;
                }
            }
        }
        return implode('', $segments);
    }

    $reasoningContent = $message['reasoning_content'] ?? null;
    if (is_string($reasoningContent)) {
        return $reasoningContent;
    }

    return '';
}

function stobeExtractDeltaContent(array $choice): string {
    $delta = $choice['delta'] ?? [];
    $content = $delta['content'] ?? null;

    if (is_string($content)) {
        return $content;
    }

    if (is_array($content)) {
        $segments = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $segments[] = $part;
                continue;
            }
            if (is_array($part)) {
                $segmentText = strval($part['text'] ?? '');
                if ($segmentText !== '') {
                    $segments[] = $segmentText;
                }
            }
        }
        return implode('', $segments);
    }

    return '';
}

function stobeRecordLlmAudit(string $npcName, string $model, int $promptTokens, int $completionTokens): void {
    try {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }
        $db->exec(
            "INSERT INTO audit_llm (npc_name, model, prompt_tokens, completion_tokens, localts)
             VALUES ($1, $2, $3, $4, $5)",
            [$npcName, $model, $promptTokens, $completionTokens, time()]
        );
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to record LLM audit row', [
            'error' => $exception->getMessage(),
            'model' => $model,
            'npc_name' => $npcName,
        ]);
    }
}

function stobeBuildPromptLogPayload(array $payload): string {
    $wrapped = ['full' => $payload];
    $export = var_export($wrapped, true);
    if (is_string($export) && trim($export) !== '') {
        return $export;
    }
    $fallback = json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($fallback) ? $fallback : '{}';
}

function stobeBuildHttpRequestLogField(
    string $url,
    string $model,
    array $usage,
    int $httpCode,
    string $connectorType,
    array $meta = []
): string {
    $parts = [];
    $parts[] = $url;
    if ($model !== '') {
        $parts[] = 'model=' . $model;
    }
    if ($connectorType !== '') {
        $parts[] = 'connector=' . $connectorType;
    }
    if ($httpCode > 0) {
        $parts[] = 'http=' . strval($httpCode);
    }
    $requestId = strval($GLOBALS['__stobe_request_id'] ?? '');
    if ($requestId !== '') {
        $parts[] = 'request_id=' . $requestId;
    }
    $eventType = trim(strval($meta['event_type'] ?? ''));
    if ($eventType !== '') {
        $parts[] = 'event_type=' . $eventType;
    }
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    if ($promptTokens > 0 || $completionTokens > 0) {
        $parts[] = '[prompt_tokens]=' . strval($promptTokens);
        $parts[] = '[completion_tokens]=' . strval($completionTokens);
        $parts[] = '[total_tokens]=' . strval($promptTokens + $completionTokens);
    }
    return implode(' | ', $parts);
}

function stobeRecordLlmResponseLog(string $promptPayload, string $responseText, string $urlField): void {
    try {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }
        $db->exec(
            "INSERT INTO log (localts, prompt, response, url)
             VALUES ($1, $2, $3, $4)",
            [time(), $promptPayload, $responseText, $urlField]
        );
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to record LLM response log row', [
            'error' => $exception->getMessage(),
        ]);
    }
}

function stobeGetLlmDebugLogPath(string $filename): string {
    if (function_exists('stobeGetLogPath')) {
        return stobeGetLogPath($filename);
    }
    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $logDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    return $logDir . $filename;
}

function stobeShouldLogContextPayload(): bool {
    if (function_exists('getSettingBool')) {
        // Default ON for debugging parity with Herika-style context tracing.
        return getSettingBool('LOG_CONTEXT_PAYLOAD', true);
    }
    return true;
}

function stobeAppendLlmDebugLog(string $filename, string $label, array $payload): void {
    $logPath = stobeGetLlmDebugLogPath($filename);
    $stamp = date(DATE_ATOM);

    // Keep context logs in Herika-style var_export blocks so multiline XML prompt
    // content remains readable instead of escaped JSON.
    if ($filename === 'context_sent_to_llm.log') {
        if (!stobeShouldLogContextPayload()) {
            return;
        }
        $body = var_export($payload, true);
        if (!is_string($body) || $body === '') {
            $body = "array (\n  '__export_error' => 'context_export_failed',\n)";
        }
        $header = $stamp . "\n=\n";
        if (trim($label) !== '') {
            $header .= "label: " . $label . "\n";
        }
        @file_put_contents($logPath, $header . $body . "\n=\n", FILE_APPEND | LOCK_EX);
        return;
    }

    // Mirror Herika-style output log framing so raw responses are easier to scan.
    if ($filename === 'output_from_llm.log') {
        $header = "\n== " . $stamp . " START";
        if (trim($label) !== '') {
            $header .= " [" . $label . "]";
        }
        $header .= "\n\n";
        $body = var_export($payload, true);
        $footer = "\n\n== " . $stamp . " END\n\n";
        @file_put_contents($logPath, $header . $body . $footer, FILE_APPEND | LOCK_EX);
        return;
    }

    $timestamp = gmdate('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] [INFO] ' . $label;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (is_string($json) && $json !== '') {
        $line .= ' | ' . $json;
    }
    $line .= PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function stobeIsFastLlmEventType(string $eventType): bool {
    $normalized = strtolower(trim($eventType));
    if ($normalized === '') {
        return false;
    }

    $knownFastTypes = [
        'autochat_rewrite',
        'relationship_eval',
        'relationship_llm',
        'relationship_analyze',
        'relationship_batch',
        'relationship_infer',
    ];
    if (in_array($normalized, $knownFastTypes, true)) {
        return true;
    }

    return strpos($normalized, 'fast') !== false || strpos($normalized, 'relationship') === 0;
}

function stobeAppendContextRequestLog(string $label, array $payload, bool $forceFastLog = false): void {
    stobeAppendLlmDebugLog('context_sent_to_llm.log', $label, $payload);
    $eventType = strval($payload['event_type'] ?? '');
    if ($forceFastLog || stobeIsFastLlmEventType($eventType)) {
        stobeAppendLlmDebugLog('context_sent_to_llm_fast.log', $label, $payload);
    }
}

function stobeSerializeAuditPayload(mixed $value, int $maxLen = 24000): string {
    if (is_string($value)) {
        $serialized = $value;
    } else {
        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($serialized) || $serialized === '') {
            $serialized = var_export($value, true);
            if (!is_string($serialized) || $serialized === '') {
                $serialized = '';
            }
        }
    }

    if ($maxLen > 0 && strlen($serialized) > $maxLen) {
        $serialized = substr($serialized, 0, $maxLen) . '...';
    }
    return $serialized;
}

function stobeRecordAuditRequest(array $entry): void {
    $requestId = trim(strval($entry['request_id'] ?? ($GLOBALS['__stobe_request_id'] ?? '')));
    $eventType = trim(strval($entry['event_type'] ?? ''));
    $npcName = trim(strval($entry['npc_name'] ?? ''));
    $connectorType = trim(strval($entry['connector_type'] ?? ''));
    $model = trim(strval($entry['model'] ?? ''));
    $url = trim(strval($entry['url'] ?? ''));
    $httpCode = intval($entry['http_code'] ?? 0);
    $durationMs = intval($entry['duration_ms'] ?? 0);
    if ($durationMs < 0) {
        $durationMs = 0;
    }
    $isStream = boolval($entry['is_stream'] ?? false);
    $status = strtolower(trim(strval($entry['status'] ?? 'ok')));
    if (!in_array($status, ['ok', 'error'], true)) {
        $status = 'ok';
    }
    $error = trim(strval($entry['error'] ?? ''));
    $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    $totalTokens = intval($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
    if ($totalTokens <= 0 && ($promptTokens > 0 || $completionTokens > 0)) {
        $totalTokens = $promptTokens + $completionTokens;
    }

    $requestPayload = stobeSerializeAuditPayload($entry['request_payload'] ?? [], 24000);
    $resultPayload = stobeSerializeAuditPayload($entry['result_payload'] ?? '', 24000);

    try {
        $db = $GLOBALS['db'] ?? null;
        if ($db) {
            $db->exec(
                "INSERT INTO audit_request (
                    localts,
                    request_id,
                    event_type,
                    npc_name,
                    connector,
                    model,
                    url,
                    request,
                    result,
                    http_code,
                    duration_ms,
                    is_stream,
                    prompt_tokens,
                    completion_tokens,
                    total_tokens,
                    status,
                    error
                ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17
                )",
                [
                    time(),
                    $requestId,
                    $eventType,
                    $npcName,
                    $connectorType,
                    $model,
                    $url,
                    $requestPayload,
                    $resultPayload,
                    $httpCode,
                    $durationMs,
                    $isStream,
                    $promptTokens,
                    $completionTokens,
                    $totalTokens,
                    $status,
                    $error,
                ]
            );
        }
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to persist audit_request row', [
            'error' => $exception->getMessage(),
            'request_id' => $requestId,
            'event_type' => $eventType,
            'npc_name' => $npcName,
            'model' => $model,
        ]);
    }

    $filePayload = [
        'request_id' => $requestId,
        'event_type' => $eventType,
        'npc_name' => $npcName,
        'connector_type' => $connectorType,
        'model' => $model,
        'status' => $status,
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'is_stream' => $isStream,
        'usage' => [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ],
        'error' => $error,
        'url' => $url,
        'request' => $requestPayload,
        'result' => $resultPayload,
    ];
    stobeAppendLlmDebugLog('audit_request.log', 'audit_request', $filePayload);
}

function callLLM(array $messages, array $config, array $meta = []): string|false {
    $apiKey = $config['api_key'] ?? '';
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $model = $config['model'] ?? '';
    $maxTokens = $config['max_tokens'] ?? 2048;
    $temperature = $config['temperature'] ?? 0.8;
    $connectorConfig = $config['config'] ?? [];
    $connectorType = strtolower(trim(strval($config['connector_type'] ?? 'openaijson')));

    if (!is_array($connectorConfig)) {
        $decodedConfig = json_decode(strval($connectorConfig), true);
        $connectorConfig = is_array($decodedConfig) ? $decodedConfig : [];
    }

    if ($baseUrl === '') {
        $baseUrl = 'https://openrouter.ai/api/v1';
    }
    if ($model === '') {
        $model = 'openrouter/auto';
    }

    $requestStartedAt = microtime(true);
    $url = "{$baseUrl}/chat/completions";
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'stream' => false,
    ];

    if (stobeIsOpenAiModel($model)) {
        $payload['max_completion_tokens'] = intval($maxTokens);
    } else {
        $payload['max_tokens'] = intval($maxTokens);
    }
    if (is_array($meta['response_format'] ?? null)) {
        $payload['response_format'] = $meta['response_format'];
    }

    $extraPayload = stobeParseConnectorExtras($connectorConfig);
    foreach ($extraPayload as $key => $value) {
        $payload[$key] = $value;
    }

    if (stobeIsReasoningModel($model)) {
        $payload['reasoning'] = ['exclude' => true];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        stobeLogError('Failed to encode LLM payload', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['error' => 'payload_encode_failed'],
            'http_code' => 0,
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => 'payload_encode_failed',
        ]);
        return false;
    }

    stobeLogInfo('LLM request dispatch', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'message_count' => count($messages),
        'max_tokens' => intval($maxTokens),
        'response_format' => is_array($meta['response_format'] ?? null)
            ? strval($meta['response_format']['type'] ?? 'custom')
            : '',
    ]);

    $forceFastLog = boolval($meta['__stobe_force_fast_log'] ?? false);
    stobeAppendContextRequestLog('llm_request', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'url' => $url,
        'payload' => $payload,
    ], $forceFastLog);

    $headers = stobeBuildLlmRequestHeaders($apiKey, $connectorConfig, $connectorType, false);

    $timeoutSeconds = function_exists('getSettingInt') ? getSettingInt('HTTP_TIMEOUT', 60) : 60;
    if ($timeoutSeconds < 5) {
        $timeoutSeconds = 5;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (($httpCode !== 200 || $response === false) && isset($payload['response_format'])) {
        stobeLogWarn('LLM request failed with response_format; retrying without response_format', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
        ]);

        unset($payload['response_format']);
        $retryPayloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($retryPayloadJson) && $retryPayloadJson !== '') {
            stobeAppendContextRequestLog('llm_request_retry_no_response_format', [
                'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
                'event_type' => strval($meta['event_type'] ?? ''),
                'npc_name' => strval($meta['npc_name'] ?? ''),
                'connector_type' => $connectorType,
                'model' => $model,
                'base_url' => $baseUrl,
                'url' => $url,
                'payload' => $payload,
            ], $forceFastLog);

            $retryCh = curl_init($url);
            curl_setopt_array($retryCh, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $retryPayloadJson,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);
            $response = curl_exec($retryCh);
            $httpCode = curl_getinfo($retryCh, CURLINFO_HTTP_CODE);
            $curlError = curl_error($retryCh);
            curl_close($retryCh);
        }
    }

    if ($httpCode !== 200 || $response === false) {
        $responsePreview = is_string($response) ? substr($response, 0, 400) : '';
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_error', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => is_string($response) ? $response : '',
        ]);
        stobeLogError('LLM request failed', [
            'http_code' => $httpCode,
            'base_url' => $baseUrl,
            'model' => $model,
            'curl_error' => $curlError,
            'response_preview' => $responsePreview,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => [
                'curl_error' => $curlError,
                'response_preview' => $responsePreview,
            ],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => $curlError !== '' ? $curlError : 'http_error',
        ]);
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_invalid_json', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response' => $response,
        ]);
        stobeLogError('LLM response was not valid JSON', [
            'model' => $model,
            'response_preview' => substr($response, 0, 400),
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_preview' => substr($response, 0, 1000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => 'invalid_json_response',
        ]);
        return false;
    }

    $usage = $data['usage'] ?? [];
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    stobeRecordLlmAudit(
        strval($meta['npc_name'] ?? ''),
        $model,
        $promptTokens,
        $completionTokens
    );

    $choices = $data['choices'] ?? [];
    if (empty($choices)) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_no_choices', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response_json' => $data,
        ]);
        stobeLogWarn('LLM response had no choices', [
            'http_code' => $httpCode,
            'model' => $model,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_json' => $data],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'no_choices',
        ]);
        return false;
    }

    $content = stobeExtractMessageContent($choices[0]);

    if (trim($content) === '') {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_empty_content', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response_json' => $data,
        ]);
        stobeLogWarn('LLM content was empty after parse', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_json' => $data],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'empty_content',
        ]);
        return false;
    }

    stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'usage' => is_array($usage) ? $usage : [],
        'response_text' => $content,
        'response_json' => $data,
    ]);

    $speaker = trim(strval($meta['npc_name'] ?? ''));
    $loggedResponseText = $speaker !== '' ? ($speaker . ': ' . $content) : $content;
    stobeRecordLlmResponseLog(
        stobeBuildPromptLogPayload($payload),
        $loggedResponseText,
        stobeBuildHttpRequestLogField($url, $model, is_array($usage) ? $usage : [], intval($httpCode), $connectorType, $meta)
    );

    stobeRecordAuditRequest([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'url' => $url,
        'request_payload' => $payload,
        'result_payload' => ['response_text' => $content],
        'http_code' => intval($httpCode),
        'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
        'is_stream' => false,
        'usage' => is_array($usage) ? $usage : [],
        'status' => 'ok',
    ]);

    return $content;
}

function callLLMStream(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    $apiKey = $config['api_key'] ?? '';
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $model = $config['model'] ?? '';
    $maxTokens = $config['max_tokens'] ?? 2048;
    $temperature = $config['temperature'] ?? 0.8;
    $connectorConfig = $config['config'] ?? [];
    $connectorType = strtolower(trim(strval($config['connector_type'] ?? 'openaijson')));

    if (!is_array($connectorConfig)) {
        $decodedConfig = json_decode(strval($connectorConfig), true);
        $connectorConfig = is_array($decodedConfig) ? $decodedConfig : [];
    }

    if ($baseUrl === '') {
        $baseUrl = 'https://openrouter.ai/api/v1';
    }
    if ($model === '') {
        $model = 'openrouter/auto';
    }

    $requestStartedAt = microtime(true);
    $url = "{$baseUrl}/chat/completions";
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'stream' => true,
    ];

    if (stobeIsOpenAiModel($model)) {
        $payload['max_completion_tokens'] = intval($maxTokens);
    } else {
        $payload['max_tokens'] = intval($maxTokens);
    }

    if (is_array($meta['response_format'] ?? null)) {
        $payload['response_format'] = $meta['response_format'];
    }

    $extraPayload = stobeParseConnectorExtras($connectorConfig);
    foreach ($extraPayload as $key => $value) {
        $payload[$key] = $value;
    }

    if (stobeIsReasoningModel($model)) {
        $payload['reasoning'] = ['exclude' => true];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        stobeLogError('Failed to encode streaming LLM payload', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['error' => 'payload_encode_failed'],
            'http_code' => 0,
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'status' => 'error',
            'error' => 'payload_encode_failed',
        ]);
        return false;
    }

    stobeLogInfo('LLM stream request dispatch', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'message_count' => count($messages),
        'max_tokens' => intval($maxTokens),
        'response_format' => is_array($meta['response_format'] ?? null)
            ? strval($meta['response_format']['type'] ?? 'custom')
            : '',
    ]);

    $forceFastLog = boolval($meta['__stobe_force_fast_log'] ?? false);
    stobeAppendContextRequestLog('llm_stream_request', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'url' => $url,
        'payload' => $payload,
    ], $forceFastLog);
    $headers = stobeBuildLlmRequestHeaders($apiKey, $connectorConfig, $connectorType, true);

    $timeoutSeconds = function_exists('getSettingInt') ? getSettingInt('HTTP_TIMEOUT', 60) : 60;
    if ($timeoutSeconds < 15) {
        $timeoutSeconds = 15;
    }

    $streamBuffer = '';
    $rawStream = '';
    $fullContent = '';
    $usage = [];
    $streamDone = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_WRITEFUNCTION => function ($curlHandle, string $data) use (
            &$streamBuffer,
            &$rawStream,
            &$fullContent,
            &$usage,
            &$streamDone,
            $onTextDelta
        ): int {
            $rawStream .= $data;
            $streamBuffer .= $data;

            while (true) {
                $newlinePos = strpos($streamBuffer, "\n");
                if ($newlinePos === false) {
                    break;
                }

                $line = substr($streamBuffer, 0, $newlinePos);
                $streamBuffer = substr($streamBuffer, $newlinePos + 1);
                $line = trim(strval($line), "\r\n");
                if ($line === '') {
                    continue;
                }
                if (stripos($line, 'data:') !== 0) {
                    continue;
                }

                $chunkPayload = trim(substr($line, 5));
                if ($chunkPayload === '' || $chunkPayload === '[DONE]') {
                    if ($chunkPayload === '[DONE]') {
                        $streamDone = true;
                    }
                    continue;
                }

                $chunkData = json_decode($chunkPayload, true);
                if (!is_array($chunkData)) {
                    continue;
                }

                if (is_array($chunkData['usage'] ?? null)) {
                    $usage = $chunkData['usage'];
                }

                $choices = $chunkData['choices'] ?? [];
                if (!is_array($choices) || count($choices) === 0 || !is_array($choices[0] ?? null)) {
                    continue;
                }

                $deltaText = stobeExtractDeltaContent($choices[0]);
                if ($deltaText === '') {
                    $messageContent = stobeExtractMessageContent($choices[0]);
                    if ($messageContent !== '') {
                        $deltaText = $messageContent;
                    }
                }
                if ($deltaText === '') {
                    continue;
                }

                $fullContent .= $deltaText;
                try {
                    $onTextDelta($deltaText);
                } catch (Throwable $callbackException) {
                    stobeLogWarn('LLM stream text callback threw exception', [
                        'error' => $callbackException->getMessage(),
                    ]);
                }
            }

            return strlen($data);
        },
    ]);

    $execResult = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $execResult === false) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response_error', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'raw_stream_preview' => substr($rawStream, 0, 4000),
        ]);
        stobeLogError('LLM stream request failed', [
            'http_code' => $httpCode,
            'base_url' => $baseUrl,
            'model' => $model,
            'curl_error' => $curlError,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['raw_stream_preview' => substr($rawStream, 0, 2000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'status' => 'error',
            'error' => $curlError !== '' ? $curlError : 'http_error',
        ]);
        return false;
    }

    // Fallback: some providers may ignore stream and return a single JSON body.
    if (trim($fullContent) === '' && trim($rawStream) !== '') {
        $maybeJson = json_decode($rawStream, true);
        if (is_array($maybeJson)) {
            $choices = $maybeJson['choices'] ?? [];
            if (is_array($choices) && count($choices) > 0 && is_array($choices[0] ?? null)) {
                $fullContent = stobeExtractMessageContent($choices[0]);
                if (trim($fullContent) !== '') {
                    try {
                        $onTextDelta($fullContent);
                    } catch (Throwable $callbackException) {
                        stobeLogWarn('LLM stream fallback callback threw exception', [
                            'error' => $callbackException->getMessage(),
                        ]);
                    }
                }
            }
            if (is_array($maybeJson['usage'] ?? null)) {
                $usage = $maybeJson['usage'];
            }
        }
    }

    if (trim($fullContent) === '') {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response_empty_content', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'stream_done' => $streamDone,
            'raw_stream_preview' => substr($rawStream, 0, 4000),
        ]);
        stobeLogWarn('LLM stream content was empty after parse', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['raw_stream_preview' => substr($rawStream, 0, 2000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'empty_content',
        ]);
        return false;
    }

    stobeRecordLlmAudit(
        strval($meta['npc_name'] ?? ''),
        $model,
        intval($usage['prompt_tokens'] ?? 0),
        intval($usage['completion_tokens'] ?? 0)
    );

    stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'usage' => is_array($usage) ? $usage : [],
        'response_text' => $fullContent,
    ]);

    $speaker = trim(strval($meta['npc_name'] ?? ''));
    $loggedResponseText = $speaker !== '' ? ($speaker . ': ' . $fullContent) : $fullContent;
    stobeRecordLlmResponseLog(
        stobeBuildPromptLogPayload($payload),
        $loggedResponseText,
        stobeBuildHttpRequestLogField($url, $model, is_array($usage) ? $usage : [], intval($httpCode), $connectorType, $meta)
    );

    stobeRecordAuditRequest([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'url' => $url,
        'request_payload' => $payload,
        'result_payload' => ['response_text' => $fullContent],
        'http_code' => intval($httpCode),
        'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
        'is_stream' => true,
        'usage' => is_array($usage) ? $usage : [],
        'status' => 'ok',
    ]);

    return $fullContent;
}
