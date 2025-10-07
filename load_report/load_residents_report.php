<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include '../class/session_timeout.php';

/* ---------- Role gate ---------- */
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin','Barangay Secretary','Punong Barangay','Encoder'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

/* ---------- Print flag ---------- */
$print = (isset($_GET['print']) && $_GET['print'] == '1');

/* ---------- Filters (with AGE RANGE support) ---------- */
$gender     = isset($_GET['gender'])   && $_GET['gender']   !== '' ? $_GET['gender']   : null;
$res_zone   = isset($_GET['res_zone']) && $_GET['res_zone'] !== '' ? $_GET['res_zone'] : null;

// Back-compat exact age
$age_exact  = isset($_GET['age'])      && $_GET['age']      !== '' ? (int)$_GET['age'] : null;
$age_min_in = isset($_GET['age_min'])  && $_GET['age_min']  !== '' ? (int)$_GET['age_min'] : null;
$age_max_in = isset($_GET['age_max'])  && $_GET['age_max']  !== '' ? (int)$_GET['age_max'] : null;

if ($age_exact !== null) { $age_min = $age_exact; $age_max = $age_exact; }
else { $age_min = $age_min_in; $age_max = $age_max_in; }

/* ---------- Pagination (screen only) ---------- */
$limit  = 10;
$page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

/* ---------- WHERE builders ---------- */
$base_where = " WHERE resident_delete_status = 0";
$params = []; $types = "";

$addCond = function(string $cond, array $p = [], string $t = "") use (&$base_where, &$params, &$types) {
  $base_where .= " AND " . $cond;
  foreach ($p as $v) { $params[] = $v; }
  $types .= $t;
};

if ($gender !== null)   { $addCond("gender = ?",     [$gender],  "s"); }
if ($res_zone !== null) { $addCond("res_zone = ?",   [$res_zone], "s"); }

if ($age_min !== null && $age_max !== null) {
  $addCond("age BETWEEN ? AND ?", [$age_min, $age_max], "ii");
} elseif ($age_min !== null) {
  $addCond("age >= ?", [$age_min], "i");
} elseif ($age_max !== null) {
  $addCond("age <= ?", [$age_max], "i");
}

/* male/female cards: exclude gender filter but keep zone + age range */
$base_where_no_gender = " WHERE resident_delete_status = 0";
$params_no_gender = []; $types_no_gender = "";
if ($res_zone !== null) { $base_where_no_gender .= " AND res_zone = ?"; $params_no_gender[] = $res_zone; $types_no_gender .= "s"; }
if ($age_min !== null && $age_max !== null) {
  $base_where_no_gender .= " AND age BETWEEN ? AND ?"; $params_no_gender[] = $age_min; $params_no_gender[] = $age_max; $types_no_gender .= "ii";
} elseif ($age_min !== null) {
  $base_where_no_gender .= " AND age >= ?"; $params_no_gender[] = $age_min; $types_no_gender .= "i";
} elseif ($age_max !== null) {
  $base_where_no_gender .= " AND age <= ?"; $params_no_gender[] = $age_max; $types_no_gender .= "i";
}

/* age groups: exclude age-range filter but keep gender + zone */
$base_where_no_age = " WHERE resident_delete_status = 0";
$params_no_age = []; $types_no_age = "";
if ($gender !== null)   { $base_where_no_age .= " AND gender = ?";   $params_no_age[] = $gender;   $types_no_age .= "s"; }
if ($res_zone !== null) { $base_where_no_age .= " AND res_zone = ?"; $params_no_age[] = $res_zone; $types_no_age .= "s"; }

