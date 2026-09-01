<?php
// Full-page loader shown until window 'load' fires. Self-contained (own <style>)
// so it works on every page regardless of which <head> partial is used.
// Respects localStorage.loaderLogo ('off' hides the brand mark, set via the
// sidebar's "Loader logo" switch) through the .no-loader-logo class on <html>,
// set synchronously in partials/head.php / login.php before first paint.
// Stays visible for at least $minLoaderMs (Settings > Loader), so it doesn't
// flash instantly on a fast/cached load - $pdo is already in scope here since
// every including page requires db.php before this partial.
$minLoaderMs = 2000;
try {
    $row = $pdo->query("SELECT min_loader_ms FROM ui_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($row) $minLoaderMs = max(0, (int) $row['min_loader_ms']);
} catch (Exception $e) {
    // fall back to the 2s default above
}
?>
<style>
    .no-loader-logo .loader-with-logo { display: none !important; }
    .loader-plain { display: none; }
    .no-loader-logo .loader-plain { display: flex; }
    .loader-mark { animation: loaderPulse 2.2s ease-in-out infinite; }
    @keyframes loaderPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.06); } }
    .loader-ring { animation: loaderSpin 1.1s linear infinite; }
    @keyframes loaderSpin { to { transform: rotate(360deg); } }
</style>
<script>
    window.__loaderStart = Date.now();
    // Sidebar nav sets this right before navigating so an in-app click
    // (Main Menu <-> Sub Menu, or between sub-menu items) gets a quick
    // slide transition instead of the full boot loader - see partials/nav.php.
    try { window.__skipLoader = sessionStorage.getItem('navTransition') === '1'; } catch (e) { window.__skipLoader = false; }
</script>
<div id="global-loader" class="fixed inset-0 z-[100] bg-ink flex items-center justify-center transition-opacity duration-500">
    <div class="loader-with-logo flex flex-col items-center">
        <div class="relative mb-5">
            <div class="loader-ring absolute -inset-3 rounded-full border-[3px] border-accent-800/40 border-t-accent-400"></div>
            <div class="loader-mark relative w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-400 to-accent-700 flex items-center justify-center shadow-glow">
                <svg class="w-8 h-8 text-ink" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path></svg>
            </div>
        </div>
        <div class="font-bold text-sm tracking-tight text-white">NC<span class="text-accent-400"> Admin</span></div>
        <div class="mt-2 text-accent-400 font-mono text-[.65rem] font-bold tracking-widest uppercase animate-pulse">Loading system</div>
    </div>
    <div class="loader-plain flex-col items-center">
        <div class="w-14 h-14 border-4 border-accent-700/30 border-t-accent-400 rounded-full animate-spin"></div>
        <div class="mt-4 text-accent-400 font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div>
    </div>
</div>
<script>
    (function() {
        const l = document.getElementById('global-loader');
        if (window.__skipLoader) {
            // Arrived via a sidebar nav click - partials/nav.php is already
            // playing its own slide transition, so just get out of the way.
            l.style.display = 'none';
            return;
        }
        const MIN_MS = <?= (int) $minLoaderMs ?>;
        function hide() {
            l.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => l.style.display = 'none', 500);
        }
        window.addEventListener('load', () => {
            const elapsed = Date.now() - (window.__loaderStart || Date.now());
            setTimeout(hide, Math.max(0, MIN_MS - elapsed));
        });
    })();
</script>
