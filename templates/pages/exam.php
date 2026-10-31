<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\LmsCatalog;

$questions = LmsCatalog::getFinalExamQuestions();
$bestExam = $storage->getBestExamAttempt($userId, $currentCourseSlug);
$allAttempts = $storage->getExamAttempts($userId, $currentCourseSlug);
$latestAttempt = !empty($allAttempts) ? $allAttempts[0] : null;
$showRetake = isset($_GET['retake']) && $_GET['retake'] === '1';
$classroomState = $storage->getClassroomState($currentCourseSlug);
$isExamLockedForStudent = !empty($classroomState['lock_exam']) && (($currentUser['role'] ?? 'student') === 'student');
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">QUALIOPI INDICATEUR 11 • ÉVALUATION SOMMATIVE CERTIFIANTE</span>
            <span class="badge-phase tabular-nums">40 Questions Pondérées • Seuil de Réussite : 75% (30/40)</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">🏆 Examen Final de Certification PostgreSQL 18</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Validez officiellement l'ensemble des compétences opérationnelles du cursus PostgreSQL 18 (Architecture, SQL Avancé, Sécurité SCRAM/RLS, Sauvegardes PITR/HA, CBO & Kernel Linux Module 20).
        </p>
    </div>

    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
        <?php if (!$isExamLockedForStudent): ?>
            <div id="exam-timer-pill" class="badge-code tabular-nums" style="font-size: 0.95rem; padding: 0.45rem 0.9rem;">
                ⏱ Chronomètre : <span id="exam-timer-display">00:00</span>
            </div>
        <?php else: ?>
            <span class="badge-code" style="background: rgba(245, 158, 11, 0.15); color: #FBBF24; border-color: rgba(245, 158, 11, 0.35);">
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
    <div class="lms-card" style="margin-bottom: 1.75rem; border-color: <?= !empty($latestAttempt['passed']) ? '#10B981' : '#F59E0B' ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <span class="<?= !empty($latestAttempt['passed']) ? 'badge-completed' : 'badge-code' ?>">
                    <?= !empty($latestAttempt['passed']) ? '🎓 CERTIFICATION VALIDÉE' : '⚠️ SEUIL DE 75% NON ATTEINT' ?>
                </span>
                <h2 style="font-size: 1.35rem; margin: 0.45rem 0 0.2rem;">
                    <?= Security::e((string)($latestAttempt['mention'] ?? '')) ?>
                </h2>
                <div style="font-size: 0.84rem; color: var(--text-secondary);" class="tabular-nums">
                    Référence : <code><?= Security::e((string)($latestAttempt['certificate_id'] ?? '')) ?></code> •
                    Tentatives : <?= count($allAttempts) ?> •
                    Meilleur score : <strong><?= (int)($bestExam['percent'] ?? 0) ?>%</strong>
                </div>
            </div>
            <div style="text-align: center; background: var(--bg-base); padding: 1rem 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-strong);">
                <div class="kpi-label">Score Sommatif</div>
                <div class="kpi-value tabular-nums" style="color: <?= !empty($latestAttempt['passed']) ? '#34D399' : '#FBBF24' ?>; font-size: 2.2rem;">
                    <?= (int)($latestAttempt['percent'] ?? 0) ?>%
                </div>
                <div class="kpi-sub tabular-nums"><?= (int)($latestAttempt['score'] ?? 0) ?> / <?= (int)($latestAttempt['total'] ?? 40) ?> bonnes réponses</div>
            </div>
        </div>

        <div class="card-grid" style="margin-bottom: 1.25rem;">
            <?php foreach (($latestAttempt['domains'] ?? []) as $dKey => $dInfo): ?>
                <div style="background: var(--bg-base); padding: 0.85rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-hairline);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <strong style="font-size: 0.84rem;"><?= Security::e((string)($dInfo['label'] ?? $dKey)) ?></strong>
                        <span class="badge-code tabular-nums"><?= (int)($dInfo['correct'] ?? 0) ?>/<?= (int)($dInfo['total'] ?? 8) ?> (<?= (int)($dInfo['percent'] ?? 0) ?>%)</span>
                    </div>
                    <div class="progress-bar-track" style="height: 6px;">
                        <div class="progress-bar-fill" style="width: <?= (int)($dInfo['percent'] ?? 0) ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
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
    <div class="lms-card" style="border-color: #F59E0B; text-align: center; padding: 2.25rem 1.5rem;">
        <div style="font-size: 2.4rem; margin-bottom: 0.5rem;">🔒</div>
        <h2 style="font-size: 1.3rem; margin-bottom: 0.45rem;">
            🔒 L'examen final est temporairement verrouillé par le formateur jusqu'à la session d'évaluation officielle
        </h2>
        <p style="color: var(--text-secondary); max-width: 640px; margin: 0 auto 1.25rem; font-size: 0.9rem;">
            Conformément au protocole d'évaluation sommative Qualiopi (Indicateur 11), le formateur déverrouille l'épreuve certifiante de 40 QCM lors de la séquence d'examen. En attendant, vous pouvez consolider vos acquis avec les flashcards SRS et les ateliers Docker.
        </p>
        <div style="display: flex; justify-content: center; gap: 0.65rem; flex-wrap: wrap;">
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

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.25rem;">
        <h2 style="font-size: 1.25rem; margin: 0;">📝 Épreuve Certifiante (40 Questions QCM)</h2>
        <span style="font-size: 0.84rem; color: var(--text-secondary);">Chaque question vaut 2,5 points sur 100.</span>
    </div>

    <?php foreach ($questions as $q):
        $qid = $q['id'];
        $prevDetail = ($latestAttempt !== null && !$showRetake) ? ($latestAttempt['details'][$qid] ?? null) : null;
    ?>
        <div class="quiz-question-box" style="margin-bottom: 1.1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.45rem;">
                <span class="badge-code tabular-nums">Question <?= sprintf('%02d', (int)$q['number']) ?> / 40</span>
                <span class="badge-phase"><?= Security::e($q['domain_label']) ?></span>
            </div>
            <div style="font-weight: 700; margin-bottom: 0.65rem; color: var(--text-primary);">
                <?= Security::formatInlineText($q['question']) ?>
            </div>
            <?php foreach ($q['options'] as $letter => $optText):
                $checked = ($prevDetail !== null && ($prevDetail['given'] ?? '') === $letter);
            ?>
                <label class="quiz-option-label">
                    <input type="radio" name="answers[<?= Security::e($qid) ?>]" value="<?= Security::e($letter) ?>" <?= $checked ? 'checked' : '' ?> required style="margin-top: 0.25rem;"/>
                    <span><strong><?= Security::e($letter) ?>.</strong> <?= Security::formatInlineText($optText) ?></span>
                </label>
            <?php endforeach; ?>

            <?php if ($prevDetail !== null): ?>
                <div style="margin-top: 0.75rem; padding: 0.65rem 0.9rem; border-radius: var(--radius-sm); background: <?= !empty($prevDetail['is_correct']) ? 'rgba(16, 185, 129, 0.12)' : 'rgba(244, 63, 94, 0.12)' ?>; border-left: 3px solid <?= !empty($prevDetail['is_correct']) ? '#10B981' : '#F43F5E' ?>; font-size: 0.84rem;">
                    <strong><?= !empty($prevDetail['is_correct']) ? '✔ Correct' : '✘ Réponse exacte : ' . Security::e($q['correct']) ?></strong> — <?= Security::formatInlineText($q['explanation']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-emerald" style="width: 100%; padding: 0.9rem; font-size: 1rem;">
        🏆 Soumettre mon Examen Final de Certification PostgreSQL 18
    </button>
</form>

<script>
(function () {
    var startTs = Date.now();
    var display = document.getElementById('exam-timer-display');
    var input = document.getElementById('exam-duration-seconds');
    setInterval(function () {
        var elapsed = Math.floor((Date.now() - startTs) / 1000);
        if (input) input.value = String(elapsed);
        if (display) {
            var m = String(Math.floor(elapsed / 60)).padStart(2, '0');
            var s = String(elapsed % 60).padStart(2, '0');
            display.textContent = m + ':' + s;
        }
    }, 1000);
})();
</script>
<?php endif; ?>
