<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\PdoStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\Gamification;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\AdminService;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\LmsCatalog;
use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Course\DownloadController;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;

$appConfig = require __DIR__ . '/../config/app.php';
$coursesConfig = require __DIR__ . '/../config/courses.php';

$storage = ($appConfig['storage_driver'] === 'pdo')
    ? new PdoStorage($appConfig['pdo'], $appConfig['storage_path'])
    : new JsonStorage($appConfig['storage_path']);

$auth = new Auth($storage);
$featureManager = new FeatureManager($storage);
$adminService = new AdminService();
$courseRepo = new CourseRepository($coursesConfig, $storage->getCustomCourses());
$downloader = new DownloadController($courseRepo);
$docker = new DockerManager($storage, $appConfig['docker']);
$sqlRunner = new LabSqlRunner($docker);
$mdParser = new MarkdownParser();

$action = (string)($_GET['action'] ?? '');
$page = (string)($_GET['page'] ?? 'dashboard');
$requestedCourse = isset($_GET['course']) ? (string)$_GET['course'] : '';
$currentCourseSlug = $requestedCourse !== '' ? $requestedCourse : 'postgresql-18';
$currentCourse = $courseRepo->getCourse($currentCourseSlug) ?? $courseRepo->getCourse('postgresql-18');
if ($currentCourse !== null) {
    $currentCourseSlug = (string)$currentCourse['slug'];
}

// ============================================================================
// 1. PUBLIC AUTHENTICATION ROUTES (Login / Register / Logout)
// ============================================================================
$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if ($action === 'logout') {
    $auth->logout();
    header('Location: index.php?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Session expirée (jeton CSRF invalide). Veuillez réessayer.';
        header('Location: index.php?page=login');
        exit;
    }

    if ($auth->isLoginRateLimited($email, $clientIp)) {
        $_SESSION['flash_error'] = 'Trop de tentatives de connexion échouées pour ce compte. Veuillez patienter 5 minutes.';
        header('Location: index.php?page=login');
        exit;
    }

    $loggedUser = $auth->login($email, $password, $clientIp);
    if ($loggedUser !== null) {
        $_SESSION['flash_success'] = 'Bienvenue, ' . $loggedUser['name'] . ' !';
        header('Location: index.php?page=dashboard');
        exit;
    }
    $_SESSION['flash_error'] = 'Identifiants incorrects ou compte inactif.';
    header('Location: index.php?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    if (!$featureManager->isEnabled('self_registration')) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'L\'auto-inscription est actuellement désactivée par l\'administrateur.';
        header('Location: index.php?page=login');
        exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $company = trim((string)($_POST['company'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Jeton CSRF invalide.';
        header('Location: index.php?page=login&tab=register');
        exit;
    }

    $created = $auth->registerUser($name, $email, $password, 'student', $company);
    if ($created !== null) {
        $auth->login($email, $password, $clientIp);
        $_SESSION['flash_success'] = 'Votre compte apprenant a été créé avec succès !';
        header('Location: index.php?page=dashboard');
        exit;
    }
    $_SESSION['flash_error'] = 'Impossible de créer ce compte (vérifiez l\'email ou le mot de passe d\'au moins 6 caractères).';
    header('Location: index.php?page=login&tab=register');
    exit;
}

$currentUser = $auth->currentUser();
if ($currentUser === null && $page !== 'verify') {
    require __DIR__ . '/../templates/pages/login.php';
    exit;
}

$userId = (int)($currentUser['id'] ?? 0);

// Enforce enrolled course scoping for learners (falling back to the first enrolled course if no ?course= was specified)
if ($currentUser !== null && $currentCourseSlug !== '' && !$auth->canAccessCourse($currentUser, $currentCourseSlug)) {
    $fallbackCourse = null;
    if ($requestedCourse === '') {
        foreach ((array)($currentUser['enrolled_courses'] ?? []) as $enrolledSlug) {
            $candidate = $courseRepo->getCourse((string)$enrolledSlug);
            if ($candidate !== null && $auth->canAccessCourse($currentUser, (string)$candidate['slug'])) {
                $fallbackCourse = $candidate;
                break;
            }
        }
    }
    if ($fallbackCourse !== null) {
        $currentCourse = $fallbackCourse;
        $currentCourseSlug = (string)$fallbackCourse['slug'];
    } else {
        http_response_code(403);
        if (str_starts_with($action, 'api_')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Accès refusé (403) : vous n\'êtes pas inscrit à cette formation.']);
        } else {
            echo 'Accès refusé (403) : vous n\'êtes pas inscrit à cette formation.';
        }
        exit;
    }
}

// Initialize the session CSRF token, then release the session lock for read/API requests
Security::getCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'GET' || str_starts_with($action, 'api_')) {
    session_write_close();
}

// ============================================================================
// 2. DIRECT DOWNLOAD & COURSE ASSET ROUTES (PDF, PPTX, MD, HTML, DOCX)
// ============================================================================
if ($action === 'download') {
    $type = (string)($_GET['type'] ?? 'module');
    $itemId = (string)($_GET['id'] ?? '');
    $format = (string)($_GET['format'] ?? 'pdf');
    $inline = isset($_GET['inline']) && $_GET['inline'] === '1';

    if ($type === 'lab') {
        $classroomState = $storage->getClassroomState($currentCourseSlug);
        $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$auth->isStaff($currentUser);
        if ($solutionsLocked) {
            http_response_code(403);
            echo 'Le téléchargement et la consultation des énoncés/corrigés de labs sont temporairement verrouillés par le formateur.';
            exit;
        }
    }

    $resolved = $downloader->resolveDownloadPath($currentCourseSlug, $type, $itemId, $format);
    if ($resolved === null) {
        http_response_code(404);
        echo 'Document introuvable.';
        exit;
    }

    if (!$inline) {
        $storage->logAuditEvent($currentUser['email'], 'DOWNLOAD', "Téléchargement {$resolved['filename']} ({$format})");
    }
    $downloader->streamFile($resolved, !$inline);
}

if ($action === 'course_asset') {
    $relPath = (string)($_GET['path'] ?? '');
    $resolved = $downloader->resolveRelativeCourseAsset($currentCourseSlug, $relPath);
    if ($resolved === null) {
        http_response_code(404);
        echo 'Ressource introuvable.';
        exit;
    }
    $downloader->streamFile($resolved, false);
}

