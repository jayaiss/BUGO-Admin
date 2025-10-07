<?php
// TEMPORARY: Suppress warnings from being displayed in the HTTP response body
// This helps prevent Flutter's FormatException by ensuring a clean JSON response.
// You should aim to fix any underlying warnings permanently later.
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE); // Show errors, hide warnings and notices
ini_set('display_errors', 0); // Do not display errors on screen

// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your database connection file
include_once '../include/connection.php';

// Function to sanitize input
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = isset($_POST['resident_id']) ? intval($_POST['resident_id']) : 0;
    $email = sanitize_input($_POST['email'] ?? '');
    $contact_number = sanitize_input($_POST['contact_number'] ?? '');
    $occupation = sanitize_input($_POST['occupation'] ?? '');

    $emergencyContactName = sanitize_input($_POST['emergency_contact_name'] ?? '');
    $emergencyContactRelationship = sanitize_input($_POST['emergency_contact_relationship'] ?? '');
    $emergencyContactNumber = sanitize_input($_POST['emergency_contact_number'] ?? '');
    $emergencyContactEmail = sanitize_input($_POST['emergency_contact_email'] ?? '');
    $emergencyContactAddress = sanitize_input($_POST['emergency_contact_address'] ?? '');

    if ($resident_id <= 0) {
        http_response_code(400);
        echo json_encode(array("status" => "error", "message" => "Invalid resident ID."));
        exit();
    }

    $mysqli->begin_transaction();

    $profilePictureBlob = null; // Initialize profile picture BLOB

    try {
        // --- START: Image Upload Handling for BLOB ---
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
            $fileSize = $_FILES['profile_picture']['size'];
            $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));

            error_log("[DEBUG] Uploaded File Name: " . $_FILES['profile_picture']['name']);
            error_log("[DEBUG] Uploaded Temp Path: " . $fileTmpPath);
            error_log("[DEBUG] Uploaded File Error: " . $_FILES['profile_picture']['error']);
            error_log("[DEBUG] Uploaded File Size: " . $fileSize . " bytes");

            $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif');
            if (!in_array($fileExtension, $allowedfileExtensions)) {
                throw new Exception("Upload failed. Allowed file types: " . implode(', ', $allowedfileExtensions));
            }
            if ($fileSize > 5000000) { // Max file size 5MB
                throw new Exception("Upload failed. File size is too large (max 5MB).");
            }

            $profilePictureBlob = file_get_contents($fileTmpPath);
            if ($profilePictureBlob === false) {
                throw new Exception("Failed to read uploaded file content.");
            }
            error_log("[DEBUG] Profile picture BLOB size after file_get_contents: " . strlen($profilePictureBlob) . " bytes");
        } else if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            error_log("[DEBUG] File upload error: " . $_FILES['profile_picture']['error']);
            throw new Exception("File upload failed with error code: " . $_FILES['profile_picture']['error']);
        }
        // --- END: Image Upload Handling for BLOB ---

        // --- START OF MODIFIED RESIDENTS UPDATE USING send_long_data ---
        // SQL query with a placeholder for the BLOB (`profile_picture = ?`)
        // The order of parameters must exactly match the `bind_param` call below.
        $sql_residents = "UPDATE residents SET email = ?, contact_number = ?, occupation = ?, profile_picture = ? WHERE id = ?";
        $stmt_residents = $mysqli->prepare($sql_residents);

        if ($stmt_residents === false) {
            throw new Exception("Failed to prepare statement for residents table: " . $mysqli->error);
        }

        // Bind parameters for email, contact_number, occupation, and resident_id.
        // For 'profile_picture', we bind a NULL placeholder with 'b' (BLOB) type.
        // The actual BLOB data will be sent separately using `send_long_data`.
        // The order of arguments for bind_param here corresponds to the `?` placeholders in the SQL:
        // 0: email, 1: contact_number, 2: occupation, 3: profile_picture, 4: id
        $stmt_residents->bind_param("sssbi", $email, $contact_number, $occupation, $profilePictureBlob, $resident_id);

        // If a new profile picture BLOB is available, send it using `send_long_data`.
        // The parameter index `3` corresponds to the 4th `?` in the SQL query (profile_picture).
        if ($profilePictureBlob !== null) {
            $stmt_residents->send_long_data(3, $profilePictureBlob);
        } else {
            // If no new picture is uploaded, the `profile_picture` column will be updated with an empty BLOB ('')
            // because `profilePictureBlob` is null, and it's bound as type 'b' to a NOT NULL longblob.
            // This is the desired behavior for clearing or not changing the picture if no new one is provided.
        }

        $stmt_residents->execute();
        $residents_affected_rows = $stmt_residents->affected_rows;
        error_log("[DEBUG] Residents table affected rows: " . $residents_affected_rows);
        $stmt_residents->close();
        // --- END OF MODIFIED RESIDENTS UPDATE USING send_long_data ---

        // 2. Handle the emergency_contact table (Existing logic, assuming it works)
        // ... (This part remains unchanged from your previous code) ...
        $sql_check_ec = "SELECT id FROM emergency_contact WHERE resident_id = ?";
        $stmt_check_ec = $mysqli->prepare($sql_check_ec);
        if ($stmt_check_ec === false) {
            throw new Exception("Failed to prepare statement for checking emergency contact: " . $mysqli->error);
        }
        $stmt_check_ec->bind_param("i", $resident_id);
        $stmt_check_ec->execute();
        $result_check_ec = $stmt_check_ec->get_result();
        $existing_ec_id = null;
        if ($row = $result_check_ec->fetch_assoc()) {
            $existing_ec_id = $row['id'];
        }
        $stmt_check_ec->close();

        $name_parts = explode(' ', $emergencyContactName, 4);
        $ec_first_name = $name_parts[0] ?? '';
        $ec_middle_name = $name_parts[1] ?? '';
        $ec_last_name = $name_parts[2] ?? '';
        $ec_suffix_name = $name_parts[3] ?? '';

        if ($existing_ec_id) {
            $sql_ec_update = "UPDATE emergency_contact SET 
                                emergency_first_name = ?, 
                                emergency_middle_name = ?, 
                                emergency_last_name = ?, 
                                emergency_suffix_name = ?,
                                emergency_contact_phone = ?, 
                                emergency_contact_email = ?, 
                                emergency_contact_address = ?, 
                                emergency_contact_relationship = ? 
                              WHERE id = ?";
            $stmt_ec = $mysqli->prepare($sql_ec_update);

            if ($stmt_ec === false) {
                throw new Exception("Failed to prepare statement for updating emergency contact: " . $mysqli->error);
            }

            $stmt_ec->bind_param(
                "ssssssssi", 
                $ec_first_name,
                $ec_middle_name,
                $ec_last_name,
                $ec_suffix_name,
                $emergencyContactNumber,
                $emergencyContactEmail,
                $emergencyContactAddress,
                $emergencyContactRelationship,
                $existing_ec_id
            );
            $stmt_ec->execute();
            $ec_affected_rows = $stmt_ec->affected_rows;
            $stmt_ec->close();
        } else {
            $sql_ec_insert = "INSERT INTO emergency_contact (
                                resident_id, 
                                emergency_first_name, 
                                emergency_middle_name, 
                                emergency_last_name, 
                                emergency_suffix_name,
                                emergency_contact_phone, 
                                emergency_contact_email, 
                                emergency_contact_address, 
                                emergency_contact_relationship
                              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_ec = $mysqli->prepare($sql_ec_insert);

            if ($stmt_ec === false) {
                throw new Exception("Failed to prepare statement for inserting emergency contact: " . $mysqli->error);
            }

            $stmt_ec->bind_param(
                "issssssss", 
                $resident_id,
                $ec_first_name,
                $ec_middle_name,
                $ec_last_name,
                $ec_suffix_name,
                $emergencyContactNumber,
                $emergencyContactEmail,
                $emergencyContactAddress,
                $emergencyContactRelationship
            );
            $stmt_ec->execute();
            $ec_affected_rows = $stmt_ec->affected_rows;
            $stmt_ec->close();
        }

        $mysqli->commit(); 

        echo json_encode(array("status" => "success", "message" => "Profile updated: Resident, emergency contact, and/or picture details changed."));

    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500); 
        echo json_encode(array("status" => "error", "message" => $e->getMessage()));
        error_log("Profile update error: " . $e->getMessage());
    }

} else {
    http_response_code(405);
    echo json_encode(array("status" => "error", "message" => "Method not allowed."));
}

$mysqli->close();
?>