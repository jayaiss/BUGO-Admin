<?php
ini_set('display_errors', 0); // Hide errors from users
ini_set('log_errors', 1);     // Log to server
error_reporting(E_ALL);

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

/* =========================================
   Helpers
========================================= */
function count_records($table, $delete_status_column) {
    global $mysqli;
    $sql = "SELECT COUNT(*) FROM $table WHERE $delete_status_column = 1";
    $res = $mysqli->query($sql);
    return (int)($res ? $res->fetch_row()[0] : 0);
}

/** Windowed pagination renderer (First/Prev/…/Next/Last) */
function render_pagination(int $total_items, int $limit, int $page, string $pageBase, string $qs): void {
    $total_pages = max(1, (int)ceil($total_items / $limit));
    $page = max(1, min($page, $total_pages));
    $window = 2; // pages to show left/right of current
    $start = max(1, $page - $window);
    $end   = min($total_pages, $page + $window);

    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';

    // First
    if ($page <= 1) {
        echo '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><i class="fa fa-angle-double-left" aria-hidden="true"></i><span class="visually-hidden">First</span></span></li>';
    } else {
        echo '<li class="page-item"><a class="page-link" href="'.$pageBase.$qs.'&pagenum=1" aria-label="First"><i class="fa fa-angle-double-left" aria-hidden="true"></i><span class="visually-hidden">First</span></a></li>';
    }

    // Previous
    if ($page <= 1) {
        echo '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="visually-hidden">Previous</span></span></li>';
    } else {
        echo '<li class="page-item"><a class="page-link" href="'.$pageBase.$qs.'&pagenum='.($page-1).'" aria-label="Previous"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="visually-hidden">Previous</span></a></li>';
    }

    // Left ellipsis
    if ($start > 1) {
        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }

    // Windowed page numbers
    for ($i = $start; $i <= $end; $i++) {
        $active = ($i == $page) ? ' active' : '';
        echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$pageBase.$qs.'&pagenum='.$i.'">'.$i.'</a></li>';
    }

    // Right ellipsis
    if ($end < $total_pages) {
        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }

    // Next
    if ($page >= $total_pages) {
        echo '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="visually-hidden">Next</span></span></li>';
    } else {
        echo '<li class="page-item"><a class="page-link" href="'.$pageBase.$qs.'&pagenum='.($page+1).'" aria-label="Next"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="visually-hidden">Next</span></a></li>';
    }

    // Last
    if ($page >= $total_pages) {
        echo '<li class="page-item disabled"><span class="page-link" aria-disabled="true"><i class="fa fa-angle-double-right" aria-hidden="true"></i><span class="visually-hidden">Last</span></span></li>';
    } else {
        echo '<li class="page-item"><a class="page-link" href="'.$pageBase.$qs.'&pagenum='.$total_pages.'" aria-label="Last"><i class="fa fa-angle-double-right" aria-hidden="true"></i><span class="visually-hidden">Last</span></a></li>';
    }

    echo '</ul></nav>';
}

