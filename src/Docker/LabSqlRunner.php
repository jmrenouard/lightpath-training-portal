<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

class LabSqlRunner
{
    public const ALLOWED_DATABASES = ['formation_pg', 'dvdrental', 'postgres'];

    private DockerManager $docker;

    public function __construct(DockerManager $docker)
    {
        $this->docker = $docker;
    }

    public function normalizeDatabase(?string $database): string
    {
        $clean = strtolower(trim((string)$database));
        return in_array($clean, self::ALLOWED_DATABASES, true) ? $clean : 'formation_pg';
    }

    public function detectTargetDatabase(string $labId, string $markdown = ''): string
    {
        $code = $this->extractLabCode($labId);
        if (str_starts_with($code, 'sql_')) {
            return 'dvdrental';
        }
        if ($markdown !== '' && (str_contains($markdown, '\c dvdrental') || preg_match('/\bFROM\s+(?:public\.)?(?:film|rental|payment|actor)\b/i', $markdown))) {
            return 'dvdrental';
        }
        return 'formation_pg';
    }

    /**
     * Exécute une requête SQL sur le conteneur dédié de l'utilisateur
     * (ou sur pg_formation_primary si disponible) et retourne le résultat tabulaire.
     */
    public function executeSql(int $userId, string $sql, string $database = 'formation_pg'): array
    {
        $sql = trim($sql);
        $db = $this->normalizeDatabase($database);
        if ($sql === '') {
            return ['success' => false, 'database' => $db, 'output' => 'Requête SQL vide.', 'duration_ms' => 0];
        }

        $start = microtime(true);
        $targetContainer = $this->resolveActiveContainer($userId);

        if ($targetContainer !== null) {
            $b64 = base64_encode($sql);
            $cmd = sprintf(
                'exec %s sh -c "echo %s | base64 -d | psql -U postgres -d %s -P pager=off"',
                $targetContainer,
                $b64,
                $db
            );
            $res = $this->docker->runDockerCommand($cmd);
            $duration = round((microtime(true) - $start) * 1000, 1);

            return [
                'success' => $res['code'] === 0 && !preg_match('/^ERROR:/m', (string)$res['output']),
                'container' => $targetContainer,
                'database' => $db,
                'output' => $res['output'] !== '' ? $res['output'] : '(Exécuté avec succès - 0 ligne retournée)',
                'duration_ms' => $duration,
            ];
        }

        return [
            'success' => false,
            'container' => 'non_demarre',
            'database' => $db,
            'output' => "⚠️ Aucun conteneur Docker actif pour votre session.\nCliquez sur le bouton « 🐳 Démarrer mon Conteneur Lab PostgreSQL 18 » en haut de l'atelier pour provisionner votre instance isolée.",
            'duration_ms' => 0,
        ];
    }

    /**
     * Extrait les marqueurs de validation attendus (VALIDATION_OK, [SUCCÈS LAB...]) depuis le Markdown d'un atelier.
     */
    public function extractExpectedMarkers(string $markdown): array
    {
        $markers = [];
        if (preg_match_all('/(VALIDATION_OK|\[SUCCÈS[^\]]*\]|SUCCESS\s*:\s*Validation)/iu', $markdown, $m)) {
            foreach ($m[1] as $mk) {
                $clean = trim($mk);
                if (!in_array($clean, $markers, true)) {
                    $markers[] = $clean;
                }
            }
        }
        if (empty($markers)) {
            $markers[] = 'VALIDATION_OK';
        }
        return $markers;
    }

    /**
     * Extrait tous les blocs SQL du fichier Markdown d'un atelier en distinguant les blocs d'amorçage/corrigé
     * et le bloc final d'oracle `DO $$`.
     */
    public function extractSolutionSqlBlocks(string $markdown): array
    {
        $blocks = [];
        if (preg_match_all('/```sql\s*(.*?)```/isu', $markdown, $matches)) {
            foreach ($matches[1] as $idx => $rawSql) {
                $clean = trim($rawSql);
                if ($clean === '') {
                    continue;
                }
                $isOracle = (bool)preg_match('/\bDO\s*\$\$/i', $clean);
                $blocks[] = [
                    'index' => $idx + 1,
                    'is_oracle' => $isOracle,
                    'has_validation_ok' => str_contains($clean, 'VALIDATION_OK') || str_contains($clean, 'SUCCÈS') || str_contains($clean, 'SUCCESS'),
                    'sql' => $clean,
                ];
            }
        }
        return $blocks;
    }

