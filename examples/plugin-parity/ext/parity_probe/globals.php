<?php

// Plugin parity example. Registration only: no provider, database or file writes.
$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe:globals';

chimRegisterPromptInjection(
    'character_bottom',
    'parity_probe.marker',
    '<parity_probe>The parity probe extension is loaded.</parity_probe>',
    90
);

chimRegisterPromptInjection('prompt_bottom', 'parity_probe.footer', static function (string $slot, array $context): string {
    $speaker = trim(strval($context['herika_name'] ?? ''));
    return $speaker === '' ? '' : '<parity_probe_footer>Prompt built for ' . $speaker . '.</parity_probe_footer>';
});

chimRegisterActorProfileEnricher('parity_probe.actor', static function (string $actorName, string $actorType, array $context): string {
    return $actorType === 'npc' ? 'Parity probe sees ' . $actorName : '';
}, 90);

stobeRegisterExtensionAction(
    'ExtCmdParityProbe_Ping',
    'Send a harmless parity ping through the client bridge. Use only when asked to test the parity probe.',
    ['target' => 'optional']
);
