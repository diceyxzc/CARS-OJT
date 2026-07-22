<?php
session_start();
require_once '../config/db.php';
// Users that can Use the edit Function
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';
$trip_data = null;

// Get all drivers for dropdown
$all_drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active' 
    ORDER BY d.name
")->fetchAll();

// Get trip ID from URL
$trip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle AJAX passenger addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_passenger_ajax'])) {
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
    
    $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_allocated_passengers WHERE passenger_id = ?");
    $check->execute([$passenger_id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Cannot delete: Passenger is assigned to $count trip(s). Remove from trips first."
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

if ($trip_id > 0) {
    // Fetch trip details
    $stmt = $pdo->prepare("
        SELECT a.*, 
               c.brand, c.plate_number, c.parking, 
               d.name as driver_name, d.driver_id, 
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
        WHERE a.allocation_id = ?
    ");
    $stmt->execute([$trip_id]);
    $trip_data = $stmt->fetch();
    
    if (!$trip_data) {
        $error = "Trip not found.";
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_trip'])) {
    $id = $_POST['allocation_id'];
    $driver_id = $_POST['driver_id'];
    $date = $_POST['date'];
    $pickup_time = $_POST['pickup_time'];
    $pickup_location = $_POST['pickup_location'];
    $dropoff_location = $_POST['dropoff_location'];
    $travel_type = $_POST['travel_type'] ?? '';
    $purpose = $_POST['purpose'] ?? '';
    $selected_passengers = $_POST['passengers'] ?? [];
    
    // Get the car assigned to this driver
    $car_stmt = $pdo->prepare("SELECT car_id FROM tbl_drivers WHERE driver_id = ?");
    $car_stmt->execute([$driver_id]);
    $car_data = $car_stmt->fetch();
    
    if (!$car_data || !$car_data['car_id']) {
        $error = "Selected driver does not have a car assigned.";
    } else {
        $car_id = $car_data['car_id'];
        
        // Combine purpose and travel type into remarks
        $remarks = "Purpose: " . $purpose . " | Travel Type: " . $travel_type;
        
        $stmt = $pdo->prepare("UPDATE tbl_allocations SET car_id = ?, driver_id = ?, date = ?, pickup_time = ?, pickup_location = ?, dropoff_location = ?, remarks = ? WHERE allocation_id = ?");
        $stmt->execute([$car_id, $driver_id, $date, $pickup_time, $pickup_location, $dropoff_location, $remarks, $id]);
        
        // Delete existing passenger links
        $stmt = $pdo->prepare("DELETE FROM tbl_allocated_passengers WHERE allocation_id = ?");
        $stmt->execute([$id]);
        
        // Insert new passenger links
        if (!empty($selected_passengers)) {
            foreach ($selected_passengers as $passenger_id) {
                $pdo->prepare("INSERT INTO tbl_allocated_passengers (allocation_id, passenger_id) VALUES (?, ?)")->execute([$id, $passenger_id]);
            }
        }
        
        $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'updated', ?, 'Trip details updated', NOW())");
        $log->execute([$_SESSION['user_id'], $id]);
        
        $success = "Trip updated successfully!";
        
        // Refresh trip data
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   c.brand, c.plate_number, c.parking, 
                   d.name as driver_name, d.driver_id, 
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
            WHERE a.allocation_id = ?
        ");
        $stmt->execute([$id]);
        $trip_data = $stmt->fetch();
    }
}

// Get passengers for the trip
$trip_passengers = [];
if ($trip_data) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name, p.passenger_id
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$trip_data['allocation_id']]);
    $trip_passengers = $pass_stmt->fetchAll();
}

