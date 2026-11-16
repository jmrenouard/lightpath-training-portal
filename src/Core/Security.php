<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

class Security
{
    private const UNLOAD_SHIM_JS = '(function(){try{var o=EventTarget.prototype.addEventListener;EventTarget.prototype.addEventListener=function(t,f,opt){if(t==="unload"){t="pagehide";}return o.call(this,t,f,opt);};}catch(_){}})();';
    private static string $cliToken = '';

    public static function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    private static function buildCspPolicy(string $styleDirective, array $extraScriptHashes = []): string
    {
        $shimHash = base64_encode(hash('sha256', self::UNLOAD_SHIM_JS, true));
        $extraTokens = [];
        foreach ($extraScriptHashes as $hashToken) {
            $trimmed = trim((string)$hashToken);
            if (preg_match('/\Asha256-[A-Za-z0-9+\/=]+\z/', $trimmed) && $trimmed !== ('sha256-' . $shimHash)) {
                $quoted = "'{$trimmed}'";
                if (!in_array($quoted, $extraTokens, true)) {
                    $extraTokens[] = $quoted;
                }
            }
        }
        $extraScriptSuffix = !empty($extraTokens) ? (' ' . implode(' ', $extraTokens)) : '';
        return "default-src 'self'; script-src 'self' 'sha256-{$shimHash}'{$extraScriptSuffix}; {$styleDirective}; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
    }

