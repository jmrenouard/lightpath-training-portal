<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

use PDO;

/**
 * PDO storage adapter (PostgreSQL / MySQL / SQLite) for production web hosting.
 * Persists JSON KV payloads in a relational table or falls back to JsonStorage when offline.
 */
class PdoStorage extends JsonStorage
{
    protected ?PDO $pdo = null;
    protected ?string $lastError = null;

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
        } catch (\Throwable $e) {
            // Fall back cleanly to JsonStorage when the configured DB is unreachable at boot.
            $this->lastError = $e->getMessage();
            error_log('[LightPath LMS PdoStorage init] ' . $this->lastError);
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

    public function getLastError(): ?string
    {
        return $this->lastError;
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
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
                error_log('[LightPath LMS PdoStorage readFile] ' . $this->lastError);
                return parent::readFile($name, $default);
            }
        }
        return parent::readFile($name, $default);
    }

    protected function writeFile(string $name, array $data): void
    {
        if ($this->pdo !== null) {
            try {
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || $json === '') {
                    throw new \RuntimeException("JSON encoding failed for '$name'");
                }
                $now = date('Y-m-d H:i:s');
                $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

                // PostgreSQL / SQLite atomic upsert via ON CONFLICT DO UPDATE
                if ($driver === 'pgsql' || $driver === 'sqlite') {
                    $stmt = $this->pdo->prepare('
                        INSERT INTO lms_kv_store (store_key, payload, updated_at)
                        VALUES (:k, :p, :u)
                        ON CONFLICT (store_key) DO UPDATE SET payload = EXCLUDED.payload, updated_at = EXCLUDED.updated_at
                    ');
                    $stmt->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                    $this->lastError = null;
                    return;
                }

                // MySQL atomic upsert via ON DUPLICATE KEY UPDATE
                if ($driver === 'mysql') {
                    $stmt = $this->pdo->prepare('
                        INSERT INTO lms_kv_store (store_key, payload, updated_at)
                        VALUES (:k, :p, :u)
                        ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)
                    ');
                    $stmt->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                    $this->lastError = null;
                    return;
                }

                $upd = $this->pdo->prepare('UPDATE lms_kv_store SET payload = :p, updated_at = :u WHERE store_key = :k');
                $upd->execute([':p' => $json, ':u' => $now, ':k' => $name]);
                if ($upd->rowCount() === 0) {
                    $ins = $this->pdo->prepare('INSERT INTO lms_kv_store (store_key, payload, updated_at) VALUES (:k, :p, :u)');
                    $ins->execute([':k' => $name, ':p' => $json, ':u' => $now]);
                }
                $this->lastError = null;
                return;
            } catch (\Throwable $e) {
                // Do not silently fall back to local JSON once PDO is connected to avoid split-brain writes.
                $this->lastError = $e->getMessage();
                error_log('[LightPath LMS PdoStorage writeFile] ' . $this->lastError);
                throw new \RuntimeException("PDO write error for '$name': " . $this->lastError, 0, $e);
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
                } catch (\Throwable $e) {
                    $this->lastError = $e->getMessage();
                    error_log('[LightPath LMS PdoStorage beginTx] ' . $this->lastError);
                    $ownTx = false;
                }
            }
            try {
                // Lock target row on PostgreSQL / MySQL to prevent lost updates
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
                $this->lastError = $e->getMessage();
                error_log('[LightPath LMS PdoStorage mutateFile] ' . $this->lastError);
                if ($ownTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return parent::mutateFile($name, $default, $mutator);
    }

    protected function listAuditArchiveKeys(): array
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query("SELECT store_key FROM lms_kv_store WHERE store_key LIKE 'audit_logs_%' ORDER BY store_key DESC");
                $keys = [];
                if ($stmt !== false) {
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                        $name = (string)$key;
                        if (preg_match('/^audit_logs_\d{6}$/', $name)) {
                            $keys[] = $name;
                        }
                    }
                }
                return $keys;
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
                error_log('[LightPath LMS PdoStorage listAuditArchiveKeys] ' . $this->lastError);
                return parent::listAuditArchiveKeys();
            }
        }
        return parent::listAuditArchiveKeys();
    }
}
