<?php
declare(strict_types=1);

namespace LightPath\LMS\Course;

/**
 * Catalogue pédagogique Enterprise LMS pour PostgreSQL 18 :
 * - Positionnement Initial Qualiopi (Indicateur 8 — 15 QCM sur 5 domaines DBA)
 * - Examen Final de Certification Sommatif (Indicateur 11 — 40 QCM pondérés, seuil 75%)
 * - Cartes Mémo SRS (42 Flashcards Spaced Repetition PostgreSQL 18)
 * - Glossaire Technique Interactif (52 entrées PostgreSQL 18)
 * - Générateur de Manifeste SCORM 1.2 (imsmanifest.xml)
 */
class LmsCatalog
{
    public const DOMAINS = [
        'architecture' => 'Architecture Interne, Mémoire & MVCC',
        'sql_dev' => 'Requêtage SQL Avancé, JSONB & PL/pgSQL',
        'security_admin' => 'Administration, Sécurité SCRAM & RLS',
        'ha_backup' => 'Sauvegardes PITR, pgBackRest & Haute Disponibilité',
        'perf_linux' => 'Optimisation CBO, Indexation & Kernel Linux (M20)',
    ];

    /**
     * Retourne les 15 questions d'évaluation diagnostique pré-formation (Qualiopi Indicateur 8).
     */
    public static function getDiagnosticQuestions(): array
    {
        return [
            [
                'id' => 'diag_01',
                'domain' => 'architecture',
                'question' => 'Quel processus d\'arrière-plan PostgreSQL 18 est responsable de l\'écriture périodique des pages modifiées (dirty buffers) sur disque lors des points de reprise ?',
                'options' => [
                    'A' => 'autovacuum launcher',
                    'B' => 'checkpointer',
                    'C' => 'walwriter',
                    'D' => 'archiver',
                ],
                'correct' => 'B',
                'explanation' => 'Le processus checkpointer vide tous les dirty buffers antérieurs au Redo Point sur disque afin de borner le temps de crash recovery.',
            ],
            [
                'id' => 'diag_02',
                'domain' => 'architecture',
                'question' => 'Dans le modèle MVCC de PostgreSQL, que se passe-t-il physiquement lors d\'un UPDATE sur une ligne existante ?',
                'options' => [
                    'A' => 'La ligne est écrasée sur place et l\'ancienne valeur est copiée dans un Undo Segment.',
                    'B' => 'Un nouveau tuple (nouvelle version) est inséré avec un nouveau xmin et l\'ancien tuple reçoit un xmax.',
                    'C' => 'La table entière est verrouillée en ACCESS EXCLUSIVE pendant la modification.',
                    'D' => 'Le tuple est déplacé dans pg_wal jusqu\'au prochain VACUUM FULL.',
                ],
                'correct' => 'B',
                'explanation' => 'PostgreSQL n\'utilise pas d\'Undo Log classique : un UPDATE crée une nouvelle version physique du tuple et marque l\'ancienne avec xmax.',
            ],
            [
                'id' => 'diag_03',
                'domain' => 'architecture',
                'question' => 'Quelle nouveauté majeure de PostgreSQL 18 améliore les lectures séquentielles et Bitmap Heap Scans sur stockage NVMe ?',
                'options' => [
                    'A' => 'Le sous-système Asynchronous I/O (AIO) et le prefetching par flux (io_method / io_combine_limit).',
                    'B' => 'La suppression complète du processus walwriter.',
                    'C' => 'Le remplacement des pages de 8 Ko par des pages fixes de 1 Mo.',
                    'D' => 'L\'exécution des requêtes SQL directement dans le navigateur client.',
                ],
                'correct' => 'A',
                'explanation' => 'PostgreSQL 18 introduit le sous-système AIO et les Read Streams permettant de précharger et combiner les lectures I/O asynchrones.',
            ],
            [
                'id' => 'diag_04',
                'domain' => 'sql_dev',
                'question' => 'Quelle est la différence fondamentale entre les types JSON et JSONB dans PostgreSQL ?',
                'options' => [
                    'A' => 'JSONB stocke les données sous forme binaire décomposée et supporte l\'indexation GIN.',
                    'B' => 'JSON est binaire tandis que JSONB conserve les espaces et les clés dupliquées.',
                    'C' => 'JSONB est limité à 255 octets par colonne.',
                    'D' => 'Il n\'y a aucune différence physique entre JSON et JSONB.',
                ],
                'correct' => 'A',
                'explanation' => 'JSONB décompose le document en représentation binaire indexable via GIN (@>, ?), contrairement à JSON qui stocke le texte brut.',
            ],
            [
                'id' => 'diag_05',
                'domain' => 'sql_dev',
                'question' => 'Quelle fonction de fenêtrage SQL attribue un rang continu sans trou en cas d\'ex-aequo (ex: 1, 2, 2, 3) ?',
                'options' => [
                    'A' => 'ROW_NUMBER()',
                    'B' => 'RANK()',
                    'C' => 'DENSE_RANK()',
                    'D' => 'NTILE(4)',
                ],
                'correct' => 'C',
                'explanation' => 'DENSE_RANK() ne saute aucun numéro après une égalité, contrairement à RANK() qui donnerait 1, 2, 2, 4.',
            ],
            [
                'id' => 'diag_06',
                'domain' => 'sql_dev',
                'question' => 'Dans une CTE récursive (WITH RECURSIVE), quel opérateur sépare le membre d\'ancrage du membre récursif ?',
                'options' => [
                    'A' => 'CROSS JOIN',
                    'B' => 'UNION ou UNION ALL',
                    'C' => 'EXCEPT ALL',
                    'D' => 'INTERSECT',
                ],
                'correct' => 'B',
                'explanation' => 'Une CTE récursive combine son terme initial (non récursif) et son terme itératif avec UNION ALL (ou UNION).',
            ],
            [
                'id' => 'diag_07',
                'domain' => 'security_admin',
                'question' => 'Quel mécanisme d\'authentification par mot de passe est recommandé et activé par défaut dans PostgreSQL 18 (pg_hba.conf) ?',
                'options' => [
                    'A' => 'md5',
                    'B' => 'trust',
                    'C' => 'scram-sha-256',
                    'D' => 'password (clair)',
                ],
                'correct' => 'C',
                'explanation' => 'scram-sha-256 protège contre le rejeu et le vol de condensats grâce à un salage par utilisateur et un échange challenge-response.',
            ],
            [
                'id' => 'diag_08',
                'domain' => 'security_admin',
                'question' => 'Quelle fonctionnalité PostgreSQL permet de filtrer dynamiquement les lignes visibles d\'une table selon l\'utilisateur connecté (isolation multi-locataire) ?',
                'options' => [
                    'A' => 'Row-Level Security (RLS) via ALTER TABLE ... ENABLE ROW LEVEL SECURITY et CREATE POLICY.',
                    'B' => 'Les triggers BEFORE TRUNCATE.',
                    'C' => 'Les tables UNLOGGED.',
                    'D' => 'Le paramètre shared_preload_libraries.',
                ],
                'correct' => 'A',
                'explanation' => 'Row-Level Security (RLS) applique automatiquement les prédicats de sécurité définis par CREATE POLICY sur chaque requête.',
            ],
            [
                'id' => 'diag_09',
                'domain' => 'security_admin',
                'question' => 'Depuis PostgreSQL 15+, quels droits le rôle PUBLIC possède-t-il par défaut sur le schéma public d\'une nouvelle base ?',
                'options' => [
                    'A' => 'USAGE uniquement (le droit CREATE a été révoqué par défaut pour prévenir les attaques CVE-2018-1058).',
                    'B' => 'USAGE et CREATE complets.',
                    'C' => 'SUPERUSER.',
                    'D' => 'Aucun droit de connexion ni de lecture.',
                ],
                'correct' => 'A',
                'explanation' => 'Le privilège CREATE sur le schéma public est révoqué pour PUBLIC par défaut afin d\'empêcher l\'empoisonnement du search_path.',
            ],
            [
                'id' => 'diag_10',
                'domain' => 'ha_backup',
                'question' => 'Quel outil génère une sauvegarde logique parallèle par table exportée dans un répertoire de fichiers compressés ?',
                'options' => [
                    'A' => 'pg_basebackup -Fp',
                    'B' => 'pg_dump -Fd -j 4',
                    'C' => 'pg_controldata',
                    'D' => 'pg_resetwal',
                ],
                'correct' => 'B',
                'explanation' => 'Le format directory (-Fd) de pg_dump est le seul à supporter l\'option de parallélisation multi-processus (-j).',
            ],
            [
                'id' => 'diag_11',
                'domain' => 'ha_backup',
                'question' => 'Dans une architecture Haute Disponibilité Patroni + etcd, quel est le rôle du cluster etcd ?',
                'options' => [
                    'A' => 'Stocker les fichiers de données des tables volumineuses (TOAST).',
                    'B' => 'Fournir un Distributed Configuration Store (DCS) à consensus Raft détenant le jeton de leader pour éviter le Split-Brain.',
                    'C' => 'Compresser les journaux WAL archivés sur S3.',
                    'D' => 'Compiler les fonctions PL/pgSQL en C.',
                ],
                'correct' => 'B',
                'explanation' => 'etcd maintient l\'état du cluster par quorum Raft : seul le nœud détenant le bail (leader key) dans etcd peut agir comme primaire.',
            ],
            [
                'id' => 'diag_12',
                'domain' => 'ha_backup',
                'question' => 'Quel mode de pooling PgBouncer offre le meilleur ratio de multiplexage tout en restant compatible avec les transactions ACID courtes ?',
                'options' => [
                    'A' => 'pool_mode = session',
                    'B' => 'pool_mode = transaction',
                    'C' => 'pool_mode = statement',
                    'D' => 'pool_mode = disabled',
                ],
                'correct' => 'B',
                'explanation' => 'En mode transaction, la connexion serveur PostgreSQL n\'est allouée au client que pendant la durée exacte d\'un bloc BEGIN...COMMIT.',
            ],
            [
                'id' => 'diag_13',
                'domain' => 'perf_linux',
                'question' => 'Quelle commande permet d\'obtenir à la fois le plan d\'exécution estimé par l\'optimiseur et les temps/volumes réels mesurés lors de l\'exécution ?',
                'options' => [
                    'A' => 'EXPLAIN (ANALYZE, BUFFERS)',
                    'B' => 'SHOW plan_cache_mode',
                    'C' => 'ANALYZE VERBOSE',
                    'D' => 'VACUUM FREEZE',
                ],
                'correct' => 'A',
                'explanation' => 'EXPLAIN (ANALYZE, BUFFERS) exécute réellement la requête et affiche les compteurs exacts de blocs lus/touchés (shared hit/read) et le temps par nœud.',
            ],
            [
                'id' => 'diag_14',
                'domain' => 'perf_linux',
                'question' => 'Quel type d\'index PostgreSQL est le plus adapté et le plus compact (quelques centaines de Ko) pour une table IoT de 500 millions de lignes insérées chronologiquement ?',
                'options' => [
                    'A' => 'BRIN (Block Range Index)',
                    'B' => 'Hash Index',
                    'C' => 'GIN (Generalized Inverted Index)',
                    'D' => 'SP-GiST',
                ],
                'correct' => 'A',
                'explanation' => 'BRIN stocke uniquement les bornes min/max par plage de pages physiques contiguës (pages_per_range), idéal pour les séries temporelles corrélées physiquement.',
            ],
            [
                'id' => 'diag_15',
                'domain' => 'perf_linux',
                'question' => 'Quel réglage noyau Linux est impératif sur un serveur PostgreSQL dédié pour empêcher l\'OOM Killer de tuer brutalement le processus postmaster ?',
                'options' => [
                    'A' => 'vm.overcommit_memory = 2 (et OOMScoreAdjust=-1000 dans l\'unité systemd)',
                    'B' => 'vm.swappiness = 100',
                    'C' => 'Activer Transparent Huge Pages (THP = always)',
                    'D' => 'Désactiver totalement les fichiers WAL',
                ],
                'correct' => 'A',
                'explanation' => 'vm.overcommit_memory = 2 interdit au noyau Linux d\'allouer plus de mémoire virtuelle que la RAM + Swap configurés, renvoyant une erreur propre au lieu d\'invoquer l\'OOM Killer.',
            ],
        ];
    }

