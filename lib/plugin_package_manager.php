<?php

declare(strict_types=1);

final class DwemerPluginPackageException extends RuntimeException
{
}

final class DwemerPluginPackageManager
{
    private const SUPPORTED_ARCHIVE_EXTENSIONS = ['dwpkg', 'zip'];
    // Extensions shipped with the server source. Packages may not replace or remove them.
    private const BUILT_IN_EXTENSIONS = ['relationship_system'];
    private const ORIGIN_TYPES = ['game', 'upload', 'catalog'];
    private const STATE_DIRECTORY_MODE = 02770;
    private const PAYLOAD_DIRECTORY_MODE = 0775;
    private const PAYLOAD_FILE_MODE = 0664;
    private const LOCK_WAIT_SECONDS = 30;

    public const SCHEMA_VERSION = 4;
    public const MAX_ENTRIES = 5000;
    public const MAX_UNCOMPRESSED_BYTES = 1073741824;
    public const MAX_ARCHIVE_BYTES = 536870912;
    public const MAX_UPLOAD_CHUNK_BYTES = 1572864;

    private string $serverRoot;
    private string $stateRoot;
    private $migrationRunner;
    private $operationLock = null;

    public function __construct(?string $serverRoot = null, ?string $stateRoot = null, ?callable $migrationRunner = null)
    {
        $this->serverRoot = rtrim($serverRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
        $this->stateRoot = rtrim(
            $stateRoot ?? ($this->serverRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugin_packages'),
            DIRECTORY_SEPARATOR
        );
        $this->migrationRunner = $migrationRunner;
        $this->ensureStateDirectories();
    }

    public function probe(string $name, string $version): array
    {
        $this->validatePluginName($name);
        $this->validateVersion($version);
        $this->rejectBuiltInName($name);
        $installed = $this->installedPackage($name);
        $current = is_array($installed) && hash_equals($this->canonicalName((string)$installed['name']), $this->canonicalName($name));
        $present = $current && $this->installedTargetExists($installed);
        $sameVersion = $present && hash_equals((string)$installed['version'], $version);

        $reason = 'not_installed';
        if ($sameVersion) {
            $reason = 'current';
        } elseif ($current && !$present) {
            $reason = 'missing_files';
        } elseif ($current) {
            $reason = 'version_changed';
        }

        return [
            'name' => $name,
            'requested_version' => $version,
            'installed_version' => $current ? (string)$installed['version'] : null,
            'upload_required' => !$sameVersion,
            'reason' => $reason,
        ];
    }

    public function installedPackages(): array
    {
        $packages = [];
        foreach (glob($this->stateRoot . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            try {
                $package = $this->readJsonFile($path);
                if (($package['manifest']['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
                    continue;
                }
                $this->validatePluginName((string)($package['name'] ?? ''));
                $this->validateVersion((string)($package['version'] ?? ''));
                $package['state'] = $this->installedTargetExists($package) ? 'installed' : 'missing_files';
                $package['origin'] = $this->normalizeOrigin($package['origin'] ?? null);
                $packages[] = $package;
            } catch (Throwable) {
                continue;
            }
        }
        usort($packages, static fn(array $left, array $right): int => strcasecmp((string)$left['name'], (string)$right['name']));
        return $packages;
    }

    /**
     * Compact view of ext/ for the Server Plugins page. Package state comes from
     * the ledger and the filesystem only; it does not claim any hook has executed.
     */
    public function extensionInventory(): array
    {
        $items = [];
        $managed = [];
        foreach ($this->installedPackages() as $package) {
            $installName = (string)($package['server']['install_name'] ?? $package['name']);
            $managed[$this->canonicalName($installName)] = true;
            $items[] = [
                'name' => (string)$package['name'],
                'version' => (string)$package['version'],
                'description' => $this->shortText($package['manifest']['description'] ?? ''),
                'kind' => 'package',
                'state' => (string)$package['state'],
                'origin' => $package['origin'],
                'installed_at' => (string)($package['installed_at'] ?? ''),
                'mutable_paths' => array_values(array_map('strval', (array)($package['manifest']['server']['mutable_paths'] ?? []))),
                'removable' => true,
            ];
        }

        $extRoot = $this->extRoot();
        foreach (is_dir($extRoot) ? (scandir($extRoot) ?: []) : [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $path = $extRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path) || isset($managed[$this->canonicalName($entry)])) {
                continue;
            }
            $manifest = $this->readSmallManifest($path . DIRECTORY_SEPARATOR . 'manifest.json');
            $items[] = [
                'name' => $entry,
                'version' => $this->shortText($manifest['version'] ?? '', 64),
                'description' => $this->shortText($manifest['description'] ?? ''),
                'kind' => $this->isBuiltInName($entry) ? 'built_in' : 'unmanaged',
                'state' => 'present',
                'origin' => null,
                'installed_at' => '',
                'mutable_paths' => [],
                'removable' => false,
            ];
        }

        foreach (glob($this->stateRoot . DIRECTORY_SEPARATOR . 'retained' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'record.json') ?: [] as $recordPath) {
            try {
                $record = $this->readJsonFile($recordPath);
                $this->validatePluginName((string)($record['name'] ?? ''));
            } catch (Throwable) {
                continue;
            }
            if (isset($managed[$this->canonicalName((string)$record['name'])])) {
                continue;
            }
            $items[] = [
                'name' => (string)$record['name'],
                'version' => (string)($record['version'] ?? ''),
                'description' => '',
                'kind' => 'retained',
                'state' => 'removed',
                'origin' => $this->normalizeOrigin($record['origin'] ?? null),
                'installed_at' => '',
                'removed_at' => (string)($record['removed_at'] ?? ''),
                'mutable_paths' => [],
                'removable' => false,
            ];
        }

        usort($items, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        return $items;
    }

    public function installArchive(
        string $sourceArchive,
        ?string $originalName = null,
        ?string $expectedName = null,
        ?string $expectedVersion = null,
        array $origin = ['type' => 'game'],
        ?string $existingJobId = null
    ): array {
        if (!is_file($sourceArchive) || !is_readable($sourceArchive)) {
            throw new DwemerPluginPackageException('Package archive is missing or unreadable.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new DwemerPluginPackageException('PHP ZipArchive support is required for server plugin packages.');
        }
        if (filesize($sourceArchive) > self::MAX_ARCHIVE_BYTES) {
            throw new DwemerPluginPackageException('Package archive exceeds 512 MB.');
        }
        $origin = $this->normalizeOrigin($origin);

        $existingJob = $existingJobId !== null ? $this->readJob($existingJobId) : null;
        $jobId = $existingJob !== null ? (string)$existingJob['id'] : bin2hex(random_bytes(16));
        $archivePath = $this->stateRoot . DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR . $jobId . '.dwpkg';
        $stageRoot = $this->stateRoot . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $jobId;

        try {
            if (!copy($sourceArchive, $archivePath)) {
                throw new DwemerPluginPackageException('Could not copy the package into server staging.');
            }
            if ($existingJob !== null) {
                $this->updateJob($jobId, ['status' => 'validating']);
            }
            $manifest = $this->validateAndExtractArchive($archivePath, $stageRoot);
            if ($expectedName !== null && $this->canonicalName($manifest['name']) !== $this->canonicalName($expectedName)) {
                throw new DwemerPluginPackageException('Package name does not match the expected plugin (game-side folder or catalog entry).');
            }
            if ($expectedVersion !== null && !hash_equals((string)$manifest['version'], $expectedVersion)) {
                throw new DwemerPluginPackageException('Package version does not match the expected version (game-side filename or catalog release).');
            }
            $this->rejectBuiltInName((string)$manifest['name']);

            $now = gmdate(DATE_ATOM);
            $job = array_merge($existingJob ?? ['created_at' => $now], [
                'id' => $jobId,
                'status' => 'activating_server',
                'name' => (string)$manifest['name'],
                'version' => (string)$manifest['version'],
                'original_name' => $originalName ?? basename($sourceArchive),
                'archive_path' => $archivePath,
                'archive_sha256' => hash_file('sha256', $archivePath),
                'stage_root' => $stageRoot,
                'manifest' => $manifest,
                'origin' => $origin,
                'updated_at' => $now,
                'error' => null,
            ]);
            $this->writeJob($job);
            return $this->activateAndFinalize($job);
        } catch (Throwable $error) {
            $this->removeDirectory($stageRoot);
            @unlink($archivePath);
            if ($existingJob !== null) {
                $this->updateJob($jobId, ['status' => 'failed', 'error' => $error->getMessage()]);
            }
            throw $error;
        }
    }

    public function startChunkedUpload(
        ?string $name,
        ?string $version,
        string $originalName,
        int $size,
        int $totalChunks,
        array $origin = ['type' => 'game']
    ): array {
        // Game sync declares name/version up front; a browser upload learns them from the manifest.
        if ($name !== null || $version !== null) {
            $this->validatePluginName((string)$name);
            $this->validateVersion((string)$version);
            $this->rejectBuiltInName((string)$name);
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_ARCHIVE_EXTENSIONS, true)) {
            throw new DwemerPluginPackageException('Server plugin packages must use the .dwpkg or .zip extension.');
        }
        if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new DwemerPluginPackageException('Package archive size is invalid or exceeds 512 MB.');
        }
        if ($totalChunks < 1 || $totalChunks > 4096) {
            throw new DwemerPluginPackageException('Package upload chunk count is invalid.');
        }
        $this->sweepStaleUploads();

        $uploadId = bin2hex(random_bytes(16));
        $metadata = [
            'id' => $uploadId,
            'name' => $name,
            'version' => $version,
            'original_name' => basename($originalName),
            'size' => $size,
            'total_chunks' => $totalChunks,
            'next_index' => 0,
            'received_bytes' => 0,
            'origin' => $this->normalizeOrigin($origin),
            'created_at' => gmdate(DATE_ATOM),
        ];
        $this->atomicWrite(
            $this->uploadMetadataPath($uploadId),
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        return ['upload_id' => $uploadId, 'next_index' => 0];
    }

    public function appendUploadChunk(string $uploadId, int $index, string $data): array
    {
        $length = strlen($data);
        if ($length < 1 || $length > self::MAX_UPLOAD_CHUNK_BYTES) {
            throw new DwemerPluginPackageException('Upload chunk is empty or exceeds the chunk size limit.');
        }
        $metadataPath = $this->uploadMetadataPath($uploadId);
        if (!is_file($metadataPath)) {
            throw new DwemerPluginPackageException('Chunked upload was not found.');
        }
        $handle = fopen($metadataPath, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            throw new DwemerPluginPackageException('Could not lock chunked upload.');
        }

        $complete = false;
        try {
            rewind($handle);
            $metadata = json_decode((string)stream_get_contents($handle), true, 32, JSON_THROW_ON_ERROR);
            if ($index !== (int)$metadata['next_index']) {
                throw new DwemerPluginPackageException('Upload chunks must arrive once and in order.');
            }
            $received = (int)$metadata['received_bytes'] + $length;
            if ($received > (int)$metadata['size']) {
                throw new DwemerPluginPackageException('Upload exceeds its declared archive size.');
            }
            if (file_put_contents($this->uploadPartPath($uploadId), $data, FILE_APPEND | LOCK_EX) === false) {
                throw new DwemerPluginPackageException('Could not write upload chunk.');
            }
            $metadata['received_bytes'] = $received;
            $metadata['next_index'] = $index + 1;
            $complete = $metadata['next_index'] === (int)$metadata['total_chunks'];
            if ($complete && $received !== (int)$metadata['size']) {
                throw new DwemerPluginPackageException('Completed upload size does not match the declared archive size.');
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (!$complete) {
            return ['complete' => false, 'next_index' => $index + 1];
        }

        try {
            $metadata = $this->readJsonFile($metadataPath);
            $job = $this->installArchive(
                $this->uploadPartPath($uploadId),
                (string)$metadata['original_name'],
                isset($metadata['name']) ? (string)$metadata['name'] : null,
                isset($metadata['version']) ? (string)$metadata['version'] : null,
                $this->normalizeOrigin($metadata['origin'] ?? null)
            );
            return ['complete' => true, 'job' => $job];
        } finally {
            @unlink($metadataPath);
            @unlink($this->uploadPartPath($uploadId));
        }
    }

    public function getJob(string $jobId): array
    {
        return $this->publicJob($this->readJob($jobId));
    }

    /** Creates a queued job for a server-resolved catalog install; the caller runs it separately. */
    public function createQueuedJob(string $name, array $origin, string $owner): array
    {
        $this->validatePluginName($name);
        $this->rejectBuiltInName($name);
        $now = gmdate(DATE_ATOM);
        $job = [
            'id' => bin2hex(random_bytes(16)),
            'status' => 'queued',
            'name' => $name,
            'version' => '',
            'origin' => $this->normalizeOrigin($origin),
            'owner' => $owner,
            'bytes_received' => 0,
            'bytes_total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'error' => null,
        ];
        $this->writeJob($job);
        return $this->publicJob($job);
    }

    /** Claims a queued job exactly once for the session that created it. */
    public function claimQueuedJob(string $jobId, string $owner): array
    {
        $claimPath = $this->jobPath($jobId) . '.claim';
        $job = $this->readJob($jobId);
        if (!hash_equals((string)($job['owner'] ?? ''), $owner)) {
            throw new DwemerPluginPackageException('Package job was not found.');
        }
        $claim = @fopen($claimPath, 'x');
        if (!is_resource($claim) || ($job['status'] ?? '') !== 'queued') {
            if (is_resource($claim)) fclose($claim);
            throw new DwemerPluginPackageException('This package job has already started.');
        }
        fclose($claim);
        return $job;
    }

    public function updateJob(string $jobId, array $fields): void
    {
        $job = $this->readJob($jobId);
        foreach (['status', 'version', 'error', 'bytes_received', 'bytes_total'] as $key) {
            if (array_key_exists($key, $fields)) {
                $job[$key] = $fields[$key];
            }
        }
        $job['updated_at'] = gmdate(DATE_ATOM);
        $this->writeJob($job);
    }

    public function jobArchiveDownloadPath(string $jobId): string
    {
        $this->jobPath($jobId);
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'archives' . DIRECTORY_SEPARATOR . $jobId . '.download';
    }

    /**
     * Removes a ledger-managed extension. The extension folder moves to retained
     * storage, so mutable files are restored by a later install of the same
     * package. Database tables and migration records are left untouched.
     */
    public function removePackage(string $name): array
    {
        $this->validatePluginName($name);
        $this->rejectBuiltInName($name);
        return $this->withOperationLock(function () use ($name): array {
            $installed = $this->installedPackage($name);
            if (!is_array($installed) || !hash_equals($this->canonicalName((string)($installed['name'] ?? '')), $this->canonicalName($name))) {
                throw new DwemerPluginPackageException('Only packages installed through the package ledger can be removed here.');
            }
            $installName = (string)($installed['server']['install_name'] ?? $installed['name']);
            $this->validatePluginName($installName);
            if ($this->canonicalName($installName) !== $this->canonicalName($name)) {
                throw new DwemerPluginPackageException('Package ledger entry does not match its install folder.');
            }
            $this->rejectBuiltInName($installName);

            $target = $this->extRoot() . DIRECTORY_SEPARATOR . $installName;
            $retained = null;
            if (is_link($target)) {
                throw new DwemerPluginPackageException('The installed extension path is a link and was not removed.');
            }
            if (is_dir($target)) {
                $realExt = realpath($this->extRoot());
                $realTarget = realpath($target);
                if ($realExt === false || $realTarget === false || dirname($realTarget) !== $realExt) {
                    throw new DwemerPluginPackageException('The installed extension path is outside ext/ and was not removed.');
                }
                $retainedRoot = $this->retainedRoot($installName);
                if (is_dir($retainedRoot)) {
                    $previous = $this->stateRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
                    $this->ensureDirectory($previous);
                    if (!rename($retainedRoot, $previous . DIRECTORY_SEPARATOR . 'retained')) {
                        throw new DwemerPluginPackageException('Could not archive previously retained plugin data.');
                    }
                }
                $this->ensureDirectory($retainedRoot);
                $retained = $retainedRoot . DIRECTORY_SEPARATOR . 'files';
                if (!rename($target, $retained)) {
                    throw new DwemerPluginPackageException("Could not move extension '{$installName}' out of ext/.");
                }
                $this->atomicWrite(
                    $retainedRoot . DIRECTORY_SEPARATOR . 'record.json',
                    json_encode([
                        'name' => (string)$installed['name'],
                        'version' => (string)$installed['version'],
                        'origin' => $this->normalizeOrigin($installed['origin'] ?? null),
                        'removed_at' => gmdate(DATE_ATOM),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
                );
            }
            if (!@unlink($this->installedPackagePath($installName)) && is_file($this->installedPackagePath($installName))) {
                throw new DwemerPluginPackageException('Extension files were retained, but the package ledger entry could not be removed.');
            }

            return [
                'name' => (string)$installed['name'],
                'version' => (string)$installed['version'],
                'origin' => $this->normalizeOrigin($installed['origin'] ?? null),
                'files_retained' => $retained !== null,
                'ledger_only' => $retained === null,
            ];
        });
    }

    public function validateAndExtractArchive(string $archivePath, string $stageRoot): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new DwemerPluginPackageException('Package is not a readable ZIP archive.');
        }

        try {
            if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new DwemerPluginPackageException('Package has an invalid number of entries.');
            }
            $totalBytes = 0;
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'])) {
                    throw new DwemerPluginPackageException('Package contains an unreadable entry.');
                }
                $name = self::normalizeArchivePath((string)$stat['name']);
                $directory = str_ends_with((string)$stat['name'], '/');
                $totalBytes += (int)($stat['size'] ?? 0);
                if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new DwemerPluginPackageException('Package exceeds the uncompressed size limit.');
                }
                if ($this->zipEntryIsSymlink($zip, $index)) {
                    throw new DwemerPluginPackageException("Package entry '{$name}' is a symbolic link.");
                }
                if (isset($entries[$name])) {
                    throw new DwemerPluginPackageException("Package contains duplicate path '{$name}'.");
                }
                $entries[$name] = ['index' => $index, 'directory' => $directory];
            }
            foreach (['manifest.json', 'checksums.sha256'] as $required) {
                if (!isset($entries[$required]) || $entries[$required]['directory']) {
                    throw new DwemerPluginPackageException("Package is missing {$required}.");
                }
            }

            $manifest = json_decode((string)$zip->getFromIndex($entries['manifest.json']['index']), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new DwemerPluginPackageException('manifest.json must contain an object.');
            }
            $this->validateManifest($manifest, $entries);
            $this->removeDirectory($stageRoot);
            $this->ensureDirectory($stageRoot, self::PAYLOAD_DIRECTORY_MODE);
            foreach ($entries as $name => $entry) {
                $destination = $stageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
                if ($entry['directory']) {
                    $this->ensureDirectory($destination, self::PAYLOAD_DIRECTORY_MODE);
                    continue;
                }
                $this->ensureDirectory(dirname($destination), self::PAYLOAD_DIRECTORY_MODE);
                $input = $zip->getStream((string)$zip->getNameIndex($entry['index']));
                $output = fopen($destination, 'wb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new DwemerPluginPackageException("Could not stage package entry '{$name}'.");
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                @chmod($destination, self::PAYLOAD_FILE_MODE);
            }
            $this->verifyChecksums($stageRoot, $entries);
            return $manifest;
        } catch (JsonException $error) {
            throw new DwemerPluginPackageException('manifest.json is not valid JSON.', 0, $error);
        } finally {
            $zip->close();
        }
    }

    public static function normalizeArchivePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new DwemerPluginPackageException('Package contains an invalid archive path.');
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) {
            throw new DwemerPluginPackageException("Package path '{$path}' is absolute.");
        }
        $trimmed = rtrim($path, '/');
        if ($trimmed === '') {
            throw new DwemerPluginPackageException('Package contains an empty archive path.');
        }
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new DwemerPluginPackageException("Package path '{$path}' is unsafe.");
            }
        }
        return $trimmed;
    }

