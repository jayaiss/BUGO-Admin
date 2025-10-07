<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/redirects.php';
$mysqli = db_connection();

/* -----------------------------
   Helpers / lookups
----------------------------- */
function transform_logs_filename($logs_name) {
    switch ((int)$logs_name) {
        case 2: return 'RESIDENTS';
        case 3: return 'SCHEDULE APPOINTMENT';
        case 4: return 'CEDULA';
        case 7: return 'LOGIN';
        case 8: return 'LOGOUT';
        case 12: return 'CHANGE PASSWORD';
        case 18: return 'PERSONAL DETAILS';
        case 19: return 'UPLOADED PROFILE PICTURE';
        case 20: return 'FEEDBACKS';
        case 28: return 'BESO';
        case 31: return 'RESIDENTS FORGOT PASSWORD';
        case 32: return 'LINKED FAMILY MEMBER';
        default: return '';
    }
}
function transform_action_made($action_made) {
    switch ((int)$action_made) {
        case 2: return 'EDITED';
        case 3: return 'ADDED';
        case 6: return 'LOGIN';
        case 7: return 'LOGOUT';
        case 11: return 'SCHEDULE';
        case 12: return 'PASSWORD_RESET';
        default: return '';
    }
}
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function selected_attr($current, $value) { return ((string)$current === ((string)$value) && $value !== '') ? ' selected' : ''; }

/* -----------------------------
   Pagination + filters
----------------------------- */
$limit  = 20;
$page   = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) && (int)$_GET['pagenum'] > 0 ? (int)$_GET['pagenum'] : 1;
$offset = ($page - 1) * $limit;

