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

function stobe_voice_trim(mixed $value): string
{
    return trim(strval($value));
}

function stobe_voice_normalize_voiceid(mixed $value): string
{
    $voiceid = strtolower(stobe_voice_trim($value));
    if ($voiceid === "") {
        return "";
    }
    if (strlen($voiceid) > 255) {
        $voiceid = substr($voiceid, 0, 255);
    }
    return $voiceid;
}

function stobe_voice_normalize_sample_file(mixed $value): string
{
    $name = basename(stobe_voice_trim($value));
    if ($name === "") {
        return "";
    }
    if (strlen($name) > 255) {
        $name = substr($name, 0, 255);
    }
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
        return "";
    }
    return $name;
}

function stobe_voice_build_url(array $params = [], bool $embed = false, string $anchor = ""): string
{
    $base = basename($_SERVER["PHP_SELF"] ?? "voice_manager.php");
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

function stobe_voice_store_upload(array $file, string $voiceid, string $voicesDir, string &$error): string
{
    $error = "";
    $uploadError = intval($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return "";
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        $error = "Upload failed (error code " . strval($uploadError) . ").";
        return "";
    }

    $tmpPath = strval($file["tmp_name"] ?? "");
    if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
        $error = "Invalid upload payload.";
        return "";
    }

    $originalName = strval($file["name"] ?? "");
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ["mp3", "wav", "ogg"];
    if (!in_array($ext, $allowed, true)) {
        $error = "Unsupported file type. Allowed: mp3, wav, ogg.";
        return "";
    }

    $size = intval($file["size"] ?? 0);
    $maxSize = 25 * 1024 * 1024;
    if ($size <= 0 || $size > $maxSize) {
        $error = "Invalid file size. Maximum 25MB.";
        return "";
    }

    if (!is_dir($voicesDir)) {
        if (!@mkdir($voicesDir, 0775, true) && !is_dir($voicesDir)) {
            $error = "Could not create voices directory.";
            return "";
        }
    }

    $base = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_-]+/i', '_', $base) ?? "";
    $base = trim($base, "_");
    if ($base === "") {
        $base = $voiceid !== "" ? $voiceid : "voice_sample";
    }
    if (strlen($base) > 120) {
        $base = substr($base, 0, 120);
    }

    $uploadedHeader = @file_get_contents($tmpPath, false, null, 0, 12);
    $isRiffWav = is_string($uploadedHeader)
        && strlen($uploadedHeader) >= 12
        && substr($uploadedHeader, 0, 4) === "RIFF"
        && substr($uploadedHeader, 8, 4) === "WAVE";
    $mustConvertToWav = ($ext !== "wav") || !$isRiffWav;

    $targetExt = "wav";
    $candidate = $base . "." . $targetExt;
    $targetPath = $voicesDir . DIRECTORY_SEPARATOR . $candidate;
    $suffix = 2;
    while (file_exists($targetPath) && $suffix < 2000) {
        $candidate = $base . "_" . strval($suffix) . "." . $targetExt;
        $targetPath = $voicesDir . DIRECTORY_SEPARATOR . $candidate;
        $suffix++;
    }

    if ($mustConvertToWav) {
        $ffmpegPath = trim(strval(@shell_exec("command -v ffmpeg 2>/dev/null")));
        if ($ffmpegPath === "") {
            $ffmpegFallback = "/usr/bin/ffmpeg";
            if (is_file($ffmpegFallback)) {
                $ffmpegPath = $ffmpegFallback;
            }
        }
        if ($ffmpegPath === "") {
            $error = "ffmpeg is required to convert uploaded audio to WAV but was not found.";
            return "";
        }

        $command = escapeshellarg($ffmpegPath)
            . " -y -i " . escapeshellarg($tmpPath)
            . " -ac 1 -ar 22050 -f wav "
            . escapeshellarg($targetPath)
            . " >/dev/null 2>&1";
        $exitCode = 1;
        @exec($command, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($targetPath) || intval(@filesize($targetPath)) <= 44) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            $error = "Failed to convert uploaded audio to WAV.";
            return "";
        }
    } else {
        if (!@move_uploaded_file($tmpPath, $targetPath)) {
            $error = "Failed to move uploaded voice sample.";
            return "";
        }
    }

    @chmod($targetPath, 0664);
    return $candidate;
}

