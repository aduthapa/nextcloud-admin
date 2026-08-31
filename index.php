<?php
require 'db.php'; require_login();
if (isset($_GET['logout'])) { audit_log('logout'); session_destroy(); header("Location: /login"); exit; }

// ==========================================
// CONFIGURATION
// ==========================================
$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$SYS_PASS     = 'Root_Secret_2025!'; // <--- CHANGE THIS SECURITY PASSWORD
$DATA_DIR     = __DIR__ . '/data';
$LOCK_FILE    = $DATA_DIR . '/system.lock';
$RUNNER       = 'sudo /usr/local/bin/nc-runner'; // The Gatekeeper

// Ensure data directory exists
if (!is_dir($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); chown($DATA_DIR, 'apache'); }

// ==========================================
// COMMAND DEFINITIONS
// ==========================================
$COMMANDS = [
    // --- MODSECURITY (Requires Auth) ---
    'modsec_status' => ['type' => 'sync', 'cmd' => "$RUNNER modsec_status"],
    'modsec_on'     => ['type' => 'sync', 'cmd' => "$RUNNER modsec_on", 'auth' => true],
    'modsec_off'    => ['type' => 'sync', 'cmd' => "$RUNNER modsec_off", 'auth' => true],

    // --- NEXTCLOUD OPERATIONS (No Auth Required - handled by login) ---
    'status'        => ['type' => 'sync', 'cmd' => "$RUNNER occ status"],
    'app_list'      => ['type' => 'sync', 'cmd' => "$RUNNER occ app:list"],
    'maint_on'      => ['type' => 'sync', 'cmd' => "$RUNNER occ maint:on"],
    'maint_off'     => ['type' => 'sync', 'cmd' => "$RUNNER occ maint:off"],
    'trash_clean'   => ['type' => 'sync', 'cmd' => "$RUNNER occ trash:clean"],
    'versions_clean'=> ['type' => 'sync', 'cmd' => "$RUNNER occ versions:clean"],
    'cache_clear'   => ['type' => 'sync', 'cmd' => "$RUNNER occ cache:clear"],
    'db_missing'    => ['type' => 'async','cmd' => "$RUNNER occ db:indices", 'log' => 'db_indices.log'],
    'files_scan'    => ['type' => 'async','cmd' => "$RUNNER occ files:scan", 'log' => 'files_scan.log'],
    'preview_gen'   => ['type' => 'async','cmd' => "$RUNNER occ preview:gen", 'log' => 'preview_gen.log'],
    'update_apps'   => ['type' => 'async','cmd' => "$RUNNER occ app:update", 'log' => 'app_update.log'],
    'app_enable'    => ['type' => 'sync', 'cmd' => "$RUNNER occ app:enable"],
    'app_disable'   => ['type' => 'sync', 'cmd' => "$RUNNER occ app:disable"],
    'log_truncate'  => ['type' => 'sync', 'cmd' => 'truncate -s 0 /var/www/html/nextcloud/data/nextcloud.log && echo "Log Truncated."'],

    // --- SYSTEM ADMIN (Requires Security Password) ---
    'sys_update'    => ['type' => 'async', 'cmd' => "$RUNNER sys_update", 'log' => 'sys_update.log', 'auth' => true],
    'reboot'        => ['type' => 'async', 'cmd' => "$RUNNER reboot", 'log' => 'reboot.log', 'auth' => true],
    'restart_httpd' => ['type' => 'sync', 'cmd' => "$RUNNER restart_httpd", 'auth' => true],
    'restart_php'   => ['type' => 'sync', 'cmd' => "$RUNNER restart_php", 'auth' => true],
    'firewall_status'=>['type' => 'sync', 'cmd' => "$RUNNER firewall_status"],
    'kill_stuck'    => ['type' => 'sync', 'cmd' => "$RUNNER kill_stuck"],
];

// Actions that are read-only / polled on an interval - kept out of the audit trail to avoid noise.
$AUDIT_SKIP = ['status', 'app_list', 'modsec_status', 'firewall_status'];

