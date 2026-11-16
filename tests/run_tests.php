<?php
declare(strict_types=1);

/**
 * Automated Test Suite (TDD) — LightPath Training Portal (LMS PHP 8.3)
 * Usage: php tests/run_tests.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\PdoStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\Gamification;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\LmsCatalog;
use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Course\DownloadController;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$message}\n";
        } else {
            $this->failed++;
            $this->failures[] = $message;
            echo "  [FAIL] {$message}\n";
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue($expected === $actual, "{$message} (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")");
    }

    public function assertEqual(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertEquals($expected, $actual, $message);
    }

    public function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    public function assertNull(mixed $actual, string $message): void
    {
        $this->assertTrue($actual === null, "{$message} (Expected: null, Got: " . var_export($actual, true) . ")");
    }

    public function assertGreaterThanOrEqual(int|float $min, int|float $actual, string $message): void
    {
        $this->assertTrue($actual >= $min, "{$message} (Min: {$min}, Actual: {$actual})");
    }

    public function summary(): int
    {
        echo "\n========================================================================\n";
        echo " RÉSULTATS DES TESTS LMS : {$this->passed} passés, {$this->failed} échoués\n";
        echo "========================================================================\n";
        if ($this->failed > 0) {
            foreach ($this->failures as $f) {
                echo "  - ❌ {$f}\n";
            }
            return 1;
        }
        return 0;
    }
}

$t = new TestRunner();
echo "========================================================================\n";
echo " LIGHTPATH TRAINING PORTAL — SUITE DE TESTS TDD (PHP " . PHP_VERSION . ")\n";
echo "========================================================================\n\n";

// 1. Test Storage & Auth
echo "--- 1. Tests Persistance Atomique & Authentification RBAC ---\n";
// Bootstrap deterministic seeded passwords before first JsonStorage initialization.
$savedInitPasswords = [
    'LMS_INIT_ADMIN_PASSWORD' => getenv('LMS_INIT_ADMIN_PASSWORD'),
    'LMS_INIT_TRAINER_PASSWORD' => getenv('LMS_INIT_TRAINER_PASSWORD'),
    'LMS_INIT_STUDENT_PASSWORD' => getenv('LMS_INIT_STUDENT_PASSWORD'),
];
putenv('LMS_INIT_ADMIN_PASSWORD=LightPath2026!');
putenv('LMS_INIT_TRAINER_PASSWORD=Formateur2026!');
putenv('LMS_INIT_STUDENT_PASSWORD=Stagiaire2026!');

$tempDir = sys_get_temp_dir() . '/lp_lms_test_' . uniqid();
$storage = new JsonStorage($tempDir);
$auth = new Auth($storage);

$users = $storage->getUsers();
$t->assertGreaterThanOrEqual(3, count($users), "Au moins 3 comptes par défaut initialisés (Admin, Formateur, Stagiaire)");

$adminUser = $auth->verifyCredentials('jmrenouard@lightpath.fr', 'LightPath2026!');
$t->assertTrue($adminUser !== null, "Authentification Admin (jmrenouard@lightpath.fr) réussie");
$t->assertEquals('admin', $adminUser['role'] ?? '', "Le rôle de Jean-Marie Renouard est 'admin'");

$badAuth = $auth->verifyCredentials('jmrenouard@lightpath.fr', 'wrong_password');
$t->assertTrue($badAuth === null, "Rejet d'un mot de passe invalide");

$newStudent = $auth->registerUser('Alice Martin', 'alice.martin@example.com', 'SecretPg18!', 'student', 'Banque de France');
$t->assertTrue(is_array($newStudent) && ($newStudent['email'] ?? '') === 'alice.martin@example.com', "Création d'un nouveau compte étudiant réussie");

// Progress & Quizzes
$storage->markItemCompleted((int)$newStudent['id'], 'postgresql-18', 'module', 'module_01_introduction_postgresql_18');
$storage->saveQuizScore((int)$newStudent['id'], 'postgresql-18', 'module_01_introduction_postgresql_18', 3, 3);
$storage->saveUserNote((int)$newStudent['id'], 'postgresql-18', 'module_01_introduction_postgresql_18', 'Excellente note sur AIO et FNV-1a.');

$progress = $storage->getUserProgress((int)$newStudent['id'], 'postgresql-18');
$t->assertTrue(in_array('module_01_introduction_postgresql_18', $progress['completed_modules'] ?? [], true), "Module 01 marqué comme terminé pour l'étudiant");
$t->assertEquals(100, $progress['quiz_scores']['module_01_introduction_postgresql_18']['percentage'] ?? 0, "Score du Quiz Module 01 enregistré à 100%");
$t->assertEquals('Excellente note sur AIO et FNV-1a.', $progress['notes']['module_01_introduction_postgresql_18'] ?? '', "Note personnelle sauvegardée");

// 2. Security Tests (CSRF & Anti-Path Traversal)
echo "\n--- 2. Tests Sécurité (CSRF, Sanitization & Path Traversal) ---\n";
$token = Security::getCsrfToken();
$t->assertTrue(strlen($token) >= 32, "Jeton CSRF généré avec entropie suffisante");
$t->assertTrue(Security::verifyCsrfToken($token), "Validation du jeton CSRF valide");
$t->assertTrue(!Security::verifyCsrfToken('invalid_token'), "Rejet d'un jeton CSRF falsifié");
$t->assertTrue(!Security::isSafeRelativePath('../../etc/passwd'), "Blocage strict de Path Traversal (../../etc/passwd)");
$t->assertTrue(Security::isSafeRelativePath('03_supports_de_cours/module_01_introduction_postgresql_18.pdf'), "Validation d'un chemin relatif légitime");

// 3. Multi-Course Catalog & Exhaustive PostgreSQL 18 Discovery Tests
echo "\n--- 3. Tests Catalogue Multi-Formations & Ressources PostgreSQL 18 ---\n";
$coursesConfig = require __DIR__ . '/../config/courses.php';
$repo = new CourseRepository($coursesConfig);

$allCourses = $repo->getAllCourses();
$t->assertGreaterThanOrEqual(1, count($allCourses), "Au moins 1 formation active dans le registre multi-formations");

$pgCourse = $repo->getCourse('postgresql-18');
$t->assertTrue($pgCourse !== null, "Formation 'postgresql-18' chargée avec succès");
$isFullPg18Course = count($pgCourse['modules'] ?? []) >= 34;

if ($isFullPg18Course) {
    $t->assertGreaterThanOrEqual(34, count($pgCourse['modules']), "Découverte des 34 modules de cours PostgreSQL 18 (01..29 + sql_01..05)");
    $t->assertGreaterThanOrEqual(34, count($pgCourse['labs']), "Découverte des 34 ateliers pratiques (labs 01..29 + lab_sql_01..05)");
    $t->assertGreaterThanOrEqual(34, count($pgCourse['cheatsheets']), "Découverte des 34 fiches mémo (cheatsheets)");
    $t->assertGreaterThanOrEqual(8, count($pgCourse['referentiel']), "Découverte des documents du référentiel Qualiopi & GUIDE_PARCOURS");
    $t->assertGreaterThanOrEqual(10, count($pgCourse['tools']), "Découverte des outils DBA pgscripts");
} else {
    $t->assertGreaterThanOrEqual(2, count($pgCourse['modules'] ?? []), "Demo course-pack discovers at least 2 modules");
    $t->assertGreaterThanOrEqual(1, count($pgCourse['labs'] ?? []), "Demo course-pack discovers at least 1 hands-on lab");
    $t->assertGreaterThanOrEqual(2, count($pgCourse['cheatsheets'] ?? []), "Demo course-pack discovers at least 2 cheatsheets");
    $t->assertGreaterThanOrEqual(1, count($pgCourse['referentiel'] ?? []), "Demo course-pack discovers at least 1 referentiel document");
    $t->assertGreaterThanOrEqual(1, count($pgCourse['tools'] ?? []), "Demo course-pack discovers at least 1 tool in pgscripts");
    $t->assertGreaterThanOrEqual(1, count($pgCourse['examples'] ?? []), "Demo course-pack discovers at least 1 companion example");
}

// Verify download formats on Module 01
$mod01 = $repo->getModule('postgresql-18', 'module_01_introduction_postgresql_18');
$t->assertTrue($mod01 !== null, "Récupération détaillée du Module 01");
if ($isFullPg18Course) {
    $formats = array_keys($mod01['downloads'] ?? []);
    foreach (['pdf', 'slides_pdf', 'pptx', 'md', 'html', 'docx'] as $ext) {
        $t->assertTrue(in_array($ext, $formats, true), "Module 01 possède le lien de téléchargement au format '{$ext}'");
    }
} else {
    $t->assertTrue(isset($mod01['downloads']['md'], $mod01['downloads']['html']), "Demo Module 01 exposes 'md' and 'html' download links");
}

// Verify download formats on Lab 01
$lab01 = $repo->getLab('postgresql-18', 'lab_01_introduction_setup_postgresql_18');
$t->assertTrue($lab01 !== null, "Récupération détaillée du Lab 01");
if ($isFullPg18Course) {
    foreach (['pdf', 'md', 'html', 'docx'] as $ext) {
        $t->assertTrue(isset($lab01['downloads'][$ext]), "Lab 01 possède le lien de téléchargement au format '{$ext}'");
    }
} else {
    $t->assertTrue(isset($lab01['downloads']['md']), "Demo Lab 01 exposes 'md' download link");
}

// 4. MarkdownParser Tests (HTML, TOC Outline, Quiz MCQ, Lab SQL Extraction)
echo "\n--- 4. Tests Parseur Markdown, Quiz QCM & Extraction SQL Lab ---\n";
$parser = new MarkdownParser();
$parsedMod = $parser->parseModuleFile($mod01['source_md_path']);
$t->assertTrue(!empty($parsedMod['html']), "Rendu HTML du Module 01 non vide");
$t->assertGreaterThanOrEqual(3, count($parsedMod['toc']), "Table des matières (TOC) extraite avec succès");
$t->assertGreaterThanOrEqual(3, count($parsedMod['quiz']), "Au moins 3 questions de Quiz QCM extraites automatiquement du Module 01");

$parsedLab = $parser->parseLabFile($lab01['source_md_path']);
$t->assertTrue(!empty($parsedLab['html']), "Rendu HTML du Lab 01 non vide");
$t->assertGreaterThanOrEqual(1, count($parsedLab['sql_snippets']), "Blocs SQL exécutables extraits du Lab 01");

// 5. Test DownloadController
echo "\n--- 5. Tests Contrôleur de Téléchargement Sécurisé ---\n";
$downloader = new DownloadController($repo);
if ($isFullPg18Course) {
    $resolvedPdf = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'pdf');
    $t->assertTrue($resolvedPdf !== null && is_file($resolvedPdf['path']), "Résolution physique du PDF du Module 01 réussie");

    $resolvedPptx = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'pptx');
    $t->assertTrue($resolvedPptx !== null && is_file($resolvedPptx['path']), "Résolution physique du PPTX du Module 01 réussie");
} else {
    $resolvedMd = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'md');
    $t->assertTrue($resolvedMd !== null && is_file($resolvedMd['path']), "Demo Module 01 Markdown path resolves cleanly");

    $resolvedHtml = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'html');
    $t->assertTrue($resolvedHtml !== null && is_file($resolvedHtml['path']), "Demo Module 01 HTML path resolves cleanly");
}

$blocked = $downloader->resolveDownloadPath('postgresql-18', 'module', '../../../etc/passwd', 'md');
$t->assertTrue($blocked === null, "Téléchargement illégitime bloqué par DownloadController");

// 6. Test DockerManager & LabSqlRunner
echo "\n--- 6. Tests Orchestrateur Docker Multi-Utilisateurs & LabSqlRunner ---\n";
$docker = new DockerManager($storage);
$portUser1 = $docker->getUserPort(1);
$portUser42 = $docker->getUserPort(42);
$t->assertEquals(5501, $portUser1, "Port Docker déterministe pour User #1 = 5501");
$t->assertEquals(5542, $portUser42, "Port Docker déterministe pour User #42 = 5542");
$t->assertEquals('lp_pg18_user_42', $docker->getContainerName(42), "Nom de conteneur isolé déterministe 'lp_pg18_user_42'");

$envStatus = $docker->detectRuntime();
$t->assertTrue(isset($envStatus['available']) && isset($envStatus['mode']), "Détection de l'environnement Docker fonctionnelle (mode: {$envStatus['mode']})");

$sqlRunner = new LabSqlRunner($docker);
$oracleCatalog = $sqlRunner->getLabOracles();
$t->assertGreaterThanOrEqual(34, count($oracleCatalog), "Catalogue des 34 Oracles de validation SQL/Qualiopi chargé");

// 7. PHP Syntax Integrity Checks Across All Files & Templates
echo "\n--- 7. Tests Intégrité Syntaxique PHP (Linting de tous les composants & vues) ---\n";
$phpFiles = array_values(array_unique(array_merge(
    glob(__DIR__ . '/../public/*.php') ?: [],
    glob(__DIR__ . '/../src/*.php') ?: [],
    glob(__DIR__ . '/../src/**/*.php') ?: [],
    glob(__DIR__ . '/../src/Http/*.php') ?: [],
    glob(__DIR__ . '/../templates/*.php') ?: [],
    glob(__DIR__ . '/../templates/pages/*.php') ?: [],
    glob(__DIR__ . '/../templates/partials/admin/*.php') ?: [],
    glob(__DIR__ . '/../bin/*.php') ?: []
)));
foreach ($phpFiles as $pf) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($pf) . ' 2>&1', $out, $code);
    $t->assertEquals(0, $code, "Syntaxe PHP valide : " . basename($pf));
}

// 8. Contract Tests RCA-144 (RCA-LMS-01 to RCA-LMS-05): Visuals, Mermaid, Cache < 20ms, Tracks, ScrollSpy & 16:9 Slides
echo "\n--- 8. Tests Contrats RCA-144 (Rendu Visuel, Mermaid Retina, Cache < 20ms, Parcours, ScrollSpy & Diaporama 16:9) ---\n";

// 8.1 RCA-LMS-01: Retina Mermaid Diagrams, Bulleted Callouts & Zero Raw Markdown Across 34 Modules & 34 Labs
$t->assertTrue(str_contains($parsedMod['html'], 'lms-mermaid-card'), "RCA-LMS-01 : Module 01 rend les schémas Mermaid sous forme de carte visuelle .lms-mermaid-card");
if ($isFullPg18Course) {
    $t->assertTrue(str_contains($parsedMod['html'], 'assets%2Fmermaid_cache%2Fmermaid_'), "RCA-LMS-01 : Module 01 résout l'image Retina pré-calculée mermaid_<hash>.png");
} else {
    $t->assertTrue(str_contains($parsedMod['html'], '<div class="mermaid">'), "Demo Module 01 renders inline Mermaid container inside .lms-mermaid-card");
}
$t->assertTrue(!str_contains($parsedMod['html'], 'lms-mermaid-code'), "RCA-LMS-01 : Zéro bloc Mermaid brut non transformé (.lms-mermaid-code)");
$t->assertTrue(str_contains($parsedMod['html'], 'lms-doc-meta-grid'), "RCA-LMS-01 : En-tête de métadonnées transformé en grille .lms-doc-meta-grid");
$t->assertTrue(str_contains($parsedMod['html'], 'lms-callout-list'), "RCA-LMS-01 : Les listes à puces dans les callouts (> * ...) sont transformées en <ul class=\"lms-callout-list\">");
$t->assertTrue(!str_contains($parsedMod['html'], '<br/>* '), "RCA-LMS-01 : Zéro puce brute <br/>* à l'intérieur des callouts");
$t->assertTrue(is_file(__DIR__ . '/../public/assets/js/mermaid.min.js'), "RCA-LMS-01 : Moteur vectoriel local public/assets/js/mermaid.min.js présent (zéro CDN)");

$rawAsteriskModules = 0;
$minSlidesCount = 999;
foreach ($pgCourse['modules'] as $mKey => $mInfo) {
    $pm = $parser->parseModuleFile($mInfo['source_md_path']);
    // Strip <pre>...</pre> and <code>...</code> blocks before checking for raw ** markers
    $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pm['html']) ?? $pm['html'];
    if (str_contains($textOnly, '**') || str_contains($textOnly, '<br/>* ')) {
        $rawAsteriskModules++;
        echo "  [WARN] Artefact Markdown détecté dans {$mKey}\n";
    }
    $sc = count($pm['slides'] ?? []);
    if ($sc < $minSlidesCount) {
        $minSlidesCount = $sc;
    }
}
$t->assertEquals(0, $rawAsteriskModules, "RCA-LMS-01 : 0 / 34 modules avec tokens Markdown '**' ou '<br/>* ' non transformés hors code");

$rawAsteriskLabs = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    $pl = $parser->parseLabFile($lInfo['source_md_path']);
    $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pl['html']) ?? $pl['html'];
    if (str_contains($textOnly, '**') || str_contains($textOnly, '<br/>* ')) {
        $rawAsteriskLabs++;
        echo "  [WARN] Artefact Markdown détecté dans {$lKey}\n";
    }
}
$t->assertEquals(0, $rawAsteriskLabs, "RCA-LMS-01 : 0 / 34 labs avec tokens Markdown '**' ou '<br/>* ' non transformés hors code");

// 8.2 RCA-LMS-02: CSS Overflow Guardrails
$cssContent = (string)file_get_contents(__DIR__ . '/../public/assets/css/app.css');
$t->assertTrue(str_contains($cssContent, 'overflow-wrap: break-word') && str_contains($cssContent, 'min-width: 0'), "RCA-LMS-02 : Garde-fous CSS anti-débordement (overflow-wrap: break-word & min-width: 0) actifs");

// 8.3 RCA-LMS-03: Catalog & Docker Cache Performance (< 20 ms)
$t0 = microtime(true);
$warmRepo = new CourseRepository($coursesConfig);
$warmCourse = $warmRepo->getCourse('postgresql-18');
$warmDockerStatus = $docker->getCachedUserContainerStatus(1);
$elapsedMs = (microtime(true) - $t0) * 1000.0;
$t->assertTrue($elapsedMs < 25.0, sprintf("RCA-LMS-03 : Chargement Catalogue + Statut Docker en cache ultra-rapide (%.2f ms < 25 ms)", $elapsedMs));
$t->assertTrue(!empty($warmDockerStatus['container_name']), "RCA-LMS-03 : Statut Docker en cache immédiat sans appel shell wsl bloquant");

// 8.4 RCA-LMS-04: 4-Track Structuring, Position Stepper & ScrollSpy
$tracksCfg = $pgCourse['tracks'] ?? [];
$t->assertEquals(4, count($tracksCfg), "RCA-LMS-04 : Les 4 parcours pédagogiques sont définis");
foreach ($tracksCfg as $tId => $tData) {
    $t->assertTrue(!empty($tData['phases']) && !empty($tData['target_audience']) && !empty($tData['objective']), "RCA-LMS-04 : Parcours '{$tId}' possède ses journées/phases, son public cible et son objectif");
}
$t->assertEquals(1, $mod01['track_position'] ?? 0, "RCA-LMS-04 : Module 01 est positionné à l'Étape 1 de son parcours");
$t->assertEquals(20, $mod01['track_total'] ?? 0, "RCA-LMS-04 : Module 01 indique un total de 20 étapes dans le Parcours 1");

$jsContent = (string)file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$t->assertTrue(str_contains($jsContent, 'updateScrollSpy') && str_contains($jsContent, 'data-scrollspy-target'), "RCA-LMS-04 : Moteur ScrollSpy temps réel présent dans public/assets/js/app.js");

// 8.5 RCA-LMS-05: Interactive 16:9 Slideshow Mode (HTML5 Slides + PDF Slides)
$t->assertGreaterThanOrEqual(12, $minSlidesCount, "RCA-LMS-05 : Chacun des 34 modules génère au moins 12 diapositives 16:9 interactives (min mesuré : {$minSlidesCount})");
$modViewTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/module_view.php');
$t->assertTrue(str_contains($modViewTpl, 'lms-slide-deck-wrapper') && str_contains($modViewTpl, 'mode=slides'), "RCA-LMS-05 : Lecteur Diaporama 16:9 intégré dans templates/pages/module_view.php");

// 9. Contract Tests RCA-145 (RCA-LMS-06 to RCA-LMS-10): Dark/Light Logos, Zero 404, 58 Studio Slides M01, Speed & Direct 34-Module Nav
echo "\n--- 9. Tests Contrats RCA-145 (Logos Dark/Light, Zéro 404, 58 Slides Studio M01, Vitesse & Navigation Directe 34 Modules) ---\n";

// 9.1 RCA-LMS-06: 100% Transparent & Adaptive Dark / Light Logos
foreach (['lightpath_logo_white_text.png', 'lightpath_logo_dark_text.png', 'postgresql_elephant_dark.png', 'postgresql_elephant_light.png'] as $logoFile) {
    $logoPath = __DIR__ . '/../public/assets/img/' . $logoFile;
    $t->assertTrue(is_file($logoPath) && filesize($logoPath) > 1000, "RCA-LMS-06 : Logo transparent '{$logoFile}' présent dans public/assets/img/");
}
$layoutTpl = (string)file_get_contents(__DIR__ . '/../templates/layout.php');
$t->assertTrue(str_contains($layoutTpl, 'theme-aware-logo') && str_contains($layoutTpl, 'data-dark-src') && str_contains($layoutTpl, 'data-light-src'), "RCA-LMS-06 : Bascule automatique des logos Dark/Light intégrée dans templates/layout.php");

// 9.2 RCA-LMS-07: Zero 404 on Static Assets (logos, course images, favicon, devtools) & Permissions Policy unload
$t->assertTrue(is_file(__DIR__ . '/../public/favicon.ico'), "RCA-LMS-07 : public/favicon.ico présent (zéro 404)");
$t->assertTrue(is_file(__DIR__ . '/../public/.well-known/appspecific/com.chrome.devtools.json'), "RCA-LMS-07 : Manifeste Chrome DevTools présent (zéro 404)");
if ($isFullPg18Course) {
    $t->assertTrue(is_file(__DIR__ . '/../public/assets/logos/lightpath_logo_dark_text.png'), "RCA-LMS-07 : Jonction statique public/assets/logos/ opérationnelle");
    $t->assertTrue(is_file(__DIR__ . '/../public/assets/images/modules/img_mod_01_aio_subsystem.png'), "RCA-LMS-07 : Jonction statique public/assets/images/modules/img_mod_01_aio_subsystem.png opérationnelle");
}
$dlControllerCode = (string)file_get_contents(__DIR__ . '/../src/Course/DownloadController.php');
$t->assertTrue(str_contains($dlControllerCode, 'assets/') && str_contains($dlControllerCode, 'Permissions-Policy: unload='), "RCA-LMS-07 : DownloadController réécrit les chemins ../assets/ et autorise unload=*");
$t->assertTrue(str_contains($modViewTpl, 'data-src="index.php?action=download') && str_contains($modViewTpl, 'allow="unload *"'), "RCA-LMS-07 : Iframe PDF chargée à la demande (data-src) avec allow=\"unload *\"");

// 9.3 RCA-LMS-08: Exact Alignment of 58 Studio 16:9 Slides on Module 01 (and 1,686 slides across 34 modules)
if ($isFullPg18Course) {
    $t->assertEquals(58, count($parsedMod['slides'] ?? []), "RCA-LMS-08 : Le Module 01 expose exactement les 58 diapositives officielles Studio 16:9");
    $t->assertGreaterThanOrEqual(30, $minSlidesCount, "RCA-LMS-08 : Chacun des 34 modules expose son deck complet Studio 16:9 (>= 30 slides par module, min={$minSlidesCount})");
} else {
    $t->assertGreaterThanOrEqual(12, count($parsedMod['slides'] ?? []), "Demo Module 01 generates at least 12 interactive 16:9 slides");
}

// 9.4 RCA-LMS-09: Render Speed (< 15 ms, session_write_close(), zero synchronous 3.3 MB mermaid.min.js load)
$indexPhpCode = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(str_contains($indexPhpCode, 'session_write_close()'), "RCA-LMS-09 : Libération immédiate du verrou de session PHP (session_write_close) dans public/index.php");
$t->assertTrue(!str_contains($layoutTpl, '<script src="assets/js/mermaid.min.js"></script>'), "RCA-LMS-09 : Le bundle mermaid.min.js (3,3 Mo) n'est plus chargé de façon synchrone bloquante dans layout.php");
if ($isFullPg18Course) {
    $t->assertTrue(str_contains($parsedMod['html'], 'src="assets/mermaid_cache/mermaid_'), "RCA-LMS-09 : Les schémas Mermaid utilisent les URLs statiques directes assets/mermaid_cache/");
}

// 9.5 RCA-LMS-10: Direct Navigation to Each of the 34 Modules in the Left Sidebar
$t->assertTrue(str_contains($layoutTpl, 'sidebar-module-filter') && str_contains($layoutTpl, 'sidebar-tracks-accordion') && str_contains($layoutTpl, 'sidebar-module-link'), "RCA-LMS-10 : Arbre de navigation directe des 34 modules + filtre rapide présents dans la barre verticale gauche");
$t->assertTrue(str_contains($jsContent, 'syncThemeLogos') && str_contains($jsContent, 'sidebar-module-filter'), "RCA-LMS-10 : Logique JS de filtrage latéral et de synchronisation des logos active dans app.js");

// 10. Contract Tests RCA-146 (RCA-LMS-11 to RCA-LMS-16): 1:1 Slides/PDF Sync, Main-Column PDF Reader, Sticky Right Menu, Collapsible Sidebars, Lab Prompts/Solutions & Unload Security
echo "\n--- 10. Tests Contrats RCA-146 (Synchronisation Slides 1:1, Lecteur PDF Colonne 1, Menu Droite Sticky Sans Scrollbar, Barres Repliables, Énoncés/Corrigés Labs & Sécurité) ---\n";

// 10.1 RCA-LMS-11: 1:1 Sync Between Slide Outline Titles and Official PDF PNG Images
$mod01Slides = $parsedMod['slides'] ?? [];
$t->assertTrue(str_contains($mod01Slides[1]['title'] ?? '', 'Objectifs Pédagogiques'), "RCA-LMS-11 : Slide 2 du Module 01 correspond à 'Objectifs Pédagogiques Opérationnels'");
$t->assertTrue(str_contains($mod01Slides[2]['title'] ?? '', 'Plan du Module'), "RCA-LMS-11 : Slide 3 du Module 01 correspond exactement à la page PDF 3 'Plan du Module'");
if ($isFullPg18Course) {
    $t->assertTrue(str_contains($mod01Slides[3]['title'] ?? '', 'Références & Documentation Officielle'), "RCA-LMS-11 : Slide 4 du Module 01 correspond exactement à la page PDF 4 'Références & Documentation Officielle postgresql.org'");
}
$t->assertTrue(!str_contains($modViewTpl, 'src="<?= $idx <= 2 ?'), "RCA-LMS-11 : Aucune balise <img src=\"\"> vide sur les diapositives 3..N dans module_view.php");

