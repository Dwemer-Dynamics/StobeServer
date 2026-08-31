<?php

// Build saved connector and installed voice choices for pronunciation previews.
function stobeTtsPronunciationPreviewOptions(): array
{
    $connectors = [];
    foreach (getAllTtsConnectors() as $row) {
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $connectors[] = [
            'id' => $id,
            'label' => trim(strval($row['name'] ?? '')),
            'driver' => stobeNormalizeTtsConnectorType(strval($row['connector_type'] ?? '')),
        ];
    }

    $voices = [];
    $db = $GLOBALS['db'] ?? null;
    if ($db) {
        $voiceRows = $db->fetchAll(
            "SELECT voiceid
             FROM combined_core_voiceid
             WHERE COALESCE(BTRIM(voiceid), '') <> ''
             ORDER BY LOWER(voiceid)
             LIMIT 4096"
        );
        foreach (is_array($voiceRows) ? $voiceRows : [] as $row) {
            $voice = trim(strval($row['voiceid'] ?? ''));
            if ($voice !== '') {
                $voices[strtolower($voice)] = $voice;
            }
        }
    }
    natcasesort($voices);
    $voices = array_values($voices);

    $defaultConnector = getDefaultTtsConnector();
    $defaultConnectorId = intval(is_array($defaultConnector) ? ($defaultConnector['id'] ?? 0) : 0);
    $availableConnectorIds = array_map(
        static fn(array $row): int => intval($row['id'] ?? 0),
        $connectors
    );
    if (!in_array($defaultConnectorId, $availableConnectorIds, true)) {
        $defaultConnectorId = intval($connectors[0]['id'] ?? 0);
    }

    $narratorName = function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator';
    $configuredVoice = stobeResolveNpcVoiceIdByName($narratorName);
    $defaultVoice = '';
    foreach ($voices as $voice) {
        if ($configuredVoice !== '' && strcasecmp($voice, $configuredVoice) === 0) {
            $defaultVoice = $voice;
            break;
        }
    }
    if ($defaultVoice === '' && !empty($voices)) {
        $defaultVoice = strval($voices[0]);
    }

    return [
        'connectors' => $connectors,
        'voices' => $voices,
        'default_connector_id' => $defaultConnectorId,
        'default_voice' => $defaultVoice,
    ];
}
