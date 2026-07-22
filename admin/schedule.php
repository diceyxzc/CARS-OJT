<?php
session_start();
require_once '../includes/load.php';
require_admin();

$view_type = $_GET['view'] ?? 'daily';
$filter_date = $_GET['date'] ?? date('Y-m-d');

// Label shown in the driver schedule modal title, based on which view triggered it
if ($view_type == 'weekly') {
    $schedule_modal_label = 'Weekly Schedule';
} elseif ($view_type == 'monthly') {
    $schedule_modal_label = 'Month Schedule';
} else {
    $schedule_modal_label = 'Today Schedule';
}

$schedule = [];
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$week_days = [];

// Monthly view variables
$month_year = $_GET['month'] ?? date('Y-m');
$month_start = date('Y-m-01', strtotime($month_year));
$month_end = date('Y-m-t', strtotime($month_year));
$month_days = [];

// ---- Helpers for the new driver-based grouping ----

// Priority order for sorting trips within a day in the driver modal:
// in_progress first, then approved, then completed, anything else (e.g. pending) last.
function tripStatusPriority($status) {
    $order = ['in_progress' => 0, 'approved' => 1, 'completed' => 2, 'pending' => 3];
    return $order[$status] ?? 4;
}

// Pulls total trip count and a car label (from the first trip found) out of a days array
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

// Attaches passenger list to each trip row
function attachPassengers($pdo, &$trips) {
    foreach ($trips as $key => $t) {
        $pass_stmt = $pdo->prepare("
            SELECT p.passenger_name 
            FROM tbl_allocated_passengers ap 
            JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
            WHERE ap.allocation_id = ?
        ");
        $pass_stmt->execute([$t['allocation_id']]);
        $trips[$key]['passengers'] = $pass_stmt->fetchAll();
    }
}

// Groups trips into: [date => [driver_id => ['driver_id','driver_name','driver_mobile','car_brand','car_plate','trip_count']]]
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

// Builds the payload for a driver's weekly modal, ordered latest day -> soonest day.
// Scoped strictly to the 7-day week starting at $week_start, but only includes
// days the driver actually has trips on (blank days are skipped, not shown).
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
    // Latest day first, soonest day last
    return array_merge([
        'driver_id' => $driver_id,
        'driver_name' => $driver_name,
        'driver_mobile' => $driver_mobile,
        'days' => $days
    ], extractDriverMeta($days));
}

// Builds the payload for a driver's modal in Daily view — just that single day's trips.
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

