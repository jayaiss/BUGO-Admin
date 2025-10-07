<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();

$user_role = strtolower($_SESSION['Role_Name'] ?? '');
if (!in_array($user_role, ['lupon','punong barangay','barangay secretary','admin'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json; charset=UTF-8');

function sanitize_input($s) { return htmlspecialchars(strip_tags(trim((string)$s)), ENT_QUOTES, 'UTF-8'); }

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$limit  = 100;
$page   = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) && (int)$_GET['pagenum'] > 0) ? (int)$_GET['pagenum'] : 1;
$offset = ($page - 1) * $limit;

$searchTerm = '%' . $search . '%';

/** Build full names from the cases table itself */
$complainantExpr = "TRIM(CONCAT_WS(' ', Comp_First_Name, NULLIF(Comp_Middle_Name,''), Comp_Last_Name, NULLIF(Comp_Suffix_Name,'')))";
$respondentExpr  = "TRIM(CONCAT_WS(' ', Resp_First_Name, NULLIF(Resp_Middle_Name,''), Resp_Last_Name, NULLIF(Resp_Suffix_Name,'')))";

/** ---- Count ---- */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM cases
    WHERE $complainantExpr LIKE ?
       OR $respondentExpr  LIKE ?
       OR CAST(case_number AS CHAR) LIKE ?
";
if (!$stmt = $mysqli->prepare($count_sql)) {
    echo json_encode(['error' => 'Prepare failed (count): '.$mysqli->error]); exit;
}
$stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
$stmt->execute();
$res = $stmt->get_result();
$total_rows  = (int)($res->fetch_assoc()['total'] ?? 0);
$total_pages = (int)ceil($total_rows / $limit);
$stmt->close();

/** ---- Page data ---- */
$data_sql = "
    SELECT
        cases.*,
        $respondentExpr  AS respondent_full_name,
        $complainantExpr AS complainant_full_name
    FROM cases
    WHERE $complainantExpr LIKE ?
       OR $respondentExpr  LIKE ?
       OR CAST(case_number AS CHAR) LIKE ?
    ORDER BY date_filed DESC, case_number DESC
    LIMIT ? OFFSET ?
";
if (!$stmt = $mysqli->prepare($data_sql)) {
    echo json_encode(['error' => 'Prepare failed (data): '.$mysqli->error]); exit;
}
$stmt->bind_param('sssii', $searchTerm, $searchTerm, $searchTerm, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

echo json_encode([
    'cases'        => $rows,
    'total_pages'  => $total_pages,
    'current_page' => $page,
    'total_rows'   => $total_rows
]);
