<?php
// ======================================================================
// barangay_officials.php
// List/Add/Edit Barangay Officials with Photo & e-Signature (LONGBLOB)
// ======================================================================

declare(strict_types=1);

ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

include 'class/session_timeout.php';
include_once 'logs/logs_trig.php';
$trigs = new Trigger();

// ---------------------------------------------------
// Helpers
// ---------------------------------------------------
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}

/** Read an uploaded image (optional). Returns [content|null, mime|null] or exits on invalid type. */
function read_image_if_any(string $field): array {
    if (!isset($_FILES[$field]) || empty($_FILES[$field]['tmp_name'])) {
        return [null, null];
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $mime = @mime_content_type($tmp) ?: '';
    $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($mime, $allowed, true)) {
        echo "<script>
            Swal.fire({icon:'error', title:'Invalid File Type',
            text:'Only JPG and PNG are allowed for images.',
            confirmButtonColor:'#dc3545'});
        </script>";
        exit;
    }
    $content = file_get_contents($tmp);
    return [$content, $mime];
}

// ---------------------------------------------------
// Fetch Residents (not deleted) for dropdown
// ---------------------------------------------------
$residents = [];
$sql = "SELECT CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name, ' ', IFNULL(suffix_name, '')) AS full_name, id
        FROM residents
        WHERE resident_delete_status = 0";
if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $residents[] = ['full_name' => (string)$row['full_name'], 'id' => (int)$row['id']];
    }
    $res->close();
}

// ---------------------------------------------------
// Fetch Roles for dropdown
// ---------------------------------------------------
$roles = [];
$sql_roles = "SELECT Role_Name FROM employee_roles";
if ($res = $mysqli->query($sql_roles)) {
    while ($row = $res->fetch_assoc()) {
        $roles[] = (string)$row['Role_Name'];
    }
    $res->close();
}

// ---------------------------------------------------
// Pagination
// ---------------------------------------------------
$limit  = 10;
$page   = isset($_GET['pagenum']) ? max(1, (int)$_GET['pagenum']) : 1;
$offset = ($page - 1) * $limit;

// ---------------------------------------------------
// Status Change (GET)
// ---------------------------------------------------
if (isset($_GET['id'], $_GET['status'])) {
    $id = (int)$_GET['id'];
    $status = ($_GET['status'] === 'active') ? 'active' : 'inactive';

    $upd = $mysqli->prepare("UPDATE barangay_information SET status=? WHERE id=?");
    $upd->bind_param('si', $status, $id);
    if ($upd->execute()) {
        $trigs->isStatusChange(12, $id); // log status change
        echo "<script>
            Swal.fire({
                icon:'success', title:'Updated',
                text:'Status updated successfully!', confirmButtonColor:'#198754'
            }).then(()=>{ window.location.href = '{$redirects['barangay_officials']}'; });
        </script>";
        exit;
    } else {
        echo "<script>
            Swal.fire({icon:'error', title:'Update Failed',
            text: " . json_encode($mysqli->error) . ", confirmButtonColor:'#dc3545'});
        </script>";
        exit;
    }
}

