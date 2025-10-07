<?php
ini_set('display_errors', 0); // don't show errors to users
ini_set('log_errors', 1);     // log them instead
ini_set('error_log', __DIR__ . '/../logs/php_error.log');
error_reporting(E_ALL);       // report everything to logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

include 'class/session_timeout.php';
date_default_timezone_set('Asia/Manila');

require_once './logs/logs_trig.php';
$trigs = new Trigger();

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ======================================================================
   Helpers
   ====================================================================== */

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim((string)$data)));
}

function generatePassword(int $len = 12): string {
    $base = str_replace(['/', '+', '='], '', base64_encode(random_bytes($len + 2)));
    return substr($base, 0, $len);
}

/**
 * Robust mail sender. Returns true on success, false on failure.
 * Uses env vars if available; otherwise falls back to defaults.
 */
function sendCredentials(string $to, string $password): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendCredentials: invalid email '{$to}'");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // SMTP config
        $mail->isSMTP();
        $mail->Host          = 'mail.bugoportal.site';
        $mail->SMTPAuth      = true;
        $mail->Username      = 'admin@bugoportal.site';
        $mail->Password      = 'Jayacop@100';
        // Try one combo at a time. Start with SMTPS:465
        $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port          = 465;

        $mail->Timeout       = 12;
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');

        $mail->setFrom('admin@bugoportal.site', 'Barangay Bugo');
        $mail->addAddress($to);
        $mail->addReplyTo('admin@bugoportal.site', 'Barangay Bugo');
        // $mail->addBCC('admin@bugoportal.site');

        $portalLink = 'https://bugoportal.site/';
        $mail->isHTML(true);
        $mail->Subject = 'Your Barangay Bugo Resident Portal Credentials';
        $mail->Body    = "<p>Hi {$safeTo},</p>
                          <p>Here are your Barangay Bugo portal login credentials:</p>
                          <ul>
                            <li><strong>Username:</strong> {$safeTo}</li>
                            <li><strong>Password:</strong> {$password}</li>
                          </ul>
                          <p>Please log in and change your password.</p>
                          <p><a href=\"{$portalLink}\">Open Resident Portal</a></p>
                          <br><p>Thank you,<br>Barangay Bugo</p>";
        $mail->AltBody = "Hi {$to},\n\nHere are your Barangay Bugo portal login credentials:\n"
                       . "Username: {$to}\nPassword: {$password}\n\n"
                       . "Please log in and change your password.\n\n{$portalLink}\n\nThank you,\nBarangay Bugo";

        // ✅ Actually return a bool
        $ok = $mail->send();
        if (!$ok) {
            error_log("sendCredentials: send() returned false for {$to}: " . $mail->ErrorInfo);
        }
        return $ok;
    } catch (Exception $e) {
        error_log("sendCredentials exception ({$to}): {$mail->ErrorInfo} | {$e->getMessage()}");
        return false;
    }
}

/**
 * Only sends mail if a valid email is present. Logs skips.
 * Returns true on success, false on skip/failure.
 *
 * Optional: feature-flag to disable mail in dev:
 * define('SEND_EMAILS', (getenv('SEND_EMAILS') ?: '1') === '1');
 */
if (!defined('SEND_EMAILS')) {
    define('SEND_EMAILS', (getenv('SEND_EMAILS') ?: '1') === '1');
}
function sendCredentialsIfPresent(?string $to, string $password): bool {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendCredentialsIfPresent: skipped (missing/invalid email): '{$to}'");
        return false;
    }
    if (!SEND_EMAILS) {
        error_log("sendCredentialsIfPresent: SEND_EMAILS=0 (pretend sent to '{$to}')");
        return true;
    }
    return sendCredentials($to, $password);
}

/* ======================================================================
   Pagination + Filters
   ====================================================================== */

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$page   = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$count_sql = "SELECT COUNT(*) as total FROM residents
              WHERE resident_delete_status = 0
              AND CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) LIKE ?";

$params = ["%$search%"];
$types  = 's';

if (!empty($_GET['filter_gender'])) {
    $count_sql .= " AND gender = ?";
    $params[] = $_GET['filter_gender'];
    $types .= 's';
}
if (!empty($_GET['filter_zone'])) {
    $count_sql .= " AND res_zone = ?";
    $params[] = $_GET['filter_zone'];
    $types .= 's';
}
if (!empty($_GET['filter_status'])) {
    $count_sql .= " AND civil_status = ?";
    $params[] = $_GET['filter_status'];
    $types .= 's';
}

$stmt = $mysqli->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$count_result = $stmt->get_result();
$total_rows   = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages  = max((int)ceil($total_rows / $limit), 1);

$redirects = $redirects ?? []; // ensure variable exists
$baseUrl   = $redirects['residents'] ?? 'index_Admin.php?page=resident_info';

if ($page > $total_pages) {
    $page = 1;
}
$offset = ($page - 1) * $limit;

/* ======================================================================
   Batch Import (Excel)
   ====================================================================== */

