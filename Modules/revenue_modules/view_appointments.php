<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');

$user_role   = $_SESSION['Role_Name'] ?? '';
$user_id     = $_SESSION['user_id'] ?? 0;        // used for duplicate checks
$employee_id = $_SESSION['employee_id'] ?? null; // used when updating status

/* ---------------- Pagination + Filters (request) ---------------- */
$results_per_page = 100;
$page  = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? max(1, (int)$_GET['pagenum']) : 1;

$date_filter   = $_GET['date_filter']   ?? 'today'; // today|this_week|next_week|this_month|this_year
$status_filter = $_GET['status_filter'] ?? '';      // Pending|Approved|Rejected|Released|ApprovedCaptain
$search_term   = trim($_GET['search'] ?? '');       // name or tracking

/* ---------------- Delete = soft-archive by flag ---------------- */
if (isset($_POST['delete_appointment'], $_POST['tracking_number'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $certificate     = $_POST['certificate'];

    if (strtolower($certificate) === 'cedula') {
        $update_query = "UPDATE cedula SET cedula_delete_status = 1 WHERE tracking_number = ?";
    } else {
        $update_query = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";
    }

    $stmt_update = $mysqli->prepare($update_query);
    $stmt_update->bind_param("s", $tracking_number);
    $stmt_update->execute();
    $stmt_update->close();

    echo "<script>
        alert('Appointment archived.');
        window.location = '" . enc_page('view_appointments') . "';
    </script>";
    exit;
}

/* ---------------- Status update (with duplicate cedula check) ---------------- */
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number  = $_POST['tracking_number'];
    $new_status       = $_POST['new_status'];
    $certificate      = $_POST['certificate'];
    $cedula_number    = trim($_POST['cedula_number'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    // Is this an urgent cedula?
    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($isUrgentCedula);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();

    // Uniqueness check for cedula number when approving
    if (($isUrgentCedula > 0 || $certificate === 'Cedula') && $new_status === 'Approved' && !empty($cedula_number)) {
        $checkDup = $mysqli->prepare("
            SELECT COUNT(*) FROM (
                SELECT cedula_number FROM urgent_cedula_request WHERE cedula_number = ? AND res_id != ?
                UNION ALL
                SELECT cedula_number FROM cedula WHERE cedula_number = ? AND res_id != ?
            ) AS all_cedulas
        ");
        $checkDup->bind_param("sisi", $cedula_number, $user_id, $cedula_number, $user_id);
        $checkDup->execute();
        $checkDup->bind_result($dupCount);
        $checkDup->fetch();
        $checkDup->close();

        if ($dupCount > 0) {
            echo "<script>alert('❌ Cedula number already exists for another resident. Please enter a unique Cedula number.'); history.back();</script>";
            exit;
        }
    }

    // Update the correct source table
    if ($isUrgentCedula > 0) {
        if ($new_status === 'Rejected') {
            $query = "UPDATE urgent_cedula_request 
                      SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE urgent_cedula_request 
                      SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }
    } elseif ($certificate === 'Cedula') {
        if ($new_status === 'Rejected') {
            $query = "UPDATE cedula 
                      SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE cedula 
                      SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }
    } else {
        // urgent non-cedula?
        $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgent->bind_param("s", $tracking_number);
        $checkUrgent->execute();
        $checkUrgent->bind_result($isUrgent);
        $checkUrgent->fetch();
        $checkUrgent->close();

        if ($isUrgent > 0) {
            if ($new_status === 'Rejected') {
                $query = "UPDATE urgent_request 
                          SET status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE urgent_request 
                          SET status = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }
        } else {
            if ($new_status === 'Rejected') {
                $query = "UPDATE schedules 
                          SET status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE schedules 
                          SET status = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }
        }
    }

    $stmt->execute();
    $stmt->close();

    /* --------- Fetch resident contact (source-aware) --------- */
    $isUrgentCedula = false;
    $isUrgentSchedule = false;

    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($urgentCedulaCount);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();
    if ($urgentCedulaCount > 0) { $isUrgentCedula = true; }

    if (!$isUrgentCedula) {
        $checkUrgentSchedule = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgentSchedule->bind_param("s", $tracking_number);
        $checkUrgentSchedule->execute();
        $checkUrgentSchedule->bind_result($urgentScheduleCount);
        $checkUrgentSchedule->fetch();
        $checkUrgentSchedule->close();
        if ($urgentScheduleCount > 0) { $isUrgentSchedule = true; }
    }

    if ($isUrgentCedula) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM urgent_cedula_request u JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } elseif ($certificate === 'Cedula') {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM cedula c JOIN residents r ON c.res_id = r.id
                        WHERE c.tracking_number = ?";
    } elseif ($isUrgentSchedule) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM urgent_request u JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } else {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM schedules s JOIN residents r ON s.res_id = r.id
                        WHERE s.tracking_number = ?";
    }

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email && $result_email->num_rows > 0) {
        $rowe = $result_email->fetch_assoc();
        $email          = $rowe['email'];
        $resident_name  = $rowe['full_name'];
        $contact_number = $rowe['contact_number'];

        // Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'jayacop9@gmail.com';
            $mail->Password = 'nyiq ulrn sbhz chcd';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('jayacop9@gmail.com', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->Subject = 'Appointment Status Update';
            $mail->Body = "Dear $resident_name,\n\nYour appointment for \"$certificate\" has been updated to \"$new_status\".\n\nThank you.\nBarangay Office";
            $mail->send();
        } catch (Exception $e) {
            error_log("Email failed: " . $mail->ErrorInfo);
        }

        // SMS via Semaphore
        $apiKey = 'your_semaphore_api_key';
        $sender = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query([
            'apikey' => $apiKey,
            'number' => $contact_number,
            'message' => $sms_message,
            'sendername' => $sender
        ]);
        $sms_options = ['http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $sms_data,
        ]];
        $sms_context = stream_context_create($sms_options);
        $sms_result  = @file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);

        if ($sms_result !== FALSE) {
            $sms_response = json_decode($sms_result, true);
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_query = "INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?, ?, ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            error_log("❌ SMS failed to send to $contact_number");
        }
    }

    echo "<script>
        alert('Status updated to $new_status');
        window.location = '" . enc_revenue('view_appointments') . "';
    </script>";
    exit;
}

