<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

use LightPath\LMS\Core\Security;

class DownloadController
{
    private CourseRepository $repo;

    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'md' => 'text/markdown; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'sql' => 'application/sql; charset=UTF-8',
        'py' => 'text/x-python; charset=UTF-8',
        'sh' => 'text/x-shellscript; charset=UTF-8',
        'yml' => 'text/yaml; charset=UTF-8',
        'yaml' => 'text/yaml; charset=UTF-8',
        'php' => 'text/plain; charset=UTF-8',
        'go' => 'text/plain; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'ico' => 'image/x-icon',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(CourseRepository $repo)
    {
        $this->repo = $repo;
    }

    public function resolveDownloadPath(string $courseSlug, string $itemType, string $itemId, string $format): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $courseSlug) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $itemId)) {
            return null;
        }

        $item = match ($itemType) {
            'module' => $this->repo->getModule($courseSlug, $itemId),
            'lab' => $this->repo->getLab($courseSlug, $itemId),
            'cheatsheet' => $this->repo->getCheatsheet($courseSlug, $itemId),
            'referentiel' => $this->repo->getReferentielDoc($courseSlug, $itemId),
            'tool' => $this->repo->getToolDoc($courseSlug, $itemId),
            default => null,
        };

        if ($item === null) {
            return null;
        }

        if ($itemType === 'tool') {
            $path = (string)($item['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return null;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return [
                'course' => $courseSlug,
                'path' => $path,
                'filename' => basename($path),
                'mime' => self::MIME_TYPES[$ext] ?? 'application/octet-stream',
                'size' => (int)filesize($path),
            ];
        }

        $dl = $item['downloads'][$format] ?? null;
        if ($dl === null || empty($dl['path']) || !is_file($dl['path'])) {
            return null;
        }

        $ext = strtolower(pathinfo($dl['path'], PATHINFO_EXTENSION));
        return [
            'course' => $courseSlug,
            'path' => $dl['path'],
            'filename' => $dl['filename'],
            'mime' => self::MIME_TYPES[$ext] ?? 'application/octet-stream',
            'size' => (int)filesize($dl['path']),
        ];
    }

    public function resolveRelativeCourseAsset(string $courseSlug, string $relativePath): ?array
    {
        if (!Security::isSafeRelativePath($relativePath)) {
            return null;
        }
        $course = $this->repo->getCourse($courseSlug);
        if ($course === null) {
            return null;
        }

        $root = realpath((string)$course['source_root']);
        if ($root === false) {
            return null;
        }

        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($candidate === false || !str_starts_with($candidate, $root) || !is_file($candidate)) {
            return null;
        }

        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        return [
            'course' => $courseSlug,
            'path' => $candidate,
            'filename' => basename($candidate),
            'mime' => self::MIME_TYPES[$ext] ?? 'application/octet-stream',
            'size' => (int)filesize($candidate),
            'inline' => in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'ico', 'html', 'pdf', 'md', 'yml', 'yaml', 'py', 'sql', 'sh', 'php', 'go', 'txt'], true),
        ];
    }

    public function streamFile(array $fileInfo, bool $forceDownload = true): never
    {
        $disp = ($forceDownload && empty($fileInfo['inline'])) ? 'attachment' : 'inline';
        $safeFilename = Security::sanitizeFilename((string)$fileInfo['filename']);
        Security::sendSecurityHeaders();
        header('Content-Type: ' . $fileInfo['mime']);
        header('Content-Disposition: ' . $disp . '; filename="' . $safeFilename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Permissions-Policy: unload=*, fullscreen=(self)');

        if ($disp === 'inline') {
            header('Cache-Control: public, max-age=86400');
            // Si on affiche un livrable HTML en mode inline (Rendu Web Studio), réécrire les chemins relatifs ../assets/ vers assets/
            // et les liens relatifs documentaires vers index.php?action=course_asset, puis injecter le shim unload -> pagehide
            if (str_starts_with((string)$fileInfo['mime'], 'text/html')) {
                $html = (string)file_get_contents($fileInfo['path']);
                $html = (string)preg_replace('/(?:\.\.\/)+assets\//', 'assets/', $html);
                $courseSlug = (string)($fileInfo['course'] ?? 'postgresql-18-mastery');
                $course = $this->repo->getCourse($courseSlug);
                $root = $course !== null ? realpath((string)$course['source_root']) : false;
                $fileDir = dirname((string)$fileInfo['path']);
                if ($root !== false && is_dir($fileDir)) {
                    $html = (string)preg_replace_callback(
                        '/\b(href|src)="([^"#?]+)(#[^"]*)?"/i',
                        static function (array $m) use ($root, $fileDir, $courseSlug): string {
                            $attr = $m[1];
                            $relUrl = $m[2];
                            $anchor = $m[3] ?? '';
                            if (preg_match('/^(https?:\/\/|mailto:|data:|javascript:|index\.php|\?|\/|assets\/)/i', $relUrl)) {
                                return $m[0];
                            }
                            $resolved = realpath($fileDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relUrl));
                            if ($resolved !== false && str_starts_with($resolved, $root) && is_file($resolved)) {
                                $relToRoot = ltrim(str_replace('\\', '/', substr($resolved, strlen($root))), '/');
                                return $attr . '="index.php?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($relToRoot) . $anchor . '"';
                            }
                            return $m[0];
                        },
                        $html
                    );
                }
                $shim = Security::renderUnloadShimScript();
                if (stripos($html, '<head>') !== false) {
                    $html = (string)preg_replace('/<head>/i', '<head>' . $shim, $html, 1);
                } else {
                    $html = $shim . $html;
                }
                header('Content-Length: ' . strlen($html));
                echo $html;
                exit;
            }
        }

        header('Content-Length: ' . $fileInfo['size']);
        readfile($fileInfo['path']);
        exit;
    }
}
