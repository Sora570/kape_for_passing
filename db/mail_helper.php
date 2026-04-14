<?php
// db/mail_helper.php
// Provides a single send_reset_mail() function which prefers PHPMailer+SMTP
// when configured, and falls back to PHP mail(). Returns true on success.

// Try to autoload Composer packages (PHPMailer) if available
$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

function send_reset_mail(string $toEmail, string $subject, string $body, bool $isHtml = false): bool {
    $cfg = require __DIR__ . '/mail_config.php';

    // If PHPMailer is available and SMTP is configured, use it
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && !empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            // Server settings
            $mail->isSMTP();
            $mail->Host = $cfg['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['smtp_user'];
            $mail->Password = $cfg['smtp_pass'];
            $mail->Port = (int)$cfg['smtp_port'];
            if (!empty($cfg['smtp_secure'])) {
                $mail->SMTPSecure = $cfg['smtp_secure'];
            }
            $mail->setFrom($cfg['from_address'], $cfg['from_name']);
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->CharSet = 'UTF-8';

            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = strip_tags($body);
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }

            $mail->send();
            error_log("Password reset email sent successfully via PHPMailer to: $toEmail");
            return true;
        } catch (\Exception $e) {
            // Log detailed error and fall back
            $errorMsg = 'PHPMailer send failed: ' . $e->getMessage();
            error_log($errorMsg);
            // Also log to a file for easier debugging
            @file_put_contents(__DIR__ . '/mail_error.log', date('c') . " - PHPMailer Error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    } else {
        // Log why PHPMailer isn't being used
        $reason = !class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? 'PHPMailer not available' : 'SMTP not configured (host or user empty)';
        error_log("PHPMailer not used for password reset email: $reason");
    }

    // Fallback: try PHP mail(). Use simple headers and support HTML if requested.
    $headers = "From: " . ($cfg['from_name'] ?? 'Kape Timplado') . " <" . ($cfg['from_address'] ?? 'no-reply@localhost') . ">\r\n";
    if ($isHtml) {
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }
    $headers .= "Reply-To: " . ($cfg['from_address'] ?? 'no-reply@localhost') . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Try to send via PHP mail() and log the result
    $ok = @mail($toEmail, $subject, $body, $headers);
    if (!$ok) {
        $lastError = error_get_last();
        $errorMsg = isset($lastError['message']) ? $lastError['message'] : 'Unknown PHP mail() error';
        error_log("PHP mail() function failed for password reset email to: $toEmail - Error: $errorMsg");
        @file_put_contents(__DIR__ . '/mail_error.log', date('c') . " - PHP mail() Error: $errorMsg\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log("Password reset email sent successfully via PHP mail() to: $toEmail");
    }
    return (bool)$ok;
}
