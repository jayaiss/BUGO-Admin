<?php
// components/resident_modal/view_modal.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'class/session_timeout.php';
?>
<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><!-- styled in res.css -->
        <h5 class="modal-title" id="viewModalLabel">
          <i class="fa-solid fa-id-card-clip me-2"></i>Resident Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- Top identity block -->
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="avatar" id="viewAvatar">RB</div>
          <div>
            <div class="fw-bold fs-5" id="viewFullName">—</div>
            <div class="d-flex flex-wrap gap-2 mt-1">
              <span class="chip" id="chipSex">Sex: —</span>
              <span class="chip" id="chipStatus">Status: —</span>
              <span class="chip" id="chipAge">Age: —</span>
            </div>
          </div>
        </div>

        <!-- Key–Value grid -->
        <div class="view-kv">
          <div class="label">Last Name</div>
          <div class="value" id="viewLastName">—</div>

          <div class="label">First Name</div>
          <div class="value" id="viewFirstName">—</div>

          <div class="label">Middle Name</div>
          <div class="value" id="viewMiddleName">—</div>

          <div class="label">Extension</div>
          <div class="value" id="viewSuffix">—</div>

          <div class="label">Address</div>
          <div class="value" id="viewStreetAddress">—</div>

          <div class="label">Birth Date</div>
          <div class="value" id="viewBirthDate">—</div>

          <div class="label">Occupation</div>
          <div class="value" id="viewOccupation">—</div>

          <!-- Username (only when shown) -->
          <div class="label" id="lblUsername" style="display:none;">Username</div>
          <div class="value d-flex align-items-center gap-2" id="valUsername" style="display:none;">
            <span id="viewUsername"></span>
            <button type="button" class="btn btn-sm btn-outline-dark copy-btn" id="copyUserBtn" title="Copy username">
              <i class="fa-regular fa-copy"></i>
            </button>
          </div>

          <!-- Temp Password (only when res_pass_change = 0) -->
          <div class="label" id="lblPassword" style="display:none;">Temporary Password</div>
          <div class="value d-flex align-items-center gap-2" id="valPassword" style="display:none;">
            <span id="viewPassword" class="mask"></span>
            <div class="btn-group btn-group-sm">
              <button type="button" class="btn btn-outline-dark" id="togglePassBtn" title="Show/Hide password">
                <i class="fa-regular fa-eye"></i>
              </button>
              <button type="button" class="btn btn-outline-dark copy-btn" id="copyPassBtn" title="Copy password">
                <i class="fa-regular fa-copy"></i>
              </button>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>
</div>

