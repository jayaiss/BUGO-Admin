<?php
// pages/faq.php
declare(strict_types=1);
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../class/session_timeout.php';
session_start();

if (empty($_SESSION['employee_id'])) {
    header('Location: ../index.php');
    exit;
}

$mysqli = db_connection();

// Build/refresh CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Fetch FAQs (latest first)
$sql = "
    SELECT f.faq_id, f.faq_question, f.faq_answer, f.faq_status, f.created_at
    FROM faqs f
    LEFT JOIN employee_list e ON e.employee_id = f.employee_id
    ORDER BY f.faq_id DESC
    LIMIT 200
";
$result = $mysqli->query($sql);

// Safe output helper
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<link rel="stylesheet" href="css/Notice/faq.css?v=3">

<div class="container my-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-question-circle-fill me-2"></i>FAQs</h4>
    <button id="openFaqBtn" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#faqModal">
      <i class="bi bi-plus-circle me-1"></i> Add FAQ
    </button>
  </div>

  <div class="card faq-card">
    <!-- Beige band header -->
    <div class="table-band d-flex align-items-center justify-content-between px-3 py-3">
      <div class="fw-semibold small text-muted">Manage entries and quick actions</div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 450px;">Question</th>
              <th style="width: 450px;">Answer</th>
              <th style="width: 450px;">Status</th>
              <th style="width: 450px;">Created</th>
            </tr>
          </thead>
          <tbody id="faqTableBody">
            <?php if ($result && $result->num_rows): ?>
              <?php while ($row = $result->fetch_assoc()): ?>
                <tr id="faq-row-<?php echo (int)$row['faq_id']; ?>">
                  <td><?php echo h(mb_strimwidth((string)$row['faq_question'], 0, 120, '…')); ?></td>
                  <td><?php echo h(mb_strimwidth(strip_tags((string)$row['faq_answer']), 0, 140, '…')); ?></td>
                  <td>
                    <?php if ($row['faq_status'] === 'Active'): ?>
                      <span class="badge bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo h(date('Y-m-d H:i', strtotime((string)$row['created_at']))); ?></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="4">
                  <div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-inboxes"></i></div>
                    <div class="empty-text">No FAQs found</div>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add FAQ Modal -->
<div class="modal fade" id="faqModal" tabindex="-1" aria-labelledby="faqModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="faqForm" method="post" action="ajax/faq_create.php" class="needs-validation" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="faqModalLabel">Add FAQ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="faq_status" value="Active">

          <div class="mb-3">
            <label class="form-label" for="faq_question">Question <span class="text-danger">*</span></label>
            <textarea id="faq_question" name="faq_question" class="form-control" rows="2" maxlength="1000" required></textarea>
            <div class="invalid-feedback">Question is required.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="faq_answer">Answer <span class="text-danger">*</span></label>
            <textarea id="faq_answer" name="faq_answer" class="form-control" rows="6" maxlength="10000" required></textarea>
            <div class="invalid-feedback">Answer is required.</div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <span class="spinner-border spinner-border-sm me-1 d-none" id="faqSubmitSpinner" role="status" aria-hidden="true"></span>
            Save FAQ
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bootstrap Bundle (needed for modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
// Ensure top bar stays clickable
document.querySelector('.d-flex.align-items-center.justify-content-between.mb-3').style.zIndex = '5';

// Bootstrap form validation
(() => {
  'use strict';
  const form = document.getElementById('faqForm');
  form.addEventListener('submit', (event) => {
    if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
    form.classList.add('was-validated');
  }, false);
})();

// Fallback: open modal via JS if data-bs-toggle fails
document.getElementById('openFaqBtn')?.addEventListener('click', (e) => {
  const el = document.getElementById('faqModal');
  if (window.bootstrap && bootstrap.Modal.getOrCreateInstance) {
    bootstrap.Modal.getOrCreateInstance(el).show();
  }
});

// AJAX submit
document.getElementById('faqForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  if (!form.checkValidity()) return;

  const spinner = document.getElementById('faqSubmitSpinner');
  spinner.classList.remove('d-none');

  try {
    const res = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'Accept': 'application/json' }
    });
    const json = await res.json();
    spinner.classList.add('d-none');

    if (json.success) {
      if (window.Swal) {
        await Swal.fire({ icon: 'success', title: 'Saved', text: json.message || 'FAQ created successfully.' });
      } else {
        alert(json.message || 'FAQ created successfully.');
      }

      if (json.row_html) {
        const tbody = document.getElementById('faqTableBody');
        const temp = document.createElement('tbody');
        temp.innerHTML = json.row_html.trim();
        const newRow = temp.firstElementChild;
        if (newRow) tbody.prepend(newRow);
      }

      const modalEl = document.getElementById('faqModal');
      (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
      setTimeout(() => {
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
      }, 150);
    } else {
      (window.Swal
        ? Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'Failed to save.' })
        : alert(json.message || 'Failed to save.'));
    }
  } catch (err) {
    spinner.classList.add('d-none');
    (window.Swal
      ? Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' })
      : alert('Network error. Please try again.'));
  }
});
</script>
