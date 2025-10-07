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
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'components/beso/beso_fetch.php';
require_once 'components/beso/edit_modal.php';
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="css/beso/beso.css">
<div class="container my-5">
  <h2>BESO List</h2>
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">ðŸ§¾ BESO List</div>
    <div class="card-body p-0">
      <div class="table-responsive" style="overflow-y: auto; max-height: 600px; overflow-x: hidden;">
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
                <a href="<?= $redirects['beso'] ?>" class="btn btn-secondary w-100">Clear</a>
                </div>
            </div>
            </form>

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
            if ($result->num_rows > 0) {
              while ($row = $result->fetch_assoc()) {
                $fullName = htmlspecialchars($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
                if (!empty($row['suffix_name'])) {
                    $fullName .= ' ' . htmlspecialchars($row['suffix_name']);
                }

                echo "<tr>
                    <td>" . $fullName . "</td>
                    <td>" . htmlspecialchars($row['education_attainment']) . "</td>
                    <td>" . htmlspecialchars($row['course']) . "</td>
                    <td>" . date('F j, Y g:i A', strtotime($row['created_at'])) . "</td>
                    <td class='text-center'>
                        <button 
                        class='btn btn-sm btn-warning me-1 editBtn' 
                        title='Edit'
                        data-bs-toggle='modal' 
                        data-bs-target='#editBesoModal'
                        data-id='" . (int)$row['id'] . "'
                        data-education='" . htmlspecialchars($row['education_attainment'], ENT_QUOTES) . "'
                        data-course='" . htmlspecialchars($row['course'], ENT_QUOTES) . "'>
                        <i class='fas fa-edit'></i>
                        </button>
                        <button class='btn btn-sm btn-danger' title='Delete' onclick='confirmDelete(" . (int)$row['id'] . ")'>
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
$baseUrl   = strtok($redirects['beso'], '?');
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
        <a class="page-link" href="<?= $pageBase . '&pagenum=1' ?>" aria-label="First">
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
        <a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page - 1) ?>" aria-label="Previous">
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
        <a class="page-link" href="<?= $pageBase . '&pagenum=' . $i; ?>"><?= $i; ?></a>
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
        <a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page + 1) ?>" aria-label="Next">
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
        <a class="page-link" href="<?= $pageBase . '&pagenum=' . $total_pages ?>" aria-label="Last">
          <i class="fa fa-angle-double-right" aria-hidden="true"></i>
          <span class="visually-hidden">Last</span>
        </a>
      </li>
    <?php endif; ?>

  </ul>
</nav>


      </div>
    </div>
  </div>
</div>
<script>const deleteBaseUrl = "<?= $redirects['beso'] ?>";</script>
<script src = "components/beso/beso_script.js"></script>

