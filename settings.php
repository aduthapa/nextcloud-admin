<?php
// DB Logic + Error Handling
require 'db.php'; require_login();
if (isset($_GET['logout'])) { session_destroy(); header("Location: /login"); exit; }

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V'; 
$DATA_DIR = __DIR__ . '/data'; $LOCK_FILE = $DATA_DIR . '/system.lock'; $BACKUP_DIR = $DATA_DIR . '/backups';
if (!is_dir($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); chown($DATA_DIR, 'apache'); }
if (!is_dir($BACKUP_DIR)) { mkdir($BACKUP_DIR, 0755, true); chown($BACKUP_DIR, 'apache'); }

$OCC_BASE = '/usr/bin/php -d output_buffering=0 /var/www/html/nextcloud/occ';

$COMMANDS = [
    'user_list'  => ['type'=>'sync', 'cmd'=>"$OCC_BASE user:list"],
    'cron_status'=> ['type'=>'sync', 'cmd'=>'sudo /usr/bin/systemctl status crond'], 
    'cron_run'   => ['type'=>'async','cmd'=>'/usr/bin/php -f /var/www/html/nextcloud/cron.php','log'=>'cron_run.log'],
    'db_backup'  => ['type'=>'async','cmd'=>'/usr/bin/mysqldump nextcloud > '.$BACKUP_DIR.'/nc_db_$(date +%F_%H-%M).sql','log'=>'backup_db.log'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) { http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit; }
    $action = $input['action'] ?? '';

    if ($action === 'save_smtp') {
        try {
            $check = $pdo->query("SELECT count(*) FROM smtp_settings WHERE id=1")->fetchColumn();
            if ($check == 0) $pdo->exec("INSERT INTO smtp_settings (id, host, port, username, password, encryption, from_email, from_name) VALUES (1, '', 587, '', '', 'tls', '', '')");
            $sql = "UPDATE smtp_settings SET host=?, port=?, username=?, password=?, encryption=?, from_email=?, from_name=? WHERE id=1";
            $pdo->prepare($sql)->execute([$input['host'], $input['port'], $input['user'], $input['pass'], $input['enc'], $input['email'], $input['name']]);
            echo json_encode(['status'=>'done','output'=>'SMTP Settings Saved.']); 
        } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); } exit;
    }
    if ($action === 'save_profile') {
        try {
            $sql = "UPDATE admins SET username=?, email=? WHERE username=?";
            $pdo->prepare($sql)->execute([$input['new_user'], $input['new_email'], $_SESSION['admin_user']]);
            if(!empty($input['new_pass'])) {
                $hash = password_hash($input['new_pass'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admins SET password=? WHERE username=?")->execute([$hash, $input['new_user']]);
            }
            $_SESSION['admin_user'] = $input['new_user']; 
            echo json_encode(['status'=>'done','output'=>'Profile Updated.']);
        } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); } exit;
    }
    if ($action === 'get_server_log') {
        $target = $input['log_type'] == 'apache' ? '/var/log/httpd/error_log' : ($input['log_type'] == 'php' ? '/var/log/php-fpm/error.log' : '/var/www/html/nextcloud/data/nextcloud.log');
        echo json_encode(['output'=>shell_exec("sudo tail -n 100 ".escapeshellarg($target))]); exit;
    }
    if ($action==='get_state'){if(file_exists($LOCK_FILE))echo json_encode(['status'=>'busy','log_file'=>file_get_contents($LOCK_FILE)]);else echo json_encode(['status'=>'idle']);exit;}
    if ($action==='read_log'){$l=$DATA_DIR.'/'.basename($input['file']);if(file_exists($l))echo json_encode(['output'=>shell_exec("tail -n 50 ".escapeshellarg($l))]);else echo json_encode(['output'=>'Log missing...']);exit;}
    if ($action==='force_unlock'){if(file_exists($LOCK_FILE))unlink($LOCK_FILE);echo json_encode(['status'=>'done','output'=>'Unlocked']);exit;}
    
    if (!isset($COMMANDS[$action])) { echo json_encode(['error'=>'Unknown command']); exit; }
    if (file_exists($LOCK_FILE)) { echo json_encode(['error'=>'System Busy.']); exit; }
    $config = $COMMANDS[$action]; $cmd = $config['cmd'];
    if ($config['type'] === 'sync') { $out=shell_exec($cmd.' 2>&1'); echo json_encode(['status'=>'done','output'=>$out]); }
    else { $l=$config['log']; $lf=$DATA_DIR.'/'.$l; if(!file_exists($lf))touch($lf); shell_exec("nohup sh -c 'echo \"$l\" > $LOCK_FILE; $cmd; rm $LOCK_FILE' > $lf 2>&1 &"); echo json_encode(['status'=>'started','log_file'=>$l]); }
    exit;
}

