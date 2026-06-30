<?php
/**
 * delete_reading.php
 * Handles deleting single or all rows from sensor_history.
 * Secured: Admin-only access restriction applied.
 */

session_start();
require_once "db_connection.php";

// Strict Role Guard
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Access Denied: Administrators only."]);
    exit;
}

$jsonInput = file_get_contents("php://input");
$data = json_decode($jsonInput, true);

try {
    // Clear All Action
    if (isset($data['action']) && $data['action'] === 'clear_all') {
        $pdo->exec("TRUNCATE TABLE sensor_history");
        echo json_encode(["status" => "success", "message" => "All history cleared successfully."]);
        exit;
    }

    // Single Delete Action
    if (isset($data['id'])) {
        $id = intval($data['id']);
        $stmt = $pdo->prepare("DELETE FROM sensor_history WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(["status" => "success", "message" => "Record deleted successfully."]);
        exit;
    }

    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request parameters."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database operation failed: " . $e->getMessage()]);
}
?>