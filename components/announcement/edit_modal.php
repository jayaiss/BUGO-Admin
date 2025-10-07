<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);   
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html'; // Customize the path to your 403 error page
    exit;
} ?>

<!-- Edit Modal -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-labelledby="editLabel" aria-hidden="true">
<input type="hidden" name="original_announcement_details" id="originalDetails">
  <div class="modal-dialog">
    <form method="POST" action="">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Announcement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="announcement_id" id="editId">
          <div class="mb-3">
            <label class="form-label">Details</label>
            <textarea class="form-control" name="announcement_details" id="editDetails" rows="4" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="update_announcement" class="btn btn-success">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>