<script>
// Vanilla JS (Bootstrap 5)
(function () {
  const viewModal = document.getElementById('viewModal');
  if (!viewModal) return;

  // helpers
  const pick = (obj, keys) => { for (const k of keys) if (obj[k] != null && obj[k] !== '') return obj[k]; return ''; };
  const setText = (id, v='—') => { const el=document.getElementById(id); if(el) el.textContent = v || '—'; };
  const show = (id, on) => { const el=document.getElementById(id); if(el) el.style.display = on ? '' : 'none'; };

  const calcAge = (ymd) => {
    if (!ymd) return '';
    const d = new Date(ymd + 'T00:00:00');
    if (isNaN(d)) return '';
    const t = new Date();
    let a = t.getFullYear() - d.getFullYear();
    const m = t.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && t.getDate() < d.getDate())) a--;
    return a >= 0 ? String(a) : '';
  };

  function initials(first, last){
    const f = (first||'').trim().charAt(0).toUpperCase();
    const l = (last||'').trim().charAt(0).toUpperCase();
    return (f || 'R') + (l || 'B');
  }

  function copy(text){
    if (!text) return;
    navigator.clipboard?.writeText(text).then(()=>{}).catch(()=>{});
  }

  viewModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;
    const d = button.dataset || {};

    // Get values from data-*
    const last   = pick(d, ['lastname','lname','last','lastName']);
    const first  = pick(d, ['firstname','fname','first','firstName']);
    const middle = pick(d, ['middlename','mname','middle','middleName']);
    const suffix = pick(d, ['suffix','sname','ext','extension']);
    const addr   = pick(d, ['streetaddress','address','addr','streetAddress']);
    const bdate  = pick(d, ['birthdate','bdate','birth','birthDate']);
    const sex    = pick(d, ['gender','sex']);
    const status = pick(d, ['civilstatus','status','civilStatus']);
    const job    = pick(d, ['occupation','work','job']);
    const user   = pick(d, ['username','user','email','uname']);
    const tpass  = pick(d, ['tempPassword','temppassword','temppass','temp_password','password']);
    const passChangeRaw = pick(d, ['passchange','resPassChange','res_pass_change']);

    // normalize passChange (accepts "0","1","false","true")
    let passChange = parseInt(passChangeRaw, 10);
    if (isNaN(passChange)) {
      if (/^(0|false|no)$/i.test(String(passChangeRaw))) passChange = 0;
      else if (/^(1|true|yes)$/i.test(String(passChangeRaw))) passChange = 1;
    }

    // Top identity
    setText('viewFullName', [first, middle, last, suffix].filter(Boolean).join(' '));
    const av = document.getElementById('viewAvatar'); if (av) av.textContent = initials(first, last);
    setText('chipSex', `Sex: ${sex || '—'}`);
    setText('chipStatus', `Status: ${status || '—'}`);
    setText('chipAge', `Age: ${calcAge(bdate) || '—'}`);

    // Key–Value grid
    setText('viewLastName', last);
    setText('viewFirstName', first);
    setText('viewMiddleName', middle);
    setText('viewSuffix', suffix);
    setText('viewStreetAddress', addr);
    setText('viewBirthDate', bdate);
    setText('viewOccupation', job);

    // Username visibility (show if we have a user)
    const hasUser = !!user;
    show('lblUsername', hasUser);
    show('valUsername', hasUser);
    setText('viewUsername', user);

    // Temp password + username only when res_pass_change = 0
    const shouldShowCreds = (!isNaN(passChange) && passChange === 0);
    const hasTemp = !!tpass;

    show('lblPassword', shouldShowCreds && hasTemp);
    show('valPassword', shouldShowCreds && hasTemp);

    // Store original password in data attr
    const passEl = document.getElementById('viewPassword');
    if (passEl){
      passEl.dataset.real = tpass || '';
      passEl.classList.add('mask');
      passEl.textContent = tpass ? tpass : '';
    }

    // Toggle & copy handlers
    const toggleBtn = document.getElementById('togglePassBtn');
    if (toggleBtn){
      toggleBtn.onclick = function(){
        const el = document.getElementById('viewPassword');
        if (!el) return;
        el.classList.toggle('mask');
        const i = this.querySelector('i');
        if (i){
          i.classList.toggle('fa-eye');
          i.classList.toggle('fa-eye-slash');
        }
      };
    }

    const copyUserBtn = document.getElementById('copyUserBtn');
    if (copyUserBtn){
      copyUserBtn.onclick = function(){
        const txt = document.getElementById('viewUsername')?.textContent?.trim();
        copy(txt);
      }
    }

    const copyPassBtn = document.getElementById('copyPassBtn');
    if (copyPassBtn){
      copyPassBtn.onclick = function(){
        const txt = document.getElementById('viewPassword')?.dataset?.real || '';
        copy(txt);
      }
    }
  });

  // Clear sensitive fields when closing
  viewModal.addEventListener('hidden.bs.modal', function () {
    ['lblUsername','valUsername','lblPassword','valPassword'].forEach(id=>show(id,false));
    ['viewUsername','viewPassword','viewFullName','viewLastName','viewFirstName',
     'viewMiddleName','viewSuffix','viewStreetAddress','viewBirthDate','viewOccupation'
    ].forEach(id=>setText(id,''));
    setText('chipSex','Sex: —'); setText('chipStatus','Status: —'); setText('chipAge','Age: —');
  });
})();
</script>
