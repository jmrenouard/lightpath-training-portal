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
    $runner->assertTrue(
        $client->firstHeader($verifyResp['headers'], 'content-security-policy') !== null,
        'GET ?page=verify sends Content-Security-Policy header'
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
    $runner->assertTrue(
        $loginScriptSrc !== ''
        && str_contains($loginScriptSrc, "'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='")
        && !str_contains($loginScriptSrc, 'unsafe-inline'),
        'GET ?page=login CSP script-src uses unload shim SHA-256 hash without unsafe-inline'
    );

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
        !str_contains($labView['body'], 'postgres_master_pw'),
        'Lab view does NOT expose deprecated postgres_master_pw'
    );

    $apiCsrf = EphemeralHttpClient::extractCsrf($labView['body']);
    $runner->assertTrue($apiCsrf !== '', 'Lab view provides CSRF token for API calls');

    $dlMod = $studentClient->request(
        'GET',
        '/index.php?action=download&course=postgresql-18&type=module&id=module_01_introduction_postgresql_18&format=md'
    );
    $runner->assertEquals(200, $dlMod['status'], 'Student downloads module Markdown cleanly via DownloadController HTTP 200');

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
    $systemTabBody = '';
    foreach ($adminTabs as $tab) {
        $tabResp = $adminClient->request('GET', '/index.php?page=admin&tab=' . urlencode($tab));
        $runner->assertEquals(200, $tabResp['status'], "Admin tab '{$tab}' renders HTTP 200");
        $runner->assertTrue(
            !str_contains($tabResp['body'], 'Fatal error') && !str_contains($tabResp['body'], 'Uncaught'),
            "Admin tab '{$tab}' contains zero PHP fatal/uncaught errors"
        );
        if ($tab === 'users') {
            $usersTabBody = $tabResp['body'];
        } elseif ($tab === 'system') {
            $systemTabBody = $tabResp['body'];
        }
    }

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
    $runner->assertTrue(
        str_starts_with($auditCsvResp['body'], "\xEF\xBB\xBF"),
        'export_audit_csv output begins with UTF-8 BOM'
    );
    $runner->assertTrue(
        preg_match('//u', $auditCsvResp['body']) === 1
        && str_contains($auditCsvResp['body'], 'Horodatage;Acteur;Action;Details'),
        'export_audit_csv returns valid UTF-8 CSV with expected semicolon headers'
    );

    // Feature flag toggle persistence & clean restore.
    $aCsrfSys = EphemeralHttpClient::extractCsrf($systemTabBody);
    $toggleOffResp = $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => $aCsrfSys,
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'docker_labs' => '1',
            'chaos_engineering' => '1',
            'qna_forum' => '1',
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

    // Restore all feature flags back to enabled default.
    $aCsrfRestore = EphemeralHttpClient::extractCsrf($sysAfterToggle['body']);
    $adminClient->request('POST', '/index.php?page=admin&tab=system&action=admin_save_features', [
        'csrf_token' => $aCsrfRestore,
        'features' => [
            'self_registration' => '1',
            'demo_quick_login' => '1',
            'docker_labs' => '1',
            'chaos_engineering' => '1',
            'gamification' => '1',
            'qna_forum' => '1',
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
    $runner->assertEquals(403, $ndsSeedBlocked['status'], 'api_seed_lab returns HTTP 403 for students when docker_labs=false');
    $runner->assertEquals(403, $ndsSqlBlocked['status'], 'api_run_sql returns HTTP 403 for students when docker_labs=false');

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
            'scorm_export' => '1',
            'cold_satisfaction_j60' => '1',
        ],
    ]);

    // ------------------------------------------------------------------------
    // 6. Verify Child Server Error Stream Has Zero PHP Warnings/Fatals
    // ------------------------------------------------------------------------
    echo "[6/6] Inspecting server error output stream...\n";
    $serverStderr = is_file($serverErrLog) ? (string)file_get_contents($serverErrLog) : '';
    $runner->assertTrue(
        !str_contains($serverStderr, 'Fatal error') && !str_contains($serverStderr, 'Parse error'),
        'PHP built-in server stderr contains zero fatal/parse errors',
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
