<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

class CourseRepository
{
    private array $coursesConfig;
    private array $cache = [];
    private string $cacheDir;

    public function __construct(array $coursesConfig, array $customCourses = [], ?string $cacheDir = null)
    {
        $this->coursesConfig = array_merge($coursesConfig, $customCourses);
        $this->cacheDir = $cacheDir ?? (dirname(__DIR__, 2) . '/storage/data');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public function getAllCourses(): array
    {
        $result = [];
        foreach ($this->coursesConfig as $slug => $cfg) {
            if (!empty($cfg['active'])) {
                $course = $this->getCourse((string)$slug);
                if ($course !== null) {
                    $result[$slug] = $course;
                }
            }
        }
        return $result;
    }

    public function getCourse(string $slug): ?array
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }
        if (!isset($this->coursesConfig[$slug])) {
            return null;
        }

        $cfg = $this->coursesConfig[$slug];
        $root = rtrim((string)($cfg['source_root'] ?? ''), '/\\');
        $pgScriptsRoot = (string)($cfg['pgscripts_root'] ?? '');

        $fingerprint = $this->computeCatalogFingerprint($root, $pgScriptsRoot, $cfg['tracks'] ?? []);
        $cacheFile = $this->cacheDir . '/catalog_cache_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $slug) . '.json';

        if (is_file($cacheFile)) {
            $cachedRaw = @file_get_contents($cacheFile);
            if ($cachedRaw !== false) {
                $cachedData = @json_decode($cachedRaw, true);
                if (is_array($cachedData) && ($cachedData['fingerprint'] ?? '') === $fingerprint && isset($cachedData['payload'])) {
                    $course = array_merge($cfg, $cachedData['payload']);
                    $this->cache[$slug] = $course;
                    return $course;
                }
            }
        }

        $modules = $this->discoverModules($root, $cfg['tracks'] ?? []);
        $labs = $this->discoverLabs($root);
        $cheatsheets = $this->discoverCheatsheets($root);
        $referentiel = $this->discoverReferentiel($root);
        $tools = $this->discoverTools($pgScriptsRoot);

        // Lier chaque module à son Lab et sa Cheatsheet correspondants + propager le parcours au Lab
        foreach ($modules as $modId => &$mod) {
            $code = $mod['code'];
            foreach ($labs as $labId => &$lab) {
                if ($lab['code'] === $code) {
                    $mod['linked_lab_id'] = $labId;
                    $mod['linked_lab_title'] = $lab['title'];
                    $lab['linked_module_id'] = $modId;
                    $lab['linked_module_title'] = $mod['title'];
                    $lab['track_id'] = $mod['track_id'];
                    $lab['track_title'] = $mod['track_title'];
                    $lab['track_short_title'] = $mod['track_short_title'];
                    $lab['track_number'] = $mod['track_number'];
                    $lab['track_color'] = $mod['track_color'];
                    $lab['phase_code'] = $mod['phase_code'];
                    $lab['phase_title'] = $mod['phase_title'];
                    $lab['track_position'] = $mod['track_position'];
                    $lab['track_total'] = $mod['track_total'];
                    break;
                }
            }
            unset($lab);

            foreach ($cheatsheets as $csId => $cs) {
                if ($cs['type'] === 'module' && $cs['code'] === $code) {
                    $mod['linked_cheatsheet_id'] = $csId;
                    $mod['linked_cheatsheet_title'] = $cs['title'];
                    break;
                }
            }
        }
        unset($mod);

        // Lier chaque Lab à sa Cheatsheet de Lab correspondante (RCA-154)
        foreach ($labs as $labId => &$lab) {
            $code = $lab['code'];
            foreach ($cheatsheets as $csId => $cs) {
                if ($cs['type'] === 'lab' && $cs['code'] === $code) {
                    $lab['linked_cheatsheet_id'] = $csId;
                    $lab['linked_cheatsheet_title'] = $cs['title'];
                    break;
                }
            }
        }
        unset($lab);

