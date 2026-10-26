<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'plugin_package_manager.php';

/**
 * Product-owned server plugin catalog (ui/data/plugin_repository.json).
 *
 * Every download URL is resolved on the server from a catalog entry and one of
 * its channels; the browser only sends an entry ID and channel ID. Each channel
 * points at an HTTPS release document:
 *   {"name": "...", "version": "1.2.0", "package_url": "https://...", "sha256": "..."}
 * "package_url" may instead come from the channel's own template, where
 * "<version>" is replaced by the release version.
 */
final class DwemerPluginCatalog
{
    public const CHANNELS = ['live' => 'Live', 'dev' => 'Dev'];
    private const MAX_ENTRIES = 50;
    private const MAX_CATALOG_BYTES = 262144;
    private const MAX_RELEASE_BYTES = 65536;

    private string $catalogPath;
    private string $userAgent;
    private ?array $entries = null;
    private int $skipped = 0;

    public function __construct(?string $catalogPath = null, string $userAgent = 'StobeServer-Plugins')
    {
        $this->catalogPath = $catalogPath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugin_repository.json';
        $this->userAgent = $userAgent;
    }

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $this->entries = [];
        if (!is_file($this->catalogPath) || filesize($this->catalogPath) > self::MAX_CATALOG_BYTES) {
            return $this->entries;
        }
        $data = json_decode((string)file_get_contents($this->catalogPath), true, 32);
        $plugins = is_array($data) && is_array($data['plugins'] ?? null) ? $data['plugins'] : [];
        foreach ($plugins as $id => $plugin) {
            if (count($this->entries) >= self::MAX_ENTRIES) {
                break;
            }
            $entry = is_array($plugin) ? $this->normalizeEntry((string)$id, $plugin) : null;
            if ($entry === null) {
                $this->skipped++;
                continue;
            }
            $this->entries[$entry['id']] = $entry;
        }
        return $this->entries;
    }

    /** Catalog details safe to send to the browser (no release or package URLs). */
    public function publicEntries(): array
    {
        $public = [];
        foreach ($this->entries() as $entry) {
            $channels = [];
            foreach ($entry['channels'] as $channel) {
                $channels[] = ['id' => $channel['id'], 'label' => $channel['label']];
            }
            $public[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'description' => $entry['description'],
                'homepage' => $entry['homepage'],
                'default_channel' => $entry['default_channel'],
                'channels' => $channels,
            ];
        }
        return $public;
    }

    public function skippedEntries(): int
    {
        $this->entries();
        return $this->skipped;
    }

    public function entry(string $id): array
    {
        $entries = $this->entries();
        if (!isset($entries[$id])) {
            throw new DwemerPluginPackageException('That plugin is not listed in this server\'s catalog.');
        }
        return $entries[$id];
    }

    public function channel(array $entry, string $channelId): array
    {
        if (!isset($entry['channels'][$channelId])) {
            throw new DwemerPluginPackageException('That channel is not offered for this plugin.');
        }
        return $entry['channels'][$channelId];
    }

    /** Fetches one release document and resolves its package URL on the server. */
    public function resolveRelease(array $entry, array $channel): array
    {
        $body = $this->fetch($channel['release_url'], self::MAX_RELEASE_BYTES, 15);
        $release = json_decode($body, true, 16);
        if (!is_array($release)) {
            throw new DwemerPluginPackageException('The catalog release document is not valid JSON.');
        }
        $version = (string)($release['version'] ?? '');
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version)) {
            throw new DwemerPluginPackageException('The catalog release document has no valid version.');
        }
        if (isset($release['name']) && strtolower((string)$release['name']) !== strtolower($entry['name'])) {
            throw new DwemerPluginPackageException('The catalog release document names a different plugin.');
        }
        $packageUrl = (string)($release['package_url'] ?? '');
        if ($packageUrl === '' && $channel['package_url'] !== '') {
            $packageUrl = str_replace('<version>', rawurlencode($version), $channel['package_url']);
        }
        $packageHost = self::httpsHost($packageUrl);
        if ($packageHost === null || !in_array($packageHost, $channel['package_hosts'], true)) {
            throw new DwemerPluginPackageException('The catalog release points to a package host this catalog does not allow.');
        }
        $sha256 = strtolower((string)($release['sha256'] ?? ''));
        if ($sha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new DwemerPluginPackageException('The catalog release has an invalid SHA-256 value.');
        }
        return ['version' => $version, 'package_url' => $packageUrl, 'sha256' => $sha256];
    }

    /** Explicit update check: one release request per catalog channel, never on page load. */
    public function checkReleases(): array
    {
        $results = [];
        foreach ($this->entries() as $entry) {
            foreach ($entry['channels'] as $channel) {
                try {
                    $release = $this->resolveRelease($entry, $channel);
                    $results[] = ['id' => $entry['id'], 'channel' => $channel['id'], 'version' => $release['version'], 'error' => null];
                } catch (Throwable $error) {
                    $message = $error instanceof DwemerPluginPackageException ? $error->getMessage() : 'Release check failed.';
                    $results[] = ['id' => $entry['id'], 'channel' => $channel['id'], 'version' => null, 'error' => $message];
                }
            }
        }
        return $results;
    }

    /**
     * Streams an HTTPS download into $destination with a hard byte limit.
     * $progress receives (bytesReceived, bytesTotal) at most about once per second.
     */
    public function download(string $url, string $destination, int $maxBytes, ?callable $progress = null): int
    {
        $output = fopen($destination, 'wb');
        if (!is_resource($output)) {
            throw new DwemerPluginPackageException('Could not open the package download file.');
        }
        $received = 0;
        $lastReport = 0.0;
        try {
            $this->request($url, 600, function (string $chunk) use ($output, $maxBytes, &$received, $progress, &$lastReport): bool {
                $received += strlen($chunk);
                if ($received > $maxBytes || fwrite($output, $chunk) !== strlen($chunk)) {
                    return false;
                }
                if ($progress !== null && microtime(true) - $lastReport >= 1.0) {
                    $lastReport = microtime(true);
                    $progress($received);
                }
                return true;
            }, $maxBytes);
        } finally {
            fclose($output);
        }
        if ($received > $maxBytes) {
            throw new DwemerPluginPackageException('The package download exceeds the size limit.');
        }
        return $received;
    }

    public static function httpsHost(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s\x00-\x1f]/', $url)) {
            return null;
        }
        return strtolower((string)$parts['host']);
    }

    private function fetch(string $url, int $maxBytes, int $timeout): string
    {
        $body = '';
        $this->request($url, $timeout, function (string $chunk) use (&$body, $maxBytes): bool {
            $body .= $chunk;
            return strlen($body) <= $maxBytes;
        }, $maxBytes);
        if (strlen($body) > $maxBytes) {
            throw new DwemerPluginPackageException('The catalog release document is too large.');
        }
        return $body;
    }

    private function request(string $url, int $timeout, callable $sink, int $maxBytes): void
    {
        if (self::httpsHost($url) === null) {
            throw new DwemerPluginPackageException('Catalog downloads must use HTTPS.');
        }
        if (!function_exists('curl_init')) {
            throw new DwemerPluginPackageException('PHP curl support is required for catalog installs.');
        }
        $aborted = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/octet-stream, */*'],
            CURLOPT_FAILONERROR => true,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($sink, &$aborted): int {
                if (!$sink($chunk)) {
                    $aborted = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);
        curl_close($handle);
        // 63 = CURLE_FILESIZE_EXCEEDED: the declared Content-Length is over the limit.
        if ($aborted || $errno === 63) {
            throw new DwemerPluginPackageException('The download exceeded its size limit or could not be written.');
        }
        if ($ok === false || $status !== 200) {
            $detail = $status > 0 ? "HTTP {$status}" : ($error !== '' ? $error : 'no response');
            throw new DwemerPluginPackageException("Catalog request failed ({$detail}).");
        }
    }

    private function normalizeEntry(string $id, array $plugin): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            return null;
        }
        $name = (string)($plugin['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/', $name) || str_ends_with($name, '.') || str_ends_with($name, ' ')) {
            return null;
        }
        $homepage = (string)($plugin['homepage'] ?? '');
        if ($homepage !== '' && self::httpsHost($homepage) === null) {
            return null;
        }
        $channels = [];
        foreach ((array)($plugin['channels'] ?? []) as $channelId => $channel) {
            $channelId = (string)$channelId;
            if (!isset(self::CHANNELS[$channelId]) || !is_array($channel)) {
                continue;
            }
            $releaseUrl = (string)($channel['release_url'] ?? '');
            $releaseHost = self::httpsHost($releaseUrl);
            $packageTemplate = (string)($channel['package_url'] ?? '');
            $templateHost = $packageTemplate !== '' ? self::httpsHost(str_replace('<version>', '0', $packageTemplate)) : null;
            if ($releaseHost === null || ($packageTemplate !== '' && $templateHost === null)) {
                continue;
            }
            $hosts = [$releaseHost];
            if ($templateHost !== null) {
                $hosts[] = $templateHost;
            }
            foreach ((array)($channel['package_hosts'] ?? []) as $host) {
                if (is_string($host) && preg_match('/^[a-z0-9.-]{1,253}$/', strtolower($host))) {
                    $hosts[] = strtolower($host);
                }
            }
            $channels[$channelId] = [
                'id' => $channelId,
                'label' => self::CHANNELS[$channelId],
                'release_url' => $releaseUrl,
                'package_url' => $packageTemplate,
                'package_hosts' => array_values(array_unique($hosts)),
            ];
        }
        if (!$channels) {
            return null;
        }
        $default = (string)($plugin['default_channel'] ?? 'live');
        return [
            'id' => $id,
            'name' => $name,
            'description' => mb_substr(trim((string)($plugin['description'] ?? '')), 0, 300),
            'homepage' => $homepage,
            'default_channel' => isset($channels[$default]) ? $default : (string)array_key_first($channels),
            'channels' => $channels,
        ];
    }
}

/**
 * Runs a queued catalog job: resolve, download, verify, then install through the
 * package ledger. Failures are recorded on the job rather than thrown.
 */
function dwemerPluginCatalogRunJob(DwemerPluginPackageManager $manager, DwemerPluginCatalog $catalog, string $jobId, string $owner): array
{
    $job = $manager->claimQueuedJob($jobId, $owner);
    $origin = (array)($job['origin'] ?? []);
    $download = $manager->jobArchiveDownloadPath($jobId);
    try {
        $entry = $catalog->entry((string)($origin['catalog_id'] ?? ''));
        $channel = $catalog->channel($entry, (string)($origin['channel'] ?? ''));
        $manager->updateJob($jobId, ['status' => 'resolving']);
        $release = $catalog->resolveRelease($entry, $channel);
        $manager->updateJob($jobId, ['status' => 'downloading', 'version' => $release['version']]);
        $bytes = $catalog->download(
            $release['package_url'],
            $download,
            DwemerPluginPackageManager::MAX_ARCHIVE_BYTES,
            static function (int $received) use ($manager, $jobId): void {
                $manager->updateJob($jobId, ['bytes_received' => $received]);
            }
        );
        $manager->updateJob($jobId, ['bytes_received' => $bytes, 'bytes_total' => $bytes]);
        if ($release['sha256'] !== '' && !hash_equals($release['sha256'], (string)hash_file('sha256', $download))) {
            throw new DwemerPluginPackageException('The downloaded package does not match the catalog SHA-256.');
        }
        $fileName = basename((string)parse_url($release['package_url'], PHP_URL_PATH)) ?: 'catalog.dwpkg';
        return $manager->installArchive($download, $fileName, $entry['name'], $release['version'], $origin, $jobId);
    } catch (Throwable $error) {
        $message = $error instanceof DwemerPluginPackageException ? $error->getMessage() : 'Catalog install failed unexpectedly.';
        try {
            $current = $manager->getJob($jobId);
            if ($current['status'] !== 'failed') {
                $manager->updateJob($jobId, ['status' => 'failed', 'error' => $message]);
            }
        } catch (Throwable) {
        }
        if (!$error instanceof DwemerPluginPackageException) {
            error_log('[PLUGIN-CATALOG] ' . $error->getMessage());
        }
        return $manager->getJob($jobId);
    } finally {
        @unlink($download);
    }
}
