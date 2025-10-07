<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}     // Still report them in logs
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../include/redirects.php';
header('Content-Type: text/html; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child_id = $_POST['child_id'];
    $parent_id = $_POST['parent_id'];
    $type = $_POST['relationship_type'];

    // ❌ Prevent self-linking
    if ($child_id == $parent_id) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Invalid Link',
  text: 'A person cannot be their own parent.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

    }

    // ✅ Age validation (minimum 12-year gap)
    $childRes = $mysqli->query("SELECT birth_date FROM residents WHERE id = $child_id");
    $parentRes = $mysqli->query("SELECT birth_date FROM residents WHERE id = $parent_id");

    if ($childRes && $parentRes && $childRes->num_rows && $parentRes->num_rows) {
        $childDOB = new DateTime($childRes->fetch_assoc()['birth_date']);
        $parentDOB = new DateTime($parentRes->fetch_assoc()['birth_date']);
        $gap = $parentDOB->diff($childDOB)->y;

        if ($gap < 12) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'warning',
  title: 'Invalid Relationship',
  text: 'Age gap must be at least 12 years.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

        }
    }

    // ✅ Check for existing same relationship type
    $check = $mysqli->prepare("
        SELECT r.first_name, r.middle_name, r.last_name 
        FROM resident_relationships rr
        JOIN residents r ON rr.resident_id = r.id
        WHERE rr.related_resident_id = ? AND rr.relationship_type = ?
    ");
    $check->bind_param("is", $child_id, $type);
    $check->execute();
    $existing = $check->get_result();

    if ($existing->num_rows > 0) {
        $e = $existing->fetch_assoc();
        $fullName = trim("{$e['first_name']} {$e['middle_name']} {$e['last_name']}");
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'info',
  title: 'Relationship Exists',
  text: 'This child is already linked to a $type: $fullName.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

    }

    // ✅ Handle file upload (birth certificate)
    $certificate = null;
    if (isset($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['birth_certificate']['tmp_name'];
        $fileType = mime_content_type($fileTmp);

        // Only accept image or PDF
        if (!in_array($fileType, ['image/jpeg', 'image/png'])) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Invalid File Type',
  text: 'Only JPG, and PNG files are allowed.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;
        }

        $certificate = file_get_contents($fileTmp);
    }

    // ✅ Insert into database
    $stmt = $mysqli->prepare("
        INSERT INTO resident_relationships (related_resident_id, resident_id, relationship_type, id_birthcertificate, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("iisb", $child_id, $parent_id, $type, $null);
    $stmt->send_long_data(3, $certificate);

    if ($stmt->execute()) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'success',
  title: 'Success',
  text: 'Relationship linked successfully! Status is now pending.',
  confirmButtonText: 'OK'
}).then(() => {
  window.location.href = '{$redirects['residents_api']}';
});
</script>";

    } else {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Link Failed',
  text: 'Failed to link relationship.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
    }
}
