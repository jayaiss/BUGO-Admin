<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);   
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$zone = $_POST['zone'];

$stmt = $mysqli->prepare("SELECT Leaders_Id, Leader_FullName FROM zone_leaders WHERE Zone = ? AND Leader_Delete_Status = 0 LIMIT 1");
$stmt->bind_param("s", $zone);
$stmt->execute();
$result = $stmt->get_result();

$response = ['status' => 'error', 'leader_id' => null, 'leader_name' => null];

if ($row = $result->fetch_assoc()) {
    $response['status'] = 'success';
    $response['leader_id'] = $row['Leaders_Id'];
    $response['leader_name'] = $row['Leader_FullName'];
}

echo json_encode($response);
?>
