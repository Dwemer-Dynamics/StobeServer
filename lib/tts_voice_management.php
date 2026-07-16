<?php

function stobeVoiceProviderNormalize(string $provider): string
{
    $provider = strtolower(trim($provider));
    return match ($provider) {
        'pockettts', 'pocket-tts', 'pocket_tts' => 'pocket_tts',
        'xtts', 'xtts-fastapi' => 'xtts',
        'chatterbox' => 'chatterbox',
        'omnivoice' => 'omnivoice',
        default => '',
    };
}

function stobeVoiceProviderNormalizeId(string $voiceId): string
{
    $voiceId = preg_replace('/\.wav$/i', '', trim($voiceId)) ?? '';
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $voiceId) === 1 ? $voiceId : '';
}

function stobeVoiceProviderIsAudioCpp(string $provider, string $endpoint): bool
{
    return stobeVoiceProviderNormalize($provider) === 'pocket_tts'
        && (preg_match('/\:8086(?:\/|$)/', $endpoint) === 1 || str_contains($endpoint, '/v1/audio/speech'));
}

function stobeVoiceProviderTarget(array $connector): array
{
    $config = $connector['config'] ?? [];
    if (!is_array($config)) {
        $decoded = json_decode(strval($config), true);
        $config = is_array($decoded) ? $decoded : [];
    }
    $provider = stobeVoiceProviderNormalize(strval($config['provider'] ?? $connector['connector_type'] ?? ''));
    $endpoint = trim(strval($connector['base_url'] ?? ''));
    if ($endpoint === '') {
        $endpoint = trim(strval($config['endpoint'] ?? ''));
    }
    if ($endpoint === '' && $provider !== '') {
        $endpoint = $provider === 'omnivoice' ? 'http://127.0.0.1:8021' : 'http://127.0.0.1:8020';
    }
    return [
        'id' => intval($connector['id'] ?? 0),
        'name' => trim(strval($connector['name'] ?? '')),
        'provider' => $provider,
        'endpoint' => rtrim($endpoint, '/'),
        'language' => strtolower(trim(strval($config['language'] ?? ''))),
        'can_manage' => $provider !== '' && $endpoint !== '' && !stobeVoiceProviderIsAudioCpp($provider, $endpoint),
    ];
}

function stobeVoiceProviderDeleteUrl(array $target, string $voiceId): string
{
    $voiceId = stobeVoiceProviderNormalizeId($voiceId);
    if ($voiceId === '' || empty($target['endpoint'])) {
        return '';
    }
    $url = rtrim(strval($target['endpoint']), '/') . '/voices/' . rawurlencode($voiceId);
    if (($target['provider'] ?? '') === 'omnivoice') {
        $language = strtolower(trim(strval($target['language'] ?? '')));
        if ($language !== '') {
            $url .= '?language=' . rawurlencode($language);
        }
    }
    return $url;
}

function stobeVoiceProviderResponseMessage(string $response, int $httpCode): string
{
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        $messageValue = $decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? '';
        if (is_array($messageValue)) {
            $message = implode(': ', array_filter([
                trim(strval($messageValue['error'] ?? '')),
                trim(strval($messageValue['reason'] ?? '')),
                trim(strval($messageValue['hint'] ?? '')),
            ]));
        } else {
            $message = trim(strval($messageValue));
        }
        if ($message !== '') {
            return $message;
        }
    }
    $response = trim($response);
    return $response !== '' ? $response : 'Provider returned HTTP ' . $httpCode . '.';
}

function stobeVoiceProviderDelete(array $target, string $voiceId): array
{
    if (empty($target['can_manage'])) {
        return ['success' => false, 'message' => 'This connector uses the local WAV directly and has no uploaded provider copy to remove.'];
    }
    $url = stobeVoiceProviderDeleteUrl($target, $voiceId);
    if ($url === '') {
        return ['success' => false, 'message' => 'Invalid voice ID or connector endpoint.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['success' => false, 'message' => 'Delete request failed: ' . $error];
    }
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'message' => stobeVoiceProviderResponseMessage($response, $httpCode),
    ];
}

function stobeVoiceProviderUpload(array $target, string $voiceId, string $samplePath): array
{
    $voiceId = stobeVoiceProviderNormalizeId($voiceId);
    if (empty($target['can_manage'])) {
        return ['success' => false, 'message' => 'This connector uses the local WAV directly; replacing the local sample is sufficient.'];
    }
    if ($voiceId === '' || !is_file($samplePath) || !is_readable($samplePath)) {
        return ['success' => false, 'message' => 'A valid voice ID and readable local audio sample are required.'];
    }

    $uploadPath = $samplePath;
    $tempConvertedPath = '';
    $header = @file_get_contents($samplePath, false, null, 0, 12);
    $isRiffWav = is_string($header)
        && strlen($header) >= 12
        && substr($header, 0, 4) === 'RIFF'
        && substr($header, 8, 4) === 'WAVE';
    if (!$isRiffWav) {
        $ffmpegPath = trim(strval(@shell_exec('command -v ffmpeg 2>/dev/null')));
        if ($ffmpegPath === '') {
            return ['success' => false, 'message' => 'ffmpeg is required to convert this sample to WAV.'];
        }
        $tempBase = tempnam(sys_get_temp_dir(), 'stobe_voice_provider_');
        if (!is_string($tempBase) || $tempBase === '') {
            return ['success' => false, 'message' => 'Could not allocate a temporary WAV file.'];
        }
        @unlink($tempBase);
        $tempConvertedPath = $tempBase . '.wav';
        $command = escapeshellarg($ffmpegPath)
            . ' -y -i ' . escapeshellarg($samplePath)
            . ' -ac 1 -ar 22050 -f wav ' . escapeshellarg($tempConvertedPath)
            . ' >/dev/null 2>&1';
        $exitCode = 1;
        @exec($command, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($tempConvertedPath) || intval(@filesize($tempConvertedPath)) <= 44) {
            @unlink($tempConvertedPath);
            return ['success' => false, 'message' => 'Failed to convert the local sample to WAV.'];
        }
        $uploadPath = $tempConvertedPath;
    }

    $postFields = [
        'wavFile' => new CURLFile($uploadPath, 'audio/wav', $voiceId . '.wav'),
        'force' => 'true',
        'speaker_name' => $voiceId,
        'speaker_id' => $voiceId,
    ];
    if (($target['provider'] ?? '') === 'omnivoice') {
        $postFields['language'] = strval($target['language'] ?? '');
        $postFields['display_name'] = $voiceId;
    }

    $ch = curl_init(rtrim(strval($target['endpoint']), '/') . '/upload_sample');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'Content-Type: multipart/form-data'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) {
        @unlink($tempConvertedPath);
    }
    if ($response === false) {
        return ['success' => false, 'message' => 'Upload request failed: ' . $error];
    }
    $success = $httpCode >= 200 && $httpCode < 300;
    if ($success && ($target['provider'] ?? '') === 'omnivoice') {
        $decoded = json_decode($response, true);
        $status = is_array($decoded)
            ? strtolower(trim(strval($decoded['import_status'] ?? $decoded['status'] ?? '')))
            : '';
        $success = in_array($status, ['runtime_ready', 'ready', 'ok'], true);
        if (!$success && is_array($decoded)) {
            $transcriptionError = trim(strval($decoded['transcription_error'] ?? ''));
            if ($transcriptionError !== '') {
                return ['success' => false, 'message' => $transcriptionError];
            }
        }
    }
    return [
        'success' => $success,
        'message' => stobeVoiceProviderResponseMessage($response, $httpCode),
    ];
}
