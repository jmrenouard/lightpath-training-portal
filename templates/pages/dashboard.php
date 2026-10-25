<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
?>

<!-- BANNIÈRE HERO DE LA FORMATION ACTIVE -->
<section class="hero-banner">
    <div style="max-width: 780px; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.65rem;">
            <span class="badge-code"><?= Security::e($currentCourse['badge']) ?></span>
            <span class="badge-code" style="background: rgba(16, 185, 129, 0.15); color: #34D399; border-color: rgba(16, 185, 129, 0.3);">
                ✔ Certifié Qualiopi & Datadock
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                ⏱ <?= Security::e($currentCourse['duration']) ?>
            </span>
        </div>
        <h1 style="font-size: 1.7rem; margin-bottom: 0.5rem;"><?= Security::e($currentCourse['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.92rem;">
            <?= Security::e($currentCourse['description']) ?>
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1.25rem;">
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                📚 Explorer les 4 Parcours & <?= $totalModules ?> Modules
            </a>
            <a href="index.php?page=module&id=module_01_introduction_postgresql_18&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan">
                🖥️ Lancer en Mode Diaporama 16:9
            </a>
            <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                🧪 Accéder aux <?= $totalLabs ?> Ateliers Docker
            </a>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                📥 Centre de Téléchargement (6 formats)
            </a>
        </div>
    </div>

    <div style="min-width: 240px; text-align: center; background: rgba(11, 17, 32, 0.65); padding: 1.35rem; border-radius: var(--radius-md); border: 1px solid var(--border-hairline);">
        <div class="kpi-label">Votre Progression Globale</div>
        <div class="kpi-value tabular-nums" style="color: var(--brand-cyan-light); font-size: 2.4rem;"><?= $globalCompletionPct ?>%</div>
        <div class="progress-bar-track">
            <div class="progress-bar-fill" style="width: <?= $globalCompletionPct ?>%;"></div>
        </div>
        <div class="kpi-sub tabular-nums" style="margin-top: 0.55rem;">
            <strong><?= $doneModules ?></strong>/<?= $totalModules ?> Modules • <strong><?= $doneLabs ?></strong>/<?= $totalLabs ?> Labs
        </div>
        <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="margin-top: 0.85rem; width: 100%;">
            🎓 Voir mon Attestation Qualiopi
        </a>
    </div>
</section>

<!-- CARTES KPI TABULAR-NUMS -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">📚 Modules Validés</div>
        <div class="kpi-value tabular-nums"><?= $doneModules ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Cours interactifs, Diaporamas 16:9, PDF & PPTX</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">🧪 Ateliers Pratiques (Labs)</div>
        <div class="kpi-value tabular-nums"><?= $doneLabs ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalLabs ?></span></div>
        <div class="kpi-sub">Validés sur conteneurs Docker & Base Sakila</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">❓ Quiz QCM Réussis</div>
        <div class="kpi-value tabular-nums"><?= count($userProgress['quiz_scores'] ?? []) ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Évaluations formatives Bloom C01–C29</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">📑 Fiches Mémo & Outils DBA</div>
        <div class="kpi-value tabular-nums"><?= count($currentCourse['cheatsheets'] ?? []) + count($currentCourse['tools'] ?? []) ?></div>
        <div class="kpi-sub"><?= count($currentCourse['cheatsheets'] ?? []) ?> Cheatsheets + <?= count($currentCourse['tools'] ?? []) ?> Scripts pgscripts</div>
    </div>
</div>

<!-- LES 4 PARCOURS CERTIFIANTS DE LA FORMATION (DÉCOUPE STRUCTURÉE PAR JOURNÉES & PUBLIC CIBLE) -->
<section style="margin-bottom: 2.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.15rem;">
        <div>
            <span class="badge-code">ARCHITECTURE PÉDAGOGIQUE QUALIOPI • 4 PARCOURS MODULAIRES</span>
            <h2 style="font-size: 1.4rem; margin: 0.35rem 0 0.15rem;">🧭 Choisissez votre Parcours de Formation</h2>
            <p style="margin: 0; font-size: 0.88rem; color: var(--text-secondary);">
                Le cursus PostgreSQL 18 (77h) est structuré en <strong>4 parcours complémentaires</strong> organisés par profil métier, niveau et journées pédagogiques. Chaque étape associe un <strong>Module de Cours (texte + slides 16:9)</strong> et un <strong>Atelier Pratique Docker</strong>.
            </p>
        </div>
        <?php if (isset($currentCourse['referentiel']['GUIDE_PARCOURS'])): ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=referentiel&id=GUIDE_PARCOURS&format=pdf" class="btn btn-outline btn-sm">
                🗺️ Télécharger le Guide Officiel des Parcours (PDF)
            </a>
        <?php endif; ?>
    </div>

    <div class="tracks-roadmap-grid">
        <?php foreach (($currentCourse['tracks'] ?? []) as $tKey => $track):
            $trackMods = $track['module_Filter'] ?? [];
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
            $resumeModId = $firstUncompletedModId ?? $firstTrackModId ?? 'module_01_introduction_postgresql_18';
            $trackPct = $trackTotal > 0 ? (int)round(($trackDone / $trackTotal) * 100) : 0;
            $accentColor = (string)($track['color'] ?? '#00B0F0');
        ?>
            <div class="track-roadmap-card" style="--track-accent: <?= Security::e($accentColor) ?>;">
                <div class="track-roadmap-header">
                    <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap;">
                        <span class="track-number-pill" style="background: <?= Security::e($accentColor) ?>;">
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

                <!-- Métadonnées Public Cible / Prérequis / Objectif -->
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

                <!-- Découpage par Journées / Étapes du Parcours -->
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
                    <div class="progress-bar-track" style="margin-bottom: 0.75rem;">
                        <div class="progress-bar-fill" style="width: <?= $trackPct ?>%;"></div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="flex: 1;">
                            🗺️ Détail du <?= Security::e($track['number'] ?? 'Parcours') ?>
                        </a>
                        <a href="index.php?page=module&id=<?= urlencode($resumeModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm" style="flex: 1;">
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

<!-- COCKPIT DOCKER PERSONNEL PAR UTILISATEUR -->
<section id="docker-panel" class="docker-cockpit">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="min-width: 0; flex: 1;">
            <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
                <span class="badge-code">🐳 ENVIRONNEMENT LAB DOCKER ISOLÉ PAR UTILISATEUR</span>
                <span style="font-size: 0.8rem; color: var(--text-secondary);">
                    Moteur détecté : <strong><?= Security::e($dockerStatus['runtime_version'] ?? '') ?></strong>
                </span>
            </div>
            <h2 style="font-size: 1.25rem; margin: 0.45rem 0 0.25rem;">
                Instance Dédiée : <code><?= Security::e($dockerStatus['container_name']) ?></code>
                (Port Hôte : <code class="tabular-nums"><?= (int)$dockerStatus['port'] ?></code>)
            </h2>
            <p style="margin: 0; font-size: 0.86rem; color: var(--text-secondary);">
                Connexion CLI locale : <code><?= Security::e($dockerStatus['connection_string']) ?></code>
                • Base pré-configurée : <code>formation_pg</code> (Extensions <code>pageinspect</code>, <code>pg_stat_statements</code> & Base <code>Sakila</code>).
            </p>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 0.55rem;">
            <button type="button" class="btn btn-emerald btn-docker-cmd" data-cmd="start">
                ▶ Démarrer mon Lab Docker
            </button>
            <button type="button" class="btn btn-outline btn-docker-cmd" data-cmd="reset">
                🔄 Réinitialiser (Clean State)
            </button>
            <button type="button" class="btn btn-danger btn-docker-cmd" data-cmd="stop">
                ⏹ Arrêter
            </button>
        </div>
    </div>
    <div id="docker-action-feedback" class="flash-banner flash-success" style="display: none; margin-top: 1rem; margin-bottom: 0;"></div>
</section>

<!-- ACCÈS RAPIDE AUX PREMIERS MODULES DE COURS ET TÉLÉCHARGEMENTS -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
    <h2 style="font-size: 1.35rem; margin: 0;">📖 Accès Rapide aux Premiers Modules & Supports</h2>
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
                    <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                        <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
                        <span class="badge-phase"><?= Security::e($mod['phase_code'] ?? '') ?> • Étape <?= (int)$mod['track_position'] ?>/<?= (int)$mod['track_total'] ?></span>
                    </div>
                    <?php if ($isDone): ?>
                        <span class="badge-completed">✔ Terminé</span>
                    <?php else: ?>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">⏱ <?= Security::e($mod['duration']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem;">
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="color: var(--text-primary);">
                        <?= Security::e($mod['title']) ?>
                    </a>
                </h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                    <?= Security::e($mod['bloom']) ?>
                </p>
            </div>

            <div>
                <div style="display: flex; gap: 0.45rem; flex-wrap: wrap;">
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm" style="flex: 1;">
                        📖 Cours
                    </a>
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm" title="Suivre sous forme de Slides 16:9">
                        🖥️ Slides 16:9
                    </a>
                    <?php if (!empty($mod['linked_lab_id'])): ?>
                        <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                            🧪 Lab Docker
                        </a>
                    <?php endif; ?>
                </div>
                <?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? [], true) ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
