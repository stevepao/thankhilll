<?php
/**
 * includes/mailer.php — SMTP delivery via PHPMailer (env-driven).
 *
 * Auth controllers call send_email() only; they must not embed SMTP host/user/password logic.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/db.php';

/**
 * Send a plain-text email. Returns false on misconfiguration or send failure (errors are logged only).
 */
function send_email(string $to, string $subject, string $textBody): bool
{
    loadEnv();

    $host = trim((string) ($_ENV['SMTP_HOST'] ?? ''));
    $port = (int) ($_ENV['SMTP_PORT'] ?? 587);
    $smtpUser = (string) ($_ENV['SMTP_USER'] ?? '');
    $smtpPass = (string) ($_ENV['SMTP_PASS'] ?? '');
    $fromEmail = trim((string) ($_ENV['SMTP_FROM_EMAIL'] ?? ''));
    $fromName = (string) ($_ENV['SMTP_FROM_NAME'] ?? 'Gratitude Journal');

    if ($host === '' || $fromEmail === '') {
        error_log('send_email: SMTP_HOST or SMTP_FROM_EMAIL is not configured.');
        return false;
    }

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        error_log('send_email: invalid recipient address.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port > 0 ? $port : 587;

        $useAuth = $smtpUser !== '' || $smtpPass !== '';
        $mail->SMTPAuth = $useAuth;
        if ($useAuth) {
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        }

        $secure = strtolower(trim((string) ($_ENV['SMTP_SECURE'] ?? 'tls')));
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $textBody;

        $mail->send();

        return true;
    } catch (PHPMailerException) {
        error_log('send_email failed: ' . $mail->ErrorInfo);

        return false;
    }
}
