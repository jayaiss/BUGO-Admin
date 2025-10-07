<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './logs/logs_trig.php';
$trigger = new Trigger();
$trigger->login_id = $_SESSION['employee_id'] ?? null; // Required for logs
function logUpdate($trigger, int $moduleId, int $recordId): void {
    if ($trigger && method_exists($trigger, 'isUpdated')) {
        $trigger->isUpdated($moduleId, $recordId);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$employeeId = $_SESSION['employee_id'] ?? 0;

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// -----------------------------
// TIME SLOT LOGIC
// -----------------------------
if (isset($_POST['add_time_slot'])) {
    $name = sanitizeInput($_POST['time_slot_name']);
    $start = sanitizeInput($_POST['time_slot_start']);
    $end = sanitizeInput($_POST['time_slot_end']);
    $number = intval(sanitizeInput($_POST['time_slot_number']));

    $check = $mysqli->prepare("SELECT COUNT(*) FROM time_slot WHERE time_slot_name = ? AND time_slot_start = ? AND time_slot_end = ? AND employee_id = ?");
    $check->bind_param("sssi", $name, $start, $end, $employeeId);
    $check->execute();
    $check->bind_result($count);
    $check->fetch();
    $check->close();

    if ($count > 0) {
echo "<script>
Swal.fire({
  icon: 'error',
  title: 'Duplicate Found!',
  text: 'This time slot already exists.',
  confirmButtonColor: '#d33'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO time_slot (time_slot_name, time_slot_start, time_slot_end, time_slot_number, employee_id, status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param("sssii", $name, $start, $end, $number, $employeeId);
    if ($stmt->execute()) {
    $lastId = $mysqli->insert_id;
    $trigger->isAdded(21, $lastId); // 20 = Time Slot
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Added!',
  text: 'Time Slot added successfully!',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
        exit;
    } else {
        echo "<script>alert('Error: " . addslashes($stmt->error) . "');</script>";
    }
    $stmt->close();
}

if (isset($_POST['update_time_slot'])) {
    $id = intval($_POST['edit_id']);
    $new_name = sanitizeInput($_POST['edit_name']);
    $new_start = sanitizeInput($_POST['edit_start']);
    $new_end = sanitizeInput($_POST['edit_end']);
    $new_number = intval($_POST['edit_number']);

    $checkStmt = $mysqli->prepare("SELECT time_slot_name, time_slot_start, time_slot_end, time_slot_number FROM time_slot WHERE Id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        echo "<script>alert('Time slot not found.');</script>";
        exit;
    }

    $checkStmt->bind_result($current_name, $current_start, $current_end, $current_number);
    $checkStmt->fetch();
    $checkStmt->close();

    if (
        $new_name === $current_name &&
        $new_start === $current_start &&
        $new_end === $current_end &&
        $new_number === $current_number
    ) {
echo "<script>
Swal.fire({
  icon: 'info',
  title: 'No Changes',
  text: 'No updates were made.',
  confirmButtonColor: '#3085d6'
});
</script>";
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE time_slot SET time_slot_name = ?, time_slot_start = ?, time_slot_end = ?, time_slot_number = ? WHERE Id = ?");
    $stmt->bind_param("sssii", $new_name, $new_start, $new_end, $new_number, $id);
    if ($stmt->execute()) {
     logUpdate($trigger, 21, $id);
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Updated!',
  text: 'Time Slot updated successfully!',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
        exit;
    } else {
echo "<script>
Swal.fire({
  icon: 'error',
  title: 'Update Failed',
  text: '" . addslashes($stmt->error) . "',
  confirmButtonColor: '#d33'
});
</script>";
    }
    $stmt->close();
}

if (isset($_POST['toggle_status'])) {
    $id = intval($_POST['status_id']);
    $newStatus = $_POST['current_status'] === 'Active' ? 'Inactive' : 'Active';

    $stmt = $mysqli->prepare("UPDATE time_slot SET status = ? WHERE Id = ?");
    $stmt->bind_param("si", $newStatus, $id);
    if ($stmt->execute()) {
      logUpdate($trigger, 21, $id);
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Status Updated',
  text: 'Time slot status updated successfully.',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
        exit;
    } else {
echo "<script>
Swal.fire({
  icon: 'error',
  title: 'Update Failed',
  text: 'Failed to update time slot status.',
  confirmButtonColor: '#d33'
});
</script>";
    }
    $stmt->close();
}

$slots = $mysqli->query("SELECT * FROM time_slot ORDER BY time_slot_start ASC");


// -----------------------------
// HOLIDAY LOGIC
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_holiday'])) {
    $holiday_name = sanitizeInput($_POST['holiday_name']);
    $holiday_date = $_POST['holiday_date'];

    $stmt = $mysqli->prepare("INSERT INTO holiday (holiday_name, holiday_date, employee_id, status) VALUES (?, ?, ?, 'Active')");
    $stmt->bind_param("ssi", $holiday_name, $holiday_date, $employeeId);
    $stmt->execute();
    $stmt->close();
    $lastId = $mysqli->insert_id;
$trigger->isAdded(22, $lastId); 
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Added!',
  text: 'Holiday added successfully!',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
    exit;
}

if (isset($_POST['update_holiday'])) {
    $Id = intval($_POST['edit_holiday_id']);
    $holiday_name = sanitizeInput($_POST['edit_holiday_name']);
    $holiday_date = $_POST['edit_holiday_date'];

    $stmt = $mysqli->prepare("UPDATE holiday SET holiday_name = ?, holiday_date = ? WHERE Id = ?");
    $stmt->bind_param("ssi", $holiday_name, $holiday_date, $Id);
    $stmt->execute();
    $stmt->close();
    logUpdate($trigger, 22, $Id);
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Updated!',
  text: 'Holiday updated successfully!',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
    exit;
}

if (isset($_POST['toggle_holiday_status'])) {
    $Id = intval($_POST['holiday_id']);
    $newStatus = $_POST['current_status'] === 'Active' ? 'Inactive' : 'Active';

    $stmt = $mysqli->prepare("UPDATE holiday SET status = ? WHERE Id = ?");
    $stmt->bind_param("si", $newStatus, $Id);
    $stmt->execute();
    $stmt->close();
    logUpdate($trigger, 22, $Id);
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Status Updated',
  text: 'Holiday status updated successfully.',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
    exit;
}

$holidays = $mysqli->query("SELECT * FROM holiday ORDER BY holiday_date ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Time Slot Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/BrgyInfo/tlList.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

</head>
<body class="bg-light">

<div class="container-fluid px-0 px-lg-4 my-4">
  <!-- TIME SLOT HEADER -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Time Slot and Holiday List</h3>
    <button class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#addSlotModal">+ Add Time Slot</button>
  </div>

 <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
      ⏰ Time Slots
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <div class="table-scroll">
          <table class="table table-hover table-bordered align-middle mb-0 table-fixed">
            <thead class="table-light">
              <tr>
                <th style="width: 250px;">Time Slot ID</th>
                <th style="width: 250px;">Name</th>
                <th style="width: 250px;">Start</th>
                <th style="width: 250px;">End</th>
                <th style="width: 250px;">Slot No.</th>
                <th style="width: 250px;">Status</th>
                <th style="width: 250px;">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($slots->num_rows > 0): $i = 1; while ($row = $slots->fetch_assoc()): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['time_slot_name']) ?></td>
                <td><?= date("h:i A", strtotime($row['time_slot_start'])) ?></td>
                <td><?= date("h:i A", strtotime($row['time_slot_end'])) ?></td>
                <td><?= $row['time_slot_number'] ?></td>
                <td>
                  <span class="badge rounded-pill px-3 py-2 fw-semibold <?= $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $row['status'] ?>
                  </span>
                </td>
                <td>
                  <!-- Status + Edit Buttons -->
                  <form method="POST" class="status-form d-inline">
                    <input type="hidden" name="status_id" value="<?= $row['Id'] ?>">
                    <input type="hidden" name="current_status" value="<?= $row['status'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-<?= $row['status'] === 'Active' ? 'secondary' : 'success' ?>">
                      <i class="bi <?= $row['status'] === 'Active' ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                    </button>
                  </form>
                  <button class="btn btn-sm btn-warning ms-1" 
                          data-bs-toggle="modal" 
                          data-bs-target="#editSlotModal"
                          onclick="populateEditModal(<?= htmlspecialchars(json_encode($row)) ?>)">
                    ✏️
                  </button>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="7" class="text-center text-muted">No time slots found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

   <!-- HOLIDAY SECTION -->
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center bg-success text-white">
      <span>🎉 Holiday List</span>
      <button class="btn btn-light btn-sm text-success" data-bs-toggle="modal" data-bs-target="#addHolidayModal">+ Add Holiday</button>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <div class="table-scroll-compact">
          <table class="table table-bordered table-sm align-middle mb-0 w-100 table-fixed">
            <thead class="table-light">
              <tr>
                <th style="width: 350px;">Holiday ID</th>
                <th style="width: 350px;">Holiday Name</th>
                <th style="width: 350px;">Date</th>
                <th style="width: 350px;">Status</th>
                <th style="width: 350px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($holidays->num_rows > 0): $i = 1; while ($row = $holidays->fetch_assoc()): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($row['holiday_name']) ?></td>
                  <td><?= htmlspecialchars($row['holiday_date']) ?></td>
                  <td><span class="badge bg-<?= $row['status'] === 'Active' ? 'success' : 'secondary' ?>"><?= $row['status'] ?></span></td>
                  <td>
                    <form method="POST" class="holiday-status-form d-inline">
                      <input type="hidden" name="holiday_id" value="<?= $row['Id'] ?>">
                      <input type="hidden" name="current_status" value="<?= $row['status'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-<?= $row['status'] === 'Active' ? 'secondary' : 'success' ?>">
                        <i class="bi <?= $row['status'] === 'Active' ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                      </button>
                    </form>
                    <button class="btn btn-sm btn-warning ms-1" data-bs-toggle="modal" data-bs-target="#editHolidayModal"
                            onclick='populateEditHolidayModal(<?= json_encode(["Id" => $row["Id"], "holiday_name" => $row["holiday_name"], "holiday_date" => $row["holiday_date"]]) ?>)'>✏️</button>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="5" class="text-center text-muted">No holidays found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Holiday Modal -->
<div class="modal fade" id="editHolidayModal" tabindex="-1" aria-labelledby="editHolidayModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Edit Holiday</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
<form method="POST" id="editHolidayForm">
        <div class="modal-body">
          <input type="hidden" name="edit_holiday_id" id="edit_holiday_id">
          <div class="mb-3">
            <label class="form-label">Holiday Name</label>
            <input type="text" name="edit_holiday_name" id="edit_holiday_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Holiday Date</label>
            <input type="date" name="edit_holiday_date" id="edit_holiday_date" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_holiday" class="btn btn-success rounded-pill">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- TIME SLOT MODALS -->
<!-- Add Slot Modal -->
<div class="modal fade" id="addSlotModal" tabindex="-1" aria-labelledby="addSlotModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Add Time Slot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
<form method="POST" id="addSlotForm">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Name</label><input type="text" name="time_slot_name" class="form-control" required></div>
          <div class="row">
            <div class="col"><label class="form-label">Start</label><input type="time" name="time_slot_start" class="form-control" required></div>
            <div class="col"><label class="form-label">End</label><input type="time" name="time_slot_end" class="form-control" required></div>
          </div>
          <div class="mt-3"><label class="form-label">Slot No.</label><input type="number" name="time_slot_number" class="form-control" required></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_time_slot" class="btn btn-primary rounded-pill">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Status Confirmation Modal -->
<div class="modal fade" id="statusConfirmModal" tabindex="-1" aria-labelledby="statusConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="statusConfirmModalLabel">Confirm Status Change</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="status_id" id="status_id">
          <input type="hidden" name="current_status" id="current_status">
          <p id="statusConfirmMessage">Are you sure you want to change the status?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="toggle_status" class="btn btn-primary rounded-pill">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Status Confirmation Modal for Holiday -->
<div class="modal fade" id="holidayStatusConfirmModal" tabindex="-1" aria-labelledby="holidayStatusConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="holidayStatusConfirmModalLabel">Confirm Holiday Status Change</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="holiday_id" id="holiday_id">
          <input type="hidden" name="current_status" id="holiday_current_status">
          <p id="holidayStatusConfirmMessage">Are you sure you want to change the status of this holiday?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="toggle_holiday_status" class="btn btn-primary rounded-pill">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- Edit Slot Modal -->
<div class="modal fade" id="editSlotModal" tabindex="-1" aria-labelledby="editSlotModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Edit Time Slot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="editSlotForm">
        <div class="modal-body">
          <input type="hidden" name="edit_id" id="edit_id">
          <div class="mb-3"><label class="form-label">Name</label><input type="text" name="edit_name" id="edit_name" class="form-control" required></div>
          <div class="row">
            <div class="col"><label class="form-label">Start</label><input type="time" name="edit_start" id="edit_start" class="form-control" required></div>
            <div class="col"><label class="form-label">End</label><input type="time" name="edit_end" id="edit_end" class="form-control" required></div>
          </div>
          <div class="mt-3"><label class="form-label">Slot No.</label><input type="number" name="edit_number" id="edit_number" class="form-control" required></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_time_slot" class="btn btn-success rounded-pill">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Holiday Modal -->
<div class="modal fade" id="addHolidayModal" tabindex="-1" aria-labelledby="addHolidayModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title">Add Holiday</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
<form method="POST" id="addHolidayForm">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Holiday Name</label>
            <input type="text" name="holiday_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Holiday Date</label>
            <input type="date" name="holiday_date" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_holiday" class="btn btn-success rounded-pill">Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// include '../include/connection.php';

$timeSlots = [];
$result = $mysqli->query("SELECT Id, time_slot_name, time_slot_start, time_slot_end FROM time_slot WHERE status = 'Active'");
while ($row = $result->fetch_assoc()) {
    $timeSlots[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? null;
    $slotId = $_POST['time_slot_id'] ?? null;
    $limit = $_POST['custom_limit'] ?? null;

    if ($date && $slotId && $limit !== null) {
        $stmt = $mysqli->prepare("
            INSERT INTO custom_time_slots (date, time_slot_id, custom_limit)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE custom_limit = VALUES(custom_limit)
        ");
        $stmt->bind_param("sii", $date, $slotId, $limit);
        $stmt->execute();
        logUpdate($trigger, 21, $slotId); // for holiday
echo "<script>
Swal.fire({
  icon: 'success',
  title: 'Saved!',
  text: 'Custom time slot limit saved successfully.',
  confirmButtonColor: '#3085d6'
}).then(() => {
  window.location = '{$redirects['time_slots']}';
});
</script>";
    } else {
        $message = "❌ Please fill out all fields.";
    }
}
?>

  <div class="card shadow-sm mb-5">
  <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
    <span>🎯 Set Custom Time Slot Limit</span>
  </div>
  <div class="card-body">
    <?php if (isset($message)): ?>
      <div class="alert alert-info"><?= $message ?></div>
    <?php endif; ?>


<form method="POST" id="customLimitForm">
      <div class="mb-3">
        <label for="date" class="form-label">Select Date</label>
        <input type="date" name="date" id="date" class="form-control" required>
      </div>

      <div class="mb-3">
        <label for="time_slot_id" class="form-label">Select Time Slot</label>
        <select name="time_slot_id" id="time_slot_id" class="form-select" required>
          <option value="">-- Choose a time slot --</option>
          <?php foreach ($timeSlots as $slot): ?>
            <option value="<?= $slot['Id'] ?>">
              <?= $slot['time_slot_name'] ?> (<?= $slot['time_slot_start'] ?> - <?= $slot['time_slot_end'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label for="custom_limit" class="form-label">Custom Slot Limit</label>
        <input type="number" name="custom_limit" id="custom_limit" class="form-control" min="1" required>
      </div>

      <button type="submit" class="btn btn-primary rounded-pill px-4">💾 Save</button>
    </form>
      </div>
</div>

  </div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function populateEditModal(slot) {
  document.getElementById('edit_id').value = slot.Id;
  document.getElementById('edit_name').value = slot.time_slot_name;
  document.getElementById('edit_start').value = slot.time_slot_start;
  document.getElementById('edit_end').value = slot.time_slot_end;
  document.getElementById('edit_number').value = slot.time_slot_number;
}
function openStatusModal(id, currentStatus) {
  const nextStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';
  document.getElementById('status_id').value = id;
  document.getElementById('current_status').value = currentStatus;
  document.getElementById('statusConfirmMessage').textContent = `Are you sure you want to ${nextStatus.toLowerCase()} this time slot?`;
  let modal = new bootstrap.Modal(document.getElementById('statusConfirmModal'));
  modal.show();
}
function openHolidayStatusModal(id, currentStatus) {
  const nextStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';
  document.getElementById('holiday_id').value = id;
  document.getElementById('holiday_current_status').value = currentStatus;
  document.getElementById('holidayStatusConfirmMessage').textContent = `Are you sure you want to ${nextStatus.toLowerCase()} this holiday?`;
  let modal = new bootstrap.Modal(document.getElementById('holidayStatusConfirmModal'));
  modal.show();
} 


function populateEditHolidayModal(holiday) {
  document.getElementById('edit_holiday_id').value = holiday.Id;
  document.getElementById('edit_holiday_name').value = holiday.holiday_name;
  document.getElementById('edit_holiday_date').value = holiday.holiday_date;
}

// Reusable function to add hidden input before submit
function addHiddenInput(form, name, value = "1") {
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = name;
  hidden.value = value;
  form.appendChild(hidden);
}

// ADD TIME SLOT
document.getElementById('addSlotForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  Swal.fire({
    icon: 'question',
    title: 'Add Time Slot?',
    text: 'Do you want to save this time slot?',
    showCancelButton: true,
    confirmButtonText: 'Yes, save it!',
    cancelButtonText: 'Cancel'
  }).then(result => {
    if (result.isConfirmed) {
      addHiddenInput(this, 'add_time_slot');
      this.submit();
    }
  });
});

// EDIT TIME SLOT
document.getElementById('editSlotForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  Swal.fire({
    icon: 'question',
    title: 'Update Time Slot?',
    text: 'Do you want to update this time slot?',
    showCancelButton: true,
    confirmButtonText: 'Yes, update it!',
    cancelButtonText: 'Cancel'
  }).then(result => {
    if (result.isConfirmed) {
      addHiddenInput(this, 'update_time_slot');
      this.submit();
    }
  });
});

// ADD HOLIDAY
document.getElementById('addHolidayForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  Swal.fire({
    icon: 'question',
    title: 'Add Holiday?',
    text: 'Do you want to save this holiday?',
    showCancelButton: true,
    confirmButtonText: 'Yes, save it!',
    cancelButtonText: 'Cancel'
  }).then(result => {
    if (result.isConfirmed) {
      addHiddenInput(this, 'add_holiday');
      this.submit();
    }
  });
});

// EDIT HOLIDAY
document.getElementById('editHolidayForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  Swal.fire({
    icon: 'question',
    title: 'Update Holiday?',
    text: 'Do you want to update this holiday?',
    showCancelButton: true,
    confirmButtonText: 'Yes, update it!',
    cancelButtonText: 'Cancel'
  }).then(result => {
    if (result.isConfirmed) {
      addHiddenInput(this, 'update_holiday');
      this.submit();
    }
  });
});