if ($action === 'export_notes_md') {
    $prog = $storage->getUserProgress($userId, $currentCourseSlug);
    $notes = $prog['notes'] ?? [];
    $mdLines = [
        '# Carnet de Notes & REX Personnel — ' . ($currentCourse['title'] ?? 'PostgreSQL 18'),
        '',
        '- **Apprenant :** ' . $currentUser['name'] . ' (' . $currentUser['email'] . ')',
        '- **Date d\'export :** ' . date('Y-m-d H:i:s'),
        '',
        '---',
        '',
    ];
    if (empty($notes)) {
        $mdLines[] = '_Aucune note enregistrée pour le moment._';
    } else {
        foreach ($notes as $itemKey => $noteContent) {
            $itemTitle = $currentCourse['modules'][$itemKey]['title']
                ?? $currentCourse['labs'][$itemKey]['title']
                ?? $itemKey;
            $mdLines[] = '## ' . $itemTitle . ' (`' . $itemKey . '`)';
            $mdLines[] = '';
            $mdLines[] = trim((string)$noteContent);
            $mdLines[] = '';
            $mdLines[] = '---';
            $mdLines[] = '';
        }
    }
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="notes_postgresql_18_' . preg_replace('/[^a-z0-9]/i', '_', strtolower($currentUser['name'])) . '.md"');
    echo implode("\n", $mdLines);
    exit;
}

if ($action === 'export_scorm_manifest' && $auth->isStaff($currentUser)) {
    if (!$featureManager->isEnabled('scorm_export')) {
        http_response_code(403);
        echo 'Export SCORM désactivé dans les réglages système.';
        exit;
    }
    $manifestXml = LmsCatalog::generateScormManifest($currentCourse ?? []);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="imsmanifest.xml"');
    echo $manifestXml;
    exit;
}

if ($action === 'export_scorm_zip' && $auth->isStaff($currentUser)) {
    if (!$featureManager->isEnabled('scorm_export')) {
        http_response_code(403);
        echo 'Export SCORM désactivé dans les réglages système.';
        exit;
    }
    $zipPath = $downloader->buildScorm12ZipPackage($currentCourseSlug, $currentCourse ?? []);
    if ($zipPath === null || !is_file($zipPath)) {
        http_response_code(500);
        echo 'Erreur lors de la génération du package SCORM 1.2 (.zip).';
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="scorm12_' . $currentCourseSlug . '.zip"');
    header('Content-Length: ' . (int)filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if ($action === 'export_attendance_csv' && $auth->isStaff($currentUser)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="registre_emargement_opco_' . $currentCourseSlug . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'User_ID', 'Nom', 'Email', 'Entreprise', 'Role', 'Demi_Journee',
            'Emarge_Le', 'Adresse_IP', 'Empreinte_SHA256',
            'Contre_Signe_Le', 'Nom_Formateur', 'Visa_Formateur_Hash',
        ], ';');
        foreach ($storage->getUsers() as $u) {
            $uid = (int)$u['id'];
            $uRole = (string)($u['role'] ?? 'student');
            $isStaff = $auth->isStaff($u);
            $attRecords = $storage->getUserAttendance($uid, $currentCourseSlug);
            if (empty($attRecords)) {
                // FIX-13 & LMS-38-02: Include enrolled users without signatures while distinguishing staff vs learner
                fputcsv($out, [
                    $uid,
                    Security::sanitizeCsvCell($u['name'] ?? ''),
                    Security::sanitizeCsvCell($u['email'] ?? ''),
                    Security::sanitizeCsvCell($u['company'] ?? ''),
                    Security::sanitizeCsvCell($uRole),
                    $isStaff ? 'PERSONNEL FORMATEUR' : 'AUCUNE (ABSENT)',
                    $isStaff ? 'STAFF (N/A)' : 'ABSENT (0h)',
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                ], ';');
                continue;
            }
            foreach ($attRecords as $hdKey => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                fputcsv($out, [
                    $uid,
                    Security::sanitizeCsvCell($u['name'] ?? ''),
                    Security::sanitizeCsvCell($u['email'] ?? ''),
                    Security::sanitizeCsvCell($u['company'] ?? ''),
                    Security::sanitizeCsvCell($uRole),
                    Security::sanitizeCsvCell($rec['half_day_key'] ?? (string)$hdKey),
                    Security::sanitizeCsvCell($rec['signed_at'] ?? ''),
                    Security::sanitizeCsvCell($rec['ip'] ?? ''),
                    Security::sanitizeCsvCell($rec['hash'] ?? ''),
                    Security::sanitizeCsvCell($rec['trainer_signed_at'] ?? ''),
                    Security::sanitizeCsvCell($rec['trainer_name'] ?? ''),
                    Security::sanitizeCsvCell($rec['trainer_hash'] ?? ''),
                ], ';');
            }
        }
        fclose($out);
    }
    exit;
}

if ($action === 'export_qualiopi_csv' && $auth->isStaff($currentUser)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_qualiopi_' . $currentCourseSlug . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID', 'Nom', 'Email', 'Entreprise', 'Role',
            'Modules_Valides', 'Labs_Docker_Valides', 'Quiz_QCM_Completes',
            'Diagnostic_Initial_Pct', 'Examen_Final_Best_Pct', 'Certifie_Exam',
            'Demi_Journees_Emargees', 'Heures_Emargees', 'Temps_Actif_LMS_Min',
            'Satisfaction_Moyenne_Sur_5', 'NPS_Sur_10',
        ], ';');
        foreach ($storage->getUsers() as $u) {
            $uid = (int)$u['id'];
            $p = $storage->getUserProgress($uid, $currentCourseSlug);
            $diag = $storage->getDiagnosticResult($uid, $currentCourseSlug);
            $exam = $storage->getBestExamAttempt($uid, $currentCourseSlug);
            $att = $storage->getUserAttendance($uid, $currentCourseSlug);
            $sat = $storage->getUserSatisfaction($uid, $currentCourseSlug);
            fputcsv($out, [
                $uid,
                Security::sanitizeCsvCell($u['name'] ?? ''),
                Security::sanitizeCsvCell($u['email'] ?? ''),
                Security::sanitizeCsvCell($u['company'] ?? ''),
                Security::sanitizeCsvCell($u['role'] ?? ''),
                count($p['completed_modules'] ?? []),
                count($p['completed_labs'] ?? []),
                count($p['quiz_scores'] ?? []),
                $diag !== null ? (int)$diag['percent'] : '',
                $exam !== null ? (int)$exam['percent'] : '',
                ($exam !== null && !empty($exam['passed'])) ? 'OUI' : 'NON',
                count($att),
                number_format(count($att) * 3.5, 1, '.', ''),
                (int)round(((int)($p['learning_seconds'] ?? 0)) / 60),
                $sat !== null ? $sat['average_rating'] : '',
                $sat !== null ? $sat['nps_score'] : '',
            ], ';');
        }
        fclose($out);
    }
    exit;
}

