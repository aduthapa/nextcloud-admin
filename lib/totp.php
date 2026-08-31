<?php
// RFC 6238 TOTP (30s step, SHA-1, 6 digits) - compatible with Google
// Authenticator / Authy / any standard authenticator app.

function totp_generate_secret(): string {
    return base32_encode(random_bytes(20));
}

function totp_provisioning_uri(string $secret, string $label, string $issuer): string {
    $params = http_build_query(['secret' => $secret, 'issuer' => $issuer], '', '&', PHP_QUERY_RFC3986);
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?' . $params;
}

function totp_code(string $secret, ?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, 30);
    $key = base32_decode($secret);
    $binCounter = pack('N*', 0, $counter); // 64-bit big-endian counter
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

// Accepts a code from the previous/current/next 30s window to tolerate clock drift.
function totp_verify(string $secret, string $code): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    foreach ([-30, 0, 30] as $offset) {
        if (hash_equals(totp_code($secret, $now + $offset), $code)) return true;
    }
    return false;
}

function base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0');
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function base32_decode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $data));
    $bits = '';
    foreach (str_split($data) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) break;
        $output .= chr(bindec($byte));
    }
    return $output;
}
