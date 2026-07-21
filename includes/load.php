<?php
// includes/load.phpp

// 1. Start Session (but not for API if using token auth)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load Configuration
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

// 4. Define Base Path
define('BASE_PATH', realpath(__DIR__ . '/..'));

// 5. Set Timezone
date_default_timezone_set('Asia/Manila');

// 6. Error Reporting (only in development)
if (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'localhost') !== false) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

function updateTripStatuses($pdo) {
    $status_update = [
        'trips_updated' => 0,
        'trips_completed' => 0,
        'cars_updated' => 0,
        'cars_set_available' => 0
    ];

    try {
        // Get all cars that have an ACTUAL active trip (in_progress only —
        // 'approved' just means scheduled, the car isn't physically out yet)
        $cars = $pdo->query("
            SELECT DISTINCT car_id 
            FROM tbl_allocations 
            WHERE status = 'in_progress' 
            AND date = CURDATE()
        ")->fetchAll();

        // Set those cars to 'in_use'
        foreach ($cars as $car) {
            $update = $pdo->prepare("
                UPDATE tbl_cars 
                SET status = 'in_use', status_updated_at = NOW() 
                WHERE car_id = ?
            ");
            $update->execute([$car['car_id']]);
            $status_update['cars_updated'] += $update->rowCount();
        }

        // Set cars with no in_progress trip back to 'available' (unless under maintenance)
        $cars_with_no_active = $pdo->query("
            SELECT c.car_id 
            FROM tbl_cars c
            LEFT JOIN tbl_allocations a ON c.car_id = a.car_id 
                AND a.status = 'in_progress' 
                AND a.date = CURDATE()
            WHERE a.allocation_id IS NULL
            AND c.status = 'in_use'
        ")->fetchAll();

        foreach ($cars_with_no_active as $car) {
            $update = $pdo->prepare("
                UPDATE tbl_cars 
                SET status = 'available', status_updated_at = NOW() 
                WHERE car_id = ?
            ");
            $update->execute([$car['car_id']]);
            $status_update['cars_set_available'] += $update->rowCount();
        }

    } catch (Exception $e) {
        $status_update['error'] = $e->getMessage();
    }

    return $status_update;
}

$status_update = updateTripStatuses($pdo);

/**
 * Recomputes and persists a car's status from actual usage:
 * in_use if it has an active trip, otherwise available.
 * Call this any time a trip's car_id changes, a trip's status changes,
 * or a driver-car link is created/broken — anywhere the "truth" about
 * what a car is doing could have shifted.
 */
function updateCarStatus($pdo, $car_id) {
    if (!$car_id) return null;

    $check = $pdo->prepare("
        SELECT COUNT(*) FROM tbl_allocations 
        WHERE car_id = ? AND status = 'in_progress'
    ");
    $check->execute([$car_id]);
    $active_trips = $check->fetchColumn();

    $driver_check = $pdo->prepare("
        SELECT driver_id FROM tbl_drivers WHERE car_id = ? AND status = 'active'
    ");
    $driver_check->execute([$car_id]);
    $has_driver = $driver_check->fetchColumn() ? true : false;

    $new_status = $active_trips > 0 ? 'in_use' : 'available';

    $update = $pdo->prepare("
        UPDATE tbl_cars SET status = ?, status_updated_at = NOW() WHERE car_id = ?
    ");
    $update->execute([$new_status, $car_id]);

    return $new_status;
}

/**
 * True if car_id is currently linked to an active driver OTHER than
 * $exclude_driver_id (if given). Used to block double-assignment.
 */
function carHasOtherActiveDriver($pdo, $car_id, $exclude_driver_id = null) {
    if (!$car_id) return false;
    $sql = "SELECT driver_id FROM tbl_drivers WHERE car_id = ? AND status = 'active'";
    $params = [$car_id];
    if ($exclude_driver_id !== null) {
        $sql .= " AND driver_id != ?";
        $params[] = $exclude_driver_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}
?>