<?php
declare(strict_types=1);

namespace LightPath\LMS\Http;

use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\Gamification;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\StorageInterface;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\DownloadController;
use LightPath\LMS\Course\LmsCatalog;
use LightPath\LMS\Course\MarkdownParser;

/**
 * Handles learner downloads, SCORM/Markdown exports, learner APIs, and form POST workflows.
 */
class LearnerController
{
    private StorageInterface $storage;
    private Auth $auth;
    private FeatureManager $featureManager;
    private CourseRepository $courseRepo;
    private DownloadController $downloader;
    private MarkdownParser $mdParser;

    public function __construct(
        StorageInterface $storage,
        Auth $auth,
        FeatureManager $featureManager,
        CourseRepository $courseRepo,
        DownloadController $downloader,
        MarkdownParser $mdParser
    ) {
        $this->storage = $storage;
        $this->auth = $auth;
        $this->featureManager = $featureManager;
        $this->courseRepo = $courseRepo;
        $this->downloader = $downloader;
        $this->mdParser = $mdParser;
    }

    private function isFeatureEnabledForCourse(string $flag, string $courseSlug): bool
    {
        if (method_exists($this->featureManager, 'isEnabledForCourse')) {
            return $this->featureManager->isEnabledForCourse($flag, $courseSlug);
        }
        return $this->featureManager->isEnabled($flag);
    }

    /**
     * Streams a downloadable or inline course document after checking lab lock rules.
     */
    public function handleDownload(array $get, array $currentUser, string $currentCourseSlug): never
    {
        $type = (string)($get['type'] ?? 'module');
        $itemId = (string)($get['id'] ?? '');
        $format = (string)($get['format'] ?? 'pdf');
        $inline = isset($get['inline']) && $get['inline'] === '1';

        if ($type === 'lab') {
            $classroomState = $this->storage->getClassroomState($currentCourseSlug);
            $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked']))
                && !$this->auth->isStaff($currentUser);
            if ($solutionsLocked) {
                http_response_code(403);
                echo 'Le téléchargement et la consultation des énoncés/corrigés de labs sont temporairement verrouillés par le formateur.';
                exit;
            }
        }

        $resolved = $this->downloader->resolveDownloadPath($currentCourseSlug, $type, $itemId, $format);
        if ($resolved === null) {
            http_response_code(404);
            echo 'Document introuvable.';
            exit;
        }

        if (!$inline) {
            $this->storage->logAuditEvent($currentUser['email'], 'DOWNLOAD', "Téléchargement {$resolved['filename']} ({$format})");
        }
        $this->downloader->streamFile($resolved, !$inline);
    }

    /**
     * Streams a relative course asset (diagram, image, or compiled HTML page) securely.
     */
    public function handleCourseAsset(array $get, string $currentCourseSlug): never
    {
        $relPath = (string)($get['path'] ?? '');
        $resolved = $this->downloader->resolveRelativeCourseAsset($currentCourseSlug, $relPath);
        if ($resolved === null) {
            http_response_code(404);
            echo 'Ressource introuvable.';
            exit;
        }
        $this->downloader->streamFile($resolved, false);
    }

    /**
     * Exports the learner's personal study notes as a Markdown attachment when enabled.
     */
    public function handleExportNotesMarkdown(array $currentUser, array $currentCourse, string $currentCourseSlug): never
    {
        if (!$this->isFeatureEnabledForCourse('personal_notes', $currentCourseSlug)) {
            http_response_code(403);
            echo 'Le carnet de notes personnel est actuellement désactivé.';
            exit;
        }

        $userId = (int)($currentUser['id'] ?? 0);
        $prog = $this->storage->getUserProgress($userId, $currentCourseSlug);
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

        $safeCourse = preg_replace('/[^a-z0-9_\-]/i', '_', $currentCourseSlug);
        $safeLearner = preg_replace('/[^a-z0-9]/i', '_', strtolower((string)$currentUser['name']));
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="notes_' . $safeCourse . '_' . $safeLearner . '.md"');
        echo implode("\n", $mdLines);
        exit;
    }

