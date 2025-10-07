<?php 
session_start();

// get user role
$role = $_SESSION['Role_Name'] ?? '';
$restricted = in_array($role, ['Punong Barangay','Barangay Secretary'], true);

// who can edit/add
$canEdit = !$restricted;
$canAdd  = !$restricted;

// --- server-side guard (important) ---
if (($_POST['update_case_details'] ?? '') === '1' && !$canEdit) {
  http_response_code(403); exit('Forbidden: your role cannot edit cases.');
}
if (isset($_POST['add_case']) && !$canAdd) {
  http_response_code(403); exit('Forbidden: your role cannot add cases.');
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php'; 
?>

<!-- =========================
     View/Edit Case Modal
     ========================= -->
<div class="modal fade" id="viewCaseModal" tabindex="-1" aria-labelledby="viewCaseModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="POST" id="editCaseForm" novalidate>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewCaseModalLabel">Edit Case Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="update_case_details" value="1">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="modal_case_number">Case Number</label>
              <input type="text" class="form-control" name="case_number" id="modal_case_number" readonly>
              <small class="text-muted-2">Auto-filled from the row you clicked.</small>
            </div>

            <!-- Complainant -->
            <div class="col-12"><hr class="my-2"><h6 class="mb-0">Complainant</h6></div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_First_Name">First</label>
              <input type="text" class="form-control" name="Comp_First_Name" id="Comp_First_Name" required autocomplete="given-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Middle_Name">Middle</label>
              <input type="text" class="form-control" name="Comp_Middle_Name" id="Comp_Middle_Name" autocomplete="additional-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Last_Name">Last</label>
              <input type="text" class="form-control" name="Comp_Last_Name" id="Comp_Last_Name" required autocomplete="family-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Suffix_Name">Suffix</label>
              <input type="text" class="form-control" name="Comp_Suffix_Name" id="Comp_Suffix_Name" placeholder="Jr, III">
            </div>

            <!-- Respondent (locked in edit) -->
            <div class="col-12"><hr class="my-2"><h6 class="mb-0">Respondent</h6></div>
            <div class="col-md-3">
              <label class="form-label" for="Resp_First_Name">First</label>
              <input type="text" class="form-control" name="Resp_First_Name" id="Resp_First_Name" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Resp_Middle_Name">Middle</label>
              <input type="text" class="form-control" name="Resp_Middle_Name" id="Resp_Middle_Name" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Resp_Last_Name">Last</label>
              <input type="text" class="form-control" name="Resp_Last_Name" id="Resp_Last_Name" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Resp_Suffix_Name">Suffix</label>
              <input type="text" class="form-control" name="Resp_Suffix_Name" id="Resp_Suffix_Name" readonly>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="modal_nature_offense">Nature of Offense</label>
              <input type="text" class="form-control" name="nature_offense" id="modal_nature_offense" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_date_filed">Date Filed</label>
              <input type="date" class="form-control" name="date_filed" id="modal_date_filed" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_time_filed">Time Filed</label>
              <input type="time" class="form-control" name="time_filed" id="modal_time_filed" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_date_hearing">Date of Hearing</label>
              <input type="date" class="form-control" name="date_hearing" id="modal_date_hearing" required>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <div class="d-flex w-100 justify-content-between align-items-center">
            <span class="text-muted-2 small">Fields with * are required.</span>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success" id="saveChangesBtn"
                <?= $canEdit ? '' : 'disabled aria-disabled="true" title="Not allowed for your role"' ?>>
                Save Changes
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- =========================
     Update Status Modal
     ========================= -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog">
    <form method="POST" novalidate>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="statusModalLabel">Update Case Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="update_action_only" value="1">
          <input type="hidden" name="case_number" id="status_case_number">
          <div class="mb-3">
            <label class="form-label" for="status_action_taken">Action Taken</label>
            <select class="form-select" name="action_taken" id="status_action_taken" required>
              <option value="">Select</option>
              <option value="Conciliated">Conciliated</option>
              <option value="Mediated">Mediated</option>
              <option value="Dismissed">Dismissed</option>
              <option value="Withdrawn">Withdrawn</option>
              <option value="Ongoing">Ongoing</option>
              <option value="Arbitration">Arbitration</option>
            </select>
            <small class="text-muted-2">This only changes the “Case Status” badge in the list.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update Status</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($canAdd): ?>
<!-- =========================
     Add Case Modal
     ========================= -->
<div class="modal fade" id="addCaseModal" tabindex="-1" aria-labelledby="addCaseModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="addCaseForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="addCaseModalLabel">Add Case</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="case_number_add">Case Number</label>
              <input type="text" class="form-control" name="case_number" id="case_number_add" placeholder="2025-001" required pattern="[A-Za-z0-9\-]+"
                     title="Use letters, numbers, and dash only (e.g., 2025-001).">
            </div>

            <!-- Complainant -->
            <div class="col-12"><hr class="my-2"><h6 class="mb-0">Complainant</h6></div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_First_Name_add">First</label>
              <input type="text" class="form-control" name="Comp_First_Name" id="Comp_First_Name_add" required autocomplete="given-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Middle_Name_add">Middle</label>
              <input type="text" class="form-control" name="Comp_Middle_Name" id="Comp_Middle_Name_add" autocomplete="additional-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Last_Name_add">Last</label>
              <input type="text" class="form-control" name="Comp_Last_Name" id="Comp_Last_Name_add" required autocomplete="family-name">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="Comp_Suffix_Name_add">Suffix</label>
              <input type="text" class="form-control" name="Comp_Suffix_Name" id="Comp_Suffix_Name_add" placeholder="e.g., Jr, III">
            </div>

            <!-- Respondent (pick-only from Residents) -->
            <div class="col-12">
              <label class="form-label" for="resp_lookup_add">Search Respondent (from Residents)</label>
              <input type="text" class="form-control" id="resp_lookup_add" placeholder="Type name… (e.g., Juan Dela Cruz)" autocomplete="off" aria-expanded="false" aria-controls="resp_suggestions_add">
              <!-- keep DB columns via hidden inputs -->
              <input type="hidden" name="Resp_resident_id" id="Resp_resident_id_add" required>
              <input type="hidden" name="Resp_First_Name"  id="Resp_First_Name_add">
              <input type="hidden" name="Resp_Middle_Name" id="Resp_Middle_Name_add">
              <input type="hidden" name="Resp_Last_Name"   id="Resp_Last_Name_add">
              <input type="hidden" name="Resp_Suffix_Name" id="Resp_Suffix_Name_add">
              <div id="resp_suggestions_add" class="dropdown-menu w-100 shadow d-none" role="listbox" style="max-height:260px;overflow:auto;"></div>
              <small class="text-muted-2">Pick a resident to set the Respondent (manual entry disabled).</small>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="date_filed">Date Filed</label>
              <input type="date" class="form-control" id="date_filed" name="date_filed" required>
              <small id="filedDateError" class="text-danger d-none">❌ Date filed cannot be in the future.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="time_filed">Time Filed</label>
              <input type="time" class="form-control" id="time_filed" name="time_filed" required>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="date_hearing">Date of Hearing</label>
              <input type="date" class="form-control" id="date_hearing" name="date_hearing" required>
              <small id="hearingError" class="text-danger d-none">❌ Hearing date cannot be in the past.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="nature_offense_add">Nature of Offense</label>
              <input type="text" class="form-control" id="nature_offense_add" name="nature_offense" required>
            </div>

            <div class="col-md-12">
              <label class="form-label" for="action_taken_add">Action Taken</label>
              <select class="form-select" id="action_taken_add" name="action_taken" required>
                <option value="">Select</option>
                <option value="Conciliated">Conciliated</option>
                <option value="Mediated">Mediated</option>
                <option value="Dismissed">Dismissed</option>
                <option value="Withdrawn">Withdrawn</option>
                <option value="Ongoing">Ongoing</option>
                <option value="Arbitration">Arbitration</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_case" class="btn btn-primary">Add Case</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- =========================
     JS: role gates + respondent picker
     ========================= -->
<script>
document.addEventListener("DOMContentLoaded", function () {
  const role = <?= json_encode($role) ?>;
  const restricted = role === "Punong Barangay" || role === "Barangay Secretary";

  if (restricted) {
    // Disable Save Changes in edit modal
    const saveBtn = document.getElementById("saveChangesBtn");
    if (saveBtn) {
      saveBtn.setAttribute("disabled", "");
      saveBtn.setAttribute("aria-disabled", "true");
      saveBtn.classList.add("disabled");
      saveBtn.style.pointerEvents = "none";
      saveBtn.addEventListener("click", (e) => e.preventDefault(), true);
    }
    const editForm = document.getElementById("editCaseForm");
    if (editForm) {
      editForm.addEventListener("submit", function (e) {
        e.preventDefault();
      }, true);
    }
    document.querySelectorAll('[data-bs-target="#addCaseModal"], a[href="#addCaseModal"], #addCaseTrigger')
      .forEach(el => el.remove());
    // Hide Excel upload form if present
    const excelForm = document.getElementById("excelUploadForm");
    if (excelForm) excelForm.remove();
  }

  // === Add Case: Respondent search (AJAX) ===
  (function(){
    const modal = document.getElementById('addCaseModal');
    if (!modal) return;

    const form   = modal.querySelector('#addCaseForm');
    const lookup = modal.querySelector('#resp_lookup_add');
    const menu   = modal.querySelector('#resp_suggestions_add');

    const hidId  = modal.querySelector('#Resp_resident_id_add');
    const first  = modal.querySelector('#Resp_First_Name_add');
    const middle = modal.querySelector('#Resp_Middle_Name_add');
    const last   = modal.querySelector('#Resp_Last_Name_add');
    const suffix = modal.querySelector('#Resp_Suffix_Name_add');

    const submitBtn = form?.querySelector('button[type="submit"][name="add_case"]');

    let timer = null, lastQ = '';
    const debounce = (fn, ms) => (...args) => { clearTimeout(timer); timer = setTimeout(()=>fn(...args), ms); };

    const hideMenu = () => { menu.classList.add('d-none'); menu.classList.remove('show'); lookup.setAttribute('aria-expanded','false'); };
    const showMenu = () => { menu.classList.remove('d-none'); menu.classList.add('show');  lookup.setAttribute('aria-expanded','true'); };

    function render(items){
      if (!items || !items.length){ hideMenu(); return; }
      menu.innerHTML = '';
      items.forEach(row=>{
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dropdown-item text-wrap';
        btn.dataset.id = row.id;
        btn.dataset.f  = row.first_name  || '';
        btn.dataset.m  = row.middle_name || '';
        btn.dataset.l  = row.last_name   || '';
        btn.dataset.s  = row.suffix_name || '';
        const full = [btn.dataset.f, btn.dataset.m, btn.dataset.l, btn.dataset.s].filter(Boolean).join(' ');
        const extra = [];
        if (row.res_street_address) extra.push(row.res_street_address);
        btn.innerHTML = `<strong>${full}</strong><br><small class="text-muted">${extra.join(' • ')}</small>`;
        btn.onclick = () => {
          hidId.value = btn.dataset.id;
          first.value = btn.dataset.f;
          middle.value = btn.dataset.m;
          last.value = btn.dataset.l;
          suffix.value = btn.dataset.s;
          lookup.value = full;
          hideMenu();
          if (submitBtn) submitBtn.disabled = false;
        };
        menu.appendChild(btn);
      });
      showMenu();
    }

    const search = debounce(async (q)=>{
      q = q.trim();
      if (q.length < 2 || q === lastQ){ hideMenu(); return; }
      lastQ = q;
      try{
        const res = await fetch('ajax/case_resident.php?q=' + encodeURIComponent(q), {
          headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        const data = await res.json();
        render(data);
      }catch(e){
        hideMenu();
      }
    }, 250);

    lookup.addEventListener('input', e=>{
      hidId.value = '';
      first.value = middle.value = last.value = suffix.value = '';
      if (submitBtn) submitBtn.disabled = true;
      search(e.target.value);
    });

    document.addEventListener('click', e=>{
      if (!menu.contains(e.target) && e.target !== lookup) hideMenu();
    });

    // Guard on submit
    form?.addEventListener('submit', (e)=>{
      if (!hidId.value || !first.value || !last.value) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
          Swal.fire({icon:'warning',title:'Respondent required',text:'Please select a Respondent from the residents list.'});
        } else {
          alert('Please select a Respondent from the residents list.');
        }
      }
      // quick date sanity checks
      const filed = document.getElementById('date_filed')?.value;
      const hearing = document.getElementById('date_hearing')?.value;
      if (filed && new Date(filed) > new Date()) {
        e.preventDefault();
        document.getElementById('filedDateError')?.classList.remove('d-none');
      }
      if (hearing && new Date(hearing) < new Date().setHours(0,0,0,0)) {
        e.preventDefault();
        document.getElementById('hearingError')?.classList.remove('d-none');
      }
    });

    // Start disabled until a respondent is picked
    if (submitBtn) submitBtn.disabled = true;
  })();
});
</script>
