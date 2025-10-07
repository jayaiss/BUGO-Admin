<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
// include '../class/session_timeout.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../include/encryption.php';
require_once __DIR__ . '/../../include/redirects.php';


$id = $row['id'] ?? null;
$encryptedId = $id ? urlencode(encrypt($id)) : '';

$first = trim(htmlspecialchars($row['first_name'] ?? ''));
$middle = trim(htmlspecialchars($row['middle_name'] ?? ''));
$last = trim(htmlspecialchars($row['last_name'] ?? ''));
$suffix = trim(htmlspecialchars($row['suffix_name'] ?? ''));
$gender = htmlspecialchars($row['gender'] ?? '');
$zone = htmlspecialchars($row['res_zone'] ?? '');

$fullNameParts = array_filter([$first, $middle, $last]);
$fullName = implode(' ', $fullNameParts);
$displayName = $fullName . ($suffix ? " $suffix" : '');
?>

<tr>
    <td><?= htmlspecialchars($row['last_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['first_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['middle_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['suffix_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['res_street_address'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['birth_date'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['gender'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['civil_status'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['occupation'] ?? '') ?></td>
    <td class="d-flex gap-1 flex-wrap">
        <!-- View Button -->
<button class="bi-eye-fill"
    data-bs-toggle="modal"
    data-bs-target="#viewModal"
    data-residentid="<?= $id ?>"
    data-lastname="<?= htmlspecialchars($row['last_name'] ?? '') ?>"
    data-firstname="<?= htmlspecialchars($row['first_name'] ?? '') ?>"
    data-middlename="<?= htmlspecialchars($row['middle_name'] ?? '') ?>"
    data-suffix="<?= htmlspecialchars($row['suffix_name'] ?? '') ?>"
    data-streetaddress="<?= htmlspecialchars($row['res_street_address'] ?? '') ?>"
    data-birthdate="<?= htmlspecialchars($row['birth_date'] ?? '') ?>"
    data-gender="<?= htmlspecialchars($row['gender'] ?? '') ?>"
    data-civilstatus="<?= htmlspecialchars($row['civil_status'] ?? '') ?>"
    data-occupation="<?= htmlspecialchars($row['occupation'] ?? '') ?>"
    data-username="<?= htmlspecialchars($row['username'] ?? '') ?>"
    data-temp-password="<?= htmlspecialchars($row['temp_password'] ?? '') ?>"
    data-passchange="<?= (int)($row['res_pass_change'] ?? 0) ?>"
    onclick="logResidentView(<?= $id ?>)"
>
</button>



        <!-- Edit & Delete (hidden from Lupon) -->
        <?php if (!in_array($_SESSION['Role_Name'], ['Lupon', 'Punong Barangay', 'Barangay Secretary'])) : ?>
            <!-- Edit Button -->
            <button class="btn btn-sm btn-warning" 
                data-bs-toggle="modal"
                data-bs-target="#editModal"
                data-id="<?= $row['id'] ?>"
                data-fname="<?= htmlspecialchars($row['first_name'] ?? '') ?>"
                data-mname="<?= htmlspecialchars($row['middle_name'] ?? '') ?>"
                data-lname="<?= htmlspecialchars($row['last_name'] ?? '') ?>"
                data-sname="<?= htmlspecialchars($row['suffix_name'] ?? '') ?>"
                data-gender="<?= htmlspecialchars($row['gender'] ?? '') ?>"
                data-contact="<?= htmlspecialchars($row['contact_number'] ?? '') ?>"
                data-email="<?= htmlspecialchars($row['email'] ?? '') ?>"
                data-civilstatus="<?= htmlspecialchars($row['civil_status'] ?? '') ?>"
                data-birthdate="<?= htmlspecialchars($row['birth_date'] ?? '') ?>"
                data-residencystart="<?= htmlspecialchars($row['residency_start'] ?? '') ?>"
                data-age="<?= htmlspecialchars($row['age'] ?? '') ?>"
                data-birthplace="<?= htmlspecialchars($row['birth_place'] ?? '') ?>"
                data-zone="<?= htmlspecialchars($row['res_zone'] ?? '') ?>"
                data-streetaddress="<?= htmlspecialchars($row['res_street_address'] ?? '') ?>"
                data-citizenship="<?= htmlspecialchars($row['citizenship'] ?? '') ?>"
                data-religion="<?= htmlspecialchars($row['religion'] ?? '') ?>"
                data-occupation="<?= htmlspecialchars($row['occupation'] ?? '') ?>"
                title="Edit">
                <i class="bi-pencil-fill"></i>
            </button>


            <!-- Delete Button -->
<form id="deleteResidentForm<?= $id ?>" method="GET" action="delete/delete_resident.php">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="button" class="btn btn-sm btn-danger" title="Delete" onclick="confirmDelete<?= $id ?>()">
        <i class="bi-trash-fill"></i>
    </button>
</form>
        <?php endif; ?>
    </td>
</tr>
<script>
function confirmDelete<?= $id ?>() {
    Swal.fire({
        title: 'Are you sure?',
        text: "This resident will be deleted.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteResidentForm<?= $id ?>').submit();
        }
    });
}

function logResidentView(residentId) {
    fetch('./logs/logs_trig.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `filename=2&viewedID=${residentId}`
    })
    .then(res => res.text())
    .then(data => console.log("Resident view logged:", data))
    .catch(err => console.error("Error logging resident view:", err));
}
</script>
