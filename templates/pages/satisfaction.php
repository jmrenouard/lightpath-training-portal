<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$coldSatEnabled = !isset($featureManager) || $featureManager->isEnabled('cold_satisfaction_j60');
$satTab = (string)($_GET['tab'] ?? 'hot');
if (!in_array($satTab, ['hot', 'cold'], true) || (!$coldSatEnabled && $satTab === 'cold')) {
    $satTab = 'hot';
}
$existingSurvey = $storage->getUserSatisfaction($userId, $currentCourseSlug);
$r = $existingSurvey['ratings'] ?? [];
$coldSurvey = $coldSatEnabled ? $storage->getUserColdSatisfaction($userId, $currentCourseSlug) : null;
$coldRatings = $coldSurvey['ratings'] ?? [];
?>

<div class="hero-banner hero-banner-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">QUALIOPI INDICATEUR 30 & 32 • AMÉLIORATION CONTINUE</span>
            <?php if ($satTab === 'hot' && $existingSurvey !== null): ?>
                <span class="badge-completed tabular-nums">✔ Évaluation à chaud enregistrée (Moyenne : <?= number_format((float)$existingSurvey['average_rating'], 2, ',', ' ') ?>/5 • NPS : <?= (int)$existingSurvey['nps_score'] ?>/10)</span>
            <?php elseif ($satTab === 'cold' && $coldSurvey !== null): ?>
                <span class="badge-completed tabular-nums">✔ Évaluation à froid J+60 enregistrée (Moyenne : <?= number_format((float)($coldSurvey['average_rating'] ?? 5.0), 2, ',', ' ') ?>/5)</span>
            <?php endif; ?>
        </div>
        <h1 class="hero-title-md mb-xxs">⭐ Questionnaires de Satisfaction Qualiopi<?= $coldSatEnabled ? ' (À Chaud & À Froid J+60)' : ' (À Chaud)' ?></h1>
        <p class="hero-subtitle-sm mb-0">
            Votre retour d'expérience nous permet d'améliorer en continu la qualité pédagogique des supports, des diapositives Studio 16:9, des ateliers pratiques Docker PostgreSQL 18 et de mesurer le transfert des compétences en production.
        </p>
    </div>

    <div class="d-flex gap-xs flex-wrap">
        <a href="index.php?page=satisfaction&tab=hot&course=<?= urlencode($currentCourseSlug) ?>"
           class="btn btn-sm <?= $satTab === 'hot' ? 'btn-primary' : 'btn-outline' ?>">
            🔥 Évaluation à Chaud (Fin de Formation — Ind. 30)
        </a>
        <?php if ($coldSatEnabled): ?>
            <a href="index.php?page=satisfaction&tab=cold&course=<?= urlencode($currentCourseSlug) ?>"
               class="btn btn-sm <?= $satTab === 'cold' ? 'btn-primary' : 'btn-outline' ?>">
                ❄️ Évaluation à Froid (Impact Métier à J+60 — Ind. 30 & 32)
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($satTab === 'cold'): ?>
<form method="POST" action="index.php?page=satisfaction&tab=cold&action=submit_cold_satisfaction&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>

    <h2 class="section-heading-md mb-1">❄️ Évaluation d'Impact Métier à Froid (J+60 — 1 = Faible impact à 5 = Impact majeur)</h2>

    <?php
    $coldCriteria = [
        'prod_application' => '1. Mise en pratique effective des acquis PostgreSQL 18 sur vos environnements de production',
        'perf_gains' => '2. Gains mesurables de performance, de stabilité HA ou de sécurité observés',
        'autonomy_level' => '3. Progression de votre autonomie sur le diagnostic d\'incidents (MVCC, verrous, plans EXPLAIN, sauvegardes)',
        'tools_adoption' => '4. Utilisation des livrables, fiches mémo et scripts pgscripts au quotidien',
        'career_impact' => '5. Pertinence globale de la formation vis-à-vis de l\'évolution de votre poste DBA / SRE / Architecte',
    ];
    foreach ($coldCriteria as $cKey => $cLabel):
        $curVal = (int)($coldRatings[$cKey] ?? ($coldSurvey[$cKey] ?? 5));
    ?>
        <div class="quiz-question-box mb-1">
            <div class="font-bold mb-xs"><?= Security::e($cLabel) ?></div>
            <div class="d-flex gap-md flex-wrap">
                <?php for ($v = 1; $v <= 5; $v++): ?>
                    <label class="satisfaction-radio-label">
                        <input type="radio" name="<?= Security::e($cKey) ?>" value="<?= $v ?>" <?= $curVal === $v ? 'checked' : '' ?> required/>
                        <span class="tabular-nums"><strong><?= $v ?> / 5</strong> <?= str_repeat('★', $v) ?></span>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="form-group">
        <label for="cold-concrete-projects">🚀 Projets ou chantiers PostgreSQL réalisés depuis la formation</label>
        <textarea id="cold-concrete-projects" name="concrete_projects" class="form-control" rows="3" placeholder="Ex: Migration PG 18 avec pgcopydb, mise en place de pgBackRest / pg_auto_failover, tuning work_mem et index couvrants..."><?= Security::e((string)($coldSurvey['concrete_projects'] ?? '')) ?></textarea>
    </div>

    <div class="form-group">
        <label for="cold-further-needs">🎯 Besoins d'accompagnement ou de perfectionnement complémentaires</label>
        <textarea id="cold-further-needs" name="further_needs" class="form-control" rows="3" placeholder="Ex: Audit d'architecture, approfondissement CloudNativePG Kubernetes, coaching performance..."><?= Security::e((string)($coldSurvey['further_needs'] ?? '')) ?></textarea>
    </div>

    <button type="submit" class="btn btn-emerald w-100 btn-submit-lg">
        ❄️ Enregistrer mon Évaluation à Froid (J+60) Qualiopi
    </button>
