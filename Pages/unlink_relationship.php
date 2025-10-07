<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            require_once __DIR__ . '/../security/500.html';
            exit();
        }
    });
require_once './include/redirects.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child_id = $_POST['related_resident_id'];
    $relationship_type = $_POST['relationship_type'];

    // Delete the specific relationship type for this child
    $stmt = $mysqli->prepare("DELETE FROM resident_relationships WHERE related_resident_id = ? AND relationship_type = ?");
    $stmt->bind_param("is", $child_id, $relationship_type);


if ($stmt->execute()) {
$user_role = strtolower($_SESSION['Role_Name'] ?? '');

// Determine link base URL based on role
if ($user_role === 'encoder') {
    $linkbaseUrl = enc_encoder('resident_info');    
} elseif ($user_role === 'admin') {
    $linkbaseUrl = enc_admin('resident_info');
} else {
    $linkbaseUrl = '#'; // fallback to prevent empty href
}
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Relationship Unlinked Successfully.',
        confirmButtonColor: '#3085d6'
    }).then(() => {
        window.location.href = '$linkbaseUrl';
    });
</script>";

}
else {
        echo "<script>
            alert('Failed to unlink relationship.');
            history.back();
        </script>";
    }
}
