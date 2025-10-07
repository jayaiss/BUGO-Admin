<?php
require_once __DIR__ . '/security/security.php';
require __DIR__ . '/vendor/autoload.php';
require_once 'include/encryption.php';
require_once './logs/logs_trig.php';
require_once __DIR__ . '/include/connection.php';

use PHPMailer\PHPMailer\PHPMailer;

$mysqli   = db_connection();
$trigger  = new Trigger();
$recaptcha_secret = "6Ldid00rAAAAAOXCldjZkhQfad_-fxzaRZVxg9oB";

// Ensure a session + CSRF token exist
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Email OTP sender helper (PHPMailer) ---
function send_2fa_mail(string $toEmail, string $toName, string $code): void {
  $mailboxUser = 'admin@bugoportal.site';
  $mailboxPass = 'Jayacop@100'; // TODO: move to env/secret
  $smtpHost    = 'mail.bugoportal.site';

  $safeName = htmlspecialchars($toName ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $safeCode = htmlspecialchars($code ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $buildMessage = function(PHPMailer $m) use ($toEmail, $safeName, $safeCode, $mailboxUser) {
    $m->setFrom($mailboxUser, 'Barangay Bugo');
    $m->addAddress($toEmail, $safeName);
    $m->addBCC($mailboxUser); // TEMP while testing
    $m->isHTML(true);
    $m->Subject = 'Barangay Bugo 2FA Code';
    $m->Body = "<p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your verification code is:</p>
                <h2 style='color:#0d6efd;'>{$safeCode}</h2>
                <p>This code is valid for 5 minutes.</p>
                <br><p>Thank you,<br>Barangay Bugo Portal</p>";
    $m->AltBody  = "Your verification code is: {$safeCode}\nThis code is valid for 5 minutes.";
    $m->CharSet  = 'UTF-8';
    $m->Hostname = 'bugoportal.site';
    $m->Sender   = $mailboxUser;
    $m->addReplyTo($mailboxUser, 'Barangay Bugo');
  };

  $attempt = function(string $mode, int $port) use ($smtpHost, $mailboxUser, $mailboxPass, $buildMessage) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host          = $smtpHost;
    $mail->SMTPAuth      = true;
    $mail->Username      = $mailboxUser;
    $mail->Password      = $mailboxPass;
    $mail->Port          = $port;
    $mail->Timeout       = 10;
    $mail->SMTPAutoTLS   = true;
    $mail->SMTPKeepAlive = false;
    $mail->SMTPOptions = [
      'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
      ]
    ];
    $mail->SMTPSecure = ($mode === 'ssl')
      ? PHPMailer::ENCRYPTION_SMTPS   // 465
      : PHPMailer::ENCRYPTION_STARTTLS; // 587
    $buildMessage($mail);
    $mail->send();
  };

  error_log("2FA: sending code={$code} to {$toEmail}");

  try {
    $attempt('ssl', 465);
  } catch (\Throwable $e1) {
    error_log('2FA: 465 failed: '.$e1->getMessage());
    try {
      $attempt('tls', 587);
    } catch (\Throwable $e2) {
      error_log('2FA: 587 failed: '.$e2->getMessage());
      try {
        $fallback = new PHPMailer(true);
        $fallback->isMail();
        $buildMessage($fallback);
        $fallback->send();
      } catch (\Throwable $e3) {
        error_log('2FA: sendmail failed: '.$e3->getMessage());
        throw new \RuntimeException('Unable to send verification email right now.');
      }
    }
  } finally {
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
      if (!@$GLOBALS['mysqli']->ping() && function_exists('db_connection')) {
        $GLOBALS['mysqli'] = db_connection();
      }
    }
  }
}

