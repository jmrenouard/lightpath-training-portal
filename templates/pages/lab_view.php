<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$labId = (string)($_GET['id'] ?? 'lab_01_introduction_setup_postgresql_18');
$lab = $courseRepo->getLab($currentCourseSlug, $labId);
if ($lab === null) {
    $firstKey = array_key_first($currentCourse['labs'] ?? []);
    $lab = $firstKey ? $currentCourse['labs'][$firstKey] : null;
}

if ($lab === null) {
    echo '<div class="flash-banner flash-error">Atelier introuvable.</div>';
    return;
}

$labId = (string)$lab['id'];
$parsed = $mdParser->parseLabFile($lab['source_md_path'], $currentCourseSlug);
$isLabDone = in_array($labId, $userProgress['completed_labs'] ?? [], true);
$savedNote = (string)($userProgress['notes'][$labId] ?? '');
$defaultSql = $parsed['sql_snippets'][0]['sql'] ?? "SELECT version(), current_setting('server_version_num'), current_setting('data_checksums');";

$labKeys = array_keys($currentCourse['labs'] ?? []);
$curIndex = array_search($labId, $labKeys, true);
$prevLabId = ($curIndex !== false && $curIndex > 0) ? $labKeys[$curIndex - 1] : null;
$nextLabId = ($curIndex !== false && $curIndex < count($labKeys) - 1) ? $labKeys[$curIndex + 1] : null;
?>

<!-- EN-TÊTE DE L'ATELIER PRATIQUE & BARRE DE TÉLÉCHARGEMENT -->
<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1;">
        <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
            <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">← Tous les Ateliers</a>
            <span class="badge-code" style="background: rgba(16, 185, 129, 0.16); color: #34D399; border-color: rgba(16, 185, 129, 0.35);">
                <?= Security::e($lab['badge_code']) ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">⏱ <?= Security::e($lab['duration']) ?></span>
            <?php if (!empty($lab['linked_module_id'])): ?>
                <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                    📖 Voir le Module de Cours Associé
                </a>
            <?php endif; ?>
        </div>

        <h1 style="font-size: 1.55rem; margin-bottom: 0.35rem;"><?= Security::e($lab['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0 0 0.75rem; font-size: 0.88rem;">
            <strong>Compétence Visée (Norme 7 Piliers Qualiopi) :</strong> <?= Security::e($lab['bloom']) ?>
        </p>

        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">📥 Télécharger l'énoncé :</span>
            <?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.55rem; min-width: 240px;">
        <button type="button"
                class="btn <?= $isLabDone ? 'btn-emerald' : 'btn-primary' ?> btn-toggle-complete"
                data-type="lab"
                data-id="<?= Security::e($labId) ?>"
                data-course="<?= Security::e($currentCourseSlug) ?>"
                data-completed="<?= $isLabDone ? '1' : '0' ?>">
            <?= $isLabDone ? '✔ Atelier Validé (Cliquer pour annuler)' : '✅ Marquer cet Atelier comme Terminé' ?>
        </button>

        <div style="display: flex; gap: 0.45rem;">
            <?php if ($prevLabId): ?>
                <a href="index.php?page=lab&id=<?= urlencode($prevLabId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="flex:1;">← Lab Préc.</a>
            <?php endif; ?>
            <?php if ($nextLabId): ?>
                <a href="index.php?page=lab&id=<?= urlencode($nextLabId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="flex:1;">Lab Suiv. →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- STUDIO DOCKER INTERACTIF & CONSOLE SQL LIVE -->
<section class="docker-cockpit">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <div>
            <span class="badge-code">🐳 BAC À SABLE DOCKER DÉDIÉ À VOTRE SESSION</span>
            <h3 style="margin: 0.35rem 0 0.15rem; font-size: 1.1rem;">
                Conteneur : <code><?= Security::e($dockerStatus['container_name']) ?></code> •
                Port : <code class="tabular-nums"><?= (int)$dockerStatus['port'] ?></code> •
                Statut : <strong style="color: <?= !empty($dockerStatus['running']) ? '#34D399' : '#FBBF24' ?>;">
                    <?= !empty($dockerStatus['running']) ? 'EN LIGNE (RUNNING)' : 'PRÊT AU DÉMARRAGE' ?>
                </strong>
            </h3>
            <div style="font-size: 0.8rem; color: var(--text-secondary);">
                Terminal local : <code><?= Security::e($dockerStatus['connection_string']) ?></code> (Mot de passe : <code>postgres_master_pw</code>)
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-emerald btn-sm btn-docker-cmd" data-cmd="start">
                🐳 Démarrer mon Conteneur Lab PostgreSQL 18
            </button>
            <button type="button" class="btn btn-outline btn-sm btn-docker-cmd" data-cmd="reset">
                🔄 Réinitialiser la Base
            </button>
            <?php if (!empty($dockerStatus['running'])): ?>
                <button type="button" class="btn btn-danger btn-sm btn-docker-cmd" data-cmd="stop">
                    ⏹ Stop
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="docker-action-feedback" class="flash-banner flash-success" style="display: none; margin-bottom: 1rem;"></div>

    <!-- Console SQL Interactive -->
    <div class="sql-workbench" style="margin-bottom: 0;">
        <div class="lms-code-toolbar">
            <span class="lms-code-lang">⚡ CONSOLE SQL INTERACTIVE (CONNECTÉE À VOTRE CONTENEUR DOCKER)</span>
            <div class="lms-code-actions">
                <button type="button" id="btn-execute-sql" class="btn btn-primary btn-sm">
                    ▶ Exécuter la Requête SQL
                </button>
                <button type="button" id="btn-run-oracle" class="btn btn-emerald btn-sm" data-lab-id="<?= Security::e($labId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                    🏆 Vérifier mon Lab (Oracle Qualiopi 1-Clic)
                </button>
            </div>
        </div>
        <textarea id="lab-sql-editor" class="sql-editor-textarea" spellcheck="false"><?= Security::e($defaultSql) ?></textarea>
        <div id="lab-sql-output" class="sql-output-console">Prêt. Cliquez sur n'importe quel bouton « ▶ Exécuter dans le Lab SQL » dans l'énoncé ci-dessous ou saisissez votre requête SQL, puis cliquez sur « ▶ Exécuter la Requête SQL ».</div>
    </div>
</section>

<!-- ÉNONCÉ COMPLET DES 7 PILIERS QUALIOPI & SOMMAIRE -->
<div class="reader-grid">
    <article class="reader-article">
        <?= $parsed['html'] ?>
    </article>

    <aside class="reader-sidebar">
        <div class="toc-box">
            <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.65rem;">
                🧪 Étapes de l'Atelier
            </div>
            <?php foreach ($parsed['toc'] as $tocItem): ?>
                <a href="#<?= Security::e($tocItem['id']) ?>" class="toc-link toc-level-<?= (int)$tocItem['level'] ?>">
                    <?= Security::e($tocItem['title']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="toc-box">
            <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
                📝 Notes d'Atelier / REX
            </div>
            <textarea id="user-personal-note" class="form-control" rows="5" placeholder="Consignez vos observations EXPLAIN, métriques TPS ou commandes..."><?= Security::e($savedNote) ?></textarea>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.55rem;">
                <span id="note-save-status" style="font-size: 0.74rem; color: var(--accent-emerald);"></span>
                <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($labId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                    💾 Enregistrer
                </button>
            </div>
        </div>
    </aside>
</div>
