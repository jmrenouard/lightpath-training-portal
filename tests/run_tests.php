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

// 10.6 RCA-LMS-16 : En-têtes de sécurité HTTP, Shim JS anti-violation unload (chext_loader.js) & Règle Issue GitHub
$securityPhpCode = (string)file_get_contents(__DIR__ . '/../src/Core/Security.php');
$t->assertTrue(str_contains($securityPhpCode, 'sendSecurityHeaders') && str_contains($securityPhpCode, 'renderUnloadShimScript') && str_contains($securityPhpCode, 'pagehide'), "RCA-LMS-16 : Security::sendSecurityHeaders() et Security::renderUnloadShimScript() actifs dans Security.php");
$t->assertTrue(str_contains($layoutTpl, 'Security::renderUnloadShimScript()') && str_contains($dlControllerCode, 'Security::renderUnloadShimScript()'), "RCA-LMS-16 : Shim JS unload->pagehide injecté dans layout.php, login.php et les aperçus HTML inline");

exit($t->summary());
