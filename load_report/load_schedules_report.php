<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include '../class/session_timeout.php';

// ---------- Role gate ----------
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin', 'Barangay Secretary', 'Punong Barangay' ,'Revenue Staff'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

// ---------- Page / print ----------
$limit = 15;
$page  = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$start = ($page - 1) * $limit;
$print = isset($_GET['print']) && $_GET['print'] == '1';

// ---------- Certificate filter ----------
$certificateFilter = isset($_GET['certificate']) ? trim($_GET['certificate']) : '';
$certificateCondition = "";
if ($certificateFilter !== '') {
    $certificateCondition = " AND certificate = '" . $mysqli->real_escape_string($certificateFilter) . "'";
}

// ---------- Year filter (default current year) ----------
$currentYear = (int)date('Y');
$yearFilter  = (isset($_GET['year']) && ctype_digit($_GET['year'])) ? (int)$_GET['year'] : $currentYear;
$yearCondition = " AND YEAR(appt_date) = {$yearFilter} ";

// ---------- Base status filters ----------
$statusFilterSchedules = "s.status IN ('released','rejected')";
$statusFilterUrgent    = "u.status IN ('released','rejected')";
$statusFilterCed       = "c.cedula_status IN ('Released','Rejected','released','rejected')";
$statusFilterUrgCed    = "uc.cedula_status IN ('Released','Rejected','released','rejected')";

// ---------- Helpers ----------
function colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

// Optional delete-status conditions
$condSchedDel  = colExists($mysqli, 'schedules',             'appointment_delete_status') ? " AND COALESCE(s.appointment_delete_status,0)=0" : "";
$condUrgReqDel = colExists($mysqli, 'urgent_request',        'appointment_delete_status') ? " AND COALESCE(u.appointment_delete_status,0)=0" : "";
$condCedDel    = colExists($mysqli, 'cedula',                'cedula_delete_status')      ? " AND COALESCE(c.cedula_delete_status,0)=0"      : "";
$condUrgCedDel = colExists($mysqli, 'urgent_cedula_request', 'cedula_delete_status')      ? " AND COALESCE(uc.cedula_delete_status,0)=0"     : "";

// ---------- Unified subquery (no limit) ----------
$unionSqlCore = "
  SELECT 
    s.id,
    s.res_id,
    s.purpose,
    s.selected_date AS appt_date,
    s.selected_time AS appt_time,
    s.certificate,
    s.employee_id,
    s.tracking_number,
    s.status,
    CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix_name) AS full_name,
    s.total_payment,
    'schedule' AS source
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  WHERE $statusFilterSchedules
    $condSchedDel

  UNION ALL

  SELECT
    0 AS id,
    u.res_id,
    u.purpose,
    u.selected_date AS appt_date,
    u.selected_time AS appt_time,
    u.certificate,
    u.employee_id,
    u.tracking_number,
    u.status,
    CONCAT_WS(' ', r2.first_name, r2.middle_name, r2.last_name, r2.suffix_name) AS full_name,
    u.total_payment,
    'urgent' AS source
  FROM urgent_request u
  JOIN residents r2 ON u.res_id = r2.id
  WHERE $statusFilterUrgent
    $condUrgReqDel

  UNION ALL

  SELECT
    c.ced_id AS id,
    c.res_id,
    '' AS purpose,
    c.appointment_date AS appt_date,
    c.appointment_time AS appt_time,
    'Cedula' AS certificate,
    c.employee_id,
    c.tracking_number,
    c.cedula_status AS status,
    CONCAT_WS(' ', r3.first_name, r3.middle_name, r3.last_name, r3.suffix_name) AS full_name,
    c.total_payment,
    'cedula' AS source
  FROM cedula c
  JOIN residents r3 ON c.res_id = r3.id
  WHERE $statusFilterCed
    $condCedDel

  UNION ALL

  SELECT
    uc.urg_ced_id AS id,
    uc.res_id,
    '' AS purpose,
    uc.appointment_date AS appt_date,
    uc.appointment_time AS appt_time,
    'Cedula' AS certificate,
    uc.employee_id,
    uc.tracking_number,
    uc.cedula_status AS status,
    CONCAT_WS(' ', r4.first_name, r4.middle_name, r4.last_name, r4.suffix_name) AS full_name,
    uc.total_payment,
    'urgent_cedula' AS source
  FROM urgent_cedula_request uc
  JOIN residents r4 ON uc.res_id = r4.id
  WHERE $statusFilterUrgCed
    $condUrgCedDel