try {
    $stmt = $pdo->query("SELECT * FROM smtp_settings WHERE id=1"); $smtp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$smtp) $smtp = ['host'=>'','port'=>'587','username'=>'','password'=>'','encryption'=>'tls','from_email'=>'','from_name'=>''];
    $me = $pdo->prepare("SELECT * FROM admins WHERE username=?"); $me->execute([$_SESSION['admin_user']]); $profile = $me->fetch(PDO::FETCH_ASSOC);
    if (!$profile) $profile = ['username'=>'Unknown','email'=>''];
} catch (Exception $e) { die("DB Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>NC Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config={darkMode:'class',theme:{extend:{colors:{nc:'#0082c9',nc_dark:'#005f92',term:'#0c0c0c'}}}}; 
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
        .dark .tab-btn.active { color: white; }
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
                <h1 class="text-2xl font-bold tracking-tight text-gray-800 dark:text-white">System<span class="text-nc font-light">Settings</span></h1>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="toggleTheme()" class="p-2 rounded-lg bg-gray-200 dark:bg-gray-800 text-gray-600 dark:text-yellow-400 hover:bg-gray-300 dark:hover:bg-gray-700 transition">
                    <svg id="moon-icon" class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    <svg id="sun-icon" class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </button>
                <nav class="flex gap-2 bg-white dark:bg-gray-800/50 p-1 rounded-lg border border-gray-300 dark:border-gray-700 shadow-sm">
                    <a href="/" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Dashboard</a>
                    <a href="/database" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Database</a>
                    <a href="/settings" class="px-4 py-1.5 rounded-md text-sm font-medium bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm border border-gray-300 dark:border-gray-600">Settings</a>
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
                <div class="flex space-x-1 mb-6 border-b border-gray-300 dark:border-gray-700 overflow-x-auto">
                    <?php $tabs=['backup'=>'🛡️ Backups','users'=>'👥 Users','smtp'=>'📧 SMTP','profile'=>'👤 Profile','logs'=>'📜 Logs','cron'=>'⏰ Cron'];
                    foreach($tabs as $id=>$label) echo "<button onclick=\"switchTab('tab-$id')\" class=\"tab-btn px-4 py-2 font-medium hover:text-gray-900 dark:hover:text-white transition ".($id=='backup'?'active':'')."\">$label</button>"; ?>
                </div>

                <div id="tab-backup" class="tab-content space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-blue-600 dark:text-blue-400">Database Backups</h2>
                        <button onclick="runCmd('db_backup')" class="w-full bg-blue-600 hover:bg-blue-500 text-white py-3 px-4 rounded-lg transition font-medium text-sm shadow-lg shadow-blue-900/20 border border-blue-600/50 mb-4">Backup Database Now (mysqldump)</button>
                        <div class="bg-gray-100 dark:bg-black/30 p-4 rounded text-sm font-mono text-gray-600 dark:text-gray-400">Location: <?= $BACKUP_DIR ?></div>
                    </div>
                </div>

                <div id="tab-users" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400">User Management</h2>
                        <button onclick="runCmd('user_list')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm mb-4">List All Users</button>
                        <p class="text-sm text-gray-500 italic">Note: Password resets should be handled via the Nextcloud interface for security.</p>
                    </div>
                </div>

                <div id="tab-smtp" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-green-600 dark:text-green-400">SMTP Settings</h2>
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <input id="smtp_host" placeholder="Host" value="<?= htmlspecialchars($smtp['host']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_port" placeholder="Port" value="<?= htmlspecialchars($smtp['port']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_user" placeholder="Username" value="<?= htmlspecialchars($smtp['username']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_pass" type="password" placeholder="Password" value="<?= htmlspecialchars($smtp['password']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_enc" placeholder="Encryption" value="<?= htmlspecialchars($smtp['encryption']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_email" placeholder="From Email" value="<?= htmlspecialchars($smtp['from_email']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full">
                            <input id="smtp_name" placeholder="From Name" value="<?= htmlspecialchars($smtp['from_name']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full col-span-2">
                        </div>
                        <button onclick="saveSmtp()" class="w-full bg-green-100 dark:bg-green-900/50 hover:bg-green-200 dark:hover:bg-green-900/80 text-green-800 dark:text-green-200 border border-green-200 dark:border-green-800 py-2.5 px-4 rounded-lg transition font-medium text-sm">Save SMTP Settings</button>
                    </div>
                </div>

                <div id="tab-profile" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white">Admin Profile</h2>
                        <div class="space-y-4">
                            <div><label class="text-sm text-gray-500 dark:text-gray-400 mb-1 block">Username</label><input id="prof_user" value="<?= htmlspecialchars($profile['username']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full"></div>
                            <div><label class="text-sm text-gray-500 dark:text-gray-400 mb-1 block">Email</label><input id="prof_email" value="<?= htmlspecialchars($profile['email']) ?>" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full"></div>
                            <div><label class="text-sm text-gray-500 dark:text-gray-400 mb-1 block">New Password (Optional)</label><input id="prof_pass" type="password" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-4 py-2 focus:outline-none focus:border-nc w-full"></div>
                            <button onclick="saveProfile()" class="w-full bg-nc hover:bg-nc_dark text-white shadow-lg shadow-blue-900/20 border border-blue-600/50 py-2.5 px-4 rounded-lg transition font-medium text-sm">Update Profile</button>
                        </div>
                    </div>
                </div>

                <div id="tab-logs" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-yellow-600 dark:text-yellow-400">Server Logs (Last 100 Lines)</h2>
                        <div class="flex gap-3">
                            <button onclick="viewLog('nc')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Nextcloud</button>
                            <button onclick="viewLog('apache')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Apache</button>
                            <button onclick="viewLog('php')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">PHP-FPM</button>
                        </div>
                    </div>
                </div>

                <div id="tab-cron" class="tab-content hidden space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-lg border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold mb-4 text-red-600 dark:text-red-400">Cron Scheduler</h2>
                        <div class="space-y-3">
                            <button onclick="runCmd('cron_status')" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 py-2.5 px-4 rounded-lg transition font-medium text-sm">Check Cron Status</button>
                            <button onclick="runCmd('cron_run')" class="w-full bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-900/20 border border-blue-600/50 py-2.5 px-4 rounded-lg transition font-medium text-sm">Force Run Cron</button>
                        </div>
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
        const TOKEN='<?= $SECRET_TOKEN ?>'; let pollInterval=null, isBusy=false; const terminal=document.getElementById('terminal');

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        window.addEventListener('DOMContentLoaded',()=>{ checkSystemState(); setInterval(checkSystemState,3000); });
        function switchTab(id){ document.querySelectorAll('.tab-content').forEach(e=>e.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active')); event.target.classList.add('active'); }
        async function callApi(action, data={}){ try { const res=await fetch('settings.php',{method:'POST',body:JSON.stringify({token:TOKEN,action:action,...data})}); return await res.json(); } catch(e){ log('Error: '+e.message,'error'); return {error:e.message}; } }
        
        async function saveSmtp(){ const res=await callApi('save_smtp',{host:document.getElementById('smtp_host').value,port:document.getElementById('smtp_port').value,user:document.getElementById('smtp_user').value,pass:document.getElementById('smtp_pass').value,enc:document.getElementById('smtp_enc').value,email:document.getElementById('smtp_email').value,name:document.getElementById('smtp_name').value}); showResult(res); }
        async function saveProfile(){ const res=await callApi('save_profile',{new_user:document.getElementById('prof_user').value,new_email:document.getElementById('prof_email').value,new_pass:document.getElementById('prof_pass').value}); showResult(res); }
        async function viewLog(type){ const res=await callApi('get_server_log',{log_type:type}); log("--- LOG VIEW: "+type.toUpperCase()+" ---\n"+res.output,'system'); }
        
        function showResult(res) {
             if(res.error) {
                Swal.fire({ icon: 'error', title: 'Error', text: res.error, background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                log(res.error, 'error');
             } else {
                Swal.fire({ icon: 'success', title: 'Success', text: res.output, timer: 3000, showConfirmButton: false, toast: true, position: 'top-end', background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                log(res.output, 'system');
             }
        }

        function setBusyState(busy,logFile=null){ isBusy=busy; const btns=document.querySelectorAll('button:not(#sun-icon):not(#moon-icon)'); const ind=document.getElementById('status-indicator'); const unl=document.getElementById('force-unlock-btn'); if(busy){ ind.innerHTML='<span class="text-red-400 font-bold animate-pulse">PROCESSING...</span>'; unl.classList.remove('hidden'); btns.forEach(b=>b.disabled=true); if(logFile&&!pollInterval)startPolling(logFile); }else{ ind.innerHTML='<div class="h-2 w-2 rounded-full bg-green-500 mr-2"></div><span class="text-gray-600 dark:text-gray-400">Idle</span>'; unl.classList.add('hidden'); btns.forEach(b=>b.disabled=false); if(pollInterval)stopPolling(); } }
        
        async function forceUnlock(){ 
            const result = await Swal.fire({
                title: 'Force Unlock?',
                text: "Only do this if a command is stuck.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Unlock',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#000'
            });
            if (result.isConfirmed) {
                await callApi('force_unlock'); setBusyState(false); 
                Swal.fire({ icon: 'success', title: 'Unlocked', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
            }
        }
        async function checkSystemState(){ try{const r=await fetch('settings.php',{method:'POST',body:JSON.stringify({token:TOKEN,action:'get_state'})});const d=await r.json();if(d.status==='busy'&&!isBusy)setBusyState(true,d.log_file);else if(d.status==='idle'&&isBusy)setBusyState(false);}catch(e){} }
        function log(t,type='info'){ const l=document.createElement('div'); l.className='mb-1'; if(type=='error')l.className+=' text-red-500 dark:text-red-400'; else if(type=='system')l.className+=' text-blue-600 dark:text-blue-400 font-bold mt-2'; l.textContent=t; terminal.appendChild(l); terminal.scrollTop=terminal.scrollHeight; }
        function clearLog(){ terminal.innerHTML=''; }
        async function runCmd(action){ if(isBusy)return; log(`> ${action}...`,'system'); setBusyState(true); const d=await callApi(action); if(d.error){log(d.error,'error');setBusyState(false);return;} if(d.status=='done'){log(d.output);log('Done.','system');setBusyState(false);}else if(d.status=='started'){log(`Started. Log: ${d.log_file}`,'system');startPolling(d.log_file);} }
        function startPolling(f){ if(pollInterval)clearInterval(pollInterval); pollInterval=setInterval(async()=>{ try{const r=await fetch('settings.php',{method:'POST',body:JSON.stringify({token:TOKEN,action:'read_log',file:f})});const d=await r.json();if(d.output){terminal.innerHTML='';const p=document.createElement('div');p.textContent=d.output;terminal.appendChild(p);terminal.scrollTop=terminal.scrollHeight;}}catch(e){} },2000); }
        function stopPolling(){ if(pollInterval)clearInterval(pollInterval); pollInterval=null; }
    </script>
</body>
</html>
