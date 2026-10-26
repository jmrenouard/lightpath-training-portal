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
    protected ?PDO $pdo = null;

    public function __construct(array $pdoConfig, string $fallbackDataDir)
    {
        try {
            if (isset($pdoConfig['pdo']) && $pdoConfig['pdo'] instanceof PDO) {
                $this->pdo = $pdoConfig['pdo'];
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } elseif (!empty($pdoConfig['dsn'])) {
                $this->pdo = new PDO(
                    (string)$pdoConfig['dsn'],
                    (string)($pdoConfig['username'] ?? ''),
                    (string)($pdoConfig['password'] ?? ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }
            if ($this->pdo !== null) {
                $this->initSchema();
            }
        } catch (\Throwable) {
            // Repli transparent vers JsonStorage si la base distante n'est pas joignable
            $this->pdo = null;
        }

        parent::__construct($fallbackDataDir);
    }

    protected function initSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS lms_kv_store (
                store_key VARCHAR(128) PRIMARY KEY,
                payload TEXT NOT NULL,
                updated_at VARCHAR(32) NOT NULL
            )
        ");
    }

    public function isPdoActive(): bool
    {
        return $this->pdo !== null;
    }

    protected function readFile(string $name, array $default = []): array
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare('SELECT payload FROM lms_kv_store WHERE store_key = :k LIMIT 1');
                $stmt->execute([':k' => $name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && isset($row['payload'])) {
                    $decoded = json_decode((string)$row['payload'], true);
                    return is_array($decoded) ? $decoded : $default;
                }
                return $default;
            } catch (\Throwable) {
                return parent::readFile($name, $default);
            }
        }
        return parent::readFile($name, $default);
    }

    protected function writeFile(string $name, array $data): void
    {
        if ($this->pdo !== null) {
            try {
                $json = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $now = date('Y-m-d H:i:s');
                $upd = $this->pdo->prepare('UPDATE lms_kv_store SET payload = :p, updated_at = :u WHERE store_key = :k');
                $upd->execute([':p' => $json, ':u' => $now, ':k' => $name]);
                if ($upd->rowCount() === 0) {
                    $ins = $this->pdo->prepare('INSERT INTO lms_kv_store (store_key, payload, updated_at) VALUES (:k, :p, :u)');
                    $ins->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                }
                return;
            } catch (\Throwable) {
                // Repli transparent sur le stockage fichier atomique
            }
        }
        parent::writeFile($name, $data);
    }
}
