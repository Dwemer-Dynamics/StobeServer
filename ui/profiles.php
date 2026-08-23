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
function normalize_imported_fk_id(string $table, mixed $raw): ?int {
    $allowedTables = ['core_llm_connector', 'core_tts_connector'];
    if (!in_array($table, $allowedTables, true)) {
        return null;
    }
    $value = intval($raw ?? 0);
    if ($value <= 0) {
        return null;
    }
    $db = $GLOBALS['db'];
    $row = $db->fetchOne("SELECT id FROM {$table} WHERE id = $1 LIMIT 1", [$value]);
    return intval($row['id'] ?? 0) > 0 ? $value : null;
}
function apply_visual_metadata_merge(array $base, array $metaVis): array {
    $intKeys = [
        'DIARY_DAYS',
        'AUTO_DIARY_MIN_EVENTS',
        'AUTO_DIARY_HOUR',
        'BORED_EVENT_CHANCE',
        'RELATIONSHIP_UPDATE_CHANCE',
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
        $value = intval($raw);
        if ($key === 'AUTO_DIARY_HOUR') {
            $value = max(0, min(23, $value));
        } elseif ($key === 'RELATIONSHIP_UPDATE_CHANCE') {
            $value = max(0, min(100, $value));
        }
        $base[$key] = $value;
    }
    if (array_key_exists('BORED_EVENT_CHANCE', $metaVis)) {
        unset($base['BORED_EVENT']);
    }

    $boolKeys = ['DYNAMIC_PROFILE_ENABLED', 'MIDDLE_TERM_MEMORY_ENABLED', 'AUTO_DIARY_ENABLED', 'LATEST_DIARY_CONTEXT_ENABLED'];
    foreach ($boolKeys as $key) {
        if (!array_key_exists($key, $metaVis)) {
            continue;
        }
        $base[$key] = coerceBoolean($metaVis[$key] ?? false);
    }
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

function profile_setting_sync_button(string $key, string $label): string {
    return '<button type="button" class="profile-setting-sync-btn" data-setting-key="'
        . h($key) . '" data-setting-label="' . h($label)
        . '" title="Copy this setting to every profile">Copy to all</button>';
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
function normalize_imported_profile_label(string $rawLabel, string $fileName = ''): string {
    $label = trim($rawLabel);
    $label = preg_replace('/(?:\s*\(Imported\))+$/i', '', $label);
    $label = is_string($label) ? trim($label) : '';

    if ($label === '' && trim($fileName) !== '') {
        $baseName = basename(str_replace('\\', '/', $fileName));
        $baseName = preg_replace('/\.json$/i', '', $baseName);
        $baseName = preg_replace('/[_-]+/', ' ', is_string($baseName) ? $baseName : '');
        $label = is_string($baseName) ? trim($baseName) : '';
    }

    return $label !== '' ? $label : 'Imported Profile';
}
function unique_imported_profile_label(string $label): string {
    $label = trim($label) !== '' ? trim($label) : 'Imported Profile';
    $db = $GLOBALS['db'];
    $row = $db->fetchOne("SELECT id FROM core_profiles WHERE LOWER(label)=LOWER($1) LIMIT 1", [$label]);
    if (intval($row['id'] ?? 0) <= 0) {
        return $label;
    }

    $base = $label . ' (Imported)';
    $candidate = $base;
    $i = 2;
    while (true) {
        $row = $db->fetchOne("SELECT id FROM core_profiles WHERE LOWER(label)=LOWER($1) LIMIT 1", [$candidate]);
        if (intval($row['id'] ?? 0) <= 0) {
            return $candidate;
        }
        $candidate = $base . ' ' . $i;
        $i++;
        if ($i > 200) {
            return $base . ' ' . time();
        }
    }
}

$db = $GLOBALS['db'];
$isEmbed = is_embed();
$webRoot = web_root();
$profileSyncableMetadataKeys = [
    'DYNAMIC_PROFILE_ENABLED', 'MIDDLE_TERM_MEMORY_ENABLED', 'AUTO_DIARY_ENABLED', 'LATEST_DIARY_CONTEXT_ENABLED',
    'DIARY_PROMPT', 'RECHAT_RESPONSES', 'RECHAT_PROBABILITY', 'BORED_EVENT_CHANCE', 'RELATIONSHIP_UPDATE_CHANCE',
    'CONTEXT_HISTORY', 'CONTEXT_HISTORY_DIARY', 'CONTEXT_HISTORY_DYNAMIC_PROFILE',
    'DIARY_DAYS', 'AUTO_DIARY_MIN_EVENTS', 'AUTO_DIARY_HOUR', 'DIARY_COOLDOWN',
    'DYNAMIC_PROFILE_FIELDS',
];
if ($isEmbed && !isset($_GET['embed'])) {
    $_GET['embed'] = '1';
}

if (isset($_GET['create_blank']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = saveCoreProfile([
        'label' => unique_profile_label('New Profile'),
        'is_default_npc' => false,
        'is_player_faction_profile' => false,
        'prompt_head' => '',
        'profile_prompt' => '',
        'metadata' => getDefaultCoreProfileMetadata(),
    ]);
    header('Location: ' . page_url(['edit' => $id, 'notice' => 'created']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $id = intval($_POST['id'] ?? 0);
    $label = trim(strval($_POST['label'] ?? ''));
    if ($label === '') {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Label is required']);
            exit;
        }
        header('Location: ' . page_url(['edit' => $id, 'error' => 'Label is required']));
        exit;
    }
    $requestedSyncSetting = $_POST['sync_profile_setting'] ?? null;
    $syncSettingKey = is_string($requestedSyncSetting) ? trim($requestedSyncSetting) : null;
    if ($syncSettingKey !== null && !in_array($syncSettingKey, $profileSyncableMetadataKeys, true)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'This profile setting cannot be copied to all profiles.']);
            exit;
        }
        header('Location: ' . page_url(['edit' => $id, 'error' => 'This profile setting cannot be copied to all profiles.']));
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
    $playerFactionRaw = $_POST['is_player_faction_profile'] ?? null;
    $isPlayerFactionProfilePost = false;
    if (is_array($playerFactionRaw)) {
        foreach ($playerFactionRaw as $candidate) {
            if (coerceBoolean($candidate)) {
                $isPlayerFactionProfilePost = true;
                break;
            }
        }
    } else {
        $isPlayerFactionProfilePost = coerceBoolean($playerFactionRaw);
    }
    $savedId = saveCoreProfile([
        'id' => $id,
        'label' => $id > 0 ? unique_profile_label($label, $id) : unique_profile_label($label),
        'is_default_npc' => $isDefaultNpcPost,
        'is_player_faction_profile' => $isPlayerFactionProfilePost,
        'prompt_head' => strval($_POST['prompt_head'] ?? ''),
        'profile_prompt' => strval($_POST['profile_prompt'] ?? ''),
        'llm_primary_id' => post_int_or_null('llm_primary_id'),
        'llm_secondary_id' => post_int_or_null('llm_secondary_id'),
        'llm_tertiary_id' => post_int_or_null('llm_tertiary_id'),
        'llm_quaternary_id' => post_int_or_null('llm_quaternary_id'),
        'diary_connector' => post_int_or_null('diary_connector'),
        'autochat_connector' => post_int_or_null('autochat_connector'),
        'middleterm_connector' => post_int_or_null('middleterm_connector'),
        'backgroundlife_connector' => post_int_or_null('backgroundlife_connector'),
        'dynamic_connector' => post_int_or_null('dynamic_connector'),
        'relationship_connector' => post_int_or_null('relationship_connector'),
        'tts_connector_id' => post_int_or_null('tts_connector_id'),
        'metadata' => $metadata,
    ]);
    $syncedProfiles = null;
    $syncError = null;
    if ($savedId > 0 && $syncSettingKey !== null) {
        if (array_key_exists($syncSettingKey, $metadata)) {
            $encodedValue = json_encode($metadata[$syncSettingKey], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $syncResult = $db->exec(
                "UPDATE core_profiles
                 SET metadata = jsonb_set(COALESCE(metadata, '{}'::jsonb), ARRAY[$1]::text[], $2::jsonb, true),
                     updated_at = NOW()",
                [$syncSettingKey, $encodedValue]
            );
        } else {
            $syncResult = $db->exec(
                "UPDATE core_profiles
                 SET metadata = COALESCE(metadata, '{}'::jsonb) - $1,
                     updated_at = NOW()",
                [$syncSettingKey]
            );
        }
        if ($syncResult === false) {
            $syncError = 'The profile was saved, but the selected setting could not be copied to all profiles.';
        } else {
            $countRow = $db->fetchOne('SELECT COUNT(*) AS total FROM core_profiles');
            $syncedProfiles = intval($countRow['total'] ?? 0);
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $savedId > 0 && $syncError === null,
            'id' => $savedId,
            'synced_profiles' => $syncedProfiles,
            'synced_setting' => $syncSettingKey,
            'error' => $savedId <= 0 ? 'Profile could not be saved.' : $syncError,
        ]);
        exit;
    }
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
            'is_player_faction_profile' => false,
            'prompt_head' => strval($source['prompt_head'] ?? ''),
            'profile_prompt' => strval($source['profile_prompt'] ?? ''),
            'llm_primary_id' => $source['llm_primary_id'] ?? ($source['response_connector'] ?? null),
            'llm_secondary_id' => $source['llm_secondary_id'] ?? null,
            'llm_tertiary_id' => $source['llm_tertiary_id'] ?? null,
            'llm_quaternary_id' => $source['llm_quaternary_id'] ?? null,
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_profile'])) {
    $rawJson = '';
    $importFileName = '';
    if (isset($_FILES['import_file']) && is_array($_FILES['import_file'])) {
        $tmpPath = strval($_FILES['import_file']['tmp_name'] ?? '');
        $importFileName = strval($_FILES['import_file']['name'] ?? '');
        $err = intval($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_OK && $tmpPath !== '' && is_file($tmpPath)) {
            $rawJson = strval(file_get_contents($tmpPath) ?: '');
        }
    }
    if (trim($rawJson) === '') {
        $rawJson = trim(strval($_POST['import_data'] ?? ''));
    }
    if ($rawJson === '') {
        header('Location: ' . page_url(['error' => 'Import file is required']));
        exit;
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        header('Location: ' . page_url(['error' => 'Invalid JSON file']));
        exit;
    }
    $profileData = $decoded['profile'] ?? $decoded;
    if (!is_array($profileData)) {
        header('Location: ' . page_url(['error' => 'Import payload missing profile data']));
        exit;
    }

    $labelInput = trim(strval($_POST['import_label'] ?? ''));
    $baseLabel = $labelInput !== ''
        ? normalize_imported_profile_label($labelInput, $importFileName)
        : normalize_imported_profile_label(strval($profileData['label'] ?? ''), $importFileName);

    $makeDefaultNpc = coerceBoolean($_POST['make_default_npc'] ?? false);
    $migrateOldDefaultNpcs = coerceBoolean($_POST['migrate_old_default_npcs'] ?? false);
    $makePlayerFactionProfile = coerceBoolean($_POST['make_player_faction_profile'] ?? false);
    $migratePlayerFactionNpcs = coerceBoolean($_POST['migrate_player_faction_npcs'] ?? false);

    $previousDefaultRow = $db->fetchOne(
        "SELECT id FROM core_profiles WHERE COALESCE(is_default_npc, FALSE) = TRUE ORDER BY id ASC LIMIT 1"
    );
    $previousDefaultProfileId = intval($previousDefaultRow['id'] ?? 0);
    $previousPlayerFactionRow = $db->fetchOne(
        "SELECT id FROM core_profiles WHERE COALESCE(is_player_faction_profile, FALSE) = TRUE ORDER BY id ASC LIMIT 1"
    );
    $previousPlayerFactionProfileId = intval($previousPlayerFactionRow['id'] ?? 0);

    $metadataRaw = $profileData['metadata'] ?? [];
    $metadata = normalize_json_obj($metadataRaw, getDefaultCoreProfileMetadataJson());
    $newId = saveCoreProfile([
        'label' => unique_imported_profile_label($baseLabel),
        'is_default_npc' => $makeDefaultNpc,
        'is_player_faction_profile' => $makePlayerFactionProfile,
        'prompt_head' => strval($profileData['prompt_head'] ?? ''),
        'profile_prompt' => strval($profileData['profile_prompt'] ?? ''),
        'llm_primary_id' => normalize_imported_fk_id(
            'core_llm_connector',
            $profileData['llm_primary_id'] ?? ($profileData['response_connector'] ?? null)
        ),
        'llm_secondary_id' => normalize_imported_fk_id('core_llm_connector', $profileData['llm_secondary_id'] ?? null),
        'llm_tertiary_id' => normalize_imported_fk_id('core_llm_connector', $profileData['llm_tertiary_id'] ?? null),
        'llm_quaternary_id' => normalize_imported_fk_id('core_llm_connector', $profileData['llm_quaternary_id'] ?? null),
        'diary_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['diary_connector'] ?? null),
        'autochat_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['autochat_connector'] ?? null),
        'middleterm_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['middleterm_connector'] ?? null),
        'backgroundlife_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['backgroundlife_connector'] ?? null),
        'dynamic_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['dynamic_connector'] ?? null),
        'relationship_connector' => normalize_imported_fk_id('core_llm_connector', $profileData['relationship_connector'] ?? null),
        'tts_connector_id' => normalize_imported_fk_id('core_tts_connector', $profileData['tts_connector_id'] ?? null),
        'metadata' => $metadata,
    ]);

    if ($newId <= 0) {
        header('Location: ' . page_url(['error' => 'Import failed']));
        exit;
    }

    $noticeParts = ['Profile imported'];
    if ($migrateOldDefaultNpcs) {
        $where = "profile_id IS NULL";
        $params = [];
        if ($previousDefaultProfileId > 0) {
            $where .= " OR profile_id = $1";
            $params[] = $previousDefaultProfileId;
        }
        $countRow = $db->fetchOne("SELECT COUNT(*) AS c FROM core_npc WHERE {$where}", $params);
        $migratedDefaultCount = intval($countRow['c'] ?? 0);
        $db->exec("UPDATE core_npc SET profile_id = " . intval($newId) . ", updated_at = NOW() WHERE {$where}", $params);
        $noticeParts[] = 'moved ' . $migratedDefaultCount . ' default/unassigned NPCs';
    }

    if ($migratePlayerFactionNpcs && $previousPlayerFactionProfileId > 0) {
        $countRow = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM core_npc WHERE profile_id = $1",
            [$previousPlayerFactionProfileId]
        );
        $migratedPlayerFactionCount = intval($countRow['c'] ?? 0);
        $db->exec(
            "UPDATE core_npc SET profile_id = $1, updated_at = NOW() WHERE profile_id = $2",
            [$newId, $previousPlayerFactionProfileId]
        );
        $noticeParts[] = 'moved ' . $migratedPlayerFactionCount . ' player-faction NPCs';
    }

    header('Location: ' . page_url(['edit' => $newId, 'notice' => implode('; ', $noticeParts)]));
    exit;
}

// ============= Profile Rules AJAX Handlers =============
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_import_rules'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $rules = stobeGetCoreProfileImportRules();
        echo json_encode(['ok' => true, 'data' => $rules]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_import_rule'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = stobeCreateCoreProfileImportRule([
            'description' => trim(strval($_POST['description'] ?? 'New Profile Rule')),
            'match_name' => trim(strval($_POST['match_name'] ?? '')),
            'match_race' => trim(strval($_POST['match_race'] ?? '')),
            'match_gender' => trim(strval($_POST['match_gender'] ?? '')),
            'match_faction' => trim(strval($_POST['match_faction'] ?? '')),
            'profile' => intval($_POST['profile'] ?? 0),
            'priority' => intval($_POST['priority'] ?? 0),
            'enabled' => coerceBoolean($_POST['enabled'] ?? true),
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_import_rule'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid rule id']);
            exit;
        }
        stobeUpdateCoreProfileImportRule($id, [
            'description' => trim(strval($_POST['description'] ?? 'Profile Rule')),
            'match_name' => trim(strval($_POST['match_name'] ?? '')),
            'match_race' => trim(strval($_POST['match_race'] ?? '')),
            'match_gender' => trim(strval($_POST['match_gender'] ?? '')),
            'match_faction' => trim(strval($_POST['match_faction'] ?? '')),
            'profile' => intval($_POST['profile'] ?? 0),
            'priority' => intval($_POST['priority'] ?? 0),
            'enabled' => coerceBoolean($_POST['enabled'] ?? false),
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_import_rule'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid rule id']);
            exit;
        }
        stobeDeleteCoreProfileImportRule($id);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backfill_import_rules'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $backfilled = stobeBackfillCoreProfileImportRules();
        echo json_encode(['ok' => true, 'backfilled' => $backfilled]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
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
$profileOptions = [];
foreach ($profiles as $profileRow) {
    $profileOptions[] = [
        'id' => intval($profileRow['id'] ?? 0),
        'label' => strval($profileRow['label'] ?? ''),
    ];
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
main { padding: 10px 5px 5px; }
.layout { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 14px; align-items: start; position: relative; isolation: isolate; }
@media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
.cardx { border: 1px solid #3a3a3a; border-radius: 10px; background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(34,34,34,.98)); padding: 12px; }
.profiles-list-panel { position: relative; z-index: 2; }
.profile-editor-panel { min-width: 0; }
/* Page header is the shared compact inline row (.stobe-page-head in main.css). */
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.toolbar form { margin: 0; }
.toolbar .btn-save,
.toolbar .btn-secondary { display:inline-flex; align-items:center; justify-content:center; min-height:32px; padding:6px 10px; font-size:12px; line-height:1.2; box-sizing:border-box; }
.list { display: flex; flex-direction: column; gap: 8px; max-height: 70vh; overflow: auto; }
.item { border: 1px solid #454545; border-radius: 8px; padding: 10px; background: rgba(20,20,20,.35); position: relative; z-index: 3; }
.item.active { border-color: #e6b76c; }
.item-open-form { margin: 0; pointer-events: auto; position: relative; z-index: 4; }
.item-open-btn { display: block; width: 100%; border: 0; background: transparent; padding: 0; margin: 0; text-align: left; color: inherit; cursor: pointer; }
.item-title { display: flex; justify-content: space-between; align-items: center; pointer-events: none; }
.item-title .item-label { color: #e7edf7; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; pointer-events: none; }
.default-icon { font-size: 12px; color: #e6b76c; line-height: 1; }
.badge-default { border: 1px solid rgba(109,209,156,.45); color: #9be29b; border-radius: 999px; padding: 2px 8px; font-size: 11px; }
.player-faction-icon { font-size: 12px; color: #f6d26b; line-height: 1; }
.badge-player-faction { border: 1px solid rgba(246,210,107,.5); color: #f6d26b; border-radius: 999px; padding: 2px 8px; font-size: 11px; }
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
.setting-key { font-size: 12px; color:#f0f5ff; font-weight:700; margin-bottom: 2px; display:flex; align-items:center; gap:8px; }
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
.toggle-card-title-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
body .profile-setting-sync-btn {
    display:inline-flex;
    flex:0 0 auto;
    min-height:18px !important;
    margin:0 !important;
    padding:2px 5px !important;
    border:1px solid #4b4b4b !important;
    border-radius:4px !important;
    background:#303030 !important;
    color:#f3f3f3 !important;
    font-size:9px !important;
    font-weight:600;
    line-height:1.1;
    cursor:pointer;
}
body .profile-setting-sync-btn:hover { border-color:#e6b76c !important; background:#383838 !important; }
.top-toggle-wrap { grid-column: 1 / -1; margin-top: 2px; margin-bottom: 2px; }
.top-toggle-groups { display:grid; gap:9px; }
.top-toggle-group { padding:9px; border:1px solid #3f3f3f; border-radius:8px; background:#202020; }
.top-toggle-wrap .top-toggle-title { color:#e6b76c; font-family:'MagicCards', serif; font-size:1em; font-weight:700; letter-spacing:.4px; word-spacing:4px; margin-bottom:7px; }
.profile-role-toggle input[type='checkbox'] { transform: scale(1.35); transform-origin: left center; accent-color:#176529; }
.profile-editor-toolbar { position:sticky; top:0; z-index:40; display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; padding:10px 12px; border:1px solid #454545; border-radius:8px; background:rgba(31,31,31,.97); box-shadow:0 4px 14px rgba(0,0,0,.28); }
.profile-editor-toolbar-label { color:#9fb1c9; font-size:11px; letter-spacing:.08em; text-transform:uppercase; }
.profile-editor-toolbar-name { margin-top:2px; color:#f3f5fa; font-size:16px; font-weight:700; }
.profile-editor-toolbar .btn-row { margin:0; }
.connector-groups-grid { grid-column:1 / -1; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; align-items:stretch; }
.connector-group-card { min-width:0; height:100%; padding:11px; border:1px solid #414141; border-radius:8px; background:#202020; box-sizing:border-box; }
.connector-group-title { margin:0; color:#e6b76c; font-family:'MagicCards', serif; font-size:1.05em; line-height:1.25; letter-spacing:.4px; word-spacing:5px; }
.connector-group-subtitle { min-height:30px; margin:4px 0 8px; color:#9fb1c9; font-size:11px; line-height:1.3; }
.connector-group-fields { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
.connector-option-card { min-width:0; padding:10px; border:1px solid #414141; border-radius:7px; background:#242424; }
.connector-option-card label { color:#f0f5ff; }
.profile-prompt-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:12px; }
.profile-prompt-field { min-width:0; }
.profile-prompt-field:first-child { grid-column:1 / -1; }
.profile-prompt-field textarea { min-height:88px; }
.meta-settings-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; align-items:stretch; }
.meta-settings-grid > .provider-card { height:100%; margin:0; box-sizing:border-box; }
.meta-settings-grid .setting-row { grid-template-columns:1fr; gap:7px; }
@media (max-width: 980px) {
    .connector-groups-grid,
    .meta-settings-grid,
    .profile-prompt-grid { grid-template-columns:1fr; }
    .profile-prompt-field:first-child { grid-column:auto; }
}
@media (max-width: 620px) {
    .profile-editor-toolbar { position:static; align-items:flex-start; flex-direction:column; }
    .connector-group-fields { grid-template-columns:1fr; }
}
.modal-backdrop { display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,.65); z-index:10050; }
.modal-backdrop.show { display:block; }
.modal-container { width:min(920px, 95vw); margin:4vh auto; border:1px solid #3a3a3a; border-radius:10px; overflow:hidden; background:#2a2a2a; box-shadow:0 8px 28px rgba(0,0,0,.4); }
#import_profile_modal,
#import_rules_modal {
    opacity: 1 !important;
    filter: none !important;
    backdrop-filter: none !important;
}
#import_profile_modal.show,
#import_rules_modal.show {
    display: block !important;
}
#import_profile_modal .modal-container,
#import_rules_modal .modal-container {
    opacity: 1 !important;
    filter: none !important;
    backdrop-filter: none !important;
}
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:#1f1f1f; border-bottom:1px solid #3a3a3a; }
.modal-title { margin:0; font-weight:700; color:#e6b76c; font-size:1.1em; }
.modal-close { background:#3a3a3a; color:#fff; border:1px solid #4a4a4a; border-radius:8px; padding:6px 10px; cursor:pointer; }
.modal-close:hover { background:#4a4a4a; }
.modal-body { padding:14px; max-height:78vh; overflow:auto; }
.rules-list { display:flex; flex-direction:column; gap:12px; }
.rule-card { background:#1e1e1e; border:1px solid #3f3f3f; border-radius:8px; padding:12px; }
.rule-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap; }
.rule-grid { display:grid; grid-template-columns: 160px 1fr; gap:8px 12px; align-items:center; }
@media (max-width: 900px) { .rule-grid { grid-template-columns: 1fr; } }
.rule-label { color:#9fb1c9; font-size:12px; font-weight:700; }
.rule-input { width:100%; background:#151515; color:#f0f5ff; border:1px solid #4a4a4a; border-radius:6px; padding:7px 9px; }
.rule-input:focus { border-color:#e6b76c; outline:none; box-shadow:0 0 0 3px rgba(230,183,108,.12); }
.rules-help { margin-bottom:10px; color:#cdd6e2; font-size:12px; line-height:1.45; border:1px solid #3f3f3f; border-radius:8px; background:#222; padding:10px; }
.profile-test-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.74); z-index:10070; align-items:center; justify-content:center; padding:24px; }
.profile-test-shell { width:min(1120px, 96vw); max-height:90vh; overflow:hidden; display:flex; flex-direction:column; background:#1e1e1e; border:1px solid #4a4a4a; border-radius:14px; color:#e9efff; box-shadow:0 18px 48px rgba(0,0,0,.45); }
.profile-test-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:18px 20px; border-bottom:1px solid #343434; }
.profile-test-title { font-size:18px; font-weight:800; color:#e6b76c; }
.profile-test-subtitle { color:#9fb1c9; font-size:13px; margin-top:4px; }
.profile-test-body { overflow:auto; padding:16px 20px 20px; }
.profile-test-summary { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:8px; margin-bottom:12px; }
.profile-test-card { background:#262626; border:1px solid #393939; border-radius:10px; padding:10px; }
.profile-test-card .num { font-size:20px; font-weight:800; color:#fff; }
.profile-test-card .lbl { color:#9fb1c9; font-size:12px; margin-top:2px; }
.profile-test-progress { height:8px; background:#2e2e2e; border-radius:99px; overflow:hidden; border:1px solid #3c3c3c; margin-bottom:14px; }
.profile-test-progress > div { height:100%; width:0%; background:linear-gradient(90deg, #e6b76c, #ffe0a8); transition:width .2s ease; }
.profile-test-profile { border:1px solid #383838; border-radius:10px; background:#242424; margin-bottom:10px; overflow:hidden; }
.profile-test-profile-title { padding:10px 12px; display:flex; justify-content:space-between; gap:12px; background:#2a2a2a; border-bottom:1px solid #383838; font-weight:700; }
.profile-test-slots { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; padding:10px; }
.profile-test-slot { display:grid; grid-template-columns:145px 78px 1fr; gap:8px; align-items:start; border:1px solid #363636; background:#202020; border-radius:8px; padding:8px; font-size:12px; }
.profile-test-slot .slot-name { color:#f0f4ff; font-weight:700; }
.profile-test-badge { display:inline-flex; justify-content:center; align-items:center; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:800; text-transform:uppercase; border:1px solid transparent; }
.profile-test-badge.pending { color:#d7dfef; border-color:#626262; background:#333; }
.profile-test-badge.pass { color:#bdf4cb; border-color:#2f8050; background:#16351f; }
.profile-test-badge.warn { color:#ffe2a3; border-color:#9c6a18; background:#3f2c0d; }
.profile-test-badge.fail { color:#ffb6b6; border-color:#923232; background:#421616; }
.profile-test-badge.skipped { color:#9fb1c9; border-color:#465164; background:#252b35; }
.profile-test-message { color:#cfd9ea; overflow-wrap:anywhere; }
.profile-test-detail { color:#8390a6; margin-top:3px; overflow-wrap:anywhere; }
@media (max-width: 840px) { .profile-test-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); } .profile-test-slots { grid-template-columns:1fr; } .profile-test-slot { grid-template-columns:1fr; } }
</style>
<main class="container-fluid">
    <div class="page-header stobe-page-head">
        <h1 class="api-title stobe-page-head-title">Profiles</h1>
        <p class="page-subtitle stobe-page-head-note">Configure profile prompts, connectors, and metadata for AI dialogue generation</p>
    </div>

    <?php if ($notice !== ''): ?><div class="notice ok"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice err"><?= h($error) ?></div><?php endif; ?>

    <div class="layout">
        <section class="cardx profiles-list-panel">
            <div class="toolbar sidebar-action-grid">
                <form method="get" action="profiles.php">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="create_blank" value="1">
                    <button type="submit" class="btn-save">New</button>
                </form>
                <button type="button" id="import_profile_btn" class="btn-secondary">Import</button>
                <button type="button" id="open_import_rules_btn" class="btn-secondary">Rules</button>
                <button type="button" id="profile_test_all_btn" class="btn-secondary">Test</button>
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
                                        <?php if (coerceBoolean($row['is_player_faction_profile'] ?? false)): ?><span class="player-faction-icon" title="Player faction profile">&#x2694;</span><?php endif; ?>
                                        <span><?= h($row['label'] ?? ('Profile #' . $rowId)) ?></span>
                                    </span>
                                    <span style="display:flex; gap:6px; align-items:center;">
                                        <?php if (coerceBoolean($row['is_default_npc'] ?? false)): ?><span class="badge-default">Default</span><?php endif; ?>
                                        <?php if (coerceBoolean($row['is_player_faction_profile'] ?? false)): ?><span class="badge-player-faction">Player Faction</span><?php endif; ?>
                                    </span>
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
                <div class="profile-editor-toolbar">
                    <div>
                        <div class="profile-editor-toolbar-label">Editing Profile</div>
                        <div class="profile-editor-toolbar-name"><?= h($editItem['label'] ?? 'Profile') ?></div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" form="profile_form" class="btn-save">Save Profile</button>
                        <form method="post" action="profiles.php" onsubmit="return confirm('Delete this profile?');" style="margin:0;">
                            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <input type="hidden" name="id" value="<?= h($editItem['id'] ?? '') ?>">
                            <input type="hidden" name="delete_profile" value="1">
                            <button type="submit" class="btn-danger">Delete Profile</button>
                        </form>
                    </div>
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
                        <div style="display:flex; flex-direction:column; justify-content:flex-end; gap:8px;">
                            <label class="profile-role-toggle" style="display:flex; align-items:center; gap:8px; margin:0;">
                                <input type="checkbox" name="is_default_npc" value="1" <?= coerceBoolean($editItem['is_default_npc'] ?? false) ? 'checked' : '' ?>>
                                Default NPC profile
                            </label>
                            <label class="profile-role-toggle" style="display:flex; align-items:center; gap:8px; margin:0;">
                                <input type="checkbox" name="is_player_faction_profile" value="1" <?= coerceBoolean($editItem['is_player_faction_profile'] ?? false) ? 'checked' : '' ?>>
                                Player faction profile
                            </label>
                        </div>
                        <div class="top-toggle-wrap">
                            <div class="top-toggle-groups">
                                <section class="top-toggle-group">
                                    <div class="top-toggle-title">Profiles &amp; Memories</div>
                                    <div class="toggle-grid">
                                        <div class="toggle-card">
                                            <div class="toggle-card-title-row"><label>
                                                <input type="hidden" name="meta_vis[DYNAMIC_PROFILE_ENABLED]" value="">
                                                <input type="checkbox" name="meta_vis[DYNAMIC_PROFILE_ENABLED]" value="1" <?= $metaBool('DYNAMIC_PROFILE_ENABLED') ? 'checked' : '' ?>>
                                                <span class="toggle-title">Dynamic Profile</span>
                                            </label><?= profile_setting_sync_button('DYNAMIC_PROFILE_ENABLED', 'Dynamic Profile') ?></div>
                                            <div class="toggle-desc">Enables profile field updates inferred from live conversation context.</div>
                                        </div>
                                        <div class="toggle-card">
                                            <div class="toggle-card-title-row"><label>
                                                <input type="hidden" name="meta_vis[MIDDLE_TERM_MEMORY_ENABLED]" value="">
                                                <input type="checkbox" name="meta_vis[MIDDLE_TERM_MEMORY_ENABLED]" value="1" <?= $metaBool('MIDDLE_TERM_MEMORY_ENABLED') ? 'checked' : '' ?>>
                                                <span class="toggle-title">Middle Term Memory</span>
                                            </label><?= profile_setting_sync_button('MIDDLE_TERM_MEMORY_ENABLED', 'Middle Term Memory') ?></div>
                                            <div class="toggle-desc">Allows middle-term memory to be injected into roleplay context.</div>
                                        </div>
                                    </div>
                                </section>
                                <section class="top-toggle-group">
                                    <div class="top-toggle-title">Diary</div>
                                    <div class="toggle-grid">
                                        <div class="toggle-card">
                                            <div class="toggle-card-title-row"><label>
                                                <input type="hidden" name="meta_vis[AUTO_DIARY_ENABLED]" value="">
                                                <input type="checkbox" name="meta_vis[AUTO_DIARY_ENABLED]" value="1" <?= $metaBool('AUTO_DIARY_ENABLED') ? 'checked' : '' ?>>
                                                <span class="toggle-title">Auto Diary</span>
                                            </label><?= profile_setting_sync_button('AUTO_DIARY_ENABLED', 'Auto Diary') ?></div>
                                            <div class="toggle-desc">Allows NPCs on this profile to write automatic diaries from background day processing.</div>
                                        </div>
                                        <div class="toggle-card">
                                            <div class="toggle-card-title-row"><label>
                                                <input type="hidden" name="meta_vis[LATEST_DIARY_CONTEXT_ENABLED]" value="">
                                                <input type="checkbox" name="meta_vis[LATEST_DIARY_CONTEXT_ENABLED]" value="1" <?= $metaBool('LATEST_DIARY_CONTEXT_ENABLED') ? 'checked' : '' ?>>
                                                <span class="toggle-title">Include Latest Diary Entry</span>
                                            </label><?= profile_setting_sync_button('LATEST_DIARY_CONTEXT_ENABLED', 'Include Latest Diary Entry') ?></div>
                                            <div class="toggle-desc">Adds the NPC's latest diary entry to the character section of every response prompt.</div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <?php
                            $connectorIcons = [
                                'llm_primary_id' => '&#x1F3AD;',
                                'llm_secondary_id' => '&#x26A1;',
                                'llm_tertiary_id' => '&#x1F9E0;',
                                'llm_quaternary_id' => '&#x1F9EA;',
                                'diary_connector' => '&#x1F4D4;',
                                'autochat_connector' => '&#x1F4AC;',
                                'middleterm_connector' => '&#x1F9E0;',
                                'backgroundlife_connector' => '&#x1F333;',
                                'dynamic_connector' => '&#x2699;&#xFE0F;',
                                'relationship_connector' => '&#x1F91D;',
                            ];
                            $connectorDescriptions = [
                                'llm_primary_id' => 'Reliable default LLM for normal in-character dialogue.',
                                'llm_secondary_id' => 'Faster LLM for responses where lower latency is preferred.',
                                'llm_tertiary_id' => 'Higher-capability LLM for complex or important responses.',
                                'llm_quaternary_id' => 'Optional model slot for testing new or specialized LLMs.',
                                'diary_connector' => 'LLM for writing diary entries in the character voice.',
                                'autochat_connector' => 'LLM used by Autochat to convert player intent into roleplayed speech.',
                                'middleterm_connector' => 'LLM used for memory summaries and middle-term context refresh.',
                                'backgroundlife_connector' => 'LLM for bored/background-life spontaneous dialogue generation.',
                                'dynamic_connector' => 'LLM that updates dynamic profile fields from recent context.',
                                'relationship_connector' => 'LLM used by relationship analysis and affinity updates.',
                            ];
                            $connectorGroups = [
                                [
                                    'title' => 'Response LLM Modes',
                                    'description' => 'Assign the connector used by each global response mode.',
                                    'rows' => [
                                        ['field' => 'llm_primary_id', 'label' => 'Standard LLM', 'options' => 'llm'],
                                        ['field' => 'llm_secondary_id', 'label' => 'Fast LLM', 'options' => 'llm'],
                                        ['field' => 'llm_tertiary_id', 'label' => 'Powerful LLM', 'options' => 'llm'],
                                        ['field' => 'llm_quaternary_id', 'label' => 'Experimental LLM', 'options' => 'llm'],
                                    ],
                                ],
                                [
                                    'title' => 'Task Connectors',
                                    'description' => 'Dedicated connectors for voice and background profile tasks.',
                                    'rows' => [
                                        ['field' => 'tts_connector_id', 'label' => 'TTS Connector', 'options' => 'tts'],
                                        ['field' => 'autochat_connector', 'label' => 'Autochat Connector', 'options' => 'llm'],
                                        ['field' => 'backgroundlife_connector', 'label' => 'Background Life Connector', 'options' => 'llm'],
                                        ['field' => 'diary_connector', 'label' => 'Diary Connector', 'options' => 'llm'],
                                        ['field' => 'middleterm_connector', 'label' => 'Memory Connector', 'options' => 'llm'],
                                        ['field' => 'dynamic_connector', 'label' => 'Dynamic Connector', 'options' => 'llm'],
                                        ['field' => 'relationship_connector', 'label' => 'Relationship Connector', 'options' => 'llm'],
                                    ],
                                ],
                            ];
                        ?>
                        <div class="connector-groups-grid">
                            <?php foreach ($connectorGroups as $connectorGroup): ?>
                                <section class="connector-group-card">
                                    <h3 class="connector-group-title"><?= h($connectorGroup['title']) ?></h3>
                                    <div class="connector-group-subtitle"><?= h($connectorGroup['description']) ?></div>
                                    <div class="connector-group-fields">
                                        <?php foreach ($connectorGroup['rows'] as $connectorRow): ?>
                                            <?php
                                                $field = $connectorRow['field'];
                                                $isTts = ($connectorRow['options'] ?? 'llm') === 'tts';
                                                $optionRows = $isTts ? $ttsRows : $llmRows;
                                                $description = $isTts
                                                    ? "TTS provider used for this profile's spoken output and voice playback."
                                                    : ($connectorDescriptions[$field] ?? '');
                                            ?>
                                            <div class="connector-option-card">
                                                <label for="<?= h($field) ?>"><span aria-hidden="true"><?= $isTts ? '&#x1F50A;' : ($connectorIcons[$field] ?? '') ?></span> <?= h($connectorRow['label']) ?></label>
                                                <div class="connector-help-inline"><?= h($description) ?></div>
                                                <select id="<?= h($field) ?>" name="<?= h($field) ?>">
                                                    <option value="">-- None --</option>
                                                    <?php foreach ($optionRows as $row): ?>
                                                        <?php $selected = intval($editItem[$field] ?? 0) === intval($row['id'] ?? 0); ?>
                                                        <option value="<?= h($row['id'] ?? '') ?>" <?= $selected ? 'selected' : '' ?>><?= h($row['name'] ?? (($isTts ? 'TTS #' : 'LLM #') . strval($row['id'] ?? ''))) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="profile-prompt-grid">
                    <div class="profile-prompt-field">
                        <label for="prompt_head">Prompt Head</label>
                        <div class="setting-desc" style="margin-bottom:6px;">High-priority instructions injected before the profile prompt for this NPC profile.</div>
                        <textarea id="prompt_head" name="prompt_head"><?= h($editItem['prompt_head'] ?? '') ?></textarea>
                    </div>
                    <div class="profile-prompt-field">
                        <label for="profile_prompt">Profile Prompt</label>
                        <div class="setting-desc" style="margin-bottom:6px;">Main roleplay profile prompt used as the baseline behavior for this profile.</div>
                        <textarea id="profile_prompt" name="profile_prompt"><?= h($editItem['profile_prompt'] ?? '') ?></textarea>
                    </div>
                    <div class="profile-prompt-field">
                        <div class="setting-key"><label for="meta_diary_prompt" style="margin:0;">Diary Prompt</label><?= profile_setting_sync_button('DIARY_PROMPT', 'Diary Prompt') ?></div>
                        <div class="setting-desc" style="margin-bottom:6px;">Template used when generating diary entries for this profile.</div>
                        <textarea id="meta_diary_prompt" name="meta_vis[DIARY_PROMPT]" style="min-height:88px;"><?= h(strval($metaData['DIARY_PROMPT'] ?? ($metaDefaults['DIARY_PROMPT'] ?? ''))) ?></textarea>
                    </div>
                    </div>
                    <div class="meta-box">
                        <h3>Metadata Settings</h3>

                        <div class="meta-settings-grid">
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
                                        <div class="setting-key"><span>RECHAT_RESPONSES</span><?= profile_setting_sync_button('RECHAT_RESPONSES', 'Rechat Responses') ?></div>
                                        <div class="setting-desc">Maximum number of follow-up responses allowed per conversation chain.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_rechat_responses_range" min="0" max="10" step="1" value="<?= h($metaInt('RECHAT_RESPONSES')) ?>">
                                        <input type="number" id="meta_rechat_responses_num" name="meta_vis[RECHAT_RESPONSES]" min="0" max="10" step="1" value="<?= h($metaInt('RECHAT_RESPONSES')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>RECHAT_PROBABILITY</span><?= profile_setting_sync_button('RECHAT_PROBABILITY', 'Rechat Probability') ?></div>
                                        <div class="setting-desc">Primary rechat probability used by current flow (0-100).</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_rechat_probability_range" min="0" max="100" step="1" value="<?= h($metaInt('RECHAT_PROBABILITY')) ?>">
                                        <input type="number" id="meta_rechat_probability_num" name="meta_vis[RECHAT_PROBABILITY]" min="0" max="100" step="1" value="<?= h($metaInt('RECHAT_PROBABILITY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>BORED_EVENT_CHANCE</span><?= profile_setting_sync_button('BORED_EVENT_CHANCE', 'Bored Event Chance') ?></div>
                                        <div class="setting-desc">Chance for bored/board event generated dialogue in idle cycles (0-100).</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_bored_event_chance_range" min="0" max="100" step="1" value="<?= h($metaBoredEventChance) ?>">
                                        <input type="number" id="meta_bored_event_chance_num" name="meta_vis[BORED_EVENT_CHANCE]" min="0" max="100" step="1" value="<?= h($metaBoredEventChance) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>RELATIONSHIP_UPDATE_CHANCE</span><?= profile_setting_sync_button('RELATIONSHIP_UPDATE_CHANCE', 'Relationship Update Chance') ?></div>
                                        <div class="setting-desc">Chance that an eligible response runs an extra relationship evaluation (0-100). Inline relationship commands still apply.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_relationship_update_chance_range" min="0" max="100" step="1" value="<?= h($metaInt('RELATIONSHIP_UPDATE_CHANCE')) ?>">
                                        <input type="number" id="meta_relationship_update_chance_num" name="meta_vis[RELATIONSHIP_UPDATE_CHANCE]" min="0" max="100" step="1" value="<?= h($metaInt('RELATIONSHIP_UPDATE_CHANCE')) ?>">
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
                                        <div class="setting-key"><span>CONTEXT_HISTORY</span><?= profile_setting_sync_button('CONTEXT_HISTORY', 'Context History') ?></div>
                                        <div class="setting-desc">Recent context lines included in normal response prompts.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY')) ?>">
                                        <input type="number" id="meta_context_history_num" name="meta_vis[CONTEXT_HISTORY]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>CONTEXT_HISTORY_DIARY</span><?= profile_setting_sync_button('CONTEXT_HISTORY_DIARY', 'Diary Context History') ?></div>
                                        <div class="setting-desc">Recent lines passed to diary-generation prompts.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_diary_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DIARY')) ?>">
                                        <input type="number" id="meta_context_history_diary_num" name="meta_vis[CONTEXT_HISTORY_DIARY]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DIARY')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>CONTEXT_HISTORY_DYNAMIC_PROFILE</span><?= profile_setting_sync_button('CONTEXT_HISTORY_DYNAMIC_PROFILE', 'Dynamic Profile Context History') ?></div>
                                        <div class="setting-desc">History window used when computing dynamic profile updates.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_context_history_dyn_range" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DYNAMIC_PROFILE')) ?>">
                                        <input type="number" id="meta_context_history_dyn_num" name="meta_vis[CONTEXT_HISTORY_DYNAMIC_PROFILE]" min="0" max="300" step="1" value="<?= h($metaInt('CONTEXT_HISTORY_DYNAMIC_PROFILE')) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="provider-card" id="meta_cat_diary">
                            <div class="provider-head">
                                <div class="provider-title">
                                    <div class="provider-icon">&#x1F4D4;</div>
                                    <div>Diary</div>
                                </div>
                            </div>
                            <div class="provider-body">
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>DIARY_DAYS</span><?= profile_setting_sync_button('DIARY_DAYS', 'Diary Days') ?></div>
                                        <div class="setting-desc">Auto Diary timer in in-game days. Auto diary only writes when this many days have passed since the NPC's last diary entry.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_diary_days_range" min="0" max="60" step="1" value="<?= h($metaInt('DIARY_DAYS')) ?>">
                                        <input type="number" id="meta_diary_days_num" name="meta_vis[DIARY_DAYS]" min="0" max="60" step="1" value="<?= h($metaInt('DIARY_DAYS')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>AUTO_DIARY_MIN_EVENTS</span><?= profile_setting_sync_button('AUTO_DIARY_MIN_EVENTS', 'Auto Diary Minimum Events') ?></div>
                                        <div class="setting-desc">Minimum number of relevant events required in the diary window before auto diary writes an entry.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_auto_diary_min_events_range" min="1" max="500" step="1" value="<?= h($metaInt('AUTO_DIARY_MIN_EVENTS')) ?>">
                                        <input type="number" id="meta_auto_diary_min_events_num" name="meta_vis[AUTO_DIARY_MIN_EVENTS]" min="1" max="500" step="1" value="<?= h($metaInt('AUTO_DIARY_MIN_EVENTS')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>AUTO_DIARY_HOUR</span><?= profile_setting_sync_button('AUTO_DIARY_HOUR', 'Auto Diary Hour') ?></div>
                                        <div class="setting-desc">In-game 24-hour time when auto diary becomes eligible to write for the previous completed day.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_auto_diary_hour_range" min="0" max="23" step="1" value="<?= h($metaInt('AUTO_DIARY_HOUR')) ?>">
                                        <input type="number" id="meta_auto_diary_hour_num" name="meta_vis[AUTO_DIARY_HOUR]" min="0" max="23" step="1" value="<?= h($metaInt('AUTO_DIARY_HOUR')) ?>">
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div>
                                        <div class="setting-key"><span>DIARY_COOLDOWN</span><?= profile_setting_sync_button('DIARY_COOLDOWN', 'Diary Cooldown') ?></div>
                                        <div class="setting-desc">Real-time cooldown in seconds between diary writes for the same NPC.</div>
                                    </div>
                                    <div class="range-pair">
                                        <input type="range" id="meta_diary_cooldown_range" min="0" max="3600" step="1" value="<?= h($metaInt('DIARY_COOLDOWN')) ?>">
                                        <input type="number" id="meta_diary_cooldown_num" name="meta_vis[DIARY_COOLDOWN]" min="0" max="3600" step="1" value="<?= h($metaInt('DIARY_COOLDOWN')) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="provider-card" id="meta_cat_dynamic_fields">
                            <div class="provider-head">
                                <div class="provider-title">
                                    <div class="provider-icon">&#x1F6E0;&#xFE0F;</div>
                                    <div>Dynamic Profile Fields</div>
                                    <?= profile_setting_sync_button('DYNAMIC_PROFILE_FIELDS', 'Dynamic Profile Fields') ?>
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

    <div id="profile_test_modal" class="profile-test-modal" aria-hidden="true">
        <div class="profile-test-shell" role="dialog" aria-modal="true" aria-labelledby="profile_test_title">
            <div class="profile-test-head">
                <div>
                    <div id="profile_test_title" class="profile-test-title">Test All Profiles</div>
                    <div class="profile-test-subtitle">Tests each selected profile connector once, then applies shared connector results to every profile using it.</div>
                </div>
                <button type="button" id="profile_test_close" class="btn-secondary">Close</button>
            </div>
            <div class="profile-test-body">
                <div id="profile_test_summary" class="profile-test-summary"></div>
                <div class="profile-test-progress"><div id="profile_test_progress_fill"></div></div>
                <div id="profile_test_results"></div>
            </div>
        </div>
    </div>

    <div id="import_profile_modal" class="modal-backdrop">
        <div class="modal-container" style="max-width:640px;">
            <div class="modal-header">
                <h2 class="modal-title">Import Profile</h2>
                <button type="button" id="close_import_profile_modal" class="modal-close">Close</button>
            </div>
            <div class="modal-body">
                <form method="post" action="profiles.php" id="import_profile_form" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px;">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="import_profile" value="1">
                    <div>
                        <label for="import_file">Profile JSON File</label>
                        <input id="import_file" name="import_file" type="file" accept=".json,application/json" required>
                    </div>
                    <div>
                        <label for="import_label">Optional New Label Override</label>
                        <input id="import_label" name="import_label" type="text" placeholder="Leave blank to use file profile label">
                    </div>
                    <div style="padding:12px; background:#1f1f1f; border:1px solid #3a3a3a; border-radius:8px;">
                        <div style="font-weight:700; color:#e6b76c; margin-bottom:8px;">Import Assignment Options</div>
                        <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:8px; cursor:pointer;">
                            <input type="checkbox" name="make_default_npc" value="1" style="margin-top:3px;">
                            <span>Make Default Profile</span>
                        </label>
                        <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:8px; cursor:pointer;">
                            <input type="checkbox" name="migrate_old_default_npcs" value="1" style="margin-top:3px;">
                            <span>Move current default NPCs to this profile</span>
                        </label>
                        <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:8px; cursor:pointer;">
                            <input type="checkbox" name="make_player_faction_profile" value="1" style="margin-top:3px;">
                            <span>Make Player Faction Profile</span>
                        </label>
                        <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:0; cursor:pointer;">
                            <input type="checkbox" name="migrate_player_faction_npcs" value="1" style="margin-top:3px;">
                            <span>Move current player-faction NPCs to this profile</span>
                        </label>
                    </div>
                    <div class="setting-desc" style="margin-top:2px;">
                        Imports profile prompt head/prompt, metadata, and connector assignments (when matching connector ids exist in Stobe).
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn-save">Import Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="import_rules_modal" class="modal-backdrop">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title">Profile Rules</h2>
                <div style="display:flex; gap:8px;">
                    <button type="button" id="add_rule_btn" class="btn-save">+ New Rule</button>
                    <button type="button" id="backfill_rules_btn" class="btn-save">Run on Current Profiles</button>
                    <button type="button" id="close_rules_modal" class="modal-close">Close</button>
                </div>
            </div>
            <div class="modal-body">
                <div class="rules-help">
                    Rules are evaluated top-down by <strong>priority</strong>. New NPCs use the first matching rule automatically. Use <strong>Run on Current Profiles</strong> to apply rules to NPCs already imported. Manual NPC profile choices always take priority.
                    <div style="margin-top:6px;">
                        Match fields use regex and currently support: <strong>name</strong>, <strong>race</strong>, <strong>gender</strong>, <strong>faction</strong>.
                    </div>
                </div>
                <div id="rules_list" class="rules-list"></div>
            </div>
        </div>
    </div>
</main>
<script>
(function () {
    const isEmbed = <?= $isEmbed ? 'true' : 'false' ?>;
    const profileOptions = <?= json_encode($profileOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
    const profileTestApiUrl = <?= json_encode($webRoot . '/ui/api/profile_connector_tests.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: "''" ?>;

    function buildPageUrl(extraParams) {
        const params = new URLSearchParams();
        if (isEmbed) {
            params.set('embed', '1');
        }
        if (extraParams && typeof extraParams === 'object') {
            Object.keys(extraParams).forEach(function (key) {
                const raw = extraParams[key];
                if (raw === null || raw === undefined || String(raw).trim() === '') {
                    return;
                }
                params.set(key, String(raw));
            });
        }
        const query = params.toString();
        return 'profiles.php' + (query !== '' ? ('?' + query) : '');
    }

    function appendEmbed(formData) {
        if (isEmbed) {
            formData.append('embed', '1');
        }
    }

    function notify(message, isError) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, !!isError);
            return;
        }
        alert(message);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            if (ch === '&') return '&amp;';
            if (ch === '<') return '&lt;';
            if (ch === '>') return '&gt;';
            if (ch === '"') return '&quot;';
            return '&#39;';
        });
    }

    function isTruthy(raw) {
        return raw === true || raw === 1 || raw === '1' || raw === 't' || raw === 'true' || raw === 'on';
    }

    function openModal(modal) {
        if (modal) {
            modal.classList.add('show');
        }
    }

    function closeModal(modal) {
        if (modal) {
            modal.classList.remove('show');
        }
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#96;');
    }

    function profileTestStatusLabel(status) {
        const normalized = String(status || 'pending').toLowerCase();
        if (normalized === 'pass') return 'Pass';
        if (normalized === 'warn') return 'Warn';
        if (normalized === 'fail') return 'Fail';
        if (normalized === 'skipped') return 'Skipped';
        return 'Pending';
    }

    function profileTestRenderSummary() {
        const summaryEl = document.getElementById('profile_test_summary');
        if (!summaryEl) {
            return;
        }
        const counts = { pass: 0, warn: 0, fail: 0, skipped: 0, pending: 0 };
        document.querySelectorAll('#profile_test_results .profile-test-slot').forEach(function (slot) {
            const status = String(slot.getAttribute('data-status') || 'pending').toLowerCase();
            if (Object.prototype.hasOwnProperty.call(counts, status)) {
                counts[status]++;
            } else {
                counts.pending++;
            }
        });
        summaryEl.innerHTML = [
            ['pass', 'Passed'],
            ['warn', 'Warnings'],
            ['fail', 'Failed'],
            ['skipped', 'Skipped'],
            ['pending', 'Pending']
        ].map(function (entry) {
            return '<div class="profile-test-card"><div class="num">' + counts[entry[0]] + '</div><div class="lbl">' + entry[1] + '</div></div>';
        }).join('');
    }

    function profileTestSetProgress(done, total) {
        const fill = document.getElementById('profile_test_progress_fill');
        if (!fill) {
            return;
        }
        const pct = total > 0 ? Math.round((done / total) * 100) : 100;
        fill.style.width = String(pct) + '%';
    }

    function profileTestRenderPlan(plan) {
        const resultsEl = document.getElementById('profile_test_results');
        if (!resultsEl) {
            return;
        }
        const profiles = Array.isArray(plan.profiles) ? plan.profiles : [];
        let html = '';
        profiles.forEach(function (profile) {
            const flags = [];
            if (profile.default_npc) flags.push('Default NPC');
            if (profile.player_faction) flags.push('Player Faction');
            html += '<div class="profile-test-profile">';
            html += '<div class="profile-test-profile-title">';
            html += '<span>' + escapeHtml(profile.label || ('Profile #' + profile.id)) + '</span>';
            html += '<span style="color:#9fb1c9; font-size:12px;">' + escapeHtml(flags.join(' / ')) + '</span>';
            html += '</div>';
            html += '<div class="profile-test-slots">';
            (profile.slots || []).forEach(function (slot) {
                const status = String(slot.status || 'pending').toLowerCase();
                html += '<div class="profile-test-slot" data-status="' + escapeAttr(status) + '" data-job-key="' + escapeAttr(slot.job_key || '') + '">';
                html += '<div class="slot-name">' + escapeHtml(slot.label || slot.field || 'Connector') + '</div>';
                html += '<div><span class="profile-test-badge ' + escapeAttr(status) + '">' + profileTestStatusLabel(status) + '</span></div>';
                html += '<div><div class="profile-test-message">' + escapeHtml(slot.message || '') + '</div><div class="profile-test-detail"></div></div>';
                html += '</div>';
            });
            html += '</div></div>';
        });
        resultsEl.innerHTML = html || '<div class="profile-test-card">No profiles found.</div>';
        profileTestRenderSummary();
    }

    function profileTestDetailFromResult(result) {
        const details = result && result.details ? result.details : {};
        const chunks = [];
        if (details.label) chunks.push(details.label);
        if (details.driver) chunks.push('driver: ' + details.driver);
        if (details.model) chunks.push('model: ' + details.model);
        if (details.url) chunks.push('url: ' + details.url);
        if (details.voice) chunks.push('voice: ' + details.voice);
        if (Number(result && result.elapsed_ms ? result.elapsed_ms : 0) > 0) chunks.push(String(result.elapsed_ms) + 'ms');
        if (details.response_preview) chunks.push('response: ' + details.response_preview);
        if (details.generated_file) chunks.push('audio: ' + details.generated_file);
        if (details.cached) chunks.push('cached');
        return chunks.join(' | ');
    }

    function profileTestApplyJobResult(result) {
        const jobKey = result && result.job_key ? String(result.job_key) : '';
        if (jobKey === '') {
            return;
        }
        document.querySelectorAll('#profile_test_results .profile-test-slot').forEach(function (slot) {
            if (String(slot.getAttribute('data-job-key') || '') !== jobKey) {
                return;
            }
            const status = String(result.status || 'fail').toLowerCase();
            slot.setAttribute('data-status', status);
            const badge = slot.querySelector('.profile-test-badge');
            if (badge) {
                badge.className = 'profile-test-badge ' + status;
                badge.textContent = profileTestStatusLabel(status);
            }
            const message = slot.querySelector('.profile-test-message');
            if (message) {
                message.textContent = result.message || '';
            }
            const detail = slot.querySelector('.profile-test-detail');
            if (detail) {
                detail.textContent = profileTestDetailFromResult(result);
            }
        });
        profileTestRenderSummary();
    }

    async function profileTestFetchJson(url) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        const text = await response.text();
        let json = null;
        try {
            json = JSON.parse(text);
        } catch (_error) {
            throw new Error('Invalid JSON response: ' + text.slice(0, 160));
        }
        if (!response.ok || !json || json.ok !== true) {
            throw new Error((json && json.error) ? json.error : ('HTTP ' + response.status));
        }
        return json;
    }

    async function profileTestRunJob(job) {
        const url = profileTestApiUrl + '?action=test&type=' + encodeURIComponent(job.type) + '&id=' + encodeURIComponent(job.id) + '&_=' + Date.now();
        try {
            const json = await profileTestFetchJson(url);
            return json.result;
        } catch (error) {
            return {
                job_key: String(job.type) + ':' + String(job.id),
                type: job.type,
                id: job.id,
                status: 'fail',
                message: error && error.message ? String(error.message) : 'Connector test failed',
                details: {},
                elapsed_ms: 0
            };
        }
    }

    let profileTestCancelled = false;

    async function profileTestRunJobs(jobs) {
        let completed = 0;
        const total = jobs.length;
        const queue = jobs.slice();
        profileTestSetProgress(0, total);
        const workers = Array.from({ length: Math.min(2, Math.max(1, total)) }, async function () {
            while (!profileTestCancelled && queue.length > 0) {
                const job = queue.shift();
                const result = await profileTestRunJob(job);
                profileTestApplyJobResult(result);
                completed++;
                profileTestSetProgress(completed, total);
            }
        });
        await Promise.all(workers);
    }

    async function openProfileTestModal() {
        const modal = document.getElementById('profile_test_modal');
        const summaryEl = document.getElementById('profile_test_summary');
        const resultsEl = document.getElementById('profile_test_results');
        if (!modal || !summaryEl || !resultsEl) {
            return;
        }
        profileTestCancelled = false;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        summaryEl.innerHTML = '';
        resultsEl.innerHTML = '<div class="profile-test-card">Building profile connector test plan...</div>';
        profileTestSetProgress(0, 1);

        try {
            const planJson = await profileTestFetchJson(profileTestApiUrl + '?action=plan&_=' + Date.now());
            const plan = planJson.plan || {};
            const jobs = Array.isArray(plan.jobs) ? plan.jobs : [];
            profileTestRenderPlan(plan);
            if (jobs.length === 0) {
                profileTestSetProgress(1, 1);
                return;
            }
            await profileTestRunJobs(jobs);
        } catch (error) {
            resultsEl.innerHTML = '<div class="profile-test-card"><span style="color:#ff9898;">' + escapeHtml(error && error.message ? error.message : 'Failed to run profile tests') + '</span></div>';
            profileTestRenderSummary();
            profileTestSetProgress(1, 1);
        }
    }

    function closeProfileTestModal() {
        const modal = document.getElementById('profile_test_modal');
        profileTestCancelled = true;
        if (!modal) {
            return;
        }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

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
        ['meta_relationship_update_chance_range', 'meta_relationship_update_chance_num', 0, 100],
        ['meta_context_history_range', 'meta_context_history_num', 0, 300],
        ['meta_context_history_diary_range', 'meta_context_history_diary_num', 0, 300],
        ['meta_context_history_dyn_range', 'meta_context_history_dyn_num', 0, 300],
        ['meta_diary_days_range', 'meta_diary_days_num', 0, 60],
        ['meta_auto_diary_min_events_range', 'meta_auto_diary_min_events_num', 1, 500],
        ['meta_auto_diary_hour_range', 'meta_auto_diary_hour_num', 0, 23],
        ['meta_diary_cooldown_range', 'meta_diary_cooldown_num', 0, 3600],
    ].forEach(function (pair) {
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
    ['meta_rechat_responses_range', 'meta_rechat_responses_num', 'meta_rechat_probability_range', 'meta_rechat_probability_num']
        .forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', updateRechatCalculator);
            }
        });

    const form = document.getElementById('profile_form');
    if (form) {
        form.addEventListener('submit', function (ev) {
            const meta = document.getElementById('metadata');
            if (!meta) return;
            try {
                const parsed = JSON.parse(meta.value || '{}');
                meta.value = JSON.stringify(parsed, null, 2);
            } catch (_error) {
                ev.preventDefault();
                alert('Metadata must be valid JSON.');
            }
        });

        document.querySelectorAll('.profile-setting-sync-btn').forEach(function (button) {
            button.addEventListener('click', async function () {
                const settingKey = button.dataset.settingKey || '';
                const settingLabel = button.dataset.settingLabel || 'this setting';
                if (!settingKey || !window.confirm('Copy "' + settingLabel + '" from this profile to all profiles? Other profile settings will not change.')) {
                    return;
                }

                const metadataInput = document.getElementById('metadata');
                if (metadataInput) {
                    try {
                        metadataInput.value = JSON.stringify(JSON.parse(metadataInput.value || '{}'), null, 2);
                    } catch (_error) {
                        notify('Metadata must be valid JSON.', true);
                        return;
                    }
                }

                const formData = new FormData(form);
                formData.append('sync_profile_setting', settingKey);
                appendEmbed(formData);
                button.disabled = true;
                notify('Copying ' + settingLabel + '...', false);
                try {
                    const response = await fetch(buildPageUrl(), {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const payload = await response.json();
                    if (!payload || payload.ok !== true) {
                        throw new Error(payload && payload.error ? String(payload.error) : 'Request failed');
                    }
                    const count = Number(payload.synced_profiles || 0);
                    notify(count > 0 ? (settingLabel + ' copied to ' + count + ' profiles') : 'Profile saved', false);
                } catch (error) {
                    notify(error && error.message ? String(error.message) : 'Failed to copy profile setting', true);
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    const profileTestOpenBtn = document.getElementById('profile_test_all_btn');
    const profileTestCloseBtn = document.getElementById('profile_test_close');
    const profileTestModal = document.getElementById('profile_test_modal');
    if (profileTestOpenBtn) {
        profileTestOpenBtn.addEventListener('click', openProfileTestModal);
    }
    if (profileTestCloseBtn) {
        profileTestCloseBtn.addEventListener('click', closeProfileTestModal);
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && profileTestModal && profileTestModal.style.display === 'flex') {
            closeProfileTestModal();
        }
    });

    const importModal = document.getElementById('import_profile_modal');
    const importOpenBtn = document.getElementById('import_profile_btn');
    const importCloseBtn = document.getElementById('close_import_profile_modal');
    const importForm = document.getElementById('import_profile_form');
    if (importOpenBtn) {
        importOpenBtn.addEventListener('click', function () {
            openModal(importModal);
        });
    }
    if (importCloseBtn) {
        importCloseBtn.addEventListener('click', function () {
            closeModal(importModal);
            if (importForm) {
                importForm.reset();
            }
        });
    }
    const rulesModal = document.getElementById('import_rules_modal');
    const rulesOpenBtn = document.getElementById('open_import_rules_btn');
    const rulesCloseBtn = document.getElementById('close_rules_modal');
    const addRuleBtn = document.getElementById('add_rule_btn');
    const backfillRulesBtn = document.getElementById('backfill_rules_btn');
    const rulesList = document.getElementById('rules_list');
    let rulesData = [];
    const unsavedNewRuleIds = new Set();

    function renderProfileSelect(selectedValue) {
        const selected = String(selectedValue ?? '');
        let options = '<option value="">-- Select Profile --</option>';
        profileOptions.forEach(function (profile) {
            const id = String(profile.id ?? '');
            const label = escapeHtml(profile.label || ('Profile #' + id));
            options += '<option value="' + escapeHtml(id) + '"' + (id === selected ? ' selected' : '') + '>' + label + '</option>';
        });
        return options;
    }

    function renderRules() {
        if (!rulesList) {
            return;
        }
        if (!Array.isArray(rulesData) || rulesData.length === 0) {
            rulesList.innerHTML = '<div class="setting-desc">No rules yet. Click "New Rule" to create one.</div>';
            return;
        }

        let html = '';
        rulesData.forEach(function (rule) {
            const id = parseInt(rule.id, 10) || 0;
            const description = escapeHtml(rule.description || '');
            const matchName = escapeHtml(rule.match_name || '');
            const matchRace = escapeHtml(rule.match_race || '');
            const matchGender = escapeHtml(rule.match_gender || '');
            const matchFaction = escapeHtml(rule.match_faction || '');
            const priority = parseInt(rule.priority, 10);
            const priorityValue = Number.isNaN(priority) ? 0 : priority;
            const enabled = isTruthy(rule.enabled);

            html += '<div class="rule-card" data-id="' + id + '">';
            html += '  <div class="rule-head">';
            html += '      <div><strong>Rule #' + id + '</strong></div>';
            html += '      <div class="btn-row" style="margin:0;">';
            html += '          <button type="button" class="btn-save" data-action="save">Save</button>';
            html += '          <button type="button" class="btn-danger" data-action="delete">Delete</button>';
            html += '      </div>';
            html += '  </div>';
            html += '  <div class="rule-grid">';
            html += '      <div class="rule-label">Description</div><div><input type="text" class="rule-input rule-description" value="' + description + '"></div>';
            html += '      <div class="rule-label">Assign Profile</div><div><select class="rule-input rule-profile">' + renderProfileSelect(rule.profile) + '</select></div>';
            html += '      <div class="rule-label">Priority</div><div><input type="number" class="rule-input rule-priority" value="' + priorityValue + '"></div>';
            html += '      <div class="rule-label">Enabled</div><div><label style="display:inline-flex; align-items:center; gap:8px;"><input type="checkbox" class="rule-enabled"' + (enabled ? ' checked' : '') + '> Enabled</label></div>';
            html += '      <div class="rule-label">Match Name (regex)</div><div><input type="text" class="rule-input rule-match-name" value="' + matchName + '" placeholder="e.g. ^Beep$"></div>';
            html += '      <div class="rule-label">Match Race (regex)</div><div><input type="text" class="rule-input rule-match-race" value="' + matchRace + '" placeholder="e.g. sekelton"></div>';
            html += '      <div class="rule-label">Match Gender (regex)</div><div><input type="text" class="rule-input rule-match-gender" value="' + matchGender + '" placeholder="e.g. female"></div>';
            html += '      <div class="rule-label">Match Faction (regex)</div><div><input type="text" class="rule-input rule-match-faction" value="' + matchFaction + '" placeholder="e.g. 	Nameless [204-gamedata.base]"></div>';
            html += '  </div>';
            html += '</div>';
        });

        rulesList.innerHTML = html;
    }

    async function postRulesForm(formData) {
        appendEmbed(formData);
        const response = await fetch(buildPageUrl(), {
            method: 'POST',
            body: formData
        });
        let payload;
        try {
            payload = await response.json();
        } catch (_error) {
            throw new Error('Invalid response from rules endpoint');
        }
        if (!payload || payload.ok !== true) {
            const errorMessage = payload && payload.error ? String(payload.error) : 'Request failed';
            throw new Error(errorMessage);
        }
        return payload;
    }

    async function loadRules() {
        if (!rulesList) {
            return;
        }
        rulesList.innerHTML = '<div class="setting-desc">Loading rules...</div>';
        try {
            const response = await fetch(buildPageUrl({ get_import_rules: '1' }), { cache: 'no-store' });
            const payload = await response.json();
            if (!payload || payload.ok !== true || !Array.isArray(payload.data)) {
                const errorMessage = payload && payload.error ? String(payload.error) : 'Failed to load rules';
                throw new Error(errorMessage);
            }
            rulesData = payload.data;
            renderRules();
        } catch (error) {
            rulesData = [];
            const message = error && error.message ? String(error.message) : 'Failed to load rules';
            rulesList.innerHTML = '<div class="notice err">' + escapeHtml(message) + '</div>';
        }
    }

    async function createRule() {
        const formData = new FormData();
        formData.append('create_import_rule', '1');
        formData.append('description', 'New Profile Rule');
        formData.append('priority', '0');
        formData.append('enabled', '1');
        const currentProfileId = parseInt(<?= intval($editId) ?>, 10);
        if (!Number.isNaN(currentProfileId) && currentProfileId > 0) {
            formData.append('profile', String(currentProfileId));
        }
        try {
            const payload = await postRulesForm(formData);
            const newRuleId = parseInt(payload.id, 10) || 0;
            if (newRuleId > 0) {
                unsavedNewRuleIds.add(newRuleId);
            }
            await loadRules();
            notify('Rule created. Configure it, then save.', false);
        } catch (error) {
            const message = error && error.message ? String(error.message) : 'Failed to create rule';
            notify(message, true);
        }
    }

    function getCardValue(card, selector) {
        const el = card.querySelector(selector);
        if (!el) {
            return '';
        }
        if (el.type === 'checkbox') {
            return el.checked ? '1' : '0';
        }
        return String(el.value ?? '').trim();
    }

    async function saveRule(card, id) {
        const formData = new FormData();
        formData.append('update_import_rule', '1');
        formData.append('id', String(id));
        formData.append('description', getCardValue(card, '.rule-description'));
        formData.append('match_name', getCardValue(card, '.rule-match-name'));
        formData.append('match_race', getCardValue(card, '.rule-match-race'));
        formData.append('match_gender', getCardValue(card, '.rule-match-gender'));
        formData.append('match_faction', getCardValue(card, '.rule-match-faction'));
        formData.append('profile', getCardValue(card, '.rule-profile'));
        formData.append('priority', getCardValue(card, '.rule-priority') || '0');
        formData.append('enabled', getCardValue(card, '.rule-enabled'));

        const isNewRule = unsavedNewRuleIds.has(id);
        try {
            await postRulesForm(formData);
            unsavedNewRuleIds.delete(id);
            await loadRules();
            notify('Rule saved', false);
            if (isNewRule && confirm('Apply Profile Rules to current NPC profiles now? Manual NPC profile choices will not be changed.')) {
                await runRulesOnCurrentProfiles(false);
            }
        } catch (error) {
            const message = error && error.message ? String(error.message) : 'Failed to save rule';
            notify(message, true);
        }
    }

    async function runRulesOnCurrentProfiles(requireConfirmation) {
        if (
            requireConfirmation
            && !confirm('Apply enabled Profile Rules to current NPC profiles? Manual NPC profile choices will not be changed.')
        ) {
            return;
        }

        const formData = new FormData();
        formData.append('backfill_import_rules', '1');
        if (backfillRulesBtn) {
            backfillRulesBtn.disabled = true;
            backfillRulesBtn.textContent = 'Running...';
        }
        try {
            const payload = await postRulesForm(formData);
            const updated = parseInt(payload.backfilled, 10) || 0;
            notify('Updated ' + updated + ' current NPC profile' + (updated === 1 ? '' : 's'), false);
        } catch (error) {
            const message = error && error.message ? String(error.message) : 'Failed to apply rules';
            notify(message, true);
        } finally {
            if (backfillRulesBtn) {
                backfillRulesBtn.disabled = false;
                backfillRulesBtn.textContent = 'Run on Current Profiles';
            }
        }
    }

    async function deleteRule(id) {
        if (!confirm('Delete this profile rule?')) {
            return;
        }
        const formData = new FormData();
        formData.append('delete_import_rule', '1');
        formData.append('id', String(id));

        try {
            await postRulesForm(formData);
            await loadRules();
            notify('Rule deleted', false);
        } catch (error) {
            const message = error && error.message ? String(error.message) : 'Failed to delete rule';
            notify(message, true);
        }
    }

    if (rulesOpenBtn) {
        rulesOpenBtn.addEventListener('click', function () {
            openModal(rulesModal);
            loadRules();
        });
    }
    if (rulesCloseBtn) {
        rulesCloseBtn.addEventListener('click', function () {
            closeModal(rulesModal);
        });
    }
    if (addRuleBtn) {
        addRuleBtn.addEventListener('click', function () {
            createRule();
        });
    }
    if (backfillRulesBtn) {
        backfillRulesBtn.addEventListener('click', function () {
            runRulesOnCurrentProfiles(true);
        });
    }
    if (rulesList) {
        rulesList.addEventListener('click', function (event) {
            const btn = event.target.closest('button[data-action]');
            if (!btn) {
                return;
            }
            const card = btn.closest('.rule-card');
            if (!card) {
                return;
            }
            const id = parseInt(card.getAttribute('data-id') || '0', 10);
            if (id <= 0) {
                return;
            }
            const action = btn.getAttribute('data-action');
            if (action === 'save') {
                saveRule(card, id);
                return;
            }
            if (action === 'delete') {
                deleteRule(id);
            }
        });
    }
})();
</script>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html'); ?>
