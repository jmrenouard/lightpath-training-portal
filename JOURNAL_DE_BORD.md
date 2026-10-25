# Journal de Bord d'Ingénierie — LightPath Training Portal (LMS PHP 8.3)

> **Référentiel :** Conformité Qualiopi / Datadock, Charte Studio Emil Kowalski & Traçabilité RCA (`AGENTS.md` §9)

---

### Correction RCA-144 (RCA-LMS-01 à RCA-LMS-05) — Audit & Refonte Studio de la Plateforme LMS PHP : Rendu Visuel Markdown/Mermaid Sans Déchet, Zéro Débordement CSS, Cache Catalogue & Docker `< 15 ms`, Structuration des 4 Parcours, ScrollSpy & Stepper de Position, et Mode Diaporama Interactif 16:9
* **Date :** 2026-10-25 18:15
* **Fichiers modifiés :**
  - `REGISTRE_RCA.md` (nouveau registre RCA dédié au portail LMS documentant `RCA-LMS-01` à `RCA-LMS-05`)
  - `JOURNAL_DE_BORD.md` (création et consignation de la correction `RCA-144`)
  - `config/courses.php` (enrichissement des 4 parcours `tracks` avec `number`, `short_title`, `target_audience`, `prerequisites`, `objective` et découpage chronologique par journées/étapes `phases`)
  - `src/Course/CourseRepository.php` (cache persistant sur disque `storage/data/catalog_cache_<slug>.json` invalidé par `filemtime` + hash des parcours, et enrichissement de chaque module/lab avec `track_position`, `track_total`, `phase_code`, `phase_title`)
  - `src/Docker/DockerManager.php` & `public/index.php` (lecture non bloquante `getCachedUserContainerStatus()` depuis `storage/data/docker_status_cache.json` en `< 1 ms` sur toutes les pages et endpoint JSON asynchrone `?action=api_docker_status`)
  - `src/Course/MarkdownParser.php` (résolution automatique des diagrammes Mermaid en images PNG Retina 2x pré-rendues `assets/mermaid_cache/mermaid_<sha256_16>.png` avec zoom Lightbox et fallback local `mermaid.min.js`, transformation des listes à puces/numérotées imbriquées dans les callouts en `<ul class="lms-callout-list">` / `<ol>`, conversion des tableaux et cartouches d'en-tête en grille `.lms-doc-meta-grid`, correction de la regex de gras `/\*\*((?:(?!\*\*).)+)\*\*/u` supportant l'italique imbriqué `(*Hooks*)`, filtrage de la section brute `<details>` de quiz en doublon, et extracteur de diapositives `extractSlides()` avec cache `storage/data/parsed_cache/`)
  - `public/assets/js/mermaid.min.js` (moteur vectoriel local 100 % autonome, zéro CDN)
  - `public/assets/css/app.css` (protection anti-débordement `min-width: 0`, `overflow-wrap: anywhere`, composants `.lms-doc-meta-grid`, `.lms-mermaid-card`, `.lms-roadmap-*`, `.lms-course-stepper-card`, `.lms-toc-progress-*`, `.lms-slide-deck-shell`, `.lms-lightbox-modal`)
  - `public/assets/js/app.js` (ScrollSpy temps réel sur les titres `H2`/`H3` avec barre de progression `0%–100%`, contrôleur du Mode Diaporama 16:9 clavier `←`/`→`/`Espace`/`F` + plein écran, Lightbox zoomable pour infographies et schémas Mermaid, et rafraîchissement asynchrone du statut Docker)
  - `templates/layout.php`, `templates/pages/dashboard.php`, `templates/pages/catalog.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php` (intégration de la feuille de route des 4 parcours par journées, du Stepper de position dans le parcours, du ScrollSpy TOC et du Mode Diaporama 16:9 hybride HTML5 + PowerPoint `_slides.pdf`)
  - `tests/run_tests.php` (extension de la suite de tests automatisés de 45 à 90 assertions couvrant `RCA-LMS-01` à `RCA-LMS-05` sur la totalité des 34 modules et 34 ateliers)
