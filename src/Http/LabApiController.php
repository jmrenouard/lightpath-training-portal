<?php
declare(strict_types=1);

namespace LightPath\LMS\Http;

use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\StorageInterface;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;

/**
 * Handles Docker sandbox lifecycle, SQL console, savepoint, pgbench, diagnostics,
 * readiness, Qualiopi oracle, and Chaos Engineering JSON API endpoints.
 */
class LabApiController
{
    private const DOCKER_API_ACTIONS = [
        'api_docker_status',
        'api_docker_action',
        'api_seed_lab',
        'api_docker_logs',
        'api_docker_stats',
        'api_run_sql',
        'api_schema_inspect',
        'api_run_oracle',
        'api_validate_lab',
        'api_run_lab_solution',
        'api_lab_savepoint',
        'api_lab_pgbench',
        'api_lab_pg_diag',
        'api_lab_readiness',
    ];

    private StorageInterface $storage;
    private Auth $auth;
    private FeatureManager $featureManager;
    private CourseRepository $courseRepo;
    private DockerManager $docker;
    private LabSqlRunner $sqlRunner;

    public function __construct(
        StorageInterface $storage,
        Auth $auth,
        FeatureManager $featureManager,
        CourseRepository $courseRepo,
        DockerManager $docker,
        LabSqlRunner $sqlRunner
    ) {
        $this->storage = $storage;
        $this->auth = $auth;
        $this->featureManager = $featureManager;
        $this->courseRepo = $courseRepo;
        $this->docker = $docker;
        $this->sqlRunner = $sqlRunner;
    }

    private function isFeatureEnabledForCourse(string $flag, string $courseSlug): bool
    {
        if (method_exists($this->featureManager, 'isEnabledForCourse')) {
            return $this->featureManager->isEnabledForCourse($flag, $courseSlug);
        }
        return $this->featureManager->isEnabled($flag);
    }

