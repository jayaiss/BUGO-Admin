<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

require_once __DIR__ . '/../../include/encryption.php'; // align with reference path
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';

$user_role = $_SESSION['Role_Name'] ?? '';
$residents = [];

/* --- Eligible residents (Released Residency FTJ & not yet used for BESO) --- */
$sql = "
    SELECT DISTINCT
        r.id,
        TRIM(CONCAT(
            r.first_name, ' ',
            COALESCE(NULLIF(r.middle_name, ''), ''), ' ',
            r.last_name,
            CASE WHEN COALESCE(r.suffix_name,'') <> '' THEN CONCAT(' ', r.suffix_name) ELSE '' END
        )) AS full_name
    FROM residents r
    WHERE r.resident_delete_status = 0
      AND (
        EXISTS (
          SELECT 1
          FROM schedules s
          WHERE s.res_id = r.id
            AND s.certificate = 'Barangay Residency'
            AND s.purpose = 'First Time Jobseeker'
            AND s.status = 'Released'
            AND COALESCE(s.barangay_residency_used_for_beso, 0) = 0
            AND COALESCE(s.appointment_delete_status, 0) = 0
        )
        OR
        EXISTS (
          SELECT 1
          FROM urgent_request u
          WHERE u.res_id = r.id
            AND u.certificate = 'Barangay Residency'
            AND u.purpose = 'First Time Jobseeker'
            AND u.status = 'Released'
            AND COALESCE(u.barangay_residency_used_for_beso, 0) = 0
            AND COALESCE(u.urgent_delete_status, 0) = 0
        )
      )
    ORDER BY full_name ASC
";


if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $residents[] = $row;
    }
    $result->free();
}

