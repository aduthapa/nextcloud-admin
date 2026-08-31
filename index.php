<?php
require 'db.php'; require_login(); 
if (isset($_GET['logout'])) { session_destroy(); header("Location: /login"); exit; }

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
    if ($action==='force_unlock'){if(file_exists($LOCK_FILE))unlink($LOCK_FILE);echo json_encode(['status'=>'done','output'=>'Unlocked']);exit;}
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
            echo json_encode(['error' => 'Invalid Security Password.']);
            exit;
        }
    }

    // 5. Argument Handling
    if (in_array($action, ['app_enable','app_disable'])) { 
        if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $input['arg'])) $cmd .= ' '.escapeshellarg($input['arg']); 
        else { echo json_encode(['error'=>'Invalid App ID']); exit; } 
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
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>NC Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { colors: { nc: '#0082c9', nc_dark: '#005f92', term: '#0c0c0c' } } } }; 
        // Theme Initialization
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <style>
        body { transition: background-color 0.3s, color 0.3s; }
        ::-webkit-scrollbar{width:8px;height:8px}
        ::-webkit-scrollbar-track{background:rgba(0,0,0,0.1)}
        .dark ::-webkit-scrollbar-track{background:#1a1a1a}
        ::-webkit-scrollbar-thumb{background:#888;border-radius:4px}
        .dark ::-webkit-scrollbar-thumb{background:#444}
        .terminal-box { height: 600px; max-height: 80vh; overflow-y: auto; }
        button:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(100%); }
        .tab-btn.active { border-bottom: 2px solid #0082c9; background: rgba(0, 130, 201, 0.1); }
        .dark .tab-btn.active { color: white; background: rgba(31, 41, 55, 0.5); }
        .tab-btn { color: #6b7280; } /* gray-500 */
        .dark .tab-btn { color: #9ca3af; } /* gray-400 */
        .tab-btn.active { color: #1f2937; } /* gray-900 */
    </style>
</head>
<body class="font-sans min-h-screen p-4 lg:p-8 bg-gray-100 text-gray-900 dark:bg-[#111827] dark:text-gray-200">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-gray-900 flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-16 h-16 border-4 border-blue-900/30 border-t-[#0082c9] rounded-full animate-spin"></div><div class="mt-4 text-[#0082c9] font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div></div>
    </div>
    <script>
        window.addEventListener('load', () => { const l = document.getElementById('global-loader'); l.classList.add('opacity-0', 'pointer-events-none'); setTimeout(() => l.style.display = 'none', 500); });
    </script>

    <div class="max-w-[1600px] mx-auto">
        <div class="flex justify-between items-center mb-8 pb-4 border-b border-gray-300 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="bg-nc p-2 rounded-lg"><svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"></path></svg></div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-800 dark:text-white">Nextcloud<span class="text-gray-500 font-light">Admin</span></h1>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="toggleTheme()" class="p-2 rounded-lg bg-gray-200 dark:bg-gray-800 text-gray-600 dark:text-yellow-400 hover:bg-gray-300 dark:hover:bg-gray-700 transition">
                    <svg id="moon-icon" class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    <svg id="sun-icon" class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </button>
                <nav class="flex gap-2 bg-white dark:bg-gray-800/50 p-1 rounded-lg border border-gray-300 dark:border-gray-700 shadow-sm">
                    <a href="/" class="px-4 py-1.5 rounded-md text-sm font-medium bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm">Dashboard</a>
                    <a href="/database" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Database</a>
                    <a href="/settings" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Settings</a>
                    <a href="/ncconfig" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Config</a>
                    <a href="/security.php" class="px-4 py-1.5 rounded-md text-sm font-medium text-red-600 dark:text-red-200 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition border border-red-200 dark:border-red-900/30">Security</a>
                </nav>
                <a href="/?logout=true" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 text-sm font-medium px-3 py-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">Logout</a>
                <div id="status-indicator" class="flex items-center gap-2 text-xs font-bold bg-white dark:bg-gray-800 px-3 py-1.5 rounded-full border border-gray-300 dark:border-gray-700 uppercase tracking-wider"><div class="h-2 w-2 rounded-full bg-green-500"></div><span class="text-gray-600 dark:text-gray-400">Idle</span></div>
                <button id="force-unlock-btn" onclick="forceUnlock()" class="hidden text-xs bg-red-600 text-white px-3 py-1.5 rounded-full hover:bg-red-700 transition">Unlock</button>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
            <div class="xl:col-span-8 flex flex-col">
                <div class="flex space-x-1 mb-6 border-b border-gray-300 dark:border-gray-700">
                    <button onclick="switchTab('tab-nextcloud')" class="tab-btn active px-4 py-2 font-medium transition">☁️ Nextcloud</button>
                    <button onclick="switchTab('tab-system')" class="tab-btn px-4 py-2 font-medium transition">⚙️ System Admin</button>
                    <button onclick="switchTab('tab-health')" class="tab-btn px-4 py-2 font-medium transition">📊 Server Health</button>
                </div>

                <div id="tab-nextcloud" class="tab-content space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-lg font-bold mb-4 text-purple-600 dark:text-purple-400">Plugin Manager</h2>
                        <div class="flex gap-3 mb-3">
                            <input type="text" id="app_input" placeholder="App ID (e.g. user_saml)" class="flex-1 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc">
                            <button onclick="runCmd('app_enable',true)" class="bg-green-100 dark:bg-green-900/50 hover:bg-green-200 dark:hover:bg-green-900/80 text-green-800 dark:text-green-200 border border-green-200 dark:border-green-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Enable</button>
                            <button onclick="runCmd('app_disable',true)" class="bg-red-100 dark:bg-red-900/50 hover:bg-red-200 dark:hover:bg-red-900/80 text-red-800 dark:text-red-200 border border-red-200 dark:border-red-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Disable</button>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <button onclick="runCmd('app_list')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">List Apps</button>
                            <button onclick="runCmd('update_apps')" class="w-full col-span-2 bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-900/20 py-2.5 px-4 rounded-lg transition font-medium text-sm">Update All Apps</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                            <h2 class="text-lg font-bold mb-4 text-blue-600 dark:text-blue-400">Maintenance</h2>
                            <div class="grid grid-cols-2 gap-3">
                                <button onclick="runCmd('status')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Status</button>
                                <button onclick="runCmd('cache_clear')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Clear Cache</button>
                                <button onclick="runCmd('trash_clean')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Empty Trash</button>
                                <button onclick="runCmd('db_missing')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">DB Indices</button>
                                <button onclick="runCmd('maint_on')" class="w-full bg-red-100 dark:bg-red-900/50 hover:bg-red-200 dark:hover:bg-red-900/80 text-red-800 dark:text-red-200 border border-red-200 dark:border-red-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Maint. ON</button>
                                <button onclick="runCmd('maint_off')" class="w-full bg-green-100 dark:bg-green-900/50 hover:bg-green-200 dark:hover:bg-green-900/80 text-green-800 dark:text-green-200 border border-green-200 dark:border-green-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Maint. OFF</button>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                            <h2 class="text-lg font-bold mb-4 text-yellow-600 dark:text-yellow-400">Heavy Operations</h2>
                            <div class="space-y-3">
                                <button onclick="runCmd('files_scan')" class="w-full bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-900/20 py-2.5 px-4 rounded-lg transition font-medium text-sm">Scan All Files</button>
                                <button onclick="runCmd('preview_gen')" class="w-full bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-900/20 py-2.5 px-4 rounded-lg transition font-medium text-sm">Gen Previews</button>
                                <button onclick="confirmAction('Truncate Log?', 'log_truncate')" class="w-full bg-red-100 dark:bg-red-900/50 hover:bg-red-200 dark:hover:bg-red-900/80 text-red-800 dark:text-red-200 border border-red-200 dark:border-red-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Truncate Log</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-system" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-blue-500/40">
                        <div class="flex justify-between items-center mb-4">
                            <div class="flex items-center gap-3"><div class="bg-blue-100 dark:bg-blue-900/30 p-2 rounded-lg"><svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></div><h2 class="text-xl font-bold text-gray-800 dark:text-white">Stealth <span class="text-blue-500 dark:text-blue-400">Mode</span></h2></div>
                            <div id="modsec-badge" class="px-3 py-1 rounded text-xs font-mono font-bold bg-gray-200 dark:bg-gray-700 text-gray-500 border border-gray-300 dark:border-gray-600">STATUS UNKNOWN</div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <button onclick="toggleModSec('modsec_on', true)" class="group relative w-full bg-blue-50 dark:bg-blue-600/20 hover:bg-blue-100 dark:hover:bg-blue-600/40 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-500/50 py-3 rounded-lg font-bold transition overflow-hidden"><div class="absolute inset-0 w-1 bg-blue-500 transition-all duration-300 group-hover:w-full opacity-10"></div><span class="relative flex items-center justify-center gap-2">ENABLE LOCK</span></button>
                            <button onclick="toggleModSec('modsec_off', true)" class="group relative w-full bg-gray-100 dark:bg-gray-700/40 hover:bg-gray-200 dark:hover:bg-gray-700/60 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-600/50 py-3 rounded-lg font-bold transition overflow-hidden"><span class="relative flex items-center justify-center gap-2">DISABLE LOCK</span></button>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-red-900/30">
                        <h2 class="text-xl font-bold mb-6 text-red-600 dark:text-red-400">Root Controls (Auth Required)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <h3 class="font-bold text-gray-500 dark:text-gray-400 uppercase text-xs tracking-wider">Service Management</h3>
                                <button onclick="runCmd('restart_httpd', false, true)" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 border-l-4 border-l-blue-500 text-left py-2.5 px-4 rounded-lg transition font-medium text-sm">Restart Apache</button>
                                <button onclick="runCmd('restart_php', false, true)" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 border-l-4 border-l-purple-500 text-left py-2.5 px-4 rounded-lg transition font-medium text-sm">Restart PHP-FPM</button>
                            </div>
                            <div class="space-y-4">
                                <h3 class="font-bold text-gray-500 dark:text-gray-400 uppercase text-xs tracking-wider">OS Operations</h3>
                                <button onclick="confirmAction('Run DNF Update?','sys_update', false, true)" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 text-left flex justify-between items-center py-2.5 px-4 rounded-lg transition font-medium text-sm"><span>Run DNF Update</span><span class="text-xs bg-black/10 dark:bg-black/30 px-2 py-1 rounded">SLOW</span></button>
                                <button onclick="confirmAction('REBOOT SERVER?','reboot', true, true)" class="w-full bg-red-100 dark:bg-red-900/80 hover:bg-red-200 dark:hover:bg-red-800 text-red-800 dark:text-white font-bold text-left flex justify-between items-center py-2.5 px-4 rounded-lg transition font-medium text-sm"><span>REBOOT SERVER</span><span class="text-xs bg-red-200 dark:bg-red-900 px-2 py-1 rounded">DANGER</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-health" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between mb-6"><h2 class="text-xl font-bold text-green-600 dark:text-green-400">Live Resources</h2><button onclick="fetchStats()" class="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white border border-gray-300 dark:border-gray-600 py-1 px-3 rounded text-xs transition">Refresh</button></div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="bg-gray-50 dark:bg-gray-900/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400">RAM</span><span id="ram-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-gray-700 h-2 rounded-full"><div id="ram-bar" class="bg-purple-500 h-2 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                            <div class="bg-gray-50 dark:bg-gray-900/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700"><div class="flex justify-between mb-2 text-sm"><span class="text-gray-500 dark:text-gray-400">Disk (/)</span><span id="disk-text" class="text-gray-900 dark:text-white font-mono">...</span></div><div class="w-full bg-gray-200 dark:bg-gray-700 h-2 rounded-full"><div id="disk-bar" class="bg-blue-500 h-2 rounded-full transition-all duration-500" style="width:0%"></div></div></div>
                        </div>
                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 text-sm"><span class="text-gray-500">System Uptime:</span> <span id="uptime-text" class="ml-2 text-gray-900 dark:text-white font-mono">Checking...</span></div>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-4">
                <div class="bg-term rounded-xl shadow-2xl border border-gray-300 dark:border-gray-700 flex flex-col h-full sticky top-6 overflow-hidden">
                    <div class="bg-gray-100 dark:bg-gray-800 px-4 py-3 flex justify-between items-center border-b border-gray-300 dark:border-gray-700 shrink-0">
                        <span class="text-xs font-mono text-gray-500 ml-4">admin@nc:~# output</span><button onclick="clearLog()" class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white font-bold tracking-wider ml-auto">CLEAR</button>
                    </div>
                    <div id="terminal" class="terminal-box flex-1 p-4 font-mono text-xs sm:text-sm text-green-600 dark:text-green-400 whitespace-pre-wrap break-all bg-white dark:bg-[#0c0c0c]"><span class="text-gray-400 dark:text-gray-600">// Ready...</span></div>
                </div>
            </div>
        </div>
    </div>

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
                confirmButtonColor: isDanger ? '#d33' : '#0082c9',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, proceed',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
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
                    badge.className = "px-3 py-1 rounded text-xs font-mono font-bold bg-green-100 dark:bg-green-900/50 text-green-600 dark:text-green-400 border border-green-500 shadow-[0_0_15px_rgba(74,222,128,0.1)] animate-pulse";
                    badge.innerHTML = "🔒 WAF ACTIVE";
                } else {
                    badge.className = "px-3 py-1 rounded text-xs font-mono font-bold bg-red-100 dark:bg-red-900/20 text-red-500 border border-red-300 dark:border-red-900/50";
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
                confirmButtonColor: '#0082c9',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, update',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
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
                indicator.innerHTML = '<span class="text-red-500 dark:text-red-400 font-bold animate-pulse">PROCESSING...</span>';
                unlockBtn.classList.remove('hidden');
                buttons.forEach(btn => btn.disabled = true);
                if (logFile && !pollInterval) startPolling(logFile);
            } else {
                indicator.innerHTML = '<div class="h-2 w-2 rounded-full bg-green-500 mr-2"></div><span class="text-gray-600 dark:text-gray-400">Idle</span>';
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
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Unlock',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
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
            if (type === 'error') { line.className += ' text-red-500 dark:text-red-400'; text = `[ERR] ${text}`; }
            else if (type === 'system') { line.className += ' text-blue-600 dark:text-blue-400 font-bold mt-2'; text = `>>> ${text}`; }
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
                    background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                    color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
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
                    confirmButtonColor: '#d33',
                    background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                    color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
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
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                    setBusyState(false); 
                    return; 
                }
                if (data.status === 'done') { 
                    log(data.output); 
                    log('Done.', 'system'); 
                    Swal.fire({ icon: 'success', title: 'Done', text: 'Operation completed successfully.', toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
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
