<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';

/* Optional: safe fallbacks for redirects if not set elsewhere */
$redirects = $redirects ?? [
    'case_list'    => 'case_list.php',
    'appointments' => 'view_appointments.php',
];

/* Residents for dropdown */
$residents = [];
$q = "SELECT id,
             TRIM(CONCAT(first_name,' ',IFNULL(middle_name,''),' ',last_name,' ',IFNULL(suffix_name,''))) AS full_name
      FROM residents
      WHERE resident_delete_status = 0
      ORDER BY last_name, first_name";
$r = $mysqli->query($q);
while ($row = $r->fetch_assoc()) { $residents[] = $row; }

/* Certificates */
$certificates = [];
$certQ = "SELECT Certificates_Name FROM certificates WHERE status='Active' ORDER BY Certificates_Name";
$certR = $mysqli->query($certQ);
while ($row = $certR->fetch_assoc()) { $certificates[] = $row['Certificates_Name']; }
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Urgent Appointment - Admin</title>

  <!-- Vendor CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4@5.0.16/bootstrap-4.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- App CSS (your palette + tweaks) -->
  <link rel="stylesheet" href="css/urgent/urgent.css">
</head>
<body>

<main class="container py-4">
  <div class="card app-card shadow-lg rounded-4 p-3 p-md-5 mb-5">
    <header class="text-center mb-4">
      <h1 class="h3 h2-md fw-bold d-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-exclamation-triangle-fill text-danger"></i>
        Urgent Request
      </h1>
      <p class="text-muted mb-0 small">Create and submit an urgent appointment for a resident.</p>
    </header>

    <!-- Step 1: Resident -->
    <section aria-labelledby="resident-label" class="mb-4">
      <label id="resident-label" for="residentSelect" class="form-label fw-semibold">Select Resident</label>
      <select id="residentSelect" class="form-select form-select-lg shadow-sm rounded-3" required>
        <option value="">-- Choose Resident --</option>
        <?php foreach ($residents as $resident): ?>
          <option value="<?= (int)$resident['id'] ?>"><?= htmlspecialchars($resident['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Start typing to search; press Enter to pick.</div>
    </section>

    <!-- Resident details -->
    <section id="residentDetails" class="d-none" aria-live="polite">
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header fw-bold">Resident Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <div class="detail">
                <span class="detail-label">Name</span>
                <span id="residentName" class="detail-value"></span>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="detail">
                <span class="detail-label">Birth Date</span>
                <span id="residentBirthDate" class="detail-value"></span>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="detail">
                <span class="detail-label">Birth Place</span>
                <span id="residentBirthPlace" class="detail-value"></span>
              </div>
            </div>
            <div class="col-12">
              <div class="detail">
                <span class="detail-label">Address</span>
                <span id="residentAddress" class="detail-value"></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Form -->
    <form id="urgentForm" novalidate>
      <!-- Step 2: Certificate -->
      <section id="certificateContainer" class="mb-4 d-none" aria-labelledby="cert-label">
        <label id="cert-label" for="CertificateSelect" class="form-label fw-semibold">Certificate</label>
        <select id="CertificateSelect" class="form-select" required>
          <option value="">-- Choose Certificate --</option>
          <?php foreach ($certificates as $certName): ?>
            <option value="<?= htmlspecialchars($certName) ?>"><?= htmlspecialchars($certName) ?></option>
          <?php endforeach; ?>
        </select>
      </section>

      <!-- Cedula action -->
      <section id="cedulaActionContainer" class="mb-3 d-none">
        <label for="cedulaActionSelect" class="form-label">Choose Cedula Action</label>
        <select id="cedulaActionSelect" class="form-select">
          <option value="">-- Choose Action --</option>
          <option value="upload">Upload Existing Cedula</option>
          <option value="request">Request New Cedula</option>
        </select>
      </section>

      <!-- Declared income -->
      <section id="incomeContainer" class="mb-3 d-none">
        <label for="incomeInput" class="form-label">Declared Income</label>
        <input type="number" step="0.01" id="incomeInput" class="form-control" placeholder="e.g., 15000.00" inputmode="decimal" min="0">
      </section>

      <!-- Upload Cedula -->
      <section id="uploadCedulaContainer" class="mb-3 border rounded-4 p-3 d-none">
        <h5 class="fw-bold mb-3">Upload Existing Cedula</h5>
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label for="cedulaNumber" class="form-label">Cedula Number</label>
            <input type="text" class="form-control" id="cedulaNumber" autocomplete="off">
          </div>
          <div class="col-6 col-md-4">
            <label for="dateIssued" class="form-label">Date Issued</label>
            <input type="date" class="form-control" id="dateIssued">
          </div>
          <div class="col-6 col-md-4">
            <label for="issuedAt" class="form-label">Issued At</label>
            <input type="text" class="form-control" id="issuedAt" placeholder="City/Municipality">
          </div>
          <div class="col-12 col-md-4">
            <label for="incomeUpload" class="form-label">Income</label>
            <input type="number" class="form-control" id="incomeUpload" placeholder="e.g., 15000.00" inputmode="decimal" min="0">
          </div>
          <div class="col-12 col-md-8">
            <label for="cedulaFile" class="form-label">Upload Cedula File (PDF, JPG, PNG)</label>
            <input type="file" class="form-control" id="cedulaFile" accept=".pdf,.jpg,.jpeg,.png">
          </div>
        </div>
      </section>

      <!-- BESO fields -->
      <section id="besoFields" class="mb-3 d-none">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label for="educationAttainment" class="form-label">Educational Attainment</label>
            <input type="text" id="educationAttainment" class="form-control" placeholder="e.g., College Graduate">
          </div>
          <div class="col-12 col-md-6">
            <label for="course" class="form-label">Course</label>
            <input type="text" id="course" class="form-control" placeholder="e.g., BSIT">
          </div>
        </div>
      </section>

      <!-- Purpose -->
      <section id="purposeContainer" class="mb-3 d-none">
        <label for="purposeSelect" class="form-label">Purpose</label>
        <select id="purposeSelect" class="form-select">
          <option value="">-- Choose Purpose --</option>
        </select>
      </section>

      <section id="customPurposeContainer" class="mb-4 d-none">
        <label for="customPurposeInput" class="form-label">Please specify</label>
        <input type="text" id="customPurposeInput" class="form-control" placeholder="Enter custom purpose">
      </section>

      <!-- Submit -->
      <div class="position-relative">
        <button type="submit" class="btn btn-danger btn-lg w-100 sticky-submit">
          <i class="bi bi-send-fill me-2"></i>
          Submit Urgent Appointment
        </button>
      </div>
    </form>
  </div>
</main>

<!-- Vendor JS -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.4/dist/sweetalert2.all.min.js"></script>

<!-- Inline JS -->
<script>
(() => {
  const $resident = $('#residentSelect');
  const $detailsWrap = $('#residentDetails');
  const $certWrap = $('#certificateContainer');
  const $cert = $('#CertificateSelect');
  const $purposeWrap = $('#purposeContainer');
  const $purpose = $('#purposeSelect');
  const $customPurposeWrap = $('#customPurposeContainer');
  const $customPurpose = $('#customPurposeInput');
  const $cedulaActionWrap = $('#cedulaActionContainer');
  const $cedulaAction = $('#cedulaActionSelect');
  const $incomeWrap = $('#incomeContainer');
  const $income = $('#incomeInput');
  const $uploadCedulaWrap = $('#uploadCedulaContainer');
  const $besoWrap = $('#besoFields');

  let residentDetails = null;

  /* Enhance selects */
  $resident.select2({
    placeholder: 'Search resident...',
    width: '100%'
  });

  /* Helpers */
  const show = ($el, on = true) => $el.toggleClass('d-none', !on);
  const toast = (icon, title) => Swal.fire({toast:true,position:'top',showConfirmButton:false, timer:2500, icon, title});

  /* On resident change -> fetch details */
  $resident.on('change', function () {
    show($certWrap, false);
    show($detailsWrap, false);
    residentDetails = null;

    const id = this.value;
    if (!id) return;

    fetch('ajax/fetch_resident_details.php?id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        if (!data?.success) {
          Swal.fire('Error', 'Resident not found.', 'error');
          return;
        }

        residentDetails = data;

        $('#residentName').text(data.full_name || '');
        $('#residentBirthDate').text(data.birth_date || '');
        $('#residentBirthPlace').text(data.birth_place || '');
        $('#residentAddress').text(['Zone ' + (data.res_zone||''), 'Phase ' + (data.res_street_address||'')].join(', '));

        /* Age-based disabling */
        const age = parseInt(data.age || '0', 10);
        $cert.val('');
        let disabledCount = 0;
        $cert.find('option').each(function () {
          const val = $(this).val();
          if (!val) return;
          if (age < 18 && (val === 'Barangay Clearance' || val === 'BESO Application')) {
            $(this).prop('disabled', true).text(val + ' (Not allowed for under 18)');
            disabledCount++;
          } else {
            $(this).prop('disabled', false).text(val);
          }
        });

        if (disabledCount >= $cert.find('option').length - 1) {
          Swal.fire({icon:'warning', title:'No Available Certificates', text:'No certificates are available for residents under 18.'});
        }

        show($detailsWrap, true);
        show($certWrap, true);
      })
      .catch(() => Swal.fire('Error','Error fetching resident details.','error'));
  });

  /* Certificate change */
  $cert.on('change', function () {
    const selected = this.value;
    const isCedula = selected === 'Cedula';
    const isClearance = selected === 'Barangay Clearance';
    const isBeso = selected === 'BESO Application';

    show($cedulaActionWrap, isCedula);
    show($incomeWrap, false);
    show($uploadCedulaWrap, false);
    show($besoWrap, isBeso);
    show($customPurposeWrap, false);

    $purpose.html('<option value="">-- Choose Purpose --</option>');
    if (isCedula) {
      $purpose.prop('required', false);
      show($purposeWrap, false);
    } else {
      $purpose.prop('required', true);
      show($purposeWrap, true);
    }

    if (!residentDetails) return;

    /* BESO restrictions */
    if (isBeso) {
      const status = (residentDetails.cedula_status || '').toLowerCase();
      if (['pending', 'approved', 'rejected'].includes(status)) {
        Swal.fire('Not Allowed','Resident must acquire Cedula first.','warning');
        $(this).val('');
        show($besoWrap, false);
        show($purposeWrap, false);
        return;
      }
      if (residentDetails.has_residency_used) {
        Swal.fire('Limit Reached','Already used Barangay Residency for BESO.','warning');
        $(this).val('');
        show($besoWrap, false);
        show($purposeWrap, false);
        return;
      }
      if (!residentDetails.has_residency) {
        Swal.fire('Missing Certificate','No Barangay Residency for First Time Jobseeker.','warning');
        $(this).val('');
        show($besoWrap, false);
        show($purposeWrap, false);
        return;
      }
      if (residentDetails.has_existing_beso) {
        Swal.fire('Duplicate','BESO record already exists.','warning');
        $(this).val('');
        show($besoWrap, false);
        show($purposeWrap, false);
        return;
      }
    }

    /* Ongoing case restriction */
    if (isClearance && residentDetails.has_ongoing_case) {
      Swal.fire('Blocked','Resident has an ongoing case. Redirecting...','warning')
        .then(() => window.location.href = "<?= htmlspecialchars($redirects['case_list']) ?>");
      return;
    }

    /* Pending duplicate certificate */
    const pendingCerts = residentDetails.pending_certificates || [];
    if (pendingCerts.includes(selected)) {
      Swal.fire('Pending Certificate','Already has a pending ' + selected + '.','warning')
        .then(() => window.location.href = "<?= htmlspecialchars($redirects['appointments']) ?>");
      return;
    }

    if (isCedula && residentDetails.has_approved_cedula) {
      Swal.fire('Pending','Cedula request already approved.','warning');
      $(this).val('');
      show($cedulaActionWrap, false);
      return;
    }

    if (isCedula && residentDetails.has_pending_cedula) {
      Swal.fire('Pending','Cedula request already pending.','warning')
        .then(() => window.location.href = "<?= htmlspecialchars($redirects['appointments']) ?>");
      return;
    }

    if (!isCedula && !residentDetails.has_approved_cedula) {
      Swal.fire('Required','Must acquire Cedula first.','warning');
      $cert.val('Cedula').trigger('change');
      return;
    }

    /* Load purposes for non-cedula */
    if (!isCedula && selected) {
      show($purposeWrap, true);
      fetch('ajax/fetch_purposes_by_certificate.php?cert=' + encodeURIComponent(selected))
        .then(r => r.json())
        .then(data => {
          const list = Array.isArray(data) ? data : [];
          list.forEach(p => $purpose.append(`<option value="${p.purpose_name}">${p.purpose_name}</option>`));
        //   $purpose.append('<option value="others">Others</option>');
        })
        .catch(() => {
          $purpose.html('<option value="">Error loading purposes</option><option value="others">Others</option>');
        });
    }
  });

  /* Cedula action toggle */
  $cedulaAction.on('change', function () {
    const action = this.value;
    show($incomeWrap, action === 'request');
    show($uploadCedulaWrap, action === 'upload');
  });

  /* Purpose change (others, FTJ checks) */
  $purpose.on('change', function () {
    const val = this.value;
    show($customPurposeWrap, val === 'others');

    const selectedCert = $cert.val();

    if (selectedCert === 'Barangay Residency' && val === 'First Time Jobseeker') {
      if (residentDetails?.has_residency) {
        Swal.fire('Duplicate','Already has a Barangay Residency for First Time Jobseeker.','warning');
        $(this).val('');
        show($customPurposeWrap, false);
        return;
      }
    }

    /* BESO used flags & requirements for FTJ freebies */
    if ((selectedCert === 'Barangay Clearance' || selectedCert === 'Barangay Indigency') && val === 'First Time Jobseeker') {
      if (!residentDetails?.has_existing_beso) {
        Swal.fire('Missing BESO','You must apply for BESO first before requesting this free certificate.','warning');
        $(this).val('');
        show($customPurposeWrap, false);
        return;
      }
      if (
        (selectedCert === 'Barangay Clearance' && residentDetails?.has_clearance_used) ||
        (selectedCert === 'Barangay Indigency' && residentDetails?.has_indigency_used)
      ) {
        Swal.fire('Limit Reached','Already used free ' + selectedCert + ' for First Time Jobseeker.','warning');
        $(this).val('');
        show($customPurposeWrap, false);
        return;
      }

      const payload = {
        res_id: $resident.val(),
        field: (selectedCert === 'Barangay Clearance') ? 'used_for_clearance' : 'used_for_indigency'
      };

      fetch('ajax/mark_beso_used.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then(d => { if (!d?.success) console.warn('Failed to update BESO status.'); })
      .catch(err => console.error('Error marking BESO usage:', err));
    }
  });

  /* Submit */
  $('#urgentForm').on('submit', function (e) {
    e.preventDefault();

    const resId = $resident.val();
    const certificate = $cert.val();
    const isCedula = certificate === 'Cedula';
    const isBESO = certificate === 'BESO Application';

    if (!residentDetails) { Swal.fire('Missing','Please select a resident.','warning'); return; }

    const selectedPurpose = $purpose.val();
    const customPurpose = ($customPurpose.val() || '').trim();
    const finalPurpose = (selectedPurpose === 'others') ? customPurpose : selectedPurpose;

    if (!isCedula && !finalPurpose) { Swal.fire('Missing','Please provide a valid purpose.','warning'); return; }

    /* Cedula branch */
    if (isCedula) {
      const action = $cedulaAction.val();
      if (!action) { Swal.fire('Required','Please choose an action for Cedula.','warning'); return; }

      if (action === 'request') {
        const income = parseFloat($income.val()) || 0;
        if (!income) { Swal.fire('Missing','Please enter declared income.','warning'); return; }

        const payload = { userId: resId, urgent: true, certificate, purpose: 'Cedula Application', income };

        Swal.fire({title:'Submit Cedula Request?',text:'Are you sure you want to proceed?',icon:'question',showCancelButton:true,confirmButtonText:'Yes, submit'})
          .then((r) => {
            if (!r.isConfirmed) return;
            Swal.showLoading();
            fetch('class/save_schedule.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
              .then(resp => resp.text())
              .then(raw => {
                try {
                  const data = JSON.parse(raw);
                  if (data.success) {
                    Swal.fire({icon:'success',title:'Submitted!',text:'Cedula request submitted successfully.'})
                      .then(() => window.location.reload());
                  } else {
                    Swal.fire({icon:'error',title:'Failed',text:data.message || 'Something went wrong while saving.'});
                  }
                } catch { Swal.fire({icon:'error',title:'Invalid Response',text:'Server returned invalid data.',footer:`<pre>${raw}</pre>`}); }
              })
              .catch(() => Swal.fire({icon:'error',title:'Network Error',text:'Could not connect to the server. Please try again.'}));
          });
        return;
      }

      if (action === 'upload') {
        const cedulaNumber = $('#cedulaNumber').val().trim();
        const dateIssued = $('#dateIssued').val();
        const issuedAt = $('#issuedAt').val().trim();
        const incomeUpload = parseFloat($('#incomeUpload').val()) || 0;
        const cedulaFile = $('#cedulaFile')[0].files[0];

        if (!cedulaNumber || !dateIssued || !issuedAt || !incomeUpload || !cedulaFile) {
          Swal.fire('Missing','Please fill in all Cedula upload fields.','warning'); return;
        }

        const formData = new FormData();
        formData.append('userId', resId);
        formData.append('certificate', certificate);
        formData.append('urgent', true);
        formData.append('action', 'upload');
        formData.append('cedulaNumber', cedulaNumber);
        formData.append('dateIssued', dateIssued);
        formData.append('issuedAt', issuedAt);
        formData.append('income', incomeUpload);
        formData.append('cedulaFile', cedulaFile);

        fetch('class/save_urgent_cedula.php', { method:'POST', body: formData })
          .then(r => r.json())
          .then(d => {
            if (d.success) Swal.fire('Success','Cedula uploaded successfully.','success').then(() => window.location.reload());
            else Swal.fire('Error', d.message || 'Failed to upload Cedula.','error');
          })
          .catch(() => Swal.fire('Error','Upload failed.','error'));
        return;
      }
    }

    /* BESO branch */
    if (isBESO) {
      const attainment = $('#educationAttainment').val().trim();
      const course = $('#course').val().trim();
      if (!attainment || !course) { Swal.fire('Missing','Please fill in both education attainment and course.','warning'); return; }

      const besoPayload = { userId: resId, certificate, urgent: true, education_attainment: attainment, course, purpose: finalPurpose };

      fetch('class/save_schedule.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(besoPayload) })
        .then(r => r.text())
        .then(raw => {
          let data;
          try { data = JSON.parse(raw); } catch { Swal.fire('Error','Invalid server response.','error'); return; }
          if (!data.success) { Swal.fire('Urgent Request Failed', data.message || 'Could not save urgent request.','error'); return; }

          Swal.fire({title:'Submit BESO Record?', text:'Do you want to finalize and save the BESO application?', icon:'question', showCancelButton:true, confirmButtonText:'Yes, submit'})
            .then((res) => {
              if (!res.isConfirmed) return;
              fetch('class/save_beso.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(besoPayload) })
                .then(r => r.json())
                .then(bd => {
                  if (bd.success) Swal.fire({icon:'success',title:'Submitted!',text:'BESO application successfully recorded.'}).then(() => window.location.reload());
                  else Swal.fire({icon:'error',title:'Failed',text: bd.message || 'Failed to save BESO data.'});
                })
                .catch(() => Swal.fire({icon:'error',title:'Error',text:'An unexpected error occurred while saving the BESO record.'}));
            });
        })
        .catch(() => Swal.fire('Error','An unexpected error.','error'));
      return;
    }

    /* Other certificates */
    const payload = { userId: resId, urgent: true, certificate, purpose: finalPurpose };

    Swal.fire({title:'Submit Urgent Request?',text:'Are you sure you want to submit this request?',icon:'question',showCancelButton:true,confirmButtonText:'Yes, submit'})
      .then((res) => {
        if (!res.isConfirmed) return;
        Swal.showLoading();
        fetch('class/save_schedule.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
          .then(resp => resp.text())
          .then(raw => {
            try {
              const data = JSON.parse(raw);
              if (data.success) {
                Swal.fire({icon:'success',title:'Request Submitted',text:'Your urgent request was submitted successfully!'})
                  .then(() => window.location.reload());
              } else {
                Swal.fire({icon:'error',title:'Submission Failed',text:data.message || 'Something went wrong during submission.'});
              }
            } catch (e) {
              Swal.fire({icon:'error',title:'Invalid Server Response',text:'Could not parse the response. Please contact the admin.',footer:`<pre>${raw}</pre>`});
            }
          })
          .catch(() => Swal.fire({icon:'error',title:'Request Error',text:'Failed to send the request. Please check your connection.'}));
      });
  });

  /* Minor UX: auto-scroll to form when certificate shown */
  $cert.on('select2:open', () => {
    document.querySelector('#certificateContainer')?.scrollIntoView({behavior:'smooth', block:'center'});
  });
})();
</script>
</body>
</html>
