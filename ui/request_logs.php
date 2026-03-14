<?php
/**
 * StobeServer Request Logs.
 * Rewired for Stobe audit_request schema and control-panel embed usage.
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

function truncateText(string $value, int $maxLen = 180): string
{
    $trimmed = trim($value);
    if (strlen($trimmed) <= $maxLen) {
        return $trimmed;
    }
    return substr($trimmed, 0, $maxLen) . "...";
}

function requestLogsUrl(int $page, int $limit, bool $embedded): string
{
    $url = "request_logs.php?page=" . max(1, $page) . "&limit=" . max(10, $limit);
    if ($embedded) {
        $url .= "&embed=1";
    }
    return $url;
}

$db = $GLOBALS["db"];
$isEmbedded = (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 50;
$limit = max(10, min(300, $limit));
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

if (isset($_GET["cleanlog"]) && $_GET["cleanlog"] === "1") {
    safeExec($db, "DELETE FROM audit_request");
    header("Location: " . requestLogsUrl(1, $limit, $isEmbedded));
    exit;
}

$rows = safeFetchAll(
    $db,
    "SELECT
        id,
        localts,
        request_id,
        event_type,
        npc_name,
        connector,
        model,
        status,
        http_code,
        duration_ms,
        prompt_tokens,
        completion_tokens,
        total_tokens,
        url,
        request,
        result,
        error
     FROM audit_request
     ORDER BY localts DESC, id DESC
     LIMIT $1 OFFSET $2",
    [$limit, $offset]
);

$totalRow = safeFetchOne($db, "SELECT COUNT(*) AS total FROM audit_request");
$totalRecords = intval($totalRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Logs</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: <?= $isEmbedded ? "20px" : "120px" ?>;
            padding-bottom: 40px;
            padding-left: 10px;
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

        .tab-content {
            display: block;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .table-container {
            max-height: calc(100vh - 330px);
            margin-top: 16px;
            width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 12px;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 10px;
            font-weight: bold;
            text-align: left;
            color: #e6b76c;
            background: rgba(26, 26, 26, 0.95);
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
        }

        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(74, 74, 74, 0.3);
            color: #f8f9fa;
            vertical-align: top;
        }

        tr:hover {
            background: rgba(230, 183, 108, 0.08);
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            font-size: 11px;
            border: 1px solid transparent;
        }

        .status-ok {
            color: #9be49f;
            background: rgba(60, 133, 67, 0.2);
            border-color: rgba(60, 133, 67, 0.5);
        }

        .status-error {
            color: #ffb0b0;
            background: rgba(171, 58, 58, 0.2);
            border-color: rgba(171, 58, 58, 0.5);
        }

        .btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .btn-base {
            cursor: pointer;
            padding: 7px 12px;
            border-radius: 6px;
            border: 1px solid #666;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-primary {
            background: #2f5d87;
            color: #fff;
            border-color: #4677a4;
        }

        .btn-danger {
            background: #8f1f2e;
            color: #fff;
            border-color: #a43846;
        }

        .btn-secondary {
            background: #3a3a3a;
            color: #f8f9fa;
            border-color: #595959;
        }

        .btn-linklike {
            background: rgba(230, 183, 108, 0.14);
            border: 1px solid rgba(230, 183, 108, 0.4);
            color: #f8f9fa;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            cursor: pointer;
        }

        .meta-line {
            color: #b9c0c7;
            font-size: 12px;
            margin-bottom: 0;
        }

        .mono {
            font-family: Consolas, "Courier New", monospace;
            font-size: 11px;
            word-break: break-word;
        }

        .empty-state {
            color: #b9c0c7;
            padding: 16px 0;
        }

        .modal-backdrop-lite {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-panel {
            width: min(1000px, 95vw);
            max-height: 85vh;
            background: #202020;
            border: 1px solid rgba(230, 183, 108, 0.45);
            border-radius: 8px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
            display: flex;
            flex-direction: column;
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(230, 183, 108, 0.25);
        }

        .modal-title {
            color: #e6b76c;
            font-size: 16px;
            margin: 0;
        }

        .modal-body {
            padding: 12px 14px;
            overflow: auto;
        }

        .modal-pre {
            white-space: pre-wrap;
            word-break: break-word;
            color: #f8f9fa;
            margin: 0;
            font-size: 12px;
            line-height: 1.4;
            font-family: Consolas, "Courier New", monospace;
        }
    </style>
</head>
<body>
<?php if (!$isEmbedded): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="container-fluid">
    <div class="tab-content">
        <h1 class="my-2">Request to LLM Services Log</h1>
        <p class="meta-line">
            Showing <?= h((string)count($rows)) ?> of <?= h((string)$totalRecords) ?> records.
            Page <?= h((string)$page) ?> / <?= h((string)$totalPages) ?>.
        </p>

        <div class="btn-row mt-3">
            <?php if ($page > 1): ?>
                <a class="btn-base btn-primary" href="<?= h(requestLogsUrl($page - 1, $limit, $isEmbedded)) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn-base btn-primary" href="<?= h(requestLogsUrl($page + 1, $limit, $isEmbedded)) ?>">Next</a>
            <?php endif; ?>
            <a class="btn-base btn-secondary" href="<?= h(requestLogsUrl(1, 100, $isEmbedded)) ?>">Limit 100</a>
            <a class="btn-base btn-secondary" href="<?= h(requestLogsUrl(1, 200, $isEmbedded)) ?>">Limit 200</a>
            <a class="btn-base btn-danger" href="<?= h(requestLogsUrl(1, $limit, $isEmbedded)) ?>&cleanlog=1" onclick="return confirm('Delete all request log rows?');">Clear Log</a>
        </div>

        <div class="table-container">
            <?php if (count($rows) === 0): ?>
                <div class="empty-state">No rows in <code>audit_request</code> yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Time (UTC)</th>
                        <th>Request ID</th>
                        <th>Event</th>
                        <th>NPC</th>
                        <th>Connector / Model</th>
                        <th>Status</th>
                        <th>HTTP</th>
                        <th>Duration</th>
                        <th>Tokens</th>
                        <th>URL</th>
                        <th>Request</th>
                        <th>Result</th>
                        <th>Error</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $id = intval($row["id"] ?? 0);
                            $timeUtc = formatLocalTs($row["localts"] ?? 0);
                            $requestId = trim(strval($row["request_id"] ?? ""));
                            $eventType = trim(strval($row["event_type"] ?? ""));
                            $npcName = trim(strval($row["npc_name"] ?? ""));
                            $connector = trim(strval($row["connector"] ?? ""));
                            $model = trim(strval($row["model"] ?? ""));
                            $status = strtolower(trim(strval($row["status"] ?? "")));
                            $statusClass = ($status === "ok") ? "status-ok" : "status-error";
                            $httpCode = intval($row["http_code"] ?? 0);
                            $durationMs = intval($row["duration_ms"] ?? 0);
                            $promptTokens = intval($row["prompt_tokens"] ?? 0);
                            $completionTokens = intval($row["completion_tokens"] ?? 0);
                            $totalTokens = intval($row["total_tokens"] ?? 0);
                            $url = trim(strval($row["url"] ?? ""));
                            $requestRaw = strval($row["request"] ?? "");
                            $resultRaw = strval($row["result"] ?? "");
                            $errorRaw = trim(strval($row["error"] ?? ""));
                            $requestPreview = truncateText($requestRaw, 140);
                            $resultPreview = truncateText($resultRaw, 140);
                            $requestModalId = "req_" . $id;
                            $resultModalId = "res_" . $id;
                        ?>
                        <tr>
                            <td class="mono"><?= h((string)$id) ?></td>
                            <td><?= h($timeUtc) ?></td>
                            <td class="mono"><?= h($requestId) ?></td>
                            <td><?= h($eventType) ?></td>
                            <td><?= h($npcName) ?></td>
                            <td>
                                <div><?= h($connector) ?></div>
                                <div class="mono"><?= h($model) ?></div>
                            </td>
                            <td><span class="status-pill <?= h($statusClass) ?>"><?= h($status === "" ? "unknown" : $status) ?></span></td>
                            <td><?= h((string)$httpCode) ?></td>
                            <td><?= h((string)$durationMs) ?> ms</td>
                            <td class="mono"><?= h((string)$promptTokens) ?> / <?= h((string)$completionTokens) ?> / <?= h((string)$totalTokens) ?></td>
                            <td class="mono"><?= h($url) ?></td>
                            <td>
                                <div class="mono"><?= h($requestPreview) ?></div>
                                <button type="button" class="btn-linklike mt-1" data-open-modal="<?= h($requestModalId) ?>">View</button>
                            </td>
                            <td>
                                <div class="mono"><?= h($resultPreview) ?></div>
                                <button type="button" class="btn-linklike mt-1" data-open-modal="<?= h($resultModalId) ?>">View</button>
                            </td>
                            <td class="mono">
                                <?= h($errorRaw) ?>
                                <div id="<?= h($requestModalId) ?>" class="modal-backdrop-lite">
                                    <div class="modal-panel">
                                        <div class="modal-head">
                                            <h3 class="modal-title">Request Payload (ID <?= h((string)$id) ?>)</h3>
                                            <button type="button" class="btn-base btn-secondary" data-close-modal="<?= h($requestModalId) ?>">Close</button>
                                        </div>
                                        <div class="modal-body"><pre class="modal-pre"><?= h($requestRaw) ?></pre></div>
                                    </div>
                                </div>
                                <div id="<?= h($resultModalId) ?>" class="modal-backdrop-lite">
                                    <div class="modal-panel">
                                        <div class="modal-head">
                                            <h3 class="modal-title">Result Payload (ID <?= h((string)$id) ?>)</h3>
                                            <button type="button" class="btn-base btn-secondary" data-close-modal="<?= h($resultModalId) ?>">Close</button>
                                        </div>
                                        <div class="modal-body"><pre class="modal-pre"><?= h($resultRaw) ?></pre></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
(function(){
    const openButtons = document.querySelectorAll('[data-open-modal]');
    const closeButtons = document.querySelectorAll('[data-close-modal]');

    openButtons.forEach(function(button){
        button.addEventListener('click', function(){
            const id = button.getAttribute('data-open-modal');
            const modal = document.getElementById(id);
            if (modal) {
                modal.style.display = 'flex';
            }
        });
    });

    closeButtons.forEach(function(button){
        button.addEventListener('click', function(){
            const id = button.getAttribute('data-close-modal');
            const modal = document.getElementById(id);
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });

    document.querySelectorAll('.modal-backdrop-lite').forEach(function(modal){
        modal.addEventListener('click', function(event){
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    });
})();
</script>
</body>
</html>

