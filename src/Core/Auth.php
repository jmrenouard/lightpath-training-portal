<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Auth
{
    public const VALID_ROLES = ['student', 'trainer', 'admin'];

    private StorageInterface $storage;
    private int $sessionIdleTtl;
    private array $loginAttempts = [];
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const MAX_IP_LOGIN_ATTEMPTS = 25;
    private const LOCKOUT_SECONDS = 300;
    private const DUMMY_BCRYPT_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(StorageInterface $storage, ?int $sessionIdleTtl = null)
    {
        $this->storage = $storage;
        if ($sessionIdleTtl !== null) {
            $this->sessionIdleTtl = max(60, $sessionIdleTtl);
        } else {
            $appCfgFile = __DIR__ . '/../../config/app.php';
            $appCfg = is_file($appCfgFile) ? @include $appCfgFile : [];
            $this->sessionIdleTtl = is_array($appCfg) ? max(60, (int)($appCfg['session_idle_ttl'] ?? 7200)) : 7200;
        }
        if (PHP_SAPI !== 'cli' && isset($_SESSION['lms_login_attempts']) && is_array($_SESSION['lms_login_attempts'])) {
            $this->loginAttempts = $_SESSION['lms_login_attempts'];
        }
    }

    private function throttleKey(string $email, string $ip): string
    {
        return strtolower(trim($email)) . '|' . ($ip !== '' ? $ip : '127.0.0.1');
    }

    private function ipThrottleKey(string $ip): string
    {
        return 'ip|' . ($ip !== '' ? $ip : '127.0.0.1');
    }

    private function syncThrottleSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lms_login_attempts'] = $this->loginAttempts;
        }
    }

    private function isBucketRateLimited(string $key, int $limit): bool
    {
        $entry = $this->storage->getLoginThrottle($key) ?? ($this->loginAttempts[$key] ?? null);
        if (!is_array($entry)) {
            return false;
        }
        if ((time() - (int)($entry['first_at'] ?? 0)) > self::LOCKOUT_SECONDS) {
            $this->storage->clearLoginThrottle($key);
            unset($this->loginAttempts[$key]);
            $this->syncThrottleSession();
            return false;
        }
        return (int)($entry['count'] ?? 0) >= $limit;
    }

    public function isLoginRateLimited(string $email, string $ip = '127.0.0.1'): bool
    {
        $key = $this->throttleKey($email, $ip);
        $ipKey = $this->ipThrottleKey($ip);
        return $this->isBucketRateLimited($key, self::MAX_LOGIN_ATTEMPTS)
            || $this->isBucketRateLimited($ipKey, self::MAX_IP_LOGIN_ATTEMPTS);
    }

    public function recordFailedLogin(string $email, string $ip = '127.0.0.1'): void
    {
        $key = $this->throttleKey($email, $ip);
        $ipKey = $this->ipThrottleKey($ip);
        $this->loginAttempts[$key] = $this->storage->recordLoginFailure($key, self::LOCKOUT_SECONDS);
        $this->loginAttempts[$ipKey] = $this->storage->recordLoginFailure($ipKey, self::LOCKOUT_SECONDS);
        $this->syncThrottleSession();
    }

    public function clearLoginAttempts(string $email, string $ip = '127.0.0.1'): void
    {
        $key = $this->throttleKey($email, $ip);
        $this->storage->clearLoginThrottle($key);
        unset($this->loginAttempts[$key]);
        if (trim($email) === '') {
            $ipKey = $this->ipThrottleKey($ip);
            $this->storage->clearLoginThrottle($ipKey);
            unset($this->loginAttempts[$ipKey]);
        }
        $this->syncThrottleSession();
    }

    /**
     * Checks whether a user account is active (defaults to true unless explicitly false, 0, or '0').
     */
    public static function isUserActive(array $user): bool
    {
        if (!array_key_exists('active', $user)) {
            return true;
        }
        $val = $user['active'];
        return !($val === false || $val === 0 || $val === '0');
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->storage->getUserByEmail($email);
        if ($user === null || !self::isUserActive($user)) {
            // Perform a dummy bcrypt verify to mitigate account enumeration via response timing.
            password_verify($password, self::DUMMY_BCRYPT_HASH);
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
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['last_activity'] = time();
        }
        return $user;
    }

    public function logout(): void
    {
        if (PHP_SAPI !== 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies') && !headers_sent()) {
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

        // Enforce automatic session expiration after the configured idle TTL (default 7200s).
        if (isset($_SESSION['last_activity'])) {
            if ((time() - (int)$_SESSION['last_activity']) > $this->sessionIdleTtl) {
                $this->logout();
                return null;
            }
        }
        $_SESSION['last_activity'] = time();

        $user = $this->storage->getUserById($uid);
        if ($user === null || !self::isUserActive($user)) {
            $this->logout();
            return null;
        }
        return $user;
    }

    public function getCurrentUser(): ?array
    {
        return $this->currentUser();
    }

    public function isValidRole(string $role): bool
    {
        return in_array($role, self::VALID_ROLES, true);
    }

    public function canAccessCourse(array $user, string $courseSlug): bool
    {
        if ($this->isStaff($user)) {
            return true;
        }
        // Only legacy users without an enrolled_courses key default to postgresql-18; explicit [] grants zero courses.
        if (!array_key_exists('enrolled_courses', $user) || $user['enrolled_courses'] === null) {
            $enrolled = ['postgresql-18'];
        } elseif (!is_array($user['enrolled_courses'])) {
            $enrolled = [];
        } else {
            $enrolled = $user['enrolled_courses'];
        }
        return in_array($courseSlug, $enrolled, true);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->storage->getUserById($userId);
        if ($user === null) {
            return ['success' => false, 'message' => 'Compte utilisateur introuvable.'];
        }
        if ($currentPassword === '' || !password_verify($currentPassword, (string)($user['password_hash'] ?? ''))) {
            return ['success' => false, 'message' => 'Le mot de passe actuel est incorrect.'];
        }
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.'];
        }

        $user['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->storage->saveUser($user);
        $this->storage->logAuditEvent((string)($user['email'] ?? ''), 'PASSWORD_CHANGE', 'Modification du mot de passe utilisateur');
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        return ['success' => true, 'message' => 'Mot de passe mis à jour avec succès.'];
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

        if (!$this->isValidRole($role)) {
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
