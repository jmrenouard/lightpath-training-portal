<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$selectedTrackKey = trim((string)($_GET['track'] ?? 'all'));
$tracksMap = $currentCourse['tracks'] ?? [];
$activeTrackFilter = null;
$activeTrackTitle = (string)$currentCourse['title'];
$activeTrackDuration = (string)($currentCourse['duration'] ?? '70 HEURES (10 JOURS)');

if ($selectedTrackKey !== '' && $selectedTrackKey !== 'all' && isset($tracksMap[$selectedTrackKey])) {
    $tCfg = $tracksMap[$selectedTrackKey];
    $activeTrackFilter = $tCfg['module_filter'] ?? $tCfg['module_Filter'] ?? null;
    $activeTrackTitle = (string)($tCfg['title'] ?? $activeTrackTitle);
    $activeTrackDuration = (string)($tCfg['badge'] ?? $activeTrackDuration);
}
$certCourseDays = !empty($currentCourse['attendance_days'])
    ? (int)$currentCourse['attendance_days']
    : (preg_match('/(\d+)\s*JOURS?/i', (string)($currentCourse['duration'] ?? ''), $dm) ? (int)$dm[1] : 10);
$certTotalHalfDays = max(2, $certCourseDays * 2);
$certTotalBadges = max(1, (int)($userGamification['total_badges'] ?? 8));

$filteredModules = [];
$filteredDoneModules = 0;
$filteredTotalLabs = 0;
$filteredDoneLabs = 0;

foreach (($currentCourse['modules'] ?? []) as $mId => $mod) {
    if ($activeTrackFilter !== null && !in_array($mod['code'] ?? '', $activeTrackFilter, true)) {
        continue;
    }
    $filteredModules[$mId] = $mod;
    if (in_array($mId, $userProgress['completed_modules'] ?? [], true)) {
        $filteredDoneModules++;
    }
    $lId = (string)($mod['linked_lab_id'] ?? '');
    if ($lId !== '') {
        $filteredTotalLabs++;
        if (in_array($lId, $userProgress['completed_labs'] ?? [], true)) {
            $filteredDoneLabs++;
        }
    }
}

$filteredTotalModules = count($filteredModules);
$filteredTotalItems = max(1, $filteredTotalModules + $filteredTotalLabs);
$filteredCompletionPct = min(100, (int)round((($filteredDoneModules + $filteredDoneLabs) / $filteredTotalItems) * 100));

$certCourseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$certPrefix = $currentCourseSlug === 'postgresql-18'
    ? 'PG18'
    : (strtoupper(substr((string)preg_replace('/[^A-Z0-9]/i', '', $currentCourseSlug), 0, 6)) ?: 'COURSE');
$certCode = (string)($userBestExam['certificate_id'] ?? sprintf('LP-%s-ATT-%d-%s', $certPrefix, (int)$userId, date('Ymd')));
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = ($host !== '') ? "{$proto}://{$host}" : rtrim((string)($appConfig['website'] ?? 'https://www.lightpath.fr'), '/');
$verifyUrl = $baseUrl . '/index.php?page=verify&code=' . urlencode($certCode);
?>