if (isset($_POST['import_excel'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token mismatch.");
    }

    if (!empty($_FILES['excel_file']['tmp_name'])) {
        $file = $_FILES['excel_file']['tmp_name'];

        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            $processed_emails  = [];
            $duplicate_emails  = [];
            $duplicates_found  = false;

            // Pass 1: collect duplicate emails in the uploaded file
            foreach ($rows as $i => $row) {
                $email = strtolower(sanitize_input($row[9] ?? ''));
                if (!$email) continue;
                if (isset($processed_emails[$email])) {
                    $duplicate_emails[] = $email;
                    $duplicates_found = true;
                }
                $processed_emails[$email] = true;
            }

            $processed_full_names = [];

            // Pass 2: insert rows (skip duplicates and rows without email/username)
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];

                $last_name    = sanitize_input($row[0] ?? 'N/A');
                $first_name   = sanitize_input($row[1] ?? 'N/A');
                $middle_name  = sanitize_input($row[2] ?? '');
                $suffix_name  = sanitize_input($row[3] ?? '');
                $full_address = sanitize_input($row[4] ?? 'ZONE N/A N/A');
                $birth_date   = date('Y-m-d', strtotime($row[5] ?? '2000-01-01'));
                $gender       = sanitize_input($row[6] ?? 'N/A');
                $civil_status = sanitize_input($row[7] ?? 'N/A');
                $occupation   = sanitize_input($row[8] ?? 'N/A');
                $email        = strtolower(sanitize_input($row[9] ?? ''));

                // Rule: must have email OR username. For batch, we use email as username.
                if (in_array($email, $duplicate_emails, true)) continue;
                if (empty($email)) continue; // in batch, skip rows with no email

                $full_name_key = strtolower("$first_name $middle_name $last_name $suffix_name");
                if (isset($processed_full_names[$full_name_key])) continue;
                $processed_full_names[$full_name_key] = true;

                // Defaults
                $employee_id             = $_SESSION['employee_id'];
                $zone_leader_id          = 0;
                $username                = $email; // username = email
                $raw_password            = generatePassword();
                $password                = password_hash($raw_password, PASSWORD_DEFAULT);
                $birth_place             = "N/A";
                $residency_start         = date('Y-m-d');
                $res_province            = 57;
                $res_city                = 1229;
                $res_barangay            = 32600;
                $contact_number          = "0000000000";
                $citizenship             = "N/A";
                $religion                = "N/A";
                $age                     = date_diff(date_create($birth_date), date_create('today'))->y;
                $resident_delete_status  = 0;
                $res_zone                = sanitize_input($row[4] ?? 'ZONE N/A');

                // INSERT with temp_password
                $stmt = $mysqli->prepare("INSERT INTO residents (
                        employee_id, zone_leader_id, username, password, temp_password,
                        first_name, middle_name, last_name, suffix_name,
                        gender, civil_status, birth_date, residency_start, birth_place, age, contact_number, email,
                        res_province, res_city_municipality, res_barangay, res_zone, res_street_address, citizenship,
                        religion, occupation, resident_delete_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        first_name = VALUES(first_name),
                        middle_name = VALUES(middle_name),
                        last_name = VALUES(last_name),
                        suffix_name = VALUES(suffix_name),
                        gender = VALUES(gender),
                        civil_status = VALUES(civil_status),
                        birth_date = VALUES(birth_date),
                        birth_place = VALUES(birth_place),
                        contact_number = VALUES(contact_number),
                        email = VALUES(email),
                        occupation = VALUES(occupation)");

                $stmt->bind_param(
                    "iissssssssssssissiiisssssi",
                    $employee_id, $zone_leader_id, $username, $password, $raw_password,
                    $first_name, $middle_name, $last_name, $suffix_name,
                    $gender, $civil_status, $birth_date, $residency_start, $birth_place, $age,
                    $contact_number, $email, $res_province, $res_city, $res_barangay,
                    $res_zone, $full_address, $citizenship, $religion, $occupation, $resident_delete_status
                );

                $stmt->execute();
                $last_ID = $mysqli->insert_id;
                $importedCount = count($processed_full_names);
                $trigs->isResidentBatchAdded(2, $importedCount);

                // Email credentials (skip if missing/invalid)
                sendCredentialsIfPresent($email, $raw_password);
            }

            if ($duplicates_found) {
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Duplicate Entry',
                        text: 'Some duplicate entries were found and skipped.',
                        confirmButtonColor: '#d33'
                    });
                </script>";
                exit();
            } else {
                echo "<script>
                    Swal.fire({
                      icon: 'success',
                      title: 'Success!',
                      text: 'Resident Imported Successfully.',
                      confirmButtonColor: '#3085d6'
                    }).then(() => {
                      window.location.href = '{$baseUrl}';
                    });
                </script>";
            }
            exit;

        } catch (Exception $e) {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Oops!',
                    text: '" . addslashes($e->getMessage()) . "',
                    confirmButtonColor: '#d33'
                });
            </script>";
        }
    }
}