</form>
<?php else: ?>
<form method="POST" action="index.php?page=satisfaction&action=submit_satisfaction&course=<?= urlencode($currentCourseSlug) ?>" class="lms-card">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>

    <h2 class="section-heading-md mb-1">📊 Évaluation sur 5 Critères Qualité (1 = Insuffisant à 5 = Excellent)</h2>

    <?php
    $satLabsCount = count($currentCourse['labs'] ?? []);
    $criteria = [
        'content_quality' => '1. Richesse technique et clarté des supports de cours & diapositives Studio 16:9',
        'labs_docker' => '2. Pertinence des ' . $satLabsCount . ' ateliers pratiques Docker, base Sakila, pgbench & corrigés testés',
        'trainer_pedagogy' => '3. Expertise opérationnelle, pédagogie et disponibilité du formateur (' . ($appConfig['trainer_name'] ?? 'Jean-Marie Renouard') . ')',
        'lms_ergonomics' => '4. Ergonomie, rapidité et fonctionnalités du portail LMS LightPath (Console SQL, Oracles)',
        'objectives_met' => '5. Atteinte des objectifs opérationnels (Taxonomie de Bloom) applicables en production',
    ];
    foreach ($criteria as $cKey => $cLabel):
        $curVal = (int)($r[$cKey] ?? 5);
    ?>
        <div class="quiz-question-box mb-1">
            <div class="font-bold mb-xs"><?= Security::e($cLabel) ?></div>
            <div class="d-flex gap-md flex-wrap">
                <?php for ($v = 1; $v <= 5; $v++): ?>
                    <label class="satisfaction-radio-label">
                        <input type="radio" name="<?= Security::e($cKey) ?>" value="<?= $v ?>" <?= $curVal === $v ? 'checked' : '' ?> required/>
                        <span class="tabular-nums"><strong><?= $v ?> / 5</strong> <?= str_repeat('★', $v) ?></span>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="quiz-question-box mb-1">
        <label for="sat-nps-score" class="d-block font-bold mb-xs">
            6. Recommanderiez-vous cette formation à un confrère DBA, SRE ou Architecte ? (Score NPS de 0 à 10)
        </label>
        <select id="sat-nps-score" name="nps_score" class="form-control tabular-nums select-nps">
            <?php
            $curNps = (int)($existingSurvey['nps_score'] ?? 10);
            for ($n = 10; $n >= 0; $n--):
            ?>
                <option value="<?= $n ?>" <?= $curNps === $n ? 'selected' : '' ?>><?= $n ?> / 10 <?= $n >= 9 ? '(Promoteur ★)' : ($n >= 7 ? '(Passif)' : '') ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="sat-strengths">💪 Points forts de la formation et des ateliers Docker</label>
        <textarea id="sat-strengths" name="strengths" class="form-control" rows="3" placeholder="Ex: Les labs sur MVCC/pageinspect, Patroni, pgBackRest et le Module 20 Kernel Linux..."><?= Security::e((string)($existingSurvey['strengths'] ?? '')) ?></textarea>
    </div>

    <div class="form-group">
        <label for="sat-improvements">🛠️ Suggestions d'évolution ou sujets complémentaires souhaités</label>
        <textarea id="sat-improvements" name="improvements" class="form-control" rows="3" placeholder="Vos suggestions d'amélioration continue Qualiopi..."><?= Security::e((string)($existingSurvey['improvements'] ?? '')) ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary w-100 btn-submit-lg">
        💾 Enregistrer mon Évaluation de Satisfaction Qualiopi
    </button>
</form>
<?php endif; ?>