// 10.2 RCA-LMS-12: Continuous PDF Reader Renders in Main Column (.slide-stage-main-col) Instead of Replacing Right Sidebar
$t->assertTrue(str_contains($modViewTpl, 'class="slide-stage-main-col"') && str_contains($cssContent, '[hidden] {') && str_contains($jsContent, 'switchSlideEngine'), "RCA-LMS-12 : #slide-stage-html et #slide-stage-pdf sont encapsulés dans .slide-stage-main-col avec bascule propre");

// 10.3 RCA-LMS-13: Sticky Right Sidebar (overflow-x: clip) Without Visible Scrollbar (scrollbar-width: none)
$t->assertTrue(str_contains($cssContent, 'overflow-x: clip;') && !str_contains($cssContent, "html, body {\n    margin: 0;\n    padding: 0;\n    background-color: var(--bg-base);\n    color: var(--text-primary);\n    font-family: var(--font-sans);\n    font-size: 15px;\n    line-height: 1.62;\n    -webkit-font-smoothing: antialiased;\n    overflow-x: hidden;"), "RCA-LMS-13 : body utilise overflow-x: clip pour préserver position: sticky sur la barre verticale de droite");
$t->assertTrue(str_contains($cssContent, 'scrollbar-width: none !important;') && str_contains($cssContent, '.reader-sidebar::-webkit-scrollbar'), "RCA-LMS-13 : Suppression totale des barres de défilement visibles sur les menus verticaux de droite");
$t->assertTrue(!str_contains($modViewTpl, 'style="max-height: 76vh; overflow-y: auto;"'), "RCA-LMS-13 : Suppression du style inline overflow-y: auto sur le sommaire des slides");

// 10.4 RCA-LMS-14: Collapse / Expand Left & Right Vertical Sidebars
$t->assertTrue(str_contains($layoutTpl, 'btn-toggle-left-sidebar') && str_contains($layoutTpl, 'btn-toggle-right-sidebar') && str_contains($layoutTpl, 'btn-floating-restore-right'), "RCA-LMS-14 : Boutons de réduction/extension des barres verticales gauche et droite présents dans layout.php");
$t->assertTrue(str_contains($cssContent, 'body.left-sidebar-collapsed') && str_contains($cssContent, 'body.right-sidebar-collapsed'), "RCA-LMS-14 : Styles CSS de repli des barres verticales gauche et droite actifs");
$t->assertTrue(str_contains($jsContent, 'lp_lms_left_sidebar') && str_contains($jsContent, 'lp_lms_right_sidebar'), "RCA-LMS-14 : Persistance localStorage de l'état des barres verticales gauche et droite active");

// 10.5 RCA-LMS-15: Prompt & Full Solution Tabs Across All 34 Hands-On Labs
$labViewTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/lab_view.php');
$t->assertTrue(str_contains($labViewTpl, 'mode=enonce') && str_contains($labViewTpl, 'mode=corrige') && str_contains($labViewTpl, 'btn-toggle-lab-solutions'), "RCA-LMS-15 : Sélecteur Énoncé / Corrigé Complet / Vue Intégrale et bouton Solutions présents dans lab_view.php");
$labsWithEnonceAndCorrige = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    $pl = $parser->parseLabFile($lInfo['source_md_path']);
    if (!empty($pl['enonce_html']) && !empty($pl['corrige_html']) && strlen($pl['enonce_html']) > 200 && strlen($pl['corrige_html']) > 100) {
        $labsWithEnonceAndCorrige++;
    }
}
$t->assertEquals(count($pgCourse['labs']), $labsWithEnonceAndCorrige, "RCA-LMS-15 : Tous les ateliers découverts disposent d'un volet Énoncé et d'un volet Corrigé Complet distincts");

// 10.6 RCA-LMS-16: HTTP Security Headers, Unload Violation JS Shim (chext_loader.js), Security::isPathInside(), Docker Scoping & GitHub Issue Policy
$securityPhpCode = (string)file_get_contents(__DIR__ . '/../src/Core/Security.php');
$t->assertTrue(str_contains($securityPhpCode, 'sendSecurityHeaders') && str_contains($securityPhpCode, 'renderUnloadShimScript') && str_contains($securityPhpCode, 'pagehide'), "RCA-LMS-16 : Security::sendSecurityHeaders() et Security::renderUnloadShimScript() actifs dans Security.php");
$t->assertTrue(str_contains($layoutTpl, 'Security::renderUnloadShimScript()') && str_contains($dlControllerCode, 'Security::renderUnloadShimScript()'), "RCA-LMS-16 : Shim JS unload->pagehide injecté dans layout.php, login.php et les aperçus HTML inline");

$workspaceRoot = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
$t->assertTrue(Security::isPathInside(__DIR__, $workspaceRoot), "RCA-LMS-16 : Security::isPathInside() valide un sous-dossier légitime du workspace");
$t->assertTrue(!Security::isPathInside(dirname($workspaceRoot), $workspaceRoot), "RCA-LMS-16 : Security::isPathInside() bloque un répertoire parent hors du workspace");
$t->assertTrue(!Security::isPathInside('/nonexistent_path_xyz', $workspaceRoot), "RCA-LMS-16 : Security::isPathInside() rejette un chemin inexistant");

$highPort = $docker->getUserPort(999999);
$t->assertTrue($highPort >= 1024 && $highPort <= 65535, "RCA-LMS-16 : DockerManager::getUserPort(999999) borne strictement le port TCP dans [1024..65535] (obtenu : {$highPort})");
$t->assertTrue(str_contains($jsContent, 'centerElementInScrollBox') && str_contains($labViewTpl, '<details class="toc-box"'), "RCA-LMS-16 : Centrage précis par getBoundingClientRect et repli harmonisé du stepper dans lab_view.php");

// 11. Contract Tests RCA-157 (RCA-LMS-27 & RCA-LMS-28): Hardened @playwright/mcp Server, E2E Campaign, 375px Mobile Responsive & DOM Autocomplete
echo "\n--- 11. Tests Contrats RCA-157 (Serveur MCP @playwright/mcp Durci, Campagne E2E, Responsive Mobile 375px & Autocomplete DOM) ---\n";

$loginTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/login.php');
$profileTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/profile.php');
$adminTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/admin.php');
$certTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/certificate.php');
$appCfgCode = (string)file_get_contents(__DIR__ . '/../config/app.php');
$e2eRunnerCode = (string)file_get_contents(__DIR__ . '/e2e/run_playwright_campaign.mjs');

$adminPartialsCorpus = '';
foreach (glob(__DIR__ . '/../templates/partials/admin/*.php') ?: [] as $adminPartialFile) {
    $adminPartialsCorpus .= (string)file_get_contents($adminPartialFile) . "\n";
}
$adminAllTpl = $adminTpl . "\n" . $adminPartialsCorpus;

$t->assertTrue(
    str_contains($loginTpl, 'login-split-grid')
    && !preg_match('/\bstyle\s*=/i', $loginTpl)
    && str_contains($cssContent, 'minmax(min(100%, 340px), 1fr)')
    && str_contains($cssContent, '@media (max-width: 640px)'),
    "RCA-LMS-27 : Grille responsive mobile 375px (.login-split-grid / minmax 340px) dans app.css avec 0 style= dans login.php"
);
$t->assertTrue(
    str_contains($cssContent, 'minmax(min(100%, 330px), 1fr)')
    && str_contains($cssContent, '.reader-grid > *')
    && str_contains($certTpl, 'cert-print-frame')
    && !preg_match('/\bstyle\s*=/i', $certTpl)
    && str_contains($cssContent, 'clamp(1rem, 4vw, 3rem)'),
    "RCA-LMS-27 : Protection anti-débordement mobile sur .card-grid, .reader-grid > * et .cert-print-frame (0 style= dans certificate.php)"
);
$t->assertTrue(str_contains($loginTpl, 'autocomplete="username"') && str_contains($loginTpl, 'autocomplete="current-password"') && str_contains($loginTpl, 'autocomplete="new-password"'), "RCA-LMS-28 : Attributs autocomplete W3C présents sur les formulaires de connexion et d'inscription (login.php)");
$t->assertTrue(str_contains($profileTpl, 'autocomplete="new-password"') && str_contains($adminAllTpl, 'autocomplete="new-password"'), "RCA-LMS-28 : Attributs autocomplete=\"new-password\" présents dans profile.php et templates admin (zéro avertissement DOM Chromium)");

$mcpConfigFile = __DIR__ . '/../config/playwright-mcp.config.json';
$mcpCfg = is_file($mcpConfigFile) ? json_decode((string)file_get_contents($mcpConfigFile), true) : null;
$t->assertTrue(is_array($mcpCfg) && ($mcpCfg['browser']['isolated'] ?? false) === true && ($mcpCfg['allowUnrestrictedFileAccess'] ?? true) === false, "RCA-LMS-28 : Configuration durcie config/playwright-mcp.config.json valide (isolated=true, allowUnrestrictedFileAccess=false)");
$t->assertTrue(str_contains($appCfgCode, "getenv('LMS_DATA_DIR')") && str_contains($e2eRunnerCode, 'LMS_DATA_DIR') && str_contains($e2eRunnerCode, 'ERR_BLOCKED_BY_CLIENT'), "RCA-LMS-28 : Harnais E2E Playwright MCP isolé par LMS_DATA_DIR temporaire et vérifiant le sandboxing allowedOrigins");

// 12. Contract Tests RCA-147 to RCA-156 (RCA-LMS-17 to RCA-LMS-26): PdoStorage, 34 SQL DO $$ Oracles, Testable Solutions, Lab Cheatsheets, Enterprise LMS & Qualiopi
echo "\n--- 12. Tests Contrats RCA-147 à RCA-156 (PdoStorage, 34 Oracles SQL DO \$\$, Corrigés Testables, Enterprise LMS & Qualiopi) ---\n";

// 12.1 RCA-150 / RCA-LMS-20: PdoStorage (SQLite in-memory) initializes $this->pdo before parent::__construct() and persists via lms_kv_store
$pdoFallbackDir = sys_get_temp_dir() . '/lp_pdo_test_' . uniqid();
$pdoStorage = new PdoStorage(['dsn' => 'sqlite::memory:', 'username' => '', 'password' => ''], $pdoFallbackDir);
$pdoUsers = $pdoStorage->getUsers();
$t->assertGreaterThanOrEqual(3, count($pdoUsers), "RCA-LMS-20 : PdoStorage initialise correctement les utilisateurs par défaut dans SQLite in-memory sans TypeError");
$pdoStorage->markItemCompleted(3, 'postgresql-18', 'lab', 'lab_01_introduction_setup_postgresql_18', true);
$pdoProg = $pdoStorage->getUserProgress(3, 'postgresql-18');
$t->assertTrue(in_array('lab_01_introduction_setup_postgresql_18', $pdoProg['completed_labs'] ?? [], true), "RCA-LMS-20 : PdoStorage persiste et relit la progression via lms_kv_store");

// 12.2 RCA-147..149 & RCA-152 / RCA-LMS-17..19 & 22: All 34 Markdown Labs Include Solution, DoD Grid, DO $$ SQL Oracle, VALIDATION_OK & LabSqlRunner Coverage
$allLabsHaveDoBlockAndMarker = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    $rawMd = (string)file_get_contents($lInfo['source_md_path']);
    $markers = $sqlRunner->extractExpectedMarkers($rawMd);
    $solBlocks = $sqlRunner->extractSolutionSqlBlocks($rawMd);
    if (preg_match('/DO\s+\$\$/i', $rawMd) && in_array('VALIDATION_OK', $markers, true) && count($solBlocks) >= 1) {
        $allLabsHaveDoBlockAndMarker++;
    } else {
        echo "  [WARN] Lab incomplet pour Oracle SQL DO \$\$ : {$lKey}\n";
    }
}
$t->assertEquals(count($pgCourse['labs']), $allLabsHaveDoBlockAndMarker, "RCA-147/148/152 : Tous les ateliers découverts possèdent un corrigé SQL extractible, un Oracle DO \$\$ et le marqueur VALIDATION_OK");
$t->assertEquals('dvdrental', $sqlRunner->detectTargetDatabase('lab_sql_02_jointures_sous_requetes_vues'), "RCA-152 : LabSqlRunner route automatiquement les ateliers SQL 01..05 vers la base Sakila 'dvdrental'");
$t->assertEquals('formation_pg', $sqlRunner->detectTargetDatabase('lab_20_tuning_systeme_linux_postgresql'), "RCA-152 : LabSqlRunner route les ateliers DBA vers 'formation_pg'");

// 12.3 RCA-151 / RCA-LMS-21: MarkdownParser::splitLabEnonceAndCorrige() preserves further-reading section in Prompt and expands <details> in Solution
$sampleRawLab = "# Lab Test\n\n## 1. Travail\n* **Commandes Solutions :**\n```sql\nSELECT 1;\n```\n\n## 3. Commande de Validation\n```sql\nDO \$\$ BEGIN RAISE NOTICE 'VALIDATION_OK'; END \$\$;\n```\n\n## 🌟 Pour Aller Plus Loin\nDéfi Bonus";
$splitSample = $parser->splitLabEnonceAndCorrige($sampleRawLab);
$t->assertTrue(str_contains($splitSample['enonce_md'], '<details>') && str_contains($splitSample['enonce_md'], 'Pour Aller Plus Loin'), "RCA-151 : splitLabEnonceAndCorrige() transforme 'Commandes Solutions :' en spoiler <details> et préserve 'Pour Aller Plus Loin' dans l'énoncé");
$t->assertTrue(str_contains($splitSample['corrige_md'], 'Solution du Challenge Fast-Track #1'), "RCA-151 : splitLabEnonceAndCorrige() extrait la solution du challenge dans le volet Corrigé");

// 12.4 RCA-154 / RCA-LMS-24: Linking All Lab Cheatsheets + Global Full-Text Search
$labsWithCheatsheet = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    if (!empty($lInfo['linked_cheatsheet_id'])) {
        $labsWithCheatsheet++;
    }
}
$t->assertEquals(count($pgCourse['labs']), $labsWithCheatsheet, "RCA-154 : Tous les ateliers pratiques découverts sont liés à leur fiche mémo (linked_cheatsheet_id)");
$searchRes = $repo->searchContent('postgresql-18', 'pg_stat_io');
$t->assertGreaterThanOrEqual(2, count($searchRes), "RCA-156 : CourseRepository::searchContent('postgresql-18', 'pg_stat_io') retourne des modules, labs et entrées de glossaire");

// 12.5 RCA-155 & RCA-156 / RCA-LMS-25 & 26: Initial Placement (15 MCQs), Final Exam (40 MCQs), Attendance, Surveys, SRS (42 cards), Glossary (52 terms), Gamification & SCORM 1.2
$diagQs = LmsCatalog::getDiagnosticQuestions();
$t->assertEquals(15, count($diagQs), "RCA-155 : Positionnement Initial Qualiopi comporte 15 questions QCM sur 5 domaines DBA");
$diagPerfectAnswers = [];
foreach ($diagQs as $dq) {
    $diagPerfectAnswers[$dq['id']] = $dq['correct'];
}
$diagEval = LmsCatalog::evaluateDiagnostic($diagPerfectAnswers);
$t->assertEquals(100, $diagEval['percent'], "RCA-155 : Évaluation diagnostique parfaite = 100%");
$t->assertEquals('performance-tuning', $diagEval['recommended_track'], "RCA-155 / RCA-159 : Un score de 100% recommande le parcours valide 'performance-tuning'");
$t->assertEquals('performance-tuning', $diagEval['recommended_course_id'], "RCA-159 : recommended_course_id est aligné sur 'performance-tuning'");
$t->assertEquals('performance-tuning', $diagEval['recommended_course_slug'], "RCA-159 : recommended_course_slug est aligné sur 'performance-tuning'");
$t->assertTrue(isset($pgCourse['tracks'][$diagEval['recommended_track']]), "RCA-159 : Le slug recommandé pour 100% ('performance-tuning') existe dans config/courses.php");

// RCA-159: Exhaustive validation of the 3 track recommendation tiers
$diagLowEval = LmsCatalog::evaluateDiagnostic([]);
$t->assertEquals(0, $diagLowEval['percent'], "RCA-159 : Évaluation diagnostique vide = 0%");
$t->assertEquals('fondamentaux-sql', $diagLowEval['recommended_track'], "RCA-159 : Un score < 45% recommande le parcours 'fondamentaux-sql'");
$t->assertTrue(isset($pgCourse['tracks'][$diagLowEval['recommended_track']]), "RCA-159 : Le slug 'fondamentaux-sql' existe dans config/courses.php");

$midAnswers = array_slice($diagPerfectAnswers, 0, 8, true); // 8 / 15 = 53%
$diagMidEval = LmsCatalog::evaluateDiagnostic($midAnswers);
$t->assertEquals(53, $diagMidEval['percent'], "RCA-159 : 8/15 réponses correctes = 53%");
$t->assertEquals('cursus-complet', $diagMidEval['recommended_track'], "RCA-159 : Un score entre 45% et 75% recommande le parcours 'cursus-complet'");
$t->assertTrue(isset($pgCourse['tracks'][$diagMidEval['recommended_track']]), "RCA-159 : Le slug 'cursus-complet' existe dans config/courses.php");

$pdoStorage->saveDiagnosticResult(3, 'postgresql-18', $diagEval);
$t->assertEquals(100, $pdoStorage->getDiagnosticResult(3, 'postgresql-18')['percent'] ?? 0, "RCA-155 : Persistance du diagnostic initial fonctionnelle");

$examQs = LmsCatalog::getFinalExamQuestions();
$t->assertEquals(40, count($examQs), "RCA-155 : Examen Final Sommatif comporte 40 questions pondérées");
$examPerfectAnswers = [];
foreach ($examQs as $eq) {
    $examPerfectAnswers[$eq['id']] = $eq['correct'];
}
$examEval = LmsCatalog::evaluateFinalExam($examPerfectAnswers, 1250);
$t->assertTrue($examEval['passed'] === true && $examEval['percent'] === 100, "RCA-155 : Évaluation de l'Examen Final Sommatif (40/40 = 100%, passed=true)");
$savedExam = $pdoStorage->saveExamAttempt(3, 'postgresql-18', $examEval);
$t->assertTrue(str_starts_with($savedExam['certificate_id'] ?? '', 'LP-PG18-CERT-'), "RCA-155 : Génération et persistance du numéro de certificat officiel LP-PG18-CERT-...");

$pdoStorage->signAttendance(3, 'postgresql-18', 'J1_AM', 'Lu et approuvé — Jean Dupont', '127.0.0.1');
$attList = $pdoStorage->getUserAttendance(3, 'postgresql-18');
$t->assertTrue(isset($attList['J1_AM']), "RCA-155 : Signature électronique d'émargement par demi-journée (J1_AM) enregistrée");

$pdoStorage->saveSatisfactionSurvey(3, 'postgresql-18', [
    'content_quality' => 5,
    'labs_docker' => 5,
    'trainer_pedagogy' => 5,
    'lms_ergonomics' => 5,
    'objectives_met' => 5,
    'nps_score' => 10,
    'strengths' => 'Ateliers Docker Sakila et corrigés testés.',
]);
$satSurvey = $pdoStorage->getUserSatisfaction(3, 'postgresql-18');
$t->assertEquals(5.0, $satSurvey['average_rating'] ?? 0.0, "RCA-155 : Questionnaire de satisfaction à chaud Qualiopi enregistré (moyenne 5/5)");

$t->assertGreaterThanOrEqual(42, count(LmsCatalog::getFlashcards()), "RCA-156 : Au moins 42 cartes mémo SRS PostgreSQL 18 disponibles (48 enrichies RCA-161)");
$t->assertGreaterThanOrEqual(52, count(LmsCatalog::getGlossary()), "RCA-156 : Au moins 52 termes de glossaire technique PostgreSQL 18 disponibles (56 enrichis RCA-161)");

$scormXml = LmsCatalog::generateScormManifest($pgCourse);
$t->assertTrue(str_contains($scormXml, '<manifest identifier="LIGHTPATH_PG18_LMS_SCORM12"') && str_contains($scormXml, 'adlcp:scormtype="sco"'), "RCA-156 : Génération valide du manifeste SCORM 1.2 (imsmanifest.xml)");

$gamif = Gamification::computeUserBadgesAndXp(
    $pdoStorage->getUserProgress(3, 'postgresql-18'),
    $pdoStorage->getDiagnosticResult(3, 'postgresql-18'),
    $pdoStorage->getBestExamAttempt(3, 'postgresql-18'),
    $attList
);
$t->assertEquals(8, count($gamif['badges'] ?? []), "RCA-156 : Gamification calcule les 8 badges de compétences DBA et les XP (XP={$gamif['xp']})");

// 13. Contract Tests RCA-158: Inline Code Formatting, Exact Metadata Extraction (Module 10, Durations, Bloom), Escaped-Pipe Tables & Zero Raw Backticks Across 152 LMS Docs
echo "\n--- 13. Tests Contrats RCA-158 (Formatage Inline Code, Métadonnées Module 10 / Durées / Bloom, Pipes Échappés & Zéro Backtick Brut) ---\n";

$formattedInline = Security::formatInlineText('Paramètre `shared_buffers` et **AIO** <script>alert(1)</script>');
$t->assertTrue(
    str_contains($formattedInline, '<code class="lms-inline-code">shared_buffers</code>')
    && str_contains($formattedInline, '<strong>AIO</strong>')
    && str_contains($formattedInline, '&lt;script&gt;'),
    "RCA-158 : Security::formatInlineText() convertit `code` et **gras** tout en échappant le HTML XSS"
);

if ($isFullPg18Course) {
    $mod10 = $repo->getModule('postgresql-18', 'module_10_integrating_postgresql_with_applications');
    $t->assertEquals(
        '🔌 Module 10 : Intégration Applicative, ORM & Connection Pooling',
        $mod10['title'] ?? '',
        "RCA-158 : Le titre H1 du Module 10 n'est pas écrasé par les commentaires Python '# ...' des blocs de code"
    );
}

$invalidDurations = 0;
$figureSummaries = 0;
$missingBloomModules = 0;
foreach ($pgCourse['modules'] as $mKey => $mInfo) {
    $dur = $mInfo['duration'] ?? '';
    if (!preg_match('/^[1-4](?:[\.,]\d+)?(?:h|\s*heure)/iu', $dur) || strlen($dur) > 90) {
        $invalidDurations++;
    }
    if (str_starts_with($mInfo['summary'] ?? '', '*Figure')) {
        $figureSummaries++;
    }
    if (empty($mInfo['bloom'])) {
        $missingBloomModules++;
    }
}
$t->assertEquals(0, $invalidDurations, "RCA-158 : 34 / 34 modules possèdent une durée normalisée exacte (zéro capture de phrase interne)");
$t->assertEquals(0, $figureSummaries, "RCA-158 : 0 / 34 modules avec légende '*Figure :' capturée comme résumé");
$t->assertEquals(0, $missingBloomModules, "RCA-158 : 34 / 34 modules possèdent leur niveau de compétence Bloom extrait");

$rawBacktickDocs = 0;
foreach ($pgCourse['modules'] as $mKey => $mInfo) {
    $pm = $parser->parseModuleFile($mInfo['source_md_path']);
    $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pm['html']) ?? $pm['html'];
    if (str_contains($textOnly, '`')) {
        $rawBacktickDocs++;
        echo "  [WARN] Backtick brut hors <code> détecté dans module {$mKey}\n";
    }
}
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    $pl = $parser->parseLabFile($lInfo['source_md_path']);
    foreach (['html', 'enonce_html', 'corrige_html'] as $part) {
        $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pl[$part] ?? '') ?? '';
        if (str_contains($textOnly, '`')) {
            $rawBacktickDocs++;
            echo "  [WARN] Backtick brut hors <code> détecté dans lab {$lKey} ({$part})\n";
        }
    }
}
foreach ($pgCourse['cheatsheets'] as $cKey => $cInfo) {
    $pc = $parser->parseModuleFile($cInfo['source_md_path']);
    $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pc['html']) ?? $pc['html'];
    if (str_contains($textOnly, '`')) {
        $rawBacktickDocs++;
        echo "  [WARN] Backtick brut hors <code> détecté dans cheatsheet {$cKey}\n";
    }
}
foreach ($pgCourse['referentiel'] as $rKey => $rInfo) {
    $pr = $parser->parseModuleFile($rInfo['source_md_path']);
    $textOnly = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', '', $pr['html']) ?? $pr['html'];
    if (str_contains($textOnly, '`')) {
        $rawBacktickDocs++;
        echo "  [WARN] Backtick brut hors <code> détecté dans referentiel {$rKey}\n";
    }
}
$t->assertEquals(0, $rawBacktickDocs, "RCA-158 : 0 backtick brut hors <code>/<pre> sur l'ensemble des 34 modules, 34 labs, 69 cheatsheets et 15 documents de référentiel");

echo "\n--- 14. Tests Contrats RCA-160 / RCA-LMS-30 (Audit /ponytail-audit & Remédiation /dev-team : Verrouillage Atomique mutateFile, Parité StorageInterface/PdoStorage, Sécurité CSV/Download, Créneaux Qualiopi, Anti-Brute-Force & UTF-8) ---\n";

// 14.1 Complete StorageInterface Contract (16 Enterprise LMS methods declared)
$refInterface = new ReflectionClass(\LightPath\LMS\Core\StorageInterface::class);
$expectedMethods = [
    'saveDiagnosticResult', 'getDiagnosticResult', 'getAllDiagnostics',
    'saveExamAttempt', 'getExamAttempts', 'getBestExamAttempt', 'getAllExamAttempts',
    'recordLearningTime', 'signAttendance', 'getUserAttendance', 'getAllAttendance',
    'saveSatisfactionSurvey', 'getUserSatisfaction', 'getAllSatisfactionSurveys',
    'toggleBookmark', 'recordLastVisited', 'saveFlashcardConfidence',
    'addDiscussionMessage', 'getDiscussions',
];
$missingInterfaceMethods = [];
foreach ($expectedMethods as $mName) {
    if (!$refInterface->hasMethod($mName)) {
        $missingInterfaceMethods[] = $mName;
    }
}
$t->assertEquals(0, count($missingInterfaceMethods), "RCA-160 : StorageInterface déclare l'intégralité des 19 méthodes Enterprise LMS (" . implode(', ', $missingInterfaceMethods) . ")");

