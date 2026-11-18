<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$activeTab = (string)($_GET['tab'] ?? 'flashcards');
$filterQuery = trim((string)($_GET['q'] ?? ''));
$flashcards = LmsCatalog::getFlashcards($currentCourseSlug);
$glossary = LmsCatalog::getGlossary($currentCourseSlug);
$fcCourseLabel = $currentCourseSlug === 'postgresql-18'
    ? 'PostgreSQL 18'
    : (string)($currentCourse['short_title'] ?? $currentCourse['title'] ?? 'Formation');
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

<div class="hero-banner p-hero-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">MÉMORISATION ACTIVE SRS & RÉFÉRENTIEL TERMINOLOGIQUE</span>
            <span class="badge-phase tabular-nums"><?= count($flashcards) ?> Flashcards SRS • <?= count($glossary) ?> Termes Glossaire</span>
        </div>
        <h1 class="hero-title-md mb-xs">🧠 Cartes Mémo SRS (Spaced Repetition) & Glossaire<?= $currentCourseSlug === 'postgresql-18' ? ' DBA' : ' Technique' ?></h1>
        <p class="hero-subtitle-sm mb-0">
            <?php if ($currentCourseSlug === 'postgresql-18'): ?>
                Révisez activement les concepts critiques de PostgreSQL 18 (MVCC, AIO, <code>pg_stat_io</code>, Indexation, Patroni, <code>pgBackRest</code>, Kernel Linux Module 20) et auto-évaluez votre niveau de maîtrise.
            <?php else: ?>
                Révisez activement les concepts critiques de <?= Security::e($fcCourseLabel) ?> et auto-évaluez votre niveau de maîtrise.
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-xs flex-wrap">
        <a href="index.php?page=flashcards&tab=flashcards&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $activeTab === 'flashcards' ? 'btn-primary' : 'btn-outline' ?>">
            🃏 Cartes Mémo SRS (<span id="fc-mastered-count"><?= $masteredCount ?></span>/<?= count($flashcards) ?> maîtrisées)
        </a>
        <a href="index.php?page=flashcards&tab=glossary&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $activeTab === 'glossary' ? 'btn-primary' : 'btn-outline' ?>">
            📖 Glossaire Technique (<?= count($glossary) ?> termes)
        </a>
    </div>
</div>

