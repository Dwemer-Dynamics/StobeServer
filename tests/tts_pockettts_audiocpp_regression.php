<?php

function stobeLogWarn(string $message, array $context = []): void
{
}

function stobeLogInfo(string $message, array $context = []): void
{
}

require_once dirname(__DIR__) . '/tts/tts-pockettts.php';

function expectPocketTtsSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stobe_pockettts_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true)) {
    fwrite(STDERR, "Unable to create Pocket TTS test directory\n");
    exit(1);
}

$wavPath = $tempDir . DIRECTORY_SEPARATOR . 'valid.wav';
$invalidPath = $tempDir . DIRECTORY_SEPARATOR . 'invalid.mp3';
file_put_contents($wavPath, 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\0", 28));
file_put_contents($invalidPath, 'not a wave file');

expectPocketTtsSame(true, stobePocketTtsIsAudioCpp('http://127.0.0.1:8086'), 'detects audio.cpp port');
expectPocketTtsSame(true, stobeIsRiffWavFile($wavPath), 'accepts RIFF WAV sample');
expectPocketTtsSame(false, stobeIsRiffWavFile($invalidPath), 'rejects non-WAV sample');
expectPocketTtsSame(['voice_ref' => $wavPath], stobePocketTtsAudioCppVoicePayload($wavPath), 'passes valid WAV reference');
expectPocketTtsSame(['voice' => 'alba'], stobePocketTtsAudioCppVoicePayload($invalidPath), 'never passes invalid reference');

@unlink($wavPath);
@unlink($invalidPath);
@rmdir($tempDir);

fwrite(STDOUT, "tts_pockettts_audiocpp_regression: OK\n");
