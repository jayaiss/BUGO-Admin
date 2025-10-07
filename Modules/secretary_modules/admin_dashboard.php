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
    <title>Residents Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        h1 {
            font-weight: 700;
            color: #2c3e50;
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .card:hover {
            transform: translateY(-4px);
        }
        .stat-title {
            font-size: 0.9rem;
            color: #7f8c8d;
            letter-spacing: 0.05em;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            canvas {
                max-height: 300px !important;
            }
        }
        #recentPBActivity tr td {
            white-space: nowrap;
        }
    </style>
</head>
<body>
<div class="container my-5">
    <h1 class="text-center mb-5"><?php echo htmlspecialchars($roleName ?? ''); ?> Dashboard</h1>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-md-4">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">TOTAL RESIDENTS</div>
                <div class="stat-value text-primary" id="totalResidents">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">MALES</div>
                <div class="stat-value text-info" id="totalMales">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">FEMALES</div>
                <div class="stat-value text-success" id="totalFemales">--</div>
            </div>
        </div>
    </div>

    <!-- Barangay Highlights -->
    <div class="row g-4 mb-5">
        <div class="col-md-4 col-6">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">URGENT REQUESTS</div>
                <div class="stat-value text-danger" id="urgentRequests">--</div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">UPCOMING EVENTS</div>
                <div class="stat-value text-primary" id="upcomingEvents">--</div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="card text-center p-4">
                <div class="stat-title mb-1">PENDING APPOINTMENTS</div>
                <div class="stat-value text-warning" id="pendingAppointments">--</div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
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
    </div>

    <!-- Recent Activity -->
    <div class="row g-4 mt-4">
        <div class="col-md-12">
            <div class="card p-4">
                <h5 class="fw-bold mb-3">Recent Activity</h5>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:180px;">Date</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Action By (Employee)</th>
                            </tr>
                        </thead>
                        <tbody id="recentPBActivity">
                            <tr><td colspan="4" class="text-muted text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function renderRecentPB(list){
  const tbody = document.getElementById('recentPBActivity');
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

fetch('dashboard/admin_dashboard.php')
    .then(response => response.json())
    .then(data => {
        const ageData = (data.ageData || []).map(Number);
        const genderData = data.genderData || {};

        const totalResidents = ageData.reduce((a, b) => a + b, 0);
        document.getElementById('totalResidents').textContent = totalResidents;
        document.getElementById('totalMales').textContent   = genderData.Male ?? 0;
        document.getElementById('totalFemales').textContent = genderData.Female ?? 0;

        document.getElementById('urgentRequests').textContent      = data.urgentRequests ?? 0;
        document.getElementById('upcomingEvents').textContent      = data.upcomingEventsCount ?? 0;
        document.getElementById('pendingAppointments').textContent = data.pendingAppointments ?? 0;

        new Chart(document.getElementById('ageChart'), {
            type: 'bar',
            data: {
                labels: ['0-18', '19-35', '36-50', '51-65', '65+'],
                datasets: [{
                    label: 'Residents',
                    data: ageData,
                    backgroundColor: 'rgba(52, 152, 219, 0.5)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, plugins: { legend: { display: true } } }
        });

        new Chart(document.getElementById('genderChart'), {
            type: 'pie',
            data: {
                labels: Object.keys(genderData),
                datasets: [{
                    data: Object.values(genderData).map(Number),
                    backgroundColor: ['#3498db', '#2ecc71']
                }]
            },
            options: { responsive: true }
        });

        // Render Recent Activity
        renderRecentPB(data.recentActivities);
    })
    .catch(error => console.error('Error fetching data:', error));
</script>
</body>
</html>
