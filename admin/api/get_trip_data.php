<?php
session_start();
require_once '../../includes/load.php';
require_admin();

$trip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($trip_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid trip ID']);
    exit();
}

// Get all drivers for dropdown
$all_drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    WHERE d.status = 'active' 
    ORDER BY d.name
")->fetchAll();

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
    echo json_encode(['success' => false, 'message' => 'Trip not found']);
    exit();
}

// Get passengers for the trip
$pass_stmt = $pdo->prepare("
    SELECT p.passenger_name, p.passenger_id
    FROM tbl_allocated_passengers ap 
    JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
    WHERE ap.allocation_id = ?
");
$pass_stmt->execute([$trip_id]);
$trip_passengers = $pass_stmt->fetchAll();

// Get all passengers for the dropdown
$all_passengers = $pdo->prepare("SELECT * FROM tbl_passengers WHERE created_by = ? ORDER BY passenger_name");
$all_passengers->execute([$_SESSION['user_id']]);
$all_passengers = $all_passengers->fetchAll();

// Build the HTML
ob_start();
?>
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

    <div class="form-row-3">
        <div class="floating-group">
            <input type="date" class="form-control-modern" placeholder=" " name="date" id="edit_date" required value="<?= $trip_data['date'] ?>">
            <label for="edit_date">Trip Date <span class="required">*</span></label>
        </div>
        <div class="floating-group">
            <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="edit_pickup_time" required value="<?= $trip_data['pickup_time'] ?>">
            <label for="edit_pickup_time">Pickup Time <span class="required">*</span></label>
        </div>
        <div class="floating-group">
            <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="edit_dropoff_time" required value="<?= $trip_data['dropoff_time'] ?>">
            <label for="edit_dropoff_time">Dropoff Time <span class="required">*</span></label>
            <div class="dropoff-error-message" id="editDropoffError">Dropoff time must be after pickup time</div>
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
            <span class="passenger-count" id="editPassengerCount">(<?= count($trip_passengers) ?> selected)</span>
        </div>

        <div class="selected-passengers-display" id="editSelectedPassengersDisplay">
            <?php foreach($trip_passengers as $p): ?>
                <span class="selected-passenger-tag"><?= htmlspecialchars($p['passenger_name']) ?></span>
            <?php endforeach; ?>
        </div>

        <div class="passenger-grid" id="editPassengerGrid">
            <?php if (count($all_passengers) > 0): ?>
                <?php 
                $current_passenger_ids = array_column($trip_passengers, 'passenger_id');
                foreach($all_passengers as $p): 
                    $checked = in_array($p['passenger_id'], $current_passenger_ids) ? 'checked' : '';
                ?>
                    <label class="<?= $checked ? 'selected' : '' ?>">
                        <input type="checkbox" name="passengers[]" value="<?= $p['passenger_id'] ?>" <?= $checked ?> onchange="updateEditPassengerDisplay()">
                        <span class="passenger-name"><?= htmlspecialchars($p['passenger_name']) ?></span>
                        <?php if (!empty($p['contact'])): ?>
                            <span class="contact-info"> <?= htmlspecialchars($p['contact']) ?></span>
                        <?php endif; ?>
                        <button type="button" class="delete-passenger-btn" onclick="deleteEditPassenger(<?= $p['passenger_id'] ?>, '<?= htmlspecialchars($p['passenger_name']) ?>')" title="Delete passenger">
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
                <input type="text" id="editNewPassengerName" placeholder="Enter passenger name" style="border-color: #ddd;">
            </div>
            <div class="form-group">
                <input type="text" id="editNewPassengerContact" placeholder="Contact (optional)" style="border-color: #ddd;">
            </div>
            <button type="button" class="btn btn-success btn-sm" id="addEditPassengerBtn">Add Passenger</button>
        </div>
        <div id="editAddPassengerMessage" style="margin-top:5px; font-size:0.8rem;"></div>
        <div class="info-text">Select at least one passenger or add a new one.</div>
    </div>

    <div class="btn-group">
        <button type="submit" class="btn btn-primary">Update Trip</button>
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
    </div>
</form>
<?php
$html = ob_get_clean();

echo json_encode(['success' => true, 'html' => $html]);
?>