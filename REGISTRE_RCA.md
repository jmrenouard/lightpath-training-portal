# Registre d'Analyse des Causes Racines (RCA) — LightPath Training Portal (LMS PHP 8.3)

> **Organisation :** LightPath SARL (`https://www.lightpath.fr`)  
> **Auteur :** Jean-Marie Renouard (Expert Bases de Données & DataOps, Co-développeur de MySQLTuner)  
> **Synchronisation Référentiel :** `training_postgresql/REGISTRE_RCA.md` (**RCA-144**)

---

## Tableau Récapitulatif des Fiches RCA du Portail LMS

| ID RCA | Réf. Globale | Titre & Périmètre | Fichiers Cibles | Sévérité | Statut | Preuve de Validation |
| :--- | :--- | :--- | :--- | :---: | :---: | :--- |
| **RCA-LMS-01** | `RCA-144.A` | Rendu Visuel Markdown & Mermaid Sans Déchet (Diagrammes Retina `mermaid_<hash>.png`, Puces dans Callouts, Listes `<ol>`, Nettoyage `<details>` Quiz) | `src/Course/MarkdownParser.php`, `public/assets/js/mermaid.min.js` | CRITIQUE | ✅ Implémenté | `test_markdown_visual_integrity` pass sur 34 modules & 34 labs |
| **RCA-LMS-02** | `RCA-144.B` | Éradication des Débordements Horizontaux, Troncatures & Retours à la Ligne Défectueux (Tableaux, Code Inline, Cartouches, Flex/Grid) | `public/assets/css/app.css` | HAUTE | ✅ Implémenté | `test_css_overflow_guards` pass |
| **RCA-LMS-03** | `RCA-144.C` | Optimisation Critique des Temps de Réponse HTTP (`< 15 ms`) : Cache Catalogue JSON & Décorrélation Asynchrone `wsl docker inspect` | `src/Course/CourseRepository.php`, `src/Docker/DockerManager.php`, `public/index.php`, `public/assets/js/app.js` | CRITIQUE | ✅ Implémenté | `test_performance_cache` pass (`< 15 ms` par requête) |
| **RCA-LMS-04** | `RCA-144.D` | Structuration Visuelle des 4 Parcours Pédagogiques, Couplage `Module ↔ Lab`, ScrollSpy Temps Réel du Sommaire & Stepper de Position | `config/courses.php`, `templates/pages/dashboard.php`, `catalog.php`, `module_view.php`, `lab_view.php`, `public/assets/js/app.js` | HAUTE | ✅ Implémenté | `test_tracks_and_scrollspy` pass |
| **RCA-LMS-05** | `RCA-144.E` | Mode Diaporama Interactif 16:9 (`Slide Deck Player` HTML5 + Visionneuse PowerPoint `_slides.pdf` Intégrée) | `src/Course/MarkdownParser.php`, `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js` | CRITIQUE | ✅ Implémenté | `test_slide_deck_extraction` pass ($\ge 15$ slides/module) |

---

## 1. RCA-LMS-01 (`RCA-144.A`) — Rendu Visuel Markdown & Mermaid Sans Déchet

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `src/Course/MarkdownParser.php`
* **1. Mécanisme :**
  1. Les blocs ` ```mermaid ` étaient affichés sous forme de texte brut `<pre class="lms-mermaid-code">` au lieu de résoudre les diagrammes Retina 2x pré-compilés dans `training_postgresql/assets/mermaid_cache/mermaid_<sha256_16>.png`.
  2. Les listes à puces (`* `, `- `) situées à l'intérieur des cartouches et citations (`> ...`) étaient concaténées par `<br/>` au lieu d'être structurées en `<ul><li>`. L'astérisque de puce en début de ligne entrait alors en collision avec l'expression régulière d'italique `/\*([^*]+)\*/` dès qu'un second astérisque apparaissait plus loin sur la même ligne, laissant des `*` bruts non interprétés.
  3. Les blocs `<details><summary>` de la section 6 (`Quiz d'Évaluation Formative`) en fin de fichier `.md` n'étaient pas purgés du corps HTML alors qu'ils étaient déjà rendus par le composant QCM interactif, créant un doublon et un affichage brut.
* **2. Hypothèse :**
  Calculer l'empreinte SHA-256 normalisée sur 16 caractères pour chaque bloc Mermaid afin de servir l'image Retina `assets/mermaid_cache/mermaid_<hash>.png` (avec fallback vectoriel `mermaid.min.js`), parser les listes internes aux blockquotes/callouts en `<ul>`/`<ol>`, transformer les métadonnées d'en-tête (`> **Module Associé :** ...`) en grille structurée, sécuriser `formatInline()` et filtrer les `<details>` de quiz du corps principal.
* **3. Contre-mesure :**
  Refonte de `MarkdownParser.php` (`renderMermaidBlock`, `parseQuoteContent`, `formatInline`, suppression des `<details>` de quiz dupliqués) et intégration de `public/assets/js/mermaid.min.js`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-01 dans REGISTRE_RCA.md.
