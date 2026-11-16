<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin Docker fleet partial: fleet KPI summary, idle reaper, chaos engineering simulator
 * with 1-click chaos heal, live container table, and container log viewer.
 */
$chaosEnabled = method_exists($featureManager, 'isEnabledForCourse')
    ? $featureManager->isEnabledForCourse('chaos_engineering', $currentCourseSlug)
    : $featureManager->isEnabled('chaos_engineering');
?>
<section class="admin-kpi-strip" aria-label="Synthèse de la flotte Docker PostgreSQL 18">
    <div class="kpi-card">
        <div class="kpi-label">Runtime Docker</div>
        <div class="kpi-value tabular-nums"><?= !empty($fleetSummary['docker_available']) ? 'Actif' : 'Simulé' ?></div>
        <div class="kpi-sub"><?= Security::e((string)($fleetSummary['runtime_mode'] ?? 'cloud_simulated')) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Sandboxes Actives</div>
        <div class="kpi-value tabular-nums text-cyan">
            <?= (int)($fleetSummary['running_user_containers'] ?? 0) ?> / <?= (int)($fleetSummary['max_containers'] ?? 40) ?>
        </div>
        <div class="kpi-sub tabular-nums"><?= (int)($fleetSummary['stopped_user_containers'] ?? 0) ?> arrêtée(s)</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Cluster Primary HA</div>
        <div class="kpi-value tabular-nums text-emerald"><?= !empty($fleetSummary['primary_cluster_running']) ? 'Running' : 'Standby' ?></div>
        <div class="kpi-sub"><?= Security::e((string)($fleetSummary['primary_cluster_status'] ?? 'simulated')) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Conteneurs Inactifs</div>
        <div class="kpi-value tabular-nums text-amber"><?= (int)($fleetSummary['idle_count'] ?? 0) ?></div>
        <div class="kpi-sub tabular-nums">Max inactivité : <?= (int)round(((int)($fleetSummary['oldest_idle_seconds'] ?? 0)) / 60) ?> min</div>
    </div>
</section>

<?php if ($chaosEnabled): ?>
    <div class="docker-cockpit">
        <div class="admin-toolbar mb-xs">
            <div>
                <span class="badge-code">⚡ SIMULATEUR DE PANNES EN DIRECT (CHAOS ENGINEERING DBA)</span>
                <h2 class="section-heading-md mt-xs mb-xs">Injection &amp; Résolution de Scénarios de Crise PostgreSQL 18</h2>
            </div>
            <form method="POST" action="index.php?page=admin&tab=docker&action=admin_heal_chaos&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <button type="submit" class="btn btn-emerald btn-sm">
                    🩺 Assainir Tous les Verrous Chaos (1-Clic)
                </button>
            </form>
        </div>
        <p class="text-sm text-secondary mb-1">
            Injectez en 1 clic un scénario d'incident réel sur les instances Docker actives afin que les stagiaires diagnostiquent et résolvent le problème en direct.
        </p>

        <div id="chaos-feedback-box" class="flash-banner flash-success is-hidden"></div>

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
                        <h3 class="section-heading-sm mt-0 mb-xs"><?= Security::e($cInfo['title']) ?></h3>
                        <p class="text-xs text-secondary mb-1"><?= Security::e($cInfo['desc']) ?></p>
                    </div>
                    <div class="d-flex gap-sm">
                        <button type="button" class="btn btn-amber btn-sm btn-chaos-trigger flex-1" data-scenario="<?= Security::e($cKey) ?>" data-action="inject">
                            ⚡ Injecter la Panne
                        </button>
                        <button type="button" class="btn btn-emerald btn-sm btn-chaos-trigger flex-1" data-scenario="<?= Security::e($cKey) ?>" data-action="heal">
                            🧹 Nettoyer (Heal)
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="admin-toolbar mt-1 mb-1">
    <h2 class="section-heading-md mb-0">🐳 Conteneurs Docker Actifs &amp; Cluster de Formation</h2>
    <div class="d-flex flex-wrap gap-sm">
        <form method="POST" action="index.php?page=admin&tab=docker&action=admin_reap_idle_docker&course=<?= urlencode($currentCourseSlug) ?>" class="d-inline-flex gap-xs">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <input type="hidden" name="max_idle_seconds" value="3600"/>
            <button type="submit" class="btn btn-outline btn-sm">
                ⏱️ Nettoyer Conteneurs Inactifs (&gt; 60 min)
            </button>
        </form>
        <form method="POST" action="index.php?page=admin&tab=docker&action=admin_bulk_docker&course=<?= urlencode($currentCourseSlug) ?>" class="d-flex flex-wrap gap-sm">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <button type="submit" name="bulk_cmd" value="refresh_runtime" class="btn btn-outline-cyan btn-sm">
                🔄 Rafraîchir Runtime Docker
            </button>
            <button type="submit" name="bulk_cmd" value="stop_all" class="btn btn-amber btn-sm" data-confirm="Arrêter tous les conteneurs sandbox apprenants en cours d'exécution ?">
                ⏹️ Arrêter Tous les Labs
            </button>
            <button type="submit" name="bulk_cmd" value="prune_stopped" class="btn btn-danger btn-sm" data-confirm="Supprimer définitivement tous les conteneurs apprenants arrêtés ?">
                🧹 Purger Conteneurs Arrêtés
            </button>
        </form>
    </div>
</div>

<div class="lms-table-wrap">
    <table class="lms-table">
        <thead>
            <tr>
                <th>Nom du Conteneur</th>
                <th>Type</th>
                <th>Image</th>
                <th>Statut</th>
                <th>Ports Exposés</th>
                <th>Inspection</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($labContainers)): ?>
                <tr><td colspan="6">Aucun conteneur Lab en cours d'exécution. Cliquez sur « ▶ Start Lab » dans l'onglet Cohorte ou sur le Tableau de Bord.</td></tr>
            <?php else: ?>
                <?php foreach ($labContainers as $c):
                    $sandboxUid = 0;
                    if (preg_match('/^lp_pg18_user_(\d+)$/', (string)$c['name'], $uidMatch)) {
                        $sandboxUid = (int)$uidMatch[1];
                    }
                ?>
                    <tr>
                        <td><code><?= Security::e($c['name']) ?></code></td>
                        <td class="table-col-nowrap"><span class="badge-code"><?= $c['type'] === 'user_sandbox' ? 'Sandbox Utilisateur' : 'Cluster HA Partagé' ?></span></td>
                        <td><code><?= Security::e($c['image']) ?></code></td>
                        <td class="table-col-nowrap"><?= !empty($c['running']) ? '<span class="badge-completed">' . Security::e((string)$c['status']) . '</span>' : Security::e((string)$c['status']) ?></td>
                        <td><code><?= Security::e((string)$c['ports']) ?></code></td>
                        <td class="table-col-nowrap">
                            <?php if ($sandboxUid > 0): ?>
                                <button type="button"
                                        class="btn btn-outline btn-sm btn-docker-logs"
                                        data-user-id="<?= $sandboxUid ?>"
                                        data-container-name="<?= Security::e((string)$c['name']) ?>">
                                    📜 Logs
                                </button>
                            <?php else: ?>
                                <span class="text-xs text-muted">Cluster partagé</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="docker-logs-panel" class="lms-card mt-1" hidden>
    <div class="admin-toolbar mb-sm">
        <strong id="docker-logs-title">📜 Logs Conteneur</strong>
        <button type="button" id="btn-close-docker-logs" class="btn btn-outline btn-sm">Fermer</button>
    </div>
    <pre id="docker-logs-output" class="console-pre"></pre>
</div>
