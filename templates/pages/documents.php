<?php
declare(strict_types=1);
use LightPath\LMS\Core\Security;

$defaultDocTab = (($page ?? '') === 'cheatsheets') ? 'cheatsheets' : 'all';
$docTab = (string)($_GET['tab'] ?? $defaultDocTab);
$selectedDocId = trim((string)($_GET['id'] ?? ''));
$selectedDoc = null;
$selectedTool = null;
$selectedExample = null;
if ($selectedDocId !== '') {
    $selectedDoc = $currentCourse['cheatsheets'][$selectedDocId]
        ?? $currentCourse['referentiel'][$selectedDocId]
        ?? null;
    if ($selectedDoc === null && isset($currentCourse['tools'][$selectedDocId])) {
        $selectedTool = $currentCourse['tools'][$selectedDocId];
    }
    if ($selectedDoc === null && $selectedTool === null && isset($currentCourse['examples'][$selectedDocId])) {
        $selectedExample = $currentCourse['examples'][$selectedDocId];
    }
}
?>

<?php if ($selectedDoc !== null && !empty($selectedDoc['source_md_path']) && is_file($selectedDoc['source_md_path'])):
    $renderedDoc = $mdParser->renderMarkdown((string)file_get_contents($selectedDoc['source_md_path']), $currentCourseSlug, false);
?>
<div class="lms-card mb-1-75 lms-card--accent-cyan">
    <div class="d-flex justify-between items-center flex-wrap gap-md mb-1 pb-sm border-bottom-hairline">
        <div>
            <span class="badge-code">LECTEUR DOCUMENTAIRE INTÉGRÉ</span>
            <h2 class="text-xl mt-xs mb-0"><?= Security::e($selectedDoc['title']) ?></h2>
        </div>
        <div class="d-flex items-center gap-xs flex-wrap">
            <?= renderDownloadStrip($currentCourseSlug, isset($currentCourse['cheatsheets'][$selectedDocId]) ? 'cheatsheet' : 'referentiel', $selectedDocId, $selectedDoc['downloads'] ?? []) ?>
            <a href="index.php?page=documents&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">✕ Fermer l'aperçu</a>
        </div>
    </div>
    <article class="reader-article markdown-body">
        <?= $renderedDoc['html'] ?>
    </article>
</div>
<?php elseif ($selectedTool !== null && !empty($selectedTool['path']) && is_file($selectedTool['path'])):
    $toolExt = strtolower(pathinfo((string)$selectedTool['filename'], PATHINFO_EXTENSION));
    $toolLang = $toolExt === 'py' ? 'python' : ($toolExt === 'sql' ? 'sql' : 'bash');
    $toolMd = "```" . $toolLang . "\n" . (string)file_get_contents($selectedTool['path']) . "\n```";
    $renderedTool = $mdParser->renderMarkdown($toolMd, $currentCourseSlug, false);
?>
<div class="lms-card mb-1-75 lms-card--accent-cyan">
    <div class="d-flex justify-between items-center flex-wrap gap-md mb-1 pb-sm border-bottom-hairline">
        <div>
            <span class="badge-code">APERÇU CODE SOURCE DBA • <?= Security::e($selectedTool['category']) ?></span>
            <h2 class="text-xl mt-xs mb-0"><code><?= Security::e($selectedTool['filename']) ?></code></h2>
        </div>
        <div class="d-flex items-center gap-xs flex-wrap">
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=tool&id=<?= urlencode($selectedDocId) ?>&format=raw" class="dl-pill dl-md">⬇ Télécharger <?= Security::e($selectedTool['filename']) ?></a>
            <a href="index.php?page=documents&tab=tools&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">✕ Fermer l'aperçu</a>
        </div>
    </div>
    <article class="reader-article markdown-body">
        <?= $renderedTool['html'] ?>
    </article>
</div>
<?php elseif ($selectedExample !== null && !empty($selectedExample['path']) && is_file($selectedExample['path'])):
    $exExt = strtolower(pathinfo((string)$selectedExample['filename'], PATHINFO_EXTENSION));
    $exLang = match ($exExt) {
        'py' => 'python',
        'go' => 'go',
        'php' => 'php',
        'md' => 'markdown',
        'sql' => 'sql',
        default => 'text',
    };
    $rawExContent = (string)file_get_contents($selectedExample['path']);
    $exMd = ($exExt === 'md') ? $rawExContent : ("```" . $exLang . "\n" . $rawExContent . "\n```");
    $renderedExample = $mdParser->renderMarkdown($exMd, $currentCourseSlug, false);
