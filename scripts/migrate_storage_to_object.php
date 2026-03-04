<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$dryRun = !in_array('--apply', $argv, true);

$sources = [
    rtrim(PRIVATE_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR,
    rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR,
];
$targetBase = rtrim(OBJECT_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR;

if (!is_dir($targetBase) && !$dryRun) {
    mkdir($targetBase, 0755, true);
}

$copied = 0;
$skipped = 0;
$errors = 0;

$extensions = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

echo "Storage migration to object path\n";
echo "================================\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'APPLY') . "\n";
echo "Target: {$targetBase}\n\n";

foreach ($sources as $sourceBase) {
    if (!is_dir($sourceBase)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceBase, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $ext = strtolower((string)$fileInfo->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }

        $absolutePath = $fileInfo->getPathname();
        $relativePath = str_replace(['\\', '//'], ['/', '/'], substr($absolutePath, strlen($sourceBase)));
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            $skipped++;
            continue;
        }

        $destination = $targetBase . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $destinationDir = dirname($destination);

        if (is_file($destination)) {
            $skipped++;
            continue;
        }

        echo "[COPY] {$relativePath}\n";
        if ($dryRun) {
            $copied++;
            continue;
        }

        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
            echo "[ERR ] failed to create directory: {$destinationDir}\n";
            $errors++;
            continue;
        }

        if (!copy($absolutePath, $destination)) {
            echo "[ERR ] failed to copy: {$absolutePath}\n";
            $errors++;
            continue;
        }

        $copied++;
    }
}

echo "\nSummary\n";
echo "-------\n";
echo "Copied: {$copied}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: {$errors}\n";

if ($errors > 0) {
    exit(2);
}
exit(0);