// ---------------------------------------------------
// ADD / EDIT (POST)
// ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action_type'] ?? 'add';
    $position   = sanitize_input($_POST['position'] ?? '');
    $fullName   = sanitize_input($_POST['fullName'] ?? '');
    $residentId = (int)explode('-', $fullName)[0]; // expects "ID-Full Name"
    $now        = date('Y-m-d H:i:s');

    [$photoContent, $photoMime] = read_image_if_any('photo');       // optional on edit; required on add
    [$esignContent, $esignMime] = read_image_if_any('esignature');  // optional

    if ($action === 'add') {
        if ($residentId <= 0 || $position === '') {
            echo "<script>
                Swal.fire({icon:'error', title:'Missing Data',
                text:'Please complete the form.', confirmButtonColor:'#dc3545'});
            </script>";
            exit;
        }
        if ($photoContent === null || !$photoMime) {
            echo "<script>
                Swal.fire({icon:'error', title:'Photo Required',
                text:'Please upload a JPEG/PNG photo.', confirmButtonColor:'#dc3545'});
            </script>";
            exit;
        }

        $sql = "INSERT INTO barangay_information
                   (official_id, date_created, position, photo, photo_mime, esignature, esignature_mime)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $st = $mysqli->prepare($sql);
        // types: i s s b s b s
        $null = null;
        $st->bind_param('issbsbs',
            $residentId, $now, $position,
            $null, $photoMime,
            $null, $esignMime
        );
        // Send blobs
        $st->send_long_data(3, $photoContent); // photo
        if ($esignContent !== null) {
            $st->send_long_data(5, $esignContent); // esign
        }
        $ok = $st->execute();
        $newId = $mysqli->insert_id;
        $st->close();

        if ($ok) {
            $trigs->isAdded(12, (int)$newId);
            echo "<script>
                Swal.fire({icon:'success', title:'Success',
                text:'Barangay official added successfully!',
                confirmButtonColor:'#198754'}).then(()=> {
                    window.location.href = '{$redirects['barangay_officials']}';
                });
            </script>";
            exit;
        } else {
            echo "<script>
                Swal.fire({icon:'error', title:'Insert Failed',
                text: " . json_encode($mysqli->error) . ", confirmButtonColor:'#dc3545'});
            </script>";
            exit;
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['record_id'] ?? 0);
        if ($id <= 0 || $residentId <= 0 || $position === '') {
            echo "<script>
                Swal.fire({icon:'error', title:'Invalid Request',
                text:'Missing record id or fields.', confirmButtonColor:'#dc3545'});
            </script>";
            exit;
        }

        // Build dynamic UPDATE based on which files were provided
        $sets  = ["official_id=?", "position=?"];
        $types = "is";
        $binds = [$residentId, $position];

        $hasPhoto = ($photoContent !== null && $photoMime);
        $hasEsign = ($esignContent !== null && $esignMime);

        if ($hasPhoto) {
            $sets[]  = "photo=?";
            $sets[]  = "photo_mime=?";
            $types  .= "bs";
            $binds[] = null;      // blob placeholder
            $binds[] = $photoMime;
        }
        if ($hasEsign) {
            $sets[]  = "esignature=?";
            $sets[]  = "esignature_mime=?";
            $types  .= "bs";
            $binds[] = null;      // blob placeholder
            $binds[] = $esignMime;
        }

        $types .= "i";
        $binds[] = $id;

        $sql = "UPDATE barangay_information SET " . implode(", ", $sets) . " WHERE id=?";
        $st = $mysqli->prepare($sql);

        // Bind params dynamically
        $refs = [];
        $refs[] = &$types;
        foreach ($binds as $k => $v) { $refs[] = &$binds[$k]; }
        call_user_func_array([$st, 'bind_param'], $refs);

        // Send blobs in order they appear
        $paramIndex = 0;
        for ($i = 0, $n = strlen($types); $i < $n; $i++) {
            $paramIndex++;
            if ($types[$i] === 'b') {
                // Determine which blob this is by checking previous appended order
                static $blobCount = 0;
                $blobCount++;
                if ($blobCount === 1 && $hasPhoto) {
                    $st->send_long_data($paramIndex - 1, $photoContent);
                } else {
                    // If first blob wasn't photo (means photo not set), this is esign
                    if ($hasEsign) {
                        $st->send_long_data($paramIndex - 1, $esignContent);
                    }
                }
            }
        }

        $ok = $st->execute();
        $st->close();
        if ($ok) {
            $trigs->isUpdated(12, $id);
            echo "<script>
                Swal.fire({icon:'success', title:'Updated',
                text:'Official details updated successfully!',
                confirmButtonColor:'#198754'}).then(()=> {
                    window.location.href = '{$redirects['barangay_officials']}';
                });
            </script>";
            exit;
        } else {
            echo "<script>
                Swal.fire({icon:'error', title:'Update Failed',
                text: " . json_encode($mysqli->error) . ", confirmButtonColor:'#dc3545'});
            </script>";
            exit;
        }
    }
}

