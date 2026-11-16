<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin multi-course catalog partial: displays connected courses, toggle/delete controls
 * for custom courses, and a registration form to mount external course directories.
 */
?>
<div class="reader-grid">
    <div>
        <h2 class="section-heading-md mb-1">🎓 Formations Connectées au Portail LMS</h2>
        <div class="card-grid">
            <?php foreach ($allManageableCourses as $cSlug => $cCfg):
                $cSlugStr = (string)$cSlug;
                $loadedCourse = $courseRepo->getCourse($cSlugStr);
                $isActive = !empty($cCfg['active']);
                $isCustom = isset($customCourses[$cSlugStr]);
                $cTitle = (string)($loadedCourse['title'] ?? $cCfg['title'] ?? $cSlugStr);
                $cBadge = (string)($loadedCourse['badge'] ?? $cCfg['badge'] ?? strtoupper($cSlugStr));
                $cSource = (string)($loadedCourse['source_root'] ?? $cCfg['source_root'] ?? '');
                $cStats = $loadedCourse['stats'] ?? ['modules_count' => 0, 'labs_count' => 0, 'cheatsheets_count' => 0];
            ?>
                <div class="lms-card">
                    <div>
                        <div class="card-top-meta">
                            <span class="badge-code"><?= Security::e($cBadge) ?></span>
                            <?= $isActive
                                ? '<span class="badge-completed">Active</span>'
                                : '<span class="badge-danger-text">Inactive</span>' ?>
                        </div>
                        <h3 class="section-heading-sm mb-xs"><?= Security::e($cTitle) ?></h3>
                        <p class="text-xs text-secondary mb-sm">
                            Dossier source : <code><?= Security::e($cSource) ?></code>
                        </p>
                        <div class="text-xs text-cyan tabular-nums">
                            📚 <?= (int)($cStats['modules_count'] ?? 0) ?> Modules •
                            🧪 <?= (int)($cStats['labs_count'] ?? 0) ?> Labs •
                            📑 <?= (int)($cStats['cheatsheets_count'] ?? 0) ?> Cheatsheets
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-xs mt-1">
                        <?php if ($isActive): ?>
                            <a href="index.php?page=dashboard&course=<?= urlencode($cSlugStr) ?>" class="btn btn-primary btn-sm flex-1">
                                Ouvrir cette Formation →
                            </a>
                        <?php endif; ?>
                        <?php if ($isCustom): ?>
                            <form method="POST" action="index.php?page=admin&tab=courses&action=admin_toggle_course&course=<?= urlencode($currentCourseSlug) ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                <input type="hidden" name="course_slug" value="<?= Security::e($cSlugStr) ?>"/>
                                <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>"/>
                                <button type="submit" class="btn btn-outline btn-sm">
                                    <?= $isActive ? '⏸️ Désactiver' : '▶ Activer' ?>
                                </button>
                            </form>
                            <form method="POST"
                                  action="index.php?page=admin&tab=courses&action=admin_delete_course&course=<?= urlencode($currentCourseSlug) ?>"
                                  class="d-inline"
                                  data-confirm="Supprimer la formation personnalisée « <?= Security::e($cTitle) ?> » ?">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                <input type="hidden" name="course_slug" value="<?= Security::e($cSlugStr) ?>"/>
                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑️</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="toc-box">
        <h3 class="section-heading-sm mt-0 mb-sm">➕ Connecter une Nouvelle Formation</h3>
        <p class="text-xs text-secondary mb-1">
            Ce portail LMS est 100% générique. Indiquez le chemin d'un autre dépôt de formation (ex: MySQL, Kubernetes, Linux, Python) respectant la structure <code>03_supports_de_cours/</code> et <code>04_ateliers_et_tp/</code>.
        </p>
        <form method="POST" action="index.php?page=admin&action=admin_add_course&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div class="form-group">
                <label for="add-course-slug">Identifiant (Slug)</label>
                <input id="add-course-slug" type="text" name="slug" class="form-control" required placeholder="Ex: kubernetes-production"/>
            </div>
            <div class="form-group">
                <label for="add-course-title">Titre de la Formation</label>
                <input id="add-course-title" type="text" name="title" class="form-control" required placeholder="Ex: Kubernetes &amp; Cloud Native en Production"/>
            </div>
            <div class="form-group">
                <label for="add-course-category">Catégorie</label>
                <input id="add-course-category" type="text" name="category" class="form-control" value="Cloud, DevOps &amp; Bases de Données"/>
            </div>
            <div class="form-group">
                <label for="add-course-duration">Durée</label>
                <input id="add-course-duration" type="text" name="duration" class="form-control" value="35h (5 jours) - Certifié Qualiopi"/>
            </div>
            <div class="form-group">
                <label for="add-course-source">Chemin du Référentiel Source</label>
                <input id="add-course-source" type="text" name="source_root" class="form-control" placeholder="Chemin absolu ou relatif vers la formation"/>
            </div>
            <div class="form-group">
                <label for="add-course-description">Description</label>
                <textarea id="add-course-description" name="description" class="form-control" rows="3" placeholder="Objectifs et public visé..."></textarea>
            </div>
            <button type="submit" class="btn btn-emerald w-100">
                🚀 Ajouter au Catalogue Multi-Formations
            </button>
        </form>
    </aside>
</div>
