<?php
session_start();
require_once '../includes/load.php';
require_admin();

$view_type = $_GET['view'] ?? 'daily';
$pending_count = $pdo->query("SELECT COUNT(*) FROM tbl_allocations WHERE status = 'pending'")->fetchColumn();

// Get in-progress trips for today (for Outgoing Trips card)
$in_progress_today = $pdo->query("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE status IN ('in_progress', 'completed') AND date = CURDATE()
")->fetchColumn();

// Get today's trips - EXCLUDING cancelled and pending
$today_trips = $pdo->query("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE date = CURDATE() 
    AND status NOT IN ('cancelled', 'pending', 'declined')
")->fetchColumn();

// Get weekly approved trips - EXCLUDING cancelled and pending
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

$approved_trips_count = $pdo->query("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE status = 'approved'
")->fetchColumn();

// Get total trips from first day of month to today - EXCLUDING cancelled and pending
$month_start = date('Y-m-01');
$month_end = date('Y-m-d');

// Get total trips - ALL trips regardless of date, EXCLUDING cancelled, pending, and declined
$total_trips_month = $pdo->query("
    SELECT COUNT(*) FROM tbl_allocations 
    WHERE status = 'completed'
");
$total_trips_month_count = $total_trips_month->fetchColumn();

// Get available cars count
$available_cars = $pdo->query("SELECT COUNT(*) FROM tbl_cars WHERE status = 'available'")->fetchColumn();
$total_cars = $pdo->query("SELECT COUNT(*) FROM tbl_cars")->fetchColumn();
$in_use_cars = $pdo->query("SELECT COUNT(*) FROM tbl_cars WHERE status = 'in_use'")->fetchColumn();
$maintenance_cars = $pdo->query("SELECT COUNT(*) FROM tbl_cars WHERE status = 'under_maintenance'")->fetchColumn();

// NOTE: previously hard-capped at LIMIT 5, which silently hid any cars beyond
// the first 5. Now we pull a larger working set (capped at 50 as a sane
// ceiling) and let the front-end paginate it 5-at-a-time instead.
$cars_in_use = $pdo->query("
    SELECT c.car_id, c.brand,
           a.pickup_time, a.dropoff_time, a.pickup_location, a.dropoff_location
    FROM tbl_allocations a
    JOIN tbl_cars c ON a.car_id = c.car_id
    WHERE a.date = CURDATE() 
    AND a.status = 'in_progress'
    ORDER BY c.brand
    LIMIT 50
")->fetchAll();

// dashboard.php - replace the today_schedule query around line 62
$today_schedule = $pdo->query("
    SELECT a.*, 
           c.brand, c.plate_number, c.parking, 
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
    WHERE a.date = CURDATE() AND a.status IN ('approved', 'in_progress', 'completed')
    ORDER BY FIELD(a.status, 'in_progress', 'approved', 'completed'), a.pickup_time
")->fetchAll();

foreach ($today_schedule as $key => $s) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$s['allocation_id']]);
    $today_schedule[$key]['passengers'] = $pass_stmt->fetchAll();
}

$weekly_schedule = $pdo->prepare("
    SELECT a.*, 
           c.brand, c.plate_number, c.parking, 
           d.name as driver_name, d.mobile as driver_mobile, 
           COALESCE(u.full_name, u.username) as requestor, u.email as requestor_email,
           DAYNAME(a.date) as day_name,
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
    WHERE a.date BETWEEN ? AND ? AND a.status IN ('approved', 'in_progress', 'completed')
    ORDER BY a.date, a.pickup_time
");
$weekly_schedule->execute([$week_start, $week_end]);
$weekly_schedule = $weekly_schedule->fetchAll();

foreach ($weekly_schedule as $key => $s) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$s['allocation_id']]);
    $weekly_schedule[$key]['passengers'] = $pass_stmt->fetchAll();
}

// ---- Driver-grouping helpers (mirrors schedule.php's weekly view) ----

function tripStatusPriority($status) {
    $order = ['in_progress' => 0, 'approved' => 1, 'completed' => 2, 'pending' => 3];
    return $order[$status] ?? 4;
}

function groupActiveDriversByDate($trips) {
    $map = [];
    foreach ($trips as $t) {
        $date = $t['date'];
        $did = $t['driver_id'];
        if (!isset($map[$date][$did])) {
            $map[$date][$did] = [
                'driver_id' => $did,
                'driver_name' => $t['driver_name'],
                'driver_mobile' => $t['driver_mobile'],
                'car_brand' => $t['brand'],
                'car_plate' => $t['plate_number'],
                'trip_count' => 0
            ];
        }
        $map[$date][$did]['trip_count']++;
    }
    return $map;
}

function extractDriverMeta($days) {
    $total = 0;
    $car = '';
    foreach ($days as $d) {
        foreach ($d['trips'] as $t) {
            $total++;
            if ($car === '' && !empty($t['brand'])) {
                $car = $t['brand'] . (!empty($t['plate_number']) ? ' (' . $t['plate_number'] . ')' : '');
            }
        }
    }
    return ['total_trips' => $total, 'car' => $car];
}

function buildDriverWeekPayload($driver_id, $driver_name, $driver_mobile, $week_start, $source_trips) {
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime($week_start . " +$i days"));
        $day_trips = array_values(array_filter($source_trips, function ($t) use ($driver_id, $date) {
            return $t['driver_id'] == $driver_id && $t['date'] == $date;
        }));
        if (count($day_trips) === 0) continue;
        usort($day_trips, function ($a, $b) {
            $pa = tripStatusPriority($a['status']);
            $pb = tripStatusPriority($b['status']);
            if ($pa !== $pb) return $pa <=> $pb;
            return strtotime($a['pickup_time']) <=> strtotime($b['pickup_time']);
        });
        $days[] = [
            'date' => $date,
            'display' => date('D, M j', strtotime($date)),
            'trips' => $day_trips
        ];
    }
    return array_merge([
        'driver_id' => $driver_id,
        'driver_name' => $driver_name,
        'driver_mobile' => $driver_mobile,
        'days' => $days
    ], extractDriverMeta($days));
}

