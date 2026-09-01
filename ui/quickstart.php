<?php
/**
 * StobeServer quickstart menu.
 * First-run onboarding for core connector setup.
 */

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

try {
    require_once($path . "debug/db_updates.php");
} catch (Throwable $exception) {
    stobeLogException($exception, "Quickstart db update check failed");
}
require_once($path . "lib/core/stt_connector.class.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "includes" . DIRECTORY_SEPARATOR . "local_llm_setup.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function stobeQuickstartWebRoot(): string
{
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPos = strpos($scriptPath, '/ui/');
    $root = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : dirname(dirname($scriptPath));
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    return rtrim($root, '/');
}

function stobeQuickstartFetchApiKeyMap(sql $db): array
{
    $rows = $db->fetchAll("SELECT label, COALESCE(api_key, '') AS api_key FROM core_api_badge");
    $map = [];
    foreach ($rows as $row) {
        $map[strtolower(trim(strval($row['label'] ?? '')))] = strval($row['api_key'] ?? '');
    }
    return $map;
}

function stobeQuickstartUpsertApiKey(sql $db, string $label, string $apiKey): void
{
    $safeLabel = trim($label);
    if ($safeLabel === '') {
        return;
    }
    $db->exec(
        "INSERT INTO core_api_badge (label, api_key)
         VALUES ($1, $2)
         ON CONFLICT (label) DO UPDATE
         SET api_key = EXCLUDED.api_key",
        [$safeLabel, $apiKey]
    );
}

function stobeQuickstartFetchDefaultProfile(sql $db): array
{
    $row = $db->fetchOne(
        "SELECT id,
                COALESCE(label, '') AS label,
                COALESCE(is_default_npc, FALSE) AS is_default_npc,
                COALESCE(is_player_faction_profile, FALSE) AS is_player_faction_profile,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
         FROM core_profiles
         ORDER BY CASE
                    WHEN COALESCE(is_default_npc, FALSE) = TRUE THEN 0
                    WHEN LOWER(COALESCE(label, '')) = 'default profile' THEN 1
                    ELSE 2
                  END,
                  id ASC
         LIMIT 1"
    );
    return is_array($row) ? $row : [];
}

function stobeQuickstartFetchProfileById(sql $db, int $profileId): array
{
    if ($profileId <= 0) {
        return [];
    }
    $row = $db->fetchOne(
        "SELECT id,
                COALESCE(label, '') AS label,
                COALESCE(is_default_npc, FALSE) AS is_default_npc,
                COALESCE(is_player_faction_profile, FALSE) AS is_player_faction_profile,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$profileId]
    );
    return is_array($row) ? $row : [];
}

function stobeQuickstartFetchPlayerFactionProfile(sql $db): array
{
    $row = $db->fetchOne(
        "SELECT id,
                COALESCE(label, '') AS label,
                COALESCE(is_default_npc, FALSE) AS is_default_npc,
                COALESCE(is_player_faction_profile, FALSE) AS is_player_faction_profile,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
         FROM core_profiles
         ORDER BY CASE
                    WHEN COALESCE(is_player_faction_profile, FALSE) = TRUE THEN 0
                    WHEN LOWER(COALESCE(label, '')) = 'player faction' THEN 1
                    ELSE 2
                  END,
                  id ASC
         LIMIT 1"
    );
    if (!is_array($row)) {
        return [];
    }
    $isPlayerFaction = stobeQuickstartBool($row['is_player_faction_profile'] ?? false);
    $label = strtolower(trim(strval($row['label'] ?? '')));
    if ($isPlayerFaction || $label === 'player faction') {
        return $row;
    }
    return [];
}

function stobeQuickstartEncodePlayerFactionMetadata(mixed $rawMetadata): string
{
    $metadata = stobeQuickstartDecodeJsonObject($rawMetadata);
    $metadata['DYNAMIC_PROFILE_ENABLED'] = true;
    $metadata['MIDDLE_TERM_MEMORY_ENABLED'] = true;
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || trim($encoded) === '') {
        return '{"DYNAMIC_PROFILE_ENABLED":true,"MIDDLE_TERM_MEMORY_ENABLED":true}';
    }
    return $encoded;
}

function stobeQuickstartEnsurePlayerFactionProfile(sql $db, array $defaultProfile): array
{
    $defaultProfileId = intval($defaultProfile['id'] ?? 0);
    if ($defaultProfileId <= 0) {
        return stobeQuickstartFetchPlayerFactionProfile($db);
    }

    $playerProfile = stobeQuickstartFetchPlayerFactionProfile($db);
    $playerProfileId = intval($playerProfile['id'] ?? 0);
    $playerMetadataJson = stobeQuickstartEncodePlayerFactionMetadata($defaultProfile['metadata'] ?? '{}');

    $primaryConnector = intval($defaultProfile['llm_primary_id'] ?? ($defaultProfile['response_connector'] ?? 0));
    $secondaryConnector = intval($defaultProfile['llm_secondary_id'] ?? 0);
    $tertiaryConnector = intval($defaultProfile['llm_tertiary_id'] ?? 0);
    $quaternaryConnector = intval($defaultProfile['llm_quaternary_id'] ?? 0);
    $diaryConnector = intval($defaultProfile['diary_connector'] ?? 0);
    $autochatConnector = intval($defaultProfile['autochat_connector'] ?? 0);
    $middletermConnector = intval($defaultProfile['middleterm_connector'] ?? 0);
    $backgroundlifeConnector = intval($defaultProfile['backgroundlife_connector'] ?? 0);
    $dynamicConnector = intval($defaultProfile['dynamic_connector'] ?? 0);
    $relationshipConnector = intval($defaultProfile['relationship_connector'] ?? 0);
    $ttsConnectorId = intval($defaultProfile['tts_connector_id'] ?? 0);

    if ($playerProfileId <= 0) {
        $inserted = $db->fetchOne(
            "INSERT INTO core_profiles (
                label,
                is_default_npc,
                is_player_faction_profile,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
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
                'Player Faction',
                FALSE,
                TRUE,
                $1, $2, $3, $4, $1, $5, $6, $7, $8, $9, $10, $11,
                $12::jsonb
            )
            ON CONFLICT (label) DO UPDATE SET
                is_default_npc = FALSE,
                is_player_faction_profile = TRUE,
                llm_primary_id = COALESCE(EXCLUDED.llm_primary_id, core_profiles.llm_primary_id),
                llm_secondary_id = COALESCE(EXCLUDED.llm_secondary_id, core_profiles.llm_secondary_id),
                llm_tertiary_id = COALESCE(EXCLUDED.llm_tertiary_id, core_profiles.llm_tertiary_id),
                llm_quaternary_id = COALESCE(EXCLUDED.llm_quaternary_id, core_profiles.llm_quaternary_id),
                response_connector = COALESCE(EXCLUDED.llm_primary_id, core_profiles.response_connector),
                diary_connector = COALESCE(EXCLUDED.diary_connector, core_profiles.diary_connector),
                autochat_connector = COALESCE(EXCLUDED.autochat_connector, core_profiles.autochat_connector),
                middleterm_connector = COALESCE(EXCLUDED.middleterm_connector, core_profiles.middleterm_connector),
                backgroundlife_connector = COALESCE(EXCLUDED.backgroundlife_connector, core_profiles.backgroundlife_connector),
                dynamic_connector = COALESCE(EXCLUDED.dynamic_connector, core_profiles.dynamic_connector),
                relationship_connector = COALESCE(EXCLUDED.relationship_connector, core_profiles.relationship_connector),
                tts_connector_id = COALESCE(EXCLUDED.tts_connector_id, core_profiles.tts_connector_id),
                metadata = CASE
                    WHEN core_profiles.metadata IS NULL
                      OR core_profiles.metadata = '[]'::jsonb
                      OR jsonb_typeof(core_profiles.metadata) <> 'object'
                    THEN EXCLUDED.metadata
                    ELSE jsonb_set(
                        jsonb_set(core_profiles.metadata, '{DYNAMIC_PROFILE_ENABLED}', 'true'::jsonb, true),
                        '{MIDDLE_TERM_MEMORY_ENABLED}',
                        'true'::jsonb,
                        true
                    )
                END,
                updated_at = NOW()
            RETURNING id",
            [
                $primaryConnector > 0 ? $primaryConnector : null,
                $secondaryConnector > 0 ? $secondaryConnector : null,
                $tertiaryConnector > 0 ? $tertiaryConnector : null,
                $quaternaryConnector > 0 ? $quaternaryConnector : null,
                $diaryConnector > 0 ? $diaryConnector : null,
                $autochatConnector > 0 ? $autochatConnector : null,
                $middletermConnector > 0 ? $middletermConnector : null,
                $backgroundlifeConnector > 0 ? $backgroundlifeConnector : null,
                $dynamicConnector > 0 ? $dynamicConnector : null,
                $relationshipConnector > 0 ? $relationshipConnector : null,
                $ttsConnectorId > 0 ? $ttsConnectorId : null,
                $playerMetadataJson
            ]
        );
        $playerProfileId = intval($inserted['id'] ?? 0);
    }

    if ($playerProfileId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET label = 'Player Faction',
                  is_default_npc = FALSE,
                  is_player_faction_profile = TRUE,
                  llm_primary_id = COALESCE(llm_primary_id, $1::INT),
                  llm_secondary_id = COALESCE(llm_secondary_id, $2::INT),
                  llm_tertiary_id = COALESCE(llm_tertiary_id, $3::INT),
                  llm_quaternary_id = COALESCE(llm_quaternary_id, $4::INT),
                  response_connector = COALESCE(response_connector, $1::INT),
                  diary_connector = COALESCE(diary_connector, $5::INT),
                  autochat_connector = COALESCE(autochat_connector, $6::INT),
                  middleterm_connector = COALESCE(middleterm_connector, $7::INT),
                  backgroundlife_connector = COALESCE(backgroundlife_connector, $8::INT),
                  dynamic_connector = COALESCE(dynamic_connector, $9::INT),
                  relationship_connector = COALESCE(relationship_connector, $10::INT),
                  tts_connector_id = COALESCE(tts_connector_id, $11::INT),
                  metadata = CASE
                    WHEN metadata IS NULL
                      OR metadata = '[]'::jsonb
                      OR jsonb_typeof(metadata) <> 'object'
                    THEN $12::jsonb
                    ELSE jsonb_set(
                        jsonb_set(metadata, '{DYNAMIC_PROFILE_ENABLED}', 'true'::jsonb, true),
                        '{MIDDLE_TERM_MEMORY_ENABLED}',
                        'true'::jsonb,
                        true
                    )
                 END,
                 updated_at = NOW()
             WHERE id = $13",
            [
                $primaryConnector > 0 ? $primaryConnector : null,
                $secondaryConnector > 0 ? $secondaryConnector : null,
                $tertiaryConnector > 0 ? $tertiaryConnector : null,
                $quaternaryConnector > 0 ? $quaternaryConnector : null,
                $diaryConnector > 0 ? $diaryConnector : null,
                $autochatConnector > 0 ? $autochatConnector : null,
                $middletermConnector > 0 ? $middletermConnector : null,
                $backgroundlifeConnector > 0 ? $backgroundlifeConnector : null,
                $dynamicConnector > 0 ? $dynamicConnector : null,
                $relationshipConnector > 0 ? $relationshipConnector : null,
                $ttsConnectorId > 0 ? $ttsConnectorId : null,
                $playerMetadataJson,
                $playerProfileId
            ]
        );

        $db->exec(
            "UPDATE core_profiles
             SET is_player_faction_profile = FALSE,
                 updated_at = NOW()
             WHERE id <> $1
               AND COALESCE(is_player_faction_profile, FALSE) = TRUE",
            [$playerProfileId]
        );
    }

    return stobeQuickstartFetchProfileById($db, $playerProfileId);
}

