<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

// Disallow direct access
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// DB + session/timeout + logs
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';
$trigs = new Trigger();

// Keep redirect path (encrypted)
$redirect = enc_page('add_guidelines');

// Sanitizer that preserves newlines on render (via CSS white-space: pre-wrap)
function sanitize_input_preserve_newlines($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* -----------------------------
   DELETE
------------------------------ */
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);

    // Trigger for deletion
    $trigs->isDelete(19, $id);

    $stmt = $mysqli->prepare("DELETE FROM guidelines WHERE Id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Deleted!',
            text: 'Deleted Successfully!',
            timer: 1000,
            timerProgressBar: true,
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$redirect';
        });
    </script>";
    exit;
}

/* -----------------------------
   UPDATE
------------------------------ */
if (isset($_POST['update_id'])) {
    $id           = intval($_POST['update_id']);
    $cert_id      = intval($_POST['cert_id']);
    $desc         = trim($_POST['guide_description']);
    $requirements = sanitize_input_preserve_newlines($_POST['requirements'] ?? '');
    $custom_cert  = trim($_POST['custom_cert_name'] ?? '');
    $employee_id  = $_SESSION['employee_id'] ?? null; // ensure defined for trigger

    if ($cert_id === 0) {
        if ($custom_cert === '') {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Input',
                    text: 'Please enter a custom certificate name.',
                    timer: 1000,
                    timerProgressBar: true,
                    confirmButtonColor: '#d33'
                }).then(() => {
                    window.history.back();
                });
            </script>";
            exit;
        }
        $cert_name = htmlspecialchars($custom_cert);
    } else {
        $res = $mysqli->query("SELECT Certificates_Name FROM certificates WHERE Cert_Id = $cert_id");
        $cert_name = $res->fetch_assoc()['Certificates_Name'] ?? 'Unknown';
    }

    $final = "Guidelines - " . htmlspecialchars($desc);

    $stmt = $mysqli->prepare("UPDATE guidelines SET cert_id = ?, guide_description = ?, requirements = ? WHERE Id = ?");
    $stmt->bind_param("issi", $cert_id, $final, $requirements, $id);
    $stmt->execute();

    // Trigger edit (module 19)
    $trigs->isEdit(19, $id, $employee_id);

    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: 'Edited Successfully!',
            timer: 1000,
            timerProgressBar: true,
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$redirect';
        });
    </script>";
    exit;
}

/* -----------------------------
   ADD
------------------------------ */
if (isset($_POST['add_guideline'])) {
    $cert_id      = intval($_POST['cert_id']);
    $desc         = sanitize_input_preserve_newlines($_POST['guide_description']);
    $requirements = sanitize_input_preserve_newlines($_POST['requirements'] ?? '');
    $custom_cert  = sanitize_input_preserve_newlines($_POST['custom_cert_name'] ?? '');
    $employee_id  = $_SESSION['employee_id'];

    // Prevent duplicate guideline per certificate (except custom/0)
    if ($cert_id !== 0) {
        $check = $mysqli->prepare("SELECT COUNT(*) FROM guidelines WHERE cert_id = ? AND status = 1");
        $check->bind_param("i", $cert_id);
        $check->execute();
        $check->bind_result($exists);
        $check->fetch();
        $check->close();

        if ($exists > 0) {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Already Exists',
                    text: 'A guideline for this certificate already exists.',
                    timer: 1000,
                    timerProgressBar: true,
                    confirmButtonColor: '#d33'
                }).then(() => {
                    window.location.href = '$redirect';
                });
            </script>";
            exit;
        }
    }

    if ($cert_id === 0) {
        if ($custom_cert === '') {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Input',
                    text: 'Please enter a custom certificate name.',
                    timer: 1000,
                    timerProgressBar: true,
                    confirmButtonColor: '#d33'
                }).then(() => {
                    window.history.back();
                });
            </script>";
            exit;
        }
        $cert_name = htmlspecialchars($custom_cert);
    } else {
        $res = $mysqli->query("SELECT Certificates_Name FROM certificates WHERE Cert_Id = $cert_id");
        $cert_name = $res->fetch_assoc()['Certificates_Name'] ?? 'Unknown';
    }

    $final_desc = "Guidelines - " . htmlspecialchars($desc);

    $stmt = $mysqli->prepare(
        "INSERT INTO guidelines (cert_id, guide_description, requirements, status, employee_id)
         VALUES (?, ?, ?, 1, ?)"
    );
    $stmt->bind_param("issi", $cert_id, $final_desc, $requirements, $employee_id);
    $stmt->execute();
    $inserted_id = $stmt->insert_id;

    // Trigger add
    $trigs->isAdded(19, $inserted_id);

    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: 'Guideline added successfully.',
            timer: 1000,
            timerProgressBar: true,
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$redirect';
        });
    </script>";
    exit;
}

