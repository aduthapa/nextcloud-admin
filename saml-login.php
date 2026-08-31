<?php
require 'db.php';
require __DIR__ . '/saml_config.php';
require __DIR__ . '/lib/saml.php';

if (isset($_SESSION['admin_user'])) { header('Location: /'); exit; }

$requestId = saml_new_id();
saml_store_request($pdo, $requestId, 'login');
header('Location: ' . saml_build_authn_request($SAML_SP_ENTITY_ID, $SAML_ACS_URL, $SAML_IDP_SSO_URL, $requestId));
exit;
