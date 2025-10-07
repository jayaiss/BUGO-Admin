<?php 
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include '../../class/session_timeout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reports</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="icon" type="image/png" href="assets/logo/logo.png">

  <style>
  body {
    background-color: #f8f9fa;
    font-family: 'Segoe UI', sans-serif;
  }
  .card {
    border-radius: 1rem;
    padding: 2rem;
    background-color: #ffffff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  }
  .form-label { font-weight: 600; }
  .btn-icon { display: inline-flex; align-items: center; gap: 5px; }

  .table-responsive {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 70vh;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
  }

  #reportResult table {
    width: 100%;
    max-width: 100%;
    margin-bottom: 1rem;
    font-size: 0.875rem;
    border-collapse: collapse;
    box-shadow: 0 0 5px rgba(0,0,0,0.05);
    border-radius: 0.4rem;
    overflow: hidden;
  }
  #reportResult thead th {
    background-color: #0d6efd;
    color: white;
    font-weight: 600;
    font-size: 0.75rem;
    text-align: center;
    padding: 0.6rem 0.4rem;
    border: 1px solid #e0e0e0;
    white-space: normal;
    word-break: break-word;
    vertical-align: middle;
  }
  #reportResult tbody td {
    padding: 0.5rem 0.4rem;
    text-align: center;
    vertical-align: middle;
    border: 1px solid #dee2e6;
  }
  #reportResult tbody tr:nth-child(even) { background-color: #f8f9fa; }
  #reportResult tbody tr:hover {
    background-color: #e2e6ea;
    transition: background-color 0.3s ease;
  }
  #reportResult tfoot td {
    font-weight: bold;
    background-color: #f1f3f5;
  }
  @media print {
    body * { visibility: hidden; }
    #reportResult, #reportResult * { visibility: visible; }
    #reportResult {
      position: absolute;
      left: 0;
      top: 0;
      width: 100%;
    }
    button, select, input { display: none !important; }
  }
  </style>
</head>
<body class="p-4">
<div class="container my-5">
  <div class="card shadow-lg">
    <h2 class="mb-4 text-primary"><i class="bi bi-bar-chart-line"></i> Generate Reports</h2>

    <div class="mb-3">
      <label class="form-label">Select Report Type:</label>
      <select id="reportType" class="form-select" onchange="loadFiltersAndReport()">
        <option value="">-- Select Report --</option>
        <option value="cases">Cases</option>
      </select>
    </div>

    <!-- Case Filters -->
    <div id="caseFilters" class="mb-3" style="display: none;">
      <div class="row">
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Month:</label>
          <select id="caseMonth" class="form-select">
            <option value="">All Months</option>
            <option value="01">January</option>
            <option value="02">February</option>
            <option value="03">March</option>
            <option value="04">April</option>
            <option value="05">May</option>
            <option value="06">June</option>
            <option value="07">July</option>
            <option value="08">August</option>
            <option value="09">September</option>
            <option value="10">October</option>
            <option value="11">November</option>
            <option value="12">December</option>
          </select>
        </div>
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Year:</label>
          <select id="caseYear" class="form-select">
            <option value="">All Years</option>
            <?php
              $currentYear = date('Y');
              for ($y = $currentYear; $y >= 2020; $y--) {
                echo "<option value='$y'>$y</option>";
              }
            ?>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mb-3">
      <button class="btn btn-success btn-icon" onclick="loadReport()">
        <i class="bi bi-arrow-repeat"></i> Load Report
      </button>
      <button class="btn btn-dark btn-icon" onclick="printReport()">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>

    <div class="table-responsive" id="reportResult">
      <!-- Report table will load here -->
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function loadFiltersAndReport() {
  const type = document.getElementById("reportType").value;

  // Hide non-existing filters safely
  const ced = document.getElementById("cedulaFilters");
  const res = document.getElementById("residentsFilters");
  if (ced) ced.style.display = "none";
  if (res) res.style.display = "none";

  const caseFilters = document.getElementById("caseFilters");
  if (type === "cases") {
    caseFilters.style.display = "block";
    loadReport(); // auto-load when selecting cases
  } else {
    caseFilters.style.display = "none";
    document.getElementById("reportResult").innerHTML = "";
  }

  if (type === "cedula" || type === "residents") {
    loadReport();
  }
}

function loadReport(page = 1) {
  const type = document.getElementById("reportType").value;
  let url = "";

  if (type === "cases") {
    const month = document.getElementById("caseMonth").value;
    const year  = document.getElementById("caseYear").value;
    url = `load_report/load_cases_report.php?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}&page=${page}`;
  }

  if (!url) return;
  fetch(url)
    .then(res => res.text())
    .then(data => {
      document.getElementById("reportResult").innerHTML = data;
      attachPaginationHandlers();
    })
    .catch(error => {
      console.error("Error loading report:", error);
      document.getElementById("reportResult").innerHTML = "<div class='text-danger'>Failed to load report.</div>";
    });
}

function attachPaginationHandlers() {
  document.querySelectorAll(".pagination-link").forEach(link => {
    link.addEventListener("click", e => {
      e.preventDefault();
      const page = parseInt(link.getAttribute("data-page"), 10) || 1;
      loadReport(page);
    });
  });
}

// Auto-reload when month/year changes
document.addEventListener("DOMContentLoaded", () => {
  const monthSel = document.getElementById("caseMonth");
  const yearSel  = document.getElementById("caseYear");
  [monthSel, yearSel].forEach(el => {
    if (el) el.addEventListener("change", () => loadReport());
  });
});

function printReport() {
  const type = document.getElementById("reportType").value;

  // Log print action
  fetch('logs/log_report_print.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `report_type=${encodeURIComponent(type)}`
  });

  if (type === "cases") {
    const month = document.getElementById("caseMonth").value;
    const year  = document.getElementById("caseYear").value;
    const win = window.open(
      `load_report/load_cases_report.php?print=1&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`,
      '_blank'
    );
    if (win) {
      win.focus();
      win.onload = () => setTimeout(() => win.print(), 500);
    }
  } else {
    window.print();
  }
}
</script>
</body>
</html>
