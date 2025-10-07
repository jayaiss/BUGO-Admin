<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
session_start();

// Secure session cookie settings (applies to every page)
ini_set('session.cookie_httponly', 1);  
ini_set('session.cookie_secure', 1);    
session_set_cookie_params([
    'lifetime' => 3600,   
    'secure' => true,     
    'httponly' => true,   
    'samesite' => 'Strict' 
]);

// Destroy the session to log the user out
session_unset();
session_destroy();
header("Location: /index.php");
exit();
?>