$logsFilter   = isset($_GET['logs_name']) && is_numeric($_GET['logs_name']) ? (int)$_GET['logs_name'] : '';
$actionFilter = isset($_GET['action_made']) && is_numeric($_GET['action_made']) ? (int)$_GET['action_made'] : '';
$actorFilter  = trim($_GET['actor'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

/* 🔒 Force role filter to RESIDENTS only (hide other roles in UI) */
$roleFilter = 'residents';

// Validate date strings (YYYY-MM-DD)
$validDate = static function($d) {
    if ($d === '') return '';
    $parts = explode('-', $d);
    if (count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
    }
    return '';
};
$dateFrom = $validDate($dateFrom);
$dateTo   = $validDate($dateTo);

// Build filter query string for pagination links
$filterQS = [];
if ($logsFilter !== '')   $filterQS['logs_name']   = $logsFilter;
if ($actionFilter !== '') $filterQS['action_made'] = $actionFilter;
$filterQS['role_id'] = 'residents'; // always submit as residents
if ($actorFilter !== '')  $filterQS['actor']       = $actorFilter;
if ($dateFrom !== '')     $filterQS['date_from']   = $dateFrom;
if ($dateTo !== '')       $filterQS['date_to']     = $dateTo;
$filtersQueryString = http_build_query($filterQS);

/* -----------------------------
   Core SELECT with dynamic WHERE
----------------------------- */
$select = "
SELECT 
    res_audit_info.id,
    res_audit_info.logs_id, 
    res_audit_info.logs_name, 
    CASE 
        WHEN res_audit_info.logs_name = 2 THEN CONCAT(residents.first_name, ' ', residents.last_name)
        WHEN res_audit_info.logs_name = 3 THEN schedules.certificate
        WHEN res_audit_info.logs_name = 4 THEN cedula.cedula_number
        WHEN res_audit_info.logs_name = 29 THEN announcement.announcement_details
        WHEN res_audit_info.logs_name = 5 THEN 
            CASE 
                WHEN cases.id IS NOT NULL THEN CONCAT(cases.Comp_First_Name, ' ', cases.Comp_Last_Name)
                ELSE ''
            END
        WHEN res_audit_info.logs_name = 6 THEN
            CASE
                WHEN res_audit_info.restore_value = 10 THEN CONCAT(employee_list.employee_fname, ' ', employee_list.employee_lname)
                WHEN res_audit_info.restore_value = 20 THEN CONCAT(residents.first_name, ' ', residents.last_name)
                WHEN res_audit_info.restore_value = 30 THEN schedules.certificate
                WHEN res_audit_info.restore_value = 40 THEN cases.case_number
                WHEN res_audit_info.restore_value = 50 THEN
                    CASE 
                        WHEN cases.id IS NOT NULL THEN CONCAT(cases.Comp_First_Name, ' ', cases.Comp_Last_Name)
                        ELSE ''
                    END
                WHEN res_audit_info.restore_value = 60 THEN announcement.announcement_details
                ELSE ''
            END
        WHEN res_audit_info.logs_name IN (7,8) THEN 
            COALESCE(CONCAT(emp_action_by.employee_fname, ' ', emp_action_by.employee_lname),
                     CONCAT(res_action_by.first_name, ' ', res_action_by.last_name))
        WHEN res_audit_info.logs_name = 9 THEN urgent_request.certificate
        WHEN res_audit_info.logs_name = 10 THEN urgent_cedula_request.cedula_number
        ELSE ''
    END AS name,

    employee_roles.Role_Name AS role_name,
    res_audit_info.action_made,

    /* 🔎 Action By: residents = full name; employees = first + last */
    CASE 
        WHEN res_action_by.id IS NOT NULL THEN CONCAT_WS(' ',
            TRIM(res_action_by.first_name),
            NULLIF(TRIM(res_action_by.middle_name), ''),
            TRIM(res_action_by.last_name),
            NULLIF(TRIM(res_action_by.suffix_name), '')
        )
        ELSE CONCAT(TRIM(emp_action_by.employee_fname), ' ', TRIM(emp_action_by.employee_lname))
    END AS action_by_full,

    /* (optional legacy fields you already had) */
    COALESCE(emp_action_by.employee_fname, res_action_by.first_name) AS action_by_fname,
    COALESCE(emp_action_by.employee_lname, res_action_by.last_name) AS action_by_lname,

    res_audit_info.date_created,
    res_audit_info.old_version,
    res_audit_info.new_version
FROM res_audit_info
LEFT JOIN employee_list 
    ON (res_audit_info.logs_name = 1 OR res_audit_info.restore_value = 10)
    AND res_audit_info.logs_id = employee_list.employee_id
LEFT JOIN residents 
    ON (res_audit_info.logs_name = 2 OR res_audit_info.restore_value = 20)
    AND res_audit_info.logs_id = residents.id
LEFT JOIN schedules 
    ON (res_audit_info.logs_name = 3 OR res_audit_info.restore_value = 30)
    AND res_audit_info.logs_id = schedules.id
LEFT JOIN cedula 
    ON (res_audit_info.logs_name = 4 OR res_audit_info.restore_value = 40)
    AND res_audit_info.logs_id = cedula.Ced_Id
LEFT JOIN cases
    ON (res_audit_info.logs_name = 5 OR res_audit_info.restore_value = 50)
    AND res_audit_info.logs_id = cases.case_number
LEFT JOIN urgent_request
    ON res_audit_info.logs_name = 9
    AND res_audit_info.logs_id = urgent_request.urg_id
LEFT JOIN urgent_cedula_request
    ON res_audit_info.logs_name = 10
    AND res_audit_info.logs_id = urgent_cedula_request.urg_ced_id
LEFT JOIN employee_list AS emp_action_by 
    ON res_audit_info.action_by = emp_action_by.employee_id
LEFT JOIN residents AS res_action_by 
    ON res_audit_info.action_by = res_action_by.id
LEFT JOIN employee_roles 
    ON emp_action_by.Role_Id = employee_roles.Role_Id
LEFT JOIN announcement 
    ON (res_audit_info.logs_name = 29 OR res_audit_info.restore_value = 60)
    AND res_audit_info.logs_id = announcement.Id
";


$where = [];
$types = '';
$vals  = [];

// Module (logs_name)
if ($logsFilter !== '') {
    $where[] = 'res_audit_info.logs_name = ?';
    $types  .= 'i';
    $vals[]  = $logsFilter;
}

// Action (action_made)
if ($actionFilter !== '') {
    $where[] = 'res_audit_info.action_made = ?';
    $types  .= 'i';
    $vals[]  = $actionFilter;
}

/* Role filter: Residents only => employee_roles.Role_Id IS NULL (i.e., actions by residents) */
$where[] = 'employee_roles.Role_Id IS NULL';

// Actor (action_by full name LIKE)
if ($actorFilter !== '') {
    $where[] = '(CONCAT(COALESCE(emp_action_by.employee_fname, res_action_by.first_name), " ", COALESCE(emp_action_by.employee_lname, res_action_by.last_name)) LIKE ?)';
    $types  .= 's';
    $vals[]  = '%'.$actorFilter.'%';
}

// Date range
if ($dateFrom !== '' && $dateTo !== '') {
    $where[] = 'res_audit_info.date_created BETWEEN ? AND ?';
    $types  .= 'ss';
    $vals[]  = $dateFrom . ' 00:00:00';
    $vals[]  = $dateTo   . ' 23:59:59';
} elseif ($dateFrom !== '') {
    $where[] = 'res_audit_info.date_created >= ?';
    $types  .= 's';
    $vals[]  = $dateFrom . ' 00:00:00';
} elseif ($dateTo !== '') {
    $where[] = 'res_audit_info.date_created <= ?';
    $types  .= 's';
    $vals[]  = $dateTo . ' 23:59:59';
}

$sql = $select;
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where) . " ";
}
$sql .= " ORDER BY res_audit_info.date_created DESC LIMIT ? OFFSET ?";

