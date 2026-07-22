<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
require_once '../../config/db.php';
require_admin();

header('Cache-Control: no-cache, must-revalidate');

$date = $_GET['date'] ?? date('Y-m-d');

// Get today's schedule with status updates
$schedule = $pdo->prepare("
    SELECT a.allocation_id, a.request_number, a.status, 
           a.pickup_time, a.dropoff_time, a.pickup_location, a.dropoff_location,
           c.brand, c.plate_number, d.name as driver_name,
           a.updated_at
    FROM tbl_allocations a
    JOIN tbl_cars c ON a.car_id = c.car_id
    JOIN tbl_drivers d ON a.driver_id = d.driver_id
    WHERE a.date = ? AND a.status IN ('approved', 'in_progress', 'completed')
    ORDER BY a.pickup_time
");
$schedule->execute([$date]);
$trips = $schedule->fetchAll();

// Get status counts
$counts = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM tbl_allocations 
    WHERE date = ?
");
$counts->execute([$date]);
$status_counts = $counts->fetch();

//Show the dispatch team all today's trips organized by status so they can monitor operations at a glance.
echo json_encode([
    'success' => true,
    'data' => [
        'trips' => $trips,
        'counts' => $status_counts,
        'date' => $date,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);