<?php
// Renders the persistent left sidebar: brand, nav links, theme toggle, status pill, session.
// Requires $activePage: 'dashboard'|'database'|'settings'|'config'|'security'|'audit'
// Optional $showStatusBar = true to render the Idle/Busy pill + Force Unlock button.
$__nav = [
    'dashboard' => ['Dashboard', '/', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
    'database'  => ['Database', '/database', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>'],
    'settings'  => ['Settings', '/settings', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'],
    'config'    => ['Config', '/ncconfig', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 20l4-16M6 8L2 12l4 4M18 8l4 4-4 4"/>'],
    'audit'     => ['Audit Log', '/audit.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
    'security'  => ['Security', '/security.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
];
?>
<aside class="w-60 shrink-0 h-screen sticky top-0 flex flex-col bg-white dark:bg-surface border-r border-gray-200 dark:border-line">
    <div class="h-16 flex items-center gap-2.5 px-5 border-b border-gray-200 dark:border-line shrink-0">
        <div class="w-8 h-8 rounded-lg bg-accent-600 flex items-center justify-center shrink-0">
            <svg class="text-white" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
        </div>
        <span class="font-bold text-sm tracking-tight text-gray-900 dark:text-white leading-none">NC<span class="text-[#0d9488] dark:text-accent-400"> Admin</span></span>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
        <?php foreach ($__nav as $key => $item): [$label, $href, $iconPath] = $item; $isActive = ($activePage ?? '') === $key; ?>
        <a href="<?= $href ?>" class="sb-link <?= $isActive ? 'active' : '' ?>">
            <svg class="shrink-0" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $iconPath ?></svg>
            <span><?= $label ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="p-3 border-t border-gray-200 dark:border-line space-y-2 shrink-0">
        <?php if (!empty($showStatusBar)): ?>
        <div class="flex items-center justify-between gap-2">
            <div id="status-indicator" class="flex items-center gap-2 text-[.68rem] font-mono font-bold px-2.5 py-1.5 rounded-lg bg-gray-100 dark:bg-surface2 uppercase tracking-wider"><div class="h-1.5 w-1.5 rounded-full bg-green-500 status-dot-pulse"></div><span class="text-gray-500 dark:text-gray-400">Idle</span></div>
            <button id="force-unlock-btn" onclick="forceUnlock()" class="hidden text-[.65rem] font-mono font-bold bg-rose-600 text-white px-2 py-1.5 rounded-lg hover:bg-rose-700 transition">UNLOCK</button>
        </div>
        <?php endif; ?>
        <button onclick="toggleTheme()" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-surface2 hover:text-gray-900 dark:hover:text-white transition">
            <svg id="moon-icon" class="w-4 h-4 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
            <svg id="sun-icon" class="w-4 h-4 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            <span>Toggle theme</span>
        </button>
        <div class="flex items-center justify-between px-1 pt-1">
            <span class="text-xs text-gray-500 dark:text-gray-500 truncate">
                <?= isset($_SESSION['admin_user']) ? htmlspecialchars($_SESSION['admin_user']) : '' ?>
            </span>
            <a href="/?logout=true" class="text-xs font-medium text-gray-400 hover:text-rose-500 dark:hover:text-rose-400 transition">Logout</a>
        </div>
    </div>
</aside>
