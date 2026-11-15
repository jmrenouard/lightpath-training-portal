<?php
declare(strict_types=1);

/**
 * Multi-course registry (Course-Pack Engine).
 * Connects any past or future course pack by pointing to its source root
 * or a synchronized course bundle under storage/courses/<slug>.
 */

$pgSourceRoot = realpath(__DIR__ . '/../../training_postgresql') ?: (__DIR__ . '/../../training_postgresql');
$pgScriptsRoot = realpath(__DIR__ . '/../../pgscripts') ?: (__DIR__ . '/../../pgscripts');

return [
    'postgresql-18' => [
        'slug' => 'postgresql-18',
        'title' => 'PostgreSQL 18 — Cursus Complet, Architecture, Haute Disponibilité & Performance Tuning',
        'short_title' => 'PostgreSQL 18 Enterprise',
        'badge' => 'PostgreSQL 18',
        'category' => 'Bases de Données & DataOps',
        'level' => 'Tous niveaux à Expert DBA (Bloom C01–C29)',
        'duration' => '77h (4 Parcours Certifiants Qualiopi)',
        'instructor' => 'Jean-Marie Renouard',
        'icon' => '/assets/img/postgresql_elephant.png',
        'active' => true,
        'docker_enabled' => true,
        'source_root' => $pgSourceRoot,
        'pgscripts_root' => $pgScriptsRoot,
        'description' => 'Formation industrielle complète sur PostgreSQL 18 : architecture interne, AIO, administration, sécurité SCRAM/RLS, réplication Patroni/pg_auto_failover, sauvegardes pgBackRest, Kubernetes/CloudNativePG, développement Python/PHP/Go, langage SQL:2023 et spécialisation Performance Tuning (Méthode RALPH).',
        'tracks' => [
            'cursus-complet' => [
                'id' => 'cursus-complet',
                'number' => 'Parcours 1',
                'short_title' => 'Cursus Complet DBA & DevOps (M01–M20)',
                'title' => 'Parcours 1 : Cursus Complet PostgreSQL 18 — Administration, Cloud, DevOps & Dév (Modules 01 à 20)',
                'badge' => '35h–42h (5 Jours) • 20 Modules + 20 Labs',
                'target_audience' => 'DBA, Ingénieurs Système/DevOps, Architectes & Développeurs Back-End',
                'prerequisites' => 'Bases Linux et notions générales de bases de données relationnelles',
                'objective' => 'Déployer, sécuriser, sauvegarder, répliquer, conteneuriser et interfacer PostgreSQL 18 en production critique.',
                'color' => '#00B0F0',
                'description' => 'Maîtrise intégrale du cycle de vie PostgreSQL 18 : architecture interne & AIO, installation PGDG, SQL avancé, indexation, tuning, pgBackRest, sécurité SCRAM/RLS, Patroni, PgBouncer, Docker, Swarm, Kubernetes CloudNativePG, connecteurs Python/PHP/Go, maîtrise psql, migration pgcopydb et durcissement noyau Linux.',
                'module_filter' => ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20'],
                'phases' => [
                    [
                        'code' => 'JOUR 1',
                        'title' => 'Architecture Interne PG 18, Installation, SQL:2023 & Extensions',
                        'subtitle' => 'Comprendre le moteur (AIO, MVCC, mémoire partagée), initialiser un cluster et exploiter les types avancés.',
                        'modules' => ['01', '02', '03', '04'],
                    ],
                    [
                        'code' => 'JOUR 2',
                        'title' => 'Indexation, Tuning, Sauvegardes PITR (pgBackRest) & Sécurité (SCRAM / RLS)',
                        'subtitle' => 'Optimiser les accès, garantir le RPO/RTO industriel et verrouiller l\'authentification et l\'audit.',
                        'modules' => ['05', '06', '07', '08'],
                    ],
                    [
                        'code' => 'JOUR 3',
                        'title' => 'Haute Disponibilité (Patroni), Pooling (PgBouncer) & Conteneurs (Docker / Swarm)',
                        'subtitle' => 'Concevoir des topologies résilientes sans SPOF et orchestrer PostgreSQL en conteneurs.',
                        'modules' => ['09', '10', '11', '12'],
                    ],
                    [
                        'code' => 'JOUR 4',
                        'title' => 'Kubernetes CloudNativePG & Ingénierie Applicative (ORM, Python, PHP, Go)',
                        'subtitle' => 'Opérer PostgreSQL sur Kubernetes et développer des applications haute performance sans anti-pattern N+1.',
                        'modules' => ['13', '14', '15', '16', '17'],
                    ],
                    [
                        'code' => 'JOUR 5',
                        'title' => 'Productivité CLI psql, Migration Zéro-Downtime (pgcopydb) & Tuning Noyau Linux',
                        'subtitle' => 'Industrialiser l\'exploitation DBA et durcir le système d\'exploitation Linux (HugePages, sysctl, NVMe).',
                        'modules' => ['18', '19', '20'],
                    ],
                ],
            ],
            'performance-tuning' => [
                'id' => 'performance-tuning',
                'number' => 'Parcours 2',
                'short_title' => 'Performance Tuning & CBO (M21–M25)',
                'title' => 'Parcours 2 : Spécialisation Performance Tuning & Internals — Protocole RALPH (Modules 21 à 25)',
                'badge' => '21h (3 Jours) • 5 Modules + 5 Labs',
                'target_audience' => 'DBA Seniors, Architectes Performance, Tech Leads & Experts SGBD',
                'prerequisites' => 'Maîtrise de l\'administration PostgreSQL ou validation des Modules 01 à 06',
                'objective' => 'Diagnostiquer scientifiquement et résoudre les goulets d\'étranglement CPU, I/O, mémoire et verrous MVCC.',
                'color' => '#F59E0B',
                'description' => 'Dissection du Cost-Based Optimizer (CBO), statistiques multivariées, introspection physique d\'index B-Tree/GIN/GiST/BRIN avec pageinspect, élimination des temp spills mémoire/I/O AIO et résolution chirurgicale des contentions MVCC et de l\'autovacuum.',
                'module_filter' => ['21','22','23','24','25'],
                'phases' => [
                    [
                        'code' => 'ÉTAPE 1',
                        'title' => 'Modèle Mathématique du CBO & Statistiques Multivariées',
                        'subtitle' => 'Décrypter EXPLAIN (ANALYZE, BUFFERS, WAL) et corriger les erreurs d\'estimation de cardinalité.',
                        'modules' => ['21', '22'],
                    ],
                    [
                        'code' => 'ÉTAPE 2',
                        'title' => 'Internals d\'Indexation (pageinspect) & Tuning Mémoire / I/O Asynchrone PG 18',
                        'subtitle' => 'Inspecter les pages 8 Ko et dimensionner shared_buffers, work_mem et les workers AIO.',
                        'modules' => ['23', '24'],
                    ],
                    [
                        'code' => 'ÉTAPE 3',
                        'title' => 'Concurrence MVCC, Arbres de Verrous, Bloat & Autovacuum Haute Fréquence',
                        'subtitle' => 'Éradiquer le table/index bloat, le gel XID wraparound et les blocages transactionnels.',
                        'modules' => ['25'],
                    ],
                ],
            ],
            'ingenierie-specialisee' => [
                'id' => 'ingenierie-specialisee',
                'number' => 'Parcours 3',
                'short_title' => 'Architecture & HA Avancée (M26–M29)',
                'title' => 'Parcours 3 : Architecture & Ingénierie Spécialisée — Volumétrie, Observabilité, FDW & HA (Modules 26 à 29)',
                'badge' => '14h (2 Jours) • 4 Modules + 4 Labs',
                'target_audience' => 'Architectes Données, Ingénieurs SRE & DBA Production',
                'prerequisites' => 'Administration PostgreSQL (Modules 01 à 09)',
                'objective' => 'Gérer des tables multi-téraoctets, instrumenter l\'observabilité forensique et fédérer des architectures distribuées.',
                'color' => '#10B981',
                'description' => 'Partitionnement déclaratif haute volumétrie (Range/List/Hash & Partition Pruning), observabilité forensique pgBadger & Prometheus/Grafana, fédération de données distribuées postgres_fdw et haute disponibilité automatisée pg_auto_failover.',
                'module_filter' => ['26','27','28','29'],
                'phases' => [
                    [
                        'code' => 'VOLET 1',
                        'title' => 'Partitionnement Déclaratif Haute Volumétrie & Observabilité Forensique',
                        'subtitle' => 'Découper les tables géantes avec pg_partman et analyser la télémétrie via pgBadger et Prometheus.',
                        'modules' => ['26', '27'],
                    ],
                    [
                        'code' => 'VOLET 2',
                        'title' => 'Fédération Distribuée (postgres_fdw) & Basculement Automatique (pg_auto_failover)',
                        'subtitle' => 'Requêter des serveurs distants avec pushdown et automatiser le failover quorum sans etcd.',
                        'modules' => ['28', '29'],
                    ],
                ],
            ],
            'fondamentaux-sql' => [
                'id' => 'fondamentaux-sql',
                'number' => 'Parcours 4',
                'short_title' => 'Fondamentaux & SQL:2023 (SQL 01–05)',
                'title' => 'Parcours 4 : Fondamentaux & Pratique Approfondie du Langage SQL:2023 (Modules SQL 01 à 05)',
                'badge' => '14h (2 Jours) • 5 Modules + 5 Labs',
                'target_audience' => 'Développeurs, Data Analysts, BI & Nouveaux DBA',
                'prerequisites' => 'Aucun prérequis PostgreSQL (idéal pour démarrer ou consolider ses bases SQL)',
                'objective' => 'Écrire des requêtes SQL:2023 fiables, performantes et conformes ACID sur le schéma de référence Sakila.',
                'color' => '#38BDF8',
                'description' => 'Maîtrise progressive du langage SQL : projection et filtrage déclaratif, transactions DML ACID et clause RETURNING, jointures multi-tables et anti-joins, agrégations analytiques GROUPING SETS/CUBE/FILTER et modélisation DDL (IDENTITY, contraintes, vues matérialisées).',
                'module_filter' => ['sql_01','sql_02','sql_03','sql_04','sql_05'],
                'phases' => [
                    [
                        'code' => 'SOCLE 1',
                        'title' => 'Syntaxe Déclarative, Logique Trivaluée NULL & Transactions DML ACID',
                        'subtitle' => 'Interroger et modifier les données en toute sécurité avec RETURNING et gestion des conflits UPSERT.',
                        'modules' => ['sql_01', 'sql_02'],
                    ],
                    [
                        'code' => 'SOCLE 2',
                        'title' => 'Jointures Ensemblistes, Agrégations Analytiques & Modélisation DDL',
                        'subtitle' => 'Maîtriser LATERAL, NOT EXISTS, GROUPING SETS et l\'architecture de schémas normalisés.',
                        'modules' => ['sql_03', 'sql_04', 'sql_05'],
                    ],
                ],
            ],
        ],
    ],
];
