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

function stobe_desc_trim(mixed $value): string
{
    return trim(strval($value));
}

$isEmbed = isset($_GET["embed"]) && strval($_GET["embed"]) === "1";

function stobe_desc_build_url(array $params = [], bool $embed = false, string $anchor = ""): string
{
    $base = basename($_SERVER["PHP_SELF"] ?? "description.php");
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

function stobe_desc_upsert(sql $db, string $stringid, string $name, string $description): bool
{
    if ($stringid === "") {
        return false;
    }
    $result = $db->exec(
        "INSERT INTO descriptions_custom (stringid, name, description)
         VALUES ($1, $2, $3)
         ON CONFLICT (stringid)
         DO UPDATE SET
            name = EXCLUDED.name,
            description = EXCLUDED.description",
        [$stringid, $name, $description]
    );
    return $result !== false;
}

// --------------------------------------------------------------------------
// CSV / Export actions (before HTML)
// --------------------------------------------------------------------------
if (isset($_GET["action"]) && $_GET["action"] === "export_custom_items") {
    $rows = $db->fetchAll("SELECT stringid, name, description FROM descriptions_custom ORDER BY stringid ASC");
    $filename = "custom_descriptions_export_" . date("Y-m-d_H-i-s") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description"]);
    foreach ($rows as $row) {
        fputcsv($out, [
            strval($row["stringid"] ?? ""),
            strval($row["name"] ?? ""),
            strval($row["description"] ?? ""),
        ]);
    }
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "download_example") {
    $filename = "example_descriptions.csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description"]);
    fputcsv($out, ["515-gamedata.base", "Standard First Aid Kit", "A basic medical kit used to bandage wounds and stabilize injuries."]);
    fputcsv($out, ["18020-gamedata.base", "Skeleton Repair Kit", "Specialized toolkit and parts for repairing robotic limbs and skeleton bodies."]);
    fputcsv($out, ["52306-rebirth.mod", "Falling Sun", "A heavy two-handed blade built for brutal cleaving strikes."]);
    fputcsv($out, ["14491-rebirth.mod", "Shinobi Thieves", "A covert faction known for stealth, trade contacts, and opportunistic contracts."]);
    fclose($out);
    exit;
}

$message = "";
$messageType = "ok";

