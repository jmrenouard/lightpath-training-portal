<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$csrfToken = Security::getCsrfToken();

/**
 * Helper d'affichage des liens de téléchargement multi-formats (PDF, Slides PDF, PPTX, MD, HTML, DOCX)
 */
function renderDownloadStrip(string $courseSlug, string $itemType, string $itemId, array $downloads, bool $compact = false): string
{
    if (empty($downloads)) {
        return '';
    }
    $icons = [
        'pdf' => '📕 PDF',
        'slides_pdf' => '🖥️ Slides PDF',
        'pptx' => '📊 PPTX',
        'md' => '📝 MD',
        'html' => '🌐 HTML',
        'docx' => '📘 DOCX',
        'video_mp4' => '🎬 Vidéo MP4',
        'video_slides_mp4' => '🎞️ Vidéo Slides',
        'video_vtt' => '💬 Sous-titres VTT',
        'video_transcript' => '📜 Transcription',
    ];
    $out = '<div class="download-strip">';
    foreach ($downloads as $fmt => $info) {
        if ($compact && in_array($fmt, ['video_slides_mp4', 'video_vtt', 'video_transcript'], true)) {
            continue;
        }
        $label = $icons[$fmt] ?? strtoupper($fmt);
        $size = !$compact && !empty($info['size_formatted']) ? ' <span class="dl-size">(' . Security::e($info['size_formatted']) . ')</span>' : '';
        $url = sprintf(
            'index.php?action=download&course=%s&type=%s&id=%s&format=%s',
            urlencode($courseSlug),
            urlencode($itemType),
            urlencode($itemId),
            urlencode($fmt)
        );
        $cssClass = 'dl-pill dl-' . preg_replace('/[^a-z]/', '', $fmt);
        $title = 'Télécharger ' . ($info['filename'] ?? $fmt) . ' (' . ($info['size_formatted'] ?? '') . ')';
        $out .= '<a href="' . Security::e($url) . '" class="' . $cssClass . '" title="' . Security::e($title) . '">⬇ ' . $label . $size . '</a>';
    }
    $out .= '</div>';
    return $out;
}

/**
 * Helper unifié d'affichage du Forum Q&A et de modération Formateur (Audit #7 & Feature D2)
 */
