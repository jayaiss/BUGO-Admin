<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BESO Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    h1 { font-weight: 700; color: #2c3e50; }
    .card { border: none; border-radius: 1rem; box-shadow: 0 0 15px rgba(0,0,0,0.1); transition: transform 0.2s ease; }
    .card:hover { transform: translateY(-4px); }
    .stat-title { font-size: 0.9rem; color: #7f8c8d; letter-spacing: 0.05em; }
    .stat-value { font-size: 1.8rem; font-weight: 600; }
    .filter-label { font-size: .85rem; color: #6c757d; }
    @media (max-width: 768px) { canvas { max-height: 300px !important; } }
    /* Recent activity table tweaks */
    #recentBesoActivity td { white-space: nowrap; }
  </style>
</head>
<body>
<div class="container my-5">
  <h1 class="text-center mb-5"><?php echo htmlspecialchars($roleName); ?> Dashboard</h1>

  <!-- Filter Bar -->
  <div class="card p-3 mb-4">
    <form id="filters" class="row g-3 align-items-end">
      <div class="col-6 col-md-2">
        <label class="filter-label">Gender</label>
        <select class="form-select" name="gender" id="genderFilter">
          <option value="">All</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>

      <div class="col-6 col-md-3">
        <label class="filter-label">Status</label>
        <select class="form-select" name="status" id="statusFilter">
          <option value="">All</option>
          <option>Pending</option>
          <option>Rejected</option>
          <option>Approved</option>
          <option>ApprovedCaptain</option>
          <option>Released</option>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="filter-label">Min Age</label>
        <input type="number" min="0" class="form-control" name="min_age" id="minAge" placeholder="e.g. 18">
      </div>
      <div class="col-6 col-md-2">
        <label class="filter-label">Max Age</label>
        <input type="number" min="0" class="form-control" name="max_age" id="maxAge" placeholder="e.g. 35">
      </div>

      <div class="col-12 col-md-3">
        <label class="filter-label">Education</label>
        <select class="form-select" id="educationFilter" name="education">
          <!-- Populated from initial data keys -->
        </select>
      </div>

      <div class="col-6 col-md-2">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" value="1" id="urgentOnly">
          <label class="form-check-label" for="urgentOnly">Urgent only</label>
        </div>
      </div>

      <div class="col-12 col-md-5 d-flex gap-2">
        <button type="button" class="btn btn-primary flex-fill" id="applyBtn">Apply Filters</button>
        <button type="button" class="btn btn-outline-secondary flex-fill" id="resetBtn">Reset</button>
      </div>
    </form>
  </div>

  <!-- Stats Cards -->
  <div class="row g-4 mb-4">
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">TOTAL BESO APPLICATIONS</div>
        <div class="stat-value text-primary" id="totalBesoApplications">--</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">MALE APPLICANTS</div>
        <div class="stat-value text-info" id="maleApplicants">--</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">FEMALE APPLICANTS</div>
        <div class="stat-value text-success" id="femaleApplicants">--</div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-5">
    <div class="col-md-4 col-6">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">URGENT BESO REQUESTS</div>
        <div class="stat-value text-danger" id="urgentRequests">--</div>
      </div>
    </div>
    <div class="col-md-4 col-12">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">PENDING BESO APPOINTMENTS</div>
        <div class="stat-value text-warning" id="pendingAppointments">--</div>
      </div>
    </div>
    <div class="col-md-4 col-6">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">SCHEDULE BESO REQUESTS</div>
        <div class="stat-value text-info" id="scheduleRequests">--</div>
      </div>
    </div>
  </div>

  <!-- Charts + Recent Activity -->
  <div class="row g-4">
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold text-center mb-3">Age Distribution (BESO)</h5>
        <canvas id="ageChart"></canvas>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold text-center mb-3">Gender Distribution (BESO)</h5>
        <canvas id="genderChart"></canvas>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold text-center mb-3">Educational Attainment (BESO)</h5>
        <canvas id="educationChart"></canvas>
      </div>
    </div>

    <!-- NEW: Recent Activity card (same width as charts) -->
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold mb-3 text-center">Recent Activity (BESO user)</h5>
        <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width: 180px;">Date</th>
                <th>Module</th>
                <th>Action</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody id="recentBesoActivity">
              <tr><td colspan="4" class="text-muted text-center py-3">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div><!-- /row -->
</div>

<script>
const API = 'dashboard/dashboard_beso.php';

let charts = { age: null, gender: null, education: null };
let currentFetch; // AbortController

/* ------------ utils ------------ */
function destroyCharts() {
  Object.values(charts).forEach(c => c && c.destroy && c.destroy());
  charts = { age: null, gender: null, education: null };
}

function randomColorArray(n) {
  const arr = [];
  for (let i = 0; i < n; i++) {
    const hue = Math.round((360 / Math.max(1, n)) * i);
    arr.push(`hsl(${hue} 70% 55% / 0.85)`);
  }
  return arr;
}

// Defensive fetch -> won’t crash on HTML error pages
async function fetchJSON(url, signal) {
  const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
  const text = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0,300)}`);
  try { return JSON.parse(text); }
  catch { console.error('Bad JSON payload:\n', text); throw new Error('Response was not valid JSON.'); }
}

/* ------------ filters (NO URL SYNC) ------------ */
function buildQueryFromFilters() {
  const p = new URLSearchParams();

  const gender    = document.getElementById('genderFilter')?.value.trim();
  const status    = document.getElementById('statusFilter')?.value.trim();
  const minAge    = document.getElementById('minAge')?.value.trim();
  const maxAge    = document.getElementById('maxAge')?.value.trim();
  const urgentOnly= document.getElementById('urgentOnly')?.checked;
  const education = document.getElementById('educationFilter')?.value.trim(); // single-select

  if (gender)    p.set('gender', gender);
  if (status)    p.set('status', status);
  if (minAge)    p.set('min_age', minAge);
  if (maxAge)    p.set('max_age', maxAge);
  if (urgentOnly)p.set('urgent_only', '1');
  if (education) p.set('education', education);

  return p.toString();
}

// populate single-select from educationData keys; keep current selection if still valid
function ensureEduOptions(data) {
  const sel = document.getElementById('educationFilter');
  if (!sel) return;

  const prev = sel.value;
  const keys = Object.keys(data.educationData || {}).sort();

  const frag = document.createDocumentFragment();
  const allOpt = document.createElement('option');
  allOpt.value = '';
  allOpt.textContent = 'All';
  frag.appendChild(allOpt);
  keys.forEach(k => {
    const opt = document.createElement('option');
    opt.value = k;
    opt.textContent = k;
    frag.appendChild(opt);
  });

  sel.innerHTML = '';
  sel.appendChild(frag);
  if (prev && keys.includes(prev)) sel.value = prev; // restore if possible
}

/* ------------ charts ------------ */
const noDataPlugin = {
  id: 'noData',
  afterDraw(chart) {
    const ds = chart.data.datasets?.[0];
    const vals = Array.isArray(ds?.data) ? ds.data : [];
    const sum = vals.reduce((a, v) => a + (Number(v) || 0), 0);
    if (sum > 0) return;
    const { ctx, chartArea } = chart;
    if (!chartArea) return;
    ctx.save();
    ctx.font = '14px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    ctx.fillStyle = '#6c757d';
    ctx.textAlign = 'center';
    ctx.fillText('No data', (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
    ctx.restore();
  }
};

/* ------------ recent activity render ------------ */
function renderRecentBeso(list) {
  const tbody = document.getElementById('recentBesoActivity');
  if (!tbody) return;

  const esc = (s) => String(s ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');

  if (!list || !list.length) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3">No recent activity.</td></tr>`;
    return;
  }

  tbody.innerHTML = list.map(r => `
    <tr>
      <td>${esc(r.date_human || r.date)}</td>
      <td><span class="badge bg-secondary">${esc((r.module || '').toUpperCase())}</span></td>
      <td><span class="badge bg-info text-dark">${esc((r.action || '').toUpperCase())}</span></td>
      <td class="fw-semibold">${esc(r.action_by)}</td>
    </tr>
  `).join('');
}