$typesWithLimit = $types . 'ii';
$valsWithLimit  = $vals;
$valsWithLimit[] = $limit;
$valsWithLimit[] = $offset;

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare query.');
}
$stmt->bind_param($typesWithLimit, ...$valsWithLimit);
$stmt->execute();
$result = $stmt->get_result();

/* -----------------------------
   Count (with same WHERE)
----------------------------- */
$countSql = "
SELECT COUNT(*) AS total
FROM res_audit_info
LEFT JOIN employee_list AS emp_action_by
    ON res_audit_info.action_by = emp_action_by.employee_id
LEFT JOIN residents AS res_action_by
    ON res_audit_info.action_by = res_action_by.id
LEFT JOIN employee_roles 
    ON emp_action_by.Role_Id = employee_roles.Role_Id
";
if (!empty($where)) {
    $countSql .= " WHERE " . implode(' AND ', $where);
}
$countStmt = $mysqli->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    exit('Unable to prepare count.');
}
if ($types !== '') {
    $countStmt->bind_param($types, ...$vals);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, (int)ceil($totalRows / $limit));

/* -----------------------------
   Page URL base
----------------------------- */
$baseUrl  = enc_page('residents_audit'); // e.g. "index_Admin.php?page=ENCRYPTED..."
$basePath = strtok($baseUrl, '?'); // "index_Admin.php"

