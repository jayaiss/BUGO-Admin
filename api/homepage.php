<!DOCTYPE html>
<html lang="en">
<?php
require_once __DIR__ . '/../include/connection.php';

/* ---------- COUNTS (shared with your schema) ---------- */

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('formatFullName')) {
  function formatFullName($first, $middle, $last, $suffix) {
    $mi = $middle ? strtoupper($middle[0]).'.' : '';
    $suffix = $suffix ? ' '.$suffix : '';
    return trim("$first $mi $last$suffix");
  }
}
$BASE_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';

/* --- Captain (Punong Barangay) --- */
$captain = null;
$captain_sql = "
  SELECT b.photo, b.position,
         r.first_name, r.middle_name, r.last_name, r.suffix_name
  FROM barangay_information AS b
  JOIN residents AS r ON b.official_id = r.id
  WHERE b.status = 'active' AND b.position = 'Punong Barangay'
  ORDER BY b.id ASC
  LIMIT 1
";
if ($cap_rs = $mysqli->query($captain_sql)) {
  if ($cap_rs->num_rows > 0) $captain = $cap_rs->fetch_assoc();
}

// Active residents
$res_q = "SELECT COUNT(*) AS c FROM residents WHERE resident_delete_status = 0";
$res_r = $mysqli->query($res_q);
$active_residents = (int)($res_r->fetch_assoc()['c'] ?? 0);

// Total requests (live + archived, all modules)
$total_requests_q = "
  SELECT SUM(total) AS total_count FROM (
    SELECT COUNT(*) AS total FROM cedula WHERE cedula_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM schedules WHERE appointment_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM urgent_request WHERE urgent_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM urgent_cedula_request WHERE cedula_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM archived_cedula WHERE cedula_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM archived_schedules WHERE appointment_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM archived_urgent_request WHERE urgent_delete_status = 0
    UNION ALL
    SELECT COUNT(*) AS total FROM archived_urgent_cedula_request WHERE cedula_delete_status = 0
  ) x";
$total_requests = (int)(($mysqli->query($total_requests_q))->fetch_assoc()['total_count'] ?? 0);

// Issued certificates (all modules)
$issued_q = "
  SELECT SUM(issued) AS c FROM (
    SELECT COUNT(*) AS issued FROM cedula WHERE cedula_status='Issued' AND cedula_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM schedules WHERE status='Issued' AND appointment_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM urgent_request WHERE status='Issued' AND urgent_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM urgent_cedula_request WHERE cedula_status='Issued' AND cedula_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM archived_cedula WHERE cedula_status='Issued' AND cedula_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM archived_schedules WHERE status='Issued' AND appointment_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM archived_urgent_request WHERE urgent_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS issued FROM archived_urgent_cedula_request WHERE cedula_status='Issued' AND cedula_delete_status=0
  ) x";
$total_issued = (int)(($mysqli->query($issued_q))->fetch_assoc()['c'] ?? 0);
$issued_pct = $total_requests > 0 ? round($total_issued / $total_requests * 100) : 0;

// Pending approvals (all modules)
$pending_q = "
  SELECT SUM(pending) AS c FROM (
    SELECT COUNT(*) AS pending FROM schedules WHERE status='Pending' AND appointment_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS pending FROM cedula WHERE cedula_status='Pending' AND cedula_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS pending FROM urgent_request WHERE status='Pending' AND urgent_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS pending FROM urgent_cedula_request WHERE cedula_status='Pending' AND cedula_delete_status=0
  ) x";
$pending_approvals = (int)(($mysqli->query($pending_q))->fetch_assoc()['c'] ?? 0);

// Urgent requests (pending only)
$urgent_q = "
  SELECT SUM(u) AS c FROM (
    SELECT COUNT(*) AS u FROM urgent_request WHERE status='Pending' AND urgent_delete_status=0
    UNION ALL
    SELECT COUNT(*) AS u FROM urgent_cedula_request WHERE cedula_status='Pending' AND cedula_delete_status=0
  ) x";
