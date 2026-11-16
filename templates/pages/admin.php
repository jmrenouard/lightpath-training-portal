<?php
declare(strict_types=1);

use LightPath\LMS\Core\AdminService;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\PdoStorage;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

/**
 * Modular trainer & administrator cockpit router dispatching across 7 tab partials.
 */
$appConfig = $appConfig ?? ($config ?? []);
$coursesConfig = $coursesConfig ?? (is_file(__DIR__ . '/../../config/courses.php') ? require __DIR__ . '/../../config/courses.php' : []);
$courseRepo = $courseRepo ?? new CourseRepository($coursesConfig, $storage->getCustomCourses());
$currentCourse = $currentCourse ?? ($courseRepo->getCourse($currentCourseSlug) ?? [
    'slug' => $currentCourseSlug,
    'short_title' => strtoupper($currentCourseSlug),
    'modules' => [],
    'labs' => [],
]);
$courseModules = $currentCourse['modules'] ?? [];
$courseLabs = $currentCourse['labs'] ?? [];
$totalModules = $totalModules ?? count($courseModules);
$totalLabs = $totalLabs ?? count($courseLabs);
$csrfToken = $csrfToken ?? Security::getCsrfToken();

$isAdminUser = $auth->isAdmin($currentUser);
$adminService = $adminService ?? new AdminService();
$featureManager = $featureManager ?? new FeatureManager($storage);

$allowedTabs = $isAdminUser
    ? ['cohort', 'users', 'qna', 'docker', 'courses', 'audit', 'system']
    : ['cohort', 'qna', 'docker'];

$adminTab = (string)($_GET['tab'] ?? 'cohort');
if (!in_array($adminTab, $allowedTabs, true)) {
    $adminTab = 'cohort';
}

$allUsers = $storage->getUsers();
$allCourseDiscussions = $storage->getAllCourseDiscussions($currentCourseSlug);
$customCourses = $storage->getCustomCourses();
$allManageableCourses = array_merge($coursesConfig ?? [], $customCourses);

$totalAttendanceHalfDays = (int)($currentCourse['half_days'] ?? (
    preg_match('/(\d+)\s*jours?/i', (string)($currentCourse['duration'] ?? ''), $dm)
        ? ((int)$dm[1] * 2)
        : max(2, (int)ceil(max(1, count($courseModules)) / 1.7))
));

$allProgress = [];
$cohortKpis = [];
$classroomState = $classroomState ?? [];
$atRiskLearners = [];
$moduleCompletionMatrix = ['modules' => [], 'labs' => [], 'learners_count' => 0];

if ($adminTab === 'cohort') {
    $allProgress = $storage->getAllProgress();
    $classroomState = $storage->getClassroomState($currentCourseSlug);
    $cohortKpis = $adminService->computeCohortKpis(
        $allUsers,
        $storage,
        $courseModules,
        $courseLabs,
        $currentCourseSlug,
        $allProgress
    );
    $atRiskLearners = $adminService->detectAtRiskLearners($allUsers, $allProgress, $currentCourse);
    $moduleCompletionMatrix = $adminService->computeModuleCompletionMatrix($allUsers, $allProgress, $currentCourse);
}

$labContainers = ($adminTab === 'docker') ? $docker->listAllLabContainers() : [];
$fleetSummary = ($adminTab === 'docker') ? $docker->getFleetSummary() : [];

$userSearchQuery = trim((string)($_GET['uq'] ?? ''));
$userRoleFilter = trim((string)($_GET['ur'] ?? ''));
$filteredUsers = ($adminTab === 'users') ? $adminService->filterUsers($allUsers, $userSearchQuery, $userRoleFilter) : [];
$loginThrottles = ($adminTab === 'users' && $isAdminUser) ? $storage->listLoginThrottles() : [];

$auditCategoryFilter = trim((string)($_GET['audit_cat'] ?? ''));
$auditActorFilter = trim((string)($_GET['audit_actor'] ?? ''));
$auditLogs = ($adminTab === 'audit' && $isAdminUser)
    ? $adminService->filterAuditLogs($storage->getAuditLogs(200, true), $auditCategoryFilter, $auditActorFilter)
    : [];
$auditIntegrity = ($adminTab === 'audit' && $isAdminUser) ? $storage->verifyAuditIntegrity(true) : ['valid' => true];

$featureDefs = ($adminTab === 'system' && $isAdminUser) ? $featureManager->getDefinitions() : [];
$featureState = ($adminTab === 'system' && $isAdminUser) ? $featureManager->all() : [];
$featureProfiles = ($adminTab === 'system' && $isAdminUser && method_exists($featureManager, 'getProfiles'))
    ? $featureManager->getProfiles()
    : [];
