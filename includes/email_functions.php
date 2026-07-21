<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email using PHPMailer
 */
function sendEmail($to, $subject, $body, $isHTML = true) {
    global $pdo;

    try {
        $credentials = getEmailCredentials($pdo);
        if (!$credentials) {
            throw new Exception("No email credentials found");
        }

        $mail = new PHPMailer(true);

        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = $credentials['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['email'];
        $mail->Password = $credentials['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // Recipients
        $mail->setFrom($credentials['email'], 'CARS System');
        $mail->addAddress($to);

        // CRITICAL FIX: Set content type BEFORE setting body
        $mail->ContentType = 'text/html; charset=UTF-8';
        
        // Content
        $mail->Subject = $subject;
        
        // ALWAYS set as HTML
        $mail->isHTML(true);
        
        // The HTML body
        $mail->Body = $body;
        
        // Generate plain text version by stripping HTML tags for email clients that don't support HTML
        $plainText = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $body));
        $plainText = preg_replace('/\s+/', ' ', $plainText);
        $mail->AltBody = $plainText;

        // Force the Content-Type header again (belt and suspenders)
        $mail->ContentType = 'text/html; charset=UTF-8';

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

/* =========================================================================
 * Shared HTML email chrome (wrapper, header, footer, table helpers)
 * ========================================================================= */

/**
 * Escape a value for safe HTML output. Passes through numbers/strings.
 */
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Wraps the email body content in a consistent HTML shell.
 *
 * @param string $headline    Short status headline shown under the header bar
 * @param string $accentColor Hex color used for the header bar / headline accents
 * @param string $contentHtml Inner HTML (sections, tables) for the email body
 */
function wrapEmailShell($headline, $accentColor, $contentHtml) {
    $year = date('Y');

    // Ensure proper HTML structure with DOCTYPE
    return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$headline}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family:Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08); max-width:600px; width:100%;">

          <!-- Header -->
          <tr>
            <td style="background-color:{$accentColor}; padding:24px 32px;">
              <span style="color:#ffffff; font-size:14px; letter-spacing:1px; text-transform:uppercase; font-weight:bold;">Car Allocation Reservation System</span>
              <div style="color:#ffffff; font-size:22px; font-weight:bold; margin-top:6px;">{$headline}</div>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              {$contentHtml}
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f4f5f7; padding:20px 32px; border-top:1px solid #e8e9ec;">
              <p style="margin:0; font-size:12px; color:#9a9ea6; line-height:1.6;">
                This is an automated message from the Car Allocation Reservation System. Please do not reply directly to this email.<br>
                &copy; {$year} CARS. All rights reserved.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Renders a titled section with a bordered details table of label/value rows.
 *
 * @param string $title Section title (e.g. "Request Details")
 * @param array  $rows  Associative array of label => value
 */
function renderDetailsSection($title, array $rows) {
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= <<<HTML
        <tr>
          <td style="padding:10px 16px; border-bottom:1px solid #eef0f2; font-size:13px; color:#6b7280; width:40%; vertical-align:top;">{$label}</td>
          <td style="padding:10px 16px; border-bottom:1px solid #eef0f2; font-size:14px; color:#1f2937; font-weight:600; vertical-align:top;">{$value}</td>
        </tr>
HTML;
    }

    return <<<HTML
    <div style="margin-bottom:24px;">
      <div style="font-size:13px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#374151; margin-bottom:10px;">{$title}</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef0f2; border-radius:6px; overflow:hidden;">
        {$rowsHtml}
      </table>
    </div>
HTML;
}

/**
 * Renders a status pill / badge.
 */
function renderStatusBadge($label, $bgColor, $textColor) {
    return <<<HTML
    <span style="display:inline-block; padding:6px 14px; border-radius:999px; background-color:{$bgColor}; color:{$textColor}; font-size:13px; font-weight:bold; letter-spacing:0.3px;">{$label}</span>
HTML;
}

/**
 * Builds a passenger list as an HTML unordered list, or a muted placeholder.
 */
function renderPassengerList(array $passengers) {
    if (empty($passengers)) {
        return '<p style="margin:0; font-size:14px; color:#9a9ea6;">No passengers specified</p>';
    }

    $items = '';
    foreach ($passengers as $p) {
        $items .= '<li style="padding:4px 0; font-size:14px; color:#1f2937;">' . e($p['passenger_name']) . '</li>';
    }

    return '<ul style="margin:0; padding-left:20px;">' . $items . '</ul>';
}

/* =========================================================================
 * Email builders
 * ========================================================================= */

/**
 * Build email content for request submitted
 */
