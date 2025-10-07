<?php
session_start();
$user_role = strtolower($_SESSION['Role_Name'] ?? '');


if ($user_role !== 'Lupon' && $user_role !== 'punong barangay' && $user_role !== "barangay secretary" && $user_role !== "revenue staff" && $user_role !== "admin") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection(); // adjust path if needed

$q = trim($_GET['q'] ?? '');
$qLike = "%$q%";

$sql = "
    SELECT 
        id,
        first_name,
        middle_name,
        last_name,
        suffix_name,
        gender,
        res_zone,
        contact_number,
        email,
        civil_status,
        birth_date, 
        residency_start,
        age,
        birth_place,
        res_street_address,
        citizenship,
        religion,
        occupation
    FROM residents
    WHERE 
        CONCAT(first_name, ' ', middle_name, ' ', last_name) LIKE ?
        OR first_name LIKE ?
        OR middle_name LIKE ?
        OR last_name LIKE ?
        OR res_zone LIKE ?
    LIMIT 50
";


$stmt = $mysqli->prepare($sql);
$stmt->bind_param("sssss", $qLike, $qLike, $qLike, $qLike, $qLike);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        include '../components/resident_modal/resident_row.php'; // ✅ use ../ if you're in class or api
    }
} else {
    echo '<tr><td colspan="5" class="text-center">No matching residents found.</td></tr>';
}

?>
