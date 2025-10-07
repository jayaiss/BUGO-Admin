<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../include/connection.php';

header('Content-Type: application/json');

/* ----------------------------------------------------------------------
 * Guard clauses
 * -------------------------------------------------------------------- */
if (!isset($_SESSION['id'])) {
    echo json_encode(['has_ongoing_cases' => false, 'message' => 'User not logged in']);
    exit;
}

$loggedInUserId = $_SESSION['id'];

// Get the resident ID from the request (assuming it's passed as a GET or POST parameter)
// For a GET request (e.g., check_ongoing_cases.php?res_id=123)
$res_id = isset($_GET['res_id']) ? intval($_GET['res_id']) : (isset($_POST['res_id']) ? intval($_POST['res_id']) : 0);

if ($res_id === 0) {
    echo json_encode(['has_ongoing_cases' => false, 'message' => 'Resident ID not provided']);
    exit;
}

/* --------------------------------------------------
 * Check for ongoing or pending cases
 * -------------------------------------------------- */
$caseCheck = $mysqli->prepare("
    SELECT COUNT(*)
      FROM cases
     WHERE res_id = ?
       AND action_taken IN ('Pending', 'Ongoing')
");
$caseCheck->bind_param("i", $res_id);
$caseCheck->execute();
$caseCheck->bind_result($ongoingCount);
$caseCheck->fetch();
$caseCheck->close();

$mysqli->close();

if ($ongoingCount > 0) {
    echo json_encode([
        'has_ongoing_cases' => true,
        'count' => $ongoingCount,
        'message' => 'You have pending or ongoing cases.'
    ]);
} else {
    echo json_encode([
        'has_ongoing_cases' => false,
        'count' => $ongoingCount,
        'message' => 'No pending or ongoing cases found.'
    ]);
}
?>
