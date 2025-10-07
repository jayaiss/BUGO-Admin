<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include '../class/session_timeout.php';

/* ---------- Role gate ---------- */
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin','Barangay Secretary','Punong Barangay','Revenue Staff'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

/* ---------- Filters (Month + Year) ---------- */
$currentYear = (int)date('Y');
$yearFilter  = (isset($_GET['year']) && ctype_digit($_GET['year'])) ? (int)$_GET['year'] : $currentYear;
$monthFilter = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0; // 0 = All
if ($monthFilter < 0 || $monthFilter > 12) $monthFilter = 0;

$conds = ["COALESCE(feedback_delete_status,0)=0", "YEAR(created_at)={$yearFilter}"];
if ($monthFilter !== 0) $conds[] = "MONTH(created_at)={$monthFilter}";
$where = "WHERE " . implode(" AND ", $conds);

/* ---------- Pagination ---------- */
$limit = 10;
$page  = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$start = ($page - 1) * $limit;

/* ---------- Count + Page data ---------- */
$total_query   = $mysqli->query("SELECT COUNT(*) AS total FROM feedback {$where}");
$total_row     = $total_query ? $total_query->fetch_assoc() : ['total'=>0];
$total_records = (int)$total_row['total'];
$total_pages   = max(1, (int)ceil($total_records / $limit));

$list_sql = "SELECT feedback_text FROM feedback {$where} ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $mysqli->prepare($list_sql);
$stmt->bind_param('ii', $start, $limit);
$stmt->execute();
$result = $stmt->get_result();

/* ---------- Print flag ---------- */
$print = isset($_GET['print']) && $_GET['print'] == '1';

/* ---------- Branding (logos + signatories) for PRINT ---------- */
$barangayName = 'LGU Barangay Bugo';

function logo_to_data_uri(?string $blob): ?string {
  if (!$blob) return null;
  $info = @getimagesizefromstring($blob);
  $mime = is_array($info) && !empty($info['mime']) ? $info['mime'] : 'image/png';
  return 'data:'.$mime.';base64,'.base64_encode($blob);
}
function fetch_first_two_active_logos(mysqli $db): array {
  $rs = $db->query("SELECT logo_image FROM logos WHERE status='active' ORDER BY id ASC LIMIT 2");
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
          $first=$middle=$last=$suffix=null;
          $stmt2->bind_result($first,$middle,$last,$suffix);
          if ($stmt2->fetch()) $name = trim(preg_replace('/\s+/', ' ', ($first??'').' '.($middle??'').' '.($last??'').' '.($suffix??'')));
        }
        $stmt2->close();
      }
    }
  }
  $stmt1->free_result();
  $stmt1->close();
  return ['name'=>$name,'sig'=>$sig];
}

