<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$userNotes = $userProgress['notes'] ?? [];
if (!is_array($userNotes)) {
    $userNotes = [];
}
$nonEmptyNotes = array_filter($userNotes, static fn($v): bool => trim((string)$v) !== '');
$courseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$courseDockerEnabled = $courseHasDocker && (!isset($featureManager) || $featureManager->isEnabledForCourse('docker_labs', $currentCourseSlug));
?>

<div class="hero-banner hero-banner-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">TRAÇABILITÉ PERSONNELLE & RÉVISIONS TECHNIQUES</span>
            <span class="badge-phase tabular-nums"><?= count($nonEmptyNotes) ?> note(s) enregistrée(s)</span>
        </div>
        <h1 class="hero-title-md mb-xxs">📝 Mon Carnet de Notes & Révisions</h1>
        <p class="hero-subtitle-sm mb-0">
            Retrouvez, filtrez et enrichissez l'ensemble de vos notes personnelles prises sur les <?= count($currentCourse['modules'] ?? []) ?> modules de cours et les <?= count($currentCourse['labs'] ?? []) ?> <?= $courseDockerEnabled ? 'ateliers pratiques Docker' : 'ateliers pratiques' ?>, ou exportez votre carnet complet en Markdown.
        </p>
    </div>
    <div class="d-flex gap-sm flex-wrap">
        <a href="index.php?action=export_notes_md&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm">
            📥 Exporter toutes mes notes (.md)
        </a>
    </div>
</div>

<!-- Quick create or update a personal study note -->
<div class="lms-card mb-lg">
    <div class="d-flex justify-between items-center flex-wrap gap-1 mb-1">
        <h2 class="section-heading-md mb-0">➕ Ajouter ou mettre à jour une note sur un Module / Atelier</h2>
        <input type="search" id="notes-filter-input" class="form-control search-inline-input" aria-label="Filtrer mes notes par mot-clé" placeholder="Filtrer mes notes par mot-clé..."/>
    </div>

    <div class="notes-composer-grid">
        <div class="d-flex gap-sm flex-wrap">
            <select id="new-note-item-select" class="form-control flex-1 search-query-input" aria-label="Module ou atelier cible pour la note">
                <optgroup label="📚 Modules de Cours (<?= count($currentCourse['modules'] ?? []) ?>)">
                    <?php foreach (($currentCourse['modules'] ?? []) as $mId => $mod): ?>
                        <option value="<?= Security::e($mId) ?>">
                            <?= Security::e(($mod['badge_code'] ?? 'MOD') . ' — ' . ($mod['title'] ?? $mId)) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="<?= $courseDockerEnabled ? '🧪 Ateliers Pratiques Docker' : '🧪 Ateliers Pratiques' ?> (<?= count($currentCourse['labs'] ?? []) ?>)">
                    <?php foreach (($currentCourse['labs'] ?? []) as $lId => $lab): ?>
                        <option value="<?= Security::e($lId) ?>">
                            <?= Security::e(($lab['badge_code'] ?? 'LAB') . ' — ' . ($lab['title'] ?? $lId)) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <button type="button" id="btn-save-new-note" class="btn btn-emerald">
                💾 Enregistrer la note
            </button>
        </div>
        <textarea id="new-note-textarea" class="form-control" rows="3" aria-label="Contenu de la nouvelle note" placeholder="<?= $currentCourseSlug === 'postgresql-18' ? "Saisissez vos commandes SQL clés, retours d'expérience ou points de vigilance de production..." : "Saisissez vos commandes clés, retours d'expérience ou points de vigilance pratiques..." ?>"></textarea>
        <div id="new-note-feedback" class="text-sm text-emerald" hidden>✔ Note sauvegardée avec succès.</div>
    </div>
</div>

<!-- Saved study notes list -->
<?php if (empty($nonEmptyNotes)): ?>
    <div class="lms-card text-center p-lg">
        <p class="text-secondary mb-0">
            Aucune note enregistrée pour le moment. Utilisez le formulaire ci-dessus ou le bloc de prise de notes présent dans chaque module et atelier pratique.
        </p>
    </div>
<?php else: ?>
    <div class="card-grid" id="notes-cards-grid">
        <?php foreach ($nonEmptyNotes as $itemId => $noteText):
            $isLab = isset($currentCourse['labs'][$itemId]);
            $itemObj = $currentCourse['modules'][$itemId] ?? $currentCourse['labs'][$itemId] ?? null;
            $itemTitle = (string)($itemObj['title'] ?? $itemId);
            $itemBadge = (string)($itemObj['badge_code'] ?? ($isLab ? 'LAB' : 'MODULE'));
            $itemUrl = 'index.php?page=' . ($isLab ? 'lab' : 'module') . '&id=' . urlencode((string)$itemId) . '&course=' . urlencode($currentCourseSlug);
            $searchable = strtolower($itemId . ' ' . $itemBadge . ' ' . $itemTitle . ' ' . $noteText);
        ?>
            <article class="lms-card note-item-card" data-searchable="<?= Security::e($searchable) ?>" data-item-id="<?= Security::e((string)$itemId) ?>">
                <div>
                    <div class="card-top-meta">
                        <span class="badge-code"><?= Security::e($itemBadge) ?></span>
                        <a href="<?= Security::e($itemUrl) ?>" class="btn btn-outline btn-xs">
                            📖 Ouvrir le <?= $isLab ? 'Lab' : 'Module' ?> →
                        </a>
                    </div>
                    <h3 class="card-heading-sm mb-sm">
                        <a href="<?= Security::e($itemUrl) ?>" class="link-primary">
                            <?= Security::e($itemTitle) ?>
                        </a>
                    </h3>
                    <textarea class="form-control note-inline-editor mb-xs" data-item-id="<?= Security::e((string)$itemId) ?>" rows="5" aria-label="Note pour <?= Security::e($itemTitle) ?>"><?= Security::e((string)$noteText) ?></textarea>
                </div>
                <div class="d-flex justify-between items-center gap-xs">
                    <span class="note-save-status text-xs text-muted">Modifiable en direct</span>
                    <button type="button" class="btn btn-primary btn-sm btn-save-inline-note" data-item-id="<?= Security::e((string)$itemId) ?>">
                        💾 Sauvegarder
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
