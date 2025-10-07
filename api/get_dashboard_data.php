<?php
// api/get_dashboard_data.php
// This line tells the browser/app that the content is JSON, not HTML.
header('Content-Type: application/json');

// IMPORTANT: Adjust this path if your 'connection.php' is not in 'your_project_root/include/'
// If your 'api' folder and 'include' folder are in the same parent directory:
include '../include/connection.php'; 

// Prepare a structure to hold our data or any errors
$response = [
    'resident_count' => 0,
    'error' => null
];

try {
    // SQL query to count residents
    $resQuery = "SELECT COUNT(*) AS total FROM residents";
    $resResult = $mysqli->query($resQuery);

    if ($resResult) {
        // Fetch the count
        $resCount = $resResult->fetch_assoc()['total'];
        // Store it as an integer
        $response['resident_count'] = (int)$resCount;
    } else {
        // If query fails, throw an error
        throw new Exception("Failed to fetch resident count: " . $mysqli->error);
    }

} catch (Exception $e) {
    // Catch any errors and add them to the response
    $response['error'] = $e->getMessage();
    // Send a 500 status code for internal server errors
    http_response_code(500); 
}

// Convert the PHP array to a JSON string and print it
echo json_encode($response);

// Close the database connection
$mysqli->close();
?>