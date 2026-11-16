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
$totalTracksCount = count($tracks);
$totalModulesCount = count($allModules);
$totalLabsCount = count($allLabs);

// Index modules by code ('01', '02', ..., 'sql_01') for structured track & phase rendering
$modulesByCode = [];
foreach ($allModules as $mId => $mObj) {
    $modulesByCode[$mObj['code']] = $mObj;
}
?>

<div class="d-flex justify-between items-end flex-wrap gap-1 mb-lg">
    <div class="flex-1 min-w-0">
        <span class="badge-code">CATALOGUE STRUCTURÉ PAR PARCOURS & JOURNÉES • QUALIOPI</span>
        <h1 class="hero-title-lg mt-xs mb-xxs">
            <?= $activeTab === 'labs'
                ? '🧪 Les ' . $totalLabsCount . ' Ateliers Pratiques & Labs Docker'
                : '📚 Les ' . $totalTracksCount . ' Parcours & ' . $totalModulesCount . ' Modules de Formation' ?>
        </h1>
        <p class="hero-subtitle-sm">
            Chaque étape couple un <strong>Module de Cours</strong> (lisible en mode texte interactif ou en <strong>Diaporama 16:9</strong>) et un <strong>Atelier Pratique Docker</strong>, avec téléchargement direct en <code>PDF</code>, <code>Slides PDF</code>, <code>PPTX</code>, <code>MD</code>, <code>HTML</code> et <code>DOCX</code>.
        </p>
    </div>

    <div class="d-flex gap-sm flex-wrap">
        <a href="index.php?page=catalog&tab=modules&track=<?= urlencode($selectedTrack) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab !== 'labs' ? 'btn-primary' : 'btn-outline' ?>">
            📚 Modules & Parcours (<?= $totalModulesCount ?>)
        </a>
        <a href="index.php?page=catalog&tab=labs&track=<?= urlencode($selectedTrack) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab === 'labs' ? 'btn-emerald' : 'btn-outline' ?>">
            🧪 Ateliers & TP Docker (<?= $totalLabsCount ?>)
        </a>
    </div>
</div>

<!-- Track filter strip -->
<div class="track-selector-strip">
    <a href="index.php?page=catalog&tab=<?= urlencode($activeTab) ?>&track=all&course=<?= urlencode($currentCourseSlug) ?>"
       class="track-filter-pill <?= $selectedTrack === 'all' ? 'active' : '' ?>">
        <span class="track-filter-num">Vue Globale</span>
        <span class="track-filter-name">Les <?= $totalTracksCount ?> Parcours Complets</span>
        <span class="track-filter-count tabular-nums"><?= $activeTab === 'labs' ? $totalLabsCount : $totalModulesCount ?> étapes</span>
    </a>
    <?php foreach ($tracks as $tKey => $track):
        $tCodes = $track['module_filter'] ?? $track['module_Filter'] ?? [];
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
           data-accent="<?= Security::e($track['color'] ?? '#00B0F0') ?>">
            <span class="track-filter-num">
                <?= Security::e($track['number'] ?? 'Parcours') ?>
            </span>
            <span class="track-filter-name"><?= Security::e($track['short_title'] ?? $track['title']) ?></span>
            <span class="track-filter-count tabular-nums"><?= $tDone ?>/<?= count($tCodes) ?> validés</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($activeTab !== 'labs'): ?>
    <!-- Modules grouped by track and learning phase -->
    <?php foreach ($tracks as $tKey => $track):
        if ($selectedTrack !== 'all' && $selectedTrack !== (string)$tKey) {
            continue;
        }
        $tCodes = $track['module_filter'] ?? $track['module_Filter'] ?? [];
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
        <section class="catalog-track-section" data-accent="<?= Security::e($accent) ?>">
            <div class="catalog-track-banner">
                <div class="catalog-track-banner-main">
                    <div class="d-flex items-center gap-sm flex-wrap mb-xs">
                        <span class="track-number-pill">
                            <?= Security::e($track['number'] ?? 'Parcours') ?>
                        </span>
                        <span class="badge-code"><?= Security::e($track['badge']) ?></span>
                        <?php if (!empty($track['target_audience'])): ?>
                            <span class="text-xs text-secondary">
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
                    <div class="tabular-nums section-heading-md font-extrabold text-cyan">
                        <?= $tDone ?> / <?= $tTotal ?> <span class="text-sm font-semibold text-secondary">modules (<?= $tPct ?>%)</span>
                    </div>
                    <div class="progress-bar-track progress-bar-track--my">
                        <div class="progress-bar-fill" data-progress="<?= $tPct ?>"></div>
                    </div>
                    <div class="text-xs text-muted">
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
                        <span class="catalog-phase-badge">
                            <?= Security::e($phase['code']) ?>
                        </span>
                        <div class="min-w-0">
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
                                        <div class="d-flex items-center gap-xs flex-wrap">
                                            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
                                            <span class="badge-phase tabular-nums">
                                                Étape <?= (int)$mod['track_position'] ?>/<?= (int)$mod['track_total'] ?>
                                            </span>
                                        </div>
                                        <div class="d-flex items-center gap-xs flex-wrap">
                                            <?php if ($quizScore !== null): ?>
                                                <span class="badge-code badge-cyan-soft tabular-nums">Quiz <?= (int)$quizScore['percentage'] ?>%</span>
                                            <?php endif; ?>
                                            <?php if ($isDone): ?>
                                                <span class="badge-completed">✔ Validé</span>
                                            <?php else: ?>
                                                <span class="text-xs text-muted">⏱ <?= Security::e($mod['duration']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <h4 class="card-heading-sm">
                                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="link-primary">
                                            <?= Security::e($mod['title']) ?>
                                        </a>
                                    </h4>

                                    <p class="card-desc-sm">
                                        <strong>Compétence Bloom :</strong> <?= Security::e($mod['bloom']) ?>
                                    </p>

                                    <?php if ($linkedLabId !== ''): ?>
                                        <div class="coupled-lab-strip">
                                            <span>🧪 <strong>Atelier couplé :</strong> <?= Security::e($mod['linked_lab_title'] ?? ('Lab ' . $mod['code'])) ?></span>
                                            <?php if ($isLabDone): ?>
                                                <span class="badge-completed text-2xs">✔ Lab Validé</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <div class="d-flex gap-xs flex-wrap mt-xs">
                                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm flex-1">
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
    <!-- Practice labs grid filterable by track -->
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
                        <div class="d-flex items-center gap-xs flex-wrap">
                            <span class="badge-code badge-emerald-soft">
                                <?= Security::e($lab['badge_code']) ?>
                            </span>
                            <span class="badge-phase tabular-nums">
                                <?= Security::e($lab['track_number'] ?? 'Parcours') ?> • Étape <?= (int)($lab['track_position'] ?? 1) ?>/<?= (int)($lab['track_total'] ?? 20) ?>
                            </span>
                        </div>
                        <?php if ($isLabDone): ?>
                            <span class="badge-completed">✔ Oracle Validé</span>
                        <?php else: ?>
                            <span class="text-xs text-muted">⏱ <?= Security::e($lab['duration']) ?></span>
                        <?php endif; ?>
                    </div>

                    <h3 class="card-heading-sm">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="link-primary">
                            <?= Security::e($lab['title']) ?>
                        </a>
                    </h3>

                    <p class="card-desc-sm">
                        <?= Security::e($lab['bloom']) ?>
                    </p>
                </div>

                <div>
                    <div class="d-flex gap-xs flex-wrap">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm flex-1">
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
