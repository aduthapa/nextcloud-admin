<?php
require 'db.php'; require_login();
require __DIR__ . '/saml_config.php';

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$username = $_SESSION['admin_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) { http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit; }
    $action = $input['action'] ?? '';

    if ($action === 'saml_unlink') {
        $pdo->prepare("DELETE FROM admin_saml_identities WHERE username = ?")->execute([$username]);
        audit_log('saml_identity_unlinked');
        echo json_encode(['status' => 'done']); exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$stmt = $pdo->prepare("SELECT name_id, created_at FROM admin_saml_identities WHERE username = ?");
$stmt->execute([$username]);
$identity = $stmt->fetch();

$idpConfigured = strpos($SAML_IDP_SSO_URL, 'YOUR_TENANT') === false && strpos($SAML_IDP_X509_CERT, 'CHANGE_THIS') === false;

$pageTitle = 'Auth0 SAML';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'admin-settings'; $activeChild = 'auth0-saml'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-3xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Auth0 SAML</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">SSO login &amp; logout for the NC Admin panel</p>
            </div>

            <?php if (!$idpConfigured): ?>
            <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 text-amber-700 dark:text-amber-400 px-4 py-3 rounded-lg text-sm">
                <strong>Not configured yet.</strong> Add your real Auth0 tenant SSO/SLO URLs and signing certificate in <a href="/settings#integrations" class="underline font-semibold hover:text-amber-800 dark:hover:text-amber-300">Settings &rarr; Integrations</a> before SSO will work.
            </div>
            <?php endif; ?>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-orange-500/10 text-orange-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">SP Metadata</h2>
                    </div>
                    <span class="section-eyebrow">paste into Auth0</span>
                </div>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">SP Entity ID</dt><dd class="text-gray-900 dark:text-gray-200 font-mono text-right break-all"><?= htmlspecialchars($SAML_SP_ENTITY_ID) ?></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">ACS URL (login)</dt><dd class="text-gray-900 dark:text-gray-200 font-mono text-right break-all"><?= htmlspecialchars($SAML_ACS_URL) ?></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">SLS URL (logout)</dt><dd class="text-gray-900 dark:text-gray-200 font-mono text-right break-all"><?= htmlspecialchars($SAML_SLS_URL) ?></dd></div>
                </dl>
                <a href="/saml-metadata.php" target="_blank" rel="noopener noreferrer" class="ext-link mt-4">
                    <span>View full SP metadata XML</span>
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white mb-3">SSO Login</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Redirects to Auth0, then back to this panel's ACS endpoint. Only works for an NC Admin account whose email matches the Auth0 identity.</p>
                    <a href="/saml-login.php" class="btn btn-primary w-full text-center">Test SSO Login</a>
                </div>
                <div class="card p-6">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white mb-3">SSO Logout</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Signing out via the sidebar Logout link already triggers SAML Single Logout automatically when you signed in via SSO.</p>
                    <a href="/?logout=true" class="btn btn-outline-red w-full text-center">Test SSO Logout</a>
                </div>
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Your Linked Identity</h2>
                </div>
                <?php if ($identity): ?>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-mono text-sm text-gray-900 dark:text-gray-200"><?= htmlspecialchars($identity['name_id']) ?></div>
                        <div class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">linked <?= htmlspecialchars($identity['created_at']) ?></div>
                    </div>
                    <button onclick="unlink()" class="btn btn-outline-red py-1.5 px-3 text-xs">Unlink</button>
                </div>
                <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-500">No SAML identity linked yet - one links automatically the first time you log in via Auth0 with a matching email.</p>
                <?php endif; ?>
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-gray-500/10 text-gray-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Auth0 Dashboard</h2>
                    </div>
                    <span class="badge bg-orange-50 dark:bg-orange-950/30 text-orange-600 dark:text-orange-400">External</span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Configure the SAML2 Web App addon (login) and enable Single Logout with the SLS URL above (logout) on Auth0's side.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="https://manage.auth0.com/" target="_blank" rel="noopener noreferrer" class="ext-link-card"><span>Auth0 Dashboard</span><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                    <a href="https://status.auth0.com/" target="_blank" rel="noopener noreferrer" class="ext-link-card"><span>Auth0 Status</span><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                </div>
            </div>
        </div>
    </main>

    <style>
        .ext-link { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; font-weight: 600; color: #0d9488; }
        html.dark .ext-link { color: #2dd4bf; }
        .ext-link:hover { text-decoration: underline; }
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

        async function unlink() {
            const result = await Swal.fire({
                title: 'Unlink SAML identity?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!result.isConfirmed) return;
            await fetch('auth0-saml.php', { method: 'POST', body: JSON.stringify({ token: '<?= $SECRET_TOKEN ?>', action: 'saml_unlink' }) });
            location.reload();
        }
    </script>
</body>
</html>
