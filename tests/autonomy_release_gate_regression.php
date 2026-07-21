<?php

require_once dirname(__DIR__) . '/lib/autonomy_release_gate.php';

function releaseGateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

releaseGateAssert(!stobeAutonomyReleaseEnabled(), 'autonomy must remain disabled for the beta release');

$root = dirname(__DIR__);
foreach (['autonomy_control.php', 'autonomy_tick.php', 'autonomy_observation.php', 'autonomy_pilot.php'] as $endpoint) {
    $source = file_get_contents($root . '/' . $endpoint);
    releaseGateAssert(
        is_string($source) && str_contains($source, 'stobeAutonomyRejectForRelease();'),
        "{$endpoint} must enforce the release gate"
    );
}

$stateSource = file_get_contents($root . '/autonomy_state.php');
releaseGateAssert(
    is_string($stateSource) && str_contains($stateSource, 'stobeAutonomyDisableForRelease();'),
    'autonomy_state.php must force persisted autonomy off'
);

foreach (['ui/events-memories.php', 'ui/stobenpcs.php', 'ui/tmpl/navbar.php'] as $uiFile) {
    $source = file_get_contents($root . '/' . $uiFile);
    releaseGateAssert(
        is_string($source) && !str_contains($source, 'autonomy.php'),
        "{$uiFile} must not expose the autonomy UI"
    );
}

echo "PASS: autonomy beta release gate regression\n";
