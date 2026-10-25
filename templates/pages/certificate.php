<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
?>

<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <span class="badge-code">TRAÇABILITÉ QUALIOPI & TAXONOMIE DE BLOOM</span>
        <h1 style="font-size: 1.65rem; margin: 0.35rem 0 0;">🎓 Attestation de Compétences & Relevé Qualiopi</h1>
    </div>
    <button type="button" onclick="window.print()" class="btn btn-primary">
        🖨️ Imprimer / Exporter en PDF Officiel
    </button>
</div>

<div style="background: var(--bg-surface); border: 2px solid var(--brand-cyan); border-radius: var(--radius-lg); padding: 3rem; box-shadow: var(--shadow-elevated); max-width: 1050px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-hairline); padding-bottom: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="assets/img/lightpath_logo_white_text.png"
                 data-dark-src="assets/img/lightpath_logo_white_text.png"
                 data-light-src="assets/img/lightpath_logo_dark_text.png"
                 alt="LightPath SARL"
                 class="theme-aware-logo"
                 style="height: 48px;"/>
            <div>
                <div style="font-weight: 800; font-size: 1.2rem;"><?= Security::e($appConfig['company']) ?></div>
                <div style="font-size: 0.82rem; color: var(--text-secondary);">Organisme de Formation Professionnelle • Réf. <?= Security::e($appConfig['qualiopi_ref']) ?></div>
            </div>
        </div>
        <img src="assets/img/postgresql_elephant_dark.png"
             data-dark-src="assets/img/postgresql_elephant_dark.png"
             data-light-src="assets/img/postgresql_elephant_light.png"
             alt="PostgreSQL 18"
             class="theme-aware-logo"
             style="height: 48px;"/>
    </div>

    <div style="text-align: center; margin-bottom: 2rem;">
        <div class="badge-code" style="font-size: 0.82rem;">ATTESTATION D'ACQUISITION DE COMPÉTENCES PROFESSIONNELLES</div>
        <h2 style="font-size: 1.85rem; margin: 0.75rem 0 0.35rem;"><?= Security::e($currentCourse['title']) ?></h2>
        <p style="color: var(--text-secondary); margin: 0;">
            Délivrée à <strong style="color: var(--text-primary); font-size: 1.15rem;"><?= Security::e($currentUser['name']) ?></strong>
            (<?= Security::e($currentUser['email']) ?><?= !empty($currentUser['company']) ? ' — ' . Security::e($currentUser['company']) : '' ?>)
        </p>
    </div>

    <div class="kpi-grid" style="margin-bottom: 2rem;">
        <div class="kpi-card">
            <div class="kpi-label">Taux d'Accomplissement Global</div>
            <div class="kpi-value" style="color: var(--brand-cyan-light);"><?= $globalCompletionPct ?>%</div>
            <div class="kpi-sub">Progression certifiée LMS</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Modules Théoriques Validés</div>
            <div class="kpi-value"><?= $doneModules ?> / <?= $totalModules ?></div>
            <div class="kpi-sub">Taxonomie de Bloom C01–C29</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ateliers Docker Validés</div>
            <div class="kpi-value"><?= $doneLabs ?> / <?= $totalLabs ?></div>
            <div class="kpi-sub">Oracles d'exécution PostgreSQL 18</div>
        </div>
    </div>

    <h3 style="font-size: 1.1rem; margin-bottom: 0.75rem;">📊 Détail des Modules, Compétences Bloom & Évaluations Formatives</h3>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Module & Compétence Opérationnelle Bloom</th>
                    <th>Cours</th>
                    <th>Quiz QCM</th>
                    <th>Lab Docker</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['modules'] ?? []) as $mId => $mod):
                    $mDone = in_array($mId, $userProgress['completed_modules'] ?? [], true);
                    $qRes = $userProgress['quiz_scores'][$mId] ?? null;
                    $lId = $mod['linked_lab_id'] ?? '';
                    $lDone = $lId !== '' && in_array($lId, $userProgress['completed_labs'] ?? [], true);
                ?>
                    <tr>
                        <td><span class="badge-code"><?= Security::e($mod['badge_code']) ?></span></td>
                        <td>
                            <strong><?= Security::e($mod['title']) ?></strong>
                            <div style="font-size: 0.76rem; color: var(--text-muted);"><?= Security::e($mod['bloom']) ?></div>
                        </td>
                        <td><?= $mDone ? '<span class="badge-completed">✔ Validé</span>' : '<span style="color:var(--text-muted);">En cours</span>' ?></td>
                        <td class="tabular-nums"><?= $qRes ? ((int)$qRes['percentage'] . '%') : '—' ?></td>
                        <td><?= $lDone ? '<span class="badge-completed">✔ Oracle OK</span>' : '<span style="color:var(--text-muted);">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-hairline); font-size: 0.85rem;">
        <div>
            <strong>Date d'émission :</strong> <?= date('d/m/Y') ?><br/>
            <strong>Vérification :</strong> <?= Security::e($appConfig['website']) ?> | <?= Security::e($appConfig['contact_email']) ?>
        </div>
        <div style="text-align: right;">
            <strong>Le Formateur & Responsable Pédagogique :</strong><br/>
            <?= Security::e($appConfig['trainer_name']) ?><br/>
            <span style="color: var(--text-secondary); font-size: 0.78rem;"><?= Security::e($appConfig['trainer_title']) ?></span>
        </div>
    </div>
</div>
