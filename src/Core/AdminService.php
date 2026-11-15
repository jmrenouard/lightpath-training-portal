<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Docker\DockerManager;

/**
 * Lean application service aggregating cohort KPIs, filtering, CSV audit exports,
 * and system health diagnostics for the LMS administrative interface.
 */
class AdminService
{
    /**
     * Computes aggregated cohort metrics and per-user summaries in a single pass
     * using preloaded progress payloads (eliminating N+1 storage reads).
     */
    public function computeCohortKpis(
        array $users,
        StorageInterface $storage,
        array $modules,
        array $labs,
        string $courseSlug,
        array $allProgress = []
    ): array {
        if ($allProgress === []) {
            $allProgress = $storage->getAllProgress();
        }

        $allDiagnostics = $storage->getAllDiagnostics();
        $allExams = $storage->getAllExamAttempts();
        $allAttendance = $storage->getAllAttendance();
        $allSurveys = $storage->getAllSatisfactionSurveys();

        $totalModules = max(0, count($modules));
        $totalLabs = max(0, count($labs));

        $studentsCount = 0;
        $activeStudentsCount = 0;
        $staffCount = 0;
        $sumModulePct = 0.0;
        $sumLabPct = 0.0;
        $diagnosticsCount = 0;
        $examsPassedCount = 0;
        $quizzesTotalCount = 0;
        $sumSignedHalfDays = 0;
        $perUser = [];

        foreach ($users as $u) {
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $role = (string)($u['role'] ?? 'student');
            $isStaff = in_array($role, ['admin', 'trainer'], true);

            // Resolve course-scoped entry supporting "{uid}:{courseSlug}", nested "[uid][courseSlug]", and legacy "[uid]" maps
            $uProg = self::resolveCourseEntry($allProgress, $uid, $courseSlug);
            $uProg = is_array($uProg) ? $uProg : [];

            $doneMods = count((array)($uProg['completed_modules'] ?? []));
            $doneLabs = count((array)($uProg['completed_labs'] ?? []));
            $quizzes = is_array($uProg['quiz_scores'] ?? null)
                ? $uProg['quiz_scores']
                : (is_array($uProg['quizzes'] ?? null) ? $uProg['quizzes'] : []);
            $quizCount = count($quizzes);
            $quizAvg = 0;
            if ($quizCount > 0) {
                $pctSum = 0;
                foreach ($quizzes as $qz) {
                    if (is_array($qz)) {
                        $pctSum += (int)($qz['percentage'] ?? $qz['percent'] ?? 0);
                    }
                }
                $quizAvg = (int)round($pctSum / $quizCount);
            }

            $modulePct = $totalModules > 0 ? min(100, (int)round(($doneMods / $totalModules) * 100)) : 0;
            $labPct = $totalLabs > 0 ? min(100, (int)round(($doneLabs / $totalLabs) * 100)) : 0;

            $uDiag = self::resolveCourseEntry($allDiagnostics, $uid, $courseSlug);
            if (!is_array($uDiag)) {
                $uDiag = null;
            }

            // JsonStorage prepends attempts via array_unshift, so index 0 is normally latest
            $uExamsRaw = self::resolveCourseEntry($allExams, $uid, $courseSlug);
            $uExams = is_array($uExamsRaw) ? array_values($uExamsRaw) : [];
            $latestExam = !empty($uExams) && is_array($uExams[0] ?? null) ? $uExams[0] : null;
            if (count($uExams) > 1 && is_array($latestExam)) {
                $tailAttempt = end($uExams);
                if (is_array($tailAttempt)) {
                    $headTs = (string)($latestExam['submitted_at'] ?? '');
                    $tailTs = (string)($tailAttempt['submitted_at'] ?? '');
                    if ($tailTs !== '' && $headTs !== '' && strcmp($tailTs, $headTs) > 0) {
                        $latestExam = $tailAttempt;
                    }
                }
            }
            $hasPassedExam = false;
            foreach ($uExams as $attempt) {
                if (is_array($attempt) && !empty($attempt['passed'])) {
                    $hasPassedExam = true;
                    break;
                }
            }

            $uAttRaw = self::resolveCourseEntry($allAttendance, $uid, $courseSlug);
            $uAttEntries = is_array($uAttRaw) ? $uAttRaw : [];
            $signedEntries = array_filter(
                $uAttEntries,
                static fn($entry): bool => is_array($entry) && !empty($entry['signed_at'])
            );
            $signedHalfDays = count($signedEntries);
            $learningSeconds = (int)($uProg['learning_seconds'] ?? $uProg['time_spent_seconds'] ?? 0);

            $perUser[$uid] = [
                'done_modules' => $doneMods,
                'done_labs' => $doneLabs,
                'quiz_count' => $quizCount,
                'avg_quiz_score' => $quizAvg,
                'module_pct' => $modulePct,
                'lab_pct' => $labPct,
                'learning_seconds' => $learningSeconds,
                'diagnostic' => $uDiag,
                'exams' => $uExams,
                'latest_exam' => is_array($latestExam) ? $latestExam : null,
                'has_passed_exam' => $hasPassedExam,
                'attendance_entries' => $uAttEntries,
                'signed_half_days' => $signedHalfDays,
                'attendance_hours' => $signedHalfDays * 3.5,
            ];

            if ($isStaff) {
                $staffCount++;
                continue;
            }

            $studentsCount++;
            if (!empty($u['active'])) {
                $activeStudentsCount++;
            }
            $sumModulePct += $modulePct;
            $sumLabPct += $labPct;
            $quizzesTotalCount += $quizCount;
            $sumSignedHalfDays += $signedHalfDays;
            if ($uDiag !== null) {
                $diagnosticsCount++;
            }
            if ($hasPassedExam) {
                $examsPassedCount++;
            }
        }

        $avgModulesPct = $studentsCount > 0 ? round($sumModulePct / $studentsCount, 1) : 0.0;
        $avgLabsPct = $studentsCount > 0 ? round($sumLabPct / $studentsCount, 1) : 0.0;
        $examPassRate = $studentsCount > 0 ? round(($examsPassedCount / $studentsCount) * 100, 1) : 0.0;
        $avgHalfDays = $studentsCount > 0 ? round($sumSignedHalfDays / $studentsCount, 1) : 0.0;

        $surveysCount = 0;
        foreach ($allSurveys as $sKey => $sRow) {
            if (!is_array($sRow)) {
                continue;
            }
            $sKeyStr = (string)$sKey;
            $rowCourse = (string)($sRow['course_slug'] ?? '');
            if ($rowCourse !== '') {
                if ($rowCourse === $courseSlug) {
                    $surveysCount++;
                }
            } elseif (str_contains($sKeyStr, ':')) {
                if (str_ends_with($sKeyStr, ':' . $courseSlug)) {
                    $surveysCount++;
                }
            } else {
                $surveysCount++;
            }
        }

        return [
            'course_slug' => $courseSlug,
            'students_count' => $studentsCount,
            'active_students_count' => $activeStudentsCount,
            'staff_count' => $staffCount,
            'total_modules' => $totalModules,
            'total_labs' => $totalLabs,
            'avg_modules_pct' => $avgModulesPct,
            'avg_labs_pct' => $avgLabsPct,
            'diagnostics_count' => $diagnosticsCount,
            'exams_passed_count' => $examsPassedCount,
            'exam_pass_rate' => $examPassRate,
            'quizzes_total_count' => $quizzesTotalCount,
            'avg_attendance_half_days' => $avgHalfDays,
            'avg_attendance_hours' => round($avgHalfDays * 3.5, 1),
            'surveys_count' => $surveysCount,
            'per_user' => $perUser,
        ];
    }

