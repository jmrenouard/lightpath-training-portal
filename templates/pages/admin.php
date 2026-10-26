<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$adminTab = (string)($_GET['tab'] ?? 'cohort');
$allUsers = $storage->getUsers();
$allProgress = $storage->getAllProgress();
$labContainers = $docker->listAllLabContainers();
$auditLogs = $storage->getAuditLogs(40);
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div>
        <span class="badge-code">PORTAIL D'ADMINISTRATION & COCKPIT FORMATEUR</span>
        <h1 style="font-size: 1.65rem; margin: 0.4rem 0 0.25rem;">🛡️ Administration des Comptes, Cohortes, Docker & Formations</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Supervisez la progression des apprenants en temps réel, gérez les comptes utilisateurs, pilotez les conteneurs Docker par stagiaire, injectez des scénarios Chaos DBA et ajoutez de nouvelles formations.
        </p>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 0.45rem;">
        <a href="index.php?page=admin&tab=cohort&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'cohort' ? 'btn-primary' : 'btn-outline' ?>">📊 Suivi Cohorte & Qualiopi</a>
        <a href="index.php?page=admin&tab=users&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'users' ? 'btn-primary' : 'btn-outline' ?>">👥 Gestion des Comptes (<?= count($allUsers) ?>)</a>
        <a href="index.php?page=admin&tab=docker&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'docker' ? 'btn-primary' : 'btn-outline' ?>">🐳 Conteneurs Docker & Chaos DBA</a>
        <a href="index.php?page=admin&tab=courses&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'courses' ? 'btn-primary' : 'btn-outline' ?>">🎓 Multi-Formations (<?= count($allCourses) ?>)</a>
        <a href="index.php?page=admin&tab=audit&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'audit' ? 'btn-primary' : 'btn-outline' ?>">📜 Journal d'Audit</a>
    </div>
</div>

<?php if ($adminTab === 'cohort'): ?>
    <!-- 1. SUIVI DE COHORTE & PROGRESSION QUALIOPI -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <h2 style="font-size: 1.3rem; margin: 0;">📊 Matrice de Suivi Pédagogique de la Cohorte (<?= Security::e($currentCourse['short_title']) ?>)</h2>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="index.php?action=export_qualiopi_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                📊 Exporter Audit Qualiopi (CSV)
            </a>
            <a href="index.php?action=export_scorm_manifest&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
                📦 Exporter Manifeste SCORM 1.2 (imsmanifest.xml)
            </a>
        </div>
    </div>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Apprenant</th>
                    <th>Entreprise</th>
                    <th>Rôle</th>
                    <th>Modules Validés</th>
                    <th>Labs Docker Validés</th>
                    <th>Quiz / Examen</th>
                    <th>Émargement & Temps</th>
                    <th>Port Docker Dédié</th>
                    <th>Action Docker</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u):
                    $uid = (int)$u['id'];
                    $uProg = $storage->getUserProgress($uid, $currentCourseSlug);
                    $uMods = count($uProg['completed_modules'] ?? []);
                    $uLabs = count($uProg['completed_labs'] ?? []);
                    $uQuiz = count($uProg['quiz_scores'] ?? []);
                    $uDiag = $storage->getDiagnosticResult($uid, $currentCourseSlug);
                    $uExam = $storage->getBestExamAttempt($uid, $currentCourseSlug);
                    $uAtt = $storage->getUserAttendance($uid, $currentCourseSlug);
                    $uPort = $docker->getUserPort($uid);
                    $uContainer = $docker->getContainerName($uid);
                ?>
                    <tr>
                        <td class="tabular-nums">#<?= $uid ?></td>
                        <td>
                            <strong><?= Security::e($u['name']) ?></strong>
                            <div style="font-size: 0.76rem; color: var(--text-muted);"><?= Security::e($u['email']) ?></div>
                        </td>
                        <td><?= Security::e($u['company'] ?? '—') ?></td>
                        <td><span class="role-badge role-<?= Security::e($u['role']) ?>"><?= Security::e($u['role']) ?></span></td>
                        <td class="tabular-nums"><strong><?= $uMods ?></strong> / <?= $totalModules ?></td>
                        <td class="tabular-nums"><strong><?= $uLabs ?></strong> / <?= $totalLabs ?></td>
                        <td class="tabular-nums">
                            Quiz: <strong><?= $uQuiz ?></strong>/<?= $totalModules ?>
                            <?php if ($uDiag !== null): ?>
                                <div style="font-size: 0.74rem; color: var(--text-secondary);">Diag: <?= (int)$uDiag['percent'] ?>%</div>
                            <?php endif; ?>
                            <?php if ($uExam !== null): ?>
                                <div style="font-size: 0.74rem; color: <?= !empty($uExam['passed']) ? '#34D399' : '#FBBF24' ?>;">Exam: <?= (int)$uExam['percent'] ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td class="tabular-nums">
                            <strong><?= count($uAtt) ?></strong>/20 demi-j.
                            <div style="font-size: 0.74rem; color: var(--text-muted);"><?= (int)round(((int)($uProg['learning_seconds'] ?? 0)) / 60) ?> min LMS</div>
                        </td>
                        <td><code><?= Security::e($uContainer) ?> (<?= $uPort ?>)</code></td>
                        <td>
                            <button type="button" class="btn btn-emerald btn-sm btn-docker-cmd" data-cmd="start" data-user-id="<?= $uid ?>">
                                ▶ Start Lab
                            </button>
                            <button type="button" class="btn btn-outline btn-sm btn-docker-cmd" data-cmd="reset" data-user-id="<?= $uid ?>">
                                🔄 Reset
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="docker-action-feedback" class="flash-banner flash-success" style="display: none; margin-top: 1rem;"></div>

