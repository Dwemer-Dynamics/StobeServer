<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}
$db = $GLOBALS["db"];

if (!defined("ACTION_EDITOR_MAX_ROWS")) {
    define("ACTION_EDITOR_MAX_ROWS", 2000);
}

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8");
}

function action_editor_trim(mixed $value): string
{
    return trim(strval($value));
}

function action_editor_to_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $text = strtolower(trim(strval($value)));
    return in_array($text, ["1", "true", "yes", "on", "t"], true);
}

/**
 * Read a request field as a plain string; arrays and missing keys become "".
 */
function action_editor_request_string(array $bag, string $key): string
{
    return is_string($bag[$key] ?? null) ? trim($bag[$key]) : "";
}

function action_editor_build_url(array $params = [], bool $embed = false, string $anchor = ""): string
{
    $base = basename($_SERVER["PHP_SELF"] ?? "action_editor.php");
    if ($embed) {
        $params["embed"] = "1";
    }
    $qs = http_build_query($params);
    $url = $base . ($qs !== "" ? ("?" . $qs) : "");
    if ($anchor !== "") {
        $url .= "#" . ltrim($anchor, "#");
    }
    return $url;
}

function action_editor_upsert_custom_toggle(sql $db, string $command, bool $enabled): bool
{
    if ($command === "") {
        return false;
    }
    $base = $db->fetchOne(
        "SELECT command, action_name, description
         FROM combined_core_action
         WHERE UPPER(command) = UPPER($1)
         LIMIT 1",
        [$command]
    );
    if (!$base) {
        return false;
    }

    $result = $db->exec(
        "INSERT INTO core_action_custom (command, action_name, description, is_activated)
         VALUES ($1, $2, $3, $4)
         ON CONFLICT (command)
         DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            is_activated = EXCLUDED.is_activated,
            updated_at = NOW()",
        [
            strval($base["command"] ?? $command),
            strval($base["action_name"] ?? ""),
            strval($base["description"] ?? ""),
            $enabled,
        ]
    );
    return $result !== false;
}

/**
 * Commands the editor is allowed to touch: the same catalog, and the same
 * 2000-row cap, that the table below renders from. Keyed by upper-case command.
 */
function action_editor_catalog_map(sql $db): array
{
    $rows = $db->fetchAll(
        "SELECT command
         FROM combined_core_action
         ORDER BY LOWER(action_name), LOWER(command)
         LIMIT " . ACTION_EDITOR_MAX_ROWS
    );
    $catalog = [];
    foreach ($rows as $row) {
        $command = action_editor_trim($row["command"] ?? "");
        if ($command === "") {
            continue;
        }
        $catalog[strtoupper($command)] = $command;
    }
    return $catalog;
}

/**
 * Validate one staged bulk payload.
 * Returns a list of [command => canonical, enabled => bool] on success,
 * or an error code string on any structural, catalog, or value problem.
 */
function action_editor_parse_bulk_payload(string $raw, array $catalog): array|string
{
    if (action_editor_trim($raw) === "") {
        return "empty";
    }
    $decoded = json_decode($raw, true, 6);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return "payload";
    }
    if (count($decoded) === 0) {
        return "empty";
    }
    if (count($decoded) > ACTION_EDITOR_MAX_ROWS) {
        return "limit";
    }

    $changes = [];
    $seen = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry) || !array_key_exists("command", $entry) || !array_key_exists("enabled", $entry)) {
            return "payload";
        }
        if (!is_string($entry["command"])) {
            return "payload";
        }
        $command = action_editor_trim($entry["command"]);
        if ($command === "") {
            return "payload";
        }
        if (!is_bool($entry["enabled"])) {
            return "value";
        }
        $key = strtoupper($command);
        if (!isset($catalog[$key])) {
            return "command";
        }
        if (isset($seen[$key])) {
            return "duplicate";
        }
        $seen[$key] = true;
        $changes[] = ["command" => $catalog[$key], "enabled" => $entry["enabled"]];
    }
    return $changes;
}

/**
 * Apply every staged toggle inside one transaction: all rows or none.
 */
function action_editor_apply_bulk(sql $db, array $changes): bool
{
    if (count($changes) === 0) {
        return false;
    }
    if ($db->exec("BEGIN") === false) {
        return false;
    }
    foreach ($changes as $change) {
        if (!action_editor_upsert_custom_toggle($db, strval($change["command"]), (bool)$change["enabled"])) {
            $db->exec("ROLLBACK");
            return false;
        }
    }
    if ($db->exec("COMMIT") === false) {
        $db->exec("ROLLBACK");
        return false;
    }
    return true;
}

