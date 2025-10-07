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
include 'class/session_timeout.php';
session_start();
$user_role = $_SESSION['Role_Name'] ?? '';
$isPunongBarangay = strtolower($user_role) === 'punong barangay';
?>


<tr>
    <td><?= htmlspecialchars($row['case_number']); ?></td>
    <td><?= htmlspecialchars($row['Comp_First_Name']); ?></td>
    <td><?= htmlspecialchars($row['respondent_full_name'] ?? ''); ?></td>
    <td><?= htmlspecialchars($row['nature_offense']); ?></td>
    <td><?= htmlspecialchars($row['date_filed']); ?></td>
    <td><?= htmlspecialchars($row['time_filed']); ?></td>
    <td><?= htmlspecialchars($row['date_hearing']); ?></td>
    <td>
<?php if ($isPunongBarangay): ?>
    <span class="form-control bg-light" readonly><?= htmlspecialchars($row['action_taken']) ?: 'No action' ?></span>
<?php else: ?>
    <form method="POST" action="" onsubmit="return confirmUpdate();">
        <input type="hidden" name="case_number" value="<?= $row['case_number']; ?>">
        <select name="action_taken" class="form-select">
            <?php
            $actions = ['Conciliated', 'Mediated', 'Dismissed', 'Withdrawn', 'Ongoing', 'Arbitration'];
            foreach ($actions as $action) {
                $selected = ($row['action_taken'] === $action) ? 'selected' : '';
                echo "<option value=\"$action\" $selected>$action</option>";
            }
            ?>
        </select>
        <button type="submit" name="update_action" class="btn btn-primary mt-2">Update Case</button>
    </form>
<?php endif; ?>
    </td>
</tr>
