<?php
// class/eventname.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/redirects.php';
include_once __DIR__ . '/../logs/logs_trig.php';

header_remove('X-Powered-By');

// Helper
function sanitize($v) {
    return htmlspecialchars(trim($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function want_json(): bool {
    // If request looks like AJAX/JSON, return JSON instead of HTML/JS
    $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return $xhr === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

// ─────────────────────────────────────────────────────────
// Role guard
// ─────────────────────────────────────────────────────────
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin', 'Multimedia'], true)) {
    if (want_json()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// Resolve redirect URL by role
$resbaseUrl = ($role === 'Admin') ? enc_admin('event_list')
            : (($role === 'Multimedia') ? enc_multimedia('event_list') : '../index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (want_json()) {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
    header('Location: ' . $resbaseUrl);
    exit;
}

$newTitleRaw = $_POST['new_event_name'] ?? '';
$newTitle = sanitize($newTitleRaw);
$emp_id = (int)($_SESSION['employee_id'] ?? 1);

// Basic validation
if ($newTitle === '') {
    if (want_json()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Event Name is required.']);
        exit;
    }
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({icon:'error', title:'Missing Field', text:'Event Name is required.'})
        .then(() => window.history.back());
      });
    </script>";
    exit;
}

// Optional: enforce max length, adjust to your schema
if (mb_strlen($newTitle) > 150) {
    if (want_json()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Event Name is too long.']);
        exit;
    }
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({icon:'error', title:'Too Long', text:'Event Name is too long.'})
        .then(() => window.history.back());
      });
    </script>";
    exit;
}

// Duplicate check (case-insensitive, active/inactive)
$exists = $mysqli->prepare("SELECT id, status FROM event_name WHERE LOWER(event_name) = LOWER(?) LIMIT 1");
$exists->bind_param("s", $newTitleRaw);
$exists->execute();
$existsResult = $exists->get_result();
$existing = $existsResult ? $existsResult->fetch_assoc() : null;
$exists->close();

$trigs = new Trigger();

if ($existing) {
    // If exists but inactive, you could optionally reactivate it here
    // For now, treat as duplicate
    if (want_json()) {
        echo json_encode(['ok' => false, 'error' => 'Event Name already exists.']);
        exit;
    }
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({icon:'warning', title:'Duplicate', text:'Event Name already exists.'})
        .then(() => window.location.href = '$resbaseUrl');
      });
    </script>";
    exit;
}

// Insert new event name
$ins = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?, 1, ?)");
$ins->bind_param("si", $newTitleRaw, $emp_id);

if ($ins->execute()) {
    $eventNameId = $ins->insert_id;
    // Log: table type 11 = EVENTS (if you have a separate code for event_name, adjust it)
    $trigs->isAdded(11, $eventNameId);

    if (want_json()) {
        echo json_encode(['ok' => true, 'id' => $eventNameId, 'name' => $newTitleRaw]);
        exit;
    }

    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({icon:'success', title:'✅ Success!', text:'Event Name added.'})
        .then(() => window.location.href = '$resbaseUrl');
      });
    </script>";
} else {
    $err = addslashes($ins->error);
    if (want_json()) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({icon:'error', title:'❌ Error', text:'$err'})
        .then(() => window.history.back());
      });
    </script>";
}

$ins->close();
$mysqli->close();
