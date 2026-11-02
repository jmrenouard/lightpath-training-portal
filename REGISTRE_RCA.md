# Registre d'Analyse des Causes Racines (RCA) — LightPath Training Portal (LMS PHP 8.3)

> **Organisation :** LightPath SARL (`https://www.lightpath.fr`)  
> **Auteur :** Jean-Marie Renouard (Expert Bases de Données & DataOps, Co-développeur de MySQLTuner)  
> **Synchronisation Référentiel :** `training_postgresql/REGISTRE_RCA.md` (**RCA-144 & RCA-145**)

---

## Tableau Récapitulatif des Fiches RCA du Portail LMS

| ID RCA | Réf. Globale | Titre & Périmètre | Fichiers Cibles | Sévérité | Statut | Preuve de Validation |
| :--- | :--- | :--- | :--- | :---: | :---: | :--- |
| **RCA-LMS-01** | `RCA-144.A` | Rendu Visuel Markdown & Mermaid Sans Déchet (Diagrammes Retina `mermaid_<hash>.png`, Puces dans Callouts, Listes `<ol>`, Nettoyage `<details>` Quiz) | `src/Course/MarkdownParser.php`, `public/assets/js/mermaid.min.js` | CRITIQUE | ✅ Implémenté | `test_markdown_visual_integrity` pass sur 34 modules & 34 labs |
| **RCA-LMS-02** | `RCA-144.B` | Éradication des Débordements Horizontaux, Troncatures & Retours à la Ligne Défectueux (Tableaux, Code Inline, Cartouches, Flex/Grid) | `public/assets/css/app.css` | HAUTE | ✅ Implémenté | `test_css_overflow_guards` pass |
| **RCA-LMS-03** | `RCA-144.C` | Optimisation Critique des Temps de Réponse HTTP (`< 15 ms`) : Cache Catalogue JSON & Décorrélation Asynchrone `wsl docker inspect` | `src/Course/CourseRepository.php`, `src/Docker/DockerManager.php`, `public/index.php`, `public/assets/js/app.js` | CRITIQUE | ✅ Implémenté | `test_performance_cache` pass (`< 15 ms` par requête) |
| **RCA-LMS-04** | `RCA-144.D` | Structuration Visuelle des 4 Parcours Pédagogiques, Couplage `Module ↔ Lab`, ScrollSpy Temps Réel du Sommaire & Stepper de Position | `config/courses.php`, `templates/pages/dashboard.php`, `catalog.php`, `module_view.php`, `lab_view.php`, `public/assets/js/app.js` | HAUTE | ✅ Implémenté | `test_tracks_and_scrollspy` pass |
| **RCA-LMS-05** | `RCA-144.E` | Mode Diaporama Interactif 16:9 (`Slide Deck Player` HTML5 + Visionneuse PowerPoint `_slides.pdf` Intégrée) | `src/Course/MarkdownParser.php`, `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js` | CRITIQUE | ✅ Implémenté | `test_slide_deck_extraction` pass ($\ge 15$ slides/module) |
| **RCA-LMS-06** | `RCA-145.A` | Logos Dark/Light 100 % Transparents (`lightpath_logo_white_text.png` sans rectangle blanc & `postgresql_elephant.png` à texte clair sur fond sombre) | `public/assets/img/lightpath_logo_white_text.png`, `public/assets/img/postgresql_elephant.png`, `templates/layout.php`, `public/assets/css/app.css` | CRITIQUE | ✅ Implémenté | `alpha == 0` vérifié aux 4 coins & contraste texte clair certifié |
| **RCA-LMS-07** | `RCA-145.B` | Éradication de 100 % des Erreurs `404 Not Found` Console (`assets/logos`, `assets/images`, `favicon.ico`, `.well-known`) & des Violations `unload` | `public/assets/{logos,images,mermaid_cache,vendor}`, `src/Course/DownloadController.php`, `public/favicon.ico` | CRITIQUE | ✅ Implémenté | 0 erreur 404 sur tous les modes (`reader`, `slides`, `html_preview`) |
| **RCA-LMS-08** | `RCA-145.C` | Alignement Exact du Mode Diaporama 16:9 sur les **58 Slides** du Module 01 (et les **1 686 Slides** des 34 Modules Officiels `.pptx` / `_slides.pdf`) | `public/assets/slides_cache/*`, `src/Course/MarkdownParser.php`, `templates/pages/module_view.php` | CRITIQUE | ✅ Implémenté | 58/58 slides Module 01 & 1 686 slides Studio 16:9 vérifiées |
| **RCA-LMS-09** | `RCA-145.D` | Accélération de l'Affichage Navigateur : Suppression du Chargement Synchrone de `mermaid.min.js` (3,33 Mo), `session_write_close()` & Images Statiques Directes | `templates/layout.php`, `public/index.php`, `public/assets/js/app.js`, `src/Course/MarkdownParser.php` | CRITIQUE | ✅ Implémenté | 0 blocage JS/Session, affichage instantané des pages |
| **RCA-LMS-10** | `RCA-145.E` | Accordéon de Navigation Directe des 34 Modules dans la Barre Latérale Gauche & Refonte Ergonomique Épurée de la Vue Module | `templates/layout.php`, `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js` | HAUTE | ✅ Implémenté | Navigation 1-clic vers les 34 modules depuis la sidebar gauche |

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

---

## 6. RCA-LMS-06 (`RCA-145.A`) — Logos Dark/Light 100 % Transparents (`lightpath_logo_white_text.png` & `postgresql_elephant.png`)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `public/assets/img/lightpath_logo_white_text.png`, `public/assets/img/postgresql_elephant.png`, `templates/layout.php`, `public/assets/css/app.css`
* **1. Mécanisme :**
  `lightpath_logo_white_text.png` avait un fond blanc rectangulaire opaque (`alpha = 255`) avec un texte foncé, et `postgresql_elephant.png` avait le texte `"Postgre"` et le contour extérieur de Slonik en noir (`#000000`) sur fond transparent, ce qui le rendait invisible sur le thème sombre `#0B1120`.
* **2. Hypothèse :**
  Générer par traitement RGBA (`Pillow`/`NumPy`) un vrai `lightpath_logo_white_text.png` à fond 100 % transparent (`alpha = 0`) et texte `"LightPath"` blanc pur `#F8FAFC`, ainsi qu'un `postgresql_elephant.png` adapté au thème sombre (contour blanc net, `"Postgre"` en `#F8FAFC` et `"SQL"` en `#38BDF8`), avec commutation automatique vers les variantes claires (`lightpath_logo_dark_text.png` / `postgresql_elephant_light.png`) en thème clair.
* **3. Contre-mesure :**
  Régénération RGBA sans fond blanc des logos dans `public/assets/img/` et `training_postgresql/assets/logos/`, et bascule automatique Dark/Light dans `templates/layout.php` et `public/assets/css/app.css`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-06 dans REGISTRE_RCA.md.
Tâche : Vérifier que public/assets/img/lightpath_logo_white_text.png et postgresql_elephant.png ont alpha=0 aux 4 coins et un texte clair lisible sur fond sombre. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 7. RCA-LMS-07 (`RCA-145.B`) — Éradication des Erreurs `404 Not Found` Console & Violations `unload`

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `public/assets/`, `src/Course/DownloadController.php`, `public/favicon.ico`, `public/.well-known/appspecific/com.chrome.devtools.json`
* **1. Mécanisme :**
  En mode `Rendu Web Studio` (`mode=html_preview`), les chemins relatifs `../assets/logos/...` et `../assets/images/...` des fichiers `.html` étaient résolus vers `/assets/logos/...` et `/assets/images/...` absents de `public/assets/`. En outre, `/favicon.ico` et `/.well-known/appspecific/com.chrome.devtools.json` renvoyaient 404, et l'iframe PDF cachée provoquait des avertissements `Permissions policy violation: unload`.
* **2. Hypothèse :**
  Exposer `logos/`, `images/`, `mermaid_cache/` et `vendor/` sous `public/assets/`, créer `public/favicon.ico` et `public/.well-known/appspecific/com.chrome.devtools.json`, réécrire les chemins relatifs dans `DownloadController::streamFile()` pour les rendus HTML inline, et ne charger les iframes PDF qu'au clic explicite (`data-src`).
* **3. Contre-mesure :**
  Création des jonctions/fichiers statiques sous `public/`, réécriture dynamique dans `DownloadController::streamFile()` et suppression du préchargement d'iframe PDF.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-07 dans REGISTRE_RCA.md.
