<?php
// Shared top-right nav cluster: theme toggle, page nav, logout, optional busy indicator.
// Requires $activePage: 'dashboard'|'database'|'settings'|'config'|'security'|'audit'
// Optional $showStatusBar = true to render the Idle/Busy pill + Force Unlock button.
$__nav = [
    'dashboard' => ['Dashboard', '/'],
    'database'  => ['Database', '/database'],
    'settings'  => ['Settings', '/settings'],
    'config'    => ['Config', '/ncconfig'],
    'audit'     => ['Audit Log', '/audit.php'],
];
?>
<div class="flex items-center gap-3">
    <button onclick="toggleTheme()" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-yellow-400 hover:bg-gray-200 dark:hover:bg-gray-700 transition" aria-label="Toggle theme">
        <svg id="moon-icon" class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
        <svg id="sun-icon" class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
    </button>
    <nav class="flex gap-1 bg-white dark:bg-gray-800/60 p-1 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-x-auto max-w-[70vw]">
        <?php foreach ($__nav as $key => $item): [$label, $href] = $item; $isActive = ($activePage ?? '') === $key; ?>
        <a href="<?= $href ?>" class="px-3.5 py-1.5 rounded-lg text-sm font-medium transition whitespace-nowrap <?= $isActive ? 'bg-nc text-white shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white' ?>"><?= $label ?></a>
        <?php endforeach; ?>
        <a href="/security.php" class="px-3.5 py-1.5 rounded-lg text-sm font-semibold transition whitespace-nowrap <?= ($activePage ?? '') === 'security' ? 'bg-red-600 text-white shadow-sm' : 'text-red-600 dark:text-red-300 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40' ?>">Security</a>
    </nav>
    <a href="/?logout=true" class="text-sm font-medium text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition px-1 whitespace-nowrap">Logout</a>
    <?php if (!empty($showStatusBar)): ?>
    <div id="status-indicator" class="flex items-center gap-2 text-xs font-bold bg-white dark:bg-gray-800 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-700 uppercase tracking-wider whitespace-nowrap"><div class="h-2 w-2 rounded-full bg-green-500 status-dot-pulse"></div><span class="text-gray-600 dark:text-gray-400">Idle</span></div>
    <button id="force-unlock-btn" onclick="forceUnlock()" class="hidden text-xs bg-red-600 text-white px-3 py-1.5 rounded-full hover:bg-red-700 transition font-semibold whitespace-nowrap">Unlock</button>
    <?php endif; ?>
</div>
