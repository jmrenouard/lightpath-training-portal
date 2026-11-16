<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

/**
 * Admin system configuration partial: cache invalidation, deployment feature profiles,
 * categorized 10 feature flags, per-course overrides, JSON config export/import, and system health grid.
 */
$groupedDefs = [];
foreach ($featureDefs as $fKey => $fMeta) {
    $cat = (string)($fMeta['category'] ?? 'Général');
    $groupedDefs[$cat][$fKey] = $fMeta;
}
$selectedOverrideCourse = (string)($_GET['override_course'] ?? $currentCourseSlug);
if (!isset($allManageableCourses[$selectedOverrideCourse])) {
    $selectedOverrideCourse = $currentCourseSlug;
}
$courseOverrides = method_exists($featureManager, 'getCourseOverrides')
    ? $featureManager->getCourseOverrides($selectedOverrideCourse)
    : [];
?>
<section class="lms-card mb-2">
    <span class="badge-code">⚙️ GESTION DU SYSTÈME & DES CACHES DE RENDU</span>
    <h3 class="section-heading-md mt-xs mb-sm">Invalidation &amp; Purge Immédiate des Caches</h3>
    <p class="text-sm text-secondary mb-1">
        Le portail met en cache le catalogue multi-formations et le rendu HTML des modules/ateliers Markdown pour des performances optimales.
        En cas de mise à jour manuelle des supports de cours ou des scripts SQL, utilisez cette action pour forcer la recompilation immédiate.
    </p>
    <div class="d-flex flex-wrap gap-sm items-center">
        <form method="POST"
              action="index.php?page=admin&tab=system&action=admin_clear_cache&course=<?= urlencode($currentCourseSlug) ?>"
              data-confirm="Purger tous les caches de catalogue et de rendu Markdown ?">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
            <button type="submit" class="btn btn-primary">
                🧹 Vider les Caches Catalogue &amp; Markdown
            </button>
        </form>
        <a href="index.php?action=export_features_json&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline-cyan btn-sm">
            📥 Exporter Configuration JSON
        </a>
    </div>
</section>

<?php if (!empty($featureProfiles)): ?>
    <section class="lms-card mb-2">
        <div class="admin-toolbar mb-sm">
            <div>
                <span class="badge-code">🚀 PROFILS DE DÉPLOIEMENT EN 1 CLIC</span>
                <h3 class="section-heading-md mt-xs mb-0">Application Rapide d'une Politique de Plateforme</h3>
            </div>
        </div>
        <div class="preset-toolbar mb-sm">
            <?php foreach ($featureProfiles as $pKey => $pMeta): ?>
                <form method="POST"
                      action="index.php?page=admin&tab=system&action=admin_apply_feature_profile&course=<?= urlencode($currentCourseSlug) ?>"
                      class="d-inline"
                      data-confirm="Appliquer le profil « <?= Security::e((string)($pMeta['label'] ?? $pKey)) ?> » ?">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                    <input type="hidden" name="profile_key" value="<?= Security::e((string)$pKey) ?>"/>
                    <button type="submit"
                            class="btn btn-outline btn-sm"
                            title="<?= Security::e((string)($pMeta['description'] ?? '')) ?>">
                        <?= Security::e((string)($pMeta['label'] ?? $pKey)) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <details>
            <summary class="btn btn-outline btn-sm">📤 Importer une Configuration JSON</summary>
            <form method="POST" action="index.php?page=admin&tab=system&action=admin_import_features_json&course=<?= urlencode($currentCourseSlug) ?>" class="mt-sm">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <div class="form-group">
                    <label for="features-json-import">Payload JSON (schéma <code>lightpath-lms-features-v1</code>)</label>
                    <textarea id="features-json-import" name="features_json" class="form-control" rows="4" required placeholder='{"flags":{"docker_labs":true,"chaos_engineering":false}}'></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Importer &amp; Appliquer</button>
            </form>
        </details>
    </section>
<?php endif; ?>