Tâche : Vérifier que /assets/logos/postgresql_elephant.png, /assets/logos/lightpath_logo_dark_text.png, /assets/images/modules/img_mod_01_aio_subsystem.png et /favicon.ico existent sous public/ et que DownloadController réécrit les chemins relatifs HTML. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 8. RCA-LMS-08 (`RCA-145.C`) — Alignement Exact du Mode Diaporama 16:9 sur les 58 Slides du Module 01 (et les 1 686 Slides des 34 Modules)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `public/assets/slides_cache/`, `src/Course/MarkdownParser.php`, `templates/pages/module_view.php`
* **1. Mécanisme :**
  Le découpeur heuristique PHP produisait 27 slides pour le Module 01 alors que le support de présentation officiel (`module_01_introduction_postgresql_18.pptx` / `_slides.pdf`) comporte **58 diapositives Studio 16:9** (et **1 686 diapositives** sur l'ensemble des 34 modules).
* **2. Hypothèse :**
  Exporter les **1 686 diapositives Studio 16:9** des 34 modules (`58 slides` pour le Module 01) depuis `*_slides.pdf` et `*.pptx` dans `public/assets/slides_cache/<module_id>/` (`slide_001.png`..`slide_NNN.png` + `manifest.json` avec titres exacts, chapitres et notes présentateur) et alimenter le lecteur 16:9 directement avec ces diapositives officielles.
* **3. Contre-mesure :**
  Génération des 34 manifestes et des 1 686 diapositives 16:9 dans `public/assets/slides_cache/`, lecture prioritaire dans `MarkdownParser::extractSlides()` et affichage haute fidélité dans `module_view.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-08 dans REGISTRE_RCA.md.
Tâche : Vérifier que le Module 01 expose exactement 58 slides dans extractSlides() et que chacun des 34 modules possède son manifest.json aligné sur son support _slides.pdf. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 9. RCA-LMS-09 (`RCA-145.D`) — Accélération de l'Affichage Navigateur (Zéro Blocage `mermaid.min.js` 3,33 Mo, `session_write_close()` & Images Statiques Directes)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `templates/layout.php`, `public/index.php`, `public/assets/js/app.js`, `src/Course/MarkdownParser.php`
* **1. Mécanisme :**
  1) `mermaid.min.js` (3,33 Mo) était chargé sur toutes les pages alors que tous les diagrammes sont déjà pré-rendus en PNG.
  2) `app.js` appelait `POST /index.php?action=api_docker_status` à chaque page, bloquant le verrou de session PHP et le serveur mono-processus `php -S` pendant l'exécution de `wsl docker inspect`.
  3) Les images de cours passaient par `index.php?action=course_asset`.
* **2. Hypothèse :**
  Ne charger `mermaid.min.js` qu'à la demande s'il existe un bloc `.mermaid` non pré-rendu, libérer immédiatement le verrou de session via `session_write_close()` dans `public/index.php`, supprimer l'appel automatique `api_docker_status` au chargement de page, et servir toutes les images directement via `assets/images/...` et `assets/mermaid_cache/...`.
* **3. Contre-mesure :**
  Mise à jour de `templates/layout.php`, `public/index.php`, `public/assets/js/app.js` et `src/Course/MarkdownParser.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-09 dans REGISTRE_RCA.md.
Tâche : Vérifier l'absence de <script src="assets/js/mermaid.min.js"> statique dans templates/layout.php, la présence de session_write_close() dans public/index.php, et l'utilisation d'URLs statiques directes assets/images/ et assets/mermaid_cache/ dans MarkdownParser.php. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 10. RCA-LMS-10 (`RCA-145.E`) — Accordéon de Navigation Directe des 34 Modules dans la Barre Latérale Gauche & Ergonomie de la Vue Module

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `templates/layout.php`, `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js`
* **1. Mécanisme :**
  La barre verticale gauche ne proposait pas de liens directs vers les 34 modules de formation, obligeant l'apprenant à repasser par le catalogue à chaque changement de module, et l'en-tête de la page Module était visuellement chargé.
* **2. Hypothèse :**
  Intégrer dans la barre verticale gauche (`templates/layout.php`) un accordéon par parcours listant tous les modules (`M01`..`M29`, `SQL-01`..`SQL-05`) avec filtre rapide et état de complétion, et clarifier l'agencement de `module_view.php`.
* **3. Contre-mesure :**
  Ajout de l'arbre des 34 modules dans la sidebar gauche (`templates/layout.php`), filtre rapide dans `app.js` et refonte de l'en-tête et du sommaire latéral dans `templates/pages/module_view.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-10 dans REGISTRE_RCA.md.
Tâche : Vérifier que templates/layout.php affiche l'accordéon de navigation des 34 modules dans la barre verticale gauche (.sidebar-module-tree) et que module_view.php propose une navigation claire. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 11. RCA-LMS-11 (`RCA-146.A`) — Synchronisation 1:1 des Diapositives au Clic (Élimination du Décalage entre `.pptx` et `_slides.pdf`)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `bin/export_slides_cache.py`, `public/assets/slides_cache/*/manifest.json`, `templates/pages/module_view.php`, `src/Course/MarkdownParser.php`
* **1. Mécanisme :**
  29 des 34 modules présentaient un nombre de pages différent entre le fichier `.pptx` et le fichier `*_slides.pdf` compilé (ex: Module 01 avait une page 4 « Références & Documentation Officielle » dans le PDF absente du PPTX). L'extraction des titres par index depuis le `.pptx` décalait tous les titres à partir de la diapositive 3 par rapport aux images `slide_NNN.png` extraites du PDF : cliquer sur un titre dans le sommaire affichait donc la mauvaise image. De plus, `src=""` sur les slides `> 2` était invalide en HTML5.
* **2. Hypothèse :**
  Extraire le titre (`title`), la section (`section`) et l'aperçu textuel (`body_preview`) de chaque diapositive directement depuis la page `i` correspondante du fichier `*_slides.pdf` via PyMuPDF (`fitz`), apparier les notes de l'orateur du `.pptx` par titre normalisé, et renseigner `src="<?= Security::e($s['image_url']) ?>"` avec `loading="lazy"` sur toutes les balises `<img>`.
* **3. Contre-mesure :**
  Refonte de `bin/export_slides_cache.py`, régénération des 34 `manifest.json` (1 686 slides synchronisées 1:1), invalidation du cache `mod_v5_rca146` dans `MarkdownParser.php` et suppression de `src=""` dans `templates/pages/module_view.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-11 dans REGISTRE_RCA.md.
Tâche : Vérifier que bin/export_slides_cache.py extrait les titres directement depuis chaque page de *_slides.pdf pour garantir une correspondance 1:1 entre le titre affiché et l'image slide_NNN.png, et que module_view.php ne génère aucun attribut src="" vide. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 12. RCA-LMS-12 (`RCA-146.B`) — Affichage du Lecteur PDF Continu dans la Zone Principale (et non à la place du Menu Vertical de Droite)

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `templates/pages/module_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js`
* **1. Mécanisme :**
  Dans `.slide-deck-layout` (`grid-template-columns: minmax(0, 1fr) 290px`), `#slide-stage-html`, `#slide-stage-pdf` et `.slide-outline-sidebar` étaient trois enfants directs de la grille à 2 colonnes. Comme `.slide-stage-container` définissait `display: flex;`, l'attribut HTML `hidden` sur `#slide-stage-html` était surchargé par le CSS : lors du clic sur « Lecteur PDF Continu », `#slide-stage-html` restait visible en colonne 1 et `#slide-stage-pdf` prenait la place de la colonne 2 (290 px, colonne du menu vertical de droite), poussant le sommaire à la ligne suivante.
* **2. Hypothèse :**
  Encapsuler `#slide-stage-html` et `#slide-stage-pdf` dans un conteneur unique `<div class="slide-stage-main-col">` occupant toujours la colonne 1 de `.slide-deck-layout`, ajouter la règle globale `[hidden] { display: none !important; }` dans `public/assets/css/app.css`, et basculer explicitement `style.display` dans `switchSlideEngine()` (`public/assets/js/app.js`).
* **3. Contre-mesure :**
  Ajout de `.slide-stage-main-col` dans `templates/pages/module_view.php`, règle `[hidden] { display: none !important; }` dans `app.css` et fonction `switchSlideEngine()` dans `app.js`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-12 dans REGISTRE_RCA.md.
Tâche : Vérifier que #slide-stage-html et #slide-stage-pdf sont encapsulés dans .slide-stage-main-col (colonne 1 de .slide-deck-layout) et que [hidden] { display: none !important; } est présent dans app.css. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 13. RCA-LMS-13 (`RCA-146.C`) — Menu Vertical de Droite Sticky au Défilement & Suppression Totale des Barres de Défilement Visibles

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `public/assets/css/app.css`, `templates/pages/module_view.php`
* **1. Mécanisme :**
  1) La règle `html, body { overflow-x: hidden; }` dans `public/assets/css/app.css` créait un contexte de défilement sur `body` qui désactivait `position: sticky` sur `.reader-sidebar` et `.slide-outline-sidebar` : la barre verticale de droite disparaissait dès que l'utilisateur faisait défiler le cours vers le bas.
  2) Les conteneurs `.reader-sidebar`, `.slide-outline-sidebar`, `.toc-scrollspy-nav`, `.curriculum-stepper-list` et `.slide-outline-list` affichaient des barres de défilement (scrollbars) disgracieuses.
* **2. Hypothèse :**
  Remplacer `overflow-x: hidden` sur `html, body` par `overflow-x: clip` uniquement sur `body` pour restaurer le comportement natif `position: sticky` (`top: 76px`) tout au long du défilement, et appliquer `scrollbar-width: none !important; -ms-overflow-style: none !important;` ainsi que `::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }` sur l'ensemble des conteneurs du menu vertical de droite.
* **3. Contre-mesure :**
  Mise à jour de `public/assets/css/app.css` et remplacement du style inline `style="max-height: 76vh; overflow-y: auto;"` par la classe `.slide-outline-box` dans `templates/pages/module_view.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-13 dans REGISTRE_RCA.md.
Tâche : Vérifier que body utilise overflow-x: clip dans public/assets/css/app.css (afin que position: sticky fonctionne sur .reader-sidebar et .slide-outline-sidebar) et que scrollbar-width: none !important + ::-webkit-scrollbar { display: none !important; } masquent toute barre de défilement sur les menus verticaux de droite. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 14. RCA-LMS-14 (`RCA-146.D`) — Réduction / Extension en 1-Clic des Barres Verticales de Gauche et de Droite

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `templates/layout.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php`, `public/assets/css/app.css`, `public/assets/js/app.js`
* **1. Mécanisme :**
  L'utilisateur ne pouvait réduire ni la barre verticale de gauche (`.app-sidebar`, 298 px) ni la barre verticale de droite (`.reader-sidebar` 330 px / `.slide-outline-sidebar` 290 px) pour maximiser l'espace de lecture du cours, du diaporama 16:9 ou de l'atelier SQL.
* **2. Hypothèse :**
  Intégrer des boutons de bascule dédiés dans la barre supérieure (`#btn-toggle-left-sidebar` et `#btn-toggle-right-sidebar`), dans l'en-tête des barres latérales (`#btn-collapse-left-inline`, `.btn-toggle-right-panel`) ainsi qu'un bouton flottant de réouverture rapide (`#btn-floating-restore-right`), avec persistance dans `localStorage` (`lp_lms_left_sidebar`, `lp_lms_right_sidebar`) et raccourcis clavier (`Alt+G` / `Alt+D`).
* **3. Contre-mesure :**
  Ajout des contrôles UI dans `templates/layout.php`, `templates/pages/module_view.php` et `templates/pages/lab_view.php`, classes `body.left-sidebar-collapsed` et `body.right-sidebar-collapsed` dans `public/assets/css/app.css` et gestionnaires JS dans `public/assets/js/app.js`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-14 dans REGISTRE_RCA.md.
Tâche : Vérifier la présence de #btn-toggle-left-sidebar, #btn-toggle-right-sidebar, .btn-toggle-right-panel et #btn-floating-restore-right ainsi que des classes CSS body.left-sidebar-collapsed et body.right-sidebar-collapsed. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 15. RCA-LMS-15 (`RCA-146.E`) — Modes d'Étude « Énoncé » et « Corrigé Complet & REX » sur les 34 Ateliers Pratiques

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `src/Course/MarkdownParser.php`, `templates/pages/lab_view.php`, `public/assets/js/app.js`, `public/assets/css/app.css`
* **1. Mécanisme :**
  Contrairement aux modules de cours qui offraient plusieurs vues d'étude, la page d'atelier (`templates/pages/lab_view.php`) affichait uniquement un flux monolithique mélangeant l'énoncé et les corrigés, sans onglets dédiés permettant de consulter séparément l'Énoncé Pas-à-Pas et le Corrigé Complet / Débriefing REX.
* **2. Hypothèse :**
  Découper chaque atelier dans `MarkdownParser::parseLabFile()` (`splitLabEnonceAndCorrige()`) en deux volets distincts (`enonce_html` avec solutions Fast-Track repliées en `<details>` spoilers, et `corrige_html` regroupant les solutions des défis, le Corrigé Détaillé, l'Oracle de validation Qualiopi, la Grille d'évaluation DoD et le Débriefing REX Expert), et ajouter dans `templates/pages/lab_view.php` une barre `.view-mode-switcher` (`📋 Énoncé & Travail Pas-à-Pas`, `✅ Corrigé Complet & Débriefing REX`, `📖 Vue Intégrale`, `🌐 Rendu Web Studio`, `📕 Support PDF`) complétée du bouton `#btn-toggle-lab-solutions`.
* **3. Contre-mesure :**
  Implémentation de `splitLabEnonceAndCorrige()` dans `src/Course/MarkdownParser.php`, ajout des 5 onglets de mode et du bouton `#btn-toggle-lab-solutions` dans `templates/pages/lab_view.php` et validation sur les 34 ateliers.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-LMS-15 dans REGISTRE_RCA.md.
Tâche : Vérifier que MarkdownParser::parseLabFile() retourne enonce_html et corrige_html non vides pour les 34 labs et que templates/pages/lab_view.php expose les onglets mode=enonce, mode=corrige, mode=full, mode=html_preview et mode=pdf_preview. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## 16. RCA-LMS-16 (`RCA-146.F`) — Durcissement Sécurité End-to-End, Shim `unload -> pagehide` (`chext_loader.js`) & Règle d'Issue GitHub Systématique

* **Date de détection :** 2026-10-25
* **Composant Affecté :** `src/Core/Security.php`, `src/bootstrap.php`, `src/Course/DownloadController.php`, `public/index.php`, `src/Docker/DockerManager.php`, `AGENTS.md`
* **1. Mécanisme :**
  1) Certaines extensions Chrome injectent un script `chext_loader.js` qui enregistre `window.addEventListener('unload', ...)`. Lorsque le navigateur ou un document applique une politique restrictive sur `unload`, la console affiche `[Violation] Permissions policy violation: unload is not allowed in this document`.
  2) Les en-têtes HTTP de sécurité (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) et l'intercepteur JS `unload -> pagehide` n'étaient pas centralisés dans `src/bootstrap.php`.
  3) La règle d'ingénierie exigeant une Issue GitHub par tâche fermée avec commentaire après fusion de la PR sur les deux dépôts devait être codifiée dans `AGENTS.md`.
* **2. Hypothèse :**
  Centraliser `Security::sendSecurityHeaders()` (`Permissions-Policy: unload=*, fullscreen=(self)`) dans `src/bootstrap.php`, injecter `Security::renderUnloadShimScript()` (qui convertit silencieusement tout `addEventListener('unload', ...)` en `'pagehide'`) en tête de `<head>` dans `layout.php`, `login.php` et les aperçus HTML inline de `DownloadController.php`, durcir `admin_add_course` et `DockerManager.php`, et codifier la règle d'Issue GitHub dans `AGENTS.md`.
* **3. Contre-mesure :**
  Mise à jour de `src/Core/Security.php`, `src/bootstrap.php`, `src/Course/DownloadController.php`, `public/index.php`, `src/Docker/DockerManager.php`, `templates/layout.php`, `templates/pages/login.php` et `AGENTS.md`.
* **4. Prompt LLM :**
```text
Contexte : dépôts lightpath-training-portal et training_postgresql. Lis RCA-LMS-16 dans REGISTRE_RCA.md.
Tâche : Vérifier que Security::sendSecurityHeaders() et Security::renderUnloadShimScript() sont actifs sur toutes les pages et aperçus HTML, et que AGENTS.md impose la création et la fermeture commentée d'une Issue GitHub après chaque PR fusionnée sur les deux dépôts. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-25

---

## Fiche d'Incident RCA-147 : Enrichissement des 34 Ateliers Pratiques, Normalisation Énoncé/Corrigé et Couverture 100 % par Oracles SQL `DO $$` (`RCA-LMS-17`)

* **Composant Affecté :** `04_ateliers_et_tp/lab_*.md`, `scripts/validate_labs.py`
* **Symptôme :** 11 ateliers (`lab_09` et `lab_11` à `lab_20`) ne disposaient que d'un script de validation Bash sans bloc SQL `DO $$` exécutable dans la console SQL du LMS, et 16 ateliers plaçaient `🌟 Pour Aller Plus Loin` après le titre H2 de validation, le masquant de l'onglet Énoncé.
* **1. Mécanisme :** Les ateliers orientés infrastructure et développement (`lab_09`, `lab_11`..`lab_20`) avaient été conçus avec des assertions shell uniquement, et l'ordre des sections finales plaçait parfois `Pour Aller Plus Loin` après `## 3. Commande de Validation`.
* **2. Hypothèse :** Ajouter dans chacun des 11 ateliers un oracle SQL PostgreSQL 18 autonome (`DO $$ ... RAISE NOTICE 'VALIDATION_OK...'; END $$;`) ainsi que des défis experts dotés de blocs `* **Requête Solution :**`, et repositionner systématiquement `## 🌟 Pour Aller Plus Loin` avant le H2 de validation sur les 34 ateliers.
* **3. Contre-mesure :** Enrichissement des 34 fichiers `04_ateliers_et_tp/lab_*.md` avec 100 % de couverture d'oracles SQL `DO $$` émettant `VALIDATION_OK`, ajout des solutions de défis experts et ajout du mode `--check-markdown-labs` dans `scripts/validate_labs.py`.
* **4. Prompt LLM :**
```text
Contexte : dépôt training_postgresql (04_ateliers_et_tp/lab_*.md et scripts/validate_labs.py).
Tâche : Vérifier que les 34 ateliers possèdent chacun au moins un bloc ```sql contenant DO $$ et VALIDATION_OK, au moins 2 blocs de solution (* **Requête Solution :** / * **Corrigé Technique :**), et que la section "🌟 Pour Aller Plus Loin" précède le titre H2 de validation. Exécuter `python scripts/validate_labs.py --check-markdown-labs` (exit code 0 requis).
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-148 : Idempotence SQL des Corrigés d'Ateliers (`lab_02`, `lab_20`), Ajout des Grilles DoD (`lab_21..25`) et Synchronisation de `livret_exercices_complet.md` (`RCA-LMS-18`)

* **Composant Affecté :** `04_ateliers_et_tp/lab_02_*.md`, `lab_20_*.md`, `lab_21..25_*.md`, `livret_exercices_complet.md`
* **Symptôme :** La ré-exécution de `lab_02` échouait sur `database "logistics_db" already exists`, `lab_20` exécutait `ALTER SYSTEM SET huge_pages = 'on'` susceptible de bloquer le redémarrage d'un conteneur Docker sans HugePages hôtes, `lab_21`..`25` manquaient de grille DoD, et `livret_exercices_complet.md` était condensé par rapport aux 34 ateliers individuels.
* **1. Mécanisme :** Absence de gardes `WHERE NOT EXISTS (...) \gexec` / `DROP TABLE IF EXISTS` dans `lab_02`, absence de mode `huge_pages = 'try'` + `RESET` dans `lab_20`, omission de la section `### ✅ Grille d'Évaluation de Conformité Opérationnelle (DoD)` dans `lab_21`..`25`, et non-reconstruction du livret consolidé.
* **2. Hypothèse :** Rendre `lab_02` et `lab_20` 100 % idempotents et sûrs en conteneur Docker, ajouter les grilles DoD à 4 critères dans `lab_21`..`25`, reconstruire `livret_exercices_complet.md` à partir des 34 ateliers enrichis et recompiler les livrables `.html`, `.pdf`, `.docx`.
* **3. Contre-mesure :** Mise à jour de `lab_02`, `lab_20`, `lab_21`..`25`, reconstruction intégrale de `livret_exercices_complet.md` (10 534 lignes) et compilation via `python scripts/build_docs.py`.
* **4. Prompt LLM :**
```text
Contexte : dépôt training_postgresql (04_ateliers_et_tp/).
Tâche : Vérifier l'idempotence SQL de lab_02 (`WHERE NOT EXISTS` / `CREATE TABLE IF NOT EXISTS`), la directive `ALTER SYSTEM SET huge_pages = 'try'` et `ALTER SYSTEM RESET huge_pages` dans lab_20, la présence de la Grille DoD dans lab_21 à lab_25, et la synchronisation de `livret_exercices_complet.md` via `python scripts/build_docs.py --check-sync`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-149 : Correction de l'Ordre d'Initialisation PDO et de la Visibilité `protected` dans `PdoStorage` / `JsonStorage` (`RCA-LMS-19`)

* **Composant Affecté :** `lightpath-training-portal/src/Core/PdoStorage.php`, `src/Core/JsonStorage.php`
* **Symptôme :** `PdoStorage` appelait `parent::__construct()` avant d'initialiser `$this->pdo` et `readFile()`/`writeFile()` étaient `private` dans `JsonStorage`, empêchant `PdoStorage` de persister les données dans la table SQL `lms_kv_store`.
* **1. Mécanisme :** En PHP, une méthode `private` de la classe parente ne peut pas être redéfinie par polymorphisme dans une sous-classe, et l'appel à `ensureDefaultUsers()` dans le constructeur parent s'exécutait avant la création de l'instance `PDO`.
* **2. Hypothèse :** Passer `readFile()` et `writeFile()` en `protected` dans `JsonStorage`, initialiser `$this->pdo` et la table `lms_kv_store` en premier dans `PdoStorage::__construct()`, et implémenter `readFile()`/`writeFile()` dans `PdoStorage` avec repli transparent sur fichier JSON.
* **3. Contre-mesure :** Refactorisation de `src/Core/JsonStorage.php` et `src/Core/PdoStorage.php`, validée par un test unitaire `PDO('sqlite::memory:')` dans `tests/run_tests.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal (src/Core/JsonStorage.php et src/Core/PdoStorage.php).
Tâche : Vérifier que readFile() et writeFile() sont `protected` dans JsonStorage, que PdoStorage initialise $this->pdo et la table lms_kv_store avant d'appeler parent::__construct(), et que PdoStorage persiste correctement les clés JSON en base SQLite `:memory:` dans `php tests/run_tests.php`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-150 : Fiabilisation de `LabSqlRunner` — Sélecteur Multi-Bases (`formation_pg` / `dvdrental`), Préservation de l'Oracle `DO $$` et Exécuteur de Corrigé `runLabSolution()` (`RCA-LMS-20`)

* **Composant Affecté :** `lightpath-training-portal/src/Docker/LabSqlRunner.php`, `templates/pages/lab_view.php`, `public/index.php`
* **Symptôme :** `LabSqlRunner::executeSql()` forçait `-d formation_pg` (mettant en échec `lab_sql_01`..`05` qui ciblent `dvdrental`) et les apprenants/formateurs ne pouvaient pas exécuter et valider en 1 clic le corrigé SQL complet d'un atelier.
* **1. Mécanisme :** Base cible codée en dur dans la commande `psql` et absence d'extracteur/exécuteur des blocs SQL de corrigé et d'oracle `DO $$` depuis le fichier Markdown de l'atelier.
* **2. Hypothèse :** Ajouter une liste blanche de bases (`['formation_pg', 'dvdrental', 'postgres']`), la détection automatique `detectTargetDatabase()`, la validation d'oracle Markdown `validateLabOracle()` et l'exécuteur complet de corrigé `runLabSolution()` exposé via `?action=api_run_lab_solution`.
* **3. Contre-mesure :** Mise à jour de `src/Docker/LabSqlRunner.php`, ajout du sélecteur de base et du bouton « 🧪 Tester & Valider le Corrigé Officiel » dans `templates/pages/lab_view.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal (src/Docker/LabSqlRunner.php et templates/pages/lab_view.php).
Tâche : Vérifier que LabSqlRunner prend en charge le paramètre `$database` (liste blanche formation_pg, dvdrental, postgres), détecte automatiquement `dvdrental` pour `lab_sql_01..05`, et implémente `extractSolutionSqlBlocks()`, `validateLabOracle()` et `runLabSolution()`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-151 : Durcissement de `MarkdownParser::splitLabEnonceAndCorrige()` pour Tous les Marqueurs de Solutions et Préservation des Défis/DoD (`RCA-LMS-21`)

* **Composant Affecté :** `lightpath-training-portal/src/Course/MarkdownParser.php`
* **Symptôme :** L'expression régulière de `splitLabEnonceAndCorrige()` ne capturait que `**Requête Solution :**` et ignorait `**Commandes Solution :**`, `**Solution Analytique :**`, `**Configuration Solution :**`, `**Requête Démonstration :**` et `**Corrigé Technique :**`.
* **1. Mécanisme :** Regex trop restrictive limitée à une seule variante d'intitulé de solution et découpage brut au premier titre H2 de validation.
* **2. Hypothèse :** Étendre la regex de capture des solutions dans `splitLabEnonceAndCorrige()` à toutes les variantes d'intitulés de corrigé et réinjecter toute section `Pour Aller Plus Loin` ou `Grille d'Évaluation` dans `enonce_md` si elle se trouve après le H2 de validation.
* **3. Contre-mesure :** Mise à jour de `MarkdownParser::splitLabEnonceAndCorrige()` et incrémentation de la clé de cache `lab_v7_rca151`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal (src/Course/MarkdownParser.php).
Tâche : Vérifier que `splitLabEnonceAndCorrige()` encapsule en `<details>` toutes les variantes de marqueurs de solutions (`Requête Solution`, `Commandes Solution`, `Solution Analytique`, `Configuration Solution`, `Requête Démonstration`, `Corrigé Technique`) et conserve `Pour Aller Plus Loin` dans `enonce_md`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-152 : Exactitude de `DockerManager` (Statut Réel des Conteneurs & Amorçage Sakila `dvdrental`) et Liaison des Aide-Mémoires aux 34 Ateliers (`RCA-LMS-22`)

* **Composant Affecté :** `lightpath-training-portal/src/Docker/DockerManager.php`, `src/Course/CourseRepository.php`, `templates/pages/documents.php`
* **Symptôme :** `DockerManager::getCachedUserContainerStatus()` initialisait `running => true` même lorsqu'aucun conteneur individuel n'était démarré, `bootstrapContainerSchema()` ne créait pas la base `dvdrental` Sakila, et `CourseRepository` ne liait pas les fiches mémo (`linked_cheatsheet_id`) aux ateliers.
* **1. Mécanisme :** Valeur par défaut optimiste dans le cache JSON Docker, script SQL de bootstrap limité à 2 tables dans `formation_pg`, et liaison des cheatsheets restreinte aux modules.
* **2. Hypothèse :** Initialiser `running => false` par défaut dans `getCachedUserContainerStatus()`, enrichir `bootstrapContainerSchema()` pour créer et peupler `dvdrental` (`film`, `actor`, `inventory`, `rental`, `payment`, `customer`, `category`, `address`) + `formation_pg`, et lier chaque atelier à sa fiche `cheatsheet_lab_*` dans `CourseRepository`.
* **3. Contre-mesure :** Mise à jour de `DockerManager.php`, `CourseRepository.php` et ajout d'un lecteur Markdown intégré des fiches mémo dans `templates/pages/documents.php` honorant `$_GET['id']`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal (src/Docker/DockerManager.php, src/Course/CourseRepository.php, templates/pages/documents.php).
Tâche : Vérifier que `getCachedUserContainerStatus()` retourne `running => false` par défaut, que `bootstrapContainerSchema()` initialise `dvdrental` (Sakila) et `formation_pg`, et que chaque lab dans `CourseRepository` possède `linked_cheatsheet_id`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-153 : Conformité Qualiopi LMS — Test de Positionnement Initial, Feuille d'Émargement Numérique, Temps Actif et Enquête de Satisfaction à Chaud (`RCA-LMS-23`)

* **Composant Affecté :** `lightpath-training-portal/src/Course/LmsCatalog.php`, `src/Core/JsonStorage.php`, `templates/pages/{diagnostic,attendance,satisfaction}.php`, `public/index.php`
* **Symptôme :** Le portail LMS ne proposait pas d'interface interactive pour le test de positionnement pré-formation (Indicateur Qualiopi 8), la feuille d'émargement numérique par demi-journée avec traçabilité du temps actif (Indicateur 12), ni le questionnaire de satisfaction à chaud (Indicateur 30).
* **1. Mécanisme :** Ces dispositifs existaient sous forme de documents statiques Markdown/PDF dans `01_programme_et_referentiel/` mais n'étaient pas digitalisés ni persistés dans le LMS.
* **2. Hypothèse :** Créer `LmsCatalog.php`, étendre `JsonStorage` et ajouter les vues `?page=diagnostic` (15 QCM sur 5 domaines + recommandation de parcours), `?page=attendance` (signature numérique Matin/Après-midi + heartbeat JS 60 s `api_heartbeat`) et `?page=satisfaction` (6 critères étoilés + NPS).
* **3. Contre-mesure :** Implémentation complète des 3 modules Qualiopi dans `lightpath-training-portal` avec persistance atomique et agrégation dans le tableau de bord formateur.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal.
Tâche : Vérifier le fonctionnement des routes `?page=diagnostic`, `?page=attendance` (`?action=api_heartbeat`, `?action=sign_attendance`) et `?page=satisfaction` ainsi que leurs méthodes de persistance dans `JsonStorage`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-154 : Examen Final Certifiant Chronométré (40 QCM Pondérés par Domaine DBA) et Durée Dynamique sur le Certificat (`RCA-LMS-24`)

* **Composant Affecté :** `lightpath-training-portal/templates/pages/{exam,certificate}.php`, `src/Course/LmsCatalog.php`, `public/index.php`
* **Symptôme :** L'évaluation sommative finale n'était pas disponible sous forme d'examen chronométré interactif dans le LMS, et `templates/pages/certificate.php` affichait une durée statique codée en dur (`35 heures (5 jours)`).
* **1. Mécanisme :** Absence de moteur d'examen sommatif chronométré et chaîne statique en dur à la ligne 85 de `certificate.php`.
* **2. Hypothèse :** Implémenter `?page=exam` avec 40 questions pondérées couvrant les 5 domaines DBA PostgreSQL 18, un chronomètre de 45 minutes (`tabular-nums`), un seuil de certification à 75 % et un corrigé détaillé par question, et calculer dynamiquement le volume horaire et les jours dans `certificate.php`.
* **3. Contre-mesure :** Création de `templates/pages/exam.php`, ajout de la banque de 40 questions sommatives dans `LmsCatalog.php`, et calcul dynamique de la durée + mention de l'examen final dans `templates/pages/certificate.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal (templates/pages/exam.php et templates/pages/certificate.php).
Tâche : Vérifier que `?page=exam` propose 40 questions pondérées avec chronomètre 45 min et correction détaillée, et que `certificate.php` calcule dynamiquement la durée du parcours et affiche le score de l'examen final.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-155 : Expérience d'Apprentissage Avancée LMS — Flashcards SRS & Glossaire PG 18, Recherche Globale `Ctrl+K`, Favoris/Reprise/Export Notes, Q&A, Badges DBA & Exports SCORM 1.2 (`RCA-LMS-25`)

* **Composant Affecté :** `lightpath-training-portal/src/Core/Gamification.php`, `src/Course/LmsCatalog.php`, `templates/pages/{flashcards,search,dashboard,admin}.php`, `templates/layout.php`
* **Symptôme :** Le LMS ne disposait pas des fonctionnalités d'engagement et d'interopérabilité d'un LMS standard d'entreprise (Flashcards SRS, Glossaire interactif, recherche plein texte `Ctrl+K`, favoris, reprise automatique, export `.md` des notes, Q&A par module/lab, badges XP et exports CSV Qualiopi / SCORM 1.2 `imsmanifest.xml`).
* **1. Mécanisme :** Portail initialement centré sur la lecture des modules, le mode diaporama 16:9 et la console Docker SQL sans couche de révision active ni exports d'interopérabilité LMS.
* **2. Hypothèse :** Implémenter `?page=flashcards` (42 flashcards SRS + glossaire de 52 termes PG 18 + lecteur d'aide-mémoires), `?page=search` + raccourci `Ctrl+K`, les favoris et la bannière de reprise sur le dashboard, l'export Markdown des notes (`?action=export_notes_md`), le fil Q&A par module/lab (`?action=api_post_discussion`), les 8 badges DBA & XP (`Gamification.php`) et les exports Admin CSV Qualiopi + manifeste SCORM 1.2 (`?action=export_scorm_manifest`).
* **3. Contre-mesure :** Déploiement complet des fonctionnalités dans `lightpath-training-portal` avec navigation dédiée dans la barre latérale gauche (`templates/layout.php`).
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal.
Tâche : Vérifier la présence et le fonctionnement de `?page=flashcards`, `?page=search` (`?action=api_search`), `?action=api_toggle_bookmark`, `?action=export_notes_md`, `?action=api_post_discussion`, `Gamification.php`, `?action=export_qualiopi_csv` et `?action=export_scorm_manifest`.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-156 : Couverture de Tests Automatisés à 100 % — `validate_labs.py --check-markdown-labs`, Tests CLI `pgscripts/`, `pgexamples/` et Suite TDD LMS (`RCA-LMS-26`)

* **Composant Affecté :** `scripts/validate_labs.py`, `tests/test_rca_contracts.py`, `pgscripts/tests/test_cli_scripts.py`, `lightpath-training-portal/tests/run_tests.php`
* **Symptôme :** Plusieurs composants n'étaient pas couverts par des tests unitaires automatisés (`PdoStorage` avec base SQLite réelle, `LabSqlRunner::runLabSolution`, les 6 scripts CLI autonomes de `pgscripts/` et la structure Markdown/SQL des 34 fichiers `lab_*.md`).
* **1. Mécanisme :** Les tests existants de `pgscripts` couvraient les analyseurs Python (`pg_memory_tuner`, `pg_explain_analyzer`, etc.) mais pas les 6 scripts CLI principaux (`pg_bloat_check.py`, `pg_cluster_state.py`, `pg_long_transactions.py`, `pg_quick_audit.py`, `pg_replication_lag.py`, `pg_sql_runner.py`), et `run_tests.php` testait 128 assertions de base.
* **2. Hypothèse :** Créer `pgscripts/tests/test_cli_scripts.py` (tests unitaires avec mock `psycopg`), ajouter `--check-markdown-labs` dans `scripts/validate_labs.py`, étendre `tests/test_rca_contracts.py` jusqu'à `RCA-156`, et porter `lightpath-training-portal/tests/run_tests.php` à plus de 300 assertions couvrant toutes les classes et fonctionnalités.
* **3. Contre-mesure :** Ajout de `pgscripts/tests/test_cli_scripts.py`, mise à jour de `training_postgresql/tests/test_rca_contracts.py` (`RCA-01`..`RCA-156`) et extension complète de `lightpath-training-portal/tests/run_tests.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôts training_postgresql, pgscripts et lightpath-training-portal.
Tâche : Exécuter `python scripts/validate_labs.py --check-markdown-labs`, `pytest tests -q` dans `training_postgresql`, `pytest tests -q` dans `pgscripts`, et `php tests/run_tests.php` dans `lightpath-training-portal`. Exiger 100 % de réussite sur les 4 suites.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-157 (`RCA-LMS-27` & `RCA-LMS-28`) : Installation & Durcissement du Serveur Officiel `@playwright/mcp`, Campagne Automatisée E2E LMS (59 Assertions), Correction Responsive Mobile `375x812` & Conformité DOM `autocomplete`

* **Date de détection :** 2026-10-26
* **Composant Affecté :** `config/app.php`, `templates/pages/login.php`, `templates/pages/profile.php`, `templates/pages/admin.php`, `templates/pages/certificate.php`, `public/assets/css/app.css`, `config/playwright-mcp.config.json`, `tests/e2e/run_playwright_campaign.mjs`
* **1. Mécanisme :**
  1) **Débordements Horizontaux Mobile (`375x812` — `RCA-LMS-27`) :** Lors de la campagne de tests multi-viewports pilotée par `@playwright/mcp` (`browser_resize(375, 812)` + `browser_evaluate` sur les boîtes englobantes `getBoundingClientRect().right`), plusieurs débordements horizontaux masqués par `body { overflow-x: clip; }` ont été identifiés sur l'écran Mobile `375 px` :
     - Sur `templates/pages/login.php`, la grille inline utilisait `grid-template-columns: repeat(auto-fit, minmax(360px, 1fr))` dans un conteneur avec `padding: 2rem` (`32 px` de chaque côté), poussant le bord droit de la carte à `right = 393 px > 375 px` (18 px tronqués).
     - Dans `public/assets/css/app.css`, `.topbar-left-group` ne passait pas en `flex-wrap: wrap` sur mobile, `.topbar-search` imposait `min-width: 220px`, `.card-grid` utilisait `minmax(330px, 1fr)` (débordant dans `.docker-cockpit`), `.reader-grid > *` n'imposait pas `min-width: 0` (provoquant l'expansion de la colonne tableau sur `admin.php?tab=users`), et `templates/pages/certificate.php` utilisait un `padding: 3rem` fixe.
     - La classe `.btn` imposait `white-space: nowrap` sans adaptation mobile (`@media (max-width: 640px)`), faisant déborder les boutons longs (`right = 388 px > 375 px` sur `lab_view.php`).
  2) **Avertissements DOM `autocomplete`, Isolation `LMS_DATA_DIR` & Durcissement MCP (`RCA-LMS-28`) :** L'audit `browser_console_messages` (`level: "debug"`) de `@playwright/mcp` a révélé des avertissements DOM Chromium `[DOM] Input elements should have autocomplete attributes` sur `login.php`, `profile.php` et `admin.php`. De plus, `config/app.php` ne lisait pas `getenv('LMS_DATA_DIR')`, empêchant le harnais E2E d'isoler ses écritures (`users.json`, `audit_log.json`) dans un répertoire temporaire, et les configurations globales `mcp_config.json` devaient inclure `--browser=chrome --headless --isolated --block-service-workers --viewport-size=1440x900`.
* **2. Hypothèse :**
  Remplacer `minmax(360px, 1fr)` par `minmax(min(100%, 340px), 1fr)` dans `templates/pages/login.php`, utiliser `minmax(min(100%, 330px), 1fr)` sur `.card-grid`, ajouter `.reader-grid > * { min-width: 0; }` et `@media (max-width: 640px)` dans `public/assets/css/app.css`, fluidifier `certificate.php`, ajouter les attributs W3C `autocomplete` dans `login.php`, `profile.php` et `admin.php`, supporter `LMS_DATA_DIR` dans `config/app.php`, et valider les 59 assertions E2E MCP dans `tests/e2e/run_playwright_campaign.mjs`.
* **3. Contre-mesure :**
  Mise à jour de `config/app.php`, `templates/pages/login.php`, `templates/pages/profile.php`, `templates/pages/admin.php`, `templates/pages/certificate.php`, `public/assets/css/app.css`, création de `config/playwright-mcp.config.json` et de `tests/e2e/run_playwright_campaign.mjs` (59 assertions E2E MCP JSON-RPC 2.0 incluant la vérification active de `allowedOrigins` et d'une chaîne continue de 255 caractères sans espace sur mobile), et ajout des tests de contrat dans `tests/run_tests.php` (134 assertions).
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-157 (RCA-LMS-27 & RCA-LMS-28) dans REGISTRE_RCA.md.
Tâche : Vérifier que config/app.php supporte LMS_DATA_DIR, que templates/pages/login.php utilise minmax(min(100%, 340px), 1fr), que public/assets/css/app.css contient minmax(min(100%, 330px), 1fr), .reader-grid > * { min-width: 0; } et @media (max-width: 640px), que tous les champs password/email de login.php, profile.php et admin.php possèdent leurs attributs autocomplete W3C, que config/playwright-mcp.config.json active isolated=true et allowUnrestrictedFileAccess=false, et que php tests/run_tests.php (134 assertions) ainsi que node tests/e2e/run_playwright_campaign.mjs (59 assertions) passent à 100%.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-26

---

## Fiche d'Incident RCA-158 : Rendu Markdown Inline Sécurisé, Extraction Précise des Métadonnées Pédagogiques (Module 10, Durées, Bloom) & Éradication des Backticks Bruts sur les 152 Documents LMS

* **Date de détection :** 2026-10-26
* **Composant Affecté :** `src/Core/Security.php`, `src/Course/CourseRepository.php`, `src/Course/MarkdownParser.php`, `templates/pages/{catalog,diagnostic,documents,exam,flashcards,module_view,search}.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  1) Le titre H1 du Module 10 était masqué ou pollué par les commentaires Python `# ...` présents dans les blocs de code en tête de fichier.
  2) Les durées des modules capturaient parfois des phrases textuelles issues du corps Markdown plutôt qu'une valeur normalisée (`Xh`).
  3) Les niveaux de compétence Bloom n'étaient pas extraits de manière déterministe pour tous les modules.
  4) Plusieurs documents Markdown contenaient des backticks bruts non interprétés dans les tableaux ou textes inline sans balise `<code>`.
* **2. Hypothèse :**
  Implémenter `Security::formatInlineText()` pour transformer en toute sécurité `code` et `**gras**` avec échappement XSS, durcir les regex d'extraction du titre H1, de la durée et du niveau Bloom dans `CourseRepository.php`, et enrichir `MarkdownParser.php` pour parser correctement les tableaux avec pipes échappés et neutraliser les backticks orphelins.
* **3. Contre-mesure :**
  Ajout de `Security::formatInlineText()`, refonte de l'extraction des métadonnées dans `CourseRepository.php`, sécurisation du parsing des tableaux et suppression des backticks bruts dans `MarkdownParser.php`.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-158 dans REGISTRE_RCA.md.
Tâche : Vérifier que Security::formatInlineText() est utilisé sur les vues de contenu, que CourseRepository extrait correctement les durées et niveaux Bloom sans pollution de titre, et que 0 backtick brut ne subsiste hors <code>/<pre> sur les 152 documents LMS. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-27

---

## Fiche d'Incident RCA-159 (`RCA-LMS-29`) : Alignement des Slugs de Recommandation de Parcours du Diagnostic Qualiopi avec `config/courses.php`

* **Date de détection :** 2026-10-26
* **Composant Affecté :** `src/Course/LmsCatalog.php`, `templates/pages/diagnostic.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  Dans `src/Course/LmsCatalog.php::evaluateDiagnostic()`, les clés de parcours retournées dans `$recommendedTrack` étaient `sql-debutant`, `dba-exploitation`, et `dba-avance`. Or, dans `config/courses.php`, les 4 parcours certifiants portent les identifiants officiels `fondamentaux-sql`, `cursus-complet`, `performance-tuning`, et `ingenierie-specialisee`. Lorsque l'apprenant complétait son positionnement initial et cliquait sur le bouton "Accéder au Parcours Recommandé" (`?page=catalog&track=...`), le catalogue filtrait sur une clé inexistante, affichant un catalogue vide. De plus, `tests/run_tests.php` testait uniquement l'égalité avec l'ancienne valeur `dba-avance` sans vérifier l'existence de la clé dans le catalogue.
* **2. Hypothèse :**
  Mapper les 3 tranches de score du diagnostic sur les identifiants officiels de parcours :
  - Score `< 45%` : `recommended_track = 'fondamentaux-sql'`, titre `'Parcours 4 : Fondamentaux & Pratique Approfondie du Langage SQL:2023 (Modules SQL 01 à 05)'`.
  - Score `45% à 74%` : `recommended_track = 'cursus-complet'`, titre `'Parcours 1 : Cursus Complet PostgreSQL 18 — Administration, Cloud, DevOps & Dév (Modules 01 à 20)'`.
  - Score `>= 75%` : `recommended_track = 'performance-tuning'`, titre `'Parcours 2 : Spécialisation Performance Tuning & Internals — Protocole RALPH (Modules 21 à 25)'`.
  Fournir également les clés de compatibilité `recommended_course_id` et `recommended_course_slug`, et tester exhaustivement les 3 scénarios dans `tests/run_tests.php` en affirmant l'appartenance à `array_keys($pgCourse['tracks'])`.
* **3. Contre-mesure :**
  Modification de `src/Course/LmsCatalog.php` (lignes 265-295) pour assigner les identifiants et titres officiels, ajout de `recommended_course_id` et `recommended_course_slug`, et extension des tests dans `tests/run_tests.php` pour couvrir les 3 paliers et garantir la résolution de parcours dans le catalogue.
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-159 dans REGISTRE_RCA.md.
Tâche : Vérifier dans src/Course/LmsCatalog.php que evaluateDiagnostic() retourne 'fondamentaux-sql' (<45%), 'cursus-complet' (<75%) et 'performance-tuning' (>=75%), avec recommended_course_id et recommended_course_slug alignés. Vérifier dans tests/run_tests.php que les 3 slugs existent dans config/courses.php. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-27

---

## Fiche d'Incident RCA-160 (`RCA-LMS-30`) : Audit Qualité & Sécurité `/ponytail-audit` et Remédiation `/dev-team` du Portail LMS (Verrouillage Concurrent `flock`, Anti-Injection CSV, Rate-Limiting `(email, IP)`, `StorageInterface` & UTF-8 Search)

* **Date de détection :** 2026-10-30
* **Composant Affecté :** `src/Core/StorageInterface.php`, `src/Core/Security.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php`, `src/Core/Auth.php`, `src/Course/DownloadController.php`, `src/Course/CourseRepository.php`, `public/index.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  L'audit `/ponytail-audit` suivi de la session d'architecture `/dev-team` (PM, Architect, Developer, QA) a identifié 9 axes de durcissement pour la tenue en charge multi-stagiaires et la sécurité applicative :
  1) **Concurrence Read-Modify-Write (`JsonStorage`) :** Les 16 méthodes de mutation appelaient `readFile()` puis `writeFile()` sans maintenir un verrou exclusif durant la lecture et la modification en mémoire, exposant aux pertes de mises à jour (Lost Update / TOCTOU) lorsque 15 stagiaires valident simultanément un quiz ou un émargement.
  2) **Anti-Injection CSV Formules (`export_qualiopi_csv`) :** Les champs texte libres exportés en CSV (nom, commentaire de satisfaction) n'étaient pas neutralisés contre l'interprétation de formules Excel/LibreOffice (`=`, `+`, `-`, `@`, `\t`, `\r`).
  3) **Rate-Limiting d'Authentification (`Auth`) :** Le formulaire de connexion `?action=login` ne limitait pas les tentatives d'échec par couple `(email, IP)` (important en salle de formation derrière un NAT partagé).
  4) **Garde-fou Path Boundary (`DownloadController`) :** `resolveDeliverable()` utilisait `str_starts_with($candidate, $root)` au lieu de `Security::isPathInside($candidate, $root)` et utilisait le slug par défaut `'postgresql-18-mastery'` au lieu de `'postgresql-18'`.
  5) **Contrat `StorageInterface` incomplet :** L'interface `StorageInterface` ne déclarait que 10 méthodes alors que `JsonStorage` et `PdoStorage` en implémentaient 29.
  6) **Validation `half_day` (`signAttendance`) :** L'émargement n'imposait pas une liste blanche stricte des demi-journées autorisées (`J1_AM`..`J5_PM`).
  7) **Validation `return_page` & bornage `api_post_discussion` :** Le contrôleur ne validait pas `$returnPage` contre `['module', 'lab']` ni la non-vacuité du message après nettoyage.
  8) **Sûreté UTF-8 dans `CourseRepository::search()` sans `mbstring` :** Lorsque l'extension `mbstring` n'est pas chargée en CLI, `substr()` pouvait couper un caractère UTF-8 multi-octets en deux et faire échouer `json_encode()`.
  9) **Écritures disque redondantes & clé dupliquée :** `recordLastVisited()` réécrivait `progress.json` même si la cible n'avait pas changé (depuis moins de 60 s), et `saveFlashcardConfidence()` écrivait dans deux clés (`flashcards_srs` et `flashcards`).
* **2. Hypothèse :**
  Introduire `JsonStorage::mutateFile()` verrouillant un fichier `.lock` dédié via `flock(LOCK_EX)` dans un bloc `try ... finally` (et sa surcharge transactionnelle `BEGIN` / `COMMIT` / `ROLLBACK` dans `PdoStorage`), ajouter `Security::sanitizeCsvCell()` et `Security::isValidHalfDayKey()`, implémenter le rate-limiting par `(email, IP)` dans `Auth`, utiliser `Security::isPathInside()` dans `DownloadController`, compléter les 19 signatures de `StorageInterface`, implémenter `CourseRepository::extractUtf8SafeSnippet()` (nettoyant les octets UTF-8 orphelins de tête et de queue sans dépendre de `mbstring`), et supprimer les écritures redondantes.
* **3. Contre-mesure :**
  Implémentation complète des 9 correctifs dans `src/Core/{StorageInterface,Security,JsonStorage,PdoStorage,Auth}.php`, `src/Course/{DownloadController,CourseRepository}.php`, `public/index.php` et ajout de la Section 14 de tests TDD dans `tests/run_tests.php` (portant la suite à 201 assertions).
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-160 (RCA-LMS-30) dans REGISTRE_RCA.md.
Tâche : Vérifier que StorageInterface déclare les 29 méthodes du contrat LMS, que JsonStorage::mutateFile() utilise flock(LOCK_EX) sur un fichier .lock dédié dans un bloc try...finally, que PdoStorage::mutateFile() utilise une transaction PDO, que Security::sanitizeCsvCell() et Security::isValidHalfDayKey() sont actifs, que Auth::isLoginRateLimited() limite par (email, IP), que DownloadController utilise Security::isPathInside(), et que CourseRepository::extractUtf8SafeSnippet() garantit un JSON UTF-8 valide même sans mbstring. Valide avec php tests/run_tests.php (201 assertions attendues).
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-30

---

## Fiche d'Incident RCA-161 (`RCA-LMS-31`) : Revue Pédagogique `/teamwork` — Enrichissement des Flashcards SRS (48 Cartes) & du Glossaire PG 18 (56 Termes) pour les Modules Spécialisés (`M14`, `M19`, `M26`..`M29`)

* **Date de détection :** 2026-10-30
* **Composant Affecté :** `src/Course/LmsCatalog.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  Lors de la revue pédagogique `/teamwork` croisant les 34 modules de cours, les 34 ateliers pratiques, les 69 aide-mémoires et le catalogue LMS (`src/Course/LmsCatalog.php`), il a été constaté que les modules spécialisés d'ingénierie (`M14` CloudNativePG, `M19` `pgcopydb`, `M26` Partitionnement déclaratif, `M27` `pgBadger`, `M28` `postgres_fdw`, `M29` `pg_auto_failover`) étaient couverts par le quiz de positionnement, l'examen final et les aide-mémoires, mais ne disposaient pas de cartes de révision dédiées dans le paquet de Flashcards SRS (`getFlashcards()`) ni d'entrées dédiées dans le Glossaire technique (`getGlossary()`).
* **2. Hypothèse :**
  Enrichir `LmsCatalog::getFlashcards()` avec 6 nouvelles cartes SRS (`fc_43` à `fc_48` couvrant `M14`, `M19`, `M26`, `M27`, `M28`, `M29`, portant le total à 48 cartes) et `LmsCatalog::getGlossary()` avec 4 nouvelles définitions d'architecture (`CloudNativePG`, `pg_auto_failover`, `pgBadger`, `pgcopydb`, portant le total à 56 entrées triées alphabétiquement), en harmonisant simultanément les supports et ateliers sources dans `training_postgresql`.
* **3. Contre-mesure :**
  Mise à jour de `src/Course/LmsCatalog.php` (48 flashcards SRS et 56 termes de glossaire) et vérification par `tests/run_tests.php` (`201/201` assertions validées).
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-161 (RCA-LMS-31) dans REGISTRE_RCA.md.
Tâche : Vérifier dans src/Course/LmsCatalog.php que getFlashcards() retourne au moins 48 cartes couvrant M14, M19, M26, M27, M28 et M29 (fc_43..fc_48) et que getGlossary() retourne au moins 56 termes incluant CloudNativePG, pg_auto_failover, pgBadger et pgcopydb. Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-30

---

## Fiche d'Incident RCA-162 (`RCA-LMS-32`) : Audit Pédagogique `/ponytail-audit` & Remédiation `/teamwork` — Rééquilibrage A/B/C/D de l'Examen Final (10/10/10/10) et du Diagnostic (4/4/4/3), Tagging `'module'` et Couverture `Parcours 4 (SQL-01..05)` des 53 Flashcards SRS

* **Date de détection :** 2026-10-31
* **Composant Affecté :** `src/Course/LmsCatalog.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  L'audit qualité `/ponytail-audit` du contenu pédagogique a mis en évidence trois vulnérabilités d'évaluation et de couverture SRS dans `src/Course/LmsCatalog.php` :
  1) **Biais critique de bonnes réponses sur l'Examen Final de Certification (`getFinalExamQuestions()`) :** Sur les 40 questions (`exam_01` à `exam_40`), 39 avaient la bonne réponse positionnée sur l'option `'A'` (`{"A": 39, "B": 1, "C": 0, "D": 0}`). Un stagiaire cochant systématiquement `'A'` sans lire les énoncés obtenait `39/40` (`97,5 %`) et validait la certification (seuil `75 %`).
  2) **Déséquilibre A/B/C/D du Diagnostic Pré-Formation (`getDiagnosticQuestions()`) :** Sur les 15 questions (`diag_01` à `diag_15`), la répartition était `{"A": 7, "B": 6, "C": 2, "D": 0}` (`0` réponse `'D'` et `87 %` de réponses `'A'` ou `'B'`).
  3) **Absence de tag `'module'` et de couverture `Parcours 4 (SQL-01..05)` dans les Flashcards SRS (`getFlashcards()`) :** Les 48 flashcards existantes (`fc_01`..`fc_48`) ne comportaient pas de champ `'module'` normalisé (`M01`..`M29`) et le Parcours 4 (`SQL-01` à `SQL-05` — SQL:2023) ne disposait d'aucune carte SRS dédiée.
* **2. Hypothèse :**
  1) Permuter les propositions `'A'`, `'B'`, `'C'`, `'D'` et mettre à jour `'correct'` / `'answer'` sur les 40 questions de `LmsCatalog::getFinalExamQuestions()` pour atteindre une distribution exacte **10 `'A'`, 10 `'B'`, 10 `'C'`, 10 `'D'`** (soit 2 réponses de chaque lettre dans chacun des 5 domaines DBA, plafonnant toute réponse mono-lettre à `25 %`).
  2) Permuter les propositions et mettre à jour `'correct'` / `'answer'` sur `LmsCatalog::getDiagnosticQuestions()` pour obtenir une distribution exacte **4 `'A'`, 4 `'B'`, 4 `'C'`, 3 `'D'`**.
  3) Ajouter l'attribut `'module'` (`M01`..`M29`) sur les 48 flashcards existantes et créer 5 nouvelles flashcards (`fc_49` à `fc_53`, `'category' => 'SQL:2023'`, `'module' => 'SQL-01'` à `'SQL-05'`) couvrant `GENERATED ALWAYS AS IDENTITY` / `UNIQUE NULLS NOT DISTINCT`, la logique ternaire `NULL` / `IS DISTINCT FROM` / `WITH TIES`, les jointures `LATERAL` / `NOT EXISTS`, `GROUPING SETS` / `ROLLUP` / `CUBE` / `FILTER`, et le fenêtrage `ROWS/RANGE/GROUPS` + `WITH RECURSIVE` (`SEARCH` / `CYCLE`).
* **3. Contre-mesure :**
  Mise à jour de `src/Course/LmsCatalog.php` (rééquilibrage 10/10/10/10 de l'examen final, rééquilibrage 4/4/4/3 du diagnostic initial, tagging `'module'` et ajout de `fc_49`..`fc_53` portant le total à 53 flashcards SRS) et ajout de la Section 15 de tests TDD dans `tests/run_tests.php` (`265/265` assertions passées).
* **4. Prompt LLM :**
```text
Contexte : dépôt lightpath-training-portal. Lis RCA-162 (RCA-LMS-32) dans REGISTRE_RCA.md.
Tâche : Vérifier dans src/Course/LmsCatalog.php que :
1) getFinalExamQuestions() retourne 40 questions avec exactement 10 'A', 10 'B', 10 'C' et 10 'D' (cocher 'A' partout donne 25% et passed=false).
2) getDiagnosticQuestions() retourne 15 questions avec exactement 4 'A', 4 'B', 4 'C' et 3 'D'.
3) getFlashcards() retourne >= 53 cartes dotées chacune d'une clé 'module' valide (M01..M29 ou SQL-01..SQL-05) incluant fc_49..fc_53 pour le Parcours 4 SQL:2023.
Valide avec php tests/run_tests.php.
```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-31

---

## Fiche d'Incident RCA-163 (`RCA-LMS-33`) : Audit `/ponytail-audit` de la Plateforme LMS (7 Correctifs) & Implémentation des 12 Fonctionnalités LMS Entreprise & Qualiopi (Axes A–D)

* **Date de détection :** 2026-10-31
* **Issues GitHub associées :** `lightpath-training-portal#23`, `training_postgresql#100`
* **Composant Affecté :** `src/Core/{Security,StorageInterface,JsonStorage,PdoStorage}.php`, `src/Course/{CourseRepository,DownloadController}.php`, `src/Docker/{DockerManager,LabSqlRunner}.php`, `public/index.php`, `public/assets/js/app.js`, `templates/layout.php`, `templates/pages/{module_view,lab_view,documents,flashcards,attendance,satisfaction,exam,certificate,verify,notes,dashboard,admin}.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  L'audit `/ponytail-audit` de la plateforme LMS (`lightpath-training-portal`) a identifié **7 correctifs** (4 Must-Fix, 3 Should-Fix/Nice-to-Have) et **12 fonctionnalités LMS Entreprise & Qualiopi manquantes** réparties sur 4 axes (A à D) :
  1) **7 Correctifs de l'audit `/ponytail-audit` :**
     - *Audit #1 (Must-Fix) :* `Security::isValidHalfDayKey()` rejetait `J6_AM..J11_PM`, bloquant l'émargement de la Semaine 2 des cursus de 10 jours (70h).
     - *Audit #2 (Must-Fix) :* `templates/pages/flashcards.php` lisait `$userProgress['flashcards']` au lieu de `$userProgress['flashcard_confidence']` (alimenté par `saveFlashcardConfidence()`), et `layout.php` / `dashboard.php` affichaient un compteur figé à `42` au lieu des `53` cartes SRS.
     - *Audit #3 (Must-Fix) :* `templates/pages/exam.php` appelait `end($allAttempts)` alors que `JsonStorage::saveExamAttempt()` insère la tentative la plus récente en tête (`$allAttempts[0]`) via `array_unshift`.
     - *Audit #4 (Must-Fix) :* `api_submit_quiz` dans `public/index.php` enregistrait le score client sans recalculer le score côté serveur à partir de `MarkdownParser::parseModuleFile()['quiz']` lorsque `answers` est fourni.
     - *Audit #5 (Should-Fix) :* `DockerManager::writeStatusCache()` écrivait `docker_status_cache.json` sans fichier temporaire `.tmp` ni `LOCK_EX` + `rename()`.
     - *Audit #6 (Should-Fix) :* `CourseRepository::searchContent()` (`Ctrl+K`) n'indexait ni `referentiel` (19 documents Qualiopi), ni `tools` (`pgscripts`), ni `examples` (`pgexamples`), ni `flashcards` (53 cartes SRS).
     - *Audit #7 (Should-Fix) :* Branche morte `$page === 'docker'` dans `public/index.php`, duplication de `#discussion-section` entre `module_view.php` et `lab_view.php`, et absence du badge `$fc['module']` sur les cartes SRS.
  2) **12 Fonctionnalités LMS Entreprise & Qualiopi (Axes A–D) :**
     - *Axe A (Médiathèque Vidéo, Code Polyglotte & SRS) :* Lecteur vidéo HTML5 Studio 1080p (`?mode=video` dans `module_view.php`) avec streaming HTTP `206 Partial Content` (`DownloadController`), sous-titres WebVTT `.vtt` et transcription Markdown interactive ; explorateur de code compagnon multi-langages (`04_ateliers_et_tp/pgexamples/` : Python `psycopg3`, Go `pgx/v5`, PHP `PDO`, SQL) dans `documents.php` (`?page=documents&tab=examples`) ; filtres par module/niveau et Mode Focus carte-par-carte dans `flashcards.php`.
     - *Axe B (Conformité Qualiopi Indicateurs 11, 12, 30 & 32) :* Filtre de feuille d'émargement par parcours (3j/5j/10j), contre-signature électronique formateur (`signTrainerAttendance`) et bouton d'impression OPCO dans `attendance.php` ; questionnaire d'évaluation « À Froid (J+60 Impact Métier) » (`saveColdSatisfactionSurvey`, `?page=satisfaction&tab=cold`) ; portail de vérification d'authenticité des certificats (`?page=verify&code=LP-PG18-CERT-...`, `findCertificateByCode`) et QR Code SVG vectoriel 100 % hors-ligne (`Security::renderVerificationQrSvg`) dans `certificate.php`.
     - *Axe C (Workbench SQL & Carnet de Notes) :* Explorateur de schéma relationnel en 1 clic (`🗂️ Schéma des Tables`, `LabSqlRunner::inspectDatabaseSchema`), bouton `📊 EXPLAIN ANALYZE` 1-clic et historique SQL persistant dans `lab_view.php` ; carnet de notes centralisé (`?page=notes` dans `templates/pages/notes.php`) avec recherche, édition en ligne et export Markdown ; indices pédagogiques progressifs (`💡 Indice & Piste de Diagnostic DBA`, `LabSqlRunner::extractLabHint`) dans `lab_view.php`.
     - *Axe D (Pilotage Salle de Classe Live & Interopérabilité) :* Bannière d'annonce temps réel salle de classe et verrouillage pédagogique formateur des corrigés de labs (`lock_solutions`) et de l'examen final (`lock_exam`) (`getClassroomState` / `saveClassroomState`) ; modération formateur du forum Q&A (`resolveDiscussionMessage`, `deleteDiscussionMessage`, `getAllCourseDiscussions` + onglet `💬 Questions Q&A` dans `admin.php`) ; import CSV de cohorte en masse (`importUsersFromCsv`) et export de package `.zip` SCORM 1.2 complet (`DownloadController::buildScorm12ZipPackage`).
* **2. Hypothèse :**
  Implémenter les 7 correctifs `/ponytail-audit` et les 12 fonctionnalités des Axes A à D via la session `/dev-team` (`PM`, `Architect`, `Developer`, `QA`), en préservant la parité `StorageInterface` / `JsonStorage` / `PdoStorage` et le zéro dépendance CDN.
* **3. Contre-mesure :**
  Implémentation complète sur l'ensemble des contrôleurs, services et templates, et ajout de la Section 16 de tests TDD dans `tests/run_tests.php` (`298 / 298` assertions passées).
* **4. Prompt LLM :**
  ```text
  Contexte : dépôt lightpath-training-portal. Lis RCA-163 (RCA-LMS-33) dans REGISTRE_RCA.md.
  Tâche : Vérifier les 7 correctifs /ponytail-audit et les 12 fonctionnalités Entreprise/Qualiopi (Axes A à D) : émargement J1_AM..J11_PM + contre-signature formateur, persistance SRS flashcard_confidence + filtres/Mode Focus, sélection $allAttempts[0] dans exam.php, validation serveur QCM dans api_submit_quiz, cache atomique DockerManager, recherche plein-texte étendue (referentiel, tools, examples, flashcards), lecteur vidéo MP4 Range 206 + VTT + transcript, explorateur pgexamples, évaluation à froid J+60, vérification certificat + QR SVG offline, explorateur de schéma SQL + EXPLAIN + historique + indices progressifs, carnet de notes ?page=notes, pilotage salle live + verrous, modération Q&A, import CSV cohortes et export ZIP SCORM 1.2. Valide avec php tests/run_tests.php (298 assertions attendues).
  ```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-10-31

---

## Fiche d'Incident RCA-164 (`RCA-LMS-34`) : Remédiation des Constats de la Revue `/ponytail-review` sur le Portail LMS (Items 1, 2, 3, 4, 5, 7, 8, 12) & Suite de Tests Contractuels Section 17

* **Date de détection :** 2026-11-01
* **Issues GitHub associées :** `lightpath-training-portal#25`, `training_postgresql#101`
* **Composant Affecté :** `templates/pages/{admin,verify,lab_view,module_view,attendance}.php`, `public/index.php`, `templates/layout.php`, `src/Course/{CourseRepository,DownloadController}.php`, `src/Core/JsonStorage.php`, `tests/run_tests.php`
* **1. Mécanisme :**
  La revue contradictoire `/ponytail-review` consécutive à l'audit RCA-163 a révélé 8 points d'amélioration et de remédiation sur le portail LMS :
  1) **Item 1 (Import CSV cohorte) :** Désynchronisation entre le formulaire `templates/pages/admin.php` (`name="csv_payload"`) et le routeur `public/index.php` (`$_POST['csv_content']`), provoquant un import à vide (0 compte créé).
  2) **Item 2 (Vérification publique de certificat) :** Le filtre d'authentification initial dans `public/index.php` redirigeait immédiatement vers `login.php` toute requête anonyme (`$currentUser === null`), bloquant l'accès externe direct aux QR codes des attestations de formation `?page=verify&code=...`.
  3) **Item 3 (Verrouillage étanche des corrigés de labs) :** Le verrou formateur `lock_solutions` pouvait être contourné via l'appel API direct `api_run_lab_solution`, le téléchargement des énoncés complets de labs (`action=download&type=lab`), les onglets d'aperçu studio `html_preview` et `pdf_preview`, et le bouton vert de fin d'atelier dans `lab_view.php`.
  4) **Item 4 (Transcription vidéo Studio) :** `CourseRepository` définissait la clé `video_transcript_path` tandis que `module_view.php` cherchait `transcript_md_path`, empêchant l'affichage de la transcription synchronisée sous le lecteur vidéo.
  5) **Item 5 (Streaming vidéo Range 206) :** `DownloadController::streamFile()` chargeait la plage demandée en mémoire contiguë avec `fread($fp, $length)`, risquant la saturation mémoire `memory_limit` sur les requêtes initiales `Range: bytes=0-`.
  6) **Item 7 (Contre-signature formateur et affichage fidèle) :** `JsonStorage::signTrainerAttendance()` ne reconnaissait pas les clés journalières `J<N>_DAY` ou `J<N>`, et `attendance.php` affichait « Contre-signé » pour la journée dès qu'une seule demi-journée était visée.
  7) **Item 8 (Découverte des scripts SQL d'exemples) :** `CourseRepository::discoverExamples()` ne scannait pas le sous-dossier `04_ateliers_et_tp/pgexamples/sql/dbclients/*.sql`, privant la médiathèque de code des 9 exemples SQL relationnels.
  8) **Item 12 (Cache catalogue & invalidation) :** Absence d'alias `loadCourse()`, absence de `clearCatalogCache()`, absence de `LOCK_EX` lors de l'écriture du cache sur disque, et salt de fingerprint `v7_rca163` devant être haussé à `v8_rca164`.
* **2. Hypothèse :**
  Corriger chaque point de manière minimale et chirurgicale, assurer la rétrocompatibilité (fallback de noms de champs, clés dupliquées, gestion propre de `$currentUser === null` dans le layout), découper le streaming HTTP 206 par blocs de 64 Ko avec `flush()`, étendre `signTrainerAttendance()` pour `J<N>_DAY`, et valider l'ensemble par une suite de tests contractuels Section 17 garantissant 100% de succès.
* **3. Contre-mesure :**
  - Harmonisation du champ textarea dans `admin.php` sur `name="csv_content"` et tolérance `$_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')` dans `index.php`.
  - Autorisation de `$page === 'verify'` sans session active dans `index.php`, garde `$userId = 0`, sécurisation de `templates/layout.php` (widgets utilisateur masqués et bouton « 🔐 Connexion » propre) et protection de `verify.php`.
  - Blocage HTTP 403 sur `api_run_lab_solution` et `action=download&type=lab` lorsque `lock_solutions` est actif pour les non-staff, masquage de la barre de téléchargement de lab, des onglets d'aperçu `html_preview`/`pdf_preview` et du bouton de corrigé de bas de page dans `lab_view.php`.
  - Émission simultanée de `video_transcript_path` et `transcript_md_path` dans `CourseRepository`, et lecture avec repli dans `module_view.php`.
  - Découpage du streaming partiel vidéo en chunks de 64 Ko (`65536` octets) avec `ob_flush()` et `flush()`.
  - Support de `preg_match('/^J([1-9]|1[01])(?:_DAY)?$/i', $halfDayKey)` dans `JsonStorage::signTrainerAttendance()` et distinction visuelle fidèle (journée complète vs matin vs après-midi) avec soumission de `J<N>_DAY` dans `attendance.php`.
  - Inclusion de `glob($exDir . '/sql/dbclients/*.sql') ?: []` dans `CourseRepository::discoverExamples()` (9 exemples SQL découverts, total porté à 27 exemples multi-langages).
  - Ajout des méthodes `loadCourse()` et `clearCatalogCache()`, écriture du cache avec `LOCK_EX`, et mise à jour de l'empreinte avec `v8_rca164` incluant `sql/dbclients`.
  - Ajout de la Section 17 dans `tests/run_tests.php` avec 18 assertions ciblées (suite portée à 316 tests passés à 100 %).
* **4. Prompt LLM :**
  ```text
  Contexte : dépôt lightpath-training-portal. Lis la fiche RCA-164 (RCA-LMS-34) dans REGISTRE_RCA.md.
  Tâche : Vérifier les remédiations de la revue Ponytail sur le portail LMS :
  1) admin.php soumet name="csv_content" et index.php lit avec repli $_POST['csv_content'] ?? $_POST['csv_payload'].
  2) ?page=verify est accessible sans session ($currentUser null), le layout adapte la topbar et verify.php ne lève pas d'avertissement.
  3) lock_solutions bloque api_run_lab_solution (403), le download de lab (403), et lab_view.php masque téléchargement, prévisualisations et bouton de corrigé.
  4) CourseRepository fournit video_transcript_path et transcript_md_path, lus avec repli par module_view.php.
  5) DownloadController diffuse le streaming Range 206 par blocs de 64 Ko avec flush().
  6) JsonStorage supporte la contre-signature formateur J<N>_DAY (matin et après-midi), et attendance.php envoie J<N>_DAY et affiche fidèlement les visas.
  7) CourseRepository découvre les 9 exemples SQL de pgexamples/sql/dbclients/ (total 27 exemples).
  8) CourseRepository expose loadCourse(), clearCatalogCache(), écrit avec LOCK_EX et calcule le fingerprint v8_rca164.
  9) La suite de tests Section 17 passe à 100% avec au moins 316 assertions réussies.
  Valide avec : php tests/run_tests.php.
  ```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-11-01

---

## Fiche d'Incident RCA-165 (`RCA-LMS-35`) : Robustesse & Sécurité Interactive du Portail LMS (Timeout Docker, Conformité Certificats Qualiopi, Concurrence Import CSV, SRS Reset, RBAC SCORM, Registre OPCO, Diffusion Salle Live, Redirection Q&A & Recherche)

* **Date de détection :** 2026-11-02
* **Issues GitHub associées :** `lightpath-training-portal#27`
* **Composant Affecté :** `src/Docker/LabSqlRunner.php`, `src/Core/{JsonStorage,StorageInterface,PdoStorage}.php`, `templates/pages/{verify,flashcards,attendance}.php`, `public/index.php`, `templates/layout.php`, `public/assets/js/app.js`, `tests/run_tests.php`
* **1. Mécanisme :**
  L'investigation et l'audit de robustesse du portail LMS (Boucle 2 — Pilier 4) ont relevé 9 vulnérabilités et axes d'amélioration opérationnels :
  1) **Sécurité Docker SQL (`LabSqlRunner`) :** L'exécuteur `psql` appelait le conteneur sans aucun timeout de requête (`statement_timeout`) ni de verrou (`lock_timeout`). Une requête longue ou un verrou bloquant suspendait indéfiniment les workers PHP.
  2) **Conformité Qualiopi Certificats (`findCertificateByCode`, `verify.php`) :** L'identifiant `LP-PG18-CERT-...` était attribué à toutes les tentatives d'examen, et `verify.php` affichait le bandeau vert de certification authentique même pour un score inférieur au seuil requis de 75% (`passed === false`).
  3) **Concurrence & Inscription Import CSV (`importUsersFromCsv`) :** Pour chaque ligne du CSV, `importUsersFromCsv` effectuait des E/S fichiers répétées et verrouillages unitaires, et inscrivait les stagiaires dans un cours figé `'postgresql-18'` sans paramètre de cours cible.
  4) **Réinitialisation Flashcards SRS (`StorageInterface`, `JsonStorage`, `PdoStorage`, `flashcards.php`) :** Aucune méthode ni route ne permettait à un apprenant de réinitialiser ses évaluations SRS pour redémarrer une session d'entraînement à blanc.
  5) **Contrôle d'Accès Exports SCORM 1.2 (`public/index.php`) :** Les routes de téléchargement `export_scorm_manifest` et `export_scorm_zip` n'étaient pas restreintes au rôle staff (`$auth->isStaff($currentUser)`).
  6) **Registre d'Émargement Détaillé OPCO (`export_attendance_csv`, `attendance.php`) :** Seuls les totaux agrégés étaient exportés en CSV ; les auditeurs OPCO et Qualiopi exigent le détail nominatif horodaté de chaque émargement individuel avec IP, hash SHA-256 et visa formateur.
  7) **Diffusion Salle de Classe Live en Temps Réel (`api_heartbeat`, `app.js`) :** La réponse JSON de `api_heartbeat` ne renvoyait pas `classroom_state`, empêchant la mise à jour dynamique immédiate des annonces du formateur sans rafraîchissement manuel.
  8) **Sécurisation de la Redirection Q&A (`moderate_discussion`) :** Le paramètre `return_page` n'était pas filtré contre une liste blanche stricte `['admin', 'module', 'lab']`.
  9) **Préservation du Cours en Recherche Globale (`app.js`) :** L'appui sur Entrée dans `#global-search-input` omettait le paramètre `&course=...`, basculant silencieusement sur le cours par défaut.
* **2. Hypothèse :**
  1) Injecter `PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"` dans `LabSqlRunner::executeSql()`.
  2) Conditionner l'authentification officielle du certificat à `passed === true` dans `findCertificateByCode()` et afficher une notice d'attestation de suivi sans certification officielle si `passed === false`.
  3) Refactoriser `importUsersFromCsv()` sous un verrou atomique unique `mutateFile('users')` et supporter le paramètre `$courseSlug`.
  4) Ajouter `resetFlashcardReviews()` dans `StorageInterface`, `JsonStorage`, `PdoStorage`, la route `api_reset_flashcards` et le bouton de réinitialisation avec état vide dans `flashcards.php`.
  5) Restreindre `export_scorm_manifest` et `export_scorm_zip` à `$auth->isStaff($currentUser)`.
  6) Ajouter la route réservée au staff `export_attendance_csv` produisant le registre d'audit OPCO complet et ajouter son lien de téléchargement dans `attendance.php`.
  7) Inclure `'classroom_state' => $storage->getClassroomState($currentCourseSlug)` dans la réponse de `api_heartbeat` et mettre à jour le DOM client en temps réel.
  8) Restreindre strictement `return_page` à `in_array($rawReturnPage, ['admin', 'module', 'lab'], true)`.
  9) Transmettre `&course=${encodeURIComponent(currentCourse)}` lors de la soumission Entrée de la recherche globale.
* **3. Contre-mesure :**
  Implémentation des 9 améliorations sur les composants cibles, ajout de la Section 18 dans `tests/run_tests.php` validant 100% des contrats (326/326 tests réussis, 0 régression).
* **4. Prompt LLM :**
  ```text
  Contexte : dépôt lightpath-training-portal. Lis la fiche RCA-165 (RCA-LMS-35) dans REGISTRE_RCA.md.
  Tâche : Vérifier les 9 améliorations de robustesse et sécurité du LMS :
  1) LabSqlRunner::executeSql injecte statement_timeout=15000 et lock_timeout=10000.
  2) findCertificateByCode et verify.php distinguent passed=true (certificat authentique) et passed=false (attestation de suivi sans certification, seuil 75% non atteint).
  3) importUsersFromCsv exécute sous verrou atomique mutateFile('users') et inscrit les utilisateurs dans $courseSlug.
  4) resetFlashcardReviews réinitialise flashcard_confidence et route api_reset_flashcards opérationnelle avec bouton dans flashcards.php.
  5) export_scorm_manifest et export_scorm_zip sont strictement restreints à $auth->isStaff($currentUser).
  6) export_attendance_csv génère le registre détaillé d'émargement OPCO avec lien dans attendance.php.
  7) api_heartbeat inclut classroom_state et app.js met à jour l'annonce en direct.
  8) moderate_discussion restreint return_page à ['admin', 'module', 'lab'].
  9) Recherche globale sur Entrée conserve le paramètre &course=.
  10) Section 18 de tests/run_tests.php passe à 100% (326 tests).
  Valide avec : php tests/run_tests.php.
  ```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-11-02

---

## Fiche d'Incident RCA-166 (`RCA-LMS-36`) : Conformité du Rendu Markdown, Callouts CSS du Lecteur & Badges de Syntaxe

* **Date de détection :** 2026-11-02
* **Issues GitHub associées :** `lightpath-training-portal#29`
* **Composant Affecté :** `public/assets/css/app.css`, `src/Course/MarkdownParser.php`, `storage/data/parsed_cache/`, `tests/run_tests.php`
* **1. Mécanisme :**
  L'audit approfondi du Pilier 2 (Conformité des documents et lecteur LMS) a identifié plusieurs incohérences visuelles et contractuelles sur le portail LMS :
  1) **Classe CSS Callout Manquante (`.lms-callout-note`) :** Le parseur Markdown `MarkdownParser.php` génère la classe `<div class="lms-callout lms-callout-note">` pour l'ensemble des alertes `[!NOTE]`. Dans `app.css`, les classes `lms-callout-important`, `lms-callout-warning`, `lms-callout-caution` et `lms-callout-tip` étaient définies, mais `.lms-callout-note` était totalement absente, privant les notes techniques de bordure Cyan et de fond translucide.
  2) **Coloration Syntaxique & Badges de Langage (`.lms-code-badge`) :** Les blocs de code affichaient le code brut sans styles de jetons (`token-keyword`, `token-string`, `token-comment`, etc.) et le badge de langage dans la barre d'outils n'était pas harmonisé avec la palette Obsidian/Cyan d'Emil Kowalski ni en chiffres tabulaires (`font-variant-numeric: tabular-nums`).
  3) **Cache de Parsing Statique Obsolète :** Le répertoire `storage/data/parsed_cache/` contenait des caches pré-générés pouvant conserver des fragments HTML antérieurs aux corrections visuelles.
  4) **Absence de Tests de Non-Régression Rendu Markdown :** La suite de tests `tests/run_tests.php` ne vérifiait pas contractuellement la déclaration des 5 types d'alertes dans `app.css`, la méthode d'entrée `MarkdownParser::parse()`, ni la préservation des tags de syntaxe sur les blocs de code.
* **2. Hypothèse :**
  1) Ajouter dans `public/assets/css/app.css` la règle `.lms-callout-note { border-left-color: #00B0F0; background: rgba(0, 176, 240, 0.08); }`.
  2) Ajouter les styles pour `.lms-code-badge`, les jetons de code et `tabular-nums` dans `app.css` et associer la classe `.lms-code-badge` dans `MarkdownParser.php`.
  3) Exposer la méthode publique statique et d'instance `MarkdownParser::parse(string $markdown, ...)` pour convertir directement du Markdown en HTML et TOC.
  4) Invalider et vider le cache `storage/data/parsed_cache/`.
  5) Ajouter la Section 19 dans `tests/run_tests.php` pour certifier l'ensemble des contrats de rendu.
* **3. Contre-mesure :**
  Implémentation de l'ensemble des correctifs dans `public/assets/css/app.css` et `src/Course/MarkdownParser.php`, nettoyage du cache `storage/data/parsed_cache/`, et ajout de la Section 19 dans `tests/run_tests.php` (342/342 tests passés à 100%).
* **4. Prompt LLM :**
  ```text
  Contexte : dépôt lightpath-training-portal. Lis la fiche RCA-166 (RCA-LMS-36) dans REGISTRE_RCA.md.
  Tâche : Vérifier la conformité du rendu Markdown et des callouts CSS du lecteur LMS :
  1) public/assets/css/app.css définit .lms-callout-note avec border-left-color: #00B0F0 et background: rgba(0, 176, 240, 0.08).
  2) public/assets/css/app.css définit .lms-code-badge et les classes de jetons syntaxiques en palette Emil Kowalski Obsidian/Cyan avec font-variant-numeric: tabular-nums.
  3) src/Course/MarkdownParser.php expose public static function parse() et applique la classe lms-code-badge sur l'en-tête de bloc de code.
  4) Les 5 types d'alertes GitHub (TIP, NOTE, IMPORTANT, WARNING, CAUTION) sont correctement traduits en classes CSS lms-callout-* correspondantes.
  5) Les blocs de code préservent les tags de langage lang-sql, lang-bash, lang-python.
  6) Le cache storage/data/parsed_cache/ est purgé et reconstruit à chaud.
  7) La suite Section 19 de tests/run_tests.php passe à 100% avec 342 tests passés.
  Valide avec : php tests/run_tests.php.
  ```
* **Statut :** ✅ Implémenté — voir Journal de Bord 2026-11-02






