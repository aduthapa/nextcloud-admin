<?php
// Shared <head> boilerplate. Include after setting $pageTitle (string).
// Optional $extraHead (raw HTML string, e.g. an extra CDN <script> tag).
?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= htmlspecialchars($pageTitle ?? 'NC Admin') ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if (!empty($extraHead)) echo $extraHead; ?>
<script>
    tailwind.config = { darkMode: 'class', theme: { extend: { colors: { nc: '#0082c9', nc_dark: '#005f92', term: '#0c0c0c' } } } };
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
</script>
<style>
    body { transition: background-color 0.2s ease, color 0.2s ease; }
    ::-webkit-scrollbar{width:8px;height:8px}
    ::-webkit-scrollbar-track{background:rgba(0,0,0,0.06)}
    .dark ::-webkit-scrollbar-track{background:#1a1a1a}
    ::-webkit-scrollbar-thumb{background:#c7c7c7;border-radius:4px}
    .dark ::-webkit-scrollbar-thumb{background:#444}
    ::-webkit-scrollbar-thumb:hover{background:#a3a3a3}
    .terminal-box { height: 600px; max-height: 80vh; overflow-y: auto; }
    button:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(100%); }
    .tab-btn { color: #6b7280; border-bottom: 2px solid transparent; }
    .dark .tab-btn { color: #9ca3af; }
    .tab-btn.active { border-bottom-color: #0082c9; background: rgba(0, 130, 201, 0.08); color: #0082c9; }
    .dark .tab-btn.active { color: #fff; background: rgba(0, 130, 201, 0.16); }
    .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.875rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .dark .card { background: #1f2937; border-color: #374151; }
    .fade-in { animation: fadeIn .25s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    .status-dot-pulse { box-shadow: 0 0 0 0 rgba(34,197,94,.5); animation: pulseDot 2s infinite; }
    @keyframes pulseDot { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.45); } 70% { box-shadow: 0 0 0 6px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
    .empty-state { color: #9ca3af; font-size: .875rem; text-align: center; padding: 2rem 1rem; }

    /* Shared button language */
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; font-weight:600; font-size:.875rem; padding:.625rem 1rem; border-radius:.65rem; border:1px solid transparent; transition:all .15s ease; }
    .btn:active:not(:disabled) { transform: scale(.98); }
    .btn-primary { background:#0082c9; color:#fff; box-shadow:0 4px 14px rgba(0,130,201,.25); }
    .btn-primary:hover { background:#005f92; }
    .btn-neutral { background:#f3f4f6; color:#1f2937; border-color:#e5e7eb; }
    .btn-neutral:hover { background:#e5e7eb; }
    .dark .btn-neutral { background:#374151; color:#fff; border-color:#4b5563; }
    .dark .btn-neutral:hover { background:#4b5563; }
    .btn-soft-green { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .btn-soft-green:hover { background:#bbf7d0; }
    .dark .btn-soft-green { background:rgba(22,101,52,.35); color:#86efac; border-color:rgba(22,101,52,.6); }
    .dark .btn-soft-green:hover { background:rgba(22,101,52,.55); }
    .btn-soft-red { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .btn-soft-red:hover { background:#fecaca; }
    .dark .btn-soft-red { background:rgba(127,29,29,.35); color:#fca5a5; border-color:rgba(127,29,29,.6); }
    .dark .btn-soft-red:hover { background:rgba(127,29,29,.55); }
    .btn-soft-blue { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
    .btn-soft-blue:hover { background:#bfdbfe; }
    .dark .btn-soft-blue { background:rgba(30,64,175,.3); color:#93c5fd; border-color:rgba(30,64,175,.55); }
    .dark .btn-soft-blue:hover { background:rgba(30,64,175,.5); }
    .btn-danger { background:#dc2626; color:#fff; }
    .btn-danger:hover { background:#b91c1c; }
    .section-eyebrow { font-size:.7rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#9ca3af; }
    .badge { display:inline-flex; align-items:center; gap:.35rem; font-size:.7rem; font-weight:700; padding:.2rem .55rem; border-radius:9999px; letter-spacing:.03em; }
</style>