<?php elseif ($adminTab === 'users'): ?>
    <!-- 2. GESTION COMPLÈTE DES COMPTES UTILISATEURS -->
    <div class="reader-grid">
        <div>
            <h2 style="font-size: 1.3rem; margin-bottom: 1rem;">👥 Comptes Enregistrés (<?= count($allUsers) ?>)</h2>
            <div class="lms-table-wrap">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom & Email</th>
                            <th>Entreprise</th>
                            <th>Rôle & Statut</th>
                            <th>Actions Administrateur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $u):
                            $uid = (int)$u['id'];
                        ?>
                            <tr>
                                <td class="tabular-nums">#<?= $uid ?></td>
                                <td>
                                    <strong><?= Security::e($u['name']) ?></strong><br/>
                                    <span style="font-size: 0.78rem; color: var(--text-muted);"><?= Security::e($u['email']) ?></span>
                                </td>
                                <td><?= Security::e($u['company'] ?? '') ?></td>
                                <td>
                                    <span class="role-badge role-<?= Security::e($u['role']) ?>"><?= Security::e($u['role']) ?></span>
                                    <?= !empty($u['active']) ? '<span class="badge-completed">Actif</span>' : '<span style="color:#FB7185;">Inactif</span>' ?>
                                </td>
                                <td>
                                    <?php if ($auth->isAdmin($currentUser)): ?>
                                        <details style="display: inline-block;">
                                            <summary class="btn btn-outline btn-sm">✏️ Éditer</summary>
                                            <form method="POST" action="index.php?page=admin&action=admin_update_user" style="margin-top: 0.6rem; padding: 0.85rem; background: var(--bg-base); border: 1px solid var(--border-strong); border-radius: var(--radius-sm); min-width: 260px;">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <div class="form-group">
                                                    <label>Nom</label>
                                                    <input type="text" name="name" class="form-control" autocomplete="name" value="<?= Security::e($u['name']) ?>" required/>
                                                </div>
                                                <div class="form-group">
                                                    <label>Entreprise</label>
                                                    <input type="text" name="company" class="form-control" autocomplete="organization" value="<?= Security::e($u['company'] ?? '') ?>"/>
                                                </div>
                                                <div class="form-group">
                                                    <label>Rôle</label>
                                                    <select name="role" class="form-control">
                                                        <option value="student" <?= $u['role'] === 'student' ? 'selected' : '' ?>>student</option>
                                                        <option value="trainer" <?= $u['role'] === 'trainer' ? 'selected' : '' ?>>trainer</option>
                                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>Nouveau mot de passe (optionnel)</label>
                                                    <input type="password" name="reset_password" class="form-control" autocomplete="new-password" placeholder="Laisser vide pour conserver"/>
                                                </div>
                                                <div class="form-group">
                                                    <label><input type="checkbox" name="active" value="1" <?= !empty($u['active']) ? 'checked' : '' ?>/> Compte actif</label>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Enregistrer</button>
                                            </form>
                                        </details>

                                        <?php if ($uid !== 1): ?>
                                            <form method="POST" action="index.php?page=admin&action=admin_delete_user" style="display: inline;" onsubmit="return confirm('Supprimer ce compte utilisateur ?');">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Formulaire de création rapide d'un compte -->
        <aside class="toc-box">
            <h3 style="font-size: 1.1rem; margin-bottom: 0.75rem;">➕ Créer un Nouveau Compte</h3>
            <form method="POST" action="index.php?page=admin&action=admin_create_user">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label>Nom Complet</label>
                    <input type="text" name="name" class="form-control" autocomplete="name" required placeholder="Ex: Marc Lefèvre"/>
                </div>
                <div class="form-group">
                    <label>Email Professionnel</label>
                    <input type="email" name="email" class="form-control" autocomplete="email" required placeholder="marc.lefevre@client.fr"/>
                </div>
                <div class="form-group">
                    <label>Entreprise / Équipe</label>
                    <input type="text" name="company" class="form-control" autocomplete="organization" placeholder="Ex: Équipe SRE / DBA"/>
                </div>
                <div class="form-group">
                    <label>Rôle</label>
                    <select name="role" class="form-control">
                        <option value="student">Apprenant / Stagiaire (student)</option>
                        <option value="trainer">Formateur (trainer)</option>
                        <option value="admin">Administrateur (admin)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mot de Passe Initial</label>
                    <input type="text" name="password" class="form-control" autocomplete="new-password" required value="LightPath2026!"/>
                </div>
                <button type="submit" class="btn btn-emerald" style="width: 100%;">
                    ➕ Créer le Compte Utilisateur
                </button>
            </form>
        </aside>
    </div>

