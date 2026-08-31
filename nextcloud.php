<?php
require 'db.php'; require_login();

// ==========================================
// CONFIGURATION
// ==========================================
$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$SYS_PASS     = 'Root_Secret_2025!'; // <--- CHANGE THIS SECURITY PASSWORD
$DATA_DIR     = __DIR__ . '/data';
$LOCK_FILE    = $DATA_DIR . '/system.lock';
$RUNNER       = 'sudo /usr/local/bin/nc-runner'; // The Gatekeeper

if (!is_dir($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); chown($DATA_DIR, 'apache'); }

// ==========================================
// COMMAND DEFINITIONS - all occ (Nextcloud) commands live on this page
// ==========================================
$COMMANDS = [
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

    // --- REPAIR (Async) ---
    'maint_repair'   => ['type' => 'async', 'cmd' => "$RUNNER occ maintenance:repair", 'log' => 'maint_repair.log'],
    'db_add_columns' => ['type' => 'async', 'cmd' => "$RUNNER occ db:add-missing-columns", 'log' => 'db_columns.log'],
    'db_add_pks'     => ['type' => 'async', 'cmd' => "$RUNNER occ db:add-missing-primary-keys", 'log' => 'db_pks.log'],
    'files_cleanup'  => ['type' => 'async', 'cmd' => "$RUNNER occ files:cleanup", 'log' => 'files_cleanup.log'],

    // --- DIAGNOSTICS ---
    'integrity_check'  => ['type' => 'async', 'cmd' => "$RUNNER occ integrity:check-core", 'log' => 'integrity_check.log'],
    'data_fingerprint' => ['type' => 'sync', 'cmd' => "$RUNNER occ maintenance:data-fingerprint"],
    'config_list'      => ['type' => 'sync', 'cmd' => "$RUNNER occ config:list system"],

    // --- USER MANAGEMENT ---
    'user_enable'         => ['type' => 'sync', 'cmd' => "$RUNNER occ user:enable"],
    'user_disable'        => ['type' => 'sync', 'cmd' => "$RUNNER occ user:disable"],
    'user_delete'         => ['type' => 'sync', 'cmd' => "$RUNNER occ user:delete", 'auth' => true],
    'user_2fa_disable'    => ['type' => 'sync', 'cmd' => "$RUNNER occ twofactorauth:disable"],
    'user_reset_password' => ['type' => 'sync', 'cmd' => "$RUNNER occ user:resetpassword"],
];

