<?php

// Normalize local endpoints before any probe, test, or connector write.
function stobeLocalLlmSetupInput(array $raw, bool $requireModel = true): array
{
    foreach (['provider', 'base_url', 'model', 'api_key'] as $field) {
        if (isset($raw[$field]) && !is_string($raw[$field])) {
            throw new InvalidArgumentException('Local setup fields must contain text.');
        }
    }
    $providers = ['lmstudio' => 'LM Studio', 'ollama' => 'Ollama', 'llamacpp' => 'llama.cpp',
        'koboldcpp' => 'KoboldCPP', 'custom' => 'OpenAI-compatible'];
    $provider = strval($raw['provider'] ?? '');
    if (!isset($providers[$provider])) {
        throw new InvalidArgumentException('Choose a supported local server.');
    }
    $url = trim(strval($raw['base_url'] ?? ''));
    $parts = parse_url($url);
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)
        || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('Use an http:// or https:// local URL without credentials or query parameters.');
    }
    $host = strtolower(trim($parts['host'], '[]'));
    $packed = @inet_pton($host);
    $allowed = $host === 'localhost';
    if (is_string($packed) && strlen($packed) === 4) {
        $octets = array_values(unpack('C4', $packed));
        $allowed = in_array($octets[0], [10, 127], true)
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    } elseif (is_string($packed) && strlen($packed) === 16) {
        $allowed = $packed === str_repeat("\0", 15) . "\1" || in_array(ord($packed[0]), [0xfc, 0xfd], true);
    }
    if (!$allowed) {
        throw new InvalidArgumentException('Use localhost or a private LAN IP. Public hosts and DNS names are not supported here.');
    }
    $url = rtrim($url, '/');
    $urlPath = rtrim(strval($parts['path'] ?? ''), '/');
    if ($urlPath === '') {
        $url .= '/v1/chat/completions';
    } elseif (str_ends_with($urlPath, '/v1')) {
        $url .= '/chat/completions';
    } elseif (!str_ends_with($urlPath, '/chat/completions')) {
        throw new InvalidArgumentException('Use the OpenAI-compatible /v1/chat/completions endpoint.');
    }
    $model = trim(strval($raw['model'] ?? ''));
    if (($requireModel && $model === '') || strlen($model) > 255 || preg_match('/[\x00-\x1f\x7f]/', $model)) {
        throw new InvalidArgumentException('Enter a model name of 1 to 255 characters.');
    }
    $apiKey = trim(strval($raw['api_key'] ?? ''));
    if (strlen($apiKey) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
        throw new InvalidArgumentException('The API key contains invalid characters or is too long.');
    }
    return ['provider' => $provider, 'label' => $providers[$provider], 'base_url' => $url,
        'model' => $model, 'api_key' => $apiKey];
}

// Unlike the existing reachability probe, discovery needs authenticated JSON and a strict 2xx response.
function stobeLocalLlmRequest(array $setup, bool $test): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to connect to a local model.');
    }
    $url = $test ? $setup['base_url'] : substr($setup['base_url'], 0, -strlen('/chat/completions')) . '/models';
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($setup['api_key'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $setup['api_key'];
    }
    $body = '';
    $tooLarge = false;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $test ? 30 : 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 262144) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($test) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode([
            'model' => $setup['model'], 'stream' => false, 'max_tokens' => 32, 'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
        ], JSON_THROW_ON_ERROR));
    }
    $ok = curl_exec($handle);
    $status = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);
    if ($tooLarge) {
        throw new RuntimeException('The local server response was too large.');
    }
    if ($ok === false) {
        throw new RuntimeException('Could not reach the local server. Check its address, listening interface and firewall.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('The local server returned HTTP ' . $status . '. Check the endpoint and optional API key.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The local server did not return valid JSON.');
    }
    if ($test) {
        $reply = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($reply) || trim($reply) === '') {
            throw new RuntimeException('The local server returned no chat reply. Check the model name.');
        }
        return ['success' => true, 'message' => 'The model replied. You can now apply it to dialogue.'];
    }
    if (!isset($decoded['data']) || !is_array($decoded['data'])) {
        throw new RuntimeException('No OpenAI-compatible model list was returned. Enter the model name manually and test it.');
    }
    $models = [];
    foreach ($decoded['data'] as $row) {
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        if (is_string($id) && trim($id) !== '' && strlen($id) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $id)) {
            $models[] = $id;
        }
        if (count($models) >= 100) {
            break;
        }
    }
    return ['success' => true, 'message' => $models ? 'Models found. Choose one and test it.'
        : 'No models are loaded. Load one or enter its name manually, then test.', 'models' => array_values(array_unique($models))];
}

