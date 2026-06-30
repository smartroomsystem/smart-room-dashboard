<?php
session_start();
require_once "db_connection.php";
require_once "log_activity.php";
require_once "validate.php";

// If already fully logged in, redirect straight to the dashboard
if (isset($_SESSION['admin_id']) && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
    } else {
        header("Location: user_dashboard.php");
    }
    exit;
}

$message = "";
$status = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $email = filter_var(trim($_POST["email"] ?? ""), FILTER_VALIDATE_EMAIL);
    $password = trim($_POST["password"] ?? "");
    
    // Public registration is locked to 'user' role only.
    // Admin accounts must be seeded via database or granted by an existing admin.
    $role = 'user';

    if (!empty($username) && $email && !empty($password) && isValidUsername($username) && !hasDangerousChars($username) && !hasDangerousChars($email) && isValidPassword($password)) {
        try {
            // Check if username or email is already taken
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?");
            $checkStmt->execute([$username, $email]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $status = "error";
                $message = "Username or Email address is already registered.";
            } else {
                // Securely hash password
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                $stmt = $pdo->prepare(
                    "INSERT INTO admins (username, email, password, role) VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([$username, $email, $hash, $role]);

                $status = "success";
                $message = "Account (" . ucfirst($role) . ") created successfully! You can now login.";
                logActivity($pdo, $email, "New $role account registered (username: $username)");
            }
        } catch(PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            $status = "error";
            $message = "An internal error occurred. Please try again later.";
        }
    } else {
        $status = "error";
        $message = "Please complete all fields with a valid email address. Username must be letters/spaces only (no numbers or symbols), and password must be 8-72 characters with at least one letter and one number. No special/invalid characters allowed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Room - Register</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body class="login-page">
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">&#9651;</div>
            <h1>SMART ROOM</h1>
            <p class="login-subtitle">Create Account</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $status === 'success' ? 'success' : 'error' ?>" style="<?= $status === 'success' ? 'background:rgba(0,230,118,0.1); border:1px solid rgba(0,230,118,0.3); color:#00e676;' : '' ?>">
                <?= $status === 'success' ? '&#10004;' : '&#9888;' ?> <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="username">USERNAME</label>
                <input type="text" id="username" name="username" pattern="[A-Za-z ]{2,50}" title="Letters and spaces only, no numbers or symbols" maxlength="50" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="email">EMAIL ADDRESS</label>
                <input type="email" id="email" name="email" maxlength="255" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="password">PASSWORD</label>
                <input type="password" id="password" name="password" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" title="8-72 characters, at least one letter and one number" minlength="8" maxlength="72" required>
            </div>
            <button type="submit" class="btn-login">CREATE ACCOUNT</button>
        </form>
        <p style="text-align:center; margin-top:20px; font-size:0.8rem; font-family:'JetBrains Mono'; color:var(--muted);">
            Back to <a href="login.php" style="color:var(--green); text-decoration:none;">Login</a>
        </p>
    </div>
</div>
</body>
</html>