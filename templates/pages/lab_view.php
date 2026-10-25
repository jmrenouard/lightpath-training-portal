<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

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

// Calculer les ateliers du même parcours pour le Stepper de Position
$trackId = (string)($lab['track_id'] ?? 'cursus-complet');
$trackLabs = [];
foreach (($currentCourse['labs'] ?? []) as $lKey => $lItem) {
    if (($lItem['track_id'] ?? '') === $trackId) {
        $trackLabs[$lKey] = $lItem;
    }
}
$trackDoneCount = 0;
foreach ($trackLabs as $tlKey => $tlItem) {
    if (in_array($tlKey, $userProgress['completed_labs'] ?? [], true)) {
        $trackDoneCount++;
    }
}
$trackProgressPct = count($trackLabs) > 0 ? (int)round(($trackDoneCount / count($trackLabs)) * 100) : 0;

$labKeys = array_keys($currentCourse['labs'] ?? []);
$curIndex = array_search($labId, $labKeys, true);
$prevLabId = ($curIndex !== false && $curIndex > 0) ? $labKeys[$curIndex - 1] : null;
$nextLabId = ($curIndex !== false && $curIndex < count($labKeys) - 1) ? $labKeys[$curIndex + 1] : null;

$labMode = (string)($_GET['mode'] ?? 'enonce');
if (!in_array($labMode, ['enonce', 'corrige', 'full', 'html_preview', 'pdf_preview'], true)) {
    $labMode = 'enonce';
}
$activeLabHtml = match ($labMode) {
    'corrige' => ($parsed['corrige_html'] ?? $parsed['html']),
    'full' => $parsed['html'],
    default => ($parsed['enonce_html'] ?? $parsed['html']),
};
$activeLabToc = match ($labMode) {
    'corrige' => (!empty($parsed['corrige_toc']) ? $parsed['corrige_toc'] : $parsed['toc']),
    'full' => $parsed['toc'],
    default => (!empty($parsed['enonce_toc']) ? $parsed['enonce_toc'] : $parsed['toc']),
};
?>

<!-- EN-TÊTE DE L'ATELIER PRATIQUE, POSITION DANS LE PARCOURS & BARRE DE TÉLÉCHARGEMENT -->
<div class="hero-banner" style="padding: 1.4rem 1.85rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
            <a href="index.php?page=catalog&tab=labs&track=<?= urlencode($trackId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                ← <?= Security::e($lab['track_number'] ?? 'Parcours') ?> (Labs)
            </a>
            <span class="badge-code" style="background: rgba(16, 185, 129, 0.16); color: #34D399; border-color: rgba(16, 185, 129, 0.35);">
                <?= Security::e($lab['badge_code']) ?>
            </span>
            <span class="badge-phase tabular-nums">
                <?= Security::e($lab['phase_code'] ?? 'ATELIER') ?> • 📍 Étape <?= (int)($lab['track_position'] ?? 1) ?> sur <?= (int)($lab['track_total'] ?? 20) ?> du <?= Security::e($lab['track_number'] ?? 'Parcours') ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">⏱ <?= Security::e($lab['duration']) ?></span>
            <?php if (!empty($lab['linked_module_id'])): ?>
                <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                    📖 Voir le Cours
                </a>
                <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                    🖥️ Voir les Slides 16:9
                </a>
            <?php endif; ?>
        </div>

        <h1 style="font-size: 1.5rem; margin-bottom: 0.35rem;"><?= Security::e($lab['title']) ?></h1>
        <p style="color: var(--text-secondary); margin: 0 0 0.75rem; font-size: 0.87rem;">
            <strong>Compétence Visée (Norme 7 Piliers Qualiopi) :</strong> <?= Security::e($lab['bloom']) ?>
        </p>

        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">📥 Télécharger l'énoncé :</span>
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
                <a href="index.php?page=lab&id=<?= urlencode($prevLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="flex:1;">← Lab Préc.</a>
            <?php endif; ?>
            <?php if ($nextLabId): ?>
                <a href="index.php?page=lab&id=<?= urlencode($nextLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm" style="flex:1;">Lab Suiv. →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- BARRE DE BASCULE DES MODES D'ATELIER (ÉNONCÉ / CORRIGÉ COMPLET / VUE INTÉGRALE / RENDU WEB / PDF) -->