        $payload = [
            'modules' => $modules,
            'labs' => $labs,
            'cheatsheets' => $cheatsheets,
            'referentiel' => $referentiel,
            'tools' => $tools,
            'stats' => [
                'modules_count' => count($modules),
                'labs_count' => count($labs),
                'cheatsheets_count' => count($cheatsheets),
                'referentiel_count' => count($referentiel),
                'tools_count' => count($tools),
            ],
        ];

        @file_put_contents($cacheFile, json_encode([
            'fingerprint' => $fingerprint,
            'generated_at' => date('c'),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $course = array_merge($cfg, $payload);
        $this->cache[$slug] = $course;
        return $course;
    }

    private function computeCatalogFingerprint(string $root, string $pgScriptsRoot, array $tracks): string
    {
        $dirs = [
            $root . '/03_supports_de_cours',
            $root . '/04_ateliers_et_tp',
            $root . '/05_cheatsheets/modules',
            $root . '/05_cheatsheets/labs',
            $root . '/01_programme_et_referentiel',
            $pgScriptsRoot,
        ];
        $parts = ['v6_rca158', md5(json_encode($tracks) ?: '')];
        foreach ($dirs as $d) {
            $parts[] = is_dir($d) ? (string)@filemtime($d) : '0';
        }
        return implode(':', $parts);
    }

    public function getModule(string $courseSlug, string $moduleId): ?array
    {
        $course = $this->getCourse($courseSlug);
        return $course['modules'][$moduleId] ?? null;
    }

    public function getLab(string $courseSlug, string $labId): ?array
    {
        $course = $this->getCourse($courseSlug);
        return $course['labs'][$labId] ?? null;
    }

    public function getCheatsheet(string $courseSlug, string $csId): ?array
    {
        $course = $this->getCourse($courseSlug);
        return $course['cheatsheets'][$csId] ?? null;
    }

    public function getReferentielDoc(string $courseSlug, string $docId): ?array
    {
        $course = $this->getCourse($courseSlug);
        return $course['referentiel'][$docId] ?? null;
    }

    public function getToolDoc(string $courseSlug, string $toolId): ?array
    {
        $course = $this->getCourse($courseSlug);
        return $course['tools'][$toolId] ?? null;
    }

    /**
     * Recherche globale plein-texte rapide sur les modules, ateliers pratiques, mémos techniques, référentiels et glossaire PostgreSQL 18.
     */
    public function searchContent(string $courseSlug, string $query): array
    {
        $q = trim($query);
        if ($q === '' || strlen($q) < 2) {
            return [];
        }
        $course = $this->getCourse($courseSlug);
        if ($course === null) {
            return [];
        }

        $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        $results = [];

        foreach ($course['modules'] ?? [] as $modId => $mod) {
            $haystack = ($mod['title'] ?? '') . ' ' . ($mod['summary'] ?? '') . ' ' . ($mod['bloom'] ?? '') . ' ' . ($mod['code'] ?? '');
            $haystackLower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            $matchedInBody = false;
            $snippet = (string)($mod['summary'] ?? '');
            if (!str_contains($haystackLower, $needle) && !empty($mod['source_md_path']) && is_file($mod['source_md_path'])) {
                $raw = (string)@file_get_contents($mod['source_md_path']);
                $pos = stripos($raw, $q);
                if ($pos !== false) {
                    $matchedInBody = true;
                    $start = max(0, $pos - 60);
                    $snippet = '…' . trim(preg_replace('/\s+/', ' ', substr($raw, $start, 180)) ?? '') . '…';
                }
            }
            if (str_contains($haystackLower, $needle) || $matchedInBody) {
                $results[] = [
                    'type' => 'module',
                    'badge' => $mod['badge_code'] ?? ('MOD ' . $mod['code']),
                    'id' => $modId,
                    'title' => $mod['title'],
                    'snippet' => $snippet,
                    'url' => '?page=module&course=' . urlencode($courseSlug) . '&id=' . urlencode($modId),
                ];
            }
        }

        foreach ($course['labs'] ?? [] as $labId => $lab) {
            $haystack = ($lab['title'] ?? '') . ' ' . ($lab['summary'] ?? '') . ' ' . ($lab['bloom'] ?? '') . ' ' . ($lab['code'] ?? '');
            $haystackLower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            $matchedInBody = false;
            $snippet = (string)($lab['summary'] ?? '');
            if (!str_contains($haystackLower, $needle) && !empty($lab['source_md_path']) && is_file($lab['source_md_path'])) {
                $raw = (string)@file_get_contents($lab['source_md_path']);
                $pos = stripos($raw, $q);
                if ($pos !== false) {
                    $matchedInBody = true;
                    $start = max(0, $pos - 60);
                    $snippet = '…' . trim(preg_replace('/\s+/', ' ', substr($raw, $start, 180)) ?? '') . '…';
                }
            }
            if (str_contains($haystackLower, $needle) || $matchedInBody) {
                $results[] = [
                    'type' => 'lab',
                    'badge' => $lab['badge_code'] ?? ('LAB ' . $lab['code']),
                    'id' => $labId,
                    'title' => $lab['title'],
                    'snippet' => $snippet,
                    'url' => '?page=lab&course=' . urlencode($courseSlug) . '&id=' . urlencode($labId),
                ];
            }
        }

        foreach ($course['cheatsheets'] ?? [] as $csId => $cs) {
            $haystack = ($cs['title'] ?? '') . ' ' . ($cs['summary'] ?? '') . ' ' . ($cs['code'] ?? '');
            $haystackLower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            if (str_contains($haystackLower, $needle)) {
                $results[] = [
                    'type' => 'cheatsheet',
                    'badge' => 'MÉMO ' . strtoupper((string)($cs['code'] ?? '')),
                    'id' => $csId,
                    'title' => $cs['title'],
                    'snippet' => (string)($cs['summary'] ?? ''),
                    'url' => '?page=documents&course=' . urlencode($courseSlug) . '&id=' . urlencode($csId),
                ];
            }
        }

        if (class_exists(LmsCatalog::class)) {
            foreach (LmsCatalog::getGlossary() as $g) {
                $haystack = ($g['term'] ?? '') . ' ' . ($g['category'] ?? '') . ' ' . ($g['definition'] ?? '');
                $haystackLower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                if (str_contains($haystackLower, $needle)) {
                    $results[] = [
                        'type' => 'glossary',
                        'badge' => 'GLOSSAIRE DBA',
                        'id' => $g['term'],
                        'title' => $g['term'] . ' (' . $g['category'] . ')',
                        'snippet' => $g['definition'],
                        'url' => '?page=flashcards&course=' . urlencode($courseSlug) . '&tab=glossary&q=' . urlencode($g['term']),
                    ];
                }
            }
        }

        return array_slice($results, 0, 50);
    }

    private function discoverModules(string $root, array $tracks): array
    {
        $dir = $root . '/03_supports_de_cours';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/module_*.md') ?: [];
        natsort($files);

        $modules = [];
        foreach ($files as $mdPath) {
            $baseName = pathinfo($mdPath, PATHINFO_FILENAME);
            $code = $this->extractItemCode($baseName, 'module_');
            $meta = $this->extractMarkdownSummary($mdPath, $baseName);

            $trackId = 'cursus-complet';
            $trackTitle = 'Parcours 1 : Cursus Complet';
            $trackShortTitle = 'Cursus Complet (M01–M20)';
            $trackNumber = 'Parcours 1';
            $trackColor = '#00B0F0';
            $phaseCode = 'ÉTAPE';
            $phaseTitle = 'Formation PostgreSQL 18';
            $trackPos = 1;
            $trackTotal = 1;

            foreach ($tracks as $tKey => $tCfg) {
                $filterList = $tCfg['module_Filter'] ?? [];
                $posIdx = array_search($code, $filterList, true);
                if ($posIdx !== false) {
                    $trackId = (string)$tKey;
                    $trackTitle = (string)$tCfg['title'];
                    $trackShortTitle = (string)($tCfg['short_title'] ?? $tCfg['title']);
                    $trackNumber = (string)($tCfg['number'] ?? 'Parcours');
                    $trackColor = (string)($tCfg['color'] ?? '#00B0F0');
                    $trackPos = ((int)$posIdx) + 1;
                    $trackTotal = count($filterList);

                    foreach ($tCfg['phases'] ?? [] as $phase) {
                        if (in_array($code, $phase['modules'] ?? [], true)) {
                            $phaseCode = (string)$phase['code'];
                            $phaseTitle = (string)$phase['title'];
                            break;
                        }
                    }
                    break;
                }
            }

            $downloads = $this->collectDownloads($dir . '/' . $baseName, [
                'pdf' => ['ext' => '.pdf', 'label' => 'Support PDF'],
                'slides_pdf' => ['ext' => '_slides.pdf', 'label' => 'Slides PDF'],
                'pptx' => ['ext' => '.pptx', 'label' => 'PowerPoint PPTX'],
                'md' => ['ext' => '.md', 'label' => 'Markdown'],
                'html' => ['ext' => '.html', 'label' => 'Web HTML'],
                'docx' => ['ext' => '.docx', 'label' => 'Word DOCX'],
            ]);

            $modules[$baseName] = [
                'id' => $baseName,
                'code' => $code,
                'badge_code' => str_starts_with($code, 'sql_') ? strtoupper(str_replace('_', ' ', $code)) : 'MOD ' . $code,
                'title' => $meta['title'],
                'bloom' => $meta['bloom'],
                'duration' => $meta['duration'],
                'summary' => $meta['summary'],
                'track_id' => $trackId,
                'track_title' => $trackTitle,
                'track_short_title' => $trackShortTitle,
                'track_number' => $trackNumber,
                'track_color' => $trackColor,
                'phase_code' => $phaseCode,
                'phase_title' => $phaseTitle,
                'track_position' => $trackPos,
                'track_total' => $trackTotal,
                'source_md_path' => $mdPath,
                'source_html_path' => is_file($dir . '/' . $baseName . '.html') ? ($dir . '/' . $baseName . '.html') : null,
                'downloads' => $downloads,
                'linked_lab_id' => null,
                'linked_lab_title' => null,
                'linked_cheatsheet_id' => null,
            ];
        }

        return $modules;
    }

    private function discoverLabs(string $root): array
    {
        $dir = $root . '/04_ateliers_et_tp';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/lab_*.md') ?: [];
        natsort($files);

        $labs = [];
        foreach ($files as $mdPath) {
            $baseName = pathinfo($mdPath, PATHINFO_FILENAME);
            $code = $this->extractItemCode($baseName, 'lab_');
            $meta = $this->extractMarkdownSummary($mdPath, $baseName);

            $downloads = $this->collectDownloads($dir . '/' . $baseName, [
                'pdf' => ['ext' => '.pdf', 'label' => 'Atelier PDF'],
                'md' => ['ext' => '.md', 'label' => 'Markdown'],
                'html' => ['ext' => '.html', 'label' => 'Web HTML'],
                'docx' => ['ext' => '.docx', 'label' => 'Word DOCX'],
            ]);

            $labs[$baseName] = [
                'id' => $baseName,
                'code' => $code,
                'badge_code' => str_starts_with($code, 'sql_') ? 'LAB ' . strtoupper(str_replace('_', ' ', $code)) : 'LAB ' . $code,
                'title' => $meta['title'],
                'bloom' => $meta['bloom'],
                'duration' => $meta['duration'],
                'summary' => $meta['summary'],
                'track_id' => 'cursus-complet',
                'track_title' => 'Parcours 1 : Cursus Complet',
                'track_short_title' => 'Cursus Complet',
                'track_number' => 'Parcours 1',
                'track_color' => '#00B0F0',
                'phase_code' => 'ATELIER',
                'phase_title' => 'Mise en Pratique Qualiopi',
                'track_position' => 1,
                'track_total' => 20,
                'source_md_path' => $mdPath,
                'source_html_path' => is_file($dir . '/' . $baseName . '.html') ? ($dir . '/' . $baseName . '.html') : null,
                'downloads' => $downloads,
                'linked_module_id' => null,
                'linked_module_title' => null,
                'linked_cheatsheet_id' => null,
                'linked_cheatsheet_title' => null,
            ];
        }

        return $labs;
    }

    private function discoverCheatsheets(string $root): array
    {
        $dir = $root . '/05_cheatsheets';
        if (!is_dir($dir)) {
            return [];
        }

        $files = array_merge(
            glob($dir . '/modules/cheatsheet_*.md') ?: [],
            glob($dir . '/labs/cheatsheet_*.md') ?: [],
            glob($dir . '/*.md') ?: []
        );
        natsort($files);

        $sheets = [];
        foreach ($files as $mdPath) {
            $baseName = pathinfo($mdPath, PATHINFO_FILENAME);
            if (strtolower($baseName) === 'readme') {
                continue;
            }
            $subDir = dirname($mdPath);
            $type = str_contains($baseName, '_lab_') ? 'lab' : (str_contains($baseName, '_mod_') ? 'module' : 'global');
            $code = $this->extractItemCode($baseName, $type === 'lab' ? 'cheatsheet_lab_' : 'cheatsheet_mod_');
            $meta = $this->extractMarkdownSummary($mdPath, $baseName);

            $downloads = $this->collectDownloads($subDir . '/' . $baseName, [
                'pdf' => ['ext' => '.pdf', 'label' => 'Mémo PDF'],
                'md' => ['ext' => '.md', 'label' => 'Markdown'],
                'html' => ['ext' => '.html', 'label' => 'Web HTML'],
                'docx' => ['ext' => '.docx', 'label' => 'Word DOCX'],
            ]);

            $sheets[$baseName] = [
                'id' => $baseName,
                'code' => $code,
                'type' => $type,
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'source_md_path' => $mdPath,
                'downloads' => $downloads,
            ];
        }

        return $sheets;
    }

    private function discoverReferentiel(string $root): array
    {
        $docs = [];

        if (is_file($root . '/GUIDE_PARCOURS.md')) {
            $meta = $this->extractMarkdownSummary($root . '/GUIDE_PARCOURS.md', 'GUIDE_PARCOURS');
            $docs['GUIDE_PARCOURS'] = [
                'id' => 'GUIDE_PARCOURS',
                'category' => 'Orientation & Parcours',
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'source_md_path' => $root . '/GUIDE_PARCOURS.md',
                'downloads' => $this->collectDownloads($root . '/GUIDE_PARCOURS', [
                    'pdf' => ['ext' => '.pdf', 'label' => 'PDF'],
                    'md' => ['ext' => '.md', 'label' => 'Markdown'],
                    'html' => ['ext' => '.html', 'label' => 'HTML'],
                    'docx' => ['ext' => '.docx', 'label' => 'DOCX'],
                ]),
            ];
        }

        $refDir = $root . '/01_programme_et_referentiel';
        if (is_dir($refDir)) {
            $files = array_merge(
                glob($refDir . '/*.md') ?: [],
                glob($refDir . '/syllabus/*.md') ?: []
            );
            natsort($files);
            foreach ($files as $mdPath) {
                $baseName = pathinfo($mdPath, PATHINFO_FILENAME);
                $subDir = dirname($mdPath);
                $meta = $this->extractMarkdownSummary($mdPath, $baseName);
                $docs[$baseName] = [
                    'id' => $baseName,
                    'category' => basename($subDir) === 'syllabus' ? 'Syllabus & Planning' : 'Qualiopi & Programmes',
                    'title' => $meta['title'],
                    'summary' => $meta['summary'],
                    'source_md_path' => $mdPath,
                    'downloads' => $this->collectDownloads($subDir . '/' . $baseName, [
                        'pdf' => ['ext' => '.pdf', 'label' => 'PDF'],
                        'md' => ['ext' => '.md', 'label' => 'Markdown'],
                        'html' => ['ext' => '.html', 'label' => 'HTML'],
                        'docx' => ['ext' => '.docx', 'label' => 'DOCX'],
                    ]),
                ];
            }
        }

        $hbDir = $root . '/04_ateliers_et_tp/student_handbook';
        if (is_dir($hbDir)) {
            foreach (glob($hbDir . '/*.md') ?: [] as $mdPath) {
                $baseName = pathinfo($mdPath, PATHINFO_FILENAME);
                $meta = $this->extractMarkdownSummary($mdPath, $baseName);
                $docs[$baseName] = [
                    'id' => $baseName,
                    'category' => 'Livret Stagiaire',
                    'title' => $meta['title'],
                    'summary' => $meta['summary'],
                    'source_md_path' => $mdPath,
                    'downloads' => $this->collectDownloads($hbDir . '/' . $baseName, [
                        'pdf' => ['ext' => '.pdf', 'label' => 'PDF'],
                        'md' => ['ext' => '.md', 'label' => 'Markdown'],
                        'html' => ['ext' => '.html', 'label' => 'HTML'],
                        'docx' => ['ext' => '.docx', 'label' => 'DOCX'],
                    ]),
                ];
            }
        }

        $livretPath = $root . '/04_ateliers_et_tp/livret_exercices_complet.md';
        if (is_file($livretPath)) {
            $meta = $this->extractMarkdownSummary($livretPath, 'livret_exercices_complet');
            $docs['livret_exercices_complet'] = [
                'id' => 'livret_exercices_complet',
                'category' => 'Livret Stagiaire',
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'source_md_path' => $livretPath,
                'downloads' => $this->collectDownloads($root . '/04_ateliers_et_tp/livret_exercices_complet', [
                    'pdf' => ['ext' => '.pdf', 'label' => 'PDF'],
                    'md' => ['ext' => '.md', 'label' => 'Markdown'],
                    'html' => ['ext' => '.html', 'label' => 'HTML'],
                    'docx' => ['ext' => '.docx', 'label' => 'DOCX'],
                ]),
            ];
        }

        return $docs;
    }

    private function discoverTools(string $pgScriptsRoot): array
    {
        if ($pgScriptsRoot === '' || !is_dir($pgScriptsRoot)) {
            return [];
        }

        $tools = [];
        $files = array_merge(
            glob($pgScriptsRoot . '/pg_*.py') ?: [],
            glob($pgScriptsRoot . '/sql/*.sql') ?: []
        );
        natsort($files);

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $id = pathinfo($filePath, PATHINFO_FILENAME) . '_' . $ext;

            $tools[$id] = [
                'id' => $id,
                'filename' => $fileName,
                'language' => $ext === 'py' ? 'Python 3' : 'SQL',
                'category' => $ext === 'py' ? 'Script Python DBA' : 'Diagnostic SQL Expert',
                'path' => $filePath,
                'size_formatted' => self::formatBytes((int)@filesize($filePath)),
            ];
        }

        return $tools;
    }

    private function extractItemCode(string $baseName, string $prefix): string
    {
        $stripped = str_starts_with($baseName, $prefix) ? substr($baseName, strlen($prefix)) : $baseName;
        if (preg_match('/^(sql_\d{2}|\d{2})/', $stripped, $m)) {
            return $m[1];
        }
        return substr($stripped, 0, 6);
    }

    private function extractMarkdownSummary(string $mdPath, string $fallbackTitle): array
    {
        $title = ucwords(str_replace('_', ' ', $fallbackTitle));
        $bloom = 'Bloom C1–C6';
        $duration = '1h30';
        $summary = '';

        $titleFound = false;
        $bloomFound = false;
        $awaitingNextBloomLine = false;
        $durationFound = false;
        $inCodeBlock = false;

        $fp = @fopen($mdPath, 'rb');
        if ($fp !== false) {
            $linesRead = 0;
            while (($line = fgets($fp)) !== false && $linesRead < 65) {
                $linesRead++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    $awaitingNextBloomLine = false;
                    continue;
                }
                if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
                    $inCodeBlock = !$inCodeBlock;
                    $awaitingNextBloomLine = false;
                    continue;
                }
                if ($inCodeBlock) {
                    continue;
                }
                if ($awaitingNextBloomLine) {
                    $bLine = trim(ltrim($trimmed, '> '));
                    if ($bLine !== '') {
                        $bloom = trim($bloom . ' — ' . strip_tags(str_replace(['**', '*', '`'], '', $bLine)));
                        $bloomFound = true;
                    }
                    $awaitingNextBloomLine = false;
                    continue;
                }
                if (!$titleFound && str_starts_with($trimmed, '# ')) {
                    $title = trim(str_replace(['**', '`'], '', substr($trimmed, 2)));
                    $titleFound = true;
                } elseif (!$durationFound && preg_match('/^\|\s*[^|]*Durée[^|]*\|\s*([^|]+)\|/iu', $trimmed, $m)) {
                    $duration = trim(strip_tags(str_replace(['**', '*', '`'], '', $m[1])));
                    $durationFound = true;
                } elseif (!$durationFound && preg_match('/^(?:>\s*|\*\s*)?[^:]*?\bDurée[^:]*:\*?\*?\s*(.+)$/iu', $trimmed, $m)) {
                    $duration = trim(strip_tags(str_replace(['**', '*', '`'], '', $m[1])));
                    $durationFound = true;
                } elseif (!$bloomFound && preg_match('/^\|\s*[^|]*(?:Compétence|Bloom)[^|]*\|\s*([^|]+)\|/iu', $trimmed, $m)) {
                    $bloom = trim(strip_tags(str_replace(['**', '*', '`'], '', $m[1])));
                    $bloomFound = true;
                } elseif (!$bloomFound && preg_match('/^(?:>\s*|\*\s*)?[^:]*?(?:Compétence[^:]*|Objectif\s+Pédagogique\s*\(([^)]+)\))\s*:\*?\*?\s*(.*)$/iu', $trimmed, $m)) {
                    $bloomTag = trim((string)($m[1] ?? ''));
                    $bloomDesc = trim(strip_tags(str_replace(['**', '*', '`'], '', (string)($m[2] ?? ''))));
                    if ($bloomDesc !== '') {
                        $bloom = $bloomTag !== '' ? ($bloomTag . ' — ' . $bloomDesc) : $bloomDesc;
                        $bloomFound = true;
                    } elseif ($bloomTag !== '') {
                        $bloom = $bloomTag;
                        $awaitingNextBloomLine = true;
                    }
                } elseif (
                    $summary === ''
                    && !str_starts_with($trimmed, '#')
                    && !str_starts_with($trimmed, '>')
                    && !str_starts_with($trimmed, '!')
                    && !str_starts_with($trimmed, '---')
                    && !str_starts_with($trimmed, '|')
                    && !str_starts_with($trimmed, '<!--')
                    && !preg_match('/^[\*_]\s*(?:Figure|Illustration|Schéma)\s*:/iu', $trimmed)
                    && strlen($trimmed) > 35
                ) {
                    $cleanLine = preg_replace('/^[\*\-\d\.]+\s+/', '', $trimmed) ?? $trimmed;
                    $cleanLine = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $cleanLine) ?? $cleanLine;
                    $summary = trim(strip_tags(str_replace(['**', '*', '`'], '', $cleanLine)));
                }
            }
            fclose($fp);
        }

        if ($summary === '') {
            $summary = $bloom;
        }

        return [
            'title' => $title,
            'bloom' => self::truncateUtf8($bloom, 110),
            'duration' => $duration,
            'summary' => self::truncateUtf8($summary, 180),
        ];
    }

    public static function truncateUtf8(string $text, int $maxChars): string
    {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $maxChars, '…', 'UTF-8');
        }
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        $cut = substr($text, 0, $maxChars);
        $cut = preg_replace('/[\x80-\xBF]+$/', '', $cut) ?? $cut;
        $cut = preg_replace('/[\xC0-\xFD]$/', '', $cut) ?? $cut;
        return rtrim($cut) . '…';
    }

    private function collectDownloads(string $basePathWithoutExt, array $spec): array
    {
        $downloads = [];
        foreach ($spec as $key => $info) {
            $candidate = $basePathWithoutExt . $info['ext'];
            if (is_file($candidate)) {
                $size = (int)@filesize($candidate);
                $downloads[$key] = [
                    'format' => $key,
                    'label' => $info['label'],
                    'ext' => ltrim($info['ext'], '_'),
                    'filename' => basename($candidate),
                    'path' => $candidate,
                    'size' => $size,
                    'size_formatted' => self::formatBytes($size),
                ];
            }
        }
        return $downloads;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 Ko';
        }
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
        }
        return number_format($bytes / 1048576, 2, ',', ' ') . ' Mo';
    }
}
