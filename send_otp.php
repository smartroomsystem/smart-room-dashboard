<?php
function sendOTP($toEmail, $otpCode, $username = "User")
{
    $apiKey = getenv('BREVO_API_KEY');

    if (!$apiKey) {
        error_log("OTP Mail Error to $toEmail: BREVO_API_KEY is not set.");
        return false;
    }

    $payload = [
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
