<?php
require_once '../config/db.php';  

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
$driver_trips = [];

for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . ' + ' . $i . ' days'));
    $week_days[$date] = [
        'date' => $date,
        'display' => date('D, M j', strtotime($date)),
        'day_name' => date('l', strtotime($date)),
        'drivers' => []
    ];
    
    // Initialize each driver for this day (ALL drivers)
    foreach ($drivers as $driver) {
        $week_days[$date]['drivers'][$driver['driver_id']] = [
            'driver' => $driver,
            'trips' => []
        ];
    }
}

// Assign trips to drivers and days
foreach ($trips as $trip) {
    if (isset($week_days[$trip['date']]) && isset($week_days[$trip['date']]['drivers'][$trip['driver_id']])) {
        $week_days[$trip['date']]['drivers'][$trip['driver_id']]['trips'][] = $trip;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Driver Weekly Schedule - CARS</title>
    <link rel="stylesheet" href="../assets/css/style.css"> 
    <link rel="stylesheet" href="../pages/driver/driver.css">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
</head>
<body>
    <nav class="navbar no-print">
        <div class="container">
            <a href="#" class="navbar-brand">CARS <span>Drivers Schedule</span></a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header no-print">
            <div>
                <h1>Driver Weekly Schedule</h1>
            </div>
        </div>

        <!-- Week Navigation -->
        <div class="week-nav no-print">
            <button onclick="openPrintModal()" class="btn btn-print">Print Schedule</button>
            <a href="#" class="btn btn-outline" id="prevWeek">◀ Previous</a>
            <a href="#" class="btn btn-primary" id="thisWeek">This Week</a>
            <a href="#" class="btn btn-outline" id="nextWeek">Next ▶</a>
            <span class="week-label" id="weekLabel"><?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?></span>
        </div>

        <!-- Legend - Only Approved and In Progress -->
        <div class="legend no-print">
            <span class="legend-item"><span class="dot approved"></span> Approved</span>
            <span class="legend-item"><span class="dot in_progress"></span> In Progress</span>
            <span style="color:#6c757d; font-size:0.75rem; margin-left:auto;" id="tripCount">
                <?= count($drivers) ?> total drivers • <?= count($trips) ?> trips
            </span>
        </div>

        <!-- Schedule Grid -->
        <div class="schedule-wrapper">
            <div class="schedule-grid" id="scheduleGrid">
                <!-- Header -->
                <div class="schedule-header">
                    <div class="cell">Driver / Car</div>
                    <?php foreach($week_days as $date => $day): 
                        $is_today = $date == date('Y-m-d');
                    ?>
                        <div class="cell <?= $is_today ? 'today' : '' ?>">
                            <?= date('D', strtotime($date)) ?>
                            <br>
                            <span style="font-weight:400; font-size:0.7rem; text-transform:none;">
                                <?= date('M j', strtotime($date)) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Rows -->
                <?php foreach($drivers as $driver): ?>
                    <div class="schedule-row" data-driver-id="<?= $driver['driver_id'] ?>">
                        <div class="cell driver-name">
                            <?= htmlspecialchars($driver['name']) ?>
                            <span class="car-info">
                                <?php if ($driver['car_id']): ?>
                                    <?= htmlspecialchars($driver['brand']) ?>
                                    <span class="plate">(<?= htmlspecialchars($driver['plate_number']) ?>)</span>
                                    <?php if ($driver['parking']): ?>
                                        <br>
                                        <span style="font-size:0.6rem; color:#6c757d;">Designation: <?= htmlspecialchars($driver['parking']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#999;">No car assigned</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php foreach($week_days as $date => $day): ?>
                            <div class="cell" data-date="<?= $date ?>" data-driver-id="<?= $driver['driver_id'] ?>">
                                <?php 
                                $driver_trips = $day['drivers'][$driver['driver_id']]['trips'] ?? [];
                                // Only show approved and in_progress trips
                                $filtered_trips = array_filter($driver_trips, function($trip) {
                                    return in_array($trip['status'], ['approved', 'in_progress']);
                                });
                                if (count($filtered_trips) > 0): 
                                ?>
                                    <?php foreach($filtered_trips as $trip): ?>
                                        <div class="trip-card" data-allocation-id="<?= $trip['allocation_id'] ?>">
                                            <div class="trip-time-row">
                                                <span class="trip-time"><?= date('g:i A', strtotime($trip['pickup_time'])) ?></span>
                                                <?php if ($trip['dropoff_time']): ?>
                                                    <span class="trip-time-dropoff"><?= date('g:i A', strtotime($trip['dropoff_time'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="trip-time-row">
                                                <span class="trip-location"><?= htmlspecialchars($trip['pickup_location']) ?></span>
                                                <?php if (!empty($trip['dropoff_location'])): ?>
                                                    <span class="trip-location trip-location-dropoff"><?= htmlspecialchars($trip['dropoff_location']) ?></span>
                                                <?php endif; ?>
                                            </div>
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

        <!-- No drivers message -->
        <div id="noDriversMessage" style="<?= count($drivers) > 0 ? 'display:none;' : '' ?> text-align:center; padding:40px; background:white; border-radius:10px; margin-top:20px; color:#6c757d;">
            <p style="font-size:1.1rem;">No drivers found.</p>
            <p style="font-size:0.9rem;">Please add drivers in the admin panel.</p>
        </div>
    </div>

    <!-- Print Selection Modal -->
    <div class="modal-overlay" id="printModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3>Print Schedule</h3>
                <button class="modal-close" onclick="closePrintModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="modal-options">
                    <button class="modal-option-btn btn-print-all" onclick="printAllDrivers()">
                        <div class="option-text">
                            <div class="option-title">Print All Drivers</div>
                            <div class="option-desc">Print the complete schedule for all drivers</div>
                        </div>
                    </button>
                    <button class="modal-option-btn btn-select-drivers" onclick="showDriverSelection()">
                        <div class="option-text">
                            <div class="option-title">Select Drivers to Print</div>
                            <div class="option-desc">Choose specific drivers to print</div>
                        </div>
                    </button>
                </div>
                
                <!-- Driver Selection (hidden by default) -->
                <div id="driverSelectionArea" style="display:none; margin-top:15px;">
                    <div class="modal-select-all">
                        <label>
                            <input type="checkbox" id="modalSelectAll" checked> Select All Drivers
                        </label>
                        <span id="modalSelectedCount">0</span> selected
                    </div>
                    <div class="modal-driver-list" id="modalDriverList">
                        <!-- Driver checkboxes will be loaded here -->
                    </div>
                    <div style="display:flex; gap:10px; margin-top:15px; justify-content:flex-end;">
                        <button class="btn btn-secondary" onclick="closePrintModal()">Cancel</button>
                        <button class="btn btn-primary" onclick="printSelectedDrivers()">Print Selected</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script src="../pages/driver/driver.js"></script>

    <script>
    // ============================================================
    // AUTO-UPDATE SCRIPT
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Driver schedule auto-update starting...');

    // Close modal when clicking outside
    document.getElementById('printModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePrintModal();
        }
    });

    const apiUrl = '../admin/api/driver_schedule_status.php';
    let currentWeek = '<?= $week_start ?>';
    let updateInterval = null;
    let isFetching = false;

    function getStatusDisplay(status) {
        const display = {
            'approved': 'Approved',
            'in_progress': 'In Progress',
            'completed': 'Completed',
            'cancelled': 'Cancelled'
        };
        return display[status] || status.replace('_', ' ');
    }

    function getStatusClass(status) {
        const classes = {
            'approved': 'approved',
            'in_progress': 'in_progress',
            'completed': 'completed',
            'cancelled': 'cancelled'
        };
        return classes[status] || status;
    }

    function formatTime(timeStr) {
        if (!timeStr) return 'N/A';
        try {
            const date = new Date('2000-01-01 ' + timeStr);
            return date.toLocaleTimeString('en-PH', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
        } catch (e) {
            return timeStr;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateCounters(data) {
        let totalDriverCount = 0;
        let totalActiveTrips = 0;

        if (data.drivers) {
            totalDriverCount = data.drivers.length;
        }

        if (data.week_days) {
            Object.keys(data.week_days).forEach(date => {
                const dayData = data.week_days[date];
                if (dayData.drivers) {
                    Object.keys(dayData.drivers).forEach(driverId => {
                        const trips = dayData.drivers[driverId].trips || [];
                        totalActiveTrips += trips.filter(t => 
                            t.status === 'approved' || t.status === 'in_progress'
                        ).length;
                    });
                }
            });
        }

        const tripCountEl = document.getElementById('tripCount');
        if (tripCountEl) {
            tripCountEl.textContent = totalDriverCount + ' total drivers • ' + 
                totalActiveTrips + ' upcoming trips';
        }

        const weekLabel = document.getElementById('weekLabel');
        if (weekLabel && data.week_start && data.week_end) {
            const start = new Date(data.week_start);
            const end = new Date(data.week_end);
            weekLabel.textContent = start.toLocaleDateString('en-US', { month: 'long', day: 'numeric' }) + 
                ' – ' + end.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        }
    }

    function rebuildSchedule(data) {
        updateCounters(data);
        const grid = document.getElementById('scheduleGrid');
        const noDriversMsg = document.getElementById('noDriversMessage');
        
        let dayDates = [];
        if (data.week_days) {
            dayDates = Object.keys(data.week_days).sort();
        }
        
        if (dayDates.length === 0 && data.week_start) {
            for (let i = 0; i < 7; i++) {
                const date = new Date(data.week_start);
                date.setDate(date.getDate() + i);
                dayDates.push(date.toISOString().split('T')[0]);
            }
        }
        
        let driversData = data.drivers || [];
        
        if (noDriversMsg) {
            if (driversData.length === 0) {
                noDriversMsg.style.display = 'block';
            } else {
                noDriversMsg.style.display = 'none';
            }
        }
        
        if (driversData.length === 0) {
            grid.innerHTML = '';
            return;
        }
        
        let html = '';
        
        html += `
            <div class="schedule-header">
                <div class="cell">Driver / Car</div>
        `;
        
        dayDates.forEach(date => {
            const isToday = date === new Date().toISOString().split('T')[0];
            const dateObj = new Date(date + 'T00:00:00');
            html += `
                <div class="cell ${isToday ? 'today' : ''}">
                    ${dateObj.toLocaleDateString('en-US', { weekday: 'short' })}
                    <br>
                    <span style="font-weight:400; font-size:0.7rem; text-transform:none;">
                        ${dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                    </span>
                </div>
            `;
        });
        html += `</div>`;
        
        driversData.forEach(driver => {
            const driverId = driver.driver_id;
            const driverName = driver.name || 'Unknown Driver';
            const hasCar = driver.car_id || driver.brand;
            
            html += `
                <div class="schedule-row" data-driver-id="${driverId}">
                    <div class="cell driver-name">
                        ${escapeHtml(driverName)}
                        <span class="car-info">
                            ${hasCar ? `
                                ${escapeHtml(driver.brand || '')}
                                <span class="plate">(${escapeHtml(driver.plate_number || '')})</span>
                                ${driver.parking ? `<br><span style="font-size:0.6rem; color:#6c757d;">Designation: ${escapeHtml(driver.parking)}</span>` : ''}
                            ` : `
                                <span style="color:#999;">No car assigned</span>
                            `}
                        </span>
                    </div>
            `;
            
            dayDates.forEach(date => {
                let trips = [];
                if (data.week_days && data.week_days[date] && 
                    data.week_days[date].drivers && 
                    data.week_days[date].drivers[driverId]) {
                    trips = data.week_days[date].drivers[driverId].trips || [];
                }
                
                trips = trips.filter(trip => trip.status === 'approved' || trip.status === 'in_progress');
                
                html += `<div class="cell" data-date="${date}" data-driver-id="${driverId}">`;
                
                if (trips.length > 0) {
                    trips.forEach(trip => {
                        html += `
                            <div class="trip-card" data-allocation-id="${trip.allocation_id}">
                                <div class="trip-time-row">
                                    <span class="trip-time">${formatTime(trip.pickup_time)}</span>
                                    ${trip.dropoff_time ? `<span class="trip-time-dropoff">${formatTime(trip.dropoff_time)}</span>` : ''}
                                </div>
                                <div class="trip-time-row">
                                    <span class="trip-location">${escapeHtml(trip.pickup_location)}</span>
                                    ${trip.dropoff_location ? `<span class="trip-location trip-location-dropoff">${escapeHtml(trip.dropoff_location)}</span>` : ''}
                                </div>
                                <span class="trip-status ${getStatusClass(trip.status)}">${getStatusDisplay(trip.status)}</span>
                            </div>
                        `;
                    });
                } else {
                    html += `<div class="empty-cell">—</div>`;
                }
                
                html += `</div>`;
            });
            
            html += `</div>`;
        });
        
        grid.innerHTML = html;
        
        let totalDrivers = driversData.length;
        let totalTrips = 0;
        
        if (data.week_days) {
            Object.keys(data.week_days).forEach(date => {
                const dayData = data.week_days[date];
                if (dayData.drivers) {
                    Object.keys(dayData.drivers).forEach(driverId => {
                        const trips = dayData.drivers[driverId].trips || [];
                        totalTrips += trips.filter(t => 
                            t.status === 'approved' || t.status === 'in_progress'
                        ).length;
                    });
                }
            });
        }
        
        const tripCountEl = document.getElementById('tripCount');
        if (tripCountEl) {
            tripCountEl.textContent = totalDrivers + ' total drivers • ' + 
                totalTrips + ' upcoming trips';
        }
        
        const weekLabel = document.getElementById('weekLabel');
        if (weekLabel && data.week_start && data.week_end) {
            const start = new Date(data.week_start);
            const end = new Date(data.week_end);
            weekLabel.textContent = start.toLocaleDateString('en-US', { month: 'long', day: 'numeric' }) + 
                ' – ' + end.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        }
    }

    function fetchAndUpdate() {
        if (isFetching) {
            console.log('⏳ Already fetching, skipping...');
            return;
        }
        
        isFetching = true;
        const url = apiUrl + '?week=' + encodeURIComponent(currentWeek);
        console.log('📡 Fetching: ' + url);
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    console.log('✅ Schedule data received');
                    rebuildSchedule(data.data);
                } else {
                    console.error('❌ API returned error:', data.message);
                    const weekLabel = document.getElementById('weekLabel');
                    if (weekLabel && weekLabel.textContent === 'Loading...') {
                        weekLabel.textContent = '<?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?>';
                    }
                }
            })
            .catch(error => {
                console.error('❌ Error fetching schedule:', error);
                const weekLabel = document.getElementById('weekLabel');
                if (weekLabel && weekLabel.textContent === 'Loading...') {
                    weekLabel.textContent = '<?= date('F j', strtotime($week_start)) ?> – <?= date('F j, Y', strtotime($week_end)) ?>';
                }
            })
            .finally(() => {
                isFetching = false;
            });
    }

    function getNewWeekDate(current, offset) {
        const date = new Date(current);
        date.setDate(date.getDate() + (offset * 7));
        return date.toISOString().split('T')[0];
    }

    document.getElementById('prevWeek')?.addEventListener('click', function(e) {
        e.preventDefault();
        currentWeek = getNewWeekDate(currentWeek, -1);
        document.getElementById('weekLabel').textContent = 'Loading...';
        fetchAndUpdate();
        window.history.pushState({}, '', '?week=' + currentWeek);
    });

    document.getElementById('nextWeek')?.addEventListener('click', function(e) {
        e.preventDefault();
        currentWeek = getNewWeekDate(currentWeek, 1);
        document.getElementById('weekLabel').textContent = 'Loading...';
        fetchAndUpdate();
        window.history.pushState({}, '', '?week=' + currentWeek);
    });

    document.getElementById('thisWeek')?.addEventListener('click', function(e) {
        e.preventDefault();
        const today = new Date().toISOString().split('T')[0];
        currentWeek = today;
        document.getElementById('weekLabel').textContent = 'Loading...';
        fetchAndUpdate();
        window.history.pushState({}, '', '?week=' + today);
    });

    window.addEventListener('popstate', function() {
        const params = new URLSearchParams(window.location.search);
        const week = params.get('week');
        if (week) {
            currentWeek = week;
            document.getElementById('weekLabel').textContent = 'Loading...';
            fetchAndUpdate();
        }
    });

    fetchAndUpdate();

    if (updateInterval) {
        clearInterval(updateInterval);
    }
    updateInterval = setInterval(fetchAndUpdate, 1000);
    console.log('✅ Driver schedule auto-update initialized');
    });
    </script>
</body>
</html>