<?php elseif ($adminTab === 'docker'): ?>
    <!-- 3. SUPERVISION DES CONTENEURS DOCKER & CHAOS ENGINEERING DBA -->
    <div class="docker-cockpit">
        <span class="badge-code">⚡ SIMULATEUR DE PANNES EN DIRECT (CHAOS ENGINEERING DBA)</span>
        <h2 style="font-size: 1.3rem; margin: 0.4rem 0 0.35rem;">Injection & Résolution de Scénarios de Crise PostgreSQL 18</h2>
        <p style="color: var(--text-secondary); font-size: 0.88rem; margin-bottom: 1rem;">
            Injectez en 1 clic un scénario d'incident réel sur les instances Docker actives afin que les stagiaires diagnostiquent et résolvent le problème en direct.
        </p>

        <div id="chaos-feedback-box" class="flash-banner flash-success" style="display: none;"></div>

        <div class="card-grid">
            <?php
            $chaosList = [
                'bloat' => ['title' => '🔥 Table Bloat Massif (chaos_orders)', 'desc' => 'Génère une table avec autovacuum désactivé et des milliers de dead tuples suite à des UPDATEs intensifs.'],
                'lock' => ['title' => '🔒 Contention de Verrous (chaos_ledger)', 'desc' => 'Prépare une table financière critique soumise à des verrous concurrents pour audit pg_blocking_pids().'],
                'idle_in_tx' => ['title' => '⏳ Sessions Idle in Transaction', 'desc' => 'Crée 1 000 sessions applicatives et illustre l\'impact sur la rétention XID et le vacuum.'],
                'wal_pressure' => ['title' => '🌊 Rafale d\'Écritures WAL & Checkpoint', 'desc' => 'Génère un burst d\'écritures dans chaos_wal_burst et force un CHECKPOINT immédiat.'],
            ];
            foreach ($chaosList as $cKey => $cInfo):
            ?>
                <div class="lms-card">
                    <div>
                        <h3 style="font-size: 1rem; margin-bottom: 0.35rem;"><?= Security::e($cInfo['title']) ?></h3>
                        <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 1rem;"><?= Security::e($cInfo['desc']) ?></p>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-amber btn-sm btn-chaos-trigger" data-scenario="<?= Security::e($cKey) ?>" data-action="inject" style="flex:1;">
                            ⚡ Injecter la Panne
                        </button>
                        <button type="button" class="btn btn-emerald btn-sm btn-chaos-trigger" data-scenario="<?= Security::e($cKey) ?>" data-action="heal" style="flex:1;">
                            🧹 Nettoyer (Heal)
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">🐳 Conteneurs Docker Actifs & Cluster de Formation</h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Nom du Conteneur</th>
                    <th>Type</th>
                    <th>Image</th>
                    <th>Statut</th>
                    <th>Ports Exposés</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($labContainers)): ?>
                    <tr><td colspan="5">Aucun conteneur Lab en cours d'exécution. Cliquez sur « ▶ Start Lab » dans l'onglet Cohorte ou sur le Tableau de Bord.</td></tr>
                <?php else: ?>
                    <?php foreach ($labContainers as $c): ?>
                        <tr>
                            <td><code><?= Security::e($c['name']) ?></code></td>
                            <td><span class="badge-code"><?= $c['type'] === 'user_sandbox' ? 'Sandbox Utilisateur' : 'Cluster HA Partagé' ?></span></td>
                            <td><code><?= Security::e($c['image']) ?></code></td>
                            <td><?= $c['running'] ? '<span class="badge-completed">' . Security::e($c['status']) . '</span>' : Security::e($c['status']) ?></td>
                            <td><code><?= Security::e($c['ports']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($adminTab === 'courses'): ?>
    <!-- 4. ARCHITECTURE MULTI-FORMATIONS (RÉUTILISABILITÉ DU SITE) -->
    <div class="reader-grid">
        <div>
            <h2 style="font-size: 1.3rem; margin-bottom: 1rem;">🎓 Formations Connectées au Portail LMS</h2>
            <div class="card-grid">
                <?php foreach ($allCourses as $cSlug => $cObj): ?>
                    <div class="lms-card">
                        <div>
                            <div class="card-top-meta">
                                <span class="badge-code"><?= Security::e($cObj['badge']) ?></span>
                                <span class="badge-completed">Active</span>
                            </div>
                            <h3 style="font-size: 1.1rem; margin-bottom: 0.35rem;"><?= Security::e($cObj['title']) ?></h3>
                            <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                                Dossier source : <code><?= Security::e($cObj['source_root']) ?></code>
                            </p>
                            <div style="font-size: 0.82rem; color: var(--brand-cyan-light);">
                                📚 <?= (int)$cObj['stats']['modules_count'] ?> Modules •
                                🧪 <?= (int)$cObj['stats']['labs_count'] ?> Labs •
                                📑 <?= (int)$cObj['stats']['cheatsheets_count'] ?> Cheatsheets
                            </div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="index.php?page=dashboard&course=<?= urlencode((string)$cSlug) ?>" class="btn btn-primary btn-sm" style="width:100%;">
                                Ouvrir cette Formation →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="toc-box">
            <h3 style="font-size: 1.1rem; margin-bottom: 0.65rem;">➕ Connecter une Nouvelle Formation</h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem;">
                Ce portail LMS est 100% générique. Indiquez le chemin d'un autre dépôt de formation (ex: MySQL, Kubernetes, Linux, Python) respectant la structure <code>03_supports_de_cours/</code> et <code>04_ateliers_et_tp/</code>.
            </p>
            <form method="POST" action="index.php?page=admin&action=admin_add_course">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label>Identifiant (Slug)</label>
                    <input type="text" name="slug" class="form-control" required placeholder="Ex: kubernetes-production"/>
                </div>
                <div class="form-group">
                    <label>Titre de la Formation</label>
                    <input type="text" name="title" class="form-control" required placeholder="Ex: Kubernetes & Cloud Native en Production"/>
                </div>
                <div class="form-group">
                    <label>Catégorie</label>
                    <input type="text" name="category" class="form-control" value="Cloud, DevOps & Bases de Données"/>
                </div>
                <div class="form-group">
                    <label>Durée</label>
                    <input type="text" name="duration" class="form-control" value="35h (5 jours) - Certifié Qualiopi"/>
                </div>
                <div class="form-group">
                    <label>Chemin du Référentiel Source</label>
                    <input type="text" name="source_root" class="form-control" placeholder="Chemin absolu ou relatif vers la formation"/>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Objectifs et public visé..."></textarea>
                </div>
                <button type="submit" class="btn btn-emerald" style="width: 100%;">
                    🚀 Ajouter au Catalogue Multi-Formations
                </button>
            </form>
        </aside>
    </div>

<?php else: ?>
    <!-- 5. JOURNAL D'AUDIT ET TRAÇABILITÉ QUALIOPI -->
    <h2 style="font-size: 1.3rem; margin-bottom: 1rem;">📜 Journal d'Audit & Traçabilité Qualiopi</h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Horodatage</th>
                    <th>Utilisateur</th>
                    <th>Type d'Événement</th>
                    <th>Détails de l'Opération</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLogs as $log): ?>
                    <tr>
                        <td class="tabular-nums"><code><?= Security::e($log['timestamp'] ?? '') ?></code></td>
                        <td><strong><?= Security::e($log['actor'] ?? '') ?></strong></td>
                        <td><span class="badge-code"><?= Security::e($log['action'] ?? '') ?></span></td>
                        <td><?= Security::e($log['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
