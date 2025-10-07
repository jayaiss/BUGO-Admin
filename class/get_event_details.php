<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// Get the event date from the request
if (isset($_GET['event_date'])) {
    $event_date = $_GET['event_date'];  // Format: MM/DD/YYYY
    
    // Query to fetch event details based on the event date
    $stmt = $mysqli->prepare("SELECT event_title, event_description, event_time, event_location FROM events WHERE event_date = ?");
    $stmt->bind_param("s", $event_date);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Bind the results to variables
        $stmt->bind_result($event_title, $event_description, $event_time, $event_location);
        $stmt->fetch();
        
        // Return event details as JSON
        echo json_encode([
            "event_title" => $event_title,
            "event_description" => $event_description,
            "event_time" => $event_time,
            "event_location" => $event_location
        ]);
    } else {
        echo json_encode(["message" => "No event found for this date."]);
    }

    $stmt->close();
}

$mysqli->close();
?>