    /**
     * Évalue les réponses au test de positionnement initial Qualiopi et recommande un parcours adapté.
     */
    public static function evaluateDiagnostic(array $answers): array
    {
        $questions = self::getDiagnosticQuestions();
        $total = count($questions);
        $score = 0;
        $byDomain = [];
        foreach (self::DOMAINS as $dKey => $dLabel) {
            $byDomain[$dKey] = ['label' => $dLabel, 'correct' => 0, 'total' => 0, 'percent' => 0];
        }

        $details = [];
        foreach ($questions as $q) {
            $qid = $q['id'];
            $dom = $q['domain'];
            $given = strtoupper(trim((string)($answers[$qid] ?? '')));
            $isOk = ($given === $q['correct']);
            $byDomain[$dom]['total']++;
            if ($isOk) {
                $score++;
                $byDomain[$dom]['correct']++;
            }
            $details[$qid] = [
                'given' => $given,
                'correct' => $q['correct'],
                'is_correct' => $isOk,
                'explanation' => $q['explanation'],
            ];
        }

        foreach ($byDomain as $dKey => &$dStat) {
            $dStat['percent'] = $dStat['total'] > 0 ? (int)round(($dStat['correct'] / $dStat['total']) * 100) : 0;
        }
        unset($dStat);

        $percent = $total > 0 ? (int)round(($score / $total) * 100) : 0;

        if ($percent < 45) {
            $level = 'Débutant / Initiation SQL & Fondamentaux';
            $recommendedTrack = 'fondamentaux-sql';
            $recommendedTitle = 'Parcours 4 : Fondamentaux & Pratique Approfondie du Langage SQL:2023 (Modules SQL 01 à 05)';
            $recommendationText = 'Nous vous recommandons de démarrer par le Parcours 4 : Fondamentaux & Pratique Approfondie du Langage SQL:2023 (Modules SQL 01 à 05) afin d\'acquérir une solide maîtrise déclarative, DML et DDL sur PostgreSQL 18.';
        } elseif ($percent < 75) {
            $level = 'Intermédiaire / DBA Exploitation & Production';
            $recommendedTrack = 'cursus-complet';
            $recommendedTitle = 'Parcours 1 : Cursus Complet PostgreSQL 18 — Administration, Cloud, DevOps & Dév (Modules 01 à 20)';
            $recommendationText = 'Vous disposez d\'un bon socle initial. Le Parcours 1 : Cursus Complet (Modules 01 à 20) vous permettra d\'approfondir l\'architecture interne, MVCC, la sécurité SCRAM/RLS, pgBackRest, Patroni et le durcissement noyau Linux.';
        } else {
            $level = 'Avancé / Architecte & Spécialiste Performance PostgreSQL 18';
            $recommendedTrack = 'performance-tuning';
            $recommendedTitle = 'Parcours 2 : Spécialisation Performance Tuning & Internals — Protocole RALPH (Modules 21 à 25)';
            $recommendationText = 'Excellent niveau technique ! Vous pouvez vous orienter directement vers le Parcours 2 : Spécialisation Performance Tuning & Internals — Protocole RALPH (Modules 21 à 25 : CBO, statistiques multivariées, internals d\'indexation et analyse des contentions) ou vers le Parcours 3 : Ingénierie Spécialisée.';
        }

        return [
            'score' => $score,
            'total' => $total,
            'percent' => $percent,
            'level' => $level,
            'recommended_track' => $recommendedTrack,
            'recommended_course_id' => $recommendedTrack,
            'recommended_course_slug' => $recommendedTrack,
            'recommended_title' => $recommendedTitle,
            'recommendation_text' => $recommendationText,
            'domains' => $byDomain,
            'details' => $details,
        ];
    }

