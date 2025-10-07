<?php
// api/get_events.php
header('Content-Type: application/json');
// IMPORTANT: Adjust this path if your 'connection.php' is not in 'your_project_root/include/'
include '../include/connection.php'; 

$response = [
    'events' => [],
    'error' => null
];

try {
    // SQL query to get events, joining with event_name for the title
    $eventsQuery = "
        SELECT e.id, en.event_name AS event_title, e.event_description, e.event_date,
               e.event_time, e.event_location, e.event_image, e.image_type
        FROM events e
        JOIN event_name en ON e.event_title = en.Id
        WHERE e.events_delete_status = 0
        ORDER BY e.event_date DESC
        LIMIT 10
    "; // Limits to 10 latest events

    $eventsResult = $mysqli->query($eventsQuery);

    if ($eventsResult) {
        // Loop through results
        while ($event = $eventsResult->fetch_assoc()) {
            // If an image exists, convert its binary data to a base64 string
            // This allows the image to be sent as text within JSON and displayed in Flutter.
            if (!empty($event['event_image'])) {
                $event['event_image_base64'] = base64_encode($event['event_image']);
            } else {
                $event['event_image_base64'] = null; // No image, or you can put a default image URL here
            }
            // Remove the original binary image data from the array before encoding to JSON
            unset($event['event_image']); 

            $response['events'][] = $event;
        }
    } else {
        throw new Exception("Failed to fetch events: " . $mysqli->error);
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

$mysqli->close();
?>