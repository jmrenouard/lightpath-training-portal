<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

use LightPath\LMS\Core\Security;

class MarkdownParser
{
    public function parseModuleFile(string $mdPath, string $courseSlug = 'postgresql-18'): array
    {
        if (!is_file($mdPath)) {
            return ['html' => '', 'toc' => [], 'quiz' => []];
        }
        $raw = (string)file_get_contents($mdPath);
        $quiz = $this->extractQuizQuestions($raw);
        $rendered = $this->renderMarkdown($raw, $courseSlug, false);

        return [
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'quiz' => $quiz,
        ];
    }

    public function parseLabFile(string $mdPath, string $courseSlug = 'postgresql-18'): array
    {
        if (!is_file($mdPath)) {
            return ['html' => '', 'toc' => [], 'sql_snippets' => []];
        }
        $raw = (string)file_get_contents($mdPath);
        $sqlSnippets = $this->extractSqlSnippets($raw);
        $rendered = $this->renderMarkdown($raw, $courseSlug, true);

        return [
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'sql_snippets' => $sqlSnippets,
        ];
    }

    public function extractQuizQuestions(string $markdown): array
    {
        $questions = [];
        if (preg_match_all('/<details>\s*<summary>(.*?)<\/summary>(.*?)<\/details>/isu', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $idx => $m) {
                $qTitle = trim(strip_tags($m[1]));
                $body = $m[2];

                $options = [];
                if (preg_match_all('/^\s*[\*\-]\s*\*\*([A-D])\.?\*\*\s*(.+)$/imu', $body, $optMatches, PREG_SET_ORDER)) {
                    foreach ($optMatches as $om) {
                        $letter = strtoupper(trim($om[1]));
                        $options[$letter] = trim(str_replace(['**', '`'], ['', ''], $om[2]));
                    }
                }

                $correct = 'A';
                if (preg_match('/Réponse\s+correcte\s*:\s*([A-D])/iu', $body, $cm)) {
                    $correct = strtoupper($cm[1]);
                }

                $explanation = '';
                if (preg_match('/Justification[^:]*:\*?\*?\s*(.+)$/imu', $body, $em)) {
                    $explanation = trim(str_replace(['**'], [''], $em[1]));
                }

                if (!empty($options)) {
                    $questions[] = [
                        'index' => $idx + 1,
                        'question' => $qTitle,
                        'options' => $options,
                        'correct' => $correct,
                        'explanation' => $explanation,
                    ];
                }
            }
        }
        return $questions;
    }

    public function extractSqlSnippets(string $markdown): array
    {
        $snippets = [];
        if (preg_match_all('/```sql\s*(.*?)```/isu', $markdown, $matches)) {
            foreach ($matches[1] as $idx => $code) {
                $clean = trim($code);
                if ($clean !== '') {
                    $snippets[] = [
                        'id' => 'sql_step_' . ($idx + 1),
                        'label' => 'Bloc SQL #' . ($idx + 1),
                        'sql' => $clean,
                    ];
                }
            }
        }
        return $snippets;
    }

    public function renderMarkdown(string $markdown, string $courseSlug = 'postgresql-18', bool $isLab = false): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $toc = [];

        $inCodeBlock = false;
        $codeLang = '';
        $codeBuffer = [];
        $inTable = false;
        $tableRows = [];
        $inList = false;
        $inBlockquote = false;
        $blockquoteLines = [];
        $sqlCounter = 0;

        $flushList = static function () use (&$inList, &$html): void {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
        };

        $flushBlockquote = function () use (&$inBlockquote, &$blockquoteLines, &$html): void {
            if (!$inBlockquote) {
                return;
            }
            $inBlockquote = false;
            $first = $blockquoteLines[0] ?? '';
            $alertType = 'note';
            $alertTitle = 'Note Technique';

            if (preg_match('/^\[!(IMPORTANT|WARNING|CAUTION|TIP|NOTE)\]\s*(.*)$/i', $first, $am)) {
                $alertType = strtolower($am[1]);
                $customTitle = trim($am[2]);
                $defaultTitles = [
                    'important' => 'Important — Exigence Critique',
                    'warning' => 'Attention — Point de Vigilance',
                    'caution' => 'Précaution Majeure en Production',
                    'tip' => 'Astuce & Bonne Pratique DBA',
                    'note' => 'Information Technique',
                ];
                $alertTitle = $customTitle !== '' ? $this->formatInline($customTitle) : ($defaultTitles[$alertType] ?? 'Note');
                array_shift($blockquoteLines);
            }

            $bodyHtml = implode('<br/>', array_map(fn(string $l): string => $this->formatInline($l), $blockquoteLines));
            $html[] = '<div class="lms-callout lms-callout-' . $alertType . '">';
            $html[] = '  <div class="lms-callout-title">' . $alertTitle . '</div>';
            $html[] = '  <div class="lms-callout-body">' . $bodyHtml . '</div>';
            $html[] = '</div>';
            $blockquoteLines = [];
        };

