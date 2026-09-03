<?php
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function send_reset_email(string $toEmail, string $toName, string $resetLink): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Reset your ' . APP_NAME . ' password';
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
        $mail->Body = "<p>Hi {$safeName},</p>"
            . "<p>We received a request to reset your " . APP_NAME . " password. This link expires in 45 minutes and can only be used once.</p>"
            . "<p><a href=\"{$safeLink}\">{$safeLink}</a></p>"
            . "<p>If you didn't request this, you can safely ignore this email.</p>";
        $mail->AltBody = "Reset your password: {$resetLink}\nThis link expires in 45 minutes and can only be used once.";

        return $mail->send();
    } catch (PHPMailerException $e) {
        error_log('send_reset_email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
