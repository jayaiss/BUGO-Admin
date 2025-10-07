<?php

// Include the soft_delete class
include '../include/delete.php'; // If it's in the include folder, one level up
require_once '../include/redirects.php'; // ✅ Include this

if (isset($_GET['id'])) {
    $resident_id = $_GET['id'];

    // Instantiate the soft_delete class and call the delete_resident method
    $soft_delete = new soft_delete();
    $soft_delete->delete_resident($resident_id, $redirects); // Soft delete the resident
}


?>
