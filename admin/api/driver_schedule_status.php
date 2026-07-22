<?php
// admin/api/driver_schedule_status.php
session_start();
require_once '../../includes/load.php';
require_admin();

header('Cache-Control: no-cache, must-revalidate');
date_default_timezone_set('Asia/Manila');

$week_start = $_GET['week'] ?? date('Y-m-d');
$week_start = date('Y-m-d', strtotime($week_start . ' - ' . (date('N', strtotime($week_start)) - 1) . ' days'));
$week_end = date('Y-m-d', strtotime($week_start . ' + 6 days'));

// FIXED: Only get ACTIVE drivers
$drivers = $pdo->query("
    SELECT d.driver_id, d.name, d.mobile, d.status,
           c.car_id, c.brand, c.plate_number, c.parking
    FROM tbl_drivers d
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id
    ORDER BY d.name
")->fetchAll();

// Get trips for the week (approved and in_progress only)
$trips = $pdo->prepare("
    SELECT a.allocation_id, a.request_number, a.status, a.date,
           a.pickup_time, a.dropoff_time, a.pickup_location, a.dropoff_location,
           a.driver_id, c.brand, c.plate_number,
           d.name as driver_name
    FROM tbl_allocations a
    JOIN tbl_cars c ON a.car_id = c.car_id
    JOIN tbl_drivers d ON a.driver_id = d.driver_id
    WHERE a.date BETWEEN ? AND ? 
    AND a.status IN ('approved', 'in_progress')
    ORDER BY a.date, a.pickup_time
");
$trips->execute([$week_start, $week_end]);
$trips_data = $trips->fetchAll();

// Organize trips by day and driver Also only initialize for active drivers
$week_days = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . ' + ' . $i . ' days'));
    $week_days[$date] = [
        'date' => $date,
        'display' => date('D, M j', strtotime($date)),
        'drivers' => []
    ];
    

    foreach ($drivers as $driver) {
        $week_days[$date]['drivers'][$driver['driver_id']] = [
            'driver' => $driver,
            'trips' => []
        ];
    }
}

// Assign trips to drivers and days (only if driver is active)
foreach ($trips_data as $trip) {
    // Check if this driver is still active
    $driverActive = false;
    foreach ($drivers as $driver) {
        if ($driver['driver_id'] == $trip['driver_id']) {
            $driverActive = true;
            break;
        }
    }
    
    if ($driverActive && isset($week_days[$trip['date']]) && 
        isset($week_days[$trip['date']]['drivers'][$trip['driver_id']])) {
        $week_days[$trip['date']]['drivers'][$trip['driver_id']]['trips'][] = $trip;
    }
}

// Send a full weekly driver schedule with all trip assignments so the frontend can render a calendar view.
echo json_encode([
    'success' => true,
    'data' => [
        'week_days' => $week_days,
        'drivers' => $drivers,
        'week_start' => $week_start,
        'week_end' => $week_end,
        'total_trips' => count($trips_data),
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);