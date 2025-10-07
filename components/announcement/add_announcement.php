<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    // header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../../logs/logs_trig.php';
require_once __DIR__ . '/../../include/encryption.php';

if (isset($_POST['add_announcement'])) {
    $details = trim($_POST['announcement_details']);
    $employee_id = $_SESSION['employee_id'] ?? null;
    $role = strtolower($_SESSION['Role_Name'] ?? '');

    if (!empty($details) && $employee_id) {
        $stmt = $mysqli->prepare("INSERT INTO announcement (announcement_details, employee_id) VALUES (?, ?)");
        $stmt->bind_param("si", $details, $employee_id);

        if ($stmt->execute()) {
            $trigger = new Trigger();
            $trigger->isAdded(29, $stmt->insert_id); // 28 = ANNOUNCEMENT module

            // Switch role-based redirect
            switch ($role) {
                case 'admin':
                    $redirectPage = enc_admin('admin_dashboard');
                    break;
                case 'punong barangay':
                    $redirectPage = enc_captain('admin_dashboard');
                    break;
                case 'beso':
                    $redirectPage = enc_beso('admin_dashboard');
                    break;
                case 'barangay secretary':
                    $redirectPage = enc_brgysec('admin_dashboard');
                    break;
                case 'lupon':
                    $redirectPage = enc_lupon('admin_dashboard');
                    break;
                case 'multimedia':
                    $redirectPage = enc_multimedia('admin_dashboard');
                    break;
                case 'revenue staff':
                    $redirectPage = enc_revenue('admin_dashboard');
                    break;  
                case 'encoder':
                    $redirectPage = enc_encoder('admin_dashboard');
                    break;              
                default:
                    $redirectPage = '../../index.php';
                    break;
            }

            echo "
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Announcement Added',
                        text: 'Your announcement has been successfully added!',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        window.location.href = '$redirectPage';
                    });
                });
            </script>";
        } else {
            echo "
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Database Error',
                        text: '" . addslashes($mysqli->error) . "',
                        confirmButtonColor: '#d33'
                    }).then(() => {
                        window.history.back();
                    });
                });
            </script>";
        }

        $stmt->close();
    } else {
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Fields',
                    text: 'Please complete all required fields.',
                    confirmButtonColor: '#f39c12'
                }).then(() => {
                    window.history.back();
                });
            });
        </script>";
    }
}
?>
