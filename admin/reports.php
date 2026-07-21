<?php
session_start();
require_once '../includes/load.php';
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;
require_admin();

$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$report_type = $_GET['type'] ?? 'trips';

// Canonical status ordering used everywhere in this report: In Progress -> Approved -> Completed -> Cancelled
$status_priority = [
    'in_progress' => 0,
    'approved'    => 1,
    'completed'   => 2,
    'pending'     => 3,
    'cancelled'   => 4,
];

$status_labels = [
    'pending'     => 'Pending',
    'approved'    => 'Approved',
    'in_progress' => 'In Progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
];

// Colors used for badges + the left accent border on each row
$status_colors = [
    'in_progress' => ['bg' => '#fff3e0', 'text' => '#e65100', 'border' => '#f57c00'],
    'approved'    => ['bg' => '#e8f5e9', 'text' => '#2e7d32', 'border' => '#2e7d32'],
    'completed'   => ['bg' => '#e3f2fd', 'text' => '#0d47a1', 'border' => '#0d47a1'],
    'pending'     => ['bg' => '#fff8e1', 'text' => '#f57f17', 'border' => '#f9a825'],
    'cancelled'   => ['bg' => '#ffebee', 'text' => '#c62828', 'border' => '#c62828'],
];

// Colors used for the "actual vs scheduled" pickup/dropoff indicators
$actual_time_colors = [
    'ontime' => '#2e7d32',
    'late'   => '#e65100',
    'none'   => '#6c757d',
];

// MySQL FIELD() clause to sort a status column by the priority order above
function statusOrderSql($col = 'a.status') {
    return "FIELD($col, 'in_progress','approved','completed','pending','cancelled')";
}

/**
 * Compares an actual pickup/dropoff timestamp against its scheduled counterpart.
 * Returns:
 *   'text'   -> formatted time (g:i A) or the fallback label if not recorded yet
 *   'status' -> 'ontime' | 'late' | 'none'
 * Used identically by the on-screen table, CSV export, and PDF export so the
 * late/on-time determination never drifts between the three.
 */
function getActualTimeInfo($actual_time, $scheduled_time, $fallback_label) {
    if (empty($actual_time)) {
        return ['text' => $fallback_label, 'status' => 'none'];
    }
    $grace_seconds = 5 * 60;
    $is_late = !empty($scheduled_time) && strtotime($actual_time) > (strtotime($scheduled_time) + $grace_seconds);
    return [
        'text' => date('g:i A', strtotime($actual_time)),
        'status' => $is_late ? 'late' : 'ontime',
    ];
}

$all_drivers = $pdo->query("SELECT driver_id, name FROM tbl_drivers ORDER BY name")->fetchAll();
$all_cars = $pdo->query("SELECT car_id, brand, plate_number FROM tbl_cars ORDER BY brand")->fetchAll();

// -----------------------------------------------------------------------
// DISPLAY DATA (what renders on the page). This is intentionally NOT
// filtered by $start_date / $end_date - those only apply to CSV/PDF
// exports further down. The "Generate" button never changes what's shown
// on screen; it only updates the range used when you export.
// -----------------------------------------------------------------------
$report_data = [];
$total_trips = 0;
$completed = 0;
$approved = 0;
$in_progress = 0;
$cancelled = 0;

