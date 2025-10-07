<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
header('Content-Type: application/json');

session_start();

// 🚫 Check login
if (empty($_SESSION['employee_id'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// Optional role check
// if ($_SESSION['role'] !== 'admin') {
//     http_response_code(403);
//     echo json_encode(['success' => false, 'message' => 'Access forbidden. Insufficient permissions.']);
//     exit;
// }

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

$data = json_decode(file_get_contents("php://input"), true);

$res_id = intval($data['userId'] ?? 0);
$attainment = sanitize_input($data['education_attainment'] ?? '');
$course = sanitize_input($data['course'] ?? '');
$employee_id = $_SESSION['employee_id'] ?? 0;

if (!$res_id || !$attainment || !$course) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// ✅ Duplication check
$check = $mysqli->prepare("SELECT COUNT(*) FROM beso WHERE res_id = ? AND beso_delete_status = 0");
$check->bind_param("i", $res_id);
$check->execute();
$check->bind_result($existingCount);
$check->fetch();
$check->close();

if ($existingCount > 0) {
    echo json_encode(['success' => false, 'message' => 'This resident already has a BESO record.']);
    exit;
}

// ✅ Insert new record
$stmt = $mysqli->prepare("INSERT INTO beso (
    res_id, education_attainment, course, employee_id, beso_delete_status
) VALUES (?, ?, ?, ?, 0)");

$stmt->bind_param("issi", $res_id, $attainment, $course, $employee_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>
