<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../include/connection.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // PHPMailer
use PHPMailer\PHPMailer\PHPMailer;

if (empty($_SESSION['email_otp_pending'])) {
    header('Location: /index.php');
    exit;
}

$mysqli = db_connection();
$ctx    = $_SESSION['email_otp_pending'];
$empId  = (int)$ctx['employee_id'];

// Fetch fresh email + name from DB
$q = $mysqli->prepare("
  SELECT employee_email,
         COALESCE(NULLIF(TRIM(CONCAT(employee_fname,' ',employee_lname)),''), employee_username) AS employee_name
  FROM employee_list
  WHERE employee_id = ? AND employee_delete_status = 0
");
$q->bind_param('i', $empId);
$q->execute();
$r  = $q->get_result();
$u  = $r->fetch_assoc();
$q->close();

if (!$u || !filter_var($u['employee_email'], FILTER_VALIDATE_EMAIL)) {
    $_SESSION['resend_error'] = 'Cannot resend — invalid email on file.';
    header('Location: /auth/login_auth/verify_email.php');
    exit;
}

// New OTP
$code      = (string)random_int(100000, 999999);
$code_hash = password_hash($code, PASSWORD_DEFAULT);
$expires   = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

// Save
$ins = $mysqli->prepare("
  INSERT INTO employee_email_otp (employee_id, code_hash, expires_at)
  VALUES (?, ?, ?)
");
$ins->bind_param('iss', $empId, $code_hash, $expires);
$ins->execute();
$ins->close();

// --- Mail sender helper (same as your reference) ---
function send_2fa_mail(string $toEmail, string $toName, string $code): void {
  $mailboxUser = 'admin@bugoportal.site';
  $mailboxPass = 'Jayacop@100';
  $smtpHost    = 'mail.bugoportal.site';

  $safeName = htmlspecialchars($toName ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $safeCode = htmlspecialchars($code ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $buildMessage = function(PHPMailer $m) use ($toEmail, $safeName, $safeCode, $mailboxUser) {
    $m->setFrom($mailboxUser, 'Barangay Bugo');
    $m->addAddress($toEmail, $safeName);
    $m->addBCC($mailboxUser);
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

    if ($mode === 'ssl') {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $buildMessage($mail);
    $mail->send();
  };

  error_log("2FA: resending code={$code} to {$toEmail}");

  try {
    $attempt('ssl', 465);
  } catch (\Throwable $e1) {
    try {
      $attempt('tls', 587);
    } catch (\Throwable $e2) {
      try {
        $fallback = new PHPMailer(true);
        $fallback->isMail();
        $buildMessage($fallback);
        $fallback->send();
      } catch (\Throwable $e3) {
        throw new \RuntimeException('Unable to send verification email right now.');
      }
    }
  }
}
// Send it
send_2fa_mail($u['employee_email'], $u['employee_name'] ?? '', $code);

$_SESSION['resend_success'] = true;
header('Location: /auth/login_auth/verify_email.php');
exit;
