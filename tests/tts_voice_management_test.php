<?php

require_once dirname(__DIR__) . '/lib/tts_voice_management.php';

function expectSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

expectSame('pocket_tts', stobeVoiceProviderNormalize('PocketTTS'), 'normalizes PocketTTS');
expectSame('', stobeVoiceProviderNormalizeId('../voice'), 'rejects traversal');
expectSame('npc.voice-1', stobeVoiceProviderNormalizeId('npc.voice-1.wav'), 'normalizes WAV suffix');

$omni = stobeVoiceProviderTarget([
    'id' => 4,
    'name' => 'Omni',
    'connector_type' => 'omnivoice',
    'base_url' => 'http://localhost:8021/',
    'config' => json_encode(['language' => 'EN']),
]);
expectSame('http://localhost:8021/voices/npc.voice-1?language=en', stobeVoiceProviderDeleteUrl($omni, 'npc.voice-1'), 'builds OmniVoice delete URL');

$chatterboxDefault = stobeVoiceProviderTarget(['connector_type' => 'chatterbox']);
expectSame('http://127.0.0.1:8023', $chatterboxDefault['endpoint'], 'uses dedicated Chatterbox port');
$pocketDefault = stobeVoiceProviderTarget(['connector_type' => 'pocket_tts']);
expectSame('http://127.0.0.1:8024', $pocketDefault['endpoint'], 'uses dedicated Python PocketTTS port');
$xttsDefault = stobeVoiceProviderTarget(['connector_type' => 'xtts']);
expectSame('http://127.0.0.1:8020', $xttsDefault['endpoint'], 'keeps XTTS on port 8020');

$audioCpp = stobeVoiceProviderTarget([
    'connector_type' => 'pocket_tts',
    'base_url' => 'http://127.0.0.1:8086',
]);
expectSame(false, $audioCpp['can_manage'], 'protects audio.cpp local samples');

$cartesia = stobeVoiceProviderTarget([
    'id' => 8,
    'name' => 'Cartesia',
    'connector_type' => 'cartesia',
    'base_url' => '',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN']),
]);
expectSame('cartesia', $cartesia['provider'], 'normalizes Cartesia');
expectSame(true, $cartesia['cloud'], 'marks Cartesia as cloud');
expectSame(true, $cartesia['can_manage'], 'enables configured Cartesia management');

$inworldMissingWorkspace = stobeVoiceProviderTarget([
    'id' => 9,
    'name' => 'Inworld',
    'connector_type' => 'inworld',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN_US']),
]);
expectSame(false, $inworldMissingWorkspace['can_manage'], 'requires Inworld workspace');

$inworld = stobeVoiceProviderTarget([
    'id' => 9,
    'name' => 'Inworld',
    'connector_type' => 'inworld',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN_US', 'workspace' => 'workspace-1']),
]);
expectSame(true, $inworld['can_manage'], 'enables configured Inworld management');
expectSame('not found', stobeVoiceProviderResponseMessage('{"detail":"not found"}', 404), 'extracts API detail');
expectSame(
    'protected_voice: Only custom uploaded voices can be deleted.',
    stobeVoiceProviderResponseMessage('{"detail":{"error":"protected_voice","hint":"Only custom uploaded voices can be deleted."}}', 403),
    'extracts nested FastAPI detail'
);

fwrite(STDOUT, "tts_voice_management_test: OK\n");
