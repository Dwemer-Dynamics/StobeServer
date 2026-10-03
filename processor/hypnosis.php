<?php

// Keep explicit profile rewrites outside dialogue, action and speech processing.
function stobeRunHypnosis(string $instruction, string $target, string $storageId, string $speaker): void
{
    $notify = static function (string $message): void {
        echo 'rolemaster|HypnosisStatus|' . str_replace(["\r", "\n", '|'], ' ', $message) . "\r\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    };
    $target = normalizeParticipantNameToken($target);
    $storageId = normalizeStorageIdToken($storageId);
    if (trim($instruction) === '' || strlen($instruction) > 8192 || $target === '' || $storageId === ''
        || in_array(strtolower($target), ['the narrator', 'everyone', 'all'], true)
        || strcasecmp($target, normalizeParticipantNameToken($speaker)) === 0) {
        $notify('Hypnosis needs one NPC target and an instruction.');
        return;
    }
    $db = $GLOBALS['db'];
    $npc = getNpcData($target);
    $metadata = normalizeCoreNpcMetadata($npc['metadata'] ?? []);
    if (!$npc || normalizeStorageIdToken((string)($metadata['storage_id'] ?? '')) !== $storageId) {
        $notify('Hypnosis target changed or has no saved profile. Select the NPC again.');
        return;
    }
    $fields = ['personality', 'goals', 'speechstyle', 'occupation'];
    try {
        require_once __DIR__ . '/../connector/llm_dispatcher.php';
        require_once __DIR__ . '/../lib/dynamic_profile_helper_functions.php';
        $config = getLlmConfigForNpcPurpose($npc, 'dynamic');
        if (trim((string)($config['model'] ?? '')) === '') {
            $notify('Configure the dynamic-profile connector before using Hypnosis.');
            return;
        }
        $notify('Hypnosis started for ' . $npc['name'] . '.');
        $messages = [
            ['role' => 'system', 'content' => 'Rewrite a Kenshi NPC profile to follow the player instruction. Return only a JSON object with four nonempty string fields: personality, goals, speechstyle, occupation. Keep Kenshi lore and the character identity. This changes the written profile only, not game factions, inventory or actions.'],
            ['role' => 'user', 'content' => json_encode([
                'name' => $npc['name'], 'race' => $npc['race'] ?? '',
                'current_profile' => array_intersect_key($npc, array_flip($fields)),
                'instruction' => $instruction,
            ], JSON_THROW_ON_ERROR)],
        ];
        $raw = stobeCallLLM($messages, $config, [
            'npc_name' => $npc['name'], 'event_type' => 'hypnosis',
            'response_format' => ['type' => 'json_object'],
        ]);
        $updates = is_string($raw) ? stobeDynamicProfileDecodeJsonObject($raw) : [];
        if (count($updates) !== count($fields)) throw new RuntimeException('Incomplete hypnosis profile');
        $sets = []; $where = ['id=$1', "metadata->>'storage_id'=$2", 'name=$3'];
        $values = [(int)$npc['id'], (string)$metadata['storage_id'], $npc['name']];
        foreach ($fields as $field) {
            if (!isset($updates[$field]) || !is_string($updates[$field]) || trim($updates[$field]) === '') {
                throw new RuntimeException('Invalid hypnosis profile');
            }
            $values[] = trim($updates[$field]);
            $sets[] = $field . '=$' . count($values);
            $values[] = $npc[$field] ?? null;
            $where[] = $field . ' IS NOT DISTINCT FROM $' . count($values);
        }
        // Patch this NPC only; never update its shared/player-faction core profile.
        $saved = $db->fetchOne('UPDATE core_npc_master SET ' . implode(',', $sets)
            . ' WHERE ' . implode(' AND ', $where) . ' RETURNING id', $values);
        $notify($saved ? 'Updated basic profile for ' . $npc['name'] . '.' : 'Hypnosis could not save. The profile changed while generating.');
    } catch (Throwable $error) {
        stobeLogWarn('Hypnosis generation or save failed', ['error_type' => get_class($error)]);
        $notify('Hypnosis could not update the profile. Existing fields were kept.');
    }
}
