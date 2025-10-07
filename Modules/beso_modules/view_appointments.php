<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Graceful 500 page for fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$user_role = $_SESSION['Role_Name'] ?? '';
date_default_timezone_set('Asia/Manila');

/* ============== Pagination + Filters (read) ============== */
$results_per_page = 100; // match reference density
$page  = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum'])) ? max(1, (int)$_GET['pagenum']) : 1;
$offset = ($page - 1) * $results_per_page;

$date_filter   = $_GET['date_filter']   ?? 'today'; // today|this_week|next_week|this_month|this_year
$status_filter = $_GET['status_filter'] ?? '';      // Pending|Approved|Rejected|Released|ApprovedCaptain
$search_term   = trim($_GET['search'] ?? '');

/* ============== Delete (BESO only) ============== */
if (isset($_POST['delete_appointment'], $_POST['tracking_number'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $certificate     = $_POST['certificate'];

    if (strtolower($certificate) === 'beso application') {
        $update_query_urgent = "UPDATE urgent_request SET appointment_delete_status = 1 WHERE tracking_number = ?";
        $update_query_sched  = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";

        $stmt = $mysqli->prepare($update_query_urgent);
        $stmt->bind_param("s", $tracking_number);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt = $mysqli->prepare($update_query_sched);
            $stmt->bind_param("s", $tracking_number);
            $stmt->execute();
        }

        echo "<script>
            Swal.fire({icon:'success',title:'Deleted',text:'The appointment was archived.'})
              .then(()=>{ window.location.href='" . enc_beso('view_appointments') . "'; });
        </script>";
        exit;
    }
}

/* ============== Status update (BESO) ============== */
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number  = $_POST['tracking_number'];
    $new_status       = $_POST['new_status'];
    $certificate      = $_POST['certificate'];
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    if (strtolower($certificate) !== 'beso application') {
        echo "<script>
            Swal.fire({icon:'info',title:'Notice',text:'This endpoint only updates BESO Applications.'})
              .then(()=>history.back());
        </script>";
        exit;
    }

    // Is it from urgent_request?
    $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
    $checkUrgent->bind_param("s", $tracking_number);
    $checkUrgent->execute();
    $checkUrgent->bind_result($isUrgent);
    $checkUrgent->fetch();
    $checkUrgent->close();

    // Gate: Released only after ApprovedCaptain
    $curSql  = ($isUrgent > 0) ? "SELECT status FROM urgent_request WHERE tracking_number = ?" : "SELECT status FROM schedules WHERE tracking_number = ?";
    $curStmt = $mysqli->prepare($curSql);
    $curStmt->bind_param("s", $tracking_number);
    $curStmt->execute();
    $curStmt->bind_result($current_status);
    $curStmt->fetch();
    $curStmt->close();

    if ($new_status === 'Released' && $current_status !== 'ApprovedCaptain') {
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'You can only set "Released" after Captain approval.']);
        } else {
            echo "<script>
                Swal.fire({icon:'warning',title:'Not allowed',text:'Approve by Captain first.'})
                  .then(()=>history.back());
            </script>";
        }
        exit;
    }

    // Perform update
    if ($isUrgent > 0) {
        if ($new_status === 'Rejected') {
            $stmt = $mysqli->prepare("UPDATE urgent_request SET status=?, rejection_reason=?, is_read=0, notif_sent=1 WHERE tracking_number=?");
            $stmt->bind_param("sss", $new_status, $rejection_reason, $tracking_number);
        } else {
            $stmt = $mysqli->prepare("UPDATE urgent_request SET status=?, rejection_reason=NULL, is_read=0, notif_sent=1 WHERE tracking_number=?");
            $stmt->bind_param("ss", $new_status, $tracking_number);
        }
    } else {
        if ($new_status === 'Rejected') {
            $stmt = $mysqli->prepare("UPDATE schedules SET status=?, rejection_reason=?, is_read=0, notif_sent=1 WHERE tracking_number=?");
            $stmt->bind_param("sss", $new_status, $rejection_reason, $tracking_number);
        } else {
            $stmt = $mysqli->prepare("UPDATE schedules SET status=?, rejection_reason=NULL, is_read=0, notif_sent=1 WHERE tracking_number=?");
            $stmt->bind_param("ss", $new_status, $tracking_number);
        }
    }
    $stmt->execute();
    $stmt->close();

    // Log
    require_once './logs/logs_trig.php';
    $trigger  = new Trigger();
    $filename = ($isUrgent > 0) ? 9 : 3;

    $fetchResIDStmt = $mysqli->prepare(($isUrgent > 0)
        ? "SELECT res_id FROM urgent_request WHERE tracking_number = ?"
        : "SELECT res_id FROM schedules WHERE tracking_number = ?");
    $fetchResIDStmt->bind_param("s", $tracking_number);
    $fetchResIDStmt->execute();
    $fetchResIDStmt->bind_result($resident_id);
    $fetchResIDStmt->fetch();
    $fetchResIDStmt->close();

    try { $trigger->isStatusUpdate($filename, $resident_id, $new_status, $tracking_number); } catch (Exception $e) { error_log($e->getMessage()); }

    // Notify (email + SMS)
    $email_query = ($isUrgent > 0)
        ? "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
           FROM urgent_request u JOIN residents r ON u.res_id = r.id WHERE u.tracking_number=?"
        : "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
           FROM schedules s JOIN residents r ON s.res_id = r.id WHERE s.tracking_number=?";

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email->num_rows > 0) {
        $row            = $result_email->fetch_assoc();
        $email          = $row['email'];
        $resident_name  = $row['full_name'];
        $contact_number = $row['contact_number'];

        // Email (move creds to env/config!)
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host        = 'mail.bugoportal.site';
            $mail->SMTPAuth    = true;
            $mail->Username    = 'admin@bugoportal.site';
            $mail->Password    = 'Jayacop@100';
            $mail->Port        = 465;
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout     = 12;
            $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];

            $mail->setFrom('admin@bugoportal.site', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->addReplyTo('admin@bugoportal.site', 'Barangay Office');
            $mail->Sender   = 'admin@bugoportal.site';
            $mail->Hostname = 'bugoportal.site';
            $mail->CharSet  = 'UTF-8';

            $safeName   = htmlspecialchars($resident_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeCert   = htmlspecialchars($certificate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeStatus = htmlspecialchars($new_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $mail->isHTML(true);
            $mail->Subject = 'Appointment Status Update';
            $mail->Body    = "<p>Dear {$safeName},</p><p>Your appointment for <strong>{$safeCert}</strong> is now <strong>{$safeStatus}</strong>.</p><p>Thank you,<br>Barangay Bugo</p>";
            $mail->AltBody = "Dear {$resident_name},\n\nYour {$certificate} appointment is now {$new_status}.\n\nThank you.\nBarangay Bugo";
            $mail->send();
        } catch (Exception $e) { error_log('Email failed: ' . $mail->ErrorInfo); }

        // SMS (Semaphore)
        $apiKey      = 'your_semaphore_api_key';
        $sender      = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query([
            'apikey'     => $apiKey,
            'number'     => $contact_number,
            'message'    => $sms_message,
            'sendername' => $sender
        ]);
        $sms_options = ['http'=>[
            'header'=>"Content-type: application/x-www-form-urlencoded\r\n",
            'method'=>'POST',
            'content'=>$sms_data
        ]];
        $sms_context = stream_context_create($sms_options);
        $sms_result  = @file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);
        if ($sms_result !== FALSE) {
            $sms_response = json_decode($sms_result, true);
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_stmt = $mysqli->prepare("INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?,?,?,?)");
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
        } else { error_log("SMS failed to send to $contact_number"); }
    }

    echo "<script>
      Swal.fire({icon:'success',title:'Status Updated',text:'BESO appointment updated.'})
        .then(()=>{ window.location.href='" . enc_beso('view_appointments') . "'; });
    </script>";
    exit;
}

