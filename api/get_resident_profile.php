<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Include your database connection file
include_once '../include/connection.php';

// Function to sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Ensure it's a GET request for fetching data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $resident_id = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : 0;

    if ($resident_id <= 0) {
        http_response_code(400);
        echo json_encode(array("status" => "error", "message" => "Missing or invalid resident ID."));
        exit();
    }

    $sql = "SELECT
                r.id,
                r.username,
                r.first_name,
                r.middle_name,
                r.last_name,
                r.suffix_name,
                r.gender,
                r.civil_status,
                r.birth_date,
                r.age,
                r.birth_place,
                r.res_zone,
                r.res_street_address,
                r.residency_start,
                r.contact_number,
                r.email,
                r.citizenship,
                r.religion,
                r.occupation,
                r.profile_picture, -- ADDED: Select the profile_picture BLOB
                ec.emergency_first_name,
                ec.emergency_middle_name,
                ec.emergency_last_name,
                ec.emergency_suffix_name,
                ec.emergency_contact_phone,
                ec.emergency_contact_email,
                ec.emergency_contact_address,
                ec.emergency_contact_relationship
            FROM
                residents r
            LEFT JOIN
                emergency_contact ec ON r.id = ec.resident_id
            WHERE
                r.id = ?";

    $stmt = $mysqli->prepare($sql);

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(array("status" => "error", "message" => "Failed to prepare statement: " . $mysqli->error));
        exit();
    }

    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $resident_data = $result->fetch_assoc();

        // Base64 encode the profile_picture BLOB data
        if (isset($resident_data['profile_picture']) && $resident_data['profile_picture'] !== null) {
            $resident_data['profile_picture_base64'] = base64_encode($resident_data['profile_picture']);
        } else {
            $resident_data['profile_picture_base64'] = null; // No image, or empty BLOB
        }
        // Unset the original binary data to avoid issues with json_encode
        unset($resident_data['profile_picture']);

        // Dynamically create the full emergency contact name for Flutter
        $emergencyContactName = '';
        if (isset($resident_data['emergency_first_name'])) {
            $emergencyContactName .= $resident_data['emergency_first_name'];
            if (!empty($resident_data['emergency_middle_name'])) {
                $emergencyContactName .= ' ' . $resident_data['emergency_middle_name'];
            }
            if (!empty($resident_data['emergency_last_name'])) {
                $emergencyContactName .= ' ' . $resident_data['emergency_last_name'];
            }
            if (!empty($resident_data['emergency_suffix_name'])) {
                $emergencyContactName .= ' ' . $resident_data['emergency_suffix_name'];
            }
        }
        
        $resident_data['emergency_contact_name'] = $emergencyContactName;
        $resident_data['emergency_contact_relationship'] = $resident_data['emergency_contact_relationship'] ?? '';
        $resident_data['emergency_contact_number'] = $resident_data['emergency_contact_phone'] ?? '';
        $resident_data['emergency_contact_email'] = $resident_data['emergency_contact_email'] ?? '';
        $resident_data['emergency_contact_address'] = $resident_data['emergency_contact_address'] ?? '';

        unset($resident_data['emergency_first_name']);
        unset($resident_data['emergency_middle_name']);
        unset($resident_data['emergency_last_name']);
        unset($resident_data['emergency_suffix_name']);
        unset($resident_data['emergency_contact_phone']);

        echo json_encode(array("status" => "success", "data" => $resident_data));
    } else {
        http_response_code(404);
        echo json_encode(array("status" => "error", "message" => "Resident not found."));
    }

    $stmt->close();
    $mysqli->close();

} else {
    http_response_code(405);
    echo json_encode(array("status" => "error", "message" => "Invalid request method. Only GET is allowed."));
}
?>