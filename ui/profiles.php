<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function is_embed(): bool {
    if (isset($_GET['embed'])) {
        return strval($_GET['embed']) === '1';
    }
    if (isset($_POST['embed'])) {
        return strval($_POST['embed']) === '1';
    }
    return false;
}
function web_root(): string {
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPos = strpos($scriptPath, '/ui/');
    $root = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : dirname(dirname($scriptPath));
    if ($root === '/' || $root === '\\') { $root = ''; }
    return rtrim($root, '/');
}
function page_url(array $params = []): string {
    $query = is_embed() ? ['embed' => '1'] : [];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') { continue; }
        $query[$k] = (string)$v;
    }
    return 'profiles.php' . (count($query) ? ('?' . http_build_query($query)) : '');
}
function normalize_json_obj(mixed $raw, string $fallback = '{}'): array {
    if (is_array($raw)) { return $raw; }
    $text = trim(strval($raw));
    if ($text === '') {
        $decoded = json_decode($fallback, true);
        return is_array($decoded) ? $decoded : [];
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}
function pretty_json(mixed $raw): string {
    $arr = normalize_json_obj($raw, getDefaultCoreProfileMetadataJson());
    $json = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}
function post_int_or_null(string $key): ?int {
    $raw = trim(strval($_POST[$key] ?? ''));
    return $raw === '' ? null : intval($raw);
}
function apply_visual_metadata_merge(array $base, array $metaVis): array {
    $intKeys = [
        'DIARY_DAYS',
        'BORED_EVENT_CHANCE',
        'DIARY_COOLDOWN',
        'CONTEXT_HISTORY',
        'RECHAT_RESPONSES',
        'RECHAT_PROBABILITY',
        'CONTEXT_HISTORY_DIARY',
        'CONTEXT_HISTORY_DYNAMIC_PROFILE',
    ];
    foreach ($intKeys as $key) {
        if (!array_key_exists($key, $metaVis)) {
            continue;
        }
        $raw = trim(strval($metaVis[$key] ?? ''));
        if ($raw === '') {
            unset($base[$key]);
            continue;
        }
        $base[$key] = intval($raw);
    }
    if (array_key_exists('BORED_EVENT_CHANCE', $metaVis)) {
        unset($base['BORED_EVENT']);
    }

    $boolKeys = ['DYNAMIC_PROFILE_ENABLED', 'MIDDLE_TERM_MEMORY_ENABLED'];
    foreach ($boolKeys as $key) {
        if (!array_key_exists($key, $metaVis)) {
            continue;
        }
        $base[$key] = coerceBoolean($metaVis[$key] ?? false);
    }
    unset($base['AUTO_DIARY_ENABLED']);

    if (array_key_exists('DIARY_PROMPT', $metaVis)) {
        $prompt = trim(strval($metaVis['DIARY_PROMPT'] ?? ''));
        if ($prompt === '') {
            unset($base['DIARY_PROMPT']);
        } else {
            $base['DIARY_PROMPT'] = $prompt;
        }
    }

    if (array_key_exists('DYNAMIC_PROFILE_FIELDS', $metaVis)) {
        $allowed = ['personality', 'occupation', 'speechstyle', 'goals'];
        $rawFields = $metaVis['DYNAMIC_PROFILE_FIELDS'];
        $rawFields = is_array($rawFields) ? $rawFields : [$rawFields];
        $fields = [];
        foreach ($rawFields as $value) {
            $val = trim(strval($value));
            if ($val === '' || !in_array($val, $allowed, true)) {
                continue;
            }
            if (!in_array($val, $fields, true)) {
                $fields[] = $val;
            }
        }
        if (count($fields) > 0) {
            $base['DYNAMIC_PROFILE_FIELDS'] = $fields;
        } else {
            unset($base['DYNAMIC_PROFILE_FIELDS']);
        }
    }

    return $base;
}
function unique_profile_label(string $base, int $excludeId = 0): string {
    $db = $GLOBALS['db'];
    $candidate = trim($base) !== '' ? trim($base) : 'Profile';
    $i = 2;
    while (true) {
        $row = $db->fetchOne("SELECT id FROM core_profiles WHERE LOWER(label)=LOWER($1) LIMIT 1", [$candidate]);
        $existing = intval($row['id'] ?? 0);
        if ($existing <= 0 || $existing === $excludeId) { return $candidate; }
        $candidate = trim($base) . ' (' . $i . ')';
        $i++;
        if ($i > 200) { return trim($base) . ' ' . time(); }
    }
}

$db = $GLOBALS['db'];
$isEmbed = is_embed();
$webRoot = web_root();
if ($isEmbed && !isset($_GET['embed'])) {
    $_GET['embed'] = '1';
}

if (isset($_GET['create_blank']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = saveCoreProfile([
        'label' => unique_profile_label('New Profile'),
        'is_default_npc' => false,
        'prompt_head' => '',
        'profile_prompt' => '',
        'metadata' => getDefaultCoreProfileMetadata(),
    ]);
    header('Location: ' . page_url(['edit' => $id, 'notice' => 'created']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $id = intval($_POST['id'] ?? 0);
    $label = trim(strval($_POST['label'] ?? ''));
    if ($label === '') {
        header('Location: ' . page_url(['edit' => $id, 'error' => 'Label is required']));
        exit;
    }
    $metadata = normalize_json_obj($_POST['metadata'] ?? '', getDefaultCoreProfileMetadataJson());
    if (isset($_POST['meta_vis']) && is_array($_POST['meta_vis'])) {
        $metadata = apply_visual_metadata_merge($metadata, $_POST['meta_vis']);
    }
    $defaultRaw = $_POST['is_default_npc'] ?? null;
    $isDefaultNpcPost = false;
    if (is_array($defaultRaw)) {
        foreach ($defaultRaw as $candidate) {
            if (coerceBoolean($candidate)) {
                $isDefaultNpcPost = true;
                break;
            }
        }
    } else {
        $isDefaultNpcPost = coerceBoolean($defaultRaw);
    }
    $savedId = saveCoreProfile([
        'id' => $id,
        'label' => $id > 0 ? unique_profile_label($label, $id) : unique_profile_label($label),
        'is_default_npc' => $isDefaultNpcPost,
        'prompt_head' => strval($_POST['prompt_head'] ?? ''),
        'profile_prompt' => strval($_POST['profile_prompt'] ?? ''),
        'response_connector' => post_int_or_null('response_connector'),
        'diary_connector' => post_int_or_null('diary_connector'),
        'autochat_connector' => post_int_or_null('autochat_connector'),
        'middleterm_connector' => post_int_or_null('middleterm_connector'),
        'backgroundlife_connector' => post_int_or_null('backgroundlife_connector'),
        'dynamic_connector' => post_int_or_null('dynamic_connector'),
        'relationship_connector' => post_int_or_null('relationship_connector'),
        'tts_connector_id' => post_int_or_null('tts_connector_id'),
        'metadata' => $metadata,
    ]);
    header('Location: ' . page_url(['edit' => $savedId, 'notice' => 'Saved Profile']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_profile'])) {
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId > 0) { deleteCoreProfile($deleteId); }
    header('Location: ' . page_url(['notice' => 'deleted']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clone_profile'])) {
    $sourceId = intval($_POST['id'] ?? 0);
    $source = getCoreProfileById($sourceId);
    if ($source) {
        $newId = saveCoreProfile([
            'label' => unique_profile_label(trim(strval($source['label'] ?? 'Profile')) . ' (Copy)'),
            'is_default_npc' => false,
            'prompt_head' => strval($source['prompt_head'] ?? ''),
            'profile_prompt' => strval($source['profile_prompt'] ?? ''),
            'response_connector' => $source['response_connector'] ?? null,
            'diary_connector' => $source['diary_connector'] ?? null,
            'autochat_connector' => $source['autochat_connector'] ?? null,
            'middleterm_connector' => $source['middleterm_connector'] ?? null,
            'backgroundlife_connector' => $source['backgroundlife_connector'] ?? null,
            'dynamic_connector' => $source['dynamic_connector'] ?? null,
            'relationship_connector' => $source['relationship_connector'] ?? null,
            'tts_connector_id' => $source['tts_connector_id'] ?? null,
            'metadata' => normalize_json_obj($source['metadata'] ?? '{}', getDefaultCoreProfileMetadataJson()),
        ]);
        header('Location: ' . page_url(['edit' => $newId, 'notice' => 'cloned']));
        exit;
    }
}

if (isset($_GET['export_profile'])) {
    $exportId = intval($_GET['export_profile']);
    $profile = getCoreProfileById($exportId);
    if (!$profile) {
        http_response_code(404);
        echo 'Profile not found';
        exit;
    }
    $payload = [
        'export_version' => '2.0-stobe',
        'export_date' => date('c'),
        'profile' => $profile,
    ];
    $filename = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(strval($profile['label'] ?? 'profile')));
    if (!is_string($filename) || $filename === '') { $filename = 'profile'; }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '_profile.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$profiles = getAllCoreProfiles();
if (!is_array($profiles) || count($profiles) === 0) {
    ensureDefaultCoreProfile();
    $profiles = getAllCoreProfiles();
}
$llmRows = getAllLlmConnectors();
$ttsRows = getAllTtsConnectors();

$llmNameById = [];
foreach ($llmRows as $row) { $llmNameById[strval($row['id'])] = strval($row['name'] ?? ('LLM #' . strval($row['id']))); }
$ttsNameById = [];
foreach ($ttsRows as $row) { $ttsNameById[strval($row['id'])] = strval($row['name'] ?? ('TTS #' . strval($row['id']))); }

$usageRows = $db->fetchAll("SELECT profile_id, COUNT(*) AS c FROM core_npc_master WHERE profile_id IS NOT NULL GROUP BY profile_id");
$usageById = [];
foreach (($usageRows ?? []) as $row) { $usageById[strval($row['profile_id'] ?? '')] = intval($row['c'] ?? 0); }

$editId = intval($_GET['edit'] ?? 0);
if ($editId <= 0 && isset($profiles[0]['id'])) { $editId = intval($profiles[0]['id']); }
$editItem = $editId > 0 ? getCoreProfileById($editId) : false;
if (!$editItem && isset($profiles[0]['id'])) { $editId = intval($profiles[0]['id']); $editItem = getCoreProfileById($editId); }

$notice = trim(strval($_GET['notice'] ?? ''));
$error = trim(strval($_GET['error'] ?? ''));
$metaDefaults = getDefaultCoreProfileMetadata();
$metaData = normalize_json_obj(($editItem['metadata'] ?? []), getDefaultCoreProfileMetadataJson());
$metaInt = static function (string $key) use ($metaData, $metaDefaults): string {
    $fallback = intval($metaDefaults[$key] ?? 0);
    return strval(intval($metaData[$key] ?? $fallback));
};
$metaBool = static function (string $key) use ($metaData, $metaDefaults): bool {
    $fallback = coerceBoolean($metaDefaults[$key] ?? false);
    if (!array_key_exists($key, $metaData)) {
        return $fallback;
    }
    return coerceBoolean($metaData[$key]);
};
$metaBoredEventChance = array_key_exists('BORED_EVENT_CHANCE', $metaData)
    ? intval($metaData['BORED_EVENT_CHANCE'])
    : (
        array_key_exists('BORED_EVENT', $metaData)
            ? intval($metaData['BORED_EVENT'])
            : intval($metaDefaults['BORED_EVENT_CHANCE'] ?? ($metaDefaults['BORED_EVENT'] ?? 50))
    );
$dynamicFieldOptions = ['personality', 'occupation', 'speechstyle', 'goals'];
$dynamicFieldCurrent = $metaData['DYNAMIC_PROFILE_FIELDS'] ?? ($metaDefaults['DYNAMIC_PROFILE_FIELDS'] ?? []);
$dynamicFieldCurrent = is_array($dynamicFieldCurrent) ? array_values(array_map('strval', $dynamicFieldCurrent)) : [];

$TITLE = 'Stobe Profiles';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<style>
main { padding: 30px 5px 5px; }
.layout { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 14px; align-items: start; position: relative; isolation: isolate; }
@media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
.cardx { border: 1px solid #3a3a3a; border-radius: 10px; background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(34,34,34,.98)); padding: 12px; }
.profiles-list-panel { position: relative; z-index: 2; }
.profile-editor-panel { min-width: 0; }
.page-header { background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98)); padding: 20px; border-radius: 10px; border: 1px solid #3a3a3a; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03); text-align: center; margin-bottom: 30px; }
.page-header h1.api-title { margin-bottom: 8px; }
.page-subtitle { color: #bbb; font-size: 1.1em; margin: 0; }
h1.api-title { margin: 0 0 20px 0; font-family: 'MagicCards', serif; word-spacing: 8px; font-size: 2.2em; color: #e6b76c; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); text-align: center; }
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.list { display: flex; flex-direction: column; gap: 8px; max-height: 70vh; overflow: auto; }
.item { border: 1px solid #454545; border-radius: 8px; padding: 10px; background: rgba(20,20,20,.35); position: relative; z-index: 3; }
.item.active { border-color: #e6b76c; }
.item-open-form { margin: 0; pointer-events: auto; position: relative; z-index: 4; }
.item-open-btn { display: block; width: 100%; border: 0; background: transparent; padding: 0; margin: 0; text-align: left; color: inherit; cursor: pointer; }
.item-title { display: flex; justify-content: space-between; align-items: center; pointer-events: none; }
.item-title .item-label { color: #e7edf7; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; pointer-events: none; }
.default-icon { font-size: 12px; color: #e6b76c; line-height: 1; }
.badge-default { border: 1px solid rgba(109,209,156,.45); color: #9be29b; border-radius: 999px; padding: 2px 8px; font-size: 11px; }
.item-sub { color: #9fb1c9; font-size: 12px; margin-top: 4px; }
.item-actions { display: flex; gap: 6px; margin-top: 8px; position: relative; z-index: 5; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 14px; }
@media (max-width: 980px) { .form-grid { grid-template-columns: 1fr; } }
label { color: #cdd6e2; font-size: 12px; margin-bottom: 4px; display: block; font-weight: 600; }
input[type='text'], select, textarea { width: 100%; background: rgba(26,26,26,.92); color: #f0f5ff; border: 1px solid #4a4a4a; border-radius: 7px; padding: 8px 10px; }
.connector-help-inline { color:#9fb1c9; font-size:12px; margin-top:6px; line-height:1.35; }
textarea { min-height: 90px; resize: vertical; }
textarea.meta { min-height: 220px; font-family: Consolas, 'Courier New', monospace; font-size: 12px; }
.btn-save, .btn-secondary, .btn-danger { border: 1px solid #4a4a4a; border-radius: 8px; padding: 8px 12px; cursor: pointer; color: #fff; text-decoration: none; background: #3a3a3a; }
.btn-save { background: linear-gradient(180deg, #176529, #125021); border-color: #2b7d3d; }
.btn-danger { background: linear-gradient(180deg, #8a1a1a, #6b1313); border-color: #992c2c; }
.btn-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.notice { margin-bottom: 8px; border-radius: 8px; padding: 8px 10px; font-size: 13px; }
.ok { background: rgba(38,89,53,.45); border: 1px solid rgba(109,209,156,.5); color: #9be29b; }
.err { background: rgba(97,30,30,.45); border: 1px solid rgba(255,107,107,.5); color: #ff9b9b; }
.meta-box { margin-top: 12px; border: 1px solid #454545; border-radius: 8px; padding: 12px; background: rgba(20,20,20,.35); }
.meta-box h3 { margin: 0 0 10px 0; color: #e6b76c; font-size: 1.08em; }
.meta-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 12px; }
@media (max-width: 980px) { .meta-grid { grid-template-columns: 1fr; } }
.meta-toggle-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
.meta-toggle { display:inline-flex; align-items:center; gap:8px; border:1px solid #4a4a4a; border-radius:999px; padding:6px 12px; background: rgba(28,28,28,.72); color:#d9e1ef; }
.meta-help { color:#9fb1c9; font-size:12px; margin-top:4px; }
.meta-fields { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.meta-chip { display:inline-flex; align-items:center; gap:6px; border:1px solid #4a4a4a; border-radius:999px; padding:6px 10px; background: rgba(28,28,28,.72); }
.meta-advanced { margin-top: 12px; border: 1px solid #4a4a4a; border-radius: 8px; padding: 10px; background: rgba(15,15,15,.35); }
.meta-advanced > summary { cursor: pointer; color: #dfe6f4; font-weight: 600; }
.provider-card { background:#2a2a2a; border:1px solid #4a4a4a; border-radius:8px; padding:12px; margin-bottom:10px; }
.provider-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.provider-title { display:flex; align-items:center; gap:10px; color:#e0e0e0; font-weight:700; }
.provider-icon { width:22px; text-align:center; color: #e6b76c; }
.provider-body { display:block; }
.setting-row { display:grid; grid-template-columns: minmax(260px, 1fr) minmax(240px, 340px); gap:10px 14px; align-items:center; padding:7px 0; border-top:1px solid rgba(255,255,255,0.04); }
.setting-row:first-child { border-top: 0; }
@media (max-width: 980px) { .setting-row { grid-template-columns: 1fr; } }
.setting-key { font-size: 12px; color:#f0f5ff; font-weight:700; margin-bottom: 2px; }
.setting-desc { font-size: 12px; color:#9fb1c9; line-height:1.35; }
.range-pair { display:flex; align-items:center; gap:8px; }
.range-pair input[type='range'] { flex:1; accent-color: #e6b76c; }
.range-pair input[type='number'] { width:86px; min-width:86px; text-align:right; }
.toggle-grid { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:10px; }
@media (max-width: 980px) { .toggle-grid { grid-template-columns: 1fr; } }
.toggle-card { border:1px solid #3f3f3f; border-radius:8px; padding:10px; background: rgba(24,24,24,.7); }
.toggle-card label { display:flex; align-items:center; gap:8px; margin:0; }
.toggle-card input[type='checkbox'] { transform: scale(1.08); accent-color:#176529; }
.toggle-card .toggle-title { color:#dfe6f4; font-weight:700; font-size:12px; }
.toggle-card .toggle-desc { color:#9fb1c9; font-size:12px; margin-top:4px; line-height:1.35; }
.top-toggle-wrap { grid-column: 1 / -1; margin-top: 2px; margin-bottom: 2px; }
.top-toggle-wrap .top-toggle-title { color: #e6b76c; font-size: 12px; font-weight: 700; margin-bottom: 6px; }
.default-npc-toggle input[type='checkbox'] { transform: scale(1.35); transform-origin: left center; accent-color:#176529; }
</style>
<main class="container-fluid">
    <div class="page-header">
        <h1 class="api-title">Profiles</h1>
        <p class="page-subtitle">Configure profile prompts, connectors, and metadata for AI dialogue generation</p>
    </div>

    <?php if ($notice !== ''): ?><div class="notice ok"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice err"><?= h($error) ?></div><?php endif; ?>

    <div class="layout">
        <section class="cardx profiles-list-panel">
            <div class="toolbar">
                <form method="get" action="profiles.php" style="display:inline;">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="create_blank" value="1">
                    <button type="submit" class="btn-save">New Profile</button>
                </form>
            </div>
            <div class="list">
                <?php foreach ($profiles as $row): ?>
                    <?php
                        $rowId = intval($row['id'] ?? 0);
                        $active = ($rowId === $editId);
                        $response = $llmNameById[strval($row['response_connector'] ?? '')] ?? 'None';
                        $tts = $ttsNameById[strval($row['tts_connector_id'] ?? '')] ?? 'None';
                    ?>
                    <article class="item <?= $active ? 'active' : '' ?>">
                        <form method="get" action="profiles.php" class="item-open-form">
                            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <input type="hidden" name="edit" value="<?= h($rowId) ?>">
                            <button type="submit" class="item-open-btn" aria-label="Open profile <?= h($row['label'] ?? ('Profile #' . $rowId)) ?>">
                                <div class="item-title">
                                    <span class="item-label">
                                        <?php if (coerceBoolean($row['is_default_npc'] ?? false)): ?><span class="default-icon" title="Default profile">&#x2605;</span><?php endif; ?>
                                        <span><?= h($row['label'] ?? ('Profile #' . $rowId)) ?></span>
                                    </span>
                                    <?php if (coerceBoolean($row['is_default_npc'] ?? false)): ?><span class="badge-default">Default</span><?php endif; ?>
                                </div>
                                <div class="item-sub">Response: <?= h($response) ?> | TTS: <?= h($tts) ?></div>
                                <div class="item-sub">NPCs using profile: <?= h(intval($usageById[strval($rowId)] ?? 0)) ?></div>
                            </button>
                        </form>
                        <div class="item-actions">
                            <form method="get" action="profiles.php" style="display:inline;">
                                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                <input type="hidden" name="export_profile" value="<?= h($rowId) ?>">
                                <button type="submit" class="btn-secondary">Export</button>
                            </form>
                            <form method="post" action="profiles.php" style="display:inline;" onsubmit="return confirm('Clone this profile?');">
                                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                <input type="hidden" name="id" value="<?= h($rowId) ?>">
                                <input type="hidden" name="clone_profile" value="1">
                                <button type="submit" class="btn-secondary">Clone</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="cardx profile-editor-panel">
            <?php if (!$editItem): ?>
                <div style="color:#9fb1c9;">No profile selected.</div>
            <?php else: ?>
                <div class="btn-row" style="margin-top:0; margin-bottom:12px;">
                    <button type="submit" form="profile_form" class="btn-save">Save Profile</button>
                    <form method="post" action="profiles.php" onsubmit="return confirm('Delete this profile?');" style="margin:0;">
                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <input type="hidden" name="id" value="<?= h($editItem['id'] ?? '') ?>">
                        <input type="hidden" name="delete_profile" value="1">
                        <button type="submit" class="btn-danger">Delete Profile</button>
                    </form>
                </div>
                <form method="post" action="profiles.php" id="profile_form">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="save_profile" value="1">
                    <input type="hidden" name="id" value="<?= h($editItem['id'] ?? '') ?>">
                    <div class="form-grid">
                        <div>
                            <label for="label">Label</label>
                            <input type="text" id="label" name="label" value="<?= h($editItem['label'] ?? '') ?>" required>
                        </div>
                        <div style="display:flex; align-items:flex-end;">
                            <label class="default-npc-toggle" style="display:flex; align-items:center; gap:8px; margin:0;">
                                <input type="checkbox" name="is_default_npc" value="1" <?= coerceBoolean($editItem['is_default_npc'] ?? false) ? 'checked' : '' ?>>
                                Default NPC profile
                            </label>
                        </div>
                        <div class="top-toggle-wrap">
                            <div class="top-toggle-title">Profile Runtime Toggles</div>
                            <div class="toggle-grid">
                                <div class="toggle-card">
                                    <label>
                                        <input type="hidden" name="meta_vis[DYNAMIC_PROFILE_ENABLED]" value="">
                                        <input type="checkbox" name="meta_vis[DYNAMIC_PROFILE_ENABLED]" value="1" <?= $metaBool('DYNAMIC_PROFILE_ENABLED') ? 'checked' : '' ?>>
                                        <span class="toggle-title">DYNAMIC_PROFILE_ENABLED</span>
                                    </label>
                                    <div class="toggle-desc">Enables profile field updates inferred from live conversation context.</div>
                                </div>
                                <div class="toggle-card">
                                    <label>
                                        <input type="hidden" name="meta_vis[MIDDLE_TERM_MEMORY_ENABLED]" value="">
                                        <input type="checkbox" name="meta_vis[MIDDLE_TERM_MEMORY_ENABLED]" value="1" <?= $metaBool('MIDDLE_TERM_MEMORY_ENABLED') ? 'checked' : '' ?>>
                                        <span class="toggle-title">MIDDLE_TERM_MEMORY_ENABLED</span>
                                    </label>
                                    <div class="toggle-desc">Allows middle-term memory to be injected into roleplay context.</div>
                                </div>
                            </div>
                        </div>

                        <?php
                            $llmFields = [
                                'response_connector' => 'Response Connector',
                                'diary_connector' => 'Diary Connector',
                                'autochat_connector' => 'Autochat Connector',
                                'middleterm_connector' => 'Memory Connector',
                                'backgroundlife_connector' => 'Backgroundlife Connector',
                                'dynamic_connector' => 'Dynamic Connector',
                                'relationship_connector' => 'Relationship Connector',
                            ];
                            $connectorIcons = [
                                'response_connector' => '&#x1F3AD;',
                                'diary_connector' => '&#x1F4D4;',
                                'autochat_connector' => '&#x1F4AC;',
                                'middleterm_connector' => '&#x1F9E0;',
                                'backgroundlife_connector' => '&#x1F333;',
                                'dynamic_connector' => '&#x2699;&#xFE0F;',
                                'relationship_connector' => '&#x1F91D;',
                            ];
                            $connectorDescriptions = [
                                'response_connector' => 'General purpose LLM for standard in-character roleplay dialogue.',
                                'diary_connector' => 'LLM for writing diary entries in the character voice.',
                                'autochat_connector' => 'LLM used by Autochat to convert player intent into roleplayed speech.',
                                'middleterm_connector' => 'LLM used for memory summaries and middle-term context refresh.',
                                'backgroundlife_connector' => 'LLM for bored/background-life spontaneous dialogue generation.',
                                'dynamic_connector' => 'LLM that updates dynamic profile fields from recent context.',
                                'relationship_connector' => 'LLM used by relationship analysis and affinity updates.',
                            ];
                            foreach ($llmFields as $field => $label):
                        ?>
                            <div>
                                <label for="<?= h($field) ?>"><span aria-hidden="true"><?= $connectorIcons[$field] ?? '' ?></span> <?= h($label) ?></label>
                                <select id="<?= h($field) ?>" name="<?= h($field) ?>">
                                    <option value="">-- None --</option>
                                    <?php foreach ($llmRows as $row): ?>
                                        <?php $selected = intval($editItem[$field] ?? 0) === intval($row['id'] ?? 0); ?>
                                        <option value="<?= h($row['id'] ?? '') ?>" <?= $selected ? 'selected' : '' ?>><?= h($row['name'] ?? ('LLM #' . strval($row['id'] ?? ''))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="connector-help-inline"><?= h($connectorDescriptions[$field] ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>

                        <div>
                            <label for="tts_connector_id"><span aria-hidden="true">&#x1F50A;</span> TTS Connector</label>
                            <select id="tts_connector_id" name="tts_connector_id">
                                <option value="">-- None --</option>
                                <?php foreach ($ttsRows as $row): ?>
                                    <?php $selected = intval($editItem['tts_connector_id'] ?? 0) === intval($row['id'] ?? 0); ?>
                                    <option value="<?= h($row['id'] ?? '') ?>" <?= $selected ? 'selected' : '' ?>><?= h($row['name'] ?? ('TTS #' . strval($row['id'] ?? ''))) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="connector-help-inline">TTS provider used for this profile's spoken output and voice playback.</div>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <label for="prompt_head">Prompt Head</label>
                        <div class="setting-desc" style="margin-bottom:6px;">High-priority instructions injected before the profile prompt for this NPC profile.</div>
                        <textarea id="prompt_head" name="prompt_head"><?= h($editItem['prompt_head'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-top:10px;">
                        <label for="profile_prompt">Profile Prompt</label>
                        <div class="setting-desc" style="margin-bottom:6px;">Main roleplay profile prompt used as the baseline behavior for this profile.</div>
                        <textarea id="profile_prompt" name="profile_prompt"><?= h($editItem['profile_prompt'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-top:10px;">
                        <label for="meta_diary_prompt">Diary Prompt</label>
                        <div class="setting-desc" style="margin-bottom:6px;">Template used when generating diary entries for this profile.</div>
                        <textarea id="meta_diary_prompt" name="meta_vis[DIARY_PROMPT]" style="min-height:88px;"><?= h(strval($metaData['DIARY_PROMPT'] ?? ($metaDefaults['DIARY_PROMPT'] ?? ''))) ?></textarea>
                    </div>
                    <div class="meta-box">
                        <h3>Metadata Settings</h3>

                        <div class="provider-card" id="meta_cat_conversation">
                            <div class="provider-head">
                                <div class="provider-title">
                                    <div class="provider-icon">&#x1F4AC;</div>
                                    <div>Conversation & Rechat</div>
                                </div>
                            </div>
                            <div class="provider-card" style="margin: 0 0 12px 0; background: #1a1a1a; padding: 10px 12px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <div style="font-size: 18px;">&#x1F501;</div>
                                    <div style="font-weight: 700; color: #e6b76c; font-size: 13px;">Rechat Response Calculator</div>
                                </div>
                                <div id="rechat-calc-output" style="font-size: 13px; line-height: 1.6;"></div>
                            </div>
                            <div class="provider-body">
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">RECHAT_RESPONSES</div>
                                        <div class="setting-desc">Maximum number of follow-up responses allowed per conversation chain.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_rechat_responses_range" min="0" max="10" step="1" value="<?= h($metaInt('RECHAT_RESPONSES')) ?>">
                                        <input type="number" id="meta_rechat_responses_num" name="meta_vis[RECHAT_RESPONSES]" min="0" max="10" step="1" value="<?= h($metaInt('RECHAT_RESPONSES')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">RECHAT_PROBABILITY</div>
                                        <div class="setting-desc">Primary rechat probability used by current flow (0-100).</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_rechat_probability_range" min="0" max="100" step="1" value="<?= h($metaInt('RECHAT_PROBABILITY')) ?>">
                                        <input type="number" id="meta_rechat_probability_num" name="meta_vis[RECHAT_PROBABILITY]" min="0" max="100" step="1" value="<?= h($metaInt('RECHAT_PROBABILITY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">BORED_EVENT_CHANCE</div>
                                        <div class="setting-desc">Chance for bored/board event generated dialogue in idle cycles (0-100).</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_bored_event_chance_range" min="0" max="100" step="1" value="<?= h($metaBoredEventChance) ?>">
                                        <input type="number" id="meta_bored_event_chance_num" name="meta_vis[BORED_EVENT_CHANCE]" min="0" max="100" step="1" value="<?= h($metaBoredEventChance) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="provider-card" id="meta_cat_context">
                            <div class="provider-head">
                                <div class="provider-title">
                                    <div class="provider-icon">&#x1F9E0;</div>
                                    <div>Context Windows</div>
                                </div>
                            </div>
                            <div class="provider-body">
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">CONTEXT_HISTORY</div>
                                        <div class="setting-desc">Recent context lines included in normal response prompts.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY')) ?>">
                                        <input type="number" id="meta_context_history_num" name="meta_vis[CONTEXT_HISTORY]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">CONTEXT_HISTORY_DIARY</div>
                                        <div class="setting-desc">Recent lines passed to diary-generation prompts.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_diary_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DIARY')) ?>">
                                        <input type="number" id="meta_context_history_diary_num" name="meta_vis[CONTEXT_HISTORY_DIARY]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DIARY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key">CONTEXT_HISTORY_DYNAMIC_PROFILE</div>
                                        <div class="setting-desc">History window used when computing dynamic profile updates.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_dyn_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DYNAMIC_PROFILE')) ?>">
                                        <input type="number" id="meta_context_history_dyn_num" name="meta_vis[CONTEXT_HISTORY_DYNAMIC_PROFILE]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DYNAMIC_PROFILE')) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="provider-card" id="meta_cat_dynamic_fields">
                            <div class="provider-head">
                                <div class="provider-title">
                                    <div class="provider-icon">&#x1F6E0;&#xFE0F;</div>
                                    <div>Dynamic Profile Fields</div>
                                </div>
                            </div>
                            <div class="provider-body">
                                <div class="setting-desc">Pick which profile fields can be rewritten by dynamic profile generation.</div>
                                <input type="hidden" name="meta_vis[DYNAMIC_PROFILE_FIELDS][]" value="">
                                <div class="meta-fields">
                                    <?php foreach ($dynamicFieldOptions as $fieldName): ?>
                                        <label class="meta-chip">
                                            <input type="checkbox" name="meta_vis[DYNAMIC_PROFILE_FIELDS][]" value="<?= h($fieldName) ?>" <?= in_array($fieldName, $dynamicFieldCurrent, true) ? 'checked' : '' ?>>
                                            <span><?= h($fieldName) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <details class="meta-advanced" id="meta_cat_advanced">
                            <summary>Advanced JSON Editor</summary>
                            <div style="margin-top:10px;">
                                <label for="metadata">Metadata (JSON)</label>
                                <textarea id="metadata" class="meta" name="metadata"><?= h(pretty_json($editItem['metadata'] ?? '{}')) ?></textarea>
                            </div>
                        </details>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
<script>
(function(){
    function bindRangePair(rangeId, numberId, min, max) {
        const rangeEl = document.getElementById(rangeId);
        const numberEl = document.getElementById(numberId);
        if (!rangeEl || !numberEl) {
            return;
        }
        const clamp = function (value) {
            let n = parseInt(value, 10);
            if (isNaN(n)) {
                n = parseInt(numberEl.defaultValue || rangeEl.defaultValue || '0', 10);
                if (isNaN(n)) {
                    n = min;
                }
            }
            if (n < min) n = min;
            if (n > max) n = max;
            return n;
        };
        rangeEl.addEventListener('input', function () {
            numberEl.value = String(clamp(rangeEl.value));
        });
        numberEl.addEventListener('input', function () {
            const n = clamp(numberEl.value);
            numberEl.value = String(n);
            rangeEl.value = String(n);
        });
        const start = clamp(numberEl.value);
        numberEl.value = String(start);
        rangeEl.value = String(start);
    }

    [
        ['meta_rechat_responses_range', 'meta_rechat_responses_num', 0, 10],
        ['meta_rechat_probability_range', 'meta_rechat_probability_num', 0, 100],
        ['meta_bored_event_chance_range', 'meta_bored_event_chance_num', 0, 100],
        ['meta_context_history_range', 'meta_context_history_num', 0, 300],
        ['meta_context_history_diary_range', 'meta_context_history_diary_num', 0, 300],
        ['meta_context_history_dyn_range', 'meta_context_history_dyn_num', 0, 300],
    ].forEach(function(pair){
        bindRangePair(pair[0], pair[1], pair[2], pair[3]);
    });

    function updateRechatCalculator() {
        const output = document.getElementById('rechat-calc-output');
        if (!output) {
            return;
        }

        const responsesNum = document.getElementById('meta_rechat_responses_num');
        const rechatProbNum = document.getElementById('meta_rechat_probability_num');
        if (!rechatProbNum) {
            return;
        }

        const responseCountRaw = responsesNum ? parseInt(responsesNum.value, 10) : NaN;
        const responseCount = Math.max(1, !isNaN(responseCountRaw) ? responseCountRaw : 2);
        const probability = Math.max(0, Math.min(100, parseInt(rechatProbNum.value, 10) || 50)) / 100;

        const parts = [];
        for (let response = 1; response <= responseCount; response++) {
            let responseProb = 0;
            let color = '#9fb1c9';
            if (response === 1) {
                responseProb = 100;
                color = '#6dd19c';
            } else {
                responseProb = Math.pow(probability, response - 1) * 100;
                if (responseProb >= 50) color = '#6dd19c';
                else if (responseProb >= 25) color = '#e6b76c';
                else if (responseProb >= 10) color = '#ffa500';
                else color = '#ff6b6b';
            }
            parts.push('<span style="color:' + color + '; font-weight:600;">Response ' + response + ': ' + responseProb.toFixed(1) + '%</span>');
        }

        output.innerHTML = parts.join(' <span style="color:#4a4a4a;">|</span> ');
    }

    updateRechatCalculator();
    ['meta_rechat_responses_range','meta_rechat_responses_num','meta_rechat_probability_range','meta_rechat_probability_num']
        .forEach(function(id){
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', updateRechatCalculator);
            }
        });

    const form = document.getElementById('profile_form');
    if (!form) return;
    form.addEventListener('submit', function(ev){
        const meta = document.getElementById('metadata');
        if (!meta) return;
        try {
            const parsed = JSON.parse(meta.value || '{}');
            meta.value = JSON.stringify(parsed, null, 2);
        } catch (e) {
            ev.preventDefault();
            alert('Metadata must be valid JSON.');
        }
    });
})();
</script>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html'); ?>
