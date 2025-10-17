<?php
// pages/faq.php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
  http_response_code(403);
  require_once __DIR__ . '/../security/403.html';
  exit;
}

require_once __DIR__ . '/../include/connection.php';
session_start(); // start first to avoid double-start in includes below
require_once __DIR__ . '/../class/session_timeout.php'; // AJAX-aware below

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
<link rel="stylesheet" href="css/Notice/faq.css?v=5">

<div class="container my-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-question-circle-fill me-2"></i>FAQs</h4>
    <button id="openFaqBtn" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#faqModal">
      <i class="bi bi-plus-circle me-1"></i> Add FAQ
    </button>
  </div>

  <div class="card faq-card">
    <div class="table-band d-flex align-items-center justify-content-between px-3 py-3">
      <div class="fw-semibold small text-muted">Manage entries and quick actions</div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 400px;">Question</th>
              <th style="width: 400px;">Answer</th>
              <th style="width: 140px;">Status</th>
              <th style="width: 160px;">Created</th>
              <th class="text-center" style="width: 120px;">Actions</th>
            </tr>
          </thead>
          <tbody id="faqTableBody">
            <?php if ($result && $result->num_rows): ?>
              <?php while ($row = $result->fetch_assoc()): ?>
                <tr id="faq-row-<?php echo (int)$row['faq_id']; ?>">
                  <td class="faq-q"><?php echo h(mb_strimwidth((string)$row['faq_question'], 0, 120, '…')); ?></td>
                  <td class="faq-a"><?php echo h(mb_strimwidth(strip_tags((string)$row['faq_answer']), 0, 140, '…')); ?></td>
                  <td class="faq-status">
                    <?php if ($row['faq_status'] === 'Active'): ?>
                      <span class="badge bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="faq-created"><?php echo h(date('Y-m-d H:i', strtotime((string)$row['created_at']))); ?></td>
                  <td class="text-center">
                    <button type="button" class="btn btn-light btn-sm me-1 action-view-edit" title="View / Edit" data-id="<?php echo (int)$row['faq_id']; ?>">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <button type="button" class="btn btn-light btn-sm action-archive" title="Archive" data-id="<?php echo (int)$row['faq_id']; ?>">
                      <i class="bi bi-archive"></i>
                    </button>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5">
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

<!-- View/Edit Modal -->
<div class="modal fade" id="faqEditModal" tabindex="-1" aria-labelledby="faqEditModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="faqEditForm" method="post" action="ajax/faq_create.php?action=update" class="needs-validation" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="faqEditModalLabel">View / Edit FAQ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="faq_id" id="edit_faq_id">

          <div class="mb-3">
            <label class="form-label" for="edit_faq_question">Question <span class="text-danger">*</span></label>
            <textarea id="edit_faq_question" name="faq_question" class="form-control" rows="3" maxlength="1000" required></textarea>
            <div class="invalid-feedback">Question is required.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="edit_faq_answer">Answer <span class="text-danger">*</span></label>
            <textarea id="edit_faq_answer" name="faq_answer" class="form-control" rows="8" maxlength="10000" required></textarea>
            <div class="invalid-feedback">Answer is required.</div>
          </div>

          <div class="mb-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>Use the Archive action in the table to deactivate this FAQ.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">
            <span class="spinner-border spinner-border-sm me-1 d-none" id="faqEditSpinner" role="status" aria-hidden="true"></span>
            Update FAQ
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
document.querySelector('.d-flex.align-items-center.justify-content-between.mb-3').style.zIndex = '5';
const csrfToken = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

// Bootstrap validations
(() => {
  'use strict';
  const addForm = document.getElementById('faqForm');
  addForm.addEventListener('submit', (ev) => {
    if (!addForm.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
    addForm.classList.add('was-validated');
  }, false);

  const editForm = document.getElementById('faqEditForm');
  editForm.addEventListener('submit', (ev) => {
    if (!editForm.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
    editForm.classList.add('was-validated');
  }, false);
})();

// Helpers
const truncate = (s, n) => (s.length > n ? s.slice(0, n - 1) + '…' : s);
const stripTags = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d.textContent || d.innerText || ''; };
async function ensureJson(res) {
  const ct = res.headers.get('content-type') || '';
  if (!ct.includes('application/json')) {
    const text = await res.text();
    throw new Error(/<!DOCTYPE|<html/i.test(text) ? 'Session expired or redirected to login. Please refresh and sign in again.' : (text.slice(0,200) || 'Non-JSON response'));
  }
  return res.json();
}

// Open Add modal programmatically if needed
document.getElementById('openFaqBtn')?.addEventListener('click', () => {
  const el = document.getElementById('faqModal');
  if (window.bootstrap && bootstrap.Modal.getOrCreateInstance) {
    bootstrap.Modal.getOrCreateInstance(el).show();
  }
});

// CREATE
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
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    const json = await ensureJson(res);
    spinner.classList.add('d-none');

    if (json.success) {
      if (window.Swal) await Swal.fire({ icon: 'success', title: 'Saved', text: json.message || 'FAQ created successfully.' });
      const tbody = document.getElementById('faqTableBody');
      if (json.row_html) {
        const temp = document.createElement('tbody');
        temp.innerHTML = json.row_html.trim();
        const newRow = temp.firstElementChild;
        if (newRow) tbody.prepend(newRow);
      } else if (json.faq) {
        const tr = document.createElement('tr');
        tr.id = `faq-row-${json.faq.faq_id}`;
        tr.innerHTML = `
          <td class="faq-q">${truncate(json.faq.faq_question, 120)}</td>
          <td class="faq-a">${truncate(stripTags(json.faq.faq_answer), 140)}</td>
          <td class="faq-status"><span class="badge bg-success">Active</span></td>
          <td class="faq-created">${json.faq.created_at || ''}</td>
          <td class="text-center">
            <button type="button" class="btn btn-light btn-sm me-1 action-view-edit" title="View / Edit" data-id="${json.faq.faq_id}">
              <i class="bi bi-pencil-square"></i>
            </button>
            <button type="button" class="btn btn-light btn-sm action-archive" title="Archive" data-id="${json.faq.faq_id}">
              <i class="bi bi-archive"></i>
            </button>
          </td>`;
        tbody.prepend(tr);
      }

      // Close and reset
      const modalEl = document.getElementById('faqModal');
      (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
      setTimeout(() => {
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        form.reset();
        form.classList.remove('was-validated');
      }, 150);
    } else {
      (window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'Failed to save.' }) : alert(json.message || 'Failed to save.'));
    }
  } catch (err) {
    spinner.classList.add('d-none');
    (window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: err.message }) : alert(err.message));
  }
});

