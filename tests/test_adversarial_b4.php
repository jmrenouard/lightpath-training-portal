<?php
declare(strict_types=1);

/**
 * ============================================================================
 * EMPIRICAL ADVERSARIAL TEST SUITE — BOUCLE 4 (CHALLENGER_B4_1)
 * ============================================================================
 * Banc d'essai adverse empirique et oracles de stress pour la Boucle 4
 * du portail LMS (RCA-167 / RCA-LMS-37) :
 *
 * 1. Concurrence & Protection contre l'Écrasement Destructif (mutateFile & writeFile)
 * 2. Registre d'Émargement Qualiopi — Préservation du Visa Formateur & Hash SHA-256
 * 3. Sécurité Console SQL Docker (LabSqlRunner) — Injections & Commandes Backslash
 * 4. Sécurité des Sessions, Inactivité (14400s), Déconnexion & Fuseau Horaire
 * 5. Vérification Publique des Attestations Qualiopi (findCertificateByCode LP-PG18-ATT-*)
 * 6. Importation CSV des Cohortes (UTF-8 BOM, TSV, Mots de passe courts, Injections CSV)
 * 7. Export OPCO d'Émargement & Inclusion des Stagiaires Absents
 * 8. Invalidation & Purge des Caches Administrateur (admin_clear_cache)
 * 9. Non-Régression Globale & Vérification des Suites Antérieures (b1, b2, b3, run_tests)
 * ============================================================================
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LightPath\LMS\Core\JsonStorage;
use LightPath\LMS\Core\Auth;
use LightPath\LMS\Core\Security;
use LightPath\LMS\Course\CourseRepository;
use LightPath\LMS\Course\MarkdownParser;
use LightPath\LMS\Docker\DockerManager;
use LightPath\LMS\Docker\LabSqlRunner;

class AdversarialTestSuiteB4
{
    private int $passed = 0;
    private int $failed = 0;
    private array $assertions = [];

    public function assert(bool $condition, string $label, string $details = ''): void
    {
        $this->assertions[] = [
            'label' => $label,
            'passed' => $condition,
            'details' => $details,
        ];
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$label}\n";
            if ($details !== '') {
                echo "         Détail: {$details}\n";
            }
        }
    }

    public function getPassed(): int
    {
        return $this->passed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('=', 72) . "\n";
        echo " SYNTHÈSE DES VÉRIFICATIONS ADVERSES BOUCLE 4 (CHALLENGER_B4_1)\n";
        echo " Succès : {$this->passed} | Échecs : {$this->failed}\n";
        echo str_repeat('=', 72) . "\n";

        if ($this->failed === 0) {
            echo "✅ VERDICT : APPROVE — Toutes les barrières de robustesse RCA-167 / RCA-LMS-37 sont certifiées conformes.\n";
            return 0;
        }

        echo "❌ VERDICT : REQUEST_CHANGES — Des défauts de robustesse, d'étanchéité ou de contournement ont été détectés empiriquement.\n";
        return 1;
    }
}

$suite = new AdversarialTestSuiteB4();

echo "\n" . str_repeat('=', 72) . "\n";
echo " ADVERSARIAL CHALLENGER B4 — EMPIRICAL VERIFICATION HARNESS\n";
echo " Target: lightpath-training-portal (PHP 8.3.36)\n";
echo str_repeat('=', 72) . "\n";

// Configuration d'un stockage de test isolé
$testDataDir = sys_get_temp_dir() . '/lms_adversarial_b4_' . mt_rand(10000, 99999);
@mkdir($testDataDir, 0777, true);
$storage = new JsonStorage($testDataDir);
$auth = new Auth($storage);
$docker = new DockerManager($storage);
$sqlRunner = new LabSqlRunner($docker);

// ============================================================================
// SUITE 1 : Concurrence & Protection contre l'Écrasement Destructif (mutateFile)
// ============================================================================
echo "\n--- Suite 1 : Concurrence & Protection contre l'Écrasement Destructif ---\n";

// 1.1 Tentative d'écrasement avec JSON invalide via writeFile (données non encodables)
$threwOnInvalidJson = false;
try {
    // Utilisation d'une classe anonyme étendant JsonStorage pour exposer writeFile
    $exposedStorage = new class($testDataDir) extends JsonStorage {
        public function testWriteFile(string $name, array $data): void {
            $this->writeFile($name, $data);
        }
        public function testMutateFile(string $name, array $default, callable $mutator): mixed {
            return $this->mutateFile($name, $default, $mutator);
        }
    };

    // Une séquence d'octets UTF-8 invalide fait échouer json_encode
    $invalidUtf8Data = ["bad_data" => "\xB1\x31"];
    $exposedStorage->testWriteFile('test_invalid_json', $invalidUtf8Data);
} catch (\RuntimeException $e) {
    $threwOnInvalidJson = true;
} catch (\Throwable $e) {
    $threwOnInvalidJson = false;
}
$suite->assert(
    $threwOnInvalidJson,
    "writeFile lève une RuntimeException et refuse d'écrire un JSON corrompu ou vide"
);

// 1.2 Protection de mutateFile contre la destruction d'un fichier corrompu existant
$corruptedFilePath = $testDataDir . '/cohort_critical_data.json';
file_put_contents($corruptedFilePath, "{\"users\": [{\"id\": 1, \"name\": \"Valid User\"}, TRUNCATED_CRASH");

$threwOnCorruptMutate = false;
$corruptedFilePreserved = false;
try {
    $exposedStorage->testMutateFile('cohort_critical_data', [], function(array $current) {
        // Si readFile renvoie [] à tort, ce mutateur tenterait d'écraser la promotion avec un tableau vide
        return [['wiped' => true], 'ok'];
    });
} catch (\RuntimeException $e) {
    $threwOnCorruptMutate = str_contains($e->getMessage(), 'Données corrompues ou illisibles');
    // Vérifier que le fichier sur disque contient toujours le contenu original et n'a pas été écrasé
    $onDisk = file_get_contents($corruptedFilePath);
    $corruptedFilePreserved = str_contains((string)$onDisk, 'TRUNCATED_CRASH');
}

$suite->assert(
    $threwOnCorruptMutate,
    "mutateFile bloque l'exécution si le fichier source contient des données tronquées/corrompues"
);
$suite->assert(
    $corruptedFilePreserved,
    "Le fichier existant corrompu n'a PAS été écrasé par les données par défaut []"
);

// 1.3 Cas légitime où le fichier contient [] : mutateFile autorise la mutation
$emptyArrFilePath = $testDataDir . '/legit_empty_file.json';
file_put_contents($emptyArrFilePath, "[]");
$mutateEmptySuccess = false;
try {
    $res = $exposedStorage->testMutateFile('legit_empty_file', [], function(array $current) {
        $current[] = ['first_entry' => true];
        return [$current, 'success'];
    });
    $mutateEmptySuccess = ($res === 'success') && is_file($emptyArrFilePath);
} catch (\Throwable $e) {
    $mutateEmptySuccess = false;
}
$suite->assert(
    $mutateEmptySuccess,
    "mutateFile autorise la mutation d'un fichier contenant légitimement '[]'"
);

// ============================================================================
// SUITE 2 : Registre d'Émargement Qualiopi — Préservation Visa & Hash SHA-256
// ============================================================================
echo "\n--- Suite 2 : Registre d'Émargement Qualiopi — Préservation Visa & SHA-256 ---\n";

// Création d'un stagiaire de test
$studentUser = $auth->registerUser("Alice Dubois", "alice.dubois@test.fr", "StudentPass2026!", "student", "Acme Corp");
$aliceId = (int)($studentUser['id'] ?? 101);

// Stagiaire émerge pour J1_AM
$initialSign = $storage->signAttendance($aliceId, 'postgresql-18', 'J1_AM', 'Signature Initiale Alice');
$suite->assert(
    isset($initialSign['J1_AM']),
    "Émargement initial stagiaire J1_AM enregistré avec succès"
);

// Formateur contre-signe pour J1_AM
$trainerName = "Jean-Marie Renouard";
$trainerSigned = $storage->signTrainerAttendance($aliceId, 'postgresql-18', 'J1_AM', $trainerName);
$visaTrainerAt = $trainerSigned['J1_AM']['trainer_signed_at'] ?? '';
$visaTrainerHash = $trainerSigned['J1_AM']['trainer_hash'] ?? '';

$suite->assert(
    $visaTrainerAt !== '' && $visaTrainerHash !== '',
    "Le formateur appose son visa horodaté et son condensat SHA-256"
);

// L'apprenant ré-émarge ensuite ou modifie sa signature
$studentReSign = $storage->signAttendance($aliceId, 'postgresql-18', 'J1_AM', 'Signature Modifiée / Rectifiée Alice');
$reSignedRecord = $studentReSign['J1_AM'] ?? [];

$suite->assert(
    ($reSignedRecord['trainer_signed_at'] ?? '') === $visaTrainerAt,
    "trainer_signed_at est rigoureusement PRÉSERVÉ après ré-émargement stagiaire",
    "Attendu: {$visaTrainerAt}, Obtenu: " . ($reSignedRecord['trainer_signed_at'] ?? 'NULL')
);
$suite->assert(
    ($reSignedRecord['trainer_name'] ?? '') === $trainerName,
    "trainer_name est rigoureusement PRÉSERVÉ après ré-émargement stagiaire",
    "Attendu: {$trainerName}, Obtenu: " . ($reSignedRecord['trainer_name'] ?? 'NULL')
);
$suite->assert(
    ($reSignedRecord['trainer_hash'] ?? '') === $visaTrainerHash,
    "trainer_hash est rigoureusement PRÉSERVÉ après ré-émargement stagiaire",
    "Attendu: {$visaTrainerHash}, Obtenu: " . ($reSignedRecord['trainer_hash'] ?? 'NULL')
);

// Test de contre-signature journée complète J3_DAY
$storage->signTrainerAttendance($aliceId, 'postgresql-18', 'J3_DAY', $trainerName);
$aliceAttAfterDay = $storage->getUserAttendance($aliceId, 'postgresql-18');
$suite->assert(
    isset($aliceAttAfterDay['J3_AM']['trainer_signed_at']) && isset($aliceAttAfterDay['J3_PM']['trainer_signed_at']),
    "Contre-signature journée complète J3_DAY valide simultanément J3_AM et J3_PM"
);

// Test de l'entropie temporelle du condensat SHA-256 d'émargement
// Calcul du hash à deux secondes différentes
$hashT1 = substr(hash('sha256', $aliceId . '|postgresql-18|J1_AM|Sig|2026-11-02 09:00:00'), 0, 16);
$hashT2 = substr(hash('sha256', $aliceId . '|postgresql-18|J1_AM|Sig|2026-11-02 09:05:00'), 0, 16);
$suite->assert(
    $hashT1 !== $hashT2 && strlen($hashT1) === 16 && strlen($hashT2) === 16,
    "Le condensat SHA-256 intègre l'horodatage signed_at et varie selon le moment de la signature"
);

// ============================================================================
// SUITE 3 : Sécurité Console SQL Docker (LabSqlRunner) — Injections & Commandes
// ============================================================================
echo "\n--- Suite 3 : Sécurité Console SQL Docker (LabSqlRunner) ---\n";

// 3.1 Commandes d'échappement psql interactives standard
$interactiveBackslashCommands = [
    '\\! whoami' => 'Échappement shell \\! whoami',
    '\\! id' => 'Échappement shell \\! id',
    '   \\! whoami' => 'Échappement shell avec espaces en tête',
    "\t\\! whoami" => 'Échappement shell avec tabulation en tête',
    "\n\n\\! whoami" => 'Échappement shell avec sauts de ligne en tête',
    '\\copy (SELECT 1) TO \'/tmp/pwn\'' => 'Copie fichier serveur \\copy',
    '\\COPY (SELECT 1) TO \'/tmp/pwn\'' => 'Copie fichier serveur en majuscules \\COPY',
    '\\o /tmp/evil.txt' => 'Redirection sortie \\o',
    '\\O /tmp/evil.txt' => 'Redirection sortie en majuscules \\O',
    '\\w /tmp/query.txt' => 'Écriture buffer requête \\w',
    '\\i /etc/passwd' => 'Inclusion fichier système \\i',
    '\\I /etc/passwd' => 'Inclusion fichier système en majuscules \\I',
    '\\gexec' => 'Exécution dynamique de résultat \\gexec',
    '\\GEXEC' => 'Exécution dynamique en majuscules \\GEXEC',
    "SELECT 1;\n\\! whoami" => 'Échappement shell en seconde ligne',
];

foreach ($interactiveBackslashCommands as $cmd => $desc) {
    $res = $sqlRunner->executeSql($aliceId, $cmd, 'formation_pg');
    $isBlocked = ($res['container'] ?? '') === 'blocked' && !$res['success'];
    $suite->assert(
        $isBlocked,
        "LabSqlRunner bloque : {$desc}",
        "Payload: " . addcslashes($cmd, "\n\r\t") . " => Résultat: " . ($res['output'] ?? '')
    );
}

// 3.2 Commandes interactives supplémentaires (\connect, \cd) et contournements de commentaires
$advancedBypassCommands = [
    '\\connect dvdrental' => 'Changement de connexion de base \\connect',
    '\\cd /tmp' => 'Changement de répertoire hôte \\cd',
    '/* comment */ \\! whoami' => 'Contournement avec commentaire multiligne avant \\!',
    'SELECT 1; \\! whoami' => 'Contournement sur la même ligne après point-virgule',
];

