<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
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

// ✅ Allow print only if status is 'approvedcaptain'
$canPrint = $status === 'approvedcaptain';

// ✅ Construct safe JS onclick string if printing is allowed
$onclick = "";
if ($canPrint) {
    $onclick = "printAppointment('" . 
    addslashes($row['certificate'] ?? '') . "','" . 
    addslashes($row['fullname'] ?? '') . "','" . 
    addslashes($row['res_zone'] ?? '') . "','" . 
    addslashes($row['birth_date'] ?? '') . "','" . 
    addslashes($row['birth_place'] ?? '') . "','" . 
    addslashes($row['res_street_address'] ?? '') . "','" . 
    addslashes($row['purpose'] ?? '') . "','" . 
    addslashes($row['issued_on'] ?? '') . "','" . 
    addslashes($row['issued_at'] ?? '') . "','" . 
    addslashes($row['cedula_number'] ?? '') . "','" . 
    addslashes($row['civil_status'] ?? '') . "','" . 
    addslashes($row['residency_start'] ?? '') . "','" . 
    (isset($row['birth_date']) ? calculate_age($row['birth_date']) : '') . 
    "')";
}
$displayStatus = $row['status'];
if (strcasecmp($row['status'], 'Released') === 0) {
    $displayStatus = 'Released / Paid';
}
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
             <?= htmlspecialchars($displayStatus) ?>
            
        </span>
    </td>
    <td class="d-flex gap-2">
        <!-- View Button (Always Available) -->
        <button class="btn btn-sm btn-info text-white" 
            data-bs-toggle="modal" 
            data-bs-target="#viewModal"
            data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
            data-certificate="<?= htmlspecialchars($row['certificate']) ?>"
            data-tracking-number="<?= htmlspecialchars($row['tracking_number']) ?>"
            data-selected-date="<?= htmlspecialchars($row['selected_date']) ?>"
            data-selected-time="<?= htmlspecialchars($row['selected_time']) ?>"
            data-status="<?= htmlspecialchars($row['status']) ?>"
            title="View Details">
            <i class="bi bi-eye-fill"></i>
        </button>

        <!-- Print Button (Only if status is 'Approved by Captain') -->
        <button 
            class="btn btn-sm <?= $canPrint ? 'btn-secondary' : 'btn-light text-muted' ?>" 
            <?= $canPrint ? '' : 'disabled' ?> 
            onclick="<?= $canPrint ? $onclick : '' ?>"
            title="<?= $canPrint ? 'Print' : 'Print not available' ?>"
        >
            <i class="bi bi-printer<?= $canPrint ? '-fill' : '' ?>"></i>
        </button>

        <!-- Status Button -->
        <!--<button class="btn btn-outline-warning btn-sm" -->
        <!--    data-bs-toggle="modal"-->
        <!--    data-bs-target="#statusModal"-->
        <!--    data-tracking-number="<?= htmlspecialchars($row['tracking_number']) ?>"-->
        <!--    data-certificate="<?= htmlspecialchars($row['certificate']) ?>"-->
        <!--    data-cedula-number="<?= htmlspecialchars($row['cedula_number'] ?? '') ?>"-->
        <!--    data-current-status="<?= htmlspecialchars($row['status']) ?>"-->
        <!--    title="Update Status">-->
        <!--    <i class="bi-pencil-fill"></i>-->
        <!--</button>-->

        <!-- Delete Button
        <form method="POST" action="" style="display:inline;">
            <input type="hidden" name="tracking_number" value="<?= htmlspecialchars($row['tracking_number']) ?>">
            <input type="hidden" name="certificate" value="<?= htmlspecialchars($row['certificate']) ?>">
            <button type="submit" name="delete_appointment" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure?')" title="Delete">
                <i class="bi-trash-fill"></i>
            </button>
        </form> -->
    </td>
</tr>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>