// Get all passengers for the dropdown
$all_passengers = $pdo->prepare("SELECT * FROM tbl_passengers WHERE created_by = ? ORDER BY passenger_name");
$all_passengers->execute([$_SESSION['user_id']]);
$all_passengers = $all_passengers->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Trip - CARS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        body {
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }

        .edit-container {
            background: white;
            border-radius: 12px;
            padding: 35px 40px;
            max-width: 750px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-30px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .edit-container .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .edit-container .header h2 {
            color: #1a237e;
            font-size: 1.3rem;
            margin: 0;
        }

        .edit-container .header .close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: #6c757d;
            line-height: 1;
            padding: 0 5px;
            text-decoration: none;
        }

        .edit-container .header .close-btn:hover {
            color: #1a1a2e;
        }

        .edit-container .subtitle {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .floating-group {
            position: relative;
            margin-bottom: 20px;
        }

        .floating-group .form-control-modern {
            width: 100%;
            padding: 16px 14px 6px 14px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.3s ease;
            background: #fafbfc;
            color: #1a1a2e;
            height: 44px;
        }

        .floating-group .form-control-modern:focus {
            outline: none;
            border-color: #1a237e;
            background: white;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.08);
        }

        .floating-group select.form-control-modern {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
            cursor: pointer;
        }

        .floating-group label {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            font-size: 0.85rem;
            pointer-events: none;
            transition: all 0.3s ease;
            background: transparent;
            padding: 0 4px;
        }

        .floating-group .form-control-modern:focus ~ label,
        .floating-group .form-control-modern:not(:placeholder-shown) ~ label {
            top: 4px;
            font-size: 0.65rem;
            color: #1a237e;
            background: white;
            padding: 0 6px;
        }

        .passenger-section {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin: 10px 0 15px 0;
            background: #fafbfc;
        }

        .passenger-section .section-title {
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .passenger-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 6px;
            max-height: 150px;
            overflow-y: auto;
            padding: 4px;
        }

        .passenger-grid label {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            background: white;
        }

        .passenger-grid label:hover {
            background: #f8f9ff;
            border-color: #1a237e;
        }

        .passenger-grid label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: #1a237e;
            cursor: pointer;
            flex-shrink: 0;
        }

        .passenger-grid label.selected {
            background: #e8f0fe;
            border-color: #1a237e;
        }

        .passenger-grid .delete-passenger-btn {
            background: none;
            border: none;
            color: #c62828;
            cursor: pointer;
            font-size: 0.7rem;
            padding: 2px 4px;
            border-radius: 4px;
            transition: all 0.2s;
            opacity: 0.5;
            flex-shrink: 0;
        }

        .passenger-grid .delete-passenger-btn:hover {
            opacity: 1;
            background: #fbe9e7;
        }

        .passenger-grid .contact-info {
            font-size: 0.65rem;
            color: #6c757d;
        }

        .add-passenger-form {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
            align-items: end;
        }

        .add-passenger-form .form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 120px;
        }

        .add-passenger-form .form-group input {
            padding: 6px 10px;
            border: 2px solid #e9ecef;
            border-radius: 4px;
            font-size: 0.8rem;
            width: 100%;
            transition: all 0.3s;
            background: white;
        }

        .add-passenger-form .form-group input:focus {
            outline: none;
            border-color: #1a237e;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.08);
        }

        .add-passenger-form .btn-sm {
            padding: 6px 14px;
            font-size: 0.75rem;
        }

        .selected-passengers-display {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin: 6px 0;
        }

        .selected-passenger-tag {
            background: #1a237e;
            color: white;
            padding: 2px 10px;
            border-radius: 16px;
            font-size: 0.7rem;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn-group .btn {
            padding: 10px 28px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
            font-size: 0.9rem;
            display: inline-block;
        }

        .btn-primary {
            background: #1a237e;
            color: white;
        }

        .btn-primary:hover {
            background: #283593;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26, 35, 126, 0.3);
        }

        .btn-secondary {
            background: #e9ecef;
            color: #1a1a2e;
        }

        .btn-secondary:hover {
            background: #dee2e6;
        }

        .btn-success {
            background: #2e7d32;
            color: white;
        }

        .btn-success:hover {
            background: #1b5e20;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
            border-left: 4px solid;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left-color: #2e7d32;
        }

        .alert-error {
            background: #fbe9e7;
            color: #c62828;
            border-left-color: #c62828;
        }

        .info-text {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 4px;
        }

        .required {
            color: #c62828;
            font-weight: 700;
        }

        .trip-info-box {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .trip-info-box .row {
            display: flex;
            padding: 3px 0;
            font-size: 0.85rem;
        }

        .trip-info-box .row .label {
            font-weight: 600;
            color: #6c757d;
            min-width: 100px;
        }

        .trip-info-box .row .value {
            color: #1a1a2e;
        }

        .passenger-count {
            font-size: 0.8rem;
            color: #6c757d;
            margin-left: 6px;
        }

        @media (max-width: 768px) {
            .edit-container {
                padding: 25px 20px;
            }
            .form-row-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .passenger-grid {
                grid-template-columns: 1fr 1fr;
            }
            .trip-info-box .row {
                flex-direction: column;
                gap: 2px;
            }
            .trip-info-box .row .label {
                min-width: auto;
            }
            .add-passenger-form {
                flex-direction: column;
            }
            .add-passenger-form .form-group {
                min-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .edit-container {
                padding: 20px 15px;
            }
            .passenger-grid {
                grid-template-columns: 1fr;
            }
            .btn-group {
                flex-direction: column;
            }
            .btn-group .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="edit-container">
        <div class="header">
            <h2>Edit Trip</h2>
            <a href="requests.php?tab=approved" class="close-btn">&times;</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
                <br>
                <a href="requests.php?tab=approved" style="color:#1a237e; font-weight:600; text-decoration:none;">← Back to Approved Trips</a>
            </div>
        <?php endif; ?>

        <?php if ($trip_data && !$success): ?>
            <p class="subtitle">Update the trip details below. The driver's assigned car will be automatically updated.</p>

            <!-- Trip Info Summary -->
            <div class="trip-info-box">
                <div class="row">
                    <span class="label">Requestor:</span>
                    <span class="value"><?= htmlspecialchars($trip_data['requestor']) ?></span>
                </div>
                <div class="row">
                    <span class="label">Email:</span>
                    <span class="value"><?= htmlspecialchars($trip_data['requestor_email'] ?? 'Not provided') ?></span>
                </div>
                <div class="row">
                    <span class="label">Current Driver:</span>
                    <span class="value"><?= htmlspecialchars($trip_data['driver_name']) ?></span>
                </div>
                <div class="row">
                    <span class="label">Current Car:</span>
                    <span class="value"><?= htmlspecialchars($trip_data['brand']) ?> (<?= htmlspecialchars($trip_data['plate_number']) ?>)</span>
                </div>
                <div class="row">
                    <span class="label">Parking:</span>
                    <span class="value"><?= htmlspecialchars($trip_data['parking'] ?? 'Not specified') ?></span>
                </div>
                <div class="row">
                    <span class="label">Status:</span>
                    <span class="value"><span class="badge badge-approved"><?= $trip_data['status'] ?></span></span>
                </div>
            </div>

            <form method="POST" id="editForm">
                <input type="hidden" name="update_trip" value="1">
                <input type="hidden" name="allocation_id" value="<?= $trip_data['allocation_id'] ?>">

                <div class="form-row-2">
                    <div class="floating-group">
                        <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_date" required value="<?= $trip_data['date'] ?>">
                        <label for="edit_date">Trip Date <span class="required">*</span></label>
                    </div>
                    <div class="floating-group">
                        <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_pickup_time" required value="<?= $trip_data['pickup_time'] ?>">
                        <label for="edit_pickup_time">Pickup Time <span class="required">*</span></label>
                    </div>
                </div>

                <div class="floating-group">
                    <select class="form-control-modern" name="driver_id" id="edit_driver_id" required>
                        <option value="">Select Driver</option>
                        <?php foreach($all_drivers as $d): ?>
                            <option value="<?= $d['driver_id'] ?>" <?= $d['driver_id'] == $trip_data['driver_id'] ? 'selected' : '' ?>
                                data-brand="<?= htmlspecialchars($d['brand']) ?>"
                                data-plate="<?= htmlspecialchars($d['plate_number']) ?>"
                                data-parking="<?= htmlspecialchars($d['parking']) ?>">
                                <?= htmlspecialchars($d['name']) ?>
                                <?php if ($d['car_id']): ?>
                                    - <?= htmlspecialchars($d['brand']) ?> (<?= htmlspecialchars($d['plate_number']) ?>)
                                    <?php if ($d['parking']): ?>
                                        • <?= htmlspecialchars($d['parking']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    - No car assigned
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="edit_driver_id">Select Driver <span class="required">*</span></label>
                    <div class="info-text">Changing the driver will automatically update the assigned car.</div>
                </div>

                <div class="form-row-2">
                    <div class="floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="pickup_location" id="edit_pickup_location" required value="<?= htmlspecialchars($trip_data['pickup_location']) ?>">
                        <label for="edit_pickup_location">Pickup Location <span class="required">*</span></label>
                    </div>
                    <div class="floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="dropoff_location" id="edit_dropoff_location" required value="<?= htmlspecialchars($trip_data['dropoff_location'] ?? '') ?>">
                        <label for="edit_dropoff_location">Dropoff Location <span class="required">*</span></label>
                    </div>
                </div>

                <!-- Travel Type & Purpose Section -->
                <div class="form-row-2">
                    <div class="floating-group">
                        <select class="form-control-modern" name="travel_type" id="edit_travel_type" required>
                            <option value="">Select Travel Type</option>
                            <option value="Drop Off" <?= ($trip_data['travel_type'] ?? '') == 'Drop Off' ? 'selected' : '' ?>>Drop Off</option>
                            <option value="Back and Forth" <?= ($trip_data['travel_type'] ?? '') == 'Back and Forth' ? 'selected' : '' ?>>Back and Forth</option>
                        </select>
                        <label for="edit_travel_type">Travel Type <span class="required">*</span></label>
                    </div>
                    <div class="floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="purpose" id="edit_purpose" required value="<?= htmlspecialchars($trip_data['purpose'] ?? '') ?>">
                        <label for="edit_purpose">Purpose / Remarks <span class="required">*</span></label>
                    </div>
                </div>

                <!-- Passengers Section -->
                <div class="passenger-section">
                    <div class="section-title">
                        Select Passenger/s <span class="required">*</span>
                        <span class="passenger-count" id="passengerCount">(<?= count($trip_passengers) ?> selected)</span>
                    </div>

                    <div class="selected-passengers-display" id="selectedPassengersDisplay">
                        <?php foreach($trip_passengers as $p): ?>
                            <span class="selected-passenger-tag"><?= htmlspecialchars($p['passenger_name']) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="passenger-grid" id="passengerGrid">
                        <?php if (count($all_passengers) > 0): ?>
                            <?php 
                            $current_passenger_ids = array_column($trip_passengers, 'passenger_id');
                            foreach($all_passengers as $p): 
                                $checked = in_array($p['passenger_id'], $current_passenger_ids) ? 'checked' : '';
                            ?>
                                <label class="<?= $checked ? 'selected' : '' ?>">
                                    <input type="checkbox" name="passengers[]" value="<?= $p['passenger_id'] ?>" <?= $checked ?> onchange="updatePassengerDisplay()">
                                    <span class="passenger-name"><?= htmlspecialchars($p['passenger_name']) ?></span>
                                    <?php if (!empty($p['contact'])): ?>
                                        <span class="contact-info"> <?= htmlspecialchars($p['contact']) ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="delete-passenger-btn" onclick="deletePassenger(<?= $p['passenger_id'] ?>, '<?= htmlspecialchars($p['passenger_name']) ?>')" title="Delete passenger">
                                        ✕
                                    </button>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="grid-column: 1 / -1; color: #6c757d; font-size: 0.85rem; padding: 8px 0;">
                                No passengers available. Add one below!
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="add-passenger-form">
                        <div class="form-group">
                            <input type="text" id="new_passenger_name" placeholder="Enter passenger name" style="border-color: #ddd;">
                        </div>
                        <div class="form-group">
                            <input type="text" id="new_passenger_contact" placeholder="Contact (optional)" style="border-color: #ddd;">
                        </div>
                        <button type="button" class="btn btn-success btn-sm" onclick="addPassenger()">Add Passenger</button>
                    </div>
                    <div id="addPassengerMessage" style="margin-top:5px; font-size:0.8rem;"></div>
                    <div class="info-text">Select at least one passenger or add a new one.</div>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Update Trip</button>
                    <a href="requests.php?tab=approved" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>

        <?php if (!$trip_data && !$error && !$success): ?>
            <div style="text-align:center; padding:30px 0; color:#6c757d;">
                <p style="font-size:1.1rem;">Trip not found.</p>
                <a href="requests.php?tab=approved" class="btn btn-primary" style="margin-top:15px; display:inline-block;">← Back to Approved Trips</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="/assets/js/script.js"></script>
    <script src="/admin/assets/js/admin-requests.js"></script>
</body>
</html>