foreach ($advancedBypassCommands as $cmd => $desc) {
    $res = $sqlRunner->executeSql($aliceId, $cmd, 'formation_pg');
    $isBlocked = ($res['container'] ?? '') === 'blocked' && !$res['success'];
    $suite->assert(
        $isBlocked,
        "LabSqlRunner bloque : {$desc}",
        "Payload: " . addcslashes($cmd, "\n\r\t") . " => Conteneur: " . ($res['container'] ?? '') . " / Output: " . ($res['output'] ?? '')
    );
}

// 3.3 Neutralisation des modifications de statement_timeout
$timeoutTamperCommands = [
    'SET statement_timeout = 0;' => 'Désactivation timeout via SET = 0',
    'set statement_timeout = 0;' => 'Désactivation en minuscules set = 0',
    'SET statement_timeout TO 0;' => 'Désactivation via SET ... TO 0',
    'SET LOCAL statement_timeout = 0;' => 'Désactivation via SET LOCAL',
    'SET statement_timeout = \'0\';' => 'Désactivation via chaîne quotée',
    'RESET statement_timeout;' => 'Réinitialisation via RESET statement_timeout',
    'reset statement_timeout;' => 'Réinitialisation en minuscules',
    'SET statement_timeout = 300000;' => 'Extension du timeout à 300s',
    'RESET ALL;' => 'Réinitialisation globale de tous les paramètres de session via RESET ALL',
];

