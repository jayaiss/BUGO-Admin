<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
function swal_back(string $title, string $text = '', string $icon = 'error'): void {
    $cfg = [
        'icon'  => $icon,
        'title' => $title,
    ];
    if ($text !== '') $cfg['text'] = $text;
    echo "<script>Swal.fire(" . json_encode($cfg) . ").then(()=>{ window.history.back(); });</script>";
    exit;
}
// Accept 24h or 12h time with optional seconds, and normalize to H:i:s
function normalize_time_or_fail(string $input): string {
    $tf = trim($input);

    // allow: 24h HH:MM[:SS]  OR  12h HH:MM[:SS] AM/PM
    $ok = preg_match(
        '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$|^(0?[1-9]|1[0-2]):[0-5]\d(:[0-5]\d)?\s?([APap][Mm])$/',
        $tf
    );
    if (!$ok) {
        swal_back('Invalid time format', 'Use HH:MM or HH:MM:SS (24h) or HH:MM(:SS) AM/PM.');
    }

    $ts = strtotime($tf);
    if ($ts === false) {
        swal_back('Invalid time format', 'Unable to parse the time you entered.');
    }
    return date('H:i:s', $ts); // normalized for DB
}

include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';
$resbaseUrl = enc_lupon('case_list');
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;


if (isset($_POST['import_excel'])) {
    if (!empty($_FILES['excel_file']['tmp_name'])) {
        $file = $_FILES['excel_file']['tmp_name'];
        $employee_id = (int)($_SESSION['employee_id'] ?? 0);

        /* ---------- LOGGING ---------- */
        $logDirPreferred = __DIR__ . '/logs';
        $logDirFallback  = sys_get_temp_dir();
        $logBasename     = 'case_import.log';
        if (!is_dir($logDirPreferred)) { @mkdir($logDirPreferred, 0755, true); }
        $logDirInUse = (is_dir($logDirPreferred) && is_writable($logDirPreferred)) ? $logDirPreferred : $logDirFallback;
        $logFile = rtrim($logDirInUse, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $logBasename;
        $log = function ($msg) use ($logFile) {
            $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
            @file_put_contents($logFile, $line, FILE_APPEND);
            @error_log("[CaseImport] $msg");
        };
        $log("=== Import start === employee_id=$employee_id, tmp={$file}, logFile={$logFile}");

        /* ---------- helpers ---------- */
        $norm = function ($h) {
            $h = strtolower(trim((string)$h));
            $h = preg_replace('/[^a-z0-9#]+/i', '_', $h);
            return trim($h, '_');
        };
        $splitName = function ($full) {
            $full = trim(preg_replace('/\s+/', ' ', (string)$full));
            if ($full === '') return array('','','','');
            $p = explode(' ', $full);
            $first=$p[0]??''; $middle=''; $last=''; $suffix='';
            if (count($p) == 2) {
                $last = $p[1];
            } elseif (count($p) >= 3) {
                $maybe = strtoupper(end($p));
                $sfx = array('JR','SR','III','IV','V');
                if (in_array($maybe, $sfx, true)) { array_pop($p); $suffix = $maybe; }
                $first = $p[0] ?? '';
                $last  = $p[count($p)-1] ?? '';
                if (count($p) > 2) $middle = implode(' ', array_slice($p, 1, -1));
            }
            return array($first,$middle,$last,$suffix);
        };
        $toDate = function($cellObj) {
            if ($cellObj === null) return null;
            $v = $cellObj->getValue();
            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
            try {
                if (is_numeric($v) || ExcelDate::isDateTime($cellObj)) {
                    return ExcelDate::excelToDateTimeObject($v)->format('Y-m-d');
                }
            } catch (\Throwable $e) {}
            $s = trim((string)$v);
            if ($s === '') return null;
            $t = strtotime($s);
            return $t ? date('Y-m-d', $t) : null;
        };
        $toTime = function($cellObj) {
            if ($cellObj === null) return null;
            $v = $cellObj->getValue();
            if ($v instanceof \DateTimeInterface) return $v->format('H:i:s');
            try {
                if (is_numeric($v) || ExcelDate::isDateTime($cellObj)) {
                    return ExcelDate::excelToDateTimeObject($v)->format('H:i:s');
                }
            } catch (\Throwable $e) {}
            $s = trim((string)$v);
            if ($s === '') return null;
            if (preg_match('/^\d{1,2}:\d{2}$/', $s)) $s .= ':00';
            $t = strtotime($s);
            return $t ? date('H:i:s', $t) : null;
        };

        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();

            // Map headers
            $headers = array();
            $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, true, true);
            $headerRow = $headerRow ? $headerRow[1] : array();
            foreach ($headerRow as $col => $label) {
                $headers[$norm($label)] = $col;
            }
            $log('Headers normalized: ' . json_encode($headers));

            $need = array(
                'date_filed'          => array('date_filed','date filed'),
                'time_filed'          => array('time_filed','time filed'),
                'case#'               => array('case#','case_no','case_number','case'),
                'complainants'        => array('complainants','complainant','complainant_name'),
                'respondents'         => array('respondents','respondent','respondent_name'),
                'nature_of_offense'   => array('nature_of_offense','nature_offense','offense','offence'),
                'schedule_of_hearing' => array('schedule_of_hearing','date_of_hearing','date_hearing','hearing'),
                'action_taken'        => array('action_taken','status','case_status'),
            );
            $colOf = function($key) use ($need,$headers) {
                foreach ($need[$key] as $alias) {
                    if (isset($headers[$alias])) return $headers[$alias];
                }
                return null;
            };

            $cDate   = $colOf('date_filed');
            $cTime   = $colOf('time_filed');
            $cCase   = $colOf('case#');
            $cComp   = $colOf('complainants');
            $cResp   = $colOf('respondents');
            $cNature = $colOf('nature_of_offense');
            $cHear   = $colOf('schedule_of_hearing');
            $cAct    = $colOf('action_taken');

            if (!$cDate || !$cTime || !$cCase || !$cComp || !$cResp || !$cNature || !$cHear) {
                throw new Exception('Missing required headers. Needed: Date_Filed, Time_Filed, Case#, Complainants, Respondents, Nature_of_Offense, Schedule_of_Hearing.');
            }

            $getCellObj = function($col, $row) use ($sheet) {
                if (!$col) return null;
                return $sheet->getCell($col . $row);
            };

            $highestRow = (int)$sheet->getHighestDataRow();
            $log("highestDataRow={$highestRow}");

            $imported = 0;
            $skipped  = array();
            $seen     = array();

            $sql = "INSERT INTO cases
                    (case_number, employee_id,
                     Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name,
                     Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name,
                     nature_offense, date_filed, time_filed, date_hearing, action_taken)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { throw new Exception('Prepare failed: '.$mysqli->error); }

            for ($r = 2; $r <= $highestRow; $r++) {
                $cellCase  = $getCellObj($cCase,  $r);
                $cellComp  = $getCellObj($cComp,  $r);
                $cellResp  = $getCellObj($cResp,  $r);
                $cellNat   = $getCellObj($cNature,$r);
                $cellDate  = $getCellObj($cDate,  $r);
                $cellTime  = $getCellObj($cTime,  $r);
                $cellHear  = $getCellObj($cHear,  $r);
                $cellAct   = $getCellObj($cAct,   $r);

                $case_number   = trim((string)($cellCase ? $cellCase->getValue() : ''));
                $complainant   = trim((string)($cellComp ? $cellComp->getValue() : ''));
                $respondentVal = trim((string)($cellResp ? $cellResp->getValue() : ''));
                $nature        = trim((string)($cellNat  ? $cellNat ->getValue() : ''));
                $dateFiled     = $toDate($cellDate);
                $timeFiled     = $toTime($cellTime);
                $dateHearing   = $toDate($cellHear);
                $actionTaken   = trim((string)($cellAct ? $cellAct->getValue() : ''));

                $log("Row $r raw: case={$case_number}, compl='{$complainant}', resp='{$respondentVal}', nature='{$nature}', ".
                     "dateCell=".json_encode($cellDate ? $cellDate->getValue() : null).", parsedDate={$dateFiled}, ".
                     "timeCell=".json_encode($cellTime ? $cellTime->getValue() : null).", parsedTime={$timeFiled}, ".
                     "hearingCell=".json_encode($cellHear ? $cellHear->getValue() : null).", parsedHearing={$dateHearing}, ".
                     "action='{$actionTaken}'");

                if ($case_number==='' && $complainant==='' && $respondentVal==='' && $nature==='') continue;

                if ($case_number===''){ $skipped[]="Row $r: missing Case#"; continue; }
                if ($employee_id<=0)  { $skipped[]="Row $r: missing employee_id"; continue; }
                if (!$dateFiled)      { $skipped[]="Row $r: invalid Date_Filed"; continue; }
                if (!$timeFiled)      { $skipped[]="Row $r: invalid Time_Filed"; continue; }
                if (!$dateHearing)    { $skipped[]="Row $r: invalid Hearing date"; continue; }

                if (isset($seen[$case_number])) { $skipped[]="Row $r: duplicate Case# in file"; continue; }
                $seen[$case_number] = $r;

                list($cf,$cm,$cl,$cs) = $splitName($complainant);
                list($rf,$rm,$rl,$rs) = $splitName($respondentVal);

                // CHANGED: first type is 's' (case_number is VARCHAR), second remains 'i' (employee_id)
                $stmt->bind_param('sisssssssssssss',
                    $case_number,
                    $employee_id,
                    $cf, $cm, $cl, $cs,
                    $rf, $rm, $rl, $rs,
                    $nature,
                    $dateFiled,
                    $timeFiled,
                    $dateHearing,
                    $actionTaken
                );

                if ($stmt->execute()) {
                    $imported++;
                    $log("Row $r inserted OK (case {$case_number})");
                } else {
                    $err = $stmt->error;
                    $skipped[] = "Row $r: DB error $err";
                    $log("Row $r DB error: $err");
                }
            }
            $stmt->close();

            if ($imported > 0 && class_exists('Trigger')) {
                $trigger = new Trigger();
                $trigger->isCaseBatchAdded(5, $imported);
            }

            $msg = "Imported rows: <b>{$imported}</b>";
            if (!empty($skipped)) {
                $msg .= "<br>Skipped: <b>".count($skipped)."</b><br><small class='text-muted'>".htmlspecialchars(implode('<br>', $skipped))."</small>";
            }
            $log("=== Import end === imported=$imported, skipped=".count($skipped));

            $currentUrl = $_SERVER['REQUEST_URI'];
            echo "<script>
                Swal.fire({
                    icon:'success',
                    title:'Imported!',
                    html: ".json_encode($msg).",
                    confirmButtonColor:'#3085d6'
                }).then(()=>{ window.location.href = ".json_encode($currentUrl)." });
            </script>";
            exit;

        } catch (Exception $e) {
            $log('Exception: '.$e->getMessage());
            echo "<script>
                Swal.fire({
                    icon:'error',
                    title:'Import Failed',
                    html: ".json_encode($e->getMessage()).",
                    confirmButtonColor:'#d33'
                });
            </script>";
        }
    }
}

