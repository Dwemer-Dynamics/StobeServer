<?php

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");
try {
    require_once($path . "debug/db_updates.php");
} catch (Throwable $exception) {
    stobeLogException($exception, "Global settings db update check failed");
}

try {
    $db = $GLOBALS['db'] ?? null;
    if ($db) {
        $requiredSettings = [
            [
                'id' => 'AUTO_LOCK_PROFILE',
                'value' => 'true',
                'description' => 'When true, saving an NPC profile automatically locks it to prevent rollback/history overwrite updates.',
            ],
            [
                'id' => 'RELATIONSHIP_SYSTEM_ENABLED',
                'value' => 'true',
                'description' => 'Enable relationship system analysis and updates for NPC interactions.',
            ],
        ];

        foreach ($requiredSettings as $requiredSetting) {
            $id = strval($requiredSetting['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = $db->fetchOne(
                "SELECT id FROM general_settings WHERE id = $1 LIMIT 1",
                [$id]
            );
            if (!$row) {
                setSetting(
                    $id,
                    strval($requiredSetting['value'] ?? ''),
                    'general',
                    strval($requiredSetting['description'] ?? '')
                );
            }
        }
    }
} catch (Throwable $exception) {
    stobeLogException($exception, "Failed to ensure required global settings exist");
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function stobeSettingsWebRoot(): string
{
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $root = dirname(dirname($scriptPath));
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    return rtrim($root, '/');
}

function stobeFetchGeneralSettingsRows(): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    try {
        return $db->fetchAll(
            "SELECT id, COALESCE(value, '') AS value, COALESCE(description, '') AS description, updated_at
             FROM general_settings
             ORDER BY id ASC"
        );
    } catch (Throwable $exception) {
        return [];
    }
}

function stobeHideFromGlobalSettingsUi(string $id): bool
{
    $idUpper = strtoupper(trim($id));
    if ($idUpper === '') {
        return true;
    }

    // Internal/system keys not meant for this page.
    if ($idUpper === 'ACTIVE_CAMPAIGN') {
        return true;
    }
    if ($idUpper === 'MEMORY_AUTO_CREATE_SUMMARIES') {
        return true;
    }
    if ($idUpper === 'STOBE_QUICKSTART_COMPLETED') {
        return true;
    }
    // Legacy relationship toggle key; RELATIONSHIP_SYSTEM is the canonical setting.
    if ($idUpper === 'RELATIONSHIP_SYSTEM_ENABLED') {
        return true;
    }

    return false;
}

function stobeSettingLooksBoolean(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true);
}

function stobeSettingType(string $id, string $value): string
{
    $idUpper = strtoupper($id);
    if ($idUpper === 'MEMORY_CONTEXT_SIZE') {
        return 'int';
    }
    if (stobeSettingLooksBoolean($value)) {
        return 'bool';
    }
    if (preg_match('/^-?\d+$/', trim($value)) === 1) {
        return 'int';
    }
    if (preg_match('/^-?\d+\.\d+$/', trim($value)) === 1) {
        return 'float';
    }
    if (strpos($idUpper, 'API_KEY') !== false || strpos($idUpper, 'SECRET') !== false || strpos($idUpper, 'TOKEN') !== false) {
        return 'password';
    }
    if (in_array($idUpper, ['PROMPT_HEAD', 'EMOTEMOODS', 'ROLEPLAY_INSTRUCTIONS', 'GENERAL_INSTRUCTIONS', 'ACTIONS_ALLOWLIST'], true)) {
        return 'textarea';
    }
    if (strlen($value) > 120 || strpos($value, "\n") !== false) {
        return 'textarea';
    }
    return 'text';
}