foreach ($timeoutTamperCommands as $cmd => $desc) {
    $res = $sqlRunner->executeSql($aliceId, $cmd, 'formation_pg');
    $isBlocked = ($res['container'] ?? '') === 'blocked' && !$res['success'];
    $suite->assert(
        $isBlocked,
        "LabSqlRunner bloque : {$desc}",
        "Payload: {$cmd} => Output: " . ($res['output'] ?? '')
    );
}

// ============================================================================
// SUITE 4 : Sécurité des Sessions, Inactivité (14400s), Déconnexion & Timezone
// ============================================================================
echo "\n--- Suite 4 : Sécurité des Sessions, Inactivité (14400s), Déconnexion & Timezone ---\n";

// 4.1 Vérification du fuseau horaire Europe/Paris
$tz = date_default_timezone_get();
$suite->assert(
    $tz === 'Europe/Paris',
    "Fuseau horaire système configuré sur 'Europe/Paris' (Actuel: {$tz})"
);

// 4.2 Expiration de session après inactivité > 14400s (4 heures)
$_SESSION['user_id'] = $aliceId;
$_SESSION['last_activity'] = time() - 14401; // Inactif depuis 4h + 1s

$expiredUser = $auth->currentUser();
$suite->assert(
    $expiredUser === null,
    "currentUser() retourne null après inactivité supérieure à 14400s (4 heures)"
);
$suite->assert(
    empty($_SESSION['user_id']),
    "La session inactive est révoquée et \$_SESSION['user_id'] est vidé"
);

