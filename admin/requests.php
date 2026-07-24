<?php
session_start();
require_once '../includes/load.php';
require_once '../includes/email_functions.php';
require_admin();

// Generate request number for admin direct allocation
function generateAdminRequestNumber($pdo) {
    $year = date('Y');
    $month = date('m');
    
    $stmt = $pdo->prepare("SELECT request_number FROM tbl_allocations 
                           WHERE request_number LIKE 'ADMIN-$year$month-%' 
                           ORDER BY request_number DESC LIMIT 1");
    $stmt->execute();
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = intval(substr($last, -4)) + 1;
    } else {
        $num = 1;
    }
    
    return "ADMIN-$year$month-" . str_pad($num, 4, '0', STR_PAD_LEFT);
}

$passengers = $pdo->prepare("SELECT * FROM tbl_passengers WHERE created_by = ? ORDER BY passenger_name");
$passengers->execute([$_SESSION['user_id']]);
$passengers = $passengers->fetchAll();

// Get pending requests WITHOUT driver/car assigned (guest requests)
$pending = $pdo->query("
    SELECT a.*, COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email 
    FROM tbl_allocations a 
    JOIN tbl_users u ON a.requestor_id = u.user_id 
    WHERE a.status = 'pending' AND (a.driver_id IS NULL OR a.car_id IS NULL)
    ORDER BY a.created_at DESC
")->fetchAll();

// Get all drivers with their car info
$all_drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking, c.coding_day 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active' 
    ORDER BY d.name
")->fetchAll();

$all_cars = $pdo->query("SELECT * FROM tbl_cars ORDER BY brand")->fetchAll();

$all_drivers_with_car = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking, c.coding_day, c.capacity 
    FROM tbl_drivers d 
    JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active' AND c.status != 'under_maintenance'
    ORDER BY d.name
")->fetchAll();

$active_tab = $_GET['tab'] ?? 'direct';

// Date filters for the Approved Trips / Outgoing Trips driver-card views
$approved_date = $_GET['approved_date'] ?? date('Y-m-d');
$outgoing_date = $_GET['outgoing_date'] ?? date('Y-m-d');

// Groups a flat trips array into: [driver_id => ['driver_id','driver_name','driver_mobile','car_brand','car_plate','trips'=>[...]]]
function groupTripsByDriver($trips) {
    $map = [];
    foreach ($trips as $t) {
        $did = $t['driver_id'];
        if (!isset($map[$did])) {
            $map[$did] = [
                'driver_id' => $did,
                'driver_name' => $t['driver_name'],
                'driver_mobile' => $t['driver_mobile'] ?? '',
                'car_brand' => $t['brand'],
                'car_plate' => $t['plate_number'],
                'trips' => []
            ];
        }
        $map[$did]['trips'][] = $t;
    }
    return $map;
}

// Renders the driver-card list (used for both Approved and Outgoing tabs, and the AJAX refresh below)
// $mode controls what shows in the mini trip preview under each driver's name:
//   'approved' -> shows up to 3 trips (all trips here are 'approved' status anyway)
//   'outgoing' -> shows only 'in_progress' trips, up to 3
function renderDriverCardsHtml($drivers, $modalFn, $mode = 'approved') {
    ob_start();
    if (count($drivers) > 0):
        foreach ($drivers as $driver):
            $all_trips = $driver['trips'];

            if ($mode === 'outgoing') {
                $mini_trips = array_values(array_filter($all_trips, function ($t) {
                    return $t['status'] === 'in_progress';
                }));
            } else {
                $mini_trips = $all_trips;
            }

            $recent_trips = array_slice($mini_trips, 0, 3);
            $remaining_count = count($mini_trips) - count($recent_trips);
        ?>
            <div class="admin-driver-card" onclick="<?= $modalFn ?>(<?= htmlspecialchars(json_encode($driver)) ?>)">
                <div class="driver-card-main">
                    <div class="driver-status-dot active"></div>
                    <div class="driver-info">
                        <span class="driver-name">
                            <?= htmlspecialchars($driver['driver_name']) ?>
                            <span class="driver-car-tag"><?= htmlspecialchars($driver['car_brand']) ?> (<?= htmlspecialchars($driver['car_plate']) ?>)</span>
                            <span class="trip-count-pill"><?= count($all_trips) ?></span>
                        </span>
                        <span class="driver-mobile"><?= htmlspecialchars($driver['driver_mobile']) ?></span>
                    </div>
                    <span class="badge-active">Active</span>
                </div>
                <?php if (count($recent_trips) > 0): ?>
                    <div class="driver-mini-trips">
                        <?php foreach ($recent_trips as $trip): ?>
                            <div class="driver-mini-trip-row" onclick="event.stopPropagation(); openTripModal(<?= htmlspecialchars(json_encode($trip)) ?>)">
                                <span class="mini-trip-time"><?= date('g:i A', strtotime($trip['pickup_time'])) ?></span>
                                <span class="mini-trip-loc"><?= htmlspecialchars($trip['pickup_location']) ?><?= !empty($trip['dropoff_location']) ? ' → ' . htmlspecialchars($trip['dropoff_location']) : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($remaining_count > 0): ?>
                            <div class="driver-mini-trip-more">+<?= $remaining_count ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($mode === 'outgoing'): ?>
                    <div class="driver-mini-trips">
                        <div class="driver-mini-trip-empty">No trips currently in progress</div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach;
    else: ?>
        <div class="empty-state" style="padding:20px 0;">
            <p class="text-muted" style="font-size:0.85rem;">No trips for this date.</p>
        </div>
    <?php endif;
    return ob_get_clean();
}

// Get sort parameters for outgoing trips
$outgoing_sort = $_GET['outgoing_sort'] ?? 'date';
$outgoing_order = $_GET['outgoing_order'] ?? 'DESC';

// Define allowed sort columns to prevent SQL injection
$allowed_sort_columns = ['date', 'brand', 'driver_name', 'driver_mobile', 'pickup_time', 'dropoff_time', 'pickup_location', 'dropoff_location', 'requestor', 'status'];
$allowed_order = ['ASC', 'DESC'];

if (!in_array($outgoing_sort, $allowed_sort_columns)) {
    $outgoing_sort = 'date';
}
if (!in_array($outgoing_order, $allowed_order)) {
    $outgoing_order = 'DESC';
}

// Approve request with driver assignment - now using POST with modal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['approve_request'])) {
    $id = $_POST['allocation_id'];
    $driver_id = $_POST['driver_id'];
    
    // Get the car assigned to this driver
    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_drivers WHERE driver_id = ?");
    $car_stmt->execute([$driver_id]);
    $car_data = $car_stmt->fetch();
    
    if (!$car_data || !$car_data['car_id']) {
        $error = "Selected driver does not have a car assigned.";
        header('Location: requests.php?tab=requests&error=' . urlencode($error));
        exit();
    }
    
    $car_id = $car_data['car_id'];

    if (carHasOtherActiveDriver($pdo, $car_id, $driver_id)) {
        $error = "This car is linked to more than one active driver — please fix the assignment before approving.";
        header('Location: requests.php?tab=requests&error=' . urlencode($error));
        exit();
    }
    
    $stmt = $pdo->prepare("UPDATE tbl_allocations SET status = 'approved', approved_by = ?, driver_id = ?, car_id = ? WHERE allocation_id = ?");
    $stmt->execute([$_SESSION['user_id'], $driver_id, $car_id, $id]);
    
    $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'approved', ?, 'Request approved with driver/car assigned', NOW())");
    $log->execute([$_SESSION['user_id'], $id]);
    
    // ---- Send approval email to requestor ----
    $email_stmt = $pdo->prepare("
        SELECT a.*, COALESCE(u.full_name, u.username) as requestor_name, u.email as requestor_email,
            d.name as driver_name, d.mobile as driver_mobile,
            c.brand, c.plate_number, c.parking,
            CASE WHEN a.remarks LIKE '%Purpose:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                ELSE NULL END as purpose,
            CASE WHEN a.remarks LIKE '%Travel Type:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Travel Type:', -1), '|', 1))
                ELSE NULL END as travel_type
        FROM tbl_allocations a
        JOIN tbl_users u ON a.requestor_id = u.user_id
        JOIN tbl_drivers d ON a.driver_id = d.driver_id
        JOIN tbl_cars c ON a.car_id = c.car_id
        WHERE a.allocation_id = ?
    ");
    $email_stmt->execute([$id]);
    $allocation = $email_stmt->fetch();

    if ($allocation && !empty($allocation['requestor_email'])) {
        $pass_stmt = $pdo->prepare("
            SELECT p.passenger_name
            FROM tbl_allocated_passengers ap
            JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id
            WHERE ap.allocation_id = ?
        ");
        $pass_stmt->execute([$id]);
        $passengers_list = $pass_stmt->fetchAll();

        $requestData = [
            'request_number' => $allocation['request_number'],
            'requestor_name' => $allocation['requestor_name'],
            'date' => $allocation['date'],
            'pickup_time' => $allocation['pickup_time'],
            'dropoff_time' => $allocation['dropoff_time'],
            'pickup_location' => $allocation['pickup_location'],
            'dropoff_location' => $allocation['dropoff_location'],
            'travel_type' => $allocation['travel_type'],
            'purpose' => $allocation['purpose']
        ];
        $driverData = ['name' => $allocation['driver_name'], 'mobile' => $allocation['driver_mobile']];
        $carData = ['brand' => $allocation['brand'], 'plate_number' => $allocation['plate_number'], 'parking' => $allocation['parking']];

        $subject = "Car Request Approved - {$allocation['request_number']}";
        $body = buildRequestApprovedEmail($requestData, $driverData, $carData, $passengers_list);
        sendEmail($allocation['requestor_email'], $subject, $body, false);
    }

    $success = "Request approved successfully!";
    header('Location: requests.php?tab=requests&success=' . urlencode($success));
    exit();
}

if (isset($_GET['reject'])) {
    $id = $_GET['reject'];
    $reason = $_GET['reason'] ?? 'No reason given';
    $stmt = $pdo->prepare("UPDATE tbl_allocations SET status = 'declined', approved_by = ? WHERE allocation_id = ?");
    $stmt->execute([$_SESSION['user_id'], $id]);
    $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'rejected', ?, ?, NOW())");
    $log->execute([$_SESSION['user_id'], $id, "Rejected: " . $reason]);

    // ---- Send rejection email to requestor ----
    $email_stmt = $pdo->prepare("
        SELECT a.*, COALESCE(u.full_name, u.username) as requestor_name, u.email as requestor_email,
               CASE WHEN a.remarks LIKE '%Purpose:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                    ELSE NULL END as purpose
        FROM tbl_allocations a
        JOIN tbl_users u ON a.requestor_id = u.user_id
        WHERE a.allocation_id = ?
    ");
    $email_stmt->execute([$id]);
    $allocation = $email_stmt->fetch();

    if ($allocation && !empty($allocation['requestor_email'])) {
        $requestData = [
            'request_number' => $allocation['request_number'],
            'requestor_name' => $allocation['requestor_name'],
            'date' => $allocation['date'],
            'pickup_time' => $allocation['pickup_time'],
            'pickup_location' => $allocation['pickup_location'],
            'purpose' => $allocation['purpose']
        ];
        $subject = "Car Request Declined - {$allocation['request_number']}";
        $body = buildRequestRejectedEmail($requestData, $reason);
        sendEmail($allocation['requestor_email'], $subject, $body, false);
    }

    header('Location: requests.php?tab=requests');
    exit();
}

