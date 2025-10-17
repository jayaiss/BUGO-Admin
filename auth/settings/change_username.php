<?php
// auth/settings/change_username.php  (EMPLOYEE + role-based routing → DASHBOARD)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../../include/encryption.php';

// ⬇️ Role-based router helper (provides get_role_based_action)
$routerHelper = __DIR__ . '/../../util/helper/router.php';
if (is_file($routerHelper)) {
    require_once $routerHelper;
}

// Flash helper (fallback)
if (!function_exists('set_flash')) {
    function set_flash($key, $val) { $_SESSION['flash'][$key] = $val; }
}

/* ------------------ Redirect target: DASHBOARD ------------------ */
// Prefer role-based dashboard URL (e.g., index_Admin.php?page=<enc>)
$redirectUrl = null;
if (function_exists('get_role_based_action')) {
    $redirectUrl = get_role_based_action('admin_dashboard');
}
// Fallbacks if helper isn’t available
if (!$redirectUrl) {
    $redirectUrl = '/index_Admin.php'; // safe default
    if (function_exists('enc_admin')) {
        $redirectUrl = enc_admin('admin_dashboard');
    }
}

// Never redirect back to this handler
$handlerPath = '/auth/settings/change_username.php';
if (parse_url($redirectUrl, PHP_URL_PATH) === $handlerPath) {
    $redirectUrl = function_exists('get_role_based_action')
        ? get_role_based_action('admin_dashboard')
        : '/index_Admin.php';
}

/* ------------------ Auth: employee session ------------------ */
$loggedInEmployeeId = $_SESSION['employee_id'] ?? null;
$loggedInUsername   = $_SESSION['username']     ?? null;

if (!$loggedInEmployeeId || !$loggedInUsername) {
    header('Location: /index.php');
    exit();
}

/* ------------------ Column resolution (employee_list) ------------------ */
function column_exists(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

$table          = 'employee_list';
$usernameColsWL = ['employee_username','username'];
$passwordColsWL = ['employee_password','password'];
$idColsWL       = ['employee_id','id'];

$usernameCol = null; foreach ($usernameColsWL as $c) { if (column_exists($mysqli, $table, $c)) { $usernameCol = $c; break; } }
if (!$usernameCol) { $usernameCol = 'employee_username'; }

$passwordCol = null; foreach ($passwordColsWL as $c) { if (column_exists($mysqli, $table, $c)) { $passwordCol = $c; break; } }
if (!$passwordCol) { $passwordCol = 'password'; }

$idCol = null; foreach ($idColsWL as $c) { if (column_exists($mysqli, $table, $c)) { $idCol = $c; break; } }
if (!$idCol) { $idCol = 'employee_id'; }

/* ------------------ Method + CSRF ------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('message', ['type' => 'err', 'text' => 'Invalid request method.']);
    header("Location: {$redirectUrl}");
    exit();
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    set_flash('message', ['type' => 'err', 'text' => 'Invalid session. Please try again.']);
    header("Location: {$redirectUrl}");
    exit();
}
$_SESSION['csrf'] = bin2hex(random_bytes(32));

/* ------------------ Inputs ------------------ */
$currentPassword = trim($_POST['current_password'] ?? '');
$newUsername     = trim($_POST['new_username'] ?? '');

if ($currentPassword === '' || $newUsername === '') {
    set_flash('message', ['type' => 'err', 'text' => 'All fields are required.']);
    header("Location: {$redirectUrl}");
    exit();
}
if (!preg_match('/^[A-Za-z0-9._-]{4,30}$/', $newUsername)) {
    set_flash('message', ['type' => 'err', 'text' => 'Invalid username format.']);
    header("Location: {$redirectUrl}");
    exit();
}

/* ------------------ Fetch + verify password ------------------ */
$sql = "SELECT {$usernameCol}, {$passwordCol} FROM {$table} WHERE {$idCol} = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $loggedInEmployeeId);
$stmt->execute();
$stmt->bind_result($currentUsername, $passwordHash);
if (!$stmt->fetch()) {
    $stmt->close();
    set_flash('message', ['type' => 'err', 'text' => 'Account not found.']);
    header("Location: {$redirectUrl}");
    exit();
}
$stmt->close();

if (!password_verify($currentPassword, $passwordHash)) {
    set_flash('message', ['type' => 'err', 'text' => 'Incorrect password.']);
    header("Location: {$redirectUrl}");
    exit();
}

/* ------------------ Short-circuit if same ------------------ */
if (strcasecmp($newUsername, $currentUsername) === 0) {
    set_flash('message', ['type' => 'ok', 'text' => 'That is already your username.']);
    header("Location: {$redirectUrl}");
    exit();
}

/* ------------------ Unique username ------------------ */
$sql = "SELECT {$idCol} FROM {$table} WHERE {$usernameCol} = ? AND {$idCol} <> ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('si', $newUsername, $loggedInEmployeeId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    set_flash('message', ['type' => 'err', 'text' => 'Username is already taken.']);
    header("Location: {$redirectUrl}");
    exit();
}
$stmt->close();

/* ------------------ Update ------------------ */
$sql = "UPDATE {$table} SET {$usernameCol} = ? WHERE {$idCol} = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('si', $newUsername, $loggedInEmployeeId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    set_flash('message', ['type' => 'err', 'text' => 'Failed to update username. Please try again.']);
    header("Location: {$redirectUrl}");
    exit();
}

/* ------------------ Refresh session ------------------ */
$_SESSION['username'] = $newUsername;

set_flash('message', ['type' => 'ok', 'text' => 'Username updated successfully.']);
header("Location: {$redirectUrl}");
exit();