// Builds the payload for a driver's modal in Monthly view — only the dates
// within the month that the driver actually has trips on (no blank filler days).
function buildDriverMonthPayload($driver_id, $driver_name, $driver_mobile, $source_trips) {
    $by_date = [];
    foreach ($source_trips as $t) {
        if ($t['driver_id'] != $driver_id) continue;
        $by_date[$t['date']][] = $t;
    }
    krsort($by_date); // latest date first
    $days = [];
    foreach ($by_date as $date => $day_trips) {
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

if ($view_type == 'monthly') {
    $month_year = $_GET['month'] ?? date('Y-m');
    $month_start = date('Y-m-01', strtotime($month_year));
    $month_end = date('Y-m-t', strtotime($month_year));

    $stmt = $pdo->prepare("
        SELECT a.*, a.request_number, 
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
        WHERE a.date BETWEEN ? AND ? 
        AND a.status IN ('pending', 'approved', 'completed', 'in_progress') 
        ORDER BY a.date, a.pickup_time
    ");
    $stmt->execute([$month_start, $month_end]);
    $schedule = $stmt->fetchAll();
    attachPassengers($pdo, $schedule);

    $active_drivers_by_date = groupActiveDriversByDate($schedule);

    // Build monthly calendar skeleton
    $first_day = date('N', strtotime($month_start));
    $days_in_month = date('t', strtotime($month_start));
    $month_days = [];

    for ($i = 1; $i < $first_day; $i++) {
        $month_days[] = null;
    }
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date = date('Y-m-d', strtotime($month_year . '-' . str_pad($day, 2, '0', STR_PAD_LEFT)));
        $month_days[] = [
            'date' => $date,
            'day' => $day
        ];
    }

    $pending_count = 0;
    $approved_count = 0;
    $in_progress_count = 0;
    $completed_count = 0;

    foreach ($schedule as $t) {
        if ($t['status'] == 'pending') $pending_count++;
        elseif ($t['status'] == 'approved') $approved_count++;
        elseif ($t['status'] == 'in_progress') $in_progress_count++;
        elseif ($t['status'] == 'completed') $completed_count++;
    }

} elseif ($view_type == 'weekly') {
    $week_start = $_GET['week'] ?? date('Y-m-d');
    $week_start = date('Y-m-d', strtotime($week_start . ' - ' . (date('N', strtotime($week_start)) - 1) . ' days'));
    $week_end = date('Y-m-d', strtotime($week_start . ' + 6 days'));

    $stmt = $pdo->prepare("
        SELECT a.*, a.request_number, 
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
        WHERE a.date BETWEEN ? AND ? 
        AND a.status IN ('pending', 'approved', 'completed', 'in_progress') 
        ORDER BY a.date, a.pickup_time
    ");
    $stmt->execute([$week_start, $week_end]);
    $schedule = $stmt->fetchAll();
    attachPassengers($pdo, $schedule);

    $active_drivers_by_date = groupActiveDriversByDate($schedule);

    $week_days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime($week_start . ' + ' . $i . ' days'));
        $week_days[$date] = [
            'date' => $date,
            'display' => date('D, M j', strtotime($date))
        ];
    }

    $pending_count = 0;
    $approved_count = 0;
    $in_progress_count = 0;
    $completed_count = 0;

    foreach ($schedule as $t) {
        if ($t['status'] == 'pending') $pending_count++;
        elseif ($t['status'] == 'approved') $approved_count++;
        elseif ($t['status'] == 'in_progress') $in_progress_count++;
        elseif ($t['status'] == 'completed') $completed_count++;
    }

} else {
    // Daily view
    $stmt = $pdo->prepare("
        SELECT a.*, a.request_number,  
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
        WHERE a.date = ? 
        AND a.status IN ('pending', 'approved', 'completed', 'in_progress')
        ORDER BY a.pickup_time
    ");
    $stmt->execute([$filter_date]);
    $schedule = $stmt->fetchAll();
    attachPassengers($pdo, $schedule);

    $active_drivers_by_date = groupActiveDriversByDate($schedule);

    $pending_count = 0;
    $approved_count = 0;
    $in_progress_count = 0;
    $completed_count = 0;

    foreach ($schedule as $t) {
        if ($t['status'] == 'pending') $pending_count++;
        elseif ($t['status'] == 'approved') $approved_count++;
        elseif ($t['status'] == 'in_progress') $in_progress_count++;
        elseif ($t['status'] == 'completed') $completed_count++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Schedule - CARS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../admin/assets/css/admin.css">
    <style>
        /* Custom styles for navigation buttons */
        .week-nav .btn-outline {
            background: white;
            color: #333;
            border: 1px solid #ddd;
            padding: 6px 16px;
            font-size: 0.8rem;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .week-nav .btn-outline:hover {
            background: #f5f5f5;
            border-color: #bbb;
            color: #333;
        }
        .week-nav .btn-primary {
            background: #1a237e;
            color: white;
            border: 1px solid #1a237e;
            padding: 6px 16px;
            font-size: 0.8rem;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .week-nav .btn-primary:hover {
            background: #0d1b5e;
            border-color: #0d1b5e;
            color: white;
        }
        .week-nav .date-picker-form {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .week-nav .date-picker-form input[type="date"] {
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.8rem;
            height: 32px;
        }
        .week-nav .date-picker-form .btn-view {
            background: #1a237e;
            color: white;
            border: 1px solid #1a237e;
            padding: 5px 14px;
            font-size: 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            height: 32px;
            transition: all 0.2s ease;
        }
        .week-nav .date-picker-form .btn-view:hover {
            background: #0d1b5e;
            border-color: #0d1b5e;
            color: white;
        }
        .week-nav .week-label {
            font-weight: 500;
            font-size: 0.9rem;
            color: #333;
            margin-left: auto;
        }
        .week-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .week-nav .nav-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* ---- Driver-based active list (Daily view) ---- */
        .admin-driver-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .admin-driver-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            background: #fff;
            transition: all 0.15s ease;
        }
        .admin-driver-card:hover {
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
        .driver-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .driver-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1a237e;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .driver-mobile {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .badge-active {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .driver-car-tag {
            font-size: 0.72rem;
            font-weight: 500;
            color: #455a64;
            background: #eceff5;
            padding: 1px 9px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .trip-count-pill {
            background: #1a237e;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 800;
            padding: 2px 10px;
            border-radius: 10px;
            line-height: 1.5;
            box-shadow: 0 1px 4px rgba(26, 35, 126, 0.45);
            flex-shrink: 0;
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
        .driver-mini-name {
            font-weight: 600;
            color: #1a237e;
            flex: 1;
            display: flex;
            align-items: center;
            gap: 5px;
            overflow: hidden;
        }
        .driver-car-tag-mini {
            font-size: 0.62rem;
            font-weight: 500;
            color: #455a64;
            background: #eceff5;
            padding: 0 6px;
            border-radius: 6px;
            flex-shrink: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 70px;
        }
        .driver-mini-name .trip-count-pill {
            font-size: 0.55rem;
            padding: 1.1px 10px;
            flex-shrink: 0;
        }

/* ---- Driver mini badges (Monthly view day cells) ---- */
        .month-driver-mini {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            background: #f5f5f5;
            margin-bottom: 4px;
        }
        .month-driver-mini:hover {
            background: #e8f0fe;
        }
        .month-driver-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #bdbdbd;
            flex-shrink: 0;
        }
        .month-driver-dot.active {
            background: #2e7d32;
        }
        .month-driver-name {
            font-size: 0.8rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ---- Bigger monthly calendar ---- */
        .admin-month-grid {
            gap: 6px;
        }
        .month-header {
            font-size: 0.95rem;
            padding: 10px 0;
        }
        .month-day {
            min-height: 150px;
            padding: 8px;
        }
        .month-day-number {
            font-size: 1.1rem;
            margin-bottom: 6px;
        }
        .month-driver-mini {
            font-size: 0.72rem;
            padding: 3px 6px;
            gap: 6px;
            margin-bottom: 3px;
        }
        .month-driver-dot {
            width: 8px;
            height: 8px;
        }
        .month-trip-count {
            font-size: 0.72rem;
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

        /* Make sure the trip-details modal always renders above the driver-schedule
           modal when opened from inside it, instead of behind it. */
        .trip-modal-overlay#driverModal {
            z-index: 9000 !important;
        }
        .trip-modal-overlay#tripModal {
            z-index: 9500 !important;
        }

        /* Explicit color-coded status badges inside the driver modal, so they
           always match the rest of the page regardless of external stylesheet rules. */
        .driver-modal-trip .badge {
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 600;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .driver-modal-trip .badge.badge-pending {
            background: #fff3e0;
            color: #e65100;
        }
        .driver-modal-trip .badge.badge-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .driver-modal-trip .badge.badge-in_progress {
            background: #fff8e1;
            color: #f57c00;
        }
        .driver-modal-trip .badge.badge-completed {
            background: #e3f2fd;
            color: #0d47a1;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="dashboard.php" class="navbar-brand">CARS <span>Admin</span></a>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="requests.php">Requests</a>
                <a href="schedule.php" class="active">Schedule</a>
                <a href="driver_vehicle.php">Drivers & Vehicles</a>
                <a href="reports.php">Reports</a>
                <a href="#" onclick="openLogoutModal(); return false;">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="modal-overlay" id="logoutModal">
            <div class="modal-box">
                <h3>Logout Confirmation</h3>
                <p>Are you sure you want to logout?</p>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel-modal" onclick="closeLogoutModal()">Cancel</button>
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

        <div class="page-header" style="margin-bottom:10px;">
            <div>
                <h2>Trip Schedule</h2>
            </div>
        </div>

        <div class="admin-view-toggle">
            <a href="?view=daily&date=<?= $filter_date ?>" class="<?= $view_type == 'daily' ? 'active' : '' ?>">Daily View</a>
            <a href="?view=weekly<?= isset($_GET['week']) ? '&week='.$_GET['week'] : '' ?>" class="<?= $view_type == 'weekly' ? 'active' : '' ?>">Weekly View</a>
            <a href="?view=monthly&month=<?= $month_year ?>" class="<?= $view_type == 'monthly' ? 'active' : '' ?>">Monthly View</a>
        </div>

        <?php if ($view_type == 'daily'): ?>
            <!-- Daily View -->
            <div class="week-nav">
                <div class="nav-group">
                    <a href="?view=daily&date=<?= date('Y-m-d', strtotime($filter_date . ' -1 day')) ?>" class="btn-outline">◀ Previous</a>
                    <a href="?view=daily&date=<?= date('Y-m-d') ?>" class="btn-primary">Today</a>
                    <a href="?view=daily&date=<?= date('Y-m-d', strtotime($filter_date . ' +1 day')) ?>" class="btn-outline">Next ▶</a>
                </div>
                <form method="GET" class="date-picker-form">
                    <input type="hidden" name="view" value="daily">
                    <input type="date" name="date" value="<?= $filter_date ?>">
                    <button type="submit" class="btn-view">View</button>
                </form>
                <span class="week-label"><?= date('l, F j, Y', strtotime($filter_date)) ?></span>
            </div>

            <div class="card" style="padding:12px 16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
                    <h3 style="font-size:1rem; margin:0;"><?= date('l, F j, Y', strtotime($filter_date)) ?></h3>
                    <?php if (count($schedule) > 0): ?>
                        <div class="admin-schedule-stats" style="margin:0;">
                            <span class="stat-item">Total: <strong><?= count($schedule) ?></strong></span>
                            <?php if ($pending_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff3e0;">Pending: <strong style="color:#e65100;"><?= $pending_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($approved_count > 0): ?>
                                <span class="stat-item" style="border-color:#e8f5e9;">Approved: <strong style="color:#2e7d32;"><?= $approved_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($in_progress_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff8e1;">In Progress: <strong style="color:#f57c00;"><?= $in_progress_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($completed_count > 0): ?>
                                <span class="stat-item" style="border-color:#e3f2fd;">Completed: <strong style="color:#0d47a1;"><?= $completed_count ?></strong></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php $today_drivers = $active_drivers_by_date[$filter_date] ?? []; ?>
                <?php if (count($today_drivers) > 0): ?>
                    <div class="admin-driver-list">
                        <?php foreach ($today_drivers as $driver): ?>
                            <?php $payload = buildDriverDayPayload($driver['driver_id'], $driver['driver_name'], $driver['driver_mobile'], $filter_date, $schedule); $payload['label'] = $schedule_modal_label; ?>
                            <div class="admin-driver-card" onclick="openDriverModal(<?= htmlspecialchars(json_encode($payload)) ?>)">
                                <div class="driver-status-dot active"></div>
                                <div class="driver-info">
                                    <span class="driver-name"><?= htmlspecialchars($driver['driver_name']) ?><span class="driver-car-tag"><?= htmlspecialchars($driver['car_brand']) ?> (<?= htmlspecialchars($driver['car_plate']) ?>)</span><span class="trip-count-pill"><?= $driver['trip_count'] ?></span></span>
                                    <span class="driver-mobile"><?= htmlspecialchars($driver['driver_mobile']) ?></span>
                                </div>
                                <span class="badge-active">Active</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px 0;">
                        <p style="font-size:0.95rem; margin-bottom:4px;">No trips on this date</p>
                        <p class="text-muted" style="font-size:0.8rem;">There are no trips scheduled for <?= date('l, F j, Y', strtotime($filter_date)) ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view_type == 'weekly'): ?>
            <!-- Weekly View -->
            <div class="week-nav">
                <div class="nav-group">
                    <a href="?view=weekly&week=<?= date('Y-m-d', strtotime($week_start . ' - 7 days')) ?>" class="btn-outline">◀ Previous</a>
                    <a href="?view=weekly&week=<?= date('Y-m-d') ?>" class="btn-primary">This Week</a>
                    <a href="?view=weekly&week=<?= date('Y-m-d', strtotime($week_start . ' + 7 days')) ?>" class="btn-outline">Next ▶</a>
                </div>
                <span class="week-label"><?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?></span>
            </div>

            <div class="card" style="padding:12px 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                    <h3 style="font-size:1rem; margin:0;"><?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?></h3>
                    <?php if (count($schedule) > 0): ?>
                        <div class="admin-schedule-stats" style="margin:0;">
                            <span class="stat-item">Total: <strong><?= count($schedule) ?></strong></span>
                            <?php if ($pending_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff3e0;">Pending: <strong style="color:#e65100;"><?= $pending_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($approved_count > 0): ?>
                                <span class="stat-item" style="border-color:#e8f5e9;">Approved: <strong style="color:#2e7d32;"><?= $approved_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($in_progress_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff8e1;">In Progress: <strong style="color:#f57c00;"><?= $in_progress_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($completed_count > 0): ?>
                                <span class="stat-item" style="border-color:#e3f2fd;">Completed: <strong style="color:#0d47a1;"><?= $completed_count ?></strong></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($schedule) > 0): ?>
                    <div class="admin-week-grid">
                        <?php foreach ($week_days as $date => $day): ?>
                            <?php
                            $is_today = $date == date('Y-m-d');
                            $drivers_today = $active_drivers_by_date[$date] ?? [];
                            $driver_count = count($drivers_today);
                            ?>
                            <div class="admin-day-card <?= $is_today ? 'today' : '' ?>">
                                <div class="day-head">
                                    <div><?= date('D', strtotime($date)) ?></div>
                                    <div class="num"><?= date('j', strtotime($date)) ?></div>
                                    <div class="count"><?= $driver_count ?> driver<?= $driver_count != 1 ? 's' : '' ?></div>
                                </div>
                                <?php if ($driver_count > 0): ?>
                                    <?php foreach ($drivers_today as $driver): ?>
                                        <?php $payload = buildDriverDayPayload($driver['driver_id'], $driver['driver_name'], $driver['driver_mobile'], $date, $schedule); $payload['label'] = $schedule_modal_label; ?>
                                        <div class="admin-driver-mini" onclick="openDriverModal(<?= htmlspecialchars(json_encode($payload)) ?>)">
                                            <span class="driver-status-dot active"></span>
                                            <span class="driver-mini-name"><?= htmlspecialchars($driver['driver_name']) ?><span class="trip-count-pill"><?= $driver['trip_count'] ?></span></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="admin-day-empty">No trips</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px 0;">
                        <p class="text-muted" style="font-size:0.8rem;">There are no trips scheduled for this week.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Monthly View -->
            <div class="week-nav">
                <div class="nav-group">
                    <a href="?view=monthly&month=<?= date('Y-m', strtotime($month_year . ' -1 month')) ?>" class="btn-outline">◀ Previous</a>
                    <a href="?view=monthly&month=<?= date('Y-m') ?>" class="btn-primary">This Month</a>
                    <a href="?view=monthly&month=<?= date('Y-m', strtotime($month_year . ' +1 month')) ?>" class="btn-outline">Next ▶</a>
                </div>
                <span class="week-label"><?= date('F Y', strtotime($month_year)) ?></span>
            </div>

            <div class="card" style="padding:12px 14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
                    <h3 style="font-size:1rem; margin:0;"><?= date('F Y', strtotime($month_year)) ?></h3>
                    <?php if (count($schedule) > 0): ?>
                        <div class="admin-schedule-stats" style="margin:0;">
                            <span class="stat-item">Total: <strong><?= count($schedule) ?></strong></span>
                            <?php if ($pending_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff3e0;">Pending: <strong style="color:#e65100;"><?= $pending_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($approved_count > 0): ?>
                                <span class="stat-item" style="border-color:#e8f5e9;">Approved: <strong style="color:#2e7d32;"><?= $approved_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($in_progress_count > 0): ?>
                                <span class="stat-item" style="border-color:#fff8e1;">In Progress: <strong style="color:#f57c00;"><?= $in_progress_count ?></strong></span>
                            <?php endif; ?>
                            <?php if ($completed_count > 0): ?>
                                <span class="stat-item" style="border-color:#e3f2fd;">Completed: <strong style="color:#0d47a1;"><?= $completed_count ?></strong></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($schedule) > 0): ?>
                    <div class="admin-month-grid">
                        <!-- Day headers -->
                        <div class="month-header">Mon</div>
                        <div class="month-header">Tue</div>
                        <div class="month-header">Wed</div>
                        <div class="month-header">Thu</div>
                        <div class="month-header">Fri</div>
                        <div class="month-header">Sat</div>
                        <div class="month-header">Sun</div>

                        <?php foreach ($month_days as $day): ?>
                            <?php if ($day === null): ?>
                                <div class="month-day empty"></div>
                            <?php else: ?>
                                <?php
                                $is_today = $day['date'] == date('Y-m-d');
                                $drivers_today = $active_drivers_by_date[$day['date']] ?? [];
                                $driver_count = count($drivers_today);
                                ?>
                                <div class="month-day <?= $is_today ? 'today' : '' ?> <?= $driver_count > 0 ? 'has-trips' : '' ?>">
                                    <div class="month-day-number"><?= $day['day'] ?></div>
                                    <?php if ($driver_count > 0): ?>
                                        <div class="month-day-trips">
                                            <?php foreach ($drivers_today as $driver): ?>
                                                <?php $payload = buildDriverDayPayload($driver['driver_id'], $driver['driver_name'], $driver['driver_mobile'], $day['date'], $schedule); $payload['label'] = $schedule_modal_label; ?>
                                                <div class="month-driver-mini" onclick="openDriverModal(<?= htmlspecialchars(json_encode($payload)) ?>)">
                                                    <span class="month-driver-dot active"></span>
                                                    <span class="month-driver-name"><?= htmlspecialchars($driver['driver_name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="month-trip-count"><?= $driver_count ?> driver<?= $driver_count != 1 ? 's' : '' ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px 0;">
                        <p class="text-muted" style="font-size:0.8rem;">There are no trips scheduled for this month.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="../assets/js/script.js"></script>
    <script src="../admin/assets/js/admin.js"></script>
    <script>
    // ---- Driver schedule modal ----
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
        
        // Show trips count first - using span with class
        if (typeof data.total_trips !== 'undefined') {
            subtitleParts.push('<span class="driver-modal-count">' + data.total_trips + ' trip' + (data.total_trips !== 1 ? 's' : '') + '</span>');
        }
        
        // Show car - blue and bold
        if (data.car) {
            subtitleParts.push('<span style="color:#343434; font-weight:700;">' + data.car + '</span>');
        }
        
        // Show mobile number - green and bold
        if (data.driver_mobile) {
            subtitleParts.push('<span style="color:#2e7d32; font-weight:700;">' + data.driver_mobile + '</span>');
        }
        
        // Display all on the same line
        if (subtitleParts.length > 0) {
            html += '<p class="driver-modal-mobile" style="font-size:0.95rem; margin-bottom:12px;">';
            html += subtitleParts.join(' &middot; ');
            html += '</p>';
        }

        if (data.days.length === 0) {
            html += '<div class="driver-modal-empty">No trips</div>';
        }

        // --- ORIGINAL TRIPS DISPLAY - KEPT EXACTLY AS IS ---
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

    // Escape key closes only the topmost modal currently visible
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            const tripModalEl = document.getElementById('tripModal');
            const driverModalEl = document.getElementById('driverModal');
            const logoutModalEl = document.getElementById('logoutModal');

            if (tripModalEl && tripModalEl.classList.contains('active')) {
                if (typeof closeTripModal === 'function') closeTripModal();
            } else if (driverModalEl && driverModalEl.classList.contains('active')) {
                closeDriverModal();
            } else if (logoutModalEl && logoutModalEl.classList.contains('active')) {
                if (typeof closeLogoutModal === 'function') closeLogoutModal();
            }
        }
    });
    </script>
</body>
</html>