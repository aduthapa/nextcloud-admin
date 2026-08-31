<?php
session_start();

// Database Credentials
$db_host = 'localhost';
$db_name = 'nc_admin_panel';
$db_user = 'nc_admin_user';
$db_pass = 'Strong_DB_Password_123!'; // CHANGE THIS to match Phase 1

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Audit trail table (created on demand so no manual migration step is needed)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(191) NOT NULL,
        action VARCHAR(191) NOT NULL,
        details TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'success',
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Non-fatal: don't let audit-table provisioning block login/usage.
}

// Password reset tokens (used by resetpass.php). Only the SHA-256 hash of
// the token is stored - the raw token lives only in the emailed/shown link.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(191) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (token_hash),
        INDEX (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Non-fatal, same reasoning as above.
}

// Function to force login
function require_login() {
    if (!isset($_SESSION['admin_user'])) {
        header("Location: /login");
        exit;
    }
}

// Records one admin action to the audit trail. Never throws - a logging
// failure must not block the operation it's trying to record.
function audit_log($action, $details = null, $status = 'success') {
    global $pdo;
    $user = $_SESSION['admin_user'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_audit_log (username, action, details, status, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user, $action, $details, $status, $ip]);
    } catch (PDOException $e) {
        // swallow - auditing must never break the request it's observing
    }
}
?>
