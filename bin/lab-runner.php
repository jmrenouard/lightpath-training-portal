#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI utility for managing per-user PostgreSQL 18 Docker lab containers and oracles.
 *
 * Usage:
 *   php bin/lab-runner.php list [--json]
 *   php bin/lab-runner.php status <uid> [--json]
 *   php bin/lab-runner.php start <uid> [--json]
 *   php bin/lab-runner.php stop <uid> [--json]
 *   php bin/lab-runner.php restart <uid> [--json]
 *   php bin/lab-runner.php reset <uid> [--json]
 *   php bin/lab-runner.php seed <uid> [all|sakila|pgbench] [--no-reset] [--json]
 *   php bin/lab-runner.php logs <uid> [tail] [--json]
 *   php bin/lab-runner.php stats <uid> [--json]
 *   php bin/lab-runner.php oracle <uid> <lab_id> [--json]
 *   php bin/lab-runner.php stop-all [--json]
 *   php bin/lab-runner.php prune [--json]
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;

$appConfig = require __DIR__ . '/../config/app.php';
$storage = new JsonStorage($appConfig['storage_path']);
$docker = new DockerManager($storage, $appConfig['docker']);
$sqlRunner = new LabSqlRunner($docker);

$rawArgs = array_slice($argv, 1);
$asJson = false;
$noReset = false;
$positional = [];

foreach ($rawArgs as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } elseif ($arg === '--no-reset') {
        $noReset = true;
    } else {
        $positional[] = $arg;
    }
}

$cmd = strtolower((string)($positional[0] ?? 'list'));
$arg1 = $positional[1] ?? null;
$arg2 = $positional[2] ?? null;
$userId = max(1, (int)($arg1 ?? 1));

$emit = static function (array $payload) use ($asJson): void {
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }
    print_r($payload);
};

switch ($cmd) {
    case 'list':
        $list = $docker->listAllLabContainers();
        if ($asJson) {
            $emit(['count' => count($list), 'containers' => $list]);
        } else {
            echo "Active/managed lab containers: " . count($list) . "\n";
            foreach ($list as $c) {
                echo " - {$c['name']} | {$c['image']} | {$c['status']} | {$c['ports']}\n";
            }
        }
        exit(0);

    case 'status':
        $emit($docker->getUserContainerStatus($userId));
        exit(0);

    case 'start':
        $res = $docker->startUserContainer($userId, 'cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'stop':
        $res = $docker->stopUserContainer($userId, 'cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'restart':
        $res = $docker->restartUserContainer($userId, 'cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'reset':
        $res = $docker->resetUserContainer($userId, 'cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'seed':
        $dataset = (string)($arg2 ?? 'all');
        $res = $docker->seedUserContainer($userId, $dataset, !$noReset, 'cli-admin', true);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'logs':
        $tail = $arg2 !== null ? (int)$arg2 : 60;
        $res = $docker->getContainerLogs($userId, $tail, true);
        if ($asJson) {
            $emit($res);
        } else {
            echo "[Container: {$res['container']} | Mode: {$res['mode']} | Tail: {$res['tail']}]\n";
            $logBody = ($res['logs'] ?? '') !== '' ? $res['logs'] : ($res['message'] ?? '');
            echo $logBody . "\n";
        }
        exit(!empty($res['success']) ? 0 : 1);

    case 'stats':
        $res = $docker->getContainerStats($userId);
        $emit($res);
        exit(0);

    case 'oracle':
        if ($arg2 === null || trim((string)$arg2) === '') {
            $err = ['passed' => false, 'error' => 'Missing <lab_id>. Usage: php bin/lab-runner.php oracle <uid> <lab_id> [--json]'];
            $emit($err);
            exit(1);
        }
        $res = $sqlRunner->runLabOracle($userId, (string)$arg2);
        $emit($res);
        exit(!empty($res['passed']) ? 0 : 1);

    case 'stop-all':
        $res = $docker->stopAllLabContainers('cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'prune':
        $res = $docker->pruneStoppedLabContainers('cli-admin');
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'help':
    case '-h':
    case '--help':
        echo "Commands: list | status <uid> | start <uid> | stop <uid> | restart <uid> | reset <uid> | seed <uid> [dataset] | logs <uid> [tail] | stats <uid> | oracle <uid> <lab_id> | stop-all | prune [--json]\n";
        exit(0);

    default:
        $msg = "Unknown command: {$cmd}";
        if ($asJson) {
            $emit(['success' => false, 'error' => $msg]);
        } else {
            fwrite(STDERR, $msg . "\n");
        }
        exit(1);
}
