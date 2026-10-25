<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\PdoStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;
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
$courseRepo = new CourseRepository($coursesConfig, $storage->getCustomCourses());
$downloader = new DownloadController($courseRepo);
$docker = new DockerManager($storage, $appConfig['docker']);
$sqlRunner = new LabSqlRunner($docker);
$mdParser = new MarkdownParser();

$action = (string)($_GET['action'] ?? '');
$page = (string)($_GET['page'] ?? 'dashboard');
$currentCourseSlug = (string)($_GET['course'] ?? 'postgresql-18');
$currentCourse = $courseRepo->getCourse($currentCourseSlug) ?? $courseRepo->getCourse('postgresql-18');
if ($currentCourse !== null) {
    $currentCourseSlug = (string)$currentCourse['slug'];
}

// ============================================================================
// 1. ROUTES PUBLIQUES D'AUTHENTIFICATION (Login / Register / Logout)
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

    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Session expirée (jeton CSRF invalide). Veuillez réessayer.';
        header('Location: index.php?page=login');
        exit;
    }

    $loggedUser = $auth->login($email, $password);
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
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $company = trim((string)($_POST['company'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!Security::verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Jeton CSRF invalide.';
        header('Location: index.php?page=login&tab=register');
        exit;
    }

    $created = $auth->registerUser($name, $email, $password, 'student', $company);
    if ($created !== null) {
        $auth->login($email, $password);
        $_SESSION['flash_success'] = 'Votre compte apprenant a été créé avec succès !';
        header('Location: index.php?page=dashboard');
        exit;
    }
    $_SESSION['flash_error'] = 'Impossible de créer ce compte (vérifiez l\'email ou le mot de passe d\'au moins 6 caractères).';
    header('Location: index.php?page=login&tab=register');
    exit;
}

$currentUser = $auth->currentUser();
if ($currentUser === null) {
    require __DIR__ . '/../templates/pages/login.php';
    exit;
}

$userId = (int)$currentUser['id'];

// ============================================================================
// 2. ROUTES DE TÉLÉCHARGEMENT DIRECT & ASSETS DE COURS (PDF, PPTX, MD, HTML, DOCX)
// ============================================================================
if ($action === 'download') {
    $type = (string)($_GET['type'] ?? 'module');
    $itemId = (string)($_GET['id'] ?? '');
    $format = (string)($_GET['format'] ?? 'pdf');
    $inline = isset($_GET['inline']) && $_GET['inline'] === '1';

    $resolved = $downloader->resolveDownloadPath($currentCourseSlug, $type, $itemId, $format);
    if ($resolved === null) {
        http_response_code(404);
        echo 'Document introuvable.';
        exit;
    }

    $storage->logAuditEvent($currentUser['email'], 'DOWNLOAD', "Téléchargement {$resolved['filename']} ({$format})");
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

// ============================================================================
// 3. ROUTES API JSON INTERACTIVES (Progression, Quiz, Notes, Docker, SQL, Oracle)
// ============================================================================
if (str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=UTF-8');
    $rawBody = (string)file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $csrf = (string)($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Security::verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide.']);
        exit;
    }

    switch ($action) {
        case 'api_toggle_complete':
            $itemType = (string)($payload['item_type'] ?? 'module');
            $itemId = (string)($payload['item_id'] ?? '');
            $completed = (bool)($payload['completed'] ?? true);
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
            $moduleId = (string)($payload['module_id'] ?? '');
            $score = (int)($payload['score'] ?? 0);
            $total = max(1, (int)($payload['total'] ?? 1));
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
                'reset' => $docker->resetUserContainer($targetUserId, $currentUser['email']),
                default => ['success' => true, 'status' => $docker->getUserContainerStatus($targetUserId)],
            };
            echo json_encode($res);
            exit;

        case 'api_run_sql':
            $sql = (string)($payload['sql'] ?? '');
            $res = $sqlRunner->executeSql($userId, $sql);
            echo json_encode($res);
            exit;

        case 'api_run_oracle':
            $labId = (string)($payload['lab_id'] ?? '');
            $res = $sqlRunner->runLabOracle($userId, $labId);
            if (!empty($res['passed']) && $labId !== '') {
                $storage->markItemCompleted($userId, $currentCourseSlug, 'lab', $labId, true);
                $storage->logAuditEvent($currentUser['email'], 'LAB_ORACLE_PASS', "Validation Oracle réussie sur {$labId} ({$res['bloom']})");
            }
            echo json_encode($res);
            exit;

        case 'api_chaos':
            if (!$auth->isStaff($currentUser)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Accès réservé aux formateurs et administrateurs.']);
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
// 4. ACTIONS POST D'ADMINISTRATION & PROFIL
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
        $newPass = (string)($_POST['new_password'] ?? '');
        if (strlen($newPass) >= 6) {
            $currentUser['password_hash'] = password_hash($newPass, PASSWORD_BCRYPT);
        }
        $storage->saveUser($currentUser);
        $_SESSION['flash_success'] = 'Votre profil a été mis à jour avec succès.';
        header('Location: index.php?page=profile');
        exit;
    }

    if ($auth->isAdmin($currentUser)) {
        if ($action === 'admin_create_user') {
            $uName = trim((string)($_POST['name'] ?? ''));
            $uEmail = trim((string)($_POST['email'] ?? ''));
            $uPass = (string)($_POST['password'] ?? 'LightPath2026!');
            $uRole = (string)($_POST['role'] ?? 'student');
            $uComp = trim((string)($_POST['company'] ?? ''));

            $created = $auth->registerUser($uName, $uEmail, $uPass, $uRole, $uComp);
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
                    $targetUser['role'] = (string)($_POST['role'] ?? $targetUser['role']);
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

        if ($action === 'admin_add_course') {
            $cSlug = strtolower((string)preg_replace('/[^a-zA-Z0-9\-]/', '-', trim((string)($_POST['slug'] ?? ''))));
            $cTitle = trim((string)($_POST['title'] ?? ''));
            $cSource = trim((string)($_POST['source_root'] ?? ''));
            $cCat = trim((string)($_POST['category'] ?? 'Infrastructure & Cloud'));
            $cDur = trim((string)($_POST['duration'] ?? '35h (5 jours)'));

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
                    'icon' => '/assets/img/lightpath_cropped.png',
                    'active' => true,
                    'docker_enabled' => true,
                    'source_root' => $cSource !== '' ? $cSource : ($coursesConfig['postgresql-18']['source_root'] ?? ''),
                    'pgscripts_root' => '',
                    'description' => trim((string)($_POST['description'] ?? 'Formation professionnelle LightPath SARL.')),
                    'tracks' => [],
                ]);
                $storage->logAuditEvent($currentUser['email'], 'ADMIN_COURSE_ADD', "Ajout de la formation '{$cTitle}' ({$cSlug})");
                $_SESSION['flash_success'] = "Nouvelle formation « {$cTitle} » enregistrée dans le portail multi-formations !";
            }
            header('Location: index.php?page=admin&tab=courses');
            exit;
        }
    }
}

// ============================================================================
// 5. PRÉPARATION DU CONTEXTE GLOBAL & AFFICHAGE DES PAGES
// ============================================================================
$userProgress = $storage->getUserProgress($userId, $currentCourseSlug);
$dockerStatus = ($page === 'docker')
    ? $docker->getUserContainerStatus($userId)
    : $docker->getCachedUserContainerStatus($userId);
$allCourses = $courseRepo->getAllCourses();

$totalModules = count($currentCourse['modules'] ?? []);
$totalLabs = count($currentCourse['labs'] ?? []);
$doneModules = count($userProgress['completed_modules'] ?? []);
$doneLabs = count($userProgress['completed_labs'] ?? []);
$totalItems = max(1, $totalModules + $totalLabs);
$globalCompletionPct = min(100, (int)round((($doneModules + $doneLabs) / $totalItems) * 100));

require __DIR__ . '/../templates/layout.php';
