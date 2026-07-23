<?php
session_start();
require_once '../includes/load.php';
require_admin();

$active_tab = $_GET['tab'] ?? 'drivers';
$error = $_GET['error'] ?? $error ?? null;

// Get all cars with driver assignment info
$all_cars = $pdo->query("
    SELECT c.*, 
           d.driver_id, d.name as driver_name, d.mobile as driver_mobile, d.status as driver_status
    FROM tbl_cars c
    LEFT JOIN tbl_drivers d ON c.car_id = d.car_id
    ORDER BY c.brand
")->fetchAll();

// Count cars with a coding day assigned
$coding_count = 0;
foreach ($all_cars as $c) {
    if (!empty($c['coding_day'])) $coding_count++;
}

$drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking as car_parking 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active'
    ORDER BY d.name
")->fetchAll();

$inactive_drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking as car_parking 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'inactive' 
    ORDER BY d.name
")->fetchAll();

$unassigned_cars = $pdo->query("
    SELECT c.* 
    FROM tbl_cars c 
    LEFT JOIN tbl_drivers d ON c.car_id = d.car_id 
    WHERE d.driver_id IS NULL
    ORDER BY c.brand
")->fetchAll();

// Handle Assign Car
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_car'])) {
    $car_id = $_POST['car_id'];
    $driver_id = $_POST['driver_id'] ?: null;
    
    if ($driver_id) {
        $chk = $pdo->prepare("SELECT driver_id FROM tbl_drivers WHERE car_id = ? AND status = 'active'");
        $chk->execute([$car_id]);
        if ($chk->fetch()) {
            $error = urlencode("This car is already assigned to another active driver.");
            header('Location: driver_vehicle.php?tab=' . ($_POST['redirect_tab'] ?? 'cars') . '&error=' . $error);
            exit();
        } else {
            $stmt = $pdo->prepare("UPDATE tbl_drivers SET car_id = ? WHERE driver_id = ?");
            $stmt->execute([$car_id, $driver_id]);
            updateCarStatus($pdo, $car_id);
            $success = "Car assigned to driver successfully!";
        }
    }
    header('Location: driver_vehicle.php?tab=' . ($_POST['redirect_tab'] ?? 'cars'));
    exit();
}

// Handle Unassign Car
if (isset($_GET['unassign_car'])) {
    $id = $_GET['unassign_car'];
    $driver_id = $_GET['driver_id'] ?? null;

    if ($driver_id) {
        $stmt = $pdo->prepare("UPDATE tbl_drivers SET car_id = NULL WHERE car_id = ? AND driver_id = ?");
        $stmt->execute([$id, $driver_id]);
    } else {
        // Fallback for old links without driver_id param
        $stmt = $pdo->prepare("UPDATE tbl_drivers SET car_id = NULL WHERE car_id = ?");
        $stmt->execute([$id]);
    }
    
    $success = "Car unassigned successfully!";
    header('Location: driver_vehicle.php?tab=cars');
    exit();
}

// Handle Add Driver via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_driver_ajax'])) {
    $name = trim($_POST['name']);
    $mobile = trim($_POST['mobile']);
    $car_id = $_POST['car_id'] ?: null;
    
    $stmt = $pdo->prepare("INSERT INTO tbl_drivers (name, mobile, car_id, status) VALUES (?, ?, ?, 'active')");
    $stmt->execute([$name, $mobile, $car_id]);
    
    // If car was assigned, update its status based on actual usage
    if ($car_id) {
        updateCarStatus($pdo, $car_id);
    }
    
    echo json_encode(['success' => true, 'message' => 'Driver added successfully!', 'driver_id' => $pdo->lastInsertId()]);
    exit();
}

