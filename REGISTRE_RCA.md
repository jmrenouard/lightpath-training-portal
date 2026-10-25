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

