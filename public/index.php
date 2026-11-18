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
use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Course\DownloadController;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;
use LightPath\LMS\Http\AuthController;
use LightPath\LMS\Http\LearnerController;
use LightPath\LMS\Http\LabApiController;
use LightPath\LMS\Http\AdminController;

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
$action = (string)($_GET['action'] ?? '');
$page = (string)($_GET['page'] ?? 'dashboard');
$requestedCourse = isset($_GET['course']) ? (string)$_GET['course'] : '';
$currentCourseSlug = $requestedCourse !== '' ? $requestedCourse : 'postgresql-18';
if ($page === 'verify' && $requestedCourse === '' && !empty($_GET['code'])) {
    $rawVerifyCode = trim((string)$_GET['code']);
    $verifyLookup = $storage->findCertificateByCode($rawVerifyCode);
    if (is_array($verifyLookup) && !empty($verifyLookup['course_slug'])) {
        $currentCourseSlug = (string)$verifyLookup['course_slug'];
    } elseif (str_starts_with(strtoupper($rawVerifyCode), 'LP-GITBAS-')) {
        $currentCourseSlug = 'git-basics';
    }
}
$currentCourse = $courseRepo->getCourse($currentCourseSlug) ?? $courseRepo->getCourse('postgresql-18');
if ($currentCourse !== null) {
    $currentCourseSlug = (string)$currentCourse['slug'];
}

$mdParser = new MarkdownParser($currentCourse['source_root'] ?? null);

$authCtrl = new AuthController($auth, $featureManager);
$learnerCtrl = new LearnerController($storage, $auth, $featureManager, $courseRepo, $downloader, $mdParser);
$labApiCtrl = new LabApiController($storage, $auth, $featureManager, $courseRepo, $docker, $sqlRunner);
$adminCtrl = new AdminController($storage, $auth, $featureManager, $adminService, $courseRepo, $docker, $mdParser);

$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// 1. Public authentication routes
if ($action === 'logout') {
    $authCtrl->handleLogout();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $authCtrl->handleLoginPost($_POST, $_SERVER);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $authCtrl->handleRegisterPost($_POST, $_SERVER);
}

$currentUser = $auth->currentUser();
if ($currentUser === null && $page !== 'verify') {
    require __DIR__ . '/../templates/pages/login.php';
    exit;
}

$userId = (int)($currentUser['id'] ?? 0);
$preAccessSlug = $currentCourseSlug;
$authCtrl->enforceCourseAccess($currentUser, $courseRepo, $currentCourseSlug, $currentCourse, $requestedCourse, $action);
if ($currentCourseSlug !== $preAccessSlug) {
    $mdParser = new MarkdownParser($currentCourse['source_root'] ?? null);
    $learnerCtrl = new LearnerController($storage, $auth, $featureManager, $courseRepo, $downloader, $mdParser);
    $adminCtrl = new AdminController($storage, $auth, $featureManager, $adminService, $courseRepo, $docker, $mdParser);
}

// Initialize session CSRF token, then release the session file lock on read/API traffic.
Security::getCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'GET' || str_starts_with($action, 'api_')) {
    session_write_close();
}

// 2. Direct download, asset, SCORM, attendance CSV, and export routes
if ($action === 'download') {
    $type = (string)($_GET['type'] ?? 'module');
    if ($type === 'lab') {
        $classroomState = $storage->getClassroomState($currentCourseSlug);
        $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$auth->isStaff($currentUser);
        if ($solutionsLocked) {
            http_response_code(403);
            echo 'Le téléchargement et la consultation des énoncés/corrigés de labs sont temporairement verrouillés par le formateur.';
            exit;
        }
    }
    $learnerCtrl->handleDownload($_GET, $currentUser, $currentCourseSlug);
}

if ($action === 'course_asset') {
    $learnerCtrl->handleCourseAsset($_GET, $currentCourseSlug);
}

if ($action === 'export_notes_md') {
    $learnerCtrl->handleExportNotesMarkdown($currentUser, $currentCourse ?? [], $currentCourseSlug);
}

if ($action === 'export_scorm_manifest' && $auth->isStaff($currentUser)) {
    $learnerCtrl->handleExportScormManifest($currentCourse ?? [], $currentCourseSlug);
}

