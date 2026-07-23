<?php
require_once '../config/db.php';
require_once '../includes/email_functions.php';

function generateRequestNumber($pdo) {
    $year = date('Y');
    $month = date('m');
    
    $stmt = $pdo->prepare("SELECT request_number FROM tbl_allocations 
                           WHERE request_number LIKE 'CAR-$year$month-%' 
                           ORDER BY request_number DESC LIMIT 1");
    $stmt->execute();
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = intval(substr($last, -4)) + 1;
    } else {
        $num = 1;
    }
    
    return "CAR-$year$month-" . str_pad($num, 4, '0', STR_PAD_LEFT);
}

$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_request'])) {
    $name = trim($_POST['name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $date = $_POST['date'] ?? '';
    $pickup_time = $_POST['pickup_time'] ?? '';
    $dropoff_time = $_POST['dropoff_time'] ?? '';
    $travel_type = $_POST['travel_type'] ?? '';
    $pickup_location = $_POST['pickup_location'] ?? '';
    $dropoff_location = $_POST['dropoff_location'] ?? '';
    $purpose = $_POST['purpose'] ?? '';
    $passengers_text = trim($_POST['passengers'] ?? '');
    
    $errors = [];
    
    // Validation
    if (empty($name)) {
        $errors[] = "Please enter your name.";
    }
    if (empty($department)) {
        $errors[] = "Please select your department.";
    }
    if (empty($contact)) {
        $errors[] = "Please enter your local number.";
    }
    if (empty($email)) {
        $errors[] = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($date)) {
        $errors[] = "Please select a date.";
    }
    if (empty($pickup_time)) {
        $errors[] = "Please select a pickup time.";
    }
    if (empty($dropoff_time)) {
        $errors[] = "Please select a dropoff time.";
    } elseif ($pickup_time && $dropoff_time && $dropoff_time <= $pickup_time) {
        $errors[] = "Dropoff time must be after pickup time.";
    }
    if (empty($travel_type)) {
        $errors[] = "Please select a travel type.";
    }
    if (empty($pickup_location)) {
        $errors[] = "Please enter a pickup location.";
    }
    if (empty($dropoff_location)) {
        $errors[] = "Please enter a dropoff location.";
    }
    if (empty($passengers_text)) {
        $errors[] = "Please enter at least one passenger name.";
    }
    
    if (empty($errors)) {
        try {
            // Check if user exists with this email
            $user_stmt = $pdo->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
            $user_stmt->execute([$email]);
            $existing_user = $user_stmt->fetch();
            
            if ($existing_user) {
                $user_id = $existing_user['user_id'];
                // Keep full_name current in case it changed since last request
                $update_name = $pdo->prepare("UPDATE tbl_users SET full_name = ? WHERE user_id = ?");
                $update_name->execute([$name, $user_id]);
            } else {
                // Username stays derived from email (for login), full_name holds what they typed
                $username = explode('@', $email)[0];
                $check_username = $pdo->prepare("SELECT user_id FROM tbl_users WHERE username = ?");
                $check_username->execute([$username]);
                if ($check_username->rowCount() > 0) {
                    $username = $username . rand(100, 999);
                }
                $insert_user = $pdo->prepare("INSERT INTO tbl_users (username, full_name, email, password, role, created_at) VALUES (?, ?, ?, ?, 'general', NOW())");
                $random_password = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $insert_user->execute([$username, $name, $email, $random_password]);
                $user_id = $pdo->lastInsertId();
            }
            
            $remarks = "Purpose: " . $purpose . " | Travel Type: " . $travel_type;
            $request_number = generateRequestNumber($pdo);
            
            $stmt = $pdo->prepare("INSERT INTO tbl_allocations (requestor_id, date, pickup_time, dropoff_time, pickup_location, dropoff_location, local_number, remarks, status, request_number, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())");
            $stmt->execute([
                $user_id, 
                $date, 
                $pickup_time, 
                $dropoff_time, 
                $pickup_location, 
                $dropoff_location, 
                $contact,
                $remarks,
                $request_number
            ]);
            $allocation_id = $pdo->lastInsertId();
            
            // Process passengers - split by comma and trim
            $passenger_names = array_map('trim', explode(',', $passengers_text));
            $passenger_names = array_filter($passenger_names); // Remove empty
            
            // Create passengers and link to allocation
            foreach ($passenger_names as $passenger_name) {
                // Check if passenger already exists for this user
                $check_stmt = $pdo->prepare("SELECT passenger_id FROM tbl_passengers WHERE passenger_name = ? AND created_by = ?");
                $check_stmt->execute([$passenger_name, $user_id]);
                $existing = $check_stmt->fetch();
                
                if ($existing) {
                    $passenger_id = $existing['passenger_id'];
                } else {
                    // Create new passenger
                    $insert_passenger = $pdo->prepare("INSERT INTO tbl_passengers (passenger_name, contact, created_by) VALUES (?, ?, ?)");
                    $insert_passenger->execute([$passenger_name, '', $user_id]);
                    $passenger_id = $pdo->lastInsertId();
                }
                
                // Link passenger to allocation
                $pdo->prepare("INSERT INTO tbl_allocated_passengers (allocation_id, passenger_id) VALUES (?, ?)")->execute([$allocation_id, $passenger_id]);
            }
            
            // Log the action
            $passenger_count = count($passenger_names);
            $log = $pdo->prepare("INSERT INTO tbl_audit_logs (user_id, action, allocation_id, details, timestamp) VALUES (?, 'created', ?, ?, NOW())");
            $log->execute([$user_id, $allocation_id, "Request submitted with $passenger_count passengers"]);
            
            $success = true;

            // ---- Send confirmation email to the requestor ----
            $requestData = [
                'request_number' => $request_number,
                'requestor_name' => $name,
                'requestor_email' => $email,
                'date' => $date,
                'pickup_time' => $pickup_time,
                'dropoff_time' => $dropoff_time,
                'pickup_location' => $pickup_location,
                'dropoff_location' => $dropoff_location,
                'travel_type' => $travel_type,
                'purpose' => $purpose,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $passengers_data = array_map(function($n) { return ['passenger_name' => $n]; }, $passenger_names);

            $requestor_subject = "Car Request Submitted - $request_number";
            $requestor_body = buildRequestSubmittedEmail($requestData, $passengers_data);
            sendEmail($email, $requestor_subject, $requestor_body, false);

            // ---- Notify all admins ----
            $admin_stmt = $pdo->prepare("SELECT email FROM tbl_users WHERE role = 'admin'");
            $admin_stmt->execute();
            $admin_emails = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

            $admin_subject = "New Car Request - $request_number";
            $admin_body = buildAdminNotificationEmail($requestData, $passengers_data);

            foreach ($admin_emails as $admin_email) {
                sendEmail($admin_email, $admin_subject, $admin_body, false);
            }
            
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Request Car - CARS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../pages/user/user.css">
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <style>
        /* Modal Overlay - FIXED POSITIONING */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex !important;
        }

        .modal-overlay .modal-box {
            background: white;
            border-radius: 12px;
            padding: 30px 35px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
            position: relative;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-30px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-close {
            position: absolute;
            top: 12px;
            right: 16px;
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

        /* ========================================
        CONFIRMATION MODAL - LARGER & CLEANER
        ======================================== */

        .confirm-modal .modal-box {
            max-width: 700px;
            padding: 35px 40px;
        }

        .confirm-modal .modal-box .confirm-title {
            color: #1a237e;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .confirm-modal .modal-box .confirm-subtitle {
            color: #6c757d;
            text-align: center;
            font-size: 1rem;
            margin-bottom: 20px;
        }

        .confirm-modal .modal-box .trip-summary-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 12px;
            margin: 8px 0;
            border: 1px solid #e9ecef;
            font-size: 1rem;
        }

        .confirm-modal .modal-box .trip-summary-box .row {
            display: flex;
            padding: 7px 0;
            border-bottom: 1px solid #f1f3f5;
        }

        .confirm-modal .modal-box .trip-summary-box .row:last-child {
            border-bottom: none;
        }

        .confirm-modal .modal-box .trip-summary-box .row .label {
            font-weight: 600;
            color: #495057;
            min-width: 160px;
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .confirm-modal .modal-box .trip-summary-box .row .value {
            color: #1a1a2e;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .confirm-modal .modal-box .trip-summary-box .passenger-tag-mini {
            display: inline-block;
            background: #e8f0fe;
            color: #1a237e;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin: 2px 3px;
        }

        .confirm-modal .modal-box .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 22px;
        }

        .confirm-modal .modal-box .btn-confirm {
            background: #1a237e;
            color: white;
            padding: 14px 36px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.2s;
        }   

        .confirm-modal .modal-box .btn-confirm:hover {
            background: #283593;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26, 35, 126, 0.3);
        }

        .confirm-modal .modal-box .btn-cancel-modal {
            background: #e9ecef;
            color: #1a1a2e;
            padding: 14px 36px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.2s;
        }

        .confirm-modal .modal-box .btn-cancel-modal:hover {
            background: #dee2e6;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .modal-overlay .modal-box {
                padding: 25px 20px;
                max-width: 100%;
                margin: 10px;
            }
            
            .confirm-modal .modal-box {
                max-width: 100%;
                padding: 25px 20px;
            }
            
            .confirm-modal .modal-box .trip-summary-box .row {
                flex-direction: column;
                gap: 2px;
                padding: 6px 0;
            }
            
            .confirm-modal .modal-box .trip-summary-box .row .label {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="gen_user.php" class="navbar-brand">CARS <span>Request</span></a>
        </div>
    </nav>

    <div class="container">
        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="success-page">
                <div class="success-icon">✓</div>
                <h2>Request Submitted Successfully!</h2>
                <p>Your car allocation request has been submitted. The HR Manager will review your request and assign a driver and car.</p>
                <p style="font-size:0.85rem; color:#6c757d; margin-top:3px;">You will receive a confirmation email once your request is approved.</p>
                <button class="btn btn-primary" onclick="window.location.href='gen_user.php'" style="margin-top:12px; padding:10px 24px; font-size:0.9rem;">
                    Submit Another Request
                </button>
            </div>
        <?php else: ?>
            <!-- Request Form -->
            <div class="request-page">
                <div class="page-header">
                    <h2>Request Car</h2>
                    <p class="sub">Fill in the details below to request a car allocation. An admin will assign a driver and car to your request.</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= $error ?></div>
                <?php endif; ?>
                
                <div class="card">
                    <form method="POST" id="requestForm">
                        <input type="hidden" name="submit_request" value="1">
                        
                        <!-- Personal Information -->
                        <h3>Personal Information</h3>
                        <div class="form-row-3">
                            <div class="form-group floating-group">
                                <input type="text" class="form-control-modern" placeholder=" " name="name" id="req_name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                                <label for="req_name">Full Name <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group choices-floating">
                                <select class="form-control-modern" name="department" id="req_department" required>
                                    <?php
                                    $departments = [
                                        'ACCOUNTING',
                                        'DOK',
                                        'HR & ADMINISTRATION',
                                        'ICT',
                                        'PARTS INSPECTION',
                                        'PARTS PRODUCTION',
                                        'PPIC',
                                        'PROD. TECHNOLOGY',
                                        'PRODUCTION 1',
                                        'PRODUCTION 2',
                                        'PRODUCTION SUPPORT',
                                        'PURCHASING',
                                        'QUALITY ASSURANCE',
                                        'QUALITY CONTROL',
                                        'N/A'
                                    ];
                                    $selected_dept = $_POST['department'] ?? '';
                                    foreach ($departments as $dept) {
                                        $selected = ($selected_dept === $dept) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($dept) . "\" $selected>" . htmlspecialchars($dept) . "</option>";
                                    }
                                    ?>
                                </select>
                                <label for="req_department">Department <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <input type="text" class="form-control-modern" placeholder=" " name="contact" id="req_contact" required value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>">
                                <label for="req_contact">Local Number <span class="required">*</span></label>
                            </div>
                        </div>
                        
                        <div class="form-group floating-group">
                            <input type="email" class="form-control-modern" placeholder=" " name="email" id="req_email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <label for="req_email">Email Address (example@glory.com.ph) <span class="required">*</span></label>
                        </div>
                        
                        <!-- Trip Details -->
                        <h3>Trip Details</h3>
                        <div class="form-row-3">
                            <div class="form-group floating-group">
                                <input type="date" class="form-control-modern" placeholder=" " name="date" id="req_date" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">
                                <label for="req_date">Trip Date <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <input type="time" class="form-control-modern" placeholder=" " name="pickup_time" id="req_pickup_time" required value="<?= htmlspecialchars($_POST['pickup_time'] ?? '') ?>">
                                <label for="req_pickup_time">Departure <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <input type="time" class="form-control-modern" placeholder=" " name="dropoff_time" id="req_dropoff_time" required value="<?= htmlspecialchars($_POST['dropoff_time'] ?? '') ?>">
                                <label for="req_dropoff_time">Arrival <span class="required">*</span></label>
                            </div>
                        </div>
                        
                        <div class="form-row-2">
                            <div class="form-group floating-group">
                                <input type="text" class="form-control-modern" placeholder=" " name="pickup_location" id="req_pickup_location" required value="<?= htmlspecialchars($_POST['pickup_location'] ?? '') ?>">
                                <label for="req_pickup_location">Pickup Location <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <input type="text" class="form-control-modern" placeholder=" " name="dropoff_location" id="req_dropoff_location" required value="<?= htmlspecialchars($_POST['dropoff_location'] ?? '') ?>">
                                <label for="req_dropoff_location">Dropoff Location <span class="required">*</span></label>
                            </div>
                        </div>
                        
                        <div class="form-row-2">
                            <div class="form-group floating-group">
                                <select class="form-control-modern" name="travel_type" id="req_travel_type" required>
                                    <option value="">Select Travel Type</option>
                                    <option value="Drop Off" <?= ($_POST['travel_type'] ?? '') === 'Drop Off' ? 'selected' : '' ?>>Drop Off</option>
                                    <option value="Back and Forth" <?= ($_POST['travel_type'] ?? '') === 'Back and Forth' ? 'selected' : '' ?>>Back and Forth</option>
                                </select>
                                <label for="req_travel_type">Travel Type <span class="required">*</span></label>
                            </div>
                            <div class="form-group floating-group">
                                <input type="text" class="form-control-modern" placeholder=" " name="purpose" id="req_purpose" required value="<?= htmlspecialchars($_POST['purpose'] ?? '') ?>">
                                <label for="req_purpose">Purpose of Travel <span class="required">*</span></label>
                            </div>
                        </div>
                        
                        <!-- Passengers Section - Simple Input Field -->
                        <h3>Passengers</h3>
                        <div class="passenger-section">
                            <div class="section-title">
                                Passenger/s
                                <span style="font-size:0.7rem; color:#6c757d; font-weight:400; margin-left:8px;">(Separate multiple names with commas)</span>
                            </div>
                            
                            <div class="form-group floating-group" style="margin-bottom:0;">
                                <input type="text" class="form-control-modern" placeholder=" " name="passengers" id="req_passengers" required value="<?= htmlspecialchars($_POST['passengers'] ?? '') ?>">
                                <label for="req_passengers">Enter passenger names</label>
                            </div>
                            <div class="info-text">Example: John Doe, Jane Smith, Bob Johnson</div>
                        </div>
                        
                        <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary" id="submitBtn">Review Request</button>
                            <button type="reset" class="btn btn-secondary" onclick="resetForm()">Clear All</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Confirmation Modal -->
        <div class="modal-overlay confirm-modal" id="confirmModal">
            <div class="modal-box">
                <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
                <div class="confirm-title">Review Your Request</div>
                <div class="confirm-subtitle">Please review all trip details before submitting your request.</div>
                
                <div class="trip-summary-box" id="confirmTripSummary">
                    <div class="row"><span class="label">Name:</span><span class="value" id="confirmName">-</span></div>
                    <div class="row"><span class="label">Department:</span><span class="value" id="confirmDepartment">-</span></div>
                    <div class="row"><span class="label">Local Number:</span><span class="value" id="confirmLocalNumber">-</span></div>
                    <div class="row"><span class="label">Email:</span><span class="value" id="confirmEmail">-</span></div>
                    <div class="row"><span class="label">Date:</span><span class="value" id="confirmDate">-</span></div>
                    <div class="row"><span class="label">Time:</span><span class="value" id="confirmTime">-</span></div>
                    <div class="row"><span class="label">Route:</span><span class="value" id="confirmRoute">-</span></div>
                    <div class="row"><span class="label">Travel Type:</span><span class="value" id="confirmTravelType">-</span></div>
                    <div class="row"><span class="label">Purpose:</span><span class="value" id="confirmPurpose">-</span></div>
                    <div class="row"><span class="label">Passengers:</span><span class="value" id="confirmPassengers">-</span></div>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel-modal" onclick="closeConfirmModal()">Go Back</button>
                    <button type="button" class="btn btn-confirm" id="confirmSubmitBtn">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Submitting Overlay -->
    <div class="modal-overlay submitting-overlay" id="submittingOverlay">
        <div class="submitting-box">
            <div class="submitting-spinner"></div>
            <div class="submitting-title">Submitting your request...</div>
            <div class="submitting-subtitle">Sending confirmation email, please wait.</div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script src="../pages/user/user.js"></script>
    
    <script>
    function showSubmittingOverlay() {
        var overlay = document.getElementById('submittingOverlay');
        if (overlay) overlay.classList.add('active');

        var submitBtn = document.getElementById('submitBtn');
        if (submitBtn) submitBtn.disabled = true;
        var confirmBtn = document.getElementById('confirmSubmitBtn');
        if (confirmBtn) confirmBtn.disabled = true;
    }

    var confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', showSubmittingOverlay);
    }
    </script>
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const select = document.getElementById('req_department');
        const group = select.closest('.floating-group');

        new Choices(select, {
            searchEnabled: true,
            itemSelectText: '',
            shouldSort: false,
            placeholder: true,
            placeholderValue: 'Select Department (Put N/A if none)'
        });

        function updateLabelState() {
            group.classList.toggle('has-value', !!select.value);
        }

        select.addEventListener('change', updateLabelState);
        updateLabelState(); 
    });
    </script>
</body>
</html>