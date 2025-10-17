<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './logs/logs_trig.php';
$trigs = new Trigger();

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function generateNumericCode(int $len = 8): string {
    // Solid numeric code generator (avoids leading-zero loss issues)
    $code = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= (string)random_int(0, 9);
    }
    return $code;
}

$zones = $mysqli->query("SELECT Id, Zone_Name FROM zone")->fetch_all(MYSQLI_ASSOC);
$provinces = $mysqli->query("SELECT province_id, province_name FROM province ORDER BY UPPER(SUBSTRING(province_name, 1, 1)) ASC, province_name ASC")->fetch_all(MYSQLI_ASSOC);

$error_message = "";
$error_fields = [];
$message = "";

/** Helpers */
function sanitize_input($data) {
    $data = trim($data);
    $data = preg_replace('/\s+/u', ' ', $data);
    return htmlspecialchars(strip_tags($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendCredentials(string $toEmail, string $username, string $plainPassword): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("sendCredentials: invalid recipient email: {$toEmail}");
        return false;
    }

    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePass = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');

    // central message builder (so all fallbacks send the same content)
    $buildMessage = function(PHPMailer $mail) use ($toEmail, $safeUser, $safePass, $username, $plainPassword) {
        $mail->setFrom('admin@bugoportal.site', 'Barangay Bugo');
        $mail->addAddress($toEmail);
        $mail->addReplyTo('admin@bugoportal.site', 'Barangay Bugo');

        // Set these to improve deliverability on some hosts
        $mail->Sender   = 'admin@bugoportal.site'; // envelope-from
        $mail->Hostname = 'bugoportal.site';        // EHLO/HELO host

        $portalLink = 'https://office.bugoportal.site/'; // or your intended portal URL

        $mail->isHTML(true);
        $mail->Subject = 'Your Barangay Bugo Employee Portal Credentials';
        $mail->Body = "
            <p>Hello,</p>
            <p>Your employee portal credentials are:</p>
            <ul>
                <li><strong>Username:</strong> {$safeUser}</li>
                <li><strong>Password:</strong> {$safePass}</li>
            </ul>
            <p>Please log in and change your password right away.</p>
            <p><a href=\"{$portalLink}\">Open Portal</a></p>
            <br><p>Thank you,<br>Barangay Bugo</p>";
        $mail->AltBody = "Username: {$username}\nPassword: {$plainPassword}\n{$portalLink}";
        $mail->CharSet = 'UTF-8';
    };

    // attempt helper for SMTP modes
    $attempt = function(string $mode, int $port) use ($buildMessage) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host          = 'mail.bugoportal.site';
        $mail->SMTPAuth      = true;
        $mail->Username      = 'admin@bugoportal.site';
        $mail->Password      = 'Jayacop@100';
        $mail->Port          = $port;
        $mail->Timeout       = 12;
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;

        // Some shared hosts use self-signed or mismatched certs
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        if ($mode === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;    // 465
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587
        }

        // $mail->SMTPDebug = 2; // enable if needed
        $buildMessage($mail);
        $mail->send();
    };

    try {
        error_log('sendCredentials: try SMTPS 465…');
        $attempt('ssl', 465);
        error_log('sendCredentials: sent via 465');
        return true;
    } catch (\Throwable $e1) {
        error_log('sendCredentials: 465 failed: '.$e1->getMessage());

        try {
            error_log('sendCredentials: try STARTTLS 587…');
            $attempt('tls', 587);
            error_log('sendCredentials: sent via 587');
            return true;
        } catch (\Throwable $e2) {
            error_log('sendCredentials: 587 failed: '.$e2->getMessage());

            // Last resort: local sendmail (works on many cPanel hosts)
            try {
                error_log('sendCredentials: fallback sendmail…');
                $fallback = new PHPMailer(true);
                $fallback->isMail();
                $buildMessage($fallback);
                $fallback->send();
                error_log('sendCredentials: sent via sendmail');
                return true;
            } catch (\Throwable $e3) {
                error_log('sendCredentials: sendmail failed: '.$e3->getMessage());
                return false;
            }
        }
    }
}


