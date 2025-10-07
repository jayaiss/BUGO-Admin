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
      <div class="modal-header"><!-- styled by res.css -->
        <h5 class="modal-title" id="editModalLabel">
          <i class="fa-solid fa-user-pen me-2"></i>Edit Resident Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form id="editForm" method="POST" action="class/update_resident.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="id" id="editId">

          <!-- Names -->
          <div class="row gy-3">
            <div class="col-md-3">
              <label for="editFirstName" class="form-label">First Name</label>
              <input type="text" class="form-control" id="editFirstName" name="first_name" required>
            </div>
            <div class="col-md-3">
              <label for="editMiddleName" class="form-label">Middle Name</label>
              <input type="text" class="form-control" id="editMiddleName" name="middle_name">
            </div>
            <div class="col-md-3">
              <label for="editLastName" class="form-label">Last Name</label>
              <input type="text" class="form-control" id="editLastName" name="last_name" required>
            </div>
            <div class="col-md-3">
              <label for="editSuffixName" class="form-label">Suffix</label>
              <input type="text" class="form-control" id="editSuffixName" name="suffix_name" placeholder="Jr., Sr., III">
            </div>
          </div>

          <!-- Gender / Zone -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editGender" class="form-label">Gender</label>
              <select class="form-select" id="editGender" name="gender" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="editZone" class="form-label">Zone</label>
              <input type="text" class="form-control" id="editZone" name="zone" required>
            </div>
          </div>

          <!-- Contact / Email -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editContactNumber" class="form-label">Contact Number</label>
              <input type="text" class="form-control" id="editContactNumber" name="contact_number" inputmode="numeric" pattern="[0-9]{10,12}" required>
            </div>
            <div class="col-md-6">
              <label for="editEmail" class="form-label">Email</label>
              <input type="email" class="form-control" id="editEmail" name="email" required>
            </div>
          </div>

          <!-- Civil status / Birth -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editCivilStatus" class="form-label">Civil Status</label>
              <select class="form-select" id="editCivilStatus" name="civilStatus" required>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Divorced">Divorced</option>
                <option value="Widowed">Widowed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="editBirthDate" class="form-label">Birth Date</label>
              <input type="date" class="form-control" id="editBirthDate" name="birth_date" required>
            </div>
          </div>

          <!-- Birth place / Residency -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editBirthPlace" class="form-label">Birth Place</label>
              <input type="text" class="form-control" id="editBirthPlace" name="birth_place" required>
            </div>
            <div class="col-md-6">
              <label for="editResidencyStart" class="form-label">Residency Start</label>
              <input type="date" class="form-control" id="editResidencyStart" name="residency_start" required>
            </div>
          </div>

          <!-- Address / Citizenship -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editStreetAddress" class="form-label">Street Address</label>
              <input type="text" class="form-control" id="editStreetAddress" name="street_address" required>
            </div>
            <div class="col-md-6">
              <label for="editCitizenship" class="form-label">Citizenship</label>
              <input type="text" class="form-control" id="editCitizenship" name="citizenship" required>
            </div>
          </div>

          <!-- Religion / Occupation -->
          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editReligion" class="form-label">Religion</label>
              <input type="text" class="form-control" id="editReligion" name="religion" required>
            </div>
            <div class="col-md-6">
              <label for="editOccupation" class="form-label">Occupation</label>
              <input type="text" class="form-control" id="editOccupation" name="occupation" required>
            </div>
          </div>

          <!-- Hidden fields -->
          <input type="hidden" name="zone_leader" id="editZoneLeader">
          <input type="hidden" name="province" id="editProvince">
          <input type="hidden" name="city_municipality" id="editCityMunicipality">
          <input type="hidden" name="barangay" id="editBarangay">

          <!-- Child Section -->
          <div class="card-surface mb-4 mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Child (Optional)</h6>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="editAddFamilyMembers" onchange="toggleEditFamilySection()">
                <label class="form-check-label" for="editAddFamilyMembers">Add Child</label>
              </div>
            </div>
            <div class="card-body" id="editFamilyMembersSection" style="display:none;">
              <div class="mb-3 d-flex align-items-center">
                <button type="button" class="btn btn-success btn-sm" onclick="addEditFamilyMember()">
                  <i class="fas fa-plus"></i> Add Child
                </button>
                <small class="text-muted ms-2">You can add multiple children who will share the same address.</small>
              </div>
              <div id="editFamilyMembersContainer"><!-- added dynamically --></div>
            </div>
          </div>

          <div class="text-end">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-1"></i>Save Changes
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Populate Edit Modal with Resident Data
$('#editModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget);

  $('#editId').val(button.data('id'));
  $('#editFirstName').val(button.data('fname'));
  $('#editMiddleName').val(button.data('mname'));
  $('#editLastName').val(button.data('lname'));
  $('#editSuffixName').val(button.data('sname'));

  $('#editGender').val(button.data('gender'));
  $('#editContactNumber').val(button.data('contact'));
  $('#editEmail').val(button.data('email'));
  $('#editCivilStatus').val(button.data('civilstatus'));

  $('#editBirthDate').val(button.data('birthdate'));
  $('#editResidencyStart').val(button.data('residencystart'));
  $('#editBirthPlace').val(button.data('birthplace'));

  $('#editZone').val(button.data('zone'));
  $('#editStreetAddress').val(button.data('streetaddress'));
  $('#editCitizenship').val(button.data('citizenship'));
  $('#editReligion').val(button.data('religion'));
  $('#editOccupation').val(button.data('occupation'));

  // Hidden inputs
  $('#editZoneLeader').val(button.data('zoneleader') || '');
  $('#editProvince').val(button.data('province') || '');
  $('#editCityMunicipality').val(button.data('city') || '');
  $('#editBarangay').val(button.data('barangay') || '');
});
</script>

