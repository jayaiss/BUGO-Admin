<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../util/helper/router.php';
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/connection.php';

$employeeEmail = $_SESSION['employee_email'] ?? '';
$twoFaCode     = $_SESSION['2fa_code'] ?? '';

if (!$employeeEmail || !$twoFaCode) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Missing Info', 'Session expired or invalid request.', 'error');
    </script>";
    exit();
}

$mail = new PHPMailer(true);
try {
    // ── cPanel SMTP (bugoportal) ───────────────────────────────────────────
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

    // TEMP: relax TLS checks if cert CN doesn't match yet
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];

    // From / headers
    $mail->setFrom('admin@bugoportal.site', 'Barangay Bugo');
    $mail->addAddress($employeeEmail);
    // $mail->addBCC('admin@bugoportal.site'); // uncomment while testing deliveries
    $mail->addReplyTo('admin@bugoportal.site', 'Barangay Bugo');
    $mail->Sender   = 'admin@bugoportal.site'; // envelope-from
    $mail->Hostname = 'bugoportal.site';
    $mail->CharSet  = 'UTF-8';

    // Message
    $safeCode = htmlspecialchars($twoFaCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mail->isHTML(true);
    $mail->Subject = 'Barangay Bugo 2FA Code';
    $mail->Body    = "
        <p>Hello,</p>
        <p>Your verification code is:</p>
        <h2 style='color:#0d6efd; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial;'>$safeCode</h2>
        <p>This code is valid for 5 minutes.</p>
        <br><p>Thank you,<br>Barangay Bugo Portal</p>";
    $mail->AltBody = "Your verification code is: $twoFaCode\nThis code is valid for 5 minutes.";

    $mail->send();

    // ✅ Redirect to encrypted route for verify_2fa_password via role-based router
    $redirectPath = get_role_based_action('verify_2fa_password'); // may be relative or absolute

    if (preg_match('~^https?://~i', $redirectPath)) {
        // Already an absolute URL
        $fullUrl = $redirectPath;
    } else {
        // Build absolute from relative
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if ($redirectPath === '' || $redirectPath[0] !== '/') {
            $redirectPath = '/'.$redirectPath;
        }
        $fullUrl = $scheme . $host . $redirectPath;
    }

    // Safer redirect (server-side if headers not sent)
    if (!headers_sent()) {
        header('Location: ' . $fullUrl, true, 302);
        exit;
    } else {
        echo "<script>window.location.href = " . json_encode($fullUrl) . ";</script>";
        exit;
    }

} catch (Exception $e) {
    error_log('2FA mail error: ' . $mail->ErrorInfo);
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Email Failed',
            text: 'Could not send code: " . addslashes($mail->ErrorInfo) . "',
            confirmButtonColor: '#d33'
        });
    </script>";
}