$healthReport = ($adminTab === 'system' && $isAdminUser)
    ? $adminService->getSystemHealthReport(
        $appConfig ?? [],
        $storage,
        $docker,
        $courseRepo,
        $storage instanceof PdoStorage ? $storage : null
    )
    : ['summary' => ['ok' => 0, 'total' => 0], 'checks' => []];
?>

<div class="hero-banner hero-banner-compact">
    <div>
        <span class="badge-code">PORTAIL D'ADMINISTRATION &amp; COCKPIT FORMATEUR</span>
        <h1 class="hero-title-md">🛡️ Administration des Comptes, Cohortes, Docker &amp; Formations</h1>
        <p class="hero-subtitle-sm">
            Supervisez la progression des apprenants en temps réel, pilotez la salle de classe live, modérez le forum Q&amp;A, importez vos cohortes CSV et exportez vos packages SCORM 1.2.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-sm">
        <a href="index.php?page=admin&tab=cohort&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'cohort' ? 'btn-primary' : 'btn-outline' ?>">📊 Suivi Cohorte &amp; Qualiopi</a>
        <?php if ($isAdminUser): ?>
            <a href="index.php?page=admin&tab=users&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'users' ? 'btn-primary' : 'btn-outline' ?>">👥 Gestion des Comptes (<span class="tabular-nums"><?= count($allUsers) ?></span>)</a>
        <?php endif; ?>
        <a href="index.php?page=admin&tab=qna&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'qna' ? 'btn-primary' : 'btn-outline' ?>">💬 Questions Q&amp;A (<span class="tabular-nums"><?= count($allCourseDiscussions) ?></span>)</a>
        <a href="index.php?page=admin&tab=docker&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'docker' ? 'btn-primary' : 'btn-outline' ?>">🐳 Conteneurs Docker &amp; Chaos DBA</a>
        <?php if ($isAdminUser): ?>
            <a href="index.php?page=admin&tab=courses&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'courses' ? 'btn-primary' : 'btn-outline' ?>">🎓 Multi-Formations (<span class="tabular-nums"><?= count($allManageableCourses) ?></span>)</a>
            <a href="index.php?page=admin&tab=audit&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'audit' ? 'btn-primary' : 'btn-outline' ?>">📜 Journal d'Audit</a>
            <a href="index.php?page=admin&tab=system&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'system' ? 'btn-primary' : 'btn-outline' ?>">⚙️ Caches &amp; Système</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($adminTab === 'cohort'): ?>
    <?php require __DIR__ . '/../partials/admin/tab_cohort.php'; ?>
<?php elseif ($adminTab === 'users' && $isAdminUser): ?>
    <?php require __DIR__ . '/../partials/admin/tab_users.php'; ?>
<?php elseif ($adminTab === 'qna'): ?>
    <?php require __DIR__ . '/../partials/admin/tab_qna.php'; ?>
<?php elseif ($adminTab === 'docker'): ?>
    <?php require __DIR__ . '/../partials/admin/tab_docker.php'; ?>
<?php elseif ($adminTab === 'courses' && $isAdminUser): ?>
    <?php require __DIR__ . '/../partials/admin/tab_courses.php'; ?>
<?php elseif ($adminTab === 'system'): ?>
    <?php if ($isAdminUser): ?>
        <?php require __DIR__ . '/../partials/admin/tab_system.php'; ?>
    <?php endif; ?>
<?php elseif ($isAdminUser): ?>
    <?php require __DIR__ . '/../partials/admin/tab_audit.php'; ?>
<?php endif; ?>

<?php if ($isAdminUser && $adminTab !== 'users'): ?>
    <details class="lms-card mt-md">
        <summary class="btn btn-outline btn-sm">⚡ Raccourci Administrateur : Création Compte &amp; Import CSV Cohorte</summary>
        <div class="admin-split-grid mt-md">
            <form method="POST" action="index.php?page=admin&action=admin_create_user&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="quick-create-name">Nom Complet</label>
                    <input id="quick-create-name" type="text" name="name" class="form-control" autocomplete="name" required/>
                </div>
                <div class="form-group">
                    <label for="quick-create-email">Email</label>
                    <input id="quick-create-email" type="email" name="email" class="form-control" autocomplete="email" required/>
                </div>
                <div class="form-group">
                    <label for="quick-create-password">Mot de passe initial</label>
                    <input id="quick-create-password" type="password" name="password" class="form-control" autocomplete="new-password" required minlength="6"/>
                </div>
                <button type="submit" class="btn btn-emerald btn-sm">➕ Créer Compte</button>
            </form>
            <form method="POST" action="index.php?page=admin&tab=users&action=admin_import_users_csv&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="quick-csv-content">Import CSV Cohorte</label>
                    <textarea id="quick-csv-content" name="csv_content" class="form-control" rows="3" required placeholder="nom;email;entreprise;student"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">📥 Importer CSV</button>
            </form>
        </div>
    </details>
<?php endif; ?>
