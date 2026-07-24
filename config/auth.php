<?php
/**
 * includes/auth.php
 * Session/role guards + shared login redirect logic.
 * Call session_start() BEFORE requiring this file.
 */

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit();
    }
}

function require_role($role) {
    require_login();
    if ($_SESSION['role'] != $role) {
        http_response_code(403);
        die('Forbidden');
    }
}

function require_admin() {
    require_role('admin');
}

function require_admin_api() {
    // For AJAX/API endpoints: JSON error instead of redirect/die
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
}