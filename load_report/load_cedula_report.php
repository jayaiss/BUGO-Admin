<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include '../class/session_timeout.php';

$role = $_SESSION['Role_Name'] ?? '';

if ($role !== 'Admin' && $role !== "Barangay Secretary" && $role !== "Punong Barangay" && $role !== "Revenue Staff") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

$zone = isset($_GET['address']) ? $_GET['address'] : ''; // Still using 'address' param name
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

$limit = 10;
$offset = ($page - 1) * $limit;

// Build dynamic WHERE clause
$where = [];
$params = [];
$types = '';

if (!empty($zone)) {
    $where[] = "residents.res_zone = ?";
    $params[] = $zone;
    $types .= 's';
}

$whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Count total
$countQuery = "
    SELECT COUNT(*) AS total FROM (
        SELECT cedula.res_id
        FROM cedula
        JOIN residents ON cedula.res_id = residents.id
        " . ($zone ? "WHERE residents.res_zone = ?" : "") . "
        UNION ALL
        SELECT urgent_cedula_request.res_id
        FROM urgent_cedula_request
        JOIN residents ON urgent_cedula_request.res_id = residents.id
        " . ($zone ? "WHERE residents.res_zone = ?" : "") . "
    ) AS combined
";
$countStmt = $mysqli->prepare($countQuery);
if (!empty($zone)) {
    $countStmt->bind_param("ss", $zone, $zone);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRow = $countResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $limit);

// Fetch filtered data
$query = "
    SELECT CONCAT(residents.first_name, ' ', residents.middle_name, ' ', residents.last_name, ' ', residents.suffix_name) AS full_name,
           residents.res_zone AS address,
           cedula.issued_on
    FROM cedula
    JOIN residents ON cedula.res_id = residents.id
    " . ($zone ? "WHERE residents.res_zone = ?" : "") . "

    UNION ALL

    SELECT CONCAT(residents.first_name, ' ', residents.middle_name, ' ', residents.last_name, ' ', residents.suffix_name) AS full_name,
           residents.res_zone AS address,
           urgent_cedula_request.issued_on
    FROM urgent_cedula_request
    JOIN residents ON urgent_cedula_request.res_id = residents.id
    " . ($zone ? "WHERE residents.res_zone = ?" : "") . "

    ORDER BY full_name ASC
    LIMIT ? OFFSET ?
";

$stmt = $mysqli->prepare($query);
if (!empty($zone)) {
    $stmt->bind_param("ssii", $zone, $zone, $limit, $offset);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

// Output
$zoneLabel = $zone ? " | Zone: $zone" : "";
echo "<h3 class='text-primary mb-3'>Cedula Report$zoneLabel</h3>";
echo "<div class='table-responsive'>";
echo "<table class='table table-bordered table-hover'>";
echo "<thead class='table-primary'><tr><th>Name</th><th>Zone</th><th>Issued Date</th></tr></thead>";
echo "<tbody>";

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $issuedDate = date('F d, Y', strtotime($row['issued_on']));
        echo "<tr><td>{$row['full_name']}</td><td>{$row['address']}</td><td>$issuedDate</td></tr>";
    }
} else {
    echo "<tr><td colspan='3' class='text-center text-muted'>No records found.</td></tr>";
}

echo "</tbody></table>";
echo "</div>";

// Pagination links
if ($totalPages > 1) {
    echo "<div class='mt-3'>";
    for ($i = 1; $i <= $totalPages; $i++) {
        echo "<a href='#' class='btn btn-sm btn-outline-primary me-1 pagination-link' data-page='$i'>$i</a>";
    }
    echo "</div>";
}
?>