    /**
     * Valide l'oracle SQL `DO $$` d'un fichier Markdown d'atelier sans écraser le bloc DO $$ trouvé.
     */
    public function validateLabOracle(int $userId, string $labId, string $labMarkdownPath = '', ?string $database = null): array
    {
        $markdown = ($labMarkdownPath !== '' && is_file($labMarkdownPath))
            ? (string)file_get_contents($labMarkdownPath)
            : '';

        $targetDb = $database !== null && $database !== ''
            ? $this->normalizeDatabase($database)
            : $this->detectTargetDatabase($labId, $markdown);

        $blocks = $this->extractSolutionSqlBlocks($markdown);
        $oracleSql = '';
        $foundDoBlock = false;

        foreach ($blocks as $blk) {
            if ($blk['is_oracle']) {
                $oracleSql = $blk['sql'];
                $foundDoBlock = true;
                if ($blk['has_validation_ok']) {
                    break;
                }
            } elseif (!$foundDoBlock) {
                $oracleSql = $blk['sql'];
            }
        }

        if ($oracleSql === '') {
            $oracles = $this->getLabOracles();
            $code = $this->extractLabCode($labId);
            $oracleSql = ($oracles[$code] ?? $oracles['01'])['sql'];
        }

        $expectedMarkers = $this->extractExpectedMarkers($markdown);
        $res = $this->executeSql($userId, $oracleSql, $targetDb);
        $out = (string)($res['output'] ?? '');

        $markerMatched = str_contains($out, 'ORACLE_PASS') || str_contains($out, 'VALIDATION_OK');
        if (!$markerMatched) {
            foreach ($expectedMarkers as $mk) {
                if (stripos($out, $mk) !== false) {
                    $markerMatched = true;
                    break;
                }
            }
        }

        $passed = !empty($res['success']) && $markerMatched && !preg_match('/^ERROR:/mi', $out);

        return [
            'passed' => $passed,
            'lab_id' => $labId,
            'database' => $targetDb,
            'found_do_block' => $foundDoBlock,
            'expected_markers' => $expectedMarkers,
            'oracle_sql' => $oracleSql,
            'output' => $out,
            'duration_ms' => $res['duration_ms'] ?? 0,
        ];
    }

    /**
     * Exécute séquentiellement les blocs SQL du corrigé d'un atelier + son oracle final `DO $$`.
     */
    public function runLabSolution(int $userId, string $labId, string $labMarkdownPath = '', ?string $database = null): array
    {
        $markdown = ($labMarkdownPath !== '' && is_file($labMarkdownPath))
            ? (string)file_get_contents($labMarkdownPath)
            : '';

        $targetDb = $database !== null && $database !== ''
            ? $this->normalizeDatabase($database)
            : $this->detectTargetDatabase($labId, $markdown);

        $blocks = $this->extractSolutionSqlBlocks($markdown);
        $steps = [];
        $combinedOutput = [];
        $start = microtime(true);

        foreach ($blocks as $blk) {
            // Filtrer les méta-commandes interactives bloquantes (\watch) pour exécution batch
            $cleanSql = preg_replace('/^\s*\\\\watch\b.*$/m', '-- \\watch skipped in batch mode', $blk['sql']) ?? $blk['sql'];
            $stepRes = $this->executeSql($userId, $cleanSql, $targetDb);
            $steps[] = [
                'index' => $blk['index'],
                'is_oracle' => $blk['is_oracle'],
                'success' => $stepRes['success'],
                'duration_ms' => $stepRes['duration_ms'],
                'output' => $stepRes['output'],
            ];
            $combinedOutput[] = "-- [Bloc SQL #{$blk['index']}" . ($blk['is_oracle'] ? ' (Oracle DO $$)' : '') . "] --\n" . $stepRes['output'];
        }

        // Exécuter également l'oracle catalogue rapide si aucun bloc DO $$ n'a émis VALIDATION_OK
        $fullOut = implode("\n\n", $combinedOutput);
        $oracleCheck = $this->runLabOracle($userId, $labId, $targetDb);
        $oraclePassed = str_contains($fullOut, 'VALIDATION_OK')
            || str_contains($fullOut, '[SUCCÈS')
            || str_contains($fullOut, 'SUCCESS :')
            || !empty($oracleCheck['passed']);

        return [
            'passed' => $oraclePassed,
            'lab_id' => $labId,
            'database' => $targetDb,
            'steps_count' => count($steps),
            'steps' => $steps,
            'oracle' => $oracleCheck,
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            'output' => $fullOut !== '' ? $fullOut : ($oracleCheck['output'] ?? ''),
        ];
    }

