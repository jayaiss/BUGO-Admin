<?php
header('Content-Type: application/json');
include '../include/connection.php'; // --- FINAL CORRECTED PATH ---

$response = array('status' => 'error', 'message' => '');

// Allow requests from any origin (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $response['message'] = 'Invalid JSON received.';
    echo json_encode($response);
    exit();
}

$request_id = isset($data['request_id']) ? intval($data['request_id']) : 0;
$request_type = isset($data['request_type']) ? $data['request_type'] : '';

if ($request_id <= 0 || empty($request_type)) {
    $response['message'] = 'Invalid request_id or request_type provided.';
    echo json_encode($response);
    exit();
}

$table = '';
$id_column = '';
$delete_status_column = '';
$type_display_name = ''; // For more descriptive message

switch ($request_type) {
    case 'Appointment':
        $table = 'schedules';
        $id_column = 'id';
        $delete_status_column = 'appointment_delete_status';
        $type_display_name = 'Appointment';
        break;
    case 'Cedula Request':
        $table = 'cedula';
        $id_column = 'Ced_Id';
        $delete_status_column = 'cedula_delete_status';
        $type_display_name = 'Cedula Request';
        break;
    case 'Urgent Request':
        $table = 'urgent_request';
        $id_column = 'urg_id';
        $delete_status_column = 'urgent_delete_status';
        $type_display_name = 'Urgent Request';
        break;
    default:
        $response['message'] = 'Invalid request_type specified.';
        echo json_encode($response);
        exit();
}

// Prepare and execute the update statement for soft delete
$stmt = $mysqli->prepare("UPDATE `$table` SET `$delete_status_column` = 1 WHERE `$id_column` = ?");

if ($stmt) {
    $stmt->bind_param('i', $request_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['status'] = 'success';
            $response['message'] = "$type_display_name with ID $request_id successfully deleted.";
        } else {
            // This might mean the ID was not found or already deleted
            $response['message'] = "$type_display_name with ID $request_id not found or already deleted.";
        }
    } else {
        $response['message'] = 'Database execute error: ' . $stmt->error;
    }
    $stmt->close();
} else {
    $response['message'] = 'Database prepare error: ' . $mysqli->error;
}

$mysqli->close();
echo json_encode($response);
?>