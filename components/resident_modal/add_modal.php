<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

$formAction = 'index_Admin.php?page=' . urlencode(encrypt('resident_info')); // default
include 'class/session_timeout.php';

if (isset($_SESSION['Role_Name'])) {
    $role = strtolower($_SESSION['Role_Name']);
    if ($role === 'barangay secretary') {
        $formAction = 'index_barangay_secretary.php?page=' . urlencode(encrypt('resident_info'));
    } elseif ($role === 'encoder') {
        $formAction = 'index_barangay_staff.php?page=' . urlencode(encrypt('resident_info'));
    } elseif ($role === 'admin') {
        $formAction = 'index_Admin.php?page=' . urlencode(encrypt('resident_info'));
    }
}
?>

<!-- Add Resident Modal -->
<div class="modal fade" id="addResidentModal" tabindex="-1" aria-labelledby="addResidentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"> <!-- styled by res.css -->
        <h5 class="modal-title" id="addResidentModalLabel">
          <i class="fas fa-user-plus me-2"></i>Add New Resident
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form action="<?php echo $formAction; ?>" method="POST" id="addResidentForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

          <!-- Primary Resident Information -->
          <div class="card-surface mb-4">
            <div class="card-header">
              <h6 class="mb-0"><i class="fas fa-user me-2"></i>Primary Resident Information</h6>
            </div>
            <div class="card-body">

              <div class="row gy-3">
                <div class="col-md-3">
                  <label class="form-label">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control primary-first-name" id="primary_firstName" name="firstName" required autocomplete="given-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Middle Name</label>
                  <input type="text" class="form-control primary-middle-name" id="primary_middleName" name="middleName" autocomplete="additional-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control primary-last-name" id="primary_lastName" name="lastName" required autocomplete="family-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Suffix</label>
                  <input type="text" class="form-control primary-suffix-name" id="primary_suffixName" name="suffixName" placeholder="Jr., Sr., III">
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Birth Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="birthDate" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Residency Start Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="residency_start" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Birth Place <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="birthPlace" required>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Gender <span class="text-danger">*</span></label>
                  <select class="form-select" name="gender" required>
                    <option value="" disabled selected>Select Gender</option>
                    <option>Male</option>
                    <option>Female</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="contactNumber" inputmode="numeric" pattern="[0-9]{10,12}" placeholder="09XXXXXXXXX" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Civil Status <span class="text-danger">*</span></label>
                  <select class="form-select" name="civilStatus" required>
                    <option value="" disabled selected>Select Status</option>
                    <option>Single</option>
                    <option>Married</option>
                    <option>Divorced</option>
                    <option>Widowed</option>
                  </select>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Province <span class="text-danger">*</span></label>
                  <select class="form-control" id="province" name="province" required>
                    <option value="">Select Province</option>
                    <?php foreach ($provinces as $province): ?>
                      <option value="<?= $province['province_id'] ?>"><?= htmlspecialchars($province['province_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">City/Municipality <span class="text-danger">*</span></label>
                  <select class="form-control" id="city_municipality" name="city_municipality" disabled required>
                    <option value="">Select City/Municipality</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Barangay <span class="text-danger">*</span></label>
                  <select class="form-control" id="barangay" name="barangay" disabled required>
                    <option value="">Select Barangay</option>
                  </select>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-3">
                  <label class="form-label">Zone <span class="text-danger">*</span></label>
                  <select class="form-control" name="res_zone" required>
                    <option value="">Select Zone</option>
                    <?php foreach ($zones as $zone): ?>
                      <option value="<?= $zone['Zone_Name'] ?>"><?= htmlspecialchars($zone['Zone_Name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Street Address (Phase, Street, Blk, Lot) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="res_street_address" required>
                </div>
                <div class="col-md-5">
                  <label class="form-label">Zone Leader</label>
                  <input type="text" class="form-control mb-1" id="zone_leader" placeholder="Auto-filled by Zone" readonly>
                  <input type="hidden" class="form-control" name="zone_leader" id="zone_leader_id">
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4" id="emailWrapper">
                  <label class="form-label">Email Address</label>
                  <input type="email" class="form-control" id="primary_email" name="email" placeholder="Enter email if available" autocomplete="email" aria-describedby="emailFeedback">
                  <small id="emailFeedback" class="form-text"></small>
                </div>
                <div class="col-md-4" id="usernameWrapper">
                  <label class="form-label">Username</label>
                  <input type="text" class="form-control" id="primary_username" name="username" placeholder="Enter username if no email" autocomplete="username">
                  <small class="form-text text-muted">Either Email or Username is required.</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Citizenship <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="citizenship" required>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Religion <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="religion" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Occupation <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="occupation" required>
                </div>
              </div>

            </div>
          </div>

          <!-- Add Family Members Section -->
          <div class="card-surface mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Add Child (Optional)</h6>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="addFamilyMembers" onchange="toggleFamilySection()">
                <label class="form-check-label" for="addFamilyMembers">Add Child</label>
              </div>
            </div>

            <div class="card-body" id="familyMembersSection" style="display: none;">
              <div class="mb-3 d-flex align-items-center">
                <button type="button" class="btn btn-success btn-sm" onclick="addFamilyMember()">
                  <i class="fas fa-plus"></i> Add Child
                </button>
                <small class="text-muted ms-2">Children will share the same address as the primary resident.</small>
              </div>

              <div id="familyMembersContainer"><!-- injected dynamically --></div>
            </div>
          </div>

          <div class="text-center mt-3">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="fas fa-save me-1"></i> Submit Resident & Child
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const emailInput = document.getElementById("primary_email");
  const usernameInput = document.getElementById("primary_username");
  const emailWrapper = document.getElementById("emailWrapper");
  const usernameWrapper = document.getElementById("usernameWrapper");

  function toggleFields() {
    const hasEmail = (emailInput.value || "").trim() !== "";
    const hasUser  = (usernameInput.value || "").trim() !== "";

    if (hasEmail) {
      usernameWrapper.style.display = "none";
      emailWrapper.style.display = "";
    } else if (hasUser) {
      emailWrapper.style.display = "none";
      usernameWrapper.style.display = "";
    } else {
      emailWrapper.style.display = "";
      usernameWrapper.style.display = "";
    }
  }

  if (emailInput && usernameInput) {
    emailInput.addEventListener("input", toggleFields);
    usernameInput.addEventListener("input", toggleFields);
    toggleFields(); // initialize on open
  }
});
</script>