<?php if ($activeTab === 'glossary'): ?>
    <div class="lms-card mb-lg">
        <div class="d-flex justify-between items-center flex-wrap gap-md mb-1">
            <h2 class="section-heading-md mb-0">📖 Glossaire Technique <?= Security::e($fcCourseLabel) ?> (<?= count($glossary) ?> définitions)</h2>
            <input type="search" id="glossary-filter-input" class="form-control search-inline-input" aria-label="Filtrer les termes du glossaire" placeholder="<?= $currentCourseSlug === 'postgresql-18' ? 'Filtrer un terme (ex: MVCC, HOT, AIO, WAL)...' : 'Filtrer un terme (ex: commit, HEAD, merge, remote)...' ?>" value="<?= Security::e($filterQuery) ?>"/>
        </div>

        <div class="card-grid" id="glossary-cards-grid">
            <?php foreach ($glossary as $item): ?>
                <article class="lms-card glossary-item-card" data-searchable="<?= Security::e(strtolower($item['term'] . ' ' . $item['category'] . ' ' . $item['definition'])) ?>">
                    <div>
                        <div class="card-top-meta">
                            <span class="badge-code"><?= Security::e($item['category']) ?></span>
                        </div>
                        <h3 class="section-heading-sm mb-xs text-cyan">
                            <?= Security::formatInlineText($item['term']) ?>
                        </h3>
                        <p class="text-sm text-secondary mb-0">
                            <?= Security::formatInlineText($item['definition']) ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Filter toolbar & SRS Focus Mode -->
    <div class="lms-card mb-md p-card-compact">
        <div class="d-flex justify-between items-center flex-wrap gap-sm">
            <div class="d-flex items-center gap-xs flex-wrap flex-1">
                <select id="fc-filter-module" class="form-control fc-select-module" aria-label="Filtrer par module">
                    <option value="">Tous les modules</option>
                    <?php foreach ($distinctModules as $modCode): ?>
                        <option value="<?= Security::e($modCode) ?>">Module <?= Security::e($modCode) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="fc-filter-level" class="form-control fc-select-level" aria-label="Filtrer par niveau SRS">
                    <option value="">Tous les niveaux</option>
                    <option value="1">🔴 À revoir (1)</option>
                    <option value="2">🟡 En acquisition (2)</option>
                    <option value="3">🟢 Maîtrisé (3)</option>
                    <option value="0">⚪ Non évalué (0)</option>
                </select>

                <input type="search" id="fc-filter-search" class="form-control fc-search-input" aria-label="Rechercher dans les flashcards" placeholder="Rechercher dans les flashcards..." value="<?= Security::e($filterQuery) ?>"/>
            </div>

            <div class="d-flex items-center gap-xs flex-wrap">
                <span id="fc-visible-counter" class="badge-code tabular-nums"><?= count($flashcards) ?> cartes affichées</span>
                <button type="button" id="btn-fc-focus-toggle" class="btn btn-outline-cyan btn-sm">
                    🎯 Mode Focus (Carte par Carte)
                </button>
                <button type="button" id="btn-fc-reset" class="btn btn-outline btn-sm btn-danger-outline" title="Réinitialiser toutes vos auto-évaluations SRS pour ce cours">
                    🔄 Réinitialiser SRS
                </button>
            </div>
        </div>

        <div id="fc-focus-controls" class="fc-focus-controls">
            <div class="badge-phase tabular-nums" id="fc-focus-position">Carte 1 / <?= count($flashcards) ?></div>
            <div class="d-flex gap-xs flex-wrap">
                <button type="button" id="btn-fc-prev" class="btn btn-outline btn-sm">◀ Carte Préc.</button>
                <button type="button" id="btn-fc-flip" class="btn btn-primary btn-sm">👁️ Retourner</button>
                <button type="button" id="btn-fc-next" class="btn btn-outline btn-sm">Carte Suiv. ▶</button>
            </div>
        </div>
    </div>

    <div class="card-grid" id="flashcards-grid">
        <div id="fc-empty-state" class="lms-card fc-empty-state">
            <p class="mb-0 text-base">🔍 Aucune flashcard ne correspond aux filtres sélectionnés.</p>
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
                        <div class="d-flex items-center gap-xs flex-wrap">
                            <span class="badge-code tabular-nums">#<?= sprintf('%02d', $idx + 1) ?> • <?= Security::e($fc['category']) ?></span>
                            <span class="badge-code"><?= Security::e($modCode) ?></span>
                        </div>
                        <span class="badge-phase fc-status-badge"><?= $statusLabel ?></span>
                    </div>
                    <h3 class="text-base mb-sm text-primary">
                        ❓ <?= Security::formatInlineText($fc['front']) ?>
                    </h3>
                    <details class="lms-details mb-sm">
                        <summary class="lms-summary">👁️ Retourner la carte (Voir la réponse)</summary>
                        <div class="lms-details-body text-sm text-secondary whitespace-pre-line"><?= Security::formatInlineText(str_replace('\n', "\n", (string)$fc['back'])) ?></div>
                    </details>
                </div>

                <div class="fc-rating-bar">
                    <button type="button" class="btn btn-outline btn-sm btn-fc-rate fc-rate-btn" data-card-id="<?= Security::e($cid) ?>" data-level="1">
                        🔴 À revoir
                    </button>
                    <button type="button" class="btn btn-outline btn-sm btn-fc-rate fc-rate-btn" data-card-id="<?= Security::e($cid) ?>" data-level="2">
                        🟡 Moyen
                    </button>
                    <button type="button" class="btn btn-emerald btn-sm btn-fc-rate fc-rate-btn" data-card-id="<?= Security::e($cid) ?>" data-level="3">
                        🟢 Maîtrisé
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