    /**
     * Resolves a storage map entry across composite "{uid}:{courseSlug}", nested "[uid][courseSlug]",
     * and legacy "[uid]" indexing schemes.
     */
    private static function resolveCourseEntry(array $map, int $uid, string $courseSlug): mixed
    {
        $compositeKey = $uid . ':' . $courseSlug;
        if (array_key_exists($compositeKey, $map)) {
            return $map[$compositeKey];
        }

        $bucket = $map[(string)$uid] ?? $map[$uid] ?? null;
        if (is_array($bucket) && isset($bucket[$courseSlug]) && is_array($bucket[$courseSlug])) {
            return $bucket[$courseSlug];
        }

        return $bucket;
    }

    /**
     * Filters users by case-insensitive search query (name, email, company) and optional role.
     */
    public function filterUsers(array $users, string $query = '', string $role = ''): array
    {
        $needle = trim($query);
        $roleFilter = strtolower(trim($role));

        $filtered = array_filter($users, static function ($user) use ($needle, $roleFilter): bool {
            if (!is_array($user)) {
                return false;
            }
            if ($roleFilter !== '' && strtolower((string)($user['role'] ?? '')) !== $roleFilter) {
                return false;
            }
            if ($needle === '') {
                return true;
            }

            $haystack = implode(' ', [
                (string)($user['name'] ?? ''),
                (string)($user['email'] ?? ''),
                (string)($user['company'] ?? ''),
            ]);

            if (function_exists('mb_stripos')) {
                return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
            }
            return stripos($haystack, $needle) !== false;
        });

        return array_values($filtered);
    }

