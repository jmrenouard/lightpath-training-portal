<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
$allowSelfRegistration = isset($featureManager) ? $featureManager->isEnabled('self_registration') : true;
$allowDemoQuickLogin = isset($featureManager) ? $featureManager->isEnabled('demo_quick_login') : false;
$activeTab = ($allowSelfRegistration && ($_GET['tab'] ?? 'login') === 'register') ? 'register' : 'login';
$csrfToken = Security::getCsrfToken();
$moduleCountLabel = !empty($currentCourse['modules']) ? count($currentCourse['modules']) : 34;
$labCountLabel = !empty($currentCourse['labs']) ? count($currentCourse['labs']) : 34;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?= Security::renderUnloadShimScript() ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Connexion — <?= Security::e($appConfig['app_name']) ?> (LightPath SARL)</title>
    <link rel="icon" href="assets/img/favicon.ico"/>
    <link rel="stylesheet" href="assets/css/app.css"/>
</head>
<body>
<div class="login-page-wrap">
    <div class="login-split-grid">

        <!-- LightPath branding column -->
        <div class="login-brand-col">
            <div>
                <div class="login-brand-logos">
                    <img src="assets/img/lightpath_logo_white_text.png" alt="LightPath SARL" class="login-logo-main"/>
                    <img src="assets/img/postgresql_elephant.png" alt="PostgreSQL 18" class="login-logo-pg"/>
                </div>
                <span class="badge-code">PORTAIL LMS MULTI-FORMATIONS • QUALIOPI</span>
                <h1 class="hero-title-lg mt-sm mb-sm">LightPath Training Portal</h1>
                <p class="text-secondary text-md">
                    Plateforme interactive d'apprentissage et d'entraînement pratique :
                    <strong class="tabular-nums"><?= (int)$moduleCountLabel ?> Modules de cours</strong>, <strong class="tabular-nums"><?= (int)$labCountLabel ?> Ateliers Docker</strong>, <strong>Console SQL Live</strong>,
                    <strong>Quiz QCM</strong> et téléchargements multi-formats (<code>PDF</code>, <code>PPTX</code>, <code>MD</code>, <code>HTML</code>, <code>DOCX</code>).
                </p>

                <?php if ($allowDemoQuickLogin): ?>
                <div class="login-demo-box">
                    <div class="sidebar-section-label mb-xs">
                        ⚡ Connexion Rapide Démo (1-Clic)
                    </div>
                    <div class="d-flex flex-col gap-xs">
                        <button type="button" class="btn btn-outline btn-sm login-demo-btn" data-demo-email="jmrenouard@lightpath.fr" data-demo-pass="LightPath2026!">
                            🛡️ <strong>Admin :</strong>&nbsp;jmrenouard@lightpath.fr
                        </button>
                        <button type="button" class="btn btn-outline btn-sm login-demo-btn" data-demo-email="formateur@lightpath.fr" data-demo-pass="Formateur2026!">
                            🎓 <strong>Formateur :</strong>&nbsp;formateur@lightpath.fr
                        </button>
                        <button type="button" class="btn btn-outline btn-sm login-demo-btn" data-demo-email="stagiaire@lightpath.fr" data-demo-pass="Stagiaire2026!">
                            👨‍💻 <strong>Stagiaire :</strong>&nbsp;stagiaire@lightpath.fr
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="login-footer-meta">
                <strong><?= Security::e($appConfig['trainer_name']) ?></strong><br/>
                <?= Security::e($appConfig['trainer_title']) ?><br/>
                <?= Security::e($appConfig['contact_email']) ?> • <a href="<?= Security::e($appConfig['website']) ?>" target="_blank" rel="noopener noreferrer">www.lightpath.fr</a>
            </div>
        </div>

        <!-- Authentication form column -->
        <div class="login-form-col">
            <?php if (!empty($flashError)): ?>
                <div class="flash-banner flash-error"><?= Security::e($flashError) ?></div>
            <?php endif; ?>
            <?php if (!empty($flashSuccess)): ?>
                <div class="flash-banner flash-success"><?= Security::e($flashSuccess) ?></div>
            <?php endif; ?>

            <div class="login-tabs-bar">
                <a href="index.php?page=login&tab=login" class="btn <?= $activeTab === 'login' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Se Connecter</a>
                <?php if ($allowSelfRegistration): ?>
                    <a href="index.php?page=login&tab=register" class="btn <?= $activeTab === 'register' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Créer un Compte Apprenant</a>
                <?php endif; ?>
            </div>

            <?php if ($activeTab === 'login'): ?>
                <h2 class="text-xl mb-xs">Accéder à votre Espace Formation</h2>
                <p class="text-secondary text-sm mb-md">
                    Connectez-vous pour retrouver votre progression, vos supports et votre conteneur Docker PostgreSQL 18.
                </p>
                <form method="POST" action="index.php?action=login" id="login-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <div class="form-group">
                        <label for="login-email">Adresse Email Professionnelle</label>
                        <input type="email" id="login-email" name="email" class="form-control" autocomplete="username" required placeholder="prenom.nom@entreprise.fr"/>
                    </div>
                    <div class="form-group">
                        <label for="login-password">Mot de Passe</label>
                        <input type="password" id="login-password" name="password" class="form-control" autocomplete="current-password" required/>
                    </div>
                    <button type="submit" class="btn btn-primary login-submit-btn">
                        🚀 Se connecter au Portail LightPath
                    </button>
                </form>
            <?php else: ?>
                <h2 class="text-xl mb-xs">Inscription Nouvel Apprenant</h2>
                <p class="text-secondary text-sm mb-md">
                    Créez votre compte pour accéder immédiatement aux modules, quiz et à votre instance Docker dédiée.
                </p>
                <form method="POST" action="index.php?action=register" id="register-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <div class="form-group">
                        <label for="register-name">Nom &amp; Prénom</label>
                        <input type="text" id="register-name" name="name" class="form-control" autocomplete="name" required placeholder="Ex: Sophie Laurent"/>
                    </div>
                    <div class="form-group">
                        <label for="register-email">Adresse Email</label>
                        <input type="email" id="register-email" name="email" class="form-control" autocomplete="email" required placeholder="sophie.laurent@entreprise.fr"/>
                    </div>
                    <div class="form-group">
                        <label for="register-company">Entreprise / Organisation</label>
                        <input type="text" id="register-company" name="company" class="form-control" autocomplete="organization" placeholder="Ex: Direction Technique"/>
                    </div>
                    <div class="form-group">
                        <label for="register-password">Mot de passe (6 caractères min.)</label>
                        <input type="password" id="register-password" name="password" class="form-control" autocomplete="new-password" required minlength="6"/>
                    </div>
                    <button type="submit" class="btn btn-emerald login-submit-btn">
                        ✨ Créer mon compte &amp; Démarrer la formation
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
