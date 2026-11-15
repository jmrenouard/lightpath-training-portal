<?php
declare(strict_types=1);

use LightPath\LMS\Core\AdminService;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\PdoStorage;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

$appConfig = $appConfig ?? ($config ?? []);
$coursesConfig = $coursesConfig ?? (is_file(__DIR__ . '/../../config/courses.php') ? require __DIR__ . '/../../config/courses.php' : []);
$courseRepo = $courseRepo ?? new CourseRepository($coursesConfig, $storage->getCustomCourses());
$currentCourse = $currentCourse ?? ($courseRepo->getCourse($currentCourseSlug) ?? [
    'short_title' => strtoupper($currentCourseSlug),
    'modules' => [],
    'labs' => [],
]);
$courseModules = $currentCourse['modules'] ?? [];
$courseLabs = $currentCourse['labs'] ?? [];
$totalModules = $totalModules ?? count($courseModules);
$totalLabs = $totalLabs ?? count($courseLabs);

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
$classroomState = [];
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
}

$labContainers = ($adminTab === 'docker') ? $docker->listAllLabContainers() : [];

$userSearchQuery = trim((string)($_GET['uq'] ?? ''));
$userRoleFilter = trim((string)($_GET['ur'] ?? ''));
$filteredUsers = ($adminTab === 'users') ? $adminService->filterUsers($allUsers, $userSearchQuery, $userRoleFilter) : [];

$auditCategoryFilter = trim((string)($_GET['audit_cat'] ?? ''));
$auditActorFilter = trim((string)($_GET['audit_actor'] ?? ''));
$auditLogs = ($adminTab === 'audit')
    ? $adminService->filterAuditLogs($storage->getAuditLogs(200, true), $auditCategoryFilter, $auditActorFilter)
    : [];
?>

