<?php
session_start(); // <-- ADD THIS LINE HERE, IT MUST BE THE VERY FIRST THING
// FILE: bugo/api/schedule_appointment.php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow all origins for development
header('Access-Control-Allow-Methods: POST'); // This script should only accept POST requests
header('Access-Control-Allow-Headers: Content-Type'); // Allow Content-Type header for JSON

// Include your database connection file
include_once '../include/connection.php'; // Adjust path if necessary

// Function to generate a unique tracking number with a specific prefix
function generateTrackingNumber($prefix) {
    return $prefix . strtoupper(uniqid()); // Prefix plus a unique ID
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $json_data = file_get_contents("php://input");
    $data = json_decode($json_data, true);

    // Validate incoming JSON payload
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(array("success" => false, "message" => "Invalid JSON payload."));
        exit();
    }

    // Extract common data
    $resident_id = $data['resident_id'] ?? null;
    $schedule_date = $data['selected_date'] ?? null;
    $schedule_time = $data['selected_time'] ?? null;
    $certificate = $data['certificate'] ?? null;
    $purpose = $data['purpose'] ?? null;
    $additional_details = $data['additional_details'] ?? null;

    // Validate that all common required data is present
    if (!$resident_id || !$schedule_date || !$schedule_time || !$certificate || !$purpose) {
        http_response_code(400); // Bad Request
        echo json_encode(array("success" => false, "message" => "Missing required common appointment details (resident_id, selected_date, selected_time, certificate, purpose)."));
        exit();
    }
    
    // Convert certificate to lowercase for consistent checking
    $certificate_lower = strtolower($certificate);

    // Start transaction to ensure atomicity
    $mysqli->begin_transaction();

    $generated_tracking_number = ''; // Initialize tracking number
    $inserted_id = 0; // Initialize inserted ID

    try {
        if ($certificate_lower === 'cedula') {
            // --- Handle Cedula specific insertion (ONLY into cedula table) ---
            $cedulaMode = $data['cedula_mode'] ?? null; 
            $income = $data['income'] ?? null;
            
            if (!$cedulaMode || !$income) {
                throw new Exception("Missing required Cedula mode ('cedula_mode') or income details for Cedula appointment.");
            }

            // Generate a unique tracking number for the cedula table
            $generated_tracking_number = generateTrackingNumber('CEDULA-'); 

            if ($cedulaMode === 'request') {
                $sql_cedula = "INSERT INTO cedula (res_id, income, appointment_date, appointment_time, tracking_number, cedula_status) VALUES (?, ?, ?, ?, ?, 'Pending')";
                $stmt_cedula = $mysqli->prepare($sql_cedula);
                if ($stmt_cedula === false) {
                    throw new Exception("Failed to prepare cedula (request) statement: " . $mysqli->error);
                }
                $stmt_cedula->bind_param("idsss", $resident_id, $income, $schedule_date, $schedule_time, $generated_tracking_number);
            } elseif ($cedulaMode === 'upload') {
                $cedula_number = $data['cedula_number'] ?? null;
                $issued_at = $data['issued_at'] ?? null;
                $issued_on = $data['issued_on'] ?? null;
                $cedula_image_base64 = $data['cedula_image_base64'] ?? null;

                if (!$cedula_number || !$issued_at || !$issued_on || !$cedula_image_base64) {
                    throw new Exception("Missing required Cedula upload details (cedula_number, issued_at, issued_on, cedula_image_base64).");
                }
                
                $sql_cedula = "INSERT INTO cedula (res_id, income, appointment_date, appointment_time, tracking_number, cedula_status, cedula_number, issued_at, issued_on, cedula_img) VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?)";
                $stmt_cedula = $mysqli->prepare($sql_cedula);
                if ($stmt_cedula === false) {
                    throw new Exception("Failed to prepare cedula (upload) statement: " . $mysqli->error);
                }
                $stmt_cedula->bind_param("idssssssss", $resident_id, $income, $schedule_date, $schedule_time, $generated_tracking_number, $cedula_number, $issued_at, $issued_on, $cedula_image_base64);
            } else {
                throw new Exception("Invalid Cedula mode provided: '$cedulaMode'. Expected 'request' or 'upload'.");
            }

            if (!$stmt_cedula->execute()) {
                throw new Exception("Failed to schedule appointment in cedula table: " . $stmt_cedula->error);
            }
            $inserted_id = $mysqli->insert_id; // Get the ID from cedula table
            $stmt_cedula->close();

        } else {
            // --- Handle other certificates (ONLY insert into schedules table) ---
            // Generate a unique tracking number for the schedules table
            $generated_tracking_number = generateTrackingNumber('BRGYTRK');

            $sql_schedule = "INSERT INTO schedules (res_id, selected_date, selected_time, certificate, purpose, tracking_number, status, additional_details) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)";
            $stmt_schedule = $mysqli->prepare($sql_schedule);
            if ($stmt_schedule === false) {
                throw new Exception("Failed to prepare schedules statement: " . $mysqli->error);
            }
            $stmt_schedule->bind_param("issssss", $resident_id, $schedule_date, $schedule_time, $certificate, $purpose, $generated_tracking_number, $additional_details);
            
            if (!$stmt_schedule->execute()) {
                throw new Exception("Failed to schedule appointment in schedules table: " . $stmt_schedule->error);
            }
            $inserted_id = $mysqli->insert_id; // Get the ID from schedules table
            $stmt_schedule->close();
        }

        // If all inserts are successful, commit the transaction
        $mysqli->commit();

        echo json_encode(array(
            "success" => true,
            "message" => "Appointment scheduled successfully!",
            "tracking_number" => $generated_tracking_number,
            "appointment_id" => $inserted_id // This will be the ID from either cedula or schedules table
        ));

    } catch (Exception $e) {
        // If any error occurs, rollback the transaction
        $mysqli->rollback();
        http_response_code(500); // Internal Server Error
        echo json_encode(array("success" => false, "message" => $e->getMessage()));
    } finally {
        $mysqli->close();
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(array("success" => false, "message" => "Method not allowed. Use POST."));
}
?>