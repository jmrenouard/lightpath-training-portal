<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

class CourseRepository
{
    private array $coursesConfig;
    private array $cache = [];

    public function __construct(array $coursesConfig, array $customCourses = [])
    {
        $this->coursesConfig = array_merge($coursesConfig, $customCourses);
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

        $modules = $this->discoverModules($root, $cfg['tracks'] ?? []);
        $labs = $this->discoverLabs($root);
        $cheatsheets = $this->discoverCheatsheets($root);
        $referentiel = $this->discoverReferentiel($root);
        $tools = $this->discoverTools((string)($cfg['pgscripts_root'] ?? ''));

        // Lier chaque module à son Lab et sa Cheatsheet correspondants
        foreach ($modules as $modId => &$mod) {
            $code = $mod['code'];
            foreach ($labs as $labId => $lab) {
                if ($lab['code'] === $code) {
                    $mod['linked_lab_id'] = $labId;
                    $labs[$labId]['linked_module_id'] = $modId;
                    break;
                }
            }
            foreach ($cheatsheets as $csId => $cs) {
                if ($cs['type'] === 'module' && $cs['code'] === $code) {
                    $mod['linked_cheatsheet_id'] = $csId;
                    break;
                }
            }
        }
        unset($mod);

        $course = array_merge($cfg, [
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
        ]);

        $this->cache[$slug] = $course;
        return $course;
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
            foreach ($tracks as $tKey => $tCfg) {
                if (in_array($code, $tCfg['module_Filter'] ?? [], true)) {
                    $trackId = (string)$tKey;
                    $trackTitle = (string)$tCfg['title'];
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
                'source_md_path' => $mdPath,
                'source_html_path' => is_file($dir . '/' . $baseName . '.html') ? ($dir . '/' . $baseName . '.html') : null,
                'downloads' => $downloads,
                'linked_lab_id' => null,
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
                'source_md_path' => $mdPath,
                'source_html_path' => is_file($dir . '/' . $baseName . '.html') ? ($dir . '/' . $baseName . '.html') : null,
                'downloads' => $downloads,
                'linked_module_id' => null,
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

        // 1. GUIDE_PARCOURS à la racine
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

        // 2. Documents Qualiopi dans 01_programme_et_referentiel
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

        // 3. Student Handbook dans 04_ateliers_et_tp/student_handbook
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

        $fp = @fopen($mdPath, 'rb');
        if ($fp !== false) {
            $linesRead = 0;
            while (($line = fgets($fp)) !== false && $linesRead < 45) {
                $linesRead++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                if (str_starts_with($trimmed, '# ')) {
                    $title = trim(substr($trimmed, 2));
                } elseif (preg_match('/Compétence[^:]*:\*?\*?\s*(.+)$/iu', $trimmed, $m)) {
                    $bloom = trim(strip_tags(str_replace(['**', '*'], '', $m[1])));
                } elseif (preg_match('/Durée[^:]*:\*?\*?\s*(.+)$/iu', $trimmed, $m)) {
                    $duration = trim(strip_tags(str_replace(['**', '*'], '', $m[1])));
                } elseif ($summary === '' && !str_starts_with($trimmed, '#') && !str_starts_with($trimmed, '>') && !str_starts_with($trimmed, '!') && !str_starts_with($trimmed, '---') && !str_starts_with($trimmed, '|') && strlen($trimmed) > 40) {
                    $summary = strip_tags(str_replace(['**', '*', '`'], '', $trimmed));
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
        // Éviter de couper au milieu d'une séquence multi-octets UTF-8
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
