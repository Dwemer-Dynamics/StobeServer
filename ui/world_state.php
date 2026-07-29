<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

function world_stateLabel(string $value): string
{
    return ucwords(str_replace('_', ' ', trim($value)));
}

// Expands the one known vanilla FCS name that ends with an unfinished player marker.
function world_stateDisplayQueryName(array $query): string
{
    $name = trim(strval($query['query_name'] ?? 'Unnamed query'));
    if (
        str_ends_with(strtolower($name), '(p')
        && boolval($query['player_involvement_required'] ?? false)
    ) {
        $name = rtrim(substr($name, 0, -2)) . ' (Player Involved)';
    }
    return world_stateLabel($name);
}

function world_stateQueryUrl(array $params, bool $isEmbed): string
{
    if ($isEmbed) {
        $params['embed'] = '1';
    }
    $query = http_build_query($params);
    return 'world_state.php' . ($query !== '' ? '?' . $query : '');
}

function world_stateDownloadModCsv(bool $customOnly): never
{
    $where = $customOnly
        ? 'd.is_vanilla = FALSE AND c.query_id IS NOT NULL'
        : 'd.is_vanilla = FALSE AND d.active = TRUE';
    $rows = $GLOBALS['db']->fetchAll(
        'SELECT d.query_id, d.query_name, d.source_mod,
                a.matched_topics, a.when_true, a.when_false, a.enabled
         FROM world_state_definition d
         INNER JOIN combined_world_state_addendum a ON a.query_id = d.query_id
         LEFT JOIN world_state_addendum_custom c ON c.query_id = d.query_id
         WHERE ' . $where . '
         ORDER BY LOWER(d.source_mod), LOWER(d.query_name), d.query_id'
    );

    $filename = $customOnly
        ? 'world_state_custom_mod_addenda_' . date('Y-m-d_H-i-s') . '.csv'
        : 'world_state_loaded_mod_states_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'query_id',
        'query_name',
        'source_mod',
        'world_knowledge_topics',
        'when_true',
        'when_false',
        'enabled',
    ]);
    foreach ($rows as $row) {
        fputcsv($output, [
            strval($row['query_id'] ?? ''),
            strval($row['query_name'] ?? ''),
            strval($row['source_mod'] ?? ''),
            implode(' | ', stobeWorldStateNormalizeTopics($row['matched_topics'] ?? [])),
            strval($row['when_true'] ?? ''),
            strval($row['when_false'] ?? ''),
            stobeWorldStateParseBool($row['enabled'] ?? false) === true ? 'true' : 'false',
        ]);
    }
    fclose($output);
    exit;
}

$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';
$stateFilter = strtolower(trim(strval($_GET['state'] ?? 'all')));
if (!in_array($stateFilter, ['all', 'true', 'false', 'undefined'], true)) {
    $stateFilter = 'all';
}
$sourceFilter = strtolower(trim(strval($_GET['source'] ?? 'vanilla')));
if (!in_array($sourceFilter, ['vanilla', 'mod'], true)) {
    $sourceFilter = 'vanilla';
}
$search = trim(strval($_GET['q'] ?? ''));
$searchKey = strtolower($search);
$openQueryId = trim(strval($_GET['open'] ?? ''));
$notice = trim(strval($_GET['notice'] ?? ''));
$error = trim(strval($_GET['error'] ?? ''));

$downloadAction = trim(strval($_GET['action'] ?? ''));
if ($downloadAction === 'export_loaded_mod_states') {
    world_stateDownloadModCsv(false);
}
if ($downloadAction === 'export_custom_mod_states') {
    world_stateDownloadModCsv(true);
}
if ($downloadAction === 'download_example_mod_states') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="example_world_state_mod_addenda.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'query_id',
        'query_name',
        'source_mod',
        'world_knowledge_topics',
        'when_true',
        'when_false',
        'enabled',
    ]);
    fputcsv($output, [
        '12345-ExampleMod.mod',
        'Example leader is okay',
        'ExampleMod.mod',
        'Example Leader | Example Town',
        'The Example Leader is alive and still controls Example Town.',
        'The Example Leader is dead and no longer controls Example Town.',
        'true',
    ]);
    fclose($output);
    exit;
}

