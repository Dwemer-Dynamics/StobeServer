<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . "../";

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");

$GLOBALS["ENGINE_PATH"]=$enginePath;

require_once("{$enginePath}/lib/core/npc_master.class.php");

$CONF_SAMPLE_VARS=extract_assignments("$enginePath/lib/bootstrap.php");


//function renderSelect($obj, $fieldName, $labelText, $selectedValue = "") 
//function include from below file
include(__DIR__."/tmpl/ui_utils.php");

// Determine web root and include site chrome like world_knowledge_upload
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");

// Route AI profile generation through this page to avoid web-root/cmd path issues.
if (isset($_GET['action_ai_regen_profile']) && strval($_GET['action_ai_regen_profile']) === '1') {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'cmd' . DIRECTORY_SEPARATOR . 'action_ai_regen_profile.php');
    exit;
}

function stobeUiResolveMetadataToggleOverride(array $metadata, string $settingKey): ?bool
{
    if (!array_key_exists($settingKey, $metadata)) {
        return null;
    }
    $raw = $metadata[$settingKey];
    if ($raw === '' || $raw === null) {
        return null;
    }
    return coerceBoolean($raw);
}

function stobeUiResolveMtmOverride(array $metadata, array $extended): ?bool
{
    $metadataOverride = stobeUiResolveMetadataToggleOverride($metadata, 'MIDDLE_TERM_MEMORY_ENABLED');
    if ($metadataOverride !== null) {
        return $metadataOverride;
    }
    if (array_key_exists('middle_term_enabled', $extended)) {
        $raw = $extended['middle_term_enabled'];
        if ($raw !== '' && $raw !== null) {
            return coerceBoolean($raw);
        }
    }
    return null;
}

function stobeUiResolveIndividualMemoryEnabled(array $extended): bool
{
    if (!array_key_exists('individual_memory_enabled', $extended)) {
        return false;
    }
    $raw = $extended['individual_memory_enabled'];
    if ($raw === '' || $raw === null) {
        return false;
    }
    return coerceBoolean($raw);
}

$TITLE = "Stobe - NPC Master";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/head.html");
?>

<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<style>
/* Core styling alignment */
@font-face {
    font-family: 'MagicCards';
    src: url('<?php echo $webRoot; ?>/ui/css/font/MailartRubberstamp-Regular.otf') format('opentype');
    font-weight: normal;
    font-style: normal;
}
main { 
    padding-top: 40px; 
    padding-bottom: 40px; 
}
.page-header {
    margin: 0 0 24px 0; 
    padding: 24px;
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(28, 28, 28, 0.98));
    border-radius: 10px;
    border: 1px solid #3a3a3a;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    text-align: center;
}
h1.api-title { 
    margin: 0 0 8px 0; 
    font-family: 'MagicCards', serif; 
    word-spacing: 8px; 
    font-size: 2em; 
    color: #e6b76c; 
    text-shadow: 2px 2px 4px rgba(0,0,0,0.5); 
}
.page-subtitle {
    color: #aaa;
    font-size: 0.95em;
    line-height: 1.5;
    margin: 0;
}

/* Relationship Build Button - Gray/Orange theme to match UI */
.btn-rel-build {
    background: rgba(58, 58, 74, 0.8);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 8px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 600;
}
.btn-rel-build:hover {
    background: rgba(74, 74, 90, 0.9);
    border-color: #e6b76c;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
}

