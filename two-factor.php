<?php
require 'db.php'; require_login();
require __DIR__ . '/lib/totp.php';

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$SYS_PASS     = 'Root_Secret_2025!'; // <--- CHANGE THIS SECURITY PASSWORD
$username = $_SESSION['admin_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) { http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit; }
    $action = $input['action'] ?? '';

    if ($action === 'totp_generate') {
        $secret = totp_generate_secret();
        $_SESSION['pending_totp_secret'] = $secret;
        echo json_encode(['secret' => $secret, 'uri' => totp_provisioning_uri($secret, $username, 'NC Admin')]);
        exit;
    }

    if ($action === 'totp_confirm') {
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        if (!$secret || !totp_verify($secret, $input['code'] ?? '')) {
            echo json_encode(['error' => 'Invalid code. Check the app and try again.']); exit;
        }
        $pdo->prepare("INSERT INTO admin_2fa (username, totp_secret, totp_enabled, duo_enabled) VALUES (?, ?, 1, 0)
            ON DUPLICATE KEY UPDATE totp_secret = VALUES(totp_secret), totp_enabled = 1, duo_enabled = 0")->execute([$username, $secret]);
        unset($_SESSION['pending_totp_secret']);
        audit_log('totp_enabled');
        echo json_encode(['status' => 'done']);
        exit;
    }

    if ($action === 'totp_disable') {
        if (($input['sec_pass'] ?? '') !== $SYS_PASS) {
            audit_log('totp_disable', null, 'auth_failed');
            echo json_encode(['error' => 'Invalid Security Password.']); exit;
        }
        $pdo->prepare("UPDATE admin_2fa SET totp_enabled = 0 WHERE username = ?")->execute([$username]);
        audit_log('totp_disabled');
        echo json_encode(['status' => 'done']);
        exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$stmt = $pdo->prepare("SELECT totp_enabled FROM admin_2fa WHERE username = ?");
$stmt->execute([$username]);
$row = $stmt->fetch();
$totpEnabled = $row && $row['totp_enabled'];

$pageTitle = 'Two-Factor';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'admin-settings'; $activeChild = 'two-factor'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-2xl mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Two-Factor</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">TOTP for your NC Admin login (<?= htmlspecialchars($username) ?>)</p>
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-violet-500/10 text-violet-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Authenticator App</h2>
                    </div>
                    <span id="tfa-status" class="badge <?= $totpEnabled ? 'bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400' : 'bg-gray-100 dark:bg-surface2 text-gray-500 dark:text-gray-400' ?>"><?= $totpEnabled ? 'ENABLED' : 'DISABLED' ?></span>
                </div>

                <div id="tfa-disabled-view" class="<?= $totpEnabled ? 'hidden' : '' ?>">
                    <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">After enabling, a 6-digit code from your authenticator app will be required every time you log in with your password.</p>
                    <button onclick="startEnroll()" class="btn btn-primary">Enable 2FA</button>
                    <div id="enroll-box" class="hidden mt-5 space-y-4">
                        <div class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line rounded-lg p-4">
                            <div class="text-xs text-gray-500 dark:text-gray-500 mb-1">Secret key (enter manually in your app)</div>
                            <div id="totp-secret" class="font-mono text-sm text-gray-900 dark:text-white break-all"></div>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="confirm-code" placeholder="6-digit code" inputmode="numeric" maxlength="6" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 transition">
                            <button onclick="confirmEnroll()" class="btn btn-outline-green">Confirm &amp; Enable</button>
                        </div>
                    </div>
                </div>

                <div id="tfa-enabled-view" class="<?= $totpEnabled ? '' : 'hidden' ?>">
                    <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">2FA is protecting your account. Disabling it removes the second step from your password login.</p>
                    <button onclick="disableTfa()" class="btn btn-outline-red">Disable 2FA</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'two-factor.php';

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; }
            else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
        }

        function toast(icon, title) {
            Swal.fire({ icon, title, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
        }

        async function startEnroll() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'totp_generate' }) });
            const data = await res.json();
            document.getElementById('totp-secret').textContent = data.secret;
            document.getElementById('enroll-box').classList.remove('hidden');
        }

        async function confirmEnroll() {
            const code = document.getElementById('confirm-code').value.trim();
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'totp_confirm', code }) });
            const data = await res.json();
            if (data.error) { toast('error', data.error); return; }
            toast('success', '2FA enabled');
            setTimeout(() => location.reload(), 1000);
        }

        async function disableTfa() {
            const { value: password } = await Swal.fire({
                title: 'Security Password Required', input: 'password', inputLabel: 'Enter Security Password', showCancelButton: true,
                confirmButtonColor: '#e11d48',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!password) return;
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'totp_disable', sec_pass: password }) });
            const data = await res.json();
            if (data.error) { toast('error', data.error); return; }
            toast('success', '2FA disabled');
            setTimeout(() => location.reload(), 1000);
        }
    </script>
</body>
</html>