/* ---------------- Auto-archive housekeeping (unchanged) ---------------- */
$mysqli->query("INSERT INTO archived_schedules SELECT * FROM schedules WHERE status='Released' AND selected_date<CURDATE()");
$mysqli->query("DELETE FROM schedules WHERE status='Released' AND selected_date<CURDATE()");

$mysqli->query("INSERT INTO archived_cedula SELECT * FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");
$mysqli->query("DELETE FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_cedula_request SELECT * FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");
$mysqli->query("DELETE FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_request SELECT * FROM urgent_request WHERE status='Released' AND selected_date<CURDATE()");
$mysqli->query("DELETE FROM urgent_request WHERE status='Released' AND selected_date<CURDATE()");

$mysqli->query("UPDATE schedules SET appointment_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");

/* ---------------- UNION (ALL) + shared WHERE for list & count ---------------- */
$unionSql = "
  SELECT 
    ucr.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    'Cedula' AS certificate,
    ucr.cedula_status AS status,
    ucr.appointment_time AS selected_time,
    ucr.appointment_date AS selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    'Cedula Application (Urgent)' AS purpose,
    ucr.issued_on, ucr.cedula_number, ucr.issued_at,
    ucr.income AS cedula_income
  FROM urgent_cedula_request ucr
  JOIN residents r ON ucr.res_id = r.id
  WHERE ucr.cedula_delete_status = 0 AND ucr.appointment_date >= CURDATE()

  UNION ALL

  SELECT 
    s.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    s.certificate, s.status, s.selected_time, s.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    s.purpose,
    c.issued_on, c.cedula_number, c.issued_at,
    c.income AS cedula_income
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  LEFT JOIN cedula c ON c.res_id = r.id
  WHERE s.appointment_delete_status = 0 AND s.selected_date >= CURDATE()
    AND s.certificate != 'BESO Application'

  UNION ALL

  SELECT 
    c.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    'Cedula' AS certificate,
    c.cedula_status AS status,
    c.appointment_time AS selected_time,
    c.appointment_date AS selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    'Cedula Application' AS purpose,
    c.issued_on, c.cedula_number, c.issued_at,
    c.income AS cedula_income
  FROM cedula c
  JOIN residents r ON c.res_id = r.id
  WHERE c.cedula_delete_status = 0 AND c.appointment_date >= CURDATE()

  UNION ALL

  SELECT 
    u.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    u.certificate, u.status, u.selected_time, u.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    u.purpose,
    COALESCE(c.issued_on, uc.issued_on) AS issued_on,
    COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
    COALESCE(c.issued_at, uc.issued_at) AS issued_at,
    COALESCE(c.income, uc.income) AS cedula_income
  FROM urgent_request u
  JOIN residents r ON u.res_id = r.id
  LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status = 'Approved'
  LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
  WHERE u.urgent_delete_status = 0 AND u.selected_date >= CURDATE()
    AND u.certificate != 'BESO Application'
";

/* Build WHERE for both count & list */
$whereParts = [];
$types = '';
$vals  = [];

switch ($date_filter) {
  case 'today':
    $whereParts[] = "selected_date = CURDATE()";
    break;
  case 'this_week':
    $whereParts[] = "YEARWEEK(selected_date,1) = YEARWEEK(CURDATE(),1)";
    break;
  case 'next_week':
    $whereParts[] = "YEARWEEK(selected_date,1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK),1)";
    break;
  case 'this_month':
    $whereParts[] = "YEAR(selected_date)=YEAR(CURDATE()) AND MONTH(selected_date)=MONTH(CURDATE())";
    break;
  case 'this_year':
    $whereParts[] = "YEAR(selected_date)=YEAR(CURDATE())";
    break;
}

if ($status_filter !== '') {
  $whereParts[] = "status = ?";
  $types .= 's';
  $vals[]  = $status_filter;
}

if ($search_term !== '') {
  $whereParts[] = "(tracking_number LIKE ? OR fullname LIKE ?)";
  $types .= 'ss';
  $like = "%$search_term%";
  $vals[] = $like;
  $vals[] = $like;
}

$whereSql = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

/* ---------------- Count (dedup by tracking_number) ---------------- */
$countSql = "
  SELECT COUNT(*) AS total
  FROM (
    SELECT tracking_number
    FROM ( $unionSql ) base
    $whereSql
    GROUP BY tracking_number
  ) t
";
$stmt = $mysqli->prepare($countSql);
if ($types !== '') { $stmt->bind_param($types, ...$vals); }
$stmt->execute();
$total_results = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($page - 1) * $results_per_page;

/* ---------------- List page (same filters; dedup; order; limit) ---------------- */
$listSql = "
  SELECT *
  FROM (
    SELECT *
    FROM ( $unionSql ) base
    $whereSql
    GROUP BY tracking_number
  ) all_appointments
  ORDER BY
    (status='Pending' AND selected_time='URGENT' AND selected_date=CURDATE()) DESC,
    (status='Pending' AND selected_date=CURDATE()) DESC,
    selected_date ASC, selected_time ASC,
    FIELD(status,'Pending','Approved','Rejected')
  LIMIT ? OFFSET ?
";
$stmt = $mysqli->prepare($listSql);
$bindTypes = $types . 'ii';
$bindVals  = array_merge($vals, [ $results_per_page, $offset ]);
$stmt->bind_param($bindTypes, ...$bindVals);
$stmt->execute();
$result = $stmt->get_result();

$filtered_appointments = [];
while ($row = $result->fetch_assoc()) {
    $filtered_appointments[] = $row;
}
$stmt->close();

