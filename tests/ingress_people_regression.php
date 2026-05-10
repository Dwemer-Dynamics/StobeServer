<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function ingressPeopleFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function ingressPeopleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        ingressPeopleFail($message);
    }
}

function ingressPeopleAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        ingressPeopleFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function ingressPeopleAssertSameList(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        ingressPeopleFail(
            $message
            . ' (expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ', actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ')'
        );
    }
}

function ingressPeopleResetEventlog(): void
{
    $db = $GLOBALS['db'];
    $db->exec('DELETE FROM eventlog');
}

function ingressPeopleSeedNpc(string $name, string $state = '', string $storageId = ''): void
{
    storeNpcProfile($name, []);

    $metadata = [];
    $extended = [];
    if ($state !== '') {
        $metadata['character_state'] = $state;
        $extended['character_state'] = $state;
    }
    if ($storageId !== '') {
        $metadata['storage_id'] = normalizeStorageIdToken($storageId);
    }

    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $extendedJson = json_encode($extended, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($metadataJson) || !is_string($extendedJson)) {
        ingressPeopleFail('failed to encode NPC ingress metadata');
    }

    $db = $GLOBALS['db'];
    $db->exec(
        'UPDATE core_npc_master
         SET metadata = $1::jsonb,
             extended_data = $2::jsonb,
             updated_at = NOW()
         WHERE LOWER(name) = LOWER($3)',
        [$metadataJson, $extendedJson, $name]
    );
}

$normalizedTokens = stobePeopleTokenListFromRaw('["Ruka|100","Beep","Ruka|100"]');
ingressPeopleAssertSameList(
    ['Ruka|hand_100', 'Beep'],
    $normalizedTokens,
    'people token normalization should dedupe repeated identities and normalize numeric storage IDs'
);

ingressPeopleSeedNpc('Ruka', 'sleeping', '100');
ingressPeopleSeedNpc('Beep', 'knocked out', '200');
ingressPeopleSeedNpc('Agnu', 'dead', '300');

$annotatedPeople = stobeAnnotatePeopleTokensWithNpcStates('["Ruka|100","Beep|200","Agnu|300","Burn"]');
ingressPeopleAssertSame(
    '["Ruka (sleeping)|hand_100","Beep (knocked out)|hand_200","Agnu|hand_300","Burn"]',
    $annotatedPeople,
    'people annotation should add awareness tags for sleeping and knocked out NPCs while preserving other participants'
);

ingressPeopleAssertSame(
    'not-json',
    stobeAnnotatePeopleTokensWithNpcStates('not-json'),
    'invalid people payloads should be passed through unchanged'
);

ingressPeopleResetEventlog();
$GLOBALS['CACHE_PEOPLE'] = '["Ruka|100","Beep|200"]';
storeEvent('chat', time(), 777, 'Ruka: I see Beep nearby. (talking to: Beep)');
unset($GLOBALS['CACHE_PEOPLE']);

$recovered = stobeRecoverSparsePeopleForCriticalEvent(
    'death',
    'Ruka: has died near the gate.',
    '["Ruka"]'
);
ingressPeopleAssertSame(
    '["Ruka|hand_100","Beep|hand_200"]',
    $recovered,
    'death ingress should recover sparse people lists from recent matching participant context'
);

$nonDeath = stobeRecoverSparsePeopleForCriticalEvent(
    'chat',
    'Ruka: hello there.',
    '["Ruka"]'
);
ingressPeopleAssertSame(
    '["Ruka"]',
    $nonDeath,
    'non-death ingress should leave people lists unchanged'
);

echo "All ingress people regression tests passed.\n";
