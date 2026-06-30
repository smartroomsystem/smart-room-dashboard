<?php
/**
 * get_history.php
 * Fetches sensor history from the MySQL database.
 * Secured: Permissive to authenticated sessions, regardless of role profile.
 */

session_start();
require_once "db_connection.php";

// Guard Layer: Allow any logged-in account with verified OTP status
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit;
}

try {
    // Fetch latest records first. Capped with LIMIT so this stays fast
    // as the table grows — an unbounded ORDER BY id DESC over the whole
    // table is what makes the dashboard feel slower over time.
    // 300 rows is plenty for the chart (15 pts) and the visible history table.
    $limit = isset($_GET['limit']) ? max(1, min(1000, intval($_GET['limit']))) : 300;
    $stmt = $pdo->prepare("SELECT id, temperature, fan_status, system_status, recorded_at FROM sensor_history ORDER BY id DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll();

    echo json_encode(["status" => "success", "data" => $history]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database read failed: " . $e->getMessage()]);
}
?>