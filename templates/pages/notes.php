<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$userNotes = $userProgress['notes'] ?? [];
if (!is_array($userNotes)) {
    $userNotes = [];
}
$nonEmptyNotes = array_filter($userNotes, static fn($v): bool => trim((string)$v) !== '');
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">TRAÇABILITÉ PERSONNELLE & RÉVISIONS TECHNIQUES</span>
            <span class="badge-phase tabular-nums"><?= count($nonEmptyNotes) ?> note(s) enregistrée(s)</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">📝 Mon Carnet de Notes & Révisions</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Retrouvez, filtrez et enrichissez l'ensemble de vos notes personnelles prises sur les 34 modules de cours et les 34 ateliers pratiques Docker, ou exportez votre carnet complet en Markdown.
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="index.php?action=export_notes_md&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm">
            📥 Exporter toutes mes notes (.md)
        </a>
    </div>
</div>

<!-- AJOUTER OU MODIFIER UNE NOTE RAPIDEMENT -->
<div class="lms-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <h2 style="font-size: 1.2rem; margin: 0;">➕ Ajouter ou mettre à jour une note sur un Module / Atelier</h2>
        <input type="search" id="notes-filter-input" class="form-control" style="max-width: 320px;" placeholder="Filtrer mes notes par mot-clé..."/>
    </div>

    <div style="display: grid; grid-template-columns: 1fr; gap: 0.75rem;">
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <select id="new-note-item-select" class="form-control" style="flex: 1; min-width: 280px;">
                <optgroup label="📚 Modules de Cours (<?= count($currentCourse['modules'] ?? []) ?>)">
                    <?php foreach (($currentCourse['modules'] ?? []) as $mId => $mod): ?>
                        <option value="<?= Security::e($mId) ?>">
                            <?= Security::e(($mod['badge_code'] ?? 'MOD') . ' — ' . ($mod['title'] ?? $mId)) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="🧪 Ateliers Pratiques Docker (<?= count($currentCourse['labs'] ?? []) ?>)">
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
        <textarea id="new-note-textarea" class="form-control" rows="3" placeholder="Saisissez vos commandes SQL clés, retours d'expérience ou points de vigilance de production..."></textarea>
        <div id="new-note-feedback" style="font-size: 0.82rem; color: #34D399; display: none;">✔ Note sauvegardée avec succès.</div>
    </div>
</div>

<!-- LISTE DES NOTES EXISTANTES -->
<?php if (empty($nonEmptyNotes)): ?>
    <div class="lms-card" style="text-align: center; padding: 2rem;">
        <p style="color: var(--text-secondary); margin: 0;">
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
                        <a href="<?= Security::e($itemUrl) ?>" class="btn btn-outline btn-sm" style="font-size: 0.74rem;">
                            📖 Ouvrir le <?= $isLab ? 'Lab' : 'Module' ?> →
                        </a>
                    </div>
                    <h3 style="font-size: 1.05rem; margin-bottom: 0.55rem;">
                        <a href="<?= Security::e($itemUrl) ?>" style="color: var(--text-primary);">
                            <?= Security::e($itemTitle) ?>
                        </a>
                    </h3>
                    <textarea class="form-control note-inline-editor" data-item-id="<?= Security::e((string)$itemId) ?>" rows="5" style="margin-bottom: 0.65rem;"><?= Security::e((string)$noteText) ?></textarea>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                    <span class="note-save-status" style="font-size: 0.78rem; color: var(--text-muted);">Modifiable en direct</span>
                    <button type="button" class="btn btn-primary btn-sm btn-save-inline-note" data-item-id="<?= Security::e((string)$itemId) ?>">
                        💾 Sauvegarder
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    var csrf = <?= json_encode($csrfToken) ?>;
    var course = <?= json_encode($currentCourseSlug) ?>;

    function saveNoteRequest(itemId, noteText, onDone) {
        fetch('index.php?action=api_save_note&course=' + encodeURIComponent(course), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({
                csrf_token: csrf,
                item_id: itemId,
                note: noteText
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (onDone) onDone(data); })
        .catch(function () {});
    }

    var newBtn = document.getElementById('btn-save-new-note');
    var newSelect = document.getElementById('new-note-item-select');
    var newArea = document.getElementById('new-note-textarea');
    var newFeedback = document.getElementById('new-note-feedback');

    if (newBtn && newSelect && newArea) {
        newBtn.addEventListener('click', function () {
            saveNoteRequest(newSelect.value, newArea.value, function (res) {
                if (res && res.success) {
                    if (newFeedback) newFeedback.style.display = 'block';
                    window.location.reload();
                }
            });
        });
    }

    document.querySelectorAll('.btn-save-inline-note').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('.note-item-card');
            if (!card) return;
            var itemId = btn.getAttribute('data-item-id') || '';
            var area = card.querySelector('.note-inline-editor');
            var status = card.querySelector('.note-save-status');
            if (!area) return;
            saveNoteRequest(itemId, area.value, function (res) {
                if (res && res.success && status) {
                    status.textContent = '✔ Enregistré à ' + (res.saved_at || '');
                    status.style.color = '#34D399';
                }
            });
        });
    });

    var filterInput = document.getElementById('notes-filter-input');
    var noteCards = document.querySelectorAll('.note-item-card');
    if (filterInput) {
        filterInput.addEventListener('input', function () {
            var q = filterInput.value.trim().toLowerCase();
            noteCards.forEach(function (c) {
                var hay = (c.getAttribute('data-searchable') || '').toLowerCase();
                c.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }
})();
</script>