    /**
     * Retourne les 40 questions pondérées de l'Examen Final de Certification PostgreSQL 18 (Qualiopi Indicateur 11).
     */
    public static function getFinalExamQuestions(): array
    {
        $base = [
            // Domaine 1 : Architecture Interne, Mémoire & MVCC (8 questions)
            ['architecture', 'Quelle est la taille standard par défaut d\'une page (bloc) de données dans PostgreSQL 18 ?', ['A' => '4 Ko', 'B' => '8 Ko', 'C' => '16 Ko', 'D' => '64 Ko'], 'B', 'Par défaut, BLCKSZ vaut 8 192 octets (8 Ko) dans PostgreSQL.'],
            ['architecture', 'Dans l\'en-tête d\'une page PostgreSQL (PageHeaderData), que contiennent les pointeurs Line Pointers (ItemIdData) ?', ['A' => 'L\'offset physique et la longueur de chaque tuple dans la page (permettant à HOT de ne pas modifier les index).', 'B' => 'Le mot de passe chiffré de l\'utilisateur.', 'C' => 'Le plan EXPLAIN mis en cache.', 'D' => 'L\'adresse IP du client connecté.'], 'A', 'Les index pointent vers le TID (numéro de bloc + numéro d\'item), et l\'ItemId pointe vers l\'offset réel du tuple dans la page.'],
            ['architecture', 'Quelle condition est indispensable pour qu\'un UPDATE bénéficie de l\'optimisation HOT (Heap-Only Tuple) ?', ['A' => 'Aucune colonne indexée n\'est modifiée et il reste de l\'espace libre dans la même page de 8 Ko.', 'B' => 'La table doit être partitionnée par hachage.', 'C' => 'autovacuum doit être désactivé.', 'D' => 'La transaction doit être en niveau SERIALIZABLE.'], 'A', 'HOT évite toute maintenance d\'index si aucune colonne indexée ne change et que le nouveau tuple tient dans la même page (aidé par un fillfactor < 100).'],
            ['architecture', 'Quel fichier de la structure PGDATA garantit qu\'un tuple dont tous les index couvrants sont lus via Index-Only Scan est visible de toutes les transactions ?', ['A' => 'La Visibility Map (suffixe _vm)', 'B' => 'La Free Space Map (suffixe _fsm)', 'C' => 'pg_hba.conf', 'D' => 'postmaster.pid'], 'A', 'La Visibility Map (_vm) contient deux bits par page : all-visible (évitant les Heap Fetches lors d\'un Index-Only Scan) et all-frozen.'],
            ['architecture', 'Quel risque critique survient si autovacuum ne parvient pas à geler (FREEZE) les anciens identifiants de transaction avant 2 milliards de transactions ?', ['A' => 'Transaction ID Wraparound : l\'instance refuse toute écriture pour éviter la corruption logique des données.', 'B' => 'La suppression automatique de la base postgres.', 'C' => 'Le passage automatique en mode synchrone.', 'D' => 'La désactivation de TLS.'], 'A', 'Pour protéger l\'intégrité MVCC face au bouclage du compteur 32-bits XID, PostgreSQL passe en mode arrêt d\'urgence avant le wraparound.'],
            ['architecture', 'Quelle vue système introduite dans PostgreSQL 16 et enrichie en PostgreSQL 18 centralise les métriques d\'entrées/sorties par backend_type, object et context ?', ['A' => 'pg_stat_io', 'B' => 'pg_locks', 'C' => 'pg_depend', 'D' => 'pg_Trigger'], 'A', 'pg_stat_io fournit une observabilité granulaire des lectures, écritures, extensions, hits et évictions de buffers par type de processus et contexte.'],
            ['architecture', 'Quel paramètre mémoire PostgreSQL est alloué PAR opération de tri ou de table de hachage (et peut donc être multiplié plusieurs fois par requête et par session) ?', ['A' => 'work_mem', 'B' => 'shared_buffers', 'C' => 'wal_buffers', 'D' => 'effective_cache_size'], 'A', 'Contrairement à shared_buffers (global), work_mem est alloué par nœud Sort ou Hash Join actif dans chaque backend.'],
            ['architecture', 'Que mesure le paramètre effective_cache_size dans postgresql.conf ?', ['A' => 'Une indication purement statistique donnée au CBO sur la mémoire totale disponible (shared_buffers + cache OS) sans allouer un seul octet de RAM.', 'B' => 'La RAM réservée au processus walwriter.', 'C' => 'La taille maximale d\'un fichier journal WAL.', 'D' => 'La mémoire allouée aux procédures stockées Python.'], 'A', 'effective_cache_size n\'alloue aucune mémoire : il guide l\'optimiseur (CBO) sur la probabilité de trouver les pages d\'index en mémoire.'],

            // Domaine 2 : Requêtage SQL Avancé, JSONB & PL/pgSQL (8 questions)
            ['sql_dev', 'Quelle clause SQL permet de retourner immédiatement les colonnes d\'une ligne qui vient d\'être insérée, modifiée ou supprimée sans exécuter de SELECT supplémentaire ?', ['A' => 'RETURNING (avec support de OLD/NEW en PostgreSQL 18)', 'B' => 'YIELDING', 'C' => 'OUTPUTTING', 'D' => 'FETCHING'], 'A', 'RETURNING renvoie les valeurs affectées par INSERT/UPDATE/DELETE, et PostgreSQL 18 permet même d\'accéder explicitement aux valeurs OLD et NEW.'],
            ['sql_dev', 'Quel opérateur JSONB vérifie si le document JSONB de gauche contient la sous-structure JSONB de droite (et exploite un index GIN) ?', ['A' => '@>', 'B' => '->>', 'C' => '#>', 'D' => '||'], 'A', 'L\'opérateur de contenance @> (ex: metadata @> \'{"tier": "VIP"}\') est directement accéléré par les index GIN jsonb_ops et jsonb_path_ops.'],
            ['sql_dev', 'Quelle est la différence entre une fonction (CREATE FUNCTION) et une procédure stockée (CREATE PROCEDURE) en PL/pgSQL ?', ['A' => 'Une procédure (invoquée par CALL) peut gérer les transactions (COMMIT / ROLLBACK internes), contrairement à une fonction.', 'B' => 'Une fonction ne peut pas exécuter de SELECT.', 'C' => 'Une procédure ne peut pas accepter de paramètres.', 'D' => 'Une fonction ne peut être écrite qu\'en langage C.'], 'A', 'Seules les procédures appelées via CALL peuvent valider (COMMIT) ou annuler (ROLLBACK) des transactions au fil de leur exécution.'],
            ['sql_dev', 'Dans une fonction SQL/PLpgSQL, quel marqueur de volatilité permet à l\'optimiseur d\'évaluer la fonction une seule fois à la compilation et de l\'utiliser dans un index fonctionnel ?', ['A' => 'IMMUTABLE', 'B' => 'VOLATILE', 'C' => 'STABLE', 'D' => 'LEAKPROOF'], 'A', 'Une fonction IMMUTABLE garantit un résultat strictement identique pour les mêmes arguments sans jamais lire la base ni l\'horloge système.'],
            ['sql_dev', 'Que permet la jointure LATERAL (CROSS JOIN LATERAL / LEFT JOIN LATERAL) dans PostgreSQL ?', ['A' => 'Permettre à une sous-requête dans le FROM de référencer les colonnes des tables placées à sa gauche (ex: Top-N par catégorie).', 'B' => 'Exécuter une requête sur deux serveurs sans FDW.', 'C' => 'Forcer un tri décroissant sans index.', 'D' => 'Supprimer les doublons d\'une table physique.'], 'A', 'LATERAL évalue la sous-requête pour chaque ligne issue de la table de gauche, rendant les requêtes Top-N par groupe ultra-performantes avec un index.'],
            ['sql_dev', 'Quelle fonction native introduite récemment et optimisée dans PostgreSQL 18 génère des identifiants UUID triables chronologiquement (idéals pour les clés primaires B-Tree) ?', ['A' => 'uuidv7()', 'B' => 'md5(random()::text)', 'C' => 'txid_current()', 'D' => 'pg_backend_pid()'], 'A', 'uuidv7() préfixe l\'UUID par un horodatage sur 48 bits, éliminant la fragmentation aléatoire des feuilles d\'index B-Tree causée par uuidv4().'],
            ['sql_dev', 'Dans une clause de fenêtrage, que signifie la frame par défaut `RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW` lorsqu\'un `ORDER BY` est présent ?', ['A' => 'La fenêtre agrège toutes les lignes depuis le début de la partition jusqu\'à la valeur courante incluse (avec ses ex-aequo).', 'B' => 'La fenêtre ne lit que la dernière ligne de la table.', 'C' => 'La fenêtre ignore la clause PARTITION BY.', 'D' => 'La fenêtre supprime les lignes nulles.'], 'A', 'Avec ORDER BY dans OVER(...), la frame par défaut est un cumul glissant depuis le début de la partition jusqu\'à la valeur de tri courante.'],
            ['sql_dev', 'Quelle commande SQL standardisée permet de réaliser en une seule instruction atomique des INSERT, UPDATE et DELETE conditionnels entre une table source et une table cible ?', ['A' => 'MERGE INTO ... USING ... WHEN MATCHED / WHEN NOT MATCHED', 'B' => 'UPSERT TABLE', 'C' => 'SYNC TABLE', 'D' => 'REPLACE INTO'], 'A', 'MERGE INTO (enrichi dans PG 17/18 avec WHEN NOT MATCHED BY SOURCE et RETURNING) orchestre les synchronisations ETL atomiques.'],

            // Domaine 3 : Administration, Sécurité SCRAM & RLS (8 questions)
            ['security_admin', 'Dans quel ordre PostgreSQL évalue-t-il les règles du fichier pg_hba.conf lors d\'une tentative de connexion ?', ['A' => 'Séquentiellement de haut en bas : la première règle qui correspond (type, base, user, IP) est appliquée et l\'évaluation s\'arrête.', 'B' => 'Par ordre alphabétique des noms de bases.', 'C' => 'De la règle la plus permissive à la plus restrictive.', 'D' => 'Aléatoirement.'], 'A', 'pg_hba.conf fonctionne en First-Match : dès qu\'un enregistrement correspond au tuple (connexion, base, rôle, IP), sa méthode d\'authentification fait foi.'],
            ['security_admin', 'Quelle commande SQL recharge la configuration de postgresql.conf et pg_hba.conf à chaud sans interrompre les connexions actives ?', ['A' => 'SELECT pg_reload_conf();', 'B' => 'SELECT pg_terminate_backend(pid);', 'C' => 'TRUNCATE pg_settings;', 'D' => 'VACUUM FULL;'], 'A', 'pg_reload_conf() envoie un signal SIGHUP au postmaster pour appliquer immédiatement tous les paramètres de contexte sighup et recharger pg_hba.conf.'],
            ['security_admin', 'Dans la vue pg_settings, que signifie la valeur `context = postmaster` pour un paramètre comme `shared_buffers` ou `max_connections` ?', ['A' => 'Toute modification de ce paramètre nécessite un redémarrage complet de l\'instance PostgreSQL pour entrer en vigueur.', 'B' => 'Le paramètre peut être modifié par n\'importe quel utilisateur avec SET.', 'C' => 'Le paramètre est obsolète.', 'D' => 'Le paramètre ne s\'applique qu\'aux réplicas.'], 'A', 'Les paramètres de contexte postmaster dimensionnent la mémoire partagée ou les structures fixes allouées au démarrage du serveur.'],
            ['security_admin', 'Lorsqu\'une politique Row-Level Security (RLS) comporte à la fois une clause USING (...) et une clause WITH CHECK (...), quel est le rôle respectif de chacune ?', ['A' => 'USING filtre les lignes existantes visibles (SELECT/UPDATE/DELETE) tandis que WITH CHECK valide les nouvelles lignes écrites (INSERT/UPDATE).', 'B' => 'USING ne sert qu\'aux index et WITH CHECK ne sert qu\'aux sauvegardes.', 'C' => 'Les deux clauses sont purement décoratives.', 'D' => 'WITH CHECK désactive le chiffrement SSL.'], 'A', 'USING contrôle la visibilité en lecture/ciblage, et WITH CHECK empêche l\'insertion ou la modification de lignes hors du périmètre autorisé.'],
            ['security_admin', 'Pourquoi un propriétaire de table (table owner) contourne-t-il par défaut les politiques RLS de sa propre table, et comment forcer l\'application du RLS même pour lui ?', ['A' => 'Par défaut le propriétaire est exempté ; il faut exécuter `ALTER TABLE ... FORCE ROW LEVEL SECURITY` (hors superuser/BYPASSRLS).', 'B' => 'Il faut supprimer la clé primaire de la table.', 'C' => 'Il faut passer la table en UNLOGGED.', 'D' => 'C\'est impossible dans PostgreSQL.'], 'A', 'ALTER TABLE ... FORCE ROW LEVEL SECURITY oblige même le propriétaire de la table à respecter les politiques RLS définies.'],
            ['security_admin', 'Quelle extension standard de l\'écosystème PostgreSQL permet de journaliser de manière granulaire les accès DDL/DML par rôle ou par objet pour la conformité réglementaire (PCI-DSS, RGPD, HDS) ?', ['A' => 'pgaudit', 'B' => 'pg_trgm', 'C' => 'cube', 'D' => 'fuzzystrmatch'], 'A', 'L\'extension pgaudit génère des journaux d\'audit structurés par session ou par rôle/objet bien plus précis que log_statement = all.'],
            ['security_admin', 'Lorsqu\'une fonction PL/pgSQL est déclarée avec `SECURITY DEFINER`, quelle précaution de sécurité critique est obligatoire pour éviter l\'escalade de privilèges ?', ['A' => 'Fixer explicitement le search_path sur la fonction : `SET search_path = pg_catalog, public` (ou schéma dédié).', 'B' => 'Augmenter work_mem à 1 Go.', 'C' => 'Désactiver autovacuum.', 'D' => 'Utiliser uniquement des tables temporaires.'], 'A', 'Fixer search_path avec pg_catalog en tête empêche un attaquant de détourner un opérateur ou une table via un objet malveillant placé dans son schéma courant.'],
            ['security_admin', 'Quel rôle prédéfini PostgreSQL permet à un agent de supervision (ex: Prometheus postgres_exporter) de lire toutes les vues statistiques sans être superuser ?', ['A' => 'pg_monitor', 'B' => 'pg_write_server_files', 'C' => 'pg_execute_server_program', 'D' => 'pg_create_subscription'], 'A', 'Le rôle prédéfini pg_monitor regroupe pg_read_all_settings, pg_read_all_stats et pg_stat_scan_tables selon le principe du moindre privilège.'],

            // Domaine 4 : Sauvegardes PITR, pgBackRest & Haute Disponibilité (8 questions)
            ['ha_backup', 'Quels sont les deux éléments indispensables pour réaliser une restauration ponctuelle PITR (Point-In-Time Recovery) à une seconde précise ?', ['A' => 'Une sauvegarde physique de base (base backup) antérieure à la cible + la séquence continue des journaux WAL depuis cette sauvegarde jusqu\'à l\'instant cible.', 'B' => 'Uniquement un fichier SQL issu de pg_dump.', 'C' => 'Uniquement le fichier postgresql.conf.', 'D' => 'La vue pg_stat_activity.'], 'A', 'Le PITR restaure d\'abord le base backup physique puis rejoue (REDO) les segments WAL archivés jusqu\'au recovery_target_time ou LSN souhaité.'],
            ['ha_backup', 'Dans PostgreSQL 12 à 18, quel fichier vide créé dans PGDATA indique au serveur de démarrer en mode restauration (Recovery / PITR) ?', ['A' => 'recovery.signal', 'B' => 'standby.signal', 'C' => 'recovery.conf', 'D' => 'backup_label.old'], 'A', 'recovery.signal déclenche une restauration ponctuelle (suivie d\'une promotion), tandis que standby.signal maintient l\'instance en réplica continu.'],
            ['ha_backup', 'Quel mécanisme PostgreSQL garantit que le serveur primaire ne recycle jamais un segment WAL dont un réplica a encore besoin, mais présente un risque de saturation disque si le réplica tombe définitivement ?', ['A' => 'Les slots de réplication (Replication Slots — surveillés via pg_replication_slots et bornés par max_slot_wal_keep_size).', 'B' => 'Les tablespaces.', 'C' => 'Les séquences SQL.', 'D' => 'Les index partiels.'], 'A', 'Un slot de réplication inactif retient indéfiniment les fichiers dans pg_wal sauf si max_slot_wal_keep_size est configuré comme disjoncteur.'],
            ['ha_backup', 'Quelle valeur de `synchronous_commit` garantit que lors d\'un COMMIT, les WAL ont été reçus, écrits sur disque ET rejoués (visibles immédiatement en SELECT) sur le réplica synchrone ?', ['A' => 'remote_apply', 'B' => 'remote_write', 'C' => 'local', 'D' => 'off'], 'A', 'remote_apply est le niveau le plus fort : le COMMIT attend que le réplica ait appliqué les changements en mémoire, éliminant tout décalage de lecture (read-your-writes).'],
            ['ha_backup', 'Quel utilitaire permet de resynchroniser rapidement un ancien serveur primaire déchu avec le nouveau primaire promu sans reconstruire l\'intégralité du volume de données ?', ['A' => 'pg_rewind', 'B' => 'pg_upgrade', 'C' => 'pg_dumpall', 'D' => 'initdb'], 'A', 'pg_rewind analyse les journaux WAL depuis le point de divergence de timeline et ne recopie que les blocs de 8 Ko modifiés (nécessite wal_log_hints = on ou data checksums).'],
            ['ha_backup', 'Quelle est une différence majeure entre la Réplication Physique (Streaming) et la Réplication Logique (Publication / Subscription) ?', ['A' => 'La réplication logique permet de répliquer un sous-ensemble de tables et de franchir des versions majeures PostgreSQL différentes.', 'B' => 'La réplication logique copie les octets bruts des fichiers d\'index.', 'C' => 'La réplication physique permet d\'écrire sur le réplica.', 'D' => 'La réplication logique ne nécessite pas de wal_level = logical.'], 'A', 'La réplication logique décode les changements ligne par ligne par table (permettant les mises à jour majeures zéro-downtime), tandis que la réplication physique clone tout le cluster bloc à bloc.'],
            ['ha_backup', 'Pourquoi certaines fonctionnalités comme `PREPARE / EXECUTE` (avant protocole spécifique) ou `SET search_path` de session posent-elles problème avec `PgBouncer` en mode `transaction` ?', ['A' => 'Parce que la connexion backend PostgreSQL change d\'une transaction à l\'autre : l\'état de session ne persiste pas (bien que PgBouncer récent supporte max_prepared_statements).', 'B' => 'Parce que PgBouncer interdit les requêtes SELECT.', 'C' => 'Parce que PgBouncer modifie le schéma des tables.', 'D' => 'Parce que PgBouncer ne fonctionne qu\'en UDP.'], 'A', 'En transaction pooling, les variables de session et états liés au backend ne sont pas attachés exclusivement à un client unique entre deux transactions.'],
            ['ha_backup', 'Quel avantage clé offre `pgBackRest` par rapport à un simple script `pg_basebackup` pour une base de production de plusieurs téraoctets ?', ['A' => 'Sauvegardes différentielles/incrémentales parallélisées, restauration delta par empreinte SHA, vérification d\'intégrité et archivage asynchrone des WAL.', 'B' => 'Il remplace l\'optimiseur de requêtes SQL.', 'C' => 'Il permet d\'exécuter PostgreSQL sans disque dur.', 'D' => 'Il supprime le besoin de sauvegarder les WAL.'], 'A', 'pgBackRest industrialise les sauvegardes physiques (Full/Diff/Incr), le chiffrement AES-256, la compression Zstandard et la restauration Delta ultra-rapide.'],

            // Domaine 5 : Optimisation CBO, Indexation & Kernel Linux (8 questions)
            ['perf_linux', 'Lorsque le Cost-Based Optimizer (CBO) sous-estime fortement le nombre de lignes retournées par `WHERE ville = \'Paris\' AND code_postal LIKE \'75%\'` à cause de la corrélation entre ces deux colonnes, quelle commande résout ce problème ?', ['A' => 'CREATE STATISTICS ... (dependencies, ndistinct, mcv) ON ville, code_postal FROM table; puis ANALYZE;', 'B' => 'REINDEX SYSTEM;', 'C' => 'SET enable_seqscan = on;', 'D' => 'CLUSTER table;'], 'A', 'Les statistiques étendues multivariées (CREATE STATISTICS) capturent les dépendances fonctionnelles et les fréquences conjointes (MCV) entre colonnes corrélées.'],
            ['perf_linux', 'Sur un stockage SSD ou NVMe moderne, quelle valeur recommander pour `random_page_cost` (dont la valeur historique par défaut est 4.0) ?', ['A' => '1.1 (proche de seq_page_cost = 1.0)', 'B' => '40.0', 'C' => '0.0', 'D' => '100.0'], 'A', 'Sur SSD/NVMe, le coût d\'un accès aléatoire est quasi identique à un accès séquentiel : abaisser random_page_cost à 1.1 encourage légitimement les Index Scans.'],
            ['perf_linux', 'Dans la sortie d\'un `EXPLAIN (ANALYZE, BUFFERS)`, quel indicateur révèle qu\'un tri (`Sort`) a débordé sur le disque temporaire faute d\'un `work_mem` suffisant ?', ['A' => 'Sort Method: external merge  Disk: ...kB (accompagné de temp read/written dans Buffers)', 'B' => 'Sort Method: quicksort  Memory: 25kB', 'C' => 'Buffers: shared hit=12', 'D' => 'Index Cond: (id = 1)'], 'A', 'La mention "external merge Disk: ...kB" prouve que work_mem a été dépassé et que PostgreSQL a dû écrire des fichiers temporaires sur disque.'],
            ['perf_linux', 'Quel est l\'intérêt majeur de la clause `INCLUDE (col1, col2)` lors de la création d\'un index B-Tree (`CREATE INDEX ... ON tbl(key) INCLUDE (col1, col2)`) ?', ['A' => 'Ajouter des colonnes utiles dans les feuilles de l\'index pour permettre un Index-Only Scan sans alourdir les nœuds internes de navigation ni la contrainte d\'unicité.', 'B' => 'Chiffrer les colonnes col1 et col2.', 'C' => 'Créer un index Full-Text GIN.', 'D' => 'Supprimer automatiquement les lignes nulles.'], 'A', 'Les colonnes INCLUDE (covering index) ne sont stockées que dans les pages feuilles : elles permettent de satisfaire la requête en Index-Only Scan sans toucher au Heap.'],
            ['perf_linux', 'En production 24/7, pourquoi faut-il toujours privilégier `CREATE INDEX CONCURRENTLY` plutôt que `CREATE INDEX` standard sur une table active ?', ['A' => 'CREATE INDEX standard pose un verrou SHARE qui bloque toutes les écritures (INSERT/UPDATE/DELETE) pendant toute la construction, alors que CONCURRENTLY les autorise.', 'B' => 'CREATE INDEX CONCURRENTLY est 10 fois plus rapide en CPU.', 'C' => 'CREATE INDEX standard supprime les données existantes.', 'D' => 'CREATE INDEX CONCURRENTLY fonctionne à l\'intérieur d\'un bloc BEGIN...COMMIT.'], 'A', 'CREATE INDEX CONCURRENTLY effectue deux passes avec un verrou SHARE UPDATE EXCLUSIVE non bloquant pour le trafic DML de production.'],
            ['perf_linux', 'Dans le cadre du Partitionnement Déclaratif PostgreSQL 18, qu\'est-ce que le Partition Pruning ?', ['A' => 'L\'élimination automatique par le planificateur (ou à l\'exécution) des partitions enfants qui ne peuvent pas contenir les lignes recherchées.', 'B' => 'La suppression physique des tables de plus de 5 ans.', 'C' => 'La compression des colonnes texte avec LZ4.', 'D' => 'La fusion de deux serveurs PostgreSQL.'], 'A', 'Le Partition Pruning évite de scanner les partitions hors périmètre (statiquement au plan ou dynamiquement à l\'exécution).'],
            ['perf_linux', 'Pourquoi faut-il impérativement désactiver les Transparent Huge Pages (`echo never > /sys/kernel/mm/transparent_hugepage/enabled`) sur un serveur Linux hébergeant PostgreSQL 18 ?', ['A' => 'Pour éviter la latence CPU de défragmentation synchrone du noyau (khugepaged) et l\'amplification mémoire lors des fork() de backends.', 'B' => 'Parce que PostgreSQL ne supporte pas les processeurs 64 bits.', 'C' => 'Pour activer le swap sur réseau.', 'D' => 'Pour limiter la taille de la base à 2 Go.'], 'A', 'THP provoque des gels de latence (stalls de défragmentation mémoire) ; PostgreSQL doit utiliser des Huge Pages statiques explicites (`huge_pages = on`, `vm.nr_hugepages`).'],
            ['perf_linux', 'Pourquoi recommande-t-on de réduire fortement `vm.dirty_background_ratio` (ou `vm.dirty_background_bytes`, ex: 64-128 Mo) et `vm.dirty_bytes` sur un serveur Linux doté de 256 Go de RAM ?', ['A' => 'Pour éviter que le noyau Linux n\'accumule des dizaines de gigaoctets de pages sales avant de bloquer brutalement toutes les I/O disque lors d\'un flush massif.', 'B' => 'Pour empêcher PostgreSQL d\'écrire des fichiers journaux.', 'C' => 'Pour augmenter la fréquence du processeur.', 'D' => 'Pour désactiver le cache de requêtes DNS.'], 'A', 'Avec les ratios par défaut (10%/20%) sur 256 Go de RAM, Linux attendrait 25 Go de dirty pages avant de flusher, créant des tempêtes d\'I/O lors des checkpoints PostgreSQL.'],
        ];

        $questions = [];
        foreach ($base as $idx => $item) {
            $num = $idx + 1;
            $questions[] = [
                'id' => sprintf('exam_%02d', $num),
                'number' => $num,
                'domain' => $item[0],
                'domain_label' => self::DOMAINS[$item[0]] ?? $item[0],
                'weight' => 2.5,
                'question' => $item[1],
                'options' => $item[2],
                'correct' => $item[3],
                'explanation' => $item[4],
            ];
        }
        return $questions;
    }