// ==========================================
// BACKEND LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // 1. Token Check
    if (($input['token'] ?? '') !== $SECRET_TOKEN) {
        http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit;
    }
    $action = $input['action'] ?? '';

    // 2. Helper Actions
    if ($action==='force_unlock'){if(file_exists($LOCK_FILE))unlink($LOCK_FILE);audit_log('force_unlock');echo json_encode(['status'=>'done','output'=>'Unlocked']);exit;}
    if ($action==='get_server_stats'){$f=preg_split("/\s+/",trim(shell_exec('free -m')));$dt=disk_total_space("/");$du=$dt-disk_free_space("/");echo json_encode(['mem_percent'=>round(($f[8]/$f[7])*100),'mem_text'=>"{$f[8]}MB / {$f[7]}MB",'disk_percent'=>round(($du/$dt)*100),'disk_text'=>round($du/1e9,2)."GB",'uptime'=>shell_exec('uptime -p')]);exit;}
    if ($action==='get_state'){if(file_exists($LOCK_FILE))echo json_encode(['status'=>'busy','log_file'=>file_get_contents($LOCK_FILE)]);else echo json_encode(['status'=>'idle']);exit;}
    if ($action==='read_log'){$l=$DATA_DIR.'/'.basename($input['file']);if(file_exists($l))echo json_encode(['output'=>shell_exec("tail -n 50 ".escapeshellarg($l))]);else echo json_encode(['output'=>'Log missing...']);exit;}

    // 3. Command Validation
    if (!isset($COMMANDS[$action])) { echo json_encode(['error'=>'Unknown command']); exit; }
    if (file_exists($LOCK_FILE)) { echo json_encode(['error'=>'System Busy.']); exit; }

    $config = $COMMANDS[$action];
    $cmd = $config['cmd'];

    // 4. SECURITY PASSWORD CHECK
    if (isset($config['auth']) && $config['auth'] === true) {
        $providedPass = $input['sec_pass'] ?? '';
        if ($providedPass !== $SYS_PASS) {
            audit_log($action, null, 'auth_failed');
            echo json_encode(['error' => 'Invalid Security Password.']);
            exit;
        }
    }

    // 5. Argument Handling
    if (in_array($action, ['app_enable','app_disable'])) {
        if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $input['arg'])) $cmd .= ' '.escapeshellarg($input['arg']);
        else { echo json_encode(['error'=>'Invalid App ID']); exit; }
    }

    // 5b. Audit Trail
    if (!in_array($action, $AUDIT_SKIP)) {
        audit_log($action, $input['arg'] ?? null);
    }

    // 6. Execution
    if ($config['type'] === 'sync') {
        $out = shell_exec($cmd.' 2>&1');
        echo json_encode(['status'=>'done','output'=>$out]);
    } else {
        $l=$config['log']; $lf=$DATA_DIR.'/'.$l; if(!file_exists($lf))touch($lf);
        shell_exec("nohup sh -c 'echo \"$l\" > $LOCK_FILE; $cmd; rm $LOCK_FILE' > $lf 2>&1 &");
        echo json_encode(['status'=>'started','log_file'=>$l]);
    }
    exit;
}