if (isset($_POST['add_passenger_ajax'])) {
    $name = trim($_POST['passenger_name']);
    $contact = trim($_POST['contact'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    $check = $pdo->prepare("SELECT passenger_id FROM tbl_passengers WHERE passenger_name = ? AND created_by = ?");
    $check->execute([$name, $user_id]);
    if ($check->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have this passenger']);
        exit();
    }
    
    $stmt = $pdo->prepare("INSERT INTO tbl_passengers (passenger_name, contact, created_by) VALUES (?, ?, ?)");
    $stmt->execute([$name, $contact, $user_id]);
    $new_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true, 
        'passenger_id' => $new_id, 
        'passenger_name' => $name,
        'contact' => $contact
    ]);
    exit();
}

if (isset($_POST['delete_passenger_ajax'])) {
    $passenger_id = $_POST['passenger_id'];
    $user_id = $_SESSION['user_id'];
    
    $check = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tbl_allocated_passengers ap
        JOIN tbl_allocations a ON a.allocation_id = ap.allocation_id
        WHERE ap.passenger_id = ? AND a.status NOT IN ('cancelled', 'declined')
    ");
    $check->execute([$passenger_id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Cannot delete: Passenger is assigned to $count active trip(s). Remove from trips first."
        ]);
        exit();
    }
    
    $stmt = $pdo->prepare("DELETE FROM tbl_passengers WHERE passenger_id = ? AND created_by = ?");
    $stmt->execute([$passenger_id, $user_id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Passenger deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'You can only delete passengers you created']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['direct'])) {
    if (empty($_POST['car_id'])) {
        $error = "Please select a driver with an assigned car.";
    } elseif (empty(trim($_POST['travel_type'] ?? ''))) {
        $error = "Please select a travel type.";
    } elseif (empty($_POST['passengers']) || !is_array($_POST['passengers']) || count($_POST['passengers']) === 0) {
        $error = "Please select at least one passenger.";
    } else {
        $car_check = $pdo->prepare("SELECT car_id FROM tbl_cars WHERE car_id = ?");
        $car_check->execute([$_POST['car_id']]);
        if ($car_check->rowCount() == 0) {
            $error = "Selected car does not exist. Please assign a car to the driver first.";
        }
    }
    
    if (!isset($error)) {
        // Check if bulk date range is used
        $date_type = $_POST['date_type'] ?? 'single';
        $dates_to_insert = [];
        
        if ($date_type == 'range') {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            
            if ($start_date && $end_date) {
                $current = strtotime($start_date);
                $end = strtotime($end_date);
                
                while ($current <= $end) {
                    $dates_to_insert[] = date('Y-m-d', $current);
                    $current = strtotime('+1 day', $current);
                }
            } else {
                $error = "Please select both start and end dates.";
            }
        } else {
            // Single date
            $dates_to_insert[] = $_POST['date'];
        }
        
        if (!isset($error) && count($dates_to_insert) > 0) {
            $allocation_ids = [];
            $success_count = 0;
            $skipped_dates = [];

            $range_dropoff = $_POST['dropoff_time'] ?? date('H:i:s', strtotime($_POST['pickup_time'] . ' + 1 hour'));
            $car_coding_stmt = $pdo->prepare("SELECT coding_day FROM tbl_cars WHERE car_id = ?");
            $car_coding_stmt->execute([$_POST['car_id']]);
            $car_coding_day = $car_coding_stmt->fetchColumn();

            foreach ($dates_to_insert as $date) {
                // Skips coding dates in date range
                if ($date_type === 'range' && !empty($car_coding_day)) {
                    $day_of_week = date('l', strtotime($date)); // e.g. "Monday"
                    if (strcasecmp($car_coding_day, $day_of_week) === 0) {
                        $skipped_dates[] = date('M d', strtotime($date)) . ' (coding day)';
                        continue;
                    }
                }
                
                // Check if car is available
                $check = $pdo->prepare("SELECT * FROM tbl_allocations WHERE car_id = ? AND date = ? AND status IN ('pending', 'approved', 'in_progress') AND pickup_time < ? AND dropoff_time > ?");
                $check->execute([$_POST['car_id'], $date, $range_dropoff, $_POST['pickup_time']]);
                if ($check->rowCount() > 0) {
                    $skipped_dates[] = date('M d', strtotime($date)) . ' (car busy)';
                    continue;
                }

                // Check if driver is available
                $check2 = $pdo->prepare("SELECT * FROM tbl_allocations WHERE driver_id = ? AND date = ? AND status IN ('pending', 'approved', 'in_progress') AND pickup_time < ? AND dropoff_time > ?");
                $check2->execute([$_POST['driver_id'], $date, $range_dropoff, $_POST['pickup_time']]);
                if ($check2->rowCount() > 0) {
                    $skipped_dates[] = date('M d', strtotime($date)) . ' (driver busy)';
                    continue;
                }
                
                // Generate admin request number
                $request_number = generateAdminRequestNumber($pdo);
                

                $travel_type_input = trim($_POST['travel_type'] ?? '');
                $remarks_input = trim($_POST['remarks'] ?? '');
                $combined_remarks_parts = [];
                if ($travel_type_input !== '') {
                    $combined_remarks_parts[] = 'Travel Type: ' . $travel_type_input;
                }
                if ($remarks_input !== '') {
                    $combined_remarks_parts[] = 'Purpose: ' . $remarks_input;
                }
                $combined_remarks = implode(' | ', $combined_remarks_parts);
                $start_now = isset($_POST['start_now']) && $date_type !== 'range';
                $initial_status = $start_now ? 'in_progress' : 'approved';
                $actual_pickup = $start_now ? normalizeTimeInput($_POST['pickup_time']) : null;

                $stmt = $pdo->prepare("INSERT INTO tbl_allocations (car_id, driver_id, requestor_id, approved_by, date, pickup_time, dropoff_time, pickup_location, dropoff_location, remarks, status, request_number, actual_pickup_time, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $_POST['car_id'], 
                    $_POST['driver_id'], 
                    $_SESSION['user_id'], 
                    $_SESSION['user_id'],
                    $date,
                    $_POST['pickup_time'],
                    $_POST['dropoff_time'] ?? null,
                    $_POST['pickup_location'],
                    $_POST['dropoff_location'],
                    $combined_remarks,
                    $initial_status,
                    $request_number,
                    $actual_pickup
                ]);
                $allocation_id = $pdo->lastInsertId();
                $allocation_ids[] = $allocation_id;

                if ($start_now) {
                    $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'in_use', status_updated_at = NOW() WHERE car_id = ?");
                    $car_update->execute([$_POST['car_id']]);
                }
                
                // Insert passengers for this allocation
                if (isset($_POST['passengers']) && is_array($_POST['passengers'])) {
                    foreach ($_POST['passengers'] as $passenger_id) {
                        $pdo->prepare("INSERT INTO tbl_allocated_passengers (allocation_id, passenger_id) VALUES (?, ?)")->execute([$allocation_id, $passenger_id]);
                    }
                }
                
                $log_detail = $start_now ? "Direct allocation for $date (started immediately)" : "Direct allocation for $date";
                $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'created', ?, ?, NOW())");
                $log->execute([$_SESSION['user_id'], $allocation_id, $log_detail]);
                $success_count++;
            }
            
            if ($success_count > 0) {
                if ($date_type == 'range') {
                    $success = "Successfully created $success_count trip(s) from " . date('M d', strtotime($start_date)) . " to " . date('M d, Y', strtotime($end_date)) . "!";
                    if (!empty($skipped_dates)) {
                        $success .= " Skipped: " . implode(', ', $skipped_dates) . ".";
                    }
                } else {
                    $success = "Trip created successfully!";
                }
            } else {
                if ($date_type == 'range') {
                    $error = "No trips could be created. Conflicts on: " . implode(', ', $skipped_dates) . ".";
                } else {
                    $error = "Trip could not be created. The car or driver may be unavailable at this time.";
                }
            }
            $active_tab = 'direct';
        }
    }
}

if (isset($_POST['delete_unused_passengers_ajax'])) {
    $user_id = $_SESSION['user_id'];

    // Find passengers created by this user that have zero *active* allocations.
    // Passengers only linked to cancelled/declined trips should still count as unused.
    $stmt = $pdo->prepare("
        SELECT p.passenger_id 
        FROM tbl_passengers p
        LEFT JOIN tbl_allocated_passengers ap ON ap.passenger_id = p.passenger_id
        LEFT JOIN tbl_allocations a ON a.allocation_id = ap.allocation_id 
            AND a.status NOT IN ('cancelled', 'declined')
        WHERE p.created_by = ?
        GROUP BY p.passenger_id
        HAVING COUNT(a.allocation_id) = 0
    ");
    $stmt->execute([$user_id]);
    $unused_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($unused_ids)) {
        echo json_encode(['success' => true, 'deleted_count' => 0, 'message' => 'No unused passengers to remove.']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($unused_ids), '?'));
    $del = $pdo->prepare("DELETE FROM tbl_passengers WHERE passenger_id IN ($placeholders) AND created_by = ?");
    $del->execute(array_merge($unused_ids, [$user_id]));

    $deleted_count = $del->rowCount();

    $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'deleted', NULL, ?, NOW())");
    $log->execute([$user_id, "Bulk deleted $deleted_count unused passenger(s)"]);

    echo json_encode([
        'success' => true, 
        'deleted_count' => $deleted_count, 
        'deleted_ids' => $unused_ids,
        'message' => "Removed $deleted_count unused passenger(s)."
    ]);
    exit();
}

// Get approved trips with more details, scoped to the selected date
$approved_stmt = $pdo->prepare("
    SELECT a.*, a.request_number,
           c.brand, c.plate_number, 
           d.name as driver_name, d.mobile as driver_mobile, 
           COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email,
           CASE WHEN a.remarks LIKE '%Purpose:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                ELSE NULL END as purpose,
           CASE WHEN a.remarks LIKE '%Travel Type:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Travel Type:', -1), '|', 1))
                ELSE NULL END as travel_type
    FROM tbl_allocations a 
    JOIN tbl_cars c ON a.car_id = c.car_id 
    JOIN tbl_drivers d ON a.driver_id = d.driver_id 
    JOIN tbl_users u ON a.requestor_id = u.user_id 
    WHERE a.status = 'approved' AND a.date = ?
    ORDER BY a.pickup_time
