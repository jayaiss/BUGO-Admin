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
    <title>Event Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        h1 { font-weight: 700; color: #2c3e50; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 0 15px rgba(0,0,0,0.1); transition: transform .2s ease; }
        .card:hover { transform: translateY(-4px); }
        .stat-title { font-size: .9rem; color: #7f8c8d; letter-spacing: .05em; }
        .stat-value { font-size: 1.8rem; font-weight: 600; }
        @media (max-width: 768px) { canvas { max-height: 300px !important; } }
        /* Recent activity */
        #recentMMActivity td { white-space: nowrap; }
    </style>
</head>
<body>
<div class="container my-5">
    <h1 class="text-center mb-5"><?php echo htmlspecialchars($roleName); ?> Dashboard</h1>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-6 col-12">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">UPCOMING EVENTS (This Month)</div>
                <div class="stat-value text-primary" id="upcomingEventsCount">--</div>
            </div>
        </div>
        <div class="col-md-6 col-12">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">TOTAL EVENTS (Overall)</div>
                <div class="stat-value text-success" id="totalEventsCount">--</div>
            </div>
        </div>
    </div>

    <!-- Charts + Recent Activity -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold text-center mb-3">Event Name Count (This Month)</h5>
                <canvas id="eventNameChart"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold text-center mb-3">Event Locations (This Month)</h5>
                <canvas id="eventLocationChart"></canvas>
            </div>
        </div>

        <!-- NEW: Recent Activity card -->
        <div class="col-md-6">
            <div class="card p-4">
                <h5 class="fw-bold mb-3 text-center">Recent Activity (Multimedia)</h5>
                <div class="table-responsive" style="max-height:350px; overflow-y:auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:180px;">Date</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody id="recentMMActivity">
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
function renderRecentMM(list){
  const tbody = document.getElementById('recentMMActivity');
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

fetch('dashboard/dashboard_multimedia.php')
  .then(res => res.json())
  .then(data => {
    const eventNameData     = data.eventNameData || {};
    const locationData      = data.locationData || {};
    const upcomingCount     = data.upcomingEventsCount ?? 0;
    const totalEventsCount  = data.totalEventsCount ?? 0;

    // Stat cards
    document.getElementById('upcomingEventsCount').textContent = upcomingCount;
    document.getElementById('totalEventsCount').textContent    = totalEventsCount;

    // Charts
    new Chart(document.getElementById('eventNameChart'), {
      type: 'pie',
      data: {
        labels: Object.keys(eventNameData),
        datasets: [{
          data: Object.values(eventNameData),
          backgroundColor: ['#3498db','#2ecc71','#f1c40f','#e74c3c','#9b59b6','#1abc9c','#e67e22','#34495e']
        }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('eventLocationChart'), {
      type: 'bar',
      data: {
        labels: Object.keys(locationData),
        datasets: [{
          label: 'Events',
          data: Object.values(locationData),
          backgroundColor: 'rgba(46, 204, 113, 0.5)',
          borderColor: 'rgba(46, 204, 113, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });

    // NEW: render recent activity
    renderRecentMM(data.recentActivities || []);
  })
  .catch(error => console.error('Error fetching data:', error));
</script>

</body>
</html>
