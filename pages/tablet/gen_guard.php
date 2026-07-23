<?php
session_start();
require_once '../../includes/load.php';

/**
 * Normalizes a user-submitted time (HH:MM or HH:MM:SS) into HH:MM:SS.
 * Mirrors requests.php's version so start/complete times behave identically.
 */
function normalizeTimeInput($value) {
    if (empty($value)) {
        return date('H:i:s');
    }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value, $m)) {
        $seconds = isset($m[4]) ? $m[4] : '00';
        return $m[1] . ':' . $m[2] . ':' . $seconds;
    }
    return date('H:i:s');
}

/**
 * Returns ['startable' => bool, 'reason' => string] for a given approved trip.
 * Mirrors requests.php's version exactly.
 */
function getTripStartability($pdo, $trip) {
    if ($trip['status'] !== 'approved') {
        return ['startable' => false, 'reason' => 'Trip is not approved.'];
    }
    if ($trip['date'] !== date('Y-m-d')) {
        return ['startable' => false, 'reason' => 'Trips can only be started on their scheduled day.'];
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocations WHERE driver_id = ? AND date = ? AND status = 'in_progress'");
    $check->execute([$trip['driver_id'], $trip['date']]);
    if ($check->fetchColumn() > 0) {
        return ['startable' => false, 'reason' => 'Driver already has a trip in progress.'];
    }

    $check2 = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocations WHERE car_id = ? AND date = ? AND status = 'in_progress'");
    $check2->execute([$trip['car_id'], $trip['date']]);
    if ($check2->fetchColumn() > 0) {
        return ['startable' => false, 'reason' => 'Car is currently in use on another trip.'];
    }

    $check3 = $pdo->prepare("SELECT COUNT(*) FROM tbl_cars WHERE car_id = ? AND status = 'under_maintenance'");
    $check3->execute([$trip['car_id']]);
    if ($check3->fetchColumn() > 0) {
        return ['startable' => false, 'reason' => 'Car is currently under maintenance.'];
    }

    $next = $pdo->prepare("
        SELECT allocation_id FROM tbl_allocations 
        WHERE driver_id = ? AND date = ? AND status = 'approved' 
        ORDER BY pickup_time ASC, allocation_id ASC LIMIT 1
    ");
    $next->execute([$trip['driver_id'], $trip['date']]);
    $next_id = $next->fetchColumn();

    if ((int)$next_id !== (int)$trip['allocation_id']) {
        return ['startable' => false, 'reason' => 'An earlier trip for this driver must be started first.'];
    }

    return ['startable' => true, 'reason' => ''];
}

// ============================================
// AJAX: Start Trip
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['start_trip_ajax'])) {
    $id = $_POST['allocation_id'];
    $actual_time = normalizeTimeInput($_POST['actual_time'] ?? null);

    $stmt = $pdo->prepare("SELECT * FROM tbl_allocations WHERE allocation_id = ?");
    $stmt->execute([$id]);
    $trip = $stmt->fetch();

    if (!$trip) {
        echo json_encode(['success' => false, 'message' => 'Trip not found.']);
        exit();
    }

    $check = getTripStartability($pdo, $trip);
    if (!$check['startable']) {
        echo json_encode(['success' => false, 'message' => $check['reason']]);
        exit();
    }

    $update = $pdo->prepare("UPDATE tbl_allocations SET status = 'in_progress', actual_pickup_time = ? WHERE allocation_id = ? AND status = 'approved'");
    $update->execute([$actual_time, $id]);

    if ($update->rowCount() > 0) {
        $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'in_use', status_updated_at = NOW() WHERE car_id = ?");
        $car_update->execute([$trip['car_id']]);

        $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'started', ?, ?, NOW())");
        $log->execute([$_SESSION['user_id'], $id, "Trip started by guard, actual pickup time set to $actual_time"]);

        echo json_encode(['success' => true, 'message' => 'Trip started!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Trip could not be started. It may have already changed status.']);
    }
    exit();
}

// ============================================
// AJAX: Complete In Progress Trip
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_inprogress_ajax'])) {
    $id = $_POST['allocation_id'];
    $actual_time = normalizeTimeInput($_POST['actual_time'] ?? null);

    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ? AND status = 'in_progress'");
    $car_stmt->execute([$id]);
    $allocation = $car_stmt->fetch();

    if ($allocation) {
        $car_id = $allocation['car_id'];

        $stmt = $pdo->prepare("UPDATE tbl_allocations SET status = 'completed', actual_dropoff_time = ? WHERE allocation_id = ? AND status = 'in_progress'");
        $stmt->execute([$actual_time, $id]);

        if ($stmt->rowCount() > 0) {
            $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'available', status_updated_at = NOW() WHERE car_id = ?");
            $car_update->execute([$car_id]);

            $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'completed', ?, ?, NOW())");
            $log->execute([$_SESSION['user_id'], $id, "Trip completed by guard, actual dropoff time set to $actual_time, car set to available"]);

            echo json_encode(['success' => true, 'message' => 'Trip marked as completed!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to complete trip or trip not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Trip not found or not in progress.']);
    }
    exit();
}

