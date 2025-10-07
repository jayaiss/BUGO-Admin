<?php
// ---------- JSON-only API hardening ----------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');

set_error_handler(function($severity, $message, $file, $line) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $message]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server exception', 'detail' => $e->getMessage()]);
    exit;
});

session_start();

// ---------- Auth ----------
$role = strtolower($_SESSION['Role_Name'] ?? '');
if ($role !== 'beso') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ---------- DB ----------
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'detail' => $mysqli->connect_error]);
    exit;
}

// ---------- Helpers ----------
function col_exists(mysqli $db, string $table, string $col): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($col);
    $res = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    return $ok;
}

// Maps for audit_info pretty labels
function transform_logs_filename($n){
  static $map = [
    1=>'EMPLOYEE',2=>'RESIDENTS',3=>'APPOINTMENTS',4=>'CEDULA',5=>'CASES',6=>'ARCHIVE',
    7=>'LOGIN',8=>'LOGOUT',9=>'URGENT REQUEST',10=>'URGENT CEDULA',11=>'EVENTS',
    12=>'BARANGAY OFFICIALS',13=>'BARANGAY INFO',14=>'BARANGAY LOGO',15=>'BARANGAY CERTIFICATES',
    16=>'BARANGAY CERTIFICATES PURPOSES',17=>'ZONE LEADERS',18=>'ZONE',19=>'GUIDELINES',
    20=>'FEEDBACKS',21=>'TIME SLOT',22=>'HOLIDAY',23=>'ARCHIVED RESIDENTS',24=>'ARCHIVED EMPLOYEE',
    25=>'ARCHIVED APPOINTMENTS',26=>'ARCHIVED EVENTS',27=>'ARCHIVED FEEDBACKS',28=>'BESO LIST',
    29=>'ANNOUNCEMENTS',30=>'EMPLOYEE FORGOT PASSWORD'
  ];
  return $map[(int)$n] ?? (string)$n;
}
function transform_action_made($n){
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',6=>'LOGIN',7=>'LOGOUT',
    8=>'UPDATE_STATUS',9=>'BATCH_ADD',10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  return $map[(int)$n] ?? (string)$n;
}

// Does schedules have a 'certificate' column?
$hasSchedCert = col_exists($mysqli, 'schedules', 'certificate');
// Base BESO condition for schedules (alias s0 used in EXISTS)
$schedBesoCond = $hasSchedCert
    ? "(s0.certificate = 'BESO Application' AND s0.appointment_delete_status = 0)"
    : "(s0.barangay_residency_used_for_beso = 1 AND s0.appointment_delete_status = 0)";

// ---------- Filters ----------
$gender      = $_GET['gender']      ?? '';
$statusRaw   = $_GET['status']      ?? ''; // Pending, Rejected, Approved, ApprovedCaptain, Released
$min_age     = isset($_GET['min_age']) ? (int)$_GET['min_age'] : null;
$max_age     = isset($_GET['max_age']) ? (int)$_GET['max_age'] : null;
$education   = $_GET['education']   ?? ''; // single or CSV
$urgentOnly  = isset($_GET['urgent_only']) && $_GET['urgent_only'] == '1';

$validGenders = ['Male', 'Female'];
$validStatusMap = [
    'pending'         => 'pending',
    'rejected'        => 'rejected',
    'approved'        => 'approved',
    'approvedcaptain' => 'approvedcaptain',
    'released'        => 'released',
];
$status = strtolower(trim($statusRaw));
if ($status !== '' && !isset($validStatusMap[$status])) {
    $status = ''; // ignore unknown
}

// ---------- Base WHERE (BESO via UR or Schedules) ----------
$existsUR = "
  EXISTS (
    SELECT 1
    FROM urgent_request ur0
    WHERE ur0.res_id = b.res_id
      AND ur0.certificate = 'BESO Application'
      AND ur0.urgent_delete_status = 0
  )
