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

---

### Correction RCA-147 — Enrichissement des 34 ateliers pratiques, normalisation Énoncé/Corrigé et couverture 100 % par oracles SQL `DO $$` (`RCA-LMS-17`)
* **Date :** 2026-10-26 20:40
* **Fichiers modifiés :** `04_ateliers_et_tp/lab_*.md`, `scripts/validate_labs.py`
* **Nature de la correction :** Ajout des corrigés pas-à-pas et des oracles SQL `DO $$ ... RAISE NOTICE 'VALIDATION_OK%'; END $$;` dans les 11 ateliers (`lab_09`, `lab_11`..`lab_20`), repositionnement de `🌟 Pour Aller Plus Loin` avant le H2 de validation sur l'ensemble des 34 ateliers, et ajout de `--check-markdown-labs` dans `scripts/validate_labs.py`.
* **Tests post-correction :** ✅ 34 / 34 ateliers Markdown validés (0 régression)

### Correction RCA-148 — Idempotence SQL des corrigés d'ateliers (`lab_02`, `lab_20`), ajout des grilles DoD (`lab_21..25`) et synchronisation de `livret_exercices_complet.md` (`RCA-LMS-18`)
* **Date :** 2026-10-26 20:42
* **Fichiers modifiés :** `04_ateliers_et_tp/lab_02_getting_started_databases_clusters.md`, `04_ateliers_et_tp/lab_20_tuning_systeme_linux_postgresql.md`, `04_ateliers_et_tp/lab_21..25_*.md`, `04_ateliers_et_tp/livret_exercices_complet.md`
* **Nature de la correction :** Idempotence DDL de `lab_02`, sécurité conteneur `huge_pages = 'try'` + `RESET` dans `lab_20`, ajout des grilles DoD dans `lab_21`..`25`, reconstruction de `livret_exercices_complet.md` et recompilation multi-formats.
* **Tests post-correction :** ✅ 100 % des livrables synchronisés (`build_docs.py --check-sync`)

### Correction RCA-149 — Ordre d'initialisation PDO et visibilité `protected` dans `PdoStorage` / `JsonStorage` (`RCA-LMS-19`)
* **Date :** 2026-10-26 20:45
* **Fichiers modifiés :** `lightpath-training-portal/src/Core/JsonStorage.php`, `lightpath-training-portal/src/Core/PdoStorage.php`
* **Nature de la correction :** Passage de `readFile()` et `writeFile()` en `protected` dans `JsonStorage`, initialisation de `$this->pdo` et de la table `lms_kv_store` avant `parent::__construct()` dans `PdoStorage`, et implémentation de la persistance clé-valeur SQL avec fallback JSON.
* **Tests post-correction :** ✅ Validé sur instance `PDO('sqlite::memory:')` sans régression

### Correction RCA-150 — Fiabilisation de `LabSqlRunner` : sélecteur multi-bases (`formation_pg` / `dvdrental`) et exécuteur de corrigé `runLabSolution()` (`RCA-LMS-20`)
* **Date :** 2026-10-26 20:46
* **Fichiers modifiés :** `lightpath-training-portal/src/Docker/LabSqlRunner.php`, `lightpath-training-portal/templates/pages/lab_view.php`, `lightpath-training-portal/public/index.php`
* **Nature de la correction :** Prise en charge multi-bases (`formation_pg`, `dvdrental`, `postgres`), détection automatique `detectTargetDatabase()`, validation stricte d'oracle `validateLabOracle()` et exécution séquentielle du corrigé officiel `runLabSolution()`.
* **Tests post-correction :** ✅ 34 / 34 oracles et corrigés de labs pris en charge

### Correction RCA-151 — Durcissement de `MarkdownParser::splitLabEnonceAndCorrige()` pour tous les marqueurs de solutions (`RCA-LMS-21`)
* **Date :** 2026-10-26 20:47
* **Fichiers modifiés :** `lightpath-training-portal/src/Course/MarkdownParser.php`
* **Nature de la correction :** Extension de la regex des solutions aux variantes (`Requête Solution`, `Commandes Solution`, `Solution Analytique`, `Configuration Solution`, `Requête Démonstration`, `Corrigé Technique`) et préservation des défis/DoD dans l'énoncé.
* **Tests post-correction :** ✅ 100 % des tests de découpage Énoncé/Corrigé passés

### Correction RCA-152 — Exactitude de `DockerManager` (statut réel & amorçage Sakila `dvdrental`) et liaison des aide-mémoires aux 34 ateliers (`RCA-LMS-22`)
* **Date :** 2026-10-26 20:48
* **Fichiers modifiés :** `lightpath-training-portal/src/Docker/DockerManager.php`, `lightpath-training-portal/src/Course/CourseRepository.php`, `lightpath-training-portal/templates/pages/documents.php`
* **Nature de la correction :** Statut initial `running => false` dans `getCachedUserContainerStatus()`, amorçage automatique du schéma Sakila `dvdrental` dans `bootstrapContainerSchema()`, liaison `linked_cheatsheet_id` sur les 34 ateliers et lecteur Markdown intégré des fiches mémo.
* **Tests post-correction :** ✅ 34 / 34 ateliers liés à leur aide-mémoire