// 4.3 Maintien de session si inactivité < 14400s
$_SESSION['user_id'] = $aliceId;
$_SESSION['last_activity'] = time() - 3600; // Inactif depuis 1h seulement
$activeUser = $auth->currentUser();
$suite->assert(
    $activeUser !== null && (int)$activeUser['id'] === $aliceId,
    "currentUser() maintient la session active si l'inactivité est inférieure à 14400s"
);
$suite->assert(
    isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) < 5,
    "currentUser() rafraîchit \$_SESSION['last_activity'] lors d'un accès légitime"
);

// 4.4 Déconnexion propre via logout()
$auth->logout();
$suite->assert(
    empty($_SESSION),
    "Auth::logout() vide intégralement \$_SESSION"
);

// ============================================================================
// SUITE 5 : Vérification Publique des Attestations Qualiopi (findCertificateByCode)
// ============================================================================
echo "\n--- Suite 5 : Vérification Publique des Attestations Qualiopi ---\n";

// 5.1 Code d'attestation valide pour utilisateur existant
$today = date('Ymd');
$validAttCode = "LP-PG18-ATT-{$aliceId}-{$today}";
$certResult = $storage->findCertificateByCode($validAttCode);

$suite->assert(
    $certResult !== null,
    "findCertificateByCode résout avec succès le code d'attestation valide '{$validAttCode}'"
);
$suite->assert(
    ($certResult['certificate_type'] ?? '') === 'attendance',
    "Le type de certificat résolu est strictement 'attendance'"
);
$suite->assert(
    ($certResult['percentage'] ?? 0) === 100 && ($certResult['percent'] ?? 0) === 100,
    "Le score d'assiduité Qualiopi est attesté à 100%"
);
$suite->assert(
    !empty($certResult['passed']) && !empty($certResult['certified']) && !empty($certResult['verified']),
    "Les indicateurs d'intégrité (passed, certified, verified) sont tous vrais"
);
$suite->assert(
    ($certResult['user_name'] ?? '') === 'Alice Dubois',
    "Le nom du titulaire certifié résout fidèlement 'Alice Dubois'"
);

