<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function settingsOrderFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function settingsOrderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        settingsOrderFail($message);
    }
}

$oldGet = $_GET ?? [];
$oldServer = $_SERVER ?? [];

try {
    $_GET = ['embed' => '1'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/StobeServer/ui/settings.php';

    ob_start();
    include __DIR__ . '/../ui/settings.php';
    $html = ob_get_clean();

    settingsOrderAssert(is_string($html) && trim($html) !== '', 'settings page should render html');
    settingsOrderAssert(
        substr_count($html, 'data-group="context-selections"') === 1,
        'context selections section should render exactly once'
    );

    $sectionMatches = [];
    preg_match_all('/<section class="content-section" data-group="([^"]+)">/', $html, $sectionMatches);
    $orderedGroups = $sectionMatches[1] ?? [];

    settingsOrderAssert(count($orderedGroups) > 0, 'settings page should render grouped content sections');

    $worldIndex = array_search('world knowledge', $orderedGroups, true);
    $contextIndex = array_search('context-selections', $orderedGroups, true);

    settingsOrderAssert($worldIndex !== false, 'world knowledge group should render on settings page');
    settingsOrderAssert($contextIndex !== false, 'context selections group should render on settings page');
    settingsOrderAssert(
        intval($contextIndex) === (intval($worldIndex) + 1),
        'context selections should render immediately after world knowledge'
    );

    echo "PASS: settings context order regression\n";
} finally {
    $_GET = $oldGet;
    $_SERVER = $oldServer;
}

