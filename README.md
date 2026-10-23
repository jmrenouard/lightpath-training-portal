# LightPath Training Portal — Plateforme LMS Multi-Formations (PHP 8.3 & Docker Labs)

> **Éditeur :** LightPath SARL (`contact@lightpath.fr` | [https://www.lightpath.fr](https://www.lightpath.fr))  
> **Concepteur & Formateur :** Jean-Marie Renouard (Expert Bases de Données & DataOps, Co-développeur de MySQLTuner)  
> **Conformité :** Référentiels Nationaux **Qualiopi** & **Datadock** — Taxonomie de Bloom

---

## 1. Présentation Générale

**`lightpath-training-portal`** est une plateforme web complète de gestion et de diffusion de formations en ligne (LMS — *Learning Management System*), développée en **PHP 8.3** sans dépendance lourde, conçue pour être :
1. **Facilement hébergeable sur le Web** (persistance hybride Flat-File JSON atomique zéro-config par défaut + connecteur PDO PostgreSQL/MySQL/SQLite).
2. **Réutilisable pour toutes vos formations** grâce à son moteur déclaratif multi-formations (`config/courses.php` et ajout dynamique depuis l'interface d'administration).
3. **Interactive et orientée pratique** avec un **Orchestrateur Docker par Utilisateur**, une **Console SQL en direct dans le navigateur**, des **Quiz QCM auto-corrigés**, et un **Centre de Téléchargement Multi-Formats** (`PDF`, `Slides PDF`, `PPTX`, `MD`, `HTML`, `DOCX`).

---

## 2. Fonctionnalités Clés

### 📚 Catalogue & Diffusion Multi-Formats
- Intégration native des **4 Parcours Certifiants PostgreSQL 18** :
  - **Parcours 1 :** Cursus Complet PostgreSQL 18 (Modules 01 à 20)
  - **Parcours 2 :** Spécialisation Performance Tuning & Query Optimization — Méthode RALPH (Modules 21 à 25)
  - **Parcours 3 :** Ingénierie Spécialisée — Partitionnement, pgBadger/Prometheus, FDW, `pg_auto_failover` (Modules 26 à 29)
  - **Parcours 4 :** Fondamentaux & Pratique Approfondie SQL:2023 sur Sakila (Modules SQL 01 à 05)
- **Liens de téléchargement directs** sur chaque ressource :
  - Supports de cours : `.pdf`, `_slides.pdf`, `.pptx`, `.md`, `.html`, `.docx`
  - Ateliers pratiques (34 Labs) : `.pdf`, `.md`, `.html`, `.docx`
  - Fiches mémo (69 Cheatsheets) : `.pdf`, `.md`, `.html`, `.docx`
  - Programme, Syllabus, Guide Formateur, Examen & Référentiel Qualiopi (18 documents)
  - Boîte à outils DBA `pgscripts` (35 scripts Python & SQL)

### 🐳 Orchestration Docker par Utilisateur & Console SQL Live
- Chaque utilisateur connecté dispose d'un conteneur PostgreSQL 18 isolé (`lp_pg18_user_<id>`, port dynamique `5500 + id`) démarrable, réinitialisable et arrêtable en 1 clic depuis l'interface web.
- **Console SQL Interactive** intégrée dans chaque Atelier Pratique avec envoi en 1 clic des blocs SQL de l'énoncé (« ▶ Exécuter dans le Lab SQL »).
- **Oracle de Validation Qualiopi en 1 clic** vérifiant les assertions sur l'instance Docker de l'étudiant et validant automatiquement l'atelier.
- **Simulateur de Pannes Chaos Engineering DBA** (Bloat, Verrous bloquants, Sessions Idle in Transaction, Pression WAL) pilotable par le formateur.

### 🛡️ Gestion des Comptes & Portail d'Administration
- **3 Rôles RBAC :**
  - `admin` (`jmrenouard@lightpath.fr` / `LightPath2026!`)
  - `trainer` (`formateur@lightpath.fr` / `Formateur2026!`)
  - `student` (`stagiaire@lightpath.fr` / `Stagiaire2026!`)
- Tableau de suivi de cohorte en temps réel, gestion des utilisateurs, ajout de nouvelles formations, journal d'audit et génération d'**Attestation de Compétences Qualiopi** imprimable.

---

## 3. Démarrage Rapide

```bash
# 1. Exécuter la suite de tests automatisés
php tests/run_tests.php

# 2. Démarrer le serveur web local sur http://localhost:8080
php -S localhost:8080 -t public
```