$pageTitle = 'NC Dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-ink flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-14 h-14 border-4 border-accent-700/30 border-t-accent-400 rounded-full animate-spin"></div><div class="mt-4 text-accent-400 font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div></div>
    </div>
    <script>
        window.addEventListener('load', () => { const l = document.getElementById('global-loader'); l.classList.add('opacity-0', 'pointer-events-none'); setTimeout(() => l.style.display = 'none', 500); });
    </script>

    <?php $activePage = 'dashboard'; $showStatusBar = true; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Dashboard</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">nextcloud operations &amp; system control</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                <div class="xl:col-span-8 flex flex-col">
                    <div class="flex gap-1 mb-6 border-b border-gray-200 dark:border-line overflow-x-auto">
                        <button onclick="switchTab('tab-nextcloud')" class="tab-btn active px-4 py-2.5 transition whitespace-nowrap">NEXTCLOUD</button>
                        <button onclick="switchTab('tab-system')" class="tab-btn px-4 py-2.5 transition whitespace-nowrap">SYSTEM_ADMIN</button>
                        <button onclick="switchTab('tab-health')" class="tab-btn px-4 py-2.5 transition whitespace-nowrap">SERVER_HEALTH</button>
                    </div>

                    <div id="tab-nextcloud" class="tab-content space-y-6 fade-in">
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Plugin Manager</h2>
                                <span class="section-eyebrow">occ app:*</span>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3 mb-3">
                                <input type="text" id="app_input" placeholder="App ID (e.g. user_saml)" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 transition">
                                <div class="flex gap-3">
                                    <button onclick="runCmd('app_enable',true)" class="btn btn-outline-green flex-1 sm:flex-none">Enable</button>
                                    <button onclick="runCmd('app_disable',true)" class="btn btn-outline-red flex-1 sm:flex-none">Disable</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <button onclick="runCmd('app_list')" class="btn btn-neutral w-full">List Apps</button>
                                <button onclick="runCmd('update_apps')" class="btn btn-primary w-full col-span-2">Update All Apps</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Maintenance</h2>
                                    <span class="section-eyebrow">Routine</span>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <button onclick="runCmd('status')" class="btn btn-neutral w-full">Status</button>
                                    <button onclick="runCmd('cache_clear')" class="btn btn-neutral w-full">Clear Cache</button>
                                    <button onclick="runCmd('trash_clean')" class="btn btn-neutral w-full">Empty Trash</button>
                                    <button onclick="runCmd('db_missing')" class="btn btn-neutral w-full">DB Indices</button>
                                    <button onclick="runCmd('maint_on')" class="btn btn-outline-red w-full">Maint. ON</button>
                                    <button onclick="runCmd('maint_off')" class="btn btn-outline-green w-full">Maint. OFF</button>
                                </div>
                            </div>
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Heavy Operations</h2>
                                    <span class="section-eyebrow">Async</span>
                                </div>
                                <div class="space-y-3">
                                    <button onclick="runCmd('files_scan')" class="btn btn-primary w-full">Scan All Files</button>
                                    <button onclick="runCmd('preview_gen')" class="btn btn-primary w-full">Gen Previews</button>
                                    <button onclick="confirmAction('Truncate Log?', 'log_truncate')" class="btn btn-outline-red w-full">Truncate Log</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="tab-system" class="tab-content hidden space-y-6">
                        <div class="card card-accent p-6">
                            <div class="flex justify-between items-center mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg bg-violet-500/10 flex items-center justify-center"><svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></div>
                                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Stealth Mode</h2>
                                </div>
                                <div id="modsec-badge" class="badge bg-gray-100 dark:bg-surface2 text-gray-500 dark:text-gray-400">STATUS UNKNOWN</div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <button onclick="toggleModSec('modsec_on', true)" class="btn btn-violet py-3">ENABLE LOCK</button>
                                <button onclick="toggleModSec('modsec_off', true)" class="btn btn-neutral py-3">DISABLE LOCK</button>
                            </div>
                        </div>
                        <div class="card p-6" style="border-top:2px solid #e11d48">
                            <h2 class="text-base font-bold mb-6 text-gray-900 dark:text-white flex items-center gap-2">Root Controls <span class="badge bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-300">Auth Required</span></h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-3">
                                    <h3 class="section-eyebrow mb-1">Service Management</h3>
                                    <button onclick="runCmd('restart_httpd', false, true)" class="btn btn-neutral w-full justify-start">Restart Apache</button>
                                    <button onclick="runCmd('restart_php', false, true)" class="btn btn-neutral w-full justify-start">Restart PHP-FPM</button>
                                </div>
                                <div class="space-y-3">
                                    <h3 class="section-eyebrow mb-1">OS Operations</h3>
                                    <button onclick="confirmAction('Run DNF Update?','sys_update', false, true)" class="btn btn-neutral w-full justify-between"><span>Run DNF Update</span><span class="badge bg-gray-100 dark:bg-surface2">SLOW</span></button>
                                    <button onclick="confirmAction('REBOOT SERVER?','reboot', true, true)" class="btn btn-danger w-full justify-between"><span>REBOOT SERVER</span><span class="badge bg-white/20">DANGER</span></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="tab-health" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <div class="flex justify-between mb-6"><h2 class="text-base font-bold text-gray-900 dark:text-white">Live Resources</h2><button onclick="fetchStats()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button></div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">RAM</span><span id="ram-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="ram-bar" class="bg-violet-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                                <div class="bg-gray-50 dark:bg-surface2 p-4 rounded-lg border border-gray-200 dark:border-line"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400 font-mono">DISK (/)</span><span id="disk-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-line h-1.5 rounded-full"><div id="disk-bar" class="bg-accent-500 h-1.5 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                            </div>
                            <div class="mt-6 pt-6 border-t border-gray-200 dark:border-line text-sm font-mono"><span class="text-gray-500">uptime:</span> <span id="uptime-text" class="ml-2 text-gray-900 dark:text-white">checking...</span></div>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-4">
                    <div class="bg-term rounded-xl border border-line flex flex-col h-full sticky top-6 overflow-hidden">
                        <div class="bg-surface px-4 py-3 flex justify-between items-center border-b border-line shrink-0">
                            <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-rose-500/70"></span><span class="w-2 h-2 rounded-full bg-amber-500/70"></span><span class="w-2 h-2 rounded-full bg-accent-500/70"></span><span class="text-xs font-mono text-gray-500 ml-2">admin@nc:~# output</span></div>
                            <button onclick="clearLog()" class="text-xs font-mono text-gray-500 hover:text-white font-bold tracking-wider ml-auto">CLEAR</button>
                        </div>
                        <div id="terminal" class="terminal-box flex-1 p-4 font-mono text-xs sm:text-sm text-accent-400 whitespace-pre-wrap break-all bg-term"><span class="text-gray-600">// Ready...</span></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        let pollInterval = null;
        let isBusy = false;
        const terminal = document.getElementById('terminal');

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        // SweetAlert Helper
        async function confirmAction(msg, action, isDanger = false, needsAuth = false) {
            const result = await Swal.fire({
                title: 'Confirmation',
                text: msg,
                icon: isDanger ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: isDanger ? '#e11d48' : '#0d9488',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, proceed',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });

            if (result.isConfirmed) {
                runCmd(action, false, needsAuth);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            checkSystemState();
            setInterval(checkSystemState, 3000);
            fetchStats();
            checkModSecStatus();
            setInterval(checkModSecStatus, 5000);
        });

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(tabId).classList.remove('hidden');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }

        async function checkModSecStatus() {
            try {
                const res = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'modsec_status' }) });
                const data = await res.json();
                const badge = document.getElementById('modsec-badge');
                if (data.output && data.output.trim() === 'LOCKED') {
                    badge.className = "badge bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400 status-dot-pulse";
                    badge.innerHTML = "🔒 WAF ACTIVE";
                } else {
                    badge.className = "badge bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400";
                    badge.innerHTML = "🔓 WAF INACTIVE";
                }
            } catch (e) {}
        }

        async function toggleModSec(action, needsAuth = false) {
            const result = await Swal.fire({
                title: 'WAF Configuration',
                text: 'Are you sure you want to update ModSecurity rules?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0d9488',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, update',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!result.isConfirmed) return;

            await runCmd(action, false, needsAuth);
            setTimeout(checkModSecStatus, 1000);
        }

        async function fetchStats() {
            try {
                const res = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_server_stats' }) });
                const data = await res.json();
                document.getElementById('ram-text').textContent = data.mem_text;
                document.getElementById('ram-bar').style.width = data.mem_percent + '%';
                document.getElementById('disk-text').textContent = data.disk_text;
                document.getElementById('disk-bar').style.width = data.disk_percent + '%';
                document.getElementById('uptime-text').textContent = data.uptime;
            } catch (e) {}
        }

        function setBusyState(busy, logFile = null) {
            isBusy = busy;
            const indicator = document.getElementById('status-indicator');
            const unlockBtn = document.getElementById('force-unlock-btn');
            const buttons = document.querySelectorAll('button:not(#force-unlock-btn):not(#sun-icon):not(#moon-icon)');
            if (busy) {
                indicator.innerHTML = '<span class="text-rose-500 dark:text-rose-400 font-bold animate-pulse">BUSY</span>';
                unlockBtn.classList.remove('hidden');
                buttons.forEach(btn => btn.disabled = true);
                if (logFile && !pollInterval) startPolling(logFile);
            } else {
                indicator.innerHTML = '<div class="h-1.5 w-1.5 rounded-full bg-green-500 status-dot-pulse"></div><span class="text-gray-500 dark:text-gray-400">Idle</span>';
                unlockBtn.classList.add('hidden');
                buttons.forEach(btn => btn.disabled = false);
                if (pollInterval) stopPolling();
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

            await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'force_unlock' }) });
            setBusyState(false);
        }

        async function checkSystemState() {
            try {
                const response = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_state' }) });
                const data = await response.json();
                if (data.status === 'busy' && !isBusy) setBusyState(true, data.log_file);
                else if (data.status === 'idle' && isBusy) setBusyState(false);
            } catch (e) {}
        }

        function log(text, type = 'info') {
            const line = document.createElement('div');
            line.className = 'mb-1';
            if (type === 'error') { line.className += ' text-rose-400'; text = `[ERR] ${text}`; }
            else if (type === 'system') { line.className += ' text-violet-400 font-bold mt-2'; text = `>>> ${text}`; }
            line.textContent = text;
            terminal.appendChild(line);
            terminal.scrollTop = terminal.scrollHeight;
        }
        function clearLog() { terminal.innerHTML = ''; }

        async function runCmd(action, reqArg = false, needsAuth = false) {
            if (isBusy) return;
            let arg = null;
            let pass = null;

            // 1. Handle Argument
            if (reqArg) {
                const { value: appId } = await Swal.fire({
                    title: 'Enter App ID',
                    input: 'text',
                    inputLabel: 'e.g. user_saml',
                    inputPlaceholder: 'App ID',
                    showCancelButton: true,
                    background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                    color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
                });
                if (!appId) return;
                arg = appId;
            }

            // 2. Handle Authentication (Using SweetAlert2)
            if (needsAuth) {
                const { value: password } = await Swal.fire({
                    title: 'Root Privilege Required',
                    input: 'password',
                    inputLabel: 'Enter Security Password',
                    inputPlaceholder: 'Password',
                    showCancelButton: true,
                    confirmButtonColor: '#e11d48',
                    background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                    color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
                });
                if (!password) {
                    log('Cancelled: Security Password required.', 'error');
                    return;
                }
                pass = password;
            }

            log(`Executing: ${action}...`, 'system');
            setBusyState(true);
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        token: TOKEN,
                        action: action,
                        arg: arg,
                        sec_pass: pass
                    })
                });
                const data = await response.json();
                if (data.error) {
                    log(data.error, 'error');
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                    setBusyState(false);
                    return;
                }
                if (data.status === 'done') {
                    log(data.output);
                    log('Done.', 'system');
                    Swal.fire({ icon: 'success', title: 'Done', text: 'Operation completed successfully.', toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                    setBusyState(false);
                }
                else if (data.status === 'started') { log(`Job started. Log: ${data.log_file}`, 'system'); startPolling(data.log_file); }
            } catch (e) { log(`Connection Failed: ${e.message}`, 'error'); setBusyState(false); }
        }

        function startPolling(filename) {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(async () => {
                try {
                    const res = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'read_log', file: filename }) });
                    const data = await res.json();
                    if(data.output) {
                        terminal.innerHTML = '';
                        const pre = document.createElement('div');
                        pre.textContent = data.output;
                        terminal.appendChild(pre);
                        terminal.scrollTop = terminal.scrollHeight;
                    }
                } catch (e) {}
            }, 2000);
        }
        function stopPolling() { if (pollInterval) clearInterval(pollInterval); pollInterval = null; }
    </script>
</body>
</html>
