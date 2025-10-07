<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL); 
include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
// include './include/encryption.php';
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
date_default_timezone_set('Asia/Manila');

// Pagination settings
$results_per_page = 200;
$page = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset = ($page - 1) * $results_per_page;

// Handle delete (Mark appointment as archived)
if (isset($_POST['delete_appointment'], $_POST['tracking_number'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $certificate = $_POST['certificate'];

    if (strtolower($certificate) === 'cedula') {
        // Delete Cedula Appointment
        $update_query = "UPDATE cedula SET cedula_delete_status = 1 WHERE tracking_number = ?";
    } else {
        // Delete Schedule Appointment
        $update_query = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";
    }

    $stmt_update = $mysqli->prepare($update_query);
    $stmt_update->bind_param("s", $tracking_number);
    $stmt_update->execute();

    echo "<script>
        alert('Status updated to $new_status');
        window.location = '" . enc_brgysec('view_appointments') . "';
    </script>";
}


// Handle status update (PROCESS UNCHANGED)
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $new_status = $_POST['new_status'];
    $certificate = $_POST['certificate'];
    $cedula_number = trim($_POST['cedula_number'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    // Check if this is an urgent Cedula request
    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($isUrgentCedula);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();

    // Uniqueness check for Cedula number (only when updating cedula)
    if (($isUrgentCedula > 0 || $certificate === 'Cedula') && $new_status === 'Approved' && !empty($cedula_number)) {
        // Check for duplicates only from other residents
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

    $employee_id = $_SESSION['employee_id'];

    // Perform the status update (exact same branches)
    if ($isUrgentCedula > 0) {
        if ($new_status === 'Rejected') {
            $query = "UPDATE urgent_cedula_request 
                    SET cedula_status = ?, 
                        rejection_reason = ?, 
                        is_read = 0, 
                        notif_sent = 1,
                        employee_id =?
                    WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE urgent_cedula_request 
                    SET cedula_status = ?, 
                        cedula_number = ?, 
                        rejection_reason = NULL, 
                        is_read = 0, 
                        notif_sent = 1,
                        employee_id =?
                    WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }

    } elseif ($certificate === 'Cedula') {
        if ($new_status === 'Rejected') {
            $query = "UPDATE cedula 
                    SET cedula_status = ?, 
                        rejection_reason = ?, 
                        is_read = 0, 
                        notif_sent = 1,
                        employee_id =?
                    WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE cedula 
                    SET cedula_status = ?, 
                        cedula_number = ?, 
                        rejection_reason = NULL, 
                        is_read = 0, 
                        notif_sent = 1,
                        employee_id =?
                    WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }

    } else {
        // Check if the tracking number exists in urgent_request (for non-cedula urgent appointments)
        $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgent->bind_param("s", $tracking_number);
        $checkUrgent->execute();
        $checkUrgent->bind_result($isUrgent);
        $checkUrgent->fetch();
        $checkUrgent->close();

        if ($isUrgent > 0) {
            if ($new_status === 'Rejected') {
                $query = "UPDATE urgent_request 
                        SET status = ?, 
                            rejection_reason = ?, 
                            is_read = 0, 
                            notif_sent = 1,
                            employee_id =?
                        WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE urgent_request 
                        SET status = ?, 
                            rejection_reason = NULL, 
                            is_read = 0, 
                            notif_sent = 1,
                            employee_id =?
                        WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }

        } else {
            if ($new_status === 'Rejected') {
                $query = "UPDATE schedules 
                        SET status = ?, 
                            rejection_reason = ?, 
                            is_read = 0, 
                            notif_sent = 1,
                            employee_id =? 
                        WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE schedules 
                        SET status = ?, 
                            rejection_reason = NULL, 
                            is_read = 0, 
                            notif_sent = 1,
                            employee_id =?
                        WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }
        }
    }

    $stmt->execute();
    $stmt->close();

    // Determine the appointment source (unchanged)
    $isUrgentCedula = false;
    $isUrgentSchedule = false;

    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($urgentCedulaCount);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();

    if ($urgentCedulaCount > 0) {
        $isUrgentCedula = true;
    }

    if (!$isUrgentCedula) {
        $checkUrgentSchedule = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgentSchedule->bind_param("s", $tracking_number);
        $checkUrgentSchedule->execute();
        $checkUrgentSchedule->bind_result($urgentScheduleCount);
        $checkUrgentSchedule->fetch();
        $checkUrgentSchedule->close();

        if ($urgentScheduleCount > 0) {
            $isUrgentSchedule = true;
        }
    }

    // Build the correct email query (unchanged)
    if ($isUrgentCedula) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM urgent_cedula_request u
                        JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } elseif ($certificate === 'Cedula') {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM cedula c
                        JOIN residents r ON c.res_id = r.id
                        WHERE c.tracking_number = ?";
    } elseif ($isUrgentSchedule) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM urgent_request u
                        JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } else {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM schedules s
                        JOIN residents r ON s.res_id = r.id
                        WHERE s.tracking_number = ?";
    }

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email->num_rows > 0) {
        $row = $result_email->fetch_assoc();
        $email = $row['email'];
        $resident_name = $row['full_name'];
        $contact_number = $row['contact_number'];

        // Email via PHPMailer (unchanged)
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

        // SMS via Semaphore (unchanged)
        $apiKey = 'your_semaphore_api_key';
        $sender = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query([
            'apikey' => $apiKey,
            'number' => $contact_number,
            'message' => $sms_message,
            'sendername' => $sender
        ]);

        $sms_options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $sms_data,
            ],
        ];

        $sms_context = stream_context_create($sms_options);
        $sms_result = file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);

        if ($sms_result === FALSE) {
            error_log("❌ SMS failed to send to $contact_number");
        } else {
            $sms_response = json_decode($sms_result, true);
            error_log("✅ SMS sent response: " . print_r($sms_response, true));

            // Log to DB (unchanged)
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_query = "INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?, ?, ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
        }
    }

    echo "<script>
        alert('Status updated to $new_status');
        window.location = '" . enc_brgysec('view_appointments') . "';
    </script>";
}


