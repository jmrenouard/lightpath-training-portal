<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

$firstModViewId = (string)(array_key_first($currentCourse['modules'] ?? []) ?: 'module_01_introduction_postgresql_18');
$moduleId = (string)($_GET['id'] ?? $firstModViewId);
$mod = $courseRepo->getModule($currentCourseSlug, $moduleId);
if ($mod === null) {
    $firstKey = array_key_first($currentCourse['modules'] ?? []);
    $mod = $firstKey ? $currentCourse['modules'][$firstKey] : null;
}

if ($mod === null) {
    echo '<div class="flash-banner flash-error">Module introuvable.</div>';
    return;
}

$moduleId = (string)$mod['id'];
$parsed = $mdParser->parseModuleFile($mod['source_md_path'], $currentCourseSlug);
$slides = $parsed['slides'] ?? [];
$totalSlides = count($slides);
$isDone = in_array($moduleId, $userProgress['completed_modules'] ?? [], true);
$savedNote = (string)($userProgress['notes'][$moduleId] ?? '');
$quizScore = $userProgress['quiz_scores'][$moduleId] ?? null;
$isBookmarked = false;
foreach (($userProgress['bookmarks'] ?? []) as $bm) {
    if (($bm['type'] ?? '') === 'module' && ($bm['id'] ?? '') === $moduleId) {
        $isBookmarked = true;
        break;
    }
}
$discussions = $storage->getDiscussions($currentCourseSlug, $moduleId);

// Collect modules belonging to the same track for the position stepper
$trackId = (string)($mod['track_id'] ?? 'cursus-complet');
$trackModules = [];
foreach (($currentCourse['modules'] ?? []) as $mKey => $mItem) {
    if (($mItem['track_id'] ?? '') === $trackId) {
        $trackModules[$mKey] = $mItem;
    }
}
$trackDoneCount = 0;
foreach ($trackModules as $tmKey => $tmItem) {
    if (in_array($tmKey, $userProgress['completed_modules'] ?? [], true)) {
        $trackDoneCount++;
    }
}
$trackProgressPct = count($trackModules) > 0 ? (int)round(($trackDoneCount / count($trackModules)) * 100) : 0;

// Compute previous and next modules with their badge labels
$allCourseMods = $currentCourse['modules'] ?? [];
$modKeys = array_keys($allCourseMods);
$curIndex = array_search($moduleId, $modKeys, true);
$prevModId = ($curIndex !== false && $curIndex > 0) ? $modKeys[$curIndex - 1] : null;
$nextModId = ($curIndex !== false && $curIndex < count($modKeys) - 1) ? $modKeys[$curIndex + 1] : null;
$prevModBadge = $prevModId !== null ? (string)($allCourseMods[$prevModId]['badge_code'] ?? '') : '';
$nextModBadge = $nextModId !== null ? (string)($allCourseMods[$nextModId]['badge_code'] ?? '') : '';

$viewMode = (string)($_GET['mode'] ?? 'reader');
if (!in_array($viewMode, ['reader', 'slides', 'video', 'html_preview'], true)) {
    $viewMode = 'reader';
}
$hasVideo = !empty($mod['has_video']) || isset($mod['downloads']['video_mp4']) || isset($mod['downloads']['video_slides_mp4']) || isset($mod['downloads']['video_transcript']);
$videoVariant = (string)($_GET['video_src'] ?? 'course');
if (!in_array($videoVariant, ['course', 'slides'], true)) {
    $videoVariant = 'course';
}
$courseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$courseDockerEnabled = $courseHasDocker && (!isset($featureManager) || $featureManager->isEnabledForCourse('docker_labs', $currentCourseSlug));
?>

