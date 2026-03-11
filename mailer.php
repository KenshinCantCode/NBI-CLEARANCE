<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';

function smtpAuthHint(string $host, string $rawPassword): string
{
    $hostLower = strtolower($host);

    if (str_contains($hostLower, 'gmail.com')) {
        if (str_contains($rawPassword, ' ') || str_contains($rawPassword, '-')) {
            return 'Gmail app password must be 16 letters only. Remove spaces/dashes from SMTP_PASSWORD.';
        }
        return 'For Gmail, turn on 2-Step Verification and use a valid 16-character App Password.';
    }

    if (str_contains($hostLower, 'office365.com') || str_contains($hostLower, 'outlook.com')) {
        return 'For Microsoft 365/Outlook, use the full mailbox email and password (or app password if MFA requires it).';
    }

    return 'Check SMTP host, port, encryption, username, and password.';
}

function sendAppEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array
{
    $config = require __DIR__ . '/mailer_config.php';
    $driver = strtolower((string) ($config['driver'] ?? 'smtp'));

    if ($driver === 'file') {
        $logDir = (string) ($config['log_dir'] ?? (__DIR__ . '/mail_logs'));
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        $filePath = rtrim($logDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        preg_match('/href="([^"]+)"/i', $htmlBody, $match);
        $firstLink = $match[1] ?? '';

        $meta = "<!--\n";
        $meta .= "Time: {$timestamp}\n";
        $meta .= "To: {$toName} <{$toEmail}>\n";
        $meta .= "Subject: {$subject}\n";
        if ($firstLink !== '') {
            $meta .= "Primary-Link: {$firstLink}\n";
        }
        $meta .= "-->\n";

        file_put_contents($filePath, $meta . $htmlBody);

        return [
            'success' => true,
            'error' => '',
            'delivery' => 'file',
            'debug_link' => $firstLink,
            'debug_file' => 'mail_logs/' . $fileName
        ];
    }

    $requiredKeys = ['host', 'username', 'password', 'port', 'from_email'];
    foreach ($requiredKeys as $key) {
        if (empty($config[$key])) {
            return [
                'success' => false,
                'error' => "Email is not configured. Missing {$key} in mailer_config.php or environment variables.",
                'delivery' => 'smtp'
            ];
        }
    }

    $mail = new PHPMailer(true);

    try {
        $host = trim((string) $config['host']);
        $username = trim((string) $config['username']);
        $rawPassword = trim((string) $config['password']);
        $password = $rawPassword;
        $fromEmail = trim((string) $config['from_email']);

        if (str_contains(strtolower($host), 'gmail.com')) {
            $password = str_replace([' ', '-'], '', $password);
        }

        if ($fromEmail === '') {
            $fromEmail = $username;
        }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = (int) $config['port'];
        $mail->SMTPSecure = (string) $config['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;

        $mail->setFrom($fromEmail, (string) $config['from_name']);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);

        $mail->send();

        return [
            'success' => true,
            'error' => '',
            'delivery' => 'smtp',
            'debug_link' => '',
            'debug_file' => ''
        ];
    } catch (Exception $e) {
        $baseError = $mail->ErrorInfo ?: $e->getMessage();
        $errorLower = strtolower($baseError);
        $hint = '';

        if (str_contains($errorLower, 'authenticate')) {
            $hint = ' ' . smtpAuthHint((string) ($config['host'] ?? ''), (string) ($config['password'] ?? ''));
        }

        return [
            'success' => false,
            'error' => $baseError . $hint,
            'delivery' => 'smtp'
        ];
    }
}