$isEmbed = isset($_GET["embed"]) && strval($_GET["embed"]) === "1";
$scriptPath = $_SERVER["SCRIPT_NAME"] ?? "";
$uiPos = strpos($scriptPath, "/ui/");
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : "";
if ($webRoot === "/") {
    $webRoot = "";
}
$webRoot = rtrim($webRoot, "/");

$voicesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "voices";
$message = "";
$messageType = "ok";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["save_voice"])) {
        $originalVoiceId = stobe_voice_normalize_voiceid($_POST["original_voiceid"] ?? "");
        $voiceid = stobe_voice_normalize_voiceid($_POST["voiceid"] ?? "");
        if ($originalVoiceId !== "") {
            $voiceid = $originalVoiceId;
        }

        $gender = strtolower(stobe_voice_trim($_POST["gender"] ?? "any"));
        if (!in_array($gender, ["any", "male", "female"], true)) {
            $gender = "any";
        }

        $race = stobe_voice_trim($_POST["race"] ?? "any");
        if ($race === "") {
            $race = "any";
        }
        $faction = stobe_voice_trim($_POST["faction"] ?? "any");
        if ($faction === "") {
            $faction = "any";
        }
        $unique = stobe_voice_trim($_POST["unique"] ?? "");
        $notes = stobe_voice_trim($_POST["notes"] ?? "");

        $existingSample = stobe_voice_normalize_sample_file($_POST["existing_sample_file"] ?? "");
        $uploadError = "";
        $uploadedSample = "";
        $voiceIdFormatOk = (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $voiceid) === 1);

        if ($voiceid === "") {
            $message = "Voice ID is required.";
            $messageType = "err";
        } elseif (!$voiceIdFormatOk) {
            $message = "Voice ID may only contain lowercase letters, numbers, underscore, and dash.";
            $messageType = "err";
        } else {
            if (isset($_FILES["voice_file"]) && is_array($_FILES["voice_file"])) {
                $uploadedSample = stobe_voice_store_upload($_FILES["voice_file"], $voiceid, $voicesDir, $uploadError);
            }
            if ($uploadError !== "") {
                $message = $uploadError;
                $messageType = "err";
            }

            $sampleFile = $uploadedSample !== "" ? $uploadedSample : $existingSample;
            if ($messageType !== "err" && $sampleFile === "") {
                $message = "Upload a voice sample.";
                $messageType = "err";
            } elseif ($messageType !== "err") {
                $ok = $db->exec(
                    "INSERT INTO core_voiceid_custom (voiceid, sample_file, gender, race, faction, \"unique\", notes, updated_at)
                     VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
                     ON CONFLICT (voiceid) DO UPDATE SET
                        sample_file = EXCLUDED.sample_file,
                        gender = EXCLUDED.gender,
                        race = EXCLUDED.race,
                        faction = EXCLUDED.faction,
                        \"unique\" = EXCLUDED.\"unique\",
                        notes = EXCLUDED.notes,
                        updated_at = NOW()",
                    [$voiceid, $sampleFile, $gender, $race, $faction, $unique, $notes]
                );
                if ($ok) {
                    header("Location: " . stobe_voice_build_url(["ok" => "saved", "edit" => $voiceid], $isEmbed, "edit-form"));
                    exit;
                }
                $message = "Failed to save voice entry.";
                $messageType = "err";
            }
        }
    } elseif (isset($_POST["delete_override"])) {
        $voiceid = stobe_voice_normalize_voiceid($_POST["voiceid"] ?? "");
        if ($voiceid !== "") {
            $db->exec("DELETE FROM core_voiceid_custom WHERE LOWER(voiceid) = LOWER($1)", [$voiceid]);
        }
        header("Location: " . stobe_voice_build_url(["ok" => "deleted"], $isEmbed, "entries"));
        exit;
    } elseif (isset($_POST["truncate_custom"])) {
        $db->exec("TRUNCATE TABLE core_voiceid_custom");
        header("Location: " . stobe_voice_build_url(["ok" => "reset"], $isEmbed, "entries"));
        exit;
    }
}

