<?php
// /includes/mailer.php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/env.php';

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

function send_smtp_mail(array $opts): bool
{
    $toEmail  = trim((string)($opts['to_email'] ?? ''));
    $toName   = trim((string)($opts['to_name'] ?? ''));
    $subject  = (string)($opts['subject'] ?? '');
    $bodyText = (string)($opts['body_text'] ?? '');
    $bodyHtml = (string)($opts['body_html'] ?? '');

    if ($toEmail === '' || $subject === '' || ($bodyText === '' && $bodyHtml === '')) {
        error_log('MAILER: Missing required fields');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // ======= SMTP SETTINGS (fill these) =======
        $smtpHost = 'smtp.hostinger.com';
        $smtpUser = 'info@runledger.org';
        $smtpPass = 'Lillylee2006$';
        $smtpPort = 587;
        $smtpSec  = PHPMailer::ENCRYPTION_STARTTLS;
        // If using port 465, use: PHPMailer::ENCRYPTION_SMTPS
        // =========================================

        $fromEmail = 'info@runledger.org';
        $fromName  = 'Ledger Support and Knowledge Base';

        $mail->isSMTP();
        $mail->Timeout = 5;          // stops infinite loading
        $mail->SMTPKeepAlive = false;
        $mail->SMTPOptions = [
          'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
          ],
        ];
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->SMTPSecure = $smtpSec;

        // Best for testing without breaking pages:
        $mail->SMTPDebug  = 0;
        // $mail->SMTPDebug = 2; $mail->Debugoutput = 'error_log';

        $mail->CharSet = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);

        $mail->addAddress($toEmail, $toName);

        $mail->Subject = $subject;

        if ($bodyHtml !== '') {
            $mail->isHTML(true);
            $mail->Body    = $bodyHtml;
            $mail->AltBody = ($bodyText !== '') ? $bodyText : strip_tags($bodyHtml);
        } else {
            $mail->isHTML(false);
            $mail->Body = $bodyText;
        }

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('MAILER: Send failed: ' . $mail->ErrorInfo);
        return false;
    }
}
