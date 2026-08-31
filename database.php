<?php
require 'db.php'; require_login();

// CONFIGURATION
// Path to phpMyAdmin (Change if yours is different)
$PMA_URL = '/phpmyadmin';

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V';

// --- BACKEND HELPER FUNCTIONS ---

// sudo without a TTY/NOPASSWD prints its standard first-use lecture (and the
// password prompt) to stderr, which we capture via 2>&1 - strip those known
// boilerplate lines out so they don't get parsed as SQL result rows.
function strip_sudo_noise($output) {
    if ($output === null || $output === '') return $output;
    $lines = preg_split('/\r?\n/', $output);
    $clean = array_filter($lines, function($line) {
        $l = trim($line);
        if ($l === '') return false;
        if (stripos($l, 'sudo:') === 0) return false;
        if (preg_match('/^we trust you have received/i', $l)) return false;
        if (preg_match('/^administrator\.? it usually boils down/i', $l)) return false;
        if (preg_match('/^#\d\)/', $l)) return false;
        if (preg_match('/^for security reasons/i', $l)) return false;
        if (preg_match('/^\[sudo\] password/i', $l)) return false;
        return true;
    });
    return implode("\n", $clean);
}

function run_sql_cmd($sql) {
    // Option 1: Secure (requires /root/.my.cnf setup)
    $cmd = "sudo mysql -e " . escapeshellarg($sql) . " -sN 2>&1";
    return strip_sudo_noise(shell_exec($cmd));
}

// Detects whether passwordless sudo is actually usable for this session,
// so we can show an honest banner instead of silently mangled data.
function sudo_is_passwordless() {
    $out = trim((string) shell_exec('sudo -n true 2>&1'));
    return $out === '';
}

// --- FORM HANDLING ---
$message = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Create Database
    if ($action === 'create_db') {
        $newDB = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['db_name']);
        if ($newDB) {
            $out = run_sql_cmd("CREATE DATABASE `$newDB` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
            if (trim($out) == '') { $message = "Database '$newDB' created."; $msgType = 'success'; }
            else { $message = "Error: $out"; $msgType = 'error'; }
            audit_log('create_db', "db=$newDB", $msgType === 'success' ? 'success' : 'failed');
        }
    }

    // 2. Create User
    if ($action === 'create_user') {
        $u = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_user']);
        $p = $_POST['new_pass'];
        if ($u && $p) {
            $sql = "CREATE USER '$u'@'localhost' IDENTIFIED BY '" . addslashes($p) . "';";
            $out = run_sql_cmd($sql);
            if (trim($out) == '') { $message = "User '$u' created."; $msgType = 'success'; }
            else { $message = "Error: $out"; $msgType = 'error'; }
            audit_log('create_db_user', "user=$u", $msgType === 'success' ? 'success' : 'failed');
        }
    }

    // 3. Grant Privileges
    if ($action === 'grant_priv') {
        $u = $_POST['grant_user'];
        $d = $_POST['grant_db'];
        if ($u && $d) {
            $userPart = explode('@', $u)[0];
            $sql = "GRANT ALL PRIVILEGES ON `$d`.* TO '$userPart'@'localhost'; FLUSH PRIVILEGES;";
            $out = run_sql_cmd($sql);
            if (trim($out) == '') { $message = "Granted access to $d for $u."; $msgType = 'success'; }
            else { $message = "Error: $out"; $msgType = 'error'; }
            audit_log('grant_priv', "user=$u db=$d", $msgType === 'success' ? 'success' : 'failed');
        }
    }

    // 4. Optimize & Repair
    if ($action === 'optimize_all') {
        $out = shell_exec("sudo mysqlcheck -o --all-databases 2>&1");
        $message = "Optimization Complete."; $msgType = 'success';
        audit_log('optimize_all');
    }
}

// --- DATA FETCHING ---
$sudoOk = sudo_is_passwordless();

$dbs_raw = explode("\n", trim(run_sql_cmd("SHOW DATABASES")));
$dbs = array_filter($dbs_raw, function($d) { return !in_array($d, ['information_schema', 'performance_schema', 'sys', '']); });

$users_raw = explode("\n", trim(run_sql_cmd("SELECT CONCAT(User, '@', Host) FROM mysql.user")));
$users = array_filter($users_raw);

