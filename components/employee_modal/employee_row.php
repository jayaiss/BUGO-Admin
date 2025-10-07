<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
if (!isset($row)) return;

$mid = trim((string)($row['employee_mname'] ?? ''));
$fullName = trim(
    htmlspecialchars($row['employee_fname'] ?? '') . ' ' .
    ($mid !== '' ? htmlspecialchars($mid) . ' ' : '') .
    htmlspecialchars($row['employee_lname'] ?? '')
);

echo "<tr>";
echo "<td>" . htmlspecialchars($row['employee_id']) . "</td>";
echo "<td>" . $fullName . "</td>";
echo "<td>" . htmlspecialchars($row['employee_gender']) . "</td>";
echo "<td>" . htmlspecialchars($row['employee_zone']) . "</td>";
echo "<td class='d-flex gap-4'>";

echo "<button class='btn btn-info btn-sm' 
        data-bs-toggle='modal' 
        data-bs-target='#viewModal' 
        onclick='logEmployeeView(" . (int)$row['employee_id'] . ")'
        data-id='" . htmlspecialchars($row['employee_id']) . "'
        data-fname='" . htmlspecialchars($row['employee_fname']) . "'
        data-mname='" . htmlspecialchars($row['employee_mname']) . "'
        data-lname='" . htmlspecialchars($row['employee_lname']) . "'
        data-birthdate='" . htmlspecialchars($row['employee_birth_date']) . "'
        data-birthplace='" . htmlspecialchars($row['employee_birth_place']) . "'
        data-gender='" . htmlspecialchars($row['employee_gender']) . "'
        data-contact='" . htmlspecialchars($row['employee_contact_number']) . "'
        data-civilstatus='" . htmlspecialchars($row['employee_civil_status']) . "'
        data-email='" . htmlspecialchars($row['employee_email']) . "'
        data-zone='" . htmlspecialchars($row['employee_zone']) . "'
        data-citizenship='" . htmlspecialchars($row['employee_citizenship']) . "'
        data-religion='" . htmlspecialchars($row['employee_religion']) . "'
        data-term='" . htmlspecialchars($row['employee_term']) . "'
        data-username='" . htmlspecialchars($row['employee_username'] ?? '') . "'  
        data-temp='" . htmlspecialchars($row['temp_pass'] ?? '') . "'                
        data-pchange='" . (int)($row['password_change'] ?? 0) . "'>                 
        <i class='bi-eye-fill'></i>
    </button>";

echo "<button class='btn btn-warning btn-sm' data-bs-toggle='modal' data-bs-target='#editModal'
        data-id='" . htmlspecialchars($row['employee_id']) . "'
        data-fname='" . htmlspecialchars($row['employee_fname']) . "'
        data-mname='" . htmlspecialchars($row['employee_mname']) . "'
        data-lname='" . htmlspecialchars($row['employee_lname']) . "'
        data-gender='" . htmlspecialchars($row['employee_gender']) . "'
        data-zone='" . htmlspecialchars($row['employee_zone']) . "'
        data-birthdate='" . htmlspecialchars($row['employee_birth_date']) . "'
        data-birthplace='" . htmlspecialchars($row['employee_birth_place']) . "'
        data-contact='" . htmlspecialchars($row['employee_contact_number']) . "'
        data-civilstatus='" . htmlspecialchars($row['employee_civil_status']) . "'
        data-email='" . htmlspecialchars($row['employee_email']) . "'
        data-citizenship='" . htmlspecialchars($row['employee_citizenship']) . "'
        data-religion='" . htmlspecialchars($row['employee_religion']) . "'
        data-term='" . htmlspecialchars($row['employee_term']) . "'
        data-has-esig='" . (!empty($row['has_esig']) ? "1" : "0") . "'>
        <i class='bi-pencil-fill'></i>
    </button>";

echo "<button onclick=\"confirmDelete('" . htmlspecialchars($row['employee_id']) . "');\" class='btn btn-danger btn-sm'>
        <i class='bi-trash-fill'></i>
    </button>";

echo "</td>";
echo "</tr>";
?>
