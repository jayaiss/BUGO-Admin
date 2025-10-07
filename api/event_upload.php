<?php

session_start(); // Start session

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../include/encryption.php';
require_once '../include/redirects.php';
include_once '../logs/logs_trig.php';

// include 'class/session_timeout.php';
function validateInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}
// Role check: Only allow Admin or Multimedia
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin', 'Multimedia'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// Set correct redirect URL based on role
if ($role === 'Admin') {
    $resbaseUrl = enc_admin('event_list');
} elseif ($role === 'Multimedia') {
    $resbaseUrl = enc_multimedia('event_list');
} else {
    $resbaseUrl = '../index.php'; // Fallback
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = validateInput($_POST['event_name']);
    $description = validateInput($_POST['description']);
    $date = $_POST['date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $location = validateInput($_POST['location']);
    $emp_id = $_SESSION['employee_id'] ?? 1;
    $trigs = new Trigger();


    $imageData = null;
    $imageType = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
        $imageType = $_FILES['image']['type'];
    }

    // Insert or find event name
    if ($title === 'other' && !empty($_POST['new_event_name'])) {
        $newTitle = trim($_POST['new_event_name']);
        $stmt = $mysqli->prepare("SELECT id FROM event_name WHERE event_name = ?");
        $stmt->bind_param("s", $newTitle);
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($eventNameId);
            $stmt->fetch();
        } else {
            $insert = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?, 1, ?)");
            $insert->bind_param("si", $newTitle, $emp_id);
            $insert->execute();
            $eventNameId = $insert->insert_id;
                $eventId = $mysqli->insert_id;
                $trigs->isAdded(11, $eventId);
        }
        $stmt->close();

        echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            icon: 'success',
            title: '✅ Success!',
            text: 'Event successfully added.',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$resbaseUrl'; // Go back to your UI
        });
    });
    </script>";
        exit;
    } else {
        $eventNameId = (int) $title;
    }

    // Insert into events table
    $stmt = $mysqli->prepare("INSERT INTO events (emp_id, event_title, event_description, event_location, event_time, event_end_time, event_date, event_image, image_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssss", $emp_id, $eventNameId, $description, $location, $start_time, $end_time, $date, $imageData, $imageType);
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
if ($stmt->execute()) {
    $eventId = $mysqli->insert_id;
    $trigs->isAdded(11, $eventId);
    echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            icon: 'success',
            title: '✅ Success!',
            text: 'Event successfully added.',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$resbaseUrl'; // Go back to your UI
        });
    });
    </script>";
} else {
    $errorMsg = addslashes($stmt->error); // prevent breaking quotes
    echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                icon: 'error',
                title: '❌ Error',
                text: 'Something went wrong: $errorMsg',
                confirmButtonColor: '#d33'
            }).then(() => {
                window.history.back(); // Go back to form
            });
        });
    </script>";
}

    $stmt->close();
    $mysqli->close();
}
?>
