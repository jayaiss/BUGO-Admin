<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'components/beso/beso_fetch.php';
require_once 'components/beso/edit_modal.php';

/* ---------------------------------------------
   CSRF helper
---------------------------------------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_ok($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/* ---------------------------------------------
   Upload directory (create if missing)
   Prefer a folder outside web root; if not possible, use /uploads with .htaccess
---------------------------------------------- */
$UPLOAD_DIR = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$BESO_DIR   = $UPLOAD_DIR . '/beso_imports';
if (!is_dir($BESO_DIR)) {
    @mkdir($BESO_DIR, 0700, true);
}
@chmod($BESO_DIR, 0700);

/* ---------------------------------------------
   Flash helper (kept if you still want inline alerts)
---------------------------------------------- */
function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
$flashHtml = '';
if (!empty($_SESSION['flash'])) {
    $color = $_SESSION['flash']['type'] === 'success' ? 'success' : 'danger';
    $flashHtml = '<div class="alert alert-' . $color . ' alert-dismissible fade show" role="alert">'
               . htmlspecialchars($_SESSION['flash']['msg'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
               . '</div>';
    unset($_SESSION['flash']);
}

/* ---------------------------------------------
   Tiny sanitizers
---------------------------------------------- */
function s50(?string $v): string {
    $v = trim((string)$v);
    $v = strip_tags($v);
    if (mb_strlen($v) > 50) $v = mb_substr($v, 0, 50);
    return $v;
}

/* ---------------------------------------------
   Import handler (SweetAlert success/error)
---------------------------------------------- */
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    ($_POST['action'] ?? '') === 'import_beso' &&
    csrf_ok($_POST['csrf'] ?? '')
) {
    try {
        if (!isset($_FILES['beso_file']) || !is_array($_FILES['beso_file'])) {
            throw new RuntimeException('No file received.');
        }

        $file = $_FILES['beso_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code: ' . (int)$file['error']);
        }

        // Size limit: 5 MB
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('File too large. Max 5 MB.');
        }

        // Extension + MIME validation
        $allowedExt   = ['xlsx', 'csv'];
        $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Invalid file type. Only .xlsx and .csv are allowed.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMime = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => ['text/plain', 'text/csv', 'application/vnd.ms-excel', 'application/csv']
        ];
        $okMime = $ext === 'xlsx'
            ? ($mime === $allowedMime['xlsx'])
            : in_array($mime, $allowedMime['csv'], true);

        if (!$okMime) {
            throw new RuntimeException('MIME type not allowed: ' . htmlspecialchars($mime));
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Possible file upload attack.');
        }

        // Move to locked folder with random name
        $randName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = $BESO_DIR . '/' . $randName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }
        @chmod($dest, 0600);

        // Parse rows
        $rows = [];
        if ($ext === 'xlsx') {
            // Use PhpSpreadsheet if available
            $loaded = false;
            @include_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest);
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($sheet->toArray(null, true, true, false) as $idx => $r) {
                    // Expect: [0]=firstName, [1]=middleName, [2]=lastName, [3]=suffixName, [4]=education_attainment, [5]=course
                    if ($idx === 0) continue; // skip header row
                    if (empty($r) || (count(array_filter($r, fn($x) => $x !== null && $x !== '')) === 0)) continue;
                    $rows[] = [
                        s50($r[0] ?? ''), // firstName
                        s50($r[1] ?? ''), // middleName
                        s50($r[2] ?? ''), // lastName
                        s50($r[3] ?? ''), // suffixName
                        s50($r[4] ?? ''), // education_attainment
                        s50($r[5] ?? ''), // course
                    ];
                }
                $loaded = true;
            }
            if (!$loaded) {
                @unlink($dest);
                throw new RuntimeException('PhpSpreadsheet not installed. Please upload CSV or install the library via Composer.');
            }
        } else { // CSV
            $fh = fopen($dest, 'r');
            if (!$fh) {
                @unlink($dest);
                throw new RuntimeException('Cannot read uploaded CSV.');
            }
            // Handle BOM
            $first = fgets($fh);
            if ($first !== false) {
                $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // strip UTF-8 BOM
                $cols  = str_getcsv($first);
                // Try to detect header
                $maybeHeader = array_map('strtolower', array_map('trim', $cols));
                $isHeader = in_array('firstname', $maybeHeader, true) || in_array('first_name', $maybeHeader, true);
                if (!$isHeader) {
                    // this was data; parse and push
                    $rows[] = [
                        s50($cols[0] ?? ''), s50($cols[1] ?? ''), s50($cols[2] ?? ''),
                        s50($cols[3] ?? ''), s50($cols[4] ?? ''), s50($cols[5] ?? '')
                    ];
                }
            }
            // Remaining lines
            while (($data = fgetcsv($fh)) !== false) {
                if (count(array_filter($data, fn($x) => $x !== null && $x !== '')) === 0) continue;
                $rows[] = [
                    s50($data[0] ?? ''), s50($data[1] ?? ''), s50($data[2] ?? ''),
                    s50($data[3] ?? ''), s50($data[4] ?? ''), s50($data[5] ?? '')
                ];
            }
            fclose($fh);
        }

        // Clean up file after reading
        @unlink($dest);

        if (empty($rows)) {
            throw new RuntimeException('No rows to import.');
        }

        // Insert rows
        $mysqli->begin_transaction();

        $stmt = $mysqli->prepare("
            INSERT INTO beso
                (firstName, middleName, lastName, suffixName, education_attainment, course, employee_id, beso_delete_status, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        if (!$stmt) {
            throw new RuntimeException('DB error (prepare): ' . $mysqli->error);
        }

        $employeeId = (int)($_SESSION['employee_id'] ?? 0);
        $inserted = 0; $skipped = 0;

        foreach ($rows as $r) {
            [$f, $m, $l, $suf, $edu, $course] = $r;

            // Minimal required fields: first + last
            if ($f === '' || $l === '') { $skipped++; continue; }

            // Bind & execute
            $stmt->bind_param('ssssssi', $f, $m, $l, $suf, $edu, $course, $employeeId);
            if (!$stmt->execute()) {
                // Skip problematic rows silently; log server-side
                error_log('[BESO Import][Row Skip] ' . $stmt->error);
                $skipped++;
                continue;
            }
            $inserted++;
        }

        $mysqli->commit();
        $stmt->close();

        // Compute redirect target safely
        $redirectURL = isset($redirects['beso'])
            ? $redirects['beso']
            : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index_Admin.php?page=beso');

        // SweetAlert success + redirect
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
          icon: 'success',
          title: 'Import Complete',
          text: 'Inserted: " . (int)$inserted . ", Skipped: " . (int)$skipped . ".',
          confirmButtonColor: '#3085d6'
        }).then(() => { window.location.href = " . json_encode($redirectURL) . "; });
        </script>";
        exit;

    } catch (Throwable $e) {
        @mysqli_rollback($mysqli);
        error_log('[BESO Import] ' . $e->getMessage());

        $redirectURL = isset($redirects['beso']) ? $redirects['beso'] : 'index_Admin.php?page=beso';

        // SweetAlert error + redirect
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
          icon: 'error',
          title: 'Import Failed',
          text: " . json_encode($e->getMessage()) . ",
          confirmButtonColor: '#d33'
        }).then(() => { window.location.href = " . json_encode($redirectURL) . "; });
        </script>";
        exit;
    }
}
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="css/beso/beso.css">

