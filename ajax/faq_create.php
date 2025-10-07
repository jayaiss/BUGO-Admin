<?php
// ajax/faq_create.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
        exit;
    }

    require_once __DIR__ . '/../include/connection.php';
    require_once __DIR__ . '/../class/session_timeout.php';
    session_start();

    if (empty($_SESSION['employee_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $employeeId = (int) $_SESSION['employee_id'];

    // CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'CSRF token mismatch']);
        exit;
    }

    // Input
    $question = trim((string)($_POST['faq_question'] ?? ''));
    $answer   = trim((string)($_POST['faq_answer'] ?? ''));
    $status   = trim((string)($_POST['faq_status'] ?? 'Active'));

    if ($question === '' || $answer === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Question and answer are required.']);
        exit;
    }
    if (!in_array($status, ['Active','Inactive'], true)) $status = 'Active';

    if (mb_strlen($question) > 1000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Question too long.']);
        exit;
    }
    if (mb_strlen($answer) > 10000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Answer too long.']);
        exit;
    }

    $mysqli = db_connection();
    $sql = "INSERT INTO faqs (faq_question, faq_answer, faq_status, employee_id)
            VALUES (?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sssi', $question, $answer, $status, $employeeId);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    // Fetch for display (join username + created_at)
    $q = $mysqli->prepare("
        SELECT f.faq_question, f.faq_answer, f.faq_status, f.created_at
        FROM faqs f
        LEFT JOIN employee_list e ON e.employee_id = f.employee_id
        WHERE f.faq_id = ?
        LIMIT 1
    ");
    $q->bind_param('i', $newId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    // Safely build row HTML to prepend
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $statusBadge = ($row['faq_status'] === 'Active')
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';

    $rowHtml = '
<tr id="faq-row-'.(int)$row['faq_id'].'">
  <td>'.$h(mb_strimwidth($row['faq_question'], 0, 120, '…')).'</td>
  <td>'.$h(mb_strimwidth(strip_tags($row['faq_answer']), 0, 140, '…')).'</td>
  <td>'.$statusBadge.'</td>
  <td>'.$h(date('Y-m-d H:i', strtotime($row['created_at']))).'</td>
</tr>';

    echo json_encode([
        'success'  => true,
        'message'  => 'FAQ created successfully.',
        'faq_id'   => $newId,
        'row_html' => $rowHtml
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