// ---------- Archiving (UNCHANGED) ----------
$archiveSchedules = "
    INSERT INTO archived_schedules
    SELECT * FROM schedules
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$deleteSchedules = "
    DELETE FROM schedules
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$mysqli->query($archiveSchedules);
$mysqli->query($deleteSchedules);

$archiveCedula = "
    INSERT INTO archived_cedula
    SELECT * FROM cedula
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteCedula = "
    DELETE FROM cedula
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$mysqli->query($archiveCedula);
$mysqli->query($deleteCedula);

$archiveUrgentCedula = "
    INSERT INTO archived_urgent_cedula_request
    SELECT * FROM urgent_cedula_request
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteUrgentCedula = "
    DELETE FROM urgent_cedula_request
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$mysqli->query($archiveUrgentCedula);
$mysqli->query($deleteUrgentCedula);

$archiveUrgentRequest = "
    INSERT INTO archived_urgent_request
    SELECT * FROM urgent_request
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$deleteUrgentRequest = "
    DELETE FROM urgent_request
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$mysqli->query($archiveUrgentRequest);
$mysqli->query($deleteUrgentRequest);

$mysqli->query("UPDATE schedules SET appointment_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released', 'Rejected')");

// ---------- DATA SOURCE (UNCHANGED, only WHERE narrowed for captain screen) ----------
$sql = "
SELECT * FROM (
    -- Urgent Cedula
    SELECT 
        ucr.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname,
        'Cedula' AS certificate,
        ucr.cedula_status AS status,
        ucr.appointment_time AS selected_time,
        ucr.appointment_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application (Urgent)' AS purpose,
        ucr.issued_on,
        ucr.cedula_number,
        ucr.issued_at
    FROM urgent_cedula_request ucr
    JOIN residents r ON ucr.res_id = r.id
    WHERE ucr.cedula_delete_status = 0 
      AND ucr.appointment_date >= CURDATE()

    UNION

    -- Regular Schedules
    SELECT 
        s.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname, 
        s.certificate, 
        s.status,
        s.selected_time,
        s.selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        s.purpose,
        c.issued_on,
        c.cedula_number,
        c.issued_at
    FROM schedules s
    JOIN residents r ON s.res_id = r.id
    LEFT JOIN cedula c ON c.res_id = r.id
    WHERE s.appointment_delete_status = 0 
      AND s.selected_date >= CURDATE()

    UNION

    -- Cedula Appointments
    SELECT 
        c.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname, 
        'Cedula' AS certificate,
        c.cedula_status AS status,
        c.appointment_time AS selected_time,
        c.appointment_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application' AS purpose,
        c.issued_on,
        c.cedula_number,
        c.issued_at
    FROM cedula c
    JOIN residents r ON c.res_id = r.id
    WHERE c.cedula_delete_status = 0 
      AND c.appointment_date >= CURDATE()

    UNION

    -- Urgent Requests (with optional Cedula info)
    SELECT 
        u.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname,
        u.certificate,
        u.status,
        u.selected_time,
        u.selected_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        u.purpose,
        COALESCE(c.issued_on, uc.issued_on) AS issued_on,
        COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
        COALESCE(c.issued_at, uc.issued_at) AS issued_at
    FROM urgent_request u
    JOIN residents r ON u.res_id = r.id
    LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status = 'Approved'
    LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
    WHERE u.urgent_delete_status = 0 
      AND u.selected_date >= CURDATE()
) AS all_appointments
 WHERE status IN ('Approved', 'ApprovedCaptain')
