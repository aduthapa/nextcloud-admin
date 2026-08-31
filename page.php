<?php
// ==========================================
// CONFIGURATION
// ==========================================
$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V'; 
$DATA_DIR     = __DIR__ . '/data'; 
$LOCK_FILE    = $DATA_DIR . '/system.lock';

// Ensure data directory exists and is writable by apache
if (!is_dir($DATA_DIR)) { 
    mkdir($DATA_DIR, 0755, true); 
    chown($DATA_DIR, 'apache'); 
}

$OCC_BASE = '/usr/bin/php /var/www/html/nextcloud/occ';

// ==========================================
// COMMAND DEFINITIONS
// ==========================================
$COMMANDS = [
    // --- TAB 1: NEXTCLOUD ---
    'status'          => ['type' => 'sync', 'cmd' => "$OCC_BASE status"],
    'app_list'        => ['type' => 'sync', 'cmd' => "$OCC_BASE app:list"],
    'maint_on'        => ['type' => 'sync', 'cmd' => "$OCC_BASE maintenance:mode --on"],
    'maint_off'       => ['type' => 'sync', 'cmd' => "$OCC_BASE maintenance:mode --off"],
    'trash_clean'     => ['type' => 'sync', 'cmd' => "$OCC_BASE trashbin:cleanup --all-users"],
    'versions_clean'  => ['type' => 'sync', 'cmd' => "$OCC_BASE versions:cleanup"],
    'cache_clear'     => ['type' => 'sync', 'cmd' => "$OCC_BASE files:cleanup"],
    'db_missing'      => ['type' => 'async', 'cmd' => "$OCC_BASE db:add-missing-indices", 'log' => 'db_indices.log'],
    'db_convert'      => ['type' => 'async', 'cmd' => "$OCC_BASE db:convert-filecache-bigint", 'log' => 'db_bigint.log'],
    'integrity_check' => ['type' => 'async', 'cmd' => "$OCC_BASE integrity:check-core", 'log' => 'integrity.log'],
    
    // UPDATED: Added --verbose so output writes immediately
    'files_scan'      => ['type' => 'async', 'cmd' => "$OCC_BASE files:scan --all --verbose", 'log' => 'files_scan.log'],
    
    // UPDATED: Added -vv for verbose output
    'preview_gen'     => ['type' => 'async', 'cmd' => "$OCC_BASE preview:generate-all -vv", 'log' => 'preview_gen.log'],
    
    'update_apps'     => ['type' => 'async', 'cmd' => "$OCC_BASE app:update --all", 'log' => 'app_update.log'],
    'app_enable'      => ['type' => 'sync', 'cmd' => "$OCC_BASE app:enable"], 
    'app_disable'     => ['type' => 'sync', 'cmd' => "$OCC_BASE app:disable"], 
    
    // UPDATED: Hardcoded path to avoid variable corruption from OCC warnings
    'log_truncate'    => ['type' => 'sync', 'cmd' => 'truncate -s 0 /var/www/html/nextcloud/data/nextcloud.log && echo "Nextcloud Log Truncated."'],

    // --- TAB 2: SYSTEM ADMIN ---
    'sys_update'      => ['type' => 'async', 'cmd' => 'sudo /usr/bin/dnf update -y', 'log' => 'sys_update.log'],
    'reboot'          => ['type' => 'async', 'cmd' => 'sudo /usr/sbin/reboot', 'log' => 'reboot.log'],
    
    // UPDATED: Sleep 3 prevents connection reset errors on restart
    'restart_httpd'   => ['type' => 'sync', 'cmd' => 'nohup sh -c "sleep 3; sudo /usr/bin/systemctl restart httpd" > /dev/null 2>&1 & echo "Apache will restart in 3 seconds."'],
    'restart_php'     => ['type' => 'sync', 'cmd' => 'nohup sh -c "sleep 3; sudo /usr/bin/systemctl restart php-fpm" > /dev/null 2>&1 & echo "PHP-FPM will restart in 3 seconds."'],
    
    'firewall_status' => ['type' => 'sync', 'cmd' => 'sudo /usr/bin/systemctl status firewalld'],
    
    // UPDATED: Kills both Preview and Scan jobs. No sudo needed (apache owns them).
    'kill_stuck'      => ['type' => 'sync', 'cmd' => 'pkill -f "preview:generate-all" || pkill -f "files:scan"; echo "Killed background Nextcloud jobs."'],
];

