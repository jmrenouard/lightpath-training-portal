<?php
declare(strict_types=1);

namespace LightPath\LMS\Http;

use LightPath\LMS\Core\AdminService;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\StorageInterface;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Docker\DockerManager;

/**
 * Handles staff and administrator exports, classroom state orchestration, user management,
 * Docker fleet governance, feature flag profiles, and system maintenance workflows.
 */
class AdminController
{
    private StorageInterface $storage;
    private Auth $auth;
    private FeatureManager $featureManager;
    private AdminService $adminService;
    private CourseRepository $courseRepo;
    private DockerManager $docker;
    private MarkdownParser $mdParser;

    public function __construct(
        StorageInterface $storage,
        Auth $auth,
        FeatureManager $featureManager,
        AdminService $adminService,
        CourseRepository $courseRepo,
        DockerManager $docker,
        MarkdownParser $mdParser
    ) {
        $this->storage = $storage;
        $this->auth = $auth;
        $this->featureManager = $featureManager;
        $this->adminService = $adminService;
        $this->courseRepo = $courseRepo;
        $this->docker = $docker;
        $this->mdParser = $mdParser;
    }

    private function denyForbidden(string $message): never
    {
        http_response_code(403);
        echo $message;
        exit;
    }

    private function collectAllCourseProgress(array $users, string $courseSlug): array
    {
        $allProgress = [];
        foreach ($users as $u) {
            $uid = (int)($u['id'] ?? 0);
            if ($uid > 0) {
                $allProgress[$uid] = $this->storage->getUserProgress($uid, $courseSlug);
            }
        }
        return $allProgress;
    }

    /**
     * Applies a curated one-click classroom governance preset for the active course.
     */
    private function applyClassroomPreset(string $presetKey, array $post, array $currentUser, string $currentCourseSlug): never
    {
        $existing = $this->storage->getClassroomState($currentCourseSlug);
        $activeTrack = trim((string)($post['active_track'] ?? ($existing['active_track'] ?? 'cursus-complet')));
        if ($activeTrack === '') {
            $activeTrack = 'cursus-complet';
        }

        $presets = [
            'lecture' => [
                'label' => 'Cours magistral',
                'broadcast_message' => 'Cours magistral en cours — suivez le support de présentation projeté.',
                'broadcast_level' => 'info',
                'lock_solutions' => true,
                'lock_exam' => true,
            ],
            'guided_lab' => [
                'label' => 'Atelier pratique guidé',
                'broadcast_message' => 'Atelier pratique ouvert : démarrez votre conteneur PostgreSQL 18 et testez l\'Oracle.',
                'broadcast_level' => 'info',
                'lock_solutions' => false,
                'lock_exam' => true,
            ],
            'exam_lockdown' => [
                'label' => 'Évaluation certifiante',
                'broadcast_message' => 'Mode Examen Certifiant actif : corrigés verrouillés, épreuve finale débloquée.',
                'broadcast_level' => 'warning',
                'lock_solutions' => true,
                'lock_exam' => false,
            ],
            'open_review' => [
                'label' => 'Révision libre',
                'broadcast_message' => 'Phase de révision complète : corrigés, ateliers et examen sont ouverts à la cohorte.',
                'broadcast_level' => 'success',
                'lock_solutions' => false,
                'lock_exam' => false,
            ],
        ];

        if (!isset($presets[$presetKey])) {
            $_SESSION['flash_error'] = 'Preset de salle de classe inconnu.';
            header('Location: index.php?page=admin&tab=cohort&course=' . urlencode($currentCourseSlug));
            exit;
        }

        $chosen = $presets[$presetKey];
        $this->storage->saveClassroomState($currentCourseSlug, [
            'broadcast_message' => $chosen['broadcast_message'],
            'broadcast_level' => $chosen['broadcast_level'],
            'lock_solutions' => $chosen['lock_solutions'],
            'lock_exam' => $chosen['lock_exam'],
            'active_track' => $activeTrack,
            'updated_by' => (string)$currentUser['name'],
        ]);
        $this->storage->logAuditEvent(
            (string)$currentUser['email'],
            'CLASSROOM_PRESET_APPLY',
            sprintf('Preset "%s" appliqué sur %s', $presetKey, $currentCourseSlug)
        );
        $_SESSION['flash_success'] = sprintf('Preset « %s » appliqué à la salle de classe Live.', $chosen['label']);
        header('Location: index.php?page=admin&tab=cohort&course=' . urlencode($currentCourseSlug));
        exit;
    }

