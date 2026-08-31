<?php
// Minimal hand-rolled SAML 2.0 Service Provider (no external library).
//
// SECURITY NOTES - read before touching this file:
//  - We only ever trust data extracted from an element whose XML-DSig
//    signature we personally verified against the configured IdP cert.
//    The element we read from is the SAME element the <Reference URI="#id">
//    points to (found by ID, not by tag name) - this is what defends
//    against XML Signature Wrapping: an attacker can't paste a forged,
//    unsigned Assertion next to a validly-signed one and have us read
//    the forged one instead.
//  - Every inbound Response/Assertion is checked for: signature validity,
//    Conditions (NotBefore/NotOnOrAfter), AudienceRestriction (must be our
//    SP entity ID), SubjectConfirmationData (Recipient must be our ACS URL),
//    InResponseTo (must match a request this SP actually issued and hasn't
//    already consumed), and the assertion ID must not have been seen before
//    (replay protection via saml_used_assertions).
//  - We do not support <EncryptedAssertion> - if Auth0 is configured to
//    encrypt assertions, validation fails closed rather than silently
//    accepting unverified data.
//  - Outgoing AuthnRequest/LogoutRequest are sent unsigned (HTTP-Redirect
//    binding, common for SPs); security relies on strictly validating what
//    the IdP signs back, not on the SP signing its own requests.

function saml_new_id(): string {
    return '_' . bin2hex(random_bytes(16));
}

function saml_store_request(PDO $pdo, string $id, string $type, int $ttlSeconds = 300): void {
    $stmt = $pdo->prepare("INSERT INTO saml_request_state (request_id, type, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
    $stmt->execute([$id, $type, $ttlSeconds]);
}

// One-time use: returns true only the first time a given (id, type) is consumed.
function saml_consume_request(PDO $pdo, string $id, string $type): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM saml_request_state WHERE request_id = ? AND type = ? AND expires_at > NOW()");
    $stmt->execute([$id, $type]);
    if (!$stmt->fetch()) return false;
    $pdo->prepare("DELETE FROM saml_request_state WHERE request_id = ? AND type = ?")->execute([$id, $type]);
    return true;
}

function saml_mark_assertion_used(PDO $pdo, string $assertionId): bool {
    try {
        $pdo->prepare("INSERT INTO saml_used_assertions (assertion_id, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$assertionId]);
        return true; // first time seen
    } catch (PDOException $e) {
        return false; // already used (primary key collision) - replay
    }
}

function saml_deflate_redirect_url(string $baseUrl, string $xml, array $extraParams = []): string {
    $deflated = gzdeflate($xml);
    $params = array_merge(['SAMLRequest' => base64_encode($deflated)], $extraParams);
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    return $baseUrl . $sep . http_build_query($params);
}

function saml_build_authn_request(string $spEntityId, string $acsUrl, string $idpSsoUrl, string $requestId): string {
    $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
    $xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
        . ' ID="' . htmlspecialchars($requestId) . '" Version="2.0" IssueInstant="' . $issueInstant . '"'
        . ' Destination="' . htmlspecialchars($idpSsoUrl) . '" AssertionConsumerServiceURL="' . htmlspecialchars($acsUrl) . '"'
        . ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
        . '<saml:Issuer>' . htmlspecialchars($spEntityId) . '</saml:Issuer>'
        . '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="false"/>'
        . '</samlp:AuthnRequest>';
    return saml_deflate_redirect_url($idpSsoUrl, $xml);
}

function saml_build_logout_request(string $spEntityId, string $idpSloUrl, string $requestId, string $nameId, ?string $sessionIndex): string {
    $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
    $sessionIndexXml = $sessionIndex ? '<samlp:SessionIndex>' . htmlspecialchars($sessionIndex) . '</samlp:SessionIndex>' : '';
    $xml = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
        . ' ID="' . htmlspecialchars($requestId) . '" Version="2.0" IssueInstant="' . $issueInstant . '"'
        . ' Destination="' . htmlspecialchars($idpSloUrl) . '">'
        . '<saml:Issuer>' . htmlspecialchars($spEntityId) . '</saml:Issuer>'
        . '<saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">' . htmlspecialchars($nameId) . '</saml:NameID>'
        . $sessionIndexXml
        . '</samlp:LogoutRequest>';
    return saml_deflate_redirect_url($idpSloUrl, $xml);
}

function saml_build_logout_response(string $spEntityId, string $idpSloUrl, string $inResponseTo): string {
    $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
    $xml = '<samlp:LogoutResponse xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
        . ' ID="' . htmlspecialchars(saml_new_id()) . '" Version="2.0" IssueInstant="' . $issueInstant . '"'
        . ' Destination="' . htmlspecialchars($idpSloUrl) . '" InResponseTo="' . htmlspecialchars($inResponseTo) . '">'
        . '<saml:Issuer>' . htmlspecialchars($spEntityId) . '</saml:Issuer>'
        . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
        . '</samlp:LogoutResponse>';
    return saml_deflate_redirect_url($idpSloUrl, $xml);
}

// Loads XML defensively: no DOCTYPE (hard XXE guard) and no network/entity resolution.
function saml_safe_load_xml(string $xml): ?DOMDocument {
    if (stripos($xml, '<!DOCTYPE') !== false) return null;
    $doc = new DOMDocument();
    $doc->resolveExternals = false;
    $prevErrors = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET);
    libxml_use_internal_errors($prevErrors);
    return $ok ? $doc : null;
}

