<?php
session_start();
require_once '../config/db.php';
require_once 'includes/trip_status_updater.php';

// If called directly (not via API), redirect back
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
    header('Location: dashboard.php');
    exit();
}

// If called via AJAX, return JSON
$result = updateTripStatuses($pdo);
echo json_encode([
    'success' => true,
    'cars_updated' => $result['cars_updated'],
    'cars_set_available' => $result['cars_set_available'],
    'message' => "Car statuses synced."
]);
exit();
?>