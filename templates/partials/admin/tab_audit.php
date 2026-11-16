<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin audit trail partial: SHA-256 hash-chain integrity pill, category/actor filters,
 * OPCO/Qualiopi CSV export, and paginated event log table.
 */
$chainValid = !empty($auditIntegrity['valid']);
$chainedCount = (int)($auditIntegrity['chained_entries'] ?? 0);
$totalCheckedCount = (int)($auditIntegrity['total_checked'] ?? 0);
$brokenCount = is_array($auditIntegrity['broken_links'] ?? null) ? count($auditIntegrity['broken_links']) : 0;
?>
<div class="admin-toolbar mb-1">
    <div class="d-flex flex-wrap items-center gap-sm">
        <h2 class="section-heading-md mb-0">📜 Journal d'Audit & Traçabilité Qualiopi (<span class="tabular-nums"><?= count($auditLogs) ?></span> entrées)</h2>
        <?php if ($chainValid): ?>
            <span class="integrity-pill-ok tabular-nums" title="Vérification cryptographique SHA-256 de la chaîne d'audit">
                🔒 Chaîne SHA-256 Intègre (<?= $chainedCount ?>/<?= $totalCheckedCount ?>)
            </span>
        <?php else: ?>
            <span class="integrity-pill-error tabular-nums" title="Rupture détectée dans la chaîne cryptographique d'audit">
                ⚠ Alerte Intégrité SHA-256 (<?= $brokenCount ?> anomalie(s))
            </span>
        <?php endif; ?>
    </div>
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
                <option value="CLASSROOM_" <?= $auditCategoryFilter === 'CLASSROOM_' ? 'selected' : '' ?>>CLASSROOM_*</option>
                <option value="CHAOS_" <?= $auditCategoryFilter === 'CHAOS_' ? 'selected' : '' ?>>CHAOS_*</option>
                <option value="ATTENDANCE_" <?= $auditCategoryFilter === 'ATTENDANCE_' ? 'selected' : '' ?>>ATTENDANCE_*</option>
                <option value="TRAINER_" <?= $auditCategoryFilter === 'TRAINER_' ? 'selected' : '' ?>>TRAINER_*</option>
                <option value="QUIZ_" <?= $auditCategoryFilter === 'QUIZ_' ? 'selected' : '' ?>>QUIZ_*</option>
                <option value="FINAL_EXAM_" <?= $auditCategoryFilter === 'FINAL_EXAM_' ? 'selected' : '' ?>>FINAL_EXAM_*</option>
                <option value="DIAGNOSTIC_" <?= $auditCategoryFilter === 'DIAGNOSTIC_' ? 'selected' : '' ?>>DIAGNOSTIC_*</option>
                <option value="COHORT_" <?= $auditCategoryFilter === 'COHORT_' ? 'selected' : '' ?>>COHORT_*</option>
                <option value="QUALIOPI_" <?= $auditCategoryFilter === 'QUALIOPI_' ? 'selected' : '' ?>>QUALIOPI_*</option>
                <option value="DOWNLOAD" <?= $auditCategoryFilter === 'DOWNLOAD' ? 'selected' : '' ?>>DOWNLOAD</option>
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
                        <td class="tabular-nums table-col-nowrap"><code><?= Security::e((string)($log['timestamp'] ?? '')) ?></code></td>
                        <td><strong><?= Security::e((string)($log['actor'] ?? '')) ?></strong></td>
                        <td class="table-col-nowrap"><span class="badge-code"><?= Security::e((string)($log['action'] ?? '')) ?></span></td>
                        <td><?= Security::e((string)($log['details'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
