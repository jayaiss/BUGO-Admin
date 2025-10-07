<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();

$role = $_SESSION['Role_Name'] ?? '';

if ($role !== 'Revenue Staff' && $role !== 'Admin') {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$res_id = intval($data['res_id'] ?? 0);
$field = $data['field'] ?? '';

$validFields = ['used_for_clearance', 'used_for_indigency'];

if (!$res_id || !in_array($field, $validFields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$tables = ['schedules', 'urgent_request', 'archived_schedules', 'archived_urgent_request'];
$totalUpdated = 0;

foreach ($tables as $table) {
    $sql = "UPDATE $table SET $field = 1 WHERE res_id = ? AND certificate = 'BESO Application'";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $res_id);
    if ($stmt->execute()) {
        $totalUpdated += $stmt->affected_rows;
    }
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'updated' => $totalUpdated
]);
