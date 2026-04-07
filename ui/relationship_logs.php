<?php
/**
 * StobeServer Relationship Logs.
 * Rewired for Stobe audit_request schema + embed usage in control panel.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function safeFetchAll(sql $db, string $query, array $params = []): array
{
    try {
        return $db->fetchAll($query, $params);
    } catch (Throwable $exception) {
        return [];
    }
}

function safeFetchOne(sql $db, string $query, array $params = []): array|false
{
    try {
        return $db->fetchOne($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function safeExec(sql $db, string $query, array $params = []): bool
{
    try {
        return (bool)$db->exec($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function formatLocalTs(mixed $value): string
{
    $ts = intval($value ?? 0);
    if ($ts <= 0) {
        return "";
    }
    $dt = new DateTime("@" . $ts);
    $dt->setTimezone(new DateTimeZone("UTC"));
    return $dt->format("d-m-Y H:i:s");
}

function decodeJsonLoose(string $raw): array|null
{
    $value = trim($raw);
    if ($value === "") {
        return null;
    }

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $value, $matches) === 1) {
        $value = trim($matches[1]);
    }
    $parsed = json_decode($value, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    if (preg_match('/\{[\s\S]*\}/', $value, $matches) === 1) {
        $parsed = json_decode($matches[0], true);
        if (is_array($parsed)) {
            return $parsed;
        }
    }
    return null;
}

function relationshipLogBaseWhere(): string
{
    return "(connector ILIKE '%RelationshipLLM%' OR url LIKE 'ext/relationship_system/%' OR event_type LIKE 'relationship_%')";
}

function relationshipTypeFilter(string $type): array
{
    $type = strtolower(trim($type));
    if (!in_array($type, ["eval", "analyze", "npc2npc"], true)) {
        return ["", []];
    }
    if ($type === "eval") {
        return [
            " AND (LOWER(COALESCE(event_type, '')) LIKE 'relationship_eval%' OR request LIKE $1)",
            ['%"type":"eval_%']
        ];
    }
    if ($type === "analyze") {
        return [
            " AND (LOWER(COALESCE(event_type, '')) LIKE 'relationship_analyze%' OR request LIKE $1)",
            ['%"type":"analyze_%']
        ];
    }
    return [
        " AND (LOWER(COALESCE(event_type, '')) LIKE 'relationship_npc2npc%' OR request LIKE $1)",
        ['%"type":"npc2npc_%']
    ];
}

function relationshipTypeLabel(string $rawType): array
{
    $rawType = trim($rawType);
    $rawTypeLower = strtolower($rawType);
    if ($rawTypeLower === "relationship_eval" || str_starts_with($rawTypeLower, "relationship_eval")) {
        return ["eval", "rel-type-eval", ""];
    }
    if ($rawTypeLower === "relationship_analyze" || str_starts_with($rawTypeLower, "relationship_analyze")) {
        return ["analyze", "rel-type-analyze", ""];
    }
    if ($rawTypeLower === "relationship_npc2npc" || str_starts_with($rawTypeLower, "relationship_npc2npc")) {
        return ["npc2npc", "rel-type-npc2npc", ""];
    }
    if (str_starts_with($rawType, "eval_")) {
        return ["eval", "rel-type-eval", trim(substr($rawType, 5))];
    }
    if (str_starts_with($rawType, "analyze_")) {
        return ["analyze", "rel-type-analyze", trim(substr($rawType, 8))];
    }
    if (str_starts_with($rawType, "npc2npc_")) {
        $rest = trim(substr($rawType, 8));
        $pieces = explode("_", $rest, 2);
        $speaker = trim($pieces[0] ?? "");
        $listener = trim($pieces[1] ?? "");
        $display = trim($speaker . ($listener !== "" ? " -> " . $listener : ""));
        return ["npc2npc", "rel-type-npc2npc", $display];
    }
    return [$rawType === "" ? "unknown" : $rawType, "rel-type-eval", ""];
}

function extractContextText(array $requestData): string
{
    $messages = $requestData["messages"] ?? null;
    if (!is_array($messages)) {
        return "";
    }
    $parts = [];
    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = trim(strval($msg["role"] ?? ""));
        $content = trim(strval($msg["content"] ?? ""));
        if ($content === "") {
            continue;
        }
        $parts[] = ($role !== "" ? strtoupper($role) . ": " : "") . $content;
    }
    return implode("\n\n", $parts);
}

function extractRelationshipTagFromMessages(array $requestData, string $tagName): string
{
    if ($tagName === "") {
        return "";
    }

    $messages = $requestData["messages"] ?? null;
    if (!is_array($messages) && isset($requestData["full"]) && is_array($requestData["full"])) {
        $messages = $requestData["full"]["messages"] ?? null;
    }
    if (!is_array($messages)) {
        return "";
    }

    $pattern = '/<' . preg_quote($tagName, '/') . '>\s*([^<]+?)\s*<\/' . preg_quote($tagName, '/') . '>/i';
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $content = strval($message["content"] ?? "");
        if ($content === "") {
            continue;
        }
        if (preg_match($pattern, $content, $matches) === 1) {
            return trim(strval($matches[1] ?? ""));
        }
    }

    return "";
}

function extractChanges(string $resultRaw, string $speakerName = "", string $listenerName = ""): array
{
    $parsed = decodeJsonLoose($resultRaw);
    if (!is_array($parsed)) {
        return [];
    }

    $candidates = [$parsed];
    $nestedTextKeys = ["response_text", "output_text", "content", "result", "response"];
    foreach ($nestedTextKeys as $nestedKey) {
        if (!isset($parsed[$nestedKey]) || !is_string($parsed[$nestedKey])) {
            continue;
        }
        $nested = decodeJsonLoose($parsed[$nestedKey]);
        if (is_array($nested)) {
            $candidates[] = $nested;
        }
    }
    if (isset($parsed["data"]) && is_array($parsed["data"])) {
        $candidates[] = $parsed["data"];
    }

    $changes = [];
    $appendUpdate = static function (array $entry) use (&$changes): void {
        $target = trim(strval($entry["target"] ?? ($entry["name"] ?? ($entry["listener"] ?? ($entry["npc"] ?? "")))));
        if ($target === "") {
            return;
        }

        $delta = 0;
        if (isset($entry["delta"])) {
            $delta = intval($entry["delta"]);
        } elseif (isset($entry["aff_delta"])) {
            $delta = intval($entry["aff_delta"]);
        } elseif (isset($entry["change"])) {
            $delta = intval($entry["change"]);
        } elseif (isset($entry["new_aff"]) && isset($entry["old_aff"])) {
            $delta = intval($entry["new_aff"]) - intval($entry["old_aff"]);
        }

        $reason = trim(strval($entry["reason"] ?? ($entry["note"] ?? ($entry["summary"] ?? ""))));
        $changes[$target] = [
            "delta" => $delta,
            "reason" => $reason,
        ];
    };

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        if (isset($candidate["changes"]) && is_array($candidate["changes"])) {
            foreach ($candidate["changes"] as $target => $changePayload) {
                if (is_array($changePayload)) {
                    if (!isset($changePayload["target"]) && is_string($target)) {
                        $changePayload["target"] = $target;
                    }
                    $appendUpdate($changePayload);
                }
            }
        }

        $rawUpdates = [];
        if (isset($candidate["updates"]) && is_array($candidate["updates"])) {
            $rawUpdates = $candidate["updates"];
        } elseif (isset($candidate["relationships"]) && is_array($candidate["relationships"])) {
            $rawUpdates = $candidate["relationships"];
        } elseif (array_keys($candidate) === range(0, count($candidate) - 1)) {
            $rawUpdates = $candidate;
        }
        foreach ($rawUpdates as $update) {
            if (is_array($update)) {
                $appendUpdate($update);
            }
        }

        if (isset($candidate["speaker"]) && is_array($candidate["speaker"]) && isset($candidate["speaker"]["delta"])) {
            $name = $speakerName !== "" ? $speakerName : "speaker";
            $speakerPayload = $candidate["speaker"];
            if (!isset($speakerPayload["target"])) {
                $speakerPayload["target"] = $name;
            }
            $appendUpdate($speakerPayload);
        }
        if (isset($candidate["listener"]) && is_array($candidate["listener"]) && isset($candidate["listener"]["delta"])) {
            $name = $listenerName !== "" ? $listenerName : "listener";
            $listenerPayload = $candidate["listener"];
            if (!isset($listenerPayload["target"])) {
                $listenerPayload["target"] = $name;
            }
            $appendUpdate($listenerPayload);
        }
    }
    if (count($changes) > 0) {
        return $changes;
    }

    return $changes;
}

function relationshipLogsUrl(int $page, int $limit, string $type, bool $embedded): string
{
    $url = "relationship_logs.php?page=" . max(1, $page) . "&limit=" . max(10, $limit);
    if ($type !== "") {
        $url .= "&type=" . urlencode($type);
    }
    if ($embedded) {
        $url .= "&embed=1";
    }
    return $url;
}

$db = $GLOBALS["db"];
$isEmbedded = (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 50;
$limit = max(10, min(250, $limit));
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;
$typeFilter = strtolower(trim(strval($_GET["type"] ?? "")));
if (!in_array($typeFilter, ["", "eval", "analyze", "npc2npc"], true)) {
    $typeFilter = "";
}

$deleteMessage = "";
if (isset($_POST["delete_action"])) {
    $action = trim(strval($_POST["delete_action"] ?? ""));
    if ($action === "delete_all") {
        safeExec($db, "DELETE FROM audit_request WHERE " . relationshipLogBaseWhere());
        $deleteMessage = "All relationship logs deleted.";
    } elseif ($action === "delete_older") {
        $interval = trim(strval($_POST["delete_interval"] ?? "1 week"));
        $allowed = [
            "1 hour",
            "6 hours",
            "1 day",
            "3 days",
            "1 week",
            "2 weeks",
            "1 month",
        ];
        if (in_array($interval, $allowed, true)) {
            safeExec(
                $db,
                "DELETE FROM audit_request
                 WHERE " . relationshipLogBaseWhere() . "
                 AND localts < EXTRACT(EPOCH FROM (NOW() - INTERVAL '" . $interval . "'))"
            );
            $deleteMessage = "Logs older than " . $interval . " deleted.";
        }
    }
}

[$typeClause, $typeParams] = relationshipTypeFilter($typeFilter);
$baseWhere = relationshipLogBaseWhere();
$whereSql = "WHERE " . $baseWhere . $typeClause;

$statsTotal = safeFetchOne($db, "SELECT COUNT(*) AS total FROM audit_request WHERE " . $baseWhere);
$statsRecent = safeFetchOne(
    $db,
    "SELECT COUNT(*) AS recent FROM audit_request WHERE " . $baseWhere . " AND localts > EXTRACT(EPOCH FROM (NOW() - INTERVAL '1 hour'))"
);

$totalLogs = intval($statsTotal["total"] ?? 0);
$recentLogs = intval($statsRecent["recent"] ?? 0);

$countParams = [];
if ($typeClause !== "") {
    $countParams = $typeParams;
}
$countRow = safeFetchOne($db, "SELECT COUNT(*) AS total FROM audit_request " . $whereSql, $countParams);
$filteredTotal = intval($countRow["total"] ?? 0);

$rowsParams = [];
if ($typeClause !== "") {
    $rowsParams[] = $typeParams[0];
}
$rowsParams[] = $limit;
$rowsParams[] = $offset;
$limitIndex = count($rowsParams) - 1;
$offsetIndex = count($rowsParams);

$rows = safeFetchAll(
    $db,
    "SELECT
        id,
        localts,
        request,
        result,
        connector,
        model,
        npc_name,
        event_type,
        status,
        error
     FROM audit_request
     " . $whereSql . "
     ORDER BY localts DESC, id DESC
     LIMIT $" . $limitIndex . " OFFSET $" . $offsetIndex,
    $rowsParams
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relationship Logs</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: <?= $isEmbedded ? "20px" : "120px" ?>;
            padding-bottom: 40px;
            padding-left: 10px;
            padding-right: 10px;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1.5px;
        }

        .panel {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 18px;
        }

        .rel-log-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rel-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rel-stat {
            background: #2a2a2a;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #4a4a4a;
        }

        .rel-stat-label {
            color: #aaa;
            font-size: 0.8em;
        }

        .rel-stat-value {
            color: #e6b76c;
            font-weight: bold;
            font-size: 1.1em;
        }

        .rel-filters {
            margin: 14px 0;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn-base {
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #666;
            background: #3a3a3a;
            color: #f8f9fa;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-base.active {
            border-color: rgba(230, 183, 108, 0.6);
            background: rgba(230, 183, 108, 0.2);
        }

        .btn-danger { background: #7a1e1e; border-color: #5a1515; }
        .btn-warning { background: #a04040; border-color: #804040; }
        .btn-primary { background: #2f5d87; border-color: #4677a4; }

        .cleanup-section {
            margin: 10px 0 14px 0;
            padding: 10px;
            background: #2a2a2a;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .cleanup-section select {
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #666;
            background: #2a2a2a;
            color: #f8f9fa;
        }

        .table-wrap {
            width: 100%;
            max-height: calc(100vh - 350px);
            overflow: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: rgba(20, 20, 20, 0.4);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: rgba(26, 26, 26, 0.95);
            padding: 9px;
            text-align: left;
            color: #e6b76c;
            border-bottom: 1px solid rgba(230, 183, 108, 0.35);
        }

        td {
            padding: 8px;
            border-bottom: 1px solid rgba(74, 74, 74, 0.35);
            vertical-align: top;
            color: #f8f9fa;
        }

        tr:hover td {
            background: rgba(230, 183, 108, 0.08);
        }

        .rel-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .rel-type-eval { background: #1e3a5f; color: #60a5fa; }
        .rel-type-analyze { background: #3f2f1e; color: #f59e0b; }
        .rel-type-npc2npc { background: #1e3f2f; color: #34d399; }

        .rel-change-item {
            margin: 4px 0;
            padding: 5px 8px;
            background: #1a1a1a;
            border-radius: 4px;
            border: 1px solid rgba(74, 74, 74, 0.45);
        }

        .rel-delta {
            font-weight: bold;
            border-radius: 4px;
            padding: 2px 6px;
            margin-right: 6px;
            display: inline-block;
            min-width: 30px;
            text-align: center;
        }

        .rel-delta-pos { background: #1e3f1e; color: #4ade80; }
        .rel-delta-neg { background: #3f1e1e; color: #f87171; }
        .rel-delta-zero { background: #2a2a2a; color: #bbb; }

        .rel-reason {
            color: #aaa;
            font-size: 11px;
            margin-top: 2px;
        }

        .mono {
            font-family: Consolas, "Courier New", monospace;
            font-size: 11px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .context-toggle {
            cursor: pointer;
            color: #e6b76c;
            text-decoration: underline;
            font-size: 11px;
        }

        .context-content {
            display: none;
            margin-top: 8px;
            padding: 8px;
            background: #141414;
            border: 1px solid #3a3a3a;
            border-radius: 4px;
            max-height: 260px;
            overflow: auto;
        }

        .pagination-buttons {
            margin: 12px 0;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .message-ok {
            margin: 10px 0;
            background: #1e3f1e;
            border: 1px solid #2d5a2d;
            padding: 9px 12px;
            border-radius: 6px;
            color: #4ade80;
        }

        .no-data {
            text-align: center;
            color: #aaa;
            padding: 28px;
        }
    </style>
</head>
<body>
<?php if (!$isEmbedded): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="container-fluid">
    <div class="panel">
        <div class="rel-log-header">
            <h1>Relationship LLM Logs</h1>
            <div class="rel-stats">
                <div class="rel-stat">
                    <div class="rel-stat-label">Total Evaluations</div>
                    <div class="rel-stat-value"><?= h(number_format($totalLogs)) ?></div>
                </div>
                <div class="rel-stat">
                    <div class="rel-stat-label">Last Hour</div>
                    <div class="rel-stat-value"><?= h(number_format($recentLogs)) ?></div>
                </div>
                <div class="rel-stat">
                    <div class="rel-stat-label">Filtered</div>
                    <div class="rel-stat-value"><?= h(number_format($filteredTotal)) ?></div>
                </div>
            </div>
        </div>

        <?php if ($deleteMessage !== ""): ?>
            <div class="message-ok"><?= h($deleteMessage) ?></div>
        <?php endif; ?>

        <div class="rel-filters">
            <a class="btn-base <?= $typeFilter === "" ? "active" : "" ?>" href="<?= h(relationshipLogsUrl(1, $limit, "", $isEmbedded)) ?>">All Types</a>
            <a class="btn-base <?= $typeFilter === "eval" ? "active" : "" ?>" href="<?= h(relationshipLogsUrl(1, $limit, "eval", $isEmbedded)) ?>">Evaluations</a>
            <a class="btn-base <?= $typeFilter === "analyze" ? "active" : "" ?>" href="<?= h(relationshipLogsUrl(1, $limit, "analyze", $isEmbedded)) ?>">Analyze</a>
            <a class="btn-base <?= $typeFilter === "npc2npc" ? "active" : "" ?>" href="<?= h(relationshipLogsUrl(1, $limit, "npc2npc", $isEmbedded)) ?>">NPC-to-NPC</a>
            <a class="btn-base btn-primary" href="<?= h(relationshipLogsUrl($page, $limit, $typeFilter, $isEmbedded)) ?>">Refresh</a>
        </div>

        <?php if ($totalLogs > 0): ?>
            <div class="cleanup-section">
                <form method="POST" style="display:inline-flex; gap:8px; align-items:center;" onsubmit="return confirm('Delete old relationship logs?');">
                    <input type="hidden" name="delete_action" value="delete_older">
                    <span>Delete logs older than</span>
                    <select name="delete_interval">
                        <option value="1 hour">1 hour</option>
                        <option value="6 hours">6 hours</option>
                        <option value="1 day">1 day</option>
                        <option value="3 days">3 days</option>
                        <option value="1 week" selected>1 week</option>
                        <option value="2 weeks">2 weeks</option>
                        <option value="1 month">1 month</option>
                    </select>
                    <button type="submit" class="btn-base btn-warning">Delete Old</button>
                </form>

                <form method="POST" style="display:inline-flex;" onsubmit="return confirm('Delete all relationship logs?');">
                    <input type="hidden" name="delete_action" value="delete_all">
                    <button type="submit" class="btn-base btn-danger">Delete All</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (count($rows) === 0): ?>
            <div class="no-data">
                <p>No relationship evaluations found.</p>
                <p>Ensure relationship LLM logging is active.</p>
            </div>
        <?php else: ?>
            <div class="pagination-buttons">
                <?php if ($page > 1): ?>
                    <a class="btn-base btn-primary" href="<?= h(relationshipLogsUrl($page - 1, $limit, $typeFilter, $isEmbedded)) ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?= h((string)$page) ?></span>
                <?php if (count($rows) >= $limit): ?>
                    <a class="btn-base btn-primary" href="<?= h(relationshipLogsUrl($page + 1, $limit, $typeFilter, $isEmbedded)) ?>">Next</a>
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:120px;">Time (UTC)</th>
                        <th style="width:90px;">Type</th>
                        <th style="width:180px;">NPC</th>
                        <th>Changes & Context</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $requestRaw = strval($row["request"] ?? "");
                            $resultRaw = strval($row["result"] ?? "");
                            $requestData = decodeJsonLoose($requestRaw);
                            $rawType = is_array($requestData) ? strval($requestData["type"] ?? "") : "";
                            if ($rawType === "") {
                                $rawType = strval($row["event_type"] ?? "");
                            }
                            [$typeLabel, $typeClass, $npcDisplay] = relationshipTypeLabel($rawType);
                            $speakerName = trim(strval($row["npc_name"] ?? ""));
                            $listenerName = "";
                            if ($speakerName === "" && is_array($requestData)) {
                                $speakerName = extractRelationshipTagFromMessages($requestData, "speaker");
                            }
                            if (is_array($requestData)) {
                                $listenerName = extractRelationshipTagFromMessages($requestData, "listener");
                            }
                            if (str_starts_with(strtolower($rawType), "npc2npc_")) {
                                $rest = trim(substr($rawType, 8));
                                $parts = explode("_", $rest, 2);
                                if ($speakerName === "") {
                                    $speakerName = trim($parts[0] ?? "");
                                }
                                if ($listenerName === "") {
                                    $listenerName = trim($parts[1] ?? "");
                                }
                            }
                            $changes = extractChanges($resultRaw, $speakerName, $listenerName);
                            $contextText = is_array($requestData) ? extractContextText($requestData) : "";
                            $rowKey = "ctx_" . intval($row["id"] ?? 0);
                            $npcDisplay = $speakerName !== "" ? $speakerName : "unknown";
                        ?>
                        <tr>
                            <td><?= h(formatLocalTs($row["localts"] ?? 0)) ?></td>
                            <td><span class="rel-type <?= h($typeClass) ?>"><?= h($typeLabel) ?></span></td>
                            <td class="mono"><?= h($npcDisplay) ?></td>
                            <td>
                                <?php if (count($changes) === 0): ?>
                                    <div class="rel-change-item">No parsed change payload.</div>
                                <?php else: ?>
                                    <?php foreach ($changes as $target => $change): ?>
                                        <?php
                                            $delta = intval($change["delta"] ?? 0);
                                            $reason = trim(strval($change["reason"] ?? ""));
                                            $deltaClass = $delta > 0 ? "rel-delta-pos" : ($delta < 0 ? "rel-delta-neg" : "rel-delta-zero");
                                        ?>
                                        <div class="rel-change-item">
                                            <span class="rel-delta <?= h($deltaClass) ?>"><?= h(($delta > 0 ? "+" : "") . strval($delta)) ?></span>
                                            <strong><?= h(strval($target)) ?></strong>
                                            <?php if ($reason !== ""): ?>
                                                <div class="rel-reason"><?= h($reason) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($contextText !== "" || trim($resultRaw) !== ""): ?>
                                    <span class="context-toggle" onclick="toggleContext('<?= h($rowKey) ?>')">Show/Hide Context</span>
                                    <div id="<?= h($rowKey) ?>" class="context-content">
                                        <?php if ($contextText !== ""): ?>
                                            <div class="mono"><strong>REQUEST MESSAGES</strong><br><?= h($contextText) ?></div>
                                        <?php endif; ?>
                                        <?php if (trim($resultRaw) !== ""): ?>
                                            <div class="mono" style="margin-top:10px;"><strong>RAW RESULT</strong><br><?= h($resultRaw) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row["error"])): ?>
                                            <div class="mono" style="margin-top:10px;color:#f87171;"><strong>ERROR</strong><br><?= h(strval($row["error"])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
function toggleContext(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}
</script>
</body>
</html>


