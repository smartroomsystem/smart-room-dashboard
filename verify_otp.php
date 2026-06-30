<?php
session_start();
require_once "db_connection.php";
require_once "log_activity.php";

// Set your local timezone to prevent OTP timestamp mismatches
date_default_timezone_set('Asia/Manila'); 

// Block direct access
if (!isset($_SESSION['pending_admin_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submitted = trim($_POST['otp'] ?? "");
    $userId = $_SESSION['pending_admin_id'];
    $currentTime = date('Y-m-d H:i:s');

    // Check OTP and expiry
    $stmt = $pdo->prepare(
        "SELECT * FROM otp_tokens 
        WHERE user_id = ?
        AND otp_code = ?
        AND expires_at > ?"
    );
    $stmt->execute([$userId, $submitted, $currentTime]);
    $record = $stmt->fetch();

    if ($record) {
        // Upgrade session lifecycle safely
        session_regenerate_id(true);

        try {
            $userStmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
            $userStmt->execute([$userId]);
            $userAccount = $userStmt->fetch();
            $role = $userAccount ? $userAccount['role'] : 'user';
        } catch (PDOException $e) {
            $role = 'user'; // Fallback safely
        }

        // Clean up completed token
        $delete = $pdo->prepare("DELETE FROM otp_tokens WHERE user_id = ?");
        $delete->execute([$userId]);

        // Log successful login
        $userEmail = '';
        try {
            $emailStmt = $pdo->prepare("SELECT email FROM admins WHERE id = ?");
            $emailStmt->execute([$userId]);
            $userEmail = $emailStmt->fetchColumn() ?: 'unknown';
        } catch (PDOException $e) {}
        logActivity($pdo, $userEmail, ucfirst($role) . " logged in successfully (OTP verified)");

        // Standardized across dashboards
        $_SESSION['admin_id'] = $userId; 
        $_SESSION['admin_username'] = $_SESSION['pending_admin_username'];
        $_SESSION['role'] = $role;
        $_SESSION['otp_verified'] = true;
        $_SESSION['login_time'] = time();

        // Clear transient states
        unset($_SESSION['pending_admin_id']);
        unset($_SESSION['pending_admin_username']);

        // Route accordingly
        if ($role === 'admin') {
            header("Location: dashboard.php");
        } else {
            header("Location: user_dashboard.php");
        }
        exit;
    } else {
        $error = "Invalid or expired OTP code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Room - Verify OTP</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body class="login-page">
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">&#9094;</div>
            <h1>ENTER OTP</h1>
            <p class="login-subtitle">A verification code was sent to your email.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                &#9888; <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="otp">6-DIGIT CODE</label>
                <input type="text" id="otp" name="otp" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">
            </div>
            <button type="submit" class="btn-login">VERIFY & ENTER</button>
        </form>
    </div>
</div>
</body>
</html>