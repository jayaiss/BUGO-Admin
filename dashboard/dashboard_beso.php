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

// ---------- Helpers (for recent activities pretty labels) ----------
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

// ---------- Filters (BESO-only) ----------
// (match your BESO List UI: name search, month, year, course, education)
$q          = trim($_GET['q'] ?? '');               // name search
$month      = isset($_GET['month']) ? (int)$_GET['month'] : null; // 1-12
$year       = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$course     = $_GET['course']     ?? '';            // single or CSV
$education  = $_GET['education']  ?? '';            // single or CSV

$conditions = ["b.beso_delete_status = 0"];

// name search across first/middle/last/suffix
if ($q !== '') {
    $like = '%' . $mysqli->real_escape_string($q) . '%';
    $conditions[] = "
      (
        b.firstName   LIKE '$like' OR
        b.middleName  LIKE '$like' OR
        b.lastName    LIKE '$like' OR
        b.suffixName  LIKE '$like'
      )
    ";
}

// month/year from created_at
if ($month !== null && $month >= 1 && $month <= 12) {
    $conditions[] = "MONTH(b.created_at) = {$month}";
}
if ($year !== null && $year >= 1900 && $year <= 2100) {
    $conditions[] = "YEAR(b.created_at) = {$year}";
}

// course filter (single or CSV)
$courseList = array_filter(array_map('trim', explode(',', $course)));
if (!empty($courseList)) {
    $safe = array_map(fn($c) => "'" . $mysqli->real_escape_string($c) . "'", $courseList);
    $conditions[] = "b.course IN (" . implode(',', $safe) . ")";
}

// education filter (single or CSV)
$eduList = array_filter(array_map('trim', explode(',', $education)));
if (!empty($eduList)) {
    $safeEdu = array_map(fn($e) => "'" . $mysqli->real_escape_string($e) . "'", $eduList);
    $conditions[] = "b.education_attainment IN (" . implode(',', $safeEdu) . ")";
}

$whereSql = implode(' AND ', $conditions);

// ---------- Main dataset (BESO-only) ----------
$sql = "
  SELECT
    b.education_attainment,
    b.course
  FROM beso b
  WHERE $whereSql
";
$result = $mysqli->query($sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error', 'detail' => $mysqli->error]);
    exit;
}

$total          = 0;
$educationData  = [];
$courseData     = [];

while ($row = $result->fetch_assoc()) {
    $total++;

    $edu = (string)($row['education_attainment'] ?? '');
    if ($edu !== '') {
        $educationData[$edu] = ($educationData[$edu] ?? 0) + 1;
    }

    $crs = (string)($row['course'] ?? '');
    if ($crs !== '') {
        $courseData[$crs] = ($courseData[$crs] ?? 0) + 1;
    }
}
$result->free();

// ---------- Recent Activity (current BESO user only) ----------
$recentActivities = [];
$employee_id = (int)($_SESSION['employee_id'] ?? 0);
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
// Note: gender/age/urgent/pending/scheduled are 0 now since BESO has no res_id link.
http_response_code(200);
echo json_encode([
    'total'            => $total,
    'males'            => 0,
    'females'          => 0,
    'urgent'           => 0,
    'pending'          => 0,
    'scheduled'        => 0,
    'ageData'          => [0,0,0,0,0],
    'genderData'       => ['Male' => 0, 'Female' => 0],
    'educationData'    => $educationData,
    'courseData'       => $courseData,
    'recentActivities' => $recentActivities
], JSON_UNESCAPED_UNICODE);
exit;
