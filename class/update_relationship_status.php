<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child_id = $_POST['related_resident_id'] ?? null;
    $parent_id = $_POST['resident_id'] ?? null;
    $type = $_POST['relationship_type'] ?? null;
    $status = $_POST['status'] ?? 'pending';

    $stmt = $mysqli->prepare("UPDATE resident_relationships SET status = ? WHERE related_resident_id = ? AND resident_id = ? AND relationship_type = ?");
    $stmt->bind_param("siis", $status, $child_id, $parent_id, $type);
    $stmt->execute();

    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}
?>
