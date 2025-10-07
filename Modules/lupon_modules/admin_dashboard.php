<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
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
    <title>Lupong Tagapamayapa Dashboard</title>
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
        @media (max-width: 768px) { canvas { max-height: 300px !important; } }
        /* Recent activity table tweaks */
        #recentLuponActivity td { white-space: nowrap; }
    </style>
</head>
<body>
<div class="container my-5">
  <h1 class="text-center mb-5">Lupong Tagapamayapa — Cases Dashboard</h1>

  <div class="card p-3 mb-4">
  <form id="caseFilters" class="row g-3 align-items-end">
    <div class="col-12 col-md-3">
      <label class="form-label">Start date (filed)</label>
      <input type="date" class="form-control" name="start_date">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">End date (filed)</label>
      <input type="date" class="form-control" name="end_date">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Offense</label>
      <select class="form-select" name="offense" id="offenseSelect">
        <option value="all">All</option>
        <!-- options populated from API -->
      </select>
    </div>
    <div class="col-12 col-md-2">
    <label class="form-label">Status</label>
    <select class="form-select" name="status">
      <option value="all">All</option>
      <option value="Conciliated">Conciliated</option>
      <option value="Mediated">Mediated</option>
      <option value="Dismissed">Dismissed</option>
      <option value="Withdrawn">Withdrawn</option>
      <option value="Ongoing">Ongoing</option>
      <option value="Arbitration">Arbitration</option>
    </select>
    </div>
    <div class="col-12 col-md-1 d-grid">
      <button type="button" id="applyFilters" class="btn btn-primary">Apply</button>
    </div>
    <div class="col-12 col-md-1 d-grid">
      <button type="button" id="resetFilters" class="btn btn-outline-secondary">Reset</button>
    </div>
  </form>
</div>

  <!-- Top stats -->
  <div class="row g-4 mb-4">
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">TOTAL CASES</div>
        <div class="stat-value text-primary" id="totalCases">--</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">ONGOING</div>
        <div class="stat-value text-warning" id="ongoingCases">--</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">RESOLVED / CLOSED</div>
        <div class="stat-value text-success" id="resolvedCases">--</div>
      </div>
    </div>
  </div>

  <!-- Secondary stats -->
  <div class="row g-4 mb-5">
    <div class="col-md-6 col-12">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">FILED TODAY</div>
        <div class="stat-value text-info" id="todayFiled">--</div>
      </div>
    </div>
    <div class="col-md-6 col-12">
      <div class="card text-center p-4">
        <div class="stat-title mb-1">UPCOMING HEARINGS (30 DAYS)</div>
        <div class="stat-value text-danger" id="upcomingHearings">--</div>
      </div>
    </div>
  </div>

  <!-- Charts + Recent Activity -->
  <div class="row g-4">
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold text-center mb-3">Cases by Offense Type</h5>
        <canvas id="offenseChart"></canvas>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold text-center mb-3">Cases Filed — Last 6 Months</h5>
        <canvas id="trendChart"></canvas>
      </div>
    </div>

    <!-- NEW: Recent Activity card (same width as charts) -->
    <div class="col-md-6">
      <div class="card p-4">
        <h5 class="fw-bold mb-3 text-center">Recent Activity (Lupon)</h5>
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
            <tbody id="recentLuponActivity">
              <tr><td colspan="4" class="text-muted text-center py-3">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- /Recent Activity -->
  </div>
</div>

<script>
  const labelYM = ym => {
    const [y, m] = (ym || '').split('-').map(Number);
    return new Date(y || 2000, (m || 1) - 1, 1)
      .toLocaleString(undefined, { month: 'short', year: 'numeric' });
  };

  let offenseChart = null, trendChart = null, offenseOptionsLoaded = false;
  const destroy = c => c && c.destroy && c.destroy();

  function getQueryFromForm(form) {
    if (!form) return '';
    const p = new URLSearchParams();
    new FormData(form).forEach((v, k) => { if (v && v !== 'all') p.set(k, v); });
    return p.toString();
  }

  function populateOffenseOptions(options) {
    if (offenseOptionsLoaded) return;
    const sel = document.getElementById('offenseSelect');
    if (!sel || !Array.isArray(options)) return;
    options.forEach(opt => {
      const o = document.createElement('option');
      o.value = opt;
      o.textContent = opt;
      sel.appendChild(o);
    });
    offenseOptionsLoaded = true;
  }

  function renderRecentLupon(list){
    const tbody = document.getElementById('recentLuponActivity');
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

  async function fetchJSON(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const ct = res.headers.get('content-type') || '';
    const text = await res.text();
    if (!ct.includes('application/json')) {
      throw new Error(`Expected JSON (${res.status}), got ${ct}. Snippet: ${text.slice(0,200)}`);
    }
    const data = JSON.parse(text);
    if (!res.ok) {
      const msg = data.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return data;
  }

  function render(data) {
    (document.getElementById('totalCases')       || {}).textContent = data.totalCases ?? 0;
    (document.getElementById('ongoingCases')     || {}).textContent = data.ongoingCases ?? 0;
    (document.getElementById('resolvedCases')    || {}).textContent = data.resolvedCases ?? 0;
    (document.getElementById('todayFiled')       || {}).textContent = data.todayFiled ?? 0;
    (document.getElementById('upcomingHearings') || {}).textContent = data.upcomingHearings ?? 0;

    populateOffenseOptions(data.offenseOptions || []);

    const offenseLabels = Object.keys(data.offenseByType || {});
    const offenseValues = Object.values(data.offenseByType || {});
    destroy(offenseChart);
    offenseChart = new Chart(document.getElementById('offenseChart'), {
      type: 'bar',
      data: {
        labels: offenseLabels,
        datasets: [{
          label: 'Cases',
          data: offenseValues,
          backgroundColor: 'rgba(52, 152, 219, 0.5)',
          borderColor: 'rgba(52, 152, 219, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });

    const trendLabels = Object.keys(data.casesByMonth || {}).map(labelYM);
    const trendValues = Object.values(data.casesByMonth || {});
    destroy(trendChart);
    trendChart = new Chart(document.getElementById('trendChart'), {
      type: 'line',
      data: {
        labels: trendLabels,
        datasets: [{
          label: 'Filed',
          data: trendValues,
          borderColor: 'rgba(46, 204, 113, 1)',
          backgroundColor: 'rgba(46, 204, 113, 0.25)',
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });

    // NEW: render recent activity
    renderRecentLupon(data.recentActivities || []);
  }

  function loadCases() {
    const form = document.getElementById('caseFilters'); // optional
    const qs   = getQueryFromForm(form);
    const url  = 'dashboard/dashboard_lupon.php' + (qs ? ('?' + qs) : '');
    fetchJSON(url).then(render).catch(err => {
      console.error('Cases dashboard error:', err);
      const el = document.getElementById('totalCases');
      if (el) el.textContent = '—';
    });
  }

  const applyBtn = document.getElementById('applyFilters');
  if (applyBtn) applyBtn.addEventListener('click', loadCases);

  const resetBtn = document.getElementById('resetFilters');
  if (resetBtn) resetBtn.addEventListener('click', () => {
    const form = document.getElementById('caseFilters');
    if (form) form.reset();
    loadCases();
  });

  // initial load
  loadCases();
</script>
</body>
</html>