function renderDiscussionSection(
    string $courseSlug,
    string $returnPage,
    string $itemId,
    array $discussions,
    string $csrfToken,
    bool $isStaff = false
): string {
    $labelItem = $returnPage === 'lab' ? 'cet Atelier' : 'ce Module';
    ob_start();
    ?>
    <section id="discussion-section" class="lms-card" style="margin-top: 1.5rem;">
        <span class="badge-code">ENTRAIDE TECHNIQUE & ACCOMPAGNEMENT FORMATEUR</span>
        <h3 style="font-size: 1.15rem; margin: 0.35rem 0 0.75rem;">💬 Questions & Réponses sur <?= Security::e($labelItem) ?> (<span class="tabular-nums"><?= count($discussions) ?></span>)</h3>
        <?php if (!empty($discussions)): ?>
            <div style="display: flex; flex-direction: column; gap: 0.65rem; margin-bottom: 1rem;">
                <?php foreach ($discussions as $msg):
                    $msgId = (string)($msg['id'] ?? '');
                    $isResolved = !empty($msg['resolved']);
                ?>
                    <div style="padding: 0.75rem 1rem; border-radius: var(--radius-sm); background: var(--bg-base); border: 1px solid <?= $isResolved ? 'rgba(16, 185, 129, 0.45)' : 'var(--border-hairline)' ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.3rem; font-size: 0.78rem;">
                            <span style="display: inline-flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                <strong><?= Security::e((string)($msg['user_name'] ?? 'Apprenant')) ?></strong>
                                <span class="role-badge role-<?= Security::e((string)($msg['user_role'] ?? 'student')) ?>"><?= Security::e((string)($msg['user_role'] ?? 'student')) ?></span>
                                <?php if ($isResolved): ?>
                                    <span class="badge-completed" style="font-size: 0.7rem;">✔ Résolu</span>
                                <?php endif; ?>
                            </span>
                            <div style="display: inline-flex; align-items: center; gap: 0.45rem;">
                                <span style="color: var(--text-muted);" class="tabular-nums"><?= Security::e(substr((string)($msg['created_at'] ?? ''), 0, 16)) ?></span>
                                <?php if ($isStaff && $msgId !== ''): ?>
                                    <form method="POST" action="index.php?page=<?= urlencode($returnPage) ?>&action=moderate_discussion&course=<?= urlencode($courseSlug) ?>" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                        <input type="hidden" name="item_id" value="<?= Security::e($itemId) ?>"/>
                                        <input type="hidden" name="message_id" value="<?= Security::e($msgId) ?>"/>
                                        <input type="hidden" name="return_page" value="<?= Security::e($returnPage) ?>"/>
                                        <button type="submit" name="mod_action" value="resolve" class="btn btn-outline btn-sm" style="padding: 0.1rem 0.45rem; font-size: 0.7rem;" title="Basculer statut Résolu">
                                            <?= $isResolved ? '↩ Rouvrir' : '✔ Résolu' ?>
                                        </button>
                                        <button type="submit" name="mod_action" value="delete" class="btn btn-danger btn-sm" style="padding: 0.1rem 0.45rem; font-size: 0.7rem;" title="Supprimer ce message">
                                            🗑️
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.86rem; color: var(--text-secondary); white-space: pre-line;"><?= Security::e((string)($msg['message'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="index.php?page=<?= urlencode($returnPage) ?>&action=post_discussion&course=<?= urlencode($courseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <input type="hidden" name="item_id" value="<?= Security::e($itemId) ?>"/>
            <input type="hidden" name="return_page" value="<?= Security::e($returnPage) ?>"/>
            <textarea name="message" class="form-control" rows="2" required placeholder="Posez une question technique ou partagez un retour d'expérience sur <?= Security::e($labelItem) ?>..."></textarea>
            <div style="margin-top: 0.5rem; text-align: right;">
                <button type="submit" class="btn btn-primary btn-sm">💬 Publier ma question / remarque</button>
            </div>
        </form>
    </section>
    <?php
    return (string)ob_get_clean();
}

// Déterminer le parcours et l'élément actif si on consulte un module, un lab ou un filtre de catalogue
$activeSidebarTrack = (string)($_GET['track'] ?? '');
$activeModuleId = '';
if ($page === 'module') {
    $activeModuleId = (string)($_GET['id'] ?? 'module_01_introduction_postgresql_18');
    if ($activeSidebarTrack === '') {
        $activeSidebarTrack = (string)($currentCourse['modules'][$activeModuleId]['track_id'] ?? '');
    }
} elseif ($page === 'lab') {
    $lId = (string)($_GET['id'] ?? '');
    $activeModuleId = (string)($currentCourse['labs'][$lId]['linked_module_id'] ?? '');
    if ($activeSidebarTrack === '') {
        $activeSidebarTrack = (string)($currentCourse['labs'][$lId]['track_id'] ?? '');
    }
}
$completedModulesList = $userProgress['completed_modules'] ?? [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?= Security::renderUnloadShimScript() ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= Security::e($csrfToken) ?>"/>
    <title><?= Security::e($currentCourse['short_title'] ?? 'Formation') ?> — <?= Security::e($appConfig['app_name']) ?></title>
    <link rel="icon" href="assets/img/favicon.ico"/>
    <link rel="stylesheet" href="assets/css/app.css"/>
</head>
<body>
<!-- Barre de progression de lecture globale (ScrollSpy) -->
<div id="reading-progress-container" class="reading-progress-container" aria-hidden="true">
    <div id="reading-progress-bar" class="reading-progress-bar"></div>
</div>

<div class="app-shell">

    <!-- ==================================================================
         SIDEBAR DE NAVIGATION PRINCIPALE + ACCÈS DIRECT AUX 34 MODULES
         ================================================================== -->
    <aside class="app-sidebar" id="app-left-sidebar">
        <div class="sidebar-brand">
            <a href="index.php?page=dashboard&course=<?= urlencode($currentCourseSlug) ?>" class="sidebar-brand-link">
                <img src="assets/img/lightpath_logo_white_text.png"
                     data-dark-src="assets/img/lightpath_logo_white_text.png"
                     data-light-src="assets/img/lightpath_logo_dark_text.png"
                     alt="LightPath"
                     class="brand-logo theme-aware-logo"/>
                <img src="assets/img/postgresql_elephant_dark.png"
                     data-dark-src="assets/img/postgresql_elephant_dark.png"
                     data-light-src="assets/img/postgresql_elephant_light.png"
                     alt="PG18"
                     class="pg-icon theme-aware-logo"/>
            </a>
            <button type="button"
                    id="btn-collapse-left-inline"
                    class="btn-sidebar-collapse-inline"
                    title="Réduire la barre verticale de gauche (Alt+G)"
                    aria-label="Réduire la barre verticale de gauche">
                ◂
            </button>
        </div>

        <!-- Sélecteur Multi-Formations -->
        <div class="sidebar-course-selector">
            <label for="course-switcher">🎓 Formation Active</label>
            <select id="course-switcher" class="course-select" onchange="window.location.href='index.php?page=dashboard&course=' + encodeURIComponent(this.value)">
                <?php foreach ($allCourses as $cSlug => $cItem): ?>
                    <option value="<?= Security::e((string)$cSlug) ?>" <?= $cSlug === $currentCourseSlug ? 'selected' : '' ?>>
                        <?= Security::e($cItem['short_title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Espace Apprenant</div>
            <a href="index.php?page=dashboard&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
                <span>🏠 Tableau de Bord</span>
                <span class="nav-badge tabular-nums"><?= $globalCompletionPct ?>%</span>
            </a>
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= ($page === 'catalog' && ($_GET['tab'] ?? '') !== 'labs' && $activeSidebarTrack === '') ? 'active' : '' ?>">
                <span>📚 Catalogue des Parcours</span>
                <span class="nav-badge tabular-nums"><?= $doneModules ?>/<?= $totalModules ?></span>
            </a>
            <a href="index.php?page=module&id=<?= urlencode($activeModuleId !== '' ? $activeModuleId : 'module_01_introduction_postgresql_18') ?>&mode=slides&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= ($page === 'module' && ($_GET['mode'] ?? '') === 'slides') ? 'active' : '' ?>">
                <span>🖥️ Diaporama Studio 16:9</span>
                <span class="nav-badge">Slides</span>
            </a>
            <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= ($page === 'lab' || ($page === 'catalog' && ($_GET['tab'] ?? '') === 'labs')) ? 'active' : '' ?>">
                <span>🧪 Ateliers & Labs Docker</span>
                <span class="nav-badge tabular-nums"><?= $doneLabs ?>/<?= $totalLabs ?></span>
            </a>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'documents' ? 'active' : '' ?>">
                <span>📥 Téléchargements</span>
                <span class="nav-badge">6 formats</span>
            </a>
            <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'certificate' ? 'active' : '' ?>">
                <span>🎓 Certificat Qualiopi</span>
                <span class="nav-badge">Bloom</span>
            </a>

            <div class="nav-section-title" style="margin-top: 0.85rem;">🎯 Évaluations & Outils Qualiopi</div>
            <a href="index.php?page=diagnostic&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'diagnostic' ? 'active' : '' ?>">
                <span>🧭 Positionnement Initial</span>
                <span class="nav-badge tabular-nums"><?= $userDiagnostic !== null ? ((int)$userDiagnostic['percent'] . '%') : '15 QCM' ?></span>
            </a>
            <a href="index.php?page=exam&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'exam' ? 'active' : '' ?>">
                <span>🏆 Examen Final Certifiant</span>
                <span class="nav-badge tabular-nums"><?= $userBestExam !== null ? ((int)$userBestExam['percent'] . '%') : '40 QCM' ?></span>
            </a>
            <a href="index.php?page=attendance&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'attendance' ? 'active' : '' ?>">
                <span>✍️ Émargement & Assiduité</span>
                <span class="nav-badge tabular-nums"><?= count($userAttendance ?? []) ?>/20</span>
            </a>
            <a href="index.php?page=flashcards&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'flashcards' ? 'active' : '' ?>">
                <span>🧠 Cartes SRS & Glossaire</span>
                <span class="nav-badge tabular-nums"><?= count(\LightPath\LMS\Course\LmsCatalog::getFlashcards()) ?> SRS</span>
            </a>
            <a href="index.php?page=notes&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'notes' ? 'active' : '' ?>">
                <span>📝 Mon Carnet de Notes</span>
                <span class="nav-badge tabular-nums"><?= count($userProgress['notes'] ?? []) ?></span>
            </a>
            <a href="index.php?page=satisfaction&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'satisfaction' ? 'active' : '' ?>">
                <span>⭐ Satisfaction Qualiopi</span>
                <span class="nav-badge">Chaud/Froid</span>
            </a>
            <a href="index.php?page=verify&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'verify' ? 'active' : '' ?>">
                <span>🛡️ Vérifier un Certificat</span>
                <span class="nav-badge">QR</span>
            </a>
            <a href="index.php?page=search&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'search' ? 'active' : '' ?>">
                <span>🔍 Recherche Plein-Texte</span>
                <span class="nav-badge">Ctrl+K</span>
            </a>

            <!-- NAVIGATION DIRECTE PAR PARCOURS ET PAR MODULE (M01..M29, SQL-01..SQL-05) -->
            <div class="nav-section-title" style="margin-top: 0.9rem; display: flex; justify-content: space-between; align-items: center;">
                <span>Navigation Directe (34 Modules)</span>
            </div>
            <div class="sidebar-module-filter-wrap">
                <input type="search"
                       id="sidebar-module-filter"
                       class="sidebar-module-filter"
                       placeholder="Filtrer un module (ex: M01, MVCC, SQL)..."
                       autocomplete="off"
                       aria-label="Filtrer les modules dans la barre latérale"/>
            </div>

            <div id="sidebar-tracks-accordion" class="sidebar-tracks-accordion">
                <?php
                $allCourseModules = $currentCourse['modules'] ?? [];
                foreach (($currentCourse['tracks'] ?? []) as $tKey => $track):
                    $tFilter = $track['module_Filter'] ?? [];
                    $isTrackActive = ($activeSidebarTrack === (string)$tKey);
                    $shouldOpen = $isTrackActive || ($activeSidebarTrack === '' && $tKey === 'cursus-complet');

                    // Collecter les modules appartenant à ce parcours
                    $trackModsList = [];
                    $trackDone = 0;
                    foreach ($allCourseModules as $mKey => $mInfo) {
                        if (in_array((string)$mInfo['code'], $tFilter, true)) {
                            $trackModsList[$mKey] = $mInfo;
                            if (in_array($mKey, $completedModulesList, true)) {
                                $trackDone++;
                            }
                        }
                    }
                ?>
                    <details class="sidebar-track-group <?= $isTrackActive ? 'is-active-track' : '' ?>"
                             data-track-id="<?= Security::e((string)$tKey) ?>"
                             <?= $shouldOpen ? 'open' : '' ?>>
                        <summary class="sidebar-track-summary" title="<?= Security::e($track['title']) ?>">
                            <span class="nav-track-label">
                                <span class="track-dot" style="background: <?= Security::e($track['color'] ?? '#00B0F0') ?>;"></span>
                                <span class="nav-track-text"><?= Security::e($track['short_title'] ?? $track['title']) ?></span>
                            </span>
                            <span class="nav-badge tabular-nums"><?= $trackDone ?>/<?= count($trackModsList) ?></span>
                        </summary>

                        <div class="sidebar-track-modules">
                            <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>"
                               class="sidebar-track-overview-link">
                                🧭 Vue d'ensemble du <?= Security::e($track['number'] ?? 'Parcours') ?> →
                            </a>
                            <?php foreach ($trackModsList as $mKey => $mInfo):
                                $isCurrentMod = ($activeModuleId === (string)$mKey);
                                $isDoneMod = in_array((string)$mKey, $completedModulesList, true);
                                $shortLabel = preg_replace('/^Module\s+(?:SQL\s*)?\d+\s*[—\-:]\s*/iu', '', (string)$mInfo['title']) ?? (string)$mInfo['title'];
                            ?>
                                <a href="index.php?page=module&id=<?= urlencode((string)$mKey) ?>&course=<?= urlencode($currentCourseSlug) ?>"
                                   class="sidebar-module-link <?= $isCurrentMod ? 'active' : '' ?> <?= $isDoneMod ? 'is-done' : '' ?>"
                                   data-module-id="<?= Security::e((string)$mKey) ?>"
                                   data-search-text="<?= Security::e(strtolower($mInfo['badge_code'] . ' ' . $mInfo['title'])) ?>"
                                   title="<?= Security::e($mInfo['badge_code'] . ' — ' . $mInfo['title']) ?>">
                                    <span class="sidebar-mod-code tabular-nums"><?= Security::e($mInfo['badge_code']) ?></span>
                                    <span class="sidebar-mod-title"><?= Security::e($shortLabel) ?></span>
                                    <?php if ($isDoneMod): ?>
                                        <span class="sidebar-mod-check" title="Module terminé">✔</span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <?php if ($auth->isStaff($currentUser)): ?>
                <div class="nav-section-title" style="margin-top: 1rem;">Administration & Formateur</div>
                <a href="index.php?page=admin&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'admin' ? 'active' : '' ?>">
                    <span>🛡️ Cockpit Administration</span>
                    <span class="nav-badge">Admin</span>
                </a>
            <?php endif; ?>

            <?php if ($currentUser !== null): ?>
                <div class="nav-section-title" style="margin-top: 1rem;">Mon Compte</div>
                <a href="index.php?page=profile&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'profile' ? 'active' : '' ?>">
                    <span>👤 Profil & Paramètres</span>
                </a>
                <a href="index.php?action=logout" class="nav-item" style="color: #FB7185;">
                    <span>🚪 Déconnexion</span>
                </a>
            <?php else: ?>
                <div class="nav-section-title" style="margin-top: 1rem;">Accès Portail</div>
                <a href="index.php?page=login" class="nav-item" style="color: var(--accent-cyan);">
                    <span>🔐 Connexion</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div><strong><?= Security::e($appConfig['company']) ?></strong></div>
            <div>Formateur : <?= Security::e($appConfig['trainer_name']) ?></div>
            <div style="margin-top: 0.25rem;">Certifié Qualiopi & Datadock</div>
        </div>
    </aside>

    <!-- ==================================================================
         ZONE PRINCIPALE (TOPBAR + VUE DYNAMIQUE)
         ================================================================== -->
    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left-group">
                <button type="button"
                        id="btn-toggle-left-sidebar"
                        class="btn btn-outline btn-sm btn-sidebar-toggle"
                        title="Réduire / Afficher la barre verticale de gauche (Alt+G)"
                        aria-label="Réduire ou afficher la barre verticale de gauche">
                    <span class="sidebar-toggle-icon">◧</span>
                    <span id="label-toggle-left-sidebar" class="sidebar-toggle-label">Menu Gauche</span>
                </button>
                <div class="topbar-search">
                    <span class="search-icon">🔍</span>
                    <input type="search" id="global-search-input" placeholder="Filtrer un module, lab, commande SQL, cheatsheet... (Ctrl+K)"/>
                </div>
            </div>

            <div class="topbar-actions">
                <?php if ($page === 'module' || $page === 'lab'): ?>
                    <button type="button"
                            id="btn-toggle-right-sidebar"
                            class="btn btn-outline btn-sm btn-sidebar-toggle"
                            title="Réduire / Afficher la barre verticale de droite (Alt+D)"
                            aria-label="Réduire ou afficher la barre verticale de droite">
                        <span class="sidebar-toggle-icon">◨</span>
                        <span id="label-toggle-right-sidebar" class="sidebar-toggle-label">Menu Droite</span>
                    </button>
                <?php endif; ?>

                <?php if ($currentUser !== null): ?>
                    <!-- Statut du Conteneur Docker Personnel (rafraîchi en asynchrone sans bloquer la navigation) -->
                    <a href="index.php?page=dashboard&course=<?= urlencode($currentCourseSlug) ?>#docker-panel"
                       id="topbar-docker-pill"
                       class="docker-pill"
                       title="État de votre conteneur PostgreSQL 18 dédié">
                        <span id="topbar-docker-dot" class="status-dot <?= !empty($dockerStatus['running']) ? 'online' : 'offline' ?>"></span>
                        <span id="topbar-docker-text" class="tabular-nums">
                            🐳 <?= Security::e($dockerStatus['container_name']) ?>
                            (Port <?= (int)$dockerStatus['port'] ?> : <?= !empty($dockerStatus['running']) ? 'ACTIF' : 'PRÊT' ?>)
                        </span>
                    </a>

                    <span class="badge-code tabular-nums" title="<?= Security::e($userGamification['rank'] ?? 'DBA PostgreSQL 18') ?>">
                        ⚡ <?= (int)($userGamification['xp'] ?? 0) ?> XP • 🏅 <?= (int)($userGamification['unlocked_count'] ?? 0) ?>/<?= (int)($userGamification['total_badges'] ?? 8) ?>
                    </span>
                <?php endif; ?>

                <button type="button" id="btn-toggle-theme" class="btn btn-outline btn-sm" title="Basculer Thème Sombre / Clair">
                    🌗 Thème
                </button>

                <?php if ($currentUser !== null): ?>
                    <div class="user-chip">
                        <span>👤 <strong><?= Security::e($currentUser['name']) ?></strong></span>
                        <span class="role-badge role-<?= Security::e($currentUser['role']) ?>"><?= Security::e($currentUser['role']) ?></span>
                    </div>
                <?php else: ?>
                    <a href="index.php?page=login" class="btn btn-primary btn-sm">
                        🔐 Connexion
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <main class="app-content">
            <?php
            $classroomState = $storage->getClassroomState($currentCourseSlug);
            $broadcastMsg = trim((string)($classroomState['broadcast_message'] ?? ''));
            ?>
            <?php if ($broadcastMsg !== ''): ?>
                <div class="flash-banner" style="background: rgba(56, 189, 248, 0.14); border: 1px solid rgba(56, 189, 248, 0.45); color: var(--text-primary); display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <span class="badge-code" style="margin-right: 0.45rem;">📢 MESSAGE SALLE EN DIRECT</span>
                        <strong><?= Security::e($broadcastMsg) ?></strong>
                        <?php if (!empty($classroomState['updated_by'])): ?>
                            <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 0.5rem;">
                                (<?= Security::e((string)$classroomState['updated_by']) ?> • <?= Security::e(substr((string)($classroomState['updated_at'] ?? ''), 11, 5)) ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="flash-banner flash-error"><?= Security::e($flashError) ?></div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="flash-banner flash-success"><?= Security::e($flashSuccess) ?></div>
            <?php endif; ?>

            <?php
            $targetTemplate = match ($page) {
                'catalog' => __DIR__ . '/pages/catalog.php',
                'module' => __DIR__ . '/pages/module_view.php',
                'lab' => __DIR__ . '/pages/lab_view.php',
                'documents', 'cheatsheets' => __DIR__ . '/pages/documents.php',
                'certificate' => __DIR__ . '/pages/certificate.php',
                'verify' => __DIR__ . '/pages/verify.php',
                'diagnostic' => __DIR__ . '/pages/diagnostic.php',
                'exam' => __DIR__ . '/pages/exam.php',
                'attendance' => __DIR__ . '/pages/attendance.php',
                'satisfaction' => __DIR__ . '/pages/satisfaction.php',
                'flashcards' => __DIR__ . '/pages/flashcards.php',
                'notes' => __DIR__ . '/pages/notes.php',
                'search' => __DIR__ . '/pages/search.php',
                'admin' => $auth->isStaff($currentUser) ? (__DIR__ . '/pages/admin.php') : (__DIR__ . '/pages/dashboard.php'),
                'profile' => __DIR__ . '/pages/profile.php',
                default => __DIR__ . '/pages/dashboard.php',
            };
            require $targetTemplate;
            ?>
        </main>
    </div>
</div>

<?php if ($page === 'module' || $page === 'lab'): ?>
<button type="button"
        id="btn-floating-restore-right"
        class="floating-restore-right-btn"
        title="Réafficher la barre verticale de droite (Alt+D)">
    ◂ Afficher Menu Droite
</button>
<?php endif; ?>

<!-- Lightbox Plein Écran pour Schémas Mermaid & Infographies Studio -->
<div id="diagram-lightbox" class="diagram-lightbox" hidden>
    <div class="diagram-lightbox-backdrop"></div>
    <div class="diagram-lightbox-content">
        <div class="diagram-lightbox-toolbar">
            <span id="diagram-lightbox-caption" class="diagram-lightbox-title">Schéma d'Architecture PostgreSQL 18</span>
            <button type="button" id="btn-close-lightbox" class="btn btn-sm btn-danger">✕ Fermer (Échap)</button>
        </div>
        <div class="diagram-lightbox-body">
            <img id="diagram-lightbox-img" src="" alt="Schéma agrandi"/>
        </div>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
