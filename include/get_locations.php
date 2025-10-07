<?php
// Display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the database connection
include('connection.php');  // Adjust the path if necessary

// Check if province_id is set in the POST request
if (isset($_POST['province_id'])) {
    $province_id = $_POST['province_id']; // Get province_id from POST

    // Query to fetch city/municipality for the given province_id, ordered alphabetically
    $query = "SELECT city_municipality_id, city_municipality_name FROM city_municipality WHERE province_id = ? ORDER BY city_municipality_name ASC";

    if ($stmt = $mysqli->prepare($query)) {
        // Bind the parameter (province_id) to the query
        $stmt->bind_param("i", $province_id);  

        // Execute the statement
        $stmt->execute();

        // Get the result of the query
        $result = $stmt->get_result();

        // Prepare options for city/municipality
        $options = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $options[] = "<option value='" . $row['city_municipality_id'] . "'>" . $row['city_municipality_name'] . "</option>";
            }
            // Send back the response for city/municipality
            echo json_encode(['type' => 'city_municipality', 'options' => $options]);
        } else {
            // Send empty options if no data found
            echo json_encode(['type' => 'city_municipality', 'options' => []]);
        }
    } else {
        // If the query fails to prepare, send an error message
        echo json_encode(['error' => 'Failed to prepare query']);
    }
} 

// Check if municipality_id is set in the POST request
elseif (isset($_POST['municipality_id'])) {
    $municipality_id = $_POST['municipality_id'];  // Get municipality_id from POST

    // Query to fetch barangays for the given municipality_id, ordered alphabetically
    $query = "SELECT barangay_id, barangay_name FROM barangay WHERE municipality_id = ? ORDER BY barangay_name ASC";

    if ($stmt = $mysqli->prepare($query)) {
        // Bind the parameter (municipality_id) to the query
        $stmt->bind_param("i", $municipality_id);  

        // Execute the statement
        $stmt->execute();
        
        // Get the result of the query
        $result = $stmt->get_result();

        // Prepare options for barangay
        $barangay_options = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $barangay_options[] = [
                    'id' => $row['barangay_id'],
                    'name' => $row['barangay_name']
                ];
            }
            // Send back the response for barangay
            echo json_encode(['status' => 'success', 'type' => 'barangay', 'data' => $barangay_options]);
        } else {
            // Send empty options if no data found
            echo json_encode(['status' => 'success', 'type' => 'barangay', 'data' => []]);
        }
    } else {
        // If the query fails to prepare, send an error message
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query']);
    }
} else {
        http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

$mysqli->close();
?>