";

// ---------- Totals / details honoring filters ----------
$countSql = "
  SELECT COUNT(*) AS total
  FROM ( $unionSqlCore ) AS unified
  WHERE 1=1
    $certificateCondition
    $yearCondition
";
$total_query   = $mysqli->query($countSql);
$total_row     = $total_query ? $total_query->fetch_assoc() : ['total' => 0];
$total_records = (int)$total_row['total'];
$total_pages   = ($total_records > 0) ? (int)ceil($total_records / $limit) : 1;

$detailSql = "
  SELECT *
  FROM ( $unionSqlCore ) AS unified
  WHERE 1=1
    $certificateCondition
    $yearCondition
  ORDER BY appt_date DESC, appt_time DESC
  " . ($print ? "" : "LIMIT $start, $limit");
$result = $mysqli->query($detailSql);

// ---------- Revenue aggregations (Released only) ----------
$grandSql = "
  SELECT
    COUNT(*) AS total_rows,
    COALESCE(SUM(total_payment), 0) AS total_revenue
  FROM ( $unionSqlCore ) AS unified
  WHERE status IN ('released','Released')
    $certificateCondition
    $yearCondition
";
$grand = $mysqli->query($grandSql)->fetch_assoc();

$byCertSql = "
  SELECT certificate,
         COUNT(*) AS cnt,
         COALESCE(SUM(total_payment),0) AS revenue
  FROM ( $unionSqlCore ) AS unified
  WHERE status IN ('released','Released')
    $certificateCondition
    $yearCondition
  GROUP BY certificate
  ORDER BY revenue DESC
";
$byCert = $mysqli->query($byCertSql);

$byMonthSql = "
  SELECT
    DATE_FORMAT(appt_date, '%Y-%m') AS ym,
    COALESCE(SUM(total_payment),0) AS revenue,
    COUNT(*) AS cnt
  FROM ( $unionSqlCore ) AS unified
  WHERE status IN ('released','Released')
    $certificateCondition
    $yearCondition
  GROUP BY ym
  ORDER BY ym ASC
";
$byMonth = $mysqli->query($byMonthSql);

// ---------- Today (only if viewing current year) ----------
$showToday = ($yearFilter === $currentYear);
$todayRevenue = 0.0; $todayCount = 0;
if ($showToday) {
  $today = date('Y-m-d');
  $todaySql = "
    SELECT 
      COALESCE(SUM(total_payment),0) AS today_revenue,
      COUNT(*) AS today_count
    FROM ( $unionSqlCore ) AS unified
    WHERE status IN ('released','Released')
      AND DATE(appt_date) = '$today' $certificateCondition
  ";
  $todayData = $mysqli->query($todaySql)->fetch_assoc();
  $todayRevenue = (float)($todayData['today_revenue'] ?? 0);
  $todayCount   = (int)($todayData['today_count'] ?? 0);
}

// ---------- Branding (logos + signatories) for PRINT ----------
$barangayName = 'LGU Barangay Bugo';

function logo_to_data_uri(?string $blob): ?string {
  if (!$blob) return null;
  $info = @getimagesizefromstring($blob);
  $mime = is_array($info) && !empty($info['mime']) ? $info['mime'] : 'image/png';
  return 'data:'.$mime.';base64,'.base64_encode($blob);
}
function fetch_first_two_active_logos(mysqli $db): array {
  $sql = "SELECT logo_image FROM logos WHERE status='active' ORDER BY id ASC LIMIT 2";
  $rs = $db->query($sql);
  $out = [];
  if ($rs) while ($r = $rs->fetch_assoc()) $out[] = logo_to_data_uri($r['logo_image'] ?? null);
  return $out;
}
$logos = fetch_first_two_active_logos($mysqli);
$seal1 = $logos[0] ?? null;
$seal2 = $logos[1] ?? null;

function normalize_signature_data_uri(?string $data, ?string $mimeHint='image/png'): ?string {
  if (!$data) return null;
  if (str_starts_with($data, 'data:')) return $data;
  $info = @getimagesizefromstring($data);
  if (is_array($info) && !empty($info['mime'])) {
    return 'data:'.$info['mime'].';base64,'.base64_encode($data);
  }
  $mime = $mimeHint ?: 'image/png';
  $b64 = preg_replace('/\s+/', '', $data);
  return 'data:'.$mime.';base64,'.$b64;
}

