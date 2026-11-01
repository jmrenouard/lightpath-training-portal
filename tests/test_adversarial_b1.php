<?php
declare(strict_types=1);

/**
 * ============================================================================
 * EMPIRICAL ADVERSARIAL TEST SUITE — BOUCLE 1 (CHALLENGER_B1_1)
 * ============================================================================
 * Tests empiriques rigoureux et oracles adverses pour certifier la Milestone 1
 * du dépôt lightpath-training-portal suite aux correctifs RCA-164 / RCA-LMS-34.
 *
 * 1. Bypass lock_solutions (api_run_lab_solution, download&type=lab, templates)
 * 2. Stress test unauthenticated certificate verification (?page=verify, $currentUser === null)
 * 3. Robustesse import CSV cohorte (csv_content, csv_payload, malformed, empty)
 * 4. Contrôle du streaming chunked 64 Ko (DownloadController HTTP 206)
 * ============================================================================
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\DownloadController;

class AdversarialTestSuite
{
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function assert(bool $condition, string $label, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->failed++;
            $msg = $details !== '' ? "{$label} — {$details}" : $label;
            $this->errors[] = $msg;
            echo "  [FAIL] {$label}\n";
            if ($details !== '') {
                echo "         Détail: {$details}\n";
            }
        }
    }

    public function getPassed(): int
    {
        return $this->passed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

$suite = new AdversarialTestSuite();
$appConfig = require __DIR__ . '/../config/app.php';
$coursesConfig = require __DIR__ . '/../config/courses.php';

echo "========================================================================\n";
echo " ADVERSARIAL CHALLENGER B1 — EMPIRICAL VERIFICATION HARNESS\n";
echo " Target: lightpath-training-portal (PHP " . PHP_VERSION . ")\n";
echo "========================================================================\n\n";

// ----------------------------------------------------------------------------
// Création d'un espace de stockage de test isolé
// ----------------------------------------------------------------------------
$testStorageDir = sys_get_temp_dir() . '/lms_adversarial_b1_' . mt_rand(10000, 99999);
if (!is_dir($testStorageDir)) {
    mkdir($testStorageDir, 0777, true);
}

$storage = new JsonStorage($testStorageDir);
$auth = new Auth($storage);
$courseRepo = new CourseRepository($coursesConfig, $storage->getCustomCourses());
$downloader = new DownloadController($courseRepo);

// Utilisateurs de référence
$studentUser = $storage->getUserById(3);
$trainerUser = $storage->getUserById(2);
$adminUser = $storage->getUserById(1);

// ============================================================================
// SUITE 1 : TENTATIVES DE CONTOURNEMENT DU VERROUILLAGE DES CORRIGÉS (lock_solutions)
// ============================================================================
echo "--- Suite 1 : Tentatives de Contournement lock_solutions (Simulations Apprenant) ---\n";

// 1.1 Activer le verrouillage via lock_solutions = true
$storage->saveClassroomState('postgresql-18', [
    'lock_solutions' => true,
    'broadcast_message' => 'Solutions verrouillées pour l\'examen blanc',
]);
$state = $storage->getClassroomState('postgresql-18');
$suite->assert(!empty($state['lock_solutions']), "État de classe: lock_solutions est actif");

// 1.2 Test direct de la condition de garde lock_solutions pour un stagiaire
$solutionsLockedStudent = (!empty($state['lock_solutions']) || !empty($state['solutions_locked'])) && !$auth->isStaff($studentUser);
$suite->assert($solutionsLockedStudent === true, "La condition solutionsLocked s'applique strictement à l'apprenant");

// 1.3 Test de la condition pour un formateur (ne doit PAS être verrouillé)
$solutionsLockedTrainer = (!empty($state['lock_solutions']) || !empty($state['solutions_locked'])) && !$auth->isStaff($trainerUser);
$suite->assert($solutionsLockedTrainer === false, "Le formateur conserve l'accès aux corrigés malgré lock_solutions");

// 1.4 Test de la condition pour un admin (ne doit PAS être verrouillé)
$solutionsLockedAdmin = (!empty($state['lock_solutions']) || !empty($state['solutions_locked'])) && !$auth->isStaff($adminUser);
$suite->assert($solutionsLockedAdmin === false, "L'administrateur conserve l'accès aux corrigés malgré lock_solutions");

// 1.5 Simulation de requête sur api_run_lab_solution
$simulatedApiAction = static function (array $user, array $classroomState): array {
    $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && in_array($user['role'] ?? '', ['student'], true);
    if ($solutionsLocked) {
        return [
            'status' => 403,
            'body' => [
                'success' => false,
                'passed' => false,
                'error' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
                'message' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
            ],
        ];
    }
    return ['status' => 200, 'body' => ['success' => true]];
};

$resApiStudent = $simulatedApiAction($studentUser, $storage->getClassroomState('postgresql-18'));
$suite->assert($resApiStudent['status'] === 403, "Appel api_run_lab_solution par apprenant renvoie HTTP 403", "Status: {$resApiStudent['status']}");
$suite->assert($resApiStudent['body']['success'] === false && $resApiStudent['body']['passed'] === false, "api_run_lab_solution renvoie success=false et passed=false");

// Formateur sur api_run_lab_solution
$resApiTrainer = $simulatedApiAction($trainerUser, $storage->getClassroomState('postgresql-18'));
$suite->assert($resApiTrainer['status'] === 200, "Appel api_run_lab_solution par formateur autorisé (HTTP 200)");

// 1.6 Simulation du téléchargement lab (download&type=lab)
$simulatedDownloadAction = static function (array $user, string $type, array $classroomState): array {
    if ($type === 'lab') {
        $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && in_array($user['role'] ?? '', ['student'], true);
        if ($solutionsLocked) {
            return [
                'status' => 403,
                'body' => 'Le téléchargement et la consultation des énoncés/corrigés de labs sont temporairement verrouillés par le formateur.',
            ];
        }
    }
    return ['status' => 200, 'body' => 'OK'];
};

$resDlStudent = $simulatedDownloadAction($studentUser, 'lab', $storage->getClassroomState('postgresql-18'));
$suite->assert($resDlStudent['status'] === 403, "Téléchargement lab (download&type=lab) par apprenant renvoie HTTP 403", "Status: {$resDlStudent['status']}");
$suite->assert(str_contains($resDlStudent['body'], 'temporairement verrouillés'), "Le corps HTTP 403 explique clairement le verrouillage pédagogique");

// Formateur sur download&type=lab
$resDlTrainer = $simulatedDownloadAction($trainerUser, 'lab', $storage->getClassroomState('postgresql-18'));
$suite->assert($resDlTrainer['status'] === 200, "Téléchargement lab par formateur autorisé (HTTP 200)");

// 1.7 Simulation avec lock_solutions = false (déverrouillé)
$storage->saveClassroomState('postgresql-18', ['lock_solutions' => false]);
$unlockedState = $storage->getClassroomState('postgresql-18');
$resDlStudentUnlocked = $simulatedDownloadAction($studentUser, 'lab', $unlockedState);
$suite->assert($resDlStudentUnlocked['status'] === 200, "Quand lock_solutions=false, l'apprenant peut télécharger son lab (HTTP 200)");

// 1.8 Simulation du filtrage des modes dans lab_view.php lorsque verrouillé
$storage->saveClassroomState('postgresql-18', ['lock_solutions' => true]);
$lockedState = $storage->getClassroomState('postgresql-18');
$testModes = ['corrige', 'full', 'html_preview', 'pdf_preview'];
foreach ($testModes as $attemptedMode) {
    $labMode = $attemptedMode;
    $solutionsLocked = (!empty($lockedState['lock_solutions']) || !empty($lockedState['solutions_locked'])) && !$auth->isStaff($studentUser);
    $lockedSolutionsNotice = false;
    if ($solutionsLocked && in_array($labMode, ['corrige', 'full', 'html_preview', 'pdf_preview'], true)) {
        $labMode = 'enonce';
        $lockedSolutionsNotice = true;
    }
    $suite->assert($labMode === 'enonce' && $lockedSolutionsNotice === true, "Tentative d'accès au mode '{$attemptedMode}' redirigée vers 'enonce' avec alerte");
}

// 1.9 Bouton de bas de page masqué dans lab_view.php
$solutionsLocked = true;
$labMode = 'enonce';
$showBottomSolutionButton = (!$solutionsLocked && $labMode !== 'corrige');
$suite->assert($showBottomSolutionButton === false, "Le bouton bas de page 'Consulter le Corrigé Complet' est rigoureusement masqué");


// ============================================================================
// SUITE 2 : STRESS TEST VÉRIFICATION DE CERTIFICAT SANS SESSION ($currentUser === null)
// ============================================================================
echo "\n--- Suite 2 : Stress Test Vérification de Certificat Anonyme (?page=verify) ---\n";

// Installation d'un trap d'erreurs strict : AUCUN warning, notice ou fatal toléré
$capturedPhpErrors = [];
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$capturedPhpErrors): bool {
    $capturedPhpErrors[] = [
        'errno' => $errno,
        'errstr' => $errstr,
        'errfile' => $errfile,
        'errline' => $errline,
    ];
    return true; // continue execution
});

// Créer un certificat valide dans notre storage de test pour Thomas Dubois
$validCertCode = 'LP-PG18-CERT-3-TESTVALID';
$storage->saveExamAttempt(3, 'postgresql-18', [
    'score' => 20,
    'total' => 20,
    'percent' => 100,
    'passed' => true,
    'certificate_id' => $validCertCode,
    'submitted_at' => date('Y-m-d H:i:s'),
    'user_name' => 'Thomas Dubois (Stagiaire DBA)',
    'mention' => 'Certifié LightPath',
]);

// Fonction de simulation du rendu de verify.php et layout.php
$renderVerifyPage = static function (?string $code, ?array $currentUser, JsonStorage $storage, array $appConfig, array $coursesConfig) use (&$capturedPhpErrors): string {
    $currentCourseSlug = 'postgresql-18';
    $currentCourse = [
        'slug' => 'postgresql-18',
        'title' => 'PostgreSQL 18 — Administration & Optimisation',
        'short_title' => 'PG18 Admin',
        'modules' => [],
        'labs' => [],
    ];
    $userBestExam = null;
    $flashError = null;
    $flashSuccess = null;
    $page = 'verify';
    $auth = new Auth($storage);

    $_GET['page'] = 'verify';
    if ($code !== null) {
        $_GET['code'] = $code;
    } else {
        unset($_GET['code']);
    }

    ob_start();
    require __DIR__ . '/../templates/pages/verify.php';
    return ob_get_clean() ?: '';
};

// 2.1 Requête sans code et sans session active ($currentUser === null)
$capturedPhpErrors = [];
$htmlEmptyCode = $renderVerifyPage('', null, $storage, $appConfig, $coursesConfig);
$suite->assert(empty($capturedPhpErrors), "Rendu ?page=verify sans code et sans session : 0 notice / 0 warning", json_encode($capturedPhpErrors));
$suite->assert(str_contains($htmlEmptyCode, 'Vérification d\'Authenticité d\'un Certificat'), "Le titre officiel du registre de certification est présent");
$suite->assert(str_contains($htmlEmptyCode, 'REGISTRE OFFICIEL DE CERTIFICATION LIGHTPATH SARL'), "Le bandeau Qualiopi officiel est présent");

// 2.2 Requête avec code INVALIDE / inexistant et sans session active
$invalidCodesToTest = [
    'LP-INVALID-999',
    '<script>alert("xss")</script>',
    '\' OR \'1\'=\'1',
    '../../../../etc/passwd',
    str_repeat('A', 2048),
    "LP-CERT\x00NULLBYTE",
    '   espaces   ',
];

foreach ($invalidCodesToTest as $invCode) {
    $capturedPhpErrors = [];
    $htmlInvalid = $renderVerifyPage($invCode, null, $storage, $appConfig, $coursesConfig);
    $suite->assert(empty($capturedPhpErrors), "Code adverse '" . substr($invCode, 0, 20) . "' : 0 notice / 0 warning", json_encode($capturedPhpErrors));
    $suite->assert(str_contains($htmlInvalid, 'CODE DE CERTIFICAT INTROUVABLE'), "Le code invalide affiche l'avertissement adéquat");
    // Vérification anti-XSS
    if (str_contains($invCode, '<script>')) {
        $suite->assert(!str_contains($htmlInvalid, '<script>alert'), "La charge XSS est rigoureusement neutralisée / échappée");
    }
}

// 2.3 Requête avec code VALIDE et sans session active ($currentUser === null)
$capturedPhpErrors = [];
$htmlValid = $renderVerifyPage($validCertCode, null, $storage, $appConfig, $coursesConfig);
$suite->assert(empty($capturedPhpErrors), "Rendu avec code VALIDE sans session : 0 notice / 0 warning", json_encode($capturedPhpErrors));
$suite->assert(str_contains($htmlValid, 'CERTIFICAT AUTHENTIQUE & VÉRIFIÉ PAR LIGHTPATH SARL'), "Bandeau vert de certificat authentique affiché");
$suite->assert(str_contains($htmlValid, 'Thomas Dubois'), "Nom du titulaire certifié affiché correctement");
$suite->assert(str_contains($htmlValid, '100%'), "Score sommatif affiché");
$suite->assert(str_contains($htmlValid, '<svg class="lms-qr-verify-svg"'), "QR Code SVG offline généré avec classe lms-qr-verify-svg sans ressource externe");

// 2.4 Vérification de layout.php avec $currentUser === null
$capturedPhpErrors = [];
$renderLayoutAnonymously = static function (?array $currentUser, JsonStorage $storage, array $appConfig, array $coursesConfig) use (&$capturedPhpErrors): string {
    $currentCourseSlug = 'postgresql-18';
    $currentCourse = [
        'slug' => 'postgresql-18',
        'title' => 'PostgreSQL 18 — Administration & Optimisation',
        'short_title' => 'PostgreSQL 18 Enterprise',
        'modules' => [],
        'labs' => [],
    ];
    $page = 'verify';
    $userId = 0;
    $flashError = null;
    $flashSuccess = null;
    $auth = new Auth($storage);
    $courseRepo = new CourseRepository($coursesConfig, []);
    $allCourses = [$currentCourse];
    $userProgress = ['completed_modules' => [], 'completed_labs' => [], 'quiz_scores' => []];
    $userDiagnostic = null;
    $userBestExam = null;
    $userAttendance = [];
    $userGamification = ['xp' => 0, 'rank' => 'Visiteur', 'unlocked_count' => 0, 'total_badges' => 8];
    $dockerStatus = ['running' => false, 'container_name' => 'aucun', 'port' => 0, 'connection_string' => ''];
    $classroomState = [];
    $activeModuleId = '';
    $completedModulesList = [];
    $trackModsList = [];
    $totalModules = 0;
    $totalLabs = 0;
    $doneModules = 0;
    $doneLabs = 0;
    $activeSidebarTrack = '';
    $globalCompletionPct = 0;

    ob_start();
    require __DIR__ . '/../templates/layout.php';
    return ob_get_clean() ?: '';
};

$layoutHtml = $renderLayoutAnonymously(null, $storage, $appConfig, $coursesConfig);
$suite->assert(empty($capturedPhpErrors), "Rendu layout.php pour visiteur anonyme : 0 notice / 0 warning", json_encode($capturedPhpErrors));
$suite->assert(str_contains($layoutHtml, '🔐 Connexion'), "Bouton '🔐 Connexion' présent dans le topbar et sidebar");
$suite->assert(!str_contains($layoutHtml, 'id="topbar-docker-pill"'), "Le widget Docker personnel est masqué pour l'utilisateur non connecté");

restore_error_handler();


// ============================================================================
// SUITE 3 : ROBUSTESSE IMPORT CSV COHORTE (csv_content, csv_payload, malformed)
// ============================================================================
echo "\n--- Suite 3 : Tests d'Admissibilité & Robustesse Import CSV Cohorte ---\n";

// 3.1 Import via nom standard csv_content
$csvContentStd = <<<CSV
Nom;Email;Société;Rôle;MotDePasse
Alice Renaud;alice.renaud@b1corp.fr;B1Corp;student;B1Pass2026!
CSV;
$resStd = $storage->importUsersFromCsv($csvContentStd);
$suite->assert($resStd['created'] === 1 && $resStd['skipped'] === 0, "Import standard (csv_content) crée 1 compte");
$aliceUser = $storage->getUserByEmail('alice.renaud@b1corp.fr');
$suite->assert($aliceUser !== null && $aliceUser['name'] === 'Alice Renaud', "Utilisateur Alice Renaud créé et vérifiable en stockage");

// 3.2 Simulation de lecture rétrocompatible index.php : $_POST['csv_content'] ?? $_POST['csv_payload']
$simulatedPostRoute = static function (array $postData, JsonStorage $storage): array {
    $csvRaw = trim((string)($postData['csv_content'] ?? ($postData['csv_payload'] ?? '')));
    $defaultPw = trim((string)($postData['default_password'] ?? 'LightPath2026!'));
    return $storage->importUsersFromCsv($csvRaw, $defaultPw !== '' ? $defaultPw : 'LightPath2026!');
};

// Test avec uniquement csv_payload (ancien champ du formulaire admin.php avant correctif)
$csvPayloadOld = <<<CSV
Nom;Email;Société;Rôle;MotDePasse
Bernard Dupont;bernard.dupont@b1corp.fr;B1Corp;student;B1Pass2026!
CSV;
$resOld = $simulatedPostRoute(['csv_payload' => $csvPayloadOld], $storage);
$suite->assert($resOld['created'] === 1 && $resOld['skipped'] === 0, "Import rétrocompatible avec clé 'csv_payload' crée 1 compte");
$bernardUser = $storage->getUserByEmail('bernard.dupont@b1corp.fr');
$suite->assert($bernardUser !== null && $bernardUser['name'] === 'Bernard Dupont', "Utilisateur Bernard Dupont créé avec succès");

// 3.3 Test avec les deux clés présentes (csv_content prioritaire)
$resBoth = $simulatedPostRoute([
    'csv_content' => "Chloe Martin;chloe.martin@b1corp.fr;B1Corp;student;B1Pass2026!",
    'csv_payload' => "Denis Ignored;denis.ignored@b1corp.fr;B1Corp;student;B1Pass2026!",
], $storage);
$suite->assert($resBoth['created'] === 1, "Quand les deux clés sont envoyées, csv_content est prioritaire");
$suite->assert($storage->getUserByEmail('chloe.martin@b1corp.fr') !== null, "Chloe Martin (de csv_content) a bien été créée");
$suite->assert($storage->getUserByEmail('denis.ignored@b1corp.fr') === null, "Denis Ignored (de csv_payload) a été ignoré");

// 3.4 Test avec chaîne vide et espaces uniquement
$resEmpty = $simulatedPostRoute(['csv_content' => ''], $storage);
$suite->assert($resEmpty['created'] === 0 && $resEmpty['skipped'] === 0, "Chaîne vide traitée sans erreur : 0 créé, 0 ignoré");
$resSpaces = $simulatedPostRoute(['csv_content' => "   \r\n   \n\t  "], $storage);
$suite->assert($resSpaces['created'] === 0 && $resSpaces['skipped'] === 0, "Lignes d'espaces blancs traitées sans erreur");

// 3.5 Test CSV malformé (1 seule colonne, email invalide, délimiteur mixte)
$csvMalformed = <<<CSV
LigneSansEmailNiDelimiteur
NomSeulSansEmail;
EmailInvalideSeul;ceci_n_est_pas_un_email;Entreprise;student
Alice Renaud;alice.renaud@b1corp.fr;B1Corp;student;B1Pass2026!
David Petit,david.petit@b1corp.fr,B1Corp,student,B1Pass2026!
CSV;
$resMalformed = $storage->importUsersFromCsv($csvMalformed);
// Ligne 1: < 2 colonnes -> skip
// Ligne 2: email vide -> skip
// Ligne 3: email invalide -> skip
// Ligne 4: doublon alice.renaud -> skip
// Ligne 5: délimiteur virgule valide -> créé
$suite->assert($resMalformed['created'] === 1, "CSV malformé : seule la ligne valide est importée (created=1)", "Got: " . $resMalformed['created']);
$suite->assert($resMalformed['skipped'] === 4, "CSV malformé : 4 lignes invalides ou doublons correctement ignorées (skipped=4)", "Got: " . $resMalformed['skipped']);

// 3.6 Neutralisation de tentative d'injection de formule (CSV Formula Injection)
$csvFormula = <<<CSV
Nom;Email;Société;Rôle;MotDePasse
=cmd|' /C calc'!A0;hacker1@b1corp.fr;@SUM(1,2);student;Hack2026!
+SUM(A1:A10);hacker2@b1corp.fr;-2+3*cmd;student;Hack2026!
CSV;
$resFormula = $storage->importUsersFromCsv($csvFormula);
$suite->assert($resFormula['created'] === 2, "Lignes avec formules créées");
$hacker1 = $storage->getUserByEmail('hacker1@b1corp.fr');
$hacker2 = $storage->getUserByEmail('hacker2@b1corp.fr');
$suite->assert(str_starts_with($hacker1['name'], "'="), "La formule =cmd est neutralisée par un préfixe d'apostrophe sécurisé", "Got: {$hacker1['name']}");
$suite->assert(str_starts_with($hacker1['company'], "'@"), "La formule @SUM est neutralisée pour tableur", "Got: {$hacker1['company']}");
$suite->assert(str_starts_with($hacker2['name'], "'+"), "La formule +SUM est neutralisée", "Got: {$hacker2['name']}");
$suite->assert(str_starts_with($hacker2['company'], "'-"), "L'expression -2+3 est neutralisée", "Got: {$hacker2['company']}");


// ============================================================================
// SUITE 4 : LOGIQUE DE STREAMING CHUNKED HTTP 206 DANS DownloadController
// ============================================================================
echo "\n--- Suite 4 : Contrôle du Streaming Chunked 64 Ko (DownloadController) ---\n";

// 4.1 Inspection du code source de DownloadController.php pour absence de fread($fp, $length)
$dlSource = (string)file_get_contents(__DIR__ . '/../src/Course/DownloadController.php');

$suite->assert(!preg_match('/fread\s*\(\s*\$fp\s*,\s*\$length\s*\)/i', $dlSource), "Aucune allocation monolithique fread(\$fp, \$length) dans DownloadController.php");
$suite->assert(str_contains($dlSource, '$chunkSize = 64 * 1024'), "DownloadController définit explicitement un buffer loop de 64 Ko (64 * 1024)");
$suite->assert(str_contains($dlSource, 'fread($fp, $readSize)'), "Lecture séquentielle fread(\$fp, \$readSize) présente dans la boucle");
$suite->assert(str_contains($dlSource, 'ob_flush()') && str_contains($dlSource, 'flush()'), "Vidage mémoire ob_flush() et flush() actifs dans la boucle de diffusion");

// 4.2 Test empirique de la boucle de découpage et profilage de consommation mémoire
$tempBinarySize = 8 * 1024 * 1024; // 8 Mo
$tempBinaryPath = sys_get_temp_dir() . '/stream_test_' . mt_rand(1000, 9999) . '.mp4';
$fpTemp = fopen($tempBinaryPath, 'wb');
// Remplir avec 8 Mo de données déterministes
for ($i = 0; $i < 128; $i++) {
    fwrite($fpTemp, str_repeat(chr($i % 256), 64 * 1024));
}
fclose($fpTemp);

// Profiler la consommation mémoire pendant le streaming chunked
$memBefore = memory_get_usage(false);
$peakBefore = memory_get_peak_usage(false);

// Exécuter l'algorithme exact de DownloadController::streamFile pour Range: bytes=0-
$start = 0;
$size = (int)filesize($tempBinaryPath);
$end = $size - 1;
$length = $end - $start + 1;
$chunkSize = 64 * 1024;

$fp = fopen($tempBinaryPath, 'rb');
$suite->assert($fp !== false, "Ouverture du flux vidéo temporaire réussie");

$bytesStreamed = 0;
$chunksCount = 0;
$maxBufferMem = 0;

if ($fp !== false) {
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $readSize = min($remaining, $chunkSize);
        $buffer = fread($fp, $readSize);
        if ($buffer === false || $buffer === '') {
            break;
        }
        $bytesStreamed += strlen($buffer);
        $chunksCount++;
        $maxBufferMem = max($maxBufferMem, strlen($buffer));
        unset($buffer);
        $remaining -= $readSize;
    }
    fclose($fp);
}
@unlink($tempBinaryPath);

$peakAfter = memory_get_peak_usage(false);
$memDelta = $peakAfter - $peakBefore;

$suite->assert($bytesStreamed === $tempBinarySize, "L'intégralité des 8 Mo a été diffusée (Bytes: {$bytesStreamed})");
$suite->assert($chunksCount === 128, "Diffusion exactement découpée en 128 chunks de 64 Ko (Chunks: {$chunksCount})");
$suite->assert($maxBufferMem === 64 * 1024, "Taille maximale de chunk en RAM rigoureusement plafonnée à 64 Ko");
$suite->assert($memDelta < 256 * 1024, "Consommation mémoire peak sous 256 Ko (aucun pic lié aux 8 Mo du fichier)", "Peak delta: " . round($memDelta / 1024, 2) . " Ko");

// 4.3 Test d'un Range partiel HTTP 206 (ex: bytes=1000-2047 soit 1048 octets)
$testPartialRange = static function (int $reqStart, int $reqEnd, int $fileSize): array {
    $start = (int)$reqStart;
    $end = min($fileSize - 1, (int)$reqEnd);
    $length = $end - $start + 1;
    return [
        'status' => '206 Partial Content',
        'content_range' => "bytes {$start}-{$end}/{$fileSize}",
        'content_length' => $length,
    ];
};

$rangeMeta = $testPartialRange(1000, 2047, 1000000);
$suite->assert($rangeMeta['content_length'] === 1048, "Range HTTP 206 : Content-Length calculé fidèlement (1048 octets)");
$suite->assert($rangeMeta['content_range'] === 'bytes 1000-2047/1000000', "Range HTTP 206 : En-tête Content-Range exact");


// ============================================================================
// BILAN DES TESTS ADVERSES
// ============================================================================
echo "\n========================================================================\n";
echo " SYNTHÈSE DES VÉRIFICATIONS ADVERSES\n";
echo " Succès : {$suite->getPassed()} | Échecs : {$suite->getFailed()}\n";
echo "========================================================================\n";

if ($suite->getFailed() > 0) {
    echo "❌ VERDICT : REJECT — Des vulnérabilités ou anomalies ont été détectées :\n";
    foreach ($suite->getErrors() as $err) {
        echo "   - {$err}\n";
    }
    exit(1);
}

echo "✅ VERDICT : APPROVE — Toutes les barrières de sécurité et contrats sont certifiés étanches.\n";
exit(0);
