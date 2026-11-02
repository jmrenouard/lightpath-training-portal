<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$adminTab = (string)($_GET['tab'] ?? 'cohort');
$allUsers = $storage->getUsers();
$allProgress = $storage->getAllProgress();
$labContainers = $docker->listAllLabContainers();
$auditLogs = $storage->getAuditLogs(40);
$classroomState = $storage->getClassroomState($currentCourseSlug);
$allCourseDiscussions = $storage->getAllCourseDiscussions($currentCourseSlug);
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div>
        <span class="badge-code">PORTAIL D'ADMINISTRATION & COCKPIT FORMATEUR</span>
        <h1 style="font-size: 1.65rem; margin: 0.4rem 0 0.25rem;">🛡️ Administration des Comptes, Cohortes, Docker & Formations</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Supervisez la progression des apprenants en temps réel, pilotez la salle de classe live, modérez le forum Q&A, importez vos cohortes CSV et exportez vos packages SCORM 1.2.
        </p>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 0.45rem;">
        <a href="index.php?page=admin&tab=cohort&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'cohort' ? 'btn-primary' : 'btn-outline' ?>">📊 Suivi Cohorte & Qualiopi</a>
        <a href="index.php?page=admin&tab=users&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'users' ? 'btn-primary' : 'btn-outline' ?>">👥 Gestion des Comptes (<?= count($allUsers) ?>)</a>
        <a href="index.php?page=admin&tab=qna&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'qna' ? 'btn-primary' : 'btn-outline' ?>">💬 Questions Q&A (<?= count($allCourseDiscussions) ?>)</a>
        <a href="index.php?page=admin&tab=docker&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'docker' ? 'btn-primary' : 'btn-outline' ?>">🐳 Conteneurs Docker & Chaos DBA</a>
        <a href="index.php?page=admin&tab=courses&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'courses' ? 'btn-primary' : 'btn-outline' ?>">🎓 Multi-Formations (<?= count($allCourses) ?>)</a>
        <a href="index.php?page=admin&tab=audit&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'audit' ? 'btn-primary' : 'btn-outline' ?>">📜 Journal d'Audit</a>
        <a href="index.php?page=admin&tab=system&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $adminTab === 'system' ? 'btn-primary' : 'btn-outline' ?>">⚙️ Caches & Système</a>
    </div>
</div>

<?php if ($adminTab === 'cohort'): ?>
    <!-- PANNEAU DE PILOTAGE SALLE DE CLASSE EN DIRECT & VERROUILLAGE PÉDAGOGIQUE (Feature D1) -->
    <section class="lms-card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; border-color: rgba(56, 189, 248, 0.35);">
        <span class="badge-code">📢 PILOTAGE SALLE DE CLASSE LIVE & VERROUILLAGE PÉDAGOGIQUE</span>
        <h3 style="font-size: 1.15rem; margin: 0.35rem 0 0.75rem;">Annonce Temps Réel aux Apprenants & Contrôle d'Accès (Corrigés / Examen)</h3>
        <form method="POST" action="index.php?page=admin&tab=cohort&action=save_classroom_state&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; align-items: end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Message diffusé en haut de toutes les pages des stagiaires (laisser vide pour masquer)</label>
                    <input type="text"
                           name="broadcast_message"
                           class="form-control"
                           value="<?= Security::e((string)($classroomState['broadcast_message'] ?? '')) ?>"
                           placeholder="Ex: Pause déjeuner jusqu'à 14h00 — Reprise avec l'Atelier 06 (Indexation B-Tree/GIN)"/>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.45rem;">
                    <label style="font-size: 0.82rem; display: flex; align-items: center; gap: 0.45rem; cursor: pointer;">
                        <input type="checkbox" name="lock_solutions" value="1" <?= !empty($classroomState['lock_solutions']) ? 'checked' : '' ?>/>
                        <span>🔒 Verrouiller les Corrigés des Labs (Phase Recherche)</span>
                    </label>
                    <label style="font-size: 0.82rem; display: flex; align-items: center; gap: 0.45rem; cursor: pointer;">
                        <input type="checkbox" name="lock_exam" value="1" <?= !empty($classroomState['lock_exam']) ? 'checked' : '' ?>/>
                        <span>🔒 Verrouiller l'Examen Final Certifiant (40 QCM)</span>
                    </label>
                </div>
            </div>
            <div style="margin-top: 0.85rem; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary btn-sm">
                    📡 Appliquer en Direct à toute la Salle
                </button>
            </div>
        </form>
    </section>

    <!-- 1. SUIVI DE COHORTE & PROGRESSION QUALIOPI -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <h2 style="font-size: 1.3rem; margin: 0;">📊 Matrice de Suivi Pédagogique de la Cohorte (<?= Security::e($currentCourse['short_title']) ?>)</h2>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="index.php?action=export_qualiopi_csv&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-emerald btn-sm">
                📊 Exporter Audit Qualiopi (CSV)
            </a>
            <a href="index.php?action=export_scorm_manifest&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
                📄 Manifeste SCORM 1.2 (.xml)
            </a>
            <a href="index.php?action=export_scorm_zip&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-primary btn-sm">
                📦 Exporter Package SCORM 1.2 Complet (.zip)
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

            <!-- Import CSV de Cohorte en Masse (Feature D3) -->
            <hr style="border: none; border-top: 1px solid var(--border-hairline); margin: 1.25rem 0;"/>
            <h3 style="font-size: 1.05rem; margin-bottom: 0.45rem;">📥 Import CSV de Cohorte en Masse</h3>
            <p style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 0.65rem;">
                Collez une liste CSV (<code>nom;email;entreprise;role;mot_de_passe</code>) pour inscrire toute une session en 1 clic.
            </p>
            <form method="POST" action="index.php?page=admin&tab=users&action=admin_import_users_csv&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <textarea name="csv_content" class="form-control" rows="4" required placeholder="Claire Martin;claire@banque.fr;Banque SA;student;LightPath2026!&#10;David Roy;david@banque.fr;Banque SA;student;LightPath2026!"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">
                    📥 Importer la Cohorte CSV
                </button>
            </form>
        </aside>
    </div>