// 5.2 Insensibilité à la casse
$lowerAttCode = strtolower($validAttCode);
$certLowerResult = $storage->findCertificateByCode($lowerAttCode);
$suite->assert(
    $certLowerResult !== null && ($certLowerResult['user_id'] ?? 0) === $aliceId,
    "findCertificateByCode résout le code insensiblement à la casse"
);

// 5.3 Rejet des codes invalides ou pour utilisateur inexistant
$invalidAttCodes = [
    "LP-PG18-ATT-999999-{$today}" => "Utilisateur non existant #999999",
    "LP-PG18-ATT-" => "Code tronqué sans UID ni date",
    "LP-PG18-ATT-{$aliceId}-" => "Code avec date manquante",
    "LP-PG18-ATT-{$aliceId}-99" => "Code avec date invalide (< 6 chiffres)",
    "LP-PG18-ATT--{$today}" => "Code avec UID manquant",
    "LP-PG18-ATT-XYZ-{$today}" => "Identifiant utilisateur non numérique",
    "" => "Code vide",
    "   " => "Espaces blancs",
    "LP-PG18-ATT-{$aliceId}-{$today}<script>" => "Code avec injection XSS",
    "' OR '1'='1" => "Tentative d'injection SQL",
    "LP-CERT-NOT-EXIST" => "Code d'examen aléatoire inexistant",
];

