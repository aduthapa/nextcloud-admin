<?php
require 'db.php'; require_login();

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$SYS_PASS     = 'Root_Secret_2025!'; // <--- CHANGE THIS SECURITY PASSWORD
$username = $_SESSION['admin_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) { http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit; }
    $action = $input['action'] ?? '';

    if (($input['sec_pass'] ?? '') !== $SYS_PASS) {
        audit_log($action, null, 'auth_failed');
        echo json_encode(['error' => 'Invalid Security Password.']); exit;
    }

    if ($action === 'duo_enable') {
        $pdo->prepare("INSERT INTO admin_2fa (username, duo_enabled, totp_enabled) VALUES (?, 1, 0)
            ON DUPLICATE KEY UPDATE duo_enabled = 1, totp_enabled = 0")->execute([$username]);
        audit_log('duo_enabled');
        echo json_encode(['status' => 'done']); exit;
    }
    if ($action === 'duo_disable') {
        $pdo->prepare("UPDATE admin_2fa SET duo_enabled = 0 WHERE username = ?")->execute([$username]);
        audit_log('duo_disabled');
        echo json_encode(['status' => 'done']); exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$stmt = $pdo->prepare("SELECT duo_enabled FROM admin_2fa WHERE username = ?");
$stmt->execute([$username]);
$row = $stmt->fetch();
$duoEnabled = $row && $row['duo_enabled'];

$pageTitle = 'Duo Admin';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'admin-settings'; $activeChild = 'duo-admin'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-2xl mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Duo Admin (Cisco)</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">Duo Security, part of Cisco - MFA for your NC Admin login (<?= htmlspecialchars($username) ?>)</p>
            </div>

            <div class="card p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-emerald-500/10 text-emerald-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Duo MFA</h2>
                    </div>
                    <span id="duo-status" class="badge <?= $duoEnabled ? 'bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400' : 'bg-gray-100 dark:bg-surface2 text-gray-500 dark:text-gray-400' ?>"><?= $duoEnabled ? 'ENABLED' : 'DISABLED' ?></span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Uses Duo's Universal Prompt (push/passcode/etc. - whatever methods your account has enrolled in Duo). Your NC Admin username must already exist as a user in Duo before enabling this.</p>
                <div class="flex gap-3">
                    <button id="duo-enable-btn" onclick="setDuo(true)" class="btn btn-outline-green <?= $duoEnabled ? 'hidden' : '' ?>">Enable Duo</button>
                    <button id="duo-disable-btn" onclick="setDuo(false)" class="btn btn-outline-red <?= $duoEnabled ? '' : 'hidden' ?>">Disable Duo</button>
                </div>
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-gray-500/10 text-gray-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Duo Admin Panel</h2>
                    </div>
                    <span class="badge bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400">External</span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">User enrollment, MFA policies, and integration keys are managed in Cisco's own Duo console - this panel only stores an on/off flag per admin.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <a href="https://admin.duosecurity.com/" target="_blank" rel="noopener noreferrer" class="ext-link-card"><span>Duo Admin Panel</span><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                    <a href="https://status.duo.com/" target="_blank" rel="noopener noreferrer" class="ext-link-card"><span>Duo Status</span><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                    <a href="https://duo.com/docs" target="_blank" rel="noopener noreferrer" class="ext-link-card"><span>Duo Docs</span><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                </div>
            </div>
        </div>
    </main>

    <style>
        .ext-link-card { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .65rem 1rem; border-radius: .5rem; border: 1px solid #e5e7eb; font-size: .8rem; font-weight: 600; color: inherit; transition: all .15s; }
        html.dark .ext-link-card { border-color: #232a38; }
        .ext-link-card:hover { border-color: #2dd4bf; color: #0d9488; }
        html.dark .ext-link-card:hover { color: #2dd4bf; }
    </style>

    <script>
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; }
            else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
        }

        async function setDuo(enable) {
            const { value: password } = await Swal.fire({
                title: 'Security Password Required', input: 'password', inputLabel: 'Enter Security Password', showCancelButton: true,
                confirmButtonColor: enable ? '#0d9488' : '#e11d48',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!password) return;
            const res = await fetch('duo-admin.php', { method: 'POST', body: JSON.stringify({ token: '<?= $SECRET_TOKEN ?>', action: enable ? 'duo_enable' : 'duo_disable', sec_pass: password }) });
            const data = await res.json();
            if (data.error) {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
                return;
            }
            location.reload();
        }
    </script>
</body>
</html>
