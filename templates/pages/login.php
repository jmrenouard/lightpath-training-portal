<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
$activeTab = ($_GET['tab'] ?? 'login') === 'register' ? 'register' : 'login';
$csrfToken = Security::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Connexion — <?= Security::e($appConfig['app_name']) ?> (LightPath SARL)</title>
    <link rel="icon" href="assets/img/favicon.ico"/>
    <link rel="stylesheet" href="assets/css/app.css"/>
</head>
<body>
<div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; background: radial-gradient(circle at top right, rgba(0, 176, 240, 0.14), transparent 55%), var(--bg-base);">
    <div style="max-width: 980px; width: 100%; display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); background: var(--bg-surface); border: 1px solid var(--border-strong); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-elevated);">

        <!-- Colonne Branding LightPath -->
        <div style="padding: 2.5rem; background: linear-gradient(160deg, #0B1120 0%, #1E293B 100%); display: flex; flex-direction: column; justify-content: space-between; border-right: 1px solid var(--border-hairline);">
            <div>
                <div style="display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1.75rem;">
                    <img src="assets/img/lightpath_logo_white_text.png" alt="LightPath SARL" style="height: 44px;"/>
                    <img src="assets/img/postgresql_elephant.png" alt="PostgreSQL 18" style="height: 36px;"/>
                </div>
                <span class="badge-code">PORTAIL LMS MULTI-FORMATIONS • QUALIOPI</span>
                <h1 style="font-size: 1.75rem; margin-top: 0.85rem; margin-bottom: 0.75rem;">LightPath Training Portal</h1>
                <p style="color: var(--text-secondary); font-size: 0.92rem;">
                    Plateforme interactive d'apprentissage et d'entraînement pratique :
                    <strong>34 Modules de cours</strong>, <strong>34 Ateliers Docker</strong>, <strong>Console SQL Live</strong>,
                    <strong>Quiz QCM</strong> et téléchargements multi-formats (<code>PDF</code>, <code>PPTX</code>, <code>MD</code>, <code>HTML</code>, <code>DOCX</code>).
                </p>

                <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(0, 176, 240, 0.08); border: 1px solid var(--border-strong); border-radius: var(--radius-md);">
                    <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-cyan-light); margin-bottom: 0.5rem;">
                        ⚡ Connexion Rapide Démo (1-Clic)
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.45rem;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="fillDemo('jmrenouard@lightpath.fr','LightPath2026!')" style="justify-content: flex-start;">
                            🛡️ <strong>Admin :</strong>&nbsp;jmrenouard@lightpath.fr
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="fillDemo('formateur@lightpath.fr','Formateur2026!')" style="justify-content: flex-start;">
                            🎓 <strong>Formateur :</strong>&nbsp;formateur@lightpath.fr
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="fillDemo('stagiaire@lightpath.fr','Stagiaire2026!')" style="justify-content: flex-start;">
                            👨‍💻 <strong>Stagiaire :</strong>&nbsp;stagiaire@lightpath.fr
                        </button>
                    </div>
                </div>
            </div>

            <div style="margin-top: 2rem; font-size: 0.78rem; color: var(--text-muted); border-top: 1px solid var(--border-hairline); padding-top: 1rem;">
                <strong><?= Security::e($appConfig['trainer_name']) ?></strong><br/>
                <?= Security::e($appConfig['trainer_title']) ?><br/>
                <?= Security::e($appConfig['contact_email']) ?> • <a href="<?= Security::e($appConfig['website']) ?>" target="_blank">www.lightpath.fr</a>
            </div>
        </div>

        <!-- Colonne Formulaire -->
        <div style="padding: 2.5rem;">
            <?php if ($flashError): ?>
                <div class="flash-banner flash-error"><?= Security::e($flashError) ?></div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="flash-banner flash-success"><?= Security::e($flashSuccess) ?></div>
            <?php endif; ?>

            <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-hairline); padding-bottom: 0.75rem;">
                <a href="index.php?page=login&tab=login" class="btn <?= $activeTab === 'login' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Se Connecter</a>
                <a href="index.php?page=login&tab=register" class="btn <?= $activeTab === 'register' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Créer un Compte Apprenant</a>
            </div>

            <?php if ($activeTab === 'login'): ?>
                <h2 style="font-size: 1.35rem; margin-bottom: 0.35rem;">Accéder à votre Espace Formation</h2>
                <p style="color: var(--text-secondary); font-size: 0.86rem; margin-bottom: 1.35rem;">
                    Connectez-vous pour retrouver votre progression, vos supports et votre conteneur Docker PostgreSQL 18.
                </p>
                <form method="POST" action="index.php?action=login" id="login-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <div class="form-group">
                        <label for="login-email">Adresse Email Professionnelle</label>
                        <input type="email" id="login-email" name="email" class="form-control" required value="jmrenouard@lightpath.fr"/>
                    </div>
                    <div class="form-group">
                        <label for="login-password">Mot de Passe</label>
                        <input type="password" id="login-password" name="password" class="form-control" required value="LightPath2026!"/>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem; padding: 0.75rem;">
                        🚀 Se connecter au Portail LightPath
                    </button>
                </form>
            <?php else: ?>
                <h2 style="font-size: 1.35rem; margin-bottom: 0.35rem;">Inscription Nouvel Apprenant</h2>
                <p style="color: var(--text-secondary); font-size: 0.86rem; margin-bottom: 1.35rem;">
                    Créez votre compte pour accéder immédiatement aux modules, quiz et à votre instance Docker dédiée.
                </p>
                <form method="POST" action="index.php?action=register">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <div class="form-group">
                        <label>Nom & Prénom</label>
                        <input type="text" name="name" class="form-control" required placeholder="Ex: Sophie Laurent"/>
                    </div>
                    <div class="form-group">
                        <label>Adresse Email</label>
                        <input type="email" name="email" class="form-control" required placeholder="sophie.laurent@entreprise.fr"/>
                    </div>
                    <div class="form-group">
                        <label>Entreprise / Organisation</label>
                        <input type="text" name="company" class="form-control" placeholder="Ex: Direction Technique"/>
                    </div>
                    <div class="form-group">
                        <label>Mot de passe (6 caractères min.)</label>
                        <input type="password" name="password" class="form-control" required minlength="6"/>
                    </div>
                    <button type="submit" class="btn btn-emerald" style="width: 100%; margin-top: 0.5rem; padding: 0.75rem;">
                        ✨ Créer mon compte & Démarrer la formation
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function fillDemo(email, pass) {
    const emailInput = document.getElementById('login-email');
    const passInput = document.getElementById('login-password');
    if (emailInput && passInput) {
        emailInput.value = email;
        passInput.value = pass;
        document.getElementById('login-form').submit();
    } else {
        window.location.href = 'index.php?page=login&tab=login';
    }
}
</script>
</body>
</html>
