<?php
$pageTitle = 'Savings';
require_once __DIR__ . '/../includes/header.php';

// Filters
$search = trim($_GET['q'] ?? '');
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

// Account list (joined w/ member)
$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_no LIKE ? OR sa.account_no LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM savings_accounts sa
    JOIN members m ON m.id = sa.member_id
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT sa.*, m.first_name, m.last_name, m.member_no, m.status AS member_status
    FROM savings_accounts sa
    JOIN members m ON m.id = sa.member_id
    $whereSql
    ORDER BY sa.balance DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

// Totals
$summary = $pdo->query("
    SELECT
        COUNT(*) AS accounts,
        COALESCE(SUM(balance), 0) AS total_balance,
        COALESCE(AVG(balance), 0) AS avg_balance
    FROM savings_accounts
")->fetch();

// This month
$thisMonth = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='deposit'    THEN amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals,
        COUNT(*) AS txn_count
    FROM savings_transactions
    WHERE YEAR(transaction_date) = YEAR(CURDATE())
      AND MONTH(transaction_date) = MONTH(CURDATE())
")->fetch();

$netFlow = (float)$thisMonth['deposits'] - (float)$thisMonth['withdrawals'];
?>

<div class="page-head">
    <div>
        <h1>Savings</h1>
        <p>Every member's savings account, balances, and activity at a glance.</p>
    </div>
    <div class="page-head-actions">
        <a href="history.php" class="btn btn-ghost">All transactions</a>
        <a href="deposit.php" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" d="M12 4v16m0 0l-4-4m4 4l4-4"/>
            </svg>
            Record deposit
        </a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Total savings held</div></div><div class="stat-icon">💰</div></div>
        <div class="stat-value"><?= e(money($summary['total_balance'])) ?></div>
        <div class="stat-trend muted"><?= number_format((int)$summary['accounts']) ?> accounts</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Average balance</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value"><?= e(money($summary['avg_balance'])) ?></div>
        <div class="stat-trend muted">Per account</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">This month deposits</div></div><div class="stat-icon">⬆️</div></div>
        <div class="stat-value"><?= e(money($thisMonth['deposits'])) ?></div>
        <div class="stat-trend up"><?= number_format((int)$thisMonth['txn_count']) ?> transactions</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Net flow (month)</div></div><div class="stat-icon">📈</div></div>
        <div class="stat-value" style="color: <?= $netFlow >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
            <?= e(money($netFlow)) ?>
        </div>
        <div class="stat-trend muted">Deposits − withdrawals</div>
    </div>
</div>

<!-- Toolbar -->
<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <div style="flex:1;min-width:220px;position:relative;">
        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-3);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            </svg>
        </span>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search account no, member name, member no…"
               style="width:100%;padding:9px 12px 9px 36px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </div>
    <button class="btn btn-outline btn-sm" type="submit">Search</button>
    <?php if ($search): ?><a href="index.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<!-- Accounts table -->
<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Member</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
            <?php $initial = strtoupper(substr($a['first_name'], 0, 1) . substr($a['last_name'], 0, 1)); ?>
            <tr>
                <td><code style="font-size:.82rem;"><?= e($a['account_no']) ?></code></td>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="avatar" style="width:32px;height:32px;font-size:.75rem;"><?= e($initial) ?></div>
                        <div>
                            <div style="font-weight:600;"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
                            <div class="muted" style="font-size:.76rem;"><code><?= e($a['member_no']) ?></code></div>
                        </div>
                    </div>
                </td>
                <td style="text-align:right;font-weight:700;"><?= e(money($a['balance'])) ?></td>
                <td><span class="badge badge-<?= e($a['member_status']) ?>"><?= e($a['member_status']) ?></span></td>
                <td style="text-align:right;">
                    <div style="display:inline-flex;gap:6px;">
                        <a href="/scms/members/view.php?id=<?= (int)$a['member_id'] ?>" class="btn btn-ghost btn-sm" title="Member">👤</a>
                        <a href="statement.php?account_id=<?= (int)$a['id'] ?>" class="btn btn-outline btn-sm">Statement</a>
                        <a href="deposit.php?account_id=<?= (int)$a['id'] ?>" class="btn btn-success btn-sm">+</a>
                        <a href="withdraw.php?account_id=<?= (int)$a['id'] ?>" class="btn btn-outline btn-sm">−</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$accounts): ?>
            <tr><td colspan="5" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">💼</div>
                <div style="font-weight:600;color:var(--text);margin-bottom:4px;">No savings accounts found</div>
                <div style="font-size:.85rem;">Add members to automatically create their savings accounts.</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pg['pages'] > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:12px;">
        <div class="muted" style="font-size:.85rem;">
            Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['per_page'], $pg['total']) ?>
            of <?= number_format($pg['total']) ?> accounts
        </div>
        <div style="display:flex;gap:6px;">
            <a href="<?= e(qs(['page' => max(1, $pg['current'] - 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>← Prev</a>
            <?php for ($i = max(1, $pg['current'] - 2); $i <= min($pg['pages'], $pg['current'] + 2); $i++): ?>
                <a href="<?= e(qs(['page' => $i])) ?>" class="btn btn-sm <?= $i === $pg['current'] ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="<?= e(qs(['page' => min($pg['pages'], $pg['current'] + 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] >= $pg['pages'] ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Next →</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>