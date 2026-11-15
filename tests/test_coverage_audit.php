<?php
declare(strict_types=1);

/**
 * Automated Public API Coverage, Security & Governance Static Audit
 * LightPath Training Portal (LMS PHP 8.3)
 *
 * Verifies:
 *   1. Reflection-based public API test coverage = 100% across src/Core, src/Course, src/Docker
 *   2. Zero third-party brand leaks (e.g., prohibited training vendor names) across codebase
 *   3. Zero external CDN dependencies in templates/, public/, and docs/
 *   4. Zero inline on*= HTML event handler attributes in templates/
 *   5. 100% English technical comments across config/, src/, bin/ (incl. .py), public/ (.php/.js/.css), templates/, tests/ (incl. .mjs)
 *   6. Strict SHA-256 CSP on script-src without 'unsafe-inline' in src/Core/Security.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$failures = [];

echo "========================================================================\n";
echo " LIGHTPATH LMS — COVERAGE, SECURITY & GOVERNANCE STATIC AUDIT\n";
echo "========================================================================\n\n";

// ----------------------------------------------------------------------------
// 1. Public API Reflection Coverage Audit (src/Core, src/Course, src/Docker)
// ----------------------------------------------------------------------------
$testCorpus = '';
foreach (glob($repoRoot . '/tests/*.php') ?: [] as $testFile) {
    $testCorpus .= (string)file_get_contents($testFile) . "\n";
}

$totalPublicMethods = 0;
$coveredPublicMethods = 0;
$uncoveredMethods = [];

foreach (['src/Core', 'src/Course', 'src/Docker'] as $relDir) {
    $files = glob($repoRoot . '/' . $relDir . '/*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        $nsSub = str_replace('src/', '', $relDir);
        $className = 'LightPath\\LMS\\' . str_replace('/', '\\', $nsSub) . '\\' . basename($file, '.php');
        if (!class_exists($className)) {
            continue;
        }
        $rc = new ReflectionClass($className);
        if ($rc->isInterface() || $rc->isAbstract()) {
            continue;
        }
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $methodName = $method->getName();
            if ($methodName === '__construct') {
                continue;
            }
            $totalPublicMethods++;
            $isInvoked = (bool)preg_match('/(?:->|::)' . preg_quote($methodName, '/') . '\s*\(/', $testCorpus);
            if ($isInvoked) {
                $coveredPublicMethods++;
            } else {
                $uncoveredMethods[] = $rc->getShortName() . '::' . $methodName;
            }
        }
    }
}

$coveragePct = $totalPublicMethods > 0
    ? round(($coveredPublicMethods / $totalPublicMethods) * 100, 1)
    : 0.0;

echo sprintf(
    "[1/6] Public API Reflection Coverage: %d / %d methods (%.1f%%)\n",
    $coveredPublicMethods,
    $totalPublicMethods,
    $coveragePct
);
if (!empty($uncoveredMethods)) {
    echo "      Uncovered methods: " . implode(', ', $uncoveredMethods) . "\n";
}
if ($coveragePct < 100.0 || !empty($uncoveredMethods)) {
    $failures[] = sprintf(
        "Public API coverage %.1f%% (%d/%d) is below the required 100.0%% threshold.",
        $coveragePct,
        $coveredPublicMethods,
        $totalPublicMethods
    );
}

// ----------------------------------------------------------------------------
// 2. Prohibited Vendor Name & Branding Scan (all PHP, JS, CSS, Markdown)
// ----------------------------------------------------------------------------
$prohibitedVendor = 'noble' . 'prog';
$vendorHits = [];
$scanDirs = ['config', 'src', 'public', 'templates', 'bin', 'tests', 'docs'];
$allowedExts = ['php', 'js', 'mjs', 'css', 'md', 'html', 'py'];

$collectFiles = static function (string $baseDir, array $dirs, array $exts): array {
    $list = [];
    foreach ($dirs as $d) {
        $full = $baseDir . '/' . $d;
        if (!is_dir($full)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $exts, true)) {
                $list[] = $file->getPathname();
            }
        }
    }
    return $list;
};

foreach ($collectFiles($repoRoot, $scanDirs, $allowedExts) as $path) {
    $content = (string)file_get_contents($path);
    if (stripos($content, $prohibitedVendor) !== false) {
        $vendorHits[] = str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path);
    }
}
foreach (['README.md', 'PROJECT-CONTEXT.md'] as $rootDoc) {
    $p = $repoRoot . '/' . $rootDoc;
    if (is_file($p) && stripos((string)file_get_contents($p), $prohibitedVendor) !== false) {
        $vendorHits[] = $rootDoc;
    }
}

echo sprintf("[2/6] Prohibited Vendor Leaks: %d\n", count($vendorHits));
if (!empty($vendorHits)) {
    $failures[] = "Prohibited vendor reference found in: " . implode(', ', $vendorHits);
}

// ----------------------------------------------------------------------------
// 3. External CDN Dependency Audit (templates/, public/, docs/)
// ----------------------------------------------------------------------------
$cdnPatterns = [
    'https://cd' . 'n.',
    'http://cd' . 'n.',
    'fonts.google' . 'apis',
    'fonts.gst' . 'atic',
    'unp' . 'kg.com',
    'jsdeli' . 'vr.net',
    'cdnjs.cloud' . 'flare.com',
];
$cdnHits = [];
foreach ($collectFiles($repoRoot, ['templates', 'public', 'docs'], ['php', 'js', 'css', 'html', 'md']) as $path) {
    $content = (string)file_get_contents($path);
    foreach ($cdnPatterns as $pat) {
        if (stripos($content, $pat) !== false) {
            $cdnHits[] = str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path) . " ($pat)";
        }
    }
}
foreach (['README.md', 'PROJECT-CONTEXT.md'] as $rootDoc) {
    $p = $repoRoot . '/' . $rootDoc;
    if (!is_file($p)) {
        continue;
    }
    $content = (string)file_get_contents($p);
    foreach ($cdnPatterns as $pat) {
        if (stripos($content, $pat) !== false) {
            $cdnHits[] = $rootDoc . " ($pat)";
        }
    }
}

echo sprintf("[3/6] External CDN URL References: %d\n", count($cdnHits));
if (!empty($cdnHits)) {
    $failures[] = "External CDN references found: " . implode(', ', $cdnHits);
}

// ----------------------------------------------------------------------------
// 4. Inline HTML Event Handler Audit (on*= attributes in templates/)
// ----------------------------------------------------------------------------
$inlineHandlerHits = [];
foreach ($collectFiles($repoRoot, ['templates'], ['php', 'html']) as $path) {
    $content = (string)file_get_contents($path);
    if (preg_match_all('/\bon(?:click|dblclick|submit|change|input|keyup|keydown|keypress|load|error|mouseover|mouseout|focus|blur|mousedown|mouseup)\s*=/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$matchText, $offset]) {
            $lineNo = substr_count(substr($content, 0, $offset), "\n") + 1;
            $rel = str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path);
            $inlineHandlerHits[] = "{$rel}:L{$lineNo} ({$matchText})";
        }
    }
}

echo sprintf("[4/6] Inline on*= HTML Event Attributes in templates/: %d\n", count($inlineHandlerHits));
if (!empty($inlineHandlerHits)) {
    $failures[] = "Inline on*= attributes detected: " . implode(', ', $inlineHandlerHits);
}

// ----------------------------------------------------------------------------
// 5. Strict English Source Comment Audit (config/, src/, bin/, public/, tests/)
// ----------------------------------------------------------------------------
$frenchCommentPattern = '/[àâäçéèêëîïôöùûüÿœæ]|\b(vérifier|vérification|utilisateur|utilisateurs|stagiaire|stagiaires|formateur|formateurs|fichier|fichiers|requête|requêtes|réponse|donnée|données|tableau|boucle|mot de passe|jeton|créer|création|supprimer|suppression|chargement|échec|succès|après|avant|toujours|jamais|aucun|aucune|toutes|tous|dans|pour|avec|sans|chaque|lorsque|sinon|renvoie|retourne|invalide|verrou|exercice|émargement|attestation|cohorte|corrigé|énoncé)\b/iu';

$frenchCommentHits = [];
foreach ($collectFiles($repoRoot, ['config', 'src', 'bin', 'public', 'templates', 'tests'], ['php']) as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace('\\', '/', str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path));
    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok) || ($tok[0] !== T_COMMENT && $tok[0] !== T_DOC_COMMENT)) {
            continue;
        }
        // Strip quoted UI/Markdown examples inside docblocks before scanning natural-language comments.
        $cleaned = preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`|\[[^\]]*\]|>\s*\*\*.*$/m', ' ', $tok[1]) ?? $tok[1];
        if (preg_match($frenchCommentPattern, $cleaned)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr($tok[1], 0, 100)) ?? '');
            $frenchCommentHits[] = "{$rel}:L{$tok[2]} -> {$snippet}";
        }
    }
}

// Inspect HTML <!-- ... --> comments inside templates/
foreach ($collectFiles($repoRoot, ['templates'], ['php', 'html']) as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace('\\', '/', str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path));
    if (preg_match_all('/<!--[\s\S]*?-->/', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$commentText, $offset]) {
            $cleaned = preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', ' ', $commentText) ?? $commentText;
            if (preg_match($frenchCommentPattern, $cleaned)) {
                $lineNo = substr_count(substr($src, 0, $offset), "\n") + 1;
                $snippet = trim(preg_replace('/\s+/', ' ', substr($commentText, 0, 100)) ?? '');
                $frenchCommentHits[] = "{$rel}:L{$lineNo} -> {$snippet}";
            }
        }
    }
}

// Inspect JS, MJS, and CSS single-line and block comments inside public/ and tests/
foreach ($collectFiles($repoRoot, ['public', 'tests'], ['js', 'mjs', 'css']) as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace('\\', '/', str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path));
    if (preg_match_all('/(?:\/\*[\s\S]*?\*\/|(?:^|\s)\/\/[^\r\n]*)/m', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$commentText, $offset]) {
            $cleaned = preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', ' ', $commentText) ?? $commentText;
            if (preg_match($frenchCommentPattern, $cleaned)) {
                $lineNo = substr_count(substr($src, 0, $offset), "\n") + 1;
                $snippet = trim(preg_replace('/\s+/', ' ', substr($commentText, 0, 100)) ?? '');
                $frenchCommentHits[] = "{$rel}:L{$lineNo} -> {$snippet}";
            }
        }
    }
}

// Inspect Python # comments inside bin/ (stripping quoted string literals first)
foreach ($collectFiles($repoRoot, ['bin'], ['py']) as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace('\\', '/', str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $path));
    $lines = preg_split('/\r?\n/', $src) ?: [];
    foreach ($lines as $idx => $lineText) {
        $masked = preg_replace('/(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/', '""', $lineText) ?? $lineText;
        $hashPos = strpos($masked, '#');
        if ($hashPos !== false) {
            $commentPart = substr($masked, $hashPos);
            if (preg_match($frenchCommentPattern, $commentPart)) {
                $snippet = trim(preg_replace('/\s+/', ' ', substr($commentPart, 0, 100)) ?? '');
                $frenchCommentHits[] = "{$rel}:L" . ($idx + 1) . " -> {$snippet}";
            }
        }
    }
}

echo sprintf("[5/6] Non-English Source Comments Detected: %d\n", count($frenchCommentHits));
foreach (array_slice($frenchCommentHits, 0, 20) as $hit) {
    echo "      - {$hit}\n";
}
if (count($frenchCommentHits) > 20) {
    echo "      ... (" . (count($frenchCommentHits) - 20) . " additional occurrences omitted)\n";
}
if (!empty($frenchCommentHits)) {
    $failures[] = sprintf("%d non-English source code comments remain.", count($frenchCommentHits));
}

// ----------------------------------------------------------------------------
// 6. Strict SHA-256 CSP Check on src/Core/Security.php (No 'unsafe-inline' in script-src)
// ----------------------------------------------------------------------------
$secFile = $repoRoot . '/src/Core/Security.php';
$secSource = is_file($secFile) ? (string)file_get_contents($secFile) : '';
$scriptSrcDirective = preg_match('/script-src\s+([^;\r\n"]+)/', $secSource, $secCspMatch) ? $secCspMatch[0] : '';
$cspStrictOk = $scriptSrcDirective !== ''
    && !str_contains($scriptSrcDirective, 'unsafe-inline')
    && str_contains($scriptSrcDirective, 'sha256-');
echo sprintf(
    "[6/6] Strict Security CSP script-src (0 'unsafe-inline'): %s\n",
    $cspStrictOk ? 'PASS' : 'FAIL'
);
if (!$cspStrictOk) {
    $failures[] = "Security.php script-src CSP is missing strict sha256 hash or allows 'unsafe-inline'.";
}

echo "\n========================================================================\n";
if (!empty($failures)) {
    echo "AUDIT FAILED (" . count($failures) . " violation(s)):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "AUDIT PASSED - 100% API Coverage, Strict CSP, Zero Brand/CDN/Inline-JS/French-Comment Leaks.\n";
exit(0);