    private function emitJson(array $payload, int $statusCode = 200): never
    {
        if ($statusCode !== 200) {
            http_response_code($statusCode);
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $encoded !== false ? $encoded : '{"success":false,"message":"JSON serialization failure."}';
        exit;
    }

    /**
     * Resolves a scalar savepoint name from structured DockerManager response payloads.
     */
    private function resolveSavepointName(array $res, string $fallback): string
    {
        if (isset($res['name']) && is_string($res['name']) && $res['name'] !== '') {
            return $res['name'];
        }
        $sp = $res['savepoint'] ?? null;
        if (is_array($sp) && isset($sp['name']) && is_string($sp['name']) && $sp['name'] !== '') {
            return $sp['name'];
        }
        if (is_string($sp) && $sp !== '') {
            return $sp;
        }
        if (isset($res['deleted']) && is_string($res['deleted']) && $res['deleted'] !== '') {
            return $res['deleted'];
        }
        return $fallback;
    }

    /**
     * Handles Docker savepoint operations (create, list, restore, delete) cleanly.
     */
    private function handleSavepointAction(int $userId, array $payload, bool $allowSharedFallback): never
    {
        $op = strtolower(trim((string)($payload['op'] ?? 'list')));
        $name = trim((string)($payload['name'] ?? ''));
        $db = trim((string)($payload['db'] ?? ($payload['database'] ?? 'formation_pg')));
        if ($db === '') {
            $db = 'formation_pg';
        }

        switch ($op) {
            case 'create':
                $targetName = $name !== '' ? $name : 'checkpoint_1';
                $res = $this->docker->createSavepoint($userId, $targetName, $db, $allowSharedFallback);
                $res['name'] = $this->resolveSavepointName($res, $targetName);
                $this->emitJson($res);

            case 'restore':
                $targetName = $name !== '' ? $name : 'checkpoint_1';
                $res = $this->docker->restoreSavepoint($userId, $targetName, $db, $allowSharedFallback);
                $res['name'] = $this->resolveSavepointName($res, $targetName);
                $this->emitJson($res);

            case 'delete':
                if ($name === '') {
                    $this->emitJson(['success' => false, 'message' => 'Nom de savepoint requis pour la suppression.'], 400);
                }
                $res = $this->docker->deleteSavepoint($userId, $name, $allowSharedFallback);
                $res['name'] = $this->resolveSavepointName($res, $name);
                $this->emitJson($res);

            case 'list':
                $res = $this->docker->listSavepoints($userId, $allowSharedFallback);
                $this->emitJson($res);

            default:
                $this->emitJson(['success' => false, 'message' => 'Opération savepoint inconnue (create|list|restore|delete).'], 400);
        }
    }

    /**
     * Dispatches lab workbench JSON API actions after enforcing course feature toggles and lock policies.
     */
    public function handleAction(string $action, array $payload, array $currentUser, string $currentCourseSlug): bool
    {
        if (!in_array($action, self::DOCKER_API_ACTIONS, true) && $action !== 'api_chaos') {
            return false;
        }

        $userId = (int)($currentUser['id'] ?? 0);
        $isStaff = $this->auth->isStaff($currentUser);

        if (in_array($action, self::DOCKER_API_ACTIONS, true)
            && !$this->isFeatureEnabledForCourse('docker_labs', $currentCourseSlug)
            && !$isStaff
        ) {
            $this->emitJson([
                'success' => false,
                'message' => 'Les laboratoires Docker sont actuellement désactivés par l\'administrateur.',
            ], 403);
        }

        switch ($action) {
            case 'api_docker_status':
                $this->emitJson([
                    'success' => true,
                    'status' => $this->docker->getUserContainerStatus($userId),
                ]);

            case 'api_docker_action':
                $cmdAction = (string)($payload['docker_cmd'] ?? 'status');
                $targetUserId = (isset($payload['target_user_id']) && $isStaff)
                    ? (int)$payload['target_user_id']
                    : $userId;

                $res = match ($cmdAction) {
                    'start' => $this->docker->startUserContainer($targetUserId, (string)$currentUser['email']),
                    'stop' => $this->docker->stopUserContainer($targetUserId, (string)$currentUser['email']),
                    'restart' => $this->docker->restartUserContainer($targetUserId, (string)$currentUser['email']),
                    'reset' => $this->docker->resetUserContainer($targetUserId, (string)$currentUser['email']),
                    default => ['success' => true, 'status' => $this->docker->getUserContainerStatus($targetUserId)],
                };
                $this->emitJson($res);

            case 'api_seed_lab':
                $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $isStaff)
                    ? (int)$payload['target_user_id']
                    : $userId;
                $dataset = trim((string)($payload['dataset'] ?? 'all'));
                $forceReset = !isset($payload['force_reset']) || (bool)$payload['force_reset'];
                $this->emitJson(
                    $this->docker->seedUserContainer($targetUserId, $dataset, $forceReset, (string)$currentUser['email'], $isStaff)
                );

            case 'api_docker_logs':
                $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $isStaff)
                    ? (int)$payload['target_user_id']
                    : $userId;
                $tail = max(10, min(500, (int)($payload['tail'] ?? 60)));
                $this->emitJson($this->docker->getContainerLogs($targetUserId, $tail, $isStaff));

            case 'api_docker_stats':
                $targetUserId = ((int)($payload['target_user_id'] ?? 0) > 0 && $isStaff)
                    ? (int)$payload['target_user_id']
                    : $userId;
                $this->emitJson($this->docker->getContainerStats($targetUserId));

            case 'api_run_sql':
                $sql = (string)($payload['sql'] ?? '');
                $database = (string)($payload['database'] ?? ($payload['db'] ?? 'formation_pg'));
                $this->emitJson($this->sqlRunner->executeSql($userId, $sql, $database));

            case 'api_schema_inspect':
                $database = (string)($payload['database'] ?? ($payload['db'] ?? 'dvdrental'));
                $this->emitJson($this->sqlRunner->inspectDatabaseSchema($userId, $database));

            case 'api_run_oracle':
            case 'api_validate_lab':
                $labId = (string)($payload['lab_id'] ?? '');
                $database = isset($payload['database']) && $payload['database'] !== '' ? (string)$payload['database'] : null;
                $labMeta = $this->courseRepo->getLab($currentCourseSlug, $labId);
                $labMdPath = (string)($labMeta['source_md_path'] ?? '');
                $res = $this->sqlRunner->validateLabOracle($userId, $labId, $labMdPath, $database);
                if (!empty($res['passed']) && $labId !== '') {
                    $this->storage->markItemCompleted($userId, $currentCourseSlug, 'lab', $labId, true);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'LAB_ORACLE_PASS', "Validation Oracle réussie sur {$labId} ({$res['bloom']})");
                }
                $this->emitJson($res);

