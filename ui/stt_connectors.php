<?php
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $path . 'lib/bootstrap.php';
require_once $path . 'lib/core/stt_connector.class.php';
try { require_once $path . 'debug/db_updates.php'; } catch (Throwable $exception) { stobeLogException($exception, 'STT connector migration failed'); }
function h(mixed $value): string { return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8'); }
$connector = new STTConnector();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stt'])) {
    $driver = $connector->normalizeDriverValue($_POST['driver'] ?? 'parakeet');
    $metadata = $connector->defaultsForDriver($driver);
    foreach ($connector->getProviderFieldSchema($driver) as $name => $definition) {
        $key = 'meta_' . $name;
        if (($definition['type'] ?? '') === 'boolean') $metadata[$name] = isset($_POST[$key]);
        elseif (($definition['type'] ?? '') === 'integer') $metadata[$name] = max(5, min(120, intval($_POST[$key] ?? 60)));
        else $metadata[$name] = trim(strval($_POST[$key] ?? ($definition['default'] ?? '')));
    }
    $id = $connector->saveGlobal(['driver' => $driver, 'metadata' => $metadata,
        'api_badge_id' => $connector->driverUsesApiBadge($driver) ? intval($_POST['api_badge_id'] ?? 0) : null,
        'url' => trim(strval($_POST['url'] ?? ''))]);
    $message = $id > 0 ? 'Speech-to-text settings saved.' : 'Speech-to-text settings could not be saved.';
}
$row = $connector->getActive() ?: [];
$driver = $connector->normalizeDriverValue($row['driver'] ?? 'parakeet');
$metadata = array_merge($connector->defaultsForDriver($driver), $connector->decodeMetadata($row['metadata'] ?? '{}'));
$badges = $GLOBALS['db']->fetchAll("SELECT id, label, COALESCE(api_key, '') AS api_key FROM core_api_badge ORDER BY LOWER(label)");
$isEmbed = strval($_GET['embed'] ?? '') === '1';
$schemas = []; $urls = [];
foreach ($connector->getDriverOptions() as $option) { $schemas[$option] = $connector->getProviderFieldSchema($option); $urls[$option] = $connector->getDefaultUrlForDriver($option); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Speech to Text</title><link rel="stylesheet" href="css/main.css"><link rel="stylesheet" href="css/stobe-theme.css">
<style>
body{padding:<?= $isEmbed ? '10px' : '80px 12px 30px' ?>;background:#2c2c2c}.stt-shell{max-width:960px;margin:0 auto}.stt-card{background:#242424;border:1px solid #444;border-radius:9px;padding:18px}.stt-card h1{color:#e6b76c;font-size:1.7rem;margin:0 0 8px}.stt-help{color:#aeb8c8;margin:0 0 16px}.stt-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.stt-field{display:flex;flex-direction:column;gap:6px}.stt-field label{color:#e6b76c;font-weight:600}.stt-field input,.stt-field select{background:#191919;color:#fff;border:1px solid #4a4a4a;border-radius:7px;padding:9px}.stt-check{display:flex;align-items:center;justify-content:space-between;border:1px solid #3d3d3d;padding:9px;border-radius:7px}.stt-check input{width:18px;height:18px}.stt-actions{display:flex;gap:10px;margin-top:16px}.stt-actions button,.stt-actions a{background:#176529;color:#fff;border:1px solid #2b7d3d;border-radius:7px;padding:9px 14px;text-decoration:none}.stt-actions a{background:#2f3b52;border-color:#53617a}.stt-message{color:#8ee0a2;margin-bottom:12px}@media(max-width:700px){.stt-grid{grid-template-columns:1fr}}
</style></head><body>
<?php if (!$isEmbed) include __DIR__ . '/tmpl/navbar.php'; ?>
<main class="stt-shell"><section class="stt-card"><h1>Speech to Text</h1><p class="stt-help">Choose the service used by Stobe push-to-talk. Parakeet is the default local service.</p>
<?php if ($message !== ''): ?><div class="stt-message"><?= h($message) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="save_stt" value="1"><div class="stt-grid">
<div class="stt-field"><label for="driver">STT Service</label><select id="driver" name="driver">
<?php foreach ($connector->getDriverOptions() as $option): ?><option value="<?= h($option) ?>"<?= $driver === $option ? ' selected' : '' ?>><?= h($connector->getDisplayName($option)) ?><?= $option === 'parakeet' ? ' (Recommended)' : '' ?></option><?php endforeach; ?>
</select></div>
<div class="stt-field" data-url-field><label for="url">Service URL</label><input id="url" name="url" type="url" value="<?= h($row['url'] ?? $connector->getDefaultUrlForDriver($driver)) ?>"></div>
<div class="stt-field" data-api-field><label for="api_badge_id">API Key</label><select id="api_badge_id" name="api_badge_id"><option value="0">Choose an API key</option>
<?php foreach ($badges as $badge): ?><option value="<?= intval($badge['id']) ?>"<?= intval($row['api_badge_id'] ?? 0) === intval($badge['id']) ? ' selected' : '' ?>><?= h($badge['label']) ?><?= trim(strval($badge['api_key'])) !== '' ? ' (configured)' : '' ?></option><?php endforeach; ?></select></div>
<div id="provider-fields"></div></div>
<div class="stt-actions"><button type="submit">Save</button><a href="stttest.php<?= $isEmbed ? '?embed=1' : '' ?>">Test Microphone</a></div></form></section></main>
<script>
const schemas=<?= json_encode($schemas, JSON_UNESCAPED_SLASHES) ?>;
const values=<?= json_encode($metadata, JSON_UNESCAPED_SLASHES) ?>;
const urls=<?= json_encode($urls, JSON_UNESCAPED_SLASHES) ?>;
const apiDrivers=['deepgram','whisper','gemini','azure','inworld'],urlDrivers=['parakeet','localwhisper','whisper','deepgram'];
function render(){const d=document.getElementById('driver').value,p=document.getElementById('provider-fields');p.innerHTML='';Object.entries(schemas[d]||{}).forEach(([name,def])=>{const wrap=document.createElement('div');wrap.className=def.type==='boolean'?'stt-check':'stt-field';const label=document.createElement('label');label.textContent=name.toLowerCase().replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());const input=document.createElement('input');input.name='meta_'+name;if(def.type==='boolean'){input.type='checkbox';input.checked=Boolean(values[name]??def.default);wrap.append(label,input)}else{input.type=def.type==='integer'?'number':'text';input.value=values[name]??def.default??'';wrap.append(label,input)}p.append(wrap)});document.querySelector('[data-api-field]').style.display=apiDrivers.includes(d)?'flex':'none';document.querySelector('[data-url-field]').style.display=urlDrivers.includes(d)?'flex':'none';const u=document.getElementById('url');if(urlDrivers.includes(d)&&(!u.value||Object.values(urls).includes(u.value)))u.value=urls[d]||''}
document.getElementById('driver').addEventListener('change',render);render();
</script></body></html>
