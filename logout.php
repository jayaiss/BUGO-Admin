<?php
session_start();
require_once './include/connection.php';
$mysqli = db_connection();
require_once './logs/logs_trig.php';

$trigger = new Trigger();
$employeeId = $_SESSION['employee_id'] ?? null;

// 🔐 Audit logging
if ($employeeId) {
    $trigger->isLogout(7, $employeeId);
}

// 🧹 Delete remember_token from DB
if (isset($_COOKIE['remember_token'])) {
    $tokenHash = hash('sha256', $_COOKIE['remember_token']);
    $stmt = $mysqli->prepare("DELETE FROM login_tokens WHERE token_hash = ?");
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
}

// ❌ Clear remember_token cookie client-side
setcookie('remember_token', '', time() - 3600, "/", "", true, true);

// 🔒 Clear session
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging Out...</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        Swal.fire({
            icon: "success",
            title: "Logged",
            text: "You have been successfully logged out.",
            confirmButtonColor: "#3085d6"
        }).then(() => {
            window.location.href = "index.php"; // /index.php
        });
    });
</script>
</body>
</html>
