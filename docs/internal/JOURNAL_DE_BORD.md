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

---

### Régularisation Gouvernance Git — Clôture Formelle de l'Issue #11 & Validation Forensique
* **Date :** 2026-10-27 02:25
* **Issue GitHub associée :** `lightpath-training-portal#11` (Clôturée) / `lightpath-training-portal#17`
* **Pull Request associée :** `lightpath-training-portal#12`
* **Fichiers modifiés :** `JOURNAL_DE_BORD.md`
* **Nature de la régularisation :**
  - Clôture formelle de l'Issue #11 (`feat(lms-qa): Durcissement du serveur MCP @playwright/mcp, Campagne de Tests E2E LMS (18 routes, 3 viewports) et correctifs RCA-LMS-17/18`) conformément à la Règle 7 d'AGENTS.md.
  - L'implémentation associée avait été mergée via la Pull Request #12 mais l'issue n'avait pas été formellement fermée avec son commentaire d'attestation.
  - Clôture enregistrée avec le commentaire d'attestation : *"Résolu et validé via la fusion de la Pull Request #12. Audit E2E Playwright MCP 100% conforme (68 assertions passées, 0 débordement mobile 375px, autocomplete DOM conforme)."*
  - Vérification de l'absence totale d'issues orphelines ouvertes sur le dépôt (`gh issue list --state open`).
* **Tests post-régularisation :** ✅ 179 / 179 tests passés dans `php tests/run_tests.php` + ✅ 68 / 68 passés dans `node tests/e2e/run_playwright_campaign.mjs` (0 régression)

---

### Correction RCA-160 (`RCA-LMS-30`) — Audit `/ponytail-audit` & Remédiation `/dev-team` du Portail LMS (Verrouillage Atomique `mutateFile`, Anti-Injection CSV, Rate-Limiting `(email, IP)`, `StorageInterface` & UTF-8 Search)
* **Date :** 2026-10-30 23:55
* **Issue GitHub associée :** `lightpath-training-portal#19`
* **Fichiers modifiés :** `src/Core/StorageInterface.php`, `src/Core/Security.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php`, `src/Core/Auth.php`, `src/Course/DownloadController.php`, `src/Course/CourseRepository.php`, `public/index.php`, `tests/run_tests.php`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Implémentation complète des 9 recommandations issues de l'audit `/ponytail-audit` et validées par la session `/dev-team` (PM, Architect, Developer, QA) : verrouillage atomique Read-Modify-Write `JsonStorage::mutateFile()` par fichier `.lock` (`flock(LOCK_EX)`) et transaction PDO dans `PdoStorage::mutateFile()`, neutralisation des formules CSV (`Security::sanitizeCsvCell()`), validation stricte des demi-journées d'émargement (`Security::isValidHalfDayKey()`), limitation de débit des connexions par couple `(email, IP)` (`Auth::isLoginRateLimited()`), vérification canonique des chemins (`Security::isPathInside()`), alignement des 29 méthodes dans `StorageInterface`, extraction d'extraits UTF-8 sûrs sans dépendance obligatoire à `mbstring` (`CourseRepository::extractUtf8SafeSnippet()`), et suppression des écritures redondantes (`recordLastVisited`, `saveFlashcardConfidence`).
* **Tests post-correction :** ✅ 201 / 201 passés dans `php tests/run_tests.php` (0 régression)

### Correction RCA-161 (`RCA-LMS-31`) — Revue Pédagogique `/teamwork` & Enrichissement SRS (48 Flashcards) et Glossaire (56 Termes) pour les Modules Spécialisés (`M14`, `M19`, `M26`..`M29`)
* **Date :** 2026-10-30 23:58
* **Issue GitHub associée :** `lightpath-training-portal#19`
* **Fichiers modifiés :** `src/Course/LmsCatalog.php`, `tests/run_tests.php`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Ajout de 6 nouvelles Flashcards SRS (`fc_43`..`fc_48` pour les modules `M14` CloudNativePG, `M19` `pgcopydb`, `M26` Partitionnement `DETACH CONCURRENTLY`, `M27` `pgBadger`, `M28` `postgres_fdw`, `M29` `pg_auto_failover` — total porté à 48 cartes) et de 4 nouveaux termes dans le Glossaire PostgreSQL 18 (`CloudNativePG`, `pg_auto_failover`, `pgBadger`, `pgcopydb` — total porté à 56 termes).
* **Tests post-correction :** ✅ 201 / 201 passés dans `php tests/run_tests.php` (0 régression)

---

### Correction RCA-162 (`RCA-LMS-32`) — Audit Pédagogique `/ponytail-audit` & Remédiation `/teamwork` : Rééquilibrage A/B/C/D de l'Examen Final (10/10/10/10) et du Diagnostic (4/4/4/3), Tagging `'module'` et Couverture `Parcours 4 (SQL-01..05)` des 53 Flashcards SRS
* **Date :** 2026-10-31 07:32
* **Fichiers modifiés :** `src/Course/LmsCatalog.php`, `tests/run_tests.php`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :**
  1. **Rééquilibrage exact 10/10/10/10 de l'Examen Final de Certification (`getFinalExamQuestions()`) :** Permutation des options `'A'`, `'B'`, `'C'`, `'D'` et alignement de `'correct'` / `'answer'` sur les 40 questions (`exam_01`..`exam_40`) pour garantir exactement **10 `'A'`, 10 `'B'`, 10 `'C'`, 10 `'D'`** (2 par lettre dans chacun des 5 domaines DBA, plafonnant toute réponse uniforme à `25 %` au lieu de `97,5 %`).
  2. **Rééquilibrage A/B/C/D du Diagnostic Pré-Formation (`getDiagnosticQuestions()`) :** Permutation des options sur les 15 questions (`diag_01`..`diag_15`) pour garantir exactement **4 `'A'`, 4 `'B'`, 4 `'C'`, 3 `'D'`**.
  3. **Tagging `'module'` et couverture `Parcours 4 (SQL-01..05)` des Flashcards SRS (`getFlashcards()`) :** Ajout du champ `'module'` (`M01`..`M29`) sur les 48 flashcards existantes (`fc_01`..`fc_48`) et création de 5 nouvelles flashcards (`fc_49` à `fc_53`, `'category' => 'SQL:2023'`, `'module' => 'SQL-01'` à `'SQL-05'`) couvrant l'intégralité du Parcours 4 (total porté à **53 flashcards SRS**).