// Handle Edit Driver via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_driver_ajax'])) {
    $driver_id = $_POST['driver_id'];
    $name = trim($_POST['name']);
    $mobile = trim($_POST['mobile']);
    
    $stmt = $pdo->prepare("UPDATE tbl_drivers SET name = ?, mobile = ? WHERE driver_id = ?");
    $stmt->execute([$name, $mobile, $driver_id]);
    
    echo json_encode(['success' => true, 'message' => 'Driver updated successfully!']);
    exit();
}

// Handle Add Car via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_car_ajax'])) {
    $brand = trim($_POST['brand']);
    $plate_number = trim($_POST['plate_number']);
    $parking = trim($_POST['parking'] ?? '');
    $coding_day = $_POST['coding_day'] ?? '';
    $status = $_POST['status'] ?? 'available';
    
    $stmt = $pdo->prepare("INSERT INTO tbl_cars (brand, plate_number, parking, coding_day, status, status_updated_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$brand, $plate_number, $parking, $coding_day, $status]);
    
    echo json_encode(['success' => true, 'message' => 'Car added successfully!', 'car_id' => $pdo->lastInsertId()]);
    exit();
}

// Handle Edit Car via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_car_ajax'])) {
    $car_id = $_POST['car_id'];
    $brand = trim($_POST['brand']);
    $plate_number = trim($_POST['plate_number']);
    $parking = trim($_POST['parking'] ?? '');
    $coding_day = $_POST['coding_day'] ?? '';
    $status = $_POST['status'] ?? 'available';
    
    $stmt = $pdo->prepare("UPDATE tbl_cars SET brand = ?, plate_number = ?, parking = ?, coding_day = ?, status = ?, status_updated_at = NOW() WHERE car_id = ?");
    $stmt->execute([$brand, $plate_number, $parking, $coding_day, $status, $car_id]);
    
    echo json_encode(['success' => true, 'message' => 'Car updated successfully!']);
    exit();
}

// Handle Toggle Driver Status with modal confirmation
if (isset($_GET['toggle'])) {
    $id = $_GET['toggle'];
    $driver = $pdo->prepare("SELECT status FROM tbl_drivers WHERE driver_id = ?");
    $driver->execute([$id]);
    $current = $driver->fetch()['status'];
    $new = $current == 'active' ? 'inactive' : 'active';
    $stmt = $pdo->prepare("UPDATE tbl_drivers SET status = ? WHERE driver_id = ?");
    $stmt->execute([$new, $id]);
    
    // If driver had a car, update its status
    $car_check = $pdo->prepare("SELECT car_id FROM tbl_drivers WHERE driver_id = ?");
    $car_check->execute([$id]);
    $car = $car_check->fetch();
    if ($car && $car['car_id']) {
        updateCarStatus($pdo, $car['car_id']);
    }
    
    header('Location: driver_vehicle.php?tab=drivers');
    exit();
}

// DELETE DRIVER HANDLER - REMOVED! Drivers can no longer be deleted.

// Handle Unassign Car with modal confirmation
if (isset($_GET['unassign_car_confirm'])) {
    $id = $_GET['unassign_car_confirm'];
    $driver_id = $_GET['driver_id'] ?? null;

    if ($driver_id) {
        $stmt = $pdo->prepare("UPDATE tbl_drivers SET car_id = NULL WHERE car_id = ? AND driver_id = ?");
        $stmt->execute([$id, $driver_id]);
    } else {
        // Fallback for old links without driver_id param
        $stmt = $pdo->prepare("UPDATE tbl_drivers SET car_id = NULL WHERE car_id = ?");
        $stmt->execute([$id]);
    }
    
    $success = "Car unassigned successfully!";
    header('Location: driver_vehicle.php?tab=cars');
    exit();
}

$with_cars = 0;
foreach($drivers as $d) {
    if ($d['car_id']) $with_cars++;
}

// Recalculate car stats to ensure accuracy
$total_cars = count($all_cars);
$assigned_cars = 0;
$unassigned_cars_count = 0;
foreach ($all_cars as $c) {
    if ($c['driver_id'] !== null) {
        $assigned_cars++;
    } else {
        $unassigned_cars_count++;
    }
}

