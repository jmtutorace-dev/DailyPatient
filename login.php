<?php
/**
 * YAKAP GAMOT SYSTEM - Login Page
 */
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Auto-create default admin if users table is empty (first run)
$check = $conn->query("SELECT COUNT(*) AS cnt FROM users");
if ($check && (int)$check->fetch_assoc()['cnt'] === 0) {
    $default_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password_hash, full_name, role) VALUES ('admin', '$default_hash', 'Administrator', 'admin')");
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT user_id, username, password_hash, full_name, role, is_active FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id']    = (int)$user['user_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['user_role']  = $user['role'];

                $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'dashboard.php';
                unset($_SESSION['redirect_after_login']);
                header("Location: $redirect");
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Invalid username or password.';
        }
        $stmt->close();
    } else {
        $error = 'Please enter both username and password.';
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YAKAP GAMOT SYSTEM - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <h1>YAKAP GAMOT SYSTEM</h1>
            <p>Clinic Patient &amp; Medicine Tracking</p>
        </div>

        <?php if ($error): ?>
            <div class="flash-message flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-primary login-btn">Login</button>
        </form>

        <div class="login-footer">
            <p>&copy; <?= date('Y') ?> YAKAP GAMOT SYSTEM</p>
        </div>

    </div>
</div>

</body>
</html>

