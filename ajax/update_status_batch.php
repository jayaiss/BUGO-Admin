<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Default to JSON
header('Content-Type: application/json');

// ----- Fatal/Warning handlers -> JSON -----
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'EXCEPTION: ' . $e->getMessage(), 'line' => $e->getLine()]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "ERROR: [$errno] $errstr at $errfile:$errline"]);
    exit;
});

// ----- Bootstrap / deps -----
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

require_once __DIR__ . '/../logs/logs_trig.php';
$trigger = new Trigger();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ----- Session / AuthZ -----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role         = $_SESSION['Role_Name']     ?? '';
$employee_id  = (int)($_SESSION['employee_id'] ?? 0); // ✅ who performs the update

if ($role !== 'Revenue Staff' && $role !== 'Admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// ----- Inputs -----
$tracking_number = $_POST['tracking_number'] ?? '';
$new_status      = $_POST['new_status'] ?? '';
$reason          = $_POST['rejection_reason'] ?? '';
$cedula_number   = $_POST['cedula_number'] ?? '';
$apply_all       = isset($_POST['apply_all_same_day']) && $_POST['apply_all_same_day'] === '1';

if ($tracking_number === '' || $new_status === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// ----- Locate base record (res_id + date) from any table that holds the tracking number -----
$query = "
    SELECT res_id, selected_date AS appt_date FROM schedules WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date FROM cedula WHERE tracking_number = ?
    UNION
    SELECT res_id, selected_date AS appt_date FROM urgent_request WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date FROM urgent_cedula_request WHERE tracking_number = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
$stmt->execute();
$result = $stmt->get_result();

if (!($row = $result->fetch_assoc())) {
    echo json_encode(['success' => false, 'message' => 'Failed to find appointment info.']);
    exit;
}
$res_id = (int)$row['res_id'];
$date   = $row['appt_date'] ?? null;

/**
 * Helper to build BESO skip condition (revenue staff cannot touch "BESO Application")
 * We will reuse this in the safety guard and the update logic.
 */
$buildSkipBesoCondition = function (string $table, string $role): string {
    if ($role === 'Revenue Staff' && in_array($table, ['schedules', 'urgent_request'], true)) {
        return " AND (certificate IS NULL OR LOWER(TRIM(certificate)) != 'beso application')";
    }
    return '';
};

if ($new_status === 'Released') {
    if ($apply_all && $date) {
        // Check all target rows across the 4 tables for this resident on this date
        $tablesToCheck = [
            // table, statusCol, dateCol
            ['schedules',              'status',         'selected_date'],
            ['cedula',                 'cedula_status',  'appointment_date'],
            ['urgent_request',         'status',         'selected_date'],
            ['urgent_cedula_request',  'cedula_status',  'appointment_date'],
        ];

        $violations = 0;
        foreach ($tablesToCheck as [$table, $statusCol, $dateCol]) {
            $skipBeso = $buildSkipBesoCondition($table, $role);

            // Count rows that would be affected AND are NOT in ApprovedCaptain
            $sqlChk = "SELECT COUNT(*) AS bad
                       FROM {$table}
                       WHERE res_id = ? AND {$dateCol} = ? {$skipBeso}
                         AND COALESCE({$statusCol}, '') <> 'ApprovedCaptain'";
            $st = $mysqli->prepare($sqlChk);
            $st->bind_param("is", $res_id, $date);
            $st->execute();
            $rs = $st->get_result();
            $bad = (int)($rs->fetch_assoc()['bad'] ?? 0);
            $st->close();

            $violations += $bad;
            if ($violations > 0) break;
        }

        if ($violations > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'You can only set status to "Released" after all affected records are "ApprovedCaptain".'
            ]);
            exit;
        }
    } else {
        // Single record: inspect current status from whichever table holds this tracking number
        $sqlCurrent = "
            SELECT st FROM (
                SELECT cedula_status AS st FROM cedula                 WHERE tracking_number = ?
                UNION ALL
                SELECT cedula_status AS st FROM urgent_cedula_request WHERE tracking_number = ?
                UNION ALL
                SELECT status        AS st FROM schedules              WHERE tracking_number = ?
                UNION ALL
                SELECT status        AS st FROM urgent_request         WHERE tracking_number = ?
            ) x LIMIT 1
        ";
        $stc = $mysqli->prepare($sqlCurrent);
        $stc->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
        $stc->execute();
        $rsc = $stc->get_result();
        $currentStatus = $rsc && $rsc->num_rows ? (string)$rsc->fetch_assoc()['st'] : '';
        $stc->close();

        if ($currentStatus !== 'ApprovedCaptain') {
            echo json_encode([
                'success' => false,
                'message' => 'You can only set status to "Released" after it is "ApprovedCaptain".'
            ]);
            exit;
        }
    }
}

// ====================================================================
// Cedula-number requirement: only when releasing AND a cedula row is hit
// ====================================================================

