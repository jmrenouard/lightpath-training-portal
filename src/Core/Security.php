<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Security
{
    private static string $cliToken = '';

    public static function sendSecurityHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: unload=*, fullscreen=(self)');
    }

    /**
     * Intercepte l'enregistrement d'écouteurs 'unload' par les extensions navigateur tierces
     * (ex: chext_loader.js) et les redirige vers 'pagehide' pour éviter toute violation console.
     */
    public static function renderUnloadShimScript(): string
    {
        return '<script>(function(){try{var o=EventTarget.prototype.addEventListener;EventTarget.prototype.addEventListener=function(t,f,opt){if(t==="unload"){t="pagehide";}return o.call(this,t,f,opt);};}catch(_){}})();</script>';
    }

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

    /**
     * Vérifie strictement qu'un chemin résolu ($targetPath) se situe à l'intérieur
     * d'un répertoire racine autorisé ($baseDir), en prévenant toute évasion par préfixe partiel.
     */
    public static function isPathInside(string $targetPath, string $baseDir): bool
    {
        if ($targetPath === '' || $baseDir === '' || str_contains($targetPath, "\0") || str_contains($baseDir, "\0")) {
            return false;
        }
        $realTarget = realpath($targetPath);
        $realBase = realpath($baseDir);
        if ($realTarget === false || $realBase === false) {
            return false;
        }
        $normTarget = rtrim(str_replace('\\', '/', $realTarget), '/');
        $normBase = rtrim(str_replace('\\', '/', $realBase), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normTarget = strtolower($normTarget);
            $normBase = strtolower($normBase);
        }
        return $normTarget === $normBase || str_starts_with($normTarget, $normBase . '/');
    }

    public static function sanitizeFilename(string $filename): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', basename($filename));
        return ($clean !== null && $clean !== '') ? $clean : 'document.bin';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Formate de manière sécurisée (anti-XSS) un texte court contenant des blocs inline `code`, **gras** ou *italique*.
     */
    public static function formatInlineText(?string $value): string
    {
        $raw = (string)$value;
        if ($raw === '') {
            return '';
        }
        $raw = str_replace('\|', '|', $raw);
        $placeholders = [];
        $protected = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$placeholders): string {
            $key = '%%INLINE_CODE_' . count($placeholders) . '%%';
            $placeholders[$key] = '<code class="lms-inline-code">' . self::e($m[1]) . '</code>';
            return $key;
        }, $raw) ?? $raw;

        $escaped = self::e($protected);
        $escaped = preg_replace('/\*\*\*((?:(?!\*\*\*).)+)\*\*\*/u', '<strong><em>$1</em></strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*((?:(?!\*\*).)+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![\w*])\*([^\s*](?:[^*]*[^\s*])?)\*(?![\w*])/u', '<em>$1</em>', $escaped) ?? $escaped;

        foreach ($placeholders as $key => $html) {
            $escaped = str_replace($key, $html, $escaped);
        }
        return $escaped;
    }
}

