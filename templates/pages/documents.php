<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$defaultDocTab = (($page ?? '') === 'cheatsheets') ? 'cheatsheets' : 'all';
$docTab = (string)($_GET['tab'] ?? $defaultDocTab);
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div>
        <span class="badge-code">CENTRE DOCUMENTAIRE EXHAUSTIF • TOUS FORMATS</span>
        <h1 style="font-size: 1.65rem; margin: 0.4rem 0 0.25rem;">📥 Bibliothèque & Téléchargements Multi-Formats</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Téléchargez l'ensemble des livrables de la formation en haute définition :
            Supports <code>.pdf</code>, Présentations Studio <code>.pptx</code> & <code>_slides.pdf</code>, Sources <code>.md</code>, Pages Web <code>.html</code>, Documents Word <code>.docx</code> et Scripts <code>.sql / .py</code>.
        </p>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 0.45rem;">
        <a href="index.php?page=documents&tab=all&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'all' ? 'btn-primary' : 'btn-outline' ?>">Tout Voir</a>
        <a href="index.php?page=documents&tab=referentiel&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'referentiel' ? 'btn-primary' : 'btn-outline' ?>">📋 Programme & Qualiopi (<?= count($currentCourse['referentiel'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=modules&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'modules' ? 'btn-primary' : 'btn-outline' ?>">📚 Modules (<?= count($currentCourse['modules'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'labs' ? 'btn-primary' : 'btn-outline' ?>">🧪 Labs (<?= count($currentCourse['labs'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=cheatsheets&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'cheatsheets' ? 'btn-primary' : 'btn-outline' ?>">📑 Cheatsheets (<?= count($currentCourse['cheatsheets'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=tools&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'tools' ? 'btn-primary' : 'btn-outline' ?>">🛠️ Scripts DBA (<?= count($currentCourse['tools'] ?? []) ?>)</a>
    </div>
</div>

<!-- 1. PROGRAMME, SYLLABUS, LIVRETS & RÉFÉRENTIEL QUALIOPI -->
<?php if ($docTab === 'all' || $docTab === 'referentiel'): ?>
    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">📋 Programme, Syllabus, Guide Formateur, Examen & Référentiel Qualiopi</h2>
    <div class="lms-table-wrap" style="margin-bottom: 2.25rem;">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Document / Référentiel</th>
                    <th>Formats de Téléchargement Directs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['referentiel'] ?? []) as $docId => $doc): ?>
                    <tr data-searchable="<?= Security::e($doc['category'] . ' ' . $doc['title'] . ' ' . $docId) ?>">
                        <td><span class="badge-code"><?= Security::e($doc['category']) ?></span></td>
                        <td>
                            <strong style="color: var(--text-primary);"><?= Security::e($doc['title']) ?></strong>
                            <div style="font-size: 0.78rem; color: var(--text-muted);"><?= Security::e($docId) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'referentiel', $docId, $doc['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 2. SUPPORTS DE COURS (34 MODULES : PDF, SLIDES PDF, PPTX, MD, HTML, DOCX) -->
<?php if ($docTab === 'all' || $docTab === 'modules'): ?>
    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">📚 Supports de Cours & Présentations Studio (34 Modules)</h2>
    <div class="lms-table-wrap" style="margin-bottom: 2.25rem;">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Intitulé du Module</th>
                    <th>Téléchargements (PDF, Slides PDF, PPTX, MD, HTML, DOCX)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['modules'] ?? []) as $mId => $mod): ?>
                    <tr data-searchable="<?= Security::e($mod['badge_code'] . ' ' . $mod['title'] . ' ' . $mId) ?>">
                        <td><span class="badge-code"><?= Security::e($mod['badge_code']) ?></span></td>
                        <td>
                            <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="font-weight: 700; color: var(--text-primary);">
                                <?= Security::e($mod['title']) ?>
                            </a>
                            <div style="font-size: 0.78rem; color: var(--text-muted);"><?= Security::e($mod['track_title']) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 3. ATELIERS PRATIQUES & LABS DOCKER (34 LABS) -->
<?php if ($docTab === 'all' || $docTab === 'labs'): ?>
    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">🧪 Énoncés & Corrigés des 34 Ateliers Pratiques (Labs)</h2>
    <div class="lms-table-wrap" style="margin-bottom: 2.25rem;">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Intitulé de l'Atelier Pratique</th>
                    <th>Téléchargements (PDF, MD, HTML, DOCX)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['labs'] ?? []) as $labId => $lab): ?>
                    <tr data-searchable="<?= Security::e($lab['badge_code'] . ' ' . $lab['title'] . ' ' . $labId) ?>">
                        <td><span class="badge-code" style="color:#34D399;"><?= Security::e($lab['badge_code']) ?></span></td>
                        <td>
                            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" style="font-weight: 700; color: var(--text-primary);">
                                <?= Security::e($lab['title']) ?>
                            </a>
                            <div style="font-size: 0.78rem; color: var(--text-muted);"><?= Security::e($lab['bloom']) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 4. CHEATSHEETS & FICHES MÉMO (69 FICHES) -->
<?php if ($docTab === 'all' || $docTab === 'cheatsheets'): ?>
    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">📑 Fiches Mémo & Cheatsheets Opérationnelles (<?= count($currentCourse['cheatsheets'] ?? []) ?> Fiches)</h2>
    <div class="lms-table-wrap" style="margin-bottom: 2.25rem;">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Fiche Mémo / Aide-Mémoire</th>
                    <th>Téléchargements (PDF, MD, HTML, DOCX)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['cheatsheets'] ?? []) as $csId => $cs): ?>
                    <tr data-searchable="<?= Security::e($cs['title'] . ' ' . $csId) ?>">
                        <td><span class="badge-code"><?= strtoupper(Security::e($cs['type'])) ?></span></td>
                        <td>
                            <strong style="color: var(--text-primary);"><?= Security::e($cs['title']) ?></strong>
                            <div style="font-size: 0.76rem; color: var(--text-muted);"><?= Security::e($csId) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'cheatsheet', $csId, $cs['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 5. BOÎTE À OUTILS DBA PGSCRIPTS (PYTHON & SQL) -->
<?php if ($docTab === 'all' || $docTab === 'tools'): ?>
    <h2 style="font-size: 1.3rem; margin-bottom: 0.85rem;">🛠️ Boîte à Outils Production DBA (`pgscripts` Python & 23 Scripts SQL)</h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Fichier Script</th>
                    <th>Langage</th>
                    <th>Taille</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['tools'] ?? []) as $tId => $tool): ?>
                    <tr data-searchable="<?= Security::e($tool['filename'] . ' ' . $tool['category']) ?>">
                        <td><span class="badge-code"><?= Security::e($tool['category']) ?></span></td>
                        <td><code><?= Security::e($tool['filename']) ?></code></td>
                        <td><?= Security::e($tool['language']) ?></td>
                        <td class="tabular-nums"><?= Security::e($tool['size_formatted']) ?></td>
                        <td>
                            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=tool&id=<?= urlencode($tId) ?>&format=raw" class="dl-pill dl-md">
                                ⬇ Télécharger <?= Security::e($tool['filename']) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