function renderAll(data) {
  document.getElementById('totalBesoApplications').textContent = data.total ?? 0;
  document.getElementById('maleApplicants').textContent       = data.males ?? 0;
  document.getElementById('femaleApplicants').textContent     = data.females ?? 0;
  document.getElementById('urgentRequests').textContent       = data.urgent ?? 0;
  document.getElementById('pendingAppointments').textContent  = data.pending ?? 0;
  document.getElementById('scheduleRequests').textContent     = data.scheduled ?? 0;

  destroyCharts();

  charts.age = new Chart(document.getElementById('ageChart'), {
    type: 'bar',
    data: {
      labels: ['0-18', '19-35', '36-50', '51-65', '65+'],
      datasets: [{
        label: 'Applicants',
        data: (data.ageData || []).map(Number),
        backgroundColor: 'rgba(52, 152, 219, 0.5)',
        borderColor: 'rgba(52, 152, 219, 1)',
        borderWidth: 1
      }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } },
    plugins: [noDataPlugin]
  });

  const gLabels = Object.keys(data.genderData || {});
  const gValues = Object.values(data.genderData || {}).map(Number);
  charts.gender = new Chart(document.getElementById('genderChart'), {
    type: 'pie',
    data: { labels: gLabels, datasets: [{ data: gValues, backgroundColor: gLabels.length <= 2 ? ['#3498db','#2ecc71'] : randomColorArray(gLabels.length) }] },
    options: { responsive: true },
    plugins: [noDataPlugin]
  });

  const eLabels = Object.keys(data.educationData || {});
  const eValues = Object.values(data.educationData || {}).map(Number);
  charts.education = new Chart(document.getElementById('educationChart'), {
    type: 'pie',
    data: { labels: eLabels, datasets: [{ data: eValues, backgroundColor: eLabels.length ? randomColorArray(eLabels.length) : ['#bdc3c7'] }] },
    options: { responsive: true },
    plugins: [noDataPlugin]
  });

  // NEW: render recent activity
  renderRecentBeso(data.recentActivities || []);
}