function buildRequestSubmittedEmail($requestData, $passengers) {
    $date = date('F d, Y', strtotime($requestData['date']));
    $pickup_time = date('g:i A', strtotime($requestData['pickup_time']));
    $dropoff_time = $requestData['dropoff_time'] ? date('g:i A', strtotime($requestData['dropoff_time'])) : 'N/A';
    $travel_type = e($requestData['travel_type'] ?? 'N/A');
    $purpose = e($requestData['purpose'] ?? $requestData['remarks'] ?? 'N/A');

    $intro = '<p style="margin:0 0 20px; font-size:15px; color:#1f2937; line-height:1.6;">'
        . 'Dear ' . e($requestData['requestor_name']) . ',<br><br>'
        . 'Your car allocation request has been successfully submitted and is now awaiting approval.'
        . '</p>';

    $statusBlock = '<div style="margin-bottom:24px;">' . renderStatusBadge('PENDING APPROVAL', '#fff4de', '#b45309') . '</div>';

    $requestDetails = renderDetailsSection('Request Details', [
        'Request Number'    => e($requestData['request_number']),
        'Date'               => e($date),
        'Pickup Time'        => e($pickup_time),
        'Dropoff Time'       => e($dropoff_time),
        'Pickup Location'    => e($requestData['pickup_location']),
        'Dropoff Location'   => e($requestData['dropoff_location']),
        'Travel Type'        => $travel_type,
        'Purpose'            => $purpose,
    ]);

    $passengerSection = '<div style="margin-bottom:8px;">'
        . '<div style="font-size:13px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#374151; margin-bottom:10px;">Passengers</div>'
        . renderPassengerList($passengers)
        . '</div>';

    $note = '<p style="margin:24px 0 0; font-size:13px; color:#6b7280; line-height:1.6;">'
        . "You'll receive another email once your request has been reviewed. If you have any questions in the meantime, please contact HR/Admin."
        . '</p>';

    $content = $intro . $statusBlock . $requestDetails . $passengerSection . $note;

    return wrapEmailShell('Request Submitted', '#2563eb', $content);
}

/**
 * Build email content for request approved
 */
function buildRequestApprovedEmail($requestData, $driverData, $carData, $passengers) {
    $date = date('F d, Y', strtotime($requestData['date']));
    $pickup_time = date('g:i A', strtotime($requestData['pickup_time']));
    $dropoff_time = $requestData['dropoff_time'] ? date('g:i A', strtotime($requestData['dropoff_time'])) : 'N/A';
    $travel_type = e($requestData['travel_type'] ?? 'N/A');
    $purpose = e($requestData['purpose'] ?? $requestData['remarks'] ?? 'N/A');

    $intro = '<p style="margin:0 0 20px; font-size:15px; color:#1f2937; line-height:1.6;">'
        . 'Good news — your car allocation request has been <strong>approved</strong>. Your vehicle and driver details are below.'
        . '</p>';

    $statusBlock = '<div style="margin-bottom:24px;">' . renderStatusBadge('APPROVED', '#e6f7ec', '#15803d') . '</div>';

    $tripDetails = renderDetailsSection('Trip Details', [
        'Request Number'    => e($requestData['request_number']),
        'Date'               => e($date),
        'Pickup Time'        => e($pickup_time),
        'Dropoff Time'       => e($dropoff_time),
        'Pickup Location'    => e($requestData['pickup_location']),
        'Dropoff Location'   => e($requestData['dropoff_location']),
        'Travel Type'        => $travel_type,
        'Purpose'            => $purpose,
    ]);

    $vehicleDetails = renderDetailsSection('Assigned Vehicle', [
        'Car'            => e($carData['brand']),
        'Plate Number'   => e($carData['plate_number']),
        'Parking'        => e($carData['parking']),
    ]);

    $driverDetails = renderDetailsSection('Assigned Driver', [
        'Name'     => e($driverData['name']),
        'Contact'  => e($driverData['mobile']),
    ]);

    $passengerSection = '<div style="margin-bottom:8px;">'
        . '<div style="font-size:13px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#374151; margin-bottom:10px;">Passengers</div>'
        . renderPassengerList($passengers)
        . '</div>';

    $content = $intro . $statusBlock . $tripDetails . $vehicleDetails . $driverDetails . $passengerSection;

    return wrapEmailShell('Request Approved', '#15803d', $content);
}

/**
 * Build email content for request rejected
 */
