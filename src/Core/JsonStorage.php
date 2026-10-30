<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Moteur de persistance Flat-File JSON Atomique (Zéro-Config)
 * Fonctionne sur 100% des hébergements PHP sans base de données externe requise.
 * Utilise flock(LOCK_EX) et écriture atomique par fichier temporaire.
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
        file_put_contents($tmpPath, $json, LOCK_EX);
        if (!@rename($tmpPath, $path)) {
            copy($tmpPath, $path);
            @unlink($tmpPath);
        }
    }

    /**
     * Exécute un cycle lecture-modification-écriture atomique sous verrou exclusif (.lock)
     * pour éliminer toute race condition check-then-write en salle de formation multi-utilisateurs.
     */
    protected function mutateFile(string $name, array $default, callable $mutator): mixed
    {
        $lockPath = $this->dataDir . '/' . $name . '.lock';
        $lockFp = @fopen($lockPath, 'c+b');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }
        try {
            $current = $this->readFile($name, $default);
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

        $now = date('Y-m-d H:i:s');
        $defaults = [
            [
                'id' => 1,
                'name' => 'Jean-Marie Renouard',
                'email' => 'jmrenouard@lightpath.fr',
                'password_hash' => password_hash('LightPath2026!', PASSWORD_BCRYPT),
                'role' => 'admin',
                'company' => 'LightPath SARL',
                'active' => true,
                'enrolled_courses' => ['postgresql-18'],
                'created_at' => $now,
                'last_login' => $now,
            ],
            [
                'id' => 2,
                'name' => 'Formateur LightPath',
                'email' => 'formateur@lightpath.fr',
                'password_hash' => password_hash('Formateur2026!', PASSWORD_BCRYPT),
                'role' => 'trainer',
                'company' => 'LightPath SARL',
                'active' => true,
                'enrolled_courses' => ['postgresql-18'],
                'created_at' => $now,
                'last_login' => $now,
            ],
            [
                'id' => 3,
                'name' => 'Thomas Dubois (Stagiaire DBA)',
                'email' => 'stagiaire@lightpath.fr',
                'password_hash' => password_hash('Stagiaire2026!', PASSWORD_BCRYPT),
                'role' => 'student',
                'company' => 'Client Entreprise',
                'active' => true,
                'enrolled_courses' => ['postgresql-18'],
                'created_at' => $now,
                'last_login' => $now,
            ],
        ];

        $this->writeFile('users', $defaults);

        // Pré-initialiser une progression de démonstration réaliste pour le stagiaire #3
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
            return false; // Protection du compte Super-Admin principal
        }
        return (bool)$this->mutateFile('users', [], static function (array $users) use ($id): array {
            $filtered = array_values(array_filter($users, static fn(array $u): bool => (int)$u['id'] !== $id));
            if (count($filtered) === count($users)) {
                return [null, false];
            }
            return [$filtered, true];
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

    public function logAuditEvent(string $actor, string $action, string $details): void
    {
        $this->mutateFile('audit_logs', [], static function (array $logs) use ($actor, $action, $details): array {
            array_unshift($logs, [
                'timestamp' => date('Y-m-d H:i:s'),
                'actor' => $actor,
                'action' => $action,
                'details' => $details,
            ]);
            if (count($logs) > 250) {
                $logs = array_slice($logs, 0, 250);
            }
            return [$logs, null];
        });
    }

    public function getAuditLogs(int $limit = 50): array
    {
        $logs = $this->readFile('audit_logs', []);
        return array_slice($logs, 0, $limit);
    }

    // =========================================================================
    // Fonctionnalités LMS Enterprise & Qualiopi (RCA-153, RCA-154, RCA-155, RCA-160)
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
                $attempt['certificate_id'] = sprintf(
                    'LP-PG18-CERT-%d-%s',
                    $userId,
                    strtoupper(substr(md5($userId . ':' . $courseSlug . ':' . microtime(true)), 0, 8))
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
            $sig = trim($signatureText) !== '' ? trim($signatureText) : 'Signé numériquement';

            $entry = [
                'user_id' => $userId,
                'course_slug' => $courseSlug,
                'half_day_key' => $halfDayKey,
                'signature' => $sig,
                'signature_text' => $sig,
                'signed_at' => date('Y-m-d H:i:s'),
                'ip' => $ip !== '' ? $ip : '127.0.0.1',
                'hash' => substr(hash('sha256', $userId . '|' . $courseSlug . '|' . $halfDayKey . '|' . $sig), 0, 16),
            ];

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
}