/* ---------- CURRENT USER: employee_list + role names (employee_roles) ---------- */
function current_employee_with_roles(mysqli $db): array {
  $name = null; $sig = null; $roles = null;

  // Gather possible employee ids from session
  $empId = $_SESSION['employee_id'] ?? $_SESSION['emp_id'] ?? $_SESSION['Employee_Id'] ?? $_SESSION['id'] ?? null;

  // Pull name + signature by employee_id
  if ($empId && is_numeric($empId)) {
    if ($st = $db->prepare("
      SELECT employee_fname, employee_mname, employee_lname, esignature, esignature_mime
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

    // Pull role names (may be multiple)
    if ($st = $db->prepare("
      SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ') AS role_names
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

  // Fallback by username if still missing
  if (!$name) {
    $uname = $_SESSION['employee_username'] ?? $_SESSION['username'] ?? null;
    if ($uname && $st = $db->prepare("
      SELECT employee_id, employee_fname, employee_mname, employee_lname, esignature, esignature_mime
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
          // roles by found id
          if ($id && ($st2 = $db->prepare("
            SELECT GROUP_CONCAT(Role_Name ORDER BY Role_Id SEPARATOR ', ') AS role_names
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

  // Final fallbacks
  if (!$name) {
    $cand = [
      $_SESSION['Full_Name'] ?? null,
      $_SESSION['full_name'] ?? null,
      trim(($_SESSION['first_name'] ?? '').' '.($_SESSION['last_name'] ?? '')) ?: null,
    ];
    foreach ($cand as $v) { if ($v && trim($v)!=='') { $name = trim($v); break; } }
  }

  if (!$roles) {
    // At least show the gate role if available
    $roles = $_SESSION['Role_Name'] ?? null;
  }

  return ['name'=>$name, 'sig'=>$sig, 'roles'=>$roles];
}

/* ---------- Signatories ---------- */
$sgBeso = fetch_signatory_separate($mysqli, 'Revenue Staff');
$sgSec  = fetch_signatory_separate($mysqli, 'Barangay Secretary');
$sgPB   = fetch_signatory_separate($mysqli, 'Punong Barangay');

/* ---------- Prepared/Noted/Attested (Prepared by = CURRENT USER) ---------- */
$cu = current_employee_with_roles($mysqli);
$preparedBy  = $cu['name'] ?: ($sgBeso['name'] ?: 'Revenue Staff');
$preparedSig = $cu['sig']  ?? ($sgBeso['sig'] ?? null);
$preparedPos = $cu['roles'] ?: ''; // <-- role name(s) to display under name

$notedBy    = $sgSec['name']  ?: 'Barangay Secretary';
$attestedBy = $sgPB['name']   ?: 'Punong Barangay';

$notedSig    = $sgSec['sig'] ?? null;
$attestedSig = $sgPB['sig']  ?? null;

/* ---------- Build content area (reused in print) ---------- */
$monthName = $monthFilter === 0 ? 'All Months' : date('F', mktime(0,0,0,$monthFilter,1));
$self = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);

ob_start();
?>
<style>
  @media print { .d-print-none { display:none!important; } }
</style>

<!-- Filters (hidden in print) -->

<div class="table-responsive">
  <table class="table table-bordered table-striped table-hover">
    <thead class="table-primary">
      <tr><th style= "width: 1700px;">Feedback</th></tr>
    </thead>
    <tbody>
      <?php if ($result && $result->num_rows): while($row=$result->fetch_assoc()): ?>
        <tr><td><?=htmlspecialchars($row['feedback_text'])?></td></tr>
      <?php endwhile; else: ?>
        <tr><td class="text-center text-muted">No feedback found</td></tr>
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
          <a class="page-link" href="<?=$self?>?page=<?=$i?>&year=<?=$yearFilter?>&month=<?=$monthFilter?>"><?=$i?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
</div>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();

/* ---------- PRINT WINDOW (landscape; add table borders) ---------- */
if ($print) {
  $yrUi = htmlspecialchars((string)$yearFilter);
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Feedback Report</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    body { font-family: Segoe UI, Arial, sans-serif; color:#0f172a; }
    @media print{ html, body { width:297mm; height:210mm; }

    }
    .header{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; margin-bottom:10px; }
    .seals{ display:flex; gap:12px; }
    .seal{ width:52px; height:52px; border-radius:50%; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
    .seal img{ width:100%; height:100%; object-fit:cover; }
    .title{text-align:center;}
    .title h1{ margin:0; font-size:20px; font-weight:800; }
    .year{ font-size:14px; }
    .box{ border:1.5px solid #cfd8e3; padding:10px; page-break-inside:auto; }
    tr, img { break-inside: avoid; page-break-inside: avoid; }
    .box table{ width:100%; border-collapse:collapse; margin-top:6px; }
    .box th, .box td{ border:1px solid #cbd5e1; padding:8px 10px; font-size:13.5px; text-align:left; }
    .box thead th{ background:#0d6efd; color:#fff; text-align:center; font-weight:700; }
    .box tbody tr:nth-child(even) td{ background:#f8fafc; }
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

  // Header
  echo '<div class="header">
          <div class="seals">'.
            ($seal1 ? '<div class="seal"><img src="'.$seal1.'" alt="Seal 1"></div>' : '').
            ($seal2 ? '<div class="seal"><img src="'.$seal2.'" alt="Seal 2"></div>' : '').
        '</div>
          <div class="title"><h1>'.htmlspecialchars($barangayName).' - Feedback Report</h1></div>
          <div class="year">Year: '.$yrUi.'<br>Month: '.htmlspecialchars($monthName).'</div>
        </div>';

  // Content
  echo '<div class="box">'.$contentHtml.'</div>';

  // Footer
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

/* ---------- NORMAL OUTPUT ---------- */
echo $contentHtml;