/* -----------------------------
   FETCH FOR DISPLAY
------------------------------ */
$result      = $mysqli->query("SELECT * FROM guidelines WHERE status = 1 ORDER BY cert_id ASC");
$certOptions = $mysqli->query("SELECT Cert_Id, Certificates_Name FROM certificates WHERE status = 'Active' ORDER BY Certificates_Name ASC");

// Track used certs
$usedCerts = [];
$usedRes   = $mysqli->query("SELECT cert_id FROM guidelines WHERE status = 1 AND cert_id <> 0");
while ($row = $usedRes->fetch_assoc()) {
    $usedCerts[] = $row['cert_id'];
}
?>
<!-- HTML BELOW -->

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="css/BrgyInfo/guidelines.css">
<style>
  /* Clamp long description to 4 lines; preserve newlines on render */
  .text-clamp-4{
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
    white-space: pre-wrap;
  }
  /* Modal body text preserves line breaks and wraps */
  #vd-desc, #vd-req { white-space: pre-wrap; }
</style>

<div class="container py-5">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <h3 class="fw-bold mb-0">📜 Guideline List</h3>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGuidelineModal">
        + Add Guideline
      </button>
    </div>

    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light text-center">
            <tr>
              <th style="width: 250px;">Certificate</th>
              <th style="width: 250px;">Description</th>
              <th style="width: 250px;">Requirements</th>
              <th style="width: 250px;">Created</th>
              <th style="width: 250px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php while ($row = $result->fetch_assoc()):
              if ($row['cert_id'] == 0) {
                  $certName = 'Custom';
              } else {
                  $certRes  = $mysqli->query("SELECT Certificates_Name FROM certificates WHERE Cert_Id = " . (int)$row['cert_id']);
                  $certName = $certRes->fetch_assoc()['Certificates_Name'] ?? 'Unknown';
              }
              // Remove the 'Guidelines - ' prefix from the stored description
              $cleaned = preg_replace('/^Guidelines - /', '', $row['guide_description']);
              $prefill_custom = ($row['cert_id'] == 0) ? $certName : '';

              // Decide if we show "View details" (based on length or line count)
              $isLongDesc  = (mb_strlen($cleaned) > 280) || (substr_count($cleaned, "\n") > 4);
              $descPreview = $cleaned; // CSS clamp will visually limit
          ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($certName) ?></td>

              <!-- Description with clamp + view details -->
              <td>
                <div class="text-clamp-4"><?= htmlspecialchars($descPreview) ?></div>
                <?php if ($isLongDesc): ?>
                  <button
                    type="button"
                    class="btn btn-link p-0 mt-1 small"
                    data-bs-toggle="modal"
                    data-bs-target="#viewDetailsModal"
                    data-title="<?= htmlspecialchars($certName) ?>"
                    data-desc="<?= htmlspecialchars($cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    data-req="<?= htmlspecialchars($row['requirements'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    data-created="<?= htmlspecialchars($row['created_at'] ?? '') ?>"
                  >
                    View details
                  </button>
                <?php endif; ?>
              </td>

              <!-- Requirements (plain, wrapped) -->
              <td style="white-space: pre-wrap;"><?= htmlspecialchars($row['requirements']) ?></td>

              <td class="text-nowrap"><?= $row['created_at'] ?></td>

              <td class="text-center">
                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['Id'] ?>">✏️</button>
                <button type="button" class="btn btn-sm btn-danger swal-delete-btn" data-id="<?= $row['Id'] ?>">🗑️</button>
              </td>
            </tr>

            <!-- Edit Modal -->
            <div class="modal fade" id="editModal<?= $row['Id'] ?>" tabindex="-1">
              <div class="modal-dialog modal-dialog-centered">
                <form class="edit-guideline-form" method="POST" action="<?= enc_page('add_guidelines'); ?>">
                  <input type="hidden" name="update_id" value="<?= $row['Id'] ?>">
                  <div class="modal-content">
                    <div class="modal-header bg-light">
                      <h5 class="modal-title">Edit Guideline</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <!-- Certificate select -->
                      <div class="mb-3">
                        <label class="form-label">Certificate</label>
                        <select name="cert_id" class="form-select" onchange="toggleCustomCert(this)">
                          <option value="">-- Select Certificate --</option>
                          <option value="0" <?= $row['cert_id'] == 0 ? 'selected' : '' ?>>Others</option>
                          <?php
                          mysqli_data_seek($certOptions, 0);
                          while ($cert = $certOptions->fetch_assoc()):
                              $disabled = in_array($cert['Cert_Id'], $usedCerts) && $cert['Cert_Id'] != $row['cert_id'];
                          ?>
                            <option value="<?= $cert['Cert_Id'] ?>" <?= $cert['Cert_Id'] == $row['cert_id'] ? 'selected' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                              <?= htmlspecialchars($cert['Certificates_Name']) ?>
                            </option>
                          <?php endwhile; ?>
                        </select>
                      </div>

                      <!-- Custom cert input -->
                      <div class="mb-3 <?= $row['cert_id'] == 0 ? '' : 'd-none' ?> custom-cert-group">
                        <label class="form-label">Custom Certificate Name</label>
                        <input type="text" name="custom_cert_name" class="form-control" value="<?= htmlspecialchars($prefill_custom) ?>">
                      </div>

                      <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="guide_description" class="form-control" rows="5" required><?= htmlspecialchars($cleaned) ?></textarea>
                      </div>

                      <div class="mb-3">
                        <label class="form-label">Requirements</label>
                        <textarea name="requirements" class="form-control" rows="3"><?= htmlspecialchars($row['requirements']) ?></textarea>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-success confirm-edit-btn">💾 Save</button>
                      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Reusable View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">Guideline Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="mb-2">
          <div class="small text-muted">Certificate</div>
          <div id="vd-cert" class="fw-semibold"></div>
        </div>

        <div class="mb-3">
          <div class="small text-muted">Description</div>
          <div id="vd-desc"></div>
        </div>

        <div class="mb-2">
          <div class="small text-muted">Requirements</div>
          <div id="vd-req"></div>
        </div>

        <div class="text-end small text-muted">
          <span id="vd-created"></span>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden delete form -->
<form id="swal-delete-form" method="POST" action="<?= enc_page('add_guidelines'); ?>" style="display: none;">
  <input type="hidden" name="delete_id" id="swal-delete-id">
</form>

<!-- Add Modal -->
<div class="modal fade" id="addGuidelineModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="add-guideline-form" method="POST" action="<?= enc_page('add_guidelines'); ?>">
      <input type="hidden" name="add_guideline" value="1">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add Guideline</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Certificate</label>
            <select name="cert_id" class="form-select" onchange="toggleCustomCert(this)" required>
              <option value="">-- Select Certificate --</option>
              <!-- <option value="0">Others</option> -->
              <?php
              mysqli_data_seek($certOptions, 0);
              while ($cert = $certOptions->fetch_assoc()):
                  $disabled = in_array($cert['Cert_Id'], $usedCerts);
              ?>
                <option value="<?= $cert['Cert_Id'] ?>" <?= $disabled ? 'disabled' : '' ?>>
                  <?= htmlspecialchars($cert['Certificates_Name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="mb-2 d-none custom-cert-group">
            <label class="form-label">Custom Certificate Name</label>
            <input type="text" name="custom_cert_name" class="form-control">
          </div>

          <div class="mb-2">
            <label class="form-label">Description</label>
            <textarea name="guide_description" class="form-control" rows="6"></textarea>
          </div>

          <div class="mb-2">
            <label class="form-label">Requirements</label>
            <textarea name="requirements" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-success" id="confirm-add-btn">Save</button>
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCustomCert(selectEl) {
  const modalBody = selectEl.closest('.modal-body') ?? document;
  const customDiv = modalBody.querySelector('.custom-cert-group');
  if (!customDiv) return;

  if (selectEl.value === '0') {
    customDiv.classList.remove('d-none');
  } else {
    customDiv.classList.add('d-none');
    const input = customDiv.querySelector('input');
    if (input) input.value = '';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  // Delete confirmation
  document.querySelectorAll('.swal-delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      Swal.fire({
        title: 'Are you sure?',
        text: "This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d'
      }).then((result) => {
        if (result.isConfirmed) {
          document.getElementById('swal-delete-id').value = id;
          document.getElementById('swal-delete-form').submit();
        }
      });
    });
  });

  // Add guideline confirmation
  const addBtn = document.getElementById('confirm-add-btn');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      Swal.fire({
        icon: 'question',
        title: 'Add Guideline?',
        text: "Do you want to save this new guideline?",
        showCancelButton: true,
        confirmButtonText: 'Yes, save it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#6c757d'
      }).then(result => {
        if (result.isConfirmed) {
          document.getElementById('add-guideline-form').submit();
        }
      });
    });
  }

  // Edit guideline confirmation
  document.querySelectorAll('.confirm-edit-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const form = this.closest('.modal').querySelector('form');
      Swal.fire({
        icon: 'question',
        title: 'Save Changes?',
        text: "Do you want to save the changes to this guideline?",
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#6c757d'
      }).then(result => {
        if (result.isConfirmed && form) {
          form.submit();
        }
      });
    });
  });

  // Fill the reusable "View details" modal on open
  const detailsModal = document.getElementById('viewDetailsModal');
  if (detailsModal) {
    detailsModal.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      if (!btn) return;

      const cert    = btn.getAttribute('data-title')   || '—';
      const desc    = btn.getAttribute('data-desc')    || '—';
      const req     = btn.getAttribute('data-req')     || '—';
      const created = btn.getAttribute('data-created') || '';

      detailsModal.querySelector('#vd-cert').textContent    = cert;
      detailsModal.querySelector('#vd-desc').textContent    = desc;
      detailsModal.querySelector('#vd-req').textContent     = req;
      detailsModal.querySelector('#vd-created').textContent = created;
    });
  }
});
</script>
