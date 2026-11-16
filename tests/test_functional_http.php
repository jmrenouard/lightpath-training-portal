<?php
declare(strict_types=1);

/**
 * End-to-End Functional HTTP Test Suite — LightPath Training Portal (LMS PHP 8.3)
 *
 * Boots an ephemeral built-in PHP HTTP server against public/ with an isolated
 * temporary LMS_DATA_DIR and LMS_DOCKER_FORCE_SIMULATED=1, then exercises full
 * GET/POST/API/RBAC/CSRF/Session workflows using real HTTP requests and cookies.
 *
 * Usage: php tests/test_functional_http.php
 */

$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$publicDir = $repoRoot . DIRECTORY_SEPARATOR . 'public';

class HttpTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $failures = [];

    public function assertTrue(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $msg = $detail !== '' ? "{$label} ({$detail})" : $label;
        $this->failures[] = $msg;
        echo "  [FAIL] {$msg}\n";
    }

    public function assertEquals(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $msg = sprintf(
            '%s (expected=%s, actual=%s)',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
        $this->failures[] = $msg;
        echo "  [FAIL] {$msg}\n";
    }

    public function getPassed(): int
    {
        return $this->passed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function printSummary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n========================================================================\n";
        echo sprintf(
            " HTTP FUNCTIONAL SUITE: %d assertions | %d passed | %d failed\n",
            $total,
            $this->passed,
            $this->failed
        );
        echo "========================================================================\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

class EphemeralHttpClient
{
    private string $host;
    private int $port;
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function resetCookies(): void
    {
        $this->cookies = [];
    }

    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string, list<string>>, body:string, final_path:string}
     */
    public function request(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        bool $followRedirects = true,
        int $maxHops = 6
    ): array {
        $currentMethod = strtoupper($method);
        $currentPath = str_starts_with($path, '/') ? $path : '/' . ltrim($path, '/');
        $currentBody = $body;
        $currentHeaders = $headers;

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $resp = $this->sendOnce($currentMethod, $currentPath, $currentBody, $currentHeaders);
            $status = $resp['status'];
            $location = $this->firstHeader($resp['headers'], 'location');

            if (!$followRedirects || $location === null || !in_array($status, [301, 302, 303, 307, 308], true)) {
                $resp['final_path'] = $currentPath;
                return $resp;
            }

            if ($status !== 307 && $status !== 308) {
                $currentMethod = 'GET';
                $currentBody = null;
                unset($currentHeaders['Content-Type'], $currentHeaders['Content-Length']);
            }

            if (preg_match('/^https?:\/\/[^\/]+(\/.*)$/i', $location, $m)) {
                $currentPath = $m[1];
            } elseif (str_starts_with($location, '/')) {
                $currentPath = $location;
            } else {
                $currentPath = '/' . ltrim($location, '/');
            }
        }

        $resp['final_path'] = $currentPath;
        return $resp;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string, list<string>>, body:string}
     */
    private function sendOnce(string $method, string $path, array|string|null $body, array $headers): array
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($sock)) {
            throw new RuntimeException("Failed connecting to http://{$this->host}:{$this->port}: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, 30);

        $rawBody = '';
        $normalizedHeaders = [];
        foreach ($headers as $k => $v) {
            $normalizedHeaders[strtolower($k)] = [$k, $v];
        }

        if (is_array($body)) {
            $ct = $normalizedHeaders['content-type'][1] ?? '';
            if (stripos($ct, 'application/json') !== false) {
                $rawBody = (string)json_encode($body);
            } else {
                $rawBody = http_build_query($body);
                $normalizedHeaders['content-type'] = ['Content-Type', 'application/x-www-form-urlencoded'];
            }
        } elseif (is_string($body)) {
            $rawBody = $body;
        }

        if ($rawBody !== '' && !isset($normalizedHeaders['content-type'])) {
            $normalizedHeaders['content-type'] = ['Content-Type', 'application/x-www-form-urlencoded'];
        }
        if ($rawBody !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $normalizedHeaders['content-length'] = ['Content-Length', (string)strlen($rawBody)];
        }

        $normalizedHeaders['host'] = ['Host', "{$this->host}:{$this->port}"];
        $normalizedHeaders['connection'] = ['Connection', 'close'];
        if (!empty($this->cookies)) {
            $pairs = [];
            foreach ($this->cookies as $cn => $cv) {
                $pairs[] = $cn . '=' . $cv;
            }
            $normalizedHeaders['cookie'] = ['Cookie', implode('; ', $pairs)];
        }

        $reqLines = ["{$method} {$path} HTTP/1.1"];
        foreach ($normalizedHeaders as [$origName, $val]) {
            $reqLines[] = "{$origName}: {$val}";
        }
        $wire = implode("\r\n", $reqLines) . "\r\n\r\n" . $rawBody;

        $written = 0;
        $totalLen = strlen($wire);
        while ($written < $totalLen) {
            $n = fwrite($sock, substr($wire, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }

        $rawResp = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($sock);
                if (!empty($meta['timed_out'])) {
                    break;
                }
                continue;
            }
            $rawResp .= $chunk;
        }
        fclose($sock);

        $sepPos = strpos($rawResp, "\r\n\r\n");
        if ($sepPos === false) {
            throw new RuntimeException("Malformed HTTP response from {$method} {$path}: " . substr($rawResp, 0, 200));
        }

        $headBlock = substr($rawResp, 0, $sepPos);
        $respBody = substr($rawResp, $sepPos + 4);
        $headLines = preg_split('/\r\n/', $headBlock) ?: [];
        $statusLine = (string)array_shift($headLines);
        $statusCode = 0;
        if (preg_match('/HTTP\/\d+\.\d+\s+(\d{3})/', $statusLine, $sm)) {
            $statusCode = (int)$sm[1];
        }

        $respHeaders = [];
        foreach ($headLines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $hName = strtolower(trim(substr($line, 0, $colon)));
            $hVal = trim(substr($line, $colon + 1));
            $respHeaders[$hName][] = $hVal;

            if ($hName === 'set-cookie') {
                $semi = strpos($hVal, ';');
                $cookieKv = $semi !== false ? substr($hVal, 0, $semi) : $hVal;
                $eq = strpos($cookieKv, '=');
                if ($eq !== false) {
                    $cName = trim(substr($cookieKv, 0, $eq));
                    $cVal = trim(substr($cookieKv, $eq + 1));
                    if ($cVal === '' || stripos($hVal, 'Max-Age=0') !== false) {
                        unset($this->cookies[$cName]);
                    } else {
                        $this->cookies[$cName] = $cVal;
                    }
                }
            }
        }

        $te = strtolower($this->firstHeader($respHeaders, 'transfer-encoding') ?? '');
        if (str_contains($te, 'chunked')) {
            $respBody = $this->decodeChunkedBody($respBody);
        }

        return [
            'status' => $statusCode,
            'headers' => $respHeaders,
            'body' => $respBody,
        ];
    }

    private function decodeChunkedBody(string $raw): string
    {
        $decoded = '';
        $pos = 0;
        $len = strlen($raw);
        while ($pos < $len) {
            $crlf = strpos($raw, "\r\n", $pos);
            if ($crlf === false) {
                break;
            }
            $sizeHex = trim(explode(';', substr($raw, $pos, $crlf - $pos))[0]);
            $chunkSize = (int)hexdec($sizeHex);
            $pos = $crlf + 2;
            if ($chunkSize <= 0) {
                break;
            }
            $decoded .= substr($raw, $pos, $chunkSize);
            $pos += $chunkSize + 2;
        }
        return $decoded;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public function firstHeader(array $headers, string $name): ?string
    {
        $vals = $headers[strtolower($name)] ?? [];
        return $vals[0] ?? null;
    }

    public static function extractCsrf(string $html): string
    {
        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
}

$removeDirRecursive = static function (string $dir) use (&$removeDirRecursive): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $removeDirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};

// Allocate a free ephemeral loopback port safely.
$probeSock = @stream_socket_server('tcp://127.0.0.1:0', $probeErr, $probeMsg);
if (!is_resource($probeSock)) {
    fwrite(STDERR, "Failed allocating ephemeral TCP port: {$probeMsg}\n");
    exit(1);
}
$sockName = (string)stream_socket_get_name($probeSock, false);
fclose($probeSock);
$parts = explode(':', $sockName);
$port = (int)end($parts);
if ($port <= 0) {
    fwrite(STDERR, "Invalid ephemeral port resolved from '{$sockName}'.\n");
    exit(1);
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lms_http_e2e_' . getmypid() . '_' . mt_rand(1000, 9999);
$tmpDataDir = $tmpRoot . DIRECTORY_SEPARATOR . 'data';
$tmpCacheDir = $tmpRoot . DIRECTORY_SEPARATOR . 'cache';
mkdir($tmpDataDir, 0777, true);
mkdir($tmpCacheDir, 0777, true);

$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['LMS_DATA_DIR'] = $tmpDataDir;
$env['LMS_DOCKER_FORCE_SIMULATED'] = '1';
$env['LMS_INIT_ADMIN_PASSWORD'] = 'LightPath2026!';
$env['LMS_INIT_TRAINER_PASSWORD'] = 'Formateur2026!';
$env['LMS_INIT_STUDENT_PASSWORD'] = 'Stagiaire2026!';

$proc = null;
$serverOutLog = $tmpRoot . DIRECTORY_SEPARATOR . 'php_server_out.log';
$serverErrLog = $tmpRoot . DIRECTORY_SEPARATOR . 'php_server_err.log';
$runner = new HttpTestRunner();

echo "========================================================================\n";
echo " LIGHTPATH LMS — END-TO-END FUNCTIONAL HTTP TEST SUITE\n";
echo " Server: http://127.0.0.1:{$port} | DataDir: {$tmpDataDir}\n";
echo "========================================================================\n\n";

try {
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $publicDir];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverOutLog, 'w'],
        2 => ['file', $serverErrLog, 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to spawn built-in PHP HTTP server.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    // Wait up to 8 seconds for the server TCP socket to accept connections.
    $ready = false;
    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $testConn = @stream_socket_client("tcp://127.0.0.1:{$port}", $ce, $cm, 0.25);
        if (is_resource($testConn)) {
            fclose($testConn);
            $ready = true;
            break;
        }
        $procStatus = proc_get_status($proc);
        if (empty($procStatus['running'])) {
            $stderr = is_file($serverErrLog) ? (string)file_get_contents($serverErrLog) : '';
            throw new RuntimeException("Ephemeral PHP server exited prematurely: {$stderr}");
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException("Timed out waiting for php -S on 127.0.0.1:{$port}");
    }

    // ------------------------------------------------------------------------
    // 1. Public Unauthenticated Endpoints & Security Headers
    // ------------------------------------------------------------------------
    echo "[1/6] Public pages (?page=verify, ?page=login) & security headers...\n";
    $client = new EphemeralHttpClient('127.0.0.1', $port);

    $verifyResp = $client->request('GET', '/index.php?page=verify');
    $runner->assertEquals(200, $verifyResp['status'], 'GET ?page=verify returns HTTP 200');
    $runner->assertTrue(
        str_contains($verifyResp['body'], 'Vérification'),
        'GET ?page=verify renders public verification title'
    );
    $verifyCsp = (string)($client->firstHeader($verifyResp['headers'], 'content-security-policy') ?? '');
    $verifyScriptSrc = preg_match('/script-src\s+([^;]+)/i', $verifyCsp, $verifyScriptMatch) ? $verifyScriptMatch[0] : '';
    $verifyStyleSrc = preg_match('/style-src\s+([^;]+)/i', $verifyCsp, $verifyStyleMatch) ? $verifyStyleMatch[0] : '';
    $runner->assertTrue(
        $verifyScriptSrc !== ''
        && str_contains($verifyScriptSrc, "'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='")
        && !str_contains($verifyScriptSrc, 'unsafe-inline')
        && $verifyStyleSrc !== ''
        && str_contains($verifyStyleSrc, "'self'")
        && !str_contains($verifyStyleSrc, 'unsafe-inline'),
        'GET ?page=verify CSP enforces strict script-src SHA-256 and style-src self without unsafe-inline'
    );
    $runner->assertEquals(
        0,
        preg_match_all('/\bstyle\s*=/i', $verifyResp['body']),
        'GET ?page=verify HTML contains zero inline style= attributes'
    );
    $runner->assertEquals(
        'same-origin',
        $client->firstHeader($verifyResp['headers'], 'cross-origin-opener-policy'),
        'GET ?page=verify sends Cross-Origin-Opener-Policy: same-origin'
    );

    $loginPage = $client->request('GET', '/index.php?page=login');
    $runner->assertEquals(200, $loginPage['status'], 'GET ?page=login returns HTTP 200');
    $runner->assertTrue(
        $client->firstHeader($loginPage['headers'], 'content-security-policy') !== null,
        'GET ?page=login sends Content-Security-Policy header'
    );
    $runner->assertEquals(
        'same-origin',
        $client->firstHeader($loginPage['headers'], 'cross-origin-opener-policy'),
        'GET ?page=login sends Cross-Origin-Opener-Policy: same-origin'
    );
    $runner->assertTrue(
        EphemeralHttpClient::extractCsrf($loginPage['body']) !== '',
        'GET ?page=login embeds a non-empty CSRF token'
    );
    preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $loginPage['body'], $loginScripts, PREG_SET_ORDER);
    $unexpectedInlineScript = false;
    foreach ($loginScripts as $scriptMatch) {
        $attrs = (string)($scriptMatch[1] ?? '');
        $scriptBody = (string)($scriptMatch[2] ?? '');
        if (!preg_match('/\bsrc\s*=/i', $attrs) && !str_contains($scriptBody, 'EventTarget.prototype.addEventListener')) {
            $unexpectedInlineScript = true;
        }
    }
    $runner->assertTrue(
        str_contains($loginPage['body'], '<script src="assets/js/app.js"') && !$unexpectedInlineScript,
        'GET ?page=login loads external app.js without any unexpected inline <script> blocks'
    );
    $loginCsp = (string)($client->firstHeader($loginPage['headers'], 'content-security-policy') ?? '');
    $loginScriptSrc = preg_match('/script-src\s+([^;]+)/i', $loginCsp, $loginCspMatch) ? $loginCspMatch[0] : '';
    $loginStyleSrc = preg_match('/style-src\s+([^;]+)/i', $loginCsp, $loginStyleMatch) ? $loginStyleMatch[0] : '';
    $runner->assertTrue(
        $loginScriptSrc !== ''
        && str_contains($loginScriptSrc, "'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='")
        && !str_contains($loginScriptSrc, 'unsafe-inline'),
        'GET ?page=login CSP script-src uses unload shim SHA-256 hash without unsafe-inline'
    );
    $runner->assertTrue(
        $loginStyleSrc !== ''
        && str_contains($loginStyleSrc, "'self'")
        && !str_contains($loginStyleSrc, 'unsafe-inline'),
        'GET ?page=login CSP style-src enforces self without unsafe-inline'
    );
    $runner->assertEquals(
        0,
        preg_match_all('/\bstyle\s*=/i', $loginPage['body']),
        'GET ?page=login HTML contains zero inline style= attributes'
    );

    // Cross-origin POST rejection checks (form login + JSON API).
    $crossOriginLogin = $client->request(
        'POST',
        '/index.php?action=login',
        [
            'csrf_token' => EphemeralHttpClient::extractCsrf($loginPage['body']),
            'email' => 'stagiaire@lightpath.fr',
            'password' => 'Stagiaire2026!',
        ],
        ['Origin' => 'https://evil.example'],
        false
    );
    $runner->assertEquals(403, $crossOriginLogin['status'], 'Cross-origin form POST (Origin: https://evil.example) is rejected with HTTP 403');

    // ------------------------------------------------------------------------
    // 2. Cookie-less Brute-Force Lockout Across Fresh HTTP Clients
    // ------------------------------------------------------------------------
    echo "[2/6] Cookie-less brute-force lockout across 6 rapid failed logins...\n";
    $lockedDetected = false;
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $freshClient = new EphemeralHttpClient('127.0.0.1', $port);
        $lp = $freshClient->request('GET', '/index.php?page=login');
        $csrf = EphemeralHttpClient::extractCsrf($lp['body']);
        $postResp = $freshClient->request('POST', '/index.php?action=login', [
            'csrf_token' => $csrf,
            'email' => 'bruteforce.target@lightpath.fr',
            'password' => 'WrongPass!' . $attempt,
        ]);
        $runner->assertEquals(200, $postResp['status'], "Attempt #{$attempt} resolves cleanly via HTTP 302 -> 200");
        if (str_contains($postResp['body'], 'Trop de tentatives de connexion')) {
            $lockedDetected = true;
        }
    }
    $runner->assertTrue(
        $lockedDetected,
        'Cookie-less brute-force lockout triggers on attempt 6 across separate fresh HTTP clients'
    );

    // ------------------------------------------------------------------------
    // 3. Student Workflow (Login, Module, Lab, JSON APIs, Profile Password, RBAC)
    // ------------------------------------------------------------------------
    echo "[3/6] Student E2E workflow (stagiaire@lightpath.fr)...\n";
    $studentClient = new EphemeralHttpClient('127.0.0.1', $port);
    $sLoginPage = $studentClient->request('GET', '/index.php?page=login');
    $sCsrfLogin = EphemeralHttpClient::extractCsrf($sLoginPage['body']);
    $sDash = $studentClient->request('POST', '/index.php?action=login', [
        'csrf_token' => $sCsrfLogin,
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'Stagiaire2026!',
    ]);
    $runner->assertEquals(200, $sDash['status'], 'Student login lands on dashboard HTTP 200');
    $runner->assertTrue(
        str_contains($sDash['final_path'], 'page=dashboard'),
        'Student login redirects to ?page=dashboard'
    );

    $modView = $studentClient->request('GET', '/index.php?page=module&course=postgresql-18&id=module_01_introduction_postgresql_18');
    $runner->assertEquals(200, $modView['status'], 'Student accesses module view HTTP 200');

    $labView = $studentClient->request('GET', '/index.php?page=lab&course=postgresql-18&id=lab_01_introduction_setup_postgresql_18');
    $runner->assertEquals(200, $labView['status'], 'Student accesses lab view HTTP 200');
    $runner->assertTrue(str_contains($labView['body'], 'postgres'), 'Lab view shows postgres container password');
    $runner->assertTrue(str_contains($labView['body'], 'id="btn-seed-lab"'), 'Lab view contains #btn-seed-lab');
    $runner->assertTrue(str_contains($labView['body'], 'id="btn-docker-logs"'), 'Lab view contains #btn-docker-logs');
    $runner->assertTrue(
        str_contains($labView['body'], 'id="lab-savepoint-name"')
        && str_contains($labView['body'], 'id="btn-savepoint-create"')
        && str_contains($labView['body'], 'id="btn-run-pgbench"')
        && str_contains($labView['body'], 'id="btn-pg-diag"')
        && str_contains($labView['body'], 'id="btn-check-readiness"'),
        'Lab view includes secondary workbench controls (savepoint, pgbench, PG18 diag, readiness)'
    );
    $runner->assertEquals(
        0,
        preg_match_all('/\bstyle\s*=/i', $labView['body']),
        'Lab view contains zero inline style= attributes'
    );
    $runner->assertTrue(
        !str_contains($labView['body'], 'postgres_master_pw'),
        'Lab view does NOT expose deprecated postgres_master_pw'
    );

    $apiCsrf = EphemeralHttpClient::extractCsrf($labView['body']);
    $runner->assertTrue($apiCsrf !== '', 'Lab view provides CSRF token for API calls');

    $crossOriginApi = $studentClient->request(
        'POST',
        '/index.php?action=api_save_note',
        ['item_id' => 'module_01_introduction_postgresql_18', 'note' => 'x'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf, 'Origin' => 'https://evil.example'],
        false
    );
    $runner->assertEquals(403, $crossOriginApi['status'], 'Cross-origin JSON API POST (Origin: https://evil.example) is rejected with HTTP 403');

    $dlMod = $studentClient->request(
        'GET',
        '/index.php?action=download&course=postgresql-18&type=module&id=module_01_introduction_postgresql_18&format=md'
    );
    $runner->assertEquals(200, $dlMod['status'], 'Student downloads module Markdown cleanly via DownloadController HTTP 200');

    // Standalone inline HTML preview stream uses standalone CSP cleanly while keeping script-src SHA-256 locked.
    $inlineHtmlResp = $studentClient->request(
        'GET',
        '/index.php?action=download&course=postgresql-18&type=module&id=module_01_introduction_postgresql_18&format=html&inline=1'
    );
    $runner->assertEquals(200, $inlineHtmlResp['status'], 'Inline HTML preview download returns HTTP 200');
    $inlineCsp = (string)($studentClient->firstHeader($inlineHtmlResp['headers'], 'content-security-policy') ?? '');
    $inlineScriptSrc = preg_match('/script-src\s+([^;]+)/i', $inlineCsp, $inSm) ? $inSm[0] : '';
    $inlineStyleSrc = preg_match('/style-src\s+([^;]+)/i', $inlineCsp, $inStm) ? $inStm[0] : '';
    $hasMermaidInitScript = str_contains($inlineHtmlResp['body'], 'mermaid.initialize');
    $runner->assertTrue(
        str_contains($inlineHtmlResp['body'], 'EventTarget.prototype.addEventListener')
        && str_contains($inlineStyleSrc, "'unsafe-inline'")
        && str_contains($inlineScriptSrc, "'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='")
        && (!$hasMermaidInitScript || str_contains($inlineScriptSrc, "'sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA='"))
        && !str_contains($inlineScriptSrc, 'unsafe-inline'),
        'Standalone HTML preview emits standalone style-src CSP while keeping script-src SHA-256 strict'
    );

    // HTTP method enforcement: GET against mutating api_run_sql rejects with 405 + Allow: POST; read-only api_docker_status allows GET.
    $getMutatingApi = $studentClient->request(
        'GET',
        '/index.php?action=api_run_sql&sql=SELECT%201&csrf_token=' . urlencode($apiCsrf),
        null,
        [],
        false
    );
    $getMutatingJson = json_decode($getMutatingApi['body'], true);
    $runner->assertEquals(405, $getMutatingApi['status'], 'GET request to mutating api_run_sql is rejected with HTTP 405');
    $runner->assertEquals(
        'POST',
        strtoupper((string)($studentClient->firstHeader($getMutatingApi['headers'], 'allow') ?? '')),
        'HTTP 405 response for GET api_run_sql emits Allow: POST header'
    );
    $runner->assertTrue(
        is_array($getMutatingJson) && ($getMutatingJson['success'] ?? true) === false,
        'HTTP 405 response for GET api_run_sql returns JSON error payload'
    );

    $getReadOnlyApi = $studentClient->request(
        'GET',
        '/index.php?action=api_docker_status&csrf_token=' . urlencode($apiCsrf)
    );
    $getReadOnlyJson = json_decode($getReadOnlyApi['body'], true);
    $runner->assertEquals(200, $getReadOnlyApi['status'], 'Read-only GET api_docker_status still returns HTTP 200');
    $runner->assertTrue(
        is_array($getReadOnlyJson) && ($getReadOnlyJson['success'] ?? false) === true,
        'Read-only GET api_docker_status returns valid Docker status JSON'
    );

    // HTTP Range 206 & 416 checks over real MP4 stream.
    $mp4Range206 = $studentClient->request(
        'GET',
        '/index.php?action=download&course=postgresql-18&type=module&id=module_01_introduction_postgresql_18&format=video_mp4&inline=1',
        null,
        ['Range' => 'bytes=0-63']
    );
    $runner->assertEquals(206, $mp4Range206['status'], 'Video MP4 Range bytes=0-63 returns HTTP 206 Partial Content');
    $runner->assertTrue(
        strlen($mp4Range206['body']) === 64
        && str_starts_with((string)($studentClient->firstHeader($mp4Range206['headers'], 'content-range') ?? ''), 'bytes 0-63/'),
        'Video MP4 HTTP 206 response serves exact 64-byte slice with Content-Range header'
    );

    $mp4Range416 = $studentClient->request(
        'GET',
        '/index.php?action=download&course=postgresql-18&type=module&id=module_01_introduction_postgresql_18&format=video_mp4&inline=1',
        null,
        ['Range' => 'bytes=999999999-9999999999']
    );
    $runner->assertEquals(416, $mp4Range416['status'], 'Video MP4 unsatisfiable Range returns HTTP 416 Range Not Satisfiable');
    $runner->assertTrue(
        str_starts_with((string)($studentClient->firstHeader($mp4Range416['headers'], 'content-range') ?? ''), 'bytes */'),
        'Video MP4 HTTP 416 response includes Content-Range: bytes */<size>'
    );

    // Student Lab Workbench new endpoints: savepoint, pgbench, pg_diag, readiness.
    $spCreateResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_savepoint',
        ['op' => 'create', 'name' => 'http_sp_1', 'db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $spCreateJson = json_decode($spCreateResp['body'], true);
    $runner->assertEquals(200, $spCreateResp['status'], 'Student api_lab_savepoint op=create returns HTTP 200');
    $runner->assertTrue(
        is_array($spCreateJson)
        && ($spCreateJson['success'] ?? false) === true
        && ($spCreateJson['name'] ?? '') === 'http_sp_1'
        && (int)($spCreateJson['count'] ?? 0) === 1,
        'Student api_lab_savepoint op=create returns clean scalar name without PHP warnings'
    );

    $spListResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_savepoint',
        ['op' => 'list'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $spListJson = json_decode($spListResp['body'], true);
    $runner->assertTrue(
        $spListResp['status'] === 200 && is_array($spListJson) && ($spListJson['count'] ?? 0) === 1,
        'Student api_lab_savepoint op=list lists created savepoint'
    );

    $spRestoreResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_savepoint',
        ['op' => 'restore', 'name' => 'http_sp_1', 'db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $spRestoreJson = json_decode($spRestoreResp['body'], true);
    $runner->assertTrue(
        $spRestoreResp['status'] === 200
        && is_array($spRestoreJson)
        && ($spRestoreJson['success'] ?? false) === true
        && ($spRestoreJson['name'] ?? '') === 'http_sp_1',
        'Student api_lab_savepoint op=restore succeeds with scalar name'
    );

    $spDeleteResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_savepoint',
        ['op' => 'delete', 'name' => 'http_sp_1'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $spDeleteJson = json_decode($spDeleteResp['body'], true);
    $runner->assertTrue(
        $spDeleteResp['status'] === 200 && is_array($spDeleteJson) && ($spDeleteJson['count'] ?? -1) === 0,
        'Student api_lab_savepoint op=delete removes savepoint cleanly'
    );

    $pgbenchResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_pgbench',
        ['clients' => 4, 'duration' => 2, 'select_only' => false, 'db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $pgbenchJson = json_decode($pgbenchResp['body'], true);
    $runner->assertEquals(200, $pgbenchResp['status'], 'Student api_lab_pgbench returns HTTP 200');
    $runner->assertTrue(
        is_array($pgbenchJson) && ($pgbenchJson['success'] ?? false) === true && (float)($pgbenchJson['tps'] ?? 0) > 0,
        'Student api_lab_pgbench returns TPS and latency telemetry'
    );

    $pgDiagResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_pg_diag',
        ['db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $pgDiagJson = json_decode($pgDiagResp['body'], true);
    $runner->assertEquals(200, $pgDiagResp['status'], 'Student api_lab_pg_diag returns HTTP 200');
    $runner->assertTrue(
        is_array($pgDiagJson)
        && ($pgDiagJson['success'] ?? false) === true
        && ($pgDiagJson['settings']['io_method'] ?? '') === 'worker',
        'Student api_lab_pg_diag returns PG18 diagnostics payload'
    );

    $readinessResp = $studentClient->request(
        'POST',
        '/index.php?action=api_lab_readiness',
        ['lab_id' => 'lab_sql_02_manipulation_dml_transactions'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $readinessJson = json_decode($readinessResp['body'], true);
    $runner->assertEquals(200, $readinessResp['status'], 'Student api_lab_readiness returns HTTP 200');
    $runner->assertTrue(
        is_array($readinessJson)
        && ($readinessJson['success'] ?? false) === true
        && ($readinessJson['recommended_database'] ?? '') === 'dvdrental'
        && ($readinessJson['oracle_found'] ?? false) === true,
        'Student api_lab_readiness maps SQL lab prerequisites to dvdrental'
    );

    $schemaResp = $studentClient->request(
        'POST',
        '/index.php?action=api_schema_inspect',
        ['database' => 'dvdrental'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(200, $schemaResp['status'], 'api_schema_inspect returns HTTP 200');
    $schemaJson = json_decode($schemaResp['body'], true);
    $runner->assertTrue(
        is_array($schemaJson) && !empty($schemaJson['success']) && !empty($schemaJson['source'])
        && isset($schemaJson['tables']) && is_array($schemaJson['tables']) && array_is_list($schemaJson['tables'])
        && count($schemaJson['tables']) > 0,
        'api_schema_inspect returns source string and indexed tables list'
    );

    $seedResp = $studentClient->request(
        'POST',
        '/index.php?action=api_seed_lab',
        ['dataset' => 'all'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(200, $seedResp['status'], 'api_seed_lab returns HTTP 200 cleanly');
    $seedJson = json_decode($seedResp['body'], true);
    $runner->assertTrue(
        is_array($seedJson) && ($seedJson['simulated'] ?? false) === true
        && ($seedJson['dataset'] ?? '') === 'all' && !empty($seedJson['catalog']),
        'api_seed_lab returns structured simulated dataset catalog payload without errors'
    );

    $logsResp = $studentClient->request(
        'POST',
        '/index.php?action=api_docker_logs',
        ['tail' => 50],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(200, $logsResp['status'], 'api_docker_logs returns HTTP 200');
    $logsJson = json_decode($logsResp['body'], true);
    $runner->assertTrue(
        is_array($logsJson) && !empty($logsJson['success']) && !empty($logsJson['logs']),
        'api_docker_logs returns logs JSON payload'
    );

    $sqlCopyResp = $studentClient->request(
        'POST',
        '/index.php?action=api_run_sql',
        ['sql' => "COPY public.actor FROM PROGRAM 'id'", 'database' => 'dvdrental'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $sqlCopyJson = json_decode($sqlCopyResp['body'], true);
    $runner->assertTrue(
        is_array($sqlCopyJson) && ($sqlCopyJson['success'] ?? true) === false
        && ($sqlCopyJson['container'] ?? '') === 'blocked',
        'api_run_sql blocks COPY ... FROM PROGRAM'
    );

    $sqlPipeResp = $studentClient->request(
        'POST',
        '/index.php?action=api_run_sql',
        ['sql' => 'SELECT 1 \\g |id', 'database' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $sqlPipeJson = json_decode($sqlPipeResp['body'], true);
    $runner->assertTrue(
        is_array($sqlPipeJson) && ($sqlPipeJson['success'] ?? true) === false
        && ($sqlPipeJson['container'] ?? '') === 'blocked',
        'api_run_sql blocks interactive \\g |id escape'
    );

    $quizBadResp = $studentClient->request(
        'POST',
        '/index.php?action=api_submit_quiz',
        ['module_id' => 'module_01_introduction_postgresql_18', 'score' => 5, 'total' => 5],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(
        400,
        $quizBadResp['status'],
        'api_submit_quiz rejects missing answers with HTTP 400 when module has MCQs'
    );

    $quizUnknownResp = $studentClient->request(
        'POST',
        '/index.php?action=api_submit_quiz',
        ['module_id' => 'nonexistent_module_999', 'answers' => [0 => 0]],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(400, $quizUnknownResp['status'], 'api_submit_quiz rejects unknown module_id with HTTP 400');

    $toggleBadResp = $studentClient->request(
        'POST',
        '/index.php?action=api_toggle_complete',
        ['type' => 'module', 'id' => 'nonexistent_item_999', 'completed' => true],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(400, $toggleBadResp['status'], 'api_toggle_complete rejects invalid item_id with HTTP 400');

    $chaosBlockedResp = $studentClient->request(
        'POST',
        '/index.php?action=api_chaos',
        ['scenario' => 'bloat', 'mode' => 'inject'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $apiCsrf]
    );
    $runner->assertEquals(403, $chaosBlockedResp['status'], 'Student calling api_chaos receives HTTP 403');

    // Student password update validation: invalid current_password rejected, valid accepted.
    $profilePage = $studentClient->request('GET', '/index.php?page=profile');
    $runner->assertEquals(200, $profilePage['status'], 'Student profile page renders HTTP 200');
    $pCsrf = EphemeralHttpClient::extractCsrf($profilePage['body']);

    $badPwResp = $studentClient->request('POST', '/index.php?page=profile&action=update_profile', [
        'csrf_token' => $pCsrf,
        'name' => 'Thomas Dubois',
        'company' => 'ClientTest',
        'current_password' => 'WrongCurrentPass!',
        'new_password' => 'NewStudent2026!',
    ]);
    $runner->assertTrue(
        str_contains($badPwResp['body'], 'Mot de passe actuel') || str_contains($badPwResp['body'], 'incorrect'),
        'update_profile rejects password change with invalid current_password'
    );

    $goodPwResp = $studentClient->request('POST', '/index.php?page=profile&action=update_profile', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($badPwResp['body']) ?: $pCsrf,
        'name' => 'Thomas Dubois',
        'company' => 'ClientTest',
        'current_password' => 'Stagiaire2026!',
        'new_password' => 'NewStudent2026!',
    ]);
    $runner->assertTrue(
        str_contains($goodPwResp['body'], 'mis à jour avec succès'),
        'update_profile accepts password change with valid current_password'
    );

    // Verify new student password works over login, then restore original password.
    $studentVerifyClient = new EphemeralHttpClient('127.0.0.1', $port);
    $svLogin = $studentVerifyClient->request('GET', '/index.php?page=login');
    $svDash = $studentVerifyClient->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($svLogin['body']),
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'NewStudent2026!',
    ]);
    $runner->assertTrue(
        str_contains($svDash['final_path'], 'page=dashboard'),
        'Student logs in successfully with updated password'
    );
    $svProf = $studentVerifyClient->request('GET', '/index.php?page=profile');
    $studentVerifyClient->request('POST', '/index.php?page=profile&action=update_profile', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($svProf['body']),
        'name' => 'Thomas Dubois',
        'company' => 'ClientTest',
        'current_password' => 'NewStudent2026!',
        'new_password' => 'Stagiaire2026!',
    ]);

    // Non-staff student blocked from admin and staff exports.
    $sAdminBlocked = $studentClient->request('GET', '/index.php?page=admin');
    $runner->assertEquals(403, $sAdminBlocked['status'], 'Student accessing ?page=admin receives HTTP 403');
    $sExportBlocked = $studentClient->request('GET', '/index.php?action=export_qualiopi_csv');
    $runner->assertEquals(403, $sExportBlocked['status'], 'Student accessing export_qualiopi_csv receives HTTP 403');
    $studentClient->request('GET', '/index.php?action=logout');

    // ------------------------------------------------------------------------
    // 4. Trainer RBAC Verification
    // ------------------------------------------------------------------------
    echo "[4/6] Trainer RBAC checks (formateur@lightpath.fr)...\n";
    $trainerClient = new EphemeralHttpClient('127.0.0.1', $port);
    $tLogin = $trainerClient->request('GET', '/index.php?page=login');
    $tDash = $trainerClient->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($tLogin['body']),
        'email' => 'formateur@lightpath.fr',
        'password' => 'Formateur2026!',
    ]);
    $runner->assertEquals(200, $tDash['status'], 'Trainer logs in cleanly');

    $tCohort = $trainerClient->request('GET', '/index.php?page=admin&tab=cohort');
    $runner->assertEquals(200, $tCohort['status'], 'Trainer accesses ?page=admin&tab=cohort HTTP 200');
    $tCsrf = EphemeralHttpClient::extractCsrf($tCohort['body']);

    $tCreateBlocked = $trainerClient->request('POST', '/index.php?action=admin_create_user', [
        'csrf_token' => $tCsrf,
        'name' => 'Blocked User',
        'email' => 'blocked.by.rbac@test.fr',
        'password' => 'Test1234!',
        'role' => 'student',
    ], [], false);
    $runner->assertEquals(403, $tCreateBlocked['status'], 'Trainer calling admin_create_user receives HTTP 403');

    $tAuditBlocked = $trainerClient->request('GET', '/index.php?action=export_audit_csv');
    $runner->assertEquals(403, $tAuditBlocked['status'], 'Trainer calling export_audit_csv receives HTTP 403');
    $tFeatBlocked = $trainerClient->request('GET', '/index.php?action=export_features_json');
    $runner->assertEquals(403, $tFeatBlocked['status'], 'Trainer calling export_features_json receives HTTP 403');

    // Trainer authorized access to new Lab Workbench APIs & staff classroom/Docker controls.
    $tSpResp = $trainerClient->request(
        'POST',
        '/index.php?action=api_lab_savepoint',
        ['op' => 'list'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $tCsrf]
    );
    $tBenchResp = $trainerClient->request(
        'POST',
        '/index.php?action=api_lab_pgbench',
        ['clients' => 2, 'duration' => 1, 'db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $tCsrf]
    );
    $tDiagResp = $trainerClient->request(
        'POST',
        '/index.php?action=api_lab_pg_diag',
        ['db' => 'formation_pg'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $tCsrf]
    );
    $tReadyResp = $trainerClient->request(
        'POST',
        '/index.php?action=api_lab_readiness',
        ['lab_id' => 'lab_20_tuning_systeme_linux_postgresql'],
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $tCsrf]
    );
    $runner->assertTrue(
        $tSpResp['status'] === 200 && $tBenchResp['status'] === 200 && $tDiagResp['status'] === 200 && $tReadyResp['status'] === 200,
        'Trainer invokes api_lab_savepoint, api_lab_pgbench, api_lab_pg_diag, api_lab_readiness HTTP 200'
    );
    $trainerClient->request('GET', '/index.php?action=logout');

    // ------------------------------------------------------------------------
    // 5. Admin Verification (jmrenouard@lightpath.fr) — All 7 Tabs, Role Guard, Audit CSV, Feature Toggle
    // ------------------------------------------------------------------------
    echo "[5/6] Admin E2E checks (jmrenouard@lightpath.fr)...\n";
    $adminClient = new EphemeralHttpClient('127.0.0.1', $port);
    $aLogin = $adminClient->request('GET', '/index.php?page=login');
    $aDash = $adminClient->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($aLogin['body']),
        'email' => 'jmrenouard@lightpath.fr',
        'password' => 'LightPath2026!',
    ]);
    $runner->assertEquals(200, $aDash['status'], 'Admin login lands cleanly HTTP 200');
    $runner->assertTrue(
        str_contains($aDash['final_path'], 'page=dashboard'),
        'Admin login redirects to ?page=dashboard'
    );

    $adminTabs = ['cohort', 'users', 'qna', 'docker', 'courses', 'audit', 'system'];
    $usersTabBody = '';
    $auditTabBody = '';
    $systemTabBody = '';
    $adminTabsInlineStyleCount = 0;
    foreach ($adminTabs as $tab) {
        $tabResp = $adminClient->request('GET', '/index.php?page=admin&tab=' . urlencode($tab));
        $runner->assertEquals(200, $tabResp['status'], "Admin tab '{$tab}' renders HTTP 200");
        $runner->assertTrue(
            !str_contains($tabResp['body'], 'Fatal error') && !str_contains($tabResp['body'], 'Uncaught'),
            "Admin tab '{$tab}' contains zero PHP fatal/uncaught errors"
        );
        $adminTabsInlineStyleCount += (int)preg_match_all('/\bstyle\s*=/i', $tabResp['body']);
        if ($tab === 'users') {
            $usersTabBody = $tabResp['body'];
        } elseif ($tab === 'audit') {
            $auditTabBody = $tabResp['body'];
        } elseif ($tab === 'system') {
            $systemTabBody = $tabResp['body'];
        }
    }
    $runner->assertEquals(0, $adminTabsInlineStyleCount, 'All 7 admin tabs contain zero inline style= attributes');
    $runner->assertTrue(
        str_contains($auditTabBody, 'integrity-pill-ok') && str_contains($auditTabBody, 'Chaîne SHA-256 Intègre'),
        'Admin audit tab displays SHA-256 chain integrity badge'
    );
    $runner->assertTrue(
        str_contains($systemTabBody, 'admin-split-grid') && str_contains($systemTabBody, 'Chaîne Audit SHA-256'),
        'Admin system tab renders balanced .admin-split-grid and 7th SHA-256 health check'
    );

    $aCsrfUsers = EphemeralHttpClient::extractCsrf($usersTabBody);
    $badRoleResp = $adminClient->request('POST', '/index.php?action=admin_update_user', [
        'csrf_token' => $aCsrfUsers,
        'user_id' => '3',
        'name' => 'Thomas Dubois',
        'company' => 'ClientTest',
        'role' => 'superhacker',
        'active' => '1',
    ]);
    $runner->assertEquals(200, $badRoleResp['status'], 'admin_update_user invalid role resolves cleanly');
    $runner->assertTrue(
        str_contains($badRoleResp['body'], 'Rôle utilisateur invalide'),
        'admin_update_user rejects invalid role superhacker with error flash'
    );
    $runner->assertTrue(
        !str_contains($badRoleResp['body'], 'superhacker'),
        'Invalid role superhacker is never persisted onto user #3'
    );

    $auditCsvResp = $adminClient->request('GET', '/index.php?action=export_audit_csv');
    $runner->assertEquals(200, $auditCsvResp['status'], 'export_audit_csv returns HTTP 200');
    $runner->assertEquals(
        'VALID',
        $adminClient->firstHeader($auditCsvResp['headers'], 'x-audit-chain-status'),
        'export_audit_csv emits X-Audit-Chain-Status: VALID header'
    );
    $runner->assertTrue(
        str_starts_with($auditCsvResp['body'], "\xEF\xBB\xBF"),
        'export_audit_csv output begins with UTF-8 BOM'
    );
    $runner->assertTrue(
        preg_match('//u', $auditCsvResp['body']) === 1
        && str_contains($auditCsvResp['body'], 'Horodatage;Acteur;Action;Details'),
        'export_audit_csv returns valid UTF-8 CSV with expected semicolon headers'
    );

    // Cohort CSV, Qualiopi JSON, and Features JSON exports.
    $cohortCsvResp = $adminClient->request('GET', '/index.php?action=export_cohort_csv&course=postgresql-18');
    $runner->assertEquals(200, $cohortCsvResp['status'], 'export_cohort_csv returns HTTP 200');
    $runner->assertTrue(
        str_starts_with($cohortCsvResp['body'], "\xEF\xBB\xBF")
        && str_contains($cohortCsvResp['body'], 'User_ID;Nom;Email;Entreprise;Role;Statut'),
        'export_cohort_csv returns UTF-8 BOM + expected cohort headers'
    );

    $qualiopiJsonResp = $adminClient->request('GET', '/index.php?action=export_qualiopi_json&course=postgresql-18');
    $qualiopiData = json_decode($qualiopiJsonResp['body'], true);
    $runner->assertEquals(200, $qualiopiJsonResp['status'], 'export_qualiopi_json returns HTTP 200');
    $runner->assertTrue(
        is_array($qualiopiData)
        && ($qualiopiData['schema_version'] ?? '') === '2.0'
        && ($qualiopiData['audit_integrity']['valid'] ?? false) === true
        && isset($qualiopiData['at_risk_learners'], $qualiopiData['module_completion_matrix'], $qualiopiData['learners']),
        'export_qualiopi_json returns valid v2.0 Qualiopi dossier with intact SHA-256 chain'
    );

    $featuresJsonResp = $adminClient->request('GET', '/index.php?action=export_features_json');
    $featuresJsonData = json_decode($featuresJsonResp['body'], true);
    $runner->assertEquals(200, $featuresJsonResp['status'], 'export_features_json returns HTTP 200');
    $runner->assertTrue(
        is_array($featuresJsonData)
        && ($featuresJsonData['schema'] ?? '') === 'lightpath-lms-features-v1'
        && count($featuresJsonData['flags'] ?? []) === 10,
        'export_features_json exports 10 categorized feature flags'
    );

    // Admin classroom presets, Docker reaper, and Chaos heal endpoints.
    $presetResp = $adminClient->request('POST', '/index.php?page=admin&tab=cohort&action=admin_apply_classroom_preset&course=postgresql-18', [
        'csrf_token' => $aCsrfUsers,
        'preset' => 'exam_lockdown',
    ]);
    $runner->assertTrue(
        $presetResp['status'] === 200 && str_contains($presetResp['body'], 'Preset'),
        'admin_apply_classroom_preset applies exam_lockdown preset cleanly'
    );
    $adminClient->request('POST', '/index.php?page=admin&tab=cohort&action=admin_apply_classroom_preset&course=postgresql-18', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($presetResp['body']),
        'preset' => 'open_review',
    ]);

    $reapResp = $adminClient->request('POST', '/index.php?page=admin&tab=docker&action=admin_reap_idle_docker&course=postgresql-18', [
        'csrf_token' => $aCsrfUsers,
        'max_idle_seconds' => '3600',
    ]);
    $runner->assertTrue(
        $reapResp['status'] === 200 && str_contains($reapResp['body'], 'Cloud Simulé'),
        'admin_reap_idle_docker completes cleanly HTTP 200'
    );

    $healResp = $adminClient->request('POST', '/index.php?page=admin&tab=docker&action=admin_heal_chaos&course=postgresql-18', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($reapResp['body']),
    ]);
    $runner->assertTrue(
        $healResp['status'] === 200 && str_contains($healResp['body'], 'Chaos'),
        'admin_heal_chaos completes cleanly HTTP 200'
    );

    // User suspension, inactive login denial, bulk activate, admin password reset, and throttle unlock.
    $suspendResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_toggle_user_active', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($healResp['body']),
        'user_id' => '3',
        'active' => '0',
    ]);
    $runner->assertTrue(
        $suspendResp['status'] === 200 && str_contains($suspendResp['body'], 'suspendu'),
        'admin_toggle_user_active suspends student #3'
    );

    $suspendedStudent = new EphemeralHttpClient('127.0.0.1', $port);
    $ssLoginPage = $suspendedStudent->request('GET', '/index.php?page=login');
    $ssAttempt = $suspendedStudent->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($ssLoginPage['body']),
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'Stagiaire2026!',
    ]);
    $runner->assertTrue(
        str_contains($ssAttempt['final_path'], 'page=login')
        && str_contains($ssAttempt['body'], 'compte inactif'),
        'Suspended student login is denied and redirected back to ?page=login'
    );

    $bulkActivateResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_bulk_users', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($suspendResp['body']),
        'bulk_op' => 'activate',
        'selected_user_ids' => ['3'],
    ]);
    $runner->assertTrue(
        $bulkActivateResp['status'] === 200 && str_contains($bulkActivateResp['body'], 'réactivé'),
        'admin_bulk_users reactivates student #3 cleanly'
    );

    $adminResetPwResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_reset_password', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($bulkActivateResp['body']),
        'user_id' => '3',
        'new_password' => 'AdminReset2026!',
    ]);
    $runner->assertTrue(
        $adminResetPwResp['status'] === 200 && str_contains($adminResetPwResp['body'], 'réinitialisé'),
        'admin_reset_password updates student #3 password'
    );

    $resetCheckClient = new EphemeralHttpClient('127.0.0.1', $port);
    $rcLogin = $resetCheckClient->request('GET', '/index.php?page=login');
    $rcDash = $resetCheckClient->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($rcLogin['body']),
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'AdminReset2026!',
    ]);
    $runner->assertTrue(
        str_contains($rcDash['final_path'], 'page=dashboard'),
        'Student logs in with admin-reset password successfully'
    );
    $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_reset_password', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($adminResetPwResp['body']),
        'user_id' => '3',
        'new_password' => 'Stagiaire2026!',
    ]);

    $unlockThrottleResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_unlock_throttle', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($adminResetPwResp['body']),
        'email' => 'bruteforce.target@lightpath.fr',
        'ip' => '127.0.0.1',
    ]);
    $runner->assertTrue(
        $unlockThrottleResp['status'] === 200 && str_contains($unlockThrottleResp['body'], 'Verrouillage anti-brute-force levé'),
        'admin_unlock_throttle unlocks brute-force bucket cleanly'
    );

    // Non-#1 admin self-lockout protection on admin_update_user and admin_delete_user.
    $createSecAdmin = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_create_user', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($unlockThrottleResp['body']),
        'name' => 'Secondary Admin QA',
        'email' => 'sec.admin.qa@lightpath.fr',
        'password' => 'SecAdmin2026!',
        'role' => 'admin',
        'company' => 'LightPath',
    ]);
    $secAdminId = 0;
    if (preg_match('/<tr[^>]*>.*?sec\.admin\.qa@lightpath\.fr.*?name="user_id"\s+value="(\d+)"/is', $createSecAdmin['body'], $secUidMatch)) {
        $secAdminId = (int)$secUidMatch[1];
    }
    $runner->assertTrue(
        $createSecAdmin['status'] === 200 && $secAdminId > 1,
        'Super-admin creates secondary admin user with ID > 1'
    );

    $secAdminClient = new EphemeralHttpClient('127.0.0.1', $port);
    $secLoginPage = $secAdminClient->request('GET', '/index.php?page=login');
    $secDashResp = $secAdminClient->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($secLoginPage['body']),
        'email' => 'sec.admin.qa@lightpath.fr',
        'password' => 'SecAdmin2026!',
    ]);
    $runner->assertEquals(200, $secDashResp['status'], 'Secondary admin logs in cleanly HTTP 200');
    $secUsersPage = $secAdminClient->request('GET', '/index.php?page=admin&tab=users');
    $runner->assertEquals(200, $secUsersPage['status'], 'Secondary admin accesses ?page=admin&tab=users HTTP 200');

    $selfUpdateResp = $secAdminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_update_user', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($secUsersPage['body']),
        'user_id' => (string)$secAdminId,
        'name' => 'Secondary Admin QA',
        'company' => 'LightPath',
        'role' => 'student',
    ]);
    $runner->assertEquals(200, $selfUpdateResp['status'], 'Secondary admin self-update request resolves cleanly HTTP 200');

    $selfUsersCheck = $secAdminClient->request('GET', '/index.php?page=admin&tab=users');
    $runner->assertEquals(200, $selfUsersCheck['status'], 'Secondary admin retains admin access after self-demotion attempt');
    $runner->assertTrue(
        preg_match('/<select\s+id="edit-role-' . $secAdminId . '".*?<option\s+value="admin"\s+selected>/is', $selfUsersCheck['body']) === 1
        && preg_match('/<input\s+id="edit-active-' . $secAdminId . '"[^>]*checked/is', $selfUsersCheck['body']) === 1,
        'Secondary admin role=admin and active=true are protected from self-demotion/self-deactivation'
    );

    $selfDeleteResp = $secAdminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_delete_user', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($selfUsersCheck['body']),
        'user_id' => (string)$secAdminId,
    ]);
    $runner->assertTrue(
        $selfDeleteResp['status'] === 200
        && str_contains($selfDeleteResp['body'], 'ne peuvent pas être supprimés')
        && str_contains($selfDeleteResp['body'], 'sec.admin.qa@lightpath.fr'),
        'Secondary admin cannot delete own active session via admin_delete_user'
    );

    $adminUsersCleanup = $adminClient->request('GET', '/index.php?page=admin&tab=users');
    $cleanupSecResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_delete_user', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($adminUsersCleanup['body']),
        'user_id' => (string)$secAdminId,
    ]);
    $runner->assertTrue(
        $cleanupSecResp['status'] === 200 && str_contains($cleanupSecResp['body'], 'supprimé'),
        'Primary admin #1 removes temporary secondary admin cleanly after test'
    );

    // Feature flag toggle persistence & clean restore (all 10 flags).
    $aCsrfSys = EphemeralHttpClient::extractCsrf($systemTabBody);
    $toggleOffResp = $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => $aCsrfSys,
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'docker_labs' => '1',
            'chaos_engineering' => '1',
            'qna_forum' => '1',
            'personal_notes' => '1',
            'flashcards_srs' => '1',
            'scorm_export' => '1',
            'cold_satisfaction_j60' => '1',
        ],
    ]);
    $runner->assertEquals(200, $toggleOffResp['status'], 'admin_save_features toggling gamification OFF returns 200');
    $sysAfterToggle = $adminClient->request('GET', '/index.php?page=admin&tab=system');
    $runner->assertTrue(
        (bool)preg_match('/id="flag-gamification"[^>]*>/', $sysAfterToggle['body'], $gmMatch)
        && !str_contains($gmMatch[0], 'checked'),
        'Feature flag gamification toggle OFF persists across HTTP requests'
    );

    // Deployment profile & per-course feature override checks.
    $profileApplyResp = $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_apply_feature_profile', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($sysAfterToggle['body']),
        'profile_key' => 'static_hosting_nodocker',
    ]);
    $runner->assertTrue(
        $profileApplyResp['status'] === 200 && str_contains($profileApplyResp['body'], 'static_hosting_nodocker'),
        'admin_apply_feature_profile applies static_hosting_nodocker cleanly'
    );
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_apply_feature_profile', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($profileApplyResp['body']),
        'profile_key' => 'full_enterprise',
    ]);

    $courseOvResp = $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_course_features', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($profileApplyResp['body']),
        'target_course_slug' => 'postgresql-18',
        'course_features' => ['personal_notes' => '0'],
    ]);
    $runner->assertEquals(200, $courseOvResp['status'], 'admin_save_course_features persists course override');

    $ovStudent = new EphemeralHttpClient('127.0.0.1', $port);
    $ovLogin = $ovStudent->request('GET', '/index.php?page=login');
    $ovStudent->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($ovLogin['body']),
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'Stagiaire2026!',
    ]);
    $ovNotesBlocked = $ovStudent->request('GET', '/index.php?page=notes&course=postgresql-18');
    $runner->assertEquals(403, $ovNotesBlocked['status'], 'Per-course override personal_notes=0 denies ?page=notes with HTTP 403');
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_course_features', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($courseOvResp['body']),
        'target_course_slug' => 'postgresql-18',
        'reset_course_features' => '1',
    ]);
    $ovNotesRestored = $ovStudent->request('GET', '/index.php?page=notes&course=postgresql-18');
    $runner->assertEquals(200, $ovNotesRestored['status'], 'Resetting per-course overrides restores ?page=notes HTTP 200');
    $ovStudent->request('GET', '/index.php?action=logout');

    // Restore all 10 feature flags back to enabled default.
    $sysBeforeRestore = $adminClient->request('GET', '/index.php?page=admin&tab=system');
    $aCsrfRestore = EphemeralHttpClient::extractCsrf($sysBeforeRestore['body']);
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => $aCsrfRestore,
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'docker_labs' => '1',
            'chaos_engineering' => '1',
            'gamification' => '1',
            'qna_forum' => '1',
            'personal_notes' => '1',
            'flashcards_srs' => '1',
            'scorm_export' => '1',
            'cold_satisfaction_j60' => '1',
        ],
    ]);
    $sysRestored = $adminClient->request('GET', '/index.php?page=admin&tab=system');
    $runner->assertTrue(
        (bool)preg_match('/id="flag-gamification"[^>]*>/', $sysRestored['body'], $gmRestoreMatch)
        && str_contains($gmRestoreMatch[0], 'checked'),
        'Feature flag gamification cleanly restored to checked'
    );

    // Reserved course slug guard: admin_add_course rejects 'postgresql-18'.
    $aCsrfCourses = EphemeralHttpClient::extractCsrf($sysRestored['body']);
    $reservedSlugResp = $adminClient->request('POST', '/index.php?page=admin&tab=courses&action=admin_add_course', [
        'csrf_token' => $aCsrfCourses,
        'slug' => 'postgresql-18',
        'title' => 'Reserved Override Attempt',
        'category' => 'Test',
        'duration' => '7h',
        'source_root' => '/tmp/nonexistent',
        'description' => 'Should be rejected cleanly',
    ]);
    $runner->assertEquals(200, $reservedSlugResp['status'], 'admin_add_course reserved slug resolves cleanly via redirect');
    $runner->assertTrue(
        str_contains($reservedSlugResp['body'], 'Identifiant réservé'),
        'admin_add_course rejects reserved postgresql-18 slug with error flash'
    );

    // Explicit empty enrollment denies learner access (HTTP 403) until re-enrolled.
    $usersTabFresh = $adminClient->request('GET', '/index.php?page=admin&tab=users');
    $unEnrollResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_update_enrollments&course=postgresql-18', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($usersTabFresh['body']),
        'user_id' => '3',
    ]);
    $runner->assertEquals(200, $unEnrollResp['status'], 'admin_update_enrollments with empty list completes cleanly');
    $runner->assertTrue(
        str_contains($unEnrollResp['body'], 'Aucun cours assigné'),
        'Admin users table shows empty enrollment badge for student #3'
    );

    $unenrolledStudent = new EphemeralHttpClient('127.0.0.1', $port);
    $ueLoginPage = $unenrolledStudent->request('GET', '/index.php?page=login');
    $ueLoginCsrf = EphemeralHttpClient::extractCsrf($ueLoginPage['body']);
    $ueDashResp = $unenrolledStudent->request('POST', '/index.php?action=login', [
        'csrf_token' => $ueLoginCsrf,
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'Stagiaire2026!',
    ]);
    $runner->assertEquals(403, $ueDashResp['status'], 'Unenrolled student (enrolled_courses=[]) receives HTTP 403 after login redirect');
    $ueApiBlocked = $unenrolledStudent->request('POST', '/index.php?action=api_run_sql', [
        'sql' => 'SELECT 1;',
        'db_name' => 'formation_pg',
    ], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ueLoginCsrf]);
    $runner->assertEquals(403, $ueApiBlocked['status'], 'Unenrolled student receives HTTP 403 on JSON API endpoints');

    $reEnrollResp = $adminClient->request('POST', '/index.php?page=admin&tab=users&action=admin_update_enrollments&course=postgresql-18', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($unEnrollResp['body']),
        'user_id' => '3',
        'enrolled_courses' => ['postgresql-18'],
    ]);
    $runner->assertEquals(200, $reEnrollResp['status'], 'Admin restores postgresql-18 enrollment for student #3');

    // Feature gating: docker_labs=false hides cockpit/CTAs and blocks student Docker APIs (HTTP 403).
    $sysTabForDockerOff = $adminClient->request('GET', '/index.php?page=admin&tab=system');
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($sysTabForDockerOff['body']),
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'chaos_engineering' => '1',
            'gamification' => '1',
            'qna_forum' => '1',
            'personal_notes' => '1',
            'flashcards_srs' => '1',
            'scorm_export' => '1',
            'cold_satisfaction_j60' => '1',
        ],
    ]);
    $noDockerStudent = new EphemeralHttpClient('127.0.0.1', $port);
    $ndsLogin = $noDockerStudent->request('GET', '/index.php?page=login');
    $ndsDash = $noDockerStudent->request('POST', '/index.php?action=login', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($ndsLogin['body']),
        'email' => 'stagiaire@lightpath.fr',
        'password' => 'Stagiaire2026!',
    ]);
    $runner->assertEquals(200, $ndsDash['status'], 'Student dashboard renders HTTP 200 when docker_labs=false');
    $runner->assertTrue(
        !str_contains($ndsDash['body'], 'id="docker-panel"')
        && !str_contains($ndsDash['body'], 'id="topbar-docker-pill"')
        && !str_contains($ndsDash['body'], 'Accéder aux '),
        'Dashboard hides Docker panel, topbar pill, and Labs CTA when docker_labs=false'
    );
    $ndsLab = $noDockerStudent->request('GET', '/index.php?page=lab&course=postgresql-18&id=lab_01_introduction_setup_postgresql_18');
    $runner->assertEquals(200, $ndsLab['status'], 'Lab view still renders educational instructions when docker_labs=false');
    $runner->assertTrue(
        !str_contains($ndsLab['body'], 'id="btn-seed-lab"')
        && !str_contains($ndsLab['body'], 'id="btn-docker-logs"')
        && str_contains($ndsLab['body'], 'Environnement Docker Labs désactivé'),
        'Lab view hides interactive Docker cockpit buttons and renders disabled notice when docker_labs=false'
    );
    $ndsCsrf = EphemeralHttpClient::extractCsrf($ndsDash['body']);
    $ndsSeedBlocked = $noDockerStudent->request('POST', '/index.php?action=api_seed_lab', ['dataset' => 'all'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $ndsSqlBlocked = $noDockerStudent->request('POST', '/index.php?action=api_run_sql', ['sql' => 'SELECT 1;', 'db_name' => 'formation_pg'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $ndsSpBlocked = $noDockerStudent->request('POST', '/index.php?action=api_lab_savepoint', ['op' => 'list'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $ndsBenchBlocked = $noDockerStudent->request('POST', '/index.php?action=api_lab_pgbench', ['clients' => 2, 'duration' => 1], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $ndsDiagBlocked = $noDockerStudent->request('POST', '/index.php?action=api_lab_pg_diag', ['db' => 'formation_pg'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $ndsReadyBlocked = $noDockerStudent->request('POST', '/index.php?action=api_lab_readiness', ['lab_id' => 'lab_01_introduction_setup_postgresql_18'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $ndsCsrf]);
    $runner->assertEquals(403, $ndsSeedBlocked['status'], 'api_seed_lab returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsSqlBlocked['status'], 'api_run_sql returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsSpBlocked['status'], 'api_lab_savepoint returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsBenchBlocked['status'], 'api_lab_pgbench returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsDiagBlocked['status'], 'api_lab_pg_diag returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsReadyBlocked['status'], 'api_lab_readiness returns HTTP 403 for students when docker_labs=false');

    $sysTabRestoreDocker = $adminClient->request('GET', '/index.php?page=admin&tab=system');
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => EphemeralHttpClient::extractCsrf($sysTabRestoreDocker['body']),
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'docker_labs' => '1',
            'chaos_engineering' => '1',
            'gamification' => '1',
            'qna_forum' => '1',
            'personal_notes' => '1',
            'flashcards_srs' => '1',
            'scorm_export' => '1',
            'cold_satisfaction_j60' => '1',
        ],
    ]);

    $restoredStudentDash = $noDockerStudent->request('GET', '/index.php?page=dashboard');
    $runner->assertTrue(
        str_contains($restoredStudentDash['body'], 'id="docker-panel"'),
        'Restoring all 10 feature flags restores student Docker dashboard panel'
    );

    // ------------------------------------------------------------------------
    // 6. Verify Child Server Error Stream Has Zero PHP Warnings/Fatals
    // ------------------------------------------------------------------------
    echo "[6/6] Inspecting server error output stream...\n";
    $serverStderr = is_file($serverErrLog) ? (string)file_get_contents($serverErrLog) : '';
    $runner->assertTrue(
        !str_contains($serverStderr, 'Fatal error')
        && !str_contains($serverStderr, 'Parse error')
        && !str_contains($serverStderr, 'PHP Warning:')
        && !str_contains($serverStderr, 'PHP Notice:'),
        'PHP built-in server stderr contains zero fatal/parse/warning/notice errors',
        substr(trim($serverStderr), 0, 200)
    );
} catch (\Throwable $ex) {
    $serverStderr = is_file($serverErrLog) ? (string)file_get_contents($serverErrLog) : '';
    fwrite(STDERR, "\n[HTTP SUITE EXCEPTION] " . $ex->getMessage() . "\n");
    if ($serverStderr !== '') {
        fwrite(STDERR, "--- PHP SERVER STDERR ---\n" . substr($serverStderr, -3000) . "\n-------------------------\n");
    }
    throw $ex;
} finally {
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        $pid = (int)($status['pid'] ?? 0);
        @proc_terminate($proc);
        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
            @exec("taskkill /F /T /PID {$pid} >NUL 2>&1");
        }
        @proc_close($proc);
    }
    $removeDirRecursive($tmpRoot);
}

exit($runner->printSummary());