// Actions that are read-only / polled on an interval - kept out of the audit trail to avoid noise.
$AUDIT_SKIP = ['status', 'app_list'];

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
    } elseif (in_array($action, ['user_enable','user_disable','user_delete','user_2fa_disable'])) {
        $uid = $input['arg'] ?? '';
        if (preg_match('/^[a-zA-Z0-9_.@-]+$/', $uid)) $cmd .= ' '.escapeshellarg($uid);
        else { echo json_encode(['error'=>'Invalid Username']); exit; }
    } elseif ($action === 'user_reset_password') {
        $uid = $input['arg'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.@-]+$/', $uid)) { echo json_encode(['error'=>'Invalid Username']); exit; }
        // occ user:resetpassword prompts interactively for a new password. Rather than
        // trust env-var forwarding through the opaque nc-runner sudo wrapper, generate a
        // random password here and pipe it via stdin (guaranteed by POSIX shell semantics
        // regardless of sudo's env policy) - it's only ever echoed back on success, never logged.
        $newPass = bin2hex(random_bytes(9));
        $cmd = "printf '%s\\n%s\\n' " . escapeshellarg($newPass) . " " . escapeshellarg($newPass) . " | $cmd " . escapeshellarg($uid)
             . " && echo " . escapeshellarg("New password for $uid: $newPass");
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

$pageTitle = 'Nextcloud';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'nextcloud'; $activeChild = 'nextcloud'; $showStatusBar = true; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Nextcloud</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">one-click occ commands</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                <div class="xl:col-span-8 flex flex-col space-y-8">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Apps &amp; Users</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-violet-500/10 text-violet-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11 4a2 2 0 114 0v1h2.5a1.5 1.5 0 011.5 1.5V9h1a2 2 0 110 4h-1v2.5a1.5 1.5 0 01-1.5 1.5H15v1a2 2 0 11-4 0v-1h-2.5A1.5 1.5 0 017 15.5V13H5.5A1.5 1.5 0 014 11.5v-3A1.5 1.5 0 015.5 7H7V5.5A1.5 1.5 0 018.5 4H11v0z"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Plugin Manager</h2>
                                    </div>
                                    <span class="section-eyebrow">occ app:*</span>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <button onclick="runCmd('app_enable',true,false,'Enter App ID','e.g. user_saml')" class="btn btn-outline-green w-full">Enable</button>
                                    <button onclick="runCmd('app_disable',true,false,'Enter App ID','e.g. user_saml')" class="btn btn-outline-red w-full">Disable</button>
                                    <button onclick="runCmd('app_list')" class="btn btn-neutral w-full">List Apps</button>
                                    <button onclick="runCmd('update_apps')" class="btn btn-primary w-full">Update All</button>
                                </div>
                            </div>
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-fuchsia-500/10 text-fuchsia-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">User Management</h2>
                                    </div>
                                    <span class="section-eyebrow">occ user:*</span>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <button onclick="runCmd('user_enable',true,false,'Enable User','Username')" class="btn btn-outline-green w-full">Enable</button>
                                    <button onclick="runCmd('user_disable',true,false,'Disable User','Username')" class="btn btn-outline-red w-full">Disable</button>
                                    <button onclick="runCmd('user_2fa_disable',true,false,'Disable 2FA For','Username')" class="btn btn-neutral w-full">Disable 2FA</button>
                                    <button onclick="runCmd('user_reset_password',true,false,'Reset Password For','Username')" class="btn btn-primary w-full">Reset Password</button>
                                </div>
                                <button onclick="runCmd('user_delete',true,true,'Delete User','Username')" class="btn btn-danger w-full">Delete User</button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Maintenance</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-blue-500/10 text-blue-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.077-3.078a6 6 0 01-7.94 7.94l-6.816 6.816a2.121 2.121 0 01-3-3l6.816-6.816a6 6 0 017.94-7.94l-3.07 3.07z"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Routine</h2>
                                    </div>
                                    <span class="section-eyebrow">occ</span>
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
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-amber-500/10 text-amber-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Heavy Operations</h2>
                                    </div>
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

                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Repair &amp; Diagnostics</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-orange-500/10 text-orange-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11 5.5a4.5 4.5 0 016.02 4.24l-2.86 2.86a1 1 0 000 1.41l2.83 2.83a4.5 4.5 0 01-6.32-6.32l-4.79-4.79a4.5 4.5 0 00-1.4 8.06l-4.3 4.3a1.5 1.5 0 002.12 2.12l4.3-4.3a4.5 4.5 0 008.06-1.4"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Repair</h2>
                                    </div>
                                    <span class="section-eyebrow">Async</span>
                                </div>
                                <div class="space-y-3">
                                    <button onclick="runCmd('maint_repair')" class="btn btn-primary w-full">Maintenance Repair</button>
                                    <button onclick="runCmd('db_add_columns')" class="btn btn-neutral w-full">Add Missing DB Columns</button>
                                    <button onclick="runCmd('db_add_pks')" class="btn btn-neutral w-full">Add Missing Primary Keys</button>
                                    <button onclick="runCmd('files_cleanup')" class="btn btn-neutral w-full">Cleanup File Cache</button>
                                </div>
                            </div>
                            <div class="card p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="icon-badge bg-sky-500/10 text-sky-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Diagnostics</h2>
                                    </div>
                                    <span class="section-eyebrow">occ</span>
                                </div>
                                <div class="space-y-3">
                                    <button onclick="runCmd('integrity_check')" class="btn btn-neutral w-full">Core Integrity Check</button>
                                    <button onclick="runCmd('data_fingerprint')" class="btn btn-neutral w-full">Update Data Fingerprint</button>
                                    <button onclick="runCmd('config_list')" class="btn btn-neutral w-full">View System Config</button>
                                </div>
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
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'nextcloud.php';
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
        });

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

            await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'force_unlock' }) });
            setBusyState(false);
        }

        async function checkSystemState() {
            try {
                const response = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_state' }) });
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

        async function runCmd(action, reqArg = false, needsAuth = false, argTitle = 'Enter App ID', argPlaceholder = 'App ID') {
            if (isBusy) return;
            let arg = null;
            let pass = null;

            if (reqArg) {
                const { value: argValue } = await Swal.fire({
                    title: argTitle,
                    input: 'text',
                    inputLabel: argPlaceholder,
                    inputPlaceholder: argPlaceholder,
                    showCancelButton: true,
                    background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                    color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
                });
                if (!argValue) return;
                arg = argValue;
            }

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
                const response = await fetch(ENDPOINT, {
                    method: 'POST',
                    body: JSON.stringify({ token: TOKEN, action: action, arg: arg, sec_pass: pass })
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
                    const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'read_log', file: filename }) });
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