/* ======================================================================
   Add Primary + Family
   ====================================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['firstName'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo "<script>
            Swal.fire({icon:'error',title:'Security Alert!',text:'CSRF token mismatch. Operation blocked.',confirmButtonColor:'#d33'});
        </script>";
        exit();
    }

    // Primary resident data
    $firstName           = sanitize_input($_POST['firstName']);
    $middleName          = sanitize_input($_POST['middleName']);
    $lastName            = sanitize_input($_POST['lastName']);
    $suffixName          = sanitize_input($_POST['suffixName']);
    $birthDate           = sanitize_input($_POST['birthDate']);
    $residency_start     = sanitize_input($_POST['residency_start']);
    $birthPlace          = sanitize_input($_POST['birthPlace']);
    $gender              = sanitize_input($_POST['gender']);
    $contactNumber       = sanitize_input($_POST['contactNumber']);
    $civilStatus         = sanitize_input($_POST['civilStatus']);
    $email               = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $usernameField       = sanitize_input($_POST['username'] ?? '');
    $province_id         = sanitize_input($_POST['province']);
    $city_municipality_id= sanitize_input($_POST['city_municipality']);
    $barangay_id         = sanitize_input($_POST['barangay']);
    $res_zone            = sanitize_input($_POST['res_zone']);
    $zone_leader         = sanitize_input($_POST['zone_leader']);
    $res_street_address  = sanitize_input($_POST['res_street_address']);
    $citizenship         = sanitize_input($_POST['citizenship']);
    $religion            = sanitize_input($_POST['religion']);
    $occupation          = sanitize_input($_POST['occupation']);
    $employee_id         = $_SESSION['employee_id'];

    // Determine login username (email first, else manual)
    $login_username = !empty($email) ? strtolower($email) : strtolower($usernameField);

    // Required validation
    $required = [$firstName, $lastName, $birthDate, $gender, $contactNumber, $res_zone, $res_street_address, $citizenship, $religion, $occupation];
    foreach ($required as $value) {
        if (empty($value)) {
            echo "<script>
                Swal.fire({icon:'error',title:'Error',text:'Missing required field for primary resident.',confirmButtonColor:'#d33'});
            </script>";
            exit();
        }
    }

    // At least one of Email or Username (MANDATORY if no email)
    if (empty($email) && empty($usernameField)) {
        echo "<script>
            Swal.fire({icon:'error',title:'Error',text:'Please provide either Email or Username for the primary resident.',confirmButtonColor:'#d33'});
        </script>";
        exit();
    }

    // Duplicate name (active only)
    $stmt = $mysqli->prepare("SELECT id FROM residents 
        WHERE first_name = ? AND middle_name <=> ? AND last_name = ? AND suffix_name <=> ? 
        AND resident_delete_status = 0");
    $stmt->bind_param("ssss", $firstName, $middleName, $lastName, $suffixName);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo "<script>
            Swal.fire({icon:'error',title:'Duplicate Entry',text:'Primary resident with the same name already exists.',confirmButtonColor:'#d33'});
        </script>";
        exit();
    }
    $stmt->close();

    // Duplicate username (active only)
    $stmt = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND resident_delete_status = 0");
    $stmt->bind_param("s", $login_username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo "<script>
            Swal.fire({icon:'error',title:'Duplicate Username',text:'That username is already taken by an active resident.',confirmButtonColor:'#d33'});
        </script>";
        exit();
    }
    $stmt->close();

    // Duplicate email (active only)
    if (!empty($email)) {
        $stmt = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo "<script>
                Swal.fire({icon:'error',title:'Duplicate Email',text:'That email is already registered with an active resident.',confirmButtonColor:'#d33'});
            </script>";
            exit();
        }
        $stmt->close();
    }

    // Age & passwords (hash + temp_password)
    $birthDateObj  = new DateTime($birthDate);
    $today         = new DateTime();
    $age           = $today->diff($birthDateObj)->y;
    $raw_password  = generatePassword();
    $password      = password_hash($raw_password, PASSWORD_DEFAULT);

    // Transaction
    $mysqli->begin_transaction();

    try {
        // Insert primary with temp_password
        $stmt = $mysqli->prepare("INSERT INTO residents (
            employee_id, zone_leader_id, username, password, temp_password,
            first_name, middle_name, last_name, suffix_name, gender, civil_status,
            birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
            res_barangay, res_zone, res_street_address, citizenship, religion, occupation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "iissssssssssssissiiisssss",
            $employee_id, $zone_leader, $login_username, $password, $raw_password,
            $firstName, $middleName, $lastName, $suffixName,
            $gender, $civilStatus, $birthDate, $residency_start, $birthPlace, $age, $contactNumber,
            $email, $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address,
            $citizenship, $religion, $occupation
        );

        if (!$stmt->execute()) {
            throw new Exception("Error inserting primary resident: " . $stmt->error);
        }

        $primary_resident_id = $mysqli->insert_id;
        $trigs->isAdded(2, $primary_resident_id);

        /* ---- FAMILY MEMBERS ---- */
        $famPasswords = [];
        if (isset($_POST['family_firstName']) && is_array($_POST['family_firstName'])) {
            $family_first_names     = $_POST['family_firstName'];
            $family_middle_names    = $_POST['family_middleName'] ?? [];
            $family_last_names      = $_POST['family_lastName'] ?? [];
            $family_suffix_names    = $_POST['family_suffixName'] ?? [];
            $family_birth_dates     = $_POST['family_birthDate'] ?? [];
            $family_genders         = $_POST['family_gender'] ?? [];
            $family_birthplace      = $_POST['family_birthplace'] ?? [];
            $family_relationships   = $_POST['family_relationship'] ?? [];
            $family_contact_numbers = $_POST['family_contactNumber'] ?? [];
            $family_civil_statuses  = $_POST['family_civilStatus'] ?? [];
            $family_occupations     = $_POST['family_occupation'] ?? [];
            $family_emails          = $_POST['family_email'] ?? [];
            $family_usernames       = $_POST['family_username'] ?? [];

            for ($i = 0; $i < count($family_first_names); $i++) {
                if (empty($family_first_names[$i]) || empty($family_last_names[$i]) || empty($family_birth_dates[$i]) || empty($family_genders[$i])) {
                    continue;
                }

                $fam_firstName       = sanitize_input($family_first_names[$i]);
                $fam_middleName      = sanitize_input($family_middle_names[$i] ?? '');
                $fam_lastName        = sanitize_input($family_last_names[$i]);
                $fam_suffixName      = sanitize_input($family_suffix_names[$i] ?? '');
                $fam_birthDate       = sanitize_input($family_birth_dates[$i]);
                $fam_gender          = sanitize_input($family_genders[$i]);
                $fam_birthplace      = sanitize_input($family_birthplace[$i] ?? '');
                $fam_relationship    = sanitize_input($family_relationships[$i] ?? 'Child');
                $fam_contactNumber   = sanitize_input($family_contact_numbers[$i] ?? '0000000000');
                $fam_civilStatus     = sanitize_input($family_civil_statuses[$i] ?? 'Single');
                $fam_occupation      = sanitize_input($family_occupations[$i] ?? '');
                $fam_email           = filter_var($family_emails[$i] ?? '', FILTER_SANITIZE_EMAIL);
                $fam_username_field  = sanitize_input($family_usernames[$i] ?? '');

                if (empty($fam_email) && empty($fam_username_field)) {
                    throw new Exception("Child #".($i+1).": Please provide either Email or Username.");
                }

                $fam_login_username = !empty($fam_email) ? strtolower($fam_email) : strtolower($fam_username_field);

                // Duplicate username (active only)
                $chk = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND resident_delete_status = 0");
                $chk->bind_param("s", $fam_login_username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    throw new Exception("Child #".($i+1).": Username already exists.");
                }
                $chk->close();

                // Duplicate email (active only)
                if (!empty($fam_email)) {
                    $chk = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0");
                    $chk->bind_param("s", $fam_email);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        throw new Exception("Child #".($i+1).": Email already in use by active resident.");
                    }
                    $chk->close();
                }

                // Age & password
                $fam_birthDateObj  = new DateTime($fam_birthDate);
                $fam_age           = $today->diff($fam_birthDateObj)->y;
                $fam_raw_password  = generatePassword();
                $fam_password      = password_hash($fam_raw_password, PASSWORD_DEFAULT);
                $famPasswords[$i]  = ['email' => $fam_email, 'pass' => $fam_raw_password];

                // Insert child with temp_password
                $fam_stmt = $mysqli->prepare("INSERT INTO residents (
                    employee_id, zone_leader_id, username, password, temp_password,
                    first_name, middle_name, last_name, suffix_name, gender, civil_status,
                    birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
                    res_barangay, res_zone, res_street_address, citizenship, religion, occupation
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $fam_stmt->bind_param(
                    "iissssssssssssissiiisssss",
                    $employee_id, $zone_leader, $fam_login_username, $fam_password, $fam_raw_password,
                    $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName,
                    $fam_gender, $fam_civilStatus, $fam_birthDate, $residency_start, $fam_birthplace, $fam_age, $fam_contactNumber, $fam_email,
                    $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address, $citizenship, $religion, $fam_occupation
                );

                if (!$fam_stmt->execute()) {
                    throw new Exception("Error inserting family member: " . $fam_stmt->error);
                }

                $family_member_id = $mysqli->insert_id;
                $trigs->isAdded(2, $family_member_id);

                if (!empty($fam_relationship)) {
                    $rel_stmt = $mysqli->prepare("INSERT INTO resident_relationships 
                        (resident_id, related_resident_id, relationship_type, created_by, created_at) 
                        VALUES (?, ?, ?, ?, NOW())");
                    $rel_stmt->bind_param("iisi", $primary_resident_id, $family_member_id, $fam_relationship, $employee_id);
                    if (!$rel_stmt->execute()) {
                        throw new Exception("Error inserting relationship: " . $rel_stmt->error);
                    }
                }
            }
        }

        $mysqli->commit();

        // Send credentials (only if present & valid)
        sendCredentialsIfPresent($email, $raw_password);

        if (!empty($famPasswords)) {
            foreach ($famPasswords as $fp) {
                sendCredentialsIfPresent($fp['email'] ?? '', $fp['pass']);
            }
        }

        $family_count = isset($_POST['family_firstName']) ? count(array_filter($_POST['family_firstName'])) : 0;
        $success_message = "Primary resident added successfully";
        if ($family_count > 0) {
            $success_message .= " along with {$family_count} child/children";
        }

        echo "<script>
            Swal.fire({icon:'success',title:'Success!',text:'" . addslashes($success_message) . "',confirmButtonColor:'#3085d6'})
            .then(() => { window.location.href = '{$baseUrl}'; });
        </script>";
        exit();

    } catch (Exception $e) {
        $mysqli->rollback();
        echo "<script>
            Swal.fire({icon:'error',title:'Oops!',text:'" . addslashes($e->getMessage()) . "',confirmButtonColor:'#d33'});
        </script>";
    }
}

