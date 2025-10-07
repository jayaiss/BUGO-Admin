<?php
session_start();
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../logs/logs_trig.php';

header('Content-Type: application/json');

$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin','Multimedia'])) { echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

function clean($s){ return htmlspecialchars(stripslashes(trim((string)$s))); }

$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$event_name  = $_POST['event_name'] ?? '';
$new_name    = clean($_POST['new_event_name'] ?? '');
$desc        = clean($_POST['description'] ?? '');
$date        = $_POST['date'] ?? '';
$start_time  = $_POST['start_time'] ?? '';
$end_time    = $_POST['end_time'] ?? '';
$location    = clean($_POST['location'] ?? '');

if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

/* server-side validation: future date and start<end */
$today = date('Y-m-d');
if (!$date || $date < $today) {
  echo json_encode(['success'=>false,'message'=>'Date must be today or later.']); exit;
}
if (!$start_time || !$end_time || ($end_time <= $start_time)) {
  echo json_encode(['success'=>false,'message'=>'End time must be later than start time.']); exit;
}

/* resolve event_title id; allow new name when "other" */
if ($event_name === 'other' && $new_name !== '') {
  $find = $mysqli->prepare("SELECT id FROM event_name WHERE event_name = ? LIMIT 1");
  $find->bind_param("s", $new_name);
  $find->execute(); $find->bind_result($foundId);
  if ($find->fetch()) {
    $eventTitleId = (int)$foundId;
    $find->close();
  } else {
    $find->close();
    $ins = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?,1,?)");
    $emp_id = $_SESSION['employee_id'] ?? 1;
    $ins->bind_param("si", $new_name, $emp_id);
    $ins->execute();
    $eventTitleId = $ins->insert_id;
    $ins->close();
  }
} else {
  $eventTitleId = (int)$event_name;
}

/* update with/without new image */
$hasImage = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;

if ($hasImage) {
  $img = file_get_contents($_FILES['image']['tmp_name']);
  $typ = $_FILES['image']['type'];
  $sql = "UPDATE events 
          SET event_title=?, event_description=?, event_location=?, event_time=?, event_end_time=?, event_date=?, event_image=?, image_type=?
          WHERE id=?";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("isssssbsis", $eventTitleId, $desc, $location, $start_time, $end_time, $date, $img, $typ, $id);
  // send_long_data for the blob
  $stmt->send_long_data(6, $img);
} else {
  $sql = "UPDATE events 
          SET event_title=?, event_description=?, event_location=?, event_time=?, event_end_time=?, event_date=?
          WHERE id=?";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("isssssi", $eventTitleId, $desc, $location, $start_time, $end_time, $date, $id);
}

$ok = $stmt->execute();
$stmt->close();

if ($ok) {
  $trigs = new Trigger();
  $trigs->isUpdated(11, $id);
  echo json_encode(['success'=>true]);
} else {
  echo json_encode(['success'=>false,'message'=>'Database update failed.']);
}
