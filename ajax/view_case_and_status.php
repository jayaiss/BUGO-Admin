<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();

/* ------- AuthZ ------- */
$role = $_SESSION['Role_Name'] ?? '';
if ($role !== 'Revenue Staff' && $role !== 'Admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

/* ------- Input ------- */
$tracking_number = $_GET['tracking_number'] ?? '';
if (!$tracking_number) {
    echo json_encode(['success' => false, 'message' => 'Tracking number missing']);
    exit;
}

/* ------- Locate the appointment (any table) ------- */
$main = null;
$search_sql = "
    SELECT res_id, selected_date  AS appt_date, status        AS current_status FROM schedules            WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date, cedula_status AS current_status FROM cedula               WHERE tracking_number = ?
    UNION
    SELECT res_id, selected_date  AS appt_date, status        AS current_status FROM urgent_request        WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date, cedula_status AS current_status FROM urgent_cedula_request WHERE tracking_number = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($search_sql);
$stmt->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $main = $result->fetch_assoc();
}
$stmt->close();

if (!$main) {
    echo json_encode(['success' => false, 'message' => 'No appointment found']);
    exit;
}

$res_id = (int)$main['res_id'];
$date   = $main['appt_date'];
$status = $main['current_status'];

/* ------- Collect all same-day appointments across tables ------- */
/* Include cedula_number and income (as cedula_income) where available */
$appointments = [];

$tables = [
    // table,          certificate expr, time col,          date col,           cedula_number expr,         income expr (aliased)
    ['schedules',      'certificate',    'selected_time',   'selected_date',    'NULL AS cedula_number',    'NULL AS cedula_income'],
    ['cedula',         "'Cedula'",       'appointment_time','appointment_date', 'cedula_number',            'income AS cedula_income'],
    ['urgent_request', 'certificate',    'selected_time',   'selected_date',    'NULL AS cedula_number',    'NULL AS cedula_income'],
    // show as 'Cedula' for consistency so UI rules apply
    ['urgent_cedula_request', "'Cedula'",'appointment_time','appointment_date', 'cedula_number',            'income AS cedula_income'],
];

foreach ($tables as [$table, $certCol, $timeCol, $dateCol, $cedCol, $incCol]) {
    $sql = "
        SELECT tracking_number,
               $certCol AS certificate,
               $timeCol AS time_slot,
               $cedCol,
               $incCol
        FROM $table
        WHERE res_id = ? AND $dateCol = ?
    ";
    $st = $mysqli->prepare($sql);
    if (!$st) {
        error_log("prepare failed for $table: " . $mysqli->error);
        continue;
    }
    $st->bind_param("is", $res_id, $date);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        // Exclude BESO for Revenue Staff
        if ($role === 'Revenue Staff' && strtolower(trim($r['certificate'])) === 'beso application') {
            continue;
        }

        $appointments[] = [
            'tracking_number' => $r['tracking_number'],
            'certificate'     => $r['certificate'],
            'time_slot'       => $r['time_slot'] ?? '',
            'cedula_number'   => $r['cedula_number'] ?? '',
            'cedula_income'   => $r['cedula_income'] ?? null, // <-- now present for Cedula rows
        ];
    }
    $st->close();
}

/* ------- Case History: match resident as RESPONDENT ------- */
/* 1) Resident name parts */
$rStmt = $mysqli->prepare("
    SELECT 
        COALESCE(first_name,'')  AS first_name,
        COALESCE(middle_name,'') AS middle_name,
        COALESCE(last_name,'')   AS last_name,
        COALESCE(suffix_name,'') AS suffix_name
    FROM residents
    WHERE id = ?
    LIMIT 1
");
if (!$rStmt) {
    error_log('prepare residents failed: ' . $mysqli->error);
    echo json_encode(['success' => true, 'status' => $status, 'cases' => [], 'appointments' => $appointments]);
    exit;
}
$rStmt->bind_param('i', $res_id);
$rStmt->execute();
$residentRow = $rStmt->get_result()->fetch_assoc();
$rStmt->close();

$first  = $residentRow['first_name']  ?? '';
$middle = $residentRow['middle_name'] ?? '';
$last   = $residentRow['last_name']   ?? '';
$suffix = $residentRow['suffix_name'] ?? '';

/* 2) Cases query */
$cases = [];
$case_sql = "
    SELECT case_number, nature_offense, date_filed, date_hearing, action_taken
    FROM cases c
    WHERE
        UPPER(TRIM(c.Resp_First_Name)) = UPPER(TRIM(?))
        AND UPPER(TRIM(c.Resp_Last_Name))  = UPPER(TRIM(?))
        AND (
            c.Resp_Middle_Name IS NULL OR c.Resp_Middle_Name = ''
            OR UPPER(TRIM(c.Resp_Middle_Name)) = UPPER(TRIM(?))
            OR LEFT(UPPER(TRIM(c.Resp_Middle_Name)), 1) = LEFT(UPPER(TRIM(?)), 1)
        )
        AND (
            c.Resp_Suffix_Name IS NULL OR c.Resp_Suffix_Name = ''
            OR UPPER(TRIM(c.Resp_Suffix_Name)) = UPPER(TRIM(?))
        )
    ORDER BY COALESCE(date_filed, '0000-00-00') DESC, case_number DESC
";
$cStmt = $mysqli->prepare($case_sql);
if ($cStmt) {
    $cStmt->bind_param('sssss', $first, $last, $middle, $middle, $suffix);
    $cStmt->execute();
    $res = $cStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $cases[] = $row;
    }
    $cStmt->close();
} else {
    error_log('prepare cases failed: ' . $mysqli->error);
}

/* ------- Response ------- */
echo json_encode([
    'success'      => true,
    'status'       => $status,
    'cases'        => $cases,
    'appointments' => $appointments
]);
exit;
