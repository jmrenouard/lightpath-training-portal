<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$searchQuery = trim((string)($_GET['q'] ?? ''));
$results = $searchQuery !== '' ? $courseRepo->searchContent($currentCourseSlug, $searchQuery) : [];
$searchModulesCount = count($currentCourse['modules'] ?? []);
$searchLabsCount = count($currentCourse['labs'] ?? []);
$searchCheatsheetsCount = count($currentCourse['cheatsheets'] ?? []);
$searchGlossaryCount = count(\LightPath\LMS\Course\LmsCatalog::getGlossary());
?>

<div class="hero-banner hero-banner-compact">
    <div class="flex-1 min-w-0">
        <span class="badge-code">MOTEUR DE RECHERCHE PLEIN-TEXTE INSTANTANÉ (CTRL+K)</span>
        <h1 class="hero-title-md mt-xs mb-xxs">🔍 Recherche Globale — <?= Security::e($currentCourse['short_title'] ?? 'Formation') ?></h1>
        <p class="hero-subtitle-sm mb-0">
            Recherchez simultanément dans les <?= $searchModulesCount ?> modules de cours, les <?= $searchLabsCount ?> ateliers pratiques Docker, les <?= $searchCheatsheetsCount ?> fiches mémo et les <?= $searchGlossaryCount ?> définitions du glossaire DBA.
        </p>
    </div>
</div>

<div class="lms-card mb-lg">
    <form method="GET" action="index.php" class="d-flex gap-sm flex-wrap">
        <input type="hidden" name="page" value="search"/>
        <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
        <input type="search" name="q" class="form-control flex-1 search-query-input" aria-label="Rechercher dans tout le cursus" placeholder="Ex: pg_stat_io, mvcc, patroni, pgbackrest, hugepages, uuidv7, scram..." value="<?= Security::e($searchQuery) ?>" autofocus/>
        <button type="submit" class="btn btn-primary">🔍 Rechercher</button>
    </form>
</div>

<?php if ($searchQuery !== ''): ?>
    <h2 style="font-size: 1.2rem; margin-bottom: 1rem;" class="tabular-nums">
        Résultats pour « <?= Security::e($searchQuery) ?> » (<?= count($results) ?> correspondance<?= count($results) > 1 ? 's' : '' ?>)
    </h2>

    <?php if (empty($results)): ?>
        <div class="lms-card">
            <p style="margin: 0; color: var(--text-secondary);">Aucun résultat trouvé pour ce terme. Essayez un mot-clé SQL ou un concept PostgreSQL 18 (<code>VACUUM</code>, <code>BRIN</code>, <code>WAL</code>, <code>Patroni</code>, <code>sysctl</code>).</p>
        </div>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($results as $r): ?>
                <article class="lms-card">
                    <div>
                        <div class="card-top-meta">
                            <span class="badge-code"><?= Security::e($r['badge']) ?></span>
                            <span class="badge-phase"><?= strtoupper(Security::e($r['type'])) ?></span>
                        </div>
                        <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem;">
                            <a href="index.php<?= Security::e($r['url']) ?>" style="color: var(--text-primary);">
                                <?= Security::formatInlineText($r['title']) ?>
                            </a>
                        </h3>
                        <p style="font-size: 0.84rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                            <?= Security::formatInlineText($r['snippet']) ?>
                        </p>
                    </div>
                    <a href="index.php<?= Security::e($r['url']) ?>" class="btn btn-outline btn-sm" style="width: 100%;">
                        Ouvrir la ressource →
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
