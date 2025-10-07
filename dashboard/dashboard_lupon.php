<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$role = strtolower($_SESSION['Role_Name'] ?? '');

if ($role !== "lupon") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html'; // fixed path
    exit;
}

if ($mysqli->connect_error) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/500.html'; // fixed path
    exit;
}

if (!isset($_SESSION['id']) && !isset($_SESSION['username'])) {
    http_response_code(401);
    require_once __DIR__ . '/../../security/401.html'; // fixed path
    exit;
}

/* -----------------------------
   Helpers
----------------------------- */
function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    array_unshift($refs, $types);
    $stmt->bind_param(...$refs);
}
function in_placeholders(int $n): string { return implode(',', array_fill(0, max(0, $n), '?')); }

/* Pretty labels for audit_info */
function transform_logs_filename($n){
  static $map = [
    0=>'SYSTEM', // fallback for 0s you sometimes get
    1=>'EMPLOYEE',2=>'RESIDENTS',3=>'APPOINTMENTS',4=>'CEDULA',5=>'CASES',6=>'ARCHIVE',
    7=>'LOGIN',8=>'LOGOUT',9=>'URGENT REQUEST',10=>'URGENT CEDULA',11=>'EVENTS',
    12=>'BARANGAY OFFICIALS',13=>'BARANGAY INFO',14=>'BARANGAY LOGO',15=>'BARANGAY CERTIFICATES',
    16=>'BARANGAY CERTIFICATES PURPOSES',17=>'ZONE LEADERS',18=>'ZONE',19=>'GUIDELINES',
    20=>'FEEDBACKS',21=>'TIME SLOT',22=>'HOLIDAY',23=>'ARCHIVED RESIDENTS',24=>'ARCHIVED EMPLOYEE',
    25=>'ARCHIVED APPOINTMENTS',26=>'ARCHIVED EVENTS',27=>'ARCHIVED FEEDBACKS',28=>'BESO LIST',
    29=>'ANNOUNCEMENTS',30=>'EMPLOYEE FORGOT PASSWORD'
  ];
  $n = (int)$n;
  return $map[$n] ?? 'UNKNOWN MODULE';
}
function transform_action_made($n){
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',6=>'LOGIN',7=>'LOGOUT',
    8=>'UPDATE_STATUS',9=>'BATCH_ADD',10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  $n = (int)$n;
  return $map[$n] ?? 'UNKNOWN ACTION';
}

function run_count(
    mysqli $db,
    string $baseWhereSql, string $baseTypes, array $baseVals,
    string $extraSql = '', string $extraTypes = '', array $extraVals = []
): int {
    $sql = "SELECT COUNT(*) AS count FROM `cases` WHERE $baseWhereSql $extraSql";
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    $types = $baseTypes . $extraTypes;
    $vals  = array_merge($baseVals, $extraVals);
    bind_params($stmt, $types, $vals);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int)$row['count'] : 0;
}

