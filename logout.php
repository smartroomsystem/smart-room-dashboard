<?php
session_start();
require_once "db_connection.php";
require_once "log_activity.php";

$role = ucfirst($_SESSION['role'] ?? 'user');

// Look up the real email so the activity log stays consistent with
// login.php / verify_otp.php (which log the email, not the username).
$email = 'unknown';
if (isset($_SESSION['admin_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT email FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $email = $stmt->fetchColumn() ?: ($_SESSION['admin_username'] ?? 'unknown');
    } catch (PDOException $e) {
        $email = $_SESSION['admin_username'] ?? 'unknown';
    }
}

logActivity($pdo, $email, "$role logged out");

session_unset();
session_destroy();
header("Location: login.php");
exit;
?>