// ============================================
// AJAX: Refresh trip list (for auto-refresh, mirrors driver_schedule_status.php pattern)
// ============================================
if (isset($_GET['ajax_refresh'])) {
    header('Content-Type: application/json');

    $stmt = $pdo->prepare("
        SELECT a.*, c.brand, c.plate_number, d.name as driver_name, d.mobile as driver_mobile,
               COALESCE(u.full_name, u.username) as requestor
        FROM tbl_allocations a
        JOIN tbl_cars c ON a.car_id = c.car_id
        JOIN tbl_drivers d ON a.driver_id = d.driver_id
        JOIN tbl_users u ON a.requestor_id = u.user_id
        WHERE a.date = CURDATE() AND a.status IN ('approved', 'in_progress')
        ORDER BY FIELD(a.status, 'in_progress', 'approved'), a.pickup_time ASC
    ");
    $stmt->execute();
    $trips = $stmt->fetchAll();

    foreach ($trips as $key => $t) {
        $trips[$key]['startability'] = getTripStartability($pdo, $t);
    }

    echo json_encode(['success' => true, 'trips' => $trips]);
    exit();
}

// ============================================
// Initial page load data
// ============================================
$today_trips = $pdo->prepare("
    SELECT a.*, c.brand, c.plate_number, d.name as driver_name, d.mobile as driver_mobile,
           COALESCE(u.full_name, u.username) as requestor
    FROM tbl_allocations a
    JOIN tbl_cars c ON a.car_id = c.car_id
    JOIN tbl_drivers d ON a.driver_id = d.driver_id
    JOIN tbl_users u ON a.requestor_id = u.user_id
    WHERE a.date = CURDATE() AND a.status IN ('approved', 'in_progress')
    ORDER BY FIELD(a.status, 'in_progress', 'approved'), a.pickup_time ASC
");
$today_trips->execute();
$today_trips = $today_trips->fetchAll();

foreach ($today_trips as $key => $t) {
    $today_trips[$key]['startability'] = getTripStartability($pdo, $t);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Trip Monitoring - CARS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../admin/assets/css/admin.css">
    <link rel="stylesheet" href="../../pages/tablet/css/guard.css">
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="gen_guard.php" class="navbar-brand">CARS <span>Trip Monitoring</span></a>
            <div class="nav-links">
                <a href="gen_guard.php" class="active">Trips</a>
                <a href="guard_schedule.php">Schedule</a>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <div class="page-header">
            <h2>Today's Trips <span style="font-size:0.8rem; color:#6c757d; font-weight:normal;"><?= date('F j, Y') ?></span></h2>
        </div>

        <div id="guardTripList">
            <?php if (count($today_trips) > 0): ?>
                <?php foreach ($today_trips as $t): ?>
                    <div class="trip-card-guard status-<?= $t['status'] ?>" data-allocation-id="<?= $t['allocation_id'] ?>">
                        <div class="trip-card-guard-top">
                            <div class="trip-main-info">
                                <strong><?= htmlspecialchars($t['driver_name']) ?></strong>
                                — <?= htmlspecialchars($t['brand']) ?> (<?= htmlspecialchars($t['plate_number']) ?>)
                                <div class="trip-sub-info">
                                    <?= date('g:i A', strtotime($t['pickup_time'])) ?>
                                    <?= $t['dropoff_time'] ? ' – ' . date('g:i A', strtotime($t['dropoff_time'])) : '' ?>
                                    &middot; <?= htmlspecialchars($t['pickup_location']) ?><?= !empty($t['dropoff_location']) ? ' → ' . htmlspecialchars($t['dropoff_location']) : '' ?>
                                    &middot; Requested by <?= htmlspecialchars($t['requestor']) ?>
                                </div>
                                <?php if ($t['status'] === 'approved' && !$t['startability']['startable']): ?>
                                    <div class="trip-blocked-reason"><?= htmlspecialchars($t['startability']['reason']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="trip-actions">
                                <?php if ($t['status'] === 'approved'): ?>
                                    <button type="button" class="btn-guard-start"
                                        <?= $t['startability']['startable'] ? '' : 'disabled' ?>
                                        onclick="openGuardModal(<?= $t['allocation_id'] ?>, 'start')">
                                        Start
                                    </button>
                                <?php elseif ($t['status'] === 'in_progress'): ?>
                                    <button type="button" class="btn-guard-complete"
                                        onclick="openGuardModal(<?= $t['allocation_id'] ?>, 'complete')">
                                        Complete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">No trips scheduled for today.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Start/Complete confirmation modal -->
    <div class="guard-modal-overlay" id="guardModal">
        <div class="guard-modal-box">
            <h3 id="guardModalTitle">Start Trip</h3>
            <div class="form-group">
                <label for="guardModalTime" id="guardModalTimeLabel">Actual Departure</label>
                <input type="time" id="guardModalTime" required>
            </div>
            <div class="guard-modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeGuardModal()">Cancel</button>
                <button type="button" class="btn-guard-start" id="guardModalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    <script src="../../pages/tablet/js/guard.js"></script> 
</body>
</html>