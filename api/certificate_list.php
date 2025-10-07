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
// include 'include/redirects.php';
include 'class/session_timeout.php';
include_once 'logs/logs_trig.php';
$trigs = new Trigger();

$redirect = enc_page('certificate_list');
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}
$responseScript = '';
$loggedInEmployeeId = $_SESSION['employee_id'] ?? null;

// ✅ Add certificate
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_certificate'])) {
    $certName = sanitizeInput($_POST['Certificates_Name']);
    if ($loggedInEmployeeId && !empty($certName)) {
        $check = $mysqli->prepare("SELECT 1 FROM certificates WHERE Certificates_Name = ?");
        $check->bind_param("s", $certName);
        $check->execute();
        $check->store_result();

        if ($check->num_rows === 0) {
            $stmt = $mysqli->prepare("INSERT INTO certificates (Certificates_Name, Employee_Id) VALUES (?, ?)");
            $stmt->bind_param("si", $certName, $loggedInEmployeeId);
            $stmt->execute();
            $trigs->isAdded(15, $mysqli->insert_id);
            $stmt->close();
            $responseScript =  "<script>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: 'Certificate successfully added!',
                confirmButtonColor: '#198754'
            }).then(() => {
                window.location.href = '$redirect';
            });
            </script>";
        } else {
            $responseScript =  "<script>
            Swal.fire({
                icon: 'error',
                title: 'Duplicate',
                text: 'This certificate already exists.',
                confirmButtonColor: '#d33'
            });
            </script>";

        }
        $check->close();
    } else {
        echo "<script>alert('Missing employee session or certificate name.');</script>";
    }
}

// ✅ Add purpose
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_purpose'])) {
    $purposeName = sanitizeInput($_POST['purpose_name']);
    $certId = (int) $_POST['cert_id'];

    if ($loggedInEmployeeId && !empty($purposeName) && $certId > 0) {
        $stmt = $mysqli->prepare("INSERT INTO purposes (purpose_name, cert_id, employee_id) VALUES (?, ?, ?)");
        $stmt->bind_param("sii", $purposeName, $certId, $loggedInEmployeeId);
        $stmt->execute();
        $trigs->isAdded(16, $mysqli->insert_id);
        $stmt->close();
        $responseScript =  "<script>
        Swal.fire({
            icon: 'success',
            title: 'Added',
            text: 'Purpose successfully added!',
            confirmButtonColor: '#198754'
        }).then(() => {
            window.location.href = '$redirect';
        });
        </script>";

    } else {
        $responseScript =  "<script>
        Swal.fire({
            icon: 'warning',
            title: 'Missing Info',
            text: 'Missing data for adding purpose.',
            confirmButtonColor: '#ffc107'
        });
        </script>";
    }
}

// ✅ Update certificate status
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cert_id'], $_POST['new_status'])) {

    $certId = $_POST['cert_id'];
    $newStatus = $_POST['new_status'];
    $stmt = $mysqli->prepare("UPDATE certificates SET status = ? WHERE Cert_Id = ?");
    $stmt->bind_param("si", $newStatus, $certId);
    $stmt->execute();
    $trigs->isStatusChange(15, $certId);
    $stmt->close();
    $responseScript =  "<script>
        Swal.fire({
            icon: 'success',
            title: 'Status Updated',
            text: 'Certificate status changed.',
            confirmButtonColor: '#0d6efd'
        }).then(() => {
           window.location.href = '$redirect';
        });
        </script>";
}

// ✅ Update purpose status
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['purpose_id'], $_POST['new_status'])) {
    $purposeId = $_POST['purpose_id'];
    $newStatus = $_POST['new_status'];
    $stmt = $mysqli->prepare("UPDATE purposes SET status = ? WHERE purpose_id = ?");
    $stmt->bind_param("si", $newStatus, $purposeId);
    $stmt->execute();
    $trigs->isStatusChange(16, $purposeId);  
    $stmt->close();
    $responseScript =  "<script>
    Swal.fire({
        icon: 'success',
        title: 'Status Updated',
        text: 'Purpose status changed.',
        confirmButtonColor: '#0d6efd'
    }).then(() => {
        window.location.href = '$redirect';
    });
    </script>";

}
?>
  <!--<link rel="stylesheet" href="css/styles.css">-->
  <link rel="stylesheet" href="css/BrgyInfo/certList.css">
<div class="container mt-5">
    <h3 class="mb-4">📜 Certificate List</h3>

    <!-- Buttons -->
    <div class="mb-3 d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCertificateModal">
            ➕ Add Certificate
        </button>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPurposeModal">
            ➕ Add Purpose
        </button>
    </div>