if (isset($_GET["ok"])) {
    $ok = stobe_voice_trim($_GET["ok"] ?? "");
    if ($ok === "saved") {
        $message = "Voice entry saved to custom overrides.";
    } elseif ($ok === "deleted") {
        $message = "Custom voice override deleted.";
    } elseif ($ok === "reset") {
        $message = "All custom voice overrides cleared.";
    }
}

$letter = strtoupper(stobe_voice_trim($_GET["letter"] ?? ""));
$search = stobe_voice_trim($_GET["search"] ?? "");
$params = [];
$whereParts = [];

if ($letter !== "" && preg_match('/^[A-Z]$/', $letter) === 1) {
    $params[] = strtolower($letter) . "%";
    $idx = count($params);
    $whereParts[] = "LOWER(COALESCE(v.voiceid, '')) LIKE $" . $idx;
}

if ($search !== "") {
    $params[] = "%" . strtolower($search) . "%";
    $idx = count($params);
    $whereParts[] = "(LOWER(COALESCE(v.voiceid, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.sample_file, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.gender, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.race, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.faction, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.\"unique\", '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.notes, '')) LIKE $" . $idx . ")";
}

$whereSql = count($whereParts) > 0 ? ("WHERE " . implode(" AND ", $whereParts)) : "";
$rows = $db->fetchAll(
    "SELECT
        v.voiceid,
        v.sample_file,
        v.gender,
        v.race,
        v.faction,
        v.\"unique\",
        v.notes,
        EXISTS (
            SELECT 1
            FROM core_voiceid_custom c
            WHERE LOWER(c.voiceid) = LOWER(v.voiceid)
        ) AS is_custom
     FROM combined_core_voiceid v
     $whereSql
     ORDER BY LOWER(v.voiceid)
     LIMIT 2000",
    $params
);

