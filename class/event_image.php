<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(404); exit; }

$stmt = $mysqli->prepare("SELECT event_image, image_type FROM events WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($blob, $type);
if ($stmt->fetch() && $blob !== null) {
  header("Content-Type: " . ($type ?: 'image/jpeg'));
  echo $blob;
} else {
  http_response_code(404);
}
$stmt->close();