/* ============== Auto-archive housekeeping (same as reference) ============== */
$mysqli->query("INSERT INTO archived_schedules SELECT * FROM schedules WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("DELETE FROM schedules WHERE status='Released' AND selected_date < CURDATE()");

$mysqli->query("INSERT INTO archived_cedula SELECT * FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("DELETE FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_cedula_request SELECT * FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("DELETE FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_request SELECT * FROM urgent_request WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("DELETE FROM urgent_request WHERE status='Released' AND selected_date < CURDATE()");

$mysqli->query("UPDATE schedules SET appointment_delete_status=1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status=1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status=1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status=1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");

/* ============== Data for header/logos/officials (from reference) ============== */
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status='active'
          AND b.position NOT LIKE '%Lupon%'
          AND b.position NOT LIKE '%Barangay Tanod%'
          AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position,'Punong Barangay','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','SK Chairman','Secretary','Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult && $offresult->num_rows > 0) {
    while ($row = $offresult->fetch_assoc()) {
        $officials[] = ['position'=>$row['position'], 'name'=>$row['first_name'].' '.$row['middle_name'].' '.$row['last_name']];
    }
}

$logo_sql    = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status='active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo        = ($logo_result && $logo_result->num_rows > 0) ? $logo_result->fetch_assoc() : null;

$citySql     = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status='active' LIMIT 1";
$cityResult  = $mysqli->query($citySql);
$cityLogo    = ($cityResult && $cityResult->num_rows > 0) ? $cityResult->fetch_assoc() : null;

$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id=bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id=b.barangay_id
                    WHERE bi.id=1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);
if ($barangayInfoResult && $barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) { $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName); }
    else { $cityMunicipalityName = strtoupper($cityMunicipalityName); }
    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']));
    if (stripos($barangayName, "Barangay") !== false) { $barangayName = strtoupper($barangayName); }
    elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) { $barangayName = "POBLACION " . strtoupper($barangayName); }
    elseif (stripos($barangayName, "Poblacion") !== false) { $barangayName = strtoupper($barangayName); }
    else { $barangayName = "BARANGAY " . strtoupper($barangayName); }
} else { $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND"; $barangayName = "NO BARANGAY FOUND"; }

$councilTermResult = $mysqli->query("SELECT council_term FROM barangay_info WHERE id=1");
$councilTerm = ($councilTermResult && $councilTermResult->num_rows > 0) ? ($councilTermResult->fetch_assoc()['council_term'] ?? '#') : '#';

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
              FROM barangay_information b
              INNER JOIN residents r ON b.official_id = r.id
              WHERE b.status='active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);
$lupon_official = null;
$barangay_tanod_official = null;
if ($lupon_result && $lupon_result->num_rows > 0) {
    while ($lr = $lupon_result->fetch_assoc()) {
        if (stripos($lr['position'], 'Lupon') !== false) { $lupon_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name']; }
        if (stripos($lr['position'], 'Barangay Tanod') !== false || stripos($lr['position'], 'Barangay Police') !== false) {
            $barangay_tanod_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        }
    }
}

$barangayContactResult = $mysqli->query("SELECT telephone_number, mobile_number FROM barangay_info WHERE id=1");
if ($barangayContactResult && $barangayContactResult->num_rows > 0) {
    $contactInfo     = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber    = $contactInfo['mobile_number'];
} else { $telephoneNumber = "No telephone number found"; $mobileNumber="No mobile number found"; }

/* ============== Main Listing (BESO-focused) with server filters (updated like reference) ============== */
$unionSql = "
  SELECT 
    s.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS fullname, 
    s.certificate, 
    s.status,
    s.selected_time,
    s.selected_date,
    r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    s.purpose,
    c.issued_on, c.cedula_number, c.issued_at
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  LEFT JOIN cedula c ON c.res_id = r.id
  WHERE s.appointment_delete_status=0
    AND s.selected_date >= CURDATE()
    AND ('$user_role'!='BESO' OR s.certificate='BESO Application')

  UNION ALL

  SELECT 
    u.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS fullname,
    u.certificate,
    u.status,
    u.selected_time,
    u.selected_date,
    r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    u.purpose,
    COALESCE(c.issued_on, uc.issued_on) AS issued_on,
    COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
    COALESCE(c.issued_at, uc.issued_at) AS issued_at
  FROM urgent_request u
  JOIN residents r ON u.res_id = r.id
  LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status='Approved'
  LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status='Approved'
  WHERE u.urgent_delete_status=0
    AND u.selected_date >= CURDATE()
    AND ('$user_role'!='BESO' OR u.certificate='BESO Application')
";

$whereParts = [];
$types = '';
$params = [];

/* Date filter (reference logic) */
switch ($date_filter) {
  case 'today':
    $whereParts[] = "selected_date = CURDATE()";
    break;
  case 'this_week':
    $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(CURDATE(), 1)";
    break;
  case 'next_week':
    $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1)";
    break;
  case 'this_month':
    $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE()) AND MONTH(selected_date) = MONTH(CURDATE())";
    break;
  case 'this_year':
    $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE())";
    break;
  default:
    /* none */
    break;
}

