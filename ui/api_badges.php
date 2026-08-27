<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8");
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';
$withEmbed = function (string $url) use ($isEmbed): string {
    return $isEmbed ? ($url . (strpos($url, '?') === false ? '?embed=1' : '&embed=1')) : $url;
};

$presetProviders = [
    'openrouter' => [
        'label' => 'OpenRouter',
        'usage' => 'LLM connectors using OpenRouter models',
        'key_url' => 'https://openrouter.ai/keys',
    ],
    'openai' => [
        'label' => 'OpenAI',
        'usage' => 'LLM connectors using OpenAI-compatible models',
        'key_url' => 'https://platform.openai.com/api-keys',
    ],
    'groq' => [
        'label' => 'Groq',
        'usage' => 'LLM connectors using Groq models',
        'key_url' => 'https://console.groq.com/keys',
    ],
    'nano-gpt' => [
        'label' => 'Nano-GPT',
        'usage' => 'LLM connectors routed through Nano-GPT',
        'key_url' => 'https://nano-gpt.com/',
    ],
    'google' => [
        'label' => 'Google',
        'usage' => 'LLM connectors using Google model endpoints',
        'key_url' => 'https://console.cloud.google.com/apis/credentials',
    ],
    'cartesia' => [
        'label' => 'Cartesia',
        'usage' => 'Cartesia TTS connector',
        'key_url' => 'https://play.cartesia.ai/console',
    ],
    'inworld' => [
        'label' => 'Inworld',
        'usage' => 'Inworld TTS connector',
        'key_url' => 'https://studio.inworld.ai/',
    ],
];

$reservedLabelsLower = [];
foreach ($presetProviders as $provider) {
    $reservedLabelsLower[] = strtolower(trim(strval($provider['label'] ?? '')));
}