// --------------------------------------------------------------------------
// Mutations
// --------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["submit_individual"])) {
        $stringid = stobe_desc_trim($_POST["stringid"] ?? $_POST["baseid"] ?? "");
        $name = stobe_desc_trim($_POST["name"] ?? "");
        $description = stobe_desc_trim($_POST["description"] ?? "");
        if (strlen($stringid) > 128) {
            $stringid = substr($stringid, 0, 128);
        }
        if ($stringid === "") {
            $message = "Please fill in the required field: String ID.";
            $messageType = "err";
        } else {
            if (stobe_desc_upsert($db, $stringid, $name, $description)) {
                $message = "Item data inserted/updated successfully.";
            } else {
                $message = "Error inserting/updating item data.";
                $messageType = "err";
            }
        }
    } elseif (isset($_POST["submit_csv"])) {
        if (!isset($_FILES["csv_file"]) || intval($_FILES["csv_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = "No file uploaded or there was an upload error.";
            $messageType = "err";
        } else {
            $tmpPath = strval($_FILES["csv_file"]["tmp_name"] ?? "");
            $name = strval($_FILES["csv_file"]["name"] ?? "");
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== "csv") {
                $message = "Upload failed. Allowed file types: csv";
                $messageType = "err";
            } else {
                $csvData = @file_get_contents($tmpPath);
                if ($csvData === false) {
                    $message = "Error reading the uploaded CSV file.";
                    $messageType = "err";
                } else {
                    if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
                        $csvData = substr($csvData, 3);
                    }
                    if (strpos($csvData, "\x00") !== false) {
                        $csvData = mb_convert_encoding($csvData, "UTF-8", "UTF-16");
                    } elseif (!mb_check_encoding($csvData, "UTF-8")) {
                        $csvData = mb_convert_encoding($csvData, "UTF-8", "Windows-1252");
                    }
                    $stream = fopen("php://memory", "r+");
                    fwrite($stream, $csvData);
                    rewind($stream);

                    $header = fgetcsv($stream, 0, ",");
                    $map = [];
                    if (is_array($header)) {
                        foreach ($header as $i => $colName) {
                            $k = strtolower(trim(strval($colName)));
                            if ($k !== "") {
                                $map[$k] = intval($i);
                            }
                        }
                    }

                    $pick = static function (array $row, array $m, array $aliases, int $fallback = -1): string {
                        foreach ($aliases as $alias) {
                            $k = strtolower(trim($alias));
                            if ($k !== "" && array_key_exists($k, $m)) {
                                return trim(strval($row[intval($m[$k])] ?? ""));
                            }
                        }
                        if ($fallback >= 0) {
                            return trim(strval($row[$fallback] ?? ""));
                        }
                        return "";
                    };

                    $count = 0;
                    while (($row = fgetcsv($stream, 0, ",")) !== false) {
                        if (!is_array($row) || count($row) === 0) {
                            continue;
                        }
                        $stringid = $pick($row, $map, ["stringid", "baseid"], 0);
                        $rowName = $pick($row, $map, ["name"], 1);
                        $desc = $pick($row, $map, ["description"], 2);
                        if (strlen($stringid) > 128) {
                            $stringid = substr($stringid, 0, 128);
                        }
                        if ($stringid === "") {
                            continue;
                        }
                        if (stobe_desc_upsert($db, $stringid, $rowName, $desc)) {
                            $count++;
                        }
                    }
                    fclose($stream);
                    $message = $count . " records inserted/updated successfully from the CSV file.";
                }
            }
        }
    } elseif (isset($_POST["truncate_items"])) {
        $ok = $db->exec("TRUNCATE TABLE descriptions_custom");
        if ($ok) {
            $message = "All custom item entries have been deleted successfully.";
        } else {
            $message = "Error truncating custom table.";
            $messageType = "err";
        }
    } elseif (isset($_POST["action"]) && $_POST["action"] === "update_single") {
        $stringid = stobe_desc_trim($_POST["stringid"] ?? $_POST["baseid"] ?? "");
        $name = stobe_desc_trim($_POST["name"] ?? "");
        $description = stobe_desc_trim($_POST["description"] ?? "");
        if (strlen($stringid) > 128) {
            $stringid = substr($stringid, 0, 128);
        }
        if ($stringid === "") {
            $message = "String ID is required.";
            $messageType = "err";
        } else {
            if (stobe_desc_upsert($db, $stringid, $name, $description)) {
                $message = "Item entry updated successfully.";
            } else {
                $message = "Error updating item entry.";
                $messageType = "err";
            }
        }
    }
}

// --------------------------------------------------------------------------
// Query list
// --------------------------------------------------------------------------
$letter = strtoupper(stobe_desc_trim($_GET["letter"] ?? ""));
$search = stobe_desc_trim($_GET["search"] ?? "");
$params = [];
$whereParts = [];

if ($letter !== "" && preg_match("/^[A-Z]$/", $letter)) {
    $params[] = $letter . "%";
    $idx = count($params);
    $whereParts[] = "LOWER(COALESCE(name, '')) LIKE LOWER($" . $idx . ")";
}
if ($search !== "") {
    $params[] = "%" . $search . "%";
    $idx = count($params);
    $whereParts[] = "(LOWER(COALESCE(stringid, '')) LIKE LOWER($" . $idx . ")
                 OR LOWER(COALESCE(name, '')) LIKE LOWER($" . $idx . ")
                 OR LOWER(COALESCE(description, '')) LIKE LOWER($" . $idx . "))";
}

$whereSql = "";
if (count($whereParts) > 0) {
    $whereSql = "WHERE " . implode(" AND ", $whereParts);
}

