<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$firstDashModId = (string)(array_key_first($currentCourse['modules'] ?? []) ?: 'module_01_introduction_postgresql_18');
$dashTrackCount = count($currentCourse['tracks'] ?? []);
$dashCourseDays = !empty($currentCourse['attendance_days'])
    ? (int)$currentCourse['attendance_days']
    : (preg_match('/(\d+)\s*JOURS?/i', (string)($currentCourse['duration'] ?? ''), $dm) ? (int)$dm[1] : 10);
$dashTotalHalfDays = max(2, $dashCourseDays * 2);
$gamificationEnabled = !isset($featureManager) || $featureManager->isEnabledForCourse('gamification', $currentCourseSlug);
$dockerLabsEnabled = !isset($featureManager) || $featureManager->isEnabledForCourse('docker_labs', $currentCourseSlug);
$courseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$courseDockerEnabled = $courseHasDocker && $dockerLabsEnabled;
$dashDiagCount = count(\LightPath\LMS\Course\LmsCatalog::getDiagnosticQuestions($currentCourseSlug));
$dashExamCount = count(\LightPath\LMS\Course\LmsCatalog::getFinalExamQuestions($currentCourseSlug));
$dashFlashcardsCount = count(\LightPath\LMS\Course\LmsCatalog::getFlashcards($currentCourseSlug));
$dashXp = (int)($userGamification['xp'] ?? 0);
$dashCourseBadge = (string)($currentCourse['badge'] ?? 'LightPath LMS');
$dashRankLabel = (string)($userGamification['rank'] ?? ($currentCourseSlug === 'postgresql-18' ? 'Apprenant DBA' : ('Apprenant • ' . $dashCourseBadge)));
?>

<!-- Active course hero banner -->
<section class="hero-banner">
    <div class="hero-content-col">
        <div class="d-flex items-center gap-sm flex-wrap mb-xs">
            <span class="badge-code"><?= Security::e($currentCourse['badge']) ?></span>
            <span class="badge-code badge-emerald-soft">
                ✔ Certifié Qualiopi & Datadock
            </span>
            <span class="text-sm text-secondary">
                ⏱ <?= Security::e($currentCourse['duration']) ?>
            </span>
        </div>
        <h1 class="hero-title-lg mb-xs"><?= Security::e($currentCourse['title']) ?></h1>
        <p class="hero-subtitle-sm">
            <?= Security::e($currentCourse['description']) ?>
        </p>
        <div class="d-flex flex-wrap gap-sm mt-1">
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                📚 Explorer les <?= $dashTrackCount ?> Parcours & <?= $totalModules ?> Modules
            </a>
            <a href="index.php?page=module&id=<?= urlencode($firstDashModId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan">
                🖥️ Lancer en Mode Diaporama 16:9
            </a>
            <?php if ($dockerLabsEnabled): ?>
                <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                    <?= $courseDockerEnabled ? ('🧪 Accéder aux ' . $totalLabs . ' Ateliers Docker') : ('🧪 Accéder aux ' . $totalLabs . ' Ateliers Pratiques') ?>
                </a>
            <?php endif; ?>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                📥 Centre de Téléchargement (<?= $currentCourseSlug === 'postgresql-18' ? '6 formats' : 'Supports & Fiches' ?>)
            </a>
            <a href="index.php?page=diagnostic&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                🧭 Diagnostic Initial (<?= $dashDiagCount ?> QCM)
            </a>
            <a href="index.php?page=exam&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                🏆 Examen Final (<?= $dashExamCount ?> QCM)
            </a>
        </div>
    </div>

    <div class="hero-progress-box">
        <div class="kpi-label">Votre Progression Globale</div>
        <div class="kpi-value tabular-nums text-cyan hero-kpi-pct"><?= $globalCompletionPct ?>%</div>
        <div class="progress-bar-track">
            <div class="progress-bar-fill" data-progress="<?= (int)$globalCompletionPct ?>"></div>
        </div>
        <div class="kpi-sub tabular-nums mt-xs">
            <strong><?= $doneModules ?></strong>/<?= $totalModules ?> Modules • <strong><?= $doneLabs ?></strong>/<?= $totalLabs ?> Labs
        </div>
        <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm mt-sm w-100">
            🎓 Voir mon Attestation Qualiopi
        </a>
    </div>
</section>

