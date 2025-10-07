<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
} include 'class/session_timeout.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child_id = $_POST['child_id'];
    $parent_id = $_POST['parent_id'];
    $type = $_POST['relationship_type'];

    // ❌ Prevent self-linking
    if ($child_id == $parent_id) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Invalid Link',
  text: 'A person cannot be their own parent.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

    }

    // ✅ Age validation (minimum 12-year gap)
    $childRes = $mysqli->query("SELECT birth_date FROM residents WHERE id = $child_id");
    $parentRes = $mysqli->query("SELECT birth_date FROM residents WHERE id = $parent_id");

    if ($childRes && $parentRes && $childRes->num_rows && $parentRes->num_rows) {
        $childDOB = new DateTime($childRes->fetch_assoc()['birth_date']);
        $parentDOB = new DateTime($parentRes->fetch_assoc()['birth_date']);
        $gap = $parentDOB->diff($childDOB)->y;

        if ($gap < 12) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'warning',
  title: 'Invalid Relationship',
  text: 'Age gap must be at least 12 years.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

        }
    }

    // ✅ Check for existing same relationship type
    $check = $mysqli->prepare("
        SELECT r.first_name, r.middle_name, r.last_name 
        FROM resident_relationships rr
        JOIN residents r ON rr.resident_id = r.id
        WHERE rr.related_resident_id = ? AND rr.relationship_type = ?
    ");
    $check->bind_param("is", $child_id, $type);
    $check->execute();
    $existing = $check->get_result();

    if ($existing->num_rows > 0) {
        $e = $existing->fetch_assoc();
        $fullName = trim("{$e['first_name']} {$e['middle_name']} {$e['last_name']}");
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'info',
  title: 'Relationship Exists',
  text: 'This child is already linked to a $type: $fullName.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;

    }

    // ✅ Handle file upload (birth certificate)
    $certificate = null;
    if (isset($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['birth_certificate']['tmp_name'];
        $fileType = mime_content_type($fileTmp);

        // Only accept image or PDF
        if (!in_array($fileType, ['application/pdf','image/jpeg', 'image/png'])) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Invalid File Type',
  text: 'Only PDF, JPG, and PNG files are allowed.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
exit;
        }

        $certificate = file_get_contents($fileTmp);
    }

    // ✅ Insert into database
    $stmt = $mysqli->prepare("
        INSERT INTO resident_relationships (related_resident_id, resident_id, relationship_type, id_birthcertificate, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("iisb", $child_id, $parent_id, $type, $null);
    if ($certificate !== null) {
    $stmt->send_long_data(3, $certificate);
}

    if ($stmt->execute()) {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'success',
  title: 'Success',
  text: 'Relationship linked successfully! Status is now pending.',
  confirmButtonText: 'OK'
}).then(() => {
  window.location.href = '{$redirects['residents']}';
});
</script>";

    } else {
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: 'error',
  title: 'Link Failed',
  text: 'Failed to link relationship.',
  confirmButtonText: 'Go Back'
}).then(() => history.back());
</script>";
    }
}

?>
<!-- Link Relationship Modal -->
<div class="modal fade" id="linkRelationshipModal" tabindex="-1" aria-labelledby="linkRelationshipModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="" class="modal-content" id="linkRelationshipForm">
      <div class="modal-header">
        <h5 class="modal-title" id="linkRelationshipModalLabel"><i class="fas fa-link"></i> Link Parent to Child</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body row g-3">
        <?php
        $res = $mysqli->query("SELECT id, first_name, middle_name, last_name, birth_date FROM residents WHERE resident_delete_status = 0");
        $residents = [];
        while ($r = $res->fetch_assoc()) {
          $r['age'] = date_diff(date_create($r['birth_date']), date_create('today'))->y;
          $residents[] = $r;
        }

        // Group children and possible parent matches
        $children = array_filter($residents, fn($r) => $r['age'] < 18);
        $adults = array_filter($residents, fn($r) => $r['age'] >= 18);
        ?>

        <div class="col-md-6">
          <label class="form-label">Select Child (Under 18)</label>
          <select class="form-select" name="child_id" id="childSelect" required>
            <option value="">-- Choose Child --</option>
            <?php foreach ($children as $child): ?>
              <?php
                $name = "{$child['first_name']} {$child['middle_name']} {$child['last_name']}";
                echo "<option value='{$child['id']}' data-lastname='{$child['last_name']}' data-middlename='{$child['middle_name']}'>{$name} (Age: {$child['age']})</option>";
              ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Select Matching Parent</label>
          <select class="form-select" name="parent_id" id="parentSelect" required>
            <option value="">-- Choose Matching Parent --</option>
            <?php foreach ($adults as $parent): ?>
              <?php
                $parentName = "{$parent['first_name']} {$parent['middle_name']} {$parent['last_name']}";
                echo "<option value='{$parent['id']}' data-lastname='{$parent['last_name']}' style='display:none;'>{$parentName} (Age: {$parent['age']})</option>";
              ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Relationship Type</label>
          <select class="form-select" name="relationship_type" required>
            <option value="father">Father</option>
            <option value="mother">Mother</option>
            <option value="guardian">Guardian</option>
          </select>
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="confirm" required>
            <label class="form-check-label">I confirm this relationship is valid.</label>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Relationship</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('linkRelationshipForm');

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    Swal.fire({
      title: 'Confirm Relationship',
      text: 'Are you sure you want to save this relationship?',
      icon: 'question',
      showCancelButton: true,
      cancelButtonText: 'Cancel',
      confirmButtonText: 'Yes, Save it!',
      reverseButtons: false
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit(); // Proceed with actual submission
      }
    });
  });
});
</script>