/* ======================================================================
   Fetch options + Residents list
   ====================================================================== */

$zones     = $mysqli->query("SELECT Id, Zone_Name FROM zone")->fetch_all(MYSQLI_ASSOC);
$provinces = $mysqli->query("SELECT province_id, province_name FROM province")->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT 
    id, 
    first_name, middle_name, last_name, suffix_name,
    gender, res_zone, contact_number, email, civil_status, 
    birth_date, residency_start, age, birth_place, 
    res_street_address, citizenship, religion, occupation, username, temp_password
FROM residents
WHERE resident_delete_status = 0 
  AND CONCAT(first_name, ' ', IFNULL(middle_name,''), ' ', last_name) LIKE ?";

if (!empty($_GET['filter_gender'])) {
    $sql .= " AND gender = ?";
}
if (!empty($_GET['filter_zone'])) {
    $sql .= " AND res_zone = ?";
}
if (!empty($_GET['filter_status'])) {
    $sql .= " AND civil_status = ?";
}
$sql .= " LIMIT ? OFFSET ?";

$searchTerm = "%$search%";
$params     = [$searchTerm];
$types      = 's';

if (!empty($_GET['filter_gender'])) { $params[] = $_GET['filter_gender']; $types .= 's'; }
if (!empty($_GET['filter_zone']))   { $params[] = $_GET['filter_zone'];   $types .= 's'; }
if (!empty($_GET['filter_status'])) { $params[] = $_GET['filter_status']; $types .= 's'; }

$params[] = $limit;
$params[] = $offset;
$types   .= 'ii';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee List</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
    
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/Resident/res.css">
</head>

<body>

<div class="container my-5">
    <h2 class="page-title"><i class="fas fa-users"></i> Resident List</h2>
    
<div class="d-flex justify-content-start mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addResidentModal">
        <i class="fas fa-user-plus"></i> Add Resident
    </button>
    <!-- Link Parent-Child Modal Trigger -->
<button class="btn btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#linkRelationshipModal">
    <i class="fas fa-link"></i> Link Parent-Child
</button>

<!-- Navigate to Linked Families Page -->
<a href="<?php echo $redirects['family']; ?>"class="btn btn-info ms-2">
    <i class="fas fa-users"></i> View Linked Families
</a>

</div>


<!-- <div class="d-flex justify-content-end mb-3">
    <button class="btn btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#linkRelationshipModal">
        <i class="fas fa-link"></i> Link Parent-Child
    </button>
</div> -->