    private function validateManifest(array $manifest, array $entries): void
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new DwemerPluginPackageException('Unsupported package schema version.');
        }
        foreach (['name', 'version', 'server'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new DwemerPluginPackageException("Package manifest is missing '{$field}'.");
            }
        }
        $this->validatePluginName((string)$manifest['name']);
        $this->validateVersion((string)$manifest['version']);
        if (!is_array($manifest['server'])) {
            throw new DwemerPluginPackageException('Package server settings must be an object.');
        }
        $this->validateMutablePaths($manifest['server']['mutable_paths'] ?? []);
        $this->requirePayloadPrefix($entries, 'server/');
        foreach ($entries as $path => $entry) {
            if ($entry['directory'] || in_array($path, ['manifest.json', 'checksums.sha256'], true)) {
                continue;
            }
            if (!str_starts_with($path, 'server/')) {
                throw new DwemerPluginPackageException("Unsupported package payload '{$path}'.");
            }
        }
    }

    private function validatePluginName(string $name): void
    {
        if (strlen($name) > 64 || trim($name) !== $name || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/', $name)) {
            throw new DwemerPluginPackageException('Plugin name contains unsupported characters.');
        }
        if (str_ends_with($name, '.') || str_ends_with($name, ' ')) {
            throw new DwemerPluginPackageException('Plugin name cannot end with a dot or space.');
        }
    }

    private function validateVersion(string $version): void
    {
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version)) {
            throw new DwemerPluginPackageException('Plugin version contains unsupported characters.');
        }
    }

    private function isBuiltInName(string $name): bool
    {
        foreach (self::BUILT_IN_EXTENSIONS as $builtIn) {
            if ($this->canonicalName($builtIn) === $this->canonicalName($name)) {
                return true;
            }
        }
        return false;
    }

    private function rejectBuiltInName(string $name): void
    {
        if ($this->isBuiltInName($name)) {
            throw new DwemerPluginPackageException("'{$name}' is a built-in server extension and cannot be managed as a package.");
        }
    }

    private function validateMutablePaths(mixed $paths): void
    {
        if (!is_array($paths)) {
            throw new DwemerPluginPackageException('mutable_paths must be an array.');
        }
        foreach ($paths as $path) {
            self::normalizeArchivePath((string)$path);
        }
    }

    private function requirePayloadPrefix(array $entries, string $prefix): void
    {
        foreach ($entries as $path => $entry) {
            if (!$entry['directory'] && str_starts_with($path, $prefix)) {
                return;
            }
        }
        throw new DwemerPluginPackageException("Package payload '{$prefix}' is empty.");
    }

    private function verifyChecksums(string $stageRoot, array $entries): void
    {
        $lines = file($stageRoot . DIRECTORY_SEPARATOR . 'checksums.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new DwemerPluginPackageException('Could not read checksums.sha256.');
        }
        $expected = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([a-fA-F0-9]{64})\s+\*?(.+)$/', trim($line), $match)) {
                throw new DwemerPluginPackageException('checksums.sha256 contains an invalid line.');
            }
            $path = self::normalizeArchivePath($match[2]);
            if ($path === 'checksums.sha256' || isset($expected[$path])) {
                throw new DwemerPluginPackageException("Duplicate or recursive checksum entry '{$path}'.");
            }
            $expected[$path] = strtolower($match[1]);
        }
        foreach ($entries as $path => $entry) {
            if ($entry['directory'] || $path === 'checksums.sha256') continue;
            if (!isset($expected[$path])) {
                throw new DwemerPluginPackageException("Package file '{$path}' is not covered by checksums.sha256.");
            }
            $filePath = $stageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!hash_equals($expected[$path], hash_file('sha256', $filePath))) {
                throw new DwemerPluginPackageException("Checksum mismatch for '{$path}'.");
            }
            unset($expected[$path]);
        }
        if ($expected) {
            throw new DwemerPluginPackageException("Checksum references missing file '" . array_key_first($expected) . "'.");
        }
    }

    private function activateAndFinalize(array $job): array
    {
        try {
            // Activation and its ledger entry share one lock, so a concurrent
            // removal or update cannot interleave and be overwritten by a stale entry.
            $this->withOperationLock(function () use ($job): void {
                $serverState = $this->activateServerComponent($job);
                try {
                    $this->recordInstalledPackage($job, $serverState);
                } catch (Throwable $error) {
                    throw new DwemerPluginPackageException('Server extension was activated, but its package ledger entry could not be recorded: ' . $error->getMessage(), 0, $error);
                }
            });
            $job['status'] = 'completed';
            $job['updated_at'] = gmdate(DATE_ATOM);
            $job['error'] = null;
            $this->writeJob($job);
            $this->removeDirectory((string)$job['stage_root']);
            @unlink((string)$job['archive_path']);
            return $this->publicJob($job);
        } catch (Throwable $error) {
            $job['status'] = 'failed';
            $job['error'] = $error->getMessage();
            $job['updated_at'] = gmdate(DATE_ATOM);
            $this->writeJob($job);
            $this->removeDirectory((string)$job['stage_root']);
            @unlink((string)$job['archive_path']);
            return $this->publicJob($job);
        }
    }

    private function activateServerComponent(array $job): array
    {
        $name = (string)$job['name'];
        $source = (string)$job['stage_root'] . DIRECTORY_SEPARATOR . 'server';
        $target = $this->extRoot() . DIRECTORY_SEPARATOR . $name;
        $backup = $this->stateRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $job['id'] . DIRECTORY_SEPARATOR . $name;
        $failed = $this->stateRoot . DIRECTORY_SEPARATOR . 'failed' . DIRECTORY_SEPARATOR . $job['id'] . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($source)) {
            throw new DwemerPluginPackageException('Staged server payload is missing.');
        }
        if (is_link($target)) {
            throw new DwemerPluginPackageException("Server extension path '{$name}' is a link and was not replaced.");
        }
        $this->ensureDirectory(dirname($target), self::PAYLOAD_DIRECTORY_MODE);
        $this->ensureDirectory(dirname($backup));
        $mutablePaths = $job['manifest']['server']['mutable_paths'] ?? [];
        $retainedRoot = $this->retainedRoot($name);
        $retainedFiles = $retainedRoot . DIRECTORY_SEPARATOR . 'files';
        $restoreRetained = !is_dir($target) && is_dir($retainedFiles);
        $this->preserveMutablePaths($restoreRetained ? $retainedFiles : $target, $source, $mutablePaths);
        $hadPrevious = is_dir($target);
        if ($hadPrevious && !rename($target, $backup)) {
            throw new DwemerPluginPackageException("Could not back up existing server extension '{$name}'.");
        }
        try {
            if (!rename($source, $target)) {
                throw new DwemerPluginPackageException("Could not activate server extension '{$name}'.");
            }
            $this->runMigrations($target, $name);
        } catch (Throwable $error) {
            if (is_dir($target)) {
                $this->ensureDirectory(dirname($failed));
                @rename($target, $failed);
                if (is_dir($target)) $this->removeDirectory($target);
            }
            if ($hadPrevious && is_dir($backup)) @rename($backup, $target);
            throw new DwemerPluginPackageException('Server activation rolled back: ' . $error->getMessage(), 0, $error);
        }
        if ($restoreRetained) {
            // The retained copy has been consumed; keep it with this job's backups rather than deleting it.
            $this->ensureDirectory(dirname($backup));
            @rename($retainedRoot, dirname($backup) . DIRECTORY_SEPARATOR . 'retained');
        }
        return [
            'install_name' => $name,
            'path' => $target,
            'backup_path' => $hadPrevious ? $backup : null,
            'restored_retained_data' => $restoreRetained,
            'files' => $this->buildFileLedger($target),
        ];
    }

    private function preserveMutablePaths(string $oldRoot, string $newRoot, array $mutablePaths): void
    {
        if (!is_dir($oldRoot)) return;
        foreach ($mutablePaths as $relativePath) {
            $normalized = self::normalizeArchivePath((string)$relativePath);
            $oldPath = $oldRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            $newPath = $newRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            if (is_file($oldPath)) {
                $this->ensureDirectory(dirname($newPath), self::PAYLOAD_DIRECTORY_MODE);
                if (!copy($oldPath, $newPath)) {
                    throw new DwemerPluginPackageException("Could not preserve mutable file '{$normalized}'.");
                }
                @chmod($newPath, self::PAYLOAD_FILE_MODE);
            } elseif (is_dir($oldPath)) {
                $this->copyDirectory($oldPath, $newPath);
            }
        }
    }

    private function runMigrations(string $targetDir, string $pluginName): void
    {
        $migrations = glob($targetDir . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($migrations, SORT_STRING);
        if (!$migrations) return;
        if (is_callable($this->migrationRunner)) {
            ($this->migrationRunner)($targetDir, $pluginName, $migrations);
            return;
        }
        if (!function_exists('pg_connect')) {
            throw new DwemerPluginPackageException('PostgreSQL support is required to run plugin migrations.');
        }
        $connection = @pg_connect(self::migrationConnectionString(), PGSQL_CONNECT_FORCE_NEW);
        if (!$connection) throw new DwemerPluginPackageException('Could not connect to PostgreSQL for plugin migrations.');
        self::applyMigrations($connection, $pluginName, $migrations);
    }

    /** Same STOBE_DB_* environment settings and defaults as the Stobe runtime connection. */
    private static function migrationConnectionString(): string
    {
        $settings = [
            'host' => getenv('STOBE_DB_HOST') ?: 'localhost',
            'port' => getenv('STOBE_DB_PORT') ?: '5432',
            'dbname' => getenv('STOBE_DB_NAME') ?: 'stobe',
            'user' => getenv('STOBE_DB_USER') ?: 'dwemer',
            'password' => getenv('STOBE_DB_PASSWORD') ?: 'dwemer',
        ];
        $parts = [];
        foreach ($settings as $key => $value) {
            $value = $key === 'password' ? (string)$value : trim((string)$value);
            $parts[] = $key . '=' . (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1 ? $value : "'" . str_replace(["\\", "'"], ["\\\\", "\'"], $value) . "'");
        }
        return implode(' ', $parts);
    }

    /** Applies unrecorded migrations in one transaction and closes the connection. */
    public static function applyMigrations($connection, string $pluginName, array $migrations): void
    {
        try {
            if (!pg_query($connection, 'BEGIN')) throw new DwemerPluginPackageException('Could not start plugin migration transaction.');
            $setup = 'CREATE SCHEMA IF NOT EXISTS plugins; CREATE TABLE IF NOT EXISTS plugins.plugin_migrations (plugin_name VARCHAR(255), migration_name VARCHAR(255), executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (plugin_name, migration_name));';
            if (!pg_query($connection, $setup)) throw new DwemerPluginPackageException('Could not initialize plugin migration tracking.');
            foreach ($migrations as $migrationPath) {
                $migrationName = basename($migrationPath);
                $existing = pg_query_params($connection, 'SELECT 1 FROM plugins.plugin_migrations WHERE plugin_name = $1 AND migration_name = $2', [$pluginName, $migrationName]);
                if ($existing && pg_num_rows($existing) > 0) continue;
                $sql = file_get_contents($migrationPath);
                if ($sql === false || !@pg_query($connection, $sql)) {
                    throw new DwemerPluginPackageException("Migration '{$migrationName}' failed: " . pg_last_error($connection));
                }
                if (!pg_query_params($connection, 'INSERT INTO plugins.plugin_migrations (plugin_name, migration_name) VALUES ($1, $2)', [$pluginName, $migrationName])) {
                    throw new DwemerPluginPackageException("Could not record migration '{$migrationName}'.");
                }
            }
            if (!pg_query($connection, 'COMMIT')) throw new DwemerPluginPackageException('Could not commit plugin migrations.');
        } catch (Throwable $error) {
            @pg_query($connection, 'ROLLBACK');
            throw $error;
        } finally {
            pg_close($connection);
        }
    }

    private function recordInstalledPackage(array $job, array $serverState): void
    {
        $state = [
            'name' => $job['name'],
            'version' => $job['version'],
            'installed_at' => gmdate(DATE_ATOM),
            'job_id' => $job['id'],
            'archive_sha256' => $job['archive_sha256'],
            'origin' => $this->normalizeOrigin($job['origin'] ?? null),
            'server' => $serverState,
            'manifest' => $job['manifest'],
        ];
        $this->atomicWrite(
            $this->installedPackagePath((string)$job['name']),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    private function installedPackage(string $name): ?array
    {
        $path = $this->installedPackagePath($name);
        return is_file($path) ? $this->readJsonFile($path) : null;
    }

    private function installedTargetExists(array $package): bool
    {
        $installName = (string)($package['server']['install_name'] ?? $package['name'] ?? '');
        if ($installName === '') {
            return false;
        }
        $target = $this->extRoot() . DIRECTORY_SEPARATOR . $installName;
        return is_dir($target) && !is_link($target);
    }

    private function installedPackagePath(string $name): string
    {
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . hash('sha256', $this->canonicalName($name)) . '.json';
    }

    private function retainedRoot(string $name): string
    {
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'retained' . DIRECTORY_SEPARATOR . hash('sha256', $this->canonicalName($name));
    }

    private function extRoot(): string
    {
        return $this->serverRoot . DIRECTORY_SEPARATOR . 'ext';
    }

    private function canonicalName(string $name): string
    {
        return strtolower($name);
    }

    private function normalizeOrigin(mixed $origin): array
    {
        // Ledger entries written before origins were recorded came from game sync.
        $origin = is_array($origin) ? $origin : [];
        $type = in_array($origin['type'] ?? null, self::ORIGIN_TYPES, true) ? (string)$origin['type'] : 'game';
        $normalized = ['type' => $type];
        if ($type === 'catalog') {
            foreach (['catalog_id', 'channel'] as $key) {
                $value = (string)($origin[$key] ?? '');
                $normalized[$key] = preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) ? $value : '';
            }
        }
        return $normalized;
    }

    private function shortText(mixed $value, int $limit = 300): string
    {
        $text = is_scalar($value) ? trim((string)$value) : '';
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    }

    private function readSmallManifest(string $path): array
    {
        if (!is_file($path) || filesize($path) > 65536) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($path), true, 16);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildFileLedger(string $root): array
    {
        $ledger = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $ledger[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($ledger, SORT_STRING);
        return $ledger;
    }

    private function publicJob(array $job): array
    {
        return [
            'id' => $job['id'],
            'status' => $job['status'],
            'name' => $job['name'],
            'version' => $job['version'],
            'origin' => $this->normalizeOrigin($job['origin'] ?? null),
            'bytes_received' => (int)($job['bytes_received'] ?? 0),
            'bytes_total' => (int)($job['bytes_total'] ?? 0),
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
            'error' => $job['error'] ?? null,
        ];
    }

    private function writeJob(array $job): void
    {
        $this->atomicWrite($this->jobPath((string)$job['id']), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function readJob(string $jobId): array
    {
        $path = $this->jobPath($jobId);
        if (!is_file($path)) throw new DwemerPluginPackageException('Package job was not found.');
        return $this->readJsonFile($path);
    }

    private function jobPath(string $jobId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) throw new DwemerPluginPackageException('Package job ID is invalid.');
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    private function readJsonFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) throw new DwemerPluginPackageException('Could not read package state.');
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DwemerPluginPackageException('Package state is corrupted.', 0, $error);
        }
        if (!is_array($decoded)) throw new DwemerPluginPackageException('Package state is invalid.');
        return $decoded;
    }

    private function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes) || $opsys !== ZipArchive::OPSYS_UNIX) return false;
        return (($attributes >> 16) & 0170000) === 0120000;
    }

    /**
     * Serializes activation and removal across web and CLI processes. The lock
     * file is opened in place and never deleted or replaced.
     */
    private function withOperationLock(callable $operation): mixed
    {
        if ($this->operationLock !== null) {
            return $operation();
        }
        $path = $this->stateRoot . DIRECTORY_SEPARATOR . 'operation.lock';
        $created = !file_exists($path);
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new DwemerPluginPackageException('Could not open the package operation lock. Check data/plugin_packages permissions.');
        }
        if ($created) {
            @chmod($path, 0660);
        }
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new DwemerPluginPackageException('Another package install or removal is still running. Try again shortly.');
            }
            usleep(250000);
        }
        $this->operationLock = $handle;
        try {
            return $operation();
        } finally {
            $this->operationLock = null;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function sweepStaleUploads(): void
    {
        $cutoff = time() - 86400;
        foreach (glob($this->stateRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metadataPath) {
            if (!preg_match('/^[a-f0-9]{32}\.json$/', basename($metadataPath)) || (int)@filemtime($metadataPath) >= $cutoff) {
                continue;
            }
            @unlink(substr($metadataPath, 0, -5) . '.part');
            @unlink($metadataPath);
        }
    }

    private function ensureStateDirectories(): void
    {
        $this->ensureDirectory($this->stateRoot);
        foreach (['archives', 'backups', 'failed', 'jobs', 'packages', 'retained', 'staging', 'uploads'] as $directory) {
            $this->ensureDirectory($this->stateRoot . DIRECTORY_SEPARATOR . $directory);
        }
    }

    private function uploadMetadataPath(string $uploadId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) throw new DwemerPluginPackageException('Chunked upload ID is invalid.');
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $uploadId . '.json';
    }

    private function uploadPartPath(string $uploadId): string
    {
        $this->uploadMetadataPath($uploadId);
        return $this->stateRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $uploadId . '.part';
    }

    /**
     * Creates missing directories with an explicit mode so a restrictive umask
     * cannot hide shared state from the other web/CLI account. Existing
     * directories keep their current owner, group and mode.
     */
    private function ensureDirectory(string $path, int $mode = self::STATE_DIRECTORY_MODE): void
    {
        if (is_dir($path)) {
            return;
        }
        $parent = dirname($path);
        if ($parent !== $path && !is_dir($parent)) {
            $this->ensureDirectory($parent, $mode);
        }
        if (!@mkdir($path, $mode) && !is_dir($path)) {
            throw new DwemerPluginPackageException("Could not create directory '{$path}'.");
        }
        @chmod($path, $mode);
    }

    private function atomicWrite(string $path, string $contents, int $mode = 0660): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) throw new DwemerPluginPackageException("Could not write '{$path}'.");
        @chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new DwemerPluginPackageException("Could not publish '{$path}'.");
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination, self::PAYLOAD_DIRECTORY_MODE);
        foreach (new DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) continue;
            $target = $destination . DIRECTORY_SEPARATOR . $entry->getFilename();
            if ($entry->isDir() && !$entry->isLink()) {
                $this->copyDirectory($entry->getPathname(), $target);
            } elseif ($entry->isFile()) {
                $this->ensureDirectory(dirname($target), self::PAYLOAD_DIRECTORY_MODE);
                if (!copy($entry->getPathname(), $target)) throw new DwemerPluginPackageException("Could not preserve '{$entry->getFilename()}'.");
                @chmod($target, self::PAYLOAD_FILE_MODE);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
