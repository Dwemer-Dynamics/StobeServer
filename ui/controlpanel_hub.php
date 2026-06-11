<?php
/**
 * StobeServer Control Panel Hub.
 * Config-hub style tab shell for control panel pages.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');
$distroDashboardRoot = preg_replace('#/(HerikaServer|StobeServer)$#', '/Dwemer-Dashboard', $webRoot);
if (!is_string($distroDashboardRoot) || trim($distroDashboardRoot) === '' || $distroDashboardRoot === $webRoot) {
    $distroDashboardRoot = '/Dwemer-Dashboard';
}
$distroDebuggerStobeEmbedUrl = rtrim($distroDashboardRoot, '/') . '/distro_debugger.php?embed=1&tab=stobe';
$distroDatabaseManagerUrl = rtrim($distroDashboardRoot, '/') . '/database_manager.php';

function buildTabTargetSrc(array $tab, string $webRoot): string
{
    $directUrl = trim(strval($tab['url'] ?? ''));
    if ($directUrl !== '') {
        return $directUrl;
    }
    $page = trim(strval($tab['page'] ?? ''));
    if ($page === '') {
        return '';
    }
    $src = ($webRoot !== '' ? $webRoot : '') . '/ui/' . ltrim($page, '/');
    if (!empty($tab['embed'])) {
        $src .= '?embed=1';
    }
    return $src;
}

$tabs = [
    ['id' => 'server_logs', 'label' => 'Server Logs', 'page' => '', 'url' => $distroDebuggerStobeEmbedUrl, 'status' => 'wired', 'embed' => false],
    ['id' => 'audio_image_cache', 'label' => 'Audio & Image Cache', 'page' => '', 'url' => ($webRoot !== '' ? $webRoot : '') . '/soundcache/', 'status' => 'wired', 'embed' => false],
    ['id' => 'request_logs', 'label' => 'Request Logs', 'page' => 'request_logs.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'world_knowledge_audit', 'label' => 'World Knowledge Audit', 'page' => 'world_knowledge_audit.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'cost_breakdown', 'label' => 'Cost Breakdown', 'page' => 'audit.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'response_queue', 'label' => 'Response Queue', 'page' => 'response_queue.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'relationship_logs', 'label' => 'Relationship Logs', 'page' => 'relationship_logs.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'playthrough_manager', 'label' => 'Playthrough Manager', 'page' => 'playthrough_manager.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'database_manager', 'label' => 'Database Manager', 'page' => '', 'url' => $distroDatabaseManagerUrl, 'status' => 'wired', 'embed' => false],
];

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'server_logs')));
if ($activeTab === 'playthough_manager') {
    $activeTab = 'playthrough_manager';
}
$tabMap = [];
foreach ($tabs as $tab) {
    $tabMap[$tab['id']] = $tab;
}
if (!isset($tabMap[$activeTab])) {
    $activeTab = 'server_logs';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Panel</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        body {
            padding-top: 80px;
        }

        main {
            padding-top: 20px;
            padding-bottom: 40px;
            padding-left: 10px;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1.5px;
        }

        .tab-container {
            margin: 20px 0;
        }

        .tab-buttons {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.2);
            gap: 5px;
            word-spacing: 5px;
        }

        .tab-button {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.8), rgba(34, 34, 34, 0.9));
            border: 2px solid #3a3a3a;
            border-bottom: none;
            padding: 12px 18px;
            color: #f8f9fa;
            cursor: pointer;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            transition: all 0.3s ease;
            font-size: 1em;
            white-space: nowrap;
            font-family: "MagicCards", sans-serif;
            word-spacing: 5px;
            letter-spacing: 1.5px;
            margin-bottom: -2px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
        }

        .tab-button:hover {
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 1));
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-decoration: none;
        }

        .tab-button.active {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-color: rgba(230, 183, 108, 0.5);
            border-bottom: 2px solid rgba(42, 42, 42, 0.95);
            color: #e6b76c;
            box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
        }

        .tab-content {
            display: none;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 20px;
            border-radius: 8px;
            border-top-left-radius: 0;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            min-height: 240px;
        }

        .tab-content.active {
            display: block;
        }

        .embed-wrap {
            width: 100%;
            min-height: calc(100vh - 260px);
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            overflow: hidden;
            background: #2a2a2a;
        }

        .embed {
            width: 100%;
            min-height: calc(100vh - 260px);
            border: 0;
            background: transparent;
        }

        .embed-wrap-light {
            background: #ffffff;
            border-color: #d7d7d7;
        }

        .embed-light {
            background: #ffffff;
        }

        .info-message {
            color: #9ca3af;
            padding: 18px 4px;
        }
    </style>
</head>
<body>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>

<main class="container-fluid">
    <div class="tab-container">
        <div class="tab-buttons">
            <?php foreach ($tabs as $tab): ?>
                <button
                    type="button"
                    class="tab-button <?= $activeTab === $tab['id'] ? 'active' : '' ?>"
                    data-tab="<?= h($tab['id']) ?>"
                ><?= h($tab['label']) ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($tabs as $tab): ?>
            <?php
                $isActive = ($activeTab === $tab['id']);
                $targetSrc = ($tab['status'] === 'wired') ? buildTabTargetSrc($tab, $webRoot) : '';
                $hasPage = ($targetSrc !== '');
                $isLightEmbed = ($tab['id'] === 'audio_image_cache');
            ?>
            <div id="tab-<?= h($tab['id']) ?>" class="tab-content <?= $isActive ? 'active' : '' ?>">
                <?php if ($hasPage): ?>
                    <div class="embed-wrap<?= $isLightEmbed ? ' embed-wrap-light' : '' ?>">
                        <iframe
                            class="embed<?= $isLightEmbed ? ' embed-light' : '' ?>"
                            loading="<?= $isActive ? 'eager' : 'lazy' ?>"
                            src="<?= $isActive ? h($targetSrc) : 'about:blank' ?>"
                            data-src="<?= h($targetSrc) ?>"
                        ></iframe>
                    </div>
                <?php else: ?>
                    <div class="info-message">This tab is not wired yet in StobeServer UI.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<script>
(function(){
    const buttons = document.querySelectorAll('.tab-button');
    const tabs = document.querySelectorAll('.tab-content');

    function loadIframeFor(tabPane) {
        if (!tabPane) return;
        const iframe = tabPane.querySelector('iframe[data-src]');
        if (!iframe) return;
        if (iframe.src === 'about:blank') {
            iframe.src = iframe.getAttribute('data-src');
        }
    }

    function activate(tabId) {
        buttons.forEach(function(btn){
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        tabs.forEach(function(pane){
            const isActive = (pane.id === ('tab-' + tabId));
            pane.classList.toggle('active', isActive);
            if (isActive) {
                loadIframeFor(pane);
            }
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url.toString());
    }

    buttons.forEach(function(btn){
        btn.addEventListener('click', function(){
            activate(btn.dataset.tab);
        });
    });

    const active = document.querySelector('.tab-content.active');
    loadIframeFor(active);
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

