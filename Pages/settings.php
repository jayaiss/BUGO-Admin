<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../security/500.html';
        exit();
    }
});

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------------------------
   Base URL (no trailing slash) for localhost vs prod
---------------------------------------------- */
if (!defined('OFFICE_BASE_URL')) {
    define(
        'OFFICE_BASE_URL',
        (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false)
            ? 'http://localhost/BUGO-Admin'          // <-- local project folder
            : 'https://office.bugoportal.site'        // <-- production
    );
}

/* ---------------------------------------------
   Includes
---------------------------------------------- */
require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../include/encryption.php';
$mysqli = db_connection();

/* ---------------------------------------------
   Auth guard
---------------------------------------------- */
$loggedInUsername = $_SESSION['username'] ?? '';
if (!$loggedInUsername) {
    header('Location: ' . OFFICE_BASE_URL . '/index.php');
    exit();
}

/* ---------------------------------------------
   Load current user
---------------------------------------------- */
$success = $error = null;
$user = null;
$employeeId = null;
$employeeEmail = '';

$stmt = $mysqli->prepare("SELECT employee_id, employee_email, employee_password FROM employee_list WHERE employee_username = ?");
if ($stmt) {
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $employeeId   = $user['employee_id'] ?? null;
    $employeeEmail = trim($user['employee_email'] ?? '');
    $stmt->close();
}

if (!$user) {
    header('Location: ' . OFFICE_BASE_URL . '/index.php');
    exit();
}

/* ---------------------------------------------
   Email check (for 2FA)
---------------------------------------------- */
$canSend2FA = (bool) filter_var($employeeEmail, FILTER_VALIDATE_EMAIL);
$emailWarning = $canSend2FA ? null : 'No valid email is saved for your account. Add an email address first so we can send your 2FA code.';