// Get drivers WITHOUT cars for assign modal
$drivers_without_cars = $pdo->query("
    SELECT driver_id, name 
    FROM tbl_drivers 
    WHERE status = 'active' AND car_id IS NULL 
    ORDER BY name
")->fetchAll();

// Get all drivers for dropdown in assign modal (only those without cars)
$all_drivers = $pdo->query("SELECT driver_id, name FROM tbl_drivers WHERE status = 'active' AND car_id IS NULL ORDER BY name")->fetchAll();

// Get available cars for dropdown (only available or under_maintenance, not in_use)
$available_cars_for_dropdown = $pdo->query("
    SELECT c.car_id, c.brand, c.plate_number, c.parking 
    FROM tbl_cars c
    LEFT JOIN tbl_drivers d ON c.car_id = d.car_id AND d.status = 'active'
    WHERE c.status IN ('available', 'under_maintenance')
      AND d.driver_id IS NULL
    ORDER BY c.brand
")->fetchAll();

// Get all cars for dropdown (for adding driver) - only available cars
$all_cars_dropdown = $pdo->query("
    SELECT c.car_id, c.brand, c.plate_number, c.parking 
    FROM tbl_cars c
    LEFT JOIN tbl_drivers d ON c.car_id = d.car_id AND d.status = 'active'
    WHERE c.status IN ('available', 'under_maintenance')
      AND d.driver_id IS NULL
    ORDER BY c.brand
")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Drivers - CARS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../admin/assets/css/admin.css">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="dashboard.php" class="navbar-brand">CARS <span>Admin</span></a>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="requests.php">Requests</a>
                <a href="schedule.php">Schedule</a>
                <a href="driver_vehicle.php" class="active">Drivers & Vehicles</a>
                <a href="reports.php">Reports</a>
                <a href="#" onclick="openLogoutModal(); return false;">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Modals -->
        <div class="modal-overlay" id="logoutModal">
            <div class="modal-box">
                <h3>Logout Confirmation</h3>
                <p>Are you sure you want to logout?</p>
                <div class="modal-buttons">
                    <button class="btn btn-cancel-modal" onclick="closeLogoutModal()">Cancel</button>
                    <a href="../logout.php" class="btn btn-danger-modal">Logout</a>
                </div>
            </div>
        </div>

        <!-- Add Driver Modal -->
        <div class="modal-overlay" id="addDriverModal">
            <div class="modal-box" style="max-width:500px;">
                <button class="modal-close" onclick="closeAddDriverModal()">&times;</button>
                <h3>Add New Driver</h3>
                <form id="addDriverForm">
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="name" id="add_driver_name" required>
                        <label for="add_driver_name">Driver Name <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="mobile" id="add_driver_mobile" required>
                        <label for="add_driver_mobile">Mobile Number <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="car_id" id="add_driver_car">
                            <option value="">No car assigned</option>
                            <?php foreach($available_cars_for_dropdown as $c): ?>
                                <option value="<?= $c['car_id'] ?>"><?= htmlspecialchars($c['brand']) ?> - <?= htmlspecialchars($c['plate_number']) ?> (<?= htmlspecialchars($c['parking'] ?? 'No parking') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <label for="add_driver_car">Assign Car (Optional)</label>
                        <div class="info-text">Only available cars are shown. Cars in use cannot be assigned.</div>
                    </div>
                    <div id="addDriverMessage" style="margin-bottom:10px;"></div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel-modal" onclick="closeAddDriverModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Driver</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Driver Modal -->
        <div class="modal-overlay" id="editDriverModal">
            <div class="modal-box" style="max-width:500px;">
                <button class="modal-close" onclick="closeEditDriverModal()">&times;</button>
                <h3>Edit Driver</h3>
                <form id="editDriverForm">
                    <input type="hidden" name="edit_driver_ajax" value="1">
                    <input type="hidden" name="driver_id" id="edit_driver_id">
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="name" id="edit_driver_name" required>
                        <label for="edit_driver_name">Driver Name <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="mobile" id="edit_driver_mobile" required>
                        <label for="edit_driver_mobile">Mobile Number <span class="required">*</span></label>
                    </div>
                    <div id="editDriverMessage" style="margin-bottom:10px;"></div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel-modal" onclick="closeEditDriverModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Driver</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Car Modal -->
        <div class="modal-overlay" id="addCarModal">
            <div class="modal-box" style="max-width:500px;">
                <button class="modal-close" onclick="closeAddCarModal()">&times;</button>
                <h3>Add New Car</h3>
                <form id="addCarForm">
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="brand" id="add_car_brand" required>
                        <label for="add_car_brand">Car Brand / Model <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="plate_number" id="add_car_plate" required>
                        <label for="add_car_plate">Plate Number <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="parking" id="add_car_parking">
                        <label for="add_car_parking">Parking Location (Optional)</label>
                    </div>
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="coding_day" id="add_car_coding">
                            <option value="">No Coding</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                            <option value="Sunday">Sunday</option>
                        </select>
                        <label for="add_car_coding">Coding Day (Optional)</label>
                    </div>
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="status" id="add_car_status" required>
                            <option value="available">Available</option>
                            <option value="under_maintenance">Under Maintenance</option>
                        </select>
                        <label for="add_car_status">Status <span class="required">*</span></label>
                        <div class="info-text">Note: 'In Use' status is automatically managed by the system.</div>
                    </div>
                    <div id="addCarMessage" style="margin-bottom:10px;"></div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel-modal" onclick="closeAddCarModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Car</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Car Modal -->
        <div class="modal-overlay" id="editCarModal">
            <div class="modal-box" style="max-width:500px;">
                <button class="modal-close" onclick="closeEditCarModal()">&times;</button>
                <h3>Edit Car</h3>
                <form id="editCarForm">
                    <input type="hidden" name="edit_car_ajax" value="1">
                    <input type="hidden" name="car_id" id="edit_car_id">
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="brand" id="edit_car_brand" required>
                        <label for="edit_car_brand">Car Brand / Model <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="plate_number" id="edit_car_plate" required>
                        <label for="edit_car_plate">Plate Number <span class="required">*</span></label>
                    </div>
                    <div class="form-group floating-group">
                        <input type="text" class="form-control-modern" placeholder=" " name="parking" id="edit_car_parking">
                        <label for="edit_car_parking">Parking Location (Optional)</label>
                    </div>
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="coding_day" id="edit_car_coding">
                            <option value="">No Coding</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                            <option value="Sunday">Sunday</option>
                        </select>
                        <label for="edit_car_coding">Coding Day (Optional)</label>
                    </div>
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="status" id="edit_car_status" required>
                            <option value="available">Available</option>
                            <option value="under_maintenance">Under Maintenance</option>
                        </select>
                        <label for="edit_car_status">Status <span class="required">*</span></label>
                        <div class="info-text">Note: 'In Use' status is automatically managed by the system.</div>
                    </div>
                    <div id="editCarMessage" style="margin-bottom:10px;"></div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel-modal" onclick="closeEditCarModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Car</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assign Car Modal -->
        <div class="modal-overlay" id="assignModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeAssignModal()">&times;</button>
                <h3>Assign Car to Driver</h3>
                <p style="font-size:0.85rem; color:#6c757d; margin-bottom:4px;" id="assignCarInfo"></p>
                <form method="POST">
                    <input type="hidden" name="assign_car" value="1">
                    <input type="hidden" name="car_id" id="assign_car_id">
                    <input type="hidden" name="redirect_tab" id="assign_redirect_tab" value="cars">
                    
                    <div class="form-group floating-group">
                        <select class="form-control-modern" name="driver_id" id="assign_driver_id" required>
                            <option value="">Select Driver</option>
                            <?php foreach($drivers_without_cars as $d): ?>
                                <option value="<?= $d['driver_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                            <?php if (count($drivers_without_cars) == 0): ?>
                                <option value="" disabled>No available drivers (all have cars)</option>
                            <?php endif; ?>
                        </select>
                        <label for="assign_driver_id">Select Driver <span class="required">*</span></label>
                        <?php if (count($drivers_without_cars) == 0): ?>
                            <div class="info-text" style="color:#c62828;">All active drivers already have cars assigned.</div>
                        <?php else: ?>
                            <div class="info-text">Only drivers without cars are shown.</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel-modal" onclick="closeAssignModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" <?= count($drivers_without_cars) == 0 ? 'disabled' : '' ?>>Assign Car</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Unassign Car Modal -->
        <div class="modal-overlay" id="unassignModal">
            <div class="modal-box">
                <h3>Unassign Car</h3>
                <p>Are you sure you want to unassign this car from the driver?</p>
                <p style="font-size:0.85rem; color:#6c757d; margin-bottom:4px;" id="unassignCarInfo"></p>
                <div class="modal-warning">The car will become available for reassignment.</div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel-modal" onclick="closeUnassignModal()">Cancel</button>
                    <a href="#" class="btn btn-warning-modal" id="confirmUnassignBtn">Unassign Car</a>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <div class="tab-nav">
            <a href="?tab=drivers" class="<?= $active_tab == 'drivers' ? 'active' : '' ?>">
                Drivers
                <span class="badge-tab"><?= count($drivers) ?></span>
            </a>
            <a href="?tab=cars" class="<?= $active_tab == 'cars' ? 'active' : '' ?>">
                Cars
                <span class="badge-tab"><?= count($all_cars) ?></span>
            </a>
        </div>

        <!-- Drivers Tab -->
        <div class="tab-content <?= $active_tab == 'drivers' ? 'active' : '' ?>">
            <div class="stats-summary">
                <div class="stat-card green">
                    <div class="number"><?= count($drivers) ?></div>
                    <div class="label">Active Drivers</div>
                </div>
                <div class="stat-card orange">
                    <div class="number"><?= count($inactive_drivers) ?></div>
                    <div class="label">Inactive Drivers</div>
                </div>
                <div class="stat-card" style="border-left-color: #6c757d;">
                    <div class="number" style="color: #6c757d;"><?= $with_cars ?></div>
                    <div class="label">Drivers with Cars</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= count($unassigned_cars) ?></div>
                    <div class="label">Unassigned Cars</div>
                </div>
            </div>

            <div style="margin-bottom:15px;">
                <button class="btn btn-primary" onclick="openAddDriverModal()" style="padding:10px 24px; font-size:0.95rem;">+ Add New Driver</button>
            </div>

            <div class="card">
                <h3>All Drivers (<?= count($drivers) + count($inactive_drivers) ?>)</h3>
                <?php if (count($drivers) > 0 || count($inactive_drivers) > 0): ?>
                    <div class="table-container">
                        <table id="driversTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Name</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Mobile</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Assigned Car</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Parking</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Status</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach(array_merge($drivers, $inactive_drivers) as $d): ?>
                                <tr>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($d['mobile']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if ($d['car_id']): ?>
                                            <span style="color:#2e7d32;"><?= htmlspecialchars($d['brand']) ?> (<?= htmlspecialchars($d['plate_number']) ?>)</span>
                                        <?php else: ?>
                                            <span style="color:#6c757d; font-size:0.75rem;">No car assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if ($d['car_id'] && $d['car_parking']): ?>
                                            <?= htmlspecialchars($d['car_parking']) ?>
                                        <?php else: ?>
                                            <span style="color:#6c757d; font-size:0.75rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <span class="badge <?= $d['status'] == 'active' ? 'badge-approved' : 'badge-declined' ?>">
                                            <?= $d['status'] == 'active' ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <div class="action-buttons" style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <button class="btn btn-sm btn-primary" onclick="openEditDriverModal(<?= htmlspecialchars(json_encode($d)) ?>)" style="font-size:0.75rem; padding:6px 14px;">Edit</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No drivers found. Add a driver to get started.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cars Tab -->
        <div class="tab-content <?= $active_tab == 'cars' ? 'active' : '' ?>">
            <div class="stats-summary">
                <div class="stat-card">
                    <div class="number"><?= $total_cars ?></div>
                    <div class="label">Total Cars</div>
                </div>
                <div class="stat-card green">
                    <div class="number"><?= $assigned_cars ?></div>
                    <div class="label">Assigned Cars</div>
                </div>
                <div class="stat-card orange">
                    <div class="number"><?= $unassigned_cars_count ?></div>
                    <div class="label">Unassigned Cars</div>
                </div>
                <?php 
                $coding_count = 0;
                foreach($all_cars as $c) {
                    if (!empty($c['coding_day'])) $coding_count++;
                }
                ?>
                <div class="stat-card" style="border-left-color: #f57c00;">
                    <div class="number" style="color: #f57c00;"><?= $coding_count ?></div>
                    <div class="label">Has Coding</div>
                </div>
            </div>

            <div style="margin-bottom:15px;">
                <button class="btn btn-primary" onclick="openAddCarModal()" style="padding:10px 24px; font-size:0.95rem;">+ Add New Car</button>
            </div>

            <div class="card">
                <h3>All Cars (<?= count($all_cars) ?>)</h3>
                <?php if (count($all_cars) > 0): ?>
                    <div class="table-container">
                        <table id="carsTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Brand</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Plate Number</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Parking</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Coding Day</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Assignment</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Status</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Assigned To</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sorted_cars = $all_cars;
                                usort($sorted_cars, function($a, $b) {
                                    $a_assigned = $a['driver_id'] !== null ? 1 : 0;
                                    $b_assigned = $b['driver_id'] !== null ? 1 : 0;
                                    return $a_assigned - $b_assigned;
                                });
                                foreach($sorted_cars as $c): 
                                    $is_assigned = $c['driver_id'] !== null;
                                ?>
                                <tr>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($c['brand']) ?></strong></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($c['plate_number']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($c['parking'] ?? '-') ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if (!empty($c['coding_day'])): ?>
                                            <span style="color:#e65100; font-weight:500;"><?= htmlspecialchars($c['coding_day']) ?></span>
                                        <?php else: ?>
                                            <span style="color:#6c757d;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if ($is_assigned): ?>
                                            <span class="badge badge-approved" style="font-size:0.7rem;">Assigned</span>
                                        <?php else: ?>
                                            <span class="badge badge-cancelled" style="font-size:0.7rem;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <span class="badge <?= 
                                            $c['status'] == 'available' ? 'badge-approved' : 
                                            ($c['status'] == 'in_use' ? 'badge-in_progress' : 
                                            ($c['status'] == 'under_maintenance' ? 'badge-declined' : 
                                            ($c['status'] == 'coding_restricted' ? 'badge-cancelled' : 
                                            'badge-pending'))) 
                                        ?>">
                                            <?= str_replace('_', ' ', $c['status']) ?>
                                        </span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if ($is_assigned): ?>
                                            <?= htmlspecialchars($c['driver_name']) ?>
                                            <br><span class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($c['driver_mobile']) ?></span>
                                        <?php else: ?>
                                            <span style="color:#6c757d; font-size:0.75rem;">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <div class="action-buttons" style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <button class="btn btn-sm btn-primary" onclick="openEditCarModal(<?= htmlspecialchars(json_encode($c)) ?>)" style="font-size:0.75rem; padding:6px 14px;">Edit</button>
                                            <?php if (!$is_assigned): ?>
                                                <button class="btn btn-sm btn-success" onclick="openAssignModal(<?= $c['car_id'] ?>, 'cars', '<?= htmlspecialchars($c['brand']) ?>', '<?= htmlspecialchars($c['plate_number']) ?>')" style="font-size:0.75rem; padding:6px 14px;">Assign</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-warning" onclick="openUnassignModal(<?= $c['car_id'] ?>, <?= $c['driver_id'] ?>, '<?= htmlspecialchars($c['brand']) ?>', '<?= htmlspecialchars($c['plate_number']) ?>')" style="font-size:0.75rem; padding:6px 14px;">Unassign</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No cars found. Add a car to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../admin/assets/js/admin.js"></script>
    <script>
    $(document).ready(function() {
        $('#driversTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [5] }],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ drivers",
                infoEmpty: "No drivers found",
                infoFiltered: "(filtered from _MAX_ total drivers)",
                zeroRecords: "No matching drivers found"
            },
            dom: '<"dt-top"lf>t<"dt-bottom"ip>',
            classes: { sWrapper: 'dataTables_wrapper dt-custom-requests' }
        });

        $('#carsTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[3, 'asc'], [4, 'desc']], 
            columnDefs: [
                { orderable: false, targets: [7] },
                { targets: [4], orderable: true }
            ],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ cars",
                infoEmpty: "No cars found",
                infoFiltered: "(filtered from _MAX_ total cars)",
                zeroRecords: "No matching cars found"
            },
            dom: '<"dt-top"lf>t<"dt-bottom"ip>',
            classes: { sWrapper: 'dataTables_wrapper dt-custom-requests' }
        });
    });

    // Add Driver Modal
    function openAddDriverModal() {
        document.getElementById('addDriverModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeAddDriverModal() {
        document.getElementById('addDriverModal').classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('addDriverForm').reset();
        document.getElementById('addDriverMessage').innerHTML = '';
    }

    // Edit Driver Modal
    function openEditDriverModal(driver) {
        document.getElementById('edit_driver_id').value = driver.driver_id;
        document.getElementById('edit_driver_name').value = driver.name;
        document.getElementById('edit_driver_mobile').value = driver.mobile;
        document.getElementById('editDriverModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        document.getElementById('editDriverMessage').innerHTML = '';
    }
    function closeEditDriverModal() {
        document.getElementById('editDriverModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Add Car Modal
    function openAddCarModal() {
        document.getElementById('addCarModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeAddCarModal() {
        document.getElementById('addCarModal').classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('addCarForm').reset();
        document.getElementById('addCarMessage').innerHTML = '';
    }

    // Edit Car Modal
    function openEditCarModal(car) {
        document.getElementById('edit_car_id').value = car.car_id;
        document.getElementById('edit_car_brand').value = car.brand;
        document.getElementById('edit_car_plate').value = car.plate_number;
        document.getElementById('edit_car_parking').value = car.parking || '';
        document.getElementById('edit_car_coding').value = car.coding_day || '';
        document.getElementById('edit_car_status').value = car.status || 'available';
        document.getElementById('editCarModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        document.getElementById('editCarMessage').innerHTML = '';
    }
    function closeEditCarModal() {
        document.getElementById('editCarModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Assign Car Modal
    function openAssignModal(carId, redirectTab, brand, plate) {
        document.getElementById('assign_car_id').value = carId;
        document.getElementById('assign_redirect_tab').value = redirectTab || 'cars';
        document.getElementById('assignCarInfo').textContent = (brand && plate) ? brand + ' (' + plate + ')' : '';
        document.getElementById('assignModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeAssignModal() {
        document.getElementById('assignModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Unassign Car Modal
    function openUnassignModal(carId, driverId, brand, plate) {
        document.getElementById('unassignCarInfo').textContent = brand + ' (' + plate + ')';
        document.getElementById('confirmUnassignBtn').href = '?unassign_car=' + carId + '&driver_id=' + driverId + '&tab=cars';
        document.getElementById('unassignModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeUnassignModal() {
        document.getElementById('unassignModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Form submissions
    document.getElementById('addDriverForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('add_driver_ajax', '1');
        var messageDiv = document.getElementById('addDriverMessage');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            messageDiv.innerHTML = data.success ? '<span style="color: #2e7d32;">' + data.message + '</span>' : '<span style="color: #c62828;">' + data.message + '</span>';
            if (data.success) setTimeout(() => location.reload(), 500);
        })
        .catch(() => messageDiv.innerHTML = '<span style="color: #c62828;">Error. Please try again.</span>');
    });

    document.getElementById('editDriverForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var messageDiv = document.getElementById('editDriverMessage');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            messageDiv.innerHTML = data.success ? '<span style="color: #2e7d32;">' + data.message + '</span>' : '<span style="color: #c62828;">' + data.message + '</span>';
            if (data.success) setTimeout(() => location.reload(), 500);
        })
        .catch(() => messageDiv.innerHTML = '<span style="color: #c62828;">Error. Please try again.</span>');
    });

    document.getElementById('addCarForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('add_car_ajax', '1');
        var messageDiv = document.getElementById('addCarMessage');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            messageDiv.innerHTML = data.success ? '<span style="color: #2e7d32;">' + data.message + '</span>' : '<span style="color: #c62828;">' + data.message + '</span>';
            if (data.success) setTimeout(() => location.reload(), 500);
        })
        .catch(() => messageDiv.innerHTML = '<span style="color: #c62828;">Error. Please try again.</span>');
    });

    document.getElementById('editCarForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var messageDiv = document.getElementById('editCarMessage');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            messageDiv.innerHTML = data.success ? '<span style="color: #2e7d32;">' + data.message + '</span>' : '<span style="color: #c62828;">' + data.message + '</span>';
            if (data.success) setTimeout(() => location.reload(), 500);
        })
        .catch(() => messageDiv.innerHTML = '<span style="color: #c62828;">Error. Please try again.</span>');
    });

    // Close modals
    document.addEventListener('click', function(e) {
        ['addDriverModal', 'editDriverModal', 'addCarModal', 'editCarModal', 'assignModal', 'unassignModal'].forEach(id => {
            if (e.target === document.getElementById(id)) {
                if (id === 'addDriverModal') closeAddDriverModal();
                else if (id === 'editDriverModal') closeEditDriverModal();
                else if (id === 'addCarModal') closeAddCarModal();
                else if (id === 'editCarModal') closeEditCarModal();
                else if (id === 'assignModal') closeAssignModal();
                else if (id === 'unassignModal') closeUnassignModal();
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddDriverModal();
            closeEditDriverModal();
            closeAddCarModal();
            closeEditCarModal();
            closeAssignModal();
            closeUnassignModal();
        }
    });
    </script>

    <style>
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
        background: #f8f9ff;
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
    .dt-custom-requests table.dataTable tbody tr:hover {
        background: #f8f9ff !important;
    }
    .btn-warning-modal {
        background: #f57c00;
        color: white;
        padding: 8px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
    }
    .btn-warning-modal:hover {
        background: #e65100;
        transform: translateY(-1px);
    }
    .modal-close {
        float: right;
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #6c757d;
        line-height: 1;
        padding: 0 5px;
    }
    .modal-close:hover {
        color: #1a1a2e;
    }
    .btn-warning {
        background: #f57c00;
        color: white;
    }
    .btn-warning:hover {
        background: #e65100;
        color: white;
    }
    .info-text {
        font-size: 0.75rem;
        color: #6c757d;
        margin-top: 4px;
    }
    </style>    
</body>
</html>