<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load normal .env
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../../security/403.html';
    exit;
}

// Load .env.migrator if it exists (will override)
$migratorEnvPath = dirname(__DIR__) . '/.env.migrator';
if (file_exists($migratorEnvPath)) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__), ['.env.migrator'])->safeLoad();
}

// Pick credentials (prefer MIGRATOR_* if set)
$dbHost    = $_ENV['MIGRATOR_HOST']    ?? $_ENV['DB_HOST'];
$dbPort    = (int)($_ENV['MIGRATOR_PORT'] ?? $_ENV['DB_PORT'] ?? 3306);
$dbName    = $_ENV['MIGRATOR_DB']      ?? $_ENV['DB_NAME'];
$dbUser    = $_ENV['MIGRATOR_USER']    ?? $_ENV['DB_USER'];
$dbPass    = $_ENV['MIGRATOR_PASS']    ?? $_ENV['DB_PASS'];
$dbCharset = $_ENV['MIGRATOR_CHARSET'] ?? $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// Safety: block prod unless MIGRATE_ALLOW=1
if (($_ENV['APP_ENV'] ?? 'development') === 'production' && ($_ENV['MIGRATE_ALLOW'] ?? '0') !== '1') {
    fwrite(STDERR, "❌ Migrations are disabled in production. Set MIGRATE_ALLOW=1 to run.\n");
    exit(1);
}

// Connect directly (skip app connection.php so we can use migrator creds)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = mysqli_init();
$mysqli->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$mysqli->set_charset($dbCharset);

// Show who we connected as
printf("✅ Connected as %s@%s\n", 
    $mysqli->query("SELECT CURRENT_USER()")->fetch_row()[0],
    $dbHost
);

// 1. Create migrations tracking table if it doesn't exist
$mysqli->query("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// 2. Get already-applied migrations
$applied = [];
$res = $mysqli->query("SELECT filename FROM migrations");
while ($row = $res->fetch_assoc()) {
    $applied[] = $row['filename'];
}

// 3. Find .sql migration files
$migrationsDir = __DIR__; // since migrate.php is already inside migrations/
$files = glob($migrationsDir . '/*.sql');
sort($files);

if (empty($files)) {
    echo "ℹ️  No migration files found.\n";
}

// 4. Run new migrations
foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied, true)) {
        echo "[SKIP] $filename (already applied)\n";
        continue;
    }

    echo "[RUN] $filename...\n";
    $sql = file_get_contents($file);

    if ($mysqli->multi_query($sql)) {
        while ($mysqli->more_results() && $mysqli->next_result()) { /* flush */ }
        $stmt = $mysqli->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->bind_param('s', $filename);
        $stmt->execute();
        echo "[OK] $filename applied\n";
    } else {
        echo "[ERROR] Failed on $filename: " . $mysqli->error . "\n";
        exit(1);
    }
}

echo "🎉 All migrations complete.\n";
