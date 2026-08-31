<?php
// Shared <head> boilerplate. Include after setting $pageTitle (string).
// Optional $extraHead (raw HTML string, e.g. an extra CDN <script> tag).
?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= htmlspecialchars($pageTitle ?? 'NC Admin') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if (!empty($extraHead)) echo $extraHead; ?>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
                },
                colors: {
                    accent: { DEFAULT: '#2dd4bf', 50:'#f0fdfa', 100:'#ccfbf1', 200:'#99f6e4', 300:'#5eead4', 400:'#2dd4bf', 500:'#14b8a6', 600:'#0d9488', 700:'#0f766e', 800:'#115e59', 900:'#134e4a', 950:'#042f2e' },
                    violet: { DEFAULT: '#a78bfa', 500:'#8b5cf6', 600:'#7c3aed' },
                    ink: '#080b10',
                    surface: '#11161f',
                    surface2: '#171d29',
                    line: '#232a38',
                    term: '#080b10',
                },
                boxShadow: {
                    glow: '0 0 0 1px rgba(45,212,191,0.25), 0 8px 24px -8px rgba(45,212,191,0.35)',
                },
            }
        }
    };
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    if (localStorage.loaderLogo === 'off') {
        document.documentElement.classList.add('no-loader-logo');
    }
</script>
<style>
    html, body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    body { transition: background-color 0.2s ease, color 0.2s ease; }
    .font-mono, code, .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    ::-webkit-scrollbar{width:8px;height:8px}
    ::-webkit-scrollbar-track{background:transparent}
    ::-webkit-scrollbar-thumb{background:#cbd2dd;border-radius:4px}
    .dark ::-webkit-scrollbar-thumb{background:#3a4353}
    ::-webkit-scrollbar-thumb:hover{background:#aab2c0}
    .dark ::-webkit-scrollbar-thumb:hover{background:#4c5769}

    .terminal-box { height: 600px; max-height: 80vh; overflow-y: auto; }
    button:disabled { opacity: 0.45; cursor: not-allowed; filter: grayscale(60%); }

    .tab-btn { color: #6b7280; border-bottom: 2px solid transparent; font-family: 'JetBrains Mono', monospace; font-size: .8rem; letter-spacing: .02em; }
    .dark .tab-btn { color: #7d8798; }
    .tab-btn.active { border-bottom-color: #2dd4bf; color: #0f766e; }
    .dark .tab-btn.active { color: #5eead4; }

    /* Card language: flat surfaces, hairline borders, accent-on-hover, no heavy shadows */
    .card { background: #ffffff; border: 1px solid #e6e8ec; border-radius: 1rem; position: relative; transition: border-color .15s ease, box-shadow .15s ease; }
    .dark .card { background: #11161f; border-color: #232a38; }
    .card:hover { border-color: rgba(13,148,136,.35); box-shadow: 0 0 0 1px rgba(13,148,136,.08); }
    .dark .card:hover { border-color: rgba(45,212,191,.3); box-shadow: 0 0 0 1px rgba(45,212,191,.06); }
    .card-accent { border-top: 2px solid #2dd4bf; }

    .icon-badge { width: 2.25rem; height: 2.25rem; border-radius: .65rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .term-cursor { display: inline-block; width: 6px; height: 13px; background: currentColor; margin-left: 3px; vertical-align: -2px; animation: termBlink 1s step-end infinite; }
    @keyframes termBlink { 50% { opacity: 0; } }

    .fade-in { animation: fadeIn .3s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    .status-dot-pulse { box-shadow: 0 0 0 0 rgba(45,212,191,.55); animation: pulseDot 2s infinite; }
    @keyframes pulseDot { 0% { box-shadow: 0 0 0 0 rgba(45,212,191,.5); } 70% { box-shadow: 0 0 0 7px rgba(45,212,191,0); } 100% { box-shadow: 0 0 0 0 rgba(45,212,191,0); } }
    .empty-state { color: #8a93a3; font-size: .875rem; text-align: center; padding: 2.5rem 1rem; font-family: 'JetBrains Mono', monospace; }

    /* Buttons: flat, decisive, no pastel fills */
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; font-weight:600; font-size:.8rem; letter-spacing:.01em; padding:.65rem 1.1rem; border-radius:.6rem; border:1px solid transparent; transition:all .15s ease; }
    .btn:active:not(:disabled) { transform: scale(.97); }
    .btn-primary { background:#0d9488; color:#ecfdf9; }
    .btn-primary:hover { background:#0f766e; }
    .btn-neutral { background:transparent; color:#374151; border-color:#d8dce3; }
    .btn-neutral:hover { background:#f3f4f6; border-color:#c3c9d3; }
    .dark .btn-neutral { color:#c7cdd8; border-color:#2b3241; }
    .dark .btn-neutral:hover { background:#171d29; border-color:#3a4353; }
    .btn-outline-green { background:transparent; color:#0d9488; border-color:#2dd4bf; }
    .btn-outline-green:hover { background:rgba(45,212,191,.12); }
    .dark .btn-outline-green { color:#5eead4; border-color:#0f766e; }
    .dark .btn-outline-green:hover { background:rgba(45,212,191,.1); }
    .btn-outline-red { background:transparent; color:#e11d48; border-color:#fda4af; }
    .btn-outline-red:hover { background:rgba(225,29,72,.08); }
    .dark .btn-outline-red { color:#fb7185; border-color:#4c1d2b; }
    .dark .btn-outline-red:hover { background:rgba(225,29,72,.12); }
    .btn-danger { background:#e11d48; color:#fff1f2; }
    .btn-danger:hover { background:#be123c; }
    .btn-violet { background:transparent; color:#7c3aed; border-color:#c4b5fd; }
    .btn-violet:hover { background:rgba(139,92,246,.1); }
    .dark .btn-violet { color:#c4b5fd; border-color:#3f2e6b; }
    .dark .btn-violet:hover { background:rgba(139,92,246,.14); }

    .section-eyebrow { font-family:'JetBrains Mono', monospace; font-size:.68rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#8a93a3; }
    .badge { display:inline-flex; align-items:center; gap:.35rem; font-family:'JetBrains Mono', monospace; font-size:.66rem; font-weight:700; padding:.22rem .55rem; border-radius:.4rem; letter-spacing:.04em; text-transform:uppercase; }

    /* Sidebar (base = light mode, .dark overrides) */
    .sb-link { display:flex; align-items:center; gap:.75rem; padding:.6rem .75rem; border-radius:.6rem; font-size:.875rem; font-weight:500; color:#6b7280; border-left:2px solid transparent; transition: all .15s ease; }
    .sb-link:hover { background:#f3f4f6; color:#111827; }
    .sb-link.active { background:rgba(13,148,136,.08); color:#0f766e; border-left-color:#0d9488; }
    .dark .sb-link { color:#8a93a3; }
    .dark .sb-link:hover { background:#171d29; color:#e6e9ef; }
    .dark .sb-link.active { background:rgba(45,212,191,.1); color:#5eead4; border-left-color:#2dd4bf; }

    /* Toggle switch (used by the sidebar's loader-logo control) */
    .switch { position: relative; display: inline-flex; align-items: center; width: 34px; height: 20px; border-radius: 9999px; background: #d8dce3; transition: background .15s ease; flex-shrink: 0; border: none; padding: 0; cursor: pointer; }
    .dark .switch { background: #2b3241; }
    .switch.on { background: #0d9488; }
    .switch .knob { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 9999px; background: #fff; transition: transform .15s ease; }
    .switch.on .knob { transform: translateX(14px); }
</style>
