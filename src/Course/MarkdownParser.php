<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

use LightPath\LMS\Core\Security;

class MarkdownParser
{
    private string $sourceRoot;
    private string $cacheDir;

    public function __construct(?string $sourceRoot = null, ?string $cacheDir = null)
    {
        $defaultRoot = dirname(__DIR__, 3) . '/training_postgresql';
        $this->sourceRoot = $sourceRoot ?? (realpath($defaultRoot) ?: $defaultRoot);
        $this->cacheDir = $cacheDir ?? (dirname(__DIR__, 2) . '/storage/data/parsed_cache');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public function parseModuleFile(string $mdPath, string $courseSlug = 'postgresql-18'): array
    {
        if (!is_file($mdPath)) {
            return ['html' => '', 'toc' => [], 'quiz' => [], 'slides' => []];
        }
        $mtime = (int)@filemtime($mdPath);
        $moduleId = pathinfo($mdPath, PATHINFO_FILENAME);
        $manifestPath = dirname(__DIR__, 2) . '/public/assets/slides_cache/' . $moduleId . '/manifest.json';
        $manifestMtime = is_file($manifestPath) ? (int)@filemtime($manifestPath) : 0;
        $cacheKey = md5('mod_v6_rca146:' . $courseSlug . ':' . $mdPath . ':' . $mtime . ':' . $manifestMtime);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile)) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['html'], $cached['slides'])) {
                return $cached;
            }
        }

        $raw = (string)file_get_contents($mdPath);
        $quiz = $this->extractQuizQuestions($raw);
        $rendered = $this->renderMarkdown($raw, $courseSlug, false);
        $slides = $this->extractSlides($raw, $courseSlug, $quiz, $moduleId);

        $result = [
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'quiz' => $quiz,
            'slides' => $slides,
        ];
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    public function parseLabFile(string $mdPath, string $courseSlug = 'postgresql-18'): array
    {
        if (!is_file($mdPath)) {
            return [
                'html' => '',
                'toc' => [],
                'enonce_html' => '',
                'enonce_toc' => [],
                'corrige_html' => '',
                'corrige_toc' => [],
                'sql_snippets' => [],
            ];
        }
        $mtime = (int)@filemtime($mdPath);
        $cacheKey = md5('lab_v6_rca146:' . $courseSlug . ':' . $mdPath . ':' . $mtime);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile)) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['html'], $cached['enonce_html'], $cached['corrige_html'], $cached['sql_snippets'])) {
                return $cached;
            }
        }

        $raw = (string)file_get_contents($mdPath);
        $sqlSnippets = $this->extractSqlSnippets($raw);
        $split = $this->splitLabEnonceAndCorrige($raw);

        $renderedFull = $this->renderMarkdown($split['full_md'], $courseSlug, true);
        $renderedEnonce = $this->renderMarkdown($split['enonce_md'], $courseSlug, true);
        $renderedCorrige = $this->renderMarkdown($split['corrige_md'], $courseSlug, true);
        // En vue Corrigé dédié, ouvrir tous les blocs <details> par défaut pour lecture directe
        $corrigeHtmlOpen = str_replace('<details class="lms-details">', '<details class="lms-details" open>', $renderedCorrige['html']);

        $result = [
            'html' => $renderedFull['html'],
            'toc' => $renderedFull['toc'],
            'enonce_html' => $renderedEnonce['html'],
            'enonce_toc' => $renderedEnonce['toc'],
            'corrige_html' => $corrigeHtmlOpen,
            'corrige_toc' => $renderedCorrige['toc'],
            'sql_snippets' => $sqlSnippets,
        ];
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /**
     * Sépare un fichier Markdown d'atelier pratique en deux volets pédagogiques :
     * - `enonce_md` : Métadonnées, Prérequis, Mise en Situation, Travail Pas-à-Pas, Résultats Attendus et Challenges (avec solutions repliées en spoiler `<details>`).
     * - `corrige_md` : Solutions des Challenges Fast-Track + Corrigé Détaillé + Oracle de Validation Automatisé Qualiopi + Grille d'Évaluation (DoD) + Débriefing & REX Expert.
     */
    private function splitLabEnonceAndCorrige(string $raw): array
    {
        // Encapsuler les blocs "* **Requête Solution :**" suivis d'un bloc de code dans un <details> spoiler
        $challengeSolutions = [];
        $processedRaw = preg_replace_callback(
            '/(?:^\s*[\*\-]\s*\*\*Requête\s+Solution\s*:\*\*\s*\R+)(```[a-zA-Z0-9_-]*\R.*?\R```)/imsu',
            static function (array $m) use (&$challengeSolutions): string {
                $codeBlock = trim($m[1]);
                $idx = count($challengeSolutions) + 1;
                $challengeSolutions[] = "### 💡 Solution du Challenge Fast-Track #{$idx}\n\n" . $codeBlock;
                return "<details>\n<summary>👁️ Afficher la Requête Solution (Challenge #{$idx})</summary>\n\n" . $codeBlock . "\n</details>\n";
            },
            $raw
        ) ?? $raw;

        $enoncePart = $processedRaw;
        $corrigePart = '';

        // Détecter la section H2 de bascule vers le Corrigé / Validation / Débriefing REX
        if (preg_match('/^##\s+(?:\d+\.\s*)?(?:Corrigé|Commande de Validation|Script de Validation|Validation Automatisée|Solution)/imu', $processedRaw, $m, PREG_OFFSET_CAPTURE)) {
            $splitOffset = (int)$m[0][1];
            $enoncePart = rtrim(substr($processedRaw, 0, $splitOffset));
            // Retirer un séparateur horizontal final "---" éventuel en fin d'énoncé
            $enoncePart = preg_replace('/\R+---\s*$/u', '', $enoncePart) ?? $enoncePart;
            $corrigePart = ltrim(substr($processedRaw, $splitOffset));
        }

        $corrigeBlocks = [];
        if (!empty($challengeSolutions)) {
            $corrigeBlocks[] = "## 🎯 Solutions des Défis & Challenges d'Approfondissement\n\n" . implode("\n\n", $challengeSolutions);
        }
        if ($corrigePart !== '') {
            $corrigeBlocks[] = $corrigePart;
        }

        if (empty($corrigeBlocks)) {
            // Fallback : extraire les blocs SQL comme corrigé de référence
            $corrigeBlocks[] = "## ✅ Corrigé Technique & Requêtes de Référence\n\n" . $processedRaw;
        }

        return [
            'full_md' => $processedRaw,
            'enonce_md' => $enoncePart,
            'corrige_md' => implode("\n\n---\n\n", $corrigeBlocks),
        ];
    }

    public function extractQuizQuestions(string $markdown): array
    {
        $questions = [];
        if (preg_match_all('/<details>\s*<summary>(.*?)<\/summary>(.*?)<\/details>/isu', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $idx => $m) {
                $qTitle = trim(strip_tags(str_replace(['**'], [''], $m[1])));
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
                        'index' => count($questions) + 1,
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

    /**
     * Génère les diapositives 16:9 interactives d'un module.
     * Priorise le manifeste officiel Studio 16:9 pré-calculé (public/assets/slides_cache/<module_id>/manifest.json,
     * ex: 58 diapositives pour le Module 01, 1 686 diapositives sur les 34 modules), avec fallback sur le découpage Markdown.
     */
    public function extractSlides(string $markdown, string $courseSlug = 'postgresql-18', array $quiz = [], ?string $moduleId = null): array
    {
        if ($moduleId !== null && $moduleId !== '') {
            $manifestFile = dirname(__DIR__, 2) . '/public/assets/slides_cache/' . $moduleId . '/manifest.json';
            if (is_file($manifestFile)) {
                $manifest = @json_decode((string)@file_get_contents($manifestFile), true);
                if (is_array($manifest) && !empty($manifest['slides']) && is_array($manifest['slides'])) {
                    $total = (int)($manifest['total_slides'] ?? count($manifest['slides']));
                    $officialSlides = [];
                    foreach ($manifest['slides'] as $idx => $s) {
                        $num = (int)($s['number'] ?? ($idx + 1));
                        $sec = (string)($s['section'] ?? ('Section ' . $num));
                        $title = (string)($s['title'] ?? ('Diapositive ' . $num . ' / ' . $total));
                        $imgUrl = (string)($s['image_url'] ?? '');
                        $notes = (string)($s['notes'] ?? '');
                        $preview = (string)($s['body_preview'] ?? '');
                        $type = (string)($s['type'] ?? 'concept');
                        $loadingAttr = $num <= 2 ? 'eager' : 'lazy';

                        $slideHtml = '<div class="lms-studio-slide-figure">'
                            . '<img src="' . Security::e($imgUrl) . '" alt="' . Security::e($title) . '" class="lms-studio-slide-img" loading="' . $loadingAttr . '" decoding="async"/>'
                            . '</div>';

                        $officialSlides[] = [
                            'index' => $num,
                            'number' => $num,
                            'total' => $total,
                            'chapter' => $sec,
                            'section' => $sec,
                            'title' => $title,
                            'type' => $type,
                            'badge' => $sec,
                            'image_url' => $imgUrl,
                            'notes' => $notes,
                            'body_preview' => $preview,
                            'html' => $slideHtml,
                        ];
                    }
                    return $officialSlides;
                }
            }
        }

        // Fallback : découpage Markdown si le manifeste Studio 16:9 n'est pas présent
        $cleanMd = preg_replace_callback(
            '/<details>\s*<summary>(.*?)<\/summary>(.*?)<\/details>/isu',
            static function (array $m): string {
                if (preg_match('/(Réponse\s+correcte|\*\*[A-D]\.?\*\*)/isu', $m[2])) {
                    return '';
                }
                return $m[0];
            },
            $markdown
        ) ?? $markdown;
        $lines = preg_split('/\r\n|\r|\n/', $cleanMd) ?: [];

        $slides = [];
        $currentChapter = 'Introduction';
        $currentTitle = '';
        $currentType = 'cover';
        $currentBadge = 'MODULE POSTGRESQL 18';
        $buffer = [];
        $inCode = false;

        $flushSlide = function () use (&$slides, &$currentChapter, &$currentTitle, &$currentType, &$currentBadge, &$buffer, $courseSlug): void {
            $rawChunk = trim(implode("\n", $buffer));
            $buffer = [];
            if ($rawChunk === '' && $currentTitle === '') {
                return;
            }
            // Ignorer la section Quiz vide si ses <details> ont été extraits en slides QCM dédiées
            if ($rawChunk === '' && (str_contains(strtolower($currentTitle), 'quiz') || str_contains(strtolower($currentTitle), 'évaluation formative'))) {
                return;
            }

            $renderedChunk = $this->renderMarkdown($rawChunk, $courseSlug, false);
            $bodyHtml = trim($renderedChunk['html']);
            if ($bodyHtml === '' && $currentTitle === '') {
                return;
            }

            $slideIndex = count($slides) + 1;
            $slides[] = [
                'index' => $slideIndex,
                'chapter' => $currentChapter,
                'title' => $currentTitle !== '' ? $currentTitle : $currentChapter,
                'type' => $currentType,
                'badge' => $currentBadge,
                'html' => $bodyHtml,
            ];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '```')) {
                $inCode = !$inCode;
                $buffer[] = $line;
                continue;
            }
            if ($inCode) {
                $buffer[] = $line;
                continue;
            }

            if ($trimmed === '---' || $trimmed === '***') {
                continue;
            }

            // Titre principal H1
            if (preg_match('/^#\s+(.+)$/', $trimmed, $m)) {
                $flushSlide();
                $currentTitle = trim(str_replace(['**', '`'], '', $m[1]));
                $currentChapter = 'Couverture & Objectifs';
                $currentType = 'cover';
                $currentBadge = 'SUPPORT OFFICIEL LIGHTPATH';
                continue;
            }

            // Section H2
            if (preg_match('/^##\s+(.+)$/', $trimmed, $m)) {
                $flushSlide();
                $h2Text = trim(str_replace(['**', '`'], '', $m[1]));
                $currentChapter = $h2Text;
                $currentTitle = $h2Text;

                $lower = strtolower($h2Text);
                if (str_contains($lower, 'objectif')) {
                    $currentType = 'objectives';
                    $currentBadge = 'TAXONOMIE DE BLOOM';
                } elseif (str_contains($lower, 'plan')) {
                    $currentType = 'agenda';
                    $currentBadge = 'AGENDA DU MODULE';
                } elseif (str_contains($lower, 'synthèse') || str_contains($lower, 'points clés')) {
                    $currentType = 'summary';
                    $currentBadge = 'SYNTHÈSE & REX';
                } elseif (str_contains($lower, 'atelier')) {
                    $currentType = 'lab';
                    $currentBadge = 'TRAVAUX PRATIQUES';
                } else {
                    $currentType = 'section';
                    $currentBadge = 'CHAPITRE TECHNIQUE';
                }
                continue;
            }

            // Sous-section H3 -> Nouvelle diapositive dédiée
            if (preg_match('/^###\s+(.+)$/', $trimmed, $m)) {
                $flushSlide();
                $currentTitle = trim(str_replace(['**', '`'], '', $m[1]));
                $currentType = 'content';
                $currentBadge = $currentChapter;
                continue;
            }

            // Si une diapositive devient très longue (> 22 lignes hors code) et qu'on rencontre un callout ou une image ou un titre H4, découper proprement
            if (count($buffer) >= 20 && (preg_match('/^####\s+/', $trimmed) || preg_match('/^!\[/', $trimmed))) {
                $prevTitle = $currentTitle;
                $flushSlide();
                $currentTitle = $prevTitle . ' (Suite)';
                $currentType = 'content';
            }

            $buffer[] = $line;
        }

        $flushSlide();

        // Ajouter une diapositive interactive par question de Quiz à la fin du diaporama
        foreach ($quiz as $q) {
            $qHtml = '<div class="slide-quiz-card" data-correct="' . Security::e($q['correct']) . '">';
            $qHtml .= '<p class="slide-quiz-question"><strong>' . Security::e($q['question']) . '</strong></p>';
            $qHtml .= '<div class="slide-quiz-options">';
            foreach ($q['options'] as $letter => $optText) {
                $isCorrect = ($letter === $q['correct']);
                $qHtml .= '<div class="slide-quiz-opt ' . ($isCorrect ? 'is-correct-answer' : '') . '" data-letter="' . Security::e($letter) . '">'
                    . '<span class="opt-letter">' . Security::e($letter) . '</span>'
                    . '<span class="opt-label">' . $this->formatInline($optText, $courseSlug) . '</span>'
                    . '</div>';
            }
            $qHtml .= '</div>';
            $qHtml .= '<div class="slide-quiz-answer-box" hidden>'
                . '<div class="slide-quiz-answer-badge">✅ Réponse correcte : Option ' . Security::e($q['correct']) . '</div>'
                . '<p>' . $this->formatInline($q['explanation'], $courseSlug) . '</p>'
                . '</div>';
            $qHtml .= '<button type="button" class="btn btn-sm btn-outline-cyan btn-reveal-slide-quiz" style="margin-top:0.85rem;">👁️ Révéler le corrigé & l\'explication</button>';
            $qHtml .= '</div>';

            $slides[] = [
                'index' => count($slides) + 1,
                'chapter' => 'Évaluation Formative Qualiopi',
                'title' => 'Quiz QCM — Question ' . $q['index'] . ' / ' . count($quiz),
                'type' => 'quiz',
                'badge' => 'QUIZ FORMATIF Q' . $q['index'],
                'html' => $qHtml,
            ];
        }

        return $slides;
    }

    public function renderMarkdown(string $markdown, string $courseSlug = 'postgresql-18', bool $isLab = false): array
    {
        // 1. Dans un module de cours, retirer les blocs <details> de Quiz QCM (déjà rendus par le widget QCM interactif)
        if (!$isLab) {
            $markdown = preg_replace_callback(
                '/<details>\s*<summary>(.*?)<\/summary>(.*?)<\/details>/isu',
                static function (array $m): string {
                    if (preg_match('/(Réponse\s+correcte|\*\*[A-D]\.?\*\*)/isu', $m[2])) {
                        return '';
                    }
                    return $m[0];
                },
                $markdown
            ) ?? $markdown;
        }

        // 2. Transformer les autres blocs <details><summary>...</summary>...</details> (ex: corrigés de labs) en composants HTML propres dont l'intérieur est parsé
        $detailsPlaceholders = [];
        $markdown = preg_replace_callback(
            '/<details>\s*<summary>(.*?)<\/summary>(.*?)<\/details>/isu',
            function (array $m) use (&$detailsPlaceholders, $courseSlug, $isLab): string {
                $key = '%%DETAILS_BLOCK_' . count($detailsPlaceholders) . '%%';
                $summaryTitle = $this->formatInline(trim($m[1]), $courseSlug);
                $innerRendered = $this->renderMarkdown(trim($m[2]), $courseSlug, $isLab);
                $detailsPlaceholders[$key] = '<details class="lms-details">'
                    . '<summary class="lms-summary">' . $summaryTitle . '</summary>'
                    . '<div class="lms-details-body">' . $innerRendered['html'] . '</div>'
                    . '</details>';
                return "\n" . $key . "\n";
            },
            $markdown
        ) ?? $markdown;

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $toc = [];

        $inCodeBlock = false;
        $codeLang = '';
        $codeBuffer = [];
        $inTable = false;
        $tableRows = [];
        $listStack = []; // Pile de listes actives : ['tag' => 'ul'|'ol', 'indent' => int]
        $inBlockquote = false;
        $blockquoteLines = [];
        $sqlCounter = 0;

        $flushList = static function (int $targetIndent = -1) use (&$listStack, &$html): void {
            while (!empty($listStack)) {
                $top = end($listStack);
                if ($targetIndent >= 0 && $top['indent'] <= $targetIndent) {
                    break;
                }
                $popped = array_pop($listStack);
                $html[] = '</' . $popped['tag'] . '>';
            }
        };

        $flushBlockquote = function () use (&$inBlockquote, &$blockquoteLines, &$html, $courseSlug): void {
            if (!$inBlockquote) {
                return;
            }
            $inBlockquote = false;
            $html[] = $this->renderBlockquote($blockquoteLines, $courseSlug);
            $blockquoteLines = [];
        };

        $flushTable = function () use (&$inTable, &$tableRows, &$html, $courseSlug): void {
            if (!$inTable) {
                return;
            }
            $inTable = false;
            if (count($tableRows) < 2) {
                $tableRows = [];
                return;
            }
            $headers = $this->splitTableRow($tableRows[0]);
            if (count($headers) === 2 && str_contains(strtolower($headers[0]), 'propriété')) {
                $html[] = '<div class="lms-doc-meta-grid">';
                for ($i = 1, $n = count($tableRows); $i < $n; $i++) {
                    $rowStr = trim($tableRows[$i]);
                    if (preg_match('/^\|?[\s\-\|:]+\|?$/', $rowStr)) {
                        continue;
                    }
                    $cells = $this->splitTableRow($rowStr);
                    if (count($cells) >= 2) {
                        $labelClean = trim(str_replace(['**'], [''], $cells[0]));
                        $html[] = '<div class="lms-doc-meta-item">'
                            . '<span class="lms-doc-meta-label">' . $this->formatInline($labelClean, $courseSlug) . '</span>'
                            . '<span class="lms-doc-meta-value">' . $this->formatInline($cells[1], $courseSlug) . '</span>'
                            . '</div>';
                    }
                }
                $html[] = '</div>';
                $tableRows = [];
                return;
            }
            $html[] = '<div class="lms-table-wrap"><table class="lms-table">';
            $html[] = '<thead><tr>';
            foreach ($headers as $h) {
                $html[] = '<th>' . $this->formatInline($h, $courseSlug) . '</th>';
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
                    $html[] = '<td>' . $this->formatInline($c, $courseSlug) . '</td>';
                }
                $html[] = '</tr>';
            }
            $html[] = '</tbody></table></div>';
            $tableRows = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Gestion des blocs de code ```
            if (str_starts_with($trimmed, '```')) {
                $flushList();
                $flushBlockquote();
                $flushTable();

                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $codeLang = strtolower(trim(substr($trimmed, 3)));
                    $codeBuffer = [];
                } else {
                    $inCodeBlock = false;
                    $rawCode = implode("\n", $codeBuffer);
                    if ($codeLang === 'mermaid') {
                        $html[] = $this->renderMermaidBlock($rawCode, $courseSlug);
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

            // Commentaires HTML ignorés
            if (str_starts_with($trimmed, '<!--') && str_ends_with($trimmed, '-->')) {
                continue;
            }

            // Placeholder <details> pré-rendu
            if (isset($detailsPlaceholders[$trimmed])) {
                $flushList();
                $flushBlockquote();
                $flushTable();
                $html[] = $detailsPlaceholders[$trimmed];
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

            // Titres H1..H4
            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $hm)) {
                $flushList();
                $level = strlen($hm[1]);
                $titleText = trim($hm[2]);
                $cleanTitle = trim(strip_tags(str_replace(['**', '`'], '', $titleText)));
                $slugId = 'sec-' . substr(md5($cleanTitle . count($toc)), 0, 8);
                if ($level === 2 || $level === 3) {
                    $toc[] = [
                        'level' => $level,
                        'id' => $slugId,
                        'title' => $cleanTitle,
                    ];
                }
                $html[] = "<h{$level} id=\"{$slugId}\" class=\"lms-heading lms-h{$level}\" data-section-id=\"{$slugId}\">" . $this->formatInline($titleText, $courseSlug) . "</h{$level}>";

                // Si c'est la section 6 Quiz d'un module, ajouter un bandeau pointant vers le Quiz QCM interactif juste en bas
                if (!$isLab && $level === 2 && (str_contains(strtolower($cleanTitle), 'quiz') || str_contains(strtolower($cleanTitle), 'évaluation formative'))) {
                    $html[] = '<div class="lms-quiz-inline-banner">'
                        . '<div class="lms-quiz-inline-info">'
                        . '<strong>🎯 Module d\'Évaluation Formative Qualiopi actif :</strong> '
                        . 'Répondez aux questions QCM interactives ci-dessous pour valider vos acquis, obtenir les justifications techniques détaillées et enregistrer votre score.'
                        . '</div>'
                        . '<a href="#quiz-section" class="btn btn-sm btn-primary">Aller au Quiz Interactif ↓</a>'
                        . '</div>';
                }
                continue;
            }

            // Image autonome ![alt](src)
            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', $trimmed, $im)) {
                $flushList();
                $alt = Security::e($im[1]);
                $src = $this->resolveAssetUrl($im[2], $courseSlug);
                $html[] = '<figure class="lms-figure">'
                    . '<img src="' . Security::e($src) . '" alt="' . $alt . '" class="lms-zoomable-img" loading="lazy"/>'
                    . ($alt !== '' ? '<figcaption>' . $alt . '</figcaption>' : '')
                    . '</figure>';
                continue;
            }

            // Liste à puces (*, -) ou liste numérotée (1., 2.) avec gestion de l'indentation
            if (preg_match('/^(\s*)([\*\-]|\d+\.)\s+(.+)$/', $line, $lm)) {
                $indent = strlen(str_replace("\t", '    ', $lm[1]));
                $marker = $lm[2];
                $itemContent = trim($lm[3]);
                $desiredTag = is_numeric(rtrim($marker, '.')) ? 'ol' : 'ul';
                $listClass = $desiredTag === 'ol' ? 'lms-olist' : ($indent >= 2 ? 'lms-sublist' : 'lms-list');

                // Dépiler les sous-listes plus indentées
                while (!empty($listStack) && end($listStack)['indent'] > $indent) {
                    $popped = array_pop($listStack);
                    $html[] = '</' . $popped['tag'] . '>';
                }

                // Si au même niveau d'indentation le type de liste change (ul <-> ol), fermer et rouvrir
                if (!empty($listStack) && end($listStack)['indent'] === $indent && end($listStack)['tag'] !== $desiredTag) {
                    $popped = array_pop($listStack);
                    $html[] = '</' . $popped['tag'] . '>';
                }

                // Ouvrir une nouvelle liste si nécessaire
                if (empty($listStack) || end($listStack)['indent'] < $indent) {
                    $html[] = '<' . $desiredTag . ' class="' . $listClass . '">';
                    $listStack[] = ['tag' => $desiredTag, 'indent' => $indent];
                }

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

    /**
     * Rend un bloc Mermaid en utilisant l'image Retina 2x pré-calculée (assets/mermaid_cache/mermaid_<hash>.png)
     * ou le moteur local mermaid.min.js en fallback vectoriel (100% autonome, zéro CDN).
     */
    private function renderMermaidBlock(string $rawCode, string $courseSlug): string
    {
        $rawLines = preg_split('/\r\n|\r|\n/', trim($rawCode)) ?: [];
        $normLines = [];
        foreach ($rawLines as $l) {
            $t = trim($l);
            if ($t !== '') {
                $normLines[] = $t;
            }
        }
        $normalized = implode("\n", $normLines);
        $hash = substr(hash('sha256', $normalized), 0, 16);

        $relPngPath = 'assets/mermaid_cache/mermaid_' . $hash . '.png';
        $absPngPath = rtrim($this->sourceRoot, '/\\') . '/' . $relPngPath;
        $hasCachedPng = is_file($absPngPath);

        $header = '<div class="lms-mermaid-header">'
            . '<span class="lms-mermaid-title">📐 Schéma d\'Architecture & Flux Technique</span>'
            . '<div class="lms-mermaid-actions">'
            . ($hasCachedPng ? '<button type="button" class="btn-code-action btn-zoom-diagram" title="Agrandir le diagramme en plein écran">🔍 Agrandir</button>' : '')
            . '<button type="button" class="btn-code-action btn-toggle-mermaid-src" title="Afficher/Masquer le code source Mermaid">Code Mermaid</button>'
            . '</div>'
            . '</div>';

        if ($hasCachedPng) {
            $imgUrl = $this->resolveAssetUrl($relPngPath, $courseSlug);
            $fallbackRef = '?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($relPngPath);
            $canvas = '<div class="lms-mermaid-canvas">'
                . '<img src="' . Security::e($imgUrl) . '" data-asset-ref="' . Security::e($fallbackRef) . '" alt="Schéma d\'Architecture PostgreSQL 18" class="lms-mermaid-img lms-zoomable-img" loading="lazy" decoding="async"/>'
                . '</div>';
        } else {
            $canvas = '<div class="lms-mermaid-canvas">'
                . '<div class="mermaid">' . Security::e($rawCode) . '</div>'
                . '</div>';
        }

        $sourcePre = '<pre class="lms-mermaid-source" hidden><code>' . Security::e($rawCode) . '</code></pre>';

        return '<figure class="lms-mermaid-card">' . $header . $canvas . $sourcePre . '</figure>';
    }

    /**
     * Rend un bloc de citation / Callout (> ...) avec prise en charge native :
     * - des grilles de métadonnées d'en-tête (> **Module :** ... / > **Durée :** ...)
     * - des alertes GitHub [!IMPORTANT], [!WARNING], [!CAUTION], [!TIP], [!NOTE]
     * - des listes à puces (*, -) et numérotées (1.) imbriquées à l'intérieur du cartouche
     */
    private function renderBlockquote(array $lines, string $courseSlug): string
    {
        $nonEmpty = array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
        if (empty($nonEmpty)) {
            return '';
        }

        $first = $nonEmpty[0];
        $isAlert = preg_match('/^\[!(IMPORTANT|WARNING|CAUTION|TIP|NOTE)\]\s*(.*)$/i', $first, $am) === 1;

        // Détecter un bloc de métadonnées d'en-tête de document (ex: > **Module :** ... / > **Formateur :** ...)
        if (!$isAlert && count($nonEmpty) >= 2) {
            $metaPairs = [];
            $allMeta = true;
            foreach ($nonEmpty as $line) {
                if (preg_match('/^\*\*([^:*]+)\s*:\*\*\s*(.+)$/u', $line, $mm)) {
                    $metaPairs[] = [
                        'label' => trim($mm[1]),
                        'value' => trim($mm[2]),
                    ];
                } else {
                    $allMeta = false;
                    break;
                }
            }
            if ($allMeta && count($metaPairs) >= 2) {
                $gridHtml = '<div class="lms-doc-meta-grid">';
                foreach ($metaPairs as $pair) {
                    $gridHtml .= '<div class="lms-doc-meta-item">'
                        . '<span class="lms-doc-meta-label">' . Security::e($pair['label']) . '</span>'
                        . '<span class="lms-doc-meta-value">' . $this->formatInline($pair['value'], $courseSlug) . '</span>'
                        . '</div>';
                }
                $gridHtml .= '</div>';
                return $gridHtml;
            }
        }

        $alertType = 'note';
        $alertTitle = 'Note Technique';
        $bodyLines = $lines;

        if ($isAlert) {
            $alertType = strtolower($am[1]);
            $customTitle = trim($am[2]);
            $defaultTitles = [
                'important' => 'Important — Exigence Critique',
                'warning' => 'Attention — Point de Vigilance',
                'caution' => 'Précaution Majeure en Production',
                'tip' => 'Astuce & Bonne Pratique DBA',
                'note' => 'Information Technique',
            ];
            $alertTitle = $customTitle !== '' ? $this->formatInline($customTitle, $courseSlug) : ($defaultTitles[$alertType] ?? 'Note Technique');
            // Retirer la première ligne non-vide correspondant à [!ALERT]
            foreach ($bodyLines as $k => $v) {
                if (trim($v) !== '') {
                    unset($bodyLines[$k]);
                    break;
                }
            }
            $bodyLines = array_values($bodyLines);
        } elseif (preg_match('/^\*\*((?:(?!\*\*).)+)\*\*$/u', $first, $tm)) {
            $alertTitle = $this->formatInline($tm[1], $courseSlug);
            foreach ($bodyLines as $k => $v) {
                if (trim($v) !== '') {
                    unset($bodyLines[$k]);
                    break;
                }
            }
            $bodyLines = array_values($bodyLines);
        }

        // Parser les lignes internes (paragraphes + listes * / - / 1.)
        $innerParts = [];
        $inCalloutList = false;
        $calloutListTag = 'ul';

        $closeCalloutList = static function () use (&$inCalloutList, &$calloutListTag, &$innerParts): void {
            if ($inCalloutList) {
                $innerParts[] = '</' . $calloutListTag . '>';
                $inCalloutList = false;
            }
        };

        foreach ($bodyLines as $bLine) {
            $bTrim = trim($bLine);
            if ($bTrim === '') {
                $closeCalloutList();
                continue;
            }

            if (preg_match('/^([\*\-]|\d+\.)\s+(.+)$/', $bTrim, $lm)) {
                $tag = is_numeric(rtrim($lm[1], '.')) ? 'ol' : 'ul';
                if (!$inCalloutList || $calloutListTag !== $tag) {
                    $closeCalloutList();
                    $calloutListTag = $tag;
                    $inCalloutList = true;
                    $innerParts[] = '<' . $tag . ' class="lms-callout-list">';
                }
                $innerParts[] = '<li>' . $this->formatInline(trim($lm[2]), $courseSlug) . '</li>';
            } else {
                $closeCalloutList();
                $innerParts[] = '<p class="lms-callout-p">' . $this->formatInline($bTrim, $courseSlug) . '</p>';
            }
        }
        $closeCalloutList();

        $bodyHtml = implode("\n", $innerParts);

        return '<div class="lms-callout lms-callout-' . $alertType . '">'
            . '<div class="lms-callout-title">' . $alertTitle . '</div>'
            . ($bodyHtml !== '' ? '<div class="lms-callout-body">' . $bodyHtml . '</div>' : '')
            . '</div>';
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
        if (str_starts_with($clean, 'assets/')) {
            $localPublic = dirname(__DIR__, 2) . '/public/' . $clean;
            if (is_file($localPublic)) {
                return $clean;
            }
        }
        return '?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($clean);
    }

    private function formatInline(string $text, string $courseSlug = 'postgresql-18', bool $allowRawPrefix = false): string
    {
        $prefix = '';
        if ($allowRawPrefix && preg_match('/^(<input[^>]+>\s*)(.*)$/', $text, $pm)) {
            $prefix = $pm[1];
            $text = $pm[2];
        }

        // 1. Protéger les blocs inline `code`
        $codePlaceholders = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codePlaceholders): string {
            $key = '%%CODE_' . count($codePlaceholders) . '%%';
            $codePlaceholders[$key] = '<code class="lms-inline-code">' . Security::e($m[1]) . '</code>';
            return $key;
        }, $text) ?? $text;

        $escaped = Security::e($text);

        // 2. Restaurer quelques balises inline sûres (<br/>, <b>, <i>, <sup>, <sub>)
        $escaped = str_ireplace(
            ['&lt;br/&gt;', '&lt;br&gt;', '&lt;br /&gt;', '&lt;b&gt;', '&lt;/b&gt;', '&lt;i&gt;', '&lt;/i&gt;', '&lt;sup&gt;', '&lt;/sup&gt;', '&lt;sub&gt;', '&lt;/sub&gt;'],
            ['<br/>', '<br/>', '<br/>', '<strong>', '</strong>', '<em>', '</em>', '<sup>', '</sup>', '<sub>', '</sub>'],
            $escaped
        );

        // 3. Gras + Italique (***texte***), Gras (**texte** y compris avec *italique* imbriqué), et Italique strict (*texte*)
        $escaped = preg_replace('/\*\*\*((?:(?!\*\*\*).)+)\*\*\*/u', '<strong><em>$1</em></strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*((?:(?!\*\*).)+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![\w*])\*([^\s*](?:[^*]*[^\s*])?)\*(?![\w*])/u', '<em>$1</em>', $escaped) ?? $escaped;

        // 4. Liens Markdown [label](url)
        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function (array $m) use ($courseSlug): string {
            $label = $m[1];
            $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));

            // Bloquer tout schéma URI exécutable (javascript:, vbscript:, data:)
            if (preg_match('/^\s*(?:javascript|vbscript|data):/i', $href)) {
                return '<span>' . $label . '</span>';
            }

            // Router directement vers les vues internes du LMS pour les modules, labs et cheatsheets
            if (preg_match('/(module_(?:sql_\d{2}|\d{2})_[a-z0-9_]+)\.(?:md|html|pdf)/i', $href, $mm)) {
                $url = 'index.php?page=module&course=' . urlencode($courseSlug) . '&id=' . urlencode($mm[1]);
                return '<a href="' . Security::e($url) . '" class="lms-link">' . $label . '</a>';
            }
            if (preg_match('/(lab_(?:sql_\d{2}|\d{2})_[a-z0-9_]+)\.(?:md|html|pdf)/i', $href, $lm)) {
                $url = 'index.php?page=lab&course=' . urlencode($courseSlug) . '&id=' . urlencode($lm[1]);
                return '<a href="' . Security::e($url) . '" class="lms-link">' . $label . '</a>';
            }
            if (preg_match('/(cheatsheet_[a-z0-9_]+)\.(?:md|html|pdf)/i', $href, $cm)) {
                $url = 'index.php?page=documents&tab=cheatsheets&course=' . urlencode($courseSlug) . '&id=' . urlencode($cm[1]);
                return '<a href="' . Security::e($url) . '" class="lms-link">' . $label . '</a>';
            }
            if (str_ends_with($href, '.pdf') || str_ends_with($href, '.html') || str_ends_with($href, '.md') || str_ends_with($href, '.png')) {
                $clean = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $href)) ?? $href;
                $url = '?action=course_asset&course=' . urlencode($courseSlug) . '&path=' . urlencode($clean);
                return '<a href="' . Security::e($url) . '" class="lms-link" target="_blank" rel="noopener">' . $label . '</a>';
            }
            return '<a href="' . Security::e($href) . '" class="lms-link" target="_blank" rel="noopener">' . $label . '</a>';
        }, $escaped) ?? $escaped;

        // 5. Réinjecter les blocs `code` protégés
        foreach ($codePlaceholders as $key => $replacement) {
            $escaped = str_replace($key, $replacement, $escaped);
        }

        return $prefix . $escaped;
    }
}
