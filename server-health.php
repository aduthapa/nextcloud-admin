<?php
require 'db.php'; require_login();

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$DATA_DIR     = __DIR__ . '/data';
$LOCK_FILE    = $DATA_DIR . '/system.lock';

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
    if ($action === 'get_state') {
        echo json_encode(['status' => file_exists($LOCK_FILE) ? 'busy' : 'idle']);
        exit;
    }
    if ($action === 'force_unlock') {
        if (file_exists($LOCK_FILE)) unlink($LOCK_FILE);
        audit_log('force_unlock');
        echo json_encode(['status' => 'done']);
        exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$pageTitle = 'Server Health';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'nextcloud'; $activeChild = 'server-health'; $showStatusBar = true; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Server Health</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">live resource usage</p>
            </div>

            <div class="card p-6 max-w-3xl">
                <div class="flex justify-between mb-6"><h2 class="text-base font-bold text-gray-900 dark:text-white">Live Resources</h2><button onclick="fetchStats()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">RAM</span><span id="ram-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="ram-bar" class="bg-violet-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                    <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">DISK (/)</span><span id="disk-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="disk-bar" class="bg-accent-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                </div>
                <div class="mt-6 pt-6 border-t border-gray-200 dark:border-line text-sm font-mono"><span class="text-gray-500">uptime:</span> <span id="uptime-text" class="ml-2 text-gray-900 dark:text-white">checking...</span></div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'server-health.php';
        let isBusy = false;

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
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_server_stats' }) });
                const data = await res.json();
                document.getElementById('ram-text').textContent = data.mem_text;
                document.getElementById('ram-bar').style.width = data.mem_percent + '%';
                document.getElementById('disk-text').textContent = data.disk_text;
                document.getElementById('disk-bar').style.width = data.disk_percent + '%';
                document.getElementById('uptime-text').textContent = data.uptime;
            } catch (e) {}
        }

        function setBusyState(busy) {
            isBusy = busy;
            const indicator = document.getElementById('status-indicator');
            const unlockBtn = document.getElementById('force-unlock-btn');
            if (busy) {
                indicator.innerHTML = '<span class="text-rose-500 dark:text-rose-400 font-bold animate-pulse">BUSY</span>';
                unlockBtn.classList.remove('hidden');
            } else {
                indicator.innerHTML = '<div class="h-1.5 w-1.5 rounded-full bg-green-500 status-dot-pulse"></div><span class="text-gray-500 dark:text-gray-400">Idle</span>';
                unlockBtn.classList.add('hidden');
            }
        }

        async function forceUnlock() {
            const result = await Swal.fire({
                title: 'Force Unlock?',
                text: 'Only do this if a process is genuinely stuck.',
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Unlock',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!result.isConfirmed) return;

            await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'force_unlock' }) });
            setBusyState(false);
        }

        async function checkSystemState() {
            try {
                const response = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_state' }) });
                const data = await response.json();
                setBusyState(data.status === 'busy');
            } catch (e) {}
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchStats();
            checkSystemState();
            setInterval(checkSystemState, 3000);
        });
    </script>
</body>
</html>
