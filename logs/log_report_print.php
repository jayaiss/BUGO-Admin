<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../class/session_timeout.php';
require_once 'logs_trig.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_type'])) {
    $reportType = strtolower(trim($_POST['report_type']));
    $trigger = new Trigger();

    $map = [
        'cedula' => 4,
        'residents' => 2,
        'events' => 11,
        'schedules' => 3,
        'feedbacks' => 20,
        'cases' => 5
    ];

    if (array_key_exists($reportType, $map)) {
        $trigger->isPrinted($map[$reportType], 0);
        echo "ok";
    } else {
        echo "invalid report";
    }
} else {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
}
