<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

$moduleId = (string)($_GET['id'] ?? 'module_01_introduction_postgresql_18');
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

// Calculer les modules du même parcours pour le Stepper de Position
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

// Calculer Module Précédent / Suivant avec leurs badges (ex: M01 / M02)
$allCourseMods = $currentCourse['modules'] ?? [];
$modKeys = array_keys($allCourseMods);
$curIndex = array_search($moduleId, $modKeys, true);
$prevModId = ($curIndex !== false && $curIndex > 0) ? $modKeys[$curIndex - 1] : null;
$nextModId = ($curIndex !== false && $curIndex < count($modKeys) - 1) ? $modKeys[$curIndex + 1] : null;
$prevModBadge = $prevModId !== null ? (string)($allCourseMods[$prevModId]['badge_code'] ?? '') : '';
$nextModBadge = $nextModId !== null ? (string)($allCourseMods[$nextModId]['badge_code'] ?? '') : '';

$viewMode = (string)($_GET['mode'] ?? 'reader');
if (!in_array($viewMode, ['reader', 'slides', 'html_preview'], true)) {
    $viewMode = 'reader';
}
?>

<!-- EN-TÊTE CLAIR DU MODULE, POSITION DANS LE PARCOURS & ACTIONS RAPIDES -->
<div class="hero-banner" style="padding: 1.25rem 1.65rem;">
    <div style="flex: 1; min-width: 0;">
        <!-- Fil d'Ariane & Position dans le Parcours -->
        <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
            <a href="index.php?page=catalog&track=<?= urlencode($trackId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code" title="Voir tous les modules de ce parcours">
                ← <?= Security::e($mod['track_number'] ?? 'Parcours') ?> (<?= Security::e($mod['track_short_title'] ?? '') ?>)
            </a>
            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
            <span class="badge-phase tabular-nums">
                <?= Security::e($mod['phase_code'] ?? 'ÉTAPE') ?> • 📍 Étape <?= (int)($mod['track_position'] ?? 1) ?> / <?= (int)($mod['track_total'] ?? 20) ?>
            </span>
            <span style="font-size: 0.78rem; color: var(--text-secondary);">⏱ <?= Security::e($mod['duration']) ?></span>
        </div>

        <h1 style="font-size: 1.42rem; margin-bottom: 0.3rem;"><?= Security::e($mod['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0 0 0.65rem; font-size: 0.85rem;">
            <strong>Compétence Opérationnelle :</strong> <?= Security::e($mod['bloom']) ?>
        </p>

        <!-- Liens de téléchargement directs tous formats -->
        <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
            <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">📥 Supports :</span>
            <?= renderDownloadStrip($currentCourseSlug, 'module', $moduleId, $mod['downloads'] ?? [], true) ?>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.45rem; min-width: 235px;">
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

        <?php if (!empty($mod['linked_cheatsheet_id'])): ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=cheatsheet&id=<?= urlencode((string)$mod['linked_cheatsheet_id']) ?>&format=pdf" class="btn btn-outline btn-sm">
                📑 Fiche Mémo PDF
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

<!-- BARRE DE BASCULE DES 3 MODES D'ÉTUDE + NAVIGATION MODULE PRÉCÉDENT / SUIVANT -->
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
        <?php if (isset($mod['downloads']['html'])): ?>
            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $viewMode === 'html_preview' ? 'active' : '' ?>">
                <span>🌐 Rendu Web Studio</span>
            </a>
        <?php endif; ?>
    </div>

    <div style="display: flex; gap: 0.45rem; flex-wrap: wrap; align-items: center;">
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
         MODE DIAPORAMA OFFICIEL STUDIO 16:9 (58 SLIDES POUR M01, 1 686 TOTAL)
         ====================================================================== -->
    <div id="lms-slide-deck-wrapper" class="slide-deck-wrapper">
        <!-- Barre d'outils supérieure du lecteur de diapositives -->
        <div class="slide-deck-toolbar">
            <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap;">
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

        <!-- Barre de progression du diaporama -->
        <div class="slide-progress-track">
            <div id="slide-progress-fill" class="slide-progress-fill" style="width: <?= $totalSlides > 0 ? round(100 / $totalSlides, 1) : 100 ?>%;"></div>
        </div>

        <!-- Scène principale 16:9 (Colonne 1) + Sommaire latéral des diapositives (Colonne 2) -->
        <div class="slide-deck-layout">
            <div class="slide-stage-main-col">
                <!-- Scène 16:9 Studio Interactive -->
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
                                        <span class="lms-slide-counter tabular-nums" style="margin-left: auto;">
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
                                    <div style="display: flex; align-items: center; gap: 0.6rem; min-width: 0;">
                                        <img src="assets/img/postgresql_elephant_dark.png" alt="PG18" style="width: 24px; height: 24px;"/>
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
                                    <span>LightPath SARL • PostgreSQL 18 — Jean-Marie Renouard</span>
                                    <span class="tabular-nums"><?= Security::e($mod['badge_code']) ?> • Étape <?= (int)($mod['track_position'] ?? 1) ?>/<?= (int)($mod['track_total'] ?? 20) ?></span>
                                </footer>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <!-- Scène 16:9 PDF PowerPoint Officiel (_slides.pdf) affichée dans la zone principale (Colonne 1) -->
                <?php if (isset($mod['downloads']['slides_pdf'])): ?>
                    <div id="slide-stage-pdf" class="slide-stage-pdf" hidden>
                        <iframe id="slide-pdf-iframe"
                                src="about:blank"
                                data-src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=slides_pdf&inline=1"
                                allow="unload *"
                                style="width: 100%; height: 78vh; border: none; border-radius: var(--radius-md);"
                                title="Diaporama PowerPoint PDF 16:9"></iframe>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Colonne latérale droite (Colonne 2) : Liste des Diapositives (Sticky & Sans barre de défilement) -->
            <aside class="slide-outline-sidebar" id="app-right-sidebar-slides">
                <div class="toc-box slide-outline-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.4rem; margin-bottom: 0.65rem;">
                        <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                            🖥️ Plan des <?= $totalSlides ?> Diapositives
                        </span>
                        <div style="display: flex; align-items: center; gap: 0.35rem;">
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

<?php elseif ($viewMode === 'html_preview' && isset($mod['downloads']['html'])): ?>
    <div style="background: #FFFFFF; border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-strong); height: 82vh;">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=html&inline=1"
                allow="unload *"
                style="width: 100%; height: 100%; border: none;"
                title="<?= Security::e($mod['title']) ?>"></iframe>
    </div>

<?php else: ?>
    <!-- ======================================================================
         GRILLE LECTEUR INTERACTIF + SOMMAIRE SCROLLSPY STICKY
         ====================================================================== -->
    <div class="reader-grid">
        <div class="reader-main-col">
            <article id="reader-article-content" class="reader-article markdown-body">
                <?= $parsed['html'] ?>
            </article>

            <!-- COMPOSANT QUIZ QCM INTERACTIF AUTO-CORRIGÉ -->
            <?php if (!empty($parsed['quiz'])): ?>
                <section id="quiz-section" class="quiz-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
                        <div>
                            <span class="badge-code">ÉVALUATION FORMATIVE QUALIOPI</span>
                            <h2 style="font-size: 1.35rem; margin: 0.35rem 0 0;">❓ Quiz Interactif d'Auto-Évaluation (<?= count($parsed['quiz']) ?> questions)</h2>
                        </div>
                        <?php if ($quizScore !== null): ?>
                            <span class="badge-completed tabular-nums" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                                Dernier score : <?= (int)$quizScore['score'] ?>/<?= (int)$quizScore['total'] ?> (<?= (int)$quizScore['percentage'] ?>%)
                            </span>
                        <?php endif; ?>
                    </div>

                    <form id="interactive-quiz-form" data-module-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        <?php foreach ($parsed['quiz'] as $q): ?>
                            <div class="quiz-question-box" data-correct="<?= Security::e($q['correct']) ?>">
                                <div style="font-weight: 700; margin-bottom: 0.65rem; color: var(--text-primary);">
                                    <?= Security::formatInlineText($q['question']) ?>
                                </div>
                                <?php foreach ($q['options'] as $letter => $optText): ?>
                                    <label class="quiz-option-label">
                                        <input type="radio" name="q_<?= (int)$q['index'] ?>" value="<?= Security::e($letter) ?>" required style="margin-top: 0.25rem;"/>
                                        <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::formatInlineText($optText) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="quiz-feedback" style="display: none; margin-top: 0.85rem; padding: 0.75rem; border-radius: var(--radius-sm); background: rgba(16, 185, 129, 0.1); border-left: 3px solid var(--accent-emerald); font-size: 0.84rem;">
                                    <strong>✔ Réponse attendue : <?= Security::e($q['correct']) ?></strong><br/>
                                    <?= Security::formatInlineText($q['explanation']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div id="quiz-result-banner" style="display: none;"></div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.8rem;">
                            🎯 Valider mes Réponses & Enregistrer mon Score Qualiopi
                        </button>
                    </form>
                </section>
            <?php endif; ?>

            <!-- CARTE DE NAVIGATION BAS DE PAGE (PRÉCÉDENT / ATELIER PRATIQUE / SUIVANT) -->
            <div class="module-bottom-nav-card">
                <div>
                    <?php if ($prevModId): ?>
                        <a href="index.php?page=module&id=<?= urlencode($prevModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                            ◀ <?= Security::e($prevModBadge) ?> — Module Précédent
                        </a>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
                    <?php if (!empty($mod['linked_lab_id'])): ?>
                        <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                            🧪 Passer au Lab Pratique Docker (Lab <?= Security::e($mod['code']) ?>)
                        </a>
                    <?php endif; ?>
                    <?php if ($nextModId): ?>
                        <a href="index.php?page=module&id=<?= urlencode($nextModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                            Module Suivant (<?= Security::e($nextModBadge) ?>) ▶
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FORUM D'ENTRAIDE TECHNIQUE & Q&A SUR CE MODULE -->
            <section id="discussion-section" class="lms-card" style="margin-top: 1.5rem;">
                <span class="badge-code">ENTRAIDE TECHNIQUE & ACCOMPAGNEMENT FORMATEUR</span>
                <h3 style="font-size: 1.15rem; margin: 0.35rem 0 0.75rem;">💬 Questions & Réponses sur ce Module (<?= count($discussions) ?>)</h3>
                <?php if (!empty($discussions)): ?>
                    <div style="display: flex; flex-direction: column; gap: 0.65rem; margin-bottom: 1rem;">
                        <?php foreach ($discussions as $msg): ?>
                            <div style="padding: 0.75rem 1rem; border-radius: var(--radius-sm); background: var(--bg-base); border: 1px solid var(--border-hairline);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem; font-size: 0.78rem;">
                                    <span>
                                        <strong><?= Security::e((string)($msg['user_name'] ?? 'Apprenant')) ?></strong>
                                        <span class="role-badge role-<?= Security::e((string)($msg['user_role'] ?? 'student')) ?>"><?= Security::e((string)($msg['user_role'] ?? 'student')) ?></span>
                                    </span>
                                    <span style="color: var(--text-muted);" class="tabular-nums"><?= Security::e(substr((string)($msg['created_at'] ?? ''), 0, 16)) ?></span>
                                </div>
                                <div style="font-size: 0.86rem; color: var(--text-secondary); white-space: pre-line;"><?= Security::e((string)($msg['message'] ?? '')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="index.php?page=module&action=post_discussion&course=<?= urlencode($currentCourseSlug) ?>">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <input type="hidden" name="item_id" value="<?= Security::e($moduleId) ?>"/>
                    <input type="hidden" name="return_page" value="module"/>
                    <textarea name="message" class="form-control" rows="2" required placeholder="Posez une question technique ou partagez un retour d'expérience sur ce module..."></textarea>
                    <div style="margin-top: 0.5rem; text-align: right;">
                        <button type="submit" class="btn btn-primary btn-sm">💬 Publier ma question / remarque</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- COLONNE LATÉRALE ÉPURÉE : SOMMAIRE SCROLLSPY + NOTES + PARCOURS REPLIABLE -->
        <aside class="reader-sidebar" id="app-right-sidebar">
            <!-- 1. SOMMAIRE DU DOCUMENT AVEC INDICATEUR DE POSITION EN DIRECT (SCROLLSPY) -->
            <div class="toc-box">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.4rem; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                        📑 Sommaire & Position
                    </span>
                    <div style="display: flex; align-items: center; gap: 0.35rem;">
                        <span id="toc-read-pct" class="badge-code tabular-nums" title="Progression de lecture dans ce document">0% lu</span>
                        <button type="button"
                                class="btn-sidebar-collapse-inline btn-toggle-right-panel"
                                title="Réduire la barre verticale de droite (Alt+D)"
                                aria-label="Réduire la barre verticale de droite">
                            ▸
                        </button>
                    </div>
                </div>
                <div class="progress-bar-track" style="height: 5px; margin-bottom: 0.75rem;">
                    <div id="toc-read-bar" class="progress-bar-fill" style="width: 0%;"></div>
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

            <!-- 2. BLOC-NOTES PERSONNEL DE L'ÉTUDIANT -->
            <div class="toc-box">
                <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
                    📝 Mes Notes Personnelles
                </div>
                <textarea id="user-personal-note" class="form-control" rows="4" placeholder="Notez vos points clés, paramètres postgresql.conf ou questions..."><?= Security::e($savedNote) ?></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.55rem;">
                    <span id="note-save-status" style="font-size: 0.74rem; color: var(--accent-emerald);"></span>
                    <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        💾 Enregistrer
                    </button>
                </div>
            </div>

            <!-- 3. PROGRESSION DANS LE PARCOURS (REPLIABLE POUR GARDER L'INTERFACE AÉRÉE) -->
            <details class="toc-box" style="cursor: pointer;">
                <summary style="display: flex; justify-content: space-between; align-items: center; list-style: none;">
                    <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                        📍 Étape <?= (int)($mod['track_position'] ?? 1) ?> / <?= (int)($mod['track_total'] ?? count($trackModules)) ?> du Parcours
                    </span>
                    <span class="badge-code tabular-nums"><?= $trackProgressPct ?>%</span>
                </summary>
                <div style="margin-top: 0.65rem;">
                    <div class="progress-bar-track" style="height: 5px; margin-bottom: 0.65rem;">
                        <div class="progress-bar-fill" style="width: <?= $trackProgressPct ?>%;"></div>
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