<div class="hero-banner hero-banner-compact">
    <div>
        <span class="badge-code">PORTAIL D'ADMINISTRATION & COCKPIT FORMATEUR</span>
        <h1 class="hero-title-md">🛡️ Administration des Comptes, Cohortes, Docker & Formations</h1>
        <p class="hero-subtitle-sm">
            Supervisez la progression des apprenants en temps réel, pilotez la salle de classe live, modérez le forum Q&amp;A, importez vos cohortes CSV et exportez vos packages SCORM 1.2.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-sm">
        <a href="index.php?page=admin&tab=cohort&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'cohort' ? 'btn-primary' : 'btn-outline' ?>">📊 Suivi Cohorte & Qualiopi</a>
        <?php if ($isAdminUser): ?>
            <a href="index.php?page=admin&tab=users&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'users' ? 'btn-primary' : 'btn-outline' ?>">👥 Gestion des Comptes (<span class="tabular-nums"><?= count($allUsers) ?></span>)</a>
        <?php endif; ?>
        <a href="index.php?page=admin&tab=qna&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'qna' ? 'btn-primary' : 'btn-outline' ?>">💬 Questions Q&amp;A (<span class="tabular-nums"><?= count($allCourseDiscussions) ?></span>)</a>
        <a href="index.php?page=admin&tab=docker&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'docker' ? 'btn-primary' : 'btn-outline' ?>">🐳 Conteneurs Docker & Chaos DBA</a>
        <?php if ($isAdminUser): ?>
            <a href="index.php?page=admin&tab=courses&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'courses' ? 'btn-primary' : 'btn-outline' ?>">🎓 Multi-Formations (<span class="tabular-nums"><?= count($allManageableCourses) ?></span>)</a>
            <a href="index.php?page=admin&tab=audit&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'audit' ? 'btn-primary' : 'btn-outline' ?>">📜 Journal d'Audit</a>
            <a href="index.php?page=admin&tab=system&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'system' ? 'btn-primary' : 'btn-outline' ?>">⚙️ Caches & Système</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($adminTab === 'cohort'): ?>
    <!-- Executive Cohort KPI Strip -->
    <section class="admin-kpi-strip" aria-label="Indicateurs exécutifs de la cohorte">
        <div class="kpi-card">
            <div class="kpi-label">Apprenants Actifs</div>
            <div class="kpi-value tabular-nums"><?= (int)$cohortKpis['active_students_count'] ?> / <?= (int)$cohortKpis['students_count'] ?></div>
            <div class="kpi-sub tabular-nums"><?= (int)$cohortKpis['staff_count'] ?> formateur(s)/admin(s)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Complétion Modules</div>
            <div class="kpi-value tabular-nums text-cyan"><?= number_format((float)$cohortKpis['avg_modules_pct'], 1, ',', ' ') ?>%</div>
            <div class="kpi-sub tabular-nums">Moyenne sur <?= (int)$cohortKpis['total_modules'] ?> modules</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Complétion Labs</div>
            <div class="kpi-value tabular-nums text-emerald"><?= number_format((float)$cohortKpis['avg_labs_pct'], 1, ',', ' ') ?>%</div>
            <div class="kpi-sub tabular-nums">Moyenne sur <?= (int)$cohortKpis['total_labs'] ?> ateliers</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Taux Réussite Examen</div>
            <div class="kpi-value tabular-nums text-amber"><?= number_format((float)$cohortKpis['exam_pass_rate'], 1, ',', ' ') ?>%</div>
            <div class="kpi-sub tabular-nums"><?= (int)$cohortKpis['exams_passed_count'] ?> certifié(s) • <?= (int)$cohortKpis['diagnostics_count'] ?> diag(s)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Assiduité Moyenne</div>
            <div class="kpi-value tabular-nums"><?= number_format((float)$cohortKpis['avg_attendance_half_days'], 1, ',', ' ') ?> <span class="text-sm text-muted">demi-j.</span></div>
            <div class="kpi-sub tabular-nums"><?= number_format((float)$cohortKpis['avg_attendance_hours'], 1, ',', ' ') ?>h émargées • <?= (int)$cohortKpis['surveys_count'] ?> avis</div>
        </div>
    </section>

    <!-- Live Classroom Broadcast & Pedagogical Locks -->
    <section class="lms-card admin-live-card mb-2">
        <span class="badge-code">📢 PILOTAGE SALLE DE CLASSE LIVE & VERROUILLAGE PÉDAGOGIQUE</span>
        <h3 class="section-heading-sm">Annonce Temps Réel aux Apprenants & Contrôle d'Accès (Corrigés / Examen)</h3>
        <form method="POST" action="index.php?page=admin&tab=cohort&action=save_classroom_state&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div class="admin-two-col">
                <div class="form-group mb-0">
                    <label for="broadcast-message-input">Message diffusé en haut de toutes les pages des stagiaires (laisser vide pour masquer)</label>
                    <input id="broadcast-message-input"
                           type="text"
                           name="broadcast_message"
                           class="form-control"
                           value="<?= Security::e((string)($classroomState['broadcast_message'] ?? '')) ?>"
                           placeholder="Ex: Pause déjeuner jusqu'à 14h00 — Reprise avec l'Atelier 06 (Indexation B-Tree/GIN)"/>
                </div>
                <div class="d-flex flex-col gap-xs">
                    <label class="checkbox-label" for="chk-lock-solutions">
                        <input id="chk-lock-solutions" type="checkbox" name="lock_solutions" value="1" <?= !empty($classroomState['lock_solutions']) ? 'checked' : '' ?>/>
                        <span>🔒 Verrouiller les Corrigés des Labs (Phase Recherche)</span>
                    </label>
                    <label class="checkbox-label" for="chk-lock-exam">
                        <input id="chk-lock-exam" type="checkbox" name="lock_exam" value="1" <?= !empty($classroomState['lock_exam']) ? 'checked' : '' ?>/>
                        <span>🔒 Verrouiller l'Examen Final Certifiant (40 QCM)</span>
                    </label>
                </div>
            </div>
            <div class="d-flex justify-end gap-sm mt-1">
                <button type="submit" class="btn btn-primary btn-sm">
                    📡 Appliquer en Direct à toute la Salle
                </button>
            </div>
        </form>
    </section>

    <!-- Cohort Qualiopi Matrix & Exports -->
    <div class="admin-toolbar mb-1">
        <h2 class="section-heading-md mb-0">📊 Matrice de Suivi Pédagogique de la Cohorte (<?= Security::e($currentCourse['short_title']) ?>)</h2>
        <div class="d-flex flex-wrap gap-sm">
            <input id="admin-cohort-search"
                   type="search"
                   class="form-control form-control-sm"
                   placeholder="🔎 Filtrer un apprenant, email, entreprise..."
                   aria-label="Filtrer la matrice de cohorte"
                   data-filter-table="cohort-matrix-table"/>
            <a href="index.php?action=export_qualiopi_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                📊 Exporter Audit Qualiopi (CSV)
            </a>
            <?php if ($featureManager->isEnabled('scorm_export')): ?>
                <a href="index.php?action=export_scorm_manifest&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
                    📄 Manifeste SCORM 1.2 (.xml)
                </a>
                <a href="index.php?action=export_scorm_zip&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm">
                    📦 Exporter Package SCORM 1.2 Complet (.zip)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="lms-table-wrap">
        <table id="cohort-matrix-table" class="lms-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Apprenant</th>
                    <th>Entreprise</th>
                    <th>Rôle</th>
                    <th>Modules Validés</th>
                    <th>Labs Docker Validés</th>
                    <th>Quiz / Examen</th>
                    <th>Émargement & Temps</th>
                    <th>Port Docker Dédié</th>
                    <th>Action Docker</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u):
                    $uid = (int)$u['id'];
                    $rowStat = $cohortKpis['per_user'][$uid] ?? [];
                    $uMods = (int)($rowStat['done_modules'] ?? 0);
                    $uLabs = (int)($rowStat['done_labs'] ?? 0);
                    $uQuiz = (int)($rowStat['quiz_count'] ?? 0);
                    $uDiag = $rowStat['diagnostic'] ?? null;
                    $uExam = $rowStat['latest_exam'] ?? null;
                    $signedHalfDays = (int)($rowStat['signed_half_days'] ?? 0);
                    $learningSeconds = (int)($rowStat['learning_seconds'] ?? 0);

                    $uPort = $docker->getUserPort($uid);
                    $uContainer = $docker->getContainerName($uid);
                    $searchHaystack = strtolower(trim(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['company'] ?? '') . ' ' . ($u['role'] ?? '')));
                ?>
                    <tr data-search="<?= Security::e($searchHaystack) ?>" data-role="<?= Security::e((string)($u['role'] ?? 'student')) ?>">
                        <td class="tabular-nums">#<?= $uid ?></td>
                        <td>
                            <strong><?= Security::e($u['name']) ?></strong>
                            <div class="text-xs text-muted"><?= Security::e($u['email']) ?></div>
                        </td>
                        <td><?= Security::e($u['company'] ?? '—') ?></td>
                        <td><span class="role-badge role-<?= Security::e($u['role']) ?>"><?= Security::e($u['role']) ?></span></td>
                        <td class="tabular-nums"><strong><?= $uMods ?></strong> / <?= $totalModules ?></td>
                        <td class="tabular-nums"><strong><?= $uLabs ?></strong> / <?= $totalLabs ?></td>
                        <td class="tabular-nums">
                            Quiz: <strong><?= $uQuiz ?></strong>/<?= $totalModules ?>
                            <?php if (is_array($uDiag)): ?>
                                <div class="text-xs text-secondary">Diag: <?= (int)($uDiag['percent'] ?? $uDiag['percentage'] ?? 0) ?>%</div>
                            <?php endif; ?>
                            <?php if (is_array($uExam)): ?>
                                <div class="text-xs <?= !empty($uExam['passed']) ? 'text-emerald' : 'text-amber' ?>">Exam: <?= (int)($uExam['percent'] ?? $uExam['percentage'] ?? 0) ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td class="tabular-nums">
                            <a href="index.php?page=attendance&course=<?= urlencode($currentCourseSlug) ?>&student_id=<?= $uid ?>" class="lms-link">
                                <strong><?= $signedHalfDays ?></strong>/<?= $totalAttendanceHalfDays ?> demi-j.
                            </a>
                            <div class="text-xs text-muted"><?= (int)round($learningSeconds / 60) ?> min LMS</div>
                        </td>
                        <td><code><?= Security::e($uContainer) ?> (<?= $uPort ?>)</code></td>
                        <td>
                            <div class="d-flex flex-wrap gap-xs">
                                <button type="button" class="btn btn-emerald btn-sm btn-docker-cmd" data-cmd="start" data-user-id="<?= $uid ?>">
                                    ▶ Start Lab
                                </button>
                                <button type="button" class="btn btn-outline btn-sm btn-docker-cmd" data-cmd="reset" data-user-id="<?= $uid ?>" data-confirm="Réinitialiser complètement le conteneur PostgreSQL de <?= Security::e($u['name']) ?> ?">
                                    🔄 Reset
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="docker-action-feedback" class="flash-banner flash-success mt-1 is-hidden"></div>