// Auto-login via remember_token
if (!isset($_SESSION['employee_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $hashedToken = hash('sha256', $token);

    // ❗ Block auto-login if password_changed = 0
    $stmt = $mysqli->prepare("
        SELECT lt.employee_id, el.Role_Id, er.Role_Name, el.employee_username, el.password_changed
        FROM login_tokens lt
        JOIN employee_list el ON lt.employee_id = el.employee_id
        LEFT JOIN employee_roles er ON el.Role_Id = er.Role_Id
        WHERE lt.token_hash = ? 
          AND lt.expiry >= NOW() 
          AND el.employee_delete_status = 0
          AND el.password_changed = 1
    ");
    $stmt->bind_param("s", $hashedToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $_SESSION['username']    = $row['employee_username'];
        $_SESSION['employee_id'] = $row['employee_id'];
        $_SESSION['Role_Id']     = $row['Role_Id'];
        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';
        $trigger->isLogin(6, $_SESSION['employee_id']);
    }
    $stmt->close();
}

// Redirect if already logged in
if (isset($_SESSION['employee_id'])) {
    // If still forced to change password, don't go to dashboard
    if (!empty($_SESSION['force_change_password'])) {
        header("Location: /change_password.php");
        exit();
    }
    $redirect_page = role_redirect($_SESSION['Role_Name'] ?? 'Staff');
    header("Location: $redirect_page");
    exit();
}

// Handle login
$error_message = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF guard
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error_message = "Invalid Login";
    } elseif (!empty($_POST["username"]) && !empty($_POST["password"]) && !empty($_POST["g-recaptcha-response"])) {
        $username = validateInput($_POST["username"]);
        $password = validateInput($_POST["password"]);
        $recaptcha_response = $_POST["g-recaptcha-response"];

        $verify_url = "https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret&response=$recaptcha_response";
        $response = @file_get_contents($verify_url);
        $response_keys = $response ? json_decode($response, true) : ['success'=>false];

        if (empty($response_keys["success"])) {
            $error_message = "reCAPTCHA verification failed.";
        } else {
            // Add password_changed to SELECT
            $stmt = $mysqli->prepare("
                SELECT el.employee_id,
                       el.employee_username,
                       el.Role_Id,
                       COALESCE(er.Role_Name,'Staff') AS Role_Name,
                       el.employee_password,
                       el.employee_email,
                       el.password_changed,
                       COALESCE(NULLIF(TRIM(CONCAT(el.employee_fname,' ',el.employee_lname)),''), el.employee_username) AS employee_name
                FROM employee_list el
                LEFT JOIN employee_roles er ON el.Role_Id = er.Role_Id
                WHERE LOWER(el.employee_username) = LOWER(?) AND el.employee_delete_status = 0
            ");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stored_password = $row['employee_password'];
                $role_name = strtolower($row['Role_Name'] ?? 'staff');
                $emp_id = (int)$row['employee_id'];

                // 🔒 Brute-force check BEFORE verifying password
                $lock_remaining = is_locked_out($mysqli, $emp_id);
                if ($lock_remaining !== false) {
                    $error_message = "LOCKED:" . $lock_remaining;
                    usleep(250000);
                } elseif (password_verify($password, $stored_password)) {
                    reset_attempts($mysqli, $emp_id);

                    // FORCE PASSWORD CHANGE FIRST if flag is 0
                    if ((int)$row['password_changed'] === 0) {
                        session_regenerate_id(true);
                        $_SESSION['username']    = $row['employee_username'] ?? $username;
                        $_SESSION['employee_id'] = $emp_id;
                        $_SESSION['Role_Id']     = $row['Role_Id'];
                        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';
                        $_SESSION['force_change_password'] = true;

                        // Do not "remember me" until password is changed
                        $_POST['remember'] = 0;

                        header("Location: /change_password.php"); // same level as index.php
                        exit();
                    }

                    // Decide post-login redirect based on role (used after OTP success OR bypass)
                    switch (true) {
                        case strpos($role_name, 'admin') !== false:
                            $redirect_page = enc_page('admin_dashboard'); break;
                        case strpos($role_name, 'revenue') !== false:
                            $redirect_page = enc_revenue('admin_dashboard'); break;
                        case strpos($role_name, 'lupon') !== false:
                            $redirect_page = enc_lupon('admin_dashboard'); break;
                        case strpos($role_name, 'captain') !== false || strpos($role_name, 'punong barangay') !== false:
                            $redirect_page = enc_captain('admin_dashboard'); break;
                        case strpos($role_name, 'staff') !== false || strpos($role_name, 'encoder') !== false:
                            $redirect_page = enc_encoder('admin_dashboard'); break;
                        case strpos($role_name, 'multimedia') !== false:
                            $redirect_page = enc_multimedia('admin_dashboard'); break;
                        case strpos($role_name, 'secretary') !== false:
                            $redirect_page = enc_brgysec('admin_dashboard'); break;
                        case strpos($role_name, 'beso') !== false:
                            $redirect_page = enc_beso('admin_dashboard'); break;
                        case strpos($role_name, 'tanod') !== false:
                            $redirect_page = enc_tanod('admin_dashboard'); break;    
                        default:
                            $redirect_page = enc_page('admin_dashboard');
                    }

                    // ---------------- Email OTP: create & send (with BYPASS when no email) ----------------
                    $toEmail = $row['employee_email'] ?? '';
                    $toName  = $row['employee_name'] ?? $username;

                    // BYPASS 2FA if no valid email on the account
                    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                        // 🔐 Complete login without OTP
                        session_regenerate_id(true);
                        $_SESSION['username']    = $row['employee_username'] ?? $username;
                        $_SESSION['employee_id'] = $emp_id;
                        $_SESSION['Role_Id']     = $row['Role_Id'];
                        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';

                        // Flag UI to show “add email to enable 2FA”
                        $_SESSION['needs_email_for_2FA'] = true;

                        // Optionally ignore remember-me when 2FA absent
                        $_POST['remember'] = 0;

                        // Audit login with bypass
                        $trigger->isLogin(6, $_SESSION['employee_id']);
                        error_log("LOGIN: 2FA bypass used (no email) for employee_id={$emp_id}");

                        // Redirect to role landing
                        $_SESSION['login_success'] = true;
                        $_SESSION['redirect_page'] = $redirect_page;
                        header("Location: $redirect_page");
                        exit;
                    }

                    // Normal OTP flow when email is present
                    $otp_code  = (string)random_int(100000, 999999);
                    $otp_hash  = password_hash($otp_code, PASSWORD_DEFAULT);
                    $expiresAt = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

                    $ins = $mysqli->prepare("
                        INSERT INTO employee_email_otp (employee_id, code_hash, expires_at)
                        VALUES (?, ?, ?)
                    ");
                    $ins->bind_param('iss', $emp_id, $otp_hash, $expiresAt);
                    $ins->execute();
                    $ins->close();

                    try {
                        send_2fa_mail($toEmail, $toName, $otp_code);
                    } catch (Throwable $e) {
                        error_log('2FA email send failed: ' . $e->getMessage());
                        $error_message = "Unable to send verification email right now.";
                    }

                    if (empty($error_message)) {
                        // Stash details for verify step; DO NOT log in yet.
                        $_SESSION['email_otp_pending'] = [
                            'employee_id' => $emp_id,
                            'username'    => $row['employee_username'] ?? $username,
                            'Role_Id'     => $row['Role_Id'],
                            'Role_Name'   => $row['Role_Name'] ?? 'Staff',
                            'remember'    => !empty($_POST['remember']),
                            'redirect'    => $redirect_page,
                        ];

                        // Use an absolute path so it works from anywhere
                        header('Location: /auth/login_auth/verify_email.php');
                        exit;
                    }
                } else {
                    // ❌ Failure: increment attempts and return generic error
                    record_failed_attempt($mysqli, $emp_id);
                    $error_message = "Invalid login credentials.";
                    usleep(250000);
                }
            } else {
                // Username not found — keep generic
                $error_message = "Invalid login credentials.";
                usleep(250000);
            }

            $stmt->close();
        }
    } else {
        $error_message = "Please fill out all fields and complete reCAPTCHA.";
    }
}

function validateInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Fetch barangay info
$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name FROM barangay_info bi
    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $barangayName = preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']);

    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = $barangayName;
    } elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "Poblacion " . $barangayName;
    } elseif (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = $barangayName;
    } else {
        $barangayName = "Barangay " . $barangayName;
    }
} else {
    $barangayName = "NO BARANGAY FOUND";
}

