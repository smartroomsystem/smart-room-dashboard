<?php

/**
 * Sends a 6-digit OTP verification email using Brevo's HTTP API.
 * Uses HTTPS (port 443) instead of SMTP, which avoids Railway's
 * outbound SMTP port blocking ("Network is unreachable" errors).
 *
 * Unlike Resend's free tier (which requires a verified domain to send
 * to arbitrary recipients), Brevo's free tier only requires verifying
 * a single SENDER EMAIL ADDRESS — no domain needed — and can then send
 * to any recipient.
 *
 * @param string $toEmail   Recipient email address.
 * @param string $otpCode   The 6-digit random code.
 * @param string $username  Optional username for personalizing the message body.
 * @return bool             True if sent successfully, false otherwise.
 */
function sendOTP($toEmail, $otpCode, $username = "User")
{
    $apiKey = getenv('BREVO_API_KEY');

    if (!$apiKey) {
        error_log("OTP Mail Error to $toEmail: BREVO_API_KEY is not set.");
        return false;
    }

    $payload = [
        // This MUST be an email address you have verified as a sender
        // inside your Brevo account (Senders & IP > Senders).
        "sender" => [
            "name"  => "Smart Room Security",
            "email" => "smartroomclimatecontrolsystem@gmail.com",
        ],
        "to" => [
            ["email" => $toEmail]
        ],
        "subject"     => "Your Smart Room OTP Verification Code",
        "textContent" => "Hello " . $username . ",\n\n"
                       . "A login request was made for your Smart Room Climate Control account.\n"
                       . "Your One-Time Password (OTP) is: " . $otpCode . "\n\n"
                       . "This code is valid for 10 minutes. If you did not request this code, please secure your account credentials immediately.\n\n"
                       . "Best regards,\n"
                       . "Smart Room Security Team",
    ];

    $ch = curl_init("https://api.brevo.com/v3/smtp/email");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "api-key: $apiKey",
            "Content-Type: application/json",
            "Accept: application/json",
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("OTP Mail Error to $toEmail: cURL error: $curlError");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("OTP Mail Error to $toEmail: Brevo API returned HTTP $httpCode: $response");
    return false;
}
?>
