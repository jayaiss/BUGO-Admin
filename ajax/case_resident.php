<?php
// ajax/resident_search.php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// (Optional) auth/role check here

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) { echo json_encode([]); exit; }

// Basic split for smarter WHERE
$parts = preg_split('/\s+/', $q);
$like  = '%' . $q . '%';

$sql = "
  SELECT
    id,
    first_name,
    middle_name,
    last_name,
    suffix_name,
    res_street_address,
    res_barangay
  FROM residents
  WHERE resident_delete_status = 0
    AND (
      CONCAT_WS(' ', first_name, middle_name, last_name, suffix_name) LIKE ?
      OR first_name LIKE ?
      OR last_name  LIKE ?
    )
  ORDER BY last_name, first_name
  LIMIT 15
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) { echo json_encode([]); exit; }
$stmt->bind_param('sss', $like, $like, $like);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    'id' => (int)$row['id'],
    'first_name'  => $row['first_name'] ?? '',
    'middle_name' => $row['middle_name'] ?? '',
    'last_name'   => $row['last_name'] ?? '',
    'suffix_name' => $row['suffix_name'] ?? '',
    'res_street_address' => $row['res_street_address'] ?? '',
    'res_barangay'       => $row['res_barangay'] ?? '',
  ];
}
$stmt->close();
echo json_encode($out);
