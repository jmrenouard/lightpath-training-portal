#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI de Gestion des Conteneurs Docker par Utilisateur
 * Usage :
 *   php bin/lab-runner.php list
 *   php bin/lab-runner.php status <user_id>
 *   php bin/lab-runner.php start <user_id>
 *   php bin/lab-runner.php stop <user_id>
 *   php bin/lab-runner.php reset <user_id>
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Docker\DockerManager;

$appConfig = require __DIR__ . '/../config/app.php';
$storage = new JsonStorage($appConfig['storage_path']);
$docker = new DockerManager($storage, $appConfig['docker']);

$cmd = $argv[1] ?? 'list';
$userId = isset($argv[2]) ? (int)$argv[2] : 1;

switch ($cmd) {
    case 'list':
        $list = $docker->listAllLabContainers();
        echo "Conteneurs Lab actifs : " . count($list) . "\n";
        foreach ($list as $c) {
            echo " - {$c['name']} | {$c['image']} | {$c['status']} | {$c['ports']}\n";
        }
        break;
    case 'status':
        print_r($docker->getUserContainerStatus($userId));
        break;
    case 'start':
        print_r($docker->startUserContainer($userId, 'cli-admin'));
        break;
    case 'stop':
        print_r($docker->stopUserContainer($userId, 'cli-admin'));
        break;
    case 'reset':
        print_r($docker->resetUserContainer($userId, 'cli-admin'));
        break;
    default:
        echo "Commande inconnue: {$cmd}\n";
        exit(1);
}