Tâche : Vérifier dans src/Course/MarkdownParser.php que chaque bloc ```mermaid calcule sha256(implode("\n", lignes_normalisees))[0..15] pour afficher assets/mermaid_cache/mermaid_<hash>.png, que les listes dans les blockquotes (> * ...) génèrent des <ul><li>, et qu'aucun token ** ou * brut ne subsiste. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 2. RCA-LMS-02 (`RCA-144.B`) — Éradication des Débordements Horizontaux & Retours à la Ligne

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `public/assets/css/app.css`
* **1. Mécanisme :**
  Les colonnes CSS Grid/Flexbox sans `min-width: 0`, les tableaux techniques `.lms-table` contenant des noms de paramètres GUC ou signatures SQL longues, et les balises `<code>` inline sans `overflow-wrap: break-word` provoquaient un dépassement horizontal de la zone de lecture.
* **2. Hypothèse :**
  Appliquer systématiquement `min-width: 0`, `overflow-wrap: break-word` et `word-break: break-word` sur les conteneurs de lecture, cellules de tableaux, cartouches et éléments inline, tout en conservant un défilement horizontal propre et isolé sur les blocs `<pre><code>`.
* **3. Contre-mesure :**
  Durcissement des règles de mise en page, des tableaux, des callouts et des blocs de code dans `public/assets/css/app.css`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-02 dans REGISTRE_RCA.md.
Tâche : Vérifier dans public/assets/css/app.css la présence de min-width: 0 sur les grilles (.reader-layout, .reader-main) et overflow-wrap: break-word sur .markdown-body, .lms-table td et .inline-code. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 3. RCA-LMS-03 (`RCA-144.C`) — Optimisation Critique des Temps de Réponse HTTP (`< 15 ms`)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `src/Course/CourseRepository.php`, `src/Docker/DockerManager.php`, `public/index.php`
* **1. Mécanisme :**
  Chaque chargement de page exécutait 2 à 3 appels bloquants `exec('wsl -d Ubuntu -u root -- docker inspect ...')` (~1,5 à 3,0 secondes) ainsi qu'un scan complet de ~900 fichiers sur le disque Dropbox.
* **2. Hypothèse :**
  Persister le catalogue parsé dans `storage/data/catalog_cache_<slug>.json` (invalidé par `filemtime` des dossiers) et lire l'état du conteneur Docker en mémoire/fichier via `getCachedUserContainerStatus()`, tout en rafraîchissant le statut Docker de manière asynchrone via `?action=api_docker_status`.
* **3. Contre-mesure :**
  Implémentation du cache catalogue dans `CourseRepository.php`, de `getCachedUserContainerStatus()` dans `DockerManager.php`, et de l'endpoint asynchrone `api_docker_status` dans `public/index.php` + `public/assets/js/app.js`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-03 dans REGISTRE_RCA.md.
Tâche : Vérifier que public/index.php utilise getCachedUserContainerStatus() lors du rendu des pages HTML et que CourseRepository::getCourse() utilise storage/data/catalog_cache_<slug>.json pour garantir un temps de réponse < 15 ms. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 4. RCA-LMS-04 (`RCA-144.D`) — Clarté des 4 Parcours, ScrollSpy du Sommaire & Stepper de Position

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `config/courses.php`, `templates/pages/dashboard.php`, `templates/pages/catalog.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php`, `public/assets/js/app.js`
* **1. Mécanisme :**
  Les 4 parcours n'affichaient ni leur public cible, ni leur découpage par journées/étapes, ni le couplage direct entre chaque Module et son Atelier Pratique. En outre, lors de la lecture d'un document, le sommaire latéral ne mettait pas en évidence la section en cours de lecture (absence de ScrollSpy) et l'apprenant ne voyait pas sa progression au sein du parcours.
* **2. Hypothèse :**
  Structurer chaque parcours avec métadonnées pédagogiques (public, durée, niveau, jalons par journée), coupler chaque carte Module avec son Lab associé, ajouter un **Stepper de Parcours** (`Module X / N`) dans la barre latérale et activer un moteur **ScrollSpy** + barre de progression de lecture (`0%–100%`) dans `app.js`.
* **3. Contre-mesure :**
  Mise à jour de `config/courses.php`, refonte visuelle de `dashboard.php` et `catalog.php`, et ajout du Stepper de parcours + ScrollSpy temps réel dans `module_view.php`, `lab_view.php` et `public/assets/js/app.js`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-04 dans REGISTRE_RCA.md.
Tâche : Vérifier la structuration des 4 parcours dans config/courses.php, le couplage Module/Lab dans catalog.php, le Stepper de position dans module_view.php/lab_view.php et le ScrollSpy temps réel dans public/assets/js/app.js. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 5. RCA-LMS-05 (`RCA-144.E`) — Mode Diaporama Interactif 16:9 (`Slide Deck Player` & `_slides.pdf`)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `src/Course/MarkdownParser.php`, `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js`
* **1. Mécanisme :**
  Les modules de cours ne pouvaient être parcourus que sous forme de document vertical continu, sans mode de présentation diapositive par diapositive en 16:9.
* **2. Hypothèse :**
  Implémenter `MarkdownParser::extractSlides()` pour découper chaque module en diapositives 16:9 riches (Titre, Infographie Studio, Objectifs, Sections `H2`/`H3`, Schémas Mermaid, Code, Tableaux, Quiz) et doter `module_view.php` d'un lecteur **Mode Diaporama 16:9** complet (navigation clavier `←`/`→`/`Espace`, plein écran `F`, compteur `Slide X / N`, sélecteur rapide de diapositive, et bascule instantanée vers le support PDF officiel `_slides.pdf`).
* **3. Contre-mesure :**
  Ajout de `extractSlides()` dans `MarkdownParser.php`, intégration du lecteur 16:9 et de la visionneuse `_slides.pdf` dans `module_view.php`, et ajout des contrôles clavier/plein écran dans `public/assets/js/app.js` et `public/assets/css/app.css`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-05 dans REGISTRE_RCA.md.
Tâche : Vérifier que MarkdownParser::extractSlides() génère au moins 15 diapositives 16:9 structurées par module et que templates/pages/module_view.php permet de suivre le cours en Mode Diaporama Interactif 16:9 ainsi qu'en mode PDF Slides officiel. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25