<div class="view-mode-switcher">
    <div class="view-mode-tabs">
        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=enonce&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $labMode === 'enonce' ? 'active' : '' ?>">
            <span>📋 Énoncé & Travail Pas-à-Pas</span>
        </a>
        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $labMode === 'corrige' ? 'active' : '' ?>">
            <span>✅ Corrigé Complet & Débriefing REX</span>
        </a>
        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=full&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $labMode === 'full' ? 'active' : '' ?>">
            <span>📖 Vue Intégrale (Énoncé + Corrigé)</span>
        </a>
        <?php if (isset($lab['downloads']['html'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'html_preview' ? 'active' : '' ?>">
                <span>🌐 Rendu Web Studio</span>
            </a>
        <?php endif; ?>
        <?php if (isset($lab['downloads']['pdf'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=pdf_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'pdf_preview' ? 'active' : '' ?>">
                <span>📕 Support PDF</span>
            </a>
        <?php endif; ?>
    </div>

    <div style="display: flex; gap: 0.45rem; flex-wrap: wrap; align-items: center;">
        <button type="button" id="btn-toggle-lab-solutions" class="btn btn-outline btn-sm" title="Déplier ou replier toutes les solutions SQL intégrées">
            👁️ Afficher / Masquer les Solutions
        </button>
        <button type="button"
                class="btn btn-outline btn-sm btn-toggle-right-panel"
                title="Réduire / Afficher la barre verticale de droite (Alt+D)">
            ◨ Menu Droite
        </button>
    </div>
</div>

<!-- STUDIO DOCKER INTERACTIF & CONSOLE SQL LIVE -->
<section class="docker-cockpit">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <div style="min-width: 0; flex: 1;">
            <span class="badge-code">🐳 BAC À SABLE DOCKER DÉDIÉ À VOTRE SESSION</span>
            <h3 style="margin: 0.35rem 0 0.15rem; font-size: 1.08rem;">
                Conteneur : <code><?= Security::e($dockerStatus['container_name']) ?></code> •
                Port : <code class="tabular-nums"><?= (int)$dockerStatus['port'] ?></code> •
                Statut : <strong id="lab-docker-status-text" style="color: <?= !empty($dockerStatus['running']) ? '#34D399' : '#FBBF24' ?>;">
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
            <button type="button" class="btn btn-danger btn-sm btn-docker-cmd" data-cmd="stop">
                ⏹ Stop
            </button>
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
        <div id="lab-sql-output" class="sql-output-console">Prêt. Cliquez sur n'importe quel bouton « ▶ Exécuter dans le Lab SQL » dans l'énoncé ou le corrigé ci-dessous, ou saisissez votre requête SQL puis cliquez sur « ▶ Exécuter la Requête SQL ».</div>
    </div>
</section>

<?php if ($labMode === 'html_preview' && isset($lab['downloads']['html'])): ?>
    <div style="background: #FFFFFF; border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-strong); height: 82vh;">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=lab&id=<?= urlencode($labId) ?>&format=html&inline=1"
                allow="unload *"
                style="width: 100%; height: 100%; border: none;"
                title="<?= Security::e($lab['title']) ?>"></iframe>
    </div>
<?php elseif ($labMode === 'pdf_preview' && isset($lab['downloads']['pdf'])): ?>
    <div style="background: var(--bg-surface); border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-strong); height: 82vh;">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=lab&id=<?= urlencode($labId) ?>&format=pdf&inline=1"
                allow="unload *"
                style="width: 100%; height: 100%; border: none;"
                title="<?= Security::e($lab['title']) ?> (PDF)"></iframe>
    </div>
<?php else: ?>
<!-- ÉNONCÉ / CORRIGÉ DES 7 PILIERS QUALIOPI & SOMMAIRE SCROLLSPY STICKY -->
<div class="reader-grid">
    <div class="reader-main-col">
        <?php if ($labMode === 'corrige'): ?>
            <div class="lab-mode-banner lab-mode-banner-corrige">
                <div>
                    <strong>✅ Mode Corrigé Officiel & Débriefing REX Expert :</strong>
                    Vous consultez les solutions détaillées, l'Oracle de validation Qualiopi et les explications d'architecture de l'atelier.
                </div>
                <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=enonce&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">
                    📋 Revenir à l'Énoncé
                </a>
            </div>
        <?php elseif ($labMode === 'enonce'): ?>
            <div class="lab-mode-banner lab-mode-banner-enonce">
                <div>
                    <strong>📋 Mode Énoncé & Travail Pas-à-Pas :</strong>
                    Réalisez les étapes dans la console SQL ci-dessus. Les solutions sont masquées par défaut pour préserver votre démarche d'investigation.
                </div>
                <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                    ✅ Voir le Corrigé Complet & REX →
                </a>
            </div>
        <?php endif; ?>

        <article id="reader-article-content" class="reader-article markdown-body">
            <?= $activeLabHtml ?>
        </article>

        <!-- CARTE DE NAVIGATION BAS DE PAGE D'ATELIER -->
        <div class="module-bottom-nav-card">
            <div style="display: flex; gap: 0.55rem; flex-wrap: wrap;">
                <?php if ($prevLabId): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($prevLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                        ◀ Atelier Précédent
                    </a>
                <?php endif; ?>
                <?php if ($labMode !== 'corrige'): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                        ✅ Consulter le Corrigé Complet & Débriefing REX
                    </a>
                <?php else: ?>
                    <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=enonce&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                        📋 Retourner à l'Énoncé Pas-à-Pas
                    </a>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($nextLabId): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($nextLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary">
                        Atelier Suivant ▶
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside class="reader-sidebar" id="app-right-sidebar">
        <!-- 1. SOMMAIRE DES ÉTAPES DE L'ATELIER (SCROLLSPY EN DIRECT) -->
        <div class="toc-box">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.4rem; margin-bottom: 0.45rem;">
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                    🧪 <?= $labMode === 'corrige' ? 'Plan du Corrigé' : 'Étapes & Position' ?>
                </span>
                <div style="display: flex; align-items: center; gap: 0.35rem;">
                    <span id="toc-read-pct" class="badge-code tabular-nums">0% lu</span>
                    <button type="button"
                            class="btn-sidebar-collapse-inline btn-toggle-right-panel"
                            title="Réduire la barre verticale de droite (Alt+D)"
                            aria-label="Réduire la barre verticale de droite">
                        ▸
                    </button>
                </div>
            </div>
            <div class="progress-bar-track" style="height: 5px; margin-bottom: 0.75rem;">
                <div id="toc-read-bar" class="progress-bar-fill" style="width: 0%;"></div>
            </div>

            <nav id="toc-scrollspy-nav" class="toc-scrollspy-nav">
                <?php foreach ($activeLabToc as $tIdx => $tocItem): ?>
                    <a href="#<?= Security::e($tocItem['id']) ?>"
                       class="toc-link toc-level-<?= (int)$tocItem['level'] ?> <?= $tIdx === 0 ? 'active' : '' ?>"
                       data-scrollspy-target="<?= Security::e($tocItem['id']) ?>">
                        <?= Security::e($tocItem['title']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- 2. STEPPER DE POSITION DANS LES ATELIERS DU PARCOURS (REPLIABLE COMME MODULE_VIEW) -->
        <details class="toc-box" style="cursor: pointer;">
            <summary style="display: flex; justify-content: space-between; align-items: center; list-style: none;">
                <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light);">
                    📍 Lab <?= (int)($lab['track_position'] ?? 1) ?> / <?= (int)($lab['track_total'] ?? count($trackLabs)) ?> du Parcours
                </span>
                <span class="badge-code tabular-nums"><?= $trackProgressPct ?>%</span>
            </summary>
            <div style="margin-top: 0.65rem;">
                <div style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 0.45rem;">
                    <?= Security::e($lab['track_short_title'] ?? $lab['track_title'] ?? '') ?>
                </div>
                <div class="progress-bar-track" style="height: 5px; margin-bottom: 0.75rem;">
                    <div class="progress-bar-fill" style="width: <?= $trackProgressPct ?>%;"></div>
                </div>

                <div class="curriculum-stepper-list">
                    <?php foreach ($trackLabs as $tlKey => $tlItem):
                        $tlCurrent = ($tlKey === $labId);
                        $tlDone = in_array($tlKey, $userProgress['completed_labs'] ?? [], true);
                    ?>
                        <a href="index.php?page=lab&id=<?= urlencode($tlKey) ?>&course=<?= urlencode($currentCourseSlug) ?>"
                           class="stepper-item <?= $tlCurrent ? 'is-current' : '' ?> <?= $tlDone ? 'is-done' : '' ?>">
                            <span class="stepper-indicator tabular-nums">
                                <?= $tlDone ? '✔' : (int)($tlItem['track_position'] ?? 1) ?>
                            </span>
                            <span class="stepper-label">
                                <strong><?= Security::e($tlItem['badge_code']) ?></strong> — <?= Security::e(CourseRepository::truncateUtf8($tlItem['title'], 40)) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>

        <!-- 3. BLOC-NOTES D'ATELIER -->
        <div class="toc-box">
            <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
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
<?php endif; ?>