?>
<div class="lms-card mb-1-75 lms-card--accent-cyan">
    <div class="d-flex justify-between items-center flex-wrap gap-md mb-1 pb-sm border-bottom-hairline">
        <div>
            <span class="badge-code">APERÇU CODE COMPAGNON • <?= Security::e((string)($selectedExample['language'] ?? $selectedExample['category'] ?? 'Exemple')) ?></span>
            <h2 class="text-xl mt-xs mb-0"><code><?= Security::e((string)$selectedExample['filename']) ?></code></h2>
        </div>
        <div class="d-flex items-center gap-xs flex-wrap">
            <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=example&id=<?= urlencode($selectedDocId) ?>&format=raw" class="dl-pill dl-md">⬇ Télécharger <?= Security::e((string)$selectedExample['filename']) ?></a>
            <a href="index.php?page=documents&tab=examples&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline btn-sm">✕ Fermer l'aperçu</a>
        </div>
    </div>
    <article class="reader-article markdown-body">
        <?= $renderedExample['html'] ?>
    </article>
</div>
<?php endif; ?>

<div class="hero-banner p-hero-compact">
    <div>
        <span class="badge-code">CENTRE DOCUMENTAIRE EXHAUSTIF • TOUS FORMATS</span>
        <h1 class="text-hero-sm mt-xs mb-xxs">📥 Bibliothèque & Téléchargements Multi-Formats</h1>
        <p class="text-secondary mb-0 text-sm">
            Téléchargez l'ensemble des livrables de la formation en haute définition :
            Supports <code>.pdf</code>, Présentations Studio <code>.pptx</code> & <code>_slides.pdf</code>, Sources <code>.md</code>, Pages Web <code>.html</code>, Documents Word <code>.docx</code>, Scripts <code>.sql / .py</code> et Exemples <code>pgexamples</code>.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-xs">
        <a href="index.php?page=documents&tab=all&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'all' ? 'btn-primary' : 'btn-outline' ?>">Tout Voir</a>
        <a href="index.php?page=documents&tab=referentiel&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'referentiel' ? 'btn-primary' : 'btn-outline' ?>">📋 Programme & Qualiopi (<?= count($currentCourse['referentiel'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=modules&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'modules' ? 'btn-primary' : 'btn-outline' ?>">📚 Modules (<?= count($currentCourse['modules'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=labs&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'labs' ? 'btn-primary' : 'btn-outline' ?>">🧪 Labs (<?= count($currentCourse['labs'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=cheatsheets&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'cheatsheets' ? 'btn-primary' : 'btn-outline' ?>">📑 Cheatsheets (<?= count($currentCourse['cheatsheets'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=tools&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'tools' ? 'btn-primary' : 'btn-outline' ?>">🛠️ Scripts DBA (<?= count($currentCourse['tools'] ?? []) ?>)</a>
        <a href="index.php?page=documents&tab=examples&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-sm <?= $docTab === 'examples' ? 'btn-primary' : 'btn-outline' ?>">💻 Exemples Code (<?= count($currentCourse['examples'] ?? []) ?>)</a>
    </div>
</div>

<!-- 1. Program, syllabus, booklets & Qualiopi reference -->
<?php if ($docTab === 'all' || $docTab === 'referentiel'): ?>
    <h2 class="section-heading-md mb-sm">📋 Programme, Syllabus, Guide Formateur, Examen & Référentiel Qualiopi</h2>
    <div class="lms-table-wrap mb-xl">
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
                            <a href="index.php?page=documents&tab=referentiel&id=<?= urlencode($docId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <?= Security::e($doc['title']) ?>
                            </a>
                            <div class="text-xs text-muted"><?= Security::e($docId) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'referentiel', $docId, $doc['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 2. Course modules (PDF, Slides PDF, PPTX, MD, HTML, DOCX) -->
<?php if ($docTab === 'all' || $docTab === 'modules'): ?>
    <h2 class="section-heading-md mb-sm">📚 Supports de Cours & Présentations Studio (<?= count($currentCourse['modules'] ?? []) ?> Modules)</h2>
    <div class="lms-table-wrap mb-xl">
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
                            <a href="index.php?page=module&id=<?= urlencode($mId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <?= Security::e($mod['title']) ?>
                            </a>
                            <div class="text-xs text-muted"><?= Security::e($mod['track_title']) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'module', $mId, $mod['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 3. Practice labs & Docker workshops -->
<?php if ($docTab === 'all' || $docTab === 'labs'): ?>
    <h2 class="section-heading-md mb-sm">🧪 Énoncés & Corrigés des <?= count($currentCourse['labs'] ?? []) ?> Ateliers Pratiques (Labs)</h2>
    <div class="lms-table-wrap mb-xl">
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
                        <td><span class="badge-code text-emerald-bright"><?= Security::e($lab['badge_code']) ?></span></td>
                        <td>
                            <a href="index.php?page=lab&id=<?= urlencode($labId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <?= Security::e($lab['title']) ?>
                            </a>
                            <div class="text-xs text-muted"><?= Security::e($lab['bloom']) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'lab', $labId, $lab['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 4. Operational cheatsheets and quick-reference cards -->
<?php if ($docTab === 'all' || $docTab === 'cheatsheets'): ?>
    <h2 class="section-heading-md mb-sm">📑 Fiches Mémo & Cheatsheets Opérationnelles (<?= count($currentCourse['cheatsheets'] ?? []) ?> Fiches)</h2>
    <div class="lms-table-wrap mb-xl">
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
                            <a href="index.php?page=documents&tab=cheatsheets&id=<?= urlencode($csId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <?= Security::e($cs['title']) ?>
                            </a>
                            <div class="text-xs text-muted"><?= Security::e($csId) ?></div>
                        </td>
                        <td><?= renderDownloadStrip($currentCourseSlug, 'cheatsheet', $csId, $cs['downloads'] ?? []) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 5. Production DBA toolbox pgscripts (Python & SQL) -->
<?php if ($docTab === 'all' || $docTab === 'tools'): ?>
    <h2 class="section-heading-md mb-sm">🛠️ Boîte à Outils Production DBA (<code>pgscripts</code> Python & 23 Scripts SQL)</h2>
    <div class="lms-table-wrap mb-xl">
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
                        <td>
                            <a href="index.php?page=documents&tab=tools&id=<?= urlencode($tId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <code><?= Security::e($tool['filename']) ?></code>
                            </a>
                        </td>
                        <td><?= Security::e($tool['language']) ?></td>
                        <td class="tabular-nums"><?= Security::e($tool['size_formatted']) ?></td>
                        <td>
                            <div class="download-strip">
                                <a href="index.php?page=documents&tab=tools&id=<?= urlencode($tId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="dl-pill dl-read">
                                    👁 Lire
                                </a>
                                <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=tool&id=<?= urlencode($tId) ?>&format=raw" class="dl-pill dl-md">
                                    ⬇ Télécharger <?= Security::e($tool['filename']) ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- 6. Companion code examples pgexamples (Python, Go, PHP) -->
<?php if ($docTab === 'all' || $docTab === 'examples'): ?>
    <h2 class="section-heading-md mb-sm">💻 Exemples de Code Compagnon (<code>pgexamples</code> — Python <code>psycopg3</code>, Go <code>pgx/v5</code>, PHP <code>PDO</code>)</h2>
    <div class="lms-table-wrap">
        <table class="lms-table">
            <thead>
                <tr>
                    <th>Stack / Catégorie</th>
                    <th>Fichier Exemple</th>
                    <th>Langage</th>
                    <th>Taille</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($currentCourse['examples'] ?? []) as $exId => $ex): ?>
                    <tr data-searchable="<?= Security::e(($ex['filename'] ?? '') . ' ' . ($ex['category'] ?? '') . ' ' . ($ex['language'] ?? '')) ?>">
                        <td><span class="badge-code"><?= Security::e($ex['category'] ?? 'Code') ?></span></td>
                        <td>
                            <a href="index.php?page=documents&tab=examples&id=<?= urlencode($exId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="font-bold link-primary">
                                <code><?= Security::e($ex['filename'] ?? $exId) ?></code>
                            </a>
                        </td>
                        <td><?= Security::e($ex['language'] ?? '') ?></td>
                        <td class="tabular-nums"><?= Security::e($ex['size_formatted'] ?? '') ?></td>
                        <td>
                            <div class="download-strip">
                                <a href="index.php?page=documents&tab=examples&id=<?= urlencode($exId) ?>&course=<?= urlencode($currentCourseSlug) ?>" class="dl-pill dl-read">
                                    👁 Lire
                                </a>
                                <a href="index.php?action=download&course=<?= urlencode($currentCourseSlug) ?>&type=example&id=<?= urlencode($exId) ?>&format=raw" class="dl-pill dl-md">
                                    ⬇ Télécharger <?= Security::e($ex['filename'] ?? $exId) ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
