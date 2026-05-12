<?php
/**
 * StobeServer Events.
 * Herika-style event log page (features + layout parity for event tab).
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
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

function formatGameTs(mixed $value): string
{
    return stobeGametsDisplayWithRaw($value);
}

function eventsUrl(int $page, int $limit, bool $autorefresh = false): string
{
    $url = "events.php?page=" . max(1, $page) . "&limit=" . max(10, $limit);
    if ($autorefresh) {
        $url .= "&autorefresh=true";
    }
    return $url;
}

function stobeEventsHiddenTypes(): array
{
    return ['inputtext', 'inputtext_s', 'bored', 'infonpc', 'infonpc_close', 'infoloc'];
}

function stobeEventsHiddenTypePlaceholders(int $startIndex = 1): string
{
    $placeholders = [];
    $nextIndex = max(1, $startIndex);
    foreach (stobeEventsHiddenTypes() as $_unusedType) {
        $placeholders[] = '$' . $nextIndex;
        $nextIndex++;
    }
    return implode(', ', $placeholders);
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

$db = $GLOBALS["db"];
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 100;
$limit = max(10, min(500, $limit));
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;
$isAutoRefresh = isset($_GET["autorefresh"]) && $_GET["autorefresh"];

// Handle delete all.
if (isset($_GET["reset"]) && $_GET["reset"]) {
    safeExec($db, "DELETE FROM eventlog");
    header("Location: events.php");
    exit;
}

// Handle delete latest N.
if (isset($_GET["delete_last"])) {
    $delCount = intval($_GET["delete_last"]);
    if (in_array($delCount, [20, 50, 100], true)) {
        safeExec(
            $db,
            "DELETE FROM eventlog
             WHERE rowid IN (
                 SELECT rowid FROM eventlog
                 ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) DESC, ts DESC, rowid DESC
                 LIMIT $1
             )",
            [$delCount]
        );
        header("Location: events.php");
        exit;
    }
}

// Handle bulk delete selected.
if (isset($_POST["delete_selected"])) {
    $rowids = $_POST["rowids"] ?? [];
    if (is_array($rowids)) {
        $sanitizedRowids = array_values(
            array_filter(
                array_map("intval", $rowids),
                static function ($id) {
                    return $id > 0;
                }
            )
        );
        if (count($sanitizedRowids) > 0) {
            $placeholders = [];
            $params = [];
            foreach ($sanitizedRowids as $idx => $rowid) {
                $placeholders[] = "$" . ($idx + 1);
                $params[] = $rowid;
            }
            safeExec($db, "DELETE FROM eventlog WHERE rowid IN (" . implode(",", $placeholders) . ")", $params);
            header("Location: events.php?deleted=" . count($sanitizedRowids));
            exit;
        }
    }
    header("Location: events.php?error=invalid_delete");
    exit;
}

// Live updates endpoint.
if (isset($_GET["ajax"]) && $_GET["ajax"] === "eventlog_updates") {
    $sinceRowId = isset($_GET["since_rowid"]) ? max(0, intval($_GET["since_rowid"])) : 0;
    $hiddenTypes = stobeEventsHiddenTypes();
    $hiddenTypePlaceholders = stobeEventsHiddenTypePlaceholders(2);
    $liveRows = safeFetchAll(
        $db,
        "SELECT rowid, type, data, people, location, gamets, localts, ts
         FROM eventlog
         WHERE rowid > $1
           AND type NOT IN (" . $hiddenTypePlaceholders . ")
         ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) DESC, ts DESC, rowid DESC
         LIMIT 50",
        array_merge([$sinceRowId], $hiddenTypes)
    );

    $payloadRows = [];
    foreach ($liveRows as $row) {
        $payloadRows[] = [
            "ROWID" => intval($row["rowid"] ?? 0),
            "Event" => (string)($row["type"] ?? ""),
            "Events" => (string)($row["data"] ?? ""),
            "People Present" => (string)($row["people"] ?? ""),
            "Location" => (string)($row["location"] ?? ""),
            "Game Time" => formatGameTs($row["gamets"] ?? 0),
            "Time (UTC)" => formatLocalTs($row["localts"] ?? 0),
            "TS" => (string)($row["ts"] ?? ""),
        ];
    }

    header("Content-Type: application/json");
    echo json_encode([
        "success" => true,
        "new_count" => count($payloadRows),
        "data" => $payloadRows,
    ]);
    exit;
}

$hiddenTypes = stobeEventsHiddenTypes();
$hiddenTypePlaceholders = stobeEventsHiddenTypePlaceholders(1);
$limitPlaceholder = '$' . (count($hiddenTypes) + 1);
$offsetPlaceholder = '$' . (count($hiddenTypes) + 2);
$rows = safeFetchAll(
    $db,
    "SELECT rowid, type, data, people, location, gamets, localts, ts
     FROM eventlog
     WHERE type NOT IN (" . $hiddenTypePlaceholders . ")
     ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) DESC, ts DESC, rowid DESC
     LIMIT " . $limitPlaceholder . " OFFSET " . $offsetPlaceholder,
    array_merge($hiddenTypes, [$limit, $offset])
);

$totalRecordsRow = safeFetchOne(
    $db,
    "SELECT COUNT(*) AS total
     FROM eventlog
     WHERE type NOT IN (" . $hiddenTypePlaceholders . ")",
    $hiddenTypes
);
$totalRecords = intval($totalRecordsRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events</title>
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
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: "MagicCards", sans-serif;
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
            font-family: "MagicCards", sans-serif;
            word-spacing: 5px;
            letter-spacing: 1.5px;
            margin-bottom: -2px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
        }

        .tab-button:hover {
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 1));
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-decoration: none;
        }

        .tab-button.active {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-color: rgba(230, 183, 108, 0.5);
            border-bottom: 2px solid rgba(42, 42, 42, 0.95);
            color: #e6b76c;
            box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
        }

        .tab-content {
            display: block;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 8px;
            border-top-left-radius: 0;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .table-container {
            max-height: calc(100vh - 450px) !important;
            margin-top: 20px;
            width: 100%;
            overflow-x: auto;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: small;
        }

        th {
            padding: 12px;
            font-weight: bold;
            text-align: left;
            color: #e6b76c;
            background: rgba(26, 26, 26, 0.6);
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
        }

        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(74, 74, 74, 0.3);
            color: #f8f9fa;
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: top;
            line-height: 1.5;
        }

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

        th:has(#selectAllCheckbox),
        td:has(.event-checkbox) {
            text-align: center !important;
            width: 40px !important;
            padding: 8px !important;
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
            <a class="tab-button active" href="events.php">&#x1F4DD; Events</a>
            <a class="tab-button" href="ai-response.php">&#x1F916; AI Responses</a>
            <a class="tab-button" href="memories.php">&#x1F9E0; Memories</a>
        </div>

        <div id="eventlog-tab" class="tab-content">
            <div style="background: #2a2a2a; border-left: 4px solid #e6b76c; padding: 12px 15px; border-radius: 5px; margin: 15px 0; font-size: 0.9em;">
                <span style="color: #e6b76c; font-weight: bold;">Events:</span>
                <span style="color: #f8f9fa;">Raw log of in-game events used for AI context and timeline tracking.</span>
            </div>

            <?php if (isset($_GET["deleted"])): ?>
                <div style="background: #28a745; color: white; padding: 10px; border-radius: 5px; margin: 10px 0;">
                    Successfully deleted <?= intval($_GET["deleted"]) ?> event(s).
                </div>
            <?php endif; ?>

            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 20px 0;">
                <button id="live-toggle-btn-eventlog" onclick="toggleAutoRefreshEventLog()" class="btn-base <?= $isAutoRefresh ? "btn-secondary" : "btn-primary" ?>" style="padding: 8px 12px; font-size: 0.9em;" title="Toggle live monitoring">
                    <?= $isAutoRefresh ? "Stop Live" : "Monitor Live" ?>
                </button>
                <span id="live-indicator-eventlog" style="margin-left: 10px; color: #28a745; font-weight: bold; font-size: 0.9em; <?= $isAutoRefresh ? "" : "display:none;" ?>">
                    LIVE
                </span>

                <div style="margin-left: auto; display: flex; gap: 5px; flex-wrap: wrap; align-items: center;">
                    <button id="deleteSelectedBtn" onclick="deleteSelectedEvents()" class="btn-base btn-danger" style="padding: 6px 10px; font-size: 0.8em; display: none;">
                        Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                    <button onclick="if(confirm('Are you sure you want to delete the last 20 events?')) window.location.href='events.php?delete_last=20'" class="btn-base btn-danger" style="padding: 6px 10px; font-size: 0.8em;">Delete Latest 20</button>
                    <button onclick="if(confirm('Are you sure you want to delete the last 50 events?')) window.location.href='events.php?delete_last=50'" class="btn-base btn-danger" style="padding: 6px 10px; font-size: 0.8em;">Delete Latest 50</button>
                    <button onclick="if(confirm('Are you sure you want to delete the last 100 events?')) window.location.href='events.php?delete_last=100'" class="btn-base btn-danger" style="padding: 6px 10px; font-size: 0.8em;">Delete Latest 100</button>
                    <button onclick="deleteAllEventsConfirm()" class="btn-base btn-danger" style="padding: 6px 10px; font-size: 0.8em; background-color: #dc2626; font-weight: bold;">Delete ALL</button>
                </div>
            </div>

            <div class="pagination-row" style="margin-bottom: 10px;">
                <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
                <?php if ($page > 1): ?>
                    <a class="btn-base" href="<?= h(eventsUrl($page - 1, $limit, $isAutoRefresh)) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-base" href="<?= h(eventsUrl($page + 1, $limit, $isAutoRefresh)) ?>">Next</a>
                <?php endif; ?>
            </div>

            <div id="eventlog-table-container" class="table-container">
                <table>
                    <thead>
                    <tr class="primary">
                        <th><input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes(this)" style="cursor: pointer; width: 18px; height: 18px;" title="Select/Deselect All"></th>
                        <th>Event</th>
                        <th>Events</th>
                        <th>People Present</th>
                        <th>Location</th>
                        <th>Game Time</th>
                        <th>Time (UTC)</th>
                        <th>TS</th>
                        <th>ROWID</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><input type="checkbox" class="event-checkbox" data-rowid="<?= intval($row["rowid"] ?? 0) ?>" style="cursor: pointer; width: 18px; height: 18px;"></td>
                                <td><?= h($row["type"] ?? "") ?></td>
                                <td><?= h($row["data"] ?? "") ?></td>
                                <td><?= h($row["people"] ?? "") ?></td>
                                <td><?= h($row["location"] ?? "") ?></td>
                                <td><?= h(formatGameTs($row["gamets"] ?? 0)) ?></td>
                                <td><?= h(formatLocalTs($row["localts"] ?? 0)) ?></td>
                                <td><?= h($row["ts"] ?? "") ?></td>
                                <td><?= intval($row["rowid"] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9">No event rows found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-row">
                <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
                <?php if ($page > 1): ?>
                    <a class="btn-base" href="<?= h(eventsUrl($page - 1, $limit, $isAutoRefresh)) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-base" href="<?= h(eventsUrl($page + 1, $limit, $isAutoRefresh)) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
let autoRefreshIntervalEventLog = null;
let isLiveModeEventLog = <?= $isAutoRefresh ? "true" : "false" ?>;
let lastRowIdEventLog = 0;
let totalNewEventsEventLog = 0;
const currentPageEventLog = <?= intval($page) ?>;
const currentLimitEventLog = <?= intval($limit) ?>;

function deleteAllEventsConfirm() {
    const userInput = prompt("THIS WILL DELETE ALL EVENTS IN THE EVENT LOG!\n\nEvents are used for AI context. This action cannot be undone.\n\nTo confirm this dangerous operation, please type exactly: Delete");
    if (userInput === "Delete") {
        window.location.href = "events.php?reset=true";
    } else if (userInput !== null) {
        alert("Operation cancelled. You must type exactly \"Delete\" to confirm.");
    }
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll(".event-checkbox:checked");
    const count = checkboxes.length;
    const deleteBtn = document.getElementById("deleteSelectedBtn");
    const countSpan = document.getElementById("selectedCount");
    if (countSpan) {
        countSpan.textContent = count;
    }
    if (deleteBtn) {
        deleteBtn.style.display = count > 0 ? "inline-block" : "none";
    }
}

function toggleAllCheckboxes(selectAllCheckbox) {
    const checkboxes = document.querySelectorAll(".event-checkbox");
    checkboxes.forEach((cb) => {
        cb.checked = selectAllCheckbox.checked;
    });
    updateSelectedCount();
}

function deleteSelectedEvents() {
    const checkboxes = document.querySelectorAll(".event-checkbox:checked");
    const rowids = Array.from(checkboxes).map((cb) => cb.getAttribute("data-rowid"));

    if (rowids.length === 0) {
        alert("Please select at least one event to delete.");
        return;
    }
    if (!confirm(`Are you sure you want to delete ${rowids.length} selected event(s)?`)) {
        return;
    }

    const form = document.createElement("form");
    form.method = "POST";
    form.action = "events.php";

    const deleteInput = document.createElement("input");
    deleteInput.type = "hidden";
    deleteInput.name = "delete_selected";
    deleteInput.value = "1";
    form.appendChild(deleteInput);

    rowids.forEach((rowid) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "rowids[]";
        input.value = rowid;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

function getLastRowIdEventLog() {
    const table = document.querySelector("#eventlog-table-container table");
    if (!table) {
        return 0;
    }

    const rows = table.querySelectorAll("tr");
    let maxRowId = 0;
    rows.forEach((row) => {
        const checkbox = row.querySelector(".event-checkbox");
        if (!checkbox) {
            return;
        }
        const rowId = parseInt(checkbox.getAttribute("data-rowid"), 10);
        if (!isNaN(rowId) && rowId > maxRowId) {
            maxRowId = rowId;
        }
    });
    return maxRowId;
}

function updateEventTableEventLog() {
    if (!isLiveModeEventLog) {
        return;
    }

    const liveIndicator = document.getElementById("live-indicator-eventlog");
    if (liveIndicator) {
        liveIndicator.style.opacity = "0.5";
    }

    const sinceRowId = lastRowIdEventLog;
    fetch("events.php?ajax=eventlog_updates&since_rowid=" + sinceRowId)
        .then((response) => response.json())
        .then((data) => {
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                const tbody = document.querySelector("#eventlog-table-container table tbody");
                if (!tbody) {
                    return;
                }

                data.data.reverse().forEach((row) => {
                    const newRow = document.createElement("tr");
                    newRow.style.backgroundColor = "#2d5a2d";

                    const values = [
                        "",
                        row["Event"] || "",
                        row["Events"] || "",
                        row["People Present"] || "",
                        row["Location"] || "",
                        row["Game Time"] || "",
                        row["Time (UTC)"] || "",
                        row["TS"] || "",
                        String(row["ROWID"] || "")
                    ];

                    values.forEach((val, idx) => {
                        const td = document.createElement("td");
                        if (idx === 0) {
                            td.innerHTML = '<input type="checkbox" class="event-checkbox" data-rowid="' + String(row["ROWID"] || "") + '" style="cursor: pointer; width: 18px; height: 18px;">';
                        } else {
                            td.textContent = val;
                        }
                        newRow.appendChild(td);
                    });

                    if (tbody.firstChild) {
                        tbody.insertBefore(newRow, tbody.firstChild);
                    } else {
                        tbody.appendChild(newRow);
                    }

                    const rowIdNum = parseInt(String(row["ROWID"] || "0"), 10);
                    if (!isNaN(rowIdNum) && rowIdNum > lastRowIdEventLog) {
                        lastRowIdEventLog = rowIdNum;
                    }

                    setTimeout(() => {
                        newRow.style.transition = "background-color 1s";
                        newRow.style.backgroundColor = "";
                    }, 3000);
                });

                totalNewEventsEventLog += Number(data.new_count || 0);
            }
        })
        .catch(() => {
            // Ignore transient polling errors.
        })
        .finally(() => {
            if (liveIndicator) {
                liveIndicator.style.opacity = "1";
            }
        });
}

function toggleAutoRefreshEventLog() {
    isLiveModeEventLog = !isLiveModeEventLog;
    const btn = document.getElementById("live-toggle-btn-eventlog");
    const indicator = document.getElementById("live-indicator-eventlog");

    if (isLiveModeEventLog) {
        if (currentPageEventLog !== 1) {
            window.location.href = "events.php?page=1&limit=" + String(currentLimitEventLog) + "&autorefresh=true";
            return;
        }
        btn.textContent = "Stop Live";
        btn.className = "btn-base btn-secondary";
        btn.style.padding = "8px 12px";
        btn.style.fontSize = "0.9em";
        if (indicator) {
            indicator.style.display = "inline";
        }

        lastRowIdEventLog = getLastRowIdEventLog();
        totalNewEventsEventLog = 0;
        autoRefreshIntervalEventLog = setInterval(updateEventTableEventLog, 5000);
    } else {
        btn.textContent = "Monitor Live";
        btn.className = "btn-base btn-primary";
        btn.style.padding = "8px 12px";
        btn.style.fontSize = "0.9em";
        if (indicator) {
            indicator.style.display = "none";
        }
        if (autoRefreshIntervalEventLog) {
            clearInterval(autoRefreshIntervalEventLog);
            autoRefreshIntervalEventLog = null;
        }
    }
}

document.addEventListener("DOMContentLoaded", function () {
    document.addEventListener("change", function (e) {
        if (e.target.classList.contains("event-checkbox")) {
            updateSelectedCount();

            const selectAllCheckbox = document.getElementById("selectAllCheckbox");
            if (selectAllCheckbox) {
                const allCheckboxes = document.querySelectorAll(".event-checkbox");
                const checkedCheckboxes = document.querySelectorAll(".event-checkbox:checked");
                selectAllCheckbox.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkedCheckboxes.length;
            }
        }
    });

    updateSelectedCount();

    if (isLiveModeEventLog) {
        lastRowIdEventLog = getLastRowIdEventLog();
        autoRefreshIntervalEventLog = setInterval(updateEventTableEventLog, 5000);
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

