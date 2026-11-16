#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI utility for managing per-user PostgreSQL 18 Docker lab containers and oracles.
 *
 * Usage:
 *   php bin/lab-runner.php list [--json]
 *   php bin/lab-runner.php fleet [--json]
 *   php bin/lab-runner.php status <uid> [--json]
 *   php bin/lab-runner.php start <uid> [--json]
 *   php bin/lab-runner.php stop <uid> [--json]
 *   php bin/lab-runner.php restart <uid> [--json]
 *   php bin/lab-runner.php reset <uid> [--json]
 *   php bin/lab-runner.php seed <uid> [all|sakila|pgbench] [--no-reset] [--json]
 *   php bin/lab-runner.php logs <uid> [tail] [--json]
 *   php bin/lab-runner.php stats <uid> [--json]
 *   php bin/lab-runner.php oracle <uid> <lab_id> [--json]
 *   php bin/lab-runner.php savepoint <uid> <name|list|create|restore|delete> [name_or_db] [db] [--json]
 *   php bin/lab-runner.php restore <uid> <name> [db] [--json]
 *   php bin/lab-runner.php bench <uid> [clients] [duration] [db] [--select-only] [--json]
 *   php bin/lab-runner.php diag <uid> [db] [--json]
 *   php bin/lab-runner.php readiness <uid> <lab_id> [--json]
 *   php bin/lab-runner.php reap-idle [max_idle_seconds] [--json]
 *   php bin/lab-runner.php heal [course_slug] [--json]
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
$selectOnly = false;
$optClients = null;
$optDuration = null;
$optDb = null;
$optMaxIdle = null;
$optCourse = null;
$positional = [];

foreach ($rawArgs as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } elseif ($arg === '--no-reset') {
        $noReset = true;
    } elseif ($arg === '--select-only') {
        $selectOnly = true;
    } elseif (str_starts_with($arg, '--clients=')) {
        $optClients = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--duration=')) {
        $optDuration = (int)substr($arg, 11);
    } elseif (str_starts_with($arg, '--db=')) {
        $optDb = substr($arg, 5);
    } elseif (str_starts_with($arg, '--max-idle=')) {
        $optMaxIdle = (int)substr($arg, 11);
    } elseif (str_starts_with($arg, '--course=')) {
        $optCourse = substr($arg, 9);
    } else {
        $positional[] = $arg;
    }
}

$cmd = strtolower((string)($positional[0] ?? 'list'));
$arg1 = $positional[1] ?? null;
$arg2 = $positional[2] ?? null;
$arg3 = $positional[3] ?? null;
$arg4 = $positional[4] ?? null;
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

    case 'fleet':
        $emit($docker->getFleetSummary());
        exit(0);

    case 'savepoint':
        if ($arg2 === null || trim((string)$arg2) === '') {
            $err = ['success' => false, 'error' => 'Missing savepoint name or operation. Usage: php bin/lab-runner.php savepoint <uid> <name|list|create|restore|delete> [name_or_db] [db] [--json]'];
            $emit($err);
            exit(1);
        }
        $spOp = strtolower(trim((string)$arg2));
        if ($spOp === 'list' && $arg3 === null) {
            $res = $docker->listSavepoints($userId, true);
        } elseif ($spOp === 'delete') {
            if ($arg3 === null || trim((string)$arg3) === '') {
                $emit(['success' => false, 'error' => 'Missing <name>. Usage: php bin/lab-runner.php savepoint <uid> delete <name> [--json]']);
                exit(1);
            }
            $res = $docker->deleteSavepoint($userId, (string)$arg3, true);
        } elseif ($spOp === 'restore') {
            if ($arg3 === null || trim((string)$arg3) === '') {
                $emit(['success' => false, 'error' => 'Missing <name>. Usage: php bin/lab-runner.php savepoint <uid> restore <name> [db] [--json]']);
                exit(1);
            }
            $db = $optDb ?? ($arg4 !== null ? (string)$arg4 : 'formation_pg');
            $res = $docker->restoreSavepoint($userId, (string)$arg3, $db, true);
        } elseif ($spOp === 'create') {
            if ($arg3 === null || trim((string)$arg3) === '') {
                $emit(['success' => false, 'error' => 'Missing <name>. Usage: php bin/lab-runner.php savepoint <uid> create <name> [db] [--json]']);
                exit(1);
            }
            $db = $optDb ?? ($arg4 !== null ? (string)$arg4 : 'formation_pg');
            $res = $docker->createSavepoint($userId, (string)$arg3, $db, true);
        } else {
            $db = $optDb ?? ($arg3 !== null ? (string)$arg3 : 'formation_pg');
            $res = $docker->createSavepoint($userId, (string)$arg2, $db, true);
        }
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'restore':
        if ($arg2 === null || trim((string)$arg2) === '') {
            $emit(['success' => false, 'error' => 'Missing <name>. Usage: php bin/lab-runner.php restore <uid> <name> [db] [--json]']);
            exit(1);
        }
        $db = $optDb ?? ($arg3 !== null ? (string)$arg3 : 'formation_pg');
        $res = $docker->restoreSavepoint($userId, (string)$arg2, $db, true);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'bench':
        $clients = $optClients ?? 4;
        $duration = $optDuration ?? 2;
        $db = $optDb ?? 'formation_pg';
        if ($arg2 !== null) {
            if (is_numeric($arg2)) {
                $clients = (int)$arg2;
            } else {
                $db = (string)$arg2;
            }
        }
        if ($arg3 !== null) {
            if (is_numeric($arg3)) {
                $duration = (int)$arg3;
            } else {
                $db = (string)$arg3;
            }
        }
        if ($arg4 !== null && trim((string)$arg4) !== '') {
            $db = (string)$arg4;
        }
        $res = $docker->runPgbenchBenchmark($userId, $clients, $duration, $selectOnly, $db, true);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'diag':
        $db = $optDb ?? ($arg2 !== null ? (string)$arg2 : 'formation_pg');
        $res = $sqlRunner->inspectPgDiagnostics($userId, $db, true);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'readiness':
        if ($arg2 === null || trim((string)$arg2) === '') {
            $emit(['success' => false, 'ready' => false, 'error' => 'Missing <lab_id>. Usage: php bin/lab-runner.php readiness <uid> <lab_id> [--json]']);
            exit(1);
        }
        $res = $sqlRunner->checkLabReadiness($userId, (string)$arg2, true);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'reap-idle':
        $maxIdle = $optMaxIdle ?? ($arg1 !== null && is_numeric($arg1) ? (int)$arg1 : 3600);
        $res = $docker->reapIdleContainers($maxIdle);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

    case 'heal':
        $courseSlug = $optCourse ?? ($arg1 !== null && trim((string)$arg1) !== '' ? (string)$arg1 : 'postgresql-18');
        $res = $docker->healChaosScenario($courseSlug);
        $emit($res);
        exit(!empty($res['success']) ? 0 : 1);

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
        echo "Commands: list | fleet | status <uid> | start <uid> | stop <uid> | restart <uid> | reset <uid> | seed <uid> [dataset] | logs <uid> [tail] | stats <uid> | oracle <uid> <lab_id> | savepoint <uid> <name|list|create|restore|delete> [name_or_db] [db] | restore <uid> <name> [db] | bench <uid> [clients] [duration] [db] [--select-only] | diag <uid> [db] | readiness <uid> <lab_id> | reap-idle [max_idle_seconds] | heal [course_slug] | stop-all | prune [--json]\n";
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
