<?php

error_reporting(E_ERROR);

define('STOBE_BASE_PATH', dirname(dirname(__DIR__)));
define('STOBE_LIB_PATH', STOBE_BASE_PATH . DIRECTORY_SEPARATOR . 'lib');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . "{$GLOBALS['DBDRIVER']}.class.php";
require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . 'data_functions.php';
require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . 'eventlog_helper.php';
require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . 'logger.php';
require_once STOBE_LIB_PATH . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';

if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
    $GLOBALS['db'] = new sql();
}

function stobeNpcHistoryRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stobeNpcHistoryFindNpc(array $input): array
{
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid NPC id');
    }

    $row = $GLOBALS['db']->fetchOne(
        'SELECT id, name AS npc_name FROM core_npc_master WHERE id = $1 LIMIT 1',
        [$id]
    );
    if (!$row) {
        throw new InvalidArgumentException('NPC not found');
    }
    return $row;
}

function stobeNpcHistoryListRecipients(array $input): array
{
    $search = trim(strval($input['search'] ?? ''));
    if (strlen($search) < 2) {
        return ['npcs' => []];
    }

    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id, name
         FROM core_npc_master
         WHERE name ILIKE $1
         ORDER BY name ASC
         LIMIT 10",
        ['%' . $search . '%']
    );
    return [
        'npcs' => array_map(static function (array $row): array {
            return ['id' => intval($row['id'] ?? 0), 'name' => trim(strval($row['name'] ?? ''))];
        }, $rows),
    ];
}

function stobeNpcHistoryAllowedTypesSql(array $types, int $startAt = 2): array
{
    $placeholders = [];
    for ($index = 0; $index < count($types); $index++) {
        $placeholders[] = '$' . strval($startAt + $index);
    }
    return ['sql' => implode(',', $placeholders), 'params' => array_values($types)];
}

function stobeNpcHistoryRead(array $input): array
{
    $npc = stobeNpcHistoryFindNpc($input);
    $npcName = trim(strval($npc['npc_name'] ?? ''));
    $limit = max(1, min(100, intval($input['limit'] ?? 100)));
    $selectedType = trim(strval($input['event_type'] ?? ''));
    $allowedTypes = stobeAdventureEventTypes();
    if ($selectedType !== '' && !in_array($selectedType, $allowedTypes, true)) {
        return [
            'npc' => ['id' => intval($npc['id']), 'name' => $npcName],
            'events' => [],
            'filters' => ['selected_event_type' => $selectedType, 'event_types' => []],
        ];
    }

    $allowed = stobeNpcHistoryAllowedTypesSql($allowedTypes, 2);
    $peopleWhere = stobeBuildNpcEventPeopleWhereClause('$1', 'a.people');
    $visibilityWhere = stobeBuildEventlogDeliveryVisibilitySql('a');
    $params = array_merge([$npcName], $allowed['params']);
    $selectedWhere = '';
    if ($selectedType !== '') {
        $params[] = $selectedType;
        $selectedWhere = ' AND a.type = $' . strval(count($params));
    }

    $rows = $GLOBALS['db']->fetchAll(
        "SELECT a.rowid, a.type, a.data, a.people, a.gamets, a.localts, a.ts, a.sess
         FROM eventlog a
         WHERE a.type IN ({$allowed['sql']})
           AND {$visibilityWhere}
           AND {$peopleWhere}{$selectedWhere}
         ORDER BY COALESCE(NULLIF(a.localts, 0), a.ts, 0) DESC, a.ts DESC, a.rowid DESC
         LIMIT {$limit}",
        $params
    );
    $eventTypes = $GLOBALS['db']->fetchAll(
        "SELECT a.type, COUNT(*) AS total
         FROM eventlog a
         WHERE a.type IN ({$allowed['sql']})
           AND {$visibilityWhere}
           AND {$peopleWhere}
         GROUP BY a.type
         ORDER BY a.type ASC",
        array_merge([$npcName], $allowed['params'])
    );

    return [
        'npc' => ['id' => intval($npc['id']), 'name' => $npcName],
        'events' => array_map(static function (array $row): array {
            $gamets = intval($row['gamets'] ?? 0);
            return [
                'rowid' => intval($row['rowid'] ?? 0),
                'type' => strval($row['type'] ?? ''),
                'data' => strval($row['data'] ?? ''),
                'recipients' => stobeParseEventPeople($row['people'] ?? ''),
                'gamets' => $gamets,
                'kenshi_time' => $gamets > 0 ? stobeGametsDateLabel($gamets) : '',
                'local_time' => !empty($row['localts']) ? gmdate('Y-m-d H:i:s', intval($row['localts'])) : '',
                'manual_injection' => strtolower(strval($row['type'] ?? '')) === 'inputtext'
                    && strval($row['sess'] ?? '') === 'npc_editor',
            ];
        }, $rows),
        'filters' => [
            'selected_event_type' => $selectedType,
            'event_types' => array_map(static function (array $row): array {
                return ['type' => strval($row['type'] ?? ''), 'total' => intval($row['total'] ?? 0)];
            }, $eventTypes),
        ],
    ];
}