/** separate queries for signatory: 1) barangay_information -> official_id + esig, 2) residents -> name */
function fetch_signatory_separate(mysqli $db, string $pos): array {
  $sql1 = "SELECT bi.official_id, bi.esignature, bi.esignature_mime
           FROM barangay_information bi
           WHERE LOWER(bi.`position`)=LOWER(?) AND bi.`status`='active'
           ORDER BY bi.id DESC
           LIMIT 1";
  $stmt1 = $db->prepare($sql1);
  if (!$stmt1) return ['name'=>null,'sig'=>null];

  $stmt1->bind_param('s', $pos);
  if (!$stmt1->execute()) { $stmt1->close(); return ['name'=>null,'sig'=>null]; }
  $stmt1->store_result();

  $official_id = null; $blob = null; $mime = null;
  $stmt1->bind_result($official_id, $blob, $mime);

  $name = null; $sig = null;
  if ($stmt1->fetch()) {
    if (!empty($blob)) $sig = normalize_signature_data_uri($blob, $mime ?: 'image/png');
    if ($official_id) {
      $sql2 = "SELECT first_name, middle_name, last_name, suffix_name FROM residents WHERE id = ? LIMIT 1";
      $stmt2 = $db->prepare($sql2);
      if ($stmt2) {
        $stmt2->bind_param('i', $official_id);
        if ($stmt2->execute()) {
          $stmt2->store_result();
          $first=$middle=$last=$suffix=null;
          $stmt2->bind_result($first,$middle,$last,$suffix);
          if ($stmt2->fetch()) {
            $name = trim(preg_replace('/\s+/', ' ', ($first ?? '').' '.($middle ?? '').' '.($last ?? '').' '.($suffix ?? '')));
          }
          $stmt2->free_result();
        }
        $stmt2->close();
      }
    }
  }
  $stmt1->free_result();
  $stmt1->close();
  return ['name'=>$name,'sig'=>$sig];
}
$sgBeso = fetch_signatory_separate($mysqli, 'Revenue Staff');
$sgSec  = fetch_signatory_separate($mysqli, 'Barangay Secretary');
$sgPB   = fetch_signatory_separate($mysqli, 'Punong Barangay');

