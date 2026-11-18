<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' && !empty($userBestExam['certificate_id'])) {
    $code = (string)$userBestExam['certificate_id'];
}

if (str_starts_with(strtoupper($code), 'LP-GITBAS-') || $currentCourseSlug === 'git-basics') {
    $verifyPrefix = 'GITBAS';
} elseif ($currentCourseSlug === 'postgresql-18') {
    $verifyPrefix = 'PG18';
} else {
    $verifyPrefix = strtoupper(substr((string)preg_replace('/[^A-Z0-9]/i', '', $currentCourseSlug), 0, 6)) ?: 'COURSE';
}
$verifyExampleCert = 'LP-' . $verifyPrefix . '-CERT-3-A1B2C3D4';
$verifyFormatExample = 'LP-' . $verifyPrefix . '-CERT-...';
$certData = ($code !== '') ? $storage->findCertificateByCode($code) : null;
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = ($host !== '') ? "{$proto}://{$host}" : rtrim((string)($appConfig['website'] ?? 'https://www.lightpath.fr'), '/');
$verifyUrl = $baseUrl . '/index.php?page=verify&code=' . urlencode($code);
?>

<div class="hero-banner p-hero-compact">
    <div class="flex-1 min-w-0">
        <div class="d-flex items-center gap-xs flex-wrap mb-xs">
            <span class="badge-code">REGISTRE OFFICIEL DE CERTIFICATION LIGHTPATH SARL</span>
            <span class="badge-phase">Vérification Cryptographique & Conformité Qualiopi</span>
        </div>
        <h1 class="hero-title-md mb-xs">🔍 Vérification d'Authenticité d'un Certificat</h1>
        <p class="hero-subtitle-sm mb-0">
            Saisissez l'identifiant unique figurant sur l'attestation ou le certificat (format <code><?= Security::e($verifyFormatExample) ?></code>) pour contrôler sa validité auprès de l'organisme de formation LightPath SARL.
        </p>
    </div>
</div>

<form method="GET" action="index.php" class="lms-card mb-lg">
    <input type="hidden" name="page" value="verify"/>
    <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
    <div class="d-flex gap-sm items-center flex-wrap">
        <input type="text"
               name="code"
               class="form-control tabular-nums flex-1 search-query-input code-mono-input"
               aria-label="Identifiant unique du certificat"
               placeholder="Ex: <?= Security::e($verifyExampleCert) ?>"
               value="<?= Security::e($code) ?>"
               required/>
        <button type="submit" class="btn btn-primary">
            🔍 Vérifier le Certificat
        </button>
        <?php if (!empty($userBestExam['certificate_id'])): ?>
            <a href="index.php?page=certificate&course=<?= urlencode($currentCourseSlug) ?>" class="btn btn-outline">
                🎓 Ouvrir mon Attestation
            </a>
        <?php endif; ?>
    </div>
</form>

