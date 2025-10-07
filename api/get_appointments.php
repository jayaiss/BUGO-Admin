<?php
// CORS + JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../include/connection.php';

if (!isset($_GET['resident_id']) || !ctype_digit($_GET['resident_id'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Resident ID not provided or invalid.']);
  exit();
}

$residentId = (int) $_GET['resident_id'];

/*
 We return a single, uniform list with fields your Flutter model expects:

  - purpose
  - selected_date
  - selected_time
  - description       (this becomes Appointment.title)
  - appointment_status
  - tracking_number

 From:
  • schedules: use real purpose/certificate/status/selected_* (filter deleted)
  • cedula   : force description='Cedula', purpose='N/A' (or ''), map appointment_*,
               and use appointment_date/time (filter deleted)
*/
$sql = "
  SELECT purpose,
         selected_date,
         selected_time,
         certificate AS description,
         COALESCE(status, 'Pending') AS appointment_status,
         tracking_number
  FROM schedules
  WHERE res_id = ? AND COALESCE(appointment_delete_status,0) = 0

  UNION ALL

  SELECT
         '' AS purpose,                                      -- or 'N/A' if you prefer
         appointment_date AS selected_date,
         appointment_time AS selected_time,
         'Cedula' AS description,
         COALESCE(cedula_status, 'Pending') AS appointment_status,
         tracking_number
  FROM cedula
  WHERE res_id = ? AND COALESCE(cedula_delete_status,0) = 0

  ORDER BY selected_date ASC, selected_time ASC
";

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Database prepare failed: ' . $mysqli->error]);
  exit();
}

$stmt->bind_param('ii', $residentId, $residentId);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    'purpose'            => $row['purpose'] ?? '',
    'selected_date'      => $row['selected_date'] ?? '',
    'selected_time'      => $row['selected_time'] ?? '',
    'description'        => $row['description'] ?? '',
    'appointment_status' => $row['appointment_status'] ?? 'Pending',
    'tracking_number'    => $row['tracking_number'] ?? null,
  ];
}

echo json_encode($out);
$stmt->close();
$mysqli->close();
