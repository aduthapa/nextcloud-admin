<?php
// Shared Auth0 SAML config. Primary source is the database - set the real
// values from Settings -> Integrations in the panel UI (no file editing
// needed). Everything below is only the fallback used before anything has
// been saved there.
//
// Where to find each Auth0 value: Dashboard -> Applications -> your app ->
// Addons -> SAML2 Web App. IDP_SSO_URL/IDP_SLO_URL/IDP_X509_CERT come from
// that addon's "Usage" tab (Identity Provider Login/Logout URL and
// Certificate). SP_ENTITY_ID/ACS_URL/SLS_URL are what you paste back into
// Auth0's addon settings on the "Settings" tab.
$SAML_SP_ENTITY_ID = 'https://nc-admin.adarshthapa.com/saml-metadata.php';
$SAML_ACS_URL       = 'https://nc-admin.adarshthapa.com/saml-acs.php';
$SAML_SLS_URL       = 'https://nc-admin.adarshthapa.com/saml-sls.php';

$SAML_IDP_ENTITY_ID = 'urn:CHANGE_THIS:auth0-tenant';
$SAML_IDP_SSO_URL   = 'https://YOUR_TENANT.auth0.com/samlp/YOUR_CLIENT_ID';
$SAML_IDP_SLO_URL   = 'https://YOUR_TENANT.auth0.com/samlp/YOUR_CLIENT_ID/logout';
$SAML_IDP_X509_CERT = <<<CERT
-----BEGIN CERTIFICATE-----
CHANGE_THIS_TO_YOUR_AUTH0_SAML_SIGNING_CERTIFICATE
-----END CERTIFICATE-----
CERT;

if (isset($pdo)) {
    try {
        $row = $pdo->query("SELECT sp_entity_id, acs_url, sls_url, idp_entity_id, idp_sso_url, idp_slo_url, idp_x509_cert FROM saml_idp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['sp_entity_id'] !== '') $SAML_SP_ENTITY_ID = $row['sp_entity_id'];
            if ($row['acs_url'] !== '') $SAML_ACS_URL = $row['acs_url'];
            if ($row['sls_url'] !== '') $SAML_SLS_URL = $row['sls_url'];
            if ($row['idp_entity_id'] !== '') $SAML_IDP_ENTITY_ID = $row['idp_entity_id'];
            if ($row['idp_sso_url'] !== '') $SAML_IDP_SSO_URL = $row['idp_sso_url'];
            if ($row['idp_slo_url'] !== '') $SAML_IDP_SLO_URL = $row['idp_slo_url'];
            if ($row['idp_x509_cert'] !== '') $SAML_IDP_X509_CERT = $row['idp_x509_cert'];
        }
    } catch (PDOException $e) {
        // saml_idp_settings table not migrated yet - keep the fallback above.
    }
}
