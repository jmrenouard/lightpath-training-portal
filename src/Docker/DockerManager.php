<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\StorageInterface;

/**
 * Manages per-user isolated PostgreSQL 18 Docker lab containers, non-blocking
 * runtime checks, resource limits, sandbox savepoints, bounded benchmarks, and
 * Sakila/pgbench dataset seeding.
 */
class DockerManager
{
    public const ALLOWED_DATABASES = ['formation_pg', 'dvdrental', 'postgres'];
    public const MAX_SAVEPOINTS_PER_USER = 5;
    private const SAVEPOINT_DIR = '/tmp/lms_savepoints';

    private StorageInterface $storage;
    private array $config;
    private string $cacheFile;
    private static ?array $runtimeCache = null;

    public function __construct(StorageInterface $storage, array $config = [], ?string $cacheFile = null)
    {
        $this->storage = $storage;
        $this->config = array_merge([
            'base_port' => 5500,
            'image' => 'docker-pg-primary:18',
            'fallback_image' => 'postgres:18',
            'default_db' => 'formation_pg',
            'default_user' => 'postgres',
            'default_password' => 'postgres',
            'wsl_distro' => 'Ubuntu-24.04',
            'max_containers' => 40,
        ], $config);
        $this->cacheFile = $cacheFile ?? ($this->resolveStorageDataDir() . '/docker_status_cache.json');
    }

    public function getDefaultPassword(): string
    {
        return (string)($this->config['default_password'] ?? 'postgres');
    }

    public function getUserPort(int $userId): int
    {
        $basePort = max(1024, min(60000, (int)$this->config['base_port']));
        $safeId = max(1, $userId);
        $port = $basePort + $safeId;
        if ($port > 65535) {
            $port = $basePort + (($safeId - 1) % (65535 - $basePort)) + 1;
        }
        return $port;
    }

    public function getContainerName(int $userId): string
    {
        return 'lp_pg18_user_' . max(1, $userId);
    }

    public function getConnectionInfo(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $db = (string)($this->config['default_db'] ?? 'formation_pg');
        $user = (string)($this->config['default_user'] ?? 'postgres');

        return [
            'container_name' => $name,
            'host' => 'localhost',
            'port' => $port,
            'database' => $db,
            'username' => $user,
            'password' => $this->getDefaultPassword(),
            'connection_string' => sprintf('psql -h localhost -p %d -U %s -d %s', $port, $user, $db),
        ];
    }

    /**
     * Executes a shell command with an explicit wall-clock timeout so stalled
     * WSL2 or Docker daemon calls never block PHP workers.
     */
    public function execCommandWithTimeout(string $cmd, float $timeoutSeconds = 4.0): array
    {
        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $stdoutFile = $isWindows ? @tempnam(sys_get_temp_dir(), 'lp_out_') : false;
        $stderrFile = $isWindows ? @tempnam(sys_get_temp_dir(), 'lp_err_') : false;
        $useTempFiles = ($isWindows && is_string($stdoutFile) && is_string($stderrFile));

        $descriptors = $useTempFiles
            ? [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $exitCode = 1;

        try {
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return ['code' => 1, 'output' => 'Unable to spawn command process.', 'timed_out' => false];
            }

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                @fclose($pipes[0]);
            }
            if (!$useTempFiles) {
                foreach ([1, 2] as $idx) {
                    if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                        @stream_set_blocking($pipes[$idx], false);
                    }
                }
            }

            $deadline = microtime(true) + max(0.2, $timeoutSeconds);

            while (true) {
                if (!$useTempFiles) {
                    foreach ([1 => &$stdout, 2 => &$stderr] as $idx => &$buf) {
                        if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                            $chunk = @fread($pipes[$idx], 8192);
                            if ($chunk !== false && $chunk !== '') {
                                $buf .= $chunk;
                            }
                        }
                    }
                    unset($buf);
                }

                $status = @proc_get_status($proc);
                if (!is_array($status) || empty($status['running'])) {
                    $exitCode = is_array($status) ? (int)($status['exitcode'] ?? 0) : 0;
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    // Terminate the entire process tree before closing parent handle on Windows.
                    if ($isWindows && !empty($status['pid'])) {
                        $killProc = @proc_open(
                            'taskkill /F /T /PID ' . (int)$status['pid'],
                            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                            $killPipes,
                            null,
                            null,
                            ['bypass_shell' => true]
                        );
                        if (is_resource($killProc)) {
                            foreach ($killPipes as $kp) {
                                if (is_resource($kp)) {
                                    @fclose($kp);
                                }
                            }
                            @proc_close($killProc);
                        }
                    }
                    @proc_terminate($proc, 9);
                    $exitCode = 124;
                    break;
                }

