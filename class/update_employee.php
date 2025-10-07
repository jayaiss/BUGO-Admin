<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();require_once '../include/encryption.php';
require_once '../include/redirects.php'; 

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input data
    $employee_id = filter_var($_POST['employee_id'], FILTER_SANITIZE_NUMBER_INT);
    $first_name = filter_var($_POST['first_name'], FILTER_SANITIZE_STRING);
    $middle_name = filter_var($_POST['middle_name'], FILTER_SANITIZE_STRING);
    $last_name = filter_var($_POST['last_name'], FILTER_SANITIZE_STRING);
    $birth_date = filter_var($_POST['birth_date'], FILTER_SANITIZE_STRING);
    $birth_place = filter_var($_POST['birth_place'], FILTER_SANITIZE_STRING);
    $gender = filter_var($_POST['gender'], FILTER_SANITIZE_STRING);
    $contact_number = filter_var($_POST['contact_number'], FILTER_SANITIZE_NUMBER_INT);
    $civil_status = filter_var($_POST['civil_status'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $zone = filter_var($_POST['zone'], FILTER_SANITIZE_STRING);
    $citizenship = filter_var($_POST['citizenship'], FILTER_SANITIZE_STRING);
    $religion = filter_var($_POST['religion'], FILTER_SANITIZE_STRING);
    $term = filter_var($_POST['term'], FILTER_SANITIZE_STRING);

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($birth_date) || empty($email)) {
        echo "All required fields must be filled out.";
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid email format.";
        exit;
    }

    // Validate contact number (only digits, 10-15 characters)
    if (!preg_match("/^\d{10,15}$/", $contact_number)) {
        echo "Invalid contact number. It should contain only numbers and be between 10-15 digits.";
        exit;
    }

    // Validate birth date format (assuming format is YYYY-MM-DD)
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $birth_date)) {
        echo "Invalid birth date format. Use YYYY-MM-DD.";
        exit;
    }

    // Prepare SQL query to update the employee details
    $sql = "UPDATE employee_list SET 
                employee_fname = ?, 
                employee_mname = ?, 
                employee_lname = ?, 
                employee_birth_date = ?, 
                employee_birth_place = ?, 
                employee_gender = ?, 
                employee_contact_number = ?, 
                employee_civil_status = ?, 
                employee_email = ?, 
                employee_zone = ?, 
                employee_citizenship = ?, 
                employee_religion = ?, 
                employee_term = ? 
            WHERE employee_id = ?";

    // Prepare the query
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("sssssssssssssi", 
            $first_name, 
            $middle_name, 
            $last_name, 
            $birth_date, 
            $birth_place, 
            $gender, 
            $contact_number, 
            $civil_status, 
            $email, 
            $zone, 
            $citizenship, 
            $religion, 
            $term, 
            $employee_id
        );
        
        // Execute the query
        if ($stmt->execute()) {
            // If the update is successful, redirect to the 'official_info' page
               echo "<script>
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Role added successfully!',
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            window.location.href = '{$redirects['officials_api']}';
                        });
                    </script>";
            exit(); // Ensure the script stops after the redirect
        } else {
            // Handle error (in case of failure)
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed!',
                        text: 'Error: Error updating employee',
                        confirmButtonColor: '#d33'
                    });
                </script>";
        }
        // Close the prepared statement
        $stmt->close();
    } else {
        // If the SQL query fails to prepare
        echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed!',
                        text: 'Error: Error!',
                        confirmButtonColor: '#d33'
                    });
                </script>";
    }
}

// Close the database connection
$mysqli->close();
?>
