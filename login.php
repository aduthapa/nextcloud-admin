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
        header("Location: /"); exit;
    } else { $error = "Access Denied."; }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8"><title>Login - Nextcloud Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{nc:'#0082c9',nc_dark:'#005f92'}}}}; </script>
    <style>
        .nc-glow { box-shadow: 0 0 15px rgba(0, 130, 201, 0.4); }
    </style>
</head>
<body class="bg-gray-900 text-gray-200 flex items-center justify-center h-screen font-sans selection:bg-nc selection:text-white">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-gray-900 flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center">
            <div class="w-16 h-16 border-4 border-blue-900/30 border-t-[#0082c9] rounded-full animate-spin"></div>
            <div class="mt-4 text-[#0082c9] font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div>
        </div>
    </div>
    <script>
        window.addEventListener('load', () => {
            const loader = document.getElementById('global-loader');
            loader.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => loader.style.display = 'none', 500);
        });
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
    <div class="bg-gray-800 p-10 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-700 relative overflow-hidden transform hover:shadow-nc_dark/50 transition duration-300">
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-nc to-transparent"></div>
        
        <div class="text-center mb-10">
            <h1 class="text-4xl font-extrabold text-white tracking-tight">Nextcloud<span class="text-nc">Admin</span></h1>
            <p class="text-gray-500 text-xs mt-3 uppercase tracking-widest font-semibold">System Authentication</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium animate-shake"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['reset'])): ?>
            <div class="bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded-lg mb-6 text-sm text-center font-medium">Password updated. Please login.</div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-gray-400 text-xs font-semibold uppercase mb-2 ml-1">Username</label>
                <input type="text" name="username" class="w-full bg-gray-900/80 text-white border border-gray-600 rounded-xl py-3.5 px-5 focus:border-nc focus:ring-2 focus:ring-nc focus:outline-none transition placeholder-gray-500" placeholder="admin" required>
            </div>
            <div>
                <label class="block text-gray-400 text-xs font-semibold uppercase mb-2 ml-1">Password</label>
                <input type="password" name="password" class="w-full bg-gray-900/80 text-white border border-gray-600 rounded-xl py-3.5 px-5 focus:border-nc focus:ring-2 focus:ring-nc focus:outline-none transition placeholder-gray-500" placeholder="••••••••" required>
            </div>
            <button type="submit" class="w-full bg-nc hover:bg-nc_dark text-white font-bold py-3.5 rounded-xl transition duration-200 shadow-xl nc-glow">A U T H E N T I C A T E</button>
        </form>
        
        <div class="mt-8 text-center">
            <a href="/resetpass.php" class="text-xs text-gray-500 hover:text-nc transition font-medium tracking-wide">Forgot Password?</a>
        </div>
    </div>
</body>
</html>
