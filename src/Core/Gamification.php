<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Moteur de Gamification, XP et Badges de Compétences DBA PostgreSQL 18
 */
class Gamification
{
    /**
     * Calcule l'expérience (XP), le grade DBA et les 8 badges de compétences d'un apprenant.
     */
    public static function computeUserBadgesAndXp(
        array $progress,
        ?array $diagnostic = null,
        ?array $bestExam = null,
        array $attendance = []
    ): array {
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
                'title' => 'Grand Maître Sakila (34/34 Labs)',
                'description' => 'Valider l\'intégralité des 34 ateliers pratiques sur conteneurs Docker PostgreSQL 18.',
                'unlocked' => count($completedLabs) >= 34,
            ],
            [
                'id' => 'pg18_certified',
                'icon' => '🎓',
                'title' => 'Certifié Sommatif PostgreSQL 18',
                'description' => 'Réussir l\'Examen Final de Certification Sommatif avec un score ≥ 75%.',
                'unlocked' => $bestExam !== null && !empty($bestExam['passed']),
            ],
        ];

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
