<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$activeTab = (string)($_GET['tab'] ?? 'modules');
if (in_array($activeTab, ['cheatsheets', 'referentiel', 'tools'], true)) {
    require __DIR__ . '/documents.php';
    return;
}
$selectedTrack = (string)($_GET['track'] ?? 'all');
$tracks = $currentCourse['tracks'] ?? [];
$allModules = $currentCourse['modules'] ?? [];
$allLabs = $currentCourse['labs'] ?? [];

// Indexer les modules par code ('01', '02', ..., 'sql_01') pour un affichage structuré par Parcours & Journée
$modulesByCode = [];
foreach ($allModules as $mId => $mObj) {
    $modulesByCode[$mObj['code']] = $mObj;
}
?>

<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.35rem;">
    <div style="min-width: 0; flex: 1;">
        <span class="badge-code">CATALOGUE STRUCTURÉ PAR PARCOURS & JOURNÉES • QUALIOPI</span>
        <h1 style="font-size: 1.7rem; margin: 0.4rem 0 0.2rem;">
            <?= $activeTab === 'labs' ? '🧪 Les 34 Ateliers Pratiques & Labs Docker' : '📚 Les 4 Parcours & 34 Modules de Formation' ?>
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Chaque étape couple un <strong>Module de Cours</strong> (lisible en mode texte interactif ou en <strong>Diaporama 16:9</strong>) et un <strong>Atelier Pratique Docker</strong>, avec téléchargement direct en <code>PDF</code>, <code>Slides PDF</code>, <code>PPTX</code>, <code>MD</code>, <code>HTML</code> et <code>DOCX</code>.
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="index.php?page=catalog&tab=modules&track=<?= urlencode($selectedTrack) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab !== 'labs' ? 'btn-primary' : 'btn-outline' ?>">
            📚 Modules & Parcours (<?= count($allModules) ?>)
        </a>
        <a href="index.php?page=catalog&tab=labs&track=<?= urlencode($selectedTrack) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab === 'labs' ? 'btn-emerald' : 'btn-outline' ?>">
            🧪 Ateliers & TP Docker (<?= count($allLabs) ?>)
        </a>
    </div>
</div>

