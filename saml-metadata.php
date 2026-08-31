<?php
// Public SP metadata - paste this URL (or its output) into Auth0's SAML2
// Web App addon settings. No secrets in here, safe to expose unauthenticated.
require __DIR__ . '/saml_config.php';

header('Content-Type: application/xml');
echo '<?xml version="1.0"?>';
?>
<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="<?= htmlspecialchars($SAML_SP_ENTITY_ID) ?>">
    <SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" AuthnRequestsSigned="false" WantAssertionsSigned="true">
        <SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="<?= htmlspecialchars($SAML_SLS_URL) ?>"/>
        <NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</NameIDFormat>
        <AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="<?= htmlspecialchars($SAML_ACS_URL) ?>" index="0" isDefault="true"/>
    </SPSSODescriptor>
</EntityDescriptor>
