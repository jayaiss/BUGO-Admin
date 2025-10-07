<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/* --- ensure session is available for AJAX --- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include '../class/session_timeout.php';

/* ---------- Role gate (normalized + fixed 403 path) ---------- */
$role     = isset($_SESSION['Role_Name']) ? trim($_SESSION['Role_Name']) : '';
$roleNorm = strtolower($role);
$allowed  = ['admin','barangay secretary','punong barangay','revenue staff'];
if (!in_array($roleNorm, $allowed, true)) {
  http_response_code(403);
  header('Content-Type: text/html; charset=UTF-8');
  require_once __DIR__ . '/../security/403.html';
  exit;
}

/* ---------- Pagination ---------- */
$limit = 10;
$page  = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$start = ($page - 1) * $limit;

/* ---------- Filters from GET ---------- */
$title    = isset($_GET['title'])    ? trim($_GET['title'])    : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$month    = isset($_GET['month'])    ? trim($_GET['month'])    : '';
$year     = isset($_GET['year'])     ? trim($_GET['year'])     : '';
$print    = isset($_GET['print'])    && $_GET['print'] == '1';

$conds  = [];
$types  = '';
$params = [];

/* Title: filter by event_name first, fallback to raw FK text */
if ($title !== '') {
  $conds[] = "(en.event_name = ? OR CAST(e.event_title AS CHAR) = ?)";
  $types  .= 'ss';
  $params[] = $title;
  $params[] = $title;
}

if ($location !== '') {
  $conds[] = "e.event_location = ?";
  $types  .= 's';
  $params[] = $location;
}
if ($month !== '') {
  if (preg_match('/^\d{1,2}$/', $month)) $month = str_pad($month, 2, '0', STR_PAD_LEFT);
  $conds[] = "DATE_FORMAT(e.event_date, '%m') = ?";
  $types  .= 's';
  $params[] = $month;
}
if ($year !== '') {
  $conds[] = "YEAR(e.event_date) = ?";
  $types  .= 'i';
  $params[] = (int)$year;
}

$whereSql = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';

/* ---------- Count (filtered) ---------- */
$countSql = "SELECT COUNT(*) AS total
             FROM events e
             LEFT JOIN event_name en ON en.id = e.event_title
             $whereSql";
$total_row = ['total'=>0];
if ($stc = $mysqli->prepare($countSql)) {
  if ($types !== '') $stc->bind_param($types, ...$params);
  if ($stc->execute()) {
    if ($rs = $stc->get_result()) { $total_row = $rs->fetch_assoc() ?: ['total'=>0]; $rs->free(); }
  }
  $stc->close();
}
$total_records = (int)$total_row['total'];
$total_pages   = max(1, (int)ceil($total_records / $limit));

/* ---------- Fetch page (filtered) ---------- */
$query  = "SELECT 
             COALESCE(en.event_name, CONCAT('ID#', e.event_title)) AS event_title,
             e.event_description,
             e.event_location,
             e.event_date,
             e.event_time,
             e.event_end_time
           FROM events e
           LEFT JOIN event_name en ON en.id = e.event_title
           $whereSql
           ORDER BY e.event_date DESC, e.event_time DESC
           LIMIT ?, ?";
$bindTypes  = $types . 'ii';
$bindParams = $params;
$bindParams[] = $start;
$bindParams[] = $limit;

$stmt = $mysqli->prepare($query);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$result = $stmt->get_result();

/* ---------- Print flag ---------- */
$barangayName = 'LGU Barangay Bugo';

