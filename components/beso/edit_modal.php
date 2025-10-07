<?php

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
} ?>

<!-- Edit Modal -->
<div class="modal fade" id="editBesoModal" tabindex="-1" aria-labelledby="editBesoModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editBesoModalLabel">Edit BESO Entry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="beso_id" id="edit_beso_id">
          <div class="mb-3">
            <label for="edit_education" class="form-label">Educational Attainment</label>
            <input type="text" class="form-control" id="edit_education" name="education_attainment" required>
          </div>
          <div class="mb-3">
            <label for="edit_course" class="form-label">Course</label>
            <input type="text" class="form-control" id="edit_course" name="course" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="edit_beso" id="submitEditForm" class="d-none"></button>
          <button type="button" class="btn btn-primary" onclick="confirmEditSubmit()">Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>