try {
    /* -----------------------------
       Filters (GET)
    ----------------------------- */
    $start   = $_GET['start_date'] ?? '';
    $end     = $_GET['end_date']   ?? '';
    $offense = trim((string)($_GET['offense'] ?? ''));
    $status  = trim((string)($_GET['status']  ?? 'all'));

    $where = ['1=1'];
    $types = '';
    $vals  = [];

    if ($start && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $where[] = '`date_filed` >= ?';
        $types  .= 's';
        $vals[]  = $start;
    }
    if ($end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $where[] = '`date_filed` <= ?';
        $types  .= 's';
        $vals[]  = $end;
    }
    if ($offense !== '' && strtolower($offense) !== 'all') {
        $where[] = "COALESCE(NULLIF(TRIM(`nature_offense`), ''), 'Unspecified') = ?";
        $types  .= 's';
        $vals[]  = $offense;
    }

    $ONGOING_SET  = ['ongoing','arbitration'];
    $RESOLVED_SET = ['conciliated','mediated','dismissed','withdrawn'];
    $VALID_STATUS = array_merge($ONGOING_SET, $RESOLVED_SET);

    $status_lc = strtolower($status);
    if ($status !== '' && $status_lc !== 'all' && in_array($status_lc, $VALID_STATUS, true)) {
        $where[] = "LOWER(`action_taken`) = ?";
        $types  .= 's';
        $vals[]  = $status_lc;
    }

    $whereSql = implode(' AND ', $where);

    /* -----------------------------
       Counters
    ----------------------------- */
    $totalCases = run_count($mysqli, $whereSql, $types, $vals);

    $ongoingCases = run_count(
        $mysqli, $whereSql, $types, $vals,
        " AND LOWER(`action_taken`) IN (" . in_placeholders(count($ONGOING_SET)) . ")",
        str_repeat('s', count($ONGOING_SET)),
        $ONGOING_SET
    );

    $resolvedCases = run_count(
        $mysqli, $whereSql, $types, $vals,
        " AND LOWER(`action_taken`) IN (" . in_placeholders(count($RESOLVED_SET)) . ")",
        str_repeat('s', count($RESOLVED_SET)),
        $RESOLVED_SET
    );

    $todayFiled = run_count(
        $mysqli, $whereSql, $types, $vals,
        " AND `date_filed` = CURDATE()"
    );

    $upcomingHearings = run_count(
        $mysqli, $whereSql, $types, $vals,
        " AND `date_hearing` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    );

    /* -----------------------------
       Offense distribution
    ----------------------------- */
    $offenseByType = [];
    $sql = "
        SELECT COALESCE(NULLIF(TRIM(`nature_offense`), ''), 'Unspecified') AS label,
               COUNT(*) AS total
          FROM `cases`
         WHERE $whereSql
         GROUP BY label
         ORDER BY total DESC";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $vals);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $offenseByType[$r['label']] = (int)$r['total'];
        }
        $stmt->close();
    }

    /* -----------------------------
       Cases by month (last 6 months incl. current)
    ----------------------------- */
    $casesByMonth = [];
    $sql = "
        SELECT DATE_FORMAT(`date_filed`, '%Y-%m') AS ym,
               COUNT(*) AS total
          FROM `cases`
         WHERE $whereSql
           AND `date_filed` >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym
         ORDER BY ym";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $vals);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $casesByMonth[$r['ym']] = (int)$r['total'];
        }
        $stmt->close();
    }

    /* -----------------------------
       Offense dropdown options (unfiltered)
    ----------------------------- */
    $offenseOptions = [];
    $optRes = $mysqli->query("
        SELECT DISTINCT COALESCE(NULLIF(TRIM(`nature_offense`), ''), 'Unspecified') AS offense
          FROM `cases`
         ORDER BY offense
    ");
    if ($optRes) {
        while ($r = $optRes->fetch_assoc()) {
            $offenseOptions[] = $r['offense'];
        }
    }

    /* -----------------------------
       Recent Activity (current Lupon only)
    ----------------------------- */
    $recentActivities = [];
    $employee_id = (int)($_SESSION['employee_id'] ?? 0); // make sure you set this on login
    if ($employee_id > 0) {
        $aiSql = "
          SELECT
            ai.id,
            ai.logs_name,
            ai.action_made,
            ai.date_created,
            CONCAT(el.employee_fname, ' ', el.employee_lname) AS employee_name
          FROM audit_info ai
          JOIN employee_list  el ON ai.action_by = el.employee_id
          JOIN employee_roles er ON el.Role_Id   = er.Role_Id
          WHERE ai.action_by = ?
            AND LOWER(er.Role_Name) = 'lupon'
          ORDER BY ai.date_created DESC
          LIMIT 10
        ";
        if ($stmt = $mysqli->prepare($aiSql)) {
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

    echo json_encode([
        'totalCases'        => $totalCases,
        'ongoingCases'      => $ongoingCases,
        'resolvedCases'     => $resolvedCases,
        'todayFiled'        => $todayFiled,
        'upcomingHearings'  => $upcomingHearings,
        'offenseByType'     => $offenseByType,
        'casesByMonth'      => $casesByMonth,
        'offenseOptions'    => $offenseOptions,
        'recentActivities'  => $recentActivities, // ← added
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