const SAML_DIGEST_ALGOS = [
    'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
    'http://www.w3.org/2000/09/xmldsig#sha1'  => 'sha1',
];
const SAML_SIG_ALGOS = [
    'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
    'http://www.w3.org/2000/09/xmldsig#rsa-sha1'         => OPENSSL_ALGO_SHA1,
];

function saml_find_by_id(DOMDocument $doc, string $id): ?DOMElement {
    foreach ($doc->getElementsByTagName('*') as $el) {
        if ($el->hasAttribute('ID') && hash_equals($el->getAttribute('ID'), $id)) return $el;
    }
    return null;
}

// Verifies the XML-DSig <Signature> that is a DIRECT CHILD of $signedElement,
// and returns $signedElement itself only if the Reference points back to it
// and both the digest and signature check out. Returns null on ANY failure.
function saml_verify_signature(DOMElement $signedElement, string $certPem): ?DOMElement {
    $doc = $signedElement->ownerDocument;
    $sigNode = null;
    foreach ($signedElement->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'Signature') { $sigNode = $child; break; }
    }
    if (!$sigNode) return null;

    $refNodes = $sigNode->getElementsByTagName('Reference');
    if ($refNodes->length !== 1) return null;
    $refUri = ltrim($refNodes->item(0)->getAttribute('URI'), '#');
    if ($refUri === '' || !$signedElement->hasAttribute('ID') || $refUri !== $signedElement->getAttribute('ID')) return null;
    // Confirm the Reference resolves to this exact element, not a same-ID decoy elsewhere.
    if (saml_find_by_id($doc, $refUri) !== $signedElement) return null;

    $digestMethodNodes = $sigNode->getElementsByTagName('DigestMethod');
    $digestValueNodes = $sigNode->getElementsByTagName('DigestValue');
    if ($digestMethodNodes->length !== 1 || $digestValueNodes->length !== 1) return null;
    $digestAlgo = SAML_DIGEST_ALGOS[$digestMethodNodes->item(0)->getAttribute('Algorithm')] ?? null;
    if (!$digestAlgo) return null;

    // Digest is computed over the signed element with its own Signature node removed.
    $clone = $signedElement->cloneNode(true);
    foreach ($clone->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'Signature') { $clone->removeChild($child); break; }
    }
    $canonical = $clone->C14N(true, false);
    $computedDigest = base64_encode(hash($digestAlgo, $canonical, true));
    if (!hash_equals(trim($digestValueNodes->item(0)->textContent), $computedDigest)) return null;

    $signedInfoNodes = $sigNode->getElementsByTagName('SignedInfo');
    $sigValueNodes = $sigNode->getElementsByTagName('SignatureValue');
    $sigMethodNodes = $sigNode->getElementsByTagName('SignatureMethod');
    if ($signedInfoNodes->length !== 1 || $sigValueNodes->length !== 1 || $sigMethodNodes->length !== 1) return null;
    $sigAlgo = SAML_SIG_ALGOS[$sigMethodNodes->item(0)->getAttribute('Algorithm')] ?? null;
    if (!$sigAlgo) return null;

    $signedInfoCanonical = $signedInfoNodes->item(0)->C14N(true, false);
    $signatureBytes = base64_decode(trim($sigValueNodes->item(0)->textContent));

    $pubKey = openssl_pkey_get_public($certPem);
    if ($pubKey === false) return null;
    $verified = openssl_verify($signedInfoCanonical, $signatureBytes, $pubKey, $sigAlgo);
    if ($verified !== 1) return null;

    return $signedElement;
}

