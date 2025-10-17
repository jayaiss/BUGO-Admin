<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Block direct access
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';
$trigger = new Trigger();

// --------- Role-based base URL ---------
$role = strtolower($_SESSION['Role_Name'] ?? '');
switch ($role) {
  case 'admin': $baseUrl = enc_admin('beso'); break;
  case 'beso':  $baseUrl = enc_beso('beso');  break;
  default:      $baseUrl = 'index.php';
}

// --------- Edit handler ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_beso'])) {
    $id        = (int)($_POST['beso_id'] ?? 0);
    $education = trim($_POST['education_attainment'] ?? '');
    $course    = trim($_POST['course'] ?? '');

    $stmt = $mysqli->prepare("UPDATE beso SET education_attainment = ?, course = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ssi", $education, $course, $id);
        if ($stmt->execute()) {
            // Log edit (old values passed from form)
            $oldData = [
                'education_attainment' => $_POST['original_education_attainment'] ?? '',
                'course'               => $_POST['original_course'] ?? ''
            ];
            $trigger->isEdit(28, $id, $oldData);

            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                  <script>
                    Swal.fire({icon:'success',title:'Updated!',text:'BESO entry updated successfully.'})
                        .then(()=>{ window.location.href = '{$baseUrl}';});
                  </script>";
            exit;
        } else {
            error_log('SQL error (update): ' . $stmt->error);
        }
        $stmt->close();
    }
}

// --------- Soft delete handler ---------
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $mysqli->prepare("UPDATE beso SET beso_delete_status = 1 WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $trigger->isDelete(28, $delete_id);
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                  <script>
                    Swal.fire({icon:'success',title:'Deleted!',text:'Record successfully archived.'})
                        .then(()=>{ window.location.href = '{$baseUrl}';});
                  </script>";
            exit;
        } else {
            error_log('SQL error (delete): ' . $stmt->error);
        }
        $stmt->close();
    }
}

// --------- Filters (adapted to BESO columns) ---------
$search = trim($_GET['search'] ?? '');
$month  = $_GET['month'] ?? '';
$year   = $_GET['year'] ?? '';
$course = trim($_GET['course'] ?? '');

$conditions = ["beso.beso_delete_status = 0"];
$params = [];
$types  = '';

if ($search !== '') {
    $conditions[] = "(beso.firstName  LIKE CONCAT('%', ?, '%')
                   OR beso.middleName LIKE CONCAT('%', ?, '%')
                   OR beso.lastName   LIKE CONCAT('%', ?, '%')
                   OR beso.suffixName LIKE CONCAT('%', ?, '%'))";
    $params = array_merge($params, [$search, $search, $search, $search]);
    $types .= 'ssss';
}

if ($month !== '') {
    $conditions[] = "MONTH(beso.created_at) = ?";
    $params[] = (int)$month;
    $types   .= 'i';
}

if ($year !== '') {
    $conditions[] = "YEAR(beso.created_at) = ?";
    $params[] = (int)$year;
    $types   .= 'i';
}

if ($course !== '') {
    $conditions[] = "beso.course = ?";
    $params[] = $course;
    $types   .= 's';
}

$whereClause = implode(' AND ', $conditions);

// --------- Pagination vars ---------
$page             = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum'])) ? (int)$_GET['pagenum'] : 1;
$results_per_page = 20;
$offset           = ($page - 1) * $results_per_page;

// --------- Count total rows (no join) ---------
$countQuery = "SELECT COUNT(*) AS total FROM beso WHERE $whereClause";
$countStmt  = $mysqli->prepare($countQuery);
if ($countStmt) {
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    if ($countStmt->execute()) {
        $res = $countStmt->get_result();
        $total_rows  = (int)($res->fetch_assoc()['total'] ?? 0);
        $total_pages = max(1, (int)ceil($total_rows / $results_per_page));
    } else {
        error_log('SQL error (count): ' . $countStmt->error);
        $total_rows = 0; $total_pages = 1;
    }
    $countStmt->close();
} else {
    $total_rows = 0; $total_pages = 1;
}

// --------- Main SELECT (no join; use BESO name fields) ---------
$query = "
  SELECT
    beso.id,
    beso.firstName,
    beso.middleName,
    beso.lastName,
    beso.suffixName,
    beso.education_attainment,
    beso.course,
    beso.created_at
  FROM beso
  WHERE $whereClause
  ORDER BY beso.created_at DESC
  LIMIT ? OFFSET ?";

$mainParams = $params;
$mainTypes  = $types . 'ii';
$mainParams[] = $results_per_page;
$mainParams[] = $offset;

$stmt = $mysqli->prepare($query);
if ($stmt) {
    $stmt->bind_param($mainTypes, ...$mainParams);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
    } else {
        error_log('SQL error (main): ' . $stmt->error);
        $result = false;
    }
    $stmt->close();
} else {
    $result = false;
}

// --------- Build pagination query string ---------
$queryParams = $_GET;
unset($queryParams['pagenum']);
$queryString = http_build_query($queryParams);

// $result, $total_pages, $page, $queryString, $baseUrl are ready for the view.
// NOTE: In the table template, read the fields as:
// $row['firstName'], $row['middleName'], $row['lastName'], $row['suffixName']
?>