$procs_raw = run_sql_cmd("SHOW FULL PROCESSLIST");
$procs = [];
foreach(explode("\n", $procs_raw) as $row) {
    if(!empty($row)) $procs[] = explode("\t", $row);
}

$pageTitle = 'Database Manager';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-ink flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-14 h-14 border-4 border-accent-700/30 border-t-accent-400 rounded-full animate-spin"></div></div>
    </div>
    <script>
        window.onload=()=>{const l=document.getElementById('global-loader');l.classList.add('opacity-0','pointer-events-none');setTimeout(()=>l.style.display='none',500);}
        <?php if ($message): ?>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                icon: '<?= $msgType ?>',
                title: '<?= $msgType == "success" ? "Success" : "Error" ?>',
                text: '<?= addslashes($message) ?>',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#000'
            });
        });
        <?php endif; ?>

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }
    </script>

    <?php $activePage = 'database'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto space-y-6">
            <div class="mb-2">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Database</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">mysql instance management</p>
            </div>

            <?php if (!$sudoOk): ?>
            <div class="card p-4 flex items-start gap-3" style="border-left:2px solid #f59e0b">
                <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                <div class="text-sm">
                    <p class="font-bold text-amber-600 dark:text-amber-400">Passwordless sudo isn't configured for this shell</p>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Every <code class="font-mono bg-gray-100 dark:bg-surface2 px-1 rounded">sudo mysql</code> call below is filtered for sudo's boilerplate output, but empty results here likely mean the underlying command never actually ran. Set up <code class="font-mono bg-gray-100 dark:bg-surface2 px-1 rounded">/root/.my.cnf</code> or a NOPASSWD sudoers rule for the web user, per the comment in <code class="font-mono bg-gray-100 dark:bg-surface2 px-1 rounded">run_sql_cmd()</code>.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="card p-5">
                    <h3 class="section-eyebrow mb-3">Create Database</h3>
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action" value="create_db">
                        <input type="text" name="db_name" placeholder="new_db_name" class="flex-1 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line rounded-lg px-3 py-2 text-sm font-mono text-gray-900 dark:text-white focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500 focus:outline-none" required pattern="[a-zA-Z0-9_]+">
                        <button type="submit" class="btn btn-outline-green">Create</button>
                    </form>
                </div>

                <div class="card p-5 flex flex-col justify-center gap-3">
                    <div class="flex gap-2">
                        <a href="<?= $PMA_URL ?>" target="_blank" class="btn btn-violet flex-1 group">
                            <svg class="w-4 h-4 group-hover:scale-110 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                            PHPMyAdmin
                        </a>
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="action" value="optimize_all">
                            <button type="submit" class="btn btn-neutral w-full">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                Optimize
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="section-eyebrow mb-3">Create User</h3>
                    <form method="POST" class="flex flex-col gap-2">
                        <input type="hidden" name="action" value="create_user">
                        <div class="flex gap-2">
                            <input type="text" name="new_user" placeholder="Username" class="w-1/2 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line rounded-lg px-3 py-2 text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500/30" required>
                            <input type="text" name="new_pass" placeholder="Password" class="w-1/2 bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line rounded-lg px-3 py-2 text-sm font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500/30" required>
                        </div>
                        <button type="submit" class="btn btn-neutral w-full">Add User</button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 h-[500px]">

                <div class="lg:col-span-8 card flex flex-col overflow-hidden">
                    <div class="bg-gray-50 dark:bg-surface2 px-6 py-4 border-b border-gray-200 dark:border-line flex justify-between items-center">
                        <h2 class="text-sm font-bold text-gray-900 dark:text-white">Databases <span class="badge bg-gray-100 dark:bg-surface text-gray-500 dark:text-gray-400"><?= count($dbs) ?></span></h2>
                        <div class="badge bg-accent-50 dark:bg-accent-900/20 text-accent-700 dark:text-accent-400">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            SAFE MODE
                        </div>
                    </div>
                    <div class="overflow-y-auto p-4 space-y-2">
                        <?php if (empty($dbs)): ?>
                            <div class="empty-state">No databases found<?= $sudoOk ? '' : ' — sudo could not run the query (see warning above)' ?>.</div>
                        <?php else: foreach($dbs as $db): ?>
                        <div class="flex justify-between items-center bg-gray-50 dark:bg-surface2 p-3 rounded-lg border border-gray-200 dark:border-line hover:border-accent-500/50 transition">
                            <div class="flex items-center gap-3">
                                <div class="w-7 h-7 rounded-md bg-violet-500/10 flex items-center justify-center text-violet-500"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4"></path></svg></div>
                                <span class="font-mono text-sm font-semibold text-gray-700 dark:text-gray-300"><?= htmlspecialchars($db) ?></span>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="lg:col-span-4 card flex flex-col overflow-hidden">
                    <div class="bg-gray-50 dark:bg-surface2 px-6 py-4 border-b border-gray-200 dark:border-line">
                        <h2 class="text-sm font-bold text-gray-900 dark:text-white">Users &amp; Access</h2>
                    </div>

                    <div class="p-4 border-b border-gray-200 dark:border-line bg-gray-50/60 dark:bg-surface2/60">
                        <h4 class="section-eyebrow mb-2">Quick Grant Access</h4>
                        <form method="POST" class="grid grid-cols-1 gap-2">
                            <input type="hidden" name="action" value="grant_priv">
                            <select name="grant_user" class="bg-white dark:bg-surface border border-gray-200 dark:border-line rounded-lg p-2 text-xs font-mono text-gray-700 dark:text-gray-300">
                                <?php foreach($users as $u) echo "<option value='" . htmlspecialchars($u) . "'>" . htmlspecialchars($u) . "</option>"; ?>
                            </select>
                            <select name="grant_db" class="bg-white dark:bg-surface border border-gray-200 dark:border-line rounded-lg p-2 text-xs font-mono text-gray-700 dark:text-gray-300">
                                <?php foreach($dbs as $d) echo "<option value='" . htmlspecialchars($d) . "'>" . htmlspecialchars($d) . "</option>"; ?>
                            </select>
                            <button class="btn btn-violet text-xs">Grant All Privileges</button>
                        </form>
                    </div>

                    <div class="overflow-y-auto flex-1 p-4 space-y-2">
                        <?php if (empty($users)): ?>
                            <div class="empty-state">No users found<?= $sudoOk ? '' : ' — sudo could not run the query' ?>.</div>
                        <?php else: foreach($users as $user): ?>
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <div class="w-1.5 h-1.5 rounded-full bg-accent-500"></div>
                            <span class="font-mono text-xs"><?= htmlspecialchars($user) ?></span>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="bg-gray-50 dark:bg-surface2 px-6 py-3 border-b border-gray-200 dark:border-line flex justify-between">
                    <h2 class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <span class="text-accent-500">●</span> Active Processes
                    </h2>
                    <span class="text-xs text-gray-500 font-mono">LIVE VIEW</span>
                </div>
                <div class="overflow-x-auto max-h-60">
                    <?php if (empty($procs)): ?>
                    <div class="empty-state">No active processes.</div>
                    <?php else: ?>
                    <table class="w-full text-left text-xs font-mono">
                        <thead class="bg-gray-50 dark:bg-surface2 text-gray-500 uppercase sticky top-0">
                            <tr><th class="p-3">ID</th><th class="p-3">User</th><th class="p-3">DB</th><th class="p-3">Command</th><th class="p-3">Time</th><th class="p-3">State</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-line">
                            <?php foreach($procs as $row): if(count($row) < 6) continue; ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-surface2">
                                <td class="p-3 text-gray-500"><?= htmlspecialchars($row[0]) ?></td>
                                <td class="p-3 text-violet-500"><?= htmlspecialchars($row[1]) ?></td>
                                <td class="p-3 text-accent-600 dark:text-accent-400"><?= htmlspecialchars($row[3]) ?></td>
                                <td class="p-3 text-gray-800 dark:text-white"><?= htmlspecialchars($row[4]) ?></td>
                                <td class="p-3 <?= $row[5] > 10 ? 'text-rose-500 font-bold' : 'text-gray-500 dark:text-gray-400' ?>"><?= htmlspecialchars($row[5]) ?>s</td>
                                <td class="p-3 text-gray-500 dark:text-gray-400"><?= htmlspecialchars($row[6] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>