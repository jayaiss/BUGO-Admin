<?php
declare(strict_types=1);

// --- one-time bootstrap guard ---
if (defined('BUGO_CONNECTION_BOOTSTRAPPED')) return;
define('BUGO_CONNECTION_BOOTSTRAPPED', true);

// --- block direct access ---
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// --- dotenv ---
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// --- envs ---
$dbHost    = $_ENV['DB_HOST']    ?? 'localhost';
$dbPort    = (int)($_ENV['DB_PORT'] ?? 3306);
$dbName    = $_ENV['DB_NAME']    ?? '';
$dbUser    = $_ENV['DB_USER']    ?? '';
$dbPass    = $_ENV['DB_PASS']    ?? '';
$dbCharset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// --- mysqli secure init ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

global $mysqli;

/**
 * Connect or reconnect to the DB.
 * Will always ensure a valid, live connection is returned.
 */
if (!function_exists('db_connection')) {
    function db_connection(): mysqli {
        global $mysqli, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $dbCharset;

        // Create new connection if:
        // - $mysqli not set
        // - not a mysqli instance
        // - ping() fails (already closed or lost connection)
 {
            $mysqli = mysqli_init();
            if (!$mysqli) {
                throw new RuntimeException('MySQLi initialization failed.');
            }
            $mysqli->options(MYSQLI_OPT_LOCAL_INFILE, 0);
            $mysqli->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
            $mysqli->set_charset($dbCharset);
        }

        return $mysqli;
    }
}

// --- initialize first connection ---
$mysqli = db_connection();