<form action="" method="POST" enctype="multipart/form-data" class="mb-2">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <label for="excel_file" class="form-label mb-1"><i class="fa-solid fa-file-excel me-1"></i> Upload Excel File (Batch Residents)</label>
    <input type="file" name="excel_file" id="excel_file" class="form-control mb-2" accept=".xlsx, .xls" required>
    <button type="submit" name="import_excel" class="btn btn-primary">Import Residents</button>
</form>

<form method="GET" action="index_Admin.php" class="row g-2 mb-3">
  <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'resident_info' ?>">

  <div class="col-md-2">
    <select name="filter_gender" class="form-select">
      <option value="">All Genders</option>
      <option value="Male" <?= ($_GET['filter_gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
      <option value="Female" <?= ($_GET['filter_gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
    </select>
  </div>

  <div class="col-md-2">
    <select name="filter_zone" class="form-select">
      <option value="">All Zones</option>
      <?php foreach ($zones as $zone): ?>
        <option value="<?= $zone['Zone_Name'] ?>" <?= ($_GET['filter_zone'] ?? '') === $zone['Zone_Name'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($zone['Zone_Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-2">
    <select name="filter_status" class="form-select">
      <option value="">All Status</option>
      <option value="Single" <?= ($_GET['filter_status'] ?? '') === 'Single' ? 'selected' : '' ?>>Single</option>
      <option value="Married" <?= ($_GET['filter_status'] ?? '') === 'Married' ? 'selected' : '' ?>>Married</option>
      <option value="Widowed" <?= ($_GET['filter_status'] ?? '') === 'Widowed' ? 'selected' : '' ?>>Widowed</option>
      <option value="Divorced" <?= ($_GET['filter_status'] ?? '') === 'Divorced' ? 'selected' : '' ?>>Divorced</option>
    </select>
  </div>

  <div class="col-md-3">
    <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" class="form-control" placeholder="Search name...">
  </div>

  <div class="col-md-1">
    <button type="submit" class="btn btn-primary w-100">Search/Filter</button>
  </div>

  <div class="col-md-2">
    <?php $resbaseUrl = enc_page('resident_info'); ?>
    <a href="<?= $resbaseUrl ?>" class="btn btn-secondary w-100">Reset</a>
  </div>
</form>
<!-- dri taman -->

    
    <!-- Table to display residents -->
<div class="card shadow-sm mb-4">

  <div class="card-header bg-primary text-white"> Resident List
  </div>
  <div class="card-body p-0">
    <div class="table-responsive w-100" style="height: 400px; overflow-y: auto;">

<table class="table table-bordered table-striped table-hover w-100 mb-0" style="table-layout: auto;">

<thead>
    <tr>
        <th style="width: 200px;">Last Name</th>
        <th style="width: 200px;">First Name</th>
        <th style="width: 200px;">Middle Name</th>
        <th style="width: 200px;">Extension</th>
        <th style="width: 200px;">Address</th>
        <th style="width: 200px;">Birthdate</th>
        <th style="width: 200px;">Sex</th>
        <th style="width: 200px;">Status</th>
        <th style="width: 200px;">Occupation</th>
        <th style="width: 200px;">Actions</th> <!-- Let this stretch -->
    </tr>
</thead>

<tbody id="residentTableBody">
<?php
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        include 'components/resident_modal/resident_row.php';
    }
} else {
    echo '<tr><td colspan="5" class="text-center">No residents found.</td></tr>';
}
?>
</tbody>

</table>
<?php include 'components/resident_modal/view_modal.php'; ?>
<?php include 'components/resident_modal/edit_modal.php'; ?>
<?php include 'components/resident_modal/add_modal.php'; ?>
<?php include 'components/resident_modal/link_modal.php'; ?>


<!-- Auto-filter parents based on selected child -->
<script>
document.getElementById("childSelect").addEventListener("change", function() {
    const selectedOption = this.options[this.selectedIndex];
    const childLast = selectedOption.getAttribute("data-lastname").toLowerCase();
    const childMiddle = selectedOption.getAttribute("data-middlename").toLowerCase();

    const parentSelect = document.getElementById("parentSelect");
    for (let option of parentSelect.options) {
        const parentLast = option.getAttribute("data-lastname")?.toLowerCase();
        if (parentLast && (parentLast === childLast || parentLast === childMiddle)) {
            option.style.display = "block";
        } else {
            option.style.display = "none";
        }
    }

    parentSelect.value = "";
});
document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("addResidentForm");
  if (!form) return;

  form.addEventListener("submit", function (e) {
    let valid = true;
    let msg = "";

    // Primary
    const pEmail = (document.getElementById('primary_email')?.value || '').trim();
    const pUser  = (document.getElementById('primary_username')?.value || '').trim();
    if (!pEmail && !pUser) {
      valid = false;
      msg = "Please provide either Email or Username for the primary resident.";
    }

    // Children
    const familyBlocks = document.querySelectorAll('#familyMembersContainer .family-member');
    familyBlocks.forEach((block, idx) => {
      const cEmail = (block.querySelector('.family-email')?.value || '').trim();
      const cUser  = (block.querySelector('.family-username')?.value || '').trim();
      if (!cEmail && !cUser) {
        valid = false;
        msg = `Child #${idx + 1}: Please provide either Email or Username.`;
      }
    });

    if (!valid) {
      e.preventDefault();
      Swal.fire({
        icon: 'error',
        title: 'Missing Login Info',
        text: msg
      });
    }
  });
});

</script>





<!-- Batch Upload Modal -->
<div class="modal fade" id="batchUploadModal" tabindex="-1" aria-labelledby="batchUploadModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="index_Admin.php?page=resident_info" method="POST" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="batchUploadModalLabel">Batch Upload Residents</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="excelFile" class="form-label">Upload Excel File (.xlsx)</label>
            <input type="file" class="form-control" name="excelFile" accept=".xlsx" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>
</div>




  <!-- Pagination (windowed) -->
  <?php
  // Build base + query string
  $baseUrl  = strtok($redirects['residents'], '?');
  $pageParam = ['page' => $_GET['page'] ?? 'resident_info'];
  $filters = array_filter([
      'search'        => $_GET['search'] ?? '',
      'filter_gender' => $_GET['filter_gender'] ?? '',
      'filter_zone'   => $_GET['filter_zone'] ?? '',
      'filter_status' => $_GET['filter_status'] ?? ''
  ]);
  $pageBase = $baseUrl;
  $qs       = '?' . http_build_query(array_merge($pageParam, $filters));

  // Window compute
  $window = 2; // pages left/right of current
  $start  = max(1, $page - $window);
  $end    = min($total_pages, $page + $window);
  if ($start > 1 && $end - $start < $window*2) $start = max(1, $end - $window*2);
  if ($end < $total_pages && $end - $start < $window*2) $end = min($total_pages, $start + $window*2);
  ?>

  <nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-end">

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
<script src="components/resident_modal/email.js"></script>

<script>

let familyMemberCount = 0;
let editFamilyMemberCount = 0;

function toggleFamilySection() {
    const checkbox = document.getElementById('addFamilyMembers');
    const section = document.getElementById('familyMembersSection');

    if (checkbox.checked) {
        section.style.display = 'block';
        if (familyMemberCount === 0) {
            addFamilyMember();
        }
    } else {
        section.style.display = 'none';
        document.getElementById('familyMembersContainer').innerHTML = '';
        familyMemberCount = 0;
    }
}

function addFamilyMember() {
  familyMemberCount++;
  const container = document.getElementById('familyMembersContainer');

  const html = `
    <div class="family-member border rounded p-3 mb-3 bg-light" id="familyMember${familyMemberCount}">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-primary mb-0"><i class="fas fa-user-friends"></i> Child #${familyMemberCount}</h6>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeFamilyMember(${familyMemberCount})">
          <i class="fas fa-trash"></i> Remove
        </button>
      </div>
      ${generateFamilyMemberFields('family')}
    </div>
  `;

  container.insertAdjacentHTML('beforeend', html);

  // ⬇️ integrate toggle for this new child block
  const newBlock = document.getElementById(`familyMember${familyMemberCount}`);
  applyEmailUsernameToggle(newBlock);

  // your existing validation hook
  setupChildNameValidation();
}

function removeFamilyMember(id) {
    const el = document.getElementById(`familyMember${id}`);
    if (el) el.remove();
    familyMemberCount--;

    const members = document.querySelectorAll('#familyMembersContainer .family-member');
    members.forEach((el, index) => {
        const h6 = el.querySelector('h6');
        h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
    });
}

// ---------- Edit Modal Version ----------

function toggleEditFamilySection() {
    const checkbox = document.getElementById('editAddFamilyMembers');
    const section = document.getElementById('editFamilyMembersSection');

    if (checkbox.checked) {
        section.style.display = 'block';
        if (editFamilyMemberCount === 0) {
            addEditFamilyMember();
        }
    } else {
        section.style.display = 'none';
        document.getElementById('editFamilyMembersContainer').innerHTML = '';
        editFamilyMemberCount = 0;
    }
}

function addEditFamilyMember() {
  editFamilyMemberCount++;
  const container = document.getElementById('editFamilyMembersContainer');

  const html = `
    <div class="family-member border rounded p-3 mb-3 bg-light" id="editFamilyMember${editFamilyMemberCount}">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-primary mb-0"><i class="fas fa-user-friends"></i> Child #${editFamilyMemberCount}</h6>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeEditFamilyMember(${editFamilyMemberCount})">
          <i class="fas fa-trash"></i> Remove
        </button>
      </div>
      ${generateFamilyMemberFields('edit_family')}
    </div>
  `;

  container.insertAdjacentHTML('beforeend', html);

  // 👇 NEW: bind the email/username hide–show for this EDIT block
  const newEditBlock = document.getElementById(`editFamilyMember${editFamilyMemberCount}`);
  applyEmailUsernameToggle(newEditBlock);

  // existing validation hook
  setupChildNameValidation();
}


function removeEditFamilyMember(id) {
    const el = document.getElementById(`editFamilyMember${id}`);
    if (el) el.remove();
    editFamilyMemberCount--;

    const members = document.querySelectorAll('#editFamilyMembersContainer .family-member');
    members.forEach((el, index) => {
        const h6 = el.querySelector('h6');
        h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
    });
}

function setupChildNameValidation() {
  const container = document.getElementById('familyMembersContainer');
  if (!container) return;

  const selectors = ['.child-first-name', '.child-middle-name', '.child-last-name', '.child-suffix-name'];

  selectors.forEach(selector => {
    container.querySelectorAll(selector).forEach(input => {
      input.addEventListener('blur', checkForDuplicateNames);
    });
  });

  // Also listen to changes in the primary resident name fields
  const primarySelectors = ['.primary-first-name', '.primary-middle-name', '.primary-last-name', '.primary-suffix-name'];
  primarySelectors.forEach(selector => {
    document.querySelector(selector)?.addEventListener('blur', checkForDuplicateNames);
  });
}


function checkForDuplicateNames() {
  const allMembers = document.querySelectorAll('#familyMembersContainer .family-member');
  const names = [];

  // Get primary name fields
  const primaryFirst  = (document.querySelector('.primary-first-name')?.value || '').trim().toLowerCase();
  const primaryMiddle = (document.querySelector('.primary-middle-name')?.value || '').trim().toLowerCase();
  const primaryLast   = (document.querySelector('.primary-last-name')?.value || '').trim().toLowerCase();
  const primarySuffix = (document.querySelector('.primary-suffix-name')?.value || '').trim().toLowerCase();

  allMembers.forEach(member => {
    const first  = member.querySelector('.child-first-name')?.value.trim().toLowerCase()  || '';
    const middle = member.querySelector('.child-middle-name')?.value.trim().toLowerCase() || '';
    const last   = member.querySelector('.child-last-name')?.value.trim().toLowerCase()   || '';
    const suffix = member.querySelector('.child-suffix-name')?.value.trim().toLowerCase() || '';

    names.push({ first, middle, last, suffix, element: member });
  });

  names.forEach((current, i) => {
    const feedback = current.element.querySelector('.child-name-feedback');
    const firstInput  = current.element.querySelector('.child-first-name');
    const middleInput = current.element.querySelector('.child-middle-name');
    const lastInput   = current.element.querySelector('.child-last-name');
    const suffixInput = current.element.querySelector('.child-suffix-name');

    let isDuplicate = false;

    // Compare against other children
    for (let j = 0; j < names.length; j++) {
      if (i !== j &&
          current.first === names[j].first &&
          current.middle === names[j].middle &&
          current.last === names[j].last &&
          current.suffix === names[j].suffix
      ) {
        isDuplicate = true;
        break;
      }
    }

    // Compare against primary resident
    const matchesPrimary = (
      current.first === primaryFirst &&
      current.middle === primaryMiddle &&
      current.last === primaryLast &&
      current.suffix === primarySuffix
    );

    if (isDuplicate || matchesPrimary) {
      [firstInput, middleInput, lastInput, suffixInput].forEach(input => {
        if (input) input.classList.add('is-invalid');
      });

      feedback.textContent = matchesPrimary
        ? "Child's name must not be the same as the primary resident."
        : "Duplicate child name detected.";
      feedback.style.display = 'block';
    } else {
      [firstInput, middleInput, lastInput, suffixInput].forEach(input => {
        if (input) input.classList.remove('is-invalid');
      });
      feedback.textContent = "";
      feedback.style.display = 'none';
    }
  });
}



// ---------- Shared Template ----------

function generateFamilyMemberFields(prefix) {
  return `
    <div class="row mb-3">
      <div class="col-md-3">
        <small>First Name<span class="text-danger">*</span></small>
        <input type="text" class="form-control child-first-name" name="${prefix}_firstName[]" placeholder="First Name *" required>
      </div>
      <div class="col-md-3">
        <small>Middle Name</small>
        <input type="text" class="form-control child-middle-name" name="${prefix}_middleName[]" placeholder="Middle Name">
      </div>
      <div class="col-md-3">
        <small>Last Name<span class="text-danger">*</span></small>
        <input type="text" class="form-control child-last-name" name="${prefix}_lastName[]" placeholder="Last Name *" required>
        <div class="child-name-feedback invalid-feedback"></div>
      </div>
      <div class="col-md-3">
        <small>Suffix</small>
        <input type="text" class="form-control child-suffix-name" name="${prefix}_suffixName[]" placeholder="Suffix">
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Birthdate<span class="text-danger">*</span></small>
        <input type="date" class="form-control" name="${prefix}_birthDate[]" required>
      </div>
      <div class="col-md-3">
        <small>Gender<span class="text-danger">*</span></small>
        <select class="form-select" name="${prefix}_gender[]" required>
          <option value="" disabled selected>Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>
      <div class="col-md-3">
        <small>Relationship<span class="text-danger">*</span></small>
        <select class="form-select" name="${prefix}_relationship[]" required>
          <option value="">Select Relationship</option>
          <option value="Child">Child</option>
        </select>
      </div>
      <div class="col-md-3">
        <small>Contact Number<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_contactNumber[]" placeholder="Contact Number" required>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Civil Status<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_civilStatus[]" placeholder="Civil Status" required>
      </div>
      <div class="col-md-3">
        <small>Occupation<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_occupation[]" placeholder="Occupation" required>
      </div>

      <!-- Email/Username pair for child -->
      <div class="col-md-3 family-email-wrapper">
        <small>Email</small>
        <input type="email" class="form-control family-email" name="${prefix}_email[]" placeholder="Email (if available)">
      </div>
      <div class="col-md-3 family-username-wrapper">
        <small>Username</small>
        <input type="text" class="form-control family-username" name="${prefix}_username[]" placeholder="Username if no email">
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Birth Place<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_birthplace[]" placeholder="Birth Place" required>
      </div>
    </div>
  `;
}




    $(document).ready(function () {
    $('#province').change(function () {
        let provinceId = $(this).val();
        $('#city_municipality').html('<option value="">Loading...</option>').prop('disabled', true);
        $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);

        $.ajax({
            url: 'include/get_locations.php',
            method: 'POST',
            data: { province_id: provinceId },
            success: function (response) {
                let data = JSON.parse(response);
                if (data.type === 'city_municipality') {
                    $('#city_municipality').html(data.options.join('')).prop('disabled', false);
                }
            }
        });
    });

    $('#city_municipality').change(function () {
        let cityId = $(this).val();
        $('#barangay').html('<option value="">Loading...</option>').prop('disabled', true);

        $.ajax({
            url: 'include/get_locations.php',
            method: 'POST',
            data: { municipality_id: cityId },
            success: function (response) {
                let data = JSON.parse(response);
                if (data.status === 'success' && data.type === 'barangay') {
                    let options = '<option value="">Select Barangay</option>';
                    $.each(data.data, function (index, barangay) {
                        options += '<option value="' + barangay.id + '">' + barangay.name + '</option>';
                    });
                    $('#barangay').html(options).prop('disabled', false);
                }
            }
        });
    });
});

document.getElementById('editForm').addEventListener('submit', function(event) {
    event.preventDefault(); // Always prevent default to wait for confirmation

    Swal.fire({
        title: 'Are you sure?',
        text: "Do you want to save the changes?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, save it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            this.submit(); // Proceed with form submission
        } else {
            Swal.fire({
                icon: 'info',
                title: 'Cancelled',
                text: 'Changes were not saved.',
                confirmButtonColor: '#3085d6'
            });
        }
    });
});
$('select[name="res_zone"]').change(function () {
    var selectedZone = $(this).val();

    if (!selectedZone) {
        $('#zone_leader').val('');
        $('#zone_leader_id').val('');
        return;
    }

    $.ajax({
        url: 'include/get_zone_leader.php',
        type: 'POST',
        data: { zone: selectedZone },
        success: function (response) {
            let data = JSON.parse(response);
            if (data.status === 'success') {
                $('#zone_leader').val(data.leader_name); // Display name
                $('#zone_leader_id').val(data.leader_id); // Store ID
            } else {
                $('#zone_leader').val('No leader found');
                $('#zone_leader_id').val('');
            }
        }
    });
});

function applyEmailUsernameToggle(container) {
  const emailInput = container.querySelector(".family-email");
  const usernameInput = container.querySelector(".family-username");
  const emailWrapper = container.querySelector(".family-email-wrapper");
  const usernameWrapper = container.querySelector(".family-username-wrapper");
  if (!emailInput || !usernameInput || !emailWrapper || !usernameWrapper) return;

  function toggleFields() {
    if (emailInput.value.trim()) {
      usernameWrapper.style.display = "none";
    } else if (usernameInput.value.trim()) {
      emailWrapper.style.display = "none";
    } else {
      emailWrapper.style.display = "";
      usernameWrapper.style.display = "";
    }
  }
  emailInput.addEventListener("input", toggleFields);
  usernameInput.addEventListener("input", toggleFields);
  toggleFields(); // set initial state
}

</script>
<script>
// ================== HELPERS ==================
function ymdToday(){ return new Date().toISOString().slice(0,10); }
function parseYMD(s){ return new Date(`${s}T00:00:00`); }
function ageFromYMD(s){
  const d=parseYMD(s), t=new Date();
  let a=t.getFullYear()-d.getFullYear();
  const m=t.getMonth()-d.getMonth();
  if(m<0 || (m===0 && t.getDate()<d.getDate())) a--;
  return a;
}
function ensureFeedbackEl(input){
  let fb=input.nextElementSibling;
  if(!fb || !fb.classList?.contains('invalid-feedback')){
    fb=document.createElement('div');
    fb.className='invalid-feedback';
    input.insertAdjacentElement('afterend', fb);
  }
  return fb;
}
function setInvalid(input,msg){
  input.classList.add('is-invalid');
  const fb=ensureFeedbackEl(input);
  fb.textContent=msg; fb.style.display='block';
  input.setCustomValidity(msg);
}
function clearInvalid(input){
  input.classList.remove('is-invalid');
  const fb=input.nextElementSibling;
  if(fb?.classList?.contains('invalid-feedback')){ fb.textContent=''; fb.style.display='none'; }
  input.setCustomValidity('');
}

// ================== VALIDATORS ==================
function validatePrimaryBirthdateEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}
function validateChildBirthdateEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  if(ageFromYMD(v)>17){ setInvalid(el,'Family member must be 17 years old or below.'); return false; }
  clearInvalid(el); return true;
}
function validateResidencyStartEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Residency start cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}