<div class="table-responsive w-100" style="height: 500px; overflow-y: auto;">
    <table class="table table-hover table-sm table-striped border w-100 mb-0">
        <thead class="table-primary">
            <tr>
                <th style="width: 300px;">Certificate Name</th>
                <th style="width: 300px;">Employee ID</th>
                <th style="width: 300px;">Created At</th>
                <th style="width: 300px;">Status</th>
                <th style="width: 100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $result = $mysqli->query("SELECT * FROM certificates");
            $modals = '';

            while ($row = $result->fetch_assoc()):
            ?>
            <tr>
                <td><?= htmlspecialchars($row['Certificates_Name']) ?></td>
                <td><?= $row['Employee_Id'] ?></td>
                <td><?= $row['Created_at'] ?></td>
                <td>
                    <span class="badge px-3 py-2 fw-semibold <?= $row['status'] === 'Active' ? 'bg-success text-white' : 'bg-danger text-white' ?>">

                        <?= $row['status'] ?>
                    </span>
                </td>
                <td class="text-center">
                <button type="button" 
                        class="btn btn-info btn-sm" 
                        data-bs-toggle="modal" 
                        data-bs-target="#viewPurposesModal<?= $row['Cert_Id'] ?>"
                        title="View Purpose" 
                        aria-label="View Purpose">
                    <i class="bi-eye-fill"></i>
                </button>

                <form method="POST" class="d-inline" onsubmit="return confirmStatusChange(event, this, 'certificate')">
                  <input type="hidden" name="cert_id" value="<?= $row['Cert_Id'] ?>">
                  <input type="hidden" name="new_status" value="<?= $row['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                <button type="submit" 
                        name="update_status" 
                        class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-danger' : 'btn-success' ?>"
                        title="<?= $row['status'] === 'Active' ? 'Deactivate Certificate' : 'Activate Certificate' ?>"
                        aria-label="<?= $row['status'] === 'Active' ? 'Deactivate Certificate' : 'Activate Certificate' ?>">
                    <i class="bi <?= $row['status'] === 'Active' ? 'bi-eye-slash-fill' : 'bi-eye-fill' ?>"></i>
                </button>
                </form>

                </td>
            </tr>

            <?php
            ob_start(); ?>
            <div class="modal fade" id="viewPurposesModal<?= $row['Cert_Id'] ?>" tabindex="-1" aria-labelledby="viewPurposesModalLabel<?= $row['Cert_Id'] ?>" aria-hidden="true">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="viewPurposesModalLabel<?= $row['Cert_Id'] ?>">Purposes for "<?= htmlspecialchars($row['Certificates_Name']) ?>"</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body" style="max-height: 600px; overflow-y: auto;">
    <table class="table table-hover table-bordered align-middle text-center">

                        <thead class="table-light fw-semibold text-uppercase">

                            <tr>
                                <th>#</th>
                                <th>Purpose Name</th>
                                <th>Employee ID</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $certId = $row['Cert_Id'];
                            $purposeQuery = $mysqli->prepare("SELECT * FROM purposes WHERE cert_id = ?");
                            $purposeQuery->bind_param("i", $certId);
                            $purposeQuery->execute();
                            $purposeResult = $purposeQuery->get_result();
                            $counter = 1;
                            if ($purposeResult->num_rows > 0):
                                while ($purpose = $purposeResult->fetch_assoc()):
                            ?>
                            <tr>
                                <td><?= $counter++ ?></td>
                                <td><?= htmlspecialchars($purpose['purpose_name']) ?></td>
                                <td><?= $purpose['employee_id'] ?></td>
                               <td>
    <span class="badge px-3 py-2 fw-semibold <?= $purpose['status'] === 'active' ? 'bg-success text-white' : 'bg-danger text-white' ?>">
        <?= ucfirst($purpose['status']) ?>
    </span>
