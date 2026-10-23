#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI de Synchronisation / Packaging d'une Formation pour Hébergement Web
 * Usage : php bin/sync-course.php <course-slug> <source-directory>
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = $argv[1] ?? 'postgresql-18';
$sourceDir = $argv[2] ?? realpath(__DIR__ . '/../../training_postgresql');

if (!$sourceDir || !is_dir((string)$sourceDir)) {
    fwrite(STDERR, "[ERREUR] Répertoire source introuvable.\n");
    exit(1);
}

$targetDir = __DIR__ . '/../storage/courses/' . $slug;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

$manifest = [
    'slug' => $slug,
    'synced_at' => date('Y-m-d H:i:s'),
    'source_dir' => $sourceDir,
];

file_put_contents($targetDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "[OK] Manifeste de la formation '{$slug}' synchronisé dans {$targetDir}/manifest.json\n";