/* ---------------------------------------------
   Handle password update
---------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {

    // Block immediately if no email available for 2FA
    if (!$canSend2FA) {
        $error = "We can't proceed because your account has no valid email on file for 2FA.";
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = "All fields are required.";
        } elseif (!password_verify($currentPassword, $user['employee_password'])) {
            $error = "Your current password is incorrect.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "New password and confirmation do not match.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $newPassword)) {
            $error = "Password must be at least 8 characters, contain an uppercase letter, lowercase letter, and a number.";
        } else {
            // Check password history
            $isUsedBefore = false;
            if ($employeeId) {
                $historyStmt = $mysqli->prepare("SELECT old_password FROM emp_password_history WHERE employee_id = ?");
                if ($historyStmt) {
                    $historyStmt->bind_param("i", $employeeId);
                    $historyStmt->execute();
                    $historyResult = $historyStmt->get_result();
                    while ($row = $historyResult->fetch_assoc()) {
                        if (password_verify($newPassword, $row['old_password'])) {
                            $isUsedBefore = true;
                            break;
                        }
                    }
                    $historyStmt->close();
                }
            }

            if ($isUsedBefore) {
                $error = "This password was used before. Choose a new one.";
            } elseif (password_verify($newPassword, $user['employee_password'])) {
                $error = "New password cannot be the same as your current password.";
            } else {
                // ✅ Save 2FA session data and redirect using absolute URL
                $_SESSION['new_hashed_password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                $_SESSION['employee_id']         = $employeeId;
                $_SESSION['2fa_code']            = 'BUGO-' . mt_rand(100000, 999999);
                $_SESSION['old_hashed_password'] = $user['employee_password'];
                $_SESSION['employee_email']      = $employeeEmail;

                $send2faUrl = OFFICE_BASE_URL . '/auth/send_2fa_code.php';

                echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
  Swal.fire({
    title: 'Sending Code...',
    text: 'Please wait while we send your verification code.',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });
  setTimeout(() => { window.location.href = " . json_encode($send2faUrl) . "; }, 800);
</script>";
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .settings-box {
            max-width: 500px;
            margin: 60px auto;
            padding: 30px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 0 20px rgba(0,0,0,0.08);
            animation: fadeIn 0.5s ease-in-out;
        }
        .form-group { position: relative; }
        .toggle-password {
            position: absolute; top: 50%; right: 10px;
            transform: translateY(-50%); cursor: pointer; color: #6c757d;
        }
        .strength-meter { height: 8px; border-radius: 4px; background-color: #e0e0e0; margin-top: 4px; transition: background-color 0.3s; }
        .strength-weak { background-color: #dc3545 !important; }
        .strength-medium { background-color: #ffc107 !important; }
        .strength-strong { background-color: #28a745 !important; }
        @keyframes fadeIn { from {opacity:0; transform: translateY(20px);} to {opacity:1; transform: translateY(0);} }
    </style>
</head>
<body class="bg-light">

<div class="settings-box">
    <h4 class="text-center mb-3">Change Password</h4>

    <?php if (!$canSend2FA): ?>
        <div class="alert alert-warning" role="alert">
            <strong>Heads up:</strong> <?= htmlspecialchars($emailWarning) ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info py-2" role="alert">
            We will send a 2FA code to: <strong><?= htmlspecialchars($employeeEmail) ?></strong>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="mb-3 form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" id="current_password" class="form-control" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('current_password', this)"></i>
        </div>

        <div class="mb-3 form-group">
            <label>New Password</label>
            <input type="password" name="new_password" id="new_password" class="form-control" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('new_password', this)"></i>
            <div id="strength-meter" class="strength-meter"></div>
            <div id="reuse-warning" class="text-danger small mt-1" style="display: none;">
                ❌ This is your current password. Please use a new one.
            </div>
        </div>

        <div class="mb-3 form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
        </div>

        <div class="d-grid">
            <button type="submit" name="update_password" class="btn btn-primary"
                <?= !$canSend2FA ? 'disabled' : '' ?>>Update Password</button>
        </div>
    </form>
</div>

<?php if (!$canSend2FA): ?>
<script>
Swal.fire({
    icon: 'warning',
    title: 'No email on file',
    text: 'Add an email to your account so we can send the 2FA code before changing your password.'
});
</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>Swal.fire({ icon: 'success', title: 'Success', text: <?= json_encode($success) ?> });</script>
<?php elseif (!empty($error)): ?>
<script>Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($error) ?> });</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script> -->
<script>
function togglePassword(id, el) {
    const input = document.getElementById(id);
    if (input.type === "password") {
        input.type = "text";
        el.classList.remove("fa-eye");
        el.classList.add("fa-eye-slash");
    } else {
        input.type = "password";
        el.classList.remove("fa-eye-slash");
        el.classList.add("fa-eye");
    }
}

const currentInput  = document.querySelector('input[name="current_password"]');
const newInput      = document.getElementById("new_password");
const confirmInput  = document.getElementById("confirm_password");
const strengthMeter = document.getElementById("strength-meter");
const submitBtn     = document.querySelector('button[name="update_password"]');
const canSend2FA    = <?= $canSend2FA ? 'true' : 'false' ?>;

// Disable button initially (and also disabled in HTML if no email)
submitBtn.disabled = true || !canSend2FA;

// Track changes
[currentInput, newInput, confirmInput].forEach(input => {
    input.addEventListener('input', validateForm);
});

function validateForm() {
    const currentVal = currentInput.value;
    const newVal     = newInput.value;
    const confirmVal = confirmInput.value;

    // Password strength check
    let strength = 0;
    if (newVal.length >= 8) strength++;
    if (/[a-z]/.test(newVal)) strength++;
    if (/[A-Z]/.test(newVal)) strength++;
    if (/[0-9]/.test(newVal)) strength++;

    // Update strength meter UI
    if (strength < 3) {
        strengthMeter.className = "strength-meter strength-weak";
    } else if (strength === 3) {
        strengthMeter.className = "strength-meter strength-medium";
    } else {
        strengthMeter.className = "strength-meter strength-strong";
    }

    // Enable only if all conditions are met AND we have an email for 2FA
    const isStrong = strength >= 3;
    const notOld   = currentVal !== newVal;
    const matches  = newVal === confirmVal;

    submitBtn.disabled = !(isStrong && notOld && matches && canSend2FA);
}

// Strength meter + reuse warning
document.getElementById("new_password").addEventListener("input", function () {
    const val     = this.value;
    const current = document.getElementById("current_password").value;
    const meter   = document.getElementById("strength-meter");
    const warning = document.getElementById("reuse-warning");

    if (val.length < 8 || !/[A-Z]/.test(val) || !/[a-z]/.test(val) || !/[0-9]/.test(val)) {
        meter.className = "strength-meter strength-weak";
    } else if (val.length < 10) {
        meter.className = "strength-meter strength-medium";
    } else {
        meter.className = "strength-meter strength-strong";
    }

    if (val && current && val === current) {
        warning.style.display = "block";
    } else {
        warning.style.display = "none";
    }
});
</script>
</body>
</html>