    /**
     * Évalue une tentative à l'Examen Final de Certification Sommatif (Qualiopi Indicateur 11).
     */
    public static function evaluateFinalExam(array $answers, int $durationSeconds = 0): array
    {
        $questions = self::getFinalExamQuestions();
        $totalQuestions = count($questions);
        $correctCount = 0;
        $byDomain = [];
        foreach (self::DOMAINS as $dKey => $dLabel) {
            $byDomain[$dKey] = ['label' => $dLabel, 'correct' => 0, 'total' => 0, 'percent' => 0];
        }

        $details = [];
        foreach ($questions as $q) {
            $qid = $q['id'];
            $dom = $q['domain'];
            $given = strtoupper(trim((string)($answers[$qid] ?? '')));
            $isOk = ($given === $q['correct']);
            $byDomain[$dom]['total']++;
            if ($isOk) {
                $correctCount++;
                $byDomain[$dom]['correct']++;
            }
            $details[$qid] = [
                'given' => $given,
                'correct' => $q['correct'],
                'is_correct' => $isOk,
                'explanation' => $q['explanation'],
            ];
        }

        foreach ($byDomain as $dKey => &$dStat) {
            $dStat['percent'] = $dStat['total'] > 0 ? (int)round(($dStat['correct'] / $dStat['total']) * 100) : 0;
        }
        unset($dStat);

        $percent = $totalQuestions > 0 ? (int)round(($correctCount / $totalQuestions) * 100) : 0;
        $passed = $percent >= 75;

        if ($percent >= 90) {
            $mention = 'Mention Très Bien — Expert Certifié PostgreSQL 18';
        } elseif ($percent >= 80) {
            $mention = 'Mention Bien — DBA Certifié PostgreSQL 18';
        } elseif ($percent >= 75) {
            $mention = 'Admis — Compétences Validées PostgreSQL 18';
        } else {
            $mention = 'Non validé (Seuil requis : 75% — ' . $correctCount . '/' . $totalQuestions . ')';
        }

        return [
            'score' => $correctCount,
            'total' => $totalQuestions,
            'percent' => $percent,
            'passed' => $passed,
            'mention' => $mention,
            'duration_seconds' => max(0, $durationSeconds),
            'domains' => $byDomain,
            'details' => $details,
        ];
    }

