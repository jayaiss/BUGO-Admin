<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json'); // ✅ Required for clean JSON
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();
include_once '../logs/logs_trig.php';
$trigs = new Trigger();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['userId'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

$user_id = intval($data['userId']);
$certificate = sanitize_input($data['certificate'] ?? '');
$isUrgent = isset($data['urgent']) && $data['urgent'] === true;
$trackingNumber = 'BUGO-' . date('YmdHis') . rand(1000, 9999);
$status = 'Pending';
$appointmentDeleteStatus = 0;

// Fetch resident info
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, suffix_name FROM residents WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
    exit;
}
$resident = $result->fetch_assoc();
$full_name = trim("{$resident['first_name']} {$resident['middle_name']} {$resident['last_name']} {$resident['suffix_name']}");

$purpose = sanitize_input($data['purpose'] ?? '');
$additionalDetails = sanitize_input($data['additionalDetails'] ?? '');
$selectedDate = $isUrgent ? date('Y-m-d') : sanitize_input($data['selectedDate'] ?? '');
$selectedTime = $isUrgent ? 'URGENT' : sanitize_input($data['selectedTime'] ?? '');
$employeeId = $_SESSION['employee_id'] ?? 0;

// ✨ CEDULA URGENT
if ($certificate === 'Cedula' && $isUrgent) {
    $income = floatval($data['income'] ?? 0);
    $cedulaDeleteStatus = 0;
    $cedulaStatus = 'Pending';
    $cedulaNumber = '';
    $issued_at = "Bugo, Cagayan de Oro City";
    $issued_on = date("Y-m-d");
    $cedulaImg = null;
    $cedula_expiration_date = date('Y') . '-12-31';

    $stmt = $mysqli->prepare("INSERT INTO urgent_cedula_request (
        res_id, employee_id, income, appointment_date, appointment_time,
        tracking_number, cedula_status, cedula_delete_status,
        cedula_number, issued_at, issued_on, cedula_img, cedula_expiration_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        'iisssssisssss',
        $user_id,
        $employeeId,
        $income,
        $selectedDate,
        $selectedTime,
        $trackingNumber,
        $cedulaStatus,
        $cedulaDeleteStatus,
        $cedulaNumber,
        $issued_at,
        $issued_on,
        $cedulaImg,
        $cedula_expiration_date
    );
}

// ✨ BESO URGENT (only insert into urgent_request)
elseif ($isUrgent && $certificate === 'BESO Application') {
    $selectedDateInt = intval(date('Ymd'));
    $urgentDeleteStatus = 0;
    // $purpose = 'BESO Application'; // ✅ Use a variable here

    $stmt = $mysqli->prepare("INSERT INTO urgent_request (
        employee_id, res_id, certificate, purpose, selected_date, selected_time,
        tracking_number, status, urgent_delete_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        'iissssssi',
        $employeeId,
        $user_id,
        $certificate,
        $purpose, // ✅ This fixes the "not passed by reference" error
        $selectedDateInt,
        $selectedTime,
        $trackingNumber,
        $status,
        $urgentDeleteStatus
    );
}


// ✨ OTHER URGENT
elseif ($isUrgent) {
    $selectedDateInt = intval(date('Ymd'));
    $urgentDeleteStatus = 0;

    $stmt = $mysqli->prepare("INSERT INTO urgent_request (
        employee_id, res_id, certificate, purpose, selected_date, selected_time,
        tracking_number, status, urgent_delete_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        'iissssssi',
        $employeeId,
        $user_id,
        $certificate,
        $purpose,
        $selectedDateInt,
        $selectedTime,
        $trackingNumber,
        $status,
        $urgentDeleteStatus
    );
}

// ✨ REGULAR SCHEDULE
else {
    $stmt = $mysqli->prepare("INSERT INTO schedules (
        fullname, purpose, additional_details, selected_date, selected_time, certificate,
        tracking_number, res_id, status, appointment_delete_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        'sssssssisi',
        $full_name,
        $purpose,
        $additionalDetails,
        $selectedDate,
        $selectedTime,
        $certificate,
        $trackingNumber,
        $user_id,
        $status,
        $appointmentDeleteStatus
    );
}

if ($stmt->execute()) {
    // ✅ Trigger audit log based on certificate type
    $trigs = new Trigger();

    if (strtolower($certificate) === 'cedula' && $isUrgent) {
        $trigs->isUrgent(10, $user_id); // Cedula request
    } elseif (strtolower($certificate) === 'beso application' && $isUrgent) {
        $trigs->isUrgent(9, $user_id); // BESO urgent request
    } elseif ($isUrgent) {
        $trigs->isUrgent(9, $user_id); // Other urgent requests
    } else {
        $trigs->isUrgent(9, $user_id); // Regular schedule
    }

    // ✅ BESO used-for tracking logic
    if (strtolower($certificate) === 'beso application') {
        $residencyTables = [
            'schedules' => 'appointment_delete_status = 0 AND status = "Released"',
            'urgent_request' => 'urgent_delete_status = 0 AND status = "Released"',
            'archived_schedules' => '1',
            'archived_urgent_request' => '1'
        ];

        foreach ($residencyTables as $table => $whereClause) {
            $updateQuery = "
                UPDATE $table
                SET barangay_residency_used_for_beso = 1
                WHERE res_id = ?
                AND certificate = 'Barangay Residency'
                AND purpose = 'First Time Jobseeker'
                AND barangay_residency_used_for_beso = 0
                AND $whereClause
                ORDER BY created_at DESC
                LIMIT 1
            ";

            $updateResidency = $mysqli->prepare($updateQuery);
            if ($updateResidency) {
                $updateResidency->bind_param("i", $user_id);
                $updateResidency->execute();
                $updateResidency->close();
            }
        }
    }

    echo json_encode(['success' => true, 'trackingNumber' => $trackingNumber]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}



$stmt->close();
$mysqli->close();
?>
