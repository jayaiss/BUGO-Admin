<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include the database connection file
include_once '../include/connection.php'; // Adjust path as per your setup

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Adjust these paths based on where you placed PHPMailer
// This assumes PHPMailer/src is directly inside your 'bugo' folder,
// so from 'api/request_password_reset.php' you go up one level (..)
// and then into PHPMailer/src
require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

// --- NEW: Check if connection was successful ---
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $mysqli->connect_error]);
    exit();
}
// --- END NEW ---


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['email'])) {
        $email = htmlspecialchars(trim($data['email']));

        // Check if the email exists in the residents table
        $stmt = $mysqli->prepare("SELECT COUNT(*) FROM residents WHERE email = ?");
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "SQL Prepare Error (check email): " . $mysqli->error]);
            $mysqli->close();
            exit();
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count === 0) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Email not found."]);
            $mysqli->close();
            exit();
        }

        // Generate a 6-digit OTP
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes')); // OTP valid for 10 minutes

        // Check if there's an existing unused token for this email
        $stmt = $mysqli->prepare("SELECT id FROM password_resets WHERE email = ? AND is_used = 0");
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "SQL Prepare Error (select existing token): " . $mysqli->error]);
            $mysqli->close();
            exit();
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows > 0) {
            // Update existing token
            $stmt = $mysqli->prepare("UPDATE password_resets SET token = ?, expires_at = ?, created_at = NOW(), is_used = 0 WHERE email = ?");
            if ($stmt === false) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "SQL Prepare Error (update token): " . $mysqli->error]);
                $mysqli->close();
                exit();
            }
            $stmt->bind_param("sss", $otp, $expires_at, $email);
        } else {
            // Insert new token
            $stmt = $mysqli->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            if ($stmt === false) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "SQL Prepare Error (insert token): " . $mysqli->error]);
                $mysqli->close();
                exit();
            }
            $stmt->bind_param("sss", $email, $otp, $expires_at);
        }

        if ($stmt->execute()) {
            // --- ACTUAL EMAIL SENDING WITH PHPMailer ---
            $mail = new PHPMailer(true); // Enable exceptions

            try {
                // Server settings
                // Server settings
$mail->isSMTP();
$mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'juja.martinez.coc@phinmaed.com'; // <--- IMPORTANT: Replace with your Gmail address
                $mail->Password   = 'YOUR_GMAIL_APP_PASSWORD';      // <--- IMPORTANT: Replace with your generated App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SMTPS for port 465 (most common for Gmail)
                $mail->Port       = 465;                         // TCP port to connect to

                // Recipients
                $mail->setFrom('YOUR_GMAIL_ADDRESS@gmail.com', 'Your App Name'); // Sender (must be your Gmail address)
                $mail->addAddress($email);                                  // Recipient email

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Code';
                $mail->Body    = 'Your password reset code is: <strong>' . $otp . '</strong><br>This code is valid for 10 minutes.';
                $mail->AltBody = 'Your password reset code is: ' . $otp . '. This code is valid for 10 minutes.';

                $mail->send();
                http_response_code(200);
                echo json_encode(["status" => "success", "message" => "Password reset code sent to your email."]);

            } catch (Exception $e) {
                // If email sending fails, log the error and respond accordingly
                error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}"); // Log error to server error logs
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Failed to send reset code email. Please try again. Mailer Error: {$mail->ErrorInfo}"]);
            }
            // --- END ACTUAL EMAIL SENDING ---

        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to save reset token: " . $stmt->error]);
        }
        $stmt->close();

    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Email not provided."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed. Use POST."]);
}

$mysqli->close();