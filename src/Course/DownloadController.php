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
        'mp4' => 'video/mp4',
        'vtt' => 'text/vtt; charset=UTF-8',
        'zip' => 'application/zip',
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
            'example' => $this->repo->getExampleDoc($courseSlug, $itemId),
            default => null,
        };

        if ($item === null) {
            return null;
        }

        if ($itemType === 'tool' || $itemType === 'example') {
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
        if ($candidate === false || !Security::isPathInside($candidate, $root) || !is_file($candidate)) {
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
                $courseSlug = (string)($fileInfo['course'] ?? 'postgresql-18');
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
                            if ($resolved !== false && Security::isPathInside($resolved, $root) && is_file($resolved)) {
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

        if (($fileInfo['mime'] ?? '') === 'video/mp4') {
            header('Accept-Ranges: bytes');
            $size = (int)$fileInfo['size'];
            $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
            if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/i', (string)$rangeHeader, $m)) {
                $start = (int)$m[1];
                $end = ($m[2] !== '') ? min($size - 1, (int)$m[2]) : ($size - 1);
                if ($start <= $end && $start < $size) {
                    $length = $end - $start + 1;
                    header('HTTP/1.1 206 Partial Content');
                    header("Content-Range: bytes {$start}-{$end}/{$size}");
                    header('Content-Length: ' . $length);
                    $fp = fopen($fileInfo['path'], 'rb');
                    if ($fp !== false) {
                        fseek($fp, $start);
                        $remaining = $length;
                        $chunkSize = 64 * 1024; // 64 KB
                        while ($remaining > 0 && !feof($fp)) {
                            $readSize = min($remaining, $chunkSize);
                            $buffer = fread($fp, $readSize);
                            if ($buffer === false || $buffer === '') {
                                break;
                            }
                            echo $buffer;
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                            $remaining -= strlen($buffer);
                        }
                        fclose($fp);
                    }
                    exit;
                }
            }
        }

        header('Content-Length: ' . $fileInfo['size']);
        readfile($fileInfo['path']);
        exit;
    }

    /**
     * Génère un package d'archive SCORM 1.2 complet (.zip) autonome contenant :
     * - imsmanifest.xml (conforme ADL SCORM 1.2)
     * - index_scorm.html (lanceur SCO interactif)
     * - scorm_api.js (adaptateur API SCORM 1.2 LMSInitialize / LMSSetValue / LMSFinish)
     * - README_SCORM.md (documentation d'intégration LMS tiers : Moodle, Cornerstone, 360Learning)
     */
    public function buildScorm12ZipPackage(string $courseSlug, array $course): ?string
    {
        $itemsXml = '';
        $resXml = '';
        $modListHtml = '';
        foreach (($course['modules'] ?? []) as $mId => $mod) {
            $safeId = Security::e($mId);
            $safeTitle = Security::e(($mod['badge_code'] ?? '') . ' — ' . ($mod['title'] ?? $mId));
            $itemsXml .= "      <item identifier=\"ITEM_{$safeId}\" identifierref=\"RES_{$safeId}\"><title>{$safeTitle}</title></item>\n";
            $resXml .= "    <resource identifier=\"RES_{$safeId}\" type=\"webcontent\" adlcp:scormtype=\"sco\" href=\"index_scorm.html?module={$safeId}\">\n      <file href=\"index_scorm.html\"/>\n      <file href=\"scorm_api.js\"/>\n    </resource>\n";
            $modListHtml .= "<li><strong>{$safeTitle}</strong> — " . Security::e((string)($mod['bloom'] ?? '')) . "</li>\n";
        }

        $courseTitle = Security::e((string)($course['title'] ?? 'PostgreSQL 18'));
        $manifest = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="LP_PG18_SCORM12" version="1.2"
          xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
          xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <organizations default="LIGHTPATH_ORG">
    <organization identifier="LIGHTPATH_ORG">
      <title>{$courseTitle}</title>
{$itemsXml}    </organization>
  </organizations>
  <resources>
{$resXml}  </resources>
</manifest>
XML;

        $scormJs = <<<'JS'
(function () {
    function findAPI(win) {
        var tries = 0;
        while ((win.API == null) && (win.parent != null) && (win.parent != win)) {
            tries++;
            if (tries > 10) return null;
            win = win.parent;
        }
        return win.API || null;
    }
    var api = findAPI(window) || (window.opener ? findAPI(window.opener) : null);
    window.LightPathScorm = {
        init: function () {
            if (api && typeof api.LMSInitialize === 'function') {
                api.LMSInitialize('');
                api.LMSSetValue('cmi.core.lesson_status', 'incomplete');
                api.LMSCommit('');
            }
        },
        complete: function (score) {
            if (api && typeof api.LMSSetValue === 'function') {
                api.LMSSetValue('cmi.core.score.raw', String(score || 100));
                api.LMSSetValue('cmi.core.lesson_status', 'completed');
                api.LMSCommit('');
                api.LMSFinish('');
            }
            var st = document.getElementById('scorm-status');
            if (st) st.textContent = 'Statut SCORM 1.2 synchronisé : COMPLETED (100%)';
        }
    };
    window.addEventListener('DOMContentLoaded', function () { window.LightPathScorm.init(); });
})();
JS;

        $indexHtml = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"/>
<title>{$courseTitle} — Lanceur SCORM 1.2 LightPath SARL</title>
<script src="scorm_api.js"></script>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#0B1120;color:#F8FAFC;margin:0;padding:2rem;line-height:1.6;}
.card{max-width:900px;margin:0 auto;background:#0F172A;border:1px solid #1E293B;border-radius:12px;padding:2rem;}
.badge{display:inline-block;background:rgba(0,176,240,0.15);color:#38BDF8;padding:0.25rem 0.65rem;border-radius:6px;font-size:0.8rem;font-weight:700;}
.btn{background:#00B0F0;color:#0B1120;border:none;padding:0.65rem 1.25rem;border-radius:8px;font-weight:700;cursor:pointer;}
</style>
</head>
<body>
<div class="card">
  <span class="badge">PACKAGE SCORM 1.2 CERTIFIÉ QUALIOPI • LIGHTPATH SARL</span>
  <h1>{$courseTitle}</h1>
  <p>Formateur Référent : <strong>Jean-Marie Renouard</strong> (LightPath SARL — contact@lightpath.fr)</p>
  <p id="scorm-status">Statut SCORM 1.2 : Initialisé (en cours)</p>
  <button type="button" class="btn" onclick="window.LightPathScorm.complete(100)">✔ Valider la Complétion SCORM 1.2</button>
  <h2>Modules du Cursus (34 SCOs)</h2>
  <ul>{$modListHtml}</ul>
</div>
</body>
</html>
HTML;

        $readmeMd = "# Package SCORM 1.2 — {$courseTitle}\n\n"
            . "- **Organisme** : LightPath SARL (Certifié Qualiopi)\n"
            . "- **Formateur** : Jean-Marie Renouard (`contact@lightpath.fr`)\n"
            . "- **Standard** : ADL SCORM 1.2 (`imsmanifest.xml` + API Wrapper `scorm_api.js`)\n"
            . "- **Nombre de modules (SCO)** : " . count($course['modules'] ?? []) . "\n";

        $files = [
            'imsmanifest.xml' => $manifest,
            'index_scorm.html' => $indexHtml,
            'scorm_api.js' => $scormJs,
            'README_SCORM.md' => $readmeMd,
        ];

        $zipPath = sys_get_temp_dir() . '/scorm12_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $courseSlug) . '_' . getmypid() . '.zip';
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                foreach ($files as $name => $content) {
                    $zip->addFromString($name, $content);
                }
                $zip->close();
                return $zipPath;
            }
        }

        // Fallback ZIP pur PHP (format PKZIP Store standard sans compression) si l'extension php_zip est absente
        $localParts = [];
        $centralParts = [];
        $offset = 0;
        foreach ($files as $name => $data) {
            $len = strlen($data);
            $crc = crc32($data);
            $nameLen = strlen($name);
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0) . $name;
            $localEntry = $localHeader . $data;
            $localParts[] = $localEntry;
            $centralParts[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0, 0, 0, 0, 32, $offset) . $name;
            $offset += strlen($localEntry);
        }
        $centralDir = implode('', $centralParts);
        $count = count($files);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($centralDir), $offset, 0);
        file_put_contents($zipPath, implode('', $localParts) . $centralDir . $eocd);
        return is_file($zipPath) ? $zipPath : null;
    }
}
