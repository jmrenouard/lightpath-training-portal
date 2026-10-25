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

// Calculer Module Précédent / Suivant
$modKeys = array_keys($currentCourse['modules'] ?? []);
$curIndex = array_search($moduleId, $modKeys, true);
$prevModId = ($curIndex !== false && $curIndex > 0) ? $modKeys[$curIndex - 1] : null;
$nextModId = ($curIndex !== false && $curIndex < count($modKeys) - 1) ? $modKeys[$curIndex + 1] : null;

$viewMode = (string)($_GET['mode'] ?? 'reader');
if (!in_array($viewMode, ['reader', 'slides', 'html_preview'], true)) {
    $viewMode = 'reader';
}
?>

<!-- EN-TÊTE DU MODULE, POSITION DANS LE PARCOURS & TÉLÉCHARGEMENTS MULTI-FORMATS -->
<div class="hero-banner" style="padding: 1.4rem 1.85rem;">
    <div style="flex: 1; min-width: 0;">
        <!-- Fil d'Ariane & Position dans le Parcours -->
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.55rem;">
            <a href="index.php?page=catalog&track=<?= urlencode($trackId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                ← <?= Security::e($mod['track_number'] ?? 'Parcours') ?>
            </a>
            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
            <span class="badge-phase">
                <?= Security::e($mod['phase_code'] ?? 'ÉTAPE') ?> • 📍 Étape <?= (int)($mod['track_position'] ?? 1) ?> sur <?= (int)($mod['track_total'] ?? 20) ?> du <?= Security::e($mod['track_number'] ?? 'Parcours') ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">⏱ <?= Security::e($mod['duration']) ?></span>
        </div>

        <h1 style="font-size: 1.5rem; margin-bottom: 0.35rem;"><?= Security::e($mod['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0 0 0.75rem; font-size: 0.87rem;">
            <strong>Compétence Opérationnelle :</strong> <?= Security::e($mod['bloom']) ?>
        </p>

        <!-- Liens de téléchargement directs tous formats -->
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">📥 Télécharger :</span>
            <?= renderDownloadStrip($currentCourseSlug, 'module', $moduleId, $mod['downloads'] ?? []) ?>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem; min-width: 240px;">
        <button type="button"
                class="btn <?= $isDone ? 'btn-emerald' : 'btn-primary' ?> btn-toggle-complete"
                data-type="module"
                data-id="<?= Security::e($moduleId) ?>"
                data-course="<?= Security::e($currentCourseSlug) ?>"
                data-completed="<?= $isDone ? '1' : '0' ?>">
            <?= $isDone ? '✔ Module Terminé (Cliquer pour annuler)' : '✅ Marquer ce Module comme Terminé' ?>
        </button>

        <?php if (!empty($mod['linked_lab_id'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                🧪 Passer à l'Atelier Pratique Docker →
            </a>
        <?php endif; ?>

        <?php if (!empty($mod['linked_cheatsheet_id'])): ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=cheatsheet&id=<?= urlencode((string)$mod['linked_cheatsheet_id']) ?>&format=pdf" class="btn btn-outline btn-sm">
                📑 Télécharger la Fiche Mémo (Cheatsheet PDF)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- BARRE DE BASCULE DES 3 MODES D'ÉTUDE (COURS INTERACTIF / DIAPORAMA 16:9 / PAGE WEB STUDIO) -->
<div class="view-mode-switcher">
    <div class="view-mode-tabs">
        <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=reader&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $viewMode === 'reader' ? 'active' : '' ?>">
            <span>📖 Cours Interactif & Quiz QCM</span>
        </a>
        <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $viewMode === 'slides' ? 'active' : '' ?>">
            <span>🖥️ Mode Diaporama 16:9</span>
            <span class="tab-pill tabular-nums"><?= $totalSlides ?> slides</span>
        </a>
        <?php if (isset($mod['downloads']['html'])): ?>
            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $viewMode === 'html_preview' ? 'active' : '' ?>">
                <span>🌐 Rendu Web Studio</span>
            </a>
        <?php endif; ?>
    </div>

    <div style="display: flex; gap: 0.45rem; flex-wrap: wrap;">
        <?php if ($prevModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($prevModId) ?>&mode=<?= urlencode($viewMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                ← Module Précédent
            </a>
        <?php endif; ?>
        <?php if ($nextModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($nextModId) ?>&mode=<?= urlencode($viewMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                Module Suivant →
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewMode === 'slides'): ?>
    <!-- ======================================================================
         MODE DIAPORAMA INTERACTIF 16:9 (LECTEUR HTML5 + SLIDES POWERPOINT PDF)
         ====================================================================== -->
    <div id="lms-slide-deck-wrapper" class="slide-deck-wrapper">
        <!-- Barre d'outils supérieure du lecteur de diapositives -->
        <div class="slide-deck-toolbar">
            <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap;">
                <button type="button" id="btn-slide-engine-html" class="btn btn-sm btn-primary">
                    ⚡ Diaporama Interactif 16:9 (<?= $totalSlides ?> slides)
                </button>
                <?php if (isset($mod['downloads']['slides_pdf'])): ?>
                    <button type="button" id="btn-slide-engine-pdf" class="btn btn-sm btn-outline">
                        📊 Support PowerPoint Officiel (PDF 16:9)
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
                            Slide <?= sprintf('%02d', (int)$s['index']) ?>/<?= sprintf('%02d', $totalSlides) ?> — <?= Security::e(CourseRepository::truncateUtf8($s['title'], 52)) ?>
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

        <!-- Scène principale 16:9 + Sommaire latéral des diapositives -->
        <div class="slide-deck-layout">
            <!-- Scène 16:9 Interactive HTML5 -->
            <div id="slide-stage-html" class="slide-stage-container">
                <?php foreach ($slides as $idx => $s): ?>
                    <section class="lms-slide-frame <?= $idx === 0 ? 'active' : '' ?>"
                             data-slide-index="<?= $idx ?>"
                             data-slide-title="<?= Security::e($s['title']) ?>">
                        <header class="lms-slide-header">
                            <div style="display: flex; align-items: center; gap: 0.6rem; min-width: 0;">
                                <img src="assets/img/postgresql_elephant.png" alt="PG18" style="width: 24px; height: 24px;"/>
                                <span class="lms-slide-badge"><?= Security::e($s['badge']) ?></span>
                                <span class="lms-slide-chapter"><?= Security::e($s['chapter']) ?></span>
                            </div>
                            <div class="lms-slide-counter tabular-nums">
                                DIAPO <?= sprintf('%02d', (int)$s['index']) ?> / <?= sprintf('%02d', $totalSlides) ?>
                            </div>
                        </header>

                        <div class="lms-slide-body">
                            <h2 class="lms-slide-title"><?= Security::e($s['title']) ?></h2>
                            <div class="lms-slide-content markdown-body">
                                <?= $s['html'] ?>
                            </div>
                        </div>

                        <footer class="lms-slide-footer">
                            <span>LightPath SARL • PostgreSQL 18 — Jean-Marie Renouard</span>
                            <span class="tabular-nums"><?= Security::e($mod['badge_code']) ?> • Étape <?= (int)($mod['track_position'] ?? 1) ?>/<?= (int)($mod['track_total'] ?? 20) ?></span>
                        </footer>
                    </section>
                <?php endforeach; ?>
            </div>

            <!-- Scène 16:9 PDF PowerPoint Officiel (_slides.pdf) -->
            <?php if (isset($mod['downloads']['slides_pdf'])): ?>
                <div id="slide-stage-pdf" class="slide-stage-pdf" hidden>
                    <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=slides_pdf&inline=1"
                            style="width: 100%; height: 76vh; border: none; border-radius: var(--radius-md);"
                            title="Diaporama PowerPoint PDF 16:9"></iframe>
                </div>
            <?php endif; ?>

            <!-- Colonne latérale : Liste des Diapositives (Position en direct dans le diaporama) -->
            <aside class="slide-outline-sidebar">
                <div class="toc-box" style="max-height: 74vh; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                        <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                            🖥️ Plan des Diapositives
                        </span>
                        <span id="slide-sidebar-counter" class="badge-code tabular-nums">1 / <?= $totalSlides ?></span>
                    </div>
                    <div id="slide-outline-list" class="slide-outline-list">
                        <?php foreach ($slides as $idx => $s): ?>
                            <button type="button"
                                    class="slide-outline-item <?= $idx === 0 ? 'active' : '' ?>"
                                    data-slide-jump="<?= $idx ?>">
                                <span class="slide-outline-num tabular-nums"><?= sprintf('%02d', (int)$s['index']) ?></span>
                                <span class="slide-outline-text"><?= Security::e($s['title']) ?></span>
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
                style="width: 100%; height: 100%; border: none;"
                title="<?= Security::e($mod['title']) ?>"></iframe>
    </div>

<?php else: ?>
    <!-- ======================================================================
         GRILLE LECTEUR INTERACTIF + SOMMAIRE SCROLLSPY & STEPPER DE PARCOURS
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
                                    <?= Security::e($q['question']) ?>
                                </div>
                                <?php foreach ($q['options'] as $letter => $optText): ?>
                                    <label class="quiz-option-label">
                                        <input type="radio" name="q_<?= (int)$q['index'] ?>" value="<?= Security::e($letter) ?>" required style="margin-top: 0.25rem;"/>
                                        <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::e($optText) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="quiz-feedback" style="display: none; margin-top: 0.85rem; padding: 0.75rem; border-radius: var(--radius-sm); background: rgba(16, 185, 129, 0.1); border-left: 3px solid var(--accent-emerald); font-size: 0.84rem;">
                                    <strong>✔ Réponse attendue : <?= Security::e($q['correct']) ?></strong><br/>
                                    <?= Security::e($q['explanation']) ?>
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
        </div>

        <!-- COLONNE LATÉRALE : SOMMAIRE SCROLLSPY + POSITION DANS LE PARCOURS + NOTES -->
        <aside class="reader-sidebar">
            <!-- 1. SOMMAIRE DU DOCUMENT AVEC INDICATEUR DE POSITION EN DIRECT (SCROLLSPY) -->
            <div class="toc-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                        📑 Sommaire & Position
                    </span>
                    <span id="toc-read-pct" class="badge-code tabular-nums" title="Progression de lecture dans ce document">0% lu</span>
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

            <!-- 2. STEPPER DE POSITION DANS LE PARCOURS DE FORMATION -->
            <div class="toc-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                        📍 Position dans le Parcours
                    </span>
                    <span class="badge-code tabular-nums">
                        Étape <?= (int)($mod['track_position'] ?? 1) ?> / <?= (int)($mod['track_total'] ?? count($trackModules)) ?>
                    </span>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 0.45rem;">
                    <?= Security::e($mod['track_short_title'] ?? $mod['track_title']) ?>
                </div>
                <div class="progress-bar-track" style="height: 5px; margin-bottom: 0.75rem;">
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

            <!-- 3. BLOC-NOTES PERSONNEL DE L'ÉTUDIANT -->
            <div class="toc-box">
                <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
                    📝 Mes Notes Personnelles
                </div>
                <textarea id="user-personal-note" class="form-control" rows="5" placeholder="Notez vos points clés, paramètres postgresql.conf ou questions..."><?= Security::e($savedNote) ?></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.55rem;">
                    <span id="note-save-status" style="font-size: 0.74rem; color: var(--accent-emerald);"></span>
                    <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($moduleId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                        💾 Enregistrer
                    </button>
                </div>
            </div>
        </aside>
    </div>
<?php endif; ?>
