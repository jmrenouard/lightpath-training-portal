<?php
declare(strict_types=1);

namespace LightPath\LMS\Http;

use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\FeatureManager;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;

/**
 * Handles authentication flows (login, self-registration, logout) and enrolled-course access enforcement.
 */
class AuthController
{
    private Auth $auth;
    private FeatureManager $featureManager;

    public function __construct(Auth $auth, FeatureManager $featureManager)
    {
        $this->auth = $auth;
        $this->featureManager = $featureManager;
    }

    /**
     * Terminates the current authenticated session and redirects to the login page.
     */
    public function handleLogout(): never
    {
        $this->auth->logout();
        header('Location: index.php?page=login');
        exit;
    }

    /**
     * Authenticates a user via POST credentials with Same-Origin, CSRF, and brute-force checks.
     */
    public function handleLoginPost(array $post, array $server): never
    {
        if (!Security::verifySameOriginRequest($server)) {
            http_response_code(403);
            echo 'Requête cross-origin refusée (403).';
            exit;
        }

        $email = trim((string)($post['email'] ?? ''));
        $password = (string)($post['password'] ?? '');
        $csrf = (string)($post['csrf_token'] ?? '');
        $clientIp = (string)($server['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!Security::verifyCsrfToken($csrf)) {
            $_SESSION['flash_error'] = 'Session expirée (jeton CSRF invalide). Veuillez réessayer.';
            header('Location: index.php?page=login');
            exit;
        }

        if ($this->auth->isLoginRateLimited($email, $clientIp)) {
            $_SESSION['flash_error'] = 'Trop de tentatives de connexion échouées pour ce compte. Veuillez patienter 5 minutes.';
            header('Location: index.php?page=login');
            exit;
        }

        $loggedUser = $this->auth->login($email, $password, $clientIp);
        if ($loggedUser !== null) {
            $_SESSION['flash_success'] = 'Bienvenue, ' . $loggedUser['name'] . ' !';
            header('Location: index.php?page=dashboard');
            exit;
        }

        $_SESSION['flash_error'] = 'Identifiants incorrects ou compte inactif.';
        header('Location: index.php?page=login');
        exit;
    }

    /**
     * Handles self-service student registration when enabled by system feature toggles.
     */
    public function handleRegisterPost(array $post, array $server): never
    {
        if (!Security::verifySameOriginRequest($server)) {
            http_response_code(403);
            echo 'Requête cross-origin refusée (403).';
            exit;
        }

        if (!$this->featureManager->isEnabled('self_registration')) {
            http_response_code(403);
            $_SESSION['flash_error'] = 'L\'auto-inscription est actuellement désactivée par l\'administrateur.';
            header('Location: index.php?page=login');
            exit;
        }

        $name = trim((string)($post['name'] ?? ''));
        $email = trim((string)($post['email'] ?? ''));
        $company = trim((string)($post['company'] ?? ''));
        $password = (string)($post['password'] ?? '');
        $csrf = (string)($post['csrf_token'] ?? '');
        $clientIp = (string)($server['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!Security::verifyCsrfToken($csrf)) {
            $_SESSION['flash_error'] = 'Jeton CSRF invalide.';
            header('Location: index.php?page=login&tab=register');
            exit;
        }

        $created = $this->auth->registerUser($name, $email, $password, 'student', $company);
        if ($created !== null) {
            $this->auth->login($email, $password, $clientIp);
            $_SESSION['flash_success'] = 'Votre compte apprenant a été créé avec succès !';
            header('Location: index.php?page=dashboard');
            exit;
        }

        $_SESSION['flash_error'] = 'Impossible de créer ce compte (vérifiez l\'email ou le mot de passe d\'au moins 6 caractères).';
        header('Location: index.php?page=login&tab=register');
        exit;
    }

    /**
     * Enforces enrolled-course scoping for learners, falling back to an enrolled course when unspecified.
     */
    public function enforceCourseAccess(
        ?array $currentUser,
        CourseRepository $courseRepo,
        string &$currentCourseSlug,
        ?array &$currentCourse,
        string $requestedCourse,
        string $action
    ): void {
        if ($currentUser === null || $currentCourseSlug === '' || $this->auth->canAccessCourse($currentUser, $currentCourseSlug)) {
            return;
        }

        $fallbackCourse = null;
        if ($requestedCourse === '') {
            foreach ((array)($currentUser['enrolled_courses'] ?? []) as $enrolledSlug) {
                $candidate = $courseRepo->getCourse((string)$enrolledSlug);
                if ($candidate !== null && $this->auth->canAccessCourse($currentUser, (string)$candidate['slug'])) {
                    $fallbackCourse = $candidate;
                    break;
                }
            }
        }

        if ($fallbackCourse !== null) {
            $currentCourse = $fallbackCourse;
            $currentCourseSlug = (string)$fallbackCourse['slug'];
            return;
        }

        http_response_code(403);
        if (str_starts_with($action, 'api_')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Accès refusé (403) : vous n\'êtes pas inscrit à cette formation.']);
        } else {
            echo 'Accès refusé (403) : vous n\'êtes pas inscrit à cette formation.';
        }
        exit;
    }
}
