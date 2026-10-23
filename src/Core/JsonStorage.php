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

    private function readFile(string $name, array $default = []): array
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

    private function writeFile(string $name, array $data): void
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
}
