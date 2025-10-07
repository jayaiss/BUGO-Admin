<?php
// load_report/load_beso_report.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

session_start();
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// ---------- ACCESS CONTROL ----------
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin', 'Barangay Secretary', 'Punong Barangay', 'BESO'], true)) {
  http_response_code(403);
  header('Content-Type: text/html; charset=UTF-8');
  require_once __DIR__ . '../../security/403.html';
  exit;
}

// ---------- IDENTITY ----------
$barangayName = 'LGU Barangay Bugo';

// ---------- LOGOS ----------
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

// ---------- SIGNATURE HELPERS ----------
function normalize_signature_data_uri(?string $data, ?string $mimeHint='image/png'): ?string {
  if (!$data) return null;
  if (str_starts_with($data, 'data:')) return $data;
  $info = @getimagesizefromstring($data);
  if (is_array($info) && !empty($info['mime'])) {
    return 'data:'.$info['mime'].';base64,'.base64_encode($data);
  }
  $mime = $mimeHint ?: 'image/png';
  return 'data:'.$mime.';base64,'.base64_encode($data);
}

// Current logged-in employee (name + roles + signature)
function current_employee_with_roles(mysqli $db): array {
  $name = null; $sig = null; $roles = null;
  $empId = $_SESSION['employee_id'] ?? $_SESSION['emp_id'] ?? $_SESSION['Employee_Id'] ?? $_SESSION['id'] ?? null;

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
    if ($st = $db->prepare("SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ')
                            FROM employee_roles WHERE Employee_Id=?")) {
      $st->bind_param('i', $empId);
      if ($st->execute()) {
        $st->store_result();
        $rn = null; $st->bind_result($rn);
        if ($st->fetch()) $roles = $rn ?: null;
      }
      $st->close();
    }
  }
  if (!$roles) $roles = $_SESSION['Role_Name'] ?? null;
  return ['name'=>$name, 'sig'=>$sig, 'roles'=>$roles];
}

// Barangay signatory (by position)
function fetch_signatory(mysqli $db, string $pos): array {
  $sql = "SELECT r.first_name, r.middle_name, r.last_name, r.suffix_name,
                 bi.esignature, bi.esignature_mime
          FROM barangay_information bi
          LEFT JOIN residents r ON r.id = bi.official_id
          WHERE LOWER(bi.`position`)=LOWER(?) AND bi.`status`='active'
          ORDER BY bi.id DESC LIMIT 1";
  $st = $db->prepare($sql);
  if (!$st) return ['name'=>null,'sig'=>null];
  $st->bind_param('s', $pos);
  if (!$st->execute()) return ['name'=>null,'sig'=>null];
  $row = $st->get_result()->fetch_assoc() ?: [];
  $name = trim(preg_replace('/\s+/', ' ', ($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? '').' '.($row['suffix_name'] ?? '')));
  $sig = !empty($row['esignature']) ? 'data:'.($row['esignature_mime'] ?: 'image/png').';base64,'.base64_encode($row['esignature']) : null;
  return ['name'=>$name, 'sig'=>$sig];
}
$cu      = current_employee_with_roles($mysqli);
$sgSec   = fetch_signatory($mysqli, 'Barangay Secretary');
$sgPB    = fetch_signatory($mysqli, 'Punong Barangay');
$preparedBy  = $cu['name'] ?: 'BESO';
$preparedSig = $cu['sig']  ?? null;
$preparedPos = $cu['roles'] ?: 'BESO';
$notedBy     = $sgSec['name']  ?: 'Barangay Secretary';
$attestedBy  = $sgPB['name']   ?: 'Punong Barangay';
$notedSig    = $sgSec['sig'] ?? null;
$attestedSig = $sgPB['sig']  ?? null;

// ---------- PARAMS ----------
$print  = isset($_GET['print']) && $_GET['print'] == '1';
$year   = $_GET['year']   ?? '';
$edu    = $_GET['edu']    ?? '';
$course = $_GET['course'] ?? '';
$month  = $_GET['month']  ?? '';
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 15;
$offset = ($page - 1) * $limit;

// ---------- WHERE ----------
$where  = "WHERE beso_delete_status = 0";
$params = []; $types  = "";
if ($year !== "")   { $where .= " AND YEAR(created_at) = ?"; $params[] = (int)$year; $types .= "i"; }
if ($month !== "")  { $where .= " AND MONTH(created_at) = ?"; $params[] = (int)$month; $types .= "i"; }
if ($edu !== "")    { $where .= " AND COALESCE(NULLIF(TRIM(education_attainment),''),'Unspecified') = ?"; $params[] = $edu; $types .= "s"; }
if ($course !== "") { $where .= " AND COALESCE(NULLIF(TRIM(course),''),'Unspecified') = ?"; $params[] = $course; $types .= "s"; }

