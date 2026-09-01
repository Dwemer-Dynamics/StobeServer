<?php
/**
 * StobeServer Roleplay Hub.
 * Groups event, memory, and diary tools without duplicating their data logic.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . 'lib/bootstrap.php');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');
$uiRoot = $webRoot . '/ui';

$tabs = [
    ['id' => 'events', 'group' => 'activity-logs', 'icon' => '&#x1F4DD;', 'label' => 'Events', 'src' => $uiRoot . '/events.php?embed=1'],
    ['id' => 'responses', 'group' => 'activity-logs', 'icon' => '&#x1F4AC;', 'label' => 'AI Responses', 'src' => $uiRoot . '/ai-response.php?embed=1'],
    ['id' => 'adventure', 'group' => 'activity-logs', 'icon' => '&#x1F4C6;', 'label' => 'Adventure Log', 'src' => $uiRoot . '/adventurelog.php?embed=1'],
    ['id' => 'memory', 'group' => 'memories-records', 'icon' => '&#x1F9E0;', 'label' => 'Memories', 'src' => $uiRoot . '/memories.php?embed=1'],
    ['id' => 'diaries', 'group' => 'memories-records', 'icon' => '&#x1F4D4;', 'label' => 'Stobe Diaries', 'src' => $uiRoot . '/diarylog.php?embed=1'],
];

$tabGroups = [
    'activity-logs' => 'Activity & Logs',
    'memories-records' => 'Memories & Records',
];

$aliases = [
    'eventlog' => 'events',
    'responselog' => 'responses',
    'memories' => 'memory',
    'adventure_log' => 'adventure',
    'diary_log' => 'diaries',
];
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'events')));
$activeTab = $aliases[$activeTab] ?? $activeTab;
$tabMap = [];
foreach ($tabs as $tab) {
    $tabMap[$tab['id']] = $tab;
}
if (!isset($tabMap[$activeTab])) {
    $activeTab = 'events';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roleplay</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/hub-navigation.css?v=<?= filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'hub-navigation.css') ?>">
    <style>
        body { padding-top: var(--hub-navbar-offset); }
        main { padding: 0 10px 8px; }
        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }
        .tab-container { margin: 0 0 6px; }

        /*
         * Fill the space under the navbar + compact nav instead of hard-coding an
         * offset, so a nav that wraps to two rows on mid-size viewports shrinks the
         * embed rather than overflowing the page.
         */
        body.hub-page > main {
            display: flex;
            flex-direction: column;
            height: calc(100vh - var(--hub-navbar-offset));
            min-height: 0;
        }
        .tab-container {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }
        .config-navigation.roleplay-hub-nav { flex: 0 0 auto; }

        .tab-content {
            display: none;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .tab-content.active {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }
        .embed-wrap {
            width: 100%;
            flex: 1 1 auto;
            height: auto;
            min-height: 380px;
            overflow: hidden;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            background: #2a2a2a;
        }
        .embed { width: 100%; height: 100%; border: 0; background: transparent; }
        @media (max-height: 800px) { .embed-wrap { min-height: 320px; } }
    </style>
</head>
<body class="hub-page">
<?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>

<main class="container-fluid">
    <div class="tab-container">
        <div class="config-navigation roleplay-hub-nav" aria-label="Roleplay sections">
            <div class="tab-groups">
                <?php foreach ($tabGroups as $groupId => $groupLabel): ?>
                    <section class="tab-group <?= ($tabMap[$activeTab]['group'] ?? '') === $groupId ? 'active' : '' ?>" data-category="<?= h($groupId) ?>">
                        <div class="tab-group-label"><?= h($groupLabel) ?></div>
                        <div class="tab-buttons" role="tablist" aria-label="<?= h($groupLabel) ?> pages">
                            <?php foreach ($tabs as $tab): ?>
                                <?php if ($tab['group'] !== $groupId) continue; ?>
                                <button type="button" class="tab-button <?= $activeTab === $tab['id'] ? 'active' : '' ?>" data-tab="<?= h($tab['id']) ?>" data-category="<?= h($groupId) ?>">
                                    <span class="tab-icon" aria-hidden="true"><?= $tab['icon'] ?></span><span><?= h($tab['label']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($tabs as $tab): ?>
            <?php $isActive = $activeTab === $tab['id']; ?>
            <div id="tab-<?= h($tab['id']) ?>" class="tab-content <?= $isActive ? 'active' : '' ?>">
                <div class="embed-wrap">
                    <iframe class="embed" title="<?= h($tab['label']) ?>" loading="<?= $isActive ? 'eager' : 'lazy' ?>" src="<?= $isActive ? h($tab['src']) : 'about:blank' ?>" data-src="<?= h($tab['src']) ?>"></iframe>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<script>
(function(){
    const buttons = document.querySelectorAll('.config-navigation .tab-button');
    const groups = document.querySelectorAll('.tab-group');
    const tabs = document.querySelectorAll('.tab-content');

    function loadFrame(pane) {
        const frame = pane ? pane.querySelector('iframe[data-src]') : null;
        if (frame && (!frame.src || frame.src === 'about:blank')) {
            frame.src = frame.dataset.src;
        }
    }

    function activate(tabId) {
        const selected = Array.from(buttons).find(function(button){ return button.dataset.tab === tabId; });
        if (!selected) return;
        groups.forEach(function(group){ group.classList.toggle('active', group.dataset.category === selected.dataset.category); });
        buttons.forEach(function(button){ button.classList.toggle('active', button.dataset.tab === tabId); });
        tabs.forEach(function(pane){
            const isActive = pane.id === 'tab-' + tabId;
            pane.classList.toggle('active', isActive);
            if (isActive) loadFrame(pane);
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url.toString());
    }

    buttons.forEach(function(button){ button.addEventListener('click', function(){ activate(button.dataset.tab); }); });
    loadFrame(document.querySelector('.tab-content.active'));
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
