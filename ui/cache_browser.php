<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

// Determine web root for URL building.
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

function stobeCacheFormatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function stobeCacheScanFiles(string $root, array $extensions, int $limit = 300): array
{
    $items = [];
    if (!is_dir($root)) {
        return $items;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            $items[] = [
                'name' => $fileInfo->getFilename(),
                'path' => $fileInfo->getPathname(),
                'mtime' => $fileInfo->getMTime(),
                'size' => $fileInfo->getSize(),
                'ext' => $ext,
            ];
        }
    } catch (Throwable $e) {
        return $items;
    }

    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });

    return array_slice($items, 0, $limit);
}

$soundRoot = $enginePath . 'soundcache';
$galleryRoot = $enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery';

$audioFiles = stobeCacheScanFiles($soundRoot, ['wav', 'mp3', 'ogg'], 300);
$imageFiles = stobeCacheScanFiles($galleryRoot, ['jpg', 'jpeg', 'png', 'webp', 'mp4'], 300);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stobe Cache</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
    <style>
        body { background:var(--stobe-bg, #171717); color:var(--stobe-text, #f5f1e8); margin:0; }
        main { padding: <?php echo $isEmbed ? '14px' : '80px 14px 20px'; ?>; }
        .cache-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
        .cache-title { margin:0; color:var(--stobe-accent-bright, #f4cd8a); font-size:24px; font-weight:400; }
        .cache-meta { color:var(--stobe-text-muted, #aaa69e); font-size:13px; margin-top:4px; }
        .cache-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .cache-btn { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:6px 10px; border:1px solid var(--stobe-border, #404040); border-radius:6px; background:var(--stobe-surface-2, #2b2b2b); color:var(--stobe-text, #f5f1e8); text-decoration:none; }
        .cache-btn:hover { border-color:var(--stobe-accent, #e6b76c); color:var(--stobe-accent-bright, #f4cd8a); }
        .cache-grid { display:grid; grid-template-columns:minmax(0, 1.35fr) minmax(320px, .65fr); gap:14px; }
        .cache-panel { border:1px solid var(--stobe-border, #404040); border-radius:8px; background:var(--stobe-surface-1, #232323); overflow:hidden; }
        .cache-panel h2 { margin:0; padding:10px 12px; font-size:17px; font-weight:400; color:var(--stobe-text, #f5f1e8); background:var(--stobe-surface-2, #2b2b2b); border-bottom:1px solid var(--stobe-border, #404040); }
        .cache-list { display:flex; flex-direction:column; }
        .cache-row { display:grid; grid-template-columns:minmax(220px, 1fr) 130px 140px 220px; gap:10px; align-items:center; padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.06); }
        .cache-row:last-child { border-bottom:none; }
        .cache-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#f7f7f7; }
        .cache-small { color:var(--stobe-text-muted, #aaa69e); font-size:12px; }
        audio { width:100%; height:32px; }
        .empty { padding:16px 12px; color:var(--stobe-text-muted, #aaa69e); }
        .image-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; padding:12px; }
        .image-card { display:block; overflow:hidden; border:1px solid var(--stobe-border, #404040); border-radius:6px; background:var(--stobe-surface-3, #1d1d1d); color:var(--stobe-text, #f5f1e8); text-decoration:none; }
        .image-card img, .image-card video { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; background:#111; }
        .image-card span { display:block; overflow:hidden; padding:7px 8px; text-overflow:ellipsis; white-space:nowrap; font-size:12px; }
        @media (max-width: 1100px) {
            .cache-grid { grid-template-columns:1fr; }
            .cache-row { grid-template-columns:1fr; }
            .image-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            audio { width:100%; }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/stobe-theme.css?v=<?php echo filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'stobe-theme.css'); ?>">
</head>
<body>
<main>
    <div class="cache-head">
        <div>
            <h1 class="cache-title">Audio &amp; Image Cache</h1>
            <div class="cache-meta">
                Audio: <?php echo htmlspecialchars($soundRoot, ENT_QUOTES, 'UTF-8'); ?><br>
                Images: <?php echo htmlspecialchars($galleryRoot, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="cache-actions">
            <a class="cache-btn" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/soundcache/" target="_blank" rel="noopener">Open Audio Folder URL</a>
            <?php if (is_dir($galleryRoot)): ?>
                <a class="cache-btn" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/data/pictures/gallery/" target="_blank" rel="noopener">Open Image Folder URL</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="cache-grid">
        <section class="cache-panel">
            <h2>Audio Cache</h2>
            <?php if (empty($audioFiles)): ?>
                <div class="empty">No cached audio files found.</div>
            <?php else: ?>
                <div class="cache-list">
                    <?php foreach ($audioFiles as $file): ?>
                        <?php
                            $relative = str_replace('\\', '/', substr($file['path'], strlen($soundRoot) + 1));
                            $url = $webRoot . '/soundcache/' . rawurlencode($relative);
                            $url = str_replace('%2F', '/', $url);
                        ?>
                        <div class="cache-row">
                            <div class="cache-name" title="<?php echo htmlspecialchars($relative, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="cache-small"><?php echo htmlspecialchars(strtoupper($file['ext']) . ' / ' . stobeCacheFormatBytes((int)$file['size']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="cache-small"><?php echo htmlspecialchars(date('Y-m-d H:i', (int)$file['mtime']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <audio controls preload="none" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"></audio>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="cache-panel">
            <h2>Image Cache</h2>
            <div class="cache-meta" style="padding:10px 12px;">
                <?php echo number_format(count($imageFiles)); ?> cached gallery item(s). The gallery reads from StobeServer/data/pictures/gallery.
            </div>
            <?php if (empty($imageFiles)): ?>
                <div class="empty">No cached image files found.</div>
            <?php else: ?>
                <div class="image-grid">
                    <?php foreach ($imageFiles as $file): ?>
                        <?php
                            $relative = str_replace('\\', '/', substr($file['path'], strlen($galleryRoot) + 1));
                            $url = $webRoot . '/data/pictures/gallery/' . rawurlencode($relative);
                            $url = str_replace('%2F', '/', $url);
                        ?>
                        <a class="image-card" href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                            <?php if ($file['ext'] === 'mp4'): ?>
                                <video controls preload="metadata" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"></video>
                            <?php else: ?>
                                <img loading="lazy" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <span title="<?php echo htmlspecialchars($relative, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
