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

// Context version 2 adds registered_npc and player_side; 'player' is the character the player speaks through.
chimRegisterActorProfileEnricher('parity_probe.actor', static function (string $actorName, string $actorType, array $context): string {
    if ($actorType === 'player') {
        $squads = $context['player_side']['squads'] ?? [];
        return 'Parity probe player' . ($squads === [] ? '' : ' in ' . implode(', ', $squads));
    }
    if ($actorType !== 'npc') {
        return '';
    }
    $registered = $context['registered_npc'] ?? null;
    return 'Parity probe sees ' . $actorName . (is_array($registered) ? ' (registered #' . $registered['id'] . ')' : '');
}, 90);

stobeRegisterExtensionAction(
    'ExtCmdParityProbe_Ping',
    'Send a harmless parity ping through the client bridge. Use only when asked to test the parity probe.',
    ['target' => 'optional']
);