/* Relationship Build Modal - Gray/Orange theme */
.rel-build-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}
.rel-build-modal-overlay.show { display: flex; }
.rel-build-modal {
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98));
    border: 1px solid #3a3a3a;
    border-radius: 12px;
    padding: 32px;
    max-width: 600px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6), 
                0 0 30px rgba(230, 183, 108, 0.1);
}
.rel-build-modal h2 {
    margin: 0 0 22px 0;
    color: #e6b76c;
    font-family: 'MagicCards', serif;
    font-size: 1.7em;
    text-align: center;
    text-shadow: 0 0 15px rgba(230, 183, 108, 0.4);
}
.rel-build-modal .modal-body {
    color: #e0e0e0;
    line-height: 1.6;
    margin-bottom: 20px;
    text-align: center;
}
.rel-build-modal .modal-body p { margin: 12px 0; }
.rel-build-modal .modal-body strong { color: #e6b76c; }
.rel-build-modal .stats-box {
    background: linear-gradient(135deg, rgba(26, 26, 26, 0.9), rgba(20, 20, 20, 0.95));
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
    display: flex;
    justify-content: space-around;
    text-align: center;
    box-shadow: inset 0 1px rgba(255, 255, 255, 0.03);
}
.rel-build-modal .stat-item { }
.rel-build-modal .stat-value { font-size: 2em; color: #e6b76c; font-weight: bold; text-shadow: 0 0 10px rgba(230, 183, 108, 0.3); }
.rel-build-modal .stat-label { font-size: 0.85em; color: #999; margin-top: 4px; }
.rel-build-modal .progress-section { display: none; margin: 20px 0; }
.rel-build-modal .progress-section.show { display: block; }
.rel-build-modal .progress-bar-wrap {
    background: rgba(26, 26, 26, 0.9);
    border-radius: 8px;
    height: 26px;
    overflow: hidden;
    margin: 12px 0;
    border: 1px solid #3a3a3a;
}
.rel-build-modal .progress-bar {
    background: linear-gradient(90deg, rgb(200, 100, 10), #e6b76c);
    height: 100%;
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 7px;
    box-shadow: 0 0 10px rgba(230, 183, 108, 0.5);
}
.rel-build-modal .progress-text {
    text-align: center;
    color: #e6b76c;
    font-size: 0.9em;
    margin-top: 8px;
}
.rel-build-modal .progress-log {
    background: rgba(10, 10, 10, 0.9);
    border: 1px solid #333;
    border-radius: 8px;
    padding: 12px;
    max-height: 150px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 0.8em;
    color: #999;
    margin-top: 12px;
}
.rel-build-modal .progress-log .success { color: #4ade80; }
.rel-build-modal .progress-log .error { color: #f87171; }
.rel-build-modal .progress-log .skip { color: #e6b76c; }
.rel-build-modal .modal-actions {
    display: flex;
    gap: 14px;
    justify-content: center;
    margin-top: 22px;
}
.rel-build-modal .btn-start {
    background: rgba(58, 58, 58, 0.9);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 12px 32px;
    border-radius: 8px;
    font-size: 1em;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
}
.rel-build-modal .btn-start:hover { 
    background: rgba(74, 74, 74, 0.9); 
    border-color: #e6b76c;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.3);
}
.rel-build-modal .btn-start:disabled { 
    background: #222; 
    color: #555; 
    border-color: #444; 
    cursor: not-allowed; 
    transform: none;
    box-shadow: none;
}
.rel-build-modal .btn-cancel {
    background: rgba(58, 58, 58, 0.9);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 12px 32px;
    border-radius: 8px;
    font-size: 1em;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
}
.rel-build-modal .btn-cancel:hover { 
    background: rgba(74, 74, 74, 0.9); 
    border-color: #e6b76c;
    transform: translateY(-1px);
}
.rel-build-modal .connector-info {
    background: rgba(230, 183, 108, 0.1);
    border: 1px solid rgba(230, 183, 108, 0.5);
    border-radius: 8px;
    padding: 12px;
    margin: 12px 0;
    font-size: 0.9em;
    text-align: center;
    color: #34d399;
}
.rel-build-modal .connector-info .connector-model {
    color: #bbb;
    font-size: 0.9em;
}
.rel-build-modal .no-connector {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.5);
    color: #f87171;
}
</style>

<main>

<?php
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}
$npc = new NpcMaster();

// Check if The Narrator exists in core_npc_master (for informational note)
$narratorExistsInNpcMaster = false;
try {
    $narratorExistsInNpcMaster = ($npc->getByName('The Narrator') !== false);
} catch (Throwable $e) {
    // Ignore errors
}

$lastInfoRow = $GLOBALS["db"]->fetchOne("select max(gamets) as gamets from eventlog where type='infosave'");
$LAST_INFOSAVE_EVENT = intval($lastInfoRow["gamets"] ?? 0);

// Helper: map gender text to an icon character
if (!function_exists('gender_icon_char')) {
    function gender_icon_char($gender){
        $g = strtolower(trim((string)$gender));
        if ($g === '') return '';
        if ($g === 'female' || $g === 'f' || $g === 'woman' || $g === 'girl') return 'F';
        if ($g === 'male' || $g === 'm' || $g === 'man' || $g === 'boy') return 'M';
        if ($g === 'nonbinary' || $g === 'non-binary' || $g === 'nb' || $g === 'enby' || $g === 'other' || $g === 'agender' || $g === 'genderfluid') return 'N';
        return '';
    }
}

// Helper: map gender text to a CSS class suffix for coloring
if (!function_exists('gender_icon_class')) {
    function gender_icon_class($gender){
        $g = strtolower(trim((string)$gender));
        if ($g === 'female' || $g === 'f' || $g === 'woman' || $g === 'girl') return 'gender-female';
        if ($g === 'male' || $g === 'm' || $g === 'man' || $g === 'boy') return 'gender-male';
        if ($g === 'nonbinary' || $g === 'non-binary' || $g === 'nb' || $g === 'enby' || $g === 'other' || $g === 'agender' || $g === 'genderfluid') return 'gender-nb';
        return '';
    }
}

if (!function_exists('stobe_ui_format_bounty_summary')) {
    function stobe_ui_format_bounty_summary(mixed $bountyValue, mixed $bountyPayloadValue, array $metadata): array {
        $bountyPayload = stobeNormalizeBountyPayload($bountyPayloadValue);
        if (count($bountyPayload) === 0) {
            $bountyPayload = stobeNormalizeBountyPayload($bountyValue);
        }

        $metadataBountyInfo = $metadata['bounty_info'] ?? null;
        if ($metadataBountyInfo !== null) {
            $bountyPayload = stobeNormalizeBountyPayload($bountyPayload, $metadataBountyInfo);
        }

        $bountyAmount = stobeBountyAmountFromPayload($bountyPayload);
        if ($bountyAmount <= 0) {
            $bountyAmount = stobeBountyAmountFromPayload($bountyValue);
        }
        $amountText = $bountyAmount > 0 ? (number_format($bountyAmount) . ' cats') : '0';
        $detailsText = '';
        $breakdownItems = [];
        $breakdownExtra = 0;
        $legacyDetails = '';

        if (!is_array($bountyPayload) || count($bountyPayload) === 0) {
            return [
                'amount_text' => $amountText,
                'details_text' => '',
                'breakdown_items' => [],
                'breakdown_extra' => 0,
                'legacy_details' => '',
            ];
        }

        $factions = $bountyPayload['factions'] ?? [];
        if (!is_array($factions) || count($factions) === 0) {
            $legacyText = trim(strval($metadata['bounty_text'] ?? ''));
            return [
                'amount_text' => $amountText,
                'details_text' => $legacyText,
                'breakdown_items' => [],
                'breakdown_extra' => 0,
                'legacy_details' => $legacyText,
            ];
        }

        $chunks = [];
        $maxFactionsToShow = 2;
        $maxBreakdownItems = 4;
        foreach ($factions as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (count($chunks) >= $maxFactionsToShow) {
                // Continue parsing to support full breakdown and accurate extra count.
                continue;
            }

            $factionName = trim(strval($entry['faction'] ?? ($entry['faction_id'] ?? 'Unknown faction')));
            $entryAmount = intval($entry['amount'] ?? 0);
            $reasonsRaw = $entry['what_for'] ?? [];
            $reasons = [];
            if (is_array($reasonsRaw)) {
                foreach ($reasonsRaw as $reason) {
                    $reasonText = trim(strval($reason));
                    if ($reasonText !== '') {
                        $reasons[] = $reasonText;
                    }
                }
            } elseif (is_string($reasonsRaw) && trim($reasonsRaw) !== '') {
                $reasons = array_filter(array_map('trim', explode(',', $reasonsRaw)), fn($v) => $v !== '');
            }
            if (count($reasons) > 6) {
                $reasons = array_slice($reasons, 0, 6);
            }

            if (count($breakdownItems) < $maxBreakdownItems) {
                $breakdownItems[] = [
                    'faction' => $factionName,
                    'amount_text' => $entryAmount > 0 ? (number_format($entryAmount) . ' cats') : '',
                    'reasons_text' => count($reasons) > 0 ? implode(', ', $reasons) : '',
                ];
            } else {
                $breakdownExtra++;
            }

            $segment = $factionName;
            if ($entryAmount > 0 && (count($factions) > 1 || $entryAmount !== $bountyAmount)) {
                $segment .= ' (' . number_format($entryAmount) . ')';
            }
            if (count($reasons) > 0) {
                $segment .= ' - Wanted for: ' . implode(', ', $reasons);
            }
            $chunks[] = $segment;
        }

        if (count($factions) > $maxFactionsToShow) {
            $chunks[] = '+' . (count($factions) - $maxFactionsToShow) . ' more faction(s)';
        }

        $detailsText = implode(' | ', $chunks);
        return [
            'amount_text' => $amountText,
            'details_text' => $detailsText,
            'breakdown_items' => $breakdownItems,
            'breakdown_extra' => $breakdownExtra,
            'legacy_details' => $legacyDetails,
        ];
    }
}

// Handle Create
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create"])) {
    $npc->create($_POST);
    header("Location: npc_master.php");
    exit;
}

// Handle Update
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update"])) {
    $_POST["md5"]=md5($_POST["npc_name"]);
    $npc->update($_POST["id"], $_POST);
    header("Location: npc_master.php");
    exit;
}

// Inline update (AJAX) for modal save
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["inline_update_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);

        // Merge relationship editor data into extended_data BEFORE processing
        if (file_exists(__DIR__."/../ext/relationship_system/npc_save_handler.php")) {
            include(__DIR__."/../ext/relationship_system/npc_save_handler.php");
        }

        // Ensure extended_data is valid JSON and sync NPC-only toggles.
        try {
            $postedExt = isset($_POST['extended_data']) ? (string)$_POST['extended_data'] : '';
            $tmp = [];
            if ($postedExt !== '') {
                $decoded = json_decode($postedExt, true);
                if (is_array($decoded)) {
                    $tmp = $decoded;
                }
            }
            if (array_key_exists('individual_memory_enabled', $_POST)) {
                $imbVal = $_POST['individual_memory_enabled'];
                if ($imbVal === '' || $imbVal === null || !coerceBoolean($imbVal)) {
                    unset($tmp['individual_memory_enabled']);
                } else {
                    $tmp['individual_memory_enabled'] = 1;
                }
            }
            $_POST['extended_data'] = json_encode($tmp);
        } catch (Throwable $e) {
            $_POST['extended_data'] = '{}';
        }

        // Persist Dynamic Profile / Middle Term toggles as metadata overrides.
        try {
            $postedMeta = isset($_POST['metadata']) ? (string)$_POST['metadata'] : '';
            $meta = [];
            if ($postedMeta !== '') {
                $tmpMeta = json_decode($postedMeta, true);
                if (is_array($tmpMeta)) {
                    $meta = $tmpMeta;
                }
            }

            if (array_key_exists('dynamic_profile', $_POST)) {
                $dynVal = $_POST['dynamic_profile'];
                if ($dynVal === '' || $dynVal === null) {
                    unset($meta['DYNAMIC_PROFILE_ENABLED']);
                } else {
                    $meta['DYNAMIC_PROFILE_ENABLED'] = coerceBoolean($dynVal);
                }
            }
            $_POST['metadata'] = json_encode($meta);
        } catch (Throwable $e) {
            if (!isset($_POST['metadata']) || trim((string)$_POST['metadata']) === '') {
                $_POST['metadata'] = '{}';
            }
        }
        if ($id <= 0) {
            $newId = $npc->create($_POST);
            if ($newId <= 0) {
                echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?: "Insert failed")]);
                exit;
            }
            echo json_encode(["ok"=>true, "id"=>$newId]);
        } else {
            $_POST["md5"]=md5($_POST["npc_name"]);
            $ok = $npc->update($id, $_POST);
            $npc->backupNpcById($id);// We also make a backup of manually edited NPCs, so when loading a save, will load this record
            if ($ok === false) {
                echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?? 'Update failed')]);
            } else {
                echo json_encode(["ok"=>true, "id"=>$id]);
            }
        }
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Toggle favorite (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_favorite"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid id"]); exit; }
        $rowBefore = $npc->getById($id);
        $current = coerceBoolean($rowBefore['npc_favorite'] ?? false) ? 1 : 0;
        $hasValue = array_key_exists('value', $_POST);
        $newValue = $hasValue
            ? (($_POST['value']==='1'||$_POST['value']===1||$_POST['value']===true) ? 1 : 0)
            : (1 - $current);
        $npc->update($id, ['npc_favorite' => $newValue]);
        $rowAfter = $npc->getById($id);
        $val = is_array($rowAfter)
            ? (coerceBoolean($rowAfter['npc_favorite'] ?? false) ? 1 : 0)
            : $newValue;
        echo json_encode(["ok"=>true, "favorite"=>$val]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Toggle lock (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_lock"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid id"]); exit; }
        $rowBefore = $npc->getById($id);
        $current = coerceBoolean($rowBefore['lock_profile'] ?? false) ? 1 : 0;
        $hasValue = array_key_exists('value', $_POST);
        $newValue = $hasValue
            ? (($_POST['value']==='1'||$_POST['value']===1||$_POST['value']===true) ? 1 : 0)
            : (1 - $current);
        $npc->update($id, ['lock_profile' => $newValue]);
        $rowAfter = $npc->getById($id);
        $val = is_array($rowAfter)
            ? (coerceBoolean($rowAfter['lock_profile'] ?? false) ? 1 : 0)
            : $newValue;
        echo json_encode(["ok"=>true, "locked"=>$val]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bulk delete unlocked NPCs except The Narrator (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["bulk_delete_npcs"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'Delete') { echo json_encode(["ok"=>false, "error"=>"Confirmation text mismatch"]); exit; }
        // Delete all unlocked NPCs except The Narrator.
        $sql = "WITH del AS (
                    DELETE FROM core_npc
                    WHERE (lock_profile IS NULL OR lock_profile = FALSE)
                      AND trim(lower(name)) <> 'the narrator'
                    RETURNING 1
                ) SELECT count(*) AS c FROM del";
        $row = $GLOBALS['db']->fetchOne($sql);
        $deleted = intval($row['c'] ?? 0);
        echo json_encode(["ok"=>true, "deleted"=>$deleted]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bulk switch NPC profile assignment by source profile (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["bulk_switch_profile"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'Switch') { echo json_encode(["ok"=>false, "error"=>"Confirmation text mismatch"]); exit; }

        $sourceProfileId = intval($_POST['source_profile_id'] ?? 0);
        $targetProfileId = intval($_POST['target_profile_id'] ?? 0);
        if ($sourceProfileId <= 0 || $targetProfileId <= 0) {
            echo json_encode(["ok"=>false, "error"=>"Invalid source or target profile"]);
            exit;
        }
        if ($sourceProfileId === $targetProfileId) {
            echo json_encode(["ok"=>false, "error"=>"Source and target profiles must be different"]);
            exit;
        }

        $includeLockedRaw = $_POST['include_locked'] ?? '';
        $includeLocked = (
            $includeLockedRaw === '1' ||
            $includeLockedRaw === 1 ||
            $includeLockedRaw === true ||
            $includeLockedRaw === 'true'
        );

        $sourceRow = $GLOBALS['db']->fetchOne("SELECT id, label FROM core_profiles WHERE id = {$sourceProfileId} LIMIT 1");
        $targetRow = $GLOBALS['db']->fetchOne("SELECT id, label FROM core_profiles WHERE id = {$targetProfileId} LIMIT 1");
        if (!is_array($sourceRow) || empty($sourceRow['id'])) {
            echo json_encode(["ok"=>false, "error"=>"Source profile not found"]);
            exit;
        }
        if (!is_array($targetRow) || empty($targetRow['id'])) {
            echo json_encode(["ok"=>false, "error"=>"Target profile not found"]);
            exit;
        }

        $baseWhere = "profile_id = {$sourceProfileId} and trim(lower(name)) <> 'the narrator'";
        $countRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS c FROM core_npc WHERE {$baseWhere}");
        $totalMatched = intval($countRow['c'] ?? 0);

        $skippedLocked = 0;
        if (!$includeLocked) {
            $skippedRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS c FROM core_npc WHERE {$baseWhere} AND COALESCE(lock_profile,FALSE)=TRUE");
            $skippedLocked = intval($skippedRow['c'] ?? 0);
        }

        $lockClause = $includeLocked ? "TRUE" : "COALESCE(lock_profile,FALSE)=FALSE";
        $sql = "WITH upd AS (
                    UPDATE core_npc
                    SET profile_id = {$targetProfileId}
                    WHERE {$baseWhere}
                      AND {$lockClause}
                    RETURNING 1
                )
                SELECT COUNT(*) AS c FROM upd";
        $row = $GLOBALS['db']->fetchOne($sql);
        $updated = intval($row['c'] ?? 0);

        echo json_encode([
            "ok" => true,
            "updated" => $updated,
            "total_matched" => $totalMatched,
            "skipped_locked" => $skippedLocked,
            "include_locked" => $includeLocked,
            "source_profile_id" => $sourceProfileId,
            "target_profile_id" => $targetProfileId,
            "source_profile_label" => (string)($sourceRow['label'] ?? ('Profile #'.$sourceProfileId)),
            "target_profile_label" => (string)($targetRow['label'] ?? ('Profile #'.$targetProfileId)),
        ]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Handle Delete
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $toDel = intval($_POST["id"] ?? 0);
        if ($toDel <= 0) {
            echo json_encode(["ok" => false, "error" => "Invalid id"]);
            exit;
        }
        $rowCheck = $npc->getById($toDel);
        if (!$rowCheck) {
            echo json_encode(["ok" => false, "error" => "NPC not found"]);
            exit;
        }
        if (coerceBoolean($rowCheck['lock_profile'] ?? false)) {
            echo json_encode(["ok" => false, "error" => "This NPC is locked and cannot be deleted"]);
            exit;
        }

        $npc->delete($toDel);
        echo json_encode(["ok" => true, "deleted" => $toDel]);
    } catch (Throwable $e) {
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET["delete"])) {
    $toDel = intval($_GET["delete"]);
    $rowCheck = $npc->getById($toDel);
    
    if ($rowCheck && coerceBoolean($rowCheck['lock_profile'] ?? false)) {
        header("Location: npc_master.php"); 
        exit; 
    }
    
    $npc->delete($toDel);
    header("Location: npc_master.php");
    exit;
}

// Handle Export NPC (download JSON)
if (isset($_GET["export"]) && is_numeric($_GET["export"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    
    $exportId = intval($_GET["export"]);
    $exportRow = $npc->getById($exportId);
    
    if (!$exportRow) {
        header("HTTP/1.1 404 Not Found");
        echo "NPC not found";
        exit;
    }
    
    // Build export data
    $exportData = [
        'export_version' => '1.0',
        'export_date' => date('c'),
        'npc_name' => $exportRow['npc_name'] ?? '',
        'npc_favorite' => coerceBoolean($exportRow['npc_favorite'] ?? false) ? 1 : 0,
        'lock_profile' => coerceBoolean($exportRow['lock_profile'] ?? false) ? 1 : 0,
        'prompt_head' => $exportRow['prompt_head'] ?? '',
        'npc_static_bio' => $exportRow['npc_static_bio'] ?? '',
        'world_knowledge_tags' => $exportRow['world_knowledge_tags'] ?? '',
        'emote_moods' => $exportRow['emote_moods'] ?? '',
        'personality' => $exportRow['personality'] ?? '',
        'relationships' => $exportRow['relationships'] ?? '',
        'occupation' => $exportRow['occupation'] ?? '',
        'appearance' => $exportRow['appearance'] ?? '',
        'skills' => $exportRow['skills'] ?? '',
        'speechstyle' => $exportRow['speechstyle'] ?? '',
        'goals' => $exportRow['goals'] ?? '',
        'voiceid' => $exportRow['voiceid'] ?? '',
        'gender' => $exportRow['gender'] ?? '',
        'race' => $exportRow['race'] ?? '',
        'dynamic_profile' => $exportRow['dynamic_profile'] ?? null,
        'base' => $exportRow['base'] ?? '',
        'core' => $exportRow['core'] ?? '',
        'tags' => $exportRow['tags'] ?? '',
        'metadata' => null,
        'extended_data' => null,
    ];
    
    // Parse JSON fields
    if (!empty($exportRow['metadata'])) {
        $tmp = json_decode((string)$exportRow['metadata'], true);
        if (is_array($tmp)) { $exportData['metadata'] = $tmp; }
    }
    if (!empty($exportRow['extended_data'])) {
        $tmp = json_decode((string)$exportRow['extended_data'], true);
        if (is_array($tmp)) { $exportData['extended_data'] = $tmp; }
    }
    
    $filename = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($exportRow['npc_name'] ?? 'npc')) . '_export.json';
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Import NPC (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["import_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    
    try {
        $importJson = $_POST['import_data'] ?? '';
        $targetId = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $newName = trim($_POST['new_name'] ?? '');
        
        $importData = json_decode($importJson, true);
        if (!is_array($importData)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON data']);
            exit;
        }
        
        // Build NPC data from import
        $npcData = [];
        $allowedFields = ['npc_favorite', 'lock_profile', 'prompt_head', 'npc_static_bio', 
            'world_knowledge_tags', 'emote_moods', 'personality', 'relationships', 
            'occupation', 'appearance', 'skills', 'speechstyle', 'goals', 'voiceid',
            'gender', 'race', 'dynamic_profile', 'base', 'core', 'tags'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $importData)) {
                $npcData[$field] = $importData[$field];
            }
        }
        
        // Handle JSON fields
        if (isset($importData['metadata']) && is_array($importData['metadata'])) {
            $npcData['metadata'] = json_encode($importData['metadata']);
        }
        if (isset($importData['extended_data']) && is_array($importData['extended_data'])) {
            $npcData['extended_data'] = json_encode($importData['extended_data']);
        }
        
        if ($targetId > 0) {
            // Import to existing NPC
            $existingNpc = $npc->getById($targetId);
            if (!$existingNpc) {
                echo json_encode(['ok' => false, 'error' => 'Target NPC not found']);
                exit;
            }
            
            // Don't overwrite the name when importing to existing NPC
            unset($npcData['npc_name']);
            
            $npc->update($targetId, $npcData);
            echo json_encode(['ok' => true, 'message' => 'Biography imported to existing NPC', 'id' => $targetId]);
        } else {
            // Create new NPC
            if ($newName !== '') {
                $npcData['npc_name'] = $newName;
            } elseif (!empty($importData['npc_name'])) {
                $npcData['npc_name'] = $importData['npc_name'];
            } else {
                echo json_encode(['ok' => false, 'error' => 'NPC name is required']);
                exit;
            }
            
            // Check if name already exists
            $existingByName = $npc->getByName($npcData['npc_name']);
            if ($existingByName) {
                echo json_encode(['ok' => false, 'error' => 'An NPC with this name already exists. Use "Import to Existing" option instead.']);
                exit;
            }
            
            $npcData['md5'] = md5($npcData['npc_name']);
            $newId = $npc->create($npcData);
            echo json_encode(['ok' => true, 'message' => 'New NPC created from import', 'id' => $newId]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch Data
$perPage = 12;
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
if ($page < 1) $page = 1;

// Filters and sorting
$q = trim($_GET['q'] ?? '');
$alpha = strtolower($_GET['alpha'] ?? 'asc');
if (!in_array($alpha, ['asc','desc'], true)) { $alpha = 'asc'; }
$profileIdFilter = isset($_GET['profile_id']) ? trim((string)$_GET['profile_id']) : '';
// New: checkbox filters
$favOnly = (isset($_GET['fav']) && $_GET['fav'] === '1');
$dynOnly = (isset($_GET['dyn']) && $_GET['dyn'] === '1');
$mtmOnly = (isset($_GET['mtm']) && $_GET['mtm'] === '1');

// Preload profiles for filter dropdown
$profileRows = $GLOBALS["db"]->fetchAll("SELECT id, label, metadata FROM core_profiles ORDER BY label ASC");
// Default to first profile id for new NPCs
$firstProfileId = '';
if (is_array($profileRows) && count($profileRows) > 0) {
    $firstProfileId = (string)($profileRows[0]['id'] ?? '');
}
// Preload profile connector mappings and LLM connector labels for modal summary
$profileConnRows = $GLOBALS["db"]->fetchAll(
    "SELECT
        id,
        response_connector,
        diary_connector,
        autochat_connector,
        middleterm_connector,
        backgroundlife_connector,
        dynamic_connector,
        relationship_connector,
        metadata
     FROM core_profiles
     ORDER BY id ASC"
);
$llmRows = $GLOBALS["db"]->fetchAll("SELECT id, COALESCE(NULLIF(name,''), model) AS label FROM core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(name,''), model)) ASC");
$profilesById = [];
foreach (($profileRows ?? []) as $pr) {
    $pid = (string)($pr['id'] ?? '');
    if ($pid !== '') $profilesById[$pid] = $pr['label'] ?? ('Profile #'.$pid);
}
$profileOptions = [];
foreach (($profileRows ?? []) as $pr) {
    $pid = (string)($pr['id'] ?? '');
    if ($pid === '') continue;
    $profileOptions[] = [
        'id' => $pid,
        'label' => (string)($pr['label'] ?? ('Profile #'.$pid)),
    ];
}
// Build profile metadata lookup for inherited settings
$profileMetaById = [];
foreach (($profileConnRows ?? []) as $prow) {
    $pid = (string)($prow['id'] ?? '');
    if ($pid === '') continue;
    $pmeta = [];
    try {
        if (!empty($prow['metadata'])) {
            $tmp = json_decode((string)$prow['metadata'], true);
            if (is_array($tmp)) $pmeta = $tmp;
        }
    } catch (Throwable $e) {}
    // Check for both string "1" and boolean true
    $dynVal = isset($pmeta['DYNAMIC_PROFILE_ENABLED']) ? $pmeta['DYNAMIC_PROFILE_ENABLED'] : null;
    $mtmVal = isset($pmeta['MIDDLE_TERM_MEMORY_ENABLED']) ? $pmeta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
    $blcVal = isset($pmeta['BACKGROUND_LIFE_COMMANDS']) ? $pmeta['BACKGROUND_LIFE_COMMANDS'] : null;
    $gpsVal = isset($pmeta['GPS_TRACK']) ? $pmeta['GPS_TRACK'] : null;
    
    $profileMetaById[$pid] = [
        'dyn' => ($dynVal === '1' || $dynVal === 1 || $dynVal === true),
        'mtm' => ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true),
        'blc' => ($blcVal === '1' || $blcVal === 1 || $blcVal === true),
        'gps' => ($gpsVal === '1' || $gpsVal === 1 || $gpsVal === true)
    ];
}
$profilesConnById = [];
foreach (($profileConnRows ?? []) as $prc) {
    $pid = (string)($prc['id'] ?? '');
    if ($pid !== '') $profilesConnById[$pid] = $prc;
}
$llmById = [];
foreach (($llmRows ?? []) as $lr) {
    $lid = (string)($lr['id'] ?? '');
    if ($lid !== '') $llmById[$lid] = $lr['label'] ?? ('Connector #'.$lid);
}

$where = "1=1";
if ($q !== ''){
    $qEsc = "%".$GLOBALS['db']->escape($q)."%";
    // Match by name primarily; include a few related fields
    $where .= " and (npc_name ilike '".$qEsc."' or coalesce(race,'') ilike '".$qEsc."' or coalesce(voiceid,'') ilike '".$qEsc."' or coalesce(refid,'') ilike '".$qEsc."' or coalesce(tags,'') ilike '".$qEsc."')";
}
if ($profileIdFilter !== ''){
    $where .= " and profile_id = ".intval($profileIdFilter);
}
// Apply favorites/dynamic/middle-term filters when checked
if ($favOnly) {
    $where .= " and coalesce(npc_favorite,0)=1";
}
if ($dynOnly) {
    $where .= " and coalesce(dynamic_profile,0)=1";
}
if ($mtmOnly) {
    // Prefer metadata override key, keep legacy extended_data compatibility.
    $where .= " and ((coalesce(metadata::text,'') ~ '\"MIDDLE_TERM_MEMORY_ENABLED\"\\s*:\\s*(true|1)') or (coalesce(extended_data::text,'') ~ '\"middle_term_enabled\"\\s*:\\s*(true|1)'))";
}

// Default: The Narrator first, then favorites, then alphabetical by name
$order = "order by (case when npc_name = 'The Narrator' then 0 else 1 end), coalesce(npc_favorite,0) desc, coalesce(gamets_last_updated,0) desc, lower(npc_name) ".$alpha.", id asc";

// Count with filters
$totalRows = $npc->countAll($where);
$totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
error_log("{$where} {$order} limit {$perPage} offset {$offset}");
$data = $npc->getAll("{$where} {$order} limit {$perPage} offset {$offset}");
$editItem = null;

if (isset($_GET["edit"])) {
    $editItem = $npc->getById($_GET["edit"]);
}

// Partial list renderer for AJAX refresh of grid and pagination
if (isset($_GET['list']) && $_GET['list'] === '1') {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="pagination">
      <div class="filter-inline">
        <div class="npc-filter-dropdown" style="position:relative;">
          <button type="button" id="npc_filter_btn" class="btn" style="margin-right:6px;">Filters</button>
          <div id="npc_filter_menu" class="npc-filter-menu" style="display:none; position:absolute; right:0; top:calc(100% + 6px); background:#2a2a2a; border:1px solid #4a4a4a; border-radius:8px; padding:8px; min-width:220px; box-shadow:0 6px 18px rgba(0,0,0,0.35); z-index:15;">
            <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_fav" <?= $favOnly?'checked':'' ?>> Favorites</label>
            <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_dyn" <?= $dynOnly?'checked':'' ?>> Dynamic profile</label>
            <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_mtm" <?= $mtmOnly?'checked':'' ?>> Middle-term memory</label>
          </div>
        </div>
        <input id="npc_search" type="text" placeholder="Search..." value="<?= htmlspecialchars($q) ?>" />
        <select id="npc_profile_filter" title="Filter by profile">
          <option value="">All Profiles</option>
          <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); ?>
            <option value="<?= htmlspecialchars($pid) ?>" <?= ($profileIdFilter!=='' && (string)$profileIdFilter===$pid)?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php $qbase = strtok($_SERVER['REQUEST_URI'], '?'); $make = function($p) use ($qbase){ return htmlspecialchars($qbase.'?page='.$p); }; ?>
      <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $make(1) ?>">First</a>
      <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $make(max(1,$page-1)) ?>">Prev</a>
      <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
        <?php if ($p === $page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="<?= $make($p) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
      <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $make(min($totalPages,$page+1)) ?>">Next</a>
      <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $make($totalPages) ?>">Last</a>
      <span style="border:none; background:transparent; color:#e6b76c;">Page <?= $page ?> / <?= $totalPages ?></span>
      <span style="border:none; background:transparent; color:#e6b76c;">Total <?= $totalRows ?></span>
      <button id="npc_create_btn" type="button" style="margin-left:8px;">+ Create NPC</button>
      <button id="npc_import_btn" type="button" title="Import NPC from JSON file">Import NPC</button>
      <button id="rel_bulk_build_btn" type="button" class="btn-rel-build" title="Build JSONB relationships from World Knowledge text data for all NPCs">Build Relationships</button>
      <button id="npc_bulk_switch_profile_btn" type="button" class="btn-rel-build" title="Switch all NPCs from one profile to another">Mass Switch Profile</button>
      <button id="npc_bulk_delete_btn" type="button" class="btn-danger" title="Delete all unlocked NPCs (excludes The Narrator and locked)">Delete All Profiles</button>
    </div>
    <div class="npc-grid">
    <?php foreach ($data as $row): ?>
        <?php 
        $pid = (string)($row['profile_id'] ?? ''); 
        $profLabel = $profilesById[$pid] ?? ''; 
        $metaTmp = []; 
        if (!empty($row['metadata'])) { 
            $tmp = json_decode((string)$row['metadata'], true); 
            if (is_array($tmp)) { $metaTmp = $tmp; } 
        } 
        $bountySummary = stobe_ui_format_bounty_summary(
            $row['bounty'] ?? 0,
            $row['bounty_payload'] ?? null,
            $metaTmp
        );
        $bountyAmountText = $bountySummary['amount_text'];
        $bountyDetailsText = $bountySummary['details_text'];
        $bountyBreakdownItems = is_array($bountySummary['breakdown_items'] ?? null) ? $bountySummary['breakdown_items'] : [];
        $bountyBreakdownExtra = intval($bountySummary['breakdown_extra'] ?? 0);
        $bountyLegacyDetails = trim(strval($bountySummary['legacy_details'] ?? ''));
        $extTmp = []; 
        if (!empty($row['extended_data'])) { 
            $tmp2 = json_decode((string)$row['extended_data'], true); 
            if (is_array($tmp2)) { $extTmp = $tmp2; } 
        }
        
        // Check for inherited profile settings
        $profileMeta = isset($profileMetaById[$pid]) ? $profileMetaById[$pid] : ['dyn'=>false,'mtm'=>false,'blc'=>false,'gps'=>false];
        
        // Dynamic Profile: check NPC override, otherwise inherit from profile
        $dynEnabled = $profileMeta['dyn']; // default to profile
        if (isset($row['dynamic_profile']) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
            $dynEnabled = coerceBoolean($row['dynamic_profile']);
        }
        
        // MTM: check metadata override, otherwise legacy extended_data, otherwise inherit profile
        $mtmEnabled = $profileMeta['mtm']; // default to profile
        $mtmOverride = stobeUiResolveMtmOverride($metaTmp, $extTmp);
        if ($mtmOverride !== null) {
            $mtmEnabled = $mtmOverride;
        }

        // Individual memory bank is NPC-only (no profile inheritance).
        $imbEnabled = stobeUiResolveIndividualMemoryEnabled($extTmp);
        
        // Background Life Commands: check extended_data override, otherwise inherit from profile
        $blcEnabled = $profileMeta['blc']; // default to profile
        if (array_key_exists('background_life_commands', $extTmp) && $extTmp['background_life_commands'] !== null && $extTmp['background_life_commands'] !== '') {
            $blcEnabled = !empty($extTmp['background_life_commands']);
        }
        
        // GPS Track: check metadata override, otherwise inherit from profile
        $gpsEnabled = $profileMeta['gps']; // default to profile
        if (array_key_exists('gps_track', $metaTmp) && $metaTmp['gps_track'] !== null && $metaTmp['gps_track'] !== '') {
            $gpsEnabled = !empty($metaTmp['gps_track']);
        }
        
        $tagsVal = trim((string)($row['tags'] ?? '')); 
        $tagsDisp = ($tagsVal === '') ? '' : $tagsVal; 
        ?>
        <div class="npc-card" id="npc_card_<?= htmlspecialchars($row["id"]) ?>" data-id="<?= htmlspecialchars($row["id"]) ?>">
            <div class="npc-title">
                <div class="npc-title-left"><?php 
                    // Use already-parsed $metaTmp to avoid re-decoding
                    $levelDisp = '';
                    if (isset($metaTmp['stats']) && is_array($metaTmp['stats']) && isset($metaTmp['stats']['level'])) {
                        $levelDisp = ' ('.intval($metaTmp['stats']['level']).')';
                    }
                ?><span class="npc-name"><?= htmlspecialchars(($row["npc_name"] ?? '').$levelDisp) ?></span> <?php $gch = gender_icon_char($row['gender'] ?? ''); $gcl = gender_icon_class($row['gender'] ?? ''); if ($gch!==''): ?><span class="npc-gender-icon <?= htmlspecialchars($gcl) ?>" title="<?= htmlspecialchars($row['gender'] ?? '') ?>"><?= $gch ?></span><?php endif; ?><?php if (!empty($dynEnabled)): ?><span class="npc-dyn-icon" title="Dynamic profile enabled">&#x267B;&#xFE0F;</span><?php endif; ?><?php if (!empty($mtmEnabled)): ?><span class="npc-mtm-icon" title="Middle-term memory enabled">&#x1F4C3;</span><?php endif; ?><?php if (!empty($imbEnabled)): ?><span class="npc-imb-icon" title="Individual memory bank enabled">&#x1F9E0;</span><?php endif; ?><?php if (!empty($blcEnabled)): ?><span class="npc-blc-icon" title="Background life commands enabled">&#x1F3AE;</span><?php endif; ?><?php if (!empty($gpsEnabled)): ?><span class="npc-gps-icon" title="GPS track enabled">&#x1F4CD;</span><?php endif; ?></div>
            <div class="npc-title-actions">
                    <?php if ($tagsDisp !== ''): ?>
                    <span class="npc-tags-top" title="<?= htmlspecialchars($tagsDisp) ?>"><?= htmlspecialchars($tagsDisp) ?></span>
                    <?php endif; ?>
                                <a class="btn btn-toggle <?= coerceBoolean($row["npc_favorite"] ?? false) ? "active" : "" ?>" href="#" data-favorite-id="<?= $row["id"] ?>" title="Toggle favorite"><?php echo coerceBoolean($row["npc_favorite"] ?? false) ? "&#9733;" : "&#9734;"; ?></a>
                                <a class="btn btn-toggle <?= coerceBoolean($row["lock_profile"] ?? false) ? "active" : "" ?>" href="#" data-lock-id="<?= $row["id"] ?>" title="Toggle lock - Locked profiles are protected from save rollback when loading saves"><?php echo coerceBoolean($row["lock_profile"] ?? false) ? "&#x1F512;" : "&#x1F513;"; ?></a>
                                <a class="btn btn-trash<?= coerceBoolean($row['lock_profile'] ?? false) ? ' disabled' : '' ?>" data-delete-id="<?= intval($row['id']) ?>" href="<?= coerceBoolean($row['lock_profile'] ?? false) ? '#' : ('npc_master.php?delete='.$row['id']) ?>" title="<?= coerceBoolean($row['lock_profile'] ?? false) ? 'Locked - cannot delete' : 'Delete' ?>">&#x1F5D1;&#xFE0F;</a>
                </div>
            </div>
            <div class="npc-divider"></div>
            <div class="npc-row">
                <div class="npc-fields">
                    <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"><?= htmlspecialchars($row["gender"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"><?= htmlspecialchars($row["race"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"><?= htmlspecialchars($row["voiceid"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"><?= htmlspecialchars($profLabel) ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Bounty:</span> <span class="npc-bounty"><?= htmlspecialchars($bountyAmountText) ?></span></div>
                    <?php if (count($bountyBreakdownItems) > 0 || $bountyLegacyDetails !== ''): ?>
                    <div class="npc-bounty-section">
                        <div class="npc-bounty-heading">Bounty Breakdown</div>
                        <div class="npc-bounty-breakdown">
                            <?php foreach ($bountyBreakdownItems as $bd): ?>
                            <?php
                                $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                                $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                                $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                            ?>
                            <div class="npc-bounty-item">
                                <div class="npc-bounty-item-top">
                                    <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                    <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                                </div>
                                <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($bountyBreakdownExtra > 0): ?>
                            <div class="npc-bounty-more">+<?= htmlspecialchars(strval($bountyBreakdownExtra)) ?> more faction(s)</div>
                            <?php endif; ?>
                            <?php if (count($bountyBreakdownItems) === 0 && $bountyLegacyDetails !== ''): ?>
                            <div class="npc-bounty-legacy"><?= htmlspecialchars($bountyLegacyDetails) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php $tagsVal = trim((string)($row["tags"] ?? "")); $tagsDisp = ($tagsVal === "") ? "none" : $tagsVal; ?>                </div>
                <div class="npc-right"></div>
                <div class="npc-right-warn">
                    <?php 
                    if ($row["gamets_last_updated"] != $LAST_INFOSAVE_EVENT) {
                        echo "<span title='This NPC is out of sync, this means current NPC sheet has been modified after last save. If you edit this NPC, changes will be lost if you reload a previous savegame. '>&#x26A0;&#xFE0F;</span>";
                    }

                    ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php
    exit;
}

// NPC history: return timeline of snapshots for a given NPC (Tamrielic time)
if (isset($_GET['history'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Invalid id']); exit; }
        // Skip the most recent snapshot (current state); show only historical entries
        $sel = "SELECT
                    h.history_id,
                    h.npc_id,
                    h.name AS npc_name,
                    CASE WHEN COALESCE(h.npc_favorite, FALSE) THEN 1 ELSE 0 END AS npc_favorite,
                    CASE WHEN COALESCE(h.lock_profile, FALSE) THEN 1 ELSE 0 END AS lock_profile,
                    h.prompt_head,
                    h.backstory AS npc_static_bio,
                    h.world_knowledge_tags,
                    h.emote_moods,
                    h.personality,
                    h.relationships,
                    h.occupation,
                    h.appearance,
                    h.skills,
                    h.speechstyle,
                    h.goals,
                    h.voiceid,
                    h.gender,
                    h.race,
                    COALESCE(NULLIF(h.metadata->>'storage_id', ''), NULLIF(h.metadata->>'refid', ''), '') AS refid,
                    h.profile_id,
                    CASE WHEN COALESCE(h.dynamic_profile, FALSE) THEN 1 ELSE 0 END AS dynamic_profile,
                    h.md5,
                    h.gamets_last_updated,
                    h.created,
                    ''::text AS core,
                    ''::text AS base,
                    h.tags
                FROM core_npc_master_history h
                WHERE h.npc_id = {$id}
                ORDER BY COALESCE(h.gamets_last_updated,0) DESC, h.created DESC, h.history_id DESC
                OFFSET 1";
        $rows = $GLOBALS['db']->fetchAll($sel) ?: [];
        $entries = [];
        foreach ($rows as $r){
            $g = isset($r['gamets_last_updated']) ? floatval($r['gamets_last_updated']) : 0.0;
            $tam = $g > 0 ? convert_gamets2skyrim_long_date2($g) : '';
            $greg = $g > 0 ? gamets2str_format_gregorian_date($g, 'Y-m-d H:i') : '';
            $created = (string)($r['created'] ?? '');
            $entries[] = [
                'history_id' => (int)($r['history_id'] ?? 0),
                'gamets' => $g,
                'when_tamrielic' => $tam,
                'when_gregorian' => $greg,
                'created' => $created,
                'fields' => [
                    'npc_name' => $r['npc_name'] ?? '',
                    'profile_id' => isset($r['profile_id']) ? (string)$r['profile_id'] : '',
                    'gender' => $r['gender'] ?? '',
                    'race' => $r['race'] ?? '',
                    'voiceid' => $r['voiceid'] ?? '',
                    'refid' => $r['refid'] ?? '',
                    'core' => $r['core'] ?? '',
                    'npc_static_bio' => $r['npc_static_bio'] ?? '',
                    'personality' => $r['personality'] ?? '',
                    'relationships' => $r['relationships'] ?? '',
                    'occupation' => $r['occupation'] ?? '',
                    'skills' => $r['skills'] ?? '',
                    'speechstyle' => $r['speechstyle'] ?? '',
                    'goals' => $r['goals'] ?? '',
                    'world_knowledge_tags' => $r['world_knowledge_tags'] ?? '',
                    'emote_moods' => $r['emote_moods'] ?? '',
                    'prompt_head' => $r['prompt_head'] ?? '',
                    'dynamic_profile' => coerceBoolean($r['dynamic_profile'] ?? false),
            'npc_favorite' => coerceBoolean($r['npc_favorite'] ?? false),
            'lock_profile' => coerceBoolean($r['lock_profile'] ?? false),
                    'tags' => $r['tags'] ?? '',
                    'base' => $r['base'] ?? ''
                ]
            ];
        }
        echo json_encode(['ok'=>true,'count'=>count($entries),'entries'=>$entries]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// Restore NPC from history (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["restore_from_history"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $historyId = intval($_POST['history_id'] ?? 0);
        if ($historyId <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid history_id"]); exit; }
        
        // Fetch the historical record
        $histRow = $GLOBALS['db']->fetchOne(
            "SELECT
                h.*,
                h.name AS npc_name,
                h.backstory AS npc_static_bio,
                COALESCE(NULLIF(h.metadata->>'storage_id', ''), NULLIF(h.metadata->>'refid', ''), '') AS refid,
                ''::text AS core,
                ''::text AS base
             FROM core_npc_master_history h
             WHERE h.history_id = {$historyId}"
        );
        if (!$histRow) { echo json_encode(["ok"=>false, "error"=>"Historical record not found"]); exit; }
        
        $npcId = intval($histRow['npc_id'] ?? 0);
        if ($npcId <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid NPC id in history"]); exit; }
        
        // Check if NPC is locked
        $current = $npc->getById($npcId);
        if ($current && coerceBoolean($current['lock_profile'] ?? false)) {
            echo json_encode(["ok"=>false, "error"=>"Cannot restore: NPC is locked"]);
            exit;
        }
        
        // Prepare data for update (copy relevant fields from history)
        $updateData = [
            'npc_name' => $histRow['npc_name'] ?? '',
            'profile_id' => $histRow['profile_id'] ?? null,
            'gender' => $histRow['gender'] ?? '',
            'race' => $histRow['race'] ?? '',
            'faction' => $histRow['faction'] ?? '',
            'voiceid' => $histRow['voiceid'] ?? '',
            'refid' => $histRow['refid'] ?? '',
            'core' => $histRow['core'] ?? '',
            'base' => $histRow['base'] ?? '',
            'npc_static_bio' => $histRow['npc_static_bio'] ?? '',
            'personality' => $histRow['personality'] ?? '',
            'relationships' => $histRow['relationships'] ?? '',
            'occupation' => $histRow['occupation'] ?? '',
            'appearance' => $histRow['appearance'] ?? '',
            'equipment' => $histRow['equipment'] ?? '',
            'inventory' => $histRow['inventory'] ?? '',
            'skills' => $histRow['skills'] ?? '',
            'speechstyle' => $histRow['speechstyle'] ?? '',
            'goals' => $histRow['goals'] ?? '',
            'world_knowledge_tags' => $histRow['world_knowledge_tags'] ?? '',
            'emote_moods' => $histRow['emote_moods'] ?? '',
            'prompt_head' => $histRow['prompt_head'] ?? '',
            'dynamic_profile' => coerceBoolean($histRow['dynamic_profile'] ?? false) ? 1 : 0,
            'tags' => $histRow['tags'] ?? '',
            'bounty' => $histRow['bounty'] ?? '{}',
            'limbs' => $histRow['limbs'] ?? '',
            'blood' => $histRow['blood'] ?? '',
            'hunger' => $histRow['hunger'] ?? '',
            'is_animal' => coerceBoolean($histRow['is_animal'] ?? false) ? 1 : 0,
            'is_slave' => coerceBoolean($histRow['is_slave'] ?? false) ? 1 : 0,
            'metadata' => $histRow['metadata'] ?? '',
            'extended_data' => $histRow['extended_data'] ?? '',
            'md5' => $histRow['md5'] ?? md5($histRow['npc_name'] ?? '')
        ];
        
        // Update the NPC
        $ok = $npc->update($npcId, $updateData);
        if ($ok === false) {
            echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?? 'Restore failed')]);
        } else {
            // Create a backup of the restored state
            $npc->backupNpcById($npcId);
            echo json_encode(["ok"=>true, "npc_id"=>$npcId]);
        }
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bio database: search existing templates (combined_bio_templates)
if (isset($_GET['bio_search'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    $search = trim((string)($_GET['search'] ?? ''));
    $letter = trim((string)($_GET['letter'] ?? ''));
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(50, max(1, intval($_GET['pageSize'] ?? 20)));
    $where = [];
    if ($search !== '') {
        $q = '%'.$GLOBALS['db']->escape($search).'%';
        $where[] = "(lower(npc_name) like lower('{$q}') or lower(core) like lower('{$q}'))";
    }
    if ($letter !== '' && preg_match('/^[A-Za-z]$/', $letter)) {
        $l = $GLOBALS['db']->escape(strtolower($letter));
        $where[] = "lower(npc_name) like '{$l}%'";
    }
    $whereSql = count($where) ? ('where '.implode(' and ', $where)) : '';
    $cntRow = $GLOBALS['db']->fetchOne("select count(*) as c from combined_bio_templates {$whereSql}");
    $total = intval($cntRow['c'] ?? 0);
    $offset = ($page - 1) * $pageSize;
    $rows = $GLOBALS['db']->fetchAll("select npc_name, core, voiceid, gender, race, refid, npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, coalesce(nullif(to_jsonb(cbt)->>'world_knowledge_tags',''), '') as world_knowledge_tags from combined_bio_templates cbt {$whereSql} order by lower(npc_name) asc limit {$pageSize} offset {$offset}");
    $items = [];
    foreach (($rows ?? []) as $r) {
        $extFields = ['npc_static_bio','personality','appearance','relationships','occupation','skills','speechstyle','goals'];
        $filled = 0; foreach ($extFields as $f) { $v = trim((string)($r[$f] ?? '')); if ($v !== '') $filled++; }
        $coreFull = (string)($r['core'] ?? '');
        if (function_exists('mb_strimwidth')) {
            $corePreview = mb_strimwidth($coreFull, 0, 160, 'N/A', 'UTF-8');
        } else {
            $corePreview = (strlen($coreFull) > 160) ? (substr($coreFull, 0, 157).'N/A') : $coreFull;
        }
        $items[] = [
            'npc_name' => $r['npc_name'] ?? '',
            'core_preview' => $corePreview,
            'voiceid' => $r['voiceid'] ?? '',
            'gender' => $r['gender'] ?? '',
            'race' => $r['race'] ?? '',
            'refid' => $r['refid'] ?? '',
            'extended_filled' => $filled
        ];
    }
    echo json_encode(['ok'=>true,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'items'=>$items]);
    exit;
}

// Bio database: detail of a specific template by npc_name
if (isset($_GET['bio_detail'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
    $esc = $GLOBALS['db']->escape($name);
    // Case-insensitive exact match on npc_name to tolerate capitalization differences
    $r = $GLOBALS['db']->fetchOne("select npc_name, core, voiceid, gender, race, refid, npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, coalesce(nullif(to_jsonb(cbt)->>'world_knowledge_tags',''), '') as world_knowledge_tags from combined_bio_templates cbt where lower(npc_name) = lower('{$esc}') limit 1");
    if (!$r) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$r]);
    exit;
}

// Import from bio: server builds row and creates/updates NPC
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_from_bio'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
        $includeCore = ($_POST['include_core'] ?? '') ? true : false;
        $includeExt  = ($_POST['include_extended'] ?? '1') ? true : false;
        $includeWorldKnowledge  = ($_POST['include_world_knowledge'] ?? '1') ? true : false;
        $includeVM   = ($_POST['include_voice_meta'] ?? '1') ? true : false;
        $profileId   = isset($_POST['profile_id']) && $_POST['profile_id']!=='' ? intval($_POST['profile_id']) : null;

        $esc = $GLOBALS['db']->escape($name);
        $r = $GLOBALS['db']->fetchOne("select npc_name, core, voiceid, gender, race, refid, npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, coalesce(nullif(to_jsonb(cbt)->>'world_knowledge_tags',''), '') as world_knowledge_tags from combined_bio_templates cbt where npc_name = '{$esc}' limit 1");
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'Template not found']); exit; }

        $data = [ 'npc_name' => $r['npc_name'] ?? $name ];
        if ($profileId !== null) $data['profile_id'] = $profileId;
        if ($includeCore) { $data['core'] = $r['core'] ?? null; }
        if ($includeExt) {
            foreach (['npc_static_bio','personality','appearance','relationships','occupation','skills','speechstyle','goals'] as $f) {
                $data[$f] = $r[$f] ?? null;
            }
        }
        if ($includeWorldKnowledge) { $data['world_knowledge_tags'] = $r['world_knowledge_tags'] ?? null; }
        if ($includeVM) {
            foreach (['voiceid','gender','race','refid'] as $f) { $data[$f] = $r[$f] ?? null; }
        }

        // Upsert by name
        $existing = $npc->getByName($data['npc_name']);
        if ($existing) {
            $data['md5'] = md5((string)$data['npc_name']);
            $ok = $npc->update((int)$existing['id'], $data);
            if ($ok === false) { echo json_encode(['ok'=>false,'error'=>'Update failed']); exit; }
            $newId = (int)$existing['id'];
        } else {
            $npc->create($data);
            // Fetch newly created row
            $row = $npc->getByName($data['npc_name']);
            $newId = (int)($row['id'] ?? 0);
        }
        if (!$newId) { echo json_encode(['ok'=>false,'error'=>'Insert failed']); exit; }
        $payload = $npc->getById($newId) ?: $data;
        echo json_encode(['ok'=>true,'id'=>$newId,'data'=>$payload]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}
?>

<?php if ($editItem): ?>
    <h2>Edit NPC (ID: <?= htmlspecialchars($editItem["id"]) ?>)</h2>
<?php endif; ?>

<?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ob_end_clean(); ?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<style>html,body{background:#2a2a2a;margin-bottom:50px;margin-right:5px;} main{background:#2a2a2a; padding:12px;} .form-container{background:#2a2a2a; border:1px solid #4a4a4a; border-radius:8px;}
.modal-inline-actions{display:flex; gap:6px; align-items:center; justify-content:flex-end; margin-bottom:8px;}
.modal-inline-actions .btn-toggle{background:transparent; border:none; padding:6px; color:#e9efff; font-size:22px; line-height:1; text-decoration:none; cursor:pointer;}
.modal-inline-actions .btn-toggle:hover{color: #e6b76c; text-decoration:none;}
.modal-inline-actions .btn-toggle.active{color:#ffd700; font-weight:700;}
.modal-inline-actions .btn-toggle[data-lock]{color:#e9efff;}
.modal-inline-actions .btn-toggle.active[data-lock]{color: #e6b76c;}
</style>
<form method="post" onsubmit='return false' style='display:block'>
<?php } else { ?>
<form method="post" onsubmit='return consolidation()' style='<?= $editItem!=null?"":"display:none"?>'>
<?php } ?>
    <style>
    .form-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px; }
    @media (max-width: 900px){ .form-grid { grid-template-columns: 1fr; } }
    .form-item { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
    .form-item label { font-weight:700; color:#e6b76c; }
    .form-item .hint { color:#e9efff; font-size:12px; line-height:1.35; }
    .form-item textarea { min-height:96px; }
    #prompt_head, #npc_static_bio, #appearance,
    #personality, #relationships, #occupation, #skills {
        min-height: 134px; /* 96px * 1.4 N/A 134 */
    }
    .form-item input[type="text"], .form-item textarea, .form-item select { background:#2a2a2a; color:#e9efff; border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; }
    /* Header-style checkbox next to label title */
    .label-with-toggle { display:flex; align-items:center; gap:10px; }
    .label-with-toggle input[type="checkbox"] { accent-color:#176529; transform: scale(1.8); transform-origin:center; cursor:pointer; }
    .span-2 { grid-column: 1 / -1; margin-bottom:12px; }
    .checkbox-inline { display:flex; align-items:center; gap:8px; }
    </style>
    <?php if ($editItem): ?>
        <input type="hidden" name="id" value="<?= htmlspecialchars($editItem["id"]) ?>">
    <?php endif; ?>

<?php $isPartial = (isset($_GET['partial']) && $_GET['partial']=='1'); $isFav = coerceBoolean($editItem['npc_favorite'] ?? false); $isLock = coerceBoolean($editItem['lock_profile'] ?? false); ?>
    <?php if ($isPartial): ?>
    <div class="modal-inline-actions">
        <p style="margin:0; color:#e6b76c ;">Tags:</p>
        <input type="text" id="modal_tags_input" name="tags" value="<?= htmlspecialchars($editItem['tags'] ?? '') ?>" placeholder="tags" style="max-width:240px; font-size:12px; padding:4px 6px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;" title="Tags help with searching and grouping" />
<a id="modal_fav_btn" class="btn btn-toggle<?= $isFav? ' active':'' ?>" href="#" title="Toggle favorite" data-favorite><?= $isFav? '&#9733;' : '&#9734;' ?></a>
<a id="modal_lock_btn" class="btn btn-toggle<?= $isLock? ' active':'' ?>" href="#" title="Toggle lock - Locked profiles are protected from save rollback when loading saves" data-lock><?= $isLock? '&#x1F512;' : '&#x1F513;' ?></a>
    </div>
    <?php
    // Render LLM summary container (will live-update via JS)
    $curPid = (string)($editItem['profile_id'] ?? '');
    $pc = ($curPid !== '' && isset($profilesConnById[$curPid])) ? $profilesConnById[$curPid] : null;
    $m = function($id) use ($llmById){ $k = (string)($id ?? ''); return $k !== '' && isset($llmById[$k]) ? $llmById[$k] : 'N/A'; };
    ?>
    <div id="profile_llm_summary" style="display:grid; grid-template-columns: 170px 1fr; gap:8px 10px; color:#cfd9ea; border:1px solid #4a4a4a; border-radius:8px; padding:10px; margin-bottom:8px;">
        <div style="color:#e6b76c; font-weight:700; white-space:nowrap;">Profile LLMs</div>
        <div style="display:grid; grid-template-columns: 120px 1fr; gap:4px 10px;">
            <div style="color:#9fb1c9;">Response</div><div><?= htmlspecialchars($pc ? $m($pc['response_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Diary</div><div><?= htmlspecialchars($pc ? $m($pc['diary_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Autochat</div><div><?= htmlspecialchars($pc ? $m($pc['autochat_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Memory</div><div><?= htmlspecialchars($pc ? $m($pc['middleterm_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Background</div><div><?= htmlspecialchars($pc ? $m($pc['backgroundlife_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Dynamic</div><div><?= htmlspecialchars($pc ? $m($pc['dynamic_connector'] ?? '') : 'N/A') ?></div>
            <div style="color:#9fb1c9;">Relationship</div><div><?= htmlspecialchars($pc ? $m($pc['relationship_connector'] ?? '') : 'N/A') ?></div>
        </div>
    </div>
    <script>
    (function(){
        const PROFILE_CONN = <?= json_encode($profilesConnById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        const LLM_LABELS = <?= json_encode($llmById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        function labelOf(id){ const k=String(id||''); return (k && LLM_LABELS[k]) ? String(LLM_LABELS[k]) : 'N/A'; }
        function renderProfileSummary(pid){
            const box = document.getElementById('profile_llm_summary'); if (!box) return;
            const pc = PROFILE_CONN[String(pid||'')] || null;
            const response = pc ? labelOf(pc.response_connector) : 'N/A';
            const diary = pc ? labelOf(pc.diary_connector) : 'N/A';
            const autochat = pc ? labelOf(pc.autochat_connector) : 'N/A';
            const memory = pc ? labelOf(pc.middleterm_connector) : 'N/A';
            const background = pc ? labelOf(pc.backgroundlife_connector) : 'N/A';
            const dynamic = pc ? labelOf(pc.dynamic_connector) : 'N/A';
            const relationship = pc ? labelOf(pc.relationship_connector) : 'N/A';
            box.innerHTML = ''
                + '<div style="color:#e6b76c; font-weight:700; white-space:nowrap;">Profile LLMs</div>'
                + '<div style="display:grid; grid-template-columns: 120px 1fr; gap:4px 10px;">'
                + '<div style="color:#9fb1c9;">Response</div><div>' + String(response || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Diary</div><div>' + String(diary || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Autochat</div><div>' + String(autochat || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Memory</div><div>' + String(memory || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Background</div><div>' + String(background || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Dynamic</div><div>' + String(dynamic || 'N/A') + '</div>'
                + '<div style="color:#9fb1c9;">Relationship</div><div>' + String(relationship || 'N/A') + '</div>'
                + '</div>';
        }
        document.addEventListener('DOMContentLoaded', function(){
            const sel = document.getElementById('profile_id');
            if (sel){ 
                sel.addEventListener('change', function(){ 
                    renderProfileSummary(this.value||''); 
                    updateInheritedSettings(this.value||'');
                }); 
                renderProfileSummary(sel.value||''); 
            }
        });
        
        // Profile metadata for inherited settings
        const PROFILE_META = <?= json_encode(array_map(function($pr){
            $meta = [];
            try {
                if (!empty($pr['metadata'])) {
                    $tmp = json_decode((string)$pr['metadata'], true);
                    if (is_array($tmp)) $meta = $tmp;
                }
            } catch (Throwable $e) {}
            $dynVal = isset($meta['DYNAMIC_PROFILE_ENABLED']) ? $meta['DYNAMIC_PROFILE_ENABLED'] : null;
            $mtmVal = isset($meta['MIDDLE_TERM_MEMORY_ENABLED']) ? $meta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
            $blcVal = isset($meta['BACKGROUND_LIFE_COMMANDS']) ? $meta['BACKGROUND_LIFE_COMMANDS'] : null;
            $gpsVal = isset($meta['GPS_TRACK']) ? $meta['GPS_TRACK'] : null;
            return [
                'id' => (string)($pr['id'] ?? ''),
                'dyn' => ($dynVal === '1' || $dynVal === 1 || $dynVal === true),
                'mtm' => ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true),
                'blc' => ($blcVal === '1' || $blcVal === 1 || $blcVal === true),
                'gps' => ($gpsVal === '1' || $gpsVal === 1 || $gpsVal === true)
            ];
        }, $profileConnRows ?? []), JSON_UNESCAPED_SLASHES) ?>;
        
        function updateInheritedSettings(profileId) {
            const profile = PROFILE_META.find(p => p.id === profileId);
            if (!profile) return;
            
            // Update dynamic_profile
            const dynCb = document.getElementById('dynamic_profile');
            if (dynCb) {
                dynCb.checked = profile.dyn;
                dynCb.setAttribute('data-profile-default', profile.dyn ? '1' : '0');
                const hint = dynCb.closest('.form-item').querySelector('.hint');
                if (hint) {
                    const base = 'Allow systems to evolve the profile based on gameplay events.';
                    hint.innerHTML = base + (profile.dyn ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '');
                }
            }
            
            // Update middle_term_enabled
            const mtmCb = document.getElementById('middle_term_enabled');
            if (mtmCb) {
                mtmCb.checked = profile.mtm;
                mtmCb.setAttribute('data-profile-default', profile.mtm ? '1' : '0');
                const hint = mtmCb.closest('.form-item').querySelector('.hint');
                if (hint) {
                    const base = 'Saves a list of recent events after every 10 memory summaries. Will be used for NPC context.';
                    hint.innerHTML = base + (profile.mtm ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '');
                }
            }
        }
    })();
    </script>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-item span-2">
            <label for="npc_name">NPC Name</label>
            <input type="text" id="npc_name" name="npc_name" placeholder="e.g. Aela the Huntress" value="<?= htmlspecialchars($editItem["npc_name"] ?? "") ?>">
            <small class="hint">The character's name. Must match their Kenshi in-game name!</small>
        </div>

        <div class="form-item">
            <label for="profile_id">Profile</label>
            <select id="profile_id" name="profile_id">
                <option value="">-- Select Profile --</option>
                <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); $sel = ((string)($editItem['profile_id'] ?? '') === $pid) ? ' selected' : ((empty($editItem) && $firstProfileId === $pid) ? ' selected' : ''); ?>
                    <option value="<?= htmlspecialchars($pid) ?>"<?= $sel ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="hint">Select which profile the NPC uses.</small>
        </div>

        <div class="form-item" style='<?= (isset($_GET['partial']) && $_GET['partial']=='1')?"display:none":"" ?>'>
            <label for="lock_profile" class="label-with-toggle">Lock Profile
                        <input type="checkbox" id="lock_profile" name="lock_profile" value="1" <?= coerceBoolean($editItem["lock_profile"] ?? false) ? "checked" : "" ?>>
            </label>
            <small class="hint">Prevents dynamic systems from modifying this NPC's profile.</small>
        </div>

        <div class="form-item" style='<?= (isset($_GET['partial']) && $_GET['partial']=='1')?"display:none":"" ?>'>
            <label for="npc_favorite" class="label-with-toggle">Favorite
                        <input type="checkbox" id="npc_favorite" name="npc_favorite" value="1" <?= coerceBoolean($editItem["npc_favorite"] ?? false) ? "checked" : "" ?>>
            </label>
            <small class="hint">Pin this NPC for quick access.</small>
        </div>

        <div class="form-item">
            <label for="gender">Gender</label>
            <input type="text" id="gender" name="gender" placeholder="female, male" value="<?= htmlspecialchars($editItem["gender"] ?? "") ?>">
            <small class="hint">Used for prompts.</small>
        </div>

        <div class="form-item">
            <label for="race">Race</label>
            <input type="text" id="race" name="race" placeholder="nord, dunmer, farm tool" value="<?= htmlspecialchars($editItem["race"] ?? "") ?>">
            <small class="hint">Lore-accurate race label used in prompts.</small>
        </div>

        <div class="form-item">
            <label for="world_knowledge_tags">World Knowledge Tags</label>
            <input type="text" id="world_knowledge_tags" name="world_knowledge_tags" placeholder="Comma-separated knowledge tags" value="<?= htmlspecialchars($editItem["world_knowledge_tags"] ?? "") ?>">
            <small class="hint">Used by World Knowledge systems for knowledge lookup restrictions.</small>
        </div>

        <div class="form-item">
            <label for="voiceid">Voice ID</label>
            <input type="text" id="voiceid" name="voiceid" placeholder="malenord" value="<?= htmlspecialchars($editItem["voiceid"] ?? "") ?>">
            <small class="hint">Voice ID for TTS.</small>
        </div>

        <div class="form-item">
            <label for="faction">Faction</label>
            <input type="text" id="faction" name="faction" placeholder="e.g. Holy Nation, UC, Shek Kingdom" value="<?= htmlspecialchars($editItem["faction"] ?? "") ?>">
            <small class="hint">Primary faction alignment used by prompts and rule matching.</small>
        </div>

        <?php
        // Check profile-level settings for these features
        $profileDynEnabled = false;
        $profileMtmEnabled = false;
        $currentProfileId = (string)(is_array($editItem) ? ($editItem['profile_id'] ?? '') : '');
        if ($currentProfileId !== '') {
            foreach (($profileConnRows ?? []) as $prow) {
                if ((string)($prow['id'] ?? '') === $currentProfileId) {
                    $pmeta = [];
                    try {
                        if (!empty($prow['metadata'])) {
                            $tmp = json_decode((string)$prow['metadata'], true);
                            if (is_array($tmp)) $pmeta = $tmp;
                        }
                    } catch (Throwable $e) {}
                    $dynVal = isset($pmeta['DYNAMIC_PROFILE_ENABLED']) ? $pmeta['DYNAMIC_PROFILE_ENABLED'] : null;
                    $mtmVal = isset($pmeta['MIDDLE_TERM_MEMORY_ENABLED']) ? $pmeta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
                    $profileDynEnabled = ($dynVal === '1' || $dynVal === 1 || $dynVal === true);
                    $profileMtmEnabled = ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true);
                    break;
                }
            }
        }
        
        // Dynamic Profile: check NPC override or fall back to profile default
        $dynChecked = $profileDynEnabled;
        $dynFromProfile = false;
        if (is_array($editItem) && isset($editItem['dynamic_profile']) && $editItem['dynamic_profile'] !== null && $editItem['dynamic_profile'] !== '') {
            // NPC has explicit value (override)
            $dynChecked = coerceBoolean($editItem['dynamic_profile']);
        } else {
            // No NPC override, inherit from profile
            $dynFromProfile = true;
        }
        
        // Middle Term Memory: check extended_data override or fall back to profile default
        $mtmChecked = $profileMtmEnabled;
        $mtmFromProfile = false;
        $imbChecked = false;
        try {
            $hasNpcOverride = false;
            if (is_array($editItem) && !empty($editItem['metadata'])) {
                $tmpMeta = json_decode((string)$editItem['metadata'], true);
                if (is_array($tmpMeta) && array_key_exists('MIDDLE_TERM_MEMORY_ENABLED', $tmpMeta) && $tmpMeta['MIDDLE_TERM_MEMORY_ENABLED'] !== null && $tmpMeta['MIDDLE_TERM_MEMORY_ENABLED'] !== '') {
                    $mtmChecked = coerceBoolean($tmpMeta['MIDDLE_TERM_MEMORY_ENABLED']);
                    $hasNpcOverride = true;
                }
            }
            if (is_array($editItem) && !empty($editItem['extended_data'])) {
                $tmpEd = json_decode((string)$editItem['extended_data'], true);
                if (is_array($tmpEd) && array_key_exists('middle_term_enabled', $tmpEd) && $tmpEd['middle_term_enabled'] !== null && $tmpEd['middle_term_enabled'] !== '') {
                    $mtmChecked = coerceBoolean($tmpEd['middle_term_enabled']);
                    $hasNpcOverride = true;
                }
                if (is_array($tmpEd)) {
                    $imbChecked = stobeUiResolveIndividualMemoryEnabled($tmpEd);
                }
            }
            if (!$hasNpcOverride) {
                $mtmFromProfile = true;
            }
        } catch (Throwable $e) { }
        
        ?>
        <div class="form-item">
            <label for="dynamic_profile" class="label-with-toggle">Dynamic Profile
                <input type="hidden" name="dynamic_profile" value="0">
                <input type="checkbox" id="dynamic_profile" name="dynamic_profile" value="1" <?= $dynChecked ? "checked" : "" ?> data-profile-default="<?= $profileDynEnabled ? '1' : '0' ?>">
            </label>
            <small class="hint">Allow systems to evolve the profile based on gameplay events.<?= $dynFromProfile ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '' ?></small>
        </div>

        <div class="form-item">
            <label for="middle_term_enabled" class="label-with-toggle">Middle Term Memory
                <input type="checkbox" id="middle_term_enabled" name="middle_term_enabled" value="1" <?= $mtmChecked ? "checked" : "" ?> data-profile-default="<?= $profileMtmEnabled ? '1' : '0' ?>">
            </label>
            <small class="hint">Saves a list of recent events after every 10 memory summaries. Will be used for NPC context.<?= $mtmFromProfile ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '' ?></small>
        </div>

        <div class="form-item">
            <label for="individual_memory_enabled" class="label-with-toggle">Individual Memory Bank
                <input type="hidden" name="individual_memory_enabled" value="0">
                <input type="checkbox" id="individual_memory_enabled" name="individual_memory_enabled" value="1" <?= $imbChecked ? "checked" : "" ?>>
            </label>
            <small class="hint">Enable NPC-scoped memory summaries for this character only. Scoped summaries are generated from conversations where this NPC is present.</small>
        </div>

        <div class="form-item span-2">
            <label for="prompt_head">Prompt Head Override</label>
            <textarea id="prompt_head" name="prompt_head" placeholder="High-level system instructions injected before the core."><?= htmlspecialchars($editItem["prompt_head"] ?? "") ?></textarea>
            <small class="hint">System preamble inserted before other sections. Do not worry if it is empty, as will pull from global settings prompt head.</small>
        </div>

        <div class="form-item span-2">
            <label for="npc_static_bio">Backstory</label>
            <textarea id="npc_static_bio" name="npc_static_bio" placeholder="Fixed background, history, and facts."><?= htmlspecialchars($editItem["npc_static_bio"] ?? "") ?></textarea>
            <small class="hint">Historical facts and background information.</small>
        </div>

        <div class="form-item span-2">
            <label for="appearance">Appearance</label>
            <textarea id="appearance" name="appearance" placeholder="Physical appearance."><?= htmlspecialchars($editItem["appearance"] ?? "") ?></textarea>
            <small class="hint">Physical appearance. Keep it limited to character cosmetics, not equipment.</small>
        </div>

        <div class="dynamic-profile-section span-2">
        <div class="form-item">
            <label for="personality">Personality</label>
            <textarea id="personality" name="personality" placeholder="Personality traits and speaking characteristics."><?= htmlspecialchars($editItem["personality"] ?? "") ?></textarea>
            <small class="hint">Traits and quirks that guide tone and behavior.</small>
        </div>

        

        <textarea id="relationships" name="relationships" style="display:none;"><?= htmlspecialchars($editItem["relationships"] ?? "") ?></textarea>

        <?php if (file_exists(__DIR__."/../ext/relationship_system/relationship_editor.php")) {
            include(__DIR__."/../ext/relationship_system/relationship_editor.php");
        } ?>

        <div class="form-item">
            <label for="occupation">Occupation</label>
            <textarea id="occupation" name="occupation" placeholder="Role, job, affiliations."><?= htmlspecialchars($editItem["occupation"] ?? "") ?></textarea>
            <small class="hint">Primary role or job. Include relevant guilds or factions.</small>
        </div>

        <div class="form-item">
            <label for="skills">Skills</label>
            <textarea id="skills" name="skills" placeholder="Strengths, abilities, and specialties."><?= htmlspecialchars($editItem["skills"] ?? "") ?></textarea>
            <small class="hint">Highlight notable competencies of the NPC.</small>
        </div>

        

        <div class="form-item">
            <label for="speechstyle">Speech Style</label>
            <textarea id="speechstyle" name="speechstyle" placeholder="Dialect, cadence, verbal tics."><?= htmlspecialchars($editItem["speechstyle"] ?? "") ?></textarea>
            <small class="hint">How the NPC speaks their dialogue.</small>
        </div>

        <div class="form-item">
            <label for="goals">Goals</label>
            <textarea id="goals" name="goals" placeholder="Short and long-term objectives."><?= htmlspecialchars($editItem["goals"] ?? "") ?></textarea>
            <small class="hint">Motivations and goals for the NPC.</small>
        </div>


        <div class="form-item span-2">
            <label for="emote_moods">Emote Moods Override</label>
            <textarea id="emote_moods" name="emote_moods" placeholder="Allowed mood/emote set (comma-separated).">
            <?= htmlspecialchars($editItem["emote_moods"] ?? "") ?></textarea>
            <small class="hint">Whitelist of mood/emote cues the NPC may use (e.g., calm, angry, playful). <strong>Overrides</strong> the global EMOTEMOODS setting. Leave empty to use global default.</small>
        </div>

        <?php
        $mtmLatest = '';
        try {
            if (!empty($editItem['extended_data'])){
                $ed = json_decode((string)$editItem['extended_data'], true);
                if (is_array($ed) && !empty($ed['middle_term_memory']) && is_array($ed['middle_term_memory'])){
                    $arr = array_values($ed['middle_term_memory']);
                    if (!empty($arr)) { $mtmLatest = (string)end($arr); }
                }
            }
        } catch (Throwable $e) { $mtmLatest = ''; }
        ?>
        <div class="form-item span-2">
            <label for="middle_term_latest">Recent Middle Term Memory</label>
            <textarea id="middle_term_latest" name="middle_term_latest" placeholder="No middle term memory yet."><?= htmlspecialchars($mtmLatest) ?></textarea>
            <small class="hint">Edit the most recent middle term memory entry. Changes are saved to Extended Data ? middle_term_memory (latest).</small>
        </div>

        <?php
        $editMetaTmp = [];
        try {
            if (!empty($editItem['metadata'])) {
                $tmpMeta = json_decode((string)$editItem['metadata'], true);
                if (is_array($tmpMeta)) {
                    $editMetaTmp = $tmpMeta;
                }
            }
        } catch (Throwable $e) {
            $editMetaTmp = [];
        }
        $editBountySummary = stobe_ui_format_bounty_summary(
            $editItem['bounty'] ?? 0,
            $editItem['bounty_payload'] ?? null,
            $editMetaTmp
        );
        $editBountyAmountText = trim(strval($editBountySummary['amount_text'] ?? '0'));
        $editBountyBreakdownItems = is_array($editBountySummary['breakdown_items'] ?? null)
            ? $editBountySummary['breakdown_items']
            : [];
        $editBountyBreakdownExtra = intval($editBountySummary['breakdown_extra'] ?? 0);
        $editBountyLegacyDetails = trim(strval($editBountySummary['legacy_details'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-bounty-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;" open>
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Current Bounty (Read Only)</summary>
                <small class="hint">Imported from the latest in-game snapshot. This section is informational and not editable here.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <div class="npc-line"><span class="npc-muted">Total:</span> <span class="npc-bounty"><?= htmlspecialchars($editBountyAmountText !== '' ? $editBountyAmountText : '0') ?></span></div>
                    <?php if (count($editBountyBreakdownItems) > 0 || $editBountyLegacyDetails !== ''): ?>
                    <div class="npc-bounty-section" style="margin-top:8px;">
                        <div class="npc-bounty-heading">Bounty Breakdown</div>
                        <div class="npc-bounty-breakdown">
                            <?php foreach ($editBountyBreakdownItems as $bd): ?>
                            <?php
                                $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                                $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                                $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                            ?>
                            <div class="npc-bounty-item">
                                <div class="npc-bounty-item-top">
                                    <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                    <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                                </div>
                                <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($editBountyBreakdownExtra > 0): ?>
                            <div class="npc-bounty-more">+<?= htmlspecialchars(strval($editBountyBreakdownExtra)) ?> more faction(s)</div>
                            <?php endif; ?>
                            <?php if (count($editBountyBreakdownItems) === 0 && $editBountyLegacyDetails !== ''): ?>
                            <div class="npc-bounty-legacy"><?= htmlspecialchars($editBountyLegacyDetails) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // REINSERT Skills, Equipment, Stats, Inventory sections here (below Middle Term Memory)
        // Read metadata once
        $metaRaw = '';
        $metaObj = [];
        try {
            if (is_array($editItem ?? null) && !empty($editItem['metadata'])) {
                $metaRaw = (string)$editItem['metadata'];
                if ($metaRaw !== '') { $metaObj = json_decode($metaRaw, true) ?: []; }
            }
        } catch (Throwable $e) { $metaObj = []; }
        // Skills (from core_npc.skills column)
        $skillsText = trim((string)($editItem['skills'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-skills-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Skills</summary>
                <small class="hint">These will also be used for Skill context.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($skillsText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($skillsText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No in-game skills found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // Equipment (from core_npc.equipment column)
        $equipmentText = trim((string)($editItem['equipment'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-equipment-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Current Equipment</summary>
                <small class="hint">Equipment NPC had when first added to AI system.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($equipmentText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($equipmentText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No equipment data found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // Inventory (from core_npc.inventory column)
        $inventoryText = trim((string)($editItem['inventory'] ?? ''));
        $inventoryUpdated = isset($metaObj['inventory_updated']) ? $metaObj['inventory_updated'] : null;
        ?>
        <div class="form-item span-2">
            <details class="metadata-inventory-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">
                    Inventory
                    <?php if ($inventoryUpdated): ?>
                        <span style="color:#999; font-weight:400; font-size:12px;">
                            Last updated: <?= date('Y-m-d H:i:s', $inventoryUpdated) ?>
                        </span>
                    <?php endif; ?>
                </summary>
                <small class="hint">NPC inventory updated in real-time as items are added/removed.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($inventoryText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($inventoryText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No inventory data found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <div class="form-item span-2">
            <label for="metadata">Metadata (JSON)</label>
            <textarea id="metadata" name="metadata" placeholder="{}"><?= htmlspecialchars($editItem["metadata"] ?? "") ?></textarea>
            <small class="hint">General NPC metadata used by systems.</small>
        </div>

        <div class="form-item span-2">
            <label for="extended_data">Setting Overrides</label>
            <small class="hint">Override global and profile settings for this specific NPC. Changes here take precedence over all other configurations.</small>
            <textarea id="extended_data" name="extended_data" placeholder="{}"><?= htmlspecialchars($editItem["extended_data"] ?? "") ?></textarea>
        </div>
    </div>

    <?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ?>
        <button type="button" id="npc_modal_save" class="btn-save" style="display:none"><?= $editItem ? "Update" : "Create" ?></button>
        <script>
        (function(){
            const save = document.getElementById('npc_modal_save');
            if (!save) return;
            save.addEventListener('click', async function(){
                let form = save.closest('form');
                
                // Sync extended data overrides from visual UI
                try {
                  if (typeof window.syncExtendedDataOverrides === 'function') {
                    window.syncExtendedDataOverrides();
                  }
                } catch(_e) { console.error('Failed to sync extended data overrides:', _e); }
                
                // Sync feature checkboxes into extended_data (only save if differs from profile default)
                try {
                  const mtm = form.querySelector('#middle_term_enabled');
                  const dyn = form.querySelector('#dynamic_profile');
                  const imb = form.querySelector('#individual_memory_enabled');
                  if (form.metadata){
                    let metadataObj = {};
                    try { metadataObj = JSON.parse(String(form.metadata.value||'')||'{}')||{}; } catch(_e){ metadataObj = {}; }

                    if (mtm) {
                      const profileDefault = mtm.getAttribute('data-profile-default') === '1';
                      if (mtm.checked !== profileDefault) {
                        metadataObj.MIDDLE_TERM_MEMORY_ENABLED = mtm.checked ? 1 : 0;
                      } else {
                        delete metadataObj.MIDDLE_TERM_MEMORY_ENABLED;
                      }
                    }

                    if (dyn) {
                      const profileDefault = dyn.getAttribute('data-profile-default') === '1';
                      if (dyn.checked !== profileDefault) {
                        metadataObj.DYNAMIC_PROFILE_ENABLED = dyn.checked ? 1 : 0;
                      } else {
                        delete metadataObj.DYNAMIC_PROFILE_ENABLED;
                      }
                    }
                    form.metadata.value = JSON.stringify(metadataObj);
                  }

                  if (form.extended_data){
                    let obj = {};
                    try { obj = JSON.parse(String(form.extended_data.value||'')||'{}')||{}; } catch(_e){ obj = {}; }
                    
                    // Keep legacy extended_data key removed; metadata is source of truth.
                    delete obj.middle_term_enabled;
                    if (imb) {
                      if (imb.checked) {
                        obj.individual_memory_enabled = 1;
                      } else {
                        delete obj.individual_memory_enabled;
                      }
                    }
                    form.extended_data.value = JSON.stringify(obj);
                  }
                  
                  // Dynamic Profile: handled separately in form POST
                  if (dyn) {
                    const profileDefault = dyn.getAttribute('data-profile-default') === '1';
                    const dynHidden = form.querySelector('input[type="hidden"][name="dynamic_profile"]');
                    if (dyn.checked !== profileDefault) {
                      // Override: set explicit value
                      if (dynHidden) dynHidden.value = dyn.checked ? '1' : '0';
                      dyn.value = dyn.checked ? '1' : '0';
                    } else {
                      // Inherit: send empty/null to clear override
                      if (dynHidden) dynHidden.value = '';
                      dyn.value = '';
                    }
                  }
                } catch(_e){ console.error('Failed to sync feature toggles:', _e); }

                // Sync edited middle_term_latest back into extended_data JSON
                /*
                try {
                  const mtmLatest = form.querySelector('#middle_term_latest');
                  if (mtmLatest && form.extended_data){
                    let obj = {};
                    try { obj = JSON.parse(String(form.extended_data.value||'')||'{}')||{}; } catch(_e){ obj = {}; }
                    const editedVal = String(mtmLatest.value||'').trim();
                    if (editedVal !== '') {
                      if (!Array.isArray(obj.middle_term_memory)) {
                        obj.middle_term_memory = [];
                      }
                      if (obj.middle_term_memory.length > 0) {
                        obj.middle_term_memory[obj.middle_term_memory.length - 1] = editedVal;
                      } else {
                        obj.middle_term_memory.push(editedVal);
                      }
                    }
                    form.extended_data.value = JSON.stringify(obj);
                    
                  }
                } catch(_e){ console.error('Failed to sync middle term memory:', _e); }
                */
                if (form.metadata!=undefined && typeof window.jsonEditor !== 'undefined' && jsonEditor && typeof jsonEditor.get === 'function') {
                  const content = jsonEditor.get();

                  try {
                    form.metadata.value = JSON.stringify(content.json, null, 0);
                    console.log("JSON editor values copied to form:", content.json);
                  } catch (idontcare) {}
        
                  // allow empty metadata without confirmation
                }

                const fd = new FormData(form);
                fd.append('inline_update_npc','1');
                if (!fd.has('id') && <?= json_encode(!empty($editItem['id'])) ?>){ fd.append('id', <?= json_encode($editItem['id'] ?? '') ?>); }
                const res = await fetch('npc_master.php', { method:'POST', body: fd });
                let json={}; try{ json=await res.json(); } catch(_e){}
                if (json && json.ok){
                    const payload = {};
                    form.querySelectorAll('input,textarea,select').forEach(el=>{ const n=el.name; if (!n) return; if (el.type==='checkbox'){ payload[n]=el.checked?1:0; } else { payload[n]=el.value; } });
                    // Ensure header tags input is captured
                    try { const ti = document.getElementById('modal_tags_input'); if (ti) payload['tags'] = ti.value; } catch(_){ }
                    const newId = json.id || payload.id || <?= json_encode($editItem['id'] ?? '') ?>;
                    payload.id = newId;
                    window.parent.postMessage({ type:'npc_saved', id: newId, data: payload }, '*');
                } else {
                    alert('Save failed: '+((json && json.error) ? json.error : res.status));
                }
            });
        })();
        </script>
        <script>
        (function(){
            const favBtn = document.getElementById('modal_fav_btn');
            const lockBtn = document.getElementById('modal_lock_btn');
            const lockField = document.getElementById('lock_profile');
            const idVal = <?= json_encode($editItem['id'] ?? '') ?>;
            if (favBtn && idVal){
                favBtn.addEventListener('click', async function(e){
                    e.preventDefault();
                    try{
                        const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', idVal);
                        const res = await fetch('npc_master.php', { method:'POST', body: fd });
                        let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.favorite||0)===1; favBtn.classList.toggle('active', active); favBtn.textContent = active ? '\u2605' : '\u2606'; }
                    }catch(_e){}
                });
            }
            if (lockBtn && idVal){
                lockBtn.addEventListener('click', async function(e){
                    e.preventDefault();
                    try{
                        const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', idVal);
                        const res = await fetch('npc_master.php', { method:'POST', body: fd });
                        let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){
                const active = Number(json.locked||0)===1;
                lockBtn.classList.toggle('active', active);
                lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
                if (lockField) {
                    lockField.checked = active;
                }
            }
                    }catch(_e){}
                });
            }
            if (lockField && lockBtn){
                lockField.addEventListener('change', function(){
                    const active = !!lockField.checked;
                    lockBtn.classList.toggle('active', active);
                    lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
                });
            }
        })();
        </script>
    <?php } else { ?>
        <button type="submit" name="<?= $editItem ? "update" : "create" ?>" class="btn-save"><?= $editItem ? "Update" : "Create" ?></button>
    <?php } ?>
</form>
<?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ?>
    <?php include(__DIR__."/tmpl/metadata_json_editor.php"); ?>
    </div>
    <?php exit; } ?>
</div>

<style>
.npc-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:14px; }
@media (max-width: 1400px){ .npc-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 1100px){ .npc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 720px){ .npc-grid { grid-template-columns: 1fr; } }
.npc-card { 
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98)); 
    border: 1px solid #3a3a3a; 
    border-radius: 10px; 
    padding: 16px; 
    display: flex; 
    flex-direction: column; 
    gap: 10px; 
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                inset 0 1px rgba(255, 255, 255, 0.03); 
    transition: all 0.2s ease; 
    cursor: pointer; 
}
.npc-card:hover { 
    transform: translateY(-2px); 
    background: linear-gradient(180deg, rgba(48, 48, 48, 0.95), rgba(40, 40, 40, 0.98)); 
    border-color: #4a4a4a;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25),
                inset 0 1px rgba(255, 255, 255, 0.05);
}
.npc-title { font-weight:800; color:#e9efff; font-size:18px; text-align:center; letter-spacing:0.3px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
.npc-title-left { flex:1 1 auto; text-align:left; }
.npc-title-actions { display:flex; align-items:center; gap:6px; flex:0 0 auto; }
.npc-gender-icon { margin-left:6px; opacity:0.9; }
.npc-gender-icon.gender-female { color:#ff72d2; }
.npc-gender-icon.gender-male { color:#72a0ff; }
.npc-gender-icon.gender-nb { color:#ffd166; }
.npc-dyn-icon { margin-left:6px; color:#65d46e; opacity:0.95; }
.npc-mtm-icon { margin-left:6px; color:#9fb1ff; opacity:0.95; }
.npc-imb-icon { margin-left:6px; color:#70d4d4; opacity:0.95; }
.npc-blc-icon { margin-left:6px; color:#8db4e2; opacity:0.95; }
.npc-gps-icon { margin-left:6px; color:#ff6b6b; opacity:0.95; }
.npc-divider { height:1px; background: linear-gradient(90deg, transparent, rgba(230, 183, 108, 0.3) 50%, transparent); margin:6px 0 10px; }
.npc-fields { display:flex; flex-direction:column; gap:8px; }
.npc-line { color:#e0e0e0; font-size:13px; line-height:1.35; }
.npc-muted { color:#e6b76c; }
.npc-bounty-section {
    margin-top: 4px;
    padding: 8px 10px;
    border: 1px solid rgba(230, 183, 108, 0.22);
    border-radius: 8px;
    background: rgba(230, 183, 108, 0.08);
}
.npc-bounty-heading {
    color: #f0c880;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
}
.npc-bounty-breakdown { display:flex; flex-direction:column; gap:6px; }
.npc-bounty-item { border-bottom: 1px dashed rgba(230, 183, 108, 0.2); padding-bottom: 6px; }
.npc-bounty-item:last-child { border-bottom: none; padding-bottom: 0; }
.npc-bounty-item-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.npc-bounty-faction { color:#e9efff; font-size:12px; font-weight:600; }
.npc-bounty-amount { color:#ffd9a3; font-size:12px; white-space:nowrap; }
.npc-bounty-crimes { color:#d8c6aa; font-size:11px; line-height:1.35; margin-top:3px; }
.npc-bounty-more { color:#b2c0d8; font-size:11px; }
.npc-bounty-legacy { color:#d8c6aa; font-size:11px; line-height:1.35; }
.npc-actions { display:flex; gap:8px; margin-top:6px; justify-content:center; }
.npc-actions .btn { padding:6px 10px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff; text-decoration:none; cursor:pointer; }
.npc-actions .btn:hover { background:#3a3a3a; }
.npc-actions .btn-danger { background:#5a2a2a; border-color:#7a3a3a; }
.npc-actions .btn-danger:hover { background:#6a2a2a; }
.npc-title-actions a { text-decoration:none; border:none; }
.npc-title-actions a:hover { text-decoration:none; }
.btn-toggle { background:transparent; border:none; padding:6px; color:#e9efff; font-size:22px; line-height:1; text-decoration:none; transition: color .15s ease, text-shadow .15s ease; }
/* Navbar-like glow only for lock icon on cards */
.btn-toggle[data-lock-id]:hover,
.btn-toggle[data-lock-id]:focus-visible { color: #e6b76c; background:transparent; text-decoration:none; text-shadow: 0 0 6px rgba(230, 183, 108, 0.6), 0 0 12px rgba(230, 183, 108, 0.35); }
.btn-toggle[data-favorite-id]:hover,
.btn-toggle[data-favorite-id]:focus-visible { color:#ffd700; text-shadow: 0 0 8px rgba(255, 215, 0, 0.7), 0 0 14px rgba(255, 215, 0, 0.45); }
.btn-toggle.active { color: #e6b76c; font-weight:700; text-decoration:none; }
.btn-toggle.active[data-favorite-id] { color:#ffd700; }
.btn-trash { background:transparent; border:none; padding:6px; color:#e9efff; font-size:20px; line-height:1; text-decoration:none; transition: color .15s ease, text-shadow .15s ease; }
.btn-trash:hover, .btn-trash:focus-visible { color:#ff6b6b; text-shadow: 0 0 6px rgba(255, 107, 107, 0.7), 0 0 12px rgba(255, 107, 107, 0.45); }
.npc-tags-label { font-size:11px; color:#9fb1c9; margin-right:4px; }
.npc-tags-top { font-size:11px; color:#9fb1c9; border:1px solid #4a4a4a; border-radius:999px; padding:2px 6px; max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.npc-row { display:flex; gap:10px; align-items:flex-start; }
.npc-right { margin-left:auto; flex:0 0 auto; }
@media (max-width: 720px){ .npc-right { display:none; } }
/* Dynamic profile grouping */
.dynamic-profile-section { 
    border:1px solid #3a3a3a; 
    border-radius:8px; 
    padding:12px; 
    margin:10px 0; 
    background: linear-gradient(135deg, rgba(26, 26, 26, 0.8), rgba(32, 32, 32, 0.6)); 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
}
.dynamic-profile-section .section-title { 
    font-weight:700; 
    color:#e6b76c; 
    margin-bottom:8px; 
    font-size: 1.05em;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
.dynamic-profile-section > .form-item { margin-bottom:10px; }
</style>
<style>
/* Modal styling aligned with World Knowledge edit modal */
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:10000; align-items:center; justify-content:center; overflow-y:auto; padding:20px 0; }
.modal-container { 
    position:relative; 
    top:auto; 
    left:auto; 
    transform:none; 
    max-width:1200px; 
    width:95%; 
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98)); 
    border: 1px solid #3a3a3a; 
    border-radius: 12px; 
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}
.modal-header { 
    display:flex; 
    justify-content:space-between; 
    align-items:center; 
    padding:16px 18px; 
    border-bottom: 1px solid rgba(230, 183, 108, 0.2); 
    background: rgba(42, 42, 42, 0.95); 
    position:sticky; 
    top:0; 
    z-index:2; 
    border-radius: 12px 12px 0 0;
}
.modal-title { 
    margin:0; 
    font-weight:700; 
    color: #e6b76c; 
    font-family: 'MagicCards', serif; 
    word-spacing: 6px; 
    font-size: 1.4em;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
.modal-body { 
    max-height:calc(85vh - 100px); 
    background: rgba(34, 34, 34, 0.95); 
}
/* Edit NPC modal: add subtle horizontal breathing room */
#npc_modal .modal-container {
    box-sizing: border-box;
    padding-left: 12px;
    padding-right: 12px;
}
.modal-close { 
    background:#3a3a3a; 
    color:#fff; 
    border:1px solid #4a4a4a; 
    border-radius:6px; 
    padding:6px 12px; 
    cursor:pointer; 
    transition: all 0.2s ease;
}
.modal-close:hover {
    background:#4a4a4a;
    border-color:#5a5a5a;
}
.modal-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.modal-actions .btn-save { 
    background: linear-gradient(135deg, #176529, #125121); 
    color:#fff; 
    border:1px solid rgba(72,187,120,0.3); 
    border-radius:6px; 
    padding:10px 16px; 
    cursor:pointer; 
    font-weight:700; 
    font-size:13px; 
    transition:all 0.2s ease; 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.modal-actions .btn-save:hover { 
    background: linear-gradient(135deg, #125121, #0d3d19); 
    border-color:rgba(72,187,120,0.5); 
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
}
.modal-actions .btn-cancel { 
    background:#3a3a3a; 
    color:#e9efff; 
    border:1px solid #4a4a4a; 
    border-radius:6px; 
    padding:10px 16px; 
    cursor:pointer; 
    font-weight:600; 
    font-size:13px; 
    transition:all 0.2s ease; 
}
.modal-actions .btn-cancel:hover { 
    background:#4a4a4a; 
    border-color:#5a5a5a; 
    color:#e6b76c; 
    transform: translateY(-1px);
}
.modal-actions #npc_modal_regen { 
    background:rgba(230, 183, 108,0.15); 
    border-color:#e6b76c; 
    color:#e6b76c; 
}
.modal-actions #npc_modal_regen:hover { 
    background:rgba(230, 183, 108,0.3); 
    border-color:#e6b76c;
    transform: translateY(-1px);
}
.modal-actions #npc_modal_close { 
    background: linear-gradient(135deg, #5a2a2a, #4a1a1a); 
    border-color:#7a3a3a; 
    color:#fff; 
}
.modal-actions #npc_modal_close:hover { 
    background: linear-gradient(135deg, #6a3a3a, #5a2a2a); 
    transform: translateY(-1px);
}
.modal-save { 
    background: #e6b76c; 
    color:#111; 
    border:1px solid #e6b76c; 
    border-radius:6px; 
    padding:8px 14px; 
    cursor:pointer; 
    font-weight:700; 
    transition: all 0.2s ease;
}
.modal-save:hover {
    background: rgb(230, 183, 108);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.3);
}
/* Styled tabs to match button aesthetics */
#npc_modal_tabs .pf-tab { 
    padding:8px 14px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    cursor:pointer; 
    font-weight:700; 
    transition: all 0.2s ease;
}
#npc_modal_tabs .pf-tab:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
}
#npc_modal_tabs .pf-tab.active { 
    background: linear-gradient(135deg, rgba(230, 183, 108, 0.2), rgba(230, 183, 108, 0.1)); 
    color: #e6b76c; 
    border-color: rgba(230, 183, 108, 0.5); 
    box-shadow: inset 0 -2px 0 #e6b76c;
}
</style>
<?php if ($totalPages >= 1): ?>
<style>
.pagination { display:flex; gap:8px; align-items:center; justify-content:center; margin:16px 0 0 0; flex-wrap:wrap; }
.pagination a, .pagination span { 
    padding:8px 12px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    text-decoration:none; 
    transition: all 0.2s ease;
}
.pagination a:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
    transform: translateY(-1px);
}
.pagination .active { 
    background: linear-gradient(135deg, rgba(230, 183, 108, 0.2), rgba(230, 183, 108, 0.1)); 
    color: #e6b76c; 
    border-color: rgba(230, 183, 108, 0.5); 
    font-weight:700; 
    box-shadow: inset 0 -2px 0 #e6b76c;
}
.pagination .disabled { opacity:0.4; pointer-events:none; }
.pagination button { 
    padding:8px 14px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    cursor:pointer; 
    transition: all 0.2s ease;
    font-weight: 600;
}
.pagination button:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
    transform: translateY(-1px);
}
.filter-inline { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.filter-inline input[type="text"] { 
    padding:6px 10px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(26, 26, 26, 0.8); 
    color:#e9efff; 
    height:32px; 
    transition: all 0.2s ease;
}
.filter-inline input[type="text"]:focus {
    border-color: rgba(230, 183, 108, 0.5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
}
.filter-inline select { 
    padding:6px 10px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(26, 26, 26, 0.8); 
    color:#e9efff; 
    height:32px; 
    transition: all 0.2s ease;
}
.filter-inline select:focus {
    border-color: rgba(230, 183, 108, 0.5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
}
.filter-inline .btn { 
    padding:8px 14px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    cursor:pointer; 
    transition: all 0.2s ease;
    font-weight: 600;
}
.filter-inline .btn:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
    transform: translateY(-1px);
}
</style>
<div class="pagination" style="padding: 14px; background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98)); border-radius: 10px; border: 1px solid #3a3a3a; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); margin-bottom: 16px;">
  <div class="filter-inline">
    <div class="npc-filter-dropdown" style="position:relative;">
      <button type="button" id="npc_filter_btn_top" class="btn" style="margin-right:6px;">Filters</button>
      <div id="npc_filter_menu_top" class="npc-filter-menu" style="display:none; position:absolute; right:0; top:calc(100% + 6px); background:#2a2a2a; border:1px solid #4a4a4a; border-radius:8px; padding:8px; min-width:220px; box-shadow:0 6px 18px rgba(0,0,0,0.35); z-index:15;">
        <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_fav_top" <?= $favOnly?'checked':'' ?>> Favorites</label>
        <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_dyn_top" <?= $dynOnly?'checked':'' ?>> Dynamic profile</label>
        <label style="display:flex; align-items:center; gap:8px; margin:4px 0; color:#e9efff;"><input type="checkbox" id="npc_filter_mtm_top" <?= $mtmOnly?'checked':'' ?>> Middle-term memory</label>
      </div>
    </div>
    <input id="npc_search" type="text" placeholder="Search..." value="<?= htmlspecialchars($q) ?>" />
    <select id="npc_profile_filter" title="Filter by profile">
      <option value="">All Profiles</option>
      <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); ?>
        <option value="<?= htmlspecialchars($pid) ?>" <?= ($profileIdFilter!=='' && (string)$profileIdFilter===$pid)?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php $qbase = strtok($_SERVER['REQUEST_URI'], '?'); $make = function($p) use ($qbase){ return htmlspecialchars($qbase.'?page='.$p); }; ?>
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $make(1) ?>">First</a>
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $make(max(1,$page-1)) ?>">Prev</a>
  <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
    <?php if ($p === $page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="<?= $make($p) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $make(min($totalPages,$page+1)) ?>">Next</a>
  <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $make($totalPages) ?>">Last</a>
  <span style="border:none; background:transparent; color:#e6b76c;">Page <?= $page ?> / <?= $totalPages ?></span>
  <span style="border:none; background:transparent; color:#e6b76c;">Total <?= $totalRows ?></span>
  <button id="npc_create_btn" type="button" style="margin-left:8px;">+ Create NPC</button>
  <button id="rel_bulk_build_btn" type="button" class="btn-rel-build" title="Build JSONB relationships from World Knowledge text data for all NPCs">Build Relationships</button>
  <button id="npc_bulk_switch_profile_btn" type="button" class="btn-rel-build" title="Switch all NPCs from one profile to another">Mass Switch Profile</button>
  <button id="npc_bulk_delete_btn" type="button" class="btn-danger" title="Delete all unlocked NPCs (excludes The Narrator and locked)">Delete All Profiles</button>
</div>
<div style="margin:10px 0; padding:10px 14px; background:rgba(230, 183, 108,0.08); border:1px solid rgba(230, 183, 108,0.25); border-radius:8px; font-size:12.5px; color:#cfd9ea; line-height:1.5;">
  <strong style="color:#e6b76c;">Stobe Save Rollback:</strong>
  Every time a save is loaded, Stobe snapshots NPC profiles and restores <strong>unlocked</strong> NPCs to the state captured at that save's Kenshi game timestamp.
  Loading an older save will roll unlocked profiles back to that point in time. NPCs created <em>after</em> that save timestamp may disappear.
  <div style="margin-top:4px;">
    <span style="color:#e6b76c;">Lock a profile (Lock) to protect it from rollback.</span>
    You can view and restore previous versions of any NPC via the <strong>View History</strong> button in the edit modal.
  </div>
</div>
<div class="npc-grid">
    <?php foreach ($data as $row): ?>
    <?php 
    $pid = (string)($row['profile_id'] ?? ''); 
    $profLabel = $profilesById[$pid] ?? ''; 
    $tagsVal = trim((string)($row['tags'] ?? '')); 
    $tagsDisp = ($tagsVal === '') ? 'none' : $tagsVal; 
    $metaTmp = []; 
    if (!empty($row['metadata'])) { 
        $tmp = json_decode((string)$row['metadata'], true); 
        if (is_array($tmp)) { $metaTmp = $tmp; } 
    } 
    $bountySummary = stobe_ui_format_bounty_summary(
        $row['bounty'] ?? 0,
        $row['bounty_payload'] ?? null,
        $metaTmp
    );
    $bountyAmountText = $bountySummary['amount_text'];
    $bountyDetailsText = $bountySummary['details_text'];
    $bountyBreakdownItems = is_array($bountySummary['breakdown_items'] ?? null) ? $bountySummary['breakdown_items'] : [];
    $bountyBreakdownExtra = intval($bountySummary['breakdown_extra'] ?? 0);
    $bountyLegacyDetails = trim(strval($bountySummary['legacy_details'] ?? ''));
    $extTmp = []; 
    if (!empty($row['extended_data'])) { 
        $tmp2 = json_decode((string)$row['extended_data'], true); 
        if (is_array($tmp2)) { $extTmp = $tmp2; } 
    }
    
    // Check for inherited profile settings
    $profileMeta = isset($profileMetaById[$pid]) ? $profileMetaById[$pid] : ['dyn'=>false,'mtm'=>false,'blc'=>false,'gps'=>false];
    
    // Dynamic Profile: check NPC override, otherwise inherit from profile
    $dynEnabled = $profileMeta['dyn']; // default to profile
    if (isset($row['dynamic_profile']) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynEnabled = coerceBoolean($row['dynamic_profile']);
    }

    // MTM: check metadata override, otherwise legacy extended_data, otherwise inherit from profile
    $mtmEnabled = $profileMeta['mtm']; // default to profile
    $mtmOverride = stobeUiResolveMtmOverride($metaTmp, $extTmp);
    if ($mtmOverride !== null) {
        $mtmEnabled = $mtmOverride;
    }

    // Individual memory bank is NPC-only (no profile inheritance).
    $imbEnabled = stobeUiResolveIndividualMemoryEnabled($extTmp);
    
    // Background Life Commands: check extended_data override, otherwise inherit from profile
    $blcEnabled = $profileMeta['blc']; // default to profile
    if (array_key_exists('background_life_commands', $extTmp) && $extTmp['background_life_commands'] !== null && $extTmp['background_life_commands'] !== '') {
        $blcEnabled = !empty($extTmp['background_life_commands']);
    }
    
    // GPS Track: check metadata override, otherwise inherit from profile
    $gpsEnabled = $profileMeta['gps']; // default to profile
    if (array_key_exists('gps_track', $metaTmp) && $metaTmp['gps_track'] !== null && $metaTmp['gps_track'] !== '') {
        $gpsEnabled = !empty($metaTmp['gps_track']);
    }
    
    ?>
    <div class="npc-card" id="npc_card_<?= htmlspecialchars($row["id"]) ?>" data-id="<?= htmlspecialchars($row["id"]) ?>">
            <div class="npc-title">
            <div class="npc-title-left"><?php 
                $levelDisp2 = '';
                if (isset($metaTmp['stats']) && is_array($metaTmp['stats']) && isset($metaTmp['stats']['level'])) {
                    $levelDisp2 = ' ('.intval($metaTmp['stats']['level']).')';
                }
                ?><span class="npc-name"><?= htmlspecialchars(($row["npc_name"] ?? '').$levelDisp2) ?></span> <?php $gch = gender_icon_char($row['gender'] ?? ''); $gcl = gender_icon_class($row['gender'] ?? ''); if ($gch!==''): ?><span class="npc-gender-icon <?= htmlspecialchars($gcl) ?>" title="<?= htmlspecialchars($row['gender'] ?? '') ?>"><?= $gch ?></span><?php endif; ?><?php if (!empty($dynEnabled)): ?><span class="npc-dyn-icon" title="Dynamic profile enabled">&#x267B;&#xFE0F;</span><?php endif; ?><?php if (!empty($mtmEnabled)): ?><span class="npc-mtm-icon" title="Middle-term memory enabled">&#x1F4C3;</span><?php endif; ?><?php if (!empty($imbEnabled)): ?><span class="npc-imb-icon" title="Individual memory bank enabled">&#x1F9E0;</span><?php endif; ?><?php if (!empty($blcEnabled)): ?><span class="npc-blc-icon" title="Background life commands enabled">&#x1F3AE;</span><?php endif; ?><?php if (!empty($gpsEnabled)): ?><span class="npc-gps-icon" title="GPS track enabled">&#x1F4CD;</span><?php endif; ?></div>
            <div class="npc-title-actions">
                <?php if ($tagsDisp !== ''): ?>
                <span class="npc-tags-label">Tags:</span>
                <span class="npc-tags-top" title="Use Search to filter by these tags: <?= htmlspecialchars($tagsDisp) ?>"><?= htmlspecialchars($tagsDisp) ?></span>
                <?php endif; ?>
                                <a class="btn btn-toggle <?= coerceBoolean($row["npc_favorite"] ?? false) ? "active" : "" ?>" href="#" data-favorite-id="<?= $row["id"] ?>" title="Toggle favorite"><?php echo coerceBoolean($row["npc_favorite"] ?? false) ? "&#9733;" : "&#9734;"; ?></a>
                                <a class="btn btn-toggle <?= coerceBoolean($row["lock_profile"] ?? false) ? "active" : "" ?>" href="#" data-lock-id="<?= $row["id"] ?>" title="Toggle lock - Locked profiles are protected from save rollback when loading saves"><?php echo coerceBoolean($row["lock_profile"] ?? false) ? "&#x1F512;" : "&#x1F513;"; ?></a>
                                <a class="btn btn-trash<?= coerceBoolean($row['lock_profile'] ?? false) ? ' disabled' : '' ?>" data-delete-id="<?= intval($row['id']) ?>" href="<?= coerceBoolean($row['lock_profile'] ?? false) ? '#' : ('npc_master.php?delete='.$row['id']) ?>" title="<?= coerceBoolean($row['lock_profile'] ?? false) ? 'Locked - cannot delete' : 'Delete' ?>">&#x1F5D1;&#xFE0F;</a>
            </div>
        </div>
        <div class="npc-divider"></div>
        <div class="npc-row">
            <div class="npc-fields">
                <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"><?= htmlspecialchars($row["gender"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"><?= htmlspecialchars($row["race"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"><?= htmlspecialchars($row["voiceid"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"><?= htmlspecialchars($profLabel) ?></span></div>
                <div class="npc-line"><span class="npc-muted">Bounty:</span> <span class="npc-bounty"><?= htmlspecialchars($bountyAmountText) ?></span></div>
                <?php if (count($bountyBreakdownItems) > 0 || $bountyLegacyDetails !== ''): ?>
                <div class="npc-bounty-section">
                    <div class="npc-bounty-heading">Bounty Breakdown</div>
                    <div class="npc-bounty-breakdown">
                        <?php foreach ($bountyBreakdownItems as $bd): ?>
                        <?php
                            $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                            $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                            $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                        ?>
                        <div class="npc-bounty-item">
                            <div class="npc-bounty-item-top">
                                <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                            </div>
                            <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($bountyBreakdownExtra > 0): ?>
                        <div class="npc-bounty-more">+<?= htmlspecialchars(strval($bountyBreakdownExtra)) ?> more faction(s)</div>
                        <?php endif; ?>
                        <?php if (count($bountyBreakdownItems) === 0 && $bountyLegacyDetails !== ''): ?>
                        <div class="npc-bounty-legacy"><?= htmlspecialchars($bountyLegacyDetails) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
                <div class="npc-right"></div>
            <div class="npc-right-warn">
                    <?php 
                    if ($row["gamets_last_updated"] != $LAST_INFOSAVE_EVENT) {
                        echo "<span title='This NPC is out of sync, this means current NPC sheet has been modified after last save. If you edit this NPC, changes will be lost if you reload a previous savegame. '>&#x26A0;&#xFE0F;</span>";
                    }
                    ?>
            </div>
        </div>
        
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div id="npc_modal" class="modal-backdrop">
  <div class="modal-container">
    <div class="modal-header">
      <h2 class="modal-title">Edit NPC</h2>
      <div class="modal-actions">
        <button id="npc_modal_save_header" class="btn-save">Save</button>
        <button id="npc_modal_export" class="btn-cancel" title="Export NPC biography to JSON file">Export Bio</button>
        <button id="npc_modal_import_to" class="btn-cancel" title="Import biography from another NPC's export file">Import Bio</button>
        <button id="npc_modal_reset" class="btn-cancel" title="Reimport bio template fields">Reset NPC</button>
        <button id="npc_modal_history" class="btn-cancel">View History</button>
        <button id="npc_modal_regen" class="btn-cancel" title="Will use AI to regenerate this profile. Intended for custom NPCs without biography descriptions.">AI Generate Profile</button>
        <button id="npc_modal_close" class="btn-cancel">Close</button>
      </div>
    </div>
    <div class="modal-body">
      <div id="npc_modal_tabs" style="display:flex; gap:8px; padding:8px; border-bottom:1px solid #4a4a4a; background:#2a2a2a; position:sticky; top:0; z-index:2;">
        <button type="button" class="pf-tab active" data-pane="pane_manual">Manual</button>
        <button type="button" class="pf-tab" data-pane="pane_bio">NPC Biographies</button>
      </div>
      <div id="pane_manual" class="pf-pane active" style="padding:0;">
        <iframe id="npc_modal_iframe" src="about:blank" style="width:100%; height:70vh; border:0; background:transparent;"></iframe>
      </div>
      <div id="pane_bio" class="pf-pane" style="display:none; padding:10px;">
        <div style="display:flex; gap:12px; align-items:flex-start;">
          <div style="flex: 0 0 340px; max-width:340px; border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#2a2a2a;">
            <div style="display:flex; flex-direction:column; gap:6px; align-items:stretch; margin-bottom:8px;">
              <select id="bio_letter" style="padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
                <option value="">All</option>
                <option>A</option><option>B</option><option>C</option><option>D</option><option>E</option><option>F</option><option>G</option><option>H</option><option>I</option><option>J</option><option>K</option><option>L</option><option>M</option><option>N</option><option>O</option><option>P</option><option>Q</option><option>R</option><option>S</option><option>T</option><option>U</option><option>V</option><option>W</option><option>X</option><option>Y</option><option>Z</option>
              </select>
              <input id="bio_search_input" type="text" placeholder="Search bio database..." style="padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
            </div>
            <div id="bio_list" style="height:58vh; overflow:auto; display:flex; flex-direction:column; gap:6px;"></div>
            <div id="bio_pager" style="display:flex; gap:6px; align-items:center; justify-content:center; margin-top:6px;"></div>
          </div>
          <div style="flex: 1 1 auto; min-width:0; border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#2a2a2a;">
            <div style="margin-bottom:8px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
              <label class="label-with-toggle"><input id="bio_inc_ext" type="checkbox" checked> Extended Profile</label>
              <label class="label-with-toggle"><input id="bio_inc_world_knowledge" type="checkbox" checked> World Knowledge Tags</label>
              <label class="label-with-toggle"><input id="bio_inc_vm" type="checkbox" checked> Voice & Meta</label>
              <select id="bio_profile_id" title="Assign Profile" style="margin-left:auto; padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
                <option value="">Select Profile</option>
                <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); $sel = ($firstProfileId === $pid) ? ' selected' : ''; ?>
                <option value="<?= htmlspecialchars($pid) ?>"<?= $sel ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <button id="bio_use_template" type="button" class="btn-base btn-primary">Use Template</button>
            </div>
            <div id="bio_detail" style="height:58vh; overflow:auto;">
              <div style="color:#9fb1c9">Select a template on the left</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- NPC History viewer overlay -->
<div id="history_viewer" class="modal-backdrop" style="z-index:10002;">
  <div class="modal-container" style="max-width:1100px; width:95%;">
    <div class="modal-header">
      <h2 class="modal-title">NPC History</h2>
      <div class="modal-actions">
        <button id="history_close" class="btn-cancel">Close</button>
        <button id="history_generation" class="btn-cancel" title="Note: Will do a LLM request.">Evolution report (AI request)</button>
      </div>
    </div>
    <div class="modal-body" style="height:75vh; display:flex; gap:10px;">
      <div id="history_list" style="flex: 0 0 320px; max-width:320px; border-right:1px solid #4a4a4a; overflow:auto; padding:8px;">
      </div>
      <div id="history_detail" style="flex: 1 1 auto; min-width:0; overflow:auto; padding:8px;">
        <div style="color:#9fb1c9">Select a snapshot to view details</div>
      </div>
    </div>
  </div>
</div>


<!-- Build Relationships Modal -->
<div id="rel_build_modal" class="modal-backdrop" style="z-index:10003; display:none;">
  <div class="modal-container rel-build-modal-container" style="max-width:500px;">
    <div class="modal-header" style="border-bottom:1px solid #e6b76c; text-align:center; justify-content:center;">
      <h2 class="modal-title" style="color:#e6b76c; margin:0; width:100%; text-align:center;">Build Relationships</h2>
    </div>
    <div class="modal-body" style="padding:24px; text-align:center;">
      <div id="rel_build_content">
        <!-- Info Box -->
        <div style="background:#2a2a3a; border:1px solid #5a5a6a; border-radius:8px; padding:12px; margin-bottom:16px; text-align:left;">
          <div style="color:#9fb1c9; font-size:0.85em; line-height:1.4;">
            Building runs in the background while you play. You can adjust any NPC individually by clicking their profile and editing <strong>Relationship Affinities</strong>.
          </div>
        </div>

        <!-- Model Info -->
        <div style="background:#1a1a2a; border:1px solid #4a4a4a; border-radius:8px; padding:16px; margin-bottom:20px;">
          <div style="color:#9fb1c9; font-size:0.9em; margin-bottom:4px;">Relationship Model</div>
          <div id="rel_build_model" style="color:#e6b76c; font-size:1.1em; font-weight:bold;">Loading...</div>
        </div>

        <!-- NPC Counts -->
        <div style="display:flex; gap:16px; justify-content:center; margin-bottom:20px;">
          <div style="background:#1e3f1e; border:1px solid #2d5a2d; border-radius:8px; padding:16px; min-width:120px;">
            <div style="color:#4ade80; font-size:2em; font-weight:bold;" id="rel_count_built">--</div>
            <div style="color:#9fb1c9; font-size:0.85em;">Already Built</div>
          </div>
          <div style="background:#3f2f1e; border:1px solid #5a4a2d; border-radius:8px; padding:16px; min-width:120px;">
            <div style="color:#e6b76c; font-size:2em; font-weight:bold;" id="rel_count_pending">--</div>
            <div style="color:#9fb1c9; font-size:0.85em;">Need Building</div>
          </div>
        </div>

        <!-- Options -->
        <div style="text-align:left; background:#2a2a3a; border-radius:8px; padding:16px; margin-bottom:20px;">
          <label style="display:flex; align-items:flex-start; gap:10px; color:#cfd9ea; margin-bottom:12px; cursor:pointer;">
            <input type="checkbox" id="rel_build_force" style="width:16px; height:16px; min-width:16px; min-height:16px; accent-color:#e6b76c; margin-top:2px;">
            <span>Include NPCs that were already built</span>
          </label>
          <label style="display:flex; align-items:flex-start; gap:10px; color:#cfd9ea; cursor:pointer;">
            <input type="checkbox" id="rel_build_infer" checked style="width:16px; height:16px; min-width:16px; min-height:16px; accent-color:#e6b76c; margin-top:2px;">
            <div>
              <span>Build advanced relationship connections</span>
              <div style="font-size:0.75em; color:#7a8a9a; margin-top:4px; line-height:1.4;">
                Creates indirect opinions based on social networks.<br>
                <em>Example: If Eris loves Vivienne (+80) and Vivienne hates a bandit (-70), Eris becomes wary of that bandit too.</em>
              </div>
            </div>
          </label>
        </div>

        <!-- Buttons -->
        <div style="display:flex; gap:12px; justify-content:center;">
          <button id="rel_build_start" style="background:#1e3f1e; color:#fff; border:none; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer; transition:background 0.2s;">
            Start Building
          </button>
          <button id="rel_build_close" style="background:#7a1e1e; color:#fff; border:none; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer; transition:background 0.2s;">
            Cancel
          </button>
        </div>
      </div>

      <!-- Progress View -->
      <div id="rel_build_progress" style="display:none;">
        <div style="margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span id="rel_build_status" style="color:#e6b76c; font-weight:bold;">Processing...</span>
            <span id="rel_build_count" style="color:#9fb1c9;">0 / 0</span>
          </div>
          <div style="background:#1a1a2a; border-radius:8px; height:28px; overflow:hidden; border:1px solid #4a4a4a;">
            <div id="rel_build_bar" style="background:linear-gradient(90deg, #e6b76c, #f59e0b); height:100%; width:0%; transition:width 0.3s;"></div>
          </div>
        </div>
        <div id="rel_build_log" style="background:#1a1a2a; border:1px solid #4a4a4a; border-radius:8px; padding:12px; height:200px; overflow-y:auto; font-family:monospace; font-size:12px; color:#9fb1c9; text-align:left;">
        </div>
        <div style="margin-top:16px;">
          <button id="rel_build_done" style="display:none; background:#3a3a4a; color:#e6b76c; border:1px solid #e6b76c; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer;">
            Done
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const PROFILES_BY_ID = <?= json_encode($profilesById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PROFILE_OPTIONS = <?= json_encode($profileOptions ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const modal = document.getElementById('npc_modal');
  const iframe = document.getElementById('npc_modal_iframe');
  function openModal(url){
    iframe.src = url;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    try {
      // Track current editing id for modal updates
      (function(){
        try { const m = url.match(/[?&]edit=([^&]+)/); window.CURRENT_NPC_ID = m ? String(decodeURIComponent(m[1])) : ''; } catch(_e){ window.CURRENT_NPC_ID=''; }
      })();
      const tabs = document.getElementById('npc_modal_tabs');
      const bioPane = document.getElementById('pane_bio');
      const manualPane = document.getElementById('pane_manual');
      const exportBtn = document.getElementById('npc_modal_export');
      const importBioBtn = document.getElementById('npc_modal_import_to');
      const isEdit = /[?&]edit=/.test(url);
      if (isEdit){
        if (tabs) tabs.style.display = 'none';
        if (bioPane) { bioPane.style.display = 'none'; bioPane.classList.remove('active'); }
        if (manualPane) { manualPane.style.display = 'block'; manualPane.classList.add('active'); }
        // Show export/import buttons only for existing NPCs
        if (exportBtn) exportBtn.style.display = '';
        if (importBioBtn) importBioBtn.style.display = '';
      } else {
        if (tabs) tabs.style.display = 'flex';
        // Hide export/import buttons for new NPCs
        if (exportBtn) exportBtn.style.display = 'none';
        if (importBioBtn) importBioBtn.style.display = 'none';
      }
    } catch(_e){}

     
  }
  function closeModal(){ modal.style.display = 'none'; document.body.style.overflow = 'auto'; try { iframe.src='about:blank'; } catch(_){} }
  const headerSave = document.getElementById('npc_modal_save_header');
  if (headerSave){
    window.NPC_UPDATE_SAVE_STATE = function(){
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const val = nameEl ? String(nameEl.value||'').trim() : '';
        const disable = (val === '');
        headerSave.disabled = disable;
        if (disable) headerSave.title = 'Enter NPC Name to save'; else headerSave.removeAttribute('title');
      } catch(_e){}
    };
    // Watch for iframe content load and bind input listener
    try {
      iframe.addEventListener('load', function(){
        try {
          const doc = iframe && iframe.contentDocument;
          const nameEl = doc ? doc.getElementById('npc_name') : null;
          if (nameEl){
            ['input','change','keyup'].forEach(evt=> nameEl.addEventListener(evt, window.NPC_UPDATE_SAVE_STATE));
          }
        } catch(_e){}
        window.NPC_UPDATE_SAVE_STATE();
      });
    } catch(_e){}
    headerSave.addEventListener('click', function(){
      try {
        // Guard: require NPC name
        window.NPC_UPDATE_SAVE_STATE(); if (headerSave.disabled) { return; }
        const btn = iframe && iframe.contentDocument ? iframe.contentDocument.getElementById('npc_modal_save') : null;
        if (btn){ btn.click(); }
        // else: nothing (no bio import submit anymore)
      } catch(_e){}
    });
  }
  // Reset NPC button wiring (reimport non-empty template fields by current name)
  (function(){
    const resetBtn = document.getElementById('npc_modal_reset');
    if (!resetBtn) return;
    resetBtn.addEventListener('click', async function(e){
      e.preventDefault();
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const npcName = nameEl ? String(nameEl.value||'').trim() : '';
        if (!npcName){ alert('Enter NPC Name to reset from template.'); return; }
        // Confirm overwrite of fields present in template
        const ok = window.confirm('Reset NPC "'+npcName+'" from bio template?\n\nThis will overwrite only fields present in the template. Other fields will remain unchanged.');
        if (!ok) return;
        const res = await fetch('npc_master.php?bio_detail=1&name='+encodeURIComponent(npcName));
        let j={}; try { j = await res.json(); } catch(_e) { j={ok:false}; }
        if (!j || !j.ok){ alert('No bio template found for "'+npcName+'"'); return; }
        const d = j.data || {};
        function setVal(id, val){ const el = doc ? doc.getElementById(id) : null; if (el) el.value = String(val); }
        function applyIfFilled(id, val){ if (val==null) return; const s=String(val).trim(); if (!s) return; setVal(id, s); }
        applyIfFilled('npc_static_bio', d.npc_static_bio);
        applyIfFilled('personality', d.personality);
        applyIfFilled('appearance', d.appearance);
        applyIfFilled('relationships', d.relationships);
        applyIfFilled('occupation', d.occupation);
        applyIfFilled('skills', d.skills);
        applyIfFilled('speechstyle', d.speechstyle);
        applyIfFilled('goals', d.goals);
        applyIfFilled('world_knowledge_tags', d.world_knowledge_tags);
        applyIfFilled('voiceid', d.voiceid);
        applyIfFilled('gender', d.gender);
        applyIfFilled('race', d.race);
        // Try to reflect middle_term_enabled if provided in template (rare)
        try { const mtm = (d && typeof d.middle_term_enabled!=='undefined') ? Number(d.middle_term_enabled) : null; if (mtm!==null){ const cb = doc.getElementById('middle_term_enabled'); if (cb) cb.checked = (Number(mtm)===1); } } catch(_e){}
        try { if (typeof window.NPC_UPDATE_SAVE_STATE === 'function') window.NPC_UPDATE_SAVE_STATE(); } catch(_e){}
        try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='Template values applied'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      } catch(_e){}
    });
  })();
   // Regenerate profile using AI
  
  (function(){
    const regenBtn = document.getElementById('npc_modal_regen');
    if (!regenBtn) return;
    regenBtn.addEventListener('click', async function(e){
      e.preventDefault();
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const npcName = nameEl ? String(nameEl.value||'').trim() : '';
        
        if (!npcName) { alert('Enter NPC Name to generate profile.'); return; }
        
        // Show prompt dialog for user to add custom instructions
        const promptBox = document.createElement('div');
        promptBox.style.position='fixed';
        promptBox.style.inset='0';
        promptBox.style.zIndex='10050';
        promptBox.style.display='flex';
        promptBox.style.alignItems='center';
        promptBox.style.justifyContent='center';
        promptBox.style.background='rgba(0,0,0,0.65)';
        promptBox.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:600px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#e6b76c; margin-bottom:8px; font-size:18px;">AI Generate Profile for "' + npcName.replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '"</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:12px;">Add any specific information or instructions for the AI to consider when generating this profile. Leave blank to use default generation. This uses the NPC profile\'s response connector.</div>\
          <label style="display:block; font-size:13px; margin:6px 0 4px; color:#cfd9ea; font-weight:600;">Custom Instructions (optional):</label>\
          <textarea id="ai_user_prompt" placeholder="Example: This NPC should be a merchant specializing in enchanted weapons, with a mysterious past..." style="width:100%; min-height:120px; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff; resize:vertical; font-family:inherit;"></textarea>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="ai_prompt_cancel" style="padding:10px 20px; color:#fff; background:rgba(85,95,109,0.9); border:1px solid rgba(156,163,175,0.3); border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.2s ease;">Cancel</button>\
            <button id="ai_prompt_ok" style="padding:10px 20px; color:#111; background:#e6b76c; border:1px solid #e6b76c; border-radius:8px; cursor:pointer; font-size:14px; font-weight:700; transition:all 0.2s ease;">Generate Profile</button>\
          </div></div>';
        document.body.appendChild(promptBox);
        
        const promptInput = promptBox.querySelector('#ai_user_prompt');
        const okBtn = promptBox.querySelector('#ai_prompt_ok');
        const cancelBtn = promptBox.querySelector('#ai_prompt_cancel');
        
        promptInput.focus();
        
        cancelBtn.addEventListener('click', function(){
          document.body.removeChild(promptBox);
        });
        
        okBtn.addEventListener('click', async function(){
          const userPrompt = String(promptInput.value||'').trim();
          document.body.removeChild(promptBox);
          
          document.getElementById("npc_modal").style.cursor="wait";
          
          const processingMessage = document.createElement('div');
          processingMessage.innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><div class="spinner" style="width:20px;height:20px;border:3px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite;"></div><span>Generating profile with AI...</span></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
          processingMessage.style.position = 'fixed';
          processingMessage.style.top = '50%';
          processingMessage.style.left = '50%';
          processingMessage.style.transform = 'translate(-50%, -50%)';
          processingMessage.style.backgroundColor = 'rgba(0,0,0,0.9)';
          processingMessage.style.color = '#fff';
          processingMessage.style.padding = '16px 24px';
          processingMessage.style.borderRadius = '10px';
          processingMessage.style.zIndex = '10001';
          processingMessage.style.border = '1px solid #4a4a4a';
          processingMessage.id="processing_wheel";
          document.body.appendChild(processingMessage);

          const params = new URLSearchParams({ name: npcName });
          if (userPrompt) params.append('user_prompt', userPrompt);
          
          let j = {};
          let fetchError = null;
          try {
            const endpoint = window.location.pathname + '?action_ai_regen_profile=1&' + params.toString();
            const res = await fetch(endpoint);
            if (!res.ok) {
              fetchError = 'Server returned status ' + res.status;
            } else {
              try { j = await res.json(); } catch(_e) { j = {done:false, error:'Invalid JSON response from server'}; }
            }
          } catch(e) {
            fetchError = 'Network error: ' + String(e.message || e);
          }
          
          // Remove processing message
          const procEl = document.getElementById('processing_wheel');
          if (procEl) procEl.remove();
          document.getElementById("npc_modal").style.cursor = "";
          
          if (fetchError) {
            showAIGenerateResult(false, fetchError, npcName);
            return;
          }
          
          if (j && j.done) {
            showAIGenerateResult(true, 'Profile successfully generated with ' + (j.fields_updated || 'multiple') + ' fields updated.', npcName);
          } else {
            const errMsg = (j && j.error) ? j.error : 'Unknown error occurred. Check the server logs for details.';
            showAIGenerateResult(false, errMsg, npcName);
          }
        });
        
        function showAIGenerateResult(success, message, npcName) {
          const resultBox = document.createElement('div');
          resultBox.style.position = 'fixed';
          resultBox.style.inset = '0';
          resultBox.style.zIndex = '10050';
          resultBox.style.display = 'flex';
          resultBox.style.alignItems = 'center';
          resultBox.style.justifyContent = 'center';
          resultBox.style.background = 'rgba(0,0,0,0.65)';
          
          const iconColor = success ? '#4ade80' : '#f87171';
          const iconSymbol = success ? 'OK' : 'ERR';
          const title = success ? 'Profile Generated Successfully' : 'Profile Generation Failed';
          
          resultBox.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:20px; max-width:500px; width:92%; color:#e9efff;">\
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">\
              <div style="width:32px; height:32px; border-radius:50%; background:' + iconColor + '; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:bold; color:#111;">' + iconSymbol + '</div>\
              <div style="font-weight:700; color:' + iconColor + '; font-size:18px;">' + title + '</div>\
            </div>\
            <div style="font-size:14px; color:#cfd9ea; margin-bottom:16px; line-height:1.5;">' + message.replace(/[<>]/g, c=>({'<':'&lt;','>':'&gt;'}[c])) + '</div>\
            <div style="display:flex; gap:8px; justify-content:flex-end;">\
              ' + (success ? '' : '<button id="ai_result_retry" style="padding:10px 20px; color:#fff; background:rgba(85,95,109,0.9); border:1px solid rgba(156,163,175,0.3); border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.2s ease;">Try Again</button>') + '\
              <button id="ai_result_ok" style="padding:10px 20px; color:#111; background:' + (success ? '#e6b76c' : 'rgba(85,95,109,0.9)') + '; border:1px solid ' + (success ? '#e6b76c' : 'rgba(156,163,175,0.3)') + '; border-radius:8px; cursor:pointer; font-size:14px; font-weight:700; ' + (success ? 'color:#111;' : 'color:#fff;') + ' transition:all 0.2s ease;">' + (success ? 'Reload to View' : 'Close') + '</button>\
            </div></div>';
          document.body.appendChild(resultBox);
          
          const okBtn = resultBox.querySelector('#ai_result_ok');
          const retryBtn = resultBox.querySelector('#ai_result_retry');
          
          okBtn.addEventListener('click', function(){
            document.body.removeChild(resultBox);
            if (success) {
              document.location.reload();
            }
          });
          
          if (retryBtn) {
            retryBtn.addEventListener('click', function(){
              document.body.removeChild(resultBox);
              // Re-trigger the regenerate button click
              const regenBtn = document.getElementById('npc_modal_regen');
              if (regenBtn) regenBtn.click();
            });
          }
          
          // Close on background click
          resultBox.addEventListener('click', function(e){
            if (e.target === resultBox) {
              document.body.removeChild(resultBox);
            }
          });
        }

      } catch(_e){console.log(_e)}
    });
  })();

  
  // View History button wiring
  (function(){
    const btn = document.getElementById('npc_modal_history');
    const overlay = document.getElementById('history_viewer');
    const listBox = document.getElementById('history_list');
    const detailBox = document.getElementById('history_detail');
    const closeBtn = document.getElementById('history_close');
    const reportBtn = document.getElementById('history_generation');

    const LABELS = {

      npc_name: 'NPC Name',
      profile_id: 'Profile',
      gender: 'Gender',
      race: 'Race',
      voiceid: 'Voice ID',
      faction: 'Faction',
      npc_static_bio: 'Backstory',
      appearance: 'Appearance',
      personality: 'Personality',
      relationships: 'Relationships',
      occupation: 'Occupation',
      skills: 'Skills',
      speechstyle: 'Speech Style',
      goals: 'Goals',
      world_knowledge_tags: 'World Knowledge Tags',
      emote_moods: 'Emote Moods',
      prompt_head: 'Prompt Head',
      dynamic_profile: 'Dynamic Profile',
      npc_favorite: 'Favorite',
      lock_profile: 'Lock Profile',
      tags: 'Tags'
    };
    function close(){ if (overlay) overlay.style.display='none'; }
    if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); close(); });
    if (reportBtn) reportBtn.addEventListener('click', function(e){ window.open("npc_report.php?npcid="+ String(window.CURRENT_NPC_ID||'').trim()) });
    if (overlay) overlay.addEventListener('click', function(e){ if (e.target===overlay) close(); });
    function renderDetail(entry, prev){
      if (!entry){ detailBox.innerHTML = '<div style="color:#9fb1c9">No data</div>'; return; }
      const f = entry.fields||{}; const prevF = (prev && prev.fields) ? prev.fields : {};
      const order = ['npc_name','profile_id','gender','race','voiceid','faction','npc_static_bio','appearance','personality','relationships','occupation','skills','speechstyle','goals','world_knowledge_tags','emote_moods','prompt_head','dynamic_profile','npc_favorite','lock_profile','tags'];
      let html = '';
      html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">';
      html += '<div style="color:#cfd9ea;">'+(entry.when_tamrielic || (entry.created?('Created '+entry.created):'Unknown time'))+(entry.created?(' <span style="color:#9fb1c9">('+entry.created+')</span>'):'')+'</div>';
      html += '<button class="btn-restore-history" data-history-id="'+String(entry.history_id||'')+'" style="background:#e6b76c; color:#111; border:1px solid #e6b76c; border-radius:6px; padding:6px 12px; cursor:pointer; font-weight:700;">Restore this version</button>';
      html += '</div>';
      html += '<div style="display:grid; grid-template-columns: 220px 1fr; gap:6px;">';
      order.forEach(k=>{
        let v = f[k]; const has = (v!==null && v!==undefined && String(v).trim()!=='');
        if (!has) return;
        const changed = (prevF && String(prevF[k]??'') !== String(v));
        const label = LABELS[k] || k.replace(/_/g,' ');
        if (k==='profile_id') { v = (PROFILES_BY_ID && PROFILES_BY_ID[String(v||'')]) ? PROFILES_BY_ID[String(v)] : v; }
        html += '<div style="color:#e6b76c; font-weight:700;">'+label+'</div>';
        html += '<div style="border:1px solid #4a4a4a; border-radius:6px; padding:6px;'+(changed?' background:#333333;':'')+'">'+String(v).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))+'</div>';
      });
      html += '</div>';
      detailBox.innerHTML = html;
      // Wire up restore button
      const restoreBtn = detailBox.querySelector('.btn-restore-history');
      if (restoreBtn) {
        restoreBtn.addEventListener('click', async function(){
          const histId = this.getAttribute('data-history-id');
          if (!histId) return;
          const ok = confirm('Restore this historical version?\n\nThis will replace the current NPC profile with the selected snapshot. This action creates a new backup before restoring.');
          if (!ok) return;
          try {
            this.disabled = true;
            this.textContent = 'Restoring...';
            const fd = new FormData();
            fd.append('restore_from_history', '1');
            fd.append('history_id', histId);
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j = {}; try { j = await res.json(); } catch(_e) { j = {ok:false}; }
            if (j && j.ok) {
              close();
              try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='NPC restored from history'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_e){}
              // Refresh the page to show updated NPC
              window.location.reload();
            } else {
              alert('Restore failed: ' + (j && j.error ? j.error : 'Unknown error'));
              this.disabled = false;
              this.textContent = 'Restore this version';
            }
          } catch(_e) {
            alert('Restore failed: ' + String(_e));
            this.disabled = false;
            this.textContent = 'Restore this version';
          }
        });
      }

     
    

    }
    function openHistory(){
      try {
        const id = String(window.CURRENT_NPC_ID||'').trim();
        if (!id){ return; }
        if (overlay) { overlay.style.display='flex'; }
        if (listBox) { listBox.innerHTML = '<div style="color:#9fb1c9">LoadingN/A</div>'; }
        if (detailBox) { detailBox.innerHTML = '<div style="color:#9fb1c9">Fetching historyN/A</div>'; }
        fetch('npc_master.php?history=1&id='+encodeURIComponent(id))
          .then(r=>r.json()).then(j=>{
            if (!j || !j.ok){ listBox.innerHTML = '<div style="color:#ff6b6b">Failed to load history</div>'; detailBox.innerHTML=''; return; }
            const entries = j.entries||[];
            if (entries.length===0){ listBox.innerHTML = '<div style="color:#9fb1c9">No history yet</div>'; detailBox.innerHTML=''; return; }
            listBox.innerHTML = '';
            entries.forEach((e, idx)=>{
              const div = document.createElement('div');
              div.style.border='1px solid #4a4a4a'; div.style.borderRadius='8px'; div.style.padding='8px'; div.style.cursor='pointer'; div.style.marginBottom='6px';
              const label = e.when_tamrielic || (e.created?('Created '+e.created):('Snapshot #'+String(e.history_id||idx+1)));
              const second = e.created?('<div style="color:#9fb1c9; font-size:11px;">'+e.created+'</div>') : '';
              div.innerHTML = '<div style="font-weight:700; color:#e9efff;">'+label+'</div>'+second;
              div.addEventListener('click', function(){
                listBox.querySelectorAll('.active').forEach(n=>{ n.classList.remove('active'); n.style.background=''; });
                this.classList.add('active'); this.style.background='#333333';
                renderDetail(e, idx>0?entries[idx-1]:null);
              });
              listBox.appendChild(div);
            });
          })
          .catch(()=>{ listBox.innerHTML = '<div style="color:#ff6b6b">Failed to load history</div>'; detailBox.innerHTML=''; });
      } catch(_){}
    }
    if (btn){ btn.addEventListener('click', function(e){ e.preventDefault(); openHistory(); }); }
  })();
  document.addEventListener('click', function(e){ if (e.target && e.target.id==='npc_modal_close') closeModal(); });
  modal.addEventListener('click', function(e){ if (e.target===modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeModal(); });
  // Tabs in modal
  (function(){
    const tabs = document.querySelectorAll('#npc_modal_tabs .pf-tab');
    function activate(id){
      tabs.forEach(t=>t.classList.toggle('active', t.getAttribute('data-pane')===id));
      document.querySelectorAll('.pf-pane').forEach(p=>{ p.style.display = (p.id===id) ? 'block' : 'none'; p.classList.toggle('active', p.id===id); });
    }
    tabs.forEach(tb=> tb.addEventListener('click', ()=> activate(tb.getAttribute('data-pane'))));
    activate('pane_manual');
  })();
  // Bio DB wiring
  (function(){
    const list = document.getElementById('bio_list');
    const pager = document.getElementById('bio_pager');
    const inp = document.getElementById('bio_search_input');
    const letter = document.getElementById('bio_letter');
    const detail = document.getElementById('bio_detail');
    const useBtn = document.getElementById('bio_use_template');
    const createBtn = document.getElementById('bio_use_create');
    const incExt = document.getElementById('bio_inc_ext');
    const incOgh = document.getElementById('bio_inc_world_knowledge');
    const incVM  = document.getElementById('bio_inc_vm');
    const selProfile = document.getElementById('bio_profile_id');
    let currentName = '';
    let page = 1; let total = 0; let pageSize = 20;
    async function fetchList(){
      const params = new URLSearchParams({ bio_search:'1', search:(inp.value||''), letter:(letter.value||''), page:String(page), pageSize:String(pageSize) });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok) { list.innerHTML = '<div style="color:#ff6b6b">Failed to load</div>'; return; }
      total = Number(j.total||0); page = Number(j.page||1); pageSize = Number(j.pageSize||20);
      list.innerHTML = '';
      (j.items||[]).forEach(it=>{
        const div = document.createElement('div');
        div.style.border = '1px solid #4a4a4a'; div.style.borderRadius='8px'; div.style.padding='8px'; div.style.cursor='pointer';
        div.innerHTML = `<div style="font-weight:700; color:#e9efff">${escapeHtml(it.npc_name)}</div>
          <div style="color:#9fb1c9; font-size:12px; margin:4px 0">${it.core_preview||''}</div>
          <div style="display:flex; gap:8px; flex-wrap:wrap; font-size:12px; color:#cfd9ea;">
            ${it.voiceid?('<span>Voice: '+it.voiceid+'</span>'):''}
            ${it.gender?('<span>Gender: '+it.gender+'</span>'):''}
            ${it.race?('<span>Race: '+it.race+'</span>'):''}
            <span>Extended: ${String(it.extended_filled||0)}</span>
          </div>`;
        div.addEventListener('click', ()=> loadDetail(it.npc_name));
        list.appendChild(div);
      });
      // pager
      const pages = Math.max(1, Math.ceil(total / Math.max(1,pageSize)));
      pager.innerHTML='';
      const mk = (lab, p, dis)=>{ const b=document.createElement('button'); b.textContent=lab; b.disabled=!!dis; b.addEventListener('click', ()=>{ page=p; fetchList(); }); return b; };
      pager.appendChild(mk('First', 1, page<=1));
      pager.appendChild(mk('Prev', Math.max(1,page-1), page<=1));
      const start = Math.max(1, page-2), end = Math.min(pages, page+2);
      for(let i=start;i<=end;i++){ const b=mk(String(i), i, i===page); pager.appendChild(b); }
      pager.appendChild(mk('Next', Math.min(pages,page+1), page>=pages));
      pager.appendChild(mk('Last', pages, page>=pages));
    }
    async function loadDetail(name){
      currentName = name;
      const params = new URLSearchParams({ bio_detail:'1', name:name });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok){ detail.innerHTML = '<div style="color:#ff6b6b">Failed to load detail</div>'; return; }
      const d = j.data||{};
      detail.innerHTML = `
        <div style="font-size:18px; font-weight:700; color:#e9efff;">${escapeHtml(d.npc_name||'')}</div>
        <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:10px; margin-top:8px;">
          ${kv('Backstory', d.npc_static_bio)}
          ${kv('Personality', d.personality)}
          ${kv('Appearance', d.appearance)}
          ${kv('Relationships', d.relationships)}
          ${kv('Occupation', d.occupation)}
          ${kv('Skills', d.skills)}
          ${kv('Speech Style', d.speechstyle)}
          ${kv('Goals', d.goals)}
        </div>
        <div style="margin-top:8px; color:#cfd9ea; display:flex; gap:10px; flex-wrap:wrap;">
          ${badge('VoiceID', d.voiceid)}
          ${badge('Gender', d.gender)}
          ${badge('Race', d.race)}
        </div>
        <div style="margin-top:8px; color:#cfd9ea;"><b style="color:#e6b76c">World Knowledge Tags:</b> ${escapeHtml(d.world_knowledge_tags||'N/A')}</div>
      `;
    }
    function kv(title, val){ const v=(val||'').trim(); return `<div><div style="color:#e6b76c; font-weight:700;">${title}</div><div style="white-space:pre-wrap;">${escapeHtml(v||'N/A')}</div></div>`; }
    function badge(k, v){ v=(v||'').trim(); if (!v) return ''; return `<span style="background:#3a3a3a; border:1px solid #4a4a4a; border-radius:999px; padding:3px 8px;">${k}: ${escapeHtml(v)}</span>`; }
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    let deb = null; function refetch(){ if (deb) clearTimeout(deb); deb=setTimeout(()=>{ page=1; fetchList(); }, 250); }
    if (inp) inp.addEventListener('input', refetch);
    if (letter) letter.addEventListener('change', refetch);
    if (useBtn) useBtn.addEventListener('click', async ()=>{
      if (!currentName) return;
      // Load detail and fill manual form in iframe
      const params = new URLSearchParams({ bio_detail:'1', name:currentName });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok) return;
      const d = j.data||{};
      const doc = iframe && iframe.contentDocument; if (!doc) return;
      function setVal(id, val){ const el = doc.getElementById(id); if (el) el.value = val==null?'':String(val); }
      function setChk(id, on){ const el = doc.getElementById(id); if (el && el.type==='checkbox') el.checked = !!on; }
      setVal('npc_name', d.npc_name||'');
      if (incExt && incExt.checked) {
        setVal('npc_static_bio', d.npc_static_bio||''); setVal('personality', d.personality||''); setVal('appearance', d.appearance||''); setVal('relationships', d.relationships||''); setVal('occupation', d.occupation||''); setVal('skills', d.skills||''); setVal('speechstyle', d.speechstyle||''); setVal('goals', d.goals||'');
      }
      if (incOgh && incOgh.checked) setVal('world_knowledge_tags', d.world_knowledge_tags||'');
      if (incVM && incVM.checked) { setVal('voiceid', d.voiceid||''); setVal('gender', d.gender||''); setVal('race', d.race||''); }
      if (selProfile && selProfile.value) { const el = doc.getElementById('profile_id'); if (el) el.value = selProfile.value; }
      // Switch to manual tab
      document.querySelectorAll('#npc_modal_tabs .pf-tab').forEach(t=> t.classList.toggle('active', t.getAttribute('data-pane')==='pane_manual'));
      document.getElementById('pane_manual').style.display='block'; document.getElementById('pane_manual').classList.add('active');
      document.getElementById('pane_bio').style.display='none'; document.getElementById('pane_bio').classList.remove('active');
      // Update save button state after auto-filling name
      try { if (typeof window.NPC_UPDATE_SAVE_STATE === 'function') window.NPC_UPDATE_SAVE_STATE(); } catch(_e){}
    });
    if (createBtn) createBtn.addEventListener('click', async ()=>{
      if (!currentName) return;
      const fd = new FormData();
      fd.append('import_from_bio','1');
      fd.append('name', currentName);
      fd.append('include_extended', (incExt && incExt.checked) ? '1':'');
      fd.append('include_world_knowledge', (incOgh && incOgh.checked) ? '1':'');
      fd.append('include_voice_meta', (incVM && incVM.checked) ? '1':'');
      if (selProfile && selProfile.value) fd.append('profile_id', selProfile.value);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (j && j.ok){
        window.postMessage({ type:'npc_saved', id: j.id, data: j.data }, '*');
      } else {
        alert('Import failed: '+(j && j.error ? j.error : 'Unknown'));
      }
    });
    // initial fetch
    try { fetchList(); } catch(_){}
  })();
  // Prevent browser history back/forward inside modal (mouse buttons/backspace)
  (function(){
    function blockNav(ev){ ev.preventDefault(); ev.stopPropagation(); return false; }
    window.addEventListener('popstate', blockNav, true);
    window.addEventListener('hashchange', blockNav, true);
    window.addEventListener('mousedown', function(e){ if (e.button===3 || e.button===4) { blockNav(e); } }, true);
    window.addEventListener('mouseup', function(e){ if (e.button===3 || e.button===4) { blockNav(e); } }, true);
    window.addEventListener('contextmenu', function(e){ /* noop */ }, true);
    // push a dummy state so back goes to same place
    try { history.pushState({modal:true}, document.title, location.href); } catch(_e){}
  })();
  document.querySelectorAll('.npc-card').forEach(card=>{
    card.addEventListener('click', function(ev){
      if (ev.target.closest('.npc-title-actions')) return;
      const id=this.getAttribute('data-id'); if (!id) return;
      ev.preventDefault();
      openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1');
    });
  });
  const createBtn = document.getElementById('npc_create_btn');
  if (createBtn){
    createBtn.addEventListener('click', function(){
      openModal('npc_master.php?partial=1');
    });
  }
  // Live search and alpha sort
  const searchInput = document.getElementById('npc_search');
  function updateCardLockVisualState(lockBtn, active){
    if (!lockBtn) return;
    lockBtn.classList.toggle('active', !!active);
    lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
    const card = lockBtn.closest('.npc-card');
    if (!card) return;
    const trash = card.querySelector('.btn-trash');
    if (!trash) return;
    const npcId = String(trash.getAttribute('data-delete-id') || '').trim();
    if (active) {
      trash.classList.add('disabled');
      trash.setAttribute('href', '#');
      trash.setAttribute('title', 'Locked - cannot delete');
      return;
    }
    trash.classList.remove('disabled');
    trash.setAttribute('href', npcId ? ('npc_master.php?delete=' + encodeURIComponent(npcId)) : '#');
    trash.setAttribute('title', 'Delete');
  }
  // Bulk delete wiring
  (function(){
    function bindBulk(btn){
      if (!btn) return;
      btn.addEventListener('click', function(){
        const box = document.createElement('div');
        box.style.position='fixed'; box.style.inset='0'; box.style.zIndex='10050'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.background='rgba(0,0,0,0.65)';
        box.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:520px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#ff6b6b; margin-bottom:8px;">Danger: Delete ALL unlocked NPCs</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:8px;">This will permanently delete every NPC that is not locked. The Narrator and any locked profiles will be preserved.</div>\
          <label style="display:block; font-size:13px; margin:6px 0; color:#cfd9ea;">Type <b style="color:#ffd166">Delete</b> to confirm:</label>\
          <input id="bulk_del_confirm" type="text" style="width:100%; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;"/>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="bulk_del_cancel" class="btn-cancel">Cancel</button>\
            <button id="bulk_del_ok" class="btn-danger" disabled>Delete</button>\
          </div></div>';
        document.body.appendChild(box);
        const inp = box.querySelector('#bulk_del_confirm');
        const ok  = box.querySelector('#bulk_del_ok');
        const cancel = box.querySelector('#bulk_del_cancel');
        function upd(){ ok.disabled = (String(inp.value||'').trim() !== 'Delete'); }
        inp.addEventListener('input', upd); upd(); inp.focus();
        cancel.addEventListener('click', function(){ document.body.removeChild(box); });
        ok.addEventListener('click', async function(){
          ok.disabled = true; try {
            const fd = new FormData(); fd.append('bulk_delete_npcs','1'); fd.append('confirm', String(inp.value||''));
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
            document.body.removeChild(box);
            if (j && j.ok){
              try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='Deleted '+String(j.deleted||0)+' NPCs'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_){}
              refreshList(1);
            } else {
              alert('Bulk delete failed: '+(j && j.error ? j.error : 'Unknown'));
            }
          } catch(_e){ ok.disabled=false; }
        });
      });
    }
    // expose for rebind after AJAX refresh
    window.bindNpcBulkDelete = bindBulk;
    bindBulk(document.getElementById('npc_bulk_delete_btn'));
  })();
  // Bulk profile switch wiring
  (function(){
    const profileOptions = Array.isArray(PROFILE_OPTIONS) ? PROFILE_OPTIONS : [];
    function escHtml(v){
      return String(v == null ? '' : v).replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
    }
    function buildOptions(selectedValue){
      const selected = String(selectedValue || '');
      let html = '';
      profileOptions.forEach(function(pr){
        const id = String(pr && pr.id ? pr.id : '');
        if (!id) return;
        const label = String(pr && pr.label ? pr.label : ('Profile #' + id));
        const sel = (id === selected) ? ' selected' : '';
        html += '<option value="' + escHtml(id) + '"' + sel + '>' + escHtml(label) + '</option>';
      });
      return html;
    }
    function bindBulkSwitch(btn){
      if (!btn) return;
      btn.addEventListener('click', function(){
        if (!profileOptions.length) { alert('No profiles found.'); return; }
        const filterSel = document.getElementById('npc_profile_filter');
        const sourcePref = (filterSel && filterSel.value) ? String(filterSel.value) : String((profileOptions[0] && profileOptions[0].id) || '');
        let targetPref = '';
        for (let i = 0; i < profileOptions.length; i++) {
          const pid = String(profileOptions[i] && profileOptions[i].id ? profileOptions[i].id : '');
          if (pid !== '' && pid !== sourcePref) { targetPref = pid; break; }
        }
        if (!targetPref) targetPref = sourcePref;

        const box = document.createElement('div');
        box.style.position='fixed'; box.style.inset='0'; box.style.zIndex='10050'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.background='rgba(0,0,0,0.65)';
        box.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:560px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#e6b76c; margin-bottom:8px;">Mass Switch NPC Profiles</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:12px;">Move every NPC currently on one profile to another profile in one pass. The Narrator is always excluded.</div>\
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:8px;">\
            <label style="display:flex; flex-direction:column; gap:6px; font-size:12px; color:#cfd9ea;">From profile\
              <select id="bulk_switch_source" style="padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;">' + buildOptions(sourcePref) + '</select>\
            </label>\
            <label style="display:flex; flex-direction:column; gap:6px; font-size:12px; color:#cfd9ea;">To profile\
              <select id="bulk_switch_target" style="padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;">' + buildOptions(targetPref) + '</select>\
            </label>\
          </div>\
          <label style="display:flex; align-items:center; gap:8px; margin:8px 0 12px 0; color:#cfd9ea; font-size:13px;"><input id="bulk_switch_include_locked" type="checkbox" /> Include locked NPCs</label>\
          <label style="display:block; font-size:13px; margin:6px 0; color:#cfd9ea;">Type <b style="color:#ffd166">Switch</b> to confirm:</label>\
          <input id="bulk_switch_confirm" type="text" style="width:100%; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;"/>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="bulk_switch_cancel" class="btn-cancel">Cancel</button>\
            <button id="bulk_switch_ok" class="btn-rel-build" disabled>Switch Profiles</button>\
          </div></div>';
        document.body.appendChild(box);

        const sourceEl = box.querySelector('#bulk_switch_source');
        const targetEl = box.querySelector('#bulk_switch_target');
        const includeLockedEl = box.querySelector('#bulk_switch_include_locked');
        const confirmEl = box.querySelector('#bulk_switch_confirm');
        const okEl = box.querySelector('#bulk_switch_ok');
        const cancelEl = box.querySelector('#bulk_switch_cancel');

        function updateState(){
          const confirmOk = String(confirmEl.value || '').trim() === 'Switch';
          const hasSource = !!(sourceEl && sourceEl.value);
          const hasTarget = !!(targetEl && targetEl.value);
          const different = hasSource && hasTarget && String(sourceEl.value) !== String(targetEl.value);
          okEl.disabled = !(confirmOk && hasSource && hasTarget && different);
        }
        confirmEl.addEventListener('input', updateState);
        sourceEl.addEventListener('change', updateState);
        targetEl.addEventListener('change', updateState);
        updateState();
        confirmEl.focus();

        cancelEl.addEventListener('click', function(){ document.body.removeChild(box); });
        okEl.addEventListener('click', async function(){
          okEl.disabled = true;
          try {
            const fd = new FormData();
            fd.append('bulk_switch_profile', '1');
            fd.append('source_profile_id', String(sourceEl.value || ''));
            fd.append('target_profile_id', String(targetEl.value || ''));
            fd.append('include_locked', includeLockedEl && includeLockedEl.checked ? '1' : '0');
            fd.append('confirm', String(confirmEl.value || ''));
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j = {};
            try { j = await res.json(); } catch(_){ j = { ok:false, error:'Invalid JSON response' }; }
            document.body.removeChild(box);
            if (j && j.ok){
              let msg = 'Switched ' + String(j.updated || 0) + ' NPCs';
              if (j.source_profile_label && j.target_profile_label) {
                msg += ' (' + String(j.source_profile_label) + ' -> ' + String(j.target_profile_label) + ')';
              }
              if (!j.include_locked && Number(j.skipped_locked || 0) > 0) {
                msg += '; skipped ' + String(j.skipped_locked) + ' locked';
              }
              try {
                const toast = document.getElementById('toast');
                if (toast) {
                  toast.querySelector('.message').textContent = msg;
                  toast.classList.add('show');
                  setTimeout(() => toast.classList.remove('show'), 2400);
                }
              } catch(_){}
              refreshList(1);
            } else {
              alert('Mass switch failed: ' + (j && j.error ? j.error : 'Unknown'));
            }
          } catch(_e){
            okEl.disabled = false;
          }
        });
      });
    }
    window.bindNpcBulkSwitchProfile = bindBulkSwitch;
    bindBulkSwitch(document.getElementById('npc_bulk_switch_profile_btn'));
  })();
  let listAbort = null;
  async function refreshList(page){
    const params = new URLSearchParams(window.location.search);
    const si = document.getElementById('npc_search');
    const wasFocused = document.activeElement && document.activeElement.id === 'npc_search';
    const caretStart = wasFocused && si && typeof si.selectionStart === 'number' ? si.selectionStart : null;
    const caretEnd = wasFocused && si && typeof si.selectionEnd === 'number' ? si.selectionEnd : null;
    if (si) params.set('q', si.value || '');
    const pf = document.getElementById('npc_profile_filter');
    if (pf) params.set('profile_id', pf.value || '');
    // Collect checkbox filters (prefer top bar if present else bottom)
    try {
      const fav = (document.getElementById('npc_filter_fav_top')||document.getElementById('npc_filter_fav'));
      const dyn = (document.getElementById('npc_filter_dyn_top')||document.getElementById('npc_filter_dyn'));
      const mtm = (document.getElementById('npc_filter_mtm_top')||document.getElementById('npc_filter_mtm'));
      params.set('fav', fav && fav.checked ? '1' : '');
      params.set('dyn', dyn && dyn.checked ? '1' : '');
      params.set('mtm', mtm && mtm.checked ? '1' : '');
    } catch(_e){}
    params.set('alpha', 'asc');
    if (page) params.set('page', String(page));
    params.set('list','1');
    if (listAbort) { try { listAbort.abort(); } catch(_){} }
    listAbort = new AbortController();
    const res = await fetch('npc_master.php?'+params.toString(), { signal: listAbort.signal });
    const html = await res.text();
    const temp = document.createElement('div'); temp.innerHTML = html;
    const newPag = temp.querySelector('.pagination');
    const newGrid = temp.querySelector('.npc-grid');
    if (newPag && newGrid){
      const oldPag = document.querySelector('.pagination');
      const oldGrid = document.querySelector('.npc-grid');
      if (oldPag && oldPag.parentElement) oldPag.parentElement.replaceChild(newPag, oldPag);
      if (oldGrid && oldGrid.parentElement) oldGrid.parentElement.replaceChild(newGrid, oldGrid);
      // rebind events on new elements
      document.querySelectorAll('.npc-card').forEach(card=>{
        card.addEventListener('click', function(ev){
          if (ev.target.closest('.npc-title-actions')) return;
          const id=this.getAttribute('data-id'); if (!id) return;
          ev.preventDefault();
          openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1');
        });
      });
      // Rebind filter dropdowns in refreshed DOM
      (function(){
        function bindDropdown(btnId, menuId){
          const btn = document.getElementById(btnId);
          const menu = document.getElementById(menuId);
          if (!btn || !menu) return;
          btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); menu.style.display = (menu.style.display==='none'||menu.style.display==='') ? 'block' : 'none'; });
          document.addEventListener('click', function(){ if (menu.style.display==='block') menu.style.display='none'; });
          menu.addEventListener('click', function(e){ e.stopPropagation(); });
          menu.querySelectorAll('input[type="checkbox"]').forEach(cb=> cb.addEventListener('change', function(){ refreshList(1); }));
        }
        bindDropdown('npc_filter_btn_top','npc_filter_menu_top');
        bindDropdown('npc_filter_btn','npc_filter_menu');
      })();
      document.querySelectorAll('[data-favorite-id]').forEach(btn=>{
        btn.addEventListener('click', async function(e){
          e.preventDefault(); const id = this.getAttribute('data-favorite-id');
          const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', id);
          const res = await fetch('npc_master.php', { method:'POST', body: fd }); let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.favorite||0)===1; this.classList.toggle('active', active); this.textContent = active ? '\u2605' : '\u2606'; }
        });
      });
      document.querySelectorAll('[data-lock-id]').forEach(btn=>{
        btn.addEventListener('click', async function(e){
          e.preventDefault(); const id = this.getAttribute('data-lock-id');
          const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', id);
          const res = await fetch('npc_master.php', { method:'POST', body: fd }); let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.locked||0)===1; updateCardLockVisualState(this, active); }
        });
      });
      const newCreate = document.getElementById('npc_create_btn');
      if (newCreate){ newCreate.addEventListener('click', function(){ openModal('npc_master.php?partial=1'); }); }
      // rebind bulk delete in refreshed DOM
      try { if (window.bindNpcBulkDelete) window.bindNpcBulkDelete(document.getElementById('npc_bulk_delete_btn')); } catch(_){}
      // rebind mass switch in refreshed DOM
      try { if (window.bindNpcBulkSwitchProfile) window.bindNpcBulkSwitchProfile(document.getElementById('npc_bulk_switch_profile_btn')); } catch(_){}
      // rebind Build Relationships button in refreshed DOM
      try { if (window.bindRelBuildButton) window.bindRelBuildButton(document.getElementById('rel_bulk_build_btn')); } catch(_){}
      // Hook pagination links to AJAX
      document.querySelectorAll('.pagination a[href]').forEach(a=>{
        a.addEventListener('click', function(e){
          e.preventDefault();
          const m = this.href.match(/page=(\d+)/); const p = m?parseInt(m[1],10):1; refreshList(p);
        });
      });
      const newSearch = document.getElementById('npc_search');
      if (newSearch){
        // Rebind with debounce and restore focus/caret
        newSearch.addEventListener('input', function(){ refreshListDebounced(1); });
        if (wasFocused){
          try {
            newSearch.focus();
            if (caretStart!=null && caretEnd!=null) newSearch.setSelectionRange(caretStart, caretEnd);
          } catch(_e){}
        }
      }
      const newProfileSel = document.getElementById('npc_profile_filter');
      if (newProfileSel){ newProfileSel.addEventListener('change', function(){ refreshList(1); }); }
    }
  }
  // Simple debounce for input
  let debTimer = null;
  function refreshListDebounced(page){
    if (debTimer) clearTimeout(debTimer);
    debTimer = setTimeout(()=>refreshList(page), 500)
  }
  if (searchInput){ searchInput.addEventListener('input', function(){ refreshListDebounced(1); }); }
  const profileSel = document.getElementById('npc_profile_filter');
  if (profileSel){ profileSel.addEventListener('change', function(){ refreshList(1); }); }
  // Removed alpha toggle; default remains ascending (favorites first)
  // Hook existing pagination for AJAX
  document.querySelectorAll('.pagination a[href]').forEach(a=>{
    a.addEventListener('click', function(e){ e.preventDefault(); const m = this.href.match(/page=(\d+)/); const p = m?parseInt(m[1],10):1; refreshList(p); });
  });
  // Toggle buttons
  // Filter dropdown toggles
  (function(){
    function bindDropdown(btnId, menuId){
      const btn = document.getElementById(btnId);
      const menu = document.getElementById(menuId);
      if (!btn || !menu) return;
      btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); menu.style.display = (menu.style.display==='none'||menu.style.display==='') ? 'block' : 'none'; });
      document.addEventListener('click', function(){ if (menu.style.display==='block') menu.style.display='none'; });
      menu.addEventListener('click', function(e){ e.stopPropagation(); });
      // When any checkbox changes, refetch
      menu.querySelectorAll('input[type="checkbox"]').forEach(cb=> cb.addEventListener('change', function(){ refreshList(1); }));
    }
    bindDropdown('npc_filter_btn_top','npc_filter_menu_top');
    bindDropdown('npc_filter_btn','npc_filter_menu');
  })();
  document.querySelectorAll('[data-favorite-id]').forEach(btn=>{
    btn.addEventListener('click', async function(e){
      e.preventDefault();
      const id = this.getAttribute('data-favorite-id');
      const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', id);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let json={}; try{ json=await res.json(); }catch(_e){}
      if (json && json.ok){
        const active = Number(json.favorite||0)===1;
        this.classList.toggle('active', active);
                this.textContent = active ? '\u2605' : '\u2606';
                try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent= active?'Marked favorite':'Unfavorited'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      }
    });
  });
  document.querySelectorAll('[data-lock-id]').forEach(btn=>{
    btn.addEventListener('click', async function(e){
      e.preventDefault();
      const id = this.getAttribute('data-lock-id');
      const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', id);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let json={}; try{ json=await res.json(); }catch(_e){}
      if (json && json.ok){
        const active = Number(json.locked||0)===1;
        updateCardLockVisualState(this, active);
        try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent= active?'Locked profile':'Unlocked profile'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      }
    });
  });
  // Trash/delete button handler (delegated so it also works after dynamic refresh)
  document.addEventListener('click', async function(e){
    const el = (e.target && typeof e.target.closest === 'function')
      ? e.target.closest('.btn-trash')
      : null;
    if (!el) return;

    if (el.classList.contains('disabled')) {
        e.preventDefault();
        alert('This NPC is locked and cannot be deleted.');
        return;
    }
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return;
    }

    e.preventDefault();
    const href = String(el.getAttribute('href') || '');
    let npcId = String(el.getAttribute('data-delete-id') || '').trim();
    if (!npcId) {
        const m = href.match(/[?&]delete=(\d+)/i);
        npcId = m ? String(m[1]) : '';
    }
    if (!npcId) {
        if (href) window.location.href = href;
        return;
    }
    if (!confirm('Delete this NPC?')) {
        return;
    }

    try {
        const fd = new FormData();
        fd.append('delete_npc', '1');
        fd.append('id', npcId);
        const res = await fetch('npc_master.php', { method:'POST', body: fd });
        let json = {};
        try { json = await res.json(); } catch (_e) { json = { ok:false, error:'Invalid server response' }; }
        if (!(json && json.ok)) {
            alert('Delete failed: ' + (json && json.error ? json.error : 'Unknown'));
            return;
        }
        try {
            const card = document.getElementById('npc_card_' + npcId);
            if (card && card.parentElement) card.parentElement.removeChild(card);
        } catch (_e) {}
        try {
            const toast = document.getElementById('toast');
            if (toast && toast.querySelector('.message')) {
                toast.querySelector('.message').textContent = 'NPC deleted';
                toast.classList.add('show');
                setTimeout(()=>toast.classList.remove('show'), 1600);
            }
        } catch (_e) {}
        try { if (typeof refreshList === 'function') refreshList(); } catch (_e) {}
    } catch (_e) {
        if (href) window.location.href = href;
    }
  });
  // Receive save events from iframe and update the card inline
  window.addEventListener('message', async function(e){
    const d = e.data || {};
    if (d.type === 'npc_saved'){
      const id = String(d.id||'');
      const data = d.data || {};
      let card = document.getElementById('npc_card_'+id);
      if (!card){
        // Create a new card at the start of the grid
        const grid = document.querySelector('.npc-grid');
        if (grid){
          const div = document.createElement('div');
          div.className = 'npc-card';
          div.id = 'npc_card_'+id;
          div.setAttribute('data-id', id);
          div.innerHTML = `
            <div class="npc-title">
              <div class="npc-title-left"><span class="npc-name"></span></div>
              <div class="npc-title-actions">
                <span class="npc-tags-top" style="display:none"></span>
                <a class="btn btn-toggle" href="#" data-favorite-id="${id}" title="Toggle favorite">&#9734;</a>
                <a class="btn btn-toggle" href="#" data-lock-id="${id}" title="Toggle lock - Locked profiles are protected from save rollback when loading saves">&#x1F513;</a>
                <a class="btn btn-trash" data-delete-id="${id}" href="npc_master.php?delete=${id}" title="Delete">&#x1F5D1;&#xFE0F;</a>
              </div>
            </div>
            <div class="npc-divider"></div>
            <div class="npc-row">
              <div class="npc-fields">
                <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"></span></div>
                <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"></span></div>
                <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"></span></div>
                <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"></span></div>
                <div class="npc-line"><span class="npc-muted">Bounty:</span> <span class="npc-bounty">0</span></div>
                <div class="npc-bounty-section" style="display:none">
                  <div class="npc-bounty-heading">Bounty Breakdown</div>
                  <div class="npc-bounty-breakdown"></div>
                </div>
              </div>
              <div class="npc-right"></div>
            </div>
            `;
          grid.prepend(div);
          // Wire edit button
          div.addEventListener('click', function(ev){ if (ev.target.closest('.npc-title-actions')) return; ev.preventDefault(); openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1'); });
          card = div;
        }
      }
      if (card){
        const setText = (sel, val)=>{ const el = card.querySelector(sel); if (el) el.textContent = val==null?'':String(val); };
        setText('.npc-name', data.npc_name);
        setText('.npc-gender', data.gender);
        setText('.npc-race', data.race);
        setText('.npc-voiceid', data.voiceid);
        try {
          const normalizeObj = (raw) => {
            if (!raw) return null;
            if (typeof raw === 'object') return raw;
            const txt = String(raw).trim();
            if (!txt) return null;
            try {
              const parsed = JSON.parse(txt);
              return (parsed && typeof parsed === 'object') ? parsed : null;
            } catch(_e) {
              return null;
            }
          };

          const parsePositiveInt = (value) => {
            if (typeof value === 'number' && Number.isFinite(value)) {
              return Math.max(0, Math.trunc(value));
            }
            const txt = String(value == null ? '' : value).trim();
            if (!txt) return 0;
            const parsed = parseInt(txt.replace(/[^0-9-]/g, ''), 10);
            if (!Number.isFinite(parsed) || parsed <= 0) return 0;
            return parsed;
          };
          const normalizeReasons = (raw) => {
            if (Array.isArray(raw)) {
              return raw
                .map((v) => String(v || '').trim())
                .filter((v) => v !== '')
                .slice(0, 8);
            }
            if (typeof raw === 'string' && raw.trim() !== '') {
              return raw
                .split(/[|,;]+/)
                .map((v) => String(v || '').trim())
                .filter((v) => v !== '')
                .slice(0, 8);
            }
            return [];
          };

          const bountyObj = normalizeObj(data.bounty_payload) || normalizeObj(data.bounty);
          const breakdownRows = [];
          let bountyTotal = 0;

          if (bountyObj && typeof bountyObj === 'object') {
            bountyTotal = parsePositiveInt(bountyObj.total || bountyObj.amount || bountyObj.value || bountyObj.cats || bountyObj.bounty);
            if (Array.isArray(bountyObj.factions)) {
              let computedTotal = 0;
              bountyObj.factions.forEach((f) => {
                if (!f || typeof f !== 'object') return;
                const faction = String(f.faction || f.faction_id || 'Unknown faction').trim();
                const amount = parsePositiveInt(f.amount || f.total || f.value || f.cats || f.bounty);
                const reasons = normalizeReasons(f.what_for || f.crimes || f.reason || f.reasons || []);
                if (!faction && amount <= 0 && reasons.length === 0) return;
                computedTotal += amount;
                breakdownRows.push({ faction: faction || 'Unknown faction', amount, reasons });
              });
              if (bountyTotal <= 0 && computedTotal > 0) {
                bountyTotal = computedTotal;
              }
            }
          }

          if (bountyTotal <= 0 && Object.prototype.hasOwnProperty.call(data, 'bounty')) {
            bountyTotal = parsePositiveInt(data.bounty);
          }
          setText('.npc-bounty', bountyTotal > 0 ? (bountyTotal.toLocaleString() + ' cats') : '0');

          let legacyWanted = '';
          if (Object.prototype.hasOwnProperty.call(data, 'metadata')) {
            const metaObj = normalizeObj(data.metadata);
            if (metaObj && typeof metaObj.bounty_text === 'string' && metaObj.bounty_text.trim() !== '') {
              legacyWanted = metaObj.bounty_text.trim();
            }
          }

          const breakdownWrap = card.querySelector('.npc-bounty-section');
          const breakdownOut = card.querySelector('.npc-bounty-breakdown');
          if (breakdownWrap && breakdownOut) {
            breakdownOut.textContent = '';
            const maxRows = 4;
            if (breakdownRows.length > 0) {
              breakdownRows.slice(0, maxRows).forEach((row) => {
                const item = document.createElement('div');
                item.className = 'npc-bounty-item';

                const top = document.createElement('div');
                top.className = 'npc-bounty-item-top';

                const factionEl = document.createElement('span');
                factionEl.className = 'npc-bounty-faction';
                factionEl.textContent = row.faction;
                top.appendChild(factionEl);

                if (row.amount > 0) {
                  const amountEl = document.createElement('span');
                  amountEl.className = 'npc-bounty-amount';
                  amountEl.textContent = row.amount.toLocaleString() + ' cats';
                  top.appendChild(amountEl);
                }

                item.appendChild(top);

                if (row.reasons.length > 0) {
                  const crimesEl = document.createElement('div');
                  crimesEl.className = 'npc-bounty-crimes';
                  crimesEl.textContent = 'Wanted for: ' + row.reasons.join(', ');
                  item.appendChild(crimesEl);
                }

                breakdownOut.appendChild(item);
              });

              if (breakdownRows.length > maxRows) {
                const more = document.createElement('div');
                more.className = 'npc-bounty-more';
                more.textContent = '+' + String(breakdownRows.length - maxRows) + ' more faction(s)';
                breakdownOut.appendChild(more);
              }
              breakdownWrap.style.display = '';
            } else if (legacyWanted) {
              const legacy = document.createElement('div');
              legacy.className = 'npc-bounty-legacy';
              legacy.textContent = legacyWanted;
              breakdownOut.appendChild(legacy);
              breakdownWrap.style.display = '';
            } else {
              breakdownWrap.style.display = 'none';
            }
          }
        } catch(_e){}
        // Update title tags pill
        try {
          const top = card.querySelector('.npc-title-actions .npc-tags-top');
          const tval = (data.tags||'').trim();
          if (top){
            if (tval){ top.style.display='inline-block'; top.textContent = tval; top.title = 'Use Search to filter by these tags: ' + tval; }
            else { top.style.display='none'; top.textContent=''; top.removeAttribute('title'); }
          }
        } catch(_){}
        const profId = String(data.profile_id||'');
        setText('.npc-profile', PROFILES_BY_ID[profId] || '');
        // Toggle Middle-term memory icon based on metadata override (legacy extended_data fallback).
        try {
          const mtm = (function(){
            const metaRaw = String(data.metadata||'').trim();
            if (metaRaw) {
              try {
                const m = JSON.parse(metaRaw);
                if (m && typeof m === 'object' && Object.prototype.hasOwnProperty.call(m, 'MIDDLE_TERM_MEMORY_ENABLED')) {
                  return Number(m.MIDDLE_TERM_MEMORY_ENABLED) === 1 ? 1 : 0;
                }
              } catch(_e){}
            }
            const raw = String(data.extended_data||'').trim();
            if (!raw) return 0;
            try { const o = JSON.parse(raw); return (o && Number(o.middle_term_enabled||0)===1) ? 1 : 0; } catch(_e){ return 0; }
          })();
          const left = card.querySelector('.npc-title-left');
          if (left){
            let icon = left.querySelector('.npc-mtm-icon');
            if (mtm){ if (!icon){ icon = document.createElement('span'); icon.className='npc-mtm-icon'; icon.title='Middle-term memory enabled'; icon.textContent='\u{1F4C3}'; left.appendChild(icon); } }
            else { if (icon){ icon.remove(); } }
          }
        } catch(_e){}
        // Toggle Individual memory bank icon based on extended_data flag.
        try {
          const left = card.querySelector('.npc-title-left');
          if (left){
            let imbEnabled = 0;
            const rawExt = String(data.extended_data || '').trim();
            if (rawExt) {
              try {
                const ext = JSON.parse(rawExt);
                if (ext && typeof ext === 'object' && Object.prototype.hasOwnProperty.call(ext, 'individual_memory_enabled')) {
                  const raw = ext.individual_memory_enabled;
                  imbEnabled = (raw === true || Number(raw) === 1 || String(raw).toLowerCase() === 'true') ? 1 : 0;
                }
              } catch(_e){}
            }
            let icon = left.querySelector('.npc-imb-icon');
            if (imbEnabled){
              if (!icon){
                icon = document.createElement('span');
                icon.className = 'npc-imb-icon';
                icon.title = 'Individual memory bank enabled';
                icon.textContent = '\u{1F9E0}';
                left.appendChild(icon);
              }
            } else if (icon) {
              icon.remove();
            }
          }
        } catch(_e){}
      }
      closeModal();
      try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='NPC saved'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_e){}
    }
  });
})();

// Build Relationships Modal functionality
(function(){
  async function loadModalStats(){
    // Load model info and NPC counts
    try {
      const res = await fetch('../ext/relationship_system/batch_build.php?action=stats');
      const data = await res.json();
      if (data.ok){
        document.getElementById('rel_build_model').textContent = data.model || 'Not configured';
        document.getElementById('rel_count_built').textContent = data.built || 0;
        document.getElementById('rel_count_pending').textContent = data.pending || 0;
      }
    } catch(e){
      document.getElementById('rel_build_model').textContent = 'Error loading';
    }
  }

  function bindRelBuildButton(btn){
    if (!btn) return;
    btn.addEventListener('click', function(e){
      e.preventDefault();
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Reset state
        document.getElementById('rel_build_content').style.display = 'block';
        document.getElementById('rel_build_progress').style.display = 'none';
        document.getElementById('rel_build_log').innerHTML = '';
        document.getElementById('rel_build_bar').style.width = '0%';
        document.getElementById('rel_build_count').textContent = '0 / 0';
        document.getElementById('rel_build_status').textContent = 'Ready';
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'none';
        // Load stats
        loadModalStats();
      }
    });
  }

  // Bind both buttons (AJAX partial and main page)
  bindRelBuildButton(document.getElementById('rel_bulk_build_btn'));

  // Make it available for rebinding after AJAX refresh
  window.bindRelBuildButton = bindRelBuildButton;

  // Close button
  const closeBtn = document.getElementById('rel_build_close');
  if (closeBtn){
    closeBtn.addEventListener('click', function(){
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
      }
    });
  }

  // Start button
  const startBtn = document.getElementById('rel_build_start');
  if (startBtn){
    startBtn.addEventListener('click', async function(){
      const force = document.getElementById('rel_build_force').checked ? 1 : 0;
      const infer = document.getElementById('rel_build_infer').checked ? 1 : 0;

      // Show progress
      document.getElementById('rel_build_content').style.display = 'none';
      document.getElementById('rel_build_progress').style.display = 'block';

      const logEl = document.getElementById('rel_build_log');
      const barEl = document.getElementById('rel_build_bar');
      const countEl = document.getElementById('rel_build_count');
      const statusEl = document.getElementById('rel_build_status');

      function log(msg, type){
        const line = document.createElement('div');
        line.textContent = msg;
        if (type === 'error') line.style.color = '#ff6b6b';
        else if (type === 'success') line.style.color = '#69db7c';
        else if (type === 'info') line.style.color = '#e6b76c';
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
      }

      try {
        log('Starting relationship build...', 'info');
        statusEl.textContent = 'Fetching NPC list...';

        // Fetch list of NPCs to process
      const listRes = await fetch('../ext/relationship_system/batch_build.php?action=list&force=' + force);
        const listData = await listRes.json();

        if (!listData.ok){
          log('Error: ' + (listData.error || 'Failed to get NPC list'), 'error');
          statusEl.textContent = 'Failed';
          return;
        }

        const npcs = listData.npcs || [];
        const total = npcs.length;

        if (total === 0){
          log('No NPCs need processing.', 'info');
          statusEl.textContent = 'Complete';
          barEl.style.width = '100%';
          return;
        }

        log('Found ' + total + ' NPCs to process.', 'info');
        countEl.textContent = '0 / ' + total;

        let processed = 0;
        let success = 0;
        let failed = 0;

        // Process each NPC
        for (const npc of npcs){
          statusEl.textContent = 'Processing: ' + npc.name;

          try {
        const res = await fetch('../ext/relationship_system/batch_build.php?action=process&id=' + npc.id + '&force=' + force);
            const data = await res.json();

            if (data.ok){
              success++;
              log('? ' + npc.name + ': ' + (data.count || 0) + ' relationships', 'success');
            } else {
              failed++;
              log('? ' + npc.name + ': ' + (data.error || 'Failed'), 'error');
            }
          } catch(e){
            failed++;
            log('? ' + npc.name + ': Network error', 'error');
          }

          processed++;
          countEl.textContent = processed + ' / ' + total;
          barEl.style.width = Math.round((processed / total) * 100) + '%';
        }

        // Run inference if requested
        if (infer && success > 0){
          statusEl.textContent = 'Running transitive inference...';
          log('Running transitive inference...', 'info');

          try {
      const infRes = await fetch('../ext/relationship_system/batch_build.php?action=infer');
            const infData = await infRes.json();
            if (infData.ok){
              log('? Inference complete: ' + (infData.count || 0) + ' relationships updated', 'success');
            }
          } catch(e){
            log('Inference skipped due to error', 'error');
          }
        }

        statusEl.textContent = 'Complete';
        log('Done! ' + success + ' succeeded, ' + failed + ' failed.', 'info');

        // Show Done button
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'inline-block';

      } catch(e){
        log('Error: ' + e.message, 'error');
        statusEl.textContent = 'Failed';
        // Show Done button even on error
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'inline-block';
      }
    });
  }

  // Done button handler
  const doneBtn = document.getElementById('rel_build_done');
  if (doneBtn){
    doneBtn.addEventListener('click', function(){
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
      }
    });
  }
})();

// NPC Export/Import functionality
(function(){
  // Export button in modal header
  const exportBtn = document.getElementById('npc_modal_export');
  if (exportBtn) {
    exportBtn.addEventListener('click', function(){
      const id = window.CURRENT_NPC_ID;
      if (!id) { alert('No NPC selected. Save the NPC first before exporting.'); return; }
      // Trigger download by navigating to export URL
      window.location.href = 'npc_master.php?export=' + id;
    });
  }
  
  // Import Bio to current NPC button in modal header (only for existing NPCs)
  const importToBtn = document.getElementById('npc_modal_import_to');
  if (importToBtn) {
    importToBtn.addEventListener('click', function(){
      const id = window.CURRENT_NPC_ID;
      if (!id) { alert('No NPC selected. Save the NPC first before importing.'); return; }
      
      // Create file input
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.json';
      input.style.display = 'none';
      document.body.appendChild(input);
      
      input.addEventListener('change', async function(){
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const text = await file.text();
        
        try {
          const data = JSON.parse(text);
          if (!confirm('Import biography from "' + (data.npc_name || 'Unknown') + '" to this NPC?\n\nThis will overwrite the current NPC\'s biography fields (personality, appearance, skills, etc.) but keep the name.')) {
            return;
          }
          
          const formData = new FormData();
          formData.append('import_npc', '1');
          formData.append('import_data', text);
          formData.append('target_id', id);
          
          const res = await fetch('npc_master.php', { method: 'POST', body: formData });
          const result = await res.json();
          
          if (result.ok) {
            alert(result.message || 'Biography imported successfully');
            location.reload();
          } else {
            alert('Error: ' + (result.error || 'Import failed'));
          }
        } catch(e) {
          alert('Error parsing JSON file: ' + e.message);
        } finally {
          document.body.removeChild(input);
        }
      });
      
      input.click();
    });
  }
  
  // Import NPC button in toolbar (create new NPC from JSON)
  const importBtn = document.getElementById('npc_import_btn');
  if (importBtn) {
    importBtn.addEventListener('click', function(){
      // Create file input
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.json';
      input.style.display = 'none';
      document.body.appendChild(input);
      
      input.addEventListener('change', async function(){
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const text = await file.text();
        
        try {
          const data = JSON.parse(text);
          const originalName = data.npc_name || '';
          
          // Show dialog to confirm or change name
          const newName = prompt(
            'Import NPC from file.\n\n' +
            'Original name: ' + (originalName || '(none)') + '\n\n' +
            'Enter NPC name (leave as-is or change for renamed NPCs):',
            originalName
          );
          
          if (newName === null) {
            // User cancelled
            return;
          }
          
          if (!newName.trim()) {
            alert('NPC name is required');
            return;
          }
          
          const formData = new FormData();
          formData.append('import_npc', '1');
          formData.append('import_data', text);
          formData.append('new_name', newName.trim());
          
          const res = await fetch('npc_master.php', { method: 'POST', body: formData });
          const result = await res.json();
          
          if (result.ok) {
            alert(result.message || 'NPC imported successfully');
            location.reload();
          } else {
            alert('Error: ' + (result.error || 'Import failed'));
          }
        } catch(e) {
          alert('Error parsing JSON file: ' + e.message);
        } finally {
          document.body.removeChild(input);
        }
      });
      
      input.click();
    });
  }
})();

</script>

<?php
 // Provides a JSON editor for metadata field and form consolidation function (only needed if metadata field is present)
 // Hide metadata editor in modal partial view
 if (!(isset($_GET['partial']) && $_GET['partial']=='1')) {
     include(__DIR__."/tmpl/metadata_json_editor.php");
 }
// Provides Datatables
 include(__DIR__."/tmpl/data_tables.php");
?>

    <div id="toast" class="toast-notification">
        <span class="message"></span>
    </div>

</main>

<?php
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?>

