<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$child_id = $_GET['related_resident_id'] ?? null;
$parent_id = $_GET['resident_id'] ?? null;
$relationship_type = $_GET['relationship_type'] ?? null;

if (!$child_id || !$parent_id || !$relationship_type) {
    http_response_code(400);
    require_once __DIR__ . '/../security/400.html';
    exit;
}

$stmt = $mysqli->prepare("
    SELECT id_birthcertificate 
    FROM resident_relationships 
    WHERE related_resident_id = ? AND resident_id = ? AND relationship_type = ?
");
$stmt->bind_param("iis", $child_id, $parent_id, $relationship_type);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(404);
    echo "Birth certificate not found.";
    exit;
}

$stmt->bind_result($birth_certificate);
$stmt->fetch();

if (!$birth_certificate) {
    http_response_code(404);
    echo "No birth certificate uploaded.";
    exit;
}

// Detect MIME type (PDF or image)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->buffer($birth_certificate);

// Set appropriate headers
header("Content-Type: $mimeType");
header("Content-Disposition: inline; filename=birth_certificate." . ($mimeType === 'application/pdf' ? 'pdf' : 'jpg'));
echo $birth_certificate;
