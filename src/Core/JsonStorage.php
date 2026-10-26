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
        $users = $this->getUsers();
        $id = isset($user['id']) ? (int)$user['id'] : 0;

        if ($id > 0) {
            foreach ($users as $idx => $existing) {
                if ((int)$existing['id'] === $id) {
                    $users[$idx] = array_merge($existing, $user);
                    $this->writeFile('users', $users);
                    return $users[$idx];
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
        $this->writeFile('users', $users);

        return $user;
    }

    public function deleteUser(int $id): bool
    {
        if ($id === 1) {
            return false; // Protection du compte Super-Admin principal
        }
        $users = $this->getUsers();
        $filtered = array_values(array_filter($users, static fn(array $u): bool => (int)$u['id'] !== $id));
        if (count($filtered) === count($users)) {
            return false;
        }
        $this->writeFile('users', $filtered);
        return true;
    }

    public function getAllProgress(): array
    {
        return $this->readFile('progress', []);
    }

    public function getUserProgress(int $userId, string $courseSlug): array
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        return $all[$key] ?? [
            'user_id' => $userId,
            'course_slug' => $courseSlug,
            'completed_modules' => [],
            'completed_labs' => [],
            'quiz_scores' => [],
            'notes' => [],
            'updated_at' => null,
        ];
    }

    public function markItemCompleted(int $userId, string $courseSlug, string $itemType, string $itemId, bool $completed = true): void
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

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
        $this->writeFile('progress', $all);
    }

    public function saveQuizScore(int $userId, string $courseSlug, string $moduleId, int $score, int $total): void
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

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
        $this->writeFile('progress', $all);
    }

    public function saveUserNote(int $userId, string $courseSlug, string $itemId, string $note): void
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

        $prog['notes'][$itemId] = trim($note);
        $prog['updated_at'] = date('Y-m-d H:i:s');
        $all[$key] = $prog;
        $this->writeFile('progress', $all);
    }

    public function getCustomCourses(): array
    {
        return $this->readFile('custom_courses', []);
    }

    public function saveCustomCourse(array $course): void
    {
        $courses = $this->getCustomCourses();
        $slug = (string)$course['slug'];
        $courses[$slug] = $course;
        $this->writeFile('custom_courses', $courses);
    }

    public function logAuditEvent(string $actor, string $action, string $details): void
    {
        $logs = $this->readFile('audit_logs', []);
        array_unshift($logs, [
            'timestamp' => date('Y-m-d H:i:s'),
            'actor' => $actor,
            'action' => $action,
            'details' => $details,
        ]);
        if (count($logs) > 250) {
            $logs = array_slice($logs, 0, 250);
        }
        $this->writeFile('audit_logs', $logs);
    }

    public function getAuditLogs(int $limit = 50): array
    {
        $logs = $this->readFile('audit_logs', []);
        return array_slice($logs, 0, $limit);
    }

    // =========================================================================
    // Fonctionnalités LMS Enterprise & Qualiopi (RCA-153, RCA-154, RCA-155)
    // =========================================================================

    public function saveDiagnosticResult(int $userId, string $courseSlug, array $result): void
    {
        $all = $this->readFile('diagnostics', []);
        $key = $userId . ':' . $courseSlug;
        $result['user_id'] = $userId;
        $result['course_slug'] = $courseSlug;
        $result['completed_at'] = date('Y-m-d H:i:s');
        $all[$key] = $result;
        $this->writeFile('diagnostics', $all);
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
        $all = $this->readFile('exams', []);
        $key = $userId . ':' . $courseSlug;
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
        $this->writeFile('exams', $all);
        return $attempt;
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
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

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
        $this->writeFile('progress', $all);

        return $newTotal;
    }

    public function signAttendance(int $userId, string $courseSlug, string $halfDayKey, string $signatureText = '', string $ip = '127.0.0.1'): array
    {
        $all = $this->readFile('attendance', []);
        $key = $userId . ':' . $courseSlug;
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
        $this->writeFile('attendance', $all);

        return $userRecords;
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
        $all = $this->readFile('satisfaction', []);
        $key = $userId . ':' . $courseSlug;

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
        $this->writeFile('satisfaction', $all);

        return $survey;
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
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

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
        $this->writeFile('progress', $all);

        return ['bookmarked' => $bookmarked, 'bookmarks' => $bookmarks];
    }

    public function recordLastVisited(int $userId, string $courseSlug, string $itemType, string $itemId, string $title, string $mode = 'reader'): void
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

        $prog['last_visited'] = [
            'type' => $itemType,
            'id' => $itemId,
            'title' => $title,
            'mode' => $mode,
            'visited_at' => date('Y-m-d H:i:s'),
        ];
        $all[$key] = $prog;
        $this->writeFile('progress', $all);
    }

    public function saveFlashcardConfidence(int $userId, string $courseSlug, string $cardId, int|string $level): void
    {
        $all = $this->getAllProgress();
        $key = $userId . ':' . $courseSlug;
        $prog = $this->getUserProgress($userId, $courseSlug);

        if (!isset($prog['flashcards']) || !is_array($prog['flashcards'])) {
            $prog['flashcards'] = [];
        }
        if (!isset($prog['flashcard_confidence']) || !is_array($prog['flashcard_confidence'])) {
            $prog['flashcard_confidence'] = [];
        }

        $numLevel = is_numeric($level) ? max(1, min(3, (int)$level)) : ($level === 'mastered' ? 3 : ($level === 'review' ? 1 : 2));
        $prog['flashcard_confidence'][$cardId] = [
            'level' => $numLevel,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $prog['flashcards'][$cardId] = $prog['flashcard_confidence'][$cardId];
        $all[$key] = $prog;
        $this->writeFile('progress', $all);
    }

    public function addDiscussionMessage(string $courseSlug, string $itemId, int $userId, string $userName, string $userRole, string $message, ?string $parentId = null): array
    {
        $all = $this->readFile('discussions', []);
        $key = $courseSlug . ':' . $itemId;
        $list = $all[$key] ?? [];
        $entry = [
            'id' => uniqid('msg_', false),
            'course_slug' => $courseSlug,
            'item_id' => $itemId,
            'user_id' => $userId,
            'user_name' => $userName,
            'user_role' => $userRole,
            'message' => trim($message),
            'parent_id' => $parentId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $list[] = $entry;
        $all[$key] = $list;
        $this->writeFile('discussions', $all);
        return $entry;
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
