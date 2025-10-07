<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST'); // Only POST requests are expected
header('Access-Control-Allow-Headers: Content-Type');

// Include the database connection file
include_once '../include/connection.php'; // Adjust path as per your setup

// Check if connection was successful
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $mysqli->connect_error]);
    exit();
}

// Get the raw POST data
$data = json_decode(file_get_contents("php://input"), true);

$requestId = isset($data['request_id']) ? intval($data['request_id']) : null;
$requestType = isset($data['request_type']) ? $data['request_type'] : null;

// Validate input
if (is_null($requestId) || $requestId <= 0 || is_null($requestType) || empty($requestType)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request_id or request_type provided."]);
    $mysqli->close();
    exit();
}

$tableName = "";
$idColumn = "";

// Determine table and ID column based on request_type
switch ($requestType) {
    case 'Appointment':
        $tableName = "schedules";
        $idColumn = "id";
        break;
    case 'Cedula Request':
        $tableName = "cedula";
        $idColumn = "Ced_Id"; // From your cedula.sql
        break;
    case 'Urgent Request':
        $tableName = "urgent_request";
        $idColumn = "urg_id"; // From your urgent_request.sql
        break;
    default:
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid request_type specified."]);
        $mysqli->close();
        exit();
}

// Prepare the update statement
$stmt = $mysqli->prepare("UPDATE `$tableName` SET is_read = 1 WHERE `$idColumn` = ?");

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "SQL Prepare Error: " . $mysqli->error]);
    $mysqli->close();
    exit();
}

// Bind parameter and execute
$stmt->bind_param("i", $requestId); // 'i' for integer

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "$requestType with ID $requestId marked as read."]);
    } else {
        http_response_code(404); // Not found or already read
        echo json_encode(["status" => "error", "message" => "$requestType with ID $requestId not found or already marked as read."]);
    }
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to mark $requestType as read: " . $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>