<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$searchQuery = trim((string)($_GET['q'] ?? ''));
$results = $searchQuery !== '' ? $courseRepo->searchContent($currentCourseSlug, $searchQuery) : [];
$searchModulesCount = count($currentCourse['modules'] ?? []);
$searchLabsCount = count($currentCourse['labs'] ?? []);
$searchCheatsheetsCount = count($currentCourse['cheatsheets'] ?? []);
$searchGlossaryCount = count(\LightPath\LMS\Course\LmsCatalog::getGlossary($currentCourseSlug));
$searchCourseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$searchCourseDockerEnabled = $searchCourseHasDocker && (!isset($featureManager) || $featureManager->isEnabledForCourse('docker_labs', $currentCourseSlug));
?>

<div class="hero-banner hero-banner-compact">
    <div class="flex-1 min-w-0">
        <span class="badge-code">MOTEUR DE RECHERCHE PLEIN-TEXTE INSTANTANÉ (CTRL+K)</span>
        <h1 class="hero-title-md mt-xs mb-xxs">🔍 Recherche Globale — <?= Security::e($currentCourse['short_title'] ?? 'Formation') ?></h1>
        <p class="hero-subtitle-sm mb-0">
            Recherchez simultanément dans les <?= $searchModulesCount ?> modules de cours, les <?= $searchLabsCount ?> <?= $searchCourseDockerEnabled ? 'ateliers pratiques Docker' : 'ateliers pratiques' ?>, les <?= $searchCheatsheetsCount ?> fiches mémo et les <?= $searchGlossaryCount ?> définitions du glossaire<?= $currentCourseSlug === 'postgresql-18' ? ' DBA' : '' ?>.
        </p>
    </div>
</div>

<div class="lms-card mb-lg">
    <form method="GET" action="index.php" class="d-flex gap-sm flex-wrap">
        <input type="hidden" name="page" value="search"/>
        <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
        <input type="search" name="q" class="form-control flex-1 search-query-input" aria-label="Rechercher dans tout le cursus" placeholder="<?= $currentCourseSlug === 'postgresql-18' ? 'Ex: pg_stat_io, mvcc, patroni, pgbackrest, hugepages, uuidv7, scram...' : 'Ex: init, add, commit, switch, merge, remote, fetch, pull...' ?>" value="<?= Security::e($searchQuery) ?>" autofocus/>
        <button type="submit" class="btn btn-primary">🔍 Rechercher</button>
    </form>
</div>

<?php if ($searchQuery !== ''): ?>
    <h2 class="section-heading-md mb-1 tabular-nums">
        Résultats pour « <?= Security::e($searchQuery) ?> » (<?= count($results) ?> correspondance<?= count($results) > 1 ? 's' : '' ?>)
    </h2>

    <?php if (empty($results)): ?>
        <div class="lms-card">
            <?php if ($currentCourseSlug === 'postgresql-18'): ?>
                <p class="mb-0 text-secondary">Aucun résultat trouvé pour ce terme. Essayez un mot-clé SQL ou un concept PostgreSQL 18 (<code>VACUUM</code>, <code>BRIN</code>, <code>WAL</code>, <code>Patroni</code>, <code>sysctl</code>).</p>
            <?php else: ?>
                <p class="mb-0 text-secondary">Aucun résultat trouvé pour ce terme. Essayez un mot-clé issu du cursus <?= Security::e($currentCourse['short_title'] ?? 'Formation') ?> (<code>commit</code>, <code>branch</code>, <code>merge</code>, <code>remote</code>).</p>
            <?php endif; ?>
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
                        <h3 class="section-heading-sm mb-xs">
                            <a href="index.php<?= Security::e($r['url']) ?>" class="link-primary">
                                <?= Security::formatInlineText($r['title']) ?>
                            </a>
                        </h3>
                        <p class="text-sm text-secondary mb-sm">
                            <?= Security::formatInlineText($r['snippet']) ?>
                        </p>
                    </div>
                    <a href="index.php<?= Security::e($r['url']) ?>" class="btn btn-outline btn-sm w-100">
                        Ouvrir la ressource →
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