/* Status filter */
if ($status_filter !== '') {
  $whereParts[] = "status = ?";
  $types       .= 's';
  $params[]     = $status_filter;
}

/* Search (name or tracking) */
if ($search_term !== '') {
  $whereParts[] = "(tracking_number LIKE ? OR fullname LIKE ?)";
  $like   = "%{$search_term}%";
  $types .= 'ss';
  $params[] = $like; 
  $params[] = $like;
}

$whereSql = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

/* Count */
$countSql = "SELECT COUNT(*) AS total FROM ( $unionSql ) AS all_appointments $whereSql";
$stmt = $mysqli->prepare($countSql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total_results = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $results_per_page; }

/* Page data */
$listSql = "
SELECT *
FROM ( $unionSql ) AS all_appointments
$whereSql
GROUP BY tracking_number
ORDER BY
  (status='Pending' AND selected_time='URGENT' AND selected_date=CURDATE()) DESC,
  (status='Pending' AND selected_date=CURDATE()) DESC,
  selected_date ASC, selected_time ASC,
  FIELD(status,'Pending','Approved','Rejected','ApprovedCaptain')
LIMIT ? OFFSET ?";

$typesList  = $types . 'ii';
$paramsList = array_merge($params, [$results_per_page, $offset]);

$stmt = $mysqli->prepare($listSql);
$stmt->bind_param($typesList, ...$paramsList);
$stmt->execute();
$result = $stmt->get_result();

/* ============== Render ============== */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Appointment List</title>

  <!-- Bootstrap + Icons + FA (icons used in pagination) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <!-- SweetAlert2 -->
  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- App styles -->
  <link rel="stylesheet" href="css/styles.css" />
  <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
  <style>
/* Ensure the active item is always readable */
#sameDayAppointments .list-group-item.active {
  background-color: var(--bs-primary) !important;
  color: #fff !important;
}
#sameDayAppointments .list-group-item.active .text-muted {
  color: rgba(255,255,255,.85) !important;
}
</style>

