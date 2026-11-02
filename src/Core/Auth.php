<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Auth
{
    private StorageInterface $storage;
    private array $loginAttempts = [];
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
        if (PHP_SAPI !== 'cli' && isset($_SESSION['lms_login_attempts']) && is_array($_SESSION['lms_login_attempts'])) {
            $this->loginAttempts = $_SESSION['lms_login_attempts'];
        }
    }

    private function throttleKey(string $email, string $ip): string
    {
        return strtolower(trim($email)) . '|' . ($ip !== '' ? $ip : '127.0.0.1');
    }

    private function syncThrottleSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lms_login_attempts'] = $this->loginAttempts;
        }
    }

    public function isLoginRateLimited(string $email, string $ip = '127.0.0.1'): bool
    {
        $key = $this->throttleKey($email, $ip);
        if (!isset($this->loginAttempts[$key])) {
            return false;
        }
        $entry = $this->loginAttempts[$key];
        if ((time() - (int)($entry['first_at'] ?? 0)) > self::LOCKOUT_SECONDS) {
            unset($this->loginAttempts[$key]);
            $this->syncThrottleSession();
            return false;
        }
        return (int)($entry['count'] ?? 0) >= self::MAX_LOGIN_ATTEMPTS;
    }

    public function recordFailedLogin(string $email, string $ip = '127.0.0.1'): void
    {
        $key = $this->throttleKey($email, $ip);
        $now = time();
        if (!isset($this->loginAttempts[$key]) || ($now - (int)($this->loginAttempts[$key]['first_at'] ?? 0)) > self::LOCKOUT_SECONDS) {
            $this->loginAttempts[$key] = ['count' => 1, 'first_at' => $now];
        } else {
            $this->loginAttempts[$key]['count'] = (int)$this->loginAttempts[$key]['count'] + 1;
        }
        $this->syncThrottleSession();
    }

    public function clearLoginAttempts(string $email, string $ip = '127.0.0.1'): void
    {
        $key = $this->throttleKey($email, $ip);
        unset($this->loginAttempts[$key]);
        $this->syncThrottleSession();
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

    public function login(string $email, string $password, string $ip = '127.0.0.1'): ?array
    {
        if ($this->isLoginRateLimited($email, $ip)) {
            return null;
        }

        $user = $this->verifyCredentials($email, $password);
        if ($user === null) {
            $this->recordFailedLogin($email, $ip);
            return null;
        }

        $this->clearLoginAttempts($email, $ip);
        $user['last_login'] = date('Y-m-d H:i:s');
        $this->storage->saveUser($user);
        $this->storage->logAuditEvent($user['email'], 'LOGIN', 'Connexion réussie au portail LMS');

        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['last_activity'] = time();
        }
        return $user;
    }

    public function logout(): void
    {
        if (PHP_SAPI !== 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 3600,
                    $params['path'] ?? '/',
                    $params['domain'] ?? '',
                    $params['secure'] ?? false,
                    $params['httponly'] ?? true
                );
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        } else {
            $_SESSION = [];
        }
    }

    public function currentUser(): ?array
    {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid <= 0) {
            return null;
        }

        // FIX-11: Expiration automatique de session après 4 heures (14400s) d'inactivité
        if (isset($_SESSION['last_activity'])) {
            if ((time() - (int)$_SESSION['last_activity']) > 14400) {
                $this->logout();
                return null;
            }
        }
        $_SESSION['last_activity'] = time();

        $user = $this->storage->getUserById($uid);
        if ($user === null || empty($user['active'])) {
            return null;
        }
        return $user;
    }

    public function getCurrentUser(): ?array
    {
        return $this->currentUser();
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
