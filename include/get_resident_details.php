<?php
// Include database connection
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

if (isset($_GET['id'])) {
    $id = $_GET['id'];  // Get the resident ID from the URL

    // SQL query to fetch detailed data for the resident
    $sql = "SELECT *, CONCAT(first_name, ' ', middle_name, ' ', last_name, ' ', suffix_name) AS full_name 
            FROM residents 
            WHERE id = ?";
    
    // Prepare and execute the query
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $id);  // Bind the resident ID to the query
    $stmt->execute();
    $result = $stmt->get_result();  // Execute the query and get the result

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();  // Fetch the row containing resident details

        // Calculate the age dynamically based on the birth date
        $birth_date = $row["birth_date"];
        $age = null;
        if ($birth_date) {
            $birth_date_obj = new DateTime($birth_date);
            $today = new DateTime();
            $age = $today->diff($birth_date_obj)->y;  // Calculate the age by difference in years
        }

        // Create an array with the resident's details
        $resident = array(
            'id' => $row["id"],
            'full_name' => $row["full_name"],
            'gender' => $row["gender"],
            'birth_date' => $row["birth_date"],
            'age' => $age,  // Dynamically calculated age
            'civil_status' => $row["civil_status"],
            'contact_number' => $row["contact_number"],
            'email' => $row["email"],
            'citizenship' => $row["citizenship"],
            'religion' => $row["religion"],
            'occupation' => $row["occupation"],
            'birth_place' => $row["birth_place"],
            'res_zone' => $row["res_zone"],
            'res_street_address' => $row["res_street_address"]
        );

        // Return the data as a JSON response
        echo json_encode($resident);
    } else {
        // If no details are found, return an error message
        echo json_encode(array('error' => 'No details found.'));
    }
}

$mysqli->close();  // Close the database connection
?>
