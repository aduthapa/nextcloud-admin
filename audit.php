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
        'success' => 'bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400',
        'failed'  => 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-300',
        'auth_failed' => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-300',
        default => 'bg-gray-100 dark:bg-surface2 text-gray-600 dark:text-gray-300',
    };
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="font-sans min-h-screen flex bg-gray-50 text-gray-900 dark:bg-ink dark:text-gray-100">
    <?php require __DIR__ . '/partials/loader.php'; ?>
    <script>
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) { document.documentElement.classList.remove('dark'); localStorage.theme = 'light'; }
            else { document.documentElement.classList.add('dark'); localStorage.theme = 'dark'; }
        }
    </script>

    <?php $activePage = 'audit'; require __DIR__ . '/partials/nav.php'; ?>

    <main class="flex-1 min-w-0 p-6 lg:p-10">
        <div class="max-w-[1500px] mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Audit Log</h1>
                <p class="text-sm text-gray-500 dark:text-gray-500 font-mono mt-1">who did what, when, from where</p>
            </div>

            <?php if (isset($dbError)): ?>
            <div class="card p-4 text-sm text-rose-600 dark:text-rose-400" style="border-left:2px solid #e11d48">Could not load audit log: <?= htmlspecialchars($dbError) ?></div>
            <?php endif; ?>

            <div class="card p-4">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="section-eyebrow block mb-1">User</label>
                        <input type="text" name="user" value="<?= htmlspecialchars($filterUser) ?>" placeholder="e.g. admin" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500">
                    </div>
                    <div>
                        <label class="section-eyebrow block mb-1">Action</label>
                        <select name="action" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500">
                            <option value="">All actions</option>
                            <?php foreach ($distinctActions as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="section-eyebrow block mb-1">Status</label>
                        <select name="status" class="bg-gray-50 dark:bg-surface2 border border-gray-200 dark:border-line text-gray-900 dark:text-white rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500/30 focus:border-accent-500">
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
                    <span class="ml-auto text-xs text-gray-500 font-mono self-center"><?= number_format($total) ?> event<?= $total === 1 ? '' : 's' ?></span>
                </form>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <?php if (empty($rows)): ?>
                    <div class="empty-state">no audit events match these filters</div>
                    <?php else: ?>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-surface2 text-gray-500 dark:text-gray-400 uppercase text-xs font-mono">
                            <tr>
                                <th class="p-3">Time</th>
                                <th class="p-3">User</th>
                                <th class="p-3">Action</th>
                                <th class="p-3">Details</th>
                                <th class="p-3">Status</th>
                                <th class="p-3">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-line">
                            <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-surface2">
                                <td class="p-3 font-mono text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
                                <td class="p-3 font-semibold text-gray-800 dark:text-gray-200 whitespace-nowrap"><?= htmlspecialchars($row['username']) ?></td>
                                <td class="p-3 font-mono text-xs text-violet-500 whitespace-nowrap"><?= htmlspecialchars($row['action']) ?></td>
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
                <div class="flex justify-between items-center px-4 py-3 border-t border-gray-200 dark:border-line text-sm">
                    <span class="text-gray-500 font-mono text-xs">page <?= $page ?> of <?= $totalPages ?></span>
                    <div class="flex gap-2">
                        <?php $qs = fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
                        <a href="<?= $page > 1 ? $qs($page - 1) : '#' ?>" class="btn btn-neutral text-xs <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Previous</a>
                        <a href="<?= $page < $totalPages ? $qs($page + 1) : '#' ?>" class="btn btn-neutral text-xs <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">Next</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>