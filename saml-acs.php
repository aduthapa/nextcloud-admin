<?php
// SAML Assertion Consumer Service - Auth0 POSTs the SAMLResponse here after
// the admin authenticates on Auth0's side. See lib/saml.php for the actual
// signature/replay/condition validation this relies on.
require 'db.php';
require __DIR__ . '/saml_config.php';
require __DIR__ . '/lib/saml.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['SAMLResponse'])) {
    header('Location: /login'); exit;
}

$result = saml_validate_response($_POST['SAMLResponse'], $pdo, $SAML_IDP_X509_CERT, $SAML_SP_ENTITY_ID, $SAML_ACS_URL);

if (isset($result['error'])) {
    audit_log('login_failed_saml', $result['error'], 'failed');
    header('Location: /login?ssoerror=1'); exit;
}

$nameId = $result['name_id'];

// SSO never auto-provisions a new admin - the NameID (email) must match an
// existing admins.email, or a previously-linked identity.
$stmt = $pdo->prepare("SELECT username FROM admin_saml_identities WHERE name_id = ?");
$stmt->execute([$nameId]);
$link = $stmt->fetch();

if ($link) {
    $username = $link['username'];
} else {
    $stmt = $pdo->prepare("SELECT username FROM admins WHERE email = ?");
    $stmt->execute([$nameId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        audit_log('login_failed_saml', "no local admin for name_id=$nameId", 'failed');
        header('Location: /login?ssoerror=1'); exit;
    }
    $username = $admin['username'];
    $pdo->prepare("INSERT IGNORE INTO admin_saml_identities (username, name_id) VALUES (?, ?)")->execute([$username, $nameId]);
}

$_SESSION['admin_user'] = $username;
$_SESSION['saml_auth'] = true;
$_SESSION['saml_name_id'] = $nameId;
$_SESSION['saml_session_index'] = $result['session_index'];
audit_log('login_success_saml');
header('Location: /'); exit;