if (in_array($action, ['export_scorm_manifest', 'export_scorm_zip', 'export_attendance_csv', 'export_qualiopi_csv'], true)) {
    http_response_code(403);
    echo 'Accès refusé (403) : export réservé aux formateurs et administrateurs.';
    exit;
}

if ($action === 'export_audit_csv') {
    if (!$auth->isAdmin($currentUser)) {
        http_response_code(403);
        echo 'Accès refusé (403) : export d\'audit réservé aux administrateurs.';
        exit;
    }
    $auditCat = trim((string)($_GET['category'] ?? ''));
    $auditActor = trim((string)($_GET['actor'] ?? ''));
    $filteredAudit = $adminService->filterAuditLogs($storage->getAuditLogs(1000, true), $auditCat, $auditActor);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_qualiopi_' . $currentCourseSlug . '_' . date('Ymd_His') . '.csv"');
    echo $adminService->exportAuditLogsCsv($filteredAudit);
    exit;
}

// ============================================================================
// 3. INTERACTIVE JSON API ROUTES (Progress, Quiz, Notes, Docker, SQL, Oracle)
// ============================================================================
if (str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $payload = $_GET;
    } else {
        $rawBody = (string)file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
    }

    $csrf = (string)($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Security::verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide.']);
        exit;
    }

    // Enforce docker_labs feature flag for learner sandbox endpoints
    $dockerApiActions = [
        'api_docker_status', 'api_docker_action', 'api_seed_lab',
        'api_docker_logs', 'api_docker_stats', 'api_run_sql',
        'api_schema_inspect', 'api_run_oracle', 'api_validate_lab',
        'api_run_lab_solution',
    ];
    if (in_array($action, $dockerApiActions, true) && !$featureManager->isEnabled('docker_labs') && !$auth->isStaff($currentUser)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Les laboratoires Docker sont actuellement désactivés par l\'administrateur.']);
        exit;
    }

    switch ($action) {
        case 'api_toggle_complete':
            $itemType = (string)($payload['item_type'] ?? 'module');
            $itemId = trim((string)($payload['item_id'] ?? ''));
            $completed = (bool)($payload['completed'] ?? true);
            $validItem = ($itemId !== '') && (
                ($itemType === 'module' && $courseRepo->getModule($currentCourseSlug, $itemId) !== null)
                || ($itemType === 'lab' && $courseRepo->getLab($currentCourseSlug, $itemId) !== null)
            );
            if (!$validItem) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Élément de cours invalide ou introuvable.']);
                exit;
            }
            $storage->markItemCompleted($userId, $currentCourseSlug, $itemType, $itemId, $completed);
            $prog = $storage->getUserProgress($userId, $currentCourseSlug);
            echo json_encode([
                'success' => true,
                'completed' => $completed,
                'completed_modules_count' => count($prog['completed_modules']),
                'completed_labs_count' => count($prog['completed_labs']),
            ]);
            exit;

        case 'api_submit_quiz':
            $moduleId = trim((string)($payload['module_id'] ?? ''));
            $modObj = $moduleId !== '' ? $courseRepo->getModule($currentCourseSlug, $moduleId) : null;
            if ($modObj === null || empty($modObj['source_md_path'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Module introuvable.']);
                exit;
            }

            // Enforce authoritative server-side quiz grading on non-empty module MCQ definitions
            $parsedQuiz = $mdParser->parseModuleFile($modObj['source_md_path'], $currentCourseSlug)['quiz'] ?? [];
            if (empty($parsedQuiz)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Aucun QCM défini pour ce module.']);
                exit;
            }

            $answers = $payload['answers'] ?? null;
            if (!is_array($answers) || $answers === []) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Réponses QCM invalides ou manquantes pour la correction serveur.']);
                exit;
            }
            $total = count($parsedQuiz);
            $score = 0;
            foreach ($parsedQuiz as $qItem) {
                $qIdx = (string)($qItem['index'] ?? '');
                $given = strtoupper(trim((string)($answers[$qIdx] ?? $answers['q_' . $qIdx] ?? '')));
                if ($given !== '' && $given === strtoupper(trim((string)($qItem['correct'] ?? '')))) {
                    $score++;
                }
            }

            $storage->saveQuizScore($userId, $currentCourseSlug, $moduleId, $score, $total);
            $storage->logAuditEvent($currentUser['email'], 'QUIZ_SUBMIT', "Module {$moduleId} : {$score}/{$total}");
            $prog = $storage->getUserProgress($userId, $currentCourseSlug);
            echo json_encode([
                'success' => true,
                'quiz_result' => $prog['quiz_scores'][$moduleId] ?? null,
            ]);
            exit;

        case 'api_save_note':
            $itemId = (string)($payload['item_id'] ?? '');
            $note = (string)($payload['note'] ?? '');
            $storage->saveUserNote($userId, $currentCourseSlug, $itemId, $note);
            echo json_encode(['success' => true, 'saved_at' => date('H:i:s')]);
            exit;

        case 'api_docker_status':
            echo json_encode([
                'success' => true,
                'status' => $docker->getUserContainerStatus($userId),
            ]);
            exit;

        case 'api_docker_action':
            $cmdAction = (string)($payload['docker_cmd'] ?? 'status');
            $targetUserId = isset($payload['target_user_id']) && $auth->isStaff($currentUser)
                ? (int)$payload['target_user_id']
                : $userId;

            $res = match ($cmdAction) {
                'start' => $docker->startUserContainer($targetUserId, $currentUser['email']),
                'stop' => $docker->stopUserContainer($targetUserId, $currentUser['email']),
                'restart' => $docker->restartUserContainer($targetUserId, $currentUser['email']),
                'reset' => $docker->resetUserContainer($targetUserId, $currentUser['email']),
                default => ['success' => true, 'status' => $docker->getUserContainerStatus($targetUserId)],
            };
            echo json_encode($res);
            exit;

        case 'api_seed_lab':
            $allowSharedFallback = $auth->isStaff($currentUser);
            $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $allowSharedFallback)
                ? (int)$payload['target_user_id']
                : $userId;
            $dataset = trim((string)($payload['dataset'] ?? 'all'));
            $forceReset = !isset($payload['force_reset']) || (bool)$payload['force_reset'];
            echo json_encode($docker->seedUserContainer($targetUserId, $dataset, $forceReset, $currentUser['email'], $allowSharedFallback));
            exit;

        case 'api_docker_logs':
            $allowSharedFallback = $auth->isStaff($currentUser);
            $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $allowSharedFallback)
                ? (int)$payload['target_user_id']
                : $userId;
            $tail = max(10, min(500, (int)($payload['tail'] ?? 60)));
            echo json_encode($docker->getContainerLogs($targetUserId, $tail, $allowSharedFallback));
            exit;

        case 'api_docker_stats':
            $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $auth->isStaff($currentUser))
                ? (int)$payload['target_user_id']
                : $userId;
            echo json_encode($docker->getContainerStats($targetUserId));
            exit;

        case 'api_run_sql':
            $sql = (string)($payload['sql'] ?? '');
            $database = (string)($payload['database'] ?? 'formation_pg');
            $res = $sqlRunner->executeSql($userId, $sql, $database);
            echo json_encode($res);
            exit;

        case 'api_schema_inspect':
            $database = (string)($payload['database'] ?? 'dvdrental');
            echo json_encode($sqlRunner->inspectDatabaseSchema($userId, $database));
            exit;

        case 'api_run_oracle':
        case 'api_validate_lab':
            $labId = (string)($payload['lab_id'] ?? '');
            $database = isset($payload['database']) && $payload['database'] !== '' ? (string)$payload['database'] : null;
            $labMeta = $courseRepo->getLab($currentCourseSlug, $labId);
            $labMdPath = $labMeta['source_md_path'] ?? null;
            $res = $sqlRunner->validateLabOracle($userId, $labId, $labMdPath, $database);
            if (!empty($res['passed']) && $labId !== '') {
                $storage->markItemCompleted($userId, $currentCourseSlug, 'lab', $labId, true);
                $storage->logAuditEvent($currentUser['email'], 'LAB_ORACLE_PASS', "Validation Oracle réussie sur {$labId} ({$res['bloom']})");
            }
            echo json_encode($res);
            exit;

        case 'api_run_lab_solution':
            $classroomState = $storage->getClassroomState($currentCourseSlug);
            $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$auth->isStaff($currentUser);
            if ($solutionsLocked) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'passed' => false,
                    'error' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
                    'message' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
                ]);
                exit;
            }

            $labId = (string)($payload['lab_id'] ?? '');
            $database = isset($payload['database']) && $payload['database'] !== '' ? (string)$payload['database'] : null;
            $labMeta = $courseRepo->getLab($currentCourseSlug, $labId);
            $labMdPath = $labMeta['source_md_path'] ?? null;
            $res = $sqlRunner->runLabSolution($userId, $labId, $labMdPath, $database);
            if (!empty($res['passed']) && $labId !== '') {
                $storage->markItemCompleted($userId, $currentCourseSlug, 'lab', $labId, true);
                $storage->logAuditEvent($currentUser['email'], 'LAB_SOLUTION_TEST', "Test et validation du corrigé officiel sur {$labId}");
            }
            echo json_encode($res);
            exit;

        case 'api_heartbeat':
            $seconds = max(1, min(300, (int)($payload['seconds'] ?? 60)));
            $totalSec = $storage->recordLearningTime($userId, $currentCourseSlug, $seconds);
            echo json_encode([
                'success' => true,
                'learning_seconds' => $totalSec,
                'formatted' => Gamification::formatDurationHours($totalSec),
                'classroom_state' => $storage->getClassroomState($currentCourseSlug),
            ]);
            exit;

        case 'api_toggle_bookmark':
            $itemType = (string)($payload['item_type'] ?? 'module');
            $itemId = (string)($payload['item_id'] ?? '');
            $title = (string)($payload['title'] ?? $itemId);
            $res = $storage->toggleBookmark($userId, $currentCourseSlug, $itemType, $itemId, $title);
            echo json_encode(['success' => true] + $res);
            exit;

        case 'api_flashcard_rate':
            $cardId = (string)($payload['card_id'] ?? '');
            $level = (int)($payload['level'] ?? 2);
            $storage->saveFlashcardConfidence($userId, $currentCourseSlug, $cardId, $level);
            echo json_encode(['success' => true, 'card_id' => $cardId, 'level' => $level]);
            exit;

        case 'api_reset_flashcards':
            $res = $storage->resetFlashcardReviews($userId, $currentCourseSlug);
            echo json_encode(['success' => $res, 'message' => 'Évaluations SRS réinitialisées.']);
            exit;

        case 'api_post_discussion':
            if (!$featureManager->isEnabled('qna_forum')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Le forum Q&A est actuellement désactivé.']);
                exit;
            }
            $itemId = trim((string)($payload['item_id'] ?? ''));
            $message = trim((string)($payload['message'] ?? ''));
            $parentId = isset($payload['parent_id']) ? (string)$payload['parent_id'] : null;
            if ($itemId === '' || $message === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Message vide ou cible invalide.']);
                exit;
            }
            $entry = $storage->addDiscussionMessage(
                $currentCourseSlug,
                $itemId,
                $userId,
                $currentUser['name'],
                $currentUser['role'],
                $message,
                $parentId
            );
            echo json_encode(['success' => true, 'entry' => $entry]);
            exit;

        case 'api_search':
            $q = (string)($payload['q'] ?? ($_GET['q'] ?? ''));
            echo json_encode([
                'success' => true,
                'results' => $courseRepo->searchContent($currentCourseSlug, $q),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;

        case 'api_chaos':
            if (!$auth->isStaff($currentUser)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Accès réservé aux formateurs et administrateurs.']);
                exit;
            }
            if (!$featureManager->isEnabled('chaos_engineering')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Le simulateur Chaos DBA est actuellement désactivé.']);
                exit;
            }
            $scenario = (string)($payload['scenario'] ?? 'bloat');
            $chaosAction = (string)($payload['chaos_action'] ?? 'inject');
            echo json_encode($docker->triggerChaosScenario($scenario, $chaosAction, $currentUser['email']));
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action API inconnue.']);
            exit;
    }
}

