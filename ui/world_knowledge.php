<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "world_knowledge_aliases.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8");
}

function world_knowledgeTrim(mixed $value): string
{
    return trim(strval($value));
}

function world_knowledgeNormalizeHeader(mixed $value): string
{
    $key = preg_replace('/^\xEF\xBB\xBF/', '', strval($value));
    $key = strtolower(trim(strval($key)));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim(strval($key), '_');
}

function world_knowledgePreferredDescription(array $row): string
{
    $basic = world_knowledgeTrim($row['topic_desc_basic'] ?? '');
    if ($basic !== '') {
        return $basic;
    }
    return world_knowledgeTrim($row['topic_desc'] ?? '');
}

function world_knowledgeUpdateNativeVector(int $id): void
{
    if ($id <= 0) {
        return;
    }
    stobeWorldKnowledgeUpdateNativeVector($GLOBALS["db"], $id);
}

function world_knowledgeUpsertByTopic(array $payload): int
{
    $db = $GLOBALS["db"];
    $topic = world_knowledgeTrim($payload['topic'] ?? '');
    if ($topic === '') {
        return 0;
    }

    $existing = $db->fetchOne(
        "SELECT id, knowledge_class, knowledge_class_basic
         FROM world_knowledge
         WHERE LOWER(topic) = LOWER($1)
         LIMIT 1",
        [$topic]
    );
    $topicDesc = array_key_exists('topic_desc', $payload)
        ? world_knowledgeTrim($payload['topic_desc'] ?? '')
        : '';
    $topicDescBasic = array_key_exists('topic_desc_basic', $payload)
        ? world_knowledgeTrim($payload['topic_desc_basic'] ?? '')
        : '';
    $knowledgeClass = array_key_exists('knowledge_class', $payload)
        ? world_knowledgeTrim($payload['knowledge_class'] ?? '')
        : null;
    $knowledgeClassBasic = array_key_exists('knowledge_class_basic', $payload)
        ? world_knowledgeTrim($payload['knowledge_class_basic'] ?? '')
        : null;
    if ($existing) {
        $id = intval($existing['id'] ?? 0);
        if ($id > 0) {
            if ($knowledgeClass === null) {
                $knowledgeClass = world_knowledgeTrim($existing['knowledge_class'] ?? '');
            }
            if ($knowledgeClassBasic === null) {
                $knowledgeClassBasic = world_knowledgeTrim($existing['knowledge_class_basic'] ?? '');
            }
            $db->exec(
                "UPDATE world_knowledge
                 SET topic = $1,
                     topic_desc = $2,
                     topic_desc_basic = $3,
                     knowledge_class = $4,
                     knowledge_class_basic = $5,
                     aliases = $6,
                     tags = $7
                 WHERE id = $8",
                [
                    $topic,
                    $topicDesc,
                    $topicDescBasic,
                    strval($knowledgeClass ?? ''),
                    strval($knowledgeClassBasic ?? ''),
                    world_knowledgeTrim($payload['aliases'] ?? ''),
                    world_knowledgeTrim($payload['tags'] ?? ''),
                    $id,
                ]
            );
            world_knowledgeUpdateNativeVector($id);
            return $id;
        }
    }

    $inserted = $db->fetchOne(
        "INSERT INTO world_knowledge (
            topic, topic_desc, topic_desc_basic,
            knowledge_class, knowledge_class_basic,
            aliases, tags
         ) VALUES ($1,$2,$3,$4,$5,$6,$7)
         RETURNING id",
        [
            $topic,
            $topicDesc,
            $topicDescBasic,
            strval($knowledgeClass ?? ''),
            strval($knowledgeClassBasic ?? ''),
            world_knowledgeTrim($payload['aliases'] ?? ''),
            world_knowledgeTrim($payload['tags'] ?? ''),
        ]
    );
    $id = intval($inserted['id'] ?? 0);
    if ($id > 0) {
        world_knowledgeUpdateNativeVector($id);
    }
    return $id;
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';
$withEmbed = function (string $url) use ($isEmbed): string {
    return $isEmbed ? ($url . (strpos($url, '?') === false ? '?embed=1' : '&embed=1')) : $url;
};

