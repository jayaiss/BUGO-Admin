<?php
// This line tells the browser that the content we are sending back is in JSON format.
header('Content-Type: application/json');

// This line is for development purposes to allow requests from any website or app.
// In a real production environment, you should restrict this to your Flutter app's origin for security.
header('Access-Control-Allow-Origin: *');

// This line specifies that this API endpoint should only accept POST requests (for sending login data).
header('Access-Control-Allow-Methods: POST');

// Include the file that contains the code to connect to your MySQL database.
// Based on your file structure, this file seems to be in the 'include' folder.
// Adjust the path below if your 'connection.php' file is in a different location.
include_once '../include/connection.php';

// Check if the request method is POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the data sent from the Flutter app. 'php://input' reads the raw data from the request body.
    // 'json_decode' converts the JSON data into a PHP associative array (like a dictionary).
    $data = json_decode(file_get_contents("php://input"), true);

    // Check if both 'username' and 'password' were sent in the JSON data.
    if (isset($data['username']) && isset($data['password'])) {
        $username = $data['username'];
        $password = $data['password'];

        // Prepare a SQL query to select the resident's ID, first name, last name, hashed password,
        // and the res_pass_change status from the 'residents' table.
        // [MODIFIED] Added res_pass_change to the SELECT query
        $stmt = $mysqli->prepare("SELECT id, first_name, last_name, password, res_pass_change FROM residents WHERE username = ?");

        // Bind the username parameter to the prepared statement. This helps prevent SQL injection.
        $stmt->bind_param("s", $username);

        // Execute the prepared query.
        $stmt->execute();

        // Get the result of the query.
        $result = $stmt->get_result();

        // Check if exactly one row was found (meaning a user with that username exists).
        if ($result->num_rows === 1) {
            // Fetch the data from the result row as an associative array.
            $row = $result->fetch_assoc();
            $resident_id = $row['id'];
            $first_name = $row['first_name'];
            $last_name = $row['last_name'];
            $hashed_password = $row['password'];
            // [MODIFIED] Get the res_pass_change status
            $res_pass_change = $row['res_pass_change'];

            // Verify if the entered password matches the hashed password from the database.
            // 'password_verify()' is a secure way to compare hashed passwords.
            if (password_verify($password, $hashed_password)) {
                // Login successful!
                // Create an array with the success status and user information to send back as JSON.
                // [MODIFIED] Added res_pass_change to the JSON response
                echo json_encode(array(
                    "status" => "success",
                    "resident_id" => $resident_id,
                    "first_name" => $first_name,
                    "last_name" => $last_name,
                    "res_pass_change" => $res_pass_change, // Include the status
                    "message" => "Login successful"
                ));
            } else {
                // Incorrect password.
                // Set the HTTP status code to 401 (Unauthorized).
                http_response_code(401);
                // Send back a JSON response indicating an error.
                echo json_encode(array("status" => "error", "message" => "Incorrect password"));
            }
        } else {
            // User with the provided username not found.
            // Set the HTTP status code to 401 (Unauthorized).
            http_response_code(401);
            // Send back a JSON response indicating an error.
            echo json_encode(array("status" => "error", "message" => "User not found"));
        }

        // Close the prepared statement and the database connection to free up resources.
        $stmt->close();
        $mysqli->close(); // Ensure $mysqli is closed only once after all operations
    } else {
        // If the 'username' or 'password' fields are missing in the request.
        // Set the HTTP status code to 400 (Bad Request).
        http_response_code(400);
        // Send back a JSON response indicating missing credentials.
        echo json_encode(array("status" => "error", "message" => "Missing username or password"));
    }
} else {
    // If the request method is not POST (e.g., if someone tries to access this URL with a GET request).
    // Set the HTTP status code to 405 (Method Not Allowed).
    http_response_code(405);
    // Send back a JSON response indicating that only POST requests are allowed.
    echo json_encode(array("status" => "error", "message" => "Method not allowed. Use POST."));
}
?>