/* ---------- Helpers for branding/signatories ---------- */
function logo_to_data_uri(?string $blob): ?string {
  if (!$blob) return null;
  $info = @getimagesizefromstring($blob);
  $mime = is_array($info) && !empty($info['mime']) ? $info['mime'] : 'image/png';
  return 'data:'.$mime.';base64,'.base64_encode($blob);
}
function normalize_signature_data_uri(?string $data, ?string $mimeHint='image/png'): ?string {
  if (!$data) return null;
  if (str_starts_with($data, 'data:')) return $data;
  $info = @getimagesizefromstring($data);
  if (is_array($info) && !empty($info['mime'])) {
    return 'data:'.$info['mime'].';base64,'.base64_encode($data);
  }
  $b64  = preg_replace('/\s+/', '', $data);
  $mime = $mimeHint ?: 'image/png';
  return 'data:'.$mime.';base64,'.$b64;
}
function fetch_first_two_active_logos(mysqli $db): array {
  $rs = $db->query("SELECT logo_image FROM logos WHERE status='active' ORDER BY id ASC LIMIT 2");
  $out = [];
  if ($rs) while ($r = $rs->fetch_assoc()) $out[] = logo_to_data_uri($r['logo_image'] ?? null);
  return $out;
}
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
      $stmt2 = $db->prepare("SELECT first_name, middle_name, last_name, suffix_name
                             FROM residents WHERE id=? LIMIT 1");
      if ($stmt2) {
        $stmt2->bind_param('i', $official_id);
        if ($stmt2->execute()) {
          $stmt2->store_result();
          $first=$middle=$last=$suffix=null;
          $stmt2->bind_result($first,$middle,$last,$suffix);
          if ($stmt2->fetch()) {
            $name = trim(preg_replace('/\s+/', ' ', ($first??'').' '.($middle??'').' '.($last??'').' '.($suffix??'')));
          }
        }
        $stmt2->close();
      }
    }
  }
  $stmt1->free_result();
  $stmt1->close();
  return ['name'=>$name,'sig'=>$sig];
}
function current_employee_with_roles(mysqli $db): array {
  $name=null; $sig=null; $roles=null;
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
                            FROM employee_roles WHERE Employee_Id=? AND Role_Name<>''")) {
      $st->bind_param('i', $empId);
      if ($st->execute()) { $st->store_result(); $rn=null; $st->bind_result($rn); if ($st->fetch()) $roles=$rn?:null; }
      $st->close();
    }
  }
  if (!$name) {
    $cand = [
      $_SESSION['Full_Name'] ?? null,
      $_SESSION['full_name'] ?? null,
      trim(($_SESSION['first_name'] ?? '').' '.($_SESSION['last_name'] ?? '')) ?: null,
    ];
    foreach ($cand as $v) { if ($v && trim($v)!=='') { $name=trim($v); break; } }
  }
  if (!$roles) $roles = $_SESSION['Role_Name'] ?? null;
  return ['name'=>$name,'sig'=>$sig,'roles'=>$roles];
}

$logos = fetch_first_two_active_logos($mysqli);
$seal1 = $logos[0] ?? null;
$seal2 = $logos[1] ?? null;

$sgSec  = fetch_signatory_separate($mysqli, 'Barangay Secretary');
$sgPB   = fetch_signatory_separate($mysqli, 'Punong Barangay');
$cu     = current_employee_with_roles($mysqli);

$preparedBy  = $cu['name'] ?: 'Revenue Staff';
$preparedPos = $cu['roles'] ?: '';
$notedBy     = $sgSec['name'] ?: 'Barangay Secretary';
$attestedBy  = $sgPB['name'] ?: 'Punong Barangay';
$notedSig    = $sgSec['sig'] ?? null;
$attestedSig = $sgPB['sig'] ?? null;

/* ---------- Build standard (screen) table ---------- */
ob_start();
?>
<style>
@media print { .d-print-none { display:none !important; } }
</style>

<h3 class="text-primary mb-3">Events Report</h3>

