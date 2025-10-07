<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
$role = strtolower($_SESSION['Role_Name'] ?? '');

if ($role !== "revenue staff") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html'; // fixed missing slash
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$ageData = [0, 0, 0, 0, 0]; // 0-18, 19-35, 36-50, 51-65, 65+
$genderData = ['Male' => 0, 'Female' => 0];

$total = $males = $females = $pending = 0;
$urgentToday = 0;
$pendingToday = 0;

/* ----- helpers ----- */
function processRow($row, &$total, &$males, &$females, &$pending, &$ageData, &$genderData) {
    $total++;
    $age = (int)$row['age'];
    $gender = $row['gender'] ?? '';
    $status = $row['status'] ?? '';

    if ($gender === 'Male')   { $males++;   $genderData['Male']++; }
    elseif ($gender === 'Female') { $females++; $genderData['Female']++; }

    if (strtolower($status) === 'pending') { $pending++; }

    if     ($age <= 18) $ageData[0]++;
    elseif ($age <= 35) $ageData[1]++;
    elseif ($age <= 50) $ageData[2]++;
    elseif ($age <= 65) $ageData[3]++;
    else                $ageData[4]++;
}

/* Pretty labels for audit_info */
function transform_logs_filename($n){
  static $map = [
    0 => 'SYSTEM',1=>'EMPLOYEE',2=>'RESIDENTS',3=>'APPOINTMENTS',4=>'CEDULA',5=>'CASES',6=>'ARCHIVE',
    7=>'LOGIN',8=>'LOGOUT',9=>'URGENT REQUEST',10=>'URGENT CEDULA',11=>'EVENTS',
    12=>'BARANGAY OFFICIALS',13=>'BARANGAY INFO',14=>'BARANGAY LOGO',15=>'BARANGAY CERTIFICATES',
    16=>'BARANGAY CERTIFICATES PURPOSES',17=>'ZONE LEADERS',18=>'ZONE',19=>'GUIDELINES',
    20=>'FEEDBACKS',21=>'TIME SLOT',22=>'HOLIDAY',23=>'ARCHIVED RESIDENTS',24=>'ARCHIVED EMPLOYEE',
    25=>'ARCHIVED APPOINTMENTS',26=>'ARCHIVED EVENTS',27=>'ARCHIVED FEEDBACKS',28=>'BESO LIST',
    29=>'ANNOUNCEMENTS',30=>'EMPLOYEE FORGOT PASSWORD'
  ];
  $n = (int)$n;
  return $map[$n] ?? (string)$n;
}
function transform_action_made($n){
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',6=>'LOGIN',7=>'LOGOUT',
    8=>'UPDATE_STATUS',9=>'BATCH_ADD',10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  $n = (int)$n;
  return $map[$n] ?? (string)$n;
}

/* ----- datasets (exclude BESO) ----- */
$queries = [
    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, s.status 
     FROM schedules s 
     JOIN residents r ON r.id = s.res_id 
     WHERE s.certificate != 'BESO Application' AND s.appointment_delete_status = 0",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, s.status 
     FROM archived_schedules s 
     JOIN residents r ON r.id = s.res_id 
     WHERE s.certificate != 'BESO Application'",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, u.status 
     FROM urgent_request u 
     JOIN residents r ON r.id = u.res_id 
     WHERE u.certificate != 'BESO Application' AND u.urgent_delete_status = 0",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, u.status 
     FROM archived_urgent_request u 
     JOIN residents r ON r.id = u.res_id 
     WHERE u.certificate != 'BESO Application'"
];

$cedulaQueries = [
    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, c.cedula_status AS status 
     FROM cedula c 
     JOIN residents r ON r.id = c.res_id 
     WHERE c.cedula_delete_status = 0",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, c.cedula_status AS status 
     FROM archived_cedula c 
     JOIN residents r ON r.id = c.res_id",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, c.cedula_status AS status 
     FROM urgent_cedula_request c 
     JOIN residents r ON r.id = c.res_id 
     WHERE c.cedula_delete_status = 0",

    "SELECT r.gender, TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age, c.cedula_status AS status 
     FROM archived_urgent_cedula_request c 
     JOIN residents r ON r.id = c.res_id"
];

foreach (array_merge($queries, $cedulaQueries) as $query) {
    $result = $mysqli->query($query);
    if (!$result) {
        http_response_code(500);
        echo json_encode(["error" => "Query failed: " . $mysqli->error]);
        exit;
    }
    while ($row = $result->fetch_assoc()) {
        processRow($row, $total, $males, $females, $pending, $ageData, $genderData);
    }
    $result->free();
}

