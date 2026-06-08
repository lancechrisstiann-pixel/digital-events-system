<?php
/**
 * login.php — Authentication entry point.
 */
session_start();
include('connection.php');

/* ── Seed default admin account if this is a fresh install ── */
$stmt = $con->prepare("SELECT id FROM users WHERE username = 'admin'");
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $hash = password_hash("admin123", PASSWORD_DEFAULT);
    $ins  = $con->prepare("INSERT INTO users (username, password) VALUES ('admin', ?)");
    $ins->bind_param("s", $hash);
    $ins->execute();
    $ins->close();
}
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    if (!$user || !$pass) {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $con->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['user_id']  = $row['id'];
            $_SESSION['username'] = $row['username'];
            $redirect = $row['username'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php';
            header("Location: $redirect");
            exit();
        }
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Digital Events System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<div class="login-card">
    <h2 style="text-align:center; color:#333; margin-bottom:20px; font-size:24px;">Digital Event Management System</h2>

    <?php if (isset($error)): ?>
        <p class="alert-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if (($_GET['signup'] ?? '') === 'success'): ?>
        <p class="alert-success">Account created successfully. Please log in.</p>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom:15px;">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Username</label>
            <input type="text" name="username" class="form-control" required>
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn-submit">Login</button>
    </form>

    <p style="text-align:center; margin-top:20px; font-size:14px; margin-bottom:0;">
        New here? <a href="signup.php" style="color:#3b7ddd; text-decoration:none; font-weight:600;">Create an account</a>
    </p>
</div>
</body>
</html>