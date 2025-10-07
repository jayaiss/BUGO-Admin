<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json');

// ---- Inputs ----
$email            = isset($_GET['email']) ? trim($_GET['email']) : '';
$excludeResident  = isset($_GET['exclude_resident']) ? (int)$_GET['exclude_resident'] : 0;
$excludeEmployee  = isset($_GET['exclude_employee']) ? (int)$_GET['exclude_employee'] : 0;

if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing email']);
    exit;
}

// Optional: sanity check format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'exists'          => false,
        'resident_exists' => false,
        'employee_exists' => false,
        'note'            => 'Invalid email format'
    ]);
    exit;
}

$residentExists = false;
$employeeExists = false;

/* ---------- Residents ---------- */
if ($excludeResident > 0) {
    $stmt = $mysqli->prepare("
        SELECT id 
        FROM residents 
        WHERE (email = ? OR username = ?)
          AND id <> ?
          AND resident_delete_status = 0
        LIMIT 1
    ");
    $stmt->bind_param("ssi", $email, $email, $excludeResident);
} else {
    $stmt = $mysqli->prepare("
        SELECT id 
        FROM residents 
        WHERE (email = ? OR username = ?)
          AND resident_delete_status = 0
        LIMIT 1
    ");
    $stmt->bind_param("ss", $email, $email);
}
$stmt->execute();
$stmt->store_result();
$residentExists = $stmt->num_rows > 0;
$stmt->close();

/* ---------- Employees ---------- */
if ($excludeEmployee > 0) {
    $stmt = $mysqli->prepare("
        SELECT employee_id 
        FROM employee_list 
        WHERE employee_email = ?
          AND employee_id <> ?
          AND employee_delete_status = 0
        LIMIT 1
    ");
    $stmt->bind_param("si", $email, $excludeEmployee);
} else {
    $stmt = $mysqli->prepare("
        SELECT employee_id 
        FROM employee_list 
        WHERE employee_email = ?
          AND employee_delete_status = 0
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
}
$stmt->execute();
$stmt->store_result();
$employeeExists = $stmt->num_rows > 0;
$stmt->close();

/* ---------- Output ---------- */
echo json_encode([
    'exists'          => ($residentExists || $employeeExists),
    'resident_exists' => $residentExists,
    'employee_exists' => $employeeExists
]);
