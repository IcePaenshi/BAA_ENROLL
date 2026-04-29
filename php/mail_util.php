<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/** Normalize Gmail app password / SMTP secret pasted from UI or editors */
function baa_sanitize_smtp_password(string $p): string
{
    $p = preg_replace('/^\xEF\xBB\xBF/', '', $p);
    $p = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $p);
    $p = preg_replace('/\s+/u', '', $p);
    return $p;
}

function baa_load_mail_config(): array
{
    $config = [
        'smtp_host'   => 'smtp.gmail.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => 'baaenroll@gmail.com',
        'smtp_pass'   => '',
        'from_email'  => 'baaenroll@gmail.com',
        'from_name'   => 'Baesa Adventist Academy Enrollment',
    ];
    $local = __DIR__ . '/mail_config.local.php';
    if (is_readable($local)) {
        $overrides = require $local;
        if (is_array($overrides)) {
            $config = array_merge($config, $overrides);
        }
    }
    // Gmail App Passwords: strip spaces, BOM, invisible chars (common copy/paste issues).
    if (isset($config['smtp_pass']) && is_string($config['smtp_pass'])) {
        $config['smtp_pass'] = baa_sanitize_smtp_password($config['smtp_pass']);
    }
    if (isset($config['smtp_user']) && is_string($config['smtp_user'])) {
        $config['smtp_user'] = trim($config['smtp_user']);
        if (strpos($config['smtp_user'], '@') !== false) {
            [$local, $dom] = explode('@', $config['smtp_user'], 2);
            $config['smtp_user'] = strtolower($local) . '@' . strtolower($dom);
        }
    }
    // Gmail SMTP: From must be the same mailbox you authenticate as (unless configured as alias in Google).
    if (!empty($config['smtp_user']) && stripos($config['smtp_host'] ?? '', 'gmail') !== false) {
        $config['from_email'] = $config['smtp_user'];
    }
    return $config;
}

function baa_portal_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = dirname(dirname(str_replace('\\', '/', $script)));
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    }
    return rtrim($scheme . '://' . $host . $basePath, '/');
}

/**
 * @return array{0:bool,1:string} [success, message]
 */
