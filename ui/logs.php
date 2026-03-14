<?php
/**
 * StobeServer Logs dashboard.
 * Rewired for Stobe log files and Stobe DB audit tables.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function sanitizeId(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
    $value = trim($value, '_');
    return $value === '' ? 'log' : $value;
}

function tailFile(string $filepath, int $lines = 2000): array
{
    if (!is_file($filepath) || !is_readable($filepath)) {
        return [];
    }

    $file = @fopen($filepath, "r");
    if (!$file) {
        return [];
    }

    $bufferSize = 4096;
    $chunk = "";
    $output = [];

    fseek($file, 0, SEEK_END);
    $pos = ftell($file);
    if ($pos === false) {
        fclose($file);
        return [];
    }

    while ($pos > 0 && count($output) < $lines) {
        $len = min($pos, $bufferSize);
        $pos -= $len;
        fseek($file, $pos);
        $chunk = fread($file, $len) . $chunk;

        while (($nl = strrpos($chunk, "\n")) !== false && count($output) < $lines) {
            array_unshift($output, trim(substr($chunk, $nl + 1), "\r\n"));
            $chunk = substr($chunk, 0, $nl);
        }
    }

    if ($chunk !== "" && count($output) < $lines) {
        array_unshift($output, trim($chunk, "\r\n"));
    }

    fclose($file);
    return array_values(array_filter($output, static fn($line) => $line !== ""));
}

function parseStructuredLogEntries(array $lines): array
{
    $entries = [];
    foreach ($lines as $line) {
        $line = trim(strval($line));
        if ($line === "") {
            continue;
        }

        if (preg_match('/^\[(.*?)\]\s+\[(TRACE|DEBUG|INFO|WARN|ERROR)\]\s*(.*)$/i', $line, $matches) === 1) {
            $entries[] = [
                "timestamp" => $matches[1],
                "level" => strtolower($matches[2]),
                "message" => trim($matches[3]),
            ];
        } else {
            $entries[] = [
                "timestamp" => "",
                "level" => "",
                "message" => $line,
            ];
        }
    }
    return $entries;
}

function timestampToIso8601(string $timestamp): ?string
{
    $value = trim($timestamp);
    if ($value === "") {
        return null;
    }

    $formats = [
        "Y-m-d H:i:s",
        "Y-m-d\\TH:i:sP",
        DateTime::RFC3339,
        DateTime::ATOM,
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value, new DateTimeZone("UTC"));
        if ($dt instanceof DateTime) {
            return $dt->format(DateTime::ATOM);
        }
    }

    $ts = strtotime($value . " UTC");
    if ($ts !== false) {
        $dt = new DateTime("@" . $ts);
        $dt->setTimezone(new DateTimeZone("UTC"));
        return $dt->format(DateTime::ATOM);
    }

    return null;
}

function renderLogSection(string $id, string $title, string $filepath, bool $withLevelFilter = true): void
{
    $sectionId = sanitizeId($id);
    $lines = tailFile($filepath, 2000);
    $entries = parseStructuredLogEntries($lines);
    $exists = is_file($filepath);
    $readable = $exists && is_readable($filepath);
    $size = $exists ? intval(@filesize($filepath)) : 0;

    echo '<section class="log-section">';
    echo '<div class="section-header">';
    echo '<h2>' . h($title) . '</h2>';
    echo '<button class="expand-button" type="button" data-source="' . h($sectionId) . '_container" data-modal="' . h($sectionId) . '_modal" title="Expand">';
    echo '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>';
    echo '</button>';
    echo '</div>';
    echo '<div class="search-container">';
    echo '<input class="search-input" type="text" placeholder="Search in ' . h($title) . '..." data-target="' . h($sectionId) . '_container">';
    echo '</div>';

    if ($withLevelFilter) {
        echo '<div class="log-filter-container" id="' . h($sectionId) . '_filters">';
        echo '<div class="filter-header">Filter by Level:</div>';
        echo '<div class="filter-controls">';
        echo '<button class="filter-btn filter-btn-sm" type="button" data-action="all" data-container="' . h($sectionId) . '">All</button>';
        echo '<button class="filter-btn filter-btn-sm" type="button" data-action="none" data-container="' . h($sectionId) . '">None</button>';
        echo '</div>';
        foreach (["error", "warn", "info", "debug", "trace"] as $level) {
            $checked = in_array($level, ["error", "warn"], true) ? "checked" : "";
            echo '<label class="filter-checkbox">';
            echo '<input type="checkbox" class="level-filter" data-container="' . h($sectionId) . '" data-level="' . h($level) . '" ' . $checked . '>';
            echo '<span class="filter-badge ' . h($level) . '-badge">' . strtoupper($level) . ' <span class="level-count" id="' . h($sectionId . '_' . $level . '_count') . '">0</span></span>';
            echo '</label>';
        }
        echo '</div>';
    }

    echo '<div class="log-container" id="' . h($sectionId) . '_container" data-level-filter="' . ($withLevelFilter ? "1" : "0") . '">';
    if (!$exists) {
        echo '<div class="info-message">Log file does not exist yet: <code>' . h($filepath) . '</code></div>';
    } elseif (!$readable) {
        echo '<div class="error-message">Log file is not readable: <code>' . h($filepath) . '</code></div>';
    } elseif (count($entries) === 0) {
        echo '<div class="info-message">Log is empty (' . h(strval($size)) . ' bytes).</div>';
    } else {
        foreach ($entries as $entry) {
            $level = trim(strval($entry["level"] ?? ""));
            $message = strval($entry["message"] ?? "");
            $timestamp = trim(strval($entry["timestamp"] ?? ""));
            $classes = "log-entry";
            $levelAttr = "";
            if ($level !== "") {
                $classes .= " " . h($level) . "-level";
                $levelAttr = ' data-level="' . h($level) . '"';
            }

            echo '<div class="' . $classes . '"' . $levelAttr . '>';
            if ($timestamp !== "") {
                $isoTimestamp = timestampToIso8601($timestamp);
                if ($isoTimestamp !== null) {
                    echo '<div class="timestamp" data-utc="' . h($isoTimestamp) . '" data-timezone-label="UTC">' . h($timestamp) . ' UTC</div>';
                } else {
                    echo '<div class="timestamp">' . h($timestamp) . '</div>';
                }
            }
            if ($level !== "") {
                echo '<div class="log-level">' . h(strtoupper($level)) . '</div>';
            }
            echo '<div class="log-message">' . h($message) . '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
    echo '</section>';

    echo '<div id="' . h($sectionId) . '_modal" class="log-modal">';
    echo '<div class="log-modal-content">';
    echo '<div class="log-modal-header">';
    echo '<h2 class="log-modal-title">' . h($title) . '</h2>';
    echo '<button class="close-modal" type="button" data-close-modal="' . h($sectionId) . '_modal">&times;</button>';
    echo '</div>';
    echo '<div class="modal-search-container">';
    echo '<input type="text" class="modal-search-input" placeholder="Search in ' . h($title) . '..." data-target="' . h($sectionId) . '_modal_content">';
    echo '</div>';
    echo '<div class="log-modal-body"><div id="' . h($sectionId) . '_modal_content"></div></div>';
    echo '</div>';
    echo '</div>';
}

function safeFetchAll(sql $db, string $query, array $params = []): array
{
    try {
        return $db->fetchAll($query, $params);
    } catch (Throwable $exception) {
        return [];
    }
}

function formatUtcFromTs(mixed $value): string
{
    $ts = intval($value ?? 0);
    if ($ts <= 0) {
        return "";
    }
    $dt = new DateTime("@" . $ts);
    $dt->setTimezone(new DateTimeZone("UTC"));
    return $dt->format("Y-m-d H:i:s");
}

function formatUtcIsoFromTs(mixed $value): string
{
    $ts = intval($value ?? 0);
    if ($ts <= 0) {
        return "";
    }
    $dt = new DateTime("@" . $ts);
    $dt->setTimezone(new DateTimeZone("UTC"));
    return $dt->format(DateTime::ATOM);
}

function truncateText(string $value, int $maxLen = 800): string
{
    $trimmed = trim($value);
    if (strlen($trimmed) <= $maxLen) {
        return $trimmed;
    }
    return substr($trimmed, 0, $maxLen) . "...";
}

$isEmbedded = (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");
$db = $GLOBALS["db"];
$title = "Stobe Server Logs";
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR;
$scriptPath = $_SERVER["SCRIPT_NAME"] ?? "";
$uiPos = strpos($scriptPath, "/ui/");
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : "";
if ($webRoot === "/") {
    $webRoot = "";
}
$webRoot = rtrim($webRoot, "/");
$herikaWebRoot = preg_replace('#/StobeServer$#i', '/HerikaServer', $webRoot) ?? $webRoot;
if ($herikaWebRoot === $webRoot || $herikaWebRoot === "") {
    $herikaWebRoot = "/HerikaServer";
}
$herikaMcpConfigApi = $herikaWebRoot . "/ui/api/chim_mcp_config.php";
$herikaLogsUrl = $herikaWebRoot . "/ui/tests/apache2err.php";

$mcpHost = 'localhost';
$mcpPort = 3100;
try {
    $row = $db->fetchOne(
        "SELECT value
         FROM conf_opts
         WHERE id = $1
         LIMIT 1",
        ['Network/WSL_IP']
    );
    $candidate = trim(strval($row['value'] ?? ''));
    if ($candidate !== '') {
        $mcpHost = $candidate;
    }
} catch (Throwable $exception) {
    // Keep localhost fallback.
}
$mcpBaseUrl = "http://" . $mcpHost . ":" . strval($mcpPort);

if (isset($_GET['mcp_status']) && strval($_GET['mcp_status']) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $probePort = intval($_GET['port'] ?? $mcpPort);
    if ($probePort < 1 || $probePort > 65535) {
        $probePort = $mcpPort;
    }
    $probeBaseUrl = "http://" . $mcpHost . ":" . strval($probePort);

    $result = [
        'ok' => false,
        'url' => $probeBaseUrl,
        'http_code' => 0,
        'latency_ms' => 0,
        'message' => 'MCP server unreachable',
    ];

    $start = microtime(true);
    $probeUrl = $probeBaseUrl . '/health';
    $ch = @curl_init($probeUrl);
    if ($ch) {
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $responseBody = @curl_exec($ch);
        $httpCode = intval(@curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = trim(strval(@curl_error($ch)));
        @curl_close($ch);

        $result['http_code'] = $httpCode;
        $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
        if ($httpCode >= 200 && $httpCode < 500) {
            $result['ok'] = true;
            $result['message'] = 'MCP server reachable';
            $decoded = json_decode(strval($responseBody), true);
            if (is_array($decoded)) {
                $result['health'] = $decoded;
            }
        } elseif ($curlError !== '') {
            $result['message'] = $curlError;
        } else {
            $result['message'] = 'HTTP ' . strval($httpCode) . ' from MCP health probe';
        }
    } else {
        $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
        $result['message'] = 'Failed to initialize MCP health probe';
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$auditRows = safeFetchAll(
    $db,
    "SELECT localts, npc_name, model, prompt_tokens, completion_tokens
     FROM audit_llm
     ORDER BY localts DESC, id DESC
     LIMIT 300"
);

$requestRows = safeFetchAll(
    $db,
    "SELECT rowid, localts, url, prompt, response
     FROM log
     ORDER BY localts DESC, rowid DESC
     LIMIT 200"
);

$auditRequestRows = safeFetchAll(
    $db,
    "SELECT id, localts, request_id, event_type, npc_name, connector, model, status, http_code, duration_ms, is_stream, prompt_tokens, completion_tokens, total_tokens, error, url, request, result
     FROM audit_request
     ORDER BY localts DESC, id DESC
     LIMIT 200"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: <?= $isEmbedded ? "20px" : "160px" ?>;
            padding-bottom: 40px;
            padding-left: 10px;
            padding-right: 10px;
            width: 100%;
            box-sizing: border-box;
            overflow-x: hidden;
        }

        body {
            background-color: #1e1e1e;
            color: #d4d4d4;
        }

        .log-dashboard {
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
        }

        .indent5 {
            width: 100%;
            box-sizing: border-box;
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            text-align: center;
            color: #e8e8e8;
        }

        .loading-spinner {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            border: 3px solid rgba(255, 255, 255, 0.15);
            border-top-color: #e6b76c;
            margin: 0 auto 10px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .log-dashboard h1 {
            margin: 0 0 8px 0;
            font-family: 'MagicCards', serif;
            word-spacing: 8px;
            font-size: 2em;
            font-weight: normal;
            letter-spacing: 0.5px;
        }

        .log-dashboard h2 {
            color: #e6b76c;
            font-family: 'Exo2', Arial, sans-serif;
            font-size: 1.2em;
        }

        .title-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .title-container h1 {
            color: #e6b76c;
            margin-right: 2px;
        }

        .title-helper {
            margin: 0 0 14px 0;
            color: #c3c7cf;
            font-size: 1.05em;
            line-height: 1.45;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
        }

        .title-helper a {
            color: #9bc6ff;
            text-decoration: none;
        }

        .title-helper a:hover {
            text-decoration: underline;
        }

        .logs-kagrenac-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
            align-items: start;
        }

        .logs-column {
            min-width: 0;
        }

        .refresh-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            color: #fff;
            border: 1px solid #3a3a3a;
            border-radius: 6px;
            padding: 8px 14px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
            text-decoration: none;
        }

        .refresh-button svg {
            width: 16px;
            height: 16px;
            flex: 0 0 auto;
        }

        .refresh-button:hover {
            border-color: rgba(230, 183, 108, 0.5);
            color: #e6b76c;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px rgba(255, 255, 255, 0.1);
        }

        .file-log-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .db-grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .kagrenac-panel {
            position: sticky;
            top: <?= $isEmbedded ? "20px" : "86px" ?>;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            min-height: 300px;
            height: calc(100vh - 110px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .kagrenac-header {
            padding: 12px 14px;
            border-bottom: 1px solid #3a3a3a;
            background: rgba(30, 30, 30, 0.85);
        }

        .kagrenac-header h2 {
            margin: 0 0 6px 0;
            color: #e6b76c;
            font-size: 1.05rem;
        }

        .kagrenac-subtitle {
            margin-top: 6px;
            color: #a8b0bc;
            font-size: 0.86rem;
        }

        .kagrenac-status {
            margin-top: 10px;
            border: 1px solid #3d3d3d;
            border-radius: 6px;
            background: #1b1b1b;
            padding: 10px;
        }

        .mcp-pill {
            display: inline-block;
            font-size: 0.74rem;
            border-radius: 999px;
            padding: 2px 9px;
            border: 1px solid #4a4a4a;
            background: #2a2a2a;
            color: #d8d8d8;
            font-weight: 600;
        }

        .mcp-pill.ok {
            color: #72e49a;
            border-color: rgba(68, 167, 101, 0.65);
            background: rgba(36, 89, 55, 0.45);
        }

        .mcp-pill.fail {
            color: #ff9c9c;
            border-color: rgba(186, 68, 68, 0.6);
            background: rgba(88, 31, 31, 0.45);
        }

        .mcp-meta {
            margin-top: 8px;
            color: #bfc5cf;
            font-size: 0.82rem;
            line-height: 1.4;
            word-break: break-word;
        }

        .kagrenac-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .kag-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #4a4a4a;
            border-radius: 6px;
            background: #232323;
            color: #dfdfdf;
            text-decoration: none;
            padding: 6px 10px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .kag-btn:hover {
            border-color: rgba(230, 183, 108, 0.5);
            color: #e6b76c;
            text-decoration: none;
        }

        .mcp-chat {
            margin-top: 10px;
            border-top: 1px solid #3a3a3a;
            display: flex;
            flex-direction: column;
            gap: 0;
            flex: 1;
            min-height: 0;
            background: rgba(34, 34, 34, 0.95);
        }

        .mcp-chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: radial-gradient(circle at top left, rgba(40, 40, 40, 0.35), rgba(20, 20, 20, 0.9));
        }

        .mcp-chat-row {
            display: flex;
        }

        .mcp-chat-row.user {
            justify-content: flex-end;
        }

        .mcp-chat-bubble {
            max-width: 90%;
            border-radius: 6px;
            border: 1px solid #464646;
            background: #262626;
            color: #e0e0e0;
            padding: 6px 8px;
            font-size: 0.82rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .mcp-chat-row.user .mcp-chat-bubble {
            border-color: rgba(230, 183, 108, 0.45);
            background: rgba(70, 44, 19, 0.65);
        }

        .mcp-chat-row.error .mcp-chat-bubble {
            border-color: rgba(220, 80, 80, 0.7);
            color: #ffb0b0;
            background: rgba(80, 23, 23, 0.65);
        }

        .mcp-chat-compose {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: end;
            border-top: 1px solid #3a3a3a;
            padding: 10px;
            background: rgba(34, 34, 34, 0.95);
        }

        .mcp-chat-input {
            resize: vertical;
            min-height: 58px;
            max-height: 150px;
            border: 1px solid #3a3a3a;
            border-radius: 6px;
            background: rgba(26, 26, 26, 0.8);
            color: #d4d4d4;
            padding: 8px 10px;
            font-size: 12px;
        }

        .log-section, .db-section {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 15px;
            min-height: 300px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .log-section {
            display: flex;
            flex-direction: column;
            min-width: 0;
            position: relative;
            min-height: 300px;
            min-width: 300px;
        }

        .log-section::after {
            content: none;
        }

        .log-section:hover, .db-section:hover {
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            gap: 10px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.2em;
            color: #e6b76c;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .expand-button {
            background: transparent;
            border: 1px solid #4a4a4a;
            color: #cfd3d8;
            border-radius: 4px;
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            flex: 0 0 auto;
        }

        .expand-button svg {
            width: 15px;
            height: 15px;
        }

        .expand-button:hover {
            border-color: rgba(230, 183, 108, 0.5);
            color: #e6b76c;
        }

        .search-container {
            margin: 10px 0;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-input {
            width: 100%;
            border-radius: 6px;
            border: 1px solid #4a4a4a;
            background: #202020;
            color: #e6e6e6;
            padding: 8px 10px;
            font-size: 0.9rem;
        }

        .log-filter-container {
            margin-bottom: 10px;
            border: 1px solid #3d3d3d;
            border-radius: 6px;
            background: #1f1f1f;
            padding: 8px;
        }

        .filter-header {
            font-size: 0.82rem;
            color: #b6bac2;
            margin-bottom: 6px;
        }

        .filter-controls {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }

        .filter-btn {
            border: 1px solid #505050;
            background: #2d2d2d;
            color: #e3e3e3;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 0.78rem;
        }

        .filter-checkbox {
            display: inline-flex;
            align-items: center;
            margin-right: 8px;
            margin-bottom: 4px;
            gap: 4px;
        }

        .filter-badge {
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 10px;
            background: #303030;
            border: 1px solid #474747;
            color: #ddd;
        }

        .error-badge { color: #ff8f8f; }
        .warn-badge { color: #ffd27f; }
        .info-badge { color: #9bc6ff; }
        .debug-badge { color: #a6d4a1; }
        .trace-badge { color: #b5b5b5; }

        .log-container {
            height: 600px;
            max-height: 600px;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid #444;
            border-radius: 6px;
            background: rgba(26, 26, 26, 0.8);
            padding: 10px;
            width: 100%;
            box-sizing: border-box;
            text-align: left;
        }

        .log-entry {
            background-color: #252526;
            border-left: none;
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 4px;
            font-family: monospace;
            text-align: left;
            font-size: 13px;
            color: #d7d7d7;
        }

        .log-entry:last-child {
            margin-bottom: 0;
        }

        .timestamp {
            color: #888;
            white-space: nowrap;
        }

        .log-level {
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.85em;
            min-width: 50px;
            text-align: center;
        }

        .log-message {
            word-break: break-word;
            white-space: pre-wrap;
            flex: 1;
        }

        .error-level { border-left: 4px solid #dc3545; }
        .error-level .log-level { background-color: #dc3545; color: white; }
        .warn-level { border-left: 4px solid #ffc107; }
        .warn-level .log-level { background-color: #ffc107; color: black; }
        .info-level { border-left: 4px solid #17a2b8; }
        .info-level .log-level { background-color: #17a2b8; color: white; }
        .debug-level { border-left: 4px solid #6c757d; }
        .debug-level .log-level { background-color: #6c757d; color: white; }
        .trace-level { border-left: 4px solid #28a745; }
        .trace-level .log-level { background-color: #28a745; color: white; }

        .info-message, .error-message {
            padding: 12px;
            font-size: 0.85rem;
        }

        .info-message { color: #b9c0ca; }
        .error-message { color: #ff9b9b; }

        .db-section h2 {
            margin: 0 0 10px 0;
            font-size: 1.05rem;
            color: #e6b76c;
        }

        .db-table-wrap {
            max-height: 420px;
            overflow: auto;
            border: 1px solid #444;
            border-radius: 6px;
        }

        .db-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
            background: #171717;
        }

        .db-table th, .db-table td {
            border-bottom: 1px solid #2d2d2d;
            padding: 8px 10px;
            vertical-align: top;
            color: #d7d7d7;
        }

        .db-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #1f1f1f;
            color: #f2c14a;
            text-align: left;
        }

        details summary {
            cursor: pointer;
            color: #9bc6ff;
        }

        .not-enabled {
            margin-top: 12px;
            font-size: 0.82rem;
            color: #9aa0aa;
            background: #1d1d1d;
            border: 1px dashed #3a3a3a;
            border-radius: 6px;
            padding: 10px;
            grid-column: 1 / -1;
        }

        .log-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            overflow-y: auto;
            padding-top: <?= $isEmbedded ? "20px" : "160px" ?>;
            padding-bottom: 40px;
        }

        .log-modal-content {
            position: relative;
            background-color: #252526;
            margin: 0 auto;
            padding: 20px;
            width: 95%;
            max-width: 1600px;
            border-radius: 8px;
            border: 1px solid #444;
            max-height: calc(100vh - 200px);
            display: flex;
            flex-direction: column;
        }

        .log-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #444;
        }

        .log-modal-title {
            margin: 0;
            font-size: 1.5em;
            color: #ffffff;
        }

        .close-modal {
            background: transparent;
            border: none;
            color: #f8f9fa;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }

        .close-modal:hover {
            background-color: #444;
        }

        .modal-search-container {
            margin: 0 0 15px 0;
            padding: 10px;
            background-color: #1e1e1e;
            border-radius: 4px;
            border: 1px solid #555555;
        }

        .modal-search-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #444;
            border-radius: 4px;
            background-color: #1e1e1e;
            color: #d4d4d4;
            font-family: monospace;
            font-size: 14px;
        }

        .log-modal-body {
            background-color: #1e1e1e;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #555555;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        .log-modal-body .log-container {
            height: auto;
            max-height: none;
            min-height: 0;
            border: none;
            padding: 0;
        }

        @media (max-width: 1280px) {
            .logs-kagrenac-layout {
                grid-template-columns: 1fr;
            }
            .kagrenac-panel {
                position: static;
                height: auto;
            }
            .file-log-grid, .db-grid {
                grid-template-columns: 1fr;
            }
            .mcp-chat-history {
                min-height: 180px;
            }
        }

        @media (max-width: 768px) {
            .title-container {
                align-items: flex-start;
            }
            .refresh-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php if (!$isEmbedded): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="log-dashboard">
    <div class="indent5">
    <div class="loading-overlay active" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <p class="loading-text">Loading logs...</p>
        </div>
    </div>

    <div class="title-container">
        <h1>&#127794; Server Logs</h1>
        <button class="refresh-button" type="button" id="refreshLogs" title="Reload this logs page">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3a5 5 0 0 0-5 5H1l3.5 3.5L8 8H6a2 2 0 1 1 2 2v2a4 4 0 1 0-4-4H2a6 6 0 1 1 6 6v-2a4 4 0 0 0 0-8z"/></svg>
            <span>Refresh Logs</span>
        </button>
        <a class="refresh-button" href="<?= h(($webRoot !== "" ? $webRoot : "") . "/log/") ?>" target="_blank" rel="noopener" title="Open StobeServer /log directory">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg>
            <span>Open /log Folder</span>
        </a>
        <button class="refresh-button" type="button" id="downloadLogs" title="Download visible log entries">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg>
            <span>Download Logs</span>
        </button>
        <button class="refresh-button" type="button" id="timezoneToggle" title="Toggle UTC/local browser time">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
            <span>Timezone: UTC</span>
        </button>
    </div>
    <div class="title-helper">Last 2000 lines per log file. File logs are shown in two-column card boxes; DB logs are below in matching cards. Full files are under <a href="<?= h(($webRoot !== "" ? $webRoot : "") . "/log/") ?>" target="_blank" rel="noopener">/log</a>.</div>

    <div class="logs-kagrenac-layout">
    <div class="logs-column">
    <div class="file-log-grid">
        <?php renderLogSection("stobeserver", "Stobe Server (stobeserver.log)", $logDir . "stobeserver.log", true); ?>
        <?php renderLogSection("stobe_import", "Stobe Import (stobe_import.log)", $logDir . "stobe_import.log", true); ?>
        <?php renderLogSection("php_error", "PHP Errors (php_error.log)", $logDir . "php_error.log", false); ?>
        <?php renderLogSection("llm_output", "LLM Output (output_from_llm.log)", $logDir . "output_from_llm.log", false); ?>
        <?php renderLogSection("llm_context", "LLM Context (context_sent_to_llm.log)", $logDir . "context_sent_to_llm.log", false); ?>
        <?php renderLogSection("llm_context_fast", "LLM Context Fast (context_sent_to_llm_fast.log)", $logDir . "context_sent_to_llm_fast.log", false); ?>
        <?php renderLogSection("plugin_output", "Output To Plugin (output_to_plugin.log)", $logDir . "output_to_plugin.log", true); ?>
        <?php renderLogSection("stt_input", "STT Input (stt.log)", $logDir . "stt.log", true); ?>
        <?php renderLogSection("audit_request_file", "Audit Request File (audit_request.log)", $logDir . "audit_request.log", true); ?>
        <?php if (is_file($logDir . "relationship_worker.log")): ?>
            <?php renderLogSection("rel_worker", "Relationship Worker (relationship_worker.log)", $logDir . "relationship_worker.log", false); ?>
        <?php endif; ?>

    </div>

    <div class="db-grid">
        <section class="db-section">
            <h2>LLM Usage Audit (audit_llm)</h2>
            <div class="search-container">
                <input class="search-input" type="text" placeholder="Search audit rows..." data-target="audit_llm_table_body">
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                    <tr>
                        <th>Time (UTC)</th>
                        <th>NPC</th>
                        <th>Model</th>
                        <th>Prompt</th>
                        <th>Completion</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody id="audit_llm_table_body">
                    <?php if (count($auditRows) === 0): ?>
                        <tr><td colspan="6" class="info-message">No rows yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditRows as $row): ?>
                            <?php
                                $p = intval($row["prompt_tokens"] ?? 0);
                                $c = intval($row["completion_tokens"] ?? 0);
                                $iso = formatUtcIsoFromTs($row["localts"] ?? 0);
                                $txt = formatUtcFromTs($row["localts"] ?? 0);
                            ?>
                            <tr>
                                <td><?php if ($iso !== ""): ?><span class="timestamp" data-utc="<?= h($iso) ?>" data-timezone-label="UTC"><?= h($txt) ?> UTC</span><?php else: ?><?= h($txt) ?><?php endif; ?></td>
                                <td><?= h($row["npc_name"] ?? "") ?></td>
                                <td><?= h($row["model"] ?? "") ?></td>
                                <td><?= h(strval($p)) ?></td>
                                <td><?= h(strval($c)) ?></td>
                                <td><?= h(strval($p + $c)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="db-section">
            <h2>LLM Prompt/Response Log (log)</h2>
            <div class="search-container">
                <input class="search-input" type="text" placeholder="Search prompt/response rows..." data-target="request_log_table_body">
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                    <tr>
                        <th>Row</th>
                        <th>Time (UTC)</th>
                        <th>URL / Meta</th>
                        <th>Prompt</th>
                        <th>Response</th>
                    </tr>
                    </thead>
                    <tbody id="request_log_table_body">
                    <?php if (count($requestRows) === 0): ?>
                        <tr><td colspan="5" class="info-message">No rows yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requestRows as $row): ?>
                            <?php
                                $prompt = strval($row["prompt"] ?? "");
                                $response = strval($row["response"] ?? "");
                                $iso = formatUtcIsoFromTs($row["localts"] ?? 0);
                                $txt = formatUtcFromTs($row["localts"] ?? 0);
                            ?>
                            <tr>
                                <td><?= h(strval($row["rowid"] ?? "")) ?></td>
                                <td><?php if ($iso !== ""): ?><span class="timestamp" data-utc="<?= h($iso) ?>" data-timezone-label="UTC"><?= h($txt) ?> UTC</span><?php else: ?><?= h($txt) ?><?php endif; ?></td>
                                <td><?= h(strval($row["url"] ?? "")) ?></td>
                                <td>
                                    <details>
                                        <summary>Show prompt</summary>
                                        <div class="log-message"><?= h(truncateText($prompt, 8000)) ?></div>
                                    </details>
                                </td>
                                <td>
                                    <details>
                                        <summary>Show response</summary>
                                        <div class="log-message"><?= h(truncateText($response, 8000)) ?></div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="db-section">
            <h2>Request Audit (audit_request)</h2>
            <div class="search-container">
                <input class="search-input" type="text" placeholder="Search request audit rows..." data-target="audit_request_table_body">
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Time (UTC)</th>
                        <th>Request ID</th>
                        <th>Event</th>
                        <th>NPC</th>
                        <th>Status</th>
                        <th>HTTP</th>
                        <th>Dur (ms)</th>
                        <th>Tokens</th>
                        <th>Error</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody id="audit_request_table_body">
                    <?php if (count($auditRequestRows) === 0): ?>
                        <tr><td colspan="11" class="info-message">No rows yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditRequestRows as $row): ?>
                            <?php
                                $iso = formatUtcIsoFromTs($row["localts"] ?? 0);
                                $txt = formatUtcFromTs($row["localts"] ?? 0);
                                $tokenText = strval(intval($row["prompt_tokens"] ?? 0)) . "/" . strval(intval($row["completion_tokens"] ?? 0)) . "/" . strval(intval($row["total_tokens"] ?? 0));
                            ?>
                            <tr>
                                <td><?= h(strval($row["id"] ?? "")) ?></td>
                                <td><?php if ($iso !== ""): ?><span class="timestamp" data-utc="<?= h($iso) ?>" data-timezone-label="UTC"><?= h($txt) ?> UTC</span><?php else: ?><?= h($txt) ?><?php endif; ?></td>
                                <td><?= h(strval($row["request_id"] ?? "")) ?></td>
                                <td><?= h(strval($row["event_type"] ?? "")) ?></td>
                                <td><?= h(strval($row["npc_name"] ?? "")) ?></td>
                                <td><?= h(strtoupper(strval($row["status"] ?? ""))) ?></td>
                                <td><?= h(strval($row["http_code"] ?? "")) ?></td>
                                <td><?= h(strval($row["duration_ms"] ?? "")) ?></td>
                                <td><?= h($tokenText) ?></td>
                                <td><?= h(truncateText(strval($row["error"] ?? ""), 200)) ?></td>
                                <td>
                                    <details>
                                        <summary>Show</summary>
                                        <div class="log-message"><strong>Connector:</strong> <?= h(strval($row["connector"] ?? "")) ?></div>
                                        <div class="log-message"><strong>Model:</strong> <?= h(strval($row["model"] ?? "")) ?></div>
                                        <div class="log-message"><strong>URL:</strong> <?= h(strval($row["url"] ?? "")) ?></div>
                                        <div class="log-message"><strong>Stream:</strong> <?= h(strval($row["is_stream"] ?? "")) ?></div>
                                        <div class="log-message"><strong>Request:</strong> <?= h(truncateText(strval($row["request"] ?? ""), 6000)) ?></div>
                                        <div class="log-message"><strong>Result:</strong> <?= h(truncateText(strval($row["result"] ?? ""), 6000)) ?></div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    </div>
    <aside class="kagrenac-panel" id="mcpPanel">
        <div class="kagrenac-header">
            <h2>MCP Connection</h2>
            <div class="kagrenac-subtitle">Herika-style MCP reachability check for log analysis tooling.</div>
        </div>
        <div class="kagrenac-status">
            <span class="mcp-pill" id="mcpStatusPill">Checking...</span>
            <div class="mcp-meta" id="mcpStatusMeta">
                Host: <?= h($mcpHost) ?><br>
                Status pending...
            </div>
            <div class="kagrenac-actions">
                <a class="kag-btn" id="mcpConfigLink" href="<?= h($herikaLogsUrl) ?>" target="_blank" rel="noopener">Configure in Herika Logs</a>
            </div>
        </div>
        <div class="mcp-chat">
            <div class="mcp-chat-history" id="mcpChatHistory"></div>
            <div class="mcp-chat-compose">
                <textarea class="mcp-chat-input" id="mcpChatInput" placeholder="Ask MCP..."></textarea>
                <button class="kag-btn" type="button" id="mcpSendBtn">Send</button>
            </div>
        </div>
    </aside>
    </div>
    </div>
</main>

<script>
(function() {
    let useLocalTime = false;
    const loadingOverlay = document.getElementById('loadingOverlay');
    const mcpStatusApiBase = '<?= h(($webRoot !== "" ? $webRoot : "") . "/ui/logs.php") ?>';
    const mcpHost = '<?= h($mcpHost) ?>';
    const defaultMcpPort = <?= intval($mcpPort) ?>;
    const herikaMcpConfigApi = '<?= h($herikaMcpConfigApi) ?>';
    const mcpStatusPill = document.getElementById('mcpStatusPill');
    const mcpStatusMeta = document.getElementById('mcpStatusMeta');
    const mcpChatHistory = document.getElementById('mcpChatHistory');
    const mcpChatInput = document.getElementById('mcpChatInput');
    const mcpSendBtn = document.getElementById('mcpSendBtn');

    let mcpResolvedPort = defaultMcpPort;
    let mcpRequestInFlight = false;

    function applySearchToContainer(container, query) {
        const q = query.trim().toLowerCase();
        const rows = container.querySelectorAll('.log-entry, tr');
        rows.forEach((row) => {
            if (!q) {
                row.style.display = '';
                return;
            }
            const text = (row.textContent || '').toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    }

    function currentMcpBaseUrl() {
        return 'http://' + mcpHost + ':' + String(mcpResolvedPort);
    }

    function appendMcpMessage(kind, message) {
        if (!mcpChatHistory) {
            return;
        }
        const row = document.createElement('div');
        row.className = 'mcp-chat-row ' + kind;
        const bubble = document.createElement('div');
        bubble.className = 'mcp-chat-bubble';
        bubble.textContent = String(message || '');
        row.appendChild(bubble);
        mcpChatHistory.appendChild(row);
        mcpChatHistory.scrollTop = mcpChatHistory.scrollHeight;
    }

    async function loadHerikaMcpSettings() {
        try {
            const response = await fetch(herikaMcpConfigApi, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();
            if (!payload || !payload.success || !payload.data) {
                throw new Error('Herika MCP config unavailable');
            }
            const cfg = payload.data || {};
            const parsedPort = parseInt(String(cfg.port || defaultMcpPort), 10);
            if (!Number.isNaN(parsedPort) && parsedPort > 0 && parsedPort <= 65535) {
                mcpResolvedPort = parsedPort;
            }
        } catch (error) {
            // Keep defaults on failure.
        }
    }

    async function sendMcpMessage() {
        if (!mcpChatInput || !mcpSendBtn || mcpRequestInFlight) {
            return;
        }
        const text = (mcpChatInput.value || '').trim();
        if (!text) {
            return;
        }
        appendMcpMessage('user', text);
        mcpChatInput.value = '';
        mcpRequestInFlight = true;
        mcpSendBtn.disabled = true;
        mcpSendBtn.textContent = 'Sending...';
        try {
            const response = await fetch(currentMcpBaseUrl() + '/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            if (!response.ok) {
                const raw = await response.text();
                throw new Error(raw || ('HTTP ' + String(response.status)));
            }
            const payload = await response.json();
            const answer = (payload && payload.response) ? String(payload.response) : JSON.stringify(payload || {});
            appendMcpMessage('assistant', answer || '(empty response)');
        } catch (error) {
            appendMcpMessage('error', 'MCP request failed: ' + String(error && error.message ? error.message : error));
        } finally {
            mcpRequestInFlight = false;
            mcpSendBtn.disabled = false;
            mcpSendBtn.textContent = 'Send';
        }
    }

    async function checkMcpConnection() {
        if (!mcpStatusPill || !mcpStatusMeta) {
            return;
        }

        mcpStatusPill.textContent = 'Checking...';
        mcpStatusPill.classList.remove('ok', 'fail');

        try {
            const statusUrl = mcpStatusApiBase + '?mcp_status=1&port=' + encodeURIComponent(String(mcpResolvedPort));
            const response = await fetch(statusUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();
            const ok = !!(payload && payload.ok);

            if (ok) {
                mcpStatusPill.textContent = 'Connected';
                mcpStatusPill.classList.add('ok');
            } else {
                mcpStatusPill.textContent = 'Disconnected';
                mcpStatusPill.classList.add('fail');
            }

            mcpStatusMeta.innerHTML =
                'Host: ' + mcpHost + '<br>' +
                'Status: ' + (ok ? 'Connected' : 'Disconnected');
            if (payload && payload.health && typeof payload.health === 'object') {
                const healthStr = JSON.stringify(payload.health);
                if (healthStr && healthStr !== '{}') {
                    mcpStatusMeta.innerHTML += '<br>Health: ' + healthStr;
                }
            }
        } catch (error) {
            mcpStatusPill.textContent = 'Disconnected';
            mcpStatusPill.classList.add('fail');
            mcpStatusMeta.innerHTML = 'Host: ' + mcpHost + '<br>Status: Disconnected';
        }
    }

    function formatUtc(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return date.getUTCFullYear() + '-' +
            pad(date.getUTCMonth() + 1) + '-' +
            pad(date.getUTCDate()) + ' ' +
            pad(date.getUTCHours()) + ':' +
            pad(date.getUTCMinutes()) + ':' +
            pad(date.getUTCSeconds()) + ' UTC';
    }

    function formatLocal(date) {
        return date.toLocaleString() + ' Local';
    }

    function convertTimestampElements(scope) {
        const root = scope || document;
        root.querySelectorAll('.timestamp[data-utc]').forEach((el) => {
            const iso = el.getAttribute('data-utc');
            if (!iso) return;
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) return;
            el.textContent = useLocalTime ? formatLocal(date) : formatUtc(date);
        });
    }

    function updateLevelCounts(containerId) {
        const container = document.getElementById(containerId + '_container');
        if (!container) return;
        ['error', 'warn', 'info', 'debug', 'trace'].forEach((lvl) => {
            const countEl = document.getElementById(containerId + '_' + lvl + '_count');
            if (!countEl) return;
            const count = container.querySelectorAll('.log-entry[data-level="' + lvl + '"]').length;
            countEl.textContent = String(count);
        });
    }

    function applyLevelFilters(containerId) {
        const container = document.getElementById(containerId + '_container');
        if (!container) return;
        const enabled = {};
        document.querySelectorAll('.level-filter[data-container="' + containerId + '"]').forEach((cb) => {
            enabled[cb.dataset.level] = cb.checked;
        });

        container.querySelectorAll('.log-entry').forEach((entry) => {
            const lvl = entry.dataset.level || '';
            if (!lvl) return;
            entry.style.display = enabled[lvl] ? '' : 'none';
        });
    }

    document.querySelectorAll('.search-input, .modal-search-input').forEach((input) => {
        input.addEventListener('input', () => {
            const targetId = input.dataset.target || '';
            const target = document.getElementById(targetId);
            if (!target) return;
            applySearchToContainer(target, input.value || '');
        });
    });

    document.querySelectorAll('.level-filter').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const containerId = checkbox.dataset.container || '';
            if (!containerId) return;
            applyLevelFilters(containerId);
        });
    });

    document.querySelectorAll('.filter-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const containerId = button.dataset.container || '';
            const action = button.dataset.action || '';
            if (!containerId) return;
            document.querySelectorAll('.level-filter[data-container="' + containerId + '"]').forEach((cb) => {
                cb.checked = (action === 'all');
            });
            applyLevelFilters(containerId);
        });
    });

    document.querySelectorAll('.log-container[data-level-filter="1"]').forEach((container) => {
        const containerId = container.id.replace('_container', '');
        updateLevelCounts(containerId);
        applyLevelFilters(containerId);
    });

    const refreshBtn = document.getElementById('refreshLogs');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            window.location.reload();
        });
    }

    const downloadBtn = document.getElementById('downloadLogs');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', () => {
            const chunks = [];
            document.querySelectorAll('.log-section').forEach((section) => {
                const titleNode = section.querySelector('.section-header h2');
                const title = titleNode ? titleNode.textContent.trim() : 'Log';
                chunks.push('==== ' + title + ' ====');
                section.querySelectorAll('.log-entry').forEach((row) => {
                    const line = (row.textContent || '').replace(/\s+/g, ' ').trim();
                    if (line) {
                        chunks.push(line);
                    }
                });
                chunks.push('');
            });
            const content = chunks.join('\n');
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const now = new Date();
            const stamp = [
                now.getUTCFullYear(),
                String(now.getUTCMonth() + 1).padStart(2, '0'),
                String(now.getUTCDate()).padStart(2, '0'),
                String(now.getUTCHours()).padStart(2, '0'),
                String(now.getUTCMinutes()).padStart(2, '0'),
                String(now.getUTCSeconds()).padStart(2, '0')
            ].join('');
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'stobe_logs_' + stamp + '.txt';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        });
    }

    const timezoneBtn = document.getElementById('timezoneToggle');
    if (timezoneBtn) {
        timezoneBtn.addEventListener('click', () => {
            useLocalTime = !useLocalTime;
            const text = useLocalTime ? 'Timezone: Local' : 'Timezone: UTC';
            const span = timezoneBtn.querySelector('span');
            if (span) {
                span.textContent = text;
            } else {
                timezoneBtn.textContent = text;
            }
            convertTimestampElements(document);
        });
    }

    if (mcpSendBtn) {
        mcpSendBtn.addEventListener('click', () => {
            sendMcpMessage();
        });
    }

    if (mcpChatInput) {
        mcpChatInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMcpMessage();
            }
        });
    }

    document.querySelectorAll('.expand-button').forEach((button) => {
        button.addEventListener('click', () => {
            const sourceId = button.getAttribute('data-source') || '';
            const modalId = button.getAttribute('data-modal') || '';
            if (!sourceId || !modalId) return;

            const source = document.getElementById(sourceId);
            const modal = document.getElementById(modalId);
            const modalContent = document.getElementById(modalId + '_content');
            if (!source || !modal || !modalContent) return;

            modalContent.innerHTML = '<div class="log-container">' + source.innerHTML + '</div>';
            modal.style.display = 'block';
            convertTimestampElements(modal);
        });
    });

    document.querySelectorAll('.close-modal').forEach((button) => {
        button.addEventListener('click', () => {
            const modalId = button.getAttribute('data-close-modal');
            if (!modalId) return;
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });

    window.addEventListener('click', (event) => {
        if (event.target && event.target.classList && event.target.classList.contains('log-modal')) {
            event.target.style.display = 'none';
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.log-modal').forEach((modal) => {
            modal.style.display = 'none';
        });
    });

    convertTimestampElements(document);
    appendMcpMessage('assistant', 'MCP chat ready. Configure MCP routing in HerikaServer Logs.');
    (async () => {
        await loadHerikaMcpSettings();
        await checkMcpConnection();
    })();
    if (loadingOverlay) {
        window.setTimeout(() => {
            loadingOverlay.classList.remove('active');
        }, 180);
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