GROUP BY tracking_number
ORDER BY 
    (status = 'Pending' AND selected_time = 'URGENT' AND selected_date = CURDATE()) DESC,
    (status = 'Pending' AND selected_date = CURDATE()) DESC,
    selected_date ASC,
    selected_time ASC,
    FIELD(status, 'Pending', 'Approved', 'Rejected')
LIMIT ?, ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $offset, $results_per_page);
$stmt->execute();
$result = $stmt->get_result();
$filtered_appointments = [];
$currentDate = date('Y-m-d');

// Default date_filter to 'today' if not provided
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'today';

while ($row = $result->fetch_assoc()) {
    $include = true;

    // Date filter (UNCHANGED)
    if (!empty($date_filter)) {
        $appt_date = $row['selected_date'];
        $week = date('W', strtotime($appt_date));
        $this_week = date('W');
        $next_week = date('W', strtotime("+1 week"));

        switch ($date_filter) {
            case 'today':
                $include = $appt_date === $currentDate;
                break;
            case 'this_week':
                $include = $week === $this_week && date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'next_week':
                $include = $week === $next_week && date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'this_month':
                $include = date('m', strtotime($appt_date)) === date('m') &&
                           date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'this_year':
                $include = date('Y', strtotime($appt_date)) === date('Y');
                break;
        }
    }

    // Status filter
    if ($include && !empty($_GET['status_filter'])) {
        $include = $row['status'] === $_GET['status_filter'];
    }

    if ($include) {
        $filtered_appointments[] = $row;
    }
}

$total_results_sql = "
    SELECT COUNT(*) AS total FROM (
        SELECT tracking_number FROM schedules WHERE appointment_delete_status = 0
        UNION
        SELECT tracking_number FROM cedula WHERE cedula_status IS NOT NULL AND cedula_delete_status = 0
    ) AS combined
";
$total_results_result = $mysqli->query($total_results_sql);
$total_results = $total_results_result->fetch_row()[0];
$total_pages = ceil($total_results / $results_per_page);

// Officials (UNCHANGED)
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name, b.status
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status = 'active' 
        AND b.position NOT LIKE '%Lupon%'
        AND b.position NOT LIKE '%Barangay Tanod%'
        AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position, 'Punong Barangay', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 
        'Kagawad', 'SK Chairman', 'Secretary', 'Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult->num_rows > 0) {
    while($row = $offresult->fetch_assoc()) {
        $officials[] = [
            'position' => $row['position'],
            'name' => $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']
        ];
    }
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo = $logo_result->num_rows > 0 ? $logo_result->fetch_assoc() : null;

