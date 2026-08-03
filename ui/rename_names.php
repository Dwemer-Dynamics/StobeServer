<?php

declare(strict_types=1);

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'rename_name_pool_functions.php';

$db = $GLOBALS['db'];
$isEmbed = strval($_GET['embed'] ?? ($_POST['embed'] ?? '')) === '1';

function renameNamesH(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

function renameNamesWantsJson(): bool
{
    return strtolower(strval($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || strpos(strtolower(strval($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false;
}

function renameNamesJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function renameNamesUrl(array $params, bool $isEmbed): string
{
    if ($isEmbed) {
        $params['embed'] = '1';
    }
    $query = http_build_query(array_filter($params, static fn ($value): bool => $value !== '' && $value !== null));
    return 'rename_names.php' . ($query !== '' ? '?' . $query : '');
}

function renameNamesRedirect(string $notice, bool $isEmbed, array $params = []): never
{
    $params['notice'] = $notice;
    header('Location: ' . renameNamesUrl($params, $isEmbed));
    exit;
}

if (strval($_GET['action'] ?? '') === 'download_example') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stobe_rename_names_example.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'gender', 'race', 'faction', 'enabled']);
    fputcsv($out, ['Kato', 'male', '', '', 'true']);
    fputcsv($out, ['Sable', 'neutral', 'Shek', '', 'true']);
    fputcsv($out, ['Nami', 'female', '', 'United Cities', 'true']);
    fclose($out);
    exit;
}

if (strval($_GET['action'] ?? '') === 'export_custom') {
    $rows = $db->fetchAll(
        'SELECT name, gender, race, faction, is_enabled FROM rename_global_custom ORDER BY LOWER(name)'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stobe_custom_rename_names_' . date('Y-m-d_H-i-s') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'gender', 'race', 'faction', 'enabled']);
    foreach ($rows as $row) {
        fputcsv($out, [
            strval($row['name'] ?? ''),
            strval($row['gender'] ?? ''),
            strval($row['race'] ?? ''),
            strval($row['faction'] ?? ''),
            stobeRenameNameBool($row['is_enabled'] ?? true) ? 'true' : 'false',
        ]);
    }
    fclose($out);
    exit;
}

$filterParams = [
    'q' => trim(strval($_REQUEST['q'] ?? '')),
    'state' => strtolower(trim(strval($_REQUEST['state'] ?? 'all'))),
    'source' => strtolower(trim(strval($_REQUEST['source'] ?? 'all'))),
    'gender' => strtolower(trim(strval($_REQUEST['gender'] ?? 'all'))),
    'race' => trim(strval($_REQUEST['race'] ?? 'all')),
    'faction' => trim(strval($_REQUEST['faction'] ?? 'all')),
];
if (!in_array($filterParams['state'], ['all', 'enabled', 'disabled'], true)) {
    $filterParams['state'] = 'all';
}
if (!in_array($filterParams['source'], ['all', 'base', 'custom'], true)) {
    $filterParams['source'] = 'all';
}
if (!in_array($filterParams['gender'], ['all', 'male', 'female', 'neutral', 'blank'], true)) {
    $filterParams['gender'] = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim(strval($_POST['action'] ?? '')));
    if ($action === 'toggle') {
        $rowId = intval($_POST['row_id'] ?? 0);
        $source = strtolower(trim(strval($_POST['source'] ?? '')));
        $enabled = stobeRenameNameBool($_POST['target_enabled'] ?? '0', false);
        $table = $source === 'custom' ? 'rename_global_custom' : ($source === 'base' ? 'rename_global' : '');
        $ok = $rowId > 0 && $table !== '' && $db->exec(
            "UPDATE {$table} SET is_enabled = $1, updated_at = NOW() WHERE id = $2",
            [$enabled ? '1' : '0', $rowId]
        ) !== false;
        if (renameNamesWantsJson()) {
            renameNamesJson([
                'ok' => $ok,
                'enabled' => $enabled,
                'message' => $ok ? 'Name state updated.' : 'Could not update the name state.',
            ], $ok ? 200 : 400);
        }
        renameNamesRedirect($ok ? 'toggled' : 'error', $isEmbed, $filterParams);
    }

    if ($action === 'save') {
        $result = stobeRenameNameSaveCustom($db, $_POST, intval($_POST['row_id'] ?? 0));
        $ok = boolval($result['ok'] ?? false);
        if (renameNamesWantsJson()) {
            renameNamesJson($result, $ok ? 200 : 400);
        }
        renameNamesRedirect($ok ? 'saved' : 'validation_error', $isEmbed, $filterParams);
    }

    if ($action === 'delete') {
        $rowId = intval($_POST['row_id'] ?? 0);
        $ok = $rowId > 0 && $db->exec('DELETE FROM rename_global_custom WHERE id = $1', [$rowId]) !== false;
        if (renameNamesWantsJson()) {
            renameNamesJson(['ok' => $ok, 'message' => $ok ? 'Custom name deleted.' : 'Could not delete the custom name.'], $ok ? 200 : 400);
        }
        renameNamesRedirect($ok ? 'deleted' : 'error', $isEmbed, $filterParams);
    }

    if ($action === 'import') {
        $upload = $_FILES['csv_file'] ?? [];
        $tmpPath = strval($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || intval($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            renameNamesRedirect('import_file_error', $isEmbed, $filterParams);
        }
        $parsed = stobeRenameNameParseCsv($tmpPath);
        if (!boolval($parsed['ok'] ?? false)) {
            renameNamesRedirect('import_file_error', $isEmbed, $filterParams);
        }
        $result = stobeRenameNameImport($db, is_array($parsed['rows'] ?? null) ? $parsed['rows'] : []);
        $result['invalid'] = count(is_array($parsed['errors'] ?? null) ? $parsed['errors'] : []);
        renameNamesRedirect(
            'imported',
            $isEmbed,
            array_merge($filterParams, ['import_result' => base64_encode(json_encode($result, JSON_UNESCAPED_SLASHES))])
        );
    }
}

$allRows = $db->fetchAll(
    "SELECT id, name, gender, race, faction, is_enabled, 'custom' AS source
     FROM rename_global_custom
     UNION ALL
     SELECT g.id, g.name, g.gender, g.race, g.faction, g.is_enabled, 'base' AS source
     FROM rename_global g
     LEFT JOIN rename_global_custom c ON LOWER(g.name) = LOWER(c.name)
     WHERE c.name IS NULL
     ORDER BY name"
);

$summary = ['total' => count($allRows), 'enabled' => 0, 'disabled' => 0, 'custom' => 0];
$races = [];
$factions = [];
foreach ($allRows as $row) {
    $enabled = stobeRenameNameBool($row['is_enabled'] ?? true);
    $summary[$enabled ? 'enabled' : 'disabled']++;
    if (strval($row['source'] ?? '') === 'custom') {
        $summary['custom']++;
    }
    $race = trim(strval($row['race'] ?? ''));
    $faction = trim(strval($row['faction'] ?? ''));
    if ($race !== '') {
        $races[strtolower($race)] = $race;
    }
    if ($faction !== '') {
        $factions[strtolower($faction)] = $faction;
    }
}
natcasesort($races);
natcasesort($factions);

$rows = array_values(array_filter($allRows, static function (array $row) use ($filterParams): bool {
    $enabled = stobeRenameNameBool($row['is_enabled'] ?? true);
    if ($filterParams['state'] !== 'all' && $filterParams['state'] !== ($enabled ? 'enabled' : 'disabled')) {
        return false;
    }
    if ($filterParams['source'] !== 'all' && $filterParams['source'] !== strval($row['source'] ?? '')) {
        return false;
    }
    $gender = strtolower(trim(strval($row['gender'] ?? '')));
    if ($filterParams['gender'] === 'blank' && $gender !== '') {
        return false;
    }
    if (!in_array($filterParams['gender'], ['all', 'blank'], true) && $filterParams['gender'] !== $gender) {
        return false;
    }
    if ($filterParams['race'] !== 'all' && strcasecmp($filterParams['race'], trim(strval($row['race'] ?? ''))) !== 0) {
        return false;
    }
    if ($filterParams['faction'] !== 'all' && strcasecmp($filterParams['faction'], trim(strval($row['faction'] ?? ''))) !== 0) {
        return false;
    }
    $search = strtolower($filterParams['q']);
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            strval($row['name'] ?? ''),
            strval($row['gender'] ?? ''),
            strval($row['race'] ?? ''),
            strval($row['faction'] ?? ''),
        ]));
        if (strpos($haystack, $search) === false) {
            return false;
        }
    }
    return true;
}));

