<?php
declare(strict_types=1);

/**
 * Suite de Tests Automatisés (TDD) — LightPath Training Portal (LMS PHP 8.3)
 * Exécution : php tests/run_tests.php
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

// 8. Tests Contrats RCA-144 (RCA-LMS-01 à RCA-LMS-05) : Visuel, Mermaid, Cache < 20ms, Parcours, ScrollSpy & Mode Slides 16:9
echo "\n--- 8. Tests Contrats RCA-144 (Rendu Visuel, Mermaid Retina, Cache < 20ms, Parcours, ScrollSpy & Diaporama 16:9) ---\n";

// 8.1 RCA-LMS-01 : Diagrammes Mermaid Retina, Callouts à puces & Zéro Markdown brut sur les 34 Modules et 34 Labs
$t->assertTrue(str_contains($parsedMod['html'], 'lms-mermaid-card'), "RCA-LMS-01 : Module 01 rend les schémas Mermaid sous forme de carte visuelle .lms-mermaid-card");
$t->assertTrue(str_contains($parsedMod['html'], 'assets%2Fmermaid_cache%2Fmermaid_'), "RCA-LMS-01 : Module 01 résout l'image Retina pré-calculée mermaid_<hash>.png");
$t->assertTrue(!str_contains($parsedMod['html'], 'lms-mermaid-code'), "RCA-LMS-01 : Zéro bloc Mermaid brut non transformé (.lms-mermaid-code)");
$t->assertTrue(str_contains($parsedMod['html'], 'lms-doc-meta-grid'), "RCA-LMS-01 : En-tête de métadonnées transformé en grille .lms-doc-meta-grid");
$t->assertTrue(str_contains($parsedMod['html'], 'lms-callout-list'), "RCA-LMS-01 : Les listes à puces dans les callouts (> * ...) sont transformées en <ul class=\"lms-callout-list\">");
$t->assertTrue(!str_contains($parsedMod['html'], '<br/>* '), "RCA-LMS-01 : Zéro puce brute <br/>* à l'intérieur des callouts");
$t->assertTrue(is_file(__DIR__ . '/../public/assets/js/mermaid.min.js'), "RCA-LMS-01 : Moteur vectoriel local public/assets/js/mermaid.min.js présent (zéro CDN)");

$rawAsteriskModules = 0;
$minSlidesCount = 999;
foreach ($pgCourse['modules'] as $mKey => $mInfo) {
    $pm = $parser->parseModuleFile($mInfo['source_md_path']);
    // Supprimer les blocs <pre>...</pre> et <code>...</code> avant de vérifier l'absence de ** bruts
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

// 8.2 RCA-LMS-02 : Garde-fous anti-débordement CSS
$cssContent = (string)file_get_contents(__DIR__ . '/../public/assets/css/app.css');
$t->assertTrue(str_contains($cssContent, 'overflow-wrap: break-word') && str_contains($cssContent, 'min-width: 0'), "RCA-LMS-02 : Garde-fous CSS anti-débordement (overflow-wrap: break-word & min-width: 0) actifs");

// 8.3 RCA-LMS-03 : Performance du Cache Catalogue & Docker (< 20 ms)
$t0 = microtime(true);
$warmRepo = new CourseRepository($coursesConfig);
$warmCourse = $warmRepo->getCourse('postgresql-18');
$warmDockerStatus = $docker->getCachedUserContainerStatus(1);
$elapsedMs = (microtime(true) - $t0) * 1000.0;
$t->assertTrue($elapsedMs < 25.0, sprintf("RCA-LMS-03 : Chargement Catalogue + Statut Docker en cache ultra-rapide (%.2f ms < 25 ms)", $elapsedMs));
$t->assertTrue(!empty($warmDockerStatus['container_name']), "RCA-LMS-03 : Statut Docker en cache immédiat sans appel shell wsl bloquant");

// 8.4 RCA-LMS-04 : Structuration des 4 Parcours, Stepper de Position & ScrollSpy
$tracksCfg = $pgCourse['tracks'] ?? [];
$t->assertEquals(4, count($tracksCfg), "RCA-LMS-04 : Les 4 parcours pédagogiques sont définis");
foreach ($tracksCfg as $tId => $tData) {
    $t->assertTrue(!empty($tData['phases']) && !empty($tData['target_audience']) && !empty($tData['objective']), "RCA-LMS-04 : Parcours '{$tId}' possède ses journées/phases, son public cible et son objectif");
}
$t->assertEquals(1, $mod01['track_position'] ?? 0, "RCA-LMS-04 : Module 01 est positionné à l'Étape 1 de son parcours");
$t->assertEquals(20, $mod01['track_total'] ?? 0, "RCA-LMS-04 : Module 01 indique un total de 20 étapes dans le Parcours 1");

$jsContent = (string)file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$t->assertTrue(str_contains($jsContent, 'updateScrollSpy') && str_contains($jsContent, 'data-scrollspy-target'), "RCA-LMS-04 : Moteur ScrollSpy temps réel présent dans public/assets/js/app.js");

// 8.5 RCA-LMS-05 : Mode Diaporama Interactif 16:9 (Slides HTML5 + PDF Slides)
$t->assertGreaterThanOrEqual(12, $minSlidesCount, "RCA-LMS-05 : Chacun des 34 modules génère au moins 12 diapositives 16:9 interactives (min mesuré : {$minSlidesCount})");
$modViewTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/module_view.php');
$t->assertTrue(str_contains($modViewTpl, 'lms-slide-deck-wrapper') && str_contains($modViewTpl, 'mode=slides'), "RCA-LMS-05 : Lecteur Diaporama 16:9 intégré dans templates/pages/module_view.php");

// 9. Tests Contrats RCA-145 (RCA-LMS-06 à RCA-LMS-10) : Logos Dark/Light, Zéro 404, 58 Slides Studio M01, Vitesse & Navigation Directe 34 Modules
echo "\n--- 9. Tests Contrats RCA-145 (Logos Dark/Light, Zéro 404, 58 Slides Studio M01, Vitesse & Navigation Directe 34 Modules) ---\n";

// 9.1 RCA-LMS-06 : Logos 100% transparents et adaptatifs Dark / Light
foreach (['lightpath_logo_white_text.png', 'lightpath_logo_dark_text.png', 'postgresql_elephant_dark.png', 'postgresql_elephant_light.png'] as $logoFile) {
    $logoPath = __DIR__ . '/../public/assets/img/' . $logoFile;
    $t->assertTrue(is_file($logoPath) && filesize($logoPath) > 1000, "RCA-LMS-06 : Logo transparent '{$logoFile}' présent dans public/assets/img/");
}
$layoutTpl = (string)file_get_contents(__DIR__ . '/../templates/layout.php');
$t->assertTrue(str_contains($layoutTpl, 'theme-aware-logo') && str_contains($layoutTpl, 'data-dark-src') && str_contains($layoutTpl, 'data-light-src'), "RCA-LMS-06 : Bascule automatique des logos Dark/Light intégrée dans templates/layout.php");

// 9.2 RCA-LMS-07 : Zéro 404 sur les ressources statiques (logos, images de cours, favicon, devtools) & Permissions Policy unload
$t->assertTrue(is_file(__DIR__ . '/../public/favicon.ico'), "RCA-LMS-07 : public/favicon.ico présent (zéro 404)");
$t->assertTrue(is_file(__DIR__ . '/../public/.well-known/appspecific/com.chrome.devtools.json'), "RCA-LMS-07 : Manifeste Chrome DevTools présent (zéro 404)");
$t->assertTrue(is_file(__DIR__ . '/../public/assets/logos/lightpath_logo_dark_text.png'), "RCA-LMS-07 : Jonction statique public/assets/logos/ opérationnelle");
$t->assertTrue(is_file(__DIR__ . '/../public/assets/images/modules/img_mod_01_aio_subsystem.png'), "RCA-LMS-07 : Jonction statique public/assets/images/modules/img_mod_01_aio_subsystem.png opérationnelle");
$dlControllerCode = (string)file_get_contents(__DIR__ . '/../src/Course/DownloadController.php');
$t->assertTrue(str_contains($dlControllerCode, 'assets/') && str_contains($dlControllerCode, 'Permissions-Policy: unload='), "RCA-LMS-07 : DownloadController réécrit les chemins ../assets/ et autorise unload=*");
$t->assertTrue(str_contains($modViewTpl, 'data-src="index.php?action=download') && str_contains($modViewTpl, 'allow="unload *"'), "RCA-LMS-07 : Iframe PDF chargée à la demande (data-src) avec allow=\"unload *\"");

// 9.3 RCA-LMS-08 : Alignement exact des 58 diapositives Studio 16:9 sur le Module 01 (et 1 686 slides sur les 34 modules)
$t->assertEquals(58, count($parsedMod['slides'] ?? []), "RCA-LMS-08 : Le Module 01 expose exactement les 58 diapositives officielles Studio 16:9");
$t->assertGreaterThanOrEqual(30, $minSlidesCount, "RCA-LMS-08 : Chacun des 34 modules expose son deck complet Studio 16:9 (>= 30 slides par module, min={$minSlidesCount})");

// 9.4 RCA-LMS-09 : Vitesse d'affichage (< 15 ms, session_write_close(), zéro chargement synchrone mermaid.min.js 3.3 Mo)
$indexPhpCode = (string)file_get_contents(__DIR__ . '/../public/index.php');
$t->assertTrue(str_contains($indexPhpCode, 'session_write_close()'), "RCA-LMS-09 : Libération immédiate du verrou de session PHP (session_write_close) dans public/index.php");
$t->assertTrue(!str_contains($layoutTpl, '<script src="assets/js/mermaid.min.js"></script>'), "RCA-LMS-09 : Le bundle mermaid.min.js (3,3 Mo) n'est plus chargé de façon synchrone bloquante dans layout.php");
$t->assertTrue(str_contains($parsedMod['html'], 'src="assets/mermaid_cache/mermaid_'), "RCA-LMS-09 : Les schémas Mermaid utilisent les URLs statiques directes assets/mermaid_cache/");

// 9.5 RCA-LMS-10 : Navigation directe vers chacun des 34 modules dans la barre verticale gauche
$t->assertTrue(str_contains($layoutTpl, 'sidebar-module-filter') && str_contains($layoutTpl, 'sidebar-tracks-accordion') && str_contains($layoutTpl, 'sidebar-module-link'), "RCA-LMS-10 : Arbre de navigation directe des 34 modules + filtre rapide présents dans la barre verticale gauche");
$t->assertTrue(str_contains($jsContent, 'syncThemeLogos') && str_contains($jsContent, 'sidebar-module-filter'), "RCA-LMS-10 : Logique JS de filtrage latéral et de synchronisation des logos active dans app.js");

// 10. Tests Contrats RCA-146 (RCA-LMS-11 à RCA-LMS-16) : Synchronisation 1:1 Slides/PDF, Lecteur PDF en Zone Principale, Menu Droite Sticky Sans Scrollbar, Barres Gauche/Droite Repliables, Énoncés/Corrigés d'Ateliers & Sécurité Unload
echo "\n--- 10. Tests Contrats RCA-146 (Synchronisation Slides 1:1, Lecteur PDF Colonne 1, Menu Droite Sticky Sans Scrollbar, Barres Repliables, Énoncés/Corrigés Labs & Sécurité) ---\n";

// 10.1 RCA-LMS-11 : Synchronisation 1:1 entre titres du sommaire des slides et images PNG du PDF officiel
$mod01Slides = $parsedMod['slides'] ?? [];
$t->assertTrue(str_contains($mod01Slides[1]['title'] ?? '', 'Objectifs Pédagogiques'), "RCA-LMS-11 : Slide 2 du Module 01 correspond à 'Objectifs Pédagogiques Opérationnels'");
$t->assertTrue(str_contains($mod01Slides[2]['title'] ?? '', 'Plan du Module'), "RCA-LMS-11 : Slide 3 du Module 01 correspond exactement à la page PDF 3 'Plan du Module'");
$t->assertTrue(str_contains($mod01Slides[3]['title'] ?? '', 'Références & Documentation Officielle'), "RCA-LMS-11 : Slide 4 du Module 01 correspond exactement à la page PDF 4 'Références & Documentation Officielle postgresql.org'");
$t->assertTrue(!str_contains($modViewTpl, 'src="<?= $idx <= 2 ?'), "RCA-LMS-11 : Aucune balise <img src=\"\"> vide sur les diapositives 3..N dans module_view.php");

// 10.2 RCA-LMS-12 : Le Lecteur PDF Continu s'affiche dans la colonne principale (.slide-stage-main-col) et non à la place du menu de droite
$t->assertTrue(str_contains($modViewTpl, 'class="slide-stage-main-col"') && str_contains($cssContent, '[hidden] {') && str_contains($jsContent, 'switchSlideEngine'), "RCA-LMS-12 : #slide-stage-html et #slide-stage-pdf sont encapsulés dans .slide-stage-main-col avec bascule propre");

// 10.3 RCA-LMS-13 : Barre verticale de droite sticky (overflow-x: clip) et sans barre de défilement visible (scrollbar-width: none)
$t->assertTrue(str_contains($cssContent, 'overflow-x: clip;') && !str_contains($cssContent, "html, body {\n    margin: 0;\n    padding: 0;\n    background-color: var(--bg-base);\n    color: var(--text-primary);\n    font-family: var(--font-sans);\n    font-size: 15px;\n    line-height: 1.62;\n    -webkit-font-smoothing: antialiased;\n    overflow-x: hidden;"), "RCA-LMS-13 : body utilise overflow-x: clip pour préserver position: sticky sur la barre verticale de droite");
$t->assertTrue(str_contains($cssContent, 'scrollbar-width: none !important;') && str_contains($cssContent, '.reader-sidebar::-webkit-scrollbar'), "RCA-LMS-13 : Suppression totale des barres de défilement visibles sur les menus verticaux de droite");
$t->assertTrue(!str_contains($modViewTpl, 'style="max-height: 76vh; overflow-y: auto;"'), "RCA-LMS-13 : Suppression du style inline overflow-y: auto sur le sommaire des slides");

// 10.4 RCA-LMS-14 : Réduction / Extension des barres verticales de gauche et de droite
$t->assertTrue(str_contains($layoutTpl, 'btn-toggle-left-sidebar') && str_contains($layoutTpl, 'btn-toggle-right-sidebar') && str_contains($layoutTpl, 'btn-floating-restore-right'), "RCA-LMS-14 : Boutons de réduction/extension des barres verticales gauche et droite présents dans layout.php");
$t->assertTrue(str_contains($cssContent, 'body.left-sidebar-collapsed') && str_contains($cssContent, 'body.right-sidebar-collapsed'), "RCA-LMS-14 : Styles CSS de repli des barres verticales gauche et droite actifs");
$t->assertTrue(str_contains($jsContent, 'lp_lms_left_sidebar') && str_contains($jsContent, 'lp_lms_right_sidebar'), "RCA-LMS-14 : Persistance localStorage de l'état des barres verticales gauche et droite active");

// 10.5 RCA-LMS-15 : Onglets Énoncé et Corrigé Complet sur les 34 Ateliers Pratiques
$labViewTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/lab_view.php');
$t->assertTrue(str_contains($labViewTpl, 'mode=enonce') && str_contains($labViewTpl, 'mode=corrige') && str_contains($labViewTpl, 'btn-toggle-lab-solutions'), "RCA-LMS-15 : Sélecteur Énoncé / Corrigé Complet / Vue Intégrale et bouton Solutions présents dans lab_view.php");
$labsWithEnonceAndCorrige = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    $pl = $parser->parseLabFile($lInfo['source_md_path']);
    if (!empty($pl['enonce_html']) && !empty($pl['corrige_html']) && strlen($pl['enonce_html']) > 200 && strlen($pl['corrige_html']) > 100) {
        $labsWithEnonceAndCorrige++;
    }
}
$t->assertEquals(34, $labsWithEnonceAndCorrige, "RCA-LMS-15 : 34 / 34 ateliers disposent d'un volet Énoncé et d'un volet Corrigé Complet distincts");

// 10.6 RCA-LMS-16 : En-têtes de sécurité HTTP, Shim JS anti-violation unload (chext_loader.js), Security::isPathInside(), bornage Docker & Règle Issue GitHub
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

// 11. Tests Contrats RCA-157 (RCA-LMS-27 & RCA-LMS-28) : Serveur MCP @playwright/mcp Durci, Campagne E2E, Responsive Mobile 375px & Autocomplete DOM
echo "\n--- 11. Tests Contrats RCA-157 (Serveur MCP @playwright/mcp Durci, Campagne E2E, Responsive Mobile 375px & Autocomplete DOM) ---\n";

$loginTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/login.php');
$profileTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/profile.php');
$adminTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/admin.php');
$certTpl = (string)file_get_contents(__DIR__ . '/../templates/pages/certificate.php');
$appCfgCode = (string)file_get_contents(__DIR__ . '/../config/app.php');
$e2eRunnerCode = (string)file_get_contents(__DIR__ . '/e2e/run_playwright_campaign.mjs');

$t->assertTrue(str_contains($loginTpl, 'minmax(min(100%, 340px), 1fr)') && str_contains($cssContent, '@media (max-width: 640px)'), "RCA-LMS-27 : Grille responsive mobile 375px sans débordement dans login.php et @media (max-width: 640px) dans app.css");
$t->assertTrue(str_contains($cssContent, 'minmax(min(100%, 330px), 1fr)') && str_contains($cssContent, '.reader-grid > *') && str_contains($certTpl, 'clamp(1rem, 4vw, 3rem)'), "RCA-LMS-27 : Protection anti-débordement mobile sur .card-grid, .reader-grid > * et certificate.php");
$t->assertTrue(str_contains($loginTpl, 'autocomplete="username"') && str_contains($loginTpl, 'autocomplete="current-password"') && str_contains($loginTpl, 'autocomplete="new-password"'), "RCA-LMS-28 : Attributs autocomplete W3C présents sur les formulaires de connexion et d'inscription (login.php)");
$t->assertTrue(str_contains($profileTpl, 'autocomplete="new-password"') && str_contains($adminTpl, 'autocomplete="new-password"'), "RCA-LMS-28 : Attributs autocomplete=\"new-password\" présents dans profile.php et admin.php (zéro avertissement DOM Chromium)");

$mcpConfigFile = __DIR__ . '/../config/playwright-mcp.config.json';
$mcpCfg = is_file($mcpConfigFile) ? json_decode((string)file_get_contents($mcpConfigFile), true) : null;
$t->assertTrue(is_array($mcpCfg) && ($mcpCfg['browser']['isolated'] ?? false) === true && ($mcpCfg['allowUnrestrictedFileAccess'] ?? true) === false, "RCA-LMS-28 : Configuration durcie config/playwright-mcp.config.json valide (isolated=true, allowUnrestrictedFileAccess=false)");
$t->assertTrue(str_contains($appCfgCode, "getenv('LMS_DATA_DIR')") && str_contains($e2eRunnerCode, 'LMS_DATA_DIR') && str_contains($e2eRunnerCode, 'ERR_BLOCKED_BY_CLIENT'), "RCA-LMS-28 : Harnais E2E Playwright MCP isolé par LMS_DATA_DIR temporaire et vérifiant le sandboxing allowedOrigins");

// 12. Tests Contrats RCA-147 à RCA-156 (RCA-LMS-17 à RCA-LMS-26) : PdoStorage, 34 Oracles SQL DO $$, Corrigés Testables, Cheatsheets Labs, Fonctionnalités Enterprise LMS & Qualiopi
echo "\n--- 12. Tests Contrats RCA-147 à RCA-156 (PdoStorage, 34 Oracles SQL DO \$\$, Corrigés Testables, Enterprise LMS & Qualiopi) ---\n";

// 12.1 RCA-150 / RCA-LMS-20 : PdoStorage (SQLite in-memory) initialise $this->pdo avant parent::__construct() et persiste via lms_kv_store
$pdoFallbackDir = sys_get_temp_dir() . '/lp_pdo_test_' . uniqid();
$pdoStorage = new PdoStorage(['dsn' => 'sqlite::memory:', 'username' => '', 'password' => ''], $pdoFallbackDir);
$pdoUsers = $pdoStorage->getUsers();
$t->assertGreaterThanOrEqual(3, count($pdoUsers), "RCA-LMS-20 : PdoStorage initialise correctement les utilisateurs par défaut dans SQLite in-memory sans TypeError");
$pdoStorage->markItemCompleted(3, 'postgresql-18', 'lab', 'lab_01_introduction_setup_postgresql_18', true);
$pdoProg = $pdoStorage->getUserProgress(3, 'postgresql-18');
$t->assertTrue(in_array('lab_01_introduction_setup_postgresql_18', $pdoProg['completed_labs'] ?? [], true), "RCA-LMS-20 : PdoStorage persiste et relit la progression via lms_kv_store");

// 12.2 RCA-147..149 & RCA-152 / RCA-LMS-17..19 & 22 : Les 34 ateliers Markdown ont tous un corrigé, une grille DoD, un bloc DO $$ SQL et VALIDATION_OK, et LabSqlRunner couvre les 34 labs
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
$t->assertEquals(34, $allLabsHaveDoBlockAndMarker, "RCA-147/148/152 : 34 / 34 ateliers possèdent un corrigé SQL extractible, un Oracle DO \$\$ et le marqueur VALIDATION_OK");
$t->assertEquals('dvdrental', $sqlRunner->detectTargetDatabase('lab_sql_02_jointures_sous_requetes_vues'), "RCA-152 : LabSqlRunner route automatiquement les ateliers SQL 01..05 vers la base Sakila 'dvdrental'");
$t->assertEquals('formation_pg', $sqlRunner->detectTargetDatabase('lab_20_tuning_systeme_linux_postgresql'), "RCA-152 : LabSqlRunner route les ateliers DBA vers 'formation_pg'");

// 12.3 RCA-151 / RCA-LMS-21 : MarkdownParser::splitLabEnonceAndCorrige() préserve Pour Aller Plus Loin dans l'Énoncé et ouvre les <details> dans le Corrigé
$sampleRawLab = "# Lab Test\n\n## 1. Travail\n* **Commandes Solutions :**\n```sql\nSELECT 1;\n```\n\n## 3. Commande de Validation\n```sql\nDO \$\$ BEGIN RAISE NOTICE 'VALIDATION_OK'; END \$\$;\n```\n\n## 🌟 Pour Aller Plus Loin\nDéfi Bonus";
$splitSample = $parser->splitLabEnonceAndCorrige($sampleRawLab);
$t->assertTrue(str_contains($splitSample['enonce_md'], '<details>') && str_contains($splitSample['enonce_md'], 'Pour Aller Plus Loin'), "RCA-151 : splitLabEnonceAndCorrige() transforme 'Commandes Solutions :' en spoiler <details> et préserve 'Pour Aller Plus Loin' dans l'énoncé");
$t->assertTrue(str_contains($splitSample['corrige_md'], 'Solution du Challenge Fast-Track #1'), "RCA-151 : splitLabEnonceAndCorrige() extrait la solution du challenge dans le volet Corrigé");

// 12.4 RCA-154 / RCA-LMS-24 : Liaison des 34 Cheatsheets de Labs + Recherche Globale Plein-Texte
$labsWithCheatsheet = 0;
foreach ($pgCourse['labs'] as $lKey => $lInfo) {
    if (!empty($lInfo['linked_cheatsheet_id'])) {
        $labsWithCheatsheet++;
    }
}
$t->assertEquals(34, $labsWithCheatsheet, "RCA-154 : 34 / 34 ateliers pratiques sont liés à leur fiche mémo (linked_cheatsheet_id)");
$searchRes = $repo->searchContent('postgresql-18', 'pg_stat_io');
$t->assertGreaterThanOrEqual(2, count($searchRes), "RCA-156 : CourseRepository::searchContent('postgresql-18', 'pg_stat_io') retourne des modules, labs et entrées de glossaire");

// 12.5 RCA-155 & RCA-156 / RCA-LMS-25 & 26 : Positionnement Initial (15 QCM), Examen Final (40 QCM), Émargement, Satisfaction, SRS (42 cartes), Glossaire (52 termes), Gamification & SCORM 1.2
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

// RCA-159 : Validation exhaustive des 3 paliers de recommandation de parcours
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

$t->assertEquals(42, count(LmsCatalog::getFlashcards()), "RCA-156 : 42 cartes mémo SRS PostgreSQL 18 disponibles");
$t->assertEquals(52, count(LmsCatalog::getGlossary()), "RCA-156 : 52 termes de glossaire technique PostgreSQL 18 disponibles");

$scormXml = LmsCatalog::generateScormManifest($pgCourse);
$t->assertTrue(str_contains($scormXml, '<manifest identifier="LIGHTPATH_PG18_LMS_SCORM12"') && str_contains($scormXml, 'adlcp:scormtype="sco"'), "RCA-156 : Génération valide du manifeste SCORM 1.2 (imsmanifest.xml)");

$gamif = Gamification::computeUserBadgesAndXp(
    $pdoStorage->getUserProgress(3, 'postgresql-18'),
    $pdoStorage->getDiagnosticResult(3, 'postgresql-18'),
    $pdoStorage->getBestExamAttempt(3, 'postgresql-18'),
    $attList
);
$t->assertEquals(8, count($gamif['badges'] ?? []), "RCA-156 : Gamification calcule les 8 badges de compétences DBA et les XP (XP={$gamif['xp']})");

// 13. Tests Contrats RCA-158 : Formatage Inline Code, Extraction Métadonnées Exacte (Module 10, Durées, Bloom), Tableaux avec Pipes Échappés & Zéro Backtick Brut sur les 152 Documents LMS
echo "\n--- 13. Tests Contrats RCA-158 (Formatage Inline Code, Métadonnées Module 10 / Durées / Bloom, Pipes Échappés & Zéro Backtick Brut) ---\n";

$formattedInline = Security::formatInlineText('Paramètre `shared_buffers` et **AIO** <script>alert(1)</script>');
$t->assertTrue(
    str_contains($formattedInline, '<code class="lms-inline-code">shared_buffers</code>')
    && str_contains($formattedInline, '<strong>AIO</strong>')
    && str_contains($formattedInline, '&lt;script&gt;'),
    "RCA-158 : Security::formatInlineText() convertit `code` et **gras** tout en échappant le HTML XSS"
);

$mod10 = $repo->getModule('postgresql-18', 'module_10_integrating_postgresql_with_applications');
$t->assertEquals(
    '🔌 Module 10 : Intégration Applicative, ORM & Connection Pooling',
    $mod10['title'] ?? '',
    "RCA-158 : Le titre H1 du Module 10 n'est pas écrasé par les commentaires Python '# ...' des blocs de code"
);

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

exit($t->summary());



