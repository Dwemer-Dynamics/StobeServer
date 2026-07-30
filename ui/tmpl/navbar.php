<?php
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$webRoot = dirname(dirname($scriptPath));
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$topNavSection = in_array($currentPage, ['home.php', 'index.php', 'stobe.php'], true) ? 'home' : '';
$roleplayPages = [
    'events-memories.php', 'events.php', 'memories.php', 'ai-response.php',
    'adventurelog.php', 'diarylog.php', 'relationship_logs.php',
];
$configurationPages = [
    'config_hub.php', 'stobenpcs.php', 'npc_master.php', 'profiles.php',
    'narrator_management.php', 'llm_connectors.php', 'tts_connectors.php',
    'api_badges.php', 'api_key.php', 'settings.php', 'description.php',
    'npc_bios.php', 'action_editor.php', 'prompts_manager.php',
    'voice_manager.php', 'world_knowledge.php', 'world_knowledge_rules.php', 'quickstart.php',
];
$controlPanelPages = [
    'controlpanel_hub.php', 'logs.php', 'request_logs.php', 'audit.php',
    'world_knowledge_audit.php', 'response_queue.php', 'database_manager.php',
    'playthrough_manager.php', 'llmtest.php', 'ttstest.php',
];

if (in_array($currentPage, $roleplayPages, true)) {
    $topNavSection = 'roleplay';
} elseif (in_array($currentPage, $configurationPages, true)) {
    $topNavSection = 'configuration';
} elseif (in_array($currentPage, $controlPanelPages, true)) {
    $topNavSection = 'control';
}

$menuItems = [
    ['label' => 'Home', 'href' => $webRoot . '/ui/home.php', 'section' => 'home'],
    ['label' => 'Roleplay', 'href' => $webRoot . '/ui/events-memories.php', 'section' => 'roleplay'],
    ['label' => 'Configuration', 'href' => $webRoot . '/ui/config_hub.php', 'section' => 'configuration'],
    ['label' => 'Control Panel', 'href' => $webRoot . '/ui/controlpanel_hub.php', 'section' => 'control'],
    ['label' => 'DwemerDistro Home', 'href' => '/Dwemer-Dashboard/index.php', 'section' => ''],
];

$navbarCssPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'navbar.css';
$navbarCssVersion = file_exists($navbarCssPath) ? strval(filemtime($navbarCssPath)) : strval(time());
echo '<link rel="stylesheet" href="' . $webRoot . '/ui/css/navbar.css?v=' . rawurlencode($navbarCssVersion) . '">';
$themeCssPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'stobe-theme.css';
$themeCssVersion = file_exists($themeCssPath) ? strval(filemtime($themeCssPath)) : strval(time());
echo '<link rel="stylesheet" href="' . $webRoot . '/ui/css/stobe-theme.css?v=' . rawurlencode($themeCssVersion) . '">';
echo '<style>
.stobe-navbar .container-fluid {
    display: flex !important;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}

.stobe-navbar {
    height: 64px;
}
.stobe-navbar .container-fluid > * {
    align-items: center;
}
.stobe-navbar .navbar-brand,
.stobe-navbar .navbar-center button.navbar-brand {
    padding: 0;
    line-height: 1;
}

.navbar-left,
.navbar-right,
.stobe-navbar .nav-item.mx-2,
.stobe-navbar .nav-item.dropdown.mx-2 {
    display: none !important;
}

.server-version-info {
    display: flex;
    align-items: center;
    color: #6c757d;
    font-size: 0.75em;
    font-family: Exo2, Arial, sans-serif;
    width: 120px;
    flex-shrink: 0;
}

.navbar-content-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    flex: 1;
    max-width: 1000px;
    margin: 0 auto;
}

.social-links {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 120px;
    flex-shrink: 0;
    justify-content: flex-end;
}

.social-link img {
    width: 24px;
    height: 24px;
    transition: transform 0.3s ease;
}

.social-link:hover img {
    transform: scale(1.1);
}

