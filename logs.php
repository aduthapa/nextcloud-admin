<?php
require 'db.php'; require_login();

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';

// Log sources this page can read. Adjust paths here if your install differs
// (these defaults match a RHEL/httpd + ModSecurity stack).
$LOGS = [
    'nextcloud'     => ['label' => 'Nextcloud Log',     'path' => '/var/www/html/nextcloud/data/nextcloud.log'],
    'apache_access' => ['label' => 'Apache Access Log', 'path' => '/var/log/httpd/access_log'],
    'apache_error'  => ['label' => 'Apache Error Log',  'path' => '/var/log/httpd/error_log'],
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
        $path = $LOGS[$key]['path'];
        $mode = $input['mode'] ?? 'tail';

        if ($mode === 'grep') {
            $pattern = trim((string) ($input['pattern'] ?? ''));
            if ($pattern === '') { echo json_encode(['error' => 'Enter a search term to grep for.']); exit; }
            $out = shell_exec('grep -i -n -F -- ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path) . ' 2>&1 | tail -n 500');
            echo json_encode(['output' => ($out !== null && $out !== '') ? $out : 'No matches found.']);
        } else {
            $lines = (int) ($input['lines'] ?? 200);
            $lines = max(20, min(2000, $lines));
            $out = shell_exec('tail -n ' . $lines . ' ' . escapeshellarg($path) . ' 2>&1');
            echo json_encode(['output' => ($out !== null && $out !== '') ? $out : 'Log is empty or unreadable.']);
        }
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

    <main class="flex-1 min-w-0 p-6 lg:p-10 flex flex-col h-screen">
        <div class="max-w-[1500px] w-full mx-auto flex flex-col flex-1 min-h-0">
            <div class="mb-5 shrink-0">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Logs</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">nextcloud &amp; apache log viewer</p>
            </div>

            <div class="flex flex-wrap items-center gap-3 mb-4 shrink-0">
                <select id="log-select" onchange="runTail()" class="bg-white dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-3 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500">
                    <?php foreach ($LOGS as $key => $meta): ?>
                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="search-input" placeholder="grep pattern..." onkeydown="if(event.key==='Enter') runGrep()" class="flex-1 min-w-[180px] bg-white dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500">
                <select id="lines-select" class="bg-white dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-3 py-2.5 text-sm font-mono focus:outline-none">
                    <option value="100">100 lines</option>
                    <option value="200" selected>200 lines</option>
                    <option value="500">500 lines</option>
                    <option value="1000">1000 lines</option>
                    <option value="2000">2000 lines</option>
                </select>
                <button onclick="runTail()" class="btn btn-neutral">Tail</button>
                <button onclick="runGrep()" class="btn btn-primary">Grep</button>
                <button onclick="clearTerminal()" class="btn btn-outline-red">Clear</button>
            </div>

            <div class="bg-term rounded-xl border border-line flex flex-col flex-1 min-h-0 overflow-hidden">
                <div class="bg-surface px-4 py-3 flex justify-between items-center border-b border-line shrink-0">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-rose-500/70"></span><span class="w-2 h-2 rounded-full bg-amber-500/70"></span><span class="w-2 h-2 rounded-full bg-accent-500/70"></span>
                        <span id="term-title" class="text-xs font-mono text-gray-500 ml-2">...</span>
                    </div>
                    <span id="term-path" class="text-xs font-mono text-gray-600 truncate"></span>
                </div>
                <div id="terminal" class="flex-1 min-h-0 overflow-y-auto p-4 font-mono text-xs sm:text-sm text-accent-400 whitespace-pre-wrap break-all bg-term"><span class="text-gray-600">// Ready...</span></div>
            </div>
        </div>
    </main>

    <script>
        const TOKEN = '<?= $SECRET_TOKEN ?>';
        const ENDPOINT = 'logs.php';
        const LOG_META = <?= json_encode($LOGS) ?>;
        const terminal = document.getElementById('terminal');
        const select = document.getElementById('log-select');

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        function setTerminal(text) {
            terminal.textContent = text;
            terminal.scrollTop = terminal.scrollHeight;
        }

        function clearTerminal() { setTerminal(''); }

        function updateHeader(mode) {
            const key = select.value;
            document.getElementById('term-title').textContent = `admin@nc:~# ${mode} ${LOG_META[key].label.toLowerCase().replace(/ /g, '-')}`;
            document.getElementById('term-path').textContent = LOG_META[key].path;
        }

        async function runTail() {
            const key = select.value;
            const lines = document.getElementById('lines-select').value;
            updateHeader('tail');
            setTerminal('Loading...');
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'read_log', log: key, mode: 'tail', lines: lines }) });
                const data = await res.json();
                setTerminal(data.output || data.error || 'No output.');
            } catch (e) { setTerminal('Connection failed: ' + e.message); }
        }

        async function runGrep() {
            const key = select.value;
            const pattern = document.getElementById('search-input').value.trim();
            if (!pattern) { runTail(); return; }
            updateHeader(`grep "${pattern}" in`);
            setTerminal('Searching...');
            try {
                const res = await fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ token: TOKEN, action: 'read_log', log: key, mode: 'grep', pattern: pattern }) });
                const data = await res.json();
                setTerminal(data.output || data.error || 'No output.');
            } catch (e) { setTerminal('Connection failed: ' + e.message); }
        }

        window.addEventListener('DOMContentLoaded', runTail);
    </script>
</body>
</html>
