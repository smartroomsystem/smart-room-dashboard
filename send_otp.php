<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/src/Exception.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';

/**
 * Sends a 6-digit OTP verification email using PHPMailer.
 * * @param string $toEmail   Recipient email address.
 * @param string $otpCode   The 6-digit random code.
 * @param string $username  Optional username for personalizing the message body.
 * @return bool             True if sent successfully, false otherwise.
 */
function sendOTP($toEmail, $otpCode, $username = "User")
{
    $mail = new PHPMailer(true);

    try {
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        // Your Gmail Configurations
        $mail->Username = getenv('GMAIL_USER');
        $mail->Password = getenv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        // Sender Identity
        $mail->setFrom(
            'smartroomclimatecontrolsystem@gmail.com',
            'Smart Room Security'
        );

        // Receiver Identification
        $mail->addAddress($toEmail);

        // Email Subject & Content Formulation
        $mail->Subject = "Your Smart Room OTP Verification Code";
        
        // Dynamic email copy incorporating the user identity
        $mail->Body = "Hello " . htmlspecialchars($username) . ",\n\n"
                    . "A login request was made for your Smart Room Climate Control account.\n"
                    . "Your One-Time Password (OTP) is: " . $otpCode . "\n\n"
                    . "This code is valid for 10 minutes. If you did not request this code, please secure your account credentials immediately.\n\n"
                    . "Best regards,\n"
                    . "Smart Room Security Team";

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Safe internal logging to track configuration hitches
        error_log("OTP Mail Error to " . $toEmail . ": " . $mail->ErrorInfo);
        return false;
    }
}
?>