        $flushTable = function () use (&$inTable, &$tableRows, &$html): void {
            if (!$inTable) {
                return;
            }
            $inTable = false;
            if (count($tableRows) < 2) {
                $tableRows = [];
                return;
            }
            $html[] = '<div class="lms-table-wrap"><table class="lms-table">';
            $headers = $this->splitTableRow($tableRows[0]);
            $html[] = '<thead><tr>';
            foreach ($headers as $h) {
                $html[] = '<th>' . $this->formatInline($h) . '</th>';
            }
            $html[] = '</tr></thead><tbody>';

            for ($i = 1, $n = count($tableRows); $i < $n; $i++) {
                $rowStr = trim($tableRows[$i]);
                if (preg_match('/^\|?[\s\-\|:]+\|?$/', $rowStr)) {
                    continue;
                }
                $cells = $this->splitTableRow($rowStr);
                $html[] = '<tr>';
                foreach ($cells as $c) {
                    $html[] = '<td>' . $this->formatInline($c) . '</td>';
                }
                $html[] = '</tr>';
            }
            $html[] = '</tbody></table></div>';
            $tableRows = [];
        };

        foreach ($lines as $line) {
            // Gestion des blocs de code ```
            if (str_starts_with(trim($line), '```')) {
                $flushList();
                $flushBlockquote();
                $flushTable();

                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $codeLang = strtolower(trim(substr(trim($line), 3)));
                    $codeBuffer = [];
                } else {
                    $inCodeBlock = false;
                    $rawCode = implode("\n", $codeBuffer);
                    if ($codeLang === 'mermaid') {
                        $html[] = '<div class="lms-mermaid-card">'
                            . '<div class="lms-mermaid-header"><span>📐 Schéma d\'Architecture & Flux Technique</span><span class="badge-mono">Mermaid</span></div>'
                            . '<pre class="lms-mermaid-code">' . Security::e($rawCode) . '</pre>'
                            . '</div>';
                    } else {
                        $langLabel = $codeLang !== '' ? strtoupper($codeLang) : 'CODE';
                        $runBtn = '';
                        if ($codeLang === 'sql') {
                            $sqlCounter++;
                            $b64 = base64_encode(trim($rawCode));
                            $runBtn = '<button type="button" class="btn-code-action btn-run-sql" data-sql-b64="' . $b64 . '" title="Charger et exécuter dans la Console SQL Docker">▶ Exécuter dans le Lab SQL</button>';
                        }
                        $html[] = '<div class="lms-code-block">'
                            . '<div class="lms-code-toolbar">'
                            . '<span class="lms-code-lang">' . Security::e($langLabel) . '</span>'
                            . '<div class="lms-code-actions">'
                            . $runBtn
                            . '<button type="button" class="btn-code-action btn-copy-code">📋 Copier</button>'
                            . '</div>'
                            . '</div>'
                            . '<pre><code class="lang-' . Security::e($codeLang) . '">' . Security::e($rawCode) . '</code></pre>'
                            . '</div>';
                    }
                    $codeLang = '';
                    $codeBuffer = [];
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeBuffer[] = $line;
                continue;
            }

            $trimmed = trim($line);

            // Commentaires HTML ignorés
            if (str_starts_with($trimmed, '<!--') && str_ends_with($trimmed, '-->')) {
                continue;
            }

            // Tableaux Markdown
            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
                $flushList();
                $flushBlockquote();
                $inTable = true;
                $tableRows[] = $trimmed;
                continue;
            } elseif ($inTable) {
                $flushTable();
            }

            // Blockquotes / Callouts
            if (str_starts_with($trimmed, '>')) {
                $flushList();
                $inBlockquote = true;
                $blockquoteLines[] = ltrim(substr($trimmed, 1));
                continue;
            } elseif ($inBlockquote) {
                $flushBlockquote();
            }

            // Ligne vide
            if ($trimmed === '') {
                $flushList();
                continue;
            }

            // Séparateur horizontal
            if ($trimmed === '---' || $trimmed === '***') {
                $flushList();
                $html[] = '<hr class="lms-divider"/>';
                continue;
            }

            // Balises HTML natives (<details>, <summary>, </details>)
            if (preg_match('/^<\/?(details|summary)/i', $trimmed)) {
                $flushList();
                $html[] = $line;
                continue;
            }