function buildDriverDayPayload($driver_id, $driver_name, $driver_mobile, $date, $source_trips) {
    $day_trips = array_values(array_filter($source_trips, function ($t) use ($driver_id, $date) {
        return $t['driver_id'] == $driver_id && $t['date'] == $date;
    }));
    usort($day_trips, function ($a, $b) {
        $pa = tripStatusPriority($a['status']);
        $pb = tripStatusPriority($b['status']);
        if ($pa !== $pb) return $pa <=> $pb;
        return strtotime($a['pickup_time']) <=> strtotime($b['pickup_time']);
    });
    $days = [[
        'date' => $date,
        'display' => date('D, M j', strtotime($date)),
        'trips' => $day_trips
    ]];
    return array_merge([
        'driver_id' => $driver_id,
        'driver_name' => $driver_name,
        'driver_mobile' => $driver_mobile,
        'days' => $days
    ], extractDriverMeta($days));
}

// Group this week's trips by driver, per date
$active_drivers_by_date_weekly = groupActiveDriversByDate($weekly_schedule);

$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekly_data = [];
foreach ($week_days as $day) {
    $weekly_data[$day] = [];
}
foreach ($weekly_schedule as $trip) {
    $day = date('l', strtotime($trip['date']));
    if (isset($weekly_data[$day])) {
        $weekly_data[$day][] = $trip;
    }
}

