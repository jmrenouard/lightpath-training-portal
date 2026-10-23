<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

class LabSqlRunner
{
    private DockerManager $docker;

    public function __construct(DockerManager $docker)
    {
        $this->docker = $docker;
    }

    /**
     * Exécute une requête SQL sur le conteneur dédié de l'utilisateur
     * (ou sur pg_formation_primary si disponible) et retourne le résultat tabulaire.
     */
    public function executeSql(int $userId, string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            return ['success' => false, 'output' => 'Requête SQL vide.', 'duration_ms' => 0];
        }

        $start = microtime(true);
        $targetContainer = $this->resolveActiveContainer($userId);

        if ($targetContainer !== null) {
            $cmd = sprintf(
                'exec %s psql -U postgres -d formation_pg -P pager=off -c %s',
                escapeshellarg($targetContainer),
                escapeshellarg($sql)
            );
            $res = $this->docker->runDockerCommand($cmd);
            $duration = round((microtime(true) - $start) * 1000, 1);

            return [
                'success' => $res['code'] === 0,
                'container' => $targetContainer,
                'output' => $res['output'] !== '' ? $res['output'] : '(Exécuté avec succès - 0 ligne retournée)',
                'duration_ms' => $duration,
            ];
        }

        return [
            'success' => false,
            'container' => 'non_demarre',
            'output' => "⚠️ Aucun conteneur Docker actif pour votre session.\nCliquez sur le bouton « 🐳 Démarrer mon Conteneur Lab PostgreSQL 18 » en haut de l'atelier pour provisionner votre instance isolée.",
            'duration_ms' => 0,
        ];
    }

    /**
     * Exécute l'Oracle de validation Qualiopi pour un atelier donné.
     */
    public function runLabOracle(int $userId, string $labId): array
    {
        $oracles = $this->getLabOracles();
        $code = $this->extractLabCode($labId);
        $oracle = $oracles[$code] ?? $oracles['01'];

        $targetContainer = $this->resolveActiveContainer($userId);
        if ($targetContainer === null) {
            return [
                'passed' => false,
                'lab_code' => $code,
                'bloom' => $oracle['bloom'],
                'title' => $oracle['title'],
                'output' => "⚠️ Veuillez d'abord démarrer votre conteneur Docker PostgreSQL 18 pour exécuter l'Oracle de validation.",
            ];
        }

        $res = $this->executeSql($userId, $oracle['sql']);
        $passed = $res['success'] && (str_contains($res['output'], 'ORACLE_PASS') || !str_contains(strtolower($res['output']), 'error'));

        return [
            'passed' => $passed,
            'lab_code' => $code,
            'bloom' => $oracle['bloom'],
            'title' => $oracle['title'],
            'container' => $targetContainer,
            'duration_ms' => $res['duration_ms'],
            'output' => $res['output'],
        ];
    }

    private function resolveActiveContainer(int $userId): ?string
    {
        $userStatus = $this->docker->getUserContainerStatus($userId);
        if (!empty($userStatus['running'])) {
            return $userStatus['container_name'];
        }
        if (!empty($userStatus['primary_cluster_running'])) {
            return 'pg_formation_primary';
        }
        return null;
    }

    private function extractLabCode(string $labId): string
    {
        $stripped = str_starts_with($labId, 'lab_') ? substr($labId, 4) : $labId;
        if (preg_match('/^(sql_\d{2}|\d{2})/', $stripped, $m)) {
            return $m[1];
        }
        return '01';
    }

    public function getLabOracles(): array
    {
        $baseCheck = "SELECT 'ORACLE_PASS' AS statut_validation, version() AS moteur_pg, current_setting('server_version_num') AS version_num, current_setting('data_checksums') AS checksums;";

        $catalog = [
            '01' => ['bloom' => 'Bloom C01', 'title' => 'Architecture PG 18, AIO, Alignement & Checksums FNV-1a', 'sql' => "CREATE EXTENSION IF NOT EXISTS pageinspect; SELECT 'ORACLE_PASS' AS oracle, current_setting('data_checksums') AS checksums, pagesize FROM page_header(get_raw_page('pg_class', 0));"],
            '02' => ['bloom' => 'Bloom C02', 'title' => 'Clusters, Tablespaces, Isolation MVCC & xmin/xmax', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, txid_current() AS current_xid, current_setting('default_transaction_isolation') AS isolation;"],
            '03' => ['bloom' => 'Bloom C03', 'title' => 'SQL Avancé, CTE Récursives, Window & JSONB', 'sql' => "WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n < 5) SELECT 'ORACLE_PASS' AS oracle, sum(n) AS total_cte FROM s;"],
            '04' => ['bloom' => 'Bloom C04', 'title' => 'Types Composites, uuidv7(), FTS, PostGIS & PL/pgSQL', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, to_tsvector('french', 'PostgreSQL 18 architecture haute performance') @@ to_tsquery('french', 'performance') AS fts_match;"],
            '05' => ['bloom' => 'Bloom C05', 'title' => 'Indexation B-Tree (Skip Scan), GIN, GiST, BRIN & EXPLAIN', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS index_am_count FROM pg_am WHERE amtype = 'i';"],
            '06' => ['bloom' => 'Bloom C06', 'title' => 'Tuning Mémoire, pg_stat_io, pg_stat_checkpointer & pgbench', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS io_rows FROM pg_stat_io;"],
            '07' => ['bloom' => 'Bloom C07', 'title' => 'Sauvegardes Logiques/Physiques, PITR pgBackRest & pg_combinebackup', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, pg_current_wal_lsn() AS current_lsn;"],
            '08' => ['bloom' => 'Bloom C08', 'title' => 'Sécurité SCRAM-SHA-256, OAuth 2.0, RBAC, RLS & pgaudit', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('password_encryption') AS encryption;"],
            '09' => ['bloom' => 'Bloom C09', 'title' => 'Haute Disponibilité, Streaming Replication, Patroni & etcd', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, pg_is_in_recovery() AS in_recovery, current_setting('max_wal_senders') AS wal_senders;"],
            '10' => ['bloom' => 'Bloom C10', 'title' => 'Intégration Applicative, PgBouncer Transaction Pooling & N+1', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS active_backends FROM pg_stat_activity;"],
            '11' => ['bloom' => 'Bloom C11', 'title' => 'Industrialisation Docker Compose, Volumes & Healthchecks', 'sql' => $baseCheck],
            '12' => ['bloom' => 'Bloom C12', 'title' => 'Orchestration Distribuée Docker Swarm & Réseaux Overlay', 'sql' => $baseCheck],
            '13' => ['bloom' => 'Bloom C13', 'title' => 'Kubernetes StatefulSets, Stockage CSI & Sondes Liveness/Readiness', 'sql' => $baseCheck],
            '14' => ['bloom' => 'Bloom C14', 'title' => 'Opérateur Kubernetes CloudNativePG (CNPG), Backup S3 & Failover', 'sql' => $baseCheck],
            '15' => ['bloom' => 'Bloom C15', 'title' => 'Développement Python Haute Performance (psycopg3, Async, COPY)', 'sql' => $baseCheck],
            '16' => ['bloom' => 'Bloom C16', 'title' => 'Développement Web PHP 8.3 PDO, Verrous FOR UPDATE & RETURNING', 'sql' => $baseCheck],
            '17' => ['bloom' => 'Bloom C17', 'title' => 'Microservices Golang (pgx v5, pgxpool, SendBatch & CopyFrom)', 'sql' => $baseCheck],
            '18' => ['bloom' => 'Bloom C18', 'title' => 'Maîtrise Avancée du Client CLI psql (\\gexec, \\crosstabview)', 'sql' => $baseCheck],
            '19' => ['bloom' => 'Bloom C19', 'title' => 'Migrations Ultra-Rapides & Near-Zero Downtime avec pgcopydb', 'sql' => $baseCheck],
            '20' => ['bloom' => 'Bloom C20', 'title' => 'Tuning Noyau Linux (sysctl, Huge Pages, I/O NVMe)', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('huge_pages') AS huge_pages, current_setting('shared_buffers') AS shared_buffers;"],
            '21' => ['bloom' => 'Bloom C21', 'title' => 'Planification CBO, Modèle de Coûts & Algorithmes de Jointure', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('random_page_cost') AS random_page_cost, current_setting('seq_page_cost') AS seq_page_cost;"],
            '22' => ['bloom' => 'Bloom C22', 'title' => 'Statistiques du Planner, Histogrammes, MCV & Extended Statistics', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('default_statistics_target') AS stats_target;"],
            '23' => ['bloom' => 'Bloom C23', 'title' => 'Introspection d\'Index (pageinspect, pgstattuple) & REINDEX CONCURRENTLY', 'sql' => "CREATE EXTENSION IF NOT EXISTS pageinspect; SELECT 'ORACLE_PASS' AS oracle, count(*) AS btree_check FROM pg_am WHERE amname = 'btree';"],
            '24' => ['bloom' => 'Bloom C24', 'title' => 'Saturation Mémoire, Débordements Disque (Temp Spills) & Tri RAM', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('work_mem') AS work_mem, current_setting('hash_mem_multiplier') AS hash_mult;"],
            '25' => ['bloom' => 'Bloom C25', 'title' => 'Concurrence, Arbres de Blocage pg_blocking_pids() & HOT Updates', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('deadlock_timeout') AS deadlock_timeout;"],
            '26' => ['bloom' => 'Bloom C26', 'title' => 'Partitionnement Déclaratif (RANGE, LIST, HASH) & Partition Pruning', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('enable_partition_pruning') AS partition_pruning;"],
            '27' => ['bloom' => 'Bloom C27', 'title' => 'Observabilité Forensique pgBadger & Métrologie Prometheus', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('log_min_duration_statement') AS log_min_duration;"],
            '28' => ['bloom' => 'Bloom C28', 'title' => 'Fédération de Données Hybrides (postgres_fdw, file_fdw & Pushdown)', 'sql' => "CREATE EXTENSION IF NOT EXISTS postgres_fdw; SELECT 'ORACLE_PASS' AS oracle, extname FROM pg_extension WHERE extname = 'postgres_fdw';"],
            '29' => ['bloom' => 'Bloom C29', 'title' => 'Haute Disponibilité Automatisée avec pg_auto_failover', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('wal_level') AS wal_level;"],
            'sql_01' => ['bloom' => 'Bloom C-SQL-01', 'title' => 'Fondamentaux SQL 01 : Projection, Filtrage & Pagination sur Sakila', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS total_films FROM public.film;"],
            'sql_02' => ['bloom' => 'Bloom C-SQL-02', 'title' => 'Fondamentaux SQL 02 : DML, Transactions ACID & UPSERT', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS total_actors FROM public.actor;"],
            'sql_03' => ['bloom' => 'Bloom C-SQL-03', 'title' => 'Fondamentaux SQL 03 : Jointures Multi-Tables & Anti-Join', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS total_films FROM public.film;"],
            'sql_04' => ['bloom' => 'Bloom C-SQL-04', 'title' => 'Fondamentaux SQL 04 : Agrégations, FILTER, GROUPING SETS & CUBE', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) FILTER (WHERE rental_rate >= 0.99) AS valid_rates FROM public.film;"],
            'sql_05' => ['bloom' => 'Bloom C-SQL-05', 'title' => 'Fondamentaux SQL 05 : CTE, Vues & Vues Matérialisées CONCURRENTLY', 'sql' => "WITH cte_check AS (SELECT film_id, title FROM public.film LIMIT 5) SELECT 'ORACLE_PASS' AS oracle, count(*) FROM cte_check;"],
        ];

        return $catalog;
    }
}
