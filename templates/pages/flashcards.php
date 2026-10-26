<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$activeTab = (string)($_GET['tab'] ?? 'flashcards');
$filterQuery = trim((string)($_GET['q'] ?? ''));
$flashcards = LmsCatalog::getFlashcards();
$glossary = LmsCatalog::getGlossary();
$userFcRatings = $userProgress['flashcards'] ?? [];

$masteredCount = count(array_filter($userFcRatings, static fn($lvl) => (int)$lvl === 3));
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">MÉMORISATION ACTIVE SRS & RÉFÉRENTIEL TERMINOLOGIQUE</span>
            <span class="badge-phase tabular-nums"><?= count($flashcards) ?> Flashcards SRS • <?= count($glossary) ?> Termes Glossaire</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">🧠 Cartes Mémo SRS (Spaced Repetition) & Glossaire DBA</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Révisez activement les concepts critiques de PostgreSQL 18 (MVCC, AIO, `pg_stat_io`, Indexation, Patroni, `pgBackRest`, Kernel Linux Module 20) et auto-évaluez votre niveau de maîtrise.
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="index.php?page=flashcards&tab=flashcards&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $activeTab === 'flashcards' ? 'btn-primary' : 'btn-outline' ?>">
            🃏 Cartes Mémo SRS (<?= $masteredCount ?>/<?= count($flashcards) ?> maîtrisées)
        </a>
        <a href="index.php?page=flashcards&tab=glossary&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $activeTab === 'glossary' ? 'btn-primary' : 'btn-outline' ?>">
            📖 Glossaire Technique (<?= count($glossary) ?> termes)
        </a>
    </div>
</div>

<?php if ($activeTab === 'glossary'): ?>
    <div class="lms-card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
            <h2 style="font-size: 1.25rem; margin: 0;">📖 Glossaire Technique PostgreSQL 18 (<?= count($glossary) ?> définitions)</h2>
            <input type="search" id="glossary-filter-input" class="form-control" style="max-width: 320px;" placeholder="Filtrer un terme (ex: MVCC, HOT, AIO, WAL)..." value="<?= Security::e($filterQuery) ?>"/>
        </div>

        <div class="card-grid" id="glossary-cards-grid">
            <?php foreach ($glossary as $item): ?>
                <article class="lms-card glossary-item-card" data-searchable="<?= Security::e(strtolower($item['term'] . ' ' . $item['category'] . ' ' . $item['definition'])) ?>">
                    <div>
                        <div class="card-top-meta">
                            <span class="badge-code"><?= Security::e($item['category']) ?></span>
                        </div>
                        <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem; color: var(--brand-cyan-light);">
                            <?= Security::e($item['term']) ?>
                        </h3>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                            <?= Security::e($item['definition']) ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
    (function () {
        var input = document.getElementById('glossary-filter-input');
        var cards = document.querySelectorAll('.glossary-item-card');
        function applyFilter() {
            if (!input) return;
            var q = input.value.trim().toLowerCase();
            cards.forEach(function (c) {
                var hay = (c.getAttribute('data-searchable') || '').toLowerCase();
                c.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        }
        if (input) {
            input.addEventListener('input', applyFilter);
            if (input.value) applyFilter();
        }
    })();
    </script>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($flashcards as $idx => $fc):
            $cid = $fc['id'];
            $conf = (int)($userFcRatings[$cid] ?? 0);
            $statusLabel = match ($conf) {
                3 => '🟢 Maîtrisé',
                2 => '🟡 En acquisition',
                1 => '🔴 À revoir',
                default => '⚪ Non évalué',
            };
        ?>
            <article class="lms-card flashcard-box" data-card-id="<?= Security::e($cid) ?>">
                <div>
                    <div class="card-top-meta">
                        <span class="badge-code tabular-nums">#<?= sprintf('%02d', $idx + 1) ?> • <?= Security::e($fc['category']) ?></span>
                        <span class="badge-phase fc-status-badge"><?= $statusLabel ?></span>
                    </div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.65rem; color: var(--text-primary);">
                        ❓ <?= Security::e($fc['front']) ?>
                    </h3>
                    <details class="lms-details" style="margin-bottom: 0.75rem;">
                        <summary class="lms-summary">👁️ Retourner la carte (Voir la réponse)</summary>
                        <div class="lms-details-body" style="font-size: 0.86rem; color: var(--text-secondary); white-space: pre-line;">
                            <?= Security::e($fc['back']) ?>
                        </div>
                    </details>
                </div>

                <div style="display: flex; gap: 0.4rem; padding-top: 0.5rem; border-top: 1px solid var(--border-hairline);">
                    <button type="button" class="btn btn-outline btn-sm btn-fc-rate" data-card-id="<?= Security::e($cid) ?>" data-level="1" style="flex:1; font-size:0.76rem;">
                        🔴 À revoir
                    </button>
                    <button type="button" class="btn btn-outline btn-sm btn-fc-rate" data-card-id="<?= Security::e($cid) ?>" data-level="2" style="flex:1; font-size:0.76rem;">
                        🟡 Moyen
                    </button>
                    <button type="button" class="btn btn-emerald btn-sm btn-fc-rate" data-card-id="<?= Security::e($cid) ?>" data-level="3" style="flex:1; font-size:0.76rem;">
                        🟢 Maîtrisé
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
