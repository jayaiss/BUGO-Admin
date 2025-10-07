<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// Get cert name
$certName = isset($_GET['cert']) ? trim($_GET['cert']) : '';

if (empty($certName)) {
    http_response_code(400);
     header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/400.html';
    exit;
}

// Prepare query
$stmt = $mysqli->prepare("
    SELECT purpose_name 
    FROM purposes 
    WHERE cert_id = (
        SELECT Cert_Id 
        FROM certificates 
        WHERE Certificates_Name = ? 
        LIMIT 1
    ) 
    AND status = 'active'
");
$stmt->bind_param("s", $certName);
$stmt->execute();
$result = $stmt->get_result();

// Collect results
$purposes = [];
while ($row = $result->fetch_assoc()) {
    $purposes[] = $row;
}
$stmt->close();

// Output JSON
header('Content-Type: application/json');
echo json_encode($purposes);
?>
