<?php

declare(strict_types=1);

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'plugin_package_manager.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'plugin_catalog.php';

header('Cache-Control: no-store');

function pluginPackageJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function pluginPackageInput(): array
{
    $input = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
    return is_array($input) ? $input : [];
}

// Game clients send no Origin; a browser on another site must not drive package writes.
function pluginPackageRejectCrossSite(): void
{
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = '';
    if ($origin !== '') {
        $parts = parse_url($origin);
        $originHost = strtolower((string)($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
    if ($fetchSite === 'cross-site' || ($origin !== '' && ($origin === 'null' || $originHost !== $host))) {
        pluginPackageJson(['ok' => false, 'error' => 'Cross-site package requests are not allowed.'], 403);
    }
}

/** Browser-only writes: same session CSRF token as the Server Plugins page. Returns the job owner key. */
function pluginPackageRequireBrowserSession(array $input): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || empty($_SESSION['plugin_packages_csrf']) || !hash_equals((string)$_SESSION['plugin_packages_csrf'], $token)) {
        pluginPackageJson(['ok' => false, 'error' => 'Security check failed. Reload Server Plugins and try again.'], 403);
    }
    $owner = hash('sha256', session_id());
    // Release the session so status polling is not blocked by a long install.
    session_write_close();
    return $owner;
}

try {
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    if ($method === 'POST') {
        pluginPackageRejectCrossSite();
    }
    $manager = new DwemerPluginPackageManager();

    if ($action === 'probe' && $method === 'POST') {
        $input = pluginPackageInput();
        pluginPackageJson([
            'ok' => true,
            'package' => $manager->probe((string)($input['name'] ?? ''), (string)($input['version'] ?? '')),
        ]);
    }

    if ($action === 'start-upload' && $method === 'POST') {
        $input = pluginPackageInput();
        $upload = $manager->startChunkedUpload(
            (string)($input['name'] ?? ''),
            (string)($input['version'] ?? ''),
            (string)($input['archive_name'] ?? ''),
            (int)($input['size'] ?? 0),
            (int)($input['total_chunks'] ?? 0)
        );
        pluginPackageJson(['ok' => true, 'upload' => $upload], 201);
    }

    if ($action === 'upload-chunk' && $method === 'POST') {
        $upload = $manager->appendUploadChunk(
            (string)($_GET['upload_id'] ?? ''),
            (int)($_GET['index'] ?? -1),
            (string)file_get_contents('php://input', false, null, 0, DwemerPluginPackageManager::MAX_UPLOAD_CHUNK_BYTES + 1)
        );
        pluginPackageJson(['ok' => true, 'upload' => $upload], $upload['complete'] ? 201 : 200);
    }

    if ($action === 'status' && $method === 'GET') {
        pluginPackageJson(['ok' => true, 'job' => $manager->getJob((string)($_GET['job_id'] ?? ''))]);
    }

    if ($action === 'packages' && $method === 'GET') {
        pluginPackageJson(['ok' => true, 'packages' => $manager->installedPackages()]);
    }

    if ($action === 'inventory' && $method === 'GET') {
        $catalog = new DwemerPluginCatalog();
        pluginPackageJson([
            'ok' => true,
            'items' => $manager->extensionInventory(),
            'catalog' => $catalog->publicEntries(),
            'catalog_skipped' => $catalog->skippedEntries(),
        ]);
    }

    if ($method === 'POST' && in_array($action, ['browser-start-upload', 'check-updates', 'catalog-install', 'run-job', 'remove'], true)) {
        $input = pluginPackageInput();
        $owner = pluginPackageRequireBrowserSession($input);

        if ($action === 'browser-start-upload') {
            $upload = $manager->startChunkedUpload(
                null,
                null,
                (string)($input['archive_name'] ?? ''),
                (int)($input['size'] ?? 0),
                (int)($input['total_chunks'] ?? 0),
                ['type' => 'upload']
            );
            pluginPackageJson(['ok' => true, 'upload' => $upload], 201);
        }

        if ($action === 'check-updates') {
            pluginPackageJson(['ok' => true, 'releases' => (new DwemerPluginCatalog())->checkReleases(), 'checked_at' => gmdate(DATE_ATOM)]);
        }

        if ($action === 'catalog-install') {
            $catalog = new DwemerPluginCatalog();
            $entry = $catalog->entry((string)($input['id'] ?? ''));
            $channel = $catalog->channel($entry, (string)($input['channel'] ?? ''));
            $job = $manager->createQueuedJob(
                $entry['name'],
                ['type' => 'catalog', 'catalog_id' => $entry['id'], 'channel' => $channel['id']],
                $owner
            );
            pluginPackageJson(['ok' => true, 'job' => $job], 201);
        }

        if ($action === 'run-job') {
            ignore_user_abort(true);
            set_time_limit(900);
            $job = dwemerPluginCatalogRunJob($manager, new DwemerPluginCatalog(), (string)($input['job_id'] ?? ''), $owner);
            pluginPackageJson(['ok' => true, 'job' => $job]);
        }

        if ($action === 'remove') {
            $name = (string)($input['name'] ?? '');
            if (!is_string($input['confirm'] ?? null) || !hash_equals($name, $input['confirm'])) {
                throw new DwemerPluginPackageException('Removal was not confirmed for this plugin.');
            }
            pluginPackageJson(['ok' => true, 'removed' => $manager->removePackage($name)]);
        }
    }

    pluginPackageJson(['ok' => false, 'error' => 'Unsupported package API action.'], 404);
} catch (DwemerPluginPackageException $error) {
    pluginPackageJson(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('[PLUGIN-PACKAGE] ' . $error->getMessage());
    pluginPackageJson(['ok' => false, 'error' => 'Unexpected package service failure.'], 500);
}
