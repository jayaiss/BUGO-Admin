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
    <title>Residents Dashboard</title>
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
        #recentRevenueActivity td { white-space: nowrap; }
    </style>
</head>
<body>
<div class="container my-5">
    <h1 class="text-center mb-5"><?php echo htmlspecialchars($roleName); ?> Dashboard</h1>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-md-4">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">TOTAL APPLICATIONS</div>
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

    <!-- Highlights -->
    <div class="row g-4 mb-5">
        <div class="col-md-4 col-6">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">TODAY'S URGENT REQUESTS</div>
                <div class="stat-value text-danger" id="urgentRequests">--</div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">TODAY'S PENDING APPOINTMENTS</div>
                <div class="stat-value text-warning" id="pendingAppointments">--</div>
            </div>
        </div>
    </div>

    <!-- Charts + Recent Activity -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold text-center mb-3">Age Distribution</h5>
                <canvas id="ageChart"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold text-center mb-3">Gender Distribution</h5>
                <canvas id="genderChart"></canvas>
            </div>
        </div>

        <!-- NEW: Recent Activity card (same width as charts) -->
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold mb-3 text-center">Recent Activity</h5>
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
                        <tbody id="recentRevenueActivity">
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
function renderRecentRevenue(list){
  const tbody = document.getElementById('recentRevenueActivity');
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

fetch('dashboard/dashboard_revenue.php')
  .then(response => response.json())
  .then(data => {
    // Populate stat cards
    document.getElementById('totalBesoApplications').textContent = data.total ?? 0;
    document.getElementById('maleApplicants').textContent = data.males ?? 0;
    document.getElementById('femaleApplicants').textContent = data.females ?? 0;
    document.getElementById('urgentRequests').textContent = data.urgent ?? 0;
    document.getElementById('pendingAppointments').textContent = data.pending ?? 0;

    // Charts
    new Chart(document.getElementById('ageChart'), {
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
      options: { responsive: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('genderChart'), {
      type: 'pie',
      data: {
        labels: Object.keys(data.genderData || {}),
        datasets: [{
          data: Object.values(data.genderData || {}).map(Number),
          backgroundColor: ['#3498db', '#2ecc71']
        }]
      },
      options: { responsive: true }
    });

    // NEW: Recent activity render
    renderRecentRevenue(data.recentActivities || []);
  })
  .catch(error => console.error('Error fetching dashboard data:', error));
</script>

</body>
</html>
