<?php
declare(strict_types=1);

/**
 * Bootstrap & Autoloader PSR-4 autonome pour LightPath Training Portal
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'LightPath\\LMS\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

\LightPath\LMS\Core\Security::sendSecurityHeaders();