");
$approved_stmt->execute([$approved_date]);
$approved_trips = $approved_stmt->fetchAll();

// Get passengers for each approved trip
foreach ($approved_trips as $key => $t) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$t['allocation_id']]);
    $approved_trips[$key]['passengers'] = $pass_stmt->fetchAll();

    // NEW: compute whether this trip can be started right now
    $approved_trips[$key]['startability'] = getTripStartability($pdo, $t);
}

$approved_drivers = groupTripsByDriver($approved_trips);

/**
 * Normalizes a user-submitted time (HH:MM or HH:MM:SS) into HH:MM:SS.
 * Falls back to the current time if missing or malformed — never trusts
 * the raw client value directly into the query.
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

// Trip Details now shows the selected date's trips - ONLY in_progress, completed, and pending (NO approved)
$outgoing_stmt = $pdo->prepare("
    SELECT a.*, a.request_number,
           c.brand, c.plate_number, 
           d.name as driver_name, d.mobile as driver_mobile,
           COALESCE(u.full_name, u.username) as requestor,
           CASE WHEN a.remarks LIKE '%Purpose:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                ELSE NULL END as purpose
    FROM tbl_allocations a 
    JOIN tbl_cars c ON a.car_id = c.car_id 
    JOIN tbl_drivers d ON a.driver_id = d.driver_id 
    JOIN tbl_users u ON a.requestor_id = u.user_id 
    WHERE a.status IN ('in_progress', 'completed', 'pending')
      AND a.date = ?
    ORDER BY FIELD(a.status, 'in_progress', 'completed', 'pending'), a.pickup_time ASC
");
$outgoing_stmt->execute([$outgoing_date]);
$outgoing_trips = $outgoing_stmt->fetchAll();

// Complete In Progress trip via AJAX (no page reload)
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
            $log->execute([$_SESSION['user_id'], $id, "Trip completed via AJAX, actual dropoff time set to $actual_time, car set to available"]);

            echo json_encode(['success' => true, 'message' => 'Trip marked as completed successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to complete trip or trip not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Trip not found or not in progress.']);
    }
    exit();
}

// Check if this is an AJAX request for outgoing trips (driver-card refresh, scoped to a date)
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['tab']) && $_GET['tab'] == 'outgoing') {
    $ajax_outgoing_date = $_GET['outgoing_date'] ?? date('Y-m-d');

    $ajax_out_stmt = $pdo->prepare("
        SELECT a.*, a.request_number,
               c.brand, c.plate_number, 
               d.name as driver_name, d.mobile as driver_mobile,
               COALESCE(u.full_name, u.username) as requestor,
               CASE WHEN a.remarks LIKE '%Purpose:%'
                THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                ELSE NULL END as purpose
        FROM tbl_allocations a 
        JOIN tbl_cars c ON a.car_id = c.car_id 
        JOIN tbl_drivers d ON a.driver_id = d.driver_id 
        JOIN tbl_users u ON a.requestor_id = u.user_id 
        WHERE a.status IN ('in_progress', 'completed', 'pending')
          AND a.date = ?
        ORDER BY FIELD(a.status, 'in_progress', 'completed', 'pending'), a.pickup_time ASC
    ");
    $ajax_out_stmt->execute([$ajax_outgoing_date]);
    $outgoing_trips_ajax = $ajax_out_stmt->fetchAll();

    foreach ($outgoing_trips_ajax as $key => $t) {
        $pass_stmt = $pdo->prepare("
            SELECT p.passenger_name 
            FROM tbl_allocated_passengers ap 
            JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
            WHERE ap.allocation_id = ?
        ");
        $pass_stmt->execute([$t['allocation_id']]);
        $outgoing_trips_ajax[$key]['passengers'] = $pass_stmt->fetchAll();
    }

    $ajax_outgoing_drivers = groupTripsByDriver($outgoing_trips_ajax);

    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo json_encode(array_values($ajax_outgoing_drivers));
        exit();
    }

    echo renderDriverCardsHtml($ajax_outgoing_drivers, 'openOutgoingDriverModal', 'outgoing');
    exit();
}

// Get passengers for each trip
foreach ($outgoing_trips as $key => $t) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$t['allocation_id']]);
    $outgoing_trips[$key]['passengers'] = $pass_stmt->fetchAll();
}

$outgoing_drivers = groupTripsByDriver($outgoing_trips);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $current = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ?");
    $current->execute([$_POST['allocation_id']]);
    $current_data = $current->fetch();
    
    $car_id = !empty($_POST['car_id']) ? $_POST['car_id'] : $current_data['car_id'];
    
    if (!empty($car_id)) {
        $car_check = $pdo->prepare("SELECT car_id FROM tbl_cars WHERE car_id = ?");
        $car_check->execute([$car_id]);
        if ($car_check->rowCount() == 0) {
            $error = "Selected car does not exist.";
        }
    } else {
        $error = "No car assigned to this trip. Please select a car.";
    }
    
    if (!isset($error)) {
        $stmt = $pdo->prepare("UPDATE tbl_allocations SET car_id = ?, driver_id = ?, date = ?, pickup_time = ?, dropoff_time = ?, pickup_location = ?, dropoff_location = ? WHERE allocation_id = ?");
        $stmt->execute([
            $car_id,
            $_POST['driver_id'],
            $_POST['date'],
            $_POST['pickup_time'],
            $_POST['dropoff_time'] ?? null,
            $_POST['pickup_location'],
            $_POST['dropoff_location'],
            $_POST['allocation_id']
        ]);
        $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'updated', ?, 'Trip details updated', NOW())");
        $log->execute([$_SESSION['user_id'], $_POST['allocation_id']]);
        $success = "Trip updated!";
        header('Location: requests.php?tab=approved');
        exit();
    }
}

// Complete In Progress trip via GET (for the Trip Details tab)
if (isset($_GET['complete_inprogress'])) {
    $id = $_GET['complete_inprogress'];
    $actual_time = normalizeTimeInput($_GET['actual_time'] ?? null);

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
            $log->execute([$_SESSION['user_id'], $id, "Trip completed manually, actual dropoff time set to $actual_time, car set to available"]);
            $success = "Trip marked as completed successfully!";
        } else {
            $error = "Failed to complete trip or trip not found.";
        }
    } else {
        $error = "Trip not found or not in progress.";
    }
    header('Location: requests.php?tab=outgoing');
    exit();
}

// Cancel Approved trip - sets status to 'cancelled' (not deleted)
if (isset($_GET['cancel_approved'])) {
    $id = $_GET['cancel_approved'];
    
    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ? AND status = 'approved'");
    $car_stmt->execute([$id]);
    $allocation = $car_stmt->fetch();
    
    if ($allocation) {
        $car_id = $allocation['car_id'];
        
        $stmt = $pdo->prepare("UPDATE tbl_allocations SET status = 'cancelled' WHERE allocation_id = ? AND status = 'approved'");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocations WHERE car_id = ? AND status IN ('approved', 'in_progress')");
            $check->execute([$car_id]);
            $count = $check->fetchColumn();
            
            if ($count == 0) {
                $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'available', status_updated_at = NOW() WHERE car_id = ?");
                $car_update->execute([$car_id]);
            }
            
            $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'cancelled', ?, 'Approved trip cancelled', NOW())");
            $log->execute([$_SESSION['user_id'], $id]);

            // ---- Send cancellation email to requestor ----
            $email_stmt = $pdo->prepare("
                SELECT a.*, COALESCE(u.full_name, u.username) as requestor_name, u.email as requestor_email,
                    CASE WHEN a.remarks LIKE '%Purpose:%'
                        THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                        ELSE NULL END as purpose
                FROM tbl_allocations a
                JOIN tbl_users u ON a.requestor_id = u.user_id
                WHERE a.allocation_id = ?
            ");
            $email_stmt->execute([$id]);
            $allocation_full = $email_stmt->fetch();

            if ($allocation_full && !empty($allocation_full['requestor_email'])) {
                if ($allocation_full['requestor_id'] != $_SESSION['user_id']) {
                    $requestData = [
                        'request_number'   => $allocation_full['request_number'],
                        'date'             => $allocation_full['date'],
                        'pickup_time'      => $allocation_full['pickup_time'],
                        'dropoff_time'     => $allocation_full['dropoff_time'],
                        'pickup_location'  => $allocation_full['pickup_location'],
                        'dropoff_location' => $allocation_full['dropoff_location'],
                        'purpose'          => $allocation_full['purpose']
                    ];
                    $subject = "Trip Cancelled - {$allocation_full['request_number']}";
                    $body = buildTripCancelledEmail($requestData);
                    sendEmail($allocation_full['requestor_email'], $subject, $body, false);
                }
            }

            $success = "Approved trip cancelled successfully!";
        } else {
            $error = "Failed to cancel trip or trip not found.";
        }
    } else {
        $error = "Trip not found or not approved.";
    }
    header('Location: requests.php?tab=approved');
    exit();
}

// Cancel In Progress trip - sets status to 'cancelled' (not deleted)
if (isset($_GET['cancel_inprogress'])) {
    $id = $_GET['cancel_inprogress'];
    
    // Get the car_id first
    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ? AND status = 'in_progress'");
    $car_stmt->execute([$id]);
    $allocation = $car_stmt->fetch();
    
    if ($allocation) {
        $car_id = $allocation['car_id'];
        
        // Update status to 'cancelled' instead of deleting
        $stmt = $pdo->prepare("UPDATE tbl_allocations SET status = 'cancelled' WHERE allocation_id = ? AND status = 'in_progress'");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            // Update car status back to 'available' if no other active trips
            $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocations WHERE car_id = ? AND status IN ('approved', 'in_progress')");
            $check->execute([$car_id]);
            $count = $check->fetchColumn();
            
            if ($count == 0) {
                $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'available', status_updated_at = NOW() WHERE car_id = ?");
                $car_update->execute([$car_id]);
            }
            
            $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'cancelled', ?, 'In Progress trip cancelled', NOW())");
            $log->execute([$_SESSION['user_id'], $id]);

            // ---- Send cancellation email to requestor ----
            $email_stmt = $pdo->prepare("
                SELECT a.*, COALESCE(u.full_name, u.username) as requestor_name, u.email as requestor_email,
                    CASE WHEN a.remarks LIKE '%Purpose:%'
                        THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                        ELSE NULL END as purpose
                FROM tbl_allocations a
                JOIN tbl_users u ON a.requestor_id = u.user_id
                WHERE a.allocation_id = ?
            ");
            $email_stmt->execute([$id]);
            $allocation_full = $email_stmt->fetch();

            if ($allocation_full && !empty($allocation_full['requestor_email'])) {
                if ($allocation_full['requestor_id'] != $_SESSION['user_id']) {
                    $requestData = [
                        'request_number'   => $allocation_full['request_number'],
                        'date'             => $allocation_full['date'],
                        'pickup_time'      => $allocation_full['pickup_time'],
                        'dropoff_time'     => $allocation_full['dropoff_time'],
                        'pickup_location'  => $allocation_full['pickup_location'],
                        'dropoff_location' => $allocation_full['dropoff_location'],
                        'purpose'          => $allocation_full['purpose']
                    ];
                    $subject = "Trip Cancelled - {$allocation_full['request_number']}";
                    $body = buildTripCancelledEmail($requestData);
                    sendEmail($allocation_full['requestor_email'], $subject, $body, false);
                }
            }

            $success = "In Progress trip cancelled successfully!";
        } else {
            $error = "Failed to cancel trip or trip not found.";
        }
    } else {
        $error = "Trip not found or not in progress.";
    }
    header('Location: requests.php?tab=outgoing');
    exit();
}

if (isset($_GET['start_trip'])) {
    $id = $_GET['start_trip'];
    $actual_time = normalizeTimeInput($_GET['actual_time'] ?? null);

    $stmt = $pdo->prepare("SELECT * FROM tbl_allocations WHERE allocation_id = ?");
    $stmt->execute([$id]);
    $trip = $stmt->fetch();

    if (!$trip) {
        $error = "Trip not found.";
    } else {
        $check = getTripStartability($pdo, $trip);
        if (!$check['startable']) {
            $error = $check['reason'];
        } else {
            $update = $pdo->prepare("UPDATE tbl_allocations SET status = 'in_progress', actual_pickup_time = ? WHERE allocation_id = ? AND status = 'approved'");
            $update->execute([$actual_time, $id]);

            if ($update->rowCount() > 0) {
                $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'in_use', status_updated_at = NOW() WHERE car_id = ?");
                $car_update->execute([$trip['car_id']]);

                $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'started', ?, ?, NOW())");
                $log->execute([$_SESSION['user_id'], $id, "Trip started manually, actual pickup time set to $actual_time"]);

                $success = "Trip started!";
            } else {
                $error = "Trip could not be started. It may have already changed status.";
            }
        }
    }

    $redirect = 'requests.php?tab=approved';
    $redirect .= isset($success) ? '&success=' . urlencode($success) : '&error=' . urlencode($error);
    header('Location: ' . $redirect);
    exit();
}

// Start Trip via AJAX (no page reload)
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
        $log->execute([$_SESSION['user_id'], $id, "Trip started via AJAX, actual pickup time set to $actual_time"]);

        echo json_encode(['success' => true, 'message' => 'Trip started!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Trip could not be started. It may have already changed status.']);
    }
    exit();
}

// Delete Approved trip - FIXED to update car status
if (isset($_GET['delete_approved'])) {
    $id = $_GET['delete_approved'];
    
    // Get the car_id first
    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ? AND status = 'approved'");
    $car_stmt->execute([$id]);
    $allocation = $car_stmt->fetch();
    
    if ($allocation) {
        $car_id = $allocation['car_id'];
        
        $stmt = $pdo->prepare("DELETE FROM tbl_allocated_passengers WHERE allocation_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $pdo->prepare("DELETE FROM tbl_allocations WHERE allocation_id = ? AND status = 'approved'");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            // Update car status back to 'available' if the car isn't in use elsewhere
            $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocations WHERE car_id = ? AND status IN ('approved', 'in_progress')");
            $check->execute([$car_id]);
            $count = $check->fetchColumn();
            
            if ($count == 0) {
                $car_update = $pdo->prepare("UPDATE tbl_cars SET status = 'available', status_updated_at = NOW() WHERE car_id = ?");
                $car_update->execute([$car_id]);
            }
            
            $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'deleted', ?, 'Approved trip deleted', NOW())");
            $log->execute([$_SESSION['user_id'], $id]);
            $success = "Trip deleted successfully!";
        } else {
            $error = "Failed to delete trip or trip not found.";
        }
    } else {
        $error = "Trip not found or not approved.";
    }
    header('Location: requests.php?tab=approved');
    exit();
}

// ============================================
// EDIT PENDING REQUEST - AJAX HANDLERS
// ============================================

// Handle Edit Pending Request via AJAX - REMOVED fields that shouldn't be edited
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_pending_ajax'])) {
    $id = $_POST['allocation_id'];
    $date = $_POST['date'];
    $pickup_time = $_POST['pickup_time'];
    $dropoff_time = $_POST['dropoff_time'];
    // REMOVED: pickup_location, dropoff_location, travel_type, purpose, passengers
    
    // Keep existing remarks (don't update it)
    $stmt = $pdo->prepare("UPDATE tbl_allocations SET date = ?, pickup_time = ?, dropoff_time = ? WHERE allocation_id = ? AND status = 'pending'");
    $stmt->execute([$date, $pickup_time, $dropoff_time, $id]);
    
    $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'updated', ?, 'Pending request updated (date/time only)', NOW())");
    $log->execute([$_SESSION['user_id'], $id]);
    
    echo json_encode(['success' => true, 'message' => 'Request updated successfully!']);
    exit();
}

// Get pending request data for edit modal
if (isset($_GET['get_pending_data'])) {
    $id = $_GET['get_pending_data'];
    
    $stmt = $pdo->prepare("
        SELECT a.*, COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email,
               CASE WHEN a.remarks LIKE '%Purpose:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                    ELSE NULL END as purpose,
               CASE WHEN a.remarks LIKE '%Travel Type:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Travel Type:', -1), '|', 1))
                    ELSE NULL END as travel_type
        FROM tbl_allocations a 
        JOIN tbl_users u ON a.requestor_id = u.user_id 
        WHERE a.allocation_id = ? AND a.status = 'pending'
    ");
    $stmt->execute([$id]);
    $trip_data = $stmt->fetch();
    
    if (!$trip_data) {
        echo json_encode(['success' => false, 'message' => 'Trip not found']);
        exit();
    }
    
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_id, p.passenger_name, p.contact
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$id]);
    $trip_passengers = $pass_stmt->fetchAll();
    
    // We still need all passengers for display purposes (read-only)
    $all_passengers = $pdo->prepare("SELECT * FROM tbl_passengers WHERE created_by = ? ORDER BY passenger_name");
    $all_passengers->execute([$_SESSION['user_id']]);
    $all_passengers = $all_passengers->fetchAll();
    
    echo json_encode([
        'success' => true,
        'trip' => $trip_data,
        'passengers' => $trip_passengers,
        'all_passengers' => $all_passengers
    ]);
    exit();
}

// ============================================
// EDIT APPROVED TRIP - AJAX HANDLERS
// ============================================

// Handle Edit Approved Trip via AJAX - REMOVED passengers and remarks
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_approved_ajax'])) {
    $id = $_POST['allocation_id'];
    $date = $_POST['date'];
    $pickup_time = $_POST['pickup_time'];
    $dropoff_time = $_POST['dropoff_time'];
    $pickup_location = $_POST['pickup_location'];
    $dropoff_location = $_POST['dropoff_location'];
    $driver_id = $_POST['driver_id'];
    $old_stmt = $pdo->prepare("SELECT car_id FROM tbl_allocations WHERE allocation_id = ?");
    $old_stmt->execute([$id]);
    $old_car_id = $old_stmt->fetchColumn();

    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_drivers WHERE driver_id = ?");
    $car_stmt->execute([$driver_id]);
    $car_data = $car_stmt->fetch();
    $car_id = $car_data ? $car_data['car_id'] : null;

    if (!$car_id) {
        echo json_encode(['success' => false, 'message' => 'Selected driver has no car assigned.']);
        exit();
    }

    // Driver overlap check (excludes this same allocation)
    $driver_conflict = $pdo->prepare("
        SELECT COUNT(*) FROM tbl_allocations 
        WHERE driver_id = ? AND date = ? 
        AND status IN ('approved', 'in_progress', 'pending')
        AND allocation_id != ?
        AND pickup_time < ? AND dropoff_time > ?
    ");
    $driver_conflict->execute([$driver_id, $date, $id, $dropoff_time, $pickup_time]);
    if ($driver_conflict->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'This driver already has a conflicting trip at that date/time.']);
        exit();
    }

    // Car overlap check (excludes this same allocation)
    $car_conflict = $pdo->prepare("
        SELECT COUNT(*) FROM tbl_allocations 
        WHERE car_id = ? AND date = ? 
        AND status IN ('approved', 'in_progress', 'pending')
        AND allocation_id != ?
        AND pickup_time < ? AND dropoff_time > ?
    ");
    $car_conflict->execute([$car_id, $date, $id, $dropoff_time, $pickup_time]);
    if ($car_conflict->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'The assigned car already has a conflicting trip at that date/time.']);
        exit();
    }

    $stmt = $pdo->prepare("UPDATE tbl_allocations SET date = ?, pickup_time = ?, dropoff_time = ?, pickup_location = ?, dropoff_location = ?, driver_id = ?, car_id = ? WHERE allocation_id = ? AND status = 'approved'");
    $stmt->execute([$date, $pickup_time, $dropoff_time, $pickup_location, $dropoff_location, $driver_id, $car_id, $id]);

    if ($old_car_id && $old_car_id != $car_id) {
        updateCarStatus($pdo, $old_car_id);
    }
    updateCarStatus($pdo, $car_id);

    $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'updated', ?, 'Approved trip updated (passengers and remarks not editable)', NOW())");
    $log->execute([$_SESSION['user_id'], $id]);

    echo json_encode(['success' => true, 'message' => 'Trip updated successfully!']);
    exit();
}

// Get approved trip data for edit modal - UPDATED with available drivers
if (isset($_GET['get_approved_data'])) {
    $id = $_GET['get_approved_data'];
    
    $stmt = $pdo->prepare("
        SELECT a.*, COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email,
               d.name as driver_name, d.mobile as driver_mobile,
               c.brand, c.plate_number, c.parking,
               CASE WHEN a.remarks LIKE '%Purpose:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                    ELSE NULL END as purpose,
               CASE WHEN a.remarks LIKE '%Travel Type:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Travel Type:', -1), '|', 1))
                    ELSE NULL END as travel_type
        FROM tbl_allocations a 
        JOIN tbl_users u ON a.requestor_id = u.user_id 
        JOIN tbl_drivers d ON a.driver_id = d.driver_id 
        JOIN tbl_cars c ON a.car_id = c.car_id 
        WHERE a.allocation_id = ? AND a.status = 'approved'
    ");
    $stmt->execute([$id]);
    $trip_data = $stmt->fetch();
    
    if (!$trip_data) {
        echo json_encode(['success' => false, 'message' => 'Trip not found']);
        exit();
    }
    
    // Get passengers (read-only)
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$id]);
    $trip_passengers = $pass_stmt->fetchAll();
    
    $available_drivers = getAvailableDrivers($pdo, $trip_data['date'], $trip_data['pickup_time'], $trip_data['dropoff_time']);
    $current_driver_option = [
        'driver_id' => $trip_data['driver_id'],
        'name' => $trip_data['driver_name'],
        'car_id' => $trip_data['car_id'],
        'brand' => $trip_data['brand'],
        'plate_number' => $trip_data['plate_number'],
        'parking' => $trip_data['parking'],
        'is_current' => true
    ];
    array_unshift($available_drivers, $current_driver_option);
    
    echo json_encode([
        'success' => true,
        'trip' => $trip_data,
        'passengers' => $trip_passengers,
        'available_drivers' => $available_drivers
    ]);
    exit();
}

// Check if this is an AJAX request for approved trips (driver-card refresh, scoped to a date)
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['tab']) && $_GET['tab'] == 'approved') {
    $ajax_approved_date = $_GET['approved_date'] ?? date('Y-m-d');

    $ajax_stmt = $pdo->prepare("
        SELECT a.*, a.request_number,
               c.brand, c.plate_number, 
               d.name as driver_name, d.mobile as driver_mobile, 
               COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email,
               CASE WHEN a.remarks LIKE '%Purpose:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Purpose:', -1), '|', 1))
                    ELSE NULL END as purpose,
               CASE WHEN a.remarks LIKE '%Travel Type:%'
                    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.remarks, 'Travel Type:', -1), '|', 1))
                    ELSE NULL END as travel_type
        FROM tbl_allocations a 
        JOIN tbl_cars c ON a.car_id = c.car_id 
        JOIN tbl_drivers d ON a.driver_id = d.driver_id 
        JOIN tbl_users u ON a.requestor_id = u.user_id 
        WHERE a.status = 'approved' AND a.date = ?
        ORDER BY a.pickup_time
    ");
    $ajax_stmt->execute([$ajax_approved_date]);
    $approved_trips_ajax = $ajax_stmt->fetchAll();

    foreach ($approved_trips_ajax as $key => $t) {
        $pass_stmt = $pdo->prepare("
            SELECT p.passenger_name 
            FROM tbl_allocated_passengers ap 
            JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
            WHERE ap.allocation_id = ?
        ");
        $pass_stmt->execute([$t['allocation_id']]);
        $approved_trips_ajax[$key]['passengers'] = $pass_stmt->fetchAll();
        $approved_trips_ajax[$key]['startability'] = getTripStartability($pdo, $t);
    }

    $ajax_drivers = groupTripsByDriver($approved_trips_ajax);

    // NEW: JSON variant for refreshing an open driver-trips modal without closing it
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');   
        header('Pragma: no-cache');                                      
        echo json_encode(array_values($ajax_drivers));
        exit();
    }

    echo renderDriverCardsHtml($ajax_drivers, 'openApprovedDriverModal', 'approved');
    exit();
}

$pending_count = count($pending);
$approved_count = count($approved_trips);
$outgoing_count = count($outgoing_trips);

// Get success/error messages from URL
$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';

/**
 * Returns ['startable' => bool, 'reason' => string] for a given approved trip.
 * A trip is startable only if:
 *  - it's scheduled for today
 *  - the driver has no trip currently in_progress
 *  - the car has no trip currently in_progress
 *  - it's the earliest-scheduled 'approved' trip for that driver today
 *    (ties broken by allocation_id, so insertion order wins)
 */
