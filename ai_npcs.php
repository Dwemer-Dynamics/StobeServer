<?php

/**
 * In-game AI NPC browser endpoint.
 *
 * POST JSON:
 *   {"action":"list"}
 *   {"action":"detail","sid":"<storage_id|id:123|name>"}
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$action = strtolower(trim(strval($payload['action'] ?? 'list')));
$db = $GLOBALS["db"];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

function stobeAiNpcFieldValue(array $row, string $key, string $fallback = 'Unknown'): string {
    $value = trim(strval($row[$key] ?? ''));
    return $value === '' ? $fallback : $value;
}

function stobeAiNpcRelationshipSummary(mixed $rawRelationships): string {
    $relationshipMap = stobeNormalizeRelationshipMap($rawRelationships);
    if (count($relationshipMap) === 0) {
        return 'No relationship data.';
    }

    $entries = [];
    foreach ($relationshipMap as $targetName => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $target = normalizeParticipantNameToken(strval($targetName));
        if ($target === '') {
            continue;
        }
        $aff = intval($entry['aff'] ?? 0);
        $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
        $tier = stobeRelationshipTierLabel($aff);
        $entries[] = [
            'target' => $target,
            'aff' => $aff,
            'type' => $type,
            'tier' => $tier,
        ];
    }

    if (count($entries) === 0) {
        return 'No relationship data.';
    }

    usort($entries, static function (array $a, array $b): int {
        if ($a['aff'] === $b['aff']) {
            return strcasecmp(strval($a['target']), strval($b['target']));
        }
        return intval($b['aff']) <=> intval($a['aff']);
    });

    $lines = [];
    $max = min(count($entries), 12);
    for ($i = 0; $i < $max; $i++) {
        $entry = $entries[$i];
        $lines[] = '- ' . strval($entry['target']) . ': '
            . strval($entry['tier']) . ' (' . strval($entry['type'])
            . ', aff ' . strval(intval($entry['aff'])) . ')';
    }
    return implode("\n", $lines);
}

function stobeAiNpcResolveProfileLabel(array $row): string {
    $db = $GLOBALS["db"];
    $profileId = intval($row['profile_id'] ?? 0);
    $attached = $profileId > 0;

    if (!$attached) {
        $profileId = getDefaultNpcProfileId();
    }

    if ($profileId <= 0) {
        return $attached ? ('Profile #' . strval(intval($row['profile_id'] ?? 0))) : 'Unassigned';
    }

    $profileRow = $db->fetchOne(
        "SELECT label
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$profileId]
    );
    $label = trim(strval($profileRow['label'] ?? ''));
    if ($label === '') {
        return $attached ? ('Profile #' . strval($profileId)) : 'Default Profile';
    }

    if ($attached) {
        return $label;
    }
    return $label . ' (inherited default)';
}

function stobeAiNpcResolveVoiceId(array $row): string {
    $voiceId = trim(strval($row['voiceid'] ?? ''));
    if ($voiceId !== '') {
        return $voiceId;
    }

    $name = trim(strval($row['name'] ?? ''));
    if ($name === '') {
        return 'Unassigned';
    }

    $resolved = stobeSelectVoiceIdForNpc(
        $name,
        trim(strval($row['race'] ?? '')),
        trim(strval($row['gender'] ?? '')),
        trim(strval($row['faction'] ?? '')),
        $row
    );
    if ($resolved !== '') {
        return $resolved . ' (auto)';
    }
    return 'Unassigned';
}

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT
            id,
            name,
            COALESCE(metadata->>'storage_id', '') AS storage_id,
            gamets_last_updated,
            updated_at
         FROM core_npc
         WHERE COALESCE(BTRIM(name), '') <> ''
         ORDER BY LOWER(name) ASC, gamets_last_updated DESC, updated_at DESC, id DESC"
    );

    $nameCounts = [];
    foreach ($rows as $row) {
        $name = trim(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        $nameCounts[$key] = intval($nameCounts[$key] ?? 0) + 1;
    }

    $entries = [];
    foreach ($rows as $row) {
        $name = trim(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $storageId = normalizeStorageIdToken(strval($row['storage_id'] ?? ''));
        $sid = $storageId !== '' ? $storageId : ('id:' . strval(intval($row['id'] ?? 0)));
        if ($sid === 'id:0') {
            continue;
        }

        $display = $name;
        $nameKey = strtolower($name);
        if (intval($nameCounts[$nameKey] ?? 0) > 1) {
            $suffix = $storageId !== '' ? $storageId : ('id:' . strval(intval($row['id'] ?? 0)));
            if (strlen($suffix) > 24) {
                $suffix = substr($suffix, 0, 24) . '...';
            }
            $display .= ' [' . $suffix . ']';
        }

        $entries[] = $display . "|" . $sid;
    }

    echo json_encode(
        [
            'ok' => true,
            'count' => count($entries),
            'names' => $entries,
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'detail') {
    $sid = trim(strval($payload['sid'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing sid']);
        return;
    }

    $row = false;
    if (strtolower(substr($sid, 0, 3)) === 'id:') {
        $id = intval(substr($sid, 3));
        if ($id > 0) {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE id = $1
                 LIMIT 1",
                [$id]
            );
        }
    } else {
        $normalizedSid = normalizeStorageIdToken($sid);
        if ($normalizedSid !== '') {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE COALESCE(metadata->>'storage_id', '') = $1
                 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [$normalizedSid]
            );
        }
        if (!$row) {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE LOWER(name) = LOWER($1)
                    OR LOWER(COALESCE(original_name, '')) = LOWER($1)
                 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [$sid]
            );
        }
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'NPC not found']);
        return;
    }

    $relationshipsRaw = trim(strval($row['relationships'] ?? ''));
    $relationshipMap = stobeNormalizeRelationshipMap($relationshipsRaw);
    if (count($relationshipMap) > 0) {
        $relationships = stobeAiNpcRelationshipSummary($relationshipMap);
    } else {
        $relationships = $relationshipsRaw;
    }

    $name = stobeAiNpcFieldValue($row, 'name');
    $originalName = trim(strval($row['original_name'] ?? ''));
    if ($originalName === '') {
        $originalName = '(none)';
    }
    $profileLabel = stobeAiNpcResolveProfileLabel($row);
    $voiceIdLabel = stobeAiNpcResolveVoiceId($row);

    $lines = [];
    $lines[] = "Name: " . $name;
    $lines[] = "Original Name: " . $originalName;
    $lines[] = "Current Profile: " . $profileLabel;
    $lines[] = "Voice ID: " . $voiceIdLabel;
    $lines[] = "";
    $lines[] = "Backstory:";
    $lines[] = stobeAiNpcFieldValue($row, 'backstory', '(empty)');
    $lines[] = "";
    $lines[] = "Personality:";
    $lines[] = stobeAiNpcFieldValue($row, 'personality', '(empty)');
    $lines[] = "";
    $lines[] = "Speech Style:";
    $lines[] = stobeAiNpcFieldValue($row, 'speechstyle', '(empty)');
    $lines[] = "";
    $lines[] = "Occupation:";
    $lines[] = stobeAiNpcFieldValue($row, 'occupation', '(empty)');
    $lines[] = "";
    $lines[] = "Appearance:";
    $lines[] = stobeAiNpcFieldValue($row, 'appearance', '(empty)');
    $lines[] = "";
    $lines[] = "Goals:";
    $lines[] = stobeAiNpcFieldValue($row, 'goals', '(empty)');
    $lines[] = "";
    $lines[] = "Relationships:";
    $lines[] = $relationships === '' ? 'No relationship data.' : $relationships;
    $lines[] = "";
    $lines[] = "Game TS Last Updated: " . strval(intval($row['gamets_last_updated'] ?? 0));
    $lines[] = "Updated At: " . trim(strval($row['updated_at'] ?? ''));

    echo json_encode(
        [
            'ok' => true,
            'text' => implode("\n", $lines),
        ],
        $jsonFlags
    );
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

