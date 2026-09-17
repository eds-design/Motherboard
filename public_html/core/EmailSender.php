<?php
// Email utility class for Motherboard
// This class provides a simple interface to PHPMailer

class EmailSender {
    private $mailer;
    private $companyName;
    
    public function __construct($companyName = APP_NAME) {
        $this->companyName = $companyName;
        
        // Check if PHPMailer is available
        if (!file_exists(VENDOR_PATH . '/PHPMailer/PHPMailer.php')) {
            throw new Exception('PHPMailer not found. Please install PHPMailer in the vendors/PHPMailer directory.');
        }
        
        require_once VENDOR_PATH . '/PHPMailer/PHPMailer.php';
        require_once VENDOR_PATH . '/PHPMailer/SMTP.php';
        require_once VENDOR_PATH . '/PHPMailer/Exception.php';
        
        $this->mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $this->configureSMTP();
    }
    
    private function configureSMTP() {
        $this->mailer->isSMTP();
        $this->mailer->Host = SMTP_HOST;
        if (SMTP_USER !== '') {
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USER;
            $this->mailer->Password = SMTP_PASS;
        } else {
            $this->mailer->SMTPAuth = false;
        }
        if (SMTP_SECURE) {
            $this->mailer->SMTPSecure = SMTP_SECURE;
        } else {
            $this->mailer->SMTPSecure = false;
            $this->mailer->SMTPAutoTLS = false;
        }
        $this->mailer->Port = SMTP_PORT;
        
        $this->mailer->setFrom(FROM_EMAIL, $this->companyName);
    }
    
    /**
     * Sends an arbitrary HTML email. $textBody is the plain-text alternative; $replyTo lets
     * a recipient's reply reach a real inbox instead of FROM_EMAIL.
     */
    public function send($email, $subject, $htmlBody, $textBody = '', $replyTo = '') {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearReplyTos();
            $this->mailer->addAddress($email);
            if ($replyTo !== '') {
                $this->mailer->addReplyTo($replyTo, $this->companyName);
            }

            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Subject = $subject;
            $this->mailer->isHTML(true);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $textBody;

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Failed to send email: " . $e->getMessage());
            return false;
        }
    }

    public function send2FACode($email, $code, $username) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($email);
            
            $this->mailer->Subject = t('email.2fa_subject', ['company' => $this->companyName]);
            
            $body = "
            <html>
            <body>
                <h2>" . htmlspecialchars(t('email.2fa_heading')) . "</h2>
                <p>" . htmlspecialchars(t('email.hello', ['name' => $username])) . "</p>
                <p>" . htmlspecialchars(t('email.2fa_body', ['company' => $this->companyName])) . "</p>
                <h3 style='color: #2563eb; font-size: 24px; letter-spacing: 3px;'>{$code}</h3>
                <p>" . htmlspecialchars(t('email.2fa_expire')) . "</p>
                <p>" . htmlspecialchars(t('email.2fa_ignore')) . "</p>
                <hr>
                <p><small>" . htmlspecialchars(t('email.automated', ['company' => $this->companyName])) . "</small></p>
            </body>
            </html>
            ";
            
            $this->mailer->isHTML(true);
            $this->mailer->Body = $body;
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Failed to send 2FA email: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPasswordReset($email, $token, $username) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($email);
            
            $this->mailer->Subject = t('email.reset_subject', ['company' => $this->companyName]);
            
            $resetUrl = BASE_URL . "/reset-password?token={$token}";
            
            $body = "
            <html>
            <body>
                <h2>" . htmlspecialchars(t('email.reset_heading')) . "</h2>
                <p>" . htmlspecialchars(t('email.hello', ['name' => $username])) . "</p>
                <p>" . htmlspecialchars(t('email.reset_body', ['company' => $this->companyName])) . "</p>
                <p><a href='{$resetUrl}' style='background-color: #2563eb; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . htmlspecialchars(t('auth.reset_button')) . "</a></p>
                <p>" . htmlspecialchars(t('email.reset_or')) . "</p>
                <p>{$resetUrl}</p>
                <p>" . htmlspecialchars(t('email.reset_expire')) . "</p>
                <p>" . htmlspecialchars(t('email.reset_ignore')) . "</p>
                <hr>
                <p><small>" . htmlspecialchars(t('email.automated', ['company' => $this->companyName])) . "</small></p>
            </body>
            </html>
            ";
            
            $this->mailer->isHTML(true);
            $this->mailer->Body = $body;
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Failed to send password reset email: " . $e->getMessage());
            return false;
        }
    }
}
