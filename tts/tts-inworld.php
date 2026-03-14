<?php

if (!function_exists('stobeSynthesizeViaInworld')) {
    function stobeSynthesizeViaInworld(string $speechText, array &$runtime): string|false {
        $requestedVoiceId = trim(strval($runtime['voiceid'] ?? ''));
        $voiceId = stobeGetOrCreateInworldVoiceId($requestedVoiceId, $runtime);
        if ($voiceId === '') {
            return false;
        }
        $runtime['voiceid'] = $voiceId;
        stobeLogInfo('Inworld TTS request prepared', [
            'requested_voiceid' => $requestedVoiceId,
            'resolved_voiceid' => $voiceId,
            'model_id' => trim(strval($runtime['model_id'] ?? 'inworld-tts-1')),
        ]);

        $apiCredential = trim(strval($runtime['api_key'] ?? ''));
        if ($apiCredential === '') {
            return false;
        }

        $speed = max(0.5, min(1.5, floatval($runtime['connector_config']['speed'] ?? 1.0)));
        $temperature = max(0.0, min(2.0, floatval($runtime['connector_config']['temperature'] ?? 1.1)));
        $payload = json_encode([
            'text' => $speechText,
            'voiceId' => $voiceId,
            'modelId' => trim(strval($runtime['model_id'] ?? 'inworld-tts-1')),
            'language' => stobeMapLanguageToInworld(strval($runtime['language'] ?? 'en')),
            'audioConfig' => ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 22050, 'speakingRate' => $speed],
            'temperature' => $temperature,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || $payload === '') {
            return false;
        }

        $ch = curl_init('https://api.inworld.ai/tts/v1/voice:stream');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $apiCredential, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        curl_close($ch);
        if (!is_string($response) || $response === '' || $httpCode < 200 || $httpCode >= 300) {
            stobeLogWarn('Inworld synthesis failed', ['http_code' => $httpCode, 'error' => $curlError]);
            return false;
        }
        if (substr($response, 0, 4) === 'RIFF') {
            return $response;
        }

        $pcm = '';
        $lines = preg_split('/\r\n|\r|\n/', $response);
        if (!is_array($lines)) {
            $lines = [];
        }
        foreach ($lines as $lineRaw) {
            $line = trim(strval($lineRaw));
            if ($line === '' || str_starts_with($line, 'event:')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line === '' || $line === '[DONE]') {
                continue;
            }
            $chunk = json_decode($line, true);
            if (!is_array($chunk)) {
                continue;
            }
            $chunkData = strval($chunk['result']['audioContent'] ?? '');
            if ($chunkData === '') {
                continue;
            }
            $chunkAudio = base64_decode($chunkData, true);
            if (!is_string($chunkAudio) || $chunkAudio === '') {
                continue;
            }
            if (strlen($chunkAudio) > 44 && substr($chunkAudio, 0, 4) === 'RIFF') {
                $chunkAudio = substr($chunkAudio, 44);
            }
            $pcm .= $chunkAudio;
        }
        if ($pcm === '') {
            return false;
        }
        return stobeBuildWavFromPcm16($pcm, 22050, 1);
    }
}

