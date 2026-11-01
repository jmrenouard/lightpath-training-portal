<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

interface StorageInterface
{
    public function getUsers(): array;
    public function getUserById(int $id): ?array;
    public function getUserByEmail(string $email): ?array;
    public function saveUser(array $user): array;
    public function deleteUser(int $id): bool;

    public function getUserProgress(int $userId, string $courseSlug): array;
    public function getAllProgress(): array;
    public function markItemCompleted(int $userId, string $courseSlug, string $itemType, string $itemId, bool $completed = true): void;
    public function saveQuizScore(int $userId, string $courseSlug, string $moduleId, int $score, int $total): void;
    public function saveUserNote(int $userId, string $courseSlug, string $itemId, string $note): void;

    public function getCustomCourses(): array;
    public function saveCustomCourse(array $course): void;

    public function logAuditEvent(string $actor, string $action, string $details): void;
    public function getAuditLogs(int $limit = 50): array;

    public function saveDiagnosticResult(int $userId, string $courseSlug, array $result): void;
    public function getDiagnosticResult(int $userId, string $courseSlug = 'postgresql-18'): ?array;
    public function getAllDiagnostics(): array;

    public function saveExamAttempt(int $userId, string $courseSlug, array $attempt): array;
    public function getExamAttempts(int $userId, string $courseSlug = 'postgresql-18'): array;
    public function getBestExamAttempt(int $userId, string $courseSlug = 'postgresql-18'): ?array;
    public function getAllExamAttempts(): array;

    public function recordLearningTime(int $userId, string $courseSlug, int $seconds = 60, string $itemId = ''): int;
    public function signAttendance(int $userId, string $courseSlug, string $halfDayKey, string $signatureText = '', string $ip = '127.0.0.1'): array;
    public function getUserAttendance(int $userId, string $courseSlug = 'postgresql-18'): array;
    public function getAllAttendance(): array;

    public function saveSatisfactionSurvey(int $userId, string $courseSlug, array $survey): array;
    public function getUserSatisfaction(int $userId, string $courseSlug = 'postgresql-18'): ?array;
    public function getAllSatisfactionSurveys(): array;

    public function toggleBookmark(int $userId, string $courseSlug, string $itemType, string $itemId = '', string $title = ''): array;
    public function recordLastVisited(int $userId, string $courseSlug, string $itemType, string $itemId, string $title, string $mode = 'reader'): void;
    public function saveFlashcardConfidence(int $userId, string $courseSlug, string $cardId, int|string $level): void;
    public function resetFlashcardReviews(int $userId, string $courseSlug): bool;
    public function addDiscussionMessage(string $courseSlug, string $itemId, int $userId, string $userName, string $userRole, string $message, ?string $parentId = null): array;
    public function getDiscussions(string $courseSlug, string $itemId = ''): array;

    public function signTrainerAttendance(int $userId, string $courseSlug, string $halfDayKey, string $trainerName = 'Jean-Marie Renouard'): array;
    public function saveColdSatisfactionSurvey(int $userId, string $courseSlug, array $survey): array;
    public function getUserColdSatisfaction(int $userId, string $courseSlug = 'postgresql-18'): ?array;
    public function getAllColdSatisfactionSurveys(): array;
    public function findCertificateByCode(string $certificateId): ?array;
    public function getClassroomState(string $courseSlug = 'postgresql-18'): array;
    public function saveClassroomState(string $courseSlug, array $state): array;
    public function resolveDiscussionMessage(string $courseSlug, string $itemId, string $messageId, bool $resolved = true): bool;
    public function deleteDiscussionMessage(string $courseSlug, string $itemId, string $messageId): bool;
    public function getAllCourseDiscussions(string $courseSlug = 'postgresql-18'): array;
    public function importUsersFromCsv(string $csvContent, string $defaultPassword = 'LightPath2026!', string $courseSlug = 'postgresql-18'): array;
}
