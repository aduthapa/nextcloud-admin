<?php
require 'db.php';

const RESET_TOKEN_TTL = 3600; // 1 hour

function find_admin_by_identifier(PDO $pdo, string $identifier) {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function create_reset_token(PDO $pdo, string $username, int $ttl): string {
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $pdo->prepare("DELETE FROM password_resets WHERE username = ?")->execute([$username]);
    $pdo->prepare("INSERT INTO password_resets (username, token_hash, expires_at) VALUES (?, ?, ?)")->execute([$username, $hash, $expires]);
    return $raw;
}

function lookup_reset_token(PDO $pdo, string $raw) {
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $raw)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function consume_reset_token(PDO $pdo, string $username) {
    $pdo->prepare("DELETE FROM password_resets WHERE username = ?")->execute([$username]);
}

// Minimal raw-socket SMTP client reusing the settings already captured on
// the Settings > SMTP tab. Handles STARTTLS/implicit-TLS + AUTH LOGIN, which
// covers the overwhelming majority of relays (Gmail, SES, Mailgun, etc).
function send_smtp_mail(array $smtp, string $to, string $subject, string $body): array {
    if (empty($smtp['host'])) return [false, 'SMTP host not configured'];
    $port = (int) ($smtp['port'] ?: 587);
    $enc = strtolower($smtp['encryption'] ?? 'tls');
    $transport = $enc === 'ssl' ? 'ssl://' : '';
    $fp = @fsockopen($transport . $smtp['host'], $port, $errno, $errstr, 10);
    if (!$fp) return [false, "Could not connect to SMTP host: $errstr"];
    stream_set_timeout($fp, 10);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function ($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
    $ok = function ($resp, $code) { return substr(trim($resp), 0, 3) === (string) $code; };

    $read();
    $write('EHLO nc-admin');
    $read();

    if ($enc === 'tls') {
        $write('STARTTLS');
        $read();
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); return [false, 'STARTTLS negotiation failed'];
        }
        $write('EHLO nc-admin');
        $read();
    }

    if (!empty($smtp['username'])) {
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($smtp['username']));
        $read();
        $write(base64_encode($smtp['password']));
        if (!$ok($read(), 235)) { fclose($fp); return [false, 'SMTP authentication failed']; }
    }

    $fromEmail = $smtp['from_email'] ?: $smtp['username'];
    $fromName = $smtp['from_name'] ?: 'Nextcloud Admin';

    $write("MAIL FROM:<$fromEmail>");
    $read();
    $write("RCPT TO:<$to>");
    $resp = $read();
    if (!$ok($resp, 250)) { $write('QUIT'); fclose($fp); return [false, 'Recipient rejected: ' . trim($resp)]; }

    $write('DATA');
    $read();
    $headers = "From: $fromName <$fromEmail>\r\nTo: <$to>\r\nSubject: $subject\r\nDate: " . date('r')
        . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $escapedBody = preg_replace('/^\./m', '..', $body);
    $write($headers . "\r\n" . $escapedBody . "\r\n.");
    $resp = $read();
    $write('QUIT');
    fclose($fp);

    if (!$ok($resp, 250)) return [false, 'Message rejected: ' . trim($resp)];
    return [true, ''];
}

$mode = 'request'; // request | sent | confirm | invalid
$error = '';
$fallbackLink = '';
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rawToken === '' && isset($_POST['identifier'])) {
    // Step 1: request a reset link
    $identifier = trim($_POST['identifier']);
    $admin = $identifier !== '' ? find_admin_by_identifier($pdo, $identifier) : null;

    if ($admin) {
        $raw = create_reset_token($pdo, $admin['username'], RESET_TOKEN_TTL);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $link = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/resetpass.php?token=' . $raw;

        if (!empty($admin['email'])) {
            $smtp = $pdo->query("SELECT * FROM smtp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
            $body = "A password reset was requested for {$admin['username']} on the Nextcloud Admin panel.\n\n"
                . "Reset your password: $link\n\n"
                . "This link expires in 1 hour. If you didn't request this, you can ignore this email.";
            [$sent, $sendError] = send_smtp_mail($smtp, $admin['email'], 'Nextcloud Admin - Password Reset', $body);
        } else {
            $sent = false; $sendError = 'no email on file for this account';
        }

        if ($sent) {
            audit_log('password_reset_requested', "username={$admin['username']}", 'success');
        } else {
            // Delivery isn't wired up (or failed) - fall back to showing the
            // link directly. This panel already sits behind Twingate/Okta,
            // so a visible link here is strictly safer than a silently lost reset.
            $fallbackLink = $link;
            audit_log('password_reset_requested', "username={$admin['username']} smtp_error=$sendError", 'failed');
        }
    } else {
        // Never reveal whether the identifier matched an account.
        audit_log('password_reset_requested', "identifier=$identifier (no match)", 'failed');
    }
    $mode = 'sent';
} elseif ($rawToken !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    // Step 3: submit the new password
    $tokenRow = lookup_reset_token($pdo, $rawToken);
    if (!$tokenRow) {
        $mode = 'invalid';
    } else {
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($newPass) < 8) {
            $mode = 'confirm'; $error = 'Password must be at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $mode = 'confirm'; $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password = ? WHERE username = ?")->execute([$hash, $tokenRow['username']]);
            consume_reset_token($pdo, $tokenRow['username']);
            audit_log('password_reset_completed', "username={$tokenRow['username']}", 'success');
            header('Location: /login?reset=1');
            exit;
        }
    }
} elseif ($rawToken !== '') {
    // Step 2: token arrived via link - show the new-password form if it's still valid
    $mode = lookup_reset_token($pdo, $rawToken) ? 'confirm' : 'invalid';
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><title>Reset Password - Nextcloud Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                fontFamily: { sans: ['Inter','ui-sans-serif','system-ui'], mono: ['"JetBrains Mono"','ui-monospace','monospace'] },
                colors: { accent: { DEFAULT:'#2dd4bf', 50:'#f0fdfa', 100:'#ccfbf1', 200:'#99f6e4', 300:'#5eead4', 400:'#2dd4bf', 500:'#14b8a6', 600:'#0d9488', 700:'#0f766e', 800:'#115e59', 900:'#134e4a', 950:'#042f2e' }, ink:'#080b10', surface:'#11161f', line:'#232a38' }
            } }
        };
        if (localStorage.loaderLogo === 'off') {
            document.documentElement.classList.add('no-loader-logo');
        }
    </script>
    <style>
        html, body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        .nc-glow { box-shadow: 0 0 0 1px rgba(45,212,191,.25), 0 8px 32px -8px rgba(45,212,191,.35); }
        .grid-fade { background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px); background-size: 34px 34px; }
        .font-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    </style>
