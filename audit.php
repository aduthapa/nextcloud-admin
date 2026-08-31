<?php
require 'db.php'; require_login();

// --- Filters ---
$filterUser   = trim($_GET['user'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterUser !== '')   { $where[] = 'username LIKE ?'; $params[] = "%$filterUser%"; }
if ($filterAction !== '') { $where[] = 'action LIKE ?';   $params[] = "%$filterAction%"; }
if ($filterStatus !== '') { $where[] = 'status = ?';      $params[] = $filterStatus; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_log $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM admin_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $distinctActions = $pdo->query("SELECT DISTINCT action FROM admin_audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $rows = []; $total = 0; $distinctActions = [];
    $dbError = $e->getMessage();
}

$totalPages = max(1, (int) ceil($total / $perPage));
$pageTitle = 'Audit Log';

function badge_for_status($status) {
    return match ($status) {
        'success' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-800',
        'failed'  => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800',
        'auth_failed' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
        default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-600',
    };
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen p-4 lg:p-8 bg-gray-100 text-gray-900 dark:bg-[#111827] dark:text-gray-200">
    <div id="global-loader" class="fixed inset-0 z-[100] bg-gray-900 flex items-center justify-center transition-opacity duration-500">
        <div class="relative flex flex-col items-center"><div class="w-16 h-16 border-4 border-blue-900/30 border-t-[#0082c9] rounded-full animate-spin"></div><div class="mt-4 text-[#0082c9] font-mono text-xs font-bold tracking-widest animate-pulse">LOADING</div></div>
    </div>
    <script>
        window.addEventListener('load', () => { const l = document.getElementById('global-loader'); l.classList.add('opacity-0', 'pointer-events-none'); setTimeout(() => l.style.display = 'none', 500); });
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; }
            else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
        }
    </script>

    <div class="max-w-[1600px] mx-auto">
        <div class="flex flex-wrap gap-4 justify-between items-center mb-8 pb-4 border-b border-gray-300 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="bg-gray-800 dark:bg-gray-700 p-2.5 rounded-xl"><svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-800 dark:text-white">Audit<span class="text-gray-500 font-light">Log</span></h1>
            </div>
            <?php $activePage = 'audit'; require __DIR__ . '/partials/nav.php'; ?>
        </div>

        <?php if (isset($dbError)): ?>
        <div class="card p-4 border-l-4 border-l-red-500 mb-6 text-sm text-red-600 dark:text-red-400">Could not load audit log: <?= htmlspecialchars($dbError) ?></div>
        <?php endif; ?>

        <div class="card p-4 mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="section-eyebrow block mb-1">User</label>
                    <input type="text" name="user" value="<?= htmlspecialchars($filterUser) ?>" placeholder="e.g. admin" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-nc/40 focus:border-nc">
                </div>
                <div>
                    <label class="section-eyebrow block mb-1">Action</label>
                    <select name="action" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-nc/40 focus:border-nc">
                        <option value="">All actions</option>
                        <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="section-eyebrow block mb-1">Status</label>
                    <select name="status" class="bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-nc/40 focus:border-nc">
                        <option value="">All statuses</option>
                        <?php foreach (['success', 'failed', 'auth_failed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($filterUser || $filterAction || $filterStatus): ?>
                <a href="/audit.php" class="btn btn-neutral">Clear</a>
                <?php endif; ?>
                <span class="ml-auto text-xs text-gray-500 self-center"><?= number_format($total) ?> event<?= $total === 1 ? '' : 's' ?></span>
            </form>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <?php if (empty($rows)): ?>
                <div class="empty-state">No audit events match these filters.</div>
                <?php else: ?>
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/60 text-gray-500 dark:text-gray-400 uppercase text-xs">
                        <tr>
                            <th class="p-3">Time</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Action</th>
                            <th class="p-3">Details</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($rows as $row): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="p-3 font-mono text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
                            <td class="p-3 font-semibold text-gray-800 dark:text-gray-200 whitespace-nowrap"><?= htmlspecialchars($row['username']) ?></td>
                            <td class="p-3 font-mono text-xs text-blue-600 dark:text-blue-400 whitespace-nowrap"><?= htmlspecialchars($row['action']) ?></td>
                            <td class="p-3 text-gray-600 dark:text-gray-400 text-xs max-w-md truncate" title="<?= htmlspecialchars($row['details'] ?? '') ?>"><?= htmlspecialchars($row['details'] ?? '—') ?></td>
                            <td class="p-3"><span class="badge <?= badge_for_status($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td class="p-3 font-mono text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="flex justify-between items-center px-4 py-3 border-t border-gray-200 dark:border-gray-700 text-sm">
                <span class="text-gray-500">Page <?= $page ?> of <?= $totalPages ?></span>
                <div class="flex gap-2">
                    <?php $qs = fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
                    <a href="<?= $page > 1 ? $qs($page - 1) : '#' ?>" class="btn btn-neutral text-xs <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Previous</a>
                    <a href="<?= $page < $totalPages ? $qs($page + 1) : '#' ?>" class="btn btn-neutral text-xs <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">Next</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>