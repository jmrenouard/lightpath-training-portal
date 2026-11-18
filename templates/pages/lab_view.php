<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

$firstLabViewId = (string)(array_key_first($currentCourse['labs'] ?? []) ?: 'lab_01_introduction_setup_postgresql_18');
$labId = (string)($_GET['id'] ?? $firstLabViewId);
$lab = $courseRepo->getLab($currentCourseSlug, $labId);
if ($lab === null) {
    $firstKey = array_key_first($currentCourse['labs'] ?? []);
    $lab = $firstKey ? $currentCourse['labs'][$firstKey] : null;
}

if ($lab === null) {
    echo '<div class="flash-banner flash-error">Atelier introuvable.</div>';
    return;
}

$courseHasDocker = !isset($currentCourse['docker_enabled']) || !empty($currentCourse['docker_enabled']);
$dockerFeatureEnabled = !isset($featureManager) || $featureManager->isEnabledForCourse('docker_labs', $currentCourseSlug);
$courseDockerEnabled = $courseHasDocker && $dockerFeatureEnabled;

$labId = (string)$lab['id'];
$parsed = $mdParser->parseLabFile($lab['source_md_path'], $currentCourseSlug);
$isLabDone = in_array($labId, $userProgress['completed_labs'] ?? [], true);
$savedNote = (string)($userProgress['notes'][$labId] ?? '');
$defaultSql = $courseHasDocker ? ($parsed['sql_snippets'][0]['sql'] ?? "SELECT version(), current_setting('server_version_num'), current_setting('data_checksums');") : '';
$defaultLabDb = $courseHasDocker ? $sqlRunner->detectTargetDatabase($labId) : '';
$isBookmarked = false;
foreach (($userProgress['bookmarks'] ?? []) as $bm) {
    if (($bm['type'] ?? '') === 'lab' && ($bm['id'] ?? '') === $labId) {
        $isBookmarked = true;
        break;
    }
}
$discussions = $storage->getDiscussions($currentCourseSlug, $labId);

// Compute labs belonging to the same learning track for the right-hand position stepper.
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
$classroomState = $storage->getClassroomState($currentCourseSlug);
$solutionsLocked = (!empty($classroomState['lock_solutions']) || !empty($classroomState['solutions_locked'])) && !$auth->isStaff($currentUser);
$lockedSolutionsNotice = false;
if ($solutionsLocked && in_array($labMode, ['corrige', 'full', 'html_preview', 'pdf_preview'], true)) {
    $labMode = 'enonce';
    $lockedSolutionsNotice = true;
}
$rawLabSource = is_file((string)$lab['source_md_path']) ? (string)file_get_contents((string)$lab['source_md_path']) : '';
$labHint = $sqlRunner->extractLabHint($rawLabSource, $labId);
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
$dockerPassword = $courseDockerEnabled
    ? (isset($docker) ? $docker->getDefaultPassword() : (string)($dockerStatus['default_password'] ?? 'postgres'))
    : '';
?>

