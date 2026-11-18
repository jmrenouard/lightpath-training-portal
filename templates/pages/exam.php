<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$questions = LmsCatalog::getFinalExamQuestions($currentCourseSlug);
$examTotalCount = count($questions);
$examPassCount = (int)ceil($examTotalCount * 0.75);
$examPointPerQ = $examTotalCount > 0 ? round(100 / $examTotalCount, 1) : 2.5;
$examPointFormatted = str_replace('.', ',', (string)$examPointPerQ);
$examCourseTitle = $currentCourseSlug === 'postgresql-18'
    ? 'PostgreSQL 18'
    : (string)($currentCourse['short_title'] ?? $currentCourse['title'] ?? 'Formation');
$examDomains = [];
foreach ($questions as $eq) {
    $examDomains[(string)($eq['domain'] ?? '')] = (string)($eq['domain_label'] ?? $eq['domain'] ?? '');
}
$examCourseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$bestExam = $storage->getBestExamAttempt($userId, $currentCourseSlug);
$allAttempts = $storage->getExamAttempts($userId, $currentCourseSlug);
$latestAttempt = !empty($allAttempts) ? $allAttempts[0] : null;
$showRetake = isset($_GET['retake']) && $_GET['retake'] === '1';
$classroomState = $storage->getClassroomState($currentCourseSlug);
$isExamLockedForStudent = !empty($classroomState['lock_exam']) && !$auth->isStaff($currentUser);
?>

<div class="hero-banner p-hero-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">QUALIOPI INDICATEUR 11 • ÉVALUATION SOMMATIVE CERTIFIANTE</span>
            <span class="badge-phase tabular-nums"><?= $examTotalCount ?> Questions Pondérées • Seuil de Réussite : 75% (<?= $examPassCount ?>/<?= $examTotalCount ?>)</span>
        </div>
        <h1 class="hero-title-md mb-xs">🏆 Examen Final de Certification <?= Security::e($examCourseTitle) ?></h1>
        <p class="hero-subtitle-sm mb-0">
            <?php if ($currentCourseSlug === 'postgresql-18'): ?>
                Validez officiellement l'ensemble des compétences opérationnelles du cursus PostgreSQL 18 (Architecture, SQL Avancé, Sécurité SCRAM/RLS, Sauvegardes PITR/HA, CBO & Kernel Linux Module 20).
            <?php else: ?>
                Validez officiellement l'ensemble des compétences opérationnelles de la formation <?= Security::e($examCourseTitle) ?> (<?= Security::e(implode(', ', array_values($examDomains))) ?>).
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex flex-col items-end gap-xs">
        <?php if (!$isExamLockedForStudent): ?>
            <div id="exam-timer-pill" class="badge-code tabular-nums exam-timer-pill">
                ⏱ Chronomètre : <span id="exam-timer-display">00:00</span>
            </div>
        <?php else: ?>
            <span class="badge-code badge-amber-soft">
                🔒 Session verrouillée par le formateur
            </span>
        <?php endif; ?>
        <?php if ($bestExam !== null): ?>
            <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                🎓 Voir mon Certificat Officiel
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($latestAttempt !== null && !$showRetake): ?>
    <div class="lms-card mb-1-75 <?= !empty($latestAttempt['passed']) ? 'lms-card--accent-emerald' : 'lms-card--accent-amber' ?>">
        <div class="d-flex justify-between items-center flex-wrap gap-md mb-md">
            <div>
                <span class="<?= !empty($latestAttempt['passed']) ? 'badge-completed' : 'badge-code' ?>">
                    <?= !empty($latestAttempt['passed']) ? '🎓 CERTIFICATION VALIDÉE' : '⚠️ SEUIL DE 75% NON ATTEINT' ?>
                </span>
                <h2 class="text-xl mt-xs mb-xxs">
                    <?= Security::e((string)($latestAttempt['mention'] ?? '')) ?>
                </h2>
                <div class="text-sm text-secondary tabular-nums">
                    Référence : <code><?= Security::e((string)($latestAttempt['certificate_id'] ?? '')) ?></code> •
                    Tentatives : <?= count($allAttempts) ?> •
                    Meilleur score : <strong><?= (int)($bestExam['percent'] ?? 0) ?>%</strong>
                </div>
            </div>
            <div class="kpi-box-inset kpi-box-inset--wide">
                <div class="kpi-label">Score Sommatif</div>
                <div class="kpi-value tabular-nums text-4xl <?= !empty($latestAttempt['passed']) ? 'text-emerald-bright' : 'text-amber-bright' ?>">
                    <?= (int)($latestAttempt['percent'] ?? 0) ?>%
                </div>
                <div class="kpi-sub tabular-nums"><?= (int)($latestAttempt['score'] ?? 0) ?> / <?= (int)($latestAttempt['total'] ?? $examTotalCount) ?> bonnes réponses</div>
            </div>
        </div>

        <div class="card-grid mb-md">
            <?php foreach (($latestAttempt['domains'] ?? []) as $dKey => $dInfo): ?>
                <div class="domain-breakdown-card">
                    <div class="d-flex justify-between items-center mb-xs">
                        <strong class="text-sm"><?= Security::e((string)($dInfo['label'] ?? $dKey)) ?></strong>
                        <span class="badge-code tabular-nums"><?= (int)($dInfo['correct'] ?? 0) ?>/<?= (int)($dInfo['total'] ?? 0) ?> (<?= (int)($dInfo['percent'] ?? 0) ?>%)</span>
                    </div>
                    <div class="progress-bar-track progress-bar-track--sm">
                        <div class="progress-bar-fill" data-progress="<?= (int)($dInfo['percent'] ?? 0) ?>"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-between items-center flex-wrap gap-sm">
            <?php if (!$isExamLockedForStudent): ?>
                <a href="index.php?page=exam&retake=1&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                    🔄 Nouvelle Tentative d'Examen
                </a>
            <?php endif; ?>
            <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                🎓 Ouvrir & Imprimer mon Certificat Qualiopi →
            </a>
        </div>
    </div>
