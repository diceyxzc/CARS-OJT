<?php
session_start();
require_once 'config/db.php';
require_once 'config/auth.php';

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role == 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($role == 'driver') {
        header('Location: driver/dashboard.php');
    } else {
        header('Location: user/dashboard.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM tbl_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        
        $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, details, timestamp) VALUES (?, 'login', ?, NOW())");
        $log->execute([$user['user_id'], "Logged in"]);
        
        if ($user['role'] == 'admin') {
            header('Location: admin/dashboard.php');
        } elseif ($user['role'] == 'driver') {
            header('Location: driver/dashboard.php');
        } else {
            header('Location: user/dashboard.php');
        }
        exit();
    } else {
        $error = "Invalid username or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CARS - Car Allocation Reservation System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
</head>
<body class="login-page">
    <div class="login-container">
    <div class="logo">
        <img src="../assets/img/logo.png" alt="CARS Logo" style="width:80px; height:80px;">
        <h1>CARS</h1>
        <p style="color:var(--gray); font-size:0.85rem;">Car Allocation Reservation System</p>
    </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>⚠️</span> <?= $error ?>
                <button class="close-btn">&times;</button>
            </div>
        <?php endif; ?>
        
        <form method="POST" style="margin-top:8px;">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter your username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Sign In</button>
        </form>
    </div>
    <script src="../assets/js/script.js"></script>
</body>
</html>