$baseQueryStr = (string)parse_url($baseUrl, PHP_URL_QUERY);
parse_str($baseQueryStr, $baseQueryArr);
$pageToken = $baseQueryArr['page'] ?? ($_GET['page'] ?? ''); // fallback to current request
?>
<h3><i class="fa fa-clipboard-list"></i> Residents - Audit Logs List</h3>
<link rel="stylesheet" href="css/audit/resAudit.css?v=1">
<div class="card table-card mb-4">
  <div class="card-body">

    <form class="filters mb-3" method="get" action="<?= h($basePath) ?>">
      <input type="hidden" name="page" value="<?= h($pageToken) ?>">
      <input type="hidden" name="pagenum" value="1">
      <!-- Always submit role as residents -->
      <input type="hidden" name="role_id" value="residents">

      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small text-muted">Module</label>
          <select name="logs_name" class="form-select">
            <option value="">All</option>
            <?php for ($i=1; $i<=32; $i++):
                $label = transform_logs_filename($i);
                if ($label === '') continue; ?>
              <option value="<?= $i ?>"<?= selected_attr($logsFilter, $i) ?>><?= h($label) ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label small text-muted">Action</label>
          <select name="action_made" class="form-select">
            <option value="">All</option>
            <?php for ($i=1; $i<=12; $i++):
                $label = transform_action_made($i);
                if ($label === '') continue; ?>
              <option value="<?= $i ?>"<?= selected_attr($actionFilter, $i) ?>><?= h($label) ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <!-- Read-only Role selector (display only) -->
        <div class="col-md-3">
          <label class="form-label small text-muted">Role</label>
          <select class="form-select" disabled>
            <option selected>Residents</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label small text-muted">Actor (Action By)</label>
          <input type="text" name="actor" class="form-control" placeholder="e.g. Juan Dela Cruz" value="<?= h($actorFilter) ?>">
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label small text-muted">From</label>
          <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small text-muted">To</label>
          <input type="date" name="date_to" class="form-control" value <?= '"'.h($dateTo).'"' ?>>
        </div>

        <div class="col-md-6 d-flex gap-2 justify-content-md-end">
          <button type="submit" class="btn btn-outline-primary"><i class="fa fa-filter me-1"></i> Apply</button>
          <a class="btn btn-outline-secondary" href="<?= h($baseUrl) ?>"><i class="fa fa-rotate-left me-1"></i> Reset</a>
          <!--<button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button>-->
        </div>
      </div>
    </form>

    <div class="table-wrapper">
      <table class="table custom-audit-table align-middle">
        <thead>
          <tr>
            <th style="width: 350px;">Logs Name</th>
            <th style="width: 350px;">Roles</th>
            <th style="width: 350px;">Action Made</th>
            <th style="width: 350px;">Action By</th>
            <th style="width: 350px;">Date</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr data-original='<?php echo h(json_encode($row)); ?>'>
              <td><span class="pill pill-default"><?php echo h(transform_logs_filename($row['logs_name'] ?? '')); ?></span></td>
              <td><span class="pill pill-role"><?php echo h($row['role_name'] ?? 'Residents'); ?></span></td>
              <td>
                <?php
                  $action = transform_action_made($row['action_made'] ?? '');
                  echo "<span class='pill pill-" . strtolower(str_replace(' ', '_', $action)) . "'>" . h($action) . "</span>";
                ?>
              </td>
              <td><?php echo h($row['action_by_full'] ?? ''); ?></td>
              <td><?php echo h(date('M d, Y h:i A', strtotime($row['date_created'])) ?? ''); ?></td>
            </tr>
          <?php endwhile; ?>
          <?php if ($result->num_rows === 0): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No logs found for the selected filters.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

<?php
  // Build query suffix with current filters
  $qs = $filtersQueryString ? '&'.$filtersQueryString : '';

  // Ensure we have a proper base that includes the encrypted page token
  if (!isset($pageBase)) {
      $baseUrl      = enc_page('residents_audit');                 // e.g. index_Admin.php?page=ENC...
      $basePath     = strtok($baseUrl, '?');             // index_Admin.php
      $baseQueryStr = (string)parse_url($baseUrl, PHP_URL_QUERY);
      parse_str($baseQueryStr, $baseQueryArr);
      $pageToken = $baseQueryArr['page'] ?? ($_GET['page'] ?? '');
      $pageBase  = $basePath . '?page=' . urlencode($pageToken);
  }

  // Windowed pagination: show up to 10 numeric links
  $maxLinks = 5;
  $half     = (int)floor($maxLinks / 2);
  $start    = max(1, $page - $half);
  $end      = min($total_pages, $start + $maxLinks - 1);
  $start    = max(1, $end - $maxLinks + 1); // re-adjust near the end
