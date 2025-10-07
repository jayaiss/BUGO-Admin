<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL); 
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// session_start();
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
date_default_timezone_set('Asia/Manila');

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Prepare filter values
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$page = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$count_sql = "SELECT COUNT(*) as total FROM residents WHERE resident_delete_status = 0 
    AND CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) LIKE ?";

$params = ["%$search%"];
$types = 's';

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
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = max(ceil($total_rows / $limit), 1); // Always at least 1 page

$baseUrl = $redirects['residents'];

if ($page > $total_pages) {
    $page = 1;
}
$offset = ($page - 1) * $limit;

// kani pud pataas

if (isset($_POST['import_excel'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("❌ CSRF token mismatch. Operation blocked.");
    }
    if (!empty($_FILES['excel_file']['tmp_name'])) {
        $file = $_FILES['excel_file']['tmp_name'];

        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Arrays to keep track of emails and duplicate entries
            $processed_emails = [];
            $duplicate_emails = [];
            $duplicates_found = false; // Flag to track if any duplicate is found

            // First pass: check for duplicate emails in the entire file
            foreach ($rows as $i => $row) {
                $email = sanitize_input($row[9] ?? 'N/A');

                // Skip rows where email is N/A or empty
                if ($email === 'N/A' || empty($email)) {
                    continue; // Skip the row as it does not have a valid email
                }

                // If email is already processed, mark it as a duplicate
                if (isset($processed_emails[$email])) {
                    $duplicate_emails[] = $email; // Add to duplicates array
                }

                // Mark this email as processed
                $processed_emails[$email] = true;
            }

            // Arrays to track processed rows based on email duplicates
            $processed_full_names = [];

            // Second pass: process rows and skip duplicates
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];

                $last_name = sanitize_input($row[0] ?? 'N/A');
                $first_name = sanitize_input($row[1] ?? 'N/A');
                $middle_name = sanitize_input($row[2] ?? '');
                $suffix_name = sanitize_input($row[3] ?? '');
                $full_address = sanitize_input($row[4] ?? 'ZONE N/A N/A');
                $birth_date_raw = $row[5] ?? '2000-01-01';
                $birth_date = date('Y-m-d', strtotime($birth_date_raw));
                $gender = sanitize_input($row[6] ?? 'N/A');
                $civil_status = sanitize_input($row[7] ?? 'N/A');
                $occupation = sanitize_input($row[8] ?? 'N/A');
                $email = sanitize_input($row[9] ?? 'N/A');

                // Skip the row if email is N/A or empty or if it's in the duplicates array
                if ($email === 'N/A' || empty($email) || in_array($email, $duplicate_emails)) {
                    continue; // Skip this row
                }

                // Concatenate full name including suffix to create a unique key
                $full_name = strtolower($first_name . ' ' . $middle_name . ' ' . $last_name . ' ' . $suffix_name);
                $email = strtolower($email);

                // Check if the full name already exists in processed_full_names array
                if (isset($processed_full_names[$full_name])) {
                    continue; // Skip this row
                }

                // Mark this full name and email as processed
                $processed_full_names[$full_name] = true;
                $processed_emails[$email] = true;

                // Default/fallback values
                $employee_id = $_SESSION['employee_id'];
                $zone_leader_id = 0;
                $formatted_birth = strtolower(date('FjY', strtotime($birth_date)));
                $username = strtolower($email);
                $raw_password = generatePassword(); // <- use your secure generator
                $password = password_hash($raw_password, PASSWORD_DEFAULT);
                $birth_place = "N/A";
                $residency_start = date('Y-m-d');
                $res_province = 57;
                $res_city = 1229;
                $res_barangay = 32600;
                $contact_number = "0000000000";
                $citizenship = "N/A";
                $religion = "N/A";
                $age = date_diff(date_create($birth_date), date_create('today'))->y;
                $resident_delete_status = 0;

                // Ensure res_zone has a default value if not set
                $res_zone = sanitize_input($row[4] ?? 'ZONE N/A'); // Using address as default zone

                if ($first_name && $last_name && $birth_date) {
                    // Prepare the SQL query for insertion
                    $stmt = $mysqli->prepare("INSERT INTO residents (
                        employee_id, zone_leader_id, username, password, first_name, middle_name, last_name, suffix_name,
                        gender, civil_status, birth_date, residency_start, birth_place, age, contact_number, email,
                        res_province, res_city_municipality, res_barangay, res_zone, res_street_address, citizenship,
                        religion, occupation, resident_delete_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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

                    // Bind the parameters for insertion
                    $stmt->bind_param("iisssssssssssissiiisssssi",
                        $employee_id, $zone_leader_id, $username, $password,
                        $first_name, $middle_name, $last_name, $suffix_name,
                        $gender, $civil_status, $birth_date, $residency_start, $birth_place, $age,
                        $contact_number, $email, $res_province, $res_city, $res_barangay,
                        $res_zone, $full_address, $citizenship, $religion, $occupation, $resident_delete_status
                    );

                    // Execute the statement and check for errors
                    $stmt->execute();

                    // Send the login credentials email
                    sendCredentials($email, $raw_password); // Send the raw password to the user's email
                }
            }

            // If duplicates were found, notify the user
            if ($duplicates_found) {
                echo "<script>alert('Some duplicate entries were found and skipped.');</script>";
            } else {
                echo "<script>alert('Resident Imported Successfully.'); window.location.href = '{$redirects['residents']}';</script>";
            }
            exit;

        } catch (Exception $e) {
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
        }
    }
}

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}
function generatePassword(int $len = 12): string {
    $base = str_replace(['/', '+', '='], '', base64_encode(random_bytes($len + 2)));
    return substr($base, 0, $len);
}