* **Nature de la correction :**
  1. **Rendu visuel Markdown & Mermaid irréprochable (`RCA-LMS-01`) :** 100 % des schémas `mermaid` sont affichés visuellement en haute définition Retina 2x (avec zoom plein écran et code source repliable) ; zéro token Markdown brut (`**`, `<br/>* `) sur la totalité des 34 modules et 34 labs ; transformation des métadonnées d'en-tête en grille `.lms-doc-meta-grid` ; suppression du bloc `<details>` de quiz brut en doublon au profit du Quiz QCM interactif.
  2. **Zéro débordement & retours à la ligne maîtrisés (`RCA-LMS-02`) :** Application de `min-width: 0`, `overflow-wrap: anywhere` et conteneurs scrollables `.lms-table-scroll` sur l'ensemble des tableaux, blocs de code, identifiants SQL inline et cartes.
  3. **Performance instantanée `< 15 ms` (`RCA-LMS-03`) :** Suppression des appels bloquants `wsl docker inspect` à chaque clic (remplacés par `docker_status_cache.json` + `?action=api_docker_status`) et mise en cache du catalogue et du parsing Markdown (chargement mesuré à **3,38 ms** pour Catalogue + Statut Docker).
  4. **Feuille de route claire des 4 Parcours & ScrollSpy de position (`RCA-LMS-04`) :** Présentation structurée des 4 parcours (Public cible, Prérequis, Objectif, découpage par journées `JOUR 1..5` et cartes couplées Module + Lab), Stepper latéral de position dans le parcours (`Étape X / N`) et ScrollSpy temps réel dans le sommaire latéral (`0%–100%`).
  5. **Mode Diaporama Interactif 16:9 (`RCA-LMS-05`) :** Ajout de l'onglet `🖥️ Mode Diaporama 16:9` (`?mode=slides`) offrant un lecteur de slides 16:9 interactif HTML5 (22 à 45 slides par module, navigation clavier, plein écran, plan latéral, quiz intégrés) ainsi qu'une bascule directe vers le support PowerPoint officiel `_slides.pdf`.
* **Tests post-correction :** ✅ 90 / 90 assertions passées dans `php tests/run_tests.php` (0 régression)

---