.navbar-left {
    display: flex;
    flex: 0 0 auto;
    justify-content: flex-end;
    margin: 0 15px 0 0 !important;
}

.navbar-center {
    display: flex;
    justify-content: center;
    flex: 0 0 auto;
    margin: 0 20px;
}

.navbar-right {
    display: flex;
    flex: 0 0 auto;
    justify-content: flex-start;
    margin: 0 0 0 15px !important;
}

.navbar-center .navbar-brand {
    margin: 0;
    padding: 0;
}

.nav-item.dropdown .dropdown-menu {
    min-width: 280px;
}

/* Hard override so Stobe navbar matches requested visuals */
.stobe-navbar .navbar-center .navbar-brand img:hover {
    filter: drop-shadow(0 0 8px rgba(220, 220, 220, 0.7)) !important;
}
.stobe-navbar .navbar-center .navbar-brand img:active {
    filter: drop-shadow(0 0 12px rgba(220, 220, 220, 0.85)) !important;
}
/* Ensure stobesmall icon never shifts to a warm/orange hue on hover */
.stobe-navbar .navbar-center .navbar-brand .stobe-mark {
    filter: brightness(1) drop-shadow(0 0 0 transparent) !important;
}
.stobe-navbar .navbar-center .navbar-brand:hover .stobe-mark {
    filter: drop-shadow(0 0 8px rgba(220, 220, 220, 0.7)) !important;
}
.stobe-navbar .navbar-center .navbar-brand:active .stobe-mark {
    filter: drop-shadow(0 0 12px rgba(220, 220, 220, 0.85)) !important;
}
.stobe-navbar .navbar-center .dropdown-toggle {
    color: #ffffff !important;
}
.stobe-navbar .navbar-center .dropdown-toggle::after {
    content: "" !important;
    display: inline-block !important;
    width: 0 !important;
    height: 0 !important;
    margin-left: 10px !important;
    vertical-align: middle !important;
    border-top: 0.35em solid #ffffff !important;
    border-right: 0.35em solid transparent !important;
    border-left: 0.35em solid transparent !important;
    border-bottom: 0 !important;
    opacity: 1 !important;
}

@media (max-width: 992px) {
    .container-fluid {
        flex-direction: column;
        gap: 10px;
        align-items: center;
    }

    .server-version-info,
    .social-links {
        order: 2;
        width: auto;
    }

    .navbar-content-wrapper {
        flex-direction: column;
        gap: 10px;
        order: 1;
    }

    .navbar-left,
    .navbar-right {
        justify-content: center;
        flex: none;
        margin: 0 !important;
    }

    .navbar-center {
        order: -1;
        margin: 0;
    }

    .dropdown-menu {
        left: 50%;
        transform: translateX(-50%);
    }
}
</style>';
?>

<div class="stobe-navbar-wrapper">
    <nav class="navbar navbar-expand-lg stobe-navbar">
        <div class="container-fluid mx-1">
            <div class="navbar-content-wrapper">
                <div class="navbar-center dropdown">
                    <button
                        class="navbar-brand Title btn btn-link p-0 dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="true"
                        data-bs-display="static"
                        aria-expanded="false"
                        title="Open Stobe menu"
                        style="text-decoration: none;"
                    >
                        <img class="stobe-mark" src="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/images/stobesmall.png" alt="Stobe" style="vertical-align:bottom;">
                        <img class="stobe-wordmark" src="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/images/ServerLogo.png" alt="StobeServer" style="vertical-align:bottom;">
                    </button>
                    <ul class="dropdown-menu brand-menu">
                        <?php foreach ($menuItems as $item): ?>
                            <li>
                                <?php $isActive = $item['section'] !== '' && $topNavSection === $item['section']; ?>
                                <?php $isRealLink = (strval($item['href']) !== '' && strval($item['href']) !== '#'); ?>
                                <a class="dropdown-item<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isRealLink ? ' target="_top"' : '' ?><?= $isActive ? ' aria-current="page"' : '' ?>>
                                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
</div>
