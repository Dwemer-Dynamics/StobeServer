<?php

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wkAuditWebRoot(): string
{
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $root = dirname(dirname($scriptPath));
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    return rtrim($root, '/');
}

function wkAuditParseKeyValueString(string $raw, string $separator): array
{
    $pairs = [];
    foreach (explode($separator, $raw) as $part) {
        $piece = trim($part);
        if ($piece === '') {
            continue;
        }
        $pos = strpos($piece, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($piece, 0, $pos));
        $value = trim(substr($piece, $pos + 1));
        if ($key === '') {
            continue;
        }
        $pairs[$key] = $value;
    }
    return $pairs;
}

function wkAuditFetchRows(int $limit = 200): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    try {
        return $db->fetchAll(
            'SELECT created_at, input, keywords, rank_any, rank_all, memory, "time"
             FROM audit_memory
             ORDER BY created_at DESC
             LIMIT ' . intval(max(20, min(500, $limit)))
        );
    } catch (Throwable $exception) {
        return [];
    }
}

$isEmbed = (isset($_GET['embed']) && strval($_GET['embed']) === '1');
$webRoot = wkAuditWebRoot();
$rows = wkAuditFetchRows(200);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>World Knowledge Audit</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <?php if (!$isEmbed): ?>
        <link rel="stylesheet" href="css/navbar.css">
    <?php endif; ?>
    <style>
        body { background:#1f1f1f; color:#e7e7e7; }
        main.page-wrap { padding: <?= $isEmbed ? '20px' : '110px' ?> 12px 32px; }
        .page-header, .audit-card {
            background: linear-gradient(180deg, rgba(42,42,42,.96), rgba(30,30,30,.98));
            border: 1px solid #3b3b3b;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }
        .page-header { padding: 18px; margin-bottom: 18px; text-align: center; }
        .audit-card { padding: 14px; margin-bottom: 14px; }
        .meta-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .meta-pill {
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(230,183,108,.2);
            border-radius: 8px;
            padding: 8px 10px;
        }
        .meta-label { color:#d7b37a; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .meta-value { font-size:.92rem; word-break: break-word; }
        .section-label { color:#d7b37a; font-weight:700; margin-bottom:4px; }
        .trace-box {
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 8px;
            padding: 10px;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: Consolas, Monaco, monospace;
            font-size: .85rem;
        }
        .search-wrap { margin-bottom: 14px; }
        .search-input {
            width: 100%; background:#111; color:#f2f2f2; border:1px solid #4a4a4a; border-radius:8px; padding:10px 12px;
        }
        .empty-state { padding: 20px; text-align:center; color:#aaa; }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>
<?php endif; ?>
<main class="page-wrap container-fluid">
    <div class="page-header">
        <h1>World Knowledge Audit</h1>
        <div>See whether retrieval matched, which topic won, what rank it got, and which signals were used.</div>
    </div>

    <div class="search-wrap">
        <input id="auditSearch" class="search-input" type="text" placeholder="Filter by NPC input, selected topic, signals, notes...">
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="audit-card empty-state">No rows in audit_memory yet.</div>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <?php
                $input = strval($row['input'] ?? '');
                $keywords = strval($row['keywords'] ?? '');
                $memory = strval($row['memory'] ?? '');
                $rank = strval($row['rank_any'] ?? '0');
                $elapsed = strval($row['time'] ?? '');
                $created = strval($row['created_at'] ?? '');
                $keywordMap = wkAuditParseKeyValueString($keywords, ' | ');
                $memoryMap = wkAuditParseKeyValueString($memory, ' / ');
                $selected = strval($memoryMap['selected'] ?? '');
                $selectedMode = strval($memoryMap['mode'] ?? '');
                $entryId = strval($memoryMap['entry_id'] ?? '');
                $topics = strval($keywordMap['topics'] ?? '');
                $notes = strval($keywordMap['notes'] ?? '');
                $signals = strval($keywordMap['signals'] ?? ($memoryMap['signals'] ?? ''));
                $context = strval($memoryMap['context'] ?? '');
                $location = strval($memoryMap['location'] ?? '');
                $status = $selected !== '' ? 'Matched' : 'No Match';
                $searchBlob = strtolower(implode(' ', [$input, $selected, $topics, $notes, $signals, $context, $location, $created]));
            ?>
            <section class="audit-card" data-search="<?= h($searchBlob) ?>">
                <div class="meta-grid">
                    <div class="meta-pill"><div class="meta-label">Status</div><div class="meta-value"><?= h($status) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Selected Topic</div><div class="meta-value"><?= h($selected !== '' ? $selected : '(none)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Rank</div><div class="meta-value"><?= h($rank) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Mode</div><div class="meta-value"><?= h($selectedMode !== '' ? $selectedMode : '(n/a)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Entry ID</div><div class="meta-value"><?= h($entryId !== '' ? $entryId : '(n/a)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Created</div><div class="meta-value"><?= h($created) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Elapsed</div><div class="meta-value"><?= h($elapsed) ?></div></div>
                </div>

                <div class="section-label">Player Input</div>
                <div class="trace-box"><?= h($input) ?></div>

                <div class="section-label" style="margin-top:10px;">Extracted Topics</div>
                <div class="trace-box"><?= h($topics !== '' ? $topics : '(none)') ?></div>

                <div class="section-label" style="margin-top:10px;">Signals Used For Ranking</div>
                <div class="trace-box"><?= h($signals !== '' ? $signals : '(not captured)') ?></div>

                <div class="section-label" style="margin-top:10px;">Ranking Notes</div>
                <div class="trace-box"><?= h($notes !== '' ? $notes : '(none)') ?></div>

                <div class="section-label" style="margin-top:10px;">Context Snapshot</div>
                <div class="trace-box"><?php
                    $contextParts = [];
                    if ($location !== '') { $contextParts[] = 'location=' . $location; }
                    if ($context !== '') { $contextParts[] = 'context=' . $context; }
                    if (isset($memoryMap['before'])) { $contextParts[] = 'before=' . strval($memoryMap['before']); }
                    if (isset($memoryMap['after'])) { $contextParts[] = 'after=' . strval($memoryMap['after']); }
                    if (isset($memoryMap['tags'])) { $contextParts[] = 'tags=' . strval($memoryMap['tags']); }
                    echo h(count($contextParts) > 0 ? implode("\n", $contextParts) : '(none)');
                ?></div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<script>
const searchInput = document.getElementById('auditSearch');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const needle = String(searchInput.value || '').trim().toLowerCase();
    document.querySelectorAll('[data-search]').forEach((card) => {
      const hay = String(card.getAttribute('data-search') || '').toLowerCase();
      card.style.display = (needle === '' || hay.includes(needle)) ? '' : 'none';
    });
  });
}
</script>
</body>
</html>