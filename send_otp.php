<?php
/**
 * send_otp.php
 * Sends the OTP email via a Google Apps Script Web App relay instead of
 * PHPMailer/SMTP. This is required because Railway blocks outbound SMTP
 * (ports 25/465/587/2525) on Free/Hobby plans. The Apps Script endpoint
 * uses GmailApp.sendEmail() under the hood, which goes out over HTTPS,
 * so it isn't affected by that block.
 *
 * IMPORTANT: Update GAS_WEBAPP_URL and GAS_SHARED_SECRET below to match
 * your deployed Apps Script project exactly.
 */

// The Web App URL you copied after deploying the Apps Script project.
define('GAS_WEBAPP_URL', 'https://script.google.com/macros/s/AKfycbzkFgzr0ZYv2dPlmvn2jJXzF1Z_Xkgs6cd_24oaimVEoqKJZfrbezdE9coapwUcLrX4BQ/exec');

// Must match the SHARED_SECRET constant in your Code.gs exactly.
define('GAS_SHARED_SECRET', 'smartroom-9f3a7c1e-change-me');

/**
 * Sends a 6-digit OTP verification email via the Apps Script relay.
 *
 * @param string $toEmail   Recipient email address.
 * @param string $otpCode   The 6-digit random code.
 * @param string $username  Optional username for personalizing the message body.
 * @return bool             True if sent successfully, false otherwise.
 */
function sendOTP($toEmail, $otpCode, $username = "User")
{
    $subject = "Your Smart Room OTP Verification Code";

    $body = "Hello " . $username . ",\n\n"
          . "A login request was made for your Smart Room Climate Control account.\n"
          . "Your One-Time Password (OTP) is: " . $otpCode . "\n\n"
          . "This code is valid for 10 minutes. If you did not request this code, please secure your account credentials immediately.\n\n"
          . "Best regards,\n"
          . "Smart Room Security Team";

    $payload = json_encode([
        "secret"  => GAS_SHARED_SECRET,
        "to"      => $toEmail,
        "subject" => $subject,
        "body"    => $body
    ]);

    $ch = curl_init(GAS_WEBAPP_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // Apps Script issues a redirect before returning the response
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("OTP relay cURL error for " . $toEmail . ": " . $curlErr);
        return false;
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200 || !isset($result['status']) || $result['status'] !== 'success') {
        $msg = $result['message'] ?? $response;
        error_log("OTP relay failed for " . $toEmail . " (HTTP $httpCode): " . $msg);
        return false;
    }

    return true;
}
?>
