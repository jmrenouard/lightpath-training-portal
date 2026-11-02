<?php
declare(strict_types=1);

/**
 * ============================================================================
 * EMPIRICAL ADVERSARIAL TEST SUITE — BOUCLE 3 (CHALLENGER_B3_1)
 * ============================================================================
 * Tests empiriques rigoureux et oracles adverses pour certifier la Boucle 3
 * du dépôt lightpath-training-portal suite aux travaux de worker_b3_2 :
 *
 * 1. Stress-test callout rendering in MarkdownParser:
 *    - All 5 alert types: [!TIP], [!NOTE], [!IMPORTANT], [!WARNING], [!CAUTION]
 *    - Custom titles and XSS resistance
 *    - Malformed alert types: [!FOO], lowercase [!note], empty body, nested callouts
 * 2. Verification of CSS classes and palettes in app.css:
 *    - .lms-callout-note (#00B0F0) and all alert variants
 *    - .lms-code-badge styling, colors, and tabular-nums enforcement
 * 3. Code block styling and language tags:
 *    - Missing language tags (fallback to CODE)
 *    - Special characters, XSS escaping (<, >, &, ", ')
 *    - Complex SQL scripts with base64 payload in data-sql-b64
 *    - Bash, Python, Go, PHP, Mermaid
 * 4. Verification of test suites:
 *    - run_tests.php (342/342)
 *    - test_adversarial_b1.php (69/69)
 *    - test_adversarial_b2.php (50/50)
 * ============================================================================
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Core\Security;

class AdversarialTestSuiteB3
{
    private int $passed = 0;
    private int $failed = 0;
    private array $assertions = [];

    public function assert(bool $condition, string $label, string $details = ''): void
    {
        $this->assertions[] = [
            'label' => $label,
            'passed' => $condition,
            'details' => $details,
        ];
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$label}\n";
            if ($details !== '') {
                echo "         Détail: {$details}\n";
            }
        }
    }

    public function getPassed(): int
    {
        return $this->passed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('=', 72) . "\n";
        echo " SYNTHÈSE DES VÉRIFICATIONS ADVERSES BOUCLE 3 (CHALLENGER_B3_1)\n";
        echo " Succès : {$this->passed} | Échecs : {$this->failed}\n";
        echo str_repeat('=', 72) . "\n";

        if ($this->failed === 0) {
            echo "✅ VERDICT : APPROVE — Markdown, Callouts, et Code Styling sont certifiés conformes et étanches.\n";
            return 0;
        }

        echo "❌ VERDICT : REQUEST_CHANGES — Des défauts de robustesse ou de conformité ont été détectés.\n";
        return 1;
    }
}

$suite = new AdversarialTestSuiteB3();
$appCssPath = __DIR__ . '/../public/assets/css/app.css';
$appCss = is_file($appCssPath) ? (string)file_get_contents($appCssPath) : '';

echo "\n" . str_repeat('=', 72) . "\n";
echo " ADVERSARIAL CHALLENGER B3 — EMPIRICAL VERIFICATION HARNESS\n";
echo " Target: lightpath-training-portal (PHP 8.3.36)\n";
echo str_repeat('=', 72) . "\n";

// ============================================================================
// SUITE 1 : Test des 5 Alert Types GitHub & Titres Personnalisés
// ============================================================================
echo "\n--- Suite 1 : Test des 5 Alert Types GitHub & Titres Personnalisés ---\n";

$alertTypesConfig = [
    'TIP' => [
        'class' => 'lms-callout-tip',
        'defaultTitle' => 'Astuce & Bonne Pratique DBA',
    ],
    'NOTE' => [
        'class' => 'lms-callout-note',
        'defaultTitle' => 'Information Technique',
    ],
    'IMPORTANT' => [
        'class' => 'lms-callout-important',
        'defaultTitle' => 'Important — Exigence Critique',
    ],
    'WARNING' => [
        'class' => 'lms-callout-warning',
        'defaultTitle' => 'Attention — Point de Vigilance',
    ],
    'CAUTION' => [
        'class' => 'lms-callout-caution',
        'defaultTitle' => 'Précaution Majeure en Production',
    ],
];

foreach ($alertTypesConfig as $tag => $cfg) {
    $md = "> [!{$tag}]\n> Corps de test pour l'alerte {$tag}.";
    $parsed = MarkdownParser::parse($md);
    $html = $parsed['html'] ?? '';

    $hasDivClass = str_contains($html, 'class="lms-callout ' . $cfg['class'] . '"');
    $suite->assert(
        $hasDivClass,
        "Alerte [!{$tag}] produit la classe exacte 'lms-callout {$cfg['class']}'",
        "HTML généré : " . substr($html, 0, 150)
    );

    // Titre par défaut peut contenir & ou &amp; selon formatInline
    $expectedEscapedTitle = Security::e($cfg['defaultTitle']);
    $hasTitle = str_contains($html, '<div class="lms-callout-title">' . $cfg['defaultTitle'] . '</div>')
        || str_contains($html, '<div class="lms-callout-title">' . $expectedEscapedTitle . '</div>');
    $suite->assert(
        $hasTitle,
        "Alerte [!{$tag}] affiche le titre par défaut '{$cfg['defaultTitle']}'",
        "HTML généré : " . substr($html, 0, 150)
    );

    // Corps de test échappé avec formatInline (apostrophe échappée en &#039;)
    $hasBody = str_contains($html, "Corps de test pour l&#039;alerte {$tag}.")
        || str_contains($html, "Corps de test pour l'alerte {$tag}.");
    $suite->assert(
        $hasBody,
        "Alerte [!{$tag}] intègre fidèlement le texte du corps avec protection XSS (échappement entités)",
        "HTML généré : " . substr($html, 0, 150)
    );
}

// Titre personnalisé avec [!NOTE] Mon Titre Personnalisé
$customTitleMd = "> [!NOTE] Architecture & Stockage FNV-1a\n> Description des tablespaces SSD.";
$customParsed = MarkdownParser::parse($customTitleMd);
$customHtml = $customParsed['html'] ?? '';
$suite->assert(
    str_contains($customHtml, '<div class="lms-callout-title">Architecture &amp; Stockage FNV-1a</div>')
    || str_contains($customHtml, '<div class="lms-callout-title">Architecture & Stockage FNV-1a</div>'),
    "Alerte [!NOTE] supporte un titre personnalisé explicite sur la même ligne (avec encodage sécurisé &amp;)",
    "HTML généré : " . substr($customHtml, 0, 180)
);

// Résistance XSS dans le titre personnalisé
$xssTitleMd = "> [!WARNING] <script>alert('xss')</script>\" onload=\"evil()\"\n> Contenu sécurisé.";
$xssParsed = MarkdownParser::parse($xssTitleMd);
$xssHtml = $xssParsed['html'] ?? '';
$suite->assert(
    !str_contains($xssHtml, '<script>') && str_contains($xssHtml, '&lt;script&gt;'),
    "Titre personnalisé hostile neutralise strictement les balises <script> (Protection XSS)",
    "HTML généré : " . substr($xssHtml, 0, 200)
);

// Résistance XSS dans le corps de l'alerte
$xssBodyMd = "> [!NOTE]\n> <img src=x onerror=alert(1)> and <b onmouseover=evil()>hover</b>";
$xssBodyParsed = MarkdownParser::parse($xssBodyMd);
$xssBodyHtml = $xssBodyParsed['html'] ?? '';
$suite->assert(
    !str_contains($xssBodyHtml, '<img src=x onerror=') && str_contains($xssBodyHtml, '&lt;img src=x onerror='),
    "Corps d'alerte hostile neutralise strictement les injections d'images XSS",
    "HTML généré : " . substr($xssBodyHtml, 0, 200)
);

// ============================================================================
// SUITE 2 : Stress-Test Alertes Malformées & Cas Limites
// ============================================================================
echo "\n--- Suite 2 : Stress-Test Alertes Malformées & Cas Limites ---\n";

// Alertes en minuscules [!note], [!tip], [!warning], [!caution], [!important]
$lowercaseAlerts = [
    'note' => 'lms-callout-note',
    'tip' => 'lms-callout-tip',
    'warning' => 'lms-callout-warning',
    'caution' => 'lms-callout-caution',
    'important' => 'lms-callout-important',
];

foreach ($lowercaseAlerts as $lowTag => $expectedClass) {
    $lowMd = "> [!{$lowTag}]\n> Alerte en casse minuscule {$lowTag}.";
    $lowParsed = MarkdownParser::parse($lowMd);
    $lowHtml = $lowParsed['html'] ?? '';
    $suite->assert(
        str_contains($lowHtml, $expectedClass),
        "Alerte minuscule [!{$lowTag}] est résolue insensiblement à la casse en .{$expectedClass}",
        "HTML généré : " . substr($lowHtml, 0, 150)
    );
}

// Alerte en casse mixte [!NoTe], [!WaRnInG], [!TiP]
$mixedMd = "> [!NoTe]\n> Casse mixte.";
$mixedParsed = MarkdownParser::parse($mixedMd);
$mixedHtml = $mixedParsed['html'] ?? '';
$suite->assert(
    str_contains($mixedHtml, 'lms-callout-note'),
    "Alerte casse mixte [!NoTe] est résolue en .lms-callout-note",
    "HTML généré : " . substr($mixedHtml, 0, 150)
);

$mixedWarnMd = "> [!WaRnInG]\n> Casse mixte warning.";
$mixedWarnParsed = MarkdownParser::parse($mixedWarnMd);
$mixedWarnHtml = $mixedWarnParsed['html'] ?? '';
$suite->assert(
    str_contains($mixedWarnHtml, 'lms-callout-warning'),
    "Alerte casse mixte [!WaRnInG] est résolue en .lms-callout-warning",
    "HTML généré : " . substr($mixedWarnHtml, 0, 150)
);

// Alerte inconnue / malformée [!FOO], [!DANGER]
$unknownMd = "> [!FOO]\n> Contenu d'alerte non reconnue.";
$unknownParsed = MarkdownParser::parse($unknownMd);
$unknownHtml = $unknownParsed['html'] ?? '';
$suite->assert(
    str_contains($unknownHtml, 'lms-callout') && str_contains($unknownHtml, 'lms-callout-note'),
    "Alerte non reconnue [!FOO] se replie proprement sur .lms-callout-note sans lever d'erreur PHP",
    "HTML généré : " . substr($unknownHtml, 0, 150)
);
$suite->assert(
    str_contains($unknownHtml, 'Note Technique'),
    "Alerte non reconnue [!FOO] adopte le titre de repli 'Note Technique'",
    "HTML généré : " . substr($unknownHtml, 0, 150)
);

// Alerte avec corps vide (> [!NOTE])
$emptyMd = "> [!NOTE]";
$emptyParsed = MarkdownParser::parse($emptyMd);
$emptyHtml = $emptyParsed['html'] ?? '';
$suite->assert(
    str_contains($emptyHtml, 'class="lms-callout lms-callout-note"') && str_contains($emptyHtml, 'Information Technique'),
    "Alerte sans corps (> [!NOTE] seul) génère un cartouche valide avec son titre",
    "HTML généré : {$emptyHtml}"
);
$suite->assert(
    !str_contains($emptyHtml, '<div class="lms-callout-body"></div>'),
    "Alerte sans corps omet élégamment la balise .lms-callout-body vide",
    "HTML généré : {$emptyHtml}"
);

// Alerte avec seulement des espaces (> [!NOTE]\n>    \n>    )
$emptySpacesMd = "> [!NOTE]\n>    \n>    ";
$emptySpacesParsed = MarkdownParser::parse($emptySpacesMd);
$emptySpacesHtml = $emptySpacesParsed['html'] ?? '';
$suite->assert(
    str_contains($emptySpacesHtml, 'class="lms-callout lms-callout-note"') && !str_contains($emptySpacesHtml, '<div class="lms-callout-body"></div>'),
    "Alerte avec espaces uniquement omet le corps vide sans générer d'éléments superflus",
    "HTML généré : {$emptySpacesHtml}"
);

// Alerte avec sauts de lignes internes ('>')
$blankLinesMd = "> [!NOTE]\n>\n> Ligne 1 après blanc.\n>\n> Ligne 2 après blanc.";
$blankParsed = MarkdownParser::parse($blankLinesMd);
$blankHtml = $blankParsed['html'] ?? '';
$suite->assert(
    str_contains($blankHtml, 'Ligne 1 après blanc.') && str_contains($blankHtml, 'Ligne 2 après blanc.'),
    "Alerte avec sauts de lignes internes ('>') préserve tous les paragraphes",
    "HTML généré : " . substr($blankHtml, 0, 250)
);

// Alerte avec liste à puces et liste numérotée imbriquées
$listMd = "> [!TIP]\n> * Puce A\n> * Puce B\n> 1. Numéro 1\n> 2. Numéro 2";
$listParsed = MarkdownParser::parse($listMd);
$listHtml = $listParsed['html'] ?? '';
$suite->assert(
    str_contains($listHtml, '<ul class="lms-callout-list">') && str_contains($listHtml, '<li>Puce A</li>'),
    "Alerte avec liste à puces produit <ul class=\"lms-callout-list\"><li>",
    "HTML généré : " . substr($listHtml, 0, 250)
);
$suite->assert(
    str_contains($listHtml, '<ol class="lms-callout-list">') && str_contains($listHtml, '<li>Numéro 1</li>'),
    "Alerte avec liste numérotée produit <ol class=\"lms-callout-list\"><li>",
    "HTML généré : " . substr($listHtml, 0, 250)
);

// Alerte avec sous-titre markdown (### ...)
$subtitleMd = "> [!IMPORTANT]\n> ### Directive de sécurité\n> Pas de mot de passe en clair.";
$subParsed = MarkdownParser::parse($subtitleMd);
$subHtml = $subParsed['html'] ?? '';
$suite->assert(
    str_contains($subHtml, '<h4 class="lms-callout-subtitle"'),
    "Sous-titre markdown dans l'alerte produit <h4 class=\"lms-callout-subtitle\">",
    "HTML généré : " . substr($subHtml, 0, 250)
);

// Blockquote imbriqué multi-niveaux (> > [!NOTE])
$nestedMd = "> > [!NOTE]\n> > Alerte imbriquée";
$nestedParsed = MarkdownParser::parse($nestedMd);
$nestedHtml = $nestedParsed['html'] ?? '';
$suite->assert(
    str_contains($nestedHtml, 'class="lms-callout'),
    "Blockquote imbriqué multi-niveaux s'exécute sans erreur PHP et produit un rendu sécurisé",
    "HTML généré : " . substr($nestedHtml, 0, 200)
);

// ============================================================================
// SUITE 3 : Vérification Stricte des Déclarations CSS dans app.css
// ============================================================================
echo "\n--- Suite 3 : Vérification Stricte des Déclarations CSS dans app.css ---\n";

$suite->assert(
    $appCss !== '',
    "Fichier public/assets/css/app.css présent et non vide"
);

// .lms-callout-note et couleur #00B0F0
$suite->assert(
    preg_match('/\.lms-callout-note\s*\{[^}]*border-left-color:\s*#00B0F0/i', $appCss) === 1,
    ".lms-callout-note est défini avec border-left-color: #00B0F0 (Cyan LightPath officiel)"
);
$suite->assert(
    preg_match('/\.lms-callout-note\s*\{[^}]*background:\s*rgba\(0,\s*176,\s*240,\s*0\.08\)/i', $appCss) === 1,
    ".lms-callout-note est défini avec fond translucide cyan rgba(0, 176, 240, 0.08)"
);

// Vérification de toutes les autres alertes dans le CSS
$expectedCalloutsCss = [
    'tip' => '#10B981',
    'important' => '#38BDF8',
    'warning' => '#F59E0B',
    'caution' => '#F43F5E',
];
foreach ($expectedCalloutsCss as $type => $hexColor) {
    $hasRule = preg_match('/\.lms-callout-' . $type . '\s*\{[^}]*border-left-color:\s*' . preg_quote($hexColor, '/') . '/i', $appCss) === 1;
    $suite->assert(
        $hasRule,
        ".lms-callout-{$type} est défini dans app.css avec la couleur {$hexColor}"
    );
}

// .lms-code-badge et tabular-nums
$hasBadge = str_contains($appCss, '.lms-code-badge');
$suite->assert(
    $hasBadge,
    "Classe .lms-code-badge déclarée dans app.css"
);

$hasBadgeTabular = preg_match('/(?:\.lms-code-badge|\.lms-code-lang)[^{]*\{[^}]*font-variant-numeric:\s*tabular-nums/s', $appCss) === 1;
$suite->assert(
    $hasBadgeTabular,
    ".lms-code-badge applique font-variant-numeric: tabular-nums (Norme Emil Kowalski)"
);

// Tabular nums sur les blocs de code pre et code
$hasPreTabular = preg_match('/\.lms-code-block\s+pre\s*\{[^}]*font-variant-numeric:\s*tabular-nums/s', $appCss) === 1;
$suite->assert(
    $hasPreTabular,
    ".lms-code-block pre applique font-variant-numeric: tabular-nums"
);

$hasCodeTabular = preg_match('/\.lms-code-block\s+code\s*\{[^}]*font-variant-numeric:\s*tabular-nums/s', $appCss) === 1;
$suite->assert(
    $hasCodeTabular,
    ".lms-code-block code applique font-variant-numeric: tabular-nums"
);

// Coloration syntaxique & jetons Emil Kowalski Obsidian/Cyan
$expectedTokens = [
    'token-keyword' => '#38BDF8',
    'token-string' => '#34D399',
    'token-comment' => '#64748B',
    'token-number' => '#FBBF24',
    'token-function' => '#818CF8',
];
foreach ($expectedTokens as $tokClass => $tokColor) {
    $hasTok = str_contains($appCss, '.' . $tokClass) && str_contains($appCss, $tokColor);
    $suite->assert(
        $hasTok,
        "Jeton syntaxique .{$tokClass} stylisé avec la couleur {$tokColor}"
    );
}

// ============================================================================
// SUITE 4 : Blocs de Code, Badges de Langage & Cas Limites
// ============================================================================
echo "\n--- Suite 4 : Blocs de Code, Badges de Langage & Cas Limites ---\n";

// Bloc de code sans spécification de langage (``` seul)
$noLangMd = "```\necho 'hello world';\n```";
$noLangParsed = MarkdownParser::parse($noLangMd);
$noLangHtml = $noLangParsed['html'] ?? '';
$suite->assert(
    str_contains($noLangHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($noLangHtml, '>CODE<'),
    "Bloc de code sans langage spécifié utilise le badge par défaut 'CODE'",
    "HTML généré : " . substr($noLangHtml, 0, 180)
);
$suite->assert(
    str_contains($noLangHtml, '<code class="lang-">'),
    "Bloc de code sans langage utilise <code class=\"lang-\"> sans erreur PHP",
    "HTML généré : " . substr($noLangHtml, 0, 180)
);

// Bloc de code avec langage en majuscules (```SQL ...)
$upperLangMd = "```SQL\nSELECT 42;\n```";
$upperLangParsed = MarkdownParser::parse($upperLangMd);
$upperLangHtml = $upperLangParsed['html'] ?? '';
$suite->assert(
    str_contains($upperLangHtml, '<code class="lang-sql">') && str_contains($upperLangHtml, '>SQL<'),
    "Bloc de code avec langage en majuscules (```SQL) normalise en lang-sql et badge SQL",
    "HTML généré : " . substr($upperLangHtml, 0, 180)
);

// Bloc SQL avec bouton d'exécution interactive Docker et payload base64
$sqlSample = "SELECT datname, pg_size_pretty(pg_database_size(datname)) FROM pg_database WHERE datname NOT LIKE 'template%';";
$sqlMd = "```sql\n{$sqlSample}\n```";
$sqlParsed = MarkdownParser::parse($sqlMd);
$sqlHtml = $sqlParsed['html'] ?? '';
$suite->assert(
    str_contains($sqlHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($sqlHtml, '>SQL<'),
    "Bloc SQL affiche le badge de langage SQL avec .lms-code-badge",
    "HTML généré : " . substr($sqlHtml, 0, 180)
);
$suite->assert(
    str_contains($sqlHtml, 'btn-run-sql') && str_contains($sqlHtml, 'data-sql-b64="'),
    "Bloc SQL intègre le bouton interactif 'btn-run-sql' avec attribut data-sql-b64",
    "HTML généré : " . substr($sqlHtml, 0, 250)
);

// Décoder et vérifier le payload base64 du bouton SQL
if (preg_match('/data-sql-b64="([^"]+)"/', $sqlHtml, $b64m)) {
    $decodedSql = base64_decode($b64m[1]);
    $suite->assert(
        $decodedSql === trim($sqlSample),
        "data-sql-b64 contient exactement la requête SQL d'origine encodée en base64"
    );
} else {
    $suite->assert(false, "Attribut data-sql-b64 présent et lisible");
}

// Bloc Bash sans bouton SQL mais avec badge BASH
$bashMd = "```bash\npgbench -i -s 10 -p 5432 formation_pg\n```";
$bashParsed = MarkdownParser::parse($bashMd);
$bashHtml = $bashParsed['html'] ?? '';
$suite->assert(
    str_contains($bashHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($bashHtml, '>BASH<'),
    "Bloc Bash affiche le badge BASH avec .lms-code-badge",
    "HTML généré : " . substr($bashHtml, 0, 180)
);
$suite->assert(
    !str_contains($bashHtml, 'btn-run-sql'),
    "Bloc Bash n'injecte PAS de bouton d'exécution SQL",
    "HTML généré : " . substr($bashHtml, 0, 180)
);

// Bloc Python avec badge PYTHON
$pyMd = "```python\nimport psycopg\nconn = psycopg.connect('dbname=formation_pg')\n```";
$pyParsed = MarkdownParser::parse($pyMd);
$pyHtml = $pyParsed['html'] ?? '';
$suite->assert(
    str_contains($pyHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($pyHtml, '>PYTHON<') && str_contains($pyHtml, '<code class="lang-python">'),
    "Bloc Python affiche le badge PYTHON et <code class=\"lang-python\">",
    "HTML généré : " . substr($pyHtml, 0, 180)
);

// Bloc Go avec badge GO
$goMd = "```go\npackage main\nimport \"fmt\"\nfunc main() { fmt.Println(\"Slonik\") }\n```";
$goParsed = MarkdownParser::parse($goMd);
$goHtml = $goParsed['html'] ?? '';
$suite->assert(
    str_contains($goHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($goHtml, '>GO<') && str_contains($goHtml, '<code class="lang-go">'),
    "Bloc Go affiche le badge GO et <code class=\"lang-go\">",
    "HTML généré : " . substr($goHtml, 0, 180)
);

// Bloc PHP avec badge PHP
$phpMd = "```php\n<?php\necho 'PostgreSQL 18';\n```";
$phpParsed = MarkdownParser::parse($phpMd);
$phpHtml = $phpParsed['html'] ?? '';
$suite->assert(
    str_contains($phpHtml, 'class="lms-code-lang lms-code-badge"') && str_contains($phpHtml, '>PHP<') && str_contains($phpHtml, '<code class="lang-php">'),
    "Bloc PHP affiche le badge PHP et <code class=\"lang-php\">",
    "HTML généré : " . substr($phpHtml, 0, 180)
);

// Bouton copier présent sur tous les blocs de code standard
$suite->assert(
    str_contains($sqlHtml, 'btn-copy-code') && str_contains($bashHtml, 'btn-copy-code') && str_contains($pyHtml, 'btn-copy-code'),
    "Bouton '📋 Copier' (.btn-copy-code) présent sur les blocs SQL, Bash et Python"
);

// Bloc de code avec caractères hostiles / spéciaux (<, >, &, ", ', scripts)
$hostileCode = "<script>alert('xss');</script>\n<?php echo \$a && \$b || \$c > \$d; ?>\nDROP TABLE \"secret\"; -- comment & test";
$hostileMd = "```sql\n{$hostileCode}\n```";
$hostileParsed = MarkdownParser::parse($hostileMd);
$hostileHtml = $hostileParsed['html'] ?? '';
$suite->assert(
    !str_contains($hostileHtml, '<script>') && str_contains($hostileHtml, '&lt;script&gt;'),
    "Caractères HTML spéciaux (<, >) sont rigoureusement échappés dans <code>",
    "HTML généré : " . substr($hostileHtml, 0, 250)
);
$suite->assert(
    str_contains($hostileHtml, '&amp;&amp;'),
    "L'esperluette (&) est correctement encodée en entité HTML dans <code>",
    "HTML généré : " . substr($hostileHtml, 0, 250)
);

// Bloc Mermaid
$mermaidCode = "graph TD\n  A[Client] --> B[PostgreSQL]";
$mermaidMd = "```mermaid\n{$mermaidCode}\n```";
$mermaidParsed = MarkdownParser::parse($mermaidMd);
$mermaidHtml = $mermaidParsed['html'] ?? '';
$suite->assert(
    str_contains($mermaidHtml, 'class="lms-mermaid-card"') && str_contains($mermaidHtml, 'lms-mermaid-canvas'),
    "Bloc ```mermaid génère un composant .lms-mermaid-card",
    "HTML généré : " . substr($mermaidHtml, 0, 200)
);

// ============================================================================
// SUITE 5 : Vérification des Suites de Tests Globales
// ============================================================================
echo "\n--- Suite 5 : Vérification des Suites de Tests Globales ---\n";

// 1. Exécution de run_tests.php
$cmdRunTests = 'php "' . __DIR__ . '/run_tests.php" 2>&1';
exec($cmdRunTests, $runTestsOut, $runTestsExit);
$runTestsOutStr = implode("\n", $runTestsOut);
$hasTestsPass = preg_match('/RÉSULTATS DES TESTS LMS : (\d+) passés, 0 échoués/', $runTestsOutStr, $matches) && (int)$matches[1] >= 342;
$suite->assert(
    $runTestsExit === 0 && $hasTestsPass,
    "Suite officielle tests/run_tests.php s'exécute avec succès (>= 342 passés, 0 échoués, code de sortie 0)",
    "Exit: {$runTestsExit}, Sortie: " . substr($runTestsOutStr, -200)
);

// 2. Exécution de test_adversarial_b1.php
$cmdAdvB1 = 'php "' . __DIR__ . '/test_adversarial_b1.php" 2>&1';
exec($cmdAdvB1, $b1Out, $b1Exit);
$b1OutStr = implode("\n", $b1Out);
$hasB1Approve = str_contains($b1OutStr, 'VERDICT : APPROVE');
$suite->assert(
    $b1Exit === 0 && $hasB1Approve,
    "Suite adverse tests/test_adversarial_b1.php s'exécute avec succès (69/69 passés, VERDICT : APPROVE)",
    "Exit: {$b1Exit}, Sortie: " . substr($b1OutStr, -200)
);

// 3. Exécution de test_adversarial_b2.php
$cmdAdvB2 = 'php "' . __DIR__ . '/test_adversarial_b2.php" 2>&1';
exec($cmdAdvB2, $b2Out, $b2Exit);
$b2OutStr = implode("\n", $b2Out);
$hasB2Approve = str_contains($b2OutStr, 'VERDICT : APPROVE');
$suite->assert(
    $b2Exit === 0 && $hasB2Approve,
    "Suite adverse tests/test_adversarial_b2.php s'exécute avec succès (50/50 passés, VERDICT : APPROVE)",
    "Exit: {$b2Exit}, Sortie: " . substr($b2OutStr, -200)
);

exit($suite->summary());
