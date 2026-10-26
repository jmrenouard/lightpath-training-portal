<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\Gamification;

$userAttendance = $storage->getUserAttendance($userId, $currentCourseSlug);
$learningSeconds = (int)($userProgress['learning_seconds'] ?? 0);
$signedHalfDaysCount = count($userAttendance);
$loggedHours = $signedHalfDaysCount * 3.5;

$halfDaySchedule = [
    1 => ['title' => 'Jour 1 — Architecture Interne, Installation & Stockage (M01–M02)', 'mod_focus' => 'M01, M02, Labs 01–02'],
    2 => ['title' => 'Jour 2 — MVCC, Isolation Transactionnelle & Sécurité SCRAM/RLS (M03–M04)', 'mod_focus' => 'M03, M04, Labs 03–04'],
    3 => ['title' => 'Jour 3 — Modélisation Avancée, Indexation B-Tree/GIN/GiST/BRIN (M05–M06)', 'mod_focus' => 'M05, M06, Labs 05–06'],
    4 => ['title' => 'Jour 4 — Développement SQL Moderne, CTE, Fenêtrage & PL/pgSQL (M07, SQL 01–05)', 'mod_focus' => 'M07, SQL 01–05'],
    5 => ['title' => 'Jour 5 — Sauvegardes Logiques/Physiques PITR & Réplication HA (M08–M09)', 'mod_focus' => 'M08, M09, Labs 08–09'],
    6 => ['title' => 'Jour 6 — Pooling PgBouncer, Optimiseur CBO & Plans EXPLAIN (M10–M12)', 'mod_focus' => 'M10, M11, M12'],
    7 => ['title' => 'Jour 7 — Tuning Mémoire/WAL, Partitionnement & Extensions (M13–M15)', 'mod_focus' => 'M13, M14, M15'],
    8 => ['title' => 'Jour 8 — Observabilité pg_stat_io, Migration & Vacuum Avancé (M16–M18)', 'mod_focus' => 'M16, M17, M18'],
    9 => ['title' => 'Jour 9 — pgBackRest Industriel & Tuning Kernel Linux (M19–M20)', 'mod_focus' => 'M19, M20, Labs 19–20'],
    10 => ['title' => 'Jour 10 — Architecture Cloud/K8s, Chaos DBA & Certification Finale (M21–M29)', 'mod_focus' => 'M21–M29 + Examen'],
];
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">CONFORMITÉ OPCO / QUALIOPI • TRAÇABILITÉ D'ASSIDUITÉ</span>
            <span class="badge-phase tabular-nums"><?= $signedHalfDaysCount ?> / 20 demi-journées émargées (<?= number_format($loggedHours, 1, ',', ' ') ?>h)</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">✍️ Feuille d'Émargement Numérique & Temps de Connexion</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Signez électroniquement votre présence par demi-journée (Matin 09h00–12h30 / Après-midi 14h00–17h30). Chaque signature est horodatée avec empreinte d'audit pour les organismes financeurs (OPCO / France Travail / Qualiopi).
        </p>
    </div>
    <div style="min-width: 220px; text-align: center; background: var(--bg-base); padding: 1rem 1.35rem; border-radius: var(--radius-md); border: 1px solid var(--border-strong);">
        <div class="kpi-label">Temps Actif Mesuré LMS</div>
        <div class="kpi-value tabular-nums" style="color: var(--brand-cyan-light); font-size: 1.85rem;">
            <?= Security::e(Gamification::formatDurationHours($learningSeconds)) ?>
        </div>
        <div class="kpi-sub tabular-nums"><?= number_format($loggedHours, 1, ',', ' ') ?>h émargées officiellement</div>
    </div>
</div>

<div class="lms-card">
    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">📅 Registre Officiel des 20 Demi-Journées (Cursus 70h / 10 Jours)</h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Journée & Programme</th>
                    <th>Matin (09h00 – 12h30 • 3h30)</th>
                    <th>Après-Midi (14h00 – 17h30 • 3h30)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($halfDaySchedule as $dayNum => $dayInfo):
                    $keyAm = "J{$dayNum}_AM";
                    $keyPm = "J{$dayNum}_PM";
                    $sigAm = $userAttendance[$keyAm] ?? null;
                    $sigPm = $userAttendance[$keyPm] ?? null;
                ?>
                    <tr>
                        <td>
                            <strong><?= Security::e($dayInfo['title']) ?></strong>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">Périmètre : <?= Security::e($dayInfo['mod_focus']) ?></div>
                        </td>
                        <td>
                            <?php if ($sigAm !== null): ?>
                                <span class="badge-completed">✔ Signé (<?= Security::e(substr((string)($sigAm['signed_at'] ?? ''), 0, 16)) ?>)</span>
                                <div style="font-size: 0.74rem; color: var(--text-muted);" class="tabular-nums">
                                    <code><?= Security::e((string)($sigAm['signature_text'] ?? $currentUser['name'])) ?></code>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="index.php?page=attendance&action=sign_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="display: flex; gap: 0.4rem; align-items: center;">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="half_day_key" value="<?= Security::e($keyAm) ?>"/>
                                    <input type="hidden" name="signature_text" value="Lu et approuvé — <?= Security::e($currentUser['name']) ?>"/>
                                    <button type="submit" class="btn btn-primary btn-sm">✍️ Émarger Matin (J<?= $dayNum ?>)</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sigPm !== null): ?>
                                <span class="badge-completed">✔ Signé (<?= Security::e(substr((string)($sigPm['signed_at'] ?? ''), 0, 16)) ?>)</span>
                                <div style="font-size: 0.74rem; color: var(--text-muted);" class="tabular-nums">
                                    <code><?= Security::e((string)($sigPm['signature_text'] ?? $currentUser['name'])) ?></code>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="index.php?page=attendance&action=sign_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="display: flex; gap: 0.4rem; align-items: center;">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="half_day_key" value="<?= Security::e($keyPm) ?>"/>
                                    <input type="hidden" name="signature_text" value="Lu et approuvé — <?= Security::e($currentUser['name']) ?>"/>
                                    <button type="submit" class="btn btn-emerald btn-sm">✍️ Émarger Après-Midi (J<?= $dayNum ?>)</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
