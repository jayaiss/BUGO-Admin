<?php

//  Call: require_once __DIR__ . '/security/security.php';

// --- Enforce HTTPS for cookies ---
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Set true in production with HTTPS
session_set_cookie_params([
    'lifetime' => 3600,
    'secure'   => true,
    'httponly' => true, 
    'samesite' => 'Strict' 
]);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function record_failed_attempt(mysqli $db, int $employee_id): void {
    $max = 2;         // failures allowed
    $lock = 60;      // 15 minutes

    $q = $db->prepare("SELECT attempts FROM login_attempts WHERE employee_id = ?");
    $q->bind_param("i", $employee_id);
    $q->execute();
    $r = $q->get_result();

    if ($row = $r->fetch_assoc()) {
        $attempts = $row['attempts'] + 1;
        if ($attempts >= $max) {
            $u = $db->prepare("UPDATE login_attempts SET attempts=?, locked_until=DATE_ADD(NOW(), INTERVAL ? SECOND), last_attempt=NOW() WHERE employee_id=?");
            $u->bind_param("iii", $attempts, $lock, $employee_id);
        } else {
            $u = $db->prepare("UPDATE login_attempts SET attempts=?, last_attempt=NOW() WHERE employee_id=?");
            $u->bind_param("ii", $attempts, $employee_id);
        }
        $u->execute();
    } else {
        $i = $db->prepare("INSERT INTO login_attempts (employee_id, attempts, last_attempt) VALUES (?, 1, NOW())");
        $i->bind_param("i", $employee_id);
        $i->execute();
    }
}

function is_locked_out(mysqli $db, int $employee_id) {
    $q = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS remaining 
                       FROM login_attempts 
                       WHERE employee_id = ?");
    $q->bind_param("i", $employee_id);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
        if ($row['remaining'] > 0) {
            return (int)$row['remaining']; // already in seconds, timezone-safe
        }
    }
    return false;
}

function reset_attempts(mysqli $db, int $employee_id): void {
    $d = $db->prepare("DELETE FROM login_attempts WHERE employee_id = ?");
    $d->bind_param("i", $employee_id);
    $d->execute();
}
