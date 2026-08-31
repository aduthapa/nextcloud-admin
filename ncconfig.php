<?php
require 'db.php'; require_login();
$configFile = '/var/www/html/nextcloud/config/config.php';
$message = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_content'])) {
    $newContent = $_POST['config_content'];
    $tempFile = tempnam(sys_get_temp_dir(), 'nc_check');
    file_put_contents($tempFile, $newContent);
    $check = shell_exec("php -l " . escapeshellarg($tempFile));
    unlink($tempFile);

    if (strpos($check, 'No syntax errors') !== false) {
        copy($configFile, $configFile . '.bak.' . time());
        if (file_put_contents($configFile, $newContent)) {
            $message = "Configuration Saved & Backup Created."; $msgType = "success";
            audit_log('config_save', 'nextcloud config.php updated', 'success');
        }
        else { $message = "Write Error. Check permissions on the config file."; $msgType = "error"; audit_log('config_save', 'write failed', 'failed'); }
    } else { $message = "Syntax Error! Save aborted.<br>" . nl2br($check); $msgType = "error"; audit_log('config_save', 'syntax error, save aborted', 'failed'); }
}
$content = file_get_contents($configFile);

$pageTitle = 'Config Editor';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php $extraHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js"></script>'; require __DIR__ . '/partials/head.php'; ?>
<style>
    #editor { position: absolute; top: 0; right: 0; bottom: 0; left: 0; font-size: 14px; }
</style>
</head>
<body class="h-screen flex flex-col overflow-hidden font-sans bg-gray-100 text-gray-900 dark:bg-[#0d1117] dark:text-gray-200">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-gray-900 flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-16 h-16 border-4 border-blue-900/30 border-t-[#0082c9] rounded-full animate-spin"></div><div class="mt-4 text-[#0082c9] font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div></div>
    </div>
    <script>
        window.addEventListener('load', () => { const l = document.getElementById('global-loader'); l.classList.add('opacity-0', 'pointer-events-none'); setTimeout(() => l.style.display = 'none', 500); });
        <?php if ($message): ?>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                icon: '<?= $msgType ?>',
                title: '<?= $msgType == "success" ? "Saved" : "Error" ?>',
                html: '<?= str_replace(["\r", "\n"], "", addslashes($message)) ?>',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#000'
            });
        });
        <?php endif; ?>
    </script>

    <header class="bg-white dark:bg-gray-800 border-b border-gray-300 dark:border-gray-700 px-6 py-4 flex flex-wrap gap-4 justify-between items-center shrink-0">
        <div class="flex items-center gap-3">
            <div class="bg-nc p-2 rounded-lg"><svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></div>
            <span class="font-bold text-xl tracking-tight text-gray-800 dark:text-white">Config<span class="text-gray-500 font-light ml-1">Editor</span></span>
        </div>
        <?php $activePage = 'config'; require __DIR__ . '/partials/nav.php'; ?>
    </header>

    <form id="configForm" method="POST" class="flex-1 flex flex-col min-h-0 p-6 pt-0 mt-6">
        <div class="flex flex-wrap gap-3 justify-between items-center bg-gray-200 dark:bg-gray-900/80 px-4 py-3 rounded-t-xl border border-gray-300 dark:border-gray-700 border-b-0 shrink-0">
            <span class="font-mono text-xs text-gray-600 dark:text-gray-500 font-semibold tracking-wide">/var/www/html/nextcloud/config/config.php</span>
            <button type="button" onclick="confirmSave()" class="btn btn-primary px-8">SAVE CHANGES</button>
        </div>

        <textarea name="config_content" id="hidden_content" class="hidden"></textarea>

        <div class="relative flex-1 w-full border border-gray-300 dark:border-gray-700 rounded-b-xl overflow-hidden shadow-2xl">
            <div id="editor"></div>
        </div>
    </form>

    <script>
        // Ace Editor Setup
        var editor = ace.edit("editor");
        editor.session.setMode("ace/mode/php");
        editor.setFontSize(14);
        editor.setShowPrintMargin(false);

        // Set initial value from PHP
        editor.setValue(<?= json_encode($content) ?>, -1); // -1 moves cursor to start

        // Theme Logic
        function updateEditorTheme() {
            if (document.documentElement.classList.contains('dark')) {
                editor.setTheme("ace/theme/monokai");
            } else {
                editor.setTheme("ace/theme/chrome");
            }
        }
        updateEditorTheme(); // On Load

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
            updateEditorTheme();
        }

        async function confirmSave() {
            // Sync value
            const currentCode = editor.getValue();
            document.getElementById('hidden_content').value = currentCode;

            const result = await Swal.fire({
                title: 'Save Configuration?',
                text: "A syntax error here can crash Nextcloud. Ensure your PHP is valid.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0082c9',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Save',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e5e7eb' : '#1f2937'
            });

            if (result.isConfirmed) {
                document.getElementById('configForm').submit();
            }
        }
    </script>
</body>
</html>