function getTripStartability($pdo, $trip) {
    if ($trip['status'] !== 'approved') {
        return ['startable' => false, 'reason' => 'Trip is not approved.'];
    }
    if ($trip['date'] > date('Y-m-d')) {
        return ['startable' => false, 'reason' => 'Trips cannot be started before their scheduled day.'];
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

/**
 * Get available drivers for a specific date and time
 */
function getAvailableDrivers($pdo, $date, $pickup_time, $dropoff_time = null) {
    if (!$dropoff_time) {
        $dropoff_time = date('H:i:s', strtotime($pickup_time . ' + 1 hour'));
    }
    
    $stmt = $pdo->prepare("
        SELECT d.*, c.brand, c.plate_number, c.parking, c.coding_day, c.capacity 
        FROM tbl_drivers d 
        LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
        WHERE d.status = 'active' 
        AND c.car_id IS NOT NULL
        AND c.status != 'under_maintenance'
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
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Requests - CARS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../admin/assets/css/admin.css">
    <link rel="stylesheet" href="../admin/assets/css/admin-requests.css">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modal responsive */
        @media (max-width: 992px) {
            #approvedDriverModal .trip-modal-box,
            #outgoingDriverModal .trip-modal-box {
                min-width: auto !important;
                width: 98% !important;
                max-width: 98% !important;
            }
        }
        
        /* DataTable custom styles */
        .dt-custom-requests .dataTables_length,
        .dt-custom-requests .dataTables_filter {
            margin-bottom: 10px;
            font-size: 0.85rem;
        }
        .dt-custom-requests .dataTables_length select,
        .dt-custom-requests .dataTables_filter input {
            padding: 4px 8px;
            border: 2px solid #e9ecef;
            border-radius: 4px;
            font-size: 0.85rem;
            background: white;
        }
        .dt-custom-requests .dataTables_filter input:focus {
            outline: none;
            border-color: #1a237e;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.08);
        }
        .dt-custom-requests .dataTables_info {
            font-size: 0.85rem;
            color: #6c757d;
            padding-top: 10px;
        }
        .dt-custom-requests .dataTables_paginate {
            padding-top: 10px;
        }
        .dt-custom-requests .dataTables_paginate .paginate_button {
            padding: 4px 12px;
            margin: 0 2px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            background: white;
            color: #1a1a2e;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .dt-custom-requests .dataTables_paginate .paginate_button:hover {
            background: #2b3152;
            border-color: #1a237e;
            color: #1a237e;
        }
        .dt-custom-requests .dataTables_paginate .paginate_button.current {
            background: #1a237e;
            color: #ffffff !important;
            border-color: #1a237e;
        }
        .dt-custom-requests .dataTables_paginate .paginate_button.current:hover {
            background: #1a237e;
            color: #ffffff !important;
        }
        .dt-custom-requests .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        .dt-custom-requests .dataTables_empty {
            padding: 20px;
            color: #6c757d;
            text-align: center;
        }
        .dt-custom-requests table.dataTable {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .dt-custom-requests table.dataTable tbody tr:hover {
            background: #f8f9ff !important;
        }
        .info-text i {
            margin-right: 4px;
        }
        .fa-spinner {
            animation: fa-spin 1s infinite linear;
        }
        @keyframes fa-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Coding Warning Modal */
        .coding-warning-modal .modal-box {
            max-width: 500px;
        }
        .coding-warning-modal .modal-box .warning-icon {
            font-size: 3rem;
            text-align: center;
            margin-bottom: 10px;
        }
        .coding-warning-modal .modal-box .warning-title {
            color: #c62828;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .coding-warning-modal .modal-box .warning-message {
            color: #333;
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .coding-warning-modal .modal-box .warning-details {
            background: #fff8e1;
            border: 1px solid #ffcc02;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 10px 0;
            font-size: 0.85rem;
        }
        .coding-warning-modal .modal-box .warning-details strong {
            color: #e65100;
        }
        .coding-warning-modal .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .coding-warning-modal .modal-box .btn-proceed {
            background: #f57c00;
            color: white;
        }
        .coding-warning-modal .modal-box .btn-proceed:hover {
            background: #e65100;
        }
        .coding-warning-modal .modal-box .btn-cancel {
            background: #e9ecef;
            color: #1a1a2e;
        }
        .coding-warning-modal .modal-box .btn-cancel:hover {
            background: #dee2e6;
        }

        /* Complete In Progress Modal */
        .complete-inprogress-modal .modal-box {
            max-width: 500px;
        }
        .complete-inprogress-modal .modal-box .modal-title {
            color: #2e7d32;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .complete-inprogress-modal .modal-box .modal-message {
            color: #333;
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .complete-inprogress-modal .modal-box .trip-details {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 10px 0;
            font-size: 0.85rem;
        }
        .complete-inprogress-modal .modal-box .trip-details strong {
            color: #2e7d32;
        }
        .complete-inprogress-modal .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .complete-inprogress-modal .modal-box .btn-complete-inprogress {
            background: #2e7d32;
            color: white;
        }
        .complete-inprogress-modal .modal-box .btn-complete-inprogress:hover {
            background: #1b5e20;
        }
        .complete-inprogress-modal .modal-box .btn-cancel {
            background: #e9ecef;
            color: #1a1a2e;
        }
        .complete-inprogress-modal .modal-box .btn-cancel:hover {
            background: #dee2e6;
        }

        .start-trip-modal .modal-box {
            max-width: 500px;
        }
        .start-trip-modal .modal-box .modal-title {
            color: #1a237e;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .start-trip-modal .modal-box .modal-message {
            color: #333;
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .start-trip-modal .modal-box .trip-details {
            background: #e8eaf6;
            border: 1px solid #c5cae9;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 10px 0;
            font-size: 0.85rem;
        }
        .start-trip-modal .modal-box .trip-details strong {
            color: #1a237e;
        }
        .start-trip-modal .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .start-trip-modal .modal-box .btn-start-trip {
            background: #1a237e;
            color: white;
        }
        .start-trip-modal .modal-box .btn-start-trip:hover {
            background: #283593;
        }
        .start-trip-modal .modal-box .btn-cancel {
            background: #e9ecef;
            color: #1a1a2e;
        }
        .start-trip-modal .modal-box .btn-cancel:hover {
            background: #dee2e6;
        }

        /* Cancel Modal */
        .cancel-modal .modal-box {
            max-width: 500px;
        }
        .cancel-modal .modal-box .cancel-icon {
            font-size: 3rem;
            text-align: center;
            margin-bottom: 10px;
        }
        .cancel-modal .modal-box .cancel-title {
            color: #c62828;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .cancel-modal .modal-box .cancel-message {
            color: #333;
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .cancel-modal .modal-box .trip-details {
            background: #fbe9e7;
            border: 1px solid #ef9a9a;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 10px 0;
            font-size: 0.85rem;
        }
        .cancel-modal .modal-box .trip-details strong {
            color: #c62828;
        }
        .cancel-modal .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .cancel-modal .modal-box .btn-danger {
            background: #c62828;
            color: white;
        }
        .cancel-modal .modal-box .btn-danger:hover {
            background: #b71c1c;
        }
        .cancel-modal .modal-box .btn-cancel {
            background: #e9ecef;
            color: #1a1a2e;
        }
        .cancel-modal .modal-box .btn-cancel:hover {
            background: #dee2e6;
        }
        .passenger-error-message {
            display: none;
            color: #c62828;
            font-size: 0.75rem;
            margin-bottom: 8px;
            padding: 6px 10px;
            background: #ffebee;
            border: 1px solid #ef9a9a;
            border-radius: 4px;
        }
        .passenger-error-message.show {
            display: block;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="dashboard.php" class="navbar-brand">CARS <span>Admin</span></a>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="requests.php" class="active">Requests</a>
                <a href="schedule.php">Schedule</a>
                <a href="driver_vehicle.php">Drivers & Vehicles</a>
                <a href="reports.php">Reports</a>
                <a href="../pages/gen_driver.php" target="_blank">Driver Schedule</a>
                <a href="#" onclick="openLogoutModal(); return false;">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Modals -->
        <div class="modal-overlay" id="logoutModal">
            <div class="modal-box">
                <div class="modal-icon"></div>
                <h3>Logout Confirmation</h3>
                <p>Are you sure you want to logout?</p>
                <div class="modal-buttons">
                    <button class="btn btn-cancel-modal" onclick="closeLogoutModal()">Cancel</button>
                    <a href="../logout.php" class="btn btn-danger-modal">Logout</a>
                </div>
            </div>
        </div>

        <!-- Delete Modal (for Approved trips only) -->
        <div class="modal-overlay" id="deleteModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
                <h3 id="deleteModalTitle">Delete Approved Trip</h3>
                <p id="deleteModalMessage">Are you sure you want to delete this approved trip?</p>
                <p style="font-size:0.85rem; color:#6c757d; margin-bottom:4px;" id="modalTripInfo"></p>
                <div class="modal-warning">This action cannot be undone.</div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel-modal" onclick="closeDeleteModal()">Cancel</button>
                    <a href="#" class="btn btn-danger-modal" id="confirmDeleteBtn">Delete Trip</a>
                </div>
            </div>
        </div>

        <!-- Complete In Progress Modal -->
        <div class="modal-overlay complete-inprogress-modal" id="completeInprogressModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeCompleteInprogressModal()">&times;</button>
                <div class="modal-title"> Complete In Progress Trip</div>
                <div class="modal-message">
                    Are you sure you want to mark this in-progress trip as completed?
                </div>
                <div class="trip-details">
                    <strong>Car:</strong> <span id="completeInprogressCar">-</span><br>
                    <strong>Driver:</strong> <span id="completeInprogressDriver">-</span><br>
                    <strong>Date:</strong> <span id="completeInprogressDate">-</span><br>
                    <strong>Actual Departure:</strong> <span id="completeInprogressPickup">-</span>
                </div>
                <div class="form-group floating-group" style="margin-top:12px;">
                    <input type="time" class="form-control-modern" placeholder=" " id="completeInprogressActualTime" required>
                    <label for="completeInprogressActualTime">Actual Arrival<span class="required">*</span></label>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeCompleteInprogressModal()">Cancel</button>
                    <a href="#" class="btn btn-complete-inprogress" id="confirmCompleteInprogressBtn">Complete Trip</a>
                </div>
            </div>
        </div>

        <!-- Start Trip Modal -->
        <div class="modal-overlay start-trip-modal" id="startTripModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeStartTripModal()">&times;</button>
                <div class="modal-title">Start Trip</div>
                <div class="modal-message">
                    Are you sure you want to start this trip? The current time will be recorded as the actual pickup time.
                </div>
                <div class="trip-details">
                    <strong>Requestor:</strong> <span id="startTripRequestor">-</span><br>
                    <strong>Car:</strong> <span id="startTripCar">-</span><br>
                    <strong>Driver:</strong> <span id="startTripDriver">-</span><br>
                    <strong>Scheduled Departure:</strong> <span id="startTripPickup">-</span>
                </div>
                <div class="form-group floating-group" style="margin-top:12px;">
                    <input type="time" class="form-control-modern" placeholder=" " id="startTripActualTime" required>
                    <label for="startTripActualTime">Actual Departure <span class="required">*</span></label>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeStartTripModal()">Cancel</button>
                    <button type="button" class="btn btn-start-trip" id="confirmStartTripBtn">Start Trip</button>
                </div>
            </div>
        </div>

        <!-- Cancel Trip Modal -->
        <div class="modal-overlay cancel-modal" id="cancelModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeCancelModal()">&times;</button>
                <div class="cancel-title">Cancel Trip</div>
                <div class="cancel-message">
                    Are you sure you want to cancel this trip?
                    <br>
                    <span style="font-size:0.85rem; color:#6c757d;">The trip will be marked as cancelled and will appear in reports.</span>
                </div>
                <div class="trip-details" id="cancelTripDetails">
                    <strong>Car:</strong> <span id="cancelCar">-</span><br>
                    <strong>Driver:</strong> <span id="cancelDriver">-</span><br>
                    <strong>Date:</strong> <span id="cancelDate">-</span><br>
                    <strong>Departure:</strong> <span id="cancelPickup">-</span>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeCancelModal()">Go Back</button>
                    <a href="#" class="btn btn-danger" id="confirmCancelBtn">Cancel Trip</a>
                </div>
            </div>
        </div>

        <!-- Approve Confirmation Modal -->
        <div class="approve-modal-overlay" id="approveModal">
            <div class="approve-modal-box">
                <button class="modal-close" onclick="closeApproveModal()">&times;</button>
                <h3>Confirm Assignment</h3>
                <p style="color:#6c757d; font-size:0.9rem; margin-bottom:15px;">Please review the details before approving this request.</p>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:600; color:#1a237e; margin-bottom:6px; font-size:0.85rem;">
                        Assign Driver <span class="required">*</span>
                    </label>
                    <select id="reviewDriverSelect" class="form-control-modern" style="width:100%; padding:10px 14px; border:2px solid #e9ecef; border-radius:6px; font-size:0.9rem;" onchange="updateReviewDriverInfo()">
                        <option value="">Select a driver</option>
                    </select>
                </div>

                <div class="trip-summary" id="tripSummary">
                    <div class="row">
                        <span class="label">Requestor</span>
                        <span class="value" id="approveRequestor">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Email</span>
                        <span class="value" id="approveEmail">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Date</span>
                        <span class="value" id="approveDate">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Departure</span>
                        <span class="value" id="approvePickupTime">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Arrival</span>
                        <span class="value" id="approveDropoffTime">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Departure</span>
                        <span class="value" id="approvePickup">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Arrival</span>
                        <span class="value" id="approveDropoff">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Travel Type</span>
                        <span class="value" id="approveTravelType">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Purpose</span>
                        <span class="value" id="approvePurpose">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Passengers</span>
                        <span class="value" id="approvePassengers">-</span>
                    </div>
                </div>
                
                <div class="driver-info" id="driverInfo" style="display:none;">
                    <div class="row">
                        <span class="label">Driver</span>
                        <span class="value" id="approveDriverName">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Car</span>
                        <span class="value" id="approveCarInfo">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Parking</span>
                        <span class="value" id="approveParking">-</span>
                    </div>
                    <div class="row">
                        <span class="label">Capacity</span>
                        <span class="value" id="approveCapacity">-</span>
                    </div>
                </div>
                <div class="capacity-warning-message" id="approveCapacityWarning" style="display:none; margin-top:8px; padding:8px 12px; background:#fff8e1; border:1px solid #ffcc02; border-radius:6px; font-size:0.8rem; color:#e65100;">
                    <i class="fas fa-exclamation-triangle"></i> <span id="approveCapacityWarningText"></span>
                </div>
                
                <form method="POST" id="approveForm">
                    <input type="hidden" name="approve_request" value="1">
                    <input type="hidden" name="allocation_id" id="approveAllocationId">
                    <input type="hidden" name="driver_id" id="approveDriverId">
                    
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="closeApproveModal()">Cancel</button>
                        <button type="button" class="btn btn-reject-inline" onclick="switchReviewToReject()">Reject</button>
                        <button type="submit" class="btn btn-approve">Approve Request</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reject Confirmation Modal -->
        <div class="reject-modal-overlay" id="rejectModal">
            <div class="reject-modal-box">
                <button class="modal-close" onclick="closeRejectModal()">&times;</button>
                <h3>Reject Request</h3>
                <p style="color:#6c757d; font-size:0.9rem; margin-bottom:15px;">Please provide a reason for rejecting this request.</p>
                
                <form method="GET" id="rejectForm">
                    <input type="hidden" name="reject" id="rejectAllocationId">
                    <input type="hidden" name="tab" value="requests">
                    
                    <div class="form-group" style="margin-bottom:15px;">
                        <label for="rejectReason" style="display:block; font-weight:600; color:#1a237e; margin-bottom:5px;">Reason for Rejection <span class="required">*</span></label>
                        <select name="reason" id="rejectReason" class="form-control-modern" required style="width:100%; padding:10px 14px; border:2px solid #e9ecef; border-radius:6px; font-size:0.95rem;">
                            <option value="">Select a reason...</option>
                            <option value="Driver not available">Driver not available</option>
                            <option value="Car not available">Car not available</option>
                            <option value="Invalid request details">Invalid request details</option>
                            <option value="Duplicate request">Duplicate request</option>
                            <option value="Insufficient information">Insufficient information</option>
                            <option value="Date not available">Date not available</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom:15px;">
                        <label for="rejectCustomReason" style="display:block; font-weight:600; color:#1a237e; margin-bottom:5px;">Custom Reason <span style="color:#6c757d; font-weight:400;">(Optional)</span></label>
                        <input type="text" name="custom_reason" id="rejectCustomReason" class="form-control-modern" placeholder="Enter custom reason if needed..." style="width:100%; padding:10px 14px; border:2px solid #e9ecef; border-radius:6px; font-size:0.95rem;">
                    </div>
                    
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
                        <button type="submit" class="btn btn-reject">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Submitting Overlay -->
        <div class="modal-overlay submitting-overlay" id="submittingOverlay">
            <div class="submitting-box">
                <div class="submitting-spinner"></div>
                <div class="submitting-title">Processing...</div>
                <div class="submitting-subtitle" id="submittingSubtitle">Sending email, please wait.</div>
            </div>
        </div>

        <!-- Coding Warning Modal -->
        <div class="modal-overlay coding-warning-modal" id="codingWarningModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeCodingWarningModal()">&times;</button>
                <div class="warning-title">Coding Day Warning!</div>
                <div class="warning-message">
                    The selected car has a coding restriction on <strong id="codingDayDisplay">Monday</strong>.
                    <br>
                    Are you sure you want to proceed with this allocation?
                </div>
                <div class="warning-details">
                    <strong>Car:</strong> <span id="codingCarDisplay">-</span><br>
                    <strong>Plate Number:</strong> <span id="codingPlateDisplay">-</span><br>
                    <strong>Coding Day:</strong> <span id="codingDayDisplay2">-</span><br>
                    <strong>Trip Date:</strong> <span id="codingDateDisplay">-</span>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeCodingWarningModal()">Cancel</button>
                    <button type="button" class="btn btn-proceed" id="codingProceedBtn">Proceed Anyway</button>
                </div>
            </div>
        </div>

        <!-- Range Conflict Warning Modal -->
        <div class="modal-overlay coding-warning-modal" id="rangeConflictModal">
            <div class="modal-box" style="max-width:560px;">
                <button class="modal-close" onclick="closeRangeConflictModal()">&times;</button>
                <div class="warning-title">Some Dates Have Conflicts</div>
                <div class="warning-message">
                    These dates in your range will be <strong>skipped</strong> due to coding-day restrictions or scheduling conflicts. All other dates will still be created.
                    <br><br>
                    <span style="color:#e65100; font-weight:600;">If you want to assign a trip on a coding day, do the single date instead — date ranges can't override coding-day restrictions.</span>
                </div>
                <div class="warning-details" style="max-height:240px; overflow-y:auto; text-align:left;">
                    <div id="rangeConflictList"></div>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeRangeConflictModal()">Go Back</button>
                    <button type="button" class="btn btn-proceed" id="rangeConflictProceedBtn">Proceed with Remaining Dates</button>
                </div>
            </div>
        </div>

        <!-- Direct Allocation Confirmation Modal -->
        <div class="modal-overlay confirm-modal" id="confirmModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
                <div class="confirm-title">Confirm Trip Details</div>
                <div class="confirm-subtitle">Please review all trip details before submitting.</div>
                
                <div class="trip-summary-box" id="confirmTripSummary">
                    <div class="row"><span class="label">Date:</span><span class="value" id="confirmDate">-</span></div>
                    <div class="row"><span class="label">Departure:</span><span class="value" id="confirmPickupTime">-</span></div>
                    <div class="row"><span class="label">Arrival:</span><span class="value" id="confirmDropoffTime">-</span></div>
                    <div class="row"><span class="label">Status:</span><span class="value" id="confirmStatus">-</span></div>
                    <div class="row"><span class="label">Driver:</span><span class="value" id="confirmDriver">-</span></div>
                    <div class="row"><span class="label">Car:</span><span class="value" id="confirmCar">-</span></div>
                    <div class="row"><span class="label">Parking:</span><span class="value" id="confirmParking">-</span></div>
                    <div class="row"><span class="label">Coding Day:</span><span class="value" id="confirmCoding">-</span></div>
                    <div class="row"><span class="label">Pickup Location:</span><span class="value" id="confirmPickupLocation">-</span></div>
                    <div class="row"><span class="label">Dropoff Location:</span><span class="value" id="confirmDropoffLocation">-</span></div>
                    <div class="row"><span class="label">Passengers:</span><span class="value" id="confirmPassengers">-</span></div>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel-modal" onclick="closeConfirmModal()">Cancel</button>
                    <button type="button" class="btn btn-confirm" id="confirmSubmitBtn">Confirm & Submit</button>
                </div>
            </div>
        </div>

        <!-- Edit Approved Trip Modal -->
        <div class="edit-modal-overlay" id="editModal">
            <div class="edit-modal-box">
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
                <h3>Edit Approved Trip</h3>
                <p style="color:#6c757d; font-size:0.85rem; margin-bottom:12px;">You can edit trip details below. Passengers and remarks are read-only.</p>
                <div id="editModalBody">
                    <div class="loading-spinner">Loading trip details...</div>
                </div>
            </div>
        </div>

        <!-- Edit Pending Request Modal -->
        <div class="edit-modal-overlay" id="editPendingModal">
            <div class="edit-modal-box">
                <button class="modal-close" onclick="closeEditPendingModal()">&times;</button>
                <h3>Edit Pending Request</h3>
                <p style="color:#6c757d; font-size:0.85rem; margin-bottom:12px;">You can only edit the date and time. Other details are locked.</p>
                <div id="editPendingBody">
                    <div class="loading-spinner">Loading request details...</div>
                </div>
            </div>
        </div>

        <!-- Approved Trips - Driver Data Table Modal -->
        <div class="trip-modal-overlay" id="approvedDriverModal">
            <div class="trip-modal-box">
                <button class="modal-close" onclick="closeApprovedDriverModal()">&times;</button>
                <h3 id="approvedDriverModalTitle">Driver Trips</h3>
                <p id="approvedDriverModalSubtitle" class="driver-modal-mobile"></p>
                <div class="table-container">
                    <table id="approvedDriverModalTable" class="display responsive nowrap" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Requestor</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Date &amp; Time</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Car</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Route</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Passengers</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Remarks</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="approvedDriverModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Outgoing Trips - Driver Data Table Modal -->
        <div class="trip-modal-overlay" id="outgoingDriverModal">
            <div class="trip-modal-box">
                <button class="modal-close" onclick="closeOutgoingDriverModal()">&times;</button>
                <h3 id="outgoingDriverModalTitle">Driver Trips</h3>
                <p id="outgoingDriverModalSubtitle" class="driver-modal-mobile"></p>
                <div class="table-container">
                    <table id="outgoingDriverModalTable" class="display responsive nowrap" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Requestor</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Date &amp; Time</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Car</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Route</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Passengers</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Status</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Remarks</th>
                                <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-size:0.75rem; text-transform:uppercase; color:#6c757d;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="outgoingDriverModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <div class="tab-nav">
            <a href="?tab=direct" class="<?= $active_tab == 'direct' ? 'active' : '' ?>">
                Direct Allocation
            </a>    
            <a href="?tab=requests" class="<?= $active_tab == 'requests' ? 'active' : '' ?>">
                Pending Requests
                <span class="badge-tab" id="pendingBadge"><?= $pending_count ?></span>
            </a>
            <a href="?tab=approved" class="<?= $active_tab == 'approved' ? 'active' : '' ?>">
                Approved Trips
                <span class="badge-tab"><?= $approved_count ?></span>
            </a>
            <a href="?tab=outgoing" class="<?= $active_tab == 'outgoing' ? 'active' : '' ?>">
                Outgoing Trips
                <span class="badge-tab"><?= $outgoing_count ?></span>
            </a>
        </div>

        <!-- Pending Requests Tab - DataTable -->
        <div class="tab-content <?= $active_tab == 'requests' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <h3 style="font-size:1rem;">Pending Requests</h3>
                    <span class="badge" style="background:#e65100; color:white; padding:4px 12px;"><?= $pending_count ?> Requests</span>
                </div>
                <?php if ($pending_count > 0): ?>
                    <div class="table-container">
                        <table id="pendingTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Request #</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Requestor</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Local Number</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Date</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Departure</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Arrival</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Location</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Remarks</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Passengers</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($pending as $r): 
                                    $pass_stmt = $pdo->prepare("
                                        SELECT p.passenger_name 
                                        FROM tbl_allocated_passengers ap 
                                        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
                                        WHERE ap.allocation_id = ?
                                    ");
                                    $pass_stmt->execute([$r['allocation_id']]);
                                    $request_passengers = $pass_stmt->fetchAll();
                                    
                                    $day_of_week = date('l', strtotime($r['date']));
                                    
                                    $purpose = '';
                                    $travel_type = '';
                                    if (!empty($r['remarks'])) {
                                        if (preg_match('/Purpose:\s*([^|]+)/i', $r['remarks'], $matches)) {
                                            $purpose = trim($matches[1]);
                                        }
                                        if (preg_match('/Travel Type:\s*([^|]+)/i', $r['remarks'], $matches)) {
                                            $travel_type = trim($matches[1]);
                                        }
                                        if (empty($purpose) && empty($travel_type)) {
                                            $purpose = $r['remarks'];
                                        }
                                    }
                                    
                                    $available_drivers = getAvailableDrivers($pdo, $r['date'], $r['pickup_time'], $r['dropoff_time']);
                                ?>
                                <tr>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($r['request_number']) ?></strong></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= htmlspecialchars($r['requestor']) ?>
                                        <br>
                                        <span class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($r['requestor_email'] ?? '') ?></span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= htmlspecialchars($r['local_number'] ?? '-') ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= date('M d, Y', strtotime($r['date'])) ?> <span class="text-muted" style="font-size:0.6rem;">(<?= $day_of_week ?>)</span></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= date('g:i A', strtotime($r['pickup_time'])) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $r['dropoff_time'] ? date('g:i A', strtotime($r['dropoff_time'])) : '-' ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= htmlspecialchars($r['pickup_location']) ?>
                                        <?php if (!empty($r['dropoff_location'])): ?>
                                            <span style="font-size:0.6rem; color:#6c757d;">→ <?= htmlspecialchars($r['dropoff_location']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5; max-width:180px;">
                                        <?php if ($travel_type): ?>
                                            <div style="font-size:0.6rem; color:#6c757d; text-transform:uppercase; letter-spacing:0.3px;"><?= htmlspecialchars($travel_type) ?></div>
                                        <?php endif; ?>
                                        <?php if ($purpose): ?>
                                            <div style="font-size:0.75rem;"><?= htmlspecialchars($purpose) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.7rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if (!empty($request_passengers)): ?>
                                            <?php foreach($request_passengers as $p): ?>
                                                <span class="passenger-tag" style="font-size:0.65rem;"><?= htmlspecialchars($p['passenger_name']) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.65rem;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                            <button type="button" class="btn btn-primary btn-review" onclick="openReviewModal(<?= $r['allocation_id'] ?>, '<?= addslashes(htmlspecialchars($r['requestor'])) ?>', '<?= addslashes(htmlspecialchars($r['requestor_email'] ?? '')) ?>', '<?= date('M d, Y', strtotime($r['date'])) ?> (<?= $day_of_week ?>)', '<?= date('g:i A', strtotime($r['pickup_time'])) ?>', '<?= $r['dropoff_time'] ? date('g:i A', strtotime($r['dropoff_time'])) : 'Not specified' ?>', '<?= addslashes(htmlspecialchars($r['pickup_location'])) ?>', '<?= addslashes(htmlspecialchars($r['dropoff_location'] ?? 'Not specified')) ?>', '<?= addslashes(htmlspecialchars($travel_type)) ?>', '<?= addslashes(htmlspecialchars($purpose)) ?>', '<?= htmlspecialchars(json_encode(array_column($request_passengers, 'passenger_name')), ENT_QUOTES) ?>', '<?= htmlspecialchars(json_encode($available_drivers), ENT_QUOTES) ?>', '<?= $r['date'] ?>')">
                                                <i class="fas fa-clipboard-check"></i> Review
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-review" onclick="openEditPendingModal(<?= $r['allocation_id'] ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No pending requests.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Direct Allocation Tab -->
        <div class="tab-content <?= $active_tab == 'direct' ? 'active' : '' ?>">
            <div class="card direct-form">
                <h3>Direct Allocation</h3>
                <p style="color:#6c757d; font-size:0.85rem; margin-bottom:10px;">Create a new trip assignment directly without waiting for approval.</p>
                
                <form method="POST" id="allocationForm">
                    <input type="hidden" name="direct" value="1">
                    
                    <div class="date-type-toggle">
                        <label>
                            <input type="radio" name="date_type" value="single" checked onchange="toggleDateType()">
                            Single Date
                        </label>
                        <label>
                            <input type="radio" name="date_type" value="range" onchange="toggleDateType()">
                            Date Range
                        </label>
                    </div>

                    <div class="date-single-group" id="singleDateGroup">
                        <div class="form-row-2">
                            <div class="form-group floating-group">
                                <input type="date" class="form-control-modern" placeholder=" " name="date" id="date" required>
                                <label for="date">Trip Date <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <select class="form-control-modern" name="travel_type" id="travel_type_single" required>
                                    <option value="">Select travel type</option>
                                    <option value="Drop Off">Drop Off</option>
                                    <option value="Back and Forth">Back and Forth</option>
                                </select>
                                <label for="travel_type_single">Travel Type <span class="required">*</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="date-range-group" id="dateRangeGroup">
                        <div class="form-group floating-group">
                            <input type="date" class="form-control-modern" placeholder=" " name="start_date" id="start_date">
                            <label for="start_date">Start Date <span class="required">*</span></label>
                        </div>
                        <div class="form-group floating-group">
                            <input type="date" class="form-control-modern" placeholder=" " name="end_date" id="end_date">
                            <label for="end_date">End Date <span class="required">*</span></label>
                        </div>
                        <div class="form-group floating-group">
                            <select class="form-control-modern" name="travel_type" id="travel_type_range" disabled>
                                <option value="">Select travel type</option>
                                <option value="Drop Off">Drop Off</option>
                                <option value="Back and Forth">Back and Forth</option>
                            </select>
                            <label for="travel_type_range">Travel Type <span class="required">*</span></label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group floating-group">
                            <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="pickup_time" required>
                            <label for="pickup_time">Departure <span class="required">*</span></label>
                        </div>
                        <div class="form-group floating-group">
                            <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="dropoff_time" required>
                            <label for="dropoff_time">Arrival <span class="required">*</span></label>
                            <div class="dropoff-error-message" id="dropoffError">Arrival time must be after pickup time</div>
                        </div>
                    </div>

                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="driver_id" id="driver_id" required onchange="updateCarInfo(this.value)">
                            <option value="">Select a driver</option>
                            <?php 
                            $available_drivers = getAvailableDrivers($pdo, date('Y-m-d'), date('H:i:s'), date('H:i:s', strtotime('+1 hour')));
                            foreach($available_drivers as $d): 
                            ?>
                                <option value="<?= $d['driver_id'] ?>" 
                                    data-car-id="<?= $d['car_id'] ?>" 
                                    data-brand="<?= $d['brand'] ?>" 
                                    data-plate="<?= $d['plate_number'] ?>" 
                                    data-parking="<?= $d['parking'] ?>"
                                    data-coding-day="<?= $d['coding_day'] ?>"
                                    data-capacity="<?= (int)($d['capacity'] ?? 0) ?>">
                                    <?= htmlspecialchars($d['name']) ?> 
                                    <?php if ($d['car_id']): ?>
                                        - <?= htmlspecialchars($d['brand']) ?> (<?= htmlspecialchars($d['plate_number']) ?>) · Seats <?= (int)($d['capacity'] ?? 0) ?>
                                        <?php if ($d['coding_day']): ?>
                                            <span style="color:#c62828; font-size:0.7rem;"> [Coding: <?= htmlspecialchars($d['coding_day']) ?>]</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (count($available_drivers) == 0): ?>
                                <option value="" disabled style="color:#c62828;">No drivers available for this time slot</option>
                            <?php endif; ?>
                        </select>
                        <label for="driver_id">Select Driver <span class="required">*</span></label>
                        <div class="info-text" id="driverAvailabilityInfo">
                            <?php if (count($available_drivers) > 0): ?>
                                <i class="fas fa-check-circle" style="color:#2e7d32;"></i> 
                                <?= count($available_drivers) ?> driver(s) available
                            <?php else: ?>
                                <i class="fas fa-exclamation-circle" style="color:#c62828;"></i> 
                                No drivers available
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="auto-car-display" id="carDisplay">
                        <div class="car-detail">
                            <span class="label">Assigned Car:</span>
                            <span class="value" id="carDisplayText">-</span>
                        </div>
                        <div class="car-detail">
                            <span class="label">Parking:</span>
                            <span class="value" id="parkingDisplayText">-</span>
                        </div>
                        <div class="car-detail">
                            <span class="label">Capacity:</span>
                            <span class="value" id="capacityDisplayText">-</span>
                        </div>
                        <div class="car-detail" id="codingDisplay" style="display:none;">
                            <span class="label">Coding Day:</span>
                            <span class="value coding-warning" id="codingDisplayText">-</span>
                        </div>
                        <div class="capacity-warning-message" id="capacityWarning" style="display:none; margin-top:8px; padding:8px 12px; background:#fff8e1; border:1px solid #ffcc02; border-radius:6px; font-size:0.8rem; color:#e65100;">
                            <i class="fas fa-exclamation-triangle"></i> <span id="capacityWarningText"></span>
                        </div>
                    </div>

                    <input type="hidden" name="car_id" id="car_id" value="">

                    <div class="form-row-3">
                        <div class="form-group floating-group">
                            <input type="text" class="form-control-modern" placeholder=" " name="pickup_location" id="pickup_location" required>
                            <label for="pickup_location">Pickup Location <span class="required">*</span></label>
                        </div>
                        <div class="form-group floating-group">
                            <input type="text" class="form-control-modern" placeholder=" " name="dropoff_location" id="dropoff_location" required>
                            <label for="dropoff_location">Dropoff Location <span class="required">*</span></label>
                        </div>
                        <div class="form-group floating-group">
                            <input type="text" class="form-control-modern" placeholder=" " name="remarks" id="remarks">
                            <label for="remarks">Remarks <span style="color:#6c757d; font-weight:400;">(Optional)</span></label>
                        </div>
                    </div>

                    <div class="passenger-section floating-group choices-floating choices-multiple">
                        <div class="section-title">
                            Select Passenger/s <span class="required">*</span>
                        </div>
                        <select class="form-control-modern" name="passengers[]" id="passengerGrid" required multiple>
                            <?php foreach($passengers as $p): ?>
                                <option value="<?= $p['passenger_id'] ?>"><?= htmlspecialchars($p['passenger_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="add-passenger-form">
                            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                <input type="text" id="new_passenger_name" placeholder="Enter new passenger name" 
                                style="border-color: #ddd; width: 100%; padding: 8px 12px; border: 2px solid #e9ecef; 
                                border-radius: 6px; font-size: 0.85rem; height: 38px;">
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" onclick="addPassenger()" style="height: 38px; padding: 0 16px; font-size: 0.8rem; white-space: nowrap; flex-shrink: 0;">Add Passenger</button>
                        </div>
                        <div id="addPassengerMessage" style="margin-top:4px; font-size:0.75rem;"></div>
                        <div class="info-text">Type to search, or select multiple passengers from the dropdown.
                        </div>

                        <div style="margin-top:6px;">
                            <button type="button" onclick="deleteUnusedPassengers()" style="font-size:0.75rem; color:#c62828; background:none; border:none; text-decoration:underline; cursor:pointer; padding:0;">
                                Clean up unused passengers
                            </button>
                        </div>
                        <div id="managePassengersList" style="display:none; margin-top:8px; padding:8px; background:#f8f9fa; border-radius:6px;"></div>
                    </div>

                    <div class="form-group" style="display:flex; align-items:center; gap:8px; margin:10px 0;">
                        <input type="checkbox" id="start_now_checkbox" name="start_now" value="1" style="width:auto;">
                        <label for="start_now_checkbox" style="font-weight:500; font-size:0.85rem; margin:0;">
                            Check this if this trip already happened (Late Allocation) / is happening now.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" id="directSubmitBtn">Create Trip(s)</button>
                </form>
            </div>
        </div>

        <!-- Approved Trips Tab -->
        <div class="tab-content <?= $active_tab == 'approved' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <h3 style="font-size:1rem;">Approved Trips</h3>
                    <span class="badge" style="background:#1a237e; color:white; padding:4px 12px;"><?= $approved_count ?> Trips</span>
                </div>
                <div class="date-picker-bar">
                    <form method="GET" id="approvedDateForm" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="approved">
                        <button type="button" class="btn-nav" onclick="changeApprovedDate(-1)" title="Previous Day">‹</button>
                        <input type="date" name="approved_date" id="approved_date_input" value="<?= htmlspecialchars($approved_date) ?>" onchange="document.getElementById('approvedDateForm').submit()">
                        <button type="button" class="btn-nav" onclick="changeApprovedDate(1)" title="Next Day">›</button>
                        <a href="?tab=approved&approved_date=<?= date('Y-m-d') ?>" class="btn-today">Today</a>
                    </form>
                </div>
                <div class="admin-driver-list" id="approvedDriverList">
                    <?= renderDriverCardsHtml($approved_drivers, 'openApprovedDriverModal', 'approved') ?>
                </div>
            </div>
        </div>

        <!-- Trip Details Tab -->
        <div class="tab-content <?= $active_tab == 'outgoing' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <h3 style="font-size:1rem;">Trip Details</h3>
                    <span class="badge" style="background:#1a237e; color:white; padding:4px 12px;"><?= $outgoing_count ?> Trips</span>
                </div>
                <div class="date-picker-bar">
                    <form method="GET" id="outgoingDateForm" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="outgoing">
                        <button type="button" class="btn-nav" onclick="changeOutgoingDate(-1)" title="Previous Day">‹</button>
                        <input type="date" name="outgoing_date" id="outgoing_date_input" value="<?= htmlspecialchars($outgoing_date) ?>" onchange="document.getElementById('outgoingDateForm').submit()">
                        <button type="button" class="btn-nav" onclick="changeOutgoingDate(1)" title="Next Day">›</button>
                        <a href="?tab=outgoing&outgoing_date=<?= date('Y-m-d') ?>" class="btn-today">Today</a>
                    </form>
                </div>
                <div class="admin-driver-list" id="outgoingDriverList">
                    <?= renderDriverCardsHtml($outgoing_drivers, 'openOutgoingDriverModal', 'outgoing') ?>
                </div>
            </div>
        </div>

    <script>
        var ALL_DRIVERS_WITH_CAR = <?= json_encode($all_drivers_with_car) ?>;
    </script>    

    <!-- jQuery and DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../admin/assets/js/admin.js"></script>
    <script src="../admin/assets/js/admin-requests.js"></script>
</body>
</html>