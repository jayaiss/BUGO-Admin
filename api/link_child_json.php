<?php
// bugo/api/link_child_json.php
// JSON version: avoids multipart, good when server blocks file uploads.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// --- config ---
$UPLOAD_DIR = __DIR__ . '/../uploads/birth_certs';
if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0777, true); } // ensure folder exists

require_once __DIR__ . '/../include/connection.php'; // $mysqli

try {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (!is_array($json)) { throw new Exception('Invalid JSON body.'); }

  $parentId = isset($json['parent_id']) ? intval($json['parent_id']) : 0;
  $fullNameExact = trim($json['full_name_exact'] ?? '');
  $relationship = strtolower(trim($json['relationship_type'] ?? 'child'));
  $fileName = trim($json['file_name'] ?? 'birth_cert');       // client-provided filename (optional)
  $fileB64  = $json['birth_certificate_base64'] ?? '';        // base64 payload (optional)

  if ($parentId <= 0 || $fullNameExact === '') {
    echo json_encode(['status'=>'error','message'=>'Missing parent_id or full_name_exact.']);
    exit;
  }
  if ($relationship !== 'child') {
    echo json_encode(['status'=>'error','message'=>'Only relationship_type=child is supported.']);
    exit;
  }

  // 1) find child by exact full name in RESIDENTS (your requirement)
  $sqlChild = "
    SELECT id, first_name, middle_name, last_name, suffix_name
    FROM residents
    WHERE TRIM(CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name,' ',COALESCE(suffix_name,''))) = ?
    LIMIT 1
  ";
  $stmt = $mysqli->prepare($sqlChild);
  if ($stmt === false) throw new Exception('Prepare failed: '.$mysqli->error);

  // normalize spaces in PHP too
  $needle = preg_replace('/\s+/', ' ', $fullNameExact);
  $stmt->bind_param('s', $needle);
  $stmt->execute();
  $res = $stmt->get_result();
  $childRow = $res->fetch_assoc();
  $stmt->close();

  if (!$childRow) {
    echo json_encode(['status'=>'error','message'=>'No resident matched that exact full name.']);
    exit;
  }
  $childId = (int)$childRow['id'];

  // 2) OPTIONAL: save the file if provided
  $storedRelPath = null;
  if (is_string($fileB64) && $fileB64 !== '') {
    $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fileName);
    if ($safeBase === '') $safeBase = 'birth_cert';
    // default to .pdf if we can't detect
    $ext = (str_ends_with(strtolower($safeBase), '.pdf') || str_ends_with(strtolower($safeBase), '.png') ||
            str_ends_with(strtolower($safeBase), '.jpg') || str_ends_with(strtolower($safeBase), '.jpeg') ||
            str_ends_with(strtolower($safeBase), '.webp')) ? '' : '.pdf';

    $final = $safeBase.$ext;
    $abs   = $UPLOAD_DIR . '/' . uniqid('bc_'). '_' . $final;

    // if it has data URL header, strip it:
    if (strpos($fileB64, ',') !== false) { $fileB64 = explode(',', $fileB64, 2)[1]; }
    $bytes = base64_decode($fileB64);
    if ($bytes === false) { throw new Exception('Invalid base64 file payload.'); }

    if (file_put_contents($abs, $bytes) === false) { throw new Exception('Failed to write file.'); }

    // store a relative path that your site can serve if needed
    $storedRelPath = 'uploads/birth_certs/' . basename($abs);
  }

  // 3) record the link somewhere
  // If you already created family_relationships table, use it; else comment this out.
  $createTable = "
    CREATE TABLE IF NOT EXISTS family_relationships (
      id INT AUTO_INCREMENT PRIMARY KEY,
      parent_id INT NOT NULL,
      child_id INT NOT NULL,
      relationship_type VARCHAR(32) NOT NULL,
      birth_certificate_path VARCHAR(255) NULL,
      status VARCHAR(16) DEFAULT 'pending',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  $mysqli->query($createTable);

  $ins = $mysqli->prepare("
    INSERT INTO family_relationships (parent_id, child_id, relationship_type, birth_certificate_path, status)
    VALUES (?, ?, 'child', ?, 'pending')
  ");
  if ($ins === false) throw new Exception('Prepare insert failed: '.$mysqli->error);

  $ins->bind_param('iis', $parentId, $childId, $storedRelPath);
  $ins->execute();
  $ins->close();

  echo json_encode(['status'=>'success','message'=>'Linked child saved (JSON).']);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
} finally {
  if (isset($mysqli) && $mysqli) $mysqli->close();
}
