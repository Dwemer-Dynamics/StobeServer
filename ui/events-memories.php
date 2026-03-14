<?php
/**
 * StobeServer Events & Memories.
 * Herika-style tabbed control panel with Stobe-backed data.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$webRoot = dirname(dirname($scriptPath));
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'memories')));
if ($activeTab === 'responses') {
    header('Location: ai-response.php');
    exit;
}
if (!in_array($activeTab, ['memories'], true)) {
    $activeTab = 'memories';
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$limit = max(10, min(500, $limit));
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatLocalTs(mixed $value): string
{
    $ts = intval($value ?? 0);
    if ($ts <= 0) {
        return '';
    }
    $dt = new DateTime('@' . $ts);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('d-m-Y H:i:s');
}

function formatGameTs(mixed $value): string
{
    return stobeGametsDisplayWithRaw($value);
}

function formatPeopleLabel(mixed $value): string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $names = [];
        foreach ($decoded as $entry) {
            if (!is_scalar($entry) || $entry === null) {
                continue;
            }
            $name = trim(strval($entry));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if (count($names) > 0) {
            return implode(', ', $names);
        }
    }
    return $raw;
}

function tabUrl(string $tab, int $page, int $limit): string
{
    return 'events-memories.php?tab=' . urlencode($tab) . '&page=' . max(1, $page) . '&limit=' . max(10, $limit);
}

function safeFetchAll(sql $db, string $query): array
{
    try {
        return $db->fetchAll($query);
    } catch (Throwable $exception) {
        return [];
    }
}

function safeCount(sql $db, string $query): int
{
    try {
        $row = $db->fetchOne($query);
        return intval($row['total'] ?? 0);
    } catch (Throwable $exception) {
        return 0;
    }
}

$db = $GLOBALS['db'];
$rows = [];
$totalRecords = 0;

$rows = safeFetchAll(
    $db,
    "SELECT id, people, summary, period_start, period_end, created_at
     FROM memory_summary
     ORDER BY created_at DESC, id DESC
     LIMIT $limit OFFSET $offset"
);
$totalRecords = safeCount($db, "SELECT COUNT(*) AS total FROM memory_summary");

$totalPages = max(1, (int)ceil($totalRecords / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events & Memories</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        body {
            padding-top: 80px;
        }

        main {
            padding-top: 20px;
            padding-bottom: 40px;
            padding-left: 10px;
        }

        @font-face {
            font-family: 'MagicCards';
            src: url('css/font/MailartRubberstamp-Regular.otf') format('opentype');
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: 'MagicCards', sans-serif;
            letter-spacing: 1.5px;
        }

        .tab-container {
            margin: 20px 0;
        }

        .tab-buttons {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.2);
            gap: 5px;
            word-spacing: 5px;
        }

        .tab-button {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.8), rgba(34, 34, 34, 0.9));
            border: 2px solid #3a3a3a;
            border-bottom: none;
            padding: 12px 18px;
            color: #f8f9fa;
            cursor: pointer;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            transition: all 0.3s ease;
            font-size: 1em;
            white-space: nowrap;
            font-family: 'MagicCards', sans-serif;
            word-spacing: 5px;
            letter-spacing: 1.5px;
            margin-bottom: -2px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .tab-button:hover {
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 1));
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .tab-button.active {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-color: rgba(230, 183, 108, 0.5);
            border-bottom: 2px solid rgba(42, 42, 42, 0.95);
            color: #e6b76c;
            box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
        }

        .tab-content {
            display: none;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 8px;
            border-top-left-radius: 0;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .tab-content.active {
            display: block;
        }

        .table-container {
            max-height: calc(100vh - 450px) !important;
            margin-top: 20px;
            width: 100%;
            overflow-x: auto;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                        inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 12px;
        }

        .table-container table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: small;
        }

        .table-container th {
            padding: 12px 10px;
            font-weight: bold;
            text-align: left;
            vertical-align: top;
            color: #e6b76c;
            background: rgba(26, 26, 26, 0.6);
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
            font-size: 0.95em;
        }

        th {
            padding: 12px;
            font-weight: bold;
            text-align: left;
            color: #e6b76c;
            background: rgba(26, 26, 26, 0.6);
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
        }

        .table-container td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            vertical-align: top;
            padding: 10px;
            line-height: 1.5;
            border-bottom: 1px solid rgba(74, 74, 74, 0.3);
            color: #d0d0d0;
        }

        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(74, 74, 74, 0.3);
            color: #f8f9fa;
        }

        .table-container tr:hover td,
        tr:hover td {
            background: rgba(230, 183, 108, 0.05);
        }

        .pagination-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .info-message {
            color: #9ca3af;
            padding: 14px 2px;
        }

        @media (max-width: 768px) {
            .table-container {
                margin: 10px -15px;
                border-radius: 0;
            }
            table {
                font-size: smaller;
            }
            th, td {
                padding: 8px;
            }
            .tab-button {
                padding: 10px 14px;
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>

<main class="container-fluid">
    <div class="tab-container">
        <div class="tab-buttons">
            <a class="tab-button" href="events.php">&#x1F4DD; Events</a>
            <a class="tab-button" href="ai-response.php">&#x1F916; AI Responses</a>
            <a class="tab-button <?= $activeTab === 'memories' ? 'active' : '' ?>" href="memories.php">&#x1F9E0; Memories</a>
        </div>

        <div id="responses-tab" class="tab-content <?= $activeTab === 'responses' ? 'active' : '' ?>">
            <div class="info-message">AI response audit (token/model usage).</div>
            <div class="table-container">
                <table>
                    <thead>
                    <tr>
                        <th style="width:20%">NPC</th>
                        <th style="width:28%">Model</th>
                        <th style="width:14%">Prompt Tokens</th>
                        <th style="width:16%">Completion Tokens</th>
                        <th style="width:22%">UTC</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($activeTab === 'responses' && count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= h($row['npc_name'] ?? '') ?></td>
                                <td><?= h($row['model'] ?? '') ?></td>
                                <td><?= h($row['prompt_tokens'] ?? 0) ?></td>
                                <td><?= h($row['completion_tokens'] ?? 0) ?></td>
                                <td><?= h(formatLocalTs($row['localts'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No AI response rows found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="memories-tab" class="tab-content <?= $activeTab === 'memories' ? 'active' : '' ?>">
            <div class="info-message">Memory summary records (placeholder tab style with current table wiring).</div>
            <div class="table-container">
                <table>
                    <thead>
                    <tr>
                        <th style="width:16%">People</th>
                        <th style="width:60%">Summary</th>
                        <th style="width:12%">Period</th>
                        <th style="width:12%">Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($activeTab === 'memories' && count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $period = '';
                            $start = trim((string)($row['period_start'] ?? ''));
                            $end = trim((string)($row['period_end'] ?? ''));
                            if ($start !== '' || $end !== '') {
                                $period = ($start !== '' ? $start : '?') . ' -> ' . ($end !== '' ? $end : '?');
                            }
                            ?>
                            <tr>
                                <td><?= h(formatPeopleLabel($row['people'] ?? '')) ?></td>
                                <td><?= h($row['summary'] ?? '') ?></td>
                                <td><?= h($period) ?></td>
                                <td><?= h((string)($row['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No memory rows found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pagination-row">
            <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
            <?php if ($page > 1): ?>
                <a class="btn-base" href="<?= h(tabUrl($activeTab, $page - 1, $limit)) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn-base" href="<?= h(tabUrl($activeTab, $page + 1, $limit)) ?>">Next</a>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function switchTab(tabName) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    url.searchParams.set('page', '1');
    if (!url.searchParams.get('limit')) {
        url.searchParams.set('limit', '100');
    }
    window.location.href = url.toString();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