### Correction RCA-153 — Conformité Qualiopi LMS : test de positionnement initial, émargement numérique, temps actif et satisfaction à chaud (`RCA-LMS-23`)
* **Date :** 2026-10-26 20:49
* **Fichiers modifiés :** `lightpath-training-portal/src/Course/LmsCatalog.php`, `lightpath-training-portal/templates/pages/diagnostic.php`, `lightpath-training-portal/templates/pages/attendance.php`, `lightpath-training-portal/templates/pages/satisfaction.php`
* **Nature de la correction :** Ajout du test de positionnement initial (15 QCM sur 5 domaines), de la feuille d'émargement numérique Qualiopi par demi-journée avec suivi du temps actif (`api_heartbeat`), et du questionnaire de satisfaction à chaud.
* **Tests post-correction :** ✅ 100 % des tests Qualiopi passés

### Correction RCA-154 — Examen final certifiant chronométré (40 QCM pondérés) et durée dynamique sur le certificat (`RCA-LMS-24`)
* **Date :** 2026-10-26 20:50
* **Fichiers modifiés :** `lightpath-training-portal/templates/pages/exam.php`, `lightpath-training-portal/templates/pages/certificate.php`, `lightpath-training-portal/src/Course/LmsCatalog.php`
* **Nature de la correction :** Implémentation de l'examen final chronométré (40 QCM, 45 min, seuil 75 %, bilan par domaine DBA) et calcul dynamique de la durée du cursus/parcours sur le certificat.
* **Tests post-correction :** ✅ Notation pondérée et certificat dynamique validés

### Correction RCA-155 — Expérience d'apprentissage avancée : Flashcards SRS & Glossaire PG 18, recherche `Ctrl+K`, favoris/reprise/notes, Q&A, badges DBA & exports SCORM 1.2 (`RCA-LMS-25`)
* **Date :** 2026-10-26 20:51
* **Fichiers modifiés :** `lightpath-training-portal/src/Core/Gamification.php`, `lightpath-training-portal/templates/pages/flashcards.php`, `lightpath-training-portal/templates/pages/search.php`, `lightpath-training-portal/templates/pages/dashboard.php`, `lightpath-training-portal/templates/pages/admin.php`, `lightpath-training-portal/templates/layout.php`
* **Nature de la correction :** Ajout des 42 flashcards SRS, du glossaire de 52 termes PG 18, du moteur de recherche plein texte `Ctrl+K`, des favoris/bannière de reprise/export `.md` des notes, du fil d'entraide Q&A, des 8 badges DBA & XP et des exports Admin CSV Qualiopi / SCORM 1.2 `imsmanifest.xml`.
* **Tests post-correction :** ✅ 100 % des nouvelles routes et fonctionnalités testées

### Correction RCA-156 — Couverture de tests automatisés à 100 % (`validate_labs.py`, `pgscripts`, `pgexamples`, `test_rca_contracts.py` et `run_tests.php`) (`RCA-LMS-26`)
* **Date :** 2026-10-26 20:52
* **Fichiers modifiés :** `scripts/validate_labs.py`, `tests/test_rca_contracts.py`, `pgscripts/tests/test_cli_scripts.py`, `lightpath-training-portal/tests/run_tests.php`
* **Nature de la correction :** Extension des tests de contrats jusqu'à `RCA-156`, ajout des tests unitaires CLI pour les 6 scripts de `pgscripts/` et extension de la suite TDD PHP du portail LMS.
* **Tests post-correction :** ✅ 100 % des suites Pytest et PHP TDD passées (0 régression)

---

