<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}  // Still report them in logs
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// Check if POST data exists
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }
    // Retrieve form data from the JavaScript fetch
    $event_title = sanitizeInput($_POST['event_title']);
    $event_description = sanitizeInput($_POST['event_description']);
    $event_location = sanitizeInput($_POST['event_location']);
    $event_time = sanitizeInput($_POST['event_time']);
    $event_date = sanitizeInput($_POST['event_date']);  // This will be in MM/DD/YYYY format

    // Check if all required fields are provided
    if (empty($event_title) || empty($event_description) || empty($event_location) || empty($event_time) || empty($event_date)) {
        echo json_encode(["success" => false, "message" => "All fields are required"]);
        exit; // Stop execution if any field is missing
    }

    // Prepare the SQL query to insert data into the database
    $stmt = $mysqli->prepare("INSERT INTO events (event_title, event_description, event_location, event_time, event_date) 
                            VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $event_title, $event_description, $event_location, $event_time, $event_date);

    // Execute the statement and check if the insertion was successful
    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
    }

    // Close the statement and connection
    $stmt->close();
    $mysqli->close();
}
?>
