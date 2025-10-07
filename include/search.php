<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

if (isset($_GET['query'])) {
    $searchTerm = '%' . $_GET['query'] . '%';

    $query = "SELECT id, CONCAT(first_name, ' ', middle_name, ' ', last_name) AS full_name 
              FROM residents WHERE first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? LIMIT 20";

    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();

    $respondents = [];
    while ($row = $result->fetch_assoc()) {
        $respondents[] = $row;
    }

    echo json_encode($respondents);
    exit;
}
?>
