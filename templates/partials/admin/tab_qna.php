<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin Q&A moderation partial: lists module/lab technical threads with resolve and delete actions.
 */
?>
<div class="admin-toolbar mb-1">
    <div>
        <h2 class="section-heading-md mb-0">💬 Modération du Forum d'Entraide Technique &amp; Q&amp;A (<span class="tabular-nums"><?= count($allCourseDiscussions) ?></span> messages)</h2>
        <p class="text-sm text-secondary mt-xs mb-0">
            Visualisez toutes les questions posées sur les modules et ateliers, marquez-les comme résolues ou supprimez les entrées obsolètes.
        </p>
    </div>
</div>
<div class="lms-table-wrap">
    <table class="lms-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Élément (Module / Lab)</th>
                <th>Auteur</th>
                <th>Question / Message</th>
                <th>Statut</th>
                <th>Actions Formateur</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($allCourseDiscussions)): ?>
                <tr><td colspan="6">Aucune question publiée pour le moment sur cette formation.</td></tr>
            <?php else: ?>
                <?php foreach ($allCourseDiscussions as $disc):
                    $dItemId = (string)($disc['item_id'] ?? '');
                    $dMsgId = (string)($disc['id'] ?? '');
                    $dIsLab = str_starts_with($dItemId, 'lab_');
                    $dTargetPage = $dIsLab ? 'lab' : 'module';
                    $dResolved = !empty($disc['resolved']);
                ?>
                    <tr>
                        <td class="tabular-nums table-col-nowrap"><code><?= Security::e(substr((string)($disc['created_at'] ?? ''), 0, 16)) ?></code></td>
                        <td class="table-col-nowrap">
                            <a href="index.php?page=<?= $dTargetPage ?>&id=<?= urlencode($dItemId) ?>&course=<?= urlencode($currentCourseSlug) ?>#discussion-section" class="badge-code">
                                <?= $dIsLab ? '🧪 ' : '📖 ' ?><?= Security::e($dItemId) ?>
                            </a>
                        </td>
                        <td>
                            <strong><?= Security::e((string)($disc['user_name'] ?? 'Apprenant')) ?></strong>
                            <span class="role-badge role-<?= Security::e((string)($disc['user_role'] ?? 'student')) ?>"><?= Security::e((string)($disc['user_role'] ?? 'student')) ?></span>
                        </td>
                        <td class="qna-message-cell"><?= Security::e((string)($disc['message'] ?? '')) ?></td>
                        <td class="table-col-nowrap">
                            <?= $dResolved
                                ? '<span class="badge-completed">✔ Résolu</span>'
                                : '<span class="badge-phase">⏳ Ouvert</span>' ?>
                        </td>
                        <td class="table-col-nowrap">
                            <form method="POST" action="index.php?page=admin&tab=qna&action=moderate_discussion&course=<?= urlencode($currentCourseSlug) ?>" class="d-inline-flex gap-xs">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                <input type="hidden" name="item_id" value="<?= Security::e($dItemId) ?>"/>
                                <input type="hidden" name="message_id" value="<?= Security::e($dMsgId) ?>"/>
                                <input type="hidden" name="return_page" value="admin"/>
                                <button type="submit" name="mod_action" value="resolve" class="btn btn-emerald btn-sm">
                                    <?= $dResolved ? '↩ Rouvrir' : '✔ Résolu' ?>
                                </button>
                                <button type="submit" name="mod_action" value="delete" class="btn btn-danger btn-sm" aria-label="Supprimer">
                                    🗑️
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