function buildRequestRejectedEmail($requestData, $reason) {
    $date = date('F d, Y', strtotime($requestData['date']));
    $pickup_time = date('g:i A', strtotime($requestData['pickup_time']));
    $purpose = e($requestData['purpose'] ?? $requestData['remarks'] ?? 'N/A');

    $intro = '<p style="margin:0 0 20px; font-size:15px; color:#1f2937; line-height:1.6;">'
        . 'We regret to inform you that your car allocation request has been <strong>rejected</strong>.'
        . '</p>';

    $statusBlock = '<div style="margin-bottom:24px;">' . renderStatusBadge('REJECTED', '#fdecec', '#b91c1c') . '</div>';

    $reasonSection = '<div style="margin-bottom:24px; background-color:#fdecec; border-left:4px solid #b91c1c; border-radius:4px; padding:14px 16px;">'
        . '<div style="font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#b91c1c; margin-bottom:6px;">Reason for Rejection</div>'
        . '<p style="margin:0; font-size:14px; color:#1f2937; line-height:1.5;">' . e($reason) . '</p>'
        . '</div>';

    $requestDetails = renderDetailsSection('Request Details', [
        'Request Number'  => e($requestData['request_number']),
        'Date'             => e($date),
        'Pickup Time'      => e($pickup_time),
        'Pickup Location'  => e($requestData['pickup_location']),
        'Purpose'          => $purpose,
    ]);

    $nextSteps = '<div style="margin-bottom:8px;">'
        . '<div style="font-size:13px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#374151; margin-bottom:10px;">What You Can Do</div>'
        . '<ul style="margin:0; padding-left:20px; font-size:14px; color:#1f2937; line-height:1.7;">'
        . '<li>Submit a new request with corrected details</li>'
        . '<li>Contact HR/Admin for clarification</li>'
        . '</ul>'
        . '</div>';

    $content = $intro . $statusBlock . $reasonSection . $requestDetails . $nextSteps;

    return wrapEmailShell('Request Rejected', '#b91c1c', $content);
}

/**
 * Build email content for admin notification (new pending request)
 */
function buildAdminNotificationEmail($requestData, $passengers) {
    $date = date('F d, Y', strtotime($requestData['date']));
    $pickup_time = date('g:i A', strtotime($requestData['pickup_time']));
    $dropoff_time = $requestData['dropoff_time'] ? date('g:i A', strtotime($requestData['dropoff_time'])) : 'N/A';
    $travel_type = e($requestData['travel_type'] ?? 'N/A');
    $purpose = e($requestData['purpose'] ?? $requestData['remarks'] ?? 'N/A');
    $submitted_at = date('F d, Y g:i A', strtotime($requestData['created_at'] ?? 'now'));

    $intro = '<p style="margin:0 0 20px; font-size:15px; color:#1f2937; line-height:1.6;">'
        . 'A new car allocation request has been submitted and is pending your review.'
        . '</p>';

    $requestDetails = renderDetailsSection('Request Details', [
        'Request Number'  => e($requestData['request_number']),
        'Submitted By'     => e($requestData['requestor_name']),
        'Email'            => e($requestData['requestor_email']),
        'Submitted At'     => e($submitted_at),
        'Date'             => e($date),
        'Pickup Time'      => e($pickup_time),
        'Dropoff Time'     => e($dropoff_time),
        'Pickup Location'  => e($requestData['pickup_location']),
        'Dropoff Location' => e($requestData['dropoff_location']),
        'Travel Type'      => $travel_type,
        'Purpose'          => $purpose,
    ]);

    $passengerSection = '<div style="margin-bottom:8px;">'
        . '<div style="font-size:13px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#374151; margin-bottom:10px;">Passengers</div>'
        . renderPassengerList($passengers)
        . '</div>';

    $content = $intro . $requestDetails . $passengerSection;

    return wrapEmailShell('New Pending Request', '#7c3aed', $content);
}

/**
 * Build email content for an approved trip cancelled by admin
 */
function buildTripCancelledEmail($requestData) {
    $date = date('F d, Y', strtotime($requestData['date']));
    $pickup_time = date('g:i A', strtotime($requestData['pickup_time']));
    $dropoff_time = $requestData['dropoff_time'] ? date('g:i A', strtotime($requestData['dropoff_time'])) : 'N/A';
    $purpose = e($requestData['purpose'] ?? $requestData['remarks'] ?? 'N/A');

    $intro = '<p style="margin:0 0 20px; font-size:15px; color:#1f2937; line-height:1.6;">'
        . 'Your previously approved car allocation trip has been <strong>cancelled</strong> by the admin.'
        . '</p>';

    $statusBlock = '<div style="margin-bottom:24px;">' . renderStatusBadge('CANCELLED', '#fff4de', '#b45309') . '</div>';

    $reasonSection = '<div style="margin-bottom:24px; background-color:#fff4de; border-left:4px solid #f59e0b; border-radius:4px; padding:14px 16px;">'
        . '<p style="margin:0; font-size:14px; color:#1f2937; line-height:1.5;">This trip is no longer scheduled. If you still need transportation, please submit a new request.</p>'
        . '</div>';

    $tripDetails = renderDetailsSection('Trip Details', [
        'Request Number'    => e($requestData['request_number']),
        'Date'               => e($date),
        'Pickup Time'        => e($pickup_time),
        'Dropoff Time'       => e($dropoff_time),
        'Pickup Location'    => e($requestData['pickup_location']),
        'Dropoff Location'   => e($requestData['dropoff_location']),
        'Purpose'            => $purpose,
    ]);

    $note = '<p style="margin:24px 0 0; font-size:13px; color:#6b7280; line-height:1.6;">'
        . 'If you have any questions, please contact HR/Admin.'
        . '</p>';

    $content = $intro . $statusBlock . $reasonSection . $tripDetails . $note;

    return wrapEmailShell('Trip Cancelled', '#f59e0b', $content);
}
?>