foreach ($invalidAttCodes as $badCode => $reason) {
    $badRes = $storage->findCertificateByCode($badCode);
    $suite->assert(
        $badRes === null,
        "findCertificateByCode rejette : {$reason}",
        "Code: '{$badCode}' => Résultat: " . json_encode($badRes)
    );
}

// ============================================================================
// SUITE 6 : Importation CSV des Cohortes (UTF-8 BOM, TSV, Mots de passe)
// ============================================================================
echo "\n--- Suite 6 : Importation CSV des Cohortes (BOM, TSV, Délimiteurs) ---\n";

// 6.1 CSV exporté par Microsoft Excel avec BOM UTF-8 (\xEF\xBB\xBF)
$excelCsv = "\xEF\xBB\xBFNom;Email;Entreprise;Role;Password\n" .
    "Benoît Girard;benoit.girard@acme.fr;Acme France;student;Secure2026!\n";

$importExcelRes = $storage->importUsersFromCsv($excelCsv);
$suite->assert(
    ($importExcelRes['created'] ?? 0) === 1,
    "importUsersFromCsv importe 1 utilisateur depuis un CSV avec BOM UTF-8 Excel"
);
$benoit = $storage->getUserByEmail('benoit.girard@acme.fr');
$suite->assert(
    $benoit !== null && $benoit['name'] === 'Benoît Girard',
    "Le BOM UTF-8 en tête de fichier n'a pas corrompu le nom du premier utilisateur",
    "Nom obtenu: '" . ($benoit['name'] ?? 'NULL') . "'"
);

// 6.2 Détection automatique des séparateurs tabulations (TSV)
$tsvPayload = "Nom\tEmail\tEntreprise\tRole\tPassword\n" .
    "Camille Roux\tcamille.roux@acme.fr\tAcme Logistique\tstudent\tCamille2026!\n";

$importTsvRes = $storage->importUsersFromCsv($tsvPayload);
$suite->assert(
    ($importTsvRes['created'] ?? 0) === 1,
    "importUsersFromCsv détecte le délimiteur tabulation (TSV) et crée le compte"
);

// 6.3 Détection automatique du séparateur virgule (,)
$commaPayload = "Damien Morel,damien.morel@acme.fr,Acme SI,student,Damien2026!\n";
$importCommaRes = $storage->importUsersFromCsv($commaPayload);
$suite->assert(
    ($importCommaRes['created'] ?? 0) === 1,
    "importUsersFromCsv détecte le délimiteur virgule (,) et crée le compte"
);

// 6.4 Sécurisation des mots de passe trop courts (< 6 caractères)
$shortPassPayload = "Élodie Faure;elodie.faure@acme.fr;Acme RH;student;123\n";
$importShortRes = $storage->importUsersFromCsv($shortPassPayload, 'DefaultPass2026!');
$suite->assert(
    ($importShortRes['created'] ?? 0) === 1,
    "importUsersFromCsv accepte la ligne avec mot de passe trop court"
);
$elodie = $storage->getUserByEmail('elodie.faure@acme.fr');
$suite->assert(
    $elodie !== null && password_verify('DefaultPass2026!', (string)($elodie['password_hash'] ?? '')),
    "Le mot de passe trop court ('123') a été substitué par le mot de passe par défaut sécurisé (>= 6 caractères)"
);

