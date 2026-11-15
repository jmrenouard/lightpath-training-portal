<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Core\Gamification;

$isStaffUser = in_array($currentUser['role'] ?? '', ['trainer', 'admin'], true);
$inspectedUserId = ($isStaffUser && !empty($_GET['student_id'])) ? (int)$_GET['student_id'] : $userId;
$inspectedUser = ($inspectedUserId !== $userId) ? ($storage->getUserById($inspectedUserId) ?? $currentUser) : $currentUser;
$userAttendance = $storage->getUserAttendance($inspectedUserId, $currentCourseSlug);
$learningSeconds = (int)($userProgress['learning_seconds'] ?? 0);
$selectedTrack = (string)($_GET['track'] ?? 'cursus-complet');

$attendanceTracks = [
    'cursus-complet' => [
        'label' => 'Cursus Complet : 10 jours / 20 demi-journées (70h) — Jours 1 à 10',
        'days' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        'half_days' => 20,
    ],
    'parcours-1-initial-dba' => [
        'label' => 'Parcours 1 Initial DBA : 3 jours / 6 demi-journées (21h) — Jours 1 à 3',
        'days' => [1, 2, 3],
        'half_days' => 6,
    ],
    'parcours-2-administration-production' => [
        'label' => 'Parcours 2 Administration Production : 5 jours / 10 demi-journées (35h) — Jours 1 à 5',
        'days' => [1, 2, 3, 4, 5],
        'half_days' => 10,
    ],
    'parcours-3-performance-tuning' => [
        'label' => 'Parcours 3 Performance & Tuning : 3 jours / 6 demi-journées (21h) — Jours 6 à 8',
        'days' => [6, 7, 8],
        'half_days' => 6,
    ],
];
if (!isset($attendanceTracks[$selectedTrack])) {
    $selectedTrack = 'cursus-complet';
}
$activeTrackSpec = $attendanceTracks[$selectedTrack];

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

$signedHalfDaysCount = count($userAttendance);
$loggedHours = $signedHalfDaysCount * 3.5;
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">CONFORMITÉ OPCO / QUALIOPI • TRAÇABILITÉ D'ASSIDUITÉ</span>
            <span class="badge-phase tabular-nums"><?= $signedHalfDaysCount ?> / <?= (int)$activeTrackSpec['half_days'] ?> demi-journées émargées (<?= number_format($loggedHours, 1, ',', ' ') ?>h)</span>
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

