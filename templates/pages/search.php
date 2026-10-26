<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$searchQuery = trim((string)($_GET['q'] ?? ''));
$results = $searchQuery !== '' ? $courseRepo->searchContent($currentCourseSlug, $searchQuery) : [];
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <span class="badge-code">MOTEUR DE RECHERCHE PLEIN-TEXTE INSTANTANÉ (CTRL+K)</span>
        <h1 style="font-size: 1.6rem; margin: 0.4rem 0 0.35rem;">🔍 Recherche Globale dans le Cursus PostgreSQL 18</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Recherchez simultanément dans les 34 modules de cours, les 34 ateliers pratiques Docker, les 69 fiches mémo et les 52 définitions du glossaire DBA.
        </p>
    </div>
</div>

<div class="lms-card" style="margin-bottom: 1.5rem;">
    <form method="GET" action="index.php" style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
        <input type="hidden" name="page" value="search"/>
        <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
        <input type="search" name="q" class="form-control" style="flex: 1; min-width: 260px;" placeholder="Ex: pg_stat_io, mvcc, patroni, pgbackrest, hugepages, uuidv7, scram..." value="<?= Security::e($searchQuery) ?>" autofocus/>
        <button type="submit" class="btn btn-primary">🔍 Rechercher</button>
    </form>
</div>

<?php if ($searchQuery !== ''): ?>
    <h2 style="font-size: 1.2rem; margin-bottom: 1rem;" class="tabular-nums">
        Résultats pour « <?= Security::e($searchQuery) ?> » (<?= count($results) ?> correspondance<?= count($results) > 1 ? 's' : '' ?>)
    </h2>

    <?php if (empty($results)): ?>
        <div class="lms-card">
            <p style="margin: 0; color: var(--text-secondary);">Aucun résultat trouvé pour ce terme. Essayez un mot-clé SQL ou un concept PostgreSQL 18 (`VACUUM`, `BRIN`, `WAL`, `Patroni`, `sysctl`).</p>
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
                                <?= Security::e($r['title']) ?>
                            </a>
                        </h3>
                        <p style="font-size: 0.84rem; color: var(--text-secondary); margin-bottom: 0.85rem;">
                            <?= Security::e($r['snippet']) ?>
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
