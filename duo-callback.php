<?php
require 'db.php';
require __DIR__ . '/duo_config.php';
require __DIR__ . '/lib/duo.php';

$username = $_SESSION['pending_2fa_user'] ?? null;
$expectedState = $_SESSION['duo_state'] ?? null;
unset($_SESSION['duo_state']);

if (!$username || !$expectedState || empty($_GET['state']) || empty($_GET['duo_code']) || !hash_equals($expectedState, $_GET['state'])) {
    audit_log('login_failed_duo', 'invalid callback state', 'failed');
    header('Location: /login?ssoerror=1'); exit;
}

$result = duo_exchange_code($DUO_API_HOST, $DUO_CLIENT_ID, $DUO_CLIENT_SECRET, $DUO_REDIRECT_URI, $_GET['duo_code']);

if (!$result || !$result['allowed'] || $result['username'] !== $username) {
    audit_log('login_failed_duo', "username=$username", 'failed');
    header('Location: /login?ssoerror=1'); exit;
}

unset($_SESSION['pending_2fa_user']);
$_SESSION['admin_user'] = $username;
audit_log('login_success_duo');
header('Location: /'); exit;
