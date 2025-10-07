<?php
// Stream ONLY the Punong Barangay e-signature from barangay_information

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// Always bypass caches so edits show immediately
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function out_blank() {
    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9t8H4nQAAAAASUVORK5CYII=');
    header('Content-Type: image/png');
    echo $png;
    exit;
}

try {
    // Directly query barangay_information by position
    $sql = "
        SELECT esignature, esignature_mime
        FROM barangay_information
        WHERE TRIM(LOWER(position)) = 'lupon'
          AND esignature IS NOT NULL
          AND status = 'active'
        ORDER BY date_created DESC
        LIMIT 1
    ";
    $res = $mysqli->query($sql);

    if (!$res) {
        error_log('show_esignature query error: ' . $mysqli->errno . ' ' . $mysqli->error);
        out_blank();
    }

    $row = $res->fetch_assoc();
    if (!$row || empty($row['esignature'])) {
        out_blank();
    }

    $mime = $row['esignature_mime'] ?: 'image/png';
    header('Content-Type: ' . $mime);
    echo $row['esignature'];  // raw LONGBLOB bytes
    exit;

} catch (Throwable $e) {
    error_log('show_esignature exception: ' . $e->getMessage());
    out_blank();
}
