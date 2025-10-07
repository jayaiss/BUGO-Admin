<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'components/announcement/announcement_fetch.php';

$role = strtolower($_SESSION['Role_Name'] ?? '');
switch ($role) {
    case 'admin':
        $redirectPage = enc_admin('announcements');
        break;
    case 'punong barangay':
        $redirectPage = enc_captain('announcements');
        break;
    case 'beso':
        $redirectPage = enc_beso('announcements');
        break;
    case 'barangay secretary':
        $redirectPage = enc_brgysec('announcements');
        break;
    case 'lupon':
        $redirectPage = enc_lupon('announcements');
        break;
    case 'multimedia':
        $redirectPage = enc_multimedia('announcements');
        break;
    case 'revenue staff':
        $redirectPage = enc_revenue('announcements');
        break; 
}
$parsedBaseUrl = strtok($redirectPage, '?'); // ✅ remove query string early
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="css/Notice/announcement.css">
<div class="container my-5">
  <h2>📢 Announcements</h2>
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">📢 Announcement List</div>
    <div class="card-body p-0">
      <div class="table-responsive" style="overflow-y: auto; max-height: 600px; overflow-x: hidden;">

<form method="GET" action="<?= htmlspecialchars($redirectPage) ?>" class="mb-3">
    <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page'] ?? 'announcements') ?>">
    <div class="row g-2 align-items-end">
        
        <!-- 🔍 Search -->
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control"
                   placeholder="Search details..."
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>

        <!-- 📅 Month -->
        <div class="col-md-2">
            <label class="form-label">Month</label>
            <select name="month" class="form-select">
                <option value="">All</option>
                <?php
                $selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : '';
                for ($m = 1; $m <= 12; $m++) {
                    $isSelected = ($selectedMonth === $m) ? 'selected' : '';
                    echo "<option value='$m' $isSelected>" . date('F', mktime(0, 0, 0, $m, 1)) . "</option>";
                }
                ?>
            </select>
        </div>

        <!-- 📅 Year -->
        <div class="col-md-2">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
                <option value="">All</option>
                <?php
                $currentYear = date('Y');
                $selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : '';
                for ($y = $currentYear; $y >= 2020; $y--) {
                    $isSelected = ($selectedYear === $y) ? 'selected' : '';
                    echo "<option value='$y' $isSelected>$y</option>";
                }
                ?>
            </select>
        </div>

        <!-- 🔘 Buttons -->
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
            <a href="<?= htmlspecialchars($redirectPage) ?>" class="btn btn-secondary w-100">Clear</a>
        </div>
    </div>
</form>


        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="min-width: 1000px;">Details</th>
              <th>Created</th>
              <th style="width: 150px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result->num_rows > 0): ?>
              <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($row['announcement_details']) ?></td>
                  <td><?= date('F j, Y g:i A', strtotime($row['created'])) ?></td>
                  <td class="text-center">
                    <button 
                      class="btn btn-sm btn-warning me-1 editBtn"
                      data-bs-toggle="modal"
                      data-bs-target="#editAnnouncementModal"
                      data-id="<?= $row['Id'] ?>"
                      data-details="<?= htmlspecialchars($row['announcement_details'], ENT_QUOTES) ?>"
                    >
                      <i class="fas fa-edit"></i>
                    </button>
                    <button 
                      class="btn btn-sm btn-danger"
                      onclick="confirmDelete(<?= $row['Id'] ?>)"
                    >
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="3" class="text-center">No announcements found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <?php require_once 'components/announcement/edit_modal.php';?>
                <nav aria-label="Page navigation">
        <ul class="pagination justify-content-end">
            <?php if ($page > 1) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $parsedBaseUrl  ?>?<?= $queryString ?>&pagenum=1">First</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $parsedBaseUrl  ?>?<?= $queryString ?>&pagenum=<?= $page - 1 ?>">Previous</a>
            </li>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                <a class="page-link" href="<?= $parsedBaseUrl  ?>?<?= $queryString ?>&pagenum=<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($page < $total_pages) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $parsedBaseUrl  ?>?<?= $queryString ?>&pagenum=<?= $page + 1 ?>">Next</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $parsedBaseUrl  ?>?<?= $queryString ?>&pagenum=<?= $total_pages ?>">Last</a>
            </li>
            <?php endif; ?>
        </ul>
        </nav>
      </div>
    </div>
  </div>
</div>
<script>
  const deleteBaseUrl = "<?= $redirectPage ?>";
</script>
<script src = "components/announcement/announcement.js"></script>