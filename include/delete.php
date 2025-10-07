<?php
// Include the connection file to establish the database connection
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once 'encryption.php';
require_once 'redirects.php'; 

class soft_delete {

    // Method to delete a resident
    public function delete_resident($resident_id, $redirects) {
        global $mysqli; // Use the global variable $mysqli from connection.php
        session_start();
        require_once '../logs/logs_trig.php';
        $trigger = new Trigger();

        // Check if the connection is successful
        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        // Ensure that the resident_id is valid
        if ($resident_id === NULL || $resident_id === '') {
            die("Resident ID is not set or empty.");
        }

        // SQL query to update the soft delete status in the residents table
        $stmt = $mysqli->prepare("UPDATE `residents` SET `resident_delete_status` = 1 WHERE id = ?");
        $stmt->bind_param("i", $resident_id);

        if ($stmt->execute() && $mysqli->affected_rows > 0) {
             $trigger->isDelete(2, $resident_id);
            $role = $_SESSION['Role_Name'] ?? '';
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";

if ($role === 'Barangay Secretary') {
    $linkbaseUrl = enc_brgysec('resident_info');
} elseif ($role === 'Encoder') {
    $linkbaseUrl = enc_encoder('resident_info');
} elseif ($role === 'Admin') {
    $linkbaseUrl = enc_admin('resident_info');
}

if (isset($linkbaseUrl)) {
    echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: 'Resident deleted successfully.',
                    confirmButtonColor: '#3085d6'
                }).then(() => {
                    window.location.href = '$linkbaseUrl';
                });
            });
          </script>";
}
 else {
                echo "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: 'Resident deleted successfully.',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                window.location.href = '{$redirects['residents_api']}';
                            });
                        });
                      </script>";
            }
        } else {
            echo "Error: " . $stmt->error;
        }
    }

    // Method to delete an employee
    public function delete_employee($employee_id, $redirects) {
        global $mysqli;
        require_once '../logs/logs_trig.php';
        $trigger = new Trigger();

        if ($employee_id === NULL || $employee_id === '') {
            die("Employee ID is not set or empty.");
        }

        $stmt = $mysqli->prepare("UPDATE `employee_list` SET `employee_delete_status` = 1 WHERE employee_id = ?");
        $stmt->bind_param("i", $employee_id);

        if ($stmt->execute() && $mysqli->affected_rows > 0) {
            $trigger->isDelete(1, $employee_id);
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Employee deleted successfully.',
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            window.location.href = '{$redirects['officials_api']}';
                        });
                    });
                </script>";
        } else {
            echo "Error: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>