if ($action === 'export_scorm_zip' && $auth->isStaff($currentUser)) {
    $learnerCtrl->handleExportScormZip($currentCourse ?? [], $currentCourseSlug);
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
                fputcsv($out, [
                    $uid,
                    Security::sanitizeCsvCell($u['name'] ?? ''),
                    Security::sanitizeCsvCell($u['email'] ?? ''),
                    Security::sanitizeCsvCell($u['company'] ?? ''),
                    Security::sanitizeCsvCell($uRole),
                    $isStaff ? 'PERSONNEL FORMATEUR' : 'AUCUNE (ABSENT)',
                    $isStaff ? 'STAFF (N/A)' : 'ABSENT (0h)',
                    '—', '—', '—', '—', '—',
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

if (in_array($action, ['export_scorm_manifest', 'export_scorm_zip', 'export_attendance_csv'], true)) {
    http_response_code(403);
    echo 'Accès refusé (403) : export réservé aux formateurs et administrateurs.';
    exit;
}

$adminCtrl->handleExportAction($action, $_GET, $currentUser ?? [], $currentCourse ?? [], $currentCourseSlug);

// 3. JSON API dispatch (HTTP Method + Same-Origin + CSRF protected)
if (str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=UTF-8');
    $readOnlyApis = ['api_docker_status', 'api_docker_logs', 'api_docker_stats', 'api_schema_inspect', 'api_lab_pg_diag', 'api_lab_readiness', 'api_search'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!in_array($action, $readOnlyApis, true)) {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['success' => false, 'message' => 'Méthode HTTP non autorisée : utilisez POST pour cette action API.']);
            exit;
        }
        $payload = $_GET;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::verifySameOriginRequest($_SERVER)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Requête cross-origin refusée.']);
            exit;
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
    } else {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'message' => 'Méthode HTTP non autorisée.']);
        exit;
    }

    $csrf = (string)($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Security::verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide.']);
        exit;
    }

    if ($action === 'api_submit_quiz') {
        $moduleId = trim((string)($payload['module_id'] ?? ''));
        $modObj = $moduleId !== '' ? $courseRepo->getModule($currentCourseSlug, $moduleId) : null;
        if ($modObj === null || empty($modObj['source_md_path'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Module introuvable.']);
            exit;
        }
        $parsedQuiz = $mdParser->parseModuleFile($modObj['source_md_path'], $currentCourseSlug)['quiz'] ?? [];
        $learnerCtrl->completeQuizSubmission($parsedQuiz, $payload, $moduleId, $currentUser, $currentCourseSlug);
    }

    if ($action === 'api_heartbeat') {
        $seconds = max(1, min(300, (int)($payload['seconds'] ?? 60)));
        $totalSec = $storage->recordLearningTime($userId, $currentCourseSlug, $seconds);
        echo json_encode([
            'success' => true,
            'learning_seconds' => $totalSec,
            'formatted' => Gamification::formatDurationHours($totalSec),
            'classroom_state' => $storage->getClassroomState($currentCourseSlug),
        ]);
        exit;
    }

    if ($action === 'api_run_lab_solution') {
        $classroomState = $storage->getClassroomState($currentCourseSlug);
        $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$auth->isStaff($currentUser);
        if ($solutionsLocked) {
            http_response_code(403);
            echo json_encode(['success' => false, 'passed' => false, 'error' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.']);
            exit;
        }
    }

    $labApiCtrl->handleAction($action, $payload, $currentUser, $currentCourseSlug);
    $learnerCtrl->handleApiAction($action, $payload, $currentUser, $currentCourseSlug);

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action API inconnue.']);
    exit;
}

// 4. Form POST dispatch (Same-Origin + CSRF protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifySameOriginRequest($_SERVER)) {
        http_response_code(403);
        echo 'Requête cross-origin refusée (403).';
        exit;
    }
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Jeton CSRF invalide.';
        header('Location: index.php?page=' . urlencode($page));
        exit;
    }

    if ($action === 'sign_trainer_attendance' && $auth->isStaff($currentUser)) {
        $returnStudentId = (int)($_POST['return_student_id'] ?? 0);
        $returnTrack = trim((string)($_POST['return_track'] ?? ''));
        $_POST['return_student_id'] = $returnStudentId;
        $_POST['return_track'] = $returnTrack;
    }
    if ($action === 'moderate_discussion') {
        $rawReturnPage = trim((string)($_POST['return_page'] ?? 'admin'));
        $_POST['return_page'] = in_array($rawReturnPage, ['admin', 'module', 'lab'], true) ? $rawReturnPage : 'admin';
    }
    if ($action === 'admin_import_users_csv') {
        $_POST['csv_content'] = trim((string)($_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')));
    }
    if ($action === 'admin_clear_cache' && $auth->isAdmin($currentUser)) {
        $adminCtrl->handleAdminAction($action, $_POST, $currentUser, $currentCourseSlug, $coursesConfig);
    }

    $adminCtrl->handleStaffAction($action, $_POST, $currentUser, $currentCourseSlug);
    $adminCtrl->handleAdminAction($action, $_POST, $currentUser, $currentCourseSlug, $coursesConfig);
    $learnerCtrl->handlePostAction($action, $_POST, $currentUser, $currentCourseSlug);
}

// 5. Global page preparation, feature checks, and template rendering
if ($page === 'notes' && !$featureManager->isEnabledForCourse('personal_notes', $currentCourseSlug)) {
    http_response_code(403);
    echo 'Le carnet de notes personnel est actuellement désactivé par l\'administrateur.';
    exit;
}
if ($page === 'flashcards' && !$featureManager->isEnabledForCourse('flashcards_srs', $currentCourseSlug)) {
    http_response_code(403);
    echo 'Les cartes de révision SRS & glossaire sont actuellement désactivés par l\'administrateur.';
    exit;
}

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
$userGamification = Gamification::computeUserBadgesAndXp($userProgress, $userDiagnostic, $userBestExam, $userAttendance, $totalModules, $totalLabs, $currentCourseSlug);

$dockerStatus = $userId > 0 ? $docker->getCachedUserContainerStatus($userId) : ['running' => false, 'container_name' => 'aucun', 'port' => 0, 'connection_string' => ''];
$classroomState = $storage->getClassroomState($currentCourseSlug);
$allCourses = $courseRepo->getAllCourses();

$doneModules = count($userProgress['completed_modules'] ?? []);
$doneLabs = count($userProgress['completed_labs'] ?? []);
$totalItems = max(1, $totalModules + $totalLabs);
$globalCompletionPct = min(100, (int)round((($doneModules + $doneLabs) / $totalItems) * 100));

require __DIR__ . '/../templates/layout.php';
