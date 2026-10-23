<?php
declare(strict_types=1);

/**
 * Registre Multi-Formations (Course-Pack Engine)
 * Permet d'ajouter ou brancher n'importe quelle formation passée ou future
 * en pointant vers son dossier source ou un pack synchronisé dans storage/courses/<slug>.
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
                'title' => 'Parcours 1 : Cursus Complet PostgreSQL 18 (Modules 01 à 20)',
                'badge' => '35h–42h • Administration, Cloud, DevOps & Dév',
                'color' => '#00B0F0',
                'description' => 'Maîtrise intégrale du cycle de vie PostgreSQL 18 : architecture, installation PGDG, SQL avancé, indexation, tuning, pgBackRest, sécurité, Patroni, PgBouncer, Docker, Swarm, Kubernetes, CNPG, Python, PHP, Go, psql, pgcopydb et tuning noyau Linux.',
                'module_Filter' => ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20'],
            ],
            'performance-tuning' => [
                'id' => 'performance-tuning',
                'title' => 'Parcours 2 : Spécialisation Performance Tuning & Internals (Modules 21 à 25)',
                'badge' => '21h • Protocole RALPH & CBO',
                'color' => '#F59E0B',
                'description' => 'Dissection du Cost-Based Optimizer (CBO), statistiques multivariées, introspection d\'index B-Tree/GIN/GiST/BRIN avec pageinspect, gestion des temp spills mémoire/I/O AIO et résolution des contentions MVCC.',
                'module_Filter' => ['21','22','23','24','25'],
            ],
            'ingenierie-specialisee' => [
                'id' => 'ingenierie-specialisee',
                'title' => 'Parcours 3 : Architecture & Ingénierie Spécialisée (Modules 26 à 29)',
                'badge' => '14h • Partitionnement, Observabilité, FDW & HA',
                'color' => '#10B981',
                'description' => 'Partitionnement déclaratif haute volumétrie, observabilité forensique pgBadger & Prometheus, fédération de données postgres_fdw et haute disponibilité automatisée pg_auto_failover.',
                'module_Filter' => ['26','27','28','29'],
            ],
            'fondamentaux-sql' => [
                'id' => 'fondamentaux-sql',
                'title' => 'Parcours 4 : Fondamentaux & Pratique Approfondie SQL:2023 (SQL 01 à 05)',
                'badge' => '14h • Norme ANSI SQL:2023 sur Sakila',
                'color' => '#38BDF8',
                'description' => 'Maîtrise progressive du langage SQL : projection et filtrage déclaratif, transactions DML ACID, jointures multi-tables et anti-joins, agrégations analytiques GROUPING SETS/CUBE et modélisation DDL.',
                'module_Filter' => ['sql_01','sql_02','sql_03','sql_04','sql_05'],
            ],
        ],
    ],
];