<?php elseif ($adminTab === 'users' && $isAdminUser): ?>
    <!-- User Account Management & Enrollment Control (Admin Only) -->
    <div class="reader-grid">
        <div>
            <div class="admin-toolbar mb-1">
                <h2 class="section-heading-md mb-0">👥 Comptes Enregistrés (<span class="tabular-nums"><?= count($filteredUsers) ?></span> / <span class="tabular-nums"><?= count($allUsers) ?></span>)</h2>
                <form method="GET" action="index.php" class="d-flex flex-wrap gap-sm">
                    <input type="hidden" name="page" value="admin"/>
                    <input type="hidden" name="tab" value="users"/>
                    <input type="hidden" name="course" value="<?= urlencode($currentCourseSlug) ?>"/>
                    <input id="admin-user-search"
                           type="search"
                           name="uq"
                           class="form-control form-control-sm"
                           value="<?= Security::e($userSearchQuery) ?>"
                           placeholder="🔎 Nom, email, entreprise..."
                           aria-label="Rechercher un utilisateur"
                           data-filter-table="users-management-table"/>
                    <select id="admin-user-role-filter"
                            name="ur"
                            class="form-control form-control-sm"
                            aria-label="Filtrer par rôle"
                            data-filter-role-table="users-management-table">
                        <option value="">Tous les rôles</option>
                        <option value="student" <?= $userRoleFilter === 'student' ? 'selected' : '' ?>>student</option>
                        <option value="trainer" <?= $userRoleFilter === 'trainer' ? 'selected' : '' ?>>trainer</option>
                        <option value="admin" <?= $userRoleFilter === 'admin' ? 'selected' : '' ?>>admin</option>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Filtrer</button>
                </form>
            </div>

            <div class="lms-table-wrap">
                <table id="users-management-table" class="lms-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom &amp; Email</th>
                            <th>Entreprise</th>
                            <th>Rôle &amp; Statut</th>
                            <th>Inscriptions</th>
                            <th>Actions Administrateur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredUsers as $u):
                            $uid = (int)$u['id'];
                            $userEnrolled = array_map('strval', (array)($u['enrolled_courses'] ?? []));
                            $searchHaystack = strtolower(trim(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['company'] ?? '')));
                        ?>
                            <tr data-search="<?= Security::e($searchHaystack) ?>" data-role="<?= Security::e((string)($u['role'] ?? 'student')) ?>">
                                <td class="tabular-nums">#<?= $uid ?></td>
                                <td>
                                    <strong><?= Security::e($u['name']) ?></strong><br/>
                                    <span class="text-xs text-muted"><?= Security::e($u['email']) ?></span>
                                </td>
                                <td><?= Security::e($u['company'] ?? '') ?></td>
                                <td>
                                    <span class="role-badge role-<?= Security::e($u['role']) ?>"><?= Security::e($u['role']) ?></span>
                                    <?= !empty($u['active']) ? '<span class="badge-completed">Actif</span>' : '<span class="badge-danger-text">Inactif</span>' ?>
                                </td>
                                <td>
                                    <?php if (($u['role'] ?? 'student') === 'student'): ?>
                                        <?php if (!array_key_exists('enrolled_courses', $u) || $u['enrolled_courses'] === null): ?>
                                            <span class="text-xs text-secondary">postgresql-18 (défaut)</span>
                                        <?php elseif ($userEnrolled === []): ?>
                                            <span class="badge-danger-text">Aucun cours assigné</span>
                                        <?php else: ?>
                                            <span class="text-xs text-secondary"><?= Security::e(implode(', ', $userEnrolled)) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">Toutes (Staff)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-xs">
                                        <details class="d-inline-block">
                                            <summary class="btn btn-outline btn-sm">✏️ Éditer</summary>
                                            <div class="admin-inline-popover">
                                                <form method="POST" action="index.php?page=admin&tab=users&action=admin_update_user">
                                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                    <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                    <div class="form-group">
                                                        <label for="edit-name-<?= $uid ?>">Nom</label>
                                                        <input id="edit-name-<?= $uid ?>" type="text" name="name" class="form-control" autocomplete="name" value="<?= Security::e($u['name']) ?>" required/>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="edit-company-<?= $uid ?>">Entreprise</label>
                                                        <input id="edit-company-<?= $uid ?>" type="text" name="company" class="form-control" autocomplete="organization" value="<?= Security::e($u['company'] ?? '') ?>"/>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="edit-role-<?= $uid ?>">Rôle</label>
                                                        <select id="edit-role-<?= $uid ?>" name="role" class="form-control">
                                                            <option value="student" <?= $u['role'] === 'student' ? 'selected' : '' ?>>student</option>
                                                            <option value="trainer" <?= $u['role'] === 'trainer' ? 'selected' : '' ?>>trainer</option>
                                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="edit-reset-pw-<?= $uid ?>">Nouveau mot de passe (optionnel)</label>
                                                        <input id="edit-reset-pw-<?= $uid ?>" type="password" name="reset_password" class="form-control" autocomplete="new-password" placeholder="Laisser vide pour conserver"/>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="checkbox-label" for="edit-active-<?= $uid ?>">
                                                            <input id="edit-active-<?= $uid ?>" type="checkbox" name="active" value="1" <?= !empty($u['active']) ? 'checked' : '' ?>/>
                                                            <span>Compte actif</span>
                                                        </label>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary btn-sm w-100">Enregistrer</button>
                                                </form>

                                                <hr class="hairline-divider"/>

                                                <form method="POST" action="index.php?page=admin&tab=users&action=admin_update_enrollments&course=<?= urlencode($currentCourseSlug) ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                    <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                    <div class="text-xs text-secondary mb-xs"><strong>Formations inscrites :</strong></div>
                                                    <div class="d-flex flex-col gap-xs mb-sm">
                                                        <?php foreach ($allManageableCourses as $cKey => $cCfg):
                                                            $cKeyStr = (string)$cKey;
                                                            $isChecked = in_array($cKeyStr, $userEnrolled, true);
                                                        ?>
                                                            <label class="checkbox-label text-xs" for="enroll-<?= $uid ?>-<?= Security::e($cKeyStr) ?>">
                                                                <input id="enroll-<?= $uid ?>-<?= Security::e($cKeyStr) ?>" type="checkbox" name="enrolled_courses[]" value="<?= Security::e($cKeyStr) ?>" <?= $isChecked ? 'checked' : '' ?>/>
                                                                <span><?= Security::e((string)($cCfg['short_title'] ?? $cCfg['title'] ?? $cKeyStr)) ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <button type="submit" class="btn btn-outline-cyan btn-sm w-100">🎓 Mettre à jour inscriptions</button>
                                                </form>

                                                <form method="POST"
                                                      action="index.php?page=admin&tab=users&action=admin_reset_user_progress&course=<?= urlencode($currentCourseSlug) ?>"
                                                      class="mt-sm"
                                                      data-confirm="Réinitialiser toute la progression (modules, labs, quiz) de <?= Security::e($u['name']) ?> sur <?= Security::e($currentCourseSlug) ?> ?">
                                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                    <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                    <input type="hidden" name="target_course" value="<?= Security::e($currentCourseSlug) ?>"/>
                                                    <button type="submit" class="btn btn-amber btn-sm w-100">🔄 Réinitialiser progression</button>
                                                </form>
                                            </div>
                                        </details>

                                        <?php if ($uid !== 1): ?>
                                            <form method="POST"
                                                  action="index.php?page=admin&action=admin_delete_user"
                                                  class="d-inline"
                                                  data-confirm="Supprimer définitivement le compte de <?= Security::e($u['name']) ?> ?">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Account Creation & Bulk CSV Cohort Import -->
        <aside class="toc-box">
            <h3 class="section-heading-sm mt-0">➕ Créer un Nouveau Compte</h3>
            <form method="POST" action="index.php?page=admin&action=admin_create_user&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="create-user-name">Nom Complet</label>
                    <input id="create-user-name" type="text" name="name" class="form-control" autocomplete="name" required placeholder="Ex: Marc Lefèvre"/>
                </div>
                <div class="form-group">
                    <label for="create-user-email">Email Professionnel</label>
                    <input id="create-user-email" type="email" name="email" class="form-control" autocomplete="email" required placeholder="marc.lefevre@client.fr"/>
                </div>
                <div class="form-group">
                    <label for="create-user-company">Entreprise / Équipe</label>
                    <input id="create-user-company" type="text" name="company" class="form-control" autocomplete="organization" placeholder="Ex: Équipe SRE / DBA"/>
                </div>
                <div class="form-group">
                    <label for="create-user-role">Rôle</label>
                    <select id="create-user-role" name="role" class="form-control">
                        <option value="student">Apprenant / Stagiaire (student)</option>
                        <option value="trainer">Formateur (trainer)</option>
                        <option value="admin">Administrateur (admin)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="create-user-password">Mot de Passe Initial</label>
                    <input id="create-user-password" type="password" name="password" class="form-control" autocomplete="new-password" required minlength="6" placeholder="Minimum 6 caractères"/>
                </div>
                <button type="submit" class="btn btn-emerald w-100">
                    ➕ Créer le Compte Utilisateur
                </button>
            </form>

            <hr class="hairline-divider"/>
            <h3 class="section-heading-sm mt-0">📥 Import CSV de Cohorte en Masse</h3>
            <p class="text-xs text-secondary mb-sm">
                Collez une liste CSV (<code>nom;email;entreprise;role;mot_de_passe</code>) pour inscrire toute une session en 1 clic.
            </p>
            <form method="POST" action="index.php?page=admin&tab=users&action=admin_import_users_csv&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="csv-default-password">Mot de passe par défaut (si absent du CSV)</label>
                    <input id="csv-default-password" type="password" name="default_password" class="form-control" autocomplete="new-password" minlength="6" placeholder="Laisser vide pour génération aléatoire"/>
                </div>
                <div class="form-group">
                    <label for="csv-cohort-textarea">Données CSV</label>
                    <textarea id="csv-cohort-textarea" name="csv_content" class="form-control" rows="4" required placeholder="Claire Martin;claire@banque.fr;Banque SA;student&#10;David Roy;david@banque.fr;Banque SA;student"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    📥 Importer la Cohorte CSV
                </button>
            </form>
        </aside>
    </div>