// ================== DELEGATED BINDINGS ==================
// Works even if inputs are added later (modal content, dynamic rows)
document.addEventListener('input', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))       validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))   validateChildBirthdateEl(t);
}, true);

document.addEventListener('change', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))       validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))   validateChildBirthdateEl(t);
}, true);

// Set native max attribute for residency_start whenever it appears / gains focus
function enforceResidencyMax(el){ if(el) el.setAttribute('max', ymdToday()); }
document.addEventListener('focusin', (e)=>{
  if(e.target.matches('input[name="residency_start"]')) enforceResidencyMax(e.target);
});

// Bootstrap modal hook (when Add Resident opens)
document.addEventListener('shown.bs.modal', (e)=>{
  if(e.target.id==='addResidentModal'){
    enforceResidencyMax(e.target.querySelector('input[name="residency_start"]'));
    // Initial passes for visible fields
    e.target.querySelectorAll('input[name="birthDate"]').forEach(validatePrimaryBirthdateEl);
    e.target.querySelectorAll('input[name="residency_start"]').forEach(validateResidencyStartEl);
    e.target.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
  }
});

// Also run once on DOM ready (in case modal markup is already present)
document.addEventListener('DOMContentLoaded', ()=>{
  enforceResidencyMax(document.querySelector('input[name="residency_start"]'));
  const p=document.querySelector('input[name="birthDate"]'); if(p) validatePrimaryBirthdateEl(p);
  document.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);

  // Guard on submit
  const addForm=document.getElementById('addResidentForm');
  if(addForm){
    addForm.addEventListener('submit',(e)=>{
      let ok=true;
      const pDOB=document.querySelector('input[name="birthDate"]');        if(pDOB) ok=validatePrimaryBirthdateEl(pDOB)&&ok;
      const rs=document.querySelector('input[name="residency_start"]');    if(rs)   { enforceResidencyMax(rs); ok=validateResidencyStartEl(rs)&&ok; }
      document.querySelectorAll('input[name$="_birthDate[]"]').forEach(inp=>{ ok=validateChildBirthdateEl(inp)&&ok; });
      if(!ok || !addForm.checkValidity()){
        e.preventDefault();
        (addForm.querySelector(':invalid')||addForm.querySelector('.is-invalid'))?.focus();
      }
    }, true);
  }
});

// ================== DYNAMIC FAMILY ROW SUPPORT ==================
(function wrapDynamicAdders(){
  const origAdd=window.addFamilyMember;
  if(typeof origAdd==='function'){
    window.addFamilyMember=function(){
      origAdd.apply(this, arguments);
      // validate any new birthDate[] in the newly added block
      const block=document.getElementById(`familyMember${window.familyMemberCount}`);
      if(block) block.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
    };
  }
  const origAddEdit=window.addEditFamilyMember;
  if(typeof origAddEdit==='function'){
    window.addEditFamilyMember=function(){
      origAddEdit.apply(this, arguments);
      const block=document.getElementById(`editFamilyMember${window.editFamilyMemberCount}`);
      if(block) block.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
    };
  }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>