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

/* ---- preload dropdown options for Events (by EVENT NAME) ---- */
$eventTitles = [];
$eventLocations = [];
if ($mysqli) {
  // Titles: prefer event_name.event_name; fallback to raw events.event_title text
  $sqlTitles = "
    SELECT DISTINCT COALESCE(en.event_name, CAST(e.event_title AS CHAR)) AS title
    FROM events e
    LEFT JOIN event_name en ON en.id = e.event_title
    WHERE COALESCE(en.event_name, CAST(e.event_title AS CHAR)) IS NOT NULL
      AND COALESCE(en.event_name, CAST(e.event_title AS CHAR)) <> ''
    ORDER BY title
  ";
  if ($rs = $mysqli->query($sqlTitles)) {
    while ($r = $rs->fetch_assoc()) { $eventTitles[] = $r['title']; }
    $rs->free();
  }

  // Locations
  if ($rs = $mysqli->query("SELECT DISTINCT event_location FROM events WHERE event_location IS NOT NULL AND event_location<>'' ORDER BY event_location")) {
    while ($r = $rs->fetch_assoc()) { $eventLocations[] = $r['event_location']; }
    $rs->free();
  }
}
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

  <style>
    body { background-color:#f8f9fa; font-family:'Segoe UI', sans-serif; }
    .card { border-radius:1rem; padding:2rem; background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.05); }
    .form-label { font-weight:600; }
    .btn-icon { display:inline-flex; align-items:center; gap:5px; }
    .table-responsive { overflow:auto; max-height:70vh; border:1px solid #dee2e6; border-radius:.5rem; }
    #reportResult table { width:100%; font-size:.875rem; border-collapse:collapse; box-shadow:0 0 5px rgba(0,0,0,.05); border-radius:.4rem; overflow:hidden; }
    #reportResult thead th { background:#0d6efd; color:#fff; font-weight:600; font-size:.75rem; text-align:center; padding:.6rem .4rem; border:1px solid #e0e0e0; }
    #reportResult tbody td { padding:.5rem .4rem; text-align:center; vertical-align:middle; border:1px solid #dee2e6; }
    #reportResult tbody tr:nth-child(even){ background:#f8f9fa; }
    #reportResult tbody tr:hover{ background:#e2e6ea; transition:background-color .3s ease; }
    #reportResult tfoot td { font-weight:bold; background:#f1f3f5; }
    @page { size:A4; margin:12mm; }
    @media print {
      html,body{ background:#fff!important; }
      button,select,input{ display:none!important; }
      #reportResult{ box-shadow:none!important; border:none!important; max-height:none!important; }
      #reportResult thead{ display:table-header-group; }
      #reportResult tfoot{ display:table-footer-group; }
      #reportResult th,#reportResult td{ padding:6px 8px!important; font-size:12px; }
      *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
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
        <option value="residents">Residents</option>
        <option value="events">Events</option>
        <option value="schedules">Schedules</option>
        <option value="feedbacks">Feedbacks</option>
        <option value="cases">Cases</option>
        <option value="beso">BESO</option>
      </select>
    </div>

    <!-- Cedula Filter -->
    <div id="cedulaFilters" class="mb-3" style="display:none;">
      <label class="form-label">Address:</label>
      <input type="text" id="cedulaAddress" class="form-control" placeholder="Enter address (optional)">
    </div>

    <!-- Residents Filters -->
    <div id="residentsFilters" class="mb-3" style="display:none;">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Zone:</label>
          <select id="residentZone" class="form-select">
            <option value="">All</option>
            <option value="Zone 1">Zone 1</option>
            <option value="Zone 2">Zone 2</option>
            <option value="Zone 3">Zone 3</option>
            <option value="Zone 4">Zone 4</option>
            <option value="Zone 5">Zone 5</option>
            <option value="Zone 6">Zone 6</option>
            <option value="Zone 7">Zone 7</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Gender:</label>
          <select id="residentGender" class="form-select">
            <option value="">All</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Age Range:</label>
          <select id="residentAgeRange" class="form-select" onchange="toggleCustomAge()">
            <option value="">All ages</option>
            <option value="0-10">0–10</option>
            <option value="11-19">11–19</option>
            <option value="20-29">20–29</option>
            <option value="30-39">30–39</option>
            <option value="40-49">40–49</option>
            <option value="50-59">50–59</option>
            <option value="60-200">60+</option>
            <option value="custom">Custom…</option>
          </select>
        </div>
      </div>

      <!-- Custom age min/max -->
      <div id="residentAgeCustom" class="row g-3 mt-2" style="display:none;">
        <div class="col-md-6">
          <label class="form-label">Min age:</label>
          <select id="residentAgeMin" class="form-select">
            <option value="">—</option>
            <option>0</option><option>5</option><option>10</option><option>15</option>
            <option>20</option><option>25</option><option>30</option><option>35</option>
            <option>40</option><option>45</option><option>50</option><option>55</option>
            <option>60</option><option>65</option><option>70</option><option>75</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Max age:</label>
          <select id="residentAgeMax" class="form-select">
            <option value="">—</option>
            <option>10</option><option>15</option><option>20</option><option>25</option>
            <option>30</option><option>35</option><option>40</option><option>45</option>
            <option>50</option><option>55</option><option>60</option><option>65</option>
            <option>70</option><option>75</option><option>80</option><option>90</option>
            <option>100</option><option>120</option><option>150</option><option>200</option>
          </select>
        </div>
      </div>

      <!-- Actions -->
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-outline-primary btn-icon" onclick="loadReport(1)">
          <i class="bi bi-funnel"></i> Apply Filters
        </button>
        <button class="btn btn-outline-secondary btn-icon" onclick="resetResidentFilters()">
          <i class="bi bi-arrow-counterclockwise"></i> Reset
        </button>
      </div>
    </div>

    <!-- Events Filters (Dropdowns) -->
    <div id="eventsFilters" class="mb-3" style="display:none;">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Title:</label>
          <select id="eventTitle" class="form-select">
            <option value="">All</option>
            <?php foreach ($eventTitles as $t): ?>
              <option value="<?=htmlspecialchars($t, ENT_QUOTES)?>"><?=htmlspecialchars($t)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Location:</label>
          <select id="eventLocation" class="form-select">
            <option value="">All</option>
            <?php foreach ($eventLocations as $l): ?>
              <option value="<?=htmlspecialchars($l, ENT_QUOTES)?>"><?=htmlspecialchars($l)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Month:</label>
          <select id="eventMonth" class="form-select">
            <option value="">All</option>
            <option value="01">January</option><option value="02">February</option>
            <option value="03">March</option><option value="04">April</option>
            <option value="05">May</option><option value="06">June</option>
            <option value="07">July</option><option value="08">August</option>
            <option value="09">September</option><option value="10">October</option>
            <option value="11">November</option><option value="12">December</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Year:</label>
          <select id="eventYear" class="form-select">
            <option value="">All</option>
            <?php $cy=date('Y'); for($y=$cy;$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
          </select>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-outline-primary btn-icon" onclick="loadReport(1)">
          <i class="bi bi-funnel"></i> Apply Filters
        </button>
        <button class="btn btn-outline-secondary btn-icon" onclick="resetEventsFilters()">
          <i class="bi bi-arrow-counterclockwise"></i> Reset
        </button>
      </div>
    </div>

    <!-- Case Filters -->
    <div id="caseFilters" class="mb-3" style="display:none;">
      <div class="row">
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Month:</label>
          <select id="caseMonth" class="form-select">
            <option value="">All Months</option>
            <option value="01">January</option><option value="02">February</option>
            <option value="03">March</option><option value="04">April</option>
            <option value="05">May</option><option value="06">June</option>
            <option value="07">July</option><option value="08">August</option>
            <option value="09">September</option><option value="10">October</option>
            <option value="11">November</option><option value="12">December</option>
          </select>
        </div>
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Year:</label>
          <select id="caseYear" class="form-select">
            <option value="">All Years</option>
            <?php $currentYear = date('Y'); for ($y=$currentYear; $y>=2020; $y--) echo "<option value='$y'>$y</option>"; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Feedback Filters -->
    <div id="feedbackFilters" class="mb-3" style="display:none;">
      <div class="row">
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Month:</label>
          <select id="feedbackMonth" class="form-select">
            <option value="">All Months</option>
            <option value="01">January</option><option value="02">February</option>
            <option value="03">March</option><option value="04">April</option>
            <option value="05">May</option><option value="06">June</option>
            <option value="07">July</option><option value="08">August</option>
            <option value="09">September</option><option value="10">October</option>
            <option value="11">November</option><option value="12">December</option>
          </select>
        </div>
        <div class="col-md-6 mb-2">
          <label class="form-label">Select Year:</label>
          <select id="feedbackYear" class="form-select">
            <option value="">All Years</option>
            <?php $currentYear = date('Y'); for ($y=$currentYear; $y>=2020; $y--) echo "<option value='$y'>$y</option>"; ?>
          </select>
        </div>
        <div class="mt-2 d-flex gap-2">
          <button class="btn btn-outline-primary btn-icon" onclick="loadReport()">
            <i class="bi bi-funnel"></i> Apply Filters
          </button>
          <button class="btn btn-outline-secondary btn-icon" onclick="resetFeedbackFilters()">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
          </button>
        </div>
      </div>
    </div>

    <!-- Schedules Certificate Filter -->
    <div id="certificateFilter" class="mb-3" style="display:none;">
      <label class="form-label">Certificate:</label>
      <select id="reportCertificate" class="form-select">
        <option value="">All Certificates</option>
        <option value="Barangay Clearance">Barangay Clearance</option>
        <option value="Barangay Indigency">Barangay Indigency</option>
        <option value="Barangay Residency">Barangay Residency</option>
        <option value="Cedula">Cedula</option>
      </select>
    </div>

    <!-- Year Filter for Schedules & BESO -->
    <div id="yearFilterBar" class="mb-3" style="display:none;">
      <label class="form-label me-2">Year:</label>
      <select id="reportYear" class="form-select" style="max-width:140px; display:inline-block;">
        <?php $cy=(int)date('Y'); for($y=$cy;$y>=2020;$y--) echo "<option value='$y' ".($y===$cy?'selected':'').">$y</option>"; ?>
      </select>
      <button type="button" class="btn btn-primary ms-2" onclick="loadReport()">Apply</button>
      <button type="button" class="btn btn-outline-secondary ms-1" onclick="document.getElementById('reportYear').value='<?=$cy?>'; loadReport();">Reset</button>
    </div>

    <!-- BESO: Extra Filters -->
    <div id="besoExtraFilters" class="mb-3" style="display:none;">
      <div class="d-flex flex-wrap align-items-end gap-3">
        <div>
          <label class="form-label">Education:</label>
          <input type="text" id="filterEdu" class="form-control" style="min-width:220px;" placeholder="e.g. College Graduate">
        </div>
        <div>
          <label class="form-label">Course:</label>
          <input type="text" id="filterCourse" class="form-control" style="min-width:220px;" placeholder="e.g. BSIT">
        </div>
        <div>
          <label class="form-label">Month:</label>
          <select id="reportMonth" class="form-select" style="min-width:160px;">
            <option value="">All Months</option>
            <option value="01">January</option><option value="02">February</option>
            <option value="03">March</option><option value="04">April</option>
            <option value="05">May</option><option value="06">June</option>
            <option value="07">July</option><option value="08">August</option>
            <option value="09">September</option><option value="10">October</option>
            <option value="11">November</option><option value="12">December</option>
          </select>
        </div>
        <div>
          <label class="form-label">Year:</label>
          <select id="reportYearInline" class="form-select" style="min-width:120px;"
                  onchange="document.getElementById('reportYear').value=this.value;">
            <?php $cy=(int)date('Y'); for($y=$cy;$y>=2020;$y--) echo "<option value='$y' ".($y===$cy?'selected':'').">$y</option>"; ?>
          </select>
        </div>
        <div class="mt-2">
          <button class="btn btn-outline-primary btn-icon" onclick="loadReport()">
            <i class="bi bi-funnel"></i> Apply Filters
          </button>
          <button class="btn btn-outline-secondary btn-icon" onclick="resetBesoFilters()">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
          </button>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mb-3">
      <button class="btn btn-success btn-icon" onclick="loadReport()"><i class="bi bi-arrow-repeat"></i> Load Report</button>
      <button class="btn btn-dark btn-icon" onclick="printReport()"><i class="bi bi-printer"></i> Print</button>
    </div>

    <div class="table-responsive" id="reportResult"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCustomAge() {
  const v = document.getElementById('residentAgeRange').value;
  document.getElementById('residentAgeCustom').style.display = (v === 'custom') ? 'flex' : 'none';
}

function resetResidentFilters() {
  document.getElementById('residentZone').value = '';
  document.getElementById('residentGender').value = '';
  document.getElementById('residentAgeRange').value = '';
  document.getElementById('residentAgeMin').value = '';
  document.getElementById('residentAgeMax').value = '';
  document.getElementById('residentAgeCustom').style.display = 'none';
  loadReport(1);
}

function resetEventsFilters(){
  document.getElementById('eventTitle').value='';
  document.getElementById('eventLocation').value='';
  document.getElementById('eventMonth').value='';
  document.getElementById('eventYear').value='';
  loadReport(1);
}

function loadFiltersAndReport() {
  const type = document.getElementById("reportType").value;

  // hide all
  ["cedulaFilters","residentsFilters","eventsFilters","caseFilters","feedbackFilters","certificateFilter","yearFilterBar","besoExtraFilters"]
    .forEach(id => { const el=document.getElementById(id); if(el) el.style.display="none"; });

  if (type === "residents") {
    document.getElementById("residentsFilters").style.display = "block";
  } else if (type === "cedula") {
    document.getElementById("cedulaFilters").style.display = "block";
  } else if (type === "cases") {
    document.getElementById("caseFilters").style.display = "block";
  } else if (type === "schedules") {
    document.getElementById("certificateFilter").style.display = "block";
    document.getElementById("yearFilterBar").style.display = "block";
  } else if (type === "feedbacks") {
    document.getElementById("feedbackFilters").style.display = "block";
  } else if (type === "beso") {
    document.getElementById("besoExtraFilters").style.display = "block";
    const y = document.getElementById('reportYear')?.value || '';
    const yi = document.getElementById('reportYearInline');
    if (yi && y) yi.value = y; // keep inline year in sync
  } else if (type === "events") {
    document.getElementById("eventsFilters").style.display = "block";
  }

  if (type) {
    loadReport();
  } else {
    document.getElementById("reportResult").innerHTML = "";
  }
}

function resetFeedbackFilters() {
  document.getElementById('feedbackMonth').value = '';
  document.getElementById('feedbackYear').value  = '';
  loadReport(1);
}

function resetBesoFilters() {
  document.getElementById("filterEdu").value = "";
  document.getElementById("filterCourse").value = "";
  document.getElementById("reportMonth").value = "";
  loadReport();
}

function loadReport(page = 1) {
  const type = document.getElementById("reportType").value;
  let url = "";

  if (type === "residents") {
    const gender = document.getElementById("residentGender").value || "";
    const zone   = document.getElementById("residentZone").value || "";
    const range  = document.getElementById("residentAgeRange").value || "";

    let age_min = "", age_max = "";
    if (range && range !== "custom") {
      const [minS, maxS] = range.split("-");
      age_min = minS || "";
      age_max = maxS || "";
    } else if (range === "custom") {
      age_min = document.getElementById("residentAgeMin").value || "";
      age_max = document.getElementById("residentAgeMax").value || "";
    }

    url = `load_report/load_residents_report.php?gender=${encodeURIComponent(gender)}&res_zone=${encodeURIComponent(zone)}&age_min=${encodeURIComponent(age_min)}&age_max=${encodeURIComponent(age_max)}&page=${page}`;

  } else if (type === "events") {
    const t = document.getElementById("eventTitle").value || "";
    const l = document.getElementById("eventLocation").value || "";
    const m = document.getElementById("eventMonth").value || "";
    const y = document.getElementById("eventYear").value || "";
    url = `load_report/load_events_report.php?title=${encodeURIComponent(t)}&location=${encodeURIComponent(l)}&month=${encodeURIComponent(m)}&year=${encodeURIComponent(y)}&page=${page}`;

  } else if (type === "schedules") {
    const cert = document.getElementById("reportCertificate")?.value || "";
    const year = document.getElementById("reportYear")?.value || "";
    url = `load_report/load_schedules_report.php?certificate=${encodeURIComponent(cert)}&year=${encodeURIComponent(year)}&page=${page}`;

  } else if (type === "feedbacks") {
    const month = document.getElementById("feedbackMonth").value || "";
    const year  = document.getElementById("feedbackYear").value || "";
    url = `load_report/load_feedbacks_report.php?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}&page=${page}`;

  } else if (type === "cases") {
    const month = document.getElementById("caseMonth").value || "";
    const year  = document.getElementById("caseYear").value || "";
    url = `load_report/load_cases_report.php?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}&page=${page}`;

  } else if (type === "beso") {
    const year   = document.getElementById("reportYear")?.value || "";
    const edu    = document.getElementById("filterEdu")?.value || "";
    const course = document.getElementById("filterCourse")?.value || "";
    const month  = document.getElementById("reportMonth")?.value || "";
    url = `load_report/load_beso_report.php?year=${encodeURIComponent(year)}&edu=${encodeURIComponent(edu)}&course=${encodeURIComponent(course)}&month=${encodeURIComponent(month)}&page=${page}`;
  }

  if (!url) return;

  fetch(url)
    .then(res => res.text())
    .then(html => { document.getElementById("reportResult").innerHTML = html; })
    .catch(() => { document.getElementById("reportResult").innerHTML = "<div class='text-danger'>Failed to load report.</div>"; });
}

function printReport() {
  const type = document.getElementById("reportType").value;

  // optional: log print action
  fetch('logs/log_report_print.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `report_type=${encodeURIComponent(type)}`
  });

  if (type === "events") {
    const t = document.getElementById("eventTitle").value || "";
    const l = document.getElementById("eventLocation").value || "";
    const m = document.getElementById("eventMonth").value || "";
    const y = document.getElementById("eventYear").value || "";
    const win = window.open(
      `load_report/load_events_report.php?print=1&title=${encodeURIComponent(t)}&location=${encodeURIComponent(l)}&month=${encodeURIComponent(m)}&year=${encodeURIComponent(y)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else if (type === "cases") {
    const month = document.getElementById("caseMonth").value || "";
    const year  = document.getElementById("caseYear").value || "";
    const win = window.open(
      `load_report/load_cases_report.php?print=1&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else if (type === "schedules") {
    const cert = document.getElementById("reportCertificate")?.value || "";
    const year = document.getElementById("reportYear")?.value || "";
    const win = window.open(
      `load_report/load_schedules_report.php?print=1&certificate=${encodeURIComponent(cert)}&year=${encodeURIComponent(year)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else if (type === "residents") {
    const gender = document.getElementById("residentGender")?.value || "";
    const zone   = document.getElementById("residentZone")?.value || "";
    const range  = document.getElementById("residentAgeRange").value || "";
    let age_min = "", age_max = "";
    if (range && range !== "custom") {
      const [minS, maxS] = range.split("-");
      age_min = minS || "";
      age_max = maxS || "";
    } else if (range === "custom") {
      age_min = document.getElementById("residentAgeMin").value || "";
      age_max = document.getElementById("residentAgeMax").value || "";
    }
    const win = window.open(
      `load_report/load_residents_report.php?print=1&gender=${encodeURIComponent(gender)}&res_zone=${encodeURIComponent(zone)}&age_min=${encodeURIComponent(age_min)}&age_max=${encodeURIComponent(age_max)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else if (type === "feedbacks") {
    const month = document.getElementById("feedbackMonth").value || "";
    const year  = document.getElementById("feedbackYear").value || "";
    const win = window.open(
      `load_report/load_feedbacks_report.php?print=1&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else if (type === "beso") {
    const year   = document.getElementById("reportYear")?.value || "";
    const edu    = document.getElementById("filterEdu")?.value || "";
    const course = document.getElementById("filterCourse")?.value || "";
    const month  = document.getElementById("reportMonth")?.value || "";
    const win = window.open(
      `load_report/load_beso_report.php?print=1&year=${encodeURIComponent(year)}&edu=${encodeURIComponent(edu)}&course=${encodeURIComponent(course)}&month=${encodeURIComponent(month)}`,
      '_blank'
    );
    if (win) win.onload = () => setTimeout(() => win.print());

  } else {
    window.print();
  }
}

// Pagination link delegation
document.addEventListener('click', function (e) {
  const link = e.target.closest('.pagination-link');
  if (!link) return;
  e.preventDefault();
  const page = parseInt(link.dataset.page || '1', 10);
  if (typeof loadReport === 'function') loadReport(page);
});

// Optional: initialize visibility on first load
document.addEventListener('DOMContentLoaded', () => {
  loadFiltersAndReport();
});
</script>
</body>
</html>
