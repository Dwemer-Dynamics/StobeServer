<?php
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $path . 'lib/bootstrap.php';
require_once $path . 'lib/core/stt_connector.class.php';

try {
    require_once $path . 'debug/db_updates.php';
} catch (Throwable $exception) {
    stobeLogException($exception, 'STT connector migration failed');
}

$connector = new STTConnector();
$isEmbed = strval($_GET['embed'] ?? '') === '1';

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

function sttPageUrl(array $params = []): string
{
    global $isEmbed;
    if ($isEmbed && !isset($params['embed'])) {
        $params['embed'] = '1';
    }
    $query = http_build_query($params);
    return 'stt_connectors.php' . ($query !== '' ? '?' . $query : '');
}

function sttGroupedDriverOptions(STTConnector $connector): array
{
    $available = array_fill_keys($connector->getDriverOptions(), true);
    $groups = [
        'Recommended' => ['parakeet', 'deepgram'],
        'Other Services' => ['whisper', 'localwhisper', 'gemini', 'azure', 'inworld'],
        'System' => ['none'],
    ];
    foreach ($groups as $label => $drivers) {
        $groups[$label] = array_values(array_filter($drivers, static fn(string $driver): bool => isset($available[$driver])));
        foreach ($groups[$label] as $driver) {
            unset($available[$driver]);
        }
    }
    if ($available) {
        $groups['Other Services'] = array_merge($groups['Other Services'], array_keys($available));
    }
    return $groups;
}

function sttProviderTitle(string $driver): string
{
    return [
        'parakeet' => 'Fast local transcription through DwemerDistro.',
        'deepgram' => 'Cloud transcription with strong conversational accuracy.',
        'whisper' => 'OpenAI hosted Whisper transcription.',
        'localwhisper' => 'A local or remotely hosted Whisper service.',
        'gemini' => 'Google Gemini audio transcription.',
        'azure' => 'Microsoft Azure Speech transcription.',
        'inworld' => 'Inworld speech-to-text transcription.',
        'none' => 'Turn off speech-to-text in Stobe.',
    ][$driver] ?? 'Speech-to-text service.';
}

function sttFieldLabel(string $name): string
{
    return [
        'MODEL_ID' => 'Model ID',
        'SMART_FORMAT' => 'Smart Format',
        'FORMFIELD' => 'Form Field',
    ][$name] ?? ucwords(str_replace('_', ' ', strtolower($name)));
}

function sttFieldHelp(string $name): string
{
    return [
        'LANGUAGE' => 'Language code used when transcribing speech.',
        'TRANSLATE' => 'Translate recognized speech to English.',
        'FORMFIELD' => 'Multipart form field expected by the local service.',
        'MODEL' => 'Model used by this speech-to-text service.',
        'MODEL_ID' => 'Inworld model used for transcription.',
        'SMART_FORMAT' => 'Format punctuation, dates, and numbers automatically.',
        'REGION' => 'Azure region that hosts the Speech resource.',
        'PROFANITY' => 'How Azure returns recognized profanity.',
        'TIMEOUT' => 'Maximum seconds to wait for a transcription response.',
    ][$name] ?? '';
}

function sttApiBadgeHasKey(mixed $value): bool
{
    $value = trim(strval($value));
    return $value !== '' && !preg_match('/^(?:\*+|null|none|n\/a)$/i', $value)
        && !preg_match('/^[^A-Za-z0-9]+$/', $value);
}

$driverOptions = $connector->getDriverOptions();
$groupedDriverOptions = sttGroupedDriverOptions($connector);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_connector'])) {
    $driver = $connector->normalizeDriverValue($_POST['driver'] ?? 'parakeet');
    $metadata = $connector->defaultsForDriver($driver);
    foreach ($connector->getProviderFieldSchema($driver) as $name => $definition) {
        $key = 'meta__' . $driver . '__' . $name;
        $type = $definition['type'] ?? 'string';
        if ($type === 'boolean') {
            $metadata[$name] = strval($_POST[$key] ?? 'false') === 'true';
        } elseif ($type === 'integer') {
            $metadata[$name] = max(5, min(120, intval($_POST[$key] ?? ($definition['default'] ?? 60))));
        } else {
            $metadata[$name] = trim(strval($_POST[$key] ?? ($definition['default'] ?? '')));
        }
    }

    $connector->saveGlobal([
        'driver' => $driver,
        'label' => trim(strval($_POST['label'] ?? 'Global STT Connector')),
        'metadata' => $metadata,
        'api_badge_id' => $connector->driverUsesApiBadge($driver) ? intval($_POST['api_badge_id'] ?? 0) : null,
        'url' => trim(strval($_POST['url'] ?? '')),
    ]);
    header('Location: ' . sttPageUrl(['saved' => '1']));
    exit;
}

