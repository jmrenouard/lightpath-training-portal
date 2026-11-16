<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin users & enrollments partial: bulk actions toolbar, searchable users table with
 * status toggles, admin password reset popover, account creation, CSV import, and login throttle unlock.
 */
$selfUserId = (int)($currentUser['id'] ?? 0);
?>
<div class="reader-grid">
    <div>
        <div class="admin-toolbar mb-1">
            <h2 class="section-heading-md mb-0">👥 Comptes Enregistrés (<span class="tabular-nums"><?= count($filteredUsers) ?></span> / <span class="tabular-nums"><?= count($allUsers) ?></span>)</h2>
            <form method="GET" action="index.php" class="d-flex flex-wrap gap-sm">
                <input type="hidden" name="page" value="admin"/>
                <input type="hidden" name="tab" value="users"/>
                <input type="hidden" name="course" value="<?= urlencode($currentCourseSlug) ?>"/>
                <input id="admin-user-search"
                       type="search"
                       name="uq"
                       class="form-control form-control-sm"
                       value="<?= Security::e($userSearchQuery) ?>"
                       placeholder="🔎 Nom, email, entreprise..."
                       aria-label="Rechercher un utilisateur"
                       data-filter-table="users-management-table"/>
                <select id="admin-user-role-filter"
                        name="ur"
                        class="form-control form-control-sm"
                        aria-label="Filtrer par rôle"
                        data-filter-role-table="users-management-table">
                    <option value="">Tous les rôles</option>
                    <option value="student" <?= $userRoleFilter === 'student' ? 'selected' : '' ?>>student</option>
                    <option value="trainer" <?= $userRoleFilter === 'trainer' ? 'selected' : '' ?>>trainer</option>
                    <option value="admin" <?= $userRoleFilter === 'admin' ? 'selected' : '' ?>>admin</option>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Filtrer</button>
            </form>
        </div>

        <form id="bulk-users-form"
              method="POST"
              action="index.php?page=admin&tab=users&action=admin_bulk_users&course=<?= urlencode($currentCourseSlug) ?>"
              class="preset-toolbar">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <span class="text-xs text-muted font-bold">Actions Groupées :</span>
            <select name="bulk_course_slug" class="form-control form-control-sm admin-role-select" aria-label="Formation cible pour l'inscription groupée">
                <?php foreach ($allManageableCourses as $bKey => $bCfg): ?>
                    <option value="<?= Security::e((string)$bKey) ?>" <?= (string)$bKey === $currentCourseSlug ? 'selected' : '' ?>>
                        <?= Security::e((string)($bCfg['short_title'] ?? $bCfg['title'] ?? $bKey)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="bulk_op" value="enroll" class="btn btn-outline-cyan btn-sm">🎓 Inscrire</button>
            <button type="submit" name="bulk_op" value="activate" class="btn btn-emerald btn-sm">✅ Activer</button>
            <button type="submit" name="bulk_op" value="suspend" class="btn btn-amber btn-sm" data-confirm="Suspendre tous les comptes sélectionnés ?">⏸️ Suspendre</button>
        </form>

        <div class="lms-table-wrap">
            <table id="users-management-table" class="lms-table">
                <thead>
                    <tr>
                        <th class="table-col-nowrap">
                            <input type="checkbox"
                                   aria-label="Tout sélectionner"
                                   data-bulk-select-all="selected_user_ids[]"/>
                        </th>
                        <th>ID</th>
                        <th>Nom &amp; Email</th>
                        <th>Entreprise</th>
                        <th>Rôle &amp; Statut</th>
                        <th>Inscriptions</th>
                        <th>Actions Administrateur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredUsers as $u):
                        $uid = (int)$u['id'];
                        $isRowActive = !empty($u['active']);
                        $userEnrolled = array_map('strval', (array)($u['enrolled_courses'] ?? []));
                        $searchHaystack = strtolower(trim(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['company'] ?? '')));
                    ?>
                        <tr data-search="<?= Security::e($searchHaystack) ?>" data-role="<?= Security::e((string)($u['role'] ?? 'student')) ?>">
                            <td class="table-col-nowrap">
                                <input type="checkbox"
                                       form="bulk-users-form"
                                       name="selected_user_ids[]"
                                       value="<?= $uid ?>"
                                       aria-label="Sélectionner <?= Security::e($u['name']) ?>"/>
                            </td>
                            <td class="tabular-nums">#<?= $uid ?></td>
                            <td>
                                <strong><?= Security::e($u['name']) ?></strong><br/>
                                <span class="text-xs text-muted"><?= Security::e($u['email']) ?></span>
                            </td>
                            <td><?= Security::e($u['company'] ?? '') ?></td>
                            <td>
                                <span class="role-badge role-<?= Security::e($u['role']) ?>"><?= Security::e($u['role']) ?></span>
                                <?= $isRowActive ? '<span class="badge-completed">Actif</span>' : '<span class="badge-danger-text">Suspendu</span>' ?>
                            </td>
                            <td>
                                <?php if (($u['role'] ?? 'student') === 'student'): ?>
                                    <?php if (!array_key_exists('enrolled_courses', $u) || $u['enrolled_courses'] === null): ?>
                                        <span class="text-xs text-secondary">postgresql-18 (défaut)</span>
                                    <?php elseif ($userEnrolled === []): ?>
                                        <span class="badge-danger-text">Aucun cours assigné</span>
                                    <?php else: ?>
                                        <span class="text-xs text-secondary"><?= Security::e(implode(', ', $userEnrolled)) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-xs text-muted">Toutes (Staff)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-xs">
                                    <?php if ($uid > 1 && $uid !== $selfUserId): ?>
                                        <form method="POST"
                                              action="index.php?page=admin&tab=users&action=admin_toggle_user_active&course=<?= urlencode($currentCourseSlug) ?>"
                                              class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                            <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                            <input type="hidden" name="active" value="<?= $isRowActive ? '0' : '1' ?>"/>
                                            <button type="submit" class="btn btn-sm <?= $isRowActive ? 'btn-amber' : 'btn-emerald' ?>">
                                                <?= $isRowActive ? '⏸️ Suspendre' : '▶ Activer' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <details class="d-inline-block">
                                        <summary class="btn btn-outline btn-sm">🔑 MDP</summary>
                                        <div class="admin-inline-popover">
                                            <form method="POST" action="index.php?page=admin&tab=users&action=admin_reset_password&course=<?= urlencode($currentCourseSlug) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <div class="form-group">
                                                    <label for="quick-reset-pw-<?= $uid ?>">Nouveau mot de passe</label>
                                                    <input id="quick-reset-pw-<?= $uid ?>"
                                                           type="password"
                                                           name="new_password"
                                                           class="form-control"
                                                           autocomplete="new-password"
                                                           minlength="6"
                                                           required
                                                           placeholder="6 caractères minimum"/>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm w-100">Réinitialiser</button>
                                            </form>
                                        </div>
                                    </details>

                                    <details class="d-inline-block">
                                        <summary class="btn btn-outline btn-sm">✏️ Éditer</summary>
                                        <div class="admin-inline-popover">
                                            <form method="POST" action="index.php?page=admin&tab=users&action=admin_update_user&course=<?= urlencode($currentCourseSlug) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <div class="form-group">
                                                    <label for="edit-name-<?= $uid ?>">Nom</label>
                                                    <input id="edit-name-<?= $uid ?>" type="text" name="name" class="form-control" autocomplete="name" value="<?= Security::e($u['name']) ?>" required/>
                                                </div>
                                                <div class="form-group">
                                                    <label for="edit-company-<?= $uid ?>">Entreprise</label>
                                                    <input id="edit-company-<?= $uid ?>" type="text" name="company" class="form-control" autocomplete="organization" value="<?= Security::e($u['company'] ?? '') ?>"/>
                                                </div>
                                                <div class="form-group">
                                                    <label for="edit-role-<?= $uid ?>">Rôle</label>
                                                    <select id="edit-role-<?= $uid ?>" name="role" class="form-control">
                                                        <option value="student" <?= $u['role'] === 'student' ? 'selected' : '' ?>>student</option>
                                                        <option value="trainer" <?= $u['role'] === 'trainer' ? 'selected' : '' ?>>trainer</option>
                                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="edit-reset-pw-<?= $uid ?>">Nouveau mot de passe (optionnel)</label>
                                                    <input id="edit-reset-pw-<?= $uid ?>" type="password" name="reset_password" class="form-control" autocomplete="new-password" placeholder="Laisser vide pour conserver"/>
                                                </div>
                                                <div class="form-group">
                                                    <label class="checkbox-label" for="edit-active-<?= $uid ?>">
                                                        <input id="edit-active-<?= $uid ?>" type="checkbox" name="active" value="1" <?= $isRowActive ? 'checked' : '' ?>/>
                                                        <span>Compte actif</span>
                                                    </label>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm w-100">Enregistrer</button>
                                            </form>

                                            <hr class="hairline-divider"/>

                                            <form method="POST" action="index.php?page=admin&tab=users&action=admin_update_enrollments&course=<?= urlencode($currentCourseSlug) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <div class="text-xs text-secondary mb-xs"><strong>Formations inscrites :</strong></div>
                                                <div class="d-flex flex-col gap-xs mb-sm">
                                                    <?php foreach ($allManageableCourses as $cKey => $cCfg):
                                                        $cKeyStr = (string)$cKey;
                                                        $isChecked = in_array($cKeyStr, $userEnrolled, true);
                                                    ?>
                                                        <label class="checkbox-label text-xs" for="enroll-<?= $uid ?>-<?= Security::e($cKeyStr) ?>">
                                                            <input id="enroll-<?= $uid ?>-<?= Security::e($cKeyStr) ?>" type="checkbox" name="enrolled_courses[]" value="<?= Security::e($cKeyStr) ?>" <?= $isChecked ? 'checked' : '' ?>/>
                                                            <span><?= Security::e((string)($cCfg['short_title'] ?? $cCfg['title'] ?? $cKeyStr)) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button type="submit" class="btn btn-outline-cyan btn-sm w-100">🎓 Mettre à jour inscriptions</button>
                                            </form>

                                            <form method="POST"
                                                  action="index.php?page=admin&tab=users&action=admin_reset_user_progress&course=<?= urlencode($currentCourseSlug) ?>"
                                                  class="mt-sm"
                                                  data-confirm="Réinitialiser toute la progression (modules, labs, quiz) de <?= Security::e($u['name']) ?> sur <?= Security::e($currentCourseSlug) ?> ?">
                                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                                <input type="hidden" name="target_course" value="<?= Security::e($currentCourseSlug) ?>"/>
                                                <button type="submit" class="btn btn-amber btn-sm w-100">🔄 Réinitialiser progression</button>
                                            </form>
                                        </div>
                                    </details>

                                    <?php if ($uid !== 1): ?>
                                        <form method="POST"
                                              action="index.php?page=admin&action=admin_delete_user&course=<?= urlencode($currentCourseSlug) ?>"
                                              class="d-inline"
                                              data-confirm="Supprimer définitivement le compte de <?= Security::e($u['name']) ?> ?">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                            <input type="hidden" name="user_id" value="<?= $uid ?>"/>
                                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <section class="lms-card mt-md">
            <div class="admin-toolbar mb-sm">
                <div>
                    <span class="badge-code">🛡️ PROTECTION ANTI-BRUTE-FORCE</span>
                    <h3 class="section-heading-md mt-xs mb-0">
                        Inspecteur de Verrouillages &amp; Déblocage Rapide (<span class="tabular-nums"><?= count($loginThrottles) ?></span>)
                    </h3>
                </div>
            </div>
            <?php if (empty($loginThrottles)): ?>
                <p class="text-xs text-muted mb-0">Aucun compteur d'échec de connexion actif ou compte verrouillé actuellement.</p>
            <?php else: ?>
                <div class="lms-table-wrap">
                    <table class="lms-table">
                        <thead>
                            <tr>
                                <th>Cible</th>
                                <th>IP</th>
                                <th class="table-col-nowrap">Tentatives</th>
                                <th class="table-col-nowrap">Statut</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loginThrottles as $thr):
                                $tLocked = !empty($thr['locked']);
                            ?>
                                <tr>
                                    <td><code><?= Security::e((string)($thr['email'] !== '' ? $thr['email'] : ($thr['key'] ?? ''))) ?></code></td>
                                    <td><code><?= Security::e((string)($thr['ip'] ?? '—')) ?></code></td>
                                    <td class="tabular-nums table-col-nowrap"><?= (int)($thr['count'] ?? 0) ?></td>
                                    <td class="table-col-nowrap">
                                        <?= $tLocked
                                            ? '<span class="badge-danger-text">Verrouillé (' . (int)($thr['remaining_seconds'] ?? 0) . 's)</span>'
                                            : '<span class="badge-phase">Surveillance</span>' ?>
                                    </td>
                                    <td class="table-col-nowrap">
                                        <form method="POST" action="index.php?page=admin&tab=users&action=admin_unlock_throttle&course=<?= urlencode($currentCourseSlug) ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                                            <input type="hidden" name="throttle_key" value="<?= Security::e((string)($thr['key'] ?? '')) ?>"/>
                                            <input type="hidden" name="email" value="<?= Security::e((string)($thr['email'] ?? '')) ?>"/>
                                            <input type="hidden" name="ip" value="<?= Security::e((string)($thr['ip'] ?? '')) ?>"/>
                                            <button type="submit" class="btn btn-emerald btn-sm">🔓 Déverrouiller</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="toc-box">
        <h3 class="section-heading-sm mt-0">➕ Créer un Nouveau Compte</h3>
        <form method="POST" action="index.php?page=admin&action=admin_create_user&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div class="form-group">
                <label for="create-user-name">Nom Complet</label>
                <input id="create-user-name" type="text" name="name" class="form-control" autocomplete="name" required placeholder="Ex: Marc Lefèvre"/>
            </div>
            <div class="form-group">
                <label for="create-user-email">Email Professionnel</label>
                <input id="create-user-email" type="email" name="email" class="form-control" autocomplete="email" required placeholder="marc.lefevre@client.fr"/>
            </div>
            <div class="form-group">
                <label for="create-user-company">Entreprise / Équipe</label>
                <input id="create-user-company" type="text" name="company" class="form-control" autocomplete="organization" placeholder="Ex: Équipe SRE / DBA"/>
            </div>
            <div class="form-group">
                <label for="create-user-role">Rôle</label>
                <select id="create-user-role" name="role" class="form-control">
                    <option value="student">Apprenant / Stagiaire (student)</option>
                    <option value="trainer">Formateur (trainer)</option>
                    <option value="admin">Administrateur (admin)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="create-user-password">Mot de Passe Initial</label>
                <input id="create-user-password" type="password" name="password" class="form-control" autocomplete="new-password" required minlength="6" placeholder="Minimum 6 caractères"/>
            </div>
            <button type="submit" class="btn btn-emerald w-100">
                ➕ Créer le Compte Utilisateur
            </button>
        </form>

        <hr class="hairline-divider"/>
        <h3 class="section-heading-sm mt-0">📥 Import CSV de Cohorte en Masse</h3>
        <p class="text-xs text-secondary mb-sm">
            Collez une liste CSV (<code>nom;email;entreprise;role;mot_de_passe</code>) pour inscrire toute une session en 1 clic.
        </p>
        <form method="POST" action="index.php?page=admin&tab=users&action=admin_import_users_csv&course=<?= urlencode($currentCourseSlug) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <div class="form-group">
                <label for="csv-default-password">Mot de passe par défaut (si absent du CSV)</label>
                <input id="csv-default-password" type="password" name="default_password" class="form-control" autocomplete="new-password" minlength="6" placeholder="Laisser vide pour génération aléatoire"/>
            </div>
            <div class="form-group">
                <label for="csv-cohort-textarea">Données CSV</label>
                <textarea id="csv-cohort-textarea" name="csv_content" class="form-control" rows="4" required placeholder="Claire Martin;claire@banque.fr;Banque SA;student&#10;David Roy;david@banque.fr;Banque SA;student"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm w-100">
                📥 Importer la Cohorte CSV
            </button>
        </form>
    </aside>
</div>