$isEmbed = action_editor_request_string($_GET, "embed") === "1"
    || action_editor_request_string($_POST, "embed") === "1";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $redirectSearch = action_editor_request_string($_POST, "search");
    if ($redirectSearch === "") {
        $redirectSearch = action_editor_request_string($_GET, "search");
    }
    $redirectState = strtolower(action_editor_request_string($_POST, "state"));
    if ($redirectState === "") {
        $redirectState = strtolower(action_editor_request_string($_GET, "state"));
    }
    if (!in_array($redirectState, ["all", "enabled", "disabled"], true)) {
        $redirectState = "all";
    }
    $redirectParams = [];
    if ($redirectSearch !== "") {
        $redirectParams["search"] = $redirectSearch;
    }
    if ($redirectState !== "all") {
        $redirectParams["state"] = $redirectState;
    }

    if (action_editor_request_string($_POST, "action") !== "save_action_toggles") {
        $redirectParams["err"] = "payload";
    } else {
        $rawChanges = action_editor_request_string($_POST, "changes");
        $parsed = action_editor_parse_bulk_payload($rawChanges, action_editor_catalog_map($db));
        if (is_string($parsed)) {
            $redirectParams["err"] = $parsed;
        } elseif (!action_editor_apply_bulk($db, $parsed)) {
            $redirectParams["err"] = "save";
        } else {
            $redirectParams["saved"] = count($parsed);
        }
    }

    header("Location: " . action_editor_build_url($redirectParams, $isEmbed, "entries"), true, 303);
    exit;
}

$message = "";
$messageType = "ok";
if (isset($_GET["saved"])) {
    $savedCount = max(0, intval(action_editor_request_string($_GET, "saved")));
    $message = sprintf("Saved %d action %s.", $savedCount, $savedCount === 1 ? "change" : "changes");
} elseif (isset($_GET["err"])) {
    $messageType = "err";
    $message = match (action_editor_request_string($_GET, "err")) {
        "empty" => "No staged changes to save.",
        "limit" => sprintf("Too many staged changes. Save at most %d actions at once.", ACTION_EDITOR_MAX_ROWS),
        "command" => "One or more staged actions are not in the action catalog. Nothing was saved.",
        "value" => "Staged toggle values must be true or false. Nothing was saved.",
        "duplicate" => "The same action was staged more than once. Nothing was saved.",
        "save" => "Could not save action toggles. All changes were rolled back.",
        default => "Invalid save request. Nothing was saved.",
    };
}

$search = action_editor_request_string($_GET, "search");
$state = strtolower(action_editor_request_string($_GET, "state"));
if ($state === "") {
    $state = "all";
}
if (!in_array($state, ["all", "enabled", "disabled"], true)) {
    $state = "all";
}

$whereParts = [];
$params = [];
if ($search !== "") {
    $params[] = "%" . $search . "%";
    $idx = count($params);
    $whereParts[] = "(LOWER(COALESCE(v.command, '')) LIKE LOWER($" . $idx . ")
                 OR LOWER(COALESCE(v.action_name, '')) LIKE LOWER($" . $idx . ")
                 OR LOWER(COALESCE(v.description, '')) LIKE LOWER($" . $idx . "))";
}
if ($state === "enabled") {
    $whereParts[] = "v.is_activated = TRUE";
} elseif ($state === "disabled") {
    $whereParts[] = "v.is_activated = FALSE";
}
$whereSql = count($whereParts) > 0 ? ("WHERE " . implode(" AND ", $whereParts)) : "";

$rows = $db->fetchAll(
    "SELECT
        v.command,
        v.action_name,
        v.description,
        v.is_activated,
        EXISTS (
            SELECT 1 FROM core_action_custom c
            WHERE UPPER(c.command) = UPPER(v.command)
        ) AS is_custom
     FROM combined_core_action v
     $whereSql
     ORDER BY LOWER(v.action_name), LOWER(v.command)
     LIMIT " . ACTION_EDITOR_MAX_ROWS,
    $params
);