    /**
     * Handles bulk user actions (enroll in course, suspend account, activate account).
     */
    private function processBulkUsers(array $post, array $currentUser, string $currentCourseSlug): never
    {
        $bulkOp = trim((string)($post['bulk_op'] ?? ''));
        $rawIds = $post['selected_user_ids'] ?? [];
        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($rawIds) ? $rawIds : []),
            static fn(int $id): bool => $id > 0
        )));

        if (empty($targetIds)) {
            $_SESSION['flash_error'] = 'Sélectionnez au moins un utilisateur avant d\'exécuter une action groupée.';
            header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
            exit;
        }

        $selfId = (int)($currentUser['id'] ?? 0);
        $updated = 0;

        if ($bulkOp === 'enroll') {
            $bulkCourse = trim((string)($post['bulk_course_slug'] ?? $currentCourseSlug));
            if ($this->courseRepo->getCourse($bulkCourse) === null) {
                $_SESSION['flash_error'] = "Formation cible introuvable pour l'inscription groupée ({$bulkCourse}).";
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;
            }
            foreach ($targetIds as $uid) {
                $targetUser = $this->storage->getUserById($uid);
                if ($targetUser === null) {
                    continue;
                }
                $enrolled = array_map('strval', (array)($targetUser['enrolled_courses'] ?? []));
                if (!in_array($bulkCourse, $enrolled, true)) {
                    $enrolled[] = $bulkCourse;
                    $targetUser['enrolled_courses'] = array_values(array_unique($enrolled));
                    $this->storage->saveUser($targetUser);
                }
                $updated++;
            }
            $this->storage->logAuditEvent(
                (string)$currentUser['email'],
                'ADMIN_BULK_USERS',
                sprintf('Inscription groupée à %s : %d compte(s) traité(s)', $bulkCourse, $updated)
            );
            $_SESSION['flash_success'] = sprintf('%d utilisateur(s) inscrit(s) à la formation « %s ».', $updated, $bulkCourse);
        } elseif ($bulkOp === 'suspend') {
            foreach ($targetIds as $uid) {
                if ($uid === 1 || $uid === $selfId) {
                    continue;
                }
                if ($this->storage->setUserActive($uid, false)) {
                    $updated++;
                }
            }
            $this->storage->logAuditEvent(
                (string)$currentUser['email'],
                'ADMIN_BULK_USERS',
                sprintf('Suspension groupée : %d compte(s) désactivé(s)', $updated)
            );
            $_SESSION['flash_success'] = sprintf('%d compte(s) suspendu(s) (Super-Admin et session active protégés).', $updated);
        } elseif ($bulkOp === 'activate') {
            foreach ($targetIds as $uid) {
                if ($this->storage->setUserActive($uid, true)) {
                    $updated++;
                }
            }
            $this->storage->logAuditEvent(
                (string)$currentUser['email'],
                'ADMIN_BULK_USERS',
                sprintf('Réactivation groupée : %d compte(s) activé(s)', $updated)
            );
            $_SESSION['flash_success'] = sprintf('%d compte(s) réactivé(s) avec succès.', $updated);
        } else {
            $_SESSION['flash_error'] = 'Action groupée inconnue.';
        }

        header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
        exit;
    }

    /**
     * Dispatches GET export endpoints reserved for staff or administrators.
     */
    public function handleExportAction(
        string $action,
        array $get,
        array $currentUser,
        array $currentCourse,
        string $currentCourseSlug
    ): bool {
        $staffExports = ['export_qualiopi_csv', 'export_cohort_csv', 'export_qualiopi_json'];
        $adminExports = ['export_audit_csv', 'export_features_json'];

        if (!in_array($action, $staffExports, true) && !in_array($action, $adminExports, true)) {
            return false;
        }

        if (in_array($action, $staffExports, true) && !$this->auth->isStaff($currentUser)) {
            $this->denyForbidden('Accès refusé (403) : export réservé aux formateurs et administrateurs.');
        }
        if (in_array($action, $adminExports, true) && !$this->auth->isAdmin($currentUser)) {
            $this->denyForbidden('Accès refusé (403) : export réservé aux administrateurs.');
        }

        if ($action === 'export_qualiopi_csv') {
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
                foreach ($this->storage->getUsers() as $u) {
                    $uid = (int)$u['id'];
                    $p = $this->storage->getUserProgress($uid, $currentCourseSlug);
                    $diag = $this->storage->getDiagnosticResult($uid, $currentCourseSlug);
                    $exam = $this->storage->getBestExamAttempt($uid, $currentCourseSlug);
                    $att = $this->storage->getUserAttendance($uid, $currentCourseSlug);
                    $sat = $this->storage->getUserSatisfaction($uid, $currentCourseSlug);
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

        if ($action === 'export_cohort_csv') {
            $users = $this->storage->getUsers();
            $allProgress = $this->collectAllCourseProgress($users, $currentCourseSlug);
            $csv = $this->adminService->exportCohortCsv($users, $allProgress, $currentCourse);
            $this->storage->logAuditEvent(
                (string)$currentUser['email'],
                'COHORT_CSV_EXPORT',
                sprintf('Export CSV cohorte pour %s (%d comptes)', $currentCourseSlug, count($users))
            );
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="cohort_progression_' . $currentCourseSlug . '_' . date('Ymd_His') . '.csv"');
            echo $csv;
            exit;
        }

        if ($action === 'export_qualiopi_json') {
            $users = $this->storage->getUsers();
            $allProgress = $this->collectAllCourseProgress($users, $currentCourseSlug);
            $attendance = [];
            foreach ($users as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid > 0) {
                    $attendance[$uid] = $this->storage->getUserAttendance($uid, $currentCourseSlug);
                }
            }
            $auditLogs = $this->storage->getAuditLogs(2000, true);
            $integrityReport = $this->storage->verifyAuditIntegrity(true);
            $json = $this->adminService->exportQualiopiJson(
                $users,
                $allProgress,
                $attendance,
                $auditLogs,
                $currentCourse,
                $integrityReport
            );
            $this->storage->logAuditEvent(
                (string)$currentUser['email'],
                'QUALIOPI_DOSSIER_JSON_EXPORT',
                sprintf('Export dossier Qualiopi JSON pour %s', $currentCourseSlug)
            );
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="qualiopi_dossier_' . $currentCourseSlug . '_' . date('Ymd_His') . '.json"');
            echo $json;
            exit;
        }

        if ($action === 'export_audit_csv') {
            $auditCat = trim((string)($get['category'] ?? ''));
            $auditActor = trim((string)($get['actor'] ?? ''));
            $filteredAudit = $this->adminService->filterAuditLogs($this->storage->getAuditLogs(1000, true), $auditCat, $auditActor);
            $meta = $this->adminService->buildAuditExportMetadata($this->storage, true);
            header('X-Audit-Chain-Status: ' . (!empty($meta['chain_valid']) ? 'VALID' : 'BROKEN'));
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="audit_qualiopi_' . $currentCourseSlug . '_' . date('Ymd_His') . '.csv"');
            echo $this->adminService->exportAuditLogsCsv($filteredAudit);
            exit;
        }

        // export_features_json
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="features_config_' . date('Ymd_His') . '.json"');
        echo $this->featureManager->exportConfigJson();
        exit;
    }

    /**
     * Dispatches staff POST actions (attendance countersign, classroom state, Q&A moderation, Docker fleet controls).
     */
    public function handleStaffAction(string $action, array $post, array $currentUser, string $currentCourseSlug): bool
    {
        $staffActions = [
            'sign_trainer_attendance',
            'save_classroom_state',
            'admin_apply_classroom_preset',
            'moderate_discussion',
            'admin_bulk_docker',
            'admin_reap_idle_docker',
            'admin_heal_chaos',
        ];
        if (!in_array($action, $staffActions, true)) {
            return false;
        }
        if (!$this->auth->isStaff($currentUser)) {
            $this->denyForbidden('Accès refusé (403) : action réservée aux formateurs et administrateurs.');
        }

        $userId = (int)($currentUser['id'] ?? 0);

        switch ($action) {
            case 'sign_trainer_attendance':
                $targetUid = (int)($post['target_user_id'] ?? $userId);
                $halfDayKey = trim((string)($post['half_day_key'] ?? 'ALL'));
                $this->storage->signTrainerAttendance($targetUid, $currentCourseSlug, $halfDayKey, (string)$currentUser['name']);
                $this->storage->logAuditEvent(
                    (string)$currentUser['email'],
                    'TRAINER_ATTENDANCE_COUNTERSIGN',
                    "Contre-signature formateur ({$halfDayKey}) pour cible #{$targetUid}"
                );
                $_SESSION['flash_success'] = "Contre-signature formateur Qualiopi apposée avec succès ({$halfDayKey}).";
                $redirectUrl = 'index.php?page=attendance&course=' . urlencode($currentCourseSlug);
                $returnStudentId = (int)($post['return_student_id'] ?? 0);
                if ($returnStudentId > 0) {
                    $redirectUrl .= '&student_id=' . $returnStudentId;
                }
                $returnTrack = trim((string)($post['return_track'] ?? ''));
                if ($returnTrack !== '') {
                    $redirectUrl .= '&track=' . urlencode($returnTrack);
                }
                header('Location: ' . $redirectUrl);
                exit;

            case 'save_classroom_state':
                $this->storage->saveClassroomState($currentCourseSlug, [
                    'broadcast_message' => trim((string)($post['broadcast_message'] ?? '')),
                    'broadcast_level' => (string)($post['broadcast_level'] ?? 'info'),
                    'lock_solutions' => isset($post['lock_solutions']) && $post['lock_solutions'] === '1',
                    'lock_exam' => isset($post['lock_exam']) && $post['lock_exam'] === '1',
                    'active_track' => (string)($post['active_track'] ?? 'cursus-complet'),
                    'updated_by' => (string)$currentUser['name'],
                ]);
                $this->storage->logAuditEvent((string)$currentUser['email'], 'CLASSROOM_STATE_UPDATE', 'Mise à jour du mode Salle de Classe Live & Verrous');
                $_SESSION['flash_success'] = 'Configuration de la Salle de Classe Live mise à jour pour tous les stagiaires.';
                header('Location: index.php?page=admin&tab=cohort&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_apply_classroom_preset':
                $preset = trim((string)($post['preset'] ?? ''));
                $this->applyClassroomPreset($preset, $post, $currentUser, $currentCourseSlug);

            case 'moderate_discussion':
                $itemId = trim((string)($post['item_id'] ?? ''));
                $msgId = trim((string)($post['message_id'] ?? ''));
                $modAction = trim((string)($post['mod_action'] ?? 'resolve'));
                $rawReturnPage = trim((string)($post['return_page'] ?? 'admin'));
                $returnPage = in_array($rawReturnPage, ['admin', 'module', 'lab'], true) ? $rawReturnPage : 'admin';
                if ($itemId !== '' && $msgId !== '') {
                    if ($modAction === 'delete') {
                        $this->storage->deleteDiscussionMessage($currentCourseSlug, $itemId, $msgId);
                        $_SESSION['flash_success'] = 'Message supprimé du fil Q&A.';
                    } else {
                        $this->storage->resolveDiscussionMessage($currentCourseSlug, $itemId, $msgId, true);
                        $_SESSION['flash_success'] = 'Question marquée comme « ✔ Résolue » par le formateur.';
                    }
                }
                if ($returnPage === 'admin') {
                    header('Location: index.php?page=admin&tab=qna&course=' . urlencode($currentCourseSlug));
                } else {
                    header('Location: index.php?page=' . urlencode($returnPage) . '&id=' . urlencode($itemId) . '&course=' . urlencode($currentCourseSlug) . '#discussion-section');
                }
                exit;

            case 'admin_bulk_docker':
                $bulkCmd = trim((string)($post['bulk_cmd'] ?? 'refresh_runtime'));
                if ($bulkCmd === 'stop_all') {
                    $res = $this->docker->stopAllLabContainers((string)$currentUser['email']);
                    $_SESSION['flash_success'] = (string)($res['message'] ?? 'Conteneurs arrêtés.');
                } elseif ($bulkCmd === 'prune_stopped') {
                    $res = $this->docker->pruneStoppedLabContainers((string)$currentUser['email']);
                    $_SESSION['flash_success'] = (string)($res['message'] ?? 'Conteneurs arrêtés purgés.');
                } else {
                    $rt = $this->docker->detectRuntime(true);
                    $this->storage->logAuditEvent(
                        (string)$currentUser['email'],
                        'DOCKER_RUNTIME_REFRESH',
                        'Rafraîchissement du runtime Docker (' . (string)($rt['mode'] ?? 'unknown') . ')'
                    );
                    $_SESSION['flash_success'] = 'Statut du démon Docker rafraîchi (' . (string)($rt['version'] ?? $rt['mode'] ?? 'OK') . ').';
                }
                header('Location: index.php?page=admin&tab=docker&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_reap_idle_docker':
                $maxIdle = max(60, (int)($post['max_idle_seconds'] ?? 3600));
                $res = $this->docker->reapIdleContainers($maxIdle);
                $_SESSION['flash_success'] = (string)($res['message'] ?? 'Nettoyage des conteneurs inactifs terminé.');
                header('Location: index.php?page=admin&tab=docker&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_heal_chaos':
                $res = $this->docker->healChaosScenario($currentCourseSlug);
                if (!empty($res['success'])) {
                    $_SESSION['flash_success'] = (string)($res['message'] ?? 'Environnement Chaos assaini.');
                } else {
                    $_SESSION['flash_error'] = (string)($res['message'] ?? 'Échec lors du nettoyage Chaos.');
                }
                header('Location: index.php?page=admin&tab=docker&course=' . urlencode($currentCourseSlug));
                exit;
        }

        return false;
    }

    /**
     * Dispatches super-administrator POST actions (cache, features, user lifecycle, throttle unlocks, courses).
     */
    public function handleAdminAction(
        string $action,
        array $post,
        array $currentUser,
        string $currentCourseSlug,
        array $coursesConfig
    ): bool {
        $adminActions = [
            'admin_clear_cache',
            'admin_save_features',
            'admin_apply_feature_profile',
            'admin_save_course_features',
            'admin_import_features_json',
            'admin_create_user',
            'admin_update_user',
            'admin_toggle_user_active',
            'admin_reset_password',
            'admin_bulk_users',
            'admin_unlock_throttle',
            'admin_update_enrollments',
            'admin_reset_user_progress',
            'admin_delete_user',
            'admin_import_users_csv',
            'admin_toggle_course',
            'admin_delete_course',
            'admin_add_course',
        ];
        if (!in_array($action, $adminActions, true)) {
            return false;
        }
        if (!$this->auth->isAdmin($currentUser)) {
            $this->denyForbidden('Accès refusé (403) : action réservée aux administrateurs.');
        }

        $selfId = (int)($currentUser['id'] ?? 0);

        switch ($action) {
            case 'admin_clear_cache':
                $catCleared = $this->courseRepo->clearCatalogCache();
                $parsedCleared = $this->mdParser->clearParsedCache();
                $this->storage->logAuditEvent(
                    (string)$currentUser['email'],
                    'ADMIN_CACHE_CLEAR',
                    "Purge des caches : catalogue ({$catCleared}) et markdown ({$parsedCleared})"
                );
                $_SESSION['flash_success'] = "Caches purgés avec succès : {$catCleared} cache(s) catalogue et {$parsedCleared} fichier(s) Markdown réinitialisés.";
                header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_save_features':
                $submitted = is_array($post['features'] ?? null) ? $post['features'] : [];
                $this->featureManager->updateFlags($submitted, (string)$currentUser['email']);
                $_SESSION['flash_success'] = 'Feature flags mis à jour et audités avec succès.';
                header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_apply_feature_profile':
                $profileKey = trim((string)($post['profile_key'] ?? ''));
                if ($profileKey !== '' && $this->featureManager->applyProfile($profileKey, (string)$currentUser['email'])) {
                    $_SESSION['flash_success'] = "Profil de déploiement « {$profileKey} » appliqué avec succès.";
                } else {
                    $_SESSION['flash_error'] = "Profil de déploiement inconnu ({$profileKey}).";
                }
                header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_save_course_features':
                $targetCourse = trim((string)($post['target_course_slug'] ?? $currentCourseSlug));
                if ($this->courseRepo->getCourse($targetCourse) === null) {
                    $_SESSION['flash_error'] = "Formation cible introuvable ({$targetCourse}).";
                    header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                if (!empty($post['reset_course_features'])) {
                    $this->featureManager->clearCourseOverrides($targetCourse, (string)$currentUser['email']);
                    $_SESSION['flash_success'] = "Surcharges réinitialisées pour la formation « {$targetCourse} ».";
                } else {
                    $submittedOverrides = is_array($post['course_features'] ?? null) ? $post['course_features'] : [];
                    $this->featureManager->setCourseOverrides($targetCourse, $submittedOverrides, (string)$currentUser['email']);
                    $_SESSION['flash_success'] = "Surcharges de fonctionnalités enregistrées pour « {$targetCourse} ».";
                }
                header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_import_features_json':
                $jsonPayload = trim((string)($post['features_json'] ?? ''));
                if ($jsonPayload !== '' && $this->featureManager->importConfigJson($jsonPayload, (string)$currentUser['email'])) {
                    $_SESSION['flash_success'] = 'Configuration JSON des fonctionnalités importée et appliquée.';
                } else {
                    $_SESSION['flash_error'] = 'JSON de configuration invalide ou aucun module reconnu.';
                }
                header('Location: index.php?page=admin&tab=system&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_create_user':
                $uName = trim((string)($post['name'] ?? ''));
                $uEmail = trim((string)($post['email'] ?? ''));
                $uPass = (string)($post['password'] ?? '');
                $uRole = trim((string)($post['role'] ?? 'student'));
                $uComp = trim((string)($post['company'] ?? ''));

                if (!$this->auth->isValidRole($uRole)) {
                    $_SESSION['flash_error'] = 'Rôle utilisateur invalide.';
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                if (strlen($uPass) < 6) {
                    $_SESSION['flash_error'] = 'Le mot de passe initial doit contenir au moins 6 caractères.';
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }

                $created = $this->auth->registerUser($uName, $uEmail, $uPass, $uRole, $uComp, [$currentCourseSlug]);
                if ($created !== null) {
                    $_SESSION['flash_success'] = "Utilisateur {$uName} ({$uRole}) créé avec succès.";
                } else {
                    $_SESSION['flash_error'] = "Impossible de créer l'utilisateur (email déjà existant ou invalide).";
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_update_user':
                $targetId = (int)($post['user_id'] ?? 0);
                $targetUser = $this->storage->getUserById($targetId);
                if ($targetUser !== null) {
                    $targetUser['name'] = trim((string)($post['name'] ?? $targetUser['name']));
                    $targetUser['company'] = trim((string)($post['company'] ?? $targetUser['company']));
                    if ($targetId !== 1 && $targetId !== $selfId) {
                        $newRole = trim((string)($post['role'] ?? $targetUser['role']));
                        if (!$this->auth->isValidRole($newRole)) {
                            $_SESSION['flash_error'] = 'Rôle utilisateur invalide.';
                            header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                            exit;
                        }
                        $targetUser['role'] = $newRole;
                        $targetUser['active'] = isset($post['active']) && $post['active'] === '1';
                    }
                    $resetPass = trim((string)($post['reset_password'] ?? ''));
                    if (strlen($resetPass) >= 6) {
                        $targetUser['password_hash'] = password_hash($resetPass, PASSWORD_BCRYPT);
                    }
                    $this->storage->saveUser($targetUser);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_USER_UPDATE', "Modification du compte #{$targetId} ({$targetUser['email']})");
                    $_SESSION['flash_success'] = "Compte de {$targetUser['name']} mis à jour.";
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_toggle_user_active':
                $targetId = (int)($post['user_id'] ?? 0);
                if ($targetId <= 1 || $targetId === $selfId) {
                    $_SESSION['flash_error'] = 'Le compte Super-Admin (#1) et votre propre session ne peuvent pas être suspendus.';
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                $targetUser = $this->storage->getUserById($targetId);
                if ($targetUser === null) {
                    $_SESSION['flash_error'] = "Compte #{$targetId} introuvable.";
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                $newActive = isset($post['active'])
                    ? ($post['active'] === '1')
                    : empty($targetUser['active']);
                $this->storage->setUserActive($targetId, $newActive);
                $statusLabel = $newActive ? 'activé' : 'suspendu';
                $this->storage->logAuditEvent(
                    (string)$currentUser['email'],
                    'ADMIN_USER_STATUS',
                    "Compte #{$targetId} ({$targetUser['email']}) {$statusLabel}"
                );
                $_SESSION['flash_success'] = "Compte de {$targetUser['name']} {$statusLabel}.";
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_reset_password':
                $targetId = (int)($post['user_id'] ?? 0);
                $newPass = (string)($post['new_password'] ?? '');
                if (strlen($newPass) < 6) {
                    $_SESSION['flash_error'] = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                $targetUser = $this->storage->getUserById($targetId);
                if ($targetUser === null) {
                    $_SESSION['flash_error'] = "Utilisateur #{$targetId} introuvable.";
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                if ($this->storage->adminResetUserPassword($targetId, $hash)) {
                    $this->storage->logAuditEvent(
                        (string)$currentUser['email'],
                        'ADMIN_PASSWORD_RESET',
                        "Réinitialisation administrateur du mot de passe pour #{$targetId} ({$targetUser['email']})"
                    );
                    $_SESSION['flash_success'] = "Mot de passe réinitialisé pour {$targetUser['name']}.";
                } else {
                    $_SESSION['flash_error'] = "Impossible de réinitialiser le mot de passe pour #{$targetId}.";
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_bulk_users':
                $this->processBulkUsers($post, $currentUser, $currentCourseSlug);

            case 'admin_unlock_throttle':
                $throttleKey = trim((string)($post['throttle_key'] ?? ''));
                $targetEmail = trim((string)($post['email'] ?? ''));
                $targetIp = trim((string)($post['ip'] ?? ''));
                if ($throttleKey !== '') {
                    $this->storage->clearLoginThrottle($throttleKey, '');
                    $label = $throttleKey;
                } else {
                    $this->storage->clearLoginThrottle($targetEmail, $targetIp);
                    $label = trim($targetEmail . ($targetIp !== '' ? " ({$targetIp})" : ''));
                }
                $this->storage->logAuditEvent(
                    (string)$currentUser['email'],
                    'ADMIN_THROTTLE_UNLOCK',
                    "Déverrouillage anti-brute-force pour {$label}"
                );
                $_SESSION['flash_success'] = "Verrouillage anti-brute-force levé pour {$label}.";
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_update_enrollments':
                $targetId = (int)($post['user_id'] ?? 0);
                $targetUser = $this->storage->getUserById($targetId);
                if ($targetUser !== null) {
                    $knownCourses = array_keys($this->courseRepo->getAllCourses());
                    $submittedCourses = array_values(array_intersect(
                        array_map('strval', (array)($post['enrolled_courses'] ?? [])),
                        $knownCourses
                    ));
                    $targetUser['enrolled_courses'] = $submittedCourses;
                    $this->storage->saveUser($targetUser);
                    $this->storage->logAuditEvent(
                        (string)$currentUser['email'],
                        'ADMIN_ENROLLMENT_UPDATE',
                        "Inscriptions mises à jour pour #{$targetId} ({$targetUser['email']}) : " . implode(', ', $submittedCourses)
                    );
                    $_SESSION['flash_success'] = "Inscriptions aux formations mises à jour pour {$targetUser['name']}.";
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_reset_user_progress':
                $targetId = (int)($post['user_id'] ?? 0);
                $targetCourse = trim((string)($post['target_course'] ?? $currentCourseSlug));
                if ($targetId > 0 && $this->storage->resetUserProgress($targetId, $targetCourse)) {
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_PROGRESS_RESET', "Réinitialisation progression utilisateur #{$targetId} sur {$targetCourse}");
                    $_SESSION['flash_success'] = "Progression de l'utilisateur #{$targetId} réinitialisée sur « {$targetCourse} ».";
                } else {
                    $_SESSION['flash_error'] = "Impossible de réinitialiser la progression pour l'utilisateur #{$targetId}.";
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_delete_user':
                $targetId = (int)($post['user_id'] ?? 0);
                if ($targetId <= 1 || $targetId === $selfId) {
                    $_SESSION['flash_error'] = 'Le compte Super-Admin (#1) et votre propre session ne peuvent pas être supprimés.';
                    header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                    exit;
                }
                if ($this->storage->deleteUser($targetId)) {
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_USER_DELETE', "Suppression du compte #{$targetId}");
                    $_SESSION['flash_success'] = "Compte utilisateur #{$targetId} supprimé.";
                } else {
                    $_SESSION['flash_error'] = 'Impossible de supprimer ce compte (le Super-Admin est protégé).';
                }
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_import_users_csv':
                $csvRaw = trim((string)($post['csv_content'] ?? ($post['csv_payload'] ?? '')));
                $defaultPw = trim((string)($post['default_password'] ?? ''));
                if (strlen($defaultPw) < 6) {
                    $defaultPw = bin2hex(random_bytes(8)) . '!Lp1';
                }
                $importRes = $this->storage->importUsersFromCsv($csvRaw, $defaultPw, $currentCourseSlug);
                $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_CSV_IMPORT', "Import cohorte CSV : {$importRes['created']} créés, {$importRes['skipped']} ignorés");
                $_SESSION['flash_success'] = "Import CSV terminé : {$importRes['created']} compte(s) créé(s), {$importRes['skipped']} ligne(s) ignorée(s).";
                header('Location: index.php?page=admin&tab=users&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_toggle_course':
                $cSlug = trim((string)($post['course_slug'] ?? ''));
                $newActive = isset($post['active']) && $post['active'] === '1';
                if ($cSlug !== '' && $this->storage->toggleCustomCourse($cSlug, $newActive)) {
                    $this->courseRepo->clearCatalogCache($cSlug);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_COURSE_TOGGLE', "Formation {$cSlug} : " . ($newActive ? 'activée' : 'désactivée'));
                    $_SESSION['flash_success'] = "Statut de la formation « {$cSlug} » mis à jour.";
                } else {
                    $_SESSION['flash_error'] = "Formation personnalisée introuvable ({$cSlug}).";
                }
                header('Location: index.php?page=admin&tab=courses&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_delete_course':
                $cSlug = trim((string)($post['course_slug'] ?? ''));
                if ($cSlug !== '' && $this->storage->deleteCustomCourse($cSlug)) {
                    $this->courseRepo->clearCatalogCache($cSlug);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_COURSE_DELETE', "Suppression de la formation personnalisée {$cSlug}");
                    $_SESSION['flash_success'] = "Formation personnalisée « {$cSlug} » supprimée.";
                } else {
                    $_SESSION['flash_error'] = 'Impossible de supprimer cette formation.';
                }
                header('Location: index.php?page=admin&tab=courses&course=' . urlencode($currentCourseSlug));
                exit;

            case 'admin_add_course':
                $cSlug = trim((string)preg_replace('/[^a-zA-Z0-9\-]/', '-', strtolower(trim((string)($post['slug'] ?? '')))), '-');
                $cTitle = trim((string)($post['title'] ?? ''));
                $cSource = trim((string)($post['source_root'] ?? ''));
                $cCat = trim((string)($post['category'] ?? 'Infrastructure & Cloud'));
                $cDur = trim((string)($post['duration'] ?? '35h (5 jours)'));

                if ($cSlug === 'postgresql-18' || isset($coursesConfig[$cSlug])) {
                    $_SESSION['flash_error'] = "Identifiant réservé : impossible de remplacer la formation noyau « {$cSlug} ».";
                    header('Location: index.php?page=admin&tab=courses&course=' . urlencode($currentCourseSlug));
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
                    $this->storage->saveCustomCourse([
                        'slug' => $cSlug,
                        'title' => $cTitle,
                        'short_title' => $cTitle,
                        'badge' => strtoupper($cSlug),
                        'category' => $cCat,
                        'level' => 'Professionnel (Qualiopi)',
                        'duration' => $cDur,
                        'instructor' => 'Jean-Marie Renouard',
                        'icon' => 'assets/img/lightpath_logo_dark_text.png',
                        'active' => true,
                        'docker_enabled' => true,
                        'source_root' => $resolvedSourceRoot,
                        'pgscripts_root' => '',
                        'description' => trim((string)($post['description'] ?? 'Formation professionnelle LightPath SARL.')),
                        'tracks' => [],
                    ]);
                    $this->courseRepo->clearCatalogCache($cSlug);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'ADMIN_COURSE_ADD', "Ajout de la formation '{$cTitle}' ({$cSlug})");
                    $_SESSION['flash_success'] = "Nouvelle formation « {$cTitle} » enregistrée dans le portail multi-formations !";
                }
                header('Location: index.php?page=admin&tab=courses&course=' . urlencode($currentCourseSlug));
                exit;
        }

        return false;
    }
}