function stobeNpcHistoryResolveRecipients(array $input, array $npc): array
{
    $ids = [intval($npc['id'])];
    foreach ((array)($input['recipient_ids'] ?? []) as $requestedId) {
        $requestedId = intval($requestedId);
        if ($requestedId > 0 && !in_array($requestedId, $ids, true)) {
            $ids[] = $requestedId;
        }
    }
    if (count($ids) > 12) {
        throw new InvalidArgumentException('An event can include at most 12 NPCs');
    }

    $recipients = [];
    foreach ($ids as $id) {
        $row = $id === intval($npc['id']) ? $npc : stobeNpcHistoryFindNpc(['id' => $id]);
        $name = trim(strval($row['npc_name'] ?? ''));
        if ($name === '' || str_contains($name, '|')) {
            throw new InvalidArgumentException('One of the selected NPC names cannot be used for event routing');
        }
        $recipients[] = ['id' => intval($row['id']), 'name' => $name];
    }
    return $recipients;
}

function stobeNpcHistoryInject(array $input): array
{
    $npc = stobeNpcHistoryFindNpc($input);
    $eventText = trim(strval($input['event'] ?? ''));
    if (strlen($eventText) >= 2 && $eventText[0] === '(' && substr($eventText, -1) === ')') {
        $eventText = trim(substr($eventText, 1, -1));
    }
    if ($eventText === '') {
        throw new InvalidArgumentException('Event text is required');
    }
    $eventLength = function_exists('mb_strlen') ? mb_strlen($eventText, 'UTF-8') : strlen($eventText);
    if ($eventLength > 4000) {
        throw new InvalidArgumentException('Event text must be 4000 characters or fewer');
    }

    $recipients = stobeNpcHistoryResolveRecipients($input, $npc);
    $people = json_encode(
        array_column($recipients, 'name'),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($people === false) {
        throw new RuntimeException('Event recipients could not be encoded');
    }
    $latest = $GLOBALS['db']->fetchOne(
        'SELECT COALESCE(MAX(ts), 0)::bigint AS ts, COALESCE(MAX(gamets), 0)::bigint AS gamets FROM eventlog'
    );
    $inserted = $GLOBALS['db']->fetchOne(
        "INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people, location)
         VALUES ($1, $2, 'inputtext', $3, 'npc_editor', $4, $5, '')
         RETURNING rowid",
        [
            max(0, intval($latest['ts'] ?? 0)) + 1,
            max(0, intval($latest['gamets'] ?? 0)),
            '(' . $eventText . ')',
            time(),
            $people,
        ]
    );
    $rowId = intval($inserted['rowid'] ?? 0);
    if ($rowId <= 0) {
        throw new RuntimeException('Event could not be injected');
    }

    return [
        'message' => 'Event injected for ' . implode(', ', array_column($recipients, 'name')) . '.',
        'rowid' => $rowId,
        'recipients' => $recipients,
    ];
}

function stobeNpcHistoryDelete(array $input): array
{
    $npc = stobeNpcHistoryFindNpc($input);
    $rowId = intval($input['rowid'] ?? 0);
    if ($rowId <= 0) {
        throw new InvalidArgumentException('Invalid event row');
    }

    $allowedTypes = stobeAdventureEventTypes();
    $allowed = stobeNpcHistoryAllowedTypesSql($allowedTypes, 3);
    $peopleWhere = stobeBuildNpcEventPeopleWhereClause('$2', 'a.people');
    $visibilityWhere = stobeBuildEventlogDeliveryVisibilitySql('a');
    $event = $GLOBALS['db']->fetchOne(
        "SELECT a.rowid
         FROM eventlog a
         WHERE a.rowid = $1
           AND {$peopleWhere}
           AND a.type IN ({$allowed['sql']})
           AND {$visibilityWhere}
         LIMIT 1",
        array_merge([$rowId, trim(strval($npc['npc_name'] ?? ''))], $allowed['params'])
    );
    if (!$event) {
        throw new InvalidArgumentException('Event is not available in this NPC history');
    }

    $deleted = $GLOBALS['db']->fetchOne('DELETE FROM eventlog WHERE rowid = $1 RETURNING rowid', [$rowId]);
    if (!$deleted) {
        throw new RuntimeException('Event could not be deleted');
    }
    return ['message' => 'Event deleted.', 'rowid' => $rowId];
}

try {
    $method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        $input = json_decode(strval(file_get_contents('php://input')), true);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid JSON request');
        }
        $operation = strtolower(trim(strval($input['operation'] ?? '')));
        if ($operation === 'inject_event') {
            stobeNpcHistoryRespond(['success' => true, 'data' => stobeNpcHistoryInject($input)]);
        }
        if ($operation === 'delete_event') {
            stobeNpcHistoryRespond(['success' => true, 'data' => stobeNpcHistoryDelete($input)]);
        }
        throw new InvalidArgumentException('Unsupported NPC history operation');
    }

    $operation = strtolower(trim(strval($_GET['operation'] ?? 'history')));
    if ($operation === 'list') {
        stobeNpcHistoryRespond(['success' => true, 'data' => stobeNpcHistoryListRecipients($_GET)]);
    }
    if ($operation === 'history') {
        stobeNpcHistoryRespond(['success' => true, 'data' => stobeNpcHistoryRead($_GET)]);
    }
    throw new InvalidArgumentException('Unsupported NPC history operation');
} catch (InvalidArgumentException $error) {
    stobeNpcHistoryRespond(['success' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Logger::error('Stobe NPC history API failed: ' . $error->getMessage());
    stobeNpcHistoryRespond(['success' => false, 'error' => 'Unable to process NPC history request'], 500);
}
