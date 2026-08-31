<?php
require 'db.php'; require_login();
$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';
$DATA_DIR = __DIR__ . '/data';
$LOG_DIR = $DATA_DIR . '/security_logs';
if (!is_dir($LOG_DIR)) { mkdir($LOG_DIR, 0755, true); chown($LOG_DIR, 'apache'); }

// --- Backend Logic ---
$TOOLS = [
    'lynis' => [
        'name' => 'Lynis', 'bin' => '/usr/bin/lynis',
        'check' => 'test -f /usr/bin/lynis',
        'install' => 'sudo dnf install -y lynis',
        'remove' => 'sudo dnf remove -y lynis',
        'scan' => 'sudo /usr/bin/lynis audit system --quick --no-colors',
        'log' => '/var/log/lynis.log' // Lynis default
    ],
    'chkrootkit' => [
        'name' => 'Chkrootkit', 'bin' => '/usr/sbin/chkrootkit',
        'check' => 'test -f /usr/sbin/chkrootkit',
        'install' => 'sudo dnf install -y chkrootkit',
        'remove' => 'sudo dnf remove -y chkrootkit',
        'scan' => 'sudo /usr/sbin/chkrootkit',
        'log' => $LOG_DIR.'/chkrootkit.log' // Custom log
    ],
    'rkhunter' => [
        'name' => 'Rootkit Hunter', 'bin' => '/usr/bin/rkhunter',
        'check' => 'test -f /usr/bin/rkhunter',
        'install' => 'sudo dnf install -y rkhunter',
        'remove' => 'sudo dnf remove -y rkhunter',
        'scan' => 'sudo /usr/bin/rkhunter --check --sk --rwo', // Report warnings only
        'log' => '/var/log/rkhunter.log'
    ],
    'trivy' => [
        'name' => 'Trivy', 'bin' => '/usr/local/bin/trivy',
        'check' => 'test -f /usr/local/bin/trivy',
        'install' => 'sudo sh -c "curl -sfL https://raw.githubusercontent.com/aquasecurity/trivy/main/contrib/install.sh | sh -s -- -b /usr/local/bin"',
        'remove' => 'sudo rm -f /usr/local/bin/trivy',
        'scan' => 'sudo /usr/local/bin/trivy fs / --scanners vuln --severity HIGH,CRITICAL --no-progress',
        'log' => $LOG_DIR.'/trivy.log'
    ],
    'lmd' => [
        'name' => 'Linux Malware Detect', 'bin' => '/usr/local/maldetect/maldet',
        'check' => 'test -d /usr/local/maldetect',
        'install' => 'sudo sh -c "wget -q http://www.rfxn.com/downloads/maldetect-current.tar.gz && tar -xzf maldetect-current.tar.gz && cd maldetect-* && ./install.sh && cd .. && rm -rf maldetect*"',
        'remove' => 'sudo sh -c "rm -rf /usr/local/maldetect /usr/local/bin/maldet"',
        'scan' => 'sudo /usr/local/maldetect/maldet --scan-all /var/www/html',
        'log' => '/usr/local/maldetect/event_log'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['token'] ?? '') !== $SECRET_TOKEN) exit(json_encode(['error'=>'Invalid Token']));

    $action = $input['action'];
    $tool = $input['tool'] ?? '';

    if ($action === 'get_status') {
        $status = [];
        foreach ($TOOLS as $k => $v) {
            $exists = shell_exec($v['check'] . ' && echo "1" || echo "0"');
            $status[$k] = trim($exists) === '1';
        }
        echo json_encode($status); exit;
    }

    if (!isset($TOOLS[$tool])) exit(json_encode(['error' => 'Unknown Tool']));
    $cfg = $TOOLS[$tool];

    if ($action === 'install') {
        // Run install async but frontend will lock
        $cmd = $cfg['install'] . ' > ' . $LOG_DIR . '/install_'.$tool.'.log 2>&1';
        shell_exec($cmd);
        audit_log('security_tool_install', "tool=$tool");
        echo json_encode(['status'=>'done', 'output'=>'Installation completed.']);
    }
    elseif ($action === 'remove') {
        shell_exec($cfg['remove']);
        audit_log('security_tool_remove', "tool=$tool");
        echo json_encode(['status'=>'done', 'output'=>'Removed.']);
    }
    elseif ($action === 'scan') {
        $logTarget = isset($cfg['log']) && strpos($cfg['log'], $LOG_DIR) === 0 ? $cfg['log'] : $LOG_DIR.'/'.$tool.'_scan.log';
        // Async scan
        $cmd = 'nohup sh -c "'.$cfg['scan'].' > '.$logTarget.'" >/dev/null 2>&1 & echo $!';
        shell_exec($cmd);
        audit_log('security_scan_start', "tool=$tool");
        echo json_encode(['status'=>'started', 'log_file'=>$logTarget]);
    }
    elseif ($action === 'read_log') {
        $file = isset($input['file']) ? $input['file'] : $cfg['log'];
        if (file_exists($file)) {
            $out = shell_exec("sudo tail -n 100 ".escapeshellarg($file));
            echo json_encode(['output'=>$out]);
        } else {
            echo json_encode(['output'=>'Log not found (Run a scan first).']);
        }
    }
    exit;
}