$editVoiceId = stobe_voice_normalize_voiceid($_GET["edit"] ?? "");
$editRow = false;
if ($editVoiceId !== "") {
    $editRow = $db->fetchOne(
        "SELECT voiceid, sample_file, gender, race, faction, \"unique\", notes
         FROM combined_core_voiceid
         WHERE LOWER(voiceid) = LOWER($1)
         LIMIT 1",
        [$editVoiceId]
    );
}
if (!$editRow) {
    $editRow = [
        "voiceid" => "",
        "sample_file" => "",
        "gender" => "any",
        "race" => "any",
        "faction" => "any",
        "unique" => "",
        "notes" => "",
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Manager</title>
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
        .page-header h1.api-title { margin-bottom: 8px; }
        h1.api-title {
            margin: 0 0 20px 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #ffffff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h1.api-title, h1.api-title * {
            font-family: "MagicCards", serif !important;
        }
        .page-subtitle {
            margin: 0;
            color: #ffffff;
            font-size: 1.1em;
            line-height: 1.6;
            font-family: var(--stobe-title-font) !important;
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
            margin: 8px 0;
            color: #c9d3e5;
            line-height: 1.55;
        }
        .logic-list {
            margin: 0;
            padding-left: 18px;
            color: #dbe4f3;
        }
        .logic-list li {
            margin-bottom: 8px;
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
        input[type="text"], input[type="file"], select, textarea {
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
        input[type="text"]:focus, input[type="file"]:focus, select:focus, textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
            background: rgba(34, 34, 34, 0.9);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .hint {
            color: #9fb1c9;
            font-size: 0.88em;
            margin-top: -6px;
            margin-bottom: 10px;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
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
        .sample-audio {
            width: 220px;
            max-width: 100%;
            height: 34px;
        }
        .small-muted {
            color: #9fb1c9;
            font-size: 12px;
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
        <h1 class="api-title">Voice Manager</h1>
        <p class="page-subtitle">Manage voice samples and matching metadata for NPC voice assignment</p>
    </div>

    <div class="content-grid">
        <div id="edit-form" class="content-section">
            <h2><?= stobe_voice_trim($editRow["voiceid"] ?? "") !== "" ? "Edit Voice Override" : "Add Voice Override" ?></h2>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="original_voiceid" value="<?= h($editRow["voiceid"] ?? "") ?>">
                <input type="hidden" name="existing_sample_file" value="<?= h($editRow["sample_file"] ?? "") ?>">

                <label for="voiceid">Voice ID (required)</label>
                <input
                    type="text"
                    id="voiceid"
                    name="voiceid"
                    value="<?= h($editRow["voiceid"] ?? "") ?>"
                    <?= stobe_voice_trim($editRow["voiceid"] ?? "") !== "" ? "readonly" : "" ?>
                    placeholder="example: male21, hive_worker_1"
                    required
                >
                <div class="hint">Lowercase letters, numbers, underscore, and dash only.</div>

                <label for="voice_file">Upload Voice Sample (single file)</label>
                <input type="file" id="voice_file" name="voice_file" accept=".mp3,.wav,.ogg">
                <div class="hint">Uploaded to <code>StobeServer/data/voices</code>. Non-WAV files are auto-converted to <code>.wav</code>.</div>

                <label for="gender">Gender</label>
                <?php $gender = strtolower(stobe_voice_trim($editRow["gender"] ?? "any")); ?>
                <select id="gender" name="gender">
                    <option value="any" <?= $gender === "any" ? "selected" : "" ?>>any</option>
                    <option value="male" <?= $gender === "male" ? "selected" : "" ?>>male</option>
                    <option value="female" <?= $gender === "female" ? "selected" : "" ?>>female</option>
                </select>

                <label for="race">Race</label>
                <input type="text" id="race" name="race" value="<?= h($editRow["race"] ?? "any") ?>" placeholder="any">

                <label for="faction">Faction</label>
                <input type="text" id="faction" name="faction" value="<?= h($editRow["faction"] ?? "any") ?>" placeholder="any">

                <label for="unique">Unique Match</label>
                <input type="text" id="unique" name="unique" value="<?= h($editRow["unique"] ?? "") ?>" placeholder="NPC name (optional)">

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Optional notes for this voice mapping."><?= h($editRow["notes"] ?? "") ?></textarea>

                <div class="action-row">
                    <button type="submit" name="save_voice" class="action-button upload-csv">Save Voice Override</button>
                    <?php if (stobe_voice_trim($editRow["voiceid"] ?? "") !== ""): ?>
                        <a href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>" class="action-button">Clear Form</a>
                    <?php endif; ?>
                </div>
            </form>
            <form action="" method="post" style="margin-top:10px;">
                <button
                    type="submit"
                    name="truncate_custom"
                    class="btn-danger"
                    onclick="return confirm('Delete all custom voice overrides? This cannot be undone.');"
                >
                    Factory Reset Custom Voice Overrides
                </button>
            </form>
        </div>

        <div class="content-section info-panel">
            <h2>Voice Selection Logic</h2>
            <p>Stobe selects a voice ID from <code>combined_core_voiceid</code> and writes the chosen value to each NPC profile.</p>
            <ol class="logic-list">
                <li>Prefer <strong>unique name matches</strong> first (the <code>unique</code> column).</li>
                <li>Rows with a non-empty <code>unique</code> are used only for that unique NPC and are never in the random pool.</li>
                <li>Then match by <strong>gender + race + faction</strong> (strict), then relax to <strong>gender + race</strong>, then <strong>gender + faction</strong>, then <strong>gender</strong>.</li>
                <li>If no match exists, use deterministic fallback from the remaining non-unique rows.</li>
                <li>If a custom row exists in <code>core_voiceid_custom</code>, it overrides base <code>core_voiceid</code> for the same <code>voiceid</code>.</li>
            </ol>
            <p class="small-muted">Tip: use <code>sample_file</code> values that exist under <code>data/voices</code> for local sample preview and consistency.</p>
        </div>

        <div class="content-section full-width-section">
            <div id="entries"></div>

            <div class="action-container">
                <form class="search-container" method="get" action="">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letter !== ""): ?><input type="hidden" name="letter" value="<?= h($letter) ?>"><?php endif; ?>
                    <input type="text" name="search" placeholder="Search voice id, sample, race, faction, unique..." value="<?= h($search) ?>">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by Voice ID:</strong>
                <div class="filter-buttons">
                    <a href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>" class="alphabet-button">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a href="<?= h(stobe_voice_build_url(["letter" => $char], $isEmbed, "entries")) ?>" class="alphabet-button"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="voice-table-container" class="table-container">
                <table>
                    <tr>
                        <th>Voice ID</th>
                        <th>Sample</th>
                        <th>Gender</th>
                        <th>Race</th>
                        <th>Faction</th>
                        <th>Unique</th>
                        <th>Notes</th>
                        <th>Source</th>
                        <th>Actions</th>
                    </tr>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="9">No voice rows found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $voiceid = strval($row["voiceid"] ?? "");
                        $sampleFile = stobe_voice_normalize_sample_file($row["sample_file"] ?? "");
                        $samplePath = $sampleFile !== "" ? ($voicesDir . DIRECTORY_SEPARATOR . $sampleFile) : "";
                        $sampleExists = ($samplePath !== "" && file_exists($samplePath));
                        $sampleUrl = ($sampleFile !== "") ? ($webRoot . "/data/voices/" . rawurlencode($sampleFile)) : "";
                        $isCustom = strval($row["is_custom"] ?? "f") === "t";
                        ?>
                        <tr>
                            <td><code><?= h($voiceid) ?></code></td>
                            <td>
                                <div><?= h($sampleFile !== "" ? $sampleFile : "(none)") ?></div>
                                <?php if ($sampleExists): ?>
                                    <audio class="sample-audio" controls preload="none" src="<?= h($sampleUrl) ?>"></audio>
                                <?php elseif ($sampleFile !== ""): ?>
                                    <div class="small-muted">File not found in data/voices</div>
                                <?php endif; ?>
                            </td>
                            <td><?= h(strval($row["gender"] ?? "")) ?></td>
                            <td><?= h(strval($row["race"] ?? "")) ?></td>
                            <td><?= h(strval($row["faction"] ?? "")) ?></td>
                            <td><?= h(strval($row["unique"] ?? "")) ?></td>
                            <td style="max-width: 280px;"><?= nl2br(h(strval($row["notes"] ?? ""))) ?></td>
                            <td>
                                <span class="status-pill <?= $isCustom ? "custom" : "base" ?>">
                                    <?= $isCustom ? "custom" : "base" ?>
                                </span>
                            </td>
                            <td>
                                <a class="action-button edit" href="<?= h(stobe_voice_build_url(["edit" => $voiceid], $isEmbed, "edit-form")) ?>">Edit</a>
                                <?php if ($isCustom): ?>
                                    <form action="" method="post" style="display:inline;">
                                        <input type="hidden" name="voiceid" value="<?= h($voiceid) ?>">
                                        <button
                                            type="submit"
                                            name="delete_override"
                                            class="action-button btn-danger"
                                            onclick="return confirm('Delete custom override for <?= h($voiceid) ?>?');"
                                        >
                                            Remove Override
                                        </button>
                                    </form>
                                <?php endif; ?>
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