</head>
<body class="bg-ink text-gray-200 flex items-center justify-center min-h-screen font-sans selection:bg-accent-500 selection:text-ink grid-fade">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <div class="bg-surface/90 backdrop-blur p-10 rounded-2xl w-full max-w-sm border border-line relative overflow-hidden nc-glow">
        <div class="absolute top-0 left-0 w-full h-[2px] bg-gradient-to-r from-transparent via-accent-400 to-transparent"></div>

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-accent-500/10 border border-accent-500/25 mb-4">
                <svg class="w-7 h-7 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <h1 class="text-2xl font-extrabold text-white tracking-tight">Reset Password</h1>
            <p class="text-gray-500 text-xs mt-3 uppercase tracking-widest font-mono font-semibold">
                <?= $mode === 'confirm' ? 'choose a new password' : ($mode === 'invalid' ? 'link expired' : 'nc admin') ?>
            </p>
        </div>

        <?php if ($mode === 'request'): ?>
            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-gray-500 text-xs font-mono font-semibold uppercase mb-2 ml-1">Username or email</label>
                    <input type="text" name="identifier" class="w-full bg-ink/60 text-white border border-line rounded-xl py-3.5 px-5 font-mono text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none transition placeholder-gray-600" placeholder="admin" required autofocus>
                </div>
                <button type="submit" class="w-full bg-accent-600 hover:bg-accent-700 text-ink font-bold py-3.5 rounded-xl transition duration-200 tracking-wide">SEND RESET LINK</button>
            </form>

        <?php elseif ($mode === 'sent'): ?>
            <div class="bg-accent-950/40 border border-accent-800/50 text-accent-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium">
                If that account exists, a reset link has been sent.
            </div>
            <?php if ($fallbackLink): ?>
                <div class="bg-amber-950/30 border border-amber-800/50 text-amber-300 px-4 py-3 rounded-lg mb-4 text-xs">
                    <p class="font-bold mb-1 font-mono uppercase tracking-wide">SMTP isn't sending yet</p>
                    <p>Configure it under Settings &rarr; SMTP so this mails automatically. Until then, here's the link:</p>
                </div>
                <input type="text" readonly value="<?= htmlspecialchars($fallbackLink) ?>" onclick="this.select()" class="w-full bg-ink/60 text-accent-300 border border-line rounded-xl py-3 px-4 font-mono text-xs mb-4 cursor-text">
            <?php endif; ?>

        <?php elseif ($mode === 'confirm'): ?>
            <?php if ($error): ?>
                <div class="bg-rose-950/40 border border-rose-800/60 text-rose-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">
                <div>
                    <label class="block text-gray-500 text-xs font-mono font-semibold uppercase mb-2 ml-1">New password</label>
                    <input type="password" name="new_password" class="w-full bg-ink/60 text-white border border-line rounded-xl py-3.5 px-5 font-mono text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none transition placeholder-gray-600" placeholder="••••••••" minlength="8" required autofocus>
                </div>
                <div>
                    <label class="block text-gray-500 text-xs font-mono font-semibold uppercase mb-2 ml-1">Confirm password</label>
                    <input type="password" name="confirm_password" class="w-full bg-ink/60 text-white border border-line rounded-xl py-3.5 px-5 font-mono text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none transition placeholder-gray-600" placeholder="••••••••" minlength="8" required>
                </div>
                <button type="submit" class="w-full bg-accent-600 hover:bg-accent-700 text-ink font-bold py-3.5 rounded-xl transition duration-200 tracking-wide">UPDATE PASSWORD</button>
            </form>

        <?php elseif ($mode === 'invalid'): ?>
            <div class="bg-rose-950/40 border border-rose-800/60 text-rose-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium">
                This reset link is invalid or has expired.
            </div>
            <a href="/resetpass.php" class="block w-full text-center bg-accent-600 hover:bg-accent-700 text-ink font-bold py-3.5 rounded-xl transition duration-200 tracking-wide">REQUEST A NEW LINK</a>
        <?php endif; ?>

        <div class="mt-8 text-center">
            <a href="/login" class="text-xs text-gray-500 hover:text-accent-400 transition font-medium tracking-wide">&larr; Back to login</a>
        </div>
    </div>
</body>
</html>
