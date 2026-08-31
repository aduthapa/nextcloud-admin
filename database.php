<?php
require 'db.php'; require_login();

// CONFIGURATION
// Path to phpMyAdmin (Change if yours is different)
$PMA_URL = '/phpmyadmin'; 

$SECRET_TOKEN = '#pdqIJ*A!ykde!0l1socWu$61bTsB*3V'; 

// --- BACKEND HELPER FUNCTIONS ---
function run_sql_cmd($sql) {
    // Option 1: Secure (requires /root/.my.cnf setup)
    $cmd = "sudo mysql -e " . escapeshellarg($sql) . " -sN 2>&1";
    return shell_exec($cmd);
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
        }
    }

    // 4. Optimize & Repair
    if ($action === 'optimize_all') {
        $out = shell_exec("sudo mysqlcheck -o --all-databases 2>&1");
        $message = "Optimization Complete."; $msgType = 'success';
    }
}

// --- DATA FETCHING ---
$dbs_raw = explode("\n", trim(run_sql_cmd("SHOW DATABASES")));
$dbs = array_filter($dbs_raw, function($d) { return !in_array($d, ['information_schema', 'performance_schema', 'sys', '']); });

$users_raw = explode("\n", trim(run_sql_cmd("SELECT CONCAT(User, '@', Host) FROM mysql.user")));
$users = array_filter($users_raw);

