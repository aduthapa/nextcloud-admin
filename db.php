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

// Function to force login
function require_login() {
    if (!isset($_SESSION['admin_user'])) {
        header("Location: /login");
        exit;
    }
}
?>
