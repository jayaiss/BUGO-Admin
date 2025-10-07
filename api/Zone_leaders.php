<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
include_once 'logs/logs_trig.php';
$trigs = new Trigger();

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

$message = '';
$employee_id = $_SESSION['employee_id'];

// Sanitize helper
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Add New Zone
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['Zone_Name'])) {
    $Zone_Name = sanitizeInput($_POST["Zone_Name"]);

    if (!empty($Zone_Name)) {
        $check = $mysqli->prepare("SELECT COUNT(*) FROM zone WHERE Zone_Name = ? AND Employee_Id = ?");
        $check->bind_param("si", $Zone_Name, $employee_id);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        if ($count > 0) {
            echo "<script>
                Swal.fire({
                    icon: 'warning',
                    title: 'Duplicate Zone',
                    text: 'This zone already exists.'
                });
            </script>";
        } else {
            $stmt = $mysqli->prepare("INSERT INTO zone (Zone_Name, Employee_Id) VALUES (?, ?)");
            $stmt->bind_param("si", $Zone_Name, $employee_id);

            if ($stmt->execute()) {
                $trigs->isAdded(18, $Zone_Name);
                echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Zone added successfully!',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        window.location.href = '{$redirects['zone_leaders']}';
                    });
                </script>";
                exit();
            } else {
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Could not add Zone.'
                    });
                </script>";
            }

            $stmt->close();
        }
    } else {
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Please enter a valid zone name.'
            });
        </script>";
    }
}

// Update Zone Leader
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_zone_leader'])) {
    $edit_id      = intval($_POST['edit_zone_leader_id']);
    $edit_name    = sanitizeInput($_POST['edit_Leader_FullName']);
    $edit_contact = sanitizeInput($_POST['edit_Contact_Number']);
    $edit_zone_id = intval($_POST['edit_Zone']);

    // Fetch zone name by ID
    $zone_name = '';
    if ($edit_zone_id > 0) {
        $stmt_zone = $mysqli->prepare("SELECT Zone_Name FROM zone WHERE Id = ?");
        $stmt_zone->bind_param("i", $edit_zone_id);
        $stmt_zone->execute();
        $stmt_zone->bind_result($zone_name);
        $stmt_zone->fetch();
        $stmt_zone->close();
    }

    if (!empty($edit_name) && !empty($edit_contact) && !empty($zone_name)) {
        $check_name = $mysqli->prepare("SELECT COUNT(*) FROM zone_leaders WHERE Leader_FullName = ? AND Leaders_Id != ?");
        $check_name->bind_param("si", $edit_name, $edit_id);
        $check_name->execute();
        $check_name->bind_result($name_count);
        $check_name->fetch();
        $check_name->close();

        $check_zone = $mysqli->prepare("SELECT COUNT(*) FROM zone_leaders WHERE Zone = ? AND Leaders_Id != ?");
        $check_zone->bind_param("si", $zone_name, $edit_id);
        $check_zone->execute();
        $check_zone->bind_result($zone_count);
        $check_zone->fetch();
        $check_zone->close();

        if ($name_count > 0) {
            echo "<script>
                Swal.fire({
                    icon: 'warning',
                    title: 'Duplicate Leader',
                    text: 'Another zone leader already has this name.'
                });
            </script>";
        } elseif ($zone_count > 0) {
            echo "<script>
                Swal.fire({
                    icon: 'warning',
                    title: 'Duplicate Zone',
                    text: 'This zone already has a leader assigned.'
                });
            </script>";
        } else {
            $stmt = $mysqli->prepare("UPDATE zone_leaders SET Leader_FullName = ?, Contact_Number = ?, Zone = ? WHERE Leaders_Id = ?");
            $stmt->bind_param("sssi", $edit_name, $edit_contact, $zone_name, $edit_id);

            if ($stmt->execute()) {
                $trigs->isStatusChange(17, $edit_id);
                echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: 'Zone Leader updated successfully.'
                    }).then(() => {
                        window.location.href = '{$redirects['zone_leaders']}';
                    });
                </script>";
                exit();
            } else {
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: 'Could not update leader.'
                    });
                </script>";
            }

            $stmt->close();
        }
    } else {
        echo "<script>
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Input',
                text: 'All fields are required to update a Zone Leader.'
            });
        </script>";
    }
}