// CUSTOM LIMIT SAVE
document.getElementById('customLimitForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  Swal.fire({
    icon: 'question',
    title: 'Save Limit?',
    text: 'Are you sure you want to save this custom slot limit?',
    showCancelButton: true,
    confirmButtonText: 'Yes, save it!',
    cancelButtonText: 'Cancel'
  }).then(result => {
    if (result.isConfirmed) {
      addHiddenInput(this, 'custom_limit_save'); // match your PHP logic
      this.submit();
    }
  });
});
// Status toggle for time slot and holiday
document.querySelectorAll('.status-form, .holiday-status-form').forEach(form => {
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const type = form.classList.contains('holiday-status-form') ? 'holiday' : 'time slot';
    const currentStatus = form.querySelector('[name="current_status"]').value;
    const nextStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';

    Swal.fire({
      icon: 'warning',
      title: `Change ${type} status?`,
      text: `Are you sure you want to change the status to "${nextStatus}"?`,
      showCancelButton: true,
      confirmButtonText: 'Yes, change it!',
      cancelButtonText: 'Cancel'
    }).then(result => {
      if (result.isConfirmed) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = type === 'holiday' ? 'toggle_holiday_status' : 'toggle_status';
        hidden.value = '1';
        form.appendChild(hidden);

        form.submit();
      }
    });
  });
});
</script>
</body>
</html>
