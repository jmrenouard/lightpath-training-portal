<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$activeTab = (string)($_GET['tab'] ?? 'flashcards');
$filterQuery = trim((string)($_GET['q'] ?? ''));
$flashcards = LmsCatalog::getFlashcards();
$glossary = LmsCatalog::getGlossary();
$rawFcRatings = $userProgress['flashcard_confidence'] ?? $userProgress['flashcards'] ?? [];
$userFcRatings = [];
foreach ($rawFcRatings as $k => $v) {
    $userFcRatings[$k] = is_array($v) ? (int)($v['level'] ?? 0) : (int)$v;
}

$masteredCount = count(array_filter($userFcRatings, static fn(int $lvl): bool => $lvl === 3));

$distinctModules = [];
foreach ($flashcards as $fcItem) {
    $mCode = (string)($fcItem['module'] ?? 'M01');
    if ($mCode !== '' && !in_array($mCode, $distinctModules, true)) {
        $distinctModules[] = $mCode;
    }
}
natsort($distinctModules);
$distinctModules = array_values($distinctModules);
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">MÉMORISATION ACTIVE SRS & RÉFÉRENTIEL TERMINOLOGIQUE</span>
            <span class="badge-phase tabular-nums"><?= count($flashcards) ?> Flashcards SRS • <?= count($glossary) ?> Termes Glossaire</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">🧠 Cartes Mémo SRS (Spaced Repetition) & Glossaire DBA</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Révisez activement les concepts critiques de PostgreSQL 18 (MVCC, AIO, <code>pg_stat_io</code>, Indexation, Patroni, <code>pgBackRest</code>, Kernel Linux Module 20) et auto-évaluez votre niveau de maîtrise.
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
            <input type="search" id="glossary-filter-input" class="form-control search-inline-input" aria-label="Filtrer les termes du glossaire" placeholder="Filtrer un terme (ex: MVCC, HOT, AIO, WAL)..." value="<?= Security::e($filterQuery) ?>"/>
        </div>

        <div class="card-grid" id="glossary-cards-grid">
            <?php foreach ($glossary as $item): ?>
                <article class="lms-card glossary-item-card" data-searchable="<?= Security::e(strtolower($item['term'] . ' ' . $item['category'] . ' ' . $item['definition'])) ?>">
                    <div>
                        <div class="card-top-meta">
                            <span class="badge-code"><?= Security::e($item['category']) ?></span>
                        </div>
                        <h3 style="font-size: 1.05rem; margin-bottom: 0.4rem; color: var(--brand-cyan-light);">
                            <?= Security::formatInlineText($item['term']) ?>
                        </h3>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                            <?= Security::formatInlineText($item['definition']) ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Filter toolbar & SRS Focus Mode -->
    <div class="lms-card" style="margin-bottom: 1.25rem; padding: 1rem 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; flex: 1;">
                <select id="fc-filter-module" class="form-control" style="max-width: 190px;" aria-label="Filtrer par module">
                    <option value="">Tous les modules</option>
                    <?php foreach ($distinctModules as $modCode): ?>
                        <option value="<?= Security::e($modCode) ?>">Module <?= Security::e($modCode) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="fc-filter-level" class="form-control" style="max-width: 220px;" aria-label="Filtrer par niveau SRS">
                    <option value="">Tous les niveaux</option>
                    <option value="1">🔴 À revoir (1)</option>
                    <option value="2">🟡 En acquisition (2)</option>
                    <option value="3">🟢 Maîtrisé (3)</option>
                    <option value="0">⚪ Non évalué (0)</option>
                </select>

                <input type="search" id="fc-filter-search" class="form-control" style="max-width: 260px;" aria-label="Rechercher dans les flashcards" placeholder="Rechercher dans les flashcards..." value="<?= Security::e($filterQuery) ?>"/>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span id="fc-visible-counter" class="badge-code tabular-nums"><?= count($flashcards) ?> cartes affichées</span>
                <button type="button" id="btn-fc-focus-toggle" class="btn btn-outline-cyan btn-sm">
                    🎯 Mode Focus (Carte par Carte)
                </button>
                <button type="button" id="btn-fc-reset" class="btn btn-outline btn-sm" style="color: #F87171; border-color: rgba(248, 113, 113, 0.4);" title="Réinitialiser toutes vos auto-évaluations SRS pour ce cours">
                    🔄 Réinitialiser SRS
                </button>
            </div>
        </div>

        <div id="fc-focus-controls" style="display: none; margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid var(--border-hairline); justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <div class="badge-phase tabular-nums" id="fc-focus-position">Carte 1 / <?= count($flashcards) ?></div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" id="btn-fc-prev" class="btn btn-outline btn-sm">◀ Carte Préc.</button>
                <button type="button" id="btn-fc-flip" class="btn btn-primary btn-sm">👁️ Retourner</button>
                <button type="button" id="btn-fc-next" class="btn btn-outline btn-sm">Carte Suiv. ▶</button>
            </div>
        </div>
    </div>

    <div class="card-grid" id="flashcards-grid">
        <div id="fc-empty-state" class="lms-card" style="display: none; text-align: center; padding: 2rem; border-style: dashed; color: var(--text-muted); grid-column: 1 / -1;">
            <p style="margin: 0; font-size: 1rem;">🔍 Aucune flashcard ne correspond aux filtres sélectionnés.</p>
        </div>
        <?php foreach ($flashcards as $idx => $fc):
            $cid = $fc['id'];
            $modCode = (string)($fc['module'] ?? 'M01');
            $conf = (int)($userFcRatings[$cid] ?? 0);
            $statusLabel = match ($conf) {
                3 => '🟢 Maîtrisé',
                2 => '🟡 En acquisition',
                1 => '🔴 À revoir',
                default => '⚪ Non évalué',
            };
            $searchHaystack = strtolower($cid . ' ' . $modCode . ' ' . $fc['category'] . ' ' . $fc['front'] . ' ' . $fc['back']);
        ?>
            <article class="lms-card flashcard-box"
                     data-card-id="<?= Security::e($cid) ?>"
                     data-module="<?= Security::e($modCode) ?>"
                     data-level="<?= $conf ?>"
                     data-searchable="<?= Security::e($searchHaystack) ?>">
                <div>
                    <div class="card-top-meta">
                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <span class="badge-code tabular-nums">#<?= sprintf('%02d', $idx + 1) ?> • <?= Security::e($fc['category']) ?></span>
                            <span class="badge-code"><?= Security::e($modCode) ?></span>
                        </div>
                        <span class="badge-phase fc-status-badge"><?= $statusLabel ?></span>
                    </div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.65rem; color: var(--text-primary);">
                        ❓ <?= Security::formatInlineText($fc['front']) ?>
                    </h3>
                    <details class="lms-details" style="margin-bottom: 0.75rem;">
                        <summary class="lms-summary">👁️ Retourner la carte (Voir la réponse)</summary>
                        <div class="lms-details-body" style="font-size: 0.86rem; color: var(--text-secondary); white-space: pre-line;">
                            <?= Security::formatInlineText($fc['back']) ?>
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
