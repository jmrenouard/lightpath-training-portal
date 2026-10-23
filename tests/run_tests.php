<?php
declare(strict_types=1);

/**
 * Suite de Tests Automatisés (TDD) — LightPath Training Portal (LMS PHP 8.3)
 * Exécution : php tests/run_tests.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;
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

// Progression & Quiz
$storage->markItemCompleted((int)$newStudent['id'], 'postgresql-18', 'module', 'module_01_introduction_postgresql_18');
$storage->saveQuizScore((int)$newStudent['id'], 'postgresql-18', 'module_01_introduction_postgresql_18', 3, 3);
$storage->saveUserNote((int)$newStudent['id'], 'postgresql-18', 'module_01_introduction_postgresql_18', 'Excellente note sur AIO et FNV-1a.');

$progress = $storage->getUserProgress((int)$newStudent['id'], 'postgresql-18');
$t->assertTrue(in_array('module_01_introduction_postgresql_18', $progress['completed_modules'] ?? [], true), "Module 01 marqué comme terminé pour l'étudiant");
$t->assertEquals(100, $progress['quiz_scores']['module_01_introduction_postgresql_18']['percentage'] ?? 0, "Score du Quiz Module 01 enregistré à 100%");
$t->assertEquals('Excellente note sur AIO et FNV-1a.', $progress['notes']['module_01_introduction_postgresql_18'] ?? '', "Note personnelle sauvegardée");

// 2. Test Sécurité (CSRF & Anti-Path Traversal)
echo "\n--- 2. Tests Sécurité (CSRF, Sanitization & Path Traversal) ---\n";
$token = Security::getCsrfToken();
$t->assertTrue(strlen($token) >= 32, "Jeton CSRF généré avec entropie suffisante");
$t->assertTrue(Security::verifyCsrfToken($token), "Validation du jeton CSRF valide");
$t->assertTrue(!Security::verifyCsrfToken('invalid_token'), "Rejet d'un jeton CSRF falsifié");
$t->assertTrue(!Security::isSafeRelativePath('../../etc/passwd'), "Blocage strict de Path Traversal (../../etc/passwd)");
$t->assertTrue(Security::isSafeRelativePath('03_supports_de_cours/module_01_introduction_postgresql_18.pdf'), "Validation d'un chemin relatif légitime");

// 3. Test Catalogue Multi-Formations & Découverte Exhaustive PostgreSQL 18
echo "\n--- 3. Tests Catalogue Multi-Formations & Ressources PostgreSQL 18 ---\n";
$coursesConfig = require __DIR__ . '/../config/courses.php';
$repo = new CourseRepository($coursesConfig);

$allCourses = $repo->getAllCourses();
$t->assertGreaterThanOrEqual(1, count($allCourses), "Au moins 1 formation active dans le registre multi-formations");

$pgCourse = $repo->getCourse('postgresql-18');
$t->assertTrue($pgCourse !== null, "Formation 'postgresql-18' chargée avec succès");
$t->assertGreaterThanOrEqual(34, count($pgCourse['modules']), "Découverte des 34 modules de cours PostgreSQL 18 (01..29 + sql_01..05)");
$t->assertGreaterThanOrEqual(34, count($pgCourse['labs']), "Découverte des 34 ateliers pratiques (labs 01..29 + lab_sql_01..05)");
$t->assertGreaterThanOrEqual(34, count($pgCourse['cheatsheets']), "Découverte des 34 fiches mémo (cheatsheets)");
$t->assertGreaterThanOrEqual(8, count($pgCourse['referentiel']), "Découverte des documents du référentiel Qualiopi & GUIDE_PARCOURS");
$t->assertGreaterThanOrEqual(10, count($pgCourse['tools']), "Découverte des outils DBA pgscripts");

// Vérification des formats de téléchargement sur le Module 01
$mod01 = $repo->getModule('postgresql-18', 'module_01_introduction_postgresql_18');
$t->assertTrue($mod01 !== null, "Récupération détaillée du Module 01");
$formats = array_keys($mod01['downloads'] ?? []);
foreach (['pdf', 'slides_pdf', 'pptx', 'md', 'html', 'docx'] as $ext) {
    $t->assertTrue(in_array($ext, $formats, true), "Module 01 possède le lien de téléchargement au format '{$ext}'");
}

// Vérification des formats de téléchargement sur le Lab 01
$lab01 = $repo->getLab('postgresql-18', 'lab_01_introduction_setup_postgresql_18');
$t->assertTrue($lab01 !== null, "Récupération détaillée du Lab 01");
foreach (['pdf', 'md', 'html', 'docx'] as $ext) {
    $t->assertTrue(isset($lab01['downloads'][$ext]), "Lab 01 possède le lien de téléchargement au format '{$ext}'");
}

// 4. Test MarkdownParser (HTML, Sommaire TOC, Quiz QCM, Extraction SQL Lab)
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
$resolvedPdf = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'pdf');
$t->assertTrue($resolvedPdf !== null && is_file($resolvedPdf['path']), "Résolution physique du PDF du Module 01 réussie");

$resolvedPptx = $downloader->resolveDownloadPath('postgresql-18', 'module', 'module_01_introduction_postgresql_18', 'pptx');
$t->assertTrue($resolvedPptx !== null && is_file($resolvedPptx['path']), "Résolution physique du PPTX du Module 01 réussie");

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

// 7. Test Intégrité Syntaxique PHP de tous les fichiers et Templates
echo "\n--- 7. Tests Intégrité Syntaxique PHP (Linting de tous les composants & vues) ---\n";
$phpFiles = array_merge(
    glob(__DIR__ . '/../public/*.php') ?: [],
    glob(__DIR__ . '/../src/**/*.php') ?: [],
    glob(__DIR__ . '/../templates/*.php') ?: [],
    glob(__DIR__ . '/../templates/pages/*.php') ?: [],
    glob(__DIR__ . '/../bin/*.php') ?: []
);
foreach ($phpFiles as $pf) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($pf) . ' 2>&1', $out, $code);
    $t->assertEquals(0, $code, "Syntaxe PHP valide : " . basename($pf));
}

exit($t->summary());
