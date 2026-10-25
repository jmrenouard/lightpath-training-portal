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
            'path' => $candidate,
            'filename' => basename($candidate),
            'mime' => self::MIME_TYPES[$ext] ?? 'application/octet-stream',
            'size' => (int)filesize($candidate),
            'inline' => in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'html', 'pdf'], true),
        ];
    }

    public function streamFile(array $fileInfo, bool $forceDownload = true): never
    {
        $disp = ($forceDownload && empty($fileInfo['inline'])) ? 'attachment' : 'inline';
        header('Content-Type: ' . $fileInfo['mime']);
        header('Content-Disposition: ' . $disp . '; filename="' . addslashes($fileInfo['filename']) . '"');
        header('Content-Length: ' . $fileInfo['size']);
        header('X-Content-Type-Options: nosniff');
        if ($disp === 'inline') {
            header('Cache-Control: private, max-age=3600');
        }
        readfile($fileInfo['path']);
        exit;
    }
}