$editRow = false;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editRow = $db->fetchOne(
        'SELECT id, name, gender, race, faction, is_enabled FROM rename_global_custom WHERE id = $1',
        [$editId]
    );
}

$noticeMap = [
    'saved' => ['ok', 'Custom name saved.'],
    'deleted' => ['ok', 'Custom name deleted.'],
    'toggled' => ['ok', 'Name state updated.'],
    'validation_error' => ['error', 'The custom name could not be saved. Check the fields and duplicate names.'],
    'import_file_error' => ['error', 'The CSV file could not be read.'],
    'error' => ['error', 'The requested change could not be completed.'],
];
$notice = strval($_GET['notice'] ?? '');
$noticeData = $noticeMap[$notice] ?? null;
$importSummary = null;
if ($notice === 'imported' && isset($_GET['import_result'])) {
    $decoded = json_decode(strval(base64_decode(strval($_GET['import_result']), true)), true);
    if (is_array($decoded)) {
        $importSummary = $decoded;
        $noticeData = ['ok', sprintf(
            'Import complete: %d added, %d updated, %d invalid, %d failed.',
            intval($decoded['inserted'] ?? 0),
            intval($decoded['updated'] ?? 0),
            intval($decoded['invalid'] ?? 0),
            intval($decoded['failed'] ?? 0)
        )];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rename Names - Stobe</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: 30px;
            padding-bottom: 40px;
            padding-left: 5px;
            padding-right: 5px;
            width: 100%;
            margin: 0;
        }
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .page-header h1.api-title {
            margin-bottom: 8px;
        }
        h1.api-title {
            margin: 0 0 20px 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #e6b76c;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h1.api-title, h1.api-title * {
            font-family: "MagicCards", serif !important;
        }
        .page-subtitle {
            margin: 0;
            color: #bbb;
            font-size: 1.1em;
            line-height: 1.6;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        .content-section {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .content-section:hover {
            border-color: #4a4a4a;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
        }
        .content-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            margin-top: 0;
            font-size: 1.4em;
        }
        .info-panel p {
            margin: 0;
            color: #c9d3e5;
            line-height: 1.55;
        }
        .full-width-section {
            grid-column: 1 / -1;
        }
        .full-width-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            font-size: 1.6em;
            text-align: center;
        }
        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            color: #e6b76c;
            font-weight: bold;
        }
        input[type="text"], input[type="file"], textarea {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 10px;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
            background: rgba(26, 26, 26, 0.8);
            color: #e9efff;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        input[type="text"]:focus, textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
            background: rgba(34, 34, 34, 0.9);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .action-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .search-container {
            display: flex;
            gap: 10px;
            min-width: 300px;
        }
        .search-container input[type="text"] {
            flex: 1;
        }
        .filter-section {
            margin-bottom: 20px;
            text-align: center;
        }
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin: 10px 0;
            justify-content: center;
        }
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-top: 20px;
            max-height: calc(100vh - 320px);
            overflow-y: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        }
        .table-container th {
            position: sticky;
            top: 0;
            background: linear-gradient(135deg, rgba(58, 58, 58, 0.95), rgba(48, 48, 48, 0.95));
            color: #e6b76c;
            padding: 12px 10px;
            text-align: left;
            font-family: "MagicCards", serif;
            letter-spacing: 1px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
            z-index: 10;
        }
        .table-container td {
            padding: 10px;
            border-bottom: 1px solid #3a3a3a;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .item-image-cell {
            width: 72px;
            min-width: 72px;
            text-align: center;
        }
        .item-image-thumb {
            width: 54px;
            height: 54px;
            object-fit: contain;
            border-radius: 6px;
            border: 1px solid #4a4a4a;
            background: rgba(20, 20, 20, 0.9);
            padding: 3px;
        }
        .item-image-missing {
            display: inline-block;
            width: 54px;
            height: 54px;
            line-height: 54px;
            border-radius: 6px;
            border: 1px dashed #4a4a4a;
            color: #8e9aab;
            background: rgba(20, 20, 20, 0.7);
            font-size: 12px;
        }
        .table-container tr:hover {
            background: rgba(58, 58, 58, 0.5);
        }
        .toast-notification {
            position: fixed;
            top: 24px;
            right: 24px;
            min-width: 280px;
            max-width: 560px;
            background: rgba(19, 24, 31, 0.96);
            color: #e9efff;
            border: 1px solid rgba(138, 155, 182, 0.38);
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            transform: translateY(-6px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 9999;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid #4a4a4a;
        }
        .status-pill.custom {
            color: #6dd19c;
            border-color: rgba(109, 209, 156, 0.45);
            background: rgba(25, 77, 50, 0.3);
        }
        .status-pill.base {
            color: #c9d3e5;
            border-color: rgba(138, 155, 182, 0.35);
            background: rgba(55, 66, 84, 0.28);
        }
        @media (max-width: 1024px) {
            main {
                padding-left: 4%;
                padding-right: 4%;
            }
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification"><span class="message"></span></div>

    <div class="page-header">
        <h1 class="api-title">Rename Names</h1>
        <p class="page-subtitle">Manage the lore-name pool used when Stobe replaces generic NPC names</p>
    </div>

    <div class="content-grid">
        <div class="content-section">
            <h2>CSV Upload</h2>
            <p>A one-column list works with or without a <code>name</code> header. The full format is <code>name, gender, race, faction, enabled</code>. Missing enabled values default to enabled.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="embed" value="<?= $isEmbed ? '1' : '0' ?>">
                <?php foreach ($filterParams as $key => $value): ?><input type="hidden" name="<?= renameNamesH($key) ?>" value="<?= renameNamesH($value) ?>"><?php endforeach; ?>
                <label for="csv_file">Select .csv file to upload:</label>
                <input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                    <button type="submit" class="action-button upload-csv">Upload CSV</button>
                    <a class="action-button download-csv" href="<?= renameNamesH(renameNamesUrl(['action' => 'download_example'], $isEmbed)) ?>">Download Example CSV</a>
                    <a class="action-button export-csv" href="<?= renameNamesH(renameNamesUrl(['action' => 'export_custom'], $isEmbed)) ?>">Export Custom Names</a>
                </div>
            </form>
        </div>

        <div class="content-section info-panel">
            <h2>Rename Names</h2>
            <p>Custom rows override matching base names. Disabled rows remain saved but are skipped when Stobe assigns a lore-friendly name. Current pool: <strong id="countTotal"><?= intval($summary['total']) ?></strong> rows, <strong id="countEnabled"><?= intval($summary['enabled']) ?></strong> enabled, <strong id="countDisabled"><?= intval($summary['disabled']) ?></strong> disabled, and <strong id="countCustom"><?= intval($summary['custom']) ?></strong> custom.</p>
        </div>

        <div class="content-section full-width-section">
            <div id="entries"></div>

            <div class="action-container">
                <button type="button" onclick="openNameModal()" class="action-button add-new">Add New Name</button>
                <form class="search-container" method="get" action="">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="state" value="<?= renameNamesH($filterParams['state']) ?>">
                    <input type="hidden" name="source" value="<?= renameNamesH($filterParams['source']) ?>">
                    <input type="hidden" name="gender" value="<?= renameNamesH($filterParams['gender']) ?>">
                    <input type="hidden" name="race" value="<?= renameNamesH($filterParams['race']) ?>">
                    <input type="hidden" name="faction" value="<?= renameNamesH($filterParams['faction']) ?>">
                    <input type="text" name="q" placeholder="Search" value="<?= renameNamesH($filterParams['q']) ?>">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= renameNamesH(renameNamesUrl([], $isEmbed)) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter Entries:</strong>
                <form class="filter-buttons" method="get" action="">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($filterParams['q'] !== ''): ?><input type="hidden" name="q" value="<?= renameNamesH($filterParams['q']) ?>"><?php endif; ?>
                    <select class="form-select w-auto" name="state" aria-label="State filter">
                        <?php foreach (['all'=>'All States','enabled'=>'Enabled','disabled'=>'Disabled'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filterParams['state']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select w-auto" name="source" aria-label="Source filter">
                        <?php foreach (['all'=>'All Sources','base'=>'Base','custom'=>'Custom'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filterParams['source']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select w-auto" name="gender" aria-label="Gender filter">
                        <?php foreach (['all'=>'All Genders','male'=>'Male','female'=>'Female','neutral'=>'Neutral','blank'=>'Any/blank'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filterParams['gender']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select w-auto" name="race" aria-label="Race filter">
                        <option value="all">All Races</option>
                        <?php foreach ($races as $value): ?><option value="<?= renameNamesH($value) ?>" <?= $filterParams['race']===$value?'selected':'' ?>><?= renameNamesH($value) ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select w-auto" name="faction" aria-label="Faction filter">
                        <option value="all">All Factions</option>
                        <?php foreach ($factions as $value): ?><option value="<?= renameNamesH($value) ?>" <?= $filterParams['faction']===$value?'selected':'' ?>><?= renameNamesH($value) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="action-button edit">Apply Filters</button>
                </form>
            </div>

            <div class="table-container">
                <table>
                    <tr><th>Name</th><th>Gender</th><th>Race</th><th>Faction</th><th>Source</th><th>State</th><th>Actions</th></tr>
                    <?php if (count($rows) === 0): ?><tr><td colspan="7">No names match these filters.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $enabled = stobeRenameNameBool($row['is_enabled'] ?? true);
                        $source = strval($row['source'] ?? 'base');
                        $jsData = json_encode([
                            'id' => intval($row['id'] ?? 0),
                            'name' => strval($row['name'] ?? ''),
                            'gender' => strval($row['gender'] ?? ''),
                            'race' => strval($row['race'] ?? ''),
                            'faction' => strval($row['faction'] ?? ''),
                            'is_enabled' => $enabled,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr data-name-row data-enabled="<?= $enabled ? '1' : '0' ?>" data-source="<?= renameNamesH($source) ?>">
                            <td><?= renameNamesH($row['name'] ?? '') ?></td>
                            <td><?= renameNamesH(trim(strval($row['gender'] ?? '')) ?: 'Any') ?></td>
                            <td><?= renameNamesH(trim(strval($row['race'] ?? '')) ?: 'Any') ?></td>
                            <td><?= renameNamesH(trim(strval($row['faction'] ?? '')) ?: 'Any') ?></td>
                            <td><span class="status-pill <?= $source === 'custom' ? 'custom' : 'base' ?>"><?= renameNamesH($source) ?></span></td>
                            <td><span class="status-pill <?= $enabled ? 'custom' : 'base' ?> state-label"><?= $enabled ? 'Enabled' : 'Disabled' ?></span></td>
                            <td>
                                <form class="toggle-form" method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="row_id" value="<?= intval($row['id'] ?? 0) ?>">
                                    <input type="hidden" name="target_enabled" value="<?= $enabled ? '0' : '1' ?>">
                                    <input type="hidden" name="embed" value="<?= $isEmbed ? '1' : '0' ?>">
                                    <?php foreach ($filterParams as $key => $value): ?><input type="hidden" name="<?= renameNamesH($key) ?>" value="<?= renameNamesH($value) ?>"><?php endforeach; ?>
                                    <input type="hidden" name="source" value="<?= renameNamesH($source) ?>">
                                    <button type="submit" class="<?= $enabled ? 'btn-danger' : 'btn-save' ?>"><?= $enabled ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <?php if ($source === 'custom'): ?>
                                    <button type="button" onclick='openNameModal(<?= renameNamesH($jsData) ?>)' class="action-button edit">Edit</button>
                                    <form class="delete-form" method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="row_id" value="<?= intval($row['id'] ?? 0) ?>">
                                        <input type="hidden" name="embed" value="<?= $isEmbed ? '1' : '0' ?>">
                                        <button class="btn-danger" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="nameModal" class="modal-backdrop" style="display:none;">
    <div class="modal-container">
        <div class="modal-header"><h2 id="nameModalTitle" class="modal-title">Add New Name</h2></div>
        <div class="modal-body">
            <form action="" method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="embed" value="<?= $isEmbed ? '1' : '0' ?>">
                <input type="hidden" name="row_id" id="name_row_id" value="0">
                <?php foreach ($filterParams as $key => $value): ?><input type="hidden" name="<?= renameNamesH($key) ?>" value="<?= renameNamesH($value) ?>"><?php endforeach; ?>

                <label for="name_name">Name:</label>
                <input type="text" name="name" id="name_name" maxlength="128" required>

                <label for="name_gender">Gender match:</label>
                <select class="form-select" name="gender" id="name_gender">
                    <option value="">Any gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="neutral">Neutral</option>
                </select>

                <label for="name_race">Race match (optional):</label>
                <input type="text" name="race" id="name_race" maxlength="64">

                <label for="name_faction">Faction match (optional):</label>
                <input type="text" name="faction" id="name_faction" maxlength="128">

                <input type="hidden" name="is_enabled" value="0">
                <label><input type="checkbox" name="is_enabled" id="name_enabled" value="1" checked> Enabled</label>

                <div class="modal-footer">
                    <button type="submit" class="btn-save">Save Name</button>
                    <button type="button" onclick="closeNameModal()" class="btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openNameModal(data = null) {
    const row = data || {};
    document.getElementById('nameModalTitle').textContent = data ? 'Edit Custom Name' : 'Add New Name';
    document.getElementById('name_row_id').value = row.id || 0;
    document.getElementById('name_name').value = row.name || '';
    document.getElementById('name_gender').value = row.gender || '';
    document.getElementById('name_race').value = row.race || '';
    document.getElementById('name_faction').value = row.faction || '';
    document.getElementById('name_enabled').checked = data ? Boolean(row.is_enabled) : true;
    document.getElementById('nameModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeNameModal() {
    document.getElementById('nameModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}
function showToast(message, duration = 5000) {
    const toast = document.getElementById('toast');
    const messageSpan = toast.querySelector('.message');
    messageSpan.textContent = message;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}
window.addEventListener('click', function (event) {
    const modal = document.getElementById('nameModal');
    if (event.target === modal) {
        closeNameModal();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const activeStateFilter = <?= json_encode($filterParams['state']) ?>;
    function adjustCount(id, delta) {
        const element = document.getElementById(id);
        if (element) element.textContent = String(Math.max(0, Number(element.textContent || 0) + delta));
    }
    document.querySelectorAll('.toggle-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button');
            const target = form.querySelector('[name="target_enabled"]');
            const row = form.closest('[data-name-row]');
            button.disabled = true;
            try {
                const response = await fetch(window.location.href, { method:'POST', body:new FormData(form), headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'} });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Could not update name state.');
                const wasEnabled = row.dataset.enabled === '1';
                const enabled = Boolean(result.enabled);
                row.dataset.enabled = enabled ? '1' : '0';
                target.value = enabled ? '0' : '1';
                const label = row.querySelector('.state-label');
                label.textContent = enabled ? 'Enabled' : 'Disabled';
                label.classList.toggle('custom', enabled);
                label.classList.toggle('base', !enabled);
                button.textContent = enabled ? 'Disable' : 'Enable';
                button.classList.toggle('btn-danger', enabled);
                button.classList.toggle('btn-save', !enabled);
                if (wasEnabled !== enabled) {
                    adjustCount('countEnabled', enabled ? 1 : -1);
                    adjustCount('countDisabled', enabled ? -1 : 1);
                }
                if (activeStateFilter !== 'all' && activeStateFilter !== (enabled ? 'enabled' : 'disabled')) row.remove();
                showToast(result.message || 'Name state updated.');
            } catch (error) { showToast(error.message || 'Could not update name state.'); }
            finally { button.disabled = false; }
        });
    });
    document.querySelectorAll('.delete-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm('Delete this custom name?')) return;
            const row = form.closest('[data-name-row]');
            try {
                const response = await fetch(window.location.href, { method:'POST', body:new FormData(form), headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'} });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Could not delete custom name.');
                adjustCount('countTotal', -1);
                adjustCount('countCustom', -1);
                adjustCount(row.dataset.enabled === '1' ? 'countEnabled' : 'countDisabled', -1);
                row.remove();
                showToast(result.message || 'Custom name deleted.');
            } catch (error) { showToast(error.message || 'Could not delete custom name.'); }
        });
    });
    <?php if (is_array($noticeData)): ?>
    showToast(<?= json_encode(strval($noticeData[1] ?? '')) ?>);
    <?php endif; ?>
    <?php if (is_array($editRow)): ?>
    openNameModal(<?= json_encode([
        'id' => intval($editRow['id'] ?? 0),
        'name' => strval($editRow['name'] ?? ''),
        'gender' => strval($editRow['gender'] ?? ''),
        'race' => strval($editRow['race'] ?? ''),
        'faction' => strval($editRow['faction'] ?? ''),
        'is_enabled' => stobeRenameNameBool($editRow['is_enabled'] ?? true),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    <?php endif; ?>
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