    /**
     * Retourne les 42 cartes mémo SRS (Spaced Repetition System) PostgreSQL 18.
     */
    public static function getFlashcards(): array
    {
        return [
            ['id' => 'fc_01', 'category' => 'Architecture & MVCC', 'front' => 'Quels sont les 4 champs d\'en-tête MVCC clés d\'un tuple PostgreSQL ?', 'back' => '`t_xmin` (XID d\'insertion), `t_xmax` (XID de suppression/mise à jour ou 0), `t_cid` (Command ID interne), et `t_ctid` (TID courant ou nouvelle version).'],
            ['id' => 'fc_02', 'category' => 'Architecture & MVCC', 'front' => 'Quelle est la condition exacte pour un HOT Update (Heap-Only Tuple) ?', 'back' => '1. Aucune colonne présente dans un index n\'est modifiée.\n2. Il reste suffisamment d\'espace libre dans la même page de 8 Ko (favorisé par `fillfactor = 80` ou `90`).'],
            ['id' => 'fc_03', 'category' => 'Architecture & MVCC', 'front' => 'Que stockent les fichiers `_fsm` et `_vm` associés à chaque table ?', 'back' => '`_fsm` (Free Space Map) : octets disponibles par page.\n`_vm` (Visibility Map) : 2 bits par page (`all_visible` pour Index-Only Scan et `all_frozen` pour sauter les pages lors du VACUUM).'],
            ['id' => 'fc_04', 'category' => 'Architecture & MVCC', 'front' => 'Qu\'est-ce que le sous-système AIO dans PostgreSQL 18 ?', 'back' => 'Un moteur d\'entrées/sorties asynchrones (`io_method`, `io_combine_limit`) couplé aux Read Streams qui précharge plusieurs blocs contigus ou non-contigus sans bloquer le CPU.'],
            ['id' => 'fc_05', 'category' => 'Architecture & MVCC', 'front' => 'Quelle est la règle de dimensionnement initiale de `shared_buffers` ?', 'back' => 'Typiquement **25% de la RAM totale** du serveur (ex: 16 Go sur un serveur de 64 Go), couplé à `huge_pages = on`.'],
            ['id' => 'fc_06', 'category' => 'Architecture & MVCC', 'front' => 'Pourquoi `work_mem` est-il dangereux s\'il est fixé trop haut globalement ?', 'back' => 'Car il est alloué **par nœud de tri ou de hachage** dans chaque requête parallèle et chaque connexion simultanée (`max_connections * opérations * work_mem`).'],
            ['id' => 'fc_07', 'category' => 'Architecture & MVCC', 'front' => 'Quel est le rôle de `full_page_writes = on` ?', 'back' => 'Lors de la première modification d\'une page 8 Ko après un Checkpoint, PostgreSQL écrit l\'image complète (FPI) de la page dans le WAL pour se protéger contre les écritures partielles (Torn Pages) de l\'OS.'],
            ['id' => 'fc_08', 'category' => 'Architecture & MVCC', 'front' => 'Quelle commande libère immédiatement l\'espace disque à l\'OS sans poser de verrou ACCESS EXCLUSIVE prolongé ?', 'back' => '`pg_repack` (extension externe reconstruisant la table en ligne via trigger de journalisation) contrairement à `VACUUM FULL` qui verrouille la table exclusivement.'],
            ['id' => 'fc_09', 'category' => 'SQL & Dev Avancé', 'front' => 'Pourquoi préférer `uuidv7()` à `gen_random_uuid()` (v4) pour une clé primaire en PostgreSQL 18 ?', 'back' => '`uuidv7()` commence par un timestamp 48-bits monotone : les insertions se font toujours en fin d\'arbre B-Tree (droite), évitant les Page Splits aléatoires et le cache thrashing.'],
            ['id' => 'fc_10', 'category' => 'SQL & Dev Avancé', 'front' => 'Quelle est la différence entre `->` et `->>` en JSONB ?', 'back' => '`->` retourne l\'élément sous forme de type `jsonb` (permettant le chaînage), tandis que `->>` extrait la valeur finale sous forme de `text`.'],
            ['id' => 'fc_11', 'category' => 'SQL & Dev Avancé', 'front' => 'Quelle classe d\'opérateurs GIN JSONB est la plus compacte et rapide pour `@>` ?', 'back' => '`jsonb_path_ops` : elle stocke uniquement des condensats (hashes) des chemins menant aux valeurs, réduisant la taille de l\'index GIN de 50% par rapport à `jsonb_ops`.'],
            ['id' => 'fc_12', 'category' => 'SQL & Dev Avancé', 'front' => 'Comment accéder aux anciennes et nouvelles valeurs dans `RETURNING` sous PostgreSQL 18 ?', 'back' => 'Grâce aux alias virtuels `OLD` et `NEW` dans la clause `RETURNING` (ex: `UPDATE film SET rental_rate = 3.99 RETURNING OLD.rental_rate AS avant, NEW.rental_rate AS apres;`).'],
            ['id' => 'fc_13', 'category' => 'SQL & Dev Avancé', 'front' => 'Que fait la clause `FILTER (WHERE condition)` sur une fonction d\'agrégation ?', 'back' => 'Elle filtre les lignes envoyées à cet agrégat spécifique (ex: `COUNT(*) FILTER (WHERE status = \'PAID\')`), remplaçant avantageusement `SUM(CASE WHEN ...)` et permettant le pivot SQL propre.'],
            ['id' => 'fc_14', 'category' => 'SQL & Dev Avancé', 'front' => 'Quand une CTE (`WITH q AS (...)`) est-elle matérialisée par défaut depuis PostgreSQL 12+ ?', 'back' => 'Si elle est référencée **plus d\'une fois** ou contient des effets de bord. Si elle n\'est appelée qu\'une fois, le CBO l\'intègre (inlining) sauf si `WITH q AS MATERIALIZED (...)` est spécifié.'],
            ['id' => 'fc_15', 'category' => 'SQL & Dev Avancé', 'front' => 'Quelle est la différence entre `IMMUTABLE`, `STABLE` et `VOLATILE` ?', 'back' => '- `IMMUTABLE` : pur calcul déterministe (indexable).\n- `STABLE` : résultat constant au sein d\'un même scan SQL (ex: `now()`, lecture table).\n- `VOLATILE` : peut changer à chaque appel ou modifier des données (`random()`, `INSERT`).'],
            ['id' => 'fc_16', 'category' => 'SQL & Dev Avancé', 'front' => 'Comment éviter l\'erreur de blocage lors du rafraîchissement d\'une Vue Matérialisée en production ?', 'back' => 'Créer un index `UNIQUE` sur la vue matérialisée, puis exécuter `REFRESH MATERIALIZED VIEW CONCURRENTLY nom_vue;`.'],
            ['id' => 'fc_17', 'category' => 'Sécurité & Admin', 'front' => 'Comment vérifier si des mots de passe obsolètes MD5 subsistent encore dans le cluster ?', 'back' => '`SELECT rolname FROM pg_authid WHERE rolpassword LIKE \'md5%\';` (tous les comptes doivent commencer par `SCRAM-SHA-256$`).'],
            ['id' => 'fc_18', 'category' => 'Sécurité & Admin', 'front' => 'Comment passer un paramètre session côté application pour filtrer une politique RLS multi-tenant ?', 'back' => '`SET LOCAL app.current_tenant_id = \'42\';` dans la transaction, lu par la policy via `current_setting(\'app.current_tenant_id\', true)::int`.'],
            ['id' => 'fc_19', 'category' => 'Sécurité & Admin', 'front' => 'Quelle est la différence entre `SET` et `SET LOCAL` ?', 'back' => '`SET LOCAL` ne s\'applique qu\'à la transaction en cours et est automatiquement réinitialisé au `COMMIT` ou `ROLLBACK` (indispensable avec PgBouncer en mode transaction).'],
            ['id' => 'fc_20', 'category' => 'Sécurité & Admin', 'front' => 'Comment empêcher les transactions fantômes `idle in transaction` de bloquer le VACUUM ?', 'back' => 'Configurer `idle_in_transaction_session_timeout = \'5min\'` (et `transaction_timeout` introduit en PG 17/18).'],
            ['id' => 'fc_21', 'category' => 'Sécurité & Admin', 'front' => 'Quelle requête identifie immédiatement quel PID bloque quel autre PID sur un verrou ?', 'back' => 'Utiliser la fonction native `pg_blocking_pids(pid)` jointe à `pg_stat_activity` (`WHERE cardinality(pg_blocking_pids(pid)) > 0`).'],
            ['id' => 'fc_22', 'category' => 'Sécurité & Admin', 'front' => 'Quelle est la différence entre `pg_cancel_backend(pid)` et `pg_terminate_backend(pid)` ?', 'back' => '`pg_cancel_backend` (SIGINT) annule uniquement la requête en cours en gardant la session ouverte ; `pg_terminate_backend` (SIGTERM) ferme la connexion complète.'],
            ['id' => 'fc_23', 'category' => 'Sécurité & Admin', 'front' => 'Comment modifier un paramètre dans `postgresql.auto.conf` via SQL ?', 'back' => '`ALTER SYSTEM SET parametre = valeur;` suivi de `SELECT pg_reload_conf();` (ou redémarrage si `context = postmaster`).'],
            ['id' => 'fc_24', 'category' => 'Sécurité & Admin', 'front' => 'Pourquoi activer `log_lock_waits = on` en production ?', 'back' => 'Il journalise automatiquement toute attente de verrou dépassant `deadlock_timeout` (1s par défaut), permettant de diagnostiquer les contentions avant tout deadlock.'],
            ['id' => 'fc_25', 'category' => 'HA, Backup & PITR', 'front' => 'Quel est l\'ordre exact des états d\'attente de `synchronous_commit` du plus rapide au plus sûr ?', 'back' => '`off` < `local` < `remote_write` (OS réplica) < `on` (disque WAL réplica) < `remote_apply` (rejoué et visible en lecture sur le réplica).'],
            ['id' => 'fc_26', 'category' => 'HA, Backup & PITR', 'front' => 'Que signifie `hot_standby_feedback = on` sur un réplica en lecture ?', 'back' => 'Le réplica informe le primaire de son plus vieux XID actif afin que le VACUUM du primaire ne supprime pas des lignes encore lues par une longue requête sur le réplica (évite l\'annulation de requête, mais peut causer du bloat sur le primaire).'],
            ['id' => 'fc_27', 'category' => 'HA, Backup & PITR', 'front' => 'Quel prérequis est obligatoire pour utiliser `pg_rewind` après un basculement (failover) ?', 'back' => 'Avoir activé les sommes de contrôle de pages (`data_checksums = on` à l\'initdb) ou `wal_log_hints = on` dans `postgresql.conf`.'],
            ['id' => 'fc_28', 'category' => 'HA, Backup & PITR', 'front' => 'Comment créer un point de restauration nommé avant une migration applicative critique ?', 'back' => '`SELECT pg_create_restore_point(\'avant_migration_v2\');` (utilisable ensuite avec `recovery_target_name = \'avant_migration_v2\'`).'],
            ['id' => 'fc_29', 'category' => 'HA, Backup & PITR', 'front' => 'Quelle option `pg_upgrade` permet de migrer une instance de 5 To en quelques secondes sur le même système de fichiers ?', 'back' => '`pg_upgrade --link` (ou `--clone` sur XFS/Btrfs/ZFS supportant le Copy-on-Write / reflink) qui crée des liens physiques au lieu de copier les fichiers de données.'],
            ['id' => 'fc_30', 'category' => 'HA, Backup & PITR', 'front' => 'Dans Patroni, que fait le Watchdog matériel Linux (`/dev/watchdog`) ?', 'back' => 'Si le processus Patroni se fige ou ne peut plus renouveler son bail etcd à temps, le noyau Linux déclenche un reset matériel immédiat du serveur primaire pour garantir qu\'aucun Split-Brain ne survienne.'],
            ['id' => 'fc_31', 'category' => 'HA, Backup & PITR', 'front' => 'Comment protéger l\'espace disque `pg_wal` contre un slot de réplication abandonné ?', 'back' => 'Définir `max_slot_wal_keep_size` (ex: `20GB`) : si un slot dépasse ce retard, il est invalidé plutôt que de provoquer un arrêt complet du primaire par disque plein (`PANIC: could not write to file pg_wal/...`).'],
            ['id' => 'fc_32', 'category' => 'HA, Backup & PITR', 'front' => 'Qu\'est-ce que l\'Identité de Réplication (`REPLICA IDENTITY`) en réplication logique ?', 'back' => 'Elle définit quelles colonnes sont écrites dans le WAL pour identifier l\'ancienne ligne lors d\'un `UPDATE` ou `DELETE` (`DEFAULT` = clé primaire, `USING INDEX`, ou `FULL` = toute la ligne).'],
            ['id' => 'fc_33', 'category' => 'Performance & Kernel Linux', 'front' => 'Dans `EXPLAIN (ANALYZE, BUFFERS)`, que signifient `shared hit` et `shared read` ?', 'back' => '- `shared hit` : nombre de blocs de 8 Ko trouvés directement dans `shared_buffers`.\n- `shared read` : blocs demandés à l\'OS (page cache Linux ou lecture disque physique).'],
            ['id' => 'fc_34', 'category' => 'Performance & Kernel Linux', 'front' => 'Comment mesurer le temps réellement passé en attente d\'entrées/sorties disque dans `EXPLAIN` ?', 'back' => 'Activer `track_io_timing = on` dans `postgresql.conf`, puis lancer `EXPLAIN (ANALYZE, BUFFERS)` qui affichera `I/O Timings: shared read=... ms`.'],
            ['id' => 'fc_35', 'category' => 'Performance & Kernel Linux', 'front' => 'Quels sont les 3 algorithmes de jointure de PostgreSQL et leurs cas d\'usage ?', 'back' => '1. **Nested Loop** : petit nombre de lignes externes avec index sur la table interne.\n2. **Hash Join** : grands volumes non triés avec égalité (`=`) tenant dans `work_mem`.\n3. **Merge Join** : grands volumes déjà triés (ou via index B-Tree) sur la clé de jointure.'],
            ['id' => 'fc_36', 'category' => 'Performance & Kernel Linux', 'front' => 'Qu\'est-ce qu\'un Index Partiel (`CREATE INDEX ... WHERE ...`) ?', 'back' => 'Un index qui n\'inclut que les lignes satisfaisant un prédicat constant (ex: `WHERE status = \'PENDING\'`), réduisant jusqu\'à 95% la taille de l\'index et le coût de maintenance DML.'],
            ['id' => 'fc_37', 'category' => 'Performance & Kernel Linux', 'front' => 'Pourquoi accélérer `autovacuum` sur une table ultra-sollicitée plutôt que globalement ?', 'back' => 'Via les paramètres de stockage par table : `ALTER TABLE orders SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_vacuum_cost_limit = 2000);`.'],
            ['id' => 'fc_38', 'category' => 'Performance & Kernel Linux', 'front' => 'Quelle formule calcule le nombre de Huge Pages statiques Linux (2 Mo) à réserver pour PostgreSQL ?', 'back' => 'Lire le `VmPeak` du processus postmaster dans `/proc/<pid>/status`, diviser par `2048 Ko` et ajouter ~5% de marge dans `vm.nr_hugepages`.'],
            ['id' => 'fc_39', 'category' => 'Performance & Kernel Linux', 'front' => 'Quels réglages `sysctl` mémoire Linux constituent le standard de durcissement Module 20 ?', 'back' => '`vm.overcommit_memory = 2`, `vm.overcommit_ratio = 80`, `vm.swappiness = 1`, `vm.dirty_background_bytes = 67108864` (64 Mo), `vm.dirty_bytes = 536870912` (512 Mo).'],
            ['id' => 'fc_40', 'category' => 'Performance & Kernel Linux', 'front' => 'Quelle option de montage XFS/ext4 élimine une écriture de métadonnées inutile à chaque lecture de fichier ?', 'back' => '`noatime` dans `/etc/fstab` (désactive la mise à jour de l\'inode `atime` lors des lectures de segments de 1 Go).'],
            ['id' => 'fc_41', 'category' => 'Performance & Kernel Linux', 'front' => 'Quel ordonnanceur (I/O Scheduler) Linux choisir pour un disque NVMe dédié à PostgreSQL ?', 'back' => '`none` (ou `mq-deadline` sur SSD SATA/SAS) car les contrôleurs NVMe gèrent nativement des milliers de files matérielles parallèles sans verrouillage logiciel.'],
            ['id' => 'fc_42', 'category' => 'Performance & Kernel Linux', 'front' => 'Quelles directives Systemd sont indispensables dans le drop-in `postgresql.service` en production ?', 'back' => '`OOMScoreAdjust=-1000` (protection du postmaster), `LimitNOFILE=65536` (descripteurs de fichiers) et `LimitMEMLOCK=infinity` (verrouillage des Huge Pages).'],
            ['id' => 'fc_43', 'category' => 'HA, Backup & PITR', 'front' => 'Dans CloudNativePG (Module 14), pourquoi ne utilise-t-on jamais de `StatefulSet` Kubernetes classique ?', 'back' => 'L\'opérateur CloudNativePG pilote directement chaque `Pod` et son `PersistentVolumeClaim` dédié (sans `StatefulSet`), ce qui permet de promouvoir instantanément n\'importe quel réplica (`pod-2` ou `pod-3`) sans être contraint par l\'ordre séquentiel `pod-0`.'],
            ['id' => 'fc_44', 'category' => 'HA, Backup & PITR', 'front' => 'Comment `pgcopydb` (Module 19) réalise-t-il une migration Near-Zero Downtime ?', 'back' => 'Il combine un `COPY` parallèle multi-processus de la base source (`pgcopydb clone --follow`) avec le décodage logique en flux continu (`wal2json` / `test_decoding`) pour rejouer le delta DML avant la bascule (`sentinel set endpos`).'],
            ['id' => 'fc_45', 'category' => 'Architecture & Stockage', 'front' => 'Comment détacher une ancienne partition mensuelle en production sans bloquer les lectures sur la table parente (Module 26) ?', 'back' => 'Utiliser `ALTER TABLE logs DETACH PARTITION logs_2025_01 CONCURRENTLY;` qui n\'acquiert qu\'un verrou léger `SHARE UPDATE EXCLUSIVE` au lieu de `ACCESS EXCLUSIVE`.'],
            ['id' => 'fc_46', 'category' => 'Sécurité & Admin', 'front' => 'Quels paramètres `postgresql.conf` sont requis pour qu\'un rapport `pgBadger` (Module 27) soit exploitable ?', 'back' => '`log_min_duration_statement = 0` (ou seuil en ms), `log_checkpoints = on`, `log_lock_waits = on`, `log_temp_files = 0` et un `log_line_prefix` incluant au minimum `%t [%p]: user=%u,db=%d,app=%a,client=%h `.'],
            ['id' => 'fc_47', 'category' => 'SQL & Développement', 'front' => 'Quelles options de serveur/table `postgres_fdw` (Module 28) optimisent le Pushdown vers un serveur distant ?', 'back' => '`use_remote_estimate \'true\'` (utilise le CBO distant), `fetch_size \'10000\'` (évite les allers-retours réseau par lots de 100 lignes) et ` extensions \'...\'` pour déléguer les fonctions immuables au serveur distant.'],
            ['id' => 'fc_48', 'category' => 'HA, Backup & PITR', 'front' => 'Dans `pg_auto_failover` (Module 29), quels sont les rôles respectifs du Monitor et du Keeper (`pg_autoctl`) ?', 'back' => 'Le **Monitor** orchestre la machine à états finis (FSM) et arbitre le quorum de santé, tandis que le **Keeper** (`pg_autoctl run`) supervise localement le démon `postgres` et applique les transitions d\'état (`single`, `primary`, `secondary`, `wait_primary`) avec `RPO = 0`.'],
        ];
    }