<!-- Practical lab header, track position, and downloadable resources bar -->
<div class="hero-banner p-hero-lab">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <a href="index.php?page=catalog&tab=labs&track=<?= urlencode($trackId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                ← <?= Security::e($lab['track_number'] ?? 'Parcours') ?> (Labs)
            </a>
            <span class="badge-completed">
                <?= Security::e($lab['badge_code']) ?>
            </span>
            <span class="badge-phase tabular-nums">
                <?= Security::e($lab['phase_code'] ?? 'ATELIER') ?> • 📍 Étape <?= (int)($lab['track_position'] ?? 1) ?> sur <?= (int)($lab['track_total'] ?? 20) ?> du <?= Security::e($lab['track_number'] ?? 'Parcours') ?>
            </span>
            <span class="text-sm text-secondary">⏱ <?= Security::e($lab['duration']) ?></span>
            <?php if (!empty($lab['linked_module_id'])): ?>
                <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                    📖 Voir le Cours
                </a>
                <a href="index.php?page=module&id=<?= urlencode((string)$lab['linked_module_id']) ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="badge-code">
                    🖥️ Voir les Slides 16:9
                </a>
            <?php endif; ?>
        </div>

        <h1 class="hero-title-md mb-xs"><?= Security::e($lab['title']) ?></h1>
        <p class="text-secondary mb-sm text-sm">
            <strong>Compétence Visée (Norme 7 Piliers Qualiopi) :</strong> <?= Security::e($lab['bloom']) ?>
        </p>

        <?php if (!$solutionsLocked): ?>
            <div class="d-flex items-center gap-xs flex-wrap">
                <span class="text-xs font-bold text-uppercase text-muted">📥 Télécharger l'énoncé :</span>
                <?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?>
            </div>
        <?php else: ?>
            <div class="d-flex items-center gap-xs flex-wrap">
                <span class="badge-phase">🔒 Téléchargement des supports du lab verrouillé par le formateur</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="hero-actions-col">
        <button type="button"
                class="btn <?= $isLabDone ? 'btn-emerald' : 'btn-primary' ?> btn-toggle-complete"
                data-type="lab"
                data-id="<?= Security::e($labId) ?>"
                data-course="<?= Security::e($currentCourseSlug) ?>"
                data-completed="<?= $isLabDone ? '1' : '0' ?>">
            <?= $isLabDone ? '✔ Atelier Validé (Cliquer pour annuler)' : '✅ Marquer cet Atelier comme Terminé' ?>
        </button>

        <div class="d-flex gap-xs">
            <button type="button"
                    class="btn btn-outline btn-sm btn-toggle-bookmark flex-1"
                    data-type="lab"
                    data-id="<?= Security::e($labId) ?>"
                    data-title="<?= Security::e($lab['badge_code'] . ' — ' . $lab['title']) ?>">
                <?= $isBookmarked ? '⭐ Favori' : '☆ Favori' ?>
            </button>
            <?php if (!empty($lab['linked_cheatsheet_id'])):
                $labCsId = (string)$lab['linked_cheatsheet_id'];
                $labCsDownloads = $currentCourse['cheatsheets'][$labCsId]['downloads'] ?? [];
                $labCsFormat = isset($labCsDownloads['pdf']) ? 'pdf' : (isset($labCsDownloads['md']) ? 'md' : (string)(array_key_first($labCsDownloads) ?: 'md'));
            ?>
                <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=cheatsheet&id=<?= urlencode($labCsId) ?>&format=<?= urlencode($labCsFormat) ?>" class="btn btn-outline btn-sm flex-1" title="Télécharger la Fiche Mémo <?= Security::e(strtoupper($labCsFormat)) ?> de cet atelier">
                    📑 Mémo Lab <?= Security::e(strtoupper($labCsFormat)) ?>
                </a>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-xs">
            <?php if ($prevLabId): ?>
                <a href="index.php?page=lab&id=<?= urlencode($prevLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm flex-1">← Lab Préc.</a>
            <?php endif; ?>
            <?php if ($nextLabId): ?>
                <a href="index.php?page=lab&id=<?= urlencode($nextLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm flex-1">Lab Suiv. →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lab view mode switcher bar (Instructions / Official Solution / Full / Web / PDF) -->
