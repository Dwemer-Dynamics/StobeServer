<?php

if (!function_exists('stobeSynthesizeViaCartesia')) {
    function stobeSynthesizeViaCartesia(string $speechText, array &$runtime): string|false {
        $requestedVoiceId = trim(strval($runtime['voiceid'] ?? ''));
        $voiceId = stobeGetOrCreateCartesiaVoiceId($requestedVoiceId, $runtime);
        if ($voiceId === '') {
            return false;
        }
        $runtime['voiceid'] = $voiceId;
        stobeLogInfo('Cartesia TTS request prepared', [
            'requested_voiceid' => $requestedVoiceId,
            'resolved_voiceid' => $voiceId,
            'model_id' => trim(strval($runtime['model_id'] ?? 'sonic-3')),
        ]);

        $apiKey = trim(strval($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            return false;
        }

        $speedRaw = $runtime['connector_config']['speed'] ?? 'normal';
        $speed = is_numeric($speedRaw) ? max(0.5, min(1.5, floatval($speedRaw))) : trim(strval($speedRaw));

        $payload = json_encode([
            'model_id' => trim(strval($runtime['model_id'] ?? 'sonic-3')),
            'transcript' => $speechText,
            'voice' => ['mode' => 'id', 'id' => $voiceId],
            'language' => strtolower(trim(strval($runtime['language'] ?? 'en'))),
            'output_format' => ['container' => 'wav', 'encoding' => 'pcm_s16le', 'sample_rate' => 22050],
            'speed' => $speed,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || $payload === '') {
            return false;
        }

        $ch = curl_init('https://api.cartesia.ai/tts/bytes');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $apiKey,
                'Cartesia-Version: 2024-11-13',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $binary = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        curl_close($ch);
        if (!is_string($binary) || $binary === '' || $httpCode < 200 || $httpCode >= 300) {
            stobeLogWarn('Cartesia synthesis failed', ['http_code' => $httpCode, 'error' => $curlError]);
            return false;
        }
        return $binary;
    }
}

