<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin cohort tracking partial: KPI strip, 1-click classroom presets, live locks,
 * at-risk learner alert, exports (Cohort CSV, Qualiopi JSON, OPCO CSV, SCORM), and module matrix.
 */
$atRiskByUserId = [];
foreach ($atRiskLearners as $ar) {
    $atRiskByUserId[(int)($ar['user_id'] ?? 0)] = $ar;
}
$scormEnabled = method_exists($featureManager, 'isEnabledForCourse')
    ? $featureManager->isEnabledForCourse('scorm_export', $currentCourseSlug)
    : $featureManager->isEnabled('scorm_export');
$matrixModules = is_array($moduleCompletionMatrix['modules'] ?? null) ? $moduleCompletionMatrix['modules'] : [];
?>
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

<?php if (!empty($atRiskLearners)): ?>
    <div class="flash-banner flash-error mb-md" role="status">
        <div>
            <strong>⚠ Alerte Décrochage Cohorte (<span class="tabular-nums"><?= count($atRiskLearners) ?></span> apprenant(s) &lt; 20%) :</strong>
            <span class="text-sm">
                <?php
                $riskNames = array_map(
                    static fn(array $r): string => (string)($r['name'] ?? '') . ' (' . (int)($r['completion_pct'] ?? 0) . '%)',
                    array_slice($atRiskLearners, 0, 5)
                );
                echo Security::e(implode(', ', $riskNames));
                if (count($atRiskLearners) > 5) {
                    echo ' … (+' . (count($atRiskLearners) - 5) . ')';
                }
                ?>
            </span>
        </div>
    </div>
<?php endif; ?>

<section class="lms-card admin-live-card mb-2">
    <span class="badge-code">📢 PILOTAGE SALLE DE CLASSE LIVE &amp; VERROUILLAGE PÉDAGOGIQUE</span>
    <h3 class="section-heading-sm">Presets Rapides, Annonce Temps Réel &amp; Contrôle d'Accès</h3>

    <form method="POST" action="index.php?page=admin&tab=cohort&action=admin_apply_classroom_preset&course=<?= urlencode($currentCourseSlug) ?>" class="preset-toolbar">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
        <input type="hidden" name="active_track" value="<?= Security::e((string)($classroomState['active_track'] ?? 'cursus-complet')) ?>"/>
        <span class="text-xs text-muted font-bold">Presets Salle 1-Clic :</span>
        <button type="submit" name="preset" value="lecture" class="btn btn-outline btn-sm">🎓 Cours Magistral</button>
        <button type="submit" name="preset" value="guided_lab" class="btn btn-outline-cyan btn-sm">🧪 Atelier Pratique</button>
        <button type="submit" name="preset" value="exam_lockdown" class="btn btn-amber btn-sm">📝 Examen Certifiant</button>
        <button type="submit" name="preset" value="open_review" class="btn btn-emerald btn-sm">📖 Révision Libre</button>
    </form>

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

<div class="admin-toolbar mb-1">
    <h2 class="section-heading-md mb-0">📊 Matrice de Suivi Pédagogique de la Cohorte (<?= Security::e($currentCourse['short_title']) ?>)</h2>
    <div class="d-flex flex-wrap gap-sm">
        <input id="admin-cohort-search"
               type="search"
               class="form-control form-control-sm"
               placeholder="🔎 Filtrer un apprenant, email, entreprise..."
               aria-label="Filtrer la matrice de cohorte"
               data-filter-table="cohort-matrix-table"/>
        <a href="index.php?action=export_cohort_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
            📥 Exporter Cohorte (CSV)
        </a>
        <a href="index.php?action=export_qualiopi_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
            📊 Exporter Audit Qualiopi (CSV)
        </a>
        <a href="index.php?action=export_qualiopi_json&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
            🗂️ Dossier Qualiopi (JSON)
        </a>
        <a href="index.php?action=export_attendance_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
            ✍️ Émargement OPCO (CSV)
        </a>
        <?php if ($scormEnabled): ?>
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
                <th>Émargement &amp; Temps</th>
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
                $riskEntry = $atRiskByUserId[$uid] ?? null;

                $uPort = $docker->getUserPort($uid);
                $uContainer = $docker->getContainerName($uid);
                $searchHaystack = strtolower(trim(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['company'] ?? '') . ' ' . ($u['role'] ?? '')));
            ?>
                <tr data-search="<?= Security::e($searchHaystack) ?>" data-role="<?= Security::e((string)($u['role'] ?? 'student')) ?>">
                    <td class="tabular-nums">#<?= $uid ?></td>
                    <td>
                        <div class="d-flex flex-wrap items-center gap-xs">
                            <strong><?= Security::e($u['name']) ?></strong>
                            <?php if ($riskEntry !== null): ?>
                                <span class="at-risk-badge" title="<?= Security::e((string)($riskEntry['risk_reason'] ?? '')) ?>">
                                    ⚠ Risque (<?= (int)$riskEntry['completion_pct'] ?>%)
                                </span>
                            <?php endif; ?>
                        </div>
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

<?php if (!empty($matrixModules)): ?>
    <section class="lms-card mt-lg">
        <div class="admin-toolbar mb-sm">
            <div>
                <span class="badge-code">📈 MATRICE DE COMPLÉTION PAR MODULE</span>
                <h3 class="section-heading-md mt-xs mb-0">
                    Couverture Pédagogique &amp; Moyennes QCM (<span class="tabular-nums"><?= (int)($moduleCompletionMatrix['learners_count'] ?? 0) ?></span> apprenant(s) inscrits)
                </h3>
            </div>
        </div>
        <div class="lms-table-wrap">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Module</th>
                        <th class="table-col-nowrap">Validations</th>
                        <th class="table-col-nowrap">Taux Cohorte</th>
                        <th class="table-col-nowrap">Score QCM Moyen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matrixModules as $mRow): ?>
                        <tr>
                            <td class="table-col-nowrap"><span class="badge-code"><?= Security::e((string)($mRow['badge_code'] ?? $mRow['id'])) ?></span></td>
                            <td><?= Security::e((string)($mRow['title'] ?? '')) ?></td>
                            <td class="tabular-nums table-col-nowrap"><?= (int)($mRow['completed_count'] ?? 0) ?> / <?= (int)($moduleCompletionMatrix['learners_count'] ?? 0) ?></td>
                            <td class="tabular-nums table-col-nowrap"><strong><?= number_format((float)($mRow['completion_pct'] ?? 0), 1, ',', ' ') ?>%</strong></td>
                            <td class="tabular-nums table-col-nowrap">
                                <?= $mRow['avg_quiz_pct'] !== null
                                    ? number_format((float)$mRow['avg_quiz_pct'], 1, ',', ' ') . '% (' . (int)($mRow['quiz_attempts'] ?? 0) . ')'
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
