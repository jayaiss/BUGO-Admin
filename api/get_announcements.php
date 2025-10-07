<?php
// api/get_announcements.php
header('Content-Type: application/json');
// IMPORTANT: Adjust this path if your 'connection.php' is not in 'your_project_root/include/'
include '../include/connection.php'; 

$response = [
    'announcements' => [],
    'error' => null
];

try {
    // SQL query to get all announcements
    $announcementQuery = "SELECT Id, announcement_details, employee_id, created_id FROM announcement ORDER BY created_id DESC";
    $announcementResult = $mysqli->query($announcementQuery);

    if ($announcementResult) {
        // Loop through results and add each announcement to the 'announcements' array
        while ($row = $announcementResult->fetch_assoc()) {
            $response['announcements'][] = $row;
        }
    } else {
        throw new Exception("Failed to fetch announcements: " . $mysqli->error);
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

$mysqli->close();
?>