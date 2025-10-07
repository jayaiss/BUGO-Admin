<?php
session_start();
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json');

$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin','Multimedia'])) {
  echo json_encode(['success'=>false,'message'=>'Forbidden']); exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

$sql = "SELECT e.id, e.event_title AS event_title_id, e.event_description, e.event_location,
               e.event_time, e.event_end_time, e.event_date
        FROM events e
        WHERE e.id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

echo json_encode(['success'=>true,'event'=>$res]);
