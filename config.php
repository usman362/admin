<?php
// config.php — OSINT Supplier Risk Dashboard
// ⚠️ Edit these settings for your environment. Keep this file outside web root if possible.

define('DB_HOST', 'localhost');
define('DB_NAME', 'osintdashboard');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Admin panel password (change this!)
define('ADMIN_PASSWORD', 'qwerty89');

// Timezone
date_default_timezone_set('UTC');

// App version
define('APP_VERSION', '1.0.0');
define('BASE_URL', '');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/error.log');

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// -----------------------------------------------
// Database connection (PDO singleton)
// -----------------------------------------------
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB Connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}

// -----------------------------------------------
// Helper: Get a setting value
// -----------------------------------------------
function getSetting(string $key, string $default = ''): string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// -----------------------------------------------
// Helper: Update a setting
// -----------------------------------------------
function setSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

// -----------------------------------------------
// Helper: Sanitize output
// -----------------------------------------------
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -----------------------------------------------
// Risk level badge colors
// -----------------------------------------------
function riskBadge(string $level): string {
    $colors = [
        'High'   => '#ef4444',
        'Medium' => '#f59e0b',
        'Low'    => '#22c55e',
    ];
    $color = $colors[$level] ?? '#6b7280';
    return "<span class=\"badge\" style=\"background:{$color}\">" . e($level) . "</span>";
}