/* ---------- Queries ---------- */
$total_sql = "SELECT COUNT(*) AS total FROM residents" . $base_where;
$stmt = $mysqli->prepare($total_sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total_res = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$male_sql = "SELECT COUNT(*) AS c FROM residents" . $base_where_no_gender . " AND gender='Male'";
$stmt = $mysqli->prepare($male_sql);
if (!empty($params_no_gender)) $stmt->bind_param($types_no_gender, ...$params_no_gender);
$stmt->execute();
$male_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$female_sql = "SELECT COUNT(*) AS c FROM residents" . $base_where_no_gender . " AND gender='Female'";
$stmt = $mysqli->prepare($female_sql);
if (!empty($params_no_gender)) $stmt->bind_param($types_no_gender, ...$params_no_gender);
$stmt->execute();
$female_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* age group breakdown (respect gender+zone, ignore age-range filter) */
$age_groups = [
  '0-10' => [0,10], '11-19'=>[11,19], '20-29'=>[20,29],
  '30-39'=>[30,39], '40-49'=>[40,49], '50-59'=>[50,59], '60+'=>[60,200],
];
$age_group_counts = [];
foreach ($age_groups as $label => [$minA,$maxA]) {
  $sql = "SELECT COUNT(*) AS cnt FROM residents".$base_where_no_age." AND age BETWEEN ? AND ?";
  $stmt = $mysqli->prepare($sql);
  $p = [...$params_no_age, $minA, $maxA];
  $t = $types_no_age . "ii";
  if (!empty($p)) $stmt->bind_param($t, ...$p);
  $stmt->execute();
  $age_group_counts[$label] = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
  $stmt->close();
}

/* zone distribution (respect full filter) */
$zone_sql = "SELECT res_zone, COUNT(*) AS cnt FROM residents".$base_where." GROUP BY res_zone ORDER BY cnt DESC";
$stmt = $mysqli->prepare($zone_sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$zone_res = $stmt->get_result();
$zones = [];
while ($z = $zone_res->fetch_assoc()) $zones[] = $z;
$stmt->close();

/* pagination count (screen only) */
$total_pages = max(1, (int)ceil($total_res / $limit));

/* data query (disable LIMIT in print mode) */
$data_sql = "SELECT id, first_name, middle_name, last_name, suffix_name, age, gender, res_zone
             FROM residents" . $base_where . " ORDER BY last_name ASC" . ($print ? "" : " LIMIT ?, ?");

$data_stmt = $mysqli->prepare($data_sql);

if ($print) {
  if (!empty($params)) {
    $data_stmt->bind_param($types, ...$params);
  }
} else {
  if (!empty($params)) {
    $types_with_limits  = $types . "ii";
    $params_with_limits = [...$params, $offset, $limit];
    $data_stmt->bind_param($types_with_limits, ...$params_with_limits);
  } else {
    $data_stmt->bind_param("ii", $offset, $limit);
  }
}
$data_stmt->execute();
$result = $data_stmt->get_result();

/* ---------- Header helpers ---------- */
$generated_at = date('Y-m-d H:i');
$ResidentsHeading = $print
  ? "Residents (Total " . number_format($total_res) . ")"
  : "Residents (Page {$page} of {$total_pages})";

/* ---------- Signature + identity helpers ---------- */
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

/* current employee (name + roles from employee_roles, signature from employee_list) */
function current_employee_with_roles(mysqli $db): array {
  $name = null; $sig = null; $roles = null;

  // common id keys in session
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

  // fallback via username
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
          if ($id && ($st2 = $db->prepare("SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ') AS role_names
                                           FROM employee_roles
                                           WHERE Employee_Id=? AND Role_Name IS NOT NULL AND Role_Name<>''"))) {
            $st2->bind_param('i', $id);
            if ($st2->execute()) {
              $st2->store_result();
              $rn=null; $st2->bind_result($rn);
              if ($st2->fetch()) $roles = $rn ?: null;
            }
            $st2->close();
          }
        }
      }
      $st->close();
    }
  }

  // final fallbacks
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

/* barangay signatory by position (name & signature) */
function fetch_signatory_separate(mysqli $db, string $pos): array {
  $stmt1 = $db->prepare("SELECT bi.official_id, bi.esignature, bi.esignature_mime
                         FROM barangay_information bi
                         WHERE LOWER(bi.`position`)=LOWER(?) AND bi.`status`='active'
                         ORDER BY bi.id DESC LIMIT 1");
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
      $stmt2 = $db->prepare("SELECT first_name, middle_name, last_name, suffix_name FROM residents WHERE id=? LIMIT 1");
      if ($stmt2) {
        $stmt2->bind_param('i', $official_id);
        if ($stmt2->execute()) {
          $stmt2->store_result();
          $f=$m=$l=$s=null;
          $stmt2->bind_result($f,$m,$l,$s);
          if ($stmt2->fetch()) $name = trim(preg_replace('/\s+/', ' ', ($f??'').' '.($m??'').' '.($l??'').' '.($s??'')));
        }
        $stmt2->close();
      }
    }
  }
  $stmt1->free_result();
  $stmt1->close();
  return ['name'=>$name,'sig'=>$sig];
}