// 1) Is the clicked tracking number itself a cedula/urgent-cedula?
$isCedulaTracking = false;
$chk = $mysqli->prepare("
    (SELECT 1 AS x FROM cedula WHERE tracking_number = ?)
    UNION ALL
    (SELECT 1 FROM urgent_cedula_request WHERE tracking_number = ?)
    LIMIT 1
");
$chk->bind_param("ss", $tracking_number, $tracking_number);
$chk->execute();
$chkRes = $chk->get_result();
$isCedulaTracking = ($chkRes && $chkRes->num_rows > 0);
$chk->close();

// 2) If applying to all same-day, will we touch any cedula records that day?
$hasSameDayCedula = false;
if ($apply_all && $date) {
    $chk2 = $mysqli->prepare("
        (SELECT 1 AS x FROM cedula WHERE res_id = ? AND appointment_date = ?)
        UNION ALL
        (SELECT 1 FROM urgent_cedula_request WHERE res_id = ? AND appointment_date = ?)
        LIMIT 1
    ");
    $chk2->bind_param("isis", $res_id, $date, $res_id, $date);
    $chk2->execute();
    $r2 = $chk2->get_result();
    $hasSameDayCedula = ($r2 && $r2->num_rows > 0);
    $chk2->close();
}

// Only require cedula number if we're releasing AND a cedula row is part of this update
$requiresCedulaNumber = ($new_status === 'Released') && ($isCedulaTracking || $hasSameDayCedula);

if ($requiresCedulaNumber) {
    $cedula_number = trim($cedula_number);

    if ($cedula_number === '') {
        echo json_encode(['success' => false, 'message' => 'Cedula number is required when status is Released.']);
        exit;
    }
    // Adjust pattern to your exact format if needed
    if (!preg_match('/^[A-Z0-9\-\/]{4,32}$/i', $cedula_number)) {
        echo json_encode(['success' => false, 'message' => 'Cedula number format is invalid.']);
        exit;
    }

    // No-duplicate guarantee across BOTH cedula tables
    $dupSql = "
        SELECT src FROM (
            SELECT 'cedula' AS src, tracking_number FROM cedula WHERE cedula_number = ? AND tracking_number <> ?
            UNION ALL
            SELECT 'urgent_cedula_request' AS src, tracking_number FROM urgent_cedula_request WHERE cedula_number = ? AND tracking_number <> ?
        ) t LIMIT 1
    ";
    $stmtDup = $mysqli->prepare($dupSql);
    $stmtDup->bind_param("ssss", $cedula_number, $tracking_number, $cedula_number, $tracking_number);
    $stmtDup->execute();
    $dupRes = $stmtDup->get_result();
    if ($dupRes && $dupRes->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Cedula number already exists. Please use a unique number.']);
        exit;
    }
}

// ----- UPDATE LOGIC -----
$tablesApplyAll = [
    // table, statusCol, dateCol, filename (for trigger)
    ['schedules',              'status',         'selected_date',     3],
    ['cedula',                 'cedula_status',  'appointment_date',  4],
    ['urgent_request',         'status',         'selected_date',     9],
    ['urgent_cedula_request',  'cedula_status',  'appointment_date', 10],
];

$tablesSingle = [
    // table, statusCol, filename (for trigger)
    ['schedules',              'status',        3],
    ['cedula',                 'cedula_status', 4],
    ['urgent_request',         'status',        9],
    ['urgent_cedula_request',  'cedula_status', 10],
];

try {
    if ($apply_all) {
        $mysqli->begin_transaction();
        $logged = false;

        foreach ($tablesApplyAll as [$table, $statusCol, $dateCol, $filename]) {
            $skipBeso = $buildSkipBesoCondition($table, $role);

            if (in_array($table, ['cedula', 'urgent_cedula_request'], true) && $requiresCedulaNumber) {
                // With cedula number (Released) for cedula tables
                $sql = "UPDATE $table 
                        SET $statusCol = ?, rejection_reason = ?, cedula_number = ?, notif_sent = 1, is_read = 0, employee_id = ?, update_time = NOW()
                        WHERE res_id = ? AND $dateCol = ? $skipBeso";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssiis", $new_status, $reason, $cedula_number, $employee_id, $res_id, $date);
            } else {
                // Without cedula number
                $sql = "UPDATE $table 
                        SET $statusCol = ?, rejection_reason = ?, notif_sent = 1, is_read = 0, employee_id = ?, update_time = NOW()
                        WHERE res_id = ? AND $dateCol = ? $skipBeso";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("ssiis", $new_status, $reason, $employee_id, $res_id, $date);
            }

            if (!$stmtUpdate->execute()) {
                throw new Exception($stmtUpdate->error, (int)$stmtUpdate->errno);
            }

            if ($stmtUpdate->affected_rows > 0 && !$logged) {
                // Log once per batch (include who)
                $trigger->isStatusUpdate($filename, $res_id, $new_status, $tracking_number . " | by employee_id {$employee_id}");
                $logged = true;
            }
        }

        $mysqli->commit();
    } else {
        $mysqli->begin_transaction();
        $updated = false;

        foreach ($tablesSingle as [$table, $statusCol, $filename]) {
            if (in_array($table, ['cedula', 'urgent_cedula_request'], true) && $requiresCedulaNumber) {
                $sql = "UPDATE $table
                        SET $statusCol = ?, rejection_reason = ?, cedula_number = ?, notif_sent = 1, is_read = 0, employee_id = ?, update_time = NOW()
                        WHERE tracking_number = ?";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssis", $new_status, $reason, $cedula_number, $employee_id, $tracking_number);
            } else {
                $sql = "UPDATE $table
                        SET $statusCol = ?, rejection_reason = ?, notif_sent = 1, is_read = 0, employee_id = ?, update_time = NOW()
                        WHERE tracking_number = ?";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("ssis", $new_status, $reason, $employee_id, $tracking_number);
            }

            if (!$stmtUpdate->execute()) {
                throw new Exception($stmtUpdate->error, (int)$stmtUpdate->errno);
            }

            if ($stmtUpdate->affected_rows > 0) {
                $trigger->isStatusUpdate($filename, $res_id, $new_status, $tracking_number . " | by employee_id {$employee_id}");
                $updated = true;
                break; // stop after first matching table (mimic original behavior)
            }
        }

        if (!$updated) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => 'No records updated.']);
            exit;
        }

        $mysqli->commit();
    }
} catch (Exception $ex) {
    // 1062: duplicate key (e.g., cedula_number unique index) -> race-safe error
    if ((int)$ex->getCode() === 1062) {
        echo json_encode(['success' => false, 'message' => 'Cedula number already exists. Please use a unique number.']);
        exit;
    }
    error_log("Update failed: " . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
    exit;
}

// ----- EMAIL NOTIFICATION (cPanel mailbox; no env) -----
$email_query = "
    SELECT r.email, r.contact_number, 
           CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name,
           CASE 
             WHEN uc.tracking_number IS NOT NULL THEN 'Urgent Cedula'
             WHEN c.tracking_number  IS NOT NULL THEN 'Cedula'
             WHEN ur.tracking_number IS NOT NULL THEN 'Urgent Request'
             WHEN s.tracking_number  IS NOT NULL THEN 'Schedule'
             ELSE 'Appointment'
           END AS certificate
    FROM residents r
    LEFT JOIN cedula c                 ON r.id = c.res_id                 AND c.tracking_number  = ?
    LEFT JOIN urgent_cedula_request uc ON r.id = uc.res_id                AND uc.tracking_number = ?
    LEFT JOIN urgent_request ur        ON r.id = ur.res_id                AND ur.tracking_number = ?
    LEFT JOIN schedules s              ON r.id = s.res_id                 AND s.tracking_number  = ?
    WHERE r.id = ?
    LIMIT 1
";
$stmt_email = $mysqli->prepare($email_query);
$stmt_email->bind_param("ssssi", $tracking_number, $tracking_number, $tracking_number, $tracking_number, $res_id);
$stmt_email->execute();
$result_email = $stmt_email->get_result();

if ($result_email && $result_email->num_rows > 0) {
    $rowE          = $result_email->fetch_assoc();
    $email         = (string)$rowE['email'];
    $resident_name = (string)$rowE['full_name'];
    $certificate   = (string)$rowE['certificate'];

    if ($email !== '') {
        $mail = new PHPMailer(true);
        try {
            // SMTP using your cPanel mailbox
            $mail->isSMTP();
            $mail->Host          = 'mail.bugoportal.site';
            $mail->SMTPAuth      = true;
            $mail->Username      = 'admin@bugoportal.site';
            $mail->Password      = 'Jayacop@100';
            $mail->Port          = 465;
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Timeout       = 12;
            $mail->SMTPAutoTLS   = true;
            $mail->SMTPKeepAlive = false;

            // (Optional) relax TLS checks if cert mismatch (dev/shared hosts)
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];

            // Headers
            $mail->setFrom('admin@bugoportal.site', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->addReplyTo('admin@bugoportal.site', 'Barangay Office');
            // $mail->addBCC('admin@bugoportal.site'); // enable while testing deliveries

            // Content
            $mail->isHTML(true);
            $prettyDate  = $date ? date('F j, Y', strtotime($date)) : 'your date';
            $mail->Subject = 'Appointment Status Update';
            $mail->Body    = "<p>Dear {$resident_name},</p>
                              <p>Your {$certificate} appointment on <strong>{$prettyDate}</strong> has been updated to:</p>
                              <h3 style='color:#0d6efd;margin:8px 0'>{$new_status}</h3>"
                              . ($reason !== '' ? "<p><em>Reason: ".htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')."</em></p>" : "")
                              . "<br><p>Thank you,<br>Barangay Office</p>";
            $mail->AltBody = "Dear {$resident_name},\n\nYour {$certificate} appointment on {$prettyDate} has been updated to \"{$new_status}\"."
                             . ($reason !== '' ? "\nReason: {$reason}" : "")
                             . "\n\nThank you.\nBarangay Office";

            $mail->send();
        } catch (Exception $e) {
            // Don’t fail the request just because email failed
            error_log("Email failed to send: " . $mail->ErrorInfo);
        }
    }
}

// ----- Success response -----
echo json_encode(['success' => true, 'message' => 'Status updated and email sent.']);
exit;


