<?php
// FILE: bugo/api/check_beso_application.php
session_start(); // Start the session at the very beginning
error_reporting(0); // Turn off all error reporting for production
ini_set('display_errors', 0); // Do not display errors

header('Content-Type: application/json'); // Ensure JSON header is set
header('Access-Control-Allow-Origin: *'); // Allow all origins for development
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include your database connection file
include_once '../include/connection.php'; // Adjust path if necessary

// Check if the request method is GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get the resident ID from the query parameters
    $resident_id = isset($_GET['res_id']) ? intval($_GET['res_id']) : 0;

    // Validate resident ID
    if ($resident_id === 0) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Resident ID not provided or invalid.']);
        exit();
    }

    // Check for existing active BESO application
    // Assuming 'beso_delete_status' = 0 means active
    $sql = "SELECT COUNT(*) FROM beso WHERE res_id = ? AND beso_delete_status = 0";
    $stmt = $mysqli->prepare($sql);

    if ($stmt === false) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $mysqli->error]);
        exit();
    }

    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    $mysqli->close();

    if ($count > 0) {
        echo json_encode(['success' => true, 'has_beso_application' => true, 'message' => 'Existing BESO application found.']);
    } else {
        echo json_encode(['success' => true, 'has_beso_application' => false, 'message' => 'No active BESO application found.']);
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
}
?>