// VIEW / EDIT (load)
document.getElementById('faqTableBody').addEventListener('click', async (e) => {
  const btn = e.target.closest('.action-view-edit');
  if (!btn) return;
  const id = btn.getAttribute('data-id');

  try {
    const res = await fetch(`ajax/faq_create.php?action=get&id=${encodeURIComponent(id)}`, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    const json = await ensureJson(res);
    if (!json.success) throw new Error(json.message || 'Failed to load FAQ');

    document.getElementById('edit_faq_id').value = json.faq.faq_id;
    document.getElementById('edit_faq_question').value = json.faq.faq_question;
    document.getElementById('edit_faq_answer').value = json.faq.faq_answer;

    const editEl = document.getElementById('faqEditModal');
    bootstrap.Modal.getOrCreateInstance(editEl).show();
  } catch (err) {
    (window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: err.message }) : alert(err.message));
  }
});

// UPDATE (submit)
document.getElementById('faqEditForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  if (!form.checkValidity()) return;
  const spinner = document.getElementById('faqEditSpinner');
  spinner.classList.remove('d-none');

  try {
    const fd = new FormData(form);
    fd.set('csrf_token', csrfToken);

    const res = await fetch(form.action, {
      method: 'POST',
      body: fd,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    const json = await ensureJson(res);
    spinner.classList.add('d-none');

    if (json.success) {
      if (window.Swal) await Swal.fire({ icon: 'success', title: 'Updated', text: json.message || 'FAQ updated successfully.' });

      const row = document.getElementById(`faq-row-${json.faq.faq_id}`);
      if (row) {
        row.querySelector('.faq-q').textContent = truncate(json.faq.faq_question, 120);
        row.querySelector('.faq-a').textContent = truncate(stripTags(json.faq.faq_answer), 140);
      }

      const modalEl = document.getElementById('faqEditModal');
      (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
      setTimeout(() => {
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
      }, 150);
    } else {
      (window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'Failed to update.' }) : alert(json.message || 'Failed to update.'));
    }
  } catch (err) {
    spinner.classList.add('d-none');
    (window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: err.message }) : alert(err.message));
  }
});

// ARCHIVE
document.getElementById('faqTableBody').addEventListener('click', async (e) => {
  const btn = e.target.closest('.action-archive');
  if (!btn) return;
  const id = btn.getAttribute('data-id');

  const ok = window.Swal
    ? (await Swal.fire({ icon:'warning', title:'Archive FAQ?', text:'This will mark the FAQ as Inactive.', showCancelButton:true, confirmButtonText:'Yes, archive it' })).isConfirmed
    : confirm('Archive this FAQ?');
  if (!ok) return;

  try {
    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    fd.set('faq_id', id);

    const res = await fetch('ajax/faq_create.php?action=archive', {
      method: 'POST',
      body: fd,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    const json = await ensureJson(res);

    if (json.success) {
      const row = document.getElementById(`faq-row-${id}`);
      if (row) row.querySelector('.faq-status').innerHTML = '<span class="badge bg-secondary">Inactive</span>';
      (window.Swal ? Swal.fire({ icon:'success', title:'Archived', text: json.message || 'FAQ archived.' }) : alert(json.message || 'FAQ archived.'));
    } else {
      (window.Swal ? Swal.fire({ icon:'error', title:'Error', text: json.message || 'Failed to archive.' }) : alert(json.message || 'Failed to archive.'));
    }
  } catch (err) {
    (window.Swal ? Swal.fire({ icon:'error', title:'Error', text: err.message }) : alert(err.message));
  }
});
</script>
