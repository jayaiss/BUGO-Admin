<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include the database connection file
include_once '../include/connection.php';

// Check if the request method is POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Decode the JSON data sent from the Flutter app
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate if all required fields are present
    if (isset($data['resident_id']) && isset($data['current_password']) && isset($data['new_password']) && isset($data['confirm_password'])) {
        $resident_id = $data['resident_id'];
        $current_password = $data['current_password'];
        $new_password = $data['new_password'];
        $confirm_password = $data['confirm_password'];

        // Password pattern: Min 8 chars, at least one uppercase, one lowercase, and one number
        $password_pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/';

        // Validate password format and matching
        if (!preg_match($password_pattern, $new_password)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "New password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, and one number."]);
            exit();
        }

        if ($new_password !== $confirm_password) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "New password and confirmation do not match."]);
            exit();
        }

        // Fetch current hashed password from the database
        $stmt = $mysqli->prepare("SELECT password FROM residents WHERE id = ? AND resident_delete_status = 0");
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $hashed_current_password = $row['password'];

            // Verify the current password
            if (password_verify($current_password, $hashed_current_password)) {
                // Check if the new password is the same as the current one
                if (password_verify($new_password, $hashed_current_password)) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "New password cannot be the same as the current password."]);
                    $stmt->close();
                    exit();
                }

                // Hash the new password before storing it
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                // [MODIFIED] Update the password and set res_pass_change to 1 for the resident
                $stmt_update = $mysqli->prepare("UPDATE residents SET password = ?, res_pass_change = 1 WHERE id = ?");
                $stmt_update->bind_param("si", $hashed_new_password, $resident_id);

                if ($stmt_update->execute()) {
                    http_response_code(200);
                    echo json_encode(["status" => "success", "message" => "Password successfully changed. You can now log in with your new password."]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error updating password. Please try again."]);
                }

                $stmt_update->close();
            } else {
                http_response_code(401);
                echo json_encode(["status" => "error", "message" => "Current password is incorrect."]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Resident not found or account is deleted."]);
        }
        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed. Use POST."]);
}

$mysqli->close();
?>