/* ================================
   PAGE DATA
   ================================ */

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

/* ================================
   UPDATE STATUS (Action only)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action_only'])) {
  $case_number = $_POST['case_number'];
  $action_taken = $_POST['action_taken'];

  $stmt = $mysqli->prepare("UPDATE cases SET action_taken = ? WHERE case_number = ?");
  $stmt->bind_param("ss", $action_taken, $case_number); // CHANGED: "si" -> "ss"

  if ($stmt->execute()) {
    $trigger = new Trigger();
    $trigger->isStatusUpdate(5, $case_number, $action_taken, null);
    echo "<script>
      Swal.fire({icon:'success',title:'Status Updated!',text:'Action taken updated successfully.',confirmButtonColor:'#3085d6'})
      .then(()=>{ window.location = '$resbaseUrl'});
    </script>";
  } else {
    echo "<script>
      Swal.fire({icon:'error',title:'Error!',html:`" . addslashes($stmt->error) . "`});
    </script>";
  }
  $stmt->close();
  exit;
}
/* ================================
   UPDATE CASE DETAILS (NO res_id)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_case_details'])) {
    // helpers
    $clean = fn($v) => htmlspecialchars(strip_tags(trim((string)$v)));

    $case_number   = $clean($_POST['case_number'] ?? '');
    $nature_offense= $clean($_POST['nature_offense'] ?? '');
    $date_filed    = $clean($_POST['date_filed'] ?? '');
    $time_filed    = $clean($_POST['time_filed'] ?? '');
    $date_hearing  = $clean($_POST['date_hearing'] ?? '');

    // names (separated)
    $Comp_First_Name  = $clean($_POST['Comp_First_Name']  ?? '');
    $Comp_Middle_Name = $clean($_POST['Comp_Middle_Name'] ?? '');
    $Comp_Last_Name   = $clean($_POST['Comp_Last_Name']   ?? '');
    $Comp_Suffix_Name = $clean($_POST['Comp_Suffix_Name'] ?? '');

    $Resp_First_Name  = $clean($_POST['Resp_First_Name']  ?? '');
    $Resp_Middle_Name = $clean($_POST['Resp_Middle_Name'] ?? '');
    $Resp_Last_Name   = $clean($_POST['Resp_Last_Name']   ?? '');
    $Resp_Suffix_Name = $clean($_POST['Resp_Suffix_Name'] ?? '');

    // minimal validation (add more if needed)
   if (!$case_number || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name ||
    !$nature_offense || !$date_filed || !$time_filed || !$date_hearing) {
    swal_back('Missing fields', 'Please complete all required fields.');
}

 // invalid dates
 if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filed) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_hearing)) {
     swal_back('Invalid date format', 'Use YYYY-MM-DD.');
 }

 // invalid time
 $time_filed = normalize_time_or_fail($time_filed);
 // complainant = respondent
 if (
     strcasecmp($Comp_First_Name, $Resp_First_Name)   === 0 &&
     strcasecmp($Comp_Middle_Name, $Resp_Middle_Name) === 0 &&
     strcasecmp($Comp_Last_Name,  $Resp_Last_Name)    === 0 &&
     strcasecmp($Comp_Suffix_Name,$Resp_Suffix_Name)  === 0
 ) {
     swal_back('Invalid Update', 'Respondent cannot be the same as the Complainant.');
 }
    $sql = "UPDATE cases SET 
              Comp_First_Name=?,  Comp_Middle_Name=?,  Comp_Last_Name=?,  Comp_Suffix_Name=?,
              Resp_First_Name=?,  Resp_Middle_Name=?,  Resp_Last_Name=?,  Resp_Suffix_Name=?,
              nature_offense=?,   date_filed=?,        time_filed=?,      date_hearing=?
            WHERE case_number=?";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
    swal_back('Unexpected Error', 'Please try again later.');
    }

    // CHANGED: all strings (case_number is VARCHAR)
    $stmt->bind_param(
        "sssssssssssss",
        $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name,
        $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name,
        $nature_offense, $date_filed, $time_filed, $date_hearing,
        $case_number
    );

    if ($stmt->execute()) {
        // optional: logs
        if (class_exists('Trigger')) {
            $trigger = new Trigger();
            $trigger->isEdit(5, $case_number, [
              'Comp_First_Name'=>$Comp_First_Name, 'Comp_Middle_Name'=>$Comp_Middle_Name,
              'Comp_Last_Name'=>$Comp_Last_Name,   'Comp_Suffix_Name'=>$Comp_Suffix_Name,
              'Resp_First_Name'=>$Resp_First_Name, 'Resp_Middle_Name'=>$Resp_Middle_Name,
              'Resp_Last_Name'=>$Resp_Last_Name,   'Resp_Suffix_Name'=>$Resp_Suffix_Name,
              'nature_offense'=>$nature_offense,   'date_filed'=>$date_filed,
              'time_filed'=>$time_filed,           'date_hearing'=>$date_hearing,
            ]);
        }

        echo "<script>
          Swal.fire({icon:'success',title:'Updated!',text:'Case details successfully updated.',confirmButtonColor:'#3085d6'})
            .then(()=>{ window.location = '$resbaseUrl'});
        </script>";
    } else {
       swal_back('Database Error', 'Something went wrong while saving. Please try again.');
    }
    $stmt->close();
}
/* ================================
   ADD CASE (NO res_id)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_case'])) {
    function sanitizeInput($d){ return htmlspecialchars(strip_tags(trim((string)$d))); }

    $case_number  = sanitizeInput($_POST['case_number']);
    $nature_offense = sanitizeInput($_POST['nature_offense']);
    $date_filed   = sanitizeInput($_POST['date_filed']);
    $time_filed   = sanitizeInput($_POST['time_filed']);
    $date_hearing = sanitizeInput($_POST['date_hearing']);
    $action_taken = sanitizeInput($_POST['action_taken']);
    $employee_id  = (int)($_SESSION['employee_id'] ?? 0);

    // complainant (either separated or fallback from complainant_name)
    $Comp_First_Name  = sanitizeInput($_POST['Comp_First_Name']  ?? '');
    $Comp_Middle_Name = sanitizeInput($_POST['Comp_Middle_Name'] ?? '');
    $Comp_Last_Name   = sanitizeInput($_POST['Comp_Last_Name']   ?? '');
    $Comp_Suffix_Name = sanitizeInput($_POST['Comp_Suffix_Name'] ?? '');

    if (!$Comp_First_Name && isset($_POST['complainant_name'])) {
        // fallback splitter
        $split = function($n){
            $n = trim(preg_replace('/\s+/', ' ', (string)$n));
            if ($n==='') return array('','','','');
            $p = explode(' ', $n);
            $first=$p[0]??''; $middle=''; $last=''; $suffix='';
            if (count($p) == 2) { $last = $p[1]; }
            elseif (count($p) >= 3) {
                $maybe = strtoupper(end($p)); $sfx = ['JR','SR','III','IV','V'];
                if (in_array($maybe, $sfx, true)) { array_pop($p); $suffix = $maybe; }
                $first = $p[0] ?? ''; $last = $p[count($p)-1] ?? '';
                if (count($p) > 2) $middle = implode(' ', array_slice($p, 1, -1));
            }
            return [$first,$middle,$last,$suffix];
        };
        list($Comp_First_Name,$Comp_Middle_Name,$Comp_Last_Name,$Comp_Suffix_Name) = $split($_POST['complainant_name']);
    }

    // respondent (separated — required)
    $Resp_First_Name  = sanitizeInput($_POST['Resp_First_Name']  ?? '');
    $Resp_Middle_Name = sanitizeInput($_POST['Resp_Middle_Name'] ?? '');
    $Resp_Last_Name   = sanitizeInput($_POST['Resp_Last_Name']   ?? '');
    $Resp_Suffix_Name = sanitizeInput($_POST['Resp_Suffix_Name'] ?? '');

    // basic validations
    // CHANGED: allow VARCHAR format (letters/numbers/dash), remove ctype_digit strict check
    if (!$case_number) {
        swal_back('Case number is required.');
    }
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $case_number)) {
        swal_back('Invalid case number format', 'Use letters/numbers/dash only (e.g., 2025-001).');
    }

    // required fields
    if (!$nature_offense || !$date_filed || !$time_filed || !$date_hearing || !$action_taken
        || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name) {
        swal_back('All fields are required.');
    }

    // invalid date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filed) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_hearing)) {
        swal_back('Invalid date format', 'Use YYYY-MM-DD.');
    }

    // invalid time format
    $time_filed = normalize_time_or_fail($time_filed);
    // duplicate case number
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM cases WHERE case_number=?");
    $stmt->bind_param("s", $case_number);
    $stmt->execute(); $stmt->bind_result($exists); $stmt->fetch(); $stmt->close();
    if ($exists > 0) {
        swal_back('Case number already exists.');
    }

    // complainant = respondent
    if (
        strcasecmp($Comp_First_Name, $Resp_First_Name)   === 0 &&
        strcasecmp($Comp_Middle_Name, $Resp_Middle_Name) === 0 &&
        strcasecmp($Comp_Last_Name,  $Resp_Last_Name)    === 0 &&
        strcasecmp($Comp_Suffix_Name,$Resp_Suffix_Name)  === 0
    ) {
        swal_back('Invalid Entry', 'Respondent cannot be the same as the Complainant.');
    }

    // insert
    $stmt = $mysqli->prepare("INSERT INTO cases 
        (case_number, employee_id,
         Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name,
         Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name,
         nature_offense, date_filed, time_filed, date_hearing, action_taken)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        swal_back('Unexpected Error', 'Please try again or contact support.');
    }

    // CHANGED: first type 's' for case_number (VARCHAR), second 'i' for employee_id
    $stmt->bind_param(
        "sisssssssssssss",
        $case_number, $employee_id,
        $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name,
        $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name,
        $nature_offense, $date_filed, $time_filed, $date_hearing, $action_taken
    );

    if ($stmt->execute()) {
        // success
        echo "<script>
          Swal.fire({icon:'success',title:'Success!',text:'Case successfully added!',confirmButtonColor:'#3085d6'})
            .then(()=>{ window.location = '$resbaseUrl'});
        </script>";
    } else {
        echo "<script>
          Swal.fire({icon:'error',title:'DB Error',html:`".addslashes($stmt->error)."`});
        </script>";
    }
    $stmt->close();
}


/* ================================
   LIST (latest 20)  — NO JOIN
   ================================ */
