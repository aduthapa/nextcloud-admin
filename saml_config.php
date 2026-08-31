<?php
// Shared Auth0 SAML config - CHANGE THESE to match your Auth0 tenant and
// this panel's real domain before SSO login/logout will work.
//
// Where to find each value in Auth0: Dashboard -> Applications -> your app
// -> Addons -> SAML2 Web App. IDP_SSO_URL/IDP_SLO_URL/IDP_X509_CERT come
// from that addon's "Usage" tab (Identity Provider Login/Logout URL and
// Certificate). SP_ENTITY_ID/ACS_URL/SLS_URL are what you paste back into
// Auth0's addon settings on the "Settings" tab.
$SAML_SP_ENTITY_ID = 'https://nc-admin.adarshthapa.com/saml-metadata.php';
$SAML_ACS_URL       = 'https://nc-admin.adarshthapa.com/saml-acs.php';
$SAML_SLS_URL       = 'https://nc-admin.adarshthapa.com/saml-sls.php';

$SAML_IDP_ENTITY_ID = 'urn:CHANGE_THIS:auth0-tenant';           // <--- CHANGE THIS
$SAML_IDP_SSO_URL   = 'https://YOUR_TENANT.auth0.com/samlp/YOUR_CLIENT_ID';        // <--- CHANGE THIS
$SAML_IDP_SLO_URL   = 'https://YOUR_TENANT.auth0.com/samlp/YOUR_CLIENT_ID/logout'; // <--- CHANGE THIS
$SAML_IDP_X509_CERT = <<<CERT
-----BEGIN CERTIFICATE-----
CHANGE_THIS_TO_YOUR_AUTH0_SAML_SIGNING_CERTIFICATE
-----END CERTIFICATE-----
CERT;
