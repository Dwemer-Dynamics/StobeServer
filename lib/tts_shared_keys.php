<?php

// Keep voice selection metadata-only; resolve shared credentials at the final HTTP boundary.
function stobeTtsSharedKeyId(array $connector, array $config, string $provider): string {
    if (!empty($connector['shared_key_id'])) {
        return (string)$connector['shared_key_id'];
    }
    foreach (['api_key', 'apiKey', 'apikey', 'credential', 'api_credential'] as $field) {
        if (trim(strval($config[$field] ?? '')) !== '') {
            return '';
        }
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return '';
    }
    $badgeId = intval($config['api_badge_id'] ?? 0);
    if ($badgeId > 0) {
        $badge = $db->fetchOne("SELECT to_jsonb(b)->>'shared_key_id' AS shared_key_id, COALESCE(api_key,'')<>'' AS configured FROM core_api_badge b WHERE id=$1", [$badgeId]);
        if (!empty($badge['shared_key_id'])) {
            return (string)$badge['shared_key_id'];
        }
        if (in_array($badge['configured'] ?? false, [true, 't', '1', 1], true)) {
            return '';
        }
    }
    $label = match ($provider) { 'inworld' => 'Inworld', 'cartesia' => 'Cartesia', default => '' };
    if ($label === '') {
        return '';
    }
    $badges = $db->fetchAll("SELECT to_jsonb(b)->>'shared_key_id' AS shared_key_id FROM core_api_badge b WHERE LOWER(label)=LOWER($1)", [$label]);
    foreach ($badges as $badge) {
        if (!empty($badge['shared_key_id'])) {
            if (count($badges) !== 1) {
                throw new RuntimeException('Ambiguous voice credential; select a saved badge explicitly.');
            }
            return (string)$badge['shared_key_id'];
        }
    }
    return '';
}

// A bound voice credential must never fall back to its retained legacy copy.
function stobeTtsCredential(array $runtime, string $endpoint): string {
    $keyId = strval($runtime['shared_key_id'] ?? '');
    if ($keyId === '') {
        return trim(strval($runtime['api_key'] ?? ''));
    }
    if (($runtime['provider'] ?? '') !== 'inworld') {
        throw new RuntimeException('This voice provider does not support shared credentials.');
    }
    require_once '/usr/local/lib/dwemerdistro/shared_keys.php';
    return DistroSharedKeys::resolve($keyId, 'tts', $endpoint);
}
