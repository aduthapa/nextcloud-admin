<?php
// Minimal Hetzner Cloud API v1 client. Every call here is a plain outbound
// HTTPS request with a Bearer token - no local sudo/RUNNER involved.
//
// Intentionally never implemented: DELETE /servers/{id}. This file must
// never gain a "delete the server" action - that's the one thing this whole
// page is scoped to leave alone.

const HETZNER_API_BASE = 'https://api.hetzner.cloud/v1';

function hetzner_request(string $token, string $method, string $path, ?array $body = null): array {
    $ch = curl_init(HETZNER_API_BASE . $path);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => "Connection failed: $curlErr"];
    $data = json_decode($raw, true);
    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['ok' => false, 'error' => $msg, 'http_code' => $httpCode];
    }
    return ['ok' => true, 'data' => $data];
}

// Returns [serverId, error]. If $HETZNER_SERVER_ID is set, uses it directly
// (no API call). Otherwise lists servers and auto-picks when there's exactly one.
function hetzner_resolve_server_id(string $token, ?int $configuredId): array {
    if ($configuredId !== null) return [$configuredId, null];
    $res = hetzner_request($token, 'GET', '/servers?per_page=50');
    if (!$res['ok']) return [null, $res['error']];
    $servers = $res['data']['servers'] ?? [];
    if (count($servers) === 1) return [$servers[0]['id'], null];
    if (count($servers) === 0) return [null, 'No servers found in this Hetzner project.'];
    return [null, 'Multiple servers found - set $HETZNER_SERVER_ID in hetzner_config.php to one of: ' . implode(', ', array_map(fn($s) => $s['id'] . ' (' . $s['name'] . ')', $servers))];
}