<?php elseif ($adminTab === 'qna'): ?>
    <!-- 2b. MODÉRATION CENTRALISÉE DU FORUM D'ENTRAIDE Q&A (Feature D2) -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <div>
            <h2 style="font-size: 1.3rem; margin: 0;">💬 Modération du Forum d'Entraide Technique & Q&A (<span class="tabular-nums"><?= count($allCourseDiscussions) ?></span> messages)</h2>
            <p style="font-size: 0.84rem; color: var(--text-secondary); margin: 0.25rem 0 0;">
                Visualisez toutes les questions posées sur les modules et ateliers, marquez-les comme résolues ou supprimez les entrées obsolètes.
            </p>
        </div>
    </div>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Élément (Module / Lab)</th>
                    <th>Auteur</th>
                    <th>Question / Message</th>
                    <th>Statut</th>
                    <th>Actions Formateur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allCourseDiscussions)): ?>
                    <tr><td colspan="6">Aucune question publiée pour le moment sur cette formation.</td></tr>
                <?php else: ?>
                    <?php foreach ($allCourseDiscussions as $disc):
                        $dItemId = (string)($disc['item_id'] ?? '');
                        $dMsgId = (string)($disc['id'] ?? '');
                        $dIsLab = str_starts_with($dItemId, 'lab_');
                        $dTargetPage = $dIsLab ? 'lab' : 'module';
                        $dResolved = !empty($disc['resolved']);
                    ?>
                        <tr>
                            <td class="tabular-nums"><code><?= Security::e(substr((string)($disc['created_at'] ?? ''), 0, 16)) ?></code></td>
                            <td>
                                <a href="index.php?page=<?= $dTargetPage ?>&id=<?= urlencode($dItemId) ?>&course=<?= urlencode($currentCourseSlug) ?>#discussion-section" class="badge-code">
                                    <?= $dIsLab ? '🧪 ' : '📖 ' ?><?= Security::e($dItemId) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= Security::e((string)($disc['user_name'] ?? 'Apprenant')) ?></strong>
                                <span class="role-badge role-<?= Security::e((string)($disc['user_role'] ?? 'student')) ?>"><?= Security::e((string)($disc['user_role'] ?? 'student')) ?></span>
                            </td>
                            <td style="max-width: 440px; white-space: pre-line; font-size: 0.85rem;"><?= Security::e((string)($disc['message'] ?? '')) ?></td>
                            <td>
                                <?= $dResolved
                                    ? '<span class="badge-completed">✔ Résolu</span>'
                                    : '<span class="badge-phase">⏳ Ouvert</span>' ?>
                            </td>
                            <td>
                                <form method="POST" action="index.php?page=admin&tab=qna&action=moderate_discussion&course=<?= urlencode($currentCourseSlug) ?>" style="display: inline-flex; gap: 0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                    <input type="hidden" name="item_id" value="<?= Security::e($dItemId) ?>"/>
                                    <input type="hidden" name="message_id" value="<?= Security::e($dMsgId) ?>"/>
                                    <input type="hidden" name="return_page" value="admin"/>
                                    <button type="submit" name="mod_action" value="resolve" class="btn btn-emerald btn-sm">
                                        <?= $dResolved ? '↩ Rouvrir' : '✔ Résolu' ?>
                                    </button>
                                    <button type="submit" name="mod_action" value="delete" class="btn btn-danger btn-sm">
                                        🗑️
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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

<?php if ($adminTab === 'system'): ?>
    <section class="lms-card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
        <span class="badge-code">⚙️ GESTION DU SYSTÈME & DES CACHES DE RENDU</span>
        <h3 style="font-size: 1.25rem; margin: 0.5rem 0 1rem;">Invalidation & Purge Immédiate des Caches</h3>
        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
            Le portail met en cache le catalogue multi-formations et le rendu HTML des modules/ateliers Markdown pour des performances optimales.
            En cas de mise à jour manuelle des supports de cours ou des scripts SQL, utilisez cette action pour forcer la recompilation immédiate.
        </p>
        <form method="POST" action="index.php?page=admin&tab=system&action=admin_clear_cache&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Purger tous les caches de catalogue et de rendu Markdown ?');">
                🧹 Vider les Caches Catalogue & Markdown
            </button>
        </form>
    </section>
<?php endif; ?>

