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
            [
                'id' => 'PLAYER_FACTION_CUSTOM_NAME',
                'value' => '',
                'description' => 'Optional custom display name for the player faction in prompts.',
            ],
            [
                'id' => 'PLAYER_FACTION_PROMPT',
                'value' => '',
                'description' => 'Optional player-faction instruction block injected into prompts.',
            ],
            [
                'id' => 'RECHAT_MODE',
                'value' => 'random',
                'description' => 'Controls how Stobe chooses the next rechat responder: tight, conversational, group, or random.',
            ],
            [
                'id' => 'ENFORCE_STRICT_RECHAT_RESPONSE',
                'value' => 'false',
                'description' => 'When true, rechat replies must target the actor who just spoke.',
            ],
            [
                'id' => 'PROMPT_CONTEXT_OPTIONS',
                'value' => json_encode(stobeGetDefaultPromptContextOptions(), JSON_UNESCAPED_SLASHES),
                'description' => 'Controls which prompt context blocks and subsections are included in Stobe system prompts. Managed from Global Settings.',
            ],
            [
                'id' => 'SPEAKER_RECHAT',
                'value' => 'false',
                'description' => 'When true, the initiating player speaker may be selected in rechat; when false, they are excluded.',
            ],
            [
                'id' => 'ALWAYS_INSERT_RACE',
                'value' => 'true',
                'description' => 'When true, always inject world knowledge entries for detected speaker and nearby NPC races when matching topics exist.',
            ],
            [
                'id' => 'TXTAI_URL',
                'value' => 'http://127.0.0.1:8082',
                'description' => 'MiniMe/TXT2VEC service base URL. Use the local DwemerDistro endpoint or a reachable remote service URL.',
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

function stobePromptContextBucketTitle(string $bucket): string
{
    return match ($bucket) {
        'enabled_sections' => 'Sections',
        'enabled_character_subsections' => 'Character Profile',
        'enabled_state_subsections' => 'State & Inventory',
        'enabled_knowledge_subsections' => 'Knowledge Details',
        default => ucwords(str_replace('_', ' ', strtolower($bucket))),
    };
}

function stobeFindSettingDescription(array $rows, string $id, string $default = ''): string
{
    $needle = strtoupper(trim($id));
    if ($needle === '') {
        return $default;
    }

    foreach ($rows as $row) {
        $rowId = strtoupper(trim(strval($row['id'] ?? '')));
        if ($rowId === $needle) {
            return strval($row['description'] ?? $default);
        }
    }

    return $default;
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
    if ($idUpper === 'PROMPT_CONTEXT_OPTIONS') {
        return true;
    }
    if (in_array($idUpper, ['MEMORY_TIME_DELAY', 'MEMORY_CONTEXT_SIZE', 'MEMORY_BIAS_A', 'MEMORY_BIAS_B'], true)) {
        return true;
    }
    if ($idUpper === 'INDIVIDUAL_MEMORY_SUMMARY_THRESHOLD') {
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
    if ($idUpper === 'TXTAI_URL') {
        return 'url';
    }
    if ($idUpper === 'RECHAT_MODE') {
        return 'select';
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
    if (in_array($idUpper, ['PROMPT_HEAD', 'EMOTEMOODS', 'ROLEPLAY_INSTRUCTIONS', 'GENERAL_INSTRUCTIONS', 'ACTIONS_ALLOWLIST', 'PLAYER_FACTION_PROMPT'], true)) {
        return 'textarea';
    }
    if (strlen($value) > 120 || strpos($value, "\n") !== false) {
        return 'textarea';
    }
    return 'text';
}

function stobeSettingSelectOptions(string $id): array
{
    return match (strtoupper(trim($id))) {
        'RECHAT_MODE' => [
            'tight' => 'Tight',
            'conversational' => 'Conversational',
            'group' => 'Group',
            'random' => 'Random',
        ],
        default => [],
    };
}

function stobeNormalizeSettingValue(string $id, string $rawValue, string $type): string
{
    $value = trim($rawValue);
    if ($type === 'bool') {
        $lower = strtolower($value);
        return in_array($lower, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
    }
    if ($type === 'select') {
        $options = stobeSettingSelectOptions($id);
        $normalized = strtolower($value);
        if (isset($options[$normalized])) {
            return $normalized;
        }
        if (strtoupper(trim($id)) === 'RECHAT_MODE') {
            return 'random';
        }
        return array_key_first($options) ?? '';
    }
    return $rawValue;
}

function stobeInferGroup(string $id): string
{
    $idUpper = strtoupper($id);

    if (str_starts_with($idUpper, 'CORE_CONNECTOR_') || strpos($idUpper, 'API_KEY') !== false) {
        return 'LLM & API';
    }
    if (str_starts_with($idUpper, 'MEMORY_') || str_starts_with($idUpper, 'INDIVIDUAL_MEMORY_') || $idUpper === 'TXTAI_URL') {
        return 'Memory';
    }
    if (str_starts_with($idUpper, 'WORLD_KNOWLEDGE_') || $idUpper === 'ALWAYS_INSERT_RACE') {
        return 'World Knowledge';
    }
    if (str_starts_with($idUpper, 'BORED_EVENT_')) {
        return 'Bored Event';
    }
    if (
        str_starts_with($idUpper, 'RECHAT_')
        || str_starts_with($idUpper, 'TALK_')
        || str_starts_with($idUpper, 'SHOUT_')
        || str_starts_with($idUpper, 'WHISPER_')
        || in_array($idUpper, ['SPEAKER_RECHAT', 'ENFORCE_STRICT_RECHAT_RESPONSE'], true)
    ) {
        return 'Rechat';
    }
    if (in_array($idUpper, [
        'CONTEXT_HISTORY',
        'HTTP_TIMEOUT',
        'BRACKET_ORIGINAL_NAME',
        'PLAYER_NAME',
        'AUTO_LOCK_PROFILE',
        'RELATIONSHIP_SYSTEM',
        'RELATIONSHIP_SYSTEM_ENABLED',
        'RELATION_SYSTEM_ENABLED',
        'PLAYER_FACTION_CUSTOM_NAME',
        'PLAYER_FACTION_PROMPT'
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
        'Rechat' => 20,
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

function stobePrettySettingLabel(string $id): string
{
    $idUpper = strtoupper(trim($id));
    $customLabels = [
        'AUTO_LOCK_PROFILE' => 'Auto Lock Profiles on Edit',
        'RELATIONSHIP_SYSTEM' => 'Relationship System',
        'RELATION_SYSTEM_ENABLED' => 'Relationship System',
        'RELATIONSHIP_SYSTEM_ENABLED' => 'Relationship System',
        'PLAYER_FACTION_CUSTOM_NAME' => 'Player Faction Custom Name',
        'PLAYER_FACTION_PROMPT' => 'Player Faction Prompt',
        'ALWAYS_INSERT_RACE' => 'Always Insert Race Knowledge',
        'BRACKET_ORIGINAL_NAME' => 'Bracket Original Name',
        'RECHAT_MODE' => 'Rechat Mode',
        'ENFORCE_STRICT_RECHAT_RESPONSE' => 'Strict Rechat Targeting',
        'SPEAKER_RECHAT' => 'Speaker Rechat',
        'PROMPT_HEAD' => 'Prompt Head',
        'EMOTEMOODS' => 'Emote Moods',
        'ROLEPLAY_INSTRUCTIONS' => 'Roleplay Instructions',
        'GENERAL_INSTRUCTIONS' => 'General Instructions',
        'ACTIONS_ALLOWLIST' => 'Actions Allowlist',
        'HTTP_TIMEOUT' => 'HTTP Timeout',
        'CONTEXT_HISTORY' => 'Context History',
        'TXTAI_URL' => 'MiniMe / TXT2VEC URL',
    ];
    if (isset($customLabels[$idUpper])) {
        return $customLabels[$idUpper];
    }

    return ucwords(str_replace('_', ' ', strtolower($idUpper)));
}

function stobeRenderPromptContextSection(
    string $promptContextSectionTitle,
    array $promptContextCatalog,
    array $currentPromptContextOptions
): string {
    ob_start();
    ?>
    <section class="content-section" data-group="context-selections">
        <h2><?= h($promptContextSectionTitle) ?></h2>
        <div class="prompt-context-wrap">
            <div class="prompt-context-intro">
                Choose which prompt-context blocks Stobe includes in system prompts. All options default to on; disable only what you want excluded.
            </div>
            <?php foreach ($promptContextCatalog as $bucket => $options): ?>
                <div class="prompt-context-group">
                    <h3><?= h(stobePromptContextBucketTitle($bucket)) ?></h3>
                    <div class="prompt-context-grid">
                        <?php foreach ($options as $optionId => $meta): ?>
                            <?php
                                $checked = in_array($optionId, $currentPromptContextOptions[$bucket] ?? [], true);
                                $inputName = 'prompt_context_' . $bucket . '[]';
                            ?>
                            <label class="prompt-context-card">
                                <input
                                    type="checkbox"
                                    name="<?= h($inputName) ?>"
                                    value="<?= h($optionId) ?>"
                                    <?= $checked ? 'checked' : '' ?>
                                >
                                <span>
                                    <div class="prompt-context-label"><?= h($meta['label'] ?? $optionId) ?></div>
                                    <div class="prompt-context-desc"><?= h($meta['description'] ?? '') ?></div>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php

    return strval(ob_get_clean());
}

$isEmbed = (isset($_GET['embed']) && strval($_GET['embed']) === '1');
$webRoot = stobeSettingsWebRoot();
$selfPage = $webRoot . '/ui/settings.php' . ($isEmbed ? '?embed=1' : '');

$settingsRows = stobeFetchGeneralSettingsRows();
$promptContextSectionTitle = 'Context Selections';
$promptContextCatalog = stobeGetPromptContextOptionCatalog();
$currentPromptContextOptions = stobeGetPromptContextOptions();
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
    $postedPromptContextOptions = stobeNormalizePromptContextOptions([
        'enabled_sections' => array_values(array_map('strval', $_POST['prompt_context_enabled_sections'] ?? [])),
        'enabled_character_subsections' => array_values(array_map('strval', $_POST['prompt_context_enabled_character_subsections'] ?? [])),
        'enabled_state_subsections' => array_values(array_map('strval', $_POST['prompt_context_enabled_state_subsections'] ?? [])),
        'enabled_knowledge_subsections' => array_values(array_map('strval', $_POST['prompt_context_enabled_knowledge_subsections'] ?? [])),
    ]);
    if ($postedPromptContextOptions !== $currentPromptContextOptions) {
        $promptContextDescription = stobeFindSettingDescription(
            $settingsRows,
            'PROMPT_CONTEXT_OPTIONS',
            'Controls which prompt context blocks and subsections are included in Stobe system prompts. Managed from Global Settings.'
        );
        setSetting(
            'PROMPT_CONTEXT_OPTIONS',
            json_encode($postedPromptContextOptions, JSON_UNESCAPED_SLASHES),
            'general',
            $promptContextDescription
        );
        $savedCount++;
    }
    $settingsRows = stobeFetchGeneralSettingsRows();
    $currentPromptContextOptions = stobeGetPromptContextOptions();
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
            'RECHAT_MODE' => 0,
            'ENFORCE_STRICT_RECHAT_RESPONSE' => 1,
            'SPEAKER_RECHAT' => 2,
            'RELATIONSHIP_SYSTEM' => 2,
            'RELATIONSHIP_SYSTEM_ENABLED' => 2,
            'RELATION_SYSTEM_ENABLED' => 2,
            'PLAYER_FACTION_CUSTOM_NAME' => 3,
            'PLAYER_FACTION_PROMPT' => 4,
            'HTTP_TIMEOUT' => 99,
            'MEMORY_ENABLED' => 0,
            'WORLD_KNOWLEDGE_ENABLED' => 0,
            'ALWAYS_INSERT_RACE' => 1,
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
            padding: 18px 20px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            margin-bottom: 18px;
        }
        .page-header-row {
            display: flex;
            align-items: center;
            gap: 14px;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .page-header-copy {
            min-width: 0;
        }
        .page-header h1.api-title {
            margin-bottom: 8px;
        }
        .page-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-left: auto;
        }
        .page-subtitle {
            color: #bbb;
            font-size: 1.1em;
            margin: 0;
            text-align: left;
        }
        h1.api-title {
            margin: 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #e6b76c;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            text-align: left;
        }
        .btn-save-green {
            background: linear-gradient(135deg, rgba(32, 122, 74, 0.9), rgba(23, 101, 57, 0.9));
            color: #fff;
            border: 1px solid rgba(72, 187, 120, 0.3);
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
        }
        .btn-save-green:hover {
            background: linear-gradient(135deg, rgba(42, 142, 94, 0.95), rgba(32, 122, 74, 0.95));
        }
        .status-banner {
            margin-bottom: 16px;
            padding: 8px 12px;
            border-radius: 6px;
            background: #1a3d1a;
            color: #90EE90;
            border: 1px solid rgba(72, 187, 120, 0.22);
            font-weight: 700;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 24px;
        }
        .content-section {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 14px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .content-section h2 {
            margin-bottom: 12px;
            padding-bottom: 8px;
            font-family: "MagicCards", serif;
            word-spacing: 7px;
            font-size: 1.18em;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
            border-bottom: 1px solid rgba(230, 183, 108, 0.2);
        }
        .provider-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        .provider-card {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.95));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            padding: 12px 14px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.02);
            display: grid;
            grid-template-columns: minmax(220px, 280px) minmax(360px, 720px) minmax(220px, 1fr);
            gap: 12px 16px;
            align-items: center;
        }
        .provider-head {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .provider-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #e0e0e0;
            min-width: 0;
            flex-wrap: wrap;
        }
        .provider-body {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 0;
        }
        .provider-body input[type="text"],
        .provider-body input[type="url"],
        .provider-body input[type="password"],
        .provider-body input[type="number"],
        .provider-body select,
        .provider-body textarea {
            flex: 1;
            width: 100%;
            border: 1px solid #4a4a4a;
            border-radius: 6px;
            padding: 8px 10px;
            background-color: rgba(26, 26, 26, 0.8);
            color: #e9efff;
        }
        .provider-body input:focus,
        .provider-body select:focus,
        .provider-body textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
        }
        .provider-body textarea {
            min-height: 145px;
            resize: vertical;
            font-family: "Consolas", "Courier New", monospace;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        .provider-toggle {
            margin-left: 10px;
            display: flex;
            align-items: center;
        }
        .provider-toggle input[type="checkbox"] {
            accent-color: #176529;
            transform: scale(1.6);
            transform-origin: center;
            cursor: pointer;
        }
        .provider-help {
            margin-top: 0;
            color: #bbb;
            font-size: 12px;
            line-height: 1.45;
            min-width: 0;
        }
        .provider-description {
            color: #bbb;
        }
        .setting-warning {
            color: #ff9a9a;
            margin-top: 8px;
        }
        .prompt-context-wrap {
            display: grid;
            gap: 14px;
        }
        .prompt-context-intro {
            color: #bbb;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 4px;
        }
        .prompt-context-group {
            border: 1px solid rgba(230, 183, 108, 0.18);
            border-radius: 8px;
            padding: 12px;
            background: linear-gradient(180deg, rgba(31, 31, 31, 0.82), rgba(24, 24, 24, 0.9));
        }
        .prompt-context-group h3 {
            margin: 0 0 10px 0;
            font-size: 0.96rem;
            color: #ead7ac;
        }
        .prompt-context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
        }
        .prompt-context-card {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 10px;
            align-items: start;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #3a3a3a;
            background: rgba(20, 20, 20, 0.65);
            cursor: pointer;
        }
        .prompt-context-card:hover {
            border-color: rgba(230, 183, 108, 0.35);
            background: rgba(28, 28, 28, 0.82);
        }
        .prompt-context-card input[type="checkbox"] {
            margin-top: 3px;
            accent-color: #176529;
            transform: scale(1.1);
        }
        .prompt-context-label {
            color: #f1e7cf;
            font-size: 0.92rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .prompt-context-desc {
            color: #bbb;
            font-size: 12px;
            line-height: 1.45;
        }
        @media (max-width: 1000px) {
            .provider-card {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 900px) {
            .page-header-row {
                align-items: flex-start;
            }
            .page-header-actions {
                margin-left: 0;
                width: 100%;
            }
            .page-subtitle,
            h1.api-title {
                text-align: left;
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
        <div class="page-header-row">
            <div class="page-header-copy">
                <h1 class="api-title">Global Settings</h1>
            </div>
            <div class="page-header-actions">
                <button type="submit" class="btn-save-green" form="stobeSettingsForm">Save All</button>
            </div>
        </div>
    </div>

    <?php if ($statusMessage !== ''): ?>
        <div class="status-banner"><?= h($statusMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h($selfPage) ?>" id="stobeSettingsForm">
        <div class="content-grid" id="settingsGrid">
            <?php $promptContextSectionRendered = false; ?>
            <?php foreach ($grouped as $groupName => $rows): ?>
                <section class="content-section" data-group="<?= h(strtolower($groupName)) ?>">
                    <h2><?= h($groupName) ?></h2>
                    <div class="provider-grid">
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $id = strval($row['id'] ?? '');
                                $value = strval($row['value'] ?? '');
                                $description = strval($row['description'] ?? '');
                                $warning = stobeSettingWarningMessage($id);
                                $type = stobeSettingType($id, $value);
                                $inputId = 'setting_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $id);
                                $label = stobePrettySettingLabel($id);
                                $checked = in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
                            ?>
                            <div
                                class="provider-card"
                                data-setting-id="<?= h(strtolower($id)) ?>"
                                data-setting-desc="<?= h(strtolower($description)) ?>"
                                data-setting-value="<?= h(strtolower($value)) ?>"
                            >
                                <div class="provider-head">
                                    <div class="provider-title">
                                        <div><?= h($label) ?></div>
                                        <?php if ($type === 'bool'): ?>
                                            <div class="provider-toggle">
                                                <input type="hidden" name="settings[<?= h($id) ?>]" value="false">
                                                <input
                                                    type="checkbox"
                                                    id="<?= h($inputId) ?>"
                                                    name="settings[<?= h($id) ?>]"
                                                    value="true"
                                                    <?= $checked ? 'checked' : '' ?>
                                                >
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="provider-body">
                                    <?php if ($type === 'bool'): ?>
                                    <?php elseif ($type === 'select'): ?>
                                        <?php $selectOptions = stobeSettingSelectOptions($id); ?>
                                        <select id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]">
                                            <?php foreach ($selectOptions as $optionValue => $optionLabel): ?>
                                                <option value="<?= h($optionValue) ?>" <?= strtolower(trim($value)) === $optionValue ? 'selected' : '' ?>>
                                                    <?= h($optionLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]"><?= h($value) ?></textarea>
                                    <?php elseif ($type === 'int'): ?>
                                        <input type="number" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" step="1">
                                    <?php elseif ($type === 'float'): ?>
                                        <input type="number" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" step="0.01">
                                    <?php elseif ($type === 'password'): ?>
                                        <input type="password" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>" autocomplete="off">
                                    <?php elseif ($type === 'url'): ?>
                                        <input type="url" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>">
                                    <?php else: ?>
                                        <input type="text" id="<?= h($inputId) ?>" name="settings[<?= h($id) ?>]" value="<?= h($value) ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="provider-help">
                                    <?php if ($description !== ''): ?>
                                        <div class="provider-description"><?= h($description) ?></div>
                                    <?php endif; ?>
                                    <?php if ($warning !== ''): ?>
                                        <div class="setting-warning"><?= h($warning) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php if (!$promptContextSectionRendered && $groupName === 'World Knowledge'): ?>
                    <?= stobeRenderPromptContextSection($promptContextSectionTitle, $promptContextCatalog, $currentPromptContextOptions) ?>
                    <?php $promptContextSectionRendered = true; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$promptContextSectionRendered): ?>
                <?= stobeRenderPromptContextSection($promptContextSectionTitle, $promptContextCatalog, $currentPromptContextOptions) ?>
            <?php endif; ?>
        </div>
    </form>
</main>
</body>
</html>