?>
<nav aria-label="Page navigation">
  <ul class="pagination justify-content-end">

    <!-- First -->
    <?php if ($page <= 1): ?>
      <li class="page-item disabled">
        <span class="page-link" aria-disabled="true">
          <i class="fa fa-angle-double-left" aria-hidden="true"></i>
          <span class="visually-hidden">First</span>
        </span>
      </li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>" aria-label="First">
          <i class="fa fa-angle-double-left" aria-hidden="true"></i>
          <span class="visually-hidden">First</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Previous -->
    <?php if ($page <= 1): ?>
      <li class="page-item disabled">
        <span class="page-link" aria-disabled="true">
          <i class="fa fa-angle-left" aria-hidden="true"></i>
          <span class="visually-hidden">Previous</span>
        </span>
      </li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>" aria-label="Previous">
          <i class="fa fa-angle-left" aria-hidden="true"></i>
          <span class="visually-hidden">Previous</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Left ellipsis -->
    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <!-- Windowed page numbers -->
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
      </li>
    <?php endfor; ?>

    <!-- Right ellipsis -->
    <?php if ($end < $total_pages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <!-- Next -->
    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled">
        <span class="page-link" aria-disabled="true">
          <i class="fa fa-angle-right" aria-hidden="true"></i>
          <span class="visually-hidden">Next</span>
        </span>
      </li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>" aria-label="Next">
          <i class="fa fa-angle-right" aria-hidden="true"></i>
          <span class="visually-hidden">Next</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Last -->
    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled">
        <span class="page-link" aria-disabled="true">
          <i class="fa fa-angle-double-right" aria-hidden="true"></i>
          <span class="visually-hidden">Last</span>
        </span>
      </li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>" aria-label="Last">
          <i class="fa fa-angle-double-right" aria-hidden="true"></i>
          <span class="visually-hidden">Last</span>
        </a>
      </li>
    <?php endif; ?>

  </ul>
</nav>

  </div>
</div>

<style>
.card {
  background-color: #fff;
  border-radius: 10px;
  border: 1px solid #dee2e6;
  box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.card-body { padding: 1.5rem; }

.filters .form-label { margin-bottom: .25rem; }
.filters .form-select, .filters .form-control { height: 38px; }

.table-wrapper {
  max-height: 500px;
  overflow-y: auto;
  overflow-x: auto;
  width: 100%;
  padding: 0 1rem;
  border-radius: 6px;
}

.custom-audit-table {
  width: 100%;
  min-width: 950px;
  table-layout: auto;
  border-collapse: separate;
  border-spacing: 0 8px;
}
.custom-audit-table thead th {
  position: sticky;
  top: 0;
  background-color: #fff;
  z-index: 2;
}

/* Pills */
.pill { font-size: .75rem; font-weight: 500; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; display: inline-block; }
.pill-role { background-color: #f8f9fa; color: #495057; border: 1px solid #ced4da; font-weight: bold; }
.pill-archived { background-color: #f8d7da; color: #842029; font-weight: bold; }
.pill-edited { background-color: #fff3cd; color: #856404; font-weight: bold; }
.pill-added { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-viewed { background-color: #dee2e6; color: #495057; font-weight: bold; }
.pill-restored { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-login { background-color: #cfe2ff; color: #084298; font-weight: bold; }
.pill-logout { background-color: #e2e3e5; color: #343a40; font-weight: bold; }
.pill-update_status { background-color: #d1c4e9; color: #4527a0; font-weight: bold; }
.pill-batch_add { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-urgent_request { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-print { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-password_reset { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-schedule { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-default { font-weight: bold; background-color: #e9ecef; color: #000; }

@media (max-width: 768px) {
  .custom-audit-table { font-size: .85rem; }
  .btn-sm { padding: 2px 8px; font-size: .75rem; }
}
</style>

<script src="js/audit.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