// Toggle Status
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status_id'])) {
    $id = intval($_POST['toggle_status_id']);
    $current_status = isset($_POST['current_status']) ? intval($_POST['current_status']) : 0;
    $new_status = $current_status === 1 ? 0 : 1;

    $stmt = $mysqli->prepare("UPDATE zone_leaders SET Leader_Status = ? WHERE Leaders_Id = ?");
    $stmt->bind_param("ii", $new_status, $id);

    if ($stmt->execute()) {
        $trigs->isStatusChange(17, $id);
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Status Updated',
                text: 'Leader status has been updated successfully.',
                confirmButtonColor: '#3085d6'
            }).then(() => {
                window.location.href = '{$redirects['zone_leaders']}';
            });
        </script>";
        exit();
    } else {
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Update Failed',
                text: 'Could not update the status.'
            });
        </script>";
    }

    $stmt->close();
}

// Add Zone Leader
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['Leader_FullName'])) {
    $leader_fullname = sanitizeInput($_POST['Leader_FullName']);
    $contact_number = sanitizeInput($_POST['Contact_Number']);
    $zone_id = intval($_POST['Zone']);
    $zone_name = '';

    if ($zone_id > 0) {
        $stmt_zone = $mysqli->prepare("SELECT Zone_Name FROM zone WHERE Id = ?");
        $stmt_zone->bind_param("i", $zone_id);
        $stmt_zone->execute();
        $stmt_zone->bind_result($zone_name);
        $stmt_zone->fetch();
        $stmt_zone->close();
    }

    if (!empty($leader_fullname) && !empty($contact_number) && !empty($zone_name)) {
        $check = $mysqli->prepare("SELECT COUNT(*) FROM zone_leaders WHERE Leader_FullName = ? AND Zone = ?");
        $check->bind_param("ss", $leader_fullname, $zone_name);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        if ($count > 0) {
            echo "<script>
                Swal.fire({
                    icon: 'warning',
                    title: 'Duplicate Entry',
                    text: 'This person is already assigned to that zone.'
                });
            </script>";
        } else {
            $stmt = $mysqli->prepare("INSERT INTO zone_leaders (Leader_FullName, Contact_Number, Zone, Employee_Id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $leader_fullname, $contact_number, $zone_name, $employee_id);

            if ($stmt->execute()) {
                $trigs->isAdded(17, $leader_fullname);
                echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Zone Leader assigned successfully!',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        window.location.href = '{$redirects['zone_leaders']}';
                    });
                </script>";
                exit();
            } else {
                echo "<script>
                    Swal.fire({
                        icon: 'warning',
                        title: 'Error',
                        text: 'Could not assign zone leader.'
                    });
                </script>";
            }

            $stmt->close();
        }
    } else {
        echo "<script>
            Swal.fire({
                icon: 'warning',
                title: 'Missing Fields',
                text: 'Please select a valid Zone Leader and Zone.'
            });
        </script>";
    }
}

// Fetch residents
$residents = [];
$result = $mysqli->query("SELECT id, first_name, middle_name, last_name, contact_number FROM residents");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $residents[] = $row;
    }
}

// Fetch zones
$zones = [];
$result = $mysqli->query("SELECT Id, Zone_Name FROM zone");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $zones[] = $row;
    }
}

// Fetch zone leaders for display
$zone_leaders = [];
$result = $mysqli->query("SELECT zl.Leaders_Id AS id, zl.Leader_FullName, zl.Contact_Number, zl.Leader_Status, zl.Zone AS Zone_Name FROM zone_leaders zl");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $zone_leaders[] = $row;
    }
}

$mysqli->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Assign Zone Leader</title>
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/BrgyInfo/zoneLeader.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Zone Leaders</h2>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignLeaderModal">+ Assign Zone Leader</button>
      <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addZoneModal">+ Add Zone</button>
    </div>
  </div>

  <?= $message ?>

