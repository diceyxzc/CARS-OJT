<?php

session_start();
require_once '../config/db.php';
require_once '../includes/email_functions.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Mock data for testing
$mockRequestData = [
    'request_number' => 'CAR-2024-0001',
    'requestor_name' => 'Juan Dela Cruz',
    'requestor_email' => 'juan.delacruz@glory.com.ph',
    'date' => '2024-01-15',
    'pickup_time' => '09:00:00',
    'dropoff_time' => '17:00:00',
    'pickup_location' => 'Main Office - BGC',
    'dropoff_location' => 'Client Site - Makati',
    'travel_type' => 'Drop Off',
    'purpose' => 'Client meeting',
    'remarks' => 'Client meeting at Makati office',
    'created_at' => date('Y-m-d H:i:s')
];

$mockDrivers = [
    ['name' => 'John Doe', 'mobile' => '0917-123-4567']
];

$mockCars = [
    ['brand' => 'Toyota Camry', 'plate_number' => 'ABC-1234', 'parking' => 'B2 - Slot 15']
];

$mockPassengers = [
    ['passenger_name' => 'Maria Santos'],
    ['passenger_name' => 'Pedro Reyes']
];

$mockReason = 'Driver not available on the requested date and time.';

// Get the current action
$action = $_SERVER['REQUEST_METHOD'] === 'POST' 
    ? ($_POST['action'] ?? 'preview') 
    : ($_GET['action'] ?? 'preview');
$email_type = $_GET['email_type'] ?? 'submitted';

// Build the email based on type
function getEmailPreview($type, $requestData, $drivers, $cars, $passengers, $reason) {
    switch ($type) {
        case 'submitted':
            return buildRequestSubmittedEmail($requestData, $passengers);
        case 'approved':
            return buildRequestApprovedEmail($requestData, $drivers[0], $cars[0], $passengers);
        case 'rejected':
            return buildRequestRejectedEmail($requestData, $reason);
        case 'admin':
            return buildAdminNotificationEmail($requestData, $passengers);
        default:
            return "Invalid email type";
    }
}

// Handle preview
if ($action === 'preview') {
    $email_content = getEmailPreview($email_type, $mockRequestData, $mockDrivers, $mockCars, $mockPassengers, $mockReason);
}

// Handle actual send (if user wants to test real sending)
$send_success = '';
$send_error = '';
$debug_info = '';
$show_debug = false;

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = $_POST['to_email'] ?? '';
    $type = $_POST['email_type'] ?? 'submitted';
    $show_debug = true;
    // Keep the preview in sync with whatever type was just tested
    $email_type = $type;

    if (empty($to)) {
        $send_error = "Please enter a recipient email address.";
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $send_error = "Please enter a valid email address.";
    } else {
        // Get SMTP credentials for debugging
        $creds = getEmailCredentials($pdo);
        if (!$creds) {
            $send_error = "No SMTP credentials found in tbl_noreply!";
        } else {
            $email_content = getEmailPreview($type, $mockRequestData, $mockDrivers, $mockCars, $mockPassengers, $mockReason);
            $subject = "TEST: " . ucfirst($type) . " Email - CARS System";
            
            // Build debug info
            $debug_info = "SMTP Debug Info:\n";
            $debug_info .= "─────────────────────────────\n";
            $debug_info .= "From Email: " . $creds['email'] . "\n";
            $debug_info .= "SMTP Host: " . $creds['host'] . "\n";
            $debug_info .= "SMTP Port: " . $creds['port'] . "\n";
            $debug_info .= "To Email: " . $to . "\n";
            $debug_info .= "Subject: " . $subject . "\n";
            $debug_info .= "Email Type: " . $type . "\n";
            $debug_info .= "─────────────────────────────\n";
            $debug_info .= "Email Content Length: " . strlen($email_content) . " characters\n";
            
            // Try to send — the builder functions now return full HTML documents,
            // so this MUST be sent as HTML or recipients will see raw tags.
            $result = sendEmail($to, $subject, $email_content, true);
            
            if ($result === true) {
                $send_success = "✅ Test email sent successfully to {$to}!";
                $send_success .= "\n📧 Check your inbox (and spam folder) for the email.";
                $debug_info .= "\n\n✅ Email sent successfully!";
            } else {
                $send_error = "❌ Failed to send test email to {$to}.";
                $send_error .= "\n\nPossible reasons:\n";
                $send_error .= "• Incorrect SMTP password\n";
                $send_error .= "• Email account is locked\n";
                $send_error .= "• Network/firewall issues\n";
                $send_error .= "• Check error logs for more details";
                $debug_info .= "\n\n❌ Email sending failed!";
            }
            
            // Log the debug info
            error_log("Email Test Debug:\n" . $debug_info);
        }
    }
}