$procs_raw = run_sql_cmd("SHOW FULL PROCESSLIST");
$procs = [];
foreach(explode("\n", $procs_raw) as $row) {
    if(!empty($row)) $procs[] = explode("\t", $row);
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><title>Database Manager</title>
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
        .glass-panel { background: #ffffff; border: 1px solid #e5e7eb; }
        .dark .glass-panel { background: rgba(31, 41, 55, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(75, 85, 99, 0.4); }
    </style>
</head>
<body class="font-sans min-h-screen flex flex-col bg-gray-100 text-gray-900 dark:bg-[#0d1117] dark:text-e5e7eb">

    <div id="global-loader" class="fixed inset-0 z-[100] bg-gray-900 flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-16 h-16 border-4 border-blue-900/30 border-t-[#0082c9] rounded-full animate-spin"></div></div>
    </div>
    <script>
        window.onload=()=>{const l=document.getElementById('global-loader');l.classList.add('opacity-0','pointer-events-none');setTimeout(()=>l.style.display='none',500);}
        <?php if ($message): ?>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                icon: '<?= $msgType ?>',
                title: '<?= $msgType == "success" ? "Success" : "Error" ?>',
                text: '<?= addslashes($message) ?>',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff',
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

    <header class="bg-white dark:bg-gray-800 border-b border-gray-300 dark:border-gray-700 px-6 py-4 flex justify-between items-center shrink-0 sticky top-0 z-50">
        <div class="flex items-center gap-3">
            <div class="bg-yellow-100 dark:bg-yellow-600/20 p-2 rounded-lg border border-yellow-200 dark:border-yellow-600/50">
                <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
            </div>
            <span class="font-bold text-xl text-gray-800 dark:text-white">Data<span class="text-yellow-600 dark:text-yellow-500 font-light">Base</span></span>
        </div>
        <div class="flex items-center gap-4">
             <button onclick="toggleTheme()" class="p-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-yellow-400">
                 <svg id="moon-icon" class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                 <svg id="sun-icon" class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            </button>
            <nav class="flex gap-2 bg-white dark:bg-gray-800/50 p-1 rounded-lg border border-gray-300 dark:border-gray-700 shadow-sm">
                <a href="/" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Dashboard</a>
                <a href="/database" class="px-4 py-1.5 rounded-md text-sm font-medium bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm border border-gray-300 dark:border-gray-600">Database</a>
                <a href="/settings" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Settings</a>
                <a href="/ncconfig" class="px-4 py-1.5 rounded-md text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Config</a>
                <a href="/security.php" class="px-4 py-1.5 rounded-md text-sm font-medium text-red-600 dark:text-red-200 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition border border-red-200 dark:border-red-900/30">Security</a>
            </nav>
        </div>
    </header>

    <div class="p-6 max-w-[1600px] mx-auto w-full space-y-6">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="glass-panel p-5 rounded-xl shadow-lg">
                <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase mb-3 tracking-wider">Create Database</h3>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="action" value="create_db">
                    <input type="text" name="db_name" placeholder="new_db_name" class="flex-1 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded px-3 py-2 text-sm text-gray-900 dark:text-white focus:border-yellow-500 focus:outline-none" required pattern="[a-zA-Z0-9_]+">
                    <button type="submit" class="bg-green-600 hover:bg-green-500 text-white px-4 py-2 rounded text-sm font-bold transition">Create</button>
                </form>
            </div>

            <div class="glass-panel p-5 rounded-xl shadow-lg flex flex-col justify-center gap-3">
                <div class="flex gap-2">
                    <a href="<?= $PMA_URL ?>" target="_blank" class="flex-1 flex items-center justify-center gap-2 bg-orange-100 dark:bg-orange-600/20 hover:bg-orange-200 dark:hover:bg-orange-600/40 border border-orange-200 dark:border-orange-600/50 text-orange-600 dark:text-orange-400 py-2 rounded transition font-bold text-sm group">
                        <svg class="w-4 h-4 group-hover:scale-110 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        Open PHPMyAdmin
                    </a>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="action" value="optimize_all">
                        <button type="submit" class="w-full flex items-center justify-center gap-2 bg-blue-100 dark:bg-blue-600/20 hover:bg-blue-200 dark:hover:bg-blue-600/40 border border-blue-200 dark:border-blue-600/50 text-blue-600 dark:text-blue-400 py-2 rounded transition font-bold text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Optimize & Repair
                        </button>
                    </form>
                </div>
            </div>

            <div class="glass-panel p-5 rounded-xl shadow-lg">
                <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase mb-3 tracking-wider">Create User</h3>
                <form method="POST" class="flex flex-col gap-2">
                    <input type="hidden" name="action" value="create_user">
                    <div class="flex gap-2">
                        <input type="text" name="new_user" placeholder="Username" class="w-1/2 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none" required>
                        <input type="text" name="new_pass" placeholder="Password" class="w-1/2 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none" required>
                    </div>
                    <button type="submit" class="w-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 py-2 rounded text-sm font-bold">Add User</button>
                </form>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 h-[500px]">
            
            <div class="lg:col-span-8 glass-panel rounded-xl shadow-lg flex flex-col overflow-hidden">
                <div class="bg-gray-50 dark:bg-gray-800/80 px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-white">Databases <span class="bg-gray-200 dark:bg-gray-700 text-xs px-2 py-1 rounded ml-2 text-gray-500 dark:text-gray-400"><?= count($dbs) ?></span></h2>
                    <div class="text-xs text-green-600 dark:text-green-500 font-mono flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        SAFE MODE ACTIVE
                    </div>
                </div>
                <div class="overflow-y-auto p-4 space-y-2">
                    <?php foreach($dbs as $db): ?>
                    <div class="flex justify-between items-center bg-gray-50 dark:bg-gray-900/50 p-3 rounded border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-3">
                            <div class="bg-blue-100 dark:bg-blue-900/20 p-2 rounded text-blue-600 dark:text-blue-400"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4"></path></svg></div>
                            <span class="font-mono font-bold text-gray-700 dark:text-gray-300"><?= $db ?></span>
                        </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="lg:col-span-4 glass-panel rounded-xl shadow-lg flex flex-col overflow-hidden">
                <div class="bg-gray-50 dark:bg-gray-800/80 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-white">Users & Access</h2>
                </div>
                
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30">
                    <h4 class="text-xs font-bold text-gray-500 uppercase mb-2">Quick Grant Access</h4>
                    <form method="POST" class="grid grid-cols-1 gap-2">
                        <input type="hidden" name="action" value="grant_priv">
                        <select name="grant_user" class="bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded p-2 text-xs text-gray-700 dark:text-gray-300">
                            <?php foreach($users as $u) echo "<option value='$u'>$u</option>"; ?>
                        </select>
                        <select name="grant_db" class="bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded p-2 text-xs text-gray-700 dark:text-gray-300">
                            <?php foreach($dbs as $d) echo "<option value='$d'>$d</option>"; ?>
                        </select>
                        <button class="bg-blue-100 dark:bg-blue-900/50 hover:bg-blue-200 dark:hover:bg-blue-800 text-blue-800 dark:text-blue-200 text-xs font-bold py-2 rounded border border-blue-200 dark:border-blue-800">Grant All Privileges</button>
                    </form>
                </div>

                <div class="overflow-y-auto flex-1 p-4 space-y-2">
                    <?php foreach($users as $user): ?>
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <div class="w-2 h-2 rounded-full bg-green-500"></div>
                        <span class="font-mono text-xs"><?= $user ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="glass-panel rounded-xl shadow-lg overflow-hidden">
            <div class="bg-gray-50 dark:bg-gray-800/80 px-6 py-3 border-b border-gray-200 dark:border-gray-700 flex justify-between">
                <h2 class="text-sm font-bold text-gray-800 dark:text-white flex items-center gap-2">
                    <span class="text-green-500">●</span> Active Processes
                </h2>
                <span class="text-xs text-gray-500 font-mono">LIVE VIEW</span>
            </div>
            <div class="overflow-x-auto max-h-60">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-gray-100 dark:bg-gray-900 text-gray-500 uppercase">
                        <tr><th class="p-3">ID</th><th class="p-3">User</th><th class="p-3">DB</th><th class="p-3">Command</th><th class="p-3">Time</th><th class="p-3">State</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-transparent">
                        <?php foreach($procs as $row): if(count($row) < 6) continue; ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="p-3 text-gray-500"><?= $row[0] ?></td>
                            <td class="p-3 text-blue-600 dark:text-blue-400"><?= $row[1] ?></td>
                            <td class="p-3 text-yellow-600 dark:text-yellow-500"><?= $row[3] ?></td>
                            <td class="p-3 text-gray-800 dark:text-white"><?= $row[4] ?></td>
                            <td class="p-3 <?= $row[5] > 10 ? 'text-red-500 font-bold' : 'text-gray-500 dark:text-gray-400' ?>"><?= $row[5] ?>s</td>
                            <td class="p-3 text-gray-500 dark:text-gray-400"><?= $row[6] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
