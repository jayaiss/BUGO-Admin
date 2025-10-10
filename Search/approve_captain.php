<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../logs/logs_trig.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// session_start();
$user_role   = strtolower($_SESSION['Role_Name'] ?? '');
$employee_id = intval($_SESSION['employee_id'] ?? 0);

// ✅ Role check
if ($user_role !== 'admin' && $user_role !== 'punong barangay' && $user_role !== "barangay secretary" && $user_role !== "revenue staff") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

$res_id        = intval($_POST['res_id'] ?? 0);
$selected_date = $_POST['selected_date'] ?? '';

if (!$res_id || !$selected_date) {
    echo "❌ Missing data.";
    exit;
}

$trigger      = new Trigger();
$totalUpdated = 0;

$tables = [
    ['table' => 'schedules',            'status_col' => 'status',        'date_col' => 'selected_date',   'log_file' => 3],
    ['table' => 'cedula',               'status_col' => 'cedula_status', 'date_col' => 'appointment_date','log_file' => 4],
    ['table' => 'urgent_request',       'status_col' => 'status',        'date_col' => 'selected_date',   'log_file' => 9],
    ['table' => 'urgent_cedula_request','status_col' => 'cedula_status', 'date_col' => 'appointment_date','log_file' => 10],
];

foreach ($tables as $t) {
    $stmt = $mysqli->prepare("
        UPDATE {$t['table']}
        SET {$t['status_col']} = 'ApprovedCaptain',
            is_read = 0,
            employee_id = ?
        WHERE res_id = ? 
          AND {$t['date_col']} = ?
          AND {$t['status_col']} = 'Approved'
    ");
    $stmt->bind_param("iis", $employee_id, $res_id, $selected_date);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $trigger->isStatusUpdate(
            $t['log_file'],
            $res_id,
            'ApprovedCaptain',
            "Bulk on $selected_date by employee_id {$employee_id}"
        );
        $totalUpdated += $affected;
    }
}

// ✅ Email notification
if ($totalUpdated > 0) {
    $info_query = "
        SELECT email, CONCAT(first_name, ' ', middle_name, ' ', last_name) AS full_name
        FROM residents
        WHERE id = ?
        LIMIT 1
    ";
    $stmt_info = $mysqli->prepare($info_query);
    $stmt_info->bind_param("i", $res_id);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();

    if ($row = $result_info->fetch_assoc()) {
        $email         = $row['email'];
        $resident_name = $row['full_name'];

        $mail = new PHPMailer(true);
        try {
            // ── cPanel SMTP config ─────────────────────────────────────────────
            $mail->isSMTP();
            $mail->Host          = 'mail.bugoportal.site';
            $mail->SMTPAuth      = true;
            $mail->Username      = 'admin@bugoportal.site';
            $mail->Password      = 'Jayacop@100';
            $mail->Port          = 465;
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS; // SSL (465)
            $mail->SMTPAutoTLS   = true;
            $mail->SMTPKeepAlive = false;
            $mail->Timeout       = 12;

            // TEMP: relax TLS checks if cert CN doesn't match yet
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
            $mail->Sender   = 'admin@bugoportal.site';
            $mail->Hostname = 'bugoportal.site';
            $mail->CharSet  = 'UTF-8';

            // Message
            $mail->isHTML(true);
            $mail->Subject = 'Appointment Approved by Barangay Captain';
            $mail->Body    = "
                <p>Dear {$resident_name},</p>
                <p>Your appointment(s) on <strong>{$selected_date}</strong> has/have been approved by the Barangay Captain.</p>
                <br><p>Thank you,<br>Barangay Office</p>";
            $mail->AltBody = "Dear {$resident_name},\n\nYour appointment(s) on {$selected_date} has/have been approved by the Barangay Captain.\n\nThank you.\nBarangay Office";

            $mail->send();
        } catch (Exception $e) {
            error_log("❌ Email failed to send: " . $mail->ErrorInfo);
        }
    }

    echo "✅ Approved {$totalUpdated} appointment(s)email sent.";
} else {
    echo "⚠️ No appointments found for this resident on that date.";
}
?>
