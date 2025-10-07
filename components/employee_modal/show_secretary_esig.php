<?php
// components/employee_modal/show_secretary_esig.php (Secretary only; requires ?employee_id=##)

ini_set('display_errors', 0); ini_set('log_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/../../include/connection.php'; $mysqli = db_connection();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');

function out_blank(){ $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9t8H4nQAAAAASUVORK5CYII='); header('Content-Type: image/png'); echo $png; exit; }

try {
    $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
    if ($employeeId <= 0) out_blank();

    $sql = "
        SELECT el.esignature, COALESCE(NULLIF(TRIM(el.esignature_mime),''),'image/png') AS mime
        FROM employee_list el
        JOIN employee_roles er ON er.Role_Id = el.Role_Id
        WHERE el.employee_id = ?
          AND TRIM(LOWER(er.Role_Name)) IN ('barangay secretary','secretary')
          AND el.esignature IS NOT NULL
        LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc(); $stmt->close();

    if (!$row || empty($row['esignature'])) out_blank();
    header('Content-Type: '.$row['mime']); echo $row['esignature']; exit;

} catch (Throwable $e) { error_log('show_secretary_esig exception: '.$e->getMessage()); out_blank(); }