<div class="no-print d-flex justify-between items-center flex-wrap gap-sm mb-lg">
    <div>
        <span class="badge-code">TRAÇABILITÉ QUALIOPI & TAXONOMIE DE BLOOM</span>
        <h1 class="hero-title-lg mt-xs mb-0">🎓 Attestation de Compétences & Relevé Qualiopi</h1>
    </div>
    <div class="d-flex items-center gap-sm flex-wrap">
        <form method="GET" action="index.php" class="d-inline-flex items-center gap-xs">
            <input type="hidden" name="page" value="certificate"/>
            <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
            <select name="track" class="form-control cert-track-select" data-auto-submit="change" aria-label="Sélectionner le parcours de l'attestation">
                <option value="all" <?= $selectedTrackKey === 'all' ? 'selected' : '' ?>>Cursus Complet (<?= $totalModules ?> Modules • <?= $totalLabs ?> Labs)</option>
                <?php foreach ($tracksMap as $tKey => $tCfg): ?>
                    <option value="<?= Security::e((string)$tKey) ?>" <?= $selectedTrackKey === (string)$tKey ? 'selected' : '' ?>>
                        <?= Security::e(($tCfg['number'] ?? 'Parcours') . ' — ' . ($tCfg['short_title'] ?? $tCfg['title'] ?? $tKey)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button type="button" data-action="print" class="btn btn-primary">
            🖨️ Imprimer / Exporter en PDF Officiel
        </button>
    </div>
</div>

<div class="certificate-sheet cert-print-frame">
    <div class="cert-header-bar">
        <div class="d-flex items-center gap-md">
            <img src="assets/img/lightpath_logo_white_text.png"
                 data-dark-src="assets/img/lightpath_logo_white_text.png"
                 data-light-src="assets/img/lightpath_logo_dark_text.png"
                 alt="LightPath SARL"
                 class="theme-aware-logo cert-logo-img"/>
            <div>
                <div class="font-extrabold text-lg"><?= Security::e($appConfig['company']) ?></div>
                <div class="text-sm text-secondary">Organisme de Formation Professionnelle • Réf. <?= Security::e($appConfig['qualiopi_ref']) ?></div>
            </div>
        </div>
        <?php if ($currentCourseSlug === 'postgresql-18'): ?>
            <img src="assets/img/postgresql_elephant_dark.png"
                 data-dark-src="assets/img/postgresql_elephant_dark.png"
                 data-light-src="assets/img/postgresql_elephant_light.png"
                 alt="PostgreSQL 18"
                 class="theme-aware-logo cert-logo-img"/>
        <?php else: ?>
            <img src="<?= Security::e(ltrim((string)($currentCourse['icon'] ?? 'assets/img/postgresql_elephant.png'), '/')) ?>"
                 alt="<?= Security::e((string)($currentCourse['short_title'] ?? 'Course')) ?>"
                 class="cert-logo-img"/>
        <?php endif; ?>
    </div>

    <div class="text-center mb-2">
        <div class="badge-code text-sm">ATTESTATION D'ACQUISITION DE COMPÉTENCES PROFESSIONNELLES • DURÉE : <?= Security::e(strtoupper($activeTrackDuration)) ?></div>
        <h2 class="text-2xl mt-sm mb-xs"><?= Security::e($activeTrackTitle) ?></h2>
        <p class="text-secondary mb-0">
            Délivrée à <strong class="text-primary text-lg"><?= Security::e($currentUser['name']) ?></strong>
            (<?= Security::e($currentUser['email']) ?><?= !empty($currentUser['company']) ? ' — ' . Security::e($currentUser['company']) : '' ?>)
        </p>
        <div class="mt-xs">
            <span class="badge-completed tabular-nums">Réf. Certificat : <?= Security::e($certCode) ?><?= !empty($userBestExam['mention']) ? ' • ' . Security::e((string)$userBestExam['mention']) : '' ?></span>
        </div>
    </div>

    <div class="kpi-grid mb-2">
        <div class="kpi-card">
            <div class="kpi-label">Taux d'Accomplissement</div>
            <div class="kpi-value tabular-nums text-cyan"><?= $filteredCompletionPct ?>%</div>
            <div class="kpi-sub">Progression certifiée LMS (<?= Security::e($activeTrackDuration) ?>)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Modules Théoriques Validés</div>
            <div class="kpi-value tabular-nums"><?= $filteredDoneModules ?> / <?= $filteredTotalModules ?></div>
            <div class="kpi-sub">Taxonomie de Bloom (<?= $filteredTotalModules ?> modules)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= $certCourseHasDocker ? 'Ateliers Docker Validés' : 'Ateliers Pratiques Validés' ?></div>
            <div class="kpi-value tabular-nums"><?= $filteredDoneLabs ?> / <?= $filteredTotalLabs ?></div>
            <div class="kpi-sub">Oracles d'exécution du cursus</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Examen Sommatif & Assiduité</div>
            <div class="kpi-value tabular-nums"><?= $userBestExam !== null ? ((int)$userBestExam['percent'] . '%') : 'En attente' ?></div>
            <div class="kpi-sub tabular-nums"><?= count($userAttendance ?? []) ?>/<?= $certTotalHalfDays ?> demi-journées • <?= (int)($userGamification['unlocked_count'] ?? 0) ?>/<?= $certTotalBadges ?> Badges<?= $currentCourseSlug === 'postgresql-18' ? ' DBA' : '' ?></div>
        </div>
    </div>

    <h3 class="section-heading-sm mb-sm">📊 Détail des Modules, Compétences Bloom & Évaluations Formatives</h3>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Module & Compétence Opérationnelle Bloom</th>
                    <th>Cours</th>
                    <th>Quiz QCM</th>
                    <th><?= $certCourseHasDocker ? 'Lab Docker' : 'Atelier' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filteredModules as $mId => $mod):
                    $mDone = in_array($mId, $userProgress['completed_modules'] ?? [], true);
                    $qRes = $userProgress['quiz_scores'][$mId] ?? null;
                    $lId = $mod['linked_lab_id'] ?? '';
                    $lDone = $lId !== '' && in_array($lId, $userProgress['completed_labs'] ?? [], true);
                ?>
                    <tr>
                        <td><span class="badge-code"><?= Security::e($mod['badge_code']) ?></span></td>
                        <td>
                            <strong><?= Security::e($mod['title']) ?></strong>
                            <div class="text-xs text-muted"><?= Security::e($mod['bloom']) ?></div>
                        </td>
                        <td><?= $mDone ? '<span class="badge-completed">✔ Validé</span>' : '<span class="text-muted">En cours</span>' ?></td>
                        <td class="tabular-nums"><?= $qRes ? ((int)$qRes['percentage'] . '%') : '—' ?></td>
                        <td><?= $lDone ? '<span class="badge-completed">✔ Oracle OK</span>' : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="cert-footer-bar">
        <div>
            <strong>Date d'émission :</strong> <?= date('d/m/Y') ?><br/>
            <strong>Vérification :</strong> <?= Security::e($appConfig['website']) ?> | <?= Security::e($appConfig['contact_email']) ?><br/>
            <a href="index.php?page=verify&code=<?= urlencode($certCode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="no-print d-inline-block mt-xs text-cyan font-semibold">
                🔍 Vérifier l'authenticité de ce certificat en ligne
            </a>
        </div>
        <div class="d-flex items-center gap-lg flex-wrap">
            <div class="text-center">
                <?= \LightPath\LMS\Core\Security::renderVerificationQrSvg($verifyUrl, $certCode) ?>
            </div>
            <div class="text-right">
                <strong>Le Formateur & Responsable Pédagogique :</strong><br/>
                <?= Security::e($appConfig['trainer_name']) ?><br/>
                <span class="text-secondary text-xs"><?= Security::e($appConfig['trainer_title']) ?></span>
            </div>
        </div>
    </div>
</div>