function baa_send_student_credentials_mail(
    string $toEmail,
    string $toDisplayName,
    string $username,
    string $plainPassword,
    ?string $assessmentAttachmentPath = null,
    ?string $enrollmentId = null,
    ?string $gradeLevel = null
): array
{
    $cfg = baa_load_mail_config();
    if ($cfg['smtp_pass'] === '' || $cfg['smtp_pass'] === null) {
        return [false, 'SMTP password not configured (add php/mail_config.local.php).'];
    }

    $mailRoot = __DIR__ . '/../PHPMailer/PHPMailer-master/src/';
    if (!is_readable($mailRoot . 'PHPMailer.php')) {
        return [false, 'PHPMailer library not found.'];
    }

    require_once $mailRoot . 'Exception.php';
    require_once $mailRoot . 'PHPMailer.php';
    require_once $mailRoot . 'SMTP.php';

    $loginUrl = baa_portal_base_url() . '/index.php';

    $safeName = htmlspecialchars($toDisplayName, ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePass = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $appDetailsHtml = '';
    $appDetailsText = '';
    if ($enrollmentId && $gradeLevel) {
        $dateStr = date('F j, Y');
        $appDetailsHtml = "<h3>Application Details:</h3>
<ul>
    <li><strong>Full Name:</strong> {$safeName}</li>
    <li><strong>Grade Level:</strong> " . htmlspecialchars($gradeLevel, ENT_QUOTES, 'UTF-8') . "</li>
    <li><strong>Enrollment ID:</strong> " . htmlspecialchars($enrollmentId, ENT_QUOTES, 'UTF-8') . "</li>
    <li><strong>Date Approved:</strong> {$dateStr}</li>
</ul>";

        $appDetailsText = "Application Details:\n"
            . "- Full Name: {$toDisplayName}\n"
            . "- Grade Level: {$gradeLevel}\n"
            . "- Enrollment ID: {$enrollmentId}\n"
            . "- Date Approved: {$dateStr}\n\n";
    }

    $bodyHtml = '<p>Dear ' . $safeName . ',</p>'
        . '<p>Your enrollment has been <strong>approved</strong>.</p>'
        . $appDetailsHtml
        . '<p>You can sign in to the student portal with the credentials below.</p>'
        . '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;margin:12px 0;">'
        . '<tr><td style="border:1px solid #ccc;"><strong>Username</strong></td><td style="border:1px solid #ccc;">' . $safeUser . '</td></tr>'
        . '<tr><td style="border:1px solid #ccc;"><strong>Password</strong></td><td style="border:1px solid #ccc;">' . $safePass . '</td></tr>'
        . '</table>'
        . '<p>Please change your password after your first login if prompted.</p>'
        . '<p><a href="' . $safeUrl . '">Open login page</a></p>'
        . '<p>Best regards,<br>Baesa Adventist Academy</p>';

    $bodyText = "Dear {$toDisplayName},\n\n"
        . "Your enrollment has been approved.\n\n"
        . $appDetailsText
        . "Sign in with:\n\n"
        . "Username: {$username}\n"
        . "Password: {$plainPassword}\n\n"
        . "Login: {$loginUrl}\n\n"
        . "Best regards,\nBaesa Adventist Academy\n";

    $profiles = [
        ['port' => (int) ($cfg['smtp_port'] ?? 587), 'secure' => $cfg['smtp_secure'] ?? 'tls'],
        ['port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ];
    // Avoid duplicate attempt if user already uses 465
    if ((int) ($cfg['smtp_port'] ?? 0) === 465) {
        $profiles = [['port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS]];
    }

    $lastError = '';
    foreach ($profiles as $idx => $prof) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $cfg['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['smtp_user'];
            $mail->Password = $cfg['smtp_pass'];
            $mail->SMTPSecure = $prof['secure'];
            $mail->Port = $prof['port'];
            $mail->CharSet = 'UTF-8';
            $mail->SMTPAutoTLS = true;
            $mail->Timeout = 30;
            // XAMPP / Windows: weak TLS store; relax verify so STARTTLS can complete
            $relax = $cfg['smtp_relax_ssl'] ?? true;
            if ($relax) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toDisplayName);

            $mail->isHTML(true);
            $mail->Subject = 'Your student portal login - Baesa Adventist Academy';
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;
            if ($assessmentAttachmentPath && is_readable($assessmentAttachmentPath)) {
                $mail->addAttachment($assessmentAttachmentPath, basename($assessmentAttachmentPath));
            }

            $mail->send();
            return [true, ''];
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            error_log('baa_send_student_credentials_mail (port ' . $prof['port'] . '): ' . $lastError);
        }
    }

    $hint = ' Gmail: use a 16-character App Password (Google Account → Security → 2-Step Verification → App passwords), '
        . 'not your normal Gmail password. The Google account must be exactly "' . ($cfg['smtp_user'] ?? '') . '". '
        . 'Save only the app password in php/mail_config.local.php (no quotes around it unless they are part of the password).';
    return [false, $lastError . $hint];
}

/**
 * @param string[] $reasons
 * @param string[] $missingDocs
 * @return array{0:bool,1:string}
 */
function baa_send_enrollment_rejection_mail(
    string $toEmail,
    string $toDisplayName,
    array $reasons,
    array $missingDocs,
    string $customMessage = ''
): array {
    $cfg = baa_load_mail_config();
    if ($cfg['smtp_pass'] === '' || $cfg['smtp_pass'] === null) {
        return [false, 'SMTP password not configured (add php/mail_config.local.php).'];
    }

    $mailRoot = __DIR__ . '/../PHPMailer/PHPMailer-master/src/';
    if (!is_readable($mailRoot . 'PHPMailer.php')) {
        return [false, 'PHPMailer library not found.'];
    }

    require_once $mailRoot . 'Exception.php';
    require_once $mailRoot . 'PHPMailer.php';
    require_once $mailRoot . 'SMTP.php';

    $safeName = htmlspecialchars($toDisplayName, ENT_QUOTES, 'UTF-8');
    $reasonsHtml = '';
    foreach ($reasons as $reason) {
        $reasonsHtml .= '<li>' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    $docsHtml = '';
    if (!empty($missingDocs)) {
        $docsHtml .= '<p><strong>Missing documents:</strong></p><ul>';
        foreach ($missingDocs as $doc) {
            $docsHtml .= '<li>' . htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $docsHtml .= '</ul>';
    }

    $customHtml = '';
    if ($customMessage !== '') {
        $customHtml = '<p><strong>Additional note:</strong><br>' . nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $bodyHtml = '<p>Dear ' . $safeName . ',</p>'
        . '<p>Your enrollment request was not approved at this time due to the following reason(s):</p>'
        . '<ul>' . $reasonsHtml . '</ul>'
        . $docsHtml
        . $customHtml
        . '<p>Please complete the requirements and coordinate with the registrar for re-evaluation.</p>'
        . '<p>Best regards,<br>Baesa Adventist Academy</p>';

    $bodyText = "Dear {$toDisplayName},\n\n"
        . "Your enrollment request was not approved due to:\n- " . implode("\n- ", $reasons) . "\n\n";
    if (!empty($missingDocs)) {
        $bodyText .= "Missing documents:\n- " . implode("\n- ", $missingDocs) . "\n\n";
    }
    if ($customMessage !== '') {
        $bodyText .= "Additional note:\n{$customMessage}\n\n";
    }
    $bodyText .= "Please complete the requirements and coordinate with the registrar.\n\nBest regards,\nBaesa Adventist Academy\n";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['smtp_user'];
        $mail->Password = $cfg['smtp_pass'];
        $mail->SMTPSecure = $cfg['smtp_secure'] ?? 'tls';
        $mail->Port = (int) ($cfg['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 30;
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toDisplayName);
        $mail->isHTML(true);
        $mail->Subject = 'Enrollment Application Update - Baesa Adventist Academy';
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * @return array{0:bool,1:string}
 */
function baa_send_assessment_form_mail(
    string $toEmail,
    string $toDisplayName,
    string $attachmentPath
): array {
    $cfg = baa_load_mail_config();
    if ($cfg['smtp_pass'] === '' || $cfg['smtp_pass'] === null) {
        return [false, 'SMTP password not configured (add php/mail_config.local.php).'];
    }

    $mailRoot = __DIR__ . '/../PHPMailer/PHPMailer-master/src/';
    if (!is_readable($mailRoot . 'PHPMailer.php')) {
        return [false, 'PHPMailer library not found.'];
    }
    if (!is_readable($attachmentPath)) {
        return [false, 'Assessment file not found for attachment.'];
    }

    require_once $mailRoot . 'Exception.php';
    require_once $mailRoot . 'PHPMailer.php';
    require_once $mailRoot . 'SMTP.php';

    $loginUrl = baa_portal_base_url() . '/index.php';
    $safeName = htmlspecialchars($toDisplayName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $bodyHtml = '<p>Dear ' . $safeName . ',</p>'
        . '<p>Your enrollment has been approved. Attached is your assessment form.</p>'
        . '<p>You may log in to your portal here: <a href="' . $safeUrl . '">Student Portal</a></p>'
        . '<p>Best regards,<br>Baesa Adventist Academy</p>';
    $bodyText = "Dear {$toDisplayName},\n\nYour enrollment has been approved. Your assessment form is attached.\nPortal: {$loginUrl}\n\nBest regards,\nBaesa Adventist Academy\n";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['smtp_user'];
        $mail->Password = $cfg['smtp_pass'];
        $mail->SMTPSecure = $cfg['smtp_secure'] ?? 'tls';
        $mail->Port = (int) ($cfg['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 30;
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toDisplayName);
        $mail->addAttachment($attachmentPath, basename($attachmentPath));
        $mail->isHTML(true);
        $mail->Subject = 'Assessment Form - Baesa Adventist Academy';
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}
