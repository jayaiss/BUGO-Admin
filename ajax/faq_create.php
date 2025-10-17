<?php
// ajax/faq_create.php  — JSON-only multi-action endpoint
declare(strict_types=1);

// never let stray HTML leak
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error','detail'=>$msg]); exit;
});
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server exception','detail'=>$e->getMessage()]); exit;
});

define('AJAX_MODE', true); // lets session_timeout emit JSON on expiry

require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../class/session_timeout.php';
// session_start();

if (empty($_SESSION['employee_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$employeeId = (int)$_SESSION['employee_id'];
$mysqli     = db_connection();

$action = strtolower((string)($_GET['action'] ?? $_POST['action'] ?? 'create'));

// helpers
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$badge = fn(string $s) => $s === 'Active'
  ? '<span class="badge bg-success">Active</span>'
  : '<span class="badge bg-secondary">Inactive</span>';

$rowHtml = function(array $r) use ($h,$badge): string {
  $id      = (int)$r['faq_id'];
  $q       = $h(mb_strimwidth((string)$r['faq_question'], 0, 120, '…'));
  $a       = $h(mb_strimwidth(strip_tags((string)$r['faq_answer']), 0, 140, '…'));
  $statusH = $badge((string)$r['faq_status']);
  $created = $h(date('Y-m-d H:i', strtotime((string)($r['created_at'] ?? 'now'))));
  return <<<HTML
<tr id="faq-row-{$id}">
  <td class="faq-q">{$q}</td>
  <td class="faq-a">{$a}</td>
  <td class="faq-status">{$statusH}</td>
  <td class="faq-created">{$created}</td>
  <td class="text-center">
    <button type="button" class="btn btn-light btn-sm me-1 action-view-edit" title="View / Edit" data-id="{$id}">
      <i class="bi bi-pencil-square"></i>
    </button>
    <button type="button" class="btn btn-light btn-sm action-archive" title="Archive" data-id="{$id}">
      <i class="bi bi-archive"></i>
    </button>
  </td>
</tr>
HTML;
};

$requireCsrf = function(): void {
  $csrf = $_POST['csrf_token'] ?? '';
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
    http_response_code(419);
    echo json_encode(['success'=>false,'message'=>'CSRF token mismatch']); exit;
  }
};

switch ($action) {
  case 'get': // GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); exit; }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

    $stmt = $mysqli->prepare("SELECT faq_id, faq_question, faq_answer, faq_status, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at FROM faqs WHERE faq_id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $faq = $res->fetch_assoc();
    $stmt->close();

    if (!$faq) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    echo json_encode(['success'=>true,'faq'=>$faq]); exit;

  case 'update': // POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); exit; }
    $requireCsrf();

    $faq_id = (int)($_POST['faq_id'] ?? 0);
    $q      = trim((string)($_POST['faq_question'] ?? ''));
    $a      = trim((string)($_POST['faq_answer'] ?? ''));
    if ($faq_id <= 0 || $q === '' || $a === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Incomplete data']); exit; }
    if (mb_strlen($q) > 1000 || mb_strlen($a) > 10000) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Input too long']); exit; }

    $stmt = $mysqli->prepare("UPDATE faqs SET faq_question=?, faq_answer=? WHERE faq_id=?");
    $stmt->bind_param('ssi', $q, $a, $faq_id);
    if (!$stmt->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Update failed']); exit; }
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT faq_id, faq_question, faq_answer, faq_status, created_at FROM faqs WHERE faq_id=? LIMIT 1");
    $stmt->bind_param('i', $faq_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    echo json_encode([
      'success'=>true,
      'message'=>'FAQ updated successfully.',
      'faq'=>[
        'faq_id'=>$faq_id,
        'faq_question'=>$q,
        'faq_answer'=>$a,
        'faq_status'=>$row['faq_status'] ?? 'Active',
        'created_at'=>date('Y-m-d H:i', strtotime((string)($row['created_at'] ?? 'now')))
      ]
    ]); exit;

  case 'archive': // POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); exit; }
    $requireCsrf();

    $faq_id = (int)($_POST['faq_id'] ?? 0);
    if ($faq_id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

    $stmt = $mysqli->prepare("UPDATE faqs SET faq_status='Inactive' WHERE faq_id=?");
    $stmt->bind_param('i', $faq_id);
    if (!$stmt->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Archive failed']); exit; }
    $stmt->close();

    echo json_encode(['success'=>true,'message'=>'FAQ archived.']); exit;

  case 'create': default: // POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); exit; }
    $requireCsrf();

    $question = trim((string)($_POST['faq_question'] ?? ''));
    $answer   = trim((string)($_POST['faq_answer'] ?? ''));
    $status   = trim((string)($_POST['faq_status'] ?? 'Active'));
    if ($question === '' || $answer === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Question and answer are required.']); exit; }
    if (!in_array($status, ['Active','Inactive'], true)) $status = 'Active';
    if (mb_strlen($question) > 1000) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Question too long.']); exit; }
    if (mb_strlen($answer) > 10000) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Answer too long.']); exit; }

    $stmt = $mysqli->prepare("INSERT INTO faqs (faq_question, faq_answer, faq_status, employee_id) VALUES (?,?,?,?)");
    $stmt->bind_param('sssi', $question, $answer, $status, $employeeId);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT faq_id, faq_question, faq_answer, faq_status, created_at FROM faqs WHERE faq_id=? LIMIT 1");
    $stmt->bind_param('i', $newId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    echo json_encode([
      'success'=>true,
      'message'=>'FAQ created successfully.',
      'faq_id'=>$newId,
      'row_html'=>$rowHtml($row),
      'faq'=>[
        'faq_id'=>$row['faq_id'],
        'faq_question'=>$row['faq_question'],
        'faq_answer'=>$row['faq_answer'],
        'faq_status'=>$row['faq_status'],
        'created_at'=>date('Y-m-d H:i', strtotime((string)($row['created_at'] ?? 'now')))
      ]
    ]); exit;
}