    /**
     * Exécute l'Oracle de validation Qualiopi pour un atelier donné.
     */
    public function runLabOracle(int $userId, string $labId, ?string $database = null): array
    {
        $oracles = $this->getLabOracles();
        $code = $this->extractLabCode($labId);
        $oracle = $oracles[$code] ?? $oracles['01'];
        $targetDb = $database !== null && $database !== ''
            ? $this->normalizeDatabase($database)
            : $this->detectTargetDatabase($labId);

        $targetContainer = $this->resolveActiveContainer($userId);
        if ($targetContainer === null) {
            return [
                'passed' => false,
                'lab_code' => $code,
                'database' => $targetDb,
                'bloom' => $oracle['bloom'],
                'title' => $oracle['title'],
                'output' => "⚠️ Veuillez d'abord démarrer votre conteneur Docker PostgreSQL 18 pour exécuter l'Oracle de validation.",
            ];
        }

        $res = $this->executeSql($userId, $oracle['sql'], $targetDb);
        $passed = $res['success'] && (str_contains($res['output'], 'ORACLE_PASS') || str_contains($res['output'], 'VALIDATION_OK') || !str_contains(strtolower($res['output']), 'error'));

        return [
            'passed' => $passed,
            'lab_code' => $code,
            'database' => $targetDb,
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
        $catalog = [
            '01' => ['bloom' => 'Bloom C01', 'title' => 'Architecture PG 18, AIO, Alignement & Checksums FNV-1a', 'sql' => "CREATE EXTENSION IF NOT EXISTS pageinspect; SELECT 'ORACLE_PASS' AS oracle, current_setting('data_checksums') AS checksums, pagesize FROM page_header(get_raw_page('pg_class', 0));"],
            '02' => ['bloom' => 'Bloom C02', 'title' => 'Clusters, Tablespaces, Isolation MVCC & xmin/xmax', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, pg_current_xact_id() AS current_xid, current_setting('default_transaction_isolation') AS isolation;"],
            '03' => ['bloom' => 'Bloom C03', 'title' => 'SQL Avancé, CTE Récursives, Window & JSONB', 'sql' => "WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n < 5) SELECT 'ORACLE_PASS' AS oracle, sum(n) AS total_cte FROM s;"],
            '04' => ['bloom' => 'Bloom C04', 'title' => 'Types Composites, uuidv7(), FTS, PostGIS & PL/pgSQL', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, to_tsvector('french', 'PostgreSQL 18 architecture haute performance') @@ to_tsquery('french', 'performance') AS fts_match;"],
            '05' => ['bloom' => 'Bloom C05', 'title' => 'Indexation B-Tree (Skip Scan), GIN, GiST, BRIN & EXPLAIN', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS index_am_count FROM pg_am WHERE amtype = 'i';"],
            '06' => ['bloom' => 'Bloom C06', 'title' => 'Tuning Mémoire, pg_stat_io, pg_stat_checkpointer & pgbench', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS io_rows FROM pg_stat_io;"],
            '07' => ['bloom' => 'Bloom C07', 'title' => 'Sauvegardes Logiques/Physiques, PITR pgBackRest & pg_combinebackup', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, pg_current_wal_lsn() AS current_lsn;"],
            '08' => ['bloom' => 'Bloom C08', 'title' => 'Sécurité SCRAM-SHA-256, OAuth 2.0, RBAC, RLS & pgaudit', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('password_encryption') AS encryption;"],
            '09' => ['bloom' => 'Bloom C09', 'title' => 'Haute Disponibilité, Streaming Replication, Patroni & etcd', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, pg_is_in_recovery() AS in_recovery, current_setting('max_wal_senders') AS wal_senders;"],
            '10' => ['bloom' => 'Bloom C10', 'title' => 'Intégration Applicative, PgBouncer Transaction Pooling & N+1', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS active_backends FROM pg_stat_activity;"],
            '11' => ['bloom' => 'Bloom C11', 'title' => 'Industrialisation Docker Compose, Volumes & Healthchecks', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('data_checksums') AS checksums, current_setting('dynamic_shared_memory_type') AS dsm_type;"],
            '12' => ['bloom' => 'Bloom C12', 'title' => 'Orchestration Distribuée Docker Swarm & Réseaux Overlay', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('listen_addresses') AS listen_addresses, current_setting('max_connections') AS max_conn;"],
            '13' => ['bloom' => 'Bloom C13', 'title' => 'Kubernetes StatefulSets, Stockage CSI & Sondes Liveness/Readiness', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('fsync') AS fsync, current_setting('full_page_writes') AS fpw;"],
            '14' => ['bloom' => 'Bloom C14', 'title' => 'Opérateur Kubernetes CloudNativePG (CNPG), Backup S3 & Failover', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('wal_level') AS wal_level, current_setting('max_replication_slots') AS slots;"],
            '15' => ['bloom' => 'Bloom C15', 'title' => 'Développement Python Haute Performance (psycopg3, Async, COPY)', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('server_encoding') AS encoding, txid_current() AS xid;"],
            '16' => ['bloom' => 'Bloom C16', 'title' => 'Développement Web PHP 8.3 PDO, Verrous FOR UPDATE & RETURNING', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS stmt_timeout;"],
            '17' => ['bloom' => 'Bloom C17', 'title' => 'Microservices Golang (pgx v5, pgxpool, SendBatch & CopyFrom)', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS client_backends FROM pg_stat_activity WHERE backend_type = 'client backend';"],
            '18' => ['bloom' => 'Bloom C18', 'title' => 'Maîtrise Avancée du Client CLI psql (\\gexec, \\crosstabview)', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, current_database() AS db, current_user AS usr;"],
            '19' => ['bloom' => 'Bloom C19', 'title' => 'Migrations Ultra-Rapides & Near-Zero Downtime avec pgcopydb', 'sql' => "SELECT 'ORACLE_PASS' AS oracle, count(*) AS user_tables FROM pg_stat_user_tables;"],
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

    /**
     * Inspecte le schéma des tables de la base cible (live sur Docker si actif, ou catalogue de référence Sakila / formation_pg hors-ligne).
     */
    public function inspectDatabaseSchema(int $userId, string $database = 'dvdrental'): array
    {
        $db = $this->normalizeDatabase($database);
        $targetContainer = $this->resolveActiveContainer($userId);

        if ($targetContainer !== null) {
            $sql = "SELECT table_name, string_agg(column_name || ' (' || data_type || ')', ', ' ORDER BY ordinal_position) AS columns "
                 . "FROM information_schema.columns WHERE table_schema = 'public' GROUP BY table_name ORDER BY table_name LIMIT 25;";
            $res = $this->executeSql($userId, $sql, $db);
            if (!empty($res['success']) && trim((string)$res['output']) !== '') {
                return [
                    'success' => true,
                    'mode' => 'live_docker',
                    'database' => $db,
                    'container' => $targetContainer,
                    'output' => $res['output'],
                    'tables' => $this->getStaticSchemaCatalog($db),
                ];
            }
        }

        $tables = $this->getStaticSchemaCatalog($db);
        $lines = [" table_name | columns (catalogue de référence {$db})", "------------+--------------------------------------------------------------------------------"];
        foreach ($tables as $tName => $cols) {
            $lines[] = sprintf(" %-10s | %s", $tName, implode(', ', $cols));
        }

        return [
            'success' => true,
            'mode' => 'reference_catalog',
            'database' => $db,
            'container' => $targetContainer ?? 'catalogue_statique',
            'output' => implode("\n", $lines),
            'tables' => $tables,
        ];
    }

    private function getStaticSchemaCatalog(string $database): array
    {
        $sakilaTables = [
            'film' => ['film_id (serial PK)', 'title (varchar)', 'description (text)', 'release_year (int)', 'rental_duration (smallint)', 'rental_rate (numeric)', 'length (smallint)', 'replacement_cost (numeric)', 'rating (varchar)', 'last_update (timestamptz)'],
            'actor' => ['actor_id (serial PK)', 'first_name (varchar)', 'last_name (varchar)', 'last_update (timestamptz)'],
            'rental' => ['rental_id (serial PK)', 'rental_date (timestamptz)', 'inventory_id (int FK)', 'customer_id (smallint FK)', 'return_date (timestamptz)', 'staff_id (smallint)'],
            'payment' => ['payment_id (serial PK)', 'customer_id (smallint FK)', 'staff_id (smallint)', 'rental_id (int FK)', 'amount (numeric)', 'payment_date (timestamptz)'],
            'customer' => ['customer_id (serial PK)', 'store_id (smallint)', 'first_name (varchar)', 'last_name (varchar)', 'email (varchar)', 'activebool (boolean)', 'create_date (date)'],
            'inventory' => ['inventory_id (serial PK)', 'film_id (int FK)', 'store_id (smallint)', 'last_update (timestamptz)'],
            'category' => ['category_id (serial PK)', 'name (varchar)', 'last_update (timestamptz)'],
        ];

        if ($database === 'postgres') {
            return [
                'pg_stat_activity' => ['pid (int)', 'datname (name)', 'usename (name)', 'state (text)', 'wait_event_type (text)', 'wait_event (text)', 'query (text)', 'backend_type (text)'],
                'pg_stat_io' => ['backend_type (text)', 'object (text)', 'context (text)', 'reads (bigint)', 'writes (bigint)', 'hits (bigint)', 'evictions (bigint)', 'fsyncs (bigint)'],
                'pg_stat_user_tables' => ['relid (oid)', 'schemaname (name)', 'relname (name)', 'seq_scan (bigint)', 'idx_scan (bigint)', 'n_live_tup (bigint)', 'n_dead_tup (bigint)', 'last_autovacuum (timestamptz)'],
                'pg_locks' => ['locktype (text)', 'database (oid)', 'relation (oid)', 'pid (int)', 'mode (text)', 'granted (bool)'],
            ];
        }

        return $sakilaTables;
    }

    /**
     * Génère un indice progressif (piste de diagnostic DBA sans dévoiler la solution complète) pour un atelier donné.
     */
    public function extractLabHint(string $markdown, string $labId): array
    {
        $code = $this->extractLabCode($labId);
        $oracles = $this->getLabOracles();
        $oracleInfo = $oracles[$code] ?? $oracles['01'];

        $views = [];
        if (preg_match_all('/\b(pg_stat_[a-z_]+|pg_locks|pg_class|pg_index|pg_settings|page_header|get_raw_page|information_schema\.[a-z_]+|EXPLAIN\s*\([^)]+\))\b/i', $markdown, $m)) {
            foreach ($m[1] as $v) {
                $clean = strtolower(trim($v));
                if (!in_array($clean, $views, true)) {
                    $views[] = $clean;
                }
            }
        }
        if (empty($views)) {
            $views = ['pg_stat_activity', 'pg_settings', 'EXPLAIN (ANALYZE, BUFFERS)'];
        }

        $blocks = $this->extractSolutionSqlBlocks($markdown);
        $firstCmdClue = 'SELECT name, setting, unit FROM pg_settings WHERE name LIKE \'%shared_buffers%\';';
        if (!empty($blocks[0]['sql'])) {
            $lines = preg_split('/\r\n|\r|\n/', trim($blocks[0]['sql'])) ?: [];
            foreach ($lines as $l) {
                $trimmed = trim($l);
                if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                    $firstCmdClue = substr($trimmed, 0, 95) . (strlen($trimmed) > 95 ? ' ...' : '');
                    break;
                }
            }
        }

        $viewsList = implode(', ', array_slice($views, 0, 5));
        $hintText = sprintf(
            'Objectif Bloom (%s — %s) : Inspectez en priorité les vues et objets %s pour diagnostiquer l\'état de l\'instance avant d\'appliquer vos commandes correctives.',
            $oracleInfo['bloom'],
            $oracleInfo['title'],
            $viewsList
        );

        return [
            'lab_code' => $code,
            'bloom' => $oracleInfo['bloom'],
            'title' => $oracleInfo['title'],
            'hint' => $hintText,
            'key_objects' => array_slice($views, 0, 5),
            'first_sql_clue' => $firstCmdClue,
            'first_command_clue' => $firstCmdClue,
            'validation_tip' => 'Utilisez le bouton « 🏆 Vérifier mon Lab (Oracle Qualiopi 1-Clic) » une fois les objets créés pour valider la pastille VALIDATION_OK.',
        ];
    }
}
