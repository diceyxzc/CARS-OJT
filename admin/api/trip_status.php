<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../../includes/load.php';
require_admin();

header('Cache-Control: no-cache, must-revalidate');

// ============================================================
// Every query below is written to match its dashboard.php
// counterpart EXACTLY (same WHERE clauses, same date ranges).
// If you change one, change the other, or they will drift again.
// ============================================================

try {
    $stats = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE status = 'pending') as pending,
            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE date = CURDATE() 
                AND status NOT IN ('cancelled', 'pending', 'declined')) as today_trips,
            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE status IN ('in_progress', 'completed') AND date = CURDATE()) as in_progress_today,
            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE status IN ('approved') AND date = CURDATE()) as approved_today,
            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE status = 'completed' AND date = CURDATE()) as completed_today,

            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE date BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) 
                                AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY) 
                AND status NOT IN ('cancelled', 'pending', 'declined')) as weekly_trips,

            (SELECT COUNT(*) FROM tbl_allocations 
                WHERE status = 'completed') as total_trips_month,

            (SELECT COUNT(*) FROM tbl_cars WHERE status = 'available') as available_cars,
            (SELECT COUNT(*) FROM tbl_cars) as total_cars,
            (SELECT COUNT(*) FROM tbl_cars WHERE status = 'in_use') as in_use_cars,
            (SELECT COUNT(*) FROM tbl_cars WHERE status = 'under_maintenance') as maintenance_cars
    ")->fetch();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]));
}

if (!$stats) {
    die(json_encode(['success' => false, 'message' => 'No stats returned from database']));
}

// Get cars in use
try {
    $cars_in_use = $pdo->query("
        SELECT c.car_id, c.brand,
               a.pickup_time, a.dropoff_time, a.pickup_location, a.dropoff_location,
               d.name as driver_name
        FROM tbl_allocations a
        JOIN tbl_cars c ON a.car_id = c.car_id
        JOIN tbl_drivers d ON a.driver_id = d.driver_id
        WHERE a.date = CURDATE() 
        AND a.status = 'in_progress'
        ORDER BY c.brand
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $cars_in_use = [];
}

// TODAY'S SCHEDULE
try {
    $today_schedule = $pdo->query("
        SELECT a.allocation_id, a.pickup_time, a.dropoff_time, a.pickup_location, a.dropoff_location, a.status, a.request_number,
               c.brand, c.plate_number, 
               d.name as driver_name, 
               COALESCE(u.full_name, u.username) as requestor
        FROM tbl_allocations a 
        JOIN tbl_cars c ON a.car_id = c.car_id 
        JOIN tbl_drivers d ON a.driver_id = d.driver_id 
        JOIN tbl_users u ON a.requestor_id = u.user_id
        WHERE a.date = CURDATE() AND a.status IN ('approved', 'in_progress', 'completed')
        ORDER BY FIELD(a.status, 'in_progress', 'approved', 'completed'), a.pickup_time
    ")->fetchAll();
} catch (Exception $e) {
    $today_schedule = [];
}

// Get passengers for each trip
foreach ($today_schedule as $key => $trip) {
    try {
        $pass_stmt = $pdo->prepare("
            SELECT p.passenger_name 
            FROM tbl_allocated_passengers ap 
            JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
            WHERE ap.allocation_id = ?
        ");
        $pass_stmt->execute([$trip['allocation_id']]);
        $today_schedule[$key]['passengers'] = $pass_stmt->fetchAll();
    } catch (Exception $e) {
        $today_schedule[$key]['passengers'] = [];
    }
}

// UPCOMING TRIPS
try {
    $upcoming_trips = $pdo->query("
        SELECT a.allocation_id, a.date, a.pickup_time, a.pickup_location, a.status, a.request_number,
               c.brand, c.plate_number, 
               d.name as driver_name
        FROM tbl_allocations a 
        JOIN tbl_cars c ON a.car_id = c.car_id 
        JOIN tbl_drivers d ON a.driver_id = d.driver_id 
        WHERE a.date > CURDATE() AND a.date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
        AND a.status IN ('approved', 'pending')
        ORDER BY a.date, a.pickup_time
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $upcoming_trips = [];
}

// Get today's schedule summary
try {
    $schedule_summary = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM tbl_allocations 
        WHERE date = CURDATE() AND status IN ('approved', 'in_progress', 'completed')
    ")->fetch();
} catch (Exception $e) {
    $schedule_summary = ['total' => 0, 'approved' => 0, 'in_progress' => 0, 'completed' => 0];
}

// Get upcoming trips count
try {
    $upcoming_count = $pdo->query("
        SELECT COUNT(*) 
        FROM tbl_allocations 
        WHERE date > CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
        AND status IN ('approved', 'pending')
    ")->fetchColumn();
} catch (Exception $e) {
    $upcoming_count = 0;
}

$total_trips = isset($stats['total_trips_month']) ? (int)$stats['total_trips_month'] : 0;

// Build response with correct Philippines time
date_default_timezone_set('Asia/Manila');
$response = [
    'success' => true,
    'data' => [
        'stats' => [
            'pending' => (int)$stats['pending'],
            'in_progress_today' => (int)$stats['in_progress_today'],
            'approved_today' => (int)($stats['approved_today']), 
            'today_trips' => (int)$stats['today_trips'],
            'completed_today' => (int)$stats['completed_today'],
            'weekly_trips' => (int)$stats['weekly_trips'],
            'total_trips_month' => (int)$stats['total_trips_month'],
            'available_cars' => (int)$stats['available_cars'],
            'total_cars' => (int)$stats['total_cars'],
            'in_use_cars' => (int)$stats['in_use_cars'],
            'maintenance_cars' => (int)$stats['maintenance_cars'],
            'upcoming' => (int)$upcoming_count
        ],
        'cars_in_use' => $cars_in_use,
        'schedule_summary' => $schedule_summary,
        'today_schedule' => $today_schedule,
        'upcoming_trips' => $upcoming_trips,
        'status_update' => isset($status_update) ? $status_update : null,
        'timestamp' => date('Y-m-d H:i:s')
    ]
];

//  Return JSON response and stop execution
echo json_encode($response);
exit();