<?php
header('Content-Type: application/json');
include '../include/connection.php'; // Corrected path to your connection file

$response = ['has_valid_cedula' => false, 'cedula_status' => null, 'expiration_date' => null];

if (isset($_GET['resident_id'])) {
    $resident_id = $_GET['resident_id'];

    // Updated SQL query:
    // - Checks for cedula_status IN ('Approved', 'Released')
    // - Checks for cedula_delete_status = 0 (not deleted)
    // - Checks if cedula_expiration_date is NULL OR greater than or equal to current date
    $stmt = $mysqli->prepare("SELECT cedula_status, cedula_expiration_date FROM cedula WHERE res_id = ? AND cedula_delete_status = 0 AND cedula_status IN ('Approved', 'Released') ORDER BY issued_on DESC LIMIT 1");
    
    if ($stmt === false) {
        $response['error'] = 'SQL prepare failed: ' . $mysqli->error;
    } else {
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $cedulaStatus = $row['cedula_status'];
            $expirationDate = $row['cedula_expiration_date'];

            // Assume valid unless expired. If expirationDate is NULL, assume never expires or is valid.
            $isExpired = false;
            if ($expirationDate !== null) {
                $currentDate = new DateTime();
                $expiryDateTime = new DateTime($expirationDate);
                if ($expiryDateTime < $currentDate) {
                    $isExpired = true;
                }
            }
            
            if (!$isExpired) {
                $response['has_valid_cedula'] = true;
            }
            
            $response['cedula_status'] = $cedulaStatus;
            $response['expiration_date'] = $expirationDate;
        }
        $stmt->close();
    }
} else {
    $response['error'] = 'resident_id not provided.';
}

echo json_encode($response);
$mysqli->close();
?>