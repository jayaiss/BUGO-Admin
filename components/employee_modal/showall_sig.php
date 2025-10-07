<?php
// streams barangay_information.esignature by row id
declare(strict_types=1);

// DO NOT block direct access here – this script is meant to be called by <img src=...>

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad request';
    exit;
}

$sql = "SELECT esignature, esignature_mime FROM barangay_information WHERE id=?";
$st  = $mysqli->prepare($sql);
$st->bind_param('i', $id);
$st->execute();
$res = $st->get_result();

if ($row = $res->fetch_assoc()) {
    $blob = $row['esignature'] ?? null;
    $mime = $row['esignature_mime'] ?: 'image/png';

    if (!empty($blob)) {
        // Important: clean buffers, no BOM/whitespace before output
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($blob));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $blob;
        exit;
    }
}

// Not found / empty
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'No signature';
