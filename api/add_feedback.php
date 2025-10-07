<?php
include('../include/connection.php');
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check for POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

// Get the raw POST data
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Validate the received data
if (!isset($data['resident_id']) || !isset($data['feedback_text'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing resident_id or feedback_text.']);
    exit();
}

$resident_id = intval($data['resident_id']);
$feedback_text = $data['feedback_text'];

// Basic validation
if (empty($feedback_text) || $resident_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
    exit();
}

// Prepare the SQL statement
$stmt = $mysqli->prepare("INSERT INTO feedback (resident_id, feedback_text, created_at, feedback_delete_status) VALUES (?, ?, NOW(), 0)");

if ($stmt) {
    // Bind parameters and execute
    $stmt->bind_param("is", $resident_id, $feedback_text);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Feedback submitted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare failed: ' . $mysqli->error]);
}

$mysqli->close();
?>