/* -------------- The rest of your officials/logos/info queries (unchanged) -------------- */
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name, b.status
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status = 'active' 
          AND b.position NOT LIKE '%Lupon%'
          AND b.position NOT LIKE '%Barangay Tanod%'
          AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position,'Punong Barangay','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad',
                       'Kagawad','SK Chairman','Secretary','Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult && $offresult->num_rows > 0) {
    while ($row = $offresult->fetch_assoc()) {
        $officials[] = [
            'position' => $row['position'],
            'name'     => $row['first_name'].' '.$row['middle_name'].' '.$row['last_name']
        ];
    }
}

$logo_sql   = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status='active' LIMIT 1";
$logo       = ($lr = $mysqli->query($logo_sql)) && $lr->num_rows > 0 ? $lr->fetch_assoc() : null;

$citySql    = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status='active' LIMIT 1";
$cityLogo   = ($cr = $mysqli->query($citySql)) && $cr->num_rows > 0 ? $cr->fetch_assoc() : null;

$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);
if ($barangayInfoResult && $barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) {
        $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName);
    } else { $cityMunicipalityName = strtoupper($cityMunicipalityName); }

    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']));
    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = strtoupper($barangayName);
    } elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "POBLACION " . strtoupper($barangayName);
    } elseif (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = strtoupper($barangayName);
    } else {
        $barangayName = "BARANGAY " . strtoupper($barangayName);
    }
} else {
    $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND";
    $barangayName = "NO BARANGAY FOUND";
}

$councilTermSql = "SELECT council_term FROM barangay_info WHERE id = 1";
$councilTermResult = $mysqli->query($councilTermSql);
$councilTerm = ($councilTermResult && $councilTermResult->num_rows > 0)
    ? ($councilTermResult->fetch_assoc()['council_term'] ?? '#') : '#';

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
              FROM barangay_information b
              INNER JOIN residents r ON b.official_id = r.id
              WHERE b.status='active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);
$lupon_official = null; $barangay_tanod_official = null;
if ($lupon_result && $lupon_result->num_rows > 0) {
    while ($lr = $lupon_result->fetch_assoc()) {
        if (stripos($lr['position'], 'Lupon') !== false) $lupon_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        if (stripos($lr['position'], 'Barangay Tanod') !== false || stripos($lr['position'], 'Barangay Police') !== false)
            $barangay_tanod_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
    }
}

$barangayContactSql = "SELECT telephone_number, mobile_number FROM barangay_info WHERE id = 1";
$barangayContactResult = $mysqli->query($barangayContactSql);
if ($barangayContactResult && $barangayContactResult->num_rows > 0) {
    $contactInfo     = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber    = $contactInfo['mobile_number'];
} else {
    $telephoneNumber = "No telephone number found";
    $mobileNumber    = "No mobile number found";
}

/* -------- $filtered_appointments now holds the rows to render.
           $total_pages is consistent; hide pagination if $total_pages == 1 -------- */