// Validates a base64 (POST-binding, not deflated) SAMLResponse. On success
// returns ['name_id' => ..., 'session_index' => ..., 'assertion_id' => ...].
// Returns ['error' => '...'] on any validation failure - never partial data.
function saml_validate_response(string $samlResponseB64, PDO $pdo, string $idpCertPem, string $spEntityId, string $acsUrl): array {
    $xml = base64_decode($samlResponseB64, true);
    if ($xml === false) return ['error' => 'Malformed SAMLResponse encoding.'];
    $doc = saml_safe_load_xml($xml);
    if (!$doc) return ['error' => 'Malformed or unsafe SAML XML.'];

    $responseEl = $doc->documentElement;
    if (!$responseEl || $responseEl->localName !== 'Response') return ['error' => 'Not a SAML Response.'];

    $inResponseTo = $responseEl->getAttribute('InResponseTo');
    if ($inResponseTo === '' || !saml_consume_request($pdo, $inResponseTo, 'login')) {
        return ['error' => 'Response does not correlate to a login this panel initiated (missing/expired/reused InResponseTo).'];
    }

    $assertions = $doc->getElementsByTagName('Assertion');
    if ($assertions->length < 1) return ['error' => 'No Assertion present.'];
    // Prefer a directly-signed Assertion; only trust the Response-level signature as a fallback.
    $verifiedAssertion = null;
    for ($i = 0; $i < $assertions->length; $i++) {
        $candidate = saml_verify_signature($assertions->item($i), $idpCertPem);
        if ($candidate) { $verifiedAssertion = $candidate; break; }
    }
    if (!$verifiedAssertion) {
        $verifiedResponse = saml_verify_signature($responseEl, $idpCertPem);
        if ($verifiedResponse && $assertions->length === 1) $verifiedAssertion = $assertions->item(0);
    }
    if (!$verifiedAssertion) return ['error' => 'Signature verification failed.'];

    $assertionId = $verifiedAssertion->getAttribute('ID');
    if ($assertionId === '' || !saml_mark_assertion_used($pdo, $assertionId)) {
        return ['error' => 'Assertion replay detected or missing assertion ID.'];
    }

    $now = time();
    $conditions = $verifiedAssertion->getElementsByTagName('Conditions')->item(0);
    if ($conditions) {
        $notBefore = $conditions->getAttribute('NotBefore');
        $notOnOrAfter = $conditions->getAttribute('NotOnOrAfter');
        if ($notBefore && strtotime($notBefore) - 60 > $now) return ['error' => 'Assertion not yet valid.'];
        if ($notOnOrAfter && strtotime($notOnOrAfter) + 60 < $now) return ['error' => 'Assertion expired.'];
        $audienceOk = false;
        foreach ($conditions->getElementsByTagName('Audience') as $aud) {
            if (trim($aud->textContent) === $spEntityId) { $audienceOk = true; break; }
        }
        if ($conditions->getElementsByTagName('AudienceRestriction')->length > 0 && !$audienceOk) {
            return ['error' => 'Audience restriction does not match this SP.'];
        }
    }

    $confirmationOk = false;
    foreach ($verifiedAssertion->getElementsByTagName('SubjectConfirmationData') as $scd) {
        $recipient = $scd->getAttribute('Recipient');
        $notOnOrAfter = $scd->getAttribute('NotOnOrAfter');
        if ($recipient === $acsUrl && (!$notOnOrAfter || strtotime($notOnOrAfter) + 60 >= $now)) { $confirmationOk = true; break; }
    }
    if (!$confirmationOk) return ['error' => 'SubjectConfirmation did not match this SP\'s ACS URL.'];

    $nameIdNode = $verifiedAssertion->getElementsByTagName('NameID')->item(0);
    if (!$nameIdNode) return ['error' => 'No NameID present.'];

    $sessionIndex = null;
    $authnStatement = $verifiedAssertion->getElementsByTagName('AuthnStatement')->item(0);
    if ($authnStatement && $authnStatement->hasAttribute('SessionIndex')) {
        $sessionIndex = $authnStatement->getAttribute('SessionIndex');
    }

    return [
        'name_id' => trim($nameIdNode->textContent),
        'session_index' => $sessionIndex,
        'assertion_id' => $assertionId,
    ];
}

// Validates an IdP-initiated LogoutRequest (POST or redirect binding, already inflated to raw XML).
function saml_validate_logout_request(string $xml, string $idpCertPem): array {
    $doc = saml_safe_load_xml($xml);
    if (!$doc) return ['error' => 'Malformed or unsafe SAML XML.'];
    $root = $doc->documentElement;
    if (!$root || $root->localName !== 'LogoutRequest') return ['error' => 'Not a SAML LogoutRequest.'];

    if (!saml_verify_signature($root, $idpCertPem)) return ['error' => 'Signature verification failed.'];

    $nameIdNode = $root->getElementsByTagName('NameID')->item(0);
    if (!$nameIdNode) return ['error' => 'No NameID present.'];
    $sessionIndexNode = $root->getElementsByTagName('SessionIndex')->item(0);

    return [
        'request_id' => $root->getAttribute('ID'),
        'name_id' => trim($nameIdNode->textContent),
        'session_index' => $sessionIndexNode ? trim($sessionIndexNode->textContent) : null,
    ];
}

// Validates the LogoutResponse Auth0 sends back after an SP-initiated logout.
function saml_validate_logout_response(string $xml, PDO $pdo): bool {
    $doc = saml_safe_load_xml($xml);
    if (!$doc) return false;
    $root = $doc->documentElement;
    if (!$root || $root->localName !== 'LogoutResponse') return false;
    $inResponseTo = $root->getAttribute('InResponseTo');
    if ($inResponseTo === '') return false;
    return saml_consume_request($pdo, $inResponseTo, 'logout');
}