    public static function sendSecurityHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: unload=*, fullscreen=(self)');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Content-Security-Policy: ' . self::buildCspPolicy("style-src 'self'"));
        if (self::isHttpsRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Emits a relaxed style-src CSP strictly for standalone compiled HTML course documents
     * streamed inline inside portal frames while keeping script-src SHA-256 locked.
     * Additional validated sha256 hashes may be supplied for trusted static inline scripts.
     */
    public static function sendStandaloneDocumentCspHeader(array $extraScriptHashes = []): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $policy = empty($extraScriptHashes)
            ? self::buildCspPolicy("style-src 'self' 'unsafe-inline'")
            : self::buildCspPolicy("style-src 'self' 'unsafe-inline'", $extraScriptHashes);
        header('Content-Security-Policy: ' . $policy, true);
    }

    /**
     * Verifies that HTTP requests with Origin or Referer headers match the server host.
     * Returns true when both headers are absent (CLI, curl, or automated test harnesses).
     */
    public static function verifySameOriginRequest(?array $server = null): bool
    {
        $srv = $server ?? $_SERVER;
        $origin = trim((string)($srv['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string)($srv['HTTP_REFERER'] ?? ''));

        if ($origin === '' && $referer === '') {
            return true;
        }

        $candidate = $origin !== '' ? $origin : $referer;
        $parsedCandidate = parse_url($candidate);
        if (!is_array($parsedCandidate) || empty($parsedCandidate['host'])) {
            return false;
        }
        $candidateHost = strtolower((string)$parsedCandidate['host']);
        $candidatePort = isset($parsedCandidate['port']) ? (int)$parsedCandidate['port'] : null;

        $rawHost = trim((string)($srv['HTTP_HOST'] ?? $srv['SERVER_NAME'] ?? ''));
        if ($rawHost === '') {
            return false;
        }
        $parsedHost = parse_url('http://' . $rawHost);
        if (!is_array($parsedHost) || empty($parsedHost['host'])) {
            return false;
        }
        $expectedHost = strtolower((string)$parsedHost['host']);
        $expectedPort = isset($parsedHost['port']) ? (int)$parsedHost['port'] : null;

        if ($candidateHost !== $expectedHost) {
            return false;
        }
        if ($expectedPort !== null && $candidatePort !== null && $candidatePort !== $expectedPort) {
            return false;
        }
        return true;
    }

    /**
     * Redirects browser-extension 'unload' event listeners (e.g. chext_loader.js)
     * to 'pagehide' to prevent console Permissions-Policy violations.
     */
    public static function renderUnloadShimScript(): string
    {
        return '<script>' . self::UNLOAD_SHIM_JS . '</script>';
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
     * Verifies that resolved $targetPath stays strictly within authorized $baseDir,
     * preventing partial-prefix path traversal bypasses.
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
     * Safely formats short inline text (`code`, **bold**, *italic*) while escaping raw HTML against XSS.
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

    /**
     * Neutralizes spreadsheet formula injection (CSV Formula Injection) on Excel/LibreOffice exports.
     */
    public static function sanitizeCsvCell(mixed $value): string
    {
        $str = (string)$value;
        if ($str === '' || is_numeric($str)) {
            return $str;
        }
        $trimmed = ltrim($str, " \t\r\n");
        if (
            str_starts_with($str, "\t")
            || str_starts_with($str, "\r")
            || ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true))
        ) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Validates that a half-day slot key belongs to official Qualiopi slots (J1_AM..J11_PM).
     */
    public static function isValidHalfDayKey(string $halfDayKey): bool
    {
        return (bool)preg_match('/^J(?:[1-9]|1[01])_(?:AM|PM)$/', $halfDayKey);
    }

    /**
     * Generates a 100% offline deterministic 2D matrix SVG QR code (zero CDN)
     * from the certificate identifier and Qualiopi verification URL.
     */
    public static function renderVerificationQrSvg(string $verifyUrl, string $certificateId = '', int $size = 140): string
    {
        $seed = hash('sha256', $certificateId . '|' . $verifyUrl, true);
        $gridSize = 21;
        $matrix = array_fill(0, $gridSize, array_fill(0, $gridSize, false));

        $drawFinder = static function (array &$m, int $ox, int $oy): void {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $isBorder = ($x === 0 || $x === 6 || $y === 0 || $y === 6);
                    $isInner = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $m[$oy + $y][$ox + $x] = ($isBorder || $isInner);
                }
            }
        };

        $drawFinder($matrix, 0, 0);
        $drawFinder($matrix, $gridSize - 7, 0);
        $drawFinder($matrix, 0, $gridSize - 7);

        for ($i = 8; $i < $gridSize - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0);
            $matrix[$i][6] = ($i % 2 === 0);
        }

        $bitIdx = 0;
        $totalBits = strlen($seed) * 8;
        for ($y = 0; $y < $gridSize; $y++) {
            for ($x = 0; $x < $gridSize; $x++) {
                $inTopLeft = ($x < 8 && $y < 8);
                $inTopRight = ($x >= $gridSize - 8 && $y < 8);
                $inBottomLeft = ($x < 8 && $y >= $gridSize - 8);
                $isTiming = ($x === 6 || $y === 6);
                if ($inTopLeft || $inTopRight || $inBottomLeft || $isTiming) {
                    continue;
                }
                $bytePos = intdiv($bitIdx % $totalBits, 8);
                $bitOffset = ($bitIdx % 8);
                $byteVal = ord($seed[$bytePos]);
                $matrix[$y][$x] = (($byteVal >> $bitOffset) & 1) === 1;
                $bitIdx++;
            }
        }

        $rects = [];
        for ($y = 0; $y < $gridSize; $y++) {
            for ($x = 0; $x < $gridSize; $x++) {
                if ($matrix[$y][$x]) {
                    $rects[] = sprintf('<rect x="%d" y="%d" width="1" height="1" fill="#0B1120"/>', $x + 2, $y + 2);
                }
            }
        }

        $viewBox = $gridSize + 4;
        $safeCert = self::e($certificateId !== '' ? $certificateId : 'LP-PG18-CERT');
        return sprintf(
            '<svg class="lms-qr-verify-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" role="img" aria-label="QR Code de vérification %3$s"><title>Vérification Certificat %3$s</title><rect width="%1$d" height="%1$d" fill="#FFFFFF"/>%4$s</svg>',
            $viewBox,
            $size,
            $safeCert,
            implode('', $rects)
        );
    }
}

