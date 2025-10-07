<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
$user_role = strtolower($_SESSION['Role_Name'] ?? '');


if ($user_role !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection(); // Adjust path if needed

$search_term = trim($_GET['q'] ?? '');
$limit = intval($_GET['limit'] ?? 50);
$offset = intval($_GET['offset'] ?? 0);

$search_like = "%$search_term%";

$sql = "SELECT 
            employee_id, 
            employee_fname, 
            employee_mname, 
            employee_lname, 
            employee_birth_date,
            employee_birth_place,
            employee_gender,
            employee_contact_number,
            employee_civil_status,
            employee_email,
            employee_citizenship,
            employee_religion,
            employee_term,
            employee_zone
        FROM employee_list 
        WHERE employee_delete_status = 0 
          AND (
              employee_fname LIKE ? 
              OR employee_mname LIKE ? 
              OR employee_lname LIKE ? 
              OR employee_id LIKE ?
          ) 
        LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ssssii", $search_like, $search_like, $search_like, $search_like, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        include '../components/employee_modal/employee_row.php'; // ✅ render each row
    }
} else {
    echo '<tr><td colspan="5" class="text-center">No matching employees found.</td></tr>';
}
?>
