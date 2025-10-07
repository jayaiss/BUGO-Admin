<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);
session_start();

$role = strtolower($_SESSION['Role_Name'] ?? '');

if ($role !== "multimedia") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$currentMonth = date('m');
$currentYear  = date('Y');
$today        = date('Y-m-d');

/* --------------------------
   Events of the Month
-------------------------- */
$eventNameQuery = "
    SELECT en.event_name, COUNT(*) AS total 
    FROM events e
    JOIN event_name en ON e.event_title = en.id
    WHERE MONTH(e.event_date) = '$currentMonth'
      AND YEAR(e.event_date) = '$currentYear'
      AND e.events_delete_status = 0
    GROUP BY en.event_name
";
$eventNameResult = $mysqli->query($eventNameQuery);
$eventNameData   = [];
while ($row = $eventNameResult->fetch_assoc()) {
    $eventNameData[$row['event_name']] = (int)$row['total'];
}

/* --------------------------
   Upcoming Events of the Month
-------------------------- */
$upcomingEventsQuery = "
    SELECT COUNT(*) AS count 
    FROM events 
    WHERE event_date >= '$today' 
      AND MONTH(event_date) = '$currentMonth' 
      AND YEAR(event_date) = '$currentYear'
      AND events_delete_status = 0
";
$upcomingEventsResult = $mysqli->query($upcomingEventsQuery)->fetch_assoc();
$upcomingEventsCount  = (int)($upcomingEventsResult['count'] ?? 0);

/* --------------------------
   Event Location Count
-------------------------- */
$locationQuery = "
    SELECT event_location, COUNT(*) AS total
    FROM events
    WHERE MONTH(event_date) = '$currentMonth'
      AND YEAR(event_date) = '$currentYear'
      AND events_delete_status = 0
    GROUP BY event_location
";
$locationResult = $mysqli->query($locationQuery);
$locationData   = [];
while ($row = $locationResult->fetch_assoc()) {
    $locationData[$row['event_location']] = (int)$row['total'];
}

/* --------------------------
   Total Events (all-time)
-------------------------- */
$totalEventsQuery  = "SELECT COUNT(*) AS total FROM events WHERE events_delete_status = 0";
$totalEventsResult = $mysqli->query($totalEventsQuery)->fetch_assoc();
$totalEventsCount  = (int)($totalEventsResult['total'] ?? 0);

/* --------------------------
   Recent Activity (Multimedia only)
-------------------------- */
function transform_logs_filename($n) {
  static $map = [
    0=>'SYSTEM',1=>'EMPLOYEE',2=>'RESIDENTS',3=>'APPOINTMENTS',4=>'CEDULA',5=>'CASES',
    6=>'ARCHIVE',7=>'LOGIN',8=>'LOGOUT',9=>'URGENT REQUEST',10=>'URGENT CEDULA',11=>'EVENTS',
    12=>'BARANGAY OFFICIALS',13=>'BARANGAY INFO',14=>'BARANGAY LOGO',15=>'BARANGAY CERTIFICATES',
    16=>'BARANGAY CERTIFICATES PURPOSES',17=>'ZONE LEADERS',18=>'ZONE',19=>'GUIDELINES',
    20=>'FEEDBACKS',21=>'TIME SLOT',22=>'HOLIDAY',23=>'ARCHIVED RESIDENTS',24=>'ARCHIVED EMPLOYEE',
    25=>'ARCHIVED APPOINTMENTS',26=>'ARCHIVED EVENTS',27=>'ARCHIVED FEEDBACKS',28=>'BESO LIST',
    29=>'ANNOUNCEMENTS',30=>'EMPLOYEE FORGOT PASSWORD'
  ];
  return $map[(int)$n] ?? 'UNKNOWN';
}

function transform_action_made($n) {
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',
    6=>'LOGIN',7=>'LOGOUT',8=>'UPDATE_STATUS',9=>'BATCH_ADD',
    10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  return $map[(int)$n] ?? 'UNKNOWN';
}

$recentActivities = [];
$employee_id = (int)($_SESSION['employee_id'] ?? 0);
if ($employee_id > 0) {
    $sql = "
      SELECT ai.id, ai.logs_name, ai.action_made, ai.date_created,
             CONCAT(el.employee_fname, ' ', el.employee_lname) AS employee_name
      FROM audit_info ai
      JOIN employee_list  el ON ai.action_by = el.employee_id
      JOIN employee_roles er ON el.Role_Id   = er.Role_Id
      WHERE ai.action_by = ?
        AND LOWER(er.Role_Name) = 'multimedia'
      ORDER BY ai.date_created DESC
      LIMIT 10
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $res = $stmt->get_result();
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
        $stmt->close();
    }
}

/* --------------------------
   Output JSON
-------------------------- */
header('Content-Type: application/json');
echo json_encode([
    'eventNameData'      => $eventNameData,
    'upcomingEventsCount'=> $upcomingEventsCount,
    'locationData'       => $locationData,
    'totalEventsCount'   => $totalEventsCount,
    'recentActivities'   => $recentActivities
], JSON_UNESCAPED_UNICODE);
