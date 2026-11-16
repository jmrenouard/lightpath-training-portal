#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI utility for synchronizing and packaging a course directory for web deployment.
 *
 * Usage:
 *   php bin/sync-course.php <course-slug> <source-directory>
 */

require_once __DIR__ . '/../src/bootstrap.php';

$rawSlug = (string)($argv[1] ?? 'postgresql-18');
$slug = (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $rawSlug);
if ($slug === '') {
    $slug = 'postgresql-18';
}
$sourceDir = $argv[2] ?? realpath(__DIR__ . '/../../training_postgresql');

if (!$sourceDir || !is_dir((string)$sourceDir)) {
    fwrite(STDERR, "[ERROR] Source directory not found.\n");
    exit(1);
}

$targetDir = __DIR__ . '/../storage/courses/' . $slug;
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "[ERROR] Failed to create target directory: {$targetDir}\n");
    exit(1);
}

$manifest = [
    'slug' => $slug,
    'synced_at' => date('Y-m-d H:i:s'),
    'source_dir' => $sourceDir,
];

$jsonPayload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false || file_put_contents($targetDir . '/manifest.json', $jsonPayload, LOCK_EX) === false) {
    fwrite(STDERR, "[ERROR] Failed to write course manifest to {$targetDir}/manifest.json\n");
    exit(1);
}
echo "[OK] Course manifest '{$slug}' synchronized to {$targetDir}/manifest.json\n";