                usleep(20000);
            }

            if (!$useTempFiles) {
                foreach ([1, 2] as $idx) {
                    if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                        if (!$timedOut) {
                            while (!feof($pipes[$idx])) {
                                $rem = @fread($pipes[$idx], 8192);
                                if ($rem === false || $rem === '') {
                                    break;
                                }
                                if ($idx === 1) {
                                    $stdout .= $rem;
                                } else {
                                    $stderr .= $rem;
                                }
                            }
                        }
                        @fclose($pipes[$idx]);
                    }
                }
            }

            $closedCode = @proc_close($proc);
            if (!$timedOut && $exitCode === -1 && $closedCode >= 0) {
                $exitCode = $closedCode;
            }

            if ($useTempFiles) {
                $outData = @file_get_contents($stdoutFile);
                $errData = @file_get_contents($stderrFile);
                $stdout = is_string($outData) ? $outData : '';
                $stderr = is_string($errData) ? $errData : '';
            }
        } finally {
            if (is_string($stdoutFile) && is_file($stdoutFile)) {
                @unlink($stdoutFile);
            }
            if (is_string($stderrFile) && is_file($stderrFile)) {
                @unlink($stderrFile);
            }
        }

        $combined = trim($stdout !== '' && $stderr !== '' ? ($stdout . "\n" . $stderr) : ($stdout . $stderr));
        if ($timedOut && $combined === '') {
            $combined = sprintf('Command timed out after %.1fs.', $timeoutSeconds);
        }

        return [
            'code' => $exitCode,
            'output' => $combined,
            'timed_out' => $timedOut,
        ];
    }

    private function readStatusCache(): array
    {
        if (!is_file($this->cacheFile)) {
            return ['runtime' => null, 'users' => []];
        }
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return ['runtime' => null, 'users' => []];
        }
        $decoded = @json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['runtime' => null, 'users' => []];
    }

    private function writeStatusCache(array $cache): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lockFile = $dir . '/docker_status_cache.lock';
        $lockFp = @fopen($lockFile, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $tmpPath = $this->cacheFile . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
            $json = (string)json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($tmpPath, $json, LOCK_EX);
            if (!@rename($tmpPath, $this->cacheFile)) {
                @copy($tmpPath, $this->cacheFile);
                @unlink($tmpPath);
            }
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }

    public function detectRuntime(bool $forceRefresh = false): array
    {
        if (getenv('LMS_DOCKER_FORCE_SIMULATED') === '1') {
            self::$runtimeCache = [
                'available' => false,
                'mode' => 'cloud_simulated',
                'prefix' => '',
                'version' => 'Mode Cloud / Sandbox Simulée',
                'cached_at' => time(),
            ];
            return self::$runtimeCache;
        }

        if (!$forceRefresh && self::$runtimeCache !== null) {
            return self::$runtimeCache;
        }

        if (!$forceRefresh) {
            $cache = $this->readStatusCache();
            if (isset($cache['runtime']) && is_array($cache['runtime']) && isset($cache['runtime']['mode'])) {
                self::$runtimeCache = $cache['runtime'];
                return self::$runtimeCache;
            }
        }

        // 1. On Windows with WSL2, probe `wsl docker` first before native Docker.
        if (PHP_OS_FAMILY === 'Windows') {
            $wslRes = $this->execCommandWithTimeout('wsl docker --version 2>&1', 3.0);
            if ($wslRes['code'] === 0 && $wslRes['output'] !== '') {
                self::$runtimeCache = [
                    'available' => true,
                    'mode' => 'wsl2',
                    'prefix' => 'wsl docker',
                    'version' => $wslRes['output'],
                    'cached_at' => time(),
                ];
                $cache = $this->readStatusCache();
                $cache['runtime'] = self::$runtimeCache;
                $this->writeStatusCache($cache);
                return self::$runtimeCache;
            }
        }

        // 2. Probe native Docker daemon.
        $nativeRes = $this->execCommandWithTimeout('docker info --format "{{.ServerVersion}}" 2>&1', 3.0);
        if ($nativeRes['code'] === 0 && $nativeRes['output'] !== '') {
            self::$runtimeCache = [
                'available' => true,
                'mode' => 'native',
                'prefix' => 'docker',
                'version' => $nativeRes['output'],
                'cached_at' => time(),
            ];
            $cache = $this->readStatusCache();
            $cache['runtime'] = self::$runtimeCache;
            $this->writeStatusCache($cache);
            return self::$runtimeCache;
        }

        self::$runtimeCache = [
            'available' => false,
            'mode' => 'cloud_simulated',
            'prefix' => '',
            'version' => 'Mode Cloud / Sandbox Simulée',
            'cached_at' => time(),
        ];
        return self::$runtimeCache;
    }

    public function runDockerCommand(string $args, float $timeoutSeconds = 12.0): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return ['code' => 1, 'output' => 'Docker daemon non disponible sur cet hôte.', 'timed_out' => false];
        }

        $prefix = trim((string)($rt['prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = 'docker';
        }
        $cmd = $prefix . ' ' . $args . ' 2>&1';
        return $this->execCommandWithTimeout($cmd, $timeoutSeconds);
    }

    /**
     * Performs an instant (< 0.2 ms) cache lookup without blocking shell calls for HTML page rendering.
     */
    public function getCachedUserContainerStatus(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $cache = $this->readStatusCache();

        if (isset($cache['users'][(string)$userId]) && is_array($cache['users'][(string)$userId])) {
            $cached = $cache['users'][(string)$userId];
            if (!array_key_exists('last_activity_at', $cached)) {
                $cached['last_activity_at'] = null;
            }
            return $cached;
        }

        $rtMode = $cache['runtime']['mode'] ?? (PHP_OS_FAMILY === 'Windows' ? 'wsl2' : 'native');
        $rtVersion = $cache['runtime']['version'] ?? 'Docker Engine (WSL2 / PG 18)';

        return [
            'container_name' => $name,
            'port' => $port,
            'status' => 'not_checked',
            'running' => false,
            'user_container_running' => false,
            'primary_cluster_running' => false,
            'default_password' => $this->getDefaultPassword(),
            'runtime_mode' => $rtMode,
            'runtime_version' => $rtVersion,
            'connection_string' => sprintf('psql -h localhost -p %d -U %s -d %s', $port, $this->config['default_user'], $this->config['default_db']),
            'last_activity_at' => null,
            'from_cache' => true,
        ];
    }

    public function getUserContainerStatus(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $rt = $this->detectRuntime();
        $connStr = sprintf('psql -h localhost -p %d -U %s -d %s', $port, $this->config['default_user'], $this->config['default_db']);

        if (!$rt['available']) {
            $status = [
                'container_name' => $name,
                'port' => $port,
                'status' => 'simulated',
                'running' => false,
                'user_container_running' => false,
                'primary_cluster_running' => false,
                'default_password' => $this->getDefaultPassword(),
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => $connStr,
                'checked_at' => time(),
            ];
            return $this->saveUserStatusCache($userId, $status);
        }

        $res = $this->runDockerCommand('inspect -f "{{.State.Status}}|{{.State.StartedAt}}" ' . $name, 4.0);
        if ($res['code'] === 0 && str_contains($res['output'], '|')) {
            [$state, $startedAt] = explode('|', trim($res['output'], "\"' \r\n"), 2);
            $running = trim($state) === 'running';
            $status = [
                'container_name' => $name,
                'port' => $port,
                'status' => trim($state),
                'running' => $running,
                'user_container_running' => $running,
                'primary_cluster_running' => $running,
                'default_password' => $this->getDefaultPassword(),
                'started_at' => substr(trim($startedAt), 0, 19),
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => $connStr,
                'checked_at' => time(),
            ];
            return $this->saveUserStatusCache($userId, $status);
        }

        $primaryRes = $this->runDockerCommand('inspect -f "{{.State.Status}}" pg_formation_primary', 4.0);
        $primaryRunning = ($primaryRes['code'] === 0 && trim($primaryRes['output'], "\"' \r\n") === 'running');

        // Keep running/user_container_running false when the personal container does not exist yet.
        $status = [
            'container_name' => $name,
            'port' => $port,
            'status' => $primaryRunning ? 'cluster_ready' : 'not_created',
            'running' => false,
            'user_container_running' => false,
            'primary_cluster_running' => $primaryRunning,
            'default_password' => $this->getDefaultPassword(),
            'runtime_mode' => $rt['mode'],
            'runtime_version' => $rt['version'],
            'connection_string' => $connStr,
            'checked_at' => time(),
        ];
        return $this->saveUserStatusCache($userId, $status);
    }

    /**
     * Records the latest activity timestamp for a user's lab sandbox container.
     */
    public function touchContainerActivity(int $userId): int
    {
        $safeId = max(1, $userId);
        $now = time();
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lockFile = $dir . '/docker_status_cache.lock';
        $lockFp = @fopen($lockFile, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $cache = $this->readStatusCache();
            if (!isset($cache['users']) || !is_array($cache['users'])) {
                $cache['users'] = [];
            }
            $existing = isset($cache['users'][(string)$safeId]) && is_array($cache['users'][(string)$safeId])
                ? $cache['users'][(string)$safeId]
                : [
                    'container_name' => $this->getContainerName($safeId),
                    'port' => $this->getUserPort($safeId),
                    'status' => 'not_checked',
                    'running' => false,
                    'user_container_running' => false,
                    'primary_cluster_running' => false,
                    'default_password' => $this->getDefaultPassword(),
                    'connection_string' => sprintf(
                        'psql -h localhost -p %d -U %s -d %s',
                        $this->getUserPort($safeId),
                        $this->config['default_user'],
                        $this->config['default_db']
                    ),
                ];
            $existing['last_activity_at'] = $now;
            unset($existing['reaped']);
            $cache['users'][(string)$safeId] = $existing;

            $tmpPath = $this->cacheFile . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
            $json = (string)json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($tmpPath, $json, LOCK_EX);
            if (!@rename($tmpPath, $this->cacheFile)) {
                @copy($tmpPath, $this->cacheFile);
                @unlink($tmpPath);
            }
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
        return $now;
    }

    private function saveUserStatusCache(int $userId, array $status): array
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lockFile = $dir . '/docker_status_cache.lock';
        $lockFp = @fopen($lockFile, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $cache = $this->readStatusCache();
            $existing = isset($cache['users'][(string)$userId]) && is_array($cache['users'][(string)$userId])
                ? $cache['users'][(string)$userId]
                : [];
            if (!array_key_exists('last_activity_at', $status)) {
                $status['last_activity_at'] = $existing['last_activity_at'] ?? null;
            }
            if (isset($existing['reaped']) && !isset($status['reaped'])) {
                $status['reaped'] = $existing['reaped'];
            }
            $cache['users'][(string)$userId] = $status;
            $tmpPath = $this->cacheFile . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
            $json = (string)json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($tmpPath, $json, LOCK_EX);
            if (!@rename($tmpPath, $this->cacheFile)) {
                @copy($tmpPath, $this->cacheFile);
                @unlink($tmpPath);
            }
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
        return $status;
    }

    public function startUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            return [
                'success' => false,
                'simulated' => true,
                'message' => 'Docker n\'est pas actif sur cet hôte (Mode Cloud Simulé).',
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        if ($rt['mode'] === 'wsl2') {
            $ping = $this->runDockerCommand('ps -q', 4.0);
            if ($ping['code'] !== 0) {
                $this->execCommandWithTimeout('wsl -u root service docker start 2>&1', 8.0);
            }
        }

        $inspect = $this->runDockerCommand('inspect -f "{{.State.Status}}" ' . $name, 4.0);
        if ($inspect['code'] === 0) {
            if (trim($inspect['output'], "\"' \r\n") !== 'running') {
                $this->runDockerCommand('start ' . $name, 15.0);
            }
            $this->bootstrapContainerSchema($name);
            $this->touchContainerActivity($userId);
            $this->storage->logAuditEvent($userEmail, 'DOCKER_START', "Démarrage du conteneur existant {$name} (port {$port})");
            return [
                'success' => true,
                'message' => "Conteneur personnel {$name} démarré sur le port {$port}.",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $maxContainers = max(1, (int)($this->config['max_containers'] ?? 40));
        $existingSandboxes = array_filter(
            $this->listAllLabContainers(),
            static fn(array $c): bool => ($c['type'] ?? '') === 'user_sandbox'
        );
        if (count($existingSandboxes) >= $maxContainers) {
            return [
                'success' => false,
                'message' => "Quota maximal de conteneurs atteint ({$maxContainers}). Demandez au formateur de purger les instances arrêtées.",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $safeImage = (string)preg_replace('/[^a-zA-Z0-9_.\-:\/]/', '', (string)$this->config['image']);
        $safeFallback = (string)preg_replace('/[^a-zA-Z0-9_.\-:\/]/', '', (string)$this->config['fallback_image']);
        $safePw = (string)preg_replace('/[^a-zA-Z0-9_.\-@!]/', '', $this->getDefaultPassword());

        $imgCheck = $this->runDockerCommand('image inspect ' . $safeImage, 4.0);
        $imageToUse = ($imgCheck['code'] === 0) ? $safeImage : $safeFallback;

        $runArgs = sprintf(
            'run -d --name %s --hostname %s -p %d:5432 --shm-size=512m --memory=1g --cpus=1.0 --pids-limit=256 --security-opt=no-new-privileges --restart=no --label lms.managed=lightpath --label lms.user_id=%d -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=%s -e POSTGRES_DB=formation_pg %s',
            $name,
            $name,
            $port,
            max(1, $userId),
            $safePw,
            $imageToUse
        );

        $runRes = $this->runDockerCommand($runArgs, 25.0);
        if ($runRes['code'] !== 0) {
            return [
                'success' => false,
                'message' => "Erreur lors de la création du conteneur {$name} : " . $runRes['output'],
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        for ($i = 0; $i < 8; $i++) {
            usleep(800000);
            $ready = $this->runDockerCommand('exec ' . $name . ' pg_isready -U postgres -d formation_pg', 4.0);
            if ($ready['code'] === 0) {
                break;
            }
        }

        $this->bootstrapContainerSchema($name);
        $this->touchContainerActivity($userId);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_PROVISION', "Provisionnement du conteneur dédié {$name} sur le port {$port} ({$imageToUse})");

        return [
            'success' => true,
            'message' => "Conteneur PostgreSQL 18 isolé ({$name}) créé et initialisé sur le port {$port}.",
            'status' => $this->getUserContainerStatus($userId),
        ];
    }

    public function stopUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [
                'success' => false,
                'simulated' => true,
                'message' => "Mode Cloud Simulé : aucun conteneur Docker actif à arrêter ({$name}).",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $res = $this->runDockerCommand('stop ' . $name, 15.0);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_STOP', "Arrêt du conteneur {$name}");
        return [
            'success' => $res['code'] === 0,
            'message' => $res['code'] === 0 ? "Conteneur {$name} arrêté." : $res['output'],
            'status' => $this->getUserContainerStatus($userId),
        ];
    }

    public function restartUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [
                'success' => false,
                'simulated' => true,
                'message' => "Mode Cloud Simulé : redémarrage indisponible sans daemon Docker ({$name}).",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $inspect = $this->runDockerCommand('inspect -f "{{.State.Status}}" ' . $name, 4.0);
        if ($inspect['code'] !== 0) {
            return $this->startUserContainer($userId, $userEmail);
        }

        $res = $this->runDockerCommand('restart ' . $name, 20.0);
        if ($res['code'] !== 0) {
            return [
                'success' => false,
                'message' => "Erreur lors du redémarrage de {$name} : " . $res['output'],
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        for ($i = 0; $i < 6; $i++) {
            usleep(500000);
            $ready = $this->runDockerCommand('exec ' . $name . ' pg_isready -U postgres -d formation_pg', 4.0);
            if ($ready['code'] === 0) {
                break;
            }
        }

        $this->bootstrapContainerSchema($name);
        $this->touchContainerActivity($userId);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_RESTART', "Redémarrage du conteneur {$name}");

        return [
            'success' => true,
            'message' => "Conteneur {$name} redémarré avec succès.",
            'status' => $this->getUserContainerStatus($userId),
        ];
    }

    public function resetUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [
                'success' => false,
                'simulated' => true,
                'message' => "Mode Cloud Simulé : réinitialisation disponible uniquement avec Docker actif ({$name}).",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $this->runDockerCommand('rm -f ' . $name, 10.0);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_RESET', "Réinitialisation complète du conteneur {$name}");
        return $this->startUserContainer($userId, $userEmail);
    }

    public function seedUserContainer(int $userId, string $dataset = 'all', bool $forceReset = true, string $actorEmail = 'student', bool $allowSharedFallback = false): array
    {
        $cleanDataset = strtolower(trim($dataset));
        if (!in_array($cleanDataset, ['all', 'sakila', 'pgbench'], true)) {
            $cleanDataset = 'all';
        }

        $rt = $this->detectRuntime();
        $catalog = SakilaSeeder::getDatasetCatalogSummary();
        if (!$rt['available']) {
            return [
                'success' => false,
                'simulated' => true,
                'mode' => $rt['mode'],
                'dataset' => $cleanDataset,
                'catalog' => $catalog,
                'message' => "Mode Cloud Simulé : le réamorçage Sakila / pgbench nécessite un daemon Docker actif.",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $status = $this->getUserContainerStatus($userId);
        $targetContainer = null;
        if (!empty($status['user_container_running'])) {
            $targetContainer = (string)$status['container_name'];
        } elseif ($allowSharedFallback && !empty($status['primary_cluster_running'])) {
            $targetContainer = 'pg_formation_primary';
        } else {
            return [
                'success' => false,
                'mode' => $rt['mode'],
                'dataset' => $cleanDataset,
                'catalog' => $catalog,
                'message' => 'Veuillez démarrer votre conteneur personnel avant cette opération.',
                'status' => $status,
            ];
        }

        $ok = $this->bootstrapContainerSchema($targetContainer, $forceReset, $cleanDataset);
        if ($ok) {
            $this->touchContainerActivity($userId);
        }
        $this->storage->logAuditEvent($actorEmail, 'DOCKER_SEED', "Réamorçage {$cleanDataset} (forceReset=" . ($forceReset ? 'true' : 'false') . ") sur {$targetContainer}");

        return [
            'success' => $ok,
            'mode' => $rt['mode'],
            'container' => $targetContainer,
            'dataset' => $cleanDataset,
            'force_reset' => $forceReset,
            'catalog' => $catalog,
            'message' => $ok
                ? "Jeux de données ({$cleanDataset}) réamorcés sur {$targetContainer} (formation_pg & dvdrental)."
                : "Échec partiel du réamorçage sur {$targetContainer}.",
            'status' => $this->getUserContainerStatus($userId),
        ];
    }

    public function getContainerLogs(int $userId, int $tail = 60, bool $allowSharedFallback = false): array
    {
        $clampedTail = max(10, min(200, $tail));
        $name = $this->getContainerName($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            return [
                'success' => true,
                'simulated' => true,
                'mode' => $rt['mode'],
                'container' => $name,
                'tail' => $clampedTail,
                'logs' => "[Mode Cloud Simulé — {$name}] Daemon Docker inactif sur cet hôte.\nLOG: database system is ready to accept connections (PostgreSQL 18 Catalogue Statique).",
            ];
        }

        $status = $this->getUserContainerStatus($userId);
        if (!empty($status['user_container_running'])) {
            $target = $name;
        } elseif ($allowSharedFallback && !empty($status['primary_cluster_running'])) {
            $target = 'pg_formation_primary';
        } else {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $rt['mode'],
                'container' => $name,
                'tail' => $clampedTail,
                'logs' => '',
                'message' => 'Veuillez démarrer votre conteneur personnel avant cette opération.',
            ];
        }

        $res = $this->runDockerCommand(sprintf('logs --tail %d %s', $clampedTail, $target), 6.0);
        return [
            'success' => $res['code'] === 0,
            'simulated' => false,
            'mode' => $rt['mode'],
            'container' => $target,
            'tail' => $clampedTail,
            'logs' => $res['output'] !== '' ? $res['output'] : '(Aucun log récent pour ce conteneur)',
        ];
    }

    public function getContainerStats(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            return [
                'success' => true,
                'simulated' => true,
                'mode' => $rt['mode'],
                'container' => $name,
                'running' => false,
                'cpu_percent' => '0.00%',
                'mem_usage' => '0B / 1GiB (Simulé)',
                'mem_percent' => '0.00%',
                'pids' => 0,
            ];
        }

        $res = $this->runDockerCommand(
            sprintf('stats --no-stream --format "{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.PIDs}}" %s', $name),
            5.0
        );
        if ($res['code'] !== 0 || !str_contains($res['output'], '|')) {
            return [
                'success' => true,
                'simulated' => false,
                'mode' => $rt['mode'],
                'container' => $name,
                'running' => false,
                'cpu_percent' => '0.00%',
                'mem_usage' => 'Idle (Arrêté)',
                'mem_percent' => '0.00%',
                'pids' => 0,
            ];
        }

        [$cpu, $memUsage, $memPct, $pids] = explode('|', trim($res['output'], "\"' \r\n"), 4);
        return [
            'success' => true,
            'simulated' => false,
            'mode' => $rt['mode'],
            'container' => $name,
            'running' => true,
            'cpu_percent' => trim($cpu),
            'mem_usage' => trim($memUsage),
            'mem_percent' => trim($memPct),
            'pids' => (int)trim($pids),
        ];
    }

    public function stopAllLabContainers(string $actorEmail = 'admin'): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [
                'success' => true,
                'simulated' => true,
                'stopped' => [],
                'count' => 0,
                'message' => 'Mode Cloud Simulé : aucun conteneur Docker actif à arrêter.',
            ];
        }

        $runningTargets = [];
        foreach ($this->listAllLabContainers() as $c) {
            if (!empty($c['running']) && ($c['type'] ?? '') === 'user_sandbox') {
                $runningTargets[] = $c['name'];
            }
        }

        if (empty($runningTargets)) {
            return [
                'success' => true,
                'simulated' => false,
                'stopped' => [],
                'count' => 0,
                'message' => 'Aucun conteneur utilisateur actif à arrêter.',
            ];
        }

        $safeNames = array_map(static fn(string $n): string => (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $n), $runningTargets);
        $this->runDockerCommand('stop ' . implode(' ', $safeNames), 30.0);
        $this->storage->logAuditEvent($actorEmail, 'DOCKER_STOP_ALL', 'Arrêt en masse de ' . count($safeNames) . ' conteneur(s) : ' . implode(', ', $safeNames));

        return [
            'success' => true,
            'simulated' => false,
            'stopped' => $safeNames,
            'count' => count($safeNames),
            'message' => count($safeNames) . ' conteneur(s) sandbox arrêté(s).',
        ];
    }

    public function pruneStoppedLabContainers(string $actorEmail = 'admin'): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [
                'success' => true,
                'simulated' => true,
                'removed' => [],
                'count' => 0,
                'message' => 'Mode Cloud Simulé : aucun conteneur arrêté à purger.',
            ];
        }

        $stoppedTargets = [];
        foreach ($this->listAllLabContainers() as $c) {
            if (empty($c['running']) && ($c['type'] ?? '') === 'user_sandbox') {
                $stoppedTargets[] = $c['name'];
            }
        }

        if (empty($stoppedTargets)) {
            return [
                'success' => true,
                'simulated' => false,
                'removed' => [],
                'count' => 0,
                'message' => 'Aucun conteneur utilisateur arrêté à purger.',
            ];
        }

        $safeNames = array_map(static fn(string $n): string => (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $n), $stoppedTargets);
        $this->runDockerCommand('rm ' . implode(' ', $safeNames), 15.0);
        $this->storage->logAuditEvent($actorEmail, 'DOCKER_PRUNE', 'Purge de ' . count($safeNames) . ' conteneur(s) arrêté(s) : ' . implode(', ', $safeNames));

        return [
            'success' => true,
            'simulated' => false,
            'removed' => $safeNames,
            'count' => count($safeNames),
            'message' => count($safeNames) . ' conteneur(s) arrêté(s) supprimé(s).',
        ];
    }

    public function listAllLabContainers(): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [];
        }

        $res = $this->runDockerCommand('ps -a --format "{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}"', 5.0);
        if ($res['code'] !== 0 || trim($res['output']) === '') {
            return [];
        }

        $containers = [];
        foreach (explode("\n", $res['output']) as $line) {
            if (!str_contains($line, '|')) {
                continue;
            }
            [$cName, $cImg, $cStatus, $cPorts] = explode('|', trim($line), 4);
            if (str_starts_with($cName, 'lp_pg18_user_') || str_starts_with($cName, 'pg_formation_')) {
                $containers[] = [
                    'name' => $cName,
                    'image' => $cImg,
                    'status' => $cStatus,
                    'running' => str_starts_with(strtolower($cStatus), 'up'),
                    'ports' => $cPorts,
                    'type' => str_starts_with($cName, 'lp_pg18_user_') ? 'user_sandbox' : 'shared_cluster',
                ];
            }
        }
        return $containers;
    }

    public function triggerChaosScenario(string $scenario, string $action, string $actorEmail): array
    {
        $scenarios = [
            'bloat' => [
                'inject' => "DROP TABLE IF EXISTS public.chaos_orders CASCADE; CREATE TABLE public.chaos_orders (id int PRIMARY KEY, payload text, updated_at timestamptz) WITH (autovacuum_enabled = off, fillfactor = 100); INSERT INTO public.chaos_orders SELECT g, repeat('X', 120), now() FROM generate_series(1, 15000) g; UPDATE public.chaos_orders SET payload = repeat('Y', 120), updated_at = now();",
                'heal' => "DROP TABLE IF EXISTS public.chaos_orders CASCADE;",
            ],
            'lock' => [
                'inject' => "DROP TABLE IF EXISTS public.chaos_ledger CASCADE; CREATE TABLE public.chaos_ledger (account_id int PRIMARY KEY, balance numeric(12,2)); INSERT INTO public.chaos_ledger VALUES (1, 1000.00), (2, 2500.00);",
                'heal' => "DROP TABLE IF EXISTS public.chaos_ledger CASCADE;",
            ],
            'idle_in_tx' => [
                'inject' => "DROP TABLE IF EXISTS public.chaos_sessions CASCADE; CREATE TABLE public.chaos_sessions (session_id int PRIMARY KEY, state text, created_at timestamptz DEFAULT now()); INSERT INTO public.chaos_sessions (session_id, state) SELECT g, 'active' FROM generate_series(1, 1000) g;",
                'heal' => "DROP TABLE IF EXISTS public.chaos_sessions CASCADE;",
            ],
            'wal_pressure' => [
                'inject' => "DROP TABLE IF EXISTS public.chaos_wal_burst CASCADE; CREATE TABLE public.chaos_wal_burst (id bigint, data text); INSERT INTO public.chaos_wal_burst SELECT g, md5(g::text) FROM generate_series(1, 25000) g; CHECKPOINT;",
                'heal' => "DROP TABLE IF EXISTS public.chaos_wal_burst CASCADE;",
            ],
        ];

        if (!isset($scenarios[$scenario][$action])) {
            return ['success' => false, 'message' => 'Scénario ou action Chaos invalide.'];
        }

        $sql = $scenarios[$scenario][$action];
        $b64 = base64_encode($sql);
        $containers = $this->listAllLabContainers();
        $targets = [];
        foreach ($containers as $c) {
            if ($c['running'] && (str_starts_with($c['name'], 'lp_pg18_user_') || $c['name'] === 'pg_formation_primary')) {
                $cmd = sprintf('exec %s sh -c "echo %s | base64 -d | psql -X -U postgres -d formation_pg"', $c['name'], $b64);
                $this->runDockerCommand($cmd, 15.0);
                $targets[] = $c['name'];
            }
        }

        $this->storage->logAuditEvent($actorEmail, 'CHAOS_DBA', "Scénario '{$scenario}' ({$action}) appliqué sur : " . (empty($targets) ? 'aucun conteneur actif' : implode(', ', $targets)));
        return [
            'success' => true,
            'message' => "Scénario Chaos '{$scenario}' ({$action}) exécuté avec succès (" . count($targets) . " instance(s) ciblée(s)).",
            'targets' => $targets,
        ];
    }

    /**
     * Creates a named PostgreSQL custom-format savepoint inside the user's sandbox
     * (or persists simulated savepoint metadata via JsonStorage when Docker is inactive).
     */
    public function createSavepoint(int $userId, string $name, string $dbName = 'formation_pg', bool $allowSharedFallback = false): array
    {
        $cleanName = $this->sanitizeSavepointName($name);
        if ($cleanName === null) {
            return [
                'success' => false,
                'message' => 'Nom de savepoint invalide (1 à 32 caractères alphanumériques, _ ou - uniquement).',
            ];
        }

        $cleanDb = $this->validateDatabaseName($dbName);
        if ($cleanDb === null) {
            return [
                'success' => false,
                'message' => 'Base de données cible non autorisée pour les savepoints.',
            ];
        }

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => (string)$target['runtime']['mode'],
                'database' => $cleanDb,
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];
        $mode = (string)$target['runtime']['mode'];

        if ($target['simulated']) {
            $mutation = $this->mutateSavepointsState($userId, static function (array $state) use ($cleanName, $cleanDb, $targetContainer): array {
                if (!isset($state[$cleanName]) && count($state) >= self::MAX_SAVEPOINTS_PER_USER) {
                    return [null, ['limit_reached' => true, 'items' => $state]];
                }

                $entry = [
                    'name' => $cleanName,
                    'database' => $cleanDb,
                    'container' => $targetContainer,
                    'path' => self::SAVEPOINT_DIR . '/' . $cleanName . '.dump',
                    'format' => 'custom',
                    'size_bytes' => $cleanDb === 'dvdrental' ? 245760 : ($cleanDb === 'formation_pg' ? 311296 : 131072),
                    'created_at' => date('Y-m-d H:i:s'),
                    'timestamp' => time(),
                    'simulated' => true,
                ];
                $state[$cleanName] = $entry;
                return [$state, ['limit_reached' => false, 'entry' => $entry, 'items' => $state]];
            });

            if (!empty($mutation['limit_reached'])) {
                return [
                    'success' => false,
                    'simulated' => true,
                    'mode' => $mode,
                    'container' => $targetContainer,
                    'database' => $cleanDb,
                    'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
                    'savepoints' => $this->sortSavepointEntries($mutation['items'] ?? []),
                    'message' => 'Limite maximale de 5 savepoints atteinte. Supprimez un savepoint existant.',
                ];
            }

            $this->touchContainerActivity($userId);
            $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_CREATE', "Simulated savepoint '{$cleanName}' created on {$cleanDb}");

            $sorted = $this->sortSavepointEntries($mutation['items'] ?? []);
            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'savepoint' => $mutation['entry'],
                'savepoints' => $sorted,
                'count' => count($sorted),
                'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
                'message' => "Savepoint simulé '{$cleanName}' créé sur {$cleanDb}.",
            ];
        }

        $dumpDir = $this->getSavepointDirectory($userId, $targetContainer);
        $dumpPath = $dumpDir . '/' . $cleanName . '.dump';

        $existing = $this->listLiveSavepointsInContainer($targetContainer, $dumpDir);
        if (!isset($existing[$cleanName]) && count($existing) >= self::MAX_SAVEPOINTS_PER_USER) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
                'savepoints' => $this->sortSavepointEntries($existing),
                'message' => 'Limite maximale de 5 savepoints atteinte. Supprimez un savepoint existant.',
            ];
        }

        $dumpCmd = sprintf(
            'exec %s sh -c "mkdir -p %s && pg_dump -Fc --clean --if-exists --no-owner --no-privileges -U postgres -d %s -f %s && stat -c \'%%s\' %s"',
            $targetContainer,
            $dumpDir,
            $cleanDb,
            $dumpPath,
            $dumpPath
        );
        $res = $this->runDockerCommand($dumpCmd, 35.0);
        if ($res['code'] !== 0) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'message' => "Échec de création du savepoint '{$cleanName}' : " . $res['output'],
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim((string)$res['output'])) ?: [];
        $lastLine = trim((string)end($lines));
        $sizeBytes = ctype_digit($lastLine) ? (int)$lastLine : 0;

        $entry = [
            'name' => $cleanName,
            'database' => $cleanDb,
            'container' => $targetContainer,
            'path' => $dumpPath,
            'format' => 'custom',
            'size_bytes' => $sizeBytes,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'simulated' => false,
        ];

        $savedItems = $this->mutateSavepointsState($userId, static function (array $state) use ($cleanName, $entry): array {
            $state[$cleanName] = $entry;
            return [$state, $state];
        });

        $this->touchContainerActivity($userId);
        $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_CREATE', "Savepoint '{$cleanName}' created on {$targetContainer}/{$cleanDb}");

        $sorted = $this->sortSavepointEntries(is_array($savedItems) ? $savedItems : [$entry]);
        return [
            'success' => true,
            'simulated' => false,
            'mode' => $mode,
            'container' => $targetContainer,
            'database' => $cleanDb,
            'savepoint' => $entry,
            'savepoints' => $sorted,
            'count' => count($sorted),
            'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
            'message' => "Savepoint '{$cleanName}' créé sur {$targetContainer} ({$cleanDb}).",
        ];
    }

    /**
     * Lists all sandbox savepoints for a user, honoring strict container tenant isolation.
     */
    public function listSavepoints(int $userId, bool $allowSharedFallback = false): array
    {
        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => (string)$target['runtime']['mode'],
                'savepoints' => [],
                'count' => 0,
                'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];
        $mode = (string)$target['runtime']['mode'];
        $stored = $this->readSavepointsState($userId);

        if ($target['simulated']) {
            $sorted = $this->sortSavepointEntries($stored);
            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'container' => $targetContainer,
                'savepoints' => $sorted,
                'count' => count($sorted),
                'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
            ];
        }

        $dumpDir = $this->getSavepointDirectory($userId, $targetContainer);
        $liveMap = $this->listLiveSavepointsInContainer($targetContainer, $dumpDir);
        $merged = [];
        foreach ($liveMap as $spName => $liveInfo) {
            $meta = is_array($stored[$spName] ?? null) ? $stored[$spName] : [];
            $merged[$spName] = array_merge($liveInfo, [
                'database' => $meta['database'] ?? 'formation_pg',
                'container' => $targetContainer,
                'created_at' => $meta['created_at'] ?? $liveInfo['created_at'],
                'simulated' => false,
            ]);
        }

        $sorted = $this->sortSavepointEntries($merged);
        return [
            'success' => true,
            'simulated' => false,
            'mode' => $mode,
            'container' => $targetContainer,
            'savepoints' => $sorted,
            'count' => count($sorted),
            'max_savepoints' => self::MAX_SAVEPOINTS_PER_USER,
        ];
    }

    /**
     * Restores a previously created savepoint into the allowed target database.
     */
    public function restoreSavepoint(int $userId, string $name, string $dbName = 'formation_pg', bool $allowSharedFallback = false): array
    {
        $cleanName = $this->sanitizeSavepointName($name);
        if ($cleanName === null) {
            return [
                'success' => false,
                'message' => 'Nom de savepoint invalide (1 à 32 caractères alphanumériques, _ ou - uniquement).',
            ];
        }

        $cleanDb = $this->validateDatabaseName($dbName);
        if ($cleanDb === null) {
            return [
                'success' => false,
                'message' => 'Base de données cible non autorisée pour la restauration.',
            ];
        }

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => (string)$target['runtime']['mode'],
                'database' => $cleanDb,
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];
        $mode = (string)$target['runtime']['mode'];
        $start = microtime(true);

        if ($target['simulated']) {
            $stored = $this->readSavepointsState($userId);
            if (!isset($stored[$cleanName]) || !is_array($stored[$cleanName])) {
                return [
                    'success' => false,
                    'simulated' => true,
                    'mode' => $mode,
                    'container' => $targetContainer,
                    'database' => $cleanDb,
                    'name' => $cleanName,
                    'message' => "Savepoint '{$cleanName}' introuvable.",
                ];
            }

            $this->touchContainerActivity($userId);
            $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_RESTORE', "Simulated savepoint '{$cleanName}' restored on {$cleanDb}");

            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'savepoint' => $stored[$cleanName],
                'duration_ms' => round((microtime(true) - $start) * 1000, 1),
                'message' => "Savepoint simulé '{$cleanName}' restauré dans {$cleanDb}.",
            ];
        }

        $dumpDir = $this->getSavepointDirectory($userId, $targetContainer);
        $dumpPath = $dumpDir . '/' . $cleanName . '.dump';

        $restoreCmd = sprintf(
            'exec %s sh -c "if [ ! -f %s ]; then echo \'__LMS_SAVEPOINT_MISSING__\'; exit 44; fi; pg_restore --clean --if-exists --no-owner --no-privileges -U postgres -d %s %s"',
            $targetContainer,
            $dumpPath,
            $cleanDb,
            $dumpPath
        );
        $res = $this->runDockerCommand($restoreCmd, 40.0);
        $durationMs = round((microtime(true) - $start) * 1000, 1);

        if ($res['code'] === 44 || str_contains((string)$res['output'], '__LMS_SAVEPOINT_MISSING__')) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'name' => $cleanName,
                'duration_ms' => $durationMs,
                'message' => "Savepoint '{$cleanName}' introuvable.",
            ];
        }

        if ($res['code'] !== 0) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'name' => $cleanName,
                'duration_ms' => $durationMs,
                'message' => "Échec de restauration du savepoint '{$cleanName}' : " . $res['output'],
            ];
        }

        $this->touchContainerActivity($userId);
        $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_RESTORE', "Savepoint '{$cleanName}' restored on {$targetContainer}/{$cleanDb}");

        $stored = $this->readSavepointsState($userId);
        return [
            'success' => true,
            'simulated' => false,
            'mode' => $mode,
            'container' => $targetContainer,
            'database' => $cleanDb,
            'savepoint' => $stored[$cleanName] ?? ['name' => $cleanName, 'database' => $cleanDb, 'path' => $dumpPath],
            'duration_ms' => $durationMs,
            'message' => "Savepoint '{$cleanName}' restauré avec succès dans {$cleanDb}.",
        ];
    }

    /**
     * Deletes a named sandbox savepoint from storage and container disk.
     */
    public function deleteSavepoint(int $userId, string $name, bool $allowSharedFallback = false): array
    {
        $cleanName = $this->sanitizeSavepointName($name);
        if ($cleanName === null) {
            return [
                'success' => false,
                'message' => 'Nom de savepoint invalide (1 à 32 caractères alphanumériques, _ ou - uniquement).',
            ];
        }

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => (string)$target['runtime']['mode'],
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];
        $mode = (string)$target['runtime']['mode'];

        if ($target['simulated']) {
            $mutation = $this->mutateSavepointsState($userId, static function (array $state) use ($cleanName): array {
                if (!isset($state[$cleanName])) {
                    return [null, ['found' => false, 'items' => $state]];
                }
                unset($state[$cleanName]);
                return [$state, ['found' => true, 'items' => $state]];
            });

            if (empty($mutation['found'])) {
                return [
                    'success' => false,
                    'simulated' => true,
                    'mode' => $mode,
                    'container' => $targetContainer,
                    'message' => "Savepoint '{$cleanName}' introuvable.",
                ];
            }

            $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_DELETE', "Simulated savepoint '{$cleanName}' deleted");
            $sorted = $this->sortSavepointEntries($mutation['items'] ?? []);

            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'container' => $targetContainer,
                'deleted' => $cleanName,
                'savepoints' => $sorted,
                'count' => count($sorted),
                'message' => "Savepoint '{$cleanName}' supprimé.",
            ];
        }

        $dumpDir = $this->getSavepointDirectory($userId, $targetContainer);
        $dumpPath = $dumpDir . '/' . $cleanName . '.dump';
        $rmCmd = sprintf(
            'exec %s sh -c "if [ ! -f %s ]; then echo \'__LMS_SAVEPOINT_MISSING__\'; exit 44; fi; rm -f %s"',
            $targetContainer,
            $dumpPath,
            $dumpPath
        );
        $res = $this->runDockerCommand($rmCmd, 10.0);
        if ($res['code'] === 44 || str_contains((string)$res['output'], '__LMS_SAVEPOINT_MISSING__')) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'message' => "Savepoint '{$cleanName}' introuvable.",
            ];
        }
        if ($res['code'] !== 0) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'message' => "Échec de suppression du savepoint '{$cleanName}' : " . $res['output'],
            ];
        }

        $remaining = $this->mutateSavepointsState($userId, static function (array $state) use ($cleanName): array {
            unset($state[$cleanName]);
            return [$state, $state];
        });

        $this->storage->logAuditEvent('user#' . max(1, $userId), 'DOCKER_SAVEPOINT_DELETE', "Savepoint '{$cleanName}' deleted on {$targetContainer}");
        $sorted = $this->sortSavepointEntries(is_array($remaining) ? $remaining : []);

        return [
            'success' => true,
            'simulated' => false,
            'mode' => $mode,
            'container' => $targetContainer,
            'deleted' => $cleanName,
            'savepoints' => $sorted,
            'count' => count($sorted),
            'message' => "Savepoint '{$cleanName}' supprimé.",
        ];
    }

    /**
     * Runs a bounded pgbench benchmark (1..8 clients, 1..5s) via non-blocking execCommandWithTimeout().
     */
    public function runPgbenchBenchmark(
        int $userId,
        int $clients = 4,
        int $durationSeconds = 2,
        bool $selectOnly = false,
        string $dbName = 'formation_pg',
        bool $allowSharedFallback = false
    ): array {
        $clampedClients = max(1, min(8, $clients));
        $clampedDuration = max(1, min(5, $durationSeconds));
        $cleanDb = $this->validateDatabaseName($dbName);

        if ($cleanDb === null) {
            return [
                'success' => false,
                'mode' => 'blocked',
                'clients' => $clampedClients,
                'duration_seconds' => $clampedDuration,
                'select_only' => $selectOnly,
                'database' => $dbName,
                'tps' => 0.0,
                'latency_avg_ms' => 0.0,
                'transactions' => 0,
                'output' => 'Invalid target database name.',
                'container' => 'blocked',
                'message' => 'Base de données cible non autorisée pour pgbench.',
            ];
        }

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        $mode = (string)$target['runtime']['mode'];

        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'clients' => $clampedClients,
                'duration_seconds' => $clampedDuration,
                'select_only' => $selectOnly,
                'database' => $cleanDb,
                'tps' => 0.0,
                'latency_avg_ms' => 0.0,
                'transactions' => 0,
                'output' => $target['error'],
                'container' => $this->getContainerName($userId),
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];

        if ($target['simulated']) {
            $tps = $selectOnly
                ? round(1280.0 + ($clampedClients * 145.5), 2)
                : round(420.0 + ($clampedClients * 48.2), 2);
            $latencyAvgMs = round(($clampedClients * 1000.0) / max(1.0, $tps), 3);
            $transactions = (int)round($tps * $clampedDuration);
            $txType = $selectOnly ? 'builtin: SELECT only' : 'builtin: TPC-B (sort of)';

            $output = implode("\n", [
                'pgbench (PostgreSQL 18.0 Simulated Sandbox)',
                'starting vacuum...end.',
                "transaction type: <{$txType}>",
                'scaling factor: 1',
                'query mode: simple',
                "number of clients: {$clampedClients}",
                'number of threads: ' . min($clampedClients, 2),
                "duration: {$clampedDuration} s",
                "number of transactions actually processed: {$transactions}",
                sprintf('latency average = %.3f ms', $latencyAvgMs),
                sprintf('tps = %.6f (without initial connection time)', $tps),
            ]);

            $this->touchContainerActivity($userId);

            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'clients' => $clampedClients,
                'duration_seconds' => $clampedDuration,
                'select_only' => $selectOnly,
                'database' => $cleanDb,
                'tps' => $tps,
                'latency_avg_ms' => $latencyAvgMs,
                'transactions' => $transactions,
                'output' => $output,
                'container' => $targetContainer,
                'message' => sprintf('Benchmark pgbench simulé terminé (%d clients, %ds) : %.2f TPS.', $clampedClients, $clampedDuration, $tps),
            ];
        }

        $threads = min($clampedClients, 2);
        $dockerArgs = sprintf(
            'exec %s pgbench -c %d -j %d -T %d%s -U postgres %s',
            $targetContainer,
            $clampedClients,
            $threads,
            $clampedDuration,
            $selectOnly ? ' -S' : '',
            $cleanDb
        );

        // Execute benchmark via runDockerCommand() and non-blocking execCommandWithTimeout().
        $res = $this->runDockerCommand($dockerArgs, (float)($clampedDuration + 10));
        $parsed = $this->parsePgbenchOutput((string)$res['output']);
        $ok = ($res['code'] === 0 && empty($res['timed_out']));

        if ($ok) {
            $this->touchContainerActivity($userId);
        }

        return [
            'success' => $ok,
            'simulated' => false,
            'mode' => $mode,
            'clients' => $clampedClients,
            'duration_seconds' => $clampedDuration,
            'select_only' => $selectOnly,
            'database' => $cleanDb,
            'tps' => $parsed['tps'],
            'latency_avg_ms' => $parsed['latency_avg_ms'],
            'transactions' => $parsed['transactions'],
            'output' => (string)$res['output'],
            'container' => $targetContainer,
            'message' => $ok
                ? sprintf('Benchmark pgbench terminé sur %s (%d clients, %ds) : %.2f TPS.', $targetContainer, $clampedClients, $clampedDuration, $parsed['tps'])
                : 'Échec ou expiration du benchmark pgbench.',
        ];
    }

    /**
     * Returns container fleet metrics including active runtime mode, container counts,
     * primary cluster state, and idle activity telemetry.
     */
    public function getFleetSummary(): array
    {
        $rt = $this->detectRuntime();
        $isLive = !empty($rt['available']);
        $activeRuntimeMode = $isLive ? 'docker_live' : 'cloud_simulated';
        $maxLimit = max(1, (int)($this->config['max_containers'] ?? 40));
        $cache = $this->readStatusCache();
        $cachedUsers = is_array($cache['users'] ?? null) ? $cache['users'] : [];
        $now = time();

        if (!$isLive) {
            return [
                'runtime_mode' => $activeRuntimeMode,
                'engine_mode' => (string)($rt['mode'] ?? 'cloud_simulated'),
                'docker_available' => false,
                'total_user_containers' => 0,
                'running_user_containers' => 0,
                'stopped_user_containers' => 0,
                'max_containers' => $maxLimit,
                'primary_cluster_status' => 'simulated',
                'primary_cluster_running' => false,
                'idle_count' => 0,
                'oldest_idle_seconds' => 0,
                'oldest_container' => null,
                'tracked_sessions' => count($cachedUsers),
                'containers' => [],
                'checked_at' => $now,
            ];
        }

        $allContainers = $this->listAllLabContainers();
        $userContainers = [];
        $runningCount = 0;
        $stoppedCount = 0;
        $primaryStatus = 'not_found';
        $idleCount = 0;
        $oldestIdleSeconds = 0;
        $oldestContainer = null;

        foreach ($allContainers as $c) {
            $cName = (string)($c['name'] ?? '');
            if ($cName === 'pg_formation_primary') {
                $primaryStatus = !empty($c['running']) ? 'running' : 'stopped';
                continue;
            }
            if (($c['type'] ?? '') !== 'user_sandbox') {
                continue;
            }

            $uid = 0;
            if (preg_match('/lp_pg18_user_(\d+)/', $cName, $m)) {
                $uid = (int)$m[1];
            }
            $userCache = $uid > 0 && is_array($cachedUsers[(string)$uid] ?? null)
                ? $cachedUsers[(string)$uid]
                : [];
            $lastActivity = isset($userCache['last_activity_at'])
                ? (int)$userCache['last_activity_at']
                : (isset($userCache['checked_at']) ? (int)$userCache['checked_at'] : null);

            $idleSeconds = $lastActivity !== null ? max(0, $now - $lastActivity) : 0;
            $c['user_id'] = $uid;
            $c['last_activity_at'] = $lastActivity;
            $c['idle_seconds'] = $idleSeconds;
            $userContainers[] = $c;

            if (!empty($c['running'])) {
                $runningCount++;
                if ($lastActivity !== null && $idleSeconds >= 3600) {
                    $idleCount++;
                }
                if ($idleSeconds > $oldestIdleSeconds) {
                    $oldestIdleSeconds = $idleSeconds;
                    $oldestContainer = $cName;
                }
            } else {
                $stoppedCount++;
            }
        }

        return [
            'runtime_mode' => $activeRuntimeMode,
            'engine_mode' => (string)($rt['mode'] ?? 'native'),
            'docker_available' => true,
            'total_user_containers' => count($userContainers),
            'running_user_containers' => $runningCount,
            'stopped_user_containers' => $stoppedCount,
            'max_containers' => $maxLimit,
            'primary_cluster_status' => $primaryStatus,
            'primary_cluster_running' => $primaryStatus === 'running',
            'idle_count' => $idleCount,
            'oldest_idle_seconds' => $oldestIdleSeconds,
            'oldest_container' => $oldestContainer,
            'tracked_sessions' => count($cachedUsers),
            'containers' => $userContainers,
            'checked_at' => $now,
        ];
    }

    /**
     * Stops idle per-user sandbox containers whose inactivity exceeds $maxIdleSeconds.
     */
    public function reapIdleContainers(int $maxIdleSeconds = 3600): array
    {
        $threshold = max(1, $maxIdleSeconds);
        $rt = $this->detectRuntime();
        $cache = $this->readStatusCache();
        $cachedUsers = is_array($cache['users'] ?? null) ? $cache['users'] : [];
        $now = time();

        if (!$rt['available']) {
            $reaped = [];
            $skipped = [];
            $cacheDirty = false;

            foreach ($cachedUsers as $uidKey => $entry) {
                if (!is_array($entry) || !empty($entry['reaped'])) {
                    continue;
                }
                $lastActivity = isset($entry['last_activity_at']) ? (int)$entry['last_activity_at'] : null;
                $cName = (string)($entry['container_name'] ?? $this->getContainerName((int)$uidKey));
                if ($lastActivity !== null && ($now - $lastActivity) >= $threshold) {
                    $reaped[] = $cName;
                    $cache['users'][(string)$uidKey]['running'] = false;
                    $cache['users'][(string)$uidKey]['user_container_running'] = false;
                    $cache['users'][(string)$uidKey]['status'] = 'reaped';
                    $cache['users'][(string)$uidKey]['reaped'] = true;
                    $cacheDirty = true;
                } else {
                    $skipped[] = $cName;
                }
            }

            if ($cacheDirty) {
                $this->writeStatusCache($cache);
            }

            if (!empty($reaped)) {
                $this->storage->logAuditEvent('system-reaper', 'DOCKER_REAP_IDLE', sprintf('Simulated idle reap (%ds): %s', $threshold, implode(', ', $reaped)));
            }

            return [
                'success' => true,
                'simulated' => true,
                'mode' => (string)$rt['mode'],
                'max_idle_seconds' => $threshold,
                'checked_count' => count($cachedUsers),
                'reaped' => $reaped,
                'skipped' => $skipped,
                'count' => count($reaped),
                'message' => !empty($reaped)
                    ? sprintf('Mode Cloud Simulé : %d conteneur(s) inactif(s) arrêté(s) (> %ds).', count($reaped), $threshold)
                    : sprintf('Mode Cloud Simulé : aucun conteneur inactif à arrêter (seuil %ds).', $threshold),
            ];
        }

        $reapTargets = [];
        $skipped = [];
        $checkedCount = 0;

        foreach ($this->listAllLabContainers() as $c) {
            if (empty($c['running']) || ($c['type'] ?? '') !== 'user_sandbox') {
                continue;
            }
            $checkedCount++;
            $cName = (string)$c['name'];
            $uid = 0;
            if (preg_match('/lp_pg18_user_(\d+)/', $cName, $m)) {
                $uid = (int)$m[1];
            }

            $userCache = ($uid > 0 && is_array($cachedUsers[(string)$uid] ?? null))
                ? $cachedUsers[(string)$uid]
                : [];
            $lastActivity = isset($userCache['last_activity_at']) ? (int)$userCache['last_activity_at'] : null;

            if ($lastActivity === null) {
                $insp = $this->runDockerCommand('inspect -f "{{.State.StartedAt}}" ' . $cName, 4.0);
                if ($insp['code'] === 0 && trim($insp['output']) !== '') {
                    $parsedTs = strtotime(trim($insp['output'], "\"' \r\n"));
                    if ($parsedTs !== false) {
                        $lastActivity = $parsedTs;
                    }
                }
            }

            if ($lastActivity !== null && ($now - $lastActivity) >= $threshold) {
                $reapTargets[$uid] = $cName;
            } else {
                $skipped[] = $cName;
            }
        }

        if (empty($reapTargets)) {
            return [
                'success' => true,
                'simulated' => false,
                'mode' => (string)$rt['mode'],
                'max_idle_seconds' => $threshold,
                'checked_count' => $checkedCount,
                'reaped' => [],
                'skipped' => $skipped,
                'count' => 0,
                'message' => sprintf('Aucun conteneur inactif dépassant %d seconde(s).', $threshold),
            ];
        }

        $safeNames = array_map(
            static fn(string $n): string => (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $n),
            array_values($reapTargets)
        );
        $stopRes = $this->runDockerCommand('stop ' . implode(' ', $safeNames), 30.0);

        if ($stopRes['code'] === 0) {
            foreach ($reapTargets as $uid => $cName) {
                if ($uid > 0 && isset($cache['users'][(string)$uid]) && is_array($cache['users'][(string)$uid])) {
                    $cache['users'][(string)$uid]['running'] = false;
                    $cache['users'][(string)$uid]['user_container_running'] = false;
                    $cache['users'][(string)$uid]['status'] = 'exited';
                    $cache['users'][(string)$uid]['reaped'] = true;
                }
            }
            $this->writeStatusCache($cache);

            $this->storage->logAuditEvent(
                'system-reaper',
                'DOCKER_REAP_IDLE',
                sprintf('Stopped %d idle container(s) (> %ds): %s', count($safeNames), $threshold, implode(', ', $safeNames))
            );
        }

        return [
            'success' => $stopRes['code'] === 0,
            'simulated' => false,
            'mode' => (string)$rt['mode'],
            'max_idle_seconds' => $threshold,
            'checked_count' => $checkedCount,
            'reaped' => $safeNames,
            'skipped' => $skipped,
            'count' => count($safeNames),
            'message' => count($safeNames) . ' conteneur(s) inactif(s) arrêté(s) avec succès.',
        ];
    }

    /**
     * Cleans active chaos locks, idle-in-transaction blockers, and chaos demo tables
     * across running lab containers safely.
     */
    public function healChaosScenario(string $courseSlug = 'postgresql-18'): array
    {
        $cleanCourse = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', trim($courseSlug));
        if ($cleanCourse === '') {
            $cleanCourse = 'postgresql-18';
        }

        $chaosTables = ['chaos_orders', 'chaos_ledger', 'chaos_sessions', 'chaos_wal_burst'];
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            $this->storage->logAuditEvent('admin-chaos-heal', 'CHAOS_HEAL', "Simulated chaos heal completed for {$cleanCourse}");
            return [
                'success' => true,
                'simulated' => true,
                'mode' => (string)$rt['mode'],
                'course_slug' => $cleanCourse,
                'healed_containers' => [],
                'cleaned_tables' => $chaosTables,
                'terminated_pids' => 0,
                'message' => "Mode Cloud Simulé : verrous et tables Chaos nettoyés pour '{$cleanCourse}'.",
            ];
        }

        $healSql = <<<'SQL'
