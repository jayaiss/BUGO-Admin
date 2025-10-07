<?php
// require_once __DIR__ . '/include/connection.php';
// $mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';

$trigger = new Trigger();
$employee_id = $_SESSION['employee_id'] ?? null;

// 🔁 Handle Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_announcement'])) {
    $id = intval($_POST['announcement_id']);
    $details = trim($_POST['announcement_details']);
    $oldData = ['announcement_details' => $_POST['original_announcement_details'] ?? ''];

    $stmt = $mysqli->prepare("UPDATE announcement SET announcement_details = ? WHERE Id = ?");
    $stmt->bind_param("si", $details, $id);

    if ($stmt->execute()) {
        $trigger->isEdit(29, $id, $oldData);
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Updated',
            text: 'Announcement updated successfully.'
        }).then(() => {
            window.location.href = 'index_Admin.php?page=" . urlencode(encrypt('announcements')) . "';
        });
        </script>";
        exit;
    }
}

// 🗑 Handle Delete
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $stmt = $mysqli->prepare("UPDATE announcement SET delete_status = 1 WHERE Id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $trigger->isDelete(29, $id);
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Deleted',
            text: 'Announcement archived successfully.'
        }).then(() => {
            window.location.href = 'index_Admin.php?page=" . urlencode(encrypt('announcements')) . "';
        });
        </script>";
        exit;
    }
}


$search = $_GET['search'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

$conditions = ['delete_status = 0'];
$params = [];
$types = '';

// Fix for search (announcement_details)
if (isset($_GET['search']) && $_GET['search'] !== '') {
    $conditions[] = "announcement_details LIKE CONCAT('%', ?, '%')";
    $params[] = $_GET['search'];
    $types .= 's';
}

// Fix for month
if (isset($_GET['month']) && $_GET['month'] !== '') {
    $conditions[] = "MONTH(created) = ?";
    $params[] = (int)$_GET['month'];
    $types .= 'i';
}

// Fix for year
if (isset($_GET['year']) && $_GET['year'] !== '') {
    $conditions[] = "YEAR(created) = ?";
    $params[] = (int)$_GET['year'];
    $types .= 'i';
}


$whereClause = implode(' AND ', $conditions);

// 📄 Pagination
$page = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? (int)$_GET['pagenum'] : 1;
$results_per_page = 20;
$offset = ($page - 1) * $results_per_page;

// 🔢 Count total
$countQuery = "SELECT COUNT(*) AS total FROM announcement WHERE $whereClause";
$countStmt = $mysqli->prepare($countQuery);
if (!$countStmt) {
    die("Prepare failed: " . $mysqli->error);
}
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$total_rows = $countResult->fetch_assoc()['total'];
$countStmt->close();
$total_pages = ceil($total_rows / $results_per_page);

$fetchQuery = "SELECT * FROM announcement WHERE $whereClause ORDER BY created DESC LIMIT ? OFFSET ?";
$typesWithPagination = $types . 'ii';
$paramsWithPagination = [...$params, $results_per_page, $offset];
$stmt = $mysqli->prepare($fetchQuery);
if (!$stmt) {
    die("Prepare failed: " . $mysqli->error);
}
$stmt->bind_param($typesWithPagination, ...$paramsWithPagination);
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}
$result = $stmt->get_result();


// 🔗 Preserve filters for pagination
$queryParams = $_GET;
unset($queryParams['pagenum'], $queryParams['delete_id']);
$queryString = http_build_query($queryParams);
?>