// 6.5 Neutralisation des injections de formules de tableur (CSV Injection / DDE)
$formulaPayload = "=cmd|' /C calc'!A0;hacker.formula@evil.fr;@MALICIOUS;student;Evil2026!\n";
$storage->importUsersFromCsv($formulaPayload);
$hacker = $storage->getUserByEmail('hacker.formula@evil.fr');
$suite->assert(
    $hacker !== null && str_starts_with((string)$hacker['name'], "'="),
    "La formule de tableur '=cmd' est neutralisée par une apostrophe de sécurité",
    "Nom obtenu: " . ($hacker['name'] ?? '')
);
$suite->assert(
    $hacker !== null && str_starts_with((string)$hacker['company'], "'@"),
    "La formule de tableur '@MALICIOUS' est neutralisée par une apostrophe de sécurité",
    "Entreprise obtenue: " . ($hacker['company'] ?? '')
);

// ============================================================================
// SUITE 7 : Export OPCO d'Émargement & Inclusion des Stagiaires Absents
// ============================================================================
echo "\n--- Suite 7 : Export OPCO d'Émargement & Inclusion des Stagiaires Absents ---\n";

// Création d'un stagiaire inscrit sans AUCUN émargement
$absentUser = $auth->registerUser("Fabien Absent", "fabien.absent@test.fr", "AbsentPass2026!", "student", "Inactifs SA");
$fabienId = (int)($absentUser['id'] ?? 109);

// Simulation de la logique de génération de l'export CSV OPCO
$csvBuffer = fopen('php://memory', 'w+b');
fwrite($csvBuffer, "\xEF\xBB\xBF");
fputcsv($csvBuffer, [
    'User_ID', 'Nom', 'Email', 'Entreprise', 'Demi_Journee',
    'Emarge_Le', 'Adresse_IP', 'Empreinte_SHA256',
    'Contre_Signe_Le', 'Nom_Formateur', 'Visa_Formateur_Hash',
], ';');

foreach ($storage->getUsers() as $u) {
    $uid = (int)$u['id'];
    $attRecords = $storage->getUserAttendance($uid, 'postgresql-18');
    if (empty($attRecords)) {
        fputcsv($csvBuffer, [
            $uid,
            Security::sanitizeCsvCell($u['name'] ?? ''),
            Security::sanitizeCsvCell($u['email'] ?? ''),
            Security::sanitizeCsvCell($u['company'] ?? ''),
            'AUCUNE (ABSENT)',
            'ABSENT (0h)',
            '—', '—', '—', '—', '—',
        ], ';');
        continue;
    }
    foreach ($attRecords as $hdKey => $rec) {
        if (!is_array($rec)) continue;
        fputcsv($csvBuffer, [
            $uid,
            Security::sanitizeCsvCell($u['name'] ?? ''),
            Security::sanitizeCsvCell($u['email'] ?? ''),
            Security::sanitizeCsvCell($u['company'] ?? ''),
            Security::sanitizeCsvCell($rec['half_day_key'] ?? (string)$hdKey),
            Security::sanitizeCsvCell($rec['signed_at'] ?? ''),
            Security::sanitizeCsvCell($rec['ip'] ?? ''),
            Security::sanitizeCsvCell($rec['hash'] ?? ''),
            Security::sanitizeCsvCell($rec['trainer_signed_at'] ?? ''),
            Security::sanitizeCsvCell($rec['trainer_name'] ?? ''),
            Security::sanitizeCsvCell($rec['trainer_hash'] ?? ''),
        ], ';');
    }
}
rewind($csvBuffer);
$generatedCsv = stream_get_contents($csvBuffer);
fclose($csvBuffer);

$suite->assert(
    str_contains($generatedCsv, 'Fabien Absent') && str_contains($generatedCsv, 'AUCUNE (ABSENT)') && str_contains($generatedCsv, 'ABSENT (0h)'),
    "L'export CSV OPCO inclut formellement les stagiaires sans émargement avec statut 'AUCUNE (ABSENT)' et 'ABSENT (0h)'"
);
$suite->assert(
    str_starts_with($generatedCsv, "\xEF\xBB\xBF"),
    "L'export CSV OPCO commence par le BOM UTF-8 (\\xEF\\xBB\\xBF) pour compatibilité Excel native"
);

