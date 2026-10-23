<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
?>

<!-- BANNIÈRE HERO DE LA FORMATION ACTIVE -->
<section class="hero-banner">
    <div style="max-width: 760px;">
        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.65rem;">
            <span class="badge-code"><?= Security::e($currentCourse['badge']) ?></span>
            <span class="badge-code" style="background: rgba(16, 185, 129, 0.15); color: #34D399; border-color: rgba(16, 185, 129, 0.3);">
                ✔ Certifié Qualiopi & Datadock
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                ⏱ <?= Security::e($currentCourse['duration']) ?>
            </span>
        </div>
        <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;"><?= Security::e($currentCourse['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.92rem;">
            <?= Security::e($currentCourse['description']) ?>
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1.25rem;">
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                📚 Parcourir les <?= $totalModules ?> Modules
            </a>
            <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                🧪 Accéder aux <?= $totalLabs ?> Ateliers Docker
            </a>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                📥 Télécharger tous les Supports (PDF, PPTX, MD, HTML, DOCX)
            </a>
            <?php if (isset($currentCourse['referentiel']['GUIDE_PARCOURS'])): ?>
                <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=referentiel&id=GUIDE_PARCOURS&format=pdf" class="btn btn-outline">
                    🗺️ Guide des Parcours (PDF)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div style="min-width: 240px; text-align: center; background: rgba(11, 17, 32, 0.65); padding: 1.35rem; border-radius: var(--radius-md); border: 1px solid var(--border-hairline);">
        <div class="kpi-label">Votre Progression Globale</div>
        <div class="kpi-value" style="color: var(--brand-cyan-light); font-size: 2.4rem;"><?= $globalCompletionPct ?>%</div>
        <div class="progress-bar-track">
            <div class="progress-bar-fill" style="width: <?= $globalCompletionPct ?>%;"></div>
        </div>
        <div class="kpi-sub" style="margin-top: 0.55rem;">
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
        <div class="kpi-value"><?= $doneModules ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Supports PDF, Slides PPTX, HTML & Markdown</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">🧪 Ateliers Pratiques (Labs)</div>
        <div class="kpi-value"><?= $doneLabs ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalLabs ?></span></div>
        <div class="kpi-sub">Validés sur conteneurs Docker & Base Sakila</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">❓ Quiz QCM Réussis</div>
        <div class="kpi-value"><?= count($userProgress['quiz_scores'] ?? []) ?> <span style="font-size: 1rem; color: var(--text-muted);">/ <?= $totalModules ?></span></div>
        <div class="kpi-sub">Évaluations formatives Bloom C01–C29</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">📑 Fiches Mémo & Outils DBA</div>
        <div class="kpi-value"><?= count($currentCourse['cheatsheets'] ?? []) + count($currentCourse['tools'] ?? []) ?></div>
        <div class="kpi-sub"><?= count($currentCourse['cheatsheets'] ?? []) ?> Cheatsheets + <?= count($currentCourse['tools'] ?? []) ?> Scripts pgscripts</div>
    </div>
</div>

<!-- COCKPIT DOCKER PERSONNEL PAR UTILISATEUR -->
<section id="docker-panel" class="docker-cockpit">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem;">
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
            <?php if (!empty($dockerStatus['running'])): ?>
                <button type="button" class="btn btn-danger btn-docker-cmd" data-cmd="stop">
                    ⏹ Arrêter
                </button>
            <?php endif; ?>
        </div>
    </div>
    <div id="docker-action-feedback" class="flash-banner flash-success" style="display: none; margin-top: 1rem; margin-bottom: 0;"></div>
</section>

<!-- LES 4 PARCOURS CERTIFIANTS DE LA FORMATION -->
<h2 style="font-size: 1.35rem; margin-bottom: 1rem;">🧭 Les 4 Parcours Pédagogiques Certifiants</h2>
<div class="card-grid" style="margin-bottom: 2.25rem;">
    <?php foreach (($currentCourse['tracks'] ?? []) as $tKey => $track):
        $trackMods = $track['module_Filter'] ?? [];
        $trackTotal = count($trackMods);
        $trackDone = 0;
        foreach (($currentCourse['modules'] ?? []) as $mId => $mObj) {
            if (in_array($mObj['code'], $trackMods, true) && in_array($mId, $userProgress['completed_modules'] ?? [], true)) {
                $trackDone++;
            }
        }
        $trackPct = $trackTotal > 0 ? (int)round(($trackDone / $trackTotal) * 100) : 0;
    ?>
        <div class="lms-card" style="border-top: 3px solid <?= Security::e($track['color']) ?>;">
            <div>
                <div class="card-top-meta">
                    <span class="badge-code"><?= Security::e($track['badge']) ?></span>
                    <span class="tabular-nums" style="font-size: 0.8rem; font-weight: 700; color: var(--brand-cyan-light);">
                        <?= $trackDone ?> / <?= $trackTotal ?> modules (<?= $trackPct ?>%)
                    </span>
                </div>
                <h3 style="font-size: 1.1rem; margin-bottom: 0.45rem;"><?= Security::e($track['title']) ?></h3>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                    <?= Security::e($track['description']) ?>
                </p>
            </div>
            <div>
                <div class="progress-bar-track" style="margin-bottom: 0.85rem;">
                    <div class="progress-bar-fill" style="width: <?= $trackPct ?>%;"></div>
                </div>
                <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="width: 100%;">
                    Explorer ce Parcours →
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ACCÈS RAPIDE AUX MODULES DE COURS ET TÉLÉCHARGEMENTS -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
    <h2 style="font-size: 1.35rem; margin: 0;">📖 Modules de Cours & Téléchargements Directs</h2>
    <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">Voir les <?= $totalModules ?> Modules →</a>
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
                    <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
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
                <div style="display: flex; gap: 0.5rem;">
                    <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm" style="flex: 1;">
                        📖 Étudier le Cours
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