// ==========================================
// BACKEND LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (($input['token'] ?? '') !== $SECRET_TOKEN) {
        http_response_code(403); echo json_encode(['error' => 'Invalid Token']); exit;
    }

    $action = $input['action'] ?? '';

    // Widget Stats
    if ($action === 'get_server_stats') {
        $freeArr = preg_split("/\s+/", trim(shell_exec('free -m')));
        $memPercent = round(($freeArr[8] / $freeArr[7]) * 100);
        $diskTotal = disk_total_space("/");
        $diskUsed  = $diskTotal - disk_free_space("/");
        echo json_encode([
            'mem_percent' => $memPercent,
            'mem_text'    => "{$freeArr[8]}MB / {$freeArr[7]}MB",
            'disk_percent'=> round(($diskUsed / $diskTotal) * 100),
            'disk_text'   => round($diskUsed/1073741824, 2)."GB / ".round($diskTotal/1073741824, 2)."GB",
            'uptime'      => shell_exec('uptime -p')
        ]);
        exit;
    }
    
    // Force Unlock
    if ($action === 'force_unlock') {
        if (file_exists($LOCK_FILE)) unlink($LOCK_FILE);
        echo json_encode(['status' => 'done', 'output' => 'Forced unlock OK.']);
        exit;
    }

    // State Check
    if ($action === 'get_state') {
        if (file_exists($LOCK_FILE)) {
            $activeLog = file_get_contents($LOCK_FILE);
            echo json_encode(['status' => 'busy', 'log_file' => $activeLog]);
        } else {
            echo json_encode(['status' => 'idle']);
        }
        exit;
    }

    // Read Log
    if ($action === 'read_log') {
        $logFile = $DATA_DIR . '/' . basename($input['file']);
        if (file_exists($logFile)) {
            // Using tail -n 50 for cleaner vertical scrolling
            $content = shell_exec("tail -n 50 " . escapeshellarg($logFile)); 
            echo json_encode(['output' => $content]);
        } else {
            echo json_encode(['output' => "Log file created but empty (buffering) or missing...\nPath: $logFile"]);
        }
        exit;
    }

    // Validate Command
    if (!isset($COMMANDS[$action])) { echo json_encode(['error' => 'Unknown command']); exit; }
    if (file_exists($LOCK_FILE)) { echo json_encode(['error' => 'System Busy.']); exit; }

    $config = $COMMANDS[$action];
    $cmd = $config['cmd'];

    // App Arguments
    if (in_array($action, ['app_enable', 'app_disable'])) {
        $arg = $input['arg'] ?? '';
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $arg)) $cmd .= ' ' . escapeshellarg($arg);
        else { echo json_encode(['error' => 'Invalid App ID']); exit; }
    }

    // Execute
    if ($config['type'] === 'sync') {
        $output = shell_exec($cmd . ' 2>&1');
        echo json_encode(['status' => 'done', 'output' => $output]);
    } else {
        $logName = $config['log'];
        $logFile = $DATA_DIR . '/' . $logName;
        $wrapper = "nohup sh -c 'echo \"$logName\" > $LOCK_FILE; $cmd; rm $LOCK_FILE' > $logFile 2>&1 &";
        shell_exec($wrapper);
        echo json_encode(['status' => 'started', 'log_file' => $logName]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NC Admin Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { nc: '#0082c9', nc_dark: '#005f92', term: '#0c0c0c' } } } }
    </script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #1a1a1a; }
        ::-webkit-scrollbar-thumb { background: #444; border-radius: 4px; }
        
        /* Strict Height Fix for Terminal */
        .terminal-strict { height: 600px; max-height: 80vh; overflow-y: auto; }
        
        button:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(100%); }
        .tab-btn.active { border-bottom: 2px solid #0082c9; color: white; background: rgba(255,255,255,0.05); }
        .tab-btn { color: #9ca3af; }
        .tab-btn:hover { color: #e5e7eb; }
        .processing-badge { animation: pulse-red 1.5s infinite; }
        @keyframes pulse-red { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-900 text-gray-200 font-sans min-h-screen p-4 lg:p-8">

    <div class="max-w-[1600px] mx-auto">
        
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-700">
            <div class="flex items-center gap-3">
                <div class="bg-nc p-2 rounded-lg"><svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"></path></svg></div>
                <h1 class="text-2xl font-bold tracking-tight text-white">Nextcloud<span class="text-gray-500 font-light">Admin</span></h1>
            </div>
            <div id="status-indicator" class="flex items-center gap-2 text-sm bg-gray-800 px-3 py-1 rounded-full border border-gray-700">
                <div class="h-2 w-2 rounded-full bg-green-500"></div>
                <span class="text-gray-400">Idle</span>
            </div>
            <button id="force-unlock-btn" onclick="forceUnlock()" class="hidden ml-2 text-xs bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-full">Force Unlock</button>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
            
            <div class="xl:col-span-8 flex flex-col">
                
                <div class="flex space-x-1 mb-6 border-b border-gray-700">
                    <button onclick="switchTab('tab-nextcloud')" class="tab-btn active px-6 py-3 font-medium transition duration-200">☁️ Nextcloud</button>
                    <button onclick="switchTab('tab-system')" class="tab-btn px-6 py-3 font-medium transition duration-200">⚙️ System Admin</button>
                    <button onclick="switchTab('tab-health')" class="tab-btn px-6 py-3 font-medium transition duration-200">📊 Server Health</button>
                </div>

                <div id="tab-nextcloud" class="tab-content space-y-6">
                    <div class="bg-gray-800 rounded-xl p-5 shadow-lg border border-gray-700">
                        <h2 class="text-lg font-semibold mb-3 text-purple-400">Plugin Manager</h2>
                        <div class="flex gap-3 mb-3">
                            <input type="text" id="app_input" placeholder="App ID (e.g. user_saml)" class="flex-1 bg-gray-900 border border-gray-600 text-white px-4 py-2 rounded focus:outline-none focus:border-nc">
                            <button onclick="runCmd('app_enable', true)" class="cmd-btn bg-green-700 hover:bg-green-600 text-white px-5 py-2 rounded">Enable</button>
                            <button onclick="runCmd('app_disable', true)" class="cmd-btn bg-red-700 hover:bg-red-600 text-white px-5 py-2 rounded">Disable</button>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <button onclick="runCmd('app_list')" class="cmd-btn text-xs bg-gray-700 hover:bg-gray-600 py-1 rounded text-gray-300">List Apps</button>
                            <button onclick="runCmd('update_apps')" class="cmd-btn text-xs bg-blue-900/50 hover:bg-blue-800 py-1 rounded text-blue-200">Update All</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800 rounded-xl p-5 shadow-lg border border-gray-700">
                            <h2 class="text-lg font-semibold mb-3 text-blue-400">Maintenance</h2>
                            <div class="grid grid-cols-2 gap-2">
                                <button onclick="runCmd('status')" class="cmd-btn bg-gray-700 hover:bg-gray-600 py-2 rounded text-sm">Status</button>
                                <button onclick="runCmd('cache_clear')" class="cmd-btn bg-gray-700 hover:bg-gray-600 py-2 rounded text-sm">Clear Cache</button>
                                <button onclick="runCmd('trash_clean')" class="cmd-btn bg-gray-700 hover:bg-gray-600 py-2 rounded text-sm">Empty Trash</button>
                                <button onclick="runCmd('db_missing')" class="cmd-btn bg-gray-700 hover:bg-gray-600 py-2 rounded text-sm">DB Indices</button>
                                <button onclick="runCmd('maint_on')" class="cmd-btn bg-red-900/40 text-red-200 border border-red-900 py-2 rounded text-sm">Maint. ON</button>
                                <button onclick="runCmd('maint_off')" class="cmd-btn bg-green-900/40 text-green-200 border border-green-900 py-2 rounded text-sm">Maint. OFF</button>
                            </div>
                        </div>
                        <div class="bg-gray-800 rounded-xl p-5 shadow-lg border border-gray-700">
                            <h2 class="text-lg font-semibold mb-3 text-yellow-400">Heavy Ops</h2>
                            <div class="space-y-2">
                                <button onclick="runCmd('files_scan')" class="cmd-btn w-full bg-indigo-600 hover:bg-indigo-500 py-2 rounded text-sm font-medium">Scan All Files (Verbose)</button>
                                <button onclick="runCmd('preview_gen')" class="cmd-btn w-full bg-indigo-600 hover:bg-indigo-500 py-2 rounded text-sm font-medium">Generate Previews (Verbose)</button>
                                <button onclick="runCmd('integrity_check')" class="cmd-btn w-full bg-gray-700 hover:bg-gray-600 py-2 rounded text-sm">Integrity Check</button>
                                <button onclick="if(confirm('Truncate Log?')) runCmd('log_truncate')" class="cmd-btn w-full bg-red-900/30 text-red-300 py-2 rounded text-sm">Truncate Log</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-system" class="tab-content hidden space-y-6">
                    <div class="bg-gray-800 rounded-xl p-6 shadow-lg border border-red-900/30">
                        <h2 class="text-xl font-semibold mb-4 text-red-400">Root Controls</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <h3 class="font-bold text-gray-300">Service Management</h3>
                                <button onclick="runCmd('restart_httpd')" class="cmd-btn w-full py-3 px-4 rounded bg-gray-700 hover:bg-gray-600 border-l-4 border-blue-500 text-left">Restart Apache</button>
                                <button onclick="runCmd('restart_php')" class="cmd-btn w-full py-3 px-4 rounded bg-gray-700 hover:bg-gray-600 border-l-4 border-purple-500 text-left">Restart PHP-FPM</button>
                                <button onclick="runCmd('firewall_status')" class="cmd-btn w-full py-3 px-4 rounded bg-gray-700 hover:bg-gray-600 border-l-4 border-yellow-500 text-left">Check Firewall Status</button>
                                <button onclick="if(confirm('Kill stuck jobs?')) runCmd('kill_stuck')" class="cmd-btn w-full py-3 px-4 rounded bg-red-900/50 hover:bg-red-900 border border-red-600 text-left text-red-200">Kill Stuck Jobs (Preview/Scan)</button>
                            </div>
                            <div class="space-y-3">
                                <h3 class="font-bold text-gray-300">OS Operations</h3>
                                <button onclick="if(confirm('Run DNF Update?')) runCmd('sys_update')" class="cmd-btn w-full py-3 px-4 rounded bg-gray-700 hover:bg-gray-600 border border-gray-600 text-left flex justify-between">
                                    <span>Run DNF Update</span> <span class="text-gray-400 text-xs uppercase">Slow</span>
                                </button>
                                <button onclick="if(confirm('REBOOT SERVER?')) runCmd('reboot')" class="cmd-btn w-full py-3 px-4 rounded bg-red-900/80 hover:bg-red-800 text-white font-bold text-left flex justify-between">
                                    <span>REBOOT SERVER</span> <span class="text-red-200 text-xs uppercase">Danger</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-health" class="tab-content hidden space-y-6">
                    <div class="bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-700">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-semibold text-green-400">Live Resources</h2>
                            <button onclick="fetchStats()" class="text-sm bg-gray-700 px-3 py-1 rounded hover:bg-gray-600">Refresh</button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="bg-gray-900/50 p-4 rounded-lg">
                                <div class="flex justify-between mb-2"><span class="text-gray-400">RAM Usage</span><span id="ram-text" class="text-white font-mono">Loading...</span></div>
                                <div class="w-full bg-gray-700 rounded-full h-4"><div id="ram-bar" class="bg-purple-500 h-4 rounded-full" style="width: 0%"></div></div>
                            </div>
                            <div class="bg-gray-900/50 p-4 rounded-lg">
                                <div class="flex justify-between mb-2"><span class="text-gray-400">Disk Usage (/)</span><span id="disk-text" class="text-white font-mono">Loading...</span></div>
                                <div class="w-full bg-gray-700 rounded-full h-4"><div id="disk-bar" class="bg-blue-500 h-4 rounded-full" style="width: 0%"></div></div>
                            </div>
                        </div>
                        <div class="mt-6 pt-6 border-t border-gray-700">
                            <span class="text-gray-400">System Uptime:</span><span id="uptime-text" class="ml-2 text-white font-mono">Checking...</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-4">
                <div class="bg-term rounded-xl shadow-2xl border border-gray-700 flex flex-col sticky top-6">
                    <div class="bg-gray-800 px-4 py-3 flex justify-between items-center border-b border-gray-700 shrink-0">
                        <span class="text-xs font-mono text-gray-400">admin@nc:~# output</span>
                        <button onclick="clearLog()" class="text-xs text-gray-500 hover:text-white uppercase font-bold tracking-wider">Clear</button>
                    </div>
                    <div id="terminal" class="terminal-strict p-4 font-mono text-xs sm:text-sm text-green-400 whitespace-pre-wrap break-all bg-term">
                        <span class="text-gray-500">// Ready...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const TOKEN = '<?php echo $SECRET_TOKEN; ?>';
        let pollInterval = null;
        let isBusy = false;
        const terminal = document.getElementById('terminal');

        window.addEventListener('DOMContentLoaded', () => {
            checkSystemState();
            setInterval(checkSystemState, 3000);
            fetchStats();
        });

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(tabId).classList.remove('hidden');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
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
            const buttons = document.querySelectorAll('.cmd-btn');

            if (busy) {
                indicator.innerHTML = '<div class="h-2 w-2 rounded-full bg-red-500 processing-badge"></div><span class="text-red-400 font-bold">Processing...</span>';
                unlockBtn.classList.remove('hidden');
                buttons.forEach(btn => btn.disabled = true);
                if (logFile && !pollInterval) startPolling(logFile);
            } else {
                indicator.innerHTML = '<div class="h-2 w-2 rounded-full bg-green-500"></div><span class="text-gray-400">Idle</span>';
                unlockBtn.classList.add('hidden');
                buttons.forEach(btn => btn.disabled = false);
                if (pollInterval) stopPolling();
            }
        }
        
        async function forceUnlock() {
            if (!confirm('Are you sure? Use this ONLY if a command is stuck.')) return;
            log('Forcing unlock...', 'system');
            try {
                await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'force_unlock' }) });
                setBusyState(false);
            } catch (e) {}
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
            if (type === 'error') { line.className += ' text-red-400'; text = `[ERR] ${text}`; }
            else if (type === 'system') { line.className += ' text-blue-400 font-bold mt-2'; text = `>>> ${text}`; }
            line.textContent = text;
            terminal.appendChild(line);
            terminal.scrollTop = terminal.scrollHeight;
        }
        function clearLog() { terminal.innerHTML = ''; }

        async function runCmd(action, requiresArg = false) {
            if (isBusy) return; 
            let arg = null;
            if (requiresArg) {
                arg = document.getElementById('app_input').value.trim();
                if (!arg) { log('Enter App ID first.', 'error'); return; }
            }
            log(`Executing: ${action}...`, 'system');
            setBusyState(true); 

            try {
                const res = await fetch('index.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, action: action, arg: arg }) });
                const data = await res.json();

                if (data.error) { log(data.error, 'error'); setBusyState(false); return; }
                if (data.status === 'done') { log(data.output); log('Done.', 'system'); setBusyState(false); }
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
