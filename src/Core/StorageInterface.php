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
}
