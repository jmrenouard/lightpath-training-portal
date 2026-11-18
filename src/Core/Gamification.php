<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Gamification engine computing learner XP, course rank, and competency badges.
 */
class Gamification
{
    /**
     * Computes learner XP, rank, and 8 competency badges using dynamic course counts and course slug.
     */
    public static function computeUserBadgesAndXp(
        array $progress,
        ?array $diagnostic = null,
        ?array $bestExam = null,
        array $attendance = [],
        int $totalModules = 34,
        int $totalLabs = 34,
        string $courseSlug = 'postgresql-18'
    ): array {
        $safeTotalModules = max(1, $totalModules);
        $safeTotalLabs = max(1, $totalLabs);
        $completedModules = $progress['completed_modules'] ?? [];
        $completedLabs = $progress['completed_labs'] ?? [];
        $quizScores = $progress['quiz_scores'] ?? [];
        $learningSeconds = (int)($progress['learning_seconds'] ?? 0);

        $xp = (count($completedModules) * 100)
            + (count($completedLabs) * 150)
            + (count($quizScores) * 50)
            + ($diagnostic !== null ? 150 : 0)
            + (($bestExam !== null && !empty($bestExam['passed'])) ? 500 : 0)
            + (count($attendance) * 25);

        $hasAnyPrefix = static function (array $items, array $prefixes): bool {
            foreach ($items as $item) {
                foreach ($prefixes as $p) {
                    if (str_contains((string)$item, $p)) {
                        return true;
                    }
                }
            }
            return false;
        };

        $countMatchingPrefixes = static function (array $items, array $prefixes): int {
            $c = 0;
            foreach ($items as $item) {
                foreach ($prefixes as $p) {
                    if (str_contains((string)$item, $p)) {
                        $c++;
                        break;
                    }
                }
            }
            return $c;
        };

        if ($courseSlug === 'postgresql-18') {
            if ($xp >= 6500) {
                $rank = 'Principal Architect PostgreSQL 18';
                $nextRankXp = 10000;
            } elseif ($xp >= 3500) {
                $rank = 'Senior Production DBA';
                $nextRankXp = 6500;
            } elseif ($xp >= 1500) {
                $rank = 'DBA Exploitation Confirmé';
                $nextRankXp = 3500;
            } elseif ($xp >= 400) {
                $rank = 'Ingénieur Base de Données Junior';
                $nextRankXp = 1500;
            } else {
                $rank = 'Apprenant DBA PostgreSQL 18';
                $nextRankXp = 400;
            }

            $badges = [
                [
                    'id' => 'first_step_pg18',
                    'icon' => '🎖️',
                    'title' => 'Premier Pas PG 18',
                    'description' => 'Valider au moins 1 module de cours, 1 atelier pratique ou le positionnement initial.',
                    'unlocked' => !empty($completedModules) || !empty($completedLabs) || $diagnostic !== null,
                ],
                [
                    'id' => 'mvcc_architect',
                    'icon' => '🏛️',
                    'title' => 'Architecte MVCC & Stockage',
                    'description' => 'Valider les modules/ateliers d\'Architecture Interne, MVCC et Maintenance (01, 03, 18).',
                    'unlocked' => $countMatchingPrefixes(array_merge($completedModules, $completedLabs), ['_01_', '_03_', '_18_']) >= 2,
                ],
                [
                    'id' => 'secops_guardian',
                    'icon' => '🛡️',
                    'title' => 'Gardien SecOps & SCRAM',
                    'description' => 'Valider les ateliers de Sécurité SCRAM-SHA-256, RLS et Audit (04, 24).',
                    'unlocked' => $hasAnyPrefix(array_merge($completedModules, $completedLabs), ['_04_', '_24_']),
                ],
                [
                    'id' => 'cbo_tuner',
                    'icon' => '⚡',
                    'title' => 'Maître du CBO & Tuning',
                    'description' => 'Valider les modules/labs d\'Indexation, EXPLAIN BUFFERS et Tuning (06, 11, 12, 13).',
                    'unlocked' => $countMatchingPrefixes(array_merge($completedModules, $completedLabs), ['_06_', '_11_', '_12_', '_13_']) >= 2,
                ],
                [
                    'id' => 'ha_pitr_master',
                    'icon' => '🔄',
                    'title' => 'Haute Disponibilité & PITR',
                    'description' => 'Valider les ateliers Sauvegardes PITR, pgBackRest, Patroni et PgBouncer (08, 09, 10, 19).',
                    'unlocked' => $countMatchingPrefixes(array_merge($completedModules, $completedLabs), ['_08_', '_09_', '_10_', '_19_']) >= 2,
                ],
                [
                    'id' => 'linux_kernel_expert',
                    'icon' => '🐧',
                    'title' => 'Expert Kernel Linux (Module 20)',
                    'description' => 'Valider le Module 20 ou l\'Atelier 20 sur le durcissement noyau Linux (HugePages, sysctl, I/O).',
                    'unlocked' => $hasAnyPrefix(array_merge($completedModules, $completedLabs), ['_20_']),
                ],
                [
                    'id' => 'sakila_grandmaster',
                    'icon' => '🏆',
                    'title' => $safeTotalLabs === 34
                        ? 'Grand Maître Sakila (34/34 Labs)'
                        : sprintf('Grand Maître Pratique (%d/%d Labs)', $safeTotalLabs, $safeTotalLabs),
                    'description' => sprintf('Valider l\'intégralité des %d ateliers pratiques sur conteneurs Docker PostgreSQL 18.', $safeTotalLabs),
                    'unlocked' => count($completedLabs) >= $safeTotalLabs,
                ],
                [
                    'id' => 'pg18_certified',
                    'icon' => '🎓',
                    'title' => 'Certifié Sommatif PostgreSQL 18',
                    'description' => 'Réussir l\'Examen Final de Certification Sommatif avec un score ≥ 75%.',
                    'unlocked' => $bestExam !== null && !empty($bestExam['passed']),
                ],
            ];
        } else {
            $isGitCourse = ($courseSlug === 'git-basics');
            if ($xp >= 6500) {
                $rank = $isGitCourse ? 'Architecte GitOps & CI/CD' : 'Architecte Technique Principal';
                $nextRankXp = 10000;
            } elseif ($xp >= 3500) {
                $rank = $isGitCourse ? 'Mainteneur Senior Git' : 'Expert Technique Senior';
                $nextRankXp = 6500;
            } elseif ($xp >= 1500) {
                $rank = $isGitCourse ? 'Contributeur Git Confirmé' : 'Praticien Confirmé';
                $nextRankXp = 3500;
            } elseif ($xp >= 400) {
                $rank = $isGitCourse ? 'Développeur Git Opérationnel' : 'Praticien Opérationnel';
                $nextRankXp = 1500;
            } else {
                $rank = $isGitCourse ? 'Apprenant Git Fondamentaux' : 'Apprenant Certifiant';
                $nextRankXp = 400;
            }

            $badges = [
                [
                    'id' => 'first_step_git',
                    'icon' => '🎖️',
                    'title' => $isGitCourse ? 'Premier Pas Git' : 'Premier Pas du Cursus',
                    'description' => 'Valider au moins 1 module de cours, 1 atelier pratique ou le positionnement initial.',
                    'unlocked' => !empty($completedModules) || !empty($completedLabs) || $diagnostic !== null,
                ],
                [
                    'id' => 'git_local_index',
                    'icon' => '📦',
                    'title' => $isGitCourse ? 'Maître du Dépôt Local & Index' : 'Socle Fondamental (Module 01)',
                    'description' => $isGitCourse
                        ? 'Valider le Module 01 ou l\'Atelier 01 (init, staging, commits atomiques, .gitignore).'
                        : 'Valider le Module 01 ou l\'Atelier 01 du cursus.',
                    'unlocked' => $hasAnyPrefix(array_merge($completedModules, $completedLabs), ['_01_']),
                ],
                [
                    'id' => 'git_branch_architect',
                    'icon' => '🌿',
                    'title' => $isGitCourse ? 'Architecte des Branches' : 'Maîtrise Thématique (Module 02)',
                    'description' => $isGitCourse
                        ? 'Valider le Module 02 sur les pointeurs légers, git switch et les fusions.'
                        : 'Valider le Module 02 du cursus de formation.',
                    'unlocked' => $hasAnyPrefix($completedModules, ['_02_']),
                ],
                [
                    'id' => 'git_conflict_solver',
                    'icon' => '🔀',
                    'title' => $isGitCourse ? 'Arbitre des Conflits Merge' : 'Pratique Avancée (Atelier 02)',
                    'description' => $isGitCourse
                        ? 'Valider l\'Atelier 02 sur la résolution de conflits de fusion sans régression.'
                        : 'Valider l\'Atelier 02 du cursus de formation.',
                    'unlocked' => $hasAnyPrefix($completedLabs, ['_02_']),
                ],
                [
                    'id' => 'git_remote_collab',
                    'icon' => '🌐',
                    'title' => $isGitCourse ? 'Collaborateur Dépôts Distants & PR' : 'Collaboration & Intégration (Module 03)',
                    'description' => $isGitCourse
                        ? 'Valider le Module 03 ou l\'Atelier 03 (fetch, pull, push -u, Pull Request).'
                        : 'Valider le Module 03 ou l\'Atelier 03 du cursus.',
                    'unlocked' => $hasAnyPrefix(array_merge($completedModules, $completedLabs), ['_03_']),
                ],
                [
                    'id' => 'git_quiz_master',
                    'icon' => '🧠',
                    'title' => 'Maître des Quiz Formatifs',
                    'description' => sprintf('Réussir les %d évaluations formatives QCM des modules de la formation.', $safeTotalModules),
                    'unlocked' => count($quizScores) >= $safeTotalModules,
                ],
                [
                    'id' => 'git_labs_grandmaster',
                    'icon' => '🏆',
                    'title' => sprintf('Grand Maître Pratique (%d/%d Ateliers)', $safeTotalLabs, $safeTotalLabs),
                    'description' => sprintf('Valider l\'intégralité des %d ateliers pratiques et critères de validation.', $safeTotalLabs),
                    'unlocked' => count($completedLabs) >= $safeTotalLabs,
                ],
                [
                    'id' => 'git_certified',
                    'icon' => '🎓',
                    'title' => $isGitCourse ? 'Certifié Sommatif Git Fondamentaux' : 'Certifié Sommatif LightPath',
                    'description' => 'Réussir l\'Examen Final de Certification Sommatif avec un score ≥ 75%.',
                    'unlocked' => $bestExam !== null && !empty($bestExam['passed']),
                ],
            ];
        }

        $unlockedCount = 0;
        foreach ($badges as $b) {
            if ($b['unlocked']) {
                $unlockedCount++;
            }
        }

        return [
            'xp' => $xp,
            'rank' => $rank,
            'next_rank_xp' => $nextRankXp,
            'learning_seconds' => $learningSeconds,
            'learning_hours_formatted' => self::formatDurationHours($learningSeconds),
            'unlocked_count' => $unlockedCount,
            'total_badges' => count($badges),
            'total_modules' => $safeTotalModules,
            'total_labs' => $safeTotalLabs,
            'badges' => $badges,
        ];
    }

    public static function formatDurationHours(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 min';
        }
        $hours = intdiv($seconds, 3600);
        $mins = intdiv($seconds % 3600, 60);
        if ($hours === 0) {
            return max(1, $mins) . ' min';
        }
        return sprintf('%dh %02dm', $hours, $mins);
    }
}
