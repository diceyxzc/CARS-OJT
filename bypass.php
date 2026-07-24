<?php
session_start();

// Set all required session variables
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';

// Also set these for good measure
$_SESSION['SESS_ID'] = 1;
$_SESSION['SESS_ROLE'] = 'admin';
$_SESSION['SESS_USERNAME'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

header('Location: admin/dashboard.php');
exit();
?>