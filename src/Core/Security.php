<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Security
{
    private static string $cliToken = '';

    public static function getCsrfToken(): string
    {
        if (PHP_SAPI === 'cli') {
            if (self::$cliToken === '') {
                self::$cliToken = bin2hex(random_bytes(24));
            }
            return self::$cliToken;
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $expected = self::getCsrfToken();
        return hash_equals($expected, $token);
    }

    public static function isSafeRelativePath(string $path): bool
    {
        $norm = str_replace('\\', '/', trim($path));
        if ($norm === '' || str_starts_with($norm, '/') || str_contains($norm, '..') || str_contains($norm, "\0")) {
            return false;
        }
        if (preg_match('/^[a-zA-Z]:/', $norm)) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z0-9_.\-\/]+$/', $norm);
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
