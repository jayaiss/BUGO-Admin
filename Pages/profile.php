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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once './include/encryption.php';
require_once './logs/logs_trig.php'; // ✅ Include the Trigger class

$loggedInUsername = $_SESSION['username'] ?? '';
if (!$loggedInUsername) {
    header("Location: ../index.php");
    exit();
}

$success = $error = null;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newEmail = $_POST['email'] ?? '';              // optional; blank means "remove"
    $newCivilStatus = $_POST['civil_status'] ?? '';
    $hasImage = isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK;

    // Email is OPTIONAL: only validate if provided (non-empty)
    if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($hasImage && !in_array($_FILES['profile_picture']['type'], ['image/jpeg', 'image/png', 'image/jpg'])) {
        $error = "Invalid image type. Only JPG and PNG are allowed.";
    } else {
        // 🔍 Get employee_id from username
        $stmtID = $mysqli->prepare("SELECT employee_id FROM employee_list WHERE employee_username = ?");
        $stmtID->bind_param("s", $loggedInUsername);
        $stmtID->execute();
        $stmtID->bind_result($employee_id);
        $stmtID->fetch();
        $stmtID->close();

        // 🔍 Fetch old data before update (for audit)
        $trigger = new Trigger();
        $oldData = $trigger->getOldAndNewData($employee_id, 1); // 1 = employee table

        // 👤 Update the profile
        if ($hasImage) {
            $imageData = file_get_contents($_FILES['profile_picture']['tmp_name']);

            // If employee_email allows NULL, this will clear it when input is blank:
            $stmt = $mysqli->prepare("
                UPDATE employee_list
                SET
                    employee_email = NULLIF(?, ''),    -- blank => NULL, value => value
                    employee_civil_status = ?,
                    profilePicture = ?
                WHERE employee_username = ?
            ");
            // NOTE: If employee_email is NOT NULL in schema, use this instead:
            // employee_email = CASE WHEN ? = '' THEN '' ELSE ? END
            // and bind: $stmt->bind_param('sssss', $newEmail, $newCivilStatus, $imageData, $loggedInUsername);
            $stmt->bind_param("ssss", $newEmail, $newCivilStatus, $imageData, $loggedInUsername);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE employee_list
                SET
                    employee_email = NULLIF(?, ''),    -- blank => NULL, value => value
                    employee_civil_status = ?
                WHERE employee_username = ?
            ");
            // If NOT NULL column: use CASE WHEN ? = '' THEN '' ELSE ? END and bind twice for the email.
            $stmt->bind_param("sss", $newEmail, $newCivilStatus, $loggedInUsername);
        }

        if ($stmt->execute()) {
            // ✅ Audit log for profile update
            $trigger->isEdit(1, $employee_id, $oldData);
            $success = "Profile updated successfully.";
        } else {
            $error = "Update failed. Please try again.";
        }
        $stmt->close();
    }
}

// Re-fetch latest data
$stmt = $mysqli->prepare("
    SELECT employee_fname, employee_mname, employee_lname, employee_sname,
           employee_email, employee_civil_status, profilePicture
    FROM employee_list
    WHERE employee_username = ?
");
$stmt->bind_param("s", $loggedInUsername);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .profile-box {
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            background-color: #fff;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .profile-image {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="bg-light">

<div class="profile-box">
    <h4 class="fw-bold text-center mb-4">My Profile</h4>

    <form method="POST" enctype="multipart/form-data">
        <div class="text-center mb-4">
            <?php if (!empty($employee['profilePicture'])): ?>
                <img src="data:image/jpeg;base64,<?= base64_encode($employee['profilePicture']) ?>" class="profile-image" alt="Profile Picture">
                <h5 class="fw-bold mt-2 text-center">
                    <?= htmlspecialchars($employee['employee_fname'] . ' ' . $employee['employee_mname'] . ' ' . $employee['employee_lname'] . ' ' . $employee['employee_sname']) ?>
                </h5>
            <?php else: ?>
                <i class="fas fa-user-circle text-secondary" style="font-size: 120px;"></i>
            <?php endif; ?>
            <br><br>
            <div class="mt-2">
                <input type="file" name="profile_picture" class="form-control form-control-sm" accept="image/*">
            </div>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email Address (optional)</label>
            <input
                type="email"
                name="email"
                id="email"
                class="form-control"
                value="<?= htmlspecialchars($employee['employee_email'] ?? '') ?>"
                placeholder="Leave blank to remove email"
            >
        </div>

        <div class="mb-3">
            <label for="civil_status" class="form-label">Civil Status</label>
            <select name="civil_status" id="civil_status" class="form-select" required>
                <option value="">-- Select --</option>
                <option value="Single"   <?= ($employee['employee_civil_status'] === 'Single') ? 'selected' : '' ?>>Single</option>
                <option value="Married"  <?= ($employee['employee_civil_status'] === 'Married') ? 'selected' : '' ?>>Married</option>
                <option value="Widowed"  <?= ($employee['employee_civil_status'] === 'Widowed') ? 'selected' : '' ?>>Widowed</option>
                <option value="Separated"<?= ($employee['employee_civil_status'] === 'Separated') ? 'selected' : '' ?>>Separated</option>
            </select>
        </div>

        <div class="d-grid">
            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
        </div>
    </form>
</div>

<?php if (!empty($success)): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($success) ?>,
    confirmButtonColor: '#3085d6'
});
</script>
<?php elseif (!empty($error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#d33'
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
