<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';
?>
<!-- Add Official Modal -->
<div class="modal fade" id="addOfficialModal" tabindex="-1" aria-labelledby="addOfficialModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addOfficialModalLabel">
          <i class="fa-solid fa-user-plus me-2"></i>Add Official
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <?php if (!empty($error_message)): ?>
          <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded" role="alert">
            <i class="me-2 fas fa-exclamation-circle text-danger"></i>
            <strong>Error:</strong> <?= $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- START FORM -->
        <form action="<?= $redirects['officials']; ?>" id="addEmployeeForm"
              method="POST" class="needs-validation email-guard" novalidate>
          <input type="hidden" name="action_type" id="actionType" value="add">

          <!-- Section: Basic Info -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-id-card"></i>
              <h6 class="m-0 fw-bold">Basic Information</h6>
            </div>

            <div class="row g-3">
              <div class="col-md-3">
                <label for="employee_fname" class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="employee_fname" name="employee_fname"
                       value="<?= isset($firstName) ? htmlspecialchars($firstName) : ''; ?>" required>
                <div class="invalid-feedback">First name is required.</div>
              </div>

              <div class="col-md-3">
                <label for="employee_mname" class="form-label">Middle Name</label>
                <input type="text" class="form-control" id="employee_mname" name="employee_mname"
                       value="<?= isset($middleName) ? htmlspecialchars($middleName) : ''; ?>">
              </div>

              <div class="col-md-3">
                <label for="employee_lname" class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="employee_lname" name="employee_lname"
                       value="<?= isset($lastName) ? htmlspecialchars($lastName) : ''; ?>" required>
                <div class="invalid-feedback">Last name is required.</div>
              </div>

              <div class="col-md-3">
                <label for="employee_sname" class="form-label">Suffix</label>
                <input type="text" class="form-control" id="employee_sname" name="employee_sname"
                       value="<?= isset($suffixName) ? htmlspecialchars($suffixName) : ''; ?>">
              </div>

              <div class="col-md-6">
                <label for="employee_birth_date" class="form-label">Birth Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="employee_birth_date" name="employee_birth_date"
                       value="<?= isset($birthDate) ? htmlspecialchars($birthDate) : ''; ?>" required max="<?= date('Y-m-d'); ?>">
                <div class="form-text">Must not be a future date.</div>
                <div class="invalid-feedback">Birth date is required.</div>
              </div>

              <div class="col-md-6">
                <label for="employee_birth_place" class="form-label">Birth Place</label>
                <input type="text" class="form-control" id="employee_birth_place" name="employee_birth_place"
                       value="<?= isset($birthPlace) ? htmlspecialchars($birthPlace) : ''; ?>">
              </div>

              <div class="col-md-6">
                <label for="employee_gender" class="form-label">Gender <span class="text-danger">*</span></label>
                <select class="form-select" id="employee_gender" name="employee_gender" required>
                  <option value="" disabled <?= empty($gender) ? 'selected' : '' ?>>Select Gender</option>
                  <option value="Male"   <?= (isset($gender) && $gender === 'Male')   ? 'selected' : '' ?>>Male</option>
                  <option value="Female" <?= (isset($gender) && $gender === 'Female') ? 'selected' : '' ?>>Female</option>
                </select>
                <div class="invalid-feedback">Please select a gender.</div>
              </div>

              <div class="col-md-6">
                <label for="employee_contact_number" class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="employee_contact_number" name="employee_contact_number"
                       value="<?= isset($contactNumber) ? htmlspecialchars($contactNumber) : ''; ?>"
                       inputmode="tel" pattern="^[0-9+\-\s]{7,20}$" required>
                <div class="invalid-feedback">Enter a valid contact number.</div>
              </div>
            </div>
          </div>

          <!-- Section: Login Identifier -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-key"></i>
              <h6 class="m-0 fw-bold">Login Identifier</h6>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="login_mode" id="mode_email" value="email" checked>
                  <label class="form-check-label" for="mode_email">Use Email (recommended)</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="login_mode" id="mode_username" value="username">
                  <label class="form-check-label" for="mode_username">No email — set a username</label>
                </div>
              </div>

              <div class="col-md-6" id="wrap_email">
                <label for="employee_email" class="form-label">Email Address</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                  <input type="email" class="form-control employee-email" id="employee_email" name="employee_email">
                </div>
                <small class="form-text email-feedback text-muted"></small>
              </div>

              <div class="col-md-6 d-none" id="wrap_username">
                <label for="employee_username" class="form-label">Username</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                  <input type="text" class="form-control" id="employee_username" name="employee_username"
                         autocomplete="off" inputmode="latin" pattern="^[A-Za-z0-9._-]{4,64}$">
                </div>
                <small class="text-muted">4–64 chars; letters, numbers, dot, underscore, or hyphen.</small>
                <div class="invalid-feedback">Enter a valid username (A–Z, 0–9, . _ -).</div>
              </div>
            </div>
          </div>

          <!-- Section: Address & Civil Info -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-location-dot"></i>
              <h6 class="m-0 fw-bold">Address & Other Details</h6>
            </div>

            <div class="row g-3">
              <div class="col-md-4">
                <label for="employee_zone" class="form-label">Zone <span class="text-danger">*</span></label>
                <select class="form-select" id="employee_zone" name="employee_zone" required>
                  <option value="">Select Zone</option>
                  <?php foreach ($zones as $zn) : ?>
                    <option value="<?= htmlspecialchars($zn['Zone_Name']) ?>">
                      <?= htmlspecialchars($zn['Zone_Name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Please select a zone.</div>
              </div>

              <div class="col-md-8">
                <label for="employee_street_address" class="form-label">Street Address
                  <small class="text-muted">(Phase, Street, Blk, Lot Number)</small>
                </label>
                <input type="text" class="form-control" id="employee_street_address" name="employee_street_address"
                       value="<?= isset($streetAddress) ? htmlspecialchars($streetAddress) : ''; ?>">
              </div>

              <div class="col-md-4">
                <label for="employee_citizenship" class="form-label">Citizenship</label>
                <input type="text" class="form-control" id="employee_citizenship" name="employee_citizenship"
                       value="<?= isset($citizenship) ? htmlspecialchars($citizenship) : ''; ?>">
              </div>

              <div class="col-md-4">
                <label for="employee_religion" class="form-label">Religion</label>
                <input type="text" class="form-control" id="employee_religion" name="employee_religion"
                       value="<?= isset($religion) ? htmlspecialchars($religion) : ''; ?>">
              </div>
            </div>
          </div>

          <!-- Section: Position -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-briefcase"></i>
              <h6 class="m-0 fw-bold">Position</h6>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label for="employee_term" class="form-label">Term <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="employee_term" name="employee_term"
                       value="<?= isset($term) ? htmlspecialchars($term) : ''; ?>" required>
                <div class="invalid-feedback">Term is required.</div>
              </div>

              <div class="col-md-6">
                <label for="Role_Id" class="form-label">Position <span class="text-danger">*</span></label>
                <select class="form-select" id="Role_Id" name="Role_Id" required>
                  <option value="" selected disabled>Select Position</option>
                  <?php
                    $query = "SELECT Role_Id, Role_Name FROM employee_roles";
                    $result = $mysqli->query($query);
                    if ($result && $result->num_rows > 0) {
                      while ($row = $result->fetch_assoc()) {
                        echo "<option value='{$row['Role_Id']}'>".htmlspecialchars($row['Role_Name'])."</option>";
                      }
                    } else {
                      echo "<option disabled>No roles available</option>";
                    }
                  ?>
                </select>
                <div class="invalid-feedback">Please choose a position.</div>
              </div>
            </div>
          </div>

          <div class="text-center">
            <button type="submit" class="btn btn-primary px-4">
              <i class="fa-solid fa-floppy-disk me-1"></i> Add Official
            </button>
          </div>
        </form>
        <!-- END FORM -->
      </div>
    </div>
  </div>
</div>

<!-- JS: mode toggle + Bootstrap validation -->
<script>
(function(){
  const modeEmail     = document.getElementById('mode_email');
  const modeUsername  = document.getElementById('mode_username');
  const wrapEmail     = document.getElementById('wrap_email');
  const wrapUsername  = document.getElementById('wrap_username');
  const emailInput    = document.getElementById('employee_email');
  const userInput     = document.getElementById('employee_username');
  const form          = document.getElementById('addEmployeeForm');

  function applyMode(){
    const useEmail = modeEmail.checked;
    wrapEmail.classList.toggle('d-none', !useEmail);
    wrapUsername.classList.toggle('d-none',  useEmail);

    if (useEmail) {
      emailInput.setAttribute('required','required');
      userInput.removeAttribute('required');
      userInput.value = '';
    } else {
      userInput.setAttribute('required','required');
      emailInput.removeAttribute('required');
      emailInput.value = '';
    }
  }

  modeEmail.addEventListener('change', applyMode);
  modeUsername.addEventListener('change', applyMode);
  applyMode();

  // Bootstrap validation
  form.addEventListener('submit', function (e) {
    // enforce future date guard (already set via max attribute)
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    }
    form.classList.add('was-validated');

    // prevent sending both identifiers
    if (modeEmail.checked) userInput.value = '';
    else emailInput.value = '';
  }, false);
})();
</script>