            // Titres H1..H4
            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $hm)) {
                $flushList();
                $level = strlen($hm[1]);
                $titleText = trim($hm[2]);
                $cleanTitle = strip_tags($titleText);
                $slugId = 'sec-' . substr(md5($cleanTitle . count($toc)), 0, 8);
                if ($level === 2 || $level === 3) {
                    $toc[] = [
                        'level' => $level,
                        'id' => $slugId,
                        'title' => $cleanTitle,
                    ];
                }
                $html[] = "<h{$level} id=\"{$slugId}\" class=\"lms-heading lms-h{$level}\">" . $this->formatInline($titleText, $courseSlug) . "</h{$level}>";
                continue;
            }

            // Image autonome ![alt](src)
            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', $trimmed, $im)) {
                $flushList();
                $alt = Security::e($im[1]);
                $src = $this->resolveAssetUrl($im[2], $courseSlug);
                $html[] = '<figure class="lms-figure"><img src="' . Security::e($src) . '" alt="' . $alt . '" loading="lazy"/><figcaption>' . $alt . '</figcaption></figure>';
                continue;
            }

            // Liste à puces ou checklist
            if (preg_match('/^([\*\-]|\d+\.)\s+(.+)$/', $trimmed, $lm)) {
                if (!$inList) {
                    $html[] = '<ul class="lms-list">';
                    $inList = true;
                }
                $itemContent = $lm[2];
                if (str_starts_with($itemContent, '[ ] ')) {
                    $itemContent = '<input type="checkbox" class="lms-checkbox"/> ' . substr($itemContent, 4);
                    $html[] = '<li class="lms-checklist-item">' . $this->formatInline($itemContent, $courseSlug, true) . '</li>';
                } elseif (str_starts_with($itemContent, '[x] ') || str_starts_with($itemContent, '[X] ')) {
                    $itemContent = '<input type="checkbox" class="lms-checkbox" checked/> ' . substr($itemContent, 4);
                    $html[] = '<li class="lms-checklist-item">' . $this->formatInline($itemContent, $courseSlug, true) . '</li>';
                } else {
                    $html[] = '<li>' . $this->formatInline($itemContent, $courseSlug) . '</li>';
                }
                continue;
            }

            $flushList();
            $html[] = '<p class="lms-p">' . $this->formatInline($trimmed, $courseSlug) . '</p>';
        }

        $flushList();
        $flushBlockquote();
        $flushTable();

        return [
            'html' => implode("\n", $html),
            'toc' => $toc,
        ];
    }

    private function splitTableRow(string $row): array
    {
        $trimmed = trim($row, '| ');
        $parts = explode('|', $trimmed);
        return array_map('trim', $parts);
    }

    private function resolveAssetUrl(string $rawPath, string $courseSlug): string
    {
        $clean = str_replace('\\', '/', trim($rawPath));
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }
        $clean = preg_replace('#^(\.\./)+#', '', $clean) ?? $clean;
        return '?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($clean);
    }

    private function formatInline(string $text, string $courseSlug = 'postgresql-18', bool $allowRawPrefix = false): string
    {
        $prefix = '';
        if ($allowRawPrefix && preg_match('/^(<input[^>]+>\s*)(.*)$/', $text, $pm)) {
            $prefix = $pm[1];
            $text = $pm[2];
        }

        // Protéger les blocs inline `code`
        $codePlaceholders = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codePlaceholders): string {
            $key = '%%CODE_' . count($codePlaceholders) . '%%';
            $codePlaceholders[$key] = '<code class="lms-inline-code">' . Security::e($m[1]) . '</code>';
            return $key;
        }, $text) ?? $text;

        $escaped = Security::e($text);

        // Restaurer quelques balises inline sûres (<br/>, <b>, <i>)
        $escaped = str_ireplace(['&lt;br/&gt;', '&lt;br&gt;', '&lt;b&gt;', '&lt;/b&gt;', '&lt;i&gt;', '&lt;/i&gt;'], ['<br/>', '<br/>', '<strong>', '</strong>', '<em>', '</em>'], $escaped);

        // Liens Markdown [label](url)
        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function (array $m) use ($courseSlug): string {
            $label = $m[1];
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (str_contains($href, 'cheatsheet_') || str_ends_with($href, '.pdf') || str_ends_with($href, '.html') || str_ends_with($href, '.md')) {
                $clean = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $href)) ?? $href;
                $url = '?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($clean);
                return '<a href="' . Security::e($url) . '" class="lms-link" target="_blank">' . $label . '</a>';
            }
            return '<a href="' . Security::e($href) . '" class="lms-link" target="_blank" rel="noopener">' . $label . '</a>';
        }, $escaped) ?? $escaped;

        // Gras et italique
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;

        foreach ($codePlaceholders as $key => $replacement) {
            $escaped = str_replace($key, $replacement, $escaped);
        }

        return $prefix . $escaped;
    }
}