function sendCredentials(string $to, string $password): void {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'jayacop9@gmail.com';          // 🔁 Your Gmail
        $mail->Password   = 'nyiq ulrn sbhz chcd';             // 🔁 App Password (not your login)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('jayacop9@gmail.com', 'Barangay Bugo');
        $mail->addAddress($to);
        $mail->Subject = 'Your Barangay Bugo Resident Portal Credentials';
        $mail->Body    = "Hi!\n\nYour Barangay Bugo portal login credentials:\nUsername: {$to}\nPassword: {$password}\n\nPlease log in and change your password. \n\nhttp://localhost/bugo-resident-side/index.php";
        $mail->send();
    } catch (Exception $e) {
        error_log("Mail error ({$to}): {$mail->ErrorInfo}");
    }
}

// Process Add Resident Form if submitted (Updated to handle family members)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['firstName'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("❌ CSRF token mismatch. Operation blocked.");
}

    
    // Primary resident data processing (existing code)
    $firstName = sanitize_input($_POST['firstName']);
    $middleName = sanitize_input($_POST['middleName']);
    $lastName = sanitize_input($_POST['lastName']);
    $suffixName = sanitize_input($_POST['suffixName']);
    $birthDate = sanitize_input($_POST['birthDate']);
    $residency_start = sanitize_input($_POST['residency_start']);
    $birthPlace = sanitize_input($_POST['birthPlace']);
    $gender = sanitize_input($_POST['gender']);
    $contactNumber = sanitize_input($_POST['contactNumber']);
    $civilStatus = sanitize_input($_POST['civilStatus']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $province_id = sanitize_input($_POST['province']);
    $city_municipality_id = sanitize_input($_POST['city_municipality']);
    $barangay_id = sanitize_input($_POST['barangay']);
    $res_zone = sanitize_input($_POST['res_zone']);
    $zone_leader = sanitize_input($_POST['zone_leader']);
    $res_street_address = sanitize_input($_POST['res_street_address']);
    $citizenship = sanitize_input($_POST['citizenship']);
    $religion = sanitize_input($_POST['religion']);
    $occupation = sanitize_input($_POST['occupation']);
    $username = $email;
    $raw_password = generatePassword();
    $password = password_hash($raw_password, PASSWORD_DEFAULT);
    $employee_id = $_SESSION['employee_id'];

    // Required validation for primary resident
    $required = [$firstName, $lastName, $birthDate, $gender, $contactNumber, $username, $password, $res_zone, $res_street_address];
    foreach ($required as $value) {
        if (empty($value)) {
            die("Error: Missing required field for primary resident.");
        }
    }

    // Duplicate name check for primary resident
    $stmt = $mysqli->prepare("SELECT id FROM residents WHERE first_name = ? AND middle_name <=> ? AND last_name = ? AND suffix_name <=> ?");
    $stmt->bind_param("ssss", $firstName, $middleName, $lastName, $suffixName);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        die("Error: Primary resident with the same name already exists.");
    }

    // // Duplicate username check for primary resident
    // $stmt = $mysqli->prepare("SELECT id FROM residents WHERE username = ?");
    // $stmt->bind_param("s", $username);
    // $stmt->execute();
    // if ($stmt->get_result()->num_rows > 0) {
    //     die("Error: Username already taken for primary resident.");
    // }

    // Calculate age for primary resident
    $birthDateObj = new DateTime($birthDate);
    $today = new DateTime();
    $age = $today->diff($birthDateObj)->y;

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert primary resident
        $stmt = $mysqli->prepare("INSERT INTO residents (
            employee_id, zone_leader_id, username, password, first_name, middle_name, last_name, suffix_name, gender, civil_status,
            birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
            res_barangay, res_zone, res_street_address, citizenship, religion, occupation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param("iisssssssssssissiiisssss",
            $employee_id, $zone_leader, $username, $password, $firstName, $middleName, $lastName, $suffixName,
            $gender, $civilStatus, $birthDate, $residency_start, $birthPlace, $age, $contactNumber, $email,
            $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address, $citizenship, $religion, $occupation
        );

        if (!$stmt->execute()) {
            throw new Exception("Error inserting primary resident: " . $stmt->error);
        }

        $primary_resident_id = $mysqli->insert_id;

        // Process family members if they exist
        if (isset($_POST['family_firstName']) && is_array($_POST['family_firstName'])) {
            
            $family_first_names = $_POST['family_firstName'];
            $family_middle_names = $_POST['family_middleName'] ?? [];
            $family_last_names = $_POST['family_lastName'] ?? [];
            $family_suffix_names = $_POST['family_suffixName'] ?? [];
            $family_birth_dates = $_POST['family_birthDate'] ?? [];
            $family_genders = $_POST['family_gender'] ?? [];
            $family_birthplace = $_POST['family_birthplace'] ?? [];
            $family_relationships = $_POST['family_relationship'] ?? [];
            $family_contact_numbers = $_POST['family_contactNumber'] ?? [];
            $family_civil_statuses = $_POST['family_civilStatus'] ?? [];
            $family_occupations = $_POST['family_occupation'] ?? [];
            $family_emails = $_POST['family_email'] ?? [];

           for ($i = 0; $i < count($family_first_names); $i++) {
                if (empty($family_first_names[$i]) || empty($family_last_names[$i]) || empty($family_birth_dates[$i]) || empty($family_genders[$i])) {
                    continue; // Skip incomplete family member entries
                }

                // Check if name matches primary resident
                $fam_firstName = sanitize_input($family_first_names[$i]);
                $fam_middleName = sanitize_input($family_middle_names[$i] ?? '');
                $fam_lastName = sanitize_input($family_last_names[$i]);
                $fam_suffixName = sanitize_input($family_suffix_names[$i] ?? '');

                if (
                    strtolower($fam_firstName) === strtolower($firstName) &&
                    strtolower($fam_middleName) === strtolower($middleName) &&
                    strtolower($fam_lastName) === strtolower($lastName) &&
                    strtolower($fam_suffixName) === strtolower($suffixName)
                ) {
                    throw new Exception("❌ Error: A family member has the same full name as the primary resident.");
                }

                // Sanitize family member data
                $fam_firstName = sanitize_input($family_first_names[$i]);
                $fam_middleName = sanitize_input($family_middle_names[$i] ?? '');
                $fam_lastName = sanitize_input($family_last_names[$i]);
                $fam_suffixName = sanitize_input($family_suffix_names[$i] ?? '');
                $fam_birthDate = sanitize_input($family_birth_dates[$i]);
                $fam_gender = sanitize_input($family_genders[$i]);
                $fam_birthplace = sanitize_input($family_birthplace[$i]);
                $fam_relationship = sanitize_input($family_relationships[$i] ?? '');
                $fam_contactNumber = sanitize_input($family_contact_numbers[$i] ?? '0000000000');
                $fam_civilStatus = sanitize_input($family_civil_statuses[$i] ?? '');
                $fam_occupation = sanitize_input($family_occupations[$i] ?? '');
                $fam_email = filter_var($family_emails[$i] ?? '', FILTER_SANITIZE_EMAIL);

                // Generate username and password for family member
                $fam_username = $fam_email;
                $fam_raw_password = generatePassword();
                $fam_password = password_hash($fam_raw_password, PASSWORD_DEFAULT);
                $famPasswords[$i] = $fam_raw_password;

                // Calculate age for family member
                $fam_birthDateObj = new DateTime($fam_birthDate);
                $fam_age = $today->diff($fam_birthDateObj)->y;

                // Insert family member with same address as primary resident
                $fam_stmt = $mysqli->prepare("INSERT INTO residents (
                    employee_id, zone_leader_id, username, password, first_name, middle_name, last_name, suffix_name, gender, civil_status,
                    birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
                    res_barangay, res_zone, res_street_address, citizenship, religion, occupation
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $fam_stmt->bind_param("iisssssssssssissiiisssss",
                    $employee_id, $zone_leader, $fam_username, $fam_password, $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName,
                    $fam_gender, $fam_civilStatus, $fam_birthDate, $residency_start, $fam_birthplace, $fam_age, $fam_contactNumber, $fam_email,
                    $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address, $citizenship, $religion, $fam_occupation
                );

                try {
                        if (!$fam_stmt->execute()) {
                            throw new Exception($fam_stmt->error, $fam_stmt->errno);
                        }
                    } catch (mysqli_sql_exception $e) {
                        if (str_contains($e->getMessage(), 'Duplicate entry')) {
                            throw new Exception("⚠️ Error: Email/Username '{$fam_email}' already exists.");
                        } else {
                            throw new Exception("Error inserting family member: " . $e->getMessage());
                        }
                    }

                $family_member_id = $mysqli->insert_id;

                // Insert relationship record if relationship is specified
                if (!empty($fam_relationship)) {
                    $rel_stmt = $mysqli->prepare("INSERT INTO resident_relationships (resident_id, related_resident_id, relationship_type, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $rel_stmt->bind_param("iisi", $primary_resident_id, $family_member_id, $fam_relationship, $employee_id);
                    
                    if (!$rel_stmt->execute()) {
                        throw new Exception("Error inserting relationship: " . $rel_stmt->error);
                    }
                }
            }
        }

        // Commit transaction
        $mysqli->commit();
        //send the default username and password to the email inputted
        sendCredentials($email, $raw_password); // To primary
            if (!empty($family_emails)) {
                foreach ($family_emails as $i => $addr) {
                    if (filter_var($addr, FILTER_VALIDATE_EMAIL) && !empty($famPasswords[$i])) {
                        sendCredentials($addr, $famPasswords[$i]);
                    }
                }
            }
        $family_count = isset($_POST['family_firstName']) ? count(array_filter($_POST['family_firstName'])) : 0;
        $success_message = "Primary resident added successfully";
        if ($family_count > 0) {
            $success_message .= " along with {$family_count} family member(s)";
        }
        $success_message .= ".";
        
        echo "<script>alert('{$success_message}'); window.location.href = '{$redirects['residents']}';</script>";
        exit();

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Fetch provinces and zones for modal form
$zones = $mysqli->query("SELECT Id, Zone_Name FROM zone")->fetch_all(MYSQLI_ASSOC);
$provinces = $mysqli->query("SELECT province_id, province_name FROM province")->fetch_all(MYSQLI_ASSOC);

// modified
$sql = "SELECT 
    id, 
    first_name, middle_name, last_name, suffix_name,
    gender, res_zone, contact_number, email, civil_status, 
    birth_date, residency_start, age, birth_place, 
    res_street_address, citizenship, religion, occupation
FROM residents
WHERE resident_delete_status = 0 
  AND CONCAT(first_name, ' ', IFNULL(middle_name,''), ' ', last_name) LIKE ?";

// Add filters if present
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
$params = [$searchTerm];
$types = 's';

if (!empty($_GET['filter_gender'])) {
    $params[] = $_GET['filter_gender'];
    $types .= 's';
}
if (!empty($_GET['filter_zone'])) {
    $params[] = $_GET['filter_zone'];
    $types .= 's';
}
if (!empty($_GET['filter_status'])) {
    $params[] = $_GET['filter_status'];
    $types .= 's';
}

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
// taman dri


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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/Resident/res.css">
</head>

<body>

<div class="container my-5">
    <h2><i class="fas fa-users"></i> Resident List</h2>

<form method="GET" action="index_barangay_secretary.php" class="row g-2 mb-3">
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
    <?php $resbaseUrl = enc_brgysec('resident_info'); ?>
    <a href="<?= $resbaseUrl ?>" class="btn btn-secondary w-100">Reset</a>
  </div>
</form>

    
    <!-- Table to display residents -->
<div class="card shadow-sm mb-4">

  <div class="card-header bg-primary text-white">
    👥 Resident List
  </div>
  <div class="card-body p-0">
    <div class="table-responsive w-100" style="height: 600px; overflow-y: auto;">

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

function confirmSaveRelationship() {
    return confirm("Are you sure you want to save this parent-child relationship?");
}   
</script>

</div>
<!-- Pagination (windowed) -->
<?php
$baseUrl   = enc_brgysec('resident_info'); // ✅ keep this
$pageParam = ['page' => $_GET['page'] ?? 'resident_info'];
$filters   = array_filter([
    'search'        => $_GET['search'] ?? '',
    'filter_gender' => $_GET['filter_gender'] ?? '',
    'filter_zone'   => $_GET['filter_zone'] ?? '',
    'filter_status' => $_GET['filter_status'] ?? ''
]);
$qs = '&' . http_build_query(array_merge($pageParam, $filters));

// Window compute
$window = 2;
$start  = max(1, $page - $window);
$end    = min($total_pages, $page + $window);

if ($start > 1 && ($end - $start) < $window * 2) {
    $start = max(1, $end - $window * 2);
}
if ($end < $total_pages && ($end - $start) < $window * 2) {
    $end = min($total_pages, $start + $window * 2);
}
?>

<nav aria-label="Page navigation" class="mt-3">
  <ul class="pagination justify-content-end">

    <!-- First -->
    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i></a>
      </li>
    <?php endif; ?>

    <!-- Previous -->
    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i></a>
      </li>
    <?php endif; ?>

    <!-- Left ellipsis -->
    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <!-- Numbers -->
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . $i ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>

    <!-- Right ellipsis -->
    <?php if ($end < $total_pages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <!-- Next -->
    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i></a>
      </li>
    <?php endif; ?>

    <!-- Last -->
    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i></a>
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
        <small>First Name<span class="text-danger">*</span> </small>
            <input type="text" class="form-control child-first-name" name="${prefix}_firstName[]" placeholder="First Name *" required>
        </div>
        <div class="col-md-3">
        <small>Middle Name</small>
            <input type="text" class="form-control child-middle-name" name="${prefix}_middleName[]" placeholder="Middle Name">
        </div>
        <div class="col-md-3">
        <small>Last Name<span class="text-danger">*</span> </small>
            <input type="text" class="form-control child-last-name" name="${prefix}_lastName[]" placeholder="Last Name *" required>
            <div class="child-name-feedback invalid-feedback"></div>
        </div>
        <div class="col-md-3">
        <small>Suffix Name</small>
            <input type="text" class="form-control child-suffix-name" name="${prefix}_suffixName[]" placeholder="Suffix">
        </div>
    </div>
    <div class="row mb-3">
        <div class="col-md-3">
        <small>Birthdate<span class="text-danger">*</span> </small>
            <input type="date" class="form-control" name="${prefix}_birthDate[]" required>
            
        </div>
        <div class="col-md-3">
        <small>Gender<span class="text-danger">*</span> </small>
            <select class="form-select" name="${prefix}_gender[]" required>
                <option value="" disabled selected>Select Gender <span class="text-danger">*</span></option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>
        <div class="col-md-3">
        <small>Relationship<span class="text-danger">*</span> </small>
            <select class="form-select" name="${prefix}_relationship[]" required>
                <option value="">Select Relationship</option>
                <option value="Child">Child</option>
            </select>
        </div>
        <div class="col-md-3">
        <small>Contact Number<span class="text-danger">*</span> </small>
            <input type="text" class="form-control" name="${prefix}_contactNumber[]" placeholder="Contact Number" required>
        </div>
    </div>
    <div class="row mb-3">
        <div class="col-md-3">
        <small>Civil Status<span class="text-danger">*</span> </small>
            <input type="text" class="form-control" name="${prefix}_civilStatus[]" placeholder="Civil Status" required>
        </div>
        <div class="col-md-3">
        <small>Occupation<span class="text-danger">*</span> </small>
            <input type="text" class="form-control" name="${prefix}_occupation[]" placeholder="Occupation" required>
        </div>
        <div class="col-md-3">
        <small>Email<span class="text-danger">*</span> </small>
            <input type="email" class="form-control family-email" name="${prefix}_email[]" placeholder="Email" required>
        </div>
        <div class="col-md-3">
        <small>Birth Place<span class="text-danger">*</span> </small>
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

// Confirm form submission before saving changes
document.getElementById('editForm').addEventListener('submit', function(event) {
    // Show confirmation prompt
    const confirmation = confirm("Are you sure you want to save the changes?");
    
    // If the user confirms, submit the form, else prevent submission
    if (!confirmation) {
        event.preventDefault();  // Prevent form submission
        alert("Changes were not saved.");
    }
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
</script>
</body>
</html>