foreach ($presetProviders as $provider) {
    $label = trim(strval($provider['label'] ?? ''));
    if ($label === '') {
        continue;
    }
    $existing = getApiBadgeByLabel($label);
    if (!$existing) {
        saveApiBadge(['label' => $label, 'api_key' => '']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_presets'])) {
    foreach ($presetProviders as $slug => $provider) {
        $posted = $_POST['presets'][$slug] ?? null;
        if (!is_array($posted)) {
            continue;
        }
        $id = intval($posted['id'] ?? 0);
        $label = trim(strval($provider['label'] ?? ''));
        $apiKey = trim(strval($posted['api_key'] ?? ''));
        if ($id <= 0) {
            $current = getApiBadgeByLabel($label);
            $id = intval($current['id'] ?? 0);
        }
        $fields = ['label' => $label, 'api_key' => $apiKey];
        if ($id > 0) {
            $fields['id'] = $id;
        }
        saveApiBadge($fields);
    }
    header('Location: ' . $withEmbed('api_badges.php?ok=saved'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_custom'])) {
    $customId = intval($_POST['custom_id'] ?? 0);
    $label = trim(strval($_POST['label'] ?? ''));
    $apiKey = trim(strval($_POST['api_key'] ?? ''));

    if ($label === '') {
        header('Location: ' . $withEmbed('api_badges.php?err=empty_label'));
        exit;
    }

    $labelLower = strtolower($label);
    if (in_array($labelLower, $reservedLabelsLower, true)) {
        header('Location: ' . $withEmbed('api_badges.php?err=reserved_label'));
        exit;
    }

    $fields = ['label' => $label, 'api_key' => $apiKey];
    if ($customId > 0) {
        $fields['id'] = $customId;
    }
    saveApiBadge($fields);
    header('Location: ' . $withEmbed('api_badges.php?ok=saved'));
    exit;
}

if (isset($_GET['delete_custom'])) {
    $deleteId = intval($_GET['delete_custom']);
    $row = $deleteId > 0 ? getApiBadgeById($deleteId) : false;
    if ($row) {
        $labelLower = strtolower(trim(strval($row['label'] ?? '')));
        if (!in_array($labelLower, $reservedLabelsLower, true)) {
            deleteApiBadge($deleteId);
        }
    }
    header('Location: ' . $withEmbed('api_badges.php?ok=deleted'));
    exit;
}

$allRows = getAllApiBadges();
$rowsByLabel = [];
foreach ($allRows as $row) {
    $rowsByLabel[strtolower(trim(strval($row['label'] ?? '')))] = $row;
}

$presetRows = [];
foreach ($presetProviders as $slug => $provider) {
    $lower = strtolower(trim(strval($provider['label'] ?? '')));
    $presetRows[$slug] = $rowsByLabel[$lower] ?? [
        'id' => '',
        'label' => strval($provider['label'] ?? ''),
        'api_key' => '',
    ];
}

$customRows = [];
foreach ($allRows as $row) {
    $labelLower = strtolower(trim(strval($row['label'] ?? '')));
    if (!in_array($labelLower, $reservedLabelsLower, true)) {
        $customRows[] = $row;
    }
}

$editCustom = false;
if (isset($_GET['edit_custom'])) {
    $editCandidate = getApiBadgeById(intval($_GET['edit_custom']));
    if ($editCandidate) {
        $labelLower = strtolower(trim(strval($editCandidate['label'] ?? '')));
        if (!in_array($labelLower, $reservedLabelsLower, true)) {
            $editCustom = $editCandidate;
        }
    }
}

$TITLE = "API Keys";
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/head.html");
?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<style>
main{padding:10px 5px 5px}.grid{display:grid;grid-template-columns:1fr;gap:16px}.section{border:1px solid #3a3a3a;border-radius:10px;background:linear-gradient(180deg,rgba(42,42,42,.95),rgba(34,34,34,.98));padding:14px}.section-head{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}.section-title{margin:0;color:#e6b76c;font-family:'MagicCards',serif}.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.card{border:1px solid #3a3a3a;border-radius:10px;padding:12px;background:rgba(26,26,26,.8)}.card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.card-title{font-weight:600}.small{color:#9fb1c9;font-size:12px}.links a{color:#e6b76c;text-decoration:underline;font-size:12px}.row{display:flex;gap:8px;align-items:center}label{color:#e6b76c;font-weight:600;margin-top:8px;margin-bottom:6px;display:block}input[type=text],input[type=password]{width:100%;box-sizing:border-box;background:rgba(26,26,26,.8);color:#e9efff;border:1px solid #3a3a3a;border-radius:6px;padding:10px 12px}.btn{padding:10px 14px;color:#fff;border-radius:8px;border:1px solid rgba(138,155,182,.35);background:#2f3b52;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-save{background:#176529;border-color:#2b7d3d}.btn-danger{background:#8a1a1a;border-color:#992c2c}.help{color:#9fb1c9;font-size:12px;margin-top:4px}.custom-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pill{font-size:11px;color:#9fb1c9;border:1px solid #4a4a4a;border-radius:999px;padding:2px 6px}@media (max-width:980px){.cards,.custom-list{grid-template-columns:1fr}}
</style>
<style>
/* Page header is the shared compact inline row (.stobe-page-head in main.css). */
</style>
<main class="d-flex flex-column">
    <div class="page-header stobe-page-head">
        <h1 class="api-title stobe-page-head-title">API Keys</h1>
        <p class="page-subtitle stobe-page-head-note">Configure API key badges used by LLM and TTS connectors</p>
    </div>

    <?php if (isset($_GET['err']) && $_GET['err'] === 'reserved_label'): ?>
        <div class="help" style="color:#ffb862;margin-bottom:10px;">That label is reserved for a preset provider.</div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'empty_label'): ?>
        <div class="help" style="color:#ffb862;margin-bottom:10px;">Custom label cannot be empty.</div>
    <?php elseif (isset($_GET['ok'])): ?>
        <div class="help" style="color:#8ee0a2;margin-bottom:10px;">Saved.</div>
    <?php endif; ?>

    <div class="grid">
        <section class="section">
            <form method="post" action="<?= h($withEmbed('api_badges.php')) ?>">
                <div class="section-head">
                    <h2 class="section-title">Preset Providers</h2>
                    <button type="submit" name="save_presets" value="1" class="btn btn-save">Save Preset Keys</button>
                </div>
                <div class="cards">
                    <?php foreach ($presetProviders as $slug => $provider): $row = $presetRows[$slug]; ?>
                        <div class="card">
                            <div class="card-head">
                                <div class="card-title"><?= h($provider['label']) ?></div>
                                <div class="links"><a href="<?= h($provider['key_url']) ?>" target="_blank" rel="noopener">Create Key</a></div>
                            </div>
                            <div class="small"><?= h($provider['usage']) ?></div>
                            <input type="hidden" name="presets[<?= h($slug) ?>][id]" value="<?= h($row['id'] ?? '') ?>">
                            <label for="preset_<?= h($slug) ?>">API Key</label>
                            <div class="row">
                                <input id="preset_<?= h($slug) ?>" type="password" name="presets[<?= h($slug) ?>][api_key]" value="<?= h($row['api_key'] ?? '') ?>" placeholder="Paste API key">
                                <button type="button" class="btn" onclick="toggleKey(this)">Show</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>
        </section>

        <section class="section">
            <div class="section-head">
                <h2 class="section-title">Custom API Badges</h2>
            </div>
            <form method="post" action="<?= h($withEmbed('api_badges.php')) ?>" style="margin-bottom:14px;">
                <input type="hidden" name="save_custom" value="1">
                <input type="hidden" name="custom_id" value="<?= h($editCustom['id'] ?? '') ?>">
                <div class="cards">
                    <div class="card" style="grid-column:1/-1;">
                        <div class="row" style="gap:12px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:240px;">
                                <label for="custom_label">Label</label>
                                <input id="custom_label" type="text" name="label" value="<?= h($editCustom['label'] ?? '') ?>" placeholder="Example: My Provider Key">
                            </div>
                            <div style="flex:1;min-width:240px;">
                                <label for="custom_api_key">API Key</label>
                                <div class="row">
                                    <input id="custom_api_key" type="password" name="api_key" value="<?= h($editCustom['api_key'] ?? '') ?>" placeholder="Paste API key">
                                    <button type="button" class="btn" onclick="toggleKey(this)">Show</button>
                                </div>
                            </div>
                        </div>
                        <div class="row" style="justify-content:flex-end;margin-top:10px;">
                            <?php if ($editCustom): ?>
                                <a class="btn" href="<?= h($withEmbed('api_badges.php')) ?>">Clear</a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-save"><?= $editCustom ? 'Update Custom Badge' : 'Create Custom Badge' ?></button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="custom-list">
                <?php foreach ($customRows as $row): ?>
                    <div class="card">
                        <div class="card-head">
                            <div class="card-title"><?= h($row['label'] ?? '') ?></div>
                            <div class="pill">ID <?= h($row['id'] ?? '') ?></div>
                        </div>
                        <div class="small">Custom badge label for connector assignment.</div>
                        <div class="row" style="justify-content:flex-end;margin-top:10px;">
                            <a class="btn" href="<?= h($withEmbed('api_badges.php?edit_custom=' . intval($row['id'] ?? 0))) ?>">Edit</a>
                            <a class="btn btn-danger" href="<?= h($withEmbed('api_badges.php?delete_custom=' . intval($row['id'] ?? 0))) ?>" onclick="return confirm('Delete this custom API badge?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

    </div>
</main>
<script>
function toggleKey(button){
    const row = button.parentElement;
    if (!row) return;
    const input = row.querySelector('input');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = 'Show';
    }
}
</script>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;
?>