$countAll = intval($db->fetchOne("SELECT COUNT(*) AS c FROM combined_core_action")["c"] ?? 0);
$countEnabled = intval($db->fetchOne("SELECT COUNT(*) AS c FROM combined_core_action WHERE is_activated = TRUE")["c"] ?? 0);
$countDisabled = max(0, $countAll - $countEnabled);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Editor</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: 10px;
            padding-bottom: 24px;
            padding-left: 5px;
            padding-right: 5px;
            width: 100%;
            margin: 0;
        }
        /* Page header is the shared compact inline row (.stobe-page-head in main.css). */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 12px;
        }
        /* Summary cards sit directly under the header, so keep them low. */
        .content-grid > .content-section {
            padding: 12px 16px;
            min-width: 0;
        }
        .content-grid > .content-section h2 {
            margin-bottom: 8px;
            font-size: 1.2em;
        }
        .content-section {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .content-section:hover {
            border-color: #4a4a4a;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
        }
        .content-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            margin-top: 0;
            font-size: 1.4em;
        }
        .full-width-section {
            grid-column: 1 / -1;
        }
        .full-width-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            font-size: 1.6em;
            text-align: center;
        }
        .stat-line {
            margin: 8px 0;
            color: #d0d6df;
        }
        .stat-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            margin-left: 8px;
            border: 1px solid #4a4a4a;
        }
        .stat-pill.enabled {
            color: #6dd19c;
            border-color: rgba(109, 209, 156, 0.45);
            background: rgba(25, 77, 50, 0.3);
        }
        .stat-pill.disabled {
            color: #ffb3b3;
            border-color: rgba(220, 110, 110, 0.45);
            background: rgba(96, 32, 32, 0.32);
        }
        .action-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .search-container {
            display: flex;
            gap: 10px;
            min-width: 360px;
            flex-wrap: wrap;
        }
        .search-container input[type="text"], .search-container select {
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #555555;
            background-color: #4a4a4a;
            color: #f8f9fa;
        }
        /* Staged-save bar: unsaved count on the left, one Save All on the right. */
        .bulk-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 6px;
            padding: 10px 14px;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(46, 46, 46, 0.9), rgba(36, 36, 36, 0.95));
        }
        .bulk-status {
            color: #d0d6df;
            font-size: 14px;
            min-height: 20px;
        }
        .bulk-status.pending {
            color: #e6b76c;
            font-weight: 600;
        }
        .bulk-status.limit {
            color: #ffb3b3;
            font-weight: 600;
        }
        .bulk-note {
            margin: 0 0 12px;
            color: #9aa6b8;
            font-size: 12px;
            line-height: 1.4;
        }
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-top: 20px;
            max-height: calc(100vh - 320px);
            overflow-y: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        }
        .table-container th {
            position: sticky;
            top: 0;
            background: linear-gradient(135deg, rgba(58, 58, 58, 0.95), rgba(48, 48, 48, 0.95));
            color: #e6b76c;
            padding: 12px 10px;
            text-align: left;
            font-family: "MagicCards", serif;
            letter-spacing: 1px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
            z-index: 10;
        }
        .table-container td {
            padding: 10px;
            border-bottom: 1px solid #3a3a3a;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .table-container tr:hover {
            background: rgba(58, 58, 58, 0.5);
        }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid #4a4a4a;
        }
        .status-pill.custom {
            color: #6dd19c;
            border-color: rgba(109, 209, 156, 0.45);
            background: rgba(25, 77, 50, 0.3);
        }
        .status-pill.base {
            color: #c9d3e5;
            border-color: rgba(138, 155, 182, 0.35);
            background: rgba(55, 66, 84, 0.28);
        }
        .toggle-cell {
            white-space: nowrap;
            width: 1%;
        }
        .action-toggle {
            width: 18px;
            height: 18px;
            accent-color: #e6b76c;
            cursor: pointer;
            vertical-align: middle;
        }
        .action-toggle:focus-visible {
            outline: 2px solid #e6b76c;
            outline-offset: 2px;
        }
        /* Row-level dirty marker: gold rail plus a decorative dot. */
        .table-container tr.row-dirty,
        .table-container tr.row-dirty:hover {
            background: rgba(230, 183, 108, 0.14);
        }
        .table-container tr.row-dirty td {
            border-bottom-color: rgba(230, 183, 108, 0.35);
        }
        .table-container tr.row-dirty td.toggle-cell {
            box-shadow: inset 3px 0 0 #e6b76c;
        }
        .dirty-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-left: 8px;
            border-radius: 50%;
            background: #e6b76c;
            vertical-align: middle;
            visibility: hidden;
        }
        .table-container tr.row-dirty .dirty-dot {
            visibility: visible;
        }
        .toast-notification {
            position: fixed;
            top: 24px;
            right: 24px;
            min-width: 280px;
            max-width: 560px;
            background: rgba(19, 24, 31, 0.96);
            color: #e9efff;
            border: 1px solid rgba(138, 155, 182, 0.38);
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            transform: translateY(-6px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 9999;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        @media (max-width: 1024px) {
            main {
                padding-left: 4%;
                padding-right: 4%;
            }
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .search-container {
                min-width: 280px;
            }
        }
        .toast-notification.err {
            border-color: rgba(220, 110, 110, 0.55);
            color: #ffd9d9;
        }
        @media (max-width: 640px) {
            .search-container {
                min-width: 0;
                width: 100%;
            }
            .search-container input[type="text"],
            .search-container select {
                width: 100%;
            }
            .bulk-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .bulk-bar .btn-save {
                width: 100%;
                margin: 0;
            }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification<?= $messageType === "err" ? " err" : "" ?>"><span class="message"></span></div>

    <div class="page-header stobe-page-head">
        <h1 class="api-title stobe-page-head-title">Action Editor</h1>
        <p class="page-subtitle stobe-page-head-note">Configure available actions exposed to AI prompting and execution</p>
    </div>

    <div class="content-grid">
        <div class="content-section">
            <h2>Action Summary</h2>
            <div class="stat-line">Total Actions <span class="stat-pill"><?= h($countAll) ?></span></div>
            <div class="stat-line">Enabled <span class="stat-pill enabled"><?= h($countEnabled) ?></span></div>
            <div class="stat-line">Disabled <span class="stat-pill disabled"><?= h($countDisabled) ?></span></div>
        </div>
        <div class="content-section">
            <h2>How It Works</h2>
            <p style="margin:0; color:#d0d6df; line-height:1.45;">
                Tick or untick the Enabled boxes, then press Save All to write every staged change
                into <code>core_action_custom</code> in a single transaction.
                Built-in defaults in <code>core_action</code> remain untouched.
            </p>
        </div>

        <div class="content-section full-width-section">
            <h2 id="entries">Actions</h2>

            <form method="get" action="" id="filterForm">
                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="action-container">
                    <div class="search-container">
                        <input type="text" name="search" id="searchBox" value="<?= h($search) ?>" placeholder="Search command, action name, description">
                        <select name="state" id="stateFilter">
                            <option value="all" <?= $state === "all" ? "selected" : "" ?>>All states</option>
                            <option value="enabled" <?= $state === "enabled" ? "selected" : "" ?>>Enabled only</option>
                            <option value="disabled" <?= $state === "disabled" ? "selected" : "" ?>>Disabled only</option>
                        </select>
                        <button type="submit" class="action-button edit">Search</button>
                        <a href="<?= h(action_editor_build_url([], $isEmbed, "entries")) ?>" class="action-button" id="clearFilters">Clear</a>
                    </div>
                </div>
            </form>

            <form method="post" action="" id="bulkToggleForm">
                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="save_action_toggles">
                <input type="hidden" name="search" value="<?= h($search) ?>">
                <input type="hidden" name="state" value="<?= h($state) ?>">
                <input type="hidden" name="changes" id="stagedChanges" value="">

                <div class="bulk-bar">
                    <div class="bulk-status" id="dirtyStatus" role="status" aria-live="polite">No unsaved changes</div>
                    <button type="submit" class="btn-save" id="saveAllBtn" disabled>Save All</button>
                </div>
                <p class="bulk-note">
                    Toggles are staged in the browser and applied together, up to
                    <?= h(ACTION_EDITOR_MAX_ROWS) ?> actions per save.
                </p>
                <noscript>
                    <p class="bulk-note" style="color:#ffb3b3;">
                        Staged saving needs JavaScript. Enable JavaScript to change action toggles.
                    </p>
                </noscript>

                <div class="table-container">
                    <table>
                        <tr>
                            <th scope="col">Enabled</th>
                            <th scope="col">Command</th>
                            <th scope="col">Name</th>
                            <th scope="col">Description</th>
                            <th scope="col">Source</th>
                        </tr>
                        <?php if (count($rows) === 0): ?>
                            <tr><td colspan="5">No actions found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $command = strval($row["command"] ?? "");
                            $actionName = strval($row["action_name"] ?? "");
                            $enabled = action_editor_to_bool($row["is_activated"] ?? false);
                            $isCustom = strval($row["is_custom"] ?? "f") === "t";
                            $toggleLabel = "Enable " . ($actionName !== "" ? ($actionName . " (" . $command . ")") : $command);
                            ?>
                            <tr>
                                <td class="toggle-cell">
                                    <input type="checkbox"
                                           class="action-toggle"
                                           data-command="<?= h($command) ?>"
                                           data-initial="<?= $enabled ? "1" : "0" ?>"
                                           aria-label="<?= h($toggleLabel) ?>"
                                           <?= $enabled ? "checked" : "" ?>>
                                    <span class="dirty-dot" aria-hidden="true"></span>
                                </td>
                                <td><code><?= h($command) ?></code></td>
                                <td><?= h($actionName) ?></td>
                                <td style="max-width: 520px;"><?= nl2br(h($row["description"] ?? "")) ?></td>
                                <td><span class="status-pill <?= $isCustom ? "custom" : "base" ?>"><?= $isCustom ? "custom" : "base" ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
function showToast(message, duration = 5000) {
    const toast = document.getElementById("toast");
    const messageSpan = toast.querySelector(".message");
    messageSpan.textContent = message;
    toast.classList.add("show");
    setTimeout(() => {
        toast.classList.remove("show");
    }, duration);
}
<?php if ($message !== ""): ?>
document.addEventListener("DOMContentLoaded", function() {
    showToast(<?= json_encode($message) ?>);
});
<?php endif; ?>

(function() {
    const MAX_ROWS = <?= json_encode(ACTION_EDITOR_MAX_ROWS) ?>;
    const form = document.getElementById("bulkToggleForm");
    const stagedField = document.getElementById("stagedChanges");
    const saveButton = document.getElementById("saveAllBtn");
    const statusEl = document.getElementById("dirtyStatus");
    if (!form || !stagedField || !saveButton || !statusEl) {
        return;
    }
    const toggles = Array.prototype.slice.call(form.querySelectorAll(".action-toggle"));
    let submitting = false;
    let discardConfirmed = false;

    function isDirty(toggle) {
        return toggle.checked !== (toggle.dataset.initial === "1");
    }

    function dirtyToggles() {
        return toggles.filter(isDirty);
    }

    function refresh() {
        toggles.forEach(function(toggle) {
            const row = toggle.closest("tr");
            if (row) {
                row.classList.toggle("row-dirty", isDirty(toggle));
            }
        });
        const pending = dirtyToggles().length;
        const overLimit = pending > MAX_ROWS;
        statusEl.classList.toggle("pending", pending > 0 && !overLimit);
        statusEl.classList.toggle("limit", overLimit);
        if (overLimit) {
            statusEl.textContent = pending + " unsaved changes exceed the " + MAX_ROWS + " action save limit";
        } else if (pending === 0) {
            statusEl.textContent = "No unsaved changes";
        } else if (pending === 1) {
            statusEl.textContent = "1 unsaved change";
        } else {
            statusEl.textContent = pending + " unsaved changes";
        }
        saveButton.disabled = pending === 0 || overLimit;
        saveButton.textContent = pending === 0 ? "Save All" : "Save All (" + pending + ")";
    }

    function confirmDiscard() {
        if (submitting || dirtyToggles().length === 0) {
            return true;
        }
        if (window.confirm("You have unsaved action changes. Leave this view and discard them?")) {
            discardConfirmed = true;
            return true;
        }
        return false;
    }

    toggles.forEach(function(toggle) {
        toggle.addEventListener("change", refresh);
    });

    form.addEventListener("submit", function(event) {
        const pending = dirtyToggles();
        if (pending.length === 0 || pending.length > MAX_ROWS) {
            event.preventDefault();
            refresh();
            return;
        }
        stagedField.value = JSON.stringify(pending.map(function(toggle) {
            return { command: toggle.dataset.command, enabled: toggle.checked };
        }));
        submitting = true;
        saveButton.disabled = true;
    });

    // Discard protection for other submits (search), links (navbar, Clear), reloads.
    document.querySelectorAll("form").forEach(function(other) {
        if (other === form) {
            return;
        }
        other.addEventListener("submit", function(event) {
            if (!confirmDiscard()) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener("click", function(event) {
        const link = event.target && event.target.closest ? event.target.closest("a[href]") : null;
        if (!link) {
            return;
        }
        const href = link.getAttribute("href") || "";
        if (href === "" || href.charAt(0) === "#" || href.toLowerCase().indexOf("javascript:") === 0) {
            return;
        }
        if (link.target && link.target !== "" && link.target !== "_self") {
            return;
        }
        if (!confirmDiscard()) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    window.addEventListener("beforeunload", function(event) {
        if (submitting || discardConfirmed || dirtyToggles().length === 0) {
            return;
        }
        event.preventDefault();
        event.returnValue = "";
    });

    refresh();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
