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
                $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

                // FIX-03: PostgreSQL / SQLite avec ON CONFLICT DO UPDATE atomique
                if ($driver === 'pgsql' || $driver === 'sqlite') {
                    $stmt = $this->pdo->prepare('
                        INSERT INTO lms_kv_store (store_key, payload, updated_at)
                        VALUES (:k, :p, :u)
                        ON CONFLICT (store_key) DO UPDATE SET payload = EXCLUDED.payload, updated_at = EXCLUDED.updated_at
                    ');
                    $stmt->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                    return;
                }

                // MySQL avec ON DUPLICATE KEY UPDATE atomique
                if ($driver === 'mysql') {
                    $stmt = $this->pdo->prepare('
                        INSERT INTO lms_kv_store (store_key, payload, updated_at)
                        VALUES (:k, :p, :u)
                        ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)
                    ');
                    $stmt->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                    return;
                }

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

    protected function mutateFile(string $name, array $default, callable $mutator): mixed
    {
        if ($this->pdo !== null) {
            $ownTx = !$this->pdo->inTransaction();
            if ($ownTx) {
                try {
                    $this->pdo->beginTransaction();
                } catch (\Throwable) {
                    $ownTx = false;
                }
            }
            try {
                // FIX-03: FOR UPDATE sous PostgreSQL / MySQL pour éviter le Lost Update
                $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $forUpdate = ($driver === 'pgsql' || $driver === 'mysql') ? ' FOR UPDATE' : '';
                $stmt = $this->pdo->prepare("SELECT payload FROM lms_kv_store WHERE store_key = :k{$forUpdate} LIMIT 1");
                $stmt->execute([':k' => $name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $current = $default;
                if (is_array($row) && isset($row['payload'])) {
                    $decoded = json_decode((string)$row['payload'], true);
                    if (is_array($decoded)) {
                        $current = $decoded;
                    }
                }

                [$updated, $result] = $mutator($current);
                if ($updated !== null) {
                    $this->writeFile($name, $updated);
                }
                if ($ownTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return $result;
            } catch (\Throwable $e) {
                if ($ownTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return parent::mutateFile($name, $default, $mutator);
    }
}