";
$existsSched = "
  EXISTS (
    SELECT 1
    FROM schedules s0
    WHERE s0.res_id = b.res_id
      AND $schedBesoCond
  )
";

$conditions = ["b.beso_delete_status = 0"];
$conditions[] = $urgentOnly ? "($existsUR)" : "($existsUR OR $existsSched)";

// gender
if ($gender && in_array($gender, $validGenders, true)) {
    $conditions[] = "r.gender = '" . $mysqli->real_escape_string($gender) . "'";
}
// age
if ($min_age !== null) {
    $conditions[] = "TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) >= " . (int)$min_age;
}
if ($max_age !== null) {
    $conditions[] = "TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) <= " . (int)$max_age;
}
// education (single or CSV)
$eduList = array_filter(array_map('trim', explode(',', $education)));
if (!empty($eduList)) {
    $safeEdu = array_map(fn($e) => "'" . $mysqli->real_escape_string($e) . "'", $eduList);
    $conditions[] = "b.education_attainment IN (" . implode(',', $safeEdu) . ")";
}

// status filter (cross-table; TRIM+LOWER)
if ($status !== '') {
    $statusConds = [];
    // UR-based statuses
    if (in_array($status, ['pending','rejected','approved','released','approvedcaptain'], true)) {
        $statusConds[] = "
          EXISTS (
            SELECT 1 FROM urgent_request ur
            WHERE ur.res_id = b.res_id
              AND ur.certificate = 'BESO Application'
              AND ur.urgent_delete_status = 0
              AND LOWER(TRIM(ur.status)) = '" . $mysqli->real_escape_string($status) . "'
          )
        ";
    }
    // Schedules-based statuses
    if (in_array($status, ['approvedcaptain','released','pending','rejected','approved'], true)) {
        $statusConds[] = "
          EXISTS (
            SELECT 1 FROM schedules s
            WHERE s.res_id = b.res_id
              AND " . ($hasSchedCert
                    ? "(s.certificate = 'BESO Application' AND s.appointment_delete_status = 0)"
                    : "(s.barangay_residency_used_for_beso = 1 AND s.appointment_delete_status = 0)") . "
              AND LOWER(TRIM(s.status)) = '" . $mysqli->real_escape_string($status) . "'
          )
        ";
    }
    if (!empty($statusConds)) {
        $conditions[] = '(' . implode(' OR ', $statusConds) . ')';
    }
}

$whereSql = implode(' AND ', $conditions);

// ---------- Main dataset (age/gender/education) ----------
$sql = "
  SELECT
    r.gender,
    TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) AS age,
    b.education_attainment
  FROM beso b
  JOIN residents r ON r.id = b.res_id
  WHERE $whereSql
";

$result = $mysqli->query($sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error', 'detail' => $mysqli->error]);
    exit;
}

$ageData       = [0, 0, 0, 0, 0]; // 0-18, 19-35, 36-50, 51-65, 65+
$genderData    = ['Male' => 0, 'Female' => 0];
$educationData = [];
$total = $males = $females = 0;

while ($row = $result->fetch_assoc()) {
    $total++;
    $age    = (int)($row['age'] ?? 0);
    $gender = (string)($row['gender'] ?? '');
    $edu    = (string)($row['education_attainment'] ?? '');

    if ($gender === 'Male')   { $males++;   $genderData['Male']++; }
    if ($gender === 'Female') { $females++; $genderData['Female']++; }

    if     ($age <= 18) $ageData[0]++;
    elseif ($age <= 35) $ageData[1]++;
    elseif ($age <= 50) $ageData[2]++;
    elseif ($age <= 65) $ageData[3]++;
    else                $ageData[4]++;

    if ($edu !== '') {
        $educationData[$edu] = ($educationData[$edu] ?? 0) + 1;
    }
}
$result->free();

