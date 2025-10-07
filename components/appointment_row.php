<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

if (!function_exists('calculate_age')) {
    function calculate_age($birth_date) {
        if (!$birth_date) return '';
        $birth = new DateTime($birth_date);
        $today = new DateTime();
        return $birth->diff($today)->y;
    }
}

/* ---- Cedula fields fallback for print (urgent first, then regular cedula if still empty) ---- */
$printCedNo = trim((string)($row['cedula_number'] ?? ''));
$printIssOn = trim((string)($row['issued_on'] ?? ''));
$printIssAt = trim((string)($row['issued_at'] ?? ''));

// normalize bogus date
if ($printIssOn === '0000-00-00') $printIssOn = '';

$rid = (int)($row['res_id'] ?? 0);
if ($rid && ($printCedNo === '' || $printIssOn === '' || $printIssAt === '')) {
    // 1) Try URGENT CEDULA first
    $q = $mysqli->prepare("
        SELECT cedula_number, issued_on, issued_at
        FROM urgent_cedula_request
        WHERE res_id = ?
          AND cedula_status IN ('Released')
          AND cedula_delete_status = 0
        ORDER BY COALESCE(NULLIF(issued_on,'0000-00-00'), appointment_date) DESC, urg_ced_id DESC
        LIMIT 1
    ");
    $q->bind_param('i', $rid);
    if ($q->execute()) {
        $q->bind_result($uc_no, $uc_on, $uc_at);
        if ($q->fetch()) {
            if ($printCedNo === '' && $uc_no) $printCedNo = $uc_no;
            if ($printIssOn === '' && $uc_on && $uc_on !== '0000-00-00') $printIssOn = $uc_on;
            if ($printIssAt === '' && $uc_at) $printIssAt = $uc_at;
        }
    }
    $q->close();
}

if ($rid && ($printCedNo === '' || $printIssOn === '' || $printIssAt === '')) {
    // 2) Fall back to REGULAR CEDULA
    $q2 = $mysqli->prepare("
        SELECT cedula_number, issued_on, issued_at
        FROM cedula
        WHERE res_id = ?
          AND cedula_status IN ('Released')
          AND cedula_delete_status = 0
        ORDER BY COALESCE(NULLIF(issued_on,'0000-00-00'), appointment_date) DESC
        LIMIT 1
    ");
    $q2->bind_param('i', $rid);
    if ($q2->execute()) {
        $q2->bind_result($c_no, $c_on, $c_at);
        if ($q2->fetch()) {
            if ($printCedNo === '' && $c_no) $printCedNo = $c_no;
            if ($printIssOn === '' && $c_on && $c_on !== '0000-00-00') $printIssOn = $c_on;
            if ($printIssAt === '' && $c_at) $printIssAt = $c_at;
        }
    }
    $q2->close();
}

$status      = strtolower($row['status'] ?? '');
$certificate = strtolower($row['certificate'] ?? '');
$role        = $_SESSION['Role_Name'] ?? '';

// ✅ Allow print only if status is 'approvedcaptain' AND not Cedula
$canPrint = ($status === 'approvedcaptain' && $certificate !== 'cedula');

// ✅ Construct safe JS onclick string if printing is allowed
$onclick = "";
if ($canPrint) {
    $onclick =
        "logPrint(" . (int)($row['res_id'] ?? 0) . ",3,'" . addslashes($row['tracking_number'] ?? '') . "');" .
        "printAppointment('"
        . addslashes($row['certificate'] ?? '') . "','"
        . addslashes($row['fullname'] ?? '') . "','"
        . addslashes($row['res_zone'] ?? '') . "','"
        . addslashes($row['birth_date'] ?? '') . "','"
        . addslashes($row['birth_place'] ?? '') . "','"
        . addslashes($row['res_street_address'] ?? '') . "','"
        . addslashes($row['purpose'] ?? '') . "','"
        . addslashes($printIssOn) . "','"
        . addslashes($printIssAt) . "','"
        . addslashes($printCedNo) . "','"
        . addslashes($row['civil_status'] ?? '') . "','"
        . addslashes($row['residency_start'] ?? '') . "','"
        . (isset($row['birth_date']) ? calculate_age($row['birth_date']) : '')
        . "')";
}

// ✅ Display "Released / Paid" instead of just "Released"
$displayStatus = $row['status'] ?? '';
if (strcasecmp($displayStatus, 'Released') === 0) {
    $displayStatus = 'Released / Paid';
}

// Map status to soft badge classes defined in css/viewApp.css
$norm = strtolower(preg_replace('/\s+/', '', $row['status'] ?? ''));
$badgeClass = match ($norm) {
    'pending'          => 'badge-soft-warning',
    'approved'         => 'badge-soft-info',
    'approvedcaptain'  => 'badge-soft-primary',
    'rejected'         => 'badge-soft-danger',
    'released'         => 'badge-soft-success',
    default            => 'badge-soft-secondary',
};
?>

<tr class="align-middle">
  <td data-label="Full Name" class="fw-medium"><?= htmlspecialchars($row['fullname']) ?></td>
  <td data-label="Certificate"><?= htmlspecialchars($row['certificate']) ?></td>
  <td data-label="Tracking Number"><span class="text-uppercase fw-semibold"><?= htmlspecialchars($row['tracking_number']) ?></span></td>
  <td data-label="Date"><?= htmlspecialchars($row['selected_date']) ?></td>
  <td data-label="Time Slot"><span class="badge bg-info text-dark"><?= htmlspecialchars($row['selected_time']) ?></span></td>
  <td data-label="Status">
    <span class="badge px-3 py-2 fw-semibold
      <?= $row['status'] === 'Approved'         ? 'badge-soft-success' :
         ($row['status'] === 'Rejected'        ? 'badge-soft-danger'  :
         ($row['status'] === 'Released'        ? 'badge-soft-primary' :
         ($row['status'] === 'ApprovedCaptain' ? 'badge-soft-warning' : 'badge-soft-secondary'))) ?>">
      <?= htmlspecialchars($displayStatus) ?>
    </span>
  </td>

  <td data-label="Actions" class="text-end d-flex gap-2 justify-content-end">
    <!-- View -->
    <button class="btn btn-sm btn-info text-white"
      data-bs-toggle="modal"
      data-bs-target="#viewModal"
      data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
      data-certificate="<?= htmlspecialchars($row['certificate']) ?>"
      data-tracking-number="<?= htmlspecialchars($row['tracking_number']) ?>"
      data-res-id="<?= (int)$row['res_id'] ?>"
      data-selected-date="<?= htmlspecialchars($row['selected_date']) ?>"
      data-selected-time="<?= htmlspecialchars($row['selected_time']) ?>"
      data-status="<?= htmlspecialchars($row['status']) ?>"
      data-cedula-income="<?= htmlspecialchars($row['cedula_income'] ?? '') ?>"
      title="View Details"
      onclick="logAppointmentView(<?= (int)$row['res_id'] ?>)">
      <i class="bi bi-eye-fill"></i>
    </button>

    <!-- Print -->
    <button
      class="btn btn-sm <?= $canPrint ? 'btn-secondary' : 'btn-light text-muted' ?>"
      <?= $canPrint ? '' : 'disabled' ?>
      onclick="<?= $canPrint ? $onclick : '' ?>"
      title="<?= $canPrint ? 'Print' : 'Print not available' ?>">
      <i class="bi bi-printer<?= $canPrint ? '-fill' : '' ?>"></i>
    </button>
  </td>
</tr>

<script>
function logPrint(resId, filename, trackingNumber) {
  try {
    const body = new URLSearchParams({
      action: 'print',
      filename: String(filename),
      viewedID: String(resId),
      tracking_number: trackingNumber || ''
    });
    fetch('./logs/logs_trig.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body
    }).catch(() => {});
  } catch (e) {}
}
</script>