$urgent_pending = (int)(($mysqli->query($urgent_q))->fetch_assoc()['c'] ?? 0);

// Approval rate (Approved vs total across modules)
$rate_q = "
  SELECT SUM(total) t, SUM(approved) a FROM (
    SELECT COUNT(*) total, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) approved
    FROM schedules WHERE appointment_delete_status=0
    UNION ALL
    SELECT COUNT(*) total, SUM(CASE WHEN cedula_status='Approved' THEN 1 ELSE 0 END) approved
    FROM cedula WHERE cedula_delete_status=0
    UNION ALL
    SELECT COUNT(*) total, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) approved
    FROM urgent_request WHERE urgent_delete_status=0
    UNION ALL
    SELECT COUNT(*) total, SUM(CASE WHEN cedula_status='Approved' THEN 1 ELSE 0 END) approved
    FROM urgent_cedula_request WHERE cedula_delete_status=0
  ) x";
$rate_res = $mysqli->query($rate_q)->fetch_assoc();
$approval_rate = ($rate_res['t'] ?? 0) > 0 ? round(($rate_res['a'] / $rate_res['t']) * 100) : 0;

/* -------- FEEDS (events, notices) -------- */

/* Upcoming events (prefer future, ASC; fallback to recent if none) */
$events_q = "
  SELECT e.id,
         en.event_name AS event_title,
         e.event_description,
         e.event_date,
         e.event_time,
         e.event_location
  FROM events e
  JOIN event_name en ON e.event_title = en.Id
  WHERE e.events_delete_status = 0
    AND DATE(e.event_date) >= CURDATE()
  ORDER BY e.event_date ASC, COALESCE(e.event_time,'00:00') ASC
  LIMIT 6
";
$events_rs = $mysqli->query($events_q);

/* If there are no future events, show the most recent past ones instead */
if (!$events_rs || $events_rs->num_rows === 0) {
  $events_q = "
    SELECT e.id,
           en.event_name AS event_title,
           e.event_description,
           e.event_date,
           e.event_time,
           e.event_location
    FROM events e
    JOIN event_name en ON e.event_title = en.Id
    WHERE e.events_delete_status = 0
    ORDER BY e.event_date DESC, COALESCE(e.event_time,'00:00') DESC
    LIMIT 6
  ";
  $events_rs = $mysqli->query($events_q);
}

/* helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Home — Barangay Bugo</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{ background:#f6f8fb; }
    .hero{ background:#fff; border-radius:24px; padding:32px; }
    .avatar{ width:190px; height:190px; object-fit:cover; border-radius:50%; box-shadow:0 8px 24px rgba(0,0,0,.08); }
    .kpi{ background:#fff; border-radius:18px; padding:18px; box-shadow:0 6px 16px rgba(0, 0, 0, 0.06); }
    .kpi .label{ font-size:.9rem; color:#6b7280; }
    .kpi .value{ font-size:1.9rem; font-weight:700; }
    .card-lite{ background:#fff; border-radius:18px; padding:18px; box-shadow:0 6px 16px rgba(0,0,0,.06); }
    
   .action-card{position:relative;background:#fff;border-radius:16px;padding:16px;box-shadow:0 6px 16px rgba(0,0,0,.06);transition:transform .12s ease,box-shadow .12s ease}
.action-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(0,0,0,.08)}
.action-chip{display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .6rem;border-radius:999px;font-weight:600}
.action-sub{font-size:.9rem;color:#6b7280}
.num{font-size:2rem;font-weight:800;line-height:1}


.pulse-card .meter {
      --val: 0deg;            /* set inline per meter */
      --col: #0d6efd;         /* set inline per meter */
      width: 120px; height: 120px; border-radius: 50%;
      background: conic-gradient(var(--col) var(--val), #e9edf5 0);
      display: grid; place-items: center;
    }
    .pulse-card .meter .inner {
      width: 86px; height: 86px; border-radius: 50%;
      background: #fff; display: grid; place-items: center;
      font-weight: 800; font-size: 1.15rem;
      box-shadow: inset 0 0 0 1px rgba(0,0,0,.06);
    }
    .chip { 
      background: #f6f8fb; border-radius: 999px; 
      padding: .35rem .6rem; font-weight: 600; font-size: .9rem;
      display: inline-flex; align-items: center; gap: .4rem; width: 100%;
      border: 1px solid rgba(0,0,0,.05);
    }
    .chip small { font-weight: 700; margin-left: auto; }
    .chip-warn    { background:#fff8e6; border-color:#ffe6a8; }
    .chip-danger  { background:#ffe8e8; border-color:#ffc4c4; }
    .chip-success { background:#e8f7ee; border-color:#cfeedd; }
    .chip-primary { background:#e9f1ff; border-color:#cfe1ff; }
    .chip-muted   { background:#eff2f6; border-color:#e0e4ea; }

    .date-badge{
      width:56px; border-radius:12px; background:#f1f5fb; 
      border:1px solid #e5ebf5; text-align:center; padding:.25rem .2rem; line-height:1.1;
    }
    .date-badge .m{font-size:.75rem; color:#5b6b7c; text-transform:uppercase}
    .date-badge .d{font-size:1.1rem; font-weight:800}
  </style>
</head>
<body>

<div class="container py-4">

  <!-- HERO (ADMIN) -->
<div class="hero mb-4" style="background:linear-gradient(135deg,#f7faff 0%,#ffffff 65%);">
  <div class="row align-items-center g-4">
    <div class="col-lg-8">
      <p class="text-muted mb-1">Administrator</p>
      <h1 class="display-6 fw-bold lh-sm mb-2">Run Barangay Operations—Fast and Organized.</h1>
      <p class="text-secondary mb-4">Review backlogs, approve requests, and post announcements in one place.</p>

      <div class="d-flex gap-2 flex-wrap">
        <a href="index_admin.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>" class="btn btn-primary">
          <i class="bi bi-calendar-check me-1"></i> Go to Appointments
        </a>
        <a href="index_admin.php?page=<?php echo urlencode(encrypt('certificate_list')); ?>" class="btn btn-outline-primary">
          <i class="bi bi-file-text me-1"></i> Certificates
        </a>
        <a href="index_admin.php?page=<?php echo urlencode(encrypt('announcements')); ?>" class="btn btn-dark">
          <i class="bi bi-megaphone me-1"></i> Post Notice
        </a>
        <a href="index_admin.php?page=<?php echo urlencode(encrypt('event_list')); ?>" class="btn btn-outline-dark">
          <i class="bi bi-calendar-event me-1"></i> Manage Events
        </a>
      </div>
    </div>

  <div class="col-lg-4 text-center">
  <?php if ($captain): ?>
    <?php if (!empty($captain['photo'])): ?>
      <img
        src="data:image/jpeg;base64,<?= base64_encode($captain['photo']) ?>"
        alt="<?= h($captain['position']) ?>"
        class="avatar mb-2"
        style="width:180px;height:180px;object-fit:cover;border-radius:50%">
    <?php else: ?>
      <img
        src="<?= h($BASE_URL) ?>assets/logo/default-captain.jpg"
        alt="<?= h($captain['position']) ?>"
        class="avatar mb-2"
        style="width:180px;height:180px;object-fit:cover;border-radius:50%">
    <?php endif; ?>
    <div class="fw-semibold">
      <?= h(formatFullName($captain['first_name'],$captain['middle_name'],$captain['last_name'],$captain['suffix_name'])) ?>
    </div>
    <div class="text-muted small"><?= h($captain['position']) ?></div>
  <?php else: ?>
    <img
      src="<?= h($BASE_URL) ?>assets/logo/default-captain.jpg"
      alt="Barangay Captain"
      class="avatar mb-2"
      style="width:180px;height:180px;object-fit:cover;border-radius:50%">
    <div class="fw-semibold">No Active Captain</div>
    <div class="text-muted small">Position Unavailable</div>
  <?php endif; ?>
</div>


  </div>
</div>

 <!-- KPI ROW (not clickable) -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Active Residents</div>
      <div class="value text-primary"><?= $active_residents ?></div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Total Requests</div>
      <div class="value"><?= $total_requests ?></div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Pending Approvals</div>
      <div class="value text-warning"><?= $pending_approvals ?></div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Urgent Requests</div>
      <div class="value text-danger"><?= $urgent_pending ?></div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Issued Certificates</div>
      <div class="value text-success">
        <?= $total_issued ?> <span class="fs-6 text-muted">(<?= $issued_pct ?>%)</span>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi text-center">
      <div class="label">Approval Rate</div>
      <div class="value"><?= $approval_rate ?>%</div>
    </div>
  </div>
</div>

    <!-- SERVICE PULSE (read-only) -->
  <div class="card-lite pulse-card mb-3">
    <h5 class="mb-3">Service Pulse</h5>

    <div class="d-flex justify-content-around">
      <!-- Approval Rate -->
      <div class="text-center">
        <div class="meter" style="--val: <?= max(0,min(100,(int)$approval_rate))*3.6 ?>deg; --col:#0d6efd;">
          <div class="inner"><?= (int)$approval_rate ?>%</div>
        </div>
        <div class="small text-muted mt-2">Approval Rate</div>
      </div>
      <!-- Certificates Issued % -->
      <div class="text-center">
        <div class="meter" style="--val: <?= max(0,min(100,(int)$issued_pct))*3.6 ?>deg; --col:#198754;">
          <div class="inner"><?= (int)$issued_pct ?>%</div>
        </div>
        <div class="small text-muted mt-2">Certificates Issued</div>
      </div>
    </div>

    <div class="row g-2 mt-3">
      <div class="col-6"><div class="chip chip-warn">Pending approvals <small><?= (int)$pending_approvals ?></small></div></div>
      <div class="col-6"><div class="chip chip-danger">Urgent requests <small><?= (int)$urgent_pending ?></small></div></div>
      <div class="col-6"><div class="chip chip-primary">All requests <small><?= (int)$total_requests ?></small></div></div>
      <div class="col-6"><div class="chip chip-muted">Active residents <small><?= (int)$active_residents ?></small></div></div>
    </div>

    <div class="text-muted small mt-2">Passive summary · No actions.</div>
  </div>

  <!-- UPCOMING (read-only) -->
  <div class="card-lite">
    <h5 class="mb-3">Upcoming</h5>
    <?php
      // Accept either $events_rs (mysqli result) or $events (array) depending on your page
      $upcoming = [];
      if (isset($events_rs) && $events_rs) {
        $events_rs->data_seek(0);
        while ($row = $events_rs->fetch_assoc()) { $upcoming[] = $row; }
      } elseif (!empty($events)) {
        $upcoming = $events;
      }

      $shown = 0;
      foreach ($upcoming as $e) {
        $date = $e['event_date'] ?? '';
        if (!$date) continue;
        // Only future or today
        if (strtotime($date) < strtotime('today')) continue;

        $title = $e['event_title'] ?? ($e['title'] ?? 'Event');
        $time  = $e['event_time'] ?? '';
        $loc   = $e['event_location'] ?? '';
        $shown++;
        if ($shown > 3) break;
        ?>
        <div class="d-flex align-items-center mb-3">
          <div class="date-badge me-3">
            <div class="m"><?= date('M', strtotime($date)) ?></div>
            <div class="d"><?= date('d', strtotime($date)) ?></div>
          </div>
          <div>
            <div class="fw-semibold"><?= h($title) ?></div>
            <div class="small text-muted">
              <?= date('l', strtotime($date)) ?>
              <?= $time ? ' · ' . h($time) : '' ?>
              <?= $loc  ? ' · ' . h($loc)  : '' ?>
            </div>
          </div>
        </div>
        <?php
      }
      if ($shown === 0): ?>
        <div class="text-muted">No upcoming items.</div>
    <?php endif; ?>
  </div>

</div>
  

</div>




<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