<!-- Tabular-nums KPI cards -->
<?php
$lastVisited = $userProgress['last_visited'] ?? null;
$bookmarksList = $userProgress['bookmarks'] ?? [];
?>
<?php if (is_array($lastVisited) && !empty($lastVisited['id'])):
    $lvType = (string)($lastVisited['type'] ?? 'module');
    $lvId = (string)$lastVisited['id'];
    $lvTitle = (string)($lastVisited['title'] ?? $lvId);
    $lvMode = (string)($lastVisited['mode'] ?? ($lvType === 'lab' ? 'enonce' : 'reader'));
    $lvUrl = 'index.php?page=' . urlencode($lvType) . '&id=' . urlencode($lvId) . '&mode=' . urlencode($lvMode) . '&course=' . urlencode($currentCourseSlug);
?>
<div class="lms-card mb-lg p-card-resume lms-card--accent-cyan d-flex flex-row justify-between items-center flex-wrap gap-md">
    <div class="d-flex items-center gap-sm flex-wrap">
        <span class="badge-code">▶️ REPRENDRE VOTRE ÉTUDE</span>
        <strong><?= Security::e($lvTitle) ?></strong>
        <span class="text-sm text-muted">
            (<?= $lvType === 'lab' ? ($courseDockerEnabled ? 'Atelier Pratique Docker' : 'Atelier Pratique') : 'Module de Cours' ?>)
        </span>
    </div>
    <div class="d-flex gap-xs flex-wrap">
        <a href="<?= Security::e($lvUrl) ?>" class="btn btn-primary btn-sm">
            ▶ Reprendre immédiatement →
        </a>
        <a href="index.php?action=export_notes_md&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
            📝 Exporter mes notes (.md)
        </a>
    </div>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">📚 Modules Validés</div>
        <div class="kpi-value tabular-nums"><?= $doneModules ?> <span class="kpi-denom">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Cours interactifs, Diaporamas 16:9, PDF & PPTX</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">🧪 Ateliers Pratiques (Labs)</div>
        <div class="kpi-value tabular-nums"><?= $doneLabs ?> <span class="kpi-denom">/ <?= $totalLabs ?></span></div>
        <div class="kpi-sub"><?= $courseDockerEnabled ? 'Validés sur conteneurs Docker & Base Sakila' : 'Ateliers pratiques pas-à-pas & grilles DoD' ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">❓ Quiz QCM Réussis</div>
        <div class="kpi-value tabular-nums"><?= count($userProgress['quiz_scores'] ?? []) ?> <span class="kpi-denom">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Évaluations formatives Bloom (<?= $totalModules ?> modules)</div>
    </div>
    <div class="kpi-card">
        <?php
        $cheatsheetsCount = count($currentCourse['cheatsheets'] ?? []);
        $toolsCount = count($currentCourse['tools'] ?? []);
        $examplesCount = count($currentCourse['examples'] ?? []);
        ?>
        <div class="kpi-label"><?= $courseDockerEnabled ? '📑 Fiches Mémo & Outils DBA' : '📑 Fiches Mémo & Outils' ?></div>
        <div class="kpi-value tabular-nums"><?= $cheatsheetsCount + $toolsCount + $examplesCount ?></div>
        <div class="kpi-sub">
            <?= $cheatsheetsCount ?> Cheatsheets + <?= $toolsCount ?> <?= $currentCourseSlug === 'postgresql-18' ? 'Scripts pgscripts' : 'Scripts' ?><?= $examplesCount > 0 ? ' + ' . $examplesCount . ' Exemples Code' : '' ?>
        </div>
    </div>
</div>

