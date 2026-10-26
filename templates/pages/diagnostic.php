<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$questions = LmsCatalog::getDiagnosticQuestions();
$existingDiag = $storage->getDiagnosticResult($userId, $currentCourseSlug);
$showRetake = isset($_GET['retake']) && $_GET['retake'] === '1';
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">QUALIOPI INDICATEUR 8 • POSITIONNEMENT INITIAL</span>
            <span class="badge-phase">15 Questions QCM • 5 Domaines DBA PostgreSQL 18</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">🧭 Évaluation Diagnostique & Positionnement Pré-Formation</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Évaluez votre niveau initial sur les 5 piliers de PostgreSQL 18 (Architecture MVCC, SQL Avancé, Sécurité SCRAM/RLS, Sauvegardes/HA, Optimisation CBO & Kernel Linux) afin d'obtenir une recommandation personnalisée de parcours.
        </p>
    </div>
    <?php if ($existingDiag !== null && !$showRetake): ?>
        <div>
            <a href="index.php?page=diagnostic&retake=1&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                🔄 Repasser le Diagnostic
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if ($existingDiag !== null && !$showRetake): ?>
    <!-- RÉSULTAT DU DIAGNOSTIC ENREGISTRÉ -->
    <div class="lms-card" style="margin-bottom: 1.75rem; border-color: var(--brand-cyan);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <span class="badge-completed">✔ Positionnement Qualiopi Complété le <?= Security::e(substr((string)($existingDiag['completed_at'] ?? date('c')), 0, 10)) ?></span>
                <h2 style="font-size: 1.35rem; margin: 0.45rem 0 0.2rem;">Profil Détecté : <?= Security::e((string)($existingDiag['level'] ?? '')) ?></h2>
                <p style="margin: 0; color: var(--text-secondary); font-size: 0.9rem;">
                    <?= Security::e((string)($existingDiag['recommendation_text'] ?? '')) ?>
                </p>
            </div>
            <div style="text-align: center; background: var(--bg-base); padding: 1rem 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-strong);">
                <div class="kpi-label">Score Initial</div>
                <div class="kpi-value tabular-nums" style="color: var(--brand-cyan-light); font-size: 2.1rem;">
                    <?= (int)($existingDiag['percent'] ?? 0) ?>%
                </div>
                <div class="kpi-sub tabular-nums"><?= (int)($existingDiag['score'] ?? 0) ?> / <?= (int)($existingDiag['total'] ?? 15) ?> réponses exactes</div>
            </div>
        </div>

        <!-- Barres de compétences par domaine -->
        <h3 style="font-size: 1.05rem; margin-bottom: 0.75rem;">📊 Profil de Compétences par Domaine DBA</h3>
        <div class="card-grid" style="margin-bottom: 1.25rem;">
            <?php foreach (($existingDiag['domains'] ?? []) as $dKey => $dInfo): ?>
                <div style="background: var(--bg-base); padding: 0.9rem 1.1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-hairline);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                        <strong style="font-size: 0.85rem;"><?= Security::e((string)($dInfo['label'] ?? $dKey)) ?></strong>
                        <span class="badge-code tabular-nums"><?= (int)($dInfo['correct'] ?? 0) ?>/<?= (int)($dInfo['total'] ?? 3) ?> (<?= (int)($dInfo['percent'] ?? 0) ?>%)</span>
                    </div>
                    <div class="progress-bar-track" style="height: 6px;">
                        <div class="progress-bar-fill" style="width: <?= (int)($dInfo['percent'] ?? 0) ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid var(--border-hairline);">
            <div>
                <strong>🎯 Parcours Recommandé :</strong>
                <span style="color: var(--brand-cyan-light); font-weight: 700;"><?= Security::e((string)($existingDiag['recommended_title'] ?? '')) ?></span>
            </div>
            <a href="index.php?page=catalog&track=<?= urlencode((string)($existingDiag['recommended_track'] ?? 'cursus-complet')) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                🚀 Accéder au Parcours Recommandé →
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- FORMULAIRE DES 15 QUESTIONS DE POSITIONNEMENT -->
<form method="POST" action="index.php?page=diagnostic&action=submit_diagnostic&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">📋 Questionnaire de Positionnement Technique (15 QCM)</h2>

    <?php foreach ($questions as $idx => $q):
        $qid = $q['id'];
        $prevDetail = $existingDiag['details'][$qid] ?? null;
    ?>
        <div class="quiz-question-box" style="margin-bottom: 1.15rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.45rem;">
                <span class="badge-code tabular-nums">Question <?= sprintf('%02d', $idx + 1) ?> / <?= count($questions) ?></span>
                <span class="badge-phase"><?= Security::e(LmsCatalog::DOMAINS[$q['domain']] ?? $q['domain']) ?></span>
            </div>
            <div style="font-weight: 700; margin-bottom: 0.65rem; color: var(--text-primary);">
                <?= Security::e($q['question']) ?>
            </div>
            <?php foreach ($q['options'] as $letter => $optText):
                $checked = ($prevDetail !== null && ($prevDetail['given'] ?? '') === $letter);
            ?>
                <label class="quiz-option-label">
                    <input type="radio" name="answers[<?= Security::e($qid) ?>]" value="<?= Security::e($letter) ?>" <?= $checked ? 'checked' : '' ?> required style="margin-top: 0.25rem;"/>
                    <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::e($optText) ?></span>
                </label>
            <?php endforeach; ?>

            <?php if ($prevDetail !== null && !$showRetake): ?>
                <div style="margin-top: 0.75rem; padding: 0.65rem 0.9rem; border-radius: var(--radius-sm); background: <?= !empty($prevDetail['is_correct']) ? 'rgba(16, 185, 129, 0.12)' : 'rgba(244, 63, 94, 0.12)' ?>; border-left: 3px solid <?= !empty($prevDetail['is_correct']) ? '#10B981' : '#F43F5E' ?>; font-size: 0.84rem;">
                    <strong><?= !empty($prevDetail['is_correct']) ? '✔ Réponse exacte' : '✘ Réponse attendue : ' . Security::e($q['correct']) ?></strong> — <?= Security::e($q['explanation']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem;">
        🧭 Valider mon Positionnement Initial & Obtenir ma Recommandation de Parcours
    </button>
</form>
