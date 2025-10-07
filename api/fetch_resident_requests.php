<?php
// CORS + JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../include/connection.php';

$res_id = isset($_GET['res_id']) ? intval($_GET['res_id']) : 0;
if ($res_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Resident ID not provided or invalid.']);
  exit();
}

/**
 * Compute Cedula payment EXACTLY like your Flutter code:
 * - monthlyIncomeText: string, may include symbols
 * - selectedDateYmd: 'YYYY-MM-DD' used for month-index based interest
 *
 * Flutter:
 *   gross = monthlyIncome * 12
 *   payment = floor(gross / 1000)
 *   cedPayment = payment + 5
 *   m = selectedDate.month  // 1..12
 *   rate = 0
 *   if (m >= 2) rate = (0.04 + 0.02*(m-2)) / 100
 *   interest = gross * rate
 *   finalAmount = round(cedPayment + interest)
 *   if (finalAmount < 50) finalAmount = 50
 */
function compute_cedula_payment_php($monthlyIncomeText, $selectedDateYmd) {
    $sanitized = preg_replace('/[^\d.]/', '', (string)$monthlyIncomeText);
    $monthlyIncome = (float)($sanitized === '' ? '0' : $sanitized);

    $gross = $monthlyIncome * 12.0;
    $payment = floor($gross / 1000.0);
    $cedPayment = $payment + 5;

    $m = 0;
    if (!empty($selectedDateYmd)) {
        $ts = strtotime($selectedDateYmd);
        if ($ts !== false) {
            $m = (int)date('n', $ts); // 1..12
        }
    }

    $rate = 0.0;
    if ($m >= 2) {
        // matches your Dart: Feb=0.04%, Mar=0.06%, ...
        $rate = (0.04 + 0.02 * ($m - 2)) / 100.0;
    }

    $interest = $gross * $rate;
    $finalAmount = round($cedPayment + $interest);

    if ($finalAmount < 50.0) $finalAmount = 50.0;
    return $finalAmount;
}

$all = [];

/* ---- APPOINTMENTS (schedules) ---- */
$sql1 = "SELECT id, purpose, certificate, selected_date, selected_time, tracking_number,
                COALESCE(status,'Pending') AS status,
                COALESCE(rejection_reason,'') AS rejection_reason,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
                COALESCE(is_read,0) AS is_read
         FROM schedules
         WHERE res_id = ? AND COALESCE(appointment_delete_status,0) = 0
         ORDER BY created_at DESC";
if ($stmt = $mysqli->prepare($sql1)) {
  $stmt->bind_param('i', $res_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $all[] = [
      'type'            => 'Appointment',
      'request_id'      => (int)$r['id'],
      'display_name'    => !empty($r['purpose']) ? $r['purpose'] : $r['certificate'],
      'current_status'  => $r['status'],
      'tracking_number' => $r['tracking_number'],
      'selected_date'   => $r['selected_date'],
      'selected_time'   => $r['selected_time'],
      'created_at'      => $r['created_at'],
      'rejection_reason'=> $r['rejection_reason'],
      'is_read'         => (int)$r['is_read'],
      // provide explicit keys so Flutter can decide ₱50 vs exempt
      'certificate'     => $r['certificate'],
      'purpose'         => $r['purpose'],
      // no amount_to_pay for generic appointments (handled as ₱50/exempt in Flutter)
    ];
  }
  $stmt->close();
}

/* ---- CEDULA ----
 * We try to read income (column name 'income'—change if yours is different).
 * We compute amount_to_pay to mirror the scheduler.
 */
$sql2 = "SELECT Ced_Id, tracking_number,
                COALESCE(cedula_status,'Pending') AS cedula_status,
                COALESCE(rejection_reason,'') AS rejection_reason,
                appointment_date, appointment_time,
                income,  /* <- adjust column name if different */
                DATE_FORMAT(COALESCE(update_time, issued_on), '%Y-%m-%d %H:%i:%s') AS created_at,
                COALESCE(is_read,0) AS is_read
         FROM cedula
         WHERE res_id = ? AND COALESCE(cedula_delete_status,0) = 0
         ORDER BY created_at DESC";
if ($stmt = $mysqli->prepare($sql2)) {
  $stmt->bind_param('i', $res_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    // compute amount using the same formula as Flutter
    $incomeText = isset($r['income']) ? $r['income'] : '0';
    $apptDate   = isset($r['appointment_date']) ? $r['appointment_date'] : '';
    $amt        = compute_cedula_payment_php($incomeText, $apptDate);

    $all[] = [
      'type'            => 'Cedula Request',
      'request_id'      => (int)$r['Ced_Id'],
      'display_name'    => 'Cedula Application',
      'current_status'  => $r['cedula_status'],
      'tracking_number' => $r['tracking_number'],
      'selected_date'   => $r['appointment_date'],
      'selected_time'   => $r['appointment_time'],
      'created_at'      => $r['created_at'],
      'rejection_reason'=> $r['rejection_reason'],
      'is_read'         => (int)$r['is_read'],
      'certificate'     => 'Cedula',
      'purpose'         => null,
      'amount_to_pay'   => number_format($amt, 2, '.', ''), // e.g. "185.00"
    ];
  }
  $stmt->close();
}

/* ---- URGENT REQUEST (if table exists) ---- */
$urgentExists = $mysqli->query("SHOW TABLES LIKE 'urgent_request'");
if ($urgentExists && $urgentExists->num_rows > 0) {
  $sql3 = "SELECT urg_id, certificate, purpose, selected_date, selected_time, tracking_number,
                  COALESCE(status,'Pending') AS status,
                  COALESCE(rejection_reason,'') AS rejection_reason,
                  DATE_FORMAT(COALESCE(update_time, created_at), '%Y-%m-%d %H:%i:%s') AS created_at,
                  COALESCE(is_read,0) AS is_read
           FROM urgent_request
           WHERE res_id = ? AND COALESCE(urgent_delete_status,0) = 0
           ORDER BY created_at DESC";
  if ($stmt = $mysqli->prepare($sql3)) {
    $stmt->bind_param('i', $res_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $all[] = [
        'type'            => 'Urgent Request',
        'request_id'      => (int)$r['urg_id'],
        'display_name'    => !empty($r['purpose']) ? $r['purpose'] : $r['certificate'],
        'current_status'  => $r['status'],
        'tracking_number' => $r['tracking_number'],
        'selected_date'   => $r['selected_date'],
        'selected_time'   => $r['selected_time'],
        'created_at'      => $r['created_at'],
        'rejection_reason'=> $r['rejection_reason'],
        'is_read'         => (int)$r['is_read'],
        'certificate'     => $r['certificate'],
        'purpose'         => $r['purpose'],
      ];
    }
    $stmt->close();
  }
}

/* Final sort newest first, then send EXACT keys your Dart expects */
usort($all, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

echo json_encode(['success' => true, 'data' => $all]);
$mysqli->close();
