<?php
/**
 * signup.php — New user registration.
 */
include('connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    if (!$user) {
        $error = "Username cannot be empty.";
    } elseif (strtolower($user) === 'admin') {
        $error = "This username is reserved.";
    } elseif (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $stmt = $con->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = "Username is already taken.";
        } else {
            $ins = $con->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $ins->bind_param("ss", $user, password_hash($pass, PASSWORD_DEFAULT));
            if ($ins->execute()) {
                $ins->close();
                header("Location: login.php?signup=success");
                exit();
            }
            $error = "Something went wrong. Please try again.";
            $ins->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up - Digital Events System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<div class="signup-card">
    <h2 style="text-align:center; color:#333; margin-bottom:10px;">Create your personal account</h2>

    <?php if (isset($error)): ?>
        <p class="alert-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom:15px;">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Username</label>
            <input type="text" name="username" class="form-control" required
                   value="<?= isset($user) ? htmlspecialchars($user) : '' ?>">
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block; margin-bottom:5px; font-weight:600;">Password</label>
            <input type="password" name="password" class="form-control" minlength="6" required>
        </div>
        <button type="submit" class="btn-submit">Sign Up</button>
    </form>

    <p style="text-align:center; margin-top:15px; font-size:14px;">
        Already have an account? <a href="login.php" style="color:#3b7ddd; text-decoration:none; font-weight:600;">Login here</a>
    </p>
</div>
</body>
</html>