<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center">
    <h2 class="m-0">BESO List</h2>

    <!-- Upload button -->
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importBesoModal">
      <i class="fa fa-file-excel-o me-1"></i> Batch Upload
    </button>
  </div>

  <!-- Flash (optional if you keep session flashes) -->
  <div class="mt-3">
    <?= $flashHtml ?>
  </div>

  <div class="card shadow-sm mb-4 mt-2">
    <div class="card-header bg-primary text-white">🧾 BESO List</div>
    <div class="card-body p-0">
      <div class="table-responsive" style="overflow-y: auto; max-height: 600px; overflow-x: hidden;">

        <!-- filters -->
        <form method="GET" action="index_Admin.php" class="mb-3">
          <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'beso' ?>">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label">Resident Name</label>
              <input type="text" name="search" class="form-control" placeholder="Search by name" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-1">
              <label class="form-label">Month</label>
              <select name="month" class="form-select">
                <option value="">All</option>
                <?php
                for ($m = 1; $m <= 12; $m++) {
                    $selected = (isset($_GET['month']) && $_GET['month'] == $m) ? 'selected' : '';
                    echo "<option value='$m' $selected>" . date('F', mktime(0, 0, 0, $m, 1)) . "</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-1">
              <label class="form-label">Year</label>
              <select name="year" class="form-select">
                <option value="">All</option>
                <?php
                $currentYear = date('Y');
                for ($y = $currentYear; $y >= 2020; $y--) {
                    $selected = (isset($_GET['year']) && $_GET['year'] == $y) ? 'selected' : '';
                    echo "<option value='$y' $selected>$y</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Course</label>
              <select name="course" class="form-select">
                <option value="">All</option>
                <?php
                $courses = $mysqli->query("SELECT DISTINCT course FROM beso WHERE course IS NOT NULL AND course != ''");
                while ($c = $courses->fetch_assoc()) {
                    $selected = (isset($_GET['course']) && $_GET['course'] == $c['course']) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars($c['course']) . "' $selected>" . htmlspecialchars($c['course']) . "</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary w-100">Filter</button>
              <a href="<?= $redirects['beso'] ?? 'index_Admin.php?page=beso' ?>" class="btn btn-secondary w-100">Clear</a>
            </div>
          </div>
        </form>

        <!-- table -->
        <table class="table table-hover table-bordered align-middle mb-0">
          <thead>
            <tr>
              <th style="width: 450px;">Resident Name</th>
              <th style="width: 300px;">Education</th>
              <th style="width: 300px;">Course</th>
              <th style="width: 250px;">Created At</th>
              <th style="width: 100px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if (!empty($result) && $result instanceof mysqli_result && $result->num_rows > 0) {
              while ($row = $result->fetch_assoc()) {
                // NOTE: If your fetch query now returns camelCase (firstName...), update these indices accordingly.
                $fullName = htmlspecialchars(($row['first_name'] ?? $row['firstName'] ?? '') . ' ' . ($row['middle_name'] ?? $row['middleName'] ?? '') . ' ' . ($row['last_name'] ?? $row['lastName'] ?? ''));
                $suffix = trim($row['suffix_name'] ?? $row['suffixName'] ?? '');
                if ($suffix !== '') $fullName .= ' ' . htmlspecialchars($suffix);

                echo "<tr>
                    <td>" . $fullName . "</td>
                    <td>" . htmlspecialchars($row['education_attainment'] ?? '') . "</td>
                    <td>" . htmlspecialchars($row['course'] ?? '') . "</td>
                    <td>" . (!empty($row['created_at']) ? date('F j, Y g:i A', strtotime($row['created_at'])) : '') . "</td>
                    <td class='text-center'>
                        <button 
                        class='btn btn-sm btn-warning me-1 editBtn' 
                        title='Edit'
                        data-bs-toggle='modal' 
                        data-bs-target='#editBesoModal'
                        data-id='" . (int)($row['id'] ?? 0) . "'
                        data-education='" . htmlspecialchars($row['education_attainment'] ?? '', ENT_QUOTES) . "'
                        data-course='" . htmlspecialchars($row['course'] ?? '', ENT_QUOTES) . "'>
                        <i class='fas fa-edit'></i>
                        </button>
                        <button class='btn btn-sm btn-danger' title='Delete' onclick='confirmDelete(" . (int)($row['id'] ?? 0) . ")'>
                          <i class='fas fa-trash-alt'></i>
                        </button>
                    </td>
                </tr>";
              }
            } else {
              echo "<tr><td colspan='5' class='text-center'>No BESO applications found</td></tr>";
            }
            ?>
          </tbody>
        </table>

<?php
// Build a clean base URL and query string WITHOUT pagenum
$baseUrl   = strtok(($redirects['beso'] ?? 'index_Admin.php?page=beso'), '?');
$params    = $_GET ?? [];
unset($params['pagenum']);
$qs        = http_build_query($params);
$pageBase  = $baseUrl . ($qs ? ('?' . $qs) : '?');

// Safety: make sure these exist
$page         = max(1, (int)($page ?? ($_GET['pagenum'] ?? 1)));
$total_pages  = max(1, (int)($total_pages ?? 1));

// Windowed pagination settings
$window   = 7; // show up to 7 page numbers
$half     = (int) floor($window / 2);
$start    = max(1, $page - $half);
$end      = min($total_pages, $start + $window - 1);
$start    = max(1, $end - $window + 1);
?>

<nav aria-label="Page navigation">
  <ul class="pagination justify-content-end">
    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></a></li>
    <?php endif; ?>

    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></a></li>
    <?php endif; ?>

    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
        <a class="page-link" href="<?= $pageBase . '&pagenum=' . $i; ?>"><?= $i; ?></a>
      </li>
    <?php endfor; ?>

    <?php if ($end < $total_pages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></a></li>
    <?php endif; ?>

    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></a></li>
    <?php endif; ?>
  </ul>
</nav>

      </div>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importBesoModal" tabindex="-1" aria-labelledby="importBesoLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importBesoLabel">Batch Upload (Excel/CSV)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="import_beso">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="mb-3">
          <label class="form-label">Choose file</label>
          <input type="file" name="beso_file" class="form-control" accept=".xlsx,.csv" required>
          <div class="form-text">
            Max 5MB. Columns: <code>firstName, middleName, lastName, suffixName, education_attainment, course</code>. First row as header.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload & Import</button>
      </div>
    </form>
  </div>
</div>

<script>const deleteBaseUrl = "<?= $redirects['beso'] ?>";</script>
<script src="components/beso/beso_script.js"></script>
