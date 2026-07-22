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

// Get all cars with driver assignment
$cars = $pdo->query("
    SELECT c.*, 
           d.driver_id, d.name as driver_name, d.mobile as driver_mobile,
           d.status as driver_status
    FROM tbl_cars c
    LEFT JOIN tbl_drivers d ON c.car_id = d.car_id
    ORDER BY c.brand
")->fetchAll();

/* Count all the cars, figure out which ones have drivers, and group them by their current status 
(available, in use, or under maintenance). */
$total_cars = count($cars);
$assigned = 0;
$unassigned = 0;
$available = 0;
$in_use = 0;
$maintenance = 0;

foreach ($cars as $car) {
    if ($car['driver_id']) $assigned++;
    else $unassigned++;
    
    switch ($car['status']) {
        case 'available': $available++; break;
        case 'in_use': $in_use++; break;
        case 'under_maintenance': $maintenance++; break;
    }
}

// Give the webpage all the car data and stats it needs to display the fleet management dashboard in one request.
echo json_encode([
    'success' => true,
    'data' => [
        'cars' => $cars,
        'stats' => [
            'total' => $total_cars,
            'assigned' => $assigned,
            'unassigned' => $unassigned,
            'available' => $available,
            'in_use' => $in_use,
            'maintenance' => $maintenance
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);