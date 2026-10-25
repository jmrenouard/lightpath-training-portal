<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

use LightPath\LMS\Core\StorageInterface;

class DockerManager
{
    private StorageInterface $storage;
    private array $config;
    private static ?array $runtimeCache = null;

    public function __construct(StorageInterface $storage, array $config = [])
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
    }

    public function getUserPort(int $userId): int
    {
        return (int)$this->config['base_port'] + max(1, $userId);
    }

    public function getContainerName(int $userId): string
    {
        return 'lp_pg18_user_' . max(1, $userId);
    }

    public function detectRuntime(): array
    {
        if (self::$runtimeCache !== null) {
            return self::$runtimeCache;
        }

        // 1. Tester binaire docker natif
        $nativeOut = [];
        $nativeCode = 1;
        @exec('docker info --format "{{.ServerVersion}}" 2>&1', $nativeOut, $nativeCode);
        if ($nativeCode === 0 && !empty($nativeOut)) {
            self::$runtimeCache = [
                'available' => true,
                'mode' => 'native',
                'prefix' => 'docker',
                'version' => trim(implode(' ', $nativeOut)),
            ];
            return self::$runtimeCache;
        }

        // 2. Tester via WSL2
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
                ];
                return self::$runtimeCache;
            }
        }

        self::$runtimeCache = [
            'available' => false,
            'mode' => 'cloud_simulated',
            'prefix' => '',
            'version' => 'Mode Cloud / Sandbox Simulée',
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

    public function getUserContainerStatus(int $userId): array
    {
        $name = $this->getContainerName($userId);
        $port = $this->getUserPort($userId);
        $rt = $this->detectRuntime();

        if (!$rt['available']) {
            return [
                'container_name' => $name,
                'port' => $port,
                'status' => 'simulated',
                'running' => false,
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
            ];
        }

        $res = $this->runDockerCommand('inspect -f "{{.State.Status}}|{{.State.StartedAt}}" ' . $name);
        if ($res['code'] === 0 && str_contains($res['output'], '|')) {
            [$state, $startedAt] = explode('|', trim($res['output'], "\"' \r\n"), 2);
            $running = trim($state) === 'running';
            return [
                'container_name' => $name,
                'port' => $port,
                'status' => trim($state),
                'running' => $running,
                'started_at' => substr(trim($startedAt), 0, 19),
                'runtime_mode' => $rt['mode'],
                'runtime_version' => $rt['version'],
                'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
            ];
        }

        // Vérifier aussi si le conteneur principal pg_formation_primary tourne
        $primaryRes = $this->runDockerCommand('inspect -f "{{.State.Status}}" pg_formation_primary');
        $primaryRunning = ($primaryRes['code'] === 0 && trim($primaryRes['output'], "\"' \r\n") === 'running');

        return [
            'container_name' => $name,
            'port' => $port,
            'status' => 'not_created',
            'running' => false,
            'primary_cluster_running' => $primaryRunning,
            'runtime_mode' => $rt['mode'],
            'runtime_version' => $rt['version'],
            'connection_string' => "psql -h localhost -p {$port} -U postgres -d formation_pg",
        ];
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

        // S'assurer que le démon Docker tourne sous WSL2 s'il est arrêté
        if ($rt['mode'] === 'wsl2') {
            $ping = $this->runDockerCommand('ps -q');
            if ($ping['code'] !== 0) {
                @exec('wsl -u root service docker start 2>&1');
            }
        }

        // Vérifier si le conteneur existe déjà
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

        // Vérifier si l'image docker-pg-primary:18 est présente, sinon postgres:18
        $imgCheck = $this->runDockerCommand('image inspect ' . $this->config['image']);
        $imageToUse = ($imgCheck['code'] === 0) ? $this->config['image'] : $this->config['fallback_image'];

        $runArgs = sprintf(
            'run -d --name %s --hostname %s -p %d:5432 --shm-size=512m -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=%s -e POSTGRES_DB=formation_pg %s',
            $name,
            $name,
            $port,
            $this->config['default_password'],
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

        // Attendre que PostgreSQL accepte les connexions (jusqu'à 8 secondes) et amorcer le schéma de lab
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
        // Exécuter sur tous les conteneurs actifs (cluster primaire + conteneurs stagiaires)
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
    }
}
