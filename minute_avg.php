<?php
/**
 * minute_avg.php
 * Returns dashboard temperature statistics and graph history.
 */

header('Content-Type: application/json');

session_start();
require_once "db_connection.php";

if (
    !isset($_SESSION['admin_id']) ||
    !isset($_SESSION['otp_verified']) ||
    $_SESSION['otp_verified'] !== true
) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized access."
    ]);
    exit;
}

try {

    // ---------- Dashboard Statistics ----------
    $stats = $pdo->query("
        SELECT
            MIN(temperature) AS min_temp,
            AVG(temperature) AS avg_temp,
            MAX(temperature) AS max_temp
        FROM sensor_history
    ")->fetch(PDO::FETCH_ASSOC);

    // ---------- Last 15 Temperature Records ----------
    $stmt = $pdo->prepare("
        SELECT
            recorded_at,
            temperature
        FROM sensor_history
        ORDER BY recorded_at DESC
        LIMIT 15
    ");

    $stmt->execute();

    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        "status" => "success",

        "stats" => [
            "min" => $stats["min_temp"] !== null ? round($stats["min_temp"],1) : null,
            "avg" => $stats["avg_temp"] !== null ? round($stats["avg_temp"],1) : null,
            "max" => $stats["max_temp"] !== null ? round($stats["max_temp"],1) : null
        ],

        "history" => $history
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}