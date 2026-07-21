<?php
session_start();
require_once '../../includes/load.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$date = $_GET['date'] ?? date('Y-m-d');
$pickup_time = $_GET['pickup'] ?? date('H:i:s');
$dropoff_time = $_GET['dropoff'] ?? date('H:i:s', strtotime($pickup_time . ' + 1 hour'));

// FIXED: Use proper overlap detection
$stmt = $pdo->prepare("
    SELECT d.*, c.brand, c.plate_number, c.parking, c.coding_day 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active' 
    AND c.car_id IS NOT NULL
    AND c.status != 'under_maintenance'  -- ADD THIS LINE
    AND NOT EXISTS (
        SELECT 1 
        FROM tbl_allocations a 
        WHERE a.driver_id = d.driver_id 
        AND a.date = ? 
        AND a.status IN ('approved', 'in_progress', 'pending')
        AND a.pickup_time < ? 
        AND a.dropoff_time > ?
    )
    ORDER BY d.name
");
$stmt->execute([$date, $dropoff_time, $pickup_time]);
$drivers = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'data' => $drivers
]);