<?php
session_start();

// --- Prevent browser caching so back button can't show stale pages ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// --- Require login; if flagged, keep them on change password ---
if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}
// If you set this during login, they stay here until success.
if (!empty($_SESSION['force_change_password'])) {
    // noop, just show page
}

require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$error_message = '';
$show_success = false; // SweetAlert trigger

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current_password = validateInput($_POST['current_password'] ?? '');
    $new_password     = validateInput($_POST['new_password'] ?? '');
    $confirm_password = validateInput($_POST['confirm_password'] ?? '');
    $employee_id      = (int)($_SESSION['employee_id'] ?? 0);

    // Min 8 chars, at least one uppercase, one lowercase, one number
    $password_pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error_message = "All fields are required.";
    } elseif (!preg_match($password_pattern, $new_password)) {
        $error_message = "New password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, and one number.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New password and confirmation do not match.";
    } else {
        // Get current hash
        $stmt = $mysqli->prepare("SELECT employee_password FROM employee_list WHERE employee_id = ? AND employee_delete_status = 0");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $hashed_current_password = $row['employee_password'];

            if (password_verify($current_password, $hashed_current_password)) {
                // Disallow same as current
                if (password_verify($new_password, $hashed_current_password)) {
                    $error_message = "You cannot reuse an old password. Please choose a new one.";
                } else {
                    // Check history
                    $stmt_history = $mysqli->prepare("SELECT old_password FROM emp_password_history WHERE employee_id = ?");
                    $stmt_history->bind_param("i", $employee_id);
                    $stmt_history->execute();
                    $history_result = $stmt_history->get_result();

                    while ($history_row = $history_result->fetch_assoc()) {
                        if (password_verify($new_password, $history_row['old_password'])) {
                            $error_message = "You cannot reuse an old password. Please choose a new one.";
                            break;
                        }
                    }

                    if ($error_message === '') {
                        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                        // Store old hash to history
                        $stmt_store_history = $mysqli->prepare("INSERT INTO emp_password_history (employee_id, old_password) VALUES (?, ?)");
                        $stmt_store_history->bind_param("is", $employee_id, $hashed_current_password);
                        $stmt_store_history->execute();
                        $stmt_store_history->close();

                        // Update new password + flag
                        $stmt_update = $mysqli->prepare("UPDATE employee_list SET employee_password = ?, password_changed = 1 WHERE employee_id = ?");
                        $stmt_update->bind_param("si", $hashed_new_password, $employee_id);

                        if ($stmt_update->execute()) {
                            // --- HARD LOGOUT + revoke remember token ---
                            session_regenerate_id(true);
                            unset($_SESSION['force_change_password']);

                            // 1) Delete any remember tokens for this user
                            if ($del = $mysqli->prepare("DELETE FROM login_tokens WHERE employee_id = ?")) {
                                $del->bind_param("i", $employee_id);
                                $del->execute();
                                $del->close();
                            }

                            // 2) Clear cookie on all likely domain/path combos
                            $cookieNames = ['remember_token'];
                            $host = $_SERVER['HTTP_HOST'] ?? '';
                            $parent = $host ? '.'.preg_replace('/^www\./','', $host) : '';
                            $domains = [
                                '',                   // default/current host
                                $host,                // exact host
                                $parent,              // .sub.example.com
                                '.bugoportal.site'    // parent domain (adjust if different)
                            ];
                            $paths = ['/', '/index.php', ''];

                            foreach ($cookieNames as $name) {
                                foreach ($domains as $dom) {
                                    foreach ($paths as $p) {
                                        if (PHP_VERSION_ID >= 70300) {
                                            setcookie($name, '', [
                                                'expires'  => time() - 3600,
                                                'path'     => $p,
                                                'domain'   => $dom ?: null,
                                                'secure'   => true,
                                                'httponly' => true,
                                                'samesite' => 'Lax'
                                            ]);
                                        } else {
                                            setcookie($name, '', time()-3600, $p, $dom ?: '', true, true);
                                        }
                                    }
                                }
                            }

                            // 3) Kill session
                            session_unset();
                            session_destroy();

                            $show_success = true; // handled by SweetAlert below
                        } else {
                            $error_message = "Error updating password. Please try again.";
                        }
                        $stmt_update->close();
                    }
                    $stmt_history->close();
                }
            } else {
                $error_message = "Current password is incorrect.";
            }
        } else {
            $error_message = "User not found or account is deleted.";
        }
        $stmt->close();
    }
}

$mysqli->close();

function validateInput($data) {
    return htmlspecialchars(stripslashes(trim($data ?? '')));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .requirement { color: gray; }
        .requirement.valid { color: green; }
        .requirement.invalid { color: red; }
        .input-group-text { cursor: pointer; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2 class="text-center">Change Password</h2>

    <?php if (!empty($_SESSION['force_change_password'])): ?>
        <div class="alert alert-warning">
            For security, you must set a new password before continuing.
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        New password must be at least 8 characters long, and include at least one uppercase letter, one lowercase letter, and one number.
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorMessage">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="current_password">Current Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="current_password" name="current_password" required>
                <span class="input-group-text toggle-password"><i class="fa fa-eye"></i></span>
            </div>
        </div>

        <div class="form-group">
            <label for="new_password">New Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="new_password" name="new_password" required>
                <span class="input-group-text toggle-password"><i class="fa fa-eye"></i></span>
            </div>
            <div class="mt-2">
                <small class="requirement" id="length">At least 8 characters</small><br>
                <small class="requirement" id="uppercase">At least one uppercase letter</small><br>
                <small class="requirement" id="lowercase">At least one lowercase letter</small><br>
                <small class="requirement" id="number">At least one number</small>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                <span class="input-group-text toggle-password"><i class="fa fa-eye"></i></span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Change Password</button>
        <a href="class/logout.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function () {
    // Live requirements
    $('#new_password').on('input', function () {
        const p = $(this).val();
        $('#length').toggleClass('valid', p.length >= 8).toggleClass('invalid', p.length < 8);
        $('#uppercase').toggleClass('valid', /[A-Z]/.test(p)).toggleClass('invalid', !/[A-Z]/.test(p));
        $('#lowercase').toggleClass('valid', /[a-z]/.test(p)).toggleClass('invalid', !/[a-z]/.test(p));
        $('#number').toggleClass('valid', /\d/.test(p)).toggleClass('invalid', !/\d/.test(p));
    });

    // Toggle eye icon
    $('.toggle-password').on('click', function () {
        const input = $(this).siblings('input');
        const icon  = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // Fade out error block
    setTimeout(() => $("#errorMessage").fadeOut("slow"), 2000);

    // Success SweetAlert -> go to login
    <?php if ($show_success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Password Changed',
        text: 'Password successfully changed. Please log in again.',
        showConfirmButton: false,
        timer: 1800,
        timerProgressBar: true
    }).then(() => {
        window.location.href = "index.php";
    });
    <?php endif; ?>
});
</script>

<!-- Block back navigation into this page; always push to login once success happened -->
<script>
  // Prevent navigating to cached version via back/forward
  window.history.pushState(null, "", window.location.href);
  window.onpopstate = function () {
      window.location.href = "index.php";
  };
</script>
</body>
</html>
