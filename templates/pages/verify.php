<?php
declare(strict_types=1);

use LightPath\LMS\Core\Security;

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' && !empty($userBestExam['certificate_id'])) {
    $code = (string)$userBestExam['certificate_id'];
}

$certData = ($code !== '') ? $storage->findCertificateByCode($code) : null;
$verifyUrl = rtrim((string)($appConfig['website'] ?? 'https://www.lightpath.fr'), '/') . '/index.php?page=verify&code=' . urlencode($code);
?>

<div class="hero-banner" style="padding: 1.5rem 2rem;">
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
            <span class="badge-code">REGISTRE OFFICIEL DE CERTIFICATION LIGHTPATH SARL</span>
            <span class="badge-phase">Vérification Cryptographique & Conformité Qualiopi</span>
        </div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.35rem;">🔍 Vérification d'Authenticité d'un Certificat</h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Saisissez l'identifiant unique figurant sur l'attestation ou le certificat (format <code>LP-PG18-CERT-...</code>) pour contrôler sa validité auprès de l'organisme de formation LightPath SARL.
        </p>
    </div>
</div>

<form method="GET" action="index.php" class="lms-card" style="margin-bottom: 1.75rem;">
    <input type="hidden" name="page" value="verify"/>
    <input type="hidden" name="course" value="<?= Security::e($currentCourseSlug) ?>"/>
    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <input type="text"
               name="code"
               class="form-control tabular-nums"
               style="flex: 1; min-width: 260px; font-family: var(--font-mono);"
               placeholder="Ex: LP-PG18-CERT-3-A1B2C3D4"
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
        $courseTitle = (string)($certData['course_title'] ?? $currentCourse['title'] ?? 'PostgreSQL 18 — Cursus Complet');
        $submittedAt = (string)($certData['submitted_at'] ?? $certData['issued_at'] ?? date('Y-m-d'));
        $scorePct = (int)($certData['percent'] ?? $certData['percentage'] ?? 0);
        $mention = (string)($certData['mention'] ?? ($scorePct >= 75 ? 'Certifié LightPath' : 'Attestation de suivi'));
    ?>
        <div class="lms-card" style="border-color: #10B981; background: linear-gradient(180deg, rgba(16, 185, 129, 0.08) 0%, var(--bg-surface) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1.5rem;">
                <div style="flex: 1; min-width: 280px;">
                    <span class="badge-completed" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
                        ✔ CERTIFICAT AUTHENTIQUE & VÉRIFIÉ PAR LIGHTPATH SARL
                    </span>
                    <h2 style="font-size: 1.45rem; margin: 0.75rem 0 0.25rem;">
                        Titulaire : <?= Security::e($holderName) ?>
                        <?php if ($holderCompany !== ''): ?>
                            <span style="font-size: 0.95rem; color: var(--text-secondary); font-weight: 500;">(<?= Security::e($holderCompany) ?>)</span>
                        <?php endif; ?>
                    </h2>
                    <p style="color: var(--text-secondary); margin: 0 0 1rem; font-size: 0.92rem;">
                        Formation : <strong><?= Security::e($courseTitle) ?></strong>
                    </p>

                    <div class="kpi-grid" style="margin-top: 1rem;">
                        <div class="kpi-card">
                            <div class="kpi-label">Code Certificat</div>
                            <div class="kpi-value tabular-nums" style="font-size: 1rem; font-family: var(--font-mono); color: var(--brand-cyan-light);">
                                <?= Security::e($code) ?>
                            </div>
                            <div class="kpi-sub">Référence unique infalsifiable</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Score Sommatif</div>
                            <div class="kpi-value tabular-nums" style="color: #34D399; font-size: 1.65rem;">
                                <?= $scorePct ?>%
                            </div>
                            <div class="kpi-sub"><?= Security::e($mention) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Date d'Obtention</div>
                            <div class="kpi-value tabular-nums" style="font-size: 1.15rem;">
                                <?= Security::e(substr($submittedAt, 0, 16)) ?>
                            </div>
                            <div class="kpi-sub">Formateur : <?= Security::e($appConfig['trainer_name'] ?? 'Jean-Marie Renouard') ?></div>
                        </div>
                    </div>
                </div>

                <div style="text-align: center; padding: 1rem; background: var(--bg-base); border-radius: var(--radius-md); border: 1px solid var(--border-hairline);">
                    <?= Security::renderVerificationQrSvg($verifyUrl, $code) ?>
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem;" class="tabular-nums">
                        Empreinte QR autonome
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="lms-card" style="border-color: #F43F5E; background: rgba(244, 63, 94, 0.08);">
            <span class="badge-code" style="color: #FDA4AF; border-color: rgba(244, 63, 94, 0.4);">
                ✘ CODE DE CERTIFICAT INTROUVABLE
            </span>
            <h2 style="font-size: 1.25rem; margin: 0.6rem 0 0.3rem;">
                Aucun certificat enregistré ne correspond au code <code><?= Security::e($code) ?></code>
            </h2>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.88rem;">
                Vérifiez l'orthographe exacte du code (ex: <code>LP-PG18-CERT-...</code>) ou contactez <strong><?= Security::e($appConfig['contact_email'] ?? 'contact@lightpath.fr') ?></strong>.
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
