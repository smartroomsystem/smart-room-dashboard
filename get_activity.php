<?php
/**
 * get_activity.php
 * Returns recent rows from account_activity_logs.
 * Secured: Admin-only.
 *
 * Hardened to ALWAYS return valid JSON, even on a fatal DB/connection
 * error, so the frontend never has to guess at a broken HTML/text
 * response — that mismatch is what causes "DB updates but the page
 * never reflects it" symptoms.
 */

header('Content-Type: application/json');

// Catch any otherwise-fatal error (e.g. db_connection.php's die()) and
// turn it into a clean JSON error response instead of plain text/HTML.
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Fatal server error while loading activity logs."]);
    }
});

session_start();

try {
    require_once "db_connection.php";
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

if (
    !isset($_SESSION['admin_id']) ||
    !isset($_SESSION['otp_verified']) ||
    $_SESSION['otp_verified'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Access Denied: Administrators only."]);
    exit;
}

try {
    $limit = isset($_GET['limit']) ? max(1, min(500, intval($_GET['limit']))) : 100;
    $stmt = $pdo->prepare(
        "SELECT email, ip_address, action, created_at
         FROM account_activity_logs
         ORDER BY id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database read failed: " . $e->getMessage()]);
}