<div class="lms-card no-print" style="margin-bottom: 1.25rem; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.85rem;">
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <strong style="font-size: 0.86rem;">🧭 Filtrer par Parcours OPCO :</strong>
        <?php foreach ($attendanceTracks as $tSlug => $tInfo): ?>
            <a href="index.php?page=attendance&track=<?= urlencode($tSlug) ?>&course=<?= urlencode($currentCourseSlug) ?>"
               class="btn btn-sm <?= $selectedTrack === $tSlug ? 'btn-primary' : 'btn-outline' ?>">
                <?= Security::e($tInfo['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <?php if ($isStaffUser): ?>
            <a href="index.php?action=export_attendance_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
                📊 Exporter Registre OPCO (CSV Détaillé)
            </a>
            <form method="POST" action="index.php?page=attendance&action=sign_trainer_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <input type="hidden" name="target_user_id" value="0"/>
                <input type="hidden" name="half_day_key" value="ALL"/>
                <input type="hidden" name="return_student_id" value="<?= $inspectedUserId ?>"/>
                <input type="hidden" name="return_track" value="<?= Security::e($selectedTrack) ?>"/>
                <button type="submit" class="btn btn-emerald btn-sm">
                    ✔ Contre-signer toutes les demi-journées émargées (Promotion)
                </button>
            </form>
        <?php endif; ?>
        <button type="button" data-action="print" class="btn btn-outline-cyan btn-sm">
            🖨️ Imprimer la Feuille d'Émargement OPCO (PDF)
        </button>
    </div>
</div>

<div class="lms-card">
    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">📅 Registre Officiel des Demi-Journées — <?= Security::e($activeTrackSpec['label']) ?></h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Journée & Programme</th>
                    <th>Matin (09h00 – 12h30 • 3h30)</th>
                    <th>Après-Midi (14h00 – 17h30 • 3h30)</th>
                    <th>Visa & Contre-Signature Formateur</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($halfDaySchedule as $dayNum => $dayInfo):
                    if (!in_array($dayNum, $activeTrackSpec['days'], true)) {
                        continue;
                    }
                    $keyAm = "J{$dayNum}_AM";
                    $keyPm = "J{$dayNum}_PM";
                    $sigAm = $userAttendance[$keyAm] ?? null;
                    $sigPm = $userAttendance[$keyPm] ?? null;
                    $trainerSignedAm = !empty($sigAm['trainer_signed_at']);
                    $trainerSignedPm = !empty($sigPm['trainer_signed_at']);
                    $trainerName = (string)($sigAm['trainer_name'] ?? $sigPm['trainer_name'] ?? $appConfig['trainer_name'] ?? 'Jean-Marie Renouard');
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
                                <?php if ($inspectedUserId === $userId): ?>
                                    <form method="POST" action="index.php?page=attendance&action=sign_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="display: flex; gap: 0.4rem; align-items: center;">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                        <input type="hidden" name="half_day_key" value="<?= Security::e($keyAm) ?>"/>
                                        <input type="hidden" name="signature_text" value="Lu et approuvé — <?= Security::e($currentUser['name']) ?>"/>
                                        <button type="submit" class="btn btn-primary btn-sm">✍️ Émarger Matin (J<?= $dayNum ?>)</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.78rem; color: var(--text-muted);">— Non émargé —</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sigPm !== null): ?>
                                <span class="badge-completed">✔ Signé (<?= Security::e(substr((string)($sigPm['signed_at'] ?? ''), 0, 16)) ?>)</span>
                                <div style="font-size: 0.74rem; color: var(--text-muted);" class="tabular-nums">
                                    <code><?= Security::e((string)($sigPm['signature_text'] ?? $currentUser['name'])) ?></code>
                                </div>
                            <?php else: ?>
                                <?php if ($inspectedUserId === $userId): ?>
                                    <form method="POST" action="index.php?page=attendance&action=sign_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="display: flex; gap: 0.4rem; align-items: center;">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                        <input type="hidden" name="half_day_key" value="<?= Security::e($keyPm) ?>"/>
                                        <input type="hidden" name="signature_text" value="Lu et approuvé — <?= Security::e($currentUser['name']) ?>"/>
                                        <button type="submit" class="btn btn-emerald btn-sm">✍️ Émarger Après-Midi (J<?= $dayNum ?>)</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.78rem; color: var(--text-muted);">— Non émargé —</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($trainerSignedAm && $trainerSignedPm): ?>
                                <span class="badge-completed">✔ Journée contre-signée</span>
                                <div style="font-size: 0.72rem; color: var(--text-muted);" class="tabular-nums">
                                    <?= Security::e(substr((string)($sigPm['trainer_signed_at'] ?? $sigAm['trainer_signed_at'] ?? ''), 0, 16)) ?> (<?= Security::e($trainerName) ?>)
                                </div>
                            <?php elseif ($trainerSignedAm): ?>
                                <span class="badge-completed">✔ Matin contre-signé</span>
                                <?php if ($sigPm !== null): ?>
                                    <div style="margin-top: 0.2rem;"><span class="badge-code">⏳ Visa PM en attente</span></div>
                                <?php endif; ?>
                            <?php elseif ($trainerSignedPm): ?>
                                <span class="badge-completed">✔ Après-midi contre-signé</span>
                                <?php if ($sigAm !== null): ?>
                                    <div style="margin-top: 0.2rem;"><span class="badge-code">⏳ Visa AM en attente</span></div>
                                <?php endif; ?>
                            <?php elseif ($sigAm !== null || $sigPm !== null): ?>
                                <span class="badge-code">⏳ En attente visa formateur</span>
                            <?php else: ?>
                                <span style="font-size: 0.78rem; color: var(--text-muted);">—</span>
                            <?php endif; ?>

                            <?php if ($isStaffUser && ($sigAm !== null || $sigPm !== null) && !($trainerSignedAm && $trainerSignedPm)): ?>
                                <form method="POST" action="index.php?page=attendance&action=sign_trainer_attendance&course=<?= urlencode($currentCourseSlug) ?>" style="margin-top: 0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="target_user_id" value="<?= $inspectedUserId ?>"/>
                                    <input type="hidden" name="day_num" value="<?= $dayNum ?>"/>
                                    <input type="hidden" name="half_day_key" value="J<?= $dayNum ?>_DAY"/>
                                    <input type="hidden" name="return_student_id" value="<?= $inspectedUserId ?>"/>
                                    <input type="hidden" name="return_track" value="<?= Security::e($selectedTrack) ?>"/>
                                    <button type="submit" class="btn btn-outline-cyan btn-sm" style="font-size: 0.74rem;">
                                        ✒️ Contre-signer J<?= $dayNum ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