            case 'api_run_lab_solution':
                $classroomState = $this->storage->getClassroomState($currentCourseSlug);
                $solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$isStaff;
                if ($solutionsLocked) {
                    $this->emitJson([
                        'success' => false,
                        'passed' => false,
                        'error' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
                        'message' => 'Les corrigés officiels sont temporairement verrouillés par le formateur.',
                    ], 403);
                }

                $labId = (string)($payload['lab_id'] ?? '');
                $database = isset($payload['database']) && $payload['database'] !== '' ? (string)$payload['database'] : null;
                $labMeta = $this->courseRepo->getLab($currentCourseSlug, $labId);
                $labMdPath = (string)($labMeta['source_md_path'] ?? '');
                $res = $this->sqlRunner->runLabSolution($userId, $labId, $labMdPath, $database);
                if (!empty($res['passed']) && $labId !== '') {
                    $this->storage->markItemCompleted($userId, $currentCourseSlug, 'lab', $labId, true);
                    $this->storage->logAuditEvent((string)$currentUser['email'], 'LAB_SOLUTION_TEST', "Test et validation du corrigé officiel sur {$labId}");
                }
                $this->emitJson($res);

            case 'api_lab_savepoint':
                $this->handleSavepointAction($userId, $payload, $isStaff);

            case 'api_lab_pgbench':
                $clients = (int)($payload['clients'] ?? 4);
                $duration = (int)($payload['duration'] ?? ($payload['duration_seconds'] ?? 2));
                $selectOnly = !empty($payload['select_only']);
                $db = trim((string)($payload['db'] ?? ($payload['database'] ?? 'formation_pg')));
                if ($db === '') {
                    $db = 'formation_pg';
                }
                $this->emitJson(
                    $this->docker->runPgbenchBenchmark($userId, $clients, $duration, $selectOnly, $db, $isStaff)
                );

            case 'api_lab_pg_diag':
                $db = trim((string)($payload['db'] ?? ($payload['database'] ?? 'formation_pg')));
                if ($db === '') {
                    $db = 'formation_pg';
                }
                $this->emitJson($this->sqlRunner->inspectPgDiagnostics($userId, $db, $isStaff));

            case 'api_lab_readiness':
                $labId = trim((string)($payload['lab_id'] ?? ''));
                $this->emitJson($this->sqlRunner->checkLabReadiness($userId, $labId, $isStaff));

            case 'api_chaos':
                if (!$isStaff) {
                    $this->emitJson(['success' => false, 'message' => 'Accès réservé aux formateurs et administrateurs.'], 403);
                }
                if (!$this->isFeatureEnabledForCourse('chaos_engineering', $currentCourseSlug)) {
                    $this->emitJson(['success' => false, 'message' => 'Le simulateur Chaos DBA est actuellement désactivé.'], 403);
                }
                $scenario = (string)($payload['scenario'] ?? 'bloat');
                $chaosAction = (string)($payload['chaos_action'] ?? 'inject');
                $this->emitJson($this->docker->triggerChaosScenario($scenario, $chaosAction, (string)$currentUser['email']));
        }

        return false;
    }
}