if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $queryId = trim(strval($_POST['query_id'] ?? ''));
    $action = trim(strval($_POST['action'] ?? ''));
    $redirectParams = [
        'state' => $stateFilter,
        'source' => $sourceFilter,
        'q' => $search,
        'open' => $queryId,
    ];

    try {
        if ($action === 'import_mod_csv') {
            $redirectParams['source'] = 'mod';
            unset($redirectParams['open']);
            $upload = $_FILES['mod_state_csv'] ?? null;
            if (
                !is_array($upload)
                || intval($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            ) {
                $redirectParams['error'] = 'No CSV file was uploaded, or the upload failed.';
            } elseif (
                strtolower(pathinfo(strval($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv'
            ) {
                $redirectParams['error'] = 'Upload failed. Allowed file type: csv.';
            } else {
                $parsed = stobeWorldStateReadModAddendaCsv(
                    strval($upload['tmp_name'] ?? '')
                );
                if (!boolval($parsed['ok'] ?? false)) {
                    $redirectParams['error'] = strval(
                        $parsed['error'] ?? 'The CSV could not be parsed.'
                    );
                } else {
                    $imported = stobeWorldStateImportModAddendaRows($parsed['rows'] ?? []);
                    $invalidCount = count($parsed['invalid'] ?? []);
                    $message = sprintf(
                        'CSV import complete: %d imported (%d new, %d updated)',
                        intval($imported['imported'] ?? 0),
                        intval($imported['inserted'] ?? 0),
                        intval($imported['updated'] ?? 0)
                    );
                    $skipped = [];
                    if (intval($imported['unknown'] ?? 0) > 0) {
                        $skipped[] = intval($imported['unknown']) . ' unknown';
                    }
                    if (intval($imported['vanilla'] ?? 0) > 0) {
                        $skipped[] = intval($imported['vanilla']) . ' vanilla';
                    }
                    if ($invalidCount > 0) {
                        $skipped[] = $invalidCount . ' invalid';
                    }
                    if (count($skipped) > 0) {
                        $message .= '; skipped ' . implode(', ', $skipped);
                    }
                    $redirectParams['notice'] = $message . '.';
                }
            }
        } elseif ($action === 'save_addendum') {
            $saved = stobeWorldStateSaveCustomAddendum(
                $queryId,
                stobeWorldStateNormalizeTopics($_POST['matched_topics'] ?? ''),
                strval($_POST['when_true'] ?? ''),
                strval($_POST['when_false'] ?? ''),
                isset($_POST['enabled'])
            );
            $redirectParams[$saved ? 'notice' : 'error'] = $saved
                ? 'World-state override saved.'
                : 'World-state override could not be saved.';
        } elseif ($action === 'reset_addendum') {
            $reset = stobeWorldStateResetCustomAddendum($queryId);
            $redirectParams[$reset ? 'notice' : 'error'] = $reset
                ? 'World-state override reset to its built-in values.'
                : 'World-state override could not be reset.';
        } else {
            $redirectParams['error'] = 'Unknown world-state action.';
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'World-state override update failed', ['query_id' => $queryId]);
        $redirectParams['error'] = 'World-state override update failed.';
    }

    header('Location: ' . world_stateQueryUrl($redirectParams, $isEmbed), true, 303);
    exit;
}

$catalog = stobeWorldStateCatalog();
$addendumByQuery = stobeWorldStateAddendumIndex();
$catalogIndex = stobeWorldStateCatalogIndex();
$catalogQueries = [];
$runtimeDefinitionIds = [];
$runtimeModQueryCount = 0;
foreach (stobeWorldStateDefinitionRows(true) as $definition) {
    $queryId = stobeWorldStateValidQueryId($definition['query_id'] ?? '');
    if ($queryId === '') {
        continue;
    }
    $runtimeDefinitionIds[$queryId] = true;
    $base = $catalogIndex[$queryId] ?? [
        'query_id' => $queryId,
        'query_name' => $definition['query_name'] ?? $queryId,
        'source_mod' => $definition['source_mod'] ?? '',
        'notes' => '',
        'semantics' => 'All listed rules are AND conditions.',
        'classification' => 'runtime_world_state',
        'consumers' => [],
        'world_knowledge' => ['matched_topics' => [], 'entities' => []],
        'prompt_addendum' => ['when_true' => '', 'when_false' => ''],
    ];
    $base['query_name'] = trim(strval($definition['query_name'] ?? '')) !== ''
        ? strval($definition['query_name'])
        : strval($base['query_name'] ?? $queryId);
    $base['source_mod'] = trim(strval($definition['source_mod'] ?? '')) !== ''
        ? strval($definition['source_mod'])
        : strval($base['source_mod'] ?? '');
    $base['player_involvement_required'] = boolval(
        $definition['player_involvement'] ?? false
    );
    $base['rules'] = $definition['rules'] ?? [];
    $base['runtime_discovered'] = true;
    $base['is_vanilla'] = boolval($definition['is_vanilla'] ?? false);
    if (!$base['is_vanilla']) {
        $runtimeModQueryCount++;
        $base['world_knowledge']['matched_topics'] =
            $addendumByQuery[$queryId]['matched_topics'] ?? [];
    }
    $catalogQueries[] = $base;
}
foreach ($catalog['queries'] ?? [] as $query) {
    if (!is_array($query)) {
        continue;
    }
    $queryId = stobeWorldStateValidQueryId($query['query_id'] ?? '');
    if ($queryId === '' || isset($runtimeDefinitionIds[$queryId])) {
        continue;
    }
    $query['runtime_discovered'] = false;
    $query['is_vanilla'] = true;
    $catalogQueries[] = $query;
}
$resultByQuery = [];
try {
    $resultRows = $GLOBALS['db']->fetchAll(
        'SELECT query_id, query_name, is_true, game_ts, catalog_sha256,
                first_observed_at, last_evaluated_at, changed_at
         FROM world_state_query_result
         ORDER BY LOWER(query_name), query_id'
    );
    foreach ($resultRows as $resultRow) {
        $queryId = trim(strval($resultRow['query_id'] ?? ''));
        if ($queryId !== '') {
            $resultByQuery[$queryId] = $resultRow;
        }
    }
} catch (Throwable $exception) {
    $resultByQuery = [];
}

$counts = ['total' => 0, 'true' => 0, 'false' => 0, 'undefined' => 0];
$sourceCounts = ['vanilla' => 0, 'mod' => 0];
$hiddenEmptyQueries = 0;
$rows = [];
$latestEvaluation = '';
foreach ($catalogQueries as $query) {
    if (!is_array($query)) {
        continue;
    }
    $queryId = trim(strval($query['query_id'] ?? ''));
    if ($queryId === '') {
        continue;
    }
    if (strval($query['classification'] ?? '') === 'ambiguous_empty_query') {
        $hiddenEmptyQueries++;
        continue;
    }
    $result = $resultByQuery[$queryId] ?? null;
    $evaluated = is_array($result);
    $isTrue = $evaluated ? stobeWorldStateParseBool($result['is_true'] ?? null) : null;
    $state = !$evaluated || $isTrue === null ? 'undefined' : ($isTrue ? 'true' : 'false');

    $counts['total']++;
    $counts[$state]++;
    $isVanilla = boolval($query['is_vanilla'] ?? false);
    $sourceCounts[$isVanilla ? 'vanilla' : 'mod']++;

    $haystackParts = [
        $queryId,
        strval($query['query_name'] ?? ''),
        strval($query['source_mod'] ?? ''),
    ];
    foreach ($query['rules'] ?? [] as $rule) {
        if (is_array($rule)) {
            $haystackParts[] = strval($rule['condition_text'] ?? '');
            $haystackParts[] = strval($rule['target_name'] ?? '');
        }
    }
    $addendum = $addendumByQuery[$queryId] ?? null;
    $matchedTopics = is_array($addendum)
        ? ($addendum['matched_topics'] ?? [])
        : ($query['world_knowledge']['matched_topics'] ?? []);
    foreach ($matchedTopics as $topic) {
        $haystackParts[] = strval($topic);
    }

    if ($stateFilter !== 'all' && $state !== $stateFilter) {
        continue;
    }
    if (($sourceFilter === 'vanilla') !== $isVanilla) {
        continue;
    }
    if ($searchKey !== '' && !str_contains(strtolower(implode(' ', $haystackParts)), $searchKey)) {
        continue;
    }

    $lastEvaluated = trim(strval($result['last_evaluated_at'] ?? ''));
    if ($lastEvaluated !== '' && ($latestEvaluation === '' || $lastEvaluated > $latestEvaluation)) {
        $latestEvaluation = $lastEvaluated;
    }
    $rows[] = [
        'catalog' => $query,
        'result' => $result,
        'state' => $state,
        'addendum' => $addendum,
    ];
}

usort($rows, static function (array $left, array $right): int {
    $stateOrder = ['true' => 0, 'false' => 1, 'undefined' => 2];
    $leftState = strval($left['state'] ?? 'undefined');
    $rightState = strval($right['state'] ?? 'undefined');
    $stateCompare = ($stateOrder[$leftState] ?? 9) <=> ($stateOrder[$rightState] ?? 9);
    if ($stateCompare !== 0) {
        return $stateCompare;
    }
    return strcasecmp(
        strval($left['catalog']['query_name'] ?? ''),
        strval($right['catalog']['query_name'] ?? '')
    );
});
$latestEvaluationLabel = $latestEvaluation;
if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $latestEvaluation, $matches) === 1) {
    $latestEvaluationLabel = $matches[1] . ' ' . $matches[2];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>World State</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main { width: 100%; margin: 0; padding: 18px 6px 28px; }
        .page-header {
            padding: 14px 18px;
            margin-bottom: 12px;
            text-align: center;
            background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(34,34,34,.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
        }
        .page-header h1 {
            margin: 0 0 4px;
            color: #e6b76c;
            font-family: "MagicCards", serif;
            font-size: 1.8em;
            word-spacing: 8px;
        }
        .page-header p { margin: 0; color: #aaa; font-size: .86rem; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(105px, 1fr));
            gap: 7px;
            margin-bottom: 9px;
        }
        .summary-card, .filter-bar, .state-row {
            background: #252525;
            border: 1px solid #414141;
            border-radius: 7px;
        }
        .summary-card { padding: 8px 10px; text-align: center; }
        .summary-card strong { display: block; color: #e6b76c; font-size: 1.08rem; line-height: 1.25; }
        .summary-card span { color: #999; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; }
        .state-tabs, .filter-bar {
            display: flex;
            gap: 7px;
            align-items: center;
            flex-wrap: wrap;
            padding: 8px;
            margin-bottom: 9px;
        }
        .state-tabs {
            padding: 0;
            background: transparent;
            border: 0;
        }
        .state-tabs .filter-button { flex: 1 1 120px; text-align: center; }
        .filter-bar form { display: flex; gap: 8px; flex: 1 1 100%; }
        .filter-bar input, .filter-bar select {
            flex: 1;
            min-width: 180px;
            color: #eee;
            background: #191919;
            border: 1px solid #505050;
            border-radius: 5px;
            padding: 6px 9px;
            font-size: .86rem;
        }
        .filter-bar select { flex: 0 1 160px; min-width: 145px; }
        .filter-button {
            display: inline-block;
            color: #ddd;
            background: #333;
            border: 1px solid #555;
            border-radius: 5px;
            padding: 6px 9px;
            font-size: .8rem;
            text-decoration: none;
            white-space: nowrap;
        }
        .filter-bar .filter-button:hover, .filter-bar .filter-button.active,
        .state-tabs .filter-button:hover, .state-tabs .filter-button.active {
            color: #1d1d1d !important;
            background: #e6b76c !important;
            border-color: #e6b76c !important;
        }
        .state-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            grid-auto-rows: 1fr;
            gap: 8px;
            align-items: stretch;
        }
        .state-row {
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
            padding: 11px 12px;
            border-left-width: 3px;
            min-width: 0;
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .state-row:hover { border-color: #68604f; box-shadow: 0 2px 10px rgba(0,0,0,.28); }
        .state-row.state-true { border-left-color: #2e7650; }
        .state-row.state-false { border-left-color: #8d3c48; }
        .state-row.state-undefined { border-left-color: #5a5a5a; }
        .state-heading { display: flex; gap: 8px; justify-content: space-between; align-items: flex-start; }
        .state-heading > div:first-child { min-width: 0; }
        .state-title {
            display: -webkit-box;
            min-height: 2.4em;
            max-height: 2.4em;
            margin: 0;
            color: #eee;
            font-size: .96rem;
            line-height: 1.2;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
        .state-id {
            margin-top: 3px;
            color: #777;
            font: .68rem Consolas, monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .state-badge {
            flex: 0 0 auto;
            min-width: 58px;
            padding: 3px 7px;
            border-radius: 999px;
            text-align: center;
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .state-badge.true { color: #b9f6ca; background: #173c26; border: 1px solid #2e7650; }
        .state-badge.false { color: #ffcdd2; background: #461f25; border: 1px solid #8d3c48; }
        .state-badge.undefined { color: #ccc; background: #383838; border: 1px solid #5a5a5a; }
        .condition-preview {
            box-sizing: border-box;
            height: 54px;
            margin-top: 9px;
            padding: 8px 9px;
            color: #d7d7d7;
            background: #1d1d1d;
            border: 1px solid #383838;
            border-radius: 5px;
            font-size: .82rem;
            line-height: 1.35;
            overflow: hidden;
        }
        .condition-count { margin-left: 5px; color: #e6b76c; white-space: nowrap; }
        .state-meta {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 8px;
            color: #999;
            font-size: .69rem;
            height: 47px;
            overflow: hidden;
        }
        .state-meta span {
            padding: 3px 6px;
            background: #202020;
            border: 1px solid #353535;
            border-radius: 999px;
        }
        .state-meta .source-chip {
            flex: 1 1 100%;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .state-meta .custom-chip { color: #f0ca8c; border-color: #70582f; }
        .conditions { margin: 0; padding-left: 18px; color: #d4d4d4; }
        .conditions li { margin: 3px 0; }
        .details-button {
            display: block;
            margin-top: auto;
            padding-top: 8px;
            color: #d7ad6b;
            background: transparent;
            border: 0;
            font-size: .76rem;
            text-align: left;
            cursor: pointer;
        }
        .details-button:hover { color: #f0ca8c; }
        .state-dialog {
            width: min(720px, calc(100vw - 24px));
            max-width: 720px;
            max-height: min(78vh, 680px);
            padding: 0;
            color: #bbb;
            background: #252525;
            border: 1px solid #555;
            border-radius: 8px;
            box-shadow: 0 18px 60px rgba(0,0,0,.7);
            overflow: hidden;
            cursor: default;
        }
        .state-dialog::backdrop { background: rgba(0,0,0,.72); }
        .dialog-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            background: #202020;
            border-bottom: 1px solid #414141;
        }
        .dialog-header h2 { margin: 0; color: #eee; font-size: 1rem; }
        .dialog-header .state-id { margin-top: 3px; }
        .dialog-close {
            flex: 0 0 auto;
            width: 30px;
            height: 30px;
            padding: 0;
            color: #ddd;
            background: #333;
            border: 1px solid #555;
            border-radius: 5px;
            font-size: 1.1rem;
            line-height: 1;
            cursor: pointer;
        }
        .dialog-close:hover { color: #1d1d1d; background: #e6b76c; border-color: #e6b76c; }
        .dialog-body { max-height: calc(min(78vh, 680px) - 58px); padding: 12px; overflow: auto; }
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; margin-top: 8px; }
        .detail-panel { padding: 8px; background: #1d1d1d; border: 1px solid #373737; border-radius: 5px; overflow-wrap: anywhere; }
        .detail-panel h3 { margin: 0 0 6px; color: #ddd; font-size: .72rem; text-transform: uppercase; }
        .detail-panel ul { margin: 0; padding-left: 18px; }
        .detail-panel a { color: #e6b76c; }
        .override-form { margin-top: 10px; }
        .override-form label {
            display: block;
            margin: 8px 0 4px;
            color: #d7ad6b;
            font-size: .72rem;
            font-weight: 600;
        }
        .override-form textarea {
            width: 100%;
            min-height: 66px;
            box-sizing: border-box;
            padding: 7px 8px;
            color: #eee;
            background: #171717;
            border: 1px solid #464646;
            border-radius: 5px;
            font: .78rem/1.35 inherit;
            resize: vertical;
        }
        .override-enabled {
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 7px 8px;
            color: #ddd !important;
            background: #222;
            border: 1px solid #3b3b3b;
            border-radius: 5px;
        }
        .override-enabled input { flex: 0 0 auto; width: 18px; height: 18px; }
        .override-actions { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 9px; }
        .override-button {
            padding: 6px 9px;
            color: #1d1d1d;
            background: #e6b76c;
            border: 1px solid #e6b76c;
            border-radius: 5px;
            font-size: .76rem;
            font-weight: 700;
            cursor: pointer;
        }
        .override-button.secondary { color: #ddd; background: #333; border-color: #555; }
        .page-message {
            margin: 0 0 9px;
            padding: 8px 10px;
            border: 1px solid #41634b;
            border-radius: 6px;
            color: #c9f2d2;
            background: #203126;
            font-size: .82rem;
        }
        .page-message.error { color: #ffd1d5; background: #3b2226; border-color: #76434a; }
        .csv-panel {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 9px;
            padding: 10px 12px;
            color: #aaa;
            background: #252525;
            border: 1px solid #414141;
            border-radius: 7px;
            font-size: .78rem;
        }
        .csv-panel-copy { flex: 1 1 280px; min-width: 220px; }
        .csv-panel h2 { margin: 0 0 3px; color: #e6b76c; font-size: .9rem; }
        .csv-panel p { margin: 0; }
        .csv-import-form {
            display: flex;
            flex: 2 1 500px;
            align-items: center;
            justify-content: flex-end;
            gap: 7px;
            flex-wrap: wrap;
        }
        .csv-import-form input[type="file"] {
            flex: 1 1 220px;
            min-width: 190px;
            padding: 5px 7px;
            color: #ddd;
            background: #191919;
            border: 1px solid #505050;
            border-radius: 5px;
            font-size: .76rem;
        }
        .csv-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .csv-actions .filter-button { padding: 5px 8px; }
        .empty-state { padding: 30px; color: #aaa; text-align: center; border: 1px dashed #555; border-radius: 8px; }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .summary-card:last-child { grid-column: 1 / -1; }
            .csv-panel { align-items: flex-start; flex-wrap: wrap; }
            .csv-import-form { justify-content: flex-start; }
        }
        @media (max-width: 520px) {
            main { padding: 10px 4px 20px; }
            .summary-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar form { flex-wrap: wrap; }
            .filter-bar select { flex: 1 1 100%; }
            .csv-import-form input[type="file"] { flex-basis: 100%; width: 100%; }
            .state-list { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>
<?php endif; ?>
<main>
    <div class="page-header">
        <h1>World State</h1>
        <p>
            Live loaded WORLD_EVENT_STATE results with editable prompt addenda.
            <?php if (count($runtimeDefinitionIds) > 0): ?>
                <?= h(count($runtimeDefinitionIds)) ?> definitions were discovered in-game, including <?= h($runtimeModQueryCount) ?> mod-added queries.
            <?php else: ?>
                Waiting for the in-game definition snapshot; showing the vanilla catalog.
            <?php endif; ?>
            <?= h($hiddenEmptyQueries) ?> empty vanilla placeholders are omitted.
        </p>
    </div>

    <?php if ($notice !== ''): ?><div class="page-message"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="page-message error"><?= h($error) ?></div><?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card"><strong><?= h($counts['total']) ?></strong><span>Defined States</span></div>
        <div class="summary-card"><strong><?= h($counts['true']) ?></strong><span>True</span></div>
        <div class="summary-card"><strong><?= h($counts['false']) ?></strong><span>False</span></div>
        <div class="summary-card"><strong><?= h($counts['undefined']) ?></strong><span>Undefined</span></div>
        <div class="summary-card"><strong><?= h($latestEvaluationLabel !== '' ? $latestEvaluationLabel : 'Waiting') ?></strong><span>Latest Evaluation</span></div>
    </div>

    <nav class="state-tabs" aria-label="World state source">
        <?php foreach (['vanilla' => 'Vanilla', 'mod' => 'Mods'] as $key => $label): ?>
            <a class="filter-button <?= $sourceFilter === $key ? 'active' : '' ?>"
               href="<?= h(world_stateQueryUrl(['state' => $stateFilter, 'source' => $key, 'q' => $search], $isEmbed)) ?>">
                <?= h($label) ?> (<?= h($sourceCounts[$key]) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($sourceFilter === 'mod'): ?>
        <section class="csv-panel" aria-labelledby="world-state-csv-title">
            <div class="csv-panel-copy">
                <h2 id="world-state-csv-title">CSV Import</h2>
                <p>
                    Export loaded mod states, add topics and prompt text, then upload the CSV.
                    Matching rows are imported immediately; unknown and vanilla IDs are skipped.
                </p>
            </div>
            <form class="csv-import-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_mod_csv">
                <input type="file" name="mod_state_csv" accept=".csv,text/csv" aria-label="Mod world-state CSV" required>
                <button class="filter-button" type="submit">Upload CSV</button>
                <div class="csv-actions">
                    <a class="filter-button"
                       href="<?= h(world_stateQueryUrl(['action' => 'export_loaded_mod_states'], $isEmbed)) ?>">Export Loaded</a>
                    <a class="filter-button"
                       href="<?= h(world_stateQueryUrl(['action' => 'export_custom_mod_states'], $isEmbed)) ?>">Export Custom</a>
                    <a class="filter-button"
                       href="<?= h(world_stateQueryUrl(['action' => 'download_example_mod_states'], $isEmbed)) ?>">Example CSV</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div class="filter-bar">
        <form method="get">
            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <input type="hidden" name="source" value="<?= h($sourceFilter) ?>">
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search query, condition, source, or topic">
            <select name="state" aria-label="Filter by state">
                <option value="all" <?= $stateFilter === 'all' ? 'selected' : '' ?>>All States</option>
                <option value="true" <?= $stateFilter === 'true' ? 'selected' : '' ?>>True</option>
                <option value="false" <?= $stateFilter === 'false' ? 'selected' : '' ?>>False</option>
                <option value="undefined" <?= $stateFilter === 'undefined' ? 'selected' : '' ?>>Undefined</option>
            </select>
            <button class="filter-button" type="submit">Search</button>
        </form>
    </div>

    <div class="state-list">
        <?php if (count($rows) === 0): ?>
            <div class="empty-state">No world-state queries match this filter.</div>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $query = $row['catalog'];
            $result = $row['result'];
            $state = $row['state'];
            $queryId = strval($query['query_id'] ?? '');
            $rules = is_array($query['rules'] ?? null) ? $query['rules'] : [];
            $primaryCondition = count($rules) > 0
                ? trim(strval($rules[0]['condition_text'] ?? 'Unknown condition'))
                : 'No conditions in the FCS record; result semantics are ambiguous.';
            $addendum = is_array($row['addendum'] ?? null)
                ? $row['addendum']
                : [
                    'matched_topics' => $query['world_knowledge']['matched_topics'] ?? [],
                    'when_true' => $query['prompt_addendum']['when_true'] ?? '',
                    'when_false' => $query['prompt_addendum']['when_false'] ?? '',
                    'enabled' => true,
                    'is_custom' => false,
                ];
            $topics = stobeWorldStateNormalizeTopics($addendum['matched_topics'] ?? []);
            $addendumEnabled = boolval($addendum['enabled'] ?? false);
            $isCustomAddendum = boolval($addendum['is_custom'] ?? false);
            $activeAddendum = !$addendumEnabled
                ? ''
                : ($state === 'true'
                    ? trim(strval($addendum['when_true'] ?? ''))
                    : ($state === 'false' ? trim(strval($addendum['when_false'] ?? '')) : ''));
            $dialogId = 'world-state-' . substr(sha1($queryId), 0, 12);
            ?>
            <article class="state-row state-<?= h($state) ?>" data-card-dialog-target="<?= h($dialogId) ?>">
                <div class="state-heading">
                    <div>
                        <h2 class="state-title" title="<?= h(world_stateDisplayQueryName($query)) ?>"><?= h(world_stateDisplayQueryName($query)) ?></h2>
                        <div class="state-id" title="<?= h($queryId) ?>"><?= h($queryId) ?></div>
                    </div>
                    <div class="state-badge <?= h($state) ?>"><?= h($state) ?></div>
                </div>

                <div class="condition-preview" title="<?= h($primaryCondition) ?>">
                    <?= h($primaryCondition) ?>
                    <?php if (count($rules) > 1): ?>
                        <span class="condition-count">+<?= h(count($rules) - 1) ?> more</span>
                    <?php endif; ?>
                </div>

                <div class="state-meta">
                    <span class="source-chip" title="Source: <?= h($query['source_mod'] ?? '') ?>">Source: <?= h($query['source_mod'] ?? '') ?></span>
                    <span>Game time: <?= h($result['game_ts'] ?? 'not evaluated') ?></span>
                    <?php if ($isCustomAddendum): ?><span class="custom-chip">Custom addendum</span><?php endif; ?>
                </div>

                <button class="details-button" type="button" data-dialog-target="<?= h($dialogId) ?>">
                    Details &middot; <?= h(count($rules)) ?> cond. &middot; <?= h(count($topics)) ?> topic<?= count($topics) === 1 ? '' : 's' ?>
                </button>
                <dialog class="state-dialog" id="<?= h($dialogId) ?>" data-query-id="<?= h($queryId) ?>" aria-labelledby="<?= h($dialogId) ?>-title">
                    <div class="dialog-header">
                        <div>
                            <h2 id="<?= h($dialogId) ?>-title"><?= h(world_stateDisplayQueryName($query)) ?></h2>
                            <div class="state-id"><?= h($queryId) ?></div>
                        </div>
                        <button class="dialog-close" type="button" data-dialog-close aria-label="Close details">&times;</button>
                    </div>
                    <div class="dialog-body">
                        <div class="detail-grid">
                        <section class="detail-panel">
                            <h3>Conditions</h3>
                            <ul class="conditions">
                                <?php if (count($rules) === 0): ?>
                                    <li>No conditions in the FCS record.</li>
                                <?php endif; ?>
                                <?php foreach ($rules as $rule): ?>
                                    <li><?= h($rule['condition_text'] ?? 'Unknown condition') ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <h3 style="margin-top:8px;">Evaluation</h3>
                            <div>Checked: <?= h($result['last_evaluated_at'] ?? 'not evaluated') ?></div>
                            <div>Changed: <?= h($result['changed_at'] ?? 'not evaluated') ?></div>
                        </section>
                        <section class="detail-panel">
                            <h3>World Knowledge</h3>
                            <?php if (count($topics) === 0): ?>
                                <div>No matched article. This result is not added to prompts.</div>
                            <?php else: ?>
                                <ul><?php foreach ($topics as $topic): ?><li><?= h($topic) ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                            <h3 style="margin-top:10px;">Current Prompt Addendum</h3>
                            <div><?= h(
                                !$addendumEnabled
                                    ? 'Disabled. This world state is not added to prompts.'
                                    : ($activeAddendum !== ''
                                        ? $activeAddendum
                                        : 'None. False multi-condition results are not inferred.')
                            ) ?></div>
                            <form class="override-form" method="post">
                                <input type="hidden" name="query_id" value="<?= h($queryId) ?>">
                                <label class="override-enabled">
                                    <span>Enabled</span>
                                    <input type="checkbox" name="enabled" value="1" <?= $addendumEnabled ? 'checked' : '' ?>>
                                </label>
                                <label for="<?= h($dialogId) ?>-topics">Matched topics, one per line</label>
                                <textarea id="<?= h($dialogId) ?>-topics" name="matched_topics"><?= h(implode("\n", $topics)) ?></textarea>
                                <label for="<?= h($dialogId) ?>-true">When true</label>
                                <textarea id="<?= h($dialogId) ?>-true" name="when_true"><?= h($addendum['when_true'] ?? '') ?></textarea>
                                <label for="<?= h($dialogId) ?>-false">When false</label>
                                <textarea id="<?= h($dialogId) ?>-false" name="when_false"><?= h($addendum['when_false'] ?? '') ?></textarea>
                                <div class="override-actions">
                                    <button class="override-button" type="submit" name="action" value="save_addendum">Save Override</button>
                                    <?php if ($isCustomAddendum): ?>
                                        <button class="override-button secondary" type="submit" name="action" value="reset_addendum"
                                                onclick="return confirm('Reset this addendum to its built-in values?')">Reset to Built-in</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </section>
                        </div>
                    </div>
                </dialog>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<script>
(function () {
    function openDialog(dialogId) {
        const dialog = document.getElementById(dialogId);
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-dialog-target]');
        if (openButton) {
            openDialog(openButton.dataset.dialogTarget);
            return;
        }

        const closeButton = event.target.closest('[data-dialog-close]');
        if (closeButton) {
            const dialog = closeButton.closest('dialog');
            if (dialog) {
                dialog.close();
            }
            return;
        }

        if (event.target instanceof HTMLDialogElement) {
            event.target.close();
            return;
        }

        const card = event.target.closest('[data-card-dialog-target]');
        const interactiveElement = event.target.closest('a, button, input, textarea, select, label, form, dialog');
        if (card && !interactiveElement) {
            openDialog(card.dataset.cardDialogTarget);
        }
    });

    const openQueryId = <?= json_encode($openQueryId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    if (openQueryId !== '') {
        const dialog = Array.from(document.querySelectorAll('dialog[data-query-id]'))
            .find(function (candidate) { return candidate.dataset.queryId === openQueryId; });
        if (dialog) {
            openDialog(dialog.id);
        }
    }
})();
</script>
</body>
</html>