// ---------- Helper ----------
function run_query(mysqli $db, string $sql, string $types = "", array $params = []) {
  $stmt = $db->prepare($sql);
  if (!$stmt) return false;
  if ($types !== "" && $params) $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()) return false;
  return $stmt->get_result();
}

// ---------- KPIs ----------
$result = run_query($mysqli, "SELECT COUNT(*) AS cnt FROM beso $where", $types, $params);
$total  = (int)(($result && ($r=$result->fetch_assoc())) ? $r['cnt'] : 0);

$resDistinct = run_query($mysqli, "SELECT COUNT(DISTINCT res_id) AS d FROM beso $where", $types, $params);
$distinctResidents = (int)(($resDistinct && ($r=$resDistinct->fetch_assoc())) ? $r['d'] : 0);

$courseDistinct = run_query($mysqli, "SELECT COUNT(DISTINCT NULLIF(TRIM(course),'')) AS d FROM beso $where", $types, $params);
$distinctCourses = (int)(($courseDistinct && ($r=$courseDistinct->fetch_assoc())) ? $r['d'] : 0);

// ---------- Breakdown ----------
$eduRes = run_query(
  $mysqli,
  "SELECT COALESCE(NULLIF(TRIM(education_attainment),''),'Unspecified') AS education_attainment,
          COUNT(*) AS c
   FROM beso $where
   GROUP BY education_attainment
   ORDER BY c DESC, education_attainment ASC",
  $types, $params
);
$eduRows = $eduRes ? $eduRes->fetch_all(MYSQLI_ASSOC) : [];

$topCourseRes = run_query(
  $mysqli,
  "SELECT COALESCE(NULLIF(TRIM(course),''),'Unspecified') AS course, COUNT(*) AS c
   FROM beso $where
   GROUP BY course
   ORDER BY c DESC, course ASC
   LIMIT 10",
  $types, $params
);
$courseRows = $topCourseRes ? $topCourseRes->fetch_all(MYSQLI_ASSOC) : [];

// ---------- Main list ----------
$listSql = "SELECT b.id, b.res_id,
                   r.first_name, r.middle_name, r.last_name, r.suffix_name,
                   b.education_attainment, b.course, b.created_at
            FROM beso b
            LEFT JOIN residents r ON b.res_id = r.id
            $where
            ORDER BY b.created_at DESC ".($print ? "" : "LIMIT $limit OFFSET $offset");
$listRes = run_query($mysqli, $listSql, $types, $params);

// ---------- HTML BODY ----------
ob_start();
?>
<div class="beso-analytics">
  <div class="card">
    <h6>Overview<?= $year!==''? ' • '.htmlspecialchars($year):'' ?><?= $month!==''? ' • '.date('F', mktime(0,0,0,(int)$month,1)) : '' ?></h6>
    <div class="kpi"><div class="lbl">Total Records</div><div class="num"><?= number_format($total) ?></div></div>
  </div>
  <div class="card wide">
    <h6>Education Breakdown</h6>
    <table>
      <thead><tr><th style= "width: 1000px;">Education</th><th style= "width: 1000px;">Count</th></tr></thead>
      <tbody>
      <?php if ($eduRows): foreach ($eduRows as $er): ?>
        <tr><td><?= htmlspecialchars($er['education_attainment']) ?></td><td style="text-align:right"><?= number_format((int)$er['c']) ?></td></tr>
      <?php endforeach; else: ?>
        <tr><td colspan="2"><em>No data</em></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h6>Top Courses (Top 10)</h6>
    <table>
      <thead><tr><th style= "width: 1000px;">Course</th><th style= "width: 1000px;">Count</th></tr></thead>
      <tbody>
      <?php if ($courseRows): foreach ($courseRows as $cr): ?>
        <tr><td><?= htmlspecialchars($cr['course']) ?></td><td style="text-align:right"><?= number_format((int)$cr['c']) ?></td></tr>
      <?php endforeach; else: ?>
        <tr><td colspan="2"><em>No data</em></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<table>
  <thead><tr><th style= "width: 300px;">#</th><th style= "width: 300px;">Name</th><th style= "width: 300px;">Education</th><th style= "width: 300px;">Course</th><th style= "width: 400px;">Created At</th></tr></thead>
  <tbody>