* **Tests post-correction :** ✅ 265 / 265 passés dans `php tests/run_tests.php` (0 régression)

---

### Correction RCA-163 (`RCA-LMS-33`) — Audit `/ponytail-audit` de la Plateforme LMS (7 Correctifs) & Implémentation `/dev-team` des 12 Fonctionnalités LMS Entreprise & Qualiopi (Axes A–D)
* **Date :** 2026-10-31 23:18
* **Issues GitHub associées :** `lightpath-training-portal#23`, `training_postgresql#100`
* **Fichiers modifiés :** `src/Core/{Security,StorageInterface,JsonStorage}.php`, `src/Course/{CourseRepository,DownloadController}.php`, `src/Docker/{DockerManager,LabSqlRunner}.php`, `public/index.php`, `public/assets/js/app.js`, `templates/layout.php`, `templates/pages/{module_view,lab_view,documents,flashcards,attendance,satisfaction,exam,certificate,verify,notes,dashboard,admin}.php`, `tests/run_tests.php`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :**
  1. **Remédiation des 7 constats de l'audit `/ponytail-audit` :**
     - Extension de `Security::isValidHalfDayKey()` à `J1_AM..J11_PM` pour couvrir les cursus de 10 jours (70h).
     - Lecture de `$userProgress['flashcard_confidence']` dans `flashcards.php` et remplacement des compteurs codés en dur (`42 SRS`) par le compteur dynamique (`53 SRS`) dans `layout.php` et `dashboard.php`.
     - Sélection de `$allAttempts[0]` (tentative la plus récente insérée par `array_unshift`) au lieu de `end($allAttempts)` dans `exam.php`.
     - Recalcul déterministe côté serveur du score QCM dans `api_submit_quiz` (`public/index.php`) lorsque `answers` est fourni.
     - Écriture atomique du cache Docker (`DockerManager::writeStatusCache()`) via `.tmp` + `LOCK_EX` + `rename()`.
     - Extension de `CourseRepository::searchContent()` (`Ctrl+K`) aux documents Qualiopi (`referentiel`), outils DBA (`tools`), exemples multi-langages (`examples`) et cartes SRS (`flashcards`).
     - Suppression de la branche morte `$page === 'docker'`, factorisation de `renderDiscussionSection()` dans `layout.php` et affichage du badge `$fc['module']` sur les flashcards.
  2. **Implémentation des 12 fonctionnalités LMS Entreprise & Qualiopi (Axes A–D) :**
     - **Axe A :** Lecteur vidéo HTML5 Studio 1080p (`?mode=video` dans `module_view.php`) avec streaming HTTP `206 Partial Content`, sous-titres WebVTT et transcription interactive ; explorateur de code polyglotte `pgexamples/` (Python, Go, PHP, SQL) dans `documents.php` (`?page=documents&tab=examples`) ; filtres par module/niveau et Mode Focus dans `flashcards.php`.
     - **Axe B :** Filtre d'émargement par parcours (3j/5j/10j), contre-signature formateur (`signTrainerAttendance`) et impression OPCO dans `attendance.php` ; évaluation Qualiopi à froid J+60 (`saveColdSatisfactionSurvey`, `?page=satisfaction&tab=cold`) ; vérification publique de certificat (`?page=verify&code=...`, `findCertificateByCode`) et QR Code SVG 100 % offline (`Security::renderVerificationQrSvg`) sur `certificate.php`.
     - **Axe C :** Explorateur de schéma relationnel (`LabSqlRunner::inspectDatabaseSchema`), bouton `📊 EXPLAIN ANALYZE` 1-clic, historique SQL persistant et indices progressifs (`LabSqlRunner::extractLabHint`) dans `lab_view.php` ; carnet de notes centralisé (`?page=notes` dans `templates/pages/notes.php`) avec filtre et export Markdown.
     - **Axe D :** Diffusion d'annonce salle de classe en direct et verrouillage pédagogique des corrigés/examen (`getClassroomState` / `saveClassroomState`) ; modération formateur du forum Q&A (`resolveDiscussionMessage`, `deleteDiscussionMessage`, `getAllCourseDiscussions` + onglet `💬 Questions Q&A` dans `admin.php`) ; import CSV de cohorte en masse (`importUsersFromCsv`) et export de package `.zip` SCORM 1.2 complet (`DownloadController::buildScorm12ZipPackage`).
* **Tests post-correction :** ✅ 298 / 298 passés dans `php tests/run_tests.php` (0 régression)

---

