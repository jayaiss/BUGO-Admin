<?php
// api/get_guidelines.php
header('Content-Type: application/json');
// IMPORTANT: Adjust this path if your 'connection.php' is not in 'your_project_root/include/'
include '../include/connection.php'; 

$response = [
    'guidelines' => [],
    'error' => null
];

try {
    // SQL query to get guidelines and join with certificate names
    $guideQuery = "
        SELECT g.Id, g.cert_id, g.guide_description, g.created_at, c.Certificates_Name
        FROM guidelines g
        JOIN certificates c ON g.cert_id = c.Cert_Id
        WHERE g.status = 1
        ORDER BY g.cert_id ASC
    ";
    $guideResult = $mysqli->query($guideQuery);

    if ($guideResult) {
        // Loop through results and add each guideline to the 'guidelines' array
        while ($row = $guideResult->fetch_assoc()) {
            $response['guidelines'][] = $row;
        }
    } else {
        throw new Exception("Failed to fetch guidelines: " . $mysqli->error);
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

$mysqli->close();
?>