<?php elseif ($adminTab === 'qna'): ?>
    <!-- Centralized Q&A Forum Moderation -->
    <div class="admin-toolbar mb-1">
        <div>
            <h2 class="section-heading-md mb-0">💬 Modération du Forum d'Entraide Technique &amp; Q&amp;A (<span class="tabular-nums"><?= count($allCourseDiscussions) ?></span> messages)</h2>
            <p class="text-sm text-secondary mt-xs mb-0">
                Visualisez toutes les questions posées sur les modules et ateliers, marquez-les comme résolues ou supprimez les entrées obsolètes.
            </p>
        </div>
    </div>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Élément (Module / Lab)</th>
                    <th>Auteur</th>
                    <th>Question / Message</th>
                    <th>Statut</th>
                    <th>Actions Formateur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allCourseDiscussions)): ?>
                    <tr><td colspan="6">Aucune question publiée pour le moment sur cette formation.</td></tr>
                <?php else: ?>
                    <?php foreach ($allCourseDiscussions as $disc):
                        $dItemId = (string)($disc['item_id'] ?? '');
                        $dMsgId = (string)($disc['id'] ?? '');
                        $dIsLab = str_starts_with($dItemId, 'lab_');
                        $dTargetPage = $dIsLab ? 'lab' : 'module';
                        $dResolved = !empty($disc['resolved']);
                    ?>
                        <tr>
                            <td class="tabular-nums"><code><?= Security::e(substr((string)($disc['created_at'] ?? ''), 0, 16)) ?></code></td>
                            <td>
                                <a href="index.php?page=<?= $dTargetPage ?>&id=<?= urlencode($dItemId) ?>&course=<?= urlencode($currentCourseSlug) ?>#discussion-section" class="badge-code">
                                    <?= $dIsLab ? '🧪 ' : '📖 ' ?><?= Security::e($dItemId) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= Security::e((string)($disc['user_name'] ?? 'Apprenant')) ?></strong>
                                <span class="role-badge role-<?= Security::e((string)($disc['user_role'] ?? 'student')) ?>"><?= Security::e((string)($disc['user_role'] ?? 'student')) ?></span>
                            </td>
                            <td class="qna-message-cell"><?= Security::e((string)($disc['message'] ?? '')) ?></td>
                            <td>
                                <?= $dResolved
                                    ? '<span class="badge-completed">✔ Résolu</span>'
                                    : '<span class="badge-phase">⏳ Ouvert</span>' ?>
                            </td>
                            <td>
                                <form method="POST" action="index.php?page=admin&tab=qna&action=moderate_discussion&course=<?= urlencode($currentCourseSlug) ?>" class="d-inline-flex gap-xs">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="item_id" value="<?= Security::e($dItemId) ?>"/>
                                    <input type="hidden" name="message_id" value="<?= Security::e($dMsgId) ?>"/>
                                    <input type="hidden" name="return_page" value="admin"/>
                                    <button type="submit" name="mod_action" value="resolve" class="btn btn-emerald btn-sm">
                                        <?= $dResolved ? '↩ Rouvrir' : '✔ Résolu' ?>
                                    </button>
                                    <button type="submit" name="mod_action" value="delete" class="btn btn-danger btn-sm" aria-label="Supprimer">
                                        🗑️
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($adminTab === 'docker'): ?>
    <!-- Docker Containers Fleet & Chaos Engineering Simulator -->
    <?php if ($featureManager->isEnabled('chaos_engineering')): ?>
        <div class="docker-cockpit">
            <span class="badge-code">⚡ SIMULATEUR DE PANNES EN DIRECT (CHAOS ENGINEERING DBA)</span>
            <h2 class="section-heading-md mt-xs mb-xs">Injection &amp; Résolution de Scénarios de Crise PostgreSQL 18</h2>
            <p class="text-sm text-secondary mb-1">
                Injectez en 1 clic un scénario d'incident réel sur les instances Docker actives afin que les stagiaires diagnostiquent et résolvent le problème en direct.
            </p>

            <div id="chaos-feedback-box" class="flash-banner flash-success is-hidden"></div>

            <div class="card-grid">
                <?php
                $chaosList = [
                    'bloat' => ['title' => '🔥 Table Bloat Massif (chaos_orders)', 'desc' => 'Génère une table avec autovacuum désactivé et des milliers de dead tuples suite à des UPDATEs intensifs.'],
                    'lock' => ['title' => '🔒 Contention de Verrous (chaos_ledger)', 'desc' => 'Prépare une table financière critique soumise à des verrous concurrents pour audit pg_blocking_pids().'],
                    'idle_in_tx' => ['title' => '⏳ Sessions Idle in Transaction', 'desc' => 'Crée 1 000 sessions applicatives et illustre l\'impact sur la rétention XID et le vacuum.'],
                    'wal_pressure' => ['title' => '🌊 Rafale d\'Écritures WAL & Checkpoint', 'desc' => 'Génère un burst d\'écritures dans chaos_wal_burst et force un CHECKPOINT immédiat.'],
                ];
                foreach ($chaosList as $cKey => $cInfo):
                ?>
                    <div class="lms-card">
                        <div>
                            <h3 class="section-heading-sm mt-0 mb-xs"><?= Security::e($cInfo['title']) ?></h3>
                            <p class="text-xs text-secondary mb-1"><?= Security::e($cInfo['desc']) ?></p>
                        </div>
                        <div class="d-flex gap-sm">
                            <button type="button" class="btn btn-amber btn-sm btn-chaos-trigger flex-1" data-scenario="<?= Security::e($cKey) ?>" data-action="inject">
                                ⚡ Injecter la Panne
                            </button>
                            <button type="button" class="btn btn-emerald btn-sm btn-chaos-trigger flex-1" data-scenario="<?= Security::e($cKey) ?>" data-action="heal">
                                🧹 Nettoyer (Heal)
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-toolbar mt-1 mb-1">
        <h2 class="section-heading-md mb-0">🐳 Conteneurs Docker Actifs &amp; Cluster de Formation</h2>
        <form method="POST" action="index.php?page=admin&tab=docker&action=admin_bulk_docker&course=<?= urlencode($currentCourseSlug) ?>" class="d-flex flex-wrap gap-sm">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <button type="submit" name="bulk_cmd" value="refresh_runtime" class="btn btn-outline-cyan btn-sm">
                🔄 Rafraîchir Runtime Docker
            </button>
            <button type="submit" name="bulk_cmd" value="stop_all" class="btn btn-amber btn-sm" data-confirm="Arrêter tous les conteneurs sandbox apprenants en cours d'exécution ?">
                ⏹️ Arrêter Tous les Labs
            </button>
            <button type="submit" name="bulk_cmd" value="prune_stopped" class="btn btn-danger btn-sm" data-confirm="Supprimer définitivement tous les conteneurs apprenants arrêtés ?">
                🧹 Purger Conteneurs Arrêtés
            </button>
        </form>
    </div>

    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Nom du Conteneur</th>
                    <th>Type</th>
                    <th>Image</th>
                    <th>Statut</th>
                    <th>Ports Exposés</th>
                    <th>Inspection</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($labContainers)): ?>
                    <tr><td colspan="6">Aucun conteneur Lab en cours d'exécution. Cliquez sur « ▶ Start Lab » dans l'onglet Cohorte ou sur le Tableau de Bord.</td></tr>
                <?php else: ?>
                    <?php foreach ($labContainers as $c):
                        $sandboxUid = 0;
                        if (preg_match('/^lp_pg18_user_(\d+)$/', (string)$c['name'], $uidMatch)) {
                            $sandboxUid = (int)$uidMatch[1];
                        }
                    ?>
                        <tr>
                            <td><code><?= Security::e($c['name']) ?></code></td>
                            <td><span class="badge-code"><?= $c['type'] === 'user_sandbox' ? 'Sandbox Utilisateur' : 'Cluster HA Partagé' ?></span></td>
                            <td><code><?= Security::e($c['image']) ?></code></td>
                            <td><?= $c['running'] ? '<span class="badge-completed">' . Security::e($c['status']) . '</span>' : Security::e($c['status']) ?></td>
                            <td><code><?= Security::e($c['ports']) ?></code></td>
                            <td>
                                <?php if ($sandboxUid > 0): ?>
                                    <button type="button"
                                            class="btn btn-outline btn-sm btn-docker-logs"
                                            data-user-id="<?= $sandboxUid ?>"
                                            data-container-name="<?= Security::e((string)$c['name']) ?>">
                                        📜 Logs
                                    </button>
                                <?php else: ?>
                                    <span class="text-xs text-muted">Cluster partagé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="docker-logs-panel" class="lms-card mt-1" hidden>
        <div class="admin-toolbar mb-sm">
            <strong id="docker-logs-title">📜 Logs Conteneur</strong>
            <button type="button" id="btn-close-docker-logs" class="btn btn-outline btn-sm">Fermer</button>
        </div>
        <pre id="docker-logs-output" class="console-pre"></pre>
    </div>

