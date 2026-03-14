<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "connector" . DIRECTORY_SEPARATOR . "llm_dispatcher.php");

function h(mixed $v): string {
    return htmlspecialchars(strval($v), ENT_QUOTES, 'UTF-8');
}

function stobeLlmtestDecodeJsonObject(mixed $raw): array {
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode(strval($raw), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeLlmtestMaskSecretString(string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (strlen($trimmed) <= 8) {
        return str_repeat('*', strlen($trimmed));
    }
    return substr($trimmed, 0, 4) . str_repeat('*', max(4, strlen($trimmed) - 8)) . substr($trimmed, -4);
}

function stobeLlmtestMaskHeader(string $header): string {
    $lower = strtolower($header);
    if (str_starts_with($lower, 'authorization:')) {
        return 'Authorization: Bearer ***';
    }
    if (str_starts_with($lower, 'player2-game-key:')) {
        return 'player2-game-key: ***';
    }
    return $header;
}

function stobeLlmtestPerformanceBadge(float $elapsedMs): array {
    $seconds = $elapsedMs / 1000.0;
    if ($seconds < 2.0) {
        return ['FAST', '#28a745'];
    }
    if ($seconds < 5.0) {
        return ['GOOD', '#007bff'];
    }
    if ($seconds < 10.0) {
        return ['NORMAL', '#ffc107'];
    }
    if ($seconds < 30.0) {
        return ['SLOW', '#fd7e14'];
    }
    return ['TOO CHIMMING SLOW', '#dc3545'];
}

function stobeLlmtestBuildResolvedPreview(array $cfg, array $messages): array {
    $runtime = function_exists('stobePrepareLlmRuntimeConfig')
        ? stobePrepareLlmRuntimeConfig($cfg)
        : $cfg;

    $connectorType = strtolower(trim(strval($runtime['connector_type'] ?? 'openaijson')));
    $baseUrl = rtrim(strval($runtime['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        $baseUrl = 'https://openrouter.ai/api/v1';
    }
    $model = trim(strval($runtime['model'] ?? ''));
    if ($model === '') {
        $model = 'openrouter/auto';
    }

    $connectorConfig = stobeLlmtestDecodeJsonObject($runtime['config'] ?? []);
    $maxTokens = intval($runtime['max_tokens'] ?? 2048);
    if ($maxTokens <= 0) {
        $maxTokens = 2048;
    }
    $temperature = floatval($runtime['temperature'] ?? 0.8);

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'stream' => false,
    ];

    if (function_exists('stobeIsOpenAiModel') && stobeIsOpenAiModel($model)) {
        $payload['max_completion_tokens'] = $maxTokens;
    } else {
        $payload['max_tokens'] = $maxTokens;
    }

    if (function_exists('stobeParseConnectorExtras')) {
        $extras = stobeParseConnectorExtras($connectorConfig);
        foreach ($extras as $key => $value) {
            $payload[$key] = $value;
        }
    }

    if (function_exists('stobeIsReasoningModel') && stobeIsReasoningModel($model)) {
        $payload['reasoning'] = ['exclude' => true];
    }

    $headers = [];
    if (function_exists('stobeBuildLlmRequestHeaders')) {
        $headers = stobeBuildLlmRequestHeaders(
            strval($runtime['api_key'] ?? ''),
            $connectorConfig,
            $connectorType,
            false
        );
    }
    $maskedHeaders = [];
    foreach ($headers as $header) {
        $maskedHeaders[] = stobeLlmtestMaskHeader(strval($header));
    }

    $runtimePreview = $runtime;
    $runtimePreview['api_key'] = stobeLlmtestMaskSecretString(strval($runtime['api_key'] ?? ''));
    $runtimePreview['config'] = $connectorConfig;

    return [
        'connector_type' => $connectorType,
        'url' => $baseUrl . '/chat/completions',
        'payload' => $payload,
        'headers' => $maskedHeaders,
        'runtime' => $runtimePreview,
    ];
}

function stobeLlmtestDecodeAuditPayload(mixed $raw): array|string {
    $text = trim(strval($raw));
    if ($text === '') {
        return [];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    return $text;
}

$db = $GLOBALS["db"] ?? null;

$connectorId = intval($_GET['connector_id'] ?? $_POST['connector_id'] ?? 0);
$connector = $connectorId > 0 ? getLlmConnectorById($connectorId) : false;
$resolvedPreview = [];
$responseText = '';
$errorText = '';
$prompt = trim(strval($_POST['prompt'] ?? $_GET['prompt'] ?? 'Give me one short Kenshi line.'));
$elapsedMs = 0.0;
$executed = false;
$requestId = '';
$auditRow = [];
$auditRequestPayload = [];
$auditResultPayload = [];

if ($connector) {
    if ($prompt === '') {
        $prompt = 'Give me one short Kenshi line.';
    }

    $messages = [
        ['role' => 'system', 'content' => 'You are a Kenshi NPC. Reply concisely in-world.'],
        ['role' => 'user', 'content' => $prompt],
    ];
    $cfg = stobeBuildLlmConfigFromConnector($connector);
    $resolvedPreview = stobeLlmtestBuildResolvedPreview($cfg, $messages);

    $requestId = 'llmtest_' . gmdate('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
    $GLOBALS['__stobe_request_id'] = $requestId;

    $start = microtime(true);
    $out = stobeCallLLM($messages, $cfg, [
        'event_type' => 'llmtest',
        'npc_name' => strval($connector['name'] ?? 'LLM Test'),
    ]);
    $elapsedMs = (microtime(true) - $start) * 1000.0;
    $executed = true;

    if ($db && $requestId !== '') {
        try {
            $row = $db->fetchOne(
                "SELECT request_id, model, url, request, result, http_code, duration_ms, status, error
                 FROM audit_request
                 WHERE request_id = $1
                 ORDER BY id DESC
                 LIMIT 1",
                [$requestId]
            );
            if (is_array($row)) {
                $auditRow = $row;
                $auditRequestPayload = stobeLlmtestDecodeAuditPayload($row['request'] ?? '');
                $auditResultPayload = stobeLlmtestDecodeAuditPayload($row['result'] ?? '');
            }
        } catch (Throwable $_ignored) {
            $auditRow = [];
        }
    }

    if ($out === false) {
        $status = trim(strval($auditRow['status'] ?? ''));
        $httpCode = intval($auditRow['http_code'] ?? 0);
        $auditError = trim(strval($auditRow['error'] ?? ''));
        if ($auditError !== '') {
            $errorText = $auditError;
        } elseif ($httpCode > 0) {
            $errorText = 'HTTP ' . $httpCode . ' from connector.';
        } elseif ($status !== '') {
            $errorText = 'LLM request failed (' . $status . ').';
        } else {
            $errorText = 'LLM request failed. Check connector and API key.';
        }
    } else {
        $responseText = strval($out);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LLM Connector Test</title>
  <style>
    body { margin: 0; font-family: Exo2, Arial, sans-serif; background: #2a2a2a; color: #e8eef9; }
    .wrap { padding: 16px; max-width: 1220px; margin: 0 auto; }
    .card { border: 1px solid #4a4a4a; border-radius: 10px; background: #2b2b2b; padding: 12px; margin-bottom: 12px; }
    .title { margin: 0 0 8px; color: #f27c11; font-size: 20px; }
    .label { display: block; margin: 0 0 6px; color: #ffb862; font-weight: 600; }
    .muted { color: #9aa0ab; font-size: 12px; }
    input[type=text] { width: 100%; box-sizing: border-box; background: #181818; color: #f2f2f2; border: 1px solid #555; border-radius: 6px; padding: 8px; }
    .btn { border: 1px solid #666; background: #3a3a3a; color: #fff; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
    .btn:hover { background: #4a4a4a; }
    pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; color: #d8e0ef; background: #232323; border: 1px solid #3a3a3a; border-radius: 8px; padding: 10px; }
    .ok { color: #6fdc8c; }
    .err { color: #ff6b6b; }
    .status-line { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .badge { border-radius: 999px; padding: 2px 8px; font-size: 12px; border: 1px solid #555; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 1180px) {
      .grid { grid-template-columns: 1fr 1fr; }
    }
    ul { margin: 8px 0 0 18px; padding: 0; }
    li { margin: 4px 0; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1 class="title">LLM Connector Test</h1>

    <div class="card">
      <div><strong>Connector:</strong> <?= $connector ? h($connector['name'] ?? ('#' . $connectorId)) : 'Not found' ?></div>
      <div class="muted">ID: <?= h($connectorId) ?></div>
      <?php if ($requestId !== ''): ?>
        <div class="muted">Request ID: <?= h($requestId) ?></div>
      <?php endif; ?>
    </div>

    <?php if (!$connector): ?>
      <div class="card err">Connector not found.</div>
    <?php else: ?>
      <form method="post" class="card">
        <input type="hidden" name="connector_id" value="<?= h($connectorId) ?>">
        <label class="label" for="prompt">Prompt</label>
        <input id="prompt" name="prompt" type="text" value="<?= h($prompt) ?>">
        <div style="margin-top:10px;"><button class="btn" type="submit">Run Test</button></div>
      </form>

      <?php if ($executed): ?>
        <?php $perf = stobeLlmtestPerformanceBadge($elapsedMs); ?>
        <div class="card">
          <label class="label">Status</label>
          <div class="status-line">
            <?php if ($errorText !== ''): ?>
              <span class="err"><strong>Failed</strong></span>
            <?php else: ?>
              <span class="ok"><strong>Completed</strong></span>
            <?php endif; ?>
            <span class="badge"><?= h(number_format($elapsedMs, 1)) ?> ms</span>
            <span class="badge" style="color: <?= h($perf[1]) ?>; border-color: <?= h($perf[1]) ?>;"><?= h($perf[0]) ?></span>
            <?php if (!empty($auditRow['http_code'])): ?>
              <span class="badge">HTTP <?= h(intval($auditRow['http_code'])) ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($auditRow['error'])): ?>
            <div class="muted" style="margin-top:6px;">Error: <?= h(strval($auditRow['error'])) ?></div>
          <?php endif; ?>
        </div>

        <div class="card">
          <label class="label">Response</label>
          <?php if ($errorText !== ''): ?>
            <div class="err"><?= h($errorText) ?></div>
            <ul class="muted">
              <li>401 Unauthorized: verify API key and badge mapping.</li>
              <li>402 Payment Required: check provider credits/billing.</li>
              <li>403 Forbidden: request likely blocked by provider policy.</li>
              <li>404 Not Found: verify connector base URL/endpoint.</li>
              <li>Empty response: lower strict JSON settings or try another model.</li>
            </ul>
          <?php else: ?>
            <pre><?= h($responseText) ?></pre>
          <?php endif; ?>
        </div>

        <div class="grid">
          <div class="card">
            <label class="label">Resolved Request Payload</label>
            <?php
              $payloadToShow = $resolvedPreview['payload'] ?? [];
              if (is_array($auditRequestPayload)) {
                  $payloadToShow = $auditRequestPayload;
              }
            ?>
            <pre><?= h(json_encode($payloadToShow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
          </div>

          <div class="card">
            <label class="label">Runtime Details</label>
            <?php
              $runtimeDetails = [
                  'connector_type' => $resolvedPreview['connector_type'] ?? '',
                  'request_url' => $resolvedPreview['url'] ?? '',
                  'headers' => $resolvedPreview['headers'] ?? [],
                  'runtime' => $resolvedPreview['runtime'] ?? [],
              ];
            ?>
            <pre><?= h(json_encode($runtimeDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
          </div>

          <div class="card">
            <label class="label">Audit Result Payload</label>
            <?php if (is_array($auditResultPayload)): ?>
              <pre><?= h(json_encode($auditResultPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php elseif (trim(strval($auditResultPayload)) !== ''): ?>
              <pre><?= h(strval($auditResultPayload)) ?></pre>
            <?php else: ?>
              <pre>(none captured)</pre>
            <?php endif; ?>
          </div>

          <div class="card">
            <label class="label">Connector Snapshot</label>
            <?php
              $connectorSnapshot = [
                  'name' => strval($connector['name'] ?? ''),
                  'connector_type' => strval($connector['connector_type'] ?? ''),
                  'base_url' => strval($connector['base_url'] ?? ''),
                  'model' => strval($connector['model'] ?? ''),
                  'max_tokens' => intval($connector['max_tokens'] ?? 0),
                  'temperature' => strval($connector['temperature'] ?? ''),
                  'api_badge_id' => intval($connector['api_badge_id'] ?? 0),
                  'config' => stobeLlmtestDecodeJsonObject($connector['config'] ?? '{}'),
              ];
            ?>
            <pre><?= h(json_encode($connectorSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>

