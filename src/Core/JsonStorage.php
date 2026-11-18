<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Atomic JSON flat-file storage engine (zero-config).
 * Runs on any PHP host without external DB, using flock(LOCK_EX) and atomic temp-file replacement.
 */
class JsonStorage implements StorageInterface
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0775, true);
        }
        $this->ensureDefaultUsers();
    }

    protected function readFile(string $name, array $default = []): array
    {
        $path = $this->dataDir . '/' . $name . '.json';
        if (!is_file($path)) {
            return $default;
        }
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return $default;
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    protected function writeFile(string $name, array $data): void
    {
        $path = $this->dataDir . '/' . $name . '.json';
        $tmpPath = $path . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $json === '') {
            throw new \RuntimeException("JSON encoding failed for file '$name'");
        }
        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary file for '$name'");
        }

        // Retry rename briefly to tolerate transient Windows lock contention (Defender/indexers).
        $renamed = false;
        $delaysUs = [5000, 15000, 35000];
        for ($attempt = 0; $attempt <= count($delaysUs); $attempt++) {
            if (@rename($tmpPath, $path)) {
                $renamed = true;
                break;
            }
            if ($attempt < count($delaysUs)) {
                usleep($delaysUs[$attempt]);
            }
        }
        if (!$renamed) {
            if (!@copy($tmpPath, $path)) {
                @unlink($tmpPath);
                throw new \RuntimeException("Unable to persist JSON file '$name'");
            }
            @unlink($tmpPath);
        }
    }

    /**
     * Executes an atomic read-modify-write cycle under an exclusive .lock file
     * to prevent check-then-write race conditions across concurrent classroom sessions.
     */
    protected function mutateFile(string $name, array $default, callable $mutator): mixed
    {
        $lockPath = $this->dataDir . '/' . $name . '.lock';
        $lockFp = @fopen($lockPath, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $path = $this->dataDir . '/' . $name . '.json';
            $targetHasData = is_file($path) && (int)@filesize($path) > 0;
            $current = $this->readFile($name, $default);
            if ($targetHasData && $current === $default) {
                $rawCheck = @file_get_contents($path);
                $trimmed = trim((string)$rawCheck);
                if ($trimmed !== '[]' && $trimmed !== '{}') {
                    throw new \RuntimeException("Données corrompues ou illisibles détectées pour '$name'. Écriture interrompue pour éviter l'écrasement.");
                }
            }
            [$updated, $result] = $mutator($current);
            if ($updated !== null) {
                $this->writeFile($name, $updated);
            }
            return $result;
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }

    private function ensureDefaultUsers(): void
    {
        $users = $this->readFile('users', []);
        if (!empty($users)) {
            return;
        }

        $adminPw = (string)(getenv('LMS_INIT_ADMIN_PASSWORD') ?: '');
        $trainerPw = (string)(getenv('LMS_INIT_TRAINER_PASSWORD') ?: '');
        $studentPw = (string)(getenv('LMS_INIT_STUDENT_PASSWORD') ?: '');

        $generatedBootstrap = false;
        $generatedCreds = [];
        if ($adminPw === '') {
            $adminPw = bin2hex(random_bytes(12));
            $generatedBootstrap = true;
            $generatedCreds[] = "admin   : jmrenouard@lightpath.fr / {$adminPw}";
        }
        if ($trainerPw === '') {
            $trainerPw = bin2hex(random_bytes(12));
            $generatedBootstrap = true;
            $generatedCreds[] = "trainer : formateur@lightpath.fr / {$trainerPw}";
        }
        if ($studentPw === '') {
            $studentPw = bin2hex(random_bytes(12));
            $generatedBootstrap = true;
            $generatedCreds[] = "student : stagiaire@lightpath.fr / {$studentPw}";
        }

        $now = date('Y-m-d H:i:s');
        $defaults = [
            [
                'id' => 1,
                'name' => 'Jean-Marie Renouard',
                'email' => 'jmrenouard@lightpath.fr',
                'password_hash' => password_hash($adminPw, PASSWORD_BCRYPT),
                'role' => 'admin',
                'company' => 'LightPath SARL',
                'active' => true,
                'enrolled_courses' => ['postgresql-18', 'git-basics'],
                'created_at' => $now,
                'last_login' => $now,
            ],
            [
                'id' => 2,
                'name' => 'Formateur LightPath',
                'email' => 'formateur@lightpath.fr',
                'password_hash' => password_hash($trainerPw, PASSWORD_BCRYPT),
                'role' => 'trainer',
                'company' => 'LightPath SARL',
                'active' => true,
                'enrolled_courses' => ['postgresql-18', 'git-basics'],
                'created_at' => $now,
                'last_login' => $now,
            ],
            [
                'id' => 3,
                'name' => 'Thomas Dubois (Stagiaire DBA)',
                'email' => 'stagiaire@lightpath.fr',
                'password_hash' => password_hash($studentPw, PASSWORD_BCRYPT),
                'role' => 'student',
                'company' => 'Client Entreprise',
                'active' => true,
                'enrolled_courses' => ['postgresql-18', 'git-basics'],
                'created_at' => $now,
                'last_login' => $now,
            ],
        ];

        $this->writeFile('users', $defaults);

        if ($generatedBootstrap) {
            $credFile = $this->dataDir . '/initial_admin_credentials.txt';
            $banner = "# LightPath Training Portal - Initial Generated Credentials\n"
                . "# Generated at: {$now}\n"
                . "# Rotate these passwords or delete this file after first sign-in.\n\n"
                . implode("\n", $generatedCreds) . "\n";
            if (!is_file($credFile)) {
                @touch($credFile);
            }
            @chmod($credFile, 0600);
            @file_put_contents($credFile, $banner, LOCK_EX);
            if (PHP_SAPI === 'cli-server') {
                error_log('[LightPath LMS] Generated initial credentials in ' . $credFile);
            }
        }

        // Seed realistic demo progress for student #3
        $this->markItemCompleted(3, 'postgresql-18', 'module', 'module_01_introduction_postgresql_18', true);
        $this->markItemCompleted(3, 'postgresql-18', 'module', 'module_02_getting_started_postgresql', true);
        $this->markItemCompleted(3, 'postgresql-18', 'lab', 'lab_01_introduction_setup_postgresql_18', true);
        $this->saveQuizScore(3, 'postgresql-18', 'module_01_introduction_postgresql_18', 3, 3);
    }

    public function getUsers(): array
    {
        return $this->readFile('users', []);
    }

    public function getUserById(int $id): ?array
    {
        foreach ($this->getUsers() as $u) {
            if ((int)($u['id'] ?? 0) === $id) {
                return $u;
            }
        }
        return null;
    }

    public function getUserByEmail(string $email): ?array
    {
        $norm = strtolower(trim($email));
        foreach ($this->getUsers() as $u) {
            if (strtolower(trim((string)($u['email'] ?? ''))) === $norm) {
                return $u;
            }
        }
        return null;
    }

    public function saveUser(array $user): array
    {
        return (array)$this->mutateFile('users', [], static function (array $users) use ($user): array {
            $id = isset($user['id']) ? (int)$user['id'] : 0;
            if ($id > 0) {
                foreach ($users as $idx => $existing) {
                    if ((int)$existing['id'] === $id) {
                        $users[$idx] = array_merge($existing, $user);
                        return [$users, $users[$idx]];
                    }
                }
            }

            $maxId = 0;
            foreach ($users as $existing) {
                if ((int)$existing['id'] > $maxId) {
                    $maxId = (int)$existing['id'];
                }
            }
            $user['id'] = $maxId + 1;
            $user['created_at'] = $user['created_at'] ?? date('Y-m-d H:i:s');
            $user['active'] = $user['active'] ?? true;
            $user['enrolled_courses'] = $user['enrolled_courses'] ?? ['postgresql-18'];
            $users[] = $user;

            return [$users, $user];
        });
    }

    public function deleteUser(int $id): bool
    {
        if ($id === 1) {
            return false; // Protect primary super-admin account
        }
        return (bool)$this->mutateFile('users', [], static function (array $users) use ($id): array {
            $filtered = array_values(array_filter($users, static fn(array $u): bool => (int)$u['id'] !== $id));
            if (count($filtered) === count($users)) {
                return [null, false];
            }
            return [$filtered, true];
        });
    }

    public function setUserActive(int $userId, bool $active): bool
    {
        if ($userId <= 0 || ($userId === 1 && !$active)) {
            return false; // Protect primary super-admin account from deactivation
        }
        return (bool)$this->mutateFile('users', [], static function (array $users) use ($userId, $active): array {
            foreach ($users as $idx => $existing) {
                if ((int)($existing['id'] ?? 0) === $userId) {
                    $users[$idx]['active'] = $active;
                    return [$users, true];
                }
            }
            return [null, false];
        });
    }

    public function adminResetUserPassword(int $userId, string $passwordHash): bool
    {
        if ($userId <= 0 || trim($passwordHash) === '') {
            return false;
        }
        return (bool)$this->mutateFile('users', [], static function (array $users) use ($userId, $passwordHash): array {
            foreach ($users as $idx => $existing) {
                if ((int)($existing['id'] ?? 0) === $userId) {
                    $users[$idx]['password_hash'] = $passwordHash;
                    $users[$idx]['password_updated_at'] = date('Y-m-d H:i:s');
                    return [$users, true];
                }
            }
            return [null, false];
        });
    }

    public function getAllProgress(): array
    {
        return $this->readFile('progress', []);
    }

    protected function defaultUserProgress(int $userId, string $courseSlug): array
    {
        return [
            'user_id' => $userId,
            'course_slug' => $courseSlug,
            'completed_modules' => [],
            'completed_labs' => [],
            'quiz_scores' => [],
            'notes' => [],
            'updated_at' => null,
        ];
    }

    public function getUserProgress(int $userId, string $courseSlug): array
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        return $all[$key] ?? $this->defaultUserProgress($userId, $courseSlug);
    }

    public function markItemCompleted(int $userId, string $courseSlug, string $itemType, string $itemId, bool $completed = true): void
    {
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);
        $this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $itemType, $itemId, $completed): array {
            $prog = $all[$key] ?? $defaultProg;
            $field = ($itemType === 'lab') ? 'completed_labs' : 'completed_modules';
            $list = $prog[$field] ?? [];

            if ($completed) {
                if (!in_array($itemId, $list, true)) {
                    $list[] = $itemId;
                }
            } else {
                $list = array_values(array_filter($list, static fn(string $v): bool => $v !== $itemId));
            }

            $prog[$field] = $list;
            $prog['updated_at'] = date('Y-m-d H:i:s');
            $all[$key] = $prog;
            return [$all, null];
        });
    }

    public function saveQuizScore(int $userId, string $courseSlug, string $moduleId, int $score, int $total): void
    {
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);
        $this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $moduleId, $score, $total): array {
            $prog = $all[$key] ?? $defaultProg;
            $pct = $total > 0 ? (int)round(($score / $total) * 100) : 0;
            $prog['quiz_scores'][$moduleId] = [
                'score' => $score,
                'total' => $total,
                'percentage' => $pct,
                'passed' => $pct >= 66,
                'date' => date('Y-m-d H:i:s'),
            ];

            if ($pct >= 66 && !in_array($moduleId, $prog['completed_modules'], true)) {
                $prog['completed_modules'][] = $moduleId;
            }

            $prog['updated_at'] = date('Y-m-d H:i:s');
            $all[$key] = $prog;
            return [$all, null];
        });
    }

    public function saveUserNote(int $userId, string $courseSlug, string $itemId, string $note): void
    {
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);
        $this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $itemId, $note): array {
            $prog = $all[$key] ?? $defaultProg;
            $prog['notes'][$itemId] = trim($note);
            $prog['updated_at'] = date('Y-m-d H:i:s');
            $all[$key] = $prog;
            return [$all, null];
        });
    }

    public function getCustomCourses(): array
    {
        return $this->readFile('custom_courses', []);
    }

    public function saveCustomCourse(array $course): void
    {
        $this->mutateFile('custom_courses', [], static function (array $courses) use ($course): array {
            $slug = (string)$course['slug'];
            $courses[$slug] = $course;
            return [$courses, null];
        });
    }

    /**
     * Computes a deterministic SHA-256 hash over an audit entry payload (excluding entry_hash).
     */
    protected static function computeAuditEntryHash(array $entry): string
    {
        $payload = $entry;
        unset($payload['entry_hash']);
        ksort($payload, SORT_STRING);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = serialize($payload);
        }
        return hash('sha256', $encoded);
    }

    public function logAuditEvent(string $actor, string $action, string $details): void
    {
        $genesisHash = str_repeat('0', 64);

        $this->mutateFile('audit_logs', [], function (array $logs) use ($actor, $action, $details, $genesisHash): array {
            $prevHash = $genesisHash;
            if (!empty($logs)) {
                $headHash = is_array($logs[0]) ? ($logs[0]['entry_hash'] ?? null) : null;
                if (is_string($headHash) && $headHash !== '') {
                    $prevHash = $headHash;
                }
            } else {
                $archiveKeys = $this->listAuditArchiveKeys();
                if (!empty($archiveKeys)) {
                    $latestArchive = $this->readFile($archiveKeys[0], []);
                    if (!empty($latestArchive)) {
                        $lastArchived = end($latestArchive);
                        if (is_array($lastArchived) && is_string($lastArchived['entry_hash'] ?? null) && $lastArchived['entry_hash'] !== '') {
                            $prevHash = $lastArchived['entry_hash'];
                        }
                    }
                }
            }

            $entry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'actor' => $actor,
                'action' => $action,
                'details' => $details,
                'prev_hash' => $prevHash,
            ];
            $entry['entry_hash'] = self::computeAuditEntryHash($entry);

            array_unshift($logs, $entry);

            // Archive evicted audit entries atomically into monthly files instead of dropping them.
            if (count($logs) > 1000) {
                $overflow = array_reverse(array_slice($logs, 1000));
                $logs = array_slice($logs, 0, 1000);

                $byMonth = [];
                foreach ($overflow as $evicted) {
                    $ts = (string)($evicted['timestamp'] ?? '');
                    $ym = preg_match('/^(\d{4})-(\d{2})/', $ts, $m) ? ($m[1] . $m[2]) : date('Ym');
                    $byMonth[$ym][] = $evicted;
                }
                foreach ($byMonth as $ym => $entries) {
                    $this->mutateFile('audit_logs_' . $ym, [], static function (array $existing) use ($entries): array {
                        foreach ($entries as $archivedEntry) {
                            $existing[] = $archivedEntry;
                        }
                        return [$existing, null];
                    });
                }
            }

            return [$logs, null];
        });
    }

    /**
     * Builds a structured break descriptor for verifyAuditIntegrity() violation reports.
     */
    private static function buildAuditBreakRecord(
        array $item,
        array $entry,
        string $reason,
        string $expectedPrevHash,
        string $actualPrevHash
    ): array {
        return [
            'index' => $item['index'],
            'source' => $item['source'],
            'timestamp' => (string)($entry['timestamp'] ?? ''),
            'actor' => (string)($entry['actor'] ?? ''),
            'action' => (string)($entry['action'] ?? ''),
            'reason' => $reason,
            'expected_prev_hash' => $expectedPrevHash,
            'actual_prev_hash' => $actualPrevHash,
        ];
    }

    /**
     * Verifies the cryptographic SHA-256 hash chain across active audit logs and optional monthly archives.
     * Pre-chain legacy entries without hash fields are tolerated and counted separately.
     */
    public function verifyAuditIntegrity(bool $includeArchives = false): array
    {
        $activeNewestFirst = $this->readFile('audit_logs', []);
        $archiveKeys = $this->listAuditArchiveKeys();

        $entries = [];
        if ($includeArchives && !empty($archiveKeys)) {
            $ascArchives = $archiveKeys;
            sort($ascArchives, SORT_STRING);
            foreach ($ascArchives as $archiveKey) {
                $archivedList = $this->readFile($archiveKey, []);
                foreach ($archivedList as $idx => $entry) {
                    if (is_array($entry)) {
                        $entries[] = ['source' => $archiveKey, 'index' => $idx, 'entry' => $entry];
                    }
                }
            }
        }

        // Active audit logs are stored newest-first; reverse to verify chronologically oldest -> newest.
        $activeOldestFirst = array_reverse($activeNewestFirst);
        foreach ($activeOldestFirst as $idx => $entry) {
            if (is_array($entry)) {
                $entries[] = ['source' => 'audit_logs', 'index' => $idx, 'entry' => $entry];
            }
        }

        $genesisHash = str_repeat('0', 64);
        $totalChecked = 0;
        $chainedEntries = 0;
        $legacyUnchained = 0;
        $brokenLinks = [];
        $expectedPrevHash = null;
        $seenChained = false;

        // Only allow a non-genesis starting prev_hash when checking active-only with rotated archives.
        $mayStartMidChain = !$includeArchives && (!empty($archiveKeys) || count($activeNewestFirst) >= 1000);

        foreach ($entries as $item) {
            $entry = $item['entry'];
            $totalChecked++;

            $hasEntryHash = array_key_exists('entry_hash', $entry);
            $hasPrevHash = array_key_exists('prev_hash', $entry);

            if (!$hasEntryHash && !$hasPrevHash) {
                if ($seenChained) {
                    $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'unchained_entry_after_chain_start', (string)$expectedPrevHash, '');
                } else {
                    $legacyUnchained++;
                }
                continue;
            }

            $chainedEntries++;
            $seenChained = true;

            $actualPrevHash = is_string($entry['prev_hash'] ?? null) ? (string)$entry['prev_hash'] : '';
            $actualEntryHash = is_string($entry['entry_hash'] ?? null) ? (string)$entry['entry_hash'] : '';

            if (!$hasEntryHash || !$hasPrevHash || $actualEntryHash === '' || $actualPrevHash === '') {
                $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'missing_hash_fields', (string)($expectedPrevHash ?? $genesisHash), $actualPrevHash);
                $expectedPrevHash = $actualEntryHash !== '' ? $actualEntryHash : self::computeAuditEntryHash($entry);
                continue;
            }

            $computedHash = self::computeAuditEntryHash($entry);
            if (!hash_equals($computedHash, $actualEntryHash)) {
                $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'entry_hash_mismatch', (string)($expectedPrevHash ?? $genesisHash), $actualPrevHash);
            }

            if ($expectedPrevHash === null) {
                if (!$mayStartMidChain && !hash_equals($genesisHash, $actualPrevHash)) {
                    $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'invalid_genesis_prev_hash', $genesisHash, $actualPrevHash);
                } elseif ($mayStartMidChain && !preg_match('/^[a-f0-9]{64}$/', $actualPrevHash)) {
                    $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'malformed_prev_hash', 'sha256_hex_64', $actualPrevHash);
                }
            } elseif (!hash_equals($expectedPrevHash, $actualPrevHash)) {
                $brokenLinks[] = self::buildAuditBreakRecord($item, $entry, 'prev_hash_mismatch', $expectedPrevHash, $actualPrevHash);
            }

            $expectedPrevHash = preg_match('/^[a-f0-9]{64}$/', $actualEntryHash) ? $actualEntryHash : $computedHash;
        }

        return [
            'valid' => empty($brokenLinks),
            'total_checked' => $totalChecked,
            'chained_entries' => $chainedEntries,
            'legacy_unchained' => $legacyUnchained,
            'broken_links' => $brokenLinks,
        ];
    }

    protected function listAuditArchiveKeys(): array
    {
        $files = glob($this->dataDir . '/audit_logs_[0-9][0-9][0-9][0-9][0-9][0-9].json') ?: [];
        $keys = [];
        foreach ($files as $file) {
            $keys[] = basename($file, '.json');
        }
        rsort($keys, SORT_STRING);
        return $keys;
    }

    public function getAuditLogs(int $limit = 50, bool $includeArchives = false): array
    {
        $max = max(0, $limit);
        $logs = $this->readFile('audit_logs', []);
        if ($includeArchives && count($logs) < $max) {
            foreach ($this->listAuditArchiveKeys() as $archiveKey) {
                $entries = $this->readFile($archiveKey, []);
                if (empty($entries)) {
                    continue;
                }
                // Evicted records are appended chronologically; reverse before stable timestamp sort so newest-first order holds even within the same second.
                $entries = array_reverse($entries);
                usort($entries, static fn(array $a, array $b): int => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));
                array_push($logs, ...$entries);
                if (count($logs) >= $max) {
                    break;
                }
            }
        }
        return array_slice($logs, 0, $max);
    }

    // =========================================================================
    // Enterprise LMS & Qualiopi Storage Methods
    // =========================================================================

    public function saveDiagnosticResult(int $userId, string $courseSlug, array $result): void
    {
        $key = $userId . ':' . $courseSlug;
        $this->mutateFile('diagnostics', [], static function (array $all) use ($key, $userId, $courseSlug, $result): array {
            $result['user_id'] = $userId;
            $result['course_slug'] = $courseSlug;
            $result['completed_at'] = date('Y-m-d H:i:s');
            $all[$key] = $result;
            return [$all, null];
        });
    }

    public function getDiagnosticResult(int $userId, string $courseSlug = 'postgresql-18'): ?array
    {
        $all = $this->readFile('diagnostics', []);
        $key = $userId . ':' . $courseSlug;
        return $all[$key] ?? null;
    }

    public function getAllDiagnostics(): array
    {
        return $this->readFile('diagnostics', []);
    }

    public function saveExamAttempt(int $userId, string $courseSlug, array $attempt): array
    {
        $key = $userId . ':' . $courseSlug;
        return (array)$this->mutateFile('exams', [], static function (array $all) use ($key, $userId, $courseSlug, $attempt): array {
            $attempt['user_id'] = $userId;
            $attempt['course_slug'] = $courseSlug;
            $attempt['submitted_at'] = date('Y-m-d H:i:s');
            if (empty($attempt['certificate_id'])) {
                $prefix = $courseSlug === 'postgresql-18'
                    ? 'PG18'
                    : (strtoupper(substr((string)preg_replace('/[^A-Z0-9]/i', '', $courseSlug), 0, 6)) ?: 'COURSE');
                $attempt['certificate_id'] = sprintf(
                    'LP-%s-CERT-%d-%s',
                    $prefix,
                    $userId,
                    strtoupper(bin2hex(random_bytes(4)))
                );
            }
            $history = $all[$key] ?? [];
            array_unshift($history, $attempt);
            $all[$key] = array_slice($history, 0, 20);
            return [$all, $attempt];
        });
    }

    public function getExamAttempts(int $userId, string $courseSlug = 'postgresql-18'): array
    {
        $all = $this->readFile('exams', []);
        $key = $userId . ':' . $courseSlug;
        return $all[$key] ?? [];
    }

    public function getBestExamAttempt(int $userId, string $courseSlug = 'postgresql-18'): ?array
    {
        $attempts = $this->getExamAttempts($userId, $courseSlug);
        if (empty($attempts)) {
            return null;
        }
        $best = $attempts[0];
        foreach ($attempts as $a) {
            $aPct = (float)($a['percent'] ?? $a['percentage'] ?? 0);
            $bestPct = (float)($best['percent'] ?? $best['percentage'] ?? 0);
            if ($aPct > $bestPct) {
                $best = $a;
            }
        }
        return $best;
    }

    public function getAllExamAttempts(): array
    {
        return $this->readFile('exams', []);
    }

    public function recordLearningTime(int $userId, string $courseSlug, int $seconds = 60, string $itemId = ''): int
    {
        $seconds = max(1, min(600, $seconds));
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);

        return (int)$this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $seconds, $itemId): array {
            $prog = $all[$key] ?? $defaultProg;
            $newTotal = (int)($prog['learning_seconds'] ?? $prog['time_spent_seconds'] ?? 0) + $seconds;
            $prog['learning_seconds'] = $newTotal;
            $prog['time_spent_seconds'] = $newTotal;
            if (!isset($prog['item_time_seconds']) || !is_array($prog['item_time_seconds'])) {
                $prog['item_time_seconds'] = [];
            }
            if ($itemId !== '') {
                $prog['item_time_seconds'][$itemId] = (int)($prog['item_time_seconds'][$itemId] ?? 0) + $seconds;
            }
            $prog['updated_at'] = date('Y-m-d H:i:s');
            $all[$key] = $prog;
            return [$all, $newTotal];
        });
    }

    public function signAttendance(int $userId, string $courseSlug, string $halfDayKey, string $signatureText = '', string $ip = '127.0.0.1'): array
    {
        if (!Security::isValidHalfDayKey($halfDayKey)) {
            return $this->getUserAttendance($userId, $courseSlug);
        }

        $key = $userId . ':' . $courseSlug;
        return (array)$this->mutateFile('attendance', [], static function (array $all) use ($key, $userId, $courseSlug, $halfDayKey, $signatureText, $ip): array {
            $userRecords = $all[$key] ?? [];
            $existing = $userRecords[$halfDayKey] ?? [];
            $sig = trim($signatureText) !== '' ? trim($signatureText) : 'Signé numériquement';
            $signedAt = date('Y-m-d H:i:s');

            $entry = [
                'user_id' => $userId,
                'course_slug' => $courseSlug,
                'half_day_key' => $halfDayKey,
                'signature' => $sig,
                'signature_text' => $sig,
                'signed_at' => $signedAt,
                'ip' => $ip !== '' ? $ip : '127.0.0.1',
                'hash' => substr(hash('sha256', $userId . '|' . $courseSlug . '|' . $halfDayKey . '|' . $sig . '|' . $signedAt), 0, 16),
            ];

            // Preserve trainer counter-signature when learner re-signs the slot
            if (!empty($existing['trainer_signed_at'])) {
                $entry['trainer_signed_at'] = $existing['trainer_signed_at'];
            }
            if (!empty($existing['trainer_name'])) {
                $entry['trainer_name'] = $existing['trainer_name'];
            }
            if (!empty($existing['trainer_hash'])) {
                $entry['trainer_hash'] = $existing['trainer_hash'];
            }

            $userRecords[$halfDayKey] = $entry;
            $all[$key] = $userRecords;
            return [$all, $userRecords];
        });
    }

    public function getUserAttendance(int $userId, string $courseSlug = 'postgresql-18'): array
    {
        $all = $this->readFile('attendance', []);
        $key = $userId . ':' . $courseSlug;
        return $all[$key] ?? [];
    }

    public function getAllAttendance(): array
    {
        return $this->readFile('attendance', []);
    }

    public function saveSatisfactionSurvey(int $userId, string $courseSlug, array $survey): array
    {
        $key = $userId . ':' . $courseSlug;
        return (array)$this->mutateFile('satisfaction', [], static function (array $all) use ($key, $userId, $courseSlug, $survey): array {
            $criteriaKeys = ['content_quality', 'labs_docker', 'trainer_pedagogy', 'lms_ergonomics', 'objectives_met'];
            $ratings = [];
            foreach ($criteriaKeys as $ck) {
                $val = isset($survey['ratings'][$ck]) ? (int)$survey['ratings'][$ck] : (int)($survey[$ck] ?? 5);
                $ratings[$ck] = max(1, min(5, $val));
            }

            $survey['user_id'] = $userId;
            $survey['course_slug'] = $courseSlug;
            $survey['ratings'] = $ratings;
            $survey['average_rating'] = round(array_sum($ratings) / count($ratings), 2);
            $survey['nps_score'] = max(0, min(10, (int)($survey['nps_score'] ?? 10)));
            $survey['submitted_at'] = date('Y-m-d H:i:s');

            $all[$key] = $survey;
            return [$all, $survey];
        });
    }

    public function getUserSatisfaction(int $userId, string $courseSlug = 'postgresql-18'): ?array
    {
        $all = $this->readFile('satisfaction', []);
        $key = $userId . ':' . $courseSlug;
        if (!isset($all[$key])) {
            return null;
        }
        $row = $all[$key];
        if (isset($row['average_rating'])) {
            $row['average_rating'] = (float)$row['average_rating'];
        }
        return $row;
    }

    public function getAllSatisfactionSurveys(): array
    {
        return $this->readFile('satisfaction', []);
    }

    public function toggleBookmark(int $userId, string $courseSlug, string $itemType, string $itemId = '', string $title = ''): array
    {
        if ($itemId === '') {
            $itemId = $itemType;
            $itemType = 'module';
        }
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);

        return (array)$this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $itemType, $itemId, $title): array {
            $prog = $all[$key] ?? $defaultProg;
            $bookmarks = $prog['bookmarks'] ?? [];
            if (!is_array($bookmarks)) {
                $bookmarks = [];
            }
            $bmKey = $itemType . ':' . $itemId;
            $bookmarked = false;
            if (isset($bookmarks[$bmKey])) {
                unset($bookmarks[$bmKey]);
            } else {
                $bookmarks[$bmKey] = [
                    'type' => $itemType,
                    'id' => $itemId,
                    'title' => $title !== '' ? $title : $itemId,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                $bookmarked = true;
            }
            $prog['bookmarks'] = $bookmarks;
            $prog['updated_at'] = date('Y-m-d H:i:s');
            $all[$key] = $prog;

            return [$all, ['bookmarked' => $bookmarked, 'bookmarks' => $bookmarks]];
        });
    }

    public function recordLastVisited(int $userId, string $courseSlug, string $itemType, string $itemId, string $title, string $mode = 'reader'): void
    {
        $currentProg = $this->getUserProgress($userId, $courseSlug);
        $last = $currentProg['last_visited'] ?? null;
        if (
            is_array($last)
            && ($last['type'] ?? '') === $itemType
            && ($last['id'] ?? '') === $itemId
            && ($last['mode'] ?? 'reader') === $mode
        ) {
            return;
        }

        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);
        $this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $itemType, $itemId, $title, $mode): array {
            $prog = $all[$key] ?? $defaultProg;
            $prog['last_visited'] = [
                'type' => $itemType,
                'id' => $itemId,
                'title' => $title,
                'mode' => $mode,
                'visited_at' => date('Y-m-d H:i:s'),
            ];
            $all[$key] = $prog;
            return [$all, null];
        });
    }

    public function saveFlashcardConfidence(int $userId, string $courseSlug, string $cardId, int|string $level): void
    {
        $key = $userId . ':' . $courseSlug;
        $defaultProg = $this->defaultUserProgress($userId, $courseSlug);
        $numLevel = is_numeric($level) ? max(1, min(3, (int)$level)) : ($level === 'mastered' ? 3 : ($level === 'review' ? 1 : 2));

        $this->mutateFile('progress', [], static function (array $all) use ($key, $defaultProg, $cardId, $numLevel): array {
            $prog = $all[$key] ?? $defaultProg;
            unset($prog['flashcards']);
            if (!isset($prog['flashcard_confidence']) || !is_array($prog['flashcard_confidence'])) {
                $prog['flashcard_confidence'] = [];
            }
            $prog['flashcard_confidence'][$cardId] = [
                'level' => $numLevel,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $all[$key] = $prog;
            return [$all, null];
        });
    }

    public function resetFlashcardReviews(int $userId, string $courseSlug): bool
    {
        $key = $userId . ':' . $courseSlug;
        return (bool)$this->mutateFile('progress', [], static function (array $all) use ($key): array {
            if (isset($all[$key]) && is_array($all[$key])) {
                $all[$key]['flashcard_confidence'] = [];
                unset($all[$key]['flashcards']);
                return [$all, true];
            }
            return [$all, true];
        });
    }

    public function addDiscussionMessage(string $courseSlug, string $itemId, int $userId, string $userName, string $userRole, string $message, ?string $parentId = null): array
    {
        $cleanMsg = function_exists('mb_substr')
            ? mb_substr(trim($message), 0, 2000, 'UTF-8')
            : substr(trim($message), 0, 2000);
        if ($cleanMsg === '') {
            return [];
        }

        $key = $courseSlug . ':' . $itemId;
        return (array)$this->mutateFile('discussions', [], static function (array $all) use ($key, $courseSlug, $itemId, $userId, $userName, $userRole, $cleanMsg, $parentId): array {
            $list = $all[$key] ?? [];
            $entry = [
                'id' => uniqid('msg_', false),
                'course_slug' => $courseSlug,
                'item_id' => $itemId,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_role' => $userRole,
                'message' => $cleanMsg,
                'parent_id' => $parentId,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $list[] = $entry;
            $all[$key] = $list;
            return [$all, $entry];
        });
    }

    public function getDiscussions(string $courseSlug, string $itemId = ''): array
    {
        $all = $this->readFile('discussions', []);
        if ($itemId === '') {
            return $all[$courseSlug] ?? [];
        }
        $key = $courseSlug . ':' . $itemId;
        return $all[$key] ?? ($all[$itemId] ?? []);
    }

    // =========================================================================
    // Enterprise Classroom, Q&A Moderation & Verification Methods
    // =========================================================================

    public function signTrainerAttendance(int $userId, string $courseSlug, string $halfDayKey, string $trainerName = 'Jean-Marie Renouard'): array
    {
        $cleanTrainer = trim($trainerName) !== '' ? trim($trainerName) : 'Jean-Marie Renouard';
        return (array)$this->mutateFile('attendance', [], static function (array $all) use ($userId, $courseSlug, $halfDayKey, $cleanTrainer): array {
            $now = date('Y-m-d H:i:s');
            $targetKeys = [];
            if ($userId <= 0) {
                foreach (array_keys($all) as $k) {
                    if (str_ends_with((string)$k, ':' . $courseSlug)) {
                        $targetKeys[] = (string)$k;
                    }
                }
            } else {
                $targetKeys[] = $userId . ':' . $courseSlug;
            }

            foreach ($targetKeys as $tKey) {
                $userRecords = $all[$tKey] ?? [];
                if ($halfDayKey === 'ALL') {
                    foreach ($userRecords as $hdKey => $rec) {
                        if (is_array($rec)) {
                            $userRecords[$hdKey]['trainer_signed_at'] = $now;
                            $userRecords[$hdKey]['trainer_name'] = $cleanTrainer;
                            $userRecords[$hdKey]['trainer_hash'] = substr(hash('sha256', $tKey . '|' . $hdKey . '|' . $cleanTrainer . '|trainer|' . $now), 0, 16);
                        }
                    }
                } elseif (preg_match('/^J([1-9]|1[01])(?:_DAY)?$/i', $halfDayKey, $matches)) {
                    $dayNum = (int)$matches[1];
                    $dayKeys = ["J{$dayNum}_AM", "J{$dayNum}_PM"];
                    foreach ($dayKeys as $dk) {
                        if (isset($userRecords[$dk]) && is_array($userRecords[$dk])) {
                            $userRecords[$dk]['trainer_signed_at'] = $now;
                            $userRecords[$dk]['trainer_name'] = $cleanTrainer;
                            $userRecords[$dk]['trainer_hash'] = substr(hash('sha256', $tKey . '|' . $dk . '|' . $cleanTrainer . '|trainer|' . $now), 0, 16);
                        } else {
                            $userRecords[$dk] = [
                                'user_id' => (int)explode(':', $tKey)[0],
                                'course_slug' => $courseSlug,
                                'half_day_key' => $dk,
                                'signature' => 'Émargé en session',
                                'signature_text' => 'Émargé en session',
                                'signed_at' => $now,
                                'ip' => '127.0.0.1',
                                'hash' => substr(hash('sha256', $tKey . '|' . $dk . '|' . $now), 0, 16),
                                'trainer_signed_at' => $now,
                                'trainer_name' => $cleanTrainer,
                                'trainer_hash' => substr(hash('sha256', $tKey . '|' . $dk . '|' . $cleanTrainer . '|trainer|' . $now), 0, 16),
                            ];
                        }
                    }
                } elseif (Security::isValidHalfDayKey($halfDayKey)) {
                    $existing = $userRecords[$halfDayKey] ?? [
                        'user_id' => (int)explode(':', $tKey)[0],
                        'course_slug' => $courseSlug,
                        'half_day_key' => $halfDayKey,
                        'signature' => 'Émargé en session',
                        'signature_text' => 'Émargé en session',
                        'signed_at' => $now,
                        'ip' => '127.0.0.1',
                        'hash' => substr(hash('sha256', $tKey . '|' . $halfDayKey . '|' . $now), 0, 16),
                    ];
                    $existing['trainer_signed_at'] = $now;
                    $existing['trainer_name'] = $cleanTrainer;
                    $existing['trainer_hash'] = substr(hash('sha256', $tKey . '|' . $halfDayKey . '|' . $cleanTrainer . '|trainer|' . $now), 0, 16);
                    $userRecords[$halfDayKey] = $existing;
                }
                $all[$tKey] = $userRecords;
            }

            $retKey = $userId > 0 ? ($userId . ':' . $courseSlug) : ($targetKeys[0] ?? '');
            return [$all, $retKey !== '' ? ($all[$retKey] ?? []) : $all];
        });
    }

    public function saveColdSatisfactionSurvey(int $userId, string $courseSlug, array $survey): array
    {
        $key = $userId . ':' . $courseSlug;
        return (array)$this->mutateFile('satisfaction_cold', [], static function (array $all) use ($key, $userId, $courseSlug, $survey): array {
            $criteriaKeys = ['prod_application', 'perf_gains', 'autonomy_level', 'tools_adoption', 'career_impact'];
            $ratings = [];
            foreach ($criteriaKeys as $ck) {
                $val = isset($survey['ratings'][$ck]) ? (int)$survey['ratings'][$ck] : (int)($survey[$ck] ?? 5);
                $ratings[$ck] = max(1, min(5, $val));
            }

            $survey['user_id'] = $userId;
            $survey['course_slug'] = $courseSlug;
            $survey['survey_type'] = 'cold_j60';
            $survey['ratings'] = $ratings;
            $survey['average_rating'] = round(array_sum($ratings) / count($ratings), 2);
            $survey['concrete_projects'] = trim((string)($survey['concrete_projects'] ?? ''));
            $survey['further_needs'] = trim((string)($survey['further_needs'] ?? ''));
            $survey['submitted_at'] = date('Y-m-d H:i:s');

            $all[$key] = $survey;
            return [$all, $survey];
        });
    }

    public function getUserColdSatisfaction(int $userId, string $courseSlug = 'postgresql-18'): ?array
    {
        $all = $this->readFile('satisfaction_cold', []);
        $key = $userId . ':' . $courseSlug;
        if (!isset($all[$key])) {
            return null;
        }
        $row = $all[$key];
        if (isset($row['average_rating'])) {
            $row['average_rating'] = (float)$row['average_rating'];
        }
        return $row;
    }

    public function getAllColdSatisfactionSurveys(): array
    {
        return $this->readFile('satisfaction_cold', []);
    }

    public function findCertificateByCode(string $certificateId): ?array
    {
        $norm = strtoupper(trim($certificateId));
        if ($norm === '') {
            return null;
        }

        // Recognize Qualiopi training & attendance certificates (LP-PG18-ATT-<uid>-<date>, LP-GITBAS-ATT-<uid>-<date>)
        if (preg_match('/^LP-(?:([A-Z0-9]+)-)?ATT-(\d+)-(\d{6,8})$/i', $norm, $m)) {
            $prefix = strtoupper((string)$m[1]);
            $uid = (int)$m[2];
            $user = $this->getUserById($uid);
            if ($user !== null) {
                $rawDate = (string)$m[3];
                $formattedDate = strlen($rawDate) === 8
                    ? substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2)
                    : date('Y-m-d');
                $courseMeta = match ($prefix) {
                    'GITBAS' => [
                        'slug' => 'git-basics',
                        'title' => 'Git — Les Bases Essentielles du Versionnement & de la Collaboration',
                    ],
                    'PG18', '' => [
                        'slug' => 'postgresql-18',
                        'title' => 'PostgreSQL 18 — Cursus Complet (Administration & Optimisation)',
                    ],
                    default => [
                        'slug' => strtolower($prefix),
                        'title' => 'Formation Certifiante LightPath (' . $prefix . ')',
                    ],
                };
                return [
                    'certificate_id' => $norm,
                    'user_id' => $uid,
                    'user_name' => (string)($user['name'] ?? 'Apprenant LightPath'),
                    'user_email' => (string)($user['email'] ?? ''),
                    'company' => (string)($user['company'] ?? ''),
                    'course_slug' => $courseMeta['slug'],
                    'course_title' => $courseMeta['title'],
                    'certificate_type' => 'attendance',
                    'mention' => 'Attestation de Formation & Assiduité Qualiopi',
                    'percentage' => 100,
                    'percent' => 100,
                    'passed' => true,
                    'certified' => true,
                    'verified' => true,
                    'submitted_at' => $formattedDate . ' 17:30:00',
                    'issued_at' => $formattedDate,
                ];
            }
        }

        $allExams = $this->getAllExamAttempts();
        foreach ($allExams as $userCourseKey => $attempts) {
            if (!is_array($attempts)) {
                continue;
            }
            foreach ($attempts as $attempt) {
                if (!is_array($attempt)) {
                    continue;
                }
                $cid = strtoupper(trim((string)($attempt['certificate_id'] ?? '')));
                if ($cid !== '' && $cid === $norm) {
                    $uid = (int)($attempt['user_id'] ?? explode(':', (string)$userCourseKey)[0]);
                    $user = $this->getUserById($uid);
                    $passed = !empty($attempt['passed']);
                    $attemptCourseSlug = (string)($attempt['course_slug'] ?? 'postgresql-18');
                    $defaultAttemptTitle = match ($attemptCourseSlug) {
                        'git-basics' => 'Git — Les Bases Essentielles du Versionnement & de la Collaboration',
                        'postgresql-18' => 'PostgreSQL 18 — Cursus Complet (Administration & Optimisation)',
                        default => 'Formation Certifiante LightPath (' . strtoupper($attemptCourseSlug) . ')',
                    };
                    return array_merge($attempt, [
                        'verified' => true,
                        'passed' => $passed,
                        'certified' => $passed,
                        'user_id' => $uid,
                        'user_name' => (string)($user['name'] ?? 'Apprenant Certifié LightPath'),
                        'user_email' => (string)($user['email'] ?? ''),
                        'company' => (string)($user['company'] ?? ''),
                        'course_slug' => $attemptCourseSlug,
                        'course_title' => (string)($attempt['course_title'] ?? $defaultAttemptTitle),
                    ]);
                }
            }
        }
        return null;
    }

    public function getClassroomState(string $courseSlug = 'postgresql-18'): array
    {
        $all = $this->readFile('classroom_state', []);
        $default = [
            'course_slug' => $courseSlug,
            'broadcast_message' => '',
            'broadcast_level' => 'info',
            'lock_solutions' => false,
            'lock_exam' => false,
            'active_track' => 'cursus-complet',
            'updated_by' => '',
            'updated_at' => null,
        ];
        return array_merge($default, is_array($all[$courseSlug] ?? null) ? $all[$courseSlug] : []);
    }

    public function saveClassroomState(string $courseSlug, array $state): array
    {
        return (array)$this->mutateFile('classroom_state', [], static function (array $all) use ($courseSlug, $state): array {
            $current = $all[$courseSlug] ?? [];
            $updated = [
                'course_slug' => $courseSlug,
                'broadcast_message' => trim((string)($state['broadcast_message'] ?? $current['broadcast_message'] ?? '')),
                'broadcast_level' => in_array(($state['broadcast_level'] ?? ''), ['info', 'emerald', 'amber'], true)
                    ? (string)$state['broadcast_level']
                    : (string)($current['broadcast_level'] ?? 'info'),
                'lock_solutions' => isset($state['lock_solutions']) ? (bool)$state['lock_solutions'] : (bool)($current['lock_solutions'] ?? false),
                'lock_exam' => isset($state['lock_exam']) ? (bool)$state['lock_exam'] : (bool)($current['lock_exam'] ?? false),
                'active_track' => trim((string)($state['active_track'] ?? $current['active_track'] ?? 'cursus-complet')),
                'updated_by' => trim((string)($state['updated_by'] ?? $current['updated_by'] ?? 'Formateur')),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $all[$courseSlug] = $updated;
            return [$all, $updated];
        });
    }

    public function resolveDiscussionMessage(string $courseSlug, string $itemId, string $messageId, bool $resolved = true): bool
    {
        $key = $courseSlug . ':' . $itemId;
        return (bool)$this->mutateFile('discussions', [], static function (array $all) use ($key, $itemId, $messageId, $resolved): array {
            $targetKey = isset($all[$key]) ? $key : (isset($all[$itemId]) ? $itemId : null);
            if ($targetKey === null || !is_array($all[$targetKey])) {
                return [null, false];
            }
            $found = false;
            foreach ($all[$targetKey] as $idx => $msg) {
                if (($msg['id'] ?? '') === $messageId) {
                    $all[$targetKey][$idx]['resolved'] = $resolved;
                    $all[$targetKey][$idx]['resolved_at'] = $resolved ? date('Y-m-d H:i:s') : null;
                    $found = true;
                    break;
                }
            }
            return [$found ? $all : null, $found];
        });
    }

    public function deleteDiscussionMessage(string $courseSlug, string $itemId, string $messageId): bool
    {
        $key = $courseSlug . ':' . $itemId;
        return (bool)$this->mutateFile('discussions', [], static function (array $all) use ($key, $itemId, $messageId): array {
            $targetKey = isset($all[$key]) ? $key : (isset($all[$itemId]) ? $itemId : null);
            if ($targetKey === null || !is_array($all[$targetKey])) {
                return [null, false];
            }
            $before = count($all[$targetKey]);
            $filtered = array_values(array_filter(
                $all[$targetKey],
                static fn(array $m): bool => ($m['id'] ?? '') !== $messageId
            ));
            if (count($filtered) === $before) {
                return [null, false];
            }
            $all[$targetKey] = $filtered;
            return [$all, true];
        });
    }

    public function getAllCourseDiscussions(string $courseSlug = 'postgresql-18'): array
    {
        $all = $this->readFile('discussions', []);
        $result = [];
        $prefix = $courseSlug . ':';
        foreach ($all as $k => $messages) {
            if (!is_array($messages)) {
                continue;
            }
            if (str_starts_with((string)$k, $prefix)) {
                $itemId = substr((string)$k, strlen($prefix));
                foreach ($messages as $m) {
                    if (is_array($m)) {
                        $m['item_id'] = $m['item_id'] ?? $itemId;
                        $result[] = $m;
                    }
                }
            }
        }
        usort($result, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $result;
    }

    public function importUsersFromCsv(string $csvContent, string $defaultPassword = '', string $courseSlug = 'postgresql-18'): array
    {
        // Strip leading UTF-8 BOM emitted by Excel exports
        $cleanContent = preg_replace('/^\xEF\xBB\xBF/', '', trim($csvContent));
        $lines = preg_split('/\r\n|\r|\n/', (string)$cleanContent) ?: [];
        $targetCourse = trim($courseSlug) !== '' ? trim($courseSlug) : 'postgresql-18';

        return (array)$this->mutateFile('users', [], static function (array $users) use ($lines, $defaultPassword, $targetCourse): array {
            $created = 0;
            $skipped = 0;
            $importedUsers = [];

            $existingEmails = [];
            $maxId = 0;
            foreach ($users as $u) {
                if (isset($u['email'])) {
                    $existingEmails[strtolower(trim((string)$u['email']))] = true;
                }
                $uId = (int)($u['id'] ?? 0);
                if ($uId > $maxId) {
                    $maxId = $uId;
                }
            }

            foreach ($lines as $idx => $rawLine) {
                $line = trim($rawLine);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                // Auto-detect CSV/TSV field delimiter (;, ,, \t)
                $semis = substr_count($line, ';');
                $tabs = substr_count($line, "\t");
                $commas = substr_count($line, ',');
                $delim = ',';
                if ($tabs > $commas && $tabs > $semis) {
                    $delim = "\t";
                } elseif ($semis >= $commas && $semis > 0) {
                    $delim = ';';
                }

                $cols = str_getcsv($line, $delim);
                if (count($cols) < 2) {
                    $skipped++;
                    continue;
                }
                $name = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)($cols[0] ?? '')));
                $email = strtolower(trim((string)($cols[1] ?? '')));
                $company = trim((string)($cols[2] ?? ''));
                $role = strtolower(trim((string)($cols[3] ?? 'student')));

                // Enforce minimum 6-character password length or generate a random per-user secret
                $rawPass = trim((string)($cols[4] ?? ''));
                $password = (strlen($rawPass) >= 6) ? $rawPass : $defaultPassword;
                if (strlen($password) < 6) {
                    $password = bin2hex(random_bytes(8));
                }

                // Skip optional CSV header row
                if ($idx === 0 && (in_array(strtolower($name), ['name', 'nom', 'fullname', 'nom complet'], true) || in_array($email, ['email', 'courriel', 'mail'], true))) {
                    continue;
                }

                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }
                if (isset($existingEmails[$email])) {
                    $skipped++;
                    continue;
                }
                if (!in_array($role, Auth::VALID_ROLES, true)) {
                    $role = 'student';
                }

                $safeName = Security::sanitizeCsvCell($name);
                $safeCompany = Security::sanitizeCsvCell($company);

                $maxId++;
                $newUser = [
                    'id' => $maxId,
                    'name' => $safeName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'company' => $safeCompany,
                    'active' => true,
                    'enrolled_courses' => array_values(array_unique([$targetCourse, 'postgresql-18'])),
                    'created_at' => date('Y-m-d H:i:s'),
                    'last_login' => null,
                ];

                $users[] = $newUser;
                $existingEmails[$email] = true;
                $created++;
                $importedUsers[] = $newUser;
            }

            return [$users, [
                'created' => $created,
                'skipped' => $skipped,
                'users' => $importedUsers,
            ]];
        });
    }

    public function getLoginThrottle(string $key): ?array
    {
        $all = $this->readFile('login_throttle', []);
        return (isset($all[$key]) && is_array($all[$key])) ? $all[$key] : null;
    }

    public function listLoginThrottles(): array
    {
        $all = $this->readFile('login_throttle', []);
        $now = time();
        $result = [];
        foreach ($all as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $keyStr = (string)$key;
            $firstAt = (int)($entry['first_at'] ?? 0);
            $count = (int)($entry['count'] ?? 0);
            $age = $now - $firstAt;
            if ($age > 3600) {
                continue;
            }
            $isIpBucket = str_starts_with($keyStr, 'ip|');
            $parts = explode('|', $keyStr, 2);
            $emailPart = $isIpBucket ? '' : ($parts[0] ?? '');
            $ipPart = $isIpBucket ? substr($keyStr, 3) : ($parts[1] ?? '');
            $limit = $isIpBucket ? 25 : 5;
            $locked = ($age <= 300) && ($count >= $limit);
            $result[$keyStr] = [
                'key' => $keyStr,
                'type' => $isIpBucket ? 'ip' : 'account',
                'email' => $emailPart,
                'ip' => $ipPart,
                'count' => $count,
                'first_at' => $firstAt,
                'updated_at' => (int)($entry['updated_at'] ?? $firstAt),
                'locked' => $locked,
                'remaining_seconds' => $locked ? max(0, 300 - $age) : 0,
            ];
        }
        return $result;
    }

    public function recordLoginFailure(string $key, int $windowSeconds = 300): array
    {
        $window = max(1, $windowSeconds);
        return (array)$this->mutateFile('login_throttle', [], static function (array $all) use ($key, $window): array {
            $now = time();
            $pruneAge = max($window, 3600);
            foreach ($all as $k => $v) {
                if (!is_array($v) || ($now - (int)($v['first_at'] ?? 0)) > $pruneAge) {
                    unset($all[$k]);
                }
            }
            $existing = $all[$key] ?? null;
            if (!is_array($existing) || ($now - (int)($existing['first_at'] ?? 0)) > $window) {
                $entry = ['count' => 1, 'first_at' => $now, 'updated_at' => $now];
            } else {
                $entry = [
                    'count' => (int)($existing['count'] ?? 0) + 1,
                    'first_at' => (int)$existing['first_at'],
                    'updated_at' => $now,
                ];
            }
            $all[$key] = $entry;
            return [$all, $entry];
        });
    }

    public function clearLoginThrottle(string $emailOrKey, string $ip = ''): void
    {
        $this->mutateFile('login_throttle', [], static function (array $all) use ($emailOrKey, $ip): array {
            $trimmed = trim($emailOrKey);
            $removed = false;

            if ($ip !== '') {
                $targetKey = $trimmed === ''
                    ? ('ip|' . $ip)
                    : (strtolower($trimmed) . '|' . $ip);
                if (isset($all[$targetKey])) {
                    unset($all[$targetKey]);
                    $removed = true;
                }
            }

            if ($trimmed !== '' && isset($all[$trimmed])) {
                unset($all[$trimmed]);
                $removed = true;
            }

            if ($trimmed !== '' && $ip === '' && !str_contains($trimmed, '|')) {
                $prefix = strtolower($trimmed) . '|';
                foreach (array_keys($all) as $k) {
                    if (str_starts_with((string)$k, $prefix)) {
                        unset($all[$k]);
                        $removed = true;
                    }
                }
            }

            return [$removed ? $all : null, null];
        });
    }

    public function getFeatureFlags(): array
    {
        return $this->readFile('features', []);
    }

    public function saveFeatureFlags(array $flags): array
    {
        return (array)$this->mutateFile('features', [], static function (array $current) use ($flags): array {
            $clean = [];
            foreach ($flags as $k => $v) {
                $clean[(string)$k] = (bool)$v;
            }
            $merged = array_merge($current, $clean);
            return [$merged, $merged];
        });
    }

    public function getCourseFeatureOverrides(string $courseSlug): array
    {
        $slug = trim($courseSlug);
        if ($slug === '') {
            return [];
        }
        $all = $this->readFile('course_feature_overrides', []);
        $overrides = $all[$slug] ?? [];
        if (!is_array($overrides)) {
            return [];
        }
        $normalized = [];
        foreach ($overrides as $flag => $val) {
            $normalized[(string)$flag] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }
        return $normalized;
    }

    public function saveCourseFeatureOverrides(string $courseSlug, array $overrides): void
    {
        $slug = trim($courseSlug);
        if ($slug === '') {
            return;
        }
        $this->mutateFile('course_feature_overrides', [], static function (array $all) use ($slug, $overrides): array {
            $clean = [];
            foreach ($overrides as $flag => $val) {
                if ($val === null || $val === '' || $val === 'inherit') {
                    continue;
                }
                $clean[(string)$flag] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            }
            if (empty($clean)) {
                unset($all[$slug]);
            } else {
                $all[$slug] = $clean;
            }
            return [$all, null];
        });
    }

    public function resetUserProgress(int $userId, string $courseSlug): bool
    {
        $slug = trim($courseSlug);
        if ($userId <= 0 || $slug === '') {
            return false;
        }
        $key = $userId . ':' . $slug;
        return (bool)$this->mutateFile('progress', [], static function (array $all) use ($key): array {
            if (!isset($all[$key])) {
                return [null, true];
            }
            unset($all[$key]);
            return [$all, true];
        });
    }

    public function toggleCustomCourse(string $slug, bool $active): bool
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '') {
            return false;
        }
        return (bool)$this->mutateFile('custom_courses', [], static function (array $courses) use ($cleanSlug, $active): array {
            if (!isset($courses[$cleanSlug]) || !is_array($courses[$cleanSlug])) {
                return [null, false];
            }
            $courses[$cleanSlug]['active'] = $active;
            return [$courses, true];
        });
    }

    public function deleteCustomCourse(string $slug): bool
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '') {
            return false;
        }
        return (bool)$this->mutateFile('custom_courses', [], static function (array $courses) use ($cleanSlug): array {
            if (!isset($courses[$cleanSlug])) {
                return [null, false];
            }
            unset($courses[$cleanSlug]);
            return [$courses, true];
        });
    }
}
