<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
// if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
//     http_response_code(403);
//     require_once __DIR__ . '/../security/403.html';
//     exit;
// }
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../include/redirects.php';
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../logs/logs_trig.php';
require_once __DIR__ . '/../util/helper/router.php';
$redirectPath = get_role_based_action('admin_dashboard');
$error = null;
$showSuccess = false; // 🔑 used to trigger JS in HTML
$trigger = new Trigger();
$oldData = $trigger->getOldAndNewData($_SESSION['employee_id'], 1); // 1 = employee table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    $enteredCode = trim($_POST['twofa_code']);

    if ($enteredCode === $_SESSION['2fa_code']) {
        $stmt = $mysqli->prepare("UPDATE employee_list SET employee_password = ? WHERE employee_id = ?");
        $stmt->bind_param("si", $_SESSION['new_hashed_password'], $_SESSION['employee_id']);
        $stmt->execute();

        $historyStmt = $mysqli->prepare("INSERT INTO emp_password_history (old_password, employee_id, change_date) VALUES (?, ?, NOW())");
        $historyStmt->bind_param("si", $_SESSION['old_hashed_password'], $_SESSION['employee_id']);
        $historyStmt->execute();
        $trigger->isEdit(1, $_SESSION['employee_id'], $oldData);

        unset($_SESSION['new_hashed_password'], $_SESSION['old_hashed_password'], $_SESSION['2fa_code']);

        $showSuccess = true; // ✅ trigger success alert in HTML
    } else {
        $error = "Invalid verification code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify 2FA</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container">
  <div class="card mt-5">
    <div class="card-body">
      <h4 class="mb-4">Enter Verification Code</h4>
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="text" name="twofa_code" class="form-control" placeholder="Enter code" required>
        <button type="submit" name="verify_2fa" class="btn btn-primary mt-3" id="verifyBtn">
          <span id="btnText">Verify</span>
          <span id="spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ✅ SweetAlert2 Success Message -->
<?php if ($showSuccess): ?>
<script>
Swal.fire({
  title: 'Verification Successful',
  text: 'Redirecting...',
  icon: 'success',
  timer: 2000,
  showConfirmButton: false,
  allowOutsideClick: false,
  didOpen: () => {
    Swal.showLoading();
  }
}).then(() => {
    window.location.href = <?= json_encode($redirectPath) ?>;
});
</script>

<?php endif; ?>

</body>
</html>