/* ─────────────────────────────────────────────────────────────────────────── */
/* ADD ROLE                                                                   */
/* ─────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roleName'])) {
    // Guard session
    if (empty($_SESSION['employee_id'])) {
        http_response_code(404);
        require_once __DIR__ . '/../security/404.html';
        exit;
    }

    $roleName    = sanitize_input($_POST['roleName']);
    $employee_id = (int)$_SESSION['employee_id'];

    if ($roleName === '') {
        echo "<script>Swal.fire({icon:'error',title:'Failed!',text:'Please enter a valid role name.'});</script>";
        exit;
    }

    $checkStmt = $mysqli->prepare("SELECT COUNT(*) FROM employee_roles WHERE Role_Name = ? AND Employee_Id = ?");
    $checkStmt->bind_param("si", $roleName, $employee_id);
    $checkStmt->execute();
    $checkStmt->bind_result($roleCount);
    $checkStmt->fetch();
    $checkStmt->close();

    if ($roleCount > 0) {
        echo "<script>Swal.fire({icon:'error',title:'Failed!',text:'Role already exists for this employee.'});</script>";
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO employee_roles (Role_Name, Employee_Id) VALUES (?, ?)");
    $stmt->bind_param("si", $roleName, $employee_id);

    if ($stmt->execute()) {
        $last_ID = $mysqli->insert_id;
        if (isset($trigs)) { $trigs->isAdded(1, $last_ID); }
        echo "<script>
            Swal.fire({icon:'success',title:'Success!',text:'Role added successfully!'})
            .then(()=>{ window.location.href = '{$redirects['officials']}'; });
        </script>";
        exit;
    } else {
        error_log('Insert failed: '.$stmt->errno.' '.$stmt->error);
        echo "<script>Swal.fire({icon:'error',title:'Failed!',text:'Could not add role.'});</script>";
    }
}

/* ─────────────────────────────────────────────────────────────────────────── */
/* EDIT EMPLOYEE (username + optional password change + email + e-signature)  */
/* ─────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action_type'] ?? '') === 'edit')) {

    // Sanitize POST data
    $employeeId    = (int)($_POST['employee_id'] ?? 0);
    $firstName     = sanitize_input($_POST['edit_first_name']     ?? '');
    $middleName    = sanitize_input($_POST['edit_middle_name']    ?? '');
    $lastName      = sanitize_input($_POST['edit_last_name']      ?? '');
    $birthDate     = sanitize_input($_POST['edit_birth_date']     ?? '');
    $birthPlace    = sanitize_input($_POST['edit_birth_place']    ?? '');
    $gender        = sanitize_input($_POST['edit_gender']         ?? '');
    $contactNumber = sanitize_input($_POST['edit_contact_number'] ?? '');
    $civilStatus   = sanitize_input($_POST['edit_civil_status']   ?? '');
    $email         = sanitize_input($_POST['edit_email']          ?? '');
    $zone          = sanitize_input($_POST['edit_zone']           ?? '');
    $citizenship   = sanitize_input($_POST['edit_citizenship']    ?? '');
    $religion      = sanitize_input($_POST['edit_religion']       ?? '');
    $term          = sanitize_input($_POST['edit_term']           ?? '');

    // NEW: account fields
    $username      = sanitize_input($_POST['edit_username']       ?? '');       // required
    $newPassword   = (string)($_POST['edit_new_password']         ?? '');       // optional

    // Fetch oldData now (for audit trail + fallback email/username)
    $oldStmt = $mysqli->prepare("SELECT employee_email, employee_username FROM employee_list WHERE employee_id = ?");
    $oldStmt->bind_param("i", $employeeId);
    $oldStmt->execute();
    $oldResult = $oldStmt->get_result();
    $oldData   = $oldResult->fetch_assoc();
    $oldStmt->close();

    // Basic validations
    if ($employeeId <= 0 || $firstName === '' || $lastName === '' || $birthDate === '' || $username === '') {
        echo "<script>Swal.fire({icon:'warning',title:'Missing Fields',text:'First name, last name, birth date, and username are required.'});</script>";
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>Swal.fire({icon:'error',title:'Invalid Email',text:'Please enter a valid email address.'});</script>";
        exit;
    }
    if (!preg_match('/^\d{10,15}$/', $contactNumber)) {
        echo "<script>Swal.fire({icon:'error',title:'Invalid Contact',text:'Contact number must be 10–15 digits.'});</script>";
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        echo "<script>Swal.fire({icon:'error',title:'Invalid Date Format',text:'Birth date format must be YYYY-MM-DD.'});</script>";
        exit;
    }
    if ($newPassword !== '' && strlen($newPassword) < 8) {
        echo "<script>Swal.fire({icon:'error',title:'Weak Password',text:'New password must be at least 8 characters.'});</script>";
        exit;
    }

    // Start dynamic SQL (base fields)
    $params = [
        $firstName, $middleName, $lastName, $birthDate, $birthPlace,
        $gender, $contactNumber, $civilStatus, $email, $zone,
        $citizenship, $religion, $term,
        $username // NEW: always update username
    ];
    $types  = "sssssssssssss" . "s"; // 13 strings + username

    $sql = "UPDATE employee_list SET 
                employee_fname = ?, 
                employee_mname = ?, 
                employee_lname = ?, 
                employee_birth_date = ?, 
                employee_birth_place = ?, 
                employee_gender = ?, 
                employee_contact_number = ?, 
                employee_civil_status = ?, 
                employee_email = ?, 
                employee_zone = ?, 
                employee_citizenship = ?, 
                employee_religion = ?, 
                employee_term = ?,
                employee_username = ?";

    // Optional password update
    if ($newPassword !== '') {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $sql     .= ", employee_password = ?";
        $params[] = $hashed;
        $types   .= "s";
    }

    /* ---------------- E-Signature handling (UPLOAD has priority over REMOVE) ---------------- */
    $removeEsig = isset($_POST['remove_esignature']) && $_POST['remove_esignature'] === '1';
    $hasFileKey = isset($_FILES['edit_esignature']);
    $fileErr    = $hasFileKey ? $_FILES['edit_esignature']['error'] : UPLOAD_ERR_NO_FILE;
    $hasNewFile = ($fileErr === UPLOAD_ERR_OK);

    $esignatureData = null;   // LONGBLOB
    $esignatureMime = null;   // MIME

    if ($hasNewFile) {
        // ✅ New upload wins
        $tmpPath = $_FILES['edit_esignature']['tmp_name'];
        $size    = (int)$_FILES['edit_esignature']['size'];

        if ($size > 2 * 1024 * 1024) { // 2 MB limit
            echo "<script>Swal.fire({icon:'error',title:'Too large',text:'E-signature must be ≤ 2 MB.'});</script>";
            exit;
        }

        // MIME detection with fallbacks
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmpPath) ?: '';
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = mime_content_type($tmpPath) ?: '';
        }
        if ($mime === '') {
            $ext  = strtolower(pathinfo($_FILES['edit_esignature']['name'] ?? '', PATHINFO_EXTENSION));
            $map  = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
            $mime = $map[$ext] ?? '';
        }

        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            echo "<script>Swal.fire({icon:'error',title:'Invalid type',text:'Use PNG, JPG, or WEBP for the e-signature.'});</script>";
            exit;
        }

        $data = file_get_contents($tmpPath);
        if ($data === false) {
            echo "<script>Swal.fire({icon:'error',title:'Read error',text:'Unable to read uploaded e-signature.'});</script>";
            exit;
        }

        $esignatureData = $data;
        $esignatureMime = $mime;

        // Add placeholders for blob + mime
        $sql     .= ", esignature = ?, esignature_mime = ?";
        $types   .= "bs";         // 'b' blob + 's' mime
        $params[] = null;         // blob placeholder (send_long_data later)
        $params[] = $esignatureMime;

    } elseif ($removeEsig) {
        // ✅ Only clear if no new file was uploaded
        $sql .= ", esignature = NULL, esignature_mime = NULL";
    }
    /* ---------------------------------------------------------------------------------------- */

    // WHERE
    $sql .= " WHERE employee_id = ?";
    $params[] = $employeeId;
    $types   .= "i";

    // Prepare & bind (by reference for robust blob handling)
    if ($stmt = $mysqli->prepare($sql)) {

        // Build referenced array for bind_param
        $bindTypes  = $types;
        $bindValues = $params;
        $refs = [];
        $refs[] = &$bindTypes;
        foreach ($bindValues as $k => $v) {
            $refs[] = &$bindValues[$k]; // pass by reference
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);

        // Stream blob (if any) — find the index of our null placeholder
        if ($esignatureData !== null) {
            $blobParamIndex = array_search(null, $bindValues, true);
            if ($blobParamIndex !== false && $blobParamIndex !== null) {
                $stmt->send_long_data((int)$blobParamIndex, $esignatureData);
            }
        }

        if ($stmt->execute()) {
            // If password changed, email credentials to best address
            if ($newPassword !== '') {
                $targetEmail = ($email !== '') ? $email : ($oldData['employee_email'] ?? '');
                $targetUser  = ($username !== '') ? $username : ($oldData['employee_username'] ?? '');
                if ($targetEmail !== '' && filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                    @sendCredentials($targetEmail, $targetUser, $newPassword);
                }
            }

            echo "<script>
                Swal.fire({icon:'success',title:'Success!',text:'Employee updated successfully!',confirmButtonColor:'#3085d6'})
                .then(()=>{ window.location.href = '{$redirects['officials']}'; });
            </script>";
            if (isset($trigs)) { $trigs->isEdit(1, (int)$employeeId, $oldData); }
            exit();
        } else {
            error_log('mysqli execute error: '.$stmt->errno.' '.$stmt->error);
            echo "<script>Swal.fire({icon:'error',title:'Failed!',text:'Error updating employee record.',confirmButtonColor:'#d33'});</script>";
        }
        $stmt->close();
    } else {
        error_log('mysqli prepare error: '.$mysqli->errno.' '.$mysqli->error);
        echo "<script>Swal.fire({icon:'error',title:'Error!',text:'Something went wrong preparing the SQL statement.',confirmButtonColor:'#d33'});</script>";
    }
}