<div class="table-responsive">
  <table class="table table-bordered table-striped table-hover">
    <thead class="table-primary">
      <tr>
        <th>Event Title</th>
        <th>Description</th>
        <th>Date</th>
        <th>Time</th>
        <th>Location</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($result && $result->num_rows): while ($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?=htmlspecialchars($row['event_title'])?></td>
        <td><?=htmlspecialchars($row['event_description'])?></td>
        <td><?=htmlspecialchars($row['event_date'])?></td>
        <td>
          <?php
            $t1 = $row['event_time'] ?? '';
            $t2 = $row['event_end_time'] ?? '';
            echo htmlspecialchars(trim($t1.($t2 ? ' - '.$t2 : '')));
          ?>
        </td>
        <td><?=htmlspecialchars($row['event_location'])?></td>
      </tr>
    <?php endwhile; else: ?>
      <tr><td colspan="5" class="text-center text-muted">No events found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="mt-3 d-print-none">
  <nav>
    <ul class="pagination">
      <?php for ($i=1;$i<=$total_pages;$i++): $active = ($i==$page)?'active':''; ?>
        <li class="page-item <?=$active?>">
          <a class="page-link pagination-link" href="#" data-page="<?=$i?>"><?=$i?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
</div>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();

/* ---------- Print layout (A4 landscape, branded) ---------- */
if ($print) {
  $yearLabel = $year !== '' ? $year : date('Y');
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Events Report</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    body { font-family: Segoe UI, Arial, sans-serif; color:#0f172a; }
    .header{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; margin-bottom:10px; }
    .seals{ display:flex; gap:12px; }
    .seal{ width:52px; height:52px; border-radius:50%; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
    .seal img{ width:100%; height:100%; object-fit:cover; }
    .title{text-align:center;}
    .title h1{ margin:0; font-size:20px; font-weight:800; }
    .year{ font-size:14px; text-align:right; }
    .box{ border:1.5px solid #cfd8e3; padding:10px; }
    tr, img { break-inside: avoid; page-break-inside: avoid; }
    .box table{ width:100%; border-collapse:collapse; margin-top:6px; }
    .box th, .box td{ border:1px solid #cbd5e1; padding:8px 10px; font-size:13.5px; text-align:left; }
    .box thead th{ background:#0d6efd; color:#fff; text-align:center; font-weight:700; }
    .box tbody tr:nth-child(even) td{ background:#f8fafc; }
    .footer{
      display:grid; grid-template-columns:1fr 1fr 1fr; text-align:center; margin-top:20px;
      break-inside: avoid; page-break-inside: avoid;
    }
    .sig .lbl{ font-size:12px; margin-bottom:10px; font-weight:600; }
    .sig .name{ font-weight:700; font-size:14px; margin-bottom:2px; }
    .sig .pos{ font-size:12px; color:#475569; }
    .sigimg{ max-height:56px; width:auto; margin-bottom:4px; display:block; margin-left:auto; margin-right:auto; }
  </style></head><body>';

  // Header
  $logos = fetch_first_two_active_logos($mysqli);
  $seal1 = $logos[0] ?? null;
  $seal2 = $logos[1] ?? null;
  echo '<div class="header">
          <div class="seals">'.
            ($seal1 ? '<div class="seal"><img src="'.$seal1.'" alt="Seal 1"></div>' : '').
            ($seal2 ? '<div class="seal"><img src="'.$seal2.'" alt="Seal 2"></div>' : '').
        '</div>
          <div class="title"><h1>'.htmlspecialchars($barangayName).' - Event Report</h1></div>
          <div class="year">Year: '.htmlspecialchars($yearLabel).'</div>
        </div>';

  // Content box with table (filtered, no pagination for print)
  echo '<div class="box">
          <table>
            <thead>
              <tr>
                <th>Event Title</th>
                <th>Description</th>
                <th>Date</th>
                <th>Time</th>
                <th>Location</th>
              </tr>
            </thead>
            <tbody>';

  $sqlPrint = "SELECT 
                 COALESCE(en.event_name, CONCAT('ID#', e.event_title)) AS event_title,
                 e.event_description,
                 e.event_location,
                 e.event_date,
                 e.event_time,
                 e.event_end_time
               FROM events e
               LEFT JOIN event_name en ON en.id = e.event_title
               $whereSql
               ORDER BY e.event_date DESC, e.event_time DESC";

  $all = null;
  if ($ps = $mysqli->prepare($sqlPrint)) {
    if ($types !== '') $ps->bind_param($types, ...$params);
    $ps->execute();
    $all = $ps->get_result();
  }

  if ($all && $all->num_rows) {
    while ($r = $all->fetch_assoc()) {
      $time = trim(($r['event_time'] ?? '').(empty($r['event_end_time']) ? '' : ' - '.$r['event_end_time']));
      echo '<tr>
              <td>'.htmlspecialchars($r['event_title']).'</td>
              <td>'.htmlspecialchars($r['event_description']).'</td>
              <td>'.htmlspecialchars($r['event_date']).'</td>
              <td>'.htmlspecialchars($time).'</td>
              <td>'.htmlspecialchars($r['event_location']).'</td>
            </tr>';
    }
  } else {
    echo '<tr><td colspan="5" style="text-align:center;color:#64748b;">No events found</td></tr>';
  }
  echo     '</tbody>
          </table>
        </div>';

  // Footer (Prepared / Noted / Attested)
  echo '<div class="footer">
          <div class="sig">
            <div class="lbl">Prepared by:</div>
            <div class="name">'.htmlspecialchars($preparedBy).'</div>
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

/* ---------- Normal (screen) output ---------- */
echo $contentHtml;
