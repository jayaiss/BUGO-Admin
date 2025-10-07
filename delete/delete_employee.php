<?php

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../include/delete.php';
require_once '../include/redirects.php'; // ✅ Include this

if (isset($_GET['id'])) {
    $employee_id = $_GET['id'];

    $soft_delete = new soft_delete();
     $soft_delete->delete_employee($employee_id, $redirects); // ✅ Pass the second argument
}
?>
