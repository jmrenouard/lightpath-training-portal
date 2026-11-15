<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Lean runtime manager for modular LMS feature toggles.
 */
class FeatureManager
{
    private StorageInterface $storage;
    private array $definitions = [];
    private ?array $resolvedFlags = null;

    public function __construct(StorageInterface $storage, ?string $configPath = null)
    {
        $this->storage = $storage;
        $path = $configPath ?? (__DIR__ . '/../../config/features.php');
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $this->definitions = $loaded;
            }
        }
    }

    public function isEnabled(string $flag): bool
    {
        $flags = $this->all();
        if (array_key_exists($flag, $flags)) {
            return (bool)$flags[$flag];
        }
        return false;
    }

    public function all(): array
    {
        if ($this->resolvedFlags !== null) {
            return $this->resolvedFlags;
        }
        $overrides = $this->storage->getFeatureFlags();
        $resolved = [];
        foreach ($this->definitions as $key => $def) {
            $default = (bool)($def['default'] ?? $def['enabled'] ?? true);
            $resolved[$key] = array_key_exists($key, $overrides)
                ? (bool)$overrides[$key]
                : $default;
        }
        $this->resolvedFlags = $resolved;
        return $this->resolvedFlags;
    }

    public function getDefinitions(): array
    {
        $states = $this->all();
        $result = [];
        foreach ($this->definitions as $key => $def) {
            $result[$key] = array_merge($def, [
                'enabled' => $states[$key] ?? (bool)($def['default'] ?? true),
            ]);
        }
        return $result;
    }

    public function updateFlags(array $submittedFlags, string $actorEmail): array
    {
        $normalized = [];
        foreach ($this->definitions as $key => $def) {
            $normalized[$key] = isset($submittedFlags[$key])
                && filter_var($submittedFlags[$key], FILTER_VALIDATE_BOOLEAN);
        }

        $saved = $this->storage->saveFeatureFlags($normalized);
        $this->resolvedFlags = null;
        $enabledCount = count(array_filter($saved));
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_FEATURES_UPDATE',
            sprintf('Mise à jour des modules fonctionnels (%d/%d activés)', $enabledCount, count($this->definitions))
        );

        return $saved;
    }
}
