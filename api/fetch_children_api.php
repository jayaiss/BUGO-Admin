<?php
// FILE: bugo/api/fetch_children_api.php
// Purpose: List linked children for a given parent from resident_relationships.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/../include/connection.php'; // $mysqli

$parentId = $_GET['resident_id'] ?? $_GET['id'] ?? null;
if (empty($parentId) || !ctype_digit((string)$parentId)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Parent Resident ID is missing or invalid.']);
  exit();
}

// If you want to show only approved items to residents, add: AND rr.status='approved'
$sql = "
  SELECT
    c.id AS id,
    c.first_name, c.middle_name, c.last_name, c.suffix_name,
    c.birth_date,
    rr.relationship_type,
    rr.status,                -- pending / approved / rejected
    rr.created_at, rr.updated_at
  FROM resident_relationships rr
  INNER JOIN residents c ON c.id = rr.related_resident_id
  WHERE rr.resident_id = ?
    AND rr.relationship_type = 'child'
  ORDER BY c.first_name ASC, c.last_name ASC
";

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Prepare failed: '.$mysqli->error]);
  exit();
}
$pid = (int)$parentId;
$stmt->bind_param('i', $pid);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
  $fullName = trim($r['first_name'].' '.($r['middle_name'] ?? '').' '.$r['last_name'].' '.($r['suffix_name'] ?? ''));
  $rows[] = [
    'id' => (int)$r['id'],
    'full_name' => preg_replace('/\s+/', ' ', $fullName),
    'first_name' => $r['first_name'],
    'middle_name' => $r['middle_name'],
    'last_name' => $r['last_name'],
    'suffix_name' => $r['suffix_name'],
    'birth_date' => $r['birth_date'],
    'relationship_type' => $r['relationship_type'],
    'status' => $r['status'],           // <-- Flutter can use this
  ];
}
$stmt->close();

echo json_encode([
  'status' => 'success',
  'message' => 'Linked family fetched.',
  'data' => $rows
]);
