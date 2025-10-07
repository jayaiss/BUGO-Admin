<?php
declare(strict_types=1);

require_once __DIR__ . '/../../security/security.php';
require_once __DIR__ . '/../../include/connection.php';
require_once __DIR__ . '/../../include/encryption.php';
require_once __DIR__ . '/../../logs/logs_trig.php';

$mysqli  = db_connection();
$trigger = new Trigger();

if (empty($_SESSION['email_otp_pending'])) {
    header('Location: /index.php');
    exit;
}

$ctx      = $_SESSION['email_otp_pending'];
$empId    = (int)$ctx['employee_id'];
$remember = !empty($ctx['remember']);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$verified = false;
$nextUrl  = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $code        = trim($_POST['code'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit code.';
    } else {
        // Most recent OTP for this employee
        $qry = $mysqli->prepare("
            SELECT id, code_hash, expires_at, attempts, used_at
            FROM employee_email_otp
            WHERE employee_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $qry->bind_param('i', $empId);
        $qry->execute();
        $res = $qry->get_result();
        $otp = $res->fetch_assoc();
        $qry->close();

        if (!$otp || $otp['used_at'] !== null) {
            $error = 'No active verification code found. Please request a new one.';
        } else {
            $now = new DateTimeImmutable();
            $exp = new DateTimeImmutable($otp['expires_at']);

            if ($now > $exp) {
                $upd = $mysqli->prepare("UPDATE employee_email_otp SET used_at = NOW() WHERE id = ?");
                $upd->bind_param('i', $otp['id']);
                $upd->execute();
                $upd->close();
                $error = 'Verification code expired. Please request a new one.';
            } elseif ((int)$otp['attempts'] >= 5) {
                $error = 'Too many incorrect attempts. Request a new code.';
            } elseif (!password_verify($code, $otp['code_hash'])) {
                $inc = $mysqli->prepare("UPDATE employee_email_otp SET attempts = attempts + 1 WHERE id = ?");
                $inc->bind_param('i', $otp['id']);
                $inc->execute();
                $inc->close();
                $error = 'Incorrect code. Please try again.';
            } else {
                // ✅ Success
                $use = $mysqli->prepare("UPDATE employee_email_otp SET used_at = NOW() WHERE id = ?");
                $use->bind_param('i', $otp['id']);
                $use->execute();
                $use->close();

                session_regenerate_id(true);
                $_SESSION['username']    = $ctx['username'];
                $_SESSION['employee_id'] = $empId;
                $_SESSION['Role_Id']     = $ctx['Role_Id'];
                $_SESSION['Role_Name']   = $ctx['Role_Name'];
                unset($_SESSION['csrf_token'], $_SESSION['email_otp_pending']);

                // Audit
                $trigger->isLogin(6, $_SESSION['employee_id']);

                // Remember-me cookie
                if ($remember) {
                    $token      = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);

                    $del = $mysqli->prepare("DELETE FROM login_tokens WHERE employee_id = ?");
                    $del->bind_param("i", $empId);
                    $del->execute();
                    $del->close();

                    $insert = $mysqli->prepare("
                        INSERT INTO login_tokens (employee_id, token_hash, expiry)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ");
                    $insert->bind_param("is", $empId, $token_hash);
                    $insert->execute();
                    $insert->close();

                    setcookie("remember_token", $token, [
                        'expires'  => time() + (7*24*60*60),
                        'path'     => '/',
                        'secure'   => true,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }

                // ✅ Redirect based on role
                $_SESSION['login_success'] = true;
                $_SESSION['redirect_page'] = role_redirect($_SESSION['Role_Name']);

                $verified = true;
                $nextUrl  = $_SESSION['redirect_page'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Email Verification</title>
  <link rel="icon" type="image/png" href="/assets/logo/logo.png">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
:root {
  --bg: #0b1020;
  --card: #12182b;
  --muted: #98a2b3;
  --text: #e6e9f2;
  --primary: #4f46e5;
  --primary-2: #6366f1;
  --accent: #22c55e;
  --danger: #ef4444;
  --ring: rgba(99, 102, 241, 0.35);
}
* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0;
  background: radial-gradient(1200px 800px at 80% -10%, #1a2150 0%, transparent 60%),
              radial-gradient(900px 700px at -20% 120%, #0a5d7c 0%, transparent 60%),
              var(--bg);
  color: var(--text);
  font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.card {
  width: 100%;
  max-width: 520px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 16px;
  padding: 24px 22px;
  box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
}
.header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.logo { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; }
.title { font-size: 20px; font-weight: 700; letter-spacing: 0.2px; }
.sub { color: var(--muted); font-size: 14px; margin-bottom: 18px; }
.otp-wrap { display: flex; gap: 8px; justify-content: center; margin: 18px 0 10px; }
.otp-input {
  width: 48px; height: 56px; text-align: center; font-size: 22px; font-weight: 700;
  border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.12);
  background: #0f1530; color: var(--text); outline: none; transition: all 0.18s ease;
}
.otp-input:focus { border-color: var(--primary-2); box-shadow: 0 0 0 4px var(--ring); }
.prefix {
  user-select: none; font-weight: 700; letter-spacing: 0.5px; color: #a5b4fc;
  background: #0f1530; border: 1px dashed rgba(255, 255, 255, 0.12);
  border-radius: 12px; padding: 14px 10px; margin-right: 8px; align-self: center;
}
.actions { display: flex; gap: 10px; margin-top: 16px; justify-content: center; }
.btn {
  padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.12);
  background: #0f1530; color: var(--text); cursor: pointer; font-weight: 600; transition: 0.18s ease;
}
.btn:hover { transform: translateY(-1px); border-color: rgba(255, 255, 255, 0.22); }
.btn-primary { background: linear-gradient(90deg, var(--primary), var(--primary-2)); border: none; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.muted { color: var(--muted); font-size: 13px; text-align: center; margin-top: 4px; }
.alert {
  margin: 0 0 10px; padding: 10px 12px; border-radius: 10px; font-size: 14px; line-height: 1.3;
  background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #fecaca;
}
.alert.success {
  background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.3); color: #bbf7d0;
}
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <img src="/assets/logo/logo.png" class="logo" alt="Logo">
      <div class="title">Email verification</div>
    </div>
    <div class="sub">We sent a 6-digit code to your email. Enter it below to continue.</div>

    <?php if (!empty($error)): ?>
      <div class="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['resend_success'])): unset($_SESSION['resend_success']); ?>
      <div class="alert success">A new code has been sent.</div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['resend_error'])): ?>
      <div class="alert"><?php
        echo htmlspecialchars($_SESSION['resend_error'], ENT_QUOTES, 'UTF-8');
        unset($_SESSION['resend_error']);
      ?></div>
    <?php endif; ?>

    <!-- Verify form -->
    <form id="verifyForm" method="post" autocomplete="one-time-code" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
      <!-- hidden combined code to keep PHP logic unchanged -->
      <input type="hidden" name="code" id="code">

      <div style="display:flex; justify-content:center; align-items:center;">
        <div class="otp-wrap">
          <?php for ($i=0; $i<6; $i++): ?>
            <input
              type="text"
              inputmode="numeric"
              pattern="\d*"
              maxlength="1"
              class="otp-input"
              aria-label="OTP digit <?php echo $i+1; ?>"
              data-index="<?php echo $i; ?>"
            >
          <?php endfor; ?>
        </div>
      </div>

      <div class="actions">
        <button type="submit" id="verifyBtn" class="btn btn-primary" disabled>Verify</button>
        <button type="button" id="resendBtn" class="btn">Resend code</button>
      </div>
      <div class="muted">Having trouble? You can request a new code.</div>
    </form>

    <!-- Hidden resend form to preserve POST to your endpoint -->
    <form id="resendForm" method="post" action="/auth/login_auth/verify_email_resend.php" style="display:none;"></form>
  </div>

  <script>
  // SweetAlert during actions
  function showLoading(title, text) {
    Swal.fire({
      title: title,
      text: text,
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => Swal.showLoading()
    });
  }

  // OTP inputs behavior
  (function () {
    const inputs = Array.from(document.querySelectorAll('.otp-input'));
    const hidden = document.getElementById('code');
    const verifyBtn = document.getElementById('verifyBtn');
    const verifyForm = document.getElementById('verifyForm');
    const resendBtn = document.getElementById('resendBtn');
    const resendForm = document.getElementById('resendForm');

    // focus first box
    inputs[0]?.focus();

    function updateHiddenAndButton() {
      const value = inputs.map(i => i.value.replace(/\D/g, '') || '').join('');
      hidden.value = value;
      verifyBtn.disabled = value.length !== 6;
    }

    inputs.forEach((input, idx) => {
      input.addEventListener('input', (e) => {
        const v = e.target.value.replace(/\D/g,'');
        e.target.value = v.slice(-1); // keep last digit only
        if (e.target.value && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
          inputs[idx + 1].select();
        }
        updateHiddenAndButton();
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && idx > 0) {
          inputs[idx - 1].focus();
          inputs[idx - 1].value = '';
          updateHiddenAndButton();
        }
        if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
        if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
      });
    });

    // Paste handler: allow pasting all 6 digits
    inputs[0].addEventListener('paste', (e) => {
      const text = (e.clipboardData || window.clipboardData).getData('text') || '';
      const digits = text.replace(/\D/g, '').slice(0, 6).split('');
      if (digits.length) {
        e.preventDefault();
        inputs.forEach((inp, i) => { inp.value = digits[i] || ''; });
        updateHiddenAndButton();
        inputs[Math.min(digits.length, 5)].focus();
      }
    });

    verifyForm.addEventListener('submit', (e) => {
      updateHiddenAndButton();
      if (hidden.value.length !== 6) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Invalid code', text: 'Enter the 6-digit code.' });
        return;
      }
      showLoading('Verifying…', 'Please wait a moment');
    });

    resendBtn.addEventListener('click', () => {
      showLoading('Sending code…', 'Check your inbox shortly');
      resendForm.submit();
    });
  })();
  </script>

<?php if (!empty($verified)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'success',
    title: 'Verified',
    text: 'Logging you in…',
    showConfirmButton: false,
    timer: 1200,
    timerProgressBar: true
  }).then(() => {
    window.location.href = <?php echo json_encode($nextUrl); ?>;
  });
});
</script>
<?php endif; ?>
</body>
</html>