<div class="table-responsive">
  <table class="table table-bordered table-hover align-middle text-center">
    <thead class="table-dark">
      <tr>
        <th style="width: 350px;">Zone Leader</th>
        <th style="width: 350px;">Contact</th>
        <th style="width: 350px;">Zone</th>
        <th style="width: 350px;">Status</th>
        <th style="width: 350px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($zone_leaders)): ?>
        <?php foreach ($zone_leaders as $zl): ?>
          <tr>
            <td class="text-nowrap"><?= htmlspecialchars($zl['Leader_FullName']) ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($zl['Contact_Number']) ?></td>
            <td><?= htmlspecialchars($zl['Zone_Name']) ?></td>
            <td>
            <?php if ($zl['Leader_Status'] == 1): ?>
                <span class="badge bg-success">Active</span>
            <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
            <?php endif; ?>
            </td>
            <td>
              <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-sm btn-outline-primary edit-btn" 
                        data-bs-toggle="modal" 
                        data-bs-target="#editLeaderModal"
                        data-id="<?= $zl['id'] ?>"
                        data-name="<?= htmlspecialchars($zl['Leader_FullName']) ?>"
                        data-contact="<?= htmlspecialchars($zl['Contact_Number']) ?>"
                        data-zone="<?= htmlspecialchars($zl['Zone_Name']) ?>">
                  <i class="bi bi-pencil-square"></i>
                </button>
                <form method="POST" class="d-inline">
                <input type="hidden" name="toggle_status_id" value="<?= $zl['id'] ?>">
                <input type="hidden" name="current_status" value="<?= $zl['Leader_Status'] ?>">
                <button type="submit" name="update_status" value="1" class="btn btn-sm btn-outline-success update-status-btn">
                <i class="bi bi-arrow-repeat"></i>
                </button>
                </form>
              </div>
            </td>
            
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="4">No zone leaders assigned yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- Edit Zone Leader Modal -->
<div class="modal fade" id="editLeaderModal" tabindex="-1" aria-labelledby="editLeaderModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
            <input type="hidden" name="edit_zone_leader_id" id="edit_zone_leader_id">
      <div class="modal-header">
        <h5 class="modal-title" id="editLeaderModalLabel">Edit Zone Leader</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="edit_Leader_FullName" class="form-label">Select Zone Leader</label>
          <select class="form-control" id="edit_Leader_FullName" name="edit_Leader_FullName" required onchange="updateEditContact()">
            <option value="">-- Select Zone Leader --</option>
            <?php foreach ($residents as $resident): 
              $full_name = htmlspecialchars($resident['first_name'] . " " . ($resident['middle_name'] ? $resident['middle_name'] . " " : "") . $resident['last_name']);
            ?>
              <option value="<?= $full_name ?>" data-contact="<?= htmlspecialchars($resident['contact_number']) ?>">
                <?= $full_name ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label for="edit_Contact_Number" class="form-label">Contact Number</label>
          <input type="text" class="form-control" id="edit_Contact_Number" name="edit_Contact_Number" readonly>
        </div>
        <div class="mb-3">
          <label for="edit_Zone" class="form-label">Select Zone</label>
          <select class="form-control" id="edit_Zone" name="edit_Zone" required>
            <option value="">-- Select Zone --</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= $zone['Id'] ?>"><?= htmlspecialchars($zone['Zone_Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" name="update_zone_leader" class="btn btn-success btn-sm">Save Changes</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Zone Modal -->
<div class="modal fade" id="addZoneModal" tabindex="-1" aria-labelledby="addZoneModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addZoneModalLabel">Add Zone</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label for="Zone_Name" class="form-label">Zone Name</label>
        <input type="text" class="form-control" name="Zone_Name" id="Zone_Name" required>
      </div>
      <div class="modal-footer">
        <button type="reset" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button type="submit" class="btn btn-success btn-sm">Submit</button>
      </div>
    </form>
  </div>
</div>