function stobeNormalizeSettingValue(string $id, string $rawValue, string $type): string
{
    $idUpper = strtoupper($id);
    $value = trim($rawValue);
    if ($type === 'bool') {
        $lower = strtolower($value);
        return in_array($lower, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
    }
    return $rawValue;
}

function stobeInferGroup(string $id): string
{
    $idUpper = strtoupper($id);

    if (str_starts_with($idUpper, 'CORE_CONNECTOR_') || strpos($idUpper, 'API_KEY') !== false) {
        return 'LLM & API';
    }
    if (str_starts_with($idUpper, 'MEMORY_') || str_starts_with($idUpper, 'INDIVIDUAL_MEMORY_')) {
        return 'Memory';
    }
    if (str_starts_with($idUpper, 'WORLD_KNOWLEDGE_')) {
        return 'World Knowledge';
    }
    if (str_starts_with($idUpper, 'BORED_EVENT_')) {
        return 'Bored Event';
    }
    if (str_starts_with($idUpper, 'RECHAT_') || str_starts_with($idUpper, 'TALK_') || str_starts_with($idUpper, 'SHOUT_') || str_starts_with($idUpper, 'WHISPER_')) {
        return 'Conversation';
    }
    if (in_array($idUpper, [
        'CONTEXT_HISTORY',
        'HTTP_TIMEOUT',
        'BRACKET_ORIGINAL_NAME',
        'SPEAKER_RECHAT',
        'PLAYER_NAME',
        'AUTO_LOCK_PROFILE',
        'RELATIONSHIP_SYSTEM',
        'RELATIONSHIP_SYSTEM_ENABLED',
        'RELATION_SYSTEM_ENABLED'
    ], true)) {
        return 'Core';
    }
    if (in_array($idUpper, ['PROMPT_HEAD', 'EMOTEMOODS', 'ROLEPLAY_INSTRUCTIONS', 'GENERAL_INSTRUCTIONS', 'ACTIONS_ALLOWLIST'], true)) {
        return 'Prompting';
    }
    return 'Other';
}

function stobeGroupSortWeight(string $group): int
{
    static $weights = [
        'Prompting' => 5,
        'Core' => 10,
        'Conversation' => 20,
        'Bored Event' => 30,
        'Memory' => 40,
        'World Knowledge' => 50,
        'LLM & API' => 70,
        'Other' => 80,
    ];
    return $weights[$group] ?? 999;
}

function stobeSettingWarningMessage(string $id): string
{
    $idUpper = strtoupper(trim($id));
    if ($idUpper === 'PLAYTHROUGH_PRUNE_ON_ROLLBACK_ENABLED') {
        return 'Warning: disabling this can cause mismatched events when loading older saves.';
    }
    return '';
}

$isEmbed = (isset($_GET['embed']) && strval($_GET['embed']) === '1');
$webRoot = stobeSettingsWebRoot();
$selfPage = $webRoot . '/ui/settings.php' . ($isEmbed ? '?embed=1' : '');

$settingsRows = stobeFetchGeneralSettingsRows();
$statusMessage = '';
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedSettings = $_POST['settings'] ?? [];
    if (!is_array($postedSettings)) {
        $postedSettings = [];
    }
    foreach ($settingsRows as $row) {
        $id = strval($row['id'] ?? '');
        if ($id === '' || !array_key_exists($id, $postedSettings)) {
            continue;
        }
        $currentValue = strval($row['value'] ?? '');
        $description = strval($row['description'] ?? '');
        $type = stobeSettingType($id, $currentValue);
        $postedValue = $postedSettings[$id];
        if (is_array($postedValue)) {
            $postedValue = reset($postedValue);
        }
        $normalized = stobeNormalizeSettingValue($id, strval($postedValue), $type);
        if ($normalized !== $currentValue) {
            setSetting($id, $normalized, 'general', $description);
            $savedCount++;
        }
    }
    $settingsRows = stobeFetchGeneralSettingsRows();
    $statusMessage = $savedCount > 0 ? ('Saved ' . $savedCount . ' setting(s).') : 'No changes detected.';
}

$grouped = [];
foreach ($settingsRows as $row) {
    $id = strval($row['id'] ?? '');
    if ($id === '') {
        continue;
    }
    if (stobeHideFromGlobalSettingsUi($id)) {
        continue;
    }
    $group = stobeInferGroup($id);
    if (!isset($grouped[$group])) {
        $grouped[$group] = [];
    }
    $grouped[$group][] = $row;
}

foreach ($grouped as $groupName => $rows) {
    usort($rows, static function (array $a, array $b): int {
        $idA = strtoupper(strval($a['id'] ?? ''));
        $idB = strtoupper(strval($b['id'] ?? ''));
        $priorityMap = [
            'PROMPT_HEAD' => 0,
            'EMOTEMOODS' => 1,
            'BRACKET_ORIGINAL_NAME' => 0,
            'SPEAKER_RECHAT' => 1,
            'RELATIONSHIP_SYSTEM' => 2,
            'RELATIONSHIP_SYSTEM_ENABLED' => 2,
            'RELATION_SYSTEM_ENABLED' => 2,
            'HTTP_TIMEOUT' => 99,
            'MEMORY_ENABLED' => 0,
            'WORLD_KNOWLEDGE_ENABLED' => 0,
            'PLAYTHROUGH_AUTOLOAD_ENABLED' => 0,
            'PLAYTHROUGH_PRUNE_ON_ROLLBACK_ENABLED' => 1,
        ];
        $priorityA = $priorityMap[$idA] ?? 10;
        $priorityB = $priorityMap[$idB] ?? 10;
        if ($priorityA !== $priorityB) {
            return $priorityA <=> $priorityB;
        }
        return strcasecmp($idA, $idB);
    });
    $grouped[$groupName] = $rows;
}