$rows = $db->fetchAll(
    "SELECT
        v.stringid,
        v.name,
        v.description,
        EXISTS (SELECT 1 FROM descriptions_custom c WHERE LOWER(c.stringid) = LOWER(v.stringid)) AS is_custom
     FROM combined_descriptions v
     $whereSql
     ORDER BY LOWER(COALESCE(v.name, '')), LOWER(v.stringid)
     LIMIT 2000",
    $params
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Description Manager</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: 30px;
            padding-bottom: 40px;
            padding-left: 5px;
            padding-right: 5px;
            width: 100%;
            margin: 0;
        }
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .page-header h1.api-title {
            margin-bottom: 8px;
        }
        h1.api-title {
            margin: 0 0 20px 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #e6b76c;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h1.api-title, h1.api-title * {
            font-family: "MagicCards", serif !important;
        }
        .page-subtitle {
            margin: 0;
            color: #bbb;
            font-size: 1.1em;
            line-height: 1.6;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
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
        .info-panel p {
            margin: 0;
            color: #c9d3e5;
            line-height: 1.55;
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
        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            color: #e6b76c;
            font-weight: bold;
        }
        input[type="text"], input[type="file"], textarea {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 10px;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
            background: rgba(26, 26, 26, 0.8);
            color: #e9efff;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        input[type="text"]:focus, textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
            background: rgba(34, 34, 34, 0.9);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
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
            min-width: 300px;
        }
        .search-container input[type="text"] {
            flex: 1;
        }
        .filter-section {
            margin-bottom: 20px;
            text-align: center;
        }
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin: 10px 0;
            justify-content: center;
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
        @media (max-width: 1024px) {
            main {
                padding-left: 4%;
                padding-right: 4%;
            }
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
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

    <div class="page-header">
        <h1 class="api-title">Description Manager</h1>
        <p class="page-subtitle">Configure item and equipment descriptions for richer prompt context</p>
    </div>

    <div class="content-grid">
        <div class="content-section">
            <h2>CSV Upload</h2>
            <form action="" method="post" enctype="multipart/form-data">
                <label for="csv_file">Select .csv file to upload:</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                    <button type="submit" name="submit_csv" class="action-button upload-csv">Upload CSV</button>
                    <a href="<?= h(stobe_desc_build_url(["action" => "download_example"], $isEmbed)) ?>" class="action-button download-csv">Download Example CSV</a>
                    <a href="<?= h(stobe_desc_build_url(["action" => "export_custom_items"], $isEmbed)) ?>" class="action-button export-csv">Export Custom Descriptions</a>
                </div>
            </form>
            <form action="" method="post" style="margin-top: 10px;">
                <button type="submit" name="truncate_items" class="btn-danger"
                    onclick="return confirm('Are you sure you want to DELETE ALL ENTRIES in descriptions_custom? This action is IRREVERSIBLE!');">
                    Factory Reset Custom Descriptions
                </button>
            </form>
        </div>

        <div class="content-section info-panel">
            <h2>Description Manager</h2>
            <p>This page manages item and equipment descriptions used in prompt context. When items/equipment are detected in-game, entries that include a description are auto-imported into the base descriptions table using item_id/string_id (or a generated ID fallback when missing). You can also import/export in bulk via CSV, and custom rows override base records when string IDs match.</p>
        </div>

        <div class="content-section full-width-section">
            <div id="entries"></div>

            <div class="action-container">
                <button onclick="openNewEntryModal()" class="action-button add-new">Add New Entry</button>
                <form class="search-container" method="get" action="">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letter !== ""): ?><input type="hidden" name="letter" value="<?= h($letter) ?>"><?php endif; ?>
                    <input type="text" name="search" placeholder="Search" value="<?= h($search) ?>">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(stobe_desc_build_url([], $isEmbed, "entries")) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by Name:</strong>
                <div class="filter-buttons">
                    <a href="<?= h(stobe_desc_build_url([], $isEmbed, "entries")) ?>" class="alphabet-button">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a href="<?= h(stobe_desc_build_url(["letter" => $char], $isEmbed, "entries")) ?>" class="alphabet-button"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="item-table-container" class="table-container">
                <table>
                    <tr>
                        <th>String ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Actions</th>
                    </tr>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="5">No descriptions found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $stringid = strval($row["stringid"] ?? "");
                        $name = strval($row["name"] ?? "");
                        $desc = strval($row["description"] ?? "");
                        $isCustom = strval($row["is_custom"] ?? "f") === "t";
                        $jsData = json_encode([
                            "stringid" => $stringid,
                            "name" => $name,
                            "description" => $desc,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr>
                            <td><?= h($stringid) ?></td>
                            <td><?= h($name) ?></td>
                            <td style="max-width: 500px;"><?= nl2br(h(strlen($desc) > 240 ? (substr($desc, 0, 240) . "...") : $desc)) ?></td>
                            <td>
                                <span class="status-pill <?= $isCustom ? "custom" : "base" ?>">
                                    <?= $isCustom ? "custom" : "base" ?>
                                </span>
                            </td>
                            <td>
                                <button onclick='openEditModal(<?= h($jsData) ?>)' class="action-button edit">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="editModal" class="modal-backdrop" style="display:none;">
    <div class="modal-container">
        <div class="modal-header"><h2 class="modal-title">Edit Description</h2></div>
        <div class="modal-body">
            <form action="<?= h(stobe_desc_build_url([], $isEmbed, "entries")) ?>" method="post">
                <input type="hidden" name="action" value="update_single">

                <label for="edit_stringid">String ID:</label>
                <small>String IDs are immutable in edit mode. Create a new entry to change it.</small>
                <input type="text" name="stringid" id="edit_stringid" readonly style="background-color: #2a2a2a; cursor: not-allowed;" required>

                <label for="edit_name">Name:</label>
                <input type="text" name="name" id="edit_name">

                <label for="edit_description">Description:</label>
                <textarea name="description" id="edit_description" rows="6"></textarea>

                <div class="modal-footer">
                    <button type="submit" class="btn-save">Save Changes</button>
                    <button type="button" onclick="closeEditModal()" class="btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="newEntryModal" class="modal-backdrop" style="display:none;">
    <div class="modal-container">
        <div class="modal-header"><h2 class="modal-title">Add New Description</h2></div>
        <div class="modal-body">
            <form action="" method="post">
                <label for="new_stringid">String ID (required):</label>
                <small>Unique identifier for this entry (e.g., 52306-rebirth.mod).</small>
                <input type="text" name="stringid" id="new_stringid" required>

                <label for="new_name">Name:</label>
                <input type="text" name="name" id="new_name">

                <label for="new_description">Description:</label>
                <small>Short description to be injected into AI context.</small>
                <textarea name="description" id="new_description" rows="6"></textarea>

                <div class="modal-footer">
                    <button type="submit" name="submit_individual" class="btn-save">Add Entry</button>
                    <button type="button" onclick="closeNewEntryModal()" class="btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(data) {
    document.getElementById("edit_stringid").value = data.stringid || "";
    document.getElementById("edit_name").value = data.name || "";
    document.getElementById("edit_description").value = data.description || "";
    document.getElementById("editModal").style.display = "block";
    document.body.style.overflow = "hidden";
}
function closeEditModal() {
    document.getElementById("editModal").style.display = "none";
    document.body.style.overflow = "auto";
}
function openNewEntryModal() {
    document.getElementById("new_stringid").value = "";
    document.getElementById("new_name").value = "";
    document.getElementById("new_description").value = "";
    document.getElementById("newEntryModal").style.display = "block";
    document.body.style.overflow = "hidden";
}
function closeNewEntryModal() {
    document.getElementById("newEntryModal").style.display = "none";
    document.body.style.overflow = "auto";
}
window.onclick = function(event) {
    const editModal = document.getElementById("editModal");
    const newEntryModal = document.getElementById("newEntryModal");
    if (event.target === editModal) {
        closeEditModal();
    }
    if (event.target === newEntryModal) {
        closeNewEntryModal();
    }
};
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

