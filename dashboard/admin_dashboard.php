<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();
date_default_timezone_set('Asia/Manila');

$user_role = strtolower($_SESSION['Role_Name'] ?? '');
if (
  $user_role !== "admin" &&
  $user_role !== "punong barangay" &&
  $user_role !== "encoder" &&
  $user_role !== "barangay secretary" &&
  $user_role !== "revenue staff"
) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

/* -------------------------
   Helpers
------------------------- */
function fetchCount(mysqli $db, string $sql): int {
    $res = $db->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return $row ? (int)($row['count'] ?? 0) : 0;
}
function ymd_or_null(?string $s): ?string {
    $s = trim((string)$s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function esc(mysqli $db, string $s): string { return $db->real_escape_string($s); }

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
  return $map[(int)$n] ?? '';
}
function transform_action_made($n){
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',6=>'LOGIN',7=>'LOGOUT',
    8=>'UPDATE_STATUS',9=>'BATCH_ADD',10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  return $map[(int)$n] ?? '';
}

/* -------------------------
   Filters
------------------------- */
$status     = strtolower(trim($_GET['status']      ?? ''));
$reqType    = strtolower(trim($_GET['req_type']    ?? 'all'));
$start_date = ymd_or_null($_GET['start_date'] ?? null);
$end_date   = ymd_or_null($_GET['end_date']   ?? null);

$valid_status = ['pending','approved','rejected','approvedcaptain','released',''];
if (!in_array($status, $valid_status, true)) $status = '';

/* -------------------------
   Totals
------------------------- */
$totalCases = fetchCount($mysqli, "SELECT COUNT(*) AS count FROM cases");
$totalBeso  = fetchCount($mysqli, "SELECT COUNT(*) AS count FROM beso WHERE beso_delete_status = 0");

/* -------------------------
   Age & Gender
------------------------- */
$ageData = [];
$ageQueries = [
    "SELECT COUNT(*) AS count FROM residents WHERE age BETWEEN 0 AND 18  AND resident_delete_status = 0",
    "SELECT COUNT(*) AS count FROM residents WHERE age BETWEEN 19 AND 35 AND resident_delete_status = 0",
    "SELECT COUNT(*) AS count FROM residents WHERE age BETWEEN 36 AND 50 AND resident_delete_status = 0",
    "SELECT COUNT(*) AS count FROM residents WHERE age BETWEEN 51 AND 65 AND resident_delete_status = 0",
    "SELECT COUNT(*) AS count FROM residents WHERE age > 65           AND resident_delete_status = 0",
];
foreach ($ageQueries as $sql) { $ageData[] = fetchCount($mysqli, $sql); }

$genderData = ['Male'=>0,'Female'=>0];
$gRes = $mysqli->query("
  SELECT gender, COUNT(*) AS count
  FROM residents
  WHERE resident_delete_status = 0
  GROUP BY gender
");
if ($gRes) {
    while ($row = $gRes->fetch_assoc()) {
        $genderData[$row['gender']] = (int)$row['count'];
    }
    $gRes->free();
}

/* -------------------------
   WHERE snippets shared per source
------------------------- */
$statusUR  = $status ? " AND LOWER(TRIM(ur.status)) = '".esc($mysqli,$status)."'" : '';
$statusUCR = $status ? " AND LOWER(TRIM(ucr.cedula_status)) = '".esc($mysqli,$status)."'" : '';
$statusSCH = $status ? " AND LOWER(TRIM(s.status)) = '".esc($mysqli,$status)."'" : '';
$statusCED = $status ? " AND LOWER(TRIM(c.cedula_status)) = '".esc($mysqli,$status)."'" : '';

$dateUR  = ($start_date ? " AND ur.selected_date     >= '".esc($mysqli,$start_date)."'" : '') .
           ($end_date   ? " AND ur.selected_date     <= '".esc($mysqli,$end_date)."'"   : '');
$dateUCR = ($start_date ? " AND ucr.appointment_date >= '".esc($mysqli,$start_date)."'" : '') .
           ($end_date   ? " AND ucr.appointment_date <= '".esc($mysqli,$end_date)."'"   : '');
$dateSCH = ($start_date ? " AND s.selected_date      >= '".esc($mysqli,$start_date)."'" : '') .
           ($end_date   ? " AND s.selected_date      <= '".esc($mysqli,$end_date)."'"   : '');
$dateCED = ($start_date ? " AND c.appointment_date   >= '".esc($mysqli,$start_date)."'" : '') .
           ($end_date   ? " AND c.appointment_date   <= '".esc($mysqli,$end_date)."'"   : '');

/* -------------------------
   Requests
------------------------- */
$urgentRequests = fetchCount($mysqli, "
  SELECT SUM(cnt) AS count FROM (
    SELECT COUNT(*) AS cnt
    FROM urgent_request ur
    WHERE ur.urgent_delete_status = 0
      $statusUR
      $dateUR
    UNION ALL
    SELECT COUNT(*) AS cnt
    FROM urgent_cedula_request ucr
    WHERE ucr.cedula_delete_status = 0
      $statusUCR
      $dateUCR
  ) x
");

$regularRequests = fetchCount($mysqli, "
  SELECT SUM(cnt) AS count FROM (
    SELECT COUNT(*) AS cnt
    FROM schedules s
    WHERE s.appointment_delete_status = 0
      $statusSCH
      $dateSCH
    UNION ALL
    SELECT COUNT(*) AS cnt
    FROM cedula c
    WHERE c.cedula_delete_status = 0
      $statusCED
      $dateCED
  ) x
");

$pendingAppointments = fetchCount($mysqli, "
  SELECT SUM(cnt) AS count FROM (
    SELECT COUNT(*) AS cnt
    FROM schedules s
    WHERE s.appointment_delete_status = 0
      AND LOWER(TRIM(s.status)) = 'pending'
      $dateSCH
    UNION ALL
    SELECT COUNT(*) AS cnt
    FROM cedula c
    WHERE c.cedula_delete_status = 0
      AND LOWER(TRIM(c.cedula_status)) = 'pending'
      $dateCED
  ) x
");

if ($reqType === 'urgent')  { $regularRequests = 0; $pendingAppointments = 0; }
if ($reqType === 'regular') { $urgentRequests  = 0; }

/* -------------------------
   Events
------------------------- */
$today        = date('Y-m-d');
$currentMonth = date('m');
$currentYear  = date('Y');

$eventNameData = [];
$eRes = $mysqli->query("
    SELECT en.event_name, COUNT(*) AS total
    FROM events e
    JOIN event_name en ON e.event_title = en.id
    WHERE MONTH(e.event_date) = '$currentMonth'
      AND YEAR(e.event_date)  = '$currentYear'
      AND e.events_delete_status = 0
    GROUP BY en.event_name
");
if ($eRes) {
    while ($row = $eRes->fetch_assoc()) {
        $eventNameData[$row['event_name']] = (int)$row['total'];
    }
    $eRes->free();
}
$upcomingEventsCount = fetchCount($mysqli, "
    SELECT COUNT(*) AS count
    FROM events
    WHERE event_date >= '$today'
      AND MONTH(event_date) = '$currentMonth'
      AND YEAR(event_date)  = '$currentYear'
      AND events_delete_status = 0
");

/* -------------------------
   Recent Activity (Current Logged-in User)
------------------------- */
$employee_id = (int)($_SESSION['employee_id'] ?? 0);
$recentActivities = [];

if ($employee_id > 0) {
    $dtFrom = $start_date ? esc($mysqli, $start_date) . " 00:00:00" : null;
    $dtTo   = $end_date   ? esc($mysqli, $end_date)   . " 23:59:59" : null;

    $where = "ai.action_by = {$employee_id}";

    if ($dtFrom) $where .= " AND ai.date_created >= '{$dtFrom}'";
    if ($dtTo)   $where .= " AND ai.date_created <= '{$dtTo}'";

    $pbSql = "
      SELECT
        ai.id,
        ai.logs_name,
        ai.action_made,
        ai.date_created,
        CONCAT(ab.employee_fname, ' ', ab.employee_lname) AS employee_name
      FROM audit_info ai
      JOIN employee_list ab  ON ai.action_by = ab.employee_id
      JOIN employee_roles er ON ab.Role_Id   = er.Role_Id
      WHERE $where
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

/* -------------------------
   Output
------------------------- */
header('Content-Type: application/json');
try {
    echo json_encode([
        'totalCases'          => $totalCases,
        'totalBeso'           => $totalBeso,
        'ageData'             => $ageData,
        'genderData'          => $genderData,
        'urgentRequests'      => $urgentRequests,
        'regularRequests'     => $regularRequests,
        'pendingAppointments' => $pendingAppointments,
        'eventNameData'       => $eventNameData,
        'upcomingEventsCount' => $upcomingEventsCount,
        'recentActivities'    => $recentActivities,
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
