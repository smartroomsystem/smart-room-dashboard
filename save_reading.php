<?php
/**
 * save_reading.php
 * Saves incoming Web Serial sensor data to the MySQL database.
 * Secured: Admin-only writing permissions enforced.
 */

session_start();
require_once "db_connection.php";

// Guard Layer: Enforce admin-only access for data entry streams
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Access Denied: Writing logs restricted to administrators."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $jsonInput = file_get_contents("php://input");
    $data = json_decode($jsonInput, true);

    if ($data) {
        $temperature  = isset($data['temperature']) ? floatval($data['temperature']) : null;
        $fan_status   = isset($data['fan_status']) ? trim($data['fan_status']) : null;
        $system_status = isset($data['system_status']) ? trim($data['system_status']) : null;

        // Validation against database ENUMs and types
        if ($temperature !== null && in_array($fan_status, ['ON', 'OFF']) && in_array($system_status, ['NORMAL', 'SAKTO', 'HIGH TEMP'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO sensor_history (temperature, fan_status, system_status) VALUES (?, ?, ?)");
                $stmt->execute([$temperature, $fan_status, $system_status]);

                echo json_encode(["status" => "success", "message" => "Reading recorded successfully."]);
                exit;
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Database write failed: " . $e->getMessage()]);
                exit;
            }
        }
    }
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid payload structure or out-of-bounds parameters."]);
    exit;
}
?>