</head>
<body>
  <div class="container my-4 app-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
      <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
    </div>

    <!-- Filter Card (Date + Status + Search) -->
    <div class="card card-filter mb-3 shadow-sm">
      <div class="card-body py-3">
        <!-- Post back to the listing route and keep page param -->
        <form method="GET" action="<?= enc_beso('view_appointments') ?>" class="row g-2 align-items-end">
          <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'view_appointments' ?>" />
          <input type="hidden" name="pagenum" value="1">

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Date</label>
            <select name="date_filter" class="form-select form-select-sm">
              <option value="today"      <?= (($_GET['date_filter'] ?? '')==='today')?'selected':'' ?>>Today</option>
              <option value="this_week"  <?= (($_GET['date_filter'] ?? '')==='this_week')?'selected':'' ?>>This Week</option>
              <option value="next_week"  <?= (($_GET['date_filter'] ?? '')==='next_week')?'selected':'' ?>>Next Week</option>
              <option value="this_month" <?= (($_GET['date_filter'] ?? '')==='this_month')?'selected':'' ?>>This Month</option>
              <option value="this_year"  <?= (($_GET['date_filter'] ?? '')==='this_year')?'selected':'' ?>>This Year</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Status</label>
            <select name="status_filter" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="Pending"         <?= (($_GET['status_filter'] ?? '')==='Pending')?'selected':'' ?>>Pending</option>
              <option value="Approved"        <?= (($_GET['status_filter'] ?? '')==='Approved')?'selected':'' ?>>Approved</option>
              <option value="Rejected"        <?= (($_GET['status_filter'] ?? '')==='Rejected')?'selected':'' ?>>Rejected</option>
              <option value="Released"        <?= (($_GET['status_filter'] ?? '')==='Released')?'selected':'' ?>>Released</option>
              <option value="ApprovedCaptain" <?= (($_GET['status_filter'] ?? '')==='ApprovedCaptain')?'selected':'' ?>>Approved by Captain</option>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label mb-1 fw-semibold">Search</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input
                type="text"
                id="searchInput"
                name="search"
                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                class="form-control"
                placeholder="Search name or tracking number..."
              />
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Table Card -->
    <div class="card shadow-sm">
      <div class="card-body table-shell">
        <div class="table-edge">
          <div class="table-scroll">
            <table class="table table-hover align-middle mb-0" id="appointmentsTable">
              <thead class="table-head sticky-top">
                <tr>
                  <th style="width: 220px;">Full Name</th>
                  <th style="width: 140px;">Certificate</th>
                  <th style="width: 200px;">Tracking Number</th>
                  <th style="width: 160px;">Date</th>
                  <th style="width: 140px;">Time Slot</th>
                  <th style="width: 140px;">Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="appointmentTableBody">
                <?php if ($result && $result->num_rows > 0): ?>
                  <?php while ($row = $result->fetch_assoc()): ?>
                    <?php include 'Modules/beso_modules/appointment_row.php'; ?>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No appointments found</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Windowed Pagination -->
    <?php
      // Build base + preserved query (force page=view_appointments, exclude pagenum)
      $pageBase = 'index_beso_staff.php';
      $params   = $_GET ?? [];
      unset($params['pagenum']);
      $params['page'] = 'view_appointments'; // keep user on the listing, not dashboard

      // Build query string once; we’ll append pagenum to it below
      $qs = '?' . http_build_query($params);

      // Window math
      $window = 7; 
      $half   = (int)floor($window/2);
      $start  = max(1, $page - $half);
      $end    = min($total_pages, $start + $window - 1);
      if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
    ?>
    <nav aria-label="Page navigation" class="mt-3">
      <ul class="pagination justify-content-end pagination-soft mb-0">
        <?php if ($page <= 1): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i></span></li>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i></span></li>
        <?php else: ?>
          <li class="page-item">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>">
              <i class="fa fa-angle-double-left"></i>
            </a>
          </li>
          <li class="page-item">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>">
              <i class="fa fa-angle-left"></i>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($start > 1): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
          <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
          </li>
        <?php endfor; ?>

        <?php if ($end < $total_pages): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>

        <?php if ($page >= $total_pages): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i></span></li>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i></span></li>
        <?php else: ?>
          <li class="page-item">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>">
              <i class="fa fa-angle-right"></i>
            </a>
          </li>
          <li class="page-item">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>">
              <i class="fa fa-angle-double-right"></i>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>

  <!-- View Appointment Modal (enhanced, BESO) -->
  <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content modal-elev rounded-4">
        <div class="modal-header modal-accent rounded-top-4">
          <div>
            <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="viewModalLabel">
              <i class="bi bi-calendar-check-fill"></i>
              Appointment Details
            </h5>
            <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-0">
          <div class="p-4 content-grid">
            <!-- Left: Details -->

            <!-- Same-day list -->
            <section class="card soft-card grid-col-2">
              <div class="card-header soft-card-header">
                <span class="section-title"><i class="bi bi-calendar-week"></i> Appointments on This Day</span>
              </div>
              <div class="card-body p-0">
                <ul id="sameDayAppointments" class="list-group list-group-flush compact-list">
                  <li class="list-group-item">Loading...</li>
                </ul>
              </div>
            </section>

            <!-- Inline Update form -->
            <section class="card soft-card grid-col-2">
              <div class="card-header soft-card-header d-flex justify-content-between align-items-center">
                <span class="section-title"><i class="bi bi-arrow-repeat"></i> Update Status</span>
                <small class="text-muted">Email/SMS will notify the resident</small>
              </div>
              <div class="card-body">
                <form id="statusUpdateFormInline" method="POST" action="">
                  <input type="hidden" name="update_status" value="1">
                  <input type="hidden" id="inlineTrackingNumber" name="tracking_number">
                  <input type="hidden" id="inlineCertificate" name="certificate" value="BESO Application">

                  <div class="row g-3">
                    <div class="col-12 col-md-6">
                      <label class="form-label">New Status</label>
                      <select name="new_status" id="inlineNewStatus" class="form-select" data-current-status="">
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                        <option value="ApprovedCaptain">Approved by Captain</option>
                        <option value="Released">Released</option>
                      </select>
                    </div>

                    <div class="col-12 d-none" id="inlineRejectionGroup">
                      <label class="form-label">Reason for Rejection</label>
                      <textarea name="rejection_reason" id="inlineRejectionReason" class="form-control" rows="2" placeholder="Type reason..."></textarea>
                    </div>
                  </div>

                  <div class="sticky-action mt-3">
                    <button type="submit" class="btn btn-success w-100" id="inlineSaveStatusBtn">
                      <i class="bi bi-check2-circle me-1"></i> Save Status
                    </button>
                  </div>
                </form>
              </div>
            </section>
          </div>
        </div>

        <div class="modal-footer bg-transparent d-flex justify-content-between">
          <small class="text-muted">Tip: “Released” is only allowed after “Approved by Captain”.</small>
          <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>