/* ------------ loading & fetch ------------ */
function setLoading(state) {
  document.getElementById('applyBtn')?.toggleAttribute('disabled', state);
  document.getElementById('resetBtn')?.toggleAttribute('disabled', state);
}

async function loadDashboard(qs = '') {
  if (currentFetch) currentFetch.abort();
  currentFetch = new AbortController();
  const url = qs ? `${API}?${qs}` : API;
  setLoading(true);
  try { return await fetchJSON(url, currentFetch.signal); }
  finally { setLoading(false); }
}

/* ------------ actions (no history.replaceState) ------------ */
async function applyFilters() {
  try {
    const q = buildQueryFromFilters();
    const data = await loadDashboard(q);
    ensureEduOptions(data);
    renderAll(data);
  } catch (err) {
    console.error(err);
    alert('Error applying filters. See console for details.');
  }
}

async function resetFilters() {
  const form = document.getElementById('filters');
  form?.reset();
  try {
    const data = await loadDashboard('');
    ensureEduOptions(data);
    renderAll(data);
  } catch (err) {
    console.error(err);
    alert('Error resetting filters.');
  }
}

/* ------------ bootstrap ------------ */
async function bootstrap() {
  try {
    const data = await loadDashboard('');
    ensureEduOptions(data);
    renderAll(data);
  } catch (err) {
    console.error(err);
    alert('Error loading dashboard.');
  }
}

document.getElementById('applyBtn')?.addEventListener('click', applyFilters);
document.getElementById('resetBtn')?.addEventListener('click', resetFilters);
document.getElementById('filters')?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); applyFilters(); }
});

bootstrap();
</script>
</body>
</html>
