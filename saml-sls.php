<?php
// SAML Single Logout Service - handles two distinct cases on the same URL,
// both using HTTP-Redirect binding (deflate + base64 in the query string):
//   ?SAMLRequest=...  IdP-initiated logout (Auth0, or another SP, telling us
//                     to end this admin's session)
//   ?SAMLResponse=... Auth0 confirming an SP-initiated logout we started
require 'db.php';
require __DIR__ . '/saml_config.php';
require __DIR__ . '/lib/saml.php';

function saml_inflate_param(string $param): ?string {
    $raw = base64_decode($param, true);
    if ($raw === false) return null;
    $xml = @gzinflate($raw);
    return $xml === false ? null : $xml;
}

if (!empty($_GET['SAMLRequest'])) {
    $xml = saml_inflate_param($_GET['SAMLRequest']);
    if ($xml === null) { header('Location: /login'); exit; }

    $result = saml_validate_logout_request($xml, $SAML_IDP_X509_CERT);
    if (isset($result['error'])) {
        audit_log('logout_failed_saml_idp_initiated', $result['error'], 'failed');
        header('Location: /login'); exit;
    }

    // Only end the session in THIS browser if it actually belongs to the
    // identity the IdP is asking us to log out - never trust NameID alone
    // to reach into another session.
    if (isset($_SESSION['saml_auth']) && ($_SESSION['saml_name_id'] ?? null) === $result['name_id']) {
        $user = $_SESSION['admin_user'] ?? 'unknown';
        session_destroy();
        audit_log('logout_saml_idp_initiated', "username=$user");
    }

    header('Location: ' . saml_build_logout_response($SAML_SP_ENTITY_ID, $SAML_IDP_SLO_URL, $result['request_id']));
    exit;
}

if (!empty($_GET['SAMLResponse'])) {
    $xml = saml_inflate_param($_GET['SAMLResponse']);
    if ($xml !== null) saml_validate_logout_response($xml, $pdo); // best-effort cleanup only; local session already ended
    header('Location: /login?loggedout=1'); exit;
}

header('Location: /login'); exit;