<?php endif; ?>

<?php if ($isExamLockedForStudent): ?>
    <div class="lms-card lms-card--locked-center">
        <div class="exam-lock-icon">🔒</div>
        <h2 class="section-heading-lg mb-xs">
            🔒 L'examen final est temporairement verrouillé par le formateur jusqu'à la session d'évaluation officielle
        </h2>
        <p class="exam-lock-desc">
            Conformément au protocole d'évaluation sommative Qualiopi (Indicateur 11), le formateur déverrouille l'épreuve certifiante de <?= $examTotalCount ?> QCM lors de la séquence d'examen. En attendant, vous pouvez consolider vos acquis avec les flashcards SRS et les <?= $examCourseHasDocker ? 'ateliers Docker' : 'ateliers pratiques' ?>.
        </p>
        <div class="d-flex justify-center gap-sm flex-wrap">
            <a href="index.php?page=flashcards&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm">
                🧠 Réviser avec les Flashcards SRS
            </a>
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                📚 Retourner au Catalogue des Modules
            </a>
        </div>
    </div>
<?php else: ?>
<form id="final-exam-form" method="POST" action="index.php?page=exam&action=submit_exam&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
    <input type="hidden" name="duration_seconds" id="exam-duration-seconds" value="0"/>

    <div class="d-flex justify-between items-center flex-wrap gap-sm mb-md">
        <h2 class="section-heading-md mb-0">📝 Épreuve Certifiante (<?= $examTotalCount ?> Questions QCM)</h2>
        <span class="text-sm text-secondary">Chaque question vaut <?= Security::e($examPointFormatted) ?> points sur 100.</span>
    </div>

    <?php foreach ($questions as $q):
        $qid = $q['id'];
        $prevDetail = ($latestAttempt !== null && !$showRetake) ? ($latestAttempt['details'][$qid] ?? null) : null;
    ?>
        <div class="quiz-question-box mb-md">
            <div class="d-flex justify-between items-center gap-xs mb-xs">
                <span class="badge-code tabular-nums">Question <?= sprintf('%02d', (int)$q['number']) ?> / <?= $examTotalCount ?></span>
                <span class="badge-phase"><?= Security::e($q['domain_label']) ?></span>
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

            <?php if ($prevDetail !== null): ?>
                <div class="quiz-answer-feedback <?= !empty($prevDetail['is_correct']) ? 'quiz-answer-feedback--correct' : 'quiz-answer-feedback--wrong' ?>">
                    <strong><?= !empty($prevDetail['is_correct']) ? '✔ Correct' : '✘ Réponse exacte : ' . Security::e($q['correct']) ?></strong> — <?= Security::formatInlineText($q['explanation']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-emerald w-100 btn-submit-lg">
        🏆 Soumettre mon Examen Final de Certification <?= Security::e($examCourseTitle) ?>
    </button>
</form>
<?php endif; ?>