$certificates = ['BESO Application'];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Urgent BESO Request - Admin</title>

  <!-- Vendor CSS (match reference) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4@5.0.16/bootstrap-4.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- App CSS (reuse same palette/components) -->
  <link rel="stylesheet" href="css/urgent/urgent.css">
  <style>
    /* small safety if urgent.css isn't loaded yet */
    .app-card { border: 0; }
    .detail .detail-label{font-size:.8rem;color:#6c757d;display:block}
    .detail .detail-value{font-weight:600}
    .sticky-submit{position:sticky;bottom:0.5rem}
  </style>
</head>
<body>
<main class="container py-4">
  <div class="card app-card shadow-lg rounded-4 p-3 p-md-5 mb-5">
    <header class="text-center mb-4">
      <h1 class="h3 h2-md fw-bold d-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-exclamation-triangle-fill text-danger"></i>
        Urgent BESO Request
      </h1>
      <p class="text-muted mb-0 small">Create and submit a BESO (First Time Jobseeker) urgent appointment.</p>
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

      <!-- Form -->
      <form id="urgentForm" novalidate>
        <input type="hidden" id="CertificateSelect" value="BESO Application">

        <!-- BESO fields -->
        <section id="besoFields" class="mb-3">
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
        <section id="purposeContainer" class="mb-3" aria-labelledby="purpose-label">
          <label id="purpose-label" for="purposeSelect" class="form-label">Purpose</label>
          <select id="purposeSelect" class="form-select" required>
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
            Submit Urgent BESO Appointment
          </button>
        </div>
      </form>
    </section>
  </div>
</main>

<!-- Vendor JS (match reference) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.4/dist/sweetalert2.all.min.js"></script>

<!-- Inline JS (consistent behaviors with reference) -->
<script>
(() => {
  let residentDetails = null;
  const redirectUrl = "<?= enc_beso('urgent_request') ?>";

  const $resident = $('#residentSelect');
  const $detailsWrap = $('#residentDetails');
  const $purpose = $('#purposeSelect');
  const $customPurposeWrap = $('#customPurposeContainer');
  const $customPurpose = $('#customPurposeInput');

  // Enhance select
  $resident.select2({ placeholder: 'Search resident...', width: '100%' });

  // Helpers
  const show = ($el, on = true) => $el.toggleClass('d-none', !on);
  const toast = (icon, title) => Swal.fire({toast:true,position:'top',showConfirmButton:false, timer:2500, icon, title});

  // On resident change -> fetch details + guardrails
  $resident.on('change', function () {
    show($detailsWrap, false);
    residentDetails = null;

    const id = this.value;
    if (!id) return;

    fetch('ajax/fetch_resident_details.php?id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        if (!data?.success) {
          Swal.fire('Not Found','Resident not found.','error');
          return;
        }

        residentDetails = data;

        // FTJ BESO guardrails (match reference logic text/flow)
        if (parseInt(data.age || '0', 10) <= 17) {
          Swal.fire('Not Eligible','Resident is under 18 and not eligible for a BESO application.','error')
            .then(() => window.location.href = redirectUrl);
          return;
        }

        if (['pending','approved','rejected'].includes(String(data.cedula_status || '').toLowerCase())) {
          Swal.fire('Cedula Required','Resident must first acquire a Cedula. Please visit the Revenue Department.','warning')
            .then(() => window.location.href = redirectUrl);
          return;
        }

        if (data.has_residency_used) {
          Swal.fire('Already Used','This resident already used their Barangay Residency for BESO.','warning')
            .then(() => window.location.href = redirectUrl);
          return;
        }

        if (!data.has_residency) {
          Swal.fire('Missing Residency','No Barangay Residency for First Time Jobseeker.','warning')
            .then(() => window.location.href = redirectUrl);
          return;
        }

        if (data.has_existing_beso) {
          Swal.fire('Duplicate','BESO record already exists.','warning')
            .then(() => window.location.href = redirectUrl);
          return;
        }

        // Populate details card
        $('#residentName').text(data.full_name || '');
        $('#residentBirthDate').text(data.birth_date || '');
        $('#residentBirthPlace').text(data.birth_place || '');
        $('#residentAddress').text(['Zone ' + (data.res_zone||''), 'Phase ' + (data.res_street_address||'')].join(', '));

        // Load purposes for BESO
        fetch('ajax/fetch_purposes_by_certificate.php?cert=' + encodeURIComponent('BESO Application'))
          .then(r => r.json())
          .then(list => {
            $purpose.html('<option value="">-- Choose Purpose --</option>');
            (Array.isArray(list) ? list : []).forEach(p => {
              $purpose.append(`<option value="${p.purpose_name}">${p.purpose_name}</option>`);
            });
            // $purpose.append('<option value="others">Others</option>');
          })
          .catch(() => $purpose.html('<option value="">Error loading purposes</option><option value="others">Others</option>'));

        show($detailsWrap, true);
        document.querySelector('#residentDetails')?.scrollIntoView({behavior:'smooth', block:'start'});
      })
      .catch(() => Swal.fire('Error','Error fetching resident details.','error'));
  });

  // Toggle custom purpose
  $purpose.on('change', function () {
    show($customPurposeWrap, this.value === 'others');
  });

  // Submit
  $('#urgentForm').on('submit', function (e) {
    e.preventDefault();

    const resId = $resident.val();
    const attainment = $('#educationAttainment').val().trim();
    const course = $('#course').val().trim();
    const selectedPurpose = $purpose.val();
    const customPurpose = ($customPurpose.val() || '').trim();
    const finalPurpose = (selectedPurpose === 'others') ? customPurpose : selectedPurpose;

    if (!residentDetails) { Swal.fire('Missing','Please select a resident.','warning'); return; }
    if (!resId || !attainment || !course || !finalPurpose) {
      Swal.fire('Missing Info','Please complete all fields.','warning');
      return;
    }

    const payload = {
      userId: resId,
      certificate: 'BESO Application',
      urgent: true,
      education_attainment: attainment,
      course: course,
      purpose: finalPurpose
    };

    Swal.fire({title:'Submit BESO Record?', text:'Do you want to finalize and save the BESO application?', icon:'question', showCancelButton:true, confirmButtonText:'Yes, submit'})
      .then((res) => {
        if (!res.isConfirmed) return;

        fetch('class/save_schedule.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
          if (!data?.success) throw new Error(data?.message || 'Failed to save urgent request');
          return fetch('class/save_beso.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          });
        })
        .then(r => r.json())
        .then(bd => {
          if (bd?.success) {
            Swal.fire({icon:'success',title:'Success',text:'BESO application submitted.'})
              .then(() => window.location.reload());
          } else {
            Swal.fire('Error','Failed to save BESO: ' + (bd?.message || 'Unknown error'),'error');
          }
        })
        .catch(err => Swal.fire('System Error', err.message, 'error'));
      });
  });

  // Minor UX parity
  $resident.on('select2:open', () => {
    document.querySelector('#resident-label')?.scrollIntoView({behavior:'smooth', block:'center'});
  });
})();
</script>
</body>
</html>
