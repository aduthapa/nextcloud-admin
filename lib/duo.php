<?php
// Cisco Duo "Universal Prompt" client (OAuth2 Authorization Code + a
// client_assertion JWT). This is Duo's current supported integration
// method - the old signed-cookie "Traditional Prompt" (Duo Web SDK v2)
// is deprecated and can no longer be provisioned for new Duo applications.

function duo_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function duo_b64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function duo_build_jwt(array $claims, string $secret): string {
    $header = duo_b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS512']));
    $payload = duo_b64url(json_encode($claims));
    $signature = duo_b64url(hash_hmac('sha512', "$header.$payload", $secret, true));
    return "$header.$payload.$signature";
}

// Verifies signature + exp/iat + aud/iss, returns the decoded claims or null.
function duo_verify_jwt(string $jwt, string $secret, string $expectedAud): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = duo_b64url(hash_hmac('sha512', "$header.$payload", $secret, true));
    if (!hash_equals($expected, $sig)) return null;

    $claims = json_decode(duo_b64url_decode($payload), true);
    if (!is_array($claims)) return null;
    $now = time();
    if (!isset($claims['exp']) || $now > (int) $claims['exp'] + 30) return null;
    if (!isset($claims['iat']) || $now < (int) $claims['iat'] - 30) return null;
    if (($claims['aud'] ?? null) !== $expectedAud) return null;
    return $claims;
}

// Builds the URL to send the browser to for a Duo Universal Prompt login.
function duo_create_auth_url(string $apiHost, string $clientId, string $clientSecret, string $redirectUri, string $username, string $state): string {
    $now = time();
    $request = duo_build_jwt([
        'response_type' => 'code',
        'scope' => 'openid',
        'exp' => $now + 300,
        'iat' => $now,
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'duo_uname' => $username,
    ], $clientSecret);

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'request' => $request,
    ]);
    return "https://$apiHost/oauth/v1/authorize?$query";
}

// Exchanges the duo_code from the callback for a verified id_token. Returns
// ['username' => ..., 'allowed' => bool] on a structurally valid response,
// or null if the token could not be validated at all.
function duo_exchange_code(string $apiHost, string $clientId, string $clientSecret, string $redirectUri, string $duoCode): ?array {
    $now = time();
    $tokenEndpoint = "https://$apiHost/oauth/v1/token";
    $clientAssertion = duo_build_jwt([
        'iss' => $clientId,
        'sub' => $clientId,
        'aud' => $tokenEndpoint,
        'exp' => $now + 60,
        'iat' => $now,
        'jti' => bin2hex(random_bytes(16)),
    ], $clientSecret);

    $body = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $duoCode,
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $clientAssertion,
    ]);

    $ch = curl_init($tokenEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!isset($data['id_token'])) return null;

    $claims = duo_verify_jwt($data['id_token'], $clientSecret, $clientId);
    if ($claims === null) return null;

    return [
        'username' => $claims['preferred_username'] ?? null,
        'allowed' => (($claims['auth_result']['status'] ?? '') === 'allow'),
        'raw' => $claims,
    ];
}
