<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$existingSurvey = $storage->getUserSatisfaction($userId, $currentCourseSlug);
$r = $existingSurvey['ratings'] ?? [];
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">QUALIOPI INDICATEUR 30 & 32 • AMÉLIORATION CONTINUE</span>
            <?php if ($existingSurvey !== null): ?>
                <span class="badge-completed tabular-nums">✔ Évaluation enregistrée (Moyenne : <?= number_format((float)$existingSurvey['average_rating'], 2, ',', ' ') ?>/5 • NPS : <?= (int)$existingSurvey['nps_score'] ?>/10)</span>
            <?php endif; ?>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">⭐ Questionnaire de Satisfaction à Chaud Qualiopi</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Votre retour d'expérience nous permet d'améliorer en continu la qualité pédagogique des supports, des diapositives Studio 16:9 et des ateliers pratiques Docker PostgreSQL 18.
        </p>
    </div>
</div>

<form method="POST" action="index.php?page=satisfaction&action=submit_satisfaction&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>

    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">📊 Évaluation sur 5 Critères Qualité (1 = Insuffisant à 5 = Excellent)</h2>

    <?php
    $criteria = [
        'content_quality' => '1. Richesse technique et clarté des supports de cours & diapositives Studio 16:9',
        'labs_docker' => '2. Pertinence des 34 ateliers pratiques Docker, base Sakila, pgbench & corrigés testés',
        'trainer_pedagogy' => '3. Expertise opérationnelle, pédagogie et disponibilité du formateur (Jean-Marie Renouard)',
        'lms_ergonomics' => '4. Ergonomie, rapidité et fonctionnalités du portail LMS LightPath (Console SQL, Oracles)',
        'objectives_met' => '5. Atteinte des objectifs opérationnels (Taxonomie de Bloom) applicables en production',
    ];
    foreach ($criteria as $cKey => $cLabel):
        $curVal = (int)($r[$cKey] ?? 5);
    ?>
        <div class="quiz-question-box" style="margin-bottom: 1rem;">
            <div style="font-weight: 700; margin-bottom: 0.55rem;"><?= Security::e($cLabel) ?></div>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <?php for ($v = 1; $v <= 5; $v++): ?>
                    <label style="display: inline-flex; align-items: center; gap: 0.35rem; cursor: pointer;">
                        <input type="radio" name="<?= Security::e($cKey) ?>" value="<?= $v ?>" <?= $curVal === $v ? 'checked' : '' ?> required/>
                        <span class="tabular-nums"><strong><?= $v ?> / 5</strong> <?= str_repeat('★', $v) ?></span>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="quiz-question-box" style="margin-bottom: 1rem;">
        <div style="font-weight: 700; margin-bottom: 0.55rem;">
            6. Recommanderiez-vous cette formation PostgreSQL 18 à un confrère DBA, SRE ou Architecte ? (Score NPS de 0 à 10)
        </div>
        <select name="nps_score" class="form-control tabular-nums" style="max-width: 280px;">
            <?php
            $curNps = (int)($existingSurvey['nps_score'] ?? 10);
            for ($n = 10; $n >= 0; $n--):
            ?>
                <option value="<?= $n ?>" <?= $curNps === $n ? 'selected' : '' ?>><?= $n ?> / 10 <?= $n >= 9 ? '(Promoteur ★)' : ($n >= 7 ? '(Passif)' : '') ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <div class="form-group">
        <label>💪 Points forts de la formation et des ateliers Docker</label>
        <textarea name="strengths" class="form-control" rows="3" placeholder="Ex: Les labs sur MVCC/pageinspect, Patroni, pgBackRest et le Module 20 Kernel Linux..."><?= Security::e((string)($existingSurvey['strengths'] ?? '')) ?></textarea>
    </div>

    <div class="form-group">
        <label>🛠️ Suggestions d'évolution ou sujets complémentaires souhaités</label>
        <textarea name="improvements" class="form-control" rows="3" placeholder="Vos suggestions d'amélioration continue Qualiopi..."><?= Security::e((string)($existingSurvey['improvements'] ?? '')) ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem;">
        💾 Enregistrer mon Évaluation de Satisfaction Qualiopi
    </button>
</form>