function stobeQuickstartCollectTargetProfileIds(array $defaultProfile, array $playerFactionProfile): array
{
    $ids = [];
    foreach ([$defaultProfile, $playerFactionProfile] as $row) {
        $id = intval($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function stobeQuickstartFetchTtsConnectors(sql $db): array
{
    return $db->fetchAll(
        "SELECT id, COALESCE(name, '') AS name, COALESCE(base_url, '') AS base_url, config
         FROM core_tts_connector
         ORDER BY id ASC"
    );
}

function stobeQuickstartFilterDefaultTtsConnectors(array $rows): array
{
    $targetOrder = [
        'pocket tts default',
        'xtts default',
        'chatterbox default',
        'omnivoice default',
        'cartesia default',
        'inworld default',
    ];
    $rowsByName = [];
    foreach ($rows as $row) {
        $rowsByName[strtolower(trim(strval($row['name'] ?? '')))] = $row;
    }

    $filtered = [];
    foreach ($targetOrder as $targetName) {
        if (isset($rowsByName[$targetName])) {
            $filtered[] = $rowsByName[$targetName];
        }
    }

    return count($filtered) > 0 ? $filtered : $rows;
}

function stobeQuickstartTtsProviderKey(string $connectorName): string
{
    $name = strtolower(trim($connectorName));
    if (str_contains($name, 'pocket')) {
        return 'pocket_tts';
    }
    if (str_contains($name, 'xtts')) {
        return 'xtts';
    }
    if (str_contains($name, 'chatterbox')) {
        return 'chatterbox';
    }
    if (str_contains($name, 'omnivoice')) {
        return 'omnivoice';
    }
    if (str_contains($name, 'cartesia')) {
        return 'cartesia';
    }
    if (str_contains($name, 'inworld')) {
        return 'inworld';
    }
    return 'none';
}

function stobeQuickstartLocalTtsDefaultUrl(string $provider): string
{
    if ($provider === 'omnivoice') {
        return 'http://127.0.0.1:8021';
    }
    if ($provider === 'chatterbox') {
        return 'http://127.0.0.1:8023';
    }
    if ($provider === 'pocket_tts') {
        return 'http://127.0.0.1:8024';
    }
    if ($provider === 'xtts') {
        return 'http://127.0.0.1:8020';
    }
    return '';
}

function stobeQuickstartMiniMeDefaultUrl(): string
{
    return 'http://127.0.0.1:8082/';
}

function stobeQuickstartApplySttSelection(sql $db, string $selectedDriver): int
{
    $connector = new STTConnector();
    $driver = $connector->normalizeDriverValue($selectedDriver);
    $active = $connector->getActive();
    $activeDriver = $connector->normalizeDriverValue($active['driver'] ?? '');
    $metadata = $active && $activeDriver === $driver
        ? array_merge($connector->defaultsForDriver($driver), $connector->decodeMetadata($active['metadata'] ?? '{}'))
        : $connector->defaultsForDriver($driver);
    return $connector->saveGlobal([
        'driver' => $driver,
        'metadata' => $metadata,
        'api_badge_id' => $connector->driverUsesApiBadge($driver) ? $connector->getDefaultApiBadgeIdForDriver($driver) : null,
        'url' => $active && $activeDriver === $driver ? strval($active['url'] ?? '') : $connector->getDefaultUrlForDriver($driver),
    ]);
}

function stobeQuickstartDecodeJsonObject(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode(strval($raw), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeQuickstartBool(mixed $value): bool
{
    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function stobeQuickstartProbeUrl(string $rawUrl): array
{
    $result = [
        'ok' => false,
        'http_code' => 0,
        'latency_ms' => 0,
        'error' => '',
    ];

    $start = microtime(true);
    $parts = @parse_url($rawUrl);
    $scheme = strtolower(strval($parts['scheme'] ?? ''));
    $host = trim(strval($parts['host'] ?? ''));
    $port = intval($parts['port'] ?? 0);
    $path = strval($parts['path'] ?? '/');
    $query = strval($parts['query'] ?? '');

    if ($path === '') {
        $path = '/';
    }
    if ($query !== '') {
        $path .= '?' . $query;
    }

    if ($port <= 0) {
        $port = ($scheme === 'https') ? 443 : 80;
    }

    // Preferred path: cURL if extension is available.
    if (function_exists('curl_init')) {
        $ch = @curl_init($rawUrl);
        if ($ch) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain;q=0.9, */*;q=0.8'],
            ]);
            @curl_exec($ch);
            $httpCode = intval(@curl_getinfo($ch, CURLINFO_HTTP_CODE));
            $curlError = trim(strval(@curl_error($ch)));
            @curl_close($ch);

            $result['http_code'] = $httpCode;
            $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
            if ($httpCode >= 200 && $httpCode < 500) {
                $result['ok'] = true;
            } elseif ($curlError !== '') {
                $result['error'] = $curlError;
            } else {
                $result['error'] = 'HTTP ' . strval($httpCode) . ' from endpoint probe.';
            }
            return $result;
        }
    }

    // Fallback path when cURL is unavailable: low-level socket probe.
    $transport = ($scheme === 'https') ? 'ssl://' : 'tcp://';
    $target = $transport . $host . ':' . strval($port);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, 2.0, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
        $result['error'] = trim($errstr) !== '' ? trim($errstr) : ('Connection failed (' . strval($errno) . ').');
        return $result;
    }

    @stream_set_timeout($socket, 4);
    $request =
        "GET " . $path . " HTTP/1.1\r\n" .
        "Host: " . $host . "\r\n" .
        "User-Agent: StobeQuickstartProbe/1.0\r\n" .
        "Accept: */*\r\n" .
        "Connection: close\r\n\r\n";
    @fwrite($socket, $request);
    $statusLine = strval(@fgets($socket, 512));
    @fclose($socket);

    $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $statusLine, $matches)) {
        $httpCode = intval($matches[1] ?? 0);
        $result['http_code'] = $httpCode;
        if ($httpCode >= 200 && $httpCode < 500) {
            $result['ok'] = true;
            return $result;
        }
        $result['error'] = 'HTTP ' . strval($httpCode) . ' from endpoint probe.';
        return $result;
    }

    $result['error'] = 'No HTTP response from endpoint.';
    return $result;
}

function stobeQuickstartConnectorIdByName(sql $db, string $name): int
{
    $row = $db->fetchOne(
        "SELECT id
         FROM core_llm_connector
         WHERE LOWER(COALESCE(name, '')) = LOWER($1)
         LIMIT 1",
        [$name]
    );
    return intval($row['id'] ?? 0);
}

function stobeQuickstartEnsurePlayer2ConnectorId(sql $db): int
{
    $row = $db->fetchOne(
        "SELECT id
         FROM core_llm_connector
         WHERE LOWER(COALESCE(name, '')) = 'player2 local'
            OR LOWER(COALESCE(connector_type, '')) = 'player2json'
         ORDER BY CASE WHEN LOWER(COALESCE(name, '')) = 'player2 local' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    $connectorId = intval($row['id'] ?? 0);
    if ($connectorId > 0) {
        return $connectorId;
    }

    $badgeRow = $db->fetchOne(
        "SELECT id
         FROM core_api_badge
         WHERE LOWER(label) IN ('player2', 'stobe')
         ORDER BY CASE WHEN LOWER(label) = 'player2' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    $badgeId = intval($badgeRow['id'] ?? 0);
    if ($badgeId <= 0) {
        $db->exec(
            "INSERT INTO core_api_badge (label, api_key)
             VALUES ('Player2', '019cf504-1461-74e7-b4da-045b14e9019d')
             ON CONFLICT (label) DO NOTHING"
        );
        $badgeRow = $db->fetchOne(
            "SELECT id
             FROM core_api_badge
             WHERE LOWER(label) IN ('player2', 'stobe')
             ORDER BY CASE WHEN LOWER(label) = 'player2' THEN 0 ELSE 1 END, id ASC
             LIMIT 1"
        );
        $badgeId = intval($badgeRow['id'] ?? 0);
    }

    $db->exec(
        "INSERT INTO core_llm_connector (
            name, connector_type, api_badge_id, api_key, base_url,
            model, max_tokens, temperature, is_default, config
         ) VALUES (
            'Player2 Local', 'player2json', $1, '', 'http://127.0.0.1:4315/v1/chat/completions',
            'player2-app-selected', 750, 1.0, FALSE, '{\"player2_game_key\":\"019cf504-1461-74e7-b4da-045b14e9019d\"}'::jsonb
         )
         ON CONFLICT (name) DO NOTHING",
        [$badgeId > 0 ? $badgeId : null]
    );

    $row = $db->fetchOne(
        "SELECT id
         FROM core_llm_connector
         WHERE LOWER(COALESCE(name, '')) = 'player2 local'
            OR LOWER(COALESCE(connector_type, '')) = 'player2json'
         ORDER BY CASE WHEN LOWER(COALESCE(name, '')) = 'player2 local' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    return intval($row['id'] ?? 0);
}

function stobeQuickstartSetPlayer2AllLlm(sql $db, int $profileId, int $player2ConnectorId): void
{
    if ($profileId <= 0 || $player2ConnectorId <= 0) {
        return;
    }
    $db->exec(
        "UPDATE core_profiles
          SET llm_primary_id = $1,
              llm_secondary_id = $1,
              llm_tertiary_id = $1,
              llm_quaternary_id = $1,
              response_connector = $1,
             diary_connector = $1,
             autochat_connector = $1,
             middleterm_connector = $1,
             backgroundlife_connector = $1,
             dynamic_connector = $1,
             relationship_connector = $1,
             updated_at = NOW()
         WHERE id = $2",
        [$player2ConnectorId, $profileId]
    );
}

function stobeQuickstartRestoreDefaultLlm(sql $db, int $profileId): void
{
    if ($profileId <= 0) {
        return;
    }
    $standardDefault = stobeQuickstartConnectorIdByName($db, 'DeepSeek V4 Flash');
    if ($standardDefault <= 0) {
        $standardDefault = stobeQuickstartConnectorIdByName($db, 'GLM 4.7');
    }
    if ($standardDefault <= 0) {
        $standardDefault = stobeQuickstartConnectorIdByName($db, 'Gemini 2.5 Flash');
    }
    if ($standardDefault <= 0) {
        $standardDefault = stobeQuickstartConnectorIdByName($db, 'OpenRouter Default');
    }
    if ($standardDefault <= 0) {
        return;
    }

    $fastDefault = stobeQuickstartConnectorIdByName($db, 'Gemini 2.5 Flash Lite');
    if ($fastDefault <= 0) {
        $fastDefault = $standardDefault;
    }
    $powerfulDefault = stobeQuickstartConnectorIdByName($db, 'GLM 5.2');
    if ($powerfulDefault <= 0) {
        $powerfulDefault = $standardDefault;
    }
    $experimentalDefault = stobeQuickstartConnectorIdByName($db, 'DeepSeek V4 Pro');
    if ($experimentalDefault <= 0) {
        $experimentalDefault = $standardDefault;
    }

    $diaryDefault = $standardDefault;

    $autochatDefault = stobeQuickstartConnectorIdByName($db, 'Gemini 2.5 Flash Lite');
    if ($autochatDefault <= 0) {
        $autochatDefault = $standardDefault;
    }

    $memoryDefault = stobeQuickstartConnectorIdByName($db, 'Mistral Small 3.2 24B');
    if ($memoryDefault <= 0) {
        $memoryDefault = $standardDefault;
    }
    $backgroundlifeDefault = $memoryDefault;
    $dynamicDefault = $standardDefault;
    $relationshipDefault = $standardDefault;

    // Saving voice/onboarding settings must not undo an explicitly applied local dialogue model.
    $localDialogue = $db->fetchOne(
        "SELECT CASE WHEN primary_llm.config ? 'quickstart_local_provider' THEN p.llm_primary_id END AS primary_id,
                CASE WHEN response_llm.config ? 'quickstart_local_provider' THEN p.response_connector END AS response_id
         FROM core_profiles p
         LEFT JOIN core_llm_connector primary_llm ON primary_llm.id = p.llm_primary_id
         LEFT JOIN core_llm_connector response_llm ON response_llm.id = p.response_connector
         WHERE p.id = $1",
        [$profileId]
    );
    $db->exec(
        "UPDATE core_profiles
          SET llm_primary_id = COALESCE($12::INT, $1),
              llm_secondary_id = $2,
              llm_tertiary_id = $3,
              llm_quaternary_id = $4,
              response_connector = COALESCE($13::INT, $1),
             diary_connector = $5,
             autochat_connector = $6,
             middleterm_connector = $7,
             backgroundlife_connector = $8,
             dynamic_connector = $9,
             relationship_connector = $10,
             updated_at = NOW()
         WHERE id = $11",
        [
            $standardDefault,
            $fastDefault,
            $powerfulDefault,
            $experimentalDefault,
            $diaryDefault,
            $autochatDefault,
            $memoryDefault,
            $backgroundlifeDefault,
            $dynamicDefault,
            $relationshipDefault,
            $profileId,
            $localDialogue['primary_id'] ?? null,
            $localDialogue['response_id'] ?? null
        ]
    );
}

function stobeQuickstartProfileUsesPlayer2(array $profileRow, int $player2ConnectorId): bool
{
    if ($player2ConnectorId <= 0) {
        return false;
    }
    $fields = [
        'llm_primary_id',
        'llm_secondary_id',
        'llm_tertiary_id',
        'llm_quaternary_id',
        'response_connector',
        'diary_connector',
        'autochat_connector',
        'middleterm_connector',
        'backgroundlife_connector',
        'dynamic_connector',
        'relationship_connector',
    ];
    foreach ($fields as $field) {
        if (intval($profileRow[$field] ?? 0) !== $player2ConnectorId) {
            return false;
        }
    }
    return true;
}

$db = $GLOBALS["db"];

// Handles action=local_llm_probe|local_llm_test|local_llm_apply and issues a
// fresh CSRF token; must run before any markup is emitted.
$localLlmSetupAvailable = stobeLocalLlmSetupBoot($db);

if (isset($_GET['tts_probe']) && strval($_GET['tts_probe']) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $rawUrl = trim(strval($_GET['url'] ?? ''));
    $result = [
        'ok' => false,
        'url' => $rawUrl,
        'http_code' => 0,
        'latency_ms' => 0,
        'message' => 'Invalid URL',
    ];

    if ($rawUrl === '') {
        $result['message'] = 'URL is required.';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parts = @parse_url($rawUrl);
    $scheme = strtolower(strval($parts['scheme'] ?? ''));
    $host = trim(strval($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        $result['message'] = 'Use a valid http:// or https:// URL.';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $probe = stobeQuickstartProbeUrl($rawUrl);
    $result['http_code'] = intval($probe['http_code'] ?? 0);
    $result['latency_ms'] = intval($probe['latency_ms'] ?? 0);
    $result['ok'] = !empty($probe['ok']);
    if ($result['ok']) {
        $result['message'] = 'Endpoint reachable.';
    } else {
        $result['message'] = trim(strval($probe['error'] ?? '')) ?: 'Endpoint not reachable.';
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['minime_probe']) && strval($_GET['minime_probe']) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $rawUrl = trim(strval($_GET['url'] ?? stobeQuickstartMiniMeDefaultUrl()));
    $result = [
        'ok' => false,
        'url' => $rawUrl,
        'http_code' => 0,
        'latency_ms' => 0,
        'message' => 'Invalid URL',
    ];

    if ($rawUrl === '') {
        $result['message'] = 'URL is required.';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parts = @parse_url($rawUrl);
    $scheme = strtolower(strval($parts['scheme'] ?? ''));
    $host = trim(strval($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        $result['message'] = 'Use a valid http:// or https:// URL.';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $probe = stobeQuickstartProbeUrl($rawUrl);
    $result['http_code'] = intval($probe['http_code'] ?? 0);
    $result['latency_ms'] = intval($probe['latency_ms'] ?? 0);
    $result['ok'] = !empty($probe['ok']);
    if ($result['ok']) {
        $result['message'] = 'MiniMe service reachable.';
    } else {
        $result['message'] = trim(strval($probe['error'] ?? '')) ?: 'MiniMe service not reachable.';
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strval($_POST['qs_action'] ?? '') === 'save_quickstart') {
    header('Content-Type: application/json');
    try {
        stobeQuickstartUpsertApiKey($db, 'OpenRouter', trim(strval($_POST['openrouter_api_key'] ?? '')));
        $selectedSttDriver = strval($_POST['stt_driver'] ?? 'parakeet');
        if (strtolower(trim($selectedSttDriver)) === 'deepgram') {
            stobeQuickstartUpsertApiKey($db, 'Deepgram', trim(strval($_POST['deepgram_api_key'] ?? '')));
        }
        stobeQuickstartApplySttSelection($db, $selectedSttDriver);

        $selectedTtsId = intval($_POST['tts_connector_id'] ?? 0);
        $allTtsConnectors = stobeQuickstartFetchTtsConnectors($db);
        $ttsNameById = [];
        foreach ($allTtsConnectors as $connectorRow) {
            $id = intval($connectorRow['id'] ?? 0);
            if ($id > 0) {
                $ttsNameById[$id] = strval($connectorRow['name'] ?? '');
            }
        }
        $selectedTtsProvider = '';
        if ($selectedTtsId > 0 && isset($ttsNameById[$selectedTtsId])) {
            $selectedTtsProvider = stobeQuickstartTtsProviderKey($ttsNameById[$selectedTtsId]);
        }
        $usePlayer2AllLlm = stobeQuickstartBool($_POST['use_player2_all_llm'] ?? '0');
        if ($selectedTtsProvider === 'cartesia') {
            stobeQuickstartUpsertApiKey($db, 'Cartesia', trim(strval($_POST['cartesia_api_key'] ?? '')));
        } elseif ($selectedTtsProvider === 'inworld') {
            stobeQuickstartUpsertApiKey($db, 'Inworld', trim(strval($_POST['inworld_api_key'] ?? '')));
            if ($selectedTtsId > 0) {
                $workspace = trim(strval($_POST['inworld_workspace'] ?? ''));
                $ttsConnector = $db->fetchOne(
                    "SELECT config
                     FROM core_tts_connector
                     WHERE id = $1
                     LIMIT 1",
                    [$selectedTtsId]
                );
                $cfg = stobeQuickstartDecodeJsonObject($ttsConnector['config'] ?? '{}');
                $cfg['workspace'] = $workspace;
                $db->exec(
                    "UPDATE core_tts_connector
                     SET config = $1::jsonb
                     WHERE id = $2",
                    [json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $selectedTtsId]
                );
            }
        }

        $defaultProfile = stobeQuickstartFetchDefaultProfile($db);
        $playerFactionProfile = stobeQuickstartEnsurePlayerFactionProfile($db, $defaultProfile);
        $targetProfileIds = stobeQuickstartCollectTargetProfileIds($defaultProfile, $playerFactionProfile);

        if (count($targetProfileIds) > 0) {
            if ($usePlayer2AllLlm) {
                $player2ConnectorId = stobeQuickstartEnsurePlayer2ConnectorId($db);
                foreach ($targetProfileIds as $profileId) {
                    stobeQuickstartSetPlayer2AllLlm($db, intval($profileId), $player2ConnectorId);
                }
            } else {
                foreach ($targetProfileIds as $profileId) {
                    stobeQuickstartRestoreDefaultLlm($db, intval($profileId));
                }
            }
        }
        if ($selectedTtsId > 0 && count($targetProfileIds) > 0) {
            foreach ($targetProfileIds as $profileId) {
                $db->exec(
                    "UPDATE core_profiles
                     SET tts_connector_id = $1,
                         updated_at = NOW()
                     WHERE id = $2",
                    [$selectedTtsId, intval($profileId)]
                );
            }
        }

        // Response/diary connectors remain unchanged by quickstart.
        stobeMarkQuickstartCompleted(true);
        echo json_encode(['ok' => true]);
    } catch (Throwable $exception) {
        stobeLogException($exception, "Quickstart save failed");
        echo json_encode(['ok' => false, 'error' => 'Failed to save quickstart settings.']);
    }
    exit;
}

$webRoot = stobeQuickstartWebRoot();
$TITLE = "StobeServer - Quickstart";

$apiKeyMap = stobeQuickstartFetchApiKeyMap($db);
$defaultProfile = stobeQuickstartFetchDefaultProfile($db);
$playerFactionProfile = stobeQuickstartEnsurePlayerFactionProfile($db, $defaultProfile);
$targetProfileRows = [$defaultProfile];
if (intval($playerFactionProfile['id'] ?? 0) > 0) {
    $targetProfileRows[] = $playerFactionProfile;
}
$ttsConnectors = stobeQuickstartFilterDefaultTtsConnectors(stobeQuickstartFetchTtsConnectors($db));
$ttsById = [];
$recommendedTts = [];
$otherTts = [];
foreach ($ttsConnectors as $connectorRow) {
    $id = intval($connectorRow['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $name = strval($connectorRow['name'] ?? '');
    $normalizedName = strtolower(trim($name));
    $ttsById[$id] = $connectorRow;
    if ($normalizedName === 'pocket tts default' || str_contains($normalizedName, 'pocket tts')) {
        $recommendedTts[] = $connectorRow;
    } else {
        $otherTts[] = $connectorRow;
    }
}

$currentTtsConnectorId = intval($defaultProfile['tts_connector_id'] ?? 0);
$ttsConnectorId = $currentTtsConnectorId;
if ($ttsConnectorId <= 0 || !isset($ttsById[$ttsConnectorId])) {
    if (count($recommendedTts) > 0) {
        $ttsConnectorId = intval($recommendedTts[0]['id'] ?? 0);
    } elseif (count($otherTts) > 0) {
        $ttsConnectorId = intval($otherTts[0]['id'] ?? 0);
    }
}
$openrouterApiKey = strval($apiKeyMap['openrouter'] ?? '');
$cartesiaApiKey = strval($apiKeyMap['cartesia'] ?? '');
$inworldApiKey = strval($apiKeyMap['inworld'] ?? '');
$deepgramApiKey = strval($apiKeyMap['deepgram'] ?? '');
$quickstartSttConnector = new STTConnector();
$quickstartSttRow = $quickstartSttConnector->getActive() ?: [];
$quickstartSttDriver = $quickstartSttConnector->normalizeDriverValue($quickstartSttRow['driver'] ?? 'parakeet');
$localLlmProviders = stobeLocalLlmSetupProviders();
$localLlmCsrfToken = stobeLocalLlmSetupCsrfToken();
$localLlmReady = $localLlmSetupAvailable && $localLlmCsrfToken !== '';
$player2ConnectorId = stobeQuickstartEnsurePlayer2ConnectorId($db);
$usePlayer2AllLlm = count($targetProfileRows) > 0;
foreach ($targetProfileRows as $profileRow) {
    if (!stobeQuickstartProfileUsesPlayer2($profileRow, $player2ConnectorId)) {
        $usePlayer2AllLlm = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($TITLE) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= h($webRoot) ?>/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= h($webRoot) ?>/ui/css/main.css">
    <link rel="stylesheet" href="<?= h($webRoot) ?>/ui/css/navbar.css">
    <style>
        body { padding-top: 80px; background: #2c2c2c; color: #f8f9fa; }
        main.page-wrap { padding: 20px 10px 40px 10px; max-width: 980px; margin: 0 auto; }
        .qs-header {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 18px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .qs-title {
            margin: 0 0 8px 0;
            font-family: "MagicCards", serif;
            color: #e6b76c;
            font-size: 2rem;
            letter-spacing: 1px;
            word-spacing: 8px;
        }
        .qs-subtitle { margin: 0; color: #c6c6c6; }
        .qs-section {
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 16px;
            margin-bottom: 14px;
        }
        .qs-section h2 {
            margin: 0 0 10px 0;
            font-family: "MagicCards", serif;
            color: #e6b76c;
            font-size: 1.25rem;
            letter-spacing: 1px;
            word-spacing: 6px;
        }
        .qs-help { color: #9fb1c9; font-size: 0.92rem; margin-bottom: 10px; }
        .qs-field { margin-bottom: 10px; }
        .qs-field:last-child { margin-bottom: 0; }
        .qs-field label { display: block; font-weight: 600; color: #e6b76c; margin-bottom: 6px; }
        .qs-field input[type="text"], .qs-field input[type="password"], .qs-field select {
            width: 100%;
            background: rgba(26, 26, 26, 0.8);
            color: #e9efff;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .qs-inline { display: flex; align-items: center; gap: 8px; }
        .qs-inline input { flex: 1 1 auto; min-width: 0; }
        .qs-inline button, .qs-btn-row button {
            border: 1px solid rgba(138, 155, 182, 0.35);
            background: #2f3b52;
            color: #fff;
            border-radius: 8px;
            padding: 10px 12px;
            cursor: pointer;
        }
        .qs-link { color: #e6b76c; text-decoration: underline; font-size: 0.85rem; }
        .qs-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        .qs-callout {
            border: 1px solid rgba(230, 183, 108, 0.35);
            border-radius: 8px;
            background: rgba(230, 183, 108, 0.08);
            color: #f6ddbd;
            padding: 10px 12px;
            margin-top: 10px;
            margin-bottom: 10px;
            font-size: 0.92rem;
        }
        .qs-status {
            margin-top: 8px;
            font-size: 0.88rem;
            color: #b8c1d4;
        }
        .qs-status.ok { color: #8ee0a2; }
        .qs-status.err { color: #ffb7a6; }
        .btn-stobe-save {
            border: 1px solid #2b7d3d;
            background: #176529;
            color: #fff;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 600;
        }
        .btn-stobe-save:disabled { opacity: 0.7; cursor: wait; }
        .qs-note { color: #9fb1c9; font-size: 0.9rem; margin: 0; }
        .qs-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: #e6b76c;
        }
        .qs-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        .qs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
        .qs-btn-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .qs-btn-row button:disabled { opacity: 0.55; cursor: not-allowed; }
        .qs-btn-row button.qs-apply { border-color: #2b7d3d; background: #176529; font-weight: 600; }
        .qs-hint { color: #9fb1c9; font-size: 0.85rem; margin: 6px 0 0 0; }
        .qs-callout code, .qs-hint code { color: #f0c98a; background: rgba(0, 0, 0, 0.28); padding: 1px 4px; border-radius: 4px; }
        .qs-callout summary { cursor: pointer; }
    </style>
</head>
<body>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<main class="page-wrap">
    <div class="qs-header">
        <h1 class="qs-title">Quickstart Menu</h1>
    </div>

    <form id="quickstart-form">
        <section class="qs-section">
            <h2>Player 2 Connector</h2>
            <p class="qs-help">Route Default Profile and Player Faction profile LLM connectors through your local Player2 connector.</p>
            <label class="qs-check" for="use_player2_all_llm">
                <input id="use_player2_all_llm" type="checkbox" name="use_player2_all_llm" value="1"<?= $usePlayer2AllLlm ? ' checked' : '' ?>>
                <span>Use Player2 for Stobe LLM handling</span>
            </label>
        </section>

        <section class="qs-section" id="openrouter-key-section">
            <h2>OpenRouter API Key</h2>
            <div class="qs-field">
                <label for="openrouter_api_key">OpenRouter Key</label>
                <div class="qs-inline">
                    <input id="openrouter_api_key" type="password" name="openrouter_api_key" value="<?= h($openrouterApiKey) ?>" placeholder="Paste OpenRouter API key">
                    <button type="button" onclick="toggleApiInput(this)">Show</button>
                </div>
                <a class="qs-link" href="https://openrouter.ai/keys" target="_blank" rel="noopener">Create OpenRouter key</a>
            </div>
        </section>

        <section class="qs-section">
            <h2>TTS Connector</h2>
            <p class="qs-help">Choose one of the default TTS connectors (OmniVoice, Pocket TTS, XTTS, Chatterbox, Cartesia, Inworld). This applies to both Default Profile and Player Faction.</p>
            <div class="qs-field">
                <label for="tts_connector_id">TTS Connector</label>
                <select id="tts_connector_id" name="tts_connector_id">
                    <?php if (count($recommendedTts) > 0): ?>
                        <optgroup label="Recommended">
                            <?php foreach ($recommendedTts as $connector): ?>
                                <?php
                                    $id = intval($connector['id'] ?? 0);
                                    $name = strval($connector['name'] ?? ('Connector #' . $id));
                                    $provider = stobeQuickstartTtsProviderKey($name);
                                    $baseUrl = trim(strval($connector['base_url'] ?? ''));
                                    $config = stobeQuickstartDecodeJsonObject($connector['config'] ?? '{}');
                                    $workspace = trim(strval($config['workspace'] ?? ''));
                                    if ($baseUrl === '') {
                                        $baseUrl = stobeQuickstartLocalTtsDefaultUrl($provider);
                                    }
                                ?>
                                <option
                                    value="<?= $id ?>"
                                    data-provider="<?= h($provider) ?>"
                                    data-url="<?= h($baseUrl) ?>"
                                    data-workspace="<?= h($workspace) ?>"
                                    <?= $id === $ttsConnectorId ? ' selected' : '' ?>
                                ><?= h($name) ?> (Recommended)</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if (count($otherTts) > 0): ?>
                        <optgroup label="Other TTS Connectors">
                            <?php foreach ($otherTts as $connector): ?>
                                <?php
                                    $id = intval($connector['id'] ?? 0);
                                    $name = strval($connector['name'] ?? ('Connector #' . $id));
                                    $provider = stobeQuickstartTtsProviderKey($name);
                                    $baseUrl = trim(strval($connector['base_url'] ?? ''));
                                    $config = stobeQuickstartDecodeJsonObject($connector['config'] ?? '{}');
                                    $workspace = trim(strval($config['workspace'] ?? ''));
                                    if ($baseUrl === '') {
                                        $baseUrl = stobeQuickstartLocalTtsDefaultUrl($provider);
                                    }
                                ?>
                                <option
                                    value="<?= $id ?>"
                                    data-provider="<?= h($provider) ?>"
                                    data-url="<?= h($baseUrl) ?>"
                                    data-workspace="<?= h($workspace) ?>"
                                    <?= $id === $ttsConnectorId ? ' selected' : '' ?>
                                ><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div id="local-tts-block" class="qs-field" style="display:none;">
                <div class="qs-callout">
                    For `Pocket TTS`, `XTTS`, `Chatterbox`, and `OmniVoice`, ensure the service is installed and running in your Dwemer Distro.
                </div>
                <input id="local_tts_probe_url" type="hidden" value="">
                <div id="local_tts_probe_status" class="qs-status"></div>
            </div>

            <div id="cartesia-api-block" class="qs-field" style="display:none;">
                <label for="cartesia_api_key">Cartesia API Key</label>
                <div class="qs-inline">
                    <input id="cartesia_api_key" type="password" name="cartesia_api_key" value="<?= h($cartesiaApiKey) ?>" placeholder="Paste Cartesia API key">
                    <button type="button" onclick="toggleApiInput(this)">Show</button>
                </div>
                <a class="qs-link" href="https://play.cartesia.ai/console" target="_blank" rel="noopener">Create Cartesia key</a>
            </div>

            <div id="inworld-api-block" class="qs-field" style="display:none;">
                <label for="inworld_api_key">Inworld API Key</label>
                <div class="qs-inline">
                    <input id="inworld_api_key" type="password" name="inworld_api_key" value="<?= h($inworldApiKey) ?>" placeholder="Paste Inworld API key">
                    <button type="button" onclick="toggleApiInput(this)">Show</button>
                </div>
                <a class="qs-link" href="https://studio.inworld.ai/" target="_blank" rel="noopener">Create Inworld key</a>
            </div>

            <div id="inworld-workspace-block" class="qs-field" style="display:none;">
                <label for="inworld_workspace">Workspace ID (Inworld)</label>
                <input id="inworld_workspace" type="text" name="inworld_workspace" value="" placeholder="Paste Inworld workspace ID" disabled>
            </div>
        </section>

        <section class="qs-section">
            <h2>STT Service</h2>
            <p class="qs-help">Choose the speech-to-text service used by Stobe push-to-talk. Parakeet is installed through Dwemer Distro and is the default.</p>
            <div class="qs-field">
                <label for="stt_driver">STT Service</label>
                <select id="stt_driver" name="stt_driver">
                    <optgroup label="Recommended">
                        <option value="parakeet"<?= $quickstartSttDriver === 'parakeet' ? ' selected' : '' ?>>Parakeet (Recommended)</option>
                        <option value="deepgram"<?= $quickstartSttDriver === 'deepgram' ? ' selected' : '' ?>>Deepgram (Recommended)</option>
                    </optgroup>
                </select>
            </div>
            <div id="deepgram-api-block" class="qs-field"<?= $quickstartSttDriver === 'deepgram' ? '' : ' style="display:none;"' ?>>
                <label for="deepgram_api_key">Deepgram API Key</label>
                <div class="qs-inline">
                    <input id="deepgram_api_key" type="password" name="deepgram_api_key" value="<?= h($deepgramApiKey) ?>" placeholder="Paste Deepgram API key">
                    <button type="button" onclick="toggleApiInput(this)">Show</button>
                </div>
                <a class="qs-link" href="https://console.deepgram.com/" target="_blank" rel="noopener">Create Deepgram key</a>
            </div>
            <p class="qs-note">More STT providers are available from Configuration &gt; STT.</p>
        </section>

        <section class="qs-section">
            <h2>MiniMe Service</h2>
            <p class="qs-help">Checks if MiniMe is reachable at the local default endpoint.</p>
            <div class="qs-field">
                <input id="minime_probe_url" type="hidden" value="<?= h(stobeQuickstartMiniMeDefaultUrl()) ?>">
                <div id="minime_probe_status" class="qs-status"></div>
            </div>
        </section>

        <div class="qs-actions">
            <p class="qs-note">Saving marks quickstart complete and opens the dashboard.</p>
            <p class="qs-note">Once saved you can start the game and play!</p>
            <button id="qs-save-btn" type="button" class="btn-stobe-save" onclick="saveQuickstart()">Save and Continue</button>
        </div>
    </form>

    <?php if ($localLlmSetupAvailable): ?>
    <form id="local-llm-form" class="qs-section" autocomplete="off" onsubmit="return false;">
        <h2>Local LLM (Optional)</h2>
        <p class="qs-help">Use a local model for dialogue. Probe, test, then apply it separately from Quickstart.</p>

        <div class="qs-callout">
            The address must be reachable from StobeServer. In WSL, <code>127.0.0.1</code> points to WSL. For a Windows model server, use its private LAN IP.
            <details><summary>Connection help</summary>Enable network access in your model server and allow its port through Windows Firewall. Use <code>0.0.0.0</code> as the listening address, not as the endpoint. This setup accepts localhost and private LAN IPs only.</details>
        </div>

        <div class="qs-grid">
            <div class="qs-field">
                <label for="local_llm_provider">Provider</label>
                <select id="local_llm_provider" name="provider">
                    <?php $localLlmFirstProvider = true; ?>
                    <?php foreach ($localLlmProviders as $providerKey => $providerInfo): ?>
                        <option value="<?= h($providerKey) ?>" data-url="<?= h($providerInfo['base_url']) ?>"
                            data-model-hint="<?= h($providerInfo['model_hint']) ?>"
                            data-bind-hint="<?= h($providerInfo['bind_hint']) ?>"<?= $localLlmFirstProvider ? ' selected' : '' ?>><?= h($providerInfo['label']) ?></option>
                        <?php $localLlmFirstProvider = false; ?>
                    <?php endforeach; ?>
                </select>
                <p class="qs-hint" id="local_llm_bind_hint"></p>
            </div>
            <div class="qs-field">
                <label for="local_llm_base_url">Endpoint URL</label>
                <input id="local_llm_base_url" type="text" name="base_url" value="" placeholder="http://127.0.0.1:1234/v1/chat/completions" spellcheck="false">
                <p class="qs-hint">The full OpenAI-compatible chat completions path, including the port.</p>
            </div>
        </div>

        <div class="qs-grid">
            <div class="qs-field">
                <label for="local_llm_model">Model</label>
                <input id="local_llm_model" type="text" name="model" list="local_llm_model_options" value="" placeholder="Probe for models, or type a model id" spellcheck="false" autocomplete="off">
                <datalist id="local_llm_model_options"></datalist>
                <p class="qs-hint" id="local_llm_model_hint"></p>
            </div>
            <div class="qs-field">
                <label for="local_llm_api_key">API Key (optional)</label>
                <div class="qs-inline">
                    <input id="local_llm_api_key" type="password" name="api_key" value="" placeholder="Leave blank if not required" autocomplete="new-password">
                    <button type="button" onclick="toggleApiInput(this)">Show</button>
                </div>
                <p class="qs-hint">Most local runtimes ignore this. It is never displayed back on this page.</p>
            </div>
        </div>

        <div class="qs-field">
            <label for="local_llm_target">Apply to</label>
            <select id="local_llm_target" name="target">
                <option value="both">Default Profile and Player Faction</option>
                <option value="default">Default Profile only</option>
                <option value="player_faction">Player Faction only</option>
            </select>
        </div>

        <p class="qs-note">Only selected profiles' primary dialogue changes. Existing connectors and background tasks stay unchanged. Saving Quickstart keeps local dialogue selected; choose Player2 or change the profile connector to replace it.</p>

        <div class="qs-btn-row">
            <button type="button" id="local_llm_probe_btn" onclick="localLlmProbe()"<?= $localLlmReady ? '' : ' disabled' ?>>Probe models</button>
            <button type="button" id="local_llm_test_btn" onclick="localLlmTest()"<?= $localLlmReady ? '' : ' disabled' ?>>Send test message</button>
            <button type="button" id="local_llm_apply_btn" class="qs-apply" onclick="localLlmApply()" aria-describedby="local_llm_apply_hint" disabled>Apply to dialogue</button>
        </div>
        <p class="qs-hint" id="local_llm_apply_hint">Apply unlocks after a successful test with the current provider, endpoint, model, and key.</p>

        <div id="local_llm_status" class="qs-status" role="status" aria-live="polite"></div>

        <?php if (!$localLlmReady): ?>
            <div class="qs-callout">Local LLM setup is unavailable because no setup token was issued. Reload this page, or configure the connector from Configuration &gt; LLM.</div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" id="local_llm_csrf" value="<?= h($localLlmCsrfToken) ?>">
    </form>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const WEB_ROOT = <?= json_encode($webRoot) ?>;
const SAVE_URL = "quickstart.php";

function toggleApiInput(button) {
    const wrapper = button && button.parentElement ? button.parentElement : null;
    if (!wrapper) return;
    const input = wrapper.querySelector('input');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = 'Show';
    }
}

function updateConditionalApiFields() {
    const select = document.getElementById('tts_connector_id');
    const selected = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    const provider = selected ? (selected.getAttribute('data-provider') || 'none') : 'none';
    const endpointUrl = selected ? (selected.getAttribute('data-url') || '') : '';
    const inworldWorkspace = selected ? (selected.getAttribute('data-workspace') || '') : '';

    const localBlock = document.getElementById('local-tts-block');
    const localUrlInput = document.getElementById('local_tts_probe_url');
    const localStatus = document.getElementById('local_tts_probe_status');
    const cartesiaBlock = document.getElementById('cartesia-api-block');
    const inworldBlock = document.getElementById('inworld-api-block');
    const inworldWorkspaceBlock = document.getElementById('inworld-workspace-block');
    const cartesiaInput = document.getElementById('cartesia_api_key');
    const inworldInput = document.getElementById('inworld_api_key');
    const inworldWorkspaceInput = document.getElementById('inworld_workspace');

    const isLocalTts = provider === 'omnivoice' || provider === 'pocket_tts' || provider === 'xtts' || provider === 'chatterbox';
    const showCartesia = provider === 'cartesia';
    const showInworld = provider === 'inworld';

    if (localBlock) localBlock.style.display = isLocalTts ? '' : 'none';
    if (localUrlInput && isLocalTts) {
        localUrlInput.disabled = false;
        if (endpointUrl !== '') {
            localUrlInput.value = endpointUrl;
        }
    } else if (localUrlInput) {
        localUrlInput.disabled = true;
    }
    if (localStatus) {
        localStatus.textContent = '';
        localStatus.classList.remove('ok', 'err');
    }
    if (isLocalTts) {
        checkLocalTtsEndpoint();
    }

    if (cartesiaBlock) cartesiaBlock.style.display = showCartesia ? '' : 'none';
    if (inworldBlock) inworldBlock.style.display = showInworld ? '' : 'none';
    if (inworldWorkspaceBlock) inworldWorkspaceBlock.style.display = showInworld ? '' : 'none';
    if (cartesiaInput) cartesiaInput.disabled = !showCartesia;
    if (inworldInput) inworldInput.disabled = !showInworld;
    if (inworldWorkspaceInput) {
        inworldWorkspaceInput.disabled = !showInworld;
        if (showInworld) {
            inworldWorkspaceInput.value = inworldWorkspace;
        } else {
            inworldWorkspaceInput.value = '';
        }
    }
}

function updatePlayer2QuickstartUI() {
    const player2Toggle = document.getElementById('use_player2_all_llm');
    const openrouterSection = document.getElementById('openrouter-key-section');
    const enabled = !!(player2Toggle && player2Toggle.checked);
    if (openrouterSection) {
        openrouterSection.style.display = enabled ? 'none' : '';
    }
}

async function checkLocalTtsEndpoint() {
    const input = document.getElementById('local_tts_probe_url');
    const status = document.getElementById('local_tts_probe_status');
    if (!input || !status) return;

    const url = String(input.value || '').trim();
    if (url === '') {
        status.textContent = 'Enter an endpoint URL first.';
        status.classList.remove('ok');
        status.classList.add('err');
        return;
    }

    status.textContent = 'Checking endpoint...';
    status.classList.remove('ok', 'err');

    try {
        const probeUrl = SAVE_URL + '?tts_probe=1&url=' + encodeURIComponent(url);
        const response = await fetch(probeUrl, { cache: 'no-store', credentials: 'same-origin' });
        const result = await response.json();
        const http = Number(result && result.http_code ? result.http_code : 0);
        const latency = Number(result && result.latency_ms ? result.latency_ms : 0);
        const message = String((result && result.message) ? result.message : 'Probe failed.');
        if (result && result.ok) {
            status.textContent = `Reachable (${http}) in ${latency} ms. ${message}`;
            status.classList.remove('err');
            status.classList.add('ok');
        } else {
            status.textContent = `Not reachable (${http || 0}) in ${latency} ms. ${message}`;
            status.classList.remove('ok');
            status.classList.add('err');
        }
    } catch (_error) {
        status.textContent = 'Endpoint probe failed.';
        status.classList.remove('ok');
        status.classList.add('err');
    }
}

async function checkMiniMeEndpoint() {
    const input = document.getElementById('minime_probe_url');
    const status = document.getElementById('minime_probe_status');
    if (!input || !status) return;

    const url = String(input.value || '').trim();
    if (url === '') {
        status.textContent = 'MiniMe endpoint URL is empty.';
        status.classList.remove('ok');
        status.classList.add('err');
        return;
    }

    status.textContent = 'Checking MiniMe service...';
    status.classList.remove('ok', 'err');

    try {
        const probeUrl = SAVE_URL + '?minime_probe=1&url=' + encodeURIComponent(url);
        const response = await fetch(probeUrl, { cache: 'no-store', credentials: 'same-origin' });
        const result = await response.json();
        const http = Number(result && result.http_code ? result.http_code : 0);
        const latency = Number(result && result.latency_ms ? result.latency_ms : 0);
        const message = String((result && result.message) ? result.message : 'MiniMe probe failed.');
        if (result && result.ok) {
            status.textContent = `MiniMe reachable (${http}) in ${latency} ms. ${message}`;
            status.classList.remove('err');
            status.classList.add('ok');
        } else {
            status.textContent = `MiniMe not reachable (${http || 0}) in ${latency} ms. ${message}`;
            status.classList.remove('ok');
            status.classList.add('err');
        }
    } catch (_error) {
        status.textContent = 'MiniMe probe failed.';
        status.classList.remove('ok');
        status.classList.add('err');
    }
}

async function saveQuickstart() {
    const button = document.getElementById('qs-save-btn');
    const finishUrl = WEB_ROOT + "/ui/home.php";
    if (button) button.disabled = true;

    try {
        const form = document.getElementById('quickstart-form');
        const payload = new FormData(form);
        payload.append('qs_action', 'save_quickstart');

        const response = await fetch(SAVE_URL, {
            method: 'POST',
            body: payload,
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!result || !result.ok) {
            throw new Error((result && result.error) ? result.error : "Quickstart save failed.");
        }
        window.location.href = finishUrl;
    } catch (error) {
        try { alert(error && error.message ? error.message : "Quickstart save failed."); } catch (_ignored) {}
        if (button) button.disabled = false;
    }
}

const LOCAL_LLM_ENABLED = <?= $localLlmSetupAvailable ? 'true' : 'false' ?>;
const LOCAL_LLM_READY = <?= $localLlmReady ? 'true' : 'false' ?>;
const LOCAL_LLM_CHECK_IDS = ['local_llm_probe_btn', 'local_llm_test_btn'];
const LOCAL_LLM_SETTING_IDS = ['local_llm_provider', 'local_llm_base_url', 'local_llm_model', 'local_llm_api_key'];
let localLlmTestedSettings = '';

function localLlmEl(id) {
    return document.getElementById(id);
}

function localLlmValue(id) {
    const el = localLlmEl(id);
    return el ? String(el.value || '').trim() : '';
}

function localLlmSetStatus(text, state) {
    const status = localLlmEl('local_llm_status');
    if (!status) return;
    status.textContent = String(text == null ? '' : text);
    status.classList.remove('ok', 'err');
    if (state === 'ok' || state === 'err') status.classList.add(state);
}

// The backend refuses an apply unless these exact settings just passed a test.
function localLlmSettingsKey() {
    return LOCAL_LLM_SETTING_IDS.map(localLlmValue).join('\u0000');
}

function localLlmApplyAllowed() {
    return LOCAL_LLM_READY && localLlmTestedSettings !== '' && localLlmTestedSettings === localLlmSettingsKey();
}

function localLlmRefreshApplyButton() {
    const button = localLlmEl('local_llm_apply_btn');
    if (button) button.disabled = !localLlmApplyAllowed();
}

function localLlmSetBusy(busy) {
    LOCAL_LLM_CHECK_IDS.forEach(function (id) {
        const button = localLlmEl(id);
        if (button) button.disabled = busy ? true : !LOCAL_LLM_READY;
    });
    const applyButton = localLlmEl('local_llm_apply_btn');
    if (applyButton) applyButton.disabled = busy ? true : !localLlmApplyAllowed();
}

function localLlmSelectedOption(id) {
    const select = localLlmEl(id);
    if (!select || select.selectedIndex < 0) return null;
    return select.options[select.selectedIndex];
}

function localLlmSyncProviderFields(resetUrl) {
    const option = localLlmSelectedOption('local_llm_provider');
    const urlInput = localLlmEl('local_llm_base_url');
    const modelHint = localLlmEl('local_llm_model_hint');
    const bindHint = localLlmEl('local_llm_bind_hint');
    const defaultUrl = option ? String(option.getAttribute('data-url') || '') : '';

    if (urlInput && (resetUrl || String(urlInput.value || '').trim() === '')) {
        urlInput.value = defaultUrl;
    }
    if (urlInput) urlInput.placeholder = defaultUrl;
    if (modelHint) modelHint.textContent = option ? String(option.getAttribute('data-model-hint') || '') : '';
    if (bindHint) bindHint.textContent = option ? String(option.getAttribute('data-bind-hint') || '') : '';
}

function localLlmSetModelOptions(models) {
    const list = localLlmEl('local_llm_model_options');
    if (!list) return 0;
    list.textContent = '';
    const values = Array.isArray(models) ? models : [];
    let count = 0;
    values.forEach(function (model) {
        const value = String(model == null ? '' : model).trim();
        if (value === '') return;
        const option = document.createElement('option');
        option.value = value;
        list.appendChild(option);
        count += 1;
    });
    return count;
}

function localLlmOnProviderChange() {
    const select = localLlmEl('local_llm_provider');
    const presets = select ? Array.from(select.options)
        .map(function (option) { return String(option.getAttribute('data-url') || '').trim(); }) : [];
    const current = localLlmValue('local_llm_base_url');
    const isPreset = (current === '' || presets.indexOf(current) !== -1);
    localLlmSyncProviderFields(isPreset);
    localLlmSetModelOptions([]);
    localLlmTestedSettings = '';
    localLlmRefreshApplyButton();
    localLlmSetStatus(isPreset ? '' : 'Endpoint URL kept as you entered it. Clear the field to use this provider default.');
}

function localLlmValidEndpoint() {
    const url = localLlmValue('local_llm_base_url');
    if (url === '') {
        localLlmSetStatus('Enter the endpoint URL first.', 'err');
        return false;
    }
    if (!/^https?:\/\//i.test(url)) {
        localLlmSetStatus('Use a full http:// or https:// endpoint URL.', 'err');
        return false;
    }
    return true;
}

async function localLlmRequest(action, extraFields) {
    const payload = new FormData();
    payload.append('action', action);
    payload.append('csrf_token', localLlmValue('local_llm_csrf'));
    payload.append('provider', localLlmValue('local_llm_provider'));
    payload.append('base_url', localLlmValue('local_llm_base_url'));
    payload.append('api_key', localLlmValue('local_llm_api_key'));
    Object.keys(extraFields || {}).forEach(function (key) {
        payload.append(key, String(extraFields[key] == null ? '' : extraFields[key]));
    });

    const response = await fetch(SAVE_URL, {
        method: 'POST',
        body: payload,
        cache: 'no-store',
        credentials: 'same-origin'
    });
    const result = await response.json();
    return (result && typeof result === 'object') ? result : {};
}

function localLlmResultMessage(result, fallback) {
    const message = String((result && result.message) ? result.message : '').trim();
    return message !== '' ? message : fallback;
}

async function localLlmProbe() {
    if (!LOCAL_LLM_READY || !localLlmValidEndpoint()) return;
    localLlmSetBusy(true);
    localLlmSetStatus('Probing endpoint for models...');
    try {
        const result = await localLlmRequest('local_llm_probe', {});
        const count = localLlmSetModelOptions(result.models);
        const message = localLlmResultMessage(result, result.success ? 'Endpoint reachable.' : 'Probe failed.');
        if (!result.success) {
            localLlmSetStatus(message, 'err');
            return;
        }
        const modelInput = localLlmEl('local_llm_model');
        const list = localLlmEl('local_llm_model_options');
        if (modelInput && String(modelInput.value || '').trim() === '' && list && list.firstElementChild) {
            modelInput.value = list.firstElementChild.value;
        }
        localLlmSetStatus(message + ' ' + count + (count === 1 ? ' model listed.' : ' models listed.'), 'ok');
    } catch (_error) {
        localLlmSetStatus('Probe request failed. Check that the server can reach the endpoint.', 'err');
    } finally {
        localLlmSetBusy(false);
    }
}

async function localLlmTest() {
    if (!LOCAL_LLM_READY || !localLlmValidEndpoint()) return;
    if (localLlmValue('local_llm_model') === '') {
        localLlmSetStatus('Probe for models or type a model id before testing.', 'err');
        return;
    }
    const attempted = localLlmSettingsKey();
    localLlmTestedSettings = '';
    localLlmSetBusy(true);
    localLlmSetStatus('Sending a short test message...');
    try {
        const result = await localLlmRequest('local_llm_test', { model: localLlmValue('local_llm_model') });
        const message = localLlmResultMessage(result, result.success ? 'Test completed.' : 'Test failed.');
        localLlmSetStatus(message, result.success ? 'ok' : 'err');
        if (result.success) {
            localLlmTestedSettings = attempted;
        }
    } catch (_error) {
        localLlmSetStatus('Test request failed. Check that the server can reach the endpoint.', 'err');
    } finally {
        localLlmSetBusy(false);
    }
}

async function localLlmApply() {
    if (!localLlmApplyAllowed()) {
        localLlmSetStatus('Run a successful test with these settings before applying them.', 'err');
        return;
    }
    const targetOption = localLlmSelectedOption('local_llm_target');
    const targetLabel = targetOption ? String(targetOption.textContent || '').trim() : 'the selected profiles';
    const confirmed = window.confirm(
        'Set the primary dialogue connector for ' + targetLabel + '? '
        + 'Background slots and your existing connectors are left unchanged.'
    );
    if (!confirmed) return;

    localLlmSetBusy(true);
    localLlmSetStatus('Applying local LLM to dialogue...');
    try {
        const result = await localLlmRequest('local_llm_apply', {
            model: localLlmValue('local_llm_model'),
            target: localLlmValue('local_llm_target')
        });
        const message = localLlmResultMessage(result, result.success ? 'Local LLM applied.' : 'Apply failed.');
        if (!result.success) {
            localLlmSetStatus(message, 'err');
            return;
        }
        const connectorId = Number(result.connector_id);
        const suffix = (Number.isFinite(connectorId) && connectorId > 0) ? ' Connector #' + connectorId + '.' : '';
        localLlmSetStatus(message + suffix, 'ok');
    } catch (_error) {
        localLlmSetStatus('Could not confirm the save. Check the selected profiles before trying again.', 'err');
    } finally {
        localLlmSetBusy(false);
    }
}

function initLocalLlmSetup() {
    if (!LOCAL_LLM_ENABLED) return;
    const providerSelect = localLlmEl('local_llm_provider');
    if (providerSelect) {
        providerSelect.addEventListener('change', localLlmOnProviderChange);
    }
    const form = localLlmEl('local-llm-form');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            localLlmProbe();
        });
    }
    LOCAL_LLM_SETTING_IDS.forEach(function (id) {
        const field = localLlmEl(id);
        if (field) {
            field.addEventListener('input', localLlmRefreshApplyButton);
        }
    });
    localLlmSyncProviderFields(true);
    localLlmRefreshApplyButton();
}

document.addEventListener('DOMContentLoaded', function () {
    const sttSelect = document.getElementById('stt_driver');
    const deepgramBlock = document.getElementById('deepgram-api-block');
    if (sttSelect && deepgramBlock) {
        const syncStt = () => { deepgramBlock.style.display = sttSelect.value === 'deepgram' ? '' : 'none'; };
        sttSelect.addEventListener('change', syncStt);
        syncStt();
    }
    const select = document.getElementById('tts_connector_id');
    const player2Toggle = document.getElementById('use_player2_all_llm');
    if (select) {
        select.addEventListener('change', updateConditionalApiFields);
    }
    if (player2Toggle) {
        player2Toggle.addEventListener('change', updatePlayer2QuickstartUI);
    }
    updatePlayer2QuickstartUI();
    updateConditionalApiFields();
    checkMiniMeEndpoint();
    initLocalLlmSetup();
});
</script>
</body>
</html>

