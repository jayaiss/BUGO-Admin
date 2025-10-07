<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
//     http_response_code(403);
//     header('Content-Type: text/html; charset=UTF-8');
//     require_once __DIR__ . '/../security/403.html';
//     exit;
// }
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// Timeout duration: 10 minutes (600 seconds)
$timeout_duration = 60000; // 10 minutes in seconds

// Check if session has expired (inactivity longer than the timeout duration)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // Unset session variables and destroy the session
    session_unset();
    session_destroy();

    // Display the alert and then automatically redirect after the user closes the alert
    echo "
    <script>
        // Display an alert with the message
       alert('Your session has expired due to inactivity. Please log in again.');
       
       // Redirect to the login page after the alert is closed
        setTimeout(function() {
            window.location.href = 'index.php'; // Redirect to the login page
        }, 100); // Delay the redirect by 100 milliseconds to ensure the alert is shown
    </script>";
exit();}

// Update last activity time to prevent session timeout
$_SESSION['last_activity'] = time();
?>
