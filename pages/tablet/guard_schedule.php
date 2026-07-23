<?php
session_start();
require_once '../../includes/load.php';

// Get ALL drivers - both active AND inactive (no status filter)
$drivers = $pdo->query("
    SELECT d.*, c.brand, c.plate_number, c.parking 
    FROM tbl_drivers d 
    LEFT JOIN tbl_cars c ON d.car_id = c.car_id 
    ORDER BY d.name
")->fetchAll();

// Get current week dates
$week_start = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d');
$week_start = date('Y-m-d', strtotime($week_start . ' - ' . (date('N', strtotime($week_start)) - 1) . ' days'));
$week_end = date('Y-m-d', strtotime($week_start . ' + 6 days'));

// Get trips for this week - ONLY approved and in_progress
$trips = $pdo->prepare("
    SELECT a.*, c.brand, c.plate_number, c.parking, d.name as driver_name, d.driver_id, COALESCE(u.full_name, u.username) as requestor,
           d.status as driver_status
    FROM tbl_allocations a 
    JOIN tbl_cars c ON a.car_id = c.car_id 
    JOIN tbl_drivers d ON a.driver_id = d.driver_id 
    JOIN tbl_users u ON a.requestor_id = u.user_id 
    WHERE a.date BETWEEN ? AND ? 
    AND a.status IN ('approved', 'in_progress')
    ORDER BY a.date, a.pickup_time
");
$trips->execute([$week_start, $week_end]);
$trips = $trips->fetchAll();

// Get passengers for each trip
foreach ($trips as $key => $trip) {
    $pass_stmt = $pdo->prepare("
        SELECT p.passenger_name 
        FROM tbl_allocated_passengers ap 
        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
        WHERE ap.allocation_id = ?
    ");
    $pass_stmt->execute([$trip['allocation_id']]);
    $trips[$key]['passengers'] = $pass_stmt->fetchAll();
}

// Organize trips by day and driver
$week_days = [];

for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . ' + ' . $i . ' days'));
    $week_days[$date] = [
        'date' => $date,
        'display' => date('D, M j', strtotime($date)),
        'day_name' => date('l', strtotime($date)),
        'drivers' => []
    ];

    foreach ($drivers as $driver) {
        $week_days[$date]['drivers'][$driver['driver_id']] = [
            'driver' => $driver,
            'trips' => []
        ];
    }
}

foreach ($trips as $trip) {
    if (isset($week_days[$trip['date']]) && isset($week_days[$trip['date']]['drivers'][$trip['driver_id']])) {
        $week_days[$trip['date']]['drivers'][$trip['driver_id']]['trips'][] = $trip;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Schedule - CARS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../admin/assets/css/admin.css">
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link rel="stylesheet" href="../../pages/tablet/css/guard.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="schedule.php" class="navbar-brand">CARS <span>Trip Monitoring</span></a>
            <div class="nav-links">
                <a href="gen_guard.php">Trips</a>
                <a href="guard_schedule.php" class="active">Schedule</a>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <div class="page-header">
            <h2>Driver Weekly Schedule</h2>
        </div>

        <div class="week-nav">
            <a href="?week=<?= date('Y-m-d', strtotime($week_start . ' -7 days')) ?>" class="btn btn-outline">◀ Previous</a>
            <a href="?week=<?= date('Y-m-d') ?>" class="btn btn-primary">This Week</a>
            <a href="?week=<?= date('Y-m-d', strtotime($week_start . ' +7 days')) ?>" class="btn btn-outline">Next ▶</a>
            <span class="week-label"><?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?></span>
        </div>

        <div class="legend">
            <span class="legend-item"><span class="dot approved"></span> Approved</span>
            <span class="legend-item"><span class="dot in_progress"></span> In Progress</span>
            <span style="color:#6c757d; margin-left:auto;"><?= count($drivers) ?> total drivers • <?= count($trips) ?> trips</span>
        </div>

        <div class="schedule-wrapper">
            <div class="schedule-grid">
                <div class="schedule-header">
                    <div class="cell">Driver / Car</div>
                    <?php foreach ($week_days as $date => $day):
                        $is_today = $date == date('Y-m-d');
                    ?>
                        <div class="cell <?= $is_today ? 'today' : '' ?>">
                            <?= date('D', strtotime($date)) ?>
                            <br>
                            <span style="font-weight:400; font-size:0.7rem;"><?= date('M j', strtotime($date)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($drivers as $driver): ?>
                    <div class="schedule-row">
                        <div class="cell driver-name">
                            <?= htmlspecialchars($driver['name']) ?>
                            <span class="car-info">
                                <?php if ($driver['car_id']): ?>
                                    <?= htmlspecialchars($driver['brand']) ?> (<?= htmlspecialchars($driver['plate_number']) ?>)
                                <?php else: ?>
                                    <span style="color:#999;">No car assigned</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php foreach ($week_days as $date => $day): ?>
                            <div class="cell">
                                <?php
                                $driver_trips = $day['drivers'][$driver['driver_id']]['trips'] ?? [];
                                if (count($driver_trips) > 0):
                                ?>
                                    <?php foreach ($driver_trips as $trip): ?>
                                        <div class="trip-card">
                                            <div class="trip-time-row">
                                                <span class="trip-time"><?= date('g:i A', strtotime($trip['pickup_time'])) ?></span>
                                                <?php if ($trip['dropoff_time']): ?>
                                                    <span class="trip-time-dropoff"><?= date('g:i A', strtotime($trip['dropoff_time'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="trip-location"><?= htmlspecialchars($trip['pickup_location']) ?><?= !empty($trip['dropoff_location']) ? ' → ' . htmlspecialchars($trip['dropoff_location']) : '' ?></span>
                                            <span class="trip-status <?= $trip['status'] ?>"><?= ucfirst(str_replace('_', ' ', $trip['status'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-cell">—</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>