<!-- Assign Leader Modal -->
<div class="modal fade" id="assignLeaderModal" tabindex="-1" aria-labelledby="assignLeaderModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Zone Leader</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label for="Leader_FullName" class="form-label">Zone Leader</label>
        <select class="form-control mb-3" id="Leader_FullName" name="Leader_FullName" required onchange="updateContactNumber()">
          <option value="">-- Select --</option>
          <?php foreach ($residents as $resident): 
              $full_name = htmlspecialchars($resident['first_name'] . " " . ($resident['middle_name'] ? $resident['middle_name'] . " " : "") . $resident['last_name']);
          ?>
            <option value="<?= $full_name ?>" data-contact="<?= htmlspecialchars($resident['contact_number']) ?>">
              <?= $full_name ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label for="Contact_Number" class="form-label">Contact Number</label>
        <input type="text" class="form-control mb-3" name="Contact_Number" id="Contact_Number" readonly required>
        <label for="Zone" class="form-label">Zone</label>
        <select class="form-control" name="Zone" id="Zone" required>
          <option value="">-- Select Zone --</option>
          <?php foreach ($zones as $zone): ?>
            <option value="<?= $zone['Id'] ?>"><?= htmlspecialchars($zone['Zone_Name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="reset" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button type="submit" class="btn btn-success btn-sm">Submit</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateContactNumber() {
  const select = document.getElementById('Leader_FullName');
  const contact = select.options[select.selectedIndex].getAttribute('data-contact');
  document.getElementById('Contact_Number').value = contact || '';
}

function updateEditContact() {
  const selected = document.getElementById("edit_Leader_FullName").selectedOptions[0];
  document.getElementById("edit_Contact_Number").value = selected?.getAttribute("data-contact") || '';
}

document.addEventListener("DOMContentLoaded", function () {
  // Handle Edit button clicks
document.querySelectorAll(".edit-btn").forEach(btn => {
  btn.addEventListener("click", function () {
    const name = this.getAttribute("data-name");
    const contact = this.getAttribute("data-contact");
    const zoneName = this.getAttribute("data-zone");
    const id = this.getAttribute("data-id");

    document.getElementById("edit_zone_leader_id").value = id;

    const leaderDropdown = document.getElementById("edit_Leader_FullName");
    for (let i = 0; i < leaderDropdown.options.length; i++) {
      if (leaderDropdown.options[i].value.trim() === name.trim()) {
        leaderDropdown.selectedIndex = i;
        break;
      }
    }

    updateEditContact();

    const zoneDropdown = document.getElementById("edit_Zone");
    for (let i = 0; i < zoneDropdown.options.length; i++) {
      if (zoneDropdown.options[i].textContent.trim() === zoneName.trim()) {
        zoneDropdown.selectedIndex = i;
        break;
      }
    }
  });
});
//   document.querySelectorAll(".update-status-btn").forEach(button => {
//     button.addEventListener("click", function (e) {
//       e.preventDefault();
//       const form = this.closest("form");

//       Swal.fire({
//         title: 'Update Status?',
//         text: "Are you sure you want to change this leader's status?",
//         icon: 'question',
//         showCancelButton: true,
//         confirmButtonColor: '#198754',
//         cancelButtonColor: '#6c757d',
//         confirmButtonText: 'Yes, update'
//       }).then((result) => {
//         if (result.isConfirmed) {
//           form.submit(); // 🔥 This must run
//         }
//       });
//     });
//   });
});
// ✅ OUTSIDE: Confirmation for status update
document.querySelectorAll(".update-status-btn").forEach(button => {
  button.addEventListener("click", function (e) {
    e.preventDefault();
    const form = this.closest("form");

    Swal.fire({
      title: 'Update Status?',
      text: "Are you sure you want to change this leader's status?",
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#198754',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, update'
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});


document.querySelector('#addZoneModal form').addEventListener('submit', function(e) {
  e.preventDefault();
  Swal.fire({
    title: 'Add Zone?',
    text: 'Proceed with adding this zone?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#198754',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'Yes, Add'
  }).then(result => {
    if (result.isConfirmed) this.submit();
  });
});

document.querySelector('#assignLeaderModal form').addEventListener('submit', function(e) {
  e.preventDefault();
  Swal.fire({
    title: 'Assign Zone Leader?',
    text: 'Proceed with assignment?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#198754',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'Yes, Assign'
  }).then(result => {
    if (result.isConfirmed) this.submit();
  });
});
</script>
</body>
</html>
