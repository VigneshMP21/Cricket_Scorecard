<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // You need to install PHPMailer via composer

function sendPasswordResetEmail($email, $token) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? 'your-email@gmail.com';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? 'your-app-password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
        
        // Recipients
        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@cptleague.com', $_ENV['SMTP_FROM_NAME'] ?? 'CPT League');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - CPT League';
        
        $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/login_page/reset_password.php?token=" . $token;
        
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2 style="color: #2c3e50;">CPT League Password Reset</h2>
                <p>Hello,</p>
                <p>You have requested to reset your password for your CPT League account.</p>
                <p>Click the button below to reset your password:</p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $resetLink . '" 
                       style="background-color: #e74c3c; color: white; padding: 12px 24px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;">
                        Reset Password
                    </a>
                </div>
                <p>If the button doesn\'t work, copy and paste this link:</p>
                <p><code>' . $resetLink . '</code></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn\'t request this, please ignore this email.</p>
                <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                <p style="color: #7f8c8d; font-size: 12px;">
                    CPT League - Cricket Premier Tournament<br>
                    © ' . date('Y') . ' All rights reserved
                </p>
            </div>
        ';
        
        $mail->AltBody = "Reset your password using this link: " . $resetLink;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function sendPasswordChangedEmail($email, $name) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings (same as above)
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? 'your-email@gmail.com';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? 'your-app-password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
        
        // Recipients
        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@cptleague.com', $_ENV['SMTP_FROM_NAME'] ?? 'CPT League');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Changed Successfully - CPT League';
        
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2 style="color: #27ae60;">Password Changed Successfully</h2>
                <p>Hello ' . htmlspecialchars($name) . ',</p>
                <p>Your CPT League account password has been changed successfully.</p>
                <p>If you did not make this change, please contact our support team immediately.</p>
                <div style="background-color: #f8f9fa; padding: 15px; border-left: 4px solid #27ae60; margin: 20px 0;">
                    <p style="margin: 0;"><strong>Security Tip:</strong> Always use a strong, unique password for your account.</p>
                </div>
                <p>Thank you for being part of CPT League!</p>
                <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                <p style="color: #7f8c8d; font-size: 12px;">
                    CPT League - Cricket Premier Tournament<br>
                    © ' . date('Y') . ' All rights reserved
                </p>
            </div>
        ';
        
        $mail->AltBody = "Your CPT League password has been changed successfully.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>