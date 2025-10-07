<?php
// FILE: bugo/api/check_barangay_residency_jobseeker.php
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

    // Check for existing Barangay Residency with 'First Time Jobseeker' purpose
    // We need to check both 'schedules' and 'urgent_request' tables
    // Assuming 'appointment_delete_status' = 0 and 'status' = 'Released' for active/valid
    $hasResidency = false;

    // Check 'schedules' table
    $sql_schedules = "SELECT COUNT(*) FROM schedules 
                      WHERE res_id = ? 
                        AND certificate = 'Barangay Residency' 
                        AND purpose = 'First Time Jobseeker' 
                        AND status = 'Released' 
                        AND appointment_delete_status = 0";
    $stmt_schedules = $mysqli->prepare($sql_schedules);
    if ($stmt_schedules === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database prepare failed (schedules): ' . $mysqli->error]);
        exit();
    }
    $stmt_schedules->bind_param("i", $resident_id);
    $stmt_schedules->execute();
    $stmt_schedules->bind_result($count_schedules);
    $stmt_schedules->fetch();
    $stmt_schedules->close();

    if ($count_schedules > 0) {
        $hasResidency = true;
    }

    // If not found in schedules, check 'urgent_request' table
    if (!$hasResidency) {
        $sql_urgent = "SELECT COUNT(*) FROM urgent_request 
                       WHERE res_id = ? 
                         AND certificate = 'Barangay Residency' 
                         AND purpose = 'First Time Jobseeker' 
                         AND status = 'Released' 
                         AND urgent_delete_status = 0";
        $stmt_urgent = $mysqli->prepare($sql_urgent);
        if ($stmt_urgent === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database prepare failed (urgent_request): ' . $mysqli->error]);
            exit();
        }
        $stmt_urgent->bind_param("i", $resident_id);
        $stmt_urgent->execute();
        $stmt_urgent->bind_result($count_urgent);
        $stmt_urgent->fetch();
        $stmt_urgent->close();

        if ($count_urgent > 0) {
            $hasResidency = true;
        }
    }

    $mysqli->close();

    if ($hasResidency) {
        echo json_encode(['success' => true, 'has_residency_for_jobseeker' => true, 'message' => 'Existing Barangay Residency for First Time Jobseeker found.']);
    } else {
        echo json_encode(['success' => true, 'has_residency_for_jobseeker' => false, 'message' => 'No active Barangay Residency for First Time Jobseeker found.']);
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
}
?>
