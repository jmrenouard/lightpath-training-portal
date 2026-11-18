# 📘 Programme de Formation : Git — Les Bases Essentielles du Versionnement & de la Collaboration

> **Éditeur :** LightPath SARL — Jean-Marie Renouard
> **Licence du Pack :** MIT License (Open-Source)
> **Référence Qualiopi :** LP-GIT-BASES-01
> **Durée :** 7h (1 Jour • 3 Modules + 3 Ateliers)

---

## 1. Objectifs Pédagogiques & Taxonomie de Bloom (C1–C3)

À l'issue de cette journée de formation, les apprenants sont capables de :

* **Connaître & Comprendre (Bloom C1–C2) :** expliquer l'architecture distribuée de Git et distinguer les trois zones locales (Working Directory, Index/Staging, Dépôt `.git`).
* **Appliquer (Bloom C3) :** initialiser un dépôt, exclure les artefacts sensibles via `.gitignore`, enregistrer des commits atomiques et inspecter l'historique (`git log`, `git diff`).
* **Appliquer (Bloom C3) :** créer, basculer et fusionner des branches isolées (`git switch`, `git merge`) tout en résolvant méthodiquement les conflits de fusion.
* **Appliquer (Bloom C3) :** synchroniser un dépôt distant (`git clone`, `git remote`, `git fetch`, `git pull`, `git push`) et conduire un workflow de Pull Request propre.

---

## 2. Public Cible & Prérequis

* **Public visé :** Développeurs, Ingénieurs DevOps, Data Engineers, Administrateurs Systèmes et Chefs de Projet Technique.
* **Prérequis :** Pratique de base d'un terminal (Linux, macOS ou Git Bash sous Windows) et édition de fichiers texte.
* **Modalités techniques :** Environnement autonome sans dépendance externe (`git` CLI local, dépôts `--bare` locaux pour les ateliers collaboratifs et outillage Python 3 standard library).

---

## 3. Programme Détaillé & Correspondance Modules / Ateliers

| Horaires | Séquence Pédagogique | Contenu Technique & Compétences Visées | Livrables & Évaluation |
| :--- | :--- | :--- | :--- |
| **09h00 – 10h30** | **Module 01 :** Dépôt Local, Index & Commits | `git init`, `git config`, `git status`, `git add`, `git commit`, `git log`, `git diff`, `.gitignore` | Quiz QCM M01 (3 questions) |
| **10h30 – 11h30** | **Atelier 01 :** Premier Dépôt & Commits | Amorçage du projet `billing-api`, `.gitignore` sécurisé, commits conventionnels | Oracle Bash `VALIDATION_OK` |
| **11h30 – 12h30** | **Module 02 :** Branches, Switch & Fusion | Pointeurs `HEAD`, `git switch -c`, Fast-Forward vs 3-way merge, marqueurs de conflit | Quiz QCM M02 (3 questions) |
| **13h30 – 14h45** | **Atelier 02 :** Branches & Conflit Merge | Développement parallèle sur `checkout-service` et résolution d'un conflit sur `pricing.py` | Oracle Bash `VALIDATION_OK` |
| **14h45 – 16h00** | **Module 03 :** Dépôts Distants & Pull Requests | `git clone`, `git remote`, `git fetch` vs `git pull`, `git push -u`, workflow Pull Request | Quiz QCM M03 (3 questions) |
| **16h00 – 17h00** | **Atelier 03 :** Sync Remote & Pull Request | Synchronisation multi-développeurs sur dépôt `--bare` et intégration de branche PR | Oracle Bash `VALIDATION_OK` |

---

## 4. Dispositif d'Évaluation & Qualiopi

* **Évaluation formative continue :** 3 questions QCM interactives argumentées à la fin de chaque module (9 questions au total).
* **Évaluation pratique opérationnelle :** 3 ateliers structurés selon les **7 piliers Qualiopi** (scénario métier réel, amorçage idempotent, consignes pas-à-pas, artefacts témoins, défi expert, oracle automatisé `VALIDATION_OK`, débriefing REX).
* **Ressources complémentaires :** 6 fiches mémo synthétiques (`05_cheatsheets/`) et utilitaires Python de contrôle qualité (`pgscripts/pg_git_repo_audit.py`).
