<?php
// auth/verify_code.php (adjust path if different)
declare(strict_types=1);

if (!isset($_SESSION)) session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = '';
$success = '';
$attempts = $_SESSION['fp_attempts'] ?? 0;

// Must come from the forgot step
if (!isset($_SESSION['fp_employee_id'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// Set or read the creation time (10 mins validity)
if (!isset($_SESSION['fp_code_created'])) {
    $_SESSION['fp_code_created'] = time();
}
$codeCreated       = (int)$_SESSION['fp_code_created'];
$isExpired         = (time() - $codeCreated > 600);
$remainingSeconds  = max(0, 600 - (time() - $codeCreated));

// Business logic
if ($attempts >= 3) {
    $error = "Too many incorrect attempts. Please try again later.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify code
    if (isset($_POST['code'])) {
        $entered = strtoupper(trim($_POST['code'] ?? ''));
        if (strpos($entered, 'BUGO-') !== 0) {
            $digits  = preg_replace('/\D+/', '', $entered);
            $entered = 'BUGO-' . substr($digits, 0, 6);
        }
        $sessionCode = (string)($_SESSION['fp_code'] ?? '');

        if ($isExpired) {
            $error = "Code expired. Please resend.";
        } elseif ($sessionCode !== '' && hash_equals($sessionCode, $entered)) {
            $_SESSION['fp_attempts'] = 0;
            header("Location: reset_password.php");
            exit;
        } else {
            $_SESSION['fp_attempts'] = $attempts + 1;
            $error = "Invalid code. Attempt " . $_SESSION['fp_attempts'] . " of 3.";
        }
    }

    // Resend code
    if (isset($_POST['resend'])) {
        $newCode = 'BUGO-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['fp_code']         = $newCode;
        $_SESSION['fp_code_created'] = time();
        $_SESSION['fp_attempts']     = 0;

        $email = $_SESSION['fp_email'] ?? '';

        $mail = new PHPMailer(true);
        try {
            // ── cPanel SMTP (bugoportal) ──────────────────────────────
            $mail->isSMTP();
            $mail->Host          = 'mail.bugoportal.site';
            $mail->SMTPAuth      = true;
            $mail->Username      = 'admin@bugoportal.site';
            $mail->Password      = 'Jayacop@100';
            $mail->Port          = 465;
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS; // SSL (465)
            $mail->SMTPAutoTLS   = true;
            $mail->SMTPKeepAlive = false;
            $mail->Timeout       = 12;

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];

            $mail->setFrom('admin@bugoportal.site', 'Barangay Bugo');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'New Password Reset Code';
            $mail->Body    = "Your new password reset code is: <strong>" . htmlspecialchars($newCode, ENT_QUOTES, 'UTF-8') . "</strong>";
            $mail->AltBody = "Your new password reset code is: $newCode";

            $mail->send();
            $success = "New code sent to $email";
            $remainingSeconds = 600;
            $isExpired = false;
        } catch (Exception $e) {
            $error = "Could not resend code: " . $mail->ErrorInfo;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Verify Code</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/logo/logo.png">

  <!-- Use your dark card styling to match 2FA screens -->
  <link rel="stylesheet" href="/auth/assets/cp_2fa.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php if (!empty($error)): ?>
<script>Swal.fire({icon:'error', title:'Oops', text: <?= json_encode($error) ?>});</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>Swal.fire({icon:'success', title:'Sent', text: <?= json_encode($success) ?>, timer: 1200, showConfirmButton:false});</script>
<?php endif; ?>

<div class="card">
  <div class="header">
    <img class="logo" src="/assets/logo/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="title">Verify your email</div>
  </div>
  <div class="sub">Enter the 6‑digit code we sent to your email to continue.</div>

  <form id="verifyForm" method="post" autocomplete="off" style="margin-top:10px">
    <input type="hidden" name="code" id="fullCode">
    <div style="display:flex;justify-content:center;align-items:center">
      <span class="prefix">BUGO‑</span>
      <div class="otp-wrap" id="otpWrap">
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>
      </div>
    </div>

    <div class="actions" style="margin-top:14px">
      <button id="submitBtn" type="submit" class="btn btn-primary" <?= ($isExpired || $attempts>=3) ? 'disabled' : '' ?>>Verify</button>
    </div>

    <div class="muted" style="margin-top:10px">
      Code expires in:
      <b id="countdown"><?= gmdate("i:s", max(0, $remainingSeconds)) ?></b>
      <?php if ($attempts>0 && $attempts<3): ?>
        · Attempts: <?= (int)$attempts ?>/3
      <?php endif; ?>
    </div>
  </form>

  <form method="post" autocomplete="off" style="margin-top:12px; text-align:center">
    <button class="btn" name="resend" type="submit" <?= ($remainingSeconds > 540) ? 'disabled' : '' ?>>
      Resend code
    </button>
    <div class="muted" style="margin-top:6px">You can request a new code after 60 seconds.</div>
  </form>
</div>

<script>
  // OTP UX: 6 boxes + paste, maintain hidden full value "BUGO-123456"
  const inputs    = Array.from(document.querySelectorAll('.otp-input'));
  const hidden    = document.getElementById('fullCode');
  const form      = document.getElementById('verifyForm');
  const submitBtn = document.getElementById('submitBtn');

  function syncHidden() {
    const digits = inputs.map(i => i.value.replace(/\D/g,'')).join('').slice(0,6);
    hidden.value = digits ? ('BUGO-' + digits) : '';
    return digits.length === 6;
  }

  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
      e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);
      if (e.target.value && idx < inputs.length - 1) inputs[idx+1].focus();
      syncHidden();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !e.target.value && idx > 0) inputs[idx-1].focus();
    });
    inp.addEventListener('paste', e => {
      const text = (e.clipboardData || window.clipboardData).getData('text') || '';
      if (!text) return;
      e.preventDefault();
      let m = text.toUpperCase().match(/BUGO[-–—]?(\d{6})/);
      const digits = m ? m[1] : text.replace(/\D/g,'').slice(0,6);
      for (let i=0;i<inputs.length;i++) inputs[i].value = digits[i] || '';
      inputs[Math.min(digits.length,5)].focus();
      syncHidden();
    });
  });

  form?.addEventListener('submit', (e) => {
    if (!syncHidden()) {
      e.preventDefault();
      Swal.fire({icon:'warning',title:'Incomplete',text:'Please enter all 6 digits.'});
      return;
    }
    submitBtn?.setAttribute('disabled','disabled');
    submitBtn.textContent = 'Verifying…';
  });

  // Countdown
  let secondsLeft = <?= json_encode((int)$remainingSeconds) ?>;
  const countdown = document.getElementById('countdown');
  function tick() {
    if (!countdown) return;
    if (secondsLeft <= 0) { countdown.textContent = '00:00'; return; }
    secondsLeft--;
    const mm = String(Math.floor(secondsLeft/60)).padStart(2,'0');
    const ss = String(secondsLeft%60).padStart(2,'0');
    countdown.textContent = `${mm}:${ss}`;
    setTimeout(tick, 1000);
  }
  tick();

  // Prevent BFCache stale page
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
</script>

</body>
</html>
