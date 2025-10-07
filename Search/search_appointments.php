<?php

session_start();
$user_role = strtolower($_SESSION['Role_Name'] ?? '');


if ($user_role !== 'admin' && $user_role !== 'punong barangay' && $user_role !== "barangay secretary" && $user_role !== "revenue staff") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection(); // Make sure this path is correct for your setup

$q = trim($_GET['q'] ?? '');
$qLike = "%" . $q . "%"; // Prepare for LIKE comparison

$user_role = $_SESSION['Role_Name'] ?? '';

// 👇 Get today's date in Y-m-d format
$today = date('Y-m-d');

$union_queries = [];
$is_beso_role = (stripos($user_role, 'beso') !== false);
$is_revenue_role = (stripos($user_role, 'revenue') !== false);

// 1. Urgent Cedula (Exclude for BESO role)
if (!$is_beso_role) {
    $union_queries[] = "
    SELECT
        ucr.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name) AS fullname,
        'Cedula' AS certificate,
        ucr.cedula_status AS status,
        ucr.appointment_time AS selected_time,
        ucr.appointment_date AS selected_date,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application (Urgent)' AS purpose,
        ucr.issued_on,
        ucr.cedula_number,
        ucr.issued_at
    FROM urgent_cedula_request ucr
    JOIN residents r ON ucr.res_id = r.id
    WHERE ucr.cedula_delete_status = 0";
}

// 2. Regular Schedules (Apply role-based filter)
$schedule_conditions = "s.appointment_delete_status = 0";
if ($is_beso_role) {
    $schedule_conditions .= " AND s.certificate = 'BESO Application'";
} elseif ($is_revenue_role) {
    $schedule_conditions .= " AND s.certificate != 'BESO Application'";
}
$union_queries[] = "
SELECT
    s.tracking_number,
    CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name) AS fullname,
    s.certificate,
    s.status,
    s.selected_time,
    s.selected_date,
    r.birth_date,
    r.birth_place,
    r.res_zone,
    r.civil_status,
    r.residency_start,
    r.res_street_address,
    s.purpose,
    c.issued_on,
    c.cedula_number,
    c.issued_at
FROM schedules s
JOIN residents r ON s.res_id = r.id
LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status = 'Approved'
WHERE " . $schedule_conditions;


// 3. Regular Cedula (Exclude for BESO role)
if (!$is_beso_role) {
    $union_queries[] = "
    SELECT
        c.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name) AS fullname,
        'Cedula' AS certificate,
        c.cedula_status AS status,
        c.appointment_time AS selected_time,
        c.appointment_date AS selected_date,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application' AS purpose,
        c.issued_on,
        c.cedula_number,
        c.issued_at
    FROM cedula c
    JOIN residents r ON c.res_id = r.id
    WHERE c.cedula_delete_status = 0";
}

// 4. Urgent Non-Cedula Requests (Apply role-based filter)
$urgent_conditions = "u.urgent_delete_status = 0";
if ($is_beso_role) {
    $urgent_conditions .= " AND u.certificate = 'BESO Application'";
} elseif ($is_revenue_role) {
    $urgent_conditions .= " AND u.certificate != 'BESO Application'";
}
$union_queries[] = "
SELECT
    u.tracking_number,
    CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name) AS fullname,
    u.certificate,
    u.status,
    u.selected_time,
    u.selected_date,
    r.birth_date,
    r.birth_place,
    r.res_zone,
    r.civil_status,
    r.residency_start,
    r.res_street_address,
    u.purpose,
    COALESCE(c.issued_on, uc.issued_on) AS issued_on,
    COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
    COALESCE(c.issued_at, uc.issued_at) AS issued_at
FROM urgent_request u
JOIN residents r ON u.res_id = r.id
LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status = 'Approved'
LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
WHERE " . $urgent_conditions;


// Combine all active unions
if (empty($union_queries)) {
    // If no unions are selected based on role, create a dummy query that returns no rows
    $sql = "SELECT NULL AS tracking_number, NULL AS fullname, NULL AS certificate, NULL AS status, NULL AS selected_time, NULL AS selected_date, NULL AS birth_date, NULL AS birth_place, NULL AS res_zone, NULL AS civil_status, NULL AS residency_start, NULL AS res_street_address, NULL AS purpose, NULL AS issued_on, NULL AS cedula_number, NULL AS issued_at WHERE 1 = 0";
} else {
    $sql = "
    SELECT * FROM (
        " . implode("\n    UNION\n    ", $union_queries) . "
    ) AS all_appointments
    WHERE (fullname LIKE ? OR tracking_number LIKE ?)
    AND selected_date >= ?
    ORDER BY selected_date ASC, selected_time ASC
    LIMIT 100
    ";
}


// Prepare the SQL query
$stmt = $mysqli->prepare($sql);

// Bind parameters securely (3 parameters: $qLike, $qLike, $today)
// The 'sss' indicates three string parameters.
// Only bind if there are actual parameters in the final query.
if (!empty($union_queries)) { // Only bind if not the dummy query
    $stmt->bind_param("sss", $qLike, $qLike, $today);
}


// Execute the query
$stmt->execute();

$result = $stmt->get_result();

// Display results
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // This file is assumed to contain the HTML structure for a single appointment row.
        include '../components/appointment_row.php';
    }
} else {
    echo '<tr><td colspan="7" class="text-center">No appointments found.</td></tr>';
}

// Close the statement and connection
$stmt->close();
$mysqli->close();
?>