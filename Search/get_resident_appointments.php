<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();
$user_role = strtolower($_SESSION['Role_Name'] ?? '');


if ($user_role !== 'Lupon' && $user_role !== 'punong barangay' && $user_role !== "barangay secretary" && $user_role !== "revenue staff"&& $user_role !== "beso") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}



$res_id = intval($_GET['res_id'] ?? 0);
$selected_date = $_GET['selected_date'] ?? '';

if (!$res_id || !$selected_date) {
    echo "<div class='text-danger'>Invalid request.</div>";
    exit;
}

$appointments = [];

// Unified query from all 4 tables
$unionQuery = "
-- Cedula
SELECT tracking_number, 'Cedula' AS certificate, cedula_status AS status, appointment_time AS selected_time
FROM cedula 
WHERE res_id = ? AND appointment_date = ? AND cedula_delete_status = 0 AND cedula_status = 'Approved'

UNION

-- Urgent Cedula
SELECT tracking_number, 'Cedula (Urgent)' AS certificate, cedula_status, appointment_time
FROM urgent_cedula_request 
WHERE res_id = ? AND appointment_date = ? AND cedula_delete_status = 0 AND cedula_status = 'Approved'

UNION

-- Schedules
SELECT tracking_number, certificate, status, selected_time
FROM schedules 
WHERE res_id = ? AND selected_date = ? AND appointment_delete_status = 0 AND status = 'Approved'

UNION

-- Urgent Request
SELECT tracking_number, certificate, status, selected_time
FROM urgent_request 
WHERE res_id = ? AND selected_date = ? AND urgent_delete_status = 0 AND status = 'Approved'

";

$stmt = $mysqli->prepare($unionQuery);
$stmt->bind_param("isisisis", $res_id, $selected_date, $res_id, $selected_date, $res_id, $selected_date, $res_id, $selected_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<table class='table table-sm table-bordered mb-2'>
            <thead class='table-light'>
                <tr>
                    <th>Certificate</th>
                    <th>Tracking #</th>
                    <th>Time Slot</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>" . htmlspecialchars($row['certificate']) . "</td>
            <td>" . htmlspecialchars($row['tracking_number']) . "</td>
            <td>" . htmlspecialchars($row['selected_time']) . "</td>
            <td><span class='badge bg-secondary'>" . htmlspecialchars($row['status']) . "</span></td>
        </tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p class='text-muted'>No appointments found for this day.</p>";
}
?>
