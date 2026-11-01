<?php
declare(strict_types=1);

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
if ($currentUser === null && $page !== 'verify') {
    require __DIR__ . '/../templates/pages/login.php';
    exit;
}

$userId = (int)($currentUser['id'] ?? 0);
// Initialiser le jeton CSRF en session puis libérer immédiatement le verrou de session sur les requêtes GET/API
Security::getCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'GET' || str_starts_with($action, 'api_')) {
    session_write_close();
}

// ============================================================================
// 2. ROUTES DE TÉLÉCHARGEMENT DIRECT & ASSETS DE COURS (PDF, PPTX, MD, HTML, DOCX)
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

if ($action === 'export_scorm_manifest') {
    $manifestXml = LmsCatalog::generateScormManifest($currentCourse ?? []);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="imsmanifest.xml"');
    echo $manifestXml;
    exit;
}

if ($action === 'export_scorm_zip') {
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
            $modObj = $courseRepo->getModule($currentCourseSlug, $moduleId);
            if ($modObj === null && $moduleId === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Module introuvable.']);
                exit;
            }
            $total = max(1, (int)($payload['total'] ?? 1));
            $score = max(0, min($total, (int)($payload['score'] ?? 0)));

            // Si la carte des réponses (answers) est transmise, recalculer le score côté serveur
            if ($modObj !== null && !empty($payload['answers']) && is_array($payload['answers']) && !empty($modObj['source_md_path'])) {
                $parsedQuiz = $mdParser->parseModuleFile($modObj['source_md_path'], $currentCourseSlug)['quiz'] ?? [];
                if (!empty($parsedQuiz)) {
                    $total = count($parsedQuiz);
                    $score = 0;
                    foreach ($parsedQuiz as $qItem) {
                        $qIdx = (string)($qItem['index'] ?? '');
                        $given = strtoupper(trim((string)($payload['answers'][$qIdx] ?? $payload['answers']['q_' . $qIdx] ?? '')));
                        if ($given !== '' && $given === strtoupper(trim((string)($qItem['correct'] ?? '')))) {
                            $score++;
                        }
                    }
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
                'reset' => $docker->resetUserContainer($targetUserId, $currentUser['email']),
                default => ['success' => true, 'status' => $docker->getUserContainerStatus($targetUserId)],
            };
            echo json_encode($res);
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

        case 'api_post_discussion':
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
        header('Location: index.php?page=attendance&course=' . urlencode($currentCourseSlug));
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
        $returnPage = trim((string)($_POST['return_page'] ?? 'admin'));
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

    if ($action === 'post_discussion') {
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

        if ($action === 'admin_import_users_csv') {
            $csvRaw = trim((string)($_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')));
            $defaultPw = trim((string)($_POST['default_password'] ?? 'LightPath2026!'));
            $importRes = $storage->importUsersFromCsv($csvRaw, $defaultPw !== '' ? $defaultPw : 'LightPath2026!');
            $storage->logAuditEvent($currentUser['email'], 'ADMIN_CSV_IMPORT', "Import cohorte CSV : {$importRes['created']} créés, {$importRes['skipped']} ignorés");
            $_SESSION['flash_success'] = "Import CSV terminé : {$importRes['created']} compte(s) créé(s), {$importRes['skipped']} ligne(s) ignorée(s).";
            header('Location: index.php?page=admin&tab=users');
            exit;
        }

        if ($action === 'admin_add_course') {
            $cSlug = strtolower((string)preg_replace('/[^a-zA-Z0-9\-]/', '-', trim((string)($_POST['slug'] ?? ''))));
            $cTitle = trim((string)($_POST['title'] ?? ''));
            $cSource = trim((string)($_POST['source_root'] ?? ''));
            $cCat = trim((string)($_POST['category'] ?? 'Infrastructure & Cloud'));
            $cDur = trim((string)($_POST['duration'] ?? '35h (5 jours)'));

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

$userProgress = $userId > 0 ? $storage->getUserProgress($userId, $currentCourseSlug) : ['completed_modules' => [], 'completed_labs' => [], 'quiz_scores' => []];
$userDiagnostic = $userId > 0 ? $storage->getDiagnosticResult($userId, $currentCourseSlug) : null;
$userBestExam = $userId > 0 ? $storage->getBestExamAttempt($userId, $currentCourseSlug) : null;
$userAttendance = $userId > 0 ? $storage->getUserAttendance($userId, $currentCourseSlug) : [];
$userGamification = Gamification::computeUserBadgesAndXp($userProgress, $userDiagnostic, $userBestExam, $userAttendance);

$dockerStatus = $userId > 0 ? $docker->getCachedUserContainerStatus($userId) : ['running' => false, 'container_name' => 'aucun', 'port' => 0, 'connection_string' => ''];
$classroomState = $storage->getClassroomState($currentCourseSlug);
$allCourses = $courseRepo->getAllCourses();

$totalModules = count($currentCourse['modules'] ?? []);
$totalLabs = count($currentCourse['labs'] ?? []);
$doneModules = count($userProgress['completed_modules'] ?? []);
$doneLabs = count($userProgress['completed_labs'] ?? []);
$totalItems = max(1, $totalModules + $totalLabs);
$globalCompletionPct = min(100, (int)round((($doneModules + $doneLabs) / $totalItems) * 100));

require __DIR__ . '/../templates/layout.php';
