<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;
?>

<div class="profile-container">
    <span class="badge-code">PARAMÈTRES DU COMPTE</span>
    <h1 class="hero-title-lg mt-xs mb-md">👤 Mon Profil Apprenant / Formateur</h1>

    <div class="lms-card">
        <form method="POST" action="index.php?page=profile&action=update_profile">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div class="form-group">
                <label for="profile-email">Adresse Email (Identifiant unique)</label>
                <input type="email" id="profile-email" class="form-control" autocomplete="username" value="<?= Security::e($currentUser['email']) ?>" disabled/>
            </div>
            <div class="form-group">
                <label for="profile-role">Rôle sur la plateforme</label>
                <input type="text" id="profile-role" class="form-control" value="<?= strtoupper(Security::e($currentUser['role'])) ?>" disabled/>
            </div>
            <div class="form-group">
                <label for="profile-name">Nom &amp; Prénom (affiché sur l'Attestation Qualiopi)</label>
                <input type="text" id="profile-name" name="name" class="form-control" autocomplete="name" value="<?= Security::e($currentUser['name']) ?>" required/>
            </div>
            <div class="form-group">
                <label for="profile-company">Entreprise / Organisation</label>
                <input type="text" id="profile-company" name="company" class="form-control" autocomplete="organization" value="<?= Security::e($currentUser['company'] ?? '') ?>"/>
            </div>
            <div class="form-group">
                <label for="profile-current-password">Mot de Passe Actuel (requis pour changer de mot de passe)</label>
                <input type="password" id="profile-current-password" name="current_password" class="form-control" autocomplete="current-password" placeholder="Requis uniquement en cas de modification"/>
            </div>
            <div class="form-group">
                <label for="profile-new-password">Nouveau Mot de Passe (laisser vide pour ne pas modifier)</label>
                <input type="password" id="profile-new-password" name="new_password" class="form-control" autocomplete="new-password" minlength="6" placeholder="Minimum 6 caractères"/>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-xs">
                💾 Enregistrer mes Modifications
            </button>
        </form>
    </div>
</div>