?>


    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Appointment List</title>
        
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="css/styles.css">
        <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
                        <!-- SweetAlert2 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <div class="container my-4 app-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
      <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
    </div>

    <!-- Filters -->
    <div class="card card-filter mb-3 shadow-sm">
      <div class="card-body py-3">
        <form method="GET" action="index_revenue_staff.php" class="row g-2 align-items-end">
          <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'view_appointments' ?>" />

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Date</label>
            <select name="date_filter" class="form-select form-select-sm">
              <option value="today" <?= ($_GET['date_filter'] ?? '') == 'today' ? 'selected' : '' ?>>Today</option>
              <option value="this_week" <?= ($_GET['date_filter'] ?? '') == 'this_week' ? 'selected' : '' ?>>This Week</option>
              <option value="next_week" <?= ($_GET['date_filter'] ?? '') == 'next_week' ? 'selected' : '' ?>>Next Week</option>
              <option value="this_month" <?= ($_GET['date_filter'] ?? '') == 'this_month' ? 'selected' : '' ?>>This Month</option>
              <option value="this_year" <?= ($_GET['date_filter'] ?? '') == 'this_year' ? 'selected' : '' ?>>This Year</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Status</label>
            <select name="status_filter" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="Pending" <?= ($_GET['status_filter'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
              <option value="Approved" <?= ($_GET['status_filter'] ?? '') == 'Approved' ? 'selected' : '' ?>>Approved</option>
              <option value="Rejected" <?= ($_GET['status_filter'] ?? '') == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
              <option value="Released" <?= ($_GET['status_filter'] ?? '') == 'Released' ? 'selected' : '' ?>>Released</option>
              <option value="ApprovedCaptain" <?= ($_GET['status_filter'] ?? '') == 'ApprovedCaptain' ? 'selected' : '' ?>>Approved by Captain</option>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label mb-1 fw-semibold">Search</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" id="searchInput" class="form-control" placeholder="Search name or tracking number..." />
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </div>
        </form>
      </div>
    </div>

<div class="card shadow-sm">
  <div class="card-body table-shell">
    <div class="table-edge">               <!-- keeps rounded corners -->
      <div class="table-scroll">           <!-- becomes the scroller -->
        <table class="table table-hover align-middle mb-0" id="appointmentsTable">
          <thead class="table-head sticky-top">
            <tr>
              <th style="width: 200px;">Full Name</th>
              <th style="width: 100px;">Certificate</th>
              <th style="width: 200px;">Tracking Number</th>
              <th style="width: 200px;">Date</th>
              <th style="width: 200px;">Time Slot</th>
              <th style="width: 200px;">Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody id="appointmentTableBody">
<?php
if (!empty($filtered_appointments)):
    foreach ($filtered_appointments as $row):

        // ❌  Skip BESO if the user is Revenue Staff
        if (stripos($user_role, 'revenue') !== false &&
            $row['certificate'] === 'BESO Application') {
            continue;
        }

        // Re‑use your existing row template
        include 'components/appointment_row.php';

    endforeach;
else: ?>
    <tr>
        <td colspan="7" class="text-center">No appointments found</td>
    </tr>
<?php endif; ?>
</tbody>
        </table>
      </div>
    </div>
  </div>
</div>
 <!-- Windowed Pagination -->
  <?php
    // Build preserved query string excluding pagenum
    $pageBase = enc_revenue('view_appointments');
    $params = $_GET; unset($params['pagenum']);
    $qs = '';
    if (!empty($params)) {
      $pairs = [];
      foreach ($params as $k => $v) {
        if (is_array($v)) { foreach ($v as $vv) $pairs[] = urlencode($k).'='.urlencode($vv); }
        else { $pairs[] = urlencode($k).'='.urlencode($v ?? ''); }
      }
      $qs = '&'.implode('&', $pairs);
    }

    $window = 7;
    $half   = (int)floor($window/2);
    $start  = max(1, $page - $half);
    $end    = min($total_pages, $start + $window - 1);
    if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
  ?>

  <nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-end pagination-soft mb-0">

      <!-- First -->
      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>" aria-label="First">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Previous -->
      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>" aria-label="Previous">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Left ellipsis -->
      <?php if ($start > 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <!-- Windowed numbers -->
      <?php for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
        </li>
      <?php endfor; ?>

      <!-- Right ellipsis -->
      <?php if ($end < $total_pages): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <!-- Next -->
      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>" aria-label="Next">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Last -->
      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>" aria-label="Last">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </a>
        </li>
      <?php endif; ?>

    </ul>
  </nav>
  <!-- View Appointment Modal (enhanced) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"> <!-- wider modal -->
    <div class="modal-content modal-elev rounded-4">
      <div class="modal-header modal-accent rounded-top-4">
        <div>
          <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="viewModalLabel">
            <i class="bi bi-calendar-check-fill"></i>
            Appointment Details
          </h5>
          <!-- optional: show tracking/status live -->
          <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0">
        <!-- Top summary strip -->
        <!--<div class="summary-bar px-4 py-3 d-flex flex-wrap gap-3 align-items-center">-->
        <!--  <div class="d-flex align-items-center gap-2">-->
        <!--    <i class="bi bi-person-badge"></i>-->
        <!--    <span class="fw-semibold" id="viewFullname">—</span>-->
        <!--  </div>-->
        <!--  <div class="vr"></div>-->
        <!--  <div class="d-flex align-items-center gap-2">-->
        <!--    <i class="bi bi-award"></i>-->
        <!--    <span id="viewCertificate">—</span>-->
        <!--  </div>-->
        <!--  <div class="vr"></div>-->
        <!--  <div class="d-flex align-items-center gap-2">-->
        <!--    <i class="bi bi-hash"></i>-->
        <!--    <span id="viewTracking">—</span>-->
        <!--  </div>-->
        <!--  <div class="ms-auto d-flex align-items-center gap-2">-->
        <!--    <span class="badge" id="viewStatusBadge">—</span>-->
        <!--  </div>-->
        <!--</div>-->

        <!-- Content grid -->
        <div class="p-4 content-grid">
          <!-- Left: details -->
          <!--<section class="card soft-card">-->
          <!--  <div class="card-header soft-card-header">-->
          <!--    <span class="section-title"><i class="bi bi-info-circle"></i> Details</span>-->
          <!--  </div>-->
          <!--  <div class="card-body">-->
          <!--    <div class="info-grid">-->
          <!--      <div><span class="label">Date</span><span id="viewDate">—</span></div>-->
          <!--      <div><span class="label">Time Slot</span><span id="viewTime">—</span></div>-->
          <!--      <div><span class="label">Zone</span><span id="viewZone">—</span></div>-->
          <!--      <div><span class="label">Address</span><span id="viewAddress">—</span></div>-->
          <!--      <div class="grid-col-2"><span class="label">Purpose</span><span id="viewPurpose">—</span></div>-->
          <!--    </div>-->
          <!--  </div>-->
          <!--</section>-->

          <!-- Right: case history + same day -->
          <section class="card soft-card">
            <div class="card-header soft-card-header">
              <span class="section-title"><i class="bi bi-journal-check"></i> Case History</span>
            </div>
            <div class="card-body p-0">
              <div id="caseHistoryContainer" class="timeline-wrap">
                <p class="text-muted px-3 py-2 mb-0">No case history loaded...</p>
              </div>
            </div>
          </section>

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

          <!-- Update form -->
          <section class="card soft-card grid-col-2">
            <div class="card-header soft-card-header d-flex justify-content-between align-items-center">
              <span class="section-title"><i class="bi bi-arrow-repeat"></i> Update Status</span>
              <small class="text-muted">Changes notify via Email/System Notification</small>
            </div>
            <div class="card-body">
              <form id="statusUpdateForm" data-current-status="">
                <input type="hidden" id="statusTrackingNumber" name="tracking_number">

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label">New Status</label>
                    <select name="new_status" id="statusSelect" class="form-select">
                      <option value="Pending">Pending</option>
                      <option value="Approved">Approved</option>
                      <option value="Rejected">Rejected</option>
                      <option value="Released">Released</option>
                      <!--<option value="ApprovedCaptain">Approved by Captain</option>-->
                    </select>
                  </div>

                <div class="col-12 col-md-6 d-none" id="viewCedulaNumberContainer">
                  <label for="viewCedulaNumber" class="form-label">Cedula Number</label>
                  <input type="text" name="cedula_number" id="viewCedulaNumber" class="form-control" placeholder="Enter Cedula Number">
                </div>
                
                <div class="col-12 d-none" id="viewRejectionReasonGroup">
                  <label class="form-label">Reason for Rejection</label>
                  <textarea name="rejection_reason" id="viewRejectionReason" class="form-control" rows="2" placeholder="Type reason..."></textarea>
                </div>

                  <div class="col-12">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" value="1" id="applyAllSameDay" name="apply_all_same_day">
                      <label class="form-check-label" for="applyAllSameDay">
                        Apply status to all appointments of this resident on the same day
                      </label>
                    </div>
                  </div>
                </div>

                <div class="sticky-action mt-3">
                  <button type="submit" class="btn btn-success w-100" id="saveStatusBtn">
                    <i class="bi bi-check2-circle me-1"></i> Save Status
                  </button>
                </div>
              </form>
            </div>
          </section>
        </div>
      </div>

      <div class="modal-footer bg-transparent d-flex justify-content-between">
        <small class="text-muted">Tip: You can only release after “Approved by Captain”.</small>
        <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>
  <!-- Status Change Modal -->
  <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <form method="POST" action="">
        <div class="modal-content rounded-4 shadow">
          <div class="modal-header text-white rounded-top-4">
            <h5 class="modal-title" id="statusModalLabel">🛠️ Change Status</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body bg-light">
            <input type="hidden" name="tracking_number" id="modalTrackingNumber">
            <input type="hidden" name="certificate" id="modalCertificate">

            <div class="mb-3">
              <label for="newStatus" class="form-label fw-semibold">New Status</label>
              <select name="new_status" id="newStatus" class="form-select rounded-3 shadow-sm" data-current-status="">
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="Released">Released</option>
                <!--<option value="ApprovedCaptain">ApprovedCaptain</option>-->
              </select>
            </div>

        <div class="mb-3" id="statusModalCedulaNumberContainer" style="display:none;">
          <label for="statusModalCedulaNumber" class="form-label fw-semibold">Cedula Number</label>
          <input type="text" name="cedula_number" id="statusModalCedulaNumber" class="form-control shadow-sm rounded-3" placeholder="Enter Cedula Number">
        </div>
        
        <div class="mb-3" id="statusModalRejectionReasonContainer" style="display:none;">
          <label for="statusModalRejectionReason" class="form-label fw-semibold">Rejection Reason</label>
          <textarea class="form-control shadow-sm rounded-3" name="rejection_reason" id="statusModalRejectionReason" rows="2" placeholder="State reason for rejection..."></textarea>
        </div>
          </div>
          <div class="modal-footer bg-light rounded-bottom-4">
            <button type="submit" name="update_status" class="btn btn-success w-100 rounded-pill shadow-sm">
              Update
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

        <script src="util/debounce.js"></script>

        <script>


    function printAppointment(certificate, fullname, res_zone, birth_date = "", birth_place = "", res_street_address = "", purpose = "", issued_on ="", issued_at = "", cedula_number = "", civil_status = "", residency_start = "", age= "") {
        let printAreaContent = "";

    // Get current date, month, and year
    const today = new Date();
        const day = today.getDate();
        const month = today.toLocaleString('default', { month: 'long' }); // Full month name
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
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
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
                            <br>
                            <br>
                            <br>
                            <br>
                            <br>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                    <section style="width: 48%; line-height: 1.8;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
<section style="width: -155%; text-align: center; font-size: 25px; position: relative;">
    <?php
        // Find the Punong Barangay from the $officials array
        $punong_barangay = null;
        foreach ($officials as $official) {
            if ($official['position'] == 'Punong Barangay') {
                $punong_barangay = $official['name'];
                break;
            }
        }
    ?>
    <!-- Adjust the margin to reduce space between name and title, increase font size -->
    <h5 style="font-size: 30px; margin-bottom: 0; padding-bottom: 0; position: relative; z-index: 2;">
        <u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u>
    </h5>
    <p style="font-size: 20px; margin-top: 0; padding-top: 0; margin-bottom: 5px;">Punong Barangay</p>
    
    <!-- e-Signature Image positioned just below the name -->
    <img src="components/employee_modal/show_esignature.php?t=<?=time()?>" alt="Punong Barangay e-Signature" 
         style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 150px; height: auto; z-index: 1;">
</section>
                            </div>
                        </div>
                    </body>
                </html>
            `;
        } else if (certificate === "Barangay Residency") {
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';

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

<section style="width: -155%; text-align: center; font-size: 25px; position: relative;">
    <?php
        // Find the Punong Barangay from the $officials array
        $punong_barangay = null;
        foreach ($officials as $official) {
            if ($official['position'] == 'Punong Barangay') {
                $punong_barangay = $official['name'];
                break;
            }
        }
    ?>
    <!-- Adjust the margin to reduce space between name and title, increase font size -->
    <h5 style="font-size: 30px; margin-bottom: 0; padding-bottom: 0; position: relative; z-index: 2;">
        <u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u>
    </h5>
    <p style="font-size: 20px; margin-top: 0; padding-top: 0; margin-bottom: 5px;">Punong Barangay</p>
    
    <!-- e-Signature Image positioned just below the name -->
    <img src="components/employee_modal/show_esignature.php?t=<?=time()?>" alt="Punong Barangay e-Signature" 
         style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 150px; height: auto; z-index: 1;">
</section>
                    </div>
                </div>
            </body>
        </html>
    `;
}else if (certificate === "Residency") {
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
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
        <?php else: ?>
            <p>No active City/Municipality logo found.</p>
        <?php endif; ?>
                    </div>
                                <hr class="header-line">
                            </header>
                            <section class="barangay-certification">
                                <h4 style="text-align: center;font-size: 50px;"><strong>CERTIFICATION</strong></h4><br>
                                <p>TO WHOM IT MAY CONCERN:</p>
                                <br>
                                <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                                <strong>${res_zone}</strong>,<strong>${res_street_address}</strong>, Bugo, Cagayan de Oro City.</p>
                                <br>
                                <p>This certify further that according to and as reported by ___________________________________ 
                                    he/she has been at the said area since <strong>${new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</strong> up to present.</p>
                                <br>
                                <p>This certification is issued upon the request of the above-mentioned person for 
                                    <strong>${purpose}</strong>.</p>
                                <br>
                            <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                            </section>
                            <br>
                            <br>
                            <br>
                            <br>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                    <section style="width: 48%; line-height: 1.8;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
                    <section style="width: -155%; text-align: center; font-size: 25px;">
                        <?php
                            // Find the Punong Barangay from the $officials array
                            $punong_barangay = null;
                            foreach ($officials as $official) {
                                if ($official['position'] == 'Punong Barangay') {
                                    $punong_barangay = $official['name'];
                                    break;
                                }
                            }
                        ?>
                        <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                        <p>Punong Barangay</p>
                        <!-- e-Signature Image -->
                        <img src="components/employee_modal/show_esignature.php?t=<?=time()?>" alt="Punong Barangay e-Signature" style="width: 150px; height: auto; margin-top: 10px;">
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
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
        <?php else: ?>
            <p>No active City/Municipality logo found.</p>
        <?php endif; ?>
                    </div>

                    <section style="text-align: center; margin-top: 10px;">
                        <hr class="header-line" style="border: 1px solid black; margin-top: 10px;">
                        <h2 style="font-size: 30px;"><strong>BARANGAY CLEARANCE</strong></h2>
                        <br>
                    </section>
                    <section style="display: flex; justify-content: space-between; margin-top: 10px;">
                        <!-- Left side empty or other content (if needed) -->
                        <div style="flex: 1;"></div>    
                        <!-- Right side Control No. -->
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
            // Display Punong Barangay first
            foreach ($officials as $official) {
                if ($official['position'] == 'Punong Barangay') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display 1st, 2nd, and 3rd Kagawads
            for ($i = 1; $i <= 3; $i++) {
                foreach ($officials as $official) {
                    if ($official['position'] == $i . 'st Kagawad' || $official['position'] == $i . 'nd Kagawad' || $official['position'] == $i . 'rd Kagawad') {
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    }
                }
            }

            // Display 4th to 7th Kagawads
            for ($i = 4; $i <= 7; $i++) {
                foreach ($officials as $official) {
                    if ($official['position'] == $i . 'th Kagawad') {
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    }
                }
            }

            // Display SK Chairman
            foreach ($officials as $official) {
                if ($official['position'] == 'SK Chairman') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display Barangay Secretary
            foreach ($officials as $official) {
                if ($official['position'] == 'Barangay Secretary') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display Treasurer
            foreach ($officials as $official) {
                if ($official['position'] == 'Treasurer') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }
            ?>
        </div>
    </div>
</div>
                    <!-- Right Section: Certification Text -->
                    <div class="right-content">
                        <p>TO WHOM IT MAY CONCERN:</p>
                        <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, legal age, <strong>${civil_status}</strong>. 
                        Filipino citizen, is a resident of Barangay Bugo, this City, particularly in <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>.</p><br>
                        <p>FURTHER CERTIFIES that the above-named person is known to be a person of good moral character and reputation as far as this office is concerned.
                        He/She has no pending case filed and blottered before this office.</p><br>
                        <p>This certification is being issued upon the request of the above-named person, in connection with his/her desire <strong>${purpose}</strong>.</p><br>

                        <!-- New Section Added Below -->
                            <p>Given this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                        <br>
                        <div style="text-align: center; font-size: 15px;" >
                            <u><strong>${fullname}</strong></u>
                            <p>AFFIANT SIGNATURE</p>
                        </div>

<div style="display: flex; justify-content: space-between; margin-top: 70px;">
    <section style="width: 48%; position: relative;">
        <?php if ($lupon_official): ?>
            <p><strong>As per records (LUPON TAGAPAMAYAPA):</strong></p>
            <p>Brgy. Case #: ___________________________</p>
            <p>Certified by: <U><strong><?php echo htmlspecialchars($lupon_official); ?></strong></U></p>
            <!-- e-Signature for Lupon Official positioned over the name -->
                <div style="position: absolute; top: 25px; left: 50%; transform: translateX(-25%); width: 120px; height: auto;">
                    <img src="components/employee_modal/lupon_sig.php?t=<?=time()?>" alt="Lupon Tagapamayapa e-Signature" 
                        style="width: 120px; height: auto; z-index: 1;">
                </div>
            <p>Date: <?php echo date('F j, Y'); ?></p>
        <?php endif; ?>
    </section>

    <section style="width: 48%; position: relative;">
        <?php if ($barangay_tanod_official): ?>
            <p><strong>As per records (BARANGAY TANOD):</strong></p>
            <p>Brgy. Tanod Remarks: _____________________</p>
            <p>Certified by: <U><strong><?php echo htmlspecialchars($barangay_tanod_official); ?></strong></U></p>
            <!-- e-Signature for Barangay Tanod Official positioned over the name -->
            <div style="position: absolute; top: 25px; left: 50%; transform: translateX(-25%); width: 120px; height: auto;">
                    <img src="components/employee_modal/tanod_sig.php?t=<?=time()?>" alt="Lupon Tagapamayapa e-Signature" 
                        style="width: 120px; height: auto; z-index: 1;">
                </div>
            <p>Date: <?php echo date('F j, Y'); ?></p>
        <?php endif; ?>
    </section>
</div>
                        
                    </div>
                </div>

                <!-- Thumbprint Section Below Left Content -->
                <section style="margin-top: 20px; text-align: center;">
                    <div style="display: flex; justify-content: left; gap: 20px;">
                        <!-- Left Thumb Box with Label Above -->
                        <div style="text-align: center; font-size:6px;" >
                            <p><strong>Left Thumb:</strong></p>
                            <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                            </div>
                        </div>

                        <!-- Right Thumb Box -->
                        <div style="text-align: center; font-size:6px;">
                            <p><strong>Right Thumb:</strong></p>
                            <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                            </div>
                        </div>
                    </div>
                </section>

                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <section style="width: 48%; line-height: 1.8;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
                    <section style="width: -155%; text-align: center; font-size: 20px; position: relative;">
                        <?php
                            // Find the Punong Barangay from the $officials array
                            $punong_barangay = null;
                            foreach ($officials as $official) {
                                if ($official['position'] == 'Punong Barangay') {
                                    $punong_barangay = $official['name'];
                                    break;
                                }
                            }
                        ?>
                        <!-- Adjust the margin to control space between name and title -->
                        <h5 style="font-size: 18px; margin-bottom: 0; padding-bottom: 0; position: relative; z-index: 2; margin-top: -45px;">
                            <u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u>
                        </h5>
                        <p style="font-size: 14px; margin-top: 0; padding-top: 0; margin-bottom: 10px;">Punong Barangay</p>
                        
                        <!-- e-Signature Image positioned just below the name and title -->
                        <img src="components/employee_modal/show_esignature.php?t=<?=time()?>" alt="Punong Barangay e-Signature" 
                            style="position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); width: 150px; height: auto; z-index: 1;">
                    </section>
                </div>
            </div>
        </body>
    </html>


        `;
    }else if (certificate.toLowerCase() === "beso application") {
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
                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
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

                                            // Adjust if current date hasn't reached the anniversary month/day yet
                                            const m = today.getMonth() - start.getMonth();
                                            if (m < 0 || (m === 0 && today.getDate() < start.getDate())) {
                                                years--;
                                            }

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
                        <br>
                        <br>    
                        <p> Not valid without seal</p>
                        </section>
                        <section style="width: -155%; text-align: center; font-size: 25px;">
                            <?php
                                // Find the Punong Barangay from the $officials array
                                $punong_barangay = null;
                                foreach ($officials as $official) {
                                    if ($official['position'] == 'Punong Barangay') {
                                        $punong_barangay = $official['name'];
                                        break;
                                    }
                                }
                            ?>
                            <br>
                            <br>
                            <br>
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
        const printWindow = window.open('', '_blank');
        printWindow.document.write(printAreaContent);
        printWindow.document.close();

        // Wait for the document to load, then print
        printWindow.onload = function () {
            printWindow.print();
        };
    }
// --- normalize helper ---
const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g, ''); 

let currentAppointmentType = '';
let currentCedulaNumber    = '';

// ===================== VIEW MODAL TOGGLE =====================
document.getElementById('statusSelect').addEventListener('change', function () {
  const rejectionGroup = document.getElementById('viewRejectionReasonGroup');
  const cedulaGroup    = document.getElementById('viewCedulaNumberContainer');
  const cedulaInput    = document.getElementById('viewCedulaNumber');
  const selectedStatus = this.value;

  // Show rejection if rejected
  rejectionGroup.style.display = (selectedStatus === 'Rejected') ? 'block' : 'none';

  // Show Cedula only if Released + type Cedula/UrgentCedula
  const typeNorm = normStatus(currentAppointmentType);
  const isCedulaType = (typeNorm === 'cedula' || typeNorm === 'urgentcedula');
  if (selectedStatus === 'Released' && isCedulaType) {
    cedulaGroup.classList.remove('d-none');
    cedulaInput.required = true;
  } else {
    cedulaGroup.classList.add('d-none');
    cedulaInput.required = false;
    cedulaInput.value = '';
  }
});

// ===================== VIEW MODAL POPULATE =====================
document.querySelectorAll('[data-bs-target="#viewModal"]').forEach(button => {
  button.addEventListener('click', () => {
    const trackingNumber = button.dataset.trackingNumber;
    document.getElementById('statusTrackingNumber').value = trackingNumber;

    fetch('./ajax/view_case_and_status.php?tracking_number=' + encodeURIComponent(trackingNumber))
      .then(res => res.json())
      .then(data => {
        if (!data.success) { alert('❌ Failed to load appointment data.'); return; }

        const form = document.getElementById('statusUpdateForm');
        const statusSelect = document.getElementById('statusSelect');
        const releasedOption = statusSelect.querySelector('option[value="Released"]');

        // Disable status backtracking
        const pendingOpt = statusSelect.querySelector('option[value="Pending"]');
        if (['approved','approvedcaptain','released'].includes(form.dataset.currentStatusNorm)) {
          if (pendingOpt) pendingOpt.disabled = true;
        } else { if (pendingOpt) pendingOpt.disabled = false; }

        const approvedOpt = statusSelect.querySelector('option[value="Approved"]');
        if (['approvedcaptain','released'].includes(form.dataset.currentStatusNorm)) {
          if (approvedOpt) approvedOpt.disabled = true;
        } else { if (approvedOpt) approvedOpt.disabled = false; }

        // set dataset for submit handler
        form.dataset.currentStatus     = data.status || '';
        form.dataset.currentStatusNorm = normStatus(data.status);
        statusSelect.value             = data.status || '';
        releasedOption.disabled        = (form.dataset.currentStatusNorm !== 'approvedcaptain');

        // store appointment type
        const selectedAppt = (data.appointments || []).find(app => app.tracking_number === trackingNumber);
        currentAppointmentType = selectedAppt?.certificate || '';
        currentCedulaNumber    = selectedAppt?.cedula_number || '';
        statusSelect.dispatchEvent(new Event('change'));

        // Case history
        const container = document.getElementById('caseHistoryContainer');
        if (data.cases && data.cases.length) {
          container.innerHTML = '<ul class="list-group">' + data.cases.map(cs => `
            <li class="list-group-item">
              <strong>Case #${cs.case_number}</strong> - ${cs.nature_offense}<br>
              <small>Filed: ${cs.date_filed} | Hearing: ${cs.date_hearing} | Action: ${cs.action_taken}</small>
            </li>`).join('') + '</ul>';
        } else {
          container.innerHTML = '<p class="text-muted">No case records for this resident.</p>';
        }

        // Same-day appointments with income (Cedula only)
        const ul = document.getElementById('sameDayAppointments');
        const peso = v => '₱' + Number(v).toLocaleString('en-PH');
        if (data.appointments && data.appointments.length) {
          ul.innerHTML = data.appointments.map(app => {
            const incomeHtml =
              (app.certificate?.toLowerCase() === 'cedula' && app.cedula_income)
                ? `<div class="text-muted">Income: ${peso(app.cedula_income)}</div>`
                : '';
            return `
              <li class="list-group-item">
                <strong>${app.certificate}</strong><br>
                Tracking #: <code>${app.tracking_number}</code><br>
                Time: ${app.time_slot}
                ${incomeHtml}
              </li>
            `;
          }).join('');
        } else {
          ul.innerHTML = '<li class="list-group-item text-muted">No appointments for this resident on this day.</li>';
        }
      });
  });
});

// ===================== VIEW MODAL SUBMIT HANDLER =====================
document.getElementById('statusUpdateForm').addEventListener('submit', async function (e) {
  e.preventDefault();

  const form         = this;
  const statusSelect = document.getElementById('statusSelect');
  const newStatus    = statusSelect.value;
  const cedulaInput  = document.getElementById('viewCedulaNumber');

  // Validate captain approval before release
  const currentStatusRaw  = form.dataset.currentStatus || '';
  const currentStatusNorm = form.dataset.currentStatusNorm || normStatus(currentStatusRaw);
  if (normStatus(newStatus) === 'released' && currentStatusNorm !== 'approvedcaptain') {
    await Swal.fire({ icon:'warning', title:'Action Not Allowed',
      text:'You must first mark the appointment as Approved by Captain before releasing it.' });
    return;
  }

  // Require Cedula number if Released + Cedula type
  const typeNorm = normStatus(currentAppointmentType);
  const isCedulaType = (typeNorm === 'cedula' || typeNorm === 'urgentcedula');
  if (normStatus(newStatus) === 'released' && isCedulaType && !cedulaInput.value.trim()) {
    await Swal.fire({ icon:'warning', title:'Cedula Number required',
      text:'Provide the Cedula Number before marking as Released.' });
    cedulaInput.focus();
    return;
  }

  const formData = new FormData(form);

  // show loading
  Swal.fire({ title:'Saving...', text:'Please wait while we update the status.', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

  try {
    const res = await fetch('./ajax/update_status_batch.php', {
      method:'POST', body:formData, headers:{ 'X-Requested-With':'XMLHttpRequest' }
    });

    let json=null, text='';
    try { json = await res.json(); } catch { text = await res.text().catch(()=> ''); }
    if (!res.ok || !json) throw new Error(json?.message || (text ? text.slice(0,200) : `HTTP ${res.status}`));

    if (json.success) {
      await Swal.fire({ icon:'success', title:'Success', text:json.message || 'Status updated.' });
      location.reload();
    } else {
      await Swal.fire({ icon:'warning', title:'Update Failed', text:json.message || 'Please try again.' });
    }
  } catch (err) {
    console.error('Error during status update:', err);
    await Swal.fire({ icon:'error', title:'Error', text: err.message || 'Something went wrong.' });
  }
});

// ===================== STATUS MODAL POPULATE & TOGGLE =====================
document.querySelectorAll('[data-bs-target="#statusModal"]').forEach(button => {
  button.addEventListener('click', () => {
    const certificate   = button.getAttribute('data-certificate');
    const trackingNum   = button.getAttribute('data-tracking-number');
    const cedulaNumber  = button.getAttribute('data-cedula-number') || '';

    // Fill modal fields
    document.getElementById('modalTrackingNumber').value = trackingNum;
    document.getElementById('modalCertificate').value    = certificate;
    document.getElementById('statusModalCedulaNumber').value = cedulaNumber;

    const cedulaWrap   = document.getElementById('statusModalCedulaNumberContainer');
    const rejectWrap   = document.getElementById('statusModalRejectionReasonContainer');
    const statusSelect = document.getElementById('newStatus');

    const checkStatus = () => {
      const selectedStatus = statusSelect.value;
      const currentStatus  = statusSelect.getAttribute('data-current-status') || '';
      const releasedOpt    = statusSelect.querySelector('option[value="Released"]');

      if (releasedOpt) releasedOpt.disabled = (currentStatus !== 'ApprovedCaptain');

      // Cedula field toggle
      if (certificate.toLowerCase() === 'cedula' && selectedStatus === 'Released') {
        cedulaWrap.style.display = 'block';
      } else { cedulaWrap.style.display = 'none'; }

      // Rejection field toggle
      rejectWrap.style.display = (selectedStatus === 'Rejected') ? 'block' : 'none';

      // Prevent status backtracking
      const pendOpt = statusSelect.querySelector('option[value="Pending"]');
      if (['Approved','ApprovedCaptain','Released'].includes(currentStatus)) {
        if (pendOpt) pendOpt.disabled = true;
      } else { if (pendOpt) pendOpt.disabled = false; }

      const apprOpt = statusSelect.querySelector('option[value="Approved"]');
      if (['ApprovedCaptain','Released'].includes(currentStatus)) {
        if (apprOpt) apprOpt.disabled = true;
      } else { if (apprOpt) apprOpt.disabled = false; }
    };

    statusSelect.removeEventListener('change', checkStatus);
    statusSelect.addEventListener('change', checkStatus);
    checkStatus();
  });
});

// ===================== LOG APPOINTMENT VIEW =====================
function logAppointmentView(residentId) {
  fetch('./logs/logs_trig.php', {
    method:'POST',
    headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
    body:`filename=3&viewedID=${residentId}`
  })
  .then(res => res.text())
  .then(data => console.log("Appointment view logged:", data))
  .catch(err => console.error("Log view error:", err));
}

// ===================== BADGE MAPPING =====================
(function(){
  const map = {
    pending:'badge-soft-warning',
    approved:'badge-soft-info',
    approvedcaptain:'badge-soft-primary',
    rejected:'badge-soft-danger',
    released:'badge-soft-success'
  };
  document.querySelectorAll('#appointmentTableBody td:nth-child(6)').forEach(td => {
    const raw = (td.textContent || '').trim();
    const key = raw.toLowerCase().replace(/\s+/g,'');
    if (!td.querySelector('.badge')) {
      const b = document.createElement('span');
      b.className = 'badge ' + (map[key] || 'badge-soft-secondary');
      b.textContent = raw;
      td.textContent = '';
      td.appendChild(b);
    }
  });
})();

        </script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        
    </body>
    </html>