/* ----- today counts ----- */
$urgentQuery = "
    SELECT COUNT(*) AS total 
    FROM urgent_request 
    WHERE DATE(selected_date) = CURDATE() 
      AND certificate != 'BESO Application' 
      AND urgent_delete_status = 0
";
if ($res = $mysqli->query($urgentQuery)) {
    if ($row = $res->fetch_assoc()) { $urgentToday += (int)$row['total']; }
    $res->free();
}

$urgentCedulaQuery = "
    SELECT COUNT(*) AS total 
    FROM urgent_cedula_request 
    WHERE DATE(appointment_date) = CURDATE() 
      AND cedula_delete_status = 0
";
if ($res = $mysqli->query($urgentCedulaQuery)) {
    if ($row = $res->fetch_assoc()) { $urgentToday += (int)$row['total']; }
    $res->free();
}

$pendingSchedToday = "
    SELECT COUNT(*) AS total 
    FROM schedules 
    WHERE DATE(selected_date) = CURDATE() 
      AND status = 'Pending' 
      AND certificate != 'BESO Application' 
      AND appointment_delete_status = 0
";
if ($res = $mysqli->query($pendingSchedToday)) {
    if ($row = $res->fetch_assoc()) { $pendingToday += (int)$row['total']; }
    $res->free();
}

$pendingUrgentToday = "
    SELECT COUNT(*) AS total 
    FROM urgent_request 
    WHERE DATE(selected_date) = CURDATE() 
      AND status = 'Pending' 
      AND certificate != 'BESO Application' 
      AND urgent_delete_status = 0
";
if ($res = $mysqli->query($pendingUrgentToday)) {
    if ($row = $res->fetch_assoc()) { $pendingToday += (int)$row['total']; }
    $res->free();
}

$pendingCedulaToday = "
    SELECT COUNT(*) AS total 
    FROM cedula 
    WHERE DATE(appointment_date) = CURDATE() 
      AND cedula_status = 'Pending' 
      AND cedula_delete_status = 0
";
if ($res = $mysqli->query($pendingCedulaToday)) {
    if ($row = $res->fetch_assoc()) { $pendingToday += (int)$row['total']; }
    $res->free();
}

$pendingUrgentCedulaToday = "
    SELECT COUNT(*) AS total 
    FROM urgent_cedula_request 
    WHERE DATE(appointment_date) = CURDATE() 
      AND cedula_status = 'Pending' 
      AND cedula_delete_status = 0
";
if ($res = $mysqli->query($pendingUrgentCedulaToday)) {
    if ($row = $res->fetch_assoc()) { $pendingToday += (int)$row['total']; }
    $res->free();
}

/* ----- Recent Activity: current Revenue Staff only ----- */
$recentActivities = [];
$employee_id = (int)($_SESSION['employee_id'] ?? 0); // must be set during login
if ($employee_id > 0) {
    $pbSql = "
      SELECT
        ai.id,
        ai.logs_name,
        ai.action_made,
        ai.date_created,
        CONCAT(el.employee_fname, ' ', el.employee_lname) AS employee_name
      FROM audit_info ai
      JOIN employee_list  el ON ai.action_by = el.employee_id
      JOIN employee_roles er ON el.Role_Id   = er.Role_Id
      WHERE ai.action_by = {$employee_id}
        AND LOWER(er.Role_Name) = 'revenue staff'
      ORDER BY ai.date_created DESC
      LIMIT 10
    ";
    if ($res = $mysqli->query($pbSql)) {
        while ($r = $res->fetch_assoc()) {
            $recentActivities[] = [
                'id'         => (int)$r['id'],
                'module'     => transform_logs_filename($r['logs_name']),
                'action'     => transform_action_made($r['action_made']),
                'action_by'  => $r['employee_name'],
                'date'       => $r['date_created'],
                'date_human' => date('M d, Y h:i A', strtotime($r['date_created'])),
            ];
        }
        $res->free();
    }
}

/* ----- Output ----- */
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'total'            => $total,
    'males'            => $males,
    'females'          => $females,
    'urgent'           => $urgentToday,
    'pending'          => $pendingToday,
    'ageData'          => $ageData,
    'genderData'       => $genderData,
    'recentActivities' => $recentActivities
], JSON_UNESCAPED_UNICODE);
exit;
