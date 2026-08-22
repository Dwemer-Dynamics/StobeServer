<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}
$db = $GLOBALS["db"];

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

$isEmbed = isset($_GET["embed"]) && strval($_GET["embed"]) === "1";
$message = "";
$messageType = "ok";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "toggle_action") {
    $command = action_editor_trim($_POST["command"] ?? "");
    $targetEnabled = action_editor_to_bool($_POST["target_enabled"] ?? "0");
    if ($command === "") {
        $message = "Missing action command.";
        $messageType = "err";
    } else {
        if (action_editor_upsert_custom_toggle($db, $command, $targetEnabled)) {
            $message = sprintf("%s is now %s.", $command, $targetEnabled ? "enabled" : "disabled");
        } else {
            $message = "Could not update action toggle.";
            $messageType = "err";
        }
    }
}

$search = action_editor_trim($_GET["search"] ?? "");
$state = strtolower(action_editor_trim($_GET["state"] ?? "all"));
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
     LIMIT 2000",
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
        .state-enabled {
            color: #6dd19c;
            font-weight: 600;
        }
        .state-disabled {
            color: #ffb3b3;
            font-weight: 600;
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
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification"><span class="message"></span></div>

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
                Toggling an action writes a persistent override into <code>core_action_custom</code>.
                Built-in defaults in <code>core_action</code> remain untouched.
            </p>
        </div>

        <div class="content-section full-width-section">
            <h2 id="entries">Actions</h2>

            <form method="get" action="">
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
                        <a href="<?= h(action_editor_build_url([], $isEmbed, "entries")) ?>" class="action-button">Clear</a>
                    </div>
                </div>
            </form>

            <div class="table-container">
                <table>
                    <tr>
                        <th>Command</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Toggle</th>
                    </tr>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="6">No actions found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $command = strval($row["command"] ?? "");
                        $enabled = action_editor_to_bool($row["is_activated"] ?? false);
                        $isCustom = strval($row["is_custom"] ?? "f") === "t";
                        $targetEnabled = $enabled ? "0" : "1";
                        ?>
                        <tr>
                            <td><code><?= h($command) ?></code></td>
                            <td><?= h($row["action_name"] ?? "") ?></td>
                            <td style="max-width: 520px;"><?= nl2br(h($row["description"] ?? "")) ?></td>
                            <td><span class="status-pill <?= $isCustom ? "custom" : "base" ?>"><?= $isCustom ? "custom" : "base" ?></span></td>
                            <td class="<?= $enabled ? "state-enabled" : "state-disabled" ?>"><?= $enabled ? "Enabled" : "Disabled" ?></td>
                            <td>
                                <form method="post" action="">
                                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                    <input type="hidden" name="action" value="toggle_action">
                                    <input type="hidden" name="command" value="<?= h($command) ?>">
                                    <input type="hidden" name="target_enabled" value="<?= h($targetEnabled) ?>">
                                    <button type="submit" class="<?= $enabled ? "btn-danger" : "btn-save" ?>">
                                        <?= $enabled ? "Disable" : "Enable" ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
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
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