$row = $connector->getActive() ?: [];
$currentDriver = $connector->normalizeDriverValue($row['driver'] ?? 'parakeet');
$currentMetadata = array_merge(
    $connector->defaultsForDriver($currentDriver),
    $connector->decodeMetadata($row['metadata'] ?? '{}')
);
$apiRows = $GLOBALS['db']->fetchAll("SELECT id, label, COALESCE(api_key, '') AS api_key FROM core_api_badge ORDER BY LOWER(label)");
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

$defaultUrls = [];
$defaultApiBadgeIds = [];
$apiDrivers = [];
$urlDrivers = [];
foreach ($driverOptions as $driverOption) {
    $defaultUrls[$driverOption] = $connector->getDefaultUrlForDriver($driverOption);
    $defaultApiBadgeIds[$driverOption] = $connector->getDefaultApiBadgeIdForDriver($driverOption);
    if ($connector->driverUsesApiBadge($driverOption)) {
        $apiDrivers[] = $driverOption;
    }
    if ($connector->driverSupportsEditableUrl($driverOption)) {
        $urlDrivers[] = $driverOption;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>STT Connector</title>
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/stobe-theme.css">
    <style>
        body { padding-top: <?= $isEmbed ? '0' : '70px' ?>; }
        main { padding: <?= $isEmbed ? '20px 5px 5px' : '30px 5px 5px' ?>; }
        .page-shell { max-width: 1450px; margin: 0 auto; }
        .page-header, .left-col, .right-col { background: var(--stobe-surface, #242424); border: 1px solid var(--stobe-border, #3a3a3a); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.18); }
        .page-header { padding: 20px; text-align: center; margin-bottom: 30px; }
        h1.api-title { margin: 0 0 8px; font-family: var(--stobe-title-font); word-spacing: 8px; font-size: 2.2em; font-weight: 400; color: var(--stobe-accent, #e6b76c); text-shadow: 2px 2px 4px rgba(0,0,0,.5); }
        .page-subtitle { color: var(--stobe-text-muted, #bbb); font-size: 1.1em; margin: 0; }
        .notice { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(230,183,108,.3); background: var(--stobe-surface, #242424); color: #eed9b5; }
        .layout { display: grid; grid-template-columns: minmax(280px, 340px) 1fr; gap: 18px; align-items: start; }
        .left-col, .right-col { padding: 14px; }
        .left-col { position: sticky; top: 90px; max-height: calc(100vh - 110px); overflow: hidden; }
        .list-wrap { display: flex; flex-direction: column; gap: 10px; overflow: auto; max-height: calc(100vh - 280px); padding-right: 4px; }
        .group-title { color: #f0cf98; font-family: var(--stobe-title-font); word-spacing: 6px; font-size: 1.05em; margin: 8px 0 0; }
        .conn-card { border: 1px solid var(--stobe-border, #3a3a3a); border-radius: 10px; background: var(--stobe-surface-raised, #2a2a2a); padding: 12px; cursor: pointer; transition: all .2s ease; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        .conn-card:hover { transform: translateY(-2px); border-color: #5b5143; box-shadow: 0 3px 8px rgba(0,0,0,.2); }
        .conn-card.active { outline: 2px solid var(--stobe-accent, #e6b76c); background: #332d25; box-shadow: 0 4px 12px rgba(230,183,108,.22); }
        .conn-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
        .conn-name { color: var(--stobe-text, #f0f0f0); font-family: var(--stobe-title-font); word-spacing: 6px; font-size: 1.05em; }
        .conn-badge { color: #b8aa94; font-size: 11px; border: 1px solid #4a4a4a; border-radius: 999px; padding: 2px 8px; }
        .conn-sub { color: #aaa194; font-size: 12px; margin-top: 4px; overflow-wrap: anywhere; }
        .summary-note, .orm-note, .settings-empty-note { padding: 10px 12px; border: 1px dashed rgba(230,183,108,.22); border-radius: 8px; background: #191919; color: #aaa194; font-size: 12px; line-height: 1.5; }
        .summary-note { margin-bottom: 12px; }
        .orm-note { padding: 6px 10px; margin-bottom: 12px; }
        .btn-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; }
        .btn-row button, #stt_test_close { padding: 10px 20px; border-radius: 7px; cursor: pointer; }
        .editor-grid, .inline-two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field-block { margin-bottom: 12px; }
        .field-block label { display: block; color: #fff; font-weight: 700; margin-bottom: 6px; }
        .field-block input, .field-block select, .field-block textarea { width: 100%; box-sizing: border-box; background: #1a1a1a; color: #f2f2f2; border: 1px solid #444; border-radius: 6px; padding: 10px 12px; }
        .field-block input:focus, .field-block select:focus, .field-block textarea:focus { outline: none; border-color: rgba(230,183,108,.55); box-shadow: 0 0 0 3px rgba(230,183,108,.1); }
        .field-help { color: #9f9689; font-size: 12px; margin-top: 5px; line-height: 1.45; }
        .api-key-notice { margin-top: 6px; font-size: 12px; }
        .api-key-notice.warn { color: #ffb862; }
        .api-key-notice.ok { color: #6dd19c; }
        .meta-group { display: none; border-top: 1px solid rgba(230,183,108,.16); margin-top: 8px; padding-top: 16px; }
        .meta-group.active { display: block; }
        .meta-group h3 { font-family: var(--stobe-title-font); word-spacing: 6px; font-size: 1.2em; font-weight: 400; color: #f0cf98; margin: 0 0 14px; }
        #stt_test_modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.66); z-index: 9999; }
        #stt_test_modal .inner { width: min(1100px, 94vw); height: min(820px, 92vh); background: #171717; border: 1px solid #444; border-radius: 10px; position: relative; overflow: hidden; }
        #stt_test_modal iframe { width: 100%; height: 100%; border: 0; background: #171717; }
        #stt_test_close { position: absolute; top: 10px; right: 10px; z-index: 2; }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .left-col { position: relative; top: auto; max-height: none; }
            .list-wrap { max-height: 420px; }
            .editor-grid, .inline-two { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed) include __DIR__ . '/tmpl/navbar.php'; ?>
<main>
    <div class="page-shell">
        <div class="page-header">
            <h1 class="api-title">STT Connector</h1>
            <p class="page-subtitle">Speech-to-text setup options for Stobe push-to-talk.</p>
        </div>

        <?php if ($saved): ?>
            <div class="notice">STT connector saved.</div>
        <?php endif; ?>

        <div class="layout">
            <div class="left-col">
                <div class="summary-note">This page edits the single global STT connector. Switching services updates the provider used by Stobe push-to-talk.</div>
                <div class="list-wrap" id="stt_driver_list">
                    <?php foreach ($groupedDriverOptions as $groupLabel => $groupDrivers): ?>
                        <?php if (!$groupDrivers) continue; ?>
                        <div class="group-title"><?= h($groupLabel) ?></div>
                        <?php foreach ($groupDrivers as $driverValue): ?>
                            <div class="conn-card<?= $currentDriver === $driverValue ? ' active' : '' ?>" data-driver-card="<?= h($driverValue) ?>">
                                <div class="conn-head">
                                    <div class="conn-name"><?= h($connector->getDisplayName($driverValue)) ?></div>
                                    <div class="conn-badge"><?= h($driverValue === 'none' ? 'SYSTEM' : strtoupper($driverValue)) ?></div>
                                </div>
                                <div class="conn-sub"><?= h(sttProviderTitle($driverValue)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="right-col">
                <form method="post" action="<?= h(sttPageUrl()) ?>" id="stt_connector_form">
                    <input type="hidden" name="save_connector" value="1">
                    <div class="btn-row">
                        <button type="submit" class="btn-save">Save</button>
                        <button type="button" class="btn-primary" id="btn_test_connector_inline">Test</button>
                    </div>
                    <div class="orm-note">Testing saves the current connector first so the microphone test uses the latest settings.</div>

                    <div class="editor-grid">
                        <div class="field-block">
                            <label for="label">Name</label>
                            <input type="text" id="label" name="label" value="<?= h($row['label'] ?? 'Global STT Connector') ?>">
                            <div class="field-help">Internal name for the global Stobe speech-to-text connector.</div>
                        </div>
                        <div class="field-block">
                            <label for="driver">Service</label>
                            <select id="driver" name="driver">
                                <?php foreach ($groupedDriverOptions as $groupLabel => $groupDrivers): ?>
                                    <?php if (!$groupDrivers) continue; ?>
                                    <optgroup label="<?= h($groupLabel) ?>">
                                        <?php foreach ($groupDrivers as $driverValue): ?>
                                            <option value="<?= h($driverValue) ?>"<?= $currentDriver === $driverValue ? ' selected' : '' ?>><?= h($connector->getDisplayName($driverValue)) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Choose the speech-to-text service Stobe should use globally.</div>
                        </div>
                        <div class="field-block" id="url_block">
                            <label for="url">URL</label>
                            <input type="url" id="url" name="url" value="<?= h($row['url'] ?? $connector->getDefaultUrlForDriver($currentDriver)) ?>">
                            <div class="field-help">Used for local or remote speech-to-text endpoints.</div>
                        </div>
                        <div class="field-block" id="api_badge_block">
                            <label for="api_badge_id">API Badge</label>
                            <select id="api_badge_id" name="api_badge_id">
                                <option value="">-- None --</option>
                                <?php foreach ($apiRows as $apiRow): ?>
                                    <?php $hasKey = sttApiBadgeHasKey($apiRow['api_key'] ?? ''); ?>
                                    <option value="<?= intval($apiRow['id']) ?>" data-empty="<?= $hasKey ? '0' : '1' ?>"<?= intval($row['api_badge_id'] ?? 0) === intval($apiRow['id']) ? ' selected' : '' ?>>
                                        <?= h(($hasKey ? '[Configured] ' : '[Missing key] ') . $apiRow['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="api_key_notice" class="api-key-notice"></div>
                            <div class="field-help">Cloud services require a configured key from the API Keys page.</div>
                        </div>
                    </div>

                    <?php foreach ($driverOptions as $driverValue): ?>
                        <?php $schema = $connector->getProviderFieldSchema($driverValue); ?>
                        <div class="meta-group<?= $currentDriver === $driverValue ? ' active' : '' ?>" data-driver-fields="<?= h($driverValue) ?>">
                            <h3><?= h($connector->getDisplayName($driverValue)) ?> Settings</h3>
                            <?php if (!$schema): ?>
                                <div class="settings-empty-note">This service does not have additional connector settings.</div>
                            <?php else: ?>
                                <div class="inline-two">
                                    <?php foreach ($schema as $name => $definition): ?>
                                        <?php
                                        $fieldKey = 'meta__' . $driverValue . '__' . $name;
                                        $fieldValue = $driverValue === $currentDriver
                                            ? ($currentMetadata[$name] ?? ($definition['default'] ?? ''))
                                            : ($definition['default'] ?? '');
                                        ?>
                                        <div class="field-block">
                                            <label for="<?= h($fieldKey) ?>"><?= h(sttFieldLabel($name)) ?></label>
                                            <?php if (($definition['type'] ?? '') === 'boolean'): ?>
                                                <select id="<?= h($fieldKey) ?>" name="<?= h($fieldKey) ?>">
                                                    <option value="true"<?= $fieldValue ? ' selected' : '' ?>>Enabled</option>
                                                    <option value="false"<?= !$fieldValue ? ' selected' : '' ?>>Disabled</option>
                                                </select>
                                            <?php elseif (($definition['type'] ?? '') === 'integer'): ?>
                                                <input type="number" min="5" max="120" step="1" id="<?= h($fieldKey) ?>" name="<?= h($fieldKey) ?>" value="<?= h($fieldValue) ?>">
                                            <?php else: ?>
                                                <input type="text" id="<?= h($fieldKey) ?>" name="<?= h($fieldKey) ?>" value="<?= h($fieldValue) ?>">
                                            <?php endif; ?>
                                            <?php if (sttFieldHelp($name) !== ''): ?><div class="field-help"><?= h(sttFieldHelp($name)) ?></div><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </form>
            </div>
        </div>
    </div>

    <div id="stt_test_modal">
        <div class="inner">
            <button type="button" class="btn-secondary" id="stt_test_close">Close</button>
            <iframe id="stt_test_iframe" src="about:blank"></iframe>
        </div>
    </div>
</main>
<script>
(function () {
    const form = document.getElementById('stt_connector_form');
    const driverSelect = document.getElementById('driver');
    const driverCards = document.querySelectorAll('[data-driver-card]');
    const apiBadgeBlock = document.getElementById('api_badge_block');
    const apiBadgeSelect = document.getElementById('api_badge_id');
    const apiKeyNotice = document.getElementById('api_key_notice');
    const urlBlock = document.getElementById('url_block');
    const urlInput = document.getElementById('url');
    const modal = document.getElementById('stt_test_modal');
    const iframe = document.getElementById('stt_test_iframe');
    const apiDrivers = <?= json_encode($apiDrivers, JSON_UNESCAPED_SLASHES) ?>;
    const urlDrivers = <?= json_encode($urlDrivers, JSON_UNESCAPED_SLASHES) ?>;
    const defaultUrls = <?= json_encode($defaultUrls, JSON_UNESCAPED_SLASHES) ?>;
    const defaultApiBadgeIds = <?= json_encode($defaultApiBadgeIds, JSON_UNESCAPED_SLASHES) ?>;
    let previousDriver = driverSelect.value;

    function updateApiBadgeNotice() {
        if (apiBadgeBlock.style.display === 'none') {
            apiKeyNotice.textContent = '';
            return;
        }
        const option = apiBadgeSelect.options[apiBadgeSelect.selectedIndex];
        if (!option || !apiBadgeSelect.value) {
            apiKeyNotice.className = 'api-key-notice warn';
            apiKeyNotice.textContent = 'No API key selected.';
        } else if (option.dataset.empty === '1') {
            apiKeyNotice.className = 'api-key-notice warn';
            apiKeyNotice.textContent = 'Selected API badge does not have a configured key.';
        } else {
            apiKeyNotice.className = 'api-key-notice ok';
            apiKeyNotice.textContent = 'Selected API badge is configured.';
        }
    }

    function syncDriverFields() {
        const selected = driverSelect.value;
        document.querySelectorAll('[data-driver-fields]').forEach(group => group.classList.toggle('active', group.dataset.driverFields === selected));
        driverCards.forEach(card => card.classList.toggle('active', card.dataset.driverCard === selected));

        const usesApi = apiDrivers.includes(selected);
        apiBadgeBlock.style.display = usesApi ? '' : 'none';
        if (usesApi && (!apiBadgeSelect.value || apiBadgeSelect.value === String(defaultApiBadgeIds[previousDriver] || ''))) {
            apiBadgeSelect.value = String(defaultApiBadgeIds[selected] || '');
        }

        const usesUrl = urlDrivers.includes(selected);
        urlBlock.style.display = usesUrl ? '' : 'none';
        const previousUrl = defaultUrls[previousDriver] || '';
        if (usesUrl && (!urlInput.value || urlInput.value === previousUrl)) {
            urlInput.value = defaultUrls[selected] || '';
        }
        previousDriver = selected;
        updateApiBadgeNotice();
    }

    async function saveBeforeTest() {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
        return response.ok;
    }

    driverSelect.addEventListener('change', syncDriverFields);
    driverCards.forEach(card => card.addEventListener('click', function () {
        driverSelect.value = card.dataset.driverCard;
        syncDriverFields();
    }));
    apiBadgeSelect.addEventListener('change', updateApiBadgeNotice);
    document.getElementById('btn_test_connector_inline').addEventListener('click', async function () {
        if (!await saveBeforeTest()) return;
        iframe.src = 'stttest.php?embed=1&cb=' + Date.now();
        modal.style.display = 'flex';
    });
    document.getElementById('stt_test_close').addEventListener('click', function () {
        modal.style.display = 'none';
        iframe.src = 'about:blank';
    });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) document.getElementById('stt_test_close').click();
    });
    syncDriverFields();
})();
</script>
</body>
</html>
