<?php
// DB Logic + Error Handling
require 'db.php'; require_login();
if (isset($_GET['logout'])) { audit_log('logout'); session_destroy(); header("Location: /login"); exit; }

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

// Read-only / polled actions we don't want cluttering the audit trail.
$AUDIT_SKIP = ['user_list', 'cron_status'];

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
            audit_log('save_smtp');
            echo json_encode(['status'=>'done','output'=>'SMTP Settings Saved.']);
        } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); } exit;
    }
    if ($action === 'save_profile') {
        try {
            $sql = "UPDATE admins SET username=?, email=? WHERE username=?";
            $pdo->prepare($sql)->execute([$input['new_user'], $input['new_email'], $_SESSION['admin_user']]);
            $pwChanged = false;
            if(!empty($input['new_pass'])) {
                $hash = password_hash($input['new_pass'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admins SET password=? WHERE username=?")->execute([$hash, $input['new_user']]);
                $pwChanged = true;
            }
            audit_log('save_profile', "username={$input['new_user']}" . ($pwChanged ? ' (password changed)' : ''));
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
    if ($action==='force_unlock'){if(file_exists($LOCK_FILE))unlink($LOCK_FILE);audit_log('force_unlock');echo json_encode(['status'=>'done','output'=>'Unlocked']);exit;}

    if (!isset($COMMANDS[$action])) { echo json_encode(['error'=>'Unknown command']); exit; }
    if (file_exists($LOCK_FILE)) { echo json_encode(['error'=>'System Busy.']); exit; }
    $config = $COMMANDS[$action]; $cmd = $config['cmd'];
    if (!in_array($action, $AUDIT_SKIP)) { audit_log($action); }
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

$pageTitle = 'NC Settings';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'settings'; $showStatusBar = true; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Settings</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">backups, smtp, profile, logs &amp; cron</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                <div class="xl:col-span-8 flex flex-col">
                    <div class="flex gap-1 mb-6 border-b border-gray-200 dark:border-line overflow-x-auto">
                        <?php $tabs=['backup'=>'BACKUPS','users'=>'USERS','smtp'=>'SMTP','profile'=>'PROFILE','logs'=>'LOGS','cron'=>'CRON'];
                        foreach($tabs as $id=>$label) echo "<button onclick=\"switchTab('tab-$id')\" class=\"tab-btn px-4 py-2.5 transition whitespace-nowrap ".($id=='backup'?'active':'')."\">$label</button>"; ?>
                    </div>

                    <div id="tab-backup" class="tab-content space-y-6 fade-in">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">Database Backups</h2>
                            <button onclick="runCmd('db_backup')" class="btn btn-primary w-full mb-4">Backup Database Now (mysqldump)</button>
                            <div class="bg-gray-50 dark:bg-surface2 p-3 rounded-lg text-xs font-mono text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-line">location: <?= htmlspecialchars($BACKUP_DIR) ?></div>
                        </div>
                    </div>

                    <div id="tab-users" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">User Management</h2>
                            <button onclick="runCmd('user_list')" class="btn btn-neutral w-full mb-4">List All Users</button>
                            <p class="text-sm text-gray-500 italic">Note: Password resets should be handled via the Nextcloud interface for security.</p>
                        </div>
                    </div>

                    <div id="tab-smtp" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">SMTP Settings</h2>
                            <div class="grid grid-cols-2 gap-4 mb-6">
                                <input id="smtp_host" placeholder="Host" value="<?= htmlspecialchars($smtp['host']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full">
                                <input id="smtp_port" placeholder="Port" value="<?= htmlspecialchars($smtp['port']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full">
                                <input id="smtp_user" placeholder="Username" value="<?= htmlspecialchars($smtp['username']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full">
                                <input id="smtp_pass" type="password" placeholder="Password" value="<?= htmlspecialchars($smtp['password']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full">
                                <input id="smtp_enc" placeholder="Encryption" value="<?= htmlspecialchars($smtp['encryption']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full">
                                <input id="smtp_name" placeholder="From Name" value="<?= htmlspecialchars($smtp['from_name']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full col-span-2">
                                <input id="smtp_email" placeholder="From Email" value="<?= htmlspecialchars($smtp['from_email']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full col-span-2">
                            </div>
                            <button onclick="saveSmtp()" class="btn btn-outline-green w-full">Save SMTP Settings</button>
                        </div>
                    </div>

                    <div id="tab-profile" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">Admin Profile</h2>
                            <div class="space-y-4">
                                <div><label class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-1 block">username</label><input id="prof_user" value="<?= htmlspecialchars($profile['username']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full"></div>
                                <div><label class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-1 block">email</label><input id="prof_email" value="<?= htmlspecialchars($profile['email']) ?>" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full"></div>
                                <div><label class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-1 block">new password (optional)</label><input id="prof_pass" type="password" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 w-full"></div>
                                <button onclick="saveProfile()" class="btn btn-primary w-full">Update Profile</button>
                            </div>
                        </div>
                    </div>

                    <div id="tab-logs" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">Server Logs <span class="section-eyebrow">last 100 lines</span></h2>
                            <div class="flex gap-3">
                                <button onclick="viewLog('nc')" class="btn btn-neutral w-full">Nextcloud</button>
                                <button onclick="viewLog('apache')" class="btn btn-neutral w-full">Apache</button>
                                <button onclick="viewLog('php')" class="btn btn-neutral w-full">PHP-FPM</button>
                            </div>
                        </div>
                    </div>

                    <div id="tab-cron" class="tab-content hidden space-y-6">
                        <div class="card p-6">
                            <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white">Cron Scheduler</h2>
                            <div class="space-y-3">
                                <button onclick="runCmd('cron_status')" class="btn btn-neutral w-full">Check Cron Status</button>
                                <button onclick="runCmd('cron_run')" class="btn btn-primary w-full">Force Run Cron</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-4">
                    <div class="bg-term rounded-xl border border-line flex flex-col h-full sticky top-6 overflow-hidden">
                        <div class="bg-surface px-4 py-3 flex justify-between items-center border-b border-line shrink-0">
                            <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-rose-500/70"></span><span class="w-2 h-2 rounded-full bg-amber-500/70"></span><span class="w-2 h-2 rounded-full bg-accent-500/70"></span><span class="text-xs font-mono text-gray-500 ml-2">admin@nc:~# output</span></div>
                            <button onclick="clearLog()" class="text-xs font-mono text-gray-500 hover:text-white font-bold tracking-wider ml-auto">CLEAR</button>
                        </div>
                        <div id="terminal" class="terminal-box flex-1 p-4 font-mono text-xs sm:text-sm text-accent-400 whitespace-pre-wrap break-all bg-term"><span class="text-gray-600">// Ready...</span><span class="term-cursor text-accent-400"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

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
                Swal.fire({ icon: 'error', title: 'Error', text: res.error, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                log(res.error, 'error');
             } else {
                Swal.fire({ icon: 'success', title: 'Success', text: res.output, timer: 3000, showConfirmButton: false, toast: true, position: 'top-end', background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                log(res.output, 'system');
             }
        }

        function setBusyState(busy,logFile=null){ isBusy=busy; const btns=document.querySelectorAll('button:not(#sun-icon):not(#moon-icon)'); const ind=document.getElementById('status-indicator'); const unl=document.getElementById('force-unlock-btn'); if(busy){ ind.innerHTML='<span class="text-rose-400 font-bold animate-pulse">BUSY</span>'; unl.classList.remove('hidden'); btns.forEach(b=>b.disabled=true); if(logFile&&!pollInterval)startPolling(logFile); }else{ ind.innerHTML='<div class="h-1.5 w-1.5 rounded-full bg-green-500 status-dot-pulse"></div><span class="text-gray-500 dark:text-gray-400">Idle</span>'; unl.classList.add('hidden'); btns.forEach(b=>b.disabled=false); if(pollInterval)stopPolling(); } }

        async function forceUnlock(){
            const result = await Swal.fire({
                title: 'Force Unlock?',
                text: "Only do this if a command is stuck.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Unlock',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#000'
            });
            if (result.isConfirmed) {
                await callApi('force_unlock'); setBusyState(false);
                Swal.fire({ icon: 'success', title: 'Unlocked', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
            }
        }
        async function checkSystemState(){ try{const r=await fetch('settings.php',{method:'POST',body:JSON.stringify({token:TOKEN,action:'get_state'})});const d=await r.json();if(d.status==='busy'&&!isBusy)setBusyState(true,d.log_file);else if(d.status==='idle'&&isBusy)setBusyState(false);}catch(e){} }
        function log(t,type='info'){ const l=document.createElement('div'); l.className='mb-1'; if(type=='error')l.className+=' text-rose-400'; else if(type=='system')l.className+=' text-violet-400 font-bold mt-2'; l.textContent=t; terminal.appendChild(l); terminal.scrollTop=terminal.scrollHeight; }
        function clearLog(){ terminal.innerHTML=''; }
        async function runCmd(action){ if(isBusy)return; log(`> ${action}...`,'system'); setBusyState(true); const d=await callApi(action); if(d.error){log(d.error,'error');setBusyState(false);return;} if(d.status=='done'){log(d.output);log('Done.','system');setBusyState(false);}else if(d.status=='started'){log(`Started. Log: ${d.log_file}`,'system');startPolling(d.log_file);} }
        function startPolling(f){ if(pollInterval)clearInterval(pollInterval); pollInterval=setInterval(async()=>{ try{const r=await fetch('settings.php',{method:'POST',body:JSON.stringify({token:TOKEN,action:'read_log',file:f})});const d=await r.json();if(d.output){terminal.innerHTML='';const p=document.createElement('div');p.textContent=d.output;terminal.appendChild(p);terminal.scrollTop=terminal.scrollHeight;}}catch(e){} },2000); }
        function stopPolling(){ if(pollInterval)clearInterval(pollInterval); pollInterval=null; }
    </script>
</body>
</html>