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
    ];
    $out = '<div class="download-strip">';
    foreach ($downloads as $fmt => $info) {
        $label = $icons[$fmt] ?? strtoupper($fmt);
        $size = !$compact && !empty($info['size_formatted']) ? ' <span style="opacity:0.65;font-size:0.66rem;">(' . Security::e($info['size_formatted']) . ')</span>' : '';
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= Security::e($csrfToken) ?>"/>
    <title><?= Security::e($currentCourse['short_title'] ?? 'Formation') ?> — <?= Security::e($appConfig['app_name']) ?></title>
    <link rel="icon" href="assets/img/favicon.ico"/>
    <link rel="stylesheet" href="assets/css/app.css"/>
</head>
<body>
<div class="app-shell">

    <!-- ==================================================================
         SIDEBAR DE NAVIGATION PRINCIPALE
         ================================================================== -->
    <aside class="app-sidebar">
        <a href="index.php?page=dashboard&course=<?= urlencode($currentCourseSlug) ?>" class="sidebar-brand">
            <img src="assets/img/lightpath_logo_white_text.png" alt="LightPath" class="brand-logo"/>
            <img src="assets/img/postgresql_elephant.png" alt="PG18" class="pg-icon"/>
        </a>

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
                <span class="nav-badge"><?= $globalCompletionPct ?>%</span>
            </a>
            <a href="index.php?page=catalog&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= ($page === 'catalog' || $page === 'module') ? 'active' : '' ?>">
                <span>📚 Modules de Cours</span>
                <span class="nav-badge"><?= $doneModules ?>/<?= $totalModules ?></span>
            </a>
            <a href="index.php?page=catalog&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= ($page === 'lab' || ($page === 'catalog' && ($_GET['tab'] ?? '') === 'labs')) ? 'active' : '' ?>">
                <span>🧪 Ateliers & Labs Docker</span>
                <span class="nav-badge"><?= $doneLabs ?>/<?= $totalLabs ?></span>
            </a>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'documents' ? 'active' : '' ?>">
                <span>📥 Centre de Téléchargement</span>
                <span class="nav-badge">Tous formats</span>
            </a>
            <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'certificate' ? 'active' : '' ?>">
                <span>🎓 Certificat Qualiopi</span>
                <span class="nav-badge">Bloom</span>
            </a>

            <div class="nav-section-title" style="margin-top: 1rem;">Parcours Pédagogiques</div>
            <?php foreach (($currentCourse['tracks'] ?? []) as $tKey => $track): ?>
                <a href="index.php?page=catalog&track=<?= urlencode((string)$tKey) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item" style="font-size: 0.82rem;">
                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">• <?= Security::e($track['title']) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($auth->isStaff($currentUser)): ?>
                <div class="nav-section-title" style="margin-top: 1rem;">Administration & Formateur</div>
                <a href="index.php?page=admin&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'admin' ? 'active' : '' ?>">
                    <span>🛡️ Cockpit Administration</span>
                    <span class="nav-badge">Admin</span>
                </a>
            <?php endif; ?>

            <div class="nav-section-title" style="margin-top: 1rem;">Mon Compte</div>
            <a href="index.php?page=profile&course=<?= urlencode($currentCourseSlug) ?>" class="nav-item <?= $page === 'profile' ? 'active' : '' ?>">
                <span>👤 Profil & Paramètres</span>
            </a>
            <a href="index.php?action=logout" class="nav-item" style="color: #FB7185;">
                <span>🚪 Déconnexion</span>
            </a>
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
            <div class="topbar-search">
                <span class="search-icon">🔍</span>
                <input type="search" id="global-search-input" placeholder="Filtrer un module, lab, commande SQL, cheatsheet... (Ctrl+K)"/>
            </div>

            <div class="topbar-actions">
                <!-- Statut du Conteneur Docker Personnel -->
                <a href="index.php?page=dashboard&course=<?= urlencode($currentCourseSlug) ?>#docker-panel" class="docker-pill" title="État de votre conteneur PostgreSQL 18 dédié">
                    <span class="status-dot <?= !empty($dockerStatus['running']) ? 'online' : 'offline' ?>"></span>
                    <span class="tabular-nums">
                        🐳 <?= Security::e($dockerStatus['container_name']) ?>
                        (Port <?= (int)$dockerStatus['port'] ?> : <?= !empty($dockerStatus['running']) ? 'ACTIF' : 'PRÊT' ?>)
                    </span>
                </a>

                <button type="button" id="btn-toggle-theme" class="btn btn-outline btn-sm" title="Basculer Thème Sombre / Clair">
                    🌗 Thème
                </button>

                <div class="user-chip">
                    <span>👤 <strong><?= Security::e($currentUser['name']) ?></strong></span>
                    <span class="role-badge role-<?= Security::e($currentUser['role']) ?>"><?= Security::e($currentUser['role']) ?></span>
                </div>
            </div>
        </header>

        <main class="app-content">
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
                'documents' => __DIR__ . '/pages/documents.php',
                'certificate' => __DIR__ . '/pages/certificate.php',
                'admin' => $auth->isStaff($currentUser) ? (__DIR__ . '/pages/admin.php') : (__DIR__ . '/pages/dashboard.php'),
                'profile' => __DIR__ . '/pages/profile.php',
                default => __DIR__ . '/pages/dashboard.php',
            };
            require $targetTemplate;
            ?>
        </main>
    </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
