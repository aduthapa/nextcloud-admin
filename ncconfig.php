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
<body class="h-screen flex overflow-hidden font-sans bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>
    <script>
        <?php if ($message): ?>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                icon: '<?= $msgType ?>',
                title: '<?= $msgType == "success" ? "Saved" : "Error" ?>',
                html: '<?= str_replace(["\r", "\n"], "", addslashes($message)) ?>',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#fff' : '#000'
            });
        });
        <?php endif; ?>
    </script>

    <?php $activePage = 'config'; require __DIR__ . '/partials/nav.php'; ?>

    <div class="flex-1 min-w-0 flex flex-col">
        <div class="h-16 shrink-0 flex flex-wrap gap-3 justify-between items-center bg-white dark:bg-surface border-b border-gray-200 dark:border-line px-6">
            <div>
                <span class="font-mono text-xs text-gray-500 dark:text-gray-400 font-semibold tracking-wide">/var/www/html/nextcloud/config/config.php</span>
            </div>
            <button type="button" onclick="confirmSave()" class="btn btn-primary px-6">SAVE CHANGES</button>
        </div>

        <form id="configForm" method="POST" class="flex-1 min-h-0 p-4">
            <textarea name="config_content" id="hidden_content" class="hidden"></textarea>
            <div class="relative h-full w-full border border-gray-200 dark:border-line rounded-xl overflow-hidden">
                <div id="editor"></div>
            </div>
        </form>
    </div>

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
                confirmButtonColor: '#0d9488',
                cancelButtonColor: '#e11d48',
                confirmButtonText: 'Yes, Save',
                background: document.documentElement.classList.contains('dark') ? '#11161f' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#e6e9ef' : '#1f2937'
            });

            if (result.isConfirmed) {
                document.getElementById('configForm').submit();
            }
        }
    </script>
</body>
</html>