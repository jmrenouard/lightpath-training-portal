<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

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
$isDone = in_array($moduleId, $userProgress['completed_modules'] ?? [], true);
$savedNote = (string)($userProgress['notes'][$moduleId] ?? '');
$quizScore = $userProgress['quiz_scores'][$moduleId] ?? null;

// Calculer Module Précédent / Suivant
$modKeys = array_keys($currentCourse['modules'] ?? []);
$curIndex = array_search($moduleId, $modKeys, true);
$prevModId = ($curIndex !== false && $curIndex > 0) ? $modKeys[$curIndex - 1] : null;
$nextModId = ($curIndex !== false && $curIndex < count($modKeys) - 1) ? $modKeys[$curIndex + 1] : null;

$viewMode = (string)($_GET['mode'] ?? 'reader');
?>

<!-- EN-TÊTE DU MODULE & BARRE DE TÉLÉCHARGEMENT MULTI-FORMATS -->
<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1;">
        <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">← Catalogue</a>
            <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
            <span class="badge-code" style="background: rgba(245, 158, 11, 0.14); color: #FBBF24; border-color: rgba(245, 158, 11, 0.3);">
                <?= Security::e($mod['track_title']) ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">⏱ <?= Security::e($mod['duration']) ?></span>
        </div>

        <h1 style="font-size: 1.55rem; margin-bottom: 0.35rem;"><?= Security::e($mod['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0 0 0.75rem; font-size: 0.88rem;">
            <strong>Compétence Opérationnelle :</strong> <?= Security::e($mod['bloom']) ?>
        </p>

        <!-- Liens de téléchargement directs tous formats -->
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">📥 Télécharger ce cours :</span>
            <?= renderDownloadStrip($currentCourseSlug, 'module', $moduleId, $mod['downloads'] ?? []) ?>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.55rem; min-width: 230px;">
        <button type="button"
                class="btn <?= $isDone ? 'btn-emerald' : 'btn-primary' ?> btn-toggle-complete"
                data-type="module"
                data-id="<?= Security::e($moduleId) ?>"
                data-course="<?= Security::e($currentCourseSlug) ?>"
                data-completed="<?= $isDone ? '1' : '0' ?>">
            <?= $isDone ? '✔ Module Terminé (Cliquer pour annuler)' : '✅ Marquer ce Module comme Terminé' ?>
        </button>

        <?php if (!empty($mod['linked_lab_id'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
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

<!-- SÉLECTEUR DE MODE DE LECTURE -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div style="display: flex; gap: 0.5rem;">
        <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=reader&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $viewMode === 'reader' ? 'btn-primary' : 'btn-outline' ?>">
            📖 Lecteur Interactif + Quiz QCM
        </a>
        <?php if (isset($mod['downloads']['html'])): ?>
            <a href="index.php?page=module&id=<?= urlencode($moduleId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $viewMode === 'html_preview' ? 'btn-primary' : 'btn-outline' ?>">
                🌐 Rendu Web HTML Autonome
            </a>
        <?php endif; ?>
        <?php if (isset($mod['downloads']['slides_pdf'])): ?>
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=slides_pdf&inline=1" target="_blank" class="btn btn-sm btn-outline">
                🖥️ Ouvrir le Deck Slides PDF (Nouvel onglet)
            </a>
        <?php endif; ?>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <?php if ($prevModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($prevModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">← Module Précédent</a>
        <?php endif; ?>
        <?php if ($nextModId): ?>
            <a href="index.php?page=module&id=<?= urlencode($nextModId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">Module Suivant →</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewMode === 'html_preview' && isset($mod['downloads']['html'])): ?>
    <div style="background: #FFFFFF; border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-strong); height: 82vh;">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=module&id=<?= urlencode($moduleId) ?>&format=html&inline=1"
                style="width: 100%; height: 100%; border: none;"
                title="<?= Security::e($mod['title']) ?>"></iframe>
    </div>
<?php else: ?>
    <!-- GRILLE LECTEUR + SOMMAIRE FLOTTANT -->
    <div class="reader-grid">
        <div>
            <article class="reader-article">
                <?= $parsed['html'] ?>
            </article>

            <!-- COMPOSANT QUIZ QCM INTERACTIF AUTO-CORRIGÉ -->
            <?php if (!empty($parsed['quiz'])): ?>
                <section id="quiz-interactive-section" class="quiz-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <div>
                            <span class="badge-code">ÉVALUATION FORMATIVE QUALIOPI</span>
                            <h2 style="font-size: 1.35rem; margin: 0.35rem 0 0;">❓ Quiz Interactif d'Auto-Évaluation (<?= count($parsed['quiz']) ?> questions)</h2>
                        </div>
                        <?php if ($quizScore !== null): ?>
                            <span class="badge-completed" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
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

        <!-- COLONNE LATÉRALE : SOMMAIRE & NOTES PERSONNELLES -->
        <aside class="reader-sidebar">
            <div class="toc-box">
                <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.65rem;">
                    📑 Sommaire du Module
                </div>
                <?php foreach ($parsed['toc'] as $tocItem): ?>
                    <a href="#<?= Security::e($tocItem['id']) ?>" class="toc-link toc-level-<?= (int)$tocItem['level'] ?>">
                        <?= Security::e($tocItem['title']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!empty($parsed['quiz'])): ?>
                    <a href="#quiz-interactive-section" class="toc-link" style="color: var(--accent-emerald); font-weight: 700; margin-top: 0.4rem;">
                        ❓ Aller au Quiz QCM Interactif
                    </a>
                <?php endif; ?>
            </div>

            <!-- Bloc-notes personnel de l'étudiant -->
            <div class="toc-box">
                <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
                    📝 Mes Notes Personnelles
                </div>
                <textarea id="user-personal-note" class="form-control" rows="6" placeholder="Notez vos points clés, paramètres postgresql.conf ou questions pour le formateur..."><?= Security::e($savedNote) ?></textarea>
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