    /**
     * Retourne les 52 entrées du Glossaire Technique PostgreSQL 18.
     */
    public static function getGlossary(): array
    {
        return [
            ['term' => 'ACID', 'category' => 'Fondamentaux', 'definition' => 'Atomicité, Cohérence, Isolation, Durabilité : les 4 garanties transactionnelles assurées par le WAL et le moteur MVCC de PostgreSQL.'],
            ['term' => 'AIO (Asynchronous I/O)', 'category' => 'PostgreSQL 18', 'definition' => 'Nouveau sous-système PostgreSQL 18 permettant d\'émettre des requêtes de lecture disque asynchrones et combinées (io_method, io_combine_limit) sans bloquer le processus backend.'],
            ['term' => 'Autovacuum', 'category' => 'Maintenance', 'definition' => 'Ensemble de processus d\'arrière-plan chargés de recycler les tuples morts (VACUUM), de geler les anciens XID (FREEZE) et de rafraîchir les statistiques du CBO (ANALYZE).'],
            ['term' => 'B-Tree', 'category' => 'Indexation', 'definition' => 'Arbre équilibré (Lehman-Yao) utilisé par défaut dans PostgreSQL pour les recherches d\'égalité, d\'intervalles (<, <=, >, >=, BETWEEN) et les tris (ORDER BY).'],
            ['term' => 'Bitmap Heap Scan', 'category' => 'Optimiseur CBO', 'definition' => 'Méthode d\'accès en deux étapes : construit d\'abord une carte binaire des blocs/lignes via un ou plusieurs index (BitmapOr/BitmapAnd), puis lit les pages du Heap dans l\'ordre physique du disque.'],
            ['term' => 'Bloat (Gonflement)', 'category' => 'Maintenance', 'definition' => 'Espace disque et mémoire gaspillé par l\'accumulation de versions mortes de tuples (dead tuples) qui n\'ont pas pu être recyclées à temps par VACUUM.'],
            ['term' => 'BRIN (Block Range Index)', 'category' => 'Indexation', 'definition' => 'Index ultra-léger stockant uniquement les valeurs minimale et maximale par plage de pages physiques contiguës (idéal pour les tables de logs et séries temporelles).'],
            ['term' => 'CBO (Cost-Based Optimizer)', 'category' => 'Optimiseur CBO', 'definition' => 'Planificateur de requêtes de PostgreSQL qui évalue le coût en I/O et CPU de multiples plans d\'exécution à partir des statistiques de pg_statistic avant de choisir le moins coûteux.'],
            ['term' => 'Checkpoint', 'category' => 'Architecture', 'definition' => 'Opération périodique synchronisant tous les buffers modifiés (dirty buffers) de shared_buffers vers les fichiers de données sur disque afin de borner la durée de reprise après crash.'],
            ['term' => 'CLOG (pg_xact)', 'category' => 'Architecture', 'definition' => 'Commit Log stocké dans pg_xact qui enregistre l\'état final de chaque transaction (IN_PROGRESS, COMMITTED, ABORTED, SUB_COMMITTED) sur 2 bits par XID.'],
            ['term' => 'CONCURRENTLY', 'category' => 'Exploitation', 'definition' => 'Mot-clé DDL (CREATE/DROP INDEX, REFRESH MATERIALIZED VIEW, REINDEX) permettant d\'opérer en production sans bloquer les lectures ni les écritures concurrentes.'],
            ['term' => 'COPY', 'category' => 'Développement', 'definition' => 'Protocole et commande native PostgreSQL d\'ingestion et d\'export de données en masse contournant le surcoût d\'analyse des INSERT unitaires.'],
            ['term' => 'CTE (Common Table Expression)', 'category' => 'Développement', 'definition' => 'Sous-requête nommée définie par la clause WITH (supportant la récursivité via WITH RECURSIVE pour parcourir les graphes et hiérarchies).'],
            ['term' => 'Data Checksums', 'category' => 'Fiabilité', 'definition' => 'Empreinte CRC de 16 bits calculée sur chaque page de 8 Ko lors de son écriture sur disque pour détecter immédiatement toute corruption silencieuse du stockage (bit rot).'],
            ['term' => 'Dead Tuple', 'category' => 'MVCC', 'definition' => 'Ancienne version d\'une ligne supprimée ou modifiée par UPDATE dont le xmax est validé et qui n\'est plus visible par aucune transaction active.'],
            ['term' => 'Exclusion Constraint', 'category' => 'Modélisation', 'definition' => 'Contrainte déclarative (EXCLUDE USING gist) empêchant par exemple le chevauchement de deux plages temporelles (tstzrange avec l\'opérateur &&) pour une même ressource.'],
            ['term' => 'EXPLAIN (ANALYZE, BUFFERS)', 'category' => 'Performance', 'definition' => 'Outil de diagnostic fondamental du DBA qui exécute la requête et compare les estimations du CBO (rows, cost) à la réalité (actual time, rows, shared hit/read/temp).'],
            ['term' => 'FDW (Foreign Data Wrapper)', 'category' => 'Architecture', 'definition' => 'Standard SQL/MED (ex: postgres_fdw, file_fdw) permettant d\'interroger et de modifier des tables distantes comme s\'il s\'agissait de tables locales.'],
            ['term' => 'Fillfactor', 'category' => 'Stockage', 'definition' => 'Pourcentage de remplissage initial d\'une page (ex: 80% sur une table soumise à de fréquents UPDATE) réservant 20% d\'espace libre pour favoriser les mises à jour HOT.'],
            ['term' => 'FSM (Free Space Map)', 'category' => 'Stockage', 'definition' => 'Arbre binaire stocké dans le fichier _fsm de chaque relation permettant de localiser instantanément une page disposant d\'assez d\'espace libre pour un nouvel INSERT/UPDATE.'],
            ['term' => 'Full Page Image (FPI)', 'category' => 'WAL', 'definition' => 'Copie intégrale d\'un bloc de 8 Ko inscrite dans le journal WAL lors de sa première modification après un checkpoint afin de prévenir les Torn Pages en cas de coupure électrique.'],
            ['term' => 'GIN (Generalized Inverted Index)', 'category' => 'Indexation', 'definition' => 'Index inversé associant chaque clé élémentaire (mot Full-Text, clé/valeur JSONB, élément de tableau) à la liste (posting list/tree) des TIDs qui la contiennent.'],
            ['term' => 'GiST (Generalized Search Tree)', 'category' => 'Indexation', 'definition' => 'Infrastructure d\'arbre d\'indexation extensible utilisée pour les données géométriques PostGIS, les plages (rangetypes), les recherches de proximité KNN et l\'exclusion.'],
            ['term' => 'Heap', 'category' => 'Stockage', 'definition' => 'Ensemble des fichiers de données d\'une table (segments de 1 Go divisés en pages de 8 Ko) où les lignes sont stockées sans ordre physique imposé.'],
            ['term' => 'HOT (Heap-Only Tuple)', 'category' => 'MVCC', 'definition' => 'Optimisation cruciale où un UPDATE ne modifiant aucune colonne indexée chaîne l\'ancien et le nouveau tuple à l\'intérieur de la même page 8 Ko sans toucher aux index.'],
            ['term' => 'Huge Pages', 'category' => 'Kernel Linux', 'definition' => 'Pages mémoire physiques de 2 Mo (au lieu de 4 Ko) verrouillées en RAM pour shared_buffers, divisant par 512 la taille de la table des pages et les défauts de cache TLB du CPU.'],
            ['term' => 'Index-Only Scan', 'category' => 'Optimiseur CBO', 'definition' => 'Parcours d\'index couvrant qui répond à la requête uniquement en lisant l\'index et la Visibility Map (_vm), sans jamais accéder à la table (Heap Fetches = 0).'],
            ['term' => 'JSONB', 'category' => 'Développement', 'definition' => 'Format JSON binaire décomposé de PostgreSQL permettant un accès direct aux clés sans re-parsing et une indexation haute performance via GIN.'],
            ['term' => 'LSN (Log Sequence Number)', 'category' => 'WAL', 'definition' => 'Adresse 64 bits (format XX/YYYYYYYY) désignant un octet précis dans le flux continu du journal des transactions WAL ; sert à mesurer le retard de réplication et l\'état des pages (pd_lsn).'],
            ['term' => 'MVCC (Multi-Version Concurrency Control)', 'category' => 'MVCC', 'definition' => 'Mécanisme d\'isolation par instantanés (Snapshots) où les lecteurs ne bloquent jamais les rédacteurs et les rédacteurs ne bloquent jamais les lecteurs.'],
            ['term' => 'OOM Killer', 'category' => 'Kernel Linux', 'definition' => 'Mécanisme d\'urgence du noyau Linux qui tue un processus en cas d\'épuisement mémoire ; doit être neutralisé sur PostgreSQL via vm.overcommit_memory = 2 et OOMScoreAdjust=-1000.'],
            ['term' => 'Partitionnement Déclaratif', 'category' => 'Architecture', 'definition' => 'Découpage logique d\'une grande table parente en partitions physiques enfants par RANGE, LIST ou HASH avec routage automatique et Partition Pruning.'],
            ['term' => 'Patroni', 'category' => 'Haute Disponibilité', 'definition' => 'Orchestrateur Python de Haute Disponibilité PostgreSQL s\'appuyant sur un DCS à consensus (etcd, Consul, ZooKeeper) pour automatiser le failover sans Split-Brain.'],
            ['term' => 'pg_hba.conf', 'category' => 'Sécurité', 'definition' => 'Fichier Host-Based Authentication contrôlant quelles adresses IP, quels rôles et quelles bases sont autorisés à se connecter et avec quelle méthode (scram-sha-256, cert, peer).'],
            ['term' => 'pg_stat_io', 'category' => 'Observabilité', 'definition' => 'Vue système moderne détaillant toutes les opérations d\'I/O (reads, writes, extends, hits, evictions, fsyncs) ventilées par type de processus et contexte.'],
            ['term' => 'pg_stat_statements', 'category' => 'Performance', 'definition' => 'Extension officielle indispensable qui agrège les statistiques d\'exécution de toutes les requêtes SQL normalisées (calls, total_exec_time, mean_exec_time, shared_blks_hit/read).'],
            ['term' => 'pgBackRest', 'category' => 'Sauvegarde', 'definition' => 'Solution de référence pour les sauvegardes physiques industrielles PostgreSQL (Full, Diff, Incr, compression Zstd, chiffrement, multi-repo S3/local et PITR).'],
            ['term' => 'PgBouncer', 'category' => 'Architecture', 'definition' => 'Pooler de connexions léger à boucle d\'événements multiplexant des milliers de connexions applicatives sur quelques dizaines de processus backends PostgreSQL.'],
            ['term' => 'pgbench', 'category' => 'Performance', 'definition' => 'Outil officiel de test de charge et de benchmarking transactionnel (TPC-B ou scripts personnalisés) permettant de mesurer les TPS et la latence sous concurrence.'],
            ['term' => 'PITR (Point-In-Time Recovery)', 'category' => 'Sauvegarde', 'definition' => 'Capacité à restaurer un cluster PostgreSQL à un instant exact (seconde, XID ou point de restauration nommé) en rejouant les archives WAL sur une sauvegarde de base.'],
            ['term' => 'Postmaster', 'category' => 'Architecture', 'definition' => 'Processus maître superviseur de l\'instance PostgreSQL qui écoute le port TCP (5432), gère la mémoire partagée et lance les processus d\'arrière-plan et backends.'],
            ['term' => 'Replication Slot', 'category' => 'Haute Disponibilité', 'definition' => 'Verrou persistant sur le primaire garantissant que les segments WAL nécessaires à un réplica physique ou logique ne sont pas supprimés avant d\'avoir été consommés.'],
            ['term' => 'RLS (Row-Level Security)', 'category' => 'Sécurité', 'definition' => 'Sécurité au niveau des lignes filtrant automatiquement les tuples accessibles ou modifiables selon des politiques déclaratives (CREATE POLICY) par rôle ou contexte applicatif.'],
            ['term' => 'SCRAM-SHA-256', 'category' => 'Sécurité', 'definition' => 'Salted Challenge Response Authentication Mechanism : protocole standardisé RFC 7677 protégeant l\'authentification PostgreSQL contre l\'écoute réseau et l\'usurpation serveur.'],
            ['term' => 'Shared Buffers', 'category' => 'Mémoire', 'definition' => 'Cache de pages de données de 8 Ko partagé entre tous les processus de l\'instance PostgreSQL (géré par un algorithme Clock-Sweep).'],
            ['term' => 'Split-Brain', 'category' => 'Haute Disponibilité', 'definition' => 'Scénario catastrophique où deux nœuds d\'un cluster acceptent simultanément des écritures indépendamment ; prévenu par le quorum DCS (etcd) et le Watchdog matériel.'],
            ['term' => 'TID (Tuple Identifier)', 'category' => 'Stockage', 'definition' => 'Adresse physique d\'une version de ligne sous la forme `(numéro_de_page, numéro_d_item)` visible via la colonne système cachée `ctid`.'],
            ['term' => 'TOAST (The Oversized-Attribute Storage Technique)', 'category' => 'Stockage', 'definition' => 'Mécanisme automatique qui compresse (LZ4/pglz) et/ou déporte dans une table secondaire les valeurs d\'attributs dépassant le seuil de ~2 Ko afin qu\'une ligne tienne dans une page de 8 Ko.'],
            ['term' => 'UUIDv7', 'category' => 'PostgreSQL 18', 'definition' => 'Format d\'UUID standardisé RFC 9562 généré nativement par `uuidv7()` dans PostgreSQL 18, combinant un préfixe temporel ordonné et de l\'entropie aléatoire.'],
            ['term' => 'Visibility Map (VM)', 'category' => 'Stockage', 'definition' => 'Carte binaire (_vm) indiquant quelles pages de 8 Ko ne contiennent que des tuples visibles de tous (`all-visible`) ou totalement gelés (`all-frozen`).'],
            ['term' => 'WAL (Write-Ahead Logging)', 'category' => 'Architecture', 'definition' => 'Journal des transactions écrit séquentiellement dans `pg_wal` avant toute modification des fichiers de données, garantissant la durabilité ACID et la réplication.'],
            ['term' => 'XID (Transaction ID)', 'category' => 'MVCC', 'definition' => 'Identifiant numérique de transaction attribué dès la première écriture ; utilisé par `xmin` et `xmax` pour déterminer la visibilité MVCC des lignes.'],
            ['term' => 'CloudNativePG (CNPG)', 'category' => 'Kubernetes & Cloud', 'definition' => 'Opérateur Kubernetes déclaratif CNCF (Module 14) gérant nativement le cycle de vie complet de clusters PostgreSQL 18 (Pods directs sans StatefulSet, failover automatisé, sauvegarde Barman Cloud / S3 et poolers PgBouncer).'],
            ['term' => 'pg_auto_failover', 'category' => 'Haute Disponibilité', 'definition' => 'Solution de Haute Disponibilité automatisée (Module 29) reposant sur un nœud Monitor centralisant une machine à états finis (FSM) et des agents Keeper (`pg_autoctl`) garantissant un basculement sans perte de données (`RPO = 0`).'],
            ['term' => 'pgBadger', 'category' => 'Observabilité', 'definition' => 'Analyseur haute performance de journaux PostgreSQL (Module 27) générant des rapports HTML autonomes détaillant les requêtes lentes, les attentes de verrous, les fichiers temporaires et l\'activité des checkpoints.'],
            ['term' => 'pgcopydb', 'category' => 'Migration', 'definition' => 'Outil industriel de migration PostgreSQL ultra-rapide (Module 19) parallélisant le chargement initial (`COPY`) et la capture de changements par décodage logique (`--follow`) pour des bascules Near-Zero Downtime.'],
        ];
    }