// ---------- CURRENT USER (employee_list + employee_roles) ----------
function current_employee_with_roles(mysqli $db): array {
  $name = null; $sig = null; $roles = null;

  // Possible id keys in session
  $empId = $_SESSION['employee_id'] ?? $_SESSION['emp_id'] ?? $_SESSION['Employee_Id'] ?? $_SESSION['id'] ?? null;

  // Fetch by employee_id first
  if ($empId && is_numeric($empId)) {
    if ($st = $db->prepare("SELECT employee_fname, employee_mname, employee_lname, esignature, esignature_mime
                            FROM employee_list WHERE employee_id=? LIMIT 1")) {
      $empId = (int)$empId;
      $st->bind_param('i', $empId);
      if ($st->execute()) {
        $st->store_result();
        $fn=$mn=$ln=$blob=$mime=null;
        $st->bind_result($fn,$mn,$ln,$blob,$mime);
        if ($st->fetch()) {
          $mi = $mn ? ' '.mb_substr(trim($mn),0,1).'.' : '';
          $name = trim(preg_replace('/\s+/', ' ', ($fn??'').$mi.' '.($ln??'')));
          if (!empty($blob)) $sig = normalize_signature_data_uri($blob, $mime ?: 'image/png');
        }
      }
      $st->close();
    }
    if ($st = $db->prepare("SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ') AS role_names
                            FROM employee_roles
                            WHERE Employee_Id=? AND Role_Name IS NOT NULL AND Role_Name<>''")) {
      $st->bind_param('i', $empId);
      if ($st->execute()) {
        $st->store_result();
        $rn = null; $st->bind_result($rn);
        if ($st->fetch()) $roles = $rn ?: null;
      }
      $st->close();
    }
  }

  // Fallback by username
  if (!$name) {
    $uname = $_SESSION['employee_username'] ?? $_SESSION['username'] ?? null;
    if ($uname && $st = $db->prepare("SELECT employee_id, employee_fname, employee_mname, employee_lname, esignature, esignature_mime
                                      FROM employee_list WHERE employee_username=? LIMIT 1")) {
      $st->bind_param('s', $uname);
      if ($st->execute()) {
        $st->store_result();
        $id=$fn=$mn=$ln=$blob=$mime=null;
        $st->bind_result($id,$fn,$mn,$ln,$blob,$mime);
        if ($st->fetch()) {
          $mi = $mn ? ' '.mb_substr(trim($mn),0,1).'.' : '';
          $name = trim(preg_replace('/\s+/', ' ', ($fn??'').$mi.' '.($ln??'')));
          if (!empty($blob)) $sig = normalize_signature_data_uri($blob, $mime ?: 'image/png');
          if ($id && !$roles && ($st2 = $db->prepare("SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ') AS role_names
                                                      FROM employee_roles
                                                      WHERE Employee_Id=? AND Role_Name IS NOT NULL AND Role_Name<>''"))) {
            $st2->bind_param('i', $id);
            if ($st2->execute()) {
              $st2->store_result();
              $rn = null; $st2->bind_result($rn);
              if ($st2->fetch()) $roles = $rn ?: null;
            }
            $st2->close();
          }
        }
      }
      $st->close();
    }
  }

  // Last-chance fallbacks
  if (!$name) {
    $cand = [
      $_SESSION['Full_Name'] ?? null,
      $_SESSION['full_name'] ?? null,
      trim(($_SESSION['first_name'] ?? '').' '.($_SESSION['last_name'] ?? '')) ?: null,
    ];
    foreach ($cand as $v) { if ($v && trim($v)!=='') { $name = trim($v); break; } }
  }
  if (!$roles) $roles = $_SESSION['Role_Name'] ?? null;

  return ['name'=>$name, 'sig'=>$sig, 'roles'=>$roles];
}

// ---------- Prepared/Noted/Attested (Prepared by = CURRENT USER) ----------
$cu = current_employee_with_roles($mysqli);
$preparedBy  = $cu['name'] ?: ($sgBeso['name'] ?: 'Revenue Staff');
$preparedSig = $cu['sig']  ?? ($sgBeso['sig'] ?? null);
$preparedPos = $cu['roles'] ?: 'Revenue Staff';

$notedBy    = $sgSec['name']  ?: 'Barangay Secretary';
$attestedBy = $sgPB['name']   ?: 'Punong Barangay';

$notedSig    = $sgSec['sig'];
$attestedSig = $sgPB['sig'];

// ---------- Build normal-mode HTML first ----------
$certBadge = ($certificateFilter !== '')
  ? " &mdash; <span class='badge bg-info-subtle text-info-emphasis border border-info-subtle'>Filter: " . htmlspecialchars($certificateFilter) . "</span>"
  : "";

$grandCount    = (int)($grand['total_rows'] ?? 0);
$grandRevenue  = (float)($grand['total_revenue'] ?? 0);
$grandRevenueF = "₱" . number_format($grandRevenue, 2);
$todayLabel    = date('M d, Y');
$todayRevenueF = "₱" . number_format($todayRevenue, 2);

// Build rows + totals for "By Certificate"
$cert_rows = '';
$cert_total_cnt = 0;
$cert_total_rev = 0.0;
if ($byCert && $byCert->num_rows) {
  while ($c = $byCert->fetch_assoc()) {
    $c_cnt = (int)$c['cnt'];
    $c_rev = (float)$c['revenue'];
    $cert_total_cnt += $c_cnt;
    $cert_total_rev += $c_rev;
    $cert_rows .= "
      <tr>
        <td>" . htmlspecialchars($c['certificate']) . "</td>
        <td class='text-end'>{$c_cnt}</td>
        <td class='text-end'>₱" . number_format($c_rev, 2) . "</td>
      </tr>";
  }
} else {
  $cert_rows = "<tr><td colspan='3' class='text-center text-muted'>No data</td></tr>";
}

// Build rows + totals for "Monthly Revenue"
$month_rows = '';
$month_total_cnt = 0;
$month_total_rev = 0.0;
if ($byMonth && $byMonth->num_rows) {
  while ($m = $byMonth->fetch_assoc()) {
    $m_cnt = (int)$m['cnt'];
    $m_rev = (float)$m['revenue'];
    $month_total_cnt += $m_cnt;
    $month_total_rev += $m_rev;
    $monthLabel = date('F Y', strtotime($m['ym'] . '-01'));
    $month_rows .= "
      <tr>
        <td>{$monthLabel}</td>
        <td class='text-end'>{$m_cnt}</td>
        <td class='text-end'>₱" . number_format($m_rev, 2) . "</td>
      </tr>";
  }
} else {
  $month_rows = "<tr><td colspan='3' class='text-center text-muted'>No data for selected year</td></tr>";
}

$self = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);

// ---------- Buffer the content area ----------
ob_start();
?>
<style>
  .report-head {display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 12px;border:1px solid #e9ecef;border-radius:12px;background:#fff;
    box-shadow:0 2px 10px rgba(0,0,0,.03); margin-bottom:12px;}
  .report-head .title {font-weight:700;color:#0d6efd;font-size:1.2rem;}
  .report-wrap {border:1px solid #e9ecef;border-radius:14px;background:#fff;padding:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.04);}
  .analytics-grid {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
  @media (max-width:992px){.analytics-grid{grid-template-columns:1fr;}}
  .metric-card {background:linear-gradient(180deg,#ffffff,#f8fafc);border:1px solid #e9ecef;border-radius:14px;padding:14px;}
  .metric-label {color:#6c757d;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:6px;}
  .metric-value {font-size:1.25rem;font-weight:700;margin-top:4px;}
  .section-title {margin:16px 0 8px;font-size:1rem;font-weight:700;color:#212529;}
  .table-modern {width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border-radius:12px;border:1px solid #e9ecef;}
  .table-modern thead th {position:sticky;top:0;z-index:1;background:#0d6efd;color:#fff;
    text-transform:uppercase;letter-spacing:.02em;font-size:.75rem;padding:.55rem .6rem;text-align:center;}
  .table-modern tbody td {padding:.55rem .6rem;font-size:.9rem;vertical-align:middle;border-bottom:1px solid #f1f3f5;text-align:center;background:#fff;}
  .table-modern tbody tr:nth-child(even) td {background:#f8f9fa;}
  .table-modern tbody tr:hover td {background:#eef4ff;}
  .table-modern tfoot td {background:#f1f3f5;font-weight:700;padding:.6rem;text-align:center;}
  .currency {font-variant-numeric: tabular-nums;}
  @media print {.report-head {display:none !important;}}
</style>

<div class="report-head">
  <div class="title">Schedules &amp; Requests Report<?=$certBadge?></div>
  <div class="small text-muted">
    Generated on: <strong><?=htmlspecialchars($todayLabel)?></strong>
  </div>
</div>

<div class="report-wrap">
<div class="analytics-grid">
  <div class="metric-card">
    <div class="metric-label"><i class="bi bi-clipboard2-check"></i> Released Records</div>
    <div class="metric-value"><?=$grandCount?></div>
  </div>
  <div class="metric-card">
    <div class="metric-label"><i class="bi bi-cash-stack"></i> Total Revenue (<?=htmlspecialchars($yearFilter)?>)</div>
    <div class="metric-value"><?=$grandRevenueF?></div>
  </div>

  <?php if (!$print): ?>
    <?php if ($showToday): ?>
      <div class="metric-card">
        <div class="metric-label"><i class="bi bi-calendar2-day"></i> Today (<?=htmlspecialchars($todayLabel)?>)</div>
        <div class="metric-value"><?=$todayRevenueF?></div>
      </div>
    <?php else: ?>
      <div class="metric-card">
        <div class="metric-label"><i class="bi bi-info-circle"></i> Note</div>
        <div class="metric-value" style="font-size:0.95rem;font-weight:600">Viewing <?=htmlspecialchars($yearFilter)?>. Today metric hidden.</div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>


  <div class="section-title">Revenue by Certificate</div>
  <div class="table-responsive">
    <table class="table-modern">
      <thead>
        <tr>
          <th style= "width: 500px;">Certificate</th>
          <th style= "width: 500px;">Released</th>
          <th style= "width: 500px;">Revenue (₱)</th>
        </tr>
      </thead>
      <tbody>
        <?=$cert_rows?>
      </tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td class="text-end"><?=$cert_total_cnt?></td>
          <td class="text-end currency">₱<?=number_format($cert_total_rev, 2)?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="section-title">Monthly Revenue (<?=htmlspecialchars($yearFilter)?>)</div>
  <div class="table-responsive">
    <table class="table-modern">
      <thead>
        <tr>
          <th style= "width: 500px;">Month</th>
          <th style= "width: 500px;">Released</th>
          <th style= "width: 500px;">Revenue (₱)</th>
        </tr>
      </thead>
      <tbody>
        <?=$month_rows?>
      </tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td class="text-end"><?=$month_total_cnt?></td>
          <td class="text-end currency">₱<?=number_format($month_total_rev, 2)?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if (!$print && $total_pages > 1): ?>
    <nav class='mt-3'>
      <ul class='pagination justify-content-center'>
        <?php for ($i = 1; $i <= $total_pages; $i++):
          $active = ($i == $page) ? "active" : ""; ?>
          <li class='page-item <?=$active?>'>
            <a
              href='<?=$self?>?page=<?=$i?>&year=<?=$yearFilter?><?=($certificateFilter!==""?"&certificate=".urlencode($certificateFilter):"")?>'
              class='page-link'><?=$i?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>
<?php
$contentHtml = ob_get_clean();

// ---------- PRINT LAYOUT (header as normal block so it DOES NOT repeat) ----------
if ($print) {
  $yrUi = htmlspecialchars((string)$yearFilter);

  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Schedules & Requests Report</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    body { font-family: Segoe UI, Arial, sans-serif; color:#0f172a; }
    @media print{ html, body { width:297mm; height:210mm; } }

    /* Header as a normal block — shown once, never repeats */
    .header{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; margin-bottom:10px; }
    .seals{ display:flex; gap:12px; }
    .seal{ width:52px; height:52px; border-radius:50%; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
    .seal img{ width:100%; height:100%; object-fit:cover; }
    .title{text-align:center;}
    .title h1{ margin:0; font-size:20px; font-weight:800; }
    .year{ font-size:14px; }

    /* Content wrapper */
    .box{ border:1.5px solid #cfd8e3; padding:10px; page-break-inside:auto; }
    tr, img, .metric-card { break-inside: avoid; page-break-inside: avoid; }

    /* Footer immediately after content; stays together; flows to next page if needed */
    .footer{
      display:grid; grid-template-columns:1fr 1fr 1fr; text-align:center; margin-top:20px;
      break-inside: avoid; page-break-inside: avoid; clear: both;
    }
    .footer *{ break-inside: avoid; page-break-inside: avoid; }
    .sig .lbl{ font-size:12px; margin-bottom:10px; font-weight:600; }
    .sig .name{ font-weight:700; font-size:14px; margin-bottom:2px; }
    .sig .pos{ font-size:12px; color:#475569; }
    .sigimg{ max-height:56px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto; }
  </style></head><body>';

  // ——— Header (single instance) ———
  echo '<div class="header">
          <div class="seals">'.
            ($seal1 ? '<div class="seal"><img src="'.$seal1.'" alt="Seal 1"></div>' : '').
            ($seal2 ? '<div class="seal"><img src="'.$seal2.'" alt="Seal 2"></div>' : '').
        '</div>
          <div class="title"><h1>'.htmlspecialchars($barangayName).' - Revenue Report</h1></div>
          <div class="year">Year: '.$yrUi.'</div>
        </div>';

  // ——— Content ———
  echo '<div class="box">'.$contentHtml.'</div>';

  // ——— Footer (right after content) ———
  echo '<div class="footer">
          <div class="sig">
            <div class="lbl">Prepared by:</div>'.
            '<div class="name">'.htmlspecialchars($preparedBy).'</div>
            <div class="pos">'.htmlspecialchars($preparedPos).'</div>
          </div>
          <div class="sig">
            <div class="lbl">Noted by:</div>'.
            ($notedSig ? '<img class="sigimg" src="'.$notedSig.'" alt="Noted signature">' : '').
            '<div class="name">'.htmlspecialchars($notedBy).'</div>
            <div class="pos">Barangay Secretary</div>
          </div>
          <div class="sig">
            <div class="lbl">Attested by:</div>'.
            ($attestedSig ? '<img class="sigimg" src="'.$attestedSig.'" alt="Attested signature">' : '').
            '<div class="name">'.htmlspecialchars($attestedBy).'</div>
            <div class="pos">Punong Barangay</div>
          </div>
        </div>';

  echo '</body></html>';
  exit;
}

// ---------- NORMAL (AJAX) OUTPUT ----------
echo $contentHtml;