<script>
// ===== Validation Helpers =====
function todayYMD(){ return new Date().toISOString().slice(0,10); }
function setInvalid(el,msg){
  el.classList.add('is-invalid');
  let fb=el.nextElementSibling;
  if(!fb || !fb.classList.contains('invalid-feedback')){
    fb=document.createElement('div'); fb.className='invalid-feedback';
    el.insertAdjacentElement('afterend',fb);
  }
  fb.textContent=msg; fb.style.display='block';
  el.setCustomValidity(msg);
}
function clearInvalid(el){
  el.classList.remove('is-invalid');
  const fb=el.nextElementSibling;
  if(fb?.classList.contains('invalid-feedback')){ fb.textContent=''; fb.style.display='none'; }
  el.setCustomValidity('');
}

// Field Validators
function validateEditBirthDate(el){
  if(!el.value){ clearInvalid(el); return true; }
  if(el.value > todayYMD()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}
function validateEditResidencyStart(el){
  if(!el.value){ clearInvalid(el); return true; }
  if(el.value > todayYMD()){ setInvalid(el,'Residency start cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}

// Attach events
document.addEventListener('DOMContentLoaded', ()=>{
  const bd=document.getElementById('editBirthDate');
  const rs=document.getElementById('editResidencyStart');
  if(bd){
    bd.setAttribute('max', todayYMD());
    ['input','change','blur'].forEach(ev=>bd.addEventListener(ev,()=>validateEditBirthDate(bd)));
  }
  if(rs){
    rs.setAttribute('max', todayYMD());
    ['input','change','blur'].forEach(ev=>rs.addEventListener(ev,()=>validateEditResidencyStart(rs)));
  }

  const form=document.getElementById('editForm');
  if(form){
    form.addEventListener('submit',(e)=>{
      let ok=true;
      if(bd) ok=validateEditBirthDate(bd) && ok;
      if(rs) ok=validateEditResidencyStart(rs) && ok;
      if(!ok || !form.checkValidity()){
        e.preventDefault();
        (form.querySelector(':invalid')||form.querySelector('.is-invalid'))?.focus();
      }
    });
  }
});

// Re-run when modal is shown
$('#editModal').on('shown.bs.modal', function(){
  const bd=document.getElementById('editBirthDate');
  const rs=document.getElementById('editResidencyStart');
  if(bd) validateEditBirthDate(bd);
  if(rs) validateEditResidencyStart(rs);
});
</script>
