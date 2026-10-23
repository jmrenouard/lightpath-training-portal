<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$activeTab = (string)($_GET['tab'] ?? 'modules');
$selectedTrack = (string)($_GET['track'] ?? 'all');
?>

<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
        <span class="badge-code">CATALOGUE PÉDAGOGIQUE COMPLET • QUALIOPI</span>
        <h1 style="font-size: 1.75rem; margin: 0.4rem 0 0.2rem;">
            <?= $activeTab === 'labs' ? '🧪 Les 34 Ateliers Pratiques & Labs Docker' : '📚 Les 34 Modules de Cours & Supports Multi-Formats' ?>
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Accédez à chaque module ou atelier en ligne et téléchargez directement les supports en <code>PDF</code>, <code>Slides PDF</code>, <code>PPTX</code>, <code>MD</code>, <code>HTML</code> et <code>DOCX</code>.
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <a href="index.php?page=catalog&tab=modules&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab !== 'labs' ? 'btn-primary' : 'btn-outline' ?>">
            📚 Modules de Cours (<?= count($currentCourse['modules'] ?? []) ?>)
        </a>
        <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn <?= $activeTab === 'labs' ? 'btn-emerald' : 'btn-outline' ?>">
            🧪 Ateliers & TP Docker (<?= count($currentCourse['labs'] ?? []) ?>)
        </a>
    </div>
</div>

<?php if ($activeTab !== 'labs'): ?>
    <!-- Barre de filtre par Parcours -->
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.5rem;">
        <a href="index.php?page=catalog&track=all&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $selectedTrack === 'all' ? 'btn-primary' : 'btn-outline' ?>">
            Tous les Parcours (<?= count($currentCourse['modules'] ?? []) ?>)
        </a>
        <?php foreach (($currentCourse['tracks'] ?? []) as $tKey => $track): ?>
            <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $selectedTrack === $tKey ? 'btn-primary' : 'btn-outline' ?>">
                <?= Security::e($track['title']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Grille des 34 Modules -->
    <div class="card-grid">
        <?php foreach (($currentCourse['modules'] ?? []) as $mId => $mod):
            if ($selectedTrack !== 'all' && $mod['track_id'] !== $selectedTrack) {
                continue;
            }
            $isDone = in_array($mId, $userProgress['completed_modules'] ?? [], true);
            $quizScore = $userProgress['quiz_scores'][$mId] ?? null;
        ?>
            <article class="lms-card <?= $isDone ? 'is-completed' : '' ?>" data-searchable="<?= Security::e($mod['badge_code'] . ' ' . $mod['title'] . ' ' . $mod['bloom'] . ' ' . $mod['track_title']) ?>">
                <div>
                    <div class="card-top-meta">
                        <span class="badge-code"><?= Security::e($mod['badge_code']) ?></span>
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <?php if ($quizScore !== null): ?>
                                <span class="badge-code" style="background: rgba(56, 189, 248, 0.14);">Quiz : <?= (int)$quizScore['percentage'] ?>%</span>
                            <?php endif; ?>
                            <?php if ($isDone): ?>
                                <span class="badge-completed">✔ Validé</span>
                            <?php else: ?>
                                <span style="font-size: 0.75rem; color: var(--text-muted);">⏱ <?= Security::e($mod['duration']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem;">
                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="color: var(--text-primary);">
                            <?= Security::e($mod['title']) ?>
                        </a>
                    </h3>

                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                        <strong>Objectif Bloom :</strong> <?= Security::e($mod['bloom']) ?>
                    </p>
                </div>

                <div>
                    <div style="display: flex; gap: 0.45rem;">
                        <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm" style="flex: 1;">
                            📖 Lire & Passer le Quiz
                        </a>
                        <?php if (!empty($mod['linked_lab_id'])): ?>
                            <a href="index.php?page=lab&id=<?= urlencode((string)$mod['linked_lab_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm" title="Ouvrir l'Atelier Pratique Docker associé">
                                🧪 Atelier
                            </a>
                        <?php endif; ?>
                    </div>

                    <?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? []) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <!-- Grille des 34 Ateliers Pratiques (Labs Docker) -->
    <div class="card-grid">
        <?php foreach (($currentCourse['labs'] ?? []) as $labId => $lab):
            $isLabDone = in_array($labId, $userProgress['completed_labs'] ?? [], true);
        ?>
            <article class="lms-card <?= $isLabDone ? 'is-completed' : '' ?>" data-searchable="<?= Security::e($lab['badge_code'] . ' ' . $lab['title'] . ' ' . $lab['bloom']) ?>">
                <div>
                    <div class="card-top-meta">
                        <span class="badge-code" style="background: rgba(16, 185, 129, 0.16); color: #34D399; border-color: rgba(16, 185, 129, 0.35);">
                            <?= Security::e($lab['badge_code']) ?>
                        </span>
                        <?php if ($isLabDone): ?>
                            <span class="badge-completed">✔ Oracle Validé</span>
                        <?php else: ?>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">⏱ <?= Security::e($lab['duration']) ?></span>
                        <?php endif; ?>
                    </div>

                    <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem;">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="color: var(--text-primary);">
                            <?= Security::e($lab['title']) ?>
                        </a>
                    </h3>

                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                        <?= Security::e($lab['bloom']) ?>
                    </p>
                </div>

                <div>
                    <div style="display: flex; gap: 0.45rem;">
                        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm" style="flex: 1;">
                            🐳 Ouvrir le Studio Lab & Console SQL
                        </a>
                        <?php if (!empty($lab['linked_module_id'])): ?>
                            <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                                📖 Cours
                            </a>
                        <?php endif; ?>
                    </div>

                    <?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