<?php elseif ($adminTab === 'courses' && $isAdminUser): ?>
    <!-- Multi-Course Catalog Management (Admin Only) -->
    <div class="reader-grid">
        <div>
            <h2 class="section-heading-md mb-1">🎓 Formations Connectées au Portail LMS</h2>
            <div class="card-grid">
                <?php foreach ($allManageableCourses as $cSlug => $cCfg):
                    $cSlugStr = (string)$cSlug;
                    $loadedCourse = $courseRepo->getCourse($cSlugStr);
                    $isActive = !empty($cCfg['active']);
                    $isCustom = isset($customCourses[$cSlugStr]);
                    $cTitle = (string)($loadedCourse['title'] ?? $cCfg['title'] ?? $cSlugStr);
                    $cBadge = (string)($loadedCourse['badge'] ?? $cCfg['badge'] ?? strtoupper($cSlugStr));
                    $cSource = (string)($loadedCourse['source_root'] ?? $cCfg['source_root'] ?? '');
                    $cStats = $loadedCourse['stats'] ?? ['modules_count' => 0, 'labs_count' => 0, 'cheatsheets_count' => 0];
                ?>
                    <div class="lms-card">
                        <div>
                            <div class="card-top-meta">
                                <span class="badge-code"><?= Security::e($cBadge) ?></span>
                                <?= $isActive
                                    ? '<span class="badge-completed">Active</span>'
                                    : '<span class="badge-danger-text">Inactive</span>' ?>
                            </div>
                            <h3 class="section-heading-sm mb-xs"><?= Security::e($cTitle) ?></h3>
                            <p class="text-xs text-secondary mb-sm">
                                Dossier source : <code><?= Security::e($cSource) ?></code>
                            </p>
                            <div class="text-xs text-cyan tabular-nums">
                                📚 <?= (int)($cStats['modules_count'] ?? 0) ?> Modules •
                                🧪 <?= (int)($cStats['labs_count'] ?? 0) ?> Labs •
                                📑 <?= (int)($cStats['cheatsheets_count'] ?? 0) ?> Cheatsheets
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-xs mt-1">
                            <?php if ($isActive): ?>
                                <a href="index.php?page=dashboard&course=<?= urlencode($cSlugStr) ?>" class="btn btn-primary btn-sm flex-1">
                                    Ouvrir cette Formation →
                                </a>
                            <?php endif; ?>
                            <?php if ($isCustom): ?>
                                <form method="POST" action="index.php?page=admin&tab=courses&action=admin_toggle_course" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="course_slug" value="<?= Security::e($cSlugStr) ?>"/>
                                    <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>"/>
                                    <button type="submit" class="btn btn-outline btn-sm">
                                        <?= $isActive ? '⏸️ Désactiver' : '▶ Activer' ?>
                                    </button>
                                </form>
                                <form method="POST"
                                      action="index.php?page=admin&tab=courses&action=admin_delete_course"
                                      class="d-inline"
                                      data-confirm="Supprimer la formation personnalisée « <?= Security::e($cTitle) ?> » ?">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="course_slug" value="<?= Security::e($cSlugStr) ?>"/>
                                    <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="toc-box">
            <h3 class="section-heading-sm mt-0 mb-sm">➕ Connecter une Nouvelle Formation</h3>
            <p class="text-xs text-secondary mb-1">
                Ce portail LMS est 100% générique. Indiquez le chemin d'un autre dépôt de formation (ex: MySQL, Kubernetes, Linux, Python) respectant la structure <code>03_supports_de_cours/</code> et <code>04_ateliers_et_tp/</code>.
            </p>
            <form method="POST" action="index.php?page=admin&action=admin_add_course">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="add-course-slug">Identifiant (Slug)</label>
                    <input id="add-course-slug" type="text" name="slug" class="form-control" required placeholder="Ex: kubernetes-production"/>
                </div>
                <div class="form-group">
                    <label for="add-course-title">Titre de la Formation</label>
                    <input id="add-course-title" type="text" name="title" class="form-control" required placeholder="Ex: Kubernetes & Cloud Native en Production"/>
                </div>
                <div class="form-group">
                    <label for="add-course-category">Catégorie</label>
                    <input id="add-course-category" type="text" name="category" class="form-control" value="Cloud, DevOps & Bases de Données"/>
                </div>
                <div class="form-group">
                    <label for="add-course-duration">Durée</label>
                    <input id="add-course-duration" type="text" name="duration" class="form-control" value="35h (5 jours) - Certifié Qualiopi"/>
                </div>
                <div class="form-group">
                    <label for="add-course-source">Chemin du Référentiel Source</label>
                    <input id="add-course-source" type="text" name="source_root" class="form-control" placeholder="Chemin absolu ou relatif vers la formation"/>
                </div>
                <div class="form-group">
                    <label for="add-course-description">Description</label>
                    <textarea id="add-course-description" name="description" class="form-control" rows="3" placeholder="Objectifs et public visé..."></textarea>
                </div>
                <button type="submit" class="btn btn-emerald w-100">
                    🚀 Ajouter au Catalogue Multi-Formations
                </button>
            </form>
        </aside>
    </div>