<!-- Clear module header, track position & quick actions -->
<div class="hero-banner p-hero-reader">
    <div class="flex-1 min-w-0">
        <!-- Breadcrumb & track position -->
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <a href="index.php?page=catalog&track=<?= urlencode($trackId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code" title="Voir tous les modules de ce parcours">
                ← <?= Security::e($mod['track_number'] ?? 'Parcours') ?> (<?= Security::e($mod['track_short_title'] ?? '') ?>)
            </a>
            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
            <span class="badge-phase tabular-nums">
                <?= Security::e($mod['phase_code'] ?? 'ÉTAPE') ?> • 📍 Étape <?= (int)($mod['track_position'] ?? 1) ?> / <?= (int)($mod['track_total'] ?? 20) ?>
            </span>
            <span class="text-xs text-secondary">⏱ <?= Security::e($mod['duration']) ?></span>
        </div>

        <h1 class="section-heading-lg mb-xxs"><?= Security::e($mod['title']) ?></h1>
        <p class="text-secondary mb-sm text-sm">
            <strong>Compétence Opérationnelle :</strong> <?= Security::e($mod['bloom']) ?>
        </p>

        <!-- Direct multi-format downloads -->
        <div class="d-flex items-center gap-xs flex-wrap">
            <span class="text-xxs font-bold text-uppercase text-muted">📥 Supports :</span>
            <?= renderDownloadStrip($currentCourseSlug, 'module', $moduleId, $mod['downloads'] ?? [], true) ?>
        </div>
    </div>

    <div class="hero-actions-col">
        <button type="button"
                class="btn <?= $isDone ? 'btn-emerald' : 'btn-primary' ?> btn-sm btn-toggle-complete"
                data-type="module"
                data-id="<?= Security::e($moduleId) ?>"
                data-course="<?= Security::e($currentCourseSlug) ?>"
                data-completed="<?= $isDone ? '1' : '0' ?>">
            <?= $isDone ? '✔ Module Terminé (Annuler)' : '✅ Marquer comme Terminé' ?>
        </button>

        <?php if (!empty($mod['linked_lab_id'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                🧪 Atelier Pratique (Lab <?= Security::e($mod['code']) ?>) →
            </a>
        <?php endif; ?>

        <?php if (!empty($mod['linked_cheatsheet_id'])):
            $modCsId = (string)$mod['linked_cheatsheet_id'];
            $modCsDownloads = $currentCourse['cheatsheets'][$modCsId]['downloads'] ?? [];
            $modCsFormat = isset($modCsDownloads['pdf']) ? 'pdf' : (isset($modCsDownloads['md']) ? 'md' : (string)(array_key_first($modCsDownloads) ?: 'md'));
        ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=cheatsheet&id=<?= urlencode($modCsId) ?>&format=<?= urlencode($modCsFormat) ?>" class="btn btn-outline btn-sm">
                📑 Fiche Mémo <?= Security::e(strtoupper($modCsFormat)) ?>
            </a>
        <?php endif; ?>
        <button type="button"
                class="btn btn-outline btn-sm btn-toggle-bookmark"
                data-type="module"
                data-id="<?= Security::e($moduleId) ?>"
                data-title="<?= Security::e($mod['badge_code'] . ' — ' . $mod['title']) ?>">
            <?= $isBookmarked ? '⭐ Favori' : '☆ Ajouter aux Favoris' ?>
        </button>
    </div>
</div>

<!-- Study mode switcher & Prev/Next module navigation -->
<div class="view-mode-switcher">
    <div class="view-mode-tabs">
        <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=reader&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $viewMode === 'reader' ? 'active' : '' ?>">
            <span>📖 Cours Interactif & Quiz QCM</span>
        </a>
        <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $viewMode === 'slides' ? 'active' : '' ?>">
            <span>🖥️ Diaporama Studio 16:9</span>
            <span class="tab-pill tabular-nums"><?= $totalSlides ?> slides</span>
        </a>
        <?php if ($hasVideo): ?>
            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=video&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $viewMode === 'video' ? 'active' : '' ?>">
                <span>🎬 Vidéo Studio & Transcription</span>
                <?php if (isset($mod['downloads']['video_vtt'])): ?>
                    <span class="tab-pill">CC VTT</span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <?php if (isset($mod['downloads']['html'])): ?>
            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $viewMode === 'html_preview' ? 'active' : '' ?>">
                <span>🌐 Rendu Web Studio</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-xs flex-wrap items-center">
        <button type="button"
                class="btn btn-outline btn-sm btn-toggle-right-panel"
                title="Réduire / Afficher la barre verticale de droite (Alt+D)">
            ◨ Menu Droite
        </button>
        <?php if ($prevModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($prevModId) ?>&mode=<?= urlencode($viewMode) ?>&course=<?= urlencode($currentCourseSlug) ?>"
               class="btn btn-outline btn-sm"
               title="<?= Security::e($allCourseMods[$prevModId]['title'] ?? '') ?>">
                ◀ <?= Security::e($prevModBadge) ?> Précédent
            </a>
        <?php endif; ?>
        <?php if ($nextModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($nextModId) ?>&mode=<?= urlencode($viewMode) ?>&course=<?= urlencode($currentCourseSlug) ?>"
               class="btn btn-primary btn-sm"
               title="<?= Security::e($allCourseMods[$nextModId]['title'] ?? '') ?>">
                Suivant <?= Security::e($nextModBadge) ?> ▶
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewMode === 'slides'): ?>
    <!-- ======================================================================
         OFFICIAL 16:9 STUDIO SLIDE DECK MODE
         ====================================================================== -->
    <div id="lms-slide-deck-wrapper" class="slide-deck-wrapper">
        <!-- Slide deck top toolbar -->
        <div class="slide-deck-toolbar">
            <div class="d-flex items-center gap-xs flex-wrap">
                <button type="button" id="btn-slide-engine-html" class="btn btn-sm btn-primary" aria-pressed="true">
                    🖥️ Diaporama Studio 16:9 (<?= $totalSlides ?> slides)
                </button>
                <?php if (isset($mod['downloads']['slides_pdf'])): ?>
                    <button type="button" id="btn-slide-engine-pdf" class="btn btn-sm btn-outline" aria-pressed="false">
                        📄 Lecteur PDF Continu (<?= $totalSlides ?> pages)
                    </button>
                <?php endif; ?>
            </div>

            <div id="slide-html-controls" class="slide-controls-group">
                <button type="button" id="btn-slide-prev" class="btn btn-outline btn-sm" title="Diapositive précédente (Flèche Gauche)">
                    ◀ Préc.
                </button>

                <select id="slide-jump-select" class="slide-jump-select tabular-nums" aria-label="Choisir une diapositive">
                    <?php foreach ($slides as $s): ?>
                        <option value="<?= (int)$s['index'] - 1 ?>">
                            Slide <?= sprintf('%02d', (int)$s['index']) ?>/<?= sprintf('%02d', $totalSlides) ?> — <?= Security::e(CourseRepository::truncateUtf8(str_replace(['`', '**'], '', (string)$s['title']), 56)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="button" id="btn-slide-next" class="btn btn-primary btn-sm" title="Diapositive suivante (Flèche Droite / Espace)">
                    Suiv. ▶
                </button>

                <button type="button" id="btn-slide-fullscreen" class="btn btn-outline btn-sm" title="Basculer en Plein Écran (Touche F)">
                    ⛶ Plein Écran
                </button>
            </div>
        </div>

        <!-- Slide deck progress bar -->
        <div class="slide-progress-track">
            <div id="slide-progress-fill" class="slide-progress-fill" data-progress="<?= $totalSlides > 0 ? round(100 / $totalSlides, 1) : 100 ?>"></div>
        </div>

        <!-- 16:9 main stage (Column 1) + Slide outline sidebar (Column 2) -->
        <div class="slide-deck-layout">
            <div class="slide-stage-main-col">
                <!-- Interactive 16:9 studio stage -->
                <div id="slide-stage-html" class="slide-stage-container">
                    <?php foreach ($slides as $idx => $s):
                        $hasStudioImg = !empty($s['image_url']);
                        $slideNotes = trim((string)($s['notes'] ?? ''));
                        $slidePreview = trim((string)($s['body_preview'] ?? ''));
                    ?>
                        <section class="lms-slide-frame <?= $hasStudioImg ? 'is-studio-image-slide' : '' ?> <?= $idx === 0 ? 'active' : '' ?>"
                                 data-slide-index="<?= $idx ?>"
                                 data-slide-number="<?= (int)$s['index'] ?>"
                                 data-slide-title="<?= Security::e($s['title']) ?>"
                                 data-slide-img="<?= Security::e((string)($s['image_url'] ?? '')) ?>">
                            <?php if ($hasStudioImg): ?>
                                <div class="lms-studio-canvas-wrap">
                                    <button type="button" class="slide-canvas-nav slide-canvas-prev" title="Diapositive précédente (Flèche Gauche)" aria-label="Diapositive précédente">‹</button>
                                    <img src="<?= Security::e($s['image_url']) ?>"
                                         data-slide-src="<?= Security::e($s['image_url']) ?>"
                                         alt="Diapositive <?= (int)$s['index'] ?> / <?= $totalSlides ?> — <?= Security::e($s['title']) ?>"
                                         class="lms-studio-slide-img lms-zoomable-img"
                                         loading="<?= $idx <= 2 ? 'eager' : 'lazy' ?>"
                                         decoding="async"/>
                                    <button type="button" class="slide-canvas-nav slide-canvas-next" title="Diapositive suivante (Flèche Droite)" aria-label="Diapositive suivante">›</button>
                                </div>
                                <footer class="lms-studio-caption-bar">
                                    <div class="lms-studio-caption-main">
                                        <span class="lms-slide-badge"><?= Security::formatInlineText($s['badge']) ?></span>
                                        <strong class="lms-studio-caption-title"><?= Security::formatInlineText($s['title']) ?></strong>
                                        <span class="lms-slide-counter tabular-nums ml-auto">
                                            DIAPO <?= sprintf('%02d', (int)$s['index']) ?> / <?= sprintf('%02d', $totalSlides) ?>
                                        </span>
                                    </div>
                                    <?php if ($slideNotes !== '' || $slidePreview !== ''): ?>
                                        <div class="lms-studio-speaker-notes">
                                            <strong>🎙️ Notes & Points Clés :</strong>
                                            <?= Security::formatInlineText($slideNotes !== '' ? $slideNotes : $slidePreview) ?>
                                        </div>
                                    <?php endif; ?>
                                </footer>
                            <?php else: ?>
                                <header class="lms-slide-header">
                                    <div class="d-flex items-center gap-xs min-w-0">
                                        <?php $slideHeaderIcon = $currentCourseSlug === 'postgresql-18' ? 'assets/img/postgresql_elephant_dark.png' : ltrim((string)($currentCourse['icon'] ?? 'assets/img/postgresql_elephant_dark.png'), '/'); ?>
                                        <img src="<?= Security::e($slideHeaderIcon) ?>" alt="<?= Security::e((string)($currentCourse['short_title'] ?? 'Course')) ?>" class="slide-header-icon"/>
                                        <span class="lms-slide-badge"><?= Security::formatInlineText($s['badge']) ?></span>
                                        <span class="lms-slide-chapter"><?= Security::formatInlineText($s['chapter']) ?></span>
                                    </div>
                                    <div class="lms-slide-counter tabular-nums">
                                        DIAPO <?= sprintf('%02d', (int)$s['index']) ?> / <?= sprintf('%02d', $totalSlides) ?>
                                    </div>
                                </header>

                                <div class="lms-slide-body">
                                    <h2 class="lms-slide-title"><?= Security::formatInlineText($s['title']) ?></h2>
                                    <div class="lms-slide-content markdown-body">
                                        <?= $s['html'] ?>
                                    </div>
                                </div>

                                <footer class="lms-slide-footer">
                                    <span>LightPath SARL • <?= Security::e((string)($currentCourse['badge'] ?? 'LightPath LMS')) ?> — <?= Security::e((string)($appConfig['trainer_name'] ?? 'Jean-Marie Renouard')) ?></span>
                                    <span class="tabular-nums"><?= Security::e($mod['badge_code']) ?> • Étape <?= (int)($mod['track_position'] ?? 1) ?>/<?= (int)($mod['track_total'] ?? 20) ?></span>
                                </footer>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <!-- Official 16:9 PowerPoint PDF stage rendered in Column 1 -->
                <?php if (isset($mod['downloads']['slides_pdf'])): ?>
                    <div id="slide-stage-pdf" class="slide-stage-pdf" hidden>
                        <iframe id="slide-pdf-iframe"
                                src="about:blank"
                                data-src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=slides_pdf&inline=1"
                                allow="unload *"
                                class="slide-pdf-iframe"
                                title="Diaporama PowerPoint PDF 16:9"></iframe>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right sidebar (Column 2): Slide outline (sticky & scrollbar-free) -->
            <aside class="slide-outline-sidebar" id="app-right-sidebar-slides">
                <div class="toc-box slide-outline-box">
                    <div class="d-flex justify-between items-center gap-xs mb-sm">
                        <span class="sidebar-section-label">
                            🖥️ Plan des <?= $totalSlides ?> Diapositives
                        </span>
                        <div class="d-flex items-center gap-xs">
                            <span id="slide-sidebar-counter" class="badge-code tabular-nums">1 / <?= $totalSlides ?></span>
                            <button type="button"
                                    class="btn-sidebar-collapse-inline btn-toggle-right-panel"
                                    title="Réduire la barre verticale de droite (Alt+D)"
                                    aria-label="Réduire la barre verticale de droite">
                                ▸
                            </button>
                        </div>
                    </div>
                    <div id="slide-outline-list" class="slide-outline-list">
                        <?php foreach ($slides as $idx => $s): ?>
                            <button type="button"
                                    class="slide-outline-item <?= $idx === 0 ? 'active' : '' ?>"
                                    data-slide-jump="<?= $idx ?>"
                                    title="Diapositive <?= sprintf('%02d', (int)$s['index']) ?> — <?= Security::e($s['title']) ?>">
                                <span class="slide-outline-num tabular-nums"><?= sprintf('%02d', (int)$s['index']) ?></span>
                                <span class="slide-outline-text"><?= Security::formatInlineText($s['title']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>
    </div>

<?php elseif ($viewMode === 'video' && $hasVideo): ?>
    <?php
    $activeVideoFormat = ($videoVariant === 'slides' && isset($mod['downloads']['video_slides_mp4'])) ? 'video_slides_mp4' : 'video_mp4';
    $videoStreamUrl = sprintf(
        'index.php?action=download&course=%s&type=module&id=%s&format=%s&inline=1',
        urlencode($currentCourseSlug),
        urlencode($moduleId),
        urlencode($activeVideoFormat)
    );
    $vttUrl = isset($mod['downloads']['video_vtt'])
        ? sprintf('index.php?action=download&course=%s&type=module&id=%s&format=video_vtt&inline=1', urlencode($currentCourseSlug), urlencode($moduleId))
        : '';
    $transcriptHtml = '';
    $transcriptPath = $mod['video_transcript_path']
        ?? ($mod['transcript_md_path']
        ?? ($mod['downloads']['video_transcript']['path'] ?? null));
    if (!empty($transcriptPath) && is_file((string)$transcriptPath)) {
        $parsedTranscript = $mdParser->parseModuleFile((string)$transcriptPath, $currentCourseSlug);
        $transcriptHtml = (string)($parsedTranscript['html'] ?? '');
    }
    ?>
    <div class="reader-grid">
        <div class="reader-main-col">
            <section class="lms-card p-md mb-lg">
                <div class="d-flex justify-between items-center flex-wrap gap-sm mb-sm">
                    <div>
                        <span class="badge-code">🎬 LECTEUR VIDÉO STUDIO 1080P & SOUS-TITRES WEBVTT</span>
                        <h2 class="section-heading-md mt-xs mb-0"><?= Security::e($mod['title']) ?></h2>
                    </div>
                    <div class="d-flex gap-xs flex-wrap items-center">
                        <?php if (isset($mod['downloads']['video_mp4']) && isset($mod['downloads']['video_slides_mp4'])): ?>
                            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=video&video_src=course&course=<?= urlencode($currentCourseSlug) ?>"
                               class="btn btn-sm <?= $videoVariant === 'course' ? 'btn-primary' : 'btn-outline' ?>">
                                🎬 Cours Interactif MP4
                            </a>
                            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=video&video_src=slides&course=<?= urlencode($currentCourseSlug) ?>"
                               class="btn btn-sm <?= $videoVariant === 'slides' ? 'btn-primary' : 'btn-outline' ?>">
                                🎞️ Présentation Slides MP4
                            </a>
                        <?php endif; ?>
                        <div class="d-inline-flex gap-xxs items-center" aria-label="Vitesse de lecture">
                            <?php foreach ([1, 1.25, 1.5, 2] as $spd): ?>
                                <button type="button"
                                        class="btn btn-outline btn-sm btn-video-speed tabular-nums"
                                        data-speed="<?= $spd ?>">
                                    <?= $spd ?>x
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php if (isset($mod['downloads'][$activeVideoFormat])): ?>
                    <div class="video-player-wrap">
                        <video id="lms-module-video"
                               controls
                               preload="metadata"
                               class="video-player-el">
                            <source src="<?= Security::e($videoStreamUrl) ?>" type="video/mp4"/>
                            <?php if ($vttUrl !== ''): ?>
                                <track kind="subtitles" srclang="fr" label="Français (Studio)" src="<?= Security::e($vttUrl) ?>" default/>
                            <?php endif; ?>
                            Votre navigateur ne supporte pas la lecture vidéo HTML5.
                        </video>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-between items-center flex-wrap gap-xs mt-sm text-sm text-secondary">
                    <span>💡 Astuce : Activez les sous-titres synchronisés (CC) dans le lecteur ou consultez la transcription intégrale ci-dessous.</span>
                    <div class="d-flex gap-xs">
                        <?php if (isset($mod['downloads']['video_mp4'])): ?>
                            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=video_mp4" class="btn btn-outline btn-sm">
                                ⬇ Télécharger MP4 (<?= Security::e($mod['downloads']['video_mp4']['size_formatted'] ?? '') ?>)
                            </a>
                        <?php endif; ?>
                        <?php if (isset($mod['downloads']['video_vtt'])): ?>
                            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=video_vtt" class="btn btn-outline btn-sm">
                                ⬇ Sous-titres .VTT
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if ($transcriptHtml !== ''): ?>
                <section class="lms-card p-card-resume">
                    <span class="badge-code">📜 TRANSCRIPTION INTÉGRALE & ACCESSIBILITÉ (WCAG / QUALIOPI)</span>
                    <h3 class="section-heading-sm mt-xs mb-1">Transcription Textuelle Synchronisée du Module</h3>
                    <div class="markdown-body">
                        <?= $transcriptHtml ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="reader-sidebar" id="app-right-sidebar">
            <div class="toc-box">
                <div class="sidebar-section-label mb-xs">
                    📝 Mes Notes Vidéo
                </div>
                <textarea id="user-personal-note" class="form-control" rows="6" placeholder="Prenez vos notes pendant le visionnage vidéo..."><?= Security::e($savedNote) ?></textarea>
                <div class="d-flex justify-between items-center mt-xs">
                    <span id="note-save-status" class="text-xs text-emerald"></span>
                    <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        💾 Enregistrer
                    </button>
                </div>
            </div>
        </aside>
    </div>

<?php elseif ($viewMode === 'html_preview' && isset($mod['downloads']['html'])): ?>
    <div class="preview-iframe-wrap">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=html&inline=1"
                allow="unload *"
                class="preview-iframe-full"
                title="<?= Security::e($mod['title']) ?>"></iframe>
    </div>

<?php else: ?>
    <!-- ======================================================================
         INTERACTIVE READER GRID + STICKY SCROLLSPY TABLE OF CONTENTS
         ====================================================================== -->
    <div class="reader-grid">
        <div class="reader-main-col">
            <article id="reader-article-content" class="reader-article markdown-body">
                <?= $parsed['html'] ?>
            </article>

            <!-- Self-graded interactive QCM quiz -->
            <?php if (!empty($parsed['quiz'])): ?>
                <section id="quiz-section" class="quiz-card">
                    <div class="d-flex justify-between items-center flex-wrap gap-sm mb-1">
                        <div>
                            <span class="badge-code">ÉVALUATION FORMATIVE QUALIOPI</span>
                            <h2 class="text-xl mt-xs mb-0">❓ Quiz Interactif d'Auto-Évaluation (<?= count($parsed['quiz']) ?> questions)</h2>
                        </div>
                        <?php if ($quizScore !== null): ?>
                            <span class="badge-completed tabular-nums badge-lg">
                                Dernier score : <?= (int)$quizScore['score'] ?>/<?= (int)$quizScore['total'] ?> (<?= (int)$quizScore['percentage'] ?>%)
                            </span>
                        <?php endif; ?>
                    </div>

                    <form id="interactive-quiz-form" data-module-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        <?php foreach ($parsed['quiz'] as $q): ?>
                            <div class="quiz-question-box" data-correct="<?= Security::e($q['correct']) ?>">
                                <div class="font-bold mb-sm text-primary">
                                    <?= Security::formatInlineText($q['question']) ?>
                                </div>
                                <?php foreach ($q['options'] as $letter => $optText): ?>
                                    <label class="quiz-option-label">
                                        <input type="radio" name="q_<?= (int)$q['index'] ?>" value="<?= Security::e($letter) ?>" required class="quiz-option-radio"/>
                                        <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::formatInlineText($optText) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="quiz-feedback">
                                    <strong>✔ Réponse attendue : <?= Security::e($q['correct']) ?></strong><br/>
                                    <?= Security::formatInlineText($q['explanation']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div id="quiz-result-banner" class="is-hidden"></div>

                        <button type="submit" class="btn btn-primary btn-block-md">
                            🎯 Valider mes Réponses & Enregistrer mon Score Qualiopi
                        </button>
                    </form>
                </section>
            <?php endif; ?>

            <!-- Bottom module navigation card (Previous / Practical Lab / Next) -->
            <div class="module-bottom-nav-card">
                <div>
                    <?php if ($prevModId): ?>
                        <a href="index.php?page=module&id=<?= urlencode($prevModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                            ◀ <?= Security::e($prevModBadge) ?> — Module Précédent
                        </a>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-sm flex-wrap">
                    <?php if (!empty($mod['linked_lab_id'])): ?>
                        <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                            <?= $courseDockerEnabled ? '🧪 Passer au Lab Pratique Docker' : '🧪 Passer à l\'Atelier Pratique' ?> (Lab <?= Security::e($mod['code']) ?>)
                        </a>
                    <?php endif; ?>
                    <?php if ($nextModId): ?>
                        <a href="index.php?page=module&id=<?= urlencode($nextModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                            Module Suivant (<?= Security::e($nextModBadge) ?>) ▶
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Technical Q&A forum for this module -->
            <?= renderDiscussionSection($currentCourseSlug, 'module', $moduleId, $discussions, $csrfToken, $auth->isStaff($currentUser)) ?>
        </div>

        <!-- Clean right sidebar: ScrollSpy TOC + personal notes + collapsible track stepper -->
        <aside class="reader-sidebar" id="app-right-sidebar">
            <!-- 1. Document outline with live ScrollSpy position -->
            <div class="toc-box">
                <div class="d-flex justify-between items-center gap-xs mb-xs">
                    <span class="sidebar-section-label">
                        📑 Sommaire & Position
                    </span>
                    <div class="d-flex items-center gap-xs">
                        <span id="toc-read-pct" class="badge-code tabular-nums" title="Progression de lecture dans ce document">0% lu</span>
                        <button type="button"
                                class="btn-sidebar-collapse-inline btn-toggle-right-panel"
                                title="Réduire la barre verticale de droite (Alt+D)"
                                aria-label="Réduire la barre verticale de droite">
                            ▸
                        </button>
                    </div>
                </div>
                <div class="progress-bar-track progress-bar-track--xs mb-sm">
                    <div id="toc-read-bar" class="progress-bar-fill" data-progress="0"></div>
                </div>

                <nav id="toc-scrollspy-nav" class="toc-scrollspy-nav">
                    <?php foreach ($parsed['toc'] as $tIdx => $tocItem): ?>
                        <a href="#<?= Security::e($tocItem['id']) ?>"
                           class="toc-link toc-level-<?= (int)$tocItem['level'] ?> <?= $tIdx === 0 ? 'active' : '' ?>"
                           data-scrollspy-target="<?= Security::e($tocItem['id']) ?>">
                            <?= Security::e($tocItem['title']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!empty($parsed['quiz'])): ?>
                        <a href="#quiz-section"
                           class="toc-link toc-quiz-link"
                           data-scrollspy-target="quiz-section">
                            ❓ Quiz QCM Interactif (<?= count($parsed['quiz']) ?> questions)
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- 2. Learner personal notes block -->
            <div class="toc-box">
                <div class="sidebar-section-label mb-xs">
                    📝 Mes Notes Personnelles
                </div>
                <textarea id="user-personal-note" class="form-control" rows="4" aria-label="Mes notes personnelles sur ce module" placeholder="<?= $currentCourseSlug === 'postgresql-18' ? 'Notez vos points clés, paramètres postgresql.conf ou questions...' : 'Notez vos points clés, commandes et retours d\'expérience...' ?>"><?= Security::e($savedNote) ?></textarea>
                <div class="d-flex justify-between items-center mt-xs">
                    <span id="note-save-status" class="text-xs text-emerald"></span>
                    <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        💾 Enregistrer
                    </button>
                </div>
            </div>

            <!-- 3. Collapsible curriculum track progress stepper -->
            <details class="toc-box">
                <summary class="summary-reset cursor-pointer">
                    <span class="sidebar-section-label">
                        📍 Étape <?= (int)($mod['track_position'] ?? 1) ?> / <?= (int)($mod['track_total'] ?? count($trackModules)) ?> du Parcours
                    </span>
                    <span class="badge-code tabular-nums"><?= $trackProgressPct ?>%</span>
                </summary>
                <div class="mt-sm">
                    <div class="progress-bar-track progress-bar-track--xs mb-sm">
                        <div class="progress-bar-fill" data-progress="<?= (int)$trackProgressPct ?>"></div>
                    </div>
                    <div class="curriculum-stepper-list">
                        <?php foreach ($trackModules as $tmKey => $tmItem):
                            $tmCurrent = ($tmKey === $moduleId);
                            $tmDone = in_array($tmKey, $userProgress['completed_modules'] ?? [], true);
                        ?>
                            <a href="index.php?page=module&id=<?= urlencode($tmKey) ?>&course=<?= urlencode($currentCourseSlug) ?>"
                               class="stepper-item <?= $tmCurrent ? 'is-current' : '' ?> <?= $tmDone ? 'is-done' : '' ?>">
                                <span class="stepper-indicator tabular-nums">
                                    <?= $tmDone ? '✔' : (int)$tmItem['track_position'] ?>
                                </span>
                                <span class="stepper-label">
                                    <strong><?= Security::e($tmItem['badge_code']) ?></strong> — <?= Security::e(CourseRepository::truncateUtf8($tmItem['title'], 42)) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        </aside>
    </div>
<?php endif; ?>
