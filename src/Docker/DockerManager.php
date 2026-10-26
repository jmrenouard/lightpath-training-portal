<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

use LightPath\LMS\Core\StorageInterface;

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
            'default_password' => 'postgres_master_pw',
            'wsl_distro' => 'Ubuntu-24.04',
        ], $config);
        $this->cacheFile = $cacheFile ?? (dirname(__DIR__, 2) . '/storage/data/docker_status_cache.json');
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
        @file_put_contents($this->cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function detectRuntime(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && self::$runtimeCache !== null) {
            return self::$runtimeCache;
        }

        if (!$forceRefresh) {
            $cache = $this->readStatusCache();
            if (isset($cache['runtime']) && is_array($cache['runtime']) && (($cache['runtime']['cached_at'] ?? 0) > time() - 3600)) {
                self::$runtimeCache = $cache['runtime'];
                return self::$runtimeCache;
            }
        }

        // 1. Sur Windows avec WSL2 privilégier d'abord wsl docker si présent, ou docker natif
        if (PHP_OS_FAMILY === 'Windows') {
            $wslOut = [];
            $wslCode = 1;
            @exec('wsl docker --version 2>&1', $wslOut, $wslCode);
            if ($wslCode === 0 && !empty($wslOut)) {
                self::$runtimeCache = [
                    'available' => true,
                    'mode' => 'wsl2',
                    'prefix' => 'wsl docker',
                    'version' => trim(implode(' ', $wslOut)),
                    'cached_at' => time(),
                ];
                $cache = $this->readStatusCache();
                $cache['runtime'] = self::$runtimeCache;
                $this->writeStatusCache($cache);
                return self::$runtimeCache;
            }
        }

        // 2. Tester binaire docker natif
        $nativeOut = [];
        $nativeCode = 1;
        @exec('docker info --format "{{.ServerVersion}}" 2>&1', $nativeOut, $nativeCode);
        if ($nativeCode === 0 && !empty($nativeOut)) {
            self::$runtimeCache = [
                'available' => true,
                'mode' => 'native',
                'prefix' => 'docker',
                'version' => trim(implode(' ', $nativeOut)),
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

    public function runDockerCommand(string $args): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return ['code' => 1, 'output' => 'Docker daemon non disponible sur cet hôte.'];
        }

        $cmd = $rt['prefix'] . ' ' . $args . ' 2>&1';
        $lines = [];
        $code = 0;
        @exec($cmd, $lines, $code);
        return [
            'code' => $code,
            'output' => trim(implode("\n", $lines)),
        ];
    }

    /**
     * Lecture instantanée (< 0.2 ms) sans appel shell bloquant pour le rendu des pages HTML.
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
            'status' => 'cluster_ready',
            'running' => false,
            'user_container_running' => false,
            'primary_cluster_running' => true,
            'runtime_mode' => $rtMode,
            'runtime_version' => $rtVersion,
            'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
            'from_cache' => true,
        ];
    }

    public function getUserContainerStatus(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            $status = [
                'container_name' => $name,
                'port' => $port,
                'status' => 'simulated',
                'running' => false,
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
                'checked_at' => time(),
            ];
            $this->saveUserStatusCache($userId, $status);
            return $status;
        }

        $res = $this->runDockerCommand('inspect -f "{{.State.Status}}|{{.State.StartedAt}}" ' . $name);
        if ($res['code'] === 0 && str_contains($res['output'], '|')) {
            [$state, $startedAt] = explode('|', trim($res['output'], "\"' \r\n"), 2);
            $running = trim($state) === 'running';
            $status = [
                'container_name' => $name,
                'port' => $port,
                'status' => trim($state),
                'running' => $running,
                'primary_cluster_running' => true,
                'started_at' => substr(trim($startedAt), 0, 19),
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
                'checked_at' => time(),
            ];
            $this->saveUserStatusCache($userId, $status);
            return $status;
        }

        $primaryRes = $this->runDockerCommand('inspect -f "{{.State.Status}}" pg_formation_primary');
        $primaryRunning = ($primaryRes['code'] === 0 && trim($primaryRes['output'], "\"' \r\n") === 'running');

        $status = [
            'container_name' => $name,
            'port' => $port,
            'status' => $primaryRunning ? 'cluster_ready' : 'not_created',
            'running' => $primaryRunning,
            'primary_cluster_running' => $primaryRunning,
            'runtime_mode' => $rt['mode'],
            'runtime_version' => $rt['version'],
            'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
            'checked_at' => time(),
        ];
        $this->saveUserStatusCache($userId, $status);
        return $status;
    }

    private function saveUserStatusCache(int $userId, array $status): void
    {
        $cache = $this->readStatusCache();
        $cache['users'][(string)$userId] = $status;
        $this->writeStatusCache($cache);
    }

    public function startUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            return [
                'success' => false,
                'message' => 'Docker n\'est pas actif sur cet hôte.',
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        if ($rt['mode'] === 'wsl2') {
            $ping = $this->runDockerCommand('ps -q');
            if ($ping['code'] !== 0) {
                @exec('wsl -u root service docker start 2>&1');
            }
        }

        $inspect = $this->runDockerCommand('inspect -f "{{.State.Status}}" ' . $name);
        if ($inspect['code'] === 0) {
            if (trim($inspect['output'], "\"' \r\n") !== 'running') {
                $this->runDockerCommand('start ' . $name);
            }
            $this->bootstrapContainerSchema($name);
            $this->storage->logAuditEvent($userEmail, 'DOCKER_START', "Démarrage du conteneur existant {$name} (port {$port})");
            return [
                'success' => true,
                'message' => "Conteneur personnel {$name} démarré sur le port {$port}.",
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        $safeImage = (string)preg_replace('/[^a-zA-Z0-9_.\-:\/]/', '', (string)$this->config['image']);
        $safeFallback = (string)preg_replace('/[^a-zA-Z0-9_.\-:\/]/', '', (string)$this->config['fallback_image']);
        $safePw = (string)preg_replace('/[^a-zA-Z0-9_.\-@!]/', '', (string)$this->config['default_password']);

        $imgCheck = $this->runDockerCommand('image inspect ' . $safeImage);
        $imageToUse = ($imgCheck['code'] === 0) ? $safeImage : $safeFallback;

        $runArgs = sprintf(
            'run -d --name %s --hostname %s -p %d:5432 --shm-size=512m -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=%s -e POSTGRES_DB=formation_pg %s',
            $name,
            $name,
            $port,
            $safePw,
            $imageToUse
        );

        $runRes = $this->runDockerCommand($runArgs);
        if ($runRes['code'] !== 0) {
            return [
                'success' => false,
                'message' => "Erreur lors de la création du conteneur {$name} : " . $runRes['output'],
                'status' => $this->getUserContainerStatus($userId),
            ];
        }

        for ($i = 0; $i < 8; $i++) {
            usleep(800000);
            $ready = $this->runDockerCommand('exec ' . $name . ' pg_isready -U postgres -d formation_pg');
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
        $res = $this->runDockerCommand('stop ' . $name);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_STOP', "Arrêt du conteneur {$name}");
        return [
            'success' => $res['code'] === 0,
            'message' => $res['code'] === 0 ? "Conteneur {$name} arrêté." : $res['output'],
            'status' => $this->getUserContainerStatus($userId),
        ];
    }

    public function resetUserContainer(int $userId, string $userEmail = 'student'): array
    {
        $name = $this->getContainerName($userId);
        $this->runDockerCommand('rm -f ' . $name);
        $this->storage->logAuditEvent($userEmail, 'DOCKER_RESET', "Réinitialisation complète du conteneur {$name}");
        return $this->startUserContainer($userId, $userEmail);
    }

    public function listAllLabContainers(): array
    {
        $rt = $this->detectRuntime();
        if (!$rt['available']) {
            return [];
        }

        $res = $this->runDockerCommand('ps -a --format "{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}"');
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
                $cmd = sprintf('exec %s sh -c "echo %s | base64 -d | psql -U postgres -d formation_pg"', $c['name'], $b64);
                $this->runDockerCommand($cmd);
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

    private function bootstrapContainerSchema(string $containerName): void
    {
        $initSql = <<<'SQL'
CREATE EXTENSION IF NOT EXISTS pageinspect;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS pgstattuple;

CREATE TABLE IF NOT EXISTS public.actor (
    actor_id serial PRIMARY KEY,
    first_name varchar(45) NOT NULL,
    last_name varchar(45) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.film (
    film_id serial PRIMARY KEY,
    title varchar(255) NOT NULL,
    description text,
    release_year int,
    rental_duration smallint NOT NULL DEFAULT 3,
    rental_rate numeric(4,2) NOT NULL DEFAULT 4.99,
    length smallint,
    replacement_cost numeric(5,2) NOT NULL DEFAULT 19.99,
    rating varchar(10) DEFAULT 'G',
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.category (
    category_id serial PRIMARY KEY,
    name varchar(25) NOT NULL,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.customer (
    customer_id serial PRIMARY KEY,
    store_id smallint NOT NULL DEFAULT 1,
    first_name varchar(45) NOT NULL,
    last_name varchar(45) NOT NULL,
    email varchar(50),
    address_id smallint NOT NULL DEFAULT 1,
    activebool boolean NOT NULL DEFAULT true,
    create_date date NOT NULL DEFAULT CURRENT_DATE,
    last_update timestamptz DEFAULT now(),
    active int DEFAULT 1
);

CREATE TABLE IF NOT EXISTS public.inventory (
    inventory_id serial PRIMARY KEY,
    film_id int NOT NULL,
    store_id smallint NOT NULL DEFAULT 1,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.rental (
    rental_id serial PRIMARY KEY,
    rental_date timestamptz NOT NULL DEFAULT now(),
    inventory_id int NOT NULL,
    customer_id smallint NOT NULL,
    return_date timestamptz,
    staff_id smallint NOT NULL DEFAULT 1,
    last_update timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.payment (
    payment_id serial PRIMARY KEY,
    customer_id smallint NOT NULL,
    staff_id smallint NOT NULL DEFAULT 1,
    rental_id int NOT NULL,
    amount numeric(5,2) NOT NULL,
    payment_date timestamptz NOT NULL DEFAULT now()
);

INSERT INTO public.actor (first_name, last_name)
SELECT 'PENELOPE', 'GUINESS' WHERE NOT EXISTS (SELECT 1 FROM public.actor LIMIT 1);

INSERT INTO public.film (title, description, release_year, rental_rate, length, rating)
SELECT 'ACADEMY DINOSAUR', 'A Epic Drama of a Feminist And a Mad Scientist', 2006, 0.99, 86, 'PG'
WHERE NOT EXISTS (SELECT 1 FROM public.film LIMIT 1);
SQL;
        $b64 = base64_encode($initSql);
        $this->runDockerCommand(sprintf(
            'exec %s sh -c "echo %s | base64 -d | psql -U postgres -d formation_pg"',
            $containerName,
            $b64
        ));
        // S'assurer que la base dvdrental (Sakila) existe aussi et possède le socle de tables
        $this->runDockerCommand(sprintf(
            'exec %s sh -c "psql -U postgres -tc \"SELECT 1 FROM pg_database WHERE datname = \'dvdrental\'\" | grep -q 1 || createdb -U postgres dvdrental"',
            $containerName
        ));
        $this->runDockerCommand(sprintf(
            'exec %s sh -c "echo %s | base64 -d | psql -U postgres -d dvdrental"',
            $containerName,
            $b64
        ));
    }
}
