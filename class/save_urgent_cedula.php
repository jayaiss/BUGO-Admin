<?php
// class/save_urgent_cedula.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    // Only allow POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    // Required fields
    $required = ['userId', 'certificate', 'cedulaNumber', 'dateIssued', 'issuedAt', 'income'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
            exit;
        }
    }

    // Inputs
    $user_id       = (int)$_POST['userId'];
    $certificate   = trim($_POST['certificate']);
    $cedula_number = trim($_POST['cedulaNumber']);
    $issued_on     = trim($_POST['dateIssued']); // YYYY-MM-DD
    $issued_at     = trim($_POST['issuedAt']);
    $income        = (float)$_POST['income'];
    $employee_id   = (int)($_SESSION['employee_id'] ?? 0);

    if ($certificate !== 'Cedula') {
        echo json_encode(['success' => false, 'message' => 'Invalid certificate type']);
        exit;
    }

    // File validation
    if (!isset($_FILES['cedulaFile']) || $_FILES['cedulaFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Cedula file is required and must be valid.']);
        exit;
    }
    $file = $_FILES['cedulaFile'];

    $maxBytes = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 5MB).']);
        exit;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF.']);
        exit;
    }

    $cedula_img = file_get_contents($file['tmp_name']); // LONGBLOB data

    // Duplicate cedula_number check
    $dup = false;
    if ($stmt = $mysqli->prepare("SELECT 1 FROM cedula WHERE cedula_number = ? LIMIT 1")) {
        $stmt->bind_param('s', $cedula_number);
        $stmt->execute();
        $stmt->store_result();
        $dup = $dup || ($stmt->num_rows > 0);
        $stmt->close();
    }
    if (!$dup && ($stmt = $mysqli->prepare("SELECT 1 FROM urgent_cedula_request WHERE cedula_number = ? LIMIT 1"))) {
        $stmt->bind_param('s', $cedula_number);
        $stmt->execute();
        $stmt->store_result();
        $dup = $dup || ($stmt->num_rows > 0);
        $stmt->close();
    }
    if ($dup) {
        echo json_encode(['success' => false, 'message' => 'Cedula number already exists.']);
        exit;
    }

    // Fixed values
    $appointment_date       = date('Y-m-d H:i:s'); // full timestamp
    $appointment_time       = 'URGENT';
    $tracking_number        = 'BUGO-' . date('YmdHis') . random_int(1000, 9999);
    $cedula_status          = 'Pending';
    $cedula_delete_status   = 0;
    $cedula_expiration_date = date('Y') . '-12-31';

    // Insert
    $sql = "INSERT INTO urgent_cedula_request (
                res_id, employee_id, income, appointment_date, appointment_time,
                tracking_number, cedula_status, cedula_delete_status,
                cedula_number, issued_at, issued_on, cedula_img, cedula_expiration_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error]);
        exit;
    }

    // Types: i(res_id), i(employee_id), d(income), s, s, s, s, i, s, s, s, b(blob), s
    $null = null; // placeholder for blob param
    $types = "iidssssisssbs"; // 13 params: ii d s s s s i s s s b s
    $stmt->bind_param(
        $types,
        $user_id,
        $employee_id,
        $income,
        $appointment_date,
        $appointment_time,
        $tracking_number,
        $cedula_status,
        $cedula_delete_status,
        $cedula_number,
        $issued_at,
        $issued_on,
        $null,
        $cedula_expiration_date
    );

    // Blob is the 12th parameter (0-based index 11)
    $stmt->send_long_data(11, $cedula_img);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'trackingNumber' => $tracking_number]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }

    $stmt->close();
    $mysqli->close();

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unhandled error: ' . $e->getMessage()]);
}
