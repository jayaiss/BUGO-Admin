<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);
include 'class/session_timeout.php';
if (!function_exists('calculate_age')) {
    function calculate_age($birth_date) {
        if (!$birth_date) return '';
        $birth = new DateTime($birth_date);
        $today = new DateTime();
        return $birth->diff($today)->y;
    }
}

$status = strtolower($row['status'] ?? '');
$certificate = strtolower($row['certificate'] ?? '');
$role = $_SESSION['Role_Name'] ?? '';
$canPrint = false;
?>

<tr class="align-middle">
    <td class="fw-medium"><?= htmlspecialchars($row['fullname']) ?></td>
    <td><?= htmlspecialchars($row['certificate']) ?></td>
    <td><span class="text-uppercase fw-semibold"><?= htmlspecialchars($row['tracking_number']) ?></span></td>
    <td><?= htmlspecialchars($row['selected_date']) ?></td>
    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row['selected_time']) ?></span></td>
    <td>
        <span class="badge px-3 py-2 fw-semibold <?= 
            $row['status'] === 'Approved' ? 'bg-success' : 
            ($row['status'] === 'Rejected' ? 'bg-danger' : 
            ($row['status'] === 'Released' ? 'bg-primary' : 
            ($row['status'] === 'ApprovedCaptain' ? 'bg-warning' : 'bg-warning text-dark')) )
        ?>">
            <?= htmlspecialchars($row['status']) ?>
        </span>
    </td>
    <td class="d-flex gap-2">
<button class="btn btn-sm btn-info text-white" 
    data-bs-toggle="modal" 
    data-bs-target="#viewModal"
    data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
    data-certificate="<?= htmlspecialchars($row['certificate']) ?>"
    data-tracking-number="<?= htmlspecialchars($row['tracking_number']) ?>"
    data-selected-date="<?= htmlspecialchars($row['selected_date']) ?>"
    data-selected-time="<?= htmlspecialchars($row['selected_time']) ?>"
    data-status="<?= htmlspecialchars($row['status']) ?>"
    data-res-id="<?= htmlspecialchars($row['res_id'] ?? '') ?>"
    title="View Details">
    <i class="bi bi-eye-fill"></i>
</button>
    </td>
</tr>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>