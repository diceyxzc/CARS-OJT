<?php
session_start();
require_once '../../includes/load.php';
require_admin();

header('Content-Type: application/json');

// Check if driver and car are available for the requested date range
// Returns list of dates with conflicts (coding days, car busy, driver busy)
$driver_id = $_GET['driver_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$pickup_time = $_GET['pickup_time'] ?? '';
$dropoff_time = $_GET['dropoff_time'] ?? '';

if (!$driver_id || !$start_date || !$end_date || !$pickup_time) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

if (!$dropoff_time) {
    $dropoff_time = date('H:i:s', strtotime($pickup_time . ' + 1 hour'));
}

$stmt = $pdo->prepare("
    SELECT d.car_id, c.brand, c.plate_number, c.coding_day
    FROM tbl_drivers d
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id
    WHERE d.driver_id = ?
");
$stmt->execute([$driver_id]);
$driver_car = $stmt->fetch();

if (!$driver_car || !$driver_car['car_id']) {
    echo json_encode(['success' => false, 'message' => 'Driver has no car assigned']);
    exit();
}

$car_id = $driver_car['car_id'];
$coding_day = $driver_car['coding_day'];

$results = [];
$current = strtotime($start_date);
$end = strtotime($end_date);
$max_days = 366; // sanity cap
$day_count = 0;

$car_check = $pdo->prepare("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE car_id = ? AND date = ? AND status IN ('pending','approved','in_progress') 
    AND pickup_time < ? AND dropoff_time > ?
");
$driver_check = $pdo->prepare("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE driver_id = ? AND date = ? AND status IN ('pending','approved','in_progress') 
    AND pickup_time < ? AND dropoff_time > ?
");

while ($current <= $end && $day_count < $max_days) {
    $date = date('Y-m-d', $current);
    $day_of_week = date('l', $current);
    $issues = [];

    if ($coding_day && strcasecmp($coding_day, $day_of_week) === 0) {
        $issues[] = 'coding';
    }

    $car_check->execute([$car_id, $date, $dropoff_time, $pickup_time]);
    if ($car_check->fetchColumn() > 0) {
        $issues[] = 'car_busy';
    }

    $driver_check->execute([$driver_id, $date, $dropoff_time, $pickup_time]);
    if ($driver_check->fetchColumn() > 0) {
        $issues[] = 'driver_busy';
    }

    if (!empty($issues)) {
        $results[] = ['date' => $date, 'day_of_week' => $day_of_week, 'issues' => $issues];
    }

    $current = strtotime('+1 day', $current);
    $day_count++;
}

// Show the user what's wrong with this booking attempt and which dates are problematic.
echo json_encode([
    'success' => true,
    'car_brand' => $driver_car['brand'],
    'car_plate' => $driver_car['plate_number'],
    'coding_day' => $coding_day,
    'conflicts' => $results
]);