</td>

                                <td><?= $purpose['created_at'] ?></td>
                                <td>
                                   <form method="POST" class="d-inline" onsubmit="return confirmStatusChange(event, 'purpose')">
                                      <input type="hidden" name="purpose_id" value="<?= $purpose['purpose_id'] ?>">
                                      <input type="hidden" name="new_status" value="<?= $purpose['status'] === 'active' ? 'inactive' : 'active' ?>">
                                      <button type="submit" name="update_purpose_status" class="btn btn-sm <?= $purpose['status'] === 'active' ? 'btn-danger' : 'btn-success' ?>">
                                          <?= $purpose['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                      </button>
                                  </form>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" class="text-center">No purposes found for this certificate.</td></tr>
                            <?php endif; $purposeQuery->close(); ?>
                        </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
            <?php
            $modals .= ob_get_clean();
            endwhile;
            ?>
        </tbody>
    </table>
    </div>

    <?= $modals ?>
</div>


<!-- Add Certificate Modal -->
<div class="modal fade" id="addCertificateModal" tabindex="-1" aria-labelledby="addCertificateModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content" onsubmit="return confirmAddCertificate();">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="addCertificateModalLabel">Add New Certificate</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="Certificates_Name" class="form-label">Certificate Name</label>
          <input type="text" class="form-control" name="Certificates_Name" id="Certificates_Name" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_certificate" class="btn btn-success">Add Certificate</button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ Add Purpose Modal -->
<div class="modal fade" id="addPurposeModal" tabindex="-1" aria-labelledby="addPurposeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content" onsubmit="return confirmAddPurpose(event)">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="addPurposeModalLabel">Add New Purpose</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="purpose_name" class="form-label">Purpose Name</label>
          <input type="text" class="form-control" name="purpose_name" id="purpose_name" required>
        </div>
        <div class="mb-3">
          <label for="cert_id" class="form-label">Select Certificate</label>
          <select name="cert_id" id="cert_id" class="form-select" required>
            <option value="">-- Select Certificate --</option>
            <?php
            $certOptions = $mysqli->query("SELECT Cert_Id, Certificates_Name FROM certificates WHERE status = 'Active'");
            while ($cert = $certOptions->fetch_assoc()):
            ?>
              <option value="<?= $cert['Cert_Id'] ?>"><?= htmlspecialchars($cert['Certificates_Name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_purpose" class="btn btn-success">Add Purpose</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Bootstrap JS -->
 <?php if (!empty($responseScript)) echo $responseScript; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmAddCertificate(e) {
  e.preventDefault();
  const form = e.target.closest('form');

  Swal.fire({
    title: 'Add Certificate?',
    icon: 'question',
    text: 'Are you sure you want to add this certificate?',
    showCancelButton: true,
    confirmButtonText: 'Yes, add it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#198754'
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit(); // ✅ submit the closest form
    }
  });

  return false;
}

function confirmAddPurpose(e) {
  e.preventDefault();
  const form = e.target.closest('form');

  // Manually ensure the hidden value exists for PHP to detect
  const hiddenInput = document.createElement('input');
  hiddenInput.type = 'hidden';
  hiddenInput.name = 'add_purpose';
  hiddenInput.value = '1';
  form.appendChild(hiddenInput);

  Swal.fire({
    title: 'Add Purpose?',
    text: 'Are you sure you want to add this purpose?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, add it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#198754'
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit(); // ✅ Will now include "add_purpose" key
    }
  });

  return false;
}



function confirmStatusChange(event, form, type = 'item') {
  event.preventDefault(); // stop normal submit for now

  const newStatus = form.querySelector('input[name="new_status"]').value.toLowerCase();
  const action = newStatus === 'inactive' ? 'deactivate' : 'activate';

  Swal.fire({
    title: `Confirm ${action}`,
    text: `Are you sure you want to ${action} this certificate?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: `Yes, ${action}`,
    cancelButtonText: 'Cancel',
    confirmButtonColor: action === 'deactivate' ? '#d33' : '#198754'
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit(); // ✅ manually submit the form
    }
  });

  return false; // always stop native submit for now
}
function confirmStatusChange(event, type = 'item') {
  event.preventDefault();

  const form = event.target.closest('form');
  const newStatus = form.querySelector('input[name="new_status"]').value.toLowerCase();
  const action = newStatus === 'inactive' ? 'deactivate' : 'activate';

  Swal.fire({
    title: `Confirm ${action}`,
    text: `Are you sure you want to ${action} this certificate?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: `Yes, ${action}`,
    cancelButtonText: 'Cancel',
    confirmButtonColor: action === 'deactivate' ? '#d33' : '#198754'
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit(); // ✅ Submits properly
    }
  });

  return false;
}
document.addEventListener('DOMContentLoaded', () => {
  const viewModals = document.querySelectorAll('[id^="viewPurposesModal"]');

  viewModals.forEach(modal => {
    modal.addEventListener('shown.bs.modal', () => {
      const certId = modal.id.replace('viewPurposesModal', '');

      // Send fetch request to log view ONCE per session
      if (!sessionStorage.getItem(`viewed_cert_${certId}`)) {
        fetch('./logs/logs_trig.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `filename=16&viewedID=${certId}`
        })
        .then(res => res.text())
        .then(data => console.log('Certificate view logged:', data))
        .catch(err => console.error('Error logging view:', err));

        sessionStorage.setItem(`viewed_cert_${certId}`, '1');
      }
    });
  });
});
</script>