<!-- Quick tools, optional gamification badges/XP, and bookmarks -->
<section class="lms-card mb-2">
    <div class="d-flex justify-between items-center flex-wrap gap-1 mb-1">
        <?php if ($gamificationEnabled): ?>
            <div>
                <span class="badge-code"><?= $courseDockerEnabled ? '🏅 GAMIFICATION & CERTIFICATION DE COMPÉTENCES DBA' : '🏅 GAMIFICATION & VALIDATION DE COMPÉTENCES' ?></span>
                <h2 class="section-heading-md mt-xxs mb-0">
                    Grade : <span class="text-cyan"><?= Security::e($dashRankLabel) ?></span>
                    (<span class="tabular-nums"><?= (int)($userGamification['xp'] ?? 0) ?> XP</span> • Temps actif : <span class="tabular-nums"><?= Security::e($userGamification['learning_hours_formatted'] ?? '0 min') ?></span>)
                </h2>
            </div>
        <?php else: ?>
            <div>
                <span class="badge-code">🛠️ OUTILS DE SUIVI & RÉVISION QUALIOPI</span>
                <h2 class="section-heading-md mt-xxs mb-0">Raccourcis Émargement, Révisions & Notes</h2>
            </div>
        <?php endif; ?>
        <div class="d-flex gap-sm flex-wrap">
            <a href="index.php?page=attendance&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                ✍️ Émargement (<?= count($userAttendance ?? []) ?>/<?= (int)$dashTotalHalfDays ?>)
            </a>
            <a href="index.php?page=flashcards&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                🧠 Révision SRS (<?= $dashFlashcardsCount ?> cartes)
            </a>
            <a href="index.php?page=notes&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                📝 Carnet de Notes
            </a>
            <a href="index.php?action=export_notes_md&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                📥 Exporter mes notes (.md)
            </a>
        </div>
    </div>

    <?php if ($gamificationEnabled): ?>
        <div class="badges-auto-grid">
            <?php foreach (($userGamification['badges'] ?? []) as $badge): ?>
                <div class="gamification-badge-card <?= !empty($badge['unlocked']) ? 'is-unlocked' : 'is-locked' ?>">
                    <div class="d-flex justify-between items-center mb-xxs">
                        <strong class="text-sm"><?= Security::e($badge['icon'] . ' ' . $badge['title']) ?></strong>
                        <?php if (!empty($badge['unlocked'])): ?>
                            <span class="badge-completed badge-xs">Débloqué</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-xs text-secondary"><?= Security::e($badge['description']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($bookmarksList)): ?>
        <div class="mt-1 pt-sm border-top-hairline">
            <strong class="text-sm text-cyan">⭐ Mes Favoris Enregistrés :</strong>
            <div class="d-flex gap-sm flex-wrap mt-xs">
                <?php foreach ($bookmarksList as $bm):
                    $bmUrl = 'index.php?page=' . urlencode((string)($bm['type'] ?? 'module')) . '&id=' . urlencode((string)($bm['id'] ?? '')) . '&course=' . urlencode($currentCourseSlug);
                ?>
                    <a href="<?= Security::e($bmUrl) ?>" class="badge-code no-underline">
                        ⭐ <?= Security::e((string)($bm['title'] ?? $bm['id'] ?? '')) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Curriculum tracks structured by learning phase and target audience -->
