<?php

// Builds ext/parity_probe/ into a schema-4 server package (.dwpkg):
//   manifest.json, checksums.sha256 and server/<plugin files>.
// Entries are sorted and timestamped at 1980-01-01, so the same source and
// version give the same archive bytes. Requires PHP's zip extension.
//
//   php examples/plugin-parity/build_package.php <output.dwpkg> [version]

declare(strict_types=1);

$output = $argv[1] ?? '';
$version = $argv[2] ?? '1.0.0';
if ($output === '' || !preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version)) {
    fwrite(STDERR, "usage: php build_package.php <output.dwpkg> [version]\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip extension is required.\n");
    exit(2);
}

$source = __DIR__ . '/ext/parity_probe';
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
    $files['server/' . $relative] = (string)file_get_contents($file->getPathname());
}
ksort($files, SORT_STRING);

$files['manifest.json'] = json_encode([
    'schema_version' => 4,
    'name' => 'parity_probe',
    'version' => $version,
    'description' => 'Plugin parity example: prompt injections, actor enricher, ExtCmdParityProbe_Ping and a completion observer.',
    'server' => ['mutable_paths' => []],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$checksums = '';
foreach ($files as $path => $contents) {
    $checksums .= hash('sha256', $contents) . '  ' . $path . "\n";
}
$files['checksums.sha256'] = $checksums;

@unlink($output);
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Could not create {$output}\n");
    exit(1);
}
foreach (['manifest.json', 'checksums.sha256'] as $first) {
    $zip->addFromString($first, $files[$first]);
    unset($files[$first]);
}
foreach ($files as $path => $contents) {
    $zip->addFromString($path, $contents);
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $zip->setMtimeIndex($index, 315532800);
}
$zip->close();
echo $output . ' sha256=' . hash_file('sha256', $output) . PHP_EOL;