SELECT 'HEAL_PIDS|' || count(*)::text
FROM (
    SELECT pg_terminate_backend(pid)
    FROM pg_stat_activity
    WHERE pid <> pg_backend_pid()
      AND backend_type = 'client backend'
      AND (
          state IN ('idle in transaction', 'idle in transaction (aborted)')
          OR wait_event_type = 'Lock'
          OR query ILIKE '%chaos_%'
      )
) AS terminated;
DROP TABLE IF EXISTS public.chaos_orders, public.chaos_ledger, public.chaos_sessions, public.chaos_wal_burst CASCADE;
ANALYZE;
SQL;

        $b64 = base64_encode($healSql);
        $healedContainers = [];
        $totalTerminated = 0;

        foreach ($this->listAllLabContainers() as $c) {
            $cName = (string)($c['name'] ?? '');
            if (empty($c['running']) || (!str_starts_with($cName, 'lp_pg18_user_') && $cName !== 'pg_formation_primary')) {
                continue;
            }
            $cmd = sprintf('exec %s sh -c "echo %s | base64 -d | psql -X -t -A -U postgres -d formation_pg"', $cName, $b64);
            $res = $this->runDockerCommand($cmd, 15.0);
            if ($res['code'] === 0) {
                $healedContainers[] = $cName;
                if (preg_match('/HEAL_PIDS\|(\d+)/', (string)$res['output'], $m)) {
                    $totalTerminated += (int)$m[1];
                }
            }
        }

        $this->storage->logAuditEvent(
            'admin-chaos-heal',
            'CHAOS_HEAL',
            sprintf('Healed chaos locks/tables for %s on %d container(s), terminated %d pid(s)', $cleanCourse, count($healedContainers), $totalTerminated)
        );

        return [
            'success' => true,
            'simulated' => false,
            'mode' => (string)$rt['mode'],
            'course_slug' => $cleanCourse,
            'healed_containers' => $healedContainers,
            'cleaned_tables' => $chaosTables,
            'terminated_pids' => $totalTerminated,
            'message' => sprintf('Assainissement Chaos terminé sur %d instance(s) (%d session(s) bloquante(s) terminée(s)).', count($healedContainers), $totalTerminated),
        ];
    }

    private function resolveStorageDataDir(): string
    {
        if ($this->storage instanceof JsonStorage) {
            $rp = new \ReflectionProperty(JsonStorage::class, 'dataDir');
            $val = $rp->getValue($this->storage);
            if (is_string($val) && $val !== '') {
                return rtrim($val, '/\\');
            }
        }
        $envDir = getenv('LMS_DATA_DIR');
        if (is_string($envDir) && trim($envDir) !== '') {
            return rtrim(trim($envDir), '/\\');
        }
        return dirname(__DIR__, 2) . '/storage/data';
    }

    /**
     * Resolves the target container while strictly enforcing per-user tenant isolation
     * unless $allowSharedFallback is explicitly granted for staff/CLI contexts.
     *
     * @return array{runtime: array, simulated: bool, container: ?string, error: ?string}
     */
    private function resolveTargetContainer(int $userId, bool $allowSharedFallback = false): array
    {
        $rt = $this->detectRuntime();
        if (empty($rt['available'])) {
            return [
                'runtime' => $rt,
                'simulated' => true,
                'container' => $this->getContainerName($userId),
                'error' => null,
            ];
        }

        $status = $this->getUserContainerStatus($userId);
        if (!empty($status['user_container_running']) || !empty($status['running'])) {
            return [
                'runtime' => $rt,
                'simulated' => false,
                'container' => (string)$status['container_name'],
                'error' => null,
            ];
        }

        if ($allowSharedFallback && !empty($status['primary_cluster_running'])) {
            return [
                'runtime' => $rt,
                'simulated' => false,
                'container' => 'pg_formation_primary',
                'error' => null,
            ];
        }

        return [
            'runtime' => $rt,
            'simulated' => false,
            'container' => null,
            'error' => 'Veuillez démarrer votre conteneur personnel avant cette opération.',
        ];
    }

    private function sanitizeSavepointName(string $name): ?string
    {
        if (preg_match('/[\r\n\0]/', $name)) {
            return null;
        }
        $trimmed = trim($name);
        if (preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $trimmed) !== 1) {
            return null;
        }
        return $trimmed;
    }

    private function validateDatabaseName(string $dbName): ?string
    {
        $clean = strtolower(trim($dbName));
        return in_array($clean, self::ALLOWED_DATABASES, true) ? $clean : null;
    }

    private function getSavepointDirectory(int $userId, string $targetContainer): string
    {
        if ($targetContainer === 'pg_formation_primary') {
            return self::SAVEPOINT_DIR . '/user_' . max(1, $userId);
        }
        return self::SAVEPOINT_DIR;
    }

    private function getSavepointsStorageKey(int $userId): string
    {
        return 'savepoints_state_' . max(1, $userId);
    }

    private function readSavepointsState(int $userId): array
    {
        $key = $this->getSavepointsStorageKey($userId);
        if ($this->storage instanceof JsonStorage) {
            $rm = new \ReflectionMethod($this->storage, 'readFile');
            $data = $rm->invoke($this->storage, $key, []);
            return is_array($data) ? $data : [];
        }

        $path = dirname($this->cacheFile) . '/' . $key . '.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function mutateSavepointsState(int $userId, callable $mutator): mixed
    {
        $key = $this->getSavepointsStorageKey($userId);
        if ($this->storage instanceof JsonStorage) {
            $rm = new \ReflectionMethod($this->storage, 'mutateFile');
            return $rm->invoke($this->storage, $key, [], $mutator);
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $key . '.json';
        $lockFile = $dir . '/' . $key . '.lock';
        $lockFp = @fopen($lockFile, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $current = $this->readSavepointsState($userId);
            [$updated, $result] = $mutator($current);
            if ($updated !== null) {
                $tmpPath = $path . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
                $json = (string)json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                @file_put_contents($tmpPath, $json, LOCK_EX);
                if (!@rename($tmpPath, $path)) {
                    @copy($tmpPath, $path);
                    @unlink($tmpPath);
                }
            }
            return $result;
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }

    private function listLiveSavepointsInContainer(string $containerName, string $dumpDir): array
    {
        $cmd = sprintf('exec %s sh -c "if [ -d %s ]; then stat -c \'%%n|%%s|%%Y\' %s/*.dump 2>/dev/null || true; fi"', $containerName, $dumpDir, $dumpDir);
        $res = $this->runDockerCommand($cmd, 8.0);
        if ($res['code'] !== 0 || trim((string)$res['output']) === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', trim((string)$res['output'])) ?: [] as $line) {
            if (!str_contains($line, '|')) {
                continue;
            }
            [$fullPath, $sizeRaw, $mtimeRaw] = explode('|', trim($line), 3);
            $base = basename($fullPath, '.dump');
            $cleanName = $this->sanitizeSavepointName($base);
            if ($cleanName === null) {
                continue;
            }
            $ts = ctype_digit(trim($mtimeRaw)) ? (int)trim($mtimeRaw) : time();
            $items[$cleanName] = [
                'name' => $cleanName,
                'path' => trim($fullPath),
                'format' => 'custom',
                'size_bytes' => ctype_digit(trim($sizeRaw)) ? (int)trim($sizeRaw) : 0,
                'created_at' => date('Y-m-d H:i:s', $ts),
                'timestamp' => $ts,
            ];
        }
        return $items;
    }

    private function sortSavepointEntries(array $items): array
    {
        $list = array_values(array_filter($items, static fn(mixed $item): bool => is_array($item)));
        usort($list, static fn(array $a, array $b): int => ((int)($b['timestamp'] ?? 0)) <=> ((int)($a['timestamp'] ?? 0)));
        return $list;
    }

    /**
     * Extracts TPS, average latency, and processed transactions from pgbench stdout.
     *
     * @return array{tps: float, latency_avg_ms: float, transactions: int}
     */
    private function parsePgbenchOutput(string $output): array
    {
        $tps = 0.0;
        $latencyAvgMs = 0.0;
        $transactions = 0;

        if (preg_match('/\btps\s*=\s*([0-9]+(?:\.[0-9]+)?)/i', $output, $m)) {
            $tps = round((float)$m[1], 2);
        }
        if (preg_match('/\blatency\s+average\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*ms/i', $output, $m)) {
            $latencyAvgMs = round((float)$m[1], 3);
        }
        if (preg_match('/number\s+of\s+transactions\s+actually\s+processed:\s*(\d+)/i', $output, $m)) {
            $transactions = (int)$m[1];
        }

        return [
            'tps' => $tps,
            'latency_avg_ms' => $latencyAvgMs,
            'transactions' => $transactions,
        ];
    }

    private function bootstrapContainerSchema(string $containerName, bool $forceReset = false, string $dataset = 'all'): bool
    {
        if (!preg_match('/^(lp_pg18_user_\d+|pg_formation_[a-z0-9_]+)$/', $containerName)) {
            return false;
        }

        $initSql = SakilaSeeder::getBootstrapSql($forceReset, $dataset);
        $b64 = base64_encode($initSql);

        $resPrimary = $this->runDockerCommand(sprintf(
            'exec %s sh -c "echo %s | base64 -d | psql -X -U postgres -d formation_pg"',
            $containerName,
            $b64
        ), 30.0);

        // Ensure the dvdrental (Sakila) database exists and is seeded identically.
        $this->runDockerCommand(sprintf(
            'exec %s sh -c "psql -X -U postgres -tc \"SELECT 1 FROM pg_database WHERE datname = \'dvdrental\'\" | grep -q 1 || createdb -U postgres dvdrental"',
            $containerName
        ), 10.0);
        $resSakila = $this->runDockerCommand(sprintf(
            'exec %s sh -c "echo %s | base64 -d | psql -X -U postgres -d dvdrental"',
            $containerName,
            $b64
        ), 30.0);

        return $resPrimary['code'] === 0 && $resSakila['code'] === 0;
    }
}
