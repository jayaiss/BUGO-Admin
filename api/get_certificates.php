<?php
header('Content-Type: application/json');
include '../include/connection.php'; // Ensure this path is correct for your setup

$certificates = [];

// Fetch certificates from the database using the correct column name 'Certificates_Name'
$stmt = $mysqli->prepare("SELECT Cert_Id, Certificates_Name FROM certificates ORDER BY Certificates_Name ASC");
if ($stmt === false) {
    echo json_encode(['error' => 'SQL prepare failed: ' . $mysqli->error]);
    $mysqli->close();
    exit();
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $certId = $row['Cert_Id'];
    $certName = $row['Certificates_Name'];

    // *** NEW LOGIC: All certificates require Cedula, EXCEPT 'Cedula' itself ***
    $requiresCedula = true; // Default to true for all certificates
    if (strtolower($certName) == 'cedula') {
        $requiresCedula = false; // Applying for Cedula does not require a Cedula
    }

    $certificates[] = [
        'cert_id' => $certId,
        'cert_name' => $certName,
        'requires_cedula' => $requiresCedula, // Include the requires_cedula field
    ];
}

$stmt->close();
$mysqli->close();

echo json_encode($certificates);
?>