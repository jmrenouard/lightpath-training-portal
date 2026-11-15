<?php
declare(strict_types=1);

return [
    'app_name' => 'LightPath Academy',
    'app_subtitle' => 'Plateforme Interactive de Formation & Laboratoires Cloud/Docker',
    'company' => 'LightPath SARL',
    'trainer_name' => 'Jean-Marie Renouard',
    'trainer_title' => 'Expert Bases de Données & DataOps, Co-développeur de MySQLTuner (20+ ans d\'expérience)',
    'contact_email' => 'contact@lightpath.fr',
    'website' => 'https://www.lightpath.fr',
    'version' => '2.0.0',
    'qualiopi_ref' => 'LP-LMS-QUALIOPI-2026',

    // Storage backend: 'json' (zero-config atomic flat-file default) or 'pdo' (PostgreSQL / MySQL / SQLite)
    'storage_driver' => getenv('LMS_STORAGE_DRIVER') ?: 'json',
    'storage_path' => getenv('LMS_DATA_DIR') ?: (__DIR__ . '/../storage/data'),

    // Optional PDO configuration for production web hosting
    'pdo' => [
        'dsn' => getenv('LMS_PDO_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=lightpath_lms',
        'username' => getenv('LMS_PDO_USER') ?: 'postgres',
        'password' => getenv('LMS_PDO_PASS') ?: '',
    ],

    // Multi-user Docker lab configuration
    'docker' => [
        'enabled' => true,
        'wsl_distro' => getenv('LMS_WSL_DISTRO') ?: 'Ubuntu-24.04',
        'base_port' => 5500,
        'image' => 'docker-pg-primary:18',
        'fallback_image' => 'postgres:18',
        'default_db' => 'formation_pg',
        'default_user' => 'postgres',
        'default_password' => 'postgres',
        'compose_dir' => realpath(__DIR__ . '/../../training_postgresql/docker') ?: (__DIR__ . '/../../training_postgresql/docker'),
        'classroomctl_path' => realpath(__DIR__ . '/../../training_postgresql/scripts/classroomctl.py') ?: '',
    ],
];
