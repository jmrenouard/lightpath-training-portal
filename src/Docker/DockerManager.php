<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

use LightPath\LMS\Core\StorageInterface;

/**
 * Manages per-user isolated PostgreSQL 18 Docker lab containers, non-blocking
 * runtime checks, resource limits, and Sakila/pgbench dataset seeding.
 */
class DockerManager
{
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
        $this->cacheFile = $cacheFile ?? (dirname(__DIR__, 2) . '/storage/data/docker_status_cache.json');
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

        $cmd = $rt['prefix'] . ' ' . $args . ' 2>&1';
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
            return $cache['users'][(string)$userId];
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
            $this->saveUserStatusCache($userId, $status);
            return $status;
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
            $this->saveUserStatusCache($userId, $status);
            return $status;
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
        $this->saveUserStatusCache($userId, $status);
        return $status;
    }

    private function saveUserStatusCache(int $userId, array $status): void
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
