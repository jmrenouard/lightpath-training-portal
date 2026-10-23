<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Auth
{
    private StorageInterface $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->storage->getUserByEmail($email);
        if ($user === null || empty($user['active'])) {
            return null;
        }
        if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
            return null;
        }
        return $user;
    }

    public function login(string $email, string $password): ?array
    {
        $user = $this->verifyCredentials($email, $password);
        if ($user === null) {
            return null;
        }

        $user['last_login'] = date('Y-m-d H:i:s');
        $this->storage->saveUser($user);
        $this->storage->logAuditEvent($user['email'], 'LOGIN', 'Connexion réussie au portail LMS');

        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
        }
        return $user;
    }

    public function logout(): void
    {
        if (PHP_SAPI !== 'cli') {
            unset($_SESSION['user_id']);
            session_destroy();
        }
    }

    public function currentUser(): ?array
    {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid <= 0) {
            return null;
        }
        $user = $this->storage->getUserById($uid);
        if ($user === null || empty($user['active'])) {
            return null;
        }
        return $user;
    }

    public function registerUser(
        string $name,
        string $email,
        string $password,
        string $role = 'student',
        string $company = '',
        array $enrolledCourses = ['postgresql-18']
    ): ?array {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            return null;
        }
        if ($this->storage->getUserByEmail($email) !== null) {
            return null;
        }

        $allowedRoles = ['student', 'trainer', 'admin'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'student';
        }

        $saved = $this->storage->saveUser([
            'name' => trim($name),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $role,
            'company' => trim($company),
            'active' => true,
            'enrolled_courses' => $enrolledCourses,
            'created_at' => date('Y-m-d H:i:s'),
            'last_login' => null,
        ]);

        $this->storage->logAuditEvent($email, 'USER_REGISTER', "Création du compte ({$role}) - {$name}");
        return $saved;
    }

    public function isAdmin(?array $user = null): bool
    {
        $u = $user ?? $this->currentUser();
        return ($u['role'] ?? '') === 'admin';
    }

    public function isStaff(?array $user = null): bool
    {
        $u = $user ?? $this->currentUser();
        return in_array($u['role'] ?? '', ['admin', 'trainer'], true);
    }
}