$pageTitle = 'Security Center';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
    .terminal-box { height: 500px; }
    #install-lock { backdrop-filter: blur(5px); }
</style>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-ink flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-14 h-14 border-4 border-accent-700/30 border-t-accent-400 rounded-full animate-spin"></div></div>
    </div>

    <div id="install-lock" class="hidden fixed inset-0 z-[200] bg-white/85 dark:bg-black/85 flex flex-col items-center justify-center text-center transition-colors">
        <div class="w-20 h-20 border-4 border-rose-900/50 border-t-rose-500 rounded-full animate-spin mb-6"></div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 font-mono">INSTALLING COMPONENT</h2>
        <p class="text-rose-600 dark:text-rose-400 font-mono text-sm animate-pulse">DO NOT LEAVE THIS PAGE</p>
        <p id="lock-msg" class="text-gray-500 mt-4 text-sm max-w-md">System operations in progress...</p>
    </div>

    <?php $activePage = 'security'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Security Center</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">install, scan &amp; review host-level tooling</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <div class="lg:col-span-3 space-y-3">
                    <?php foreach($TOOLS as $key => $tool): ?>
                    <div id="card-<?= $key ?>" class="card p-4 relative overflow-hidden group transition hover:border-accent-500/40">
                        <div class="absolute top-3 right-3">
                            <div id="status-dot-<?= $key ?>" class="w-2.5 h-2.5 rounded-full bg-gray-300 dark:bg-line"></div>
                        </div>
                        <h3 class="font-bold text-sm text-gray-900 dark:text-white mb-1"><?= $tool['name'] ?></h3>
                        <p class="text-xs text-gray-500 mb-4 font-mono">bin: <?= basename($tool['bin']) ?></p>

                        <div class="flex flex-col gap-2" id="actions-<?= $key ?>">
                            <button onclick="installTool('<?= $key ?>')" id="btn-inst-<?= $key ?>" class="btn btn-outline-red w-full text-xs">INSTALL NOW</button>
                            <button onclick="openTab('<?= $key ?>')" id="btn-open-<?= $key ?>" class="hidden btn btn-neutral w-full text-xs">OPEN PANEL</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="lg:col-span-9">
                    <div id="panel-welcome" class="card p-10 text-center h-full flex flex-col items-center justify-center opacity-70">
                        <svg class="w-16 h-16 text-gray-300 dark:text-line mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        <h2 class="text-lg font-bold text-gray-500 dark:text-gray-400 font-mono">select a security tool</h2>
                        <p class="text-gray-500 mt-2 text-sm">Install tools via the sidebar to begin scanning.</p>
                    </div>

                    <?php foreach($TOOLS as $key => $tool): ?>
                    <div id="panel-<?= $key ?>" class="hidden flex flex-col h-full space-y-6">
                        <div class="card p-6">
                            <div class="flex justify-between items-start mb-6">
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                                        <?= $tool['name'] ?>
                                        <span class="badge bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400">INSTALLED</span>
                                    </h2>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Scanner ready. Default mode is <strong>Detect &amp; Report</strong>.</p>
                                </div>
                                <button onclick="removeTool('<?= $key ?>')" class="text-xs text-rose-500 hover:text-rose-700 dark:hover:text-rose-300 hover:underline">Uninstall Tool</button>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <button onclick="runScan('<?= $key ?>')" class="btn btn-primary py-3.5 text-sm">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                                    RUN SCAN
                                </button>
                                <button onclick="fetchLog('<?= $key ?>')" class="btn btn-neutral py-3.5 text-sm">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    VIEW REPORT
                                </button>
                            </div>
                        </div>

                        <div class="flex-1 bg-term rounded-xl border border-line flex flex-col overflow-hidden">
                            <div class="bg-surface px-4 py-2 border-b border-line flex justify-between items-center">
                                <span class="text-xs font-mono text-gray-500">root@server:~/security/<?= $key ?># output</span>
                                <span id="status-<?= $key ?>" class="text-xs font-mono font-bold text-gray-500 uppercase">IDLE</span>
                            </div>
                            <div id="term-<?= $key ?>" class="terminal-box p-4 font-mono text-sm text-accent-400 whitespace-pre-wrap">Waiting for command...</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        let activeTool = null;

        // Prevent navigation during install
        let isLocked = false;
        window.onbeforeunload = function() { if (isLocked) return "Installation in progress. Please wait."; };

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        // --- Init ---
        window.addEventListener('load', () => {
            // Hide Loader
            const l = document.getElementById('global-loader');
            l.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => l.style.display = 'none', 500);

            // Check Tools
            checkTools();
        });

        async function callApi(data) {
            const res = await fetch('security.php', { method: 'POST', body: JSON.stringify({ token: TOKEN, ...data }) });
            return await res.json();
        }

        async function checkTools() {
            const status = await callApi({ action: 'get_status' });
            for (const [tool, exists] of Object.entries(status)) {
                const dot = document.getElementById(`status-dot-${tool}`);
                const btnInst = document.getElementById(`btn-inst-${tool}`);
                const btnOpen = document.getElementById(`btn-open-${tool}`);

                if (exists) {
                    dot.className = "w-2.5 h-2.5 rounded-full bg-accent-500 status-dot-pulse";
                    btnInst.classList.add('hidden');
                    btnOpen.classList.remove('hidden');
                } else {
                    dot.className = "w-2.5 h-2.5 rounded-full bg-rose-500";
                    btnInst.classList.remove('hidden');
                    btnOpen.classList.add('hidden');
                    // If this panel is open but tool got removed
                    if (activeTool === tool) document.getElementById(`panel-${tool}`).classList.add('hidden');
                }
            }
        }

        function openTab(tool) {
            document.getElementById('panel-welcome').classList.add('hidden');
            document.querySelectorAll('[id^="panel-"]').forEach(p => p.classList.add('hidden'));
            document.getElementById(`panel-${tool}`).classList.remove('hidden');
            activeTool = tool;
        }

        async function installTool(tool) {
             const result = await Swal.fire({
                title: `Install ${tool}?`,
                text: "This may take a minute. Do not leave the page.",
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#0d9488',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Install',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!result.isConfirmed) return;

            // LOCK UI
            isLocked = true;
            document.getElementById('install-lock').classList.remove('hidden');
            document.getElementById('lock-msg').innerText = `Installing ${tool}...`;

            try {
                const res = await callApi({ action: 'install', tool: tool });
                // Unlock
                isLocked = false;
                document.getElementById('install-lock').classList.add('hidden');
                checkTools();
                if (res.status === 'done') {
                    Swal.fire({ icon: 'success', title: 'Installed', text: res.output, background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
                }
            } catch (e) {
                isLocked = false;
                document.getElementById('install-lock').classList.add('hidden');
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection Failed', background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff', color: document.documentElement.classList.contains('dark') ? '#fff' : '#000' });
            }
        }

        async function removeTool(tool) {
             const result = await Swal.fire({
                title: `Uninstall ${tool}?`,
                text: "Are you sure you want to remove this tool?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Uninstall',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });
            if (!result.isConfirmed) return;

            await callApi({ action: 'remove', tool: tool });
            location.reload();
        }

        async function runScan(tool) {
            const term = document.getElementById(`term-${tool}`);
            term.innerText = "Starting scan in background...\n";

            const res = await callApi({ action: 'scan', tool: tool });
            if (res.status === 'started') {
                term.innerText += `Process started. Log file: ${res.log_file}\nFetching live results...`;
                pollLog(tool, res.log_file);
            }
        }

        async function fetchLog(tool) {
            const term = document.getElementById(`term-${tool}`);
            const res = await callApi({ action: 'read_log', tool: tool });
            term.innerText = res.output;
        }

        function pollLog(tool, file) {
            const term = document.getElementById(`term-${tool}`);
            let count = 0;
            const interval = setInterval(async () => {
                const res = await callApi({ action: 'read_log', tool: tool, file: file });
                if (res.output) term.innerText = res.output;
                count++;
                if (count > 30) clearInterval(interval); // Stop polling after 60s approx to save resources
            }, 2000);
        }
    </script>
</body>
</html>