$logo_result = $mysqli->query("SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1");
$logo = $logo_result->num_rows > 0 ? $logo_result->fetch_assoc() : null;

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Bugo - Login</title>
    <link rel="icon" type="image/png" href="assets/logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: url('logo/bugo.png') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            min-height: 100vh;
            position: relative;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .login-card {
            position: relative;
            z-index: 2;
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .footer {
            position: relative;
            z-index: 2;
            color: #fff;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="login-card">
                    <img src="assets/logo/bugo_logo.png" alt="Barangay Logo" width="80" class="mb-3">
                    <h2>Welcome Back!</h2>
                    <p class="mb-4">Please login to your account</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3 input-group">
                            <span class="input-group-text"><i class="fa fa-user"></i></span>
                            <input type="text" name="username" class="form-control" placeholder="Username" required>
                        </div>
                        <div class="mb-3 input-group">
                          <span class="input-group-text"><i class="fa fa-lock"></i></span>
                          <input type="password" id="password" name="password" class="form-control" placeholder="Password" required>
                          <button type="button" class="btn btn-outline-secondary" id="togglePassword" aria-label="Show password">
                            <i class="fa fa-eye"></i>
                          </button>
                        </div>
                        <div class="g-recaptcha my-3" data-sitekey="6Ldid00rAAAAAJW0Uh8pFV_ZPyvhICFCnqesb6Mv"></div>

                        <button type="submit" class="btn btn-primary w-100">Login</button>
                        <div class="form-check text-start mt-2">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                    </form>
                    <div class="mt-3">
                        <a href="forgot/forgot_password.php" class="text-muted">Forgot Password?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        Copyright &copy; <?php echo $barangayName . ' ' . date('Y'); ?>
    </div>

<?php if (!empty($error_message)) : ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    <?php if (strpos($error_message, "LOCKED:") === 0): 
        $secondsLeft = (int) str_replace("LOCKED:", "", $error_message);
    ?>
        let remaining = <?php echo $secondsLeft; ?>;
        Swal.fire({
            icon: 'error',
            title: 'Account Locked',
            html: 'Too many failed login attempts.<br><b id="countdown"></b>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                const countdownEl = Swal.getHtmlContainer().querySelector('#countdown');
                const timer = setInterval(() => {
                    let mins = Math.floor(remaining / 60);
                    let secs = remaining % 60;
                    countdownEl.textContent = `${mins}m ${secs}s remaining`;
                    remaining--;
                    if (remaining < 0) {
                        clearInterval(timer);
                        Swal.close();
                        location.reload(); // Reload so they can try again
                    }
                }, 1000);
            }
        });
    <?php else: ?>
        Swal.fire({
            icon: 'error',
            title: 'Login Failed',
            text: <?php echo json_encode($error_message); ?>,
            confirmButtonColor: '#3085d6'
        });
    <?php endif; ?>
});
</script>
<?php endif; ?>


    <?php if (!empty($_SESSION['login_success']) && !empty($_SESSION['redirect_page'])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            Swal.fire({
                icon: 'success',
                title: 'Login Successful',
                text: 'Welcome back!',
                showConfirmButton: false,
                timer: 1000,
                timerProgressBar: true
            }).then(() => {
                window.location.href = <?php echo json_encode($_SESSION['redirect_page']); ?>;
            });
        });
    </script>
    <?php
        unset($_SESSION['login_success']);
        unset($_SESSION['redirect_page']);
    endif; ?>

    <?php if (!empty($_SESSION['needs_email_for_2fa'])): ?>
    <script>
      document.addEventListener("DOMContentLoaded", function () {
        Swal.fire({
          icon: 'warning',
          title: 'Add your email to enable 2FA',
          text: 'For better security, please add a valid email to your profile to enable two-factor authentication.',
          confirmButtonText: 'OK'
        });
      });
    </script>
    <?php unset($_SESSION['needs_email_for_2fa']); endif; ?>
    
    <script>
document.addEventListener('DOMContentLoaded', function () {
  const pw = document.getElementById('password');
  const btn = document.getElementById('togglePassword');
  if (!pw || !btn) return;
  btn.addEventListener('click', function () {
    const isText = pw.type === 'text';
    pw.type = isText ? 'password' : 'text';
    const icon = this.querySelector('i');
    icon.classList.toggle('fa-eye', isText);
    icon.classList.toggle('fa-eye-slash', !isText);
    this.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
  });
});
</script>

    
</body>
</html>
