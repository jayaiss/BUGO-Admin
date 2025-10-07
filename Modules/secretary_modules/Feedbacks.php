<?php
// ------------------------------------------------------------
// Bootstrap & Security
// ------------------------------------------------------------
ini_set('display_errors', 0); // hide errors from users
ini_set('log_errors', 1);     // log them instead
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

require_once __DIR__ . '/../../include/connection.php';
require_once __DIR__ . '/../../include/redirects.php';
require_once __DIR__ . '/../../logs/logs_trig.php';
require_once __DIR__ . '/../../class/session_timeout.php';

$mysqli = db_connection();

// ------------------------------------------------------------
// Pagination setup
// ------------------------------------------------------------
$results_per_page = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page   = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? (int)$_GET['pagenum'] : 1;
$page   = max(1, $page);
$offset = ($page - 1) * $results_per_page;

// ------------------------------------------------------------
// Handle archive (soft delete)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_feedback'])) {
    $feedback_id = (int)$_POST['feedback_id'];

    $update_query = "UPDATE feedback SET feedback_delete_status = 1 WHERE id = ?";
    if ($stmt_update = $mysqli->prepare($update_query)) {
        $stmt_update->bind_param("i", $feedback_id);
        $stmt_update->execute();
        $baseUrl = enc_brgysec('feedbacks');

        $trigger = new Trigger();
        $trigger->isDelete(20, $feedback_id);

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Archived!',
            text: 'Feedback archived successfully.',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '$baseUrl&pagenum=" . $page . "';
        });
        </script>";
        exit();
    } else {
        error_log('Feedback archive failed: ' . $mysqli->error);
        echo "<div class='alert alert-danger'>Unable to archive feedback right now.</div>";
    }
}

// ------------------------------------------------------------
// Count total results
// ------------------------------------------------------------
$total_results_sql = "SELECT COUNT(*) FROM feedback WHERE feedback_delete_status = 0";
$total_results_result = $mysqli->query($total_results_sql);
$total_results = $total_results_result ? (int)$total_results_result->fetch_row()[0] : 0;
$total_pages   = $total_results > 0 ? ceil($total_results / $results_per_page) : 1;

if ($page > $total_pages) {
    $page   = max(1, $total_pages);
    $offset = ($page - 1) * $results_per_page;
}

// ------------------------------------------------------------
// Query feedback list
// ------------------------------------------------------------
$results_per_page = max(1, (int)$results_per_page);
$offset           = max(0, (int)$offset);

$sql = "
    SELECT id, feedback_text, created_at
    FROM feedback
    WHERE feedback_delete_status = 0
    ORDER BY created_at DESC
    LIMIT $results_per_page OFFSET $offset
";

$result = $mysqli->query($sql);
if (!$result) {
    error_log('Feedback query failed: ' . $mysqli->error);
}
?>

<!-- --------------------------------------------------------
     HTML Output
--------------------------------------------------------- -->
<div class="container my-5">
    <h2 class="text-start mb-4">Feedback List</h2>
    <link rel="stylesheet" href="css/Notice/feedback.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <div class="card-shell">
        <!-- Controls -->
        <div class="feedback-controls">
            <div class="left">
                <input id="fb-search" class="form-control" placeholder="Search feedback…">
            </div>
            <!--<div class="right">-->
            <!--    <label class="form-label m-0 me-2">Rows:</label>-->
            <!--    <select id="fb-pp" class="form-select">-->
            <!--        <option value="10" <?= $results_per_page==10?'selected':'' ?>>10</option>-->
            <!--        <option value="20" <?= $results_per_page==20?'selected':'' ?>>20</option>-->
            <!--        <option value="50" <?= $results_per_page==50?'selected':'' ?>>50</option>-->
            <!--    </select>-->
            <!--</div>-->
        </div>

        <!-- Table -->
        <?php if ($result && $result->num_rows > 0): ?>
            <div class="table-panel">
                <table class="table align-middle text-center">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Feedback</th>
                            <th style="width: 450px;">Posted On</th>
                            <th style="width: 450px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="text-start"><?= nl2br(htmlspecialchars($row['feedback_text'])) ?></td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                                <td>
                                    <form method="POST" class="delete-feedback-form" action="" style="display:inline;">
                                        <input type="hidden" name="feedback_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="delete_feedback" value="1">
                                        <button type="submit" class="btn btn-ghost-danger btn-sm" title="Archive">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">No feedback available.</div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php $baseUrl = enc_brgysec('feedbacks'); ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-end mt-3">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $page - 1 ?>&per_page=<?= $results_per_page ?>">Previous</a>
                    </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $i ?>&per_page=<?= $results_per_page ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= $baseUrl ?>&pagenum=<?= $page + 1 ?>&per_page=<?= $results_per_page ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Confirm delete
    const deleteForms = document.querySelectorAll('.delete-feedback-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: "This feedback will be archived.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, archive it!',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    // Live search
    const search = document.querySelector('#fb-search');
    const rows = document.querySelectorAll('table tbody tr');
    search?.addEventListener('input', () => {
        const q = search.value.toLowerCase().trim();
        rows.forEach(r=>{
            const txt = r.querySelector('td')?.innerText.toLowerCase() || '';
            r.style.display = txt.includes(q) ? '' : 'none';
        });
    });

    // Rows per page
    const pp = document.querySelector('#fb-pp');
    pp?.addEventListener('change', () => {
        const base = "<?= $redirects['feedbacks'] ?>";
        const url  = new URL(base, window.location.origin);
        url.searchParams.set('pagenum','1');
        url.searchParams.set('per_page', pp.value);
        window.location.href = url.toString();
    });
});
</script>