<div class="view-mode-switcher">
    <div class="view-mode-tabs">
        <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=enonce&course=<?= urlencode($currentCourseSlug) ?>"
           class="view-mode-tab <?= $labMode === 'enonce' ? 'active' : '' ?>">
            <span>📋 Énoncé & Travail Pas-à-Pas</span>
        </a>
        <?php if (!$solutionsLocked): ?>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'corrige' ? 'active' : '' ?>">
                <span>✅ Corrigé Complet & Débriefing REX</span>
            </a>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=full&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'full' ? 'active' : '' ?>">
                <span>📖 Vue Intégrale (Énoncé + Corrigé)</span>
            </a>
        <?php else: ?>
            <span class="view-mode-tab view-mode-tab--locked" title="Le formateur a temporairement verrouillé l'accès aux corrigés pendant la phase de recherche.">
                <span>🔒 Corrigé Verrouillé (Phase Recherche)</span>
            </span>
        <?php endif; ?>
        <?php if (!$solutionsLocked && isset($lab['downloads']['html'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=html_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'html_preview' ? 'active' : '' ?>">
                <span>🌐 Rendu Web Studio</span>
            </a>
        <?php endif; ?>
        <?php if (!$solutionsLocked && isset($lab['downloads']['pdf'])): ?>
            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=pdf_preview&course=<?= urlencode($currentCourseSlug) ?>"
               class="view-mode-tab <?= $labMode === 'pdf_preview' ? 'active' : '' ?>">
                <span>📕 Support PDF</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-xs flex-wrap items-center">
        <button type="button" id="btn-toggle-lab-solutions" class="btn btn-outline btn-sm" title="Déplier ou replier toutes les solutions intégrées">
            👁️ Afficher / Masquer les Solutions
        </button>
        <button type="button"
                class="btn btn-outline btn-sm btn-toggle-right-panel"
                title="Réduire / Afficher la barre verticale de droite (Alt+D)">
            ◨ Menu Droite
        </button>
    </div>
</div>

<!-- Interactive Docker container cockpit & live SQL console -->
<?php if ($courseDockerEnabled): ?>
<section class="docker-cockpit">
    <div class="d-flex justify-between items-center flex-wrap gap-md mb-1">
        <div class="min-w-0 flex-1">
            <span class="badge-code">🐳 BAC À SABLE DOCKER DÉDIÉ À VOTRE SESSION</span>
            <h3 class="mt-xs mb-xxs section-heading-sm">
                Conteneur : <code><?= Security::e($dockerStatus['container_name']) ?></code> •
                Port : <code class="tabular-nums"><?= (int)$dockerStatus['port'] ?></code> •
                Statut : <strong id="lab-docker-status-text" class="<?= !empty($dockerStatus['running']) ? 'status-text-online' : 'status-text-standby' ?>">
                    <?= !empty($dockerStatus['running']) ? 'EN LIGNE (RUNNING)' : (!empty($dockerStatus['primary_cluster_running']) ? 'CLUSTER PARTAGÉ PRÊT' : 'PRÊT AU DÉMARRAGE') ?>
                </strong>
            </h3>
            <div class="text-sm text-secondary">
                Terminal local : <code><?= Security::e($dockerStatus['connection_string']) ?></code> (Mot de passe : <code><?= Security::e($dockerPassword) ?></code>)
            </div>
        </div>

        <div class="d-flex gap-xs flex-wrap">
            <button type="button" class="btn btn-emerald btn-sm btn-docker-cmd" data-cmd="start">
                🐳 Démarrer mon Conteneur Lab PostgreSQL 18
            </button>
            <button type="button" id="btn-seed-lab" class="btn btn-outline btn-sm" title="Réamorcer les jeux de données Sakila (dvdrental) et pgbench (formation_pg)" aria-label="Réamorcer Sakila et pgbench">
                🌱 Réamorcer Sakila / pgbench
            </button>
            <button type="button" id="btn-docker-logs" class="btn btn-outline btn-sm" title="Afficher les journaux PostgreSQL récents du conteneur" aria-label="Afficher les logs PostgreSQL">
                📜 Logs PostgreSQL
            </button>
            <button type="button" class="btn btn-outline btn-sm btn-docker-cmd" data-cmd="reset">
                🔄 Réinitialiser la Base
            </button>
            <button type="button" class="btn btn-danger btn-sm btn-docker-cmd" data-cmd="stop">
                ⏹ Stop
            </button>
        </div>
    </div>

    <div id="docker-action-feedback" class="flash-banner flash-success mb-1" hidden aria-live="polite"></div>

    <!-- Interactive SQL Workbench -->
    <div class="sql-workbench mb-0">
        <div class="lms-code-toolbar">
            <div class="d-flex items-center gap-sm flex-wrap">
                <span class="lms-code-lang">⚡ CONSOLE SQL INTERACTIVE (DOCKER PG 18)</span>
                <label for="lab-sql-db-select" class="workbench-label">
                    <span>Base cible :</span>
                    <select id="lab-sql-db-select" class="form-control select-compact">
                        <option value="formation_pg" <?= $defaultLabDb === 'formation_pg' ? 'selected' : '' ?>>formation_pg (Cluster)</option>
                        <option value="dvdrental" <?= $defaultLabDb === 'dvdrental' ? 'selected' : '' ?>>dvdrental (Sakila)</option>
                        <option value="postgres" <?= $defaultLabDb === 'postgres' ? 'selected' : '' ?>>postgres (Système)</option>
                    </select>
                </label>
                <label for="lab-sql-history-select" class="workbench-label">
                    <span>🕘 Historique :</span>
                    <select id="lab-sql-history-select" class="form-control select-compact select-compact-history">
                        <option value="">— Requêtes récentes —</option>
                    </select>
                </label>
            </div>
            <div class="lms-code-actions">
                <button type="button" id="btn-inspect-schema" class="btn btn-outline btn-sm" title="Afficher les tables, colonnes et cardinalités de la base cible">
                    🗂️ Schéma des Tables
                </button>
                <button type="button" id="btn-explain-sql" class="btn btn-outline btn-sm" title="Exécuter EXPLAIN (ANALYZE, BUFFERS) sur la requête courante">
                    📊 EXPLAIN ANALYZE
                </button>
                <button type="button" id="btn-execute-sql" class="btn btn-primary btn-sm" title="Exécuter la requête SQL (Ctrl+Entrée / Cmd+Entrée)">
                    ▶ Exécuter la Requête SQL
                </button>
                <button type="button" id="btn-run-oracle" class="btn btn-emerald btn-sm" data-lab-id="<?= Security::e($labId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                    🏆 Vérifier mon Lab (Oracle Qualiopi 1-Clic)
                </button>
                <?php if (!$solutionsLocked): ?>
                    <button type="button" id="btn-run-lab-solution" class="btn btn-outline-cyan btn-sm" data-lab-id="<?= Security::e($labId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>" title="Exécuter automatiquement les blocs SQL du corrigé officiel et vérifier l'Oracle">
                        🧪 Tester & Valider le Corrigé Officiel
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <!-- Secondary Lab Workbench Sub-Toolbar (Savepoints, pgbench, PG18 Telemetry, Readiness) -->
        <div class="workbench-subtoolbar">
            <div class="workbench-subtoolbar-group">
                <label for="lab-savepoint-name" class="workbench-label">
                    <span>Point de reprise :</span>
                </label>
                <input type="text"
                       id="lab-savepoint-name"
                       class="form-control form-control-sm workbench-savepoint-input"
                       value="checkpoint_1"
                       maxlength="32"
                       pattern="[a-zA-Z0-9_\-]{1,32}"
                       placeholder="checkpoint_1"
                       aria-label="Nom du savepoint PostgreSQL"/>
                <button type="button" id="btn-savepoint-create" class="btn btn-outline btn-sm" title="Créer un savepoint logique de la base cible">
                    💾 Savepoint
                </button>
                <button type="button" id="btn-savepoint-restore" class="btn btn-outline btn-sm" title="Restaurer le savepoint nommé">
                    ♻️ Restaurer Savepoint
                </button>
            </div>
            <div class="workbench-subtoolbar-group">
                <button type="button" id="btn-run-pgbench" class="btn btn-outline-cyan btn-sm" title="Exécuter un benchmark borné pgbench (4 clients, 2 secondes)">
                    ⚡ pgbench (4c/2s)
                </button>
                <button type="button" id="btn-pg-diag" class="btn btn-outline-cyan btn-sm" title="Collecter la télémétrie PG18 (pg_stat_activity, pg_settings, hit ratio)">
                    🔬 Diag PG18
                </button>
                <button type="button" id="btn-check-readiness" class="btn btn-outline btn-sm" data-lab-id="<?= Security::e($labId) ?>" title="Vérifier les prérequis système, extensions et oracle de cet atelier">
                    🩺 État Prérequis Lab
                </button>
            </div>
        </div>
        <textarea id="lab-sql-editor" class="sql-editor-textarea" spellcheck="false" aria-label="Éditeur de requêtes SQL PostgreSQL 18"><?= Security::e($defaultSql) ?></textarea>
        <div id="lab-sql-output" class="sql-output-console" aria-live="polite">Prêt (Ctrl+Entrée pour exécuter). Cliquez sur n'importe quel bouton « ▶ Exécuter dans le Lab SQL » dans l'énoncé ou le corrigé ci-dessous, ou cliquez sur « 🧪 Tester & Valider le Corrigé Officiel » pour rejouer le corrigé complet sur Docker.</div>
    </div>
</section>
<?php elseif ($courseHasDocker): ?>
<section class="docker-cockpit" aria-label="Environnement Docker Labs désactivé">
    <div class="flash-banner" role="status">
        ℹ️ L'environnement interactif Docker Labs et la console SQL en ligne sont actuellement désactivés par l'administrateur. Vous pouvez consulter l'énoncé et exécuter vos commandes sur votre instance PostgreSQL locale.
    </div>
</section>
<?php endif; ?>

<?php if ($labMode === 'html_preview' && isset($lab['downloads']['html'])): ?>
    <div class="preview-iframe-wrap">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=lab&id=<?= urlencode($labId) ?>&format=html&inline=1"
                allow="unload *"
                class="preview-iframe-full"
                title="<?= Security::e($lab['title']) ?>"></iframe>
    </div>
<?php elseif ($labMode === 'pdf_preview' && isset($lab['downloads']['pdf'])): ?>
    <div class="preview-iframe-wrap preview-iframe-wrap--dark">
        <iframe src="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=lab&id=<?= urlencode($labId) ?>&format=pdf&inline=1"
                allow="unload *"
                class="preview-iframe-full"
                title="<?= Security::e($lab['title']) ?> (PDF)"></iframe>
    </div>
<?php else: ?>
<!-- Qualiopi 7-pillar lab reader and sticky ScrollSpy table of contents -->
<div class="reader-grid">
    <div class="reader-main-col">
        <?php if ($lockedSolutionsNotice): ?>
            <div class="flash-banner flash-error mb-1">
                🔒 <strong>Corrigé temporairement verrouillé par le formateur :</strong> Réalisez d'abord l'investigation dans l'énoncé et utilisez l'indice progressif ci-dessous.
            </div>
        <?php endif; ?>

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
                    <?= $courseDockerEnabled ? "Réalisez les étapes dans la console SQL ci-dessus. Les solutions sont masquées par défaut pour préserver votre démarche d'investigation." : "Réalisez les étapes pratiques dans votre terminal local. Les solutions sont masquées par défaut pour préserver votre démarche d'investigation." ?>
                </div>
                <?php if (!$solutionsLocked): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                        ✅ Voir le Corrigé Complet & REX →
                    </a>
                <?php endif; ?>
            </div>

            <!-- Progressive pedagogical hint drawer without revealing the full solution -->
            <details class="lms-card lms-card--hint-drawer">
                <summary class="font-bold text-cyan text-sm">
                    💡 Besoin d'un coup de pouce ? Afficher l'Indice & Piste de Diagnostic<?= $courseHasDocker ? ' DBA' : '' ?> (sans dévoiler la solution)
                </summary>
                <div class="mt-xs text-sm text-secondary">
                    <p class="mb-xs mt-0"><?= Security::e((string)($labHint['hint'] ?? '')) ?></p>
                    <?php if (!empty($labHint['first_command_clue'])): ?>
                        <div class="text-sm text-primary">
                            <strong><?= $courseHasDocker ? 'Première piste SQL / catalogue à inspecter :' : 'Première commande / piste à inspecter :' ?></strong>
                            <code><?= Security::e((string)$labHint['first_command_clue']) ?> ...</code>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>

        <article id="reader-article-content" class="reader-article markdown-body">
            <?= $activeLabHtml ?>
        </article>

        <!-- Bottom lab navigation card -->
        <div class="module-bottom-nav-card">
            <div class="d-flex gap-sm flex-wrap">
                <?php if ($prevLabId): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($prevLabId) ?>&mode=<?= urlencode($labMode) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                        ◀ Atelier Précédent
                    </a>
                <?php endif; ?>
                <?php if (!$solutionsLocked && $labMode !== 'corrige'): ?>
                    <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&mode=corrige&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald">
                        ✅ Consulter le Corrigé Complet & Débriefing REX
                    </a>
                <?php elseif ($labMode === 'corrige'): ?>
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

        <!-- Technical Q&A discussion section for this lab -->
        <?= renderDiscussionSection($currentCourseSlug, 'lab', $labId, $discussions, $csrfToken, $auth->isStaff($currentUser)) ?>
    </div>

    <aside class="reader-sidebar" id="app-right-sidebar">
        <!-- 1. Lab step outline with live ScrollSpy tracking -->
        <div class="toc-box">
            <div class="d-flex justify-between items-center gap-xs mb-xs">
                <span class="sidebar-section-label">
                    🧪 <?= $labMode === 'corrige' ? 'Plan du Corrigé' : 'Étapes & Position' ?>
                </span>
                <div class="d-flex items-center gap-xs">
                    <span id="toc-read-pct" class="badge-code tabular-nums">0% lu</span>
                    <button type="button"
                            class="btn-sidebar-collapse-inline btn-toggle-right-panel"
                            title="Réduire la barre verticale de droite (Alt+D)"
                            aria-label="Réduire la barre verticale de droite">
                        ▸
                    </button>
                </div>
            </div>
            <div class="progress-bar-track progress-bar-track--xs mb-sm">
                <div id="toc-read-bar" class="progress-bar-fill" data-progress="0"></div>
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

        <!-- 2. Collapsible track lab stepper -->
        <details class="toc-box">
            <summary class="summary-reset cursor-pointer">
                <span class="sidebar-section-label">
                    📍 Lab <?= (int)($lab['track_position'] ?? 1) ?> / <?= (int)($lab['track_total'] ?? count($trackLabs)) ?> du Parcours
                </span>
                <span class="badge-code tabular-nums"><?= $trackProgressPct ?>%</span>
            </summary>
            <div class="mt-sm">
                <div class="text-xs text-secondary mb-xs">
                    <?= Security::e($lab['track_short_title'] ?? $lab['track_title'] ?? '') ?>
                </div>
                <div class="progress-bar-track progress-bar-track--xs mb-sm">
                    <div class="progress-bar-fill" data-progress="<?= (int)$trackProgressPct ?>"></div>
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

        <!-- 3. Lab personal notes & REX scratchpad -->
        <div class="toc-box">
            <label for="user-personal-note" class="d-block sidebar-section-label mb-xs">
                📝 Notes d'Atelier / REX
            </label>
            <textarea id="user-personal-note" class="form-control" rows="5" placeholder="<?= $courseHasDocker ? 'Consignez vos observations EXPLAIN, métriques TPS ou commandes...' : 'Consignez vos commandes, sorties et retours d\'expérience...' ?>"><?= Security::e($savedNote) ?></textarea>
            <div class="d-flex justify-between items-center mt-xs">
                <span id="note-save-status" class="text-xs text-emerald"></span>
                <button type="button" id="btn-save-note" class="btn btn-primary btn-sm" data-id="<?= Security::e($labId) ?>" data-course="<?= Security::e($currentCourseSlug) ?>">
                    💾 Enregistrer
                </button>
            </div>
        </div>
    </aside>
</div>
<?php endif; ?>