$citySql = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status = 'active' LIMIT 1";
$cityResult = $mysqli->query($citySql);
$cityLogo = $cityResult->num_rows > 0 ? $cityResult->fetch_assoc() : null;

// Barangay/City labels (UNCHANGED)
$barangayInfoSql = "SELECT 
                        bm.city_municipality_name, 
                        b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) {
        $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName);
    } else {
        $cityMunicipalityName = strtoupper($cityMunicipalityName);
    }
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName));

    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = strtoupper($barangayName);
    } else if (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "POBLACION " . strtoupper($barangayName);
    } else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = strtoupper($barangayName);
    } else {
        $barangayName = "BARANGAY " . strtoupper($barangayName);
    }
} else {
    $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND";
    $barangayName = "NO BARANGAY FOUND";
}

// Council term + contacts (UNCHANGED)
$councilTermSql = "SELECT council_term FROM barangay_info WHERE id = 1";
$councilTermResult = $mysqli->query($councilTermSql);
$councilTerm = ($councilTermResult->num_rows > 0) ? $councilTermResult->fetch_assoc()['council_term'] : "#";

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
            FROM barangay_information b
            INNER JOIN residents r ON b.official_id = r.id
            WHERE b.status = 'active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);

$lupon_official = null;
$barangay_tanod_official = null;
if ($lupon_result->num_rows > 0) {
    while ($row = $lupon_result->fetch_assoc()) {
        if (stripos($row['position'], 'Lupon') !== false) {
            $lupon_official = $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'];
        }
        if (stripos($row['position'], 'Barangay Tanod') !== false || stripos($row['position'], 'Barangay Police') !== false) {
            $barangay_tanod_official = $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'];
        }
    }
}

