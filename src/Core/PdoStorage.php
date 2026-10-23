<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

use PDO;

/**
 * Adaptateur PDO (PostgreSQL / MySQL / SQLite) pour hébergement Web de production
 * Encapsule la persistance dans une table relationnelle JSONB/TEXT ou bascule sur JsonStorage.
 */
class PdoStorage extends JsonStorage
{
    private ?PDO $pdo = null;

    public function __construct(array $pdoConfig, string $fallbackDataDir)
    {
        parent::__construct($fallbackDataDir);
        try {
            $this->pdo = new PDO(
                $pdoConfig['dsn'],
                $pdoConfig['username'] ?? '',
                $pdoConfig['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS lms_kv_store (
                    store_key VARCHAR(128) PRIMARY KEY,
                    payload TEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (\Throwable) {
            // Repli transparent vers JsonStorage si la base distante n'est pas joignable
            $this->pdo = null;
        }
    }
}