/* =========================================
   Data fetchers
========================================= */
function get_archived_residents($search_term = '', $limit = 10, $page = 1) {
    global $mysqli;
    $offset = ($page - 1) * $limit;

    if ($search_term) {
        $sql = "SELECT id, CONCAT(first_name, ' ', middle_name, ' ', last_name) AS full_name, gender, res_zone
                FROM residents
                WHERE resident_delete_status = 1
                  AND (id LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ?)
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $s = "%$search_term%";
        $stmt->bind_param("ssssii", $s,$s,$s,$s,$limit,$offset);
    } else {
        $sql = "SELECT id, CONCAT(first_name, ' ', middle_name, ' ', last_name) AS full_name, gender, res_zone
                FROM residents
                WHERE resident_delete_status = 1
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function get_archived_employees($search_term = '', $limit = 10, $page = 1) {
    global $mysqli;
    $offset = ($page - 1) * $limit;

    if ($search_term) {
        $sql = "SELECT employee_id, CONCAT(employee_fname,' ',employee_mname,' ',employee_lname) AS full_name, employee_gender, employee_zone
                FROM employee_list
                WHERE employee_delete_status = 1
                  AND (employee_id LIKE ? OR employee_fname LIKE ? OR employee_mname LIKE ? OR employee_lname LIKE ?)
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $s = "%$search_term%";
        $stmt->bind_param("ssssii", $s,$s,$s,$s,$limit,$offset);
    } else {
        $sql = "SELECT employee_id, CONCAT(employee_fname,' ',employee_mname,' ',employee_lname) AS full_name, employee_gender, employee_zone
                FROM employee_list
                WHERE employee_delete_status = 1
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function get_archived_appointments($search_term = '', $limit = 10, $page = 1) {
    global $mysqli;
    $offset = ($page - 1) * $limit;

    if ($search_term) {
        $sql = "
        SELECT * FROM (
            SELECT a.id,
                   CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
                   a.certificate, a.tracking_number, a.selected_date, a.selected_time, a.status
            FROM schedules a
            JOIN residents r ON a.res_id = r.id
            WHERE a.appointment_delete_status = 1
              AND (r.first_name LIKE ? OR r.middle_name LIKE ? OR r.last_name LIKE ? OR a.tracking_number LIKE ?)

            UNION ALL

            SELECT c.Ced_Id AS id,
                   CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
                   'Cedula' AS certificate, c.tracking_number,
                   DATE(c.appointment_date_time) AS selected_date,
                   TIME(c.appointment_date_time) AS selected_time,
                   c.cedula_status AS status
            FROM cedula c
            JOIN residents r ON c.res_id = r.id
            WHERE c.cedula_delete_status = 1
              AND (r.first_name LIKE ? OR r.middle_name LIKE ? OR r.last_name LIKE ? OR c.tracking_number LIKE ?)
        ) t
        LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $s = "%$search_term%";
        $stmt->bind_param("ssssssssii", $s,$s,$s,$s,$s,$s,$s,$s,$limit,$offset);
    } else {
        $sql = "
        SELECT * FROM (
            SELECT a.id,
                   CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
                   a.certificate, a.tracking_number, a.selected_date, a.selected_time, a.status
            FROM schedules a
            JOIN residents r ON a.res_id = r.id
            WHERE a.appointment_delete_status = 1

            UNION ALL

            SELECT c.Ced_Id AS id,
                   CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
                   'Cedula' AS certificate, c.tracking_number,
                   c.appointment_date AS selected_date,
                   c.appointment_time AS selected_time,
                   c.cedula_status AS status
            FROM cedula c
            JOIN residents r ON c.res_id = r.id
            WHERE c.cedula_delete_status = 1
        ) t
        LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function get_archived_events($search_term = '', $limit = 10, $page = 1) {
    global $mysqli;
    $offset = ($page - 1) * $limit;

    if ($search_term) {
        $sql = "SELECT id, event_title, event_description, event_location, event_time, event_date
                FROM events
                WHERE events_delete_status = 1
                  AND (event_title LIKE ? OR event_description LIKE ? OR event_location LIKE ?)
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $s = "%$search_term%";
        $stmt->bind_param("ssssi", $s,$s,$s,$limit,$offset);
    } else {
        $sql = "SELECT id, event_title, event_description, event_location, event_time, event_date
                FROM events
                WHERE events_delete_status = 1
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function get_archived_feedback($search_term = '', $limit = 10, $page = 1) {
    global $mysqli;
    $offset = ($page - 1) * $limit;

    if ($search_term) {
        $sql = "SELECT id, feedback_text, created_at
                FROM feedback
                WHERE feedback_delete_status = 1
                  AND feedback_text LIKE ?
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $s = "%$search_term%";
        $stmt->bind_param("sii", $s,$limit,$offset);
    } else {
        $sql = "SELECT id, feedback_text, created_at
                FROM feedback
                WHERE feedback_delete_status = 1
                LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/* =========================================
   Handle restore / delete POST actions
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Residents
    if (isset($_POST['restore_resident'])) {
        $resident_id = (int)$_POST['resident_id'];
        $stmt = $mysqli->prepare("UPDATE residents SET resident_delete_status = 0 WHERE id = ?");
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $trigger->isRestored(23, $resident_id, 20);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Resident restored successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    } elseif (isset($_POST['delete_resident'])) {
        $resident_id = (int)$_POST['resident_id'];
        $stmt = $mysqli->prepare("DELETE FROM residents WHERE id = ?");
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $trigger->isDelete(23, $resident_id);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Resident deleted successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    }

    // Employees
    if (isset($_POST['restore_employee'])) {
        $employee_id = (int)$_POST['employee_id'];
        $stmt = $mysqli->prepare("UPDATE employee_list SET employee_delete_status = 0 WHERE employee_id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $trigger->isRestored(24, $employee_id, 10);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Employee restored successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    } elseif (isset($_POST['delete_employee'])) {
        $employee_id = (int)$_POST['employee_id'];
        $stmt = $mysqli->prepare("DELETE FROM employee_list WHERE employee_id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $trigger->isDelete(24, $employee_id);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Employee deleted successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    }

    // Appointments
    if (isset($_POST['restore_appointment'])) {
        $appointment_id = (int)$_POST['appointment_id'];
        $stmt = $mysqli->prepare("UPDATE schedules SET appointment_delete_status = 0 WHERE id = ?");
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $trigger->isRestored(25, $appointment_id, 30);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Appointment restored successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    } elseif (isset($_POST['delete_appointment'])) {
        $appointment_id = (int)$_POST['appointment_id'];
        $stmt = $mysqli->prepare("DELETE FROM schedules WHERE id = ?");
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $trigger->isDelete(25, $appointment_id);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Appointment deleted successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    }

    // Events
    if (isset($_POST['restore_event'])) {
        $event_id = (int)$_POST['id'];
        $stmt = $mysqli->prepare("UPDATE events SET events_delete_status = 0 WHERE id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $trigger->isRestored(26, 0, 11);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Event restored successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    } elseif (isset($_POST['delete_event'])) {
        $event_id = (int)$_POST['id'];
        $stmt = $mysqli->prepare("DELETE FROM events WHERE id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $trigger->isDelete(26, 0);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Event deleted successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    }

    // Feedback
    if (isset($_POST['restore_feedback'])) {
        $feedback_id = (int)$_POST['feedback_id'];
        $stmt = $mysqli->prepare("UPDATE feedback SET feedback_delete_status = 0 WHERE id = ?");
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        $trigger->isRestored(27, $feedback_id, 20);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Feedback restored successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    } elseif (isset($_POST['delete_feedback'])) {
        $feedback_id = (int)$_POST['feedback_id'];
        $stmt = $mysqli->prepare("DELETE FROM feedback WHERE id = ?");
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        $trigger->isDelete(27, $feedback_id);
        echo "<script>Swal.fire({icon:'success',title:'Success',text:'Feedback deleted successfully!'}).then(()=>{ location.reload(); });</script>";
        exit;
    }
}

/* =========================================
   Pagination + counts + fetch
========================================= */
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$page        = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum'])) ? (int)$_GET['pagenum'] : 1;
$limit       = 10; // show 10 per page
$tab         = isset($_GET['tab']) ? $_GET['tab'] : 'residents';

// Totals
$total_residents = count_records('residents', 'resident_delete_status');
$total_employees = count_records('employee_list', 'employee_delete_status');
$total_appointments = (int)$mysqli->query("
    SELECT COUNT(*) FROM (
        SELECT tracking_number FROM schedules WHERE appointment_delete_status = 1
        UNION
        SELECT tracking_number FROM cedula WHERE cedula_delete_status = 1
    ) t
")->fetch_row()[0];
$total_events = count_records('events', 'events_delete_status');
$total_feedback = count_records('feedback', 'feedback_delete_status');

// Data
$archived_residents    = get_archived_residents($search_term, $limit, $page);
$archived_employees    = get_archived_employees($search_term, $limit, $page);
$archived_appointments = get_archived_appointments($search_term, $limit, $page);
$archived_events       = get_archived_events($search_term, $limit, $page);
$archived_feedback     = get_archived_feedback($search_term, $limit, $page);

// If your app provides $redirects['archive'], use it; otherwise fallback to current script
$baseUrl = isset($redirects['archive']) ? $redirects['archive'] : (basename($_SERVER['PHP_SELF']).'?page='.(isset($_GET['page'])?urlencode($_GET['page']):''));
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
       .nav-tabs .nav-link {
  color: #339af0 !important;  /* Light soft blue for inactive tabs */
  font-weight: 500;
  transition: all 0.2s ease;
}

.nav-tabs .nav-link.active {
  background-color: #e7f5ff !important;  /* Light blue background */
  color: #1c7ed6 !important;  /* Slightly deeper blue text for active tab */
  border-color: #339af0 #339af0 #fff;
  font-weight: 600;
}

.nav-tabs .nav-link:hover {
  color: #228be6 !important;  /* Slightly deeper blue on hover */
}

.table {
  border: 2px solid #f03e3e;  /* Red border for the table (adjust or remove as needed) */
  border-radius: 0.5rem;
}

.table th {
  background-color: #343a40;
  color: #fff;
}

.table td, .table th {
  vertical-align: middle;
}

h2 {
  color: #228be6;
  font-weight: 600;
}


    </style>    

      <link rel="stylesheet" href="css/styles.css">
      <link rel="stylesheet" href="css/archive/archive.css">
</head>
<body class="archive-page">
<div class="container mt-5">
  <h2 class="page-title">Archive</h2>

  <ul class="nav nav-tabs" id="archiveTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= ($tab=='residents')?'active':'' ?>" href="<?= $baseUrl ?>&tab=residents">Residents</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= ($tab=='employees')?'active':'' ?>" href="<?= $baseUrl ?>&tab=employees">Employees</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= ($tab=='appointments')?'active':'' ?>" href="<?= $baseUrl ?>&tab=appointments">Appointments</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= ($tab=='events')?'active':'' ?>" href="<?= $baseUrl ?>&tab=events">Events</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= ($tab=='feedback')?'active':'' ?>" href="<?= $baseUrl ?>&tab=feedback">Feedback</a>
    </li>
  </ul>

  <div class="tab-content mt-3" id="archiveTabContent">
    <!-- Residents -->
    <div class="tab-pane fade <?= ($tab=='residents')?'show active':'' ?>" id="residents" role="tabpanel">
      <div class="input-group mb-3">
        <input type="text" class="form-control searchInput" id="searchResidents" placeholder="Search residents..." value="<?= htmlspecialchars($search_term) ?>">
      </div>
      <div class="table-responsive w-100" style="height:500px;overflow-y:auto;">
        <table class="table table-bordered w-100 mb-0" id="residentsTable">
          <thead>
            <tr><th style="width: 300px;">ID</th><th style="width: 300px;">Full Name</th><th style="width: 300px;">Gender</th><th style="width: 300px;">Purok</th><th style="width: 300px;">Actions</th></tr>
          </thead>
          <tbody>
          <?php if($archived_residents->num_rows>0): while($row=$archived_residents->fetch_assoc()): ?>
            <tr>
              <td data-label="ID"><?= htmlspecialchars($row['id']) ?></td>
              <td data-label="Full Name"><?= htmlspecialchars($row['full_name']) ?></td>
              <td data-label="Gender"><?= htmlspecialchars($row['gender']) ?></td>
              <td data-label="Purok"><?= htmlspecialchars($row['res_zone']) ?></td>
              <td class="actions" data-label="Actions">
                <form method="POST">
                  <input type="hidden" name="resident_id" value="<?= $row['id'] ?>">
                  <button type="button" name="restore_resident" class="btn btn-success btn-sm swal-restore-btn" data-message="Are you sure you want to restore this resident?">Restore</button>
                  <button type="button" name="delete_resident" class="btn btn-danger btn-sm swal-delete-btn" data-message="Permanently delete this resident? This cannot be undone.">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="5" class="text-center">No archived residents found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $qs='&tab=residents&search='.urlencode($search_term);
        render_pagination($total_residents,$limit,$page,$baseUrl,$qs);
      ?>
    </div>

    <!-- Employees -->
    <div class="tab-pane fade <?= ($tab=='employees')?'show active':'' ?>" id="employees" role="tabpanel">
      <div class="input-group mb-3">
        <input type="text" class="form-control searchInput" id="searchEmployees" placeholder="Search employees..." value="<?= htmlspecialchars($search_term) ?>">
      </div>
      <div class="table-responsive w-100" style="height:500px;overflow-y:auto;">
        <table class="table table-bordered w-100 mb-0" id="employeesTable">
          <thead>
            <tr><th style="width: 300px;">ID</th><th style="width: 300px;">Full Name</th><th style="width: 300px;">Gender</th><th style="width: 300px;">Zone</th><th style="width: 300px;">Actions</th></tr>
          </thead>
          <tbody>
          <?php if($archived_employees->num_rows>0): while($row=$archived_employees->fetch_assoc()): ?>
            <tr>
              <td data-label="ID"><?= htmlspecialchars($row['employee_id']) ?></td>
              <td data-label="Full Name"><?= htmlspecialchars($row['full_name']) ?></td>
              <td data-label="Gender"><?= htmlspecialchars($row['employee_gender']) ?></td>
              <td data-label="Zone"><?= htmlspecialchars($row['employee_zone']) ?></td>
              <td class="actions" data-label="Actions">
                <form method="POST">
                  <input type="hidden" name="employee_id" value="<?= $row['employee_id'] ?>">
                  <button type="button" name="restore_employee" class="btn btn-success btn-sm swal-restore-btn" data-message="Restore this employee?">Restore</button>
                  <button type="button" name="delete_employee" class="btn btn-danger btn-sm swal-delete-btn" data-message="Permanently delete this employee?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="5" class="text-center">No archived employees found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $qs='&tab=employees&search='.urlencode($search_term);
        render_pagination($total_employees,$limit,$page,$baseUrl,$qs);
      ?>
    </div>

    <!-- Appointments -->
    <div class="tab-pane fade <?= ($tab=='appointments')?'show active':'' ?>" id="appointments" role="tabpanel">
      <div class="input-group mb-3">
        <input type="text" class="form-control searchInput" id="searchAppointments" placeholder="Search appointments..." value="<?= htmlspecialchars($search_term) ?>">
      </div>
      <div class="table-responsive w-100" style="height:500px;overflow-y:auto;">
        <table class="table table-bordered w-100 mb-0" id="appointmentsTable">
          <thead>
            <tr><th>ID</th><th style="width: 250px;">Full Name</th><th>Certificate</th><th>Tracking Number</th><th>Date</th><th>Time Slot</th><th>Status</th><th style="width: 250px;">Actions</th></tr>
          </thead>
          <tbody>
          <?php if($archived_appointments->num_rows>0): while($row=$archived_appointments->fetch_assoc()): ?>
            <tr>
              <td data-label="ID"><?= htmlspecialchars($row['id']) ?></td>
              <td data-label="Full Name"><?= htmlspecialchars($row['full_name']) ?></td>
              <td data-label="Certificate"><?= htmlspecialchars($row['certificate']) ?></td>
              <td data-label="Tracking"><?= htmlspecialchars($row['tracking_number']) ?></td>
              <td data-label="Date"><?= htmlspecialchars($row['selected_date']) ?></td>
              <td data-label="Time"><?= htmlspecialchars($row['selected_time']) ?></td>
              <td data-label="Status"><?= htmlspecialchars($row['status']) ?></td>
              <td class="actions" data-label="Actions">
                <form method="POST">
                  <input type="hidden" name="appointment_id" value="<?= $row['id'] ?>">
                  <button type="button" name="restore_appointment" class="btn btn-success btn-sm swal-restore-btn" data-message="Restore this appointment?">Restore</button>
                  <button type="button" name="delete_appointment" class="btn btn-danger btn-sm swal-delete-btn" data-message="Permanently delete this appointment?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" class="text-center">No archived appointments found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $qs='&tab=appointments&search='.urlencode($search_term);
        render_pagination($total_appointments,$limit,$page,$baseUrl,$qs);
      ?>
    </div>

    <!-- Events -->
    <div class="tab-pane fade <?= ($tab=='events')?'show active':'' ?>" id="events" role="tabpanel">
      <div class="input-group mb-3">
        <input type="text" class="form-control searchInput" id="searchEvents" placeholder="Search events..." value="<?= htmlspecialchars($search_term) ?>">
      </div>
      <div class="table-responsive w-100" style="height:500px;overflow-y:auto;">
        <table class="table table-bordered w-100 mb-0" id="eventsTable">
          <thead>
            <tr><th>Title</th><th>Description</th><th>Location</th><th>Time</th><th>Date</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if($archived_events->num_rows>0): while($row=$archived_events->fetch_assoc()): ?>
            <tr>
              <td data-label="Title"><?= htmlspecialchars($row['event_title']) ?></td>
              <td data-label="Description"><?= htmlspecialchars($row['event_description']) ?></td>
              <td data-label="Location"><?= htmlspecialchars($row['event_location']) ?></td>
              <td data-label="Time"><?= htmlspecialchars($row['event_time']) ?></td>
              <td data-label="Date"><?= htmlspecialchars($row['event_date']) ?></td>
              <td class="actions" data-label="Actions">
                <form method="POST">
                  <input type="hidden" name="id" value="<?= $row['id'] ?>">
                  <button type="button" name="restore_event" class="btn btn-success btn-sm swal-restore-btn" data-message="Restore this event?">Restore</button>
                  <button type="button" name="delete_event" class="btn btn-danger btn-sm swal-delete-btn" data-message="Permanently delete this event?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="6" class="text-center">No archived events found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $qs='&tab=events&search='.urlencode($search_term);
        render_pagination($total_events,$limit,$page,$baseUrl,$qs);
      ?>
    </div>

    <!-- Feedback -->
    <div class="tab-pane fade <?= ($tab=='feedback')?'show active':'' ?>" id="feedback" role="tabpanel">
      <div class="input-group mb-3">
        <input type="text" class="form-control searchInput" id="searchFeedback" placeholder="Search feedback..." value="<?= htmlspecialchars($search_term) ?>">
      </div>
      <div class="table-responsive w-100" style="height:500px;overflow-y:auto;">
        <table class="table table-bordered w-100 mb-0" id="feedbackTable">
          <thead>
            <tr><th style="width: 150px;">ID</th><th style="width: 550px;">Feedback Text</th><th style="width: 250px;">Created At</th><th style="width: 250px;">Actions</th></tr>
          </thead>
          <tbody>
          <?php if($archived_feedback->num_rows>0): while($row=$archived_feedback->fetch_assoc()): ?>
            <tr>
              <td data-label="ID"><?= htmlspecialchars($row['id']) ?></td>
              <td data-label="Feedback Text"><?= htmlspecialchars($row['feedback_text']) ?></td>
              <td data-label="Created At"><?= htmlspecialchars($row['created_at']) ?></td>
              <td class="actions" data-label="Actions">
                <form method="POST">
                  <input type="hidden" name="feedback_id" value="<?= $row['id'] ?>">
                  <button type="button" name="restore_feedback" class="btn btn-success btn-sm swal-restore-btn" data-message="Restore this feedback?">Restore</button>
                  <button type="button" name="delete_feedback" class="btn btn-danger btn-sm swal-delete-btn" data-message="Permanently delete this feedback?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="4" class="text-center">No archived feedback found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $qs='&tab=feedback&search='.urlencode($search_term);
        render_pagination($total_feedback,$limit,$page,$baseUrl,$qs);
      ?>
    </div>
  </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Search functionality for each tab
       $(document).ready(function () {
    // Debounce utility
    function debounce(func, delay) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }

    function bindDebouncedSearch(inputId, tableId) {
        const input = $(`#${inputId}`);
        const tableRows = $(`#${tableId} tbody tr`);

        input.on('input', debounce(function () {
            const searchTerm = input.val().toLowerCase();
            tableRows.each(function () {
                const rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.includes(searchTerm));
            });
        }, 1000)); // 300ms debounce
    }

    bindDebouncedSearch('searchResidents', 'residentsTable');
    bindDebouncedSearch('searchEmployees', 'employeesTable');
    bindDebouncedSearch('searchAppointments', 'appointmentsTable');
    bindDebouncedSearch('searchEvents', 'eventsTable');
    bindDebouncedSearch('searchFeedback', 'feedbackTable');
});
document.addEventListener('DOMContentLoaded', function () {
    function bindSwalAction(selector, title, icon, confirmText, confirmColor) {
        document.querySelectorAll(selector).forEach(button => {
            button.addEventListener('click', () => {
                const form = button.closest('form');
                const message = button.dataset.message || 'Are you sure?';
                Swal.fire({
                    title: title,
                    text: message,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonColor: confirmColor,
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: confirmText
                }).then(result => {
                    if (result.isConfirmed) {
                        // Ensure the clicked button's name is passed in POST
                        const actionName = button.getAttribute('name');
                        if (actionName) {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = actionName;
                            hidden.value = '1';
                            form.appendChild(hidden);
                        }
                        form.submit();
                    }
                });
            });
        });
    }

    bindSwalAction('.swal-delete-btn', 'Confirm Deletion', 'warning', 'Yes, delete it!', '#d33');
    bindSwalAction('.swal-restore-btn', 'Confirm Restore', 'question', 'Yes, restore it!', '#28a745');
});
    </script>
</body>
</html>