$case_query = "
  SELECT cases.*,
    CONCAT(
      IFNULL(Resp_First_Name,''),' ',
      IFNULL(Resp_Middle_Name,''), CASE WHEN IFNULL(Resp_Middle_Name,'')='' THEN '' ELSE ' ' END,
      IFNULL(Resp_Last_Name,''),
      CASE WHEN IFNULL(Resp_Suffix_Name,'')='' THEN '' ELSE CONCAT(' ', Resp_Suffix_Name) END
    ) AS respondent_full_name,
    CONCAT(
      IFNULL(Comp_First_Name,''),' ',
      IFNULL(Comp_Middle_Name,''), CASE WHEN IFNULL(Comp_Middle_Name,'')='' THEN '' ELSE ' ' END,
      IFNULL(Comp_Last_Name,''),
      CASE WHEN IFNULL(Comp_Suffix_Name,'')='' THEN '' ELSE CONCAT(' ', Comp_Suffix_Name) END
    ) AS complainant_full_name
  FROM cases
  ORDER BY cases.date_filed DESC
  LIMIT 20
";
$result = $mysqli->query($case_query);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cases List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
      <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<div class="container my-5">
  <div class="card shadow">
    <div class="card-body">
      <!-- Add Case Button -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="card-title mb-0"><i class="bi bi-archive"></i> Cases List</h2>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCaseModal">➕ Add Case</button>
