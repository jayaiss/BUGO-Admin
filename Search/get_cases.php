<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

session_start();

// --- Role gate (normalized) ---
$allowed = ['lupon', 'punong barangay', 'barangay secretary', 'revenue staff'];
$user_role = strtolower($_SESSION['Role_Name'] ?? '');

if (!in_array($user_role, $allowed, true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    // NOTE: fixed the missing slash here:
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

// --- Input ---
$res_id = isset($_GET['res_id']) ? (int)$_GET['res_id'] : 0;
if ($res_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<strong>No case history found.</strong>";
    exit;
}

// --- Query ---
// backticks for table/columns; CASES can be awkward in some environments
$sql = "SELECT `case_number`, `nature_offense`, `date_filed`, `action_taken`
        FROM `cases`
        WHERE `id` = ?
        ORDER BY `date_filed` DESC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "<strong>Error loading case history.</strong>";
    exit;
}
$stmt->bind_param("i", $res_id);
$stmt->execute();
$result = $stmt->get_result();

// --- Render small HTML snippet (the modal expects HTML) ---
header('Content-Type: text/html; charset=UTF-8');

if ($result && $result->num_rows > 0) {
    echo "<ul class='mb-0'>";
    while ($row = $result->fetch_assoc()) {
        $case_no  = htmlspecialchars($row['case_number'] ?? '');
        $nature   = htmlspecialchars($row['nature_offense'] ?? '');
        $filed    = htmlspecialchars($row['date_filed'] ?? '');
        $action   = htmlspecialchars($row['action_taken'] ?? '');
        echo "<li><strong>Case #{$case_no}</strong>: {$nature} ({$filed}) - <em>{$action}</em></li>";
    }
    echo "</ul>";
} else {
    echo "<strong>No case history found.</strong>";
}