if ($report_type == 'trips') {
   $report = $pdo->query("
        SELECT a.*, a.request_number, c.brand, c.plate_number, c.parking, 
               d.name as driver_name, d.mobile as driver_mobile, 
               COALESCE(u.full_name, u.username) as requestor,
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
        WHERE a.status IN ('approved', 'completed', 'in_progress', 'cancelled')
        ORDER BY " . statusOrderSql('a.status') . ", a.date DESC
    ");
    $report_data = $report->fetchAll();

    $total_trips = count($report_data);
    foreach ($report_data as $r) {
        if ($r['status'] == 'completed') $completed++;
        elseif ($r['status'] == 'approved') $approved++;
        elseif ($r['status'] == 'in_progress') $in_progress++;
        elseif ($r['status'] == 'cancelled') $cancelled++;
    }
}

// Driver Performance Report (all-time, not date-restricted for display)
if ($report_type == 'drivers') {
    $report = $pdo->query("
        SELECT d.*, 
               COUNT(a.allocation_id) as total_trips,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
               SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as current_trips,
               SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved_trips,
               SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips,
               c.brand, c.plate_number, c.parking
        FROM tbl_drivers d
        LEFT JOIN tbl_allocations a ON d.driver_id = a.driver_id
        LEFT JOIN tbl_cars c ON d.car_id = c.car_id
        GROUP BY d.driver_id
        ORDER BY total_trips DESC
    ");
    $report_data = $report->fetchAll();
}

// Car Utilization Report (all-time, not date-restricted for display)
if ($report_type == 'cars') {
    $report = $pdo->query("
        SELECT c.*, 
               COUNT(a.allocation_id) as total_trips,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
               SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as current_trips,
               SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved_trips,
               SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips
        FROM tbl_cars c
        LEFT JOIN tbl_allocations a ON c.car_id = a.car_id
        GROUP BY c.car_id
        ORDER BY total_trips DESC
    ");
    $report_data = $report->fetchAll();
}

// Rollup totals for drivers/cars reports (also all-time, matches the table)
$total_trips_all = 0;
$total_completed_all = 0;
$total_in_progress_all = 0;
$total_cancelled_all = 0;

if ($report_type == 'drivers' || $report_type == 'cars') {
    foreach ($report_data as $item) {
        $total_trips_all += $item['total_trips'] ?? 0;
        $total_completed_all += $item['completed_trips'] ?? 0;
        $total_in_progress_all += $item['current_trips'] ?? 0;
        $total_cancelled_all += $item['cancelled_trips'] ?? 0;
    }
}

// AJAX refresh: return only updated stats + table body for the current report view
// (mirrors the same all-time, unfiltered data as the page itself)
if (isset($_GET['ajax_refresh'])) {
    header('Content-Type: application/json');

    if ($report_type == 'trips') {
        echo json_encode([
            'total_trips' => $total_trips,
            'completed' => $completed,
            'approved' => $approved,
            'in_progress' => $in_progress,
            'cancelled' => $cancelled,
            'completion_rate' => number_format(($total_trips > 0 ? ($completed / $total_trips) * 100 : 0), 1),
            'row_count' => count($report_data)
        ]);
    } elseif ($report_type == 'drivers' || $report_type == 'cars') {
        echo json_encode([
            'total_trips_all' => $total_trips_all,
            'total_completed_all' => $total_completed_all,
            'total_in_progress_all' => $total_in_progress_all,
            'total_cancelled_all' => $total_cancelled_all,
            'completion_rate' => number_format(($total_trips_all > 0 ? ($total_completed_all / $total_trips_all) * 100 : 0), 1),
            'row_count' => count($report_data)
        ]);
    }
    exit();
}

// -----------------------------------------------------------------------
// EXPORTS (CSV / PDF). These are the only places $start_date / $end_date
// actually filter anything - the "Generate" button's date range applies
// here, not to the on-screen table.
// -----------------------------------------------------------------------

// Export CSV
if (isset($_GET['export'])) {
    $filename = '';
    $export_data = [];
    $export_total_trips_all = 0;
    $export_total_completed_all = 0;

    if ($report_type == 'trips') {
        $filename = 'trip_summary_' . $start_date . '_to_' . $end_date;
        $stmt = $pdo->prepare("
            SELECT a.*, a.request_number, c.brand, c.plate_number, c.parking, 
                   d.name as driver_name, d.mobile as driver_mobile, 
                   COALESCE(u.full_name, u.username) as requestor
            FROM tbl_allocations a 
            JOIN tbl_cars c ON a.car_id = c.car_id 
            JOIN tbl_drivers d ON a.driver_id = d.driver_id 
            JOIN tbl_users u ON a.requestor_id = u.user_id 
            WHERE a.status IN ('approved', 'completed', 'in_progress', 'cancelled')
              AND a.date BETWEEN ? AND ?
            ORDER BY " . statusOrderSql('a.status') . ", a.date DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
    } elseif ($report_type == 'drivers') {
        $filename = 'driver_performance_' . $start_date . '_to_' . $end_date;
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   COUNT(a.allocation_id) as total_trips,
                   SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                   SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as current_trips,
                   SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips,
                   c.brand, c.plate_number, c.parking
            FROM tbl_drivers d
            LEFT JOIN tbl_allocations a ON d.driver_id = a.driver_id AND a.date BETWEEN ? AND ?
            LEFT JOIN tbl_cars c ON d.car_id = c.car_id
            GROUP BY d.driver_id
            ORDER BY total_trips DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
    } elseif ($report_type == 'cars') {
        $filename = 'car_utilization_' . $start_date . '_to_' . $end_date;
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   COUNT(a.allocation_id) as total_trips,
                   SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                   SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips
            FROM tbl_cars c
            LEFT JOIN tbl_allocations a ON c.car_id = a.car_id AND a.date BETWEEN ? AND ?
            GROUP BY c.car_id
            ORDER BY total_trips DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');

    if ($report_type == 'trips') {
        fputcsv($output, ['Request #', 'Status', 'Date', 'Pickup Time', 'Dropoff Time', 'Actual Pickup Time', 'Actual Dropoff Time', 'Car', 'Driver', 'Driver Mobile', 'Pickup Location', 'Dropoff Location']);
        foreach ($export_data as $r) {
            $actual_pickup = getActualTimeInfo($r['actual_pickup_time'] ?? null, $r['pickup_time'], 'Not started');
            $actual_dropoff = getActualTimeInfo($r['actual_dropoff_time'] ?? null, $r['dropoff_time'], 'Not completed');

            $actual_pickup_csv = $actual_pickup['status'] === 'none' ? $actual_pickup['text'] : $actual_pickup['text'] . ' (' . ($actual_pickup['status'] === 'late' ? 'Late' : 'On Time') . ')';
            $actual_dropoff_csv = $actual_dropoff['status'] === 'none' ? $actual_dropoff['text'] : $actual_dropoff['text'] . ' (' . ($actual_dropoff['status'] === 'late' ? 'Late' : 'On Time') . ')';

            fputcsv($output, [
                $r['request_number'] ?? '',
                $status_labels[$r['status']] ?? ucfirst($r['status']),
                date('M d, Y', strtotime($r['date'])),
                date('g:i A', strtotime($r['pickup_time'])),
                $r['dropoff_time'] ? date('g:i A', strtotime($r['dropoff_time'])) : '',
                $actual_pickup_csv,
                $actual_dropoff_csv,
                $r['brand'] . ' (' . $r['plate_number'] . ')',
                $r['driver_name'],
                $r['driver_mobile'] ?? '',
                $r['pickup_location'],
                $r['dropoff_location'] ?? '',
            ]);
        }
    } elseif ($report_type == 'drivers') {
        fputcsv($output, ['Driver', 'Mobile', 'Car', 'Parking', 'Total Trips', 'Completed', 'Cancelled', 'Completion Rate']);
        foreach ($export_data as $d) {
            $rate = $d['total_trips'] > 0 ? round((($d['completed_trips']) / $d['total_trips']) * 100, 1) : 0;
            fputcsv($output, [
                $d['name'],
                $d['mobile'],
                ($d['car_id'] ? $d['brand'] . ' (' . $d['plate_number'] . ')' : 'No Car'),
                $d['parking'] ?? '',
                $d['total_trips'],
                $d['completed_trips'],
                $d['cancelled_trips'] ?? 0,
                $rate . '%'
            ]);
        }
    } elseif ($report_type == 'cars') {
        fputcsv($output, ['Brand', 'Plate Number', 'Parking', 'Total Trips', 'Completed', 'Cancelled', 'Completion Rate']);
        foreach ($export_data as $c) {
            $rate = $c['total_trips'] > 0 ? round(($c['completed_trips'] / $c['total_trips']) * 100, 1) : 0;
            fputcsv($output, [
                $c['brand'],
                $c['plate_number'],
                $c['parking'] ?? '',
                $c['total_trips'],
                $c['completed_trips'],
                $c['cancelled_trips'] ?? 0,
                $rate . '%'
            ]);
        }
    }
    fclose($output);
    exit();
}

// Export PDF
if (isset($_GET['export_pdf'])) {
    $export_data = [];
    $export_total_trips = 0;
    $export_completed = 0;
    $export_approved = 0;
    $export_in_progress = 0;
    $export_cancelled = 0;
    $export_total_trips_all = 0;
    $export_total_completed_all = 0;
    $export_total_in_progress_all = 0;
    $export_total_cancelled_all = 0;

    if ($report_type == 'trips') {
        $stmt = $pdo->prepare("
            SELECT a.*, a.request_number, c.brand, c.plate_number, c.parking, 
                   d.name as driver_name, d.mobile as driver_mobile, 
                   COALESCE(u.full_name, u.username) as requestor
            FROM tbl_allocations a 
            JOIN tbl_cars c ON a.car_id = c.car_id 
            JOIN tbl_drivers d ON a.driver_id = d.driver_id 
            JOIN tbl_users u ON a.requestor_id = u.user_id 
            WHERE a.status IN ('approved', 'completed', 'in_progress', 'cancelled')
              AND a.date BETWEEN ? AND ?
            ORDER BY " . statusOrderSql('a.status') . ", a.date DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
        $export_total_trips = count($export_data);
        foreach ($export_data as $r) {
            if ($r['status'] == 'completed') $export_completed++;
            elseif ($r['status'] == 'approved') $export_approved++;
            elseif ($r['status'] == 'in_progress') $export_in_progress++;
            elseif ($r['status'] == 'cancelled') $export_cancelled++;
        }
    } elseif ($report_type == 'drivers') {
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   COUNT(a.allocation_id) as total_trips,
                   SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                   SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as current_trips,
                   SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips,
                   c.brand, c.plate_number, c.parking
            FROM tbl_drivers d
            LEFT JOIN tbl_allocations a ON d.driver_id = a.driver_id AND a.date BETWEEN ? AND ?
            LEFT JOIN tbl_cars c ON d.car_id = c.car_id
            GROUP BY d.driver_id
            ORDER BY total_trips DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
        foreach ($export_data as $item) {
            $export_total_trips_all += $item['total_trips'] ?? 0;
            $export_total_completed_all += $item['completed_trips'] ?? 0;
            $export_total_in_progress_all += $item['current_trips'] ?? 0;
            $export_total_cancelled_all += $item['cancelled_trips'] ?? 0;
        }
    } elseif ($report_type == 'cars') {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   COUNT(a.allocation_id) as total_trips,
                   SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
                   SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) as current_trips,
                   SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_trips
            FROM tbl_cars c
            LEFT JOIN tbl_allocations a ON c.car_id = a.car_id AND a.date BETWEEN ? AND ?
            GROUP BY c.car_id
            ORDER BY total_trips DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $export_data = $stmt->fetchAll();
        foreach ($export_data as $item) {
            $export_total_trips_all += $item['total_trips'] ?? 0;
            $export_total_completed_all += $item['completed_trips'] ?? 0;
            $export_total_in_progress_all += $item['current_trips'] ?? 0;
            $export_total_cancelled_all += $item['cancelled_trips'] ?? 0;
        }
    }

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    ob_start();
    ?>
    <html>
    <head>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; }
            h2 { color: #1a237e; margin-bottom: 4px; text-align: center; }
            .subtitle { color: #6c757d; font-size: 10px; margin-bottom: 16px; text-align: center; }
            .stat-row { display: table; width: 100%; margin-bottom: 20px; }
            .stat-box { display: table-cell; text-align: center; padding: 8px; border: 1px solid #e9ecef; }
            .stat-box .num { font-size: 18px; font-weight: bold; color: #1a237e; }
            .stat-box .lbl { font-size: 9px; color: #6c757d; text-transform: uppercase; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th { background: #f8f9fa; text-align: center; padding: 6px 8px; font-size: 9px; text-transform: uppercase; color: #6c757d; border-bottom: 2px solid #dee2e6; vertical-align: middle; }
            td { padding: 5px 8px; font-size: 10px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; text-align: center; }
            .badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
            .actual-time { font-size: 9px; font-weight: bold; }
        </style>
    </head>
    <body>
        <h2>CARS Report — <?= ucfirst($report_type) ?></h2>
        <div class="subtitle"><?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?> · Generated <?= date('M d, Y g:i A') ?></div>

        <?php if ($report_type == 'trips'): ?>
            <div class="stat-row">
                <div class="stat-box"><div class="num"><?= $export_total_trips ?></div><div class="lbl">Total Trips</div></div>
                <div class="stat-box"><div class="num"><?= $export_completed ?></div><div class="lbl">Total Trips Completed</div></div>
                <div class="stat-box"><div class="num"><?= $export_approved ?></div><div class="lbl">Approved</div></div>
                <div class="stat-box"><div class="num"><?= $export_in_progress ?></div><div class="lbl">In Progress</div></div>
                <div class="stat-box"><div class="num"><?= $export_cancelled ?></div><div class="lbl">Cancelled</div></div>
                <div class="stat-box"><div class="num"><?= number_format(($export_total_trips > 0 ? ($export_completed / $export_total_trips) * 100 : 0), 1) ?>%</div><div class="lbl">Completion Rate</div></div>
            </div>
            <table>
                <thead><tr>
                    <th>Request #</th><th>Status</th><th>Date</th><th>Pickup</th><th>Actual Pickup</th><th>Dropoff</th><th>Actual Dropoff</th><th>Car</th><th>Driver</th>
                </tr></thead>
                <tbody>
                <?php foreach ($export_data as $r):
                    $sc = $status_colors[$r['status']] ?? ['bg' => '#f1f3f5', 'text' => '#495057'];
                    $ap = getActualTimeInfo($r['actual_pickup_time'] ?? null, $r['pickup_time'], 'Not started');
                    $ad = getActualTimeInfo($r['actual_dropoff_time'] ?? null, $r['dropoff_time'], 'Not completed');
                ?>
                    <tr>
                        <td><?= htmlspecialchars($r['request_number'] ?? '') ?></td>
                        <td><span class="badge" style="background:<?= $sc['bg'] ?>; color:<?= $sc['text'] ?>;"><?= $status_labels[$r['status']] ?? ucfirst($r['status']) ?></span></td>
                        <td><?= date('M d, Y', strtotime($r['date'])) ?></td>
                        <td><?= date('g:i A', strtotime($r['pickup_time'])) ?></td>
                        <td class="actual-time" style="color:<?= $actual_time_colors[$ap['status']] ?>;"><?= htmlspecialchars($ap['text']) ?></td>
                        <td><?= $r['dropoff_time'] ? date('g:i A', strtotime($r['dropoff_time'])) : '-' ?></td>
                        <td class="actual-time" style="color:<?= $actual_time_colors[$ad['status']] ?>;"><?= htmlspecialchars($ad['text']) ?></td>
                        <td><?= htmlspecialchars($r['brand']) ?> (<?= htmlspecialchars($r['plate_number']) ?>)</td>
                        <td><?= htmlspecialchars($r['driver_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($report_type == 'drivers'): ?>
            <div class="stat-row">
                <div class="stat-box"><div class="num"><?= count($export_data) ?></div><div class="lbl">Active Drivers</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_trips_all ?></div><div class="lbl">Total Trips</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_completed_all ?></div><div class="lbl">Total Trips Completed</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_cancelled_all ?></div><div class="lbl">Cancelled</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_in_progress_all ?></div><div class="lbl">In Progress</div></div>
            </div>
            <table>
                <thead><tr><th>Driver</th><th>Mobile</th><th>Car</th><th>Total Trips</th><th>Completed</th><th>Cancelled</th><th>Rate</th></tr></thead>
                <tbody>
                <?php foreach ($export_data as $d):
                    $rate = $d['total_trips'] > 0 ? round(($d['completed_trips'] / $d['total_trips']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= htmlspecialchars($d['mobile']) ?></td>
                        <td><?= $d['car_id'] ? htmlspecialchars($d['brand']) . ' (' . htmlspecialchars($d['plate_number']) . ')' : 'No Car' ?></td>
                        <td><?= $d['total_trips'] ?></td>
                        <td><?= $d['completed_trips'] ?></td>
                        <td><?= $d['cancelled_trips'] ?? 0 ?></td>
                        <td><?= $rate ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($report_type == 'cars'): ?>
            <div class="stat-row">
                <div class="stat-box"><div class="num"><?= count($export_data) ?></div><div class="lbl">Total Cars</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_trips_all ?></div><div class="lbl">Total Trips</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_completed_all ?></div><div class="lbl">Total Trips Completed</div></div>
                <div class="stat-box"><div class="num"><?= $export_total_cancelled_all ?></div><div class="lbl">Cancelled</div></div>
            </div>
            <table>
                <thead><tr><th>Brand</th><th>Plate</th><th>Total Trips</th><th>Completed</th><th>Cancelled</th><th>Rate</th></tr></thead>
                <tbody>
                <?php foreach ($export_data as $c):
                    $rate = $c['total_trips'] > 0 ? round(($c['completed_trips'] / $c['total_trips']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($c['brand']) ?></td>
                        <td><?= htmlspecialchars($c['plate_number']) ?></td>
                        <td><?= $c['total_trips'] ?></td>
                        <td><?= $c['completed_trips'] ?></td>
                        <td><?= $c['cancelled_trips'] ?? 0 ?></td>
                        <td><?= $rate ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('report_' . $report_type . '_' . $start_date . '_to_' . $end_date . '.pdf', ['Attachment' => true]);
    exit();
}

$export_params = array_merge($_GET, ['export' => 1]);
$export_query = http_build_query($export_params);

$export_pdf_params = array_merge($_GET, ['export_pdf' => 1]);
$export_pdf_query = http_build_query($export_pdf_params);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reports - CARS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
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
                <a href="driver_vehicle.php">Drivers & Vehicles</a>
                <a href="reports.php" class="active">Reports</a>
                <a href="#" onclick="openLogoutModal(); return false;">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Trip Details Modal -->
    <div class="trip-modal-overlay" id="tripModal">
        <div class="trip-modal-box">
            <button class="modal-close" onclick="closeTripModal()">&times;</button>
            <h3 id="tripModalTitle">Trip Details</h3>
            <div id="tripModalBody"></div>
        </div>
    </div>

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
    
        <div class="page-header">
            <h2>Reports & Analytics</h2>
        </div>

        <div class="report-tabs">
            <a href="?type=trips&start=<?= $start_date ?>&end=<?= $end_date ?>" class="<?= $report_type == 'trips' ? 'active' : '' ?>">Trip Summary</a>
            <a href="?type=drivers&start=<?= $start_date ?>&end=<?= $end_date ?>" class="<?= $report_type == 'drivers' ? 'active' : '' ?>">Driver Performance</a>
            <a href="?type=cars&start=<?= $start_date ?>&end=<?= $end_date ?>" class="<?= $report_type == 'cars' ? 'active' : '' ?>">Car Utilization</a>
        </div>

        <div class="report-section">
            <form method="GET" style="display:flex; gap:15px; align-items:end; flex-wrap:wrap;">
                <input type="hidden" name="type" value="<?= htmlspecialchars($report_type) ?>">
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:0.8rem; color:#6c757d; font-weight:500;">Export range: From</label>
                    <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" class="form-control" style="padding:8px 12px; width:auto; border:2px solid #e9ecef; border-radius:6px;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:0.8rem; color:#6c757d; font-weight:500;">To</label>
                    <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" class="form-control" style="padding:8px 12px; width:auto; border:2px solid #e9ecef; border-radius:6px;">
                </div>
                <button type="submit" class="btn btn-primary">Set Export Range</button>
                <a href="?<?= htmlspecialchars($export_query) ?>" class="btn btn-success">Export CSV</a>
                <a href="?<?= htmlspecialchars($export_pdf_query) ?>" class="btn btn-danger">Export PDF</a>
            </form>
        </div>

        <?php if ($report_type == 'trips'): ?>
            <!-- Summary Stats -->
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="number"><?= $total_trips ?></div>
                    <div class="label">Total Trips</div>
                </div>
                <div class="stat-box green">
                    <div class="number"><?= $completed ?></div>
                    <div class="label">Total Trips Completed</div>
                </div>
                <div class="stat-box orange">
                    <div class="number"><?= $approved ?></div>
                    <div class="label">Approved</div>
                </div>
                <div class="stat-box blue">
                    <div class="number"><?= $in_progress ?></div>
                    <div class="label">In Progress</div>
                </div>
                <div class="stat-box red">
                    <div class="number"><?= $cancelled ?></div>
                    <div class="label">Cancelled</div>
                </div>
                <div class="stat-box" style="border-left-color: #6c757d;">
                    <div class="number" style="color: #6c757d;"><?= number_format(($total_trips > 0 ? ($completed / $total_trips) * 100 : 0), 1) ?>%</div>
                    <div class="label">Completion Rate</div>
                </div>
            </div>

            <!-- Trip Details -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                    <h4 style="margin:0;">Trip Details</h4>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <label for="tripDateFilter" style="font-size:0.8rem; color:#6c757d; font-weight:500;">Filter by date:</label>
                        <input type="date" id="tripDateFilter" style="padding:6px 10px; border:2px solid #e9ecef; border-radius:6px; font-size:0.85rem;">
                        <button type="button" id="clearDateFilter" style="padding:6px 12px; border:2px solid #e9ecef; border-radius:6px; background:white; cursor:pointer; font-size:0.85rem; color:#495057;">Show All Trips</button>
                        <button type="button" id="showTodayFilter" style="padding:6px 14px; border:none; border-radius:6px; background:#1a237e; color:white; cursor:pointer; font-size:0.85rem; font-weight:500; transition:all 0.2s;">Show Today</button>
                    </div>
                </div>
                <?php if (count($report_data) > 0): ?>
                    <div class="table-container">
                        <table id="outgoingTripsTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Request #</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Status</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Date</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Pickup Time</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Dropoff Time</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Car</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Driver</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Pickup</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Dropoff</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Passengers</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach($report_data as $t):
                                    $pass_stmt = $pdo->prepare("
                                        SELECT p.passenger_name 
                                        FROM tbl_allocated_passengers ap 
                                        JOIN tbl_passengers p ON ap.passenger_id = p.passenger_id 
                                        WHERE ap.allocation_id = ?
                                    ");
                                    $pass_stmt->execute([$t['allocation_id']]);
                                    $trip_passengers = $pass_stmt->fetchAll();
                                    $sc = $status_colors[$t['status']] ?? ['bg' => '#f1f3f5', 'text' => '#495057', 'border' => '#6c757d'];
                                    $raw_date = date('Y-m-d', strtotime($t['date']));

                                    $actual_pickup = getActualTimeInfo($t['actual_pickup_time'] ?? null, $t['pickup_time'], 'Not started');
                                    $actual_dropoff = getActualTimeInfo($t['actual_dropoff_time'] ?? null, $t['dropoff_time'], 'Not completed');
                                    if ($t['status'] === 'cancelled') {
                                        if ($actual_pickup['status'] === 'none') $actual_pickup = ['text' => 'Cancelled', 'status' => 'none'];
                                        if ($actual_dropoff['status'] === 'none') $actual_dropoff = ['text' => 'Cancelled', 'status' => 'none'];
                                    }
                                ?>
                                <?php
                                $t['passengers'] = $trip_passengers;
                                ?>
                                <tr data-date="<?= $raw_date ?>" class="trip-row-clickable" style="border-left: 3px solid <?= $sc['border'] ?>; cursor:pointer;" onclick="openTripModal(<?= htmlspecialchars(json_encode($t)) ?>)">
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($t['request_number'] ?? '') ?></strong></td>
                                    <td data-order="<?= $status_priority[$t['status']] ?? 99 ?>" style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; background:<?= $sc['bg'] ?>; color:<?= $sc['text'] ?>;">
                                            <?= $status_labels[$t['status']] ?? ucfirst($t['status']) ?>
                                        </span>
                                    </td>
                                    <td data-order="<?= $raw_date ?>" style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= date('M d, Y', strtotime($t['date'])) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= date('g:i A', strtotime($t['pickup_time'])) ?>
                                        <br>
                                        <span style="font-size:0.65rem; font-weight:600; color:<?= $actual_time_colors[$actual_pickup['status']] ?>;">
                                            <?= $actual_pickup['status'] === 'none' ? htmlspecialchars($actual_pickup['text']) : 'Actual: ' . htmlspecialchars($actual_pickup['text']) ?>
                                        </span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= $t['dropoff_time'] ? date('g:i A', strtotime($t['dropoff_time'])) : '-' ?>
                                        <br>
                                        <span style="font-size:0.65rem; font-weight:600; color:<?= $actual_time_colors[$actual_dropoff['status']] ?>;">
                                            <?= $actual_dropoff['status'] === 'none' ? htmlspecialchars($actual_dropoff['text']) : 'Actual: ' . htmlspecialchars($actual_dropoff['text']) ?>
                                        </span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?= htmlspecialchars($t['brand']) ?>
                                        <br>
                                        <span class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($t['plate_number']) ?></span>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($t['driver_name']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($t['pickup_location']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($t['dropoff_location'] ?? '-') ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if (!empty($trip_passengers)): ?>
                                            <?php foreach($trip_passengers as $p): ?>
                                                <span class="passenger-tag" style="font-size:0.65rem;"><?= htmlspecialchars($p['passenger_name']) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.65rem;">None</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No trips found.</p>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type == 'drivers'): ?>
            <!-- Driver Stats -->
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="number"><?= count($report_data) ?></div>
                    <div class="label">Active Drivers</div>
                </div>
                <div class="stat-box green">
                    <div class="number"><?= $total_trips_all ?></div>
                    <div class="label">Total Trips</div>
                </div>
                <div class="stat-box blue">
                    <div class="number"><?= $total_completed_all ?></div>
                    <div class="label">Completed</div>
                </div>
                <div class="stat-box orange">
                    <div class="number"><?= $total_in_progress_all ?></div>
                    <div class="label">In Progress</div>
                </div>
                <div class="stat-box red">
                    <div class="number"><?= $total_cancelled_all ?></div>
                    <div class="label">Cancelled</div>
                </div>
                <div class="stat-box" style="border-left-color: #6c757d;">
                    <div class="number" style="color: #6c757d;"><?= number_format(($total_trips_all > 0 ? ($total_completed_all / $total_trips_all) * 100 : 0), 1) ?>%</div>
                    <div class="label">Completion Rate</div>
                </div>
            </div>

            <!-- Driver Performance Table -->
            <div class="card">
                <h4>Driver Details</h4>
                <?php if (count($report_data) > 0): ?>
                    <div class="table-container">
                        <table id="driverPerformanceTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Driver</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Mobile</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Assigned Car</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Parking</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Total Trips</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Completed</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Cancelled</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">In Progress</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data as $d): 
                                    $rate = $d['total_trips'] > 0 ? round(($d['completed_trips'] / $d['total_trips']) * 100, 1) : 0;
                                    $rate_class = $rate >= 80 ? 'rate-high' : ($rate >= 50 ? 'rate-medium' : 'rate-low');
                                ?>
                                <tr>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($d['mobile']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <?php if ($d['car_id']): ?>
                                            <?= htmlspecialchars($d['brand']) ?> (<?= htmlspecialchars($d['plate_number']) ?>)
                                        <?php else: ?>
                                            <span style="color:#6c757d;">No Car</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($d['parking'] ?? '-') ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $d['total_trips'] ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $d['completed_trips'] ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5; color:<?= ($d['cancelled_trips'] ?? 0) > 0 ? '#c62828' : 'inherit' ?>;"><?= $d['cancelled_trips'] ?? 0 ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $d['current_trips'] ?? 0 ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <span class="rate <?= $rate_class ?>" style="display:inline-block; padding:2px 12px; border-radius:12px; font-size:0.75rem; font-weight:600;"><?= $rate ?>%</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No driver data available.</p>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type == 'cars'): ?>
            <!-- Car Stats -->
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="number"><?= count($report_data) ?></div>
                    <div class="label">Total Cars</div>
                </div>
                <div class="stat-box green">
                    <div class="number"><?= $total_trips_all ?></div>
                    <div class="label">Total Trips</div>
                </div>
                <div class="stat-box blue">
                    <div class="number"><?= $total_completed_all ?></div>
                    <div class="label">Completed</div>
                </div>
                <div class="stat-box orange">
                    <div class="number"><?= $total_in_progress_all ?></div>
                    <div class="label">In Progress</div>
                </div>
                <div class="stat-box red">
                    <div class="number"><?= $total_cancelled_all ?></div>
                    <div class="label">Cancelled</div>
                </div>
                <div class="stat-box" style="border-left-color: #6c757d;">
                    <div class="number" style="color: #6c757d;"><?= number_format(($total_trips_all > 0 ? ($total_completed_all / $total_trips_all) * 100 : 0), 1) ?>%</div>
                    <div class="label">Completion Rate</div>
                </div>
            </div>

            <!-- Car Utilization Table -->
            <div class="card">
                <h4>Car Details</h4>
                <?php if (count($report_data) > 0): ?>
                    <div class="table-container">
                        <table id="carUtilizationTable" style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Brand</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Plate Number</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Parking</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Total Trips</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Completed</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Cancelled</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">In Progress</th>
                                    <th style="text-align:left; padding:8px 10px; background:#f8f9fa; border-bottom:2px solid #dee2e6; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d;">Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data as $c): 
                                    $rate = $c['total_trips'] > 0 ? round(($c['completed_trips'] / $c['total_trips']) * 100, 1) : 0;
                                    $rate_class = $rate >= 80 ? 'rate-high' : ($rate >= 50 ? 'rate-medium' : 'rate-low');
                                ?>
                                <tr>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><strong><?= htmlspecialchars($c['brand']) ?></strong></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($c['plate_number']) ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= htmlspecialchars($c['parking'] ?? '-') ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $c['total_trips'] ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $c['completed_trips'] ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5; color:<?= ($c['cancelled_trips'] ?? 0) > 0 ? '#c62828' : 'inherit' ?>;"><?= $c['cancelled_trips'] ?? 0 ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;"><?= $c['current_trips'] ?? 0 ?></td>
                                    <td style="padding:6px 10px; border-bottom:1px solid #f1f3f5;">
                                        <span class="rate <?= $rate_class ?>" style="display:inline-block; padding:2px 12px; border-radius:12px; font-size:0.75rem; font-weight:600;"><?= $rate ?>%</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No car data available.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- jQuery and DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="/assets/js/script.js"></script>
    <script src="/admin/assets/js/admin.js"></script>
    <script>
    $(document).ready(function() {
        var outgoingTripsTable = null;

        // Initialize Outgoing Trips Table (Trip Details section)
        if ($('#outgoingTripsTable').length > 0) {
            outgoingTripsTable = $('#outgoingTripsTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                // In Progress -> Approved -> Completed -> Cancelled (data-order on the Status cell),
                // then most recent date first within each status group.
                order: [[1, 'asc'], [2, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [4, 5, 9] }
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No entries found",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching entries found"
                },
                dom: '<"dt-top"lf>t<"dt-bottom"ip>',
                classes: { sWrapper: 'dataTables_wrapper dt-custom-reports' }
            });

            // Custom filter: only show rows matching the picked date (row's data-date attribute)
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
                if (settings.nTable.id !== 'outgoingTripsTable') return true;
                var filterDate = $('#tripDateFilter').val();
                if (!filterDate) return true;
                var rowDate = $(outgoingTripsTable.row(dataIndex).node()).data('date');
                return rowDate === filterDate;
            });

            $('#tripDateFilter').on('change', function() {
                outgoingTripsTable.draw();
            });

            $('#clearDateFilter').on('click', function() {
                $('#tripDateFilter').val('');
                outgoingTripsTable.draw();
            });
        }

        // Initialize Driver Performance Table
        if ($('#driverPerformanceTable').length > 0) {
            $('#driverPerformanceTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[4, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [2, 3] }
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ drivers",
                    infoEmpty: "No drivers found",
                    infoFiltered: "(filtered from _MAX_ total drivers)",
                    zeroRecords: "No matching drivers found"
                },
                dom: '<"dt-top"lf>t<"dt-bottom"ip>',
                classes: { sWrapper: 'dataTables_wrapper dt-custom-reports' }
            });
        }

        // Initialize Car Utilization Table
        if ($('#carUtilizationTable').length > 0) {
            $('#carUtilizationTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[3, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [2] }
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
                classes: { sWrapper: 'dataTables_wrapper dt-custom-reports' }
            });
        }
    });
    
    // Show Today button functionality
    $('#showTodayFilter').on('click', function() {
        var today = new Date();
        var year = today.getFullYear();
        var month = String(today.getMonth() + 1).padStart(2, '0');
        var day = String(today.getDate()).padStart(2, '0');
        var todayStr = year + '-' + month + '-' + day;
        
        $('#tripDateFilter').val(todayStr);
        // Trigger the change event to apply the filter
        $('#tripDateFilter').trigger('change');
    });
    </script>

    <style>
    .dt-custom-reports .dataTables_length,
    .dt-custom-reports .dataTables_filter {
        margin-bottom: 10px;
        font-size: 0.85rem;
    }
    .dt-custom-reports .dataTables_length select,
    .dt-custom-reports .dataTables_filter input {
        padding: 4px 8px;
        border: 2px solid #e9ecef;
        border-radius: 4px;
        font-size: 0.85rem;
        background: white;
    }
    .dt-custom-reports .dataTables_filter input:focus,
    #tripDateFilter:focus {
        outline: none;
        border-color: #1a237e;
        box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.08);
    }
    .dt-custom-reports .dataTables_info {
        font-size: 0.85rem;
        color: #6c757d;
        padding-top: 10px;
    }
    .dt-custom-reports .dataTables_paginate {
        padding-top: 10px;
    }
    .dt-custom-reports .dataTables_paginate .paginate_button {
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
    .dt-custom-reports .dataTables_paginate .paginate_button:hover {
        background: #2b3152;
        border-color: #1a237e;
        color: #1a237e;
    }
    .dt-custom-reports .dataTables_paginate .paginate_button.current {
        background: #1a237e;
        color: #ffffff !important;
        border-color: #1a237e;
    }
    .dt-custom-reports .dataTables_paginate .paginate_button.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    .dt-custom-reports table.dataTable tbody tr:hover {
        background: #f8f9ff !important;
    }
    .rate-high {
        background: #e8f5e9;
        color: #2e7d32;
    }
    .rate-medium {
        background: #fff8e1;
        color: #f57c00;
    }
    .rate-low {
        background: #fbe9e7;
        color: #c62828;
    }
    </style>
    <script>
    (function() {
        var reportType = '<?= $report_type ?>';

        function refreshReportStats() {
            fetch(window.location.pathname + '?type=' + reportType + '&ajax_refresh=1')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (reportType === 'trips') {
                        var map = {
                            '.stat-grid .stat-box:nth-child(1) .number': data.total_trips,
                            '.stat-grid .stat-box:nth-child(2) .number': data.completed,
                            '.stat-grid .stat-box:nth-child(3) .number': data.approved,
                            '.stat-grid .stat-box:nth-child(4) .number': data.in_progress,
                            '.stat-grid .stat-box:nth-child(5) .number': data.cancelled,
                            '.stat-grid .stat-box:nth-child(6) .number': data.completion_rate + '%'
                        };
                        Object.keys(map).forEach(function(sel) {
                            var el = document.querySelector(sel);
                            if (el && el.textContent !== String(map[sel])) el.textContent = map[sel];
                        });
                    } else {
                        var map = {
                            '.stat-grid .stat-box:nth-child(2) .number': data.total_trips_all,
                            '.stat-grid .stat-box:nth-child(3) .number': data.total_completed_all,
                            '.stat-grid .stat-box:nth-child(4) .number': data.total_in_progress_all,
                            '.stat-grid .stat-box:nth-child(5) .number': data.total_cancelled_all,
                            '.stat-grid .stat-box:nth-child(6) .number': data.completion_rate + '%'
                        };
                        Object.keys(map).forEach(function(sel) {
                            var el = document.querySelector(sel);
                            if (el && el.textContent !== String(map[sel])) el.textContent = map[sel];
                        });
                    }
                })
                .catch(function(e) { console.error('Report refresh failed:', e); });
        }

        setInterval(refreshReportStats, 3000);
    })();
    </script>
</body>
</html>