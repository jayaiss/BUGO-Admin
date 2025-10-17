<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/redirects.php';

// Helper to read either edit_* or plain keys
function postv($a, $b = null) {
    if (isset($_POST[$a])) return $_POST[$a];
    if ($b && isset($_POST[$b])) return $_POST[$b];
    return '';
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IDs
    $employee_id = filter_var(postv('employee_id'), FILTER_SANITIZE_NUMBER_INT);

    // Names
    $first_name   = filter_var(postv('edit_first_name',  'first_name'),  FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $middle_name  = filter_var(postv('edit_middle_name', 'middle_name'), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $last_name    = filter_var(postv('edit_last_name',   'last_name'),   FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Bio
    $birth_date   = filter_var(postv('edit_birth_date',  'birth_date'),  FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $birth_place  = filter_var(postv('edit_birth_place', 'birth_place'), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $gender       = filter_var(postv('edit_gender',      'gender'),      FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $civil_status = filter_var(postv('edit_civil_status','civil_status'),FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $citizenship  = filter_var(postv('edit_citizenship', 'citizenship'), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $religion     = filter_var(postv('edit_religion',    'religion'),    FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $term         = filter_var(postv('edit_term',        'term'),        FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Contact
    $zone         = filter_var(postv('edit_zone',        'zone'),        FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $email_raw    = trim(postv('edit_email',             'email'));
    $email        = $email_raw !== '' ? filter_var($email_raw, FILTER_SANITIZE_EMAIL) : '';

    // Allow +, spaces, dashes; store a cleaned numeric version (optional)
    $contact_raw  = trim(postv('edit_contact_number', 'contact_number'));
    $contact_disp = preg_replace('/[^0-9+\-\s]/', '', $contact_raw); // for display/storage as-is
    $contact_num  = preg_replace('/\D+/', '', $contact_raw);         // pure digits if you prefer

    // Account
    $username     = trim(filter_var(postv('edit_username','username'), FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $new_password = (string)postv('edit_new_password', 'new_password'); // raw for hashing

    // Basic required validations
    if (empty($employee_id) || empty($first_name) || empty($last_name) || empty($birth_date) || empty($username)) {
        echo "<script>
          Swal.fire({icon:'error', title:'Missing data', text:'First name, last name, birth date, and username are required.'});
        </script>";
        exit;
    }

    // Email (optional but if provided, must be valid)
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>Swal.fire({icon:'error', title:'Invalid email', text:'Please enter a valid email.'});</script>";
        exit;
    }

    // Contact (optional but if provided, basic format)
    if ($contact_disp !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $contact_disp)) {
        echo "<script>Swal.fire({icon:'error', title:'Invalid contact number', text:'Use 7–20 digits; +, space, and - allowed.'});</script>";
        exit;
    }

    // Birth date (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        echo "<script>Swal.fire({icon:'error', title:'Invalid date', text:'Use YYYY-MM-DD for birth date.'});</script>";
        exit;
    }

    // Password change rules (only if provided)
    $password_hash = null;
    if ($new_password !== '') {
        if (strlen($new_password) < 8) {
            echo "<script>Swal.fire({icon:'error', title:'Weak password', text:'Password must be at least 8 characters.'});</script>";
            exit;
        }
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    }

    // Build dynamic UPDATE
    $fields = [
        'employee_fname'          => $first_name,
        'employee_mname'          => $middle_name,
        'employee_lname'          => $last_name,
        'employee_birth_date'     => $birth_date,
        'employee_birth_place'    => $birth_place,
        'employee_gender'         => $gender,
        'employee_contact_number' => $contact_disp, // or $contact_num if you want pure digits
        'employee_civil_status'   => $civil_status,
        'employee_email'          => $email,
        'employee_zone'           => $zone,
        'employee_citizenship'    => $citizenship,
        'employee_religion'       => $religion,
        'employee_term'           => $term,
        'employee_username'       => $username,
    ];
    if ($password_hash !== null) {
        $fields['employee_password'] = $password_hash;
        // Optional: also set password_changed flag
        // $fields['password_changed'] = 1;
    }

    // Create SQL SET clause and types
    $setParts = [];
    $values   = [];
    $types    = '';
    foreach ($fields as $col => $val) {
        $setParts[] = "$col = ?";
        $values[]   = $val;
        $types     .= 's';
    }
    $types .= 'i'; // for WHERE id
    $values[] = (int)$employee_id;

    $sql = "UPDATE employee_list SET " . implode(', ', $setParts) . " WHERE employee_id = ?";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param($types, ...$values);

        if ($stmt->execute()) {
            echo "<script>
                Swal.fire({
                  icon: 'success',
                  title: 'Updated',
                  text: 'Employee details were updated successfully.',
                  confirmButtonColor: '#3085d6'
                }).then(() => { window.location.href = '{$redirects['officials_api']}'; });
            </script>";
            exit;
        } else {
            echo "<script>Swal.fire({icon:'error', title:'Failed', text:'Error updating employee (DB execute).'});</script>";
        }
        $stmt->close();
    } else {
        echo "<script>Swal.fire({icon:'error', title:'Failed', text:'Error preparing update statement.'});</script>";
    }
}

$mysqli->close();
?>