$barangayContactSql = "SELECT telephone_number, mobile_number FROM barangay_info WHERE id = 1";
$barangayContactResult = $mysqli->query($barangayContactSql);
if ($barangayContactResult->num_rows > 0) {
    $contactInfo = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber = $contactInfo['mobile_number'];
} else {
    $telephoneNumber = "No telephone number found";
    $mobileNumber = "No mobile number found";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Appointment List</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Icons used in headings/buttons (optional) -->
  <!--<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">-->
  <!--<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>-->
</head>
<body>
  <div class="container my-4 app-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
      <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
    </div>

    <!-- Filters (styled like reference) -->
    <div class="card card-filter mb-3 shadow-sm">
      <div class="card-body py-3">
        <form method="GET" action="index_barangay_secretary.php" class="row g-2 align-items-end">
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

    <!-- Table shell like reference -->
    <div class="card shadow-sm">
      <div class="card-body table-shell">
        <div class="table-edge">
          <div class="table-scroll">
            <table class="table table-hover align-middle mb-0" id="appointmentsTable">
              <thead class="table-head sticky-top">
                <tr>
                  <th style="width: 200px;">Full Name</th>
                  <th style="width: 120px;">Certificate</th>
                  <th style="width: 220px;">Tracking Number</th>
                  <th style="width: 160px;">Date</th>
                  <th style="width: 160px;">Time Slot</th>
                  <th style="width: 160px;">Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="appointmentTableBody">
              <?php if (count($filtered_appointments) > 0): ?>
                  <?php foreach ($filtered_appointments as $row): ?>
                      <?php include 'Modules/captain_modules/appointment_row.php'; ?>
                  <?php endforeach; ?>
              <?php else: ?>
                  <tr><td colspan="7" class="text-center">No appointments found</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <?php
      // Windowed pagination (styled like reference)
      $pageBase = enc_brgysec('view_appointments');
      $params = $_GET; unset($params['pagenum']);
      $pairs = [];
      foreach ($params as $k => $v) {
        if (is_array($v)) { foreach ($v as $vv) $pairs[] = urlencode($k).'='.urlencode($vv); }
        else { $pairs[] = urlencode($k).'='.urlencode($v ?? ''); }
      }
      $qs = $pairs ? '&'.implode('&', $pairs) : '';

      $window = 7;
      $half   = (int)floor($window/2);
      $start  = max(1, $page - $half);
      $end    = min($total_pages, $start + $window - 1);
      if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
    ?>

    <nav aria-label="Page navigation" class="mt-3">
      <ul class="pagination justify-content-end pagination-soft mb-0">
        <?php if ($page <= 1): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></a></li>
        <?php endif; ?>

        <?php if ($page <= 1): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></a></li>
        <?php endif; ?>

        <?php if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>

        <?php for ($i=$start; $i<=$end; $i++): ?>
          <li class="page-item <?= ($i==$page)?'active':'' ?>"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a></li>
        <?php endfor; ?>

        <?php if ($end < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>

        <?php if ($page >= $total_pages): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></a></li>
        <?php endif; ?>

        <?php if ($page >= $total_pages): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>

  <!-- View Appointment Modal (styled like reference) -->
  <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content modal-elev rounded-4">
        <div class="modal-header modal-accent rounded-top-4">
          <div>
            <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="viewModalLabel">
              <i class="bi bi-calendar-check-fill"></i> Appointment Details
            </h5>
            <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-0">
          <div class="p-4 content-grid">
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

            <!-- Captain bulk approve action -->
            <section class="card soft-card grid-col-2">
              <div class="card-header soft-card-header d-flex justify-content-between align-items-center">
                <span class="section-title"><i class="bi bi-person-check"></i> Captain Approval</span>
                <small class="text-muted">Approve all of resident’s appointments for the date</small>
              </div>
              <div class="card-body">
                <button id="approveCaptainBtn" type="button" class="btn btn-primary w-100">
                  <i class="bi bi-check2-circle me-1"></i> Approve All (Captain)
                </button>
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

  <!-- Status Change Modal (Captain only: ApprovedCaptain) -->
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
                <option value="ApprovedCaptain">ApprovedCaptain</option>
              </select>
            </div>

            <div class="mb-3" id="cedulaNumberContainer" style="display: none;">
              <label for="cedulaNumber" class="form-label fw-semibold">Cedula Number</label>
              <input type="text" name="cedula_number" id="cedulaNumber" class="form-control shadow-sm rounded-3" placeholder="Enter Cedula Number">
            </div>

            <div class="mb-3" id="rejectionReasonContainer" style="display: none;">
              <label for="rejectionReason" class="form-label fw-semibold">Rejection Reason</label>
              <textarea class="form-control shadow-sm rounded-3" name="rejection_reason" id="rejectionReason" rows="2" placeholder="State reason for rejection..."></textarea>
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

  <!-- Scripts -->
  <script src="util/debounce.js"></script>
  <script>
    // ---------- Helpers (UI only) ----------
    const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g,'');

    // Apply soft badge styling to Status column (like reference)
    (function(){
      const map = {
        pending: 'badge-soft-warning',
        approved: 'badge-soft-info',
        approvedcaptain: 'badge-soft-primary',
        rejected: 'badge-soft-danger',
        released: 'badge-soft-success'
      };
      document.querySelectorAll('#appointmentTableBody td:nth-child(6)').forEach(td => {
        const raw = (td.textContent || '').trim();
        if (!raw) return;
        const key = normStatus(raw);
        if (!td.querySelector('.badge')) {
          const b = document.createElement('span');
          b.className = 'badge ' + (map[key] || 'badge-soft-secondary');
          b.textContent = raw;
          td.textContent = '';
          td.appendChild(b);
        }
      });
    })();

    // ---------- View modal wiring (design & UX only; endpoints unchanged) ----------
    document.querySelectorAll('[data-bs-target="#viewModal"]').forEach(button => {
      button.addEventListener('click', () => {
        const resId = button.getAttribute('data-res-id');
        const selectedDate = button.getAttribute('data-selected-date');

        // Populate same-day appointments list (server snippet output)
        fetch(`Search/get_resident_appointments.php?res_id=${encodeURIComponent(resId)}&selected_date=${encodeURIComponent(selectedDate)}`)
          .then(res => res.text())
          .then(html => {
            // Expecting server to return <li> items or a table snippet; wrap if needed
            const ul = document.getElementById('sameDayAppointments');
            ul.innerHTML = html || '<li class="list-group-item text-muted">No appointments for this resident on this day.</li>';
          }).catch(()=> {
            document.getElementById('sameDayAppointments').innerHTML = '<li class="list-group-item text-muted">Failed to load.</li>';
          });

        // Populate case history block
        fetch(`Search/get_cases.php?res_id=${encodeURIComponent(resId)}`)
          .then(res => res.text())
          .then(html => {
            document.getElementById('caseHistoryContainer').innerHTML = html || '<p class="text-muted px-3 py-2 mb-0">No case records for this resident.</p>';
          }).catch(()=> {
            document.getElementById('caseHistoryContainer').innerHTML = '<p class="text-muted px-3 py-2 mb-0">Failed to load case history.</p>';
          });

        // Captain bulk approve button flow (design only; endpoint unchanged)
        const approveBtn = document.getElementById('approveCaptainBtn');
        if (approveBtn) {
          approveBtn.onclick = () => {
            Swal.fire({
              title: 'Approve All?',
              text: 'Approve all appointments for this resident on this date?',
              icon: 'question',
              showCancelButton: true,
              confirmButtonText: 'Yes, approve all',
              cancelButtonText: 'Cancel',
              confirmButtonColor: '#28a745'
            }).then(result => {
              if (result.isConfirmed) {
                Swal.fire({
                  title: 'Approving...',
                  text: 'Please wait while we update the records.',
                  allowOutsideClick: false,
                  didOpen: () => Swal.showLoading()
                });

                fetch('Search/approve_captain.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `res_id=${encodeURIComponent(resId)}&selected_date=${encodeURIComponent(selectedDate)}`
                })
                .then(res => res.text())
                .then(msg => {
                  Swal.fire({ icon: 'success', title: 'Done', text: msg, confirmButtonColor: '#3085d6' })
                    .then(() => location.reload());
                })
                .catch(err => {
                  console.error(err);
                  Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong while approving appointments.', confirmButtonColor: '#d33' });
                });
              }
            });
          };
        }
      });
    });

    // ---------- Status modal (Captain single-status path) ----------
    document.querySelectorAll('[data-bs-target="#statusModal"]').forEach(button => {
      button.addEventListener('click', () => {
        document.getElementById('modalTrackingNumber').value = button.getAttribute('data-tracking-number');
        document.getElementById('modalCertificate').value = button.getAttribute('data-certificate');

        const certificate = button.getAttribute('data-certificate') || '';
        const cedulaNumber = button.getAttribute('data-cedula-number') || '';
        document.getElementById('cedulaNumber').value = cedulaNumber;

        const cedulaContainer = document.getElementById('cedulaNumberContainer');
        const rejectionContainer = document.getElementById('rejectionReasonContainer');
        const statusSelect = document.getElementById('newStatus');

        // Keep UI responsive if options change later
        const checkStatus = () => {
          // Show Cedula Number if Cedula & Released (logic parity with reference UI; process still server-side)
          if (certificate.toLowerCase() === 'cedula' && statusSelect.value === 'Released') {
            cedulaContainer.style.display = 'block';
          } else {
            cedulaContainer.style.display = 'none';
          }
          // Show rejection reason if Rejected (not used here but keeps component generic)
          if (statusSelect.value === 'Rejected') {
            rejectionContainer.style.display = 'block';
          } else {
            rejectionContainer.style.display = 'none';
          }
        };

        statusSelect.removeEventListener('change', checkStatus);
        statusSelect.addEventListener('change', checkStatus);
        checkStatus();
      });
    });

    // Log view helper (unchanged, just scoped)
    function logAppointmentView(residentId) {
      fetch('./logs/logs_trig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `filename=3&viewedID=${encodeURIComponent(residentId)}`
      }).then(res => res.text())
        .then(data => console.log("Appointment view logged:", data))
        .catch(err => console.error("Log view error:", err));
    }
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
