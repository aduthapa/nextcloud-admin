<?php
// Renders the persistent left sidebar: brand, nav links, theme toggle, status pill, session.
// Requires $activePage: 'dashboard'|'nextcloud'|'admin-settings'|'database'|'settings'|'config'|'security'|'audit'
// If $activePage is a parent with a submenu, also pass $activeChild with that child's key
// to highlight which sub-menu item is open (defaults to the parent's first child).
// Optional $showStatusBar = true to render the Idle/Busy pill + Force Unlock button.
$__nav = [
    'dashboard' => ['Dashboard', '/', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
    'nextcloud' => ['Nextcloud', '/nextcloud.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z"/>', [
        ['nextcloud',     'Nextcloud',     '/nextcloud.php',     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z"/>'],
        ['system-admin',  'System Admin',  '/system-admin.php',  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
        ['server-health', 'Server Health', '/server-health.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12h3l2.5 7L13 5l2.5 7H21"/>'],
        ['logs',          'Logs',          '/logs.php',          '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
        ['hetzner',       'Hetzner Management', '/hetzner-management.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-14 0h14M7 8h.01M7 16h.01"/>'],
    ]],
    'admin-settings' => ['Admin Settings', '/two-factor.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>', [
        ['two-factor', 'Two-Factor',        '/two-factor.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>'],
        ['duo-admin',  'Duo Admin (Cisco)', '/duo-admin.php',  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
        ['auth0-saml', 'Auth0 SAML',        '/auth0-saml.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>'],
    ]],
    'database'  => ['Database', '/database', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>'],
    'settings'  => ['Settings', '/settings', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'],
    'config'    => ['Config', '/ncconfig', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 20l4-16M6 8L2 12l4 4M18 8l4 4-4 4"/>'],
    'audit'     => ['Audit Log', '/audit.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
    'security'  => ['Security', '/security.php', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
];
$__activeParent = $activePage ?? '';
$__inSection = isset($__nav[$__activeParent][3]);
$__activeChild = $activeChild ?? ($__inSection ? $__nav[$__activeParent][3][0][0] : null);
?>
<aside class="w-60 shrink-0 h-screen sticky top-0 flex flex-col bg-white dark:bg-surface border-r border-gray-200 dark:border-line">
    <div class="h-16 flex items-center gap-2.5 px-5 border-b border-gray-200 dark:border-line shrink-0">
        <div class="w-8 h-8 rounded-lg bg-accent-600 flex items-center justify-center shrink-0">
            <svg class="text-white" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
        </div>
        <span class="font-bold text-sm tracking-tight text-gray-900 dark:text-white leading-none">NC<span class="text-[#0d9488] dark:text-accent-400"> Admin</span></span>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
        <?php if ($__inSection): ?>
        <a href="/" class="sb-link !text-accent-600 dark:!text-accent-400 font-semibold mb-2">
            <svg class="shrink-0" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Main Menu</span>
        </a>
        <div class="px-3 pt-2 pb-1 section-eyebrow"><?= $__nav[$__activeParent][0] ?></div>
        <?php foreach ($__nav[$__activeParent][3] as [$childKey, $childLabel, $childHref, $childIcon]): $isActive = $__activeChild === $childKey; ?>
        <a href="<?= $childHref ?>" class="sb-link <?= $isActive ? 'active' : '' ?>">
            <svg class="shrink-0" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $childIcon ?></svg>
            <span><?= $childLabel ?></span>
        </a>
        <?php endforeach; ?>
        <?php else: ?>
        <?php foreach ($__nav as $key => $item): [$label, $href, $iconPath] = $item; $hasChildren = isset($item[3]); $isActive = ($activePage ?? '') === $key; ?>
        <a href="<?= $href ?>" class="sb-link justify-between <?= $isActive ? 'active' : '' ?>">
            <span class="flex items-center gap-3">
                <svg class="shrink-0" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $iconPath ?></svg>
                <span><?= $label ?></span>
            </span>
            <?php if ($hasChildren): ?>
            <svg class="shrink-0 opacity-50" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
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
        <div class="flex items-center justify-between px-3 py-1.5 rounded-lg text-sm text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-2.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path></svg>Loader logo</span>
            <button type="button" id="loader-logo-switch" class="switch" onclick="toggleLoaderLogo()" aria-label="Toggle loader logo"><span class="knob"></span></button>
        </div>
        <div class="flex items-center gap-2.5 px-1 pt-1">
            <div class="w-6 h-6 rounded-full bg-accent-600 text-white text-[.65rem] font-bold flex items-center justify-center shrink-0 uppercase">
                <?= isset($_SESSION['admin_user']) ? htmlspecialchars(mb_substr($_SESSION['admin_user'], 0, 1)) : '?' ?>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-500 truncate flex-1">
                <?= isset($_SESSION['admin_user']) ? htmlspecialchars($_SESSION['admin_user']) : '' ?>
            </span>
            <a href="/?logout=true" class="text-xs font-medium text-gray-400 hover:text-rose-500 dark:hover:text-rose-400 transition">Logout</a>
        </div>
    </div>
</aside>
<script>
    (function() {
        const sw = document.getElementById('loader-logo-switch');
        if (!sw) return;
        sw.classList.toggle('on', localStorage.loaderLogo !== 'off');
    })();
    function toggleLoaderLogo() {
        const sw = document.getElementById('loader-logo-switch');
        const next = !sw.classList.contains('on');
        sw.classList.toggle('on', next);
        localStorage.loaderLogo = next ? 'on' : 'off';
        document.documentElement.classList.toggle('no-loader-logo', !next);
    }
</script>
