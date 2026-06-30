<?php
session_start();
require_once "db_connection.php";
require_once "log_activity.php";
require_once "validate.php";

// Synchronize PHP timezone with your MySQL database time
date_default_timezone_set('Asia/Manila'); 

// If already fully logged in and verified, skip this page
if (isset($_SESSION['admin_id']) && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
    } else {
        header("Location: user_dashboard.php");
    }
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if (empty($username) || empty($password) || hasDangerousChars($username)) {
        $error = "Invalid username or password format.";
    } else {
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            // Mitigate Session Fixation attacks
            session_regenerate_id(true);

            require_once "send_otp.php";

            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Flush out old, unverified tokens for this user profile
            $delete = $pdo->prepare("DELETE FROM otp_tokens WHERE user_id = ?");
            $delete->execute([$admin['id']]);

            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $insert = $pdo->prepare("INSERT INTO otp_tokens (user_id, otp_code, expires_at) VALUES (?, ?, ?)");
            $insert->execute([$admin['id'], $otp, $expires]);

            // Dispatch verification code via PHPMailer configuration
            if (sendOTP($admin['email'], $otp, $admin['username'])) {
                logActivity($pdo, $admin['email'], "Login initiated — OTP dispatched");
                $_SESSION['pending_admin_id'] = $admin['id'];
                $_SESSION['pending_admin_username'] = $admin['username'];
                header("Location: verify_otp.php");
                exit;
            } else {
                $error = "Failed to dispatch authentication code. Please contact system support.";
            }
        } else {
            logActivity($pdo, $username . "@unknown", "Login failed — bad credentials (username: $username)");
            $error = "Invalid master username or security key combination.";
        }
    } catch (PDOException $e) {
        error_log("Login System Database Error: " . $e->getMessage());
        $error = "An internal authentication server error occurred.";
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Room - Login</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">&#9651;</div>
            <h1>SMART ROOM</h1>
            <p class="login-subtitle">Climate Control System</p>
        </div>

        <?php if (isset($_GET["timeout"])): ?>
            <div class="alert alert-timeout">
                &#9201; Session expired due to inactivity. Please log in again.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                &#9888; <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="username">USERNAME</label>
                <input type="text" id="username" name="username" maxlength="50" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="password">PASSWORD</label>
                <input type="password" id="password" name="password" maxlength="200" required>
            </div>

            <button type="submit" class="btn-login">AUTHENTICATE</button>
        </form>

        <p style="text-align:center; margin-top:20px; font-size:0.8rem; font-family:'JetBrains Mono'; color:var(--muted);">
            Don't have an account? <a href="register.php" style="color:var(--green); text-decoration:none;">Register here</a>
        </p>
    </div>
</div>
</body>
</html>