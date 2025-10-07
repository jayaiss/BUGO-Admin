<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include the database connection file
include_once '../include/connection.php'; // Adjust path as per your setup

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['email']) && isset($data['otp'])) {
        $email = htmlspecialchars(trim($data['email']));
        $otp = htmlspecialchars(trim($data['otp']));

        // 1. Find the latest active and unused token for the given email
        $stmt = $mysqli->prepare("SELECT id, token, expires_at, is_used FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "No reset request found for this email."]);
            $stmt->close();
            $mysqli->close();
            exit();
        }

        $reset_data = $result->fetch_assoc();
        $stmt->close();

        // 2. Check if the token is correct, not expired, and not already used
        if ($reset_data['token'] == $otp &&
            strtotime($reset_data['expires_at']) > time() &&
            $reset_data['is_used'] == 0)
        {
            // Mark the token as used immediately to prevent reuse
            $stmt_update = $mysqli->prepare("UPDATE password_resets SET is_used = 1 WHERE id = ?");
            $stmt_update->bind_param("i", $reset_data['id']);
            $stmt_update->execute();
            $stmt_update->close();

            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Code verified successfully."]);
        } else {
            // Provide specific error messages for better user experience
            if ($reset_data['token'] != $otp) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Invalid verification code."]);
            } elseif (strtotime($reset_data['expires_at']) <= time()) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Verification code has expired."]);
            } elseif ($reset_data['is_used'] == 1) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Verification code has already been used."]);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
            }
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Email or OTP not provided."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed. Use POST."]);
}

$mysqli->close();
?>