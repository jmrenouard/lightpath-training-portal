<?php
declare(strict_types=1);

/**
 * Modular feature toggle definitions for LightPath Training Portal.
 * Runtime overrides persisted in storage supersede these defaults.
 */
return [
    'self_registration' => [
        'label' => 'Auto-inscription apprenant',
        'description' => 'Autorise la création de compte en libre-service depuis la page de connexion.',
        'category' => 'Authentification & Accès',
        'default' => true,
        'enabled' => true,
    ],
    'demo_quick_login' => [
        'label' => 'Connexion rapide démo (1-clic)',
        'description' => 'Affiche les boutons de démonstration Admin, Formateur et Stagiaire sur la page de connexion.',
        'category' => 'Authentification & Accès',
        'default' => false,
        'enabled' => false,
    ],
    'docker_labs' => [
        'label' => 'Conteneurs Docker PostgreSQL 18',
        'description' => 'Active les instances Docker isolées par apprenant et la console SQL interactive.',
        'category' => 'Laboratoires & Pratique',
        'default' => true,
        'enabled' => true,
    ],
    'chaos_engineering' => [
        'label' => 'Chaos Engineering & Oracles',
        'description' => 'Active les scénarios de pannes simulées et la vérification automatisée par oracles SQL.',
        'category' => 'Laboratoires & Pratique',
        'default' => true,
        'enabled' => true,
        'requires' => ['docker_labs'],
    ],
    'gamification' => [
        'label' => 'Gamification (XP & Badges)',
        'description' => 'Active le calcul des points XP, des grades DBA et des badges de compétences.',
        'category' => 'Pédagogie & Engagement',
        'default' => true,
        'enabled' => true,
    ],
    'qna_forum' => [
        'label' => 'Forum Q&A par module',
        'description' => 'Active les fils de questions/réponses et la modération formateur dans les supports.',
        'category' => 'Pédagogie & Engagement',
        'default' => true,
        'enabled' => true,
    ],
    'personal_notes' => [
        'label' => 'Bloc-notes personnel apprenant',
        'description' => 'Active la prise de notes personnelle par module et atelier ainsi que l\'export Markdown.',
        'category' => 'Pédagogie & Engagement',
        'default' => true,
        'enabled' => true,
    ],
    'flashcards_srs' => [
        'label' => 'Révision Active (Flashcards SRS)',
        'description' => 'Active le deck de révision espacée et le suivi du niveau de confiance par carte.',
        'category' => 'Pédagogie & Engagement',
        'default' => true,
        'enabled' => true,
    ],
    'scorm_export' => [
        'label' => 'Export SCORM 1.2',
        'description' => 'Active la génération du manifeste imsmanifest.xml et du package ZIP SCORM 1.2.',
        'category' => 'Conformité & Exports',
        'default' => true,
        'enabled' => true,
    ],
    'cold_satisfaction_j60' => [
        'label' => 'Satisfaction à froid (J+60)',
        'description' => 'Active le questionnaire d\'évaluation post-formation à J+60 pour le suivi Qualiopi.',
        'category' => 'Conformité & Exports',
        'default' => true,
        'enabled' => true,
    ],
];
