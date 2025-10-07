<?php 
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';
?>
<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewModalLabel">
          <i class="fa-solid fa-eye me-2"></i> View Employee Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- ===== Identity ===== -->
        <div class="p-3 border rounded-3 mb-3 bg-white">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-id-card"></i>
            <h6 class="m-0 fw-bold">Identity</h6>
          </div>

          <div class="row g-3">
            <div class="col-md-3">
              <div class="small text-muted">First Name</div>
              <div class="fs-6 fw-semibold" id="viewFirstName">—</div>
            </div>
            <div class="col-md-3">
              <div class="small text-muted">Middle Name</div>
              <div class="fs-6 fw-semibold" id="viewMiddleName">—</div>
            </div>
            <div class="col-md-3">
              <div class="small text-muted">Last Name</div>
              <div class="fs-6 fw-semibold" id="viewLastName">—</div>
            </div>
            <div class="col-md-3">
              <div class="small text-muted">Suffix</div>
              <div class="fs-6 fw-semibold" id="viewSuffixName">—</div>
            </div>
          </div>
        </div>

        <!-- ===== Birth & Civil ===== -->
        <div class="p-3 border rounded-3 mb-3 bg-white">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-cake-candles"></i>
            <h6 class="m-0 fw-bold">Birth & Civil</h6>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="small text-muted">Birth Date</div>
              <div class="fs-6 fw-semibold" id="viewBirthDate">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Birth Place</div>
              <div class="fs-6 fw-semibold" id="viewBirthPlace">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Gender</div>
              <div class="fs-6 fw-semibold" id="viewGender">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Civil Status</div>
              <div class="fs-6 fw-semibold" id="viewCivilStatus">—</div>
            </div>
          </div>
        </div>

        <!-- ===== Contact & Address ===== -->
        <div class="p-3 border rounded-3 mb-3 bg-white">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-address-book"></i>
            <h6 class="m-0 fw-bold">Contact & Address</h6>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="small text-muted d-flex align-items-center justify-content-between">
                <span>Contact Number</span>
                <button type="button" class="btn btn-sm btn-light border copy-btn" data-copy-target="#viewContactNumber" title="Copy">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="fs-6 fw-semibold text-break" id="viewContactNumber">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted d-flex align-items-center justify-content-between">
                <span>Email</span>
                <button type="button" class="btn btn-sm btn-light border copy-btn" data-copy-target="#viewEmail" title="Copy">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="fs-6 fw-semibold text-break" id="viewEmail">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Zone</div>
              <div class="fs-6 fw-semibold" id="viewZone">—</div>
            </div>
          </div>
        </div>

        <!-- ===== Citizenship & Religion & Term ===== -->
        <div class="p-3 border rounded-3 mb-3 bg-white">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-passport"></i>
            <h6 class="m-0 fw-bold">Other Details</h6>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="small text-muted">Citizenship</div>
              <div class="fs-6 fw-semibold" id="viewCitizenship">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Religion</div>
              <div class="fs-6 fw-semibold" id="viewReligion">—</div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Employee Term</div>
              <div class="fs-6 fw-semibold" id="viewTerm">—</div>
            </div>
          </div>
        </div>

        <!-- ===== Login ===== -->
        <div class="p-3 border rounded-3 mb-1 bg-white">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-user-lock"></i>
            <h6 class="m-0 fw-bold">Login</h6>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="small text-muted d-flex align-items-center justify-content-between">
                <span>Username</span>
                <button type="button" class="btn btn-sm btn-light border copy-btn" data-copy-target="#viewUsername" title="Copy">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="fs-6 fw-semibold text-break" id="viewUsername">(none)</div>
            </div>

            <div class="col-md-6" id="wrapTempPass">
              <div class="small text-muted d-flex align-items-center justify-content-between">
                <span>Temp Password</span>
                <button type="button" class="btn btn-sm btn-light border copy-btn" data-copy-target="#viewTempPass" title="Copy">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="fs-6 fw-semibold text-break" id="viewTempPass">(not set)</div>
              <div class="form-text text-muted">Shown only if the employee has not changed their password yet.</div>
            </div>
          </div>
        </div>

      </div><!-- /modal-body -->
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const viewModal = document.getElementById('viewModal');
  if (!viewModal) return;

  function safe(el, val) {
    el.textContent = (val && val.trim() !== '') ? val : '—';
  }

  viewModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;

    const getAttr = (name) => (button.getAttribute(name) || '').trim();

    // Identity
    safe(document.getElementById('viewFirstName'),  getAttr('data-fname'));
    safe(document.getElementById('viewMiddleName'), getAttr('data-mname'));
    safe(document.getElementById('viewLastName'),   getAttr('data-lname'));
    safe(document.getElementById('viewSuffixName'), getAttr('data-sname'));

    // Birth & Civil
    safe(document.getElementById('viewBirthDate'),   getAttr('data-birthdate'));
    safe(document.getElementById('viewBirthPlace'),  getAttr('data-birthplace'));
    safe(document.getElementById('viewGender'),      getAttr('data-gender'));
    safe(document.getElementById('viewCivilStatus'), getAttr('data-civilstatus'));

    // Contact & Address
    safe(document.getElementById('viewContactNumber'), getAttr('data-contact'));
    safe(document.getElementById('viewEmail'),         getAttr('data-email'));
    safe(document.getElementById('viewZone'),          getAttr('data-zone'));

    // Other
    safe(document.getElementById('viewCitizenship'), getAttr('data-citizenship'));
    safe(document.getElementById('viewReligion'),    getAttr('data-religion'));
    safe(document.getElementById('viewTerm'),        getAttr('data-term'));

    // Login
    const username        = getAttr('data-username');
    const tempPass        = getAttr('data-temp');
    const passChangeRaw   = getAttr('data-pchange'); // "0" or "1"
    const passwordChanged = passChangeRaw === '1';

    const viewUsername = document.getElementById('viewUsername');
    const wrapTempPass = document.getElementById('wrapTempPass');
    const viewTempPass = document.getElementById('viewTempPass');

    viewUsername.textContent = username || '(none)';

    if (passwordChanged) {
      wrapTempPass.style.display = 'none';
      viewTempPass.textContent = '';
    } else {
      wrapTempPass.style.display = '';
      viewTempPass.textContent = tempPass || '(not set)';
    }
  });

  // Copy-to-clipboard (for email, username, phone, temp password)
  function copyTextFromSelector(selector) {
    const el = document.querySelector(selector);
    if (!el) return '';
    return (el.textContent || '').trim();
  }
  function showToast(text) {
    // lightweight feedback; replace with Toast if you have one
    if (window.Swal) {
      Swal.fire({ icon: 'success', title: 'Copied', text, timer: 1200, showConfirmButton: false });
    }
  }
  document.querySelectorAll('#viewModal .copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-copy-target');
      const text = copyTextFromSelector(target);
      if (!text || text === '—' || text === '(none)' || text === '(not set)') return;

      navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard');
      }).catch(() => {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showToast('Copied to clipboard'); } catch(e){}
        ta.remove();
      });
    });
  });
});
</script>