<!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>-->
           <script src="util/debounce.js"></script>

<script>
/* =========================
   KEEP: Print templates
   ========================= */
function printAppointment(
  certificate,
  fullname,
  res_zone,
  birth_date = "",
  birth_place = "",
  res_street_address = "",
  purpose = "",
  issued_on = "",
  issued_at = "",
  cedula_number = "",
  civil_status = "",
  residency_start = "",
  age = ""
) {
  let printAreaContent = "";

  // Get current date, month, and year
  const today = new Date();
  const day = today.getDate();
  const month = today.toLocaleString("default", { month: "long" }); // Full month name
  const year = today.getFullYear();

  // Function to determine the correct suffix
  function getDaySuffix(day) {
    if (day === 1 || day === 21 || day === 31) {
      return `${day}ˢᵗ`;
    } else if (day === 2 || day === 22) {
      return `${day}ⁿᵈ`;
    } else if (day === 3 || day === 23) {
      return `${day}ʳᵈ`;
    } else {
      return `${day}ᵗʰ`;
    }
  }

  const dayWithSuffix = getDaySuffix(day);

  // Check the certificate type and set the corresponding content
  if (certificate === "Barangay Indigency") {
    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/form.css">
        </head>
        <body>
          <div class="container" id="printArea">
            <header>
              <div class="logo-header"> <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
              <?php else: ?>
                <p>No active Barangay logo found.</p>
              <?php endif; ?>
                <div class="header-text">
                  <h2><strong>Republic of the Philippines</strong></h2>
                  <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                  <h3><strong><?php echo $barangayName; ?></strong></h3>
                  <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                  <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                </div>
              <?php if ($cityLogo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
              <?php else: ?>
                <p>No active City/Municipality logo found.</p>
              <?php endif; ?>
              </div>
            </header>
            <hr class="header-line">
            <section class="barangay-certification">
              <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
              <br>
              <p>TO WHOM IT MAY CONCERN:</p>
              <br>
              <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, a resident of
                <strong>${res_zone}</strong>,  <strong>${res_street_address}</strong>,Bugo, Cagayan de Oro City.</p>
              <br>
              <p>This Certification is issued upon the request of the above-mentioned person
                for <strong>${purpose}</strong> only.</p>
              <br>
              <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
            </section>
            <br><br><br><br><br>
            <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
              <section style="width: 48%; line-height: 1.8;">
                <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                <p><strong>Issued at:</strong> ${issued_at}</p>
              </section>
              <section style="width: -155%; text-align: center; font-size: 25px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  } else if (certificate === "Barangay Residency") {
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";

    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/form.css">
        </head>
        <body>
          <div class="container" id="printArea">
            <header>
              <div class="logo-header">
                <?php if ($logo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
                <?php else: ?>
                  <p>No active Barangay logo found.</p>
                <?php endif; ?>
                <div class="header-text">
                  <h2><strong>Republic of the Philippines</strong></h2>
                  <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                  <h3><strong><?php echo $barangayName; ?></strong></h3>
                  <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                  <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                </div>
                <?php if ($cityLogo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                <?php else: ?>
                  <p>No active City/Municipality logo found.</p>
                <?php endif; ?>
              </div>
            </header>
            <hr class="header-line">
            <section class="barangay-certification">
              <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
              <p>TO WHOM IT MAY CONCERN:</p><br>
              <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of
                <strong>${res_zone}</strong>, <strong>${res_street_address}</strong> Bugo, Cagayan de Oro City. He/She was born on <strong>${formattedBirthDate}</strong> at <strong>${birth_place}</strong>.
                Stayed in Bugo, CDOC since <strong>${formattedResidencyStart}</strong> and up to present.</p>
              <br>
              <p>This Certification is issued upon the request of the above-mentioned person
                for <strong>${purpose}</strong> only.</p>
              <br>
              <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
            </section>
            <br><br><br><br><br>
            <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
              <section style="width: 48%; line-height: 1.8;">
                <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                <p><strong>Issued on:</strong> ${formattedIssuedOn}</p>
                <p><strong>Issued at:</strong> ${issued_at}</p>
              </section>
              <section style="width: 48%; text-align: center; font-size: 25px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  } else if (certificate.toLowerCase() === "beso application") {
    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/form.css">
        </head>
        <body>
          <div class="container" id="printArea">
            <header>
              <div class="logo-header"> <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
              <?php else: ?>
                <p>No active Barangay logo found.</p>
              <?php endif; ?>
                <div class="header-text">
                  <h2><strong>Republic of the Philippines</strong></h2>
                  <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                  <h3><strong><?php echo $barangayName; ?></strong></h3>
                  <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                  <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                </div>
                <?php if ($cityLogo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                <?php else: ?>
                  <p>No active City/Municipality logo found.</p>
                <?php endif; ?>
              </div>
              <hr class="header-line">
            </header>
            <section class="barangay-certification">
              <h4 style="text-align: center; font-size: 50px;"><strong>BARANGAY CERTIFICATION</strong></h4>
              <p style="text-align: center; font-size: 18px; margin-top: -10px;">
                <em>(First Time Jobseeker Assistance Act - RA 11261)</em>
              </p>
              <br>
              <p>This is to certify that <strong><u>${fullname}</u></strong>, ${age} years old is a resident of
                <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>, Bugo, Cagayan de Oro City for <strong>${(() => {
                  const start = new Date(residency_start);
                  const today = new Date();
                  let years = today.getFullYear() - start.getFullYear();
                  const m = today.getMonth() - start.getMonth();
                  if (m < 0 || (m === 0 && today.getDate() < start.getDate())) { years--; }
                  return years + (years === 1 ? " year" : " years");
                })()}</strong>, is <strong>qualified</strong> availee of <strong>RA 11261</strong> or the <strong>First Time Jobseeker act of 2019.</strong>
              </p>
              <p>Further certifies that the holder/bearer was informed of his/her rights, including the duties and responsibilities accorded by RA 11261 through the <strong>OATH UNDERTAKING</strong> he/she has signed and execute in the presence of our Barangay Official.</p>
              <p>This certification is issued upon request of the above-named person for <strong>${purpose}</strong> purposes and is valid only until <strong>${(() => {
                const issuedDate = new Date();
                const validUntil = new Date(issuedDate.setFullYear(issuedDate.getFullYear() + 1));
                return validUntil.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
              })()}</strong>.</p>
              <br>
              <p>Signed this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>,
                at Barangay Bugo, Cagayan de Oro City.
              </p>
            </section>
            <br>
            <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
              <section style="width: 48%; line-height: 1.8;">
                <br><br>
                <p> Not valid without seal</p>
              </section>
              <section style="width: -155%; text-align: center; font-size: 25px; position: relative;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5 style="margin-bottom: 0; padding-bottom: 0; position: relative; z-index: 2;"><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p style="margin-top: 0; padding-top: 0;">Punong Barangay</p>
                <img src="components/employee_modal/show_esignature.php?t=<?=time()?>" alt="Punong Barangay e-Signature"
                  style="position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); width: 150px; height: auto; z-index: 1;">
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  } else if (certificate === "Barangay Clearance") {
    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/clearance.css" alt="Barangay Logo" class="logo">
        </head>
        <body>
          <div class="container" id="printArea">
            <header>
              <div class="logo-header"> <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
              <?php else: ?>
                <p>No active Barangay logo found.</p>
              <?php endif; ?>
                <div class="header-text" style="text-align: center;">
                  <h2><strong>Republic of the Philippines</strong></h2>
                  <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                  <h3><strong><?php echo $barangayName; ?></strong></h3>
                  <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                  <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                </div>
                <?php if ($cityLogo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                <?php else: ?>
                  <p>No active City/Municipality logo found.</p>
                <?php endif; ?>
              </div>
              <section style="text-align: center; margin-top: 10px;">
                <hr class="header-line" style="border: 1px solid black; margin-top: 10px;">
                <h2 style="font-size: 30px;"><strong>BARANGAY CLEARANCE</strong></h2><br>
              </section>
              <section style="display: flex; justify-content: space-between; margin-top: 10px;">
                <div style="flex: 1;"></div>
                <div style="text-align: right; flex: 1;">
                  <p><strong>Control No.</strong> _________________ <br>Series of ${year}</p>
                </div>
              </section>
            </header>

            <div class="side-by-side">
              <div class="left-content">
                <div class="council-box">
                  <h1><?php echo htmlspecialchars($councilTerm); ?><sup>th</sup> COUNCIL</h1><br>
                  <div class="official-title">
                    <?php
                      foreach ($officials as $official) {
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                      }
                    ?>
                  </div>
                </div>
              </div>

              <div class="right-content">
                <p>TO WHOM IT MAY CONCERN:</p>
                <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, legal age, <strong>${civil_status}</strong>.
                Filipino citizen, is a resident of Barangay Bugo, this City, particularly in <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>.</p><br>
                <p>FURTHER CERTIFIES that the above-named person is known to be a person of good moral character and reputation as far as this office is concerned.
                He/She has no pending case filed and blottered before this office.</p><br>
                <p>This certification is being issued upon the request of the above-named person, in connection with his/her desire <strong>${purpose}</strong>.</p><br>
                <p>Given this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p><br>
                <div style="text-align: center; font-size: 15px;">
                  <u><strong>${fullname}</strong></u>
                  <p>AFFIANT SIGNATURE</p>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 70px;">
                  <section style="width: 48%;">
                    <?php if ($lupon_official): ?>
                      <p><strong>As per records (LUPON TAGAPAMAYAPA):</strong></p>
                      <p>Brgy. Case #: ___________________________</p>
                      <p>Certified by: <U><strong><?php echo htmlspecialchars($lupon_official); ?></strong></U></p>
                      <p>Date: <?php echo date('F j, Y'); ?></p>
                    <?php endif; ?>
                  </section>
                  <section style="width: 48%;">
                    <?php if ($barangay_tanod_official): ?>
                      <p><strong>As per records (BARANGAY TANOD):</strong></p>
                      <p>Brgy. Tanod Remarks: _____________________</p>
                      <p>Certified by: <U><strong><?php echo htmlspecialchars($barangay_tanod_official); ?></strong></U></p>
                      <p>Date: <?php echo date('F j, Y'); ?></p>
                    <?php endif; ?>
                  </section>
                </div>
              </div>
            </div>

            <section style="margin-top: 20px; text-align: center;">
              <div style="display: flex; justify-content: left; gap: 20px;">
                <div style="text-align: center; font-size:6px;">
                  <p><strong>Left Thumb:</strong></p>
                  <div style="border: 1px solid black; width: 60px; height: 60px;"></div>
                </div>
                <div style="text-align: center; font-size:6px;">
                  <p><strong>Right Thumb:</strong></p>
                  <div style="border: 1px solid black; width: 60px; height: 60px;"></div>
                </div>
              </div>
            </section>

            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
              <section style="width: 48%; line-height: 1.8%;">
                <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                <p><strong>Issued at:</strong> ${issued_at}</p>
              </section>
              <section style="width: 48%; text-align: center; font-size: 18px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  }

  // Open a new print window with the content
  const printWindow = window.open("", "_blank");
  printWindow.document.write(printAreaContent);
  printWindow.document.close();

  // Wait for the document to load, then print
  printWindow.onload = function () {
    printWindow.print();
  };
}

/* =========================
   View Modal population
   ========================= */
document.querySelectorAll('[data-bs-target="#viewModal"]').forEach((button) => {
  button.addEventListener("click", () => {
    document.getElementById("modal-fullname").textContent = button.dataset.fullname || "";
    document.getElementById("modal-certificate").textContent = button.dataset.certificate || "";
    document.getElementById("modal-tracking-number").textContent = button.dataset.trackingNumber || "";
    document.getElementById("modal-selected-date").textContent = button.dataset.selectedDate || "";
    document.getElementById("modal-selected-time").textContent = button.dataset.selectedTime || "";
    document.getElementById("modal-status").textContent = button.dataset.status || "";
  });
});

/* =========================
   Status Modal (idempotent)
   - keeps your current flow
   - adds soft UI guards from reference
   ========================= */
(function attachStatusModalHandlers() {
  const openers = document.querySelectorAll('[data-bs-target="#statusModal"]');
  const form = document.getElementById("statusUpdateForm");
  const statusSelect = document.getElementById("newStatus");

  // Opening the modal
  openers.forEach((button) => {
    button.addEventListener("click", () => {
      const certificate = button.getAttribute("data-certificate") || "";
      const trackingNumber = button.getAttribute("data-tracking-number") || "";
      const cedulaNumber = button.getAttribute("data-cedula-number") || "";
      const currentStatus = button.getAttribute("data-current-status") || "";

      // Set hidden inputs
      document.getElementById("modalTrackingNumber").value = trackingNumber;
      document.getElementById("modalCertificate").value = certificate;

      // Cedula number field (if present in DOM)
      const cedulaInput = document.getElementById("cedulaNumber");
      if (cedulaInput) cedulaInput.value = cedulaNumber;

      const cedulaContainer = document.getElementById("cedulaNumberContainer");
      const rejectionContainer = document.getElementById("rejectionReasonContainer");

      // NEW: UI guard—disable "Released" unless already ApprovedCaptain
      const releasedOpt = statusSelect.querySelector('option[value="Released"]');
      if (releasedOpt) {
        const allowRelease = currentStatus === "ApprovedCaptain";
        releasedOpt.disabled = !allowRelease;
        if (!allowRelease && statusSelect.value === "Released") {
          statusSelect.value = "Pending";
        }
      }

      // OPTIONAL gentle guard like the reference (no server change)
      // If process already advanced, avoid backtracking in UI
      const pendOpt = statusSelect.querySelector('option[value="Pending"]');
      const apprOpt = statusSelect.querySelector('option[value="Approved"]');
      if (pendOpt) pendOpt.disabled = ["Approved", "ApprovedCaptain", "Released"].includes(currentStatus);
      if (apprOpt) apprOpt.disabled = ["ApprovedCaptain", "Released"].includes(currentStatus);

      const checkStatus = () => {
        // Show Cedula Number if Cedula & Released
        if (cedulaContainer) {
          if (certificate.toLowerCase() === "cedula" && statusSelect.value === "Released") {
            cedulaContainer.style.display = "block";
          } else {
            cedulaContainer.style.display = "none";
          }
        }

        // Show rejection reason if Rejected is selected
        if (rejectionContainer) {
          rejectionContainer.style.display = statusSelect.value === "Rejected" ? "block" : "none";
        }
      };

      // ensure idempotent
      statusSelect.removeEventListener("change", checkStatus);
      statusSelect.addEventListener("change", checkStatus);
      checkStatus();
    });
  });

  // Submit (Ajax) — SAME endpoint/process; adds XHR header for PHP gate
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      const formData = new FormData(form);
      formData.append("update_status", "1"); // ensure flag present

      Swal.fire({
        title: "Updating...",
        html: "Please wait while we update the appointment.",
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
      });

      fetch("", {
        method: "POST",
        body: formData,
        headers: { "X-Requested-With": "XMLHttpRequest" }, // let PHP detect AJAX
      })
        .then(async (res) => {
          const text = await res.text();
          // Try JSON first (in case server returns {ok:false,...})
          try {
            return JSON.parse(text);
          } catch {
            return { ok: true, _html: text };
          }
        })
        .then(() => {
          Swal.fire({ icon: "success", title: "Status Updated", text: "Status updated and email sent!" })
            .then(() => location.reload());
        })
        .catch((err) => {
          console.error("Update error:", err);
          Swal.fire({ icon: "error", title: "Error", text: "Something went wrong while updating the status." });
        });
    });
  }
})();

/* =========================
   Styling: turn raw status
   text into soft badges
   (purely visual)
   ========================= */
(function applyStatusBadges() {
  const map = {
    pending: "badge-soft-warning",
    approved: "badge-soft-info",
    approvedcaptain: "badge-soft-primary",
    rejected: "badge-soft-danger",
    released: "badge-soft-success",
  };
  // 6th column holds Status in your table
  document.querySelectorAll("#appointmentTableBody tr").forEach((tr) => {
    const td = tr.children[5];
    if (!td) return;
    const raw = (td.textContent || "").trim();
    const key = raw.toLowerCase().replace(/\s+/g, "");
    if (raw && !td.querySelector(".badge")) {
      const b = document.createElement("span");
      b.className = "badge " + (map[key] || "badge-soft-secondary");
      b.textContent = raw;
      td.textContent = "";
      td.appendChild(b);
    }
  });
})();

function normalizeDate(s) {
  if (!s) return '';
  // Already ISO?
  const iso = s.match(/^\d{4}-\d{2}-\d{2}$/);
  if (iso) return s;

  // Try Date.parse; if valid, convert to ISO Y-M-D
  const d = new Date(s);
  if (!isNaN(d)) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }
  // Fallback: strip time, keep first 10 chars if they look like date
  return String(s).slice(0, 10);
}

// pick first available dataset key
function pick(ds, keys, fb='') { for (const k of keys) { if (ds[k]) return ds[k]; } return fb; }

// Open the modal, seed inline form, and build “Appointments on This Day”
document.addEventListener('click', function (e) {
  // Works with either data-bs-target="#viewModal" or data-action="view"
  const btn = e.target.closest('[data-bs-target="#viewModal"], [data-action="view"]');
  if (!btn) return;

  const d            = btn.dataset;
  const rawDate      = pick(d, ['selectedDate','date','selected_date'], '');
  const selectedDate = normalizeDate(rawDate);
  const tracking     = pick(d, ['trackingNumber','tracking','tn'], '');
  const certificate  = pick(d, ['certificate'], 'BESO Application');
  const status       = pick(d, ['status'], 'Pending');

  // Seed inline status form
  const tnEl = document.getElementById('inlineTrackingNumber');  if (tnEl) tnEl.value = tracking;
  const ctEl = document.getElementById('inlineCertificate');     if (ctEl) ctEl.value = certificate;
  const sel  = document.getElementById('inlineNewStatus');
  if (sel) {
    sel.value = status || 'Pending';
    const relOpt = sel.querySelector('option[value="Released"]');
    if (relOpt) relOpt.disabled = (status !== 'ApprovedCaptain');
    toggleRejectionInline();
  }

  // Build same-day list
  const ul = document.getElementById('sameDayAppointments');
  if (ul) {
    ul.innerHTML = '';
    let count = 0;

    // scan all “view” buttons on the page
    document.querySelectorAll('[data-bs-target="#viewModal"], [data-action="view"]').forEach(b => {
      const bd        = b.dataset;
      const bDateNorm = normalizeDate(pick(bd, ['selectedDate','date','selected_date'], ''));
      if (bDateNorm !== selectedDate) return;

      const name   = pick(bd, ['fullname','name'], '—');
      const time   = pick(bd, ['selectedTime','time'], '');
      const stat   = pick(bd, ['status'], '');
      const track  = pick(bd, ['trackingNumber','tracking','tn'], '');

      const li = document.createElement('li');
      li.className = 'list-group-item d-flex justify-content-between align-items-center'
                   + (track === tracking ? ' active' : '');
      li.innerHTML = `<span>${name}</span><span class="text-muted">${time}${time && stat ? ' • ' : ''}${stat}</span>`;
      ul.appendChild(li);
      count++;
    });

    if (!count) {
      ul.innerHTML = `<li class="list-group-item text-muted">No other appointments for this day.</li>`;
    }
  }

  // Show modal
const viewEl = document.getElementById('viewModal');
bootstrap.Modal.getOrCreateInstance(viewEl).show();

});

// Inline rejection textarea toggle
function toggleRejectionInline() {
  const sel = document.getElementById('inlineNewStatus');
  const grp = document.getElementById('inlineRejectionGroup');
  if (!sel || !grp) return;
  grp.classList.toggle('d-none', sel.value !== 'Rejected');
}
document.getElementById('inlineNewStatus')?.addEventListener('change', toggleRejectionInline);
</script>


            
        </body>
        </html>