<?php elseif ($adminTab === 'system'): ?>
    <!-- System Configuration, Feature Flags, Cache Purge & Health Diagnostics (Admin Only) -->
    <?php
    $featureDefs = $featureManager->getDefinitions();
    $featureState = $featureManager->all();
    $healthReport = $adminService->getSystemHealthReport(
        $appConfig ?? [],
        $storage,
        $docker,
        $courseRepo,
        $storage instanceof PdoStorage ? $storage : null
    );
    ?>
    <section class="lms-card mb-2">
        <span class="badge-code">⚙️ GESTION DU SYSTÈME & DES CACHES DE RENDU</span>
        <h3 class="section-heading-md mt-xs mb-sm">Invalidation &amp; Purge Immédiate des Caches</h3>
        <p class="text-sm text-secondary mb-1">
            Le portail met en cache le catalogue multi-formations et le rendu HTML des modules/ateliers Markdown pour des performances optimales.
            En cas de mise à jour manuelle des supports de cours ou des scripts SQL, utilisez cette action pour forcer la recompilation immédiate.
        </p>
        <form method="POST"
              action="index.php?page=admin&tab=system&action=admin_clear_cache&course=<?= urlencode($currentCourseSlug) ?>"
              data-confirm="Purger tous les caches de catalogue et de rendu Markdown ?">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <button type="submit" class="btn btn-primary">
                🧹 Vider les Caches Catalogue &amp; Markdown
            </button>
        </form>
    </section>

    <div class="reader-grid">
        <section class="lms-card">
            <span class="badge-code">🎛️ FEATURE FLAGS APPLICATIFS</span>
            <h3 class="section-heading-md mt-xs mb-sm">Activation Dynamique des Modules Portail</h3>
            <form method="POST" action="index.php?page=admin&tab=system&action=admin_save_features&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="d-flex flex-col gap-sm mb-1">
                    <?php foreach ($featureDefs as $fKey => $fMeta):
                        $fEnabled = !empty($featureState[$fKey]);
                    ?>
                        <label class="feature-flag-row" for="flag-<?= Security::e((string)$fKey) ?>">
                            <input id="flag-<?= Security::e((string)$fKey) ?>" type="checkbox" name="features[<?= Security::e((string)$fKey) ?>]" value="1" <?= $fEnabled ? 'checked' : '' ?>/>
                            <div>
                                <strong><?= Security::e((string)($fMeta['label'] ?? $fKey)) ?></strong>
                                <div class="text-xs text-muted"><?= Security::e((string)($fMeta['description'] ?? '')) ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-emerald btn-sm">
                    💾 Enregistrer les Feature Flags
                </button>
            </form>
        </section>

        <section class="lms-card">
            <div class="admin-toolbar mb-sm">
                <div>
                    <span class="badge-code">🩺 DIAGNOSTIC SANTÉ SYSTÈME</span>
                    <h3 class="section-heading-md mt-xs mb-0">État des Composants Techniques</h3>
                </div>
                <span class="badge-completed tabular-nums">
                    <?= (int)$healthReport['summary']['ok'] ?>/<?= (int)$healthReport['summary']['total'] ?> OK
                </span>
            </div>
            <div class="lms-table-wrap">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Composant</th>
                            <th>Statut</th>
                            <th>Détail Technique</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($healthReport['checks'] as $chk):
                            $st = (string)($chk['status'] ?? 'ok');
                            $badgeClass = $st === 'ok' ? 'badge-completed' : ($st === 'warn' ? 'badge-phase' : 'badge-danger-text');
                        ?>
                            <tr>
                                <td><strong><?= Security::e((string)$chk['component']) ?></strong></td>
                                <td><span class="<?= $badgeClass ?>"><?= Security::e(strtoupper($st)) ?></span></td>
                                <td class="text-xs text-secondary"><?= Security::e((string)$chk['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

<?php elseif ($isAdminUser): ?>
    <!-- Audit Trail & Qualiopi Traceability (Admin Only, up to 200 rows) -->
    <div class="admin-toolbar mb-1">
        <h2 class="section-heading-md mb-0">📜 Journal d'Audit & Traçabilité Qualiopi (<span class="tabular-nums"><?= count($auditLogs) ?></span> entrées)</h2>
        <div class="d-flex flex-wrap gap-sm">
            <form method="GET" action="index.php" class="d-flex flex-wrap gap-sm">
                <input type="hidden" name="page" value="admin"/>
                <input type="hidden" name="tab" value="audit"/>
                <input type="hidden" name="course" value="<?= urlencode($currentCourseSlug) ?>"/>
                <select name="audit_cat" class="form-control form-control-sm" aria-label="Filtrer par catégorie d'audit">
                    <option value="">Toutes catégories</option>
                    <option value="LOGIN" <?= $auditCategoryFilter === 'LOGIN' ? 'selected' : '' ?>>LOGIN</option>
                    <option value="ADMIN_" <?= $auditCategoryFilter === 'ADMIN_' ? 'selected' : '' ?>>ADMIN_*</option>
                    <option value="DOCKER_" <?= $auditCategoryFilter === 'DOCKER_' ? 'selected' : '' ?>>DOCKER_*</option>
                    <option value="LAB_" <?= $auditCategoryFilter === 'LAB_' ? 'selected' : '' ?>>LAB_*</option>
                    <option value="FEATURE_" <?= $auditCategoryFilter === 'FEATURE_' ? 'selected' : '' ?>>FEATURE_*</option>
                    <option value="SECURITY_" <?= $auditCategoryFilter === 'SECURITY_' ? 'selected' : '' ?>>SECURITY_*</option>
                </select>
                <input type="search"
                       name="audit_actor"
                       class="form-control form-control-sm"
                       value="<?= Security::e($auditActorFilter) ?>"
                       placeholder="🔎 Acteur ou détail..."
                       aria-label="Filtrer par acteur ou détail"/>
                <button type="submit" class="btn btn-outline btn-sm">Filtrer</button>
            </form>
            <a href="index.php?action=export_audit_csv&course=<?= urlencode($currentCourseSlug) ?>&category=<?= urlencode($auditCategoryFilter) ?>&actor=<?= urlencode($auditActorFilter) ?>" class="btn btn-emerald btn-sm">
                📥 Exporter CSV
            </a>
        </div>
    </div>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Horodatage</th>
                    <th>Utilisateur</th>
                    <th>Type d'Événement</th>
                    <th>Détails de l'Opération</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditLogs)): ?>
                    <tr><td colspan="4">Aucune entrée d'audit correspondant aux critères sélectionnés.</td></tr>
                <?php else: ?>
                    <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td class="tabular-nums"><code><?= Security::e($log['timestamp'] ?? '') ?></code></td>
                            <td><strong><?= Security::e($log['actor'] ?? '') ?></strong></td>
                            <td><span class="badge-code"><?= Security::e($log['action'] ?? '') ?></span></td>
                            <td><?= Security::e($log['details'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
