<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "tts" . DIRECTORY_SEPARATOR . "tts-pockettts.php");
require_once($enginePath . "tts" . DIRECTORY_SEPARATOR . "tts-xtts.php");
require_once($enginePath . "tts" . DIRECTORY_SEPARATOR . "tts-chatterbox.php");
require_once($enginePath . "tts" . DIRECTORY_SEPARATOR . "tts-cartesia.php");
require_once($enginePath . "tts" . DIRECTORY_SEPARATOR . "tts-inworld.php");

function h(mixed $v): string { return htmlspecialchars(strval($v), ENT_QUOTES, 'UTF-8'); }
function stobe_web_root(): string {
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPos = strpos($scriptPath, '/ui/');
    $root = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
    if ($root === '/') $root = '';
    return rtrim($root, '/');
}

$connectorId = intval($_GET['connector_id'] ?? $_POST['connector_id'] ?? 0);
$connector = $connectorId > 0 ? getTtsConnectorById($connectorId) : false;
$prompt = trim(strval($_POST['prompt'] ?? $_GET['prompt'] ?? 'The great father Chitrin was betrayed by his children; broken by their sins and their lack of faith.'));
$voiceOverride = trim(strval($_POST['voiceid'] ?? $_GET['voiceid'] ?? 'male1'));
$requestPreview = null;
$resultPreview = null;
$errorText = '';
$executed = false;
$audioUrl = '';
$elapsedMs = 0.0;
$webRoot = stobe_web_root();

if ($connector) {
    $runtimePreview = stobeResolveTtsRuntimeFromConnector($connector, $voiceOverride);
    if (isset($runtimePreview['api_key']) && trim(strval($runtimePreview['api_key'])) !== '') {
        $runtimePreview['api_key'] = '***redacted***';
    }
    $requestPreview = [
        'connector_id' => intval($connector['id'] ?? 0),
        'name' => strval($connector['name'] ?? ''),
        'provider' => strval($runtimePreview['provider'] ?? ''),
        'base_url' => strval($runtimePreview['endpoint'] ?? ''),
        'voiceid_to_test' => $voiceOverride,
        'text' => $prompt,
        'runtime' => $runtimePreview,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $executed = true;
        $start = microtime(true);
        $result = stobeSynthesizeTtsFromConnector($connector, $prompt, $voiceOverride);
        $elapsedMs = (microtime(true) - $start) * 1000.0;

        if (!is_array($result) || count($result) === 0) {
            $errorText = 'TTS synthesis failed. Check provider settings, API keys, endpoint, and voice sample availability.';
        } else {
            $resultPreview = $result;
            $path = trim(strval($result['audio_path'] ?? ''));
            if ($path !== '') {
                $audioUrl = $webRoot . '/' . ltrim($path, '/');
                $audioUrl .= '?ts=' . time();
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TTS Test</title>
  <style>
    body { margin: 0; font-family: 'Exo2', Arial, sans-serif; background: #222; color: #eee; }
    .wrap { padding: 16px; }
    .card { border: 1px solid #444; border-radius: 8px; background: #2b2b2b; padding: 12px; margin-bottom: 12px; }
    label { display: block; margin: 0 0 6px; color: #ffb862; font-weight: 600; }
    input[type=text], textarea { width: 100%; box-sizing: border-box; background: #181818; color: #f2f2f2; border: 1px solid #555; border-radius: 6px; padding: 8px; }
    textarea { min-height: 130px; }
    .btn { border: 1px solid #666; background: #3a3a3a; color: #fff; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
    .btn:hover { background: #4a4a4a; }
    pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
    .err { color: #ff6b6b; }
    .ok { color: #9be29b; }
    .muted { color: #aaa; font-size: 12px; }
    audio { width: 100%; margin-top: 8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div><strong>Connector:</strong> <?= $connector ? h($connector['name'] ?? ('#' . $connectorId)) : 'Not found' ?></div>
      <div class="muted">ID: <?= h($connectorId) ?></div>
    </div>

    <?php if (!$connector): ?>
      <div class="card err">Connector not found.</div>
    <?php else: ?>
      <form method="post" class="card">
        <input type="hidden" name="connector_id" value="<?= h($connectorId) ?>">
        <label for="prompt">Test Speech</label>
        <textarea id="prompt" name="prompt"><?= h($prompt) ?></textarea>
        <label for="voiceid" style="margin-top:10px;">VoiceID to test</label>
        <input id="voiceid" name="voiceid" type="text" value="<?= h($voiceOverride) ?>">
        <div style="margin-top:10px;"><button class="btn" type="submit">Run Test</button></div>
      </form>

      <?php if ($requestPreview !== null): ?>
        <div class="card">
          <label>Request Preview</label>
          <pre><?= h(json_encode($requestPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
      <?php endif; ?>

      <?php if ($executed): ?>
        <div class="card">
          <label>Status</label>
          <?php if ($errorText !== ''): ?>
            <div class="err">Failed in <?= h(number_format($elapsedMs, 1)) ?> ms</div>
            <div class="err" style="margin-top:6px;"><?= h($errorText) ?></div>
          <?php else: ?>
            <div class="ok">Completed in <?= h(number_format($elapsedMs, 1)) ?> ms</div>
          <?php endif; ?>
        </div>

        <?php if ($resultPreview !== null): ?>
          <div class="card">
            <label>Result</label>
            <pre><?= h(json_encode($resultPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php if ($audioUrl !== ''): ?>
              <audio controls autoplay src="<?= h($audioUrl) ?>"></audio>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>

