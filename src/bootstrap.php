<?php
declare(strict_types=1);

/**
 * Bootstrap & Autoloader PSR-4 autonome pour LightPath Training Portal
 */

// FIX-13: Fuseau horaire officiel Europe/Paris pour conformité des créneaux OPCO/Qualiopi
date_default_timezone_set('Europe/Paris');

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

    // FIX-05: Activer le flag Secure sur HTTPS / reverse-proxy
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isSecure,
    ]);
    session_start();
}

\LightPath\LMS\Core\Security::sendSecurityHeaders();