// ---------------------------------------------------
// Query for display (with MIME columns for correct data URLs)
// ---------------------------------------------------
$sql_display = "SELECT r.first_name, r.last_name,
                       b.position, b.status, b.id,
                       b.photo, b.photo_mime,
                       b.esignature, b.esignature_mime,
                       r.id AS resident_id
                FROM barangay_information b
                JOIN residents r ON b.official_id = r.id
                ORDER BY b.id ASC
                LIMIT ? OFFSET ?";
$st = $mysqli->prepare($sql_display);
$st->bind_param('ii', $limit, $offset);
$st->execute();
$result_display = $st->get_result();
$rows = [];
while ($row = $result_display->fetch_assoc()) { $rows[] = $row; }
$st->close();

// Count for pagination
$sql_count = "SELECT COUNT(*) AS total
              FROM barangay_information b
              JOIN residents r ON b.official_id = r.id";
$total_records = 0;
if ($res = $mysqli->query($sql_count)) {
    $total_records = (int)$res->fetch_assoc()['total'];
    $res->close();
}
$total_pages = (int)ceil($total_records / $limit);

// Base URL for pagination
$baseUrl = $redirects['barangay_officials'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Barangay Officials</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Select2 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
  <!-- External CSS -->
  <link rel="stylesheet" href="css/BrgyInfo/brgyOff.css">
</head>
<body>
<div class="container py-4 py-sm-5">
  <!-- Header / Toolbar -->
<div class="page-header-grid">
  <div class="header-left">
    <h2 class="title mb-1">Barangay Officials</h2>
    <p class="mb-0 text-muted">Manage profiles, photos, and e-signatures.</p>
  </div>

  <div class="header-right">
    <div class="input-group search-input">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input id="tableFilter" type="search" class="form-control" placeholder="Search name or position…" aria-label="Search" />
    </div>
    <button class="btn btn-dark btn-add" data-bs-toggle="modal" data-bs-target="#addOfficialModal">
      <i class="bi bi-plus-lg me-1"></i> Add Official
    </button>
  </div>
</div>

  <!-- Table Card -->
  <div class="card card-surface shadow-sm">
    <div class="table-responsive p-2 p-sm-3" style="max-height:60vh;overflow:auto;">
      <table class="table align-middle mb-0 table-hover" id="officialsTable">
        <thead>
          <tr>
            <th style="width:38%">Full Name</th>
            <th style="width:22%">Position</th>
            <th style="width:16%">Photo</th>
            <th style="width:12%">Status</th>
            <th style="width:22%" class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $isActive = ($row['status'] === 'active');
                $statusIcon  = $isActive ? 'check-circle-fill' : 'slash-circle';
                $statusText  = ucfirst((string)$row['status']);
                $badgeClass  = $isActive ? 'bg-success-subtle text-success' : 'text-danger';
                $new_status  = $isActive ? 'inactive' : 'active';
                $pmime = $row['photo_mime'] ?: 'image/jpeg';

                $photoTag = !empty($row['photo'])
                    ? '<img src="data:' . htmlspecialchars($pmime) . ';base64,' . base64_encode($row['photo']) . '" class="avatar" alt="Photo" />'
                    : '<span class="text-muted">No photo</span>';

                $fullNameDisp = htmlspecialchars($row['first_name'] . ' ' . $row['last_name'], ENT_QUOTES, 'UTF-8');
              ?>
              <tr
                data-id="<?= (int)$row['id'] ?>"
                data-resident-id="<?= (int)$row['resident_id'] ?>"
                data-position="<?= htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8') ?>"
                data-fullname="<?= $fullNameDisp ?>"
              >
                <td data-label="Full Name"><?= $fullNameDisp ?></td>
                <td data-label="Position"><?= htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Photo"><?= $photoTag ?></td>
                <td data-label="Status">
                  <span class="badge status-badge <?= $badgeClass ?>">
                    <i class="bi bi-<?= $statusIcon ?> me-1"></i><?= $statusText ?>
                  </span>
                </td>
                <td data-label="Action">
                  <div class="action-stack justify-content-lg-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            onclick="openEditModal(this)" aria-label="Edit official">
                      <i class="bi bi-pencil-square me-1"></i>Edit
                    </button>
                    <a href="<?= $baseUrl ?>&id=<?= (int)$row['id'] ?>&status=<?= $new_status ?>"
                       class="btn btn-outline-primary btn-sm"
                       onclick="return confirmStatusChange(event, this);">
                      <i class="bi bi-arrow-repeat me-1"></i>Status
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="text-center py-4">No officials found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
      <?php if ($page > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $page - 1; ?>">Previous</a></li>
      <?php endif; ?>

      <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <li class="page-item <?= ($i === $page) ? 'active' : ''; ?>">
          <a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $i; ?>"><?= $i; ?></a>
        </li>
      <?php endfor; ?>

      <?php if ($page < $total_pages): ?>
        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $page + 1; ?>">Next</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</div>

<!-- ===================== ADD MODAL ===================== -->
<div class="modal fade" id="addOfficialModal" tabindex="-1" aria-labelledby="addOfficialModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addOfficialModalLabel">Add New Official</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="barangayForm" method="POST" enctype="multipart/form-data" onsubmit="return confirmSubmission(event)">
          <input type="hidden" name="action_type" value="add">

          <div class="mb-3">
            <label for="fullName" class="form-label">Full Name</label>
            <select class="form-select" id="fullName" name="fullName" required style="width: 100%; z-index: 1050;">
              <option value="">Select Full Name</option>
              <?php foreach ($residents as $resident): ?>
                <option value="<?= (int)$resident['id'] ?>-<?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="position" class="form-label">Position</label>
            <select class="form-select" id="position" name="position" required>
              <option value="">Select Position</option>
              <?php foreach ($roles as $role): ?>
                <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label for="photo" class="form-label">Upload Photo (JPEG/PNG)</label>
              <input type="file" class="form-control" id="photo" name="photo" accept=".jpg,.jpeg,.png" required>
              <small class="text-muted">Clear face, 1:1 aspect recommended.</small>
            </div>
            <div class="col-sm-6">
              <label for="esignature" class="form-label">Upload e-Signature (optional)</label>
              <input type="file" class="form-control" id="esignature" name="esignature" accept=".jpg,.jpeg,.png">
              <small class="text-muted">Transparent PNG looks best.</small>
            </div>
          </div>

          <div class="mt-3 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark">Submit</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===================== EDIT MODAL ===================== -->
<div class="modal fade" id="editOfficialModal" tabindex="-1" aria-labelledby="editOfficialModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editOfficialModalLabel">Edit Official</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editBarangayForm" method="POST" enctype="multipart/form-data" onsubmit="return confirmEditSubmission(event)">
          <input type="hidden" name="action_type" value="edit">
          <input type="hidden" name="record_id" id="edit_record_id">

          <div class="mb-3">
            <label for="edit_fullName" class="form-label">Full Name (link to resident)</label>
            <select class="form-select" id="edit_fullName" name="fullName" required style="width: 100%; z-index: 1050;">
              <option value="">Select Full Name</option>
              <?php foreach ($residents as $resident): ?>
                <option value="<?= (int)$resident['id'] ?>-<?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="edit_position" class="form-label">Position</label>
            <select class="form-select" id="edit_position" name="position" required>
              <option value="">Select Position</option>
              <?php foreach ($roles as $role): ?>
                <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Current Photo</label>
              <div id="currentPhotoPreview" class="border rounded p-2 text-center bg-white">
                <em class="text-muted">No preview</em>
              </div>
              <label for="edit_photo" class="form-label mt-2">Replace Photo (JPEG/PNG)</label>
              <input type="file" class="form-control" id="edit_photo" name="photo" accept=".jpg,.jpeg,.png">
              <small class="text-muted">Leave empty to keep existing photo.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Current e-Signature</label>
              <div id="currentEsignPreview" class="border rounded p-2 text-center bg-white">
                <em class="text-muted">No preview</em>
              </div>
              <label for="edit_esignature" class="form-label mt-2">Upload/Replace e-Signature (JPEG/PNG)</label>
              <input type="file" class="form-control" id="edit_esignature" name="esignature" accept=".jpg,.jpeg,.png">
              <small class="text-muted">Leave empty to keep existing signature.</small>
            </div>
          </div>

          <div class="mt-3 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Scripts: Bootstrap, SweetAlert2, Select2, jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
// Init Select2 for both modals
$(document).ready(function() {
  $('#fullName').select2({
    placeholder: "Search for a resident",
    allowClear: true, width: '100%', dropdownAutoWidth: true,
    dropdownParent: $('#addOfficialModal')
  });
  $('#edit_fullName').select2({
    placeholder: "Search for a resident",
    allowClear: true, width: '100%', dropdownAutoWidth: true,
    dropdownParent: $('#editOfficialModal')
  });
});

// Client-side filter for table
const filterInput = document.getElementById('tableFilter');
const table = document.getElementById('officialsTable');
filterInput?.addEventListener('input', () => {
  const q = filterInput.value.toLowerCase();
  const rows = table.querySelectorAll('tbody tr');
  rows.forEach(r => {
    const name = (r.getAttribute('data-fullname') || '').toLowerCase();
    const pos  = (r.getAttribute('data-position') || '').toLowerCase();
    r.style.display = (name.includes(q) || pos.includes(q)) ? '' : 'none';
  });
});

// Confirm Add
function confirmSubmission(e) {
  e.preventDefault();
  Swal.fire({
    title: 'Confirm Submission',
    text: "Do you want to add this official?",
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, submit',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#000'
  }).then((result) => {
    if (result.isConfirmed) {
      document.getElementById('barangayForm').submit();
    }
  });
  return false;
}

// Confirm Status Change
function confirmStatusChange(e, link) {
  e.preventDefault();
  const url = link.getAttribute('href');
  Swal.fire({
    title: 'Confirm Status Change',
    text: "Are you sure you want to change the status?",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, change it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#000',
    cancelButtonColor: '#6c757d'
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = url;
    }
  });
  return false;
}

// Open Edit Modal and populate fields
function openEditModal(btn) {
  const tr = btn.closest('tr');
  const id = tr.getAttribute('data-id');
  const residentId = tr.getAttribute('data-resident-id');
  const position = tr.getAttribute('data-position');

  // Record id
  document.getElementById('edit_record_id').value = id;

  // Select2: set resident (value format "ID-Full Name")
  const editFull = $('#edit_fullName');
  const optionVal = editFull.find('option').toArray()
    .map(o => o.value)
    .find(v => v && v.startsWith(residentId + '-'));
  if (optionVal) editFull.val(optionVal).trigger('change');

  // Position
  document.getElementById('edit_position').value = position;

  // Photo preview: reuse table image
  const img = tr.querySelector('td:nth-child(3) img');
  const photoContainer = document.getElementById('currentPhotoPreview');
  if (img && img.src) {
    photoContainer.innerHTML = `<img src="${img.src}" alt="Current Photo" class="img-fluid" style="max-height:180px;object-fit:contain;">`;
  } else {
    photoContainer.innerHTML = `<em class="text-muted">No preview</em>`;
  }

  // Signature preview from endpoint
  const esignContainer = document.getElementById('currentEsignPreview');
  esignContainer.innerHTML =
    `<img src="components/employee_modal/showall_sig.php?type=barangay&id=${id}&t=${Date.now()}"
          alt="Current e-Signature"
          onerror="this.replaceWith(document.createElement('em')); this.parentNode.innerHTML='<em class=&quot;text-muted&quot;>No signature</em>';"
          class="img-fluid" style="max-height:180px;object-fit:contain;">`;

  // Show modal
  const modal = new bootstrap.Modal(document.getElementById('editOfficialModal'));
  modal.show();
}

// Confirm Edit
function confirmEditSubmission(e) {
  e.preventDefault();
  Swal.fire({
    title: 'Save Changes?',
    text: "Update this official's details?",
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, save',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#000'
  }).then((result) => {
    if (result.isConfirmed) {
      document.getElementById('editBarangayForm').submit();
    }
  });
  return false;
}
</script>
</body>
</html>
