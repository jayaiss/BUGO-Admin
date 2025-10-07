<?php
// FILE: bugo/api/get_purposes_by_cert_id.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

include '../include/connection.php'; // Your database connection file

$certId = $_GET['cert_id'] ?? null;

if (empty($certId) || !is_numeric($certId)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Certificate ID is missing or invalid.']);
    exit;
}

$response = [];

try {
    $sql = "SELECT purpose_id, purpose_name FROM purposes WHERE cert_id = ? AND status = 'active' ORDER BY purpose_name ASC";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $mysqli->error);
    }
    
    $stmt->bind_param("i", $certId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                'purpose_id' => $row['purpose_id'],
                'purpose_name' => $row['purpose_name']
            ];
        }
    }
    
    // Add an "Others" option to the list so users can specify a custom purpose
    $response[] = [
        'purpose_id' => 0, // Use 0 or another special ID for "Others"
        'purpose_name' => 'Others'
    ];

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
} finally {
    if ($mysqli) {
        $mysqli->close();
    }
}

echo json_encode($response);
?>