<?php
require 'db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_user'] = $user['username'];
        audit_log('login_success');
        header("Location: /"); exit;
    } else {
        $error = "Access Denied.";
        audit_log('login_failed', 'attempted_username=' . $username, 'failed');
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><title>Login - Nextcloud Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                fontFamily: { sans: ['Inter','ui-sans-serif','system-ui'], mono: ['"JetBrains Mono"','ui-monospace','monospace'] },
                colors: { accent: { DEFAULT:'#2dd4bf', 50:'#f0fdfa', 100:'#ccfbf1', 200:'#99f6e4', 300:'#5eead4', 400:'#2dd4bf', 500:'#14b8a6', 600:'#0d9488', 700:'#0f766e', 800:'#115e59', 900:'#134e4a', 950:'#042f2e' }, ink:'#080b10', surface:'#11161f', line:'#232a38' }
            } }
        };
        if (localStorage.loaderLogo === 'off') {
            document.documentElement.classList.add('no-loader-logo');
        }
    </script>
    <style>
        html, body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        .nc-glow { box-shadow: 0 0 0 1px rgba(45,212,191,.25), 0 8px 32px -8px rgba(45,212,191,.35); }
        .grid-fade { background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px); background-size: 34px 34px; }
        .font-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    </style>
</head>
<body class="bg-ink text-gray-200 flex items-center justify-center h-screen font-sans selection:bg-accent-500 selection:text-ink grid-fade">
    <?php require __DIR__ . '/partials/loader.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', (e) => {
                    if(link.getAttribute('href').startsWith('#') || link.getAttribute('href').startsWith('javascript')) return;
                    if(link.target === '_blank') return;
                    document.getElementById('global-loader').style.display = 'flex';
                    requestAnimationFrame(() => { document.getElementById('global-loader').classList.remove('opacity-0', 'pointer-events-none'); });
                });
            });
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', () => {
                    document.getElementById('global-loader').style.display = 'flex';
                    document.getElementById('global-loader').classList.remove('opacity-0', 'pointer-events-none');
                });
            });
        });
    </script>
    <div class="bg-surface/90 backdrop-blur p-10 rounded-2xl w-full max-w-sm border border-line relative overflow-hidden nc-glow">
        <div class="absolute top-0 left-0 w-full h-[2px] bg-gradient-to-r from-transparent via-accent-400 to-transparent"></div>

        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-accent-500/10 border border-accent-500/25 mb-4">
                <svg class="w-7 h-7 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path></svg>
            </div>
            <h1 class="text-3xl font-extrabold text-white tracking-tight">NC<span class="text-accent-400"> Admin</span></h1>
            <p class="text-gray-500 text-xs mt-3 uppercase tracking-widest font-mono font-semibold">system authentication</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-950/40 border border-rose-800/60 text-rose-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium animate-shake"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['reset'])): ?>
            <div class="bg-accent-950/40 border border-accent-800/50 text-accent-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium">Password updated. Please login.</div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <div>
                <label class="block text-gray-500 text-xs font-mono font-semibold uppercase mb-2 ml-1">username</label>
                <input type="text" name="username" class="w-full bg-ink/60 text-white border border-line rounded-xl py-3.5 px-5 font-mono text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none transition placeholder-gray-600" placeholder="admin" required autofocus>
            </div>
            <div>
                <label class="block text-gray-500 text-xs font-mono font-semibold uppercase mb-2 ml-1">password</label>
                <input type="password" name="password" class="w-full bg-ink/60 text-white border border-line rounded-xl py-3.5 px-5 font-mono text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none transition placeholder-gray-600" placeholder="••••••••" required>
            </div>
            <button type="submit" class="w-full bg-accent-600 hover:bg-accent-700 text-ink font-bold py-3.5 rounded-xl transition duration-200 tracking-wide">AUTHENTICATE</button>
        </form>

        <div class="mt-8 text-center">
            <a href="/resetpass.php" class="text-xs text-gray-500 hover:text-accent-400 transition font-medium tracking-wide">Forgot Password?</a>
        </div>
    </div>
</body>
</html>