/* build signatory set */
$cu = current_employee_with_roles($mysqli);
$preparedBy  = $cu['name'] ?: 'Encoder';
$preparedSig = $cu['sig']  ?? null;
$preparedPos = $cu['roles'] ?: 'Encoder';

$sgSec  = fetch_signatory_separate($mysqli, 'Barangay Secretary');
$sgPB   = fetch_signatory_separate($mysqli, 'Punong Barangay');
$notedBy    = $sgSec['name']  ?: 'Barangay Secretary';
$attestedBy = $sgPB['name']   ?: 'Punong Barangay';
$notedSig    = $sgSec['sig'] ?? null;
$attestedSig = $sgPB['sig']  ?? null;

/* ---------- HTML Body ---------- */
ob_start();
?>
<style>
.res-report .analytics {
  display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
  gap:1rem; margin-bottom:1rem;
}
.res-report .card-analytic {
  background:#fff; border:1px solid #dee2e6; border-radius:.6rem;
  padding:1rem; box-shadow:0 2px 6px rgba(0,0,0,.05);
}
.res-report .card-analytic h6 { margin:0 0 .35rem 0; font-size:.9rem; color:#6c757d; font-weight:600; }
.res-report .card-analytic p  { margin:0; font-size:1.35rem; font-weight:700; color:#0d6efd; }
.res-report .section { border:1px solid #e9ecef; border-radius:.6rem; padding:.75rem; background:#fff; margin-bottom:1rem; }
.res-report .section h6 { margin:0 0 .5rem 0; color:#0d6efd; font-weight:700; }

/* table dividers */
.res-report table { width:100%; border-collapse:collapse; }
.res-report thead th { border:1px solid #cfd8e3; }
.res-report tbody td { border:1px solid #cfd8e3; }

/* keep rows together nicely when printing */
table { page-break-inside:auto; }
tr    { page-break-inside:avoid; page-break-after:auto; }
.print-hide { display:none !important; } /* hidden in print */
</style>
<!--<link rel="stylesheet" href="css/report/report.css">-->
<div class="res-report">
  <!-- KPI Cards -->
  <div class="analytics">
    <div class="card-analytic"><h6>Total Residents</h6><p><?= number_format($total_res) ?></p></div>
    <div class="card-analytic"><h6>Male</h6><p><?= number_format($male_count) ?></p></div>
    <div class="card-analytic"><h6>Female</h6><p><?= number_format($female_count) ?></p></div>
  </div>

  <!-- Age Groups (hidden on print) -->
  <div class="section print-hide">
    <h6>Age Group Distribution</h6>
    <table class="table table-sm table-bordered mb-0">
      <thead class="table-light">
        <tr><th>Age Range</th><th style="width:120px; text-align:right;">Count</th></tr>
      </thead>
      <tbody>
        <?php foreach($age_group_counts as $label=>$cnt): ?>
          <tr><td><?= htmlspecialchars($label) ?></td><td style="text-align:right;"><?= number_format($cnt) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Zones (hidden on print) -->
  <div class="section print-hide">
    <h6>Residents per Zone</h6>
    <table class="table table-sm table-bordered mb-0">
      <thead class="table-light">
        <tr><th>Zone</th><th style="width:120px; text-align:right;">Count</th></tr>
      </thead>
      <tbody>
        <?php if (!empty($zones)): ?>
          <?php foreach($zones as $z): ?>
            <tr><td><?= htmlspecialchars($z['res_zone']) ?></td><td style="text-align:right;"><?= number_format($z['cnt']) ?></td></tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Residents Table -->
  <div class="section">
    <table class="table table-bordered table-striped table-hover" style="page-break-inside:auto;">
      <thead class="table-primary">
        <tr>
          <th style="width: 220px;">Last Name</th>
          <th style="width: 220px;">First Name</th>
          <th style="width: 220px;">Middle Name</th>
          <th style="width: 220px;">Suffix</th>
          <th style="width: 220px;">Age</th>
          <th style="width: 220px;">Gender</th>
          <th style="width: 220px;">Zone</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
          <tr style="page-break-inside:avoid;">
            <td><?= htmlspecialchars($row['last_name']) ?></td>
            <td><?= htmlspecialchars($row['first_name']) ?></td>
            <td><?= htmlspecialchars($row['middle_name']) ?></td>
            <td><?= htmlspecialchars($row['suffix_name']) ?></td>
            <td><?= htmlspecialchars($row['age']) ?></td>
            <td><?= htmlspecialchars($row['gender']) ?></td>
            <td><?= htmlspecialchars($row['res_zone']) ?></td>
          </tr>
        <?php endwhile; ?>
        <?php if ($result->num_rows === 0): ?>
          <tr><td colspan="7" class="text-center text-muted">No residents found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$html = ob_get_clean();

/* ---------- PRINT FORMAT ---------- */
if ($print) {
  $barangayName = "LGU Barangay Bugo";
  $reportTitle  = "Residents Report";
  $yearLabel    = date('Y');

  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$reportTitle.'</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    html, body { font-family: Segoe UI, Arial, sans-serif; color:#0f172a; }
    body { margin:0; }
    .header{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; margin:0 0 10px 0; }
    .seals{ display:flex; gap:12px; }
    .seal{ width:52px; height:52px; border-radius:50%; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
    .seal img{ width:100%; height:100%; object-fit:cover; }
    .title{text-align:center;}
    .title h1{ margin:0; font-size:20px; font-weight:800; }
    .year{ font-size:14px; justify-self:end; }
    .box{ border:1.5px solid #cfd8e3; padding:10px; page-break-inside:auto; }

    tr, img, .card-analytic { break-inside: avoid; page-break-inside: avoid; }
    .footer{ display:grid; grid-template-columns:1fr 1fr 1fr; text-align:center; margin-top:12px; }
    .sig .lbl{ font-size:12px; margin-bottom:8px; font-weight:600; }
    .sig .name{ font-weight:700; font-size:14px; margin-bottom:2px; }
    .sig .pos{ font-size:12px; color:#475569; }
    .sigimg{ max-height:56px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto; }

    /* table dividers (print) */
    .box table { width:100%; border-collapse:collapse; }
    .box thead th { border:1px solid #cfd8e3; }
    .box tbody td { border:1px solid #cfd8e3; }
  </style></head><body>';

  // Header
  echo '<div class="header">
          <div class="seals">
            <div class="seal"><img src="/assets/logo/cdo.png" alt="Seal 1"></div>
            <div class="seal"><img src="/assets/logo/logo.png" alt="Seal 2"></div>
          </div>
          <div class="title"><h1>'.htmlspecialchars($barangayName).' - '.$reportTitle.'</h1></div>
          <div class="year">Year: '.$yearLabel.'</div>
        </div>';

  // Content
  echo '<div class="box">'.$html.'</div>';

  // Footer (dynamic)
  echo '<div class="footer">
          <div class="sig">
            <div class="lbl">Prepared by:</div>'.
            // ($preparedSig ? '<img class="sigimg" src="'.$preparedSig.'" alt="Prepared signature">' : '').
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

/* ---------- NORMAL OUTPUT ---------- */
echo $html;