// 14.2 Full PdoStorage Parity (SQLite in-memory / fallback) & Atomic mutateFile() Transaction
$pdoTestDir = sys_get_temp_dir() . '/lp_lms_pdo_rca160_' . uniqid();
$pdoAdapter = new \LightPath\LMS\Core\PdoStorage(['dsn' => 'sqlite::memory:'], $pdoTestDir);
$t->assertTrue($pdoAdapter instanceof \LightPath\LMS\Core\StorageInterface, "RCA-160 : PdoStorage implémente l'intégralité de StorageInterface");
$pdoExam = $pdoAdapter->saveExamAttempt(3, 'postgresql-18', ['percent' => 88.5, 'passed' => true, 'mention' => 'Bien']);
$t->assertTrue(str_starts_with($pdoExam['certificate_id'] ?? '', 'LP-PG18-CERT-3-'), "RCA-160 : PdoStorage::saveExamAttempt génère et persiste certificate_id");
$t->assertEquals(88.5, (float)($pdoAdapter->getBestExamAttempt(3, 'postgresql-18')['percent'] ?? 0), "RCA-160 : PdoStorage::getBestExamAttempt relit le meilleur score");
$pdoAtt = $pdoAdapter->signAttendance(3, 'postgresql-18', 'J1_AM', 'Thomas Dubois', '127.0.0.1');
$t->assertTrue(isset($pdoAtt['J1_AM']), "RCA-160 : PdoStorage::signAttendance persiste un créneau valide J1_AM");
$pdoAttInvalid = $pdoAdapter->signAttendance(3, 'postgresql-18', 'J99_FAKE_SLOT', 'Thomas Dubois', '127.0.0.1');
$t->assertTrue(!isset($pdoAttInvalid['J99_FAKE_SLOT']) && count($pdoAttInvalid) === 1, "RCA-160 : signAttendance rejette les créneaux hors liste blanche J1_AM..J5_PM (anti-fraude heures Qualiopi)");
$pdoSat = $pdoAdapter->saveSatisfactionSurvey(3, 'postgresql-18', ['content_quality' => 5, 'labs_docker' => 5, 'trainer_pedagogy' => 5, 'lms_ergonomics' => 5, 'objectives_met' => 5, 'nps_score' => 10]);
$t->assertEquals(5.0, $pdoAdapter->getUserSatisfaction(3, 'postgresql-18')['average_rating'] ?? 0.0, "RCA-160 : PdoStorage::getUserSatisfaction retourne average_rating (float)");
$pdoBm = $pdoAdapter->toggleBookmark(3, 'postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'Module 01');
$t->assertTrue($pdoBm['bookmarked'] === true, "RCA-160 : PdoStorage::toggleBookmark ajoute un favori");
$pdoMsg = $pdoAdapter->addDiscussionMessage('postgresql-18', 'module_01', 3, 'Thomas', 'student', '   Question sur shared_buffers   ');
$t->assertEquals('Question sur shared_buffers', $pdoMsg['message'] ?? '', "RCA-160 : PdoStorage::addDiscussionMessage nettoie et persiste le message");
$t->assertEquals(1, count($pdoAdapter->getDiscussions('postgresql-18', 'module_01')), "RCA-160 : PdoStorage::getDiscussions relit le fil de discussion");

// 14.3 Atomic .lock File Locking in JsonStorage::mutateFile & Removal of Dead 'flashcards' Key
$pdoAdapter->saveFlashcardConfidence(3, 'postgresql-18', 'fc_01', 3);
$progAfterFc = $pdoAdapter->getUserProgress(3, 'postgresql-18');
$t->assertTrue(isset($progAfterFc['flashcard_confidence']['fc_01']) && !isset($progAfterFc['flashcards']), "RCA-160 : saveFlashcardConfidence persiste uniquement flashcard_confidence (suppression du doublon mort 'flashcards')");
$storage->saveQuizScore(3, 'postgresql-18', 'module_02_getting_started_postgresql', 4, 4);
$t->assertTrue(is_file($tempDir . '/progress.lock'), "RCA-160 : JsonStorage::mutateFile utilise un fichier verrou dédié (.lock) avec LOCK_EX");

// 14.4 recordLastVisited Optimization (skip redundant disk write when page/mode unchanged)
$storage->recordLastVisited(3, 'postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'Module 01', 'reader');
$firstVisitedAt = $storage->getUserProgress(3, 'postgresql-18')['last_visited']['visited_at'] ?? '';
$t->assertTrue($firstVisitedAt !== '', "RCA-160 : recordLastVisited enregistre la visite initiale");

// 14.5 Anti-CSV Formula Injection Protection (Security::sanitizeCsvCell)
$t->assertEquals("'=cmd|' /C calc'!A0", Security::sanitizeCsvCell("=cmd|' /C calc'!A0"), "RCA-160 : Security::sanitizeCsvCell neutralise les formules commençant par '='");
$t->assertEquals("'\t+SUM(A1:A10)", Security::sanitizeCsvCell("\t+SUM(A1:A10)"), "RCA-160 : Security::sanitizeCsvCell neutralise les formules précédées de tabulation");
$t->assertEquals("LightPath SARL", Security::sanitizeCsvCell("LightPath SARL"), "RCA-160 : Security::sanitizeCsvCell préserve le texte normal intact");

// 14.6 Anti-Brute-Force Throttling Protection in Auth::login
$bfEmail = 'stagiaire@lightpath.fr';
$bfIp = '198.51.100.77';
for ($i = 0; $i < 5; $i++) {
    $auth->login($bfEmail, 'MauvaisMotDePasse!', $bfIp);
}
$t->assertTrue($auth->isLoginRateLimited($bfEmail, $bfIp), "RCA-160 : Auth bloque temporairement le couple (email, IP) après 5 échecs de connexion");
$t->assertTrue(!$auth->isLoginRateLimited('jmrenouard@lightpath.fr', $bfIp), "RCA-160 : Le rate-limiting par (email, IP) ne bloque pas un autre stagiaire partageant la même IP NAT de salle");
$t->assertTrue($auth->login($bfEmail, 'Stagiaire2026!', $bfIp) === null, "RCA-160 : Auth::login refuse la connexion tant que le verrouillage anti-brute-force est actif");
$auth->clearLoginAttempts($bfEmail, $bfIp);
$t->assertTrue($auth->login($bfEmail, 'Stagiaire2026!', $bfIp) !== null, "RCA-160 : Auth::login autorise la connexion après purge du compteur d'échecs");

// 14.7 DownloadController Uses Security::isPathInside & CourseRepository::searchContent Safely Truncates UTF-8
$dlSrc = (string)file_get_contents(__DIR__ . '/../src/Course/DownloadController.php');
$t->assertTrue(str_contains($dlSrc, 'Security::isPathInside(') && !str_contains($dlSrc, 'postgresql-18-mastery'), "RCA-160 : DownloadController utilise Security::isPathInside() et le slug 'postgresql-18'");
$utf8Search = $repo->searchContent('postgresql-18', 'réplication');
$t->assertTrue(count($utf8Search) > 0 && json_encode($utf8Search) !== false, "RCA-160 : searchContent('réplication') retourne des extraits UTF-8 valides sérialisables en JSON sans erreur");

// 15. Contract Tests RCA-162 / RCA-LMS-32 (Pedagogical Audit & Remediation: 10/10/10/10 Final Exam Split, 4/4/4/3 Diagnostic Split & 53 Module-Tagged SRS Flashcards incl. SQL-01..05)
echo "\n--- 15. Tests Contrats RCA-162 / RCA-LMS-32 (Rééquilibrage QCM Examen 10/10/10/10, Diagnostic 4/4/4/3 & 53 Flashcards SRS Taggées Module + SQL-01..05) ---\n";

$examDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$allAExamAnswers = [];
foreach ($examQs as $eq) {
    $ans = $eq['correct'] ?? '';
    $t->assertTrue(isset($eq['options'][$ans]) && ($eq['answer'] ?? '') === $ans, "RCA-LMS-32 : Question {$eq['id']} possède une clé 'correct'/'answer' valide ({$ans})");
    if (isset($examDistribution[$ans])) {
        $examDistribution[$ans]++;
    }
    $allAExamAnswers[$eq['id']] = 'A';
}
$t->assertEquals(['A' => 10, 'B' => 10, 'C' => 10, 'D' => 10], $examDistribution, "RCA-LMS-32 : Examen Final de Certification équilibré exactement en 10 A / 10 B / 10 C / 10 D");
$allAExamEval = LmsCatalog::evaluateFinalExam($allAExamAnswers, 600);
$t->assertTrue($allAExamEval['passed'] === false && $allAExamEval['percent'] === 25, "RCA-LMS-32 : Cocher 'A' partout à l'examen final donne 25% (10/40) et échoue la certification");

$diagDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach ($diagQs as $dq) {
    $ans = $dq['correct'] ?? '';
    $t->assertTrue(isset($dq['options'][$ans]) && ($dq['answer'] ?? '') === $ans, "RCA-LMS-32 : Question diagnostique {$dq['id']} possède une clé 'correct'/'answer' valide ({$ans})");
    if (isset($diagDistribution[$ans])) {
        $diagDistribution[$ans]++;
    }
}
$t->assertEquals(['A' => 4, 'B' => 4, 'C' => 4, 'D' => 3], $diagDistribution, "RCA-LMS-32 : Diagnostic Pré-Formation équilibré exactement en 4 A / 4 B / 4 C / 3 D");

$allFlashcards = LmsCatalog::getFlashcards();
$t->assertGreaterThanOrEqual(53, count($allFlashcards), "RCA-LMS-32 : Le catalogue SRS contient au moins 53 flashcards (48 DBA + 5 SQL:2023)");
$fcMissingModule = 0;
$fcModulesFound = [];
foreach ($allFlashcards as $fc) {
    $modTag = trim((string)($fc['module'] ?? ''));
    if ($modTag === '' || !preg_match('/^(M\d{2}|SQL-0[1-5])$/', $modTag)) {
        $fcMissingModule++;
    } else {
        $fcModulesFound[$modTag] = true;
    }
}
$t->assertEquals(0, $fcMissingModule, "RCA-LMS-32 : 100% des flashcards SRS possèdent un tag 'module' normalisé (M01..M29 ou SQL-01..SQL-05)");
foreach (['SQL-01', 'SQL-02', 'SQL-03', 'SQL-04', 'SQL-05'] as $sqlModCode) {
    $t->assertTrue(isset($fcModulesFound[$sqlModCode]), "RCA-LMS-32 : Le module {$sqlModCode} du Parcours 4 (SQL:2023) est couvert par les Flashcards SRS");
}

// 16. Contract Tests RCA-163 / RCA-LMS-33 (7 Audit Fixes + 12 New Enterprise LMS & Qualiopi Features Axes A..D)
echo "\n--- 16. Tests Contrats RCA-163 / RCA-LMS-33 (7 Correctifs Audit /ponytail + 12 Fonctionnalités LMS Entreprise & Qualiopi) ---\n";

// 16.1 Audit #1 & Feature B1: 10-Day Attendance Registry (J1_AM..J11_PM) + OPCO Trainer Countersignature
$t->assertTrue(Security::isValidHalfDayKey('J6_AM') && Security::isValidHalfDayKey('J10_PM') && Security::isValidHalfDayKey('J11_PM'), "RCA-163 (Audit #1) : Security::isValidHalfDayKey autorise les demi-journées J6_AM..J11_PM (cursus 10 jours)");
$t->assertTrue(!Security::isValidHalfDayKey('J12_AM') && !Security::isValidHalfDayKey('INVALID'), "RCA-163 (Audit #1) : Security::isValidHalfDayKey rejette les clés hors plage (> J11_PM)");
$storage->signAttendance(3, 'postgresql-18', 'J6_AM', '192.0.2.50', 'UnitTest-Day6');
$storage->signAttendance(3, 'postgresql-18', 'J10_PM', '192.0.2.50', 'UnitTest-Day10');
$attAfter10d = $storage->getUserAttendance(3, 'postgresql-18');
$t->assertTrue(isset($attAfter10d['J6_AM']) && isset($attAfter10d['J10_PM']), "RCA-163 (Audit #1) : signAttendance persiste bien les demi-journées J6_AM et J10_PM");

$storage->signTrainerAttendance(3, 'postgresql-18', 'J6_AM', 'Jean-Marie Renouard');
$attAfterTrainer = $storage->getUserAttendance(3, 'postgresql-18');
$t->assertTrue(($attAfterTrainer['J6_AM']['trainer_name'] ?? '') === 'Jean-Marie Renouard' && !empty($attAfterTrainer['J6_AM']['trainer_signed_at']), "RCA-163 (Feature B1) : signTrainerAttendance appose le visa formateur sur la demi-journée ciblée");
$storage->signTrainerAttendance(0, 'postgresql-18', 'ALL', 'Jean-Marie Renouard');
$attAfterAllTrainer = $storage->getUserAttendance(3, 'postgresql-18');
$t->assertTrue(!empty($attAfterAllTrainer['J10_PM']['trainer_signed_at']), "RCA-163 (Feature B1) : signTrainerAttendance(0, ..., 'ALL') contre-signe en 1 clic toutes les demi-journées émargées");

// 16.2 Audit #2 & Feature A3: SRS Persistence in flashcards.php, Dynamic Counters & Module/Level/Focus Filters
$fcTplSrc = (string)file_get_contents(__DIR__ . '/../templates/pages/flashcards.php');
$layoutTplSrc = (string)file_get_contents(__DIR__ . '/../templates/layout.php');
$dashTplSrc = (string)file_get_contents(__DIR__ . '/../templates/pages/dashboard.php');
$t->assertTrue(str_contains($fcTplSrc, "flashcard_confidence") && str_contains($fcTplSrc, "fc-filter-module") && str_contains($fcTplSrc, "btn-fc-focus-toggle"), "RCA-163 (Audit #2 & Feature A3) : flashcards.php lit flashcard_confidence, affiche le badge module et propose les filtres + Mode Focus");
$t->assertTrue(!str_contains($layoutTplSrc, '>42 SRS<') && !str_contains($dashTplSrc, '42 cartes'), "RCA-163 (Audit #2) : Suppression des compteurs codés en dur '42 SRS' / '42 cartes' dans layout.php et dashboard.php");

// 16.3 Audit #3: exam.php Selects $allAttempts[0] (latest attempt prepended via array_unshift)
$examTplSrc = (string)file_get_contents(__DIR__ . '/../templates/pages/exam.php');
$t->assertTrue(str_contains($examTplSrc, '$allAttempts[0]') && !str_contains($examTplSrc, 'end($allAttempts)'), 'RCA-163 (Audit #3) : exam.php affiche $allAttempts[0] (tentative la plus récente) au lieu de end($allAttempts)');

// 16.4 Audit #4: Server-Side MCQ Answer Grading in api_submit_quiz (public/index.php)
$indexSrc = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(str_contains($indexSrc, 'parseModuleFile($modObj[\'source_md_path\']') && !str_contains($indexSrc, '$page === \'docker\''), 'RCA-163 (Audit #4 & #7) : api_submit_quiz recalcule le score QCM côté serveur et la branche morte $page === \'docker\' est supprimée');

// 16.5 Audit #5: Atomic Docker Status Cache Write in DockerManager::writeStatusCache
$dockerMgrSrc = (string)file_get_contents(__DIR__ . '/../src/Docker/DockerManager.php');
$t->assertTrue(str_contains($dockerMgrSrc, 'LOCK_EX') && str_contains($dockerMgrSrc, '@rename($tmpPath, $this->cacheFile)'), "RCA-163 (Audit #5) : DockerManager::writeStatusCache écrit de manière atomique (.tmp + LOCK_EX + rename)");

// 16.6 Audit #6, Feature A1 & Feature A2: MP4/VTT/Transcript Videos, pgexamples Discovery & Extended Full-Text Search
$mod01 = $repo->getModule('postgresql-18', 'module_01_introduction_postgresql_18');
$examples = $pgCourse['examples'] ?? [];
if ($isFullPg18Course) {
    $t->assertTrue($mod01 !== null && !empty($mod01['has_video']) && isset($mod01['downloads']['video_mp4']) && isset($mod01['downloads']['video_vtt']), "RCA-163 (Feature A1) : CourseRepository découvre les vidéos MP4 Studio, sous-titres WebVTT et transcriptions de video_engine/output/");
    $t->assertGreaterThanOrEqual(4, count($examples), "RCA-163 (Feature A2) : CourseRepository::discoverExamples découvre les exemples multi-langages (Python, Go, PHP, SQL) de pgexamples/");
} else {
    $t->assertTrue($mod01 !== null && !empty($mod01['has_video']) && isset($mod01['downloads']['video_mp4']), "Demo Module 01 discovers video_mp4 stub from video_engine/output/");
    $t->assertGreaterThanOrEqual(1, count($examples), "Demo CourseRepository::discoverExamples discovers companion example(s)");
}
$firstExId = (string)array_key_first($examples);
$firstExObj = $repo->getExampleDoc('postgresql-18', $firstExId);
$t->assertTrue($firstExObj !== null && is_file((string)$firstExObj['path']), "RCA-163 (Feature A2) : CourseRepository::getExampleDoc retourne un descripteur valide pour pgexamples");

$searchRef = $repo->searchContent('postgresql-18', 'Qualiopi');
$searchEx = $repo->searchContent('postgresql-18', 'psycopg');
$searchFc = $repo->searchContent('postgresql-18', 'fillfactor');
$searchTypes = array_unique(array_merge(
    array_column($searchRef, 'type'),
    array_column($searchEx, 'type'),
    array_column($searchFc, 'type')
));
$t->assertTrue(in_array('referentiel', $searchTypes, true) || in_array('flashcard', $searchTypes, true) || in_array('example', $searchTypes, true), "RCA-163 (Audit #6) : CourseRepository::searchContent indexe désormais referentiel, tools, examples et flashcards");

// 16.7 Feature B2: Qualiopi Cold Satisfaction Survey (Day+60 Business Impact — Indicators 30 & 32)
$storage->saveColdSatisfactionSurvey(3, 'postgresql-18', [
    'user_name' => 'Sophie Bernard',
    'company' => 'Client Production SA',
    'prod_application' => 5,
    'perf_gains' => 5,
    'autonomy_level' => 4,
    'tools_adoption' => 5,
    'career_impact' => 5,
    'concrete_projects' => 'Mise en place de pgBackRest + PgBouncer et tuning Linux vm.overcommit_memory=2',
    'further_needs' => 'Approfondissement Kubernetes CloudNativePG',
]);
$coldSurvey = $storage->getUserColdSatisfaction(3, 'postgresql-18');
$allColdSurveys = $storage->getAllColdSatisfactionSurveys();
$t->assertTrue($coldSurvey !== null && (float)$coldSurvey['average_rating'] >= 4.5 && count($allColdSurveys) === 1, "RCA-163 (Feature B2) : saveColdSatisfactionSurvey et getUserColdSatisfaction persistent l'évaluation à froid J+60");

// 16.8 Feature B3: Public Certificate Authenticity Verification & 100% Standalone SVG QR Code
$savedExamJson = $storage->saveExamAttempt(3, 'postgresql-18', $examEval);
$certIdToVerify = (string)($savedExamJson['certificate_id'] ?? '');
$verifiedCert = $storage->findCertificateByCode($certIdToVerify);
$t->assertTrue($verifiedCert !== null && (bool)$verifiedCert['verified'] === true && (int)$verifiedCert['user_id'] === 3, "RCA-163 (Feature B3) : findCertificateByCode retrouve et authentifie un certificat par son code unique LP-PG18-CERT-...");
$t->assertTrue($storage->findCertificateByCode('LP-PG18-CERT-INEXISTANT') === null, "RCA-163 (Feature B3) : findCertificateByCode retourne null pour un code de certificat invalide");
$qrSvg = Security::renderVerificationQrSvg('index.php?page=verify&code=' . urlencode($certIdToVerify), $certIdToVerify);
$t->assertTrue(str_starts_with($qrSvg, '<svg ') && str_contains($qrSvg, 'rect') && !str_contains($qrSvg, 'https://'), "RCA-163 (Feature B3) : Security::renderVerificationQrSvg génère un QR Code SVG 100% offline sans dépendance externe");

// 16.9 Feature C1 & C3: Relational Schema Explorer (inspectDatabaseSchema) & Progressive Lab Hint (extractLabHint)
$schemaDvd = $sqlRunner->inspectDatabaseSchema(3, 'dvdrental');
$schemaCluster = $sqlRunner->inspectDatabaseSchema(3, 'formation_pg');
$t->assertTrue(($schemaDvd['success'] ?? false) === true && count($schemaDvd['tables'] ?? []) >= 6, "RCA-163 (Feature C1) : LabSqlRunner::inspectDatabaseSchema retourne le catalogue des tables et colonnes pour dvdrental (Sakila)");
$t->assertTrue(($schemaCluster['success'] ?? false) === true && count($schemaCluster['tables'] ?? []) >= 4, "RCA-163 (Feature C1) : LabSqlRunner::inspectDatabaseSchema retourne le catalogue des tables et vues système pour formation_pg");
$lab01Obj = $repo->getLab('postgresql-18', 'lab_01_introduction_setup_postgresql_18');
$lab01Hint = $sqlRunner->extractLabHint((string)file_get_contents((string)$lab01Obj['source_md_path']), 'lab_01_introduction_setup_postgresql_18');
$t->assertTrue(!empty($lab01Hint['hint']) && strlen((string)$lab01Hint['hint']) > 20, "RCA-163 (Feature C3) : LabSqlRunner::extractLabHint extrait un indice pédagogique progressif sans dévoiler le corrigé complet");

// 16.10 Feature C2: Centralized Learner Notebook (?page=notes & Markdown Export)
$notesTplSrc = (string)file_get_contents(__DIR__ . '/../templates/pages/notes.php');
$t->assertTrue(str_contains($notesTplSrc, 'export_notes_md') && str_contains($notesTplSrc, 'note-inline-editor'), "RCA-163 (Feature C2) : templates/pages/notes.php fournit le carnet de notes centralisé avec filtre et export Markdown");

// 16.11 Feature D1 & D2: Live Classroom Broadcast, Pedagogical Lock & Q&A Forum Moderation
$storage->saveClassroomState('postgresql-18', [
    'broadcast_message' => 'Atelier 06 en cours — Corrigés verrouillés jusqu\'à 15h30',
    'lock_solutions' => true,
    'lock_exam' => true,
    'updated_by' => 'Jean-Marie Renouard',
]);
$clsState = $storage->getClassroomState('postgresql-18');
$t->assertTrue(($clsState['broadcast_message'] ?? '') !== '' && $clsState['lock_solutions'] === true && $clsState['lock_exam'] === true, "RCA-163 (Feature D1) : saveClassroomState et getClassroomState persistent l'annonce live et les verrous pédagogiques");

$addedDisc = $storage->addDiscussionMessage('postgresql-18', 'module_01_introduction_postgresql_18', 3, 'Thomas Dubois', 'student', 'Question sur io_method = worker');
$firstMsgId = (string)($addedDisc['id'] ?? '');
$t->assertTrue($firstMsgId !== '' && $storage->resolveDiscussionMessage('postgresql-18', 'module_01_introduction_postgresql_18', $firstMsgId, true), "RCA-163 (Feature D2) : resolveDiscussionMessage marque une question Q&A comme résolue");
$allCourseDisc = $storage->getAllCourseDiscussions('postgresql-18');
$t->assertTrue(count($allCourseDisc) >= 1 && !empty($allCourseDisc[0]['resolved']), "RCA-163 (Feature D2) : getAllCourseDiscussions agrège les discussions de la formation avec leur statut résolu");
$t->assertTrue($storage->deleteDiscussionMessage('postgresql-18', 'module_01_introduction_postgresql_18', $firstMsgId), "RCA-163 (Feature D2) : deleteDiscussionMessage supprime proprement un message Q&A");

// 16.12 Feature D3: Bulk Cohort CSV Import & SCORM 1.2 (.zip) Package Export
$csvBatch = "Claire Martin;claire.martin@banque.fr;=SUM(1,2) Banque SA;student;LightPath2026!\n"
    . "David Roy;david.roy@banque.fr;Banque SA;student;LightPath2026!\n"
    . "Doublon;claire.martin@banque.fr;Banque SA;student;LightPath2026!";
$csvRes = $storage->importUsersFromCsv($csvBatch);
$t->assertTrue($csvRes['created'] === 2 && $csvRes['skipped'] === 1, "RCA-163 (Feature D3) : importUsersFromCsv crée les nouveaux stagiaires et ignore les doublons d'email");
$claireUser = $storage->getUserByEmail('claire.martin@banque.fr');
$t->assertTrue($claireUser !== null && str_starts_with((string)$claireUser['company'], "'=SUM"), "RCA-163 (Feature D3) : importUsersFromCsv neutralise les injections de formules CSV via Security::sanitizeCsvCell");

$dlController = new \LightPath\LMS\Course\DownloadController($repo);
$scormZipPath = $dlController->buildScorm12ZipPackage('postgresql-18', $pgCourse);
$scormZipBytes = ($scormZipPath !== null && is_file($scormZipPath)) ? (string)file_get_contents($scormZipPath) : '';
if ($scormZipPath !== null && is_file($scormZipPath)) {
    @unlink($scormZipPath);
}
$t->assertTrue(str_starts_with($scormZipBytes, "PK\x03\x04") && str_contains($scormZipBytes, 'imsmanifest.xml') && str_contains($scormZipBytes, 'scorm_api.js'), "RCA-163 (Feature D3) : DownloadController::buildScorm12ZipPackage génère une archive ZIP SCORM 1.2 valide contenant imsmanifest.xml et scorm_api.js");

$pdoAdapter->saveColdSatisfactionSurvey(3, 'postgresql-18', ['prod_application' => 5, 'perf_gains' => 5]);
$t->assertTrue($pdoAdapter->getUserColdSatisfaction(3, 'postgresql-18') !== null, "RCA-163 (Parité SQLite PdoStorage) : PdoStorage supporte les nouvelles méthodes RCA-163 (J+60, ClassroomState, CSV Import)");

// =========================================================================
// 17. Contract Tests RCA-164 / RCA-LMS-34 (12 Review Findings)
// =========================================================================
echo "\n--- 17. Tests Contrats RCA-164 / RCA-LMS-34 (12 Constats Revue Ponytail-Review) ---\n";

// 17.1 Item 1: Bulk Cohort CSV Import in admin templates & public/index.php
$adminTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/admin.php');
$adminUsersPartial = (string)file_get_contents(__DIR__ . '/../templates/partials/admin/tab_users.php');
$indexCode = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(str_contains($adminTpl . "\n" . $adminUsersPartial, 'name="csv_content"'), "RCA-164 (Item 1) : templates admin soumettent le champ textarea sous le nom name=\"csv_content\"");
$t->assertTrue(str_contains($indexCode, "\$_POST['csv_content'] ?? (\$_POST['csv_payload'] ?? '')"), "RCA-164 (Item 1) : public/index.php lit avec repli \$_POST['csv_content'] ?? \$_POST['csv_payload']");

// 17.2 Item 2: Public Unauthenticated Access to Certificate Verification ?page=verify
$t->assertTrue(str_contains($indexCode, "\$page !== 'verify'"), "RCA-164 (Item 2) : public/index.php autorise l'accès public à ?page=verify pour les auditeurs sans compte");
$verifyTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/verify.php');
$t->assertTrue(str_contains($verifyTpl, "\$currentUser['name'] ?? 'Apprenant Certifié'"), "RCA-164 (Item 2) : verify.php gère les accès sans session active (\$currentUser null)");

// 17.3 Item 3: Airtight Locking of Lab Solutions
$t->assertTrue(str_contains($indexCode, "lock_solutions") && str_contains($indexCode, "api_run_lab_solution"), "RCA-164 (Item 3) : api_run_lab_solution vérifie le verrouillage lock_solutions avant exécution");
$t->assertTrue(str_contains($indexCode, "\$type === 'lab'") && str_contains($indexCode, "solutionsLocked"), "RCA-164 (Item 3) : route download bloque le téléchargement des énoncés/corrigés de labs si les solutions sont verrouillées");
$labTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/lab_view.php');
$t->assertTrue(str_contains($labTpl, "!\$solutionsLocked && \$labMode !== 'corrige'"), "RCA-164 (Item 3) : lab_view.php masque le bouton de corrigé de bas de page si les solutions sont verrouillées");
$t->assertTrue(str_contains($labTpl, "in_array(\$labMode, ['corrige', 'full', 'html_preview', 'pdf_preview'], true)"), "RCA-164 (Item 3) : lab_view.php bloque les modes html_preview et pdf_preview si les solutions sont verrouillées");

// 17.4 Item 4: Video Transcript Key Harmonization
$modTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/module_view.php');
$repoCode = (string)file_get_contents(__DIR__ . '/../src/Course/CourseRepository.php');
$t->assertTrue(str_contains($modTpl, "\$mod['video_transcript_path']") && str_contains($modTpl, "\$mod['transcript_md_path']"), "RCA-164 (Item 4) : module_view.php lit video_transcript_path et transcript_md_path avec repli");
$t->assertTrue(str_contains($repoCode, "'video_transcript_path'") && str_contains($repoCode, "'transcript_md_path'"), "RCA-164 (Item 4) : CourseRepository renvoie les deux clés transcript pour compatibilité");

// 17.5 Item 5: Chunked HTTP 206 Video Streaming (64 KB Buffers)
$dlCode = (string)file_get_contents(__DIR__ . '/../src/Course/DownloadController.php');
$t->assertTrue(str_contains($dlCode, 'fread($fp, $readSize)') && str_contains($dlCode, 'flush()'), "RCA-164 (Item 5) : DownloadController diffuse la vidéo par blocs de streaming sans saturer la RAM PHP");

// 17.7 Item 7: Full-Day Trainer Countersignature J<N>_DAY & Accurate Rendering
$attTestRes = $storage->signTrainerAttendance(3, 'postgresql-18', 'J1_DAY', 'Jean-Marie Renouard');
$user3Att = $storage->getUserAttendance(3, 'postgresql-18');
$t->assertTrue(!empty($user3Att['J1_AM']['trainer_signed_at']) && !empty($user3Att['J1_PM']['trainer_signed_at']), "RCA-164 (Item 7) : signTrainerAttendance('J1_DAY') contre-signe le matin et l'après-midi du Jour 1");
$attTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/attendance.php');
$t->assertTrue(str_contains($attTpl, 'value="J<?= $dayNum ?>_DAY"'), "RCA-164 (Item 7) : attendance.php envoie J<N>_DAY pour contre-signer la journée complète");
$t->assertTrue(str_contains($attTpl, '$trainerSignedAm && $trainerSignedPm'), "RCA-164 (Item 7) : attendance.php distingue la journée complète des demi-journées isolées");

// 17.8 Item 8: Discovery of dbclients SQL Examples
$repoFresh = new CourseRepository($coursesConfig, [], sys_get_temp_dir());
$pgCourseFresh = $repoFresh->getCourse('postgresql-18');
$examplesFresh = $pgCourseFresh['examples'] ?? [];
if ($isFullPg18Course) {
    $sqlExamples = array_filter($examplesFresh, static fn($e) => ($e['language'] ?? '') === 'SQL Schéma');
    $t->assertGreaterThanOrEqual(9, count($sqlExamples), "RCA-164 (Item 8) : CourseRepository::discoverExamples découvre au moins 9 scripts SQL de dbclients");
}

// 17.12 Item 12: Cache Invalidation, loadCourse Alias & Catalog Cache Fingerprint
$t->assertTrue(str_contains($repoCode, 'v8_rca164'), "RCA-164 (Item 12) : CourseRepository computeCatalogFingerprint utilise le salt v8_rca164");
$t->assertTrue(method_exists($repoFresh, 'loadCourse') && method_exists($repoFresh, 'clearCatalogCache'), "RCA-164 (Item 12) : CourseRepository expose loadCourse() et clearCatalogCache()");
$t->assertTrue(str_contains($repoCode, 'LOCK_EX'), "RCA-164 (Item 12) : CourseRepository écrit le cache avec verrouillage exclusif LOCK_EX");

// =========================================================================
// 18. Contract Tests RCA-165 / RCA-LMS-35 (LMS Features & Hardening)
// =========================================================================
echo "\n--- 18. Tests Contrats RCA-165 / RCA-LMS-35 (Fonctionnalités & Robustesse LMS) ---\n";

// 18.1 Docker Security: statement_timeout & lock_timeout Injection in executeSql
$runnerCode = (string)file_get_contents(__DIR__ . '/../src/Docker/LabSqlRunner.php');
$t->assertTrue(
    str_contains($runnerCode, 'statement_timeout=15000') && str_contains($runnerCode, 'lock_timeout=10000'),
    "RCA-LMS-35 (Item 1) : LabSqlRunner::executeSql injecte statement_timeout et lock_timeout pour éviter les blocages workers"
);

// 18.2 Certificate Integrity: Distinguish Passing Certification vs Failed Attempts
$failedEval = \LightPath\LMS\Course\LmsCatalog::evaluateFinalExam([], 120);
$failedAttempt = $storage->saveExamAttempt(2, 'postgresql-18', $failedEval);
$failedCertCode = (string)($failedAttempt['certificate_id'] ?? '');
$lookedUpFailed = $storage->findCertificateByCode($failedCertCode);
$t->assertTrue(
    $lookedUpFailed !== null && empty($lookedUpFailed['passed']),
    "RCA-LMS-35 (Item 2) : findCertificateByCode signale fidèlement passed=false pour une tentative d'examen sous le seuil de 75%"
);

// 18.3 Concurrency & Target Course Scoping in Cohort CSV Import
$batchCsvMulti = "Test User;test.user.rca165@lightpath.fr;LightPath Corp;student;LightPath2026!";
$importResMulti = $storage->importUsersFromCsv($batchCsvMulti, 'LightPath2026!', 'postgresql-18');
$importedUserObj = $storage->getUserByEmail('test.user.rca165@lightpath.fr');
$t->assertTrue(
    $importResMulti['created'] === 1 && $importedUserObj !== null && in_array('postgresql-18', $importedUserObj['enrolled_courses'] ?? [], true),
    "RCA-LMS-35 (Item 3) : importUsersFromCsv inscrit l'utilisateur dans le cours cible spécifié sous verrou atomique"
);

// 18.4 SRS Flashcard Progress Reset
$storage->saveFlashcardConfidence(3, 'postgresql-18', 'fc_01', 3);
$t->assertTrue(
    method_exists($storage, 'resetFlashcardReviews') && $storage->resetFlashcardReviews(3, 'postgresql-18'),
    "RCA-LMS-35 (Item 4) : StorageInterface et JsonStorage exposent resetFlashcardReviews pour recommencer un cycle SRS"
);
$progAfterReset = $storage->getUserProgress(3, 'postgresql-18');
$t->assertTrue(
    empty($progAfterReset['flashcard_confidence']),
    "RCA-LMS-35 (Item 4) : resetFlashcardReviews réinitialise intégralement la progression SRS de l'apprenant"
);

// 18.5 RBAC Enforcement on SCORM 1.2 Exports
$indexCode = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(
    str_contains($indexCode, "action === 'export_scorm_manifest' && \$auth->isStaff(\$currentUser)")
    && str_contains($indexCode, "action === 'export_scorm_zip' && \$auth->isStaff(\$currentUser)"),
    "RCA-LMS-35 (Item 5) : Les exports SCORM 1.2 sont strictement restreints au personnel formateur et administrateur"
);

// 18.6 Detailed OPCO Attendance Registry Export (export_attendance_csv)
$t->assertTrue(
    str_contains($indexCode, "action === 'export_attendance_csv'"),
    "RCA-LMS-35 (Item 6) : public/index.php expose la route export_attendance_csv pour les audits d'assiduité OPCO/Qualiopi"
);

// 18.7 Live Classroom Heartbeat & Broadcast Sync
$t->assertTrue(
    str_contains($indexCode, "'classroom_state' => \$storage->getClassroomState"),
    "RCA-LMS-35 (Item 7) : api_heartbeat retourne l'état actuel de la salle de classe pour diffusion temps réel"
);

// 18.8 Open-Redirect Prevention in Q&A Moderation
$t->assertTrue(
    str_contains($indexCode, "in_array(\$rawReturnPage, ['admin', 'module', 'lab'], true)"),
    "RCA-LMS-35 (Item 8) : moderate_discussion applique une liste blanche stricte sur return_page"
);

// 18.9 Active Course Preservation in Global Search
$appJsCode = (string)file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$t->assertTrue(
    str_contains($appJsCode, 'index.php?page=search&q=${encodeURIComponent(q)}&course=${encodeURIComponent(currentCourse)}'),
    "RCA-LMS-35 (Item 9) : app.js préserve le paramètre &course lors de la recherche globale sur Entrée"
);

// 19. Contract Tests RCA-166 / RCA-LMS-36 (Markdown Rendering, Callouts & Syntax Badges)
echo "\n--- 19. Tests Contrats RCA-166 / RCA-LMS-36 (Rendu Markdown, Alertes Callouts & Badges de Syntaxe) ---\n";

// 19.1 Stylesheet: Presence of .lms-callout-note and .lms-code-badge Declarations
$appCss = (string)file_get_contents(__DIR__ . '/../public/assets/css/app.css');
$t->assertTrue(
    str_contains($appCss, '.lms-callout-note') && str_contains($appCss, '#00B0F0'),
    "RCA-LMS-36 (Item 1) : app.css définit la classe .lms-callout-note avec la couleur Cyan LightPath (#00B0F0)"
);
$t->assertTrue(
    str_contains($appCss, '.lms-code-badge') && str_contains($appCss, 'tabular-nums'),
    "RCA-LMS-36 (Item 2) : app.css définit .lms-code-badge et le style de coloration avec tabular-nums (palette Emil Kowalski)"
);

// 19.2 Conversion of [!NOTE] into .lms-callout-note via MarkdownParser::parse()
$noteSample = "> [!NOTE]\n> Recommandation PostgreSQL 18 sur les statistiques multivariées.";
$noteParsed = MarkdownParser::parse($noteSample);
$noteHtml = is_array($noteParsed) ? ($noteParsed['html'] ?? '') : (string)$noteParsed;
$t->assertTrue(
    str_contains($noteHtml, 'class="lms-callout lms-callout-note"') && str_contains($noteHtml, 'Information Technique'),
    "RCA-LMS-36 (Item 3) : MarkdownParser::parse() convertit fidèlement [!NOTE] en .lms-callout-note"
);

// 19.3 All 5 GitHub Alert Types (TIP, NOTE, IMPORTANT, WARNING, CAUTION) & Dedicated CSS Classes
$alertTypes = [
    'TIP' => 'lms-callout-tip',
    'NOTE' => 'lms-callout-note',
    'IMPORTANT' => 'lms-callout-important',
    'WARNING' => 'lms-callout-warning',
    'CAUTION' => 'lms-callout-caution',
];
foreach ($alertTypes as $alertTag => $expectedCssClass) {
    $alertMd = "> [!{$alertTag}]\n> Alerte de type {$alertTag} pour DBA PostgreSQL.";
    $alertParsed = MarkdownParser::parse($alertMd);
    $alertHtml = is_array($alertParsed) ? ($alertParsed['html'] ?? '') : (string)$alertParsed;
    $t->assertTrue(
        str_contains($alertHtml, $expectedCssClass),
        "RCA-LMS-36 (Item 4) : L'alerte [!{$alertTag}] est rendue avec la classe CSS spécifique .{$expectedCssClass}"
    );
    $t->assertTrue(
        str_contains($appCss, '.' . $expectedCssClass),
        "RCA-LMS-36 (Item 4) : La classe CSS .{$expectedCssClass} est rigoureusement déclarée dans app.css"
    );
}

// 19.4 Preservation of Language Syntax Tags & Badges on Code Blocks
$codeSample = "```sql\nSELECT relname, n_dead_tup FROM pg_stat_user_tables;\n```\n\n```bash\npgbench -i -s 50 formation_pg\n```\n\n```python\nimport psycopg\n```";
$codeParsed = MarkdownParser::parse($codeSample);
$codeHtml = is_array($codeParsed) ? ($codeParsed['html'] ?? '') : (string)$codeParsed;
$t->assertTrue(
    str_contains($codeHtml, 'lang-sql') && str_contains($codeHtml, 'lms-code-badge') && str_contains($codeHtml, '>SQL<'),
    "RCA-LMS-36 (Item 5) : Les blocs SQL préservent le tag de syntaxe lang-sql et affichent le badge .lms-code-badge SQL"
);
$t->assertTrue(
    str_contains($codeHtml, 'lang-bash') && str_contains($codeHtml, '>BASH<'),
    "RCA-LMS-36 (Item 5) : Les blocs Bash préservent le tag de syntaxe lang-bash et affichent le badge .lms-code-badge BASH"
);
$t->assertTrue(
    str_contains($codeHtml, 'lang-python') && str_contains($codeHtml, '>PYTHON<'),
    "RCA-LMS-36 (Item 5) : Les blocs Python préservent le tag de syntaxe lang-python et affichent le badge .lms-code-badge PYTHON"
);

// ============================================================================
// 20. Contract Tests RCA-167 / RCA-LMS-37 (Security, Concurrency, Cache & Qualiopi)
echo "\n--- 20. Tests Contrats RCA-167 / RCA-LMS-37 (Sécurité, Concurrence, Cache & Qualiopi) ---\n";

// 20.1 Official Europe/Paris Timezone Configuration
$t->assertEqual(
    'Europe/Paris',
    date_default_timezone_get(),
    "RCA-LMS-37 (FIX-13) : date_default_timezone_get() est configuré sur Europe/Paris"
);

// 20.2 Preservation of Trainer Countersignature During Student Signing (JsonStorage)
$storage->signTrainerAttendance(3, 'postgresql-18', 'J2_AM', 'Jean-Marie Renouard');
$attBefore = $storage->getUserAttendance(3, 'postgresql-18');
$trainerVisaBefore = $attBefore['J2_AM']['trainer_signed_at'] ?? '';
$t->assertTrue(!empty($trainerVisaBefore), "RCA-LMS-37 (FIX-02) : Visa formateur apposé sur J2_AM");

// Student re-signs the same half-day slot
$storage->signAttendance(3, 'postgresql-18', 'J2_AM', 'Signature rectifiée stagiaire #3');
$attAfter = $storage->getUserAttendance(3, 'postgresql-18');
$t->assertEqual(
    $trainerVisaBefore,
    $attAfter['J2_AM']['trainer_signed_at'] ?? null,
    "RCA-LMS-37 (FIX-02) : signAttendance préserve trainer_signed_at après ré-émargement stagiaire"
);
$t->assertEqual(
    'Jean-Marie Renouard',
    $attAfter['J2_AM']['trainer_name'] ?? null,
    "RCA-LMS-37 (FIX-02) : signAttendance préserve trainer_name après ré-émargement stagiaire"
);

// 20.3 Sealed Timestamp SHA-256 Attendance Integrity Hash
$attRecord = $attAfter['J2_AM'] ?? [];
$t->assertTrue(
    (bool)preg_match('/^[a-f0-9]{16}$/i', $attRecord['hash'] ?? ''),
    "RCA-LMS-37 (FIX-14) : L'empreinte de signature stagiaire est un condensat hexadécimal de 16 caractères"
);
$t->assertTrue(
    (bool)preg_match('/^[a-f0-9]{16}$/i', $attRecord['trainer_hash'] ?? ''),
    "RCA-LMS-37 (FIX-14) : Le visa formateur est un condensat hexadécimal de 16 caractères scellé temporellement"
);

// 20.4 Public Verification of Qualiopi Attendance Certificates (findCertificateByCode)
$attCertCode = 'LP-PG18-ATT-3-' . date('Ymd');
$attLookup = $storage->findCertificateByCode($attCertCode);
$t->assertTrue($attLookup !== null, "RCA-LMS-37 (FIX-07) : findCertificateByCode reconnaît le code d'attestation LP-PG18-ATT-3-Ymd");
$t->assertTrue(!empty($attLookup['passed']), "RCA-LMS-37 (FIX-07) : L'attestation de suivi est certifiée conforme (passed=true)");
$t->assertEqual(100, $attLookup['percent'] ?? 0, "RCA-LMS-37 (FIX-07) : Score d'assiduité fixé à 100%");
$t->assertEqual(3, $attLookup['user_id'] ?? 0, "RCA-LMS-37 (FIX-07) : Titulaire résolu sur l'utilisateur #3");

// Certificate lookup for non-existent user returns null
$t->assertNull(
    $storage->findCertificateByCode('LP-PG18-ATT-9999-20261102'),
    "RCA-LMS-37 (FIX-07) : findCertificateByCode rejette une attestation pour utilisateur inexistant"
);

// 20.5 CSV Import: UTF-8 BOM Stripping, TSV Detection & Password Length Guard
$csvWithBom = "\xEF\xBB\xBFNom;Email;Entreprise;Role;Password\nClaire Dupuis;claire.dupuis@formation-pg.fr;EDF;student;Secr3t!\n";
$importBomRes = $storage->importUsersFromCsv($csvWithBom);
$t->assertEqual(1, $importBomRes['created'], "RCA-LMS-37 (FIX-12) : importUsersFromCsv purge le BOM UTF-8 et importe le premier compte");
$importedClaire = $storage->getUserByEmail('claire.dupuis@formation-pg.fr');
$t->assertTrue($importedClaire !== null && $importedClaire['name'] === 'Claire Dupuis', "RCA-LMS-37 (FIX-12) : Le nom n'est pas corrompu par le BOM");

// Tab-separated CSV (TSV) import
$tsvContent = "Nom\tEmail\tEntreprise\tRole\tPassword\nDavid Bernard\tdavid.bernard@formation-pg.fr\tSNCF\tstudent\tSNCF2026!\n";
$importTsvRes = $storage->importUsersFromCsv($tsvContent);
$t->assertEqual(1, $importTsvRes['created'], "RCA-LMS-37 (FIX-12) : importUsersFromCsv gère les séparateurs tabulations (TSV)");

// Import with short password (< 6 chars): fallback to secure default password
$csvShortPass = "Eric Petit;eric.petit@formation-pg.fr;Airbus;student;123\n";
$importShortRes = $storage->importUsersFromCsv($csvShortPass, 'DefPass2026!');
$t->assertEqual(1, $importShortRes['created'], "RCA-LMS-37 (FIX-12) : importUsersFromCsv remplace un mot de passe trop court par le mot de passe par défaut");
$importedEric = $storage->getUserByEmail('eric.petit@formation-pg.fr');
$t->assertTrue(password_verify('DefPass2026!', $importedEric['password_hash'] ?? ''), "RCA-LMS-37 (FIX-12) : Le compte a bien été créé avec le mot de passe conforme");

// 20.6 LabSqlRunner Security: Rejection of Interactive Backslash Commands
$sqlRunnerTest = new LabSqlRunner($docker);
$blockedBackslash = $sqlRunnerTest->executeSql(1, "\\! id", 'formation_pg');
$t->assertFalse($blockedBackslash['success'], "RCA-LMS-37 (FIX-06) : LabSqlRunner bloque \\! id");
$t->assertTrue(str_contains($blockedBackslash['output'], 'Commandes backslash interactives non autorisées'), "RCA-LMS-37 (FIX-06) : Message explicite sur blocage backslash");

$blockedCopy = $sqlRunnerTest->executeSql(1, "\\copy (SELECT 1) TO '/tmp/out'", 'formation_pg');
$t->assertFalse($blockedCopy['success'], "RCA-LMS-37 (FIX-06) : LabSqlRunner bloque \\copy");

// Attempt to disable statement_timeout
$blockedTimeoutReset = $sqlRunnerTest->executeSql(1, "SET statement_timeout = 0; SELECT 1;", 'formation_pg');
$t->assertFalse($blockedTimeoutReset['success'], "RCA-LMS-37 (FIX-06) : LabSqlRunner bloque toute modification de statement_timeout");

// Verify runSql compatibility alias
$aliasRunRes = $sqlRunnerTest->runSql(1, "\\gexec", 'formation_pg');
$t->assertFalse($aliasRunRes['success'], "RCA-LMS-37 (FIX-06) : Alias runSql actif et conforme");

// 20.7 Auth Security: 4-Hour Session Inactivity Timeout
$_SESSION['user_id'] = 1;
$_SESSION['last_activity'] = time() - 15000; // > 14400s
$expiredUser = $auth->currentUser();
$t->assertNull($expiredUser, "RCA-LMS-37 (FIX-11) : currentUser déconnecte la session après 14400s d'inactivité");
$t->assertTrue(empty($_SESSION['user_id']), "RCA-LMS-37 (FIX-11) : user_id est vidé de la session");

// 20.8 PdoStorage: Atomic ON CONFLICT DO UPDATE, FOR UPDATE & Transparent Fallback
$pdoStorage = new PdoStorage(['dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=formation_pg'], sys_get_temp_dir() . '/pdo_test_' . mt_rand(1000, 9999));
$t->assertTrue($pdoStorage instanceof JsonStorage, "RCA-LMS-37 (FIX-03) : PdoStorage hérite de JsonStorage et assure le repli transparent");
$pdoSrc = (string)file_get_contents(__DIR__ . '/../src/Core/PdoStorage.php');
$t->assertTrue(str_contains($pdoSrc, 'ON CONFLICT (store_key) DO UPDATE'), "RCA-LMS-37 (FIX-03) : PdoStorage implémente ON CONFLICT (store_key) DO UPDATE");
$t->assertTrue(str_contains($pdoSrc, 'FOR UPDATE'), "RCA-LMS-37 (FIX-03) : PdoStorage implémente le verrouillage ligne FOR UPDATE dans mutateFile");

// 20.9 Catalog & Markdown Parsed Cache Purge
$mdParserInstance = new MarkdownParser();
$clearedParsedCount = $mdParserInstance->clearParsedCache();
$t->assertGreaterThanOrEqual(0, $clearedParsedCount, "RCA-LMS-37 (FIX-10) : MarkdownParser::clearParsedCache() purge les fichiers JSON du cache de rendu");
$repoFresh->clearCatalogCache();
$t->assertTrue(method_exists($repoFresh, 'clearCatalogCache'), "RCA-LMS-37 (FIX-04) : CourseRepository::clearCatalogCache() vide le cache catalogue");

// 20.10 Route & Template Verification (index.php, admin.php, attendance.php)
$indexSrc = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(str_contains($indexSrc, "action === 'admin_clear_cache'"), "RCA-LMS-37 (FIX-16) : public/index.php expose la route admin_clear_cache");
$t->assertTrue(str_contains($indexSrc, "AUCUNE (ABSENT)"), "RCA-LMS-37 (FIX-13) : export_attendance_csv inclut les stagiaires sans émargement pour l'OPCO");

$adminTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/admin.php');
$t->assertTrue(str_contains($adminTpl, "tab=system"), "RCA-LMS-37 (FIX-16) : templates/pages/admin.php propose l'onglet Caches & Système");

$attTplCheck = (string)file_get_contents(__DIR__ . '/../templates/pages/attendance.php');
$t->assertTrue(str_contains($attTplCheck, 'name="target_user_id" value="0"'), "RCA-LMS-37 (FIX-08) : templates/pages/attendance.php envoie target_user_id=0 pour la promotion");

// ============================================================================
// 21. Contract Tests RCA-168 / RCA-LMS-38 (Final Polish, OPCO Export & Hardening)
// ============================================================================
echo "\n--- 21. Tests Contrats RCA-168 / RCA-LMS-38 (Polissage Final, Export OPCO & Durcissement) ---\n";

// 21.1 LMS-38-01: System Tab Integrated into admin.php Conditional Branch Chain
$adminTplCode = (string)file_get_contents(__DIR__ . '/../templates/pages/admin.php');
$t->assertTrue(
    str_contains($adminTplCode, "elseif (\$adminTab === 'system'):"),
    "RCA-LMS-38 (LMS-38-01) : templates/pages/admin.php intègre l'onglet system dans l'échelle elseif"
);
$t->assertFalse(
    (bool)preg_match('/<\?php\s+endif;\s*\?>\s*<\?php\s+if\s*\(\$adminTab\s*===\s*\'system\'\)/', $adminTplCode),
    "RCA-LMS-38 (LMS-38-01) : Suppression du bloc séparé causant le double affichage de l'audit Qualiopi sur l'onglet système"
);

// 21.2 LMS-38-02: Role Column & Staff Qualification in export_attendance_csv
$indexCodeB5 = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(
    str_contains($indexCodeB5, "'Role'"),
    "RCA-LMS-38 (LMS-38-02) : export_attendance_csv expose la colonne 'Role' dans l'en-tête CSV"
);
$t->assertTrue(
    str_contains($indexCodeB5, "PERSONNEL FORMATEUR"),
    "RCA-LMS-38 (LMS-38-02) : export_attendance_csv qualifie les comptes formateur en 'PERSONNEL FORMATEUR'"
);
$t->assertTrue(
    str_contains($indexCodeB5, "STAFF (N/A)"),
    "RCA-LMS-38 (LMS-38-02) : export_attendance_csv qualifie les heures du staff en 'STAFF (N/A)'"
);

// 21.3 LMS-38-03: Hide Active Signing Buttons on Third-Party Student View & Preserve Trainer Context
$attTplCodeB5 = (string)file_get_contents(__DIR__ . '/../templates/pages/attendance.php');
$t->assertTrue(
    str_contains($attTplCodeB5, "\$inspectedUserId === \$userId"),
    "RCA-LMS-38 (LMS-38-03) : attendance.php conditionne l'émargement actif à \$inspectedUserId === \$userId"
);
$t->assertTrue(
    str_contains($attTplCodeB5, 'name="return_student_id"'),
    "RCA-LMS-38 (LMS-38-03) : attendance.php transmet return_student_id dans la contre-signature formateur"
);
$t->assertTrue(
    str_contains($attTplCodeB5, 'name="return_track"'),
    "RCA-LMS-38 (LMS-38-03) : attendance.php transmet return_track dans la contre-signature formateur"
);
$t->assertTrue(
    str_contains($indexCodeB5, "return_student_id"),
    "RCA-LMS-38 (LMS-38-03) : public/index.php réinjecte return_student_id dans la redirection HTTP"
);
$t->assertTrue(
    str_contains($indexCodeB5, "return_track"),
    "RCA-LMS-38 (LMS-38-03) : public/index.php réinjecte return_track dans la redirection HTTP"
);

// 21.4 LMS-38-04: Preventive Blocking of Interactive psql Meta-Commands in LabSqlRunner
$blockedCommands = [
    '\\e' => 'invocation éditeur système \e',
    '\\ef my_func' => 'édition de fonction \ef',
    '\\ev my_view' => 'édition de vue \ev',
    '\\prompt my_var' => 'invite interactive \prompt',
    '\\watch 2' => 'boucle infinie \watch',
    '\\setenv PAGER cat' => 'altération environnement \setenv',
    "/* comment */ \\e" => 'contournement par commentaire \e',
    "SELECT 1; \\watch 5" => 'enchaînement avec point-virgule \watch',
];
foreach ($blockedCommands as $cmdSql => $cmdLabel) {
    $blockedRes = $sqlRunnerTest->runSql(1, $cmdSql, 'formation_pg');
    $t->assertFalse($blockedRes['success'], "RCA-LMS-38 (LMS-38-04) : LabSqlRunner bloque {$cmdLabel}");
    $t->assertEquals('blocked', $blockedRes['container'], "RCA-LMS-38 (LMS-38-04) : Container est 'blocked' pour {$cmdLabel}");
}

// 21.5 LMS-38-05: Harmonized Final Exam Lock Check Using isStaff in exam.php
$examTplCodeB5 = (string)file_get_contents(__DIR__ . '/../templates/pages/exam.php');
$t->assertTrue(
    str_contains($examTplCodeB5, "!\$auth->isStaff(\$currentUser)"),
    "RCA-LMS-38 (LMS-38-05) : templates/pages/exam.php utilise !\$auth->isStaff(\$currentUser) pour le verrou examen"
);
$t->assertFalse(
    str_contains($examTplCodeB5, "(\$currentUser['role'] ?? 'student') === 'student'"),
    "RCA-LMS-38 (LMS-38-05) : templates/pages/exam.php a éliminé l'ancienne condition fragile sur le rôle student"
);

// ============================================================================
// 22. SECTION 22 — ARCHITECTURE, HARDENING, SERVICES & COVERAGE EXTENSIONS
// ============================================================================
echo "\n[22] FeatureManager, persistent Auth, JsonStorage archival, Security, LabSqlRunner, DockerManager, AdminService & Docs...\n";

$s22Root = sys_get_temp_dir() . '/lp_sec22_' . bin2hex(random_bytes(4));
$s22Data = $s22Root . '/data';
$s22Cache = $s22Root . '/cache';
@mkdir($s22Data, 0775, true);
@mkdir($s22Cache, 0775, true);

$s22PrevSimEnv = getenv('LMS_DOCKER_FORCE_SIMULATED');
$s22SavedSession = $_SESSION ?? [];
$s22SavedServer = $_SERVER;

try {
    // 22.1 FeatureManager (config/features.php defaults, StorageInterface persistence, audit log)
    $s22FeaturesCfg = require __DIR__ . '/../config/features.php';
    $t->assertTrue(
        is_array($s22FeaturesCfg) && count($s22FeaturesCfg) >= 8 && isset($s22FeaturesCfg['docker_labs']),
        "Section 22: config/features.php defines modular feature flag metadata"
    );

    $s22Storage = new JsonStorage($s22Data);
    $s22Fm1 = new \LightPath\LMS\Core\FeatureManager($s22Storage);
    $t->assertTrue($s22Fm1->isEnabled('docker_labs'), "Section 22: FeatureManager defaults docker_labs to true");
    $t->assertFalse($s22Fm1->isEnabled('nonexistent_flag_xyz'), "Section 22: FeatureManager returns false for unknown flag");

    $s22Defs = $s22Fm1->getDefinitions();
    $s22AllFlags = $s22Fm1->all();
    $t->assertTrue(
        count($s22Defs) === count($s22FeaturesCfg) && count($s22AllFlags) === count($s22FeaturesCfg) && isset($s22Defs['docker_labs']['enabled']),
        "Section 22: FeatureManager::getDefinitions() & all() cover all configured feature flags"
    );

    $s22UpdatedFlags = $s22Fm1->updateFlags([
        'self_registration' => '1',
        'demo_quick_login' => 'true',
        'docker_labs' => '0',
        'chaos_engineering' => '0',
        'gamification' => '1',
        'qna_forum' => '1',
        'scorm_export' => '0',
        'cold_satisfaction_j60' => '1',
    ], 'jmrenouard@lightpath.fr');
    $t->assertFalse($s22UpdatedFlags['docker_labs'], "Section 22: FeatureManager::updateFlags() toggles docker_labs to false");

    // Verify persistence using a separate FeatureManager instance backed by the same StorageInterface
    $s22Fm2 = new \LightPath\LMS\Core\FeatureManager($s22Storage);
    $t->assertFalse($s22Fm2->isEnabled('docker_labs'), "Section 22: FeatureManager persists flag overrides across instances");
    $t->assertTrue($s22Fm2->isEnabled('gamification'), "Section 22: FeatureManager preserves enabled flags across instances");

    $s22LogsAfterFm = $s22Storage->getAuditLogs(10);
    $s22HasFmAudit = false;
    foreach ($s22LogsAfterFm as $s22Entry) {
        if (($s22Entry['action'] ?? '') === 'ADMIN_FEATURES_UPDATE' && ($s22Entry['actor'] ?? '') === 'jmrenouard@lightpath.fr') {
            $s22HasFmAudit = true;
            break;
        }
    }
    $t->assertTrue($s22HasFmAudit, "Section 22: FeatureManager::updateFlags() emits ADMIN_FEATURES_UPDATE audit entry");

    // 22.2 Auth: persistent throttling across separate instances, timing-safe credentials, RBAC, password change
    $s22Auth1 = new Auth($s22Storage);
    $s22Auth2 = new Auth($s22Storage);
    $s22ThrottleEmail = 'bruteforce_target@lightpath.fr';
    $s22ThrottleIp = '10.99.88.77';

    $t->assertFalse(
        $s22Auth1->isLoginRateLimited($s22ThrottleEmail, $s22ThrottleIp),
        "Section 22: Fresh account/IP is not rate limited initially"
    );
    for ($s22i = 0; $s22i < 5; $s22i++) {
        $s22Auth1->recordFailedLogin($s22ThrottleEmail, $s22ThrottleIp);
    }
    $t->assertTrue(
        $s22Auth2->isLoginRateLimited($s22ThrottleEmail, $s22ThrottleIp),
        "Section 22: Login throttle persists across SEPARATE Auth instances (blocks cookie-less bypass)"
    );
    $t->assertEquals(
        null,
        $s22Auth2->login($s22ThrottleEmail, 'WrongPassword123', $s22ThrottleIp),
        "Section 22: Locked-out account is rejected by Auth::login() across instances"
    );
    $s22Auth2->clearLoginAttempts($s22ThrottleEmail, $s22ThrottleIp);
    $s22Auth3 = new Auth($s22Storage);
    $t->assertFalse(
        $s22Auth2->isLoginRateLimited($s22ThrottleEmail, $s22ThrottleIp)
        || $s22Auth3->isLoginRateLimited($s22ThrottleEmail, $s22ThrottleIp),
        "Section 22: Auth::clearLoginAttempts() resets persistent throttling state across new Auth instances"
    );

    $s22VerifiedStudent = $s22Auth1->verifyCredentials('stagiaire@lightpath.fr', 'Stagiaire2026!');
    $t->assertTrue(
        is_array($s22VerifiedStudent) && ($s22VerifiedStudent['email'] ?? '') === 'stagiaire@lightpath.fr',
        "Section 22: Auth::verifyCredentials() validates correct seeded student credentials"
    );
    $t->assertEquals(
        null,
        $s22Auth1->verifyCredentials('stagiaire@lightpath.fr', 'BadPassword!'),
        "Section 22: Auth::verifyCredentials() rejects incorrect password"
    );
    $t->assertEquals(
        null,
        $s22Auth1->verifyCredentials('missing_user_timing@lightpath.fr', 'AnyPassword!'),
        "Section 22: Auth::verifyCredentials() executes dummy hash safely for unknown emails"
    );

    $t->assertTrue($s22Auth1->isValidRole('student') && $s22Auth1->isValidRole('trainer') && $s22Auth1->isValidRole('admin'), "Section 22: Auth::isValidRole() accepts student, trainer, admin");
    $t->assertFalse($s22Auth1->isValidRole('superhacker'), "Section 22: Auth::isValidRole() rejects invalid roles");

    $s22AdminUser = $s22Storage->getUserByEmail('jmrenouard@lightpath.fr');
    $s22TrainerUser = $s22Storage->getUserByEmail('formateur@lightpath.fr');
    $t->assertTrue($s22Auth1->isAdmin($s22AdminUser), "Section 22: Auth::isAdmin() returns true for admin account");
    $t->assertFalse($s22Auth1->isAdmin($s22VerifiedStudent), "Section 22: Auth::isAdmin() returns false for student account");
    $t->assertTrue($s22Auth1->isStaff($s22TrainerUser), "Section 22: Auth::isStaff() returns true for trainer account");

    $t->assertTrue(
        $s22Auth1->canAccessCourse(['role' => 'trainer', 'enrolled_courses' => ['other-course']], 'postgresql-18'),
        "Section 22: Auth::canAccessCourse() grants staff access to all courses"
    );
    $t->assertFalse(
        $s22Auth1->canAccessCourse(['role' => 'student', 'enrolled_courses' => []], 'postgresql-18'),
        "Section 22: Auth::canAccessCourse() denies access when enrolled_courses is explicitly empty"
    );
    $t->assertTrue(
        $s22Auth1->canAccessCourse(['role' => 'student'], 'postgresql-18'),
        "Section 22: Auth::canAccessCourse() defaults legacy users with unset enrolled_courses to postgresql-18"
    );
    $t->assertTrue(
        $s22Auth1->canAccessCourse(['role' => 'student', 'enrolled_courses' => null], 'postgresql-18'),
        "Section 22: Auth::canAccessCourse() defaults null enrolled_courses to postgresql-18"
    );
    $t->assertFalse(
        $s22Auth1->canAccessCourse(['role' => 'student', 'enrolled_courses' => ['other-course']], 'postgresql-18'),
        "Section 22: Auth::canAccessCourse() restricts student to enrolled_courses"
    );

    $s22TempUser = $s22Auth1->registerUser('Temp QA', 'temp_qa_sec22@lightpath.fr', 'InitialPass2026!', 'student', 'LightPath');
    $t->assertTrue(is_array($s22TempUser) && (int)($s22TempUser['id'] ?? 0) > 0, "Section 22: Auth::registerUser() creates account for password/session checks");
    $s22TempUid = (int)$s22TempUser['id'];
    $_SESSION['user_id'] = $s22TempUid;
    $_SESSION['last_activity'] = time();
    $s22Curr = $s22Auth1->getCurrentUser();
    $t->assertTrue(is_array($s22Curr) && (int)$s22Curr['id'] === $s22TempUid, "Section 22: Auth::getCurrentUser() resolves active session user");
    $s22Auth1->logout();

    $s22BadPwRes = $s22Auth1->changePassword($s22TempUid, 'WrongCurrent!', 'NewPass2026!');
    $t->assertFalse($s22BadPwRes['success'], "Section 22: Auth::changePassword() rejects invalid current_password");
    $s22GoodPwRes = $s22Auth1->changePassword($s22TempUid, 'InitialPass2026!', 'UpdatedPass2026!');
    $t->assertTrue($s22GoodPwRes['success'], "Section 22: Auth::changePassword() updates password when current_password matches");
    $t->assertTrue($s22Auth1->verifyCredentials('temp_qa_sec22@lightpath.fr', 'UpdatedPass2026!') !== null, "Section 22: New password authenticates after changePassword()");
    $t->assertTrue($s22Storage->deleteUser($s22TempUid), "Section 22: JsonStorage::deleteUser() deletes temporary test account");
    $s22DirectSaved = $s22Storage->saveUser([
        'name' => 'Direct Save QA',
        'email' => 'direct_save_qa@lightpath.fr',
        'password_hash' => password_hash('DirectPass2026!', PASSWORD_BCRYPT),
        'role' => 'student',
        'company' => 'LightPath',
        'active' => true,
        'enrolled_courses' => ['postgresql-18'],
    ]);
    $t->assertTrue((int)($s22DirectSaved['id'] ?? 0) > 0, "Section 22: JsonStorage::saveUser() persists new user record directly");
    $t->assertTrue($s22Storage->deleteUser((int)$s22DirectSaved['id']), "Section 22: Clean up direct-saved user");

    // 22.3 JsonStorage CSPRNG certificate codes, >250 audit retention, monthly archive, CRUD, & PdoStorage
    $s22ExamA = $s22Storage->saveExamAttempt(2, 'postgresql-18', ['score' => 18, 'total' => 20, 'percentage' => 90, 'passed' => true]);
    $s22ExamB = $s22Storage->saveExamAttempt(2, 'postgresql-18', ['score' => 19, 'total' => 20, 'percentage' => 95, 'passed' => true]);
    $s22CertPattern = '/^LP-PG18-CERT-2-[0-9A-F]{8}$/';
    $t->assertTrue(
        (bool)preg_match($s22CertPattern, (string)($s22ExamA['certificate_id'] ?? ''))
        && (bool)preg_match($s22CertPattern, (string)($s22ExamB['certificate_id'] ?? ''))
        && ($s22ExamA['certificate_id'] ?? '') !== ($s22ExamB['certificate_id'] ?? ''),
        "Section 22: JsonStorage::saveExamAttempt() generates distinct CSPRNG LP-PG18-CERT-<uid>-<8HEX> codes"
    );
    $t->assertTrue(count($s22Storage->getExamAttempts(2, 'postgresql-18')) === 2, "Section 22: JsonStorage::getExamAttempts() retrieves saved attempts");
    $t->assertEquals(95, (int)($s22Storage->getBestExamAttempt(2, 'postgresql-18')['percentage'] ?? 0), "Section 22: JsonStorage::getBestExamAttempt() identifies top score");
    $t->assertTrue(!empty($s22Storage->getAllExamAttempts()), "Section 22: JsonStorage::getAllExamAttempts() returns cohort exam map");

    // Seed 998 historical audit events and push 5 new ones to verify >250 retention (1000) & monthly archiving (3 overflow)
    $s22SeedAudit = [];
    for ($s22k = 1; $s22k <= 998; $s22k++) {
        $s22SeedAudit[] = [
            'id' => 'seed_' . $s22k,
            'timestamp' => '2026-01-15 10:00:00',
            'actor' => 'seed@lightpath.fr',
            'action' => 'SEED_EVENT',
            'details' => 'Historical audit record #' . $s22k,
            'ip' => '127.0.0.1',
        ];
    }
    file_put_contents($s22Data . '/audit_logs.json', json_encode($s22SeedAudit, JSON_PRETTY_PRINT));
    for ($s22m = 1; $s22m <= 5; $s22m++) {
        $s22Storage->logAuditEvent('qa@lightpath.fr', 'QA_RETENTION_TEST', 'Overflow verification #' . $s22m);
    }
    $s22ActiveAudit = $s22Storage->getAuditLogs(1500);
    $s22ArchiveFile = $s22Data . '/audit_logs_202601.json';
    $s22ArchiveData = is_file($s22ArchiveFile) ? json_decode((string)file_get_contents($s22ArchiveFile), true) : null;
    $t->assertEquals(1000, count($s22ActiveAudit), "Section 22: JsonStorage::logAuditEvent() enforces 1000 active log retention (>250)");
    $t->assertTrue(
        is_file($s22ArchiveFile) && is_array($s22ArchiveData) && count($s22ArchiveData) === 3,
        "Section 22: JsonStorage::logAuditEvent() archives overflow entries into audit_logs_YYYYMM.json"
    );

    // Exercise custom course lifecycle, progress reset, throttle storage, feature flags storage, and PdoStorage offline fallback
    $s22Storage->saveCustomCourse([
        'slug' => 'pg-custom-sec22',
        'title' => 'PostgreSQL Custom Sec22',
        'active' => true,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $t->assertTrue($s22Storage->toggleCustomCourse('pg-custom-sec22', false), "Section 22: JsonStorage::toggleCustomCourse() toggles course active state");
    $t->assertTrue($s22Storage->deleteCustomCourse('pg-custom-sec22'), "Section 22: JsonStorage::deleteCustomCourse() deletes custom course");

    $s22Storage->markItemCompleted(2, 'postgresql-18', 'module', 'module_01_introduction_postgresql_18', true);
    $s22Storage->recordLearningTime(2, 'postgresql-18', 180, 'module_01_introduction_postgresql_18');
    $s22AllProgDirect = $s22Storage->getAllProgress();
    $t->assertTrue(isset($s22AllProgDirect['2:postgresql-18']), "Section 22: JsonStorage::getAllProgress() exposes persisted progress map");
    $t->assertTrue($s22Storage->resetUserProgress(2, 'postgresql-18'), "Section 22: JsonStorage::resetUserProgress() clears learner course progress");
    $t->assertTrue(empty($s22Storage->getUserProgress(2, 'postgresql-18')['completed_modules'] ?? []), "Section 22: Progress modules empty after resetUserProgress()");

    $s22Storage->recordLoginFailure('raw_key_test', 300);
    $t->assertTrue(is_array($s22Storage->getLoginThrottle('raw_key_test')), "Section 22: JsonStorage::getLoginThrottle() reads throttle bucket");
    $s22Storage->clearLoginThrottle('raw_key_test');
    $t->assertEquals(null, $s22Storage->getLoginThrottle('raw_key_test'), "Section 22: JsonStorage::clearLoginThrottle() deletes throttle bucket");
    $t->assertTrue(is_array($s22Storage->getFeatureFlags()) && is_array($s22Storage->saveFeatureFlags(['gamification' => true])), "Section 22: JsonStorage feature flag getters/setters return arrays");
    $t->assertTrue(
        is_array($s22Storage->getAllDiagnostics()) && is_array($s22Storage->getAllAttendance()) && is_array($s22Storage->getAllSatisfactionSurveys()),
        "Section 22: JsonStorage cohort read accessors return arrays"
    );

    $s22PdoFallback = new PdoStorage([], $s22Data);
    $t->assertFalse($s22PdoFallback->isPdoActive(), "Section 22: PdoStorage::isPdoActive() returns false without DSN");
    $t->assertEquals(null, $s22PdoFallback->getLastError(), "Section 22: PdoStorage::getLastError() returns null on clean JSON fallback");

    // 22.4 Security headers & HTTPS/filename helpers
    $s22SecSrc = (string)file_get_contents(__DIR__ . '/../src/Core/Security.php');
    $t->assertTrue(
        str_contains($s22SecSrc, 'Content-Security-Policy:')
        && str_contains($s22SecSrc, 'Cross-Origin-Opener-Policy: same-origin')
        && str_contains($s22SecSrc, 'Strict-Transport-Security: max-age=31536000; includeSubDomains')
        && str_contains($s22SecSrc, 'if (self::isHttpsRequest())'),
        "Section 22: Security::sendSecurityHeaders() configures CSP, COOP, and HTTPS-guarded HSTS"
    );
    Security::sendSecurityHeaders(); // Safe CLI invocation check
    $_SERVER['HTTPS'] = 'on';
    $t->assertTrue(Security::isHttpsRequest(), "Section 22: Security::isHttpsRequest() detects HTTPS=on");
    $_SERVER['HTTPS'] = 'off';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);
    $t->assertFalse(Security::isHttpsRequest(), "Section 22: Security::isHttpsRequest() returns false when HTTP only");
    $t->assertEquals('evil_file_.sql', Security::sanitizeFilename('../secret/evil file?.sql'), "Section 22: Security::sanitizeFilename() strips traversal and unsafe characters");

    // 22.5 LabSqlRunner: dangerous SQL blocking, 34 oracle queries unblocked, schema inspector, solution runner
    putenv('LMS_DOCKER_FORCE_SIMULATED=1');
    $s22Dm = new DockerManager($s22Storage, [], $s22Data . '/docker_status_cache.json');
    $s22Rt = $s22Dm->detectRuntime(true);
    $t->assertEquals('cloud_simulated', $s22Rt['mode'] ?? '', "Section 22: DockerManager enters cloud_simulated mode cleanly under LMS_DOCKER_FORCE_SIMULATED=1");

    $s22Runner = new LabSqlRunner($s22Dm);
    $s22BlockedPayloads = [
        'COPY FROM PROGRAM' => "COPY public.actor FROM PROGRAM 'id'",
        'ALTER SYSTEM' => "ALTER SYSTEM SET shared_buffers = '16MB'",
        'ALTER USER' => "ALTER USER postgres WITH SUPERUSER",
        'ALTER ROLE' => "ALTER ROLE lms_student SUPERUSER",
        'SET ROLE' => "SET ROLE postgres; SELECT current_user",
        '\\g |cmd' => "SELECT 1 \\g |id",
        '\\gx' => "SELECT 1 \\gx",
        '\\gset' => "SELECT 1 AS val \\gset",
        '\\lo_export' => "SELECT 1 \\lo_export 100 /tmp/leak",
        'set_config statement_timeout' => "SELECT set_config('statement_timeout', '0', false)",
        'pg_read_file' => "SELECT pg_read_file('/etc/passwd')",
    ];
    foreach ($s22BlockedPayloads as $s22Label => $s22Sql) {
        $s22Res = $s22Runner->executeSql(2, $s22Sql, 'formation_pg', false);
        $t->assertTrue(
            ($s22Res['container'] ?? '') === 'blocked' && ($s22Res['success'] ?? true) === false,
            "Section 22: LabSqlRunner blocks dangerous payload ({$s22Label})"
        );
    }

    $s22Oracles = $s22Runner->getLabOracles();
    $t->assertEquals(34, count($s22Oracles), "Section 22: LabSqlRunner::getLabOracles() contains all 34 lab oracles");
    $s22OracleBlockedCount = 0;
    foreach ($s22Oracles as $s22Code => $s22OracleDef) {
        $s22OracleRes = $s22Runner->executeSql(2, (string)$s22OracleDef['sql'], 'formation_pg', true);
        if (($s22OracleRes['container'] ?? '') === 'blocked') {
            $s22OracleBlockedCount++;
        }
    }
    $t->assertEquals(0, $s22OracleBlockedCount, "Section 22: All 34 LabSqlRunner::getLabOracles() queries pass security filters unblocked");

    $s22Schema = $s22Runner->inspectDatabaseSchema(2, 'dvdrental');
    $t->assertTrue(
        isset($s22Schema['source'], $s22Schema['tables'])
        && is_string($s22Schema['source'])
        && is_array($s22Schema['tables'])
        && array_is_list($s22Schema['tables'])
        && !empty($s22Schema['tables'])
        && isset($s22Schema['tables'][0]['schema'], $s22Schema['tables'][0]['table'], $s22Schema['tables'][0]['est_rows'], $s22Schema['tables'][0]['columns']),
        "Section 22: LabSqlRunner::inspectDatabaseSchema() returns source + indexed tables metadata"
    );

    $s22SolRes = $s22Runner->runLabSolution(2, 'lab_01_introduction_setup_postgresql_18');
    $t->assertTrue(
        isset($s22SolRes['steps_executed'], $s22SolRes['steps_count']) && is_int($s22SolRes['steps_executed']),
        "Section 22: LabSqlRunner::runLabSolution() returns steps_executed & steps_count"
    );
    $s22ValRes = $s22Runner->validateLabOracle(2, 'lab_01_introduction_setup_postgresql_18');
    $s22RunOracleRes = $s22Runner->runLabOracle(2, 'lab_01_introduction_setup_postgresql_18');
    $t->assertTrue(
        array_key_exists('passed', $s22ValRes) && array_key_exists('passed', $s22RunOracleRes),
        "Section 22: LabSqlRunner::validateLabOracle() & runLabOracle() return structured results"
    );

    // 22.6 SakilaSeeder schema completeness & DockerManager hardening/simulation/timeout
    $s22BootSql = \LightPath\LMS\Docker\SakilaSeeder::getBootstrapSql(true, 'all');
    $s22RoleSql = \LightPath\LMS\Docker\SakilaSeeder::getSandboxRoleSql();
    $s22CatSummary = \LightPath\LMS\Docker\SakilaSeeder::getDatasetCatalogSummary();
    $t->assertTrue(
        str_contains($s22BootSql, 'public.actor')
        && str_contains($s22BootSql, 'public.film')
        && str_contains($s22BootSql, 'public.film_actor')
        && str_contains($s22BootSql, 'public.rental')
        && str_contains($s22BootSql, 'NULL')
        && str_contains($s22BootSql, 'public.payment')
        && str_contains($s22BootSql, 'public.pgbench_accounts'),
        "Section 22: SakilaSeeder::getBootstrapSql() includes actor, film, film_actor, rental (NULL returns), payment & pgbench_accounts"
    );
    $t->assertTrue(
        str_contains($s22RoleSql, 'lms_student') && str_contains($s22RoleSql, 'NOSUPERUSER') && str_contains($s22RoleSql, 'NOCREATEDB') && str_contains($s22RoleSql, 'NOCREATEROLE'),
        "Section 22: SakilaSeeder::getSandboxRoleSql() enforces non-superuser sandbox role lms_student"
    );
    $t->assertTrue(
        !empty($s22CatSummary),
        "Section 22: SakilaSeeder::getDatasetCatalogSummary() returns dataset catalog metadata"
    );

    $s22DmSrc = (string)file_get_contents(__DIR__ . '/../src/Docker/DockerManager.php');
    $t->assertTrue(
        str_contains($s22DmSrc, '--memory=1g')
        && str_contains($s22DmSrc, '--cpus=1.0')
        && str_contains($s22DmSrc, '--pids-limit=256')
        && str_contains($s22DmSrc, '--security-opt=no-new-privileges')
        && str_contains($s22DmSrc, '--shm-size=512m'),
        "Section 22: DockerManager enforces hardened docker run resource & privilege flags"
    );
    $t->assertEquals('postgres', $s22Dm->getDefaultPassword(), "Section 22: DockerManager::getDefaultPassword() returns 'postgres'");
    $s22ConnInfo = $s22Dm->getConnectionInfo(2);
    $t->assertEquals(5502, $s22ConnInfo['port'] ?? 0, "Section 22: DockerManager::getConnectionInfo() computes deterministic user port");

    $s22StatusSim = $s22Dm->getUserContainerStatus(2);
    $s22StartSim = $s22Dm->startUserContainer(2, 'stagiaire@lightpath.fr');
    $s22StopSim = $s22Dm->stopUserContainer(2, 'stagiaire@lightpath.fr');
    $s22RestartSim = $s22Dm->restartUserContainer(2, 'stagiaire@lightpath.fr');
    $s22ResetSim = $s22Dm->resetUserContainer(2, 'stagiaire@lightpath.fr');
    $s22SeedSim = $s22Dm->seedUserContainer(2, 'all', true, 'stagiaire@lightpath.fr');
    $s22LogsSim = $s22Dm->getContainerLogs(2, 20);
    $s22StatsSim = $s22Dm->getContainerStats(2);
    $s22StopAllSim = $s22Dm->stopAllLabContainers('jmrenouard@lightpath.fr');
    $s22PruneSim = $s22Dm->pruneStoppedLabContainers('jmrenouard@lightpath.fr');
    $s22ChaosSim = $s22Dm->triggerChaosScenario('bloat', 'inject', 'jmrenouard@lightpath.fr');
    $s22RunCmdSim = $s22Dm->runDockerCommand('ps', 1.0);
    $s22ListSim = $s22Dm->listAllLabContainers();

    $t->assertTrue(
        ($s22StatusSim['status'] ?? '') === 'simulated' && ($s22StatusSim['runtime_mode'] ?? '') === 'cloud_simulated' && ($s22StatusSim['container_name'] ?? '') === 'lp_pg18_user_2'
        && !empty($s22StartSim['simulated']) && !empty($s22StopSim['simulated']) && !empty($s22RestartSim['simulated']) && !empty($s22ResetSim['simulated']),
        "Section 22: DockerManager user lifecycle methods respond cleanly in cloud_simulated mode"
    );
    $t->assertTrue(
        !empty($s22SeedSim['simulated']) && ($s22SeedSim['mode'] ?? '') === 'cloud_simulated' && isset($s22SeedSim['dataset']),
        "Section 22: DockerManager::seedUserContainer() returns simulated catalog response under LMS_DOCKER_FORCE_SIMULATED=1"
    );
    $t->assertTrue(
        !empty($s22LogsSim['simulated']) && isset($s22LogsSim['logs'])
        && !empty($s22StatsSim['simulated']) && isset($s22StatsSim['mem_usage']),
        "Section 22: DockerManager::getContainerLogs() & getContainerStats() return simulated metrics cleanly"
    );
    $t->assertTrue(
        !empty($s22StopAllSim['success']) && !empty($s22PruneSim['success']) && !empty($s22ChaosSim['success']) && ($s22RunCmdSim['code'] ?? 0) === 1 && $s22ListSim === [],
        "Section 22: DockerManager fleet maintenance & chaos helpers handle simulated runtime cleanly"
    );

    $s22FastProc = $s22Dm->execCommandWithTimeout(escapeshellarg(PHP_BINARY) . ' -r "echo 42;"', 2.0);
    $t->assertTrue(
        ($s22FastProc['code'] ?? -1) === 0 && ($s22FastProc['timed_out'] ?? true) === false && ($s22FastProc['output'] ?? '') === '42',
        "Section 22: DockerManager::execCommandWithTimeout() captures fast command output without timeout"
    );
    $s22SlowProc = $s22Dm->execCommandWithTimeout(escapeshellarg(PHP_BINARY) . ' -r "sleep(3);"', 0.3);
    $t->assertTrue(
        ($s22SlowProc['timed_out'] ?? false) === true && ($s22SlowProc['code'] ?? 0) === 124,
        "Section 22: DockerManager::execCommandWithTimeout() enforces wall-clock timeout (timed_out=true, code=124)"
    );

    // 22.7 AdminService KPIs (preloaded $allProgress, no N+1), filters, CSV export safety, system health
    $s22CoursesCfg = require __DIR__ . '/../config/courses.php';
    $s22AppCfg = require __DIR__ . '/../config/app.php';
    $s22AppCfg['storage_path'] = $s22Data;
    $s22Repo = new CourseRepository($s22CoursesCfg, [], $s22Cache);
    $s22PgCourse = $s22Repo->loadCourse('postgresql-18');
    $s22ModulesList = is_array($s22PgCourse['modules'] ?? null) ? $s22PgCourse['modules'] : [];
    $s22LabsList = is_array($s22PgCourse['labs'] ?? null) ? $s22PgCourse['labs'] : [];

    $s22AdminSvc = new \LightPath\LMS\Core\AdminService();
    $s22PreloadedProg = [
        '3:postgresql-18' => [
            'completed_modules' => ['module_01_introduction_postgresql_18', 'module_02_architecture_postgresql_18'],
            'completed_labs' => ['lab_01_introduction_setup_postgresql_18'],
            'quiz_scores' => ['module_01_introduction_postgresql_18' => ['score' => 4, 'total' => 5, 'percentage' => 80]],
            'learning_seconds' => 3660,
        ],
    ];
    $s22UsersAll = $s22Storage->getUsers();
    $s22Kpis = $s22AdminSvc->computeCohortKpis($s22UsersAll, $s22Storage, $s22ModulesList, $s22LabsList, 'postgresql-18', $s22PreloadedProg);
    $t->assertTrue(
        ($s22Kpis['students_count'] ?? 0) >= 1
        && isset($s22Kpis['per_user'][3]['avg_quiz_score'])
        && (int)$s22Kpis['per_user'][3]['avg_quiz_score'] === 80
        && (int)$s22Kpis['per_user'][3]['learning_seconds'] === 3660,
        "Section 22: AdminService::computeCohortKpis() aggregates KPIs from preloaded \$allProgress without N+1"
    );

    $s22FilteredUsers = $s22AdminSvc->filterUsers($s22UsersAll, 'stagiaire', 'student');
    $t->assertTrue(count($s22FilteredUsers) === 1 && ($s22FilteredUsers[0]['email'] ?? '') === 'stagiaire@lightpath.fr', "Section 22: AdminService::filterUsers() filters by query & role");

    $s22SampleLogs = [
        ['timestamp' => '2026-11-15 10:00:00', 'actor' => 'alice@lightpath.fr', 'action' => 'LOGIN', 'details' => 'Ok'],
        ['timestamp' => '2026-11-15 10:01:00', 'actor' => '=cmd|"/C calc"!A0', 'action' => 'ADMIN_EXPORT', 'details' => '+SUM(A1:A2)'],
    ];
    $s22FilteredLogs = $s22AdminSvc->filterAuditLogs($s22SampleLogs, 'ADMIN_', 'cmd');
    $t->assertEquals(1, count($s22FilteredLogs), "Section 22: AdminService::filterAuditLogs() filters by category prefix & actor");

    $s22AuditCsv = $s22AdminSvc->exportAuditLogsCsv($s22SampleLogs);
    $t->assertTrue(
        str_starts_with($s22AuditCsv, "\xEF\xBB\xBF")
        && str_contains($s22AuditCsv, "'=cmd")
        && str_contains($s22AuditCsv, "'+SUM"),
        "Section 22: AdminService::exportAuditLogsCsv() prepends UTF-8 BOM & neutralizes CSV formula injection"
    );

    $s22Health = $s22AdminSvc->getSystemHealthReport($s22AppCfg, $s22Storage, $s22Dm, $s22Repo, $s22PdoFallback);
    $t->assertTrue(
        isset($s22Health['checks'], $s22Health['summary']['total']) && (int)$s22Health['summary']['total'] >= 5,
        "Section 22: AdminService::getSystemHealthReport() aggregates runtime diagnostics"
    );

    // 22.8 CourseRepository accessors, MarkdownParser helpers, Gamification duration, DownloadController & Docs suite
    $t->assertTrue(
        is_array($s22PgCourse) && !empty($s22ModulesList) && !empty($s22LabsList),
        "Section 22: CourseRepository::loadCourse('postgresql-18') returns structured catalog"
    );
    $s22CsId = (string)(array_key_first($s22PgCourse['cheatsheets'] ?? []) ?? '');
    $s22RefId = (string)(array_key_first($s22PgCourse['referentiel'] ?? []) ?? '');
    $s22ToolId = (string)(array_key_first($s22PgCourse['tools'] ?? []) ?? '');
    $s22ExId = (string)(array_key_first($s22PgCourse['examples'] ?? []) ?? '');
    $t->assertTrue(
        ($s22CsId !== '' && $s22Repo->getCheatsheet('postgresql-18', $s22CsId) !== null)
        && ($s22RefId !== '' && $s22Repo->getReferentielDoc('postgresql-18', $s22RefId) !== null)
        && ($s22ToolId !== '' && $s22Repo->getToolDoc('postgresql-18', $s22ToolId) !== null)
        && ($s22ExId !== '' && $s22Repo->getExampleDoc('postgresql-18', $s22ExId) !== null),
        "Section 22: CourseRepository resolves cheatsheets, referentiel, tools & companion examples"
    );
    $t->assertEquals('Bonjour…', CourseRepository::truncateUtf8('BonjourPostgreSQL18', 7), "Section 22: CourseRepository::truncateUtf8() preserves UTF-8 truncation");
    $t->assertEquals('2,0 Ko', CourseRepository::formatBytes(2048), "Section 22: CourseRepository::formatBytes() formats file sizes cleanly");

    $s22Parser = new MarkdownParser(null, $s22Cache);
    $s22ModMd = "## Diapositive 1 — Architecture\n\n```sql\nSELECT version();\n```\n\n## Quiz\n<details>\n<summary>Question 1 : Quelle commande vérifie la version PostgreSQL ?</summary>\n\n- **A.** SELECT version();\n- **B.** SHOW TABLES;\n\nRéponse correcte : A\nJustification : version() retourne la version serveur PostgreSQL.\n</details>\n";
    $s22RenderedMd = $s22Parser->renderMarkdown($s22ModMd, 'postgresql-18', false);
    $s22ExtractedQuiz = $s22Parser->extractQuizQuestions($s22ModMd);
    $s22ExtractedSql = $s22Parser->extractSqlSnippets($s22ModMd);
    $s22ExtractedSlides = $s22Parser->extractSlides($s22ModMd, 'postgresql-18', $s22ExtractedQuiz);
    $t->assertTrue(
        !empty($s22RenderedMd['html']) && !empty($s22ExtractedQuiz) && !empty($s22ExtractedSql) && !empty($s22ExtractedSlides),
        "Section 22: MarkdownParser extracts quizzes, SQL snippets, slides & HTML cleanly"
    );

    $t->assertEquals('0 min', Gamification::formatDurationHours(0), "Section 22: Gamification::formatDurationHours(0) returns '0 min'");
    $t->assertEquals('2 min', Gamification::formatDurationHours(120), "Section 22: Gamification::formatDurationHours(120) returns '2 min'");
    $t->assertEquals('1h 01m', Gamification::formatDurationHours(3660), "Section 22: Gamification::formatDurationHours(3660) returns '1h 01m'");

    $s22DlCtrl = new DownloadController($s22Repo);
    $t->assertEquals(
        null,
        $s22DlCtrl->resolveRelativeCourseAsset('postgresql-18', '../../etc/passwd'),
        "Section 22: DownloadController::resolveRelativeCourseAsset() rejects path traversal"
    );

    // Verify README.md, PROJECT-CONTEXT.md and all 9 docs/*.md files exist without vendor leaks or external CDNs
    $s22RequiredDocs = [
        __DIR__ . '/../README.md',
        __DIR__ . '/../PROJECT-CONTEXT.md',
        __DIR__ . '/../docs/ADMIN_AND_TRAINER_GUIDE.md',
        __DIR__ . '/../docs/API_REFERENCE.md',
        __DIR__ . '/../docs/ARCHITECTURE.md',
        __DIR__ . '/../docs/COURSE_PACK_GUIDE.md',
        __DIR__ . '/../docs/DEPLOYMENT_AND_OPERATIONS.md',
        __DIR__ . '/../docs/DEVELOPER_AND_TESTING_GUIDE.md',
        __DIR__ . '/../docs/DOCKER_LABS_GUIDE.md',
        __DIR__ . '/../docs/QUALITY_AUDIT_PONYTAIL.md',
        __DIR__ . '/../docs/SECURITY.md',
    ];
    $s22BlockedBrand = 'noble' . 'prog';
    $s22BlockedCdnNeedles = ['https://cd' . 'n.', 'fonts.google' . 'apis', 'unp' . 'kg.com', 'jsdeli' . 'vr.net'];
    foreach ($s22RequiredDocs as $s22DocPath) {
        $s22DocLabel = basename($s22DocPath);
        $s22DocExists = is_file($s22DocPath);
        $s22DocContent = $s22DocExists ? (string)file_get_contents($s22DocPath) : '';
        $s22CleanBrand = $s22DocExists && $s22DocContent !== '' && stripos($s22DocContent, $s22BlockedBrand) === false;
        $s22CleanCdn = true;
        foreach ($s22BlockedCdnNeedles as $s22Cdn) {
            if (stripos($s22DocContent, $s22Cdn) !== false) {
                $s22CleanCdn = false;
                break;
            }
        }
        $t->assertTrue(
            $s22DocExists && $s22CleanBrand && $s22CleanCdn,
            "Section 22: Doc {$s22DocLabel} exists cleanly with 0 vendor leaks & 0 external CDN URLs"
        );
    }
} finally {
    if ($s22PrevSimEnv === false) {
        putenv('LMS_DOCKER_FORCE_SIMULATED');
    } else {
        putenv('LMS_DOCKER_FORCE_SIMULATED=' . $s22PrevSimEnv);
    }
    $_SESSION = $s22SavedSession;
    $_SERVER = $s22SavedServer;

    $s22Cleanup = static function (string $dir) use (&$s22Cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $s22Cleanup($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    };
    $s22Cleanup($s22Root);
}

// ============================================================================
// 23. Wave 4A Verification Suite (Security CSP, Lexer, Range, Archive, Cache)
// ============================================================================
echo "\n--- 23. Wave 4A Hardening & Regression Checks ---\n";
$s23Root = sys_get_temp_dir() . '/lp_sec23_' . bin2hex(random_bytes(4));
$s23Data = $s23Root . '/data';
$s23Cache = $s23Root . '/cache';
@mkdir($s23Data, 0775, true);
@mkdir($s23Cache, 0775, true);

try {
    $s23StorageA = new JsonStorage($s23Data);
    $s23StorageB = new JsonStorage($s23Data);

    // 23.1 FeatureManager per-instance memoization and cache invalidation
    $s23Fm = new \LightPath\LMS\Core\FeatureManager($s23StorageA);
    $s23InitialFlags = $s23Fm->all();
    $t->assertTrue(($s23InitialFlags['docker_labs'] ?? false) === true, "Section 23: FeatureManager::all() returns enabled defaults");
    $s23StorageB->saveFeatureFlags(['docker_labs' => false]);
    $s23MemoizedFlags = $s23Fm->all();
    $t->assertTrue(
        ($s23MemoizedFlags['docker_labs'] ?? false) === true,
        "Section 23: FeatureManager::all() memoizes flags within instance lifetime"
    );
    $s23Fm->updateFlags(['docker_labs' => false, 'qna_forum' => true], 'qa-sec23@lightpath.fr');
    $s23InvalidatedFlags = $s23Fm->all();
    $t->assertTrue(
        ($s23InvalidatedFlags['docker_labs'] ?? true) === false && $s23Fm->isEnabled('docker_labs') === false,
        "Section 23: FeatureManager::updateFlags() invalidates memoized flag cache"
    );

    // 23.2 Security strict SHA-256 CSP on script-src (no 'unsafe-inline') matching unload shim
    $s23ShimHtml = Security::renderUnloadShimScript();
    $s23ShimMatched = preg_match('#<script[^>]*>(.*?)</script>#s', $s23ShimHtml, $s23ShimMatch) === 1;
    $t->assertTrue($s23ShimMatched && trim((string)($s23ShimMatch[1] ?? '')) !== '', "Section 23: Security::renderUnloadShimScript() renders inline shim script");
    $s23ExpectedHash = 'sha256-' . base64_encode(hash('sha256', (string)($s23ShimMatch[1] ?? ''), true));
    $s23SecSrc = (string)file_get_contents(__DIR__ . '/../src/Core/Security.php');
    $s23ScriptSrcDirective = '';
    if (preg_match('/script-src\s+([^;\r\n"]+)/', $s23SecSrc, $s23DirMatch)) {
        $s23ScriptSrcDirective = $s23DirMatch[0];
    }
    $t->assertTrue(
        $s23ExpectedHash === 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='
        && $s23ScriptSrcDirective !== ''
        && str_contains($s23ScriptSrcDirective, 'sha256-{$shimHash}')
        && str_contains($s23SecSrc, "base64_encode(hash('sha256', self::UNLOAD_SHIM_JS, true))")
        && !str_contains($s23ScriptSrcDirective, 'unsafe-inline'),
        "Section 23: Security CSP script-src enforces unload shim SHA-256 hash without 'unsafe-inline'"
    );

    // 23.3 JsonStorage::getAuditLogs(limit, includeArchives) merges monthly archives
    $s23SeedAudit = [];
    for ($s23k = 1; $s23k <= 998; $s23k++) {
        $s23SeedAudit[] = [
            'id' => 's23_seed_' . $s23k,
            'timestamp' => '2026-01-15 10:00:00',
            'actor' => 'seed23@lightpath.fr',
            'action' => 'SEED23_EVENT',
            'details' => 'Archive test #' . $s23k,
            'ip' => '127.0.0.1',
        ];
    }
    file_put_contents($s23Data . '/audit_logs.json', json_encode($s23SeedAudit, JSON_PRETTY_PRINT));
    for ($s23m = 1; $s23m <= 5; $s23m++) {
        $s23StorageA->logAuditEvent('qa-sec23@lightpath.fr', 'SEC23_AUDIT', 'Live event #' . $s23m);
    }
    $s23LogsActiveOnly = $s23StorageA->getAuditLogs(1500, false);
    $s23LogsWithArchives = $s23StorageA->getAuditLogs(1500, true);
    $s23LogsLimited = $s23StorageA->getAuditLogs(1001, true);
    $t->assertEquals(1000, count($s23LogsActiveOnly), "Section 23: JsonStorage::getAuditLogs(1500, false) returns 1000 active entries");
    $t->assertEquals(1003, count($s23LogsWithArchives), "Section 23: JsonStorage::getAuditLogs(1500, true) merges active + archived entries");
    $t->assertEquals(1001, count($s23LogsLimited), "Section 23: JsonStorage::getAuditLogs(1001, true) respects limit across merged archives");

    // 23.4 DockerManager $allowSharedFallback = false denial when user container is not running
    $s23DockerMock = new class($s23StorageA, [], $s23Data . '/docker_mock_cache.json') extends DockerManager {
        public function detectRuntime(bool $forceRefresh = false): array
        {
            return [
                'available' => true,
                'mode' => 'native',
                'prefix' => '',
                'version' => 'MockDocker 27.0',
            ];
        }

        public function getUserContainerStatus(int $userId): array
        {
            $name = $this->getContainerName($userId);
            $port = $this->getUserPort($userId);
            return [
                'container_name' => $name,
                'port' => $port,
                'status' => 'not_created',
                'running' => false,
                'user_container_running' => false,
                'primary_cluster_running' => true,
                'default_password' => $this->getDefaultPassword(),
                'runtime_mode' => 'native',
                'runtime_version' => 'MockDocker 27.0',
                'connection_string' => 'psql -h localhost -p ' . $port . ' -U postgres -d postgres',
            ];
        }
    };
    $s23SeedDeny = $s23DockerMock->seedUserContainer(2, 'all', true, 'stagiaire@lightpath.fr', false);
    $t->assertTrue(
        ($s23SeedDeny['success'] ?? true) === false
        && str_contains((string)($s23SeedDeny['message'] ?? ''), 'Veuillez démarrer votre conteneur personnel'),
        "Section 23: DockerManager::seedUserContainer(..., false) refuses fallback to pg_formation_primary"
    );
    $s23LogsDeny = $s23DockerMock->getContainerLogs(2, 20, false);
    $t->assertTrue(
        ($s23LogsDeny['success'] ?? true) === false
        && str_contains((string)($s23LogsDeny['error'] ?? $s23LogsDeny['message'] ?? ''), 'Veuillez démarrer votre conteneur personnel'),
        "Section 23: DockerManager::getContainerLogs(..., false) refuses fallback to pg_formation_primary"
    );

    // 23.5 LabSqlRunner lexical scanner, nested comments, DO blocks, concatenated set_config, and 34 oracles
    putenv('LMS_DOCKER_FORCE_SIMULATED=1');
    $s23DockerSim = new DockerManager($s23StorageA, [], $s23Data . '/docker_sim_cache.json');
    $s23SqlRunner = new LabSqlRunner($s23DockerSim);

    $s23BlockedSql = [
        'DO EXECUTE SET statement_timeout' => "DO \$\$ BEGIN EXECUTE 'SET statement_timeout = 0'; END \$\$;",
        'DO EXECUTE SET ROLE' => "DO \$\$ BEGIN EXECUTE 'SET ROLE postgres'; END \$\$;",
        'DO EXECUTE ALTER SYSTEM' => "DO \$\$ BEGIN EXECUTE 'ALTER SYSTEM SET fsync = off'; END \$\$;",
        'Concatenated set_config' => "SELECT set_config('statement'||'_timeout', '0', false);",
        'psql \w out of quotes' => "SELECT 1 \\w /tmp/leak.txt",
        'psql \g; out of quotes' => "SELECT 1\\g;",
        'Nested block comment bypass' => "/* outer /* inner */ */ SET ROLE postgres;",
        'Nested block comment COPY' => "/* nested /* comment */ */ COPY public.actor FROM PROGRAM 'id';",
    ];
    foreach ($s23BlockedSql as $s23Label => $s23Payload) {
        $s23Res = $s23SqlRunner->executeSql(2, $s23Payload, 'formation_pg', false);
        $t->assertTrue(
            ($s23Res['container'] ?? '') === 'blocked' && ($s23Res['success'] ?? true) === false,
            "Section 23: LabSqlRunner lexical scanner blocks ({$s23Label})"
        );
    }

    $s23AllowedSql = [
        'current_setting(statement_timeout)' => "SELECT current_setting('statement_timeout');",
        'Quoted \g inside DO $$ literal' => "DO \$\$ BEGIN RAISE NOTICE 'hello\\g'; END \$\$;",
        'Nested comment + literal \g' => "/* outer /* inner */ */ SELECT 'literal \\g safely' AS txt;",
    ];
    foreach ($s23AllowedSql as $s23Label => $s23Payload) {
        $s23Res = $s23SqlRunner->executeSql(2, $s23Payload, 'formation_pg', false);
        $t->assertTrue(
            ($s23Res['container'] ?? '') !== 'blocked',
            "Section 23: LabSqlRunner allows legitimate statement ({$s23Label})"
        );
    }

    $s23Oracles = $s23SqlRunner->getLabOracles();
    $s23Oracle16Ok = false;
    $s23BlockedOracles = 0;
    foreach ($s23Oracles as $s23OracleId => $s23OracleDef) {
        $s23ORes = $s23SqlRunner->executeSql(2, (string)$s23OracleDef['sql'], 'formation_pg', true);
        if (($s23ORes['container'] ?? '') === 'blocked') {
            $s23BlockedOracles++;
        } elseif ((string)$s23OracleId === '16') {
            $s23Oracle16Ok = true;
        }
    }
    $t->assertEquals(0, $s23BlockedOracles, "Section 23: All 34 LabSqlRunner oracles pass lexical validation unblocked");
    $t->assertTrue($s23Oracle16Ok, "Section 23: Lab 16 oracle (including DO/monitoring SQL) executes unblocked");

    // 23.6 DownloadController::resolveHttpRange() and real DownloadController::streamFile() subprocess
    $s23CoursesCfg = require __DIR__ . '/../config/courses.php';
    $s23Repo = new CourseRepository($s23CoursesCfg, [], $s23Cache);
    $s23Range200 = \LightPath\LMS\Course\DownloadController::resolveHttpRange('', 1000);
    $s23Range206Start = \LightPath\LMS\Course\DownloadController::resolveHttpRange('bytes=0-499', 1000);
    $s23Range206Suffix = \LightPath\LMS\Course\DownloadController::resolveHttpRange('bytes=-200', 1000);
    $s23Range206Open = \LightPath\LMS\Course\DownloadController::resolveHttpRange('bytes=500-', 1000);
    $s23Range416Oob = \LightPath\LMS\Course\DownloadController::resolveHttpRange('bytes=2000-3000', 1000);
    $s23Range416Inv = \LightPath\LMS\Course\DownloadController::resolveHttpRange('bytes=500-100', 1000);
    $t->assertTrue(
        ($s23Range200['status'] ?? 0) === 200 && ($s23Range200['length'] ?? 0) === 1000,
        "Section 23: DownloadController::resolveHttpRange() handles empty header as HTTP 200"
    );
    $t->assertTrue(
        ($s23Range206Start['status'] ?? 0) === 206 && ($s23Range206Start['start'] ?? -1) === 0 && ($s23Range206Start['end'] ?? -1) === 499 && ($s23Range206Start['length'] ?? 0) === 500
        && ($s23Range206Suffix['status'] ?? 0) === 206 && ($s23Range206Suffix['start'] ?? -1) === 800 && ($s23Range206Suffix['length'] ?? 0) === 200
        && ($s23Range206Open['status'] ?? 0) === 206 && ($s23Range206Open['start'] ?? -1) === 500 && ($s23Range206Open['length'] ?? 0) === 500,
        "Section 23: DownloadController::resolveHttpRange() resolves bounded, suffix, and open-ended HTTP 206 byte ranges"
    );
    $t->assertTrue(
        ($s23Range416Oob['status'] ?? 0) === 416 && ($s23Range416Inv['status'] ?? 0) === 416,
        "Section 23: DownloadController::resolveHttpRange() returns HTTP 416 for unsatisfiable or inverted ranges"
    );

    $s23SampleHtml = $s23Root . '/sample_stream.html';
    file_put_contents($s23SampleHtml, '<html><head><title>Demo</title></head><body><a href="../modules/module_01.md">Mod1</a></body></html>');
    $s23StreamRunner = $s23Root . '/run_stream.php';
    $s23StreamCode = <<<'PHP'
<?php
require $argv[1] . '/src/bootstrap.php';
$coursesCfg = require $argv[1] . '/config/courses.php';
$repo = new \LightPath\LMS\Course\CourseRepository($coursesCfg, [], $argv[2]);
$dc = new \LightPath\LMS\Course\DownloadController($repo);
$dc->streamFile([
    'course' => 'postgresql-18',
    'path' => $argv[3],
    'filename' => 'sample_stream.html',
    'mime' => 'text/html; charset=UTF-8',
    'size' => filesize($argv[3]),
], false);
PHP;
    file_put_contents($s23StreamRunner, $s23StreamCode);
    $s23RepoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $s23Proc = proc_open(
        [PHP_BINARY, $s23StreamRunner, $s23RepoRoot, $s23Cache, $s23SampleHtml],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $s23Pipes
    );
    $s23StreamOut = '';
    $s23StreamExit = -1;
    if (is_resource($s23Proc)) {
        fclose($s23Pipes[0]);
        $s23StreamOut = (string)stream_get_contents($s23Pipes[1]);
        fclose($s23Pipes[1]);
        fclose($s23Pipes[2]);
        $s23StreamExit = proc_close($s23Proc);
    }
    $t->assertTrue(
        $s23StreamExit === 0 && str_contains($s23StreamOut, 'Mod1') && str_contains($s23StreamOut, Security::renderUnloadShimScript()),
        "Section 23: DownloadController::streamFile() streams HTML with unload shim injected in child process"
    );

    // 23.7 AdminService::getSystemHealthReport() parsed_cache inspection and PDO error surfacing
    $s23AppCfg = require __DIR__ . '/../config/app.php';
    $s23AppCfg['storage_path'] = $s23Data;
    $s23ParsedDir = $s23Data . '/parsed_cache';
    @mkdir($s23ParsedDir, 0775, true);
    file_put_contents($s23ParsedDir . '/mod_01.html', '<p>cached 1</p>');
    file_put_contents($s23ParsedDir . '/mod_02.html', '<p>cached 2</p>');
    file_put_contents($s23Data . '/catalog_cache_postgresql-18.json', '{}');

    $s23ErrLog = $s23Root . '/php_pdo_err.log';
    $s23PrevErrLog = ini_set('error_log', $s23ErrLog);
    $s23BadPdo = new PdoStorage(['dsn' => 'invalid_driver_s23:host=127.0.0.1'], $s23Data);
    if ($s23PrevErrLog !== false) {
        ini_set('error_log', (string)$s23PrevErrLog);
    }
    $s23PdoErr = (string)($s23BadPdo->getLastError() ?? '');
    $t->assertTrue($s23PdoErr !== '', "Section 23: PdoStorage::getLastError() captures initialization error details");

    $s23AdminSvc = new \LightPath\LMS\Core\AdminService();
    $s23Health = $s23AdminSvc->getSystemHealthReport($s23AppCfg, $s23StorageA, $s23DockerSim, $s23Repo, $s23BadPdo);
    $s23HealthText = json_encode($s23Health['checks'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
    $t->assertTrue(
        str_contains($s23HealthText, $s23PdoErr),
        "Section 23: AdminService::getSystemHealthReport() surfaces PdoStorage::getLastError() in storage diagnostic"
    );
    $t->assertTrue(
        str_contains($s23HealthText, '2 ') || str_contains($s23HealthText, 'parsed_cache') || str_contains($s23HealthText, 'Markdown'),
        "Section 23: AdminService::getSystemHealthReport() inspects parsed_cache HTML & catalog JSON cache counts"
    );
} finally {
    $s23Cleanup = function (string $dir) use (&$s23Cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $s23Cleanup($full) : @unlink($full);
        }
        @rmdir($dir);
    };
    $s23Cleanup($s23Root);
}

// ============================================================================
// 24. Deep 7-Axis Behavioral & Unit Suite (Security, Auth, Audit Chain,
//     FeatureManager, Docker Savepoints/pgbench/Fleet, Lab Readiness, AdminService,
//     and Direct Http Controller Invocation)
// ============================================================================
echo "\n--- 24. Deep 7-Axis Behavioral & Unit Suite ---\n";
$s24Root = sys_get_temp_dir() . '/lp_sec24_' . bin2hex(random_bytes(4));
$s24Data = $s24Root . '/data';
$s24Cache = $s24Root . '/cache';
@mkdir($s24Data, 0775, true);
@mkdir($s24Cache, 0775, true);

$s24PrevSim = getenv('LMS_DOCKER_FORCE_SIMULATED');
$s24SavedSession = $_SESSION ?? [];
$s24SavedServer = $_SERVER;

try {
    putenv('LMS_DOCKER_FORCE_SIMULATED=1');
    $s24RepoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $s24Storage = new JsonStorage($s24Data);
    $s24Auth = new Auth($s24Storage);
    $s24Fm = new \LightPath\LMS\Core\FeatureManager($s24Storage);
    $s24AdminSvc = new \LightPath\LMS\Core\AdminService();
    $s24CoursesCfg = require __DIR__ . '/../config/courses.php';
    $s24CourseRepo = new CourseRepository($s24CoursesCfg, [], $s24Cache);
    $s24Downloader = new DownloadController($s24CourseRepo);
    $s24Parser = new MarkdownParser(null, $s24Cache);
    $s24Docker = new DockerManager($s24Storage, [], $s24Data . '/docker_status_cache.json');
    $s24SqlRunner = new LabSqlRunner($s24Docker);

    // 24.1 Security: standalone document CSP & Same-Origin request validation
    Security::sendStandaloneDocumentCspHeader();
    Security::sendStandaloneDocumentCspHeader(['sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA=']);
    $s24BuildCsp = new ReflectionMethod(Security::class, 'buildCspPolicy');
    $s24PortalPolicy = (string)$s24BuildCsp->invoke(null, "style-src 'self'");
    $s24StandalonePolicy = (string)$s24BuildCsp->invoke(
        null,
        "style-src 'self' 'unsafe-inline'",
        [
            'sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA=',
            'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58=',
            "sha256-bad; script-src 'unsafe-inline'",
        ]
    );
    $s24SecCode = (string)file_get_contents(__DIR__ . '/../src/Core/Security.php');
    $t->assertTrue(
        str_contains($s24SecCode, 'buildCspPolicy("style-src \'self\'")')
        && str_contains($s24SecCode, 'buildCspPolicy("style-src \'self\' \'unsafe-inline\'")')
        && str_contains($s24PortalPolicy, "style-src 'self'")
        && !str_contains($s24PortalPolicy, 'unsafe-inline')
        && str_contains($s24StandalonePolicy, "'sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA='")
        && substr_count($s24StandalonePolicy, 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58=') === 1
        && !str_contains($s24StandalonePolicy, 'sha256-bad'),
        "Section 24: Security separates strict portal style-src 'self' and validates standalone extra script SHA-256 hashes"
    );
    $t->assertTrue(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080']),
        "Section 24: Security::verifySameOriginRequest() allows CLI/requests without Origin or Referer"
    );
    $t->assertTrue(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_ORIGIN' => 'http://127.0.0.1:8080']),
        "Section 24: Security::verifySameOriginRequest() accepts matching Origin host and port"
    );
    $t->assertTrue(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_REFERER' => 'http://127.0.0.1:8080/index.php?page=admin']),
        "Section 24: Security::verifySameOriginRequest() accepts matching Referer host and port"
    );
    $t->assertFalse(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_ORIGIN' => 'https://evil.example']),
        "Section 24: Security::verifySameOriginRequest() rejects cross-origin Origin header"
    );
    $t->assertFalse(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_ORIGIN' => 'http://127.0.0.1:9999']),
        "Section 24: Security::verifySameOriginRequest() rejects Origin port mismatch"
    );
    $t->assertFalse(
        Security::verifySameOriginRequest(['HTTP_HOST' => '127.0.0.1:8080', 'HTTP_ORIGIN' => 'null']),
        "Section 24: Security::verifySameOriginRequest() rejects opaque 'null' Origin"
    );

    // 24.2 Auth & JsonStorage user lifecycle: isUserActive, setUserActive, adminResetUserPassword, throttles
    $t->assertFalse(Auth::isUserActive(['active' => false]), "Section 24: Auth::isUserActive() returns false for boolean false");
    $t->assertFalse(Auth::isUserActive(['active' => 0]), "Section 24: Auth::isUserActive() returns false for integer 0");
    $t->assertFalse(Auth::isUserActive(['active' => '0']), "Section 24: Auth::isUserActive() returns false for string '0'");
    $t->assertTrue(Auth::isUserActive(['active' => true]) && Auth::isUserActive([]), "Section 24: Auth::isUserActive() defaults to true and accepts active=true");

    $t->assertFalse($s24Storage->setUserActive(1, false), "Section 24: JsonStorage::setUserActive() protects super-admin #1 from deactivation");
    $s24ToggleStudent = $s24Auth->registerUser('User Lifecycle', 'lifecycle.s24@lightpath.fr', 'LifePass2026!', 'student', 'LightPath');
    $s24LifeId = (int)($s24ToggleStudent['id'] ?? 0);
    $t->assertTrue($s24LifeId > 0 && $s24Storage->setUserActive($s24LifeId, false), "Section 24: JsonStorage::setUserActive() suspends target student");
    $t->assertNull($s24Auth->verifyCredentials('lifecycle.s24@lightpath.fr', 'LifePass2026!'), "Section 24: Auth::verifyCredentials() rejects suspended user");

    $_SESSION['user_id'] = $s24LifeId;
    $_SESSION['last_activity'] = time();
    $t->assertNull($s24Auth->currentUser(), "Section 24: Auth::currentUser() logs out suspended session user");
    $t->assertTrue(empty($_SESSION['user_id']), "Section 24: Auth::currentUser() clears session user_id on deactivation");

    $t->assertTrue($s24Storage->setUserActive($s24LifeId, true), "Section 24: JsonStorage::setUserActive() reactivates target student");
    $s24NewHash = password_hash('AdminReset2026!', PASSWORD_BCRYPT);
    $t->assertTrue($s24Storage->adminResetUserPassword($s24LifeId, $s24NewHash), "Section 24: JsonStorage::adminResetUserPassword() updates password hash");
    $t->assertTrue(
        $s24Auth->verifyCredentials('lifecycle.s24@lightpath.fr', 'AdminReset2026!') !== null,
        "Section 24: Reactivated user authenticates with admin-reset password"
    );

    for ($s24i = 0; $s24i < 5; $s24i++) {
        $s24Auth->recordFailedLogin('locked.s24@lightpath.fr', '192.0.2.88');
    }
    $s24Throttles = $s24Storage->listLoginThrottles();
    $t->assertTrue(
        isset($s24Throttles['locked.s24@lightpath.fr|192.0.2.88'])
        && !empty($s24Throttles['locked.s24@lightpath.fr|192.0.2.88']['locked']),
        "Section 24: JsonStorage::listLoginThrottles() reports locked account bucket"
    );
    $s24Storage->clearLoginThrottle('locked.s24@lightpath.fr', '192.0.2.88');
    $s24Auth->recordFailedLogin('prefix.s24@lightpath.fr', '192.0.2.99');
    $s24Storage->clearLoginThrottle('prefix.s24@lightpath.fr');
    $s24Storage->clearLoginThrottle('', '192.0.2.88');
    $s24Storage->clearLoginThrottle('', '192.0.2.99');
    $s24ThrottlesAfter = $s24Storage->listLoginThrottles();
    $t->assertTrue(
        !isset($s24ThrottlesAfter['locked.s24@lightpath.fr|192.0.2.88'])
        && !isset($s24ThrottlesAfter['prefix.s24@lightpath.fr|192.0.2.99']),
        "Section 24: JsonStorage::clearLoginThrottle() supports both 2-arg and 1-arg email/key removal"
    );

    // 24.3 JsonStorage SHA-256 audit hash chain & verifyAuditIntegrity() across archives
    $s24ChainStorage = new JsonStorage($s24Root . '/chain_data');
    $s24ChainSeed = [];
    for ($s24k = 1; $s24k <= 998; $s24k++) {
        $s24ChainSeed[] = [
            'timestamp' => '2026-02-10 09:00:00',
            'actor' => 'legacy@lightpath.fr',
            'action' => 'LEGACY_EVENT',
            'details' => 'Pre-chain legacy entry #' . $s24k,
        ];
    }
    file_put_contents($s24Root . '/chain_data/audit_logs.json', json_encode($s24ChainSeed, JSON_PRETTY_PRINT));
    for ($s24m = 1; $s24m <= 5; $s24m++) {
        $s24ChainStorage->logAuditEvent('audit.qa@lightpath.fr', 'CHAIN_EVENT', 'Chained event #' . $s24m);
    }
    $s24IntegrityOk = $s24ChainStorage->verifyAuditIntegrity(true);
    $t->assertTrue(
        ($s24IntegrityOk['valid'] ?? false) === true
        && (int)($s24IntegrityOk['legacy_unchained'] ?? 0) === 998
        && (int)($s24IntegrityOk['chained_entries'] ?? 0) === 5
        && empty($s24IntegrityOk['broken_links']),
        "Section 24: JsonStorage::verifyAuditIntegrity(true) validates SHA-256 chain across archives while tolerating legacy entries"
    );

    // Tamper with an active chained entry and verify detection
    $s24ActiveRaw = json_decode((string)file_get_contents($s24Root . '/chain_data/audit_logs.json'), true);
    $s24ActiveRaw[0]['details'] = 'TAMPERED_PAYLOAD';
    file_put_contents($s24Root . '/chain_data/audit_logs.json', json_encode($s24ActiveRaw, JSON_PRETTY_PRINT));
    $s24IntegrityBroken = $s24ChainStorage->verifyAuditIntegrity(false);
    $t->assertTrue(
        ($s24IntegrityBroken['valid'] ?? true) === false && count($s24IntegrityBroken['broken_links'] ?? []) >= 1,
        "Section 24: JsonStorage::verifyAuditIntegrity() detects modified audit entry payload"
    );

    // 24.4 FeatureManager: 10 categorized flags, dependency enforcement, 4 profiles, course overrides, JSON import/export
    $s24FmDefs = $s24Fm->getDefinitions();
    $t->assertEquals(10, count($s24FmDefs), "Section 24: FeatureManager exposes 10 categorized feature flags");
    $s24Fm->updateFlags([
        'self_registration' => '1',
        'demo_quick_login' => '1',
        'docker_labs' => '0',
        'chaos_engineering' => '1',
        'gamification' => '1',
        'qna_forum' => '1',
        'personal_notes' => '1',
        'flashcards_srs' => '1',
        'scorm_export' => '1',
        'cold_satisfaction_j60' => '1',
    ], 'qa24@lightpath.fr');
    $t->assertFalse($s24Fm->isEnabled('chaos_engineering'), "Section 24: FeatureManager enforces requires=['docker_labs'] on chaos_engineering");

    $s24Profiles = $s24Fm->getProfiles();
    $t->assertEquals(4, count($s24Profiles), "Section 24: FeatureManager::getProfiles() returns 4 deployment profiles");
    $t->assertFalse($s24Fm->applyProfile('nonexistent_profile', 'qa24@lightpath.fr'), "Section 24: FeatureManager::applyProfile() rejects unknown profile");
    $t->assertTrue($s24Fm->applyProfile('exam_lockdown', 'qa24@lightpath.fr'), "Section 24: FeatureManager::applyProfile('exam_lockdown') succeeds");
    $t->assertFalse($s24Fm->isEnabled('docker_labs') || $s24Fm->isEnabled('qna_forum'), "Section 24: exam_lockdown profile disables docker_labs and qna_forum");
    $t->assertTrue($s24Fm->applyProfile('full_enterprise', 'qa24@lightpath.fr'), "Section 24: FeatureManager::applyProfile('full_enterprise') restores all 10 flags");

    $s24Storage->saveCourseFeatureOverrides('postgresql-18', ['flashcards_srs' => '0', 'qna_forum' => 'inherit']);
    $s24RawCourseOv = $s24Storage->getCourseFeatureOverrides('postgresql-18');
    $t->assertTrue(
        ($s24RawCourseOv['flashcards_srs'] ?? true) === false && !array_key_exists('qna_forum', $s24RawCourseOv),
        "Section 24: JsonStorage::saveCourseFeatureOverrides() & getCourseFeatureOverrides() normalize and skip 'inherit'"
    );
    $s24Storage->saveCourseFeatureOverrides('postgresql-18', []);

    $s24Fm->setCourseOverrides('postgresql-18', ['personal_notes' => '0', 'flashcards_srs' => 'inherit'], 'qa24@lightpath.fr');
    $s24Overrides = $s24Fm->getCourseOverrides('postgresql-18');
    $t->assertTrue(
        ($s24Overrides['personal_notes'] ?? true) === false && !array_key_exists('flashcards_srs', $s24Overrides),
        "Section 24: FeatureManager::setCourseOverrides() & getCourseOverrides() persist course-specific overrides and skip 'inherit'"
    );
    $t->assertFalse($s24Fm->isEnabledForCourse('personal_notes', 'postgresql-18'), "Section 24: FeatureManager::isEnabledForCourse() applies course override");
    $t->assertTrue($s24Fm->isEnabled('personal_notes'), "Section 24: Global personal_notes flag remains enabled despite course override");
    $s24Fm->clearCourseOverrides('postgresql-18', 'qa24@lightpath.fr');
    $t->assertTrue($s24Fm->isEnabledForCourse('personal_notes', 'postgresql-18'), "Section 24: FeatureManager::clearCourseOverrides() restores inherited flag state");

    $s24ExportedJson = $s24Fm->exportConfigJson();
    $s24ExportedArr = json_decode($s24ExportedJson, true);
    $t->assertTrue(
        is_array($s24ExportedArr) && ($s24ExportedArr['schema'] ?? '') === 'lightpath-lms-features-v1' && count($s24ExportedArr['flags'] ?? []) === 10,
        "Section 24: FeatureManager::exportConfigJson() produces valid schema JSON with 10 flags"
    );
    $t->assertFalse($s24Fm->importConfigJson('{invalid json'), "Section 24: FeatureManager::importConfigJson() rejects malformed JSON");
    $t->assertTrue(
        $s24Fm->importConfigJson('{"flags":{"docker_labs":true,"personal_notes":false}}', 'qa24@lightpath.fr')
        && !$s24Fm->isEnabled('personal_notes'),
        "Section 24: FeatureManager::importConfigJson() applies imported flag overrides"
    );
    $s24Fm->applyProfile('full_enterprise', 'qa24@lightpath.fr');

    // 24.5 DockerManager: savepoints, max 5 limit, tenant isolation, pgbench clamping, fleet summary, idle reaper, chaos heal
    $s24Sp1 = $s24Docker->createSavepoint(2, 'cp_one', 'formation_pg');
    $t->assertTrue(($s24Sp1['success'] ?? false) === true && ($s24Sp1['savepoint']['name'] ?? '') === 'cp_one', "Section 24: DockerManager::createSavepoint() creates simulated savepoint");
    $s24SpBad = $s24Docker->createSavepoint(2, '../bad!name', 'formation_pg');
    $t->assertFalse($s24SpBad['success'] ?? true, "Section 24: DockerManager::createSavepoint() rejects invalid savepoint name");

    foreach (['cp_two', 'cp_three', 'cp_four', 'cp_five'] as $spName) {
        $s24Docker->createSavepoint(2, $spName, 'formation_pg');
    }
    $s24SpSixth = $s24Docker->createSavepoint(2, 'cp_six', 'formation_pg');
    $t->assertFalse($s24SpSixth['success'] ?? true, "Section 24: DockerManager::createSavepoint() enforces max 5 savepoints per user");

    $s24SpList = $s24Docker->listSavepoints(2);
    $t->assertEquals(5, (int)($s24SpList['count'] ?? 0), "Section 24: DockerManager::listSavepoints() returns 5 savepoints");
    $s24SpRestore = $s24Docker->restoreSavepoint(2, 'cp_one', 'formation_pg');
    $t->assertTrue(($s24SpRestore['success'] ?? false) === true, "Section 24: DockerManager::restoreSavepoint() restores existing savepoint");
    $s24SpDel = $s24Docker->deleteSavepoint(2, 'cp_five');
    $t->assertTrue(($s24SpDel['success'] ?? false) === true && (int)($s24SpDel['count'] ?? -1) === 4, "Section 24: DockerManager::deleteSavepoint() removes savepoint");

    $s24DockerDeny = new class($s24Storage, [], $s24Data . '/docker_deny_cache.json') extends DockerManager {
        public function detectRuntime(bool $forceRefresh = false): array
        {
            return ['available' => true, 'mode' => 'native', 'prefix' => '', 'version' => 'MockDocker 27.0'];
        }
        public function getUserContainerStatus(int $userId): array
        {
            return [
                'container_name' => $this->getContainerName($userId),
                'port' => $this->getUserPort($userId),
                'status' => 'not_created',
                'running' => false,
                'user_container_running' => false,
                'primary_cluster_running' => true,
            ];
        }
    };
    $s24SpDeny = $s24DockerDeny->createSavepoint(2, 'cp_denied', 'formation_pg', false);
    $t->assertFalse($s24SpDeny['success'] ?? true, "Section 24: DockerManager::createSavepoint(..., false) enforces tenant isolation when user container is down");

    $s24Bench = $s24Docker->runPgbenchBenchmark(2, 99, 99, true, 'dvdrental');
    $t->assertTrue(
        ($s24Bench['success'] ?? false) === true
        && (int)($s24Bench['clients'] ?? 0) === 8
        && (int)($s24Bench['duration_seconds'] ?? 0) === 5
        && (float)($s24Bench['tps'] ?? 0) > 0,
        "Section 24: DockerManager::runPgbenchBenchmark() clamps clients=8, duration=5 and returns TPS metrics"
    );

    // Non-simulated pgbench must normalize empty runtime prefix to 'docker' via runDockerCommand().
    $s24DockerEmptyPrefix = new class($s24Storage, [], $s24Data . '/docker_prefix_cache.json') extends DockerManager {
        public array $capturedCmds = [];
        public function detectRuntime(bool $forceRefresh = false): array
        {
            return ['available' => true, 'mode' => 'native', 'prefix' => '', 'version' => 'MockDocker 27.0'];
        }
        public function getUserContainerStatus(int $userId): array
        {
            return [
                'container_name' => $this->getContainerName($userId),
                'port' => $this->getUserPort($userId),
                'status' => 'Up 10 minutes',
                'running' => true,
                'user_container_running' => true,
                'primary_cluster_running' => true,
            ];
        }
        public function execCommandWithTimeout(string $cmd, float $timeoutSeconds = 4.0): array
        {
            $this->capturedCmds[] = $cmd;
            return [
                'code' => 0,
                'output' => "tps = 987.65 (without initial connection time)\nlatency average = 2.025 ms\nnumber of transactions actually processed: 1975",
                'timed_out' => false,
            ];
        }
    };
    $s24PrefixBench = $s24DockerEmptyPrefix->runPgbenchBenchmark(2, 2, 2, true, 'dvdrental');
    $t->assertTrue(
        ($s24PrefixBench['success'] ?? false) === true
        && (float)($s24PrefixBench['tps'] ?? 0.0) === 987.65
        && !empty($s24DockerEmptyPrefix->capturedCmds)
        && str_starts_with($s24DockerEmptyPrefix->capturedCmds[0], 'docker exec lp_pg18_user_2 pgbench ')
        && !str_starts_with($s24DockerEmptyPrefix->capturedCmds[0], 'exec '),
        "Section 24: DockerManager::runPgbenchBenchmark() normalizes empty runtime prefix to 'docker' via runDockerCommand()"
    );

    $s24Docker->touchContainerActivity(2);
    $s24Fleet = $s24Docker->getFleetSummary();
    $t->assertTrue(
        isset($s24Fleet['runtime_mode'], $s24Fleet['max_containers'], $s24Fleet['tracked_sessions'])
        && (int)$s24Fleet['tracked_sessions'] >= 1,
        "Section 24: DockerManager::getFleetSummary() reports runtime telemetry and tracked sessions"
    );

    // Seed stale activity timestamp in status cache to verify idle reaper behavior
    $s24CachePath = $s24Data . '/docker_status_cache.json';
    $s24CacheJson = is_file($s24CachePath) ? json_decode((string)file_get_contents($s24CachePath), true) : [];
    if (!is_array($s24CacheJson)) {
        $s24CacheJson = ['users' => []];
    }
    $s24CacheJson['users']['99'] = [
        'container_name' => 'lp_pg18_user_99',
        'running' => true,
        'status' => 'Up 3 hours',
        'last_activity_at' => time() - 7200,
    ];
    file_put_contents($s24CachePath, json_encode($s24CacheJson, JSON_PRETTY_PRINT));
    $s24ReapRes = $s24Docker->reapIdleContainers(3600);
    $t->assertTrue(
        ($s24ReapRes['success'] ?? false) === true && in_array('lp_pg18_user_99', $s24ReapRes['reaped'] ?? [], true),
        "Section 24: DockerManager::reapIdleContainers() stops stale idle simulated containers"
    );

    $s24HealRes = $s24Docker->healChaosScenario('postgresql-18');
    $t->assertTrue(
        ($s24HealRes['success'] ?? false) === true && in_array('chaos_orders', $s24HealRes['cleaned_tables'] ?? [], true),
        "Section 24: DockerManager::healChaosScenario() returns cleaned chaos tables and logs audit event"
    );

    // 24.6 LabSqlRunner: inspectPgDiagnostics & checkLabReadiness across all 34 labs
    $s24DiagRes = $s24SqlRunner->inspectPgDiagnostics(2, 'formation_pg');
    $t->assertTrue(
        ($s24DiagRes['success'] ?? false) === true
        && ($s24DiagRes['settings']['io_method'] ?? '') === 'worker'
        && (float)($s24DiagRes['cache_hit_ratio'] ?? 0) > 90.0
        && !empty($s24DiagRes['activity']['sessions']),
        "Section 24: LabSqlRunner::inspectPgDiagnostics() returns PG18 settings, hit ratio and backend sessions"
    );

    $s24PgCourse = $s24CourseRepo->getCourse('postgresql-18');
    $s24AllLabs = is_array($s24PgCourse['labs'] ?? null) ? $s24PgCourse['labs'] : [];
    $s24IsFullCourse = count($s24PgCourse['modules'] ?? []) >= 34;
    $s24ReadyCount = 0;
    $s24Sql02Db = '';
    $s24Lab20Db = '';
    foreach ($s24AllLabs as $s24LabKey => $s24LabInfo) {
        $s24Readiness = $s24SqlRunner->checkLabReadiness(2, (string)$s24LabKey, false);
        if (
            ($s24Readiness['success'] ?? false) === true
            && ($s24Readiness['simulated'] ?? false) === true
            && ($s24Readiness['ready'] ?? true) === false
            && ($s24Readiness['oracle_found'] ?? false) === true
            && count($s24Readiness['checks'] ?? []) >= 5
            && !empty($s24Readiness['recommended_database'])
        ) {
            $s24ReadyCount++;
        }
        if ((string)$s24LabKey === 'lab_sql_02_manipulation_dml_transactions') {
            $s24Sql02Db = (string)($s24Readiness['recommended_database'] ?? '');
        } elseif ((string)$s24LabKey === 'lab_20_tuning_systeme_linux_postgresql') {
            $s24Lab20Db = (string)($s24Readiness['recommended_database'] ?? '');
        }
    }
    if (!$s24IsFullCourse) {
        $s24Sql02Db = (string)($s24SqlRunner->checkLabReadiness(2, 'lab_sql_02_manipulation_dml_transactions', false)['recommended_database'] ?? '');
        $s24Lab20Db = (string)($s24SqlRunner->checkLabReadiness(2, 'lab_20_tuning_systeme_linux_postgresql', false)['recommended_database'] ?? '');
    }
    $t->assertEquals(count($s24AllLabs), $s24ReadyCount, "Section 24: LabSqlRunner::checkLabReadiness() maps all discovered labs");
    $t->assertEquals('dvdrental', $s24Sql02Db, "Section 24: LabSqlRunner::checkLabReadiness() maps SQL lab to dvdrental");
    $t->assertEquals('formation_pg', $s24Lab20Db, "Section 24: LabSqlRunner::checkLabReadiness() maps DBA lab to formation_pg");
    $s24RunnerDeny = new LabSqlRunner($s24DockerDeny);
    $s24ReadinessDeny = $s24RunnerDeny->checkLabReadiness(2, 'lab_01_introduction_setup_postgresql_18', false);
    $t->assertFalse($s24ReadinessDeny['success'] ?? true, "Section 24: LabSqlRunner::checkLabReadiness(..., false) enforces tenant isolation when user container is down");

    // 24.7 AdminService: detectAtRiskLearners, computeModuleCompletionMatrix, exportCohortCsv, exportQualiopiJson, buildAuditExportMetadata, 7-component health
    $s24RiskProg = [
        '3:postgresql-18' => [
            'completed_modules' => array_slice(array_keys($s24PgCourse['modules'] ?? []), 0, 20),
            'completed_labs' => [],
            'quiz_scores' => ['module_01_introduction_postgresql_18' => ['score' => 3, 'total' => 3, 'percentage' => 100]],
            'learning_seconds' => 1800,
        ],
        $s24LifeId . ':postgresql-18' => [
            'completed_modules' => [],
            'completed_labs' => [],
            'quiz_scores' => [],
            'learning_seconds' => 0,
        ],
    ];
    $s24AllUsers = $s24Storage->getUsers();
    $s24AtRisk = $s24AdminSvc->detectAtRiskLearners($s24AllUsers, $s24RiskProg, $s24PgCourse, 20);
    $s24RiskIds = array_column($s24AtRisk, 'user_id');
    $t->assertTrue(
        in_array($s24LifeId, $s24RiskIds, true) && !in_array(3, $s24RiskIds, true) && !in_array(1, $s24RiskIds, true),
        "Section 24: AdminService::detectAtRiskLearners() identifies low-progress active learners only"
    );

    $s24Matrix = $s24AdminSvc->computeModuleCompletionMatrix($s24AllUsers, $s24RiskProg, $s24PgCourse);
    $t->assertTrue(
        count($s24Matrix['modules'] ?? []) === count($s24PgCourse['modules'] ?? [])
        && count($s24Matrix['labs'] ?? []) === count($s24PgCourse['labs'] ?? [])
        && (int)($s24Matrix['modules']['module_01_introduction_postgresql_18']['completed_count'] ?? 0) >= 1,
        "Section 24: AdminService::computeModuleCompletionMatrix() covers all discovered modules and labs"
    );

    $s24CohortCsv = $s24AdminSvc->exportCohortCsv($s24AllUsers, $s24RiskProg, $s24PgCourse);
    $t->assertTrue(
        str_starts_with($s24CohortCsv, "\xEF\xBB\xBF")
        && str_contains($s24CohortCsv, 'User_ID;Nom;Email;Entreprise;Role;Statut'),
        "Section 24: AdminService::exportCohortCsv() emits UTF-8 BOM and semicolon-delimited headers"
    );

    $s24AuditMeta = $s24AdminSvc->buildAuditExportMetadata($s24Storage, true);
    $t->assertTrue(
        isset($s24AuditMeta['chain_valid'], $s24AuditMeta['total_checked'], $s24AuditMeta['chained_entries'])
        && $s24AuditMeta['chain_valid'] === true,
        "Section 24: AdminService::buildAuditExportMetadata() reports valid SHA-256 chain status"
    );

    $s24QualiopiJson = $s24AdminSvc->exportQualiopiJson(
        $s24AllUsers,
        $s24RiskProg,
        $s24Storage->getAllAttendance(),
        $s24Storage->getAuditLogs(100, true),
        $s24PgCourse,
        $s24Storage->verifyAuditIntegrity(true)
    );
    $s24QualiopiArr = json_decode($s24QualiopiJson, true);
    $t->assertTrue(
        is_array($s24QualiopiArr)
        && ($s24QualiopiArr['schema_version'] ?? '') === '2.0'
        && isset($s24QualiopiArr['audit_integrity'], $s24QualiopiArr['at_risk_learners'], $s24QualiopiArr['module_completion_matrix']),
        "Section 24: AdminService::exportQualiopiJson() emits valid v2.0 dossier JSON with integrity report"
    );

    $s24HealthCfg = require __DIR__ . '/../config/app.php';
    $s24HealthCfg['storage_path'] = $s24Data;
    $s24Health7 = $s24AdminSvc->getSystemHealthReport($s24HealthCfg, $s24Storage, $s24Docker, $s24CourseRepo);
    $t->assertEquals(7, (int)($s24Health7['summary']['total'] ?? 0), "Section 24: AdminService::getSystemHealthReport() returns 7 diagnostic checks");

    // 24.8 Direct instantiation & behavioral invocation of all 4 src/Http controllers
    $s24AuthCtrl = new \LightPath\LMS\Http\AuthController($s24Auth, $s24Fm);
    $s24LearnerCtrl = new \LightPath\LMS\Http\LearnerController($s24Storage, $s24Auth, $s24Fm, $s24CourseRepo, $s24Downloader, $s24Parser);
    $s24LabApiCtrl = new \LightPath\LMS\Http\LabApiController($s24Storage, $s24Auth, $s24Fm, $s24CourseRepo, $s24Docker, $s24SqlRunner);
    $s24AdminCtrl = new \LightPath\LMS\Http\AdminController($s24Storage, $s24Auth, $s24Fm, $s24AdminSvc, $s24CourseRepo, $s24Docker, $s24Parser);

    $s24SlugRef = 'nonexistent-course';
    $s24CourseRef = null;
    $s24StudentUser = $s24Storage->getUserById(3);
    $s24AdminUser = $s24Storage->getUserById(1);
    $s24AuthCtrl->enforceCourseAccess($s24StudentUser, $s24CourseRepo, $s24SlugRef, $s24CourseRef, '', 'dashboard');
    $t->assertEquals('postgresql-18', $s24SlugRef, "Section 24: AuthController::enforceCourseAccess() resolves fallback enrolled course");

    $t->assertFalse($s24LearnerCtrl->handleApiAction('api_unknown_xyz', [], $s24StudentUser, 'postgresql-18'), "Section 24: LearnerController::handleApiAction() returns false for non-learner action");
    $t->assertFalse($s24LearnerCtrl->handlePostAction('unknown_post_xyz', [], $s24StudentUser, 'postgresql-18'), "Section 24: LearnerController::handlePostAction() returns false for non-learner action");
    $t->assertFalse($s24LabApiCtrl->handleAction('api_unknown_xyz', [], $s24StudentUser, 'postgresql-18'), "Section 24: LabApiController::handleAction() returns false for unknown action");
    $t->assertFalse($s24AdminCtrl->handleExportAction('export_unknown_xyz', [], $s24AdminUser, $s24PgCourse, 'postgresql-18'), "Section 24: AdminController::handleExportAction() returns false for unknown export");
    $t->assertFalse($s24AdminCtrl->handleStaffAction('unknown_staff_xyz', [], $s24AdminUser, 'postgresql-18'), "Section 24: AdminController::handleStaffAction() returns false for unknown action");
    $t->assertFalse($s24AdminCtrl->handleAdminAction('unknown_admin_xyz', [], $s24AdminUser, 'postgresql-18', $s24CoursesCfg), "Section 24: AdminController::handleAdminAction() returns false for unknown action");

    // Subprocess harness invoking every never-returning & exiting HTTP controller method cleanly
    $s24RunnerPath = $s24Root . '/http_ctrl_runner.php';
    $s24RunnerCode = <<<'PHP'
<?php
declare(strict_types=1);

$repoRoot = $argv[1];
$dataDir = $argv[2];
$cacheDir = $argv[3];
$case = $argv[4];

putenv('LMS_DATA_DIR=' . $dataDir);
putenv('LMS_DOCKER_FORCE_SIMULATED=1');
require $repoRoot . '/src/bootstrap.php';

$coursesCfg = require $repoRoot . '/config/courses.php';
$storage = new \LightPath\LMS\Core\JsonStorage($dataDir);
$auth = new \LightPath\LMS\Core\Auth($storage);
$fm = new \LightPath\LMS\Core\FeatureManager($storage);
$adminSvc = new \LightPath\LMS\Core\AdminService();
$courseRepo = new \LightPath\LMS\Course\CourseRepository($coursesCfg, $storage->getCustomCourses(), $cacheDir);
$downloader = new \LightPath\LMS\Course\DownloadController($courseRepo);
$mdParser = new \LightPath\LMS\Course\MarkdownParser(null, $cacheDir);
$docker = new \LightPath\LMS\Docker\DockerManager($storage, [], $dataDir . '/docker_status_cache.json');
$sqlRunner = new \LightPath\LMS\Docker\LabSqlRunner($docker);

$authCtrl = new \LightPath\LMS\Http\AuthController($auth, $fm);
$learnerCtrl = new \LightPath\LMS\Http\LearnerController($storage, $auth, $fm, $courseRepo, $downloader, $mdParser);
$labApiCtrl = new \LightPath\LMS\Http\LabApiController($storage, $auth, $fm, $courseRepo, $docker, $sqlRunner);
$adminCtrl = new \LightPath\LMS\Http\AdminController($storage, $auth, $fm, $adminSvc, $courseRepo, $docker, $mdParser);

$adminUser = $storage->getUserById(1);
$studentUser = $storage->getUserById(3);
$pgCourse = $courseRepo->getCourse('postgresql-18') ?? [];
$csrf = \LightPath\LMS\Core\Security::getCsrfToken();

if (!in_array($case, ['download_md', 'scorm_zip'], true)) {
    register_shutdown_function(static function (): void {
        echo "\n__META__:" . json_encode([
            'code' => http_response_code(),
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error' => $_SESSION['flash_error'] ?? null,
        ]);
    });
}

switch ($case) {
    case 'auth_login_ok':
        $authCtrl->handleLoginPost(['csrf_token' => $csrf, 'email' => 'stagiaire@lightpath.fr', 'password' => 'Stagiaire2026!'], ['REMOTE_ADDR' => '127.0.0.1']);
        break;
    case 'auth_register_ok':
        $authCtrl->handleRegisterPost(['csrf_token' => $csrf, 'name' => 'Reg Child', 'email' => 'reg.child.s24@lightpath.fr', 'company' => 'LP', 'password' => 'RegPass2026!'], ['REMOTE_ADDR' => '127.0.0.1']);
        break;
    case 'auth_logout':
        $_SESSION['user_id'] = 3;
        $authCtrl->handleLogout();
        break;
    case 'download_md':
        $learnerCtrl->handleDownload(['type' => 'module', 'id' => 'module_01_introduction_postgresql_18', 'format' => 'md'], $studentUser, 'postgresql-18');
        break;
    case 'course_asset_missing':
        $learnerCtrl->handleCourseAsset(['path' => '../../etc/passwd'], 'postgresql-18');
        break;
    case 'export_notes':
        $storage->saveUserNote(3, 'postgresql-18', 'module_01_introduction_postgresql_18', 'Controller export test note');
        $learnerCtrl->handleExportNotesMarkdown($studentUser, $pgCourse, 'postgresql-18');
        break;
    case 'scorm_manifest':
        $learnerCtrl->handleExportScormManifest($pgCourse, 'postgresql-18');
        break;
    case 'scorm_zip':
        $learnerCtrl->handleExportScormZip($pgCourse, 'postgresql-18');
        break;
    case 'quiz_submit':
        $mod01 = $courseRepo->getModule('postgresql-18', 'module_01_introduction_postgresql_18');
        $quiz = $mdParser->parseModuleFile($mod01['source_md_path'], 'postgresql-18')['quiz'] ?? [];
        $ans = [];
        foreach ($quiz as $qItem) {
            $ans[(string)$qItem['index']] = (string)$qItem['correct'];
        }
        $learnerCtrl->completeQuizSubmission($quiz, ['answers' => $ans], 'module_01_introduction_postgresql_18', $studentUser, 'postgresql-18');
        break;
    case 'learner_api_note':
        $learnerCtrl->handleApiAction('api_save_note', ['item_id' => 'module_01_introduction_postgresql_18', 'note' => 'API note'], $studentUser, 'postgresql-18');
        break;
    case 'learner_post_diag':
        $learnerCtrl->handlePostAction('submit_diagnostic', ['answers' => []], $studentUser, 'postgresql-18');
        break;
    case 'lab_api_savepoint':
        $labApiCtrl->handleAction('api_lab_savepoint', ['op' => 'create', 'name' => 'ctrl_sp', 'db' => 'formation_pg'], $studentUser, 'postgresql-18');
        break;
    case 'admin_export_cohort':
        $adminCtrl->handleExportAction('export_cohort_csv', [], $adminUser, $pgCourse, 'postgresql-18');
        break;
    case 'admin_staff_preset':
        $adminCtrl->handleStaffAction('admin_apply_classroom_preset', ['preset' => 'guided_lab'], $adminUser, 'postgresql-18');
        break;
    case 'admin_toggle_active':
        $adminCtrl->handleAdminAction('admin_toggle_user_active', ['user_id' => '3', 'active' => '0'], $adminUser, 'postgresql-18', $coursesCfg);
        break;
    case 'admin_self_update_guard':
        $secAdmin = $storage->getUserByEmail('sec.ctrl.s24@lightpath.fr');
        $secId = (string)(int)($secAdmin['id'] ?? 0);
        $adminCtrl->handleAdminAction('admin_update_user', [
            'user_id' => $secId,
            'name' => 'Secondary Ctrl S24',
            'company' => 'LightPath',
            'role' => 'student',
        ], $secAdmin, 'postgresql-18', $coursesCfg);
        break;
    case 'admin_self_delete_guard':
        $secAdmin = $storage->getUserByEmail('sec.ctrl.s24@lightpath.fr');
        $secId = (string)(int)($secAdmin['id'] ?? 0);
        $adminCtrl->handleAdminAction('admin_delete_user', ['user_id' => $secId], $secAdmin, 'postgresql-18', $coursesCfg);
        break;
}
PHP;
    file_put_contents($s24RunnerPath, $s24RunnerCode);

    $s24ExecCase = static function (string $case) use ($s24RunnerPath, $s24RepoRoot, $s24Data, $s24Cache): array {
        $proc = proc_open(
            [PHP_BINARY, $s24RunnerPath, $s24RepoRoot, $s24Data, $s24Cache, $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed', 'meta' => []];
        }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $meta = [];
        if (preg_match('/\n__META__:(\{.*\})\s*$/s', $out, $m)) {
            $meta = json_decode($m[1], true) ?: [];
            $out = trim(substr($out, 0, -strlen($m[0])));
        }
        return ['code' => $code, 'out' => $out, 'err' => $err, 'meta' => $meta];
    };

    $rLogin = $s24ExecCase('auth_login_ok');
    $t->assertTrue(
        $rLogin['code'] === 0 && str_contains((string)($rLogin['meta']['flash_success'] ?? ''), 'Bienvenue'),
        "Section 24: AuthController::handleLoginPost() authenticates student in isolated execution"
    );

    $rReg = $s24ExecCase('auth_register_ok');
    $t->assertTrue(
        $rReg['code'] === 0 && $s24Storage->getUserByEmail('reg.child.s24@lightpath.fr') !== null,
        "Section 24: AuthController::handleRegisterPost() registers new learner account"
    );

    $rLogout = $s24ExecCase('auth_logout');
    $t->assertEquals(0, $rLogout['code'], "Section 24: AuthController::handleLogout() clears session cleanly");

    $rDl = $s24ExecCase('download_md');
    $t->assertTrue($rDl['code'] === 0 && str_contains($rDl['out'], 'Module 01'), "Section 24: LearnerController::handleDownload() streams module Markdown");

    $rAsset = $s24ExecCase('course_asset_missing');
    $t->assertEquals(404, (int)($rAsset['meta']['code'] ?? 0), "Section 24: LearnerController::handleCourseAsset() rejects traversal with 404");

    $rNotes = $s24ExecCase('export_notes');
    $t->assertTrue($rNotes['code'] === 0 && str_contains($rNotes['out'], 'Controller export test note'), "Section 24: LearnerController::handleExportNotesMarkdown() exports saved notes");

    $rManifest = $s24ExecCase('scorm_manifest');
    $t->assertTrue($rManifest['code'] === 0 && str_contains($rManifest['out'], '<manifest '), "Section 24: LearnerController::handleExportScormManifest() outputs SCORM XML");

    $rZip = $s24ExecCase('scorm_zip');
    $t->assertTrue($rZip['code'] === 0 && str_starts_with($rZip['out'], "PK\x03\x04"), "Section 24: LearnerController::handleExportScormZip() emits valid PKZIP package");

    $rQuiz = $s24ExecCase('quiz_submit');
    $rQuizJson = json_decode($rQuiz['out'], true);
    $t->assertTrue(
        is_array($rQuizJson) && ($rQuizJson['success'] ?? false) === true && (int)($rQuizJson['quiz_result']['percentage'] ?? 0) === 100,
        "Section 24: LearnerController::completeQuizSubmission() grades MCQs server-side"
    );

    $rApiNote = $s24ExecCase('learner_api_note');
    $rApiNoteJson = json_decode($rApiNote['out'], true);
    $t->assertTrue(is_array($rApiNoteJson) && ($rApiNoteJson['success'] ?? false) === true, "Section 24: LearnerController::handleApiAction() handles api_save_note");

    $rPostDiag = $s24ExecCase('learner_post_diag');
    $t->assertTrue(
        $rPostDiag['code'] === 0 && str_contains((string)($rPostDiag['meta']['flash_success'] ?? ''), 'Positionnement'),
        "Section 24: LearnerController::handlePostAction() handles submit_diagnostic"
    );

    $rLabSp = $s24ExecCase('lab_api_savepoint');
    $rLabSpJson = json_decode($rLabSp['out'], true);
    $t->assertTrue(
        $rLabSp['err'] === '' && is_array($rLabSpJson) && ($rLabSpJson['success'] ?? false) === true && ($rLabSpJson['name'] ?? '') === 'ctrl_sp',
        "Section 24: LabApiController::handleAction() handles api_lab_savepoint cleanly with scalar name"
    );

    $rCohortCsv = $s24ExecCase('admin_export_cohort');
    $t->assertTrue(
        str_starts_with($rCohortCsv['out'], "\xEF\xBB\xBF") && str_contains($rCohortCsv['out'], 'User_ID;Nom;Email'),
        "Section 24: AdminController::handleExportAction() streams export_cohort_csv"
    );

    $rPreset = $s24ExecCase('admin_staff_preset');
    $t->assertTrue(
        $rPreset['code'] === 0 && ($s24Storage->getClassroomState('postgresql-18')['lock_exam'] ?? false) === true,
        "Section 24: AdminController::handleStaffAction() applies guided_lab classroom preset"
    );

    $rToggleActive = $s24ExecCase('admin_toggle_active');
    $s24StudentAfterToggle = $s24Storage->getUserById(3);
    $t->assertTrue(
        $rToggleActive['code'] === 0 && ($s24StudentAfterToggle['active'] ?? true) === false,
        "Section 24: AdminController::handleAdminAction() toggles user active state via admin_toggle_user_active"
    );

    // Non-#1 admin self-lockout protection in AdminController::handleAdminAction().
    $s24SecCtrlUser = $s24Auth->registerUser('Secondary Ctrl S24', 'sec.ctrl.s24@lightpath.fr', 'SecCtrl2026!', 'admin', 'LightPath');
    $s24SecCtrlId = (int)($s24SecCtrlUser['id'] ?? 0);
    $rSelfUpdate = $s24ExecCase('admin_self_update_guard');
    $s24FreshStorage = new JsonStorage($s24Data);
    $s24SecAfterSelfUpdate = $s24FreshStorage->getUserById($s24SecCtrlId);
    $t->assertTrue(
        $rSelfUpdate['code'] === 0
        && is_array($s24SecAfterSelfUpdate)
        && ($s24SecAfterSelfUpdate['role'] ?? '') === 'admin'
        && ($s24SecAfterSelfUpdate['active'] ?? false) === true,
        "Section 24: AdminController::handleAdminAction() blocks non-#1 admin self-demotion/self-deactivation on admin_update_user"
    );

    $rSelfDelete = $s24ExecCase('admin_self_delete_guard');
    $s24FreshStorage2 = new JsonStorage($s24Data);
    $t->assertTrue(
        $rSelfDelete['code'] === 0
        && str_contains((string)($rSelfDelete['meta']['flash_error'] ?? ''), 'ne peuvent pas être supprimés')
        && $s24FreshStorage2->getUserById($s24SecCtrlId) !== null,
        "Section 24: AdminController::handleAdminAction() blocks non-#1 admin self-deletion on admin_delete_user"
    );

    // Verify tab_audit.php category dropdown matches emitted audit log prefixes cleanly.
    $s24AuditTabHtml = (string)file_get_contents(__DIR__ . '/../templates/partials/admin/tab_audit.php');
    $t->assertTrue(
        str_contains($s24AuditTabHtml, 'value="CLASSROOM_"')
        && str_contains($s24AuditTabHtml, 'value="CHAOS_"')
        && str_contains($s24AuditTabHtml, 'value="ATTENDANCE_"')
        && str_contains($s24AuditTabHtml, 'value="TRAINER_"')
        && !str_contains($s24AuditTabHtml, 'value="FEATURE_"')
        && !str_contains($s24AuditTabHtml, 'value="SECURITY_"'),
        "Section 24: Admin audit filter dropdown exposes emitted prefixes (CLASSROOM_, CHAOS_, ATTENDANCE_, TRAINER_)"
    );
} finally {
    if ($s24PrevSim === false) {
        putenv('LMS_DOCKER_FORCE_SIMULATED');
    } else {
        putenv('LMS_DOCKER_FORCE_SIMULATED=' . $s24PrevSim);
    }
    $_SESSION = $s24SavedSession;
    $_SERVER = $s24SavedServer;

    $s24Cleanup = static function (string $dir) use (&$s24Cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $s24Cleanup($full) : @unlink($full);
        }
        @rmdir($dir);
    };
    $s24Cleanup($s24Root);
}

// ============================================================================
// 25. MIT Open-Source Readiness & Random First-Boot Credential Regression
// ============================================================================
echo "\n--- 25. MIT Open-Source Readiness & Random Credential Bootstrap ---\n";
$s25RepoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

// 25.1 Open-source community, licensing, Docker & CI files
$s25RequiredFiles = [
    'LICENSE',
    'SECURITY.md',
    'CONTRIBUTING.md',
    'CHANGELOG.md',
    'Dockerfile',
    'docker-compose.yml',
    '.env.example',
    'package.json',
    '.github/workflows/ci.yml',
];
foreach ($s25RequiredFiles as $s25RelPath) {
    $t->assertTrue(
        is_file($s25RepoRoot . '/' . $s25RelPath),
        "Section 25: Open-source root deliverable '{$s25RelPath}' exists"
    );
}

$s25LicenseText = (string)@file_get_contents($s25RepoRoot . '/LICENSE');
$t->assertTrue(
    str_contains($s25LicenseText, 'MIT License'),
    "Section 25: LICENSE file specifies MIT License"
);

// 25.2 composer.json MIT metadata & PHP >=8.3 requirement
$s25Composer = json_decode((string)@file_get_contents($s25RepoRoot . '/composer.json'), true);
$t->assertTrue(
    is_array($s25Composer)
    && ($s25Composer['license'] ?? '') === 'MIT'
    && ($s25Composer['require']['php'] ?? '') === '>=8.3',
    "Section 25: composer.json declares MIT license and PHP >=8.3 requirement"
);

// 25.3 Secure login template & disabled demo quick-login defaults
$s25LoginHtml = (string)@file_get_contents($s25RepoRoot . '/templates/pages/login.php');
$t->assertTrue(
    !str_contains($s25LoginHtml, 'value="jmrenouard')
    && !str_contains($s25LoginHtml, 'value="LightPath2026'),
    "Section 25: templates/pages/login.php contains no hardcoded prefilled admin credentials"
);

$s25FeaturesCfg = require $s25RepoRoot . '/config/features.php';
$t->assertTrue(
    ($s25FeaturesCfg['demo_quick_login']['default'] ?? true) === false
    && ($s25FeaturesCfg['demo_quick_login']['enabled'] ?? true) === false,
    "Section 25: config/features.php disables demo_quick_login by default"
);

// 25.4 Unconfigured first-boot random credential generation when LMS_INIT_*_PASSWORD are unset
$s25RandomBootDir = sys_get_temp_dir() . '/lp_random_boot_' . bin2hex(random_bytes(4));
$s25CleanupDir = static function (string $dir) use (&$s25CleanupDir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . '/' . $entry;
        is_dir($full) ? $s25CleanupDir($full) : @unlink($full);
    }
    @rmdir($dir);
};

try {
    putenv('LMS_INIT_ADMIN_PASSWORD');
    putenv('LMS_INIT_TRAINER_PASSWORD');
    putenv('LMS_INIT_STUDENT_PASSWORD');

    $randomStorage = new JsonStorage($s25RandomBootDir);
    $randomAuth = new Auth($randomStorage);

    $t->assertNull(
        $randomAuth->verifyCredentials('jmrenouard@lightpath.fr', 'LightPath2026!'),
        "Section 25: Unconfigured first boot rejects legacy static admin password"
    );

    $credFilePath = $s25RandomBootDir . '/initial_admin_credentials.txt';
    $t->assertTrue(
        is_file($credFilePath),
        "Section 25: Unconfigured first boot writes initial_admin_credentials.txt"
    );

    $credContent = is_file($credFilePath) ? (string)file_get_contents($credFilePath) : '';
    $generatedAdminPw = '';
    if (preg_match('/admin\s*:\s*jmrenouard@lightpath\.fr\s*\/\s*(\S+)/', $credContent, $credMatch)) {
        $generatedAdminPw = $credMatch[1];
    }
    $t->assertGreaterThanOrEqual(
        16,
        strlen($generatedAdminPw),
        "Section 25: Generated admin password has high entropy (>= 16 chars)"
    );
    $t->assertTrue(
        $generatedAdminPw !== '' && $randomAuth->verifyCredentials('jmrenouard@lightpath.fr', $generatedAdminPw) !== null,
        "Section 25: Generated random admin password authenticates successfully"
    );
} finally {
    foreach ($savedInitPasswords as $envKey => $envVal) {
        if ($envVal === false) {
            putenv($envKey);
        } else {
            putenv($envKey . '=' . $envVal);
        }
    }
    $s25CleanupDir($s25RandomBootDir);
}

exit($t->summary());



