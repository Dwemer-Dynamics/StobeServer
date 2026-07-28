<?php

/**
 * In-game AI diary browser endpoint.
 *
 * POST JSON:
 *   {"action":"list"}
 *   {"action":"detail","sid":"<npc_name>"}
 *   {"action":"entries","sid":"<npc_name>"}
 *   {"action":"entry","rowid":123}
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");
require_once($path . "lib/utils_game_timestamp.php");

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

function stobeDiaryListToken(string $value): string {
    return trim(preg_replace('/[\x00-\x1F,\|]+/u', ' ', $value) ?? '');
}

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT
            BTRIM(people) AS people,
            COUNT(*) AS entry_count,
            MAX(gamets) AS last_gamets,
            MAX(localts) AS last_localts
         FROM diarylog
         WHERE COALESCE(BTRIM(people), '') <> ''
         GROUP BY BTRIM(people)
         ORDER BY MAX(gamets) DESC, MAX(localts) DESC, LOWER(BTRIM(people)) ASC"
    );

    $entries = [];
    foreach ($rows as $row) {
        $name = normalizeParticipantNameToken(strval($row['people'] ?? ''));
        if ($name === '') {
            continue;
        }
        $entryCount = intval($row['entry_count'] ?? 0);
        $display = $name . ' (' . strval($entryCount) . ')';
        $entries[] = $display . "|" . $name;
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

if ($action === 'entries') {
    $sid = normalizeParticipantNameToken(strval($payload['sid'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing sid']);
        return;
    }

    $rows = $db->fetchAll(
        "SELECT rowid, topic, content, location, gamets, localts
         FROM diarylog
         WHERE LOWER(people) = LOWER($1)
         ORDER BY gamets DESC, localts DESC, rowid DESC
         LIMIT 50",
        [$sid]
    );

    $entries = [];
    foreach ($rows as $row) {
        $rowid = intval($row['rowid'] ?? 0);
        if ($rowid <= 0) {
            continue;
        }
        $topic = stobeDiaryListToken(trim(strval($row['topic'] ?? '')));
        if ($topic === '') {
            $topic = 'Diary Entry';
        }
        $dateLabel = stobeDiaryListToken(stobeGametsDateLabel(intval($row['gamets'] ?? 0)));
        $display = $dateLabel === '' ? $topic : ($dateLabel . ' - ' . $topic);
        if (strlen($display) > 90) {
            $display = substr($display, 0, 87) . '...';
        }
        $entries[] = $display . '|' . strval($rowid);
    }

    echo json_encode(
        [
            'ok' => true,
            'count' => count($entries),
            'entries' => $entries,
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'entry') {
    $rowid = intval($payload['rowid'] ?? 0);
    if ($rowid <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing rowid']);
        return;
    }

    $row = $db->fetchOne(
        "SELECT rowid, topic, content, tags, people, location, gamets, localts
         FROM diarylog
         WHERE rowid = $1
         LIMIT 1",
        [$rowid]
    );
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Diary entry not found']);
        return;
    }

    $topic = trim(strval($row['topic'] ?? ''));
    if ($topic === '') {
        $topic = 'Diary Entry';
    }
    $content = trim(strval($row['content'] ?? ''));
    if ($content === '') {
        $content = '(empty)';
    }

    $lines = [
        $topic,
        'Written by: ' . normalizeParticipantNameToken(strval($row['people'] ?? '')),
        'Date: ' . stobeGametsDateLabel(intval($row['gamets'] ?? 0)),
    ];
    $location = trim(strval($row['location'] ?? ''));
    if ($location !== '') {
        $lines[] = 'Location: ' . $location;
    }
    $tags = trim(strval($row['tags'] ?? ''));
    if ($tags !== '') {
        $lines[] = 'Tags: ' . $tags;
    }
    $lines[] = '';
    $lines[] = $content;

    echo json_encode(
        [
            'ok' => true,
            'text' => implode("\n", $lines),
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'detail') {
    $sid = normalizeParticipantNameToken(strval($payload['sid'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing sid']);
        return;
    }

    $rows = $db->fetchAll(
        "SELECT
            rowid,
            topic,
            content,
            tags,
            location,
            gamets,
            localts
         FROM diarylog
         WHERE LOWER(people) = LOWER($1)
         ORDER BY gamets DESC, localts DESC, rowid DESC
         LIMIT 30",
        [$sid]
    );

    if (count($rows) === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No diary entries found']);
        return;
    }

    $lines = [];
    $lines[] = "Name: " . $sid;
    $lines[] = "Entries: " . strval(count($rows));
    $latestGamets = intval($rows[0]['gamets'] ?? 0);
    $lines[] = "Latest: " . stobeGametsDateLabel($latestGamets);
    $lines[] = "";

    foreach ($rows as $row) {
        $topic = trim(strval($row['topic'] ?? 'Diary Entry'));
        if ($topic === '') {
            $topic = 'Diary Entry';
        }
        $location = trim(strval($row['location'] ?? ''));
        $tags = trim(strval($row['tags'] ?? ''));
        $content = trim(strval($row['content'] ?? ''));
        if ($content === '') {
            $content = '(empty)';
        }
        $gamets = intval($row['gamets'] ?? 0);

        $lines[] = "----------------------------------------";
        $lines[] = $topic . " @ " . stobeGametsDateLabel($gamets);
        if ($location !== '') {
            $lines[] = "Location: " . $location;
        }
        if ($tags !== '') {
            $lines[] = "Tags: " . $tags;
        }
        $lines[] = $content;
        $lines[] = "";
    }

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