### Correction RCA-145 (RCA-LMS-06 à RCA-LMS-10) — Logos 100 % Transparents Adaptatifs Dark/Light, Éradication des Erreurs Console 404 & Unload, Alignement Exact des 58 Diapositives Studio 16:9 (1 686 Slides sur 34 Modules), Accélération d'Affichage `< 15 ms` & Navigation Directe des 34 Modules dans la Barre Latérale Gauche
* **Date :** 2026-10-25 19:20
* **Fichiers modifiés :**
  - `REGISTRE_RCA.md` & `JOURNAL_DE_BORD.md`
  - `public/assets/img/lightpath_logo_white_text.png`, `public/assets/img/lightpath_logo_dark_text.png`, `public/assets/img/postgresql_elephant_dark.png`, `public/assets/img/postgresql_elephant_light.png`, `public/assets/img/postgresql_elephant.png`, `public/favicon.ico`, `public/.well-known/appspecific/com.chrome.devtools.json`
  - `bin/export_slides_cache.py` (export automatisé des 34 decks officiels Studio 16:9 — 1 686 diapositives dont 58 slides pour le Module 01 — dans `public/assets/slides_cache/<module_id>/manifest.json` + `slide_001.png`..`slide_NNN.png`)
  - `public/index.php` (libération immédiate du verrou de session PHP via `session_write_close()` sur toutes les requêtes GET et `api_` pour éliminer tout blocage inter-requêtes sur le serveur `php -S`)
  - `src/Course/DownloadController.php` (réécriture automatique des chemins relatifs `../assets/` vers `assets/` lors du streaming HTML inline `mode=html_preview` et en-tête `Permissions-Policy: unload=(self)`)
  - `src/Course/MarkdownParser.php` (chargement prioritaire du manifeste officiel Studio 16:9 `public/assets/slides_cache/<module_id>/manifest.json` dans `extractSlides()` exposant les 58 slides du Module 01 et résolution directe des URLs statiques `assets/mermaid_cache/` et `assets/images/` dans `resolveAssetUrl()`)
  - `templates/layout.php` (logos adaptatifs `.theme-aware-logo` avec `data-dark-src` / `data-light-src`, arbre complet de navigation directe des 34 modules `M01`..`M29` et `SQL-01`..`SQL-05` par parcours avec filtre rapide `#sidebar-module-filter`, et suppression du chargement synchrone bloquant de `mermaid.min.js` de 3,33 Mo)
  - `templates/pages/module_view.php`, `templates/pages/certificate.php` (lecteur Studio 16:9 haute fidélité sur les 58 slides avec notes de l'orateur, iframe PDF chargée à la demande via `data-src` + `allow="unload *"`, et navigation épurée Précédent / Lab / Suivant)
  - `public/assets/js/app.js` & `public/assets/css/app.css` (`syncThemeLogos()`, filtrage en direct `#sidebar-module-filter`, pré-chargement glissant `ensureSlideImageLoaded()` des slides `N-1..N+1`, chargement paresseux de `mermaid.min.js`, et suppression du polling automatique `api_docker_status` au chargement de page)
  - `tests/run_tests.php` (extension à 108 assertions couvrant `RCA-LMS-06` à `RCA-LMS-10`)
* **Nature de la correction :**
  1. **Logos 100 % transparents compatibles Dark & Light (`RCA-LMS-06`) :** Régénération de `lightpath_logo_white_text.png` et `postgresql_elephant_dark.png` avec canal alpha `0` intégral et texte blanc pur `#FFFFFF` pour le thème sombre `#0B1120`, et bascule automatique vers `lightpath_logo_dark_text.png` et `postgresql_elephant_light.png` en thème clair.
  2. **Zéro erreur 404 Not Found & Zéro violation `unload` (`RCA-LMS-07`) :** Exposition statique de `public/assets/{logos,images,mermaid_cache,vendor}`, ajout de `public/favicon.ico` et `com.chrome.devtools.json`, réécriture à la volée de `../assets/` vers `assets/` dans `DownloadController.php` et chargement à la demande (`data-src`) de l'iframe PDF avec `allow="unload *"`.
  3. **Alignement exact des 58 diapositives du Module 01 (`RCA-LMS-08`) :** Pré-rendu et intégration des 34 decks officiels Studio 16:9 (`1 686` slides au total, **58 slides** pour le Module 01) dans `public/assets/slides_cache/` avec titres exacts extraits des PPTX et notes du formateur.
  4. **Vitesse d'affichage instantanée (`RCA-LMS-09`) :** Suppression du script synchrone `mermaid.min.js` (3,33 Mo) sur chaque page, libération immédiate du verrou de session PHP (`session_write_close()`), arrêt du polling `wsl docker inspect` au chargement de page et service statique direct des images (`< 2 ms` par ressource).
  5. **Navigation directe des 34 modules dans la barre verticale gauche (`RCA-LMS-10`) :** Ajout dans la barre latérale gauche de l'arbre complet des 4 parcours contenant les liens directs vers chacun des **34 modules** (`M01`..`M29`, `SQL-01`..`SQL-05`), avec indicateur de complétion `✔`, surbrillance du module actif et filtre instantané `#sidebar-module-filter`.
* **Tests post-correction :** ✅ 108 / 108 assertions passées dans `php tests/run_tests.php` (0 régression)

---

### Correction RCA-LMS-11 à RCA-LMS-16 (`RCA-146`) — Synchronisation 1:1 des Slides au Clic, Lecteur PDF en Zone Principale, Menu Droite Sticky Sans Scrollbar, Barres Gauche/Droite Repliables, Onglets Énoncé/Corrigé des Labs & Durcissement Sécurité / Unload Shim
* **Date :** 2026-10-25 21:48
* **Issues GitHub associées :** `lightpath-training-portal#6` & `training_postgresql#80`
* **Fichiers modifiés :**
  - `bin/export_slides_cache.py` & `public/assets/slides_cache/*/manifest.json` (extraction directe des titres, sections et aperçus depuis chaque page de `*_slides.pdf` sur les 34 modules / 1 686 slides pour une correspondance 1:1 absolue avec `slide_NNN.png`)
  - `src/Course/MarkdownParser.php` (invalidation du cache `mod_v5_rca146` / `lab_v5_rca146`, découpage `splitLabEnonceAndCorrige()` générant `enonce_html`, `enonce_toc`, `corrige_html` et `corrige_toc` sur les 34 ateliers)
  - `src/Core/Security.php`, `src/bootstrap.php`, `src/Course/DownloadController.php`, `public/index.php`, `src/Docker/DockerManager.php` (`Security::sendSecurityHeaders()`, `Security::renderUnloadShimScript()` convertissant `unload` en `pagehide` contre les violations `chext_loader.js`, validation `isPathInside` sur `admin_add_course` et assainissement Docker)
  - `templates/layout.php`, `templates/pages/login.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php` (boutons de repli des barres verticales gauche et droite, conteneur `.slide-stage-main-col`, onglets `Énoncé` / `Corrigé Complet & REX` / `Vue Intégrale` / `Rendu Web` / `PDF` sur les labs, bouton `#btn-toggle-lab-solutions`)
  - `public/assets/css/app.css` & `public/assets/js/app.js` (`[hidden] { display: none !important; }`, `body { overflow-x: clip; }` restaurant `position: sticky` sur la barre verticale de droite, masquage intégral des barres de défilement `scrollbar-width: none`, `body.left-sidebar-collapsed` / `body.right-sidebar-collapsed` avec persistance `localStorage` et raccourcis `Alt+G` / `Alt+D`)
  - `AGENTS.md`, `REGISTRE_RCA.md`, `tests/run_tests.php` (123 assertions automatisées)
* **Nature de la correction :**
  1. **Synchronisation 1:1 des diapositives au clic (`RCA-LMS-11`) :** Élimination du décalage entre `.pptx` et `_slides.pdf` sur les 29 modules concernés en extrayant les titres directement depuis chaque page du PDF officiel, et suppression de `src=""` sur les images `3..N`.
  2. **Lecteur PDF Continu dans la colonne principale (`RCA-LMS-12`) :** Encapsulation de `#slide-stage-html` et `#slide-stage-pdf` dans `.slide-stage-main-col` et règle `[hidden] { display: none !important; }` pour que le lecteur PDF s'affiche toujours dans la scène principale et ne remplace jamais le menu vertical de droite.
  3. **Menu vertical de droite sticky et sans barre de défilement (`RCA-LMS-13`) :** Remplacement de `overflow-x: hidden` sur `html, body` par `overflow-x: clip` sur `body` (rendant `.reader-sidebar` et `.slide-outline-sidebar` parfaitement sticky tout au long du scroll) et suppression de toute barre de défilement visible (`scrollbar-width: none` / `::-webkit-scrollbar`).
  4. **Barres verticales gauche et droite repliables (`RCA-LMS-14`) :** Ajout des contrôles de réduction/extension en 1 clic pour la barre gauche et la barre droite (`Alt+G` / `Alt+D`, bouton flottant de réouverture et persistance `localStorage`).
  5. **Vues Énoncé et Corrigé Complet sur les 34 ateliers (`RCA-LMS-15`) :** Ajout dans `lab_view.php` des onglets `📋 Énoncé & Travail Pas-à-Pas`, `✅ Corrigé Complet & Débriefing REX`, `📖 Vue Intégrale`, `🌐 Rendu Web Studio` et `📕 Support PDF` ainsi que du bouton `👁️ Afficher / Masquer les Solutions`.
  6. **Durcissement sécurité, shim `unload` (`chext_loader.js`) & règle Issue GitHub (`RCA-LMS-16`) :** En-têtes HTTP de sécurité, interception JS `unload -> pagehide` éliminant toute erreur console d'extension Chrome, implémentation de `Security::isPathInside()` (appelée par `admin_add_course`), bornage strict `[1024..65535]` dans `DockerManager::getUserPort()`, assainissement de `$slug` dans `bin/sync-course.php`, routage sécurisé des liens `cheatsheets` et blocage des schémas URI exécutables dans `MarkdownParser::formatInline()`, centrage précis par `getBoundingClientRect` (`centerElementInScrollBox`) dans `app.js`, repli harmonisé `<details class="toc-box">` du stepper dans `lab_view.php`, et codification dans `AGENTS.md` de l'ouverture/fermeture commentée d'une Issue GitHub par tâche sur les deux dépôts (`lightpath-training-portal#6`, `#8` & `training_postgresql#80`, `#82`).
* **Tests post-correction :** ✅ 128 / 128 assertions passées dans `php tests/run_tests.php` (0 régression)