$message = '';
$messageType = 'ok';
$editRow = false;

if (isset($_GET['action']) && $_GET['action'] === 'download_example') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="world_knowledge_example.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['topic', 'topic_desc', 'topic_desc_basic', 'knowledge_class', 'knowledge_class_basic', 'aliases', 'tags']);
    fputcsv($out, [
        'trade_ninjas',
        'A covert trade-focused faction with strict internal discipline.',
        'Trade Ninjas are covert traders with strict internal discipline.',
        '',
        '',
        'trade ninjas, trade_ninjas',
        'factions,trade',
    ]);
    fclose($out);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_custom_descriptions') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="world_knowledge_export_' . date('Y-m-d_H-i-s') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['topic', 'topic_desc', 'topic_desc_basic', 'knowledge_class', 'knowledge_class_basic', 'aliases', 'tags']);
    $exportRows = $GLOBALS["db"]->fetchAll(
        "SELECT topic, topic_desc, topic_desc_basic, knowledge_class, knowledge_class_basic, aliases, tags
         FROM world_knowledge
         ORDER BY LOWER(topic) ASC"
    );
    foreach ($exportRows as $row) {
        fputcsv($out, [
            strval($row['topic'] ?? ''),
            strval($row['topic_desc'] ?? ''),
            strval($row['topic_desc_basic'] ?? ''),
            strval($row['knowledge_class'] ?? ''),
            strval($row['knowledge_class_basic'] ?? ''),
            strval($row['aliases'] ?? ''),
            strval($row['tags'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_entry'])) {
        $description = world_knowledgeTrim($_POST['description'] ?? '');
        $payload = [
            'topic' => $_POST['topic'] ?? '',
            'topic_desc' => $description,
            'topic_desc_basic' => $description,
            'aliases' => $_POST['aliases'] ?? '',
            'tags' => $_POST['tags'] ?? '',
        ];
        if (world_knowledgeTrim($payload['topic']) === '' || world_knowledgeTrim($payload['topic_desc']) === '') {
            $message = "Topic and description are required.";
            $messageType = 'err';
        } else {
            $savedId = world_knowledgeUpsertByTopic($payload);
            if ($savedId > 0) {
                header('Location: ' . $withEmbed('world_knowledge.php?ok=saved'));
                exit;
            }
            $message = "Failed to save entry.";
            $messageType = 'err';
        }
    } elseif (isset($_POST['delete_entry'])) {
        $deleteId = intval($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $GLOBALS["db"]->exec("DELETE FROM world_knowledge WHERE id = $1", [$deleteId]);
        }
        header('Location: ' . $withEmbed('world_knowledge.php?ok=deleted'));
        exit;
    } elseif (isset($_POST['delete_all'])) {
        $GLOBALS["db"]->exec("TRUNCATE TABLE world_knowledge RESTART IDENTITY");
        header('Location: ' . $withEmbed('world_knowledge.php?ok=deleted_all'));
        exit;
    } elseif (isset($_POST['import_csv'])) {
        $importCount = 0;
        if (!isset($_FILES['csv_file']) || intval($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = "CSV upload failed.";
            $messageType = 'err';
        } else {
            $tmp = strval($_FILES['csv_file']['tmp_name'] ?? '');
            $handle = @fopen($tmp, 'r');
            if (!$handle) {
                $message = "Could not open uploaded CSV file.";
                $messageType = 'err';
            } else {
                $header = fgetcsv($handle, 0, ',');
                $map = [];
                if (is_array($header)) {
                    foreach ($header as $idx => $name) {
                        $key = world_knowledgeNormalizeHeader($name);
                        if ($key !== '') {
                            $map[$key] = intval($idx);
                        }
                    }
                }

                while (($row = fgetcsv($handle, 0, ',')) !== false) {
                    if (!is_array($row) || count($row) === 0) {
                        continue;
                    }
                    if (count(array_filter($row, static function ($value): bool {
                        return trim(strval($value)) !== '';
                    })) === 0) {
                        continue;
                    }
                    $pick = static function (array $rowData, array $columnMap, array $aliases, int $fallbackIndex = -1): string {
                        foreach ($aliases as $alias) {
                            $k = world_knowledgeNormalizeHeader($alias);
                            if ($k !== '' && array_key_exists($k, $columnMap)) {
                                $idx = intval($columnMap[$k]);
                                return trim(strval($rowData[$idx] ?? ''));
                            }
                        }
                        if ($fallbackIndex >= 0) {
                            return trim(strval($rowData[$fallbackIndex] ?? ''));
                        }
                        return '';
                    };

                    $payload = [
                        'topic' => $pick($row, $map, ['topic', 'stringid', 'baseid'], 0),
                        'topic_desc' => $pick($row, $map, ['topic_desc', 'description'], 2),
                        'knowledge_class' => $pick($row, $map, ['knowledge_class']),
                        'topic_desc_basic' => $pick($row, $map, ['topic_desc_basic', 'basic_description'], 3),
                        'knowledge_class_basic' => $pick($row, $map, ['knowledge_class_basic']),
                        'aliases' => $pick($row, $map, ['aliases']),
                        'tags' => $pick($row, $map, ['tags', 'category', 'name'], 1),
                    ];
                    if ($payload['topic'] === '' || ($payload['topic_desc'] === '' && $payload['topic_desc_basic'] === '')) {
                        continue;
                    }

                    $savedId = world_knowledgeUpsertByTopic($payload);
                    if ($savedId > 0) {
                        $importCount++;
                    }
                }
                fclose($handle);
                header('Location: ' . $withEmbed('world_knowledge.php?ok=imported&count=' . $importCount));
                exit;
            }
        }
    }
}

if (isset($_GET['ok'])) {
    $ok = strval($_GET['ok']);
    if ($ok === 'saved') {
        $message = "World Knowledge entry saved.";
    } elseif ($ok === 'deleted') {
        $message = "World Knowledge entry deleted.";
    } elseif ($ok === 'deleted_all') {
        $message = "All World Knowledge entries deleted.";
    } elseif ($ok === 'imported') {
        $count = intval($_GET['count'] ?? 0);
        $message = "CSV import completed: " . $count . " entries saved.";
    }
}

if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    if ($editId > 0) {
        $editRow = $GLOBALS["db"]->fetchOne("SELECT * FROM world_knowledge WHERE id = $1", [$editId]);
    }
}

$search = trim(strval($_GET['q'] ?? ''));
$letter = strtoupper(trim(strval($_GET['letter'] ?? '')));
if (!preg_match('/^[A-Z]$/', $letter)) {
    $letter = '';
}
$where = [];
$params = [];
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $p = '$' . count($params);
    $where[] = "(topic ILIKE {$p} OR topic_desc ILIKE {$p} OR topic_desc_basic ILIKE {$p} OR aliases ILIKE {$p} OR tags ILIKE {$p})";
}
if ($letter !== '') {
    $params[] = $letter . '%';
    $p = '$' . count($params);
    $where[] = "LOWER(COALESCE(topic, '')) LIKE LOWER({$p})";
}
$whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = $GLOBALS["db"]->fetchAll(
    "SELECT id, topic, topic_desc, topic_desc_basic, knowledge_class, knowledge_class_basic, aliases, tags
     FROM world_knowledge
     {$whereSql}
     ORDER BY LOWER(topic) ASC
    LIMIT 1000",
    $params
);

$TITLE = "World Knowledge";
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/head.html");
?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
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
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #3a3a3a;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
    text-align: center;
    margin-bottom: 30px;
}
.page-header h1.api-title { margin-bottom: 8px; }
.page-subtitle {
    color: #ffffff !important;
    font-size: 1.1em;
    margin: 0;
    font-family: var(--stobe-title-font) !important;
}
h1.api-title {
    margin: 0 0 20px 0;
    font-family: var(--stobe-title-font) !important;
    word-spacing: 8px;
    font-size: 2.2em;
    color: #ffffff !important;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    text-align: center;
}
h1.api-title, h1.api-title * {
    font-family: var(--stobe-title-font) !important;
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
    font-family: var(--stobe-title-font) !important;
    color: #ffffff !important;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
    word-spacing: 6px;
    margin-bottom: 15px;
    margin-top: 0;
    font-size: 1.4em;
}
.section-head h2,
.modal-title {
    font-family: var(--stobe-title-font) !important;
    color: #ffffff !important;
}
.info-panel p {
    margin: 0;
    color: #c9d3e5 !important;
    line-height: 1.55;
}
.full-width-section {
    grid-column: 1 / -1;
}
.full-width-section h2 {
    font-family: var(--stobe-title-font) !important;
    color: #ffffff !important;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
    word-spacing: 6px;
    margin-bottom: 15px;
    font-size: 1.6em;
    text-align: center;
}
.csv-upload-title {
    color: #ffffff !important;
    font-family: var(--stobe-title-font) !important;
}
.help { color: #9fb1c9; font-size: 12px; margin-top: 4px; }
.ok { color: #8ee0a2; }
.err { color: #ffb862; }
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
    background: rgba(26,26,26,.8);
    color: #e9efff;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
select {
    width: 100%;
    padding: 10px 12px;
    margin-bottom: 10px;
    border-radius: 6px;
    border: 1px solid #3a3a3a;
    background: rgba(26,26,26,.8);
    color: #e9efff;
    box-sizing: border-box;
}
input[type=text]:focus, textarea:focus {
    border-color: rgba(230, 183, 108, 0.5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
    background: rgba(34, 34, 34, 0.9);
}
.grid2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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
    font-family: var(--stobe-title-font) !important;
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
.table-container tr:hover {
    background: rgba(58, 58, 58, 0.5);
}
.mono {
    font-family: Consolas, monospace;
    font-size: 12px;
    color: #9fb1c9;
}
.section-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.action-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.search-container {
    display: flex;
    gap: 10px;
    min-width: 300px;
}
.search-container input[type="text"] {
    flex-grow: 1;
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
.modal-content {
    background: #2a2a2a;
    color: #f8f9fa;
    border: 1px solid #4a4a4a;
}
.modal-header, .modal-footer {
    border-color: #3a3a3a;
}
.modal .btn-close {
    filter: invert(1) grayscale(1);
}
@media (max-width:980px) {
    .grid2 { grid-template-columns: 1fr; }
    .content-grid { grid-template-columns: 1fr; }
}
</style>

<main>
    <div class="page-header">
        <h1 class="api-title">World Knowledge</h1>
        <p class="page-subtitle">Configure world knowledge entries used for prompt grounding</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="<?= $messageType === 'err' ? 'err' : 'ok' ?>" style="margin-bottom:10px;"><?= h($message) ?></div>
    <?php endif; ?>

    <div class="content-grid">
        <div class="content-section">
            <h2 class="csv-upload-title">CSV Upload</h2>
            <form method="post" action="<?= h($withEmbed('world_knowledge.php')) ?>" enctype="multipart/form-data">
                <label for="csv_file">Select .csv file to upload:</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                    <button type="submit" name="import_csv" value="1" class="action-button upload-csv">Upload CSV</button>
                    <a href="<?= h($withEmbed('world_knowledge.php?action=download_example')) ?>" class="action-button download-csv">Download Example CSV</a>
                    <a href="<?= h($withEmbed('world_knowledge.php?action=export_custom_descriptions')) ?>" class="action-button export-csv">Export Custom Descriptions</a>
                </div>
            </form>
        </div>

        <section class="content-section info-panel">
            <h2>World Knowledge</h2>
            <p>This page manages shared lore snippets that can be used to ground NPC responses. Entries are keyed by topic, tagged for organization, and can be maintained in bulk through CSV import/export.</p>
        </section>
    </div>

    <section class="content-section full-width-section">
        <div class="section-head">
            <h2 style="margin:0;">Entries</h2>
        </div>

        <div class="action-container">
            <button type="button" class="action-button add-new" data-bs-toggle="modal" data-bs-target="#worldKnowledgeModal"><?= $editRow ? 'Edit Entry' : 'Add Entry' ?></button>
            <form class="search-container" method="get" action="world_knowledge.php">
                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <?php if ($letter !== ''): ?><input type="hidden" name="letter" value="<?= h($letter) ?>"><?php endif; ?>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search">
                <button class="action-button edit" type="submit">Search</button>
                <a class="action-button" href="<?= h($withEmbed('world_knowledge.php')) ?>">Clear</a>
            </form>
        </div>

        <div class="filter-section">
            <strong>Filter by Topic:</strong>
            <div class="filter-buttons">
                <a class="alphabet-button" href="<?= h($withEmbed('world_knowledge.php?q=' . urlencode($search))) ?>">All</a>
                <?php foreach (range('A', 'Z') as $char): ?>
                    <a class="alphabet-button" href="<?= h($withEmbed('world_knowledge.php?q=' . urlencode($search) . '&letter=' . $char)) ?>"><?= h($char) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th style="width:220px;">Topic</th>
                        <th>Description</th>
                        <th style="width:220px;">Tags</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr><td colspan="5" class="mono">No entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="mono"><?= h($row['id'] ?? '') ?></td>
                            <td>
                                <div><strong><?= h($row['topic'] ?? '') ?></strong></div>
                                <?php if (trim(strval($row['aliases'] ?? '')) !== ''): ?>
                                    <div class="mono">aliases: <?= h($row['aliases']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= h(world_knowledgePreferredDescription($row)) ?></div>
                            </td>
                            <td><?= h($row['tags'] ?? '') ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <a class="action-button edit" href="<?= h($withEmbed('world_knowledge.php?edit=' . intval($row['id'] ?? 0) . '&q=' . urlencode($search) . '&letter=' . urlencode($letter))) ?>">Edit</a>
                                    <form method="post" action="<?= h($withEmbed('world_knowledge.php')) ?>" onsubmit="return confirm('Delete this entry?');">
                                        <input type="hidden" name="delete_id" value="<?= h($row['id'] ?? '') ?>">
                                        <button class="btn-danger" type="submit" name="delete_entry" value="1">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="modal fade" id="worldKnowledgeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $editRow ? 'Edit Entry' : 'Add Entry' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?= h($withEmbed('world_knowledge.php')) ?>">
                    <div class="modal-body">
                        <label for="topic">Topic</label>
                        <input id="topic" type="text" name="topic" value="<?= h($editRow['topic'] ?? '') ?>" required>
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4" required><?= h($editRow ? world_knowledgePreferredDescription($editRow) : '') ?></textarea>
                        <div class="grid2">
                            <div>
                                <label for="aliases">Aliases</label>
                                <input id="aliases" type="text" name="aliases" value="<?= h($editRow['aliases'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="tags">Tags</label>
                                <input id="tags" type="text" name="tags" value="<?= h($editRow['tags'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-save" type="submit" name="save_entry" value="1">Save Entry</button>
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const openModal = <?= $editRow ? 'true' : 'false' ?>;
    if (openModal) {
        const el = document.getElementById('worldKnowledgeModal');
        if (el) {
            const modal = new bootstrap.Modal(el);
            modal.show();
        }
    }
});
</script>

<?php
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;
?>