### Correction RCA-164 (`RCA-LMS-34`) — Remédiation des Constats de la Revue `/ponytail-review` sur le Portail LMS (Items 1, 2, 3, 4, 5, 7, 8, 12) & Suite de Tests Contractuels Section 17
* **Date :** 2026-11-01 22:42
* **Issues GitHub associées :** `lightpath-training-portal#25`, `training_postgresql#101`
* **Fichiers modifiés :** `templates/pages/{admin,verify,lab_view,module_view,attendance}.php`, `public/index.php`, `templates/layout.php`, `src/Course/{CourseRepository,DownloadController}.php`, `src/Core/JsonStorage.php`, `tests/run_tests.php`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :**
  1. **Item 1 (Import CSV) :** Remplacement de `name="csv_payload"` par `name="csv_content"` dans `templates/pages/admin.php:L275` et lecture tolérante `$_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')` dans `public/index.php:L672`.
  2. **Item 2 (Vérification Publique Certificat) :** Autorisation d'accès à `?page=verify` sans session active (`$currentUser === null`) dans `public/index.php:L108-L113`, initialisation propre de `$userId = 0`, sécurisation du layout (`templates/layout.php`) avec masquage des widgets personnels et affichage d'un bouton « 🔐 Connexion », et protection contre `$currentUser === null` dans `templates/pages/verify.php:L52-L53`.
  3. **Item 3 (Verrouillage Corrigés) :** Contrôle de `lock_solutions` / `solutions_locked` pour les non-staff dans `api_run_lab_solution` (retour 403 JSON) et dans `action === 'download' && $type === 'lab'` (retour 403 HTTP) dans `public/index.php` ; filtrage des modes `html_preview` et `pdf_preview` en mode verrouillé, masquage de la barre de téléchargement de lab et masquage du bouton d'accès au corrigé de bas de page dans `templates/pages/lab_view.php`.
  4. **Item 4 (Transcription Vidéo Studio) :** Émission simultanée des clés `'video_transcript_path'` et `'transcript_md_path'` dans `CourseRepository.php:L479`, et lecture tolérante avec repli dans `templates/pages/module_view.php:L354`.
  5. **Item 5 (Streaming Vidéo Chunked) :** Remplacement de l'allocation mémoire globale `echo fread($fp, $length)` par une boucle de streaming par blocs de 64 Ko (`$readSize = min($remaining, $chunkSize)`) avec `ob_flush()` et `flush()` dans `DownloadController.php:L188-L194`.
  6. **Item 7 (Contre-signature Formateur J1) :** Prise en charge des clés `J<N>_DAY` et `J<N>` via `preg_match('/^J([1-9]|1[01])(?:_DAY)?$/i', $halfDayKey)` dans `JsonStorage.php:L660-L688` pour viser matin et après-midi en 1 clic ; envoi de `J<?= $dayNum ?>_DAY` et affichage fidèle de la contre-signature (journée complète vs matin vs après-midi) dans `templates/pages/attendance.php:L164-L185`.
  7. **Item 8 (Découverte Exemples SQL) :** Ajout de `glob($exDir . '/sql/dbclients/*.sql') ?: []` dans `CourseRepository.php:L758`, découvrant les 9 exemples SQL de `dbclients` (total porté à 27 exemples multi-langages).
  8. **Item 12 (Cache Catalogue & Invalidation) :** Ajout de l'alias `loadCourse(string $slug)`, ajout de `clearCatalogCache(?string $slug = null)`, sécurisation de l'écriture cache avec `LOCK_EX`, et actualisation de l'empreinte avec `v8_rca164` incluant le dossier `sql/dbclients` dans `CourseRepository.php`.
  9. **Item 9 (Section 17 de Tests TDD) :** Ajout de 18 assertions contractuelles couvrant l'ensemble des points RCA-164 / RCA-LMS-34 dans `tests/run_tests.php`.
* **Tests post-correction :** ✅ 316 / 316 passés dans `php tests/run_tests.php` (100% de réussite, 0 régression)

---