<div class="admin-split-grid">
    <div class="d-flex flex-col gap-md">
        <section class="lms-card">
            <span class="badge-code">🎛️ FEATURE FLAGS APPLICATIFS</span>
            <h3 class="section-heading-md mt-xs mb-sm">Activation Dynamique des Modules Portail (<span class="tabular-nums"><?= count($featureDefs) ?></span>)</h3>
            <form method="POST" action="index.php?page=admin&tab=system&action=admin_save_features&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <?php foreach ($groupedDefs as $catName => $catItems): ?>
                    <div class="text-xs text-muted font-bold mt-sm mb-xs"><?= Security::e($catName) ?></div>
                    <div class="d-flex flex-col gap-sm mb-1">
                        <?php foreach ($catItems as $fKey => $fMeta):
                            $fKeyStr = (string)$fKey;
                            $fEnabled = !empty($featureState[$fKeyStr]);
                            $requires = is_array($fMeta['requires'] ?? null) ? $fMeta['requires'] : [];
                        ?>
                            <label class="feature-flag-row" for="flag-<?= Security::e($fKeyStr) ?>">
                                <input id="flag-<?= Security::e($fKeyStr) ?>" type="checkbox" name="features[<?= Security::e($fKeyStr) ?>]" value="1" <?= $fEnabled ? 'checked' : '' ?>/>
                                <div class="flex-1">
                                    <strong><?= Security::e((string)($fMeta['label'] ?? $fKeyStr)) ?></strong>
                                    <?php if (!empty($requires)): ?>
                                        <span class="badge-code">Requiert : <?= Security::e(implode(', ', $requires)) ?></span>
                                    <?php endif; ?>
                                    <div class="text-xs text-muted"><?= Security::e((string)($fMeta['description'] ?? '')) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-emerald btn-sm">
                    💾 Enregistrer les Feature Flags
                </button>
            </form>
        </section>

        <section class="lms-card">
            <span class="badge-code">🎓 SURCHARGES PAR FORMATION</span>
            <h3 class="section-heading-md mt-xs mb-sm">Politique Spécifique : <?= Security::e($selectedOverrideCourse) ?></h3>
            <form method="GET" action="index.php" class="d-flex flex-wrap gap-sm mb-sm">
                <input type="hidden" name="page" value="admin"/>
                <input type="hidden" name="tab" value="system"/>
                <input type="hidden" name="course" value="<?= urlencode($currentCourseSlug) ?>"/>
                <select name="override_course" class="form-control form-control-sm admin-role-select" aria-label="Formation ciblée par les surcharges">
                    <?php foreach ($allManageableCourses as $cSlug => $cCfg): ?>
                        <option value="<?= Security::e((string)$cSlug) ?>" <?= (string)$cSlug === $selectedOverrideCourse ? 'selected' : '' ?>>
                            <?= Security::e((string)($cCfg['short_title'] ?? $cCfg['title'] ?? $cSlug)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Charger</button>
            </form>

            <form method="POST" action="index.php?page=admin&tab=system&action=admin_save_course_features&course=<?= urlencode($currentCourseSlug) ?>">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"/>
                <input type="hidden" name="target_course_slug" value="<?= Security::e($selectedOverrideCourse) ?>"/>
                <div class="d-flex flex-col gap-xs mb-1">
                    <?php foreach ($featureDefs as $fKey => $fMeta):
                        $fKeyStr = (string)$fKey;
                        $hasOverride = array_key_exists($fKeyStr, $courseOverrides);
                        $ovVal = $hasOverride ? (!empty($courseOverrides[$fKeyStr]) ? '1' : '0') : 'inherit';
                    ?>
                        <div class="feature-flag-row">
                            <div class="text-xs">
                                <strong><?= Security::e((string)($fMeta['label'] ?? $fKeyStr)) ?></strong>
                            </div>
                            <select name="course_features[<?= Security::e($fKeyStr) ?>]" class="form-control form-control-sm admin-role-select" aria-label="Surcharge <?= Security::e($fKeyStr) ?>">
                                <option value="inherit" <?= $ovVal === 'inherit' ? 'selected' : '' ?>>Hériter du global</option>
                                <option value="1" <?= $ovVal === '1' ? 'selected' : '' ?>>Forcer Activé</option>
                                <option value="0" <?= $ovVal === '0' ? 'selected' : '' ?>>Forcer Désactivé</option>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-wrap gap-sm">
                    <button type="submit" class="btn btn-primary btn-sm">💾 Appliquer à cette Formation</button>
                    <button type="submit" name="reset_course_features" value="1" class="btn btn-outline btn-sm">↩ Réinitialiser</button>
                </div>
            </form>
        </section>
    </div>

    <section class="lms-card">
        <div class="admin-toolbar mb-sm">
            <div>
                <span class="badge-code">🩺 DIAGNOSTIC SANTÉ SYSTÈME</span>
                <h3 class="section-heading-md mt-xs mb-0">État des Composants Techniques</h3>
            </div>
            <span class="badge-completed tabular-nums">
                <?= (int)$healthReport['summary']['ok'] ?>/<?= (int)$healthReport['summary']['total'] ?> OK
            </span>
        </div>
        <div class="lms-table-wrap">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th class="table-col-nowrap">Composant</th>
                        <th class="table-col-nowrap">Statut</th>
                        <th>Détail Technique</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($healthReport['checks'] as $chk):
                        $st = (string)($chk['status'] ?? 'ok');
                        $badgeClass = $st === 'ok' ? 'badge-completed' : ($st === 'warn' ? 'badge-phase' : 'badge-danger-text');
                    ?>
                        <tr>
                            <td class="table-col-nowrap"><strong><?= Security::e((string)$chk['component']) ?></strong></td>
                            <td class="table-col-nowrap"><span class="<?= $badgeClass ?>"><?= Security::e(strtoupper($st)) ?></span></td>
                            <td class="text-xs text-secondary"><?= Security::e((string)$chk['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
