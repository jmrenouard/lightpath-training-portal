<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$questions = LmsCatalog::getDiagnosticQuestions($currentCourseSlug);
$diagTotalCount = count($questions);
$diagDomains = [];
foreach ($questions as $dq) {
    $diagDomains[(string)($dq['domain'] ?? '')] = true;
}
$diagDomainCount = max(1, count($diagDomains));
$diagDomainLabels = array_map(
    static fn(string $dk): string => LmsCatalog::DOMAINS[$dk] ?? $dk,
    array_keys($diagDomains)
);
$diagCourseLabel = $currentCourseSlug === 'postgresql-18'
    ? 'PostgreSQL 18'
    : (string)($currentCourse['short_title'] ?? $currentCourse['title'] ?? 'Formation');
$defaultDiagTrack = (string)(array_key_first($currentCourse['tracks'] ?? []) ?: 'cursus-complet');
$existingDiag = $storage->getDiagnosticResult($userId, $currentCourseSlug);
$showRetake = isset($_GET['retake']) && $_GET['retake'] === '1';
?>

<div class="hero-banner p-hero-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">QUALIOPI INDICATEUR 8 • POSITIONNEMENT INITIAL</span>
            <span class="badge-phase tabular-nums"><?= $diagTotalCount ?> Questions QCM • <?= $diagDomainCount ?> <?= $currentCourseSlug === 'postgresql-18' ? 'Domaines DBA' : 'Domaines' ?></span>
        </div>
        <h1 class="hero-title-md mb-xs">🧭 Évaluation Diagnostique & Positionnement Pré-Formation</h1>
        <p class="hero-subtitle-sm mb-0">
            <?php if ($currentCourseSlug === 'postgresql-18'): ?>
                Évaluez votre niveau initial sur les 5 piliers de PostgreSQL 18 (Architecture MVCC, SQL Avancé, Sécurité SCRAM/RLS, Sauvegardes/HA, Optimisation CBO & Kernel Linux) afin d'obtenir une recommandation personnalisée de parcours.
            <?php else: ?>
                Évaluez votre niveau initial sur les <?= $diagDomainCount ?> piliers de <?= Security::e($diagCourseLabel) ?> (<?= Security::e(implode(', ', $diagDomainLabels)) ?>) afin d'obtenir une recommandation personnalisée de parcours.
            <?php endif; ?>
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
    <!-- Saved diagnostic assessment result -->
    <div class="lms-card mb-1-75 lms-card--accent-cyan">
        <div class="d-flex justify-between items-center flex-wrap gap-md mb-md">
            <div>
                <span class="badge-completed">✔ Positionnement Qualiopi Complété le <?= Security::e(substr((string)($existingDiag['completed_at'] ?? date('c')), 0, 10)) ?></span>
                <h2 class="text-xl mt-xs mb-xxs">Profil Détecté : <?= Security::e((string)($existingDiag['level'] ?? '')) ?></h2>
                <p class="mb-0 text-secondary text-sm">
                    <?= Security::e((string)($existingDiag['recommendation_text'] ?? '')) ?>
                </p>
            </div>
            <div class="kpi-box-inset kpi-box-inset--wide">
                <div class="kpi-label">Score Initial</div>
                <div class="kpi-value tabular-nums text-cyan text-3xl">
                    <?= (int)($existingDiag['percent'] ?? 0) ?>%
                </div>
                <div class="kpi-sub tabular-nums"><?= (int)($existingDiag['score'] ?? 0) ?> / <?= (int)($existingDiag['total'] ?? $diagTotalCount) ?> réponses exactes</div>
            </div>
        </div>

        <!-- Competency breakdown by curriculum domain -->
        <h3 class="section-heading-sm mb-sm">📊 Profil de Compétences par Domaine<?= $currentCourseSlug === 'postgresql-18' ? ' DBA' : '' ?></h3>
        <div class="card-grid mb-md">
            <?php foreach (($existingDiag['domains'] ?? []) as $dKey => $dInfo): ?>
                <div class="domain-breakdown-card">
                    <div class="d-flex justify-between items-center mb-xs">
                        <strong class="text-sm"><?= Security::e((string)($dInfo['label'] ?? $dKey)) ?></strong>
                        <span class="badge-code tabular-nums"><?= (int)($dInfo['correct'] ?? 0) ?>/<?= (int)($dInfo['total'] ?? 3) ?> (<?= (int)($dInfo['percent'] ?? 0) ?>%)</span>
                    </div>
                    <div class="progress-bar-track progress-bar-track--sm">
                        <div class="progress-bar-fill" data-progress="<?= (int)($dInfo['percent'] ?? 0) ?>"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-between items-center flex-wrap gap-sm pt-1 border-top-hairline">
            <div>
                <strong>🎯 Parcours Recommandé :</strong>
                <span class="text-cyan font-bold"><?= Security::e((string)($existingDiag['recommended_title'] ?? '')) ?></span>
            </div>
            <a href="index.php?page=catalog&track=<?= urlencode((string)($existingDiag['recommended_track'] ?? $defaultDiagTrack)) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                🚀 Accéder au Parcours Recommandé →
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Diagnostic positioning questionnaire form -->
<form method="POST" action="index.php?page=diagnostic&action=submit_diagnostic&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
    <h2 class="section-heading-md mb-1">📋 Questionnaire de Positionnement Technique (<?= count($questions) ?> QCM)</h2>

    <?php foreach ($questions as $idx => $q):
        $qid = $q['id'];
        $prevDetail = $existingDiag['details'][$qid] ?? null;
    ?>
        <div class="quiz-question-box mb-md">
            <div class="d-flex justify-between items-center gap-xs mb-xs">
                <span class="badge-code tabular-nums">Question <?= sprintf('%02d', $idx + 1) ?> / <?= count($questions) ?></span>
                <span class="badge-phase"><?= Security::e(LmsCatalog::DOMAINS[$q['domain']] ?? $q['domain']) ?></span>
            </div>
            <div class="font-bold mb-sm text-primary">
                <?= Security::formatInlineText($q['question']) ?>
            </div>
            <?php foreach ($q['options'] as $letter => $optText):
                $checked = ($prevDetail !== null && ($prevDetail['given'] ?? '') === $letter);
            ?>
                <label class="quiz-option-label">
                    <input type="radio" name="answers[<?= Security::e($qid) ?>]" value="<?= Security::e($letter) ?>" <?= $checked ? 'checked' : '' ?> required class="quiz-option-radio"/>
                    <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::formatInlineText($optText) ?></span>
                </label>
            <?php endforeach; ?>

            <?php if ($prevDetail !== null && !$showRetake): ?>
                <div class="quiz-answer-feedback <?= !empty($prevDetail['is_correct']) ? 'quiz-answer-feedback--correct' : 'quiz-answer-feedback--wrong' ?>">
                    <strong><?= !empty($prevDetail['is_correct']) ? '✔ Réponse exacte' : '✘ Réponse attendue : ' . Security::e($q['correct']) ?></strong> — <?= Security::formatInlineText($q['explanation']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary btn-block-lg">
        🧭 Valider mon Positionnement Initial & Obtenir ma Recommandation de Parcours
    </button>
</form>
