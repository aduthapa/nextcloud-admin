<?php
require 'db.php'; require_login();
if (isset($_GET['logout'])) {
    audit_log('logout');
    if (!empty($_SESSION['saml_auth'])) {
        require __DIR__ . '/saml_config.php';
        require __DIR__ . '/lib/saml.php';
        $nameId = $_SESSION['saml_name_id'] ?? '';
        $sessionIndex = $_SESSION['saml_session_index'] ?? null;
        session_destroy();
        if ($nameId !== '') {
            $requestId = saml_new_id();
            saml_store_request($pdo, $requestId, 'logout');
            header("Location: " . saml_build_logout_request($SAML_SP_ENTITY_ID, $SAML_IDP_SLO_URL, $requestId, $nameId, $sessionIndex));
            exit;
        }
    }
    session_destroy();
    header("Location: /login?loggedout=1"); exit;
}

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';

// ==========================================
// BACKEND LOGIC (just enough for the live resource widget)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (($input['token'] ?? '') !== $SECRET_TOKEN) {
        http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit;
    }
    $action = $input['action'] ?? '';

    if ($action === 'get_server_stats') {
        $f = preg_split("/\s+/", trim(shell_exec('free -m')));
        $dt = disk_total_space("/");
        $du = $dt - disk_free_space("/");
        echo json_encode([
            'mem_percent'  => round(($f[8] / $f[7]) * 100),
            'mem_text'     => "{$f[8]}MB / {$f[7]}MB",
            'disk_percent' => round(($du / $dt) * 100),
            'disk_text'    => round($du / 1e9, 2) . "GB",
            'uptime'       => shell_exec('uptime -p'),
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$pageTitle = 'NC Dashboard';

// Recent admin activity (server-rendered, no AJAX needed for a read-only panel)
$recentActivity = [];
try {
    $recentActivity = $pdo->query("SELECT username, action, status, ip_address, created_at FROM admin_audit_log ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$quickLinks = [
    ['Nextcloud Ops', 'One-click occ commands, maintenance & diagnostics', '/nextcloud.php', 'accent',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z"/>'],
    ['Database', 'Browse databases, users & run queries', '/database', 'blue',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>'],
    ['Settings', 'SMTP, loader timing & panel preferences', '/settings', 'amber',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'],
    ['Config', 'Read & edit config.php values', '/ncconfig', 'violet',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 20l4-16M6 8L2 12l4 4M18 8l4 4-4 4"/>'],
    ['Audit Log', 'Full history of admin actions', '/audit.php', 'fuchsia',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
    ['Security', 'ModSecurity, WAF & stealth controls', '/security.php', 'rose',
        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
];
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'dashboard'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Dashboard</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">welcome back<?= isset($_SESSION['admin_user']) ? ', ' . htmlspecialchars($_SESSION['admin_user']) : '' ?></p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="card p-5">
                    <div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">RAM</span><span id="ram-text" class="text-gray-900 dark:text-white font-mono">...</span></div>
                    <div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="ram-bar" class="bg-violet-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div>
                </div>
                <div class="card p-5">
                    <div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">DISK (/)</span><span id="disk-text" class="text-gray-900 dark:text-white font-mono">...</span></div>
                    <div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="disk-bar" class="bg-accent-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div>
                </div>
                <div class="card p-5 flex flex-col justify-center">
                    <span class="text-gray-500 dark:text-gray-400 font-mono text-sm mb-1">UPTIME</span>
                    <span id="uptime-text" class="text-gray-900 dark:text-white font-mono text-sm">checking...</span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                <div class="xl:col-span-8">
                    <h2 class="section-eyebrow mb-3">Quick Access</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php foreach ($quickLinks as [$title, $desc, $href, $color, $iconPath]): ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="card p-6 hover:border-accent-500/50 transition group">
                            <div class="icon-badge bg-<?= $color ?>-500/10 text-<?= $color ?>-500 mb-4">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $iconPath ?></svg>
                            </div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white mb-1 group-hover:text-accent-600 dark:group-hover:text-accent-400 transition"><?= htmlspecialchars($title) ?></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-500"><?= htmlspecialchars($desc) ?></p>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="xl:col-span-4">
                    <h2 class="section-eyebrow mb-3">Recent Activity</h2>
                    <div class="card p-6">
                        <?php if (empty($recentActivity)): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-500">No activity recorded yet.</p>
                        <?php else: ?>
                        <div class="space-y-1">
                            <?php foreach ($recentActivity as $row): ?>
                            <div class="flex items-start justify-between gap-3 py-2.5 border-b border-gray-100 dark:border-line last:border-0 text-sm">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="w-1.5 h-1.5 rounded-full shrink-0 <?= $row['status'] === 'success' ? 'bg-accent-500' : ($row['status'] === 'auth_failed' ? 'bg-rose-500' : 'bg-amber-500') ?>"></span>
                                        <span class="font-mono text-gray-900 dark:text-gray-200 truncate"><?= htmlspecialchars($row['action']) ?></span>
                                    </div>
                                    <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate">by <?= htmlspecialchars($row['username']) ?> &middot; <?= htmlspecialchars($row['ip_address'] ?? 'unknown') ?></div>
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-500 font-mono shrink-0 whitespace-nowrap"><?= htmlspecialchars($row['created_at']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="/audit.php" class="block text-center text-xs font-mono font-bold text-accent-600 dark:text-accent-400 hover:underline mt-4 pt-4 border-t border-gray-100 dark:border-line">VIEW FULL AUDIT LOG &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        async function fetchStats() {
            try {
                const res = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: '<?= $SECRET_TOKEN ?>', action: 'get_server_stats' }) });
                const data = await res.json();
                document.getElementById('ram-text').textContent = data.mem_text;
                document.getElementById('ram-bar').style.width = data.mem_percent + '%';
                document.getElementById('disk-text').textContent = data.disk_text;
                document.getElementById('disk-bar').style.width = data.disk_percent + '%';
                document.getElementById('uptime-text').textContent = data.uptime;
            } catch (e) {}
        }

        window.addEventListener('DOMContentLoaded', fetchStats);
    </script>
</body>
</html>
