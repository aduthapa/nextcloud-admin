<?php
require 'db.php';
require __DIR__ . '/duo_config.php';
require __DIR__ . '/lib/duo.php';

if (empty($_SESSION['pending_2fa_user'])) { header('Location: /login'); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['duo_state'] = $state;
header('Location: ' . duo_create_auth_url($DUO_API_HOST, $DUO_CLIENT_ID, $DUO_CLIENT_SECRET, $DUO_REDIRECT_URI, $_SESSION['pending_2fa_user'], $state));
exit;
