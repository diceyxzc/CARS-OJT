<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get pending count
$pending_count = $pdo->query("
    SELECT COUNT(*) 
    FROM tbl_allocations 
    WHERE status = 'pending' AND (driver_id IS NULL OR car_id IS NULL)
")->fetchColumn();

// Get latest pending requests
$pending_requests = $pdo->query("
    SELECT allocation_id, request_number, 
           (SELECT username FROM tbl_users WHERE user_id = a.requestor_id) as requestor,
           date, pickup_time,
           TIMESTAMPDIFF(MINUTE, created_at, NOW()) as minutes_old
    FROM tbl_allocations a
    WHERE status = 'pending' AND (driver_id IS NULL OR car_id IS NULL)
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

// Show the admin all the requests waiting for their approval, and how many there are.
echo json_encode([
    'success' => true,
    'data' => [
        'pending_count' => (int)$pending_count,
        'pending_requests' => $pending_requests,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);