// NOTE: previously LIMIT 10, which meant only 2 pages worth of data even
// existed to paginate. Bumped so the "next 7 days" window can actually be
// browsed 5-at-a-time instead of getting cut off.
$upcoming_trips = $pdo->prepare("
    SELECT a.*, c.brand, c.plate_number, d.name as driver_name 
    FROM tbl_allocations a 
    JOIN tbl_cars c ON a.car_id = c.car_id 
    JOIN tbl_drivers d ON a.driver_id = d.driver_id 
    WHERE a.date > CURDATE() AND a.date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
    AND a.status IN ('approved', 'pending')
    ORDER BY a.date, a.pickup_time
    LIMIT 30
");
$upcoming_trips->execute([]);
$upcoming_trips = $upcoming_trips->fetchAll();

// Mirrors the FIELD() ordering used in the today_schedule query above -
// gives the Status column a numeric sort key so DataTables' own default
// order doesn't undo the in_progress-first prioritization from the SQL.
$status_priority = ['in_progress' => 0, 'approved' => 1, 'completed' => 2];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - CARS</title>
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
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="requests.php">Requests</a>
                <a href="schedule.php">Schedule</a>
                <a href="driver_vehicle.php">Drivers & Vehicles</a>
                <a href="reports.php">Reports</a>
                <a href="#" onclick="openLogoutModal(); return false;">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
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

        <!-- Trip Details Modal -->
        <div class="trip-modal-overlay" id="tripModal">
            <div class="trip-modal-box">
                <button class="modal-close" onclick="closeTripModal()">&times;</button>
                <h3 id="tripModalTitle">Trip Details</h3>
                <div id="tripModalBody"></div>
            </div>
        </div>

        <!-- Driver Schedule Modal -->
        <div class="trip-modal-overlay" id="driverModal" onclick="if (event.target === this) closeDriverModal()">
            <div class="trip-modal-box" style="max-width:640px;">
                <button class="modal-close" onclick="closeDriverModal()">&times;</button>
                <h3 id="driverModalTitle">Driver Schedule</h3>
                <div id="driverModalBody"></div>
            </div>
        </div>

        <div class="page-header">
            <h2>ADMIN Dashboard</h2>
        </div>

        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-success" id="flashMessage"><?= htmlspecialchars($_SESSION['flash_message']) ?></div>
            <?php unset($_SESSION['flash_message']); ?>
            <script>
                setTimeout(function() {
                    var flash = document.getElementById('flashMessage');
                    if (flash) {
                        flash.style.transition = 'opacity 0.5s ease';
                        flash.style.opacity = '0';
                        setTimeout(function() {
                            flash.remove();
                        }, 500); 
                    }
                }, 1000); 
            </script>
        <?php endif; ?>

        <!-- Updated Summary Cards -->
        <div class="stats">
            <a href="requests.php?tab=requests" class="stat-box-link">
                <div class="stat-box" style="border-left-color: #d32f2f;">
                    <div class="number" style="color: #d32f2f;" id="statPending"><?= $pending_count ?></div>
                    <div class="label" style="color: #d32f2f;">Pending Requests</div>
                </div>
            </a>
            <a href="requests.php?tab=outgoing" class="stat-box-link">
                <div class="stat-box" style="border-left-color: #f9a825;">
                    <div class="number" style="color: #f9a825;" id="statOutgoing"><?= $in_progress_today ?></div>
                    <div class="label" style="color: #f9a825;">Outgoing Trips</div>
                </div>
            </a>
            <a href="schedule.php?view=daily&date=<?= date('Y-m-d') ?>" class="stat-box-link">
                <div class="stat-box" style="border-left-color: #00838f;">
                    <div class="number" style="color: #00838f;" id="statToday"><?= $today_trips ?></div>
                    <div class="label" style="color: #00838f;">Today's Trips</div>
                </div>
            </a>
            <a href="reports.php?type=trips&filter_status=approved" class="stat-box-link">
                <div class="stat-box" style="border-left-color: #6a1b9a;">
                    <div class="number" style="color: #6a1b9a;" id="statApproved"><?= $approved_trips_count ?></div>
                    <div class="label" style="color: #6a1b9a;">Approved Trips</div>
                </div>
            </a>
            <a href="reports.php?type=trips&filter_status=completed&start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-d') ?>" class="stat-box-link">
                <div class="stat-box" style="border-left-color: #2e7d32;">
                    <div class="number" style="color: #2e7d32;" id="statTotal"><?= $total_trips_month_count ?></div>
                    <div class="label" style="color: #2e7d32;">Total Trips Completed</div>
                </div>
            </a>
        </div>
        
        <!-- Cars Status Cards - Two columns -->
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px; margin-bottom:20px;">
            <!-- Cars Currently In Use -->
            <div class="card" style="margin-bottom:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h3 style="font-size:1.2rem; margin:0;">Cars Currently In Use</h3>
                </div>
                <div id="carsInUseContainer" class="cars-in-use" style="max-height:300px; overflow-y:auto;">
                    <?php if (count($cars_in_use) > 0): ?>
                        <?php foreach($cars_in_use as $i => $car): ?>
                            <div class="car-in-use-item paginated-item" data-index="<?= $i ?>" style="padding:6px 10px;">
                                <div class="car-info" style="width:100%;">
                                    <div class="brand" style="font-size:0.75rem; font-weight:600; color:#1a237e;"><?= htmlspecialchars($car['brand']) ?></div>
                                    <div class="trip-route" style="font-size:0.55rem; color:#6c757d; margin-top:1px;">
                                        <div style="display:flex; align-items:center; gap:3px;">
                                            <span style="color:#1a237e; font-weight:500;"><?= isset($car['pickup_time']) ? date('g:i A', strtotime($car['pickup_time'])) : 'N/A' ?></span>
                                            <span style="color:#adb5bd;">→</span>
                                            <span style="color:#1a237e; font-weight:500;"><?= isset($car['dropoff_time']) ? date('g:i A', strtotime($car['dropoff_time'])) : 'N/A' ?></span>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:3px; margin-top:1px;">
                                            <span style="color:#2e7d32;"><?= htmlspecialchars($car['pickup_location'] ?? 'N/A') ?></span>
                                            <span style="color:#adb5bd;">→</span>
                                            <span style="color:#c62828;"><?= htmlspecialchars($car['dropoff_location'] ?? 'N/A') ?></span>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge badge-in_progress" style="font-size:0.45rem; padding:1px 6px;">In Progress</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted" style="padding:10px 0; text-align:center; font-size:0.85rem;">No cars currently in use.</p>
                    <?php endif; ?>
                </div>
                <div class="pagination-controls" data-target="carsInUseContainer">
                    <button type="button" class="pg-btn pg-prev">‹ Prev</button>
                    <span class="pg-info">Page 1 of 1</span>
                    <button type="button" class="pg-btn pg-next">Next ›</button>
                </div>
            </div>

            <!-- Cars Status Summary -->
            <div class="card" style="margin-bottom:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h3 style="font-size:1rem; margin:0;">Cars Status Summary</h3>
                    <a href="driver_vehicle.php?tab=cars" class="btn btn-sm btn-primary" style="font-size:0.7rem; padding:4px 12px;">View All</a>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                    <div class="stat-card" style="border-left-color: #1a237e; padding:8px 12px;">
                        <div class="number" id="statTotalCars" style="font-size:1.2rem; color:#1a237e;"><?= $total_cars ?></div>
                        <div class="label" style="color:#1a237e;">Total Cars</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #2e7d32; padding:8px 12px;">
                        <div class="number" id="statAvailableCars" style="font-size:1.2rem; color:#2e7d32;"><?= $available_cars ?></div>
                        <div class="label" style="color:#2e7d32;">Available</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #f57c00; padding:8px 12px;">
                        <div class="number" id="statInUseCars" style="font-size:1.2rem; color:#f57c00;"><?= $in_use_cars ?></div>
                        <div class="label" style="color:#f57c00;">In Use</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #c62828; padding:8px 12px;">
                        <div class="number" id="statMaintenanceCars" style="font-size:1.2rem; color:#c62828;"><?= $maintenance_cars ?></div>
                        <div class="label" style="color:#c62828;">Under Maintenance</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="view-toggle">
            <a href="#" class="<?= $view_type == 'daily' ? 'active' : '' ?>" id="viewDaily" onclick="switchView('daily'); return false;">Daily View</a>
            <a href="#" class="<?= $view_type == 'weekly' ? 'active' : '' ?>" id="viewWeekly" onclick="switchView('weekly'); return false;">Weekly View</a>
        </div>

        <div class="dashboard-grid">
            <div class="card" style="grid-column: 1;">
                <!-- Daily View -->
                <div id="dailyView" style="<?= $view_type == 'daily' ? '' : 'display:none;' ?>">
                    <h3>Today's Schedule <span style="font-size:0.8rem; color:#6c757d; font-weight:normal;"><?= date('F j, Y') ?></span></h3>
                    <div id="todayScheduleContainer" style="margin-top:10px">
                        <?php if (count($today_schedule) > 0): ?>
                            <div class="table-container">
                                <table id="todayScheduleTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                                    <thead>
                                        <tr>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Departure</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Arrival</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Car</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Driver</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Requestor</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Location</th>
                                            <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="todayScheduleBody">
                                        <?php foreach($today_schedule as $t): ?>
                                        <tr class="trip-row-clickable" onclick="openTripModal(<?= htmlspecialchars(json_encode($t)) ?>)">
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= date('g:i A', strtotime($t['pickup_time'])) ?></td>
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $t['dropoff_time'] ? date('g:i A', strtotime($t['dropoff_time'])) : '-' ?></td>
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                                <?= htmlspecialchars($t['brand']) ?> 
                                                <span class="text-muted">(<?= htmlspecialchars($t['plate_number']) ?>)</span>
                                            </td>
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($t['driver_name']) ?></td>
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($t['requestor']) ?></td>
                                            <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                                <?= htmlspecialchars($t['pickup_location']) ?>
                                                <?php if (!empty($t['dropoff_location'])): ?>
                                                    <span style="font-size:0.7rem; color:#6c757d;">→ <?= htmlspecialchars($t['dropoff_location']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-order="<?= $status_priority[$t['status']] ?? 99 ?>" style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><span class="badge <?= getStatusBadgeClass($t['status']) ?>"><?= getStatusDisplay($t['status']) ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted" id="todayScheduleEmpty">No trips scheduled today.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Weekly View -->
                <div id="weeklyView" style="<?= $view_type == 'weekly' ? '' : 'display:none;' ?>">
                    <h3>Weekly Schedule <span style="font-size:0.8rem; color:#6c757d; font-weight:normal;"><?= date('M j', strtotime($week_start)) ?> - <?= date('M j, Y', strtotime($week_end)) ?></span></h3>
                    
                    <div class="weekly-grid" id="weeklyGrid">
                        <?php foreach($week_days as $day): ?>
                            <?php 
                            $day_date = date('Y-m-d', strtotime($day . ' this week'));
                            $is_today = $day_date == date('Y-m-d');
                            $drivers_today = $active_drivers_by_date_weekly[$day_date] ?? [];
                            $driver_count = count($drivers_today);
                            ?>
                            <div class="weekly-day <?= $is_today ? 'today' : '' ?>">
                                <div class="day-header">
                                    <?= substr($day, 0, 3) ?>
                                    <div class="date"><?= date('j', strtotime($day_date)) ?></div>
                                </div>
                                <?php if ($driver_count > 0): ?>
                                    <?php foreach ($drivers_today as $driver): ?>
                                        <?php 
                                        $payload = buildDriverDayPayload($driver['driver_id'], $driver['driver_name'], $driver['driver_mobile'], $day_date, $weekly_schedule);
                                        $payload['label'] = 'Today Schedule';
                                        ?>
                                        <div class="admin-driver-mini" onclick="openDriverModal(<?= htmlspecialchars(json_encode($payload)) ?>)">
                                            <span class="driver-status-dot active"></span>
                                            <span class="driver-mini-name"><?= htmlspecialchars($driver['driver_name']) ?><span class="trip-count-pill"><?= $driver['trip_count'] ?></span></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="admin-day-empty" style="font-size:0.6rem;">No trips</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card" style="grid-column: 2;">
                <h3>Upcoming Trips <span style="font-size:0.8rem; color:#6c757d; font-weight:normal;">Next 7 days</span></h3>
                
                <div id="upcomingTripsContainer">
                    <?php if (count($upcoming_trips) > 0): ?>
                        <div class="upcoming-list-compact">
                            <?php foreach($upcoming_trips as $i => $trip): ?>
                                <div class="upcoming-item-compact paginated-item" data-index="<?= $i ?>">
                                    <div class="upcoming-date">
                                        <div class="upcoming-day"><?= date('D', strtotime($trip['date'])) ?></div>
                                        <div class="upcoming-num"><?= date('j', strtotime($trip['date'])) ?></div>
                                    </div>
                                    <div class="upcoming-details">
                                        <div class="upcoming-car">
                                            <strong><?= htmlspecialchars($trip['brand']) ?></strong>
                                            <span class="text-muted">(<?= htmlspecialchars($trip['plate_number']) ?>)</span>
                                        </div>
                                        <div class="upcoming-meta">
                                            <span class="upcoming-time"><?= date('g:i A', strtotime($trip['pickup_time'])) ?></span>
                                            <span class="upcoming-location"><?= htmlspecialchars($trip['pickup_location']) ?></span>
                                        </div>
                                    </div>
                                    <div class="upcoming-status">
                                        <span class="badge <?= getStatusBadgeClass($trip['status']) ?>"><?= getStatusDisplay($trip['status']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" id="upcomingTripsEmpty">No upcoming trips in the next 7 days.</p>
                    <?php endif; ?>
                </div>
                <div class="pagination-controls" data-target="upcomingTripsContainer">
                    <button type="button" class="pg-btn pg-prev">‹ Prev</button>
                    <span class="pg-info">Page 1 of 1</span>
                    <button type="button" class="pg-btn pg-next">Next ›</button>
                </div>
                
                <div style="margin-top: 10px; text-align: center;">
                    <a href="schedule.php?view=weekly" class="btn btn-sm btn-primary">View Full Schedule</a>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and DataTables JS only (no Bootstrap) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    
    <script src="../assets/js/script.js"></script>
    <script src="../admin/assets/js/admin.js"></script>
    <script src="../assets/js/global-notif.js"></script>

    <script>
    $(document).ready(function() {
        if ($('#todayScheduleTable').length > 0) {
            $('#todayScheduleTable').DataTable({
                pageLength: 5,
                lengthMenu: [[5, 10, 25, -1], [5, 10, 25, "All"]],
                order: [[6, 'asc'], [0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [2, 4] }
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ trips",
                    infoEmpty: "No trips found",
                    infoFiltered: "(filtered from _MAX_ total trips)",
                    zeroRecords: "No matching trips found"
                },
                dom: '<"dt-top"lf>t<"dt-bottom"ip>',
                classes: {
                    sWrapper: 'dataTables_wrapper dt-custom'
                }
            });
        }
    });

    // Simple client-side pagination, 5 items per page, reused for both the
    // "Cars Currently In Use" list and the "Upcoming Trips" list.
    function initPagination(containerId, perPage) {
        var container = document.getElementById(containerId);
        if (!container) return;

        var items = Array.prototype.slice.call(container.querySelectorAll('.paginated-item'));
        var controls = document.querySelector('.pagination-controls[data-target="' + containerId + '"]');
        if (!controls) return;

        var prevBtn = controls.querySelector('.pg-prev');
        var nextBtn = controls.querySelector('.pg-next');
        var info = controls.querySelector('.pg-info');
        var totalPages = Math.max(1, Math.ceil(items.length / perPage));
        var currentPage = 1;

        function render() {
            items.forEach(function(item, idx) {
                var page = Math.floor(idx / perPage) + 1;
                item.style.display = (page === currentPage) ? '' : 'none';
            });
            if (info) info.textContent = 'Page ' + currentPage + ' of ' + totalPages;
            if (prevBtn) prevBtn.disabled = currentPage === 1;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages;
            controls.style.display = (items.length === 0 || totalPages <= 1) ? 'none' : 'flex';
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                if (currentPage > 1) { currentPage--; render(); }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                if (currentPage < totalPages) { currentPage++; render(); }
            });
        }

        render();
    }

    function formatTripTime(t) {
        if (!t) return '';
        const parts = t.split(':');
        const h = parseInt(parts[0], 10);
        const m = parts[1];
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = (h % 12 === 0) ? 12 : (h % 12);
        return hour12 + ':' + m + ' ' + period;
    }

    function tripStatusBadgeClass(status) {
        const map = {
            pending: 'badge-pending',
            approved: 'badge-approved',
            in_progress: 'badge-in_progress',
            completed: 'badge-completed'
        };
        return map[status] || '';
    }

    function tripStatusDisplay(status) {
        const map = {
            pending: 'Pending',
            approved: 'Approved',
            in_progress: 'In Progress',
            completed: 'Completed'
        };
        return map[status] || status;
    }

    function openDriverModal(data) {
        document.getElementById('driverModalTitle').textContent = data.driver_name + ' — ' + (data.label || 'Schedule');

        let html = '';
        let subtitleParts = [];

        if (typeof data.total_trips !== 'undefined') {
            subtitleParts.push('<span class="driver-modal-count">' + data.total_trips + ' trip' + (data.total_trips !== 1 ? 's' : '') + '</span>');
        }
        if (data.car) {
            subtitleParts.push('<span style="color:#343434; font-weight:700;">' + data.car + '</span>');
        }
        if (data.driver_mobile) {
            subtitleParts.push('<span style="color:#2e7d32; font-weight:700;">' + data.driver_mobile + '</span>');
        }
        if (subtitleParts.length > 0) {
            html += '<p class="driver-modal-mobile" style="font-size:0.95rem; margin-bottom:12px;">';
            html += subtitleParts.join(' &middot; ');
            html += '</p>';
        }

        if (data.days.length === 0) {
            html += '<div class="driver-modal-empty">No trips</div>';
        }

        data.days.forEach(function (day) {
            html += '<div class="driver-modal-day">';
            html += '<div class="driver-modal-day-head">' + day.display + '</div>';
            if (day.trips.length > 0) {
                day.trips.forEach(function (trip) {
                    const tripJson = JSON.stringify(trip).replace(/'/g, '&#39;');
                    let timeLabel = formatTripTime(trip.pickup_time);
                    if (trip.dropoff_time) {
                        timeLabel += ' – ' + formatTripTime(trip.dropoff_time);
                    }
                    html += '<div class="driver-modal-trip" onclick=\'event.stopPropagation(); openTripModal(' + tripJson + ')\'>';
                    html += '<span class="dm-time">' + timeLabel + '</span>';
                    html += '<span class="dm-req">#' + trip.request_number + '</span>';
                    html += '<span class="dm-loc">' + trip.pickup_location + (trip.dropoff_location ? ' → ' + trip.dropoff_location : '') + '</span>';
                    html += '<span class="badge ' + tripStatusBadgeClass(trip.status) + '">' + tripStatusDisplay(trip.status) + '</span>';
                    html += '</div>';
                });
            } else {
                html += '<div class="driver-modal-empty">No trips</div>';
            }
            html += '</div>';
        });

        document.getElementById('driverModalBody').innerHTML = html;
        document.getElementById('driverModal').classList.add('active');
    }

    function closeDriverModal() {
        document.getElementById('driverModal').classList.remove('active');
    }

        document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            const driverModalEl = document.getElementById('driverModal');
            if (driverModalEl && driverModalEl.classList.contains('active')) {
                closeDriverModal();
            }
        }
    });

    function switchView(view) {
        var daily = document.getElementById('dailyView');
        var weekly = document.getElementById('weeklyView');
        var dailyLink = document.getElementById('viewDaily');
        var weeklyLink = document.getElementById('viewWeekly');

        if (view === 'daily') {
            daily.style.display = '';
            weekly.style.display = 'none';
            dailyLink.classList.add('active');
            weeklyLink.classList.remove('active');
        } else {
            daily.style.display = 'none';
            weekly.style.display = '';
            weeklyLink.classList.add('active');
            dailyLink.classList.remove('active');
        }

        // Keep the URL in sync without reloading, so refresh/back-button still work
        var url = new URL(window.location);
        url.searchParams.set('view', view);
        window.history.replaceState({}, '', url);
    }

    document.addEventListener('DOMContentLoaded', function() {
        initPagination('carsInUseContainer', 8);
        initPagination('upcomingTripsContainer', 5);
    });
    </script>
    
    <style>
    .dt-custom .dataTables_length,
    .dt-custom .dataTables_filter {
        margin-bottom: 10px;
        font-size: 0.85rem;
    }
    .dt-custom .dataTables_length select,
    .dt-custom .dataTables_filter input {
        padding: 4px 8px;
        border: 2px solid #e9ecef;
        border-radius: 4px;
        font-size: 0.85rem;
        background: white;
    }
    .dt-custom .dataTables_filter input:focus {
        outline: none;
        border-color: #1a237e;
        box-shadow: 0 0 0 3px rgba(234, 234, 236, 0.08);
    }
    .dt-custom .dataTables_info {
        font-size: 0.85rem;
        color: #6c757d;
        padding-top: 10px;
    }
    .dt-custom .dataTables_paginate {
        padding-top: 10px;
    }
    .dt-custom .dataTables_paginate .paginate_button {
        padding: 4px 12px;
        margin: 0 2px;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        background: white;
        color: #1a1a2e !important;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s;
    }
    .dt-custom .dataTables_paginate .paginate_button:hover {
        background: #f8f9ff;
        border-color: #1a237e;
        color: #1a237e !important;
    }
    .dt-custom .dataTables_paginate .paginate_button.current {
        background: #1a237e;
        color: #ffffff !important;  
        border-color: #1a237e;
    }
    .dt-custom .dataTables_paginate .paginate_button.current:hover {
        background: #1a237e;
        color: #ffffff !important; 
    }
    .dt-custom .dataTables_paginate .paginate_button.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    .dt-custom .dataTables_empty {
        padding: 20px;
        color: #6c757d;
        text-align: center;
    }
    .dt-custom table.dataTable {
        margin-top: 10px !important;
        margin-bottom: 10px !important;
    }
    .dt-custom table.dataTable thead th {
        text-align: left;
        padding: 8px 10px;
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
    }
    .dt-custom table.dataTable tbody td {
        padding: 6px 10px;
        border-bottom: 1px solid #f1f3f5;
        vertical-align: middle;
    }
    .dt-custom table.dataTable tbody td .badge {
        white-space: nowrap;
        display: inline-block;
    }
    .dt-custom table.dataTable tbody tr:hover {
        background: #f8f9ff !important;
    }
    .dt-custom table.dataTable tbody tr.trip-row-clickable {
        cursor: pointer;
    }
    .dt-custom table.dataTable tbody tr.trip-row-clickable:hover {
        background: #f8f9ff !important;
    }
    .stat-card {
        background: white;
        padding: 12px 16px;
        border-radius: 8px;
        border-left: 4px solid #1a237e;
        box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    }
    .stat-card .number {
        font-size: 1.4rem;
        font-weight: 700;
        color: #1a237e;
    }
    .stat-card .label {
        font-size: 0.8rem;
        color: #6c757d;
    }

    /* Pagination controls for Cars In Use / Upcoming Trips lists */
    .pagination-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f1f3f5;
    }
    .pagination-controls .pg-info {
        font-size: 0.75rem;
        color: #6c757d;
    }
    .pagination-controls .pg-btn {
        padding: 4px 12px;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        background: white;
        color: #1a1a2e;
        cursor: pointer;
        font-size: 0.75rem;
        transition: all 0.2s;
    }
    .pagination-controls .pg-btn:hover:not(:disabled) {
        background: #f8f9ff;
        border-color: #1a237e;
        color: #1a237e;
    }
    .pagination-controls .pg-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    /* ---- Driver mini badges (Weekly view day columns) ---- */
    .admin-driver-mini {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 5px 8px;
        margin-bottom: 4px;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.7rem;
        background: #fff;
        transition: all 0.15s ease;
    }
    .admin-driver-mini:hover {
        background: #f5f7ff;
        border-color: #c5cae9;
    }
    .driver-status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #bdbdbd;
        flex-shrink: 0;
    }
    .driver-status-dot.active {
        background: #2e7d32;
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15);
    }
    .driver-mini-name {
        font-weight: 600;
        color: #1a237e;
        flex: 1;
        display: flex;
        align-items: center;
        gap: 5px;
        overflow: hidden;
    }
    .trip-count-pill {
        background: #1a237e;
        color: #fff;
        font-size: 0.55rem;
        font-weight: 800;
        padding: 1.1px 10px;
        border-radius: 10px;
        line-height: 1.5;
        box-shadow: 0 1px 4px rgba(26, 35, 126, 0.45);
        flex-shrink: 0;
    }

    /* ---- Driver schedule modal body ---- */
    .driver-modal-day {
        margin-bottom: 12px;
    }
    .driver-modal-day-head {
        font-weight: 600;
        font-size: 0.8rem;
        color: #1a237e;
        margin-bottom: 6px;
        padding-bottom: 4px;
        border-bottom: 1px solid #eee;
    }
    .driver-modal-trip {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 6px 8px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.8rem;
        margin-bottom: 4px;
        background: #fafafa;
    }
    .driver-modal-trip:hover {
        background: #eef1ff;
    }
    .dm-time {
        font-weight: 600;
        min-width: 148px;
        flex-shrink: 0;
    }
    .dm-req {
        color: #1a237e;
        font-weight: 600;
        font-size: 0.7rem;
        flex-shrink: 0;
    }
    .dm-loc {
        flex: 1;
        color: #555;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .driver-modal-empty {
        font-size: 0.75rem;
        color: #aaa;
        padding: 4px 8px;
    }
    .driver-modal-mobile {
        margin: 0 0 10px;
        color: #6c757d;
        font-size: 0.85rem;
    }
    .driver-modal-count {
        font-weight: 700;
        color: #1a237e;
        background: #e8eaf6;
        padding: 1px 8px;
        border-radius: 10px;
    }
    .driver-modal-trip .badge {
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 0.65rem;
        font-weight: 600;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .driver-modal-trip .badge.badge-pending { background: #fff3e0; color: #e65100; }
    .driver-modal-trip .badge.badge-approved { background: #e8f5e9; color: #2e7d32; }
    .driver-modal-trip .badge.badge-in_progress { background: #fff8e1; color: #f57c00; }
    .driver-modal-trip .badge.badge-completed { background: #e3f2fd; color: #0d47a1; }

    .trip-modal-overlay#driverModal { z-index: 9000 !important; }
    .trip-modal-overlay#tripModal { z-index: 9500 !important; }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }
    @keyframes slideInRight {
        from { transform: translateX(50px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    </style>
</body>
</html>