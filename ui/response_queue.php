<?php
/**
 * StobeServer Response Queue.
 * Herika-style response queue viewer over persisted log rows.
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

function timeColor(float $seconds): string
{
    if ($seconds <= 2.0) {
        return "#88cc88";
    }
    if ($seconds <= 5.0) {
        return "#ffff00";
    }
    if ($seconds <= 8.0) {
        return "#ffa500";
    }
    return "#ff6666";
}

function renderUrlWithTimings(string $url, string $response): string
{
    $safeUrl = trim($url);
    if ($safeUrl === "") {
        return "";
    }

    if (str_starts_with($response, "Array")) {
        $stripped = preg_replace('/ in \d+\.?\d* secs$/', '', $safeUrl);
        return h((string)$stripped);
    }

    $pattern = '/\[AI secs\]\s+([\d.]+)\s+\[TTS secs\]\s+([\d.]+)/';
    if (preg_match($pattern, $safeUrl, $matches) !== 1) {
        return h($safeUrl);
    }

    $aiTime = floatval($matches[1] ?? 0.0);
    $totalTts = floatval($matches[2] ?? 0.0);
    $ttsOnly = max(0.0, $totalTts - $aiTime);

    $baseText = trim(substr($safeUrl, 0, (int)strpos($safeUrl, '[AI secs]')));
    $baseHtml = $baseText !== "" ? h($baseText) . "<br>" : "";

    return $baseHtml
        . "[LLM] <span style='color:" . h(timeColor($aiTime)) . "'>" . h(number_format($aiTime, 2)) . "</span>"
        . " [TTS] <span style='color:" . h(timeColor($ttsOnly)) . "'>" . h(number_format($ttsOnly, 2)) . "</span>"
        . " [Total] <span style='color:" . h(timeColor($totalTts)) . "'>" . h(number_format($totalTts, 2)) . "</span>";
}

function buildQueueUrl(int $page, int $limit, bool $embedded): string
{
    $url = "response_queue.php?page=" . max(1, $page) . "&limit=" . max(10, $limit);
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
    safeExec($db, "DELETE FROM log");
    header("Location: " . buildQueueUrl(1, $limit, $isEmbedded));
    exit;
}

if (isset($_GET["export"]) && $_GET["export"] === "1") {
    $allRows = safeFetchAll(
        $db,
        "SELECT rowid, localts, response, prompt, url
         FROM log
         ORDER BY localts DESC, rowid DESC"
    );
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"stobe_response_queue.csv\"");
    $out = fopen("php://output", "w");
    if ($out !== false) {
        fputcsv($out, ["rowid", "time_utc", "response", "prompt", "url"]);
        foreach ($allRows as $row) {
            fputcsv($out, [
                intval($row["rowid"] ?? 0),
                formatLocalTs($row["localts"] ?? 0),
                strval($row["response"] ?? ""),
                strval($row["prompt"] ?? ""),
                strval($row["url"] ?? ""),
            ]);
        }
        fclose($out);
    }
    exit;
}

$rows = safeFetchAll(
    $db,
    "SELECT rowid, localts, response, prompt, url
     FROM log
     ORDER BY localts DESC, rowid DESC
     LIMIT $1 OFFSET $2",
    [$limit, $offset]
);

$totalRow = safeFetchOne($db, "SELECT COUNT(*) AS total FROM log");
$totalRecords = intval($totalRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Response Queue</title>
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
        .controls-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }
        .pagination-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .btn-base {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 8px;
            border: 1px solid rgba(138, 155, 182, 0.38);
            background: rgba(30, 35, 45, 0.8);
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }
        .btn-base:hover {
            background: rgba(85, 95, 109, 0.9);
            border-color: rgba(230, 183, 108, 0.4);
            color: #fff;
        }
        .btn-danger {
            background: rgba(176, 0, 32, 0.78);
            border-color: rgba(255, 104, 129, 0.5);
        }
        .btn-danger:hover {
            background: rgba(204, 0, 36, 0.9);
            border-color: rgba(255, 130, 150, 0.65);
        }
        .mono {
            font-family: Consolas, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .empty-message {
            color: #9ca3af;
            padding: 12px 0;
        }
    </style>
</head>
<body>
<?php if (!$isEmbedded): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="container-fluid">
    <div class="tab-content">
        <h1>Response Queue</h1>
        <div class="controls-row">
            <a
                href="<?= h(buildQueueUrl($page, $limit, $isEmbedded) . "&cleanlog=1") ?>"
                class="btn-base btn-danger"
                onclick="return confirm('This will clear all entries in the response queue log. Continue?');"
            >Clean Response Log</a>
            <a
                href="<?= h(buildQueueUrl($page, $limit, $isEmbedded) . "&export=1") ?>"
                class="btn-base"
            >Export Response Log</a>
            <span class="text-secondary">Rows: <?= h((string)$totalRecords) ?></span>
        </div>

        <div class="pagination-row">
            <span>Page <?= h((string)$page) ?> / <?= h((string)$totalPages) ?></span>
            <?php if ($page > 1): ?>
                <a class="btn-base" href="<?= h(buildQueueUrl($page - 1, $limit, $isEmbedded)) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn-base" href="<?= h(buildQueueUrl($page + 1, $limit, $isEmbedded)) ?>">Next</a>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 180px;">Time (UTC)</th>
                        <th style="width: 30%;">AI Response</th>
                        <th style="width: 30%;">Prompt</th>
                        <th>HTTP Request</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr>
                            <td colspan="4" class="empty-message">No response queue entries found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= h(formatLocalTs($row["localts"] ?? 0)) ?></td>
                                <td class="mono"><?= nl2br(h(strval($row["response"] ?? ""))) ?></td>
                                <td class="mono"><?= nl2br(h(strval($row["prompt"] ?? ""))) ?></td>
                                <td><?= renderUrlWithTimings(strval($row["url"] ?? ""), strval($row["response"] ?? "")) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

