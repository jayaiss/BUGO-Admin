<?php 
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';
?>
<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">
          <i class="fa-solid fa-user-pen me-2"></i>Edit Employee Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form id="editEmployeeForm"
              action="<?= $redirects['officials']; ?>"
              method="POST"
              enctype="multipart/form-data"
              class="needs-validation" novalidate>
          <input type="hidden" id="editEmployeeId" name="employee_id">
          <input type="hidden" name="action_type" id="actionType" value="edit">

          <!-- SECTION: Name & Birth -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-id-card"></i>
              <h6 class="m-0 fw-bold">Name & Birth</h6>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editFirstName" class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" id="editFirstName" name="edit_first_name" class="form-control" required>
                <div class="invalid-feedback">First name is required.</div>
              </div>
              <div class="col-md-6">
                <label for="editMiddleName" class="form-label">Middle Name</label>
                <input type="text" id="editMiddleName" name="edit_middle_name" class="form-control">
              </div>

              <div class="col-md-6">
                <label for="editLastName" class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" id="editLastName" name="edit_last_name" class="form-control" required>
                <div class="invalid-feedback">Last name is required.</div>
              </div>
              <div class="col-md-6">
                <label for="editBirthDate" class="form-label">Birth Date <span class="text-danger">*</span></label>
                <input type="date" id="editBirthDate" name="edit_birth_date" class="form-control" required max="<?= date('Y-m-d'); ?>">
                <div class="form-text">Must not be a future date.</div>
                <div class="invalid-feedback">Birth date is required.</div>
              </div>

              <div class="col-md-6">
                <label for="editBirthPlace" class="form-label">Birth Place <span class="text-danger">*</span></label>
                <input type="text" id="editBirthPlace" name="edit_birth_place" class="form-control" required>
                <div class="invalid-feedback">Birth place is required.</div>
              </div>
              <div class="col-md-6">
                <label for="editGender" class="form-label">Gender <span class="text-danger">*</span></label>
                <select id="editGender" name="edit_gender" class="form-select" required>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                </select>
                <div class="invalid-feedback">Please select a gender.</div>
              </div>
            </div>
          </div>

          <!-- SECTION: Contact & Civil -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-phone"></i>
              <h6 class="m-0 fw-bold">Contact & Civil</h6>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editContactNumber" class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input type="text" id="editContactNumber" name="edit_contact_number"
                       class="form-control" required inputmode="tel" pattern="^[0-9+\-\s]{7,20}$">
                <div class="invalid-feedback">Enter a valid contact number.</div>
              </div>
              <div class="col-md-6">
                <label for="editCivilStatus" class="form-label">Civil Status <span class="text-danger">*</span></label>
                <select id="editCivilStatus" name="edit_civil_status" class="form-select" required>
                  <option value="Single">Single</option>
                  <option value="Married">Married</option>
                  <option value="Widowed">Widowed</option>
                  <option value="Divorced">Divorced</option>
                </select>
                <div class="invalid-feedback">Please choose a civil status.</div>
              </div>
            </div>
          </div>

          <!-- SECTION: Email & Zone -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-envelope"></i>
              <h6 class="m-0 fw-bold">Email & Zone</h6>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editEmail" class="form-label">Email</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-at"></i></span>
                  <input type="email" id="editEmail" name="edit_email" class="form-control">
                </div>
                <small class="form-text text-muted" id="editEmailFeedback"></small>
              </div>
              <div class="col-md-6">
                <label for="editZone" class="form-label">Zone <span class="text-danger">*</span></label>
                <input type="text" id="editZone" name="edit_zone" class="form-control" required>
                <div class="invalid-feedback">Zone is required.</div>
              </div>
            </div>
          </div>

          <!-- SECTION: Other Details -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-passport"></i>
              <h6 class="m-0 fw-bold">Other Details</h6>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editCitizenship" class="form-label">Citizenship <span class="text-danger">*</span></label>
                <input type="text" id="editCitizenship" name="edit_citizenship" class="form-control" required>
                <div class="invalid-feedback">Citizenship is required.</div>
              </div>
              <div class="col-md-6">
                <label for="editReligion" class="form-label">Religion <span class="text-danger">*</span></label>
                <input type="text" id="editReligion" name="edit_religion" class="form-control" required>
                <div class="invalid-feedback">Religion is required.</div>
              </div>
              <div class="col-md-12">
                <label for="editTerm" class="form-label">Employee Term <span class="text-danger">*</span></label>
                <input type="text" id="editTerm" name="edit_term" class="form-control" required>
                <div class="invalid-feedback">Term is required.</div>
              </div>
            </div>
          </div>

          <!-- SECTION: Account Login (Username + New Password) -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-user-shield"></i>
              <h6 class="m-0 fw-bold">Account Login</h6>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editUsername" class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" id="editUsername" name="edit_username" class="form-control" required minlength="4" maxlength="255" autocomplete="username">
                <div class="invalid-feedback">Username is required (min 4 chars).</div>
              </div>

              <div class="col-md-6">
                <label for="editPassword" class="form-label">New Password</label>
                <div class="input-group">
                  <input type="password" id="editPassword" name="edit_new_password" class="form-control" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current">
                  <button class="btn btn-outline-secondary" type="button" id="togglePwd"><i class="fa-regular fa-eye"></i></button>
                </div>
                <div class="form-text">Leave blank if you don’t want to change it.</div>
              </div>

              <div class="col-md-6">
                <label for="editConfirmPassword" class="form-label">Confirm New Password</label>
                <input type="password" id="editConfirmPassword" class="form-control" autocomplete="new-password" placeholder="Repeat new password">
                <div class="invalid-feedback">Passwords must match.</div>
              </div>
            </div>
          </div>

          <!-- SECTION: E-Signature -->
          <div class="p-3 border rounded-3 mb-3 bg-white">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="fa-solid fa-pen-fancy"></i>
              <h6 class="m-0 fw-bold">E-Signature</h6>
            </div>
            <div class="row g-3">
              <div class="col-12">
                <label for="editESignature" class="form-label">E-Signature (PNG/JPG/WEBP, max 2 MB)</label>
                <input type="file"
                       id="editESignature"
                       name="edit_esignature"
                       class="form-control"
                       accept="image/png,image/jpeg,image/webp">
                <div class="form-text">Upload a clear signature. Transparent PNG preferred.</div>

                <div class="mt-3 d-flex align-items-start gap-3">
                  <img id="editESignaturePreview"
                       src=""
                       alt="Current e-signature preview"
                       style="max-width:280px;max-height:140px;display:none;border:1px solid #ddd;border-radius:.5rem;padding:.25rem;background:#fff;">
                  <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" value="1" id="editESignatureRemove" name="remove_esignature">
                    <label class="form-check-label" for="editESignatureRemove">
                      Remove current e-signature
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-1"></i> Update Employee
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Simple debounce utility
function debounce(fn, wait){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), wait); }; }