### Correction RCA-165 — Robustesse & Sécurité Interactive du Portail LMS (`RCA-LMS-35`)
* **Date :** 2026-11-02 00:20
* **Issue GitHub associée :** `lightpath-training-portal#27`
* **Fichiers modifiés :**
  - `src/Docker/LabSqlRunner.php` (injection de `PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"` dans `executeSql()`)
  - `src/Core/JsonStorage.php`, `templates/pages/verify.php` (conditionnement de `passed` et `certified` dans `findCertificateByCode()` et affichage distinct dans `verify.php` : certificat vert si `passed === true`, attestation d'évaluation ambre avec mention d'échec au seuil de 75% si `passed === false`)
  - `src/Core/StorageInterface.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php` (refonte de `importUsersFromCsv()` sous verrou atomique unique `mutateFile('users')` avec inscription dans `$courseSlug` ; ajout de `resetFlashcardReviews()`)
  - `public/index.php` (protection RBAC staff sur `export_scorm_manifest` et `export_scorm_zip` ; route `export_attendance_csv` pour le registre OPCO détaillé ; route `api_reset_flashcards` ; ajout de `classroom_state` dans `api_heartbeat` ; whitelist stricte de `return_page` dans `moderate_discussion`)
  - `templates/pages/attendance.php` (ajout du lien d'export CSV du registre d'émargement OPCO pour le staff)
  - `templates/pages/flashcards.php` (ajout du bouton `🔄 Réinitialiser SRS` avec confirmation et appel AJAX `api_reset_flashcards` ; gestion de l'état vide de filtrage)
  - `templates/layout.php`, `public/assets/js/app.js` (balise meta `current-course` ; conservation de `&course=` lors de l'appui sur Entrée dans la recherche globale ; mise à jour dynamique du bandeau d'annonce salle live via `api_heartbeat`)
  - `tests/run_tests.php` (ajout de la Section 18 couvrant l'ensemble des 9 contrats RCA-165 / RCA-LMS-35)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Implémentation des 9 améliorations contractuelles de robustesse, sécurité interactive et conformité Qualiopi pour le LMS (Milestone B2-M2).
* **Tests post-correction :** ✅ 326 / 326 tests passés dans `php tests/run_tests.php` (100% de réussite, 0 régression)

---

### Correction RCA-166 — Conformité du Rendu Markdown, Callouts CSS du Lecteur & Badges de Syntaxe (`RCA-LMS-36`)
* **Date :** 2026-11-02 01:05
* **Issue GitHub associée :** `lightpath-training-portal#29`
* **Fichiers modifiés :**
  - `public/assets/css/app.css` (ajout de la classe `.lms-callout-note` avec Cyan LightPath `#00B0F0`, définition de `.lms-code-badge` et règles de coloration syntaxique avec `tabular-nums` en palette Emil Kowalski Obsidian/Cyan)
  - `src/Course/MarkdownParser.php` (ajout de la méthode publique `parse()`, ajout de la classe `.lms-code-badge` sur le label de langage)
  - `storage/data/parsed_cache/` (invalidation et purge des 694 fichiers de cache Markdown obsolètes)
  - `tests/run_tests.php` (ajout de la Section 19 validant les alertes callouts, `.lms-callout-note`, les tags de code et la méthode `MarkdownParser::parse()`)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Résolution des anomalies de rendu Markdown du lecteur de formation LMS et des styles CSS manquants (Milestone B3-M2 / LMS).
* **Tests post-correction :** ✅ 342 / 342 tests passés dans `php tests/run_tests.php` (100% de réussite, 0 régression)

---

### Correction RCA-167 — Durcissement Sécurité, Concurrence Atomique, Mémoire Cache & Cas Limites Qualiopi du Portail LMS (`RCA-LMS-37`)
* **Date :** 2026-11-02 03:00
* **Issue GitHub associée :** `lightpath-training-portal#31`
* **Fichiers modifiés :**
  - `src/Core/JsonStorage.php` (vérification non-vide dans `writeFile`, protection anti-écrasement dans `mutateFile`, préservation des visas et nom formateur dans `signAttendance`, incorporation de l'horodatage dans le hash SHA-256 de signature et contre-signature, reconnaissance des attestations d'assiduité `LP-PG18-ATT-*` dans `findCertificateByCode`, nettoyage du BOM UTF-8 `\xEF\xBB\xBF`, détection auto des séparateurs CSV `;`, `,`, `\t` et validation de longueur mot de passe >= 6 dans `importUsersFromCsv`)
  - `src/Core/PdoStorage.php` (idempotence `INSERT ... ON CONFLICT (store_key) DO UPDATE` pour PostgreSQL et SQLite, `ON DUPLICATE KEY UPDATE` pour MySQL dans `writeFile` ; verrouillage ligne `FOR UPDATE` dans `mutateFile`)
  - `src/Docker/DockerManager.php` (synchronisation exclusive `flock(LOCK_EX)` sur `docker_status_cache.lock` dans `writeStatusCache` et `saveUserStatusCache`)
  - `src/bootstrap.php` (fuseau horaire officiel `date_default_timezone_set('Europe/Paris')`, paramétrage `secure` sur les cookies de session en HTTPS)
  - `src/Core/Auth.php` (déconnexion après inactivité > 4 heures / 14400 secondes dans `currentUser`, expiration du cookie de session et `session_destroy()` dans `logout`)
  - `src/Course/CourseRepository.php` (scan dynamique des mtimes de `03_supports_de_cours` et `04_ateliers_et_tp` dans `computeCatalogFingerprint` avec mémoisation statique mémoire pour temps de chargement warm < 25 ms, purge sélective dans `clearCatalogCache`)
  - `src/Course/MarkdownParser.php` (écriture atomique avec fichier temporaire `.tmp` et `LOCK_EX` dans `writeCacheFile`, méthode publique `clearParsedCache`)
  - `src/Docker/LabSqlRunner.php` (rejet strict des métacommandes psql interactives `\!`, `\copy`, `\o`, `\w`, `\i`, `\gexec`, interdiction des altérations `SET/RESET statement_timeout`, méthode alias `runSql`)
  - `templates/pages/attendance.php` (transmission de `target_user_id = 0` pour visa formateur collectif de promotion, support du filtrage `student_id`)
  - `templates/pages/certificate.php`, `templates/pages/verify.php` (construction dynamique d'URL de vérification basée sur `HTTP_HOST` pour QR Code hors-ligne et en ligne)
  - `templates/pages/admin.php` (onglet Caches & Système avec purge en 1 clic des caches catalogue et parseur Markdown)
  - `public/index.php` (route `admin_clear_cache`, inclusion des stagiaires inscrits absents dans l'export `export_attendance_csv` avec statut `ABSENT (0h)`)
  - `tests/run_tests.php` (ajout de la Section 20 validant les 14 correctifs contractuels, totalisant 374 assertions réussies)
  - `tests/test_adversarial_b3.php` (mise à niveau de la tolérance du nombre de tests >= 342)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Implémentation complète des 14 correctifs de durcissement (Boucle 4 — Pilier 4) : atomicité des écritures fichiers et PDO, prévention d'écrasement de cohortes, préservation des visas d'émargement, scellement temporel des signatures, attestations d'assiduité Qualiopi avec QR Code, import CSV robuste multi-délimiteurs avec détection de BOM, rejet des métacommandes shell interactives en console SQL, timeout d'inactivité de session, synchronisation cache Docker et réactivité sub-milliseconde du catalogue.
* **Tests post-correction :** ✅ 374 / 374 tests passés dans `php tests/run_tests.php` + ✅ 69/69 B1 + ✅ 50/50 B2 + ✅ 69/69 B3 (100% de réussite, 0 régression).

---

### Correction RCA-167 — Remédiation Adversaire B4 : Colmatage des 5 Contournements de Sécurité LabSqlRunner (`RCA-LMS-37`)
* **Date :** 2026-11-02 03:30
* **Issue GitHub associée :** `lightpath-training-portal#33`
* **Fichiers modifiés :**
  - `src/Docker/LabSqlRunner.php` (durcissement des expressions régulières de filtrage des métacommandes psql interactives et de `statement_timeout`)
  - `.gitignore` (exclusion de `/storage/cache/`)
  - `tests/test_adversarial_b4.php` (harnais d'évaluation empirique adverse de 78 assertions)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Colmatage des 5 failles critiques d'injection et de contournement identifiées empiriquement par l'Adversarial Challenger B4 :
  1. Blocage de `\connect` et `\c` (changement de base de données).
  2. Blocage de `\cd` (changement de répertoire conteneur).
  3. Blocage de `\!` précédé d'un commentaire multiligne SQL (`/* comment */ \! whoami`).
  4. Blocage de `\!` sur la même ligne après un point-virgule (`SELECT 1; \! whoami`).
  5. Blocage de `RESET ALL;` neutralisant la directive `statement_timeout`.
* **Tests post-correction :** ✅ 78 / 78 tests passés dans `php tests/test_adversarial_b4.php` (VERDICT: APPROVE) + ✅ 374 / 374 passés dans `php tests/run_tests.php` + ✅ 69/69 B1 + ✅ 50/50 B2 + ✅ 69/69 B3 (100% de réussite, 0 régression).

---

### Correction RCA-168 — Polissage Final, Ergonomie Admin, Export OPCO et Durcissement Console SQL du Portail LMS (`RCA-LMS-38`)
* **Date :** 2026-11-02 04:15
* **Issue GitHub associée :** `lightpath-training-portal#35`
* **Fichiers modifiés :**
  - `templates/pages/admin.php` (intégration de l'onglet `system` dans l'échelle `elseif` conditionnelle, suppression du bloc séparé provoquant le double affichage du Journal d'Audit Qualiopi sur l'onglet Système)
  - `public/index.php` (ajout de la colonne `Role` dans l'en-tête et les lignes de l'export CSV d'émargement `export_attendance_csv`, qualification des comptes staff par `PERSONNEL FORMATEUR` et `STAFF (N/A)` ; extraction de `return_student_id` et `return_track` pour redirection fidèle après contre-signature formateur)
  - `templates/pages/attendance.php` (conditionnement de l'affichage des formulaires d'émargement stagiaire à `$inspectedUserId === $userId` avec affichage neutre `— Non émargé —` en vue tiers ; transmission des champs cachés `return_student_id` et `return_track` dans les formulaires de contre-signature formateur)
  - `src/Docker/LabSqlRunner.php` (extension de la regex de sécurité pour bloquer préventivement les métacommandes psql d'édition système et interactives `\e`, `\ef`, `\ev`, `\prompt`, `\watch` et `\setenv`)
  - `templates/pages/exam.php` (harmonisation du verrouillage d'examen avec `!$auth->isStaff($currentUser)`)
  - `tests/run_tests.php` (ajout de la Section 21 couvrant les 6 points LMS-38-01 à LMS-38-06, totalisant 402 assertions passées à 100%)
  - `tests/test_adversarial_b5.php` (création du banc d'essai adverse empirique de la Boucle 5, 78/78 assertions passées avec verdict APPROVE)
  - `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`
* **Nature de la correction :** Implémentation des 6 améliorations et durcissements de polissage final de la Boucle 5 (RCA-168 / RCA-LMS-38).
* **Tests post-correction :** ✅ 402 / 402 tests passés dans `php tests/run_tests.php` (100% de réussite, 0 régression) et 5/5 suites adverses approuvées (B1: 69, B2: 50, B3: 69, B4: 78, B5: 78).

---

### Correction RCA-169 — Audit Qualité Ponytail (2 itérations) & Refonte 7 Axes du LMS (`RCA-LMS-39`)
* **Date :** 2026-11-15 23:59
* **Issue GitHub associée :** `lightpath-training-portal#39` (branche `feat/issue-39-ponytail-audit-7-axes`)
* **Fichiers modifiés :**
  - **Axe 1 — Sécurité & Sandbox SQL :** `src/Core/Auth.php` (`VALID_ROLES`, `canAccessCourse()` distinguant `null` vs `[]`, `changePassword()`, limitation anti-bruteforce persistante & `DUMMY_BCRYPT_HASH`), `src/Core/Security.php` (CSP `script-src 'self' 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='` sans `'unsafe-inline'`, `UNLOAD_SHIM_JS`, `renderUnloadShimScript()`), `src/Docker/LabSqlRunner.php` (rôle non-superutilisateur `lms_student`, scanner lexical `stripCommentsAndLiterals()` gérant `/* /* */ */`, `DO $$ ... $$`, `set_config` concaténé et `\w`/`\o`/`\g` hors littéraux), `public/index.php` (HTTP `400` sur `api_submit_quiz` / `api_toggle_complete` invalides, blocage slug `'postgresql-18'` dans `admin_add_course`, HTTP `403` `api_chaos` hors staff, passage de `$allowSharedFallback = $auth->isStaff()`).
  - **Axe 2 — Documentation & Traçabilité :** `README.md`, `PROJECT-CONTEXT.md`, `docs/{ARCHITECTURE,SECURITY,ADMIN_AND_TRAINER_GUIDE,DOCKER_LABS_GUIDE,COURSE_PACK_GUIDE,API_REFERENCE,QUALITY_AUDIT_PONYTAIL}.md`, `REGISTRE_RCA.md` (`RCA-169`), `JOURNAL_DE_BORD.md`.
  - **Axe 3 — Modularité & Feature Flags :** `config/features.php`, `src/Core/FeatureManager.php` (8 feature toggles + mémoïsation `$resolvedFlags` par requête), `src/Core/AdminService.php` (extraction KPI cohorte en une passe, filtres utilisateurs/audit, santé système), `src/Core/StorageInterface.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php` (fusion mensuelle `audit_logs_YYYYMM` via `getAuditLogs($limit, $includeArchives)` et `getLastError()`).
  - **Axe 4 — Cockpit Admin & Formateur :** `templates/pages/admin.php` (chargement paresseux par onglet, distinction `Aucun cours assigné` vs défaut `postgresql-18`, portée admin stricte), `src/Core/AdminService.php` (`getSystemHealthReport()` inspectant `parsed_cache/*.html`, `catalog_cache_*.json` et `$pdoCheck->getLastError()`), `public/assets/js/app.js` (câblage `[data-filter-table]` et `[data-filter-role-table]` sur `#cohort-matrix-table` et `#users-management-table`).
  - **Axe 5 — Conteneurs Docker & Seeder :** `src/Docker/SakilaSeeder.php` (12 tables Sakila + 4 tables `pgbench` 1 000 comptes + rôle `lms_student`), `src/Docker/DockerManager.php` (`execCommandWithTimeout()` non bloquant via `proc_open()`, quotas cgroup, `$allowSharedFallback = false` sur `seedUserContainer()` et `getContainerLogs()`), `bin/lab-runner.php`, `templates/pages/lab_view.php` (gating `docker_labs`).
  - **Axe 6 — UX, Streaming, Accessibilité & Responsive :** `src/Course/DownloadController.php` (`resolveHttpRange()` supportant HTTP `200`/`206`/`416 Range Not Satisfiable`), `src/Course/MarkdownParser.php` (`$sourceRoot/assets/mermaid_cache/` & `extractSlides()`), `templates/pages/login.php` (`defer` sur `app.js`, zéro `<script>` inline), `templates/pages/dashboard.php` (gating CTAs `docker_labs`), `public/assets/js/app.js` (`TextDecoder` UTF-8 SQL, `[data-demo-email]`, helper `buildApiUrl()`/`postApiJson()` avec `&course=`), `public/assets/css/app.css` (`--text-muted` WCAG AA `#94A3B8`/`#475569`, `tabular-nums`, `@media (max-width: 1024px)` et topbar `1200–1440px`).
  - **Axe 7 — Tests, Couverture & Commentaires Anglais :** `tests/run_tests.php`, `tests/test_functional_http.php`, `tests/test_coverage_audit.php`, `tests/test_adversarial_b1..b5.php`, `tests/e2e/{run_playwright_campaign,test_adversarial_challenger}.mjs` (traduction commentaires source en anglais technique et extension de la couverture QA).
* **Nature de la correction :** Consolidation complète en 2 itérations (`/ponytail-audit` + `/dev-team` 4 rôles) résolvant les 20 constats initiaux de l'Itération 1 puis les 15 écarts résiduels (5 P0, 8 P1, 2 P2) de l'Itération 2 sans régression sur la conformité Qualiopi/Datadock/SCORM 1.2 ni l'identité exclusive LightPath SARL (zéro CDN externe).
* **Tests post-correction :**
  - Base certifiée Vague 3 (pré-Vague 4A) : ✅ `48/48` `php -l` (`0` erreur) ; ✅ `509/509` `tests/run_tests.php` ; ✅ `70/70` `tests/test_functional_http.php` ; ✅ `160/161` (`99,4 %`) `tests/test_coverage_audit.php` (`0` CDN, `0` marque tierce, `0` `on*=` dans `templates/`) ; ✅ `B1` (`69/69`), `B2` (`50/50`), `B3` (`69/69`), `B4` (`78/78`), `B5` (`78/78`).
  - Vérifications ciblées Vague 4A post-édition : ✅ `sec-core` `12/12` `php -l` + `ALL_SEC_CORE_W4_CHECKS_PASSED` ; ✅ `containers-labs` `6/6` `php -l` + `182/182` vérifications isolées (`COMMENT_ACCENT_HITS: 0`) ; ✅ `admin-ui` `6/6` `php -l` + `node --check public/assets/js/app.js` + `WAVE4A_CHECKS_OK` (`TOTAL_VERIFY_ERRORS=0`, `FRENCH_ACCENT_COMMENTS=0`).
  - Certification finale Vague 4B (`qa-browser` `#30` & Sign-Off Navigateur Itération 2 `#33`, 100 % PASS) : ✅ `48/48` `php -l` (`ERRORS=0`) ; ✅ `node --check` exit `0` (`app.js`, `run_playwright_campaign.mjs`, `test_adversarial_challenger.mjs`) ; ✅ `541/541` `tests/run_tests.php` (incluant Section 23 Vague 4 & `Auth::canAccessCourse([])`) ; ✅ `88/88` `tests/test_functional_http.php` (isolation `LMS_DATA_DIR` + `LMS_DOCKER_FORCE_SIMULATED=1`, garde-fous HTTP négatifs P0/P1 & feature flags) ; ✅ `162/162` (`100,0 %`) `tests/test_coverage_audit.php` (`0` CDN externe, `0` marque tierce, `0` attribut `on*=` dans `templates/`, `0` commentaire non-anglais PHP/JS/CSS/Python/E2E `.mjs`, CSP strict `script-src` SHA-256 `PASS`) ; ✅ Suites adverses `B1` `69/69`, `B2` `50/50`, `B3` `69/69`, `B4` `78/78`, `B5` `78/78` (`APPROVE`) ; ✅ Sign-Off Navigateur Live Itération 2 (`http://127.0.0.1:18089`, zéro erreur console/CSP) validant connexion démo CSP via `assets/js/app.js`, alignement topbar Desktop `1440x900` (`scrollWidth === 1440`), Lab 01 (`postgres`, Schema Explorer `8` tables, blocage `COPY actor TO PROGRAM 'id'`), filtres Admin Cohorte (`#admin-cohort-search`) & Utilisateurs/Rôles (`#admin-user-search` / `#admin-user-role-filter`), Santé Système (`parsed_cache`, `catalog_cache`, `8` feature flags), sidebar repliée par défaut en Tablette `768x1024` (`<= 1024px`, `scrollWidth <= 768`), et `0` fuite de marque tierce.

---

### Correction RCA-170 — Exécution Approfondie en 2 Itérations sur les 7 Axes (`RCA-LMS-40` / Issue #41)
* **Date :** 2026-11-16 01:45
* **Issue GitHub associée :** `lightpath-training-portal#41` (branche `feat/issue-41-deep-7-axis-iterations`)
* **Fichiers modifiés et créés :**
  - **Axe 1 — Sécurité & CSP Strict :** `config/app.php` (`session_idle_ttl = 7200`), `src/bootstrap.php` (alignement `session.gc_maxlifetime` et `lifetime`), `src/Core/Security.php` (`style-src 'self'` sans `'unsafe-inline'`, `buildCspPolicy()`, `sendStandaloneDocumentCspHeader(array $extraScriptHashes = [])`, `verifySameOriginRequest()`, suppression `style=` sur `.lms-qr-verify-svg`), `src/Core/Auth.php` (`isUserActive()`, `session_regenerate_id(true)` dans `changePassword()`), `src/Core/StorageInterface.php` & `src/Core/JsonStorage.php` (chaînage SHA-256 `prev_hash`/`entry_hash` + `verifyAuditIntegrity()`, `setUserActive()`, `adminResetUserPassword()`, `listLoginThrottles()`, `clearLoginThrottle()`, `getCourseFeatureOverrides()`, `saveCourseFeatureOverrides()`), `src/Course/DownloadController.php` (hachage SHA-256 des `<script>` inline d'aperçus HTML autonomes passé à `sendStandaloneDocumentCspHeader()`).
  - **Axe 2 — Documentation & Gouvernance :** Création de `docs/DEPLOYMENT_AND_OPERATIONS.md` & `docs/DEVELOPER_AND_TESTING_GUIDE.md` ; mise à jour complète de `README.md`, `PROJECT-CONTEXT.md`, `docs/{ARCHITECTURE,SECURITY,ADMIN_AND_TRAINER_GUIDE,DOCKER_LABS_GUIDE,COURSE_PACK_GUIDE,API_REFERENCE,QUALITY_AUDIT_PONYTAIL}.md`, `REGISTRE_RCA.md` (`RCA-170`) et `JOURNAL_DE_BORD.md`.
  - **Axe 3 — Modularité & Feature Flags :** Création de `src/Http/{AuthController,LearnerController,LabApiController,AdminController}.php`, refactorisation de `public/index.php` (`347` lignes, `405 Method Not Allowed` sur les requêtes `GET` aux API mutantes), création des 7 partials `templates/partials/admin/tab_{cohort,users,qna,docker,courses,audit,system}.php`, extension de `config/features.php` & `src/Core/FeatureManager.php` (`10` flags dont `personal_notes` et `flashcards_srs`, dépendances `requires`, `4` profils de déploiement, surcharges par cours, export/import JSON).
  - **Axe 4 — Cockpit Admin & Formateur :** `src/Core/AdminService.php` (`detectAtRiskLearners()`, `computeModuleCompletionMatrix()`, `exportCohortCsv()`, `exportQualiopiJson()`, `buildAuditExportMetadata()`, `7` sondes Santé Système avec `Chaîne Audit SHA-256`), `src/Http/AdminController.php` (presets 1-clic, suspension/réinitialisation/actions groupées, déblocage throttle, gardes `$targetId === $selfId` sur `admin_update_user`/`admin_delete_user`, export/import JSON flags), `templates/partials/admin/tab_{cohort,users,docker,audit,system}.php` (préfixes d'audit alignés dans `tab_audit.php`, grille `.admin-split-grid` dans `tab_system.php`).
  - **Axe 5 — Conteneurs Docker & Exercices :** `src/Docker/DockerManager.php` (`createSavepoint()`, `listSavepoints()`, `restoreSavepoint()`, `deleteSavepoint()`, `runPgbenchBenchmark()` normalisant `prefix` vers `'docker'` via `runDockerCommand()`, `touchContainerActivity()`, `getFleetSummary()`, `reapIdleContainers()`, `healChaosScenario()`), `src/Docker/SakilaSeeder.php` (`pg_read_all_stats` + `pg_stat_scan_tables`), `src/Docker/LabSqlRunner.php` (`inspectPgDiagnostics()`, `checkLabReadiness()` sur les 34 labs), `bin/lab-runner.php` & `bin/sync-course.php`.
  - **Axe 6 — UI Zero-Inline-Style & Ergonomie :** `public/assets/css/app.css` (`.admin-split-grid`, `.table-col-nowrap`, `.lms-qr-verify-svg`, `.login-split-grid`, `.cert-print-frame`, `.workbench-subtoolbar`, `.at-risk-badge`, `.integrity-pill-ok`, `.integrity-pill-error`, `.preset-toolbar`, classes utilitaires), `public/assets/js/app.js` (hydratation CSSOM `data-progress`/`data-accent`, `[data-bulk-select-all]`, `Ctrl+Enter`, boutons Savepoints/`pgbench`/Diag PG18/Readiness), `templates/layout.php`, `templates/pages/admin.php` & `15` pages modifiées sous `templates/pages/` (`0` attribut `style=` ; échappement ES `v`-mode `pattern="[a-zA-Z0-9_\-]{1,32}"` dans `templates/pages/lab_view.php`).
  - **Axe 7 — Tests, Couverture & Commentaires Anglais :** `src/Course/MarkdownParser.php` (commentaire traduit), `tests/run_tests.php` (Section 24 + `s22RequiredDocs` 11 docs), `tests/test_functional_http.php`, `tests/test_coverage_audit.php` (`src/Http` + audit `style=` + validation `pattern=` `v`-mode), `tests/e2e/{run_playwright_campaign,test_adversarial_challenger}.mjs`.
* **Nature de la correction :** Éradication totale des derniers reports (`P1-6` CSP strict `style-src 'self'` + empreintes SHA-256 des `<script>` d'aperçus HTML autonomes, `P1-7` modularisation `src/Http/*` et `templates/partials/admin/*`) et enrichissement industriel sur les 7 axes en 2 itérations (`/ponytail-audit` + `/dev-team` + 2 campagnes navigateur live `@playwright/mcp`), avec zéro élément différé.
* **Tests post-correction (`LMS_DOCKER_FORCE_SIMULATED=1`) :**
  - ✅ `php -l` (`59` fichiers PHP sur `config/`, `src/` incl. `src/Http/`, `public/`, `templates/` incl. `templates/partials/admin/`, `bin/`, `tests/`) : **`59 / 59` valides (`0` erreur)**
  - ✅ `node --check` (`public/assets/js/app.js`, `tests/e2e/run_playwright_campaign.mjs`, `tests/e2e/test_adversarial_challenger.mjs`) : **`3 / 3` valides (`exit 0`)** ; ✅ `python -m py_compile bin/export_slides_cache.py` : **`exit 0`**
  - ✅ `php tests/run_tests.php` : **`641 / 641` passés (`0` échec)**
  - ✅ `php tests/test_functional_http.php` (isolation `LMS_DATA_DIR`, `LMS_DOCKER_FORCE_SIMULATED=1`) : **`155 / 155` passés (`0` échec, `0` erreur/warning stderr serveur)**
  - ✅ `php tests/test_coverage_audit.php` : **`211 / 211` méthodes API publiques (`100,0 %` sur `Core`/`Course`/`Docker`/`Http`)**, `0` fuite fournisseur tiers, `0` CDN externe, `0` `on*=`, `0` attribut `style=`, `0` attribut `pattern=` invalide en mode `v`, `0` commentaire non-anglais, CSP strict `script-src` & `style-src 'self'` **`PASS`**
  - ✅ Suites adverses `tests/test_adversarial_b1.php`..`b5.php` : **`B1` `69/69`, `B2` `50/50`, `B3` `69/69`, `B4` `78/78`, `B5` `78/78` — toutes `APPROVE`**
  - ✅ Sign-Off Navigateur Live Itération B (`http://127.0.0.1:18090`, `@playwright/mcp` vérifié sur hôte) : **`0` violation CSP** (y compris `mode=html_preview`), **`0` erreur console JS**, **`0` débordement horizontal** sur `1440x900`, `768x1024` et `375x812`, grille `.admin-split-grid` Santé Système nette, filtres d'audit alignés, pastille d'intégrité SHA-256 et workflow Savepoint Create/Restore validés.
  - ✅ Validation Python des `13` fichiers Markdown (`README.md`, `PROJECT-CONTEXT.md`, `9` guides `docs/*.md`, `REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`) : **`CHECKED_FILES=13`, `ERRORS=[]`** (liens relatifs valides, syntaxe Mermaid saine, `0` CDN externe, `0` marque tierce, LaTeX conforme).

---

### Correction RCA-171 — Préparation Open-Source MIT v2.0.0, Relocalisation du Dépôt, Pack Démo Autonome, Durcissement Premier Démarrage & Outillage Docker / CI (`RCA-LMS-41` / Issue #43)
* **Date :** 2026-11-17 00:10
* **Issues GitHub associées :** `lightpath-training-portal#43` (branche `feat/mit-open-source-v2`) & `training_postgresql#116` (branche `chore/relocate-portal-tests`)
* **Fichiers modifiés et créés :**
  - **Vague 1 — Relocalisation & Archivage (`mover`) :** Déplacement du dépôt vers `Gestion2026\EnCours\lightpath-training-portal` avec recréation des 3 jonctions NTFS (`images`, `logos`, `mermaid_cache`), déplacement `git mv` de `REGISTRE_RCA.md` et `JOURNAL_DE_BORD.md` vers `docs/internal/`, et mise à jour des chemins croisés dans `training_postgresql/tests/{test_adversarial_rule_governance.py,test_rca_contracts.py}` (`_get_lms_root()`, `_get_lms_internal_doc()`).
  - **Vague 2 — Pack Démo Autonome (`coursepack`) :** `examples/demo-course/**` (2 modules, 1 lab 7 piliers Qualiopi, cheatsheets, programme, `demo_connect.py`, `pg_health_demo.py`, stub MP4 Range), `config/{courses,app}.php`, `bin/{sync-course.php,export_slides_cache.py}`, `src/Course/MarkdownParser.php`.
  - **Vague 2 — Durcissement Premier Démarrage (`authsec`) :** `config/features.php` (`demo_quick_login => false`), `templates/pages/login.php` (suppression valeurs pré-remplies & fallback `false`), `src/Core/{JsonStorage,StorageInterface}.php` (`LMS_INIT_*_PASSWORD` / génération CSPRNG `initial_admin_credentials.txt`).
  - **Vague 2 — Conteneurs, CI & Manifestes (`devops`) :** `.github/workflows/ci.yml`, `Dockerfile`, `.dockerignore`, `docker-compose.yml`, `.env.example`, `composer.json` (`MIT`, `php >= 8.3`, extensions optionnelles en `suggest`), `package.json`, `.gitignore`.
  - **Vague 2 — Documentation Open-Source MIT (`ossdocs`) :** Création de `LICENSE` (MIT), `SECURITY.md`, `CONTRIBUTING.md`, `CHANGELOG.md` (`[2.0.0] - 2026-11-16`), mise à jour complète de `README.md` (badge CI natif GitHub Actions, Quickstart Docker Compose & PHP + `examples/demo-course/` + `LMS_COURSE_ROOT`, section identifiants au premier démarrage, section Licence MIT vs cours commerciaux, index `docs/internal/`), consignation `RCA-171` (`RCA-LMS-41`) et entrée `JOURNAL_DE_BORD.md`.
  - **Vague 3 — Adaptation des Tests & Bascule Démo/PG18 (`testauthor`) :** `tests/run_tests.php` (`$isFullPg18Course`, Section 24 dynamique, Section 25 MIT & amorçage aléatoire), `tests/test_functional_http.php`, `tests/test_adversarial_b1..b5.php` et `tests/e2e/{run_playwright_campaign,test_adversarial_challenger}.mjs`.
* **Nature de la correction :** Découplage du dépôt de cours commercial, mise en conformité open-source MIT v2.0.0 avec pack de démonstration autonome out-of-the-box, suppression des mots de passe codés en dur au profit d'un amorçage CSPRNG ou par variables d'environnement, ajout du déploiement Docker Compose 1-commande et du pipeline GitHub Actions CI, et archivage des journaux RCA internes sous `docs/internal/`.
* **Tests post-correction :**
  - ✅ Mode A (autonome `examples/demo-course/`, `LMS_DOCKER_FORCE_SIMULATED=1`) : `php -l` **`59/59`**, `node --check` **`3/3`**, `php tests/run_tests.php` **`644/644`**, `tests/test_adversarial_b1..b5.php` **`69/50/69/78/78`** (`344/344` `APPROVE`), `php tests/test_functional_http.php` **`155/155`**, `php tests/test_coverage_audit.php` **`211/211`** (`100.0%`, `AUDIT PASSED`).
  - ✅ Mode B (`training_postgresql` monté via `LMS_COURSE_ROOT` & `LMS_PGSCRIPTS_ROOT`, `LMS_DOCKER_FORCE_SIMULATED=1`) : `php tests/run_tests.php` **`658/658`** (`34` modules/labs, `58` slides Studio M01, Section 25).
  - ✅ Dépôt compagnon `training_postgresql` : `pytest tests/test_rca_contracts.py -q` **`256/256`** passés (`0` régression).



