<?php

/**
 * Sends a 6-digit OTP verification email using Resend's HTTP API.
 * Uses HTTPS (port 443) instead of SMTP, which avoids Railway's
 * outbound SMTP port blocking ("Network is unreachable" errors).
 *
 * @param string $toEmail   Recipient email address.
 * @param string $otpCode   The 6-digit random code.
 * @param string $username  Optional username for personalizing the message body.
 * @return bool             True if sent successfully, false otherwise.
 */
function sendOTP($toEmail, $otpCode, $username = "User")
{
    $apiKey = getenv('RESEND_API_KEY');

    if (!$apiKey) {
        error_log("OTP Mail Error to $toEmail: RESEND_API_KEY is not set.");
        return false;
    }

    $payload = [
        // While testing on Resend's free/unverified domain, the "from"
        // address must be onboarding@resend.dev. Once you verify your
        // own domain in Resend, switch this to your own address
        // (e.g. security@yourdomain.com).
        "from"    => "Smart Room Security <onboarding@resend.dev>",
        "to"      => [$toEmail],
        "subject" => "Your Smart Room OTP Verification Code",
        "text"    => "Hello " . $username . ",\n\n"
                   . "A login request was made for your Smart Room Climate Control account.\n"
                   . "Your One-Time Password (OTP) is: " . $otpCode . "\n\n"
                   . "This code is valid for 10 minutes. If you did not request this code, please secure your account credentials immediately.\n\n"
                   . "Best regards,\n"
                   . "Smart Room Security Team",
    ];

    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
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

    error_log("OTP Mail Error to $toEmail: Resend API returned HTTP $httpCode: $response");
    return false;
}
?>
