<div class="text-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"
        style="
        position: fixed; 
        bottom: 75px; 
        right: 20px; 
        z-index: 1000; 
        background-color: #ff6f61; 
        color: white; 
        border: none; 
        padding: 15px; 
        border-radius: 50%; 
        cursor: pointer; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3); 
        transition: transform 0.2s, background-color 0.3s;
    ">
        <i class="fas fa-comment-dots" style="font-size: 20px;"></i>
    </button>
</div>
<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../util/helper/router.php';?>
<!-- Add Announcement Modal -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-labelledby="addAnnouncementModalLabel" aria-hidden="true">
  <div class="modal-dialog">
<form method="POST" action="<?= get_role_based_action('add_announcement') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addAnnouncementModalLabel">New Announcement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="announcement_details" class="form-label">Announcement Details</label>
            <textarea name="announcement_details" id="announcement_details" rows="4" class="form-control" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="add_announcement" class="btn btn-primary">Submit</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>