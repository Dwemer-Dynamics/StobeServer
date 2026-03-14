<?php
/**
 * StobeServer Audit / Cost Breakdown (Token-Based).
 * Herika-style infographic layout with Stobe token data.
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

function normalizeDateInput(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    return gmdate("Y-m-d");
}

function normalizeWeekInput(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-W\d{2}$/', $value) === 1) {
        return $value;
    }
    return gmdate("o-\WW");
}

function intOrZero(mixed $value): int
{
    return max(0, intval($value ?? 0));
}

function floatOrZero(mixed $value): float
{
    return max(0.0, floatval($value ?? 0));
}

function buildFilterClause(string $filterType, string $selectedDate, string $selectedWeek): array
{
    if ($filterType === "date") {
        return [
            "where" => "WHERE DATE(timezone('UTC', to_timestamp(localts))) = $1",
            "params" => [$selectedDate],
            "label" => "Date: " . $selectedDate . " (UTC)",
            "type" => "date",
        ];
    }

    if ($filterType === "week") {
        return [
            "where" => "WHERE to_char(timezone('UTC', to_timestamp(localts)), 'IYYY-\"W\"IW') = $1",
            "params" => [$selectedWeek],
            "label" => "Week: " . $selectedWeek . " (UTC)",
            "type" => "week",
        ];
    }

    if ($filterType === "all") {
        return [
            "where" => "",
            "params" => [],
            "label" => "All Time (UTC)",
            "type" => "all",
        ];
    }

    return [
        "where" => "WHERE DATE(timezone('UTC', to_timestamp(localts))) = CURRENT_DATE",
        "params" => [],
        "label" => "Date: Today (" . gmdate("Y-m-d") . ", UTC)",
        "type" => "today",
    ];
}

$db = $GLOBALS["db"];
$isEmbedded = (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");
$embedQuery = $isEmbedded ? "&embed=1" : "";

$filterType = strtolower(trim(strval($_GET["filter"] ?? "today")));
if (!in_array($filterType, ["today", "date", "week", "all"], true)) {
    $filterType = "today";
}

$selectedDate = normalizeDateInput(strval($_GET["date"] ?? gmdate("Y-m-d")));
$selectedWeek = normalizeWeekInput(strval($_GET["week"] ?? gmdate("o-\WW")));

$filter = buildFilterClause($filterType, $selectedDate, $selectedWeek);
$whereClause = $filter["where"];
$params = $filter["params"];
$filterType = $filter["type"];
$periodLabel = $filter["label"];

$summary = safeFetchOne(
    $db,
    "SELECT
        COUNT(*)::bigint AS request_count,
        COALESCE(SUM(total_tokens), 0)::bigint AS token_total,
        COALESCE(SUM(prompt_tokens), 0)::bigint AS prompt_total,
        COALESCE(SUM(completion_tokens), 0)::bigint AS completion_total,
        COALESCE(AVG(duration_ms), 0)::numeric(12,2) AS avg_duration_ms,
        COALESCE(SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END), 0)::bigint AS error_count
     FROM audit_request
     $whereClause",
    $params
);

$byConnector = safeFetchAll(
    $db,
    "SELECT
        COALESCE(NULLIF(connector, ''), '(unknown)') AS connector_name,
        COUNT(*)::bigint AS request_count,
        COALESCE(SUM(total_tokens), 0)::bigint AS token_total,
        COALESCE(SUM(prompt_tokens), 0)::bigint AS prompt_total,
        COALESCE(SUM(completion_tokens), 0)::bigint AS completion_total,
        COALESCE(AVG(duration_ms), 0)::numeric(12,2) AS avg_duration_ms
     FROM audit_request
     $whereClause
     GROUP BY 1
     ORDER BY token_total DESC, request_count DESC, connector_name ASC
     LIMIT 20",
    $params
);

$requestCount = intOrZero($summary["request_count"] ?? 0);
$tokenTotal = intOrZero($summary["token_total"] ?? 0);
$promptTotal = intOrZero($summary["prompt_total"] ?? 0);
$completionTotal = intOrZero($summary["completion_total"] ?? 0);
$avgDuration = floatOrZero($summary["avg_duration_ms"] ?? 0);
$errorCount = intOrZero($summary["error_count"] ?? 0);

$labels = [];
$values = [];
foreach ($byConnector as $row) {
    $labels[] = strval($row["connector_name"] ?? "(unknown)");
    $values[] = intOrZero($row["token_total"] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Breakdown (Token-Based)</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        * {
            box-sizing: border-box;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        main {
            padding-top: <?= $isEmbedded ? "20px" : "120px" ?>;
            padding-bottom: 40px;
            padding-left: min(5%, 24px);
            padding-right: min(5%, 24px);
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
            margin: 0;
        }

        .page-header {
            text-align: center;
            margin-bottom: 20px;
            padding: 20px;
            background: #2a2a2a;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
        }

        .page-header h1 {
            margin-bottom: 10px;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #e6b76c;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .page-header h3 {
            color: #e6b76c;
            margin: 8px 0;
            font-size: 1.1em;
            font-family: "Exo2", Arial, sans-serif;
            font-weight: 600;
        }

        .sub-note {
            color: #b9c0c7;
            margin-top: 10px;
            font-size: 12px;
        }

        .filters {
            margin-bottom: 20px;
            padding: 20px;
            background: #2a2a2a;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        .filters form {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
        }

        .filters label {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            color: #f8f9fa;
            font-weight: 500;
        }

        .filters input[type="date"],
        .filters input[type="week"] {
            padding: 8px 12px;
            border: 1px solid #4a4a4a;
            border-radius: 4px;
            font-size: 14px;
            background: #3a3a3a;
            color: #f8f9fa;
        }

        .filters button {
            padding: 8px 14px;
            background: #e6b76c;
            color: #111;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .filters button:hover {
            background: #efc98e;
            box-shadow: 0 2px 8px rgba(230, 183, 108, 0.35);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #2a2a2a;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }

        .stat-label {
            color: #b9c0c7;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            color: #e6b76c;
            font-size: 22px;
            font-weight: 700;
            margin-top: 2px;
        }

        .chart-section {
            background: #2a2a2a;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            margin-bottom: 16px;
        }

        .chart-container {
            position: relative;
            width: 100%;
            max-width: 700px;
            height: 700px;
            margin: 0 auto;
        }

        canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .table-wrap {
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            overflow: auto;
            max-height: 420px;
            background: #222;
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
            text-align: left;
            padding: 10px;
            background: rgba(26, 26, 26, 0.95);
            color: #e6b76c;
            border-bottom: 1px solid rgba(230, 183, 108, 0.35);
        }

        td {
            padding: 10px;
            border-bottom: 1px solid rgba(74, 74, 74, 0.35);
            color: #f8f9fa;
        }

        tr:hover td {
            background: rgba(230, 183, 108, 0.08);
        }

        .mono {
            font-family: Consolas, "Courier New", monospace;
            font-size: 11px;
            word-break: break-word;
        }

        .empty-state {
            color: #b9c0c7;
            text-align: center;
            padding: 20px 0;
        }

        @media (max-width: 768px) {
            main {
                padding-left: 3%;
                padding-right: 3%;
            }
            .page-header h1 {
                font-size: 1.6em;
            }
            .chart-container {
                height: 500px;
            }
            .filters form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php if (!$isEmbedded): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div class="page-header">
        <h1>Token Distribution by Connector</h1>
        <h3><?= h($periodLabel) ?></h3>
        <h3>Total Tokens: <?= h(number_format($tokenTotal)) ?></h3>
        <div class="sub-note">Token-based view from <code>audit_request</code>. Monetary provider pricing is not persisted in Stobe yet.</div>
    </div>

    <div class="filters">
        <form method="GET" action="">
            <label>
                <input type="radio" name="filter" value="today" <?= $filterType === "today" ? "checked" : "" ?>>
                Today
            </label>
            <label>
                <input type="radio" name="filter" value="all" <?= $filterType === "all" ? "checked" : "" ?>>
                All Time
            </label>
            <label>
                Date:
                <input type="date" id="dateInput" value="<?= h($selectedDate) ?>">
                <button type="button" onclick="setFilterToDate()">Apply Date</button>
            </label>
            <label>
                Week:
                <input type="week" id="weekInput" value="<?= h($selectedWeek) ?>">
                <button type="button" onclick="setFilterToWeek()">Apply Week</button>
            </label>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Requests</div><div class="stat-value"><?= h(number_format($requestCount)) ?></div></div>
        <div class="stat-card"><div class="stat-label">Prompt Tokens</div><div class="stat-value"><?= h(number_format($promptTotal)) ?></div></div>
        <div class="stat-card"><div class="stat-label">Completion Tokens</div><div class="stat-value"><?= h(number_format($completionTotal)) ?></div></div>
        <div class="stat-card"><div class="stat-label">Avg Duration (ms)</div><div class="stat-value"><?= h(number_format($avgDuration, 2)) ?></div></div>
        <div class="stat-card"><div class="stat-label">Errors</div><div class="stat-value"><?= h(number_format($errorCount)) ?></div></div>
    </div>

    <div class="chart-section">
        <?php if (count($labels) === 0): ?>
            <div class="empty-state">No audit rows in this filter range.</div>
        <?php else: ?>
            <div class="chart-container">
                <canvas id="tokenChart"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <div class="chart-section">
        <h3 style="margin-top:0;">Connector Breakdown</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Connector</th>
                    <th>Requests</th>
                    <th>Total Tokens</th>
                    <th>Prompt</th>
                    <th>Completion</th>
                    <th>Avg Duration (ms)</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($byConnector) === 0): ?>
                    <tr><td colspan="6" class="empty-state">No data.</td></tr>
                <?php else: ?>
                    <?php foreach ($byConnector as $row): ?>
                        <tr>
                            <td class="mono"><?= h(strval($row["connector_name"] ?? "(unknown)")) ?></td>
                            <td><?= h(number_format(intOrZero($row["request_count"] ?? 0))) ?></td>
                            <td><?= h(number_format(intOrZero($row["token_total"] ?? 0))) ?></td>
                            <td><?= h(number_format(intOrZero($row["prompt_total"] ?? 0))) ?></td>
                            <td><?= h(number_format(intOrZero($row["completion_total"] ?? 0))) ?></td>
                            <td><?= h(number_format(floatOrZero($row["avg_duration_ms"] ?? 0), 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    const EMBED_QUERY = <?= json_encode($embedQuery, JSON_UNESCAPED_SLASHES) ?>;

    function setFilterToDate() {
        const dateInput = document.getElementById('dateInput').value;
        if (!dateInput) return;
        window.location.href = 'audit.php?filter=date&date=' + encodeURIComponent(dateInput) + EMBED_QUERY;
    }

    function setFilterToWeek() {
        const weekInput = document.getElementById('weekInput').value;
        if (!weekInput) return;
        window.location.href = 'audit.php?filter=week&week=' + encodeURIComponent(weekInput) + EMBED_QUERY;
    }

    document.querySelectorAll('input[name="filter"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'today' || this.value === 'all') {
                window.location.href = 'audit.php?filter=' + encodeURIComponent(this.value) + EMBED_QUERY;
            }
        });
    });

    (function renderChart() {
        const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
        const dataValues = <?= json_encode($values, JSON_NUMERIC_CHECK) ?>;
        if (!labels.length || !dataValues.length) return;

        const total = dataValues.reduce((a, b) => a + b, 0);
        const formatted = labels.map((label, i) => {
            return label + ' (' + Number(dataValues[i]).toLocaleString() + ' tokens)';
        });

        const ctx = document.getElementById('tokenChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: formatted,
                datasets: [{
                    label: 'Total Tokens',
                    data: dataValues,
                    backgroundColor: [
                        '#e6b76c', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                        '#ec4899', '#14b8a6', '#6366f1', '#f97316', '#22c55e'
                    ],
                    borderColor: '#2a2a2a',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 20,
                            padding: 15,
                            color: '#f8f9fa',
                            font: { size: 13 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#2a2a2a',
                        titleColor: '#e6b76c',
                        bodyColor: '#f8f9fa',
                        borderColor: '#4a4a4a',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const value = Number(context.parsed || 0);
                                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                return context.label + ': ' + value.toLocaleString() + ' tokens (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    })();
</script>
</body>
</html>