    /**
     * Generates and streams the SCORM 1.2 XML manifest for staff users.
     */
    public function handleExportScormManifest(array $currentCourse, string $currentCourseSlug): never
    {
        if (!$this->isFeatureEnabledForCourse('scorm_export', $currentCourseSlug)) {
            http_response_code(403);
            echo 'Export SCORM désactivé dans les réglages système.';
            exit;
        }
        $manifestXml = LmsCatalog::generateScormManifest($currentCourse);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="imsmanifest.xml"');
        echo $manifestXml;
        exit;
    }

    /**
     * Builds and streams a complete SCORM 1.2 ZIP package for staff users.
     */
    public function handleExportScormZip(array $currentCourse, string $currentCourseSlug): never
    {
        if (!$this->isFeatureEnabledForCourse('scorm_export', $currentCourseSlug)) {
            http_response_code(403);
            echo 'Export SCORM désactivé dans les réglages système.';
            exit;
        }
        $zipPath = $this->downloader->buildScorm12ZipPackage($currentCourseSlug, $currentCourse);
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

    /**
     * Persists a server-graded module quiz score and emits JSON response.
     */
    public function completeQuizSubmission(
        array $parsedQuiz,
        array $payload,
        string $moduleId,
        array $currentUser,
        string $currentCourseSlug
    ): never {
        $userId = (int)($currentUser['id'] ?? 0);
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

        $this->storage->saveQuizScore($userId, $currentCourseSlug, $moduleId, $score, $total);
        $this->storage->logAuditEvent($currentUser['email'], 'QUIZ_SUBMIT', "Module {$moduleId} : {$score}/{$total}");
        $prog = $this->storage->getUserProgress($userId, $currentCourseSlug);
        echo json_encode([
            'success' => true,
            'quiz_result' => $prog['quiz_scores'][$moduleId] ?? null,
        ]);
        exit;
    }

    /**
     * Dispatches learner JSON API endpoints (completion, notes, flashcards, Q&A, search).
     */
    public function handleApiAction(string $action, array $payload, array $currentUser, string $currentCourseSlug): bool
    {
        $userId = (int)($currentUser['id'] ?? 0);

        switch ($action) {
            case 'api_toggle_complete':
                $itemType = (string)($payload['item_type'] ?? 'module');
                $itemId = trim((string)($payload['item_id'] ?? ''));
                $completed = (bool)($payload['completed'] ?? true);
                $validItem = ($itemId !== '') && (
                    ($itemType === 'module' && $this->courseRepo->getModule($currentCourseSlug, $itemId) !== null)
                    || ($itemType === 'lab' && $this->courseRepo->getLab($currentCourseSlug, $itemId) !== null)
                );
                if (!$validItem) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Élément de cours invalide ou introuvable.']);
                    exit;
                }
                $this->storage->markItemCompleted($userId, $currentCourseSlug, $itemType, $itemId, $completed);
                $prog = $this->storage->getUserProgress($userId, $currentCourseSlug);
                echo json_encode([
                    'success' => true,
                    'completed' => $completed,
                    'completed_modules_count' => count($prog['completed_modules']),
                    'completed_labs_count' => count($prog['completed_labs']),
                ]);
                exit;

            case 'api_save_note':
                if (!$this->isFeatureEnabledForCourse('personal_notes', $currentCourseSlug)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Le carnet de notes personnel est actuellement désactivé.']);
                    exit;
                }
                $itemId = (string)($payload['item_id'] ?? '');
                $note = (string)($payload['note'] ?? '');
                $this->storage->saveUserNote($userId, $currentCourseSlug, $itemId, $note);
                echo json_encode(['success' => true, 'saved_at' => date('H:i:s')]);
                exit;

            case 'api_toggle_bookmark':
                $itemType = (string)($payload['item_type'] ?? 'module');
                $itemId = (string)($payload['item_id'] ?? '');
                $title = (string)($payload['title'] ?? $itemId);
                $res = $this->storage->toggleBookmark($userId, $currentCourseSlug, $itemType, $itemId, $title);
                echo json_encode(['success' => true] + $res);
                exit;

            case 'api_flashcard_rate':
                if (!$this->isFeatureEnabledForCourse('flashcards_srs', $currentCourseSlug)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Les cartes SRS & glossaire sont actuellement désactivés.']);
                    exit;
                }
                $cardId = (string)($payload['card_id'] ?? '');
                $level = (int)($payload['level'] ?? 2);
                $this->storage->saveFlashcardConfidence($userId, $currentCourseSlug, $cardId, $level);
                echo json_encode(['success' => true, 'card_id' => $cardId, 'level' => $level]);
                exit;

            case 'api_reset_flashcards':
                if (!$this->isFeatureEnabledForCourse('flashcards_srs', $currentCourseSlug)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Les cartes SRS & glossaire sont actuellement désactivés.']);
                    exit;
                }
                $res = $this->storage->resetFlashcardReviews($userId, $currentCourseSlug);
                echo json_encode(['success' => $res, 'message' => 'Évaluations SRS réinitialisées.']);
                exit;

            case 'api_post_discussion':
                if (!$this->isFeatureEnabledForCourse('qna_forum', $currentCourseSlug)) {
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
                $entry = $this->storage->addDiscussionMessage(
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
                    'results' => $this->courseRepo->searchContent($currentCourseSlug, $q),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;

            default:
                return false;
        }
    }

    /**
     * Dispatches learner form POST actions (profile, diagnostic, exam, attendance, satisfaction, notes, Q&A).
     */
    public function handlePostAction(string $action, array $post, array $currentUser, string $currentCourseSlug): bool
    {
        $userId = (int)($currentUser['id'] ?? 0);

        switch ($action) {
            case 'update_profile':
                $currentUser['name'] = trim((string)($post['name'] ?? $currentUser['name']));
                $currentUser['company'] = trim((string)($post['company'] ?? $currentUser['company']));
                $this->storage->saveUser($currentUser);

                $newPass = (string)($post['new_password'] ?? '');
                if ($newPass !== '') {
                    $currentPass = (string)($post['current_password'] ?? '');
                    $pwRes = $this->auth->changePassword($userId, $currentPass, $newPass);
                    if (empty($pwRes['success'])) {
                        $_SESSION['flash_error'] = (string)($pwRes['message'] ?? 'Mot de passe actuel invalide ou nouveau mot de passe trop court.');
                        header('Location: index.php?page=profile');
                        exit;
                    }
                }
                $_SESSION['flash_success'] = 'Votre profil a été mis à jour avec succès.';
                header('Location: index.php?page=profile');
                exit;

            case 'submit_diagnostic':
                $answers = is_array($post['answers'] ?? null) ? $post['answers'] : [];
                $eval = LmsCatalog::evaluateDiagnostic($answers, $currentCourseSlug);
                $this->storage->saveDiagnosticResult($userId, $currentCourseSlug, $eval);
                $this->storage->logAuditEvent($currentUser['email'], 'DIAGNOSTIC_SUBMIT', "Positionnement Qualiopi : {$eval['percent']}% ({$eval['recommended_track']})");
                $_SESSION['flash_success'] = "Positionnement Qualiopi enregistré ({$eval['percent']}%) — Parcours recommandé : {$eval['recommended_title']}.";
                header('Location: index.php?page=diagnostic&course=' . urlencode($currentCourseSlug));
                exit;

            case 'submit_exam':
                $classroomState = $this->storage->getClassroomState($currentCourseSlug);
                if (!empty($classroomState['lock_exam']) && !$this->auth->isStaff($currentUser)) {
                    $_SESSION['flash_error'] = 'L\'examen final est actuellement verrouillé par le formateur.';
                    header('Location: index.php?page=exam&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                $answers = is_array($post['answers'] ?? null) ? $post['answers'] : [];
                $durationSec = (int)($post['duration_seconds'] ?? 0);
                $eval = LmsCatalog::evaluateFinalExam($answers, $durationSec, $currentCourseSlug);
                $savedAttempt = $this->storage->saveExamAttempt($userId, $currentCourseSlug, $eval);
                $this->storage->logAuditEvent($currentUser['email'], 'FINAL_EXAM_SUBMIT', "Examen Final : {$eval['percent']}% ({$eval['mention']}) — Certificat {$savedAttempt['certificate_id']}");
                if ($eval['passed']) {
                    $courseLabel = $currentCourseSlug === 'postgresql-18'
                        ? 'PostgreSQL 18'
                        : ($currentCourseSlug === 'git-basics' ? 'Git — Les Bases' : 'de la certification');
                    $_SESSION['flash_success'] = "Félicitations ! Vous avez réussi l'Examen Final de Certification {$courseLabel} avec {$eval['percent']}% ({$eval['mention']}).";
                } else {
                    $_SESSION['flash_error'] = "Score obtenu : {$eval['percent']}% (seuil requis : 75%). Consultez le détail de vos réponses ci-dessous et réessayez.";
                }
                header('Location: index.php?page=exam&course=' . urlencode($currentCourseSlug));
                exit;

            case 'sign_attendance':
                $halfDayKey = trim((string)($post['half_day_key'] ?? ''));
                $sigText = trim((string)($post['signature_text'] ?? $currentUser['name']));
                if (Security::isValidHalfDayKey($halfDayKey)) {
                    $this->storage->signAttendance($userId, $currentCourseSlug, $halfDayKey, $sigText, (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
                    $this->storage->logAuditEvent($currentUser['email'], 'ATTENDANCE_SIGN', "Émargement demi-journée {$halfDayKey}");
                    $_SESSION['flash_success'] = "Présence émargée avec succès pour la demi-journée {$halfDayKey}.";
                } else {
                    $_SESSION['flash_error'] = 'Créneau de demi-journée Qualiopi invalide.';
                }
                header('Location: index.php?page=attendance&course=' . urlencode($currentCourseSlug));
                exit;

            case 'submit_satisfaction':
                $this->storage->saveSatisfactionSurvey($userId, $currentCourseSlug, $post);
                $this->storage->logAuditEvent($currentUser['email'], 'SATISFACTION_SUBMIT', 'Questionnaire de satisfaction à chaud Qualiopi complété');
                $_SESSION['flash_success'] = 'Merci ! Votre évaluation de satisfaction Qualiopi a été enregistrée.';
                header('Location: index.php?page=satisfaction&course=' . urlencode($currentCourseSlug));
                exit;

            case 'submit_cold_satisfaction':
                if (!$this->isFeatureEnabledForCourse('cold_satisfaction_j60', $currentCourseSlug)) {
                    http_response_code(403);
                    echo 'Le questionnaire d\'impact à froid J+60 est actuellement désactivé.';
                    exit;
                }
                $this->storage->saveColdSatisfactionSurvey($userId, $currentCourseSlug, $post);
                $this->storage->logAuditEvent($currentUser['email'], 'COLD_SATISFACTION_SUBMIT', "Questionnaire d'impact à froid J+60 Qualiopi complété");
                $_SESSION['flash_success'] = 'Merci ! Votre évaluation d\'impact métier à froid (J+60) a été enregistrée.';
                header('Location: index.php?page=satisfaction&tab=cold&course=' . urlencode($currentCourseSlug));
                exit;

            case 'save_note_form':
                if (!$this->isFeatureEnabledForCourse('personal_notes', $currentCourseSlug)) {
                    http_response_code(403);
                    echo 'Le carnet de notes personnel est actuellement désactivé.';
                    exit;
                }
                $itemId = trim((string)($post['item_id'] ?? ''));
                $note = (string)($post['note'] ?? '');
                if ($itemId !== '') {
                    $this->storage->saveUserNote($userId, $currentCourseSlug, $itemId, $note);
                    $_SESSION['flash_success'] = 'Note personnelle enregistrée dans votre carnet.';
                }
                header('Location: index.php?page=notes&course=' . urlencode($currentCourseSlug));
                exit;

            case 'post_discussion':
                if (!$this->isFeatureEnabledForCourse('qna_forum', $currentCourseSlug)) {
                    http_response_code(403);
                    echo 'Le forum Q&A est actuellement désactivé.';
                    exit;
                }
                $itemId = trim((string)($post['item_id'] ?? ''));
                $rawReturnPage = trim((string)($post['return_page'] ?? 'module'));
                $returnPage = in_array($rawReturnPage, ['module', 'lab'], true) ? $rawReturnPage : 'module';
                $message = trim((string)($post['message'] ?? ''));
                $parentId = !empty($post['parent_id']) ? trim((string)$post['parent_id']) : null;
                if ($itemId !== '' && $message !== '') {
                    $this->storage->addDiscussionMessage($currentCourseSlug, $itemId, $userId, $currentUser['name'], $currentUser['role'], $message, $parentId);
                    $_SESSION['flash_success'] = 'Votre question / commentaire technique a été publié.';
                }
                header('Location: index.php?page=' . urlencode($returnPage) . '&id=' . urlencode($itemId) . '&course=' . urlencode($currentCourseSlug) . '#discussion-section');
                exit;

            default:
                return false;
        }
    }
}
