<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

$role = $_SESSION['Role_Name'] ?? '';

if ($role !== 'Lupon' && $role !== 'Admin' && $role !== "Barangay Secretary" && $role !== "Punong Barangay" && $role !== "Revenue Staff") {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

$isPrint = isset($_GET['print']) && $_GET['print'] == '1';

if ($isPrint) {
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Cases Report</title>
  <link rel="icon" type="image/png" href="/bugo-resident-side/assets/logo/logo.png">
  <style>
  @page {
    size: landscape;
    margin: 1cm;
  }

  body {
    font-family: Arial, sans-serif;
    font-size: 12px;
    margin: 0;
    padding: 0;
    background-color: white !important;
    color: black !important;
    max-width: 100%;
    overflow-wrap: break-word;
  }

  .print-only { display: none; }

  @media print {
    .print-only { display: block; }

    .print-footer {
      page-break-inside: avoid;
      break-inside: avoid;
      margin-top: 20px;
      font-size: 11px;
    }
  }

  table, th, td {
    border: 1px solid black;
    border-collapse: collapse;
    padding: 3px;
    text-align: center;
  }
</style>

  </head><body
   onload="window.print()">';
}

$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

$filters = [];

if (!empty($month)) {
  $filters[] = "MONTH(c.date_filed) = '".$mysqli->real_escape_string($month)."'";
}
if (!empty($year)) {
  $filters[] = "YEAR(c.date_filed) = '".$mysqli->real_escape_string($year)."'";
}
$where = count($filters) ? "WHERE " . implode(' AND ', $filters) : '';

$query = "
  SELECT c.*, 
         c.Comp_First_Name, c.Comp_Middle_Name, c.Comp_Last_Name, c.Comp_Suffix_Name,
         c.Resp_First_Name, c.Resp_Middle_Name, c.Resp_Last_Name, c.Resp_Suffix_Name
  FROM cases c
  $where
  ORDER BY c.created_at DESC
";

$result = $mysqli->query($query);

$totals = [
  'criminal'=>0, 'civil'=>0, 'others'=>0,
  'mediate'=>0, 'conciliate'=>0, 'arbitrate'=>0,
  'repudiated'=>0, 'withdrawn'=>0, 'pending'=>0,
  'dismissed'=>0, 'certified'=>0, 'referred'=>0, 'ongoing'=>0
];
$rows = [];

while ($row = $result->fetch_assoc()) {
  $rows[] = $row;

  switch (strtolower($row['nature_offense'])) {
    case 'criminal': $totals['criminal']++; break;
    case 'civil': $totals['civil']++; break;
    default: $totals['others']++; break;
  }

  switch (strtolower($row['action_taken'])) {
    case 'mediated':     $totals['mediate']++; break;
    case 'conciliated':  $totals['conciliate']++; break;
    case 'arbitration':  $totals['arbitrate']++; break;
    case 'repudiation':  $totals['repudiated']++; break;
    case 'withdrawn':    $totals['withdrawn']++; break;
    case 'pending':      $totals['pending']++; break;
    case 'dismissed':    $totals['dismissed']++; break;
    case 'certified':    $totals['certified']++; break;
    case 'referred':     $totals['referred']++; break;
    case 'ongoing':      $totals['ongoing']++; break;
  }
}
?>

<div style="width: 100%; padding: 20px; box-sizing: border-box;">
  <?php if ($isPrint): ?>
  <div class="print-only">
    <div style="display: flex; justify-content: space-between; font-size: 15px;" >
      <div>
        <strong>KP MONITORING FORM 1</strong><br>
        City of Cagayan de Oro<br>
        Barangay Bugo
      </div>
      <div style="text-align: right;">
        <strong>ANNEX A</strong><br>
        Reporting Month <?= date('F') ?><br>
        Calendar Year <?= date('Y') ?><br>
        Date Submitted <?= date('m.d.y') ?>
      </div>
    </div>
    <div style="text-align: center; margin: 10px 0; font-weight: bold; font-size: 20px;">
      ACTION TAKEN BY THE LUPONG TAGAPAMAYAPA (DILG MC 2007 - 129)
    </div>
  </div>
  <?php endif; ?>

  <table style="width: 100%; font-size: 10px;">
  <thead>
  <tr>
    <th rowspan="3">(1)<br>Case<br>No.</th>
    <th rowspan="3">(2)<br>Complainant/s</th>
    <th rowspan="3">(3)<br>Respondent/s</th>
    <th colspan="4">(4) Nature of Disputes</th>
    <th colspan="3">(5) Settled Cases (C1)</th>
    <th colspan="8">(6) Unsettled Cases (C2)</th>
  </tr>
  <tr>
    <th><br>Criminal</th>
    <th><br>Civil</th>
    <th><br>Others</th>
    <th><br>Total</th>
    <th><br>Thru<br>Mediate</th>
    <th><br>Thru<br>Conciliate</th>
    <th><br>Thru<br>Arbitrate</th>
    <th><br>Repudiation</th>
    <th><br>Withdrawn</th>
    <th><br>Pending</th>
    <th><br>Dismissed</th>
    <th><br>Certified<br>Cases</th>
    <th><br>Referred to<br>Concerned Agencies</th>
    <th><br>Ongoing</th>
    <th><br>Estimated Gov’t<br>Savings (in PHP)</th>
  </tr>
  <tr>
    <th>(4a)</th>
    <th>(4b)</th>
    <th>(4c)</th>
    <th>(4d)</th>
    <th>(5a)</th>
    <th>(5b)</th>
    <th>(5c)</th>
    <th>(1)</th>
    <th>(2)</th>
    <th>(3)</th>
    <th>(4)</th>
    <th>(5)</th>
    <th>(6)</th>
    <th>(7)</th>
  </tr>
</thead>

    <tbody>
      <?php foreach ($rows as $r):
        $nature = strtolower($r['nature_offense']);
        $action = strtolower($r['action_taken']);

        $isCriminal = $nature === 'criminal' ? 'x' : '';
        $isCivil = $nature === 'civil' ? 'x' : '';
        $isOther = ($nature !== 'criminal' && $nature !== 'civil') ? 'x' : '';

        $a = $b = $c = $rpd = $w = $p = $d = $cf = $rfd = $o = '';
        if ($action === 'mediated')     $a = 'x';
        if ($action === 'conciliated')  $b = 'x';
        if ($action === 'arbitration')  $c = 'x';
        if ($action === 'repudiation')  $rpd = 'x';
        if ($action === 'withdrawn')    $w = 'x';
        if ($action === 'pending')      $p = 'x';
        if ($action === 'dismissed')    $d = 'x';
        if ($action === 'certified')    $cf = 'x';
        if ($action === 'referred')     $rfd = 'x';
        if ($action === 'ongoing')      $o = 'x';
      ?>
      <tr>
        <td><?= htmlspecialchars($r['case_number']) ?></td>
        <td><?= "{$r['Comp_First_Name']} {$r['Comp_Middle_Name']} {$r['Comp_Last_Name']} {$r['Comp_Suffix_Name']}" ?></td>
        <td><?= "{$r['Resp_First_Name']} {$r['Resp_Middle_Name']} {$r['Resp_Last_Name']} {$r['Resp_Suffix_Name']}" ?></td>
        <td><?= $isCriminal ?></td>
        <td><?= $isCivil ?></td>
        <td><?= $isOther ?></td>
        <td>1</td>
        <td><?= $a ?></td>
        <td><?= $b ?></td>
        <td><?= $c ?></td>
        <td><?= $rpd ?></td>
        <td><?= $w ?></td>
        <td><?= $p ?></td>
        <td><?= $d ?></td>
        <td><?= $cf ?></td>
        <td><?= $rfd ?></td>
        <td><?= $o ?></td>
        <td></td>
      </tr>
      <?php endforeach; ?>

      <!-- TOTAL ROW -->
      <tr style="font-weight: bold;">
        <td colspan="3">TOTAL</td>
        <td><?= $totals['criminal'] ?></td>
        <td><?= $totals['civil'] ?></td>
        <td><?= $totals['others'] ?></td>
        <td><?= $totals['criminal'] + $totals['civil'] + $totals['others'] ?></td>
        <td><?= $totals['mediate'] ?></td>
        <td><?= $totals['conciliate'] ?></td>
        <td><?= $totals['arbitrate'] ?></td>
        <td><?= $totals['repudiated'] ?></td>
        <td><?= $totals['withdrawn'] ?></td>
        <td><?= $totals['pending'] ?></td>
        <td><?= $totals['dismissed'] ?></td>
        <td><?= $totals['certified'] ?></td>
        <td><?= $totals['referred'] ?></td>
        <td><?= $totals['ongoing'] ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>

  <?php if ($isPrint): ?>
<div class="print-only print-footer">
  <div style="text-align: left; margin-bottom: 10px;">
    <strong>Summary</strong>
    <table style="width:100%; font-size:11px; margin-top:5px; border:none;">
      <tr>
        <td>Mediated: <?= $totals['mediate'] ?></td>
        <td>Conciliated: <?= $totals['conciliate'] ?></td>
        <td>Arbitrated: <?= $totals['arbitrate'] ?></td>
        <td>Repudiated: <?= $totals['repudiated'] ?></td>
      </tr>
      <tr>
        <td>Withdrawn: <?= $totals['withdrawn'] ?></td>
        <td>Pending: <?= $totals['pending'] ?></td>
        <td>Dismissed: <?= $totals['dismissed'] ?></td>
        <td>Certified: <?= $totals['certified'] ?></td>
      </tr>
      <tr>
        <td>Referred: <?= $totals['referred'] ?></td>
        <td>Ongoing: <?= $totals['ongoing'] ?></td>
        <td colspan="2">Total Cases: <?= count($rows) ?></td>
      </tr>
    </table>
  </div>

  <div style="display: flex; justify-content: space-around; text-align: center; margin-top: 50px;">
    <div><strong>Prepared by:</strong><br><br>THELMA D. JAMERO</div>
    <div><strong>Noted by:</strong><br><br>EMILOR J. CABANOS<br>Brgy Secretary</div>
    <div><strong>Attested by:</strong><br><br>HON. SPENCER L. CAILING<br>Punong Barangay</div>
  </div>
</div>

<?php endif; ?>


<?php if ($isPrint) echo '</body></html>'; ?>