// ============================================================================
// 4. ADMINISTRATION & PROFILE POST ACTIONS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Jeton CSRF invalide.';
        header('Location: index.php?page=' . urlencode($page));
        exit;
    }

    if ($action === 'update_profile') {
        $currentUser['name'] = trim((string)($_POST['name'] ?? $currentUser['name']));
        $currentUser['company'] = trim((string)($_POST['company'] ?? $currentUser['company']));
        $storage->saveUser($currentUser);

        $newPass = (string)($_POST['new_password'] ?? '');
        if ($newPass !== '') {
            $currentPass = (string)($_POST['current_password'] ?? '');
            $pwRes = $auth->changePassword($userId, $currentPass, $newPass);
            if (empty($pwRes['success'])) {
                $_SESSION['flash_error'] = (string)($pwRes['message'] ?? 'Mot de passe actuel invalide ou nouveau mot de passe trop court.');
                header('Location: index.php?page=profile');
                exit;
            }
        }
        $_SESSION['flash_success'] = 'Votre profil a été mis à jour avec succès.';
        header('Location: index.php?page=profile');
        exit;
    }

    if ($action === 'submit_diagnostic') {
        $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
        $eval = LmsCatalog::evaluateDiagnostic($answers);
        $storage->saveDiagnosticResult($userId, $currentCourseSlug, $eval);
        $storage->logAuditEvent($currentUser['email'], 'DIAGNOSTIC_SUBMIT', "Positionnement Qualiopi : {$eval['percent']}% ({$eval['recommended_track']})");
        $_SESSION['flash_success'] = "Positionnement Qualiopi enregistré ({$eval['percent']}%) — Parcours recommandé : {$eval['recommended_title']}.";
        header('Location: index.php?page=diagnostic&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'submit_exam') {
        $classroomState = $storage->getClassroomState($currentCourseSlug);
        if (!empty($classroomState['lock_exam']) && !$auth->isStaff($currentUser)) {
            $_SESSION['flash_error'] = 'L\'examen final est actuellement verrouillé par le formateur.';
            header('Location: index.php?page=exam&course=' . urlencode($currentCourseSlug));
            exit;
        }
        $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
        $durationSec = (int)($_POST['duration_seconds'] ?? 0);
        $eval = LmsCatalog::evaluateFinalExam($answers, $durationSec);
        $savedAttempt = $storage->saveExamAttempt($userId, $currentCourseSlug, $eval);
        $storage->logAuditEvent($currentUser['email'], 'FINAL_EXAM_SUBMIT', "Examen Final : {$eval['percent']}% ({$eval['mention']}) — Certificat {$savedAttempt['certificate_id']}");
        if ($eval['passed']) {
            $_SESSION['flash_success'] = "Félicitations ! Vous avez réussi l'Examen Final de Certification PostgreSQL 18 avec {$eval['percent']}% ({$eval['mention']}).";
        } else {
            $_SESSION['flash_error'] = "Score obtenu : {$eval['percent']}% (seuil requis : 75%). Consultez le détail de vos réponses ci-dessous et réessayez.";
        }
        header('Location: index.php?page=exam&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'sign_attendance') {
        $halfDayKey = trim((string)($_POST['half_day_key'] ?? ''));
        $sigText = trim((string)($_POST['signature_text'] ?? $currentUser['name']));
        if (Security::isValidHalfDayKey($halfDayKey)) {
            $storage->signAttendance($userId, $currentCourseSlug, $halfDayKey, $sigText, (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
            $storage->logAuditEvent($currentUser['email'], 'ATTENDANCE_SIGN', "Émargement demi-journée {$halfDayKey}");
            $_SESSION['flash_success'] = "Présence émargée avec succès pour la demi-journée {$halfDayKey}.";
        } else {
            $_SESSION['flash_error'] = "Créneau de demi-journée Qualiopi invalide.";
        }
        header('Location: index.php?page=attendance&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'sign_trainer_attendance' && $auth->isStaff($currentUser)) {
        $targetUid = (int)($_POST['target_user_id'] ?? $userId);
        $halfDayKey = trim((string)($_POST['half_day_key'] ?? 'ALL'));
        $storage->signTrainerAttendance($targetUid, $currentCourseSlug, $halfDayKey, $currentUser['name']);
        $storage->logAuditEvent($currentUser['email'], 'TRAINER_ATTENDANCE_COUNTERSIGN', "Contre-signature formateur ({$halfDayKey}) pour cible #{$targetUid}");
        $_SESSION['flash_success'] = "Contre-signature formateur Qualiopi apposée avec succès ({$halfDayKey}).";
        $redirectUrl = 'index.php?page=attendance&course=' . urlencode($currentCourseSlug);
        $returnStudentId = (int)($_POST['return_student_id'] ?? 0);
        if ($returnStudentId > 0) {
            $redirectUrl .= '&student_id=' . $returnStudentId;
        }
        $returnTrack = trim((string)($_POST['return_track'] ?? ''));
        if ($returnTrack !== '') {
            $redirectUrl .= '&track=' . urlencode($returnTrack);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'submit_satisfaction') {
        $storage->saveSatisfactionSurvey($userId, $currentCourseSlug, $_POST);
        $storage->logAuditEvent($currentUser['email'], 'SATISFACTION_SUBMIT', "Questionnaire de satisfaction à chaud Qualiopi complété");
        $_SESSION['flash_success'] = 'Merci ! Votre évaluation de satisfaction Qualiopi a été enregistrée.';
        header('Location: index.php?page=satisfaction&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'submit_cold_satisfaction') {
        if (!$featureManager->isEnabled('cold_satisfaction_j60')) {
            http_response_code(403);
            echo 'Le questionnaire d\'impact à froid J+60 est actuellement désactivé.';
            exit;
        }
        $storage->saveColdSatisfactionSurvey($userId, $currentCourseSlug, $_POST);
        $storage->logAuditEvent($currentUser['email'], 'COLD_SATISFACTION_SUBMIT', "Questionnaire d'impact à froid J+60 Qualiopi complété");
        $_SESSION['flash_success'] = 'Merci ! Votre évaluation d\'impact métier à froid (J+60) a été enregistrée.';
        header('Location: index.php?page=satisfaction&tab=cold&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'save_note_form') {
        $itemId = trim((string)($_POST['item_id'] ?? ''));
        $note = (string)($_POST['note'] ?? '');
        if ($itemId !== '') {
            $storage->saveUserNote($userId, $currentCourseSlug, $itemId, $note);
            $_SESSION['flash_success'] = 'Note personnelle enregistrée dans votre carnet.';
        }
        header('Location: index.php?page=notes&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'save_classroom_state' && $auth->isStaff($currentUser)) {
        $storage->saveClassroomState($currentCourseSlug, [
            'broadcast_message' => trim((string)($_POST['broadcast_message'] ?? '')),
            'broadcast_level' => (string)($_POST['broadcast_level'] ?? 'info'),
            'lock_solutions' => isset($_POST['lock_solutions']) && $_POST['lock_solutions'] === '1',
            'lock_exam' => isset($_POST['lock_exam']) && $_POST['lock_exam'] === '1',
            'active_track' => (string)($_POST['active_track'] ?? 'cursus-complet'),
            'updated_by' => $currentUser['name'],
        ]);
        $storage->logAuditEvent($currentUser['email'], 'CLASSROOM_STATE_UPDATE', 'Mise à jour du mode Salle de Classe Live & Verrous');
        $_SESSION['flash_success'] = 'Configuration de la Salle de Classe Live mise à jour pour tous les stagiaires.';
        header('Location: index.php?page=admin&tab=cohort&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if ($action === 'moderate_discussion' && $auth->isStaff($currentUser)) {
        $itemId = trim((string)($_POST['item_id'] ?? ''));
        $msgId = trim((string)($_POST['message_id'] ?? ''));
        $modAction = trim((string)($_POST['mod_action'] ?? 'resolve'));
        $rawReturnPage = trim((string)($_POST['return_page'] ?? 'admin'));
        $returnPage = in_array($rawReturnPage, ['admin', 'module', 'lab'], true) ? $rawReturnPage : 'admin';
        if ($itemId !== '' && $msgId !== '') {
            if ($modAction === 'delete') {
                $storage->deleteDiscussionMessage($currentCourseSlug, $itemId, $msgId);
                $_SESSION['flash_success'] = 'Message supprimé du fil Q&A.';
            } else {
                $storage->resolveDiscussionMessage($currentCourseSlug, $itemId, $msgId, true);
                $_SESSION['flash_success'] = 'Question marquée comme « ✔ Résolue » par le formateur.';
            }
        }
        if ($returnPage === 'admin') {
            header('Location: index.php?page=admin&tab=qna&course=' . urlencode($currentCourseSlug));
        } else {
            header('Location: index.php?page=' . urlencode($returnPage) . '&id=' . urlencode($itemId) . '&course=' . urlencode($currentCourseSlug) . '#discussion-section');
        }
        exit;
    }

    if ($action === 'admin_bulk_docker' && $auth->isStaff($currentUser)) {
        $bulkCmd = trim((string)($_POST['bulk_cmd'] ?? 'refresh_runtime'));
        if ($bulkCmd === 'stop_all') {
            $res = $docker->stopAllLabContainers($currentUser['email']);
            $_SESSION['flash_success'] = (string)($res['message'] ?? 'Conteneurs arrêtés.');
        } elseif ($bulkCmd === 'prune_stopped') {
            $res = $docker->pruneStoppedLabContainers($currentUser['email']);
            $_SESSION['flash_success'] = (string)($res['message'] ?? 'Conteneurs arrêtés purgés.');
        } else {
            $rt = $docker->detectRuntime(true);
            $storage->logAuditEvent($currentUser['email'], 'DOCKER_RUNTIME_REFRESH', 'Rafraîchissement du runtime Docker (' . (string)($rt['mode'] ?? 'unknown') . ')');
            $_SESSION['flash_success'] = 'Statut du démon Docker rafraîchi (' . (string)($rt['version'] ?? $rt['mode'] ?? 'OK') . ').';
        }
        header('Location: index.php?page=admin&tab=docker&course=' . urlencode($currentCourseSlug));
        exit;
    }

    if (in_array($action, ['sign_trainer_attendance', 'save_classroom_state', 'moderate_discussion', 'admin_bulk_docker'], true) && !$auth->isStaff($currentUser)) {
        http_response_code(403);
        echo 'Accès refusé (403) : action réservée aux formateurs et administrateurs.';
        exit;
    }

    if ($action === 'post_discussion') {
        if (!$featureManager->isEnabled('qna_forum')) {
            http_response_code(403);
            echo 'Le forum Q&A est actuellement désactivé.';
            exit;
        }
        $itemId = trim((string)($_POST['item_id'] ?? ''));
        $rawReturnPage = trim((string)($_POST['return_page'] ?? 'module'));
        $returnPage = in_array($rawReturnPage, ['module', 'lab'], true) ? $rawReturnPage : 'module';
        $message = trim((string)($_POST['message'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? trim((string)$_POST['parent_id']) : null;
        if ($itemId !== '' && $message !== '') {
            $storage->addDiscussionMessage($currentCourseSlug, $itemId, $userId, $currentUser['name'], $currentUser['role'], $message, $parentId);
            $_SESSION['flash_success'] = 'Votre question / commentaire technique a été publié.';
        }
        header('Location: index.php?page=' . urlencode($returnPage) . '&id=' . urlencode($itemId) . '&course=' . urlencode($currentCourseSlug) . '#discussion-section');
        exit;
    }

    if ($auth->isAdmin($currentUser)) {
        // FIX-16: Purge both catalog and parsed Markdown caches
        if ($action === 'admin_clear_cache') {
            $catCleared = $courseRepo->clearCatalogCache();
            $parsedCleared = $mdParser->clearParsedCache();
            $storage->logAuditEvent($currentUser['email'], 'ADMIN_CACHE_CLEAR', "Purge des caches : catalogue ({$catCleared}) et markdown ({$parsedCleared})");
            $_SESSION['flash_success'] = "Caches purgés avec succès : {$catCleared} cache(s) catalogue et {$parsedCleared} fichier(s) Markdown réinitialisés.";
            header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
            exit;
        }

        if ($action === 'admin_save_features') {
            $submitted = is_array($_POST['features'] ?? null) ? $_POST['features'] : [];
            $featureManager->updateFlags($submitted, $currentUser['email']);
            $_SESSION['flash_success'] = 'Feature flags mis à jour et audités avec succès.';
            header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
            exit;
        }

        if ($action === 'admin_create_user') {
            $uName = trim((string)($_POST['name'] ?? ''));
            $uEmail = trim((string)($_POST['email'] ?? ''));
            $uPass = (string)($_POST['password'] ?? '');
            $uRole = trim((string)($_POST['role'] ?? 'student'));
            $uComp = trim((string)($_POST['company'] ?? ''));

            if (!$auth->isValidRole($uRole)) {
                $_SESSION['flash_error'] = 'Rôle utilisateur invalide.';
                header('Location: index.php?page=admin&tab=users');
                exit;
            }
            if (strlen($uPass) < 6) {
                $_SESSION['flash_error'] = 'Le mot de passe initial doit contenir au moins 6 caractères.';
                header('Location: index.php?page=admin&tab=users');
                exit;
            }

            $created = $auth->registerUser($uName, $uEmail, $uPass, $uRole, $uComp, [$currentCourseSlug]);
            if ($created !== null) {
                $_SESSION['flash_success'] = "Utilisateur {$uName} ({$uRole}) créé avec succès.";
            } else {
                $_SESSION['flash_error'] = "Impossible de créer l'utilisateur (email déjà existant ou invalide).";
            }
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_update_user') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $targetUser = $storage->getUserById($targetId);
            if ($targetUser !== null) {
                $targetUser['name'] = trim((string)($_POST['name'] ?? $targetUser['name']));
                $targetUser['company'] = trim((string)($_POST['company'] ?? $targetUser['company']));
                if ($targetId !== 1) {
                    $newRole = trim((string)($_POST['role'] ?? $targetUser['role']));
                    if (!$auth->isValidRole($newRole)) {
                        $_SESSION['flash_error'] = 'Rôle utilisateur invalide.';
                        header('Location: index.php?page=admin&tab=users');
                        exit;
                    }
                    $targetUser['role'] = $newRole;
                    $targetUser['active'] = isset($_POST['active']) && $_POST['active'] === '1';
                }
                $resetPass = trim((string)($_POST['reset_password'] ?? ''));
                if (strlen($resetPass) >= 6) {
                    $targetUser['password_hash'] = password_hash($resetPass, PASSWORD_BCRYPT);
                }
                $storage->saveUser($targetUser);
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_USER_UPDATE', "Modification du compte #{$targetId} ({$targetUser['email']})");
                $_SESSION['flash_success'] = "Compte de {$targetUser['name']} mis à jour.";
            }
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_update_enrollments') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $targetUser = $storage->getUserById($targetId);
            if ($targetUser !== null) {
                $knownCourses = array_keys($courseRepo->getAllCourses());
                $submittedCourses = array_values(array_intersect(
                    array_map('strval', (array)($_POST['enrolled_courses'] ?? [])),
                    $knownCourses
                ));
                $targetUser['enrolled_courses'] = $submittedCourses;
                $storage->saveUser($targetUser);
                $storage->logAuditEvent(
                    $currentUser['email'],
                    'ADMIN_ENROLLMENT_UPDATE',
                    "Inscriptions mises à jour pour #{$targetId} ({$targetUser['email']}) : " . implode(', ', $submittedCourses)
                );
                $_SESSION['flash_success'] = "Inscriptions aux formations mises à jour pour {$targetUser['name']}.";
            }
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_reset_user_progress') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $targetCourse = trim((string)($_POST['target_course'] ?? $currentCourseSlug));
            if ($targetId > 0 && $storage->resetUserProgress($targetId, $targetCourse)) {
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_PROGRESS_RESET', "Réinitialisation progression utilisateur #{$targetId} sur {$targetCourse}");
                $_SESSION['flash_success'] = "Progression de l'utilisateur #{$targetId} réinitialisée sur « {$targetCourse} ».";
            } else {
                $_SESSION['flash_error'] = "Impossible de réinitialiser la progression pour l'utilisateur #{$targetId}.";
            }
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_delete_user') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            if ($storage->deleteUser($targetId)) {
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_USER_DELETE', "Suppression du compte #{$targetId}");
                $_SESSION['flash_success'] = "Compte utilisateur #{$targetId} supprimé.";
            } else {
                $_SESSION['flash_error'] = "Impossible de supprimer ce compte (le Super-Admin est protégé).";
            }
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_import_users_csv') {
            $csvRaw = trim((string)($_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')));
            $defaultPw = trim((string)($_POST['default_password'] ?? ''));
            if (strlen($defaultPw) < 6) {
                $defaultPw = bin2hex(random_bytes(8)) . '!Lp1';
            }
            $importRes = $storage->importUsersFromCsv($csvRaw, $defaultPw, $currentCourseSlug);
            $storage->logAuditEvent($currentUser['email'], 'ADMIN_CSV_IMPORT', "Import cohorte CSV : {$importRes['created']} créés, {$importRes['skipped']} ignorés");
            $_SESSION['flash_success'] = "Import CSV terminé : {$importRes['created']} compte(s) créé(s), {$importRes['skipped']} ligne(s) ignorée(s).";
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_toggle_course') {
            $cSlug = trim((string)($_POST['course_slug'] ?? ''));
            $newActive = isset($_POST['active']) && $_POST['active'] === '1';
            if ($cSlug !== '' && $storage->toggleCustomCourse($cSlug, $newActive)) {
                $courseRepo->clearCatalogCache($cSlug);
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_COURSE_TOGGLE', "Formation {$cSlug} : " . ($newActive ? 'activée' : 'désactivée'));
                $_SESSION['flash_success'] = "Statut de la formation « {$cSlug} » mis à jour.";
            } else {
                $_SESSION['flash_error'] = "Formation personnalisée introuvable ({$cSlug}).";
            }
            header('Location: index.php?page=admin&tab=courses');
            exit;
        }

        if ($action === 'admin_delete_course') {
            $cSlug = trim((string)($_POST['course_slug'] ?? ''));
            if ($cSlug !== '' && $storage->deleteCustomCourse($cSlug)) {
                $courseRepo->clearCatalogCache($cSlug);
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_COURSE_DELETE', "Suppression de la formation personnalisée {$cSlug}");
                $_SESSION['flash_success'] = "Formation personnalisée « {$cSlug} » supprimée.";
            } else {
                $_SESSION['flash_error'] = "Impossible de supprimer cette formation.";
            }
            header('Location: index.php?page=admin&tab=courses');
            exit;
        }

        if ($action === 'admin_add_course') {
            $cSlug = trim((string)preg_replace('/[^a-zA-Z0-9\-]/', '-', strtolower(trim((string)($_POST['slug'] ?? '')))), '-');
            $cTitle = trim((string)($_POST['title'] ?? ''));
            $cSource = trim((string)($_POST['source_root'] ?? ''));
            $cCat = trim((string)($_POST['category'] ?? 'Infrastructure & Cloud'));
            $cDur = trim((string)($_POST['duration'] ?? '35h (5 jours)'));

            if ($cSlug === 'postgresql-18' || isset($coursesConfig[$cSlug])) {
                $_SESSION['flash_error'] = "Identifiant réservé : impossible de remplacer la formation noyau « {$cSlug} ».";
                header('Location: index.php?page=admin&tab=courses');
                exit;
            }

            $defaultSourceRoot = (string)($coursesConfig['postgresql-18']['source_root'] ?? '');
            $allowedWorkspaceDir = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
            $resolvedSourceRoot = $defaultSourceRoot;
            if ($cSource !== '') {
                $realCandidate = realpath($cSource);
                if ($realCandidate !== false && is_dir($realCandidate) && Security::isPathInside($realCandidate, $allowedWorkspaceDir)) {
                    $resolvedSourceRoot = $realCandidate;
                }
            }

            if ($cSlug !== '' && $cTitle !== '') {
                $storage->saveCustomCourse([
                    'slug' => $cSlug,
                    'title' => $cTitle,
                    'short_title' => $cTitle,
                    'badge' => strtoupper($cSlug),
                    'category' => $cCat,
                    'level' => 'Professionnel (Qualiopi)',
                    'duration' => $cDur,
                    'instructor' => $appConfig['trainer_name'],
                    'icon' => 'assets/img/lightpath_logo_dark_text.png',
                    'active' => true,
                    'docker_enabled' => true,
                    'source_root' => $resolvedSourceRoot,
                    'pgscripts_root' => '',
                    'description' => trim((string)($_POST['description'] ?? 'Formation professionnelle LightPath SARL.')),
                    'tracks' => [],
                ]);
                $courseRepo->clearCatalogCache($cSlug);
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_COURSE_ADD', "Ajout de la formation '{$cTitle}' ({$cSlug})");
                $_SESSION['flash_success'] = "Nouvelle formation « {$cTitle} » enregistrée dans le portail multi-formations !";
            }
            header('Location: index.php?page=admin&tab=courses');
            exit;
        }
    }

    if (str_starts_with($action, 'admin_') && !$auth->isAdmin($currentUser)) {
        http_response_code(403);
        echo 'Accès refusé (403) : action réservée aux administrateurs.';
        exit;
    }
}

// ============================================================================
// 5. GLOBAL CONTEXT PREPARATION & PAGE RENDERING
// ============================================================================
if ($page === 'admin') {
    if (!$auth->isStaff($currentUser)) {
        http_response_code(403);
        echo 'Accès refusé (403) : espace réservé aux formateurs et administrateurs.';
        exit;
    }
    $requestedAdminTab = (string)($_GET['tab'] ?? 'cohort');
    if (in_array($requestedAdminTab, ['users', 'courses', 'audit', 'system'], true) && !$auth->isAdmin($currentUser)) {
        http_response_code(403);
        echo 'Accès refusé (403) : cet onglet d\'administration est réservé aux administrateurs.';
        exit;
    }
}
if ($page === 'module' && !empty($_GET['id'])) {
    $mId = (string)$_GET['id'];
    $mObj = $courseRepo->getModule($currentCourseSlug, $mId);
    if ($mObj !== null) {
        $storage->recordLastVisited($userId, $currentCourseSlug, 'module', $mId, $mObj['title'], (string)($_GET['mode'] ?? 'reader'));
    }
} elseif ($page === 'lab' && !empty($_GET['id'])) {
    $lId = (string)$_GET['id'];
    $lObj = $courseRepo->getLab($currentCourseSlug, $lId);
    if ($lObj !== null) {
        $storage->recordLastVisited($userId, $currentCourseSlug, 'lab', $lId, $lObj['title'], (string)($_GET['mode'] ?? 'enonce'));
    }
}

$totalModules = count($currentCourse['modules'] ?? []);
$totalLabs = count($currentCourse['labs'] ?? []);
$userProgress = $userId > 0 ? $storage->getUserProgress($userId, $currentCourseSlug) : ['completed_modules' => [], 'completed_labs' => [], 'quiz_scores' => []];
$userDiagnostic = $userId > 0 ? $storage->getDiagnosticResult($userId, $currentCourseSlug) : null;
$userBestExam = $userId > 0 ? $storage->getBestExamAttempt($userId, $currentCourseSlug) : null;
$userAttendance = $userId > 0 ? $storage->getUserAttendance($userId, $currentCourseSlug) : [];
$userGamification = Gamification::computeUserBadgesAndXp($userProgress, $userDiagnostic, $userBestExam, $userAttendance, $totalModules, $totalLabs);

$dockerStatus = $userId > 0 ? $docker->getCachedUserContainerStatus($userId) : ['running' => false, 'container_name' => 'aucun', 'port' => 0, 'connection_string' => ''];
$classroomState = $storage->getClassroomState($currentCourseSlug);
$allCourses = $courseRepo->getAllCourses();

$doneModules = count($userProgress['completed_modules'] ?? []);
$doneLabs = count($userProgress['completed_labs'] ?? []);
$totalItems = max(1, $totalModules + $totalLabs);
$globalCompletionPct = min(100, (int)round((($doneModules + $doneLabs) / $totalItems) * 100));

require __DIR__ . '/../templates/layout.php';
