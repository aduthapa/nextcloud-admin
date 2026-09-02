<?php
require 'db.php'; require_login();
require __DIR__ . '/hetzner_config.php';
require __DIR__ . '/lib/hetzner.php';

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$SYS_PASS     = 'Root_Secret_2025!'; // <--- CHANGE THIS SECURITY PASSWORD

[$SERVER_ID, $resolveError] = hetzner_resolve_server_id($HETZNER_API_TOKEN, $HETZNER_SERVER_ID);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) { http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit; }
    $action = $input['action'] ?? '';

    if ($SERVER_ID === null) { echo json_encode(['error' => $resolveError ?: 'Server not resolved.']); exit; }

    // ---- Read-only lookups ----
    if ($action === 'get_server_info') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/servers/$SERVER_ID");
        echo json_encode($res['ok'] ? ['status'=>'done','server'=>$res['data']['server']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_actions') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/servers/$SERVER_ID/actions?sort=created:desc&per_page=15");
        echo json_encode($res['ok'] ? ['status'=>'done','actions'=>$res['data']['actions']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_snapshots') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/images?type=snapshot&sort=created:desc&per_page=25");
        echo json_encode($res['ok'] ? ['status'=>'done','images'=>$res['data']['images']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_backups') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/images?type=backup&bound_to=$SERVER_ID&sort=created:desc&per_page=25");
        echo json_encode($res['ok'] ? ['status'=>'done','images'=>$res['data']['images']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_isos') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/isos?per_page=50");
        echo json_encode($res['ok'] ? ['status'=>'done','isos'=>$res['data']['isos']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_images') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/images?type=system&per_page=50");
        echo json_encode($res['ok'] ? ['status'=>'done','images'=>$res['data']['images']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_server_types') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/server_types?per_page=50");
        echo json_encode($res['ok'] ? ['status'=>'done','server_types'=>$res['data']['server_types']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_firewalls') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/firewalls?per_page=25");
        echo json_encode($res['ok'] ? ['status'=>'done','firewalls'=>$res['data']['firewalls']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_volumes') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/volumes?per_page=25");
        echo json_encode($res['ok'] ? ['status'=>'done','volumes'=>$res['data']['volumes']] : ['error'=>$res['error']]); exit;
    }
    if ($action === 'get_floating_ips') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'GET', "/floating_ips?per_page=25");
        echo json_encode($res['ok'] ? ['status'=>'done','floating_ips'=>$res['data']['floating_ips']] : ['error'=>$res['error']]); exit;
    }

    // ---- Mutating actions ----
    // NOTE: DELETE /servers/{id} is intentionally never implemented here.
    $authGated = ['change_type', 'rebuild', 'delete_image'];
    if (in_array($action, $authGated, true)) {
        if (($input['sec_pass'] ?? '') !== $SYS_PASS) {
            audit_log($action, null, 'auth_failed');
            echo json_encode(['error' => 'Invalid Security Password.']); exit;
        }
    }

    $simpleActions = ['poweron','poweroff','reboot','reset','shutdown','disable_rescue','enable_backup','disable_backup','detach_iso'];
    if (in_array($action, $simpleActions, true)) {
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/$action");
        audit_log("hetzner_$action");
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action']] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'enable_rescue') {
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/enable_rescue", ['type' => 'linux64']);
        audit_log('hetzner_enable_rescue');
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action'],'root_password'=>$res['data']['root_password'] ?? null] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'create_image') {
        $desc = trim($input['arg'] ?? '') ?: ('manual-' . date('Y-m-d-His'));
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/create_image", ['type' => 'snapshot', 'description' => $desc]);
        audit_log('hetzner_create_image', $desc);
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action']] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'attach_iso') {
        $iso = trim($input['arg'] ?? '');
        if ($iso === '') { echo json_encode(['error'=>'No ISO selected.']); exit; }
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/attach_iso", ['iso' => $iso]);
        audit_log('hetzner_attach_iso', $iso);
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action']] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'change_type') {
        $type = trim($input['arg'] ?? '');
        if ($type === '') { echo json_encode(['error'=>'No server type selected.']); exit; }
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/change_type", ['server_type' => $type, 'upgrade_disk' => false]);
        audit_log('hetzner_change_type', $type);
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action']] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'rebuild') {
        $image = trim($input['arg'] ?? '');
        if ($image === '') { echo json_encode(['error'=>'No image selected.']); exit; }
        $res = hetzner_request($HETZNER_API_TOKEN, 'POST', "/servers/$SERVER_ID/actions/rebuild", ['image' => $image]);
        audit_log('hetzner_rebuild', $image);
        echo json_encode($res['ok'] ? ['status'=>'done','action'=>$res['data']['action']] : ['error'=>$res['error']]); exit;
    }

    if ($action === 'delete_image') {
        $imageId = (int) ($input['arg'] ?? 0);
        if ($imageId <= 0) { echo json_encode(['error'=>'No image selected.']); exit; }
        $res = hetzner_request($HETZNER_API_TOKEN, 'DELETE', "/images/$imageId");
        audit_log('hetzner_delete_image', (string) $imageId);
        echo json_encode($res['ok'] ? ['status'=>'done'] : ['error'=>$res['error']]); exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$pageTitle = 'Hetzner Management';
$tokenConfigured = strpos($HETZNER_API_TOKEN, 'CHANGE_THIS') === false;
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'nextcloud'; $activeChild = 'hetzner'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Hetzner Management</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">hetzner cloud api - this server only, never deletes it</p>
            </div>

            <?php if (!$tokenConfigured): ?>
            <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 text-amber-700 dark:text-amber-400 px-4 py-3 rounded-lg text-sm mb-6">
                <strong>Not configured yet.</strong> Add a real Hetzner Cloud API token in <a href="/settings#integrations" class="underline font-semibold hover:text-amber-800 dark:hover:text-amber-300">Settings &rarr; Integrations</a> (Hetzner Project &rarr; Security &rarr; API Tokens).
            </div>
            <?php elseif ($resolveError): ?>
            <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 text-amber-700 dark:text-amber-400 px-4 py-3 rounded-lg text-sm mb-6"><?= htmlspecialchars($resolveError) ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                <div class="xl:col-span-8 flex flex-col space-y-6">

                    <div class="card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="icon-badge bg-red-500/10 text-red-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-14 0h14M7 8h.01M7 16h.01"/></svg></div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Server</h2>
                            </div>
                            <button onclick="fetchServerInfo()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                        </div>
                        <dl id="server-info" class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-3 text-sm">
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">Status</dt><dd id="si-status" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">Type</dt><dd id="si-type" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">Location</dt><dd id="si-location" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">IPv4</dt><dd id="si-ipv4" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">Image</dt><dd id="si-image" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-500 text-xs">Backups</dt><dd id="si-backups" class="font-mono text-gray-900 dark:text-white mt-0.5">...</dd></div>
                        </dl>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="card p-6">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="icon-badge bg-blue-500/10 text-blue-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 3v9m6.364.636a9 9 0 11-12.728 0"/></svg></div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Power</h2>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <button onclick="run('poweron')" class="btn btn-outline-green w-full">Power On</button>
                                <button onclick="confirmRun('Hard power off?','poweroff')" class="btn btn-outline-red w-full">Power Off</button>
                                <button onclick="run('reboot')" class="btn btn-neutral w-full">Reboot</button>
                                <button onclick="confirmRun('Hard reset the VM?','reset')" class="btn btn-neutral w-full">Reset</button>
                            </div>
                            <button onclick="confirmRun('Graceful ACPI shutdown?','shutdown')" class="btn btn-neutral w-full mt-3">Shutdown (ACPI)</button>
                        </div>

                        <div class="card p-6">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="icon-badge bg-amber-500/10 text-amber-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg></div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Rescue Mode</h2>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-500 mb-3">Boots a temporary Linux rescue system on next power cycle - for when the OS itself is unbootable.</p>
                            <div class="grid grid-cols-2 gap-3">
                                <button onclick="confirmRun('Enable rescue mode?','enable_rescue')" class="btn btn-outline-green w-full">Enable</button>
                                <button onclick="run('disable_rescue')" class="btn btn-outline-red w-full">Disable</button>
                            </div>
                        </div>
                    </div>

                    <div class="card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="icon-badge bg-violet-500/10 text-violet-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">Backups &amp; Snapshots</h2>
                            </div>
                            <button onclick="fetchImages()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                        </div>
                        <div class="flex gap-3 mb-4">
                            <button onclick="run('enable_backup')" class="btn btn-outline-green flex-1">Enable Auto-Backups</button>
                            <button onclick="confirmRun('Disable automatic backups?','disable_backup')" class="btn btn-outline-red flex-1">Disable Auto-Backups</button>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3 mb-4">
                            <input type="text" id="snapshot-desc" placeholder="Snapshot description (optional)" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 transition">
                            <button onclick="createSnapshot()" class="btn btn-primary sm:w-48">Create Snapshot</button>
                        </div>
                        <h3 class="section-eyebrow mb-2">Snapshots</h3>
                        <div id="snapshot-list" class="space-y-1.5 text-xs font-mono mb-4">...</div>
                        <h3 class="section-eyebrow mb-2">Auto-Backups</h3>
                        <div id="backup-list" class="space-y-1.5 text-xs font-mono">...</div>
                    </div>

                    <div class="card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="icon-badge bg-sky-500/10 text-sky-500"><svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a3 3 0 013-3h13.5a3 3 0 013-3"/></svg></div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white">ISO</h2>
                            </div>
                            <button onclick="fetchIsos()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh List</button>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <select id="iso-select" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none"><option value="">Loading...</option></select>
                            <button onclick="run('attach_iso', document.getElementById('iso-select').value)" class="btn btn-outline-green">Attach</button>
                            <button onclick="run('detach_iso')" class="btn btn-outline-red">Detach</button>
                        </div>
                    </div>

                    <div class="card p-6" style="border-top:2px solid #f59e0b">
                        <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white flex items-center gap-2">Resize <span class="badge bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-300">Downtime + Auth Required</span></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-500 mb-3">Server usually needs to be powered off first. Disk upgrades are one-way.</p>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <select id="type-select" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none"><option value="">Loading...</option></select>
                            <button onclick="confirmRun('Change server type? This may cause downtime.','change_type',document.getElementById('type-select').value,true)" class="btn btn-danger sm:w-48">Change Type</button>
                        </div>
                    </div>

                    <div class="card p-6" style="border-top:2px solid #e11d48">
                        <h2 class="text-base font-bold mb-4 text-gray-900 dark:text-white flex items-center gap-2">Rebuild <span class="badge bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-300">Wipes All Data</span></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-500 mb-3">Reinstalls the OS from an image. Keeps the same server/IP, but everything on disk is gone - Nextcloud and this admin panel included. Take a snapshot first.</p>
                        <select id="rebuild-image-select" class="w-full bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none mb-3"><option value="">Loading...</option></select>
                        <button onclick="rebuildConfirm()" class="btn btn-danger w-full">Rebuild Server</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-3"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Firewalls</h2><button onclick="fetchFirewalls()" class="text-xs text-gray-500 hover:text-accent-500">refresh</button></div>
                            <div id="firewall-list" class="space-y-1.5 text-xs font-mono">...</div>
                        </div>
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-3"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Volumes</h2><button onclick="fetchVolumes()" class="text-xs text-gray-500 hover:text-accent-500">refresh</button></div>
                            <div id="volume-list" class="space-y-1.5 text-xs font-mono">...</div>
                        </div>
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-3"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Floating IPs</h2><button onclick="fetchFloatingIps()" class="text-xs text-gray-500 hover:text-accent-500">refresh</button></div>
                            <div id="floating-ip-list" class="space-y-1.5 text-xs font-mono">...</div>
                        </div>
                    </div>

                    <div class="card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-bold text-gray-900 dark:text-white">Recent Actions</h2>
                            <button onclick="fetchActions()" class="btn btn-neutral py-1.5 px-3 text-xs">Refresh</button>
                        </div>
                        <div id="action-history" class="space-y-1.5 text-xs font-mono">...</div>
                    </div>
                </div>

                <div class="xl:col-span-4">
                    <div class="bg-term rounded-xl border border-line flex flex-col h-full sticky top-6 overflow-hidden">
                        <div class="bg-surface px-4 py-3 flex justify-between items-center border-b border-line shrink-0">
                            <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-rose-500/70"></span><span class="w-2 h-2 rounded-full bg-amber-500/70"></span><span class="w-2 h-2 rounded-full bg-accent-500/70"></span><span class="text-xs font-mono text-gray-500 ml-2">hetzner api</span></div>
                            <button onclick="clearLog()" class="text-xs font-mono text-gray-500 hover:text-white font-bold tracking-wider ml-auto">CLEAR</button>
                        </div>
                        <div id="terminal" class="terminal-box flex-1 p-4 font-mono text-xs sm:text-sm text-accent-400 whitespace-pre-wrap break-all bg-term overflow-y-auto"><span class="text-gray-600">// Ready...</span></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'hetzner-management.php';
        const terminal = document.getElementById('terminal');

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; }
            else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
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

        function swalTheme() {
            return { background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff', color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937' };
        }

        async function confirmRun(msg, action, arg = null, needsAuth = false) {
            const result = await Swal.fire({ title: 'Confirm', text: msg, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48', ...swalTheme() });
            if (result.isConfirmed) run(action, arg, needsAuth);
        }

        async function run(action, arg = null, needsAuth = false) {
            let pass = null;
            if (needsAuth) {
                const { value: password } = await Swal.fire({ title: 'Security Password Required', input: 'password', showCancelButton: true, confirmButtonColor: '#e11d48', ...swalTheme() });
                if (!password) { log('Cancelled: Security Password required.', 'error'); return; }
                pass = password;
            }
            log(`Executing: ${action}...`, 'system');
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action, arg, sec_pass: pass }) });
                const data = await res.json();
                if (data.error) { log(data.error, 'error'); return; }
                log(JSON.stringify(data, null, 2));
                if (data.root_password) {
                    Swal.fire({ title: 'Rescue Root Password', html: `<div class="font-mono text-sm">${data.root_password}</div><div class="text-xs mt-2 text-gray-400">Shown once - copy it now.</div>`, ...swalTheme() });
                }
                log('Done.', 'system');
                if (action === 'get_server_info' || ['poweron','poweroff','reboot','reset','shutdown'].includes(action)) fetchServerInfo();
            } catch (e) { log(`Connection Failed: ${e.message}`, 'error'); }
        }

        async function createSnapshot() {
            const desc = document.getElementById('snapshot-desc').value.trim();
            await run('create_image', desc);
            fetchImages();
        }

        async function rebuildConfirm() {
            const image = document.getElementById('rebuild-image-select').value;
            if (!image) { log('Select an image first.', 'error'); return; }
            const { value: typed } = await Swal.fire({
                title: 'Type the server name to confirm rebuild',
                text: 'This wipes the disk and reinstalls the OS. Type the server name shown above to proceed.',
                input: 'text', showCancelButton: true, confirmButtonColor: '#e11d48', ...swalTheme()
            });
            const expected = document.getElementById('si-status').dataset.serverName || '';
            if (!typed || typed !== expected) { log('Rebuild cancelled: name did not match.', 'error'); return; }
            run('rebuild', image, true);
        }

        async function fetchServerInfo() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_server_info' }) });
            const data = await res.json();
            if (data.error) { log(data.error, 'error'); return; }
            const s = data.server;
            document.getElementById('si-status').textContent = s.status;
            document.getElementById('si-status').dataset.serverName = s.name;
            document.getElementById('si-type').textContent = s.server_type?.name || '-';
            document.getElementById('si-location').textContent = s.datacenter?.location?.city || '-';
            document.getElementById('si-ipv4').textContent = s.public_net?.ipv4?.ip || '-';
            document.getElementById('si-image').textContent = s.image?.description || s.image?.name || '-';
            document.getElementById('si-backups').textContent = s.backup_window ? 'ON (' + s.backup_window + ')' : 'OFF';
        }

        function renderList(elId, items, formatter) {
            const el = document.getElementById(elId);
            el.innerHTML = '';
            if (!items || !items.length) { el.textContent = 'None.'; return; }
            items.forEach(item => {
                const row = document.createElement('div');
                row.className = 'flex justify-between items-center gap-2';
                row.appendChild(formatter(item));
                el.appendChild(row);
            });
        }

        async function fetchImages() {
            const snapRes = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_snapshots' }) });
            const snapData = await snapRes.json();
            renderList('snapshot-list', snapData.images, img => {
                const wrap = document.createElement('div');
                wrap.className = 'flex justify-between items-center w-full';
                const label = document.createElement('span'); label.textContent = `#${img.id} ${img.description} (${img.created?.slice(0,10)})`;
                const del = document.createElement('button'); del.textContent = 'delete'; del.className = 'text-rose-500 hover:underline ml-2 shrink-0';
                del.onclick = async () => { const r = await Swal.fire({title:'Delete this snapshot?', icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48', ...swalTheme()}); if (r.isConfirmed) { const { value: password } = await Swal.fire({ title: 'Security Password', input: 'password', showCancelButton: true, ...swalTheme() }); if (password) { await fetch(ENDPOINT, {method:'POST', body: JSON.stringify({token:TOKEN, action:'delete_image', arg: img.id, sec_pass: password})}); fetchImages(); } } };
                wrap.appendChild(label); wrap.appendChild(del);
                return wrap;
            });

            const backupRes = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_backups' }) });
            const backupData = await backupRes.json();
            renderList('backup-list', backupData.images, img => {
                const span = document.createElement('span'); span.textContent = `#${img.id} ${img.description} (${img.created?.slice(0,10)})`;
                return span;
            });
        }

        async function fetchIsos() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_isos' }) });
            const data = await res.json();
            const sel = document.getElementById('iso-select');
            sel.innerHTML = '';
            (data.isos || []).forEach(iso => { const opt = document.createElement('option'); opt.value = iso.id; opt.textContent = iso.name; sel.appendChild(opt); });
        }

        async function fetchServerTypesAndImages() {
            const typeRes = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_server_types' }) });
            const typeData = await typeRes.json();
            const typeSel = document.getElementById('type-select');
            typeSel.innerHTML = '';
            (typeData.server_types || []).filter(t => !t.deprecated).forEach(t => { const opt = document.createElement('option'); opt.value = t.name; opt.textContent = `${t.name} (${t.cores} vCPU / ${t.memory}GB / ${t.disk}GB)`; typeSel.appendChild(opt); });

            const imgRes = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_images' }) });
            const imgData = await imgRes.json();
            const imgSel = document.getElementById('rebuild-image-select');
            imgSel.innerHTML = '';
            (imgData.images || []).forEach(img => { const opt = document.createElement('option'); opt.value = img.id; opt.textContent = img.description || img.name; imgSel.appendChild(opt); });
        }

        async function fetchFirewalls() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_firewalls' }) });
            const data = await res.json();
            renderList('firewall-list', data.firewalls, fw => { const s = document.createElement('span'); s.textContent = `${fw.name} (${fw.rules?.length || 0} rules)`; return s; });
        }
        async function fetchVolumes() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_volumes' }) });
            const data = await res.json();
            renderList('volume-list', data.volumes, v => { const s = document.createElement('span'); s.textContent = `${v.name} (${v.size}GB)`; return s; });
        }
        async function fetchFloatingIps() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_floating_ips' }) });
            const data = await res.json();
            renderList('floating-ip-list', data.floating_ips, ip => { const s = document.createElement('span'); s.textContent = `${ip.ip} (${ip.type})`; return s; });
        }
        async function fetchActions() {
            const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'get_actions' }) });
            const data = await res.json();
            renderList('action-history', data.actions, a => {
                const s = document.createElement('span');
                const color = a.status === 'success' ? 'text-accent-500' : (a.status === 'error' ? 'text-rose-500' : 'text-amber-500');
                s.innerHTML = `<span class="${color}">${a.status}</span> ${a.command} - ${a.progress}% (${(a.started||'').replace('T',' ').slice(0,19)})`;
                return s;
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchServerInfo();
            fetchImages();
            fetchIsos();
            fetchServerTypesAndImages();
            fetchFirewalls();
            fetchVolumes();
            fetchFloatingIps();
            fetchActions();
        });
    </script>
</body>
</html>
