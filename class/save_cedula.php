<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}   // Still report them in logs
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();

// Read JSON input
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid data']);
    exit;
}

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

$user_id = intval($data['userId']);
$certificate = sanitize_input($data['certificate'] ?? '');

if ($certificate !== 'Cedula') {
    echo json_encode(['success' => false, 'message' => 'Invalid certificate type for this endpoint']);
    exit;
}

$isUrgent = isset($data['urgent']) && $data['urgent'] === true;

// Fetch resident details
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, suffix_name, birth_date, birth_place, res_zone, res_street_address FROM residents WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
    exit;
}

$resident = $result->fetch_assoc();
$full_name = trim("{$resident['first_name']} {$resident['middle_name']} {$resident['last_name']} {$resident['suffix_name']}");
$birth_date = $resident['birth_date'];
$birth_place = $resident['birth_place'];
$address = "Zone {$resident['res_zone']}, Phase {$resident['res_street_address']}";
$income = floatval($data['income'] ?? 0);

// Date & Time
$appointment_date = $isUrgent ? date('Y-m-d') : sanitize_input($data['selectedDate'] ?? '');
$appointment_time = $isUrgent ? 'URGENT' : sanitize_input($data['selectedTime'] ?? '');

// Auto values
$tracking_number = 'CEDULA-' . date('YmdHis') . rand(1000, 9999);
$status = "Pending";
$cedula_delete_status = 0;
$employee_id = 0;
$cedula_number = '';
$issued_at = "Barangay Hall";
$cedula_expiration_date = date('Y') . '-12-31'; // ➕ Set expiration to Dec 31 of current year

// Insert
$stmt = $mysqli->prepare("INSERT INTO cedula (
    res_id, full_name, birth_date, birthplace, income, address, appointment_date, appointment_time,
    tracking_number, cedula_status, cedula_delete_status, employee_id, cedula_number, issued_at, cedula_expiration_date
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param(
    "issssssssssisss",
    $user_id,
    $full_name,
    $birth_date,
    $birth_place,
    $income,
    $address,
    $appointment_date,
    $appointment_time,
    $tracking_number,
    $status,
    $cedula_delete_status,
    $employee_id,
    $cedula_number,
    $issued_at,
    $cedula_expiration_date
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'trackingNumber' => $tracking_number]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>