/* ─────────────────────────────────────────────────────────────────────────── */
/* ADD EMPLOYEE (saves username+password and emails creds + Role_Id)          */
/* ─────────────────────────────────────────────────────────────────────────── */
/* ─────────────────────────────────────────────────────────────────────────── */
/* ADD EMPLOYEE (email OR manual username; store temp_pass plaintext)         */
/* ─────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') == 'add') {
    $firstName     = sanitize_input($_POST['employee_fname'] ?? '');
    $middleName    = sanitize_input($_POST['employee_mname'] ?? '');
    $lastName      = sanitize_input($_POST['employee_lname'] ?? '');
    $birthDate     = sanitize_input($_POST['employee_birth_date'] ?? '');
    $birthPlace    = sanitize_input($_POST['employee_birth_place'] ?? '');
    $gender        = sanitize_input($_POST['employee_gender'] ?? '');
    $contactNumber = sanitize_input($_POST['employee_contact_number'] ?? '');
    $civilStatus   = sanitize_input($_POST['employee_civil_status'] ?? '');
    $zone          = sanitize_input($_POST['employee_zone'] ?? '');
    $citizenship   = sanitize_input($_POST['employee_citizenship'] ?? '');
    $religion      = sanitize_input($_POST['employee_religion'] ?? '');
    $term          = sanitize_input($_POST['employee_term'] ?? '');
    $roleId        = isset($_POST['Role_Id']) ? (int)$_POST['Role_Id'] : 0;

    // New inputs for login identity
    $loginMode     = ($_POST['login_mode'] ?? 'email') === 'username' ? 'username' : 'email';
    $emailRaw      = sanitize_input($_POST['employee_email'] ?? '');
    $userRaw       = sanitize_input($_POST['employee_username'] ?? '');

    // basic validations (common)
    if (empty($firstName) || empty($lastName) || empty($birthDate)) {
        echo "<script>Swal.fire({icon:'warning',title:'Missing Fields',text:'First name, last name, and birth date are required.'});</script>";
        exit;
    }
    if (!preg_match('/^\d{10,15}$/', $contactNumber)) {
        echo "<script>Swal.fire({icon:'error',title:'Invalid Contact',text:'Contact number must be 10–15 digits.'});</script>";
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        echo "<script>Swal.fire({icon:'error',title:'Invalid Date Format',text:'Birth date format must be YYYY-MM-DD.'});</script>";
        exit;
    }

    // Determine username/email according to mode
    $email = null;
    if ($loginMode === 'email') {
        if ($emailRaw === '' || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
            echo "<script>Swal.fire({icon:'error',title:'Invalid Email',text:'Please enter a valid email address (or switch to manual username).'});</script>";
            exit;
        }
        $email = $emailRaw;
        $employee_username = strtolower($emailRaw);
    } else {
        // Manual username
        if ($userRaw === '' || !preg_match('/^[A-Za-z0-9._-]{4,64}$/', $userRaw)) {
            echo "<script>Swal.fire({icon:'error',title:'Invalid Username',text:'Username must be 4–64 chars and use letters, numbers, dot, underscore or hyphen.'});</script>";
            exit;
        }
        $employee_username = $userRaw;
    }

    // Create temp/plain + hashed password
    $plain_password    = generateNumericCode(8);              // e.g., "03749215"
    $employee_password = password_hash($plain_password, PASSWORD_DEFAULT);
    $temp_pass         = $plain_password;                     // ← store plaintext copy as requested

    // INSERT (now includes temp_pass; email may be NULL)
    $sql = "INSERT INTO employee_list (
                employee_fname, employee_mname, employee_lname, employee_birth_date, employee_birth_place,
                employee_gender, employee_contact_number, employee_civil_status, employee_email, employee_zone,
                employee_citizenship, employee_religion, employee_term,
                employee_username, employee_password, temp_pass, Role_Id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param(
            'ssssssssssssssssi',
            $firstName, $middleName, $lastName, $birthDate, $birthPlace,
            $gender, $contactNumber, $civilStatus, $email, $zone,
            $citizenship, $religion, $term,
            $employee_username, $employee_password, $temp_pass, $roleId
        );

        if ($stmt->execute()) {
            $last_ID = $mysqli->insert_id;
            if (isset($trigs)) { $trigs->isAdded(1, $last_ID); }

            // Send credentials only if email exists
            $mailOk = false;
            if (!empty($email)) {
                $mailOk = sendCredentials($email, $employee_username, $plain_password);
            }

            if ($mailOk) {
                echo "<script>
                    Swal.fire({icon:'success',title:'Success!',text:'Employee added and credentials emailed.'})
                    .then(()=>{ window.location.href = '{$redirects['officials']}'; });
                </script>";
            } else {
                echo "<script>
                    Swal.fire({icon:'success',title:'Employee Added',text:'Account created. ".(empty($email) ? "No email provided, so no email sent." : "Email sending failed; check mail settings.")."'})
                    .then(()=>{ window.location.href = '{$redirects['officials']}'; });
                </script>";
            }
            exit;
        } else {
            error_log('Insert failed: '.$stmt->errno.' '.$stmt->error);
            echo "<script>Swal.fire({icon:'error',title:'Failed!',text:'Error adding employee.'});</script>";
        }
        $stmt->close();
    } else {
        echo "<script>Swal.fire({icon:'error',title:'Error!',text:'SQL prepare failed.'});</script>";
    }
}

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
  <link rel="stylesheet" href="css/employee/emp.css">
</head>

<body>
  <div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="m-0"><i class="fa-solid fa-people-group me-2"></i>Employee List</h2>
      <div class="page-tools">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addOfficialModal">
          <i class="fa-solid fa-user-plus me-1"></i> Add Employee
        </button>
        <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#addRoleModal">
          <i class="fa-solid fa-briefcase me-1"></i> Add Role
        </button>
      </div>
    </div>

    <div class="card-surface mb-3">
      <div class="card-body">
        <div class="d-flex gap-2 align-items-center">
          <input type="text" id="searchResidentInput" class="form-control w-25" placeholder="Search employee name or ID...">
          <!-- Optional: hook this input to submit the querystring ?search= -->
        </div>
      </div>
    </div>

    <!-- Card Wrapper Start -->
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-primary text-white">
        👤 Employee List
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-bordered align-middle mb-0">
            <thead>
              <tr>
                <th style="width: 450px;">Employee ID</th>
                <th style="width: 450px;">Name</th>
                <th style="width: 450px;">Gender</th>
                <th style="width: 450px;">Zone</th>
                <th style="width: 450px;">Actions</th>
              </tr>
            </thead>
            <tbody id="employeeTableBody">
              <?php
                require_once __DIR__ . '/../include/connection.php';
                $mysqli = db_connection();
                include 'class/session_timeout.php';

                $limit = 10;
                $page = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
                $page = max(1, $page);
                $offset = ($page - 1) * $limit;
                $search_term = isset($_GET['search']) ? $_GET['search'] : '';

                $sql = "SELECT 
                          employee_id, 
                          employee_fname, 
                          employee_mname, 
                          employee_lname, 
                          employee_birth_date,
                          employee_birth_place,
                          employee_gender,
                          employee_contact_number,
                          employee_civil_status,
                          employee_email,
                          employee_citizenship,
                          employee_religion,
                          employee_term,
                          employee_zone,
                          employee_username,
                          temp_pass,
                          password_changed
                        FROM employee_list 
                        WHERE employee_delete_status = 0 
                          AND (employee_fname LIKE ?  
                            OR employee_mname LIKE ? 
                            OR employee_lname LIKE ? 
                            OR employee_id LIKE ?) 
                        LIMIT ? OFFSET ?";

                $stmt = $mysqli->prepare($sql);
                $search_like = "%$search_term%";
                $stmt->bind_param("ssssii", $search_like, $search_like, $search_like, $search_like, $limit, $offset);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                  while ($row = $result->fetch_assoc()) {
                    include 'components/employee_modal/employee_row.php';
                  }
                } else {
                  echo "<tr><td colspan='5' class='text-center'>No employees found</td></tr>";
                }

                // totals for pagination
                $total_results_sql = "SELECT COUNT(*) FROM employee_list WHERE employee_delete_status = 0 
                                      AND (employee_fname LIKE ? 
                                        OR employee_mname LIKE ? 
                                        OR employee_lname LIKE ? 
                                        OR employee_id LIKE ?)";
                $stmt_total = $mysqli->prepare($total_results_sql);
                $stmt_total->bind_param("ssss", $search_like, $search_like, $search_like, $search_like);
                $stmt_total->execute();
                $total_results_result = $stmt_total->get_result();
                $total_results = $total_results_result->fetch_row()[0];
                $total_pages = max(ceil($total_results / $limit), 1);
              ?>
            </tbody>
          </table>

          <?php
            // Build base + query string preserving filters
            $baseUrl  = $redirects['officials'];
            // $baseUrl may already contain query; keep it and add params
            $pageBase = $baseUrl;
            // Extract existing query string from $baseUrl (if any)
            $hasQ = str_contains($baseUrl, '?');
            $qsPrefix = $hasQ ? '&' : '?';
            // Keep search if present
            $qs = $qsPrefix . http_build_query(array_filter(['search' => $search_term]));
            // Window pagination compute
            $window = 2;
            $start  = max(1, $page - $window);
            $end    = min($total_pages, $page + $window);
            if ($start > 1 && $end - $start < $window*2) $start = max(1, $end - $window*2);
            if ($end < $total_pages && $end - $start < $window*2) $end = min($total_pages, $start + $window*2);
          ?>
        </div>
      </div>
    </div>
    <!-- Card Wrapper End -->

    <!-- Pagination -->
    <nav aria-label="Page navigation">
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

        <!-- Windowed page numbers -->
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

    <!-- Add Role Modal -->
    <div class="modal fade" id="addRoleModal" tabindex="-1" aria-labelledby="addRoleModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" id="addRoleForm">
            <div class="modal-header">
              <h5 class="modal-title" id="addRoleModalLabel">Add Role</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <?php if (!empty($message)) echo $message; ?>
              <div class="mb-3">
                <label for="roleName" class="form-label">Role Name</label>
                <input type="text" class="form-control" id="roleName" name="roleName" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="submit" class="btn btn-primary">Submit</button>
              <button type="reset" class="btn btn-secondary">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <?php include 'components/employee_modal/view_modal.php'; ?>
    <?php include 'components/employee_modal/edit_modal.php'; ?>
    <?php include 'components/employee_modal/add_modal.php'; ?>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/employee_script.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src ="components/resident_modal/email.js"></script>

  <script>

    function logEmployeeView(employeeId) {
    fetch('./logs/logs_trig.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `filename=1&viewedID=${employeeId}`
    })
    .then(res => res.text())
    .then(data => console.log("View logged:", data))
    .catch(err => console.error("Error logging view:", err));
}

    document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById("employee_email");
    const emailFeedback = document.createElement("small");
    emailFeedback.className = "form-text email-feedback";
    emailInput.parentElement.appendChild(emailFeedback);

    emailInput.addEventListener("blur", function() {
        debouncedValidate(emailInput, emailFeedback);
    });
});


// Debounce function
function debounce(func, delay) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

const employeeInput = document.getElementById('searchResidentInput');
const employeeTableBody = document.getElementById('employeeTableBody');

const fetchEmployees = debounce(function () {
    const query = employeeInput.value.trim();
    fetch('./Search/search_employee.php?q=' + encodeURIComponent(query))
        .then(res => res.text())
        .then(data => {
            employeeTableBody.innerHTML = data;
        })
        .catch(err => {
            console.error("Error loading employees:", err);
        });
}, 1000); // 300ms debounce delay

employeeInput.addEventListener('input', fetchEmployees);


    document.getElementById('employee_password').addEventListener('input', function() {
    validatePassword();
});

function validatePassword() {
    const password = document.getElementById('employee_password').value;
    const checks = {
        lowercase: /[a-z]/,
        uppercase: /[A-Z]/,
        number: /\d/,
        length: password.length >= 8
    };

    document.getElementById('lowercase-check').className = checks.lowercase.test(password) ? 'text-success' : 'text-danger';
    document.getElementById('uppercase-check').className = checks.uppercase.test(password) ? 'text-success' : 'text-danger';
    document.getElementById('number-check').className = checks.number.test(password) ? 'text-success' : 'text-danger';
    document.getElementById('length-check').className = checks.length ? 'text-success' : 'text-danger';

    document.getElementById('password-error').style.display = (checks.lowercase.test(password) && checks.uppercase.test(password) && checks.number.test(password) && checks.length) ? 'none' : 'block';
}

$(document).ready(function () {
    $('#province').change(function () {
        $.post('include/get_locations.php', { province_id: $(this).val() }, function (data) {
            const res = JSON.parse(data);
            if (res.type === 'city_municipality') {
                $('#city_municipality').html(res.options.join('')).prop('disabled', false);
                $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);
            }
        });
    });

    $('#city_municipality').change(function () {
        $.post('include/get_locations.php', { municipality_id: $(this).val() }, function (data) {
            const res = JSON.parse(data);
            if (res.status === 'success') {
                $('#barangay').html('<option value="">Select Barangay</option>');
                res.data.forEach(barangay => {
                    $('#barangay').append(`<option value="${barangay.id}">${barangay.name}</option>`);
                });
                $('#barangay').prop('disabled', false);
            }
        });
    });
});
document.getElementById('addRoleForm').addEventListener('submit', function (e) {
    e.preventDefault();

    Swal.fire({
        title: 'Add Role?',
        text: "Are you sure you want to add this role?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, add it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            e.target.submit();
        }
    });
});
document.getElementById('addEmployeeForm').addEventListener('submit', function (e) {
    e.preventDefault();

    Swal.fire({
        title: 'Add Employee?',
        text: "Confirm adding this employee.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, add',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            e.target.submit();
        }
    });
});

function confirmDelete(employeeId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to undo this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Redirect to the delete script
            window.location.href = 'delete/delete_employee.php?id=' + employeeId;
        }
    });
}
  </script>
</body>
</html>
