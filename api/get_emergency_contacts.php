<?php
// api/get_emergency_contacts.php
header('Content-Type: application/json');
// IMPORTANT: Adjust this path if your 'connection.php' is not in 'your_project_root/include/'
include '../include/connection.php'; 

$response = [
    'contacts' => [],
    'error' => null
];

try {
    // SQL query to get emergency contacts
    $contactsQuery = "SELECT Id, contact_name, contact_number FROM emergency_contact ORDER BY contact_name ASC";
    $contactsResult = $mysqli->query($contactsQuery);

    if ($contactsResult) {
        // Loop through results and add each contact to the 'contacts' array
        while ($row = $contactsResult->fetch_assoc()) {
            $response['contacts'][] = $row;
        }
    } else {
        throw new Exception("Failed to fetch emergency contacts: " . $mysqli->error);
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

$mysqli->close();
?>