### Correction RCA-157 (`RCA-LMS-27` & `RCA-LMS-28`) — Installation & Durcissement du Serveur MCP Officiel Microsoft `@playwright/mcp`, Campagne Automatisée E2E sur les 18 Routes LMS, Correction Responsive Mobile `375x812` & Conformité DOM `autocomplete`
* **Date :** 2026-10-26 20:53
* **Issues GitHub associées :** `lightpath-training-portal#11`
* **Fichiers modifiés :**
  - `config/playwright-mcp.config.json` (configuration durcie du serveur MCP officiel Microsoft `@playwright/mcp` : `isolated: true`, `headless: true`, `channel: "chrome"`, `allowedOrigins: ["http://localhost:*", "http://127.0.0.1:*"]`, `allowUnrestrictedFileAccess: false`)
  - `config/app.php` (support de la variable d'environnement `LMS_DATA_DIR` permettant d'isoler intégralement le stockage JSON lors des campagnes E2E)
  - `tests/e2e/run_playwright_campaign.mjs` (harnais automatisé de campagne E2E pilotant `@playwright/mcp` en JSON-RPC 2.0 via `stdio` sur 59 assertions : handshake MCP, test actif du blocage d'origine externe `ERR_BLOCKED_BY_CLIENT`, isolation `LMS_DATA_DIR`, en-têtes HTTP de sécurité, edge cases d'authentification/CSRF/Path Traversal, audit des 18 routes LMS, diaporama Studio 16:9 de 58 slides, bascule HTML/PDF, barres latérales repliables, thème Dark/Light, audit responsive multi-viewports `375x812`, `768x1024`, `1440x900` avec vérification des boîtes englobantes sur 9 routes + page login + chaîne continue de 255 caractères sans espace)
  - `templates/pages/login.php`, `templates/pages/profile.php`, `templates/pages/admin.php`, `templates/pages/certificate.php` (grille fluide `minmax(min(100%, 340px), 1fr)`, padding fluide `clamp(...)` sur l'attestation et ajout des attributs W3C `autocomplete="username"`, `autocomplete="current-password"`, `autocomplete="new-password"`, `autocomplete="name"`, `autocomplete="email"`, `autocomplete="organization"`)
  - `public/assets/css/app.css` (ajout de `.reader-grid > *, .slide-deck-layout > * { min-width: 0; }`, `minmax(min(100%, 330px), 1fr)` sur `.card-grid`, et `@media (max-width: 640px)` adaptant `.app-topbar`, `.topbar-left-group`, `.topbar-search`, `.btn`, `.catalog-track-banner-main` et les conteneurs pour garantir `maxRight <= innerWidth` sur mobile `375px`)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`, `tests/run_tests.php` (extension à 134 assertions unitaires/contrats PHP + 59 assertions E2E Playwright MCP)
* **Nature de la correction :**
  1. **Sélection, installation globale et durcissement de `@playwright/mcp` (`RCA-LMS-28`) :** Installation globale de `@playwright/mcp@0.0.83` (Microsoft) et durcissement des configurations MCP avec `--browser=chrome --headless --isolated --block-service-workers --viewport-size=1440x900` et restriction d'origines locales (`ERR_BLOCKED_BY_CLIENT` vérifié sur URL externe).
  2. **Éradication des débordements horizontaux Mobile `375x812` (`RCA-LMS-27`) :** Correction des débordements sur `login.php`, `.topbar-search`, `.btn`, `.card-grid`, `.reader-grid > *`, `.catalog-track-banner-main` et `certificate.php`, garantissant `scrollWidth <= 375` et `maxRight <= 375` sur toutes les vues en viewport mobile.
  3. **Éradication des avertissements DOM Chromium `autocomplete` & Isolation `LMS_DATA_DIR` (`RCA-LMS-28`) :** Ajout des attributs `autocomplete` normalisés sur tous les formulaires d'authentification et de gestion de comptes (`0` erreur et `0` avertissement console sur les 18 routes) et isolation complète des données de test E2E via `LMS_DATA_DIR`.
* **Tests post-correction :** ✅ 134 / 134 assertions passées dans `php tests/run_tests.php` + ✅ 59 / 59 assertions E2E passées dans `node tests/e2e/run_playwright_campaign.mjs` (0 régression)

---

### Correction RCA-158 — Rendu Inline Sécurisé, Extraction Métadonnées Pédagogiques Exactes & Zéro Backtick Brut sur les 152 Documents LMS
* **Date :** 2026-10-27 00:50
* **Fichiers modifiés :** `src/Core/Security.php`, `src/Course/CourseRepository.php`, `src/Course/MarkdownParser.php`, `src/Course/DownloadController.php`, `templates/pages/catalog.php`, `templates/pages/diagnostic.php`, `templates/pages/documents.php`, `templates/pages/exam.php`, `templates/pages/flashcards.php`, `templates/pages/module_view.php`, `templates/pages/search.php`, `tests/run_tests.php`
* **Nature de la correction :** Introduction de `Security::formatInlineText()` pour le rendu sécurisé du code inline et du gras sans vulnérabilité XSS, isolation du titre H1 du Module 10 contre les commentaires Python, normalisation de la durée et extraction systématique des niveaux Bloom, parsing des tableaux avec pipes échappés et éradication de tout backtick orphelin hors balises code/pre sur l'ensemble des 152 documents LMS.
* **Tests post-correction :** ✅ 170 / 170 passés dans `php tests/run_tests.php` (0 régression)

### Correction RCA-159 — Alignement des Slugs de Recommandation de Parcours du Diagnostic Qualiopi avec `config/courses.php`
* **Date :** 2026-10-27 01:00
* **Issue GitHub associée :** `lightpath-training-portal#13`
* **Fichiers modifiés :** `src/Course/LmsCatalog.php`, `tests/run_tests.php`
* **Nature de la correction :** Alignement des identifiants et libellés de parcours retournés par `LmsCatalog::evaluateDiagnostic()` sur les clés officielles déclarées dans `config/courses.php` (`fondamentaux-sql` pour score < 45 %, `cursus-complet` pour score 45-74 %, `performance-tuning` pour score >= 75 %). Ajout des champs de compatibilité `recommended_course_id` et `recommended_course_slug`, et renforcement des assertions dans `tests/run_tests.php` pour valider l'existence effective du parcours dans le catalogue sur l'ensemble des tranches de score.
* **Tests post-correction :** ✅ 179 / 179 passés dans `php tests/run_tests.php` + ✅ 68 / 68 passés dans `node tests/e2e/run_playwright_campaign.mjs` (0 régression)



