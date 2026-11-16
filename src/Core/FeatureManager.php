<?php
declare(strict_types=1);

namespace LightPath\LMS\Core;

/**
 * Runtime manager for categorized LMS feature toggles, dependency enforcement,
 * deployment profiles, and per-course overrides.
 */
class FeatureManager
{
    private StorageInterface $storage;
    private array $definitions = [];
    private ?array $resolvedFlags = null;
    private array $courseOverrideCache = [];

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

    /**
     * Resolves raw stored flags (without dependency evaluation) merged with defaults.
     */
    private function rawGlobalFlags(): array
    {
        $overrides = $this->storage->getFeatureFlags();
        $raw = [];
        foreach ($this->definitions as $key => $def) {
            $default = (bool)($def['default'] ?? $def['enabled'] ?? true);
            $raw[$key] = array_key_exists($key, $overrides)
                ? (bool)$overrides[$key]
                : $default;
        }
        return $raw;
    }

    /**
     * Evaluates a flag and its recursive `requires` dependencies against a raw boolean map.
     */
    private function isFlagChainSatisfied(string $flag, array $rawMap, array $visited = []): bool
    {
        if (!array_key_exists($flag, $this->definitions) || isset($visited[$flag])) {
            return false;
        }
        if (empty($rawMap[$flag])) {
            return false;
        }
        $visited[$flag] = true;
        $requires = $this->definitions[$flag]['requires'] ?? [];
        if (is_array($requires)) {
            foreach ($requires as $dependency) {
                if (!$this->isFlagChainSatisfied((string)$dependency, $rawMap, $visited)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Resolves effective boolean states for all flags after enforcing `requires` dependencies.
     */
    private function enforceDependencies(array $rawMap): array
    {
        $effective = [];
        foreach ($this->definitions as $key => $def) {
            $effective[$key] = $this->isFlagChainSatisfied((string)$key, $rawMap);
        }
        return $effective;
    }

    public function isEnabled(string $flag, ?string $courseSlug = null): bool
    {
        if (!array_key_exists($flag, $this->definitions)) {
            return false;
        }
        $flags = $this->all();
        if ($courseSlug === null || trim($courseSlug) === '') {
            return (bool)($flags[$flag] ?? false);
        }

        $courseOverrides = $this->getCourseOverrides($courseSlug);
        if (empty($courseOverrides)) {
            return (bool)($flags[$flag] ?? false);
        }

        $merged = array_merge($flags, $courseOverrides);
        return $this->isFlagChainSatisfied($flag, $merged);
    }

    public function isEnabledForCourse(string $flag, string $courseSlug): bool
    {
        return $this->isEnabled($flag, $courseSlug);
    }

    public function all(): array
    {
        if ($this->resolvedFlags !== null) {
            return $this->resolvedFlags;
        }
        $this->resolvedFlags = $this->enforceDependencies($this->rawGlobalFlags());
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
        $enabledCount = count(array_filter($this->all()));
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_FEATURES_UPDATE',
            sprintf('Mise à jour des modules fonctionnels (%d/%d activés)', $enabledCount, count($this->definitions))
        );

        return $saved;
    }

    /**
     * Returns preset deployment profiles for quick environment configuration.
     */
    public function getProfiles(): array
    {
        return [
            'full_enterprise' => [
                'key' => 'full_enterprise',
                'label' => 'Entreprise Complet (10/10 modules)',
                'description' => 'Active tous les modules LMS : laboratoires Docker, Chaos Engineering, gamification, Q&A, notes, flashcards, SCORM et satisfaction J+60.',
                'flags' => [
                    'self_registration' => true,
                    'demo_quick_login' => true,
                    'docker_labs' => true,
                    'chaos_engineering' => true,
                    'gamification' => true,
                    'qna_forum' => true,
                    'personal_notes' => true,
                    'flashcards_srs' => true,
                    'scorm_export' => true,
                    'cold_satisfaction_j60' => true,
                ],
            ],
            'classroom_workshop' => [
                'key' => 'classroom_workshop',
                'label' => 'Atelier Présentiel Encadré',
                'description' => 'Désactive l\'auto-inscription et la démo rapide tout en conservant les labs Docker, le Q&A, les notes, les flashcards et le suivi Qualiopi.',
                'flags' => [
                    'self_registration' => false,
                    'demo_quick_login' => false,
                    'docker_labs' => true,
                    'chaos_engineering' => true,
                    'gamification' => true,
                    'qna_forum' => true,
                    'personal_notes' => true,
                    'flashcards_srs' => true,
                    'scorm_export' => true,
                    'cold_satisfaction_j60' => true,
                ],
            ],
            'exam_lockdown' => [
                'key' => 'exam_lockdown',
                'label' => 'Mode Examen Certifiant (Verrouillé)',
                'description' => 'Mode évaluation stricte : désactive l\'inscription, les comptes démo, les labs Docker, le forum Q&A, les notes et les flashcards.',
                'flags' => [
                    'self_registration' => false,
                    'demo_quick_login' => false,
                    'docker_labs' => false,
                    'chaos_engineering' => false,
                    'gamification' => false,
                    'qna_forum' => false,
                    'personal_notes' => false,
                    'flashcards_srs' => false,
                    'scorm_export' => true,
                    'cold_satisfaction_j60' => true,
                ],
            ],
            'static_hosting_nodocker' => [
                'key' => 'static_hosting_nodocker',
                'label' => 'Hébergement Web Statique (Sans Docker)',
                'description' => 'Désactive les conteneurs Docker et le Chaos Engineering pour hébergement web standard tout en conservant cours, quiz, notes et Qualiopi.',
                'flags' => [
                    'self_registration' => true,
                    'demo_quick_login' => false,
                    'docker_labs' => false,
                    'chaos_engineering' => false,
                    'gamification' => true,
                    'qna_forum' => true,
                    'personal_notes' => true,
                    'flashcards_srs' => true,
                    'scorm_export' => true,
                    'cold_satisfaction_j60' => true,
                ],
            ],
        ];
    }

    public function applyProfile(string $profileKey, string $actorEmail = 'admin@lightpath.fr'): bool
    {
        $profiles = $this->getProfiles();
        if (!isset($profiles[$profileKey])) {
            return false;
        }
        $profileFlags = $profiles[$profileKey]['flags'];
        $normalized = [];
        foreach ($this->definitions as $key => $def) {
            $normalized[$key] = (bool)($profileFlags[$key] ?? ($def['default'] ?? true));
        }

        $this->storage->saveFeatureFlags($normalized);
        $this->resolvedFlags = null;
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_FEATURE_PROFILE',
            sprintf('Application du profil fonctionnel : %s', $profileKey)
        );
        return true;
    }

    public function getCourseOverrides(string $courseSlug): array
    {
        $slug = trim($courseSlug);
        if ($slug === '') {
            return [];
        }
        if (array_key_exists($slug, $this->courseOverrideCache)) {
            return $this->courseOverrideCache[$slug];
        }
        $stored = $this->storage->getCourseFeatureOverrides($slug);
        $filtered = [];
        foreach ($stored as $flag => $val) {
            if (array_key_exists((string)$flag, $this->definitions)) {
                $filtered[(string)$flag] = (bool)$val;
            }
        }
        $this->courseOverrideCache[$slug] = $filtered;
        return $filtered;
    }

    public function setCourseOverrides(string $courseSlug, array $overrides, string $actorEmail = 'admin@lightpath.fr'): void
    {
        $slug = trim($courseSlug);
        if ($slug === '') {
            return;
        }
        $clean = [];
        foreach ($overrides as $flag => $val) {
            $key = (string)$flag;
            if (!array_key_exists($key, $this->definitions)) {
                continue;
            }
            if ($val === null || $val === '' || $val === 'inherit') {
                continue;
            }
            $clean[$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }

        $this->storage->saveCourseFeatureOverrides($slug, $clean);
        unset($this->courseOverrideCache[$slug]);
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_COURSE_FEATURES_UPDATE',
            sprintf('Surcharges de fonctionnalités mises à jour pour le cours %s (%d flag(s))', $slug, count($clean))
        );
    }

    public function clearCourseOverrides(string $courseSlug, string $actorEmail = 'admin@lightpath.fr'): void
    {
        $slug = trim($courseSlug);
        if ($slug === '') {
            return;
        }
        $this->storage->saveCourseFeatureOverrides($slug, []);
        unset($this->courseOverrideCache[$slug]);
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_COURSE_FEATURES_CLEAR',
            sprintf('Réinitialisation des surcharges de fonctionnalités pour le cours %s', $slug)
        );
    }

    public function exportConfigJson(): string
    {
        $payload = [
            'schema' => 'lightpath-lms-features-v1',
            'exported_at' => date('Y-m-d H:i:s'),
            'flags' => $this->all(),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }

    public function importConfigJson(string $json, string $actorEmail = 'admin@lightpath.fr'): bool
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }
        $sourceFlags = $decoded['flags'] ?? $decoded['global_flags'] ?? $decoded;
        if (!is_array($sourceFlags)) {
            return false;
        }

        $matchedKeys = 0;
        $current = $this->rawGlobalFlags();
        $normalized = [];
        foreach ($this->definitions as $key => $def) {
            if (array_key_exists($key, $sourceFlags)) {
                $matchedKeys++;
                $normalized[$key] = filter_var($sourceFlags[$key], FILTER_VALIDATE_BOOLEAN);
            } else {
                $normalized[$key] = (bool)($current[$key] ?? ($def['default'] ?? true));
            }
        }

        if ($matchedKeys === 0) {
            return false;
        }

        $this->storage->saveFeatureFlags($normalized);
        $this->resolvedFlags = null;
        $this->courseOverrideCache = [];
        $this->storage->logAuditEvent(
            $actorEmail,
            'ADMIN_FEATURES_IMPORT',
            sprintf('Importation JSON de configuration des fonctionnalités (%d flag(s))', $matchedKeys)
        );
        return true;
    }
}
