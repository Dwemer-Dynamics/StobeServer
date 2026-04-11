<?php
/**
 * StobeServer Memories.
 * Herika-style memory summary page adapted for Stobe schema.
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

function getGeneralSetting(sql $db, string $id, string $default = ""): string
{
    $row = safeFetchOne($db, "SELECT value FROM general_settings WHERE id = $1 LIMIT 1", [$id]);
    if (!is_array($row)) {
        return $default;
    }
    $value = $row["value"] ?? null;
    if ($value === null || $value === "") {
        return $default;
    }
    return trim((string)$value);
}

function getGeneralSettingBool(sql $db, string $id, bool $default = false): bool
{
    $raw = strtolower(getGeneralSetting($db, $id, $default ? "true" : "false"));
    return in_array($raw, ["1", "true", "yes", "on"], true);
}

function formatUtcDate(mixed $value): string
{
    $raw = trim((string)($value ?? ""));
    if ($raw === "") {
        return "";
    }
    try {
        $dt = new DateTime($raw);
        $dt->setTimezone(new DateTimeZone("UTC"));
        return $dt->format("d-m-Y H:i:s");
    } catch (Throwable $exception) {
        return $raw;
    }
}

function formatPeriod(mixed $start, mixed $end): string
{
    $startFmt = formatUtcDate($start);
    $endFmt = formatUtcDate($end);
    if ($startFmt === "" && $endFmt === "") {
        return "";
    }
    return ($startFmt !== "" ? $startFmt : "?") . " -> " . ($endFmt !== "" ? $endFmt : "?");
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

function formatScopeLabel(mixed $value): string
{
    $raw = trim((string)($value ?? ""));
    if ($raw === "") {
        return "global";
    }
    return $raw;
}

function memoriesUrl(int $page, int $limit): string
{
    return "memories.php?page=" . max(1, $page) . "&limit=" . max(10, $limit);
}

$db = $GLOBALS["db"];
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 100;
$limit = max(10, min(500, $limit));
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

if (isset($_GET["delete_memory"])) {
    $memoryId = intval($_GET["delete_memory"]);
    if ($memoryId > 0) {
        safeExec($db, "DELETE FROM memory_summary WHERE id = $1", [$memoryId]);
        header("Location: " . memoriesUrl($page, $limit) . "&deleted=1");
        exit;
    }
}

if (in_array(strtolower(trim((string)($_GET["reset"] ?? ""))), ["true", "1"], true)) {
    safeExec($db, "DELETE FROM memory_summary");
    safeExec($db, "DELETE FROM conf_opts WHERE id LIKE 'MEMORY_CURSOR_ID_%'");
    header("Location: " . memoriesUrl(1, $limit) . "&reset_done=1");
    exit;
}

if (isset($_POST["save_memory_edit"])) {
    $memoryId = intval($_POST["id"] ?? 0);
    $summary = trim((string)($_POST["summary"] ?? ""));
    if ($memoryId > 0 && $summary !== "") {
        safeExec($db, "UPDATE memory_summary SET summary = $1 WHERE id = $2", [$summary, $memoryId]);
        header("Location: " . memoriesUrl($page, $limit) . "&updated=1");
        exit;
    }
}

$scopeColumnAvailable = false;
if (function_exists("stobeRegularMemorySummaryScopeColumnAvailable")) {
    $scopeColumnAvailable = stobeRegularMemorySummaryScopeColumnAvailable();
}

if (isset($_POST["run_memory_sync"])) {
    $gametsRow = safeFetchOne($db, "SELECT COALESCE(MAX(gamets), 0) AS v FROM eventlog");
    $syncGamets = is_array($gametsRow) ? intval($gametsRow["v"] ?? 0) : 0;
    if ($syncGamets <= 0 && function_exists("getConfOpt")) {
        $syncGamets = intval(getConfOpt("MEMORY_LAST_RUN_GAMETS", "0"));
    }

    $sync = ["passes" => 0, "global" => 0, "individual" => 0];
    if (function_exists("stobeRunRegularMemorySyncNow")) {
        $sync = stobeRunRegularMemorySyncNow($syncGamets, 24);
    }

    $redirect = memoriesUrl($page, $limit)
        . "&synced=1"
        . "&sync_passes=" . intval($sync["passes"] ?? 0)
        . "&sync_global=" . intval($sync["global"] ?? 0)
        . "&sync_individual=" . intval($sync["individual"] ?? 0);
    header("Location: " . $redirect);
    exit;
}

$memoryEnabled = getGeneralSettingBool($db, "MEMORY_ENABLED", true);
$memorySummaryInterval = getGeneralSetting($db, "MEMORY_AUTO_CREATE_SUMMARY_INTERVAL", "6");
$individualSummaryThreshold = getGeneralSetting($db, "INDIVIDUAL_MEMORY_SUMMARY_THRESHOLD", "3");
$txtaiUrl = function_exists("getMemoryTxtaiUrl") ? getMemoryTxtaiUrl() : "http://127.0.0.1:8082";
$useText2Vec = function_exists("getMemoryUseText2Vec") ? getMemoryUseText2Vec() : true;

$scopeSelect = $scopeColumnAvailable
    ? "COALESCE(NULLIF(BTRIM(scope), ''), 'global') AS scope"
    : "'global' AS scope";


$rows = safeFetchAll(
    $db,
    "SELECT id, people, {$scopeSelect}, summary, period_start, period_end, created_at
     FROM memory_summary
     ORDER BY created_at DESC, id DESC
     LIMIT $1 OFFSET $2",
    [$limit, $offset]
);

$totalRow = safeFetchOne($db, "SELECT COUNT(*) AS total FROM memory_summary");
$totalRecords = intval($totalRow["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memories</title>
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

        .edit-form {
            display: none;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            background-color: #2a2a2a;
        }

        .edit-textarea {
            width: 100%;
            min-height: 120px;
            margin-bottom: 8px;
            background-color: #333;
            color: #fff;
            border: 1px solid #444;
            padding: 8px;
            border-radius: 4px;
        }

        .summary-section {
            margin-bottom: 8px;
            padding: 5px;
            border-bottom: 1px solid #444;
        }

        .summary-label {
            font-weight: bold;
            margin-right: 5px;
            color: #fff;
        }

        .summary-content {
            color: #fff;
            white-space: pre-wrap;
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
            <a class="tab-button active" href="memories.php">&#x1F9E0; Memories</a>
        </div>

        <div id="memory-tab" class="tab-content">
            <?php if (isset($_GET["updated"])): ?>
                <div style="background: #28a745; color: white; padding: 10px; border-radius: 5px; margin: 10px 0;">Memory summary updated successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET["deleted"])): ?>
                <div style="background: #dc3545; color: white; padding: 10px; border-radius: 5px; margin: 10px 0;">Memory summary deleted successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET["reset_done"]) || intval($_GET["reset"] ?? 0) === 1): ?>
                <div style="background: #dc3545; color: white; padding: 10px; border-radius: 5px; margin: 10px 0;">All memory summaries deleted.</div>
            <?php endif; ?>
            <?php if (isset($_GET["synced"])): ?>
                <?php
                $syncPasses = intval($_GET["sync_passes"] ?? 0);
                $syncGlobal = intval($_GET["sync_global"] ?? 0);
                $syncIndividual = intval($_GET["sync_individual"] ?? 0);
                ?>
                <div style="background: #176529; color: white; padding: 10px; border-radius: 5px; margin: 10px 0;">
                    Memory sync complete. Passes: <?= $syncPasses ?>, Global summaries: <?= $syncGlobal ?>, Individual summaries: <?= $syncIndividual ?>.
                </div>
            <?php endif; ?>

            <div style="background: #2a2a2a; border-left: 4px solid #e6b76c; padding: 12px 15px; border-radius: 5px; margin: 15px 0; font-size: 0.9em;">
                <span style="color: #e6b76c; font-weight: bold;">Memories:</span>
                <span style="color: #f8f9fa;">Complete log of generated memory summaries with timeline ranges and grouped participants. Use this to debug memory continuity and long-term context quality.</span>
            </div>

            <div style="background: #1a1a1a; border: 1px solid #3a3a3a; border-radius: 8px; padding: 20px; margin: 15px 0;">
                <div style="margin-bottom: 15px;">
                    <h3 style="margin: 0; color: #e6b76c; word-spacing: 5px;">Memory System Configuration</h3>
                </div>

                <?php
                $statusIcon = static function (bool $enabled): string {
                    return $enabled
                        ? "<span style='color: #4caf50;'>Enabled</span>"
                        : "<span style='color: #f44336;'>Disabled</span>";
                };
                ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <div style="background: #2a2a2a; padding: 15px; border-radius: 5px; border: 1px solid #3a3a3a;">
                        <div style="font-weight: bold; margin-bottom: 8px; color: #e6b76c; font-size: 14px;">Memory System</div>
                        <div style="font-size: 14px;"><?= $statusIcon($memoryEnabled) ?></div>
                    </div>

                    <div style="background: #2a2a2a; padding: 15px; border-radius: 5px; border: 1px solid #3a3a3a;">
                        <div style="font-weight: bold; margin-bottom: 8px; color: #e6b76c; font-size: 14px;">TXT2VEC (Embeddings)</div>
                        <div style="font-size: 14px;"><?= $statusIcon($useText2Vec) ?></div>
                        <div style="font-size: 12px; color: #aaa; margin-top: 4px;">URL: <?= h($txtaiUrl) ?></div>
                    </div>

                    <div style="background: #2a2a2a; padding: 15px; border-radius: 5px; border: 1px solid #3a3a3a;">
                        <div style="font-weight: bold; margin-bottom: 8px; color: #e6b76c; font-size: 14px;">Summary Settings</div>
                        <div style="font-size: 12px; color: #f8f9fa;">Auto Summary Interval: <?= h($memorySummaryInterval) ?> (in-game hours)</div>
                        <div style="font-size: 12px; color: #f8f9fa; margin-top: 4px;">Individual Summary Threshold: <?= h($individualSummaryThreshold) ?></div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 15px 0; flex-wrap: wrap;">
                <form method="post" action="<?= h(memoriesUrl($page, $limit)) ?>" style="margin: 0;">
                    <input type="hidden" name="run_memory_sync" value="1">
                    <button type="submit" class="btn-base action-button add-new" style="font-weight: bold;">Sync Memory Summaries Now</button>
                </form>
                <button type="button" onclick="deleteAllMemoriesConfirm()" class="btn-base btn-danger" style="background-color: #dc2626; font-weight: bold;">Delete All Memory Summaries</button>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                    <tr>
                        <th style="width:6%">ID</th>
                        <th style="width:14%">Scope</th>
                        <th style="width:14%">People</th>
                        <th style="width:16%">Period</th>
                        <th style="width:40%">Summary</th>
                        <th style="width:10%">Created (UTC)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $memoryId = intval($row["id"] ?? 0);
                            $displayId = "display_" . $memoryId;
                            $editId = "edit_" . $memoryId;
                            ?>
                            <tr>
                                <td><?= $memoryId ?></td>
                                <td><?= h(formatScopeLabel($row["scope"] ?? "global")) ?></td>
                                <td><?= h(formatPeopleLabel($row["people"] ?? "")) ?></td>
                                <td><?= h(formatPeriod($row["period_start"] ?? "", $row["period_end"] ?? "")) ?></td>
                                <td>
                                    <div id="<?= h($displayId) ?>">
                                        <div class="summary-section">
                                            <span class="summary-content"><?= nl2br(h($row["summary"] ?? "")) ?></span>
                                        </div>
                                        <div style="margin-top: 10px;">
                                            <button class="btn-base action-button edit" onclick="toggleEdit(<?= $memoryId ?>)">Edit</button>
                                            <button class="btn-base btn-danger" onclick="deleteOneMemory(<?= $memoryId ?>)">Delete</button>
                                        </div>
                                    </div>
                                    <form id="<?= h($editId) ?>" class="edit-form" method="post" action="<?= h(memoriesUrl($page, $limit)) ?>">
                                        <input type="hidden" name="save_memory_edit" value="1">
                                        <input type="hidden" name="id" value="<?= $memoryId ?>">
                                        <textarea name="summary" class="edit-textarea"><?= h($row["summary"] ?? "") ?></textarea>
                                        <div style="margin-top: 8px;">
                                            <button type="submit" class="btn-base action-button add-new">Save</button>
                                            <button type="button" class="btn-base btn-cancel" onclick="cancelEdit(<?= $memoryId ?>)">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                                <td><?= h(formatUtcDate($row["created_at"] ?? "")) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No memory summary rows found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-row">
                <span class="info-message" style="padding:0">Page <?= intval($page) ?> / <?= intval($totalPages) ?> (<?= intval($totalRecords) ?> rows)</span>
                <?php if ($page > 1): ?>
                    <a class="btn-base" href="<?= h(memoriesUrl($page - 1, $limit)) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-base" href="<?= h(memoriesUrl($page + 1, $limit)) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
function toggleEdit(memoryId) {
    const displayDiv = document.getElementById("display_" + String(memoryId));
    const editForm = document.getElementById("edit_" + String(memoryId));
    if (!displayDiv || !editForm) {
        return;
    }
    displayDiv.style.display = "none";
    editForm.style.display = "block";
}

function cancelEdit(memoryId) {
    const displayDiv = document.getElementById("display_" + String(memoryId));
    const editForm = document.getElementById("edit_" + String(memoryId));
    if (!displayDiv || !editForm) {
        return;
    }
    displayDiv.style.display = "block";
    editForm.style.display = "none";
}

function deleteOneMemory(memoryId) {
    if (confirm("Are you sure you want to delete this memory summary?")) {
        window.location.href = "<?= h(memoriesUrl($page, $limit)) ?>&delete_memory=" + String(memoryId);
    }
}

function deleteAllMemoriesConfirm() {
    const userInput = prompt("THIS WILL DELETE ALL SUMMARIZED MEMORIES!\n\nThis action cannot be undone.\n\nTo confirm this operation, type exactly: Delete");
    const normalized = (userInput === null) ? null : String(userInput).trim().toLowerCase();
    if (normalized === "delete") {
        window.location.href = "<?= h(memoriesUrl(1, $limit)) ?>&reset=1";
    } else if (userInput !== null) {
        alert("Operation cancelled. You must type \"Delete\" to confirm.");
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