// Get email type labels for display
$emailTypeLabels = [
    'submitted' => 'Request Submitted',
    'approved' => 'Request Approved',
    'rejected' => 'Request Rejected',
    'admin' => 'Admin Notification'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Test Center - CARS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        /* Preview now renders the actual HTML email inside a sandboxed iframe
           styled like an inbox reading pane, instead of dumping escaped markup. */
        .inbox-frame {
            background: #eef0f2;
            border: 1px solid #e0e2e6;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0 10px;
        }
        .inbox-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 4px 12px;
            border-bottom: 1px solid #dcdfe4;
            margin-bottom: 14px;
        }
        .inbox-dot { width: 10px; height: 10px; border-radius: 50%; }
        .inbox-dot.red { background:#ff5f57; }
        .inbox-dot.yellow { background:#febc2e; }
        .inbox-dot.green { background:#28c840; }
        .inbox-subject-line {
            margin-left: 8px;
            font-size: 0.82rem;
            color: #5b5f66;
        }
        .inbox-subject-line strong { color:#1a1a2e; }
        #emailPreviewFrame {
            width: 100%;
            min-height: 640px;
            border: none;
            background: #ffffff;
            border-radius: 6px;
            display: block;
        }
        .html-source-box {
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            margin-top: 10px;
            border: 1px solid #333;
        }
        .test-controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            margin: 15px 0;
        }
        .test-controls select, .test-controls input {
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .test-controls select:focus, .test-controls input:focus {
            outline: none;
            border-color: #1a237e;
        }
        .email-type-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-submitted { background: #e3f2fd; color: #0d47a1; }
        .badge-approved { background: #e8f5e9; color: #2e7d32; }
        .badge-rejected { background: #fbe9e7; color: #c62828; }
        .badge-admin { background: #fff3e0; color: #e65100; }
        
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .status-dot.success { background: #2e7d32; }
        .status-dot.error { background: #c62828; }
        .status-dot.warning { background: #f57c00; }
        
        .email-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 10px 14px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            margin-bottom: 10px;
            font-size: 0.85rem;
        }
        .email-meta strong {
            color: #1a237e;
        }
        .email-meta .label {
            color: #6c757d;
        }
        .debug-box {
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            white-space: pre-wrap;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
            border: 1px solid #333;
        }
        .alert-error {
            background: #fbe9e7;
            color: #c62828;
            border-left: 4px solid #c62828;
            padding: 14px 20px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
            padding: 14px 20px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .alert-error .error-details {
            font-weight: 400;
            font-size: 0.9rem;
            margin-top: 6px;
            white-space: pre-wrap;
            color: #333;
        }
        .alert-success .success-details {
            font-weight: 400;
            font-size: 0.9rem;
            margin-top: 6px;
            white-space: pre-wrap;
            color: #333;
        }
        details {
            margin-top: 15px;
        }
        details summary {
            cursor: pointer;
            font-weight: 600;
            color: #1a237e;
            padding: 8px 14px;
            background: #f0f2ff;
            border-radius: 6px;
            border: 1px solid #d0d5f0;
            display: inline-block;
        }
        details summary:hover {
            background: #e0e5ff;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="../admin/dashboard.php" class="navbar-brand">CARS <span>Email Test</span></a>
            <div class="nav-links">
                <a href="../admin/dashboard.php">Dashboard</a>
                <a href="../admin/requests.php">Requests</a>
                <a href="../admin/schedule.php">Schedule</a>
                <a href="../admin/driver_vehicle.php">Drivers & Vehicles</a>
                <a href="../admin/reports.php">Reports</a>
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
                    <button class="btn btn-cancel-modal" onclick="closeLogoutModal()">Cancel</button>
                    <a href="../logout.php" class="btn btn-danger-modal">Logout</a>
                </div>
            </div>
        </div>

        <div class="page-header">
            <div>
                <h2>📧 Email Test Center</h2>
                <p style="color:#6c757d; margin-top:5px;">Preview and test email templates before enabling them in production.</p>
            </div>
        </div>

        <?php if (!empty($send_success)): ?>
            <div class="alert alert-success">
                <strong>✅ Success!</strong>
                <div class="success-details"><?= nl2br(htmlspecialchars($send_success)) ?></div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($send_error)): ?>
            <div class="alert alert-error">
                <strong>❌ Error!</strong>
                <div class="error-details"><?= nl2br(htmlspecialchars($send_error)) ?></div>
            </div>
        <?php endif; ?>

        <!-- SMTP Status -->
        <div class="card" style="margin-bottom:20px;">
            <h4 style="margin-bottom:10px;">📡 SMTP Status</h4>
            <?php
            $creds = getEmailCredentials($pdo);
            if ($creds):
            ?>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:0.9rem;">
                    <div><strong>Email:</strong> <?= htmlspecialchars($creds['email']) ?></div>
                    <div><strong>Host:</strong> <?= htmlspecialchars($creds['host']) ?></div>
                    <div><strong>Port:</strong> <?= htmlspecialchars($creds['port']) ?></div>
                    <div>
                        <strong>Status:</strong> 
                        <span class="status-dot success"></span>
                        <span style="color:#2e7d32; font-weight:600;">✅ Configured</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-error">❌ No email credentials found in tbl_noreply</div>
            <?php endif; ?>
        </div>

        <!-- Email Templates -->
        <div class="card">
            <h4 style="margin-bottom:15px;">📝 Email Templates</h4>

            <div class="test-controls">
                <form method="GET" style="display:flex; gap:15px; flex-wrap:wrap; align-items:center; width:100%;">
                    <input type="hidden" name="action" value="preview">
                    <label style="font-weight:600; color:#1a237e;">Select Template:</label>
                    <select name="email_type" onchange="this.form.submit()" style="min-width:200px;">
                        <option value="submitted" <?= $email_type == 'submitted' ? 'selected' : '' ?>>📩 1. Request Submitted (User)</option>
                        <option value="approved" <?= $email_type == 'approved' ? 'selected' : '' ?>>✅ 2. Request Approved (User)</option>
                        <option value="rejected" <?= $email_type == 'rejected' ? 'selected' : '' ?>>❌ 3. Request Rejected (User)</option>
                        <option value="admin" <?= $email_type == 'admin' ? 'selected' : '' ?>>🔔 4. Admin Notification</option>
                    </select>
                    <span class="email-type-badge badge-<?= $email_type ?>">
                        <?= $emailTypeLabels[$email_type] ?? ucfirst($email_type) ?>
                    </span>
                </form>
            </div>

            <!-- Email Meta Info -->
            <div class="email-meta">
                <span><span class="label">📋 Template:</span> <strong><?= $emailTypeLabels[$email_type] ?? ucfirst($email_type) ?></strong></span>
                <span><span class="label">👤 Recipient:</span> <strong><?= $email_type === 'admin' ? 'Admin(s)' : 'Requestor' ?></strong></span>
                <span><span class="label">📌 Trigger:</span> <strong>
                    <?php
                    $triggers = [
                        'submitted' => 'User submits request',
                        'approved' => 'Admin approves request',
                        'rejected' => 'Admin rejects request',
                        'admin' => 'New request submitted'
                    ];
                    echo $triggers[$email_type] ?? 'N/A';
                    ?>
                </strong></span>
            </div>

            <div style="margin-bottom:10px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <span style="color:#6c757d; font-size:0.85rem;">
                    📋 This is a live rendering of exactly what gets sent — same HTML, loaded in an isolated frame so it doesn't inherit this page's styling.
                </span>
                <span style="color:#6c757d; font-size:0.75rem;">
                    <span class="status-dot success"></span> Using mock data for preview
                </span>
            </div>

            <!-- Rendered preview: a real "inbox" chrome wrapping a sandboxed iframe -->
            <div class="inbox-frame">
                <div class="inbox-toolbar">
                    <span class="inbox-dot red"></span>
                    <span class="inbox-dot yellow"></span>
                    <span class="inbox-dot green"></span>
                    <span class="inbox-subject-line">
                        Subject: <strong><?= htmlspecialchars(ucfirst($email_type)) ?> Email - CARS System</strong>
                        &nbsp;·&nbsp; To: <?= htmlspecialchars($email_type === 'admin' ? 'admin@glory.com.ph' : $mockRequestData['requestor_email']) ?>
                    </span>
                </div>
                <iframe
                    id="emailPreviewFrame"
                    sandbox=""
                    srcdoc="<?= htmlspecialchars($email_content, ENT_QUOTES) ?>"
                    title="Email preview"
                ></iframe>
            </div>

            <!-- Raw HTML source, collapsed by default, for debugging markup issues -->
            <details>
                <summary>🔍 View HTML Source</summary>
                <div class="html-source-box"><?= htmlspecialchars($email_content) ?></div>
            </details>
        </div>

        <!-- Send Test Section -->
        <div class="card">
            <h4 style="margin-bottom:10px;">📤 Send Test Email</h4>
            <p style="color:#6c757d; font-size:0.9rem; margin-bottom:15px;">
                Enter your email address to receive a test email. This will actually send an email using the SMTP credentials.
            </p>

            <form method="POST" style="display:flex; gap:15px; flex-wrap:wrap; align-items:end;">
                <input type="hidden" name="action" value="send">
                
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-weight:600; color:#1a237e; font-size:0.85rem; margin-bottom:5px;">Recipient Email</label>
                    <input type="email" name="to_email" placeholder="your-email@glory.com.ph" required 
                           style="width:100%; padding:10px 14px; border:2px solid #e9ecef; border-radius:6px; font-size:0.9rem;">
                </div>

                <div style="min-width:150px;">
                    <label style="display:block; font-weight:600; color:#1a237e; font-size:0.85rem; margin-bottom:5px;">Email Type</label>
                    <select name="email_type" style="width:100%; padding:10px 14px; border:2px solid #e9ecef; border-radius:6px; font-size:0.9rem;">
                        <option value="submitted" <?= $email_type == 'submitted' ? 'selected' : '' ?>>📩 Request Submitted</option>
                        <option value="approved" <?= $email_type == 'approved' ? 'selected' : '' ?>>✅ Request Approved</option>
                        <option value="rejected" <?= $email_type == 'rejected' ? 'selected' : '' ?>>❌ Request Rejected</option>
                        <option value="admin" <?= $email_type == 'admin' ? 'selected' : '' ?>>🔔 Admin Notification</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="padding:10px 30px; height:48px;">
                    📤 Send Test Email
                </button>
            </form>

            <div style="margin-top:12px; padding:10px 14px; background:#fff8e1; border-radius:6px; border-left:4px solid #f57c00;">
                <strong style="color:#e65100;">⚠️ Test Mode:</strong>
                <span style="color:#6c757d; font-size:0.9rem;">
                    This will send a real email using your SMTP credentials. Make sure you're using a test email address.
                </span>
            </div>
            
            <!-- Debug Info - Always visible if show_debug is true -->
            <?php if ($show_debug && !empty($debug_info)): ?>
                <details open style="margin-top:15px;">
                    <summary>🔍 View Debug Info</summary>
                    <div class="debug-box"><?= htmlspecialchars($debug_info) ?></div>
                </details>
            <?php endif; ?>
            
            <?php if ($show_debug && empty($debug_info)): ?>
                <details style="margin-top:15px;">
                    <summary>🔍 No Debug Info Available</summary>
                    <div class="debug-box" style="color:#f57c00;">No debug information was generated. Try sending again.</div>
                </details>
            <?php endif; ?>
        </div>
    <script src="/assets/js/script.js"></script>
    <script src="/admin/assets/js/admin.js"></script>
</body>
</html>