<!-- SÉLECTEUR VISUEL DES 4 PARCOURS PÉDAGOGIQUES -->
<div class="track-selector-strip">
    <a href="index.php?page=catalog&tab=<?= urlencode($activeTab) ?>&track=all&course=<?= urlencode($currentCourseSlug) ?>"
       class="track-filter-pill <?= $selectedTrack === 'all' ? 'active' : '' ?>">
        <span class="track-filter-num">Vue Globale</span>
        <span class="track-filter-name">Les 4 Parcours Complets</span>
        <span class="track-filter-count tabular-nums">34 étapes</span>
    </a>
    <?php foreach ($tracks as $tKey => $track):
        $tCodes = $track['module_Filter'] ?? [];
        $tDone = 0;
        foreach ($tCodes as $c) {
            if (isset($modulesByCode[$c])) {
                $itemId = $activeTab === 'labs' ? ($modulesByCode[$c]['linked_lab_id'] ?? '') : $modulesByCode[$c]['id'];
                $doneList = $activeTab === 'labs' ? ($userProgress['completed_labs'] ?? []) : ($userProgress['completed_modules'] ?? []);
                if ($itemId !== '' && in_array($itemId, $doneList, true)) {
                    $tDone++;
                }
            }
        }
    ?>
        <a href="index.php?page=catalog&tab=<?= urlencode($activeTab) ?>&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>"
           class="track-filter-pill <?= $selectedTrack === (string)$tKey ? 'active' : '' ?>"
           style="--pill-accent: <?= Security::e($track['color'] ?? '#00B0F0') ?>;">
            <span class="track-filter-num" style="color: <?= Security::e($track['color'] ?? '#00B0F0') ?>;">
                <?= Security::e($track['number'] ?? 'Parcours') ?>
            </span>
            <span class="track-filter-name"><?= Security::e($track['short_title'] ?? $track['title']) ?></span>
            <span class="track-filter-count tabular-nums"><?= $tDone ?>/<?= count($tCodes) ?> validés</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($activeTab !== 'labs'): ?>
    <!-- AFFICHAGE DES MODULES REGROUPÉS PAR PARCOURS ET PAR JOURNÉES / ÉTAPES -->
    <?php foreach ($tracks as $tKey => $track):
        if ($selectedTrack !== 'all' && $selectedTrack !== (string)$tKey) {
            continue;
        }
        $tCodes = $track['module_Filter'] ?? [];
        $tTotal = count($tCodes);
        $tDone = 0;
        foreach ($tCodes as $c) {
            if (isset($modulesByCode[$c]) && in_array($modulesByCode[$c]['id'], $userProgress['completed_modules'] ?? [], true)) {
                $tDone++;
            }
        }
        $tPct = $tTotal > 0 ? (int)round(($tDone / $tTotal) * 100) : 0;
        $accent = (string)($track['color'] ?? '#00B0F0');
    ?>
        <section class="catalog-track-section" style="--track-accent: <?= Security::e($accent) ?>;">
            <div class="catalog-track-banner">
                <div class="catalog-track-banner-main">
                    <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.4rem;">
                        <span class="track-number-pill" style="background: <?= Security::e($accent) ?>;">
                            <?= Security::e($track['number'] ?? 'Parcours') ?>
                        </span>
                        <span class="badge-code"><?= Security::e($track['badge']) ?></span>
                        <?php if (!empty($track['target_audience'])): ?>
                            <span style="font-size: 0.78rem; color: var(--text-secondary);">
                                👥 <strong>Public :</strong> <?= Security::e($track['target_audience']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h2 class="catalog-track-heading"><?= Security::e($track['title']) ?></h2>
                    <p class="catalog-track-sub"><?= Security::e($track['description']) ?></p>
                    <?php if (!empty($track['prerequisites']) || !empty($track['objective'])): ?>
                        <div class="catalog-track-requisites">
                            <?php if (!empty($track['prerequisites'])): ?>
                                <span>🔑 <strong>Prérequis :</strong> <?= Security::e($track['prerequisites']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($track['objective'])): ?>
                                <span>🎯 <strong>Objectif opérationnel :</strong> <?= Security::e($track['objective']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="catalog-track-banner-progress">
                    <div class="tabular-nums" style="font-size: 1.25rem; font-weight: 800; color: var(--brand-cyan-light);">
                        <?= $tDone ?> / <?= $tTotal ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);">modules (<?= $tPct ?>%)</span>
                    </div>
                    <div class="progress-bar-track" style="margin: 0.45rem 0;">
                        <div class="progress-bar-fill" style="width: <?= $tPct ?>%;"></div>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Progression séquentielle étape par étape
                    </div>
                </div>
            </div>

            <?php
            $phases = !empty($track['phases']) ? $track['phases'] : [
                ['code' => 'CURSUS', 'title' => $track['title'], 'subtitle' => '', 'modules' => $tCodes],
            ];
            foreach ($phases as $phase):
            ?>
                <div class="catalog-phase-block">
                    <div class="catalog-phase-header">
                        <span class="catalog-phase-badge" style="border-color: <?= Security::e($accent) ?>; color: <?= Security::e($accent) ?>;">
                            <?= Security::e($phase['code']) ?>
                        </span>
                        <div style="min-width: 0;">
                            <h3 class="catalog-phase-title"><?= Security::e($phase['title']) ?></h3>
                            <?php if (!empty($phase['subtitle'])): ?>
                                <p class="catalog-phase-subtitle"><?= Security::e($phase['subtitle']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-grid">
                        <?php foreach (($phase['modules'] ?? []) as $modCode):
                            $mod = $modulesByCode[$modCode] ?? null;
                            if ($mod === null) {
                                continue;
                            }
                            $mId = (string)$mod['id'];
                            $isDone = in_array($mId, $userProgress['completed_modules'] ?? [], true);
                            $linkedLabId = (string)($mod['linked_lab_id'] ?? '');
                            $isLabDone = $linkedLabId !== '' && in_array($linkedLabId, $userProgress['completed_labs'] ?? [], true);
                            $quizScore = $userProgress['quiz_scores'][$mId] ?? null;
                        ?>
                            <article class="lms-card <?= $isDone ? 'is-completed' : '' ?>"
                                     data-searchable="<?= Security::e($mod['badge_code'] . ' ' . $mod['title'] . ' ' . $mod['bloom'] . ' ' . $mod['track_title'] . ' ' . ($phase['code'] ?? '')) ?>">
                                <div>
                                    <div class="card-top-meta">
                                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
                                            <span class="badge-phase tabular-nums">
                                                Étape <?= (int)$mod['track_position'] ?>/<?= (int)$mod['track_total'] ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                            <?php if ($quizScore !== null): ?>
                                                <span class="badge-code tabular-nums" style="background: rgba(56, 189, 248, 0.14);">Quiz <?= (int)$quizScore['percentage'] ?>%</span>
                                            <?php endif; ?>
                                            <?php if ($isDone): ?>
                                                <span class="badge-completed">✔ Validé</span>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: var(--text-muted);">⏱ <?= Security::e($mod['duration']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <h4 style="font-size: 1.04rem; margin: 0 0 0.4rem; line-height: 1.35;">
                                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="color: var(--text-primary);">
                                            <?= Security::e($mod['title']) ?>
                                        </a>
                                    </h4>

                                    <p style="font-size: 0.81rem; color: var(--text-secondary); margin-bottom: 0.65rem;">
                                        <strong>Compétence Bloom :</strong> <?= Security::e($mod['bloom']) ?>
                                    </p>

                                    <?php if ($linkedLabId !== ''): ?>
                                        <div class="coupled-lab-strip">
                                            <span>🧪 <strong>Atelier couplé :</strong> <?= Security::e($mod['linked_lab_title'] ?? ('Lab ' . $mod['code'])) ?></span>
                                            <?php if ($isLabDone): ?>
                                                <span class="badge-completed" style="font-size: 0.66rem;">✔ Lab Validé</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <div style="display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem;">
                                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm" style="flex: 1;">
                                            📖 Cours & Quiz
                                        </a>
                                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm" title="Suivre le cours sous forme de Diaporama 16:9">
                                            🖥️ Slides 16:9
                                        </a>
                                        <?php if ($linkedLabId !== ''): ?>
                                            <a href="index.php?page=lab&id=<?= urlencode($linkedLabId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm" title="Ouvrir l'Atelier Pratique Docker associé">
                                                🧪 Atelier
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? []) ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

<?php else: ?>
    <!-- GRILLE DES 34 ATELIERS PRATIQUES (LABS DOCKER) FILTRABLE PAR PARCOURS -->
    <div class="card-grid">
        <?php foreach ($allLabs as $labId => $lab):
            if ($selectedTrack !== 'all' && ($lab['track_id'] ?? '') !== $selectedTrack) {
                continue;
            }
            $isLabDone = in_array($labId, $userProgress['completed_labs'] ?? [], true);
        ?>
            <article class="lms-card <?= $isLabDone ? 'is-completed' : '' ?>"
                     data-searchable="<?= Security::e($lab['badge_code'] . ' ' . $lab['title'] . ' ' . $lab['bloom'] . ' ' . ($lab['track_title'] ?? '')) ?>">
                <div>
                    <div class="card-top-meta">
                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <span class="badge-code" style="background: rgba(16, 185, 129, 0.16); color: #34D399; border-color: rgba(16, 185, 129, 0.35);">
                                <?= Security::e($lab['badge_code']) ?>
                            </span>
                            <span class="badge-phase tabular-nums">
                                <?= Security::e($lab['track_number'] ?? 'Parcours') ?> • Étape <?= (int)($lab['track_position'] ?? 1) ?>/<?= (int)($lab['track_total'] ?? 20) ?>
                            </span>
                        </div>
                        <?php if ($isLabDone): ?>
                            <span class="badge-completed">✔ Oracle Validé</span>
                        <?php else: ?>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">⏱ <?= Security::e($lab['duration']) ?></span>
                        <?php endif; ?>
                    </div>

                    <h3 style="font-size: 1.04rem; margin-bottom: 0.4rem;">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="color: var(--text-primary);">
                            <?= Security::e($lab['title']) ?>
                        </a>
                    </h3>

                    <p style="font-size: 0.81rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                        <?= Security::e($lab['bloom']) ?>
                    </p>
                </div>

                <div>
                    <div style="display: flex; gap: 0.45rem; flex-wrap: wrap;">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm" style="flex: 1;">
                            🐳 Studio Lab & Console SQL
                        </a>
                        <?php if (!empty($lab['linked_module_id'])): ?>
                            <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                                📖 Cours
                            </a>
                            <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
                                🖥️ Slides
                            </a>
                        <?php endif; ?>
                    </div>

                    <?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