</div>



    <form action="" method="POST" enctype="multipart/form-data" class="mb-4">
    <label for="excel_file" class="form-label">📂 Upload Excel File (Batch Cases)</label>
    <input type="file" name="excel_file" id="excel_file" class="form-control mb-2" accept=".xlsx, .xls" required>
    <button type="submit" name="import_excel" class="btn btn-success">Import Cases</button>
</form>
<input type="text" id="searchInput" class="form-control mb-3" placeholder="🔍 Search by complainant, respondent, or case number">


<div class="table-responsive w-100 mt-4" style="height: 300px; overflow-y: auto;">
  <table class="table table-striped table-hover table-bordered align-middle mb-0">

        <thead class="table-dark">
            <tr>
                <th style="width: 100px;">Case Number</th>
                <th style="width: 300px;">Complainant Name</th>
                <th style="width: 300px;">Respondent Name</th>
                <th style="width: 200px;">Nature of Offense</th>
                <th style="width: 200px;">Date Filed</th>
                <th style="width: 200px;">Time Filed</th>
                <th style="width: 200px;">Date of Hearing</th>
                <th style="width: 150px;">Case Status</th>
                <th style="width: 150px;">Action</th>
            </tr>
        </thead>
<tbody id="residentTableBody">
<?php
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // include 'components/case_modal/case_row.php';
    }
} else {
    echo '<tr><td colspan="8" class="text-center">No cases found.</td></tr>';
}
?>
</tbody>

    </table>

</div>
<?php include 'components/case_modal/add_modal.php'; ?>
    <!-- Pagination -->
    <?php $baseUrl = $redirects['case_list']; ?>
<nav aria-label="Page navigation">
    <ul class="pagination justify-content-end">
    </ul>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src= "components/case_modal/case.js"></script>
<script>
    const userRole = "<?= $_SESSION['Role_Name'] ?? '' ?>";
</script>
<script src = "util/case.js"></script>
</body>
</html>