    /**
     * Filters audit trail entries by action category prefix/name and actor/detail substring.
     */
    public function filterAuditLogs(array $logs, string $category = '', string $actorQuery = ''): array
    {
        $catFilter = strtoupper(trim($category));
        $actorNeedle = trim($actorQuery);

        $filtered = array_filter($logs, static function ($entry) use ($catFilter, $actorNeedle): bool {
            if (!is_array($entry)) {
                return false;
            }
            $action = strtoupper((string)($entry['action'] ?? ''));
            if ($catFilter !== '' && $action !== $catFilter && !str_starts_with($action, $catFilter)) {
                return false;
            }
            if ($actorNeedle === '') {
                return true;
            }

            $haystack = (string)($entry['actor'] ?? '') . ' ' . (string)($entry['details'] ?? '');
            if (function_exists('mb_stripos')) {
                return mb_stripos($haystack, $actorNeedle, 0, 'UTF-8') !== false;
            }
            return stripos($haystack, $actorNeedle) !== false;
        });

        return array_values($filtered);
    }

    /**
     * Builds a spreadsheet-safe UTF-8 CSV document from audit logs with formula-injection neutralization.
     */
    public function exportAuditLogsCsv(array $logs): string
    {
        $fp = fopen('php://temp', 'r+b');
        if ($fp === false) {
            return "\xEF\xBB\xBFHorodatage;Acteur;Action;Details\n";
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['Horodatage', 'Acteur', 'Action', 'Details'], ';', '"', '\\');

        foreach ($logs as $row) {
            if (!is_array($row)) {
                continue;
            }
            fputcsv($fp, [
                Security::sanitizeCsvCell((string)($row['timestamp'] ?? '')),
                Security::sanitizeCsvCell((string)($row['actor'] ?? '')),
                Security::sanitizeCsvCell((string)($row['action'] ?? '')),
                Security::sanitizeCsvCell((string)($row['details'] ?? '')),
            ], ';', '"', '\\');
        }

        rewind($fp);
        $csv = (string)stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /**
     * Collects diagnostic health checks across PHP runtime, storage persistence, Docker, and course catalog.
     */
    public function getSystemHealthReport(
        array $config,
        StorageInterface $storage,
        DockerManager $docker,
        CourseRepository $courseRepo,
        ?PdoStorage $pdoCheck = null
    ): array {
        $checks = [];

        $requiredExts = ['json', 'mbstring', 'zip', 'pdo'];
        $missingExts = array_values(array_filter($requiredExts, static fn(string $ext): bool => !extension_loaded($ext)));
        $checks[] = [
            'component' => 'Runtime PHP ' . PHP_VERSION,
            'status' => $missingExts === [] ? 'ok' : 'warn',
            'detail' => $missingExts === []
                ? 'Extensions coeur actives (' . implode(', ', $requiredExts) . ')'
                : 'Extensions manquantes : ' . implode(', ', $missingExts),
        ];

        $pdoActive = $pdoCheck !== null && $pdoCheck->isPdoActive();
        $pdoErr = ($pdoCheck !== null && method_exists($pdoCheck, 'getLastError')) ? trim((string)$pdoCheck->getLastError()) : '';
        $dataDir = rtrim((string)($config['storage_path'] ?? (dirname(__DIR__, 2) . '/storage/data')), '/\\');
        $dataWritable = $dataDir !== '' && is_dir($dataDir) && is_writable($dataDir);
        $driver = (string)($config['storage_driver'] ?? 'json');

        $storageStatus = ($pdoActive || ($driver !== 'pdo' && $dataWritable))
            ? 'ok'
            : ($dataWritable ? 'warn' : 'error');
        $storageDetail = $pdoActive
            ? 'Base relationnelle PDO active (avec repli JSON atomique)'
            : ($pdoErr !== ''
                ? sprintf('PDO indisponible (%s) — %s', $pdoErr, $dataWritable ? 'repli JSON atomique actif' : 'stockage JSON non accessible')
                : ($dataWritable ? 'Stockage JSON atomique opérationnel (dossier accessible en écriture)' : 'Répertoire de stockage non accessible en écriture'));
        $checks[] = [
            'component' => 'Persistance & Stockage (' . strtoupper($driver) . ')',
            'status' => $storageStatus,
            'detail' => $storageDetail,
        ];

        $parsedCacheDir = $dataDir . '/parsed_cache';
        $parsedFilesCount = is_dir($parsedCacheDir) ? count(glob($parsedCacheDir . '/*.html') ?: []) : 0;
        $catalogFilesCount = $dataDir !== '' && is_dir($dataDir) ? count(glob($dataDir . '/catalog_cache_*.json') ?: []) : 0;
        $cacheWritable = is_dir($parsedCacheDir) ? is_writable($parsedCacheDir) : $dataWritable;
        $checks[] = [
            'component' => 'Cache de Rendu HTML/Catalogue',
            'status' => $cacheWritable ? 'ok' : 'warn',
            'detail' => $cacheWritable
                ? sprintf('Cache opérationnel (%d fichier(s) Markdown, %d catalogue(s))', $parsedFilesCount, $catalogFilesCount)
                : 'Répertoire de cache absent ou non accessible en écriture (' . $parsedCacheDir . ')',
        ];

        $rt = $docker->detectRuntime();
        $containers = !empty($rt['available']) ? $docker->listAllLabContainers() : [];
        $runningContainers = count(array_filter($containers, static fn(array $c): bool => !empty($c['running'])));
        $checks[] = [
            'component' => 'Orchestration Docker Labs',
            'status' => !empty($rt['available']) ? 'ok' : 'warn',
            'detail' => !empty($rt['available'])
                ? sprintf('Mode %s (%s) — %d/%d conteneur(s) actif(s)', (string)($rt['mode'] ?? 'native'), (string)($rt['version'] ?? ''), $runningContainers, count($containers))
                : 'Mode Cloud / Sandbox simulée (daemon Docker absent sur cet hôte)',
        ];

        $courses = $courseRepo->getAllCourses();
        $checks[] = [
            'component' => 'Catalogue Pédagogique',
            'status' => $courses !== [] ? 'ok' : 'error',
            'detail' => count($courses) . ' formation(s) disponible(s) dans le référentiel',
        ];

        $certSecret = (string)(getenv('LMS_CERT_HMAC_SECRET') ?: getenv('APP_SECRET') ?: '');
        $dockerPw = (string)($config['docker']['default_password'] ?? 'postgres');
        $securityOk = $certSecret !== '' && $dockerPw !== 'postgres';
        $checks[] = [
            'component' => 'Sécurité & Secrets d\'Environnement',
            'status' => $securityOk ? 'ok' : 'warn',
            'detail' => $securityOk
                ? 'Variables d\'environnement de signature et PostgreSQL configurées'
                : 'Mode développement : configurer LMS_CERT_HMAC_SECRET et mot de passe Docker dédié en production',
        ];

        $okCount = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'ok'));
        $warnCount = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'warn'));
        $errCount = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'error'));

        return [
            'checks' => $checks,
            'summary' => [
                'ok' => $okCount,
                'warn' => $warnCount,
                'error' => $errCount,
                'total' => count($checks),
                'checked_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
