<?php
// FILE: bugo/api/save_relationship_existing.php
// Purpose: Insert/Upsert a "child" relationship into resident_relationships
//          so your web admin can see it. Uses BLOB id_birthcertificate.

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$DBG = isset($_GET['dbg']) ? 1 : 0;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/../include/connection.php'; // $mysqli

function out($ok, $msg, $extraSteps = []) {
  $resp = ['status' => $ok ? 'success' : 'error', 'message' => $msg];
  if (!empty($extraSteps)) $resp['steps'] = $extraSteps;
  echo json_encode($resp);
  exit();
}

$steps = [];
$steps[] = 'DB connected';

// Accept both POST body and GET (debug)
$parentId       = isset($_POST['parent_id'])      ? intval($_POST['parent_id'])      : (isset($_GET['parent_id']) ? intval($_GET['parent_id']) : 0);
$fullNameExact  = isset($_POST['full_name_exact'])? trim($_POST['full_name_exact'])  : (isset($_GET['full_name_exact']) ? trim($_GET['full_name_exact']) : '');
$relationship   = isset($_POST['relationship_type']) ? strtolower(trim($_POST['relationship_type'])) : 'child';

$steps[] = "Inputs: parent_id=$parentId, full_name_exact='$fullNameExact', relationship_type='$relationship', method=".$_SERVER['REQUEST_METHOD'];

if ($parentId <= 0 || $fullNameExact === '') {
  http_response_code(400);
  out(false, 'Missing parent_id or full_name_exact.', $steps);
}

if ($relationship !== 'child') {
  http_response_code(400);
  out(false, 'Only relationship_type=child is allowed.', $steps);
}

// --- Lookup parent in residents
$sqlParent = "SELECT id, first_name, middle_name, last_name, suffix_name
              FROM residents WHERE id = ? LIMIT 1";
$stmt = $mysqli->prepare($sqlParent);
if (!$stmt) out(false, 'Prepare failed (parent): '.$mysqli->error, $steps);
$stmt->bind_param('i', $parentId);
$stmt->execute();
$res = $stmt->get_result();
$parent = $res->fetch_assoc();
$stmt->close();

if (!$parent) {
  http_response_code(404);
  out(false, 'Parent resident not found.', $steps);
}
$steps[] = "Parent OK: ".trim($parent['first_name'].' '.$parent['last_name'])." (#{$parent['id']})";

// --- Lookup child by exact full name in residents
$sqlFind = "
  SELECT id, first_name, middle_name, last_name, suffix_name
  FROM residents
  WHERE TRIM(REPLACE(CONCAT_WS(' ',
    first_name,
    NULLIF(middle_name,''),
    last_name,
    NULLIF(suffix_name,'')
  ), '  ', ' ')) = ?
  LIMIT 1
";
$stmt = $mysqli->prepare($sqlFind);
if (!$stmt) out(false, 'Prepare failed (child): '.$mysqli->error, $steps);
$stmt->bind_param('s', $fullNameExact);
$stmt->execute();
$res = $stmt->get_result();
$child = $res->fetch_assoc();
$stmt->close();

if (!$child) {
  http_response_code(404);
  out(false, 'No resident matches that full name exactly.', $steps);
}
$childId = (int)$child['id'];
$steps[] = "Child OK: ".trim($child['first_name'].' '.$child['last_name'])." (#$childId)";

// Prevent self-link
if ($childId === (int)$parent['id']) {
  http_response_code(400);
  out(false, 'Cannot link a resident to themselves.', $steps);
}

// --- Read optional birth certificate (we'll store as BLOB)
$blob = null;
if (!empty($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
  $tmp  = $_FILES['birth_certificate']['tmp_name'];
  $size = $_FILES['birth_certificate']['size'];

  // 2MB limit (same as app)
  if ($size > 2 * 1024 * 1024) {
    http_response_code(400);
    out(false, 'File too large (max 2MB).', $steps);
  }
  $blob = file_get_contents($tmp);
} else {
  if ($DBG) $steps[] = 'No file uploaded (OK in debug/GET).';
}

// --- Upsert into resident_relationships
// Schema reference (important columns): resident_id, related_resident_id, relationship_type,
// created_by, status (pending/approved/rejected/''), id_birthcertificate (longblob).
// Unique key: (resident_id, related_resident_id, relationship_type)
// We'll set created_by = parentId, and status='pending' for admin to approve.
$sql = "
  INSERT INTO resident_relationships
    (resident_id, related_resident_id, relationship_type, created_by, status, id_birthcertificate)
  VALUES
    (?, ?, 'child', ?, 'pending', ?)
  ON DUPLICATE KEY UPDATE
    status = 'pending',
    updated_at = NOW(),
    id_birthcertificate = IFNULL(VALUES(id_birthcertificate), id_birthcertificate)
";
$stmt = $mysqli->prepare($sql);
if (!$stmt) out(false, 'Prepare failed (insert): '.$mysqli->error, $steps);

// Bind: i i i b
// For BLOB with mysqli, bind_param needs 'b' and then send_long_data.
$nullBlob = null;
$stmt->bind_param('iiib', $parentId, $childId, $parentId, $nullBlob);
if ($blob !== null) {
  // parameter index is 4th (0-based => 3)
  $stmt->send_long_data(3, $blob);
}

if (!$stmt->execute()) {
  http_response_code(500);
  out(false, 'Execute failed: '.$stmt->error, $steps);
}
$stmt->close();

out(true, 'Linked successfully (pending).', $steps);