<?php
$i = $print ? 1 : $offset + 1;
if ($listRes && $listRes->num_rows):
  while ($row = $listRes->fetch_assoc()):
    $fullName = trim(preg_replace('/\s+/', ' ', ($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? '').' '.($row['suffix_name'] ?? '')));
?>
    <tr>
      <td><?= htmlspecialchars($i++) ?></td>
      <td><?= htmlspecialchars($fullName ?: 'N/A') ?></td>
      <td><?= htmlspecialchars($row['education_attainment'] ?: 'Unspecified') ?></td>
      <td><?= htmlspecialchars($row['course'] ?: 'Unspecified') ?></td>
      <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['created_at']))) ?></td>
    </tr>
<?php endwhile; else: ?>
    <tr><td colspan="5" style="text-align:center;color:#64748b;padding:12px">No records found.</td></tr>
<?php endif; ?>
  </tbody>
  <tfoot><tr><td colspan="5">Total records: <?= number_format($total) ?></td></tr></tfoot>
</table>
<?php
$html = ob_get_clean();

// ---------- PRINT MODE ----------
if ($print) {
  $yrUi = ($year !== '' ? htmlspecialchars($year) : date('Y'));
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>BESO Report</title>
  <style>
    @page{ size:A4 landscape; margin:12mm; }
    body{ font-family:Segoe UI,Arial,sans-serif; color:#0f172a; }
    *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .header{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; margin-bottom:12px; }
    .seals{ display:flex; gap:12px; }
    .seal{ width:52px; height:52px; border-radius:50%; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
    .seal img{ width:100%; height:100%; object-fit:cover; }
    .title{text-align:center;}
    .title h1{ margin:0; font-size:20px; font-weight:800; }
    .year{ font-size:14px; }
    .box{ border:1.5px solid #cfd8e3; padding:10px; page-break-inside: avoid; }
    table{ width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; }
    thead th{ background:#f1f5f9; border:1px solid #e2e8f0; padding:6px; }
    td{ border:1px solid #e2e8f0; padding:6px; }
    tfoot td{ background:#f1f5f9; font-weight:600; }
    .footer{ display:grid; grid-template-columns:1fr 1fr 1fr; text-align:center; margin-top:20px;
             break-inside: avoid; page-break-inside: avoid; }
    .sig .lbl{ font-size:12px; margin-bottom:12px; font-weight:600; }
    .sig .name{ font-weight:700; font-size:14px; margin-bottom:2px; }
    .sig .pos{ font-size:12px; color:#475569; }
    .sigimg{ height:48px; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto; }
  </style></head><body>';
  echo '<div class="header">
          <div class="seals">'.
            ($seal1 ? '<div class="seal"><img src="'.$seal1.'" alt="Seal 1"></div>' : '').
            ($seal2 ? '<div class="seal"><img src="'.$seal2.'" alt="Seal 2"></div>' : '').
        '</div>
          <div class="title"><h1>'.htmlspecialchars($barangayName).' - BESO Report</h1></div>
          <div class="year">Year: '.$yrUi.($month !== '' ? ' • '.date('F', mktime(0,0,0,(int)$month,1)) : '').'</div>
        </div>';
  echo '<div class="box">'.$html.'</div>';
  echo '<div class="footer">
          <div class="sig">
            <div class="lbl">Prepared by:</div>
            <div class="name">'.htmlspecialchars($preparedBy).'</div>
            <div class="pos">'.htmlspecialchars($preparedPos).'</div>
          </div>
          <div class="sig">
            <div class="lbl">Noted by:</div>'.
            ($notedSig ? '<img class="sigimg" src="'.$notedSig.'" alt="Noted signature">' : '').'
            <div class="name">'.htmlspecialchars($notedBy).'</div>
            <div class="pos">Barangay Secretary</div>
          </div>
          <div class="sig">
            <div class="lbl">Attested by:</div>'.
            ($attestedSig ? '<img class="sigimg" src="'.$attestedSig.'" alt="Attested signature">' : '').'
            <div class="name">'.htmlspecialchars($attestedBy).'</div>
            <div class="pos">Punong Barangay</div>
          </div>
        </div>';
  echo '</body></html>';
  exit;
}

// ---------- NORMAL (AJAX) OUTPUT ----------
echo $html;