<?php if ($code !== ''): ?>
    <?php if ($certData !== null):
        $holderName = (string)($certData['user_name'] ?? $certData['holder_name'] ?? ($currentUser['name'] ?? 'Apprenant Certifié'));
        $holderCompany = (string)($certData['company'] ?? ($currentUser['company'] ?? ''));
        $courseTitle = (string)($certData['course_title'] ?? $currentCourse['title'] ?? 'Formation Certifiante LightPath');
        $submittedAt = (string)($certData['submitted_at'] ?? $certData['issued_at'] ?? date('Y-m-d'));
        $scorePct = (int)($certData['percent'] ?? $certData['percentage'] ?? 0);
        $mention = (string)($certData['mention'] ?? ($scorePct >= 75 ? 'Certifié LightPath' : 'Attestation de suivi'));
        $isPassed = !empty($certData['passed']);
    ?>
        <?php if ($isPassed): ?>
            <div class="lms-card lms-card--verify-valid">
                <div class="d-flex justify-between items-start flex-wrap gap-1-5">
                    <div class="verify-main-col">
                        <span class="badge-completed badge-lg">
                            ✔ CERTIFICAT AUTHENTIQUE & VÉRIFIÉ PAR LIGHTPATH SARL
                        </span>
                        <h2 class="hero-title-md mt-sm mb-xxs">
                            Titulaire : <?= Security::e($holderName) ?>
                            <?php if ($holderCompany !== ''): ?>
                                <span class="text-md text-secondary font-medium">(<?= Security::e($holderCompany) ?>)</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-secondary mb-1 text-md">
                            Formation : <strong><?= Security::e($courseTitle) ?></strong>
                        </p>

                        <div class="kpi-grid mt-1">
                            <div class="kpi-card">
                                <div class="kpi-label">Code Certificat</div>
                                <div class="kpi-value tabular-nums text-base text-mono text-cyan">
                                    <?= Security::e($code) ?>
                                </div>
                                <div class="kpi-sub">Référence unique infalsifiable</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-label">Score Sommatif</div>
                                <div class="kpi-value tabular-nums text-emerald-bright text-hero-sm">
                                    <?= $scorePct ?>%
                                </div>
                                <div class="kpi-sub"><?= Security::e($mention) ?></div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-label">Date d'Obtention</div>
                                <div class="kpi-value tabular-nums text-lg">
                                    <?= Security::e(substr($submittedAt, 0, 16)) ?>
                                </div>
                                <div class="kpi-sub">Formateur : <?= Security::e($appConfig['trainer_name'] ?? 'Jean-Marie Renouard') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="qr-verify-box">
                        <?= Security::renderVerificationQrSvg($verifyUrl, $code) ?>
                        <div class="text-xxs text-muted mt-xs tabular-nums">
                            Empreinte QR autonome
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="lms-card lms-card--verify-partial">
                <div class="d-flex justify-between items-start flex-wrap gap-1-5">
                    <div class="verify-main-col">
                        <span class="badge-code badge-amber-soft badge-lg">
                            ⚠️ ATTESTATION D'ÉVALUATION SOMMATIVE — SEUIL NON ATTEINT
                        </span>
                        <h2 class="hero-title-md mt-sm mb-xxs">
                            Participant : <?= Security::e($holderName) ?>
                            <?php if ($holderCompany !== ''): ?>
                                <span class="text-md text-secondary font-medium">(<?= Security::e($holderCompany) ?>)</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-secondary mb-xs text-md">
                            Formation : <strong><?= Security::e($courseTitle) ?></strong>
                        </p>
                        <div class="verify-notice-amber">
                            Notice d'évaluation : Le score obtenu (<strong><?= $scorePct ?>%</strong>) est inférieur au seuil de certification requis de <strong>75%</strong>. Ce document constitue une attestation de passage et de suivi de formation sans délivrance du certificat officiel.
                        </div>

                        <div class="kpi-grid mt-1">
                            <div class="kpi-card">
                                <div class="kpi-label">Référence Attestation</div>
                                <div class="kpi-value tabular-nums text-base text-mono text-cyan">
                                    <?= Security::e($code) ?>
                                </div>
                                <div class="kpi-sub">Identifiant d'épreuve enregistré</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-label">Score Obtenu</div>
                                <div class="kpi-value tabular-nums text-amber-bright text-hero-sm">
                                    <?= $scorePct ?>%
                                </div>
                                <div class="kpi-sub">Seuil de certification : 75%</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-label">Date de l'Épreuve</div>
                                <div class="kpi-value tabular-nums text-lg">
                                    <?= Security::e(substr($submittedAt, 0, 16)) ?>
                                </div>
                                <div class="kpi-sub">Organisme : LightPath SARL</div>
                            </div>
                        </div>
                    </div>

                    <div class="qr-verify-box">
                        <?= Security::renderVerificationQrSvg($verifyUrl, $code) ?>
                        <div class="text-xxs text-muted mt-xs tabular-nums">
                            Empreinte QR autonome
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="lms-card lms-card--verify-invalid">
            <span class="badge-code badge-rose-soft">
                ✘ CODE DE CERTIFICAT INTROUVABLE
            </span>
            <h2 class="section-heading-md mt-xs mb-xxs">
                Aucun certificat enregistré ne correspond au code <code><?= Security::e($code) ?></code>
            </h2>
            <p class="text-secondary mb-0 text-sm">
                Vérifiez l'orthographe exacte du code (ex: <code><?= Security::e($verifyFormatExample) ?></code>) ou contactez <strong><?= Security::e($appConfig['contact_email'] ?? 'contact@lightpath.fr') ?></strong>.
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