    /**
     * Génère un manifeste XML SCORM 1.2 valide (imsmanifest.xml) exposant l'intégralité du cursus PostgreSQL 18.
     */
    public static function generateScormManifest(array $course): string
    {
        $title = htmlspecialchars((string)($course['title'] ?? 'PostgreSQL 18 — Cursus Complet LightPath'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $itemsXml = [];
        $resourcesXml = [];

        // 1. Évaluation Diagnostique Initiale
        $itemsXml[] = '      <item identifier="ITEM_DIAGNOSTIC" identifierref="RES_DIAGNOSTIC"><title>00 — Positionnement Initial Qualiopi (Diagnostic DBA)</title></item>';
        $resourcesXml[] = '    <resource identifier="RES_DIAGNOSTIC" type="webcontent" adlcp:scormtype="sco" href="index.php?page=diagnostic"><file href="index.php"/></resource>';

        // 2. Modules de cours
        foreach ($course['modules'] ?? [] as $modId => $mod) {
            $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$modId);
            $modTitle = htmlspecialchars(($mod['badge_code'] ?? 'MOD') . ' — ' . ($mod['title'] ?? $modId), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $href = 'index.php?page=module&amp;id=' . urlencode((string)$modId);
            $itemsXml[] = '      <item identifier="ITEM_MOD_' . $safeId . '" identifierref="RES_MOD_' . $safeId . '"><title>' . $modTitle . '</title></item>';
            $resourcesXml[] = '    <resource identifier="RES_MOD_' . $safeId . '" type="webcontent" adlcp:scormtype="sco" href="' . $href . '"><file href="index.php"/></resource>';
        }

        // 3. Ateliers pratiques
        foreach ($course['labs'] ?? [] as $labId => $lab) {
            $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$labId);
            $labTitle = htmlspecialchars(($lab['badge_code'] ?? 'LAB') . ' — ' . ($lab['title'] ?? $labId), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $href = 'index.php?page=lab&amp;id=' . urlencode((string)$labId);
            $itemsXml[] = '      <item identifier="ITEM_LAB_' . $safeId . '" identifierref="RES_LAB_' . $safeId . '"><title>' . $labTitle . '</title></item>';
            $resourcesXml[] = '    <resource identifier="RES_LAB_' . $safeId . '" type="webcontent" adlcp:scormtype="sco" href="' . $href . '"><file href="index.php"/></resource>';
        }

        // 4. Examen Final Sommatif
        $itemsXml[] = '      <item identifier="ITEM_FINAL_EXAM" identifierref="RES_FINAL_EXAM"><title>99 — Examen Final de Certification PostgreSQL 18 (Qualiopi Indicateur 11)</title></item>';
        $resourcesXml[] = '    <resource identifier="RES_FINAL_EXAM" type="webcontent" adlcp:scormtype="sco" href="index.php?page=exam"><file href="index.php"/></resource>';

        $itemsStr = implode("\n", $itemsXml);
        $resourcesStr = implode("\n", $resourcesXml);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="LIGHTPATH_PG18_LMS_SCORM12" version="1.2"
          xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
          xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <organizations default="ORG_LIGHTPATH_PG18">
    <organization identifier="ORG_LIGHTPATH_PG18">
      <title>{$title}</title>
{$itemsStr}
    </organization>
  </organizations>
  <resources>
{$resourcesStr}
  </resources>
</manifest>
XML;
    }
}
