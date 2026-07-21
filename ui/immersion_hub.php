<?php
// Compatibility endpoint retained for bookmarks from older StobeServer builds.
$legacyTab = strtolower(trim((string)($_GET['tab'] ?? 'adventure_log')));
$tabMap = [
    'adventure_log' => 'adventure',
    'diary_log' => 'diaries',
];

$query = $_GET;
$query['tab'] = $tabMap[$legacyTab] ?? 'adventure';

header('Location: events-memories.php?' . http_build_query($query), true, 302);
exit;
