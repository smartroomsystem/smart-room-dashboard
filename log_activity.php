<?php
/**
 * log_activity.php
 * Include this file, then call logActivity().
 * Callers must start the session and connect DB before including.
 */
if (!function_exists('logActivity')) {
    function logActivity(PDO $pdo, string $email, string $action): void
    {
        $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO account_activity_logs (email, ip_address, action) VALUES (?, ?, ?)"
            );
            $stmt->execute([substr($email, 0, 255), substr($ip, 0, 45), substr($action, 0, 255)]);
        } catch (PDOException $e) {
            error_log("Activity log write failed: " . $e->getMessage());
        }
    }
}
?>