<section class="mb-2">
    <div class="d-flex justify-between items-end flex-wrap gap-1 mb-1">
        <div>
            <span class="badge-code">ARCHITECTURE PÉDAGOGIQUE QUALIOPI • <?= $dashTrackCount ?> PARCOURS MODULAIRES</span>
            <h2 class="section-heading-lg mt-xs mb-0">🧭 Choisissez votre Parcours de Formation</h2>
            <p class="hero-subtitle-sm mb-0">
                Le cursus (<?= Security::e((string)($currentCourse['duration'] ?? '70h')) ?>) est structuré en <strong><?= $dashTrackCount ?> parcours <?= $dashTrackCount > 1 ? 'complémentaires' : 'structuré' ?></strong> organisés par profil métier, niveau et séquence pédagogique. Chaque étape associe un <strong>Module de Cours (texte + slides 16:9)</strong> et un <strong><?= $courseDockerEnabled ? 'Atelier Pratique Docker' : 'Atelier Pratique Guidé' ?></strong>.
            </p>
        </div>
        <?php if (isset($currentCourse['referentiel']['GUIDE_PARCOURS'])):
            $guideDownloads = $currentCourse['referentiel']['GUIDE_PARCOURS']['downloads'] ?? [];
            $guideFormat = isset($guideDownloads['pdf']) ? 'pdf' : (isset($guideDownloads['md']) ? 'md' : (string)(array_key_first($guideDownloads) ?: 'md'));
        ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=referentiel&id=GUIDE_PARCOURS&format=<?= urlencode($guideFormat) ?>" class="btn btn-outline btn-sm">
                🗺️ Télécharger le Guide Officiel des Parcours (<?= Security::e(strtoupper($guideFormat)) ?>)
            </a>
        <?php endif; ?>
    </div>

    <div class="tracks-roadmap-grid">
        <?php foreach (($currentCourse['tracks'] ?? []) as $tKey => $track):
            $trackMods = $track['module_filter'] ?? $track['module_Filter'] ?? [];
            $trackTotal = count($trackMods);
            $trackDone = 0;
            $firstUncompletedModId = null;
            $firstTrackModId = null;

            foreach (($currentCourse['modules'] ?? []) as $mId => $mObj) {
                if (in_array($mObj['code'], $trackMods, true)) {
                    if ($firstTrackModId === null) {
                        $firstTrackModId = (string)$mId;
                    }
                    if (in_array($mId, $userProgress['completed_modules'] ?? [], true)) {
                        $trackDone++;
                    } elseif ($firstUncompletedModId === null) {
                        $firstUncompletedModId = (string)$mId;
                    }
                }
            }
            $resumeModId = $firstUncompletedModId ?? $firstTrackModId ?? $firstDashModId;
            $trackPct = $trackTotal > 0 ? (int)round(($trackDone / $trackTotal) * 100) : 0;
            $accentColor = (string)($track['color'] ?? '#00B0F0');
        ?>
            <div class="track-roadmap-card" data-accent="<?= Security::e($accentColor) ?>">
                <div class="track-roadmap-header">
                    <div class="d-flex items-center gap-xs flex-wrap">
                        <span class="track-number-pill">
                            <?= Security::e($track['number'] ?? 'Parcours') ?>
                        </span>
                        <span class="badge-code"><?= Security::e($track['badge']) ?></span>
                    </div>
                    <span class="tabular-nums track-progress-badge">
                        <?= $trackDone ?> / <?= $trackTotal ?> modules (<?= $trackPct ?>%)
                    </span>
                </div>

                <h3 class="track-roadmap-title"><?= Security::e($track['title']) ?></h3>
                <p class="track-roadmap-desc"><?= Security::e($track['description']) ?></p>

                <!-- Target audience, prerequisites and learning objective metadata -->
                <div class="track-meta-boxes">
                    <?php if (!empty($track['target_audience'])): ?>
                        <div class="track-meta-row">
                            <span class="track-meta-label">👥 Public cible :</span>
                            <span class="track-meta-val"><?= Security::e($track['target_audience']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($track['prerequisites'])): ?>
                        <div class="track-meta-row">
                            <span class="track-meta-label">🔑 Prérequis :</span>
                            <span class="track-meta-val"><?= Security::e($track['prerequisites']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($track['objective'])): ?>
                        <div class="track-meta-row">
                            <span class="track-meta-label">🎯 Objectif :</span>
                            <span class="track-meta-val"><?= Security::e($track['objective']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Phase breakdown by training day and step -->
                <?php if (!empty($track['phases'])): ?>
                    <div class="track-phases-list">
                        <?php foreach ($track['phases'] as $phase): ?>
                            <div class="track-phase-item">
                                <span class="track-phase-code"><?= Security::e($phase['code']) ?></span>
                                <div class="track-phase-info">
                                    <div class="track-phase-title"><?= Security::e($phase['title']) ?></div>
                                    <div class="track-phase-mods">
                                        <?php foreach (($phase['modules'] ?? []) as $pCode):
                                            $matchedModId = null;
                                            $matchedBadge = str_starts_with($pCode, 'sql_') ? strtoupper(str_replace('_', ' ', $pCode)) : 'M' . $pCode;
                                            foreach (($currentCourse['modules'] ?? []) as $candId => $candMod) {
                                                if ($candMod['code'] === $pCode) {
                                                    $matchedModId = $candId;
                                                    break;
                                                }
                                            }
                                            $modDone = $matchedModId !== null && in_array($matchedModId, $userProgress['completed_modules'] ?? [], true);
                                        ?>
                                            <?php if ($matchedModId !== null): ?>
                                                <a href="index.php?page=module&id=<?= urlencode($matchedModId) ?>&course=<?= urlencode($currentCourseSlug) ?>"
                                                   class="phase-mod-chip <?= $modDone ? 'is-done' : '' ?>"
                                                   title="<?= Security::e($currentCourse['modules'][$matchedModId]['title'] ?? '') ?>">
                                                    <?= $modDone ? '✔ ' : '' ?><?= Security::e($matchedBadge) ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="track-roadmap-footer">
                    <div class="progress-bar-track mb-sm">
                        <div class="progress-bar-fill" data-progress="<?= $trackPct ?>"></div>
                    </div>
                    <div class="d-flex gap-xs flex-wrap">
                        <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm flex-1">
                            🗺️ Détail du <?= Security::e($track['number'] ?? 'Parcours') ?>
                        </a>
                        <a href="index.php?page=module&id=<?= urlencode($resumeModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm flex-1">
                            ▶ <?= $trackDone > 0 ? 'Continuer le Parcours' : 'Démarrer le Parcours' ?>
                        </a>
                        <a href="index.php?page=module&id=<?= urlencode($resumeModId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm" title="Suivre ce parcours en Mode Diaporama 16:9">
                            🖥️ Slides
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($courseDockerEnabled): ?>
<!-- Per-user isolated Docker container cockpit -->
<section id="docker-panel" class="docker-cockpit">
    <div class="d-flex justify-between items-center flex-wrap gap-1">
        <div class="flex-1 min-w-0">
            <div class="d-flex items-center gap-sm flex-wrap">
                <span class="badge-code">🐳 ENVIRONNEMENT LAB DOCKER ISOLÉ PAR UTILISATEUR</span>
                <span class="text-sm text-secondary">
                    Moteur détecté : <strong><?= Security::e($dockerStatus['runtime_version'] ?? '') ?></strong>
                </span>
            </div>
            <h2 class="section-heading-md mt-xs mb-xxs">
                Instance Dédiée : <code><?= Security::e($dockerStatus['container_name']) ?></code>
                (Port Hôte : <code class="tabular-nums"><?= (int)$dockerStatus['port'] ?></code>)
            </h2>
            <p class="hero-subtitle-sm mb-0">
                Connexion CLI locale : <code><?= Security::e($dockerStatus['connection_string']) ?></code>
                • Base pré-configurée : <code>formation_pg</code> (Extensions <code>pageinspect</code>, <code>pg_stat_statements</code> & Base <code>Sakila</code>).
            </p>
        </div>

        <div class="d-flex flex-wrap gap-sm">
            <button type="button" class="btn btn-emerald btn-docker-cmd" data-cmd="start">
                ▶ Démarrer mon Lab Docker
            </button>
            <button type="button" class="btn btn-outline btn-docker-cmd" data-cmd="reset" data-confirm="Réinitialiser votre conteneur Docker PostgreSQL 18 à l'état initial ?">
                🔄 Réinitialiser (Clean State)
            </button>
            <button type="button" class="btn btn-danger btn-docker-cmd" data-cmd="stop">
                ⏹ Arrêter
            </button>
        </div>
    </div>
    <div id="docker-action-feedback" class="flash-banner flash-success mt-1 mb-0" hidden aria-live="polite"></div>
</section>
<?php endif; ?>

<!-- Quick access to initial modules and multi-format downloads -->
<div class="d-flex justify-between items-center mb-1 flex-wrap gap-sm">
    <h2 class="section-heading-lg mb-0">📖 Accès Rapide aux Premiers Modules & Supports</h2>
    <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">Voir les <?= $totalModules ?> Modules classés par Parcours →</a>
</div>

<div class="card-grid">
    <?php
    $previewModules = array_slice($currentCourse['modules'] ?? [], 0, 6, true);
    foreach ($previewModules as $mId => $mod):
        $isDone = in_array($mId, $userProgress['completed_modules'] ?? [], true);
    ?>
        <article class="lms-card <?= $isDone ? 'is-completed' : '' ?>" data-searchable="<?= Security::e($mod['badge_code'] . ' ' . $mod['title'] . ' ' . $mod['bloom']) ?>">
            <div>
                <div class="card-top-meta">
                    <div class="d-flex items-center gap-xs flex-wrap">
                        <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
                        <span class="badge-phase"><?= Security::e($mod['phase_code'] ?? '') ?> • Étape <?= (int)$mod['track_position'] ?>/<?= (int)$mod['track_total'] ?></span>
                    </div>
                    <?php if ($isDone): ?>
                        <span class="badge-completed">✔ Terminé</span>
                    <?php else: ?>
                        <span class="text-xs text-muted">⏱ <?= Security::e($mod['duration']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 class="section-heading-sm mb-xs">
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="link-primary">
                        <?= Security::e($mod['title']) ?>
                    </a>
                </h3>
                <p class="card-desc-sm">
                    <?= Security::e($mod['bloom']) ?>
                </p>
            </div>

            <div>
                <div class="d-flex gap-xs flex-wrap">
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm flex-1">
                        📖 Cours
                    </a>
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm" title="Suivre sous forme de Slides 16:9">
                        🖥️ Slides 16:9
                    </a>
                    <?php if ($dockerLabsEnabled && !empty($mod['linked_lab_id'])): ?>
                        <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                            <?= $courseDockerEnabled ? '🧪 Lab Docker' : '🧪 Lab' ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? [], true) ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