// Create/reuse an exact connector without changing existing connectors or background routing.
function stobeLocalLlmApply(sql $db, array $setup, string $target): int
{
    if (!in_array($target, ['default', 'player_faction', 'both'], true)) {
        throw new InvalidArgumentException('Choose the Default NPC profile, Player Faction profile, or both.');
    }
    if ($db->exec('BEGIN') === false) {
        throw new RuntimeException('Could not start saving the local model.');
    }
    try {
        // Serialize this small write so repeated Apply requests reuse the same connector.
        if ($db->exec("SELECT pg_advisory_xact_lock(hashtext('stobe_local_llm_setup'))") === false) {
            throw new RuntimeException('Could not lock local model setup.');
        }
        $default = $target === 'player_faction' ? [] : stobeQuickstartFetchDefaultProfile($db);
        $player = $target === 'default' ? [] : stobeQuickstartFetchPlayerFactionProfile($db);
        // Do not use Quickstart's arbitrary first-profile fallback when the default role is absent.
        if ($target !== 'player_faction' && (!stobeQuickstartBool($default['is_default_npc'] ?? false)
            && strtolower(trim(strval($default['label'] ?? ''))) !== 'default profile')) {
            throw new InvalidArgumentException('No Default NPC profile exists. Set one in Profiles first.');
        }
        if ($target !== 'default' && empty($player['id'])) {
            throw new InvalidArgumentException('No Player Faction profile exists. Set one in Profiles first.');
        }
        $profileIds = stobeQuickstartCollectTargetProfileIds($default, $player);
        $config = json_encode(['service' => 'custom', 'provider' => 'local', 'disable_streaming' => true,
            'enforce_json' => false, 'quickstart_local_provider' => $setup['provider']], JSON_THROW_ON_ERROR);
        $row = $db->fetchOne(
            "SELECT id FROM core_llm_connector WHERE connector_type = 'openaijson' AND base_url = $1
             AND model = $2 AND COALESCE(api_key, '') = $3 AND api_badge_id IS NULL
             AND config = $4::jsonb AND max_tokens = 512 AND temperature = 0.7 ORDER BY id LIMIT 1",
            [$setup['base_url'], $setup['model'], $setup['api_key'], $config]
        );
        $connectorId = intval($row['id'] ?? 0);
        if ($connectorId <= 0) {
            $row = $db->fetchOne(
                "INSERT INTO core_llm_connector (name, connector_type, base_url, model, api_key, max_tokens, temperature, config)
                 VALUES ($1, 'openaijson', $2, $3, $4, 512, 0.7, $5::jsonb) RETURNING id",
                ['Local LLM - ' . $setup['label'] . ' - ' . bin2hex(random_bytes(4)),
                    $setup['base_url'], $setup['model'], $setup['api_key'], $config]
            );
            $connectorId = intval($row['id'] ?? 0);
        }
        if ($connectorId <= 0) {
            throw new RuntimeException('Could not save the local model connector.');
        }
        foreach ($profileIds as $profileId) {
            $result = $db->exec('UPDATE core_profiles SET llm_primary_id = $1, response_connector = $1 WHERE id = $2',
                [$connectorId, $profileId]);
            if ($result === false || $db->affectedRows($result) !== 1) {
                throw new RuntimeException('Could not update the selected dialogue profile.');
            }
        }
        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not finish saving the local model.');
        }
        return $connectorId;
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

// Keep local setup requests separate from Quickstart's existing cloud/TTS save path.
function stobeLocalLlmHandleRequest(sql $db): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
    }
    if (empty($_SESSION['local_llm_setup_csrf'])) {
        $_SESSION['local_llm_setup_csrf'] = bin2hex(random_bytes(32));
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !str_starts_with($action, 'local_llm_')) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!is_string($_POST['csrf_token'] ?? null)
            || !hash_equals($_SESSION['local_llm_setup_csrf'], $_POST['csrf_token'])) {
            throw new InvalidArgumentException('Reload Quickstart before using local setup.');
        }
        if (!in_array($action, ['local_llm_probe', 'local_llm_test', 'local_llm_apply'], true)) {
            throw new InvalidArgumentException('Unknown local setup action.');
        }
        if (microtime(true) - floatval($_SESSION['local_llm_last_request'] ?? 0) < 2) {
            throw new InvalidArgumentException('Wait a moment before trying again.');
        }
        $_SESSION['local_llm_last_request'] = microtime(true);
        $setup = stobeLocalLlmSetupInput($_POST, $action !== 'local_llm_probe');
        $fingerprint = hash('sha256', json_encode($setup, JSON_THROW_ON_ERROR));
        if ($action === 'local_llm_apply') {
            $tested = $_SESSION['local_llm_tested'] ?? [];
            if (($tested['fingerprint'] ?? '') !== $fingerprint || time() - intval($tested['time'] ?? 0) > 600) {
                throw new InvalidArgumentException('Test these connection settings successfully before applying them.');
            }
            $target = is_string($_POST['target'] ?? null) ? $_POST['target'] : '';
            $id = stobeLocalLlmApply($db, $setup, $target);
            $result = ['success' => true, 'connector_id' => $id,
                'message' => 'Local model applied to dialogue. Other connectors and background tasks are unchanged.'];
        } else {
            if ($action === 'local_llm_test') {
                unset($_SESSION['local_llm_tested']);
            }
            $result = stobeLocalLlmRequest($setup, $action === 'local_llm_test');
            if ($action === 'local_llm_test') {
                $_SESSION['local_llm_tested'] = ['fingerprint' => $fingerprint, 'time' => time()];
            }
        }
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $result = ['success' => false, 'message' => $exception->getMessage()];
    } catch (Throwable $exception) {
        // Do not log submitted URLs, model names, API keys, or provider response bodies.
        $result = ['success' => false, 'message' => 'Local setup failed. Check the server and try again.'];
    }
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
