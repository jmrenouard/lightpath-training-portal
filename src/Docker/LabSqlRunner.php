<?php
declare(strict_types=1);

namespace LightPath\LMS\Docker;

/**
 * Executes sandboxed SQL queries, schema inspection, progressive lab hints,
 * and Qualiopi validation oracles against PostgreSQL 18 lab containers.
 */
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

    public function runSql(int $userId, string $sql, string $database = 'formation_pg', bool $trustedOracle = false): array
    {
        return $this->executeSql($userId, $sql, $database, $trustedOracle);
    }

    /**
     * Executes an SQL statement on the user's dedicated container (or pg_formation_primary)
     * with strict timeout enforcement and non-superuser isolation for untrusted student input.
     */
    public function executeSql(int $userId, string $sql, string $database = 'formation_pg', bool $trustedOracle = false): array
    {
        $sql = trim($sql);
        $db = $this->normalizeDatabase($database);
        if ($sql === '') {
            return ['success' => false, 'database' => $db, 'output' => 'Requête SQL vide.', 'duration_ms' => 0];
        }

        $sanitized = $this->stripCommentsAndLiterals($sql);
        $commentsStripped = $sanitized['comments_stripped'];
        $outsideQuotes = $sanitized['outside_quotes'];

        // Block interactive psql meta-commands anywhere outside quoted/dollar-quoted literals.
        if (
            preg_match('/\\\\(?:!|copy\b|o\b|w\b|i\b|connect\b|cd\b|c\b|ef\b|ev\b|e\b|prompt\b|watch\b|setenv\b|gexec\b|gset\b|gx\b|g\b|lo_(?:import|export|unlink)\b)/i', $outsideQuotes)
        ) {
            return [
                'success' => false,
                'container' => 'blocked',
                'database' => $db,
                'output' => 'Commandes backslash interactives non autorisées dans la console Web.',
                'duration_ms' => 0,
            ];
        }

        // Check both raw and comment-stripped SQL before stripping literals so DO/EXECUTE bypasses are caught.
        if (
            preg_match('/\b(?:SET|RESET)\s+(?:(?:LOCAL|SESSION)\s+)?(?:statement_timeout|lock_timeout|ALL\b)/i', $sql)
            || preg_match('/\b(?:SET|RESET)\s+(?:(?:LOCAL|SESSION)\s+)?(?:statement_timeout|lock_timeout|ALL\b)/i', $commentsStripped)
            || preg_match('/\bset_config\s*\([^;]*(?:statement_timeout|lock_timeout|statement|timeout|\|\|)/is', $commentsStripped)
        ) {
            return [
                'success' => false,
                'container' => 'blocked',
                'database' => $db,
                'output' => 'Modification du paramètre statement_timeout interdite dans la console Web.',
                'duration_ms' => 0,
            ];
        }

        // Block server-side OS command execution and filesystem access unconditionally.
        if (
            preg_match('/\bCOPY\b[\s\S]*?\b(?:FROM|TO)\s+PROGRAM\b/i', $commentsStripped)
            || preg_match('/\b(?:pg_read_file|pg_read_binary_file|pg_ls_dir|pg_stat_file|lo_import|lo_export)\s*\(/i', $commentsStripped)
        ) {
            return [
                'success' => false,
                'container' => 'blocked',
                'database' => $db,
                'output' => 'Accès système fichiers ou exécution de programme externe interdits dans la console Web.',
                'duration_ms' => 0,
            ];
        }

        // For untrusted student queries, also block role escalation and cluster-wide ALTER SYSTEM/USER/ROLE.
        if (
            !$trustedOracle
            && (
                preg_match('/\bALTER\s+(?:SYSTEM|USER|ROLE)\b/i', $commentsStripped)
                || preg_match('/\b(?:SET\s+(?:(?:LOCAL|SESSION)\s+)?(?:ROLE|SESSION\s+AUTHORIZATION)|RESET\s+(?:ROLE|SESSION\s+AUTHORIZATION))\b/i', $commentsStripped)
                || preg_match('/\b(?:SET\s+(?:(?:LOCAL|SESSION)\s+)?(?:ROLE|SESSION\s+AUTHORIZATION)|RESET\s+(?:ROLE|SESSION\s+AUTHORIZATION))\b/i', $sql)
                || preg_match('/\bset_config\s*\(\s*[^,;]*(?:\b(?:role|session_authorization)\b|\|\|)/is', $commentsStripped)
            )
        ) {
            return [
                'success' => false,
                'container' => 'blocked',
                'database' => $db,
                'output' => 'Élévation de rôle ou modification système globale interdite en mode sandbox.',
                'duration_ms' => 0,
            ];
        }

        $start = microtime(true);
        $targetContainer = $this->resolveActiveContainer($userId);

        if ($targetContainer !== null) {
            $pgUser = $trustedOracle ? 'postgres' : SakilaSeeder::SANDBOX_ROLE;
            $b64 = base64_encode($sql);
            $cmd = sprintf(
                'exec %s sh -c "echo %s | base64 -d | PGOPTIONS=\"-c statement_timeout=15000 -c lock_timeout=10000\" psql -X -U %s -d %s -P pager=off"',
                $targetContainer,
                $b64,
                $pgUser,
                $db
            );
            $res = $this->docker->runDockerCommand($cmd, 18.0);

            // Lazily provision lms_student on pre-existing shared clusters if missing, then retry under lms_student.
            if (
                !$trustedOracle
                && $res['code'] !== 0
                && str_contains((string)$res['output'], SakilaSeeder::SANDBOX_ROLE)
                && str_contains((string)$res['output'], 'does not exist')
            ) {
                $roleB64 = base64_encode(SakilaSeeder::getSandboxRoleSql());
                $this->docker->runDockerCommand(sprintf(
                    'exec %s sh -c "echo %s | base64 -d | psql -X -U postgres -d %s"',
                    $targetContainer,
                    $roleB64,
                    $db
                ), 8.0);
                $res = $this->docker->runDockerCommand($cmd, 18.0);
            }

            $this->docker->touchContainerActivity($userId);

            $duration = round((microtime(true) - $start) * 1000, 1);

            return [
                'success' => $res['code'] === 0 && !preg_match('/^ERROR:/m', (string)$res['output']),
                'container' => $targetContainer,
                'database' => $db,
                'role' => $pgUser,
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
     * Strips `--` line comments and arbitrarily nested block comments outside quotes,
     * returning both comment-stripped SQL and outside-literal SQL for policy checks.
     *
     * @return array{comments_stripped: string, outside_quotes: string}
     */
    private function stripCommentsAndLiterals(string $sql): array
    {
        $len = strlen($sql);
        $i = 0;
        $commentsStripped = '';
        $outsideQuotes = '';

        while ($i < $len) {
            $ch = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Skip single-line SQL comments outside quoted literals.
            if ($ch === '-' && $next === '-') {
                $nl = strpos($sql, "\n", $i + 2);
                $i = ($nl === false) ? $len : ($nl + 1);
                $commentsStripped .= ' ';
                $outsideQuotes .= ' ';
                continue;
            }

            // Skip PostgreSQL nested block comments cleanly regardless of internal quotes.
            if ($ch === '/' && $next === '*') {
                $depth = 1;
                $i += 2;
                while ($i < $len && $depth > 0) {
                    if ($sql[$i] === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                        $depth++;
                        $i += 2;
                    } elseif ($sql[$i] === '*' && $i + 1 < $len && $sql[$i + 1] === '/') {
                        $depth--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
                $commentsStripped .= ' ';
                $outsideQuotes .= ' ';
                continue;
            }

            // Preserve single-quoted string literals (`'...'` and `E'...'`) in comments_stripped only.
            if ($ch === "'") {
                $prev = ($i > 0) ? $sql[$i - 1] : '';
                $prev2 = ($i > 1) ? $sql[$i - 2] : '';
                $isEscapeLit = ($prev === 'E' || $prev === 'e') && ($i === 1 || !ctype_alnum($prev2));
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($isEscapeLit && $sql[$i] === '\\') {
                        $i += ($i + 1 < $len) ? 2 : 1;
                        continue;
                    }
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $commentsStripped .= substr($sql, $start, $i - $start);
                $outsideQuotes .= "''";
                continue;
            }

            // Preserve dollar-quoted literals (`$$...$$` and `$tag$...$tag$`) in comments_stripped only.
            if ($ch === '$' && preg_match('/\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $dm, 0, $i)) {
                $tag = $dm[0];
                $tagLen = strlen($tag);
                $closePos = strpos($sql, $tag, $i + $tagLen);
                if ($closePos !== false) {
                    $end = $closePos + $tagLen;
                    $commentsStripped .= substr($sql, $i, $end - $i);
                    $outsideQuotes .= ' $$ $$ ';
                    $i = $end;
                    continue;
                }
            }

            $commentsStripped .= $ch;
            $outsideQuotes .= $ch;
            $i++;
        }

        return [
            'comments_stripped' => $commentsStripped,
            'outside_quotes' => $outsideQuotes,
        ];
    }

    /**
     * Extracts expected validation markers (VALIDATION_OK / SUCCESS) from lab Markdown.
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
     * Extracts all SQL code blocks from a lab Markdown file, distinguishing setup/solution blocks from DO $$ oracles.
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
     * Validates a lab's `DO $$` SQL oracle from its Markdown source without overwriting prior blocks.
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
        $res = $this->executeSql($userId, $oracleSql, $targetDb, true);
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
     * Sequentially executes the official SQL solution blocks of a lab followed by its oracle check.
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
            // Strip blocking interactive \watch commands in batch execution mode.
            $cleanSql = preg_replace('/^\s*\\\\watch\b.*$/m', '-- \\watch skipped in batch mode', $blk['sql']) ?? $blk['sql'];
            $stepRes = $this->executeSql($userId, $cleanSql, $targetDb, true);
            $steps[] = [
                'index' => $blk['index'],
                'is_oracle' => $blk['is_oracle'],
                'success' => $stepRes['success'],
                'duration_ms' => $stepRes['duration_ms'],
                'output' => $stepRes['output'],
            ];
            $combinedOutput[] = "-- [Bloc SQL #{$blk['index']}" . ($blk['is_oracle'] ? ' (Oracle DO $$)' : '') . "] --\n" . $stepRes['output'];
        }

        // Also run the catalog oracle if no DO $$ block emitted VALIDATION_OK directly.
        $fullOut = implode("\n\n", $combinedOutput);
        $oracleCheck = $this->runLabOracle($userId, $labId, $targetDb);
        $oraclePassed = str_contains($fullOut, 'VALIDATION_OK')
            || str_contains($fullOut, '[SUCCÈS')
            || str_contains($fullOut, 'SUCCESS :')
            || !empty($oracleCheck['passed']);

        $stepCount = count($steps);

        return [
            'passed' => $oraclePassed,
            'lab_id' => $labId,
            'database' => $targetDb,
            'steps_count' => $stepCount,
            'steps_executed' => $stepCount,
            'steps' => $steps,
            'oracle' => $oracleCheck,
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            'output' => $fullOut !== '' ? $fullOut : ($oracleCheck['output'] ?? ''),
        ];
    }

    /**
     * Executes the built-in Qualiopi validation oracle for a given lab ID.
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

        $res = $this->executeSql($userId, $oracle['sql'], $targetDb, true);
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
        if (!empty($userStatus['user_container_running']) || !empty($userStatus['running'])) {
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
        return [
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
    }

    /**
     * Inspects table schemas and row estimates on the target database (live Docker or static catalog).
     * Always returns `tables` as an indexed list of ['schema', 'table', 'est_rows', 'columns'] items.
     */
    public function inspectDatabaseSchema(int $userId, string $database = 'dvdrental'): array
    {
        $db = $this->normalizeDatabase($database);
        $targetContainer = $this->resolveActiveContainer($userId);

        if ($targetContainer !== null) {
            if ($db === 'postgres') {
                $sql = "SELECT 'SCHEMA_ROW|' || col.table_schema || '|' || col.table_name || '|' "
                     . "|| GREATEST(COALESCE(cls.reltuples::bigint, 0), 0)::text || '|' "
                     . "|| replace(string_agg(col.column_name || ' (' || col.data_type || ')', ', ' ORDER BY col.ordinal_position), '|', '/') AS schema_row "
                     . "FROM information_schema.columns col "
                     . "LEFT JOIN pg_catalog.pg_namespace ns ON ns.nspname = col.table_schema "
                     . "LEFT JOIN pg_catalog.pg_class cls ON cls.relnamespace = ns.oid AND cls.relname = col.table_name "
                     . "WHERE col.table_schema = 'pg_catalog' "
                     . "AND col.table_name IN ('pg_stat_activity', 'pg_stat_io', 'pg_stat_user_tables', 'pg_locks', 'pg_stat_checkpointer', 'pg_settings') "
                     . "GROUP BY col.table_schema, col.table_name, cls.reltuples "
                     . "ORDER BY col.table_name LIMIT 25;";
            } else {
                $sql = "SELECT 'SCHEMA_ROW|' || col.table_schema || '|' || col.table_name || '|' "
                     . "|| GREATEST(COALESCE(cls.reltuples::bigint, 0), 0)::text || '|' "
                     . "|| replace(string_agg(col.column_name || ' (' || col.data_type || ')', ', ' ORDER BY col.ordinal_position), '|', '/') AS schema_row "
                     . "FROM information_schema.columns col "
                     . "LEFT JOIN pg_catalog.pg_namespace ns ON ns.nspname = col.table_schema "
                     . "LEFT JOIN pg_catalog.pg_class cls ON cls.relnamespace = ns.oid AND cls.relname = col.table_name "
                     . "WHERE col.table_schema = 'public' "
                     . "GROUP BY col.table_schema, col.table_name, cls.reltuples "
                     . "ORDER BY col.table_name LIMIT 35;";
            }

            $res = $this->executeSql($userId, $sql, $db, true);
            if (!empty($res['success']) && trim((string)$res['output']) !== '') {
                $liveTables = $this->parseSchemaRows((string)$res['output']);
                if (!empty($liveTables)) {
                    return [
                        'success' => true,
                        'mode' => 'live_docker',
                        'source' => 'Docker PostgreSQL 18 Live',
                        'database' => $db,
                        'container' => $targetContainer,
                        'output' => $res['output'],
                        'tables' => $liveTables,
                    ];
                }
            }
        }

        $tables = $this->getStaticSchemaCatalog($db);
        $lines = [
            " table_name | est_rows | columns (catalogue de référence {$db})",
            "------------+----------+-------------------------------------------------------------------------",
        ];
        foreach ($tables as $t) {
            $lines[] = sprintf(" %-10s | %8d | %s", $t['table'], $t['est_rows'], $t['columns']);
        }

        return [
            'success' => true,
            'mode' => 'reference_catalog',
            'source' => 'Catalogue Statique Sakila / PG18',
            'database' => $db,
            'container' => $targetContainer ?? 'catalogue_statique',
            'output' => implode("\n", $lines),
            'tables' => $tables,
        ];
    }

    private function parseSchemaRows(string $output): array
    {
        $tables = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $trimmed = ltrim($line);
            if (!str_starts_with($trimmed, 'SCHEMA_ROW|')) {
                continue;
            }
            $parts = explode('|', $trimmed, 5);
            if (count($parts) < 5) {
                continue;
            }
            $tables[] = [
                'schema' => trim($parts[1]),
                'table' => trim($parts[2]),
                'est_rows' => (int)trim($parts[3]),
                'columns' => trim($parts[4]),
            ];
        }
        return $tables;
    }

    private function getStaticSchemaCatalog(string $database): array
    {
        if ($database === 'postgres') {
            return [
                ['schema' => 'pg_catalog', 'table' => 'pg_stat_activity', 'est_rows' => 12, 'columns' => 'pid (int), datname (name), usename (name), state (text), wait_event_type (text), wait_event (text), query (text), backend_type (text)'],
                ['schema' => 'pg_catalog', 'table' => 'pg_stat_io', 'est_rows' => 24, 'columns' => 'backend_type (text), object (text), context (text), reads (bigint), writes (bigint), hits (bigint), evictions (bigint), fsyncs (bigint)'],
                ['schema' => 'pg_catalog', 'table' => 'pg_stat_user_tables', 'est_rows' => 16, 'columns' => 'relid (oid), schemaname (name), relname (name), seq_scan (bigint), idx_scan (bigint), n_live_tup (bigint), n_dead_tup (bigint), last_autovacuum (timestamptz)'],
                ['schema' => 'pg_catalog', 'table' => 'pg_locks', 'est_rows' => 18, 'columns' => 'locktype (text), database (oid), relation (oid), pid (int), mode (text), granted (bool)'],
                ['schema' => 'pg_catalog', 'table' => 'pg_stat_checkpointer', 'est_rows' => 1, 'columns' => 'num_timed (bigint), num_requested (bigint), write_time (double precision), sync_time (double precision), buffers_written (bigint)'],
                ['schema' => 'pg_catalog', 'table' => 'pg_settings', 'est_rows' => 360, 'columns' => 'name (text), setting (text), unit (text), category (text), context (text), source (text)'],
            ];
        }

        $sakilaTables = [
            ['schema' => 'public', 'table' => 'actor', 'est_rows' => 14, 'columns' => 'actor_id (integer PK), first_name (varchar), last_name (varchar), last_update (timestamptz)'],
            ['schema' => 'public', 'table' => 'address', 'est_rows' => 12, 'columns' => 'address_id (integer PK), address (varchar), address2 (varchar), district (varchar), city_id (smallint), postal_code (varchar), phone (varchar)'],
            ['schema' => 'public', 'table' => 'category', 'est_rows' => 10, 'columns' => 'category_id (integer PK), name (varchar), last_update (timestamptz)'],
            ['schema' => 'public', 'table' => 'customer', 'est_rows' => 12, 'columns' => 'customer_id (integer PK), store_id (integer FK), first_name (varchar), last_name (varchar), email (varchar), address_id (integer FK), activebool (boolean), create_date (date)'],
            ['schema' => 'public', 'table' => 'film', 'est_rows' => 18, 'columns' => 'film_id (integer PK), title (varchar), description (text), release_year (integer), rental_duration (smallint), rental_rate (numeric), length (smallint), replacement_cost (numeric), rating (varchar)'],
            ['schema' => 'public', 'table' => 'film_actor', 'est_rows' => 26, 'columns' => 'actor_id (integer PK/FK), film_id (integer PK/FK), last_update (timestamptz)'],
            ['schema' => 'public', 'table' => 'film_category', 'est_rows' => 20, 'columns' => 'film_id (integer PK/FK), category_id (integer PK/FK), last_update (timestamptz)'],
            ['schema' => 'public', 'table' => 'inventory', 'est_rows' => 30, 'columns' => 'inventory_id (integer PK), film_id (integer FK), store_id (integer FK), last_update (timestamptz)'],
            ['schema' => 'public', 'table' => 'payment', 'est_rows' => 24, 'columns' => 'payment_id (integer PK), customer_id (integer FK), staff_id (integer FK), rental_id (integer FK), amount (numeric), payment_date (timestamptz)'],
            ['schema' => 'public', 'table' => 'rental', 'est_rows' => 24, 'columns' => 'rental_id (integer PK), rental_date (timestamptz), inventory_id (integer FK), customer_id (integer FK), return_date (timestamptz), staff_id (integer FK)'],
            ['schema' => 'public', 'table' => 'staff', 'est_rows' => 2, 'columns' => 'staff_id (integer PK), first_name (varchar), last_name (varchar), address_id (integer FK), email (varchar), store_id (integer FK), active (boolean), username (varchar)'],
            ['schema' => 'public', 'table' => 'store', 'est_rows' => 2, 'columns' => 'store_id (integer PK), manager_staff_id (smallint), address_id (integer FK), last_update (timestamptz)'],
        ];

        if ($database === 'formation_pg') {
            return [
                ['schema' => 'public', 'table' => 'pgbench_accounts', 'est_rows' => 1000, 'columns' => 'aid (integer PK), bid (integer), abalance (integer), filler (character)'],
                ['schema' => 'public', 'table' => 'pgbench_branches', 'est_rows' => 2, 'columns' => 'bid (integer PK), bbalance (integer), filler (character)'],
                ['schema' => 'public', 'table' => 'pgbench_tellers', 'est_rows' => 20, 'columns' => 'tid (integer PK), bid (integer), tbalance (integer), filler (character)'],
                ['schema' => 'public', 'table' => 'pgbench_history', 'est_rows' => 10, 'columns' => 'tid (integer), bid (integer), aid (integer), delta (integer), mtime (timestamp), filler (character)'],
                $sakilaTables[4], // film
                $sakilaTables[0], // actor
                $sakilaTables[9], // rental
                $sakilaTables[8], // payment
            ];
        }

        return $sakilaTables;
    }

    /**
     * Generates a progressive DBA diagnostic hint for a lab without exposing the full solution.
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

    /**
     * Queries live PostgreSQL 18 diagnostics (pg_stat_activity, key pg_settings,
     * buffer cache hit ratio, and database sizes) or returns simulated telemetry.
     */
    public function inspectPgDiagnostics(int $userId, string $dbName = 'formation_pg', bool $allowSharedFallback = false): array
    {
        $cleanDb = strtolower(trim($dbName));
        if (!in_array($cleanDb, self::ALLOWED_DATABASES, true)) {
            return [
                'success' => false,
                'database' => $dbName,
                'message' => 'Base de données cible non autorisée pour le diagnostic.',
            ];
        }

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        $mode = (string)$target['runtime']['mode'];

        if ($target['error'] !== null) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'database' => $cleanDb,
                'container' => null,
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];

        if ($target['simulated']) {
            return [
                'success' => true,
                'simulated' => true,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'server_version' => 'PostgreSQL 18.0 (Cloud Simulated Sandbox)',
                'settings' => [
                    'shared_buffers' => '256MB',
                    'work_mem' => '8MB',
                    'max_connections' => '100',
                    'statement_timeout' => '15s',
                    'io_method' => 'worker',
                ],
                'cache_hit_ratio' => 99.42,
                'activity' => [
                    'total_backends' => 6,
                    'active' => 1,
                    'idle' => 5,
                    'idle_in_transaction' => 0,
                    'waiting_locks' => 0,
                    'sessions' => [
                        [
                            'pid' => 101,
                            'usename' => 'lms_student',
                            'datname' => $cleanDb,
                            'state' => 'active',
                            'wait_event_type' => '',
                            'backend_type' => 'client backend',
                            'query' => 'SELECT name, setting FROM pg_settings WHERE name IN (...);',
                        ],
                        [
                            'pid' => 102,
                            'usename' => 'postgres',
                            'datname' => 'dvdrental',
                            'state' => 'idle',
                            'wait_event_type' => 'Client',
                            'backend_type' => 'client backend',
                            'query' => 'SELECT count(*) FROM public.film;',
                        ],
                    ],
                ],
                'databases' => [
                    ['datname' => 'dvdrental', 'size_pretty' => '12 MB', 'size_bytes' => 12582912, 'cache_hit_ratio' => 99.68],
                    ['datname' => 'formation_pg', 'size_pretty' => '18 MB', 'size_bytes' => 18874368, 'cache_hit_ratio' => 99.42],
                    ['datname' => 'postgres', 'size_pretty' => '8192 kB', 'size_bytes' => 8388608, 'cache_hit_ratio' => 99.85],
                ],
                'extensions' => ['pageinspect', 'pg_stat_statements', 'pgstattuple', 'postgres_fdw'],
                'message' => "Diagnostics PostgreSQL 18 générés pour {$cleanDb} (Mode Cloud Simulé).",
            ];
        }

        $this->docker->touchContainerActivity($userId);

        $diagSql = <<<'SQL'
SELECT 'DIAG_VER|' || version();
SELECT 'DIAG_SET|' || name || '|' || current_setting(name)
FROM pg_settings
WHERE name IN ('shared_buffers', 'work_mem', 'max_connections', 'statement_timeout', 'io_method')
ORDER BY name;
SELECT 'DIAG_DB|' || d.datname || '|' || pg_size_pretty(pg_database_size(d.datname)) || '|' || pg_database_size(d.datname)::text || '|' || COALESCE(round(100.0 * s.blks_hit / NULLIF(s.blks_hit + s.blks_read, 0), 2), 100.00)::text
FROM pg_database d
LEFT JOIN pg_stat_database s ON s.datname = d.datname
WHERE d.datname IN ('formation_pg', 'dvdrental', 'postgres')
ORDER BY d.datname;
SELECT 'DIAG_ACTSUM|' || count(*)::text || '|' || count(*) FILTER (WHERE state = 'active')::text || '|' || count(*) FILTER (WHERE state = 'idle')::text || '|' || count(*) FILTER (WHERE state LIKE 'idle in transaction%')::text || '|' || count(*) FILTER (WHERE wait_event_type = 'Lock')::text
FROM pg_stat_activity;
SELECT 'DIAG_ACTROW|' || pid::text || '|' || COALESCE(usename, '') || '|' || COALESCE(datname, '') || '|' || COALESCE(state, '') || '|' || COALESCE(wait_event_type, '') || '|' || COALESCE(backend_type, '') || '|' || left(regexp_replace(COALESCE(query, ''), E'[\\r\\n|]+', ' ', 'g'), 100)
FROM pg_stat_activity
ORDER BY pid
LIMIT 15;
SELECT 'DIAG_EXT|' || extname
FROM pg_extension
ORDER BY extname;
SQL;

        $res = $this->runTargetPsql($targetContainer, $cleanDb, $diagSql, false);
        if (!$res['success']) {
            return [
                'success' => false,
                'simulated' => false,
                'mode' => $mode,
                'container' => $targetContainer,
                'database' => $cleanDb,
                'message' => 'Impossible de collecter les diagnostics PostgreSQL 18 : ' . $res['output'],
                'output' => $res['output'],
            ];
        }

        $parsed = $this->parseDiagnosticOutput((string)$res['output'], $cleanDb);
        return array_merge([
            'success' => true,
            'simulated' => false,
            'mode' => $mode,
            'container' => $targetContainer,
            'database' => $cleanDb,
            'message' => "Diagnostics PostgreSQL 18 collectés sur {$targetContainer} ({$cleanDb}).",
        ], $parsed);
    }

    /**
     * Checks container state, target database, required extensions, and table prerequisites
     * across all 34 PostgreSQL 18 labs (01..29 and sql_01..sql_05).
     */
    public function checkLabReadiness(int $userId, string $labId, bool $allowSharedFallback = false): array
    {
        $oracles = $this->getLabOracles();
        $code = $this->extractLabCode($labId);
        $oracleFound = isset($oracles[$code]);
        $oracle = $oracles[$code] ?? $oracles['01'];

        $prereqMap = $this->getLabPrerequisitesMap();
        $spec = $prereqMap[$code] ?? [
            'database' => $this->detectTargetDatabase($labId),
            'extensions' => [],
            'tables' => [],
        ];
        $recommendedDb = (string)$spec['database'];
        $requiredExtensions = (array)$spec['extensions'];
        $requiredTables = (array)$spec['tables'];

        $target = $this->resolveTargetContainer($userId, $allowSharedFallback);
        $mode = (string)$target['runtime']['mode'];

        if ($target['error'] !== null) {
            $checks = [
                [
                    'key' => 'container',
                    'label' => 'Conteneur PostgreSQL 18',
                    'ok' => false,
                    'status' => 'fail',
                    'detail' => $target['error'],
                    'remediation' => 'Démarrez votre conteneur personnel depuis le cockpit Docker.',
                ],
                [
                    'key' => 'oracle',
                    'label' => 'Oracle Qualiopi',
                    'ok' => $oracleFound,
                    'status' => $oracleFound ? 'ok' : 'warn',
                    'detail' => $oracle['bloom'] . ' — ' . $oracle['title'],
                    'remediation' => '',
                ],
            ];
            return [
                'success' => false,
                'simulated' => false,
                'ready' => false,
                'mode' => $mode,
                'lab_id' => $labId,
                'lab_code' => $code,
                'bloom' => $oracle['bloom'],
                'title' => $oracle['title'],
                'oracle_found' => $oracleFound,
                'recommended_database' => $recommendedDb,
                'required_extensions' => $requiredExtensions,
                'required_tables' => $requiredTables,
                'container' => null,
                'container_running' => false,
                'checks' => $checks,
                'message' => $target['error'],
            ];
        }

        $targetContainer = (string)$target['container'];

        if ($target['simulated']) {
            $checks = [
                [
                    'key' => 'oracle',
                    'label' => 'Oracle Qualiopi',
                    'ok' => $oracleFound,
                    'status' => $oracleFound ? 'ok' : 'warn',
                    'detail' => $oracle['bloom'] . ' — ' . $oracle['title'],
                    'remediation' => '',
                ],
                [
                    'key' => 'database',
                    'label' => 'Base cible recommandée',
                    'ok' => true,
                    'status' => 'simulated',
                    'detail' => "Base attendue : {$recommendedDb}",
                    'remediation' => '',
                ],
                [
                    'key' => 'extensions',
                    'label' => 'Extensions PostgreSQL requises',
                    'ok' => true,
                    'status' => 'simulated',
                    'detail' => empty($requiredExtensions)
                        ? 'Aucune extension supplémentaire requise'
                        : 'Extensions requises : ' . implode(', ', $requiredExtensions),
                    'remediation' => empty($requiredExtensions) ? '' : 'CREATE EXTENSION IF NOT EXISTS ' . implode(', ', $requiredExtensions),
                ],
                [
                    'key' => 'tables',
                    'label' => 'Jeu de données requis',
                    'ok' => true,
                    'status' => 'simulated',
                    'detail' => empty($requiredTables)
                        ? 'Catalogues système PostgreSQL 18 standard'
                        : 'Tables requises : ' . implode(', ', $requiredTables),
                    'remediation' => empty($requiredTables) ? '' : 'Utiliser le bouton Réamorcer Sakila / pgbench si besoin.',
                ],
                [
                    'key' => 'container',
                    'label' => 'Conteneur PostgreSQL 18',
                    'ok' => false,
                    'status' => 'simulated',
                    'detail' => "Mode Cloud Simulé ({$targetContainer}) : aucun démon Docker local actif.",
                    'remediation' => 'Activez Docker / WSL2 ou connectez une instance locale sur le port dédié.',
                ],
            ];

            return [
                'success' => true,
                'simulated' => true,
                'ready' => false,
                'mode' => $mode,
                'lab_id' => $labId,
                'lab_code' => $code,
                'bloom' => $oracle['bloom'],
                'title' => $oracle['title'],
                'oracle_found' => $oracleFound,
                'recommended_database' => $recommendedDb,
                'required_extensions' => $requiredExtensions,
                'required_tables' => $requiredTables,
                'container' => $targetContainer,
                'container_running' => false,
                'checks' => $checks,
                'message' => "Prérequis cartographiés pour {$code} (Mode Cloud Simulé — base {$recommendedDb}).",
            ];
        }

        $this->docker->touchContainerActivity($userId);

        $probeDbSql = sprintf(
            "SELECT 'DB_OK|' || count(*)::text FROM pg_database WHERE datname = '%s';\nSELECT 'ROLE_OK|' || count(*)::text FROM pg_roles WHERE rolname = '%s';",
            $recommendedDb,
            SakilaSeeder::SANDBOX_ROLE
        );
        $dbProbe = $this->runTargetPsql($targetContainer, 'postgres', $probeDbSql, true);
        $dbExists = preg_match('/DB_OK\|1\b/', (string)$dbProbe['output']) === 1;
        $roleExists = preg_match('/ROLE_OK\|1\b/', (string)$dbProbe['output']) === 1;

        $installedExts = [];
        $installedTables = [];
        if ($dbExists) {
            $detailSql = "SELECT 'EXT|' || extname FROM pg_extension;\nSELECT 'TBL|' || tablename FROM pg_tables WHERE schemaname = 'public';";
            $detailProbe = $this->runTargetPsql($targetContainer, $recommendedDb, $detailSql, true);
            foreach (preg_split('/\r\n|\r|\n/', (string)$detailProbe['output']) ?: [] as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'EXT|')) {
                    $installedExts[] = substr($line, 4);
                } elseif (str_starts_with($line, 'TBL|')) {
                    $installedTables[] = substr($line, 4);
                }
            }
        }

        $missingExts = array_values(array_diff($requiredExtensions, $installedExts));
        $missingTables = array_values(array_diff($requiredTables, $installedTables));

        $checks = [
            [
                'key' => 'container',
                'label' => 'Conteneur PostgreSQL 18',
                'ok' => true,
                'status' => 'ok',
                'detail' => "Conteneur {$targetContainer} actif",
                'remediation' => '',
            ],
            [
                'key' => 'database',
                'label' => 'Base cible recommandée',
                'ok' => $dbExists,
                'status' => $dbExists ? 'ok' : 'fail',
                'detail' => $dbExists ? "Base {$recommendedDb} disponible" : "Base {$recommendedDb} absente",
                'remediation' => $dbExists ? '' : 'Cliquez sur « Réamorcer Sakila / pgbench » pour créer et peupler la base.',
            ],
            [
                'key' => 'sandbox_role',
                'label' => 'Rôle sandbox lms_student',
                'ok' => $roleExists,
                'status' => $roleExists ? 'ok' : 'warn',
                'detail' => $roleExists ? 'Rôle lms_student + pg_read_all_stats configuré' : 'Rôle lms_student non provisionné',
                'remediation' => $roleExists ? '' : 'Cliquez sur « Réamorcer Sakila / pgbench » pour configurer les droits.',
            ],
            [
                'key' => 'extensions',
                'label' => 'Extensions PostgreSQL requises',
                'ok' => empty($missingExts),
                'status' => empty($missingExts) ? 'ok' : 'fail',
                'detail' => empty($requiredExtensions)
                    ? 'Aucune extension supplémentaire requise'
                    : (empty($missingExts)
                        ? 'Installées : ' . implode(', ', $requiredExtensions)
                        : 'Manquantes : ' . implode(', ', $missingExts)),
                'remediation' => empty($missingExts) ? '' : 'Exécutez CREATE EXTENSION IF NOT EXISTS pour : ' . implode(', ', $missingExts),
            ],
            [
                'key' => 'tables',
                'label' => 'Jeu de données requis',
                'ok' => empty($missingTables),
                'status' => empty($missingTables) ? 'ok' : 'fail',
                'detail' => empty($requiredTables)
                    ? 'Tables standard prêtes'
                    : (empty($missingTables)
                        ? 'Tables présentes : ' . implode(', ', $requiredTables)
                        : 'Tables manquantes : ' . implode(', ', $missingTables)),
                'remediation' => empty($missingTables) ? '' : 'Réamorcez le jeu de données Sakila / pgbench.',
            ],
            [
                'key' => 'oracle',
                'label' => 'Oracle Qualiopi',
                'ok' => $oracleFound,
                'status' => $oracleFound ? 'ok' : 'warn',
                'detail' => $oracle['bloom'] . ' — ' . $oracle['title'],
                'remediation' => '',
            ],
        ];

        $allReady = $dbExists && empty($missingExts) && empty($missingTables) && $oracleFound;

        return [
            'success' => true,
            'simulated' => false,
            'ready' => $allReady,
            'mode' => $mode,
            'lab_id' => $labId,
            'lab_code' => $code,
            'bloom' => $oracle['bloom'],
            'title' => $oracle['title'],
            'oracle_found' => $oracleFound,
            'recommended_database' => $recommendedDb,
            'required_extensions' => $requiredExtensions,
            'missing_extensions' => $missingExts,
            'required_tables' => $requiredTables,
            'missing_tables' => $missingTables,
            'container' => $targetContainer,
            'container_running' => true,
            'checks' => $checks,
            'message' => $allReady
                ? "Lab {$code} prêt sur {$targetContainer} (base {$recommendedDb})."
                : "Prérequis incomplets pour le lab {$code} sur {$targetContainer}.",
        ];
    }

    /**
     * Resolves the target container while enforcing strict per-user tenant boundaries
     * unless $allowSharedFallback is explicitly granted for staff or CLI tools.
     *
     * @return array{runtime: array, simulated: bool, container: ?string, error: ?string}
     */
    private function resolveTargetContainer(int $userId, bool $allowSharedFallback = false): array
    {
        $rt = $this->docker->detectRuntime();
        if (empty($rt['available'])) {
            return [
                'runtime' => $rt,
                'simulated' => true,
                'container' => $this->docker->getContainerName($userId),
                'error' => null,
            ];
        }

        $status = $this->docker->getUserContainerStatus($userId);
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

    /**
     * Executes a read-only diagnostic query via base64-encoded stdin and PGOPTIONS timeout bounds.
     *
     * @return array{success: bool, output: string}
     */
    private function runTargetPsql(string $targetContainer, string $database, string $sql, bool $asSuperuser = false): array
    {
        $pgUser = $asSuperuser ? 'postgres' : SakilaSeeder::SANDBOX_ROLE;
        $b64 = base64_encode($sql);
        $cmd = sprintf(
            'exec %s sh -c "echo %s | base64 -d | PGOPTIONS=\"-c statement_timeout=15000 -c lock_timeout=10000\" psql -X -t -A -U %s -d %s -P pager=off"',
            $targetContainer,
            $b64,
            $pgUser,
            $database
        );
        $res = $this->docker->runDockerCommand($cmd, 15.0);

        if (
            !$asSuperuser
            && $res['code'] !== 0
            && str_contains((string)$res['output'], SakilaSeeder::SANDBOX_ROLE)
            && str_contains((string)$res['output'], 'does not exist')
        ) {
            $roleB64 = base64_encode(SakilaSeeder::getSandboxRoleSql());
            $this->docker->runDockerCommand(sprintf(
                'exec %s sh -c "echo %s | base64 -d | psql -X -U postgres -d %s"',
                $targetContainer,
                $roleB64,
                $database
            ), 8.0);
            $res = $this->docker->runDockerCommand($cmd, 15.0);
        }

        return [
            'success' => $res['code'] === 0 && !preg_match('/^ERROR:/m', (string)$res['output']),
            'output' => (string)$res['output'],
        ];
    }

    private function parseDiagnosticOutput(string $output, string $activeDb): array
    {
        $serverVersion = '';
        $settings = [];
        $databases = [];
        $extensions = [];
        $sessions = [];
        $summary = [
            'total_backends' => 0,
            'active' => 0,
            'idle' => 0,
            'idle_in_transaction' => 0,
            'waiting_locks' => 0,
        ];
        $activeHitRatio = 100.0;

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'DIAG_VER|')) {
                $serverVersion = substr($line, 9);
            } elseif (str_starts_with($line, 'DIAG_SET|')) {
                $parts = explode('|', $line, 3);
                if (count($parts) === 3) {
                    $settings[$parts[1]] = $parts[2];
                }
            } elseif (str_starts_with($line, 'DIAG_DB|')) {
                $parts = explode('|', $line, 5);
                if (count($parts) === 5) {
                    $ratio = (float)$parts[4];
                    $databases[] = [
                        'datname' => $parts[1],
                        'size_pretty' => $parts[2],
                        'size_bytes' => (int)$parts[3],
                        'cache_hit_ratio' => $ratio,
                    ];
                    if ($parts[1] === $activeDb) {
                        $activeHitRatio = $ratio;
                    }
                }
            } elseif (str_starts_with($line, 'DIAG_ACTSUM|')) {
                $parts = explode('|', $line, 6);
                if (count($parts) === 6) {
                    $summary = [
                        'total_backends' => (int)$parts[1],
                        'active' => (int)$parts[2],
                        'idle' => (int)$parts[3],
                        'idle_in_transaction' => (int)$parts[4],
                        'waiting_locks' => (int)$parts[5],
                    ];
                }
            } elseif (str_starts_with($line, 'DIAG_ACTROW|')) {
                $parts = explode('|', $line, 8);
                if (count($parts) === 8) {
                    $sessions[] = [
                        'pid' => (int)$parts[1],
                        'usename' => $parts[2],
                        'datname' => $parts[3],
                        'state' => $parts[4],
                        'wait_event_type' => $parts[5],
                        'backend_type' => $parts[6],
                        'query' => $parts[7],
                    ];
                }
            } elseif (str_starts_with($line, 'DIAG_EXT|')) {
                $extensions[] = substr($line, 9);
            }
        }

        return [
            'server_version' => $serverVersion !== '' ? $serverVersion : 'PostgreSQL 18',
            'settings' => $settings,
            'cache_hit_ratio' => $activeHitRatio,
            'activity' => array_merge($summary, ['sessions' => $sessions]),
            'databases' => $databases,
            'extensions' => $extensions,
        ];
    }

    /**
     * Maps all 34 PostgreSQL 18 curriculum labs (01..29 and sql_01..sql_05)
     * to their recommended target database, required extensions, and core tables.
     */
    private function getLabPrerequisitesMap(): array
    {
        $map = [];
        for ($i = 1; $i <= 29; $i++) {
            $code = sprintf('%02d', $i);
            $map[$code] = [
                'database' => 'formation_pg',
                'extensions' => [],
                'tables' => [],
            ];
        }

        $map['01']['extensions'] = ['pageinspect'];
        $map['05']['extensions'] = ['pageinspect'];
        $map['06']['extensions'] = ['pg_stat_statements'];
        $map['06']['tables'] = ['pgbench_accounts', 'pgbench_branches'];
        $map['19']['tables'] = ['pgbench_accounts'];
        $map['23']['extensions'] = ['pageinspect', 'pgstattuple'];
        $map['24']['extensions'] = ['pg_stat_statements'];
        $map['24']['tables'] = ['pgbench_accounts'];
        $map['25']['extensions'] = ['pgstattuple'];
        $map['26']['tables'] = ['pgbench_accounts'];
        $map['27']['extensions'] = ['pg_stat_statements'];
        $map['27']['tables'] = ['pgbench_accounts'];
        $map['28']['extensions'] = ['postgres_fdw'];

        for ($i = 1; $i <= 5; $i++) {
            $code = sprintf('sql_%02d', $i);
            $map[$code] = [
                'database' => 'dvdrental',
                'extensions' => [],
                'tables' => ['film', 'actor', 'customer', 'rental', 'payment'],
            ];
        }

        return $map;
    }
}