document.addEventListener('DOMContentLoaded', function () {
  const editModal        = document.getElementById('editModal');
  const form             = document.getElementById('editEmployeeForm');

  const editFirstName    = document.getElementById('editFirstName');
  const editMiddleName   = document.getElementById('editMiddleName');
  const editLastName     = document.getElementById('editLastName');
  const editGender       = document.getElementById('editGender');
  const editZone         = document.getElementById('editZone');
  const editBirthDate    = document.getElementById('editBirthDate');
  const editBirthPlace   = document.getElementById('editBirthPlace');
  const editContact      = document.getElementById('editContactNumber');
  const editCivilStatus  = document.getElementById('editCivilStatus');
  const editEmail        = document.getElementById('editEmail');
  const editCitizenship  = document.getElementById('editCitizenship');
  const editReligion     = document.getElementById('editReligion');
  const editTerm         = document.getElementById('editTerm');

  // Account fields
  const editUsername     = document.getElementById('editUsername');
  const editPassword     = document.getElementById('editPassword');
  const editConfirmPwd   = document.getElementById('editConfirmPassword');
  const togglePwdBtn     = document.getElementById('togglePwd');

  const emailFeedbackEl  = document.getElementById('editEmailFeedback');

  // E-signature elements
  const sigInput   = document.getElementById('editESignature');
  const sigPreview = document.getElementById('editESignaturePreview');
  const sigRemove  = document.getElementById('editESignatureRemove');

  // Fill values when modal opens
  editModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    document.getElementById('editEmployeeId').value = button.getAttribute('data-id') || '';
    editFirstName.value    = button.getAttribute('data-fname') || '';
    editMiddleName.value   = button.getAttribute('data-mname') || '';
    editLastName.value     = button.getAttribute('data-lname') || '';
    editGender.value       = button.getAttribute('data-gender') || 'Male';
    editZone.value         = button.getAttribute('data-zone') || '';
    editBirthDate.value    = button.getAttribute('data-birthdate') || '';
    editBirthPlace.value   = button.getAttribute('data-birthplace') || '';
    editContact.value      = button.getAttribute('data-contact') || '';
    editCivilStatus.value  = button.getAttribute('data-civilstatus') || 'Single';
    editEmail.value        = button.getAttribute('data-email') || '';
    editCitizenship.value  = button.getAttribute('data-citizenship') || '';
    editReligion.value     = button.getAttribute('data-religion') || '';
    editTerm.value         = button.getAttribute('data-term') || '';

    // Username (from data-username on edit button)
    editUsername.value     = button.getAttribute('data-username') || '';
    editPassword.value     = '';
    editConfirmPwd.value   = '';

    // Signature preview
    const hasEsig = (button.getAttribute('data-has-esig') === '1');
    const empId   = button.getAttribute('data-id');
    sigRemove.checked = false;
    sigInput.value = '';
    if (hasEsig && empId) {
      sigPreview.src = 'show_esignature.php?id=' + encodeURIComponent(empId) + '&t=' + Date.now();
      sigPreview.style.display = 'block';
    } else {
      sigPreview.style.display = 'none';
      sigPreview.removeAttribute('src');
    }

    // Clear email feedback
    emailFeedbackEl.textContent = '';
    emailFeedbackEl.classList.remove('text-danger');
    emailFeedbackEl.classList.add('text-muted');
  });

  // Email validation (basic format check + live message)
  const validateEmail = () => {
    const v = (editEmail.value || '').trim();
    if (!v) { emailFeedbackEl.textContent = ''; return; }
    const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    emailFeedbackEl.textContent = ok ? 'Looks good.' : 'Invalid email format.';
    emailFeedbackEl.classList.toggle('text-danger', !ok);
    emailFeedbackEl.classList.toggle('text-muted', ok);
  };
  editEmail.addEventListener('input', debounce(validateEmail, 250));
  editEmail.addEventListener('blur', validateEmail);

  // New e-signature live preview + guards
  sigInput.addEventListener('change', function () {
    const f = this.files && this.files[0];
    if (!f) return;
    if (f.size > 2 * 1024 * 1024) { // 2 MB
      Swal.fire({ icon:'error', title:'Too large', text:'Max size is 2 MB.' });
      this.value = '';
      return;
    }
    const allowed = ['image/png', 'image/jpeg', 'image/webp'];
    if (!allowed.includes(f.type)) {
      Swal.fire({ icon:'error', title:'Invalid type', text:'Use PNG, JPG, or WEBP.' });
      this.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = e => {
      sigPreview.src = e.target.result;
      sigPreview.style.display = 'block';
      sigRemove.checked = false; // new file means don't remove
    };
    reader.readAsDataURL(f);
  });

  // Password show/hide
  togglePwdBtn.addEventListener('click', () => {
    const isPwd = editPassword.type === 'password';
    editPassword.type   = isPwd ? 'text' : 'password';
    editConfirmPwd.type = isPwd ? 'text' : 'password';
  });

  // Password validation — only if user enters something
  function passwordsOk() {
    const p  = (editPassword.value || '').trim();
    const cp = (editConfirmPwd.value || '').trim();
    if (!p && !cp) { // nothing to change
      editPassword.setCustomValidity('');
      editConfirmPwd.setCustomValidity('');
      return true;
    }
    if (p.length && p.length < 8) {
      editPassword.setCustomValidity('Password must be at least 8 characters.');
      return false;
    }
    editPassword.setCustomValidity('');
    const match = p === cp;
    editConfirmPwd.setCustomValidity(match ? '' : 'Passwords do not match');
    return match;
  }
  editPassword.addEventListener('input', passwordsOk);
  editConfirmPwd.addEventListener('input', passwordsOk);

  // Bootstrap validation + SweetAlert confirmation
  form.addEventListener('submit', function (event) {
    event.preventDefault();

    // HTML5 validity first
    if (!form.checkValidity()) {
      event.stopPropagation();
      form.classList.add('was-validated');
      const firstInvalid = form.querySelector(':invalid');
      if (firstInvalid) firstInvalid.focus();
      return;
    }

    // Password pair check
    if (!passwordsOk()) {
      form.classList.add('was-validated');
      editConfirmPwd.focus();
      return;
    }

    Swal.fire({
      title: 'Save changes?',
      text: "Are you sure you want to save the changes?",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, save it!',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#3085d6',
      cancelButtonColor: '#d33'
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      } else {
        Swal.fire({
          icon: 'info',
          title: 'Cancelled',
          text: 'Changes were not saved.',
          timer: 1800,
          showConfirmButton: false
        });
      }
    });
  }, false);
});
</script>
