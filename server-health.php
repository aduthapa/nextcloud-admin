<?php
require 'db.php'; require_login();

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$DATA_DIR     = __DIR__ . '/data';
$LOCK_FILE    = $DATA_DIR . '/system.lock';

// Instantaneous CPU% via a short /proc/stat delta sample (world-readable, no root needed).
function cpu_percent_sample() {
    $read = function () {
        $line = @file('/proc/stat')[0] ?? '';
        $f = preg_split('/\s+/', trim($line));
        $idle = ($f[4] ?? 0) + ($f[5] ?? 0);
        $total = array_sum(array_slice($f, 1, 8));
        return [$idle, $total];
    };
    [$idle1, $total1] = $read();
    usleep(200000);
    [$idle2, $total2] = $read();
    $totald = $total2 - $total1;
    $idled = $idle2 - $idle1;
    if ($totald <= 0) return 0;
    return max(0, min(100, round((($totald - $idled) / $totald) * 100)));
}

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
        $cores = max(1, (int) trim((string) shell_exec('nproc')));
        echo json_encode([
            'mem_percent'  => round(($f[8] / $f[7]) * 100),
            'mem_text'     => "{$f[8]}MB / {$f[7]}MB",
            'disk_percent' => round(($du / $dt) * 100),
            'disk_text'    => round($du / 1e9, 2) . "GB",
            'cpu_percent'  => cpu_percent_sample(),
            'cpu_cores'    => $cores,
            'uptime'       => trim((string) shell_exec('uptime -p')),
        ]);
        exit;
    }
    if ($action === 'get_system_info') {
        $os = 'Unknown';
        if (is_readable('/etc/os-release')) {
            $rel = @parse_ini_file('/etc/os-release');
            $os = $rel['PRETTY_NAME'] ?? 'Unknown';
        }
        $cpuModel = 'Unknown';
        if (is_readable('/proc/cpuinfo')) {
            foreach (file('/proc/cpuinfo') as $line) {
                if (stripos($line, 'model name') === 0) { $cpuModel = trim(explode(':', $line, 2)[1] ?? ''); break; }
            }
        }
        $mysqlVersion = 'Unknown';
        try { $mysqlVersion = $pdo->query('SELECT VERSION()')->fetchColumn(); } catch (Exception $e) {}
        $apacheVersion = trim((string) shell_exec('httpd -v 2>/dev/null | head -n1'));
        if (!$apacheVersion) $apacheVersion = trim((string) shell_exec('apachectl -v 2>/dev/null | head -n1'));
        echo json_encode([
            'hostname'       => gethostname(),
            'os'             => $os,
            'kernel'         => trim((string) shell_exec('uname -r')),
            'cpu_model'      => $cpuModel,
            'cpu_cores'      => max(1, (int) trim((string) shell_exec('nproc'))),
            'load_avg'       => sys_getloadavg(),
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $mysqlVersion,
            'apache_version' => $apacheVersion ?: 'Unknown',
        ]);
        exit;
    }
    if ($action === 'get_processes') {
        $raw = shell_exec('ps aux --sort=-%cpu 2>/dev/null | head -n 11');
        $lines = $raw ? array_filter(explode("\n", trim($raw))) : [];
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) >= 11) {
                $rows[] = ['user' => $parts[0], 'pid' => $parts[1], 'cpu' => $parts[2], 'mem' => $parts[3], 'command' => $parts[10]];
            }
        }
        echo json_encode(['rows' => $rows]);
        exit;
    }
    if ($action === 'get_network_info') {
        $raw = shell_exec("ss -tulnH 2>/dev/null") ?: shell_exec("netstat -tuln 2>/dev/null | tail -n +3");
        $lines = $raw ? array_filter(explode("\n", trim($raw))) : [];
        $ports = [];
        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 5) $ports[] = ['proto' => $cols[0], 'address' => $cols[4]];
        }
        $ifaces = [];
        $dev = @file('/proc/net/dev');
        if ($dev) {
            foreach (array_slice($dev, 2) as $line) {
                if (strpos($line, ':') === false) continue;
                [$name, $rest] = explode(':', trim($line), 2);
                $cols = preg_split('/\s+/', trim($rest));
                $ifaces[] = ['name' => trim($name), 'rx' => round(($cols[0] ?? 0) / 1e6, 1), 'tx' => round(($cols[8] ?? 0) / 1e6, 1)];
            }
        }
        echo json_encode(['ports' => $ports, 'ifaces' => $ifaces]);
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
        <div class="max-w-[1500px] mx-auto space-y-8">
            <div class="mb-0">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Server Health</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">live resource usage, processes &amp; network</p>
            </div>

            <div class="card p-6">
                <div class="flex justify-between mb-6"><h2 class="text-base font-bold text-gray-900 dark:text-white">Live Resources</h2><button onclick="fetchStats()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button></div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">RAM</span><span id="ram-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="ram-bar" class="bg-violet-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                    <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">CPU (<span id="cpu-cores">-</span> cores)</span><span id="cpu-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="cpu-bar" class="bg-orange-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                    <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">DISK (/)</span><span id="disk-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="disk-bar" class="bg-accent-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                </div>

                <div class="mt-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-mono text-gray-500 dark:text-gray-500">history (last ~2.5 min, updates every 5s)</span>
                        <div class="flex items-center gap-4 text-xs font-mono">
                            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-violet-500"></span>RAM</span>
                            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-orange-500"></span>CPU</span>
                        </div>
                    </div>
                    <svg id="history-graph" viewBox="0 0 100 40" preserveAspectRatio="none" class="w-full h-28 bg-gray-50 dark:bg-surface2 rounded-lg border border-gray-200 dark:border-line">
                        <polyline id="ram-line" points="" fill="none" stroke="#8b5cf6" stroke-width="1" vector-effect="non-scaling-stroke"/>
                        <polyline id="cpu-line" points="" fill="none" stroke="#f97316" stroke-width="1" vector-effect="non-scaling-stroke"/>
                    </svg>
                </div>

                <div class="mt-6 pt-6 border-t border-gray-200 dark:border-line text-sm font-mono"><span class="text-gray-500">uptime:</span> <span id="uptime-text" class="ml-2 text-gray-900 dark:text-white">checking...</span></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="icon-badge bg-blue-500/10 text-blue-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-white">System Information</h2>
                        </div>
                        <button onclick="fetchSystemInfo()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                    </div>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">Hostname</dt><dd id="si-hostname" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">OS</dt><dd id="si-os" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">Kernel</dt><dd id="si-kernel" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">CPU</dt><dd id="si-cpu" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">CPU Cores</dt><dd id="si-cores" class="text-gray-900 dark:text-gray-200 font-mono text-right">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">Load Avg (1/5/15)</dt><dd id="si-load" class="text-gray-900 dark:text-gray-200 font-mono text-right">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">PHP</dt><dd id="si-php" class="text-gray-900 dark:text-gray-200 font-mono text-right">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">MySQL</dt><dd id="si-mysql" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-500 shrink-0">Apache</dt><dd id="si-apache" class="text-gray-900 dark:text-gray-200 font-mono text-right truncate">...</dd></div>
                    </dl>
                </div>

                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="icon-badge bg-teal-500/10 text-teal-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.808-3.807 9.98-3.807 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12 20.25h.008v.008H12v-.008z"/></svg></div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-white">Network</h2>
                        </div>
                        <button onclick="fetchNetwork()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                    </div>
                    <div class="mb-4">
                        <h3 class="section-eyebrow mb-2">Interfaces (RX / TX)</h3>
                        <div id="net-ifaces" class="space-y-1.5 text-xs font-mono text-gray-700 dark:text-gray-300">...</div>
                    </div>
                    <div>
                        <h3 class="section-eyebrow mb-2">Listening Ports</h3>
                        <div id="net-ports" class="space-y-1 text-xs font-mono text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto pr-1">...</div>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="icon-badge bg-rose-500/10 text-rose-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 17V7m6 10V7M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Top Processes</h2>
                    </div>
                    <button onclick="fetchProcesses()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-mono text-gray-500 dark:text-gray-500 uppercase border-b border-gray-200 dark:border-line">
                                <th class="pb-2 pr-4">PID</th><th class="pb-2 pr-4">User</th><th class="pb-2 pr-4">CPU%</th><th class="pb-2 pr-4">MEM%</th><th class="pb-2">Command</th>
                            </tr>
                        </thead>
                        <tbody id="proc-rows" class="font-mono text-xs sm:text-sm"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'server-health.php';
        let isBusy = false;
        const HISTORY_LEN = 30;
        let cpuHistory = [];
        let ramHistory = [];

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        function toPoints(arr) {
            if (arr.length < 2) return '';
            return arr.map((v, i) => {
                const x = (i / (HISTORY_LEN - 1)) * 100;
                const y = 40 - (Math.max(0, Math.min(100, v)) / 100) * 40;
                return `${x.toFixed(1)},${y.toFixed(1)}`;
            }).join(' ');
        }

        function drawGraph() {
            document.getElementById('ram-line').setAttribute('points', toPoints(ramHistory));
            document.getElementById('cpu-line').setAttribute('points', toPoints(cpuHistory));
        }

        async function fetchStats() {
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_server_stats' }) });
                const data = await res.json();
                document.getElementById('ram-text').textContent = data.mem_text;
                document.getElementById('ram-bar').style.width = data.mem_percent + '%';
                document.getElementById('disk-text').textContent = data.disk_text;
                document.getElementById('disk-bar').style.width = data.disk_percent + '%';
                document.getElementById('cpu-text').textContent = data.cpu_percent + '%';
                document.getElementById('cpu-bar').style.width = data.cpu_percent + '%';
                document.getElementById('cpu-cores').textContent = data.cpu_cores;
                document.getElementById('uptime-text').textContent = data.uptime;

                ramHistory.push(data.mem_percent); if (ramHistory.length > HISTORY_LEN) ramHistory.shift();
                cpuHistory.push(data.cpu_percent); if (cpuHistory.length > HISTORY_LEN) cpuHistory.shift();
                drawGraph();
            } catch (e) {}
        }

        async function fetchSystemInfo() {
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_system_info' }) });
                const data = await res.json();
                document.getElementById('si-hostname').textContent = data.hostname || '-';
                document.getElementById('si-os').textContent = data.os || '-';
                document.getElementById('si-kernel').textContent = data.kernel || '-';
                document.getElementById('si-cpu').textContent = data.cpu_model || '-';
                document.getElementById('si-cores').textContent = data.cpu_cores || '-';
                document.getElementById('si-load').textContent = (data.load_avg || []).map(n => n.toFixed(2)).join(' / ');
                document.getElementById('si-php').textContent = data.php_version || '-';
                document.getElementById('si-mysql').textContent = data.mysql_version || '-';
                document.getElementById('si-apache').textContent = data.apache_version || '-';
            } catch (e) {}
        }

        async function fetchProcesses() {
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_processes' }) });
                const data = await res.json();
                const tbody = document.getElementById('proc-rows');
                tbody.innerHTML = '';
                (data.rows || []).forEach(row => {
                    const tr = document.createElement('tr');
                    tr.className = 'border-b border-gray-100 dark:border-line last:border-0';
                    [row.pid, row.user, row.cpu + '%', row.mem + '%', row.command].forEach((val, i) => {
                        const td = document.createElement('td');
                        td.className = i === 4 ? 'py-1.5 truncate max-w-[1px]' : 'py-1.5 pr-4 whitespace-nowrap';
                        td.textContent = val;
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
            } catch (e) {}
        }

        async function fetchNetwork() {
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_network_info' }) });
                const data = await res.json();
                const ifaceBox = document.getElementById('net-ifaces');
                ifaceBox.innerHTML = '';
                (data.ifaces || []).forEach(f => {
                    const div = document.createElement('div');
                    div.className = 'flex justify-between';
                    div.innerHTML = `<span class="text-gray-500 dark:text-gray-500"></span>`;
                    div.querySelector('span').textContent = f.name;
                    const val = document.createElement('span');
                    val.textContent = `${f.rx} MB / ${f.tx} MB`;
                    div.appendChild(val);
                    ifaceBox.appendChild(div);
                });
                if (!(data.ifaces || []).length) ifaceBox.textContent = 'No interface data available.';

                const portBox = document.getElementById('net-ports');
                portBox.innerHTML = '';
                (data.ports || []).forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'flex justify-between';
                    const proto = document.createElement('span'); proto.className = 'text-gray-500 dark:text-gray-500 uppercase'; proto.textContent = p.proto;
                    const addr = document.createElement('span'); addr.textContent = p.address;
                    div.appendChild(proto); div.appendChild(addr);
                    portBox.appendChild(div);
                });
                if (!(data.ports || []).length) portBox.textContent = 'No listening ports found.';
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
            fetchSystemInfo();
            fetchProcesses();
            fetchNetwork();
            checkSystemState();
            setInterval(fetchStats, 5000);
            setInterval(fetchProcesses, 8000);
            setInterval(checkSystemState, 3000);
        });
    </script>
</body>
</html>
