# 🧭 Guide de Parcours : Git — Les Bases Essentielles (1 Jour / 7h)

> **Formation :** Git — Les Bases Essentielles du Versionnement & de la Collaboration
> **Durée :** 7h (1 Jour)
> **Objectif Pédagogique (Bloom C1–C3) :** Comprendre et appliquer le cycle de vie Git local, les branches et la collaboration sur dépôts distants
> **Formateur :** Jean-Marie Renouard — LightPath SARL

Ce guide présente l'architecture pédagogique du pack autonome **Les Bases de Git** (Licence MIT), conçu pour rendre les participants immédiatement opérationnels sur le versionnement distribué en une journée intensive.

---

## 1. Organisation de la Journée (7h • 1 Jour)

| Demi-Journée | Séquence | Modules & Ateliers | Objectif Opérationnel |
| :--- | :--- | :--- | :--- |
| **Matin (3h30)** | Cycle de vie local & Branches | Module 01 + Lab 01, Module 02 (partie 1) | Initialiser un dépôt, indexer (`git add`), valider (`git commit`), filtrer via `.gitignore` et créer des branches isolées |
| **Après-Midi (3h30)** | Conflits & Collaboration distante | Module 02 + Lab 02, Module 03 + Lab 03 | Résoudre un conflit de fusion (`git merge`), synchroniser un dépôt distant (`fetch`, `pull`, `push`) et mener une Pull Request |

---

## 2. Cartographie Complète des Ressources

| Code | Support de Cours | Atelier Pratique (7 Piliers Qualiopi) | Fiche Mémo Module | Fiche Mémo Lab |
| :--- | :--- | :--- | :--- | :--- |
| **01** | [Module 01 : Initialisation & Commits](03_supports_de_cours/module_01_git_init_commits_historique.md) | [Atelier 01 : Premier Dépôt & Commits](04_ateliers_et_tp/lab_01_premier_depot_et_commits.md) | [Mémo Mod 01](05_cheatsheets/modules/cheatsheet_mod_01_commandes_locales.md) | [Mémo Lab 01](05_cheatsheets/labs/cheatsheet_lab_01_init.md) |
| **02** | [Module 02 : Branches & Conflits](03_supports_de_cours/module_02_git_branches_fusion_conflits.md) | [Atelier 02 : Branches & Conflit Merge](04_ateliers_et_tp/lab_02_branches_et_conflit_merge.md) | [Mémo Mod 02](05_cheatsheets/modules/cheatsheet_mod_02_branches_merge.md) | [Mémo Lab 02](05_cheatsheets/labs/cheatsheet_lab_02_conflits.md) |
| **03** | [Module 03 : Dépôts Distants & PR](03_supports_de_cours/module_03_git_depots_distants_collaboration.md) | [Atelier 03 : Sync Remote & PR](04_ateliers_et_tp/lab_03_synchronisation_remote_pull_request.md) | [Mémo Mod 03](05_cheatsheets/modules/cheatsheet_mod_03_remotes_pr.md) | [Mémo Lab 03](05_cheatsheets/labs/cheatsheet_lab_03_remotes.md) |

---

## 3. Outils & Exemples Compagnons Inclus

* **Outil d'audit (`pgscripts/pg_git_repo_audit.py`) :** script Python standard-library analysant la branche active, l'état du répertoire de travail, la présence du fichier `.gitignore`, l'historique récent et l'absence de marqueurs de conflit résiduels.
* **Validateur Conventional Commits (`04_ateliers_et_tp/pgexamples/python/check_commit_msg.py`) :** exemple Python de hook `commit-msg` garantissant la conformité des messages de validation (`feat:`, `fix:`, `docs:`, `chore:`).
* **Documentation d'exemples :** consultez [README_git_examples.md](04_ateliers_et_tp/pgexamples/README_git_examples.md) pour la mise en œuvre pas à pas dans un dépôt local.