// ---------- Urgent count (matches filters) ----------
$urgentSql = "
  SELECT COUNT(*) AS urgent_count
  FROM beso b
  JOIN residents r ON r.id = b.res_id
  JOIN urgent_request ur ON ur.res_id = b.res_id
  WHERE $whereSql
    AND ur.certificate = 'BESO Application'
    AND ur.urgent_delete_status = 0
";
$urgent = 0;
$uRes = $mysqli->query($urgentSql);
if ($uRes) { $uRow = $uRes->fetch_assoc(); $urgent = (int)($uRow['urgent_count'] ?? 0); $uRes->free(); }

// ---------- Pending count (UR 'pending' + Schedules 'pending'), reflects filters ----------
$pendingSql = "
  SELECT SUM(c) AS pending_count FROM (
    SELECT COUNT(*) AS c
    FROM beso b
    JOIN residents r ON r.id = b.res_id
    JOIN urgent_request ur ON ur.res_id = b.res_id
    WHERE $whereSql
      AND ur.certificate = 'BESO Application'
      AND ur.urgent_delete_status = 0
      AND LOWER(TRIM(ur.status)) = 'pending'
    UNION ALL
    SELECT COUNT(*) AS c
    FROM beso b
    JOIN residents r ON r.id = b.res_id
    JOIN schedules s ON s.res_id = b.res_id
    WHERE $whereSql
      AND " . ($hasSchedCert
            ? "(s.certificate = 'BESO Application' AND s.appointment_delete_status = 0)"
            : "(s.barangay_residency_used_for_beso = 1 AND s.appointment_delete_status = 0)") . "
      AND LOWER(TRIM(s.status)) = 'pending'
  ) x
";
$pending = 0;
$pRes = $mysqli->query($pendingSql);
if ($pRes) { $pRow = $pRes->fetch_assoc(); $pending = (int)($pRow['pending_count'] ?? 0); $pRes->free(); }

/* ---------- Scheduled count (BESO schedules), reflects all filters ---------- */
$schedBesoJoinCond = $hasSchedCert
    ? "s.certificate = 'BESO Application' AND s.appointment_delete_status = 0"
    : "s.barangay_residency_used_for_beso = 1 AND s.appointment_delete_status = 0";

$scheduledStatusCond = '';
if ($status !== '') {
    if (in_array($status, ['approvedcaptain','released','pending'], true)) {
        $scheduledStatusCond = " AND LOWER(TRIM(s.status)) = '" . $mysqli->real_escape_string($status) . "'";
    }
}

$scheduledSql = "
  SELECT COUNT(*) AS scheduled_count
  FROM beso b
  JOIN residents r ON r.id = b.res_id
  JOIN schedules s ON s.res_id = b.res_id
  WHERE $whereSql
    AND $schedBesoJoinCond
    $scheduledStatusCond
";
$scheduled = 0;
$sRes = $mysqli->query($scheduledSql);
if ($sRes) {
    $sRow = $sRes->fetch_assoc();
    $scheduled = (int)($sRow['scheduled_count'] ?? 0);
    $sRes->free();
}

/* ---------- Recent Activity (current BESO user only) ---------- */
$recentActivities = [];
$employee_id = (int)($_SESSION['employee_id'] ?? 0); // must be set at login
if ($employee_id > 0) {
    $pbSql = "
      SELECT
        ai.id,
        ai.logs_name,
        ai.action_made,
        ai.date_created,
        CONCAT(el.employee_fname, ' ', el.employee_lname) AS employee_name
      FROM audit_info ai
      JOIN employee_list el  ON ai.action_by = el.employee_id
      WHERE ai.action_by = {$employee_id}
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

// ---------- Output ----------
http_response_code(200);
echo json_encode([
    'total'            => $total,
    'males'            => $males,
    'females'          => $females,
    'urgent'           => $urgent,
    'pending'          => $pending,
    'scheduled'        => $scheduled,
    'ageData'          => $ageData,
    'genderData'       => $genderData,
    'educationData'    => $educationData,
    'recentActivities' => $recentActivities
], JSON_UNESCAPED_UNICODE);
exit;