uksort($grouped, function ($a, $b) {
    $wa = stobeGroupSortWeight($a);
    $wb = stobeGroupSortWeight($b);
    if ($wa === $wb) {
        return strcasecmp($a, $b);
    }
    return $wa <=> $wb;
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StobeServer - Global Settings</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <?php if (!$isEmbed): ?>
        <link rel="stylesheet" href="css/navbar.css">
    <?php endif; ?>
    <style>
        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }
        body {
            background: #2c2c2c;
            color: #f8f9fa;
        }
        main.page-wrap {
            padding-top: 30px;
            padding-bottom: 24px;
            padding-left: 5px;
            padding-right: 5px;
        }
        .page-header {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            text-align: center;
            margin-bottom: 30px;
        }
        .page-header h1.api-title {
            margin-bottom: 8px;
        }
        .page-subtitle {
            color: #bbb;
            font-size: 1.1em;
            margin: 0;
        }
        h1.api-title {
            margin: 0 0 20px 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #e6b76c;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            text-align: center;
        }
        .btn-stobe {
            background: rgba(58, 58, 58, 0.9);
            color: #e6b76c;
            border: 1px solid rgba(230, 183, 108, 0.45);
            border-radius: 8px;
            padding: 8px 14px;
            text-decoration: none;
            font-weight: 600;
        }
        .btn-stobe:hover {
            color: #e6b76c;
            background: rgba(74, 74, 74, 0.92);
            border-color: #e6b76c;
            text-decoration: none;
        }
        .status-banner {
            margin-bottom: 12px;
            padding: 10px 12px;
            border: 1px solid rgba(230, 183, 108, 0.45);
            border-radius: 8px;
            background: rgba(230, 183, 108, 0.08);
            color: #ffd9b0;
            font-weight: 600;
        }
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(500px, 1fr));
            gap: 14px;
        }
        .settings-group {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .settings-group h2 {
            margin: 0;
            padding: 12px 14px;
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1px;
            font-size: 1.2rem;
            color: #e6b76c;
            border-bottom: 1px solid #3a3a3a;
            background: rgba(255, 255, 255, 0.02);
        }
        .setting-row {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .setting-row:last-child {
            border-bottom: 0;
        }
        .setting-id {
            font-family: "Consolas", "Courier New", monospace;
            font-size: 0.9rem;
            color: #ffdcb9;
            margin-bottom: 4px;
            word-break: break-word;
        }
        .setting-desc {
            font-size: 0.85rem;
            color: #adadad;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .setting-warning {
            font-size: 0.82rem;
            color: #ffb4b4;
            margin-top: -4px;
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .setting-control input[type="text"],
        .setting-control input[type="password"],
        .setting-control input[type="number"],
        .setting-control select,
        .setting-control textarea {
            width: 100%;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            padding: 8px 10px;
            background: rgba(22, 22, 22, 0.9);
            color: #f5f5f5;
        }
        .setting-control textarea {
            min-height: 90px;
            resize: vertical;
            font-family: "Consolas", "Courier New", monospace;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        .bool-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bool-wrap input[type="checkbox"] {
            transform: scale(1.15);
            accent-color: #e6b76c;
        }
        .top-actions {
            margin-bottom: 12px;
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        @media (max-width: 700px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="page-wrap container-fluid">
    <div class="page-header">
        <h1 class="api-title">Global Settings</h1>
        <p class="page-subtitle">Configure global Stobe settings for server behavior and systems</p>
    </div>

    <?php if ($statusMessage !== ''): ?>
        <div class="status-banner"><?= h($statusMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h($selfPage) ?>">
        <div class="top-actions">
            <button type="submit" class="btn-stobe">Save Settings</button>
        </div>

        <div class="settings-grid" id="settingsGrid">
            <?php foreach ($grouped as $groupName => $rows): ?>
                <section class="settings-group" data-group="<?= h(strtolower($groupName)) ?>">
                    <h2><?= h($groupName) ?></h2>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $id = strval($row['id'] ?? '');
                            $value = strval($row['value'] ?? '');
                            $description = strval($row['description'] ?? '');
                            $warning = stobeSettingWarningMessage($id);
                            $type = stobeSettingType($id, $value);
                            $inputId = 'setting_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $id);
                        ?>
                        <div
                            class="setting-row"
                            data-setting-id="<?= h(strtolower($id)) ?>"
                            data-setting-desc="<?= h(strtolower($description)) ?>"
                            data-setting-value="<?= h(strtolower($value)) ?>"
                        >
                            <div class="setting-id"><?= h($id) ?></div>
                            <?php if ($description !== ''): ?>
                                <div class="setting-desc"><?= h($description) ?></div>
                            <?php endif; ?>
                            <?php if ($warning !== ''): ?>
                                <div class="setting-warning"><?= h($warning) ?></div>
                            <?php endif; ?>
                            <div class="setting-control">
                                <?php if ($type === 'bool'): ?>
                                    <?php $checked = in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true); ?>
                                    <input type="hidden" name="settings[<?= h($id) ?>]" value="false">
                                    <label class="bool-wrap" for="<?= h($inputId) ?>">
                                        <input
                                            type="checkbox"
                                            id="<?= h($inputId) ?>"
                                            name="settings[<?= h($id) ?>]"
                                            value="true"
                                            <?= $checked ? 'checked' : '' ?>
                                        >
                                        <span><?= $checked ? 'Enabled' : 'Disabled' ?></span>
                                    </label>
                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]"><?= h($value) ?></textarea>
                                <?php elseif ($type === 'int'): ?>
                                    <input type="number" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" step="1">
                                <?php elseif ($type === 'float'): ?>
                                    <input type="number" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" step="0.01">
                                <?php elseif ($type === 'password'): ?>
                                    <input type="password" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" autocomplete="off">
                                <?php else: ?>
                                    <input type="text" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </form>
</main>
</body>
</html>

