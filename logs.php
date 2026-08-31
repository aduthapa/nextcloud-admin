<?php
require 'db.php'; require_login();

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';

// Log sources this page can tail. Adjust paths here if your install differs
// (these defaults match a RHEL/httpd + ModSecurity stack).
$LOGS = [
    'nextcloud'     => ['label' => 'Nextcloud Log',       'path' => '/var/www/html/nextcloud/data/nextcloud.log'],
    'apache_access' => ['label' => 'Apache Access Log',   'path' => '/var/log/httpd/access_log'],
    'apache_error'  => ['label' => 'Apache Error Log',    'path' => '/var/log/httpd/error_log'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (($input['token'] ?? '') !== $SECRET_TOKEN) {
        http_response_code(403); echo json_encode(['error'=>'Invalid Token']); exit;
    }
    $action = $input['action'] ?? '';

    if ($action === 'read_log') {
        $key = $input['log'] ?? '';
        if (!isset($LOGS[$key])) { echo json_encode(['error' => 'Unknown log']); exit; }
        $lines = (int) ($input['lines'] ?? 200);
        $lines = max(20, min(1000, $lines));
        $path = $LOGS[$key]['path'];
        $out = shell_exec('tail -n ' . $lines . ' ' . escapeshellarg($path) . ' 2>&1');
        echo json_encode(['output' => $out !== null && $out !== '' ? $out : 'Log is empty or unreadable.']);
        exit;
    }

    echo json_encode(['error' => 'Unknown command']); exit;
}

$pageTitle = 'Logs';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>

    <?php $activePage = 'nextcloud'; $activeChild = 'logs'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Logs</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">nextcloud &amp; apache log viewer</p>
            </div>

            <?php foreach ($LOGS as $key => $meta): ?>
            <div class="bg-term rounded-xl border border-line overflow-hidden">
                <div class="bg-surface px-4 py-3 flex flex-wrap items-center gap-3 border-b border-line">
                    <span class="w-2 h-2 rounded-full bg-rose-500/70"></span><span class="w-2 h-2 rounded-full bg-amber-500/70"></span><span class="w-2 h-2 rounded-full bg-accent-500/70"></span>
                    <span class="text-sm font-bold text-white ml-1"><?= htmlspecialchars($meta['label']) ?></span>
                    <span class="text-xs font-mono text-gray-500 truncate"><?= htmlspecialchars($meta['path']) ?></span>
                    <div class="ml-auto flex items-center gap-2">
                        <input type="text" id="filter-<?= $key ?>" oninput="filterLog('<?= $key ?>')" placeholder="Filter..." class="bg-surface2 border border-line text-white rounded-lg px-3 py-1.5 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 w-32 sm:w-48">
                        <select id="lines-<?= $key ?>" onchange="fetchLog('<?= $key ?>')" class="bg-surface2 border border-line text-white rounded-lg px-2 py-1.5 text-xs font-mono focus:outline-none">
                            <option value="100">100</option>
                            <option value="200" selected>200</option>
                            <option value="500">500</option>
                            <option value="1000">1000</option>
                        </select>
                        <button onclick="fetchLog('<?= $key ?>')" class="text-xs font-mono font-bold text-gray-400 hover:text-white tracking-wider">REFRESH</button>
                    </div>
                </div>
                <div id="log-<?= $key ?>" class="terminal-box p-4 font-mono text-xs text-accent-400 whitespace-pre-wrap break-all bg-term max-h-96 overflow-y-auto"><span class="text-gray-600">// Loading...</span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'logs.php';
        const rawLines = {};

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        async function fetchLog(key) {
            const box = document.getElementById('log-' + key);
            const lines = document.getElementById('lines-' + key).value;
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'read_log', log: key, lines: lines }) });
                const data = await res.json();
                rawLines[key] = (data.output || data.error || '').split('\n');
                renderLog(key);
            } catch (e) {
                box.textContent = 'Connection failed: ' + e.message;
            }
        }

        function renderLog(key) {
            const box = document.getElementById('log-' + key);
            const filter = (document.getElementById('filter-' + key).value || '').toLowerCase();
            const lines = rawLines[key] || [];
            const visible = filter ? lines.filter(l => l.toLowerCase().includes(filter)) : lines;
            box.textContent = visible.join('\n') || '// No matching lines';
            box.scrollTop = box.scrollHeight;
        }

        function filterLog(key) { renderLog(key); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php foreach ($LOGS as $key => $meta): ?>
            fetchLog('<?= $key ?>');
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