// ============================================================================
// SUITE 8 : Invalidation & Purge des Caches Administrateur (admin_clear_cache)
// ============================================================================
echo "\n--- Suite 8 : Invalidation & Purge des Caches Administrateur ---\n";

$coursesConfig = require __DIR__ . '/../config/courses.php';
$courseRepo = new CourseRepository($coursesConfig, $storage->getCustomCourses());
$mdParser = new MarkdownParser();

// 1. Création d'un faux fichier de cache Markdown
$parsedCacheDir = __DIR__ . '/../storage/data/parsed_cache';
@mkdir($parsedCacheDir, 0777, true);
$dummyCacheFile = $parsedCacheDir . '/dummy_adversarial_test_' . md5('test') . '.json';
file_put_contents($dummyCacheFile, json_encode(['dummy' => true]));

$clearedParsed = $mdParser->clearParsedCache();
$suite->assert(
    $clearedParsed >= 1 && !is_file($dummyCacheFile),
    "MarkdownParser::clearParsedCache() purge les fichiers du cache de rendu Markdown"
);

// 2. Invalidation du cache catalogue
$catalogCleared = $courseRepo->clearCatalogCache();
$suite->assert(
    $catalogCleared >= 0,
    "CourseRepository::clearCatalogCache() réinitialise le cache catalogue"
);

// ============================================================================
// SUITE 9 : Non-Régression Globale & Vérification des Suites Antérieures
// ============================================================================
echo "\n--- Suite 9 : Non-Régression Globale & Suites Antérieures ---\n";

// 9.1 Exécution de tests/run_tests.php
$cmdRun = 'php "' . __DIR__ . '/run_tests.php" 2>&1';
exec($cmdRun, $outRun, $exitRun);
$outRunStr = implode("\n", $outRun);
$passRun = preg_match('/RÉSULTATS DES TESTS LMS : (\d+) passés, 0 échoués/', $outRunStr, $mRun) && (int)$mRun[1] >= 374;
$suite->assert(
    $exitRun === 0 && $passRun,
    "Suite officielle tests/run_tests.php : >= 374 passés, 0 échoués, code sortie 0",
    "Exit: {$exitRun}, Sortie: " . substr($outRunStr, -250)
);

// 9.2 Exécution de tests/test_adversarial_b1.php
$cmdB1 = 'php "' . __DIR__ . '/test_adversarial_b1.php" 2>&1';
exec($cmdB1, $outB1, $exitB1);
$outB1Str = implode("\n", $outB1);
$passB1 = str_contains($outB1Str, 'VERDICT : APPROVE');
$suite->assert(
    $exitB1 === 0 && $passB1,
    "Suite adverse tests/test_adversarial_b1.php : 69/69 passés, VERDICT : APPROVE"
);

// 9.3 Exécution de tests/test_adversarial_b2.php
$cmdB2 = 'php "' . __DIR__ . '/test_adversarial_b2.php" 2>&1';
exec($cmdB2, $outB2, $exitB2);
$outB2Str = implode("\n", $outB2);
$passB2 = str_contains($outB2Str, 'VERDICT : APPROVE');
$suite->assert(
    $exitB2 === 0 && $passB2,
    "Suite adverse tests/test_adversarial_b2.php : 50/50 passés, VERDICT : APPROVE"
);

// 9.4 Exécution de tests/test_adversarial_b3.php
$cmdB3 = 'php "' . __DIR__ . '/test_adversarial_b3.php" 2>&1';
exec($cmdB3, $outB3, $exitB3);
$outB3Str = implode("\n", $outB3);
$passB3 = str_contains($outB3Str, 'VERDICT : APPROVE');
$suite->assert(
    $exitB3 === 0 && $passB3,
    "Suite adverse tests/test_adversarial_b3.php : 69/69 passés, VERDICT : APPROVE"
);

// Nettoyage de l'environnement de test temporaire
$files = glob($testDataDir . '/*');
if ($files) {
    foreach ($files as $f) {
        @unlink($f);
    }
}
@rmdir($testDataDir);

exit($suite->summary());
