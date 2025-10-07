<?php
// auth/reset_password.php  (admin/employee flow)
declare(strict_types=1);

if (!isset($_SESSION)) session_start();
require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../logs/logs_trig.php';

$mysqli = db_connection();
$success = $error = '';

if (!isset($_SESSION['fp_employee_id'])) {
    header('Location: forgot_password.php');
    exit;
}

$employee_id = (int)$_SESSION['fp_employee_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = trim($_POST['new_password'] ?? '');
    $p2 = trim($_POST['confirm_password'] ?? '');

    // ✅ Policy: 12+ chars, 1 upper, 1 lower, 1 digit, 1 special
    $policy = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{12,}$/';

    if ($p1 === '' || $p2 === '') {
        $error = 'All fields are required.';
    } elseif (!preg_match($policy, $p1)) {
        $error = 'Password must be at least 12 characters and include uppercase, lowercase, a number, and a special character.';
    } elseif (!hash_equals($p1, $p2)) {
        $error = 'Passwords do not match.';
    } else {
        // Fetch current hash
        $stmtCur = $mysqli->prepare("SELECT employee_password FROM employee_list WHERE employee_id = ?");
        $stmtCur->bind_param("i", $employee_id);
        $stmtCur->execute();
        $stmtCur->bind_result($current_hash);
        if (!$stmtCur->fetch()) {
            $stmtCur->close();
            $error = 'Account not found.';
        } else {
            $stmtCur->close();

            // Reject if same as current
            if (password_verify($p1, (string)$current_hash)) {
                $error = 'You cannot reuse old password.';
            } else {
                // Check password history reuse
                $stmtHist = $mysqli->prepare("SELECT old_password FROM emp_password_history WHERE employee_id = ?");
                $stmtHist->bind_param("i", $employee_id);
                $stmtHist->execute();
                $resHist = $stmtHist->get_result();

                $reused = false;
                while ($row = $resHist->fetch_assoc()) {
                    if (password_verify($p1, (string)$row['old_password'])) {
                        $reused = true;
                        break;
                    }
                }
                $stmtHist->close();

                if ($reused) {
                    $error = 'You cannot reuse old password.';
                } else {
                    $new_hash = password_hash($p1, PASSWORD_DEFAULT);

                    // Audit: capture old data BEFORE update
                    $trigger = new Trigger();
                    $oldData = $trigger->getOldAndNewData($employee_id, 1); // 1 = employee_list

                    // Update to new password
                    $stmtUpd = $mysqli->prepare("UPDATE employee_list SET employee_password = ? WHERE employee_id = ?");
                    $stmtUpd->bind_param("si", $new_hash, $employee_id);

                    if ($stmtUpd->execute()) {
                        $stmtUpd->close();

                        // Store OLD hash in history (not the new one)
                        $stmtStore = $mysqli->prepare("INSERT INTO emp_password_history (old_password, employee_id, change_date) VALUES (?, ?, NOW())");
                        $stmtStore->bind_param("si", $current_hash, $employee_id);
                        $stmtStore->execute();
                        $stmtStore->close();

                        // Audit log
                        $trigger->isEdit(1, $employee_id, $oldData);

                        // Clear reset flow session
                        unset($_SESSION['fp_code'], $_SESSION['fp_employee_id'], $_SESSION['fp_email'], $_SESSION['fp_code_created'], $_SESSION['fp_attempts']);
                        session_regenerate_id(true);

                        $success = 'Password updated!';
                    } else {
                        $stmtUpd->close();
                        $error = 'Failed to update password.';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Reset Password</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/logo/logo.png">
  <!-- Match your 2FA dark card UI -->
  <link rel="stylesheet" href="/auth/assets/cp_2fa.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php if ($error): ?>
<script>Swal.fire({icon:'error', title:'Oops', text: <?= json_encode($error) ?>});</script>
<?php endif; ?>

<?php if ($success): ?>
<script>
Swal.fire({
  title: 'Success!',
  text: 'Password updated. Redirecting to login…',
  icon: 'success',
  timer: 1800,
  showConfirmButton: false
}).then(() => {
  window.location.href = '/index.php';
});
</script>
<?php endif; ?>

<div class="card">
  <div class="header">
    <img class="logo" src="/logo/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="title">Reset your password</div>
  </div>
  <div class="sub">Use a strong password that you haven’t used here before.</div>

  <form method="post" autocomplete="off" id="resetForm">
    <div class="form-group" style="margin-top:16px">
      <label class="label" for="new_password">New password</label>
      <div style="position:relative">
        <input id="new_password" name="new_password" type="password"
               class="otp-input"
               style="width:100%; padding:12px 44px 12px 14px; letter-spacing:0; text-align:left"
               minlength="12" required
               placeholder="At least 12 characters, incl. special char">
        <button type="button" id="toggle1" class="btn"
                style="position:absolute; right:6px; top:6px; padding:6px 10px">Show</button>
      </div>
      <div id="meter" class="muted" style="margin-top:6px">Strength: <b id="meterText">—</b></div>
      <ul class="muted" style="margin-top:6px; line-height:1.3">
        <li>Uppercase, lowercase, number, special character</li>
        <li>Minimum 12 characters</li>
        <li>Avoid previously used passwords</li>
      </ul>
    </div>

    <div class="form-group" style="margin-top:14px">
      <label class="label" for="confirm_password">Confirm new password</label>
      <div style="position:relative">
        <input id="confirm_password" name="confirm_password" type="password"
               class="otp-input"
               style="width:100%; padding:12px 44px 12px 14px; letter-spacing:0; text-align:left"
               minlength="12" required placeholder="Re-enter password">
        <button type="button" id="toggle2" class="btn"
                style="position:absolute; right:6px; top:6px; padding:6px 10px">Show</button>
      </div>
    </div>

    <div class="actions" style="margin-top:18px">
      <button type="submit" class="btn btn-primary">Reset Password</button>
      <a class="btn" href="../index.php" style="margin-left:8px">Cancel</a>
    </div>
  </form>
</div>

<script>
  // Show/Hide toggles
  function toggle(id, btnId) {
    const inp = document.getElementById(id);
    const btn = document.getElementById(btnId);
    btn.addEventListener('click', () => {
      inp.type = (inp.type === 'password') ? 'text' : 'password';
      btn.textContent = (inp.type === 'password') ? 'Show' : 'Hide';
    });
  }
  toggle('new_password', 'toggle1');
  toggle('confirm_password', 'toggle2');

  // Strength meter aligned with policy
  const np = document.getElementById('new_password');
  const meterText = document.getElementById('meterText');
  np.addEventListener('input', () => {
    const v = np.value;
    let score = 0;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[a-z]/.test(v)) score++;
    if (/\d/.test(v)) score++;
    if (/[^A-Za-z\d]/.test(v)) score++; // special char
    meterText.textContent = ['Very weak','Weak','Fair','Good','Strong'][score] || '—';
  });

  // Prevent double-submit
  const form = document.getElementById('resetForm');
  form.addEventListener('submit', () => {
    const btn = document.querySelector('.actions .btn.btn-primary');
    btn.setAttribute('disabled', 'disabled');
    btn.textContent = 'Saving…';
  });
</script>

</body>
</html>
