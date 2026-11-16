# 📘 Programme Démo Open-Source : Découverte PostgreSQL 18

> **Éditeur :** LightPath SARL — Jean-Marie Renouard
> **Licence du Pack Démo :** MIT License (Open-Source)
> **Référence Qualiopi :** LP-LMS-DEMO-PG18

## 1. Objectif du Pack de Démonstration

Ce pack autonome permet d'évaluer immédiatement toutes les fonctionnalités du portail **LightPath Training Portal** sans dépendre d'un référentiel de formation commercial externe :

* Parcours pédagogique structuré (Modules 01 et 02).
* Diaporama interactif segmenté automatiquement depuis Markdown.
* Schémas d'architecture Mermaid et callouts pédagogiques.
* Quiz d'évaluation formative QCM avec correction argumentée.
* Atelier pratique Qualiopi en 7 piliers avec oracle SQL `VALIDATION_OK`.
* Fiches mémo, scripts DBA Python et lecteur vidéo MP4 avec support HTTP Range `206`.

## 2. Contenu Inclus

| Ressource | Description | Niveau Bloom |
| :--- | :--- | :--- |
| Module 01 | Introduction & Architecture PostgreSQL 18 (AIO, MVCC, WAL) | Bloom C2 |
| Module 02 | Premiers Pas, Cluster `initdb` & Configuration `ALTER SYSTEM` | Bloom C3 |
| Atelier 01 | Prise en Main & Diagnostic PostgreSQL 18 (Oracle Qualiopi) | Bloom C3 |
| Fiches Mémo | Synthèses rapides Module 01 & Atelier 01 | Référence |
| Outils & Exemples | `pg_health_demo.py` et `demo_connect.py` | Pratique |

## 3. Montage d'un Cursus Complet

Pour connecter un référentiel de cours complet externe, définissez la variable d'environnement `LMS_COURSE_